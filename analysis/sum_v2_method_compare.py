#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""展示SUM理論 v2: 現行SUMと相対化候補を時系列で比較する。

本番ロジック・DBは変更しない読み取り専用の検証。

比較対象:
  EX_TOTAL_ONLY       展示進入リマップ + 現行EX_TOTAL（SUMなし）
  CURRENT_SUM_FIXED   現行の場×C×SUM帯の勝率差、gamma=2.0固定
  CURRENT_SUM_TUNED   同上、直前31日だけでgammaを選択
  POINT_SUM           場別3指標を既存1～5点へ変換して合成
  RANK_SUM            場別3指標のレース内順位を合成
  RELATIVE_Z          場別3指標をレース内標準化して合成

各候補の補正強度は評価期間の直前だけで選び、未来情報を使わない。
デフォルトでは3つの月次前方期間を連続評価する。

Usage:
  python3 analysis/sum_v2_method_compare.py
  python3 analysis/sum_v2_method_compare.py 2026-06-15 2026-09-14
"""

from __future__ import annotations

import math
import calendar
import sys
from collections import defaultdict, deque
from dataclasses import dataclass
from datetime import date, datetime, timedelta
from pathlib import Path

import numpy as np

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))

import base_winrate_sum_compare as current  # noqa: E402
from base_winrate_exhibition_compare import (  # noqa: E402
    actual_course,
    as_float,
    as_int,
    base_prob,
    calc_around_score,
    calc_ex_score,
    calc_lap_score,
    calc_straight_score,
    normalize,
    prune_venue_ex_history,
    valid_course,
)
from slit_validate_v2 import connect_db  # noqa: E402


EX_TOTAL_BETA = current.EX_TOTAL_BETA
CURRENT_SUM_GAMMA = 2.0
TRAIN_DAYS = 31
GAMMA_GRID = (
    -2.0, -1.5, -1.0, -0.75, -0.50, -0.30, -0.20, -0.15, -0.10, -0.05,
    0.0,
    0.05, 0.10, 0.15, 0.20, 0.30, 0.50, 0.75, 1.0, 1.5, 2.0,
)
METHODS = ("CURRENT_SUM_TUNED", "POINT_SUM", "RANK_SUM", "RELATIVE_Z")
INCREMENTAL_METHOD = "CURRENT_PLUS_RELATIVE_Z"
NO_STRAIGHT_PLACES = {"AMG", "TKY", "SME"}


@dataclass
class Boat:
    lane: int
    rank: int
    y: int
    base_probability: float
    scores: dict[str, float]


@dataclass
class Race:
    race_code: str
    race_date: date
    place_code: str
    boats: list[Boat]


def parse_date(value: str) -> date:
    return datetime.strptime(value, "%Y-%m-%d").date()


def parse_args() -> tuple[date, date]:
    if len(sys.argv) == 1:
        return date(2026, 6, 15), date(2026, 9, 14)
    if len(sys.argv) != 3:
        raise SystemExit("Usage: python3 analysis/sum_v2_method_compare.py [YYYY-MM-DD YYYY-MM-DD]")
    start = parse_date(sys.argv[1])
    end = parse_date(sys.argv[2])
    if start > end:
        raise RuntimeError("開始日は終了日以前にしてください")
    return start, end


def average_ranks(values: list[float]) -> list[float]:
    """小さい値を1位とし、同値は平均順位にする。"""
    indexed = sorted(enumerate(values), key=lambda item: (item[1], item[0]))
    ranks = [0.0] * len(values)
    i = 0
    while i < len(indexed):
        j = i + 1
        while j < len(indexed) and abs(indexed[j][1] - indexed[i][1]) < 1.0e-12:
            j += 1
        average = ((i + 1) + j) / 2.0
        for k in range(i, j):
            ranks[indexed[k][0]] = average
        i = j
    return ranks


def z_advantages(values: list[float]) -> list[float]:
    """速い（値が小さい）ほど正になる、レース内の標準化差。"""
    mean = sum(values) / len(values)
    variance = sum((value - mean) ** 2 for value in values) / len(values)
    sd = math.sqrt(variance)
    if sd < 1.0e-9:
        return [0.0] * len(values)
    return [max(-2.5, min(2.5, (mean - value) / sd)) for value in values]


def build_prepared_v2(
    race_rows: list[tuple],
    venue_avg_ex: float | None,
    feature_cols: list[str],
    allow_no_straight: bool,
) -> list[dict] | None:
    if venue_avg_ex is None or venue_avg_ex <= 0:
        return None
    prepared = []
    for row in race_rows:
        lane, player_id, rank, result_course, ex_course, ex_time, ex_st, lap, around, straight = row
        lane = valid_course(lane)
        ex_course = valid_course(ex_course)
        ex_time = as_float(ex_time)
        ex_st = as_float(ex_st)
        lap = as_float(lap)
        around = as_float(around)
        straight = as_float(straight)
        if (lane is None or ex_course is None or ex_time is None or ex_st is None
                or lap is None or around is None or (straight is None and not allow_no_straight)):
            return None
        prepared.append({
            "lane": lane,
            "player_id": str(player_id or "").strip(),
            "rank": as_int(rank),
            "result_course": result_course,
            "ex_course": ex_course,
            "ex_time": ex_time,
            "ex_st": ex_st,
            "lap": lap,
            "around": around,
            "straight": straight,
        })
    if len(prepared) != 6:
        return None
    if {row["lane"] for row in prepared} != set(range(1, 7)):
        return None
    if {row["ex_course"] for row in prepared} != set(range(1, 7)):
        return None

    avg_lap = sum(row["lap"] for row in prepared) / 6.0
    avg_around = sum(row["around"] for row in prepared) / 6.0
    straight_values = [row["straight"] for row in prepared if row["straight"] is not None]
    avg_straight = sum(straight_values) / 6.0 if len(straight_values) == 6 else None
    for row in prepared:
        ex_score = calc_ex_score(row["ex_time"] - venue_avg_ex)
        lap_score = calc_lap_score(row["lap"], avg_lap)
        around_score = calc_around_score(row["around"], avg_around)
        ex_total = float(ex_score + lap_score + around_score)
        if row["straight"] is not None and avg_straight is not None:
            ex_total += float(calc_straight_score(row["straight"], avg_straight))
        row["ex_total"] = ex_total

        values = {
            "exhibition_time": row["ex_time"],
            "lap_time": row["lap"],
            "around_time": row["around"],
            "straight_time": row["straight"],
        }
        if any(values[name] is None for name in feature_cols):
            return None
        row["sum_raw"] = sum(float(values[name]) for name in feature_cols)

    race_mean = sum(row["sum_raw"] for row in prepared) / 6.0
    for row in prepared:
        row["sum_diff"] = row["sum_raw"] - race_mean
        row["sum_interval"] = current.sum_interval_label(row["sum_diff"])
    return prepared


def candidate_scores(prepared: list[dict], feature_cols: list[str], venue_avg_ex: float) -> dict[int, dict[str, float]]:
    staged = sorted(prepared, key=lambda row: row["lane"])
    averages = {
        "lap_time": sum(row["lap"] for row in staged) / 6.0,
        "around_time": sum(row["around"] for row in staged) / 6.0,
    }
    if "straight_time" in feature_cols:
        averages["straight_time"] = sum(row["straight"] for row in staged) / 6.0

    all_values = {
        "exhibition_time": [row["ex_time"] for row in staged],
        "lap_time": [row["lap"] for row in staged],
        "around_time": [row["around"] for row in staged],
        "straight_time": [row["straight"] for row in staged],
    }
    values_by_feature = {name: all_values[name] for name in feature_cols}
    ranks_by_feature = {name: average_ranks(values) for name, values in values_by_feature.items()}
    z_by_feature = {name: z_advantages(values) for name, values in values_by_feature.items()}

    result: dict[int, dict[str, float]] = {}
    for index, row in enumerate(staged):
        point_parts = {}
        for name in feature_cols:
            if name == "exhibition_time":
                point_parts[name] = calc_ex_score(row["ex_time"] - venue_avg_ex)
            elif name == "lap_time":
                point_parts[name] = calc_lap_score(row["lap"], averages["lap_time"])
            elif name == "around_time":
                point_parts[name] = calc_around_score(row["around"], averages["around_time"])
            elif name == "straight_time":
                point_parts[name] = calc_straight_score(row["straight"], averages["straight_time"])
            else:
                raise RuntimeError(f"未対応SUM feature: {name}")
        result[row["lane"]] = {
            "POINT_SUM": sum(float(point_parts[name]) for name in feature_cols) / len(feature_cols),
            # 順位1位を+1、6位を-1付近へ揃え、場ごとの指標数差をなくす。
            "RANK_SUM": sum((3.5 - ranks_by_feature[name][index]) / 2.5 for name in feature_cols) / len(feature_cols),
            "RELATIVE_Z": sum(z_by_feature[name][index] for name in feature_cols) / len(feature_cols),
        }
    return result


def load_races(load_start: date, eval_end: date) -> tuple[list[Race], dict[str, int]]:
    features = current.load_sum_features()

    venue_n = defaultdict(lambda: {course: 0 for course in range(1, 7)})
    venue_w = defaultdict(lambda: {course: 0 for course in range(1, 7)})
    global_n = {course: 0 for course in range(1, 7)}
    global_w = {course: 0 for course in range(1, 7)}
    player_hist = defaultdict(lambda: deque(maxlen=100))
    venue_ex_hist = defaultdict(deque)
    venue_ex_sum = defaultdict(float)

    sum_course_n = defaultdict(lambda: {course: 0 for course in range(1, 7)})
    sum_course_w = defaultdict(lambda: {course: 0 for course in range(1, 7)})
    sum_interval_n = defaultdict(
        lambda: {
            course: {label: 0 for label, _, _ in current.SUM_INTERVALS}
            for course in range(1, 7)
        }
    )
    sum_interval_w = defaultdict(
        lambda: {
            course: {label: 0 for label, _, _ in current.SUM_INTERVALS}
            for course in range(1, 7)
        }
    )

    races: list[Race] = []
    skipped: dict[str, int] = defaultdict(int)
    sql = """
        SELECT
            rm.race_date,
            re.race_code,
            re.lane_number,
            re.player_id::text,
            rrd.rank,
            rrd.entry_course AS result_course,
            el.entry_course AS exhibition_course,
            el.exhibition_time,
            el.start_timing,
            el.lap_time,
            el.around_time,
            el.straight_time
        FROM boat_race.race_entry re
        JOIN boat_race.race_master rm ON rm.race_code = re.race_code
        LEFT JOIN boat_race.race_result_detail rrd
          ON rrd.race_code = re.race_code AND rrd.player_id = re.player_id
        LEFT JOIN LATERAL (
            SELECT entry_course, exhibition_time, start_timing,
                   lap_time, around_time, straight_time
            FROM boat_race.exhibition_live x
            WHERE x.race_code = re.race_code AND x.player_id = re.player_id
            LIMIT 1
        ) el ON TRUE
        WHERE rm.race_date <= %s::date
        ORDER BY rm.race_date, re.race_code, re.lane_number
    """

    with connect_db() as conn:
        cursor = conn.cursor(name="sum_v2_method_stream")
        cursor.itersize = 10000
        cursor.execute(sql, (eval_end.isoformat(),))
        current_code = None
        current_date = None
        rows: list[tuple] = []

        def process_race(race_date: date, race_code: str, race_rows: list[tuple]) -> None:
            if not race_rows:
                return
            place_code = race_code[8:11] if len(race_code) >= 11 else "???"
            feature_cols = features.get(place_code)
            if not feature_cols:
                skipped["feature_config_missing"] += 1
                return

            prune_venue_ex_history(place_code, race_date, venue_ex_hist, venue_ex_sum)
            history_n = len(venue_ex_hist[place_code])
            venue_avg_ex = venue_ex_sum[place_code] / history_n if history_n > 0 else None
            winners = [row for row in race_rows if as_int(row[2]) == 1]
            unique_winner = len(winners) == 1
            winner_lane = valid_course(winners[0][0]) if unique_winner else None
            prepared = build_prepared_v2(
                race_rows,
                venue_avg_ex,
                feature_cols,
                place_code in NO_STRAIGHT_PLACES,
            )

            if race_date >= load_start:
                if not unique_winner or winner_lane is None:
                    skipped["winner_not_unique"] += 1
                elif prepared is None or venue_avg_ex is None:
                    skipped["exhibition_incomplete"] += 1
                else:
                    staged = sorted(prepared, key=lambda row: row["lane"])
                    base_raw = [
                        base_prob(
                            player_hist[row["player_id"]],
                            place_code,
                            row["ex_course"],
                            venue_n,
                            venue_w,
                            global_n,
                            global_w,
                        )
                        for row in staged
                    ]
                    remapped = normalize(base_raw)
                    if remapped is None:
                        skipped["base_normalize_failed"] += 1
                    else:
                        ex_probs = current.apply_centered_score(
                            remapped,
                            [row["ex_total"] for row in staged],
                            EX_TOTAL_BETA,
                        )
                        alternatives = candidate_scores(staged, feature_cols, venue_avg_ex)
                        if ex_probs is None:
                            skipped["ex_normalize_failed"] += 1
                        else:
                            boats: list[Boat] = []
                            for index, row in enumerate(staged):
                                production, _, _ = current.sum_scores_for_boat(
                                    place_code,
                                    row["ex_course"],
                                    row["sum_interval"],
                                    sum_course_n,
                                    sum_course_w,
                                    sum_interval_n,
                                    sum_interval_w,
                                )
                                scores = dict(alternatives[row["lane"]])
                                scores["CURRENT_SUM_TUNED"] = production["SUM_RAW"]
                                boats.append(Boat(
                                    lane=row["lane"],
                                    rank=int(row["rank"] or 99),
                                    y=1 if row["lane"] == winner_lane else 0,
                                    base_probability=ex_probs[index],
                                    scores=scores,
                                ))
                            races.append(Race(race_code, race_date, place_code, boats))

            if unique_winner:
                winner = winners[0]
                winner_course, _ = actual_course(winner[3], winner[4], winner[0])
                if winner_course is not None:
                    for course in range(1, 7):
                        venue_n[place_code][course] += 1
                        global_n[course] += 1
                    venue_w[place_code][winner_course] += 1
                    global_w[winner_course] += 1

            for row in race_rows:
                lane, player_id, rank, result_course, exhibition_course, ex_time, *_ = row
                player_id = str(player_id or "").strip()
                if player_id:
                    course, _ = actual_course(result_course, exhibition_course, lane)
                    if course is not None:
                        player_hist[player_id].append({
                            "place": place_code,
                            "course": course,
                            "win": 1 if as_int(rank) == 1 else 0,
                        })
                exhibition = as_float(ex_time)
                if exhibition is not None and exhibition > 0:
                    venue_ex_hist[place_code].append((race_date, exhibition))
                    venue_ex_sum[place_code] += exhibition

            if unique_winner and prepared is not None:
                for row in prepared:
                    course = row["ex_course"]
                    label = row["sum_interval"]
                    sum_course_n[place_code][course] += 1
                    sum_interval_n[place_code][course][label] += 1
                    if row["rank"] == 1:
                        sum_course_w[place_code][course] += 1
                        sum_interval_w[place_code][course][label] += 1

        for record in cursor:
            race_date, race_code, *boat_row = record
            race_code = str(race_code)
            if current_code is None:
                current_code = race_code
                current_date = race_date
            if race_code != current_code:
                process_race(current_date, current_code, rows)
                rows = []
                current_code = race_code
                current_date = race_date
            rows.append(tuple(boat_row))
        if current_code is not None:
            process_race(current_date, current_code, rows)
        cursor.close()

    return races, dict(skipped)


def race_prediction(race: Race, method: str | None, gamma: float) -> list[float]:
    boats = sorted(race.boats, key=lambda boat: boat.lane)
    base = [boat.base_probability for boat in boats]
    if method is None:
        return base
    if method == INCREMENTAL_METHOD:
        current_scores = [boat.scores["CURRENT_SUM_TUNED"] for boat in boats]
        current_probs = current.apply_centered_score(base, current_scores, CURRENT_SUM_GAMMA)
        if current_probs is None:
            raise RuntimeError(f"現行SUM確率正規化に失敗しました: {race.race_code}")
        relative_scores = [boat.scores["RELATIVE_Z"] for boat in boats]
        probs = current.apply_centered_score(current_probs, relative_scores, gamma)
        if probs is None:
            raise RuntimeError(f"相対Z追加の確率正規化に失敗しました: {race.race_code}")
        return probs
    scores = [boat.scores[method] for boat in boats]
    probs = current.apply_centered_score(base, scores, gamma)
    if probs is None:
        raise RuntimeError(f"確率正規化に失敗しました: {race.race_code} {method}")
    return probs


def evaluate(races: list[Race], method: str | None = None, gamma: float = 0.0) -> dict:
    if not races:
        raise RuntimeError("評価レースが0件です")
    brier_sum = 0.0
    nll_sum = 0.0
    top1 = 0
    score_first = 0
    score_top2 = 0
    score_top3 = 0
    per_race_brier = []
    per_race_nll = []
    by_place = defaultdict(lambda: [0, 0.0, 0.0])

    for race in races:
        boats = sorted(race.boats, key=lambda boat: boat.lane)
        probs = race_prediction(race, method, gamma)
        race_brier = 0.0
        race_nll = 0.0
        for probability, boat in zip(probs, boats):
            clipped = min(max(probability, 1.0e-9), 1.0 - 1.0e-9)
            race_brier += (probability - boat.y) ** 2
            race_nll += -(boat.y * math.log(clipped) + (1 - boat.y) * math.log(1 - clipped))
        race_brier /= 6.0
        race_nll /= 6.0
        brier_sum += race_brier
        nll_sum += race_nll
        per_race_brier.append(race_brier)
        per_race_nll.append(race_nll)
        by_place[race.place_code][0] += 1
        by_place[race.place_code][1] += race_brier
        by_place[race.place_code][2] += race_nll

        best_probability = max(range(6), key=lambda index: (probs[index], -boats[index].lane))
        if boats[best_probability].y == 1:
            top1 += 1
        if method is not None:
            score_method = "RELATIVE_Z" if method == INCREMENTAL_METHOD else method
            best_score = max(range(6), key=lambda index: (boats[index].scores[score_method], -boats[index].lane))
            actual_rank = boats[best_score].rank
            score_first += int(actual_rank == 1)
            score_top2 += int(actual_rank <= 2)
            score_top3 += int(actual_rank <= 3)

    n = len(races)
    return {
        "races": n,
        "brier": brier_sum / n,
        "nll": nll_sum / n,
        "top1": top1 / n,
        "score_first": score_first / n if method is not None else None,
        "score_top2": score_top2 / n if method is not None else None,
        "score_top3": score_top3 / n if method is not None else None,
        "per_race_brier": per_race_brier,
        "per_race_nll": per_race_nll,
        "by_place": by_place,
    }


def tune_gamma(train_races: list[Race], method: str) -> tuple[float, dict]:
    best = None
    for gamma in GAMMA_GRID:
        metrics = evaluate(train_races, method, gamma)
        key = (metrics["brier"], metrics["nll"], abs(gamma), gamma)
        if best is None or key < best[0]:
            best = (key, gamma, metrics)
    if best is None:
        raise RuntimeError(f"gammaを選択できません: {method}")
    return best[1], best[2]


def month_windows(start: date, end: date) -> list[tuple[date, date]]:
    windows = []
    cursor = start
    while cursor <= end:
        if cursor.month == 12:
            next_year, next_month = cursor.year + 1, 1
        else:
            next_year, next_month = cursor.year, cursor.month + 1
        next_day = min(cursor.day, calendar.monthrange(next_year, next_month)[1])
        candidate_end = date(next_year, next_month, next_day)
        window_end = min(end, candidate_end - timedelta(days=1))
        windows.append((cursor, window_end))
        cursor = window_end + timedelta(days=1)
    return windows


def bootstrap_delta(deltas: list[float], seed: int = 20260926, samples: int = 5000) -> dict:
    if not deltas:
        return {"mean": 0.0, "low": 0.0, "high": 0.0, "improve_probability": 0.0}
    values = np.asarray(deltas, dtype=np.float64)
    rng = np.random.default_rng(seed)
    n = len(values)
    means = []
    # 大標本でもメモリを使い過ぎないよう、250反復ずつ生成する。
    for offset in range(0, samples, 250):
        batch = min(250, samples - offset)
        indices = rng.integers(0, n, size=(batch, n))
        means.extend(values[indices].mean(axis=1).tolist())
    means.sort()
    return {
        "mean": float(values.mean()),
        "low": means[int(samples * 0.025)],
        "high": means[min(samples - 1, int(samples * 0.975))],
        "improve_probability": sum(value < 0.0 for value in means) / samples,
    }


def percent(value: float | None) -> str:
    return "-" if value is None else f"{value * 100:.2f}%"


def main() -> None:
    eval_start, eval_end = parse_args()
    load_start = eval_start - timedelta(days=TRAIN_DAYS)
    print("展示SUM理論 v2の比較データを構築しています...")
    races, skipped = load_races(load_start, eval_end)
    windows = month_windows(eval_start, eval_end)
    if not races:
        raise RuntimeError("比較できるレースがありません")

    combined = defaultdict(lambda: {"base_brier": [], "base_nll": [], "candidate_brier": [], "candidate_nll": [], "places": []})
    incremental = {"base_brier": [], "base_nll": [], "candidate_brier": [], "candidate_nll": []}
    incremental_rows = []
    period_rows = []

    for window_index, (window_start, window_end) in enumerate(windows, start=1):
        train_start = window_start - timedelta(days=TRAIN_DAYS)
        train_end = window_start - timedelta(days=1)
        train = [race for race in races if train_start <= race.race_date <= train_end]
        test = [race for race in races if window_start <= race.race_date <= window_end]
        if not train or not test:
            continue
        baseline = evaluate(test)
        fixed = evaluate(test, "CURRENT_SUM_TUNED", CURRENT_SUM_GAMMA)
        incremental_gamma, _ = tune_gamma(train, INCREMENTAL_METHOD)
        incremental_metrics = evaluate(test, INCREMENTAL_METHOD, incremental_gamma)
        incremental_rows.append((window_index, window_start, window_end, incremental_gamma, fixed, incremental_metrics))
        incremental["base_brier"].extend(fixed["per_race_brier"])
        incremental["base_nll"].extend(fixed["per_race_nll"])
        incremental["candidate_brier"].extend(incremental_metrics["per_race_brier"])
        incremental["candidate_nll"].extend(incremental_metrics["per_race_nll"])
        period_rows.append((window_index, window_start, window_end, "EX_TOTAL_ONLY", 0.0, baseline))
        period_rows.append((window_index, window_start, window_end, "CURRENT_SUM_FIXED", CURRENT_SUM_GAMMA, fixed))

        for method in METHODS:
            gamma, _ = tune_gamma(train, method)
            metrics = evaluate(test, method, gamma)
            period_rows.append((window_index, window_start, window_end, method, gamma, metrics))
            combined[method]["base_brier"].extend(baseline["per_race_brier"])
            combined[method]["base_nll"].extend(baseline["per_race_nll"])
            combined[method]["candidate_brier"].extend(metrics["per_race_brier"])
            combined[method]["candidate_nll"].extend(metrics["per_race_nll"])
            combined[method]["places"].extend(race.place_code for race in test)

        combined["CURRENT_SUM_FIXED"]["base_brier"].extend(baseline["per_race_brier"])
        combined["CURRENT_SUM_FIXED"]["base_nll"].extend(baseline["per_race_nll"])
        combined["CURRENT_SUM_FIXED"]["candidate_brier"].extend(fixed["per_race_brier"])
        combined["CURRENT_SUM_FIXED"]["candidate_nll"].extend(fixed["per_race_nll"])
        combined["CURRENT_SUM_FIXED"]["places"].extend(race.place_code for race in test)

    print("=" * 138)
    print("展示SUM理論 v2：現行SUM・点数・順位・相対標準化の時系列比較")
    print("=" * 138)
    print(f"評価期間     : {eval_start} ～ {eval_end}")
    print(f"gamma学習    : 各評価期間の直前{TRAIN_DAYS}日だけ")
    print("基準確率     : 展示進入リマップ + EX_TOTAL beta=0.100")
    print("場別3指標    : theories/new_sam/features.json（現行設定を固定）")
    print("本番変更     : なし")

    print("\n【期間別結果】")
    print("期間   評価日                 方式                 gamma    N       Brier      vs基準       NLL      Top1   指標1着/2連/3連")
    print("-" * 138)
    grouped = defaultdict(list)
    for row in period_rows:
        grouped[row[0]].append(row)
    for window_index in sorted(grouped):
        rows = grouped[window_index]
        baseline = next(row[5] for row in rows if row[3] == "EX_TOTAL_ONLY")
        for _, start, end, method, gamma, metrics in rows:
            delta = metrics["brier"] - baseline["brier"]
            score_text = "-"
            if metrics["score_first"] is not None:
                score_text = f"{percent(metrics['score_first'])}/{percent(metrics['score_top2'])}/{percent(metrics['score_top3'])}"
            print(
                f"P{window_index:<2}    {start}～{end}  {method:<21} {gamma:+6.2f} "
                f"{metrics['races']:>5}  {metrics['brier']:.6f}  {delta:+.6f}  "
                f"{metrics['nll']:.6f}  {percent(metrics['top1']):>7}  {score_text}"
            )
        print()

    print("【全期間・ペアBootstrap】")
    print("方式                   N    Brier差      95%CI                         改善確率    NLL差       期間改善数")
    print("-" * 126)
    summary = []
    for method, values in combined.items():
        brier_deltas = [candidate - base for candidate, base in zip(values["candidate_brier"], values["base_brier"])]
        nll_deltas = [candidate - base for candidate, base in zip(values["candidate_nll"], values["base_nll"])]
        boot = bootstrap_delta(brier_deltas)
        period_methods = [row for row in period_rows if row[3] == method]
        improved_periods = 0
        for row in period_methods:
            base = next(item[5] for item in grouped[row[0]] if item[3] == "EX_TOTAL_ONLY")
            improved_periods += int(row[5]["brier"] < base["brier"])
        nll_delta = sum(nll_deltas) / len(nll_deltas)
        summary.append((method, boot, nll_delta, improved_periods, len(brier_deltas)))
        print(
            f"{method:<22} {len(brier_deltas):>5}  {boot['mean']:+.7f}  "
            f"[{boot['low']:+.7f}, {boot['high']:+.7f}]  "
            f"{boot['improve_probability']*100:>7.2f}%  {nll_delta:+.7f}   {improved_periods}/{len(period_methods)}"
        )

    eligible = [row for row in summary if row[0] in ("POINT_SUM", "RANK_SUM", "RELATIVE_Z")]
    best = min(eligible, key=lambda row: row[1]["mean"])
    method, boot, nll_delta, improved_periods, sample_n = best
    # 置換候補はSUMなしではなく、現行固定SUMに勝つ必要がある。
    candidate_values = combined[method]
    current_values = combined["CURRENT_SUM_FIXED"]
    replacement_deltas = [
        candidate - current_candidate
        for candidate, current_candidate in zip(candidate_values["candidate_brier"], current_values["candidate_brier"])
    ]
    replacement_boot = bootstrap_delta(replacement_deltas, seed=20260927)

    incremental_brier_deltas = [
        candidate - base
        for candidate, base in zip(incremental["candidate_brier"], incremental["base_brier"])
    ]
    incremental_nll_deltas = [
        candidate - base
        for candidate, base in zip(incremental["candidate_nll"], incremental["base_nll"])
    ]
    incremental_boot = bootstrap_delta(incremental_brier_deltas, seed=20260928)
    incremental_nll_delta = sum(incremental_nll_deltas) / len(incremental_nll_deltas)
    incremental_period_improvements = sum(
        row[5]["brier"] < row[4]["brier"] for row in incremental_rows
    )

    print("\n【現行SUMへ相対Zを追加】")
    print("期間   gamma    現行Brier   追加後Brier   Brier差      現行NLL    追加後NLL")
    print("-" * 100)
    for window_index, _, _, gamma, fixed, extra in incremental_rows:
        print(
            f"P{window_index:<2}    {gamma:+6.2f}  {fixed['brier']:.6f}   {extra['brier']:.6f}   "
            f"{extra['brier']-fixed['brier']:+.7f}   {fixed['nll']:.6f}   {extra['nll']:.6f}"
        )
    print(
        f"全期間: Brier差={incremental_boot['mean']:+.7f} "
        f"95%CI=[{incremental_boot['low']:+.7f}, {incremental_boot['high']:+.7f}] "
        f"改善確率={incremental_boot['improve_probability']*100:.2f}% "
        f"NLL差={incremental_nll_delta:+.7f} "
        f"期間改善={incremental_period_improvements}/{len(incremental_rows)}"
    )

    adoption = (
        replacement_boot["mean"] < 0.0
        and replacement_boot["improve_probability"] >= 0.95
        and nll_delta < 0.0
        and improved_periods == len(windows)
    )
    incremental_adoption = (
        incremental_boot["mean"] < 0.0
        and incremental_boot["improve_probability"] >= 0.95
        and incremental_nll_delta < 0.0
        and incremental_period_improvements == len(incremental_rows)
    )

    print("\n【場別Brier差・最有力候補】")
    place_values = defaultdict(list)
    for place, candidate, base in zip(candidate_values["places"], candidate_values["candidate_brier"], candidate_values["base_brier"]):
        place_values[place].append(candidate - base)
    for place in sorted(place_values):
        deltas = place_values[place]
        print(f"{place}: N={len(deltas):4d}  Brier差={sum(deltas)/len(deltas):+.7f}")

    print("\n【暫定判定】")
    print(f"最有力候補   : {method}")
    print(
        f"現行SUM比    : Brier差={replacement_boot['mean']:+.7f} "
        f"改善確率={replacement_boot['improve_probability']*100:.2f}%"
    )
    print(f"置換判定     : {'影モデル候補' if adoption else '現行SUMを維持'}")
    print(f"追加判定     : {'影モデル候補' if incremental_adoption else '相対Zの追加も保留'}")
    if not adoption:
        reasons = []
        if replacement_boot["mean"] >= 0.0:
            reasons.append("現行SUMよりBrierが悪い")
        if replacement_boot["improve_probability"] < 0.95:
            reasons.append("現行SUM比の改善確率が95%未満")
        print("置換しない理由: " + "／".join(reasons))
    print("※ 採用判定でも本番へ直結せず、次に場別安定性と前方影運用を確認する。")

    print("\n【skip】")
    for key in sorted(skipped):
        print(f"{key:<28}: {skipped[key]}")
    print("=" * 138)


if __name__ == "__main__":
    main()
