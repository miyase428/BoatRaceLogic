#!/usr/bin/env python3
"""多摩川1C・2C・5C・6CのコンパクトML候補を現行サインと比較する。"""

from __future__ import annotations

import argparse
import json
import sys
from datetime import date, timedelta
from pathlib import Path

import numpy as np
from sklearn.base import clone
from sklearn.metrics import brier_score_loss

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
from validate_tamagawa_center_ml_candidate import (  # noqa: E402
    GROUP_ORDER,
    TARGETS,
    choose_model_name,
    group_subsets,
    monthly_metrics,
    names_for_groups,
    probability_metrics,
    selection_metrics,
    validate_target,
)

CURRENT_COURSES = (1, 2, 5)
LANE6_COVERAGES = (0.05, 0.10, 0.15, 0.20)


def compact_model(
    train: list[dict], valid: list[dict], target: str, all_features: list[str], groups: dict
) -> tuple[object, dict, np.ndarray]:
    y_train = np.asarray([row[target] for row in train], dtype=np.int8)
    y_valid = np.asarray([row[target] for row in valid], dtype=np.int8)
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
    eligible = [item for item in candidates if item["valid_brier"] <= best_brier + 0.0005]
    chosen = min(eligible, key=lambda item: (len(item["groups"]), item["valid_brier"]))
    model = clone(template)
    model.fit(matrix(train, chosen["features"]), y_train)
    valid_probability = model.predict_proba(matrix(valid, chosen["features"]))[:, 1]
    return model, {
        "model": model_name,
        "groups": list(chosen["groups"]),
        "feature_count": len(chosen["features"]),
        "features": chosen["features"],
        "best_valid_brier": best_brier,
        "chosen_valid_brier": chosen["valid_brier"],
    }, valid_probability


def validate_lane6(rows: list[dict], target: str, all_features: list[str], groups: dict) -> dict:
    train = [row for row in rows if row["split"] == "train"]
    valid = [row for row in rows if row["split"] == "valid"]
    test = [row for row in rows if row["split"] == "test"]
    y_valid = np.asarray([row[target] for row in valid], dtype=np.int8)
    y_test = np.asarray([row[target] for row in test], dtype=np.int8)
    model, chosen, valid_probability = compact_model(train, valid, target, all_features, groups)
    test_probability = model.predict_proba(matrix(test, chosen.pop("features")))[:, 1]
    baseline = np.ones(len(test), dtype=bool)
    candidates = {}
    for coverage in LANE6_COVERAGES:
        threshold = coverage_threshold(valid_probability, coverage)
        selected = test_probability >= threshold
        candidates[f"{int(coverage * 100)}pct"] = {
            "validation_coverage": coverage,
            "threshold": float(threshold),
            **selection_metrics(y_test, selected),
            "uncertainty_vs_baseline": bootstrap_rate_difference(y_test, selected, baseline),
            "monthly": monthly_metrics(test, y_test, baseline, selected),
        }
    return {
        **chosen,
        "probability": {
            "valid": probability_metrics(y_valid, valid_probability),
            "test": probability_metrics(y_test, test_probability),
        },
        "baseline": selection_metrics(y_test, baseline),
        "coverage_candidates": candidates,
    }


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
        "# 多摩川1C・2C・5C・6C コンパクトML候補検証",
        "",
        f"- 対象: {report['period']['start']}～{report['period']['end']}",
        f"- 学習: ～{report['period']['train_end']}",
        f"- 特徴選択用検証: {report['period']['valid_start']}～{report['period']['valid_end']}",
        f"- 最終テスト: {report['period']['test_start']}～{report['period']['end']}",
        f"- 使用レース: {report['races']:,}R",
        "- 1C・2C・5Cは現行サインとML候補の表示件数を完全一致。",
        "- 6Cは現行主サインが無効のため、事前固定した表示率ごとに全体平均との差を評価。",
        "",
        "|対象|採用要素|現行 N/率|ML同数 N/率|差|改善確率|",
        "|---|---|---:|---:|---:|---:|",
    ]
    for course in CURRENT_COURSES:
        for target in TARGETS:
            item = report["courses"][str(course)][target]
            group_text = "＋".join(labels[name] for name in item["groups"])
            current = item["current"]
            candidate = item["candidate_equal_count"]
            uncertainty = item["uncertainty_equal_count"]
            lines.append(
                f"|{course}C {target}|{group_text}|{current['n']} / {pct(current['rate'])}|"
                f"{candidate['n']} / {pct(candidate['rate'])}|{pct(uncertainty['difference'])}|"
                f"{pct(uncertainty['probability_improves'])}|"
            )
    lines += ["", "## 6C（現行主サインなし）", "", "|対象|採用要素|表示率|N/率|全体率|差|改善確率|", "|---|---|---:|---:|---:|---:|---:|"]
    for target in TARGETS:
        item = report["courses"]["6"][target]
        group_text = "＋".join(labels[name] for name in item["groups"])
        for key, candidate in item["coverage_candidates"].items():
            uncertainty = candidate["uncertainty_vs_baseline"]
            lines.append(
                f"|6C {target}|{group_text}|{key}|{candidate['n']} / {pct(candidate['rate'])}|"
                f"{pct(item['baseline']['rate'])}|{pct(uncertainty['difference'])}|"
                f"{pct(uncertainty['probability_improves'])}|"
            )
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
        default=ROOT / "analysis" / "output" / "tamagawa_other_course_ml_candidate_20260926",
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
        "version": "tamagawa-other-course-compact-ml-candidate-v1",
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
    for course in (*CURRENT_COURSES, 6):
        print(f"{course}C候補検証", flush=True)
        rows = [row for row in records if row["course"] == course]
        report["courses"][str(course)] = {}
        for target in TARGETS:
            print(f"  {target}", flush=True)
            if course == 6:
                item = validate_lane6(rows, target, post_features, groups)
            else:
                item, _, _, _ = validate_target(rows, target, post_features, groups)
            report["courses"][str(course)][target] = item
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
