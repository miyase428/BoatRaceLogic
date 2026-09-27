#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""CatBoostのグループ学習とv3 Dirichlet補正を2026年で比較する。"""

from __future__ import annotations

import argparse
import gc
import json
import sys
from datetime import date
from pathlib import Path

import numpy as np
from catboost import CatBoostRanker, Pool
from sklearn.linear_model import LogisticRegression

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "forecast"))
sys.path.insert(0, str(ROOT / "analysis"))

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
    COURSE,
    IS_WINNER,
    PLACE_CODE,
    RACE_CODE,
    RACE_DATE,
    build_training_matrix,
    load_rows,
)

MODEL_NAMES = (
    "v3_lightgbm_lambdarank",
    "v3_dirichlet_calibration",
    "catboost_query_softmax",
    "catboost_lambdamart",
    "catboost_yetirank_pairwise",
)


def make_catboost_ranker(loss_function: str, iterations: int) -> CatBoostRanker:
    return CatBoostRanker(
        loss_function=loss_function,
        iterations=iterations,
        depth=5,
        learning_rate=0.06,
        l2_leaf_reg=8.0,
        random_seed=20260924,
        verbose=False,
        allow_writing_files=False,
        thread_count=-1,
    )


def make_catboost_models(
    query_iterations: int,
    lambdamart_iterations: int,
    yeti_iterations: int,
    only_lambdamart: bool,
) -> dict[str, CatBoostRanker]:
    models = {
        "catboost_query_softmax": make_catboost_ranker(
            "QuerySoftMax", iterations=query_iterations
        ),
        "catboost_lambdamart": make_catboost_ranker(
            "LambdaMart:metric=NDCG;top=1", iterations=lambdamart_iterations
        ),
        "catboost_yetirank_pairwise": make_catboost_ranker(
            "YetiRankPairwise:mode=NDCG;top=1", iterations=yeti_iterations
        ),
    }
    if only_lambdamart:
        return {"catboost_lambdamart": models["catboost_lambdamart"]}
    return models


def catboost_matrix(matrix: np.ndarray) -> np.ndarray:
    result = matrix.astype(object)
    result[:, 0] = [str(int(value)) for value in matrix[:, 0]]
    result[:, 1] = [str(int(value)) for value in matrix[:, 1]]
    return result


def catboost_pool(matrix: np.ndarray, rows: list[tuple], labels: np.ndarray | None = None) -> Pool:
    return Pool(
        catboost_matrix(matrix),
        label=labels,
        group_id=[str(row[RACE_CODE]) for row in rows],
        cat_features=CATEGORICAL_COLUMNS,
    )


def probability_matrix(probabilities: np.ndarray, rows: list[tuple]) -> tuple[np.ndarray, np.ndarray, list[np.ndarray]]:
    _, groups, _ = race_groups(rows)
    matrix = np.zeros((len(groups), 6), dtype=np.float64)
    winners = np.empty(len(groups), dtype=np.int8)
    for race_index, indices in enumerate(groups):
        for index in indices:
            course_index = int(rows[index][COURSE]) - 1
            matrix[race_index, course_index] = probabilities[index]
            if bool(rows[index][IS_WINNER]):
                winners[race_index] = course_index
    return matrix, winners, groups


def make_dirichlet_calibrator(probabilities: np.ndarray, rows: list[tuple]) -> LogisticRegression:
    matrix, winners, _ = probability_matrix(probabilities, rows)
    calibrator = LogisticRegression(C=1.0, solver="lbfgs", max_iter=1000, random_state=20260924)
    calibrator.fit(np.log(np.maximum(matrix, 1e-12)), winners)
    return calibrator


def apply_dirichlet_calibrator(
    calibrator: LogisticRegression,
    probabilities: np.ndarray,
    rows: list[tuple],
) -> np.ndarray:
    matrix, _, groups = probability_matrix(probabilities, rows)
    calibrated_matrix = calibrator.predict_proba(np.log(np.maximum(matrix, 1e-12)))
    calibrated = np.empty(len(probabilities), dtype=np.float64)
    for race_index, indices in enumerate(groups):
        for index in indices:
            calibrated[index] = calibrated_matrix[race_index, int(rows[index][COURSE]) - 1]
    return calibrated


def fit_models(
    x_train: np.ndarray,
    y_train: np.ndarray,
    train_rows: list[tuple],
    train_group_sizes: np.ndarray,
    query_iterations: int,
    lambdamart_iterations: int,
    yeti_iterations: int,
    only_lambdamart: bool,
) -> tuple[object, dict[str, CatBoostRanker]]:
    v3 = make_lightgbm_ranker()
    v3.fit(x_train, y_train, group=train_group_sizes, categorical_feature=CATEGORICAL_COLUMNS)
    train_pool = catboost_pool(x_train, train_rows, y_train)
    catboost_models = make_catboost_models(
        query_iterations,
        lambdamart_iterations,
        yeti_iterations,
        only_lambdamart,
    )
    for name, model in catboost_models.items():
        print(f"  {name} を学習しています…", flush=True)
        model.fit(train_pool)
    return v3, catboost_models


