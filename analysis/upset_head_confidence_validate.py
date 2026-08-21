#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
STEP C5：穴警戒HIGH内の穴頭信頼度分解

目的
----
C2/C3/C4で固定した以下の条件を一切変えず、HIGHの中をさらに分解する。

HIGH:
    AI本命 = 1C
    CURRENT本命 != 1C
    インAI1着率 < 50%

穴Top1:
    TRIO_OUTER（イン以外でAI3連対率1位）

C4ではTRIO_TOP2に広げると頭捕捉は大きく上がった一方、
買い目点数増加でROIが低下した。そこでC5では、
「TRIO_TOP1を1艇固定しやすい条件」と
「CURRENT併用やTop2を残したい条件」を探す。

分解軸
------
1. TRIO_TOP1 と CURRENT本命が同じ / 異なる
2. TRIO_TOP1の進入コース（2C～6C）
3. TRIO_TOP1 - TRIO_TOP2 のAI3連対率差
   <2pt / 2-5pt / 5-10pt / >=10pt
4. 説明しやすい粗い信頼度セル
   SAME
   DIFF_2-4C_GAP>=5
   DIFF_2-4C_GAP<5
   DIFF_5-6C_GAP>=5
   DIFF_5-6C_GAP<5

評価
----
- R数 / 1C敗退率
- TRIO_TOP1の実頭率
- 1C敗退時のTRIO_TOP1頭捕捉率
- 1C敗退時のTRIO_TOP2頭捕捉率
- TRIO_TOP1-2差
- TRIO1_OUTCOME 1000円均等ROI
- TRIO1_CURRENT_OUTCOME 1000円均等ROI

重要
----
- C2/C3/C4の条件・穴候補定義は変更しない。
- P3側で閾値を再調整しない。
- 5pt境界は最終ルールではなく、説明可能な粗い診断セル。
- 本番Web/PredictionLogicは変更しない。

Usage:
python3 analysis/upset_head_confidence_validate.py \
  analysis/output/final_prediction_boats_20260615_20260714_OLD.csv \
  analysis/output/final_prediction_boats_20260715_20260814_OLD.csv \
  analysis/output/final_prediction_boats_20260815_20260820.csv
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import final_prediction_ai_opponent_compare as b2
import final_prediction_ai_bet_integration_compare as b4
import trifecta_probability_order_compare as step3
import upset_top2_bet_validate as c4


GAP_BANDS = (
    (None, 2.0, "<2pt"),
    (2.0, 5.0, "2-5pt"),
    (5.0, 10.0, "5-10pt"),
    (10.0, None, ">=10pt"),
)


BET_METHODS = (
    "CURRENT",
    "TRIO1_OUTCOME",
    "TRIO1_CURRENT_OUTCOME",
    "TRIO_TOP2_OUTCOME",
)


def trio_gap_pt(row):
    trio = row["trio"]
    top1 = int(row["trio1"])
    top2 = int(row["trio2"])
    return (float(trio.get(top1, 0.0)) - float(trio.get(top2, 0.0))) * 100.0


def trio1_course(row):
    return int(row["course_by_lane"].get(int(row["trio1"]), 0))


def same_current(row):
    return int(row["trio1"]) == int(row["current"])


def gap_band(row):
    gap = trio_gap_pt(row)
    for lo, hi, label in GAP_BANDS:
        if lo is not None and gap < lo:
            continue
        if hi is not None and gap >= hi:
            continue
        return label
    return "?"


def confidence_cell(row):
    if same_current(row):
        return "SAME"

    course = trio1_course(row)
    gap = trio_gap_pt(row)
    inner = course in (2, 3, 4)
    strong = gap >= 5.0

    if inner and strong:
        return "DIFF_2-4C_GAP>=5"
    if inner and not strong:
        return "DIFF_2-4C_GAP<5"
    if not inner and strong:
        return "DIFF_5-6C_GAP>=5"
    return "DIFF_5-6C_GAP<5"


def head_metrics(rows):
    n = len(rows)
    if n == 0:
        return {
            "n": 0,
            "in_fail": 0,
            "in_fail_rate": 0.0,
            "top1_win": 0,
            "top1_win_rate": 0.0,
            "top1_fail_win": 0,
            "top1_fail_capture": 0.0,
            "top2_fail_win": 0,
            "top2_fail_capture": 0.0,
            "avg_gap": 0.0,
        }

    in_fail = 0
    top1_win = 0
    top1_fail_win = 0
    top2_fail_win = 0
    gap_sum = 0.0

    for row in rows:
        actual_first = int(row["actual_first"])
        top1 = int(row["trio1"])
        top2 = int(row["trio2"])
        failed = bool(row["in_failed"])

        if actual_first == top1:
            top1_win += 1

        if failed:
            in_fail += 1
            if actual_first == top1:
                top1_fail_win += 1
            if actual_first in (top1, top2):
                top2_fail_win += 1

        gap_sum += trio_gap_pt(row)

    return {
        "n": n,
        "in_fail": in_fail,
        "in_fail_rate": in_fail / n,
        "top1_win": top1_win,
        "top1_win_rate": top1_win / n,
        "top1_fail_win": top1_fail_win,
        "top1_fail_capture": top1_fail_win / in_fail if in_fail else 0.0,
        "top2_fail_win": top2_fail_win,
        "top2_fail_capture": top2_fail_win / in_fail if in_fail else 0.0,
        "avg_gap": gap_sum / n,
    }


