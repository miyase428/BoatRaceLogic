#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
STEP C6：穴頭一致 × AI3連対率差の交互作用検証

目的
----
C5でTRAIN/P3の両方に再現した2つの信号だけをさらに確認する。

1. TRIO_TOP1 と CURRENT本命の一致
2. TRIO_TOP1 - TRIO_TOP2 のAI3連対率差

コース別はP3サンプルが小さく、再現性も弱かったためC6の主条件には使わない。
HIGH条件とTRIO_TOP1の定義はC2/C3から固定したまま変更しない。

見るもの
--------
- SAME / DIFF × gap帯の完全マトリクス
- 1C敗退時TRIO_TOP1頭捕捉率
- Wilson 95%区間（小標本の見かけの差を過信しないため）
- TRIO_TOP2頭捕捉率
- TRIO1_OUTCOME / TRIO1+CURRENT の1000円均等ROI

候補ルール（P3で再調整しない）
--------------------------------
C5のTRAINで単独に良かった信号を組み合わせた説明用候補だけ比較する。

A_STRONG_BOTH
    SAME かつ gap>=10pt

B_AGREE_OR_GAP10
    SAME または gap>=10pt

C_DIFF_GAP10
    DIFF かつ gap>=10pt

D_DIFF_GAP5_10
    DIFF かつ 5<=gap<10pt

E_DIFF_GAP_LT5
    DIFF かつ gap<5pt

重要
----
- 本番Web/PredictionLogicは変更しない。
- ROIは払戻の少数大当たりで振れやすいので、頭捕捉再現を主評価とする。
- C6で新しく得た最終ルールは、次の未使用未来期間で再検証してから採用する。

Usage:
python3 analysis/upset_head_confidence_interaction_validate.py \
  analysis/output/final_prediction_boats_20260615_20260714_OLD.csv \
  analysis/output/final_prediction_boats_20260715_20260814_OLD.csv \
  analysis/output/final_prediction_boats_20260815_20260820.csv
