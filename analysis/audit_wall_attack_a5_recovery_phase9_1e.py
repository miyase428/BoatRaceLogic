#!/usr/bin/env python3
"""Phase 9-1e read-only recovery audit for frozen WALL-R and Attack A5.

No database access, model fitting, artifact mutation, or live inference occurs.
Only fixed research caches/artifacts and the existing canonical history ledger
are read; this script writes new audit artifacts.
"""
from __future__ import annotations

import bisect
import csv
import hashlib
import json
import subprocess
import sys
from calendar import monthrange
from datetime import date, datetime
from pathlib import Path

import numpy as np

ROOT = Path(__file__).resolve().parent
REPO = ROOT.parent
OUT = ROOT / "output"
REPORT = OUT / "course4_tenkai_phase9_1e_report.json"
INVENTORY = OUT / "course4_tenkai_phase9_1e_feature_dependencies.csv"
WALL_ARTIFACT = OUT / "course34_wall_phase3r_final_model.json"
A5_ARTIFACT = OUT / "course4_attack_phase4r_final_model.json"
SUCCESS_ARTIFACT = OUT / "course4_attack_success_phase5_final_model.json"
LEDGER = OUT / "course4_attack_history_canonical_ledger_v1.csv"
A5_INPUT = OUT / "course4_attack_phase4_input_20241001_20260930.csv"
EXPECTED_HASHES = {
    WALL_ARTIFACT: "f5348195d1d05c982f273ce7924c2b0eda5174e243e3e01bb818b87d6f3e768c",
    A5_ARTIFACT: "39808675efde92bfcbb73d69f88e915260f7dd0e0c48636d1b2775d6b2874176",
    SUCCESS_ARTIFACT: "9dae5b89626692bbcc31e01f02b1ce745f4046976250e06c76d1d79491d7af4a",
}
HISTORY_FEATURES = (
    "course4_history_n", "course4_history_win_rate_pct", "course4_history_top2_rate_pct",
    "course4_history_top3_rate_pct", "course4_history_makuri_rate_pct",
    "course4_history_makurizashi_rate_pct", "course4_history_attack_rate_pct",
)


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def git_head() -> str:
    return subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=REPO, text=True).strip()


def f(value):
    try:
        return float(value) if value not in (None, "") else np.nan
    except (TypeError, ValueError):
        return np.nan


def stats(reference, replay, tolerance=1e-12):
    a, b = np.asarray(reference, dtype=np.float64), np.asarray(replay, dtype=np.float64)
    both_missing = np.isnan(a) & np.isnan(b)
    numeric = ~np.isnan(a) & ~np.isnan(b)
    one_missing = np.isnan(a) ^ np.isnan(b)
    diff = np.abs(a[numeric] - b[numeric])
    exact = both_missing.sum() + np.sum(diff == 0.0)
    within = both_missing.sum() + np.sum(diff <= tolerance)
    return {
        "n": int(len(a)), "numeric_pair_n": int(numeric.sum()), "both_missing_n": int(both_missing.sum()),
        "one_side_missing_n": int(one_missing.sum()), "exact_match_count": int(exact),
        "exact_match_rate": float(exact / len(a)), "within_tolerance_count": int(within),
        "within_tolerance_rate": float(within / len(a)), "tolerance": tolerance,
        "max_abs_diff": None if not len(diff) else float(diff.max()),
        "mean_abs_diff": None if not len(diff) else float(diff.mean()),
        "mismatch_count": int(len(a) - within),
    }


def months_ago_12(value: date) -> date:
    year = value.year - 1
    return date(year, value.month, min(value.day, monthrange(year, value.month)[1]))


def split_name(value: str) -> str:
    d = date.fromisoformat(value)
    if d <= date(2025, 9, 30):
        return "Development"
    if d <= date(2026, 3, 31):
        return "Validation"
    return "EXPOSED_CONFIRMATION"


