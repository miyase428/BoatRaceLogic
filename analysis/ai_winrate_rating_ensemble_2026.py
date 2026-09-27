#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""Rating追加v4をv2・現行v4・コース補正と同一条件で比較する。"""

from __future__ import annotations

import argparse
import csv
import gc
import gzip
import json
import sys
from collections import deque
from dataclasses import dataclass
from datetime import date
from pathlib import Path

import numpy as np

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "forecast"))
sys.path.insert(0, str(ROOT / "analysis"))

from ai_winrate_features import normalized_race_probabilities  # noqa: E402
from ai_winrate_v2_models import make_hist_gradient_boosting, make_xgboost  # noqa: E402
from ai_winrate_v4_rating_ablation_2026 import (  # noqa: E402
    PAIR_FEATURES,
    fit_predict,
    load_metadata,
)
from ai_winrate_motor_reset_ablation_2026 import (  # noqa: E402
    RESET_PAIR_FEATURES,
    append_pair_reset_features,
    load_motor_reset_dates,
)
from feature_ablation_tournament_2026 import (  # noqa: E402
    BASE_FEATURES,
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
    previous_month,
    race_groups,
    rank_probabilities,
    score_probabilities,
)
from train_ai_winrate import COURSE, IS_WINNER, PLACE_CODE, RACE_CODE, RACE_DATE  # noqa: E402

COURSE_STRENGTH = 500
HISTORY_MONTHS = 3


@dataclass
class ProbabilityBlock:
    month: str
    probabilities: dict[str, np.ndarray]
    labels: np.ndarray
    courses: np.ndarray


def base_candidate_weights() -> dict[str, tuple[float, float, float, float]]:
    """(v2, baseline-v4, pair-rating-v4, reset-pair-v4) の重み。"""
    return {
        "current_v2_15_v4_85": (0.15, 0.85, 0.00, 0.00),
        "pair_v2_05": (0.05, 0.00, 0.95, 0.00),
        "pair_v2_10": (0.10, 0.00, 0.90, 0.00),
        "pair_v2_15": (0.15, 0.00, 0.85, 0.00),
        "pair_v2_20": (0.20, 0.00, 0.80, 0.00),
        "trio_v2_15_base_21_pair_64": (0.15, 0.2125, 0.6375, 0.00),
        "trio_v2_15_base_42_pair_43": (0.15, 0.4250, 0.4250, 0.00),
        "trio_v2_15_base_64_pair_21": (0.15, 0.6375, 0.2125, 0.00),
        "trio_reset_v2_15_base_21_pair_64": (0.15, 0.2125, 0.00, 0.6375),
    }


def candidate_names() -> list[str]:
    base = list(base_candidate_weights())
    return base + [f"{name}_course_s{COURSE_STRENGTH}" for name in base]


def fit_all_scores(
    train_rows: list[tuple],
    predict_rows: list[tuple],
    columns: dict[str, int],
    variants: dict[str, tuple[str, ...]],
    place_to_id: dict[str, int],
) -> tuple[np.ndarray, dict[str, np.ndarray], list[np.ndarray], np.ndarray]:
    y_train = np.asarray([bool(row[IS_WINNER]) for row in train_rows], dtype=np.int8)
    x_train = build_matrix(train_rows, columns, BASE_FEATURES, place_to_id)
    x_predict = build_matrix(predict_rows, columns, BASE_FEATURES, place_to_id)
    race_codes = [str(row[RACE_CODE]) for row in predict_rows]
    hist = make_hist_gradient_boosting()
    xgb = make_xgboost()
    hist.fit(x_train, y_train)
    xgb.fit(x_train, y_train)
    hist_probability = normalized_race_probabilities(
        hist.predict_proba(x_predict)[:, 1], race_codes
    )
    xgb_probability = normalized_race_probabilities(
        xgb.predict_proba(x_predict)[:, 1], race_codes
    )
    v2_probability = 0.5 * hist_probability + 0.5 * xgb_probability
    scores, groups, labels = fit_predict(
        train_rows, predict_rows, columns, variants, place_to_id
    )
    del y_train, x_train, x_predict, hist, xgb, hist_probability, xgb_probability
    gc.collect()
    return v2_probability, scores, groups, labels


def build_candidates(
    v2: np.ndarray,
    baseline: np.ndarray,
    pair: np.ndarray,
    reset_pair: np.ndarray,
) -> dict[str, np.ndarray]:
    return {
        name: (
            v2_weight * v2
            + baseline_weight * baseline
            + pair_weight * pair
            + reset_pair_weight * reset_pair
        )
        for name, (
            v2_weight,
            baseline_weight,
            pair_weight,
            reset_pair_weight,
        ) in base_candidate_weights().items()
    }


