#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
STEP D1：穴警戒HIGHの配当帯別・頭候補比較

目的
----
C2～C6で作った「穴警戒HIGH」と穴頭信頼度とは別軸で、
実際の3連単払戻が中配当・高配当になったレースでは、
どの頭候補方式が実1着を拾いやすかったかを確認する。

ここではまだ「配当帯を事前予測」しない。
まず結果側の配当帯で分けて、頭の性質が本当に違うかを診断する。
違いが再現するなら、次段階でレース前情報だけを使った配当帯予測へ進む。

対象母集団
----------
C2固定の穴警戒HIGH：
    AI本命 = 1C
    CURRENT本命 != 1C
    インAI1着率 < 50%

配当帯
------
LOW        : < 5,000円（比較用）
MIDDLE     : 5,000～9,999円
HIGH       : 10,000～19,999円
VERY_HIGH  : 20,000円以上
HIGH_PLUS  : 10,000円以上（HIGH + VERY_HIGH）

比較する頭候補
--------------
C3と同じ7方式：
CURRENT_HEAD / WIN_OUTER / OUTCOME_OUTER / TRIO_OUTER /
PRIMARY_OUTER / SECONDARY_OUTER / FINAL3_OUTER

評価
----
- 配当帯ごとのR数、1C敗退率
- 実1着コース分布
- 各候補方式の全体頭捕捉率
- 1C敗退時の頭捕捉率
- TRAIN→P3で中配当/高配当の最良方式が再現するか

重要
----
- 結果の払戻で帯分けしているため、この段階では本番表示に使わない。
- P3を見て配当閾値や候補方式を調整しない。
- 本番Web/PredictionLogic/買い目は変更しない。

Usage:
python3 analysis/upset_payout_band_head_compare.py \
  analysis/output/final_prediction_boats_20260615_20260714_OLD.csv \
  analysis/output/final_prediction_boats_20260715_20260814_OLD.csv \
  analysis/output/final_prediction_boats_20260815_20260820.csv
