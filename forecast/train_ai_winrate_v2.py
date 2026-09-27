#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""HistGradientBoosting + XGBoost のAI1着率 v2を学習して保存する。"""

from __future__ import annotations

import argparse
import json
from datetime import date
from pathlib import Path

import joblib
import numpy as np

from ai_winrate_features import FEATURE_NAMES
from ai_winrate_v2_models import make_hist_gradient_boosting, make_xgboost
from train_ai_winrate import IS_WINNER, PLACE_CODE, build_training_matrix, load_rows

ROOT = Path(__file__).resolve().parent.parent


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--start-date", default="2025-01-01")
    parser.add_argument("--end-date", default=date.today().isoformat())
    parser.add_argument(
        "--output",
        default=str(ROOT / "forecast" / "models" / "ai_winrate_v2.joblib"),
    )
    args = parser.parse_args()

    print("AI1着率 v2 の学習データを読み込んでいます…", flush=True)
    rows = load_rows(args.start_date, args.end_date)
    if not rows:
        raise SystemExit("学習データがありません")

    places = sorted({str(row[PLACE_CODE]) for row in rows})
    place_to_id = {place: index + 1 for index, place in enumerate(places)}
    matrix = build_training_matrix(rows, place_to_id)
    labels = np.asarray([bool(row[IS_WINNER]) for row in rows], dtype=np.int8)

    print("HistGradientBoostingを学習しています…", flush=True)
    hist_model = make_hist_gradient_boosting()
    hist_model.fit(matrix, labels)

    print("XGBoostを学習しています…", flush=True)
    xgb_model = make_xgboost()
    xgb_model.fit(matrix, labels)

    artifact = {
        "version": "ai_winrate_v2",
        "feature_names": FEATURE_NAMES,
        "place_to_id": place_to_id,
        "hist_model": hist_model,
        "xgb_model": xgb_model,
        "blend": {"hist_weight": 0.5, "xgb_weight": 0.5},
        "training": {
            "start_date": args.start_date,
            "trained_through_exclusive": args.end_date,
            "row_count": len(rows),
            "race_count": len(rows) // 6,
        },
    }
    output = Path(args.output)
    output.parent.mkdir(parents=True, exist_ok=True)
    joblib.dump(artifact, output, compress=3)
    print(json.dumps({"status": "ok", "output": str(output), **artifact["training"]}, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
