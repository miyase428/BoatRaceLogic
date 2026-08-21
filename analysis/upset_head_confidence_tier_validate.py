#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
穴頭信頼度「強・中・弱」3段階の再確認。

C2/C3/C5/C6までの結果から、HIGH内の表示用信頼度を以下で固定する。

強:
    TRIO_TOP1 = CURRENT本命
    かつ TRIO_TOP1 - TRIO_TOP2 のAI3連対率差 >= 10pt

弱:
    TRIO_TOP1 - TRIO_TOP2 のAI3連対率差 < 2pt

中:
    上記以外のHIGH

このスクリプトはルール探索ではなく、Web表示へ反映した3段階が
TRAIN/P3でどの程度分離していたかを再現可能な形で残すためのもの。
C7以降は未使用未来データでの追跡検証として扱う。

Usage:
python3 analysis/upset_head_confidence_tier_validate.py \
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
import upset_head_confidence_validate as c5
import upset_head_confidence_interaction_validate as c6


TIERS = ("STRONG", "MIDDLE", "WEAK")


def tier(row):
    gap = c5.trio_gap_pt(row)
    if c5.same_current(row) and gap >= 10.0:
        return "STRONG"
    if gap < 2.0:
        return "WEAK"
    return "MIDDLE"


def metrics(rows):
    h = c5.head_metrics(rows)
    t1 = c4.evaluate_bets(rows, "TRIO1_OUTCOME")
    union = c4.evaluate_bets(rows, "TRIO1_CURRENT_OUTCOME")
    lo, hi = c6.wilson(h["top1_fail_win"], h["in_fail"])
    return {
        "head": h,
        "t1": t1,
        "union": union,
        "ci_lo": lo,
        "ci_hi": hi,
    }


def print_period(title, rows):
    print(f"\n【{title}】")
    print(
        "信頼度   R数  構成比  1C敗退R  T1頭率  1C敗退時T1頭  95%CI             "
        "Top2頭捕捉  T1_ROI  T1+CUR_ROI"
    )
    print("-" * 118)

    total = len(rows)
    out = {}
    for label in TIERS:
        part = [r for r in rows if tier(r) == label]
        m = metrics(part)
        out[label] = m
        h = m["head"]
        share = h["n"] / total if total else 0.0
        print(
            f"{label:<8} {h['n']:>5d}  {share*100:>6.2f}%  {h['in_fail']:>7d}  "
            f"{h['top1_win_rate']*100:>6.2f}%       {h['top1_fail_capture']*100:>7.2f}%  "
            f"[{m['ci_lo']*100:>5.1f},{m['ci_hi']*100:>5.1f}]%      "
            f"{h['top2_fail_capture']*100:>7.2f}%   "
            f"{m['t1']['roi_fixed']*100:>7.2f}%   {m['union']['roi_fixed']*100:>9.2f}%"
        )
    return out


def print_reproduction(train, p3):
    print("\n【TRAIN→P3 分離再現】")
    print("信頼度    TRAIN T1捕捉      P3 T1捕捉      差")
    print("-" * 58)
    for label in TIERS:
        tr = train[label]["head"]["top1_fail_capture"]
        te = p3[label]["head"]["top1_fail_capture"]
        print(f"{label:<8}   {tr*100:>7.2f}%        {te*100:>7.2f}%    {(te-tr)*100:+7.2f}pt")

    tr_order = [train[k]["head"]["top1_fail_capture"] for k in TIERS]
    te_order = [p3[k]["head"]["top1_fail_capture"] for k in TIERS]
    print()
    print("TRAIN 強>中>弱:", "YES" if tr_order[0] > tr_order[1] > tr_order[2] else "NO")
    print("P3    強>中>弱:", "YES" if te_order[0] > te_order[1] > te_order[2] else "NO")


def main():
    if len(sys.argv) != 4:
        print(
            "Usage: python3 analysis/upset_head_confidence_tier_validate.py "
            "P1_BOATS_CSV P2_BOATS_CSV P3_BOATS_CSV"
        )
        sys.exit(1)

    p1_csv, p2_csv, p3_csv = sys.argv[1], sys.argv[2], sys.argv[3]

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
    train_rows = p1_rows + p2_rows

    print("=" * 126)
    print("穴頭信頼度：強・中・弱 3段階再確認")
    print("=" * 126)
    print(f"TRAIN : {train_data['p1_start']} ～ {train_data['p2_end']}")
    print(f"P3    : {future_data['p2_start']} ～ {future_data['p2_end']} 完全未来")
    print(f"HIGH対象: TRAIN={len(train_rows)}R / P3={len(p3_rows)}R")
    print("強: SAME & gap>=10pt / 弱: gap<2pt / 中: その他")
    print("本番予想・買い目ロジック変更: なし")

    train = print_period("TRAIN", train_rows)
    p3 = print_period("P3完全未来", p3_rows)
    print_reproduction(train, p3)

    print("\n【位置づけ】")
    print("1. この3段階はC5/C6までの情報から作成した表示用分類")
    print("2. C7以降の未使用未来データは、分類を作るためではなく追跡検証に使う")
    print("3. ROIは参考であり、信頼度ラベル自体は1C敗退時T1頭捕捉で評価する")
    print("4. 最終予想・cut・買い目には接続しない")
    print("=" * 126)


if __name__ == "__main__":
    main()
