#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""LightGBM分類とランク学習モデルの2026年ウォークフォワード比較。"""

from __future__ import annotations

import argparse
import gc
import json
import sys
from datetime import date
from pathlib import Path

import numpy as np
from lightgbm import LGBMClassifier, LGBMRanker
from scipy.optimize import minimize_scalar
from sklearn.compose import ColumnTransformer
from sklearn.impute import SimpleImputer
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import OneHotEncoder
from xgboost import XGBRanker

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "forecast"))

from ai_winrate_features import FEATURE_NAMES, normalized_race_probabilities  # noqa: E402
from train_ai_winrate import (  # noqa: E402
    COURSE,
    IS_WINNER,
    PLACE_CODE,
    RACE_CODE,
    RACE_DATE,
    build_training_matrix,
    load_rows,
)

CATEGORICAL_COLUMNS = [0, 1]
NUMERIC_COLUMNS = list(range(2, len(FEATURE_NAMES)))


def following_month(value: date) -> date:
    return date(value.year + (value.month == 12), (value.month % 12) + 1, 1)


def previous_month(value: date) -> date:
    return date(value.year - (value.month == 1), ((value.month - 2) % 12) + 1, 1)


def make_lightgbm_classifier() -> LGBMClassifier:
    return LGBMClassifier(
        objective="binary",
        n_estimators=320,
        learning_rate=0.04,
        num_leaves=31,
        max_depth=-1,
        min_child_samples=80,
        subsample=0.85,
        colsample_bytree=0.90,
        reg_lambda=8.0,
        n_jobs=-1,
        random_state=20260923,
        verbosity=-1,
    )


def make_lightgbm_ranker() -> LGBMRanker:
    return LGBMRanker(
        objective="lambdarank",
        metric="ndcg",
        label_gain=[0, 1],
        n_estimators=320,
        learning_rate=0.04,
        num_leaves=31,
        max_depth=-1,
        min_child_samples=80,
        subsample=0.85,
        colsample_bytree=0.90,
        reg_lambda=8.0,
        n_jobs=-1,
        random_state=20260923,
        verbosity=-1,
    )


def make_xgboost_ranker() -> Pipeline:
    preprocessor = ColumnTransformer([
        ("category", OneHotEncoder(handle_unknown="ignore"), CATEGORICAL_COLUMNS),
        ("numeric", SimpleImputer(strategy="median"), NUMERIC_COLUMNS),
    ])
    model = XGBRanker(
        objective="rank:ndcg",
        eval_metric="ndcg@1",
        lambdarank_pair_method="topk",
        lambdarank_num_pair_per_sample=3,
        n_estimators=300,
        max_depth=6,
        learning_rate=0.04,
        subsample=0.85,
        colsample_bytree=0.90,
        min_child_weight=20,
        reg_lambda=8.0,
        n_jobs=-1,
        random_state=20260923,
    )
    return Pipeline([("features", preprocessor), ("model", model)])


def race_groups(rows: list[tuple]) -> tuple[list[str], list[np.ndarray], np.ndarray]:
    codes = [str(row[RACE_CODE]) for row in rows]
    grouped: dict[str, list[int]] = {}
    for index, code in enumerate(codes):
        grouped.setdefault(code, []).append(index)
    indices = [np.asarray(group, dtype=np.int64) for group in grouped.values()]
    sizes = np.asarray([len(group) for group in indices], dtype=np.int32)
    if len(rows) != len(indices) * 6 or np.any(sizes != 6):
        raise RuntimeError("ランク学習対象に6艇以外のレースが含まれています")
    return codes, indices, sizes


def rank_probabilities(scores: np.ndarray, groups: list[np.ndarray], temperature: float) -> np.ndarray:
    probabilities = np.empty(len(scores), dtype=np.float64)
    temperature = max(float(temperature), 1e-6)
    for indices in groups:
        logits = scores[indices] / temperature
        logits -= np.max(logits)
        values = np.exp(logits)
        probabilities[indices] = values / values.sum()
    return probabilities