def bet_metrics(rows, method):
    # C4と同じ1000円均等評価をそのまま再利用する。
    return c4.evaluate_bets(rows, method)


def print_group_table(title, rows, groups):
    print(f"\n【{title}】")
    print(
        "区分                     R数   構成比  1C敗退  T1頭率  1C敗退時T1頭  "
        "1C敗退時Top2頭  平均gap  T1_ROI  T1+CUR_ROI"
    )
    print("-" * 126)

    total = len(rows)
    results = {}

    for label, pred in groups:
        part = [row for row in rows if pred(row)]
        h = head_metrics(part)
        t1 = bet_metrics(part, "TRIO1_OUTCOME")
        union = bet_metrics(part, "TRIO1_CURRENT_OUTCOME")
        results[label] = {"rows": part, "head": h, "t1": t1, "union": union}

        share = h["n"] / total if total else 0.0
        print(
            f"{label:<24} {h['n']:>5d}  {share*100:>6.2f}%  "
            f"{h['in_fail_rate']*100:>6.2f}%  {h['top1_win_rate']*100:>6.2f}%     "
            f"{h['top1_fail_capture']*100:>7.2f}%         "
            f"{h['top2_fail_capture']*100:>7.2f}%     "
            f"{h['avg_gap']:>7.2f}pt  {t1['roi_fixed']*100:>7.2f}%    "
            f"{union['roi_fixed']*100:>7.2f}%"
        )

    return results


def print_bet_detail(title, rows):
    print(f"\n【{title}：買い方比較】")
    print("方式                         R数  平均点数   的中率  1000円均等ROI  的中平均払戻")
    print("-" * 94)
    for method in BET_METHODS:
        m = bet_metrics(rows, method)
        print(
            f"{method:<28} {m['n']:>5d}   {m['avg_points']:>7.2f}   "
            f"{m['hit_rate']*100:>6.2f}%      {m['roi_fixed']*100:>8.2f}%      "
            f"{m['avg_hit_payout']:>8.0f}円"
        )


def print_train_p3_cells(train_rows, p3_rows):
    labels = (
        "SAME",
        "DIFF_2-4C_GAP>=5",
        "DIFF_2-4C_GAP<5",
        "DIFF_5-6C_GAP>=5",
        "DIFF_5-6C_GAP<5",
    )

    print("\n【粗い信頼度セル TRAIN→P3再現】")
    print(
        "区分                     TRAIN_R  TRAIN_T1捕捉 TRAIN_T1_ROI TRAIN_併用ROI  "
        "P3_R  P3_T1捕捉 P3_T1_ROI P3_併用ROI"
    )
    print("-" * 126)

    for label in labels:
        tr = [r for r in train_rows if confidence_cell(r) == label]
        te = [r for r in p3_rows if confidence_cell(r) == label]

        tr_h = head_metrics(tr)
        te_h = head_metrics(te)
        tr_t1 = bet_metrics(tr, "TRIO1_OUTCOME")
        te_t1 = bet_metrics(te, "TRIO1_OUTCOME")
        tr_u = bet_metrics(tr, "TRIO1_CURRENT_OUTCOME")
        te_u = bet_metrics(te, "TRIO1_CURRENT_OUTCOME")

        print(
            f"{label:<24} {tr_h['n']:>7d}      {tr_h['top1_fail_capture']*100:>7.2f}%   "
            f"{tr_t1['roi_fixed']*100:>8.2f}%   {tr_u['roi_fixed']*100:>8.2f}%   "
            f"{te_h['n']:>5d}     {te_h['top1_fail_capture']*100:>7.2f}%   "
            f"{te_t1['roi_fixed']*100:>8.2f}%   {te_u['roi_fixed']*100:>8.2f}%"
        )


