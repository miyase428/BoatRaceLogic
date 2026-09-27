#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""AI2着率・AI3着率のモデル大会。

AI1着率で比較した分類木・線形・Boosting・Ranker系を、同じ特徴量・同じ
時系列分割で比較する。モデル選択と温度/ブレンド選択はVALIDだけで行い、
TESTは最後の未使用評価に限定する。
"""

from __future__ import annotations

import json
import math
import sys
import time
from dataclasses import dataclass
from pathlib import Path

import numpy as np
from catboost import CatBoostClassifier, CatBoostRanker, Pool
from lightgbm import LGBMClassifier, LGBMRanker
from sklearn.ensemble import ExtraTreesClassifier, HistGradientBoostingClassifier
from sklearn.linear_model import LogisticRegression
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import StandardScaler
from xgboost import XGBClassifier, XGBRanker

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "analysis"))
sys.path.insert(0, str(ROOT / "forecast"))

import train_ai_place_v1 as v1  # noqa: E402
from ai_place_features import vector  # noqa: E402


REPORT_PATH = ROOT / "analysis/output/ai_place_model_tournament_2026.json"
EPS = 1.0e-12
TEMPERATURES = tuple(float(x) for x in np.linspace(0.50, 2.00, 31))
ALPHAS = (0.25, 0.50, 0.75, 1.00)


@dataclass(frozen=True)
class Candidate:
    name: str
    kind: str
    factory: object


def candidates() -> list[Candidate]:
    return [
        Candidate(
            "logistic_regression",
            "classifier",
            lambda: Pipeline([
                ("scale", StandardScaler()),
                ("model", LogisticRegression(C=0.25, max_iter=700, random_state=20260924)),
            ]),
        ),
        Candidate(
            "extra_trees",
            "classifier",
            lambda: ExtraTreesClassifier(
                n_estimators=180,
                max_features=0.85,
                min_samples_leaf=40,
                n_jobs=-1,
                random_state=20260924,
            ),
        ),
        Candidate(
            "hist_gradient_boosting",
            "classifier",
            lambda: HistGradientBoostingClassifier(
                learning_rate=0.06,
                max_iter=220,
                max_leaf_nodes=24,
                min_samples_leaf=80,
                l2_regularization=4.0,
                random_state=20260924,
            ),
        ),
        Candidate(
            "lightgbm_classifier",
            "classifier",
            lambda: LGBMClassifier(
                objective="binary",
                n_estimators=320,
                learning_rate=0.04,
                num_leaves=31,
                min_child_samples=80,
                subsample=0.85,
                colsample_bytree=0.90,
                reg_lambda=8.0,
                n_jobs=-1,
                random_state=20260924,
                verbosity=-1,
            ),
        ),
        Candidate(
            "xgboost_classifier",
            "classifier",
            lambda: XGBClassifier(
                objective="binary:logistic",
                eval_metric="logloss",
                n_estimators=260,
                max_depth=6,
                learning_rate=0.05,
                subsample=0.85,
                colsample_bytree=0.90,
                min_child_weight=20,
                reg_lambda=8.0,
                n_jobs=-1,
                random_state=20260924,
            ),
        ),
        Candidate(
            "catboost_classifier",
            "classifier",
            lambda: CatBoostClassifier(
                loss_function="Logloss",
                iterations=300,
                depth=6,
                learning_rate=0.06,
                l2_leaf_reg=8.0,
                random_seed=20260924,
                verbose=False,
                allow_writing_files=False,
                thread_count=-1,
            ),
        ),
        Candidate(
            "lightgbm_lambdarank",
            "ranker",
            lambda: LGBMRanker(
                objective="lambdarank",
                metric="ndcg",
                label_gain=[0, 1],
                n_estimators=360,
                learning_rate=0.035,
                num_leaves=23,
                min_child_samples=45,
                subsample=0.90,
                colsample_bytree=0.90,
                reg_lambda=1.5,
                n_jobs=-1,
                random_state=428,
                verbosity=-1,
            ),
        ),
        Candidate(
            "xgboost_rank_ndcg",
            "ranker",
            lambda: XGBRanker(
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
                random_state=20260924,
            ),
        ),
        Candidate(
            "catboost_query_softmax",
            "catboost_ranker",
            lambda: CatBoostRanker(
                loss_function="QuerySoftMax",
                iterations=260,
                depth=5,
                learning_rate=0.06,
                l2_leaf_reg=8.0,
                random_seed=20260924,
                verbose=False,
                allow_writing_files=False,
                thread_count=-1,
            ),
        ),
        Candidate(
            "catboost_lambdamart",
            "catboost_ranker",
            lambda: CatBoostRanker(
                loss_function="LambdaMart:metric=NDCG;top=1",
                iterations=260,
                depth=5,
                learning_rate=0.06,
                l2_leaf_reg=8.0,
                random_seed=20260924,
                verbose=False,
                allow_writing_files=False,
                thread_count=-1,
            ),
        ),
        Candidate(
            "catboost_yetirank_pairwise",
            "catboost_ranker",
            lambda: CatBoostRanker(
                loss_function="YetiRankPairwise:mode=NDCG;top=1",
                iterations=260,
                depth=5,
                learning_rate=0.06,
                l2_leaf_reg=8.0,
                random_seed=20260924,
                verbose=False,
                allow_writing_files=False,
                thread_count=-1,
            ),
        ),
    ]


def fit(candidate: Candidate, x: np.ndarray, y: np.ndarray, groups: list[int]):
    model = candidate.factory()
    if candidate.kind == "catboost_ranker":
        group_ids = np.repeat(np.arange(len(groups), dtype=np.int32), np.asarray(groups, dtype=np.int32))
        model.fit(Pool(x, label=y, group_id=group_ids))
    elif candidate.kind == "ranker":
        model.fit(x, y, group=groups)
    else:
        model.fit(x, y)
    return model


def raw_scores(candidate: Candidate, model, x: np.ndarray) -> np.ndarray:
    if candidate.kind == "classifier":
        return np.asarray(model.predict_proba(x)[:, 1], dtype=np.float64)
    return np.asarray(model.predict(x), dtype=np.float64)


def actual_metadata(races: list[dict], target: str):
    metadata = []
    offset = 0
    for race in races:
        head, second, _third = race["actual"]
        candidates_ = [b for b in range(1, 7) if b != head]
        if target == "third":
            candidates_ = [b for b in candidates_ if b != second]
        metadata.append((race["race_code"], head, second if target == "third" else None, candidates_, offset, offset + len(candidates_)))
        offset += len(candidates_)
    return metadata


def all_context_matrix(races: list[dict], target: str):
    values = []
    metadata = []
    for race in races:
        for head in range(1, 7):
            seconds = [None] if target == "second" else [b for b in range(1, 7) if b != head]
            for second in seconds:
                candidates_ = [b for b in range(1, 7) if b != head and b != second]
                start = len(values)
                values.extend([
                    vector(
                        race["boats"][boat],
                        race["boats"][head],
                        race["race_number"],
                        race["place_id"],
                        race["boats"][second] if second is not None else None,
                    )
                    for boat in candidates_
                ])
                metadata.append((race["race_code"], head, second, candidates_, start, len(values)))
    return np.vstack(values), metadata


def base_flat(races_by_code: dict[str, dict], metadata) -> np.ndarray:
    values = np.zeros(metadata[-1][-1], dtype=np.float64)
    for code, _head, _second, boats, start, end in metadata:
        raw = np.asarray([
            max(float(races_by_code[code]["boats"][boat]["v5_probability"]), EPS)
            for boat in boats
        ], dtype=np.float64)
        values[start:end] = raw / raw.sum()
    return values


def probabilities(raw: np.ndarray, metadata, kind: str, temperature: float) -> np.ndarray:
    out = np.zeros_like(raw, dtype=np.float64)
    for _code, _head, _second, _boats, start, end in metadata:
        group = raw[start:end]
        if kind == "classifier":
            logits = np.log(np.maximum(group, EPS)) / temperature
        else:
            logits = group / temperature
        logits -= logits.max()
        exp = np.exp(logits)
        out[start:end] = exp / exp.sum()
    return out


def blend_flat(base: np.ndarray, ml: np.ndarray, metadata, alpha: float) -> np.ndarray:
    out = np.zeros_like(base, dtype=np.float64)
    for _code, _head, _second, _boats, start, end in metadata:
        score = (1.0 - alpha) * np.log(np.maximum(base[start:end], EPS)) + alpha * np.log(np.maximum(ml[start:end], EPS))
        score -= score.max()
        exp = np.exp(score)
        out[start:end] = exp / exp.sum()
    return out


def conditional_metrics(races: list[dict], metadata, flat: np.ndarray, target: str) -> dict:
    losses = []
    briers = []
    ranks = []
    for race, (_code, _head, _second, boats, start, end) in zip(races, metadata):
        actual = race["actual"][1 if target == "second" else 2]
        row = flat[start:end]
        index = boats.index(actual)
        losses.append(-math.log(max(float(row[index]), EPS)))
        labels = np.zeros(len(boats), dtype=np.float64)
        labels[index] = 1.0
        briers.append(float(np.mean((row - labels) ** 2)))
        order = sorted(range(len(boats)), key=lambda i: (-float(row[i]), boats[i]))
        ranks.append(order.index(index) + 1)
    ranks_array = np.asarray(ranks)
    return {
        "n": len(races),
        "nll": float(np.mean(losses)),
        "brier": float(np.mean(briers)),
        "top1": float(np.mean(ranks_array <= 1)),
        "top2": float(np.mean(ranks_array <= 2)),
        "top3": float(np.mean(ranks_array <= 3)),
    }


def tune(races: list[dict], target: str, candidate: Candidate, raw: np.ndarray, metadata, base: np.ndarray):
    best = None
    for temperature in TEMPERATURES:
        ml = probabilities(raw, metadata, candidate.kind, temperature)
        for alpha in ALPHAS:
            pred = blend_flat(base, ml, metadata, alpha)
            metric = conditional_metrics(races, metadata, pred, target)
            key = (metric["nll"], metric["brier"], -metric["top1"])
            if best is None or key < best[0]:
                best = (key, temperature, alpha, metric)
    return best[1], best[2], best[3]


def context_maps(metadata, flat: np.ndarray):
    out = {}
    for code, head, second, boats, start, end in metadata:
        out[(code, head, second)] = {boat: float(p) for boat, p in zip(boats, flat[start:end])}
    return out


def joint_metrics(races: list[dict], second_map: dict, third_map: dict) -> dict:
    exacta_nll = []
    trifecta_nll = []
    exacta_brier = []
    trifecta_brier = []
    exacta_ranks = []
    trifecta_ranks = []
    for race in races:
        code = race["race_code"]
        actual = tuple(race["actual"])
        p1_values = {
            boat: max(float(race["boats"][boat]["v5_probability"]), EPS)
            for boat in range(1, 7)
        }
        p1_total = sum(p1_values.values())
        p1_values = {boat: value / p1_total for boat, value in p1_values.items()}
        exacta = {}
        trifecta = {}
        for head in range(1, 7):
            p2s = second_map[(code, head, None)]
            for second, p2 in p2s.items():
                exacta[(head, second)] = p1_values[head] * p2
                p3s = third_map[(code, head, second)]
                for third, p3 in p3s.items():
                    trifecta[(head, second, third)] = p1_values[head] * p2 * p3

        actual_exacta = actual[:2]
        exacta_nll.append(-math.log(max(exacta[actual_exacta], EPS)))
        trifecta_nll.append(-math.log(max(trifecta[actual], EPS)))
        exacta_brier.append(sum((p - (1.0 if key == actual_exacta else 0.0)) ** 2 for key, p in exacta.items()))
        trifecta_brier.append(sum((p - (1.0 if key == actual else 0.0)) ** 2 for key, p in trifecta.items()))
        exacta_order = sorted(exacta, key=lambda key: (-exacta[key], key))
        trifecta_order = sorted(trifecta, key=lambda key: (-trifecta[key], key))
        exacta_ranks.append(exacta_order.index(actual_exacta) + 1)
        trifecta_ranks.append(trifecta_order.index(actual) + 1)

    exacta_rank = np.asarray(exacta_ranks)
    trifecta_rank = np.asarray(trifecta_ranks)
    return {
        "n": len(races),
        "exacta_nll": float(np.mean(exacta_nll)),
        "exacta_brier_sum": float(np.mean(exacta_brier)),
        "exacta_top1": float(np.mean(exacta_rank <= 1)),
        "exacta_top3": float(np.mean(exacta_rank <= 3)),
        "exacta_top5": float(np.mean(exacta_rank <= 5)),
        "trifecta_nll": float(np.mean(trifecta_nll)),
        "trifecta_brier_sum": float(np.mean(trifecta_brier)),
        "trifecta_top1": float(np.mean(trifecta_rank <= 1)),
        "trifecta_top5": float(np.mean(trifecta_rank <= 5)),
        "trifecta_top10": float(np.mean(trifecta_rank <= 10)),
        "trifecta_top20": float(np.mean(trifecta_rank <= 20)),
    }


def run_target(train: list[dict], valid: list[dict], test: list[dict], target: str):
    x_train, y_train, groups = v1.matrices(train, target)
    x_valid_actual, _y_valid, _g_valid = v1.matrices(valid, target)
    x_test_actual, _y_test, _g_test = v1.matrices(test, target)
    x_valid, valid_meta_all = all_context_matrix(valid, target)
    x_test, test_meta_all = all_context_matrix(test, target)
    valid_actual_meta = actual_metadata(valid, target)
    test_actual_meta = actual_metadata(test, target)
    valid_by_code = {race["race_code"]: race for race in valid}
    test_by_code = {race["race_code"]: race for race in test}
    valid_base_all = base_flat(valid_by_code, valid_meta_all)
    test_base_all = base_flat(test_by_code, test_meta_all)
    valid_base_actual = base_flat(valid_by_code, valid_actual_meta)
    test_base_actual = base_flat(test_by_code, test_actual_meta)

    results = {
        "plackett_luce_v5": {
            "temperature": None,
            "alpha": 0.0,
            "valid": conditional_metrics(valid, valid_actual_meta, valid_base_actual, target),
            "test": conditional_metrics(test, test_actual_meta, test_base_actual, target),
        }
    }
    context_predictions = {
        "valid": {"plackett_luce_v5": valid_base_all},
        "test": {"plackett_luce_v5": test_base_all},
    }

    for candidate in candidates():
        started = time.monotonic()
        print(f"  {target}: {candidate.name} 学習中…", flush=True)
        model = fit(candidate, x_train, y_train, groups)
        valid_raw_all = raw_scores(candidate, model, x_valid)
        test_raw_all = raw_scores(candidate, model, x_test)

        # actual contextはall-context配列から同じ順番で抜かず、専用行列で安全に採点する。
        valid_raw_actual = raw_scores(candidate, model, x_valid_actual)
        test_raw_actual = raw_scores(candidate, model, x_test_actual)
        temperature, alpha, valid_metric = tune(
            valid, target, candidate, valid_raw_actual, valid_actual_meta, valid_base_actual
        )
        valid_ml_all = probabilities(valid_raw_all, valid_meta_all, candidate.kind, temperature)
        test_ml_all = probabilities(test_raw_all, test_meta_all, candidate.kind, temperature)
        valid_pred_all = blend_flat(valid_base_all, valid_ml_all, valid_meta_all, alpha)
        test_pred_all = blend_flat(test_base_all, test_ml_all, test_meta_all, alpha)
        test_ml_actual = probabilities(test_raw_actual, test_actual_meta, candidate.kind, temperature)
        test_pred_actual = blend_flat(test_base_actual, test_ml_actual, test_actual_meta, alpha)
        results[candidate.name] = {
            "kind": candidate.kind,
            "temperature": temperature,
            "alpha": alpha,
            "fit_seconds": time.monotonic() - started,
            "valid": valid_metric,
            "test": conditional_metrics(test, test_actual_meta, test_pred_actual, target),
        }
        context_predictions["valid"][candidate.name] = valid_pred_all
        context_predictions["test"][candidate.name] = test_pred_all
        print(
            f"    VALID NLL={valid_metric['nll']:.6f} Br={valid_metric['brier']:.6f} "
            f"T1={valid_metric['top1']*100:.2f}% / T={temperature:.2f} a={alpha:.2f}",
            flush=True,
        )
    return results, context_predictions, valid_meta_all, test_meta_all


def main() -> int:
    print("AI2着率・AI3着率 モデル大会データを構築中…", flush=True)
    races, _place_to_id, skip = v1.build_races()
    train = [r for r in races if r["race_date"] <= v1.TRAIN_END]
    valid = [r for r in races if v1.VALID_START <= r["race_date"] <= v1.VALID_END]
    test = [r for r in races if v1.TEST_START <= r["race_date"] <= v1.TEST_END]
    print(f"ready={len(races)} train={len(train)} valid={len(valid)} test={len(test)} skip={skip}", flush=True)

    second, second_context, second_valid_meta, second_test_meta = run_target(train, valid, test, "second")
    third, third_context, third_valid_meta, third_test_meta = run_target(train, valid, test, "third")

    valid_joint = {}
    test_joint = {}
    model_names = list(second)
    # 同一モデル同士と、VALID条件付き上位3モデルの交差を候補にする。
    pairs = {(name, name) for name in model_names}
    top_second = sorted(second, key=lambda name: (second[name]["valid"]["nll"], second[name]["valid"]["brier"]))[:3]
    top_third = sorted(third, key=lambda name: (third[name]["valid"]["nll"], third[name]["valid"]["brier"]))[:3]
    pairs.update((a, b) for a in top_second for b in top_third)

    for second_name, third_name in sorted(pairs):
        key = f"{second_name}+{third_name}"
        valid_joint[key] = joint_metrics(
            valid,
            context_maps(second_valid_meta, second_context["valid"][second_name]),
            context_maps(third_valid_meta, third_context["valid"][third_name]),
        )

    selected_pair = min(
        valid_joint,
        key=lambda key: (
            valid_joint[key]["trifecta_nll"],
            valid_joint[key]["trifecta_brier_sum"],
            -valid_joint[key]["trifecta_top10"],
        ),
    )
    # TESTは選択済みペアと同一モデルペアを報告する。採用判断はselected_pairだけで行う。
    test_keys = {selected_pair} | {f"{name}+{name}" for name in model_names}
    for key in sorted(test_keys):
        second_name, third_name = key.split("+", 1)
        test_joint[key] = joint_metrics(
            test,
            context_maps(second_test_meta, second_context["test"][second_name]),
            context_maps(third_test_meta, third_context["test"][third_name]),
        )

    report = {
        "version": "ai_place_model_tournament_v1",
        "data": {
            "ready": len(races),
            "train": len(train),
            "valid": len(valid),
            "test": len(test),
            "train_end": v1.TRAIN_END.isoformat(),
            "valid_period": [v1.VALID_START.isoformat(), v1.VALID_END.isoformat()],
            "test_period": [v1.TEST_START.isoformat(), v1.TEST_END.isoformat()],
            "skip": skip,
        },
        "second": second,
        "third": third,
        "joint": {
            "valid": valid_joint,
            "selected_pair": selected_pair,
            "test": test_joint,
        },
    }
    REPORT_PATH.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")

    print("\n【条件付き2着 TEST】")
    for name in sorted(second, key=lambda n: second[n]["test"]["nll"]):
        row = second[name]["test"]
        print(f"{name:<25} NLL={row['nll']:.6f} Br={row['brier']:.6f} T1={row['top1']*100:.2f}%")
    print("\n【条件付き3着 TEST】")
    for name in sorted(third, key=lambda n: third[n]["test"]["nll"]):
        row = third[name]["test"]
        print(f"{name:<25} NLL={row['nll']:.6f} Br={row['brier']:.6f} T1={row['top1']*100:.2f}%")
    print(f"\nVALIDで選択した組合せ: {selected_pair}")
    selected = test_joint[selected_pair]
    print(
        f"TEST 2連単 NLL={selected['exacta_nll']:.6f} Top1={selected['exacta_top1']*100:.2f}% / "
        f"3連単 NLL={selected['trifecta_nll']:.6f} Top1={selected['trifecta_top1']*100:.2f}% "
        f"Top10={selected['trifecta_top10']*100:.2f}%"
    )
    print(f"保存: {REPORT_PATH}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
