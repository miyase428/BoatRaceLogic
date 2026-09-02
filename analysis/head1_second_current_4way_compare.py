#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
イン逃げ時2着予想：現在Webにある3系統 + 統合案を同じ母集団で比較する。

比較
----
1) BASIC_K10
   現行「1号艇1着時の2着率」。

2) CURRENT_FINAL_SECOND
   現行「最終予想の2着候補」。
   現行最終順位から1号艇とkiru艇を除外した順番。
   buildSummary()の相手候補選定に合わせ、最大3艇を評価する。

3) AI_FINAL
   現行「イン1着時 2連単」。
   STEP3最終120通りから P(2着艇 | 1C頭) へ集約。

4) BASIC_AI_BLEND
   BASIC_K10 と AI_FINAL の幾何平均統合。
   p ∝ BASIC^(1-w) × AI^w
   wはP1だけで選択し、P2では固定する。

評価母集団
----------
- 1号艇=1C かつ実際に1着
- 実2着一意
- BASIC/AI/現行CSVがすべて揃う共通レース

注意
----
CURRENT_FINAL_SECONDは確率モデルではないため、LogLoss/Brierでは比較しない。
4方式共通の主指標は Top1 / Top2 / Top3 の実2着捕捉率。
また、CURRENT_FINAL_SECONDはkiru艇を候補から外すため、
「全レース」と「実2着が非kiruのレース」の両方を出して、
切り判定の影響と純粋な順位付けを分けて確認する。

本番Web / PredictionLogic は変更しない。

Usage
-----
python3 analysis/head1_second_current_4way_compare.py \
  analysis/output/final_prediction_boats_20260615_20260714.csv \
  analysis/output/final_prediction_boats_20260715_20260814.csv
