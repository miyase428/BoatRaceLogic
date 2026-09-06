#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
配当サイン固定後の穴目：9/7以降の未使用前方検証。

このスクリプトでは、2026-09-06までの既観察期間で絞った方式を固定し、
新しい未来期間では一切ルールを調整しない。

固定方式
--------
PRIMARY   FIX2_S3_T2
  - primary=イン崩壊
  - 非インAI3連対率 Top2を頭
  - 各頭で P(2着|頭) Top3
  - 各(頭,2着)で P(3着|頭,2着) Top2
  - 現行cut固定
  - 理論上最大12点

SECONDARY FIX2_S2_T3
  - 頭は同じ非インAI3 Top2
  - P(2着|頭) Top2
  - P(3着|頭,2着) Top3
  - 理論上最大12点

REFERENCE FIX2_S2_T2
  - 約8点の低点数比較用

重要
----
- PayoutSignalClassifierの条件は変更しない。
- GAP2(<2pt時Top3頭)は今回の前方本命方式には採らない。
- 10点への確率順圧縮もしない。12点上限を許容した固定形をそのまま検証する。
- 本命/対抗/PredictionLogic/本番買い目は変更しない。
- 未来結果を見て方式・閾値・候補数を変更しない。

Usage:
python3 analysis/validate_payout_signal_hole_bet_forward.py \
  analysis/output/final_prediction_boats_fast_cached_20260901_20260905.csv \
  analysis/output/final_prediction_boats_fast_cached_20260907_202609XX.csv \
  analysis/output/kimarite_analysis_dataset_20260901_20260905.csv \
  analysis/output/kimarite_analysis_dataset_20260907_202609XX.csv
"""

from __future__ import annotations

import sys
import time
from collections import Counter
from datetime import datetime
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import final_prediction_ai_opponent_compare as b2
import final_prediction_ai_bet_integration_compare as b4
import trifecta_probability_order_compare as step3
import payout_signal_relative_features as rel
import compare_payout_signal_third_count_bets as third

METHODS = (
    ("PRIMARY", "FIX2_S3_T2"),
    ("SECONDARY", "FIX2_S2_T3"),
    ("REFERENCE", "FIX2_S2_T2"),
)


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


def method_summary(rows: list[dict], method: str) -> dict:
    m = third.evaluate(rows, method)
    points = Counter()
    max_points = 0
    for row in rows:
        cnt = len(row["scenarios"][method]["bets"])
        points[cnt] += 1
        max_points = max(max_points, cnt)
    return {
        **m,
        "max_points": max_points,
        "point_counts": points,
    }


def print_result(rows: list[dict]) -> None:
    print("\n【未使用前方：固定3方式】")
    print(
        "位置       方式             R数 平均点 最大点 頭捕捉 1C敗戦時頭 頭+2着 3連単的中 "
        "3着|頭2着 100円ROI 1000円ROI"
    )
    print("-" * 132)
    for role, method in METHODS:
        m = method_summary(rows, method)
        print(
            f"{role:<10} {method:<16} {m['n']:>4d} {m['avg_points']:>6.2f} {m['max_points']:>6d} "
            f"{m['head_rate']:>6.2f}% {m['fail_head_rate']:>9.2f}% {m['pair_rate']:>7.2f}% "
            f"{m['exact_rate']:>8.2f}% {m['third_given_pair']:>8.2f}% "
            f"{m['roi100']:>8.2f}% {m['roi_fixed']:>9.2f}%"
        )

    print("\n【点数分布】")
    for role, method in METHODS:
        m = method_summary(rows, method)
        parts = ", ".join(f"{k}点:{v}R" for k, v in sorted(m["point_counts"].items()))
        print(f"{role:<10} {method:<16} {parts}")

    if rows:
        p = method_summary(rows, "FIX2_S3_T2")
        s = method_summary(rows, "FIX2_S2_T3")
        r = method_summary(rows, "FIX2_S2_T2")
        print("\n【固定判断用】")
        print(f"PRIMARY vs SECONDARY 的中差 : {p['exact_rate']-s['exact_rate']:+.2f}pt")
        print(f"PRIMARY vs REFERENCE 的中差 : {p['exact_rate']-r['exact_rate']:+.2f}pt")
        print(f"PRIMARY平均点数              : {p['avg_points']:.2f}点")
        print(f"PRIMARY最大点数              : {p['max_points']}点")


def main() -> None:
    if len(sys.argv) != 5:
        print(
            "Usage: python3 analysis/validate_payout_signal_hole_bet_forward.py "
            "BASE_BOATS FUTURE_BOATS BASE_KIMARITE FUTURE_KIMARITE"
        )
        sys.exit(1)

    base_boats, future_boats, base_kimarite, future_kimarite = sys.argv[1:]
    t0 = time.perf_counter()
    print(f"開始時刻 : {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}", flush=True)
    print("未使用前方レコード構築中...", flush=True)

    common = step3.build_common_records(base_boats, future_boats)
    future_records = list(common["records"]["P2"])

    print("CSV/決まり手/払戻読込中...", flush=True)
    boats_map = b2.load_boats(base_boats, future_boats)
    kimarite_map = rel.load_kimarite(base_kimarite, future_kimarite)
    payouts = b4.load_payouts(common["p2_start"], common["p2_end"])

    rows, skip = third.build_rows(future_records, boats_map, payouts, kimarite_map)

    print("=" * 132)
    print("穴目12点方式：未使用前方検証（固定）")
    print("=" * 132)
    print(f"BASE   : {common['p1_start']} ～ {common['p1_end']}（方式決定済み側）")
    print(f"FUTURE : {common['p2_start']} ～ {common['p2_end']}（未使用前方）")
    print("PRIMARY   : FIX2_S3_T2 = AI3非インTop2頭 × 2着Top3 × 条件付き3着Top2（最大12点）")
    print("SECONDARY : FIX2_S2_T3 = AI3非インTop2頭 × 2着Top2 × 条件付き3着Top3（最大12点）")
    print("REFERENCE : FIX2_S2_T2 = 約8点")
    print("荒れ判定/AI3順位/cut/候補数/閾値: 固定。未来データで変更しない")
    print("本番ロジック変更: なし")

    print_result(rows)

    print("\njoin/skip:", dict(skip))
    print("\n【前方検証ルール】")
    print("1. FUTUREを見て方式・閾値・候補数を調整しない")
    print("2. まずPRIMARYの的中率・点数・頭+2着・3着捕捉を追跡する")
    print("3. ROIは期間ブレが大きいため補助指標。方式変更理由には単独で使わない")
    print("4. 本命/対抗への展開特徴接続は、この穴目方式の前方評価後に別検証する")
    print("=" * 132)
    print(f"総所要時間 : {format_elapsed(time.perf_counter()-t0)}")


if __name__ == "__main__":
    main()
