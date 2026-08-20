#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""SUM履歴Fact差分更新の運用設定ラッパー。

住之江(SME)は直線タイムを持たないため、AMG/TKYと同様に
straight_time欠損を許可して既存差分更新を実行する。
"""

from __future__ import annotations

import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))

# update_sum_history_fact が同じ rebuild_sum_history_fact module をimportする前に
# 運用上の対象場設定を追加する。
import rebuild_sum_history_fact as full  # noqa: E402

full.SPECIAL_PLACES.add("SME")

import update_sum_history_fact as updater  # noqa: E402


if __name__ == "__main__":
    raise SystemExit(updater.main())