def as_matrix(values: np.ndarray) -> np.ndarray:
    if len(values) % 6:
        raise RuntimeError("確率行数が6の倍数ではありません")
    return np.asarray(values, dtype=np.float64).reshape(-1, 6)


def course_factors(blocks: list[ProbabilityBlock], candidate: str) -> np.ndarray:
    probabilities = np.concatenate(
        [as_matrix(block.probabilities[candidate]) for block in blocks]
    )
    labels = np.concatenate([block.labels.reshape(-1, 6) for block in blocks])
    courses = np.concatenate([block.courses.reshape(-1, 6) for block in blocks])
    factors = np.ones(7, dtype=np.float64)
    for course in range(1, 7):
        selected = courses == course
        n = int(selected.sum())
        predicted_mean = float(probabilities[selected].mean())
        wins = float(labels[selected].sum())
        shrunk_rate = (wins + COURSE_STRENGTH * predicted_mean) / (n + COURSE_STRENGTH)
        factors[course] = shrunk_rate / predicted_mean
    return factors


def apply_course(values: np.ndarray, courses: np.ndarray, factors: np.ndarray) -> np.ndarray:
    probability = as_matrix(values) * factors[courses.reshape(-1, 6)]
    probability /= probability.sum(axis=1, keepdims=True)
    return probability.ravel()


def make_block(
    month: str,
    predict_rows: list[tuple],
    columns: dict[str, int],
    v2: np.ndarray,
    scores: dict[str, np.ndarray],
    groups: list[np.ndarray],
    labels: np.ndarray,
    temperatures: dict[str, float],
) -> ProbabilityBlock:
    baseline = rank_probabilities(scores["v4_baseline"], groups, temperatures["v4_baseline"])
    pair = rank_probabilities(scores["v4_pair_rating"], groups, temperatures["v4_pair_rating"])
    reset_pair = rank_probabilities(
        scores["v4_reset_pair_rating"],
        groups,
        temperatures["v4_reset_pair_rating"],
    )
    probabilities = build_candidates(v2, baseline, pair, reset_pair)
    courses = np.asarray([int(row[columns["course"]]) for row in predict_rows], dtype=np.int8)
    return ProbabilityBlock(month, probabilities, labels, courses)


