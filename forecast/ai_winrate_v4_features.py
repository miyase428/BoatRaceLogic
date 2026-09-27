#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""AI1着率 v4 の追加特徴量と行列化。"""

from __future__ import annotations

from typing import Any

import numpy as np

from ai_winrate_features import FEATURE_NAMES

V4_EXTRA_FEATURES = [
    "actual_st_mean_50",
    "actual_st_std_50",
    "course_actual_st_mean_30",
    "course_actual_st_std_30",
    "flying_start_count_50",
    "lane_number",
    "entry_course_change",
    "front_move_flag",
    "venue_course_win_rate",
    "venue_course_count",
    "exhibition_rank",
    "exhibition_gap_best",
    "exhibition_vs_lane1",
    "national_win_rate",
    "national_exacta_rate",
    "local_win_rate",
    "local_exacta_rate",
    "motor_vs_player_50",
]
V4_FEATURE_NAMES = FEATURE_NAMES + V4_EXTRA_FEATURES


def build_named_matrix(
    rows: list[dict[str, Any]],
    place_to_id: dict[str, int],
    feature_names: list[str] | tuple[str, ...],
) -> np.ndarray:
    matrix = np.empty((len(rows), len(feature_names)), dtype=np.float64)
    for row_index, row in enumerate(rows):
        matrix[row_index, 0] = place_to_id.get(str(row["place_code"]), 0)
        matrix[row_index, 1] = float(row["course"])
        for column_index, feature in enumerate(feature_names[2:], start=2):
            value = row.get(feature)
            try:
                matrix[row_index, column_index] = (
                    float(value) if value is not None else float("nan")
                )
            except (TypeError, ValueError):
                matrix[row_index, column_index] = float("nan")
    return matrix


def build_v4_matrix(rows: list[dict[str, Any]], place_to_id: dict[str, int]) -> np.ndarray:
    return build_named_matrix(rows, place_to_id, V4_FEATURE_NAMES)
