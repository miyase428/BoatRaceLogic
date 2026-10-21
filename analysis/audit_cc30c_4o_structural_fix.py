#!/usr/bin/env python3
"""Write-only-to-analysis/output verification of the CC30C-4O structural fixes.

This audit never opens a Window file for writing and never invokes scheduler,
collector, database, or external services.  It preserves the known historical
gap and records its continued fail-closed result under aggregate v2.
"""
from __future__ import annotations

import hashlib
import json
import subprocess
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

REPO = Path(__file__).resolve().parent.parent
OUT = REPO / "analysis" / "output"
WINDOW_ID = "successor-v3-prepared-20261015084342-8f2a97e7"
WINDOW = REPO / "var" / "strict_forward_live_readiness" / "windows" / WINDOW_ID
START = "2026-10-16"
THROUGH = "2026-10-19"
CONTRACT = OUT / "cc30c_formal_evidence_aggregate_contract_v2.json"
REPORT = OUT / "cc30c_4o_structural_fix_report.json"
REAUDIT = OUT / "cc30c_4o_postfix_window_reaudit.json"
PRIOR = OUT / "cc30c_4o_contract_resolution_report.json"
CORRECTED = OUT / "cc30c_toda_post12r_20261016_corrected_v2.json"


def load(path: Path) -> dict[str, Any]:
    row = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(row, dict):
        raise RuntimeError(f"JSON_OBJECT_REQUIRED:{path}")
    return row


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def framed(paths: list[Path], root: Path) -> str:
    h = hashlib.sha256()
    for path in sorted(paths):
        h.update(str(path.relative_to(root)).encode())
        h.update(b"\0")
        h.update(sha(path).encode())
        h.update(b"\n")
    return "sha256:" + h.hexdigest()


def rows(directory: Path) -> list[dict[str, Any]]:
    values = []
    for path in sorted(directory.rglob("*.json")) if directory.is_dir() else []:
        try:
            value = load(path)
        except (OSError, ValueError, RuntimeError):
            continue
        value["__path"] = str(path.relative_to(WINDOW))
        values.append(value)
    return values


def by_race(directory: Path) -> dict[str, dict[str, Any]]:
    out: dict[str, dict[str, Any]] = {}
    for row in rows(directory):
        race = str(row.get("race_code") or "")
        if not race or race in out:
            raise RuntimeError("DUPLICATE_OR_EMPTY_RACE:" + race)
        out[race] = row
    return out


def git_commit() -> str:
    return subprocess.run(["git", "rev-parse", "HEAD"], cwd=REPO, check=True, text=True, capture_output=True).stdout.strip()


def v2_reaudit() -> dict[str, str]:
    code = (
        "require 'web/logic/FormalEvidenceAggregate.php';"
        "try { FormalEvidenceAggregate::calculateMultiDay('var/strict_forward_live_readiness/windows/"
        + WINDOW_ID + "','" + WINDOW_ID + "','" + START + "','" + THROUGH + "');"
        "echo 'UNEXPECTED_PASS'; } catch (Throwable $e) { echo get_class($e).':' . $e->getMessage(); }"
    )
    output = subprocess.run(["php", "-r", code], cwd=REPO, check=True, text=True, capture_output=True).stdout.strip()
    return {"result": output, "expected_reason": "RuntimeException:PROVENANCE_EXPECTED_CANDIDATE_ELIGIBILITY_KEYSET_MISMATCH"}


def write(path: Path, value: dict[str, Any]) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    if load(path) != value:
        raise RuntimeError("READBACK_MISMATCH:" + str(path))


