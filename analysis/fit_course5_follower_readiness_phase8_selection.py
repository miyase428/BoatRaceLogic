#!/usr/bin/env python3
"""Phase 8-1b: Development-only Follower Readiness Selection models.

This script reads only Phase 8-1a/1aR artifacts.  It never reads or scores
EXPOSED_CONFIRMATION, does not use Attack Type probabilities, and has no
interaction, threshold, or subgroup logic.
"""
from __future__ import annotations

import csv
import hashlib
import json
import math
import subprocess
import time
from datetime import datetime
from pathlib import Path

import numpy as np
from sklearn.metrics import average_precision_score, brier_score_loss, log_loss, roc_auc_score

from analyze_course4_attack_success_phase5 import MetaLogit, predict_artifact, spearman

ROOT = Path(__file__).resolve().parent
REPO = ROOT.parent
OUT = ROOT / "output"
SPEC = OUT / "course5_follower_readiness_phase8_spec_v1.json"
SPEC_SHA = "ed41fefb390f5d098bf6a6750c7140df9ec93b1c8309141d2ca662910e078f10"
SPEC_AUDIT = OUT / "course5_follower_readiness_phase8_spec_v1_audit.json"
SPEC_AUDIT_SHA = "5b75cadc85a531b5396ada59ad80d2427e8573a6ccb0b1039d3b3da46356fa2e"
FIVE = OUT / "course5_follower_readiness_phase8_course5_fact_20241001_20260930.csv"
SIX = OUT / "course5_follower_readiness_phase8_course6_fact_20241001_20260930.csv"
ORDINARY = OUT / "course5_follower_readiness_phase8_ordinary_profiles_20241001_20260930.csv"
FOLLOWER = OUT / "course5_follower_readiness_phase8_follower_profiles_20241001_20260930.csv"
MODEL_OUT = OUT / "course5_follower_readiness_phase8_selection_model.json"
PRED_OUT = OUT / "course5_follower_readiness_phase8_selection_predictions.csv"
REPORT_OUT = OUT / "course5_follower_readiness_phase8_selection_report.json"

BASE = (
    "12m_course_count", "12m_course_second_rate", "12m_course_third_rate",
    "12m_course_top3_rate", "12m_course_average_st",
)
PLUS = (
    "36m_follower_attack_count", "36m_smoothed_second_rate",
    "36m_smoothed_third_rate", "36m_smoothed_top3_rate",
)
ALL = BASE + PLUS
EXPECTED = {"Development": 2492, "Validation": 1231, "EXPOSED_CONFIRMATION": 1258}


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def git() -> str:
    return subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=REPO, text=True).strip()


def number(v):
    try:
        x = float(v)
        return x if math.isfinite(x) else np.nan
    except (TypeError, ValueError):
        return np.nan


def metric(y, p):
    return {
        "prevalence": float(np.mean(y)),
        "auc": float(roc_auc_score(y, p)),
        "pr_auc": float(average_precision_score(y, p)),
        "brier": float(brier_score_loss(y, p)),
        "logloss": float(log_loss(y, p, labels=[0, 1])),
        **calibration(y, p),
    }


def calibration(y, p):
    """Deterministic unregularized logistic calibration on logit(p)."""
    q = np.clip(np.asarray(p, float), 1e-15, 1 - 1e-15)
    z = np.c_[np.ones(len(q)), np.log(q / (1 - q))]
    beta = np.zeros(2, dtype=float)
    for _ in range(100):
        ph = 1 / (1 + np.exp(-np.clip(z @ beta, -35, 35)))
        w = np.maximum(ph * (1 - ph), 1e-10)
        h = (z.T * w) @ z
        g = z.T @ (y - ph)
        step = np.linalg.solve(h, g)
        beta += step
        if float(np.max(np.abs(step))) < 1e-10:
            break
    return {"calibration_intercept": float(beta[0]), "calibration_slope": float(beta[1])}


