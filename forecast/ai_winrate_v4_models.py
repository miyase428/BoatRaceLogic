#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""AI1着率 v4 のLightGBM LambdaRank定義。"""

from lightgbm import LGBMRanker


def make_v4_ranker() -> LGBMRanker:
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
