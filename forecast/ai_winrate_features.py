#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""AI1着率 v1 で共有する特徴量定義。

入力は展示確定後に画面が持つ情報だけに限定する。結果・払戻・オッズは
一切使わず、過去成績も対象レースより前だけを集計する。
"""

from __future__ import annotations

from typing import Any

import numpy as np


NUMERIC_FEATURES = [
    "exhibition_relative",
    "st_relative",
    "lap_relative",
    "around_relative",
    "straight_relative",
    "player_win_rate_100",
    "player_win_count_100",
    "player_course_win_rate_50",
    "player_course_count_50",
    "motor_win_rate_50",
    "motor_count_50",
]
CATEGORICAL_FEATURES = ["place_code", "course"]
FEATURE_NAMES = CATEGORICAL_FEATURES + NUMERIC_FEATURES


def build_matrix(rows: list[dict[str, Any]], place_to_id: dict[str, int]) -> np.ndarray:
    """DB行をHistGradientBoosting用の数値行列に変換する。"""
    matrix = np.empty((len(rows), len(FEATURE_NAMES)), dtype=np.float64)

    for index, row in enumerate(rows):
        place = str(row["place_code"])
        matrix[index, 0] = place_to_id.get(place, 0)
        matrix[index, 1] = float(row["course"])

        for offset, name in enumerate(NUMERIC_FEATURES, start=2):
            value = row.get(name)
            try:
                numeric = float(value) if value is not None else float("nan")
            except (TypeError, ValueError):
                numeric = float("nan")
            matrix[index, offset] = numeric

    return matrix


def normalized_race_probabilities(probabilities: np.ndarray, race_codes: list[str]) -> np.ndarray:
    """各レースで必ず合計100%になるよう、個艇確率を正規化する。"""
    output = np.zeros(len(probabilities), dtype=np.float64)
    groups: dict[str, list[int]] = {}
    for index, race_code in enumerate(race_codes):
        groups.setdefault(race_code, []).append(index)

    for indices in groups.values():
        values = np.maximum(probabilities[indices], 1.0e-9)
        output[indices] = values / values.sum()

    return output
