#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""画面候補用 AI1着率 v6（場別モーター更新日リセット版）。"""

from __future__ import annotations

import json
import sys
from pathlib import Path

import joblib
import numpy as np
import psycopg2

from ai_winrate_features import build_matrix, normalized_race_probabilities
from ai_winrate_live import (
    fetch_target_rows,
    history_stats,
    relative_features,
    valid_virtual_map,
)
from ai_winrate_live_v4 import (
    V2_MODEL_PATH,
    V4_MODEL_PATH,
    add_current_race_features,
    rank_probability,
    v4_history_stats_batch,
)
from ai_winrate_live_v5 import calculate as calculate_v5
from ai_winrate_v2_models import blend_probabilities
from ai_winrate_v4_features import build_named_matrix, build_v4_matrix

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "common"))
from db_config import load_db_config  # noqa: E402

V6_MODEL_PATH = Path(__file__).resolve().parent / "models" / "ai_winrate_v6.joblib"


def add_reset_pair_rating_features(rows: list[dict], artifact: dict) -> None:
    state = artifact["reset_pair_rating_state"]
    player_state = state.get("player", {})
    motor_state = state.get("motor", {})
    course_state = np.asarray(state.get("course", [0.0] * 7), dtype=np.float64)
    players = np.asarray(
        [float(player_state.get(str(row["player_id"]), 0.0)) for row in rows],
        dtype=np.float64,
    )
    motors = np.asarray(
        [
            float(
                motor_state.get(
                    f"{row['place_code']}:{int(row['motor_number'])}",
                    0.0,
                )
            )
            for row in rows
        ],
        dtype=np.float64,
    )
    combined = players + motors
    scores = np.asarray(
        [
            combined[index] + course_state[int(row["course"])]
            for index, row in enumerate(rows)
        ],
        dtype=np.float64,
    )
    scores -= scores.max()
    rating_probability = np.exp(scores)
    rating_probability /= rating_probability.sum()
    for index, row in enumerate(rows):
        row["reset_pair_player_rating_relative"] = float(
            players[index] - players.mean()
        )
        row["reset_pair_motor_rating_relative"] = float(
            motors[index] - motors.mean()
        )
        row["reset_pair_combined_rating_relative"] = float(
            combined[index] - combined.mean()
        )
        row["reset_pair_rating_probability"] = float(rating_probability[index])


