#!/usr/bin/env python3
"""コースサイン用の固定データセットで HGB と CatBoost を比較する。

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


def main() -> None:
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


if __name__ == "__main__":
    main()