"""

from __future__ import annotations

import math
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import final_prediction_ai_opponent_compare as b2
import final_prediction_ai_bet_integration_compare as b4
import trifecta_probability_order_compare as step3
import upset_top2_bet_validate as c4
import upset_head_confidence_validate as c5


GAP_BANDS = (
    (None, 2.0, "<2pt"),
    (2.0, 5.0, "2-5pt"),
    (5.0, 10.0, "5-10pt"),
    (10.0, None, ">=10pt"),
)


def wilson(successes: int, total: int, z: float = 1.959963984540054):
    if total <= 0:
        return 0.0, 0.0
    p = successes / total
    z2 = z * z
    den = 1.0 + z2 / total
    center = (p + z2 / (2.0 * total)) / den
    half = (
        z
        * math.sqrt((p * (1.0 - p) / total) + z2 / (4.0 * total * total))
        / den
    )
    return max(0.0, center - half), min(1.0, center + half)


def in_gap(row, lo, hi):
    gap = c5.trio_gap_pt(row)
    if lo is not None and gap < lo:
        return False
    if hi is not None and gap >= hi:
        return False
    return True


def metrics(rows):
    h = c5.head_metrics(rows)
    t1 = c4.evaluate_bets(rows, "TRIO1_OUTCOME")
    union = c4.evaluate_bets(rows, "TRIO1_CURRENT_OUTCOME")
    top2 = c4.evaluate_bets(rows, "TRIO_TOP2_OUTCOME")
    lo, hi = wilson(h["top1_fail_win"], h["in_fail"])
    return {
        "head": h,
        "t1": t1,
        "union": union,
        "top2": top2,
        "ci_lo": lo,
        "ci_hi": hi,
    }


def print_matrix(title, rows):
    print(f"\n【{title}：SAME/DIFF × gap帯】")
    print(
        "区分        gap帯      R数  1C敗退R  T1頭捕捉  95%CI             Top2頭捕捉  "
        "T1_ROI  T1+CUR_ROI"
    )
    print("-" * 112)

    out = {}
    for same_label, same_value in (("SAME", True), ("DIFF", False)):
        for lo, hi, gap_label in GAP_BANDS:
            part = [
                r for r in rows
                if c5.same_current(r) == same_value and in_gap(r, lo, hi)
            ]
            m = metrics(part)
            out[(same_label, gap_label)] = m
            h = m["head"]
            print(
                f"{same_label:<10} {gap_label:<8} {h['n']:>5d}  {h['in_fail']:>7d}  "
                f"{h['top1_fail_capture']*100:>7.2f}%  "
                f"[{m['ci_lo']*100:>5.1f},{m['ci_hi']*100:>5.1f}]%      "
                f"{h['top2_fail_capture']*100:>7.2f}%    "
                f"{m['t1']['roi_fixed']*100:>7.2f}%   {m['union']['roi_fixed']*100:>9.2f}%"
            )
    return out


def candidate_groups():
    return (
        (
            "A_STRONG_BOTH",
            lambda r: c5.same_current(r) and c5.trio_gap_pt(r) >= 10.0,
        ),
        (
            "B_AGREE_OR_GAP10",
            lambda r: c5.same_current(r) or c5.trio_gap_pt(r) >= 10.0,
        ),
        (
            "C_DIFF_GAP10",
            lambda r: (not c5.same_current(r)) and c5.trio_gap_pt(r) >= 10.0,
        ),
        (
            "D_DIFF_GAP5_10",
            lambda r: (
                (not c5.same_current(r))
                and 5.0 <= c5.trio_gap_pt(r) < 10.0
            ),
        ),
        (
            "E_DIFF_GAP_LT5",
            lambda r: (not c5.same_current(r)) and c5.trio_gap_pt(r) < 5.0,
        ),
    )


def print_candidates(title, rows):
    print(f"\n【{title}：説明用候補ルール】")
    print(
        "候補                     R数  構成比  1C敗退R  T1頭捕捉  95%CI             "
        "Top2頭捕捉  T1_ROI  T1+CUR_ROI"
    )
    print("-" * 124)

    out = {}
    total = len(rows)
    for label, pred in candidate_groups():
        part = [r for r in rows if pred(r)]
        m = metrics(part)
        out[label] = m
        h = m["head"]
        share = h["n"] / total if total else 0.0
        print(
            f"{label:<24} {h['n']:>5d}  {share*100:>6.2f}%  {h['in_fail']:>7d}  "
            f"{h['top1_fail_capture']*100:>7.2f}%  "
            f"[{m['ci_lo']*100:>5.1f},{m['ci_hi']*100:>5.1f}]%      "
            f"{h['top2_fail_capture']*100:>7.2f}%    "
            f"{m['t1']['roi_fixed']*100:>7.2f}%   {m['union']['roi_fixed']*100:>9.2f}%"
        )
    return out


def print_reproduction(train, p3):
    print("\n【候補ルール TRAIN→P3再現】")
    print(
        "候補                     TRAIN_R TRAIN_T1捕捉 TRAIN_CI        "
        "P3_R P3_T1捕捉 P3_CI"
    )
    print("-" * 96)
    for label, _pred in candidate_groups():
        tr = train[label]
        te = p3[label]
        tr_h = tr["head"]
        te_h = te["head"]
        print(
            f"{label:<24} {tr_h['n']:>7d}    {tr_h['top1_fail_capture']*100:>7.2f}%  "
            f"[{tr['ci_lo']*100:>4.1f},{tr['ci_hi']*100:>4.1f}]   "
            f"{te_h['n']:>4d}    {te_h['top1_fail_capture']*100:>7.2f}%  "
            f"[{te['ci_lo']*100:>4.1f},{te['ci_hi']*100:>4.1f}]"
        )


def main():
    if len(sys.argv) != 4:
        print(
            "Usage: python3 analysis/upset_head_confidence_interaction_validate.py "
            "P1_BOATS_CSV P2_BOATS_CSV P3_BOATS_CSV"
        )
        sys.exit(1)

    p1_csv, p2_csv, p3_csv = sys.argv[1], sys.argv[2], sys.argv[3]

    print("C5で再現したSAME/DIFFとTRIO gapだけを交互作用検証中...")

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
    print("STEP C6：穴頭一致 × AI3連対率差の交互作用検証")
    print("=" * 126)
    print(f"TRAIN : {train_data['p1_start']} ～ {train_data['p2_end']}")
    print(f"P3    : {future_data['p2_start']} ～ {future_data['p2_end']} 完全未来")
    print(f"HIGH対象: TRAIN={len(train_rows)}R / P3={len(p3_rows)}R")
    print("主軸   : TRIO1=CURRENT一致/不一致 × TRIO1-2 AI3連対率差")
    print("コース : C6の主ルールには使わない")
    print("本番Web変更: なし")

    print_matrix("TRAIN", train_rows)
    print_matrix("P3完全未来（最重要）", p3_rows)

    train_candidates = print_candidates("TRAIN", train_rows)
    p3_candidates = print_candidates("P3完全未来（最重要）", p3_rows)
    print_reproduction(train_candidates, p3_candidates)

    print("\n【判断方針】")
    print("1. ROIより先に1C敗退時T1頭捕捉のTRAIN→P3再現を見る")
    print("2. SAMEとgap>=10の両方が揃うAが明確なら、穴頭『強』候補")
    print("3. SAMEまたはgap>=10のBが広く高水準なら、実用上の主候補")
    print("4. DIFFかつgap<5のEが弱ければ、T1一艇固定を避ける警戒層候補")
    print("5. Wilson区間が広い小標本は結論に使わない")
    print("6. C6で選んだ最終ルールは次の未使用未来期間で再検証してからWebへ反映する")
    print("7. この段階では本番Web/PredictionLogicを変更しない")
    print("=" * 126)


if __name__ == "__main__":
    main()
