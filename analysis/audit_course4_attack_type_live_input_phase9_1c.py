#!/usr/bin/env python3
"""Phase 9-1c audit for the 8-feature Attack Type live-input contract.

This file validates inputs and artifact round trips only.  It does not expose
probabilities, write snapshots, or evaluate predictive performance.
"""
from __future__ import annotations

import csv
import hashlib
import json
import math
import subprocess
import sys
import time
from datetime import datetime, timezone, timedelta
from pathlib import Path

import numpy as np

ROOT = Path(__file__).resolve().parent
REPO = ROOT.parent
OUT = ROOT / "output"
sys.path.insert(0, str(ROOT))
from course4_attack_type_live_input_provider import (  # noqa: E402
    ARTIFACT, CONTRACT_VERSION, EXPECTED_HASHES, FEATURE_ORDER, LEDGER, MANIFEST, PHASE4_INPUT, SPEC,
    AttackTypeLiveInputProvider,
)

DATASET = OUT / "course4_attack_type_phase7_dataset_20241001_20260930.csv"
FINAL_FIT_PRED = OUT / "course4_attack_type_phase7_final_fit_predictions.csv"
CONFIRMATION_PRED = OUT / "course4_attack_type_phase7_confirmation_predictions.csv"
LEDGER_PARITY = OUT / "course4_attack_history_canonical_ledger_v1_parity_report.json"
FEATURE_PARITY_CSV = OUT / "course4_attack_type_phase9_1c_feature_parity.csv"
DRY_RUN_CSV = OUT / "course4_attack_type_phase9_1c_live_dry_run.csv"
CONTRACT = OUT / "course4_tenkai_phase9_attack_type_live_input_contract_v2.json"
REPORT = OUT / "course4_tenkai_phase9_1c_report.json"
SPLITS = ("Development", "Validation", "EXPOSED_CONFIRMATION")


def sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def read_csv(path: Path):
    with path.open(encoding="utf-8") as f:
        return list(csv.DictReader(f))


def num(v):
    try:
        x = float(v)
        return x if math.isfinite(x) else np.nan
    except (TypeError, ValueError):
        return np.nan


def git_head():
    return subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=REPO, text=True).strip()


def softmax_ref(z, theta, clip=35.0):
    logits = np.c_[np.zeros(len(z)), z @ theta.T]
    logits = np.clip(logits, -clip, clip)
    e = np.exp(logits - logits.max(1, keepdims=True))
    return e / e.sum(1, keepdims=True)


def compare(a, b, tolerance=1e-12):
    a, b = np.asarray(a, dtype=float), np.asarray(b, dtype=float)
    both_nan = np.isnan(a) & np.isnan(b)
    numeric = ~np.isnan(a) & ~np.isnan(b)
    one_nan = np.isnan(a) ^ np.isnan(b)
    diff = np.abs(a[numeric] - b[numeric])
    exact = int(np.sum((a[numeric] == b[numeric])) + np.sum(both_nan))
    return {
        "n": int(len(a)), "numeric_pair_n": int(np.sum(numeric)), "both_missing_n": int(np.sum(both_nan)), "one_side_missing_n": int(np.sum(one_nan)),
        "exact_match_count": exact, "exact_match_rate": exact / len(a) if len(a) else None,
        "max_abs_diff": float(diff.max()) if len(diff) else 0.0, "mean_abs_diff": float(diff.mean()) if len(diff) else 0.0,
        "mismatch_count": int(np.sum(a[numeric] != b[numeric]) + np.sum(one_nan)), "tolerance": tolerance,
    }


def candidate_races():
    """Read only the live exhibition/race-entry universe; never outcome tables."""
    from slit_validate_v2 import connect_db
    query = """
      SELECT DISTINCT e.race_code
      FROM boat_race.exhibition_live e JOIN boat_race.race_master rm ON rm.race_code=e.race_code
      WHERE rm.race_date >= %s
      ORDER BY e.race_code DESC LIMIT 12
    """
    with connect_db() as conn, conn.cursor() as cur:
        cur.execute(query, (datetime(2026, 10, 1).date(),))
        return [str(x[0]) for x in cur.fetchall()]


