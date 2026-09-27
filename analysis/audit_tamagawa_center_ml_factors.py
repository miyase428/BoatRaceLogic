#!/usr/bin/env python3
"""多摩川3C・4Cをゼロベースで比較し、特徴群の寄与を監査する。

現行サインは比較対象にだけ使う。選手力・モーター/ボート・ST・
決まり手履歴・展示を一群ずつ外し、最終ホールドアウトで確率品質と
同一カバー率の選択成績を比較する。また、3C勝ち・4C勝ち・その他を
直接分類し、センター同士の優先順位も確認する。
"""

from __future__ import annotations

import argparse
import json
import sys
from datetime import date, timedelta
from pathlib import Path

import numpy as np
from sklearn.base import clone
from sklearn.ensemble import HistGradientBoostingClassifier
from sklearn.impute import SimpleImputer
from sklearn.inspection import permutation_importance
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import brier_score_loss, log_loss, roc_auc_score
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import StandardScaler

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "analysis"))

from audit_tamagawa_course_signals_zero_base_ml import (  # noqa: E402
    build_dataset,
    coverage_threshold,
    make_models,
    matrix,
)
from analyze_tamagawa_boaters_hypothesis import months_ago, parse_date  # noqa: E402


TARGETS = ("first", "top2", "top3")
COURSES = (3, 4)


def feature_groups(pre_features: list[str], post_features: list[str]) -> dict[str, list[str]]:
    groups: dict[str, list[str]] = {
        "player_strength": [],
        "motor_boat": [],
        "st": [],
        "technique": [],
        "exhibition": [],
    }
    strength_suffixes = (
        "_national_win_rate",
        "_national_exacta_rate",
        "_local_win_rate",
        "_local_exacta_rate",
    )
    motor_suffixes = ("_motor_exacta_rate", "_boat_exacta_rate")
    st_suffixes = ("_st_rank", "_average_start")
    technique_suffixes = (
        "_history_n",
        "_nige",
        "_sashi",
        "_makuri",
        "_attack",
    )
    pre_set = set(pre_features)
    for name in post_features:
        if name not in pre_set:
            groups["exhibition"].append(name)
        elif name.endswith(strength_suffixes):
            groups["player_strength"].append(name)
        elif name.endswith(motor_suffixes):
            groups["motor_boat"].append(name)
        elif name.endswith(st_suffixes):
            groups["st"].append(name)
        elif name.endswith(technique_suffixes) or name.startswith("lane1_vulnerability_"):
            groups["technique"].append(name)
        else:
            raise ValueError(f"未分類の特徴量: {name}")
    return groups


def binary_metrics(y: np.ndarray, p: np.ndarray) -> dict:
    return {
        "n": int(len(y)),
        "rate": float(np.mean(y)),
        "brier": float(brier_score_loss(y, p)),
        "nll": float(log_loss(y, np.clip(p, 1e-9, 1 - 1e-9), labels=[0, 1])),
        "auc": float(roc_auc_score(y, p)) if len(np.unique(y)) == 2 else None,
    }


def selected_metrics(y: np.ndarray, selected: np.ndarray) -> dict:
    n = int(np.sum(selected))
    return {
        "n": n,
        "coverage": float(np.mean(selected)),
        "rate": float(np.mean(y[selected])) if n else None,
    }


def choose_binary_model(train: list[dict], valid: list[dict], target: str, features: list[str]) -> str:
    x_train = matrix(train, features)
    x_valid = matrix(valid, features)
    y_train = np.asarray([row[target] for row in train], dtype=np.int8)
    y_valid = np.asarray([row[target] for row in valid], dtype=np.int8)
    scored = []
    for name, model in make_models().items():
        model.fit(x_train, y_train)
        probability = model.predict_proba(x_valid)[:, 1]
        scored.append((brier_score_loss(y_valid, probability), name))
    return min(scored)[1]


