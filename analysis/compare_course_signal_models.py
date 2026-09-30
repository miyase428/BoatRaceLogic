#!/usr/bin/env python3
"""コースサイン用の固定データセットで4モデルを公平に比較する。

モデル選択・特徴量選択・閾値選択は行わない。既存の point-in-time
データセット生成器が返す展示込み170特徴量を、全36ケースへそのまま渡す。
VALID / TEST は評価専用であり、TEST はいかなる設定変更にも使わない。
"""

from __future__ import annotations

import argparse
import csv
import gc
import json
import platform
import resource
import shutil
import subprocess
import sys
import time
from datetime import date
from pathlib import Path

import numpy as np
import sklearn

# --foundation-tabdpt は既存4モデルの保存済み成果物を読むだけでよい。
# 重い任意依存を持たない隔離環境でも、その経路だけを実行できるようにする。
try:
    from catboost import CatBoostClassifier
except ImportError:  # pragma: no cover - checked by the four-model path
    CatBoostClassifier = None
try:
    from flaml import AutoML
except ImportError:  # pragma: no cover - checked by the four-model path
    AutoML = None
try:
    from interpret.glassbox import ExplainableBoostingClassifier
except ImportError:  # pragma: no cover - checked by the four-model path
    ExplainableBoostingClassifier = None
try:
    import pandas as pd
    from autogluon.tabular import TabularPredictor
except ImportError:  # pragma: no cover - checked by the AutoGluon path
    pd = None
    TabularPredictor = None
try:
    from ngboost import NGBClassifier
    from ngboost.distns import Bernoulli
except ImportError:  # pragma: no cover - checked by the NGBoost path
    NGBClassifier = None
    Bernoulli = None
from sklearn.base import clone
from sklearn.impute import SimpleImputer
from sklearn.metrics import brier_score_loss, log_loss, roc_auc_score

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "analysis"))

import audit_tamagawa_course_signals_zero_base_ml as audit  # noqa: E402


PLACES = ("ASY", "AMG")
COURSES = (1, 2, 3, 4, 5, 6)
TARGETS = ("first", "top2", "top3")
TRAIN_END = date(2025, 8, 31)
VALID_START = date(2025, 9, 1)
VALID_END = date(2026, 2, 28)
TEST_START = date(2026, 3, 1)
END = date(2026, 9, 27)
RANDOM_SEED = 20260927

# 既存コースサインMLの audit.make_models()['hist_gradient'] をそのまま使う。
HGB_PARAMETERS = {
    "learning_rate": 0.045,
    "max_iter": 220,
    "max_leaf_nodes": 15,
    "min_samples_leaf": 35,
    "l2_regularization": 1.0,
    "random_state": RANDOM_SEED,
    "missing_values": "SimpleImputer(strategy=median, add_indicator=True)",
}

# CPU 4 core / 約8GBを前提にした固定の控えめな1セット。探索は行わない。
# 入力は既存と同じ170個の連続値のみで、カテゴリ列への意味変更はない。
CATBOOST_PARAMETERS = {
    "loss_function": "Logloss",
    "iterations": 300,
    "depth": 6,
    "learning_rate": 0.05,
    "l2_leaf_reg": 5.0,
    "random_seed": RANDOM_SEED,
    "thread_count": 4,
    "allow_writing_files": False,
    "verbose": False,
    "task_type": "CPU",
    "missing_values": "CatBoost native NaN handling",
    "categorical_features": "none (all existing inputs are numeric)",
}

# FLAMLは、TRAINを学習、固定VALIDをAutoMLの選択・early stopping専用に使う。
# Brierを安全に直接渡せないため、内部選択指標はlog_lossとし、BrierはVALID/TESTで
# 他モデルと同じ評価関数として算出する。TESTはfitへ一切渡さない。
FLAML_PARAMETERS = {
    "task": "classification",
    "metric": "log_loss",
    "estimator_list": ["lgbm", "xgboost"],
    "time_budget": 15,
    "max_iter": 40,
    "n_jobs": 4,
    "seed": RANDOM_SEED,
    "verbose": 0,
    "retrain_full": False,
    "eval_method": "holdout",
}

# 解釈性を保ちつつ4 core / 約8GBで36ケースを継続実行できる固定設定。
# この値は全ケース共通であり、VALID/TESTを見た個別調整はしていない。
EBM_PARAMETERS = {
    "max_bins": 256,
    "max_interaction_bins": 32,
    "interactions": 10,
    "outer_bags": 4,
    "inner_bags": 0,
    "learning_rate": 0.02,
    "max_rounds": 1500,
    "early_stopping_rounds": 100,
    "min_samples_leaf": 10,
    "validation_size": 0.15,
    "n_jobs": 4,
    "random_state": RANDOM_SEED,
    "objective": "log_loss",
}

# TabDPT v1.2.0 is the official TabDPT-Turbo release. CPU設定だけを明示し、
# 公式の標準前処理（PCA feature reduction / context subsample）は変更しない。
TABDPT_TURBO_PARAMETERS = {
    "normalizer": "standard",
    "missing_indicators": False,
    "clip_sigma": 8.0,
    "feature_reduction": "pca",
    "context_reduction": "subsample",
    "device": "cpu",
    "use_flash": False,
    "compile": False,
    "verbose": False,
    "predict_seed": RANDOM_SEED,
}

# AutoGluon 1.6系の medium_quality preset をベースに、今回の比較対象を
# CPU向けの標準ツリー系Tabular ensembleへ明示的に限定する。foundation model、
# GPU専用モデル、NLP/画像モデルは含めない。固定VALIDだけをモデル選択・ensemble
# 構成に使い、TESTは fit に渡さない。
AUTOGLUON_PARAMETERS = {
    "preset": "medium_quality",
    "time_limit_seconds": 60,
    "eval_metric": "log_loss",
    "num_cpus": 4,
    "num_gpus": 0,
    "fit_strategy": "sequential",
    "hyperparameters": {
        "GBM": {},
        "CAT": {},
        "XGB": {},
        "RF": {},
        "XT": {},
    },
    "excluded_models": ["TABPFN", "TABICL", "TABDPT", "TABDPT-TURBO"],
    # AutoGluon 1.6の全標準モデルに共通で渡せる乱数引数はなく、frameworkの
    # 再現可能な既定値0を使用する（leaderboardの各モデル設定にも記録される）。
    "random_seed": 0,
}

# NGBoost v0.5.11のBernoulli分類。CPU上の固定設定で、early stoppingは
# 固定VALIDのみを参照する。170特徴の情報量は変えず、TRAINでfitするmedian補完
# だけを前処理に使う。
NGBOOST_PARAMETERS = {
    "Dist": "Bernoulli",
    "n_estimators": 500,
    "learning_rate": 0.01,
    "minibatch_frac": 1.0,
    "col_sample": 1.0,
    "natural_gradient": True,
    "validation_fraction": 0.0,
    "early_stopping_rounds": 50,
    "random_state": RANDOM_SEED,
    "verbose": False,
}
AUTOGLUON_ROOT = Path("/tmp/boatrace-autogluon-models")


def git_sha() -> str:
    try:
        return subprocess.check_output(
            ["git", "rev-parse", "HEAD"], cwd=ROOT, text=True
        ).strip()
    except (OSError, subprocess.CalledProcessError):
        return "unknown"


def as_matrix(rows: list[dict], features: list[str]) -> np.ndarray:
    return audit.matrix(rows, features)


def metrics(y: np.ndarray, probability: np.ndarray) -> dict[str, float | int | None]:
    clipped = np.clip(probability, 1e-9, 1 - 1e-9)
    return {
        "n": int(len(y)),
        "positive_rate": float(np.mean(y)),
        "probability_mean": float(np.mean(probability)),
        "brier": float(brier_score_loss(y, probability)),
        "log_loss": float(log_loss(y, clipped, labels=[0, 1])),
        "auc": float(roc_auc_score(y, probability)) if len(np.unique(y)) == 2 else None,
        "accuracy_0_5": float(np.mean((probability >= 0.5) == y)),
    }


def fit_predict(model, x_train: np.ndarray, y_train: np.ndarray, x_valid: np.ndarray, x_test: np.ndarray):
    started = time.perf_counter()
    model.fit(x_train, y_train)
    fit_seconds = time.perf_counter() - started
    started = time.perf_counter()
    valid_probability = model.predict_proba(x_valid)[:, 1]
    test_probability = model.predict_proba(x_test)[:, 1]
    predict_seconds = time.perf_counter() - started
    return valid_probability, test_probability, fit_seconds, predict_seconds


def monthly_rows(
    place: str,
    course: int,
    target: str,
    test_rows: list[dict],
    y_test: np.ndarray,
    hgb_probability: np.ndarray,
    catboost_probability: np.ndarray,
) -> list[dict]:
    months = np.asarray([row["date"].strftime("%Y-%m") for row in test_rows])
    result: list[dict] = []
    for month in sorted(set(months.tolist())):
        mask = months == month
        hgb_brier = float(brier_score_loss(y_test[mask], hgb_probability[mask]))
        catboost_brier = float(brier_score_loss(y_test[mask], catboost_probability[mask]))
        delta = catboost_brier - hgb_brier
        result.append({
            "place": place,
            "course": course,
            "target": target,
            "month": month,
            "n": int(mask.sum()),
            "positive_rate": float(np.mean(y_test[mask])),
            "hgb_brier": hgb_brier,
            "catboost_brier": catboost_brier,
            "catboost_minus_hgb_brier": delta,
            "winner": "catboost" if delta < 0 else "hgb" if delta > 0 else "tie",
        })
    return result


def split(rows: list[dict]) -> tuple[list[dict], list[dict], list[dict]]:
    train = [row for row in rows if row["date"] <= TRAIN_END]
    valid = [row for row in rows if VALID_START <= row["date"] <= VALID_END]
    test = [row for row in rows if TEST_START <= row["date"] <= END]
    return train, valid, test


def write_csv(path: Path, rows: list[dict]) -> None:
    if not rows:
        raise RuntimeError(f"出力行がありません: {path}")
    with path.open("w", newline="", encoding="utf-8") as handle:
        writer = csv.DictWriter(handle, fieldnames=list(rows[0]), lineterminator="\n")
        writer.writeheader()
        writer.writerows(rows)


def average(rows: list[dict], key: str) -> float:
    return float(np.mean([float(row[key]) for row in rows]))


def aggregate(rows: list[dict], keys: tuple[str, ...]) -> list[dict]:
    groups: dict[tuple, list[dict]] = {}
    for row in rows:
        group_key = tuple(row[key] for key in keys)
        groups.setdefault(group_key, []).append(row)
    result = []
    for group_key, values in sorted(groups.items()):
        result.append({
            **dict(zip(keys, group_key)),
            "cases": len(values),
            "catboost_test_improved_cases": sum(row["test_winner"] == "catboost" for row in values),
            "hgb_test_improved_cases": sum(row["test_winner"] == "hgb" for row in values),
            "ties": sum(row["test_winner"] == "tie" for row in values),
            "mean_test_brier_delta": average(values, "test_catboost_minus_hgb_brier"),
            "mean_valid_brier_delta": average(values, "valid_catboost_minus_hgb_brier"),
        })
    return result


