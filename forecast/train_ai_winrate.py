#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""展示後AI1着率 v1 を時系列で学習・検証して保存する。

使うのは対象レース前に分かる、場・展示進入・展示5指標・選手/モーターの
過去成績だけ。検証期間は学習に混ぜず、最後に完了済み全期間で再学習する。
"""

from __future__ import annotations

import argparse
import json
from datetime import date
from pathlib import Path

import joblib
import numpy as np
import psycopg2
from sklearn.ensemble import HistGradientBoostingClassifier
from sklearn.metrics import brier_score_loss, log_loss

from ai_winrate_features import FEATURE_NAMES, normalized_race_probabilities

ROOT = Path(__file__).resolve().parent.parent
COMMON = ROOT / "common"
import sys
sys.path.insert(0, str(COMMON))
from db_config import load_db_config  # noqa: E402


DATASET_SQL = """
WITH base AS (
    SELECT
        re.race_code,
        rm.race_date,
        SUBSTRING(re.race_code, 9, 3) AS place_code,
        re.player_id::text AS player_id,
        re.motor_number,
        el.entry_course AS course,
        el.exhibition_time,
        el.start_timing,
        el.lap_time,
        el.around_time,
        el.straight_time,
        (rrd.rank = '1') AS is_winner
    FROM boat_race.race_entry re
    JOIN boat_race.race_master rm
      ON rm.race_code = re.race_code
    JOIN boat_race.exhibition_live el
      ON el.race_code = re.race_code
     AND el.player_id = re.player_id
    LEFT JOIN boat_race.race_result_detail rrd
      ON rrd.race_code = re.race_code
     AND rrd.player_id = re.player_id
    WHERE rm.race_date < %(end_date)s::date
), valid_races AS (
    SELECT race_code
    FROM base
    WHERE race_date >= %(start_date)s::date
      AND race_date < %(end_date)s::date
    GROUP BY race_code
    HAVING COUNT(*) = 6
       AND COUNT(*) FILTER (WHERE is_winner) = 1
       AND COUNT(course) FILTER (WHERE course BETWEEN 1 AND 6) = 6
       AND COUNT(exhibition_time) = 6
       AND COUNT(start_timing) = 6
       AND COUNT(lap_time) = 6
       AND COUNT(around_time) = 6
       AND COUNT(straight_time) = 6
), all_featured AS (
    SELECT
        b.*,
        (AVG(b.exhibition_time) OVER race_window - b.exhibition_time)
            / NULLIF(STDDEV_POP(b.exhibition_time) OVER race_window, 0) AS exhibition_relative,
        (AVG(b.start_timing) OVER race_window - b.start_timing)
            / NULLIF(STDDEV_POP(b.start_timing) OVER race_window, 0) AS st_relative,
        (AVG(b.lap_time) OVER race_window - b.lap_time)
            / NULLIF(STDDEV_POP(b.lap_time) OVER race_window, 0) AS lap_relative,
        (AVG(b.around_time) OVER race_window - b.around_time)
            / NULLIF(STDDEV_POP(b.around_time) OVER race_window, 0) AS around_relative,
        (AVG(b.straight_time) OVER race_window - b.straight_time)
            / NULLIF(STDDEV_POP(b.straight_time) OVER race_window, 0) AS straight_relative,
        AVG(b.is_winner::int) OVER player_window AS player_win_rate_100,
        COUNT(*) OVER player_window AS player_win_count_100,
        AVG(b.is_winner::int) OVER player_course_window AS player_course_win_rate_50,
        COUNT(*) OVER player_course_window AS player_course_count_50,
        AVG(b.is_winner::int) OVER motor_window AS motor_win_rate_50,
        COUNT(*) OVER motor_window AS motor_count_50
    FROM base b
    WINDOW
        race_window AS (PARTITION BY b.race_code),
        player_window AS (
            PARTITION BY b.player_id
            ORDER BY b.race_code
            ROWS BETWEEN 100 PRECEDING AND 1 PRECEDING
        ),
        player_course_window AS (
            PARTITION BY b.player_id, b.course
            ORDER BY b.race_code
            ROWS BETWEEN 50 PRECEDING AND 1 PRECEDING
        ),
        motor_window AS (
            PARTITION BY b.place_code, b.motor_number
            ORDER BY b.race_code
            ROWS BETWEEN 50 PRECEDING AND 1 PRECEDING
        )
)
SELECT
    race_code, race_date, place_code, course, is_winner,
    exhibition_relative, st_relative, lap_relative, around_relative, straight_relative,
    player_win_rate_100, player_win_count_100,
    player_course_win_rate_50, player_course_count_50,
    motor_win_rate_50, motor_count_50