def calculate(race_code: str, virtual_lane_to_course: str | None = None) -> dict:
    if not V6_MODEL_PATH.is_file():
        return calculate_v5(race_code, virtual_lane_to_course)
    if not V2_MODEL_PATH.is_file() or not V4_MODEL_PATH.is_file():
        return {
            "status": "waiting",
            "boats": {},
            "error": "AI1着率 v6の構成モデルがまだ学習されていません",
        }
    virtual_map = valid_virtual_map(virtual_lane_to_course)
    v2_artifact = joblib.load(V2_MODEL_PATH)
    v4_artifact = joblib.load(V4_MODEL_PATH)
    v6_artifact = joblib.load(V6_MODEL_PATH)
    if v2_artifact.get("version") != "ai_winrate_v2":
        raise RuntimeError("AI1着率 v2モデルの形式が一致しません")
    if v4_artifact.get("version") != "ai_winrate_v4":
        raise RuntimeError("AI1着率 v4モデルの形式が一致しません")
    if v6_artifact.get("version") != "ai_winrate_v6":
        raise RuntimeError("AI1着率 v6モデルの形式が一致しません")
    venue_priors = v4_artifact.get("venue_course_prior", {})

    with psycopg2.connect(**load_db_config()) as conn:
        rows = fetch_target_rows(conn, race_code)
        if len(rows) != 6 or {int(row["boat"]) for row in rows} != set(range(1, 7)):
            return {
                "status": "waiting",
                "boats": {},
                "error": "AI1着率：展示情報待ち",
            }
        required = [
            "actual_course",
            "exhibition_time",
            "start_timing",
            "lap_time",
            "around_time",
        ]
        if any(row[key] is None for row in rows for key in required):
            return {
                "status": "waiting",
                "boats": {},
                "error": "AI1着率：展示情報待ち",
            }
        for row in rows:
            boat = int(row["boat"])
            row["course"] = (
                virtual_map[boat] if virtual_map else int(row["actual_course"])
            )
        for row in rows:
            row.update(history_stats(conn, row, int(row["course"])))
        for row, stats in zip(rows, v4_history_stats_batch(conn, rows)):
            row.update(stats)
            venue = venue_priors.get(str(row["place_code"]), {}).get(
                str(int(row["course"])),
                {},
            )
            row["venue_course_win_rate"] = venue.get("rate")
            row["venue_course_count"] = venue.get("count", 0)

    relative_features(rows, "exhibition_time", "exhibition_relative")
    relative_features(rows, "start_timing", "st_relative")
    relative_features(rows, "lap_time", "lap_relative")
    relative_features(rows, "around_time", "around_relative")
    relative_features(rows, "straight_time", "straight_relative")
    add_current_race_features(rows)
    add_reset_pair_rating_features(rows, v6_artifact)

    race_codes = [race_code] * 6
    v2_matrix = build_matrix(rows, v2_artifact["place_to_id"])
    hist_probability = v2_artifact["hist_model"].predict_proba(v2_matrix)[:, 1]
    xgb_probability = v2_artifact["xgb_model"].predict_proba(v2_matrix)[:, 1]
    hist_weight = float(v2_artifact.get("blend", {}).get("hist_weight", 0.5))
    v2_probability = blend_probabilities(
        hist_probability,
        xgb_probability,
        race_codes,
        hist_weight,
    )

    v4_matrix = build_v4_matrix(rows, v4_artifact["place_to_id"])
    v4_probability = rank_probability(
        v4_artifact["model"].predict(v4_matrix),
        float(v4_artifact["temperature"]),
    )
    rating_matrix = build_named_matrix(
        rows,
        v6_artifact["place_to_id"],
        v6_artifact["feature_names"],
    )
    rating_probability = rank_probability(
        v6_artifact["model"].predict(rating_matrix),
        float(v6_artifact["temperature"]),
    )

    blend = v6_artifact["blend"]
    probabilities = (
        float(blend["v2_weight"]) * v2_probability
        + float(blend["v4_weight"]) * v4_probability
        + float(blend["rating_v4_weight"]) * rating_probability
    )
    factors = v6_artifact.get("course_calibration", {}).get("factors", {})
    probabilities = np.asarray(
        [
            probability * float(factors.get(str(int(row["course"])), 1.0))
            for row, probability in zip(rows, probabilities)
        ],
        dtype=np.float64,
    )
    probabilities = normalized_race_probabilities(probabilities, race_codes)
    boats = {
        str(row["boat"]): {
            "ai_rate": float(probability * 100.0),
            "course": int(row["course"]),
        }
        for row, probability in zip(rows, probabilities)
    }
    return {
        "status": "ok",
        "boats": boats,
        "totals": {"ai": float(probabilities.sum() * 100.0)},
        "method": {
            "name": "AI1着率 v6候補（場別モーター更新リセット）",
            "features": v6_artifact.get("feature_names", []),
        },
        "training": v6_artifact.get("training", {}),
        "blend": blend,
        "motor_reset": v6_artifact.get("motor_reset", {}),
        "course_calibration": v6_artifact.get("course_calibration", {}),
        "virtual_entry": virtual_map is not None,
    }


def main() -> int:
    if len(sys.argv) not in (2, 3):
        print(
            json.dumps(
                {
                    "status": "error",
                    "boats": {},
                    "error": "Usage: ai_winrate_live_v6.py RACE_CODE [LANE_TO_COURSE]",
                },
                ensure_ascii=False,
            )
        )
        return 1
    try:
        data = calculate(
            sys.argv[1].strip().upper(),
            sys.argv[2].strip() if len(sys.argv) == 3 else None,
        )
        print(json.dumps(data, ensure_ascii=False))
        return 0 if data["status"] == "ok" else 1
    except Exception as exc:
        print(
            json.dumps(
                {"status": "error", "boats": {}, "error": str(exc)},
                ensure_ascii=False,
            )
        )
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
