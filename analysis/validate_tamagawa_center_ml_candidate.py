#!/usr/bin/env python3
"""多摩川3C・4CのコンパクトML候補を現行サインと同数比較する。"""

from __future__ import annotations

import argparse
import itertools
import json
import sys
from datetime import date, timedelta
from pathlib import Path

import numpy as np
from sklearn.base import clone
from sklearn.metrics import brier_score_loss, log_loss, roc_auc_score

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "analysis"))

from audit_tamagawa_center_ml_factors import feature_groups  # noqa: E402
from audit_tamagawa_course_signals_zero_base_ml import (  # noqa: E402
    bootstrap_rate_difference,
    build_dataset,
    coverage_threshold,
    make_models,
    matrix,
)
from analyze_tamagawa_boaters_hypothesis import months_ago, parse_date  # noqa: E402


COURSES = (3, 4)
TARGETS = ("first", "top2", "top3")
GROUP_ORDER = ("player_strength", "motor_boat", "st", "technique", "exhibition")


def probability_metrics(y: np.ndarray, probability: np.ndarray) -> dict:
    return {
        "n": int(len(y)),
        "rate": float(np.mean(y)),
        "brier": float(brier_score_loss(y, probability)),
        "nll": float(log_loss(y, np.clip(probability, 1e-9, 1 - 1e-9), labels=[0, 1])),
        "auc": float(roc_auc_score(y, probability)) if len(np.unique(y)) == 2 else None,
    }


def selection_metrics(y: np.ndarray, selected: np.ndarray) -> dict:
    count = int(np.sum(selected))
    return {
        "n": count,
        "coverage": float(np.mean(selected)),
        "rate": float(np.mean(y[selected])) if count else None,
    }


def equal_count_selection(probability: np.ndarray, count: int) -> np.ndarray:
    count = max(0, min(int(count), len(probability)))
    selected = np.zeros(len(probability), dtype=bool)
    if count:
        order = np.argsort(-probability, kind="stable")
        selected[order[:count]] = True
    return selected


def group_subsets() -> list[tuple[str, ...]]:
    return [
        tuple(combo)
        for size in range(1, len(GROUP_ORDER) + 1)
        for combo in itertools.combinations(GROUP_ORDER, size)
    ]


def names_for_groups(groups: dict[str, list[str]], selected: tuple[str, ...]) -> list[str]:
    chosen = {name for group in selected for name in groups[group]}
    return [name for group in GROUP_ORDER for name in groups[group] if name in chosen]


def choose_model_name(
    train: list[dict], valid: list[dict], target: str, all_features: list[str]
) -> str:
    y_train = np.asarray([row[target] for row in train], dtype=np.int8)
    y_valid = np.asarray([row[target] for row in valid], dtype=np.int8)
    x_train = matrix(train, all_features)
    x_valid = matrix(valid, all_features)
    scored = []
    for name, model in make_models().items():
        model.fit(x_train, y_train)
        scored.append((brier_score_loss(y_valid, model.predict_proba(x_valid)[:, 1]), name))
    return min(scored)[1]


def monthly_metrics(
    rows: list[dict], y: np.ndarray, current: np.ndarray, candidate: np.ndarray
) -> dict:
    out = {}
    month_values = np.asarray([row["date"].strftime("%Y-%m") for row in rows])
    for month in sorted(set(month_values)):
        mask = month_values == month
        out[month] = {
            "base": selection_metrics(y[mask], np.ones(int(np.sum(mask)), dtype=bool)),
            "current": selection_metrics(y[mask], current[mask]),
            "candidate": selection_metrics(y[mask], candidate[mask]),
        }
    return out


def validate_target(
    rows: list[dict], target: str, all_features: list[str], groups: dict[str, list[str]]
) -> tuple[dict, np.ndarray, np.ndarray, np.ndarray]:
    train = [row for row in rows if row["split"] == "train"]
    valid = [row for row in rows if row["split"] == "valid"]
    test = [row for row in rows if row["split"] == "test"]
    y_train = np.asarray([row[target] for row in train], dtype=np.int8)
    y_valid = np.asarray([row[target] for row in valid], dtype=np.int8)
    y_test = np.asarray([row[target] for row in test], dtype=np.int8)
    current_valid = np.asarray([bool(row["current_signal"]) for row in valid])
    current_test = np.asarray([bool(row["current_signal"]) for row in test])
    model_name = choose_model_name(train, valid, target, all_features)
    template = make_models()[model_name]

    candidates = []
    for subset in group_subsets():
        features = names_for_groups(groups, subset)
        model = clone(template)
        model.fit(matrix(train, features), y_train)
        probability = model.predict_proba(matrix(valid, features))[:, 1]
        candidates.append({
            "groups": subset,
            "features": features,
            "valid_brier": float(brier_score_loss(y_valid, probability)),
        })
    best_brier = min(item["valid_brier"] for item in candidates)
    # ほぼ同等なら少ない特徴群を優先し、検証期間への過適合を抑える。
    eligible = [item for item in candidates if item["valid_brier"] <= best_brier + 0.0005]
    chosen = min(eligible, key=lambda item: (len(item["groups"]), item["valid_brier"]))
    model = clone(template)
    model.fit(matrix(train, chosen["features"]), y_train)
    valid_probability = model.predict_proba(matrix(valid, chosen["features"]))[:, 1]
    test_probability = model.predict_proba(matrix(test, chosen["features"]))[:, 1]

    current_count = int(np.sum(current_test))
    equal_selected = equal_count_selection(test_probability, current_count)
    valid_coverage = float(np.mean(current_valid)) if np.any(current_valid) else 0.20
    threshold = coverage_threshold(valid_probability, valid_coverage)
    frozen_selected = test_probability >= threshold
    result = {
        "model": model_name,
        "groups": list(chosen["groups"]),
        "feature_count": len(chosen["features"]),
        "best_valid_brier": best_brier,
        "chosen_valid_brier": chosen["valid_brier"],
        "probability": {
            "valid": probability_metrics(y_valid, valid_probability),
            "test": probability_metrics(y_test, test_probability),
        },
        "current": selection_metrics(y_test, current_test),
        "candidate_equal_count": selection_metrics(y_test, equal_selected),
        "candidate_frozen_threshold": {
            "threshold": float(threshold),
            **selection_metrics(y_test, frozen_selected),
        },
        "uncertainty_equal_count": bootstrap_rate_difference(y_test, equal_selected, current_test),
        "monthly_equal_count": monthly_metrics(test, y_test, current_test, equal_selected),
    }
    return result, test_probability, y_test, equal_selected


