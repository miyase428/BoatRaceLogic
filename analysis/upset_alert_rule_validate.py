#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
STEP C2：穴警戒ルール初版 + 穴頭候補検証

目的
----
C1で見えた

  - AI本命は1C
  - CURRENT本命は1C以外
  - インAI1着率がそれほど高くない

という状態を、まず説明可能な単純ルールとして評価する。
本命予想ロジックには混ぜず、穴狙い用の独立指標候補として扱う。

初版ルール（P3で再調整しない）
-------------------------------
高警戒
    AI本命=1C AND CURRENT本命!=1C AND インAI1着率 < 50%

中警戒
    AI本命=1C AND CURRENT本命!=1C AND 50% <= インAI1着率 < 60%

低警戒
    上記以外

穴頭候補
--------
まずは CURRENT本命 をそのまま穴頭候補として評価する。
「警戒条件は良いがCURRENT本命の頭精度が低い」場合は、次STEPで
外艇のAI1着率/出目頭確率/展示/STなどから穴頭候補を再設計する。

評価
----
- レース数 / 構成比
- 1C敗退率
- 3連単5,000円以上率
- 3連単10,000円以上率
- 3連単20,000円以上率
- 平均払戻 / 中央払戻
- CURRENT本命の1着率 / 3連対率
- 1C敗退時にCURRENT本命が実1着だった率
- 実際の1着コース分布

さらに、AI=1C & CURRENT!=1C の母集団をインAI1着率帯で細分化し、
どの帯から穴警戒が濃くなるかを確認する。

Usage:
python3 analysis/upset_alert_rule_validate.py \
  analysis/output/final_prediction_boats_20260615_20260714_OLD.csv \
  analysis/output/final_prediction_boats_20260715_20260814_OLD.csv \
  analysis/output/final_prediction_boats_20260815_20260819.csv
