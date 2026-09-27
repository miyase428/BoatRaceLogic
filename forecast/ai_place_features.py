#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""AI2着率・AI3着率で共有する特徴量定義。"""

from __future__ import annotations

import math
from typing import Any

import numpy as np


BASE_KEYS = (
    "v5_probability",
    "v5_rank",
    "first_score",
    "first_rank",
    "second_score",
    "second_rank",
    "final3",
    "final_rank",
    "rate6",
    "rate3",
    "lane",
    "course",
)

DIFF_KEYS = (
    "v5_probability",
    "first_score",
    "second_score",
    "final3",
    "course",
)


def _scaled(key: str, value: Any) -> float:
    try:
        x = float(value)
    except (TypeError, ValueError):
        return float("nan")
    if not math.isfinite(x):
        return float("nan")
    if key == "v5_probability":
        return x
    if key in {"v5_rank", "first_rank", "second_rank", "final_rank", "lane", "course"}:
        return x / 6.0
    if key in {"rate6", "rate3"}:
        return x
    return x / 100.0


def _block(prefix: str, row: dict[str, Any]) -> tuple[list[str], list[float]]:
    names = [f"{prefix}_{key}" for key in BASE_KEYS]
    values = [_scaled(key, row.get(key)) for key in BASE_KEYS]
    probability = max(float(row.get("v5_probability", 0.0) or 0.0), 1.0e-12)
    names.append(f"{prefix}_log_v5_probability")
    values.append(math.log(probability))
    return names, values


def feature_names(include_second: bool) -> list[str]:
    names, _ = _block("candidate", {})
    head_names, _ = _block("head", {})
    names.extend(head_names)
    names.extend(f"candidate_minus_head_{key}" for key in DIFF_KEYS)
    if include_second:
        second_names, _ = _block("second", {})
        names.extend(second_names)
        names.extend(f"candidate_minus_second_{key}" for key in DIFF_KEYS)
    names.extend(["race_number", "place_id"])
    return names


def vector(
    candidate: dict[str, Any],
    head: dict[str, Any],
    race_number: int,
    place_id: int,
    second: dict[str, Any] | None = None,
) -> np.ndarray:
    _, values = _block("candidate", candidate)
    _, head_values = _block("head", head)
    values.extend(head_values)
    for key in DIFF_KEYS:
        a = _scaled(key, candidate.get(key))
        b = _scaled(key, head.get(key))
        values.append(a - b)

    if second is not None:
        _, second_values = _block("second", second)
        values.extend(second_values)
        for key in DIFF_KEYS:
            a = _scaled(key, candidate.get(key))
            b = _scaled(key, second.get(key))
            values.append(a - b)

    values.extend([float(race_number) / 12.0, float(place_id)])
    return np.asarray(values, dtype=np.float64)