FROM all_featured
JOIN valid_races USING (race_code)
ORDER BY race_code, course
"""

RACE_CODE = 0
RACE_DATE = 1
PLACE_CODE = 2
COURSE = 3
IS_WINNER = 4
NUMERIC_START = 5


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--start-date", default="2025-01-01")
    parser.add_argument("--validation-start", default="2026-09-01")
    parser.add_argument("--end-date", default=date.today().isoformat())
    parser.add_argument(
        "--output",
        default=str(ROOT / "forecast" / "models" / "ai_winrate_v1.joblib"),
    )
    return parser.parse_args()


def load_rows(start_date: str, end_date: str) -> list[tuple]:
    with psycopg2.connect(**load_db_config()) as conn:
        with conn.cursor() as cur:
            cur.execute(DATASET_SQL, {"start_date": start_date, "end_date": end_date})
            # 学習データは数十万行あるため、辞書化せずタプルのまま保持する。
            return cur.fetchall()


def build_training_matrix(rows: list[tuple], place_to_id: dict[str, int]) -> np.ndarray:
    matrix = np.empty((len(rows), len(FEATURE_NAMES)), dtype=np.float64)
    for index, row in enumerate(rows):
        matrix[index, 0] = place_to_id.get(str(row[PLACE_CODE]), 0)
        matrix[index, 1] = float(row[COURSE])
        for column in range(len(FEATURE_NAMES) - 2):
            value = row[NUMERIC_START + column]
            matrix[index, column + 2] = float(value) if value is not None else float("nan")
    return matrix


def make_model() -> HistGradientBoostingClassifier:
    return HistGradientBoostingClassifier(
        learning_rate=0.06,
        max_iter=220,
        max_leaf_nodes=24,
        min_samples_leaf=80,
        l2_regularization=4.0,
        categorical_features=[True, True] + [False] * (len(FEATURE_NAMES) - 2),
        random_state=20260923,
    )


def validation_report(model, x_test, y_test, test_rows: list[tuple]) -> dict:
    raw = model.predict_proba(x_test)[:, 1]
    race_codes = [str(row[RACE_CODE]) for row in test_rows]
    normalized = normalized_race_probabilities(raw, race_codes)
    labels = np.asarray(y_test, dtype=np.int8)

    groups: dict[str, list[int]] = {}
    for index, race_code in enumerate(race_codes):
        groups.setdefault(race_code, []).append(index)
    hits = sum(labels[indices[np.argmax(normalized[indices])]] for indices in map(np.asarray, groups.values()))

    return {
        "race_count": len(groups),
        "top1_hit_rate": float(hits / len(groups)) if groups else 0.0,
        "multiclass_log_loss": float(log_loss(labels, normalized, labels=[0, 1])),
        "brier_score": float(brier_score_loss(labels, normalized)),
    }


def main() -> int:
    args = parse_args()
    if args.validation_start <= args.start_date or args.validation_start >= args.end_date:
        raise SystemExit("validation-start は start-date と end-date の間にしてください")

    rows = load_rows(args.start_date, args.end_date)
    train_rows = [row for row in rows if str(row[RACE_DATE]) < args.validation_start]
    test_rows = [row for row in rows if str(row[RACE_DATE]) >= args.validation_start]
    if not train_rows or not test_rows:
        raise SystemExit("学習または検証データがありません")

    places = sorted({str(row[PLACE_CODE]) for row in rows})
    place_to_id = {place: index + 1 for index, place in enumerate(places)}
    x_train = build_training_matrix(train_rows, place_to_id)
    y_train = np.asarray([bool(row[IS_WINNER]) for row in train_rows], dtype=np.int8)
    x_test = build_training_matrix(test_rows, place_to_id)
    y_test = np.asarray([bool(row[IS_WINNER]) for row in test_rows], dtype=np.int8)

    candidate = make_model()
    candidate.fit(x_train, y_train)
    validation = validation_report(candidate, x_test, y_test, test_rows)

    # 検証後は、検証期間を含めた完了レースすべてで本番用を再学習する。
    x_all = build_training_matrix(rows, place_to_id)
    y_all = np.asarray([bool(row[IS_WINNER]) for row in rows], dtype=np.int8)
    final_model = make_model()
    final_model.fit(x_all, y_all)

    output = Path(args.output)
    output.parent.mkdir(parents=True, exist_ok=True)
    artifact = {
        "version": "ai_winrate_v1",
        "feature_names": FEATURE_NAMES,
        "place_to_id": place_to_id,
        "model": final_model,
        "training": {
            "start_date": args.start_date,
            "trained_through_exclusive": args.end_date,
            "row_count": len(rows),
            "race_count": len(rows) // 6,
            "validation_start": args.validation_start,
            "validation": validation,
        },
    }
    joblib.dump(artifact, output, compress=3)
    print(json.dumps({"status": "ok", "output": str(output), **artifact["training"]}, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