def percent(value: float | None) -> str:
    return "-" if value is None else f"{value * 100:.3f}%"


def write_markdown(path: Path, experiment: dict, summary: list[dict], monthly: list[dict]) -> None:
    test_catboost = sum(row["test_winner"] == "catboost" for row in summary)
    valid_catboost = sum(row["valid_winner"] == "catboost" for row in summary)
    lines = [
        "# コースサインML正式比較: HGB vs CatBoost",
        "",
        "## 結論",
        "",
        f"- TEST BrierでCatBoost改善: **{test_catboost}/36**",
        f"- VALID BrierでCatBoost改善: **{valid_catboost}/36**",
        f"- TEST Brier平均差（CatBoost - HGB）: **{average(summary, 'test_catboost_minus_hgb_brier'):.8f}**",
        "- 負の差がCatBoost改善。TESTは評価専用であり、モデル・特徴量・パラメータを変更していない。",
        "",
        "## 固定実験条件",
        "",
        f"- Git: `{experiment['git_sha']}`",
        f"- 実行環境: Python {experiment['runtime']['python']} / scikit-learn {experiment['runtime']['scikit_learn']} / CatBoost {experiment['runtime']['catboost']} / NumPy {experiment['runtime']['numpy']}",
        f"- 対象: ASY・AMG、各1〜6C × first/top2/top3 = 36ケース",
        f"- 期間: TRAIN {experiment['period']['train']} / VALID {experiment['period']['valid']} / TEST {experiment['period']['test']}",
        f"- 特徴量: 展示込み {experiment['feature_count']}項目。既存 `audit_tamagawa_course_signals_zero_base_ml.build_dataset()` の `post_features` を全ケース固定で使用。",
        "- HGB: 既存コースサインMLの固定設定をそのまま使用。",
        "- CatBoost: CPU、300 iterations、depth 6、learning rate 0.05、L2 5.0、seed 20260927、4 threads。探索・early stopping・class weight・確率校正なし。",
        f"- 入力は両モデルとも同じ連続値{experiment['feature_count']}項目。CatBoostのカテゴリ機能は使わない。",
        "",
        "## データリーク確認",
        "",
        "- 着順・結果は first/top2/top3 のラベル作成のみに使い、特徴量には未使用。払戻・オッズも未使用。",
        "- course は展示進入を優先し、展示がなければ枠番へfallbackする既存事前情報経路。",
        "- 決まり手履歴は対象レース日より前の12か月、展示タイム基準は当日を含めない同場直近183日で算出する既存point-in-time実装を再利用。",
        "- 選手・機力値は race_code単位の既存事前テーブルを使用。今回、新たな結果結合や後知恵集計は追加していない。",
        "",
        "## 36ケース",
        "",
        "|場|C|target|TRAIN|VALID|TEST|VALID HGB|VALID CatBoost|VALID差|TEST HGB|TEST CatBoost|TEST差|TEST勝者|",
        "|---|---:|---|---:|---:|---:|---:|---:|---:|---:|---:|---|",
    ]
    for row in summary:
        lines.append(
            f"|{row['place']}|{row['course']}|{row['target']}|{row['train_n']}|{row['valid_n']}|{row['test_n']}|"
            f"{row['valid_hgb_brier']:.6f}|{row['valid_catboost_brier']:.6f}|{row['valid_catboost_minus_hgb_brier']:+.6f}|"
            f"{row['test_hgb_brier']:.6f}|{row['test_catboost_brier']:.6f}|{row['test_catboost_minus_hgb_brier']:+.6f}|{row['test_winner']}|"
        )
    for title, keys in (("場別", ("place",)), ("コース別", ("course",)), ("target別", ("target",))):
        lines += ["", f"## {title}集計", "", "|区分|ケース|CatBoost改善|HGB改善|同率|平均TEST差|平均VALID差|", "|---|---:|---:|---:|---:|---:|---:|"]
        for row in aggregate(summary, keys):
            label = " / ".join(str(row[key]) for key in keys)
            lines.append(
                f"|{label}|{row['cases']}|{row['catboost_test_improved_cases']}|{row['hgb_test_improved_cases']}|{row['ties']}|"
                f"{row['mean_test_brier_delta']:+.8f}|{row['mean_valid_brier_delta']:+.8f}|"
            )
    monthly_catboost = sum(row["winner"] == "catboost" for row in monthly)
    monthly_hgb = sum(row["winner"] == "hgb" for row in monthly)
    lines += [
        "",
        "## 月別安定性",
        "",
        f"- case×月の比較: CatBoost勝ち {monthly_catboost}、HGB勝ち {monthly_hgb}、同率 {len(monthly) - monthly_catboost - monthly_hgb}。",
        "- 詳細は `course_signal_model_compare_asy_amg_20260927_monthly.csv`。月別勝敗は小標本のため参考値である。",
        "",
        "## 実行負荷",
        "",
        f"- プロセス最大RSS: {experiment['runtime']['max_rss_kb']:,} KB",
        f"- 総実行時間: {experiment['runtime']['elapsed_seconds']:.2f} 秒",
        "- 個別fit・推論時間はsummary CSVに記録。",
        "",
        "## 次段階への注意",
        "",
        "この結果は本番置換の判断ではない。AI1着率v6との直接対決、確率校正、ブレンド、他モデル、race_number追加は今回の対象外。",
    ]
    path.write_text("\n".join(lines) + "\n", encoding="utf-8")


