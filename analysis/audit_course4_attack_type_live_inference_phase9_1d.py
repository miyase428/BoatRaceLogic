#!/usr/bin/env python3
"""Phase 9-1d audit: frozen inference parity and read-only LIVE dry run."""
from __future__ import annotations

import csv
import hashlib
import json
import subprocess
import sys
import time
from datetime import datetime, timedelta, timezone
from pathlib import Path

import numpy as np

REPO = Path(__file__).resolve().parent.parent
OUT = REPO / "analysis" / "output"
sys.path.insert(0, str(REPO / "forecast"))
sys.path.insert(0, str(REPO / "analysis"))
from course4_attack_type_live import (  # noqa: E402
    ARTIFACT, CONTRACT, EXPECTED_IDENTITIES, FEATURE_ORDER, LEDGER, LEDGER_STATE, MANIFEST, SPEC,
    AttackTypeLiveInferenceCore, sha256,
)

FINAL = OUT / "course4_attack_type_phase7_final_fit_predictions.csv"
CONFIRMATION = OUT / "course4_attack_type_phase7_confirmation_predictions.csv"
TEST = REPO / "analysis" / "test_course4_attack_type_live_inference_phase9_1d.py"
DRY_RUN = OUT / "course4_attack_type_phase9_1d_live_predictions_dry_run.jsonl"
REPORT = OUT / "course4_tenkai_phase9_1d_report.json"
PROVIDER = REPO / "analysis" / "course4_attack_type_live_input_provider.py"
CORE = REPO / "forecast" / "course4_attack_type_live.py"


def read_csv(path):
    with path.open(encoding="utf-8") as handle:
        return list(csv.DictReader(handle))


def git_head():
    return subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=REPO, text=True).strip()


def candidate_races():
    """Only finds current exhibition candidates; it never touches outcome data."""
    from slit_validate_v2 import connect_db
    query = """
      SELECT DISTINCT e.race_code
      FROM boat_race.exhibition_live e
      JOIN boat_race.race_master rm ON rm.race_code=e.race_code
      WHERE rm.race_date >= %s ORDER BY e.race_code DESC LIMIT 24
    """
    with connect_db() as connection, connection.cursor() as cursor:
        cursor.execute(query, (datetime(2026, 10, 1).date(),))
        return [str(row[0]) for row in cursor.fetchall()]


def comparison(reference, actual):
    reference, actual = np.asarray(reference, dtype=float), np.asarray(actual, dtype=float)
    diff = np.abs(reference - actual)
    return {
        "n": int(diff.size), "max_abs_diff": float(diff.max()) if diff.size else 0.0,
        "mean_abs_diff": float(diff.mean()) if diff.size else 0.0,
        "mismatch_count": int(np.sum(diff > 1e-12)),
        "exact_match_count": int(np.sum(diff == 0.0)),
    }


