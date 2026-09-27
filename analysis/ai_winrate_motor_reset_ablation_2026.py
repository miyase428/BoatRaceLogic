#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""DBで観測した場別モーター更新日にRatingを初期化する効果を検証する。"""

from __future__ import annotations

import argparse
import gc
import json
import sys
from collections import defaultdict
from datetime import date
from pathlib import Path

import numpy as np
import psycopg2

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "common"))
sys.path.insert(0, str(ROOT / "analysis"))

from db_config import load_db_config  # noqa: E402
from ai_winrate_v4_rating_ablation_2026 import (  # noqa: E402
    PAIR_FEATURES,
    RatingSystem,
    add_delta,
    fit_predict,
    load_metadata,
    pair_gradients,
)
from feature_ablation_tournament_2026 import (  # noqa: E402
    load_rows,
    variant_features,
)
from ranking_model_tournament_2026 import (  # noqa: E402
    add_result,
    blank_result,
    calibrate_temperature,
    finalize,
    following_month,
    previous_month,
    rank_probabilities,
    score_probabilities,
)
from train_ai_winrate import IS_WINNER, PLACE_CODE, RACE_CODE, RACE_DATE  # noqa: E402


RESET_PAIR_FEATURES = (
    "reset_pair_player_rating_relative",
    "reset_pair_motor_rating_relative",
    "reset_pair_combined_rating_relative",
    "reset_pair_rating_probability",
)

RESET_CANDIDATE_SQL = """
SELECT stadium_code, race_date
FROM boat_race.engine_specs
WHERE race_date >= %(start_date)s::date
  AND race_date < %(end_date)s::date
  AND motor_exacta_rate IS NOT NULL
GROUP BY stadium_code, race_date
HAVING COUNT(DISTINCT motor_number) >= 25
   AND AVG((motor_exacta_rate = 0)::int) >= 0.90
ORDER BY stadium_code, race_date
"""


def load_motor_reset_dates(
    start_date: str,
    end_date: str,
    max_episode_gap_days: int = 3,
) -> set[tuple[str, str]]:
    """連続する初回開催を1イベントにまとめ、最初の日だけ返す。"""
    with psycopg2.connect(**load_db_config()) as conn:
        with conn.cursor() as cursor:
            cursor.execute(
                RESET_CANDIDATE_SQL,
                {"start_date": start_date, "end_date": end_date},
            )
            rows = [(str(place), event_date) for place, event_date in cursor.fetchall()]

    result: set[tuple[str, str]] = set()
    previous_by_place: dict[str, date] = {}
    for place, event_date in rows:
        previous = previous_by_place.get(place)
        if previous is None or (event_date - previous).days > max_episode_gap_days:
            result.add((event_date.isoformat(), place))
        previous_by_place[place] = event_date
    return result


def clear_venue_motors(system: RatingSystem, venues: set[str]) -> None:
    if not venues:
        return
    system.motor = defaultdict(
        float,
        {
            key: value
            for key, value in system.motor.items()
            if key[0] not in venues
        },
    )


def append_pair_reset_features_with_state(
    rows: list[tuple],
    columns: dict[str, int],
    metadata_by_key: dict[tuple[str, int], tuple[str, str, int, int, int | None]],
    reset_dates: set[tuple[str, str]],
) -> tuple[list[tuple], dict[str, int], RatingSystem, RatingSystem]:
    normal_system = RatingSystem(0.04, 0.03, 0.01)
    reset_system = RatingSystem(0.04, 0.03, 0.01)
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
        reset_venues = {
            place for reset_date, place in reset_dates if reset_date == race_date
        }
        # 初回開催日のレース前に、その場の旧モーターRatingだけを破棄する。
        clear_venue_motors(reset_system, reset_venues)

        normal_player_delta: defaultdict[str, float] = defaultdict(float)
        normal_motor_delta: defaultdict[tuple[str, int], float] = defaultdict(float)
        normal_course_delta = np.zeros(7, dtype=np.float64)
        reset_player_delta: defaultdict[str, float] = defaultdict(float)
        reset_motor_delta: defaultdict[tuple[str, int], float] = defaultdict(float)
        reset_course_delta = np.zeros(7, dtype=np.float64)

        for race_rows in races_by_day[race_date]:
            race_code = str(race_rows[0][RACE_CODE])
            courses = [int(row[columns["course"]]) for row in race_rows]
            metadata = [metadata_by_key[(race_code, course)] for course in courses]
            normal_components = normal_system.components(metadata)
            reset_components = reset_system.components(metadata)
            for index, course in enumerate(courses):
                additions[(race_code, course)] = tuple(
                    float(component[index])
                    for component in (*normal_components, *reset_components)
                )

            add_delta(
                metadata,
                pair_gradients(normal_system, metadata),
                normal_player_delta,
                normal_motor_delta,
                normal_course_delta,
                courses,
            )
            add_delta(
                metadata,
                pair_gradients(reset_system, metadata),
                reset_player_delta,
                reset_motor_delta,
                reset_course_delta,
                courses,
            )

        normal_system.apply(
            normal_player_delta,
            normal_motor_delta,
            normal_course_delta,
        )
        reset_system.apply(
            reset_player_delta,
            reset_motor_delta,
            reset_course_delta,
        )

    feature_names = PAIR_FEATURES + RESET_PAIR_FEATURES
    next_columns = dict(columns)
    next_columns.update(
        {name: len(rows[0]) + index for index, name in enumerate(feature_names)}
    )
    enriched = [
        tuple(row) + additions[(str(row[RACE_CODE]), int(row[columns["course"]]))]
        for row in rows
    ]
    return enriched, next_columns, normal_system, reset_system


