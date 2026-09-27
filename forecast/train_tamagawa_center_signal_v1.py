#!/usr/bin/env python3
"""多摩川コースサイン v1 の検証済みモデルを固定保存する。"""

from __future__ import annotations

import hashlib
import json
import sys
from datetime import date, timedelta
from pathlib import Path

import joblib
import numpy as np
from sklearn.base import clone

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "analysis"))

from audit_tamagawa_center_ml_factors import feature_groups  # noqa: E402
from audit_tamagawa_course_signals_zero_base_ml import (  # noqa: E402
    build_dataset,
    coverage_threshold,
    make_models,
    matrix,
)


END = date(2026, 9, 26)
START = date(2023, 9, 27)
VALID_START = date(2025, 9, 27)
TEST_START = date(2026, 3, 27)
MODEL_PATH = ROOT / "forecast" / "models" / "tamagawa_center_signal_v1.joblib"

# 特徴群とアルゴリズムは、特徴選択用期間だけで決めた候補を固定する。
SPECS = {
    2: {
        "top2": {"model": "logistic", "groups": ("player_strength", "motor_boat", "exhibition")},
        "top3": {"model": "hist_gradient", "groups": ("player_strength", "motor_boat", "st", "exhibition")},
    },
    3: {
        "first": {"model": "logistic", "groups": ("technique", "exhibition")},
        "top2": {"model": "hist_gradient", "groups": ("technique", "exhibition")},
        "top3": {"model": "logistic", "groups": ("player_strength", "motor_boat", "st")},
    },
    4: {
        "first": {"model": "logistic", "groups": ("player_strength", "motor_boat", "technique", "exhibition")},
        "top2": {"model": "logistic", "groups": ("player_strength", "motor_boat", "st")},
        "top3": {"model": "logistic", "groups": ("player_strength", "motor_boat")},
    },
    5: {
        "top3": {"model": "logistic", "groups": ("player_strength", "st", "exhibition")},
    },
    6: {
        "top2": {"model": "logistic", "groups": ("player_strength", "technique"), "coverage": 0.15},
        "top3": {"model": "logistic", "groups": ("player_strength", "technique"), "coverage": 0.15},
    },
}
GROUP_ORDER = ("player_strength", "motor_boat", "st", "technique", "exhibition")


def selected_feature_names(groups: dict[str, list[str]], selected: tuple[str, ...]) -> list[str]:
    allowed = {name for group in selected for name in groups[group]}
    return [name for group in GROUP_ORDER for name in groups[group] if name in allowed]


def selection_stats(rows: list[dict], probability: np.ndarray, threshold: float) -> dict:
    selected = probability >= threshold
    n = int(selected.sum())
    return {
        "n": n,
        "coverage": float(selected.mean()),
        "first_rate": float(np.mean([rows[i]["first"] for i in range(len(rows)) if selected[i]])) if n else None,
        "top2_rate": float(np.mean([rows[i]["top2"] for i in range(len(rows)) if selected[i]])) if n else None,
        "top3_rate": float(np.mean([rows[i]["top3"] for i in range(len(rows)) if selected[i]])) if n else None,
        "period": f"{TEST_START.isoformat()}～{END.isoformat()}",
    }


def main() -> int:
    config = json.loads((ROOT / "config" / "course_signal_rules.json").read_text(encoding="utf-8"))
    records, pre_features, post_features = build_dataset(START, END, config)
    groups = feature_groups(pre_features, post_features)
    artifact = {
        "version": "tamagawa_course_signal_v1",
        "trained_at": date.today().isoformat(),
        "period": {
            "start": START.isoformat(),
            "train_end": (VALID_START - timedelta(days=1)).isoformat(),
            "valid_start": VALID_START.isoformat(),
            "valid_end": (TEST_START - timedelta(days=1)).isoformat(),
            "test_start": TEST_START.isoformat(),
            "test_end": END.isoformat(),
        },
        "feature_contract": "tamagawa-course-prerace-v1",
        "models": {},
    }

    for course, targets in SPECS.items():
        rows = [row for row in records if row["course"] == course]
        train = [row for row in rows if row["date"] < VALID_START]
        valid = [row for row in rows if VALID_START <= row["date"] < TEST_START]
        test = [row for row in rows if row["date"] >= TEST_START]
        current_valid = np.asarray([bool(row["current_signal"]) for row in valid])
        artifact["models"][str(course)] = {}
        for target, spec in targets.items():
            coverage = float(spec.get("coverage", current_valid.mean() if current_valid.any() else 0.20))
            names = selected_feature_names(groups, spec["groups"])
            model = clone(make_models()[spec["model"]])
            model.fit(matrix(train, names), np.asarray([row[target] for row in train], dtype=np.int8))
            valid_probability = model.predict_proba(matrix(valid, names))[:, 1]
            threshold = coverage_threshold(valid_probability, coverage)
            test_probability = model.predict_proba(matrix(test, names))[:, 1]
            artifact["models"][str(course)][target] = {
                "algorithm": spec["model"],
                "groups": list(spec["groups"]),
                "feature_names": names,
                "uses_exhibition": "exhibition" in spec["groups"],
                "threshold": float(threshold),
                "test_selection": selection_stats(test, test_probability, threshold),
                "model": model,
            }
            stats = artifact["models"][str(course)][target]["test_selection"]
            print(
                f"{course}C {target}: threshold={threshold:.12f} "
                f"N={stats['n']} {target}="
                f"{100.0 * float(stats[target + '_rate'] or 0):.2f}%",
                flush=True,
            )

    MODEL_PATH.parent.mkdir(parents=True, exist_ok=True)
    joblib.dump(artifact, MODEL_PATH, compress=3)
    digest = hashlib.sha256(MODEL_PATH.read_bytes()).hexdigest()
    manifest = {
        "version": artifact["version"],
        "path": str(MODEL_PATH),
        "sha256": digest,
        "period": artifact["period"],
    }
    MODEL_PATH.with_suffix(".json").write_text(
        json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    print(f"MODEL: {MODEL_PATH}")
    print(f"SHA256: {digest}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
