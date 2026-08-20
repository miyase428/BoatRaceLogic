#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""SUM履歴Factフル再構築の運用設定ラッパー。

住之江(SME)は直線タイムを持たないため、AMG/TKYと同様に
straight_time欠損を許可して既存rebuild処理を実行する。

Usage:
  python3 analysis/rebuild_sum_history_fact_configured.py SME
  python3 analysis/rebuild_sum_history_fact_configured.py ALL
"""

from __future__ import annotations

import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))

import rebuild_sum_history_fact as full  # noqa: E402

full.SPECIAL_PLACES.add("SME")


if __name__ == "__main__":
    raise SystemExit(full.main())
