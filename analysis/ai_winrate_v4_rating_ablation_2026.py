#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""選手とモーターを分離したオンラインRating特徴量をv4へ追加検証する。

Ratingは日単位でスナップショットを作り、その日の結果を全て計算した後に
更新する。したがって対象レースおよび同日後続レースの結果は特徴量へ入らない。
"""

from __future__ import annotations

import argparse
import gc
import json
import sys
from collections import defaultdict
from dataclasses import dataclass, field
from datetime import date
from pathlib import Path

import numpy as np
import psycopg2

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "common"))
sys.path.insert(0, str(ROOT / "forecast"))
sys.path.insert(0, str(ROOT / "analysis"))

from db_config import load_db_config  # noqa: E402
from feature_ablation_tournament_2026 import (  # noqa: E402
    build_matrix,
    load_rows,
    variant_features,
)
from ranking_model_tournament_2026 import (  # noqa: E402
    add_result,
    blank_result,
    calibrate_temperature,
    finalize,
    following_month,
    make_lightgbm_ranker,
    previous_month,
    race_groups,
    rank_probabilities,
    score_probabilities,
)
from train_ai_winrate import IS_WINNER, PLACE_CODE, RACE_CODE, RACE_DATE  # noqa: E402

METADATA_SQL = """
SELECT
    re.race_code,
    el.entry_course AS course,
    re.player_id::text AS player_id,
    SUBSTRING(re.race_code, 9, 3) AS place_code,
    re.motor_number,
    rrd.rank
FROM boat_race.race_entry re
JOIN boat_race.race_master rm
  ON rm.race_code = re.race_code
JOIN boat_race.exhibition_live el
  ON el.race_code = re.race_code
 AND el.player_id = re.player_id
LEFT JOIN boat_race.race_result_detail rrd
  ON rrd.race_code = re.race_code
 AND rrd.player_id = re.player_id
WHERE rm.race_date >= %(start_date)s::date
  AND rm.race_date < %(end_date)s::date
