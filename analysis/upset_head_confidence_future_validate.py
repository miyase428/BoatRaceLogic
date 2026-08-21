#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
STEP C7：穴頭「強」条件の未使用未来検証

目的
----
C6までの結果を見た時点で、次の穴頭「強」候補を固定する。
P4の結果を見てから条件・閾値は変更しない。

対象母集団（C2固定）
--------------------
HIGH:
    AI本命 = 1C
    CURRENT本命 != 1C
    インAI1着率 < 50%

穴Top1（C3固定）
----------------
TRIO_TOP1:
    イン以外でAI3連対率1位

C7で固定する「強」条件
----------------------
STRONG_A:
    TRIO_TOP1 = CURRENT本命
    かつ
    TRIO_TOP1 - TRIO_TOP2 のAI3連対率差 >= 10pt

C6でこの条件は、1C敗退時TRIO_TOP1頭捕捉が
TRAIN 52.68% / P3 72.22% と再現したため、
次の完全未使用未来P4で独立確認する。

事前に固定する判断基準
----------------------
P4で以下をすべて満たせば「再現候補 PASS」とする。

1. STRONG_A の1C敗退Rが20R以上
2. STRONG_A の1C敗退時T1頭捕捉率 >= 45%
3. STRONG_A が OTHER_HIGH より頭捕捉率で +5pt以上

1C敗退Rが20R未満なら結論を出さず、期間を延長する。
ROIは少数の高配当で振れやすいため合否条件には使わず参考表示のみ。

重要
----
- P4を見て条件、10pt閾値、45%基準、+5pt基準を変更しない。
- P4で不合格でも別条件を同じP4から探索しない。
- 本番Web/PredictionLogicは変更しない。

Usage:
python3 analysis/upset_head_confidence_future_validate.py \
  analysis/output/final_prediction_boats_20260815_20260820.csv \
  analysis/output/final_prediction_boats_20260821_20260903.csv
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


MIN_IN_FAIL = 20
MIN_CAPTURE = 0.45
MIN_LIFT_PT = 5.0


def is_strong_a(row):
    return c5.same_current(row) and c5.trio_gap_pt(row) >= 10.0


def group_metrics(rows):
    return c6.metrics(rows)


def pct(value):
    return f"{value * 100:.2f}%"


def ci_text(m):
    return f"[{m['ci_lo'] * 100:.1f}, {m['ci_hi'] * 100:.1f}]%"


def print_group(label, rows, total):
    m = group_metrics(rows)
    h = m["head"]
    share = h["n"] / total if total else 0.0

    print(
        f"{label:<14} "
        f"{h['n']:>5d}  "
        f"{share * 100:>6.2f}%  "
        f"{h['in_fail']:>7d}  "
        f"{h['top1_win_rate'] * 100:>7.2f}%  "
        f"{h['top1_fail_capture'] * 100:>9.2f}%  "
        f"{ci_text(m):>15}  "
        f"{h['top2_fail_capture'] * 100:>10.2f}%  "
        f"{m['t1']['roi_fixed'] * 100:>7.2f}%  "
        f"{m['union']['roi_fixed'] * 100:>9.2f}%"
    )
    return m


def decision(strong, other):
    sh = strong["head"]
    oh = other["head"]

    if sh["in_fail"] < MIN_IN_FAIL:
        return (
            "INSUFFICIENT",
            f"STRONG_Aの1C敗退R={sh['in_fail']}R < {MIN_IN_FAIL}R。期間延長。",
        )

    capture = sh["top1_fail_capture"]
    other_capture = oh["top1_fail_capture"]
    lift_pt = (capture - other_capture) * 100.0

    if capture >= MIN_CAPTURE and lift_pt >= MIN_LIFT_PT:
        return (
            "PASS",
            f"T1頭捕捉={capture * 100:.2f}%、OTHER_HIGH比={lift_pt:+.2f}pt。",
        )

    if capture <= other_capture:
        return (
            "FAIL",
            f"T1頭捕捉={capture * 100:.2f}%でOTHER_HIGH={other_capture * 100:.2f}%を上回らず。",
        )

    return (
        "BORDERLINE",
        f"T1頭捕捉={capture * 100:.2f}%、OTHER_HIGH比={lift_pt:+.2f}pt。固定基準未達。",
    )


def main():
    if len(sys.argv) != 3:
        print(
            "Usage: python3 analysis/upset_head_confidence_future_validate.py "
            "P3_BOATS_CSV P4_BOATS_CSV"
        )
        sys.exit(1)

    p3_csv, p4_csv = sys.argv[1], sys.argv[2]

    # P3はC6までで使用済み。ここではP4を構築するための直前期間としてのみ渡す。
    future_data = step3.build_common_records(p3_csv, p4_csv)
    p4_records = future_data["records"]["P2"]

    boats_map = b2.load_boats(p3_csv, p4_csv)
    payouts = b4.load_payouts(future_data["p1_start"], future_data["p2_end"])

    p4_rows, skip = c4.build_rows(p4_records, boats_map, payouts)
    strong_rows = [r for r in p4_rows if is_strong_a(r)]
    other_rows = [r for r in p4_rows if not is_strong_a(r)]

    print("=" * 132)
    print("STEP C7：穴頭『強』条件の未使用未来検証")
    print("=" * 132)
    print(f"P4      : {future_data['p2_start']} ～ {future_data['p2_end']} 完全未使用未来")
    print(f"HIGH対象: {len(p4_rows)}R")
    print("STRONG_A: TRIO1=CURRENT & TRIO1-TRIO2 AI3連対率差>=10pt（C6後に固定）")
    print("合否基準: 1C敗退R>=20 / T1頭捕捉>=45% / OTHER_HIGH比+5pt以上")
    print("ROI     : 参考のみ。合否には使用しない")
    print("本番Web変更: なし")

    if skip:
        print("\n【構築参考】")
        for key in sorted(skip):
            print(f"{key:<28}: {skip[key]}")

    print("\n【P4固定ルール検証】")
    print(
        "区分             R数   構成比  1C敗退R  T1頭率  1C敗退時T1頭  "
        "95%CI            Top2頭捕捉  T1_ROI  T1+CUR_ROI"
    )
    print("-" * 132)

    all_m = print_group("ALL_HIGH", p4_rows, len(p4_rows))
    strong_m = print_group("STRONG_A", strong_rows, len(p4_rows))
    other_m = print_group("OTHER_HIGH", other_rows, len(p4_rows))

    strong_capture = strong_m["head"]["top1_fail_capture"]
    all_capture = all_m["head"]["top1_fail_capture"]
    other_capture = other_m["head"]["top1_fail_capture"]

    print("\n【分離差】")
    print(f"STRONG_A - ALL_HIGH   : {(strong_capture - all_capture) * 100:+.2f}pt")
    print(f"STRONG_A - OTHER_HIGH : {(strong_capture - other_capture) * 100:+.2f}pt")

    result, detail = decision(strong_m, other_m)

    print("\n【事前固定基準による判定】")
    print(f"判定 : {result}")
    print(f"理由 : {detail}")

    if result == "PASS":
        print("次段階: Web表示専用の『穴頭信頼度：強』候補として実装検討へ進める。")
    elif result == "INSUFFICIENT":
        print("次段階: 条件を変えずにP4期間だけ延長して再実行する。")
    else:
        print("次段階: 同じP4で別条件を探索せず、STRONG_A採用を保留する。")

    print("=" * 132)


if __name__ == "__main__":
    main()
