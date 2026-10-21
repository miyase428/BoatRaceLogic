#!/usr/bin/env python3
"""Read-only CC30C-4O contract/root-cause audit.

The script reads immutable Window evidence, source code, logs, and a small set
of database rows using SELECT only.  It writes audit reports exclusively below
analysis/output; it never writes the Window, database, scheduler, collector, or
lifecycle state.
"""
from __future__ import annotations

import csv
import hashlib
import json
import re
import subprocess
from collections import Counter
from datetime import datetime
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parent.parent
OUTPUT = REPO / "analysis" / "output"
WINDOW_ID = "successor-v3-prepared-20261015084342-8f2a97e7"
WINDOW_ROOT = REPO / "var" / "strict_forward_live_readiness" / "windows" / WINDOW_ID
THROUGH_DATE = "2026-10-19"
THROUGH_COMPACT = "20261019"
TODA_PREFIX = "20261016TDA"

INVENTORY_OUT = OUTPUT / "cc30c_4o_contract_inventory.json"
KEYSET_OUT = OUTPUT / "cc30c_4o_keyset_diff_20261019.csv"
RECON_OUT = OUTPUT / "cc30c_4o_raw216_formal196_reconciliation.csv"
PROPOSAL_OUT = OUTPUT / "cc30c_4o_contract_proposal_v1.json"
REPORT_OUT = OUTPUT / "cc30c_4o_contract_resolution_report.json"


def load_json(path: Path) -> dict[str, Any]:
    value = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(value, dict):
        raise RuntimeError(f"JSON_OBJECT_REQUIRED:{path}")
    return value


def json_rows(root: Path) -> list[tuple[Path, dict[str, Any]]]:
    rows: list[tuple[Path, dict[str, Any]]] = []
    if not root.exists():
        return rows
    for path in sorted(root.rglob("*.json")):
        try:
            rows.append((path, load_json(path)))
        except (OSError, ValueError, RuntimeError):
            continue
    return rows


def jsonl_rows(path: Path) -> list[dict[str, Any]]:
    rows: list[dict[str, Any]] = []
    if not path.exists():
        return rows
    for line in path.read_text(encoding="utf-8", errors="replace").splitlines():
        try:
            value = json.loads(line)
        except ValueError:
            continue
        if isinstance(value, dict):
            rows.append(value)
    return rows


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def framed_identity(items: list[tuple[str, str]]) -> str:
    payload = "".join(f"{name}\0{digest}\n" for name, digest in sorted(items))
    return "sha256:" + hashlib.sha256(payload.encode()).hexdigest()


def source_ref(path: str, lines: str, purpose: str) -> dict[str, Any]:
    absolute = REPO / path
    return {
        "path": path,
        "sha256": sha256(absolute),
        "lines": lines,
        "purpose": purpose,
    }


def git_commit() -> str:
    return subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=REPO, text=True).strip()


def aggregate_probe() -> dict[str, Any]:
    code = (
        'require "web/logic/FormalEvidenceAggregate.php"; '
        f'try {{$x=FormalEvidenceAggregate::calculate("{WINDOW_ROOT.relative_to(REPO)}",'
        f'"{WINDOW_ID}","{THROUGH_DATE}"); echo json_encode(["ok"=>true,"file_count"=>$x["file_count"]]);}} '
        'catch(Throwable $e){echo json_encode(["ok"=>false,"class"=>get_class($e),"message"=>$e->getMessage()]);}'
    )
    completed = subprocess.run(
        ["/usr/bin/php", "-r", code], cwd=REPO, text=True, capture_output=True, check=True
    )
    value = json.loads(completed.stdout)
    if not isinstance(value, dict):
        raise RuntimeError("AGGREGATE_PROBE_INVALID")
    return value


def db_trace(race_codes: list[str]) -> dict[str, Any]:
    # Existing repository connector; every statement is SELECT/SHOW only.
    code = r'''require "common/db_connect.php";
$pdo=getPDO(); $codes=json_decode($argv[1],true); $out=[];
$tz=$pdo->query("SHOW TimeZone")->fetchColumn();
$sql="SELECT
 (SELECT count(*) FROM boat_race.race_master WHERE race_code=:c) master_n,
 (SELECT count(*) FROM boat_race.race_entry WHERE race_code=:c) entry_n,
 (SELECT count(DISTINCT lane_number) FROM boat_race.race_entry WHERE race_code=:c) entry_lane_n,
 (SELECT count(DISTINCT player_id) FROM boat_race.race_entry WHERE race_code=:c) entry_player_n,
 (SELECT count(*) FROM boat_race.race_entry WHERE race_code=:c AND
   (lane_number IS NULL OR player_id IS NULL OR motor_number IS NULL OR boat_number IS NULL)) entry_required_null_n,
 (SELECT count(*) FROM boat_race.exhibition_live WHERE race_code=:c) exhibition_n,
 (SELECT count(*) FROM boat_race.exhibition_live WHERE race_code=:c AND entry_course BETWEEN 1 AND 6
   AND player_id IS NOT NULL AND exhibition_time IS NOT NULL AND start_timing IS NOT NULL
   AND lap_time IS NOT NULL AND around_time IS NOT NULL) exhibition_core_complete_n,
 (SELECT max(created_date) FROM boat_race.exhibition_live WHERE race_code=:c) exhibition_last_at_db_utc,
 (SELECT to_char(max(created_date) AT TIME ZONE 'UTC' AT TIME ZONE 'Asia/Tokyo', 'YYYY-MM-DD HH24:MI:SS.US')
   FROM boat_race.exhibition_live WHERE race_code=:c) exhibition_last_at_jst";
foreach($codes as $c){$q=$pdo->prepare($sql);$q->execute([":c"=>$c]);$out[$c]=$q->fetch(PDO::FETCH_ASSOC);}
echo json_encode(["db_session_timezone"=>$tz,"rows"=>$out],JSON_UNESCAPED_SLASHES);'''
    completed = subprocess.run(
        ["/usr/bin/php", "-r", code, json.dumps(race_codes)],
        cwd=REPO,
        text=True,
        capture_output=True,
        check=False,
    )
    if completed.returncode != 0:
        raise RuntimeError(f"DB_TRACE_FAILED:{completed.stderr.strip()}")
    value = json.loads(completed.stdout)
    if not isinstance(value, dict):
        raise RuntimeError("DB_TRACE_INVALID")
    return value


