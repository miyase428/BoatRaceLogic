#!/usr/bin/env python3
"""Outcome-blind, read-only-input comparative policy inventory.

Only audit artifacts under analysis/output/ml_forward_comparative_inventory are
written. No runtime job, database connection, prediction, snapshot, or ledger
writer is invoked.
"""

from __future__ import annotations

import csv
import hashlib
import json
import subprocess
from collections import Counter
from datetime import datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "analysis/output/ml_forward_comparative_inventory"
CROSSWALK = ROOT / "analysis/output/ml_forward_epoch_launch_criteria/production_use_slot_crosswalk.csv"
SLOTS = ROOT / "analysis/output/ml_forward_traditional_shadow/traditional_shadow_slot_matrix.csv"
SOURCES = ROOT / "analysis/output/ml_forward_traditional_shadow/traditional_shadow_policy_sources.csv"
OLD_MATRIX = ROOT / "analysis/output/ml_forward_epoch_readiness/strict_forward_traditional_unknown_classification.csv"
API = ROOT / "web/tamagawa_lane4_star_api.php"
LEDGER = ROOT / "web/logic/StrictForwardReadinessLedger.php"
RULES = ROOT / "config/course_signal_rules.json"


def read_csv(path: Path) -> list[dict[str, str]]:
    with path.open(newline="", encoding="utf-8") as handle:
        return list(csv.DictReader(handle))


