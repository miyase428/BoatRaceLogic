#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""LightGBM LambdaRankを固定し、AI1着率の追加特徴量だけを比較する。"""

from __future__ import annotations

import argparse
import gc
import json
import sys
from collections import defaultdict, deque
from datetime import date
from pathlib import Path

import numpy as np
import psycopg2

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "common"))
sys.path.insert(0, str(ROOT / "forecast"))
sys.path.insert(0, str(ROOT / "analysis"))

from db_config import load_db_config  # noqa: E402
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
from train_ai_winrate import (  # noqa: E402
    IS_WINNER,
    PLACE_CODE,
    RACE_DATE,
    load_rows as load_baseline_rows,
)


DATASET_SQL = """
WITH base AS (
    SELECT
        re.race_code,
        rm.race_date,
        SUBSTRING(re.race_code, 9, 3) AS place_code,
        el.entry_course AS course,
        (rrd.rank = '1') AS is_winner,
        re.player_id::text AS player_id,
        re.motor_number,
        re.lane_number,
        el.exhibition_time,
        el.start_timing,
        el.lap_time,
        el.around_time,
        el.straight_time,
        rrd.start_timing AS actual_start_timing,
        ps.national_win_rate,
        ps.national_exacta_rate,
        ps.local_win_rate,
        ps.local_exacta_rate
    FROM boat_race.race_entry re
    JOIN boat_race.race_master rm
      ON rm.race_code = re.race_code
    JOIN boat_race.exhibition_live el
      ON el.race_code = re.race_code
     AND el.player_id = re.player_id
    JOIN boat_race.player_stats ps
      ON ps.race_code = re.race_code
     AND ps.player_id = re.player_id
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
       AND COUNT(DISTINCT course) = 6
       AND COUNT(lane_number) FILTER (WHERE lane_number BETWEEN 1 AND 6) = 6
       AND COUNT(DISTINCT lane_number) = 6
       AND COUNT(exhibition_time) = 6
       AND COUNT(start_timing) = 6
       AND COUNT(lap_time) = 6
       AND COUNT(around_time) = 6
       AND COUNT(straight_time) = 6
), first_stage AS (
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
        COUNT(*) OVER motor_window AS motor_count_50,
        AVG(b.actual_start_timing) OVER player_start_window AS actual_st_mean_50,
        STDDEV_POP(b.actual_start_timing) OVER player_start_window AS actual_st_std_50,
        AVG(b.actual_start_timing) OVER player_course_start_window AS course_actual_st_mean_30,
        STDDEV_POP(b.actual_start_timing) OVER player_course_start_window AS course_actual_st_std_30,
        COUNT(*) FILTER (WHERE b.actual_start_timing < 0)
            OVER player_start_window AS flying_start_count_50,
        AVG(b.is_winner::int) OVER venue_course_window AS venue_course_win_rate,
        COUNT(*) OVER venue_course_window AS venue_course_count,
        RANK() OVER (PARTITION BY b.race_code ORDER BY b.exhibition_time) AS exhibition_rank,
        b.exhibition_time - MIN(b.exhibition_time) OVER race_window AS exhibition_gap_best,
        MAX(b.exhibition_time) FILTER (WHERE b.lane_number = 1) OVER race_window
            - b.exhibition_time AS exhibition_vs_lane1
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
        ),
        player_start_window AS (
            PARTITION BY b.player_id
            ORDER BY b.race_code
            ROWS BETWEEN 50 PRECEDING AND 1 PRECEDING
        ),
        player_course_start_window AS (
            PARTITION BY b.player_id, b.course
            ORDER BY b.race_code
            ROWS BETWEEN 30 PRECEDING AND 1 PRECEDING
        ),
        venue_course_window AS (
            PARTITION BY b.place_code, b.course
            ORDER BY b.race_code
            ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING
        )
)
SELECT
    race_code, race_date, place_code, course, is_winner,
    exhibition_relative, st_relative, lap_relative, around_relative, straight_relative,
    player_win_rate_100, player_win_count_100,
    player_course_win_rate_50, player_course_count_50,
    motor_win_rate_50, motor_count_50,
    actual_st_mean_50, actual_st_std_50,
    course_actual_st_mean_30, course_actual_st_std_30, flying_start_count_50,
    lane_number,
    course - lane_number AS entry_course_change,
    (course < lane_number)::int AS front_move_flag,
    venue_course_win_rate, venue_course_count,
    exhibition_rank, exhibition_gap_best, exhibition_vs_lane1,
    national_win_rate, national_exacta_rate, local_win_rate, local_exacta_rate,
    motor_win_rate_50 - player_win_rate_100 AS motor_vs_player_50
FROM first_stage
JOIN valid_races USING (race_code)
ORDER BY race_code, course
"""

