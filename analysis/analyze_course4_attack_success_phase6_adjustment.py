#!/usr/bin/env python3
"""Phase 6C-2: pre-specified 5C/6C base-versus-plus adjustment models."""
from __future__ import annotations

import csv
import datetime
import hashlib
import json
import math
import subprocess
from pathlib import Path

import numpy as np

from analyze_course4_attack_success_phase5 import MetaLogit, metrics, predict_artifact


ROOT = Path(__file__).resolve().parent
OUT = ROOT / "output"
RACE_CACHE = OUT / "course4_attack_success_phase6_race_cache_20251001_20260930.csv"
RACE_SHA256 = "9275720d6eb56f29bf216cf0daca71cc10a6db3c052e875df8d473f349a8a778"
METRICS_OUT = OUT / "course4_attack_success_phase6_adjustment_metrics.csv"
REPORT_OUT = OUT / "course4_attack_success_phase6_adjustment_report.json"
FIT_SPLIT, EVAL_SPLIT = "Validation", "EXPOSED_CONFIRMATION"
BASE = ("national_win_rate", "local_win_rate", "average_st", "motor_2ren_rate", "boat_2ren_rate")
OUTCOMES = ("second", "third", "top3")


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def read_csv(path: Path) -> list[dict[str, str]]:
    with path.open(encoding="utf-8", newline="") as handle:
        return list(csv.DictReader(handle))


def number(value: str) -> float:
    try:
        value = float(value)
        return value if math.isfinite(value) else np.nan
    except (TypeError, ValueError):
        return np.nan


def matrix(rows: list[dict[str, str]], features: tuple[str, ...]) -> np.ndarray:
    return np.asarray([[number(row[feature]) for feature in features] for row in rows], dtype=float)


def target(rows: list[dict[str, str]], course: int, outcome: str) -> np.ndarray:
    return np.asarray([int(row[f"course{course}_{outcome}"]) for row in rows], dtype=int)


def artifact(model: MetaLogit, features: tuple[str, ...]) -> dict[str, object]:
    return {
        "feature_order": list(features),
        "missing_fill_values": model.median_.tolist(),
        "scaler_mean": model.mean_.tolist(),
        "scaler_std": model.scale_.tolist(),
        "scaler_ddof": 0,
        "intercept": float(model.beta_[0]),
        "coefficients": model.beta_[1:].tolist(),
        "solver": "Phase 5 MetaLogit: deterministic Newton-Raphson full Hessian",
        "regularization": {"type": "ridge Hessian damping", "lambda": model.ridge, "intercept_regularized": False},
        "tolerance": model.tolerance,
        "max_iterations": model.max_iter,
        "iterations_used": model.iterations_,
        "logit_clipping": [-model.clip, model.clip],
    }


def direction(delta: dict[str, float]) -> dict[str, bool]:
    return {"auc": delta["roc_auc"] > 0, "pr_auc": delta["pr_auc"] > 0, "brier": delta["brier"] < 0, "log_loss": delta["log_loss"] < 0}


def internal_label(five: dict[str, object], six: dict[str, object]) -> str:
    """Pre-declared descriptive label; it is not a Phase 6 final classification."""
    five_delta, six_delta = five["confirmation_delta"], six["confirmation_delta"]
    five_good, six_good = direction(five_delta), direction(six_delta)
    five_coef, six_coef = five["attack_standardized_coefficient"], six["attack_standardized_coefficient"]
    if five_coef > 0 and all(five_good.values()) and five_delta["roc_auc"] > six_delta["roc_auc"]:
        return "A"
    if five_coef <= 0 or not any(five_good.values()):
        return "D"
    if five_coef * six_coef > 0 and five_good == six_good:
        return "B"
    return "C"


def model_result(fit_rows: list[dict[str, str]], eval_rows: list[dict[str, str]], course: int, outcome: str, plus: bool) -> dict[str, object]:
    features = tuple(f"course{course}_{feature}" for feature in BASE) + (("attack_success_probability",) if plus else ())
    x_fit, x_eval = matrix(fit_rows, features), matrix(eval_rows, features)
    y_fit, y_eval = target(fit_rows, course, outcome), target(eval_rows, course, outcome)
    model = MetaLogit().fit(x_fit, y_fit)
    prediction_fit, prediction_eval = model.predict(x_fit), model.predict(x_eval)
    saved = artifact(model, features)
    rebuilt_eval = predict_artifact(saved, x_eval)
    diff = np.abs(prediction_eval - rebuilt_eval)
    return {
        "features": list(features),
        "model": saved,
        "validation_metrics": metrics(y_fit, prediction_fit),
        "confirmation_metrics": metrics(y_eval, prediction_eval),
        "validation_missing_raw": {feature: int(np.isnan(x_fit[:, index]).sum()) for index, feature in enumerate(features)},
        "confirmation_imputed_with_validation_median": {feature: int(np.isnan(x_eval[:, index]).sum()) for index, feature in enumerate(features)},
        "roundtrip_confirmation": {"max_abs_diff": float(diff.max()), "mean_abs_diff": float(diff.mean()), "exact_match_count": int(np.sum(prediction_eval == rebuilt_eval)), "within_1e12_count": int(np.sum(diff <= 1e-12))},
        "attack_standardized_coefficient": float(model.beta_[-1]) if plus else None,
        "attack_coefficient_sign": ("positive" if model.beta_[-1] > 0 else ("negative" if model.beta_[-1] < 0 else "zero")) if plus else None,
    }


