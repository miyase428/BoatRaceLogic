#!/usr/bin/env python3
"""Fixed no-straight ablation for SME/AMG/TKY production compatibility.

No feature selection or hyperparameter search is performed.  The frozen
Phase 3R / Phase 7 universes, labels, chronological splits and solvers are
reused; only the straight-time feature is removed from each model.
"""
from __future__ import annotations

import csv
import hashlib
import json
import math
import subprocess
import sys
from collections import Counter
from datetime import date, datetime
from pathlib import Path

import numpy as np
from sklearn.metrics import average_precision_score, brier_score_loss, log_loss, roc_auc_score

ROOT = Path(__file__).resolve().parent
REPO = ROOT.parent
OUT = ROOT / "output"
sys.path.insert(0, str(ROOT))

from analyze_course34_wall_phase3r import (  # noqa: E402
    LinearLogit,
    percentile_boundaries,
    predict_artifact as predict_wall_artifact,
)
from fit_course4_attack_type_phase7_selection import (  # noqa: E402
    EPS,
    LOGIT_CLIP,
    MAX_ITER,
    RIDGE,
    TOL,
    calibration,
    fit as fit_multiclass,
    multiclass_metrics,
    per_class,
    softmax_ref,
)

VENUES = {"SME": "住之江", "AMG": "尼崎", "TKY": "徳山"}
WALL_FEATURES = (
    "smoothed_avg_st_delta_34",
    "ex_st_delta_34",
    "avg_st_rank_delta_34",
    "st_profile_reliability_advantage_34",
    "exhibition_time_delta_34",
    "lap_time_delta_34",
    "around_time_delta_34",
)
ATTACK_FEATURES = (
    "course4_history_n",
    "course4_history_makuri_rate_pct",
    "course4_history_makurizashi_rate_pct",
    "course4_ex_st",
    "course4_exhibition_time",
    "course4_lap_time",
    "course4_around_time",
)
CLASSES = ("NO_ATTACK_SUCCESS", "MAKURI_SUCCESS", "MAKURIZASHI_SUCCESS")
DEV_END = date(2025, 9, 30)
VAL_END = date(2026, 3, 31)
CONF_END = date(2026, 9, 30)

WALL_CANONICAL = OUT / "course34_wall_canonical_input_v1.csv"
WALL_TARGETS = OUT / "course34_wall_phase3_model_input_20241001_20260930.csv"
WALL_FULL = OUT / "course34_wall_phase3r_final_model.json"
ATTACK_DATA = OUT / "course4_attack_type_phase7_dataset_20241001_20260930.csv"
ATTACK_FULL = OUT / "course4_attack_type_phase7_final_model.json"

WALL_SELECTION_OUT = OUT / "course34_wall_no_straight_v1_selection_model.json"
WALL_FINAL_OUT = OUT / "course34_wall_no_straight_v1_final_model.json"
ATTACK_SELECTION_OUT = OUT / "course4_attack_type_no_straight_v1_selection_model.json"
ATTACK_FINAL_OUT = OUT / "course4_attack_type_no_straight_v1_final_model.json"
SPEC_OUT = OUT / "course4_tenkai_no_straight_v1_spec.json"
METRICS_OUT = OUT / "course4_tenkai_no_straight_venue_metrics.csv"
REPORT_OUT = OUT / "course4_tenkai_no_straight_3venues_report.json"


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def composite_sha(paths) -> str:
    digest = hashlib.sha256()
    for path in paths:
        digest.update(path.name.encode())
        digest.update(b"\0")
        digest.update(path.read_bytes())
        digest.update(b"\0")
    return digest.hexdigest()


def git_head() -> str:
    return subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=REPO, text=True).strip()


def rows(path: Path):
    with path.open(encoding="utf-8") as handle:
        return list(csv.DictReader(handle))


def number(value):
    try:
        result = float(value)
        return result if math.isfinite(result) else np.nan
    except (TypeError, ValueError):
        return np.nan


