#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""追加特徴量版LambdaRankを学習し、AI1着率 v4モデルを保存する。"""

from __future__ import annotations

import argparse
import json
import sys
from datetime import date
from pathlib import Path

import joblib
import numpy as np

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "analysis"))

from ai_winrate_v4_features import V4_FEATURE_NAMES  # noqa: E402
from ai_winrate_v4_models import make_v4_ranker  # noqa: E402
from feature_ablation_tournament_2026 import (  # noqa: E402
    build_matrix,
    load_rows,
    variant_features,
)
from ranking_model_tournament_2026 import (  # noqa: E402
    calibrate_temperature,
    race_groups,
)
from train_ai_winrate import IS_WINNER, PLACE_CODE, RACE_DATE  # noqa: E402


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--start-date", default="2025-01-01")
    parser.add_argument("--end-date", default=date.today().isoformat())
    parser.add_argument("--calibration-start")
    parser.add_argument(
        "--output",
        default=str(ROOT / "forecast" / "models" / "ai_winrate_v4.joblib"),
    )
    args = parser.parse_args()
    end_date = date.fromisoformat(args.end_date)
    calibration_start = (
        date.fromisoformat(args.calibration_start)
        if args.calibration_start
        else date(end_date.year, end_date.month, 1)
    )
    features = variant_features()["v3_all_features"]

    print("AI1着率 v4 の時系列特徴量を構築しています…", flush=True)
    rows, columns = load_rows(args.start_date, args.end_date)
    if not rows:
        raise SystemExit("学習データがありません")
    places = sorted({str(row[PLACE_CODE]) for row in rows})
    place_to_id = {place: index + 1 for index, place in enumerate(places)}
    train_rows = [
        row for row in rows if str(row[RACE_DATE]) < calibration_start.isoformat()
    ]
    calibration_rows = [
        row for row in rows if str(row[RACE_DATE]) >= calibration_start.isoformat()
    ]
    if not train_rows or not calibration_rows:
        raise SystemExit("確率校正用の学習期間または検証期間がありません")

    print(f"{calibration_start:%Y-%m-%d}以降で温度校正しています…", flush=True)
    x_train = build_matrix(train_rows, columns, features, place_to_id)
    x_calibration = build_matrix(calibration_rows, columns, features, place_to_id)
    y_train = np.asarray([bool(row[IS_WINNER]) for row in train_rows], dtype=np.int8)
    y_calibration = np.asarray(
        [bool(row[IS_WINNER]) for row in calibration_rows], dtype=np.int8
    )
    _, _, train_group_sizes = race_groups(train_rows)
    _, calibration_groups, _ = race_groups(calibration_rows)
    calibration_model = make_v4_ranker()
    calibration_model.fit(
        x_train,
        y_train,
        group=train_group_sizes,
        categorical_feature=[0, 1],
    )
    temperature = calibrate_temperature(
        calibration_model.predict(x_calibration),
        y_calibration,
        calibration_groups,
    )

    print("完了済み全期間でv4本番モデルを学習しています…", flush=True)
    x_all = build_matrix(rows, columns, features, place_to_id)
    y_all = np.asarray([bool(row[IS_WINNER]) for row in rows], dtype=np.int8)
    _, _, all_group_sizes = race_groups(rows)
    final_model = make_v4_ranker()
    final_model.fit(
        x_all,
        y_all,
        group=all_group_sizes,
        categorical_feature=[0, 1],
    )
    venue_course_prior: dict[str, dict[str, dict[str, float | int]]] = {}
    venue_rate_column = columns["venue_course_win_rate"]
    venue_count_column = columns["venue_course_count"]
    course_column = columns["course"]
    for row in rows:
        place = str(row[PLACE_CODE])
        course = str(int(row[course_column]))
        prior_count = int(row[venue_count_column] or 0)
        prior_rate = float(row[venue_rate_column] or 0.0)
        count = prior_count + 1
        wins = prior_rate * prior_count + int(bool(row[IS_WINNER]))
        venue_course_prior.setdefault(place, {})[course] = {
            "rate": wins / count,
            "count": count,
        }
    artifact = {
        "version": "ai_winrate_v4",
        "feature_names": V4_FEATURE_NAMES,
        "place_to_id": place_to_id,
        "model": final_model,
        "temperature": temperature,
        "venue_course_prior": venue_course_prior,
        "blend": {"v2_weight": 0.15, "v4_weight": 0.85},
        "training": {
            "start_date": args.start_date,
            "trained_through_exclusive": args.end_date,
            "calibration_start": calibration_start.isoformat(),
            "row_count": len(rows),
            "race_count": len(rows) // 6,
        },
    }
    output = Path(args.output)
    output.parent.mkdir(parents=True, exist_ok=True)
    joblib.dump(artifact, output, compress=3)
    print(
        json.dumps(
            {
                "status": "ok",
                "output": str(output),
                "temperature": temperature,
                **artifact["training"],
            },
            ensure_ascii=False,
        )
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