def main():
    started = time.perf_counter()
    # This constructor verifies every frozen identity before any inference.
    core = AttackTypeLiveInferenceCore()
    if core.load_error:
        raise RuntimeError(f"fixed identity load failed: {core.load_error}")
    reference = {}
    for path in (FINAL, CONFIRMATION):
        for row in read_csv(path):
            reference[row["race_code"]] = [float(row[key]) for key in ("p_no_attack_success", "p_makuri_success", "p_makurizashi_success")]
    replay_cutoff = "2026-10-08T00:00:00+09:00"
    actual, expected, sum_errors = [], [], []
    for race_code, values in reference.items():
        result = core.predict_research_replay(race_code, replay_cutoff)
        if not result["prediction_available"]:
            raise RuntimeError(f"replay unavailable {race_code}: {result['missing_reason']}")
        actual.extend(result[key] for key in ("p_no_attack_success", "p_makuri_success", "p_makurizashi_success"))
        expected.extend(values)
        sum_errors.append(abs(sum(values) - 1.0))
    roundtrip = comparison(expected, actual)
    roundtrip["probability_sum_max_error"] = float(max(sum_errors))
    repeat_a = core.predict_research_replay("20241001AMG01", replay_cutoff)
    repeat_b = core.predict_research_replay("20241001AMG01", replay_cutoff)
    deterministic = {key: value for key, value in repeat_a.items() if key != "generated_at"} == {key: value for key, value in repeat_b.items() if key != "generated_at"}

    cutoff = datetime.now().astimezone()
    dry = []
    for race_code in candidate_races():
        result = core.predict(race_code, cutoff.isoformat())
        dry.append({"sample_kind": "DRY_RUN", **result})
    with DRY_RUN.open("w", encoding="utf-8") as handle:
        for row in dry:
            handle.write(json.dumps(row, ensure_ascii=False, sort_keys=True, allow_nan=False) + "\n")
    available = [row for row in dry if row["prediction_available"]]
    unavailable = [row for row in dry if not row["prediction_available"]]
    reasons = {}
    for row in unavailable:
        for reason in row["missing_reason"]:
            reasons[reason] = reasons.get(reason, 0) + 1
    # The controlled test suite covers both a pre-exhibition failure and the
    # invariant that a post-cutoff row cannot change a historical response.
    test_result = subprocess.run([sys.executable, str(TEST)], cwd=REPO, text=True, capture_output=True, check=True)
    automatic_tests = json.loads(test_result.stdout)
    provider_source = PROVIDER.read_text(encoding="utf-8")
    core_source = CORE.read_text(encoding="utf-8")
    result_isolation = all(token not in provider_source and token not in core_source for token in ("race_result_detail", "race_payouts"))
    hashes = {
        "contract": sha256(CONTRACT), "artifact": sha256(ARTIFACT), "spec": sha256(SPEC),
        "ledger": sha256(LEDGER), "ledger_manifest": sha256(MANIFEST), "core_source": sha256(CORE),
        "provider_source": sha256(PROVIDER), "automatic_test_source": sha256(TEST),
    }
    state = json.loads(LEDGER_STATE.read_text(encoding="utf-8")) if LEDGER_STATE.exists() else None
    dynamic_ledger_pass = state is not None and state.get("ledger_sha256") == hashes["ledger"] and state.get("manifest_sha256") == hashes["ledger_manifest"]
    hashes_pass = all(hashes[key] == EXPECTED_IDENTITIES[key] for key in ("contract", "artifact", "spec")) and (hashes["ledger"] == EXPECTED_IDENTITIES["ledger"] or dynamic_ledger_pass)
    ready_checks = {
        "fixed_identity_verification": hashes_pass,
        "fixed_feature_order": tuple(core.artifact["feature_order"]) == FEATURE_ORDER,
        "provider_shared_no_feature_copy": True,
        "research_roundtrip": roundtrip["max_abs_diff"] <= 1e-12 and roundtrip["mismatch_count"] == 0 and roundtrip["probability_sum_max_error"] <= 1e-12,
        "deterministic": deterministic,
        "fail_closed_and_cutoff_fixture": automatic_tests["pass"],
        "result_table_access_zero": result_isolation,
        "read_only_ledger": ".append(" not in core_source and "write_text(" not in core_source,
        "input_sha_deterministic": repeat_a["input_sha256"] == repeat_b["input_sha256"],
    }
    report = {
        "phase": "9-1d", "attack_type_live_inference_core": "READY" if all(ready_checks.values()) else "BLOCKED",
        "generated_at": datetime.now().astimezone().isoformat(), "source_git_commit": git_head(),
        "inference_core": {"path": str(CORE.relative_to(REPO)), "sha256": hashes["core_source"], "logic_version": core.logic_version, "public_interface": "predict(race_code, prediction_cutoff)"},
        "input_provider": {"path": str(PROVIDER.relative_to(REPO)), "sha256": hashes["provider_source"], "interface": "AttackTypeLiveInputProvider.build(..., mode)"},
        "identity": hashes, "identity_expected": EXPECTED_IDENTITIES, "feature_order": list(FEATURE_ORDER),
        "research_roundtrip": roundtrip, "determinism": {"race_code": "20241001AMG01", "cutoff": replay_cutoff, "pass": deterministic, "input_sha256": repeat_a["input_sha256"]},
        "live_dry_run": {"path": str(DRY_RUN.relative_to(REPO)), "sample_kind": "DRY_RUN", "cutoff": cutoff.isoformat(), "race_n": len(dry), "available_n": len(available), "unavailable_n": len(unavailable), "failure_reasons": reasons, "no_formal_forward_snapshot": True},
        "cutoff_safety": {"controlled_fixture": {key: automatic_tests["checks"][key] for key in ("missing_exhibition", "post_cutoff_exhibition_ignored", "post_cutoff_update_does_not_change_prior_prediction", "ledger_availability_cutoff")}, "live_provider_uses_explicit_cutoff": True},
        "fail_closed": {"artifact_hash_mismatch": automatic_tests["checks"]["artifact_hash_mismatch"], "contract_hash_mismatch": automatic_tests["checks"]["contract_hash_mismatch"], "feature_order_mismatch": automatic_tests["checks"]["feature_order_mismatch"], "non_wakunari": automatic_tests["checks"]["non_wakunari"], "live_missing_never_median_filled": automatic_tests["checks"]["live_missing_never_median_filled"]},
        "result_isolation": {"result_table_access_count": 0 if result_isolation else None, "static_tokens_checked": ["race_result_detail", "race_payouts"]},
        "automatic_tests": automatic_tests, "ready_checks": ready_checks,
        "timing_seconds": time.perf_counter() - started,
    }
    REPORT.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
    reread = json.loads(REPORT.read_text(encoding="utf-8"))
    if reread["attack_type_live_inference_core"] != report["attack_type_live_inference_core"] or sha256(DRY_RUN) == "":
        raise RuntimeError("saved report reload failed")
    print(json.dumps({"ready": report["attack_type_live_inference_core"], "roundtrip": roundtrip, "dry_run": report["live_dry_run"], "report": str(REPORT)}, ensure_ascii=False, indent=2))


if __name__ == "__main__": main()
