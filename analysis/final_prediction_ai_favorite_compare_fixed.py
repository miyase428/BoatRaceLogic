#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
final_prediction_ai_favorite_compare.py の評価母集団修正版。

元版は1レース6艇すべての actual_rank > 0 を要求していたため、
本命比較に無関係な艇の結果欠損・失格等でもレース全体を除外していた。

この修正版では、同一母集団で公平比較するために
  CURRENT
  WIN_TOP1
  OUTCOME_HEAD_TOP1
  TRIO_TOP1
の「実際に候補となる艇」の actual_rank が取得できるレースだけを採用する。

それ以外の計算・閾値選択・出力は元版をそのまま利用する。

Usage:
python3 analysis/final_prediction_ai_favorite_compare_fixed.py \
  analysis/output/final_prediction_boats_20260615_20260714_OLD.csv \
  analysis/output/final_prediction_boats_20260715_20260814_OLD.csv
"""

from collections import defaultdict

import final_prediction_ai_favorite_compare as b1


def build_eval_records_fixed(common, csv_races):
    out = {"P1": [], "P2": []}
    skip = defaultdict(int)

    for period in ("P1", "P2"):
        for record in common[period]:
            code = str(record["race_code"])
            boats = csv_races.get(code)
            if boats is None or set(boats) != set(range(1, 7)):
                skip[f"{period}_csv_missing"] += 1
                continue

            current = b1.current_favorite(boats)
            if current is None:
                skip[f"{period}_current_invalid"] += 1
                continue

            win, trio, outcome_head = b1.marginal_signals(record)
            win_top, win_p, win_gap = b1.top_info(win)
            trio_top, trio_p, trio_gap = b1.top_info(trio)
            head_top, head_p, head_gap = b1.top_info(outcome_head)

            actual = {
                lane: float(boats[lane]["actual_rank"])
                for lane in range(1, 7)
            }

            # 本命方式の公平比較に実際に必要な候補艇だけ着順必須とする。
            # 無関係な艇の結果欠損でレース全体を落とさない。
            required_lanes = {current, win_top, trio_top, head_top}
            if any(actual.get(lane, 0.0) <= 0.0 for lane in required_lanes):
                skip[f"{period}_candidate_actual_invalid"] += 1
                continue

            out[period].append({
                "race_code": code,
                "actual": actual,
                "current": current,
                "win_top": win_top,
                "trio_top": trio_top,
                "head_top": head_top,
                "win_gap": win_gap,
                "head_gap": head_gap,
                "win_p": win_p,
                "trio_p": trio_p,
                "head_p": head_p,
            })
            skip[f"{period}_ready"] += 1

    return out, skip


# 元版main()が参照する関数だけ差し替える。
b1.build_eval_records = build_eval_records_fixed


if __name__ == "__main__":
    b1.main()