# 追加特徴量は複数のSQL窓を重ねると非常に遅いため、素材だけ取得して
# race_code順に一度走査し、対象レースより前の履歴だけから構築する。
RAW_ADDITIONAL_SQL = """
SELECT
    re.race_code,
    rm.race_date,
    SUBSTRING(re.race_code, 9, 3) AS place_code,
    el.entry_course AS course,
    re.player_id::text AS player_id,
    re.motor_number,
    re.lane_number,
    el.exhibition_time,
    rrd.start_timing AS actual_start_timing,
    ps.national_win_rate,
    ps.national_exacta_rate,
    ps.local_win_rate,
    ps.local_exacta_rate
FROM boat_race.race_entry re
JOIN boat_race.race_master rm
  ON rm.race_code = re.race_code
JOIN boat_race.exhibition_live el
  ON el.race_code = re.race_code
 AND el.player_id = re.player_id
JOIN boat_race.player_stats ps
  ON ps.race_code = re.race_code
 AND ps.player_id = re.player_id
LEFT JOIN boat_race.race_result_detail rrd
  ON rrd.race_code = re.race_code
 AND rrd.player_id = re.player_id
WHERE rm.race_date >= %(start_date)s::date
  AND rm.race_date < %(end_date)s::date
ORDER BY re.race_code, el.entry_course
"""

BASE_FEATURES = (
    "exhibition_relative",
    "st_relative",
    "lap_relative",
    "around_relative",
    "straight_relative",
    "player_win_rate_100",
    "player_win_count_100",
    "player_course_win_rate_50",
    "player_course_count_50",
    "motor_win_rate_50",
    "motor_count_50",
)

FEATURE_GROUPS = {
    "start_history": (
        "actual_st_mean_50",
        "actual_st_std_50",
        "course_actual_st_mean_30",
        "course_actual_st_std_30",
        "flying_start_count_50",
    ),
    "entry_change": ("lane_number", "entry_course_change", "front_move_flag"),
    "venue_course_prior": ("venue_course_win_rate", "venue_course_count"),
    "exhibition_gaps": ("exhibition_rank", "exhibition_gap_best", "exhibition_vs_lane1"),
    "official_player_stats": (
        "national_win_rate",
        "national_exacta_rate",
        "local_win_rate",
        "local_exacta_rate",
    ),
    "motor_relative": ("motor_vs_player_50",),
}


def history_mean(values: deque[float]) -> float:
    return float(np.mean(values)) if values else float("nan")


def history_std(values: deque[float]) -> float:
    return float(np.std(values)) if values else float("nan")


