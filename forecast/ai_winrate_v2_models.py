#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""AI1着率 v2 のモデル定義。"""

from __future__ import annotations

from sklearn.compose import ColumnTransformer
from sklearn.ensemble import HistGradientBoostingClassifier
from sklearn.impute import SimpleImputer
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import OneHotEncoder
from xgboost import XGBClassifier

from ai_winrate_features import FEATURE_NAMES, normalized_race_probabilities


def make_hist_gradient_boosting() -> HistGradientBoostingClassifier:
    return HistGradientBoostingClassifier(
        learning_rate=0.06,
        max_iter=220,
        max_leaf_nodes=24,
        min_samples_leaf=80,
        l2_regularization=4.0,
        categorical_features=[True, True] + [False] * (len(FEATURE_NAMES) - 2),
        random_state=20260923,
    )


def make_xgboost() -> Pipeline:
    numeric_columns = list(range(2, len(FEATURE_NAMES)))
    preprocessor = ColumnTransformer([
        ("category", OneHotEncoder(handle_unknown="ignore"), [0, 1]),
        ("numeric", SimpleImputer(strategy="median"), numeric_columns),
    ])
    return Pipeline([
        ("features", preprocessor),
        ("model", XGBClassifier(
            objective="binary:logistic",
            eval_metric="logloss",
            n_estimators=260,
            max_depth=6,
            learning_rate=0.05,
            subsample=0.85,
            colsample_bytree=0.9,
            min_child_weight=20,
            reg_lambda=8.0,
            n_jobs=-1,
            random_state=20260923,
        )),
    ])


def blend_probabilities(hist_prob, xgb_prob, race_codes, hist_weight: float = 0.5):
    """各モデルをレース内100%へ正規化してから、指定比率で混ぜる。"""
    hist_normalized = normalized_race_probabilities(hist_prob, race_codes)
    xgb_normalized = normalized_race_probabilities(xgb_prob, race_codes)
    return hist_weight * hist_normalized + (1.0 - hist_weight) * xgb_normalized
