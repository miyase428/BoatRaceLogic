#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""場別モーター更新日リセット版LambdaRankを学習し、v6候補を保存する。"""

from __future__ import annotations

import argparse
import csv
import gzip
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
from ai_winrate_motor_reset_ablation_2026 import (  # noqa: E402
    RESET_PAIR_FEATURES,
    append_pair_reset_features_with_state,
    load_motor_reset_dates,
)
from ai_winrate_v4_rating_ablation_2026 import load_metadata  # noqa: E402
from feature_ablation_tournament_2026 import (  # noqa: E402
    build_matrix,
    load_rows,
    variant_features,
)
from ranking_model_tournament_2026 import (  # noqa: E402
    calibrate_temperature,
    previous_month,
    race_groups,
)
from train_ai_winrate import IS_WINNER, PLACE_CODE, RACE_DATE  # noqa: E402

RESET_PREDICTION_COLUMN = "trio_reset_v2_15_base_21_pair_64"
COURSE_STRENGTH = 500


def course_calibration_start(calibration_start: date, months: int = 3) -> date:
    result = calibration_start
    for _ in range(months):
        result = previous_month(result)
    return result


def load_course_factors(
    path: Path,
    start: date,
    end_exclusive: date,
) -> tuple[dict[str, float], int]:
    totals = {
        course: {"n": 0, "predicted": 0.0, "wins": 0}
        for course in range(1, 7)
    }
    with gzip.open(path, "rt", encoding="utf-8", newline="") as handle:
        reader = csv.DictReader(handle)
        if RESET_PREDICTION_COLUMN not in (reader.fieldnames or []):
            raise RuntimeError(
                f"予測キャッシュに {RESET_PREDICTION_COLUMN} がありません"
            )
        for row in reader:
            race_date = date.fromisoformat(row["race_date"])
            if not (start <= race_date < end_exclusive):
                continue
            course = int(row["course"])
            item = totals[course]
            item["n"] += 1
            item["predicted"] += float(row[RESET_PREDICTION_COLUMN])
            item["wins"] += int(row["label"])
    factors: dict[str, float] = {}
    for course, item in totals.items():
        n = int(item["n"])
        if not n:
            raise RuntimeError(f"コース補正履歴がありません: {course}コース")
        predicted_mean = float(item["predicted"]) / n
        shrunk_rate = (
            float(item["wins"]) + COURSE_STRENGTH * predicted_mean
        ) / (n + COURSE_STRENGTH)
        factors[str(course)] = shrunk_rate / predicted_mean
    return factors, sum(int(item["n"]) for item in totals.values()) // 6


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--start-date", default="2025-01-01")
    parser.add_argument("--end-date", default=date.today().isoformat())
    parser.add_argument("--calibration-start")
    parser.add_argument(
        "--prediction-cache",
        type=Path,
        default=(
            ROOT
            / "analysis"
            / "output"
            / "ai_winrate_motor_reset_ensemble_2026_predictions.csv.gz"
        ),
    )
    parser.add_argument(
        "--output",
        type=Path,
        default=ROOT / "forecast" / "models" / "ai_winrate_v6.joblib",
    )
    args = parser.parse_args()
    end_date = date.fromisoformat(args.end_date)
    calibration_start = (
        date.fromisoformat(args.calibration_start)
        if args.calibration_start
        else date(end_date.year, end_date.month, 1)
    )

    print("AI1着率 v6 の場別リセットRating特徴量を構築しています…", flush=True)
    rows, columns = load_rows(args.start_date, args.end_date)
    if not rows:
        raise SystemExit("学習データがありません")
    valid_keys = {
        (str(row[0]), int(row[columns["course"]]))
        for row in rows
    }
    metadata = load_metadata(args.start_date, args.end_date, valid_keys)
    if valid_keys - metadata.keys():
        raise RuntimeError("Ratingメタデータが不足しています")
    reset_dates = load_motor_reset_dates(args.start_date, args.end_date)
    rows, columns, _, reset_system = append_pair_reset_features_with_state(
        rows,
        columns,
        metadata,
        reset_dates,
    )
    del metadata, valid_keys

    features = variant_features()["v3_all_features"] + RESET_PAIR_FEATURES
    feature_names = V4_FEATURE_NAMES + list(RESET_PAIR_FEATURES)
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

    print(f"{calibration_start:%Y-%m-%d}以降でv6の温度校正をしています…", flush=True)
    x_train = build_matrix(train_rows, columns, features, place_to_id)
    x_calibration = build_matrix(
        calibration_rows,
        columns,
        features,
        place_to_id,
    )
    y_train = np.asarray(
        [bool(row[IS_WINNER]) for row in train_rows],
        dtype=np.int8,
    )
    y_calibration = np.asarray(
        [bool(row[IS_WINNER]) for row in calibration_rows],
        dtype=np.int8,
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

    print("完了済み全期間でv6候補モデルを学習しています…", flush=True)
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

    factor_start = course_calibration_start(calibration_start)
    print(
        f"{factor_start}～{calibration_start} の未学習予測でコース補正を作っています…",
        flush=True,
    )
    factors, factor_races = load_course_factors(
        args.prediction_cache,
        factor_start,
        calibration_start,
    )
    reset_state = {
        "player": dict(reset_system.player),
        "motor": {
            f"{place}:{motor}": value
            for (place, motor), value in reset_system.motor.items()
        },
        "course": reset_system.course.tolist(),
    }
    artifact = {
        "version": "ai_winrate_v6",
        "feature_names": feature_names,
        "place_to_id": place_to_id,
        "model": final_model,
        "temperature": temperature,
        "reset_pair_rating_state": reset_state,
        "blend": {
            "v2_weight": 0.15,
            "v4_weight": 0.2125,
            "rating_v4_weight": 0.6375,
        },
        "motor_reset": {
            "rule": (
                "clear only venue motor ratings before the first race day of a "
                "DB-observed >=25 motors and >=90% zero exacta-rate episode"
            ),
            "events": [
                {"race_date": reset_date, "place": place}
                for reset_date, place in sorted(reset_dates)
            ],
        },
        "course_calibration": {
            "strength": COURSE_STRENGTH,
            "factors": factors,
            "source_start": factor_start.isoformat(),
            "source_end_exclusive": calibration_start.isoformat(),
            "source_races": factor_races,
            "prediction_column": RESET_PREDICTION_COLUMN,
        },
        "training": {
            "start_date": args.start_date,
            "trained_through_exclusive": args.end_date,
            "calibration_start": calibration_start.isoformat(),
            "row_count": len(rows),
            "race_count": len(rows) // 6,
        },
    }
    args.output.parent.mkdir(parents=True, exist_ok=True)
    joblib.dump(artifact, args.output, compress=3)
    print(
        json.dumps(
            {
                "status": "ok",
                "output": str(args.output),
                "temperature": temperature,
                "course_factors": factors,
                "motor_reset_events": len(reset_dates),
                **artifact["training"],
            },
            ensure_ascii=False,
        ),
        flush=True,
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