def artifact(model, features, y_dev, input_audit):
    return {
        "feature_order": list(features),
        "missing_fill_values": model.median_.tolist(),
        "scaler_mean": model.mean_.tolist(),
        "scaler_std": model.scale_.tolist(),
        "scaler_ddof": 0,
        "intercept": float(model.beta_[0]),
        "coefficients": model.beta_[1:].tolist(),
        "solver": "Phase 5 MetaLogit deterministic Newton-Raphson full Hessian",
        "regularization": {"type": "ridge Hessian damping", "lambda": model.ridge, "intercept_regularized": False},
        "tolerance": model.tolerance, "max_iterations": model.max_iter,
        "iterations_used": model.iterations_, "logit_clipping": [-model.clip, model.clip],
        "development_label_prevalence": float(np.mean(y_dev)),
        "preprocessing_input_audit": input_audit,
    }


def matrix(rows, features):
    return np.asarray([[number(r[f]) for f in features] for r in rows], dtype=float)


def to_feature_row(course_row, ordinary, follower):
    key = (course_row["race_code"], str(course_row["actual_course"]))
    o, f = ordinary[key], follower[key]
    return {
        "12m_course_count": number(o["history_n"]),
        "12m_course_second_rate": number(o["history_second_rate"]),
        "12m_course_third_rate": number(o["history_third_rate"]),
        "12m_course_top3_rate": number(o["history_top3_rate"]),
        "12m_course_average_st": number(o["history_average_st"]),
        "36m_follower_attack_count": number(f["follower_attack_n"]),
        "36m_smoothed_second_rate": number(f["follower_smoothed_second_rate"]),
        "36m_smoothed_third_rate": number(f["follower_smoothed_third_rate"]),
        "36m_smoothed_top3_rate": number(f["follower_smoothed_top3_rate"]),
    }


def read_profiles():
    ordinary = {(r["race_code"], r["course"]): r for r in csv.DictReader(ORDINARY.open(encoding="utf-8"))}
    follower = {(r["race_code"], r["course"]): r for r in csv.DictReader(FOLLOWER.open(encoding="utf-8"))}
    return ordinary, follower


def read_course(path, course, ordinary, follower):
    rows = []
    for r in csv.DictReader(path.open(encoding="utf-8")):
        if r["conditional_population_four_makuri_success"] != "1":
            continue
        if r["label_available"] != "1":
            raise RuntimeError(f"conditional row without label: {course} {r['race_code']}")
        values = to_feature_row(r, ordinary, follower)
        rows.append({"race_code": r["race_code"], "split": r["split"], "label": int(r["label_top3"]), **values})
    counts = {s: sum(r["split"] == s for r in rows) for s in EXPECTED}
    if counts != EXPECTED:
        raise RuntimeError(f"course {course} conditional counts mismatch: {counts}")
    if len(rows) != len({r["race_code"] for r in rows}):
        raise RuntimeError(f"course {course} duplicate race_code")
    return rows


def preprocess_audit(x_dev, x_val, features):
    raw_std = np.nanstd(x_dev, axis=0, ddof=0)
    near_zero = [features[i] for i, x in enumerate(raw_std) if not np.isfinite(x) or x < 1e-10]
    if near_zero:
        raise RuntimeError(f"Development raw std is zero/near-zero; no feature deletion permitted: {near_zero}")
    med = np.nanmedian(x_dev, axis=0)
    filled = np.where(np.isnan(x_dev), med, x_dev)
    return {
        "development_missing": {f: int(np.isnan(x_dev[:, i]).sum()) for i, f in enumerate(features)},
        "validation_missing": {f: int(np.isnan(x_val[:, i]).sum()) for i, f in enumerate(features)},
        "development_median": {f: float(med[i]) for i, f in enumerate(features)},
        "development_mean_after_imputation": {f: float(filled[:, i].mean()) for i, f in enumerate(features)},
        "development_std_after_imputation_ddof0": {f: float(filled[:, i].std(ddof=0)) for i, f in enumerate(features)},
        "raw_std_check": {f: float(raw_std[i]) for i, f in enumerate(features)},
    }


