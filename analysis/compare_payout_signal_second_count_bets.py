#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
配当サイン固定後の穴目研究：穴頭候補を固定したうえで、
120通り P(2着|頭) の2着候補数を Top1 / Top2 / Top3 で比較する。

背景
----
前段 compare_payout_signal_head_count_bets.py では primary=イン崩壊 に対して、
- FIX_TOP2
- GAP2_TOP3_ELSE_TOP2（AI3 1-2位差<2ptだけ第3頭追加）
を主候補に絞った。
一方、既存OUTCOME買い目は各頭につき2着候補を最大3艇まで取るため、
平均点数が約21～22点と実戦目標（概ね6～12点）より多かった。

今回は頭候補ロジックを変更せず、2着候補数だけを1/2/3艇へ絞って比較する。
3着候補は現行cutを除くeligible全艇のままとし、2着候補数の効果だけを分離する。

比較
----
FIX2_S1 / FIX2_S2 / FIX2_S3
    非インAI3 Top2を頭、各頭のP(2着|頭)を上位1/2/3艇。

GAP2_S1 / GAP2_S2 / GAP2_S3
    AI3 1-2位差<2ptだけTop3を頭、それ以外Top2。
    各頭のP(2着|頭)を上位1/2/3艇。

評価
----
- 平均頭数 / 頭捕捉
- 正しい頭を含む時に実2着まで拾えた率（頭+2着捕捉）
- 3連単的中率
- 平均点数
- 100円/点ROI / 1R1000円均等ROI（既観察期間なので参考）
- 点数帯 <=6 / 7-12 / 13-18 / >18
- S1→S2→S3で何レース拾えるか
- AI3 gap<2pt帯でGAP2の第3頭追加が残るか

重要
----
- PayoutSignalClassifier v1相当の荒れ判定は変更しない。
- 頭順位はAI3連対率。展開特徴で置換しない。
- 2pt境界も既存固定値のまま。
- 3着候補/cutは変更しない。
- DEV/CONFIRMは既観察期間。ここで最終採用は決めない。
- 最終候補は9/7以降の未使用期間で前方検証する。
- 本命/対抗/PredictionLogic/本番買い目には接続しない。

Usage:
python3 analysis/compare_payout_signal_second_count_bets.py \
  analysis/output/final_prediction_boats_fast_cached_20260815_20260822.csv \
  analysis/output/final_prediction_boats_fast_cached_20260823_20260831.csv \
  analysis/output/final_prediction_boats_fast_cached_20260901_20260905.csv \
  analysis/output/kimarite_analysis_dataset_20260815_20260822.csv \
  analysis/output/kimarite_analysis_dataset_20260823_20260831.csv \
  analysis/output/kimarite_analysis_dataset_20260901_20260905.csv