def append_pair_reset_features(
    rows: list[tuple],
    columns: dict[str, int],
    metadata_by_key: dict[tuple[str, int], tuple[str, str, int, int, int | None]],
    reset_dates: set[tuple[str, str]],
) -> tuple[list[tuple], dict[str, int]]:
    enriched, next_columns, _, _ = append_pair_reset_features_with_state(
        rows,
        columns,
        metadata_by_key,
        reset_dates,
    )
    return enriched, next_columns


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--start", default="2026-01-01")
    parser.add_argument("--end-exclusive", default="2026-09-23")
    parser.add_argument("--history-start", default="2025-01-01")
    parser.add_argument(
        "--output",
        type=Path,
        default=(
            ROOT
            / "analysis"
            / "output"
            / "ai_winrate_motor_reset_ablation_2026.json"
        ),
    )
    args = parser.parse_args()
    start = date.fromisoformat(args.start)
    end_exclusive = date.fromisoformat(args.end_exclusive)

    print("v4データと更新日を読み込んでいます…", flush=True)
    rows, columns = load_rows(args.history_start, args.end_exclusive)
    valid_keys = {
        (str(row[RACE_CODE]), int(row[columns["course"]])) for row in rows
    }
    metadata = load_metadata(args.history_start, args.end_exclusive, valid_keys)
    missing = valid_keys - metadata.keys()
    if missing:
        raise RuntimeError(f"Ratingメタデータ不足: {len(missing)}件")
    reset_dates = load_motor_reset_dates(args.history_start, args.end_exclusive)
    print(f"場別モーター更新イベント: {len(reset_dates)}件", flush=True)
    rows, columns = append_pair_reset_features(
        rows,
        columns,
        metadata,
        reset_dates,
    )
    del valid_keys, metadata
    gc.collect()

    v4_features = variant_features()["v3_all_features"]
    variants = {
        "v4_baseline": v4_features,
        "v4_plus_pair_rating": v4_features + PAIR_FEATURES,
        "v4_plus_reset_pair_rating": v4_features + RESET_PAIR_FEATURES,
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
        initial_train,
        initial_calibration,
        columns,
        variants,
        place_to_id,
    )
    temperatures = {
        name: calibrate_temperature(score, labels, groups)
        for name, score in scores.items()
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
        print(f"{month_name} の3構成を学習・検証しています…", flush=True)
        scores, groups, labels = fit_predict(
            train_rows,
            test_rows,
            columns,
            variants,
            place_to_id,
        )
        probabilities = {
            name: rank_probabilities(score, groups, temperatures[name])
            for name, score in scores.items()
        }
        metrics = {
            name: score_probabilities(probability, test_rows)
            for name, probability in probabilities.items()
        }
        for name, metric in metrics.items():
            add_result(records[name], metric, month_name)
        ranking = sorted(
            metrics.items(),
            key=lambda item: (item[1]["brier"], item[1]["winner_nll"]),
        )
        print(
            "  "
            + " / ".join(
                f"{name}: {metric['top1_rate']*100:.3f}% B={metric['brier']:.6f}"
                for name, metric in ranking
            ),
            flush=True,
        )
        temperatures = {
            name: calibrate_temperature(score, labels, groups)
            for name, score in scores.items()
        }
        del train_rows, test_rows, scores, groups, labels, probabilities, metrics
        gc.collect()
        current = month_end

    results = {name: finalize(record) for name, record in records.items()}
    current_pair = results["v4_plus_pair_rating"]
    ranking = sorted(
        (
            {
                "name": name,
                **result,
                "brier_delta_vs_pair": result["brier"] - current_pair["brier"],
                "nll_delta_vs_pair": (
                    result["winner_nll"] - current_pair["winner_nll"]
                ),
                "hit_delta_vs_pair": result["hits"] - current_pair["hits"],
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
            "reset_rule": (
                "clear only venue motor ratings before the first race day of a "
                "DB-observed >=25 motors and >=90% zero exacta-rate episode"
            ),
            "same_day_updates": (
                "deferred until all races of the day are snapshotted"
            ),
            "learning_rates": {"player": 0.04, "motor": 0.03, "course": 0.01},
            "reset_events": [
                {"race_date": reset_date, "place": place}
                for reset_date, place in sorted(reset_dates)
            ],
        },
        "ranking": ranking,
        "results": results,
    }
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(
        json.dumps(report, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )
    print("\n【場別モーター更新日リセット・アブレーション】")
    print("方式                             的中       Brier       勝者NLL")
    print("-" * 82)
    for item in ranking:
        print(
            f"{item['name']:<32} {item['hits']:>5}  "
            f"{item['brier']:.9f}  {item['winner_nll']:.9f}"
        )
    print(f"JSON: {args.output}")
    print("RESULT_JSON=" + json.dumps(ranking, ensure_ascii=False), flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