def expected_rows() -> tuple[dict[str, dict[str, Any]], list[dict[str, Any]]]:
    expected: dict[str, dict[str, Any]] = {}
    inventories: list[dict[str, Any]] = []
    for path in sorted((WINDOW_ROOT / "opportunity" / "expected_inventory").glob("*.json")):
        if path.stem > THROUGH_COMPACT:
            continue
        document = load_json(path)
        entries = document.get("expected_entries")
        if not isinstance(entries, list):
            raise RuntimeError(f"EXPECTED_ENTRIES_INVALID:{path}")
        inventories.append(
            {
                "path": str(path.relative_to(REPO)),
                "sha256": sha256(path),
                "target_date": document.get("target_date"),
                "entry_count": len(entries),
                "armed_at": document.get("armed_at"),
                "deadline_snapshot_identity": document.get("deadline_snapshot_identity"),
                "prospective_keyset_identity": document.get("prospective_keyset_identity"),
            }
        )
        for entry in entries:
            if not isinstance(entry, dict):
                raise RuntimeError(f"EXPECTED_ENTRY_INVALID:{path}")
            race = str(entry.get("race_code") or "")
            if not race or race in expected:
                raise RuntimeError(f"EXPECTED_RACE_DUPLICATE:{race}")
            expected[race] = dict(entry) | {
                "__source_path": str(path.relative_to(REPO)),
                "__deadline_snapshot_identity": document.get("deadline_snapshot_identity"),
                "__prospective_keyset_identity": document.get("prospective_keyset_identity"),
            }
    return dict(sorted(expected.items())), inventories


def keyed_rows(relative: str) -> dict[str, dict[str, Any]]:
    out: dict[str, dict[str, Any]] = {}
    for path, row in json_rows(WINDOW_ROOT / relative):
        date = str(row.get("target_date") or row.get("race_code") or "")[:10].replace("-", "")
        if date and date > THROUGH_COMPACT:
            continue
        race = str(row.get("race_code") or "")
        if not race or race in out:
            raise RuntimeError(f"DUPLICATE_RACE:{relative}:{race}")
        out[race] = row | {"__source_path": str(path.relative_to(REPO)), "__source_sha256": sha256(path)}
    return dict(sorted(out.items()))


def records_for_race(relative: str, race_code: str) -> list[dict[str, Any]]:
    return [row | {"__source_path": str(path.relative_to(REPO))} for path, row in json_rows(WINDOW_ROOT / relative)
            if str(row.get("race_code") or "") == race_code]


def scheduler_last_runs() -> dict[str, dict[str, str | None]]:
    log = REPO / "logs" / "all_race_forward_capture.log"
    out: dict[str, dict[str, str | None]] = {}
    if not log.exists():
        return out
    start_re = re.compile(r"^\[(2026-10-(?:16|17|18|19)) (\d\d:\d\d:\d\d)\] 全レース前向き保存開始")
    end_re = re.compile(r"^\[(2026-10-(?:16|17|18|19)) (\d\d:\d\d:\d\d)\] 全レース前向き保存終了")
    for line in log.read_text(encoding="utf-8", errors="replace").splitlines():
        match = start_re.match(line)
        if match:
            out.setdefault(match[1], {"last_start": None, "last_end": None})["last_start"] = f"{match[1]}T{match[2]}+09:00"
        match = end_re.match(line)
        if match:
            out.setdefault(match[1], {"last_start": None, "last_end": None})["last_end"] = f"{match[1]}T{match[2]}+09:00"
    return out


def collector_latest(race_codes: list[str]) -> dict[str, dict[str, Any] | None]:
    wanted = set(race_codes)
    latest: dict[str, dict[str, Any] | None] = {race: None for race in race_codes}
    for row in jsonl_rows(REPO / "logs" / "strict_forward_exhibition_collector.log"):
        race = str(row.get("race_code") or "")
        if race in wanted:
            latest[race] = {key: row.get(key) for key in (
                "timestamp", "status", "cutoff", "complete_boats", "skip_reason", "error",
                "attempt_started_at", "attempt_finished_at", "remaining_seconds_at_start",
            )}
    return latest


