#!/usr/bin/env python3
"""Read-only CC30C-4O recovery-strategy audit.

Only repository evidence, append-only operational logs, and prior SELECT-only
audit output are read.  Output is limited to analysis/output; the Window,
lifecycle, database, cron, Candidate, collector, and formal evidence are never
written.
"""

from __future__ import annotations

import csv
import hashlib
import json
import subprocess
from datetime import datetime
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parent.parent
OUT = REPO / "analysis" / "output"
WINDOW_ID = "successor-v3-prepared-20261015084342-8f2a97e7"
WINDOW = REPO / "var" / "strict_forward_live_readiness" / "windows" / WINDOW_ID
DECLARED = "31aeae482187c5c901ec1a4bd7815a4b969bf155e3950d007f8b9d274a238dd7"
INTERMEDIATE = "33dfa9c1d1e5ed527130c06d2da661a54192422b20dcebe0dcf8508a06158e32"
CURRENT = "31fb39f6c457d234143ff9f2b0c652a3231b134b11bc0ffc1bc32a266a571cfc"
MISSING = [f"202610{day}SMS{race:02d}" for day in range(16, 20) for race in (11, 12)]

REPORT = OUT / "cc30c_4o_recovery_strategy_report.json"
PROVENANCE_CSV = OUT / "cc30c_4o_provenance_4file_diff.csv"
REPAIR_CSV = OUT / "cc30c_4o_historical_repair_feasibility.csv"
PLAN = OUT / "cc30c_4o_clean_window_plan.json"


def load(path: Path) -> dict[str, Any]:
    value = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(value, dict):
        raise RuntimeError(f"JSON_OBJECT_REQUIRED:{path}")
    return value


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def canonical(value: Any) -> str:
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))


def tree_identity(root: Path) -> tuple[int, str]:
    items: list[tuple[str, str]] = []
    for path in sorted(p for p in root.rglob("*") if p.is_file()):
        items.append((str(path.relative_to(root)), sha(path)))
    framed = "".join(f"{name}\0{digest}\n" for name, digest in items)
    return len(items), "sha256:" + hashlib.sha256(framed.encode()).hexdigest()


def stat_fields(path: Path) -> tuple[str, str, int]:
    raw = subprocess.check_output(
        ["stat", "-c", "%w|%y|%s", str(path)], text=True
    ).strip()
    birth, mtime, size = raw.split("|", 2)
    return birth, mtime, int(size)


def manifest_map(identity: str) -> dict[str, str]:
    path = WINDOW / "provenance" / "implementation_manifests" / f"{identity}.json"
    return {str(row["path"]): str(row["sha256"]) for row in load(path)["files"]}


def changed(a: str, b: str) -> list[dict[str, str]]:
    left, right = manifest_map(a), manifest_map(b)
    return [
        {"path": path, "from_sha256": left.get(path, ""), "to_sha256": right.get(path, "")}
        for path in sorted(set(left) | set(right))
        if left.get(path) != right.get(path)
    ]


def log_rows() -> dict[str, list[dict[str, Any]]]:
    rows = {race: [] for race in MISSING}
    path = REPO / "logs" / "strict_forward_exhibition_collector.log"
    for line in path.read_text(encoding="utf-8", errors="replace").splitlines():
        try:
            row = json.loads(line)
        except ValueError:
            continue
        race = str(row.get("race_code") or "")
        if race in rows:
            rows[race].append(row)
    return rows


def last_observation(race: str) -> dict[str, Any]:
    date = race[:8]
    path = WINDOW / "opportunity" / "observations" / f"{date}.jsonl"
    found: dict[str, Any] = {}
    for line in path.read_text(encoding="utf-8", errors="replace").splitlines():
        try:
            row = json.loads(line)
        except ValueError:
            continue
        if str(row.get("race_code") or "") == race:
            found = row
    return found


def candidate(race: str) -> tuple[Path, dict[str, Any]]:
    paths = sorted((WINDOW / "opportunity" / "candidates" / race[:8]).glob("*.json"))
    for path in paths:
        row = load(path)
        if str(row.get("race_code") or "") == race:
            return path, row
    raise RuntimeError(f"CANDIDATE_MISSING:{race}")


def prior_db_trace() -> dict[str, Any]:
    report = load(OUT / "cc30c_4o_contract_resolution_report.json")
    return {str(row["race_code"]): row for row in report["missing_eight"]}


def write_json(path: Path, value: dict[str, Any]) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    if load(path) != value:
        raise RuntimeError(f"READBACK_MISMATCH:{path}")


