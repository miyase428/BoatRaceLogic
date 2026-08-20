#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
最終予想 AI活用 STEP B3：切る艇のAI救済

目的
----
現行kiru判定そのものは固定し、現在cutになっている艇のうち
AIが強く残している艇だけ救済した場合に、3連対取りこぼしを
どこまで減らせるかをP1/P2で検証する。

比較するAI信号
--------------
TRIO
    ENTRY_MODE AI3連対率。

OUTCOME_TOP3
    STEP3最終120通りから艇別に合算した最終3連対周辺確率。

重要
----
- 現行kiruの生成ロジックは変更しない。
- 救済対象は「現在kiru=1の艇」だけ。
- 全艇を残せば取りこぼし0になるため、救済率だけでは判断しない。
- 救済精度、1R平均救済艇数、残存cut数も同時に見る。
- B2で残った「現行本命1着かつ実2着がkiru」も何件救えるか表示する。
- 本番ロジックは変更しない。

Usage:
python3 analysis/final_prediction_ai_cut_rescue_compare.py \
  analysis/output/final_prediction_boats_20260615_20260714_OLD.csv \
  analysis/output/final_prediction_boats_20260715_20260814_OLD.csv
"""

from __future__ import annotations

import sys
from collections import defaultdict
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import final_prediction_ai_favorite_compare as b1
import final_prediction_ai_opponent_compare as b2
import trifecta_probability_order_compare as step3

ORDER_DELTA = 0.25
ORDER_GAMMA = 0.25
THRESHOLDS = (0.30, 0.35, 0.40, 0.45, 0.50, 0.55, 0.60, 0.65, 0.70)


def outcome_top3_scores(record):
    """STEP3最終120通りを艇別3連対周辺確率へ集約する。"""
    probs = step3.order_adjusted_probs(record, ORDER_DELTA, ORDER_GAMMA)
    scores = {lane: 0.0 for lane in range(1, 7)}
    for idx, lanes in enumerate(record["pattern_lanes"]):
        p = float(probs[idx])
        for lane in lanes:
            scores[int(lane)] += p
    return scores


def build_rows(common, csv_races):
    out = {"P1": [], "P2": []}
    skip = defaultdict(int)

    for period in ("P1", "P2"):
        for record in common[period]:
            code = str(record["race_code"])
            boats = csv_races.get(code)
            if boats is None or set(boats) != set(range(1, 7)):
                skip[f"{period}_csv_missing"] += 1
                continue

            rank_boats, head = b2.current_order_and_head(boats)
            if not rank_boats or head is None:
                skip[f"{period}_current_invalid"] += 1
                continue

            # 1～3着艇が一意に取れるレースだけ、cut誤りを正しく評価できる。
            actual_by_rank = {}
            valid = True
            for rank_no in (1, 2, 3):
                lanes = [lane for lane, row in boats.items() if float(row["actual_rank"]) == float(rank_no)]
                if len(lanes) != 1:
                    valid = False
                    break
                actual_by_rank[rank_no] = int(lanes[0])
            if not valid:
                skip[f"{period}_actual_top3_invalid"] += 1
                continue

            _, trio, _ = b1.marginal_signals(record)
            outcome_top3 = outcome_top3_scores(record)

            cut = {lane for lane, row in boats.items() if int(row["kiru"]) == 1}
            actual_top3 = set(actual_by_rank.values())
            head_won = actual_by_rank[1] == head
            actual_second = actual_by_rank[2]

            out[period].append({
                "race_code": code,
                "head": head,
                "head_won": head_won,
                "actual_second": actual_second,
                "actual_top3": actual_top3,
                "cut": cut,
                "trio": {lane: float(trio[lane]) for lane in range(1, 7)},
                "outcome_top3": {lane: float(outcome_top3[lane]) for lane in range(1, 7)},
            })
            skip[f"{period}_ready"] += 1

    return out, skip


def current_stats(rows):
    total_cut = 0
    cut_top3 = 0
    races_with_cut = 0
    head_win = 0
    head_win_second_cut = 0

    for r in rows:
        cut = r["cut"]
        total_cut += len(cut)
        if cut:
            races_with_cut += 1
        cut_top3 += len(cut & r["actual_top3"])
        if r["head_won"]:
            head_win += 1
            if r["actual_second"] in cut:
                head_win_second_cut += 1

    n = len(rows)
    return {
        "races": n,
        "total_cut": total_cut,
        "avg_cut": total_cut / n if n else 0.0,
        "cut_top3": cut_top3,
        "cut_top3_rate": cut_top3 / total_cut if total_cut else 0.0,
        "races_with_cut": races_with_cut,
        "head_win": head_win,
        "head_win_second_cut": head_win_second_cut,
    }


def evaluate_rule(rows, signal, threshold):
    rescued = 0
    rescued_top3 = 0
    rescued_non_top3 = 0
    total_cut_top3 = 0
    remaining_cut = 0
    head_win_second_cut = 0
    head_win_second_rescued = 0

    for r in rows:
        cut = r["cut"]
        total_cut_top3 += len(cut & r["actual_top3"])

        if r["head_won"] and r["actual_second"] in cut:
            head_win_second_cut += 1

        rescued_set = {
            lane for lane in cut
            if float(r[signal].get(lane, 0.0)) >= threshold
        }

        rescued += len(rescued_set)
        hit = len(rescued_set & r["actual_top3"])
        rescued_top3 += hit
        rescued_non_top3 += len(rescued_set) - hit
        remaining_cut += len(cut - rescued_set)

        if (
            r["head_won"]
            and r["actual_second"] in cut
            and r["actual_second"] in rescued_set
        ):
            head_win_second_rescued += 1

    n = len(rows)
    precision = rescued_top3 / rescued if rescued else 0.0
    recall = rescued_top3 / total_cut_top3 if total_cut_top3 else 0.0
    second_recall = (
        head_win_second_rescued / head_win_second_cut
        if head_win_second_cut else 0.0
    )

    return {
        "rescued": rescued,
        "rescued_top3": rescued_top3,
        "rescued_non_top3": rescued_non_top3,
        "precision": precision,
        "recall": recall,
        "avg_rescue": rescued / n if n else 0.0,
        "avg_remaining_cut": remaining_cut / n if n else 0.0,
        "head_win_second_cut": head_win_second_cut,
        "head_win_second_rescued": head_win_second_rescued,
        "second_recall": second_recall,
    }


def rank_rule(rows, signal, max_rank=3):
    rescued = 0
    rescued_top3 = 0
    rescued_non_top3 = 0
    total_cut_top3 = 0
    remaining_cut = 0
    head_win_second_cut = 0
    head_win_second_rescued = 0

    for r in rows:
        cut = r["cut"]
        total_cut_top3 += len(cut & r["actual_top3"])
        ranked = sorted(range(1, 7), key=lambda lane: (-float(r[signal].get(lane, 0.0)), lane))
        rank_map = {lane: idx + 1 for idx, lane in enumerate(ranked)}
        rescued_set = {lane for lane in cut if rank_map.get(lane, 99) <= max_rank}

        rescued += len(rescued_set)
        hit = len(rescued_set & r["actual_top3"])
        rescued_top3 += hit
        rescued_non_top3 += len(rescued_set) - hit
        remaining_cut += len(cut - rescued_set)

        if r["head_won"] and r["actual_second"] in cut:
            head_win_second_cut += 1
            if r["actual_second"] in rescued_set:
                head_win_second_rescued += 1

    n = len(rows)
    return {
        "rescued": rescued,
        "rescued_top3": rescued_top3,
        "rescued_non_top3": rescued_non_top3,
        "precision": rescued_top3 / rescued if rescued else 0.0,
        "recall": rescued_top3 / total_cut_top3 if total_cut_top3 else 0.0,
        "avg_rescue": rescued / n if n else 0.0,
        "avg_remaining_cut": remaining_cut / n if n else 0.0,
        "head_win_second_cut": head_win_second_cut,
        "head_win_second_rescued": head_win_second_rescued,
        "second_recall": (
            head_win_second_rescued / head_win_second_cut
            if head_win_second_cut else 0.0
        ),
    }


def print_rule(label, result):
    print(
        f"{label:<22} "
        f"{result['rescued']:>5d}  "
        f"{result['rescued_top3']:>5d}  "
        f"{result['precision']*100:>6.2f}%  "
        f"{result['recall']*100:>6.2f}%  "
        f"{result['avg_rescue']:>6.3f}  "
        f"{result['avg_remaining_cut']:>6.3f}  "
        f"{result['head_win_second_rescued']:>4d}/{result['head_win_second_cut']:<4d} "
        f"{result['second_recall']*100:>6.2f}%"
    )


def print_period(title, rows):
    base = current_stats(rows)
    print(f"\n【{title}】")
    print(
        f"対象={base['races']}R / 現行cut={base['total_cut']}艇 "
        f"(平均{base['avg_cut']:.3f}艇/R) / cutなのに実3連対={base['cut_top3']}艇 "
        f"({base['cut_top3_rate']*100:.2f}% of cut)"
    )
    print(
        f"現行本命1着={base['head_win']}R / そのうち実2着がcut={base['head_win_second_cut']}R"
    )

    print("\n順位救済")
    print("方式                    救済艇  真3連対  救済精度  cut誤り救済率  平均救済/R 残cut/R  本命1着&2着cut救済")
    print("-" * 116)
    for signal, label in (("trio", "TRIO_RANK<=3"), ("outcome_top3", "OUTCOME_RANK<=3")):
        print_rule(label, rank_rule(rows, signal, 3))

    for signal, title2 in (("trio", "AI3連対率 threshold"), ("outcome_top3", "出目モデル3連対 threshold")):
        print(f"\n{title2}")
        print("方式                    救済艇  真3連対  救済精度  cut誤り救済率  平均救済/R 残cut/R  本命1着&2着cut救済")
        print("-" * 116)
        prefix = "TRIO" if signal == "trio" else "OUTCOME"
        for th in THRESHOLDS:
            print_rule(f"{prefix}>={th*100:.0f}%", evaluate_rule(rows, signal, th))


def main():
    if len(sys.argv) != 3:
        print("Usage: python3 analysis/final_prediction_ai_cut_rescue_compare.py P1_BOATS_CSV P2_BOATS_CSV")
        sys.exit(1)

    p1_csv, p2_csv = sys.argv[1], sys.argv[2]
    print("STEP3共通AIデータと現行最終予想CSVを結合中...")
    data = step3.build_common_records(p1_csv, p2_csv)
    csv_races = b2.load_boats(p1_csv, p2_csv)
    rows, skip = build_rows(data["records"], csv_races)

    print("=" * 126)
    print("最終予想 AI活用 STEP B3：切る艇のAI救済")
    print("=" * 126)
    print(f"P1                  : {data['p1_start']} ～ {data['p1_end']}")
    print(f"P2完全ホールドアウト: {data['p2_start']} ～ {data['p2_end']}")
    print("現行kiru            : CSVのkiruを固定")
    print("TRIO                : ENTRY_MODE AI3連対率")
    print("OUTCOME_TOP3        : STEP3最終120通りの艇別3連対周辺確率")
    print("救済対象             : 現行kiru=1の艇だけ")
    print("本番Web変更          : なし")
    print(f"\n【共通評価母集団】P1={len(rows['P1'])}R / P2={len(rows['P2'])}R")

    print_period("P1 参考", rows["P1"])
    print_period("P2 ホールドアウト（最重要）", rows["P2"])

    print("\n【判断方針】")
    print("1. cut誤り救済率だけでなく救済精度と平均救済艇数を必ず同時に見る")
    print("2. P1/P2で同じthresholdの傾向が再現するか確認する")
    print("3. B2で残った『本命1着かつ実2着がcut』を何件救えるかも確認する")
    print("4. thresholdはこの結果を見てから決め、本番kiruはまだ変更しない")
    print("=" * 126)


if __name__ == "__main__":
    main()