"""

from __future__ import annotations

import sys
import time
from collections import Counter, defaultdict
from datetime import datetime
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import final_prediction_ai_opponent_compare as b2
import final_prediction_ai_bet_integration_compare as b4
import trifecta_probability_order_compare as step3
import upset_probability_validate as c1
import upset_top2_bet_validate as c4
import validate_payout_signal_hole_bet_candidates as signal_mod
import payout_signal_relative_features as rel

HEAD_RULES = ("FIX2", "GAP2")
SECOND_COUNTS = (1, 2, 3)
METHODS = tuple(f"{h}_S{k}" for h in HEAD_RULES for k in SECOND_COUNTS)


def pct(n: int, d: int) -> float:
    return 100.0 * n / d if d else 0.0


def format_elapsed(seconds: float) -> str:
    sec = max(0, int(round(seconds)))
    m, s = divmod(sec, 60)
    h, m = divmod(m, 60)
    if h:
        return f"{h}時間{m}分{s}秒"
    if m:
        return f"{m}分{s}秒"
    return f"{s}秒"


def trio_gap_pt(trio: dict[int, float], order: list[int]) -> float:
    if len(order) < 2:
        return 0.0
    return (float(trio[order[0]]) - float(trio[order[1]])) * 100.0


def heads_for(order: list[int], trio: dict[int, float], rule: str) -> tuple[int, ...]:
    if rule == "FIX2":
        return tuple(int(x) for x in order[:2])
    if rule == "GAP2":
        k = 3 if trio_gap_pt(trio, order) < 2.0 else 2
        return tuple(int(x) for x in order[:k])
    raise ValueError(rule)


def make_one_head_bets(record: dict, boats: dict, head: int, second_count: int):
    head = int(head)
    cut = b4.current_cut(boats)
    cut.discard(head)
    eligible = [lane for lane in range(1, 7) if lane != head and lane not in cut]
    if len(eligible) < 2:
        return None

    second_scores = b2.outcome_second_scores(record, head)
    second = b2.select_score(second_scores, eligible, min(int(second_count), len(eligible)))
    third = list(eligible)
    bets = b4.expand_bets(head, second, third)
    if not bets:
        return None

    return {
        "head": head,
        "cut": set(cut),
        "eligible": tuple(int(x) for x in eligible),
        "second": tuple(int(x) for x in second),
        "third": tuple(int(x) for x in third),
        "bets": set(bets),
    }


def make_union(record: dict, boats: dict, heads: tuple[int, ...], second_count: int):
    bets = set()
    per_head = {}
    valid_heads = []
    for head in dict.fromkeys(int(h) for h in heads):
        s = make_one_head_bets(record, boats, head, second_count)
        if s is None:
            continue
        valid_heads.append(head)
        per_head[head] = s
        bets |= set(s["bets"])
    if not bets or not valid_heads:
        return None
    return {
        "heads": tuple(valid_heads),
        "per_head": per_head,
        "bets": bets,
    }


def build_row(record: dict, boats: dict, payout: int, kimarite_row: dict):
    if boats is None or set(boats) != set(range(1, 7)):
        return None, "boats_missing"
    if payout is None or int(payout) <= 0:
        return None, "payout_missing"

    signal = signal_mod.classify_kimarite_row(kimarite_row)
    if signal is None:
        return None, "signal_not_ready"
    if str(signal.get("primary") or "平常") != "イン崩壊":
        return None, "not_in_collapse"

    actual = b4.actual_trifecta(boats)
    if actual is None:
        return None, "actual_missing"

    f = c1.make_features(record, boats)
    if f is None:
        return None, "feature_missing"

    in_lane = int(f["in_lane"])
    trio = {int(k): float(v) for k, v in f["trio"].items()}
    order = c4.ranked_outer(trio, in_lane)
    if len(order) < 3:
        return None, "trio_rank_missing"

    scenarios = {}
    for rule in HEAD_RULES:
        heads = heads_for(order, trio, rule)
        for second_count in SECOND_COUNTS:
            name = f"{rule}_S{second_count}"
            s = make_union(record, boats, heads, second_count)
            if s is None:
                return None, f"{name}_missing"
            scenarios[name] = s

    return {
        "race_code": str(record["race_code"]),
        "actual": tuple(int(x) for x in actual),
        "actual_first": int(actual[0]),
        "actual_second": int(actual[1]),
        "actual_third": int(actual[2]),
        "payout": int(payout),
        "in_lane": in_lane,
        "in_failed": int(actual[0]) != in_lane,
        "trio_gap_pt": trio_gap_pt(trio, order),
        "order": tuple(int(x) for x in order),
        "scenarios": scenarios,
    }, None


def build_rows(records, boats_map, payouts, kimarite_map):
    rows = []
    skip = defaultdict(int)
    for record in records:
        code = str(record["race_code"])
        k = kimarite_map.get(code)
        if k is None:
            skip["kimarite_missing"] += 1
            continue
        row, reason = build_row(record, boats_map.get(code), payouts.get(code), k)
        if row is None:
            skip[str(reason or "not_ready")] += 1
            continue
        rows.append(row)
        skip["ready_in_collapse"] += 1
    return rows, skip


def evaluate(rows: list[dict], method: str) -> dict:
    n = len(rows)
    head_hit = pair_hit = exact_hit = 0
    fail_n = fail_head_hit = 0
    points = 0
    head_sum = 0
    invest100 = ret100 = 0.0
    invest_fixed = ret_fixed = 0.0
    hit_payout_sum = 0.0
    bands = Counter()

    for r in rows:
        s = r["scenarios"][method]
        heads = tuple(s["heads"])
        head_sum += len(heads)
        actual = tuple(r["actual"])
        first, second, _ = actual

        hh = first in heads
        head_hit += 1 if hh else 0
        if r["in_failed"]:
            fail_n += 1
            fail_head_hit += 1 if hh else 0

        ph = False
        if hh:
            per = s["per_head"].get(first)
            if per is not None and second in per["second"]:
                ph = True
        pair_hit += 1 if ph else 0

        bets = set(s["bets"])
        cnt = len(bets)
        points += cnt
        invest100 += cnt * 100.0
        invest_fixed += 1000.0
        if cnt <= 6:
            bands["<=6"] += 1
        elif cnt <= 12:
            bands["7-12"] += 1
        elif cnt <= 18:
            bands["13-18"] += 1
        else:
            bands[">18"] += 1

        if actual in bets:
            exact_hit += 1
            payout = float(r["payout"])
            hit_payout_sum += payout
            ret100 += payout
            ret_fixed += payout * ((1000.0 / cnt) / 100.0)

    return {
        "n": n,
        "avg_heads": head_sum / n if n else 0.0,
        "avg_points": points / n if n else 0.0,
        "head_rate": pct(head_hit, n),
        "fail_head_rate": pct(fail_head_hit, fail_n),
        "pair_rate": pct(pair_hit, n),
        "exact_hits": exact_hit,
        "exact_rate": pct(exact_hit, n),
        "roi100": 100.0 * ret100 / invest100 if invest100 else 0.0,
        "roi_fixed": 100.0 * ret_fixed / invest_fixed if invest_fixed else 0.0,
        "avg_hit_payout": hit_payout_sum / exact_hit if exact_hit else 0.0,
        "bands": bands,
    }


def compare(rows: list[dict], base: str, test: str) -> dict:
    changed = gained = lost = both = neither = 0
    for r in rows:
        bb = set(r["scenarios"][base]["bets"])
        tb = set(r["scenarios"][test]["bets"])
        changed += 1 if bb != tb else 0
        bh = tuple(r["actual"]) in bb
        th = tuple(r["actual"]) in tb
        if th and not bh:
            gained += 1
        elif bh and not th:
            lost += 1
        elif bh and th:
            both += 1
        else:
            neither += 1
    return {
        "changed": changed,
        "gained": gained,
        "lost": lost,
        "both": both,
        "neither": neither,
    }


def print_table(title: str, rows: list[dict]) -> dict[str, dict]:
    print(f"\n【{title}: 2着候補数比較】")
    print(
        "方式        平均頭  平均点数  全R頭捕捉  1C敗戦時頭  頭+2着捕捉  3連単的中  "
        "100円ROI  1000円ROI   <=6/7-12/13-18/>18"
    )
    print("-" * 130)
    out = {}
    for method in METHODS:
        m = evaluate(rows, method)
        out[method] = m
        b = m["bands"]
        band = f"{b.get('<=6',0)}/{b.get('7-12',0)}/{b.get('13-18',0)}/{b.get('>18',0)}"
        print(
            f"{method:<11} {m['avg_heads']:>6.3f}  {m['avg_points']:>7.2f}   "
            f"{m['head_rate']:>7.2f}%     {m['fail_head_rate']:>7.2f}%      "
            f"{m['pair_rate']:>7.2f}%    {m['exact_rate']:>7.2f}%   "
            f"{m['roi100']:>7.2f}%   {m['roi_fixed']:>8.2f}%   {band}"
        )
    return out


def print_increment(title: str, rows: list[dict], rule: str) -> None:
    print(f"\n【{title}: {rule} S1→S2→S3の的中純増】")
    print("比較       買目変更   拾い   失い   両方的中   平均点数差")
    print("-" * 72)
    for a, b in ((1, 2), (2, 3), (1, 3)):
        base = f"{rule}_S{a}"
        test = f"{rule}_S{b}"
        c = compare(rows, base, test)
        ma = evaluate(rows, base)
        mb = evaluate(rows, test)
        print(
            f"S{a}->S{b:<3}   {c['changed']:>7d}   {c['gained']:>4d}   {c['lost']:>4d}   "
            f"{c['both']:>8d}    {mb['avg_points']-ma['avg_points']:>+7.2f}点"
        )


def print_gap2_band(title: str, rows: list[dict]) -> None:
    part = [r for r in rows if float(r["trio_gap_pt"]) < 2.0]
    print(f"\n【{title}: AI3 1-2位差<2ptだけ={len(part)}R】")
    print("方式        平均頭  平均点数  頭捕捉  頭+2着捕捉  3連単的中")
    print("-" * 78)
    for second_count in SECOND_COUNTS:
        for rule in HEAD_RULES:
            method = f"{rule}_S{second_count}"
            m = evaluate(part, method)
            print(
                f"{method:<11} {m['avg_heads']:>6.3f}  {m['avg_points']:>7.2f}  "
                f"{m['head_rate']:>6.2f}%    {m['pair_rate']:>7.2f}%    {m['exact_rate']:>7.2f}%"
            )
        print("-")


def print_reproduction(dev: dict[str, dict], confirm: dict[str, dict]) -> None:
    print("\n【DEV→CONFIRM 再現】")
    print("方式        DEV点数 CONF点数  DEV頭+2着 CONF頭+2着  DEV的中 CONF的中  DEV1000ROI CONF1000ROI")
    print("-" * 108)
    for method in METHODS:
        d = dev[method]
        c = confirm[method]
        print(
            f"{method:<11} {d['avg_points']:>7.2f} {c['avg_points']:>8.2f}   "
            f"{d['pair_rate']:>7.2f}%   {c['pair_rate']:>7.2f}%   "
            f"{d['exact_rate']:>7.2f}% {c['exact_rate']:>7.2f}%   "
            f"{d['roi_fixed']:>8.2f}%   {c['roi_fixed']:>8.2f}%"
        )


def main() -> None:
    if len(sys.argv) != 7:
        print(
            "Usage: python3 analysis/compare_payout_signal_second_count_bets.py "
            "DEV1_BOATS DEV2_BOATS CONFIRM_BOATS DEV1_KIMARITE DEV2_KIMARITE CONFIRM_KIMARITE"
        )
        sys.exit(1)

    p1_csv, p2_csv, p3_csv, k1_csv, k2_csv, k3_csv = sys.argv[1:]
    t0 = time.perf_counter()
    print(f"開始時刻 : {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}", flush=True)
    print("共通レコード構築中...", flush=True)

    dev_common = step3.build_common_records(p1_csv, p2_csv)
    confirm_common = step3.build_common_records(p2_csv, p3_csv)
    dev_records = list(dev_common["records"]["P1"]) + list(dev_common["records"]["P2"])
    confirm_records = list(confirm_common["records"]["P2"])

    print("CSV/決まり手/払戻読込中...", flush=True)
    boats_map = b2.load_boats(p1_csv, p2_csv, p3_csv)
    kimarite_map = rel.load_kimarite(k1_csv, k2_csv, k3_csv)
    payouts = b4.load_payouts(dev_common["p1_start"], confirm_common["p2_end"])

    dev_rows, dev_skip = build_rows(dev_records, boats_map, payouts, kimarite_map)
    confirm_rows, confirm_skip = build_rows(confirm_records, boats_map, payouts, kimarite_map)

    print("=" * 124)
    print("配当サイン固定：穴頭を固定して2着候補Top1/Top2/Top3を比較")
    print("=" * 124)
    print(f"DEV     : {dev_common['p1_start']} ～ {dev_common['p2_end']}")
    print(f"CONFIRM : {confirm_common['p2_start']} ～ {confirm_common['p2_end']} ※既観察期間")
    print("対象     : primary=イン崩壊")
    print("頭       : FIX2 / GAP2(<2ptだけTop3) の2候補")
    print("2着      : 各頭の120通り P(2着|頭) 上位1/2/3艇")
    print("3着      : 現行cutを除くeligible全艇（今回変更しない）")
    print("目的     : 頭方式を壊さず、平均6～12点へ近づけられるか")
    print("本番変更 : なし")

    dev = print_table("DEV", dev_rows)
    confirm = print_table("CONFIRM", confirm_rows)
    print_increment("DEV", dev_rows, "FIX2")
    print_increment("CONFIRM", confirm_rows, "FIX2")
    print_increment("DEV", dev_rows, "GAP2")
    print_increment("CONFIRM", confirm_rows, "GAP2")
    print_gap2_band("DEV", dev_rows)
    print_gap2_band("CONFIRM", confirm_rows)
    print_reproduction(dev, confirm)

    print("\n【判断方針】")
    print("1. まずS1/S2で6～12点へ近づけるかを見る。S3は前段OUTCOMEの再現基準")
    print("2. S1→S2の追加点数に対する的中純増がDEV/CONFIRM双方で再現するかを見る")
    print("3. S2→S3の純増が小さければ2着Top2までを優先候補にする")
    print("4. GAP2の第3頭追加が2着を絞った後でも価値を維持するか確認する")
    print("5. 3着候補/cutは今回触らない。2着方式を決めてから次段で絞る")
    print("6. 最終候補は9/7以降の未使用前方検証。本命/対抗には未接続")

    print("\njoin/skip DEV    :", dict(dev_skip))
    print("join/skip CONFIRM:", dict(confirm_skip))
    print("=" * 124)
    print(f"総所要時間 : {format_elapsed(time.perf_counter()-t0)}")


if __name__ == "__main__":
    main()