def evaluate_binary_ablation(
    rows: list[dict], target: str, all_features: list[str], groups: dict[str, list[str]]
) -> dict:
    train = [row for row in rows if row["split"] == "train"]
    valid = [row for row in rows if row["split"] == "valid"]
    test = [row for row in rows if row["split"] == "test"]
    y_train = np.asarray([row[target] for row in train], dtype=np.int8)
    y_valid = np.asarray([row[target] for row in valid], dtype=np.int8)
    y_test = np.asarray([row[target] for row in test], dtype=np.int8)
    model_name = choose_binary_model(train, valid, target, all_features)
    template = make_models()[model_name]
    current_valid = np.asarray([bool(row["current_signal"]) for row in valid])
    current_test = np.asarray([bool(row["current_signal"]) for row in test])
    coverage = float(np.mean(current_valid)) if np.any(current_valid) else 0.20

    variants = {"all": all_features}
    for group_name, excluded in groups.items():
        excluded_set = set(excluded)
        variants[f"without_{group_name}"] = [name for name in all_features if name not in excluded_set]

    result = {
        "model": model_name,
        "current": selected_metrics(y_test, current_test),
        "variants": {},
    }
    all_test_probability = None
    all_model = None
    all_x_test = None
    for variant, features in variants.items():
        model = clone(template)
        x_train = matrix(train, features)
        x_valid = matrix(valid, features)
        x_test = matrix(test, features)
        model.fit(x_train, y_train)
        valid_probability = model.predict_proba(x_valid)[:, 1]
        test_probability = model.predict_proba(x_test)[:, 1]
        threshold = coverage_threshold(valid_probability, coverage)
        result["variants"][variant] = {
            "feature_count": len(features),
            "threshold": float(threshold),
            "valid": binary_metrics(y_valid, valid_probability),
            "test": binary_metrics(y_test, test_probability),
            "selection": selected_metrics(y_test, test_probability >= threshold),
        }
        if variant == "all":
            all_test_probability = test_probability
            all_model = model
            all_x_test = x_test

    all_brier = result["variants"]["all"]["test"]["brier"]
    for group_name in groups:
        variant = result["variants"][f"without_{group_name}"]
        variant["brier_loss_without_group"] = variant["test"]["brier"] - all_brier

    importance = permutation_importance(
        all_model,
        all_x_test,
        y_test,
        scoring="neg_brier_score",
        n_repeats=4,
        random_state=20260927,
        n_jobs=1,
    )
    order = np.argsort(importance.importances_mean)[::-1][:15]
    result["top_features"] = [
        {"feature": all_features[int(index)], "importance": float(importance.importances_mean[int(index)])}
        for index in order
        if importance.importances_mean[int(index)] > 0
    ]
    result["test_probability"] = [float(value) for value in all_test_probability]
    return result


def multiclass_models() -> dict[str, Pipeline]:
    return {
        "logistic": Pipeline([
            ("imputer", SimpleImputer(strategy="median", add_indicator=True)),
            ("scale", StandardScaler()),
            ("model", LogisticRegression(C=0.5, max_iter=1800)),
        ]),
        "hist_gradient": Pipeline([
            ("imputer", SimpleImputer(strategy="median", add_indicator=True)),
            ("model", HistGradientBoostingClassifier(
                learning_rate=0.045,
                max_iter=240,
                max_leaf_nodes=15,
                min_samples_leaf=35,
                l2_regularization=1.0,
                random_state=20260927,
            )),
        ]),
    }


def multi_metrics(y: np.ndarray, p: np.ndarray) -> dict:
    classes = (0, 3, 4)
    one_hot = np.column_stack([y == cls for cls in classes]).astype(float)
    predicted = np.asarray(classes)[np.argmax(p, axis=1)]
    return {
        "n": int(len(y)),
        "nll": float(log_loss(y, np.clip(p, 1e-9, 1.0), labels=list(classes))),
        "brier_sum": float(np.mean(np.sum((p - one_hot) ** 2, axis=1))),
        "accuracy": float(np.mean(predicted == y)),
        "predicted_3_n": int(np.sum(predicted == 3)),
        "predicted_3_precision": float(np.mean(y[predicted == 3] == 3)) if np.any(predicted == 3) else None,
        "predicted_4_n": int(np.sum(predicted == 4)),
        "predicted_4_precision": float(np.mean(y[predicted == 4] == 4)) if np.any(predicted == 4) else None,
    }