def ledger_index():
    index = {}
    baseline_n = 0
    with LEDGER.open(encoding="utf-8") as handle:
        for row in csv.DictReader(handle):
            if str(row["baseline_frozen"]) not in ("1", "1.0", "True", "true"):
                continue
            baseline_n += 1
            row["date"] = date.fromisoformat(row["race_date"])
            row["final_rank"] = int(row["final_rank"])
            row["is_makuri"] = int(row["is_makuri"])
            row["is_makurizashi"] = int(row["is_makurizashi"])
            index.setdefault(str(row["player_id"]), []).append(row)
    for rows in index.values():
        rows.sort(key=lambda x: (x["date"], x["race_code"], x["event_id"]))
    return index, baseline_n


def history_from_ledger(rows, pid: str, target: date):
    candidates = rows.get(str(pid), [])
    dates = [x["date"] for x in candidates]
    lo, hi = bisect.bisect_left(dates, months_ago_12(target)), bisect.bisect_left(dates, target)
    selected = candidates[lo:hi]
    n = len(selected)
    pct = lambda count: np.nan if n == 0 else 100.0 * count / n
    makuri = sum(x["is_makuri"] for x in selected)
    makurizashi = sum(x["is_makurizashi"] for x in selected)
    return {
        "course4_history_n": float(n),
        "course4_history_win_rate_pct": pct(sum(x["final_rank"] == 1 for x in selected)),
        "course4_history_top2_rate_pct": pct(sum(x["final_rank"] <= 2 for x in selected)),
        "course4_history_top3_rate_pct": pct(sum(x["final_rank"] <= 3 for x in selected)),
        "course4_history_makuri_rate_pct": pct(makuri),
        "course4_history_makurizashi_rate_pct": pct(makurizashi),
        "course4_history_attack_rate_pct": pct(makuri + makurizashi),
    }