def main() -> None:
    if sha256(RACE_CACHE) != RACE_SHA256:
        raise RuntimeError("race cache SHA256 mismatch")
    rows = read_csv(RACE_CACHE)
    keys = [row["race_code"] for row in rows]
    if len(rows) != 42717 or len(keys) != len(set(keys)) or any(not key for key in keys):
        raise RuntimeError("race cache row/race_code audit mismatch")
    fit_rows = [row for row in rows if row["split"] == FIT_SPLIT]
    eval_rows = [row for row in rows if row["split"] == EVAL_SPLIT]
    if len(fit_rows) != 20595 or len(eval_rows) != 22122:
        raise RuntimeError("split count mismatch")
    results: dict[str, object] = {}
    csv_rows: list[dict[str, object]] = []
    for outcome in OUTCOMES:
        outcome_results: dict[str, object] = {}
        for course in (5, 6):
            base = model_result(fit_rows, eval_rows, course, outcome, plus=False)
            plus = model_result(fit_rows, eval_rows, course, outcome, plus=True)
            validation_delta = {key: plus["validation_metrics"][key] - base["validation_metrics"][key] for key in base["validation_metrics"]}
            confirmation_delta = {key: plus["confirmation_metrics"][key] - base["confirmation_metrics"][key] for key in base["confirmation_metrics"]}
            outcome_results[f"course{course}"] = {"base": base, "plus": plus, "validation_delta": validation_delta, "confirmation_delta": confirmation_delta, "attack_standardized_coefficient": plus["attack_standardized_coefficient"], "attack_coefficient_sign": plus["attack_coefficient_sign"]}
            for split_name, metric_key in (("Validation", "validation_metrics"), ("EXPOSED_CONFIRMATION", "confirmation_metrics")):
                for model_name, model_data in (("base", base), ("plus", plus)):
                    entry = {"row_type": "model", "outcome": outcome, "course": course, "split": split_name, "model": model_name, **model_data[metric_key], "attack_standardized_coefficient": model_data["attack_standardized_coefficient"], "attack_coefficient_sign": model_data["attack_coefficient_sign"]}
                    csv_rows.append(entry)
                delta = validation_delta if split_name == "Validation" else confirmation_delta
                csv_rows.append({"row_type": "plus_minus_base", "outcome": outcome, "course": course, "split": split_name, "model": "plus_minus_base", **delta, "attack_standardized_coefficient": plus["attack_standardized_coefficient"], "attack_coefficient_sign": plus["attack_coefficient_sign"]})
        outcome_results["internal_diagnostic_label"] = internal_label(outcome_results["course5"], outcome_results["course6"])
        results[outcome] = outcome_results
    fields = ("row_type", "outcome", "course", "split", "model", "roc_auc", "pr_auc", "brier", "log_loss", "attack_standardized_coefficient", "attack_coefficient_sign")
    with METRICS_OUT.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fields); writer.writeheader(); writer.writerows(csv_rows)
    report = {
        "race_cache_path": "analysis/output/course4_attack_success_phase6_race_cache_20251001_20260930.csv",
        "race_cache_sha256": RACE_SHA256,
        "fit_split": {"name": FIT_SPLIT, "N": len(fit_rows), "period": "2025-10-01..2026-03-31"},
        "evaluation_split": {"name": EVAL_SPLIT, "N": len(eval_rows), "period": "2026-04-01..2026-09-30"},
        "pre_specified_base_features": list(BASE),
        "results": results,
        "source_script": "analysis/analyze_course4_attack_success_phase6_adjustment.py",
        "source_script_sha256": sha256(Path(__file__).resolve()),
        "source_git_commit": subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT.parent, text=True).strip(),
        "generated_at": datetime.datetime.now().astimezone().isoformat(),
    }
    REPORT_OUT.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(report, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