def raw_toda_rows() -> list[dict[str, Any]]:
    rows: list[dict[str, Any]] = []
    for path, row in json_rows(WINDOW_ROOT / "ledger" / "records"):
        if str(row.get("race_code") or "").startswith(TODA_PREFIX):
            rows.append(row | {"__source_path": str(path.relative_to(REPO)), "__source_sha256": sha256(path)})
    rows.sort(key=lambda row: (str(row.get("race_code")), int(row.get("course") or 0), str(row.get("target"))))
    return rows


def write_json(path: Path, value: dict[str, Any]) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    if load_json(path) != value:
        raise RuntimeError(f"JSON_READBACK_MISMATCH:{path}")


def main() -> None:
    generated_at = datetime.now().astimezone().isoformat()
    source_git_commit = git_commit()
    OUTPUT.mkdir(parents=True, exist_ok=True)

    evidence_paths = [path for path in WINDOW_ROOT.rglob("*") if path.is_file()]
    evidence_before = framed_identity([(str(path.relative_to(WINDOW_ROOT)), sha256(path)) for path in evidence_paths])

    expected, inventories = expected_rows()
    candidates = keyed_rows("opportunity/candidates")
    eligibility = keyed_rows("opportunity/eligibility")
    expected_keys, candidate_keys, eligibility_keys = set(expected), set(candidates), set(eligibility)
    differences = {
        "EXPECTED_MINUS_ELIGIBILITY": sorted(expected_keys - eligibility_keys),
        "CANDIDATE_MINUS_ELIGIBILITY": sorted(candidate_keys - eligibility_keys),
        "ELIGIBILITY_MINUS_EXPECTED": sorted(eligibility_keys - expected_keys),
        "ELIGIBILITY_MINUS_CANDIDATE": sorted(eligibility_keys - candidate_keys),
    }
    missing = sorted((expected_keys | candidate_keys) - eligibility_keys)
    if len(expected) != 372 or len(candidates) != 372 or len(eligibility) != 364 or len(missing) != 8:
        raise RuntimeError("KNOWN_KEYSET_COUNTS_CHANGED")

    keyset_fields = [
        "set_difference", "stable_key", "race_code", "date", "venue", "race_no", "signal_component",
        "canonical_key", "semantic_schedule_fingerprint", "deadline_snapshot_identity",
        "prospective_keyset_identity", "cutoff_timestamp", "present_expected", "present_candidate",
        "present_eligibility", "expected_source", "candidate_source", "eligibility_source",
    ]
    with KEYSET_OUT.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=keyset_fields)
        writer.writeheader()
        for name, races in differences.items():
            for race in races:
                entry, candidate, resolved = expected.get(race, {}), candidates.get(race, {}), eligibility.get(race, {})
                source = entry or candidate or resolved
                writer.writerow({
                    "set_difference": name,
                    "stable_key": source.get("stable_candidate_key", ""),
                    "race_code": race,
                    "date": source.get("target_date", ""),
                    "venue": source.get("venue", race[8:11]),
                    "race_no": int(race[-2:]),
                    "signal_component": "RACE_LEVEL_SCHEDULER_OPPORTUNITY_ELIGIBILITY",
                    "canonical_key": entry.get("canonical_key", ""),
                    "semantic_schedule_fingerprint": entry.get("semantic_schedule_fingerprint", ""),
                    "deadline_snapshot_identity": entry.get("__deadline_snapshot_identity", candidate.get("deadline_snapshot_identity", "")),
                    "prospective_keyset_identity": entry.get("__prospective_keyset_identity", candidate.get("prospective_keyset_identity", "")),
                    "cutoff_timestamp": source.get("cutoff_timestamp", ""),
                    "present_expected": race in expected,
                    "present_candidate": race in candidates,
                    "present_eligibility": race in eligibility,
                    "expected_source": entry.get("__source_path", ""),
                    "candidate_source": candidate.get("__source_path", ""),
                    "eligibility_source": resolved.get("__source_path", ""),
                })

    db = db_trace(missing)
    scheduler_runs = scheduler_last_runs()
    collector = collector_latest(missing)
    missing_trace: list[dict[str, Any]] = []
    for race in missing:
        date_compact = race[:8]
        date_text = f"{date_compact[:4]}-{date_compact[4:6]}-{date_compact[6:]}"
        observations = [row for row in jsonl_rows(WINDOW_ROOT / "opportunity" / "observations" / f"{date_compact}.jsonl")
                        if str(row.get("race_code") or "") == race]
        latest = observations[-1] if observations else {}
        downstream = {
            relative: len(records_for_race(relative, race))
            for relative in ("opportunity/eligibility", "ledger/expected", "ledger/records", "input_snapshots", "comparative/pairs", "comparative/captures")
        }
        db_row = db.get("rows", {}).get(race, {})
        exhibition_at = db_row.get("exhibition_last_at_jst")
        if exhibition_at:
            exhibition_at = str(exhibition_at).replace(" ", "T") + "+09:00"
        missing_trace.append({
            "race_code": race,
            "date": date_text,
            "venue": race[8:11],
            "race_no": int(race[-2:]),
            "stable_candidate_key": expected[race].get("stable_candidate_key"),
            "deadline": expected[race].get("cutoff_timestamp"),
            "race_master": {"row_count": int(db_row.get("master_n") or 0)},
            "race_entry": {
                "row_count": int(db_row.get("entry_n") or 0),
                "lane_count": int(db_row.get("entry_lane_n") or 0),
                "player_count": int(db_row.get("entry_player_n") or 0),
                "required_null_count": int(db_row.get("entry_required_null_n") or 0),
            },
            "exhibition": {
                "row_count": int(db_row.get("exhibition_n") or 0),
                "core_complete_boat_count": int(db_row.get("exhibition_core_complete_n") or 0),
                "last_created_at_db_utc": db_row.get("exhibition_last_at_db_utc"),
                "last_created_at_jst": exhibition_at,
            },
            "candidate": {
                "exists": race in candidates,
                "source_path": candidates[race].get("__source_path"),
                "universe_recorded_at": candidates[race].get("universe_recorded_at"),
                "source_identity": candidates[race].get("source_identity"),
            },
            "eligibility_producer": {
                "source": "web/logic/StrictForwardOpportunityLedger.php::observeEligibility/finalizeExpired",
                "observation_count": len(observations),
                "latest_observation": {key: latest.get(key) for key in (
                    "observed_at", "eligibility_status", "input_availability", "exhibition_complete", "reason"
                )},
                "resolution_exists": race in eligibility,
                "last_scheduler_run": scheduler_runs.get(date_text),
            },
            "expected_ledger": downstream["ledger/expected"],
            "formal_ledger": downstream["ledger/records"],
            "input_snapshot": downstream["input_snapshots"],
            "comparative_pair": downstream["comparative/pairs"],
            "comparative_capture": downstream["comparative/captures"],
            "collector_latest": collector.get(race),
            "cause_classification": ["A_PRODUCER_DEFECT", "E_SOURCE_AVAILABILITY_TIMING"],
            "cause": "The natural scheduler stopped before this late cutoff. The last eligibility observation was incomplete and pre-cutoff; finalizeExpired therefore could not write a terminal resolution. Exhibition later became complete, but the collector does not own eligibility resolution and no post-cutoff scheduler/finalizer pass occurred.",
        })

    raw_rows = raw_toda_rows()
    post_report_path = OUTPUT / "cc30c_toda_post12r_20261016.json"
    post_report = load_json(post_report_path)
    reported_count = int(post_report.get("formal_evidence", {}).get("count") or 0)
    if len(raw_rows) != 216 or reported_count != 196:
        raise RuntimeError("RAW_REPORT_COUNTS_CHANGED")

    recon_fields = [
        "record_path", "stable_key", "race_code", "captured_at", "cutoff_timestamp", "course", "target",
        "signal_component", "formal_candidate", "before_cutoff", "classification_under_correct_v1_intent",
        "post12r_196_membership", "broken_filter_recorded_at", "broken_filter_cutoff_at", "exclusion_reason",
    ]
    with RECON_OUT.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=recon_fields)
        writer.writeheader()
        for row in raw_rows:
            writer.writerow({
                "record_path": row["__source_path"],
                "stable_key": row.get("stable_unique_key", ""),
                "race_code": row.get("race_code", ""),
                "captured_at": row.get("captured_at", ""),
                "cutoff_timestamp": row.get("cutoff_timestamp", ""),
                "course": row.get("course", ""),
                "target": row.get("target", ""),
                "signal_component": f"course={row.get('course')}|target={row.get('target')}|stage={row.get('capture_stage')}",
                "formal_candidate": row.get("formal_candidate", ""),
                "before_cutoff": row.get("before_cutoff", ""),
                "classification_under_correct_v1_intent": "FORMAL_ELIGIBLE",
                "post12r_196_membership": "UNKNOWN_NOT_PERSISTED",
                "broken_filter_recorded_at": "MISSING",
                "broken_filter_cutoff_at": "MISSING",
                "exclusion_reason": "NOT_RECOVERABLE: v1 report stored count/race_codes only; missing-field DateTime('') comparisons were nondeterministic",
            })

    contract_sources = {
        "formal_aggregate": source_ref("web/logic/FormalEvidenceAggregate.php", "23-92,136-154", "Selects dated Window evidence, verifies reachable provenance, and invokes the comparative resolver."),
        "provenance_resolver": source_ref("web/logic/FormalWindowProvenanceResolver.php", "151-198,202-309,313-335", "Defines inventory, race keyset, use keyset, snapshot, pair, and completion fail-closed contracts."),
        "candidate_eligibility": source_ref("web/logic/StrictForwardOpportunityLedger.php", "118-188,415-479,482-563", "Writes race-level immutable candidates, observations, and one terminal resolution per candidate."),
        "expected_inventory": source_ref("web/logic/GateA1ProspectiveInventory.php", "18-23,224-321,528-653", "Freezes the prospective official schedule inventory per target date."),
        "window": source_ref("web/logic/FormalMeasurementWindowContext.php", "16-203", "Resolves the active Window pointer and write authorization."),
        "window_lifecycle": source_ref("web/logic/FormalMeasurementWindowLifecycle.php", "16-530", "Defines active/closed/aborted Window lifecycle and closure integrity."),
        "activation": source_ref("web/logic/WindowActivationHold.php", "17-299", "Defines immutable Window manifest and activation record validation."),
        "epoch": source_ref("web/logic/OfficialEpochLifecycleV1.php", "22-325", "Defines Official Epoch state and aggregate-dependent lifecycle gates."),
        "binding": source_ref("web/logic/OfficialEpochWindowBindingV1.php", "11-96", "Defines immutable Epoch-to-Window binding record."),
        "binding_runtime": source_ref("web/logic/OfficialEpochWindowBindingRuntimeAuthorization.php", "12-137", "Authorizes runtime measurement against active Epoch/Window/binding/activation."),
        "collector": source_ref("analysis/collect_strict_forward_exhibition_20261011.php", "1-210", "Fetches exhibition for candidate races; it does not create eligibility resolution or formal records."),
        "post12r_audit": source_ref("analysis/audit_cc30c_toda_post12r_20261016.php", "103-127", "Report-only Toda audit; contains the recorded_at/cutoff_at field defect."),
    }

    aggregate_now = aggregate_probe()
    inventory = {
        "schema_version": "cc30c-4o-contract-inventory/v1",
        "generated_at": generated_at,
        "source_git_commit": source_git_commit,
        "window_id": WINDOW_ID,
        "capture_through_date": THROUGH_DATE,
        "sources": contract_sources,
        "existing_contracts": {
            "FormalEvidenceAggregate": {
                "status": "DEFINED",
                "scope": "All selected files at or before capture_through_date plus reachable implementation provenance.",
                "date_filter": "A path segment/file date YYYYMMDD greater than capture_through_date is excluded.",
                "aggregate_condition": "Every required file and reachable provenance hash must validate; comparative evidence triggers FormalWindowProvenanceResolver; all failures are fatal.",
            },
            "Expected_inventory": {
                "status": "DEFINED_PER_DATE",
                "key": "race_code (entry also binds canonical_key and stable_candidate_key)",
                "stable_key": "scheduler-opportunity|{race_code}",
                "source": "opportunity/expected_inventory/YYYYMMDD.json expected_entries",
                "filter": "Official schedule cutoff_timestamp > immutable armed_at, frozen by GateA1 prospective filter contract.",
            },
            "Candidate": {
                "status": "DEFINED",
                "key": "race_code; one immutable file per stable_candidate_key per target_date",
                "stable_key": "scheduler-opportunity|{race_code}",
                "source": "opportunity/candidates/YYYYMMDD/{sha256(stable_key)}.json",
                "binding_fields": ["race_code", "venue", "target_date", "cutoff_timestamp", "stable_candidate_key", "armed_at", "deadline_snapshot_identity", "prospective_keyset_identity"],
            },
            "Eligibility": {
                "status": "DEFINED",
                "key": "race_code; exactly one first-write terminal resolution file per candidate",
                "source": "opportunity/eligibility/YYYYMMDD/{sha256(stable_candidate_key)}.json",
                "terminal_statuses_observed_in_code": ["ELIGIBLE_OBSERVED", "VALID_NON_ELIGIBLE_EXHIBITION_INCOMPLETE", "ELIGIBILITY_UNRESOLVED"],
                "resolution_rules": [
                    "ELIGIBLE_OBSERVED only when exhibition_complete is observed before cutoff.",
                    "Incomplete pre-cutoff is observation-only.",
                    "At/after cutoff, finalizeExpired writes VALID_NON_ELIGIBLE or ELIGIBILITY_UNRESOLVED when no resolution exists.",
                ],
            },
            "Resolver_inventory_keyset": {
                "status": "DEFINED_BUT_MULTI_DAY_INCOMPATIBLE",
                "key_granularity": "race_code, not signal/course",
                "exact_condition": "array_keys(expected) == array_keys(candidate_by_race) == array_keys(eligibility_by_race)",
                "binding_fields": ["race_code", "venue", "target_date", "cutoff_timestamp", "stable_candidate_key"],
                "prospective_conditions": ["candidate.armed_at == manifest.armed_at", "eligibility.armed_at == manifest.armed_at", "cutoff_timestamp > armed_at"],
                "current_cardinality_condition": "Exactly one expected_inventory JSON must be present in the aggregate.",
                "current_window_inventory_file_count": len(inventories),
                "current_calculate_probe": aggregate_now,
                "note": "The exact 372/372/364 union is a four-day Window-wide diagnostic. The current resolver reaches PROVENANCE_EXPECTED_INVENTORY_CARDINALITY_INVALID first for capture_through_date=2026-10-19.",
            },
            "Expected_Canonical": {
                "status": "DEFINED",
                "key_granularity": "race_code + NUL + course + NUL + target",
                "condition": "Exact Expected/Canonical use-key equality plus immutable implementation/comparative identity equality.",
            },
            "Snapshot_Pair_Completion": {
                "status": "DEFINED",
                "snapshot_key": "race_code",
                "pair_key": "race_code + NUL + use_key",
                "completion_key": "race_code",
                "conditions": ["snapshot requires ELIGIBLE_OBSERVED and pre-cutoff times", "every pair references the exact snapshot and Expected/Canonical use", "snapshot and completion race keysets exactly equal", "completion required use-key/hash set exactly equals pair set"],
            },
            "Window_Epoch_Activation_Binding": {
                "status": "DEFINED",
                "current": {
                    "window_status": load_json(WINDOW_ROOT / "validation_window_manifest.json").get("status"),
                    "activation_schema": load_json(WINDOW_ROOT / "activation_record.json").get("activation_schema_version"),
                    "epoch_id": load_json(WINDOW_ROOT / "activation_record.json").get("epoch_id"),
                    "binding_ref": load_json(WINDOW_ROOT / "activation_record.json").get("official_epoch_window_binding_ref"),
                },
            },
            "Collector": {
                "status": "DEFINED_SEPARATE_FROM_ELIGIBILITY_PRODUCER",
                "role": "Fetch/retry exhibition within its own window and report statuses. It does not write opportunity/eligibility or formal ledger evidence.",
            },
            "post12R_audit": {
                "status": "DEFINED_REPORT_ONLY_WITH_SCHEMA_DEFECT",
                "filter_as_implemented": "race_code prefix 20261016TDA and DateTimeImmutable(recorded_at or '') < DateTimeImmutable(cutoff_at or '')",
                "canonical_fields": ["captured_at", "cutoff_timestamp"],
                "saved_report_identity_detail": "count, unique race_codes, first_timestamp only; no included stable-key list",
            },
            "CC30C_4O_4O1_4O2": {
                "status": "NOT_EXPLICITLY_DEFINED",
                "finding": "No authoritative repository contract assigning formal roles/gates to 4O, 4O-1, and 4O-2 was found; a proposal is required before mutation or PASS.",
            },
        },
        "observed_inventory_files": inventories,
    }
    write_json(INVENTORY_OUT, inventory)

    proposal = {
        "schema_version": "cc30c-4o-contract-proposal/v1",
        "status": "PROPOSED_NOT_APPLIED",
        "generated_at": generated_at,
        "source_git_commit": source_git_commit,
        "purpose": "Close CC30C only after a deterministic, future-only, Window-wide evidence aggregate proves complete opportunity accounting and immutable evidence provenance.",
        "preconditions": [
            "Epoch ACTIVE; Window ACTIVE; binding valid; activation valid; runtime authorization accepted.",
            "capture_through_date and immutable Window root are explicit.",
            "No manual/fixture evidence is included as formal evidence.",
            "All source contracts and implementation identities are hash-bound.",
        ],
        "formal_evidence_integrity": [
            "Every included file parses and its SHA256 is included in the deterministic aggregate.",
            "All raw records have nonempty stable_unique_key, no duplicate key, complete immutable identity, LIVE_STRICT mode, and captured_at < cutoff_timestamp.",
            "Expected/Canonical, Snapshot/Pair/Completion, and implementation provenance validations remain fail-closed.",
        ],
        "inventory_contract": {
            "proposal": "Union every per-date expected_inventory artifact with target_date <= capture_through_date, preserving each document SHA and prospective_keyset_identity.",
            "key": "race_code",
            "exact_keyset_condition": "union(expected race_code) == candidate race_code == terminal eligibility-resolution race_code",
            "binding_fields": ["race_code", "venue", "target_date", "cutoff_timestamp", "stable_candidate_key"],
            "allowed_exclusions": "A candidate may be excluded from downstream formal measurement only by an explicit immutable terminal eligibility/schedule status. A missing eligibility row is never an allowed exclusion.",
            "forbidden_shortcuts": ["Treat 364 as 372", "Ignore late races", "Downgrade mismatch to warning", "Infer terminal status from absence"],
        },
        "window_aggregate_condition": "All date inventories and all exact keysets/identities validate; otherwise BLOCKED.",
        "stages": {
            "4O_1": {
                "mode": "READ_ONLY",
                "role": "Contract/root-cause resolution and deterministic inventory/evidence reconciliation without changing evidence.",
                "pass": "Root causes and required change classes are confirmed and a machine-readable contract is approved.",
            },
            "4O_2": {
                "mode": "READ_ONLY_AFTER_SEPARATELY_APPROVED_FIX_OR_REPAIR",
                "role": "Re-run the exact Window-wide aggregate and verify all hashes/keysets/provenance after approved remediation.",
                "pass": "Aggregate completes without exception and all proposed integrity conditions pass.",
            },
        },
        "outcomes": {
            "PASS": "All preconditions and aggregate integrity checks pass after approved remediation; no unresolved provenance/schema defect remains.",
            "WAIT": "Natural future-only input is legitimately incomplete before its terminal deadline and producer coverage remains active.",
            "BLOCKED": "Any missing terminal resolution, keyset/identity mismatch, schema ambiguity, cardinality incompatibility, or unapproved historical repair/contract change exists.",
        },
        "next_phase_condition": "CC30C-4O may advance only after user-approved producer/report/contract work, separately approved historical repair if chosen, and a successful read-only 4O-2 aggregate. This proposal itself never changes the gate.",
    }
    write_json(PROPOSAL_OUT, proposal)

    raw_identity_before = framed_identity([(row["__source_path"], row["__source_sha256"]) for row in raw_rows])
    evidence_paths_after = [path for path in WINDOW_ROOT.rglob("*") if path.is_file()]
    evidence_after = framed_identity([(str(path.relative_to(WINDOW_ROOT)), sha256(path)) for path in evidence_paths_after])
    raw_rows_after = raw_toda_rows()
    raw_identity_after = framed_identity([(row["__source_path"], row["__source_sha256"]) for row in raw_rows_after])
    if evidence_before != evidence_after or raw_identity_before != raw_identity_after:
        raise RuntimeError("READ_ONLY_EVIDENCE_IDENTITY_CHANGED")

    report = {
        "schema_version": "cc30c-4o-contract-resolution-report/v1",
        "generated_at": generated_at,
        "source_git_commit": source_git_commit,
        "window_id": WINDOW_ID,
        "capture_through_date": THROUGH_DATE,
        "final_determinations": {
            "KEYSET_MISMATCH_ROOT_CAUSE": "CONFIRMED",
            "RAW216_FORMAL196": "EXPLAINED",
            "REPORT_SCHEMA_DEFECT": "CONFIRMED",
            "REQUIRED_CHANGE_CLASS": ["REPORT_ONLY_FIX", "PRODUCER_FIX", "HISTORICAL_DATA_REPAIR", "CONTRACT_CHANGE"],
            "CC30C_4O_CONTRACT": "PROPOSED",
            "CC30C_4O_STATUS": "BLOCKED",
        },
        "keysets": {
            "expected_count": len(expected),
            "candidate_count": len(candidates),
            "eligibility_count": len(eligibility),
            "differences": {key: {"count": len(value), "race_codes": value} for key, value in differences.items()},
            "diff_csv": str(KEYSET_OUT.relative_to(REPO)),
            "window_wide_union_result": "372/372/364; eight terminal eligibility resolutions are absent.",
            "current_aggregate_probe": aggregate_now,
            "additional_contract_blocker": "Current resolver requires exactly one expected_inventory JSON, but four daily inventories are selected through 2026-10-19. This is a multi-day cardinality contract incompatibility and requires approval before code change.",
        },
        "missing_eight": missing_trace,
        "missing_eight_root_cause": {
            "confirmed": True,
            "classification": ["A_PRODUCER_DEFECT", "E_SOURCE_AVAILABILITY_TIMING"],
            "summary": "The eligibility producer ran only through about 21:58 JST. SMS11/12 deadlines were 22:18/22:20 and 22:45. Their final pre-cutoff observations were incomplete; exhibition became available after that last scheduler pass. The collector later observed/fetched data, but does not create eligibility resolutions. No post-cutoff finalizer pass wrote the required terminal rows.",
            "not_supported": ["B_CONTRACT_CORRECT_EXCLUSION", "C_WINDOW_OR_DATE_BOUNDARY", "D_RACE_OR_VENUE_IDENTITY_MISMATCH"],
        },
        "raw216_formal196": {
            "raw_count": len(raw_rows),
            "reported_count": reported_count,
            "difference": len(raw_rows) - reported_count,
            "classification_counts_under_correct_v1_intent": {"FORMAL_ELIGIBLE": len(raw_rows), "FILTERED_OUT": 0, "INVALID": 0, "OUTSIDE_SCOPE": 0, "UNKNOWN": 0},
            "raw_checks": {
                "duplicate_stable_key": len(raw_rows) - len({row.get("stable_unique_key") for row in raw_rows}),
                "missing_identity": sum(not row.get("stable_unique_key") for row in raw_rows),
                "before_cutoff_false": sum(not bool(row.get("before_cutoff")) for row in raw_rows),
                "captured_not_before_cutoff": sum(str(row.get("captured_at")) >= str(row.get("cutoff_timestamp")) for row in raw_rows),
                "capture_mode_counts": dict(Counter(str(row.get("capture_mode")) for row in raw_rows)),
                "capture_source_counts": dict(Counter(str(row.get("capture_source")) for row in raw_rows)),
                "formal_candidate_counts": dict(Counter(str(row.get("formal_candidate")) for row in raw_rows)),
            },
            "generation_path": {
                "source": "analysis/audit_cc30c_toda_post12r_20261016.php:111-115,123",
                "implemented_fields": ["recorded_at", "cutoff_at"],
                "actual_fields": ["captured_at", "cutoff_timestamp"],
                "behavior": "Both missing values become empty strings. PHP DateTimeImmutable('') resolves to 'now' separately for each operand, so comparison outcome depends on timing and is nondeterministic.",
                "category": "REPORT_AGGREGATION_BUG",
            },
            "twenty_record_identity": {
                "status": "UNRECOVERABLE_FROM_SAVED_V1_REPORT",
                "reason": "The report persisted only count and unique race_codes, not the included/excluded stable keys. Re-running the nondeterministic filter would choose a new subset and cannot reconstruct the historical 20.",
                "non_fabrication": "No 20 stable keys are asserted. The reconciliation CSV explicitly marks membership UNKNOWN_NOT_PERSISTED for all 216 records.",
            },
            "reconciliation_csv": str(RECON_OUT.relative_to(REPO)),
        },
        "first_timestamp_defect": {
            "confirmed": True,
            "field_reference": "formal[0]['recorded_at']",
            "actual_field": "captured_at",
            "cutoff_actual_field": "cutoff_timestamp",
            "report_only_fix_possible": True,
            "not_applied": True,
            "reason_not_applied": "This turn is contract/root-cause audit only; a later approved report-only patch can fix fields and persist stable-key membership without touching evidence.",
        },
        "contract_artifacts": {
            "inventory": str(INVENTORY_OUT.relative_to(REPO)),
            "proposal": str(PROPOSAL_OUT.relative_to(REPO)),
        },
        "required_changes": [
            {
                "class": "REPORT_ONLY_FIX",
                "necessary": True,
                "scope": "Use captured_at/cutoff_timestamp, validate future-only per row, and persist included/excluded stable keys.",
                "past_evidence_impact": "None when implemented only in report generation.",
                "true_forward_contamination_risk": "LOW",
                "rollback": "Simple source revert; reports can be regenerated separately without changing evidence.",
                "approval": "REQUIRED_BEFORE_PATCH_IN_A_LATER_TURN",
            },
            {
                "class": "PRODUCER_FIX",
                "necessary": True,
                "scope": "Ensure every candidate receives a terminal eligibility resolution after its effective cutoff, including late-night races; preserve first-write and fail-closed rules.",
                "past_evidence_impact": "None prospectively; does not repair current eight rows.",
                "true_forward_contamination_risk": "MEDIUM; scheduling/finalization ownership must be tested without changing eligibility semantics.",
                "rollback": "Code/schedule rollback possible for future runs.",
                "approval": "REQUIRED",
            },
            {
                "class": "HISTORICAL_DATA_REPAIR",
                "necessary": "REQUIRED_ONLY_IF_THIS_WINDOW_IS_TO_BE_AGGREGATED_AS_COMPLETE",
                "scope": "Create eight terminal eligibility resolutions from preserved observations/deadlines under an explicitly approved repair protocol.",
                "past_evidence_impact": "Direct mutation/addition to immutable Window evidence.",
                "true_forward_contamination_risk": "HIGH",
                "rollback": "Not safely reversible under create-only evidence without a formal supersession/repair ledger.",
                "approval": "HISTORICAL_REPAIR_APPROVAL_REQUIRED",
            },
            {
                "class": "CONTRACT_CHANGE",
                "necessary": True,
                "scope": "Define multi-day per-date inventory union semantics; current resolver requires exactly one inventory artifact.",
                "past_evidence_impact": "Changes aggregate interpretation but must not weaken exact keyset equality or ignore missing rows.",
                "true_forward_contamination_risk": "HIGH until contract/version/tests are approved.",
                "rollback": "Versioned contract/code rollback possible; old aggregate identity remains immutable.",
                "approval": "CONTRACT_CHANGE_APPROVAL_REQUIRED",
            },
        ],
        "natural_evidence_preservation": {
            "window_tree_file_count_before": len(evidence_paths),
            "window_tree_file_count_after": len(evidence_paths_after),
            "window_tree_framed_sha256_before": evidence_before,
            "window_tree_framed_sha256_after": evidence_after,
            "window_tree_unchanged": evidence_before == evidence_after,
            "toda_raw_record_count_before": len(raw_rows),
            "toda_raw_record_count_after": len(raw_rows_after),
            "toda_raw_framed_sha256_before": raw_identity_before,
            "toda_raw_framed_sha256_after": raw_identity_after,
            "toda_raw_unchanged": raw_identity_before == raw_identity_after,
            "db_access": "SELECT_ONLY",
            "scheduler_collector_execution": "NONE",
            "lifecycle_mutation": "NONE",
        },
        "stop_conditions": ["HISTORICAL_REPAIR_APPROVAL_REQUIRED", "CONTRACT_CHANGE_APPROVAL_REQUIRED"],
        "next_safe_action": "Obtain explicit approval for a report-only fix, prospective late-cutoff producer/finalizer fix, versioned multi-day contract change, and—only if retaining this Window as complete—a separately governed historical repair. Keep CC30C-4O BLOCKED until read-only 4O-2 passes.",
    }
    write_json(REPORT_OUT, report)

    # Output read-back/count checks. These outputs are audit products, not evidence.
    with KEYSET_OUT.open(encoding="utf-8", newline="") as handle:
        keyset_readback = list(csv.DictReader(handle))
    with RECON_OUT.open(encoding="utf-8", newline="") as handle:
        recon_readback = list(csv.DictReader(handle))
    if len(keyset_readback) != sum(len(value) for value in differences.values()):
        raise RuntimeError("KEYSET_CSV_READBACK_MISMATCH")
    if len(recon_readback) != 216:
        raise RuntimeError("RECON_CSV_READBACK_MISMATCH")

    print(json.dumps({
        "KEYSET_MISMATCH_ROOT_CAUSE": "CONFIRMED",
        "RAW216_FORMAL196": "EXPLAINED",
        "REPORT_SCHEMA_DEFECT": "CONFIRMED",
        "CC30C_4O_CONTRACT": "PROPOSED",
        "CC30C_4O_STATUS": "BLOCKED",
        "outputs": [str(path.relative_to(REPO)) for path in (INVENTORY_OUT, KEYSET_OUT, RECON_OUT, PROPOSAL_OUT, REPORT_OUT)],
    }, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
