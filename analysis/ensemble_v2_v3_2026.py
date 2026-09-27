#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""AI1着率 v2構成モデルとv3（LightGBM LambdaRank）の組合せ検証。"""

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
from ranking_model_tournament_2026 import (  # noqa: E402
    CATEGORICAL_COLUMNS,
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
    RACE_CODE,
    RACE_DATE,
    build_training_matrix,
    load_rows,
)

V3_WEIGHTS = (0.25, 0.50, 0.75, 0.85, 0.90, 0.95)
BASE_MODELS = ("v2", "hist_gradient_boosting", "xgboost_classifier")


def blend(base_probability: np.ndarray, v3_probability: np.ndarray, v3_weight: float) -> np.ndarray:
    return (1.0 - v3_weight) * base_probability + v3_weight * v3_probability


def candidate_names() -> list[str]:
    names = ["hist_gradient_boosting", "xgboost_classifier", "v2", "v3_lightgbm_lambdarank"]
    for base in BASE_MODELS:
        for weight in V3_WEIGHTS:
            names.append(f"{base}_plus_v3_v3w{int(weight * 100):02d}")
    return names


def initial_temperature(
    rows: list[tuple],
    place_to_id: dict[str, int],
    start: date,
    calibration_start: date | None = None,
) -> float:
    calibration_start = calibration_start or previous_month(start)
    train_rows = [row for row in rows if str(row[RACE_DATE]) < calibration_start.isoformat()]
    calibration_rows = [
        row for row in rows
        if calibration_start.isoformat() <= str(row[RACE_DATE]) < start.isoformat()
    ]
    x_train = build_training_matrix(train_rows, place_to_id)
    x_calibration = build_training_matrix(calibration_rows, place_to_id)
    y_train = np.asarray([bool(row[IS_WINNER]) for row in train_rows], dtype=np.int8)
    y_calibration = np.asarray([bool(row[IS_WINNER]) for row in calibration_rows], dtype=np.int8)
    _, _, train_group_sizes = race_groups(train_rows)
    _, calibration_groups, _ = race_groups(calibration_rows)

    print(f"{calibration_start:%Y-%m} でv3の初期確率補正を行います…", flush=True)
    ranker = make_lightgbm_ranker()
    ranker.fit(x_train, y_train, group=train_group_sizes, categorical_feature=CATEGORICAL_COLUMNS)
    temperature = calibrate_temperature(
        ranker.predict(x_calibration), y_calibration, calibration_groups
    )
    del train_rows, calibration_rows, x_train, x_calibration, y_train, y_calibration, ranker
    gc.collect()
    return temperature


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--start", default="2026-01-01")
    parser.add_argument("--end-exclusive", default="2026-09-23")
    parser.add_argument("--history-start", default="2025-01-01")
    parser.add_argument("--calibration-start")
    args = parser.parse_args()
    start = date.fromisoformat(args.start)
    end_exclusive = date.fromisoformat(args.end_exclusive)
    calibration_start = date.fromisoformat(args.calibration_start) if args.calibration_start else None

    print("v3確率補正用データを読み込んでいます…", flush=True)
    pre_rows = load_rows(args.history_start, start.isoformat())
    places = sorted({str(row[PLACE_CODE]) for row in pre_rows})
    place_to_id = {place: index + 1 for index, place in enumerate(places)}
    temperature = initial_temperature(pre_rows, place_to_id, start, calibration_start)
    del pre_rows
    gc.collect()
    print(f"v3初期温度: {temperature:.6f}", flush=True)

    records = {name: blank_result() for name in candidate_names()}
    current = start
    while current < end_exclusive:
        month_end = min(following_month(current), end_exclusive)
        month_name = current.strftime("%Y-%m")
        print(f"{month_name} の学習・検証データを読み込んでいます…", flush=True)
        rows = load_rows(args.history_start, month_end.isoformat())
        places = sorted({str(row[PLACE_CODE]) for row in rows})
        place_to_id = {place: index + 1 for index, place in enumerate(places)}
        train_rows = [row for row in rows if str(row[RACE_DATE]) < current.isoformat()]
        test_rows = [
            row for row in rows
            if current.isoformat() <= str(row[RACE_DATE]) < month_end.isoformat()
        ]
        if not train_rows or not test_rows:
            print(f"{month_name} は学習または検証対象レースが0件のため終了します", flush=True)
            break
        x_train = build_training_matrix(train_rows, place_to_id)
        x_test = build_training_matrix(test_rows, place_to_id)
        y_train = np.asarray([bool(row[IS_WINNER]) for row in train_rows], dtype=np.int8)
        y_test = np.asarray([bool(row[IS_WINNER]) for row in test_rows], dtype=np.int8)
        race_codes = [str(row[RACE_CODE]) for row in test_rows]
        _, _, train_group_sizes = race_groups(train_rows)
        _, test_groups, _ = race_groups(test_rows)

        print(f"{month_name} のHGB・XGBoost・v3を学習しています…", flush=True)
        hist_model = make_hist_gradient_boosting()
        xgb_model = make_xgboost()
        v3_model = make_lightgbm_ranker()
        hist_model.fit(x_train, y_train)
        xgb_model.fit(x_train, y_train)
        v3_model.fit(
            x_train, y_train, group=train_group_sizes,
            categorical_feature=CATEGORICAL_COLUMNS,
        )

        hist_probability = normalized_race_probabilities(
            hist_model.predict_proba(x_test)[:, 1], race_codes
        )
        xgb_probability = normalized_race_probabilities(
            xgb_model.predict_proba(x_test)[:, 1], race_codes
        )
        v2_probability = 0.5 * hist_probability + 0.5 * xgb_probability
        v3_scores = v3_model.predict(x_test)
        v3_probability = rank_probabilities(v3_scores, test_groups, temperature)
        probabilities = {
            "hist_gradient_boosting": hist_probability,
            "xgboost_classifier": xgb_probability,
            "v2": v2_probability,
            "v3_lightgbm_lambdarank": v3_probability,
        }
        bases = {
            "v2": v2_probability,
            "hist_gradient_boosting": hist_probability,
            "xgboost_classifier": xgb_probability,
        }
        for base_name, base_probability in bases.items():
            for weight in V3_WEIGHTS:
                probabilities[f"{base_name}_plus_v3_v3w{int(weight * 100):02d}"] = blend(
                    base_probability, v3_probability, weight
                )

        metrics = {
            name: score_probabilities(probability, test_rows)
            for name, probability in probabilities.items()
        }
        for name, metric in metrics.items():
            add_result(
                records[name], metric, month_name,
                temperature if name == "v3_lightgbm_lambdarank" else None,
            )

        month_ranking = sorted(
            ((name, metric["hits"]) for name, metric in metrics.items()),
            key=lambda item: (-item[1], item[0]),
        )
        best_name, best_hits = month_ranking[0]
        races = metrics[best_name]["races"]
        print(
            f"{month_name}  {races}R  v2 {metrics['v2']['top1_rate'] * 100:.2f}%  "
            f"v3 {metrics['v3_lightgbm_lambdarank']['top1_rate'] * 100:.2f}%  "
            f"月首位 {best_name} {best_hits / races * 100:.2f}%",
            flush=True,
        )

        # 当月結果は次月時点では既知なので、v3の確率変換だけ次月用に更新する。
        temperature = calibrate_temperature(v3_scores, y_test, test_groups)
        del rows, train_rows, test_rows, x_train, x_test, y_train, y_test
        del hist_model, xgb_model, v3_model, hist_probability, xgb_probability
        del v2_probability, v3_scores, v3_probability, probabilities, bases, metrics
        gc.collect()
        current = month_end

    completed_records = {name: record for name, record in records.items() if record["races"] > 0}
    if not completed_records:
        print("RESULT_JSON={}", flush=True)
        return 0
    final = {name: finalize(record) for name, record in completed_records.items()}
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
    print("RANKING_JSON=" + json.dumps(ranking, ensure_ascii=False), flush=True)
    print("RESULT_JSON=" + json.dumps(final, ensure_ascii=False), flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