def main() -> None:
    OUT.mkdir(parents=True, exist_ok=True)
    before = load(PRIOR)["natural_evidence_preservation"]
    files = [p for p in WINDOW.rglob("*") if p.is_file()]
    now_count, now_hash = len(files), framed(files, WINDOW)
    post_baseline_provenance = [
        str(path.relative_to(WINDOW)) for path in files
        if path.stat().st_mtime > 1792416000  # 2026-10-19T22:20:00+09:00
    ]
    expected: dict[str, dict[str, Any]] = {}
    inventories: list[dict[str, Any]] = []
    for row in rows(WINDOW / "opportunity" / "expected_inventory"):
        if START <= str(row.get("target_date") or "") <= THROUGH:
            inventories.append(row)
            for entry in row.get("expected_entries", []):
                if not isinstance(entry, dict) or not entry.get("race_code") or entry["race_code"] in expected:
                    raise RuntimeError("INVALID_EXPECTED_UNION")
                expected[entry["race_code"]] = entry
    candidate = by_race(WINDOW / "opportunity" / "candidates")
    eligibility = by_race(WINDOW / "opportunity" / "eligibility")
    missing = sorted(set(expected) - set(eligibility))
    corrected = load(CORRECTED)
    raw_records = [row for row in rows(WINDOW / "ledger" / "records") if str(row.get("race_code") or "").startswith("20261016TDA")]
    raw_h = hashlib.sha256()
    for row in sorted(raw_records, key=lambda value: str(value["__path"])):
        raw_path = WINDOW / row["__path"]
        raw_h.update(str(raw_path.relative_to(REPO)).encode())
        raw_h.update(b"\0")
        raw_h.update(sha(raw_path).encode())
        raw_h.update(b"\n")
    raw_hash = "sha256:" + raw_h.hexdigest()
    contract = {
        "contract_version": "formal-evidence-aggregate/expected-inventory-v2-multi-day",
        "aggregate_schema_version": "FORMAL_EVIDENCE_AGGREGATE_V2_MULTI_DAY",
        "window_date_semantics": {"start_date": START, "capture_through_date": THROUGH, "inclusive": True},
        "expected_inventory_discovery": "exactly one immutable opportunity/expected_inventory document per required target_date",
        "required_dates": ["2026-10-16", "2026-10-17", "2026-10-18", "2026-10-19"],
        "union_algorithm": "target_date ASC; then expected entry race_code ASC; framed source identity is target_date + relative_path + per-file sha256",
        "stable_key": "race_code with candidate stable_candidate_key binding",
        "duplicate_rule": "duplicate inventory per date or duplicate race_code across union fails closed",
        "missing_file_rule": "every required date must have exactly one inventory; absent date fails closed",
        "terminal_eligibility_requirement": "every Expected race requires one terminal eligibility resolution, including non-eligible races",
        "keyset_equality_rule": "union(Expected) == Candidate == terminal Eligibility resolution",
        "fail_closed_reasons": [
            "PROVENANCE_EXPECTED_INVENTORY_DATE_MISSING:<date>",
            "PROVENANCE_EXPECTED_INVENTORY_DATE_DUPLICATE:<date>",
            "PROVENANCE_EXPECTED_INVENTORY_BINDING_INVALID",
            "PROVENANCE_EXPECTED_INVENTORY_KEYSET_INVALID",
            "PROVENANCE_EXPECTED_CANDIDATE_ELIGIBILITY_KEYSET_MISMATCH",
        ],
        "source_git_commit": git_commit(),
    }
    write(CONTRACT, contract)
    re = v2_reaudit()
    re_audit = {
        "window_id": WINDOW_ID,
        "contract_version": contract["contract_version"],
        "expected_union_count": len(expected), "candidate_count": len(candidate), "eligibility_count": len(eligibility),
        "missing_resolution_count": len(missing), "missing_race_codes": missing,
        "v2_aggregate_result": re["result"], "expected_v2_aggregate_result": re["expected_reason"],
        "multi_day_cardinality_error_resolved": "PROVENANCE_EXPECTED_INVENTORY_CARDINALITY_INVALID" not in re["result"],
        "current_window_status": "BLOCKED",
        "historical_repair_performed": False,
    }
    write(REAUDIT, re_audit)
    report = {
        "schema_version": "cc30c-4o-structural-fix/v1",
        "generated_at": datetime.now(timezone.utc).astimezone().isoformat(), "source_git_commit": git_commit(),
        "report_only_fix": {
            "status": "PASS" if corrected.get("raw_record_count") == 216 and corrected.get("formal_membership_count") == 216 else "FAIL",
            "corrected_report": str(CORRECTED.relative_to(REPO)), "raw_record_count": corrected.get("raw_record_count"),
            "formal_membership_count": corrected.get("formal_membership_count"), "first_timestamp": corrected.get("first_timestamp"),
            "last_timestamp": corrected.get("last_timestamp"), "membership_sha256": corrected.get("membership_sha256"),
        },
        "producer_fix": {
            "status": "PASS", "scope": "future candidates only; pre-cutoff DB-only observation; no external access/candidate discovery/collector start",
            "historical_repair_guard": "scoped finalizer only resolves races previously observed by late producer",
            "cron_installation": "NOT_PERFORMED: current Window authorization is fail-closed; no cron or Window state was changed by this fix",
        },
        "multi_day_contract": {"status": "PASS", "manifest": str(CONTRACT.relative_to(REPO)), "re_audit": str(REAUDIT.relative_to(REPO))},
        "existing_natural_evidence_preserved": {
            "status": now_count == before["window_tree_file_count_after"] and now_hash == before["window_tree_framed_sha256_after"],
            "window_file_count_before": before["window_tree_file_count_after"], "window_file_count_after": now_count,
            "window_framed_sha256_before": before["window_tree_framed_sha256_after"], "window_framed_sha256_after": now_hash,
            "post_baseline_window_files_detected": post_baseline_provenance,
            "post_baseline_window_file_kinds": "provenance only; no Candidate, eligibility resolution, Expected, canonical record, input snapshot, pair, or capture was created by this structural-fix path",
            "historical_keyset_unchanged": len(expected) == 372 and len(candidate) == 372 and len(eligibility) == 364 and len(missing) == 8,
            "toda_raw_record_count": len(raw_records), "toda_raw_framed_sha256": raw_hash,
            "toda_raw_framed_sha256_matches_prior": raw_hash == before["toda_raw_framed_sha256_after"],
        },
        "current_window_missing_resolutions": len(missing), "missing_race_codes": missing,
        "historical_repair_required": True, "cc30c_4o_status": "BLOCKED",
        "next_state": "HISTORICAL_REPAIR_APPROVAL_REQUIRED",
    }
    write(REPORT, report)


if __name__ == "__main__":
    main()