def yes(value) -> int:
    return int(str(value).lower() in {"1", "true", "t"})


def split_name(text: str) -> str:
    current = datetime.strptime(text, "%Y-%m-%d").date()
    if current <= DEV_END:
        return "Development"
    if current <= VAL_END:
        return "Validation"
    if current <= CONF_END:
        return "EXPOSED_CONFIRMATION"
    raise ValueError(f"date outside frozen research period: {text}")


def binary_metrics(y, p):
    return {
        "n": int(len(y)),
        "prevalence": float(np.mean(y)),
        "roc_auc": float(roc_auc_score(y, p)),
        "pr_auc": float(average_precision_score(y, p)),
        "brier": float(brier_score_loss(y, p)),
        "logloss": float(log_loss(y, p, labels=[0, 1])),
        "calibration": calibration(y.astype(int), p),
    }


def wall_xy(data):
    return (
        np.asarray([[number(row[name]) for name in WALL_FEATURES] for row in data], dtype=float),
        np.asarray([yes(row["four_win_attack"]) for row in data], dtype=int),
    )


def attack_xy(data):
    return (
        np.asarray([[number(row[name]) for name in ATTACK_FEATURES] for row in data], dtype=float),
        np.asarray([int(row["label_id"]) for row in data], dtype=int),
    )


def attack_predict(artifact, x):
    med = np.asarray(artifact["development_medians"], dtype=float)
    mean = np.asarray(artifact["development_means"], dtype=float)
    std = np.asarray(artifact["development_stds"], dtype=float)
    z = (np.where(np.isnan(x), med, x) - mean) / std
    theta = np.c_[np.asarray(artifact["intercepts"], dtype=float), np.asarray(artifact["coefficients"], dtype=float)]
    return softmax_ref(np.c_[np.ones(len(z)), z], theta)


def attack_metrics(y, p):
    return {
        "n": int(len(y)),
        "class_counts": np.bincount(y, minlength=3).astype(int).tolist(),
        "MAKURI_SUCCESS": {**per_class(y, p, 1), "calibration": calibration((y == 1).astype(int), p[:, 1])},
        "MAKURIZASHI_SUCCESS": {**per_class(y, p, 2), "calibration": calibration((y == 2).astype(int), p[:, 2])},
        "multiclass": multiclass_metrics(y, p),
        "probability_max_abs_sum_minus_one": float(np.max(np.abs(np.sum(p, axis=1) - 1.0))),
    }


def roundtrip(original, rebuilt):
    delta = np.abs(np.asarray(original) - np.asarray(rebuilt))
    return {
        "n": int(delta.size),
        "max_abs_diff": float(delta.max()),
        "mean_abs_diff": float(delta.mean()),
        "exact_match_count": int(np.sum(delta == 0)),
        "within_1e12_count": int(np.sum(delta <= 1e-12)),
    }


def venue_binary_metrics(data, probabilities):
    result = {}
    for code in VENUES:
        index = [i for i, row in enumerate(data) if row["venue_code"] == code]
        y = np.asarray([yes(data[i]["four_win_attack"]) for i in index], dtype=int)
        result[code] = binary_metrics(y, np.asarray(probabilities)[index]) if len(np.unique(y)) == 2 else {"n": len(index), "not_evaluable": True}
    return result


def venue_attack_metrics(data, probabilities):
    result = {}
    for code in VENUES:
        index = [i for i, row in enumerate(data) if row["venue_code"] == code]
        y = np.asarray([int(data[i]["label_id"]) for i in index], dtype=int)
        result[code] = attack_metrics(y, np.asarray(probabilities)[index]) if len(np.unique(y)) == 3 else {"n": len(index), "not_evaluable": True}
    return result