def load_rows(start_date: str, end_date: str) -> tuple[list[tuple], dict[str, int]]:
    baseline_rows = load_baseline_rows(start_date, end_date)
    valid_keys = {(str(row[0]), int(row[3])) for row in baseline_rows}

    with psycopg2.connect(**load_db_config()) as conn:
        with conn.cursor() as cur:
            cur.execute(
                RAW_ADDITIONAL_SQL,
                {"start_date": start_date, "end_date": end_date},
            )
            raw_columns = {item.name: index for index, item in enumerate(cur.description)}
            raw_by_key = {
                (str(row[raw_columns["race_code"]]), int(row[raw_columns["course"]])): row
                for row in cur.fetchall()
                if (str(row[raw_columns["race_code"]]), int(row[raw_columns["course"]]))
                in valid_keys
            }

    base_columns = {
        name: index
        for index, name in enumerate(
            ("race_code", "race_date", "place_code", "course", "is_winner")
            + BASE_FEATURES
        )
    }
    extra_names = (
        "actual_st_mean_50",
        "actual_st_std_50",
        "course_actual_st_mean_30",
        "course_actual_st_std_30",
        "flying_start_count_50",
        "lane_number",
        "entry_course_change",
        "front_move_flag",
        "venue_course_win_rate",
        "venue_course_count",
        "exhibition_rank",
        "exhibition_gap_best",
        "exhibition_vs_lane1",
        "national_win_rate",
        "national_exacta_rate",
        "local_win_rate",
        "local_exacta_rate",
        "motor_vs_player_50",
    )
    columns = dict(base_columns)
    columns.update({name: len(base_columns) + index for index, name in enumerate(extra_names)})

    player_st: defaultdict[str, deque[float]] = defaultdict(lambda: deque(maxlen=50))
    player_course_st: defaultdict[tuple[str, int], deque[float]] = defaultdict(
        lambda: deque(maxlen=30)
    )
    venue_course: defaultdict[tuple[str, int], list[int]] = defaultdict(lambda: [0, 0])
    enriched: list[tuple] = []

    position = 0
    while position < len(baseline_rows):
        race_code = str(baseline_rows[position][0])
        end = position
        while end < len(baseline_rows) and str(baseline_rows[end][0]) == race_code:
            end += 1
        race_rows = baseline_rows[position:end]
        raw_rows = [raw_by_key[(race_code, int(row[3]))] for row in race_rows]
        exhibitions = [float(row[raw_columns["exhibition_time"]]) for row in raw_rows]
        best_exhibition = min(exhibitions)
        lane1_exhibition = next(
            float(row[raw_columns["exhibition_time"]])
            for row in raw_rows
            if int(row[raw_columns["lane_number"]]) == 1
        )

        for base_row, raw_row, exhibition in zip(race_rows, raw_rows, exhibitions):
            player_id = str(raw_row[raw_columns["player_id"]])
            course = int(base_row[3])
            lane = int(raw_row[raw_columns["lane_number"]])
            place = str(base_row[PLACE_CODE])
            venue_record = venue_course[(place, course)]
            venue_rate = (
                venue_record[0] / venue_record[1]
                if venue_record[1]
                else float("nan")
            )
            player_values = player_st[player_id]
            course_values = player_course_st[(player_id, course)]
            motor_rate = base_row[base_columns["motor_win_rate_50"]]
            player_rate = base_row[base_columns["player_win_rate_100"]]
            motor_relative = (
                float(motor_rate) - float(player_rate)
                if motor_rate is not None and player_rate is not None
                else float("nan")
            )
            rank = 1 + sum(value < exhibition for value in exhibitions)
            extras = (
                history_mean(player_values),
                history_std(player_values),
                history_mean(course_values),
                history_std(course_values),
                sum(value < 0 for value in player_values),
                lane,
                course - lane,
                int(course < lane),
                venue_rate,
                venue_record[1],
                rank,
                exhibition - best_exhibition,
                lane1_exhibition - exhibition,
                raw_row[raw_columns["national_win_rate"]],
                raw_row[raw_columns["national_exacta_rate"]],
                raw_row[raw_columns["local_win_rate"]],
                raw_row[raw_columns["local_exacta_rate"]],
                motor_relative,
            )
            enriched.append(tuple(base_row) + extras)

        # 同一レースの結果が互いの事前履歴に入らないよう、計算後に更新する。
        for base_row, raw_row in zip(race_rows, raw_rows):
            player_id = str(raw_row[raw_columns["player_id"]])
            course = int(base_row[3])
            actual_st = raw_row[raw_columns["actual_start_timing"]]
            if actual_st is not None:
                value = float(actual_st)
                player_st[player_id].append(value)
                player_course_st[(player_id, course)].append(value)
            venue_record = venue_course[(str(base_row[PLACE_CODE]), course)]
            venue_record[0] += int(bool(base_row[IS_WINNER]))
            venue_record[1] += 1
        position = end

    return enriched, columns


