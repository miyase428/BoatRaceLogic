#!/usr/bin/env python3
"""コースサイン用の固定データセットで4モデルを公平に比較する。

モデル選択・特徴量選択・閾値選択は行わない。既存の point-in-time
データセット生成器が返す展示込み170特徴量を、全36ケースへそのまま渡す。
VALID / TEST は評価専用であり、TEST はいかなる設定変更にも使わない。
"""

from __future__ import annotations

import argparse
import csv
import json
import platform
import resource
import subprocess
import sys
import time
from datetime import date
from pathlib import Path

import numpy as np
import sklearn
from catboost import CatBoostClassifier
from flaml import AutoML
from interpret.glassbox import ExplainableBoostingClassifier
from sklearn.base import clone
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


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output-stem", default="course_signal_model_compare_asy_amg_20260927_four_models")
    parser.add_argument("--baseline-stem", default="course_signal_model_compare_asy_amg_20260927")
    args = parser.parse_args()
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