def race_rows(records: list[dict]) -> list[dict]:
    by_code: dict[str, dict[int, dict]] = {}
    for row in records:
        if row["course"] in COURSES:
            by_code.setdefault(row["race_code"], {})[row["course"]] = row
    out = []
    for code, courses in sorted(by_code.items()):
        if set(courses) != set(COURSES):
            continue
        c3, c4 = courses[3], courses[4]
        winner = 3 if c3["first"] else (4 if c4["first"] else 0)
        out.append({
            "race_code": code,
            "date": c3["date"],
            "split": c3["split"],
            "center_winner": winner,
            "features": c3["features"],
        })
    return out


def evaluate_center_multiclass(
    rows: list[dict], all_features: list[str], groups: dict[str, list[str]]
) -> dict:
    train = [row for row in rows if row["split"] == "train"]
    valid = [row for row in rows if row["split"] == "valid"]
    test = [row for row in rows if row["split"] == "test"]
    y_train = np.asarray([row["center_winner"] for row in train], dtype=np.int8)
    y_valid = np.asarray([row["center_winner"] for row in valid], dtype=np.int8)
    y_test = np.asarray([row["center_winner"] for row in test], dtype=np.int8)
    x_train_all = matrix(train, all_features)
    x_valid_all = matrix(valid, all_features)
    scored = []
    for name, model in multiclass_models().items():
        model.fit(x_train_all, y_train)
        probability = model.predict_proba(x_valid_all)
        scored.append((log_loss(y_valid, probability, labels=[0, 3, 4]), name))
    model_name = min(scored)[1]
    template = multiclass_models()[model_name]
    variants = {"all": all_features}
    for group_name, excluded in groups.items():
        excluded_set = set(excluded)
        variants[f"without_{group_name}"] = [name for name in all_features if name not in excluded_set]

    result = {"model": model_name, "variants": {}}
    all_model = None
    all_x_test = None
    for variant, features in variants.items():
        model = clone(template)
        model.fit(matrix(train, features), y_train)
        probability = model.predict_proba(matrix(test, features))
        result["variants"][variant] = {
            "feature_count": len(features),
            "test": multi_metrics(y_test, probability),
        }
        if variant == "all":
            all_model = model
            all_x_test = matrix(test, features)
            result["test_probability"] = [[float(x) for x in row] for row in probability]

    all_nll = result["variants"]["all"]["test"]["nll"]
    for group_name in groups:
        variant = result["variants"][f"without_{group_name}"]
        variant["nll_loss_without_group"] = variant["test"]["nll"] - all_nll

    importance = permutation_importance(
        all_model,
        all_x_test,
        y_test,
        scoring="neg_log_loss",
        n_repeats=4,
        random_state=20260927,
        n_jobs=1,
    )
    order = np.argsort(importance.importances_mean)[::-1][:15]
    result["top_features"] = [
        {"feature": all_features[int(index)], "importance": float(importance.importances_mean[int(index)])}
        for index in order
        if importance.importances_mean[int(index)] > 0
    ]
    return result


def pct(value) -> str:
    return "-" if value is None else f"{100.0 * float(value):.2f}%"


