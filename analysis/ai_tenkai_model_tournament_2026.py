#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""AI展開予想を、現行式・重み最適化式・機械学習式で前方比較する。

全方式で同じAI1着率 v5の月次未使用予測を使い、勝者がどの決まり手で
勝つかという条件付き分布だけを比較する。履歴特徴量は日単位で更新し、
対象日と同日の結果を入れない。
"""

from __future__ import annotations

import argparse
import csv
import gzip
import json
import math
import sys
from calendar import monthrange
from collections import Counter, defaultdict, deque
from dataclasses import dataclass
from datetime import date, timedelta
from pathlib import Path
from typing import Iterable

import lightgbm as lgb
import joblib
import numpy as np
import psycopg2
from scipy.optimize import minimize_scalar

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "common"))
from db_config import load_db_config  # noqa: E402

PREDICTION_COLUMN = "trio_v2_15_base_21_pair_64_course_s500"
METHODS = ("nige", "sashi", "makuri", "makurizashi", "other")
INNER_METHODS = ("nige", "other")
OUTER_METHODS = ("sashi", "makuri", "makurizashi", "other")
METHOD_JA = {
    "nige": "逃げ",
    "sashi": "差し",
    "makuri": "まくり",
    "makurizashi": "まくり差し",
    "other": "その他",
}
JA_METHOD = {value: key for key, value in METHOD_JA.items() if key != "other"}
FALLBACK_INNER = {"nige": 0.90, "other": 0.10}
FALLBACK_OUTER = {
    "sashi": 0.25,
    "makuri": 0.35,
    "makurizashi": 0.30,
    "other": 0.10,
}
CURRENT_RECENT_WEIGHT = 2.0
CURRENT_PRIOR_K = 5.0
SEED = 20260926


HISTORY_SQL = """
SELECT
    COALESCE(
        rm.race_date,
        re.race_date,
        TO_DATE(SUBSTRING(re.race_code FROM 1 FOR 8), 'YYYYMMDD')
    ) AS race_date,
    re.race_code,
    SUBSTRING(re.race_code, 9, 3) AS place_code,
    re.player_id::text,
    COALESCE(rrd.entry_course, re.lane_number)::integer AS course,
    (TRIM(COALESCE(rrd.rank, '')) = '1') AS is_winner,
    TRIM(COALESCE(rrd.technique, '')) AS technique
FROM boat_race.race_entry re
JOIN boat_race.race_master rm
  ON rm.race_code = re.race_code
LEFT JOIN boat_race.race_result_detail rrd
  ON rrd.race_code = re.race_code
 AND rrd.player_id = re.player_id
WHERE rm.race_date >= %(start_date)s::date
  AND rm.race_date < %(end_date)s::date
ORDER BY rm.race_date, re.race_code, re.lane_number
"""


TARGET_SQL = """
WITH base AS (
    SELECT
        rm.race_date,
        re.race_code,
        SUBSTRING(re.race_code, 9, 3) AS place_code,
        COALESCE(NULLIF(regexp_replace(rm.race_number, '[^0-9]', '', 'g'), ''), '0')::integer
            AS race_number,
        re.lane_number::integer AS boat,
        re.player_id::text,
        el.entry_course::integer AS course,
        el.exhibition_time::double precision,
        el.start_timing::double precision,
        el.lap_time::double precision,
        el.around_time::double precision,
        el.straight_time::double precision,
        (TRIM(COALESCE(rrd.rank, '')) = '1') AS is_winner,
        TRIM(COALESCE(rrd.technique, '')) AS technique
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
), valid AS (
    SELECT race_code
    FROM base
    GROUP BY race_code
    HAVING COUNT(*) = 6
       AND COUNT(*) FILTER (WHERE is_winner) = 1
       AND COUNT(DISTINCT course) = 6
       AND COUNT(exhibition_time) = 6
       AND COUNT(start_timing) = 6
       AND COUNT(lap_time) = 6
       AND COUNT(around_time) = 6
)
SELECT b.*
FROM base b
JOIN valid USING (race_code)
ORDER BY b.race_date, b.race_code, b.course
"""


PREVIEW_SQL = """
SELECT
    COALESCE(
        rm.race_date,
        re.race_date,
        TO_DATE(SUBSTRING(re.race_code FROM 1 FOR 8), 'YYYYMMDD')
    ) AS race_date,
    re.race_code,
    SUBSTRING(re.race_code, 9, 3) AS place_code,
    COALESCE(
        NULLIF(regexp_replace(rm.race_number, '[^0-9]', '', 'g'), ''),
        SUBSTRING(re.race_code FROM 12 FOR 2),
        '0'
    )::integer
        AS race_number,
    re.lane_number::integer AS boat,
    re.player_id::text,
    el.entry_course::integer AS course,
    el.exhibition_time::double precision,
    el.start_timing::double precision,
    el.lap_time::double precision,
    el.around_time::double precision,
    el.straight_time::double precision,
    FALSE AS is_winner,
    ''::text AS technique