def variant_features() -> dict[str, tuple[str, ...]]:
    variants = {"v3_baseline": BASE_FEATURES}
    for name, additions in FEATURE_GROUPS.items():
        variants[f"v3_plus_{name}"] = BASE_FEATURES + additions
    variants["v3_all_features"] = BASE_FEATURES + tuple(
        feature
        for additions in FEATURE_GROUPS.values()
        for feature in additions
    )
    return variants


def build_matrix(
    rows: list[tuple],
    columns: dict[str, int],
    features: tuple[str, ...],
    place_to_id: dict[str, int],
) -> np.ndarray:
    matrix = np.empty((len(rows), len(features) + 2), dtype=np.float64)
    for row_index, row in enumerate(rows):
        matrix[row_index, 0] = place_to_id.get(str(row[PLACE_CODE]), 0)
        matrix[row_index, 1] = float(row[columns["course"]])
        for feature_index, feature in enumerate(features, start=2):
            value = row[columns[feature]]
            matrix[row_index, feature_index] = (
                float(value) if value is not None else float("nan")
            )
    return matrix


def fit_predict(
    train_rows: list[tuple],
    predict_rows: list[tuple],
    columns: dict[str, int],
    variants: dict[str, tuple[str, ...]],
    place_to_id: dict[str, int],
) -> tuple[dict[str, np.ndarray], list[np.ndarray], np.ndarray]:
    y_train = np.asarray([bool(row[IS_WINNER]) for row in train_rows], dtype=np.int8)
    _, _, train_group_sizes = race_groups(train_rows)
    _, predict_groups, _ = race_groups(predict_rows)
    y_predict = np.asarray([bool(row[IS_WINNER]) for row in predict_rows], dtype=np.int8)
    scores: dict[str, np.ndarray] = {}
    for name, features in variants.items():
        print(f"  {name} を学習しています…", flush=True)
        x_train = build_matrix(train_rows, columns, features, place_to_id)
        x_predict = build_matrix(predict_rows, columns, features, place_to_id)
        model = make_lightgbm_ranker()
        model.fit(
            x_train,
            y_train,
            group=train_group_sizes,
            categorical_feature=[0, 1],
        )
        scores[name] = model.predict(x_predict)
        del x_train, x_predict, model
    gc.collect()
    return scores, predict_groups, y_predict


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--start", default="2026-01-01")
    parser.add_argument("--end-exclusive", default="2026-09-23")
    parser.add_argument("--history-start", default="2025-01-01")
    args = parser.parse_args()
    start = date.fromisoformat(args.start)
    end_exclusive = date.fromisoformat(args.end_exclusive)
    variants = variant_features()

    print("特徴量を時系列で構築しています…", flush=True)
    rows, columns = load_rows(args.history_start, args.end_exclusive)
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
    print(f"{calibration_start:%Y-%m} で初期確率補正を作成しています…", flush=True)
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
        if not train_rows or not test_rows:
            break
        print(f"{month_name} の{len(variants)}構成を比較しています…", flush=True)
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
            key=lambda item: (-item[1]["hits"], item[1]["brier"]),
        )
        best_name, best = ranking[0]
        baseline = metrics["v3_baseline"]
        print(
            f"{month_name} {best['races']}R  "
            f"基準 {baseline['top1_rate'] * 100:.3f}%  "
            f"首位 {best_name} {best['top1_rate'] * 100:.3f}%",
            flush=True,
        )
        temperatures = {
            name: calibrate_temperature(score, labels, groups)
            for name, score in scores.items()
        }
        del train_rows, test_rows, scores, groups, labels, probabilities, metrics
        gc.collect()
        current = month_end

    final = {name: finalize(record) for name, record in records.items()}
    ranking = sorted(
        (
            {
                "name": name,
                "feature_count": len(variants[name]) + 2,
                "races": result["races"],
                "hits": result["hits"],
                "top1_rate": result["top1_rate"],
                "brier": result["brier"],
                "winner_nll": result["winner_nll"],
            }
            for name, result in final.items()
        ),
        key=lambda item: (-item["hits"], item["brier"]),
    )
    print("RANKING_JSON=" + json.dumps(ranking, ensure_ascii=False), flush=True)
    print("RESULT_JSON=" + json.dumps(final, ensure_ascii=False), flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