def write_predictions(
    path: Path,
    row_parts: list[list[tuple]],
    probability_parts: dict[str, list[np.ndarray]],
) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    names = list(probability_parts)
    flattened = {name: np.concatenate(parts) for name, parts in probability_parts.items()}
    rows = [row for part in row_parts for row in part]
    with gzip.open(path, "wt", encoding="utf-8", newline="") as handle:
        fields = ["race_code", "race_date", "place", "course", "label", *names]
        writer = csv.DictWriter(handle, fieldnames=fields)
        writer.writeheader()
        for index, row in enumerate(rows):
            writer.writerow(
                {
                    "race_code": str(row[RACE_CODE]),
                    "race_date": str(row[RACE_DATE]),
                    "place": str(row[PLACE_CODE]),
                    "course": int(row[COURSE]),
                    "label": int(bool(row[IS_WINNER])),
                    **{name: float(flattened[name][index]) for name in names},
                }
            )


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--start", default="2026-01-01")
    parser.add_argument("--end-exclusive", default="2026-09-23")
    parser.add_argument("--history-start", default="2025-01-01")
    parser.add_argument(
        "--output-prefix",
        type=Path,
        default=ROOT / "analysis" / "output" / "ai_winrate_rating_ensemble_2026",
    )
    args = parser.parse_args()
    start = date.fromisoformat(args.start)
    end_exclusive = date.fromisoformat(args.end_exclusive)

    print("v4データとRating特徴量を構築しています…", flush=True)
    rows, columns = load_rows(args.history_start, args.end_exclusive)
    valid_keys = {
        (str(row[RACE_CODE]), int(row[columns["course"]])) for row in rows
    }
    metadata = load_metadata(args.history_start, args.end_exclusive, valid_keys)
    if valid_keys - metadata.keys():
        raise RuntimeError("Ratingメタデータが不足しています")
    reset_dates = load_motor_reset_dates(args.history_start, args.end_exclusive)
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
        "v4_pair_rating": v4_features + PAIR_FEATURES,
        "v4_reset_pair_rating": v4_features + RESET_PAIR_FEATURES,
    }
    places = sorted({str(row[PLACE_CODE]) for row in rows})
    place_to_id = {place: index + 1 for index, place in enumerate(places)}

    november = previous_month(previous_month(start))
    december = previous_month(start)
    november_train = [row for row in rows if str(row[RACE_DATE]) < november.isoformat()]
    november_rows = [
        row for row in rows
        if november.isoformat() <= str(row[RACE_DATE]) < december.isoformat()
    ]
    print(f"{november:%Y-%m} で初期温度を求めています…", flush=True)
    november_scores, november_groups, november_labels = fit_predict(
        november_train, november_rows, columns, variants, place_to_id
    )
    temperatures = {
        name: calibrate_temperature(score, november_labels, november_groups)
        for name, score in november_scores.items()
    }
    del november_train, november_rows, november_scores, november_groups, november_labels
    gc.collect()

    december_train = [row for row in rows if str(row[RACE_DATE]) < december.isoformat()]
    december_rows = [
        row for row in rows
        if december.isoformat() <= str(row[RACE_DATE]) < start.isoformat()
    ]
    print(f"{december:%Y-%m} の補正履歴確率を作っています…", flush=True)
    v2, scores, groups, labels = fit_all_scores(
        december_train, december_rows, columns, variants, place_to_id
    )
    december_block = make_block(
        december.strftime("%Y-%m"), december_rows, columns,
        v2, scores, groups, labels, temperatures,
    )
    temperatures = {
        name: calibrate_temperature(score, labels, groups)
        for name, score in scores.items()
    }
    history: deque[ProbabilityBlock] = deque([december_block], maxlen=HISTORY_MONTHS)
    del december_train, december_rows, v2, scores, groups, labels
    gc.collect()

    names = candidate_names()
    records = {name: blank_result() for name in names}
    row_parts: list[list[tuple]] = []
    probability_parts: dict[str, list[np.ndarray]] = {name: [] for name in names}
    current = start
    while current < end_exclusive:
        month_end = min(following_month(current), end_exclusive)
        month_name = current.strftime("%Y-%m")
        train_rows = [row for row in rows if str(row[RACE_DATE]) < current.isoformat()]
        test_rows = [
            row for row in rows
            if current.isoformat() <= str(row[RACE_DATE]) < month_end.isoformat()
        ]
        print(f"{month_name} のv2・v4・Rating混合を検証しています…", flush=True)
        v2, scores, groups, labels = fit_all_scores(
            train_rows, test_rows, columns, variants, place_to_id
        )
        block = make_block(
            month_name, test_rows, columns, v2, scores, groups, labels, temperatures
        )
        probabilities = dict(block.probabilities)
        for name in base_candidate_weights():
            factors = course_factors(list(history), name)
            probabilities[f"{name}_course_s{COURSE_STRENGTH}"] = apply_course(
                probabilities[name], block.courses, factors
            )
        metrics = {
            name: score_probabilities(probability, test_rows)
            for name, probability in probabilities.items()
        }
        for name, metric in metrics.items():
            add_result(records[name], metric, month_name)
            probability_parts[name].append(probabilities[name])
        row_parts.append(test_rows)
        best_name, best = min(
            metrics.items(), key=lambda item: (item[1]["brier"], item[1]["winner_nll"])
        )
        current_metric = metrics["current_v2_15_v4_85"]
        print(
            f"  現行 {current_metric['top1_rate']*100:.3f}% B={current_metric['brier']:.6f} / "
            f"Brier首位 {best_name} {best['top1_rate']*100:.3f}% B={best['brier']:.6f}",
            flush=True,
        )
        temperatures = {
            name: calibrate_temperature(score, labels, groups)
            for name, score in scores.items()
        }
        history.append(block)
        del train_rows, test_rows, v2, scores, groups, labels, probabilities, metrics
        gc.collect()
        current = month_end

    results = {name: finalize(record) for name, record in records.items()}
    baseline = results["current_v2_15_v4_85"]
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
        key=lambda item: (item["brier"], item["winner_nll"], -item["hits"]),
    )
    report = {
        "configuration": {
            "start": args.start,
            "end_exclusive": args.end_exclusive,
            "history_start": args.history_start,
            "course_strength": COURSE_STRENGTH,
            "course_history_months": HISTORY_MONTHS,
            "weights": base_candidate_weights(),
            "reset_events": [
                {"race_date": reset_date, "place": place}
                for reset_date, place in sorted(reset_dates)
            ],
        },
        "ranking": ranking,
        "results": results,
    }
    json_path = args.output_prefix.with_suffix(".json")
    prediction_path = args.output_prefix.with_name(
        args.output_prefix.name + "_predictions.csv.gz"
    )
    json_path.parent.mkdir(parents=True, exist_ok=True)
    json_path.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
    write_predictions(prediction_path, row_parts, probability_parts)

    print("\n【最終AI1着率候補】")
    print("方式                                      的中       Brier        ΔBrier      勝者NLL       ΔNLL")
    print("-" * 116)
    for item in ranking:
        print(
            f"{item['name']:<42} {item['hits']:>5}  {item['brier']:.9f}  "
            f"{item['brier_delta']:+.9f}  {item['winner_nll']:.9f}  {item['nll_delta']:+.9f}"
        )
    print(f"JSON: {json_path}")
    print(f"予測明細: {prediction_path}")
    print("RESULT_JSON=" + json.dumps(ranking, ensure_ascii=False), flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
