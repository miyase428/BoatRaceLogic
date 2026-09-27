#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""AI1着率候補モデルの2026年ウォークフォワード比較。

全候補を、各検証月より前のデータだけで学習する。同じ特徴量、同じレース、
同じ「最上位艇を1着本命にする」採点条件で比較する。
"""

from __future__ import annotations

import gc
import json
import sys
import argparse
from datetime import date
from pathlib import Path

import numpy as np
from catboost import CatBoostClassifier
from sklearn.compose import ColumnTransformer
from sklearn.ensemble import ExtraTreesClassifier, HistGradientBoostingClassifier
from sklearn.impute import SimpleImputer
from sklearn.linear_model import LogisticRegression
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import OneHotEncoder, StandardScaler
from xgboost import XGBClassifier

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "forecast"))
from ai_winrate_features import FEATURE_NAMES, normalized_race_probabilities  # noqa: E402
from train_ai_winrate import (  # noqa: E402
    COURSE, IS_WINNER, PLACE_CODE, RACE_CODE, RACE_DATE,
    build_training_matrix, load_rows,
)

START = date(2026, 1, 1)
END_EXCLUSIVE = date(2026, 9, 23)
CATEGORICAL_COLUMNS = [0, 1]
NUMERIC_COLUMNS = list(range(2, len(FEATURE_NAMES)))


def following_month(value: date) -> date:
    return date(value.year + (value.month == 12), (value.month % 12) + 1, 1)


def one_hot_preprocessor(scale_numeric: bool) -> ColumnTransformer:
    numeric_steps = [("impute", SimpleImputer(strategy="median"))]
    if scale_numeric:
        numeric_steps.append(("scale", StandardScaler()))
    return ColumnTransformer([
        ("category", OneHotEncoder(handle_unknown="ignore"), CATEGORICAL_COLUMNS),
        ("numeric", Pipeline(numeric_steps), NUMERIC_COLUMNS),
    ])


def candidate_models() -> dict[str, object]:
    return {
        "logistic_regression": Pipeline([
            ("features", one_hot_preprocessor(scale_numeric=True)),
            ("model", LogisticRegression(C=0.25, max_iter=500, n_jobs=-1)),
        ]),
        "extra_trees": Pipeline([
            ("features", one_hot_preprocessor(scale_numeric=False)),
            ("model", ExtraTreesClassifier(
                n_estimators=180, max_features=0.85, min_samples_leaf=40,
                class_weight=None, n_jobs=-1, random_state=20260923,
            )),
        ]),
        "hist_gradient_boosting": HistGradientBoostingClassifier(
            learning_rate=0.06, max_iter=220, max_leaf_nodes=24,
            min_samples_leaf=80, l2_regularization=4.0,
            categorical_features=[True, True] + [False] * (len(FEATURE_NAMES) - 2),
            random_state=20260923,
        ),
        "xgboost_cpu": Pipeline([
            ("features", one_hot_preprocessor(scale_numeric=False)),
            ("model", XGBClassifier(
                objective="binary:logistic", eval_metric="logloss",
                n_estimators=260, max_depth=6, learning_rate=0.05,
                subsample=0.85, colsample_bytree=0.9, min_child_weight=20,
                reg_lambda=8.0, n_jobs=-1, random_state=20260923,
            )),
        ]),
        "catboost": CatBoostClassifier(
            loss_function="Logloss", iterations=300, depth=6, learning_rate=0.06,
            l2_leaf_reg=8.0, random_seed=20260923, verbose=False,
            allow_writing_files=False, thread_count=-1,
        ),
    }


def catboost_matrix(matrix: np.ndarray) -> np.ndarray:
    """CatBoostへ場・コースを明示的なカテゴリ値として渡す。"""
    result = matrix.astype(object)
    result[:, 0] = [str(int(value)) for value in matrix[:, 0]]
    result[:, 1] = [str(int(value)) for value in matrix[:, 1]]
    return result


def score(probabilities: np.ndarray, rows: list[tuple]) -> dict:
    codes = [str(row[RACE_CODE]) for row in rows]
    normalized = normalized_race_probabilities(probabilities, codes)
    labels = np.asarray([bool(row[IS_WINNER]) for row in rows], dtype=np.int8)
    by_race: dict[str, list[int]] = {}
    for index, code in enumerate(codes):
        by_race.setdefault(code, []).append(index)

    hits = 0
    for indices in by_race.values():
        top = max(indices, key=lambda i: (normalized[i], -int(rows[i][COURSE])))
        hits += int(labels[top])
    winner_probs = normalized[labels == 1]
    return {
        "races": len(by_race),
        "hits": hits,
        "top1_rate": hits / len(by_race),
        "brier": float(np.mean((normalized - labels) ** 2)),
        "winner_nll": float(-np.mean(np.log(np.maximum(winner_probs, 1e-12)))),
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--start", default=START.isoformat())
    parser.add_argument("--end-exclusive", default=END_EXCLUSIVE.isoformat())
    args = parser.parse_args()
    start = date.fromisoformat(args.start)
    end_exclusive = date.fromisoformat(args.end_exclusive)

    results: dict[str, dict] = {
        name: {"races": 0, "hits": 0, "brier_sum": 0.0, "nll_sum": 0.0, "months": []}
        for name in candidate_models()
    }
    current = start
    while current < end_exclusive:
        month_end = min(following_month(current), end_exclusive)
        print(f"{current:%Y-%m} の学習データを読み込んでいます…", flush=True)
        rows = load_rows("2025-01-01", month_end.isoformat())
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

        print(f"{current:%Y-%m} の5モデルを学習・採点しています…", flush=True)
        for name, model in candidate_models().items():
            if name == "catboost":
                train_input = catboost_matrix(x_train)
                test_input = catboost_matrix(x_test)
                model.fit(train_input, y_train, cat_features=CATEGORICAL_COLUMNS)
            else:
                train_input = x_train
                test_input = x_test
                model.fit(train_input, y_train)
            metric = score(model.predict_proba(test_input)[:, 1], test_rows)
            results[name]["races"] += metric["races"]
            results[name]["hits"] += metric["hits"]
            results[name]["brier_sum"] += metric["brier"] * metric["races"]
            results[name]["nll_sum"] += metric["winner_nll"] * metric["races"]
            results[name]["months"].append({"month": current.strftime("%Y-%m"), **metric})
            print(f"  {name}: {metric['top1_rate'] * 100:.2f}%", flush=True)
            del model
            gc.collect()
        del rows, train_rows, test_rows, x_train, x_test, y_train
        gc.collect()
        current = month_end

    final = {}
    for name, record in results.items():
        races = record["races"]
        final[name] = {
            "races": races,
            "hits": record["hits"],
            "top1_rate": record["hits"] / races,
            "brier": record["brier_sum"] / races,
            "winner_nll": record["nll_sum"] / races,
            "months": record["months"],
        }
    print("RESULT_JSON=" + json.dumps(final, ensure_ascii=False), flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
