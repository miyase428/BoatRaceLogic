#!/usr/bin/env python3
"""場別コースサイン v1 の検証済みモデルを固定保存する。"""

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
MODEL_PATHS = {
    "TMG": ROOT / "forecast" / "models" / "tamagawa_center_signal_v1.joblib",
    "TDA": ROOT / "forecast" / "models" / "toda_course_signal_v1.joblib",
    "OMR": ROOT / "forecast" / "models" / "omura_course_signal_v1.joblib",
    "SMS": ROOT / "forecast" / "models" / "shimonoseki_course_signal_v1.joblib",
}

# 特徴群とアルゴリズムは、特徴選択用期間だけで決めた候補を固定する。
TMG_SPECS = {
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
TDA_SPECS = {
    1: {
        "top3": {"model": "hist_gradient", "groups": ("player_strength", "st", "technique")},
    },
    2: {
        "first": {"model": "logistic", "groups": ("player_strength", "exhibition")},
        "top2": {"model": "hist_gradient", "groups": ("player_strength", "st", "exhibition")},
        "top3": {"model": "logistic", "groups": ("player_strength", "motor_boat")},
    },
    3: {
        "top3": {"model": "logistic", "groups": ("player_strength", "exhibition")},
    },
    4: {
        "first": {"model": "hist_gradient", "groups": ("st", "technique", "exhibition")},
        "top2": {"model": "hist_gradient", "groups": ("player_strength", "st", "technique", "exhibition")},
        "top3": {"model": "logistic", "groups": ("player_strength", "motor_boat")},
    },
    5: {
        "top2": {"model": "logistic", "groups": ("player_strength",), "coverage": 0.15},
        "top3": {"model": "logistic", "groups": ("player_strength", "st", "exhibition"), "coverage": 0.15},
    },
    6: {
        "top3": {"model": "logistic", "groups": ("player_strength", "technique"), "coverage": 0.15},
    },
}
OMR_SPECS = {
    1: {
        "top2": {"model": "hist_gradient", "groups": ("st", "technique", "exhibition")},
    },
    2: {
        "first": {"model": "hist_gradient", "groups": ("motor_boat", "technique", "exhibition")},
        "top2": {"model": "hist_gradient", "groups": ("player_strength", "technique", "exhibition")},
        "top3": {"model": "logistic", "groups": ("player_strength", "exhibition")},
    },
    3: {
        "first": {"model": "logistic", "groups": ("player_strength", "motor_boat", "st")},
        "top2": {"model": "hist_gradient", "groups": ("player_strength", "motor_boat", "st", "technique", "exhibition")},
        "top3": {"model": "hist_gradient", "groups": ("player_strength", "st", "technique", "exhibition")},
    },
    4: {
        "first": {"model": "logistic", "groups": ("player_strength",), "coverage": 0.15},
        "top2": {"model": "logistic", "groups": ("player_strength", "motor_boat"), "coverage": 0.15},
        "top3": {"model": "logistic", "groups": ("player_strength", "motor_boat", "exhibition"), "coverage": 0.15},
    },
    5: {
        "first": {"model": "logistic", "groups": ("player_strength",), "coverage": 0.15},
        "top2": {"model": "hist_gradient", "groups": ("player_strength", "motor_boat", "st", "exhibition"), "coverage": 0.15},
        "top3": {"model": "hist_gradient", "groups": ("player_strength", "exhibition"), "coverage": 0.15},
    },
    6: {
        "top3": {"model": "logistic", "groups": ("player_strength", "motor_boat", "exhibition"), "coverage": 0.15},
    },
}
SMS_SPECS = {
    2: {
        "first": {"model": "hist_gradient", "groups": ("player_strength", "motor_boat", "technique")},
    },
    3: {
        "first": {"model": "logistic", "groups": ("player_strength", "motor_boat"), "coverage": 0.15},
        "top2": {"model": "logistic", "groups": ("player_strength", "exhibition"), "coverage": 0.15},
        "top3": {"model": "logistic", "groups": ("player_strength",), "coverage": 0.15},
    },
    4: {
        "top3": {"model": "logistic", "groups": ("player_strength", "exhibition")},
    },
    5: {
        "top2": {"model": "logistic", "groups": ("player_strength", "exhibition"), "coverage": 0.15},
        "top3": {"model": "logistic", "groups": ("player_strength", "st", "exhibition"), "coverage": 0.15},
    },
    6: {
        "top3": {"model": "logistic", "groups": ("player_strength", "st", "technique"), "coverage": 0.15},
    },
}
SPECS_BY_PLACE = {"TMG": TMG_SPECS, "TDA": TDA_SPECS, "OMR": OMR_SPECS, "SMS": SMS_SPECS}
VERSION_BY_PLACE = {
    "TMG": "tamagawa_course_signal_v1",
    "TDA": "toda_course_signal_v1",
    "OMR": "omura_course_signal_v1",
    "SMS": "shimonoseki_course_signal_v1",
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
    global audit
    import argparse

    parser = argparse.ArgumentParser()
    parser.add_argument("--place", default="TMG", choices=sorted(SPECS_BY_PLACE))
    args = parser.parse_args()
    place = str(args.place)
    audit = __import__("audit_tamagawa_course_signals_zero_base_ml")
    audit.PLACE = place
    specs = SPECS_BY_PLACE[place]
    model_path = MODEL_PATHS[place]
    config = json.loads((ROOT / "config" / "course_signal_rules.json").read_text(encoding="utf-8"))
    records, pre_features, post_features = audit.build_dataset(START, END, config)
    groups = feature_groups(pre_features, post_features)
    artifact = {
        "version": VERSION_BY_PLACE[place],
        "place": place,
        "trained_at": date.today().isoformat(),
        "period": {
            "start": START.isoformat(),
            "train_end": (VALID_START - timedelta(days=1)).isoformat(),
            "valid_start": VALID_START.isoformat(),
            "valid_end": (TEST_START - timedelta(days=1)).isoformat(),
            "test_start": TEST_START.isoformat(),
            "test_end": END.isoformat(),
        },
        "feature_contract": "venue-course-prerace-v1",
        "models": {},
    }

    for course, targets in specs.items():
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

    model_path.parent.mkdir(parents=True, exist_ok=True)
    joblib.dump(artifact, model_path, compress=3)
    digest = hashlib.sha256(model_path.read_bytes()).hexdigest()
    manifest = {
        "version": artifact["version"],
        "path": str(model_path),
        "sha256": digest,
        "period": artifact["period"],
    }
    model_path.with_suffix(".json").write_text(
        json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    print(f"MODEL: {model_path}")
    print(f"SHA256: {digest}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
