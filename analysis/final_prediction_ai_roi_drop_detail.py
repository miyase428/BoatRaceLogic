#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
最終予想 AI活用 STEP B5-2：完全未来期間 ROI低下要因の分解

目的
----
P3でAI化により的中率は大幅改善した一方、回収率が低下した理由を分解する。

比較
----
CURRENT -> WIN_HEAD
CURRENT -> WIN_HEAD_OUTCOME_AITE

確認する内容
------------
- AIで新しく拾った的中（GAIN）
- AI化で失った的中（LOSS）
- 両方で的中（BOTH）
- 払戻の平均 / 中央値 / 最大 / 合計
- 払戻帯別件数
- 100円/点方式での払戻差
- 1R1000円均等方式での払戻差
- 高額なGAIN / LOSS上位

Usage:
python3 analysis/final_prediction_ai_roi_drop_detail.py \
  analysis/output/final_prediction_boats_20260715_20260814_OLD.csv \
  analysis/output/final_prediction_boats_20260815_20260819.csv
"""

from __future__ import annotations

import statistics
import sys
from collections import Counter
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import final_prediction_ai_bet_integration_compare as b4
import final_prediction_ai_opponent_compare as b2
import trifecta_probability_order_compare as step3


SCENARIOS = ("WIN_HEAD", "WIN_HEAD_OUTCOME_AITE")
BUCKETS = (
    (0, 2000, "<2,000円"),
    (2000, 5000, "2,000～4,999円"),
    (5000, 10000, "5,000～9,999円"),
    (10000, 20000, "10,000～19,999円"),
    (20000, float("inf"), "20,000円以上"),
)


def fixed_return(payout: float, bet_count: int) -> float:
    if bet_count <= 0:
        return 0.0
    # 1R1000円を全点均等配分。payoutは100円あたり。
    return float(payout) * (1000.0 / bet_count) / 100.0


def payout_summary(items):
    values = [float(x["payout"]) for x in items]
    if not values:
        return {
            "n": 0,
            "sum": 0.0,
            "avg": 0.0,
            "median": 0.0,
            "max": 0.0,
        }
    return {
        "n": len(values),
        "sum": sum(values),
        "avg": sum(values) / len(values),
        "median": statistics.median(values),
        "max": max(values),
    }


def bucket_counts(items):
    counts = Counter()
    for x in items:
        payout = float(x["payout"])
        for low, high, label in BUCKETS:
            if low <= payout < high:
                counts[label] += 1
                break
    return counts


def classify(rows, scenario):
    groups = {"GAIN": [], "LOSS": [], "BOTH": [], "MISS": []}

    total_current_fixed = 0.0
    total_new_fixed = 0.0
    total_current_return = 0.0
    total_new_return = 0.0
    current_points = 0
    new_points = 0

    for row in rows:
        cur = row["scenarios"]["CURRENT"]
        new = row["scenarios"][scenario]
        actual = row["actual"]
        payout = float(row["payout"])

        cur_count = len(cur["bets"])
        new_count = len(new["bets"])
        current_points += cur_count
        new_points += new_count

        cur_hit = actual in cur["bets"]
        new_hit = actual in new["bets"]

        cur_fixed = fixed_return(payout, cur_count) if cur_hit else 0.0
        new_fixed = fixed_return(payout, new_count) if new_hit else 0.0
        total_current_fixed += cur_fixed
        total_new_fixed += new_fixed

        if cur_hit:
            total_current_return += payout
        if new_hit:
            total_new_return += payout

        item = {
            "race_code": row["race_code"],
            "actual": "-".join(str(x) for x in actual),
            "payout": payout,
            "cur_points": cur_count,
            "new_points": new_count,
            "cur_fixed": cur_fixed,
            "new_fixed": new_fixed,
            "fixed_delta": new_fixed - cur_fixed,
        }

        if new_hit and not cur_hit:
            groups["GAIN"].append(item)
        elif cur_hit and not new_hit:
            groups["LOSS"].append(item)
        elif cur_hit and new_hit:
            groups["BOTH"].append(item)
        else:
            groups["MISS"].append(item)

    return groups, {
        "races": len(rows),
        "current_points": current_points,
        "new_points": new_points,
        "current_return_100": total_current_return,
        "new_return_100": total_new_return,
        "current_fixed_return": total_current_fixed,
        "new_fixed_return": total_new_fixed,
    }


def print_group(label, items):
    s = payout_summary(items)
    print(f"\n{label}")
    print("-" * 90)
    print(f"件数       : {s['n']}R")
    print(f"払戻合計   : {s['sum']:,.0f}円")
    print(f"平均払戻   : {s['avg']:,.0f}円")
    print(f"中央値     : {s['median']:,.0f}円")
    print(f"最大払戻   : {s['max']:,.0f}円")

    counts = bucket_counts(items)
    parts = [f"{label2}={counts[label2]}R" for _, _, label2 in BUCKETS]
    print("払戻帯     : " + " / ".join(parts))


def print_top(title, items, n=10):
    print(f"\n{title}")
    print("-" * 98)
    print("race_code             実3連単   払戻       CURRENT点  AI点   1000円均等差")
    for x in sorted(items, key=lambda z: (-z["payout"], z["race_code"]))[:n]:
        print(
            f"{x['race_code']:<21} {x['actual']:<8} "
            f"{x['payout']:>8,.0f}円   {x['cur_points']:>6d}     {x['new_points']:>4d}   "
            f"{x['fixed_delta']:>+10,.0f}円"
        )


def print_scenario(rows, scenario):
    groups, total = classify(rows, scenario)
    gain = groups["GAIN"]
    loss = groups["LOSS"]
    both = groups["BOTH"]

    print("\n" + "=" * 126)
    print(f"CURRENT → {scenario}")
    print("=" * 126)
    print(
        f"対象={total['races']}R / GAIN={len(gain)}R / LOSS={len(loss)}R / "
        f"BOTH={len(both)}R / MISS={len(groups['MISS'])}R"
    )

    print_group("GAIN：AIで新しく拾った的中", gain)
    print_group("LOSS：AI化で失った的中", loss)
    print_group("BOTH：両方で的中", both)

    gain_sum = payout_summary(gain)["sum"]
    loss_sum = payout_summary(loss)["sum"]

    print("\n【100円/点方式の差分構造】")
    print(f"GAIN払戻合計          : {gain_sum:,.0f}円")
    print(f"LOSS払戻合計          : {loss_sum:,.0f}円")
    print(f"GAIN - LOSS           : {gain_sum - loss_sum:+,.0f}円")
    print(f"全的中払戻 CURRENT    : {total['current_return_100']:,.0f}円")
    print(f"全的中払戻 AI         : {total['new_return_100']:,.0f}円")
    print(f"購入点数 CURRENT      : {total['current_points']:,}点")
    print(f"購入点数 AI           : {total['new_points']:,}点")
    print(f"購入点数差            : {total['new_points'] - total['current_points']:+,}点")

    both_fixed_delta = sum(x["fixed_delta"] for x in both)
    gain_fixed = sum(x["new_fixed"] for x in gain)
    loss_fixed = sum(x["cur_fixed"] for x in loss)

    print("\n【1R1000円均等方式の差分構造】")
    print(f"GAINによる増加        : {gain_fixed:+,.0f}円")
    print(f"LOSSによる減少        : {-loss_fixed:+,.0f}円")
    print(f"BOTHの点数差効果      : {both_fixed_delta:+,.0f}円")
    print(f"合計払戻差            : {total['new_fixed_return'] - total['current_fixed_return']:+,.0f}円")
    print(f"CURRENT払戻           : {total['current_fixed_return']:,.0f}円")
    print(f"AI払戻                : {total['new_fixed_return']:,.0f}円")

    print_top("高額LOSS 上位10件", loss, 10)
    print_top("高額GAIN 上位10件", gain, 10)


def main():
    if len(sys.argv) != 3:
        print(
            "Usage: python3 analysis/final_prediction_ai_roi_drop_detail.py "
            "PRIOR_BOATS_CSV FUTURE_BOATS_CSV"
        )
        sys.exit(1)

    prior_csv, future_csv = sys.argv[1], sys.argv[2]

    print("固定済みAIモデル・未来期間買い目・払戻を再構築中...")
    data = step3.build_common_records(prior_csv, future_csv)
    csv_races = b2.load_boats(prior_csv, future_csv)
    payouts = b4.load_payouts(data["p1_start"], data["p2_end"])
    rows, skip = b4.build_rows(data["records"], csv_races, payouts)
    p3 = rows["P2"]

    if not p3:
        raise RuntimeError("P3評価レースがありません")

    print("=" * 126)
    print("最終予想 AI活用 STEP B5-2：完全未来期間 ROI低下要因")
    print("=" * 126)
    print(f"直前期間             : {data['p1_start']} ～ {data['p1_end']}")
    print(f"P3完全未来期間       : {data['p2_start']} ～ {data['p2_end']}")
    print(f"P3評価母集団         : {len(p3)}R")
    print("比較                 : CURRENT → WIN_HEAD / WIN_HEAD_OUTCOME_AITE")
    print("本番Web変更          : なし")

    for scenario in SCENARIOS:
        print_scenario(p3, scenario)

    print("\n【判断ポイント】")
    print("1. LOSSの平均・最大払戻がGAINより大きいなら、高配当取りこぼしがROI低下の主因")
    print("2. GAIN-LOSSがプラスなのにROIが落ちるなら、購入点数増加の影響が主因")
    print("3. 1000円均等でBOTHの差が大きければ、点数配分の変化も主因")
    print("4. 原因確認後に、本命AIは予想へ採用しつつ買い目を別ルール化するか判断する")
    print("=" * 126)


if __name__ == "__main__":
    main()