def main() -> None:
    OUT.mkdir(parents=True, exist_ok=True)
    before_count, before_tree_sha = tree_identity(WINDOW)
    generated_at = datetime.now().astimezone().isoformat()
    commit = subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=REPO, text=True).strip()

    declaration = load(WINDOW / "declared_implementation_identity.json")
    activation = load(WINDOW / "activation_record.json")
    manifest = load(WINDOW / "validation_window_manifest.json")
    if declaration["implementation_identity"] != f"sha256:{DECLARED}":
        raise RuntimeError("DECLARED_IDENTITY_CHANGED")
    if activation["implementation_identity"] != f"sha256:{DECLARED}":
        raise RuntimeError("ACTIVATION_IDENTITY_CHANGED")

    four: list[dict[str, Any]] = []
    with PROVENANCE_CSV.open("w", encoding="utf-8", newline="") as handle:
        fields = ["path", "filename", "birth_time", "mtime", "size", "sha256", "schema", "implementation_identity", "producer", "generation_reason", "formal_aggregate_input", "window_identity_input", "evidence", "classification"]
        writer = csv.DictWriter(handle, fieldnames=fields)
        writer.writeheader()
        for identity in (INTERMEDIATE, CURRENT):
            for directory in ("implementation_manifests", "implementation_sources"):
                path = WINDOW / "provenance" / directory / f"{identity}.json"
                row = load(path)
                birth, mtime, size = stat_fields(path)
                item = {
                    "path": str(path.relative_to(REPO)),
                    "filename": path.name,
                    "birth_time": birth,
                    "mtime": mtime,
                    "size": size,
                    "sha256": sha(path),
                    "schema": row.get("schema_version"),
                    "implementation_identity": row.get("implementation_identity"),
                    "producer": "MeasurementImplementationProvenance::freeze via WindowDeclaredImplementationIdentity::assertCurrent",
                    "generation_reason": "Current source was frozen before immutable declared-vs-current identity comparison returned DECLARED_IMPLEMENTATION_IDENTITY_MISMATCH.",
                    "formal_aggregate_input": False,
                    "window_identity_input": False,
                    "evidence": False,
                    "classification": "UNREFERENCED_AUXILIARY_PROVENANCE_METADATA",
                }
                four.append(item)
                writer.writerow({key: item[key] for key in fields})

    logs = log_rows()
    db = prior_db_trace()
    repair_rows: list[dict[str, Any]] = []
    repair_fields = [
        "race_code", "candidate_path", "candidate_sha256", "universe_recorded_at", "deadline",
        "last_formal_observation_at", "last_formal_observation_status", "first_collector_complete_at",
        "first_collector_complete_status", "collector_complete_before_cutoff", "collector_log_sha256",
        "db_created_at_corroboration", "race_entry_current_rows", "race_entry_asof_proven",
        "result_reference", "classification", "classification_reason",
    ]
    collector_log_sha = sha(REPO / "logs" / "strict_forward_exhibition_collector.log")
    with REPAIR_CSV.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=repair_fields)
        writer.writeheader()
        for race in MISSING:
            cpath, crow = candidate(race)
            observation = last_observation(race)
            complete = next((row for row in logs[race] if int(row.get("complete_boats") or 0) == 6), {})
            deadline = str(crow.get("cutoff_timestamp") or "")
            complete_at = str(complete.get("attempt_finished_at") or complete.get("timestamp") or "")
            current_trace = db[race]
            item = {
                "race_code": race,
                "candidate_path": str(cpath.relative_to(REPO)),
                "candidate_sha256": sha(cpath),
                "universe_recorded_at": crow.get("universe_recorded_at", ""),
                "deadline": deadline,
                "last_formal_observation_at": observation.get("observed_at", ""),
                "last_formal_observation_status": observation.get("eligibility_status", ""),
                "first_collector_complete_at": complete_at,
                "first_collector_complete_status": complete.get("status", ""),
                "collector_complete_before_cutoff": bool(complete_at and deadline and complete_at < deadline),
                "collector_log_sha256": collector_log_sha,
                "db_created_at_corroboration": current_trace["exhibition"].get("last_created_at_jst", ""),
                "race_entry_current_rows": current_trace["race_entry"].get("row_count", 0),
                "race_entry_asof_proven": False,
                "result_reference": "NONE",
                "classification": "PARTIALLY_RECONSTRUCTIBLE",
                "classification_reason": "Candidate, cutoff, 21:58 incomplete observation and a pre-cutoff collector-complete event exist. However race_entry has no source timestamp, collector JSONL is outside the immutable Window, and exhibition_live is mutable/upserted; an exact immutable as-of payload and a natural eligibility-producer observation were not persisted.",
            }
            if not item["collector_complete_before_cutoff"]:
                raise RuntimeError(f"PRE_CUTOFF_COLLECTOR_COMPLETION_NOT_FOUND:{race}")
            repair_rows.append(item)
            writer.writerow(item)

    critical = manifest_map(CURRENT)
    late_paths = [
        "analysis/finalize_strict_forward_late_eligibility.php",
        "web/logic/StrictForwardExhibitionEligibilityState.php",
        "analysis/run_strict_forward_late_eligibility_cron.sh",
    ]
    plan = {
        "schema_version": "cc30c-4o-clean-window-plan/v1",
        "status": "PROPOSED_NOT_APPLIED",
        "generated_at": generated_at,
        "source_git_commit": commit,
        "strategy": "FREEZE_CURRENT_AND_START_CLEAN_WINDOW",
        "contract_gate": "REQUIRES_CONTRACT",
        "why_contract_is_required": "The effective Window lifecycle has PREPARED/ACTIVE/CLOSED/ABORTED/INVALID only. Existing ABORT reasons do not include this producer-defect/technical-incomplete case; a normal closure is forbidden while eight terminal resolutions are absent.",
        "steps": [
            {"step": 1, "action": "Approve an external create-only technical-incomplete/abort contract for this active Window; preserve its bytes and mark its canonical evaluation disposition without calling it normally CLOSED."},
            {"step": 2, "action": "Hold scheduler/capture and use the Official Epoch incident path; classify bounded affected evidence and decide same-Epoch eligibility under the frozen incident policy."},
            {"step": 3, "action": "Freeze a pristine new Window with a new ID and an implementation identity that includes the late producer, shared predicate, multi-day aggregate v2, and all transitive measurement dependencies."},
            {"step": 4, "action": "Create and validate the new Window binding, declaration, activation, and runtime authorization using the existing same-Epoch successor requirements; use a new Epoch only if semantics are classified breaking."},
            {"step": 5, "action": "Install the approved DB-only late finalizer cadence only after the new identity is declared/activated; retain normal scheduler/collector cadence and external-fetch load."},
            {"step": 6, "action": "Start future-only capture with no copy/migration of old evidence; collect natural data."},
            {"step": 7, "action": "Run FormalEvidenceAggregate v2 read-only audit and require exact Expected/Candidate/Eligibility keysets."},
        ],
        "required_identity_dependencies": late_paths,
        "current_identity_dependency_coverage": {path: path in critical for path in late_paths},
        "current_window_evidence_disposition": {
            "toda_216": "RETAIN_DIAGNOSTIC_NATURAL_PRE_RECOVERY_WINDOW_EVIDENCE",
            "copy_to_new_window": False,
            "migrate_to_new_window": False,
        },
    }
    write_json(PLAN, plan)

    current_source_changes = changed(DECLARED, CURRENT)
    intermediate_changes = changed(DECLARED, INTERMEDIATE)
    second_changes = changed(INTERMEDIATE, CURRENT)
    report = {
        "schema_version": "cc30c-4o-recovery-strategy-report/v1",
        "generated_at": generated_at,
        "source_git_commit": commit,
        "mode": "READ_ONLY_STRATEGY_AUDIT",
        "window_id": WINDOW_ID,
        "state_mutation": "NONE",
        "final_determinations": {
            "PROVENANCE_4_FILE_DIFF": "EXPLAINED",
            "RAW_NATURAL_EVIDENCE_PRESERVED": "YES",
            "FORMAL_EVIDENCE_PRESERVED": "YES",
            "WINDOW_PROVENANCE_SET_PRESERVED": "NO",
            "WINDOW_IDENTITY_PRESERVED": "YES",
            "HISTORICAL_REPAIR_RECONSTRUCTIBILITY": "PARTIAL",
            "CLEAN_WINDOW_ROLLOVER": "REQUIRES_CONTRACT",
            "RECOMMENDED_RECOVERY_STRATEGY": "FREEZE_CURRENT_AND_START_CLEAN_WINDOW",
            "CC30C_4O_STATUS": "BLOCKED",
        },
        "provenance_four_files": {
            "count": len(four),
            "files": four,
            "csv": str(PROVENANCE_CSV.relative_to(REPO)),
            "producer_attribution": {
                "code_path": "OfficialEpochWindowBindingRuntimeAuthorization -> FormalMeasurementWindowContext::authorizeMeasurementWrite -> WindowActivationHold::authorizeFormalWrite -> WindowDeclaredImplementationIdentity::assertCurrent -> MeasurementImplementationProvenance::freeze",
                "behavior": "freeze writes the current manifest/source pair before assertCurrent compares it with the immutable declaration",
                "os_process_or_cli_invoker_persisted": False,
            },
            "identity_transitions": [
                {"from": f"sha256:{DECLARED}", "to": f"sha256:{INTERMEDIATE}", "changed_files": intermediate_changes},
                {"from": f"sha256:{INTERMEDIATE}", "to": f"sha256:{CURRENT}", "changed_files": second_changes},
            ],
            "unreferenced_identity_occurrences": "Each added identity occurs only inside its own manifest/source pair; no Candidate, eligibility, ledger, declaration, activation, or canonical evidence references it.",
            "aggregate_contract": "FormalEvidenceAggregate adds only reachable provenance and reports other implementation_manifests/implementation_sources as excluded_auxiliary_provenance.",
        },
        "evidence_preservation": {
            "toda_raw_record_count": 216,
            "toda_raw_framed_sha256": "sha256:3964a998019aae8aeceddfa8a8a3639c0690782e1150ebe11f32408025d8672b",
            "canonical_membership_sha256": "sha256:922330099a0e4d9620497b64d9afca14d012e0a9760e7bd318170a0b2c16673e",
            "physical_window_tree_before_structural_audit": {"file_count": 15081, "framed_sha256": "sha256:b8353e38d78b67bcb48579854000492473a5f6907de5f5a4d712574d02938bc2"},
            "physical_window_tree_after_auxiliary_freezes": {"file_count": before_count, "framed_sha256": before_tree_sha},
            "declared_identity": declaration["implementation_identity"],
            "activation_identity": activation["implementation_identity"],
            "current_source_identity_read_only": f"sha256:{CURRENT}",
            "declared_activation_identity_unchanged": True,
            "runtime_current_identity_matches_declaration": False,
            "interpretation": "Raw/canonical evidence and immutable declaration/activation identity are preserved. The physical provenance set is not preserved because four unreferenced auxiliary files were added; they do not enter the formal aggregate.",
        },
        "current_window_lifecycle": {
            "manifest_status": manifest.get("status"),
            "effective_state": "ACTIVE",
            "window_effective_states_in_contract": ["PREPARED", "ACTIVE", "CLOSED", "ABORTED", "INVALID"],
            "not_window_effective_states": ["FAILED", "BLOCKED", "INVALID_TECHNICAL", "SUPERSEDED"],
            "superseded_scope": "Prepared supersession exists only for pristine PREPARED recovery windows, not this evidence-bearing ACTIVE Window.",
            "normal_close_possible": False,
            "normal_close_blockers": ["MISSING_TERMINAL_RESOLUTION=8", "EXPECTED/CANDIDATE/ELIGIBILITY_KEYSET_MISMATCH"],
            "existing_abort_reason_supports_this_defect": False,
            "epoch_state_read_only": "ACTIVE",
            "same_epoch_clean_successor_framework_exists": True,
            "rollover_conclusion": "The successor/binding/recovery framework exists, but the active Window lacks an approved technical-incomplete terminal contract. Therefore rollover is not executable under the current exact contract without a small lifecycle contract addition/approval.",
        },
        "implementation_identity": {
            "declared": f"sha256:{DECLARED}",
            "current": f"sha256:{CURRENT}",
            "mismatch": True,
            "declared_to_current_changed_critical_files": current_source_changes,
            "new_late_producer_in_critical_paths": "analysis/finalize_strict_forward_late_eligibility.php" in critical,
            "shared_predicate_in_critical_paths": "web/logic/StrictForwardExhibitionEligibilityState.php" in critical,
            "required_future_action_not_executed": "Add all transitive producer/predicate/schedule contract dependencies to the critical set, freeze the finished source once, declare a new Window, bind/activate it, then require runtime identity equality.",
        },
        "late_night_future_coverage": {
            "fixture_result": "PASS",
            "fixture_command": "php analysis/test_cc30c_4o_structural_fixes.php",
            "fixture_assertions": ["22:45 incomplete remains provisional", "22:39 complete creates one ELIGIBLE_OBSERVED resolution before cutoff", "historical normal observation is not repaired", "daytime behavior unchanged", "multi-day union deterministic/fail-closed"],
            "recommended_cadence": "Every 2 minutes from 22:00 through 23:59 JST, DB-only",
            "external_fetch_added": False,
            "candidate_discovery_added": False,
            "load_assessment": "Low: unresolved frozen Candidates only, local exhibition completeness query; normal scheduler and collector retain their cadence.",
            "production_ready_under_current_identity": False,
        },
        "historical_repair": {
            "overall": "PARTIAL",
            "csv": str(REPAIR_CSV.relative_to(REPO)),
            "race_count": len(repair_rows),
            "class_counts": {"EXACT_PRE_CUTOFF_RECONSTRUCTIBLE": 0, "PARTIALLY_RECONSTRUCTIBLE": len(repair_rows), "NOT_RECONSTRUCTIBLE": 0},
            "rows": repair_rows,
            "why_not_exact": [
                "race_entry has no source/as-of timestamp columns, so its six-row current state cannot prove the exact pre-cutoff entry snapshot",
                "exhibition_live is an upserted mutable table; created_date is reset on update and current rows are corroboration, not an immutable payload archive",
                "the collector JSONL records a pre-cutoff six-boat completion but is outside the immutable Window and does not contain the six exact source rows",
                "no natural eligibility-producer observation exists after 21:58; reconstructing first_eligible_observed_at would be a post-hoc rule",
            ],
            "result_data_used": False,
            "repair_semantics_if_ever_approved": "HISTORICAL_RECONSTRUCTION or REPAIRED_FORWARD; never LIVE_STRICT and never identical to natural real-time evidence.",
            "policy": "Official incident policy forbids backfill as official evidence.",
        },
        "strategy_comparison": {
            "option_a": {
                "name": "HISTORICAL_REPAIR_CURRENT_WINDOW",
                "future_only_integrity": "FAIL_FOR_LIVE_STRICT",
                "reproducibility": "PARTIAL",
                "auditability": "Requires a new repair schema/sample_kind and explicit provenance",
                "manual_reconstruction_risk": "HIGH",
                "contract_complexity": "HIGH",
                "production_explainability": "WEAK: mixes natural live observations with later reconstructed terminal rows",
                "recommended": False,
            },
            "option_b": {
                "name": "FREEZE_CURRENT_AND_START_CLEAN_WINDOW",
                "future_only_integrity": "STRONG",
                "reproducibility": "STRONG_AFTER_NEW_IDENTITY_FREEZE",
                "auditability": "CLEAR PRE/POST RECOVERY BOUNDARY",
                "manual_reconstruction_risk": "NONE FOR NEW WINDOW",
                "contract_complexity": "MEDIUM: one approved technical-incomplete terminal path plus existing recovery successor flow",
                "production_explainability": "STRONG",
                "cost": "Current Window is diagnostic/pre-recovery only and new natural collection time is required",
                "recommended": True,
            },
        },
        "current_toda_evidence_disposition": {
            "delete_or_invalidate": False,
            "retain_as": ["DIAGNOSTIC_NATURAL_EVIDENCE", "PRE_RECOVERY_WINDOW_EVIDENCE"],
            "copy_or_migrate_to_clean_window": False,
            "eligible_for_clean_window_formal_evaluation": False,
        },
        "recommended_plan": str(PLAN.relative_to(REPO)),
        "prohibitions_confirmed": {
            "historical_repair": False,
            "eligibility_generated": False,
            "window_or_epoch_or_binding_or_activation_changed": False,
            "runtime_authorization_invoked": False,
            "cron_changed": False,
            "candidate_or_collector_or_formal_evidence_generated": False,
        },
    }
    write_json(REPORT, report)

    after_count, after_tree_sha = tree_identity(WINDOW)
    if (after_count, after_tree_sha) != (before_count, before_tree_sha):
        raise RuntimeError("WINDOW_CHANGED_DURING_READ_ONLY_AUDIT")
    # Confirm report read-back and internally declared output paths.
    if load(REPORT)["final_determinations"] != report["final_determinations"]:
        raise RuntimeError("REPORT_FINAL_DETERMINATIONS_MISMATCH")
    print(json.dumps({
        "status": "PASS",
        "report": str(REPORT.relative_to(REPO)),
        "provenance_csv": str(PROVENANCE_CSV.relative_to(REPO)),
        "repair_csv": str(REPAIR_CSV.relative_to(REPO)),
        "plan": str(PLAN.relative_to(REPO)),
        "window_file_count": after_count,
        "window_sha256": after_tree_sha,
        "recommendation": report["final_determinations"]["RECOMMENDED_RECOVERY_STRATEGY"],
    }, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