def roundtrip(model_artifact, named):
    out = {}
    for split, (x, expected) in named.items():
        rebuilt = predict_artifact(model_artifact, x)
        diff = np.abs(expected - rebuilt)
        out[split] = {
            "n": len(expected), "max_abs_diff": float(diff.max()), "mean_abs_diff": float(diff.mean()),
            "exact_match_count": int(np.sum(expected == rebuilt)), "within_1e12_count": int(np.sum(diff <= 1e-12)),
            "spearman": spearman(expected, rebuilt),
        }
    return out


def fit_one(rows, features):
    dev = [r for r in rows if r["split"] == "Development"]
    val = [r for r in rows if r["split"] == "Validation"]
    x_dev, x_val = matrix(dev, features), matrix(val, features)
    y_dev = np.asarray([r["label"] for r in dev], dtype=int)
    y_val = np.asarray([r["label"] for r in val], dtype=int)
    pre = preprocess_audit(x_dev, x_val, features)
    model = MetaLogit().fit(x_dev, y_dev)
    p_dev, p_val = model.predict(x_dev), model.predict(x_val)
    art = artifact(model, features, y_dev, pre)
    rt = roundtrip(art, {"Development": (x_dev, p_dev), "Validation": (x_val, p_val)})
    if max(x["max_abs_diff"] for x in rt.values()) > 1e-12:
        raise RuntimeError("artifact round-trip exceeded 1e-12")
    baseline_p = float(np.mean(y_dev))
    baseline = {
        "development_probability": baseline_p,
        "validation_brier": float(brier_score_loss(y_val, np.full(len(y_val), baseline_p))),
        "validation_logloss": float(log_loss(y_val, np.full(len(y_val), baseline_p), labels=[0, 1])),
    }
    return {
        "artifact": art, "development_metrics": metric(y_dev, p_dev), "validation_metrics": metric(y_val, p_val),
        "intercept_only_validation": baseline, "roundtrip": rt,
        "development_predictions": p_dev, "validation_predictions": p_val,
    }


def delta(plus, base):
    keys = ("auc", "pr_auc", "brier", "logloss")
    return {f"delta_{k}": plus["validation_metrics"][k] - base["validation_metrics"][k] for k in keys}


def gate(plus, base):
    m, b, d = plus["validation_metrics"], plus["intercept_only_validation"], delta(plus, base)
    tests = {
        "validation_auc_gt_060": m["auc"] > 0.60,
        "validation_pr_auc_gt_validation_prevalence": m["pr_auc"] > m["prevalence"],
        "brier_lt_intercept_only": m["brier"] < b["validation_brier"],
        "logloss_lt_intercept_only": m["logloss"] < b["validation_logloss"],
        "calibration_slope_in_080_120": 0.8 <= m["calibration_slope"] <= 1.2,
        "prediction_generation_100pct": len(plus["validation_predictions"]) == EXPECTED["Validation"],
        "artifact_roundtrip_lte_1e12": max(x["max_abs_diff"] for x in plus["roundtrip"].values()) <= 1e-12,
        "follower_plus_auc_improves_base": d["delta_auc"] > 0,
        "follower_plus_pr_auc_improves_base": d["delta_pr_auc"] > 0,
        "follower_plus_brier_improves_base": d["delta_brier"] < 0,
        "follower_plus_logloss_improves_base": d["delta_logloss"] < 0,
    }
    return tests, ("PASS" if all(tests.values()) else "FAIL"), d


