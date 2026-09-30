#!/usr/bin/env python3
"""Create frozen AutoGluon/EBM artifacts for AI win-rate forward validation."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import pickle
import platform
import shutil
import subprocess
import sys
import time
from datetime import date, datetime
from pathlib import Path

import joblib
import numpy as np

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "analysis"))

import audit_tamagawa_course_signals_zero_base_ml as audit
from compare_ai_winrate_systems import load_or_build_course_dataset
from compare_course_signal_models import AUTOGLUON_PARAMETERS, EBM_PARAMETERS

PLACES = ("ASY", "AMG")
COURSES = range(1, 7)
CUTOFF = date(2026, 9, 27)
TRAIN_END = date(2025, 8, 31)
VALID_END = date(2026, 2, 28)
ARTIFACT = ROOT / "analysis" / "artifacts" / "ai_winrate_forward" / "20260927"


def sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def tree_sha256(path: Path) -> str:
    h = hashlib.sha256()
    for item in sorted(p for p in path.rglob("*") if p.is_file()):
        h.update(item.relative_to(path).as_posix().encode())
        h.update(bytes.fromhex(sha256(item)))
    return h.hexdigest()


def git_sha() -> str:
    return subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT, text=True).strip()


def selected(payload: dict, place: str, course: int, start: date | None, end: date) -> list[dict]:
    return [r for r in payload["records"][place] if int(r["course"]) == course and (start is None or r["date"] >= start) and r["date"] <= end]


def matrix(rows: list[dict], features: list[str]) -> np.ndarray:
    return audit.matrix(rows, features)


def train_ebm(payload: dict) -> None:
    from interpret.glassbox import ExplainableBoostingClassifier
    features = payload["features"]
    models, rows = {}, {}
    for place in PLACES:
        models[place] = {}
        rows[place] = {}
        for course in COURSES:
            source = selected(payload, place, course, None, CUTOFF)
            model = ExplainableBoostingClassifier(feature_names=features, **EBM_PARAMETERS)
            model.fit(matrix(source, features), np.asarray([r["first"] for r in source], dtype=np.int8))
            models[place][str(course)] = model
            rows[place][str(course)] = len(source)
            print(f"EBM {place} {course}C: {len(source)} rows", flush=True)
    ARTIFACT.mkdir(parents=True, exist_ok=True)
    path = ARTIFACT / "ebm_models.joblib"
    joblib.dump({"version": "ai_winrate_forward_ebm_20260927", "features": features, "models": models}, path, compress=3)
    import interpret, sklearn
    (ARTIFACT / "ebm_stage.json").write_text(json.dumps({
        "path": str(path.relative_to(ROOT)), "sha256": sha256(path), "rows": rows,
        "python": platform.python_version(), "numpy": np.__version__, "sklearn": sklearn.__version__, "interpret": interpret.__version__,
    }, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def train_autogluon(payload: dict) -> None:
    import pandas as pd
    from autogluon.tabular import TabularPredictor
    from importlib.metadata import version
    features = payload["features"]
    root = ARTIFACT / "autogluon"
    root.mkdir(parents=True, exist_ok=True)
    rows, best_models = {}, {}
    for place in PLACES:
        rows[place], best_models[place] = {}, {}
        for course in COURSES:
            train = selected(payload, place, course, None, TRAIN_END)
            valid = selected(payload, place, course, date(2025, 9, 1), VALID_END)
            extra = selected(payload, place, course, date(2026, 3, 1), CUTOFF)
            model_path = root / f"{place}_{course}C"
            shutil.rmtree(model_path, ignore_errors=True)
            def frame(source: list[dict], labelled: bool = True):
                value = pd.DataFrame(matrix(source, features), columns=features)
                if labelled:
                    value["_target"] = np.asarray([r["first"] for r in source], dtype=np.int8)
                return value
            predictor = TabularPredictor(label="_target", problem_type="binary", eval_metric=AUTOGLUON_PARAMETERS["eval_metric"], path=str(model_path), verbosity=0)
            predictor.fit(
                train_data=frame(train), tuning_data=frame(valid),
                time_limit=AUTOGLUON_PARAMETERS["time_limit_seconds"], presets=AUTOGLUON_PARAMETERS["preset"],
                hyperparameters=AUTOGLUON_PARAMETERS["hyperparameters"], excluded_model_types=AUTOGLUON_PARAMETERS["excluded_models"],
                fit_weighted_ensemble=True, dynamic_stacking=False, calibrate_decision_threshold=False,
                num_cpus=AUTOGLUON_PARAMETERS["num_cpus"], num_gpus=AUTOGLUON_PARAMETERS["num_gpus"],
                fit_strategy=AUTOGLUON_PARAMETERS["fit_strategy"], memory_limit=6.0,
            )
            original_best = str(predictor.model_best)
            mapping = predictor.refit_full(model="best", set_best_to_refit_full=True, train_data_extra=frame(extra), num_cpus=4, num_gpus=0, fit_strategy="sequential")
            rows[place][str(course)] = {"train": len(train), "valid_selection": len(valid), "refit_extra": len(extra), "total_through_cutoff": len(train) + len(valid) + len(extra)}
            best_models[place][str(course)] = {"selection_model": original_best, "refit_model": str(predictor.model_best), "refit_mapping": mapping}
            print(f"AutoGluon {place} {course}C: {predictor.model_best}", flush=True)
    import sklearn
    (ARTIFACT / "autogluon_stage.json").write_text(json.dumps({
        "path": str(root.relative_to(ROOT)), "sha256": tree_sha256(root), "rows": rows, "best_models": best_models,
        "python": platform.python_version(), "numpy": np.__version__, "sklearn": sklearn.__version__, "autogluon": version("autogluon.tabular"),
    }, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def finalize(payload: dict) -> None:
    ag = json.loads((ARTIFACT / "autogluon_stage.json").read_text())
    ebm = json.loads((ARTIFACT / "ebm_stage.json").read_text())
    manifest = {
        "version": "ai_winrate_blend_forward_20260927", "created_at": datetime.now().astimezone().isoformat(timespec="seconds"),
        "git_sha": git_sha(), "forward_start": "2026-10-01", "training_cutoff": "2026-09-27",
        "places": list(PLACES), "courses": list(COURSES), "feature_count": len(payload["features"]), "feature_names": payload["features"],
        "feature_definition": "fixed post-exhibition 170 features from audit_tamagawa_course_signals_zero_base_ml.py",
        "label_definition": "exhibition-course subject player is positive iff that same player won",
        "weights": {"baseline": {"v6_final": 1.0}, "autogluon25": {"v6_final": 0.75, "autogluon_course_first": 0.25}, "ebm05": {"v6_final": 0.95, "ebm_course_first": 0.05}},
        "autogluon_parameters": AUTOGLUON_PARAMETERS, "ebm_parameters": EBM_PARAMETERS,
        "training_policy": "AutoGluon model selection uses the historical fixed TRAIN/VALID split, then refit_full adds the fixed historical TEST rows through cutoff. EBM fits all rows through cutoff with its fixed internal validation setting.",
        "artifacts": {"autogluon": ag, "ebm": ebm},
    }
    manifest_path = ARTIFACT / "manifest.json"
    manifest_path.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    config = {
        "manifest": str(manifest_path.relative_to(ROOT)), "manifest_sha256": sha256(manifest_path),
        "version": manifest["version"], "forward_start": manifest["forward_start"], "training_cutoff": manifest["training_cutoff"],
        "git_sha_at_training": manifest["git_sha"], "feature_count": manifest["feature_count"], "feature_names": manifest["feature_names"],
        "feature_definition": manifest["feature_definition"], "label_definition": manifest["label_definition"],
        "weights": manifest["weights"], "autogluon_parameters": manifest["autogluon_parameters"], "ebm_parameters": manifest["ebm_parameters"],
        "training_policy": manifest["training_policy"], "artifacts": manifest["artifacts"],
    }
    (ROOT / "analysis" / "ai_winrate_forward_config.json").write_text(json.dumps(config, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(config, ensure_ascii=False), flush=True)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("stage", choices=("autogluon", "ebm", "finalize"))
    args = parser.parse_args()
    payload = load_or_build_course_dataset()
    if payload.get("feature_count") != 170 or payload.get("label_definition") != "exhibition_subject_player_finish":
        raise RuntimeError("固定170特徴またはラベル定義が一致しません")
    if args.stage == "autogluon": train_autogluon(payload)
    elif args.stage == "ebm": train_ebm(payload)
    else: finalize(payload)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
