#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""画面表示用 AI1着率 v4（v2 15% + 追加特徴量LambdaRank 85%）。"""

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
from ai_winrate_v2_models import blend_probabilities
from ai_winrate_v4_features import build_v4_matrix

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "common"))
from db_config import load_db_config  # noqa: E402

V2_MODEL_PATH = Path(__file__).resolve().parent / "models" / "ai_winrate_v2.joblib"
V4_MODEL_PATH = Path(__file__).resolve().parent / "models" / "ai_winrate_v4.joblib"


def v4_history_stats_batch(
    conn,
    rows: list[dict],
) -> list[dict[str, float | int | None]]:
    sql = """
        WITH targets AS (
            SELECT player_id, course, ordinality::int AS ord
            FROM unnest(%(player_ids)s::text[], %(courses)s::int[])
                 WITH ORDINALITY AS t(player_id, course, ordinality)
        )
        SELECT
            t.ord,
            ps.mean_st, ps.std_st, pcs.mean_st, pcs.std_st, ps.flying_count
        FROM targets t
        LEFT JOIN LATERAL (
            SELECT
                AVG(h.st) AS mean_st,
                STDDEV_POP(h.st) AS std_st,
                COUNT(*) FILTER (WHERE h.st < 0)::int AS flying_count
            FROM (
                SELECT rrd.start_timing::double precision AS st
                FROM boat_race.race_entry re
                JOIN boat_race.exhibition_live el
                  ON el.race_code = re.race_code AND el.player_id = re.player_id
                JOIN boat_race.race_result_detail rrd
                  ON rrd.race_code = re.race_code AND rrd.player_id = re.player_id
                WHERE re.player_id = t.player_id
                  AND re.race_code < %(race_code)s
                  AND rrd.start_timing IS NOT NULL
                ORDER BY re.race_code DESC
                LIMIT 50
            ) h
        ) ps ON true
        LEFT JOIN LATERAL (
            SELECT AVG(h.st) AS mean_st, STDDEV_POP(h.st) AS std_st
            FROM (
                SELECT rrd.start_timing::double precision AS st
                FROM boat_race.race_entry re
                JOIN boat_race.exhibition_live el
                  ON el.race_code = re.race_code AND el.player_id = re.player_id
                JOIN boat_race.race_result_detail rrd
                  ON rrd.race_code = re.race_code AND rrd.player_id = re.player_id
                WHERE re.player_id = t.player_id
                  AND el.entry_course = t.course
                  AND re.race_code < %(race_code)s
                  AND rrd.start_timing IS NOT NULL
                ORDER BY re.race_code DESC
                LIMIT 30
            ) h
        ) pcs ON true
        ORDER BY t.ord
    """
    params = {
        "player_ids": [str(row["player_id"]) for row in rows],
        "courses": [int(row["course"]) for row in rows],
        "race_code": str(rows[0]["race_code"]),
    }
    with conn.cursor() as cur:
        cur.execute(sql, params)
        result_rows = cur.fetchall()
    names = [
        "actual_st_mean_50",
        "actual_st_std_50",
        "course_actual_st_mean_30",
        "course_actual_st_std_30",
        "flying_start_count_50",
    ]
    return [dict(zip(names, values[1:])) for values in result_rows]


def add_current_race_features(rows: list[dict]) -> None:
    exhibitions = [float(row["exhibition_time"]) for row in rows]
    best = min(exhibitions)
    lane1 = next(
        float(row["exhibition_time"])
        for row in rows
        if int(row["boat"]) == 1
    )
    for row, exhibition in zip(rows, exhibitions):
        course = int(row["course"])
        lane = int(row["boat"])
        row["lane_number"] = lane
        row["entry_course_change"] = course - lane
        row["front_move_flag"] = int(course < lane)
        row["exhibition_rank"] = 1 + sum(value < exhibition for value in exhibitions)
        row["exhibition_gap_best"] = exhibition - best
        row["exhibition_vs_lane1"] = lane1 - exhibition
        motor_rate = row.get("motor_win_rate_50")
        player_rate = row.get("player_win_rate_100")
        row["motor_vs_player_50"] = (
            float(motor_rate) - float(player_rate)
            if motor_rate is not None and player_rate is not None
            else float("nan")
        )


