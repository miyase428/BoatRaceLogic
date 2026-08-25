#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
穴目予想レイヤー：固定済み穴頭 + 120通り穴ヒモを、実買い目へ接続する価値をP1/P2/P3で最終確認する。

固定条件
--------
- HIGH: AI本命=1C / CURRENT本命!=1C / イン補正後1着率<50%
- 穴頭: TRIO_TOP1（イン以外AI3連対率1位）
- 2着側: STEP3 120通り P(2着|頭) 上位最大3艇
- 3着側: 現行cutを除く対象艇
- 現行cut維持
- 穴頭信頼度・①残り・展開候補・すじ・壁なしによる追加補正なし

比較
----
CURRENT
    現行本命 + 現行相手 + 現行cut。

CURRENT_OUTCOME
    頭はCURRENTのまま、2着候補だけ120通り P(2着|頭) 上位最大3艇へ変更。

TRIO1_OUTCOME
    頭を穴頭TRIO_TOP1へ変更し、2着候補を120通り P(2着|頭) 上位最大3艇へ変更。

目的
----
- 120通り相手化そのものの効果をCURRENT_OUTCOMEで確認する
- 穴頭TRIO_TOP1まで接続した追加効果をTRIO1_OUTCOMEで確認する
- P1/P2/P3で平均点数・的中率・ROIが再現するかを見る

重要
----
- 既存upset_top2_bet_validate.pyの固定ロジックを再利用する。
- P3を見て条件・閾値を変更しない。
- 本番Web/PredictionLogicは変更しない。
- ROIが複数期間で安定しなければ、穴目パネルは表示専用として完成扱いにする。

Usage:
python3 analysis/upset_final_bet_connection_validate.py \
  analysis/output/final_prediction_boats_20260615_20260714_OLD.csv \
  analysis/output/final_prediction_boats_20260715_20260814_OLD.csv \
  analysis/output/final_prediction_boats_fast_cached_20260815_20260822.csv
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import final_prediction_ai_opponent_compare as b2
import final_prediction_ai_bet_integration_compare as b4
import trifecta_probability_order_compare as step3
import upset_top2_bet_validate as c4

METHODS = ("CURRENT", "CURRENT_OUTCOME", "TRIO1_OUTCOME")


def delta(base, new):
    return {
        "points": new["avg_points"] - base["avg_points"],
        "hit": (new["hit_rate"] - base["hit_rate"]) * 100.0,
        "roi100": (new["roi100"] - base["roi100"]) * 100.0,
        "roi_fixed": (new["roi_fixed"] - base["roi_fixed"]) * 100.0,
    }


def print_period(title, rows, skip):
    print("\n" + "=" * 118)
    print(f"【{title}】 HIGH固定={len(rows)}R")
    print("=" * 118)
    print("方式                         R数   平均点数   的中率   100円/点ROI   1000円均等ROI   的中平均払戻")
    print("-" * 108)

    results = {}
    for method in METHODS:
        m = c4.evaluate_bets(rows, method)
        results[method] = m
        print(
            f"{method:<28} {m['n']:>5d}   {m['avg_points']:>7.2f}   "
            f"{m['hit_rate']*100:>6.2f}%     {m['roi100']*100:>8.2f}%       "
            f"{m['roi_fixed']*100:>8.2f}%       {m['avg_hit_payout']:>8.0f}円"
        )

    print("\nCURRENTとの差")
    for method in ("CURRENT_OUTCOME", "TRIO1_OUTCOME"):
        d = delta(results["CURRENT"], results[method])
        cmp = c4.compare_bets(rows, "CURRENT", method)
        print(
            f"{method:<28} 点数 {d['points']:+.2f} / 的中 {d['hit']:+.2f}pt / "
            f"100円ROI {d['roi100']:+.2f}pt / 1000円ROI {d['roi_fixed']:+.2f}pt / "
            f"拾い {cmp['gained']} / 失い {cmp['lost']}"
        )

    print("\nCURRENT_OUTCOME → TRIO1_OUTCOME（穴頭差し替え分）")
    d = delta(results["CURRENT_OUTCOME"], results["TRIO1_OUTCOME"])
    cmp = c4.compare_bets(rows, "CURRENT_OUTCOME", "TRIO1_OUTCOME")
    print(
        f"点数 {d['points']:+.2f} / 的中 {d['hit']:+.2f}pt / "
        f"100円ROI {d['roi100']:+.2f}pt / 1000円ROI {d['roi_fixed']:+.2f}pt / "
        f"拾い {cmp['gained']} / 失い {cmp['lost']}"
    )
    print("skip参考:", dict(skip))
    return results


def main():
    if len(sys.argv) != 4:
        print(
            "Usage: python3 analysis/upset_final_bet_connection_validate.py P1_BOATS P2_BOATS P3_BOATS",
            file=sys.stderr,
        )
        sys.exit(1)

    p1, p2, p3 = sys.argv[1:]

    print("固定済み穴目レイヤーの買い目接続価値をP1/P2/P3で最終確認中...", flush=True)
    train = step3.build_common_records(p1, p2)
    future = step3.build_common_records(p2, p3)
    boats_map = b2.load_boats(p1, p2, p3)
    payouts = b4.load_payouts(train["p1_start"], future["p2_end"])

    p1_rows, p1_skip = c4.build_rows(train["records"]["P1"], boats_map, payouts)
    p2_rows, p2_skip = c4.build_rows(train["records"]["P2"], boats_map, payouts)
    p3_rows, p3_skip = c4.build_rows(future["records"]["P2"], boats_map, payouts)

    print("=" * 118)
    print("穴目予想レイヤー → 買い目接続 最終前方検証 P1/P2/P3")
    print("=" * 118)
    print("固定: HIGH / TRIO_TOP1 / P(2着|頭)Top3 / 現行cut維持")
    print("追加補正: 穴頭信頼度・①残り・展開候補・すじ・壁なしは使わない")
    print("本番変更: なし")

    p1_res = print_period("P1", p1_rows, p1_skip)
    p2_res = print_period("P2", p2_rows, p2_skip)
    p3_res = print_period("P3完全未来", p3_rows, p3_skip)

    print("\n【最終判断ポイント】")
    print("1. TRIO1_OUTCOMEの的中率改善がP1/P2/P3で再現するか")
    print("2. 平均点数増が許容範囲か")
    print("3. 100円/点ROIと1000円均等ROIが複数期間でCURRENT以上または安定するか")
    print("4. CURRENT_OUTCOME比で、穴頭TRIO_TOP1への差し替え自体にも価値があるか")
    print("5. ROIが安定しなければ自動買い目へは接続せず、穴目パネルを表示専用で完成扱いにする")
    print("6. P3を見て条件・閾値を変更しない")
    print("=" * 118)


if __name__ == "__main__":
    main()
