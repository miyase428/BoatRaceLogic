#!/usr/bin/env python3
"""Build the read-only CC30C technical-incomplete Window contract proposal.

The only repository writes are the four requested analysis/output artifacts.
The production Window, lifecycle, Epoch, binding, activation and runtime
authorization are never written.
"""

from __future__ import annotations

import hashlib
import json
import subprocess
from datetime import datetime
from pathlib import Path
from zoneinfo import ZoneInfo


REPO = Path(__file__).resolve().parents[1]
OUTPUT = REPO / "analysis" / "output"
RECOVERY_REPORT = OUTPUT / "cc30c_4o_recovery_strategy_report.json"
POINTER = REPO / "var/strict_forward_live_readiness/formal_measurement_window_context.json"
LIFECYCLE = REPO / "web/logic/FormalMeasurementWindowLifecycle.php"
IDENTITY = REPO / "web/logic/WindowDeclaredImplementationIdentity.php"
PROVENANCE = REPO / "web/logic/MeasurementImplementationProvenance.php"
INCIDENT_POLICY = REPO / "web/logic/OfficialEpochIncidentPolicyV1.php"
BINDING = REPO / "web/logic/OfficialEpochWindowBindingV1.php"
FIXTURE = REPO / "analysis/test_cc30c_technical_incomplete_contract_v1.php"

AFFECTED = [
    "20261016SMS11", "20261016SMS12", "20261017SMS11", "20261017SMS12",
    "20261018SMS11", "20261018SMS12", "20261019SMS11", "20261019SMS12",
]


def sha(path: Path, prefix: bool = True) -> str:
    value = hashlib.sha256(path.read_bytes()).hexdigest()
    return f"sha256:{value}" if prefix else value


def load(path: Path) -> dict:
    value = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(value, dict):
        raise RuntimeError(f"object_required:{path}")
    return value


def dump(path: Path, value: dict) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def canonical(value: object) -> bytes:
    return json.dumps(value, ensure_ascii=False, separators=(",", ":"), sort_keys=True).encode("utf-8")


def tree_identity(root: Path) -> dict:
    rows: list[bytes] = []
    for path in sorted((p for p in root.rglob("*") if p.is_file()), key=lambda p: p.as_posix()):
        rel = path.relative_to(root).as_posix().encode("utf-8")
        rows.append(rel + b"\0" + hashlib.sha256(path.read_bytes()).hexdigest().encode("ascii"))
    return {"file_count": len(rows), "framed_sha256": "sha256:" + hashlib.sha256(b"\n".join(rows)).hexdigest()}


def git(*args: str) -> str:
    return subprocess.run(["git", *args], cwd=REPO, check=True, capture_output=True, text=True).stdout.strip()