ORDER BY re.race_code, el.entry_course
"""

WIN_FEATURES = (
    "win_player_rating_relative",
    "win_motor_rating_relative",
    "win_combined_rating_relative",
    "win_rating_probability",
)
PAIR_FEATURES = (
    "pair_player_rating_relative",
    "pair_motor_rating_relative",
    "pair_combined_rating_relative",
    "pair_rating_probability",
)
PARTIAL_FEATURES = (
    "partial_player_rating_relative",
    "partial_motor_rating_relative",
    "partial_combined_rating_relative",
    "partial_rating_probability",
)


@dataclass
class RatingSystem:
    player_learning_rate: float
    motor_learning_rate: float
    course_learning_rate: float
    player: defaultdict[str, float] = field(default_factory=lambda: defaultdict(float))
    motor: defaultdict[tuple[str, int], float] = field(default_factory=lambda: defaultdict(float))
    course: np.ndarray = field(default_factory=lambda: np.zeros(7, dtype=np.float64))

    def scores(self, metadata: list[tuple[str, str, int, int, int | None]]) -> np.ndarray:
        return np.asarray(
            [
                self.player[player_id]
                + self.motor[(place, motor_number)]
                + self.course[course]
                for player_id, place, motor_number, course, _ in metadata
            ],
            dtype=np.float64,
        )

    def components(
        self, metadata: list[tuple[str, str, int, int, int | None]]
    ) -> tuple[np.ndarray, np.ndarray, np.ndarray, np.ndarray]:
        players = np.asarray([self.player[item[0]] for item in metadata], dtype=np.float64)
        motors = np.asarray(
            [self.motor[(item[1], item[2])] for item in metadata], dtype=np.float64
        )
        combined = players + motors
        logits = self.scores(metadata)
        logits -= logits.max()
        probabilities = np.exp(logits)
        probabilities /= probabilities.sum()
        return (
            players - players.mean(),
            motors - motors.mean(),
            combined - combined.mean(),
            probabilities,
        )

    def apply(
        self,
        player_delta: dict[str, float],
        motor_delta: dict[tuple[str, int], float],
        course_delta: np.ndarray,
    ) -> None:
        for key, value in player_delta.items():
            self.player[key] += self.player_learning_rate * value
        for key, value in motor_delta.items():
            self.motor[key] += self.motor_learning_rate * value
        self.course += self.course_learning_rate * course_delta


def softmax(values: np.ndarray) -> np.ndarray:
    shifted = values - values.max()
    result = np.exp(shifted)
    return result / result.sum()


def load_metadata(
    start_date: str,
    end_date: str,
    valid_keys: set[tuple[str, int]],
) -> dict[tuple[str, int], tuple[str, str, int, int, int | None]]:
    with psycopg2.connect(**load_db_config()) as conn:
        with conn.cursor() as cursor:
            cursor.execute(
                METADATA_SQL,
                {"start_date": start_date, "end_date": end_date},
            )
            result: dict[tuple[str, int], tuple[str, str, int, int, int | None]] = {}
            for race_code, course, player_id, place, motor, rank in cursor.fetchall():
                key = (str(race_code), int(course))
                if key not in valid_keys:
                    continue
                numeric_rank = int(rank) if str(rank).isdigit() and int(rank) > 0 else None
                result[key] = (
                    str(player_id),
                    str(place),
                    int(motor),
                    int(course),
                    numeric_rank,
                )
            return result


def win_gradients(
    system: RatingSystem,
    metadata: list[tuple[str, str, int, int, int | None]],
    labels: np.ndarray,
) -> np.ndarray:
    return labels - softmax(system.scores(metadata))


def pair_gradients(
    system: RatingSystem,
    metadata: list[tuple[str, str, int, int, int | None]],
    include_unknown_bottom: bool = False,
) -> np.ndarray:
    scores = system.scores(metadata)
    ranks = [item[4] for item in metadata]
    gradients = np.zeros(len(metadata), dtype=np.float64)
    comparisons = np.zeros(len(metadata), dtype=np.float64)
    for left in range(len(metadata)):
        for right in range(left + 1, len(metadata)):
            left_rank = ranks[left]
            right_rank = ranks[right]
            if left_rank is None and right_rank is None:
                continue
            if left_rank is None or right_rank is None:
                if not include_unknown_bottom:
                    continue
                # 数値着順がある艇は、着順不明の下位艇より先着したとみなす。
                observed_left = float(left_rank is not None)
            else:
                if left_rank == right_rank:
                    continue
                observed_left = float(left_rank < right_rank)
            difference = float(np.clip(scores[left] - scores[right], -30.0, 30.0))
            expected_left = 1.0 / (1.0 + np.exp(-difference))
            residual = observed_left - expected_left
            gradients[left] += residual
            gradients[right] -= residual
            comparisons[left] += 1
            comparisons[right] += 1
    selected = comparisons > 0
    gradients[selected] /= comparisons[selected]
    return gradients


def add_delta(
    metadata: list[tuple[str, str, int, int, int | None]],
    gradients: np.ndarray,
    player_delta: dict[str, float],
    motor_delta: dict[tuple[str, int], float],
    course_delta: np.ndarray,
    courses: list[int],
) -> None:
    for item, gradient, course in zip(metadata, gradients, courses):
        player_id, place, motor_number, _, _ = item
        player_delta[player_id] += float(gradient)
        motor_delta[(place, motor_number)] += float(gradient)
        course_delta[course] += float(gradient)


def append_all_rating_features_with_state(
    rows: list[tuple],
    columns: dict[str, int],
    metadata_by_key: dict[tuple[str, int], tuple[str, str, int, int, int | None]],
) -> tuple[
    list[tuple], dict[str, int], RatingSystem, RatingSystem, RatingSystem
]:
    win_system = RatingSystem(0.08, 0.06, 0.02)
    pair_system = RatingSystem(0.04, 0.03, 0.01)
    partial_system = RatingSystem(0.04, 0.03, 0.01)
    additions: dict[tuple[str, int], tuple[float, ...]] = {}

    races_by_day: dict[str, list[list[tuple]]] = defaultdict(list)
    position = 0
    while position < len(rows):
        race_code = str(rows[position][RACE_CODE])
        end = position
        while end < len(rows) and str(rows[end][RACE_CODE]) == race_code:
            end += 1
        races_by_day[str(rows[position][RACE_DATE])].append(rows[position:end])
        position = end

    for race_date in sorted(races_by_day):
        win_player_delta: defaultdict[str, float] = defaultdict(float)
        win_motor_delta: defaultdict[tuple[str, int], float] = defaultdict(float)
        win_course_delta = np.zeros(7, dtype=np.float64)
        pair_player_delta: defaultdict[str, float] = defaultdict(float)
        pair_motor_delta: defaultdict[tuple[str, int], float] = defaultdict(float)
        pair_course_delta = np.zeros(7, dtype=np.float64)
        partial_player_delta: defaultdict[str, float] = defaultdict(float)
        partial_motor_delta: defaultdict[tuple[str, int], float] = defaultdict(float)
        partial_course_delta = np.zeros(7, dtype=np.float64)

        for race_rows in races_by_day[race_date]:
            race_code = str(race_rows[0][RACE_CODE])
            courses = [int(row[columns["course"]]) for row in race_rows]
            metadata = [metadata_by_key[(race_code, course)] for course in courses]
            labels = np.asarray([bool(row[IS_WINNER]) for row in race_rows], dtype=np.float64)
            win_components = win_system.components(metadata)
            pair_components = pair_system.components(metadata)
            partial_components = partial_system.components(metadata)
            for index, course in enumerate(courses):
                additions[(race_code, course)] = tuple(
                    float(component[index])
                    for component in (
                        *win_components,
                        *pair_components,
                        *partial_components,
                    )
                )

            add_delta(
                metadata,
                win_gradients(win_system, metadata, labels),
                win_player_delta,
                win_motor_delta,
                win_course_delta,
                courses,
            )
            add_delta(
                metadata,
                pair_gradients(pair_system, metadata),
                pair_player_delta,
                pair_motor_delta,
                pair_course_delta,
                courses,
            )
            add_delta(
                metadata,
                pair_gradients(
                    partial_system,
                    metadata,
                    include_unknown_bottom=True,
                ),
                partial_player_delta,
                partial_motor_delta,
                partial_course_delta,
                courses,
            )

        # 同日の全特徴量を確定してから、その日の結果をまとめて反映する。
        win_system.apply(win_player_delta, win_motor_delta, win_course_delta)
        pair_system.apply(pair_player_delta, pair_motor_delta, pair_course_delta)
        partial_system.apply(
            partial_player_delta,
            partial_motor_delta,
            partial_course_delta,
        )

    feature_names = WIN_FEATURES + PAIR_FEATURES + PARTIAL_FEATURES
    next_columns = dict(columns)
    next_columns.update(
        {name: len(rows[0]) + index for index, name in enumerate(feature_names)}
    )
    enriched = [
        tuple(row) + additions[(str(row[RACE_CODE]), int(row[columns["course"]]))]
        for row in rows
    ]
    return enriched, next_columns, win_system, pair_system, partial_system


def append_rating_features_with_state(
    rows: list[tuple],
    columns: dict[str, int],
    metadata_by_key: dict[tuple[str, int], tuple[str, str, int, int, int | None]],
) -> tuple[list[tuple], dict[str, int], RatingSystem, RatingSystem]:
    enriched, next_columns, win_system, pair_system, _ = (
        append_all_rating_features_with_state(rows, columns, metadata_by_key)
    )
    return enriched, next_columns, win_system, pair_system


def append_rating_features(
    rows: list[tuple],
    columns: dict[str, int],
    metadata_by_key: dict[tuple[str, int], tuple[str, str, int, int, int | None]],
) -> tuple[list[tuple], dict[str, int]]:
    enriched, next_columns, _, _ = append_rating_features_with_state(
        rows, columns, metadata_by_key
    )
    return enriched, next_columns


def fit_predict(
    train_rows: list[tuple],
    predict_rows: list[tuple],
    columns: dict[str, int],
    variants: dict[str, tuple[str, ...]],
    place_to_id: dict[str, int],
) -> tuple[dict[str, np.ndarray], list[np.ndarray], np.ndarray]:
    y_train = np.asarray([bool(row[IS_WINNER]) for row in train_rows], dtype=np.int8)
    labels = np.asarray([bool(row[IS_WINNER]) for row in predict_rows], dtype=np.int8)
    _, _, train_group_sizes = race_groups(train_rows)
    _, groups, _ = race_groups(predict_rows)
    scores: dict[str, np.ndarray] = {}
    for name, features in variants.items():
        x_train = build_matrix(train_rows, columns, features, place_to_id)
        x_predict = build_matrix(predict_rows, columns, features, place_to_id)
        model = make_lightgbm_ranker()
        model.fit(
            x_train,
            y_train,
            group=train_group_sizes,
            categorical_feature=[0, 1],
        )
        scores[name] = np.asarray(model.predict(x_predict), dtype=np.float64)
        del x_train, x_predict, model
    gc.collect()
    return scores, groups, labels


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--start", default="2026-01-01")
    parser.add_argument("--end-exclusive", default="2026-09-23")
    parser.add_argument("--history-start", default="2025-01-01")
    parser.add_argument(
        "--output",
        type=Path,
        default=ROOT / "analysis" / "output" / "ai_winrate_v4_rating_ablation_2026.json",
    )
    args = parser.parse_args()
    start = date.fromisoformat(args.start)
    end_exclusive = date.fromisoformat(args.end_exclusive)

    print("v4データを読み込んでいます…", flush=True)
    rows, columns = load_rows(args.history_start, args.end_exclusive)
    valid_keys = {
        (str(row[RACE_CODE]), int(row[columns["course"]]))
        for row in rows
    }
    print("選手・モーター・着順メタデータを読み込んでいます…", flush=True)
    metadata = load_metadata(args.history_start, args.end_exclusive, valid_keys)
    missing = valid_keys - metadata.keys()
    if missing:
        raise RuntimeError(f"Ratingメタデータ不足: {len(missing)}件")
    print("日次オンラインRating特徴量を構築しています…", flush=True)
    rows, columns = append_rating_features(rows, columns, metadata)
    del metadata, valid_keys
    gc.collect()

    v4_features = variant_features()["v3_all_features"]
    variants = {
        "v4_baseline": v4_features,
        "v4_plus_win_rating": v4_features + WIN_FEATURES,
        "v4_plus_pair_rating": v4_features + PAIR_FEATURES,
        "v4_plus_partial_rating": v4_features + PARTIAL_FEATURES,
        "v4_plus_both_ratings": v4_features + WIN_FEATURES + PAIR_FEATURES,
    }
    places = sorted({str(row[PLACE_CODE]) for row in rows})
    place_to_id = {place: index + 1 for index, place in enumerate(places)}

    calibration_start = previous_month(start)
    initial_train = [
        row for row in rows if str(row[RACE_DATE]) < calibration_start.isoformat()
    ]
    initial_calibration = [
        row for row in rows
        if calibration_start.isoformat() <= str(row[RACE_DATE]) < start.isoformat()
    ]
    print(f"{calibration_start:%Y-%m} で初期温度校正しています…", flush=True)
    scores, groups, labels = fit_predict(
        initial_train, initial_calibration, columns, variants, place_to_id
    )
    temperatures = {
        name: calibrate_temperature(score_values, labels, groups)
        for name, score_values in scores.items()
    }
    del initial_train, initial_calibration, scores, groups, labels
    gc.collect()

    records = {name: blank_result() for name in variants}
    current = start
    while current < end_exclusive:
        month_end = min(following_month(current), end_exclusive)
        month_name = current.strftime("%Y-%m")
        train_rows = [row for row in rows if str(row[RACE_DATE]) < current.isoformat()]
        test_rows = [
            row for row in rows
            if current.isoformat() <= str(row[RACE_DATE]) < month_end.isoformat()
        ]
        print(f"{month_name} の5構成を学習・検証しています…", flush=True)
        scores, groups, labels = fit_predict(
            train_rows, test_rows, columns, variants, place_to_id
        )
        probabilities = {
            name: rank_probabilities(score_values, groups, temperatures[name])
            for name, score_values in scores.items()
        }
        metrics = {
            name: score_probabilities(probability, test_rows)
            for name, probability in probabilities.items()
        }
        for name, metric in metrics.items():
            add_result(records[name], metric, month_name)
        ranking = sorted(metrics.items(), key=lambda item: (item[1]["brier"], item[1]["winner_nll"]))
        print(
            "  " + " / ".join(
                f"{name}: {metric['top1_rate']*100:.3f}% B={metric['brier']:.6f}"
                for name, metric in ranking
            ),
            flush=True,
        )
        temperatures = {
            name: calibrate_temperature(score_values, labels, groups)
            for name, score_values in scores.items()
        }
        del train_rows, test_rows, scores, groups, labels, probabilities, metrics
        gc.collect()
        current = month_end

    results = {name: finalize(record) for name, record in records.items()}
    baseline = results["v4_baseline"]
    ranking = sorted(
        (
            {
                "name": name,
                **result,
                "brier_delta": result["brier"] - baseline["brier"],
                "nll_delta": result["winner_nll"] - baseline["winner_nll"],
                "hit_delta": result["hits"] - baseline["hits"],
            }
            for name, result in results.items()
        ),
        key=lambda item: (item["brier"], item["winner_nll"]),
    )
    report = {
        "configuration": {
            "start": args.start,
            "end_exclusive": args.end_exclusive,
            "history_start": args.history_start,
            "same_day_updates": "deferred until all races of the day are snapshotted",
            "win_learning_rates": {"player": 0.08, "motor": 0.06, "course": 0.02},
            "pair_learning_rates": {"player": 0.04, "motor": 0.03, "course": 0.01},
        },
        "ranking": ranking,
        "results": results,
    }
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
    print("\n【Rating特徴量アブレーション】")
    print("方式                         的中       Brier        ΔBrier      勝者NLL       ΔNLL")
    print("-" * 100)
    for item in ranking:
        print(
            f"{item['name']:<28} {item['hits']:>5}  {item['brier']:.9f}  "
            f"{item['brier_delta']:+.9f}  {item['winner_nll']:.9f}  {item['nll_delta']:+.9f}"
        )
    print(f"JSON: {args.output}")
    print("RESULT_JSON=" + json.dumps(ranking, ensure_ascii=False), flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