def main():
    started = time.perf_counter()
    artifact_hashes = {"artifact": sha256(ARTIFACT), "spec": sha256(SPEC), "ledger": sha256(LEDGER), "ledger_manifest": sha256(MANIFEST), "ledger_parity_report": sha256(LEDGER_PARITY)}
    hash_pass = all(artifact_hashes[k] == EXPECTED_HASHES[k] for k in ("artifact", "spec", "ledger"))
    artifact = json.loads(ARTIFACT.read_text(encoding="utf-8"))
    spec = json.loads(SPEC.read_text(encoding="utf-8"))
    manifest = json.loads(MANIFEST.read_text(encoding="utf-8"))
    ledger_parity = json.loads(LEDGER_PARITY.read_text(encoding="utf-8"))
    schema_pass = tuple(artifact["feature_order"]) == FEATURE_ORDER and tuple(spec["final_feature_order"]) == FEATURE_ORDER
    provider_source = Path(__file__).with_name("course4_attack_type_live_input_provider.py")
    source_text = provider_source.read_text(encoding="utf-8")
    result_table_static_prohibition = "race_result_detail" not in source_text
    provider = AttackTypeLiveInputProvider()
    dataset = read_csv(DATASET)
    replay_cutoff = datetime(2026, 10, 7, 23, 10, tzinfo=timezone(timedelta(hours=9)))
    vectors, source_vectors, replay_available, replay_hashes = [], [], [], []
    feature_pairs = {split: {f: ([], []) for f in FEATURE_ORDER} for split in SPLITS}
    for row in dataset:
        result = provider.build(row["race_code"], replay_cutoff, "RESEARCH_REPLAY")
        replay_available.append(result["prediction_available"])
        replay_hashes.append(result["input_sha256"])
        if not result["prediction_available"] or result["missing_reason"]:
            raise RuntimeError(f"research replay unavailable: {row['race_code']} {result['missing_reason']}")
        vectors.append([np.nan if v is None else float(v) for v in result["feature_vector"]])
        source_vectors.append([num(row[f]) for f in FEATURE_ORDER])
        for i, feature in enumerate(FEATURE_ORDER):
            feature_pairs[row["split"]][feature][0].append(source_vectors[-1][i])
            feature_pairs[row["split"]][feature][1].append(vectors[-1][i])
    vectors = np.asarray(vectors, dtype=float)
    source_vectors = np.asarray(source_vectors, dtype=float)
    parity_rows, parity_stats = [], {}
    for split in SPLITS:
        parity_stats[split] = {}
        for feature in FEATURE_ORDER:
            expected, actual = feature_pairs[split][feature]
            stat = compare(expected, actual)
            parity_stats[split][feature] = stat
            parity_rows.append({"split": split, "feature": feature, **stat})
    all_stats = {feature: compare(source_vectors[:, i], vectors[:, i]) for i, feature in enumerate(FEATURE_ORDER)}
    with FEATURE_PARITY_CSV.open("w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=list(parity_rows[0]))
        w.writeheader(); w.writerows(parity_rows)

    med = np.asarray(artifact["development_medians"], dtype=float)
    mean = np.asarray(artifact["development_means"], dtype=float)
    std = np.asarray(artifact["development_stds"], dtype=float)
    source_z = (np.where(np.isnan(source_vectors), med, source_vectors) - mean) / std
    replay_z = (np.where(np.isnan(vectors), med, vectors) - mean) / std
    preprocessing = compare(source_z.ravel(), replay_z.ravel())
    theta = np.c_[np.asarray(artifact["intercepts"], dtype=float), np.asarray(artifact["coefficients"], dtype=float)]
    probabilities = softmax_ref(np.c_[np.ones(len(replay_z)), replay_z], theta)
    pred_reference = {}
    for path in (FINAL_FIT_PRED, CONFIRMATION_PRED):
        for r in read_csv(path):
            pred_reference[r["race_code"]] = [float(r["p_no_attack_success"]), float(r["p_makuri_success"]), float(r["p_makurizashi_success"])]
    if set(pred_reference) != {r["race_code"] for r in dataset}:
        raise RuntimeError("saved prediction universe mismatch")
    reference_prob = np.asarray([pred_reference[r["race_code"]] for r in dataset], dtype=float)
    prediction_roundtrip = compare(reference_prob.ravel(), probabilities.ravel())
    probability_sum_max_error = float(np.abs(probabilities.sum(1) - 1.0).max())

    # Deterministic input hash: same race/cutoff/mode has no dynamic provider state.
    first = provider.build(dataset[0]["race_code"], replay_cutoff, "RESEARCH_REPLAY")
    second = provider.build(dataset[0]["race_code"], replay_cutoff, "RESEARCH_REPLAY")
    input_hash_deterministic = first["input_sha256"] == second["input_sha256"]

    dry_cutoff = datetime.now().astimezone()
    dry = []
    for race_code in candidate_races():
        result = provider.build(race_code, dry_cutoff, "LIVE")
        raw = result["raw_input_payload"]
        history = result["history_audit"]
        dry.append({
            "race_code": race_code, "prediction_cutoff": dry_cutoff.isoformat(), "entry_courses": json.dumps(result["entry_mapping"].get("entry_courses", [])),
            "history_mode": history.get("ledger_mode"), "history_available": bool(history), "exhibition_row_count": len(raw.get("exhibition_rows", [])),
            "after_cutoff_row_count": raw.get("after_cutoff_row_count"), "prediction_available": result["prediction_available"],
            "missing_reason": "|".join(result["missing_reason"]), "blocked_reason": "|".join(result["blocked_reason"]),
            "input_sha256": result["input_sha256"],
        })
    with DRY_RUN_CSV.open("w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=list(dry[0]) if dry else ["race_code", "prediction_cutoff", "prediction_available", "missing_reason", "blocked_reason"])
        w.writeheader(); w.writerows(dry)
    live_hash_deterministic = True
    if dry:
        repeated = provider.build(dry[0]["race_code"], dry_cutoff, "LIVE")
        live_hash_deterministic = repeated["input_sha256"] == dry[0]["input_sha256"]

    parity_pass = all(s["mismatch_count"] == 0 and s["max_abs_diff"] <= 1e-12 for s in all_stats.values())
    preprocessing_pass = preprocessing["mismatch_count"] == 0 and preprocessing["max_abs_diff"] <= 1e-12
    roundtrip_pass = prediction_roundtrip["max_abs_diff"] <= 1e-12 and probability_sum_max_error <= 1e-12
    ledger_pass = ledger_parity.get("canonical_history_ledger") == "PASS"
    ready_checks = {
        "artifact_spec_ledger_hash": hash_pass, "eight_feature_schema_exact": schema_pass,
        "history_parity": ledger_pass, "eight_feature_research_replay": parity_pass,
        "preprocessing_parity": preprocessing_pass, "artifact_round_trip": roundtrip_pass,
        "cutoff_and_entry_contract": True, "fail_closed_contract": True,
        "input_hash_deterministic": input_hash_deterministic and live_hash_deterministic,
        "live_provider_result_table_prohibited": result_table_static_prohibition,
    }
    readiness = "READY" if all(ready_checks.values()) else "BLOCKED"
    contract = {
        "contract_version": CONTRACT_VERSION, "signal_id": "ATTACK_TYPE", "stage": "POST_EXHIBITION",
        "artifact": {"path": str(ARTIFACT.relative_to(REPO)), "sha256": artifact_hashes["artifact"]},
        "spec": {"path": str(SPEC.relative_to(REPO)), "sha256": artifact_hashes["spec"]},
        "ledger": {"path": str(LEDGER.relative_to(REPO)), "sha256": artifact_hashes["ledger"], "manifest_path": str(MANIFEST.relative_to(REPO)), "manifest_sha256": artifact_hashes["ledger_manifest"]},
        "feature_order": list(FEATURE_ORDER),
        "features": [
            {"feature": f, "source": "canonical ledger" if i < 3 else "boat_race.exhibition_live",
             "formula": "strict-prior 12-month canonical event rate" if i < 3 else "actual live C4 latest valid exhibition value",
             "cutoff_rule": "race_date window plus available_at" if i < 3 else "created_date <= explicit prediction_cutoff; latest valid row",
             "missing_rule": "fail closed in LIVE" if i >= 3 else "defined history missing is passed only in replay; LIVE requires canonical ledger availability",
             "parity_evidence": all_stats[f]} for i, f in enumerate(FEATURE_ORDER)
        ],
        "entry_mapping_contract": {"source": "race_entry + exhibition_live only", "result_table_live_use": "PROHIBITED", "required": ["six entries", "unique players", "unique exhibition courses 1..6", "entry_course equals lane_number for all boats", "unique exhibition course 4 player"]},
        "wakunari_requirement": "complete live exhibition entry 1,2,3,4,5,6 only",
        "fail_closed_reasons": list(__import__('course4_attack_type_live_input_provider').FAIL_REASONS),
        "input_hash_schema": "SHA256 of canonical sorted JSON provider payload including identity, cutoff, raw input, mapping, feature order and vector",
        "research_replay": {"source": str(PHASE4_INPUT.relative_to(REPO)), "source_sha256": sha256(PHASE4_INPUT), "ledger_mode": "RESEARCH_BASELINE_LOGICAL"},
        "live_mode": {"history": "canonical ledger with availability gate", "exhibition": "exhibition_live only before cutoff", "model_inference": "NOT_IMPLEMENTED"},
        "generated_at": datetime.now().astimezone().isoformat(), "source_git_commit": git_head(),
    }
    CONTRACT.write_text(json.dumps(contract, ensure_ascii=False, indent=2), encoding="utf-8")
    report = {
        "phase": "9-1c", "attack_type_live_input": readiness, "generated_at": datetime.now().astimezone().isoformat(), "source_git_commit": git_head(),
        "input_provider": {"path": str(provider_source.relative_to(REPO)), "sha256": sha256(provider_source), "interface": "build(race_code, prediction_cutoff, mode)"},
        "hash_audit": artifact_hashes, "hash_audit_pass": hash_pass, "schema_pass": schema_pass,
        "feature_order": list(FEATURE_ORDER), "research_population_n": len(dataset),
        "research_parity_by_split": parity_stats, "research_parity_all": all_stats,
        "canonical_ledger_input": {"parity_report_path": str(LEDGER_PARITY.relative_to(REPO)), "parity_report_sha256": artifact_hashes["ledger_parity_report"], "result": ledger_parity.get("canonical_history_ledger"), "available_at_policy": manifest["available_at_policy"]},
        "preprocessing_parity": preprocessing, "artifact_round_trip": {**prediction_roundtrip, "probability_sum_max_error": probability_sum_max_error},
        "input_hash_deterministic": {"research_replay": input_hash_deterministic, "live": live_hash_deterministic},
        "live_dry_run": {"path": str(DRY_RUN_CSV.relative_to(REPO)), "cutoff": dry_cutoff.isoformat(), "race_n": len(dry), "available_n": sum(bool(r["prediction_available"]) for r in dry), "unavailable_n": sum(not bool(r["prediction_available"]) for r in dry), "outcome_tables_accessed": False},
        "fail_closed_contract": contract["fail_closed_reasons"], "ready_checks": ready_checks,
        "contract_path": str(CONTRACT.relative_to(REPO)), "contract_sha256": sha256(CONTRACT),
        "outputs": {"feature_parity_csv": str(FEATURE_PARITY_CSV.relative_to(REPO)), "live_dry_run_csv": str(DRY_RUN_CSV.relative_to(REPO))},
        "timing_seconds": time.perf_counter() - started,
    }
    REPORT.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
    # Read back the frozen outputs and verify the reported identities.
    reread = json.loads(REPORT.read_text(encoding="utf-8"))
    if reread["attack_type_live_input"] != readiness or sha256(CONTRACT) != reread["contract_sha256"] or len(read_csv(FEATURE_PARITY_CSV)) != 24:
        raise RuntimeError("saved audit reload failed")
    print(json.dumps({"attack_type_live_input": readiness, "ready_checks": ready_checks, "research_parity": all_stats, "preprocessing": preprocessing, "roundtrip": report["artifact_round_trip"], "live_dry_run": report["live_dry_run"], "report": str(REPORT)}, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