"""

from __future__ import annotations

import statistics
import sys
from collections import Counter
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import final_prediction_ai_opponent_compare as final_aite
import head1_second_probability_4way_compare as four
import second_place_head1_k_compare as basic2
import trifecta_probability_order_compare as step3


METHODS = (
    "BASIC_K10",
    "CURRENT_FINAL_SECOND",
    "AI_FINAL",
    "BASIC_AI_BLEND",
)


class RankMetrics:
    def __init__(self):
        self.races = 0
        self.top_hits = {1: 0, 2: 0, 3: 0}
        self.ranks = []
        self.not_listed = 0

    def add(self, ordered: list[int], actual_second: int) -> None:
        self.races += 1
        try:
            rank = ordered.index(int(actual_second)) + 1
        except ValueError:
            self.not_listed += 1
            return

        self.ranks.append(rank)
        for k in (1, 2, 3):
            if rank <= k:
                self.top_hits[k] += 1

    def summary(self) -> dict:
        n = self.races
        ranked_n = len(self.ranks)
        return {
            "races": n,
            "top1": self.top_hits[1] / n if n else 0.0,
            "top2": self.top_hits[2] / n if n else 0.0,
            "top3": self.top_hits[3] / n if n else 0.0,
            "mean_rank": sum(self.ranks) / ranked_n if ranked_n else 0.0,
            "median_rank": statistics.median(self.ranks) if ranked_n else 0.0,
            "not_listed": self.not_listed,
        }


def current_final_order(boats: dict[int, dict]) -> tuple[list[int] | None, set[int]]:
    """
    現行最終予想のrank_boatsを再現し、1号艇頭を前提に相手順を返す。

    CURRENT_FINAL_SECONDは現在のbuildSummary()と同じ考え方で、
    1号艇とkiru艇を除外した現行最終順位順。
    """
    rank_boats, _current_head = final_aite.current_order_and_head(boats)
    if not rank_boats:
        return None, set()

    kiru = {lane for lane, b in boats.items() if int(b.get("kiru", 0)) == 1}
    ordered = [int(lane) for lane in rank_boats if int(lane) != 1 and int(lane) not in kiru]
    return ordered, kiru


def probability_order(row: dict, method: str, blend_weight: float) -> list[int]:
    if method == "BASIC_K10":
        probs = row["basic"]
    elif method == "AI_FINAL":
        probs = row["ai"]
    elif method == "BASIC_AI_BLEND":
        probs = four.blend_probs(row["basic"], row["ai"], blend_weight)
    else:
        raise ValueError(method)
    return four.topk(probs, 5)


def attach_current(rows: dict[str, list[dict]]) -> tuple[dict[str, list[dict]], Counter]:
    out = {"P1": [], "P2": []}
    skip = Counter()

    for period in ("P1", "P2"):
        for row in rows[period]:
            current, kiru = current_final_order(row["boats"])
            if current is None:
                skip[f"{period}_current_invalid"] += 1
                continue

            actual_second = int(row["actual_second"])
            item = dict(row)
            item["current_order"] = current
            item["kiru"] = kiru
            item["actual_second_cut"] = actual_second in kiru
            out[period].append(item)
            skip[f"{period}_ready"] += 1

    return out, skip


def ordered_for(row: dict, method: str, blend_weight: float) -> list[int]:
    if method == "CURRENT_FINAL_SECOND":
        return list(row["current_order"])
    return probability_order(row, method, blend_weight)


def evaluate_rank(rows: list[dict], method: str, blend_weight: float, noncut_only: bool = False) -> RankMetrics:
    metric = RankMetrics()
    for row in rows:
        if noncut_only and row["actual_second_cut"]:
            continue
        metric.add(ordered_for(row, method, blend_weight), int(row["actual_second"]))
    return metric


def compare_capture(rows: list[dict], base_method: str, new_method: str, k: int, blend_weight: float, noncut_only: bool = False) -> dict:
    changed = gained = lost = same_hit = same_miss = n = 0

    for row in rows:
        if noncut_only and row["actual_second_cut"]:
            continue
        n += 1
        actual = int(row["actual_second"])
        base = set(ordered_for(row, base_method, blend_weight)[:k])
        new = set(ordered_for(row, new_method, blend_weight)[:k])
        if base != new:
            changed += 1

        bh = actual in base
        nh = actual in new
        if nh and not bh:
            gained += 1
        elif bh and not nh:
            lost += 1
        elif bh and nh:
            same_hit += 1
        else:
            same_miss += 1

    return {
        "n": n,
        "changed": changed,
        "gained": gained,
        "lost": lost,
        "net": gained - lost,
        "same_hit": same_hit,
        "same_miss": same_miss,
    }


def print_rank_table(title: str, rows: list[dict], blend_weight: float, noncut_only: bool = False) -> None:
    print(f"\n【{title}】")
    print("方式                    R数    Top1    Top2    Top3   平均順位  中央順位  候補外")
    print("-" * 92)

    for method in METHODS:
        s = evaluate_rank(rows, method, blend_weight, noncut_only).summary()
        print(
            f"{method:<24} {s['races']:>5d}  "
            f"{s['top1']*100:>6.2f}%  {s['top2']*100:>6.2f}%  {s['top3']*100:>6.2f}%  "
            f"{s['mean_rank']:>7.2f}  {s['median_rank']:>7.1f}  {s['not_listed']:>5d}"
        )


def print_probability_table(title: str, rows: list[dict], blend_weight: float) -> None:
    print(f"\n【{title}：確率を持つ3方式のみ】")
    print("方式                 R数   LogLoss   Brier5   正解平均P   Top1    Top2    Top3")
    print("-" * 92)

    for method in ("BASIC_K10", "AI_FINAL", "BASIC_AI_BLEND"):
        if method == "BASIC_K10":
            m = four.evaluate(rows, "BASIC_K10")
        elif method == "AI_FINAL":
            m = four.evaluate(rows, "AI_FINAL")
        else:
            m = four.evaluate(rows, "BASIC_AI_BLEND", blend_weight=blend_weight)
        s = m.summary()
        print(
            f"{method:<20} {s['races']:>5d}  {s['logloss']:.6f}  {s['brier']:.6f}  "
            f"{s['actual_prob']*100:>8.3f}%  {s['top1']*100:>6.2f}%  "
            f"{s['top2']*100:>6.2f}%  {s['top3']*100:>6.2f}%"
        )


def print_current_vs_blend(title: str, rows: list[dict], blend_weight: float, noncut_only: bool = False) -> None:
    print(f"\n【{title}：② CURRENT_FINAL_SECOND → ④ BASIC_AI_BLEND】")
    print("対象      変更R   拾い   失い   純増")
    print("-" * 54)
    for k in (1, 2, 3):
        d = compare_capture(
            rows,
            "CURRENT_FINAL_SECOND",
            "BASIC_AI_BLEND",
            k,
            blend_weight,
            noncut_only,
        )
        print(
            f"Top{k:<2}   {d['changed']:>6d}  {d['gained']:>5d}  {d['lost']:>5d}  {d['net']:>+5d}"
        )


def main() -> int:
    if len(sys.argv) != 3:
        print("Usage: python3 analysis/head1_second_current_4way_compare.py P1_BOATS_CSV P2_BOATS_CSV")
        return 1

    p1_csv, p2_csv = sys.argv[1], sys.argv[2]

    print("STEP3出目モデル・基本2着率履歴・現行最終予想CSVを共通化中...")
    data = step3.build_common_records(p1_csv, p2_csv)

    basic2.P1_START = data["p1_start"]
    basic2.P1_END = data["p1_end"]
    basic2.P2_START = data["p2_start"]
    basic2.P2_END = data["p2_end"]
    snapshots, _basic_meta = basic2.load_snapshots()

    csv_races = final_aite.load_boats(p1_csv, p2_csv)
    rows0, base_skip = four.build_rows(data, snapshots, csv_races)
    rows, current_skip = attach_current(rows0)

    if not rows["P1"] or not rows["P2"]:
        raise RuntimeError("4方式の共通評価レースがありません")

    # ④の重みはP1だけで選択。前回4方式比較と同じルール。
    blend_best, blend_table = four.tune_blend(rows["P1"])
    _key, best_w, _metric = blend_best
    best_w = float(best_w)

    print("=" * 132)
    print("イン逃げ時2着予想：① BASIC / ② 現行最終予想2着 / ③ AI_FINAL / ④ BASIC+AI統合")
    print("=" * 132)
    print(f"P1                  : {data['p1_start']} ～ {data['p1_end']}")
    print(f"P2完全ホールドアウト: {data['p2_start']} ～ {data['p2_end']}")
    print("共通評価条件         : 1号艇=1C かつ実際に1着 / 実2着一意 / 4方式共通")
    print("① BASIC_K10         : 現行『1号艇1着時の2着率』")
    print("② CURRENT_FINAL_SECOND: 現行『最終予想の2着候補』= 最終順位 - 1号艇 - kiru")
    print("③ AI_FINAL          : 現行『イン1着時 2連単』")
    print(f"④ BASIC_AI_BLEND    : BASIC^(1-w) × AI^w / w={best_w:.2f} ※P1選択")
    print("主比較               : Top1 / Top2 / Top3 の実2着捕捉率")
    print("補助比較             : 実2着非kiruだけでも比較し、kiru影響を分離")
    print("本番Web変更          : なし")
    print(f"共通評価             : P1={len(rows['P1'])}R / P2={len(rows['P2'])}R")

    print("\n【P1で選んだ④の重み 上位5】")
    print("順位    w     LogLoss   Brier5   Top2")
    for i, (_k, w, m) in enumerate(blend_table[:5], 1):
        s = m.summary()
        print(f"{i:>2d}   {w:>4.2f}   {s['logloss']:.6f}  {s['brier']:.6f}  {s['top2']*100:>6.2f}%")

    print_rank_table("P1 4方式共通比較", rows["P1"], best_w)
    print_probability_table("P1", rows["P1"], best_w)

    print_rank_table("P2 ホールドアウト 4方式共通比較（最重要）", rows["P2"], best_w)
    print_probability_table("P2 ホールドアウト", rows["P2"], best_w)

    p2_cut = sum(1 for r in rows["P2"] if r["actual_second_cut"])
    p2_n = len(rows["P2"])
    print(
        f"\nP2 実2着が現行kiru : {p2_cut}/{p2_n}R "
        f"({(p2_cut / p2_n * 100.0 if p2_n else 0.0):.2f}%)"
    )

    print_rank_table("P2 実2着非kiru限定（順位付けの純粋比較）", rows["P2"], best_w, noncut_only=True)

    print_current_vs_blend("P2 全レース", rows["P2"], best_w, noncut_only=False)
    print_current_vs_blend("P2 実2着非kiru限定", rows["P2"], best_w, noncut_only=True)

    print("\n【共通化スキップ】")
    all_skip = Counter(base_skip)
    all_skip.update(current_skip)
    for key in sorted(all_skip):
        print(f"{key:<38}: {all_skip[key]}")

    print("\n【判断方針】")
    print("1. まずP2のTop1/Top2/Top3で②と④を直接比較する。")
    print("2. 全レースで④が優勢でも、非kiru限定でも改善するか確認する。")
    print("3. ④が②を上回れば、次に『頭は現行のまま・1号艇頭時の2着順位だけ④』を仮反映する。")
    print("4. その状態で現行最終予想との3連単的中率・回収率を前後比較する。")
    print("5. この段階ではWeb/PredictionLogicは変更しない。")
    print("=" * 132)

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