def build_all_rows(p1_csv, p2_csv, p3_csv):
    train_data = step3.build_common_records(p1_csv, p2_csv)
    future_data = step3.build_common_records(p2_csv, p3_csv)

    p1_records = train_data["records"]["P1"]
    p2_records = train_data["records"]["P2"]
    p3_records = future_data["records"]["P2"]

    boats_map = b2.load_boats(p1_csv, p2_csv, p3_csv)
    payouts = b4.load_payouts(train_data["p1_start"], future_data["p2_end"])

    p1_rows, _ = c4.build_rows(p1_records, boats_map, payouts)
    p2_rows, _ = c4.build_rows(p2_records, boats_map, payouts)
    p3_rows, _ = c4.build_rows(p3_records, boats_map, payouts)

    return {
        "train_data": train_data,
        "future_data": future_data,
        "train_rows": p1_rows + p2_rows,
        "p3_rows": p3_rows,
    }


def main():
    if len(sys.argv) != 4:
        print("Usage: python3 analysis/upset_head_confidence_validate.py P1_BOATS_CSV P2_BOATS_CSV P3_BOATS_CSV")
        sys.exit(1)

    p1_csv, p2_csv, p3_csv = sys.argv[1], sys.argv[2], sys.argv[3]

    print("C2/C3/C4を固定し、HIGH内の穴頭信頼度を分解中...")
    data = build_all_rows(p1_csv, p2_csv, p3_csv)
    train_rows = data["train_rows"]
    p3_rows = data["p3_rows"]
    train_data = data["train_data"]
    future_data = data["future_data"]

    print("=" * 126)
    print("STEP C5：穴警戒HIGH内の穴頭信頼度分解")
    print("=" * 126)
    print(f"TRAIN : {train_data['p1_start']} ～ {train_data['p2_end']}")
    print(f"P3    : {future_data['p2_start']} ～ {future_data['p2_end']} 完全未来")
    print(f"HIGH対象: TRAIN={len(train_rows)}R / P3={len(p3_rows)}R")
    print("HIGH条件: AI本命=1C & CURRENT本命!=1C & インAI1着率<50%（C2固定）")
    print("穴Top1 : TRIO_OUTER（C3固定）")
    print("買い目 : C4のOUTCOME方式をそのまま使用")
    print("本番Web変更: なし")

    agreement_groups = (
        ("TRIO1=CURRENT", same_current),
        ("TRIO1!=CURRENT", lambda r: not same_current(r)),
    )

    course_groups = tuple(
        (f"TRIO1_{course}C", lambda r, c=course: trio1_course(r) == c)
        for course in range(2, 7)
    )

    gap_groups = tuple(
        (label, lambda r, target=label: gap_band(r) == target)
        for _lo, _hi, label in GAP_BANDS
    )

    cell_labels = (
        "SAME",
        "DIFF_2-4C_GAP>=5",
        "DIFF_2-4C_GAP<5",
        "DIFF_5-6C_GAP>=5",
        "DIFF_5-6C_GAP<5",
    )
    cell_groups = tuple(
        (label, lambda r, target=label: confidence_cell(r) == target)
        for label in cell_labels
    )

    print_group_table("TRAIN：TRIO1とCURRENTの一致/不一致", train_rows, agreement_groups)
    print_group_table("P3：TRIO1とCURRENTの一致/不一致", p3_rows, agreement_groups)

    print_group_table("TRAIN：TRIO1進入コース別", train_rows, course_groups)
    print_group_table("P3：TRIO1進入コース別", p3_rows, course_groups)

    print_group_table("TRAIN：TRIO1-TRIO2 AI3連対率差", train_rows, gap_groups)
    print_group_table("P3：TRIO1-TRIO2 AI3連対率差", p3_rows, gap_groups)

    print_group_table("TRAIN：粗い信頼度セル", train_rows, cell_groups)
    print_group_table("P3：粗い信頼度セル", p3_rows, cell_groups)

    print_train_p3_cells(train_rows, p3_rows)

    print_bet_detail("TRAIN全HIGH", train_rows)
    print_bet_detail("P3全HIGH", p3_rows)

    print("\n【判断方針】")
    print("1. 最重要はTRAINとP3で『1C敗退時T1頭捕捉』が同方向に高いか")
    print("2. TRIO1=CURRENTならCURRENT保険追加の意味は薄いので、1艇固定候補として見る")
    print("3. TRIO1!=CURRENTではT1単独ROIとT1+CURRENT ROIの差を見て、保険の価値を判断する")
    print("4. gapが大きいほどT1頭捕捉が上がるなら、1艇固定の信頼度表示に使える")
    print("5. 2～4Cと5～6Cで再現差があるなら、コース帯を信頼度条件に使う")
    print("6. Top2捕捉だけ高くT1 ROIが伸びない層は『候補表示はTop2、購入は要選別』と分ける")
    print("7. P3サンプルが少ないセルのROIは高低だけで採用しない")
    print("8. この段階では本番Web/PredictionLogicを変更しない")
    print("=" * 126)


if __name__ == "__main__":
    main()