def main() -> None:
    OUTPUT.mkdir(parents=True, exist_ok=True)
    generated_at = datetime.now(ZoneInfo("Asia/Tokyo")).isoformat()
    commit = git("rev-parse", "HEAD")
    recovery = load(RECOVERY_REPORT)
    pointer = load(POINTER)
    window_root = REPO / str(pointer["window_root"])
    manifest = load(window_root / "validation_window_manifest.json")
    before_tree = tree_identity(window_root)

    lifecycle_source = LIFECYCLE.read_text(encoding="utf-8")
    identity_source = IDENTITY.read_text(encoding="utf-8")
    provenance_source = PROVENANCE.read_text(encoding="utf-8")
    incident_source = INCIDENT_POLICY.read_text(encoding="utf-8")
    required_source_facts = {
        "effective_states_include_aborted": "'effective_state' => 'ABORTED'" in lifecycle_source,
        "effective_states_include_closed": "'effective_state' => 'CLOSED'" in lifecycle_source,
        "invalid_is_fallback": "return ['effective_state' => 'INVALID'" in lifecycle_source,
        "abort_v1_requires_official_epoch_no": "($row['official_epoch'] ?? '') === 'NO'" in lifecycle_source,
        "normal_handoff_accepts_aborted_predecessor": "old_terminal_state" in lifecycle_source and "'ABORTED'" in lifecycle_source,
        "assert_current_is_read_only": "function assertCurrentReadOnly()" in identity_source
        and "$currentIdentity = $this->provenance->currentIdentityReadOnly();" in identity_source,
        "read_only_identity_api_exists": "function currentIdentityReadOnly()" in provenance_source,
        "major_policy_aborts_active_window": "'active_window' => 'ABORT'" in incident_source,
        "same_epoch_recovery_supported": "'same_epoch_recovery' => true" in incident_source,
    }
    if not all(required_source_facts.values()):
        raise RuntimeError("source_semantics_changed:" + ",".join(k for k, v in required_source_facts.items() if not v))

    abort_path = REPO / "var/strict_forward_live_readiness/lifecycle/aborts" / f"{pointer['window_id']}.json"
    closure_path = REPO / "var/strict_forward_live_readiness/lifecycle/closures" / f"{pointer['window_id']}.json"
    if abort_path.exists() or closure_path.exists():
        raise RuntimeError("real_window_already_terminal")
    if manifest.get("activation_state") != "ACTIVE" or manifest.get("status") != "ACTIVE":
        raise RuntimeError("real_window_not_active")

    fixture_run = subprocess.run(
        ["php", str(FIXTURE.relative_to(REPO))], cwd=REPO, check=True, capture_output=True, text=True
    )
    fixture_result = json.loads(fixture_run.stdout)
    if fixture_result.get("status") != "PASS" or fixture_result.get("production_window_accessed") is not False:
        raise RuntimeError("isolated_fixture_failed")
    after_tree = tree_identity(window_root)
    if before_tree != after_tree:
        raise RuntimeError("production_window_changed_during_read_only_audit")

    input_hashes = {
        "recovery_strategy_report": sha(RECOVERY_REPORT),
        "formal_measurement_window_lifecycle": sha(LIFECYCLE),
        "window_declared_implementation_identity": sha(IDENTITY),
        "measurement_implementation_provenance": sha(PROVENANCE),
        "official_epoch_incident_policy": sha(INCIDENT_POLICY),
        "official_epoch_window_binding_contract": sha(BINDING),
        "production_pointer": sha(POINTER),
        "production_window_manifest": sha(window_root / "validation_window_manifest.json"),
        "fixture_test": sha(FIXTURE),
    }

    lifecycle_semantics = {
        "CLOSED": {
            "meaning": "Normal successful terminal declaration after all gates pass and unresolved_count is zero.",
            "evidence_retained": True,
            "formal_evaluation_eligible": True,
            "successor_handoff_supported": True,
            "suitable_for_current_defect": False,
            "reason": "The current Window has eight unresolved eligibility records and cannot satisfy normal closure gates.",
        },
        "ABORTED": {
            "meaning": "Create-only external terminal declaration; the Window manifest and evidence remain byte-preserved and future formal writes are blocked.",
            "evidence_retained": True,
            "formal_evaluation_eligible": False,
            "diagnostic_read_allowed": True,
            "successor_handoff_supported": True,
            "suitable_for_current_defect": True,
            "implementation_gap": "abort/v1 only accepts three legacy reasons and official_epoch=NO; abort/v2 support is required before execution.",
        },
        "INVALID": {
            "meaning": "Computed fail-closed fallback for malformed or unverifiable manifest/lifecycle state; not a persisted, reasoned terminal declaration.",
            "evidence_retained": "NOT_GOVERNED_BY_STATE",
            "formal_evaluation_eligible": False,
            "successor_handoff_supported": False,
            "suitable_for_current_defect": False,
            "reason": "Using INVALID would misclassify genuine evidence and provides no valid terminal record for pointer handoff.",
        },
    }

    retention = {
        "status": "READY",
        "physical_mutation": "PROHIBITED",
        "preserve_in_place": [
            "raw ledger", "Candidate", "existing eligibility rows", "Expected inventory",
            "canonical records", "snapshot", "pair", "capture", "Toda 216 natural records", "provenance",
        ],
        "raw_natural_evidence_preserved": True,
        "formal_evidence_physically_preserved": True,
        "toda_raw_record_count": 216,
        "toda_raw_framed_sha256": recovery["evidence_preservation"]["toda_raw_framed_sha256"],
        "canonical_membership_sha256": recovery["evidence_preservation"]["canonical_membership_sha256"],
        "evaluation_disposition": "QUARANTINED_RETAINED_NONCANONICAL_NOT_INVALIDATED",
        "read_use": ["DIAGNOSTIC_NATURAL_EVIDENCE", "PRE_RECOVERY_WINDOW_EVIDENCE"],
        "formal_performance_evaluation_eligible": False,
        "production_adoption_eligible": False,
        "copy_or_migrate_to_successor": False,
        "delete_regenerate_or_repair": False,
        "auxiliary_provenance_note": "The four previously created, unreferenced freeze files remain retained and excluded; this design does not delete or add Window-local provenance.",
    }

    incident_contract = {
        "incident_type": "SCHEDULER_FAILURE_MISSED_OPPORTUNITY",
        "required_severity": "MAJOR",
        "classification_basis": "Bounded Window-level population loss caused by a late eligibility producer schedule gap; no measurement semantics change and no unbounded taint.",
        "affected_window_id": pointer["window_id"],
        "affected_race_codes": AFFECTED,
        "missing_count": 8,
        "repair_reconstructibility": "PARTIAL",
        "historical_repair_performed": False,
        "recovery_strategy": "FREEZE_CURRENT_AND_START_CLEAN_WINDOW",
        "old_implementation_identity": recovery["evidence_preservation"]["declared_identity"],
        "new_implementation_identity": None,
        "source_evidence_hash": recovery["evidence_preservation"]["canonical_membership_sha256"],
        "evidence_status_projection": "QUARANTINED",
        "artifact_disposition": "QUARANTINED_RETAINED_NONCANONICAL_NOT_INVALIDATED",
        "requires_new_window": True,
        "requires_new_epoch": False,
        "requires_cg_reapproval": False,
        "existing_policy_gap": "The policy definition describes population-loss escalation, but classify() currently leaves non-race-local scheduler loss at WARNING. Execution must fail closed until MAJOR classification is implemented and tested.",
        "recovery_resolution": {
            "schema_version": "official-epoch-incident-resolution/v3",
            "status": "PROPOSED_NOT_IMPLEMENTED",
            "purpose": "Cross-bind the MAJOR incident, abort/v2, root-cause fix, vNext identity, pristine successor and same-Epoch binding before pointer handoff.",
            "required_terminal_disposition": "TECHNICAL_INCOMPLETE_ABORTED",
            "required_artifact_disposition": "QUARANTINED_RETAINED_NONCANONICAL_NOT_INVALIDATED",
            "required_root_cause_fixed": True,
            "required_regression_result": "PASS",
            "required_same_epoch_validation": "9_OF_9_PASS",
            "requires_new_window": True,
            "requires_new_epoch": False,
            "requires_cg_reapproval": False,
            "existing_v2_gap": "resolution/v2 requires closure_status=VALID, a suspended Epoch and CG recovery approval; it cannot represent an ACTIVE-Epoch technical-incomplete abort path.",
        },
    }

    epoch_contract = {
        "epoch_action": "MAINTAIN_EXISTING_EPOCH_CONDITIONALLY",
        "epoch_state_during_same_epoch_binding": "ACTIVE",
        "suspension_required": False,
        "new_epoch_required": False,
        "basis": "The defect is bounded to one Window; evidence provenance is intact and measurement/comparative semantics are unchanged. Existing MAJOR policy permits same-Epoch recovery.",
        "fail_closed_override": "If any of the 9/9 same-Epoch successor requirements fails, or taint/semantic change becomes unbounded, do not reuse the Epoch and stop for governance review.",
        "same_epoch_successor_requirements": [
            "measurement_semantics_unchanged", "comparative_scope_unchanged",
            "comparative_policy_semantics_unchanged", "hard_gate_policy_unchanged",
            "numeric_policy_semantics_unchanged", "incident_policy_semantics_unchanged",
            "affected_evidence_uniquely_isolated", "regressions_pass", "readiness_pass",
        ],
    }

    technical_abort_v2 = {
        "schema_version": "formal-measurement-window-abort/v2",
        "status": "PROPOSED_NOT_IMPLEMENTED",
        "terminal_state": "ABORTED",
        "abort_reason": "FORMAL_EVIDENCE_INCOMPLETE_PRODUCER_DEFECT",
        "official_epoch": "YES",
        "required_fields": [
            "window_id", "armed_at", "aborted_at", "capture_through_date", "failure_code",
            "incident_id", "incident_hash", "epoch_id", "epoch_start_record_hash",
            "affected_keyset_hash", "missing_count", "repair_reconstructibility",
            "historical_repair_performed", "formal_evidence_aggregate_sha256",
            "raw_evidence_sha256", "canonical_membership_sha256", "declared_implementation_identity",
            "activation_implementation_identity", "closing_tool_implementation_identity",
            "previous_pointer_identity", "evidence_disposition", "formal_evaluation_eligible",
            "production_adoption_eligible", "successor_copy_permitted", "abort_record_hash",
        ],
        "fixed_values": {
            "missing_count": 8,
            "repair_reconstructibility": "PARTIAL",
            "historical_repair_performed": False,
            "evidence_disposition": "QUARANTINED_RETAINED_NONCANONICAL_NOT_INVALIDATED",
            "formal_evaluation_eligible": False,
            "production_adoption_eligible": False,
            "successor_copy_permitted": False,
        },
        "validation": [
            "create-only external record", "current pointer/window/ACTIVE state match",
            "incident/epoch/hash cross-binding", "evidence hashes re-read and match",
            "declared and activation identities remain unchanged", "affected keyset exact match",
            "maintenance hold and zero capture process attested", "idempotent same-intent retry only",
        ],
    }

    identity_side_effect = {
        "fix_required": True,
        "current_problem": "assertCurrent() calls freeze() before it compares current identity with the immutable declaration, so a mismatch-only check writes Window-local provenance.",
        "required_contract": {
            "verification": "Use currentIdentityReadOnly() and compare identity before any write.",
            "mismatch_effect": "Return DECLARED_IMPLEMENTATION_IDENTITY_MISMATCH with zero new files.",
            "freeze": "Separate explicit mutation permitted only during declaration of a pristine PREPARED successor.",
            "terminal_guard": "CLOSED/ABORTED/INVALID must block before any freeze.",
        },
        "required_tests": [
            "ACTIVE mismatch creates zero files", "ABORTED assertion creates zero files",
            "CLOSED assertion creates zero files", "explicit PREPARED declaration freezes exactly one manifest/source pair",
        ],
    }

    preconditions = [
        "root cause and exact affected 8-race keyset reconfirmed",
        "scheduler/capture maintenance hold active and capture process count=0",
        "current pointer, manifest, declaration, activation, Epoch and binding hashes frozen in a read-only preflight package",
        "raw evidence, formal aggregate, Toda 216 and canonical membership hashes re-read and match",
        "historical repair prohibited and historical_repair_performed=false",
        "incident record and abort/v2 payload fully prepared and hash-cross-bound",
        "identity side-effect fix and abort/v2/MAJOR-classification implementation pass regression tests",
        "vNext transitive dependency and runtime schedule identity complete",
        "pristine successor PREPARED with formal_evidence_count=0 and new identity explicitly frozen",
        "same-Epoch successor validation 9/9 PASS and binding eligible",
        "operator-approved pointer/activation/runtime rollout and rollback plan prepared",
    ]

    transition_sequence = [
        {"step": 1, "action": "Final read-only audit of current Window/Epoch/evidence", "writes": "NONE", "rollback": "NOT_APPLICABLE"},
        {"step": 2, "action": "Enter scheduler/capture maintenance hold and attest zero capture processes", "writes": "RUNTIME_HOLD_ONLY", "rollback": "RELEASE_HOLD_BEFORE_TERMINAL"},
        {"step": 3, "action": "Deploy and verify identity read-only fix, abort/v2 support, MAJOR incident classification, producer/finalizer and schedule contract", "writes": "SOURCE_AND_DEPLOYMENT_ONLY", "rollback": "ROLLBACK_DEPLOYMENT_WHILE_HOLD_REMAINS"},
        {"step": 4, "action": "Explicitly freeze vNext identity and create a distinct pristine successor Window in PREPARED state", "writes": "NEW_WINDOW_ONLY", "rollback": "ABANDON_UNBOUND_PRISTINE_SUCCESSOR_WITH_AUDIT"},
        {"step": 5, "action": "Validate 9/9 same-Epoch conditions and create successor binding while Epoch is ACTIVE", "writes": "CREATE_ONLY_BINDING", "rollback": "DO_NOT_ROUTE_TO_SUCCESSOR; SUPERSEDE ONLY IF CONTRACTUALLY REQUIRED"},
        {"step": 6, "action": "Create MAJOR incident record for bounded scheduler missed opportunities", "writes": "CREATE_ONLY_INCIDENT", "rollback": "NO_DELETE; RESOLVE_BY_APPEND_ONLY_RECORD"},
        {"step": 7, "action": "Create external abort/v2 for current Window", "writes": "CREATE_ONLY_TERMINAL_RECORD", "rollback": "IRREVERSIBLE_TERMINAL_BOUNDARY"},
        {"step": 8, "action": "Create technical-incomplete recovery resolution cross-binding incident, abort, fix identity, successor and binding", "writes": "CREATE_ONLY_RESOLUTION", "rollback": "NO_DELETE; STOP_BEFORE_ROUTING_IF_INVALID"},
        {"step": 9, "action": "CAS pointer from ABORTED current Window to bound PREPARED successor", "writes": "POINTER_AND_CREATE_ONLY_AUDIT", "rollback": "NO_DIRECT_REVERSE; NEW_INCIDENT/RECOVERY_REQUIRED"},
        {"step": 10, "action": "Activate successor and issue new runtime authorization after exact schedule attestation", "writes": "NEW_WINDOW_ACTIVATION", "rollback": "DO_NOT_MUTATE; INCIDENT/ABORT_SUCCESSOR_IF_FAILURE"},
        {"step": 11, "action": "Release hold and start future-only natural capture", "writes": "FUTURE_SUCCESSOR_EVIDENCE_ONLY", "rollback": "CAPTURE IS IRREVERSIBLE; INCIDENT-DRIVEN STOP ONLY"},
    ]

    rollback_rules = {
        "fully_reversible_before": "Before step 6, deployment/hold can be rolled back and an unbound evidence-free successor can be abandoned with an audit record.",
        "append_only_governance_boundary": "Step 6 incident creation is append-only and may only be resolved, never deleted.",
        "terminal_point_of_no_return": "Step 7 abort/v2 creation permanently makes the current Window ABORTED.",
        "routing_point_of_no_return": "Step 9 pointer CAS cannot be silently reversed.",
        "new_evidence_point_of_no_return": "The first successor capture after step 11 is immutable future evidence and cannot be rolled back.",
        "identity_freeze": "A frozen successor identity is immutable; if rejected before routing, preserve/supersede the pristine Window rather than editing the freeze.",
    }

    contract = {
        "contract_version": "cc30c-technical-incomplete-window-contract/v1",
        "status": "DESIGN_READY_NOT_APPLIED",
        "generated_at": generated_at,
        "source_git_commit": commit,
        "source_window": {
            "window_id": pointer["window_id"], "state": "ACTIVE", "window_root": pointer["window_root"],
            "expected_count": 372, "candidate_count": 372, "eligibility_count": 364,
            "missing_count": 8, "affected_race_codes": AFFECTED,
        },
        "eligible_source_states": ["ACTIVE"],
        "selected_terminal_state": "ABORTED",
        "new_lifecycle_state_required": False,
        "terminal_reason": "FORMAL_EVIDENCE_INCOMPLETE_PRODUCER_DEFECT",
        "terminal_record": technical_abort_v2,
        "lifecycle_semantics": lifecycle_semantics,
        "evidence_retention": retention,
        "evaluation_eligibility": {
            "formal_performance_evaluation": "PROHIBITED", "production_adoption": "PROHIBITED",
            "diagnostic_read": "ALLOWED", "reference_read": "ALLOWED_WITH_TECHNICAL_INCOMPLETE_LABEL",
            "successor_sample_migration": "PROHIBITED",
        },
        "successor_eligibility": {
            "status": "READY_DESIGN_NOT_EXECUTABLE_UNTIL_IMPLEMENTATION_GATES_PASS",
            "old_window_terminal_required": True, "old_window_immutable_required": True,
            "distinct_window_id_required": True, "pristine_prepared_required": True,
            "formal_evidence_count_required": 0, "incident_and_resolution_required": True,
            "new_identity_binding_activation_runtime_authorization_required": True,
            "copy_old_evidence_permitted": False,
        },
        "future_only_boundary": {
            "existing_contract": "cutoff_timestamp > armed_at",
            "additional_rollover_guards": [
                "successor prepared_at > old aborted_at", "successor armed_at > old aborted_at",
                "only observations made after successor activation are writable",
                "no predecessor Candidate/eligibility/formal/raw artifact may be copied or referenced as successor sample membership",
            ],
        },
        "incident_schema": incident_contract,
        "epoch_contract": epoch_contract,
        "identity_side_effect_requirement": identity_side_effect,
        "transition_preconditions": preconditions,
        "transition_sequence": transition_sequence,
        "rollback_rules": rollback_rules,
        "controlled_fixture": fixture_result,
        "input_hashes": input_hashes,
    }
    contract["contract_sha256"] = "sha256:" + hashlib.sha256(canonical(contract)).hexdigest()

    current_critical = set()
    for line in provenance_source.splitlines():
        stripped = line.strip()
        if stripped.startswith("'") and stripped.endswith("',"):
            current_critical.add(stripped[1:-2])

    dependency_specs = [
        ("collector", "analysis/collect_strict_forward_exhibition.php", "SOURCE_IDENTITY", True, "Pre-cutoff exhibition acquisition and candidate/time guards."),
        ("eligibility_state", "web/logic/StrictForwardExhibitionEligibilityState.php", "SOURCE_IDENTITY", True, "Eligibility state schema/transition semantics."),
        ("late_finalizer", "analysis/finalize_strict_forward_late_eligibility.php", "SOURCE_IDENTITY", True, "Post-22:00 DB-only terminal resolution producer."),
        ("late_runner", "analysis/run_strict_forward_late_eligibility_cron.sh", "SOURCE_IDENTITY", True, "Exact late producer invocation and fail-closed environment."),
        ("late_schedule_contract", "config/strict_forward_late_eligibility_schedule_v1.json", "SOURCE_AND_RUNTIME_IDENTITY", True, "22:00-23:59 JST, every two minutes; the defect was schedule-dependent."),
        ("formal_aggregate_v2", "web/logic/FormalEvidenceAggregate.php", "SOURCE_IDENTITY", True, "Window-wide multi-day formal membership/aggregate semantics."),
        ("formal_aggregate_v2_contract", "analysis/output/cc30c_formal_evidence_aggregate_contract_v2.json", "CONTRACT_IDENTITY", True, "Machine-readable aggregate v2 contract and schema version."),
        ("provenance_resolver", "web/logic/FormalWindowProvenanceResolver.php", "SOURCE_IDENTITY", True, "Expected-inventory and provenance membership resolution."),
        ("opportunity_ledger", "web/logic/StrictForwardOpportunityLedger.php", "SOURCE_IDENTITY", True, "Candidate/observation/finalize semantics."),
        ("window_lifecycle", "web/logic/FormalMeasurementWindowLifecycle.php", "SOURCE_IDENTITY", True, "abort/v2, terminal state and pointer handoff."),
        ("identity_declaration", "web/logic/WindowDeclaredImplementationIdentity.php", "SOURCE_IDENTITY", True, "Read-only assertCurrent and explicit freeze boundary."),
        ("provenance_manifest", "web/logic/MeasurementImplementationProvenance.php", "SOURCE_IDENTITY", True, "Transitive dependency inventory root."),
        ("epoch_incident_policy", "web/logic/OfficialEpochIncidentPolicyV1.php", "SOURCE_IDENTITY", True, "MAJOR scheduler-loss classification and same-Epoch recovery rules."),
        ("epoch_binding_contract", "web/logic/OfficialEpochWindowBindingV1.php", "SOURCE_IDENTITY", True, "9/9 successor and future-only boundary."),
        ("epoch_binding_store", "web/logic/OfficialEpochWindowBindingStore.php", "SOURCE_IDENTITY", True, "Prepared successor binding implementation."),
        ("epoch_binding_runtime", "web/logic/OfficialEpochWindowBindingRuntimeAuthorization.php", "SOURCE_IDENTITY", True, "Binding/activation runtime fail-closed enforcement."),
        ("activation", "web/logic/WindowActivationHold.php", "SOURCE_IDENTITY", True, "Activation/runtime authorization identity."),
        ("schedule_runtime_attestation", "SYSTEM_CRONTAB_ENTRY", "RUNTIME_ATTESTATION", True, "Installed cadence, timezone, command and enabled state must hash-match the frozen schedule contract."),
        ("artifact_schema_versions", "EMBEDDED_SCHEMA_CONSTANTS_AND_MACHINE_READABLE_CONTRACTS", "CONTRACT_IDENTITY", True, "All ledger, eligibility, aggregate, abort, incident, binding and activation schema versions."),
        ("cron_installer", "analysis/install_cc30c_late_eligibility_cron.sh", "OPERATOR_TOOL_PROVENANCE", False, "Deployment tool is audited but not measurement semantics once runtime schedule is independently attested."),
    ]
    dependency_rows = []
    for name, path, layer, required, reason in dependency_specs:
        file_path = REPO / path if not path.startswith("SYSTEM_") and not path.startswith("EMBEDDED_") else None
        dependency_rows.append({
            "name": name,
            "path": path,
            "identity_layer": layer,
            "required_for_vnext": required,
            "exists_now": file_path.exists() if file_path else None,
            "sha256_now": sha(file_path) if file_path and file_path.is_file() else None,
            "currently_in_measurement_critical_paths": path in current_critical,
            "reason": reason,
        })

    schedule_contract = {
        "required": True,
        "status": "PROPOSED_NOT_CREATED",
        "timezone": "Asia/Tokyo",
        "active_local_time_range": "22:00:00-23:59:59",
        "cadence": "every 2 minutes",
        "cron_expression": "*/2 22-23 * * *",
        "mode": "DB_ONLY_NO_EXTERNAL_FETCH",
        "scope": "Previously observed unresolved frozen candidates only; no discovery, no collector start, no historical repair.",
        "runtime_attestation": ["installed command", "cron expression", "timezone", "runner SHA256", "enabled state"],
        "drift_action": "FAIL_CLOSED_AND_HOLD_CAPTURE",
    }
    inventory = {
        "schema_version": "cc30c-vnext-identity-dependency-inventory/v1",
        "status": "READY",
        "generated_at": generated_at,
        "source_git_commit": commit,
        "current_declared_identity": recovery["evidence_preservation"]["declared_identity"],
        "current_read_only_identity": recovery["evidence_preservation"]["current_source_identity_read_only"],
        "new_identity": None,
        "freeze_performed": False,
        "dependency_rows": dependency_rows,
        "schedule_identity_decision": "INCLUDE_IN_SOURCE_IDENTITY_AND_ATTEST_ACTUAL_RUNTIME_CONFIGURATION",
        "schedule_contract": schedule_contract,
        "freeze_gate": "All required dependencies must exist, be hashed, pass regression/readiness, and the installed schedule attestation must match before explicit successor freeze.",
    }

    plan = {
        "schema_version": "cc30c-window-rollover-mutation-plan/v1",
        "status": "READY_NOT_EXECUTED",
        "generated_at": generated_at,
        "source_git_commit": commit,
        "source_window_id": pointer["window_id"],
        "target_window_id": None,
        "recovery_strategy": "FREEZE_CURRENT_AND_START_CLEAN_WINDOW",
        "terminal_state": "ABORTED",
        "terminal_reason": "FORMAL_EVIDENCE_INCOMPLETE_PRODUCER_DEFECT",
        "same_epoch": True,
        "preconditions": preconditions,
        "steps": transition_sequence,
        "rollback_rules": rollback_rules,
        "execution_blockers": [
            "abort/v2 is not implemented or recognized by effectiveState/handoffPointerCas",
            "scheduler population-loss incident is not yet deterministically classified MAJOR by classify()",
            "incident resolution/v3 for ACTIVE-Epoch technical-incomplete abort is not implemented",
            "assertCurrent mismatch remains write-producing",
            "vNext required dependencies and schedule contract are not yet all in the frozen identity",
            "successor Window, identity, binding, activation and runtime authorization do not yet exist",
        ],
        "current_phase_mutations_performed": [],
        "current_window_changed": False,
        "epoch_changed": False,
        "binding_changed": False,
        "activation_changed": False,
        "runtime_authorization_changed": False,
        "cron_changed": False,
        "identity_frozen": False,
    }

    report = {
        "schema_version": "cc30c-terminal-contract-design-report/v1",
        "generated_at": generated_at,
        "source_git_commit": commit,
        "inputs": input_hashes,
        "production_read_only_audit": {
            "window_id": pointer["window_id"],
            "state_before": "ACTIVE", "state_after": "ACTIVE",
            "window_tree_before": before_tree, "window_tree_after": after_tree,
            "window_tree_unchanged": before_tree == after_tree,
            "terminal_record_created": False, "successor_created": False,
            "epoch_binding_activation_runtime_changed": False,
        },
        "source_semantics_checks": required_source_facts,
        "lifecycle_semantics": lifecycle_semantics,
        "selected_terminal_state": "ABORTED",
        "terminal_reason": "FORMAL_EVIDENCE_INCOMPLETE_PRODUCER_DEFECT",
        "why_not_closed": lifecycle_semantics["CLOSED"]["reason"],
        "why_not_invalid": lifecycle_semantics["INVALID"]["reason"],
        "evidence_retention": retention,
        "epoch_and_incident": {"incident": incident_contract, "epoch": epoch_contract},
        "identity_side_effect": identity_side_effect,
        "controlled_state_machine_test": fixture_result,
        "contract_readiness": {
            "TECHNICAL_INCOMPLETE_TERMINAL_STATE": "ABORTED",
            "TECHNICAL_INCOMPLETE_REASON": "FORMAL_EVIDENCE_INCOMPLETE_PRODUCER_DEFECT",
            "EVIDENCE_RETENTION_CONTRACT": "READY",
            "SUCCESSOR_WINDOW_CONTRACT": "READY",
            "IDENTITY_SIDE_EFFECT_FIX_REQUIRED": "YES",
            "VNEXT_IDENTITY_DEPENDENCY_SET": "READY",
            "ROLLOVER_MUTATION_PLAN": "READY",
            "EXECUTION_READINESS": "BLOCKED_UNTIL_IMPLEMENTATION_GATES_PASS",
            "CC30C_4O_STATUS": "BLOCKED",
        },
        "artifacts": {
            "contract": "analysis/output/cc30c_technical_incomplete_window_contract_v1.json",
            "mutation_plan": "analysis/output/cc30c_window_rollover_mutation_plan_v1.json",
            "dependency_inventory": "analysis/output/cc30c_vnext_identity_dependency_inventory.json",
            "fixture_test": "analysis/test_cc30c_technical_incomplete_contract_v1.php",
        },
    }

    dump(OUTPUT / "cc30c_technical_incomplete_window_contract_v1.json", contract)
    dump(OUTPUT / "cc30c_window_rollover_mutation_plan_v1.json", plan)
    dump(OUTPUT / "cc30c_vnext_identity_dependency_inventory.json", inventory)
    dump(OUTPUT / "cc30c_terminal_contract_design_report.json", report)

    # Re-read all outputs and verify their core cross-contract conclusions.
    reread = {
        name: load(OUTPUT / name)
        for name in [
            "cc30c_technical_incomplete_window_contract_v1.json",
            "cc30c_window_rollover_mutation_plan_v1.json",
            "cc30c_vnext_identity_dependency_inventory.json",
            "cc30c_terminal_contract_design_report.json",
        ]
    }
    assert reread["cc30c_technical_incomplete_window_contract_v1.json"]["selected_terminal_state"] == "ABORTED"
    assert reread["cc30c_window_rollover_mutation_plan_v1.json"]["status"] == "READY_NOT_EXECUTED"
    assert reread["cc30c_vnext_identity_dependency_inventory.json"]["status"] == "READY"
    assert reread["cc30c_terminal_contract_design_report.json"]["contract_readiness"]["CC30C_4O_STATUS"] == "BLOCKED"

    print(json.dumps({
        "status": "PASS",
        "selected_terminal_state": "ABORTED",
        "terminal_reason": "FORMAL_EVIDENCE_INCOMPLETE_PRODUCER_DEFECT",
        "controlled_fixture": fixture_result["status"],
        "production_window_unchanged": before_tree == after_tree,
        "output_files": list(reread),
    }, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