def rank_probability(scores: np.ndarray, temperature: float) -> np.ndarray:
    logits = np.asarray(scores, dtype=np.float64) / max(float(temperature), 1e-6)
    logits -= np.max(logits)
    values = np.exp(logits)
    return values / values.sum()


def calculate(race_code: str, virtual_lane_to_course: str | None = None) -> dict:
    if not V2_MODEL_PATH.is_file() or not V4_MODEL_PATH.is_file():
        return {"status": "waiting", "boats": {}, "error": "AI1着率 v4モデルがまだ学習されていません"}
    virtual_map = valid_virtual_map(virtual_lane_to_course)
    v2_artifact = joblib.load(V2_MODEL_PATH)
    v4_artifact = joblib.load(V4_MODEL_PATH)
    if v2_artifact.get("version") != "ai_winrate_v2":
        raise RuntimeError("AI1着率 v2モデルの形式が一致しません")
    if v4_artifact.get("version") != "ai_winrate_v4":
        raise RuntimeError("AI1着率 v4モデルの形式が一致しません")
    venue_priors = v4_artifact.get("venue_course_prior", {})

    with psycopg2.connect(**load_db_config()) as conn:
        rows = fetch_target_rows(conn, race_code)
        if len(rows) != 6 or {int(row["boat"]) for row in rows} != set(range(1, 7)):
            return {"status": "waiting", "boats": {}, "error": "AI1着率：展示情報待ち"}
        required = ["actual_course", "exhibition_time", "start_timing", "lap_time", "around_time"]
        if any(row[key] is None for row in rows for key in required):
            return {"status": "waiting", "boats": {}, "error": "AI1着率：展示情報待ち"}
        for row in rows:
            boat = int(row["boat"])
            row["course"] = virtual_map[boat] if virtual_map else int(row["actual_course"])
        for row in rows:
            row.update(history_stats(conn, row, int(row["course"])))
        for row, stats in zip(rows, v4_history_stats_batch(conn, rows)):
            row.update(stats)
            venue = venue_priors.get(str(row["place_code"]), {}).get(str(int(row["course"])), {})
            row["venue_course_win_rate"] = venue.get("rate")
            row["venue_course_count"] = venue.get("count", 0)

    relative_features(rows, "exhibition_time", "exhibition_relative")
    relative_features(rows, "start_timing", "st_relative")
    relative_features(rows, "lap_time", "lap_relative")
    relative_features(rows, "around_time", "around_relative")
    relative_features(rows, "straight_time", "straight_relative")
    add_current_race_features(rows)

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
    v4_scores = v4_artifact["model"].predict(v4_matrix)
    v4_probability = rank_probability(v4_scores, float(v4_artifact["temperature"]))
    blend_config = v4_artifact.get("blend", {})
    v2_weight = float(blend_config.get("v2_weight", 0.15))
    v4_weight = float(blend_config.get("v4_weight", 0.85))
    probabilities = normalized_race_probabilities(
        v2_weight * v2_probability + v4_weight * v4_probability,
        race_codes,
    )
    boats = {
        str(row["boat"]): {"ai_rate": float(probability * 100.0), "course": int(row["course"])}
        for row, probability in zip(rows, probabilities)
    }
    return {
        "status": "ok",
        "boats": boats,
        "totals": {"ai": float(probabilities.sum() * 100.0)},
        "method": {
            "name": "AI1着率 v4（v2 15% + LambdaRank v4 85%）",
            "features": v4_artifact.get("feature_names", []),
        },
        "training": v4_artifact.get("training", {}),
        "blend": blend_config,
        "virtual_entry": virtual_map is not None,
    }


def main() -> int:
    if len(sys.argv) not in (2, 3):
        print(json.dumps({"status": "error", "boats": {}, "error": "Usage: ai_winrate_live_v4.py RACE_CODE [LANE_TO_COURSE]"}, ensure_ascii=False))
        return 1
    try:
        data = calculate(
            sys.argv[1].strip().upper(),
            sys.argv[2].strip() if len(sys.argv) == 3 else None,
        )
        print(json.dumps(data, ensure_ascii=False))
        return 0 if data["status"] == "ok" else 1
    except Exception as exc:
        print(json.dumps({"status": "error", "boats": {}, "error": str(exc)}, ensure_ascii=False))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