def legacy_main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output-stem", default="course_signal_model_compare_asy_amg_20260927")
    args = parser.parse_args()

    output_dir = ROOT / "analysis" / "output"
    output_dir.mkdir(parents=True, exist_ok=True)
    config = json.loads((ROOT / "config" / "course_signal_rules.json").read_text(encoding="utf-8"))
    all_start = time.perf_counter()
    summary: list[dict] = []
    monthly: list[dict] = []
    dataset_audit: dict[str, dict] = {}
    canonical_features: list[str] | None = None

    for place in PLACES:
        audit.PLACE = place
        records, _pre_features, post_features = audit.build_dataset(date(2023, 9, 27), END, config)
        dataset_audit[place] = dict(audit.LAST_DATASET_AUDIT)
        if canonical_features is None:
            canonical_features = list(post_features)
        elif canonical_features != post_features:
            raise RuntimeError("場ごとに展示込み特徴量一覧が異なります")

        for course in COURSES:
            course_rows = [row for row in records if row["course"] == course]
            train, valid, test = split(course_rows)
            if not train or not valid or not test:
                raise RuntimeError(f"{place} {course}C: splitが空です")
            x_train, x_valid, x_test = (as_matrix(rows, post_features) for rows in (train, valid, test))
            for target in TARGETS:
                y_train = np.asarray([row[target] for row in train], dtype=np.int8)
                y_valid = np.asarray([row[target] for row in valid], dtype=np.int8)
                y_test = np.asarray([row[target] for row in test], dtype=np.int8)
                if len(np.unique(y_train)) < 2:
                    raise RuntimeError(f"{place} {course}C {target}: TRAINラベルが単一です")

                hgb = clone(audit.make_models()["hist_gradient"])
                hgb_valid, hgb_test, hgb_fit, hgb_predict = fit_predict(hgb, x_train, y_train, x_valid, x_test)
                catboost = CatBoostClassifier(**{key: value for key, value in CATBOOST_PARAMETERS.items() if key in {
                    "loss_function", "iterations", "depth", "learning_rate", "l2_leaf_reg", "random_seed",
                    "thread_count", "allow_writing_files", "verbose", "task_type",
                }})
                cat_valid, cat_test, cat_fit, cat_predict = fit_predict(catboost, x_train, y_train, x_valid, x_test)

                hgb_valid_metrics = metrics(y_valid, hgb_valid)
                cat_valid_metrics = metrics(y_valid, cat_valid)
                hgb_test_metrics = metrics(y_test, hgb_test)
                cat_test_metrics = metrics(y_test, cat_test)
                valid_delta = float(cat_valid_metrics["brier"] - hgb_valid_metrics["brier"])
                test_delta = float(cat_test_metrics["brier"] - hgb_test_metrics["brier"])
                summary.append({
                    "place": place,
                    "course": course,
                    "target": target,
                    "feature_set": "post_exhibition",
                    "feature_count": len(post_features),
                    "train_n": len(train),
                    "valid_n": len(valid),
                    "test_n": len(test),
                    "train_positive_rate": float(np.mean(y_train)),
                    "valid_positive_rate": hgb_valid_metrics["positive_rate"],
                    "test_positive_rate": hgb_test_metrics["positive_rate"],
                    "valid_hgb_brier": hgb_valid_metrics["brier"],
                    "valid_catboost_brier": cat_valid_metrics["brier"],
                    "valid_catboost_minus_hgb_brier": valid_delta,
                    "valid_winner": "catboost" if valid_delta < 0 else "hgb" if valid_delta > 0 else "tie",
                    "test_hgb_brier": hgb_test_metrics["brier"],
                    "test_catboost_brier": cat_test_metrics["brier"],
                    "test_catboost_minus_hgb_brier": test_delta,
                    "test_winner": "catboost" if test_delta < 0 else "hgb" if test_delta > 0 else "tie",
                    "test_hgb_log_loss": hgb_test_metrics["log_loss"],
                    "test_catboost_log_loss": cat_test_metrics["log_loss"],
                    "test_hgb_auc": hgb_test_metrics["auc"],
                    "test_catboost_auc": cat_test_metrics["auc"],
                    "test_hgb_accuracy_0_5": hgb_test_metrics["accuracy_0_5"],
                    "test_catboost_accuracy_0_5": cat_test_metrics["accuracy_0_5"],
                    "test_hgb_probability_mean": hgb_test_metrics["probability_mean"],
                    "test_catboost_probability_mean": cat_test_metrics["probability_mean"],
                    "hgb_fit_seconds": hgb_fit,
                    "catboost_fit_seconds": cat_fit,
                    "hgb_predict_seconds": hgb_predict,
                    "catboost_predict_seconds": cat_predict,
                })
                monthly.extend(monthly_rows(place, course, target, test, y_test, hgb_test, cat_test))
                print(
                    f"{place} {course}C {target}: TEST ΔBrier={test_delta:+.8f} "
                    f"({'CatBoost' if test_delta < 0 else 'HGB' if test_delta > 0 else 'tie'})",
                    flush=True,
                )

    assert canonical_features is not None
    elapsed = time.perf_counter() - all_start
    import catboost  # local import keeps the metadata name explicit
    experiment = {
        "experiment": "course_signal_model_compare_hgb_vs_catboost",
        "created_at": time.strftime("%Y-%m-%dT%H:%M:%S%z"),
        "git_sha": git_sha(),
        "places": list(PLACES),
        "courses": list(COURSES),
        "targets": list(TARGETS),
        "period": {
            "dataset_start": "2023-09-27",
            "train": "2023-09-27 to 2025-08-31",
            "valid": "2025-09-01 to 2026-02-28",
            "test": "2026-03-01 to 2026-09-27",
        },
        "feature_set": "post_exhibition",
        "feature_count": len(canonical_features),
        "features": canonical_features,
        "hgb_parameters": HGB_PARAMETERS,
        "catboost_parameters": CATBOOST_PARAMETERS,
        "random_seed": RANDOM_SEED,
        "dataset_audit": dataset_audit,
        "leakage_checks": {
            "results_only_used_as_labels": True,
            "payouts_and_odds_not_used": True,
            "point_in_time_history": "TechniqueHistoryIndex profiles before each race date (12 months)",
            "point_in_time_exhibition_average": "same venue trailing 183 days, current date excluded",
            "course_definition": "exhibition entry course, otherwise race_entry lane number",
        },
        "runtime": {
            "python": platform.python_version(),
            "scikit_learn": sklearn.__version__,
            "catboost": catboost.__version__,
            "numpy": np.__version__,
            "max_rss_kb": int(resource.getrusage(resource.RUSAGE_SELF).ru_maxrss),
            "elapsed_seconds": elapsed,
        },
    }
    stem = str(args.output_stem)
    write_csv(output_dir / f"{stem}_summary.csv", summary)
    write_csv(output_dir / f"{stem}_monthly.csv", monthly)
    (output_dir / f"{stem}_experiment.json").write_text(
        json.dumps(experiment, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    write_markdown(output_dir / f"{stem}.md", experiment, summary, monthly)
    print(f"summary: {output_dir / f'{stem}_summary.csv'}", flush=True)
    print(f"monthly: {output_dir / f'{stem}_monthly.csv'}", flush=True)
    print(f"report: {output_dir / f'{stem}.md'}", flush=True)


def csv_rows(path: Path) -> list[dict]:
    with path.open(encoding="utf-8", newline="") as handle:
        return list(csv.DictReader(handle))


def load_baseline(output_dir: Path, stem: str) -> tuple[dict[tuple[str, int, str], dict], list[dict], dict]:
    """前回正式HGB/CatBoost成果物を読み、比較条件が同一であることを検査する。"""
    summary_path = output_dir / f"{stem}_summary.csv"
    monthly_path = output_dir / f"{stem}_monthly.csv"
    experiment_path = output_dir / f"{stem}_experiment.json"
    if not summary_path.exists() or not monthly_path.exists() or not experiment_path.exists():
        raise RuntimeError(f"前回の正式比較成果物が不足しています: {stem}")
    experiment = json.loads(experiment_path.read_text(encoding="utf-8"))
    if experiment.get("feature_count") != 170 or experiment.get("period", {}).get("train") != "2023-09-27 to 2025-08-31":
        raise RuntimeError("前回成果物の特徴量数または期間が今回の固定条件と一致しません")
    summary = csv_rows(summary_path)
    if len(summary) != 36:
        raise RuntimeError(f"前回summaryのケース数が36ではありません: {len(summary)}")
    indexed = {(row["place"], int(row["course"]), row["target"]): row for row in summary}
    if len(indexed) != 36:
        raise RuntimeError("前回summaryに重複ケースがあります")
    return indexed, csv_rows(monthly_path), experiment


def fit_flaml(
    x_train: np.ndarray,
    y_train: np.ndarray,
    x_valid: np.ndarray,
    y_valid: np.ndarray,
    x_test: np.ndarray,
) -> tuple[np.ndarray, np.ndarray, float, float, str]:
    model = AutoML()
    started = time.perf_counter()
    model.fit(X_train=x_train, y_train=y_train, X_val=x_valid, y_val=y_valid, **FLAML_PARAMETERS)
    fit_seconds = time.perf_counter() - started
    started = time.perf_counter()
    valid_probability = model.predict_proba(x_valid)[:, 1]
    test_probability = model.predict_proba(x_test)[:, 1]
    predict_seconds = time.perf_counter() - started
    return valid_probability, test_probability, fit_seconds, predict_seconds, str(model.best_estimator)


def fit_ebm(
    x_train: np.ndarray,
    y_train: np.ndarray,
    x_valid: np.ndarray,
    x_test: np.ndarray,
    feature_names: list[str],
) -> tuple[ExplainableBoostingClassifier, np.ndarray, np.ndarray, float, float]:
    model = ExplainableBoostingClassifier(feature_names=feature_names, **EBM_PARAMETERS)
    started = time.perf_counter()
    model.fit(x_train, y_train)
    fit_seconds = time.perf_counter() - started
    started = time.perf_counter()
    valid_probability = model.predict_proba(x_valid)[:, 1]
    test_probability = model.predict_proba(x_test)[:, 1]
    predict_seconds = time.perf_counter() - started
    return model, valid_probability, test_probability, fit_seconds, predict_seconds


def baseline_value(row: dict, name: str) -> float | None:
    value = row.get(name, "")
    return None if value in ("", None) else float(value)


def four_model_monthly_rows(
    place: str,
    course: int,
    target: str,
    test_rows: list[dict],
    y_test: np.ndarray,
    baseline_monthly: dict[tuple[str, int, str, str], dict],
    flaml_probability: np.ndarray,
    ebm_probability: np.ndarray,
) -> list[dict]:
    months = np.asarray([row["date"].strftime("%Y-%m") for row in test_rows])
    result: list[dict] = []
    for month in sorted(set(months.tolist())):
        mask = months == month
        base = baseline_monthly[(place, course, target, month)]
        hgb_brier = float(base["hgb_brier"])
        catboost_brier = float(base["catboost_brier"])
        flaml_brier = float(brier_score_loss(y_test[mask], flaml_probability[mask]))
        ebm_brier = float(brier_score_loss(y_test[mask], ebm_probability[mask]))
        scores = {"hgb": hgb_brier, "catboost": catboost_brier, "flaml": flaml_brier, "ebm": ebm_brier}
        best = min(scores.values())
        winners = [name for name, value in scores.items() if np.isclose(value, best, rtol=0.0, atol=1e-15)]
        result.append({
            "place": place, "course": course, "target": target, "month": month,
            "n": int(mask.sum()), "positive_rate": float(np.mean(y_test[mask])),
            **{f"{name}_brier": value for name, value in scores.items()},
            "winner": "+".join(winners),
        })
    return result


def model_aggregate(summary: list[dict], keys: tuple[str, ...]) -> list[dict]:
    groups: dict[tuple, list[dict]] = {}
    for row in summary:
        groups.setdefault(tuple(row[key] for key in keys), []).append(row)
    output: list[dict] = []
    for group_key, values in sorted(groups.items()):
        item = {**dict(zip(keys, group_key)), "cases": len(values)}
        for split_name in ("valid", "test"):
            for model in ("hgb", "catboost", "flaml", "ebm"):
                item[f"mean_{split_name}_{model}_brier"] = float(np.mean([float(row[f"{split_name}_{model}_brier"]) for row in values]))
        for model in ("hgb", "catboost", "flaml", "ebm"):
            item[f"test_best_{model}_cases"] = sum(model in row["test_winner"].split("+") for row in values)
        output.append(item)
    return output


def write_four_model_markdown(path: Path, experiment: dict, summary: list[dict], monthly: list[dict], importance: list[dict]) -> None:
    models = ("hgb", "catboost", "flaml", "ebm")
    mean_test = {model: float(np.mean([float(row[f"test_{model}_brier"]) for row in summary])) for model in models}
    mean_valid = {model: float(np.mean([float(row[f"valid_{model}_brier"]) for row in summary])) for model in models}
    best_test = {model: sum(model in row["test_winner"].split("+") for row in summary) for model in models}
    flaml_cat = sum(float(row["test_flaml_brier"]) < float(row["test_catboost_brier"]) for row in summary)
    ebm_cat = sum(float(row["test_ebm_brier"]) < float(row["test_catboost_brier"]) for row in summary)
    month_wins = {model: sum(model in row["winner"].split("+") for row in monthly) for model in models}
    mean_fit = {model: float(np.mean([float(row[f"{model}_fit_seconds"]) for row in summary])) for model in models}
    mean_predict = {model: float(np.mean([float(row[f"{model}_predict_seconds"]) for row in summary])) for model in models}
    valid_to_test = {model: mean_test[model] - mean_valid[model] for model in models}
    same_best = sum(bool(set(row["valid_winner"].split("+")) & set(row["test_winner"].split("+"))) for row in summary)
    lines = [
        "# コースサインML正式比較: HGB / CatBoost / FLAML / EBM", "",
        "## 結論", "",
        f"- FLAMLがCatBoostのTEST Brierを上回ったケース: **{flaml_cat}/36**",
        f"- EBMがCatBoostのTEST Brierを上回ったケース: **{ebm_cat}/36**",
        "- これは本番採用の判断ではない。TESTを用いた条件変更・再チューニング・校正・ブレンドは行っていない。", "",
        "## 固定条件", "",
        f"- Git: `{experiment['git_sha']}`",
        f"- 170展示込み特徴量、ASY/AMG × 1〜6C × first/top2/top3 = 36ケース",
        f"- TRAIN {experiment['period']['train']} / VALID {experiment['period']['valid']} / TEST {experiment['period']['test']}",
        f"- Runtime: Python {experiment['runtime']['python']} / scikit-learn {experiment['runtime']['scikit_learn']} / CatBoost {experiment['runtime']['catboost']} / FLAML {experiment['runtime']['flaml']} / InterpretML {experiment['runtime']['interpret']} / NumPy {experiment['runtime']['numpy']}",
        "- HGB/CatBoostは前回正式成果物を読み込み、同じ条件を検査して統合。FLAML/EBMのみ今回学習。",
        "- FLAMLはTRAIN学習＋固定VALIDによる15秒・最大40試行の内部選択（log_loss）。Brierは外部評価専用。TESTはfitへ未入力。",
        "- EBMは全ケース共通の固定設定（10 interactions、4 outer bags、seed固定）。",
        "",
        "## 全体集計", "",
        "|モデル|VALID平均Brier|TEST平均Brier|TEST最良ケース数|月別case×month最良数|HGB比TEST改善|CatBoost比TEST改善|",
        "|---|---:|---:|---:|---:|---:|---:|",
    ]
    for model in models:
        hgb_improved = sum(float(row[f"test_{model}_brier"]) < float(row["test_hgb_brier"]) for row in summary)
        cat_improved = sum(float(row[f"test_{model}_brier"]) < float(row["test_catboost_brier"]) for row in summary)
        lines.append(f"|{model}|{mean_valid[model]:.8f}|{mean_test[model]:.8f}|{best_test[model]}|{month_wins[model]}|{hgb_improved}|{cat_improved}|")
    lines += ["", "## VALID→TESTの安定性", "", f"- 各ケースでVALID最良モデルとTEST最良モデルが少なくとも1つ一致: {same_best}/36ケース。", "", "|モデル|平均VALID Brier|平均TEST Brier|TEST - VALID|", "|---|---:|---:|---:|"]
    for model in models:
        lines.append(f"|{model}|{mean_valid[model]:.8f}|{mean_test[model]:.8f}|{valid_to_test[model]:+.8f}|")
    lines += ["", "## 36ケース", "", "|場|C|target|TRAIN|VALID|TEST|HGB TEST|CatBoost TEST|FLAML TEST|EBM TEST|最良|FLAML推定器|", "|---|---:|---|---:|---:|---:|---:|---:|---:|---:|---|---|"]
    for row in summary:
        lines.append(f"|{row['place']}|{row['course']}|{row['target']}|{row['train_n']}|{row['valid_n']}|{row['test_n']}|{float(row['test_hgb_brier']):.6f}|{float(row['test_catboost_brier']):.6f}|{float(row['test_flaml_brier']):.6f}|{float(row['test_ebm_brier']):.6f}|{row['test_winner']}|{row['flaml_selected_estimator']}|")
    for title, keys in (("場別", ("place",)), ("コース別", ("course",)), ("target別", ("target",))):
        lines += ["", f"## {title}", "", "|区分|ケース|HGB TEST|CatBoost TEST|FLAML TEST|EBM TEST|HGB最良|CatBoost最良|FLAML最良|EBM最良|", "|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|"]
        for row in model_aggregate(summary, keys):
            label = " / ".join(str(row[key]) for key in keys)
            lines.append(f"|{label}|{row['cases']}|{row['mean_test_hgb_brier']:.6f}|{row['mean_test_catboost_brier']:.6f}|{row['mean_test_flaml_brier']:.6f}|{row['mean_test_ebm_brier']:.6f}|{row['test_best_hgb_cases']}|{row['test_best_catboost_cases']}|{row['test_best_flaml_cases']}|{row['test_best_ebm_cases']}|")
    lines += [
        "", "## 月別安定性", "",
        "- `*_monthly.csv` は case×month ごとのBrierと最良モデルを記録する。小標本月は参考値。",
        f"- 月別case×month最良数: HGB {month_wins['hgb']} / CatBoost {month_wins['catboost']} / FLAML {month_wins['flaml']} / EBM {month_wins['ebm']}。",
        "", "|月|ケース|HGB平均Brier|CatBoost平均Brier|FLAML平均Brier|EBM平均Brier|", "|---|---:|---:|---:|---:|---:|",
    ]
    for month in sorted({row["month"] for row in monthly}):
        values = [row for row in monthly if row["month"] == month]
        lines.append(f"|{month}|{len(values)}|{np.mean([float(row['hgb_brier']) for row in values]):.6f}|{np.mean([float(row['catboost_brier']) for row in values]):.6f}|{np.mean([float(row['flaml_brier']) for row in values]):.6f}|{np.mean([float(row['ebm_brier']) for row in values]):.6f}|")
    lines += [
        "", "## EBMの代表的重要特徴", "",
        "代表5ケースの上位10 termを `*_ebm_importance.csv` に保存した。相互作用termは `feature × feature` として記録する。",
        f"- 保存行数: {len(importance)}", "",
        "## データリーク確認", "",
        "- 着順・結果はfirst/top2/top3のラベル作成だけに使用し、特徴量・モデル選択には未使用。払戻・オッズも未使用。",
        "- courseは展示進入優先、展示欠損時のみ枠番fallback。履歴・展示平均は既存point-in-time生成器を再利用。",
        "- FLAMLの探索はTRAIN/VALID内に限定し、TESTをfit・early stopping・モデル選択へ渡していない。",
        "", "## 実行負荷", "",
        f"- 今回プロセス最大RSS: {experiment['runtime']['max_rss_kb']:,} KB、今回FLAML/EBM総実行時間: {experiment['runtime']['elapsed_seconds']:.2f}秒。",
        "", "|モデル|平均fit秒/ケース|平均predict秒/ケース|", "|---|---:|---:|",
    ]
    for model in models:
        lines.append(f"|{model}|{mean_fit[model]:.3f}|{mean_predict[model]:.3f}|")
    lines += [
        "- 個別fit/predict時間はsummary CSVに記録。前回HGB/CatBoostの時間は前回成果物の記録を引き継ぐ。",
        "", "## 範囲外", "",
        "本番置換、AI1着率v6直接対決、校正、ブレンド、race_number、TabDPT-Turbo、TabICLv2には進んでいない。",
    ]
    path.write_text("\n".join(lines) + "\n", encoding="utf-8")


def process_rss_kb() -> int | None:
    """現在のRSSをLinuxの/procから取得する。取得不能でも比較を止めない。"""
    try:
        for line in Path("/proc/self/status").read_text(encoding="utf-8").splitlines():
            if line.startswith("VmRSS:"):
                return int(line.split()[1])
    except OSError:
        pass
    return None


def five_model_monthly_rows(
    place: str,
    course: int,
    target: str,
    test_rows: list[dict],
    y_test: np.ndarray,
    baseline_monthly: dict[tuple[str, int, str, str], dict],
    tabdpt_probability: np.ndarray,
) -> list[dict]:
    months = np.asarray([row["date"].strftime("%Y-%m") for row in test_rows])
    output: list[dict] = []
    for month in sorted(set(months.tolist())):
        mask = months == month
        baseline = baseline_monthly[(place, course, target, month)]
        scores = {
            "hgb": float(baseline["hgb_brier"]),
            "catboost": float(baseline["catboost_brier"]),
            "flaml": float(baseline["flaml_brier"]),
            "ebm": float(baseline["ebm_brier"]),
            "tabdpt_turbo": float(brier_score_loss(y_test[mask], tabdpt_probability[mask])),
        }
        best = min(scores.values())
        output.append({
            "place": place, "course": course, "target": target, "month": month,
            "n": int(mask.sum()), "positive_rate": float(np.mean(y_test[mask])),
            **{f"{model}_brier": value for model, value in scores.items()},
            "winner": "+".join(model for model, value in scores.items() if np.isclose(value, best, rtol=0.0, atol=1e-15)),
        })
    return output


def write_five_model_markdown(path: Path, experiment: dict, summary: list[dict], monthly: list[dict]) -> None:
    models = ("hgb", "catboost", "flaml", "ebm", "tabdpt_turbo")
    mean_valid = {model: float(np.mean([float(row[f"valid_{model}_brier"]) for row in summary])) for model in models}
    mean_test = {model: float(np.mean([float(row[f"test_{model}_brier"]) for row in summary])) for model in models}
    best_cases = {model: sum(model in row["test_winner"].split("+") for row in summary) for model in models}
    monthly_wins = {model: sum(model in row["winner"].split("+") for row in monthly) for model in models}
    lines = [
        "# コースサインML正式比較: 5モデル（TabDPT-Turbo追加）", "",
        "## 実行可否", "",
        "- **TabDPT-Turbo v1.2.0: 正式36ケース比較を完走。**",
        "- **TabICLv2: CPU最小設定でも代表full splitで最大RSS 5.55GB。8GBサーバで継続安全性が不足するため、今回の正式比較対象外（smokeのみ）。**",
        "", "## 固定条件", "",
        f"- Git: `{experiment['git_sha']}` / 基準4モデル成果物: `{experiment['baseline_experiment_git_sha']}`",
        f"- 対象: ASY・AMG、1〜6C × first/top2/top3 = 36ケース。展示込み170特徴量を入力。",
        f"- TRAIN {experiment['period']['train']} / VALID {experiment['period']['valid']} / TEST {experiment['period']['test']}",
        "- HGB/CatBoost/FLAML/EBMは既存正式成果物を再利用。TabDPT-Turboだけを今回TRAINでfitし、VALID/TESTは評価専用。",
        "- TabDPT-Turboは公式v1.2.0、CPU、flash/compile無効。170特徴を入力後、公式標準のPCA feature reductionとcontext subsampleを使用。",
        "- TabICLv2は公式v2 checkpointを使うCPU smokeのみ。公式事前学習範囲は2〜100列のため、170列の結果は参考値として扱う。",
        "", "## 全体集計", "", "|モデル|VALID平均Brier|TEST平均Brier|TEST最良ケース|HGB比TEST改善|CatBoost比TEST改善|FLAML比TEST改善|EBM比TEST改善|月別case×month最良|", "|---|---:|---:|---:|---:|---:|---:|---:|---:|",
    ]
    for model in models:
        improvements = [sum(float(row[f"test_{model}_brier"]) < float(row[f"test_{baseline}_brier"]) for row in summary) for baseline in ("hgb", "catboost", "flaml", "ebm")]
        lines.append(f"|{model}|{mean_valid[model]:.8f}|{mean_test[model]:.8f}|{best_cases[model]}|{improvements[0]}|{improvements[1]}|{improvements[2]}|{improvements[3]}|{monthly_wins[model]}|")
    lines += ["", "## 36ケース", "", "|場|C|target|TRAIN|VALID|TEST|HGB|CatBoost|FLAML|EBM|TabDPT-Turbo|最良|", "|---|---:|---|---:|---:|---:|---:|---:|---:|---:|---:|---|"]
    for row in summary:
        lines.append(f"|{row['place']}|{row['course']}|{row['target']}|{row['train_n']}|{row['valid_n']}|{row['test_n']}|{float(row['test_hgb_brier']):.6f}|{float(row['test_catboost_brier']):.6f}|{float(row['test_flaml_brier']):.6f}|{float(row['test_ebm_brier']):.6f}|{float(row['test_tabdpt_turbo_brier']):.6f}|{row['test_winner']}|")
    for title, keys in (("場別", ("place",)), ("コース別", ("course",)), ("target別", ("target",))):
        groups: dict[tuple, list[dict]] = {}
        for row in summary:
            groups.setdefault(tuple(row[key] for key in keys), []).append(row)
        lines += ["", f"## {title}", "", "|区分|ケース|HGB|CatBoost|FLAML|EBM|TabDPT-Turbo|TabDPT最良|", "|---|---:|---:|---:|---:|---:|---:|---:|"]
        for key, values in sorted(groups.items()):
            label = " / ".join(map(str, key))
            means = [float(np.mean([float(row[f"test_{model}_brier"]) for row in values])) for model in models]
            wins = sum("tabdpt_turbo" in row["test_winner"].split("+") for row in values)
            lines.append(f"|{label}|{len(values)}|{means[0]:.6f}|{means[1]:.6f}|{means[2]:.6f}|{means[3]:.6f}|{means[4]:.6f}|{wins}|")
    lines += ["", "## 月別安定性", "", "|月|HGB平均|CatBoost平均|FLAML平均|EBM平均|TabDPT-Turbo平均|", "|---|---:|---:|---:|---:|---:|"]
    for month in sorted({row["month"] for row in monthly}):
        values = [row for row in monthly if row["month"] == month]
        means = [float(np.mean([float(row[f"{model}_brier"]) for row in values])) for model in models]
        lines.append(f"|{month}|{means[0]:.6f}|{means[1]:.6f}|{means[2]:.6f}|{means[3]:.6f}|{means[4]:.6f}|")
    lines += ["", "## VALID→TEST安定性", "", "|モデル|VALID平均|TEST平均|TEST - VALID|", "|---|---:|---:|---:|"]
    for model in models:
        lines.append(f"|{model}|{mean_valid[model]:.8f}|{mean_test[model]:.8f}|{mean_test[model] - mean_valid[model]:+.8f}|")
    lines += ["", "## データリークと予測相関", "", "- 既存のpoint-in-timeデータセット生成器を再利用し、結果はラベル作成のみ。払戻・オッズ・未来情報は特徴量に未使用。", "- 既存4モデルの個別予測確率は前回成果物に保存されていないため、再学習禁止方針を守り、今回の確率相関は未算出。", "", "## 実行負荷", "", f"- TabDPT-Turbo 36ケースの総実行時間: {experiment['runtime']['elapsed_seconds']:.2f}秒 / プロセス最大RSS: {experiment['runtime']['max_rss_kb']:,}KB。", "- 個別時間とRSSはsummary CSVに保存。", "", "## 範囲外", "", "本番置換、校正、ブレンド、AI1着率v6比較、race_number、AutoGluon、NGBoostは未実施。"]
    path.write_text("\n".join(lines) + "\n", encoding="utf-8")


def foundation_tabdpt_main(args: argparse.Namespace) -> None:
    """保存済み4モデル成果物へ、CPUで実用可能なTabDPT-Turboだけを統合する。"""
    from importlib.metadata import version
    from tabdpt import TabDPTClassifier
    import gc

    output_dir = ROOT / "analysis" / "output"
    baseline, baseline_monthly_rows, baseline_experiment = load_baseline(output_dir, args.baseline_stem)
    baseline_monthly = {(row["place"], int(row["course"]), row["target"], row["month"]): row for row in baseline_monthly_rows}
    config = json.loads((ROOT / "config" / "course_signal_rules.json").read_text(encoding="utf-8"))
    started = time.perf_counter()
    summary: list[dict] = []
    monthly: list[dict] = []
    dataset_audit: dict[str, dict] = {}
    canonical_features: list[str] | None = None
    for place in PLACES:
        audit.PLACE = place
        records, _pre_features, post_features = audit.build_dataset(date(2023, 9, 27), END, config)
        dataset_audit[place] = dict(audit.LAST_DATASET_AUDIT)
        if canonical_features is None:
            canonical_features = list(post_features)
        elif canonical_features != list(post_features):
            raise RuntimeError("場ごとに展示込み特徴量一覧が異なります")
        if len(post_features) != 170:
            raise RuntimeError(f"展示込み特徴量数が固定条件170と一致しません: {len(post_features)}")
        for course in COURSES:
            train, valid, test = split([row for row in records if row["course"] == course])
            x_train, x_valid, x_test = (as_matrix(rows, post_features) for rows in (train, valid, test))
            for target in TARGETS:
                key = (place, course, target)
                base = baseline[key]
                for column, actual in (("train_n", len(train)), ("valid_n", len(valid)), ("test_n", len(test))):
                    if int(base[column]) != actual:
                        raise RuntimeError(f"前回成果物と今回データ件数が不一致: {key} {column}")
                y_train = np.asarray([row[target] for row in train], dtype=np.int8)
                y_valid = np.asarray([row[target] for row in valid], dtype=np.int8)
                y_test = np.asarray([row[target] for row in test], dtype=np.int8)
                model = TabDPTClassifier(**{key: value for key, value in TABDPT_TURBO_PARAMETERS.items() if key not in {"predict_seed"}})
                fit_started = time.perf_counter(); model.fit(x_train, y_train); fit_seconds = time.perf_counter() - fit_started
                predict_started = time.perf_counter()
                valid_probability = model.predict_proba(x_valid, seed=RANDOM_SEED)[:, 1]
                test_probability = model.predict_proba(x_test, seed=RANDOM_SEED)[:, 1]
                predict_seconds = time.perf_counter() - predict_started
                valid_metrics, test_metrics = metrics(y_valid, valid_probability), metrics(y_test, test_probability)
                if np.isnan(test_probability).any() or np.isinf(test_probability).any():
                    raise RuntimeError(f"TabDPT-Turbo確率にNaN/Inf: {key}")
                valid_scores = {"hgb": float(base["valid_hgb_brier"]), "catboost": float(base["valid_catboost_brier"]), "flaml": float(base["valid_flaml_brier"]), "ebm": float(base["valid_ebm_brier"]), "tabdpt_turbo": float(valid_metrics["brier"])}
                test_scores = {"hgb": float(base["test_hgb_brier"]), "catboost": float(base["test_catboost_brier"]), "flaml": float(base["test_flaml_brier"]), "ebm": float(base["test_ebm_brier"]), "tabdpt_turbo": float(test_metrics["brier"])}
                valid_best, test_best = min(valid_scores.values()), min(test_scores.values())
                row = {"place": place, "course": course, "target": target, "feature_set": "post_exhibition_170_input", "feature_count": len(post_features), "train_n": len(train), "valid_n": len(valid), "test_n": len(test), "train_positive_rate": float(np.mean(y_train)), "valid_positive_rate": float(np.mean(y_valid)), "test_positive_rate": float(np.mean(y_test)), **{f"valid_{model}_brier": value for model, value in valid_scores.items()}, **{f"test_{model}_brier": value for model, value in test_scores.items()}, "valid_winner": "+".join(model for model, value in valid_scores.items() if np.isclose(value, valid_best, rtol=0.0, atol=1e-15)), "test_winner": "+".join(model for model, value in test_scores.items() if np.isclose(value, test_best, rtol=0.0, atol=1e-15)), "tabdpt_turbo_fit_seconds": fit_seconds, "tabdpt_turbo_predict_seconds": predict_seconds, "tabdpt_turbo_rss_kb_after_predict": process_rss_kb()}
                for metric_name in ("log_loss", "auc", "accuracy_0_5", "probability_mean"):
                    for model_name in ("hgb", "catboost", "flaml", "ebm"):
                        row[f"test_{model_name}_{metric_name}"] = baseline_value(base, f"test_{model_name}_{metric_name}")
                    row[f"test_tabdpt_turbo_{metric_name}"] = test_metrics[metric_name]
                summary.append(row)
                monthly.extend(five_model_monthly_rows(place, course, target, test, y_test, baseline_monthly, test_probability))
                print(f"{place} {course}C {target}: TEST TabDPT={test_scores['tabdpt_turbo']:.6f} winner={row['test_winner']}", flush=True)
                del model, valid_probability, test_probability
                gc.collect()
    assert canonical_features is not None
    import torch
    elapsed = time.perf_counter() - started
    experiment = {"experiment": "course_signal_model_compare_five_models_with_tabdpt_turbo", "created_at": time.strftime("%Y-%m-%dT%H:%M:%S%z"), "git_sha": git_sha(), "baseline_experiment_git_sha": baseline_experiment.get("git_sha"), "places": list(PLACES), "courses": list(COURSES), "targets": list(TARGETS), "period": {"dataset_start": "2023-09-27", "train": "2023-09-27 to 2025-08-31", "valid": "2025-09-01 to 2026-02-28", "test": "2026-03-01 to 2026-09-27"}, "feature_count": len(canonical_features), "feature_list_reference": "post_features from audit_tamagawa_course_signals_zero_base_ml.build_dataset", "tabdpt_turbo": {"package": "tabdpt", "package_version": version("tabdpt"), "model_version": "1.2.0 (official TabDPT-Turbo)", "license": "Apache-2.0", "parameters": TABDPT_TURBO_PARAMETERS, "preprocessing": "official standard normalizer=standard, feature_reduction=pca, context_reduction=subsample; all 170 features are supplied as input"}, "tabicl_v2": {"package": "tabicl", "package_version": version("tabicl"), "checkpoint": "tabicl-classifier-v2-20260212.ckpt", "license": "BSD-3-Clause", "formal_comparison": False, "reason": "full ASY 3C top3 CPU smoke used 5.55GB RSS at n_estimators=1; insufficient safety margin on 8GB host"}, "leakage_checks": {"results_only_used_as_labels": True, "payouts_and_odds_not_used": True, "point_in_time_existing_generator": True, "test_not_used_for_tabdpt_fit_or_selection": True}, "runtime": {"python": platform.python_version(), "scikit_learn": sklearn.__version__, "numpy": np.__version__, "torch": torch.__version__, "torch_cuda_available": torch.cuda.is_available(), "max_rss_kb": int(resource.getrusage(resource.RUSAGE_SELF).ru_maxrss), "elapsed_seconds": elapsed}, "dataset_audit": dataset_audit}
    stem = str(args.output_stem)
    write_csv(output_dir / f"{stem}_summary.csv", summary)
    write_csv(output_dir / f"{stem}_monthly.csv", monthly)
    (output_dir / f"{stem}_experiment.json").write_text(json.dumps(experiment, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    write_five_model_markdown(output_dir / f"{stem}.md", experiment, summary, monthly)
    print(f"summary: {output_dir / f'{stem}_summary.csv'}", flush=True)


def directory_size_bytes(path: Path) -> int:
    return sum(item.stat().st_size for item in path.rglob("*") if item.is_file())


def positive_probability(value: object) -> np.ndarray:
    """AutoGluonのbinary predict_proba出力を正例1の一次元配列へ正規化する。"""
    result = np.asarray(value, dtype=float)
    if result.ndim == 1:
        return result
    if result.ndim == 2 and result.shape[1] == 2:
        return result[:, 1]
    raise RuntimeError(f"AutoGluon predict_probaの想定外shape: {result.shape}")


def make_tabular_frame(matrix: np.ndarray, features: list[str], label: np.ndarray | None = None):
    if pd is None:
        raise RuntimeError("AutoGluon比較にはpandasが必要です")
    frame = pd.DataFrame(matrix, columns=features)
    if label is not None:
        frame["_target"] = label
    return frame


def fit_autogluon(
    x_train: np.ndarray,
    y_train: np.ndarray,
    x_valid: np.ndarray,
    y_valid: np.ndarray,
    x_test: np.ndarray,
    features: list[str],
    case_id: str,
) -> tuple[np.ndarray, np.ndarray, float, float, int, str, str, list[dict]]:
    if TabularPredictor is None:
        raise RuntimeError("AutoGluon比較にはautogluon.tabularが必要です")
    model_path = AUTOGLUON_ROOT / case_id
    shutil.rmtree(model_path, ignore_errors=True)
    train_frame = make_tabular_frame(x_train, features, y_train)
    valid_frame = make_tabular_frame(x_valid, features, y_valid)
    test_frame = make_tabular_frame(x_test, features)
    predictor = TabularPredictor(
        label="_target",
        problem_type="binary",
        eval_metric=AUTOGLUON_PARAMETERS["eval_metric"],
        path=str(model_path),
        verbosity=0,
    )
    started = time.perf_counter()
    predictor.fit(
        train_data=train_frame,
        tuning_data=valid_frame,
        time_limit=AUTOGLUON_PARAMETERS["time_limit_seconds"],
        presets=AUTOGLUON_PARAMETERS["preset"],
        hyperparameters=AUTOGLUON_PARAMETERS["hyperparameters"],
        excluded_model_types=AUTOGLUON_PARAMETERS["excluded_models"],
        fit_weighted_ensemble=True,
        dynamic_stacking=False,
        calibrate_decision_threshold=False,
        num_cpus=AUTOGLUON_PARAMETERS["num_cpus"],
        num_gpus=AUTOGLUON_PARAMETERS["num_gpus"],
        fit_strategy=AUTOGLUON_PARAMETERS["fit_strategy"],
        memory_limit=6.0,
    )
    fit_seconds = time.perf_counter() - started
    started = time.perf_counter()
    valid_probability = positive_probability(predictor.predict_proba(valid_frame.drop(columns=["_target"]), as_pandas=False))
    test_probability = positive_probability(predictor.predict_proba(test_frame, as_pandas=False))
    predict_seconds = time.perf_counter() - started
    if not np.isfinite(valid_probability).all() or not np.isfinite(test_probability).all():
        raise RuntimeError(f"AutoGluon確率にNaN/Inf: {case_id}")
    leaderboard = predictor.leaderboard(silent=True, extra_info=True)
    leaderboard_rows = []
    for rank, item in enumerate(leaderboard.to_dict(orient="records"), 1):
        leaderboard_rows.append({"rank": rank, **item})
    info = predictor.info()
    final_model = str(predictor.model_best)
    model_info = info.get("model_info", {}).get(final_model, {})
    stacker = model_info.get("stacker_info", {})
    components = stacker.get("base_model_names", [])
    if not components:
        components = model_info.get("base_model_names", [])
    composition = "+".join(map(str, components)) if components else final_model
    disk_bytes = directory_size_bytes(model_path)
    del predictor, leaderboard, info, train_frame, valid_frame, test_frame
    gc.collect()
    shutil.rmtree(model_path, ignore_errors=True)
    return valid_probability, test_probability, fit_seconds, predict_seconds, disk_bytes, final_model, composition, leaderboard_rows


def fit_ngboost(
    x_train: np.ndarray,
    y_train: np.ndarray,
    x_valid: np.ndarray,
    y_valid: np.ndarray,
    x_test: np.ndarray,
) -> tuple[np.ndarray, np.ndarray, float, float]:
    if NGBClassifier is None or Bernoulli is None:
        raise RuntimeError("NGBoost比較にはngboostが必要です")
    # keep_empty_features=True で、TRAIN内で全欠損だった列も削除せず0を代入する。
    # ケースごとに列数が変わらず、170入力情報という固定比較条件を維持する。
    imputer = SimpleImputer(strategy="median", keep_empty_features=True)
    train_input = imputer.fit_transform(x_train)
    valid_input = imputer.transform(x_valid)
    test_input = imputer.transform(x_test)
    model = NGBClassifier(
        Dist=Bernoulli,
        n_estimators=NGBOOST_PARAMETERS["n_estimators"],
        learning_rate=NGBOOST_PARAMETERS["learning_rate"],
        minibatch_frac=NGBOOST_PARAMETERS["minibatch_frac"],
        col_sample=NGBOOST_PARAMETERS["col_sample"],
        natural_gradient=NGBOOST_PARAMETERS["natural_gradient"],
        validation_fraction=NGBOOST_PARAMETERS["validation_fraction"],
        early_stopping_rounds=NGBOOST_PARAMETERS["early_stopping_rounds"],
        random_state=NGBOOST_PARAMETERS["random_state"],
        verbose=NGBOOST_PARAMETERS["verbose"],
    )
    started = time.perf_counter()
    model.fit(train_input, y_train, X_val=valid_input, Y_val=y_valid)
    fit_seconds = time.perf_counter() - started
    started = time.perf_counter()
    valid_probability = model.predict_proba(valid_input)[:, 1]
    test_probability = model.predict_proba(test_input)[:, 1]
    predict_seconds = time.perf_counter() - started
    if not np.isfinite(valid_probability).all() or not np.isfinite(test_probability).all():
        raise RuntimeError("NGBoost確率にNaN/Inf")
    del model, imputer, train_input, valid_input, test_input
    gc.collect()
    return valid_probability, test_probability, fit_seconds, predict_seconds


def load_five_baseline(output_dir: Path, stem: str) -> tuple[dict[tuple[str, int, str], dict], list[dict], dict]:
    indexed, monthly, experiment = load_baseline(output_dir, stem)
    required = {"test_tabdpt_turbo_brier", "valid_tabdpt_turbo_brier"}
    if not required.issubset(indexed[("ASY", 1, "first")]):
        raise RuntimeError("5モデル成果物にTabDPT-Turbo列がありません")
    return indexed, monthly, experiment


def seven_model_monthly_rows(
    place: str,
    course: int,
    target: str,
    test_rows: list[dict],
    y_test: np.ndarray,
    baseline_monthly: dict[tuple[str, int, str, str], dict],
    autogluon_probability: np.ndarray,
    ngboost_probability: np.ndarray,
) -> list[dict]:
    months = np.asarray([row["date"].strftime("%Y-%m") for row in test_rows])
    output: list[dict] = []
    for month in sorted(set(months.tolist())):
        mask = months == month
        baseline = baseline_monthly[(place, course, target, month)]
        scores = {name: float(baseline[f"{name}_brier"]) for name in ("hgb", "catboost", "flaml", "ebm", "tabdpt_turbo")}
        scores["autogluon"] = float(brier_score_loss(y_test[mask], autogluon_probability[mask]))
        scores["ngboost"] = float(brier_score_loss(y_test[mask], ngboost_probability[mask]))
        best = min(scores.values())
        output.append({
            "place": place, "course": course, "target": target, "month": month,
            "n": int(mask.sum()), "positive_rate": float(np.mean(y_test[mask])),
            **{f"{name}_brier": value for name, value in scores.items()},
            "winner": "+".join(name for name, value in scores.items() if np.isclose(value, best, rtol=0.0, atol=1e-15)),
        })
    return output


def write_seven_model_markdown(path: Path, experiment: dict, summary: list[dict], monthly: list[dict], leaderboard: list[dict]) -> None:
    models = ("hgb", "catboost", "flaml", "ebm", "tabdpt_turbo", "autogluon", "ngboost")
    mean_valid = {name: float(np.mean([float(row[f"valid_{name}_brier"]) for row in summary])) for name in models}
    mean_test = {name: float(np.mean([float(row[f"test_{name}_brier"]) for row in summary])) for name in models}
    best_cases = {name: sum(name in row["test_winner"].split("+") for row in summary) for name in models}
    month_wins = {name: sum(name in row["winner"].split("+") for row in monthly) for name in models}
    lines = [
        "# コースサインML正式比較: 7モデル（AutoGluon / NGBoost追加）", "",
        "## 実行可否", "",
        "- AutoGluon Tabular と NGBoost は、ASY・AMG × 1〜6C × first/top2/top3 の固定36ケースを完走した。",
        "- AutoGluon はCPU 4core、medium_quality、標準ツリー系（GBM/CAT/XGB/RF/XT）とweighted ensembleのみ。foundation model・GPU前提モデルは未使用。",
        "- NGBoost はBernoulli分類、TRAINでfit・固定VALIDでearly stopping、TESTは評価専用。median補完はTRAINだけでfitし、170特徴の情報量は変えていない。", "",
        "## 固定条件", "",
        f"- Git: `{experiment['git_sha']}` / 基準5モデル成果物: `{experiment['baseline_experiment_git_sha']}`",
        f"- 展示込み170特徴、TRAIN {experiment['period']['train']} / VALID {experiment['period']['valid']} / TEST {experiment['period']['test']}",
        "- 結果・払戻・オッズはラベル以外に未使用。既存point-in-timeデータ生成器を再利用し、TESTは設定選択・fit・early stoppingに渡していない。", "",
        "## 全体集計", "",
        "|モデル|VALID平均Brier|TEST平均Brier|TEST最良|HGB比改善|CatBoost比改善|FLAML比改善|EBM比改善|TabDPT比改善|月別最良|", "|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|",
    ]
    for name in models:
        improvements = [sum(float(row[f"test_{name}_brier"]) < float(row[f"test_{baseline}_brier"]) for row in summary) for baseline in ("hgb", "catboost", "flaml", "ebm", "tabdpt_turbo")]
        lines.append(f"|{name}|{mean_valid[name]:.8f}|{mean_test[name]:.8f}|{best_cases[name]}|{improvements[0]}|{improvements[1]}|{improvements[2]}|{improvements[3]}|{improvements[4]}|{month_wins[name]}|")
    lines += ["", "## 36ケース", "", "|場|C|target|HGB|CatBoost|FLAML|EBM|TabDPT|AutoGluon|NGBoost|最良|AutoGluon最終モデル|", "|---|---:|---|---:|---:|---:|---:|---:|---:|---:|---|---|"]
    for row in summary:
        values = [float(row[f"test_{name}_brier"]) for name in models]
        lines.append(f"|{row['place']}|{row['course']}|{row['target']}|" + "|".join(f"{value:.6f}" for value in values) + f"|{row['test_winner']}|{row['autogluon_final_model']}|")
    for title, keys in (("場別", ("place",)), ("コース別", ("course",)), ("target別", ("target",))):
        groups: dict[tuple, list[dict]] = {}
        for row in summary:
            groups.setdefault(tuple(row[key] for key in keys), []).append(row)
        lines += ["", f"## {title}", "", "|区分|ケース|" + "|".join(models) + "|", "|---|---:|" + "|".join("---:" for _ in models) + "|"]
        for key, values in sorted(groups.items()):
            means = [float(np.mean([float(row[f"test_{name}_brier"]) for row in values])) for name in models]
            lines.append(f"|{' / '.join(map(str, key))}|{len(values)}|" + "|".join(f"{value:.6f}" for value in means) + "|")
    lines += ["", "## 月別安定性", "", "|月|" + "|".join(models) + "|", "|---|" + "|".join("---:" for _ in models) + "|"]
    for month in sorted({row["month"] for row in monthly}):
        values = [row for row in monthly if row["month"] == month]
        means = [float(np.mean([float(row[f"{name}_brier"]) for row in values])) for name in models]
        lines.append(f"|{month}|" + "|".join(f"{value:.6f}" for value in means) + "|")
    lines += ["", "## VALID→TEST安定性", "", "|モデル|VALID|TEST|TEST - VALID|", "|---|---:|---:|---:|"]
    for name in models:
        lines.append(f"|{name}|{mean_valid[name]:.8f}|{mean_test[name]:.8f}|{mean_test[name] - mean_valid[name]:+.8f}|")
    ensemble_rows = [row for row in summary if "+" in row["autogluon_composition"]]
    lines += ["", "## AutoGluon構成", "", f"- 最終モデルがensembleだったケース: {len(ensemble_rows)}/36。各ケースのleaderboardは `*_autogluon_leaderboard.csv`、構成・disk量はsummary CSVに保存。", "- AutoGluonの内部selection metricはlog_loss。BrierはVALID/TESTの外部評価にだけ使用した。", "", "## 実行負荷", "", f"- 総実行時間: {experiment['runtime']['elapsed_seconds']:.2f}秒 / プロセス最大RSS: {experiment['runtime']['max_rss_kb']:,}KB。", "- AutoGluon生成model directoryは個別集計後に /tmp から削除し、Gitには含めていない。", "", "|モデル|平均fit秒/ケース|平均predict秒/ケース|", "|---|---:|---:|"]
    for name in models:
        lines.append(f"|{name}|{np.mean([float(row.get(f'{name}_fit_seconds') or 0) for row in summary]):.3f}|{np.mean([float(row.get(f'{name}_predict_seconds') or 0) for row in summary]):.3f}|")
    lines += ["", "## 予測相関", "", "- 既存5モデルの個別TEST予測確率は前回成果物に保存されておらず、再学習禁止方針を守るため、今回の7モデル間のpairwise correlationは未算出。ブレンド検証フェーズで同一splitの予測確率を保存して評価する。", "", "## 範囲外", "", "- 本番置換、AI1着率v6比較、確率校正、ブレンド、race_number追加は行っていない。"]
    path.write_text("\n".join(lines) + "\n", encoding="utf-8")


def autogluon_ngboost_main(args: argparse.Namespace, smoke_only: bool = False) -> None:
    from importlib.metadata import version

    if None in (pd, TabularPredictor, NGBClassifier, Bernoulli):
        raise RuntimeError("AutoGluon / NGBoost比較にはpandas、autogluon.tabular、ngboostが必要です")
    output_dir = ROOT / "analysis" / "output"
    baseline, baseline_monthly_rows, baseline_experiment = load_five_baseline(output_dir, args.baseline_stem)
    four_timing, _four_monthly, four_experiment = load_baseline(output_dir, "course_signal_model_compare_asy_amg_20260927_four_models")
    baseline_monthly = {(row["place"], int(row["course"]), row["target"], row["month"]): row for row in baseline_monthly_rows}
    config = json.loads((ROOT / "config" / "course_signal_rules.json").read_text(encoding="utf-8"))
    cases = [("ASY", 3, "top3")] if smoke_only else [(place, course, target) for place in PLACES for course in COURSES for target in TARGETS]
    started = time.perf_counter()
    summary: list[dict] = []
    monthly: list[dict] = []
    leaderboard: list[dict] = []
    dataset_audit: dict[str, dict] = {}
    canonical_features: list[str] | None = None
    records_by_place: dict[str, list[dict]] = {}
    for place in sorted({case[0] for case in cases}):
        audit.PLACE = place
        records, _pre_features, post_features = audit.build_dataset(date(2023, 9, 27), END, config)
        records_by_place[place] = records
        dataset_audit[place] = dict(audit.LAST_DATASET_AUDIT)
        if canonical_features is None:
            canonical_features = list(post_features)
        elif canonical_features != list(post_features):
            raise RuntimeError("場ごとに展示込み特徴量一覧が異なります")
        if len(post_features) != 170:
            raise RuntimeError(f"展示込み特徴量数が固定条件170と一致しません: {len(post_features)}")
    assert canonical_features is not None
    for place, course, target in cases:
        key = (place, course, target)
        base = baseline[key]
        timing_base = four_timing[key]
        train, valid, test = split([row for row in records_by_place[place] if row["course"] == course])
        for column, actual in (("train_n", len(train)), ("valid_n", len(valid)), ("test_n", len(test))):
            if int(base[column]) != actual:
                raise RuntimeError(f"前回成果物と今回データ件数が不一致: {key} {column}")
        x_train, x_valid, x_test = (as_matrix(rows, canonical_features) for rows in (train, valid, test))
        y_train = np.asarray([row[target] for row in train], dtype=np.int8)
        y_valid = np.asarray([row[target] for row in valid], dtype=np.int8)
        y_test = np.asarray([row[target] for row in test], dtype=np.int8)
        case_id = f"{place.lower()}_{course}c_{target}"
        ag_valid, ag_test, ag_fit, ag_predict, ag_disk, ag_final, ag_composition, ag_leaderboard = fit_autogluon(x_train, y_train, x_valid, y_valid, x_test, canonical_features, case_id)
        ng_valid, ng_test, ng_fit, ng_predict = fit_ngboost(x_train, y_train, x_valid, y_valid, x_test)
        ag_valid_metrics, ag_test_metrics = metrics(y_valid, ag_valid), metrics(y_test, ag_test)
        ng_valid_metrics, ng_test_metrics = metrics(y_valid, ng_valid), metrics(y_test, ng_test)
        valid_scores = {name: float(base[f"valid_{name}_brier"]) for name in ("hgb", "catboost", "flaml", "ebm", "tabdpt_turbo")}
        test_scores = {name: float(base[f"test_{name}_brier"]) for name in ("hgb", "catboost", "flaml", "ebm", "tabdpt_turbo")}
        valid_scores.update({"autogluon": float(ag_valid_metrics["brier"]), "ngboost": float(ng_valid_metrics["brier"])})
        test_scores.update({"autogluon": float(ag_test_metrics["brier"]), "ngboost": float(ng_test_metrics["brier"])})
        valid_best, test_best = min(valid_scores.values()), min(test_scores.values())
        row = {
            "place": place, "course": course, "target": target, "feature_set": "post_exhibition_170_input", "feature_count": len(canonical_features),
            "train_n": len(train), "valid_n": len(valid), "test_n": len(test), "train_positive_rate": float(np.mean(y_train)), "valid_positive_rate": float(np.mean(y_valid)), "test_positive_rate": float(np.mean(y_test)),
            **{f"valid_{name}_brier": value for name, value in valid_scores.items()}, **{f"test_{name}_brier": value for name, value in test_scores.items()},
            "valid_winner": "+".join(name for name, value in valid_scores.items() if np.isclose(value, valid_best, rtol=0.0, atol=1e-15)),
            "test_winner": "+".join(name for name, value in test_scores.items() if np.isclose(value, test_best, rtol=0.0, atol=1e-15)),
            "autogluon_final_model": ag_final, "autogluon_composition": ag_composition, "autogluon_disk_bytes": ag_disk,
            "autogluon_fit_seconds": ag_fit, "autogluon_predict_seconds": ag_predict, "ngboost_fit_seconds": ng_fit, "ngboost_predict_seconds": ng_predict,
            "hgb_fit_seconds": baseline_value(timing_base, "hgb_fit_seconds"), "hgb_predict_seconds": baseline_value(timing_base, "hgb_predict_seconds"),
            "catboost_fit_seconds": baseline_value(timing_base, "catboost_fit_seconds"), "catboost_predict_seconds": baseline_value(timing_base, "catboost_predict_seconds"),
            "flaml_fit_seconds": baseline_value(timing_base, "flaml_fit_seconds"), "flaml_predict_seconds": baseline_value(timing_base, "flaml_predict_seconds"),
            "ebm_fit_seconds": baseline_value(timing_base, "ebm_fit_seconds"), "ebm_predict_seconds": baseline_value(timing_base, "ebm_predict_seconds"),
            "tabdpt_turbo_fit_seconds": baseline_value(base, "tabdpt_turbo_fit_seconds"), "tabdpt_turbo_predict_seconds": baseline_value(base, "tabdpt_turbo_predict_seconds"),
            "rss_kb_after_case": process_rss_kb(),
        }
        for name in ("hgb", "catboost", "flaml", "ebm", "tabdpt_turbo"):
            for metric_name in ("log_loss", "auc", "accuracy_0_5", "probability_mean"):
                row[f"test_{name}_{metric_name}"] = baseline_value(base, f"test_{name}_{metric_name}")
        for name, value in (("autogluon", ag_test_metrics), ("ngboost", ng_test_metrics)):
            for metric_name in ("log_loss", "auc", "accuracy_0_5", "probability_mean"):
                row[f"test_{name}_{metric_name}"] = value[metric_name]
        summary.append(row)
        for item in ag_leaderboard:
            leaderboard.append({"place": place, "course": course, "target": target, **item})
        if not smoke_only:
            monthly.extend(seven_model_monthly_rows(place, course, target, test, y_test, baseline_monthly, ag_test, ng_test))
        print(f"{place} {course}C {target}: AutoGluon={test_scores['autogluon']:.6f} NGBoost={test_scores['ngboost']:.6f} winner={row['test_winner']}", flush=True)
        del x_train, x_valid, x_test, ag_valid, ag_test, ng_valid, ng_test
        gc.collect()
    elapsed = time.perf_counter() - started
    import autogluon
    import ngboost
    experiment = {
        "experiment": "course_signal_autogluon_ngboost_smoke" if smoke_only else "course_signal_model_compare_seven_models",
        "created_at": time.strftime("%Y-%m-%dT%H:%M:%S%z"), "git_sha": git_sha(), "baseline_experiment_git_sha": baseline_experiment.get("git_sha"), "four_model_experiment_git_sha": four_experiment.get("git_sha"),
        "places": sorted({case[0] for case in cases}), "cases": [{"place": place, "course": course, "target": target} for place, course, target in cases],
        "period": {"dataset_start": "2023-09-27", "train": "2023-09-27 to 2025-08-31", "valid": "2025-09-01 to 2026-02-28", "test": "2026-03-01 to 2026-09-27"},
        "feature_count": len(canonical_features), "feature_list_reference": "post_features from audit_tamagawa_course_signals_zero_base_ml.build_dataset", "random_seed": RANDOM_SEED,
        "autogluon": {"package": "autogluon.tabular", "version": version("autogluon.tabular"), "license": "Apache-2.0", "parameters": AUTOGLUON_PARAMETERS, "preprocessing": "AutoGluon standard numeric missing-value handling; all 170 inputs supplied", "model_artifacts": "written only under /tmp then deleted after leaderboard/disk capture"},
        "ngboost": {"package": "ngboost", "version": ngboost.__version__, "license": "Apache-2.0", "parameters": NGBOOST_PARAMETERS, "preprocessing": "SimpleImputer(strategy=median, keep_empty_features=True) fit on TRAIN only; all-missing TRAIN columns retained as 0 and all 170 inputs preserved"},
        "runtime": {"python": platform.python_version(), "scikit_learn": sklearn.__version__, "numpy": np.__version__, "autogluon": version("autogluon.tabular"), "ngboost": ngboost.__version__, "max_rss_kb": int(resource.getrusage(resource.RUSAGE_SELF).ru_maxrss), "elapsed_seconds": elapsed},
        "dataset_audit": dataset_audit,
        "leakage_checks": {"results_only_used_as_labels": True, "payouts_and_odds_not_used": True, "point_in_time_existing_generator": True, "test_not_used_for_fit_or_selection": True},
    }
    stem = str(args.output_stem)
    write_csv(output_dir / f"{stem}_summary.csv", summary)
    write_csv(output_dir / f"{stem}_autogluon_leaderboard.csv", leaderboard)
    (output_dir / f"{stem}_experiment.json").write_text(json.dumps(experiment, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    if smoke_only:
        row = summary[0]
        (output_dir / f"{stem}.md").write_text("\n".join([
            "# AutoGluon / NGBoost feasibility smoke", "", "- 対象: ASY 3C top3、固定170特徴・同一split。", f"- AutoGluon: fit {row['autogluon_fit_seconds']:.2f}s / predict {row['autogluon_predict_seconds']:.2f}s / disk {row['autogluon_disk_bytes']:,} bytes / RSS {row['rss_kb_after_case']}KB / TEST Brier {row['test_autogluon_brier']:.8f}", f"- NGBoost: fit {row['ngboost_fit_seconds']:.2f}s / predict {row['ngboost_predict_seconds']:.2f}s / RSS {row['rss_kb_after_case']}KB / TEST Brier {row['test_ngboost_brier']:.8f}", f"- AutoGluon final model: `{row['autogluon_final_model']}` / composition: `{row['autogluon_composition']}`", "- AutoGluon artifactは/tmpから削除済み。", "",
        ]), encoding="utf-8")
    else:
        write_csv(output_dir / f"{stem}_monthly.csv", monthly)
        write_seven_model_markdown(output_dir / f"{stem}.md", experiment, summary, monthly, leaderboard)
    print(f"summary: {output_dir / f'{stem}_summary.csv'}", flush=True)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output-stem", default="course_signal_model_compare_asy_amg_20260927_four_models")
    parser.add_argument("--baseline-stem", default="course_signal_model_compare_asy_amg_20260927")
    parser.add_argument("--foundation-tabdpt", action="store_true", help="保存済み4モデル成果物へTabDPT-Turboを統合する")
    parser.add_argument("--autogluon-ngboost", action="store_true", help="保存済み5モデル成果物へAutoGluon/NGBoostを統合する")
    parser.add_argument("--autogluon-ngboost-smoke", action="store_true", help="ASY 3C top3だけでAutoGluon/NGBoostの実行可能性を確認する")
    args = parser.parse_args()
    if args.autogluon_ngboost and args.autogluon_ngboost_smoke:
        raise RuntimeError("--autogluon-ngboost と --autogluon-ngboost-smoke は同時に使えません")
    if args.foundation_tabdpt:
        if args.output_stem == "course_signal_model_compare_asy_amg_20260927_four_models":
            args.output_stem = "course_signal_model_compare_five_models_20260927"
        if args.baseline_stem == "course_signal_model_compare_asy_amg_20260927":
            args.baseline_stem = "course_signal_model_compare_asy_amg_20260927_four_models"
        foundation_tabdpt_main(args)
        return
    if args.autogluon_ngboost or args.autogluon_ngboost_smoke:
        if args.output_stem == "course_signal_model_compare_asy_amg_20260927_four_models":
            args.output_stem = "course_signal_autogluon_ngboost_smoke_20260927" if args.autogluon_ngboost_smoke else "course_signal_model_compare_seven_models_20260927"
        if args.baseline_stem == "course_signal_model_compare_asy_amg_20260927":
            args.baseline_stem = "course_signal_model_compare_five_models_20260927"
        autogluon_ngboost_main(args, smoke_only=args.autogluon_ngboost_smoke)
        return
    if None in (CatBoostClassifier, AutoML, ExplainableBoostingClassifier):
        raise RuntimeError("HGB/CatBoost/FLAML/EBM比較にはcatboost、flaml、interpret-coreが必要です")
    output_dir = ROOT / "analysis" / "output"
    output_dir.mkdir(parents=True, exist_ok=True)
    baseline, baseline_monthly_rows, baseline_experiment = load_baseline(output_dir, args.baseline_stem)
    baseline_monthly = {(row["place"], int(row["course"]), row["target"], row["month"]): row for row in baseline_monthly_rows}
    if len(baseline_monthly) != len(baseline_monthly_rows):
        raise RuntimeError("前回monthly成果物に重複ケースがあります")

    config = json.loads((ROOT / "config" / "course_signal_rules.json").read_text(encoding="utf-8"))
    all_started = time.perf_counter()
    summary: list[dict] = []
    monthly: list[dict] = []
    importance: list[dict] = []
    estimators: list[dict] = []
    dataset_audit: dict[str, dict] = {}
    canonical_features: list[str] | None = None
    representative = {("ASY", 1, "top2"), ("ASY", 2, "top3"), ("ASY", 4, "top3"), ("AMG", 2, "top2"), ("AMG", 4, "top3")}

    for place in PLACES:
        audit.PLACE = place
        records, _pre_features, post_features = audit.build_dataset(date(2023, 9, 27), END, config)
        dataset_audit[place] = dict(audit.LAST_DATASET_AUDIT)
        if canonical_features is None:
            canonical_features = list(post_features)
        elif canonical_features != list(post_features):
            raise RuntimeError("場ごとに展示込み特徴量一覧が異なります")
        if len(post_features) != 170:
            raise RuntimeError(f"展示込み特徴量数が固定条件170と一致しません: {len(post_features)}")
        for course in COURSES:
            train, valid, test = split([row for row in records if row["course"] == course])
            if not train or not valid or not test:
                raise RuntimeError(f"{place} {course}C: splitが空です")
            x_train, x_valid, x_test = (as_matrix(rows, post_features) for rows in (train, valid, test))
            for target in TARGETS:
                key = (place, course, target)
                base = baseline.get(key)
                if base is None:
                    raise RuntimeError(f"前回成果物にケースがありません: {key}")
                y_train = np.asarray([row[target] for row in train], dtype=np.int8)
                y_valid = np.asarray([row[target] for row in valid], dtype=np.int8)
                y_test = np.asarray([row[target] for row in test], dtype=np.int8)
                for column, actual in (("train_n", len(train)), ("valid_n", len(valid)), ("test_n", len(test))):
                    if int(base[column]) != actual:
                        raise RuntimeError(f"前回成果物と今回データ件数が不一致: {key} {column}")
                if len(np.unique(y_train)) < 2:
                    raise RuntimeError(f"{place} {course}C {target}: TRAINラベルが単一です")
                flaml_valid, flaml_test, flaml_fit, flaml_predict, selected = fit_flaml(x_train, y_train, x_valid, y_valid, x_test)
                ebm, ebm_valid, ebm_test, ebm_fit, ebm_predict = fit_ebm(x_train, y_train, x_valid, x_test, list(post_features))
                flaml_valid_metrics, flaml_test_metrics = metrics(y_valid, flaml_valid), metrics(y_test, flaml_test)
                ebm_valid_metrics, ebm_test_metrics = metrics(y_valid, ebm_valid), metrics(y_test, ebm_test)
                scores_valid = {"hgb": float(base["valid_hgb_brier"]), "catboost": float(base["valid_catboost_brier"]), "flaml": float(flaml_valid_metrics["brier"]), "ebm": float(ebm_valid_metrics["brier"])}
                scores_test = {"hgb": float(base["test_hgb_brier"]), "catboost": float(base["test_catboost_brier"]), "flaml": float(flaml_test_metrics["brier"]), "ebm": float(ebm_test_metrics["brier"])}
                valid_best, test_best = min(scores_valid.values()), min(scores_test.values())
                valid_winner = "+".join(name for name, value in scores_valid.items() if np.isclose(value, valid_best, rtol=0.0, atol=1e-15))
                test_winner = "+".join(name for name, value in scores_test.items() if np.isclose(value, test_best, rtol=0.0, atol=1e-15))
                row = {
                    "place": place, "course": course, "target": target, "feature_set": "post_exhibition", "feature_count": len(post_features),
                    "train_n": len(train), "valid_n": len(valid), "test_n": len(test), "train_positive_rate": float(np.mean(y_train)), "valid_positive_rate": float(np.mean(y_valid)), "test_positive_rate": float(np.mean(y_test)),
                    "valid_hgb_brier": scores_valid["hgb"], "valid_catboost_brier": scores_valid["catboost"], "valid_flaml_brier": scores_valid["flaml"], "valid_ebm_brier": scores_valid["ebm"], "valid_winner": valid_winner,
                    "test_hgb_brier": scores_test["hgb"], "test_catboost_brier": scores_test["catboost"], "test_flaml_brier": scores_test["flaml"], "test_ebm_brier": scores_test["ebm"], "test_winner": test_winner,
                    "flaml_selected_estimator": selected,
                    "hgb_fit_seconds": baseline_value(base, "hgb_fit_seconds"), "catboost_fit_seconds": baseline_value(base, "catboost_fit_seconds"), "flaml_fit_seconds": flaml_fit, "ebm_fit_seconds": ebm_fit,
                    "hgb_predict_seconds": baseline_value(base, "hgb_predict_seconds"), "catboost_predict_seconds": baseline_value(base, "catboost_predict_seconds"), "flaml_predict_seconds": flaml_predict, "ebm_predict_seconds": ebm_predict,
                }
                for model, value in (("hgb", None), ("catboost", None), ("flaml", flaml_test_metrics), ("ebm", ebm_test_metrics)):
                    if value is None:
                        for metric_name in ("log_loss", "auc", "accuracy_0_5", "probability_mean"):
                            row[f"test_{model}_{metric_name}"] = baseline_value(base, f"test_{model}_{metric_name}")
                    else:
                        for metric_name in ("log_loss", "auc", "accuracy_0_5", "probability_mean"):
                            row[f"test_{model}_{metric_name}"] = value[metric_name]
                summary.append(row)
                estimators.append({"place": place, "course": course, "target": target, "selected_estimator": selected, "internal_metric": "log_loss", "time_budget_seconds": FLAML_PARAMETERS["time_budget"], "max_iter": FLAML_PARAMETERS["max_iter"]})
                monthly.extend(four_model_monthly_rows(place, course, target, test, y_test, baseline_monthly, flaml_test, ebm_test))
                if key in representative:
                    terms = list(ebm.term_names_)
                    values = list(ebm.term_importances())
                    top = sorted(zip(terms, values), key=lambda item: float(item[1]), reverse=True)[:10]
                    importance.extend({"place": place, "course": course, "target": target, "rank": rank, "term": term, "importance": float(value)} for rank, (term, value) in enumerate(top, 1))
                print(f"{place} {course}C {target}: TEST Brier HGB={scores_test['hgb']:.6f} Cat={scores_test['catboost']:.6f} FLAML={scores_test['flaml']:.6f} EBM={scores_test['ebm']:.6f} ({test_winner})", flush=True)

    assert canonical_features is not None
    elapsed = time.perf_counter() - all_started
    import catboost
    import flaml
    import interpret
    experiment = {
        "experiment": "course_signal_model_compare_hgb_catboost_flaml_ebm", "created_at": time.strftime("%Y-%m-%dT%H:%M:%S%z"), "git_sha": git_sha(),
        "baseline_experiment_git_sha": baseline_experiment.get("git_sha"), "places": list(PLACES), "courses": list(COURSES), "targets": list(TARGETS),
        "period": {"dataset_start": "2023-09-27", "train": "2023-09-27 to 2025-08-31", "valid": "2025-09-01 to 2026-02-28", "test": "2026-03-01 to 2026-09-27"},
        "feature_set": "post_exhibition", "feature_count": len(canonical_features), "features": canonical_features, "random_seed": RANDOM_SEED,
        "hgb_parameters": HGB_PARAMETERS, "catboost_parameters": CATBOOST_PARAMETERS, "flaml_parameters": FLAML_PARAMETERS, "ebm_parameters": EBM_PARAMETERS,
        "dataset_audit": dataset_audit,
        "leakage_checks": {"results_only_used_as_labels": True, "payouts_and_odds_not_used": True, "point_in_time_history": "TechniqueHistoryIndex profiles before each race date (12 months)", "point_in_time_exhibition_average": "same venue trailing 183 days, current date excluded", "course_definition": "exhibition entry course, otherwise race_entry lane number", "flaml_test_not_used_for_fit_or_selection": True},
        "runtime": {"python": platform.python_version(), "scikit_learn": sklearn.__version__, "catboost": catboost.__version__, "flaml": flaml.__version__, "interpret": interpret.__version__, "numpy": np.__version__, "max_rss_kb": int(resource.getrusage(resource.RUSAGE_SELF).ru_maxrss), "elapsed_seconds": elapsed},
    }
    stem = str(args.output_stem)
    write_csv(output_dir / f"{stem}_summary.csv", summary)
    write_csv(output_dir / f"{stem}_monthly.csv", monthly)
    write_csv(output_dir / f"{stem}_flaml_estimators.csv", estimators)
    write_csv(output_dir / f"{stem}_ebm_importance.csv", importance)
    (output_dir / f"{stem}_experiment.json").write_text(json.dumps(experiment, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    write_four_model_markdown(output_dir / f"{stem}.md", experiment, summary, monthly, importance)
    print(f"summary: {output_dir / f'{stem}_summary.csv'}", flush=True)


if __name__ == "__main__":
    main()