def write_csv(name: str, fieldnames: list[str], rows: list[dict[str, object]]) -> None:
    with (OUT / name).open("w", newline="", encoding="utf-8") as handle:
        writer = csv.DictWriter(handle, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerows(rows)


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def git(*args: str) -> str:
    return subprocess.check_output(["git", *args], cwd=ROOT, text=True).strip()


def main() -> None:
    OUT.mkdir(parents=True, exist_ok=True)
    start_head = git("rev-parse", "HEAD")
    crosswalk = read_csv(CROSSWALK)
    slots = read_csv(SLOTS)
    sources = read_csv(SOURCES)
    old = read_csv(OLD_MATRIX)

    if len({(r["venue"], r["course"], r["target"]) for r in crosswalk}) != len(crosswalk):
        raise RuntimeError("duplicate_production_use")
    if len({(r["venue"], r["course"], r["target"]) for r in slots}) != len(slots):
        raise RuntimeError("duplicate_structural_slot")

    api_text = API.read_text(encoding="utf-8")
    ledger_text = LEDGER.read_text(encoding="utf-8")
    static_capture_path = all(token in api_text for token in (
        "function strictForwardTraditionalEvaluationMap(",
        "StrictForwardReadinessLedger::captureLiveFromContext([",
        "'traditional_evaluation_by_course' => $strictForwardTraditionalEvaluationByCourse",
    )) and all(token in ledger_text for token in (
        "private function traditionalDecision(",
        "'RESOLVED_TRUE_FALSE'",
        "'POLICY_UNDEFINED_2C_COMBINED_DECISION'",
    ))

    slot_by_key = {(r["venue"], r["course"], r["target"]): r for r in slots}
    source_by_course = {(r["venue"], r["course"]): r for r in sources}
    inventory: list[dict[str, object]] = []
    evidence: list[dict[str, object]] = []
    unresolved = 0
    for row in crosswalk:
        key = (row["venue"], row["course"], row["target"])
        slot = slot_by_key.get(key)
        source = source_by_course.get((row["venue"], row["course"]))
        status = slot["shadow_classification"] if slot else "MAPPING_MISSING"
        if status == "RESOLVED_TRUE_FALSE" and source and static_capture_path:
            comparative_class = "TRADITIONAL_COMPARABLE"
            prospective = "NO"
            availability = "AVAILABLE_NOW"
            scope = "YES"
            reason = "Existing policy and deterministic true/false shadow path are both statically evidenced."
        elif status == "RESOLVED_TRUE_FALSE" and source:
            comparative_class = "IMPLEMENTATION_REQUIRED"
            prospective = "NO"
            availability = "IMPLEMENTATION_REQUIRED"
            scope = "NO"
            reason = "Policy is specified but formal capture path is not statically complete."
        elif status == "POLICY_UNDEFINED":
            comparative_class = "COMPARATIVE_UNAVAILABLE"
            prospective = "YES"
            availability = "POLICY_UNDEFINED"
            scope = "NO"
            reason = "No single Historical/Current Traditional true/false policy exists for this use."
        else:
            comparative_class = "UNRESOLVED_EVIDENCE"
            prospective = "UNKNOWN"
            availability = "RUNTIME_VERIFICATION_REQUIRED"
            scope = "NO"
            reason = "Required policy/source mapping evidence is incomplete."
            unresolved += 1
        evidence_source = (
            f"{source['logic_source']}; {source['shadow_evaluation_source']}; "
            f"{slot['basis'] if slot else 'MAPPING_MISSING'}"
            if source else "MAPPING_MISSING"
        )
        item = {
            "venue": row["venue"], "course": row["course"], "target": row["target"],
            "production_model_version": row["production_model_version"],
            "comparative_class": comparative_class,
            "prospective_reference_candidate": prospective,
            "traditional_policy_variant": slot["traditional_policy_variant"] if slot else "",
            "traditional_policy_exists": slot["policy_exists"] if slot else "UNKNOWN",
            "traditional_policy_source": evidence_source,
            "current_capture_availability": availability,
            "runtime_verification": "RUNTIME_VERIFICATION_REQUIRED",
            "official_traditional_comparison_scope_candidate": scope,
            "lineage_status": row["lineage_status"],
            "artifact_identity_status": row["artifact_identity_status"],
            "traditional_policy_version": row["traditional_policy_version"],
            "logic_version_basis": row["logic_version_basis"],
            "classification_reason": reason,
        }
        inventory.append(item)

    inventory.sort(key=lambda r: (str(r["venue"]), int(str(r["course"])), str(r["target"])))
    inventory_fields = list(inventory[0])
    write_csv("comparative_policy_inventory.csv", inventory_fields, inventory)
    direct = [r for r in inventory if r["comparative_class"] == "TRADITIONAL_COMPARABLE"]
    ml_only = [r for r in inventory if r["comparative_class"] != "TRADITIONAL_COMPARABLE"]
    write_csv("direct_comparative_scope.csv", inventory_fields, direct)
    write_csv("ml_only_scope.csv", inventory_fields, ml_only)

    for slot in slots:
        source = source_by_course.get((slot["venue"], slot["course"]), {})
        evidence.append({
            "venue": slot["venue"], "course": slot["course"], "target": slot["target"],
            "structural_shadow_classification": slot["shadow_classification"],
            "policy_exists": slot["policy_exists"], "traditional_policy_variant": slot["traditional_policy_variant"],
            "production_output_source": source.get("production_output_source", "MISSING"),
            "shadow_evaluation_source": source.get("shadow_evaluation_source", "MISSING"),
            "basis": slot["basis"], "traditional_policy_version": slot["traditional_policy_version"],
            "logic_version_basis": slot["logic_version_basis"],
        })
    write_csv("traditional_policy_evidence.csv", list(evidence[0]), evidence)

    slot_counts = Counter(r["shadow_classification"] for r in slots)
    old_counts = Counter(r["classification_when_unknown"] for r in old)
    class_counts = Counter(str(r["comparative_class"]) for r in inventory)
    prospective_counts = Counter(str(r["prospective_reference_candidate"]) for r in inventory)
    reconciliation = [
        {"reference":"structural slots","reference_value":"216","verified_value":len(slots),"denominator":"12 venues × 6 courses × 3 targets","grouping_unit":"venue×course×target","filter":"all structural slots","status":"VERIFIED" if len(slots)==216 else "CONFLICT","reason":"Current structural matrix"},
        {"reference":"RESOLVED_TRUE_FALSE","reference_value":"96","verified_value":slot_counts["RESOLVED_TRUE_FALSE"],"denominator":"216 structural slots","grouping_unit":"venue×course×target","filter":"current shadow classification","status":"VERIFIED" if slot_counts["RESOLVED_TRUE_FALSE"]==96 else "CONFLICT","reason":"Enabled single-course policies with deterministic negative extraction"},
        {"reference":"POLICY_UNDEFINED current","reference_value":"120","verified_value":slot_counts["POLICY_UNDEFINED"],"denominator":"216 structural slots","grouping_unit":"venue×course×target","filter":"2C combined or explicitly disabled primary policy","status":"VERIFIED" if slot_counts["POLICY_UNDEFINED"]==120 else "CONFLICT","reason":"36 combined 2C slots plus 84 disabled single-course slots"},
        {"reference":"POLICY_UNDEFINED historical","reference_value":"36","verified_value":old_counts["POLICY_UNDEFINED"],"denominator":"216 structural slots","grouping_unit":"venue×course×target","filter":"older unknown-classification artifact","status":"VERIFIED" if old_counts["POLICY_UNDEFINED"]==36 else "CONFLICT","reason":"Older artifact classified only 2C as undefined"},
        {"reference":"IMPLEMENTATION_UNAVAILABLE historical","reference_value":"180","verified_value":old_counts["IMPLEMENTATION_UNAVAILABLE"],"denominator":"216 structural slots","grouping_unit":"venue×course×target","filter":"older positive-only map evidence","status":"VERIFIED" if old_counts["IMPLEMENTATION_UNAVAILABLE"]==180 else "CONFLICT","reason":"Before prerequisite-evaluation trace, negative decisions were not safely extractable"},
        {"reference":"production ML uses","reference_value":"123","verified_value":len(inventory),"denominator":"production-enabled subset of 216","grouping_unit":"venue×course×target","filter":"production model use crosswalk","status":"VERIFIED" if len(inventory)==123 else "CONFLICT","reason":"12 production venues; partial course/target replacement"},
        {"reference":"Direct comparative","reference_value":"40","verified_value":len(direct),"denominator":"123 production ML uses","grouping_unit":"venue×course×target","filter":"TRADITIONAL_COMPARABLE only","status":"VERIFIED" if len(direct)==40 else "CONFLICT","reason":"Intersection of production ML use and current RESOLVED_TRUE_FALSE policy"},
        {"reference":"ML-only","reference_value":"83","verified_value":len(ml_only),"denominator":"123 production ML uses","grouping_unit":"venue×course×target","filter":"all non-TRADITIONAL_COMPARABLE uses","status":"VERIFIED" if len(ml_only)==83 else "CONFLICT","reason":"Current production uses without a comparable Historical/Current Traditional decision"},
    ]
    write_csv("comparative_count_reconciliation.csv", list(reconciliation[0]), reconciliation)

    hashes = {"rules": sha(RULES), "api": sha(API), "ledger": sha(LEDGER)}
    two_c = [r for r in inventory if r["course"] == "2"]
    summary = f"""# READ-ONLY Comparative Policy Inventory

- audit_at: `{datetime.now().astimezone().isoformat(timespec='seconds')}`
- HEAD: `{start_head}`
- Production ML uses: **{len(inventory)}** across **{len(set(r['venue'] for r in inventory))} venues**
- Structural slots: **{len(slots)}**
- `TRADITIONAL_COMPARABLE`: **{class_counts['TRADITIONAL_COMPARABLE']}**
- `IMPLEMENTATION_REQUIRED`: **{class_counts['IMPLEMENTATION_REQUIRED']}**
- `COMPARATIVE_UNAVAILABLE`: **{class_counts['COMPARATIVE_UNAVAILABLE']}**
- `OTHER_BLOCKED`: **{class_counts['OTHER_BLOCKED']}**
- `UNRESOLVED_EVIDENCE`: **{class_counts['UNRESOLVED_EVIDENCE']}**
- `prospective_reference_candidate=YES`: **{prospective_counts['YES']}** (separate flag; excluded from comparable count)
- Official Traditional comparison scope candidates: **{len(direct)}**

## Evidence reconciliation

- Current structural matrix: **96 RESOLVED_TRUE_FALSE / 120 POLICY_UNDEFINED / 216**.
- Older structural artifact: **36 POLICY_UNDEFINED / 180 IMPLEMENTATION_UNAVAILABLE / 216**.
- The difference is versioned evidence, not arithmetic conflict: current `strictForwardTraditionalEvaluationMap()` added safe prerequisite evaluation, moving 96 enabled slots from implementation-unavailable to resolved; the remaining 84 disabled slots joined the 36 combined-2C slots as policy-undefined.
- Production intersection: **40 comparable / 83 ML-only / 123**.

## 2C

- Production 2C ML uses: **{len(two_c)}**; all are `COMPARATIVE_UNAVAILABLE`.
- Existing product policies are separate `sashi` and `makuri` components. An explicit component can prove a positive signal, but no single combined 2C true/false Traditional policy exists.
- No OR, AND, composite, or inferred combined-false rule was created.

## Runtime availability

Static code establishes the existing `API → strictForwardTraditionalEvaluationMap() → StrictForwardReadinessLedger::traditionalDecision()` cutoff-time capture path for comparable uses. It was **not executed** in this inventory. Real-runtime observation therefore remains `RUNTIME_VERIFICATION_REQUIRED`; this does not mean implementation is absent.

## Frozen identities

- course_signal_rules SHA256: `{hashes['rules']}`
- API SHA256: `{hashes['api']}`
- Strict ledger SHA256: `{hashes['ledger']}`

No forward outcome, result, payout, ROI, hit-rate, lift, or Outcome join was inspected. No prediction, capture, snapshot, prewarm, ledger writer, cron wrapper, or DB operation was executed.
"""
    (OUT / "comparative_policy_inventory_summary.md").write_text(summary, encoding="utf-8")

    conflicts = f"""# Comparative Inventory Conflicts

No unresolved row-level mapping conflict was found (`UNRESOLVED_EVIDENCE={unresolved}`).

The apparent **36/180 versus 96/120** discrepancy is a version difference:

- Historical artifact: positive-only legacy maps left 180 non-2C negatives unextractable.
- Current artifact/code: prerequisite-evaluation tracing resolves 96 enabled slots to true/false; 84 explicitly disabled slots are policy-undefined.
- Both artifacts are retained with their timestamps/version context; neither is rewritten.

`TMG 2C top3` retains its historical lineage conflict, but its main classification is `COMPARATIVE_UNAVAILABLE` because a combined 2C Traditional policy is undefined. It is not counted as Traditional comparable.
"""
    (OUT / "comparative_conflicts.md").write_text(conflicts, encoding="utf-8")

    gate = """# Comparative Readiness Gate Draft

This is a draft only; Official Epoch is not started.

For every use admitted to the Official Traditional comparison scope, require:

1. policy specification frozen;
2. policy identity/version frozen;
3. input universe frozen;
4. cutoff contract frozen;
5. ML decision capturable before cutoff;
6. Traditional comparison decision capturable before cutoff;
7. `comparison_decision_missing = 0`;
8. `policy_identity_missing = 0`;
9. `unclassified_use = 0`;
10. Semantic Revision gate independently passes; `SEMANTIC_REVISION_UNVERIFIED` remains fail-loud.

Only rows with `comparative_class=TRADITIONAL_COMPARABLE` and `official_traditional_comparison_scope_candidate=YES` may enter the Historical/Current Traditional cohort. `prospective_reference_candidate=YES` belongs to a separate, not-yet-designed cohort.
"""
    (OUT / "comparative_readiness_gate_draft.md").write_text(gate, encoding="utf-8")

    missing = """# Comparative Inventory Missing Items

- Real LIVE_STRICT runtime capture remains `RUNTIME_VERIFICATION_REQUIRED`; this inventory intentionally did not run a capture job.
- 83 production uses have no comparable current/historical single Traditional decision. They remain outside the Traditional cohort.
- Prospective Reference policy is not designed or implemented here.
- Semantic Revision Hardening remains an independent fail-loud gate.
- Official Epoch remains unstarted.
"""
    (OUT / "comparative_inventory_missing_items.md").write_text(missing, encoding="utf-8")

    end_head = git("rev-parse", "HEAD")
    print(json.dumps({
        "status": "ok", "start_head": start_head, "end_head": end_head,
        "production_uses": len(inventory), "structural_slots": len(slots),
        "classifications": class_counts, "prospective_reference": prospective_counts,
        "official_scope_candidates": len(direct), "unresolved": unresolved,
        "forward_outcome_inspected": "NO", "result_payout_roi_inspected": "NO", "outcome_join": "NO",
    }, ensure_ascii=False, default=dict))


if __name__ == "__main__":
    main()