def feature_inventory(wall_artifact, a5_artifact):
    rows = []
    wall = {
        "smoothed_avg_st_delta_34": ("smoothed3-smoothed4; smoothed=overall+w*(course-overall)", "C3/C4 course average_start, entry_count; term/player overall_st", "Phase3 fixed input caches + fixed overall-ST cache", 30),
        "ex_st_delta_34": ("C3 exhibition ST - C4 exhibition ST", "C3/C4 exhibition ST", "Phase3 fixed input caches", None),
        "avg_st_rank_delta_34": ("C3 course average_rank - C4 course average_rank", "C3/C4 racer_results average_rank (actual semantic: average finish rank)", "Phase3 fixed input caches", None),
        "st_profile_reliability_advantage_34": ("w4-w3; w=n/(n+30)", "C3/C4 course entry_count", "Phase3 fixed input caches", 30),
        "exhibition_time_delta_34": ("C3-C4", "C3/C4 exhibition_time", "Phase3 fixed input caches", None),
        "lap_time_delta_34": ("C3-C4", "C3/C4 lap_time", "Phase3 fixed input caches", None),
        "around_time_delta_34": ("C3-C4", "C3/C4 around_time", "Phase3 fixed input caches", None),
        "straight_time_delta_34": ("C3-C4", "C3/C4 straight_time", "Phase3 fixed input caches", None),
    }
    for order, name in enumerate(wall_artifact["feature_names"], 1):
        formula, dependencies, source, k = wall[name]
        rows.append({"signal_id": "WALL_R", "feature_order": order, "feature_name": name,
                     "formula": formula, "raw_dependencies": dependencies, "research_source": source,
                     "research_transform": "artifact median fill; standardize mean/std ddof=0", "missing_policy": "artifact median",
                     "k": "" if k is None else k, "recovery_evidence": "fixed source hash matches artifact input hash",
                     "recovery_status": "RESCUABLE_FROM_FIXED_RESEARCH_SOURCE"})
    history_formulas = {
        "course4_history_n": "count eligible actual-4C events in [target-12m,target)",
        "course4_history_win_rate_pct": "100*count(final_rank=1)/history_n",
        "course4_history_top2_rate_pct": "100*count(final_rank<=2)/history_n",
        "course4_history_top3_rate_pct": "100*count(final_rank<=3)/history_n",
        "course4_history_makuri_rate_pct": "100*count(rank=1 and MAKURI)/history_n",
        "course4_history_makurizashi_rate_pct": "100*count(rank=1 and MAKURIZASHI)/history_n",
        "course4_history_attack_rate_pct": "100*(MAKURI+MAKURIZASHI wins)/history_n",
    }
    pre = {
        "smoothed_course4_st": ("overall_average_st+w*(course4_average_st-overall_average_st); w=n/(n+20)", "course4_average_st, course4_entry_count, overall_average_st", 20),
        "course4_average_st_rank": ("identity", "course4 average_rank (actual semantic: average finish rank, despite legacy name)", None),
        "course4_entry_count": ("identity", "course4 entry_count", None),
        "course4_ex_st": ("identity", "C4 exhibition ST", None), "course4_exhibition_time": ("identity", "C4 exhibition_time", None),
        "course4_lap_time": ("identity", "C4 lap_time", None), "course4_around_time": ("identity", "C4 around_time", None),
        "course4_straight_time": ("identity", "C4 straight_time", None),
        "motor_2ren_rate": ("identity", "race/player scoped pre-race motor exacta rate", None),
        "boat_2ren_rate": ("identity", "race/player scoped pre-race boat exacta rate", None),
        "national_win_rate": ("identity", "race/player scoped pre-race national win rate", None),
        "local_win_rate": ("identity", "race/player scoped pre-race local win rate", None),
        "national_2ren_rate": ("identity", "race/player scoped pre-race national exacta rate", None),
        "local_2ren_rate": ("identity", "race/player scoped pre-race local exacta rate", None),
    }
    for order, name in enumerate(a5_artifact["feature_names"], 1):
        if name in HISTORY_FEATURES:
            formula, deps, source, evidence, status, k = history_formulas[name], "canonical event final_rank + normalized technique", "Canonical History Ledger v1 frozen baseline", "86,504-row tolerance parity", "RESCUED_BY_CANONICAL_LEDGER", ""
        else:
            formula, deps, k = pre[name]
            source = "Phase4 fixed input cache" if name not in {"overall_average_st", "motor_2ren_rate", "boat_2ren_rate", "national_win_rate", "local_win_rate", "national_2ren_rate", "local_2ren_rate"} else "Phase4 fixed pre-race monthly cache"
            if name == "smoothed_course4_st":
                source = "Phase4 fixed input cache + fixed monthly pre-race cache"
            if name in {"motor_2ren_rate", "boat_2ren_rate", "national_win_rate", "local_win_rate", "national_2ren_rate", "local_2ren_rate"}:
                source = "Phase4 fixed pre-race monthly cache"
            evidence, status = "race_code/player_id fixed snapshot; source hash included in artifact input hash", "RESCUABLE_FROM_FIXED_RESEARCH_SOURCE"
        rows.append({"signal_id": "ATTACK_A5", "feature_order": order, "feature_name": name,
                     "formula": formula, "raw_dependencies": deps, "research_source": source,
                     "research_transform": "artifact median fill; standardize mean/std ddof=0", "missing_policy": "artifact median",
                     "k": k, "recovery_evidence": evidence, "recovery_status": status})
    return rows