"""

from __future__ import annotations

import sys
from collections import Counter, defaultdict
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import final_prediction_ai_opponent_compare as b2
import final_prediction_ai_bet_integration_compare as b4
import trifecta_probability_order_compare as step3
import upset_head_candidate_compare as c3


BANDS = (
    ("LOW", lambda p: p < 5000),
    ("MIDDLE", lambda p: 5000 <= p < 10000),
    ("HIGH", lambda p: 10000 <= p < 20000),
    ("VERY_HIGH", lambda p: p >= 20000),
    ("HIGH_PLUS", lambda p: p >= 10000),
)


def build_rows(records, boats_map, payouts):
    rows = []
    skip = defaultdict(int)
    for record in records:
        code = str(record["race_code"])
        boats = boats_map.get(code)
        payout = payouts.get(code)
        if boats is None or set(boats) != set(range(1, 7)):
            skip["csv_missing"] += 1
            continue
        if payout is None or int(payout) <= 0:
            skip["payout_missing"] += 1
            continue
        row = c3.build_candidate_row(record, boats)
        if row is None:
            skip["not_ready_or_not_high"] += 1
            continue
        row = dict(row)
        row["payout"] = int(payout)
        rows.append(row)
        skip["ready_high"] += 1
    return rows, skip


def band_rows(rows, pred):
    return [r for r in rows if pred(int(r["payout"]))]


def actual_course_counts(rows):
    counts = Counter()
    for r in rows:
        lane = int(r["actual_first"])
        course = int(r["course_by_lane"].get(lane, 0))
        counts[course] += 1
    return counts


def course_distribution_text(rows):
    n = len(rows)
    if n == 0:
        return "-"
    counts = actual_course_counts(rows)
    return " / ".join(
        f"{c}C={counts.get(c, 0)/n*100:.1f}%"
        for c in range(1, 7)
        if counts.get(c, 0)
    )


def summarize_band(rows):
    n = len(rows)
    in_fail = sum(1 for r in rows if r["in_failed"])
    avg_payout = sum(int(r["payout"]) for r in rows) / n if n else 0.0
    return {
        "n": n,
        "in_fail": in_fail,
        "in_fail_rate": in_fail / n if n else 0.0,
        "avg_payout": avg_payout,
    }


def evaluate_methods(rows):
    return {method: c3.evaluate(rows, method) for method in c3.METHODS}


def best_method(results, key):
    if not results:
        return "-"
    return min(
        results,
        key=lambda m: (-float(results[m][key]), m),
    )


def print_overview(title, rows):
    print(f"\n【{title}：配当帯概要】")
    print("配当帯       R数  構成比  平均払戻  1C敗退率  実1着コース分布")
    print("-" * 104)
    total = len(rows)
    parts = {}
    for label, pred in BANDS:
        part = band_rows(rows, pred)
        parts[label] = part
        s = summarize_band(part)
        share = s["n"] / total if total else 0.0
        print(
            f"{label:<10} {s['n']:>5d}  {share*100:>6.2f}%  {s['avg_payout']:>8.0f}円  "
            f"{s['in_fail_rate']*100:>7.2f}%  {course_distribution_text(part)}"
        )
    return parts


def print_method_table(title, parts):
    print(f"\n【{title}：配当帯別 頭候補比較】")
    for label in ("MIDDLE", "HIGH", "VERY_HIGH", "HIGH_PLUS"):
        rows = parts[label]
        s = summarize_band(rows)
        results = evaluate_methods(rows)
        print(f"\n--- {label}  {s['n']}R / 1C敗退={s['in_fail']}R ({s['in_fail_rate']*100:.2f}%) ---")
        print("方式                 全体頭捕捉   1C敗退時頭捕捉   実3連対率")
        print("-" * 72)
        for method in c3.METHODS:
            r = results[method]
            print(
                f"{method:<20} {r['win_rate']*100:>8.2f}%      "
                f"{r['in_fail_win_rate']*100:>8.2f}%      {r['top3_rate']*100:>8.2f}%"
            )
        print(
            f"最良（全体頭）={best_method(results, 'win_rate')} / "
            f"最良（1C敗退頭）={best_method(results, 'in_fail_win_rate')}"
        )


def compact_result(parts, label):
    rows = parts[label]
    results = evaluate_methods(rows)
    return {
        "n": len(rows),
        "in_fail": sum(1 for r in rows if r["in_failed"]),
        "results": results,
        "best_all": best_method(results, "win_rate"),
        "best_fail": best_method(results, "in_fail_win_rate"),
    }


def print_reproduction(train_parts, p3_parts):
    print("\n【TRAIN→P3：中配当/高配当の再現確認】")
    print("配当帯      TRAIN_R  TRAIN最良(1C敗退頭)  P3_R  P3最良(1C敗退頭)  TRIO_OUTER TRAIN→P3")
    print("-" * 112)
    for label in ("MIDDLE", "HIGH", "VERY_HIGH", "HIGH_PLUS"):
        tr = compact_result(train_parts, label)
        te = compact_result(p3_parts, label)
        tr_trio = tr["results"]["TRIO_OUTER"]["in_fail_win_rate"] if tr["n"] else 0.0
        te_trio = te["results"]["TRIO_OUTER"]["in_fail_win_rate"] if te["n"] else 0.0
        print(
            f"{label:<10} {tr['n']:>7d}  {tr['best_fail']:<22} "
            f"{te['n']:>5d}  {te['best_fail']:<20}  "
            f"{tr_trio*100:>6.2f}% -> {te_trio*100:>6.2f}%"
        )

    print("\n【見るポイント】")
    print("1. MIDDLEとHIGH_PLUSで最良の頭候補方式が違うか")
    print("2. その違いがTRAINとP3で同方向に再現するか")
    print("3. 高配当ほど4～6Cの実頭比率が増えるか")
    print("4. TRIO_OUTERが配当帯をまたいで安定するか、特定帯だけ強いか")
    print("5. 差が再現しなければ、配当帯別に頭方式を分けない")


def main():
    if len(sys.argv) != 4:
        print(
            "Usage: python3 analysis/upset_payout_band_head_compare.py "
            "P1_BOATS_CSV P2_BOATS_CSV P3_BOATS_CSV"
        )
        sys.exit(1)

    p1_csv, p2_csv, p3_csv = sys.argv[1], sys.argv[2], sys.argv[3]

    print("共通レコード構築中...")
    train_data = step3.build_common_records(p1_csv, p2_csv)
    future_data = step3.build_common_records(p2_csv, p3_csv)

    p1_records = train_data["records"]["P1"]
    p2_records = train_data["records"]["P2"]
    p3_records = future_data["records"]["P2"]

    print("CSV・払戻読込中...")
    boats_map = b2.load_boats(p1_csv, p2_csv, p3_csv)
    payouts = b4.load_payouts(train_data["p1_start"], future_data["p2_end"])

    print("穴警戒HIGH行を構築中...")
    p1_rows, _ = build_rows(p1_records, boats_map, payouts)
    p2_rows, _ = build_rows(p2_records, boats_map, payouts)
    p3_rows, _ = build_rows(p3_records, boats_map, payouts)
    train_rows = p1_rows + p2_rows

    print("=" * 126)
    print("STEP D1：穴警戒HIGHの配当帯別・頭候補比較")
    print("=" * 126)
    print(f"TRAIN : {train_data['p1_start']} ～ {train_data['p2_end']}")
    print(f"P3    : {future_data['p2_start']} ～ {future_data['p2_end']} 完全未来")
    print(f"HIGH対象: TRAIN={len(train_rows)}R / P3={len(p3_rows)}R")
    print("配当帯: MIDDLE=5,000～9,999円 / HIGH=10,000～19,999円 / VERY_HIGH=20,000円以上")
    print("HIGH_PLUS=10,000円以上")
    print("本番Web/PredictionLogic変更: なし")

    train_parts = print_overview("TRAIN", train_rows)
    p3_parts = print_overview("P3完全未来", p3_rows)

    print_method_table("TRAIN", train_parts)
    print_method_table("P3完全未来", p3_parts)
    print_reproduction(train_parts, p3_parts)

    print("=" * 126)


if __name__ == "__main__":
    main()
