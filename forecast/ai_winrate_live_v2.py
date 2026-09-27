#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""画面表示用 AI1着率 v2（HistGradientBoosting + XGBoost）。"""

from __future__ import annotations

import json
import sys
from pathlib import Path

import joblib

from ai_winrate_features import build_matrix, normalized_race_probabilities
from ai_winrate_live import (
    fetch_target_rows,
    history_stats,
    relative_features,
    valid_virtual_map,
)
from ai_winrate_v2_models import blend_probabilities

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "common"))
from db_config import load_db_config  # noqa: E402
import psycopg2  # noqa: E402

MODEL_PATH = Path(__file__).resolve().parent / "models" / "ai_winrate_v2.joblib"


def calculate(race_code: str, virtual_lane_to_course: str | None = None) -> dict:
    if not MODEL_PATH.is_file():
        return {"status": "waiting", "boats": {}, "error": "AI1着率 v2モデルがまだ学習されていません"}
    virtual_map = valid_virtual_map(virtual_lane_to_course)
    artifact = joblib.load(MODEL_PATH)
    if artifact.get("version") != "ai_winrate_v2":
        raise RuntimeError("AI1着率 v2モデルの形式が一致しません")

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
            row.update(history_stats(conn, row, int(row["course"])))

    relative_features(rows, "exhibition_time", "exhibition_relative")
    relative_features(rows, "start_timing", "st_relative")
    relative_features(rows, "lap_time", "lap_relative")
    relative_features(rows, "around_time", "around_relative")
    relative_features(rows, "straight_time", "straight_relative")

    matrix = build_matrix(rows, artifact["place_to_id"])
    hist_prob = artifact["hist_model"].predict_proba(matrix)[:, 1]
    xgb_prob = artifact["xgb_model"].predict_proba(matrix)[:, 1]
    hist_weight = float(artifact.get("blend", {}).get("hist_weight", 0.5))
    race_codes = [race_code] * len(rows)
    raw = blend_probabilities(hist_prob, xgb_prob, race_codes, hist_weight)
    probabilities = normalized_race_probabilities(raw, race_codes)

    boats = {
        str(row["boat"]): {"ai_rate": float(probability * 100.0), "course": int(row["course"])}
        for row, probability in zip(rows, probabilities)
    }
    return {
        "status": "ok",
        "boats": boats,
        "totals": {"ai": float(probabilities.sum() * 100.0)},
        "method": {"name": "Ensemble v2 (HGB 50% + XGB 50%)", "features": artifact.get("feature_names", [])},
        "training": artifact.get("training", {}),
        "blend": artifact.get("blend", {}),
        "virtual_entry": virtual_map is not None,
    }


def main() -> int:
    if len(sys.argv) not in (2, 3):
        print(json.dumps({"status": "error", "boats": {}, "error": "Usage: ai_winrate_live_v2.py RACE_CODE [LANE_TO_COURSE]"}, ensure_ascii=False))
        return 1
    try:
        data = calculate(sys.argv[1].strip().upper(), sys.argv[2].strip() if len(sys.argv) == 3 else None)
        print(json.dumps(data, ensure_ascii=False))
        return 0 if data["status"] == "ok" else 1
    except Exception as exc:
        print(json.dumps({"status": "error", "boats": {}, "error": str(exc)}, ensure_ascii=False))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