def wall_artifact(role, model, source_sha, development_probability, boundaries=None):
    result = {
        "artifact_version": "course34-wall-no-straight-v1",
        "model_variant": "NO_STRAIGHT",
        "model_role": role,
        "model_name": "Wall-R fixed no-straight ablation",
        "target": "four_win_attack",
        "feature_names": list(WALL_FEATURES),
        "development_period": "2024-10-01..2025-09-30",
        "validation_period": "2025-10-01..2026-03-31",
        "confirmation_period": "2026-04-01..2026-09-30",
        "fit_population": "Development" if role == "SELECTION" else "Development + Validation",
        "missing_fill_values": model.median_.tolist(),
        "scaler_mean": model.mean_.tolist(),
        "scaler_std": model.scale_.tolist(),
        "scaler_ddof": 0,
        "intercept": float(model.beta_[0]),
        "coefficients": model.beta_[1:].tolist(),
        "model_type": "deterministic_newton_logistic_regression",
        "optimizer": "Newton-Raphson full Hessian",
        "regularization": {"type": "L2 ridge", "lambda": model.ridge, "intercept_regularized": False},
        "tolerance": model.tolerance,
        "max_iterations": model.max_iter,
        "iterations_used": model.iterations_,
        "logit_clipping": [-model.clip, model.clip],
        "st_smoothing_k": 20.0,
        "score_percentile_boundaries": boundaries or percentile_boundaries(development_probability),
        "input_dataset_sha256": source_sha,
        "source_script": str(Path(__file__).relative_to(REPO)),
        "source_script_sha256": sha(Path(__file__)),
        "source_git_commit": git_head(),
        "created_at": datetime.now().astimezone().isoformat(),
    }
    return result


def attack_artifact(role, theta, convergence, med, mean, std, source_sha, counts):
    return {
        "artifact_version": "course4-attack-type-no-straight-v1",
        "model_variant": "NO_STRAIGHT",
        "model_role": role,
        "model_name": "Attack Type fixed no-straight multinomial ablation",
        "class_order": list(CLASSES),
        "reference_class": 0,
        "feature_order": list(ATTACK_FEATURES),
        "development_medians": med.tolist(),
        "development_means": mean.tolist(),
        "development_stds": std.tolist(),
        "scaler_ddof": 0,
        "intercepts": theta[:, 0].tolist(),
        "coefficients": theta[:, 1:].tolist(),
        "solver": {
            "family": "deterministic Newton/IRLS multinomial reference-class softmax",
            "ridge": RIDGE,
            "intercept_regularized": False,
            "max_iter": MAX_ITER,
            "tolerance": TOL,
            "logit_clip": [-LOGIT_CLIP, LOGIT_CLIP],
            "probability_epsilon": EPS,
            "class_weight": None,
        },
        "convergence": convergence,
        "fit_sample": "Development" if role == "SELECTION" else "Development + Validation",
        "fit_class_counts": counts.astype(int).tolist(),
        "fit_class_prevalence": (counts / counts.sum()).tolist(),
        "training_period": "2024-10-01..2025-09-30" if role == "SELECTION" else "2024-10-01..2026-03-31",
        "validation_period": "2025-10-01..2026-03-31",
        "confirmation_period": "2026-04-01..2026-09-30",
        "dataset_path": str(ATTACK_DATA.relative_to(REPO)),
        "dataset_sha256": source_sha,
        "source_script": str(Path(__file__).relative_to(REPO)),
        "source_script_sha256": sha(Path(__file__)),
        "source_git_commit": git_head(),
        "generated_at": datetime.now().astimezone().isoformat(),
    }


def full_wall_predict(artifact, data):
    names = artifact["feature_names"]
    x = np.asarray([[number(row[name]) for name in names] for row in data], dtype=float)
    return predict_wall_artifact(artifact, x)


def full_attack_predict(artifact, data):
    names = artifact["feature_order"]
    x = np.asarray([[number(row[name]) for name in names] for row in data], dtype=float)
    med = np.asarray(artifact["development_medians"])
    mean = np.asarray(artifact["development_means"])
    std = np.asarray(artifact["development_stds"])
    z = (np.where(np.isnan(x), med, x) - mean) / std
    theta = np.c_[np.asarray(artifact["intercepts"]), np.asarray(artifact["coefficients"])]
    return softmax_ref(np.c_[np.ones(len(z)), z], theta)