def pct(value) -> str:
    return "-" if value is None else f"{100.0 * float(value):.2f}%"


def write_markdown(report: dict, path: Path) -> None:
    group_labels = {
        "player_strength": "選手力",
        "motor_boat": "モーター・ボート",
        "st": "ST",
        "technique": "決まり手履歴",
        "exhibition": "展示",
    }
    lines = [
        "# 多摩川3C・4C コンパクトML候補検証",
        "",
        f"- 対象: {report['period']['start']}～{report['period']['end']}",
        f"- 学習: ～{report['period']['train_end']}",
        f"- 特徴選択用検証: {report['period']['valid_start']}～{report['period']['valid_end']}",
        f"- 最終未使用テスト: {report['period']['test_start']}～{report['period']['end']}",
        f"- 使用レース: {report['races']:,}R",
        "- 最終テストでは現行サインとML候補の選択件数を完全に一致させた。",
        "",
        "|対象|採用要素|現行 N/率|ML同数 N/率|差|改善確率|",
        "|---|---|---:|---:|---:|---:|",
    ]
    for course in COURSES:
        for target in TARGETS:
            item = report["courses"][str(course)][target]
            labels = "＋".join(group_labels[name] for name in item["groups"])
            current = item["current"]
            candidate = item["candidate_equal_count"]
            uncertainty = item["uncertainty_equal_count"]
            lines.append(
                f"|{course}C {target}|{labels}|{current['n']} / {pct(current['rate'])}|"
                f"{candidate['n']} / {pct(candidate['rate'])}|{pct(uncertainty['difference'])}|"
                f"{pct(uncertainty['probability_improves'])}|"
            )
    lines += ["", "## 月別", ""]
    for course in COURSES:
        for target in TARGETS:
            item = report["courses"][str(course)][target]
            lines += [f"### {course}C {target}", "", "|月|現行 N/率|ML N/率|", "|---|---:|---:|"]
            for month, values in item["monthly_equal_count"].items():
                lines.append(
                    f"|{month}|{values['current']['n']} / {pct(values['current']['rate'])}|"
                    f"{values['candidate']['n']} / {pct(values['candidate']['rate'])}|"
                )
            lines.append("")
    priority = report["center_priority"]
    lines += [
        "## 3C・4C優先判定",
        "",
        f"- 対象レース: {priority['n']}R",
        f"- 3C優先: {priority['course3_n']}R / 3C1着率 {pct(priority['course3_hit_rate'])}",
        f"- 4C優先: {priority['course4_n']}R / 4C1着率 {pct(priority['course4_hit_rate'])}",
        f"- 優先艇の総合1着率: {pct(priority['hit_rate'])}",
    ]
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
        default=ROOT / "analysis" / "output" / "tamagawa_center_ml_candidate_20260926",
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
        "version": "tamagawa-center-compact-ml-candidate-v1",
        "period": {
            "start": start.isoformat(),
            "end": end.isoformat(),
            "train_end": (valid_start - timedelta(days=1)).isoformat(),
            "valid_start": valid_start.isoformat(),
            "valid_end": (test_start - timedelta(days=1)).isoformat(),
            "test_start": test_start.isoformat(),
        },
        "races": len(records) // 6,
        "courses": {},
    }
    first_outputs = {}
    for course in COURSES:
        print(f"{course}C候補検証", flush=True)
        course_rows = [row for row in records if row["course"] == course]
        report["courses"][str(course)] = {}
        for target in TARGETS:
            print(f"  {target}", flush=True)
            item, probability, y_test, selected = validate_target(
                course_rows, target, post_features, groups
            )
            report["courses"][str(course)][target] = item
            if target == "first":
                first_outputs[course] = (probability, y_test, selected)

    p3, y3, s3 = first_outputs[3]
    p4, y4, s4 = first_outputs[4]
    eligible = s3 | s4
    choose4 = eligible & (p4 > p3)
    choose3 = eligible & ~choose4
    hits = (choose3 & (y3 == 1)) | (choose4 & (y4 == 1))
    report["center_priority"] = {
        "n": int(np.sum(eligible)),
        "course3_n": int(np.sum(choose3)),
        "course3_hit_rate": float(np.mean(y3[choose3])) if np.any(choose3) else None,
        "course4_n": int(np.sum(choose4)),
        "course4_hit_rate": float(np.mean(y4[choose4])) if np.any(choose4) else None,
        "hit_rate": float(np.sum(hits) / np.sum(eligible)) if np.any(eligible) else None,
    }
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
