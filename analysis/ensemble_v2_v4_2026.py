#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""追加特徴量版v4とv2・旧v3の混合比率を2026年で比較する。"""

from __future__ import annotations

import argparse
import gc
import json
import sys
from datetime import date
from pathlib import Path

import numpy as np

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "forecast"))
sys.path.insert(0, str(ROOT / "analysis"))

from ai_winrate_features import normalized_race_probabilities  # noqa: E402
from ai_winrate_v2_models import make_hist_gradient_boosting, make_xgboost  # noqa: E402
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
    make_lightgbm_ranker,
    previous_month,
    race_groups,
    rank_probabilities,
    score_probabilities,
)
from train_ai_winrate import IS_WINNER, PLACE_CODE, RACE_CODE, RACE_DATE  # noqa: E402

V4_WEIGHTS = (0.50, 0.60, 0.70, 0.75, 0.80, 0.85, 0.90, 0.95, 0.97)
BASE_MODELS = ("hist_gradient_boosting", "xgboost_classifier", "v2", "v3_baseline")


def candidate_names() -> list[str]:
    names = [*BASE_MODELS, "v4_all_features"]
    for base in BASE_MODELS:
        for weight in V4_WEIGHTS:
            names.append(f"{base}_plus_v4_v4w{int(weight * 100):02d}")
    return names


def blend(base: np.ndarray, v4: np.ndarray, v4_weight: float) -> np.ndarray:
    return (1.0 - v4_weight) * base + v4_weight * v4


