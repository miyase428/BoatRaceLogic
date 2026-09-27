#!/usr/bin/env python3
"""展示評価v2候補を、現行方式と同じ時系列条件で比較する。"""

from pathlib import Path

import ai_place_exhibition_ablation_2026 as comparison


comparison.OUTPUT_PATH = (
    Path(__file__).resolve().parent
    / "output"
    / "ai_place_exhibition_v2_compare_2026.json"
)
comparison.REPORT_PURPOSE = (
    "現行の展示評価を残したまま、各評価を個別に学習するv2候補を比較"
)

# CURRENT_DERIVED は現在のAI2連対率・AI3連対率で使う特徴量構成。
# v2候補は展示の各評価を分離し、固定された合計重みをモデルに強制しない。
comparison.VARIANTS = {
    "CURRENT_DERIVED": {
        "current": True,
        "derived": True,
        "raw": False,
        "components": False,
    },
    "V2_COMPONENTS": {
        "current": False,
        "derived": True,
        "raw": False,
        "components": True,
    },
    "V2_COMPONENTS_RAW": {
        "current": False,
        "derived": True,
        "raw": True,
        "components": True,
    },
    "V2_COMPONENTS_ONLY": {
        "current": False,
        "derived": False,
        "raw": False,
        "components": True,
    },
}


if __name__ == "__main__":
    raise SystemExit(comparison.main())