def calibrate_temperature(scores: np.ndarray, labels: np.ndarray, groups: list[np.ndarray]) -> float:
    winner_indices = np.asarray([
        indices[np.flatnonzero(labels[indices] == 1)[0]]
        for indices in groups
    ], dtype=np.int64)

    def objective(log_temperature: float) -> float:
        probabilities = rank_probabilities(scores, groups, float(np.exp(log_temperature)))
        return float(-np.mean(np.log(np.maximum(probabilities[winner_indices], 1e-12))))

    optimized = minimize_scalar(objective, bounds=(np.log(0.03), np.log(20.0)), method="bounded")
    return float(np.exp(optimized.x))


def score_probabilities(probabilities: np.ndarray, rows: list[tuple]) -> dict:
    codes, groups, _ = race_groups(rows)
    normalized = normalized_race_probabilities(probabilities, codes)
    labels = np.asarray([bool(row[IS_WINNER]) for row in rows], dtype=np.int8)
    hits = 0
    picks: dict[str, int] = {}
    for code, indices in zip(dict.fromkeys(codes), groups):
        top = max(indices, key=lambda i: (normalized[i], -int(rows[i][COURSE])))
        hits += int(labels[top])
        picks[code] = int(rows[top][COURSE])
    winner_probabilities = normalized[labels == 1]
    return {
        "races": len(groups),
        "hits": hits,
        "top1_rate": hits / len(groups),
        "brier": float(np.mean((normalized - labels) ** 2)),
        "winner_nll": float(-np.mean(np.log(np.maximum(winner_probabilities, 1e-12)))),
        "picks": picks,
    }


def fit_rankers(x_train: np.ndarray, y_train: np.ndarray, group_sizes: np.ndarray):
    lightgbm = make_lightgbm_ranker()
    lightgbm.fit(x_train, y_train, group=group_sizes, categorical_feature=CATEGORICAL_COLUMNS)
    xgboost = make_xgboost_ranker()
    xgboost.fit(x_train, y_train, model__group=group_sizes)
    return lightgbm, xgboost


def initial_temperatures(rows: list[tuple], place_to_id: dict[str, int], start: date) -> dict[str, float]:
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

    print(f"{calibration_start:%Y-%m} を使ってランクスコアの初期確率補正を行います…", flush=True)
    lightgbm, xgboost = fit_rankers(x_train, y_train, train_group_sizes)
    temperatures = {
        "lightgbm_lambdarank": calibrate_temperature(
            lightgbm.predict(x_calibration), y_calibration, calibration_groups
        ),
        "xgboost_rank_ndcg": calibrate_temperature(
            xgboost.predict(x_calibration), y_calibration, calibration_groups
        ),
    }
    del train_rows, calibration_rows, x_train, x_calibration, y_train, y_calibration
    del lightgbm, xgboost
    gc.collect()
    return temperatures


def blank_result() -> dict:
    return {"races": 0, "hits": 0, "brier_sum": 0.0, "nll_sum": 0.0, "months": []}


def add_result(target: dict, metric: dict, month: str, temperature: float | None = None) -> None:
    target["races"] += metric["races"]
    target["hits"] += metric["hits"]
    target["brier_sum"] += metric["brier"] * metric["races"]
    target["nll_sum"] += metric["winner_nll"] * metric["races"]
    month_result = {
        "month": month,
        **{key: value for key, value in metric.items() if key != "picks"},
    }
    if temperature is not None:
        month_result["temperature"] = temperature
    target["months"].append(month_result)