def main():
    started = time.perf_counter()
    if sha(SPEC) != SPEC_SHA:
        raise RuntimeError("spec SHA256 mismatch")
    if sha(SPEC_AUDIT) != SPEC_AUDIT_SHA:
        raise RuntimeError("spec audit SHA256 mismatch")
    spec = json.loads(SPEC.read_text(encoding="utf-8"))
    if spec["final_safe_feature_order"] != list(ALL) or spec["phase_8_1ar_result"] != "PASS":
        raise RuntimeError("safe feature order or spec status mismatch")
    if spec["conditional_sample_counts"] != EXPECTED:
        raise RuntimeError("fixed conditional sample mismatch")

    ordinary, follower = read_profiles()
    five = read_course(FIVE, 5, ordinary, follower)
    six = read_course(SIX, 6, ordinary, follower)
    for split in ("Development", "Validation"):
        a = {r["race_code"] for r in five if r["split"] == split}
        b = {r["race_code"] for r in six if r["split"] == split}
        if a != b:
            raise RuntimeError(f"5C/6C conditional race sets differ in {split}")

    results = {}
    for name, rows in (("5C", five), ("6C", six)):
        base, plus = fit_one(rows, BASE), fit_one(rows, ALL)
        tests, status, increments = gate(plus, base)
        coeff = {feature: {"standardized_coefficient": float(plus["artifact"]["coefficients"][len(BASE) + i]),
                            "sign": "positive" if plus["artifact"]["coefficients"][len(BASE) + i] > 0 else ("negative" if plus["artifact"]["coefficients"][len(BASE) + i] < 0 else "zero")}
                 for i, feature in enumerate(PLUS)}
        results[name] = {"ordinary_base": base, "follower_plus": plus, "incremental_validation": increments,
                         "follower_profile_standardized_coefficients": coeff, "selection_gate_tests": tests,
                         "selection_gate": status}

    # Persist only the stated columns, only Development and Validation.
    prediction_rows = []
    by_key = {}
    for name, rows in (("5C", five), ("6C", six)):
        key = "5c" if name == "5C" else "6c"
        for split, predictions_base, predictions_plus in (
            ("Development", results[name]["ordinary_base"]["development_predictions"], results[name]["follower_plus"]["development_predictions"]),
            ("Validation", results[name]["ordinary_base"]["validation_predictions"], results[name]["follower_plus"]["validation_predictions"]),
        ):
            rr = [r for r in rows if r["split"] == split]
            for row, pb, pp in zip(rr, predictions_base, predictions_plus):
                dest = by_key.setdefault((row["race_code"], split), {"race_code": row["race_code"], "split": split})
                dest[f"label_{key}"] = row["label"]
                dest[f"p_{key}_ordinary"] = repr(float(pb))
                dest[f"p_{key}_follower"] = repr(float(pp))
    prediction_rows = [by_key[k] for k in sorted(by_key)]
    if len(prediction_rows) != EXPECTED["Development"] + EXPECTED["Validation"]:
        raise RuntimeError("prediction row count mismatch")
    fields = ["race_code", "split", "label_5c", "p_5c_ordinary", "p_5c_follower", "label_6c", "p_6c_ordinary", "p_6c_follower"]
    with PRED_OUT.open("w", newline="", encoding="utf-8") as h:
        w = csv.DictWriter(h, fieldnames=fields); w.writeheader(); w.writerows(prediction_rows)

    # Drop ephemeral prediction arrays before writing the immutable artifact.
    persisted_models = {}
    for course, values in results.items():
        persisted_models[course] = {}
        for model_name in ("ordinary_base", "follower_plus"):
            m = values[model_name]
            persisted_models[course][model_name] = {k: v for k, v in m.items() if not k.endswith("_predictions")}
        for key in ("incremental_validation", "follower_profile_standardized_coefficients", "selection_gate_tests", "selection_gate"):
            persisted_models[course][key] = values[key]
    artifact_doc = {
        "artifact_version": "phase8-selection-v1", "phase": "Phase 8-1b", "model_role": "SELECTION",
        "spec": {"path": str(SPEC.relative_to(REPO)), "sha256": sha(SPEC)},
        "spec_audit": {"path": str(SPEC_AUDIT.relative_to(REPO)), "sha256": sha(SPEC_AUDIT)},
        "conditional_definition": spec["conditional_sample_definition"], "label_definition": spec["label_definition"],
        "feature_orders": {"ordinary_base": list(BASE), "follower_plus": list(ALL)},
        "fit_split": {"name": "Development", "N": EXPECTED["Development"]},
        "evaluation_split": {"name": "Validation", "N": EXPECTED["Validation"]},
        "excluded_split": {"name": "EXPOSED_CONFIRMATION", "N": EXPECTED["EXPOSED_CONFIRMATION"], "predictions_generated": False, "metrics_computed": False},
        "solver": {"optimizer": "Newton-Raphson full Hessian", "ridge": 1e-6, "max_iter": 25, "tolerance": 1e-8, "clip": [-35, 35], "class_weight": None, "intercept_regularized": False},
        "models": persisted_models,
        "input_artifacts": {str(p.relative_to(REPO)): sha(p) for p in (FIVE, SIX, ORDINARY, FOLLOWER)},
        "prediction_path": str(PRED_OUT.relative_to(REPO)), "prediction_sha256": sha(PRED_OUT),
        "generated_at": datetime.now().astimezone().isoformat(), "source_script": str(Path(__file__).relative_to(REPO)),
        "source_script_sha256": sha(Path(__file__)), "source_git_commit": git(),
        "confirmation_access": "not read for prediction, metrics, fitting, or model selection",
        "timing_seconds": {"total": time.perf_counter() - started},
    }
    MODEL_OUT.write_text(json.dumps(artifact_doc, ensure_ascii=False, indent=2), encoding="utf-8")

    # Reload artifact and reconstruct Development/Validation probabilities in their original row order.
    loaded = json.loads(MODEL_OUT.read_text(encoding="utf-8"))
    reload_checks = {}
    for name, rows in (("5C", five), ("6C", six)):
        reload_checks[name] = {}
        for model_name, features in (("ordinary_base", BASE), ("follower_plus", ALL)):
            a = loaded["models"][name][model_name]["artifact"]
            per_split = {}
            for split, expected_key in (("Development", "development_predictions"), ("Validation", "validation_predictions")):
                x = matrix([r for r in rows if r["split"] == split], features)
                rebuilt = predict_artifact(a, x)
                original = results[name][model_name][expected_key]
                d = np.abs(rebuilt - original)
                per_split[split] = {"max_abs_diff": float(d.max()), "mean_abs_diff": float(d.mean()),
                                    "exact_match_count": int(np.sum(rebuilt == original)), "spearman": spearman(rebuilt, original)}
            reload_checks[name][model_name] = per_split
    if max(v[split]["max_abs_diff"] for c in reload_checks.values() for v in c.values() for split in v) > 1e-12:
        raise RuntimeError("saved artifact reload round-trip failed")
    report = {
        "phase": "Phase 8-1b", "spec_path": str(SPEC.relative_to(REPO)), "spec_sha256": sha(SPEC),
        "spec_audit_path": str(SPEC_AUDIT.relative_to(REPO)), "spec_audit_sha256": sha(SPEC_AUDIT),
        "exact_label_definition": spec["label_definition"], "exact_conditional_definition": spec["conditional_sample_definition"],
        "conditional_counts": EXPECTED, "feature_order": list(ALL), "models": persisted_models,
        "selection_gate": {"5C_READINESS_SELECTION_GATE": results["5C"]["selection_gate"],
                           "6C_READINESS_SELECTION_DIAGNOSTIC": results["6C"]["selection_gate"]},
        "artifact_reload_audit": reload_checks, "prediction_path": str(PRED_OUT.relative_to(REPO)),
        "prediction_sha256": sha(PRED_OUT), "confirmation_evaluated": False,
        "generated_at": artifact_doc["generated_at"], "source_script": artifact_doc["source_script"],
        "source_script_sha256": artifact_doc["source_script_sha256"], "source_git_commit": artifact_doc["source_git_commit"],
        "timing_seconds": {"total": time.perf_counter() - started},
    }
    REPORT_OUT.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps({"model": str(MODEL_OUT), "predictions": str(PRED_OUT), "report": str(REPORT_OUT),
                      "gates": report["selection_gate"], "confirmation_evaluated": False,
                      "timing_seconds": report["timing_seconds"]}, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