def database_availability():
    from slit_validate_v2 import connect_db
    query = """
        SELECT substring(race_code from 9 for 3) venue,
               count(DISTINCT race_code), count(*), count(straight_time), count(lap_time),
               count(around_time), count(exhibition_time), count(start_timing)
        FROM boat_race.exhibition_live
        WHERE substring(race_code from 1 for 8) BETWEEN '20241001' AND '20260930'
          AND substring(race_code from 9 for 3)=ANY(%s)
        GROUP BY 1 ORDER BY 1
    """
    result = {}
    with connect_db() as connection, connection.cursor() as cursor:
        cursor.execute(query, (list(VENUES),))
        for code, race_n, boat_n, straight, lap, around, exhibition, ex_st in cursor.fetchall():
            result[code] = {
                "race_count": int(race_n), "boat_rows": int(boat_n),
                "straight_time_non_null_n": int(straight), "straight_time_non_null_rate": float(straight / boat_n),
                "lap_time_non_null_n": int(lap), "lap_time_non_null_rate": float(lap / boat_n),
                "around_time_non_null_n": int(around), "around_time_non_null_rate": float(around / boat_n),
                "exhibition_time_non_null_n": int(exhibition), "exhibition_time_non_null_rate": float(exhibition / boat_n),
                "ex_st_non_null_n": int(ex_st), "ex_st_non_null_rate": float(ex_st / boat_n),
            }
    return result