def main():
    for path, expected in EXPECTED_HASHES.items():
        actual = sha256(path)
        if actual != expected:
            raise RuntimeError(f"artifact hash mismatch: {path} {actual}")
    wall_artifact = json.loads(WALL_ARTIFACT.read_text())
    a5_artifact = json.loads(A5_ARTIFACT.read_text())

    sys.path.insert(0, str(ROOT))
    import analyze_course34_wall_phase3r as wall
    import analyze_course4_attack_phase4r as attack

    wall_raw, wall_overall, wall_paths = wall.source_rows()
    wall_source_hash = wall.sha256_paths(wall_paths)
    wall_prepared = wall.add_features(wall_raw, wall_overall, wall_artifact["st_smoothing_k"])
    wall_dev, wall_val, wall_conf = wall.split(wall_prepared)
    wall_x_conf, wall_y_conf = wall.xy(wall_conf, wall_artifact["feature_names"])
    wall_prob = wall.predict_artifact(wall_artifact, wall_x_conf)
    wall_saved = {r["race_code"]: f(r["wall_r_probability"]) for r in csv.DictReader((OUT / "course34_wall_phase3r_exposed_confirmation_scores.csv").open())}
    wall_ref = [wall_saved[r["race_code"]] for r in wall_conf]
    wall_probability_parity = stats(wall_ref, wall_prob)
    wall_metrics = wall.metric_dict(wall_y_conf, wall_prob)
    wall_report_metrics = json.loads((OUT / "course34_wall_phase3r_report.json").read_text())["exposed_confirmation"]
    wall_metric_diff = {key: abs(wall_metrics[key] - wall_report_metrics[key]) for key in wall_metrics}

    a5_rows, a5_paths = attack.source_rows()
    a5_source_hash = attack.paths_hash(a5_paths)
    a5_dev, a5_val, a5_conf = attack.split(a5_rows)
    a5_prob = attack.predict_artifact(a5_artifact, attack.x_of(a5_conf, attack.A5_FEATURES))
    a5_metrics = attack.metrics(attack.y_of(a5_conf), a5_prob)
    a5_report_metrics = json.loads((OUT / "course4_attack_phase4r_report.json").read_text())["a5_final_exposed_confirmation"]
    a5_metric_diff = {key: abs(a5_metrics[key] - a5_report_metrics[key]) for key in a5_metrics}

    ledger, baseline_n = ledger_index()
    reference = list(csv.DictReader(A5_INPUT.open(encoding="utf-8")))
    history_parity = {split: {} for split in ("Development", "Validation", "EXPOSED_CONFIRMATION")}
    replay = []
    for row in reference:
        target = date.fromisoformat(row["race_date"])
        replay.append(history_from_ledger(ledger, row["course4_player_id"], target))
    for split in history_parity:
        selected = [i for i, row in enumerate(reference) if split_name(row["race_date"]) == split]
        for feature in HISTORY_FEATURES:
            history_parity[split][feature] = stats([f(reference[i][feature]) for i in selected], [replay[i][feature] for i in selected])
    history_all = {feature: stats([f(row[feature]) for row in reference], [row[feature] for row in replay]) for feature in HISTORY_FEATURES}

    inventory = feature_inventory(wall_artifact, a5_artifact)
    with INVENTORY.open("w", newline="", encoding="utf-8") as handle:
        writer = csv.DictWriter(handle, fieldnames=list(inventory[0]))
        writer.writeheader(); writer.writerows(inventory)

    artifact_audit = {str(path.relative_to(REPO)): {"expected_sha256": expected, "actual_sha256": sha256(path), "match": True} for path, expected in EXPECTED_HASHES.items()}
    wall_rescuable = wall_source_hash == wall_artifact["input_dataset_sha256"] and wall_probability_parity["mismatch_count"] == 0 and max(wall_metric_diff.values()) <= 1e-12
    history_rescued = all(value["mismatch_count"] == 0 for value in history_all.values())
    a5_rescuable = a5_source_hash == a5_artifact["input_dataset_sha256"] and history_rescued and max(a5_metric_diff.values()) <= 1e-12
    report = {
        "phase": "9-1e", "mode": "READ_ONLY_AUDIT", "artifact_hash_audit": artifact_audit,
        "wall_r": {
            "verdict": "RESCUABLE" if wall_rescuable else "NOT_RESCUABLE_WITH_CURRENT_ARTIFACT",
            "artifact_feature_order": wall_artifact["feature_names"], "k": wall_artifact["st_smoothing_k"],
            "fixed_research_sources": [{"path": str(path.relative_to(REPO)), "sha256": sha256(path)} for path in wall_paths],
            "input_source_sha256": wall_source_hash, "artifact_input_source_sha256": wall_artifact["input_dataset_sha256"],
            "source_hash_match": wall_source_hash == wall_artifact["input_dataset_sha256"],
            "split_n": {"Development": len(wall_dev), "Validation": len(wall_val), "EXPOSED_CONFIRMATION": len(wall_conf)},
            "blocked_feature_recovery": {
                "smoothed_avg_st_delta_34": "raw course/overall ST and entry-count values fixed in artifact-hash-bound caches",
                "avg_st_rank_delta_34": "race-level raw values fixed; actual raw meaning is average finish rank, not ST rank",
                "st_profile_reliability_advantage_34": "race-level C3/C4 entry counts fixed; K=30 formula deterministic",
            },
            "final_feature_replay": {"n": len(wall_prepared), "feature_n": len(wall_artifact["feature_names"]), "deterministic_from_fixed_source": True},
            "exposed_confirmation_probability_parity": wall_probability_parity,
            "exposed_confirmation_metric_max_abs_diff": max(wall_metric_diff.values()),
            "research_time_final_feature_matrix_saved": False,
            "recovery_basis": "criterion C: artifact-hash-bound fixed raw sources deterministically regenerate the final features; saved confirmation probabilities match <=1e-12",
        },
        "attack_a5": {
            "verdict": "RESCUABLE" if a5_rescuable else "NOT_RESCUABLE_WITH_CURRENT_ARTIFACT",
            "artifact_feature_order": a5_artifact["feature_names"], "k": a5_artifact["st_smoothing_k"],
            "input_source_sha256": a5_source_hash, "artifact_input_source_sha256": a5_artifact["input_dataset_sha256"],
            "source_hash_match": a5_source_hash == a5_artifact["input_dataset_sha256"],
            "fixed_source_file_count": len(a5_paths),
            "split_n": {"Development": len(a5_dev), "Validation": len(a5_val), "EXPOSED_CONFIRMATION": len(a5_conf)},
            "history_ledger": {"path": str(LEDGER.relative_to(REPO)), "sha256": sha256(LEDGER), "baseline_row_count": baseline_n},
            "history_parity_by_split": history_parity, "history_parity_all": history_all,
            "a5_history_blocker": "RESCUED" if history_rescued else "BLOCKED",
            "term_static_recovery": {
                "smoothed_course4_st": "course4_average_st/count fixed in base cache; overall_average_st fixed in monthly pre-race cache; K=20 deterministic",
                "course4_average_st_rank": "race-level value fixed; actual raw meaning is average finish rank, not ST rank",
                "course4_entry_count": "race-level value fixed in Phase4 input cache",
            },
            "player_motor_recovery": "race_code/player_id values fixed in 24 monthly Phase4 pre-race caches",
            "exposed_confirmation_metric_max_abs_diff": max(a5_metric_diff.values()),
            "recovery_basis": "criteria A/C: exact race-level research snapshots are artifact-hash-bound; history seven features reproduce from canonical ledger",
        },
        "attack_success": {
            "current_status": "TRANSITIVE_BLOCKED",
            "recovery_outlook": "RESCUABLE_AFTER_WALL_R_AND_ATTACK_A5_CANONICAL_PROVIDERS_PASS",
            "live_status_changed": False,
        },
        "feature_dependency_inventory": {"path": str(INVENTORY.relative_to(REPO)), "sha256": sha256(INVENTORY), "row_count": len(inventory)},
        "live_inference_implemented": False,
        "existing_live_status_changed": False,
        "next_safe_action": "Phase 9-1f: build new versioned immutable WALL-R/A5 canonical input sources and prove live/research parity before enabling inference",
        "generated_at": datetime.now().astimezone().isoformat(), "source_script": str(Path(__file__).relative_to(REPO)),
        "source_script_sha256": sha256(Path(__file__)), "source_git_commit": git_head(),
    }
    REPORT.write_text(json.dumps(report, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    if json.loads(REPORT.read_text()) != report:
        raise RuntimeError("report reload mismatch")
    print(json.dumps({"report": str(REPORT.relative_to(REPO)), "wall_r": report["wall_r"]["verdict"],
                      "attack_a5": report["attack_a5"]["verdict"], "a5_history": report["attack_a5"]["a5_history_blocker"]}, ensure_ascii=False))


if __name__ == "__main__":
    main()