def initial_calibration(
    rows: list[tuple],
    place_to_id: dict[str, int],
    start: date,
    query_iterations: int,
    lambdamart_iterations: int,
    yeti_iterations: int,
    only_lambdamart: bool,
) -> tuple[dict[str, float], LogisticRegression]:
    calibration_start = previous_month(start)
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

    print(f"{calibration_start:%Y-%m} で初期確率補正を作成しています…", flush=True)
    v3, catboost_models = fit_models(
        x_train,
        y_train,
        train_rows,
        train_group_sizes,
        query_iterations,
        lambdamart_iterations,
        yeti_iterations,
        only_lambdamart,
    )
    raw_scores = {"v3_lightgbm_lambdarank": v3.predict(x_calibration)}
    calibration_pool = catboost_pool(x_calibration, calibration_rows)
    raw_scores.update({
        name: model.predict(calibration_pool)
        for name, model in catboost_models.items()
    })
    temperatures = {
        name: calibrate_temperature(scores, y_calibration, calibration_groups)
        for name, scores in raw_scores.items()
    }
    v3_probability = rank_probabilities(
        raw_scores["v3_lightgbm_lambdarank"],
        calibration_groups,
        temperatures["v3_lightgbm_lambdarank"],
    )
    dirichlet = make_dirichlet_calibrator(v3_probability, calibration_rows)
    del train_rows, calibration_rows, x_train, x_calibration, y_train, y_calibration
    del v3, catboost_models, calibration_pool, raw_scores, v3_probability
    gc.collect()
    return temperatures, dirichlet


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--start", default="2026-01-01")
    parser.add_argument("--end-exclusive", default="2026-09-23")
    parser.add_argument("--history-start", default="2025-01-01")
    parser.add_argument("--query-iterations", type=int, default=100)
    parser.add_argument("--lambdamart-iterations", type=int, default=80)
    parser.add_argument("--yeti-iterations", type=int, default=40)
    parser.add_argument("--only-lambdamart", action="store_true")
    args = parser.parse_args()
    start = date.fromisoformat(args.start)
    end_exclusive = date.fromisoformat(args.end_exclusive)

    print("初期補正用データを読み込んでいます…", flush=True)
    pre_rows = load_rows(args.history_start, start.isoformat())
    places = sorted({str(row[PLACE_CODE]) for row in pre_rows})
    place_to_id = {place: index + 1 for index, place in enumerate(places)}
    temperatures, dirichlet = initial_calibration(
        pre_rows,
        place_to_id,
        start,
        args.query_iterations,
        args.lambdamart_iterations,
        args.yeti_iterations,
        args.only_lambdamart,
    )
    del pre_rows
    gc.collect()
    print("初期温度: " + json.dumps(temperatures, ensure_ascii=False), flush=True)

    active_names = (
        ("v3_lightgbm_lambdarank", "v3_dirichlet_calibration", "catboost_lambdamart")
        if args.only_lambdamart
        else MODEL_NAMES
    )
    records = {name: blank_result() for name in active_names}
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
            break
        x_train = build_training_matrix(train_rows, place_to_id)
        x_test = build_training_matrix(test_rows, place_to_id)
        y_train = np.asarray([bool(row[IS_WINNER]) for row in train_rows], dtype=np.int8)
        y_test = np.asarray([bool(row[IS_WINNER]) for row in test_rows], dtype=np.int8)
        _, _, train_group_sizes = race_groups(train_rows)
        _, test_groups, _ = race_groups(test_rows)

        model_label = "CatBoost LambdaMart" if args.only_lambdamart else "CatBoost 3モデル"
        print(f"{month_name} のv3と{model_label}を学習しています…", flush=True)
        v3, catboost_models = fit_models(
            x_train,
            y_train,
            train_rows,
            train_group_sizes,
            args.query_iterations,
            args.lambdamart_iterations,
            args.yeti_iterations,
            args.only_lambdamart,
        )
        test_pool = catboost_pool(x_test, test_rows)
        raw_scores = {"v3_lightgbm_lambdarank": v3.predict(x_test)}
        raw_scores.update({name: model.predict(test_pool) for name, model in catboost_models.items()})
        probabilities = {
            name: rank_probabilities(scores, test_groups, temperatures[name])
            for name, scores in raw_scores.items()
        }
        probabilities["v3_dirichlet_calibration"] = apply_dirichlet_calibrator(
            dirichlet, probabilities["v3_lightgbm_lambdarank"], test_rows
        )
        metrics = {
            name: score_probabilities(probability, test_rows)
            for name, probability in probabilities.items()
        }
        for name, metric in metrics.items():
            add_result(records[name], metric, month_name)

        ranking = sorted(metrics.items(), key=lambda item: (-item[1]["hits"], item[1]["brier"]))
        best_name, best = ranking[0]
        print(
            f"{month_name}  {best['races']}R  "
            f"v3 {metrics['v3_lightgbm_lambdarank']['top1_rate'] * 100:.2f}%  "
            f"Dirichlet {metrics['v3_dirichlet_calibration']['top1_rate'] * 100:.2f}%  "
            f"月首位 {best_name} {best['top1_rate'] * 100:.2f}%",
            flush=True,
        )

        # 当月結果だけで、次月に使う確率補正を更新する。
        temperatures = {
            name: calibrate_temperature(scores, y_test, test_groups)
            for name, scores in raw_scores.items()
        }
        dirichlet = make_dirichlet_calibrator(
            probabilities["v3_lightgbm_lambdarank"], test_rows
        )
        del rows, train_rows, test_rows, x_train, x_test, y_train, y_test
        del v3, catboost_models, test_pool, raw_scores, probabilities, metrics
        gc.collect()
        current = month_end

    final = {name: finalize(record) for name, record in records.items() if record["races"] > 0}
    if args.start == "2026-01-01" and args.end_exclusive == "2026-09-23":
        final["reference_v2_15_v3_85"] = {
            "races": 31703,
            "hits": 17910,
            "top1_rate": 17910 / 31703,
            "brier": 0.0985346223848558,
            "winner_nll": 1.209713628107321,
        }
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
        key=lambda item: (-item["hits"], item["brier"]),
    )
    print("RANKING_JSON=" + json.dumps(ranking, ensure_ascii=False), flush=True)
    print("RESULT_JSON=" + json.dumps(final, ensure_ascii=False), flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