def finalize(record: dict) -> dict:
    races = record["races"]
    return {
        "races": races,
        "hits": record["hits"],
        "top1_rate": record["hits"] / races,
        "brier": record["brier_sum"] / races,
        "winner_nll": record["nll_sum"] / races,
        "months": record["months"],
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--start", default="2026-01-01")
    parser.add_argument("--end-exclusive", default="2026-09-23")
    parser.add_argument("--history-start", default="2025-01-01")
    args = parser.parse_args()
    start = date.fromisoformat(args.start)
    end_exclusive = date.fromisoformat(args.end_exclusive)

    print("確率補正用データを読み込んでいます…", flush=True)
    pre_rows = load_rows(args.history_start, start.isoformat())
    places = sorted({str(row[PLACE_CODE]) for row in pre_rows})
    place_to_id = {place: index + 1 for index, place in enumerate(places)}
    temperatures = initial_temperatures(pre_rows, place_to_id, start)
    del pre_rows
    gc.collect()
    print("初期温度: " + json.dumps(temperatures, ensure_ascii=False), flush=True)

    names = ("lightgbm_classifier", "lightgbm_lambdarank", "xgboost_rank_ndcg")
    records = {name: blank_result() for name in names}
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
        x_train = build_training_matrix(train_rows, place_to_id)
        x_test = build_training_matrix(test_rows, place_to_id)
        y_train = np.asarray([bool(row[IS_WINNER]) for row in train_rows], dtype=np.int8)
        y_test = np.asarray([bool(row[IS_WINNER]) for row in test_rows], dtype=np.int8)
        _, _, train_group_sizes = race_groups(train_rows)
        _, test_groups, _ = race_groups(test_rows)

        print(f"{month_name} のLightGBM分類と2つのランクモデルを学習しています…", flush=True)
        classifier = make_lightgbm_classifier()
        classifier.fit(x_train, y_train, categorical_feature=CATEGORICAL_COLUMNS)
        lightgbm_ranker, xgboost_ranker = fit_rankers(x_train, y_train, train_group_sizes)

        classifier_probability = classifier.predict_proba(x_test)[:, 1]
        lightgbm_scores = lightgbm_ranker.predict(x_test)
        xgboost_scores = xgboost_ranker.predict(x_test)
        lightgbm_probability = rank_probabilities(
            lightgbm_scores, test_groups, temperatures["lightgbm_lambdarank"]
        )
        xgboost_probability = rank_probabilities(
            xgboost_scores, test_groups, temperatures["xgboost_rank_ndcg"]
        )
        metrics = {
            "lightgbm_classifier": score_probabilities(classifier_probability, test_rows),
            "lightgbm_lambdarank": score_probabilities(lightgbm_probability, test_rows),
            "xgboost_rank_ndcg": score_probabilities(xgboost_probability, test_rows),
        }
        add_result(records["lightgbm_classifier"], metrics["lightgbm_classifier"], month_name)
        add_result(
            records["lightgbm_lambdarank"], metrics["lightgbm_lambdarank"], month_name,
            temperatures["lightgbm_lambdarank"],
        )
        add_result(
            records["xgboost_rank_ndcg"], metrics["xgboost_rank_ndcg"], month_name,
            temperatures["xgboost_rank_ndcg"],
        )
        print(
            f"{month_name}  {metrics['lightgbm_classifier']['races']}R  "
            f"LGB分類 {metrics['lightgbm_classifier']['top1_rate'] * 100:.2f}%  "
            f"LGB順位 {metrics['lightgbm_lambdarank']['top1_rate'] * 100:.2f}%  "
            f"XGB順位 {metrics['xgboost_rank_ndcg']['top1_rate'] * 100:.2f}%",
            flush=True,
        )

        # 当月の正解は翌月には既知なので、次月の確率変換だけを更新する。
        temperatures["lightgbm_lambdarank"] = calibrate_temperature(
            lightgbm_scores, y_test, test_groups
        )
        temperatures["xgboost_rank_ndcg"] = calibrate_temperature(
            xgboost_scores, y_test, test_groups
        )
        del rows, train_rows, test_rows, x_train, x_test, y_train, y_test
        del classifier, lightgbm_ranker, xgboost_ranker
        del classifier_probability, lightgbm_scores, xgboost_scores
        del lightgbm_probability, xgboost_probability, metrics
        gc.collect()
        current = month_end

    final = {name: finalize(record) for name, record in records.items()}
    final["reference_ai_winrate_v2"] = {
        "races": 31703,
        "hits": 17660,
        "top1_rate": 17660 / 31703,
        "brier": 0.10011851041712644,
        "winner_nll": 1.2259795800061188,
    }
    print("RESULT_JSON=" + json.dumps(final, ensure_ascii=False), flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
