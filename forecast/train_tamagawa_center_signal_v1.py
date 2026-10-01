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
import sklearn
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
    "AMG": ROOT / "forecast" / "models" / "amagasaki_course_signal_v1.joblib",
    "ASY": ROOT / "forecast" / "models" / "ashiya_course_signal_v1.joblib",
    "BWK": ROOT / "forecast" / "models" / "biwako_course_signal_v1.joblib",
    "HWJ": ROOT / "forecast" / "models" / "heiwajima_course_signal_v1.joblib",
    "KRY": ROOT / "forecast" / "models" / "kiryuu_course_signal_v1.joblib",
    "TMG": ROOT / "forecast" / "models" / "tamagawa_center_signal_v1.joblib",
    "TDA": ROOT / "forecast" / "models" / "toda_course_signal_v1.joblib",
    "OMR": ROOT / "forecast" / "models" / "omura_course_signal_v1.joblib",
    "SMS": ROOT / "forecast" / "models" / "shimonoseki_course_signal_v1.joblib",
    "SME": ROOT / "forecast" / "models" / "suminoe_course_signal_v1.joblib",
}

PERIODS_BY_PLACE = {
    "AMG": (date(2023, 9, 27), date(2025, 9, 1), date(2026, 3, 1), date(2026, 9, 27)),
    "ASY": (date(2023, 9, 27), date(2025, 9, 1), date(2026, 3, 1), date(2026, 9, 27)),
    "BWK": (date(2023, 9, 27), date(2025, 9, 1), date(2026, 3, 1), date(2026, 9, 27)),
    "HWJ": (date(2023, 9, 28), date(2025, 9, 28), date(2026, 3, 28), date(2026, 9, 27)),
    "KRY": (date(2023, 9, 28), date(2025, 9, 28), date(2026, 3, 28), date(2026, 9, 27)),
    "SME": (date(2023, 9, 28), date(2025, 9, 28), date(2026, 3, 28), date(2026, 9, 27)),
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
SME_SPECS = {
    2: {
        "first": {"model": "hist_gradient", "groups": ("player_strength", "motor_boat", "technique"), "coverage": 0.10},
        "top2": {"model": "logistic", "groups": ("player_strength", "motor_boat", "exhibition"), "coverage": 0.15},
        "top3": {"model": "logistic", "groups": ("player_strength", "motor_boat", "exhibition"), "coverage": 0.15},
    },
    3: {
        "first": {"model": "hist_gradient", "groups": ("player_strength", "technique", "exhibition")},
    },
    4: {
        "first": {"model": "hist_gradient", "groups": ("player_strength", "exhibition"), "coverage": 0.15},
        "top2": {"model": "hist_gradient", "groups": ("player_strength", "motor_boat", "exhibition"), "coverage": 0.15},
        "top3": {"model": "logistic", "groups": ("player_strength", "exhibition"), "coverage": 0.15},
    },
    5: {
        "first": {"model": "logistic", "groups": ("player_strength",), "coverage": 0.10},
        "top2": {"model": "logistic", "groups": ("player_strength", "exhibition"), "coverage": 0.15},
        "top3": {"model": "hist_gradient", "groups": ("player_strength", "motor_boat", "exhibition"), "coverage": 0.15},
    },
    6: {
        "top2": {"model": "logistic", "groups": ("player_strength",), "coverage": 0.15},
        "top3": {"model": "hist_gradient", "groups": ("player_strength", "motor_boat", "st", "exhibition"), "coverage": 0.15},
    },
}
KRY_SPECS = {
    1: {
        "top2": {"model": "hist_gradient", "groups": ("player_strength", "technique", "exhibition")},
    },
    2: {
        "first": {"model": "hist_gradient", "groups": ("player_strength", "exhibition")},
        "top2": {"model": "hist_gradient", "groups": ("player_strength", "motor_boat", "st", "technique")},
        "top3": {"model": "logistic", "groups": ("player_strength",)},
    },
    3: {
        "first": {"model": "hist_gradient", "groups": ("player_strength", "motor_boat", "st", "technique", "exhibition"), "coverage": 0.15},
        "top2": {"model": "hist_gradient", "groups": ("player_strength", "st", "exhibition"), "coverage": 0.15},
        "top3": {"model": "logistic", "groups": ("player_strength",), "coverage": 0.15},
    },
    5: {
        "top2": {"model": "hist_gradient", "groups": ("player_strength", "st", "exhibition")},
        "top3": {"model": "hist_gradient", "groups": ("player_strength", "exhibition")},
    },
    6: {
        "top2": {"model": "hist_gradient", "groups": ("player_strength",), "coverage": 0.15},
        "top3": {"model": "hist_gradient", "groups": ("player_strength", "motor_boat", "exhibition"), "coverage": 0.15},
    },
}
ASY_SPECS = {
    3: {
        "top3": {
            "model": "logistic",
            "groups": ("player_strength", "motor_boat"),
            "coverage": 0.35365853658536583,
        },
    },
    4: {
        "top3": {
            "model": "hist_gradient",
            "groups": ("player_strength", "motor_boat", "st", "technique", "exhibition"),
            "coverage": 0.05574912891986063,
        },
    },
}
AMG_SPECS = {
    2: {
        "first": {
            "model": "hist_gradient",
            "groups": ("player_strength",),
            "coverage": 0.24732824427480915,
        },
        "top3": {
            "model": "logistic",
            "groups": ("player_strength", "exhibition"),
            "coverage": 0.24732824427480915,
        },
    },
    3: {
        "first": {
            "model": "hist_gradient",
            "groups": ("player_strength", "st"),
            "coverage": 0.17862595419847327,
        },
        "top2": {
            "model": "logistic",
            "groups": ("player_strength",),
            "coverage": 0.17862595419847327,
        },
        "top3": {
            "model": "hist_gradient",
            "groups": ("player_strength", "st"),
            "coverage": 0.17862595419847327,
        },
    },
    4: {
        "top2": {
            "model": "hist_gradient",
            "groups": ("player_strength", "st"),
            "coverage": 0.08549618320610687,
        },
        "top3": {
            "model": "logistic",
            "groups": ("player_strength", "exhibition"),
            "coverage": 0.08549618320610687,
        },
    },
    5: {
        "top2": {
            "model": "logistic",
            "groups": ("player_strength", "exhibition"),
            "coverage": 0.27022900763358776,
        },
        "top3": {
            "model": "logistic",
            "groups": ("player_strength", "exhibition"),
            "coverage": 0.27022900763358776,
        },
    },
}
BWK_SPECS = {
    2: {
        "first": {"model": "hist_gradient", "groups": ("player_strength", "motor_boat", "st", "technique", "exhibition"), "coverage": 0.10},
        "top2": {"model": "logistic", "groups": ("player_strength", "motor_boat"), "coverage": 0.10},
        "top3": {"model": "logistic", "groups": ("player_strength", "exhibition"), "coverage": 0.15},
    },
    3: {
        "first": {"model": "logistic", "groups": ("player_strength",), "coverage": 0.10},
        "top2": {"model": "hist_gradient", "groups": ("player_strength", "motor_boat", "st", "technique"), "coverage": 0.10},
        "top3": {"model": "logistic", "groups": ("player_strength", "motor_boat", "st", "technique"), "coverage": 0.10},
    },
    4: {
        "first": {"model": "logistic", "groups": ("player_strength", "technique"), "coverage": 0.05197132616487455},
        "top3": {"model": "logistic", "groups": ("player_strength", "motor_boat"), "coverage": 0.05197132616487455},
    },
    5: {
        "top3": {"model": "logistic", "groups": ("player_strength",), "coverage": 0.10},
    },
    6: {
        "top2": {"model": "logistic", "groups": ("player_strength",), "coverage": 0.10},
        "top3": {"model": "logistic", "groups": ("player_strength", "motor_boat", "st", "technique"), "coverage": 0.10},
    },
}
HWJ_SPECS = {
    1: {
        "top2": {"model": "logistic", "groups": ("player_strength", "technique", "exhibition")},
    },
    2: {
        "top2": {"model": "hist_gradient", "groups": ("player_strength", "motor_boat", "exhibition")},
        "top3": {"model": "logistic", "groups": ("player_strength", "motor_boat")},
    },
    3: {
        "first": {"model": "hist_gradient", "groups": ("st", "technique", "exhibition")},
        "top3": {"model": "hist_gradient", "groups": ("player_strength", "technique", "exhibition")},
    },
    4: {
        "top2": {"model": "logistic", "groups": ("player_strength",)},
        "top3": {"model": "logistic", "groups": ("player_strength", "motor_boat")},
    },
    5: {
        "first": {"model": "hist_gradient", "groups": ("st", "technique", "exhibition"), "coverage": 0.15},
        "top2": {"model": "logistic", "groups": ("player_strength",), "coverage": 0.15},
        "top3": {"model": "logistic", "groups": ("player_strength", "technique", "exhibition"), "coverage": 0.15},
    },
    6: {
        "top2": {"model": "hist_gradient", "groups": ("motor_boat", "st", "technique"), "coverage": 0.15},
        "top3": {"model": "logistic", "groups": ("player_strength", "technique"), "coverage": 0.15},
    },
}
SPECS_BY_PLACE = {
    "AMG": AMG_SPECS, "ASY": ASY_SPECS, "BWK": BWK_SPECS, "HWJ": HWJ_SPECS, "KRY": KRY_SPECS, "TMG": TMG_SPECS,
    "TDA": TDA_SPECS, "OMR": OMR_SPECS, "SMS": SMS_SPECS, "SME": SME_SPECS,
}
VERSION_BY_PLACE = {
    "AMG": "amagasaki_course_signal_v1",
    "ASY": "ashiya_course_signal_v1",
    "BWK": "biwako_course_signal_v1",
    "HWJ": "heiwajima_course_signal_v1",
    "KRY": "kiryuu_course_signal_v1",
    "TMG": "tamagawa_course_signal_v1",
    "TDA": "toda_course_signal_v1",
    "OMR": "omura_course_signal_v1",
    "SMS": "shimonoseki_course_signal_v1",
    "SME": "suminoe_course_signal_v1",
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
    global audit, START, VALID_START, TEST_START, END
    import argparse

    parser = argparse.ArgumentParser()
    parser.add_argument("--place", default="TMG", choices=sorted(SPECS_BY_PLACE))
    args = parser.parse_args()
    place = str(args.place)
    if place in PERIODS_BY_PLACE:
        START, VALID_START, TEST_START, END = PERIODS_BY_PLACE[place]
    audit = __import__("audit_tamagawa_course_signals_zero_base_ml")
    audit.PLACE = place
    specs = SPECS_BY_PLACE[place]
    model_path = MODEL_PATHS[place]
    config = json.loads((ROOT / "config" / "course_signal_rules.json").read_text(encoding="utf-8"))
    records, pre_features, post_features = audit.build_dataset(START, END, config)
    if place in {"ASY", "AMG", "BWK"}:
        training_records = [row for row in records if row["date"] < VALID_START]

        def has_training_value(name: str) -> bool:
            return any(
                np.isfinite(float(row["features"].get(name, float("nan"))))
                for row in training_records
            )

        # 候補検証と同じく、TRAINで全欠損の特徴量は学習前に除外する。
        pre_features = [name for name in pre_features if has_training_value(name)]
        post_features = [name for name in post_features if has_training_value(name)]
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
        "runtime": {
            "python": sys.version.split()[0],
            "scikit_learn": sklearn.__version__,
            "numpy": np.__version__,
        },
        "production_enabled_from": date.today().isoformat() if place == "HWJ" else (
            "2026-09-28" if place in {"ASY", "AMG", "BWK"} else None
        ),
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
                "valid_coverage": coverage,
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
        "runtime": artifact["runtime"],
        "scikit_learn_version": artifact["runtime"]["scikit_learn"],
        "models": {
            course: {
                target: {
                    key: value
                    for key, value in model.items()
                    if key != "model"
                }
                for target, model in targets.items()
            }
            for course, targets in artifact["models"].items()
        },
    }
    if artifact.get("production_enabled_from"):
        manifest["production_enabled_from"] = artifact["production_enabled_from"]
    model_path.with_suffix(".json").write_text(
        json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    print(f"MODEL: {model_path}")
    print(f"SHA256: {digest}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
