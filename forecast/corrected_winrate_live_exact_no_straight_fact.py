#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""直線タイムを持たない場向け Web補正後1着率 Fact高速化ラッパー。

対象:
- AMG（尼崎）
- TKY（徳山）
- SME（住之江）

既存の no-straight exact 定義をそのまま使い、
場×コースprior と SUM統計だけ Fact を優先参照する。
Factが未構築・古い場合は従来exactへ自動フォールバックする。
"""

from __future__ import annotations

import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))

# 先にFact版をimportして、exact側の場priorをFact優先へ差し替える。
import corrected_winrate_live_exact_fact as fact  # noqa: E402
# その後、直線なし用の展示/EX_TOTAL3/SUM exact を読み込む。
import corrected_winrate_live_exact_amg_tky as no_straight  # noqa: E402

# 住之江も直線タイムを持たないため、同じ定義へ追加する。
no_straight.TARGET_PLACES.add("SME")

live = no_straight.live
_LEGACY_NO_STRAIGHT_SUM = no_straight.load_sum_stats_exact_no_straight


def load_sum_stats_no_straight_fact_first(
    conn,
    race_code,
    target_date,
    place_code,
    feature_cols,
):
    """SUM Factを優先し、使えなければ直線なしexactへ戻す。"""
    stats = fact._load_sum_stats_from_fact(
        conn,
        race_code,
        target_date,
        place_code,
        feature_cols,
    )
    if stats is not None:
        return stats

    return _LEGACY_NO_STRAIGHT_SUM(
        conn,
        race_code,
        target_date,
        place_code,
        feature_cols,
    )


# no_straight import時にSUMがexactへ差し替わるため、最後にFact優先へ戻す。
live.load_sum_stats = load_sum_stats_no_straight_fact_first


if __name__ == "__main__":
    raise SystemExit(live.main())