"""

from __future__ import annotations

import statistics
import sys
from collections import Counter
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import final_prediction_ai_opponent_compare as b2
import final_prediction_ai_bet_integration_compare as b4
import trifecta_probability_order_compare as step3
import upset_probability_validate as c1


PAYOUT_THRESHOLDS = (5000, 10000, 20000)
IN_BANDS = (
    (None, 0.40, "<40%"),
    (0.40, 0.45, "40-45%"),
    (0.45, 0.50, "45-50%"),
    (0.50, 0.55, "50-55%"),
    (0.55, 0.60, "55-60%"),
    (0.60, None, ">=60%"),
)


def warning_level(row):
    """C1結果を受けて先に固定した、説明可能な初版ルール。"""
    v = row["values"]
    ai_is_in = row["ai_head"] == row["in_lane"]
    current_is_other = row["current_head"] != row["in_lane"]
    in_p = float(v["in_win_p"])

    if ai_is_in and current_is_other and in_p < 0.50:
        return "HIGH"
    if ai_is_in and current_is_other and in_p < 0.60:
        return "MEDIUM"
    return "LOW"


def in_band(value):
    x = float(value)
    for lo, hi, label in IN_BANDS:
        if lo is not None and x < lo:
            continue
        if hi is not None and x >= hi:
            continue
        return label
    return "?"


def actual_winner_course(row):
    winner_lane = int(row["actual"][0])
    return int(row["course_by_lane"].get(winner_lane, 0))


def aggregate(rows):
    n = len(rows)
    if n <= 0:
        return {
            "n": 0,
            "in_loss": 0,
            "in_loss_rate": 0.0,
            "payout_rates": {th: 0.0 for th in PAYOUT_THRESHOLDS},
            "payout_counts": {th: 0 for th in PAYOUT_THRESHOLDS},
            "avg_payout": 0.0,
            "median_payout": 0.0,
            "current_win": 0,
            "current_win_rate": 0.0,
            "current_trio": 0,
            "current_trio_rate": 0.0,
            "current_win_on_in_loss": 0,
            "current_win_on_in_loss_rate": 0.0,
            "winner_courses": Counter(),
        }

    in_loss = 0
    current_win = 0
    current_trio = 0
    current_win_on_in_loss = 0
    payouts = []
    p_counts = {th: 0 for th in PAYOUT_THRESHOLDS}
    winner_courses = Counter()

    for r in rows:
        actual = tuple(int(x) for x in r["actual"])
        winner = actual[0]
        is_in_loss = winner != int(r["in_lane"])
        if is_in_loss:
            in_loss += 1

        if int(r["current_head"]) == winner:
            current_win += 1
            if is_in_loss:
                current_win_on_in_loss += 1

        if int(r["current_head"]) in actual:
            current_trio += 1

        payout = int(r["payout"])
        payouts.append(payout)
        for th in PAYOUT_THRESHOLDS:
            if payout >= th:
                p_counts[th] += 1

        winner_courses[actual_winner_course(r)] += 1

    return {
        "n": n,
        "in_loss": in_loss,
        "in_loss_rate": in_loss / n,
        "payout_rates": {th: p_counts[th] / n for th in PAYOUT_THRESHOLDS},
        "payout_counts": p_counts,
        "avg_payout": sum(payouts) / n,
        "median_payout": statistics.median(payouts),
        "current_win": current_win,
        "current_win_rate": current_win / n,
        "current_trio": current_trio,
        "current_trio_rate": current_trio / n,
        "current_win_on_in_loss": current_win_on_in_loss,
        "current_win_on_in_loss_rate": current_win_on_in_loss / in_loss if in_loss else 0.0,
        "winner_courses": winner_courses,
    }


def pct(v):
    return f"{v*100:.2f}%"


def print_summary_table(title, rows):
    print(f"\n【{title}】")
    print(
        "区分       R数   構成比  1C敗退率  >=5千円   >=1万円  >=2万円  "
        "CURRENT1着 CURRENT3連  1C敗退時CURRENT頭"
    )
    print("-" * 122)

    total = len(rows)
    out = {}
    for level in ("HIGH", "MEDIUM", "LOW"):
        part = [r for r in rows if warning_level(r) == level]
        s = aggregate(part)
        out[level] = s
        share = s["n"] / total if total else 0.0
        print(
            f"{level:<9} {s['n']:>4d}  {share*100:>6.2f}%   {s['in_loss_rate']*100:>7.2f}%  "
            f"{s['payout_rates'][5000]*100:>7.2f}%  {s['payout_rates'][10000]*100:>7.2f}%  "
            f"{s['payout_rates'][20000]*100:>7.2f}%    {s['current_win_rate']*100:>7.2f}%   "
            f"{s['current_trio_rate']*100:>7.2f}%       {s['current_win_on_in_loss_rate']*100:>7.2f}%"
        )

    base = aggregate(rows)
    print("-" * 122)
    print(
        f"ALL       {base['n']:>4d}  100.00%   {base['in_loss_rate']*100:>7.2f}%  "
        f"{base['payout_rates'][5000]*100:>7.2f}%  {base['payout_rates'][10000]*100:>7.2f}%  "
        f"{base['payout_rates'][20000]*100:>7.2f}%    {base['current_win_rate']*100:>7.2f}%   "
        f"{base['current_trio_rate']*100:>7.2f}%       {base['current_win_on_in_loss_rate']*100:>7.2f}%"
    )

    print("\n平均/中央払戻")
    for level in ("HIGH", "MEDIUM", "LOW"):
        s = out[level]
        if s["n"]:
            print(f"{level:<9}: 平均={s['avg_payout']:,.0f}円 / 中央={s['median_payout']:,.0f}円")

    print("\n実1着コース分布")
    for level in ("HIGH", "MEDIUM", "LOW"):
        s = out[level]
        n = s["n"]
        if not n:
            continue
        parts = []
        for c in range(1, 7):
            cnt = s["winner_courses"].get(c, 0)
            parts.append(f"{c}C={cnt/n*100:.1f}%({cnt})")
        print(f"{level:<9}: " + " / ".join(parts))

    return out, base


def disagree_in_rows(rows):
    return [
        r for r in rows
        if r["ai_head"] == r["in_lane"] and r["current_head"] != r["in_lane"]
    ]


def print_in_band_table(title, rows):
    target = disagree_in_rows(rows)
    print(f"\n【{title}: AI本命=1C × CURRENT本命!=1C のインAI1着率帯】")
    print(f"対象={len(target)}R / 全体={len(rows)}R")
    print(
        "IN勝率帯    R数  1C敗退率  >=5千円  >=1万円 >=2万円  CURRENT1着  1C敗退時CURRENT頭"
    )
    print("-" * 104)
    results = {}
    for _lo, _hi, label in IN_BANDS:
        part = [r for r in target if in_band(r["values"]["in_win_p"]) == label]
        s = aggregate(part)
        results[label] = s
        if not s["n"]:
            print(f"{label:<10}    0      -        -       -      -        -            -")
            continue
        print(
            f"{label:<10} {s['n']:>4d}   {s['in_loss_rate']*100:>7.2f}%  "
            f"{s['payout_rates'][5000]*100:>7.2f}% {s['payout_rates'][10000]*100:>7.2f}% "
            f"{s['payout_rates'][20000]*100:>7.2f}%   {s['current_win_rate']*100:>7.2f}%        "
            f"{s['current_win_on_in_loss_rate']*100:>7.2f}%"
        )
    return results


def print_current_course_table(title, rows):
    target = disagree_in_rows(rows)
    print(f"\n【{title}: CURRENT穴頭候補のコース別】")
    print("CURRENTコース  R数  CURRENT1着  CURRENT3連  >=5千円  >=1万円  1C敗退率")
    print("-" * 86)
    for c in range(2, 7):
        part = [
            r for r in target
            if int(r["course_by_lane"].get(r["current_head"], 0)) == c
        ]
        s = aggregate(part)
        if not s["n"]:
            continue
        print(
            f"{c}C             {s['n']:>4d}    {s['current_win_rate']*100:>7.2f}%    "
            f"{s['current_trio_rate']*100:>7.2f}%   {s['payout_rates'][5000]*100:>7.2f}%  "
            f"{s['payout_rates'][10000]*100:>7.2f}%   {s['in_loss_rate']*100:>7.2f}%"
        )


def print_lift(train_stats, p3_stats, train_base, p3_base):
    print("\n【初版ルールの再現チェック】")
    print("区分      指標            TRAIN lift      P3 lift")
    print("-" * 66)
    for level in ("HIGH", "MEDIUM"):
        tr = train_stats[level]
        te = p3_stats[level]
        for label, key, th in (
            (">=5千円", "payout_rates", 5000),
            (">=1万円", "payout_rates", 10000),
            ("1C敗退", "in_loss_rate", None),
        ):
            if th is None:
                tr_val = tr[key]
                te_val = te[key]
                tr_b = train_base[key]
                te_b = p3_base[key]
            else:
                tr_val = tr[key][th]
                te_val = te[key][th]
                tr_b = train_base[key][th]
                te_b = p3_base[key][th]
            tr_lift = tr_val / tr_b if tr_b > 0 else 0.0
            te_lift = te_val / te_b if te_b > 0 else 0.0
            print(f"{level:<9} {label:<10}      x{tr_lift:>5.2f}         x{te_lift:>5.2f}")


def build_all_rows(p1_csv, p2_csv, p3_csv):
    train_data = step3.build_common_records(p1_csv, p2_csv)
    future_data = step3.build_common_records(p2_csv, p3_csv)

    p1_records = train_data["records"]["P1"]
    p2_records = train_data["records"]["P2"]
    p3_records = future_data["records"]["P2"]

    boats_map = b2.load_boats(p1_csv, p2_csv, p3_csv)
    payouts = b4.load_payouts(train_data["p1_start"], future_data["p2_end"])

    p1_rows, _ = c1.build_rows(p1_records, boats_map, payouts, "P1")
    p2_rows, _ = c1.build_rows(p2_records, boats_map, payouts, "P2")
    p3_rows, _ = c1.build_rows(p3_records, boats_map, payouts, "P3")

    return {
        "train": p1_rows + p2_rows,
        "p3": p3_rows,
        "train_start": train_data["p1_start"],
        "train_end": train_data["p2_end"],
        "p3_start": future_data["p2_start"],
        "p3_end": future_data["p2_end"],
    }


def main():
    if len(sys.argv) != 4:
        print("Usage: python3 analysis/upset_alert_rule_validate.py P1_BOATS_CSV P2_BOATS_CSV P3_BOATS_CSV")
        sys.exit(1)

    p1_csv, p2_csv, p3_csv = sys.argv[1], sys.argv[2], sys.argv[3]

    print("C1特徴を再利用し、穴警戒ルール初版をTRAIN/P3で検証中...")
    data = build_all_rows(p1_csv, p2_csv, p3_csv)
    train_rows = data["train"]
    p3_rows = data["p3"]

    print("=" * 126)
    print("STEP C2：穴警戒ルール初版 + 穴頭候補検証")
    print("=" * 126)
    print(f"TRAIN : {data['train_start']} ～ {data['train_end']}")
    print(f"P3    : {data['p3_start']} ～ {data['p3_end']} 完全未来")
    print(f"母集団 : TRAIN={len(train_rows)}R / P3={len(p3_rows)}R")
    print("高警戒 : AI本命=1C & CURRENT本命!=1C & インAI1着率<50%")
    print("中警戒 : AI本命=1C & CURRENT本命!=1C & 50%<=インAI1着率<60%")
    print("低警戒 : それ以外")
    print("穴頭候補: CURRENT本命を初版候補として評価")
    print("本番Web変更: なし")

    train_stats, train_base = print_summary_table("TRAIN", train_rows)
    p3_stats, p3_base = print_summary_table("P3完全未来（最重要）", p3_rows)

    print_in_band_table("TRAIN", train_rows)
    print_in_band_table("P3完全未来", p3_rows)

    print_current_course_table("TRAIN", train_rows)
    print_current_course_table("P3完全未来", p3_rows)

    print_lift(train_stats, p3_stats, train_base, p3_base)

    print("\n【判断方針】")
    print("1. HIGHでP3の1C敗退率・5千円以上率・1万円以上率が全体より明確に高いか")
    print("2. TRAINとP3で同じ方向に再現するかを最優先する")
    print("3. CURRENT本命の1着率/3連対率が高ければ、そのまま穴頭候補初版に使える")
    print("4. 警戒は有効でもCURRENT頭精度が低ければ、次STEPで穴頭候補だけ別設計する")
    print("5. まずは確率値ではなく『穴警戒 高/中/低』の段階表示を目標にする")
    print("6. この検証では本番Web/PredictionLogicは変更しない")
    print("=" * 126)


if __name__ == "__main__":
    main()