def main():
    required = [WALL_CANONICAL, WALL_TARGETS, WALL_FULL, ATTACK_DATA, ATTACK_FULL]
    if not all(path.exists() for path in required):
        raise RuntimeError("required frozen research source is missing")

    canonical = {row["race_code"]: row for row in rows(WALL_CANONICAL)}
    target_rows = rows(WALL_TARGETS)
    if len(canonical) != 86504 or len(target_rows) != 86504 or set(canonical) != {row["race_code"] for row in target_rows}:
        raise RuntimeError("Wall canonical/target universe mismatch")
    wall = []
    for target in target_rows:
        raw = canonical[target["race_code"]]
        item = {
            "race_code": target["race_code"], "race_date": target["race_date"], "stadium": target["stadium"],
            "venue_code": target["race_code"][8:11], "split": split_name(target["race_date"]),
            "four_win_attack": target["four_win_attack"],
        }
        item.update({name: raw[f"feature_{name}"] for name in WALL_FEATURES})
        item["straight_time_delta_34"] = raw["feature_straight_time_delta_34"]
        wall.append(item)

    attack = rows(ATTACK_DATA)
    if len(attack) != 86504:
        raise RuntimeError("Attack Type frozen universe mismatch")
    for row in attack:
        row["venue_code"] = row["race_code"][8:11]

    wall_split = {name: [row for row in wall if row["split"] == name] for name in ("Development", "Validation", "EXPOSED_CONFIRMATION")}
    attack_split = {name: [row for row in attack if row["split"] == name] for name in ("Development", "Validation", "EXPOSED_CONFIRMATION")}

    xwd, ywd = wall_xy(wall_split["Development"])
    xwv, ywv = wall_xy(wall_split["Validation"])
    xwc, ywc = wall_xy(wall_split["EXPOSED_CONFIRMATION"])
    wall_selection_model = LinearLogit().fit(xwd, ywd)
    pwd = wall_selection_model.predict(xwd)
    pwv = wall_selection_model.predict(xwv)
    wall_source_sha = composite_sha((WALL_CANONICAL, WALL_TARGETS))
    wall_selection = wall_artifact("SELECTION", wall_selection_model, wall_source_sha, pwd)
    wall_selection["validation_metrics"] = binary_metrics(ywv, pwv)
    WALL_SELECTION_OUT.write_text(json.dumps(wall_selection, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    wall_final_model = LinearLogit().fit(np.vstack((xwd, xwv)), np.r_[ywd, ywv])
    pwdv = wall_final_model.predict(np.vstack((xwd, xwv)))
    pwc = wall_final_model.predict(xwc)
    wall_final = wall_artifact("FINAL", wall_final_model, wall_source_sha, pwdv, percentile_boundaries(pwdv))
    wall_final["fit_n"] = int(len(ywd) + len(ywv))
    wall_final["confirmation_metrics"] = binary_metrics(ywc, pwc)
    WALL_FINAL_OUT.write_text(json.dumps(wall_final, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    wall_reload = json.loads(WALL_FINAL_OUT.read_text())
    wall_rt = roundtrip(pwc, predict_wall_artifact(wall_reload, xwc))
    wall_final["round_trip"] = wall_rt
    WALL_FINAL_OUT.write_text(json.dumps(wall_final, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    xad, yad = attack_xy(attack_split["Development"])
    xav, yav = attack_xy(attack_split["Validation"])
    xac, yac = attack_xy(attack_split["EXPOSED_CONFIRMATION"])
    med = np.nanmedian(xad, axis=0)
    dev_filled = np.where(np.isnan(xad), med, xad)
    mean = dev_filled.mean(axis=0)
    std = dev_filled.std(axis=0, ddof=0)
    if not np.isfinite(med).all() or not np.isfinite(std).all() or np.any(std <= 1e-10):
        raise RuntimeError("invalid Attack Type Development preprocessing")
    zad = (dev_filled - mean) / std
    zav = (np.where(np.isnan(xav), med, xav) - mean) / std
    zac = (np.where(np.isnan(xac), med, xac) - mean) / std
    attack_theta_selection, attack_conv_selection = fit_multiclass(zad, yad)
    pad = softmax_ref(np.c_[np.ones(len(zad)), zad], attack_theta_selection)
    pav = softmax_ref(np.c_[np.ones(len(zav)), zav], attack_theta_selection)
    attack_source_sha = sha(ATTACK_DATA)
    attack_selection = attack_artifact("SELECTION", attack_theta_selection, attack_conv_selection, med, mean, std, attack_source_sha, np.bincount(yad, minlength=3))
    attack_selection["validation_metrics"] = attack_metrics(yav, pav)
    ATTACK_SELECTION_OUT.write_text(json.dumps(attack_selection, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    zadv = np.vstack((zad, zav)); yadv = np.r_[yad, yav]
    attack_theta_final, attack_conv_final = fit_multiclass(zadv, yadv)
    pac = softmax_ref(np.c_[np.ones(len(zac)), zac], attack_theta_final)
    attack_final = attack_artifact("FINAL", attack_theta_final, attack_conv_final, med, mean, std, attack_source_sha, np.bincount(yadv, minlength=3))
    attack_final["fit_n"] = int(len(yadv))
    attack_final["confirmation_metrics"] = attack_metrics(yac, pac)
    ATTACK_FINAL_OUT.write_text(json.dumps(attack_final, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    attack_reload = json.loads(ATTACK_FINAL_OUT.read_text())
    attack_rt = roundtrip(pac, attack_predict(attack_reload, xac))
    attack_final["round_trip"] = attack_rt
    ATTACK_FINAL_OUT.write_text(json.dumps(attack_final, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    wall_val_3 = [row for row in wall_split["Validation"] if row["venue_code"] in VENUES]
    wall_conf_3 = [row for row in wall_split["EXPOSED_CONFIRMATION"] if row["venue_code"] in VENUES]
    _, wall_val_3_y = wall_xy(wall_val_3); wall_val_3_p = wall_selection_model.predict(wall_xy(wall_val_3)[0])
    _, wall_conf_3_y = wall_xy(wall_conf_3); wall_conf_3_p = wall_final_model.predict(wall_xy(wall_conf_3)[0])
    attack_val_3 = [row for row in attack_split["Validation"] if row["venue_code"] in VENUES]
    attack_conf_3 = [row for row in attack_split["EXPOSED_CONFIRMATION"] if row["venue_code"] in VENUES]
    attack_val_3_x, attack_val_3_y = attack_xy(attack_val_3); attack_val_3_p = attack_predict(attack_selection, attack_val_3_x)
    attack_conf_3_x, attack_conf_3_y = attack_xy(attack_conf_3); attack_conf_3_p = attack_predict(attack_final, attack_conf_3_x)

    wall_full = json.loads(WALL_FULL.read_text())
    attack_full = json.loads(ATTACK_FULL.read_text())
    wall_common = [row for row in wall_split["Validation"] if math.isfinite(number(row["straight_time_delta_34"]))]
    wall_common_y = wall_xy(wall_common)[1]
    wall_common_no = wall_selection_model.predict(wall_xy(wall_common)[0])
    wall_common_full = full_wall_predict(wall_full, wall_common)
    attack_common = [row for row in attack_split["Validation"] if math.isfinite(number(row["course4_straight_time"]))]
    attack_common_y = attack_xy(attack_common)[1]
    attack_common_no = attack_predict(attack_selection, attack_xy(attack_common)[0])
    attack_common_full = full_attack_predict(attack_full, attack_common)

    availability = database_availability()
    split_counts = {
        "wall": {name: len(data) for name, data in wall_split.items()},
        "attack_type": {name: len(data) for name, data in attack_split.items()},
        "three_venue": {
            code: Counter(row["split"] for row in attack if row["venue_code"] == code)
            for code in VENUES
        },
    }
    spec = {
        "spec_version": "course4-tenkai-no-straight-v1",
        "venue_codes": VENUES,
        "wall_feature_order": list(WALL_FEATURES),
        "attack_type_feature_order": list(ATTACK_FEATURES),
        "removed_features": ["straight_time_delta_34", "course4_straight_time"],
        "target_and_universe_policy": "identical to frozen Phase 3R / Phase 7 sources",
        "split": {"Development": "2024-10-01..2025-09-30", "Validation": "2025-10-01..2026-03-31", "EXPOSED_CONFIRMATION": "2026-04-01..2026-09-30"},
        "selection_policy": "fixed ablation; no search or tuning",
        "source_identity": {str(path.relative_to(REPO)): sha(path) for path in required},
        "generated_at": datetime.now().astimezone().isoformat(),
        "source_git_commit": git_head(),
    }
    SPEC_OUT.write_text(json.dumps(spec, ensure_ascii=False, indent=2, default=dict) + "\n", encoding="utf-8")

    metric_rows = []
    for model, split_label, data, probabilities in (
        ("WALL", "Validation", wall_val_3, wall_val_3_p),
        ("WALL", "EXPOSED_CONFIRMATION", wall_conf_3, wall_conf_3_p),
    ):
        for code, metric in venue_binary_metrics(data, probabilities).items():
            metric_rows.append({"model": model, "split": split_label, "venue_code": code, "venue": VENUES[code], **{k: v for k, v in metric.items() if not isinstance(v, dict)}, "calibration_slope": metric.get("calibration", {}).get("slope")})
    for model, split_label, data, probabilities in (
        ("ATTACK_TYPE", "Validation", attack_val_3, attack_val_3_p),
        ("ATTACK_TYPE", "EXPOSED_CONFIRMATION", attack_conf_3, attack_conf_3_p),
    ):
        for code, metric in venue_attack_metrics(data, probabilities).items():
            for class_name in ("MAKURI_SUCCESS", "MAKURIZASHI_SUCCESS"):
                values = metric.get(class_name, {})
                metric_rows.append({"model": model, "class": class_name, "split": split_label, "venue_code": code, "venue": VENUES[code], "n": metric.get("n"), **{k: v for k, v in values.items() if not isinstance(v, dict)}, "calibration_slope": values.get("calibration", {}).get("slope"), "multiclass_logloss": metric.get("multiclass", {}).get("logloss")})
    keys = sorted({key for row in metric_rows for key in row})
    with METRICS_OUT.open("w", newline="", encoding="utf-8") as handle:
        writer = csv.DictWriter(handle, fieldnames=keys); writer.writeheader(); writer.writerows(metric_rows)

    validation = {
        "wall": {
            "pooled_three_venues": binary_metrics(wall_val_3_y, wall_val_3_p),
            "by_venue": venue_binary_metrics(wall_val_3, wall_val_3_p),
        },
        "attack_type": {
            "pooled_three_venues": attack_metrics(attack_val_3_y, attack_val_3_p),
            "by_venue": venue_attack_metrics(attack_val_3, attack_val_3_p),
        },
    }
    confirmation = {
        "wall": {"pooled_three_venues": binary_metrics(wall_conf_3_y, wall_conf_3_p), "by_venue": venue_binary_metrics(wall_conf_3, wall_conf_3_p)},
        "attack_type": {"pooled_three_venues": attack_metrics(attack_conf_3_y, attack_conf_3_p), "by_venue": venue_attack_metrics(attack_conf_3, attack_conf_3_p)},
    }
    sanity = {
        "wall_validation_common_straight_subset": {"n": len(wall_common), "full": binary_metrics(wall_common_y, wall_common_full), "no_straight": binary_metrics(wall_common_y, wall_common_no)},
        "attack_validation_common_straight_subset": {"n": len(attack_common), "full": attack_metrics(attack_common_y, attack_common_full), "no_straight": attack_metrics(attack_common_y, attack_common_no)},
    }
    report = {
        "phase": "course4-tenkai-no-straight-3venues-v1",
        "canonical_venue_codes": VENUES,
        "availability_audit": availability,
        "dataset": {"NO_STRAIGHT_DATASET": "READY", "total_n": len(attack), "split_counts": split_counts, "wall_source_sha256": wall_source_sha, "attack_source_sha256": attack_source_sha},
        "wall": {"WALL_NO_STRAIGHT_MODEL": "READY", "selection_artifact": str(WALL_SELECTION_OUT.relative_to(REPO)), "selection_sha256": sha(WALL_SELECTION_OUT), "final_artifact": str(WALL_FINAL_OUT.relative_to(REPO)), "final_sha256": sha(WALL_FINAL_OUT), "feature_order": list(WALL_FEATURES), "round_trip": wall_rt},
        "attack_type": {"ATTACK_TYPE_NO_STRAIGHT_MODEL": "READY", "selection_artifact": str(ATTACK_SELECTION_OUT.relative_to(REPO)), "selection_sha256": sha(ATTACK_SELECTION_OUT), "final_artifact": str(ATTACK_FINAL_OUT.relative_to(REPO)), "final_sha256": sha(ATTACK_FINAL_OUT), "feature_order": list(ATTACK_FEATURES), "round_trip": attack_rt},
        "validation": validation,
        "confirmation": confirmation,
        "full_vs_no_straight_sanity": sanity,
        "spec_path": str(SPEC_OUT.relative_to(REPO)),
        "spec_sha256": sha(SPEC_OUT),
        "venue_metrics_path": str(METRICS_OUT.relative_to(REPO)),
        "venue_metrics_sha256": sha(METRICS_OUT),
        "generated_at": datetime.now().astimezone().isoformat(),
        "source_script": str(Path(__file__).relative_to(REPO)),
        "source_script_sha256": sha(Path(__file__)),
        "source_git_commit": git_head(),
    }
    REPORT_OUT.write_text(json.dumps(report, ensure_ascii=False, indent=2, default=dict) + "\n", encoding="utf-8")
    print(json.dumps({"report": str(REPORT_OUT), "wall_validation": validation["wall"]["pooled_three_venues"], "attack_validation": validation["attack_type"]["pooled_three_venues"], "wall_confirmation": confirmation["wall"]["pooled_three_venues"], "attack_confirmation": confirmation["attack_type"]["pooled_three_venues"], "wall_artifact_sha256": sha(WALL_FINAL_OUT), "attack_artifact_sha256": sha(ATTACK_FINAL_OUT)}, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