def write_markdown(report: dict, path: Path) -> None:
    labels = {
        "player_strength": "選手力",
        "motor_boat": "モーター・ボート",
        "st": "ST",
        "technique": "決まり手履歴",
        "exhibition": "展示",
    }
    lines = [
        "# 多摩川3C・4C ML要素監査",
        "",
        f"- 対象期間: {report['period']['start']}～{report['period']['end']}",
        f"- 学習終了: {report['period']['train_end']} / 検証終了: {report['period']['valid_end']}",
        f"- 最終テスト: {report['period']['test_start']}～{report['period']['end']}",
        f"- 使用レース: {report['races']:,}R",
        "- 現行サインは比較用だけに使用し、モデル特徴量には入れていない。",
        "- Brier差は正なら、その要素を外すと悪化＝その要素に追加価値がある。",
        "",
    ]
    for course in COURSES:
        lines += [f"## {course}C", ""]
        for target in TARGETS:
            item = report["courses"][str(course)][target]
            all_item = item["variants"]["all"]
            lines += [
                f"### {target}",
                "",
                f"- 現行: {item['current']['n']}件 / {pct(item['current']['rate'])}",
                f"- 全要素ML: {all_item['selection']['n']}件 / {pct(all_item['selection']['rate'])}",
                f"- 全要素ML Brier: {all_item['test']['brier']:.6f}",
                "",
                "|外した要素|Brier差|選択 N/率|",
                "|---|---:|---:|",
            ]
            for group_name in labels:
                variant = item["variants"][f"without_{group_name}"]
                lines.append(
                    f"|{labels[group_name]}|{variant['brier_loss_without_group']:+.6f}|"
                    f"{variant['selection']['n']} / {pct(variant['selection']['rate'])}|"
                )
            features = "、".join(x["feature"] for x in item["top_features"][:8]) or "なし"
            lines += ["", f"上位特徴: {features}", ""]

    center = report["center_winner"]
    lines += [
        "## 3C勝ち・4C勝ち・その他の直接比較",
        "",
        f"- 採用モデル: {center['model']}",
        f"- 全要素 NLL: {center['variants']['all']['test']['nll']:.6f}",
        "",
        "|外した要素|NLL差|",
        "|---|---:|",
    ]
    for group_name in labels:
        variant = center["variants"][f"without_{group_name}"]
        lines.append(f"|{labels[group_name]}|{variant['nll_loss_without_group']:+.6f}|")
    features = "、".join(x["feature"] for x in center["top_features"][:10]) or "なし"
    lines += ["", f"上位特徴: {features}", ""]
    path.write_text("\n".join(lines) + "\n", encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--end", default=(date.today() - timedelta(days=1)).isoformat())
    parser.add_argument("--months", type=int, default=36)
    parser.add_argument("--valid-months", type=int, default=6)
    parser.add_argument("--test-months", type=int, default=6)
    parser.add_argument(
        "--output-prefix",
        type=Path,
        default=ROOT / "analysis" / "output" / "tamagawa_center_ml_factors_20260926",
    )
    args = parser.parse_args()
    end = parse_date(args.end)
    start = months_ago(end + timedelta(days=1), args.months)
    test_start = months_ago(end + timedelta(days=1), args.test_months)
    valid_start = months_ago(test_start, args.valid_months)
    config = json.loads((ROOT / "config" / "course_signal_rules.json").read_text(encoding="utf-8"))
    records, pre_features, post_features = build_dataset(start, end, config)
    for row in records:
        row["split"] = "train" if row["date"] < valid_start else ("valid" if row["date"] < test_start else "test")
    groups = feature_groups(pre_features, post_features)
    report = {
        "status": "ok",
        "version": "tamagawa-center-ml-factor-audit-v1",
        "period": {
            "start": start.isoformat(),
            "end": end.isoformat(),
            "train_end": (valid_start - timedelta(days=1)).isoformat(),
            "valid_end": (test_start - timedelta(days=1)).isoformat(),
            "test_start": test_start.isoformat(),
        },
        "races": len(records) // 6,
        "feature_groups": groups,
        "courses": {},
    }
    for course in COURSES:
        print(f"{course}C要素監査", flush=True)
        course_rows = [row for row in records if row["course"] == course]
        report["courses"][str(course)] = {
            target: evaluate_binary_ablation(course_rows, target, post_features, groups)
            for target in TARGETS
        }
    print("3C/4C直接比較", flush=True)
    report["center_winner"] = evaluate_center_multiclass(race_rows(records), post_features, groups)
    json_path = args.output_prefix.with_suffix(".json")
    md_path = args.output_prefix.with_suffix(".md")
    json_path.parent.mkdir(parents=True, exist_ok=True)
    json_path.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    write_markdown(report, md_path)
    print(f"JSON: {json_path}")
    print(f"REPORT: {md_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