def fit_rankers(
    train_rows: list[tuple],
    predict_rows: list[tuple],
    columns: dict[str, int],
    place_to_id: dict[str, int],
    v4_features: tuple[str, ...],
) -> tuple[np.ndarray, np.ndarray, list[np.ndarray], np.ndarray]:
    y_train = np.asarray([bool(row[IS_WINNER]) for row in train_rows], dtype=np.int8)
    y_predict = np.asarray([bool(row[IS_WINNER]) for row in predict_rows], dtype=np.int8)
    _, _, train_group_sizes = race_groups(train_rows)
    _, predict_groups, _ = race_groups(predict_rows)
    x_v3_train = build_matrix(train_rows, columns, BASE_FEATURES, place_to_id)
    x_v3_predict = build_matrix(predict_rows, columns, BASE_FEATURES, place_to_id)
    x_v4_train = build_matrix(train_rows, columns, v4_features, place_to_id)
    x_v4_predict = build_matrix(predict_rows, columns, v4_features, place_to_id)
    v3 = make_lightgbm_ranker()
    v4 = make_lightgbm_ranker()
    v3.fit(x_v3_train, y_train, group=train_group_sizes, categorical_feature=[0, 1])
    v4.fit(x_v4_train, y_train, group=train_group_sizes, categorical_feature=[0, 1])
    v3_scores = v3.predict(x_v3_predict)
    v4_scores = v4.predict(x_v4_predict)
    del x_v3_train, x_v3_predict, x_v4_train, x_v4_predict, v3, v4
    gc.collect()
    return v3_scores, v4_scores, predict_groups, y_predict


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--start", default="2026-01-01")
    parser.add_argument("--end-exclusive", default="2026-09-23")
    parser.add_argument("--history-start", default="2025-01-01")
    args = parser.parse_args()
    start = date.fromisoformat(args.start)
    end_exclusive = date.fromisoformat(args.end_exclusive)
    v4_features = variant_features()["v3_all_features"]

    print("v2・v3・v4共通データを構築しています…", flush=True)
    rows, columns = load_rows(args.history_start, args.end_exclusive)
    places = sorted({str(row[PLACE_CODE]) for row in rows})
    place_to_id = {place: index + 1 for index, place in enumerate(places)}

    calibration_start = previous_month(start)
    calibration_train = [
        row for row in rows if str(row[RACE_DATE]) < calibration_start.isoformat()
    ]
    calibration_rows = [
        row for row in rows
        if calibration_start.isoformat() <= str(row[RACE_DATE]) < start.isoformat()
    ]
    print(f"{calibration_start:%Y-%m} でv3・v4の初期確率補正を行います…", flush=True)
    v3_scores, v4_scores, groups, labels = fit_rankers(
        calibration_train,
        calibration_rows,
        columns,
        place_to_id,
        v4_features,
    )
    temperatures = {
        "v3_baseline": calibrate_temperature(v3_scores, labels, groups),
        "v4_all_features": calibrate_temperature(v4_scores, labels, groups),
    }
    del calibration_train, calibration_rows, v3_scores, v4_scores, groups, labels
    gc.collect()

    records = {name: blank_result() for name in candidate_names()}
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
        y_train = np.asarray([bool(row[IS_WINNER]) for row in train_rows], dtype=np.int8)
        x_train = build_matrix(train_rows, columns, BASE_FEATURES, place_to_id)
        x_test = build_matrix(test_rows, columns, BASE_FEATURES, place_to_id)
        race_codes = [str(row[RACE_CODE]) for row in test_rows]

        print(f"{month_name} のv2・v3・v4と混合比率を検証しています…", flush=True)
        hist_model = make_hist_gradient_boosting()
        xgb_model = make_xgboost()
        hist_model.fit(x_train, y_train)
        xgb_model.fit(x_train, y_train)
        hist_probability = normalized_race_probabilities(
            hist_model.predict_proba(x_test)[:, 1], race_codes
        )
        xgb_probability = normalized_race_probabilities(
            xgb_model.predict_proba(x_test)[:, 1], race_codes
        )
        v2_probability = 0.5 * hist_probability + 0.5 * xgb_probability
        v3_scores, v4_scores, groups, labels = fit_rankers(
            train_rows,
            test_rows,
            columns,
            place_to_id,
            v4_features,
        )
        v3_probability = rank_probabilities(
            v3_scores, groups, temperatures["v3_baseline"]
        )
        v4_probability = rank_probabilities(
            v4_scores, groups, temperatures["v4_all_features"]
        )
        bases = {
            "hist_gradient_boosting": hist_probability,
            "xgboost_classifier": xgb_probability,
            "v2": v2_probability,
            "v3_baseline": v3_probability,
        }
        probabilities = {**bases, "v4_all_features": v4_probability}
        for base_name, base_probability in bases.items():
            for weight in V4_WEIGHTS:
                name = f"{base_name}_plus_v4_v4w{int(weight * 100):02d}"
                probabilities[name] = blend(base_probability, v4_probability, weight)

        metrics = {
            name: score_probabilities(probability, test_rows)
            for name, probability in probabilities.items()
        }
        for name, metric in metrics.items():
            add_result(records[name], metric, month_name)
        ranking = sorted(metrics.items(), key=lambda item: (-item[1]["hits"], item[1]["brier"]))
        best_name, best = ranking[0]
        print(
            f"{month_name} {best['races']}R  v2 {metrics['v2']['top1_rate'] * 100:.3f}%  "
            f"v4 {metrics['v4_all_features']['top1_rate'] * 100:.3f}%  "
            f"月首位 {best_name} {best['top1_rate'] * 100:.3f}%",
            flush=True,
        )
        temperatures = {
            "v3_baseline": calibrate_temperature(v3_scores, labels, groups),
            "v4_all_features": calibrate_temperature(v4_scores, labels, groups),
        }
        del train_rows, test_rows, y_train, x_train, x_test
        del hist_model, xgb_model, hist_probability, xgb_probability, v2_probability
        del v3_scores, v4_scores, groups, labels, v3_probability, v4_probability
        del bases, probabilities, metrics
        gc.collect()
        current = month_end

    final = {name: finalize(record) for name, record in records.items()}
    ranking = sorted(
        (
            {
                "name": name,
                "hits": result["hits"],
                "top1_rate": result["top1_rate"],
                "brier": result["brier"],
                "winner_nll": result["winner_nll"],
            }
            for name, result in final.items()
        ),
        key=lambda item: (-item["hits"], item["brier"], item["name"]),
    )
    probability_ranking = sorted(
        ranking,
        key=lambda item: (item["brier"], item["winner_nll"], -item["hits"]),
    )
    print("RANKING_JSON=" + json.dumps(ranking, ensure_ascii=False), flush=True)
    print(
        "PROBABILITY_RANKING_JSON=" + json.dumps(probability_ranking, ensure_ascii=False),
        flush=True,
    )
    print("RESULT_JSON=" + json.dumps(final, ensure_ascii=False), flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