FROM boat_race.race_entry re
LEFT JOIN boat_race.race_master rm
  ON rm.race_code = re.race_code
JOIN boat_race.exhibition_live el
  ON el.race_code = re.race_code
 AND el.player_id = re.player_id
WHERE re.race_code = %(race_code)s
ORDER BY el.entry_course
"""


def shift_months(value: date, months: int) -> date:
    month_index = value.year * 12 + value.month - 1 + months
    year, month0 = divmod(month_index, 12)
    month = month0 + 1
    return date(year, month, min(value.day, monthrange(year, month)[1]))


def finite(value: object, default: float = 0.0) -> float:
    try:
        result = float(value)
    except (TypeError, ValueError):
        return default
    return result if math.isfinite(result) else default


def canonical_method(course: int, technique: str) -> str:
    key = JA_METHOD.get(technique.strip(), "other")
    if course == 1:
        return "nige" if key == "nige" else "other"
    return key if key in OUTER_METHODS else "other"


def valid_methods(course: int) -> tuple[str, ...]:
    return INNER_METHODS if course == 1 else OUTER_METHODS


@dataclass
class HistoryItem:
    race_date: date
    is_winner: bool
    method: str


class PlayerCourseHistory:
    def __init__(self) -> None:
        self.items: dict[tuple[str, int], deque[HistoryItem]] = defaultdict(deque)

    def add(self, player_id: str, course: int, item: HistoryItem) -> None:
        self.items[(player_id, course)].append(item)

    def snapshot(self, player_id: str, course: int, target_date: date) -> dict:
        values = self.items[(player_id, course)]
        cutoff12 = shift_months(target_date, -12)
        cutoff6 = shift_months(target_date, -6)
        while values and values[0].race_date < cutoff12:
            values.popleft()
        counts12: Counter[str] = Counter()
        counts6: Counter[str] = Counter()
        starts12 = starts6 = wins12 = wins6 = 0
        for item in values:
            starts12 += 1
            if item.race_date >= cutoff6:
                starts6 += 1
            if not item.is_winner:
                continue
            wins12 += 1
            counts12[item.method] += 1
            if item.race_date >= cutoff6:
                wins6 += 1
                counts6[item.method] += 1
        return {
            "starts6": starts6,
            "starts12": starts12,
            "wins6": wins6,
            "wins12": wins12,
            "counts6": counts6,
            "counts12": counts12,
        }


class VenueHistory:
    def __init__(self) -> None:
        self.items: dict[str, deque[tuple[date, int, str]]] = defaultdict(deque)
        self.cache: dict[tuple[date, str], dict] = {}

    def add(self, place: str, race_date: date, course: int, method: str) -> None:
        self.items[place].append((race_date, course, method))

    def snapshot(self, place: str, target_date: date) -> dict:
        cache_key = (target_date, place)
        cached = self.cache.get(cache_key)
        if cached is not None:
            return cached
        values = self.items[place]
        cutoff = shift_months(target_date, -12)
        while values and values[0][0] < cutoff:
            values.popleft()
        by_course: dict[int, Counter[str]] = defaultdict(Counter)
        for _, course, method in values:
            by_course[course][method] += 1
        result = {"total": len(values), "courses": by_course}
        self.cache[cache_key] = result
        return result


def venue_distribution(snapshot: dict, course: int) -> tuple[dict[str, float], int]:
    methods = valid_methods(course)
    counts = snapshot["courses"].get(course, Counter())
    total = sum(int(counts.get(method, 0)) for method in methods)
    fallback = FALLBACK_INNER if course == 1 else FALLBACK_OUTER
    if total <= 0:
        return dict(fallback), 0
    return ({method: counts.get(method, 0) / total for method in methods}, total)


def conditional_distribution(
    history: dict,
    prior: dict[str, float],
    course: int,
    recent_weight: float,
    prior_k: float,
) -> dict[str, float]:
    methods = valid_methods(course)
    wins6 = int(history["wins6"])
    wins12 = int(history["wins12"])
    older_wins = max(0, wins12 - wins6)
    denominator = recent_weight * wins6 + older_wins + prior_k
    values: dict[str, float] = {}
    for method in methods:
        recent = int(history["counts6"].get(method, 0))
        total = int(history["counts12"].get(method, 0))
        older = max(0, total - recent)
        numerator = recent_weight * recent + older + prior_k * prior.get(method, 0.0)
        values[method] = numerator / denominator if denominator > 0 else prior.get(method, 0.0)
    total_value = sum(values.values())
    if total_value <= 0:
        fallback = FALLBACK_INNER if course == 1 else FALLBACK_OUTER
        return dict(fallback)
    return {key: value / total_value for key, value in values.items()}


def load_prediction_cache(path: Path) -> dict[tuple[str, int], float]:
    result: dict[tuple[str, int], float] = {}
    with gzip.open(path, "rt", encoding="utf-8", newline="") as handle:
        reader = csv.DictReader(handle)
        if PREDICTION_COLUMN not in (reader.fieldnames or []):
            raise RuntimeError(f"予測キャッシュに {PREDICTION_COLUMN} がありません")
        for row in reader:
            result[(row["race_code"], int(row["course"]))] = float(row[PREDICTION_COLUMN])
    return result


def load_database_rows(history_start: date, end_exclusive: date) -> tuple[list[tuple], list[tuple]]:
    with psycopg2.connect(**load_db_config()) as conn:
        with conn.cursor() as cursor:
            cursor.execute(
                HISTORY_SQL,
                {"start_date": history_start, "end_date": end_exclusive},
            )
            history = cursor.fetchall()
            cursor.execute(
                TARGET_SQL,
                {"start_date": date(2026, 1, 1), "end_date": end_exclusive},
            )
            targets = cursor.fetchall()
    return history, targets


def load_preview_rows(race_code: str) -> list[tuple]:
    with psycopg2.connect(**load_db_config()) as conn:
        with conn.cursor() as cursor:
            cursor.execute(PREVIEW_SQL, {"race_code": race_code})
            return cursor.fetchall()


def z_scores(values: list[float]) -> list[float]:
    array = np.asarray(values, dtype=np.float64)
    std = float(array.std())
    if std <= 1e-12:
        return [0.0] * len(values)
    return [float((array.mean() - value) / std) for value in array]


def build_race_rows(
    raw_rows: list[tuple],
    predictions: dict[tuple[str, int], float],
    require_winner: bool = True,
) -> dict[str, dict]:
    grouped: dict[str, list[tuple]] = defaultdict(list)
    for row in raw_rows:
        grouped[str(row[1])].append(row)
    races: dict[str, dict] = {}
    for race_code, rows in grouped.items():
        rows.sort(key=lambda row: int(row[6]))
        if len(rows) != 6 or {int(row[6]) for row in rows} != set(range(1, 7)):
            continue
        if any((race_code, int(row[6])) not in predictions for row in rows):
            continue
        probabilities = np.asarray(
            [predictions[(race_code, int(row[6]))] for row in rows], dtype=np.float64
        )
        if not np.all(np.isfinite(probabilities)) or probabilities.sum() <= 0:
            continue
        probabilities /= probabilities.sum()
        metrics = {}
        for name, index in (
            ("exhibition", 7),
            ("start", 8),
            ("lap", 9),
            ("around", 10),
            ("straight", 11),
        ):
            metrics[name] = z_scores([finite(row[index]) for row in rows])
        boats = []
        for position, row in enumerate(rows):
            boats.append(
                {
                    "race_date": row[0],
                    "race_code": race_code,
                    "place": str(row[2]),
                    "race_number": int(row[3]),
                    "boat": int(row[4]),
                    "player_id": str(row[5]),
                    "course": int(row[6]),
                    "p_win": float(probabilities[position]),
                    "is_winner": bool(row[12]),
                    "technique": str(row[13]),
                    **{f"{name}_z": values[position] for name, values in metrics.items()},
                }
            )
        winners = [boat for boat in boats if boat["is_winner"]]
        if require_winner and len(winners) != 1:
            continue
        races[race_code] = {
            "race_date": rows[0][0],
            "place": str(rows[0][2]),
            "race_number": int(rows[0][3]),
            "boats": boats,
            "winner_course": int(winners[0]["course"]) if winners else 0,
            "winner_method": (
                canonical_method(int(winners[0]["course"]), winners[0]["technique"])
                if winners
                else ""
            ),
        }
    return races


def attach_history_features(
    races: dict[str, dict],
    history_rows: list[tuple],
) -> None:
    targets_by_date: dict[date, list[dict]] = defaultdict(list)
    for race in races.values():
        targets_by_date[race["race_date"]].append(race)
    history_by_date: dict[date, list[tuple]] = defaultdict(list)
    for row in history_rows:
        history_by_date[row[0]].append(row)
    player_history = PlayerCourseHistory()
    venue_history = VenueHistory()
    all_dates = sorted(set(history_by_date) | set(targets_by_date))
    for day in all_dates:
        for race in targets_by_date.get(day, []):
            venue = venue_history.snapshot(race["place"], day)
            aligned = {boat["course"]: boat for boat in race["boats"]}
            for boat in race["boats"]:
                course = int(boat["course"])
                history = player_history.snapshot(boat["player_id"], course, day)
                prior, venue_wins = venue_distribution(venue, course)
                boat["history"] = history
                boat["venue_prior"] = prior
                boat["venue_course_wins"] = venue_wins
                boat["venue_races"] = int(venue["total"])
                boat["current_conditional"] = conditional_distribution(
                    history, prior, course, CURRENT_RECENT_WEIGHT, CURRENT_PRIOR_K
                )
                boat["aligned"] = aligned
        # 同日結果は全ターゲットの特徴量を作った後で反映する。
        for row in history_by_date.get(day, []):
            _, _, place, player_id, course, is_winner, technique = row
            course = int(course)
            method = canonical_method(course, str(technique))
            player_history.add(
                str(player_id), course, HistoryItem(day, bool(is_winner), method)
            )
            if is_winner:
                venue_history.add(str(place), day, course, method)


def one_hot(value: object, choices: Iterable[object]) -> list[float]:
    return [float(value == choice) for choice in choices]


def ml_feature_vector(boat: dict) -> list[float]:
    history = boat["history"]
    prior = boat["venue_prior"]
    current = boat["current_conditional"]
    course = int(boat["course"])
    vector: list[float] = []
    vector += one_hot(boat["place"], PLACE_CODES)
    vector += one_hot(course, range(1, 7))
    vector += [
        float(boat["race_number"]) / 12.0,
        float(boat["p_win"]),
        float(boat["exhibition_z"]),
        float(boat["start_z"]),
        float(boat["lap_z"]),
        float(boat["around_z"]),
        float(boat["straight_z"]),
        math.log1p(history["starts6"]),
        math.log1p(history["starts12"]),
        math.log1p(history["wins6"]),
        math.log1p(history["wins12"]),
        math.log1p(boat["venue_course_wins"]),
        math.log1p(boat["venue_races"]),
    ]
    for method in METHODS:
        vector += [
            float(history["counts6"].get(method, 0)),
            float(history["counts12"].get(method, 0)),
            float(prior.get(method, 0.0)),
            float(current.get(method, 0.0)),
        ]
    for aligned_course in range(1, 7):
        row = boat["aligned"][aligned_course]
        vector += [
            float(row["p_win"]),
            float(row["exhibition_z"]),
            float(row["start_z"]),
            float(row["lap_z"]),
            float(row["around_z"]),
            float(row["straight_z"]),
        ]
    return vector


PLACE_CODES: tuple[str, ...] = ()


def winner_samples(races: list[dict], inner: bool) -> tuple[np.ndarray, np.ndarray]:
    rows = []
    labels = []
    methods = INNER_METHODS if inner else OUTER_METHODS
    method_to_id = {method: index for index, method in enumerate(methods)}
    for race in races:
        winner = next(boat for boat in race["boats"] if boat["is_winner"])
        if (winner["course"] == 1) != inner:
            continue
        rows.append(ml_feature_vector(winner))
        labels.append(method_to_id[race["winner_method"]])
    return np.asarray(rows, dtype=np.float32), np.asarray(labels, dtype=np.int8)


def make_classifier(class_count: int) -> lgb.LGBMClassifier:
    common = dict(
        n_estimators=360,
        learning_rate=0.035,
        num_leaves=24,
        max_depth=-1,
        min_child_samples=100,
        subsample=0.90,
        colsample_bytree=0.85,
        reg_alpha=1.5,
        reg_lambda=6.0,
        random_state=SEED,
        n_jobs=-1,
        verbosity=-1,
    )
    if class_count == 2:
        return lgb.LGBMClassifier(objective="binary", **common)
    return lgb.LGBMClassifier(objective="multiclass", num_class=class_count, **common)


def temperature_scale(probability: np.ndarray, temperature: float) -> np.ndarray:
    logits = np.log(np.clip(probability, 1e-12, 1.0)) / max(temperature, 1e-6)
    logits -= logits.max(axis=1, keepdims=True)
    values = np.exp(logits)
    return values / values.sum(axis=1, keepdims=True)


def tune_temperature(probability: np.ndarray, labels: np.ndarray) -> float:
    indices = np.arange(len(labels))

    def objective(log_temperature: float) -> float:
        scaled = temperature_scale(probability, math.exp(log_temperature))
        return float(-np.mean(np.log(np.clip(scaled[indices, labels], 1e-12, 1.0))))

    result = minimize_scalar(objective, bounds=(-1.5, 1.5), method="bounded")
    return float(math.exp(result.x))


def fit_ml(train: list[dict], validation: list[dict]) -> dict:
    result = {}
    for inner, key, methods in (
        (True, "inner", INNER_METHODS),
        (False, "outer", OUTER_METHODS),
    ):
        x_train, y_train = winner_samples(train, inner)
        x_valid, y_valid = winner_samples(validation, inner)
        model = make_classifier(len(methods))
        model.fit(x_train, y_train)
        valid_probability = np.asarray(model.predict_proba(x_valid), dtype=np.float64)
        if valid_probability.ndim == 1:
            valid_probability = np.column_stack([1.0 - valid_probability, valid_probability])
        temperature = tune_temperature(valid_probability, y_valid)
        result[key] = {
            "model": model,
            "methods": methods,
            "temperature": temperature,
            "train_samples": len(y_train),
            "validation_samples": len(y_valid),
        }
    return result


def ml_conditional(boat: dict, models: dict) -> dict[str, float]:
    cached = boat.get("ml_conditional")
    if isinstance(cached, dict):
        return cached
    spec = models["inner" if boat["course"] == 1 else "outer"]
    x = np.asarray([ml_feature_vector(boat)], dtype=np.float32)
    probability = np.asarray(spec["model"].predict_proba(x), dtype=np.float64)
    if probability.ndim == 1:
        probability = np.column_stack([1.0 - probability, probability])
    probability = temperature_scale(probability, spec["temperature"])[0]
    return {method: float(value) for method, value in zip(spec["methods"], probability)}


def attach_ml_predictions(races: list[dict], models: dict) -> None:
    """LightGBMの予測をコース群ごとにまとめ、採点時の1艇ずつの呼出しを避ける。"""
    for inner, key in ((True, "inner"), (False, "outer")):
        boats = [
            boat
            for race in races
            for boat in race["boats"]
            if (boat["course"] == 1) == inner
        ]
        if not boats:
            continue
        spec = models[key]
        matrix = np.asarray([ml_feature_vector(boat) for boat in boats], dtype=np.float32)
        probability = np.asarray(spec["model"].predict_proba(matrix), dtype=np.float64)
        if probability.ndim == 1:
            probability = np.column_stack([1.0 - probability, probability])
        probability = temperature_scale(probability, spec["temperature"])
        for boat, values in zip(boats, probability):
            boat["ml_conditional"] = {
                method: float(value)
                for method, value in zip(spec["methods"], values)
            }


def event_distribution(race: dict, method: str, parameter: object) -> dict[tuple[int, str], float]:
    events: dict[tuple[int, str], float] = {}
    for boat in race["boats"]:
        if method == "formula":
            recent_weight, prior_k = parameter
            conditional = conditional_distribution(
                boat["history"], boat["venue_prior"], boat["course"], recent_weight, prior_k
            )
        elif method == "ml":
            conditional = ml_conditional(boat, parameter)
        else:
            raise ValueError(method)
        for technique, share in conditional.items():
            events[(int(boat["course"]), technique)] = float(boat["p_win"]) * float(share)
    total = sum(events.values())
    return {key: value / total for key, value in events.items()}


def race_metric(race: dict, events: dict[tuple[int, str], float]) -> dict[str, float]:
    actual = (race["winner_course"], race["winner_method"])
    probability = max(events.get(actual, 0.0), 1e-12)
    ranked = sorted(events, key=events.get, reverse=True)
    actual_rank = ranked.index(actual) + 1 if actual in ranked else len(ranked) + 1
    winner_boat = next(boat for boat in race["boats"] if boat["is_winner"])
    winner_methods = {
        key[1]: value for key, value in events.items() if key[0] == winner_boat["course"]
    }
    conditional_top = max(winner_methods, key=winner_methods.get)
    return {
        "nll": -math.log(probability),
        "brier": sum(value * value for value in events.values()) - 2.0 * probability + 1.0,
        "top1": float(actual_rank <= 1),
        "top3": float(actual_rank <= 3),
        "top5": float(actual_rank <= 5),
        "method_hit_given_winner": float(conditional_top == race["winner_method"]),
        "actual_probability": probability,
    }


def evaluate(races: list[dict], method: str, parameter: object) -> tuple[dict, list[dict]]:
    rows = [race_metric(race, event_distribution(race, method, parameter)) for race in races]
    metrics = {
        "races": len(rows),
        "event_top1_rate": float(np.mean([row["top1"] for row in rows])),
        "event_top3_coverage": float(np.mean([row["top3"] for row in rows])),
        "event_top5_coverage": float(np.mean([row["top5"] for row in rows])),
        "method_hit_given_winner": float(
            np.mean([row["method_hit_given_winner"] for row in rows])
        ),
        "event_brier": float(np.mean([row["brier"] for row in rows])),
        "event_nll": float(np.mean([row["nll"] for row in rows])),
        "mean_actual_probability": float(
            np.mean([row["actual_probability"] for row in rows])
        ),
    }
    return metrics, rows


def paired_bootstrap(base: list[dict], candidate: list[dict], samples: int = 3000) -> dict:
    rng = np.random.default_rng(SEED)
    n = len(base)
    result = {}
    for key, higher_is_better in (
        ("event_top1_rate", True),
        ("event_top3_coverage", True),
        ("event_top5_coverage", True),
        ("event_brier", False),
        ("event_nll", False),
    ):
        row_key = {
            "event_top1_rate": "top1",
            "event_top3_coverage": "top3",
            "event_top5_coverage": "top5",
            "event_brier": "brier",
            "event_nll": "nll",
        }[key]
        base_values = np.asarray([row[row_key] for row in base], dtype=np.float64)
        candidate_values = np.asarray([row[row_key] for row in candidate], dtype=np.float64)
        delta = candidate_values - base_values
        if not higher_is_better:
            delta = -delta
        chunk = []
        for _ in range(samples):
            indices = rng.integers(0, n, n)
            chunk.append(float(delta[indices].mean()))
        values = np.asarray(chunk)
        result[key] = {
            "improvement_probability": float(np.mean(values > 0.0)),
            "improvement_mean": float(delta.mean()),
            "ci95": [float(np.quantile(values, 0.025)), float(np.quantile(values, 0.975))],
        }
    return result


def method_breakdown(races: list[dict], method: str, parameter: object) -> dict:
    totals: dict[str, list[dict]] = defaultdict(list)
    for race in races:
        totals[race["winner_method"]].append(
            race_metric(race, event_distribution(race, method, parameter))
        )
    return {
        METHOD_JA[key]: {
            "races": len(rows),
            "top1_rate": float(np.mean([row["top1"] for row in rows])),
            "top3_coverage": float(np.mean([row["top3"] for row in rows])),
            "mean_probability": float(np.mean([row["actual_probability"] for row in rows])),
        }
        for key, rows in totals.items()
    }


def preview_top5(race: dict, method: str, parameter: object) -> list[dict]:
    events = event_distribution(race, method, parameter)
    by_course = {int(boat["course"]): boat for boat in race["boats"]}
    result = []
    for (course, technique), probability in sorted(
        (
            (key, value)
            for key, value in events.items()
            if key[1] != "other"
        ),
        key=lambda item: item[1],
        reverse=True,
    )[:5]:
        boat = by_course[course]
        venue_average = 0.0
        if boat["venue_races"] > 0:
            venue_average = (
                float(boat["venue_prior"].get(technique, 0.0))
                * float(boat["venue_course_wins"])
                / float(boat["venue_races"])
            )
        result.append(
            {
                "boat": int(boat["boat"]),
                "course": course,
                "technique": METHOD_JA[technique],
                "probability": probability,
                "venue_average": venue_average,
                "difference": probability - venue_average,
            }
        )
    return result


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument(
        "--prediction-cache",
        type=Path,
        default=ROOT / "analysis" / "output" / "ai_winrate_rating_ensemble_2026_predictions.csv.gz",
    )
    parser.add_argument(
        "--output",
        type=Path,
        default=ROOT / "analysis" / "output" / "ai_tenkai_model_tournament_2026.json",
    )
    parser.add_argument("--preview-race-code")
    parser.add_argument("--model-output", type=Path)
    args = parser.parse_args()

    history_start = date(2025, 1, 1)
    train_start = date(2026, 1, 1)
    validation_start = date(2026, 8, 1)
    test_start = date(2026, 9, 1)
    end_exclusive = date(2026, 9, 23)
    database_end_exclusive = end_exclusive

    print("AI1着率 v5の未使用予測を読み込んでいます…", flush=True)
    predictions = load_prediction_cache(args.prediction_cache)
    preview_code = (args.preview_race_code or "").strip().upper()
    if preview_code:
        if len(preview_code) != 13 or not preview_code[:8].isdigit():
            raise ValueError("preview-race-code は YYYYMMDDXXX99 形式で指定してください")
        preview_date = date.fromisoformat(
            f"{preview_code[:4]}-{preview_code[4:6]}-{preview_code[6:8]}"
        )
        database_end_exclusive = max(database_end_exclusive, preview_date + timedelta(days=1))
        sys.path.insert(0, str(ROOT / "forecast"))
        from ai_winrate_live_v5 import calculate as calculate_ai_winrate_v5

        live = calculate_ai_winrate_v5(preview_code)
        if live.get("status") != "ok":
            raise RuntimeError(f"プレビューのAI1着率v5を取得できません: {live}")
        for row in live["boats"].values():
            predictions[(preview_code, int(row["course"]))] = float(row["ai_rate"]) / 100.0
    print("決まり手・展示履歴をDBから読み込んでいます…", flush=True)
    history_rows, target_rows = load_database_rows(history_start, database_end_exclusive)
    races_by_code = build_race_rows(target_rows, predictions)
    if preview_code and preview_code not in races_by_code:
        races_by_code.update(
            build_race_rows(load_preview_rows(preview_code), predictions, require_winner=False)
        )
    print(f"比較可能レース: {len(races_by_code):,}R", flush=True)
    attach_history_features(races_by_code, history_rows)

    global PLACE_CODES
    PLACE_CODES = tuple(sorted({race["place"] for race in races_by_code.values()}))
    races = sorted(races_by_code.values(), key=lambda race: race["boats"][0]["race_code"])
    train = [race for race in races if train_start <= race["race_date"] < validation_start]
    validation = [race for race in races if validation_start <= race["race_date"] < test_start]
    test = [race for race in races if test_start <= race["race_date"] < end_exclusive]
    if min(len(train), len(validation), len(test)) <= 0:
        raise RuntimeError("学習・調整・テスト期間のデータが不足しています")
    print(
        f"学習 {len(train):,}R / 調整 {len(validation):,}R / テスト {len(test):,}R",
        flush=True,
    )

    current_parameter = (CURRENT_RECENT_WEIGHT, CURRENT_PRIOR_K)
    current_validation, _ = evaluate(validation, "formula", current_parameter)
    grid = []
    for recent_weight in (1.0, 1.5, 2.0, 3.0, 4.0):
        for prior_k in (0.0, 1.0, 2.0, 5.0, 10.0, 20.0, 50.0):
            metrics, _ = evaluate(validation, "formula", (recent_weight, prior_k))
            grid.append(
                {
                    "recent_weight": recent_weight,
                    "prior_k": prior_k,
                    **metrics,
                }
            )
    grid.sort(key=lambda row: (row["event_nll"], row["event_brier"], -row["event_top1_rate"]))
    selected = grid[0]
    optimized_parameter = (selected["recent_weight"], selected["prior_k"])
    print(
        f"重み最適化: 6か月×{optimized_parameter[0]:.1f} / K={optimized_parameter[1]:.1f}",
        flush=True,
    )

    print("決まり手の条件付き機械学習モデルを学習しています…", flush=True)
    ml_models = fit_ml(train, validation)
    print(
        f"温度校正: 1C={ml_models['inner']['temperature']:.3f} / 外={ml_models['outer']['temperature']:.3f}",
        flush=True,
    )
    preview_race = races_by_code.get(preview_code) if preview_code else None
    attach_ml_predictions(validation + test + ([preview_race] if preview_race else []), ml_models)
    if args.model_output is not None:
        artifact = {
            "version": "ai_tenkai_v1",
            "place_codes": list(PLACE_CODES),
            "inner": ml_models["inner"],
            "outer": ml_models["outer"],
            "optimized_fallback": {
                "recent_weight": optimized_parameter[0],
                "prior_k": optimized_parameter[1],
            },
            "training": {
                "train_start": train_start.isoformat(),
                "trained_through_exclusive": validation_start.isoformat(),
                "calibration_start": validation_start.isoformat(),
                "calibration_end_exclusive": test_start.isoformat(),
                "train_races": len(train),
                "validation_races": len(validation),
            },
        }
        args.model_output.parent.mkdir(parents=True, exist_ok=True)
        joblib.dump(artifact, args.model_output, compress=3)

    methods = {
        "current": ("formula", current_parameter),
        "optimized_formula": ("formula", optimized_parameter),
        "machine_learning": ("ml", ml_models),
    }
    validation_results = {}
    test_results = {}
    test_rows = {}
    for name, (kind, parameter) in methods.items():
        validation_results[name], _ = evaluate(validation, kind, parameter)
        test_results[name], test_rows[name] = evaluate(test, kind, parameter)
        test_results[name]["by_actual_method"] = method_breakdown(test, kind, parameter)

    comparisons = {
        "optimized_formula_vs_current": paired_bootstrap(
            test_rows["current"], test_rows["optimized_formula"]
        ),
        "machine_learning_vs_current": paired_bootstrap(
            test_rows["current"], test_rows["machine_learning"]
        ),
        "machine_learning_vs_optimized_formula": paired_bootstrap(
            test_rows["optimized_formula"], test_rows["machine_learning"]
        ),
    }
    model_meta = {
        key: {
            "temperature": spec["temperature"],
            "train_samples": spec["train_samples"],
            "validation_samples": spec["validation_samples"],
            "methods": list(spec["methods"]),
        }
        for key, spec in ml_models.items()
    }
    preview = None
    if preview_code:
        if preview_race is None:
            raise RuntimeError(f"プレビューレースを比較可能データから作れません: {preview_code}")
        preview = {
            "race_code": preview_code,
            "ai_win_rates": {
                str(boat["boat"]): float(boat["p_win"])
                for boat in preview_race["boats"]
            },
            "current": preview_top5(preview_race, "formula", current_parameter),
            "optimized_formula": preview_top5(
                preview_race, "formula", optimized_parameter
            ),
            "machine_learning": preview_top5(preview_race, "ml", ml_models),
        }
    report = {
        "purpose": "AI展開予想の現行式・重み最適化式・機械学習式を同一AI1着率v5で比較",
        "production_changed": False,
        "periods": {
            "history_start": history_start.isoformat(),
            "train": [train_start.isoformat(), (validation_start.replace(day=1)).isoformat()],
            "validation": [validation_start.isoformat(), "2026-08-31"],
            "test": [test_start.isoformat(), "2026-09-22"],
        },
        "coverage": {
            "database_history_rows": len(history_rows),
            "database_target_rows": len(target_rows),
            "races": {"train": len(train), "validation": len(validation), "test": len(test)},
            "places": list(PLACE_CODES),
        },
        "shared_win_probability": {
            "name": "AI1着率 v5",
            "prediction_column": PREDICTION_COLUMN,
            "source": str(args.prediction_cache),
        },
        "current_formula": {
            "recent_weight": CURRENT_RECENT_WEIGHT,
            "prior_k": CURRENT_PRIOR_K,
        },
        "optimized_formula": {
            "selected_on_validation": {
                "recent_weight": optimized_parameter[0],
                "prior_k": optimized_parameter[1],
            },
            "top10_validation_grid": grid[:10],
        },
        "machine_learning": {
            "algorithm": "LightGBM conditional technique classifier",
            "separate_models": "1C: escape/other; 2-6C: sashi/makuri/makurizashi/other",
            "features": "venue/course, AI1, player technique history, venue prior, current exhibition/ST and all-course lineup",
            "models": model_meta,
        },
        "validation": validation_results,
        "test": test_results,
        "paired_bootstrap_comparisons": comparisons,
        "preview": preview,
        "selection_rule": "Primary: untouched-test NLL/Brier; secondary: top1/top3/top5 coverage",
    }
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps({"status": "ok", "output": str(args.output), "test": test_results}, ensure_ascii=False), flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
