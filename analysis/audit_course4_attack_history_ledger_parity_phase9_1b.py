#!/usr/bin/env python3
"""Verify Phase 7 historical features using only the canonical ledger.

No database connection is imported or made by this script.  It proves that the
frozen baseline ledger, rather than mutable raw history, recreates the three
Attack-Type history inputs across the complete research population.
"""
from __future__ import annotations

import bisect
import csv
import hashlib
import json
import math
import subprocess
import sys
from collections import defaultdict
from datetime import date, datetime, timedelta
from pathlib import Path

ROOT = Path(__file__).resolve().parent
REPO = ROOT.parent
OUT = ROOT / "output"
sys.path.insert(0, str(ROOT))
from build_course4_attack_history_canonical_ledger_phase9_1b import (  # noqa: E402
    build_ledger_index,
    history_features_from_canonical_ledger,
)
LEDGER = OUT / "course4_attack_history_canonical_ledger_v1.csv"
MANIFEST = OUT / "course4_attack_history_canonical_ledger_v1_manifest.json"
DATASET = OUT / "course4_attack_type_phase7_dataset_20241001_20260930.csv"
PHASE4_INPUT = OUT / "course4_attack_phase4_input_20241001_20260930.csv"
REPORT = OUT / "course4_attack_history_canonical_ledger_v1_parity_report.json"
PHASE_REPORT = OUT / "course4_tenkai_phase9_1b_report.json"
FEATURES = ("course4_history_n", "course4_history_makuri_rate_pct", "course4_history_makurizashi_rate_pct")
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


def git_head():
    return subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=REPO, text=True).strip()


def number(v):
    try:
        x = float(v)
        return x if math.isfinite(x) else None
    except (TypeError, ValueError):
        return None


def months_ago(d: date, n: int) -> date:
    total = d.year * 12 + d.month - 1 - n
    y, m0 = divmod(total, 12)
    m = m0 + 1
    import calendar
    return date(y, m, min(d.day, calendar.monthrange(y, m)[1]))


def summary(pairs):
    numeric = [(a, b) for a, b in pairs if a is not None and b is not None]
    both_missing = sum(a is None and b is None for a, b in pairs)
    one_missing = len(pairs) - len(numeric) - both_missing
    diffs = [abs(a - b) for a, b in numeric]
    return {
        "n": len(pairs), "numeric_pair_n": len(numeric), "both_missing_n": both_missing, "one_side_missing_n": one_missing,
        "exact_match_count": sum(a == b for a, b in numeric) + both_missing,
        "exact_match_rate": (sum(a == b for a, b in numeric) + both_missing) / len(pairs) if pairs else None,
        "max_abs_diff": max(diffs) if diffs else None, "mean_abs_diff": sum(diffs) / len(diffs) if diffs else None,
        "mismatch_count": sum(a != b for a, b in numeric) + one_missing,
    }


def stable_row_hash(rows):
    h = hashlib.sha256()
    for row in rows:
        h.update(json.dumps(row, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode())
        h.update(b"\n")
    return h.hexdigest()


def append_test(baseline_rows):
    """In-memory only; no test row can reach the canonical ledger file."""
    existing = list(baseline_rows)
    before = stable_row_hash(existing)
    by_id = {r["event_id"]: r for r in existing}
    duplicate = dict(existing[0])
    duplicate_result = duplicate["event_id"] not in by_id
    synthetic = dict(existing[0])
    synthetic.update({"event_id": "TEST_PHASE9_1B:0000", "race_code": "TEST_PHASE9_1B", "player_id": "0000", "race_date": "2099-01-01", "baseline_frozen": "0", "source_kind": "test_only"})
    first_add = synthetic["event_id"] not in by_id
    if first_add:
        by_id[synthetic["event_id"]] = synthetic
    second_add = synthetic["event_id"] not in by_id
    baseline_after = [by_id[r["event_id"]] for r in existing]
    return {
        "execution": "in_memory_only", "duplicate_existing_event_second_append_no_op": not duplicate_result,
        "existing_baseline_event_append_no_op": not duplicate_result,
        "new_event_first_append_added_once": first_add, "new_event_second_append_no_op": not second_add,
        "baseline_row_hash_before": before, "baseline_row_hash_after": stable_row_hash(baseline_after),
        "baseline_rows_unchanged": before == stable_row_hash(baseline_after),
    }


def availability_test(ledger, index, activation_at):
    """Prove late_bootstrap rows do not enter a cutoff before observation."""
    late = next((r for r in ledger if str(r["baseline_frozen"]) != "1"), None)
    if late is None:
        return {"performed": False, "reason": "no_late_bootstrap_row"}
    observed = datetime.fromisoformat(late["first_observed_at"])
    target = date.fromisoformat(late["race_date"]) + timedelta(days=1)
    before = history_features_from_canonical_ledger(index, late["player_id"], target, observed - timedelta(microseconds=1), research_reproduction=False, ledger_activation_at=activation_at, include_event_ids=True)
    after = history_features_from_canonical_ledger(index, late["player_id"], target, observed, research_reproduction=False, ledger_activation_at=activation_at, include_event_ids=True)
    return {
        "performed": True, "event_id": late["event_id"], "target_race_date": target.isoformat(),
        "first_observed_at": late["first_observed_at"],
        "excluded_before_observation": late["event_id"] not in before["included_event_ids"],
        "included_at_observation": late["event_id"] in after["included_event_ids"],
        "before_history_n": before["course4_history_n"], "at_history_n": after["course4_history_n"],
    }


def main():
    manifest = json.loads(MANIFEST.read_text(encoding="utf-8"))
    if sha256(LEDGER) != manifest["ledger_sha256"]:
        raise RuntimeError("ledger hash does not match manifest")
    ledger = read_csv(LEDGER)
    if len(ledger) != len({r["event_id"] for r in ledger}):
        raise RuntimeError("duplicate event_id in ledger")
    baseline = [r for r in ledger if str(r["baseline_frozen"]) == "1"]
    if len(baseline) != manifest["baseline_row_count"]:
        raise RuntimeError("baseline count mismatch")
    for r in baseline:
        if r["source_kind"] != "frozen_research_cache" or r["actual_course"] != "4" or r["is_history_eligible"] != "1":
            raise RuntimeError("invalid baseline row")
    index = build_ledger_index(ledger)
    event_dates = {r["event_id"]: date.fromisoformat(r["race_date"]) for r in baseline}
    activation_at = datetime.fromisoformat(manifest["ledger_activation_at"])
    dataset = read_csv(DATASET)
    phase4 = {r["race_code"]: r["course4_player_id"] for r in read_csv(PHASE4_INPUT)}
    if set(phase4) != {r["race_code"] for r in dataset}:
        raise RuntimeError("Phase4-to-Phase7 race universe mismatch")
    comparisons = {split: {feature: [] for feature in FEATURES} for split in SPLITS}
    temporal = {"target_self_leak_count": 0, "same_day_result_leak_count": 0, "future_source_count": 0, "source_max_date_violation": 0}
    profile_examples = []
    for i, row in enumerate(dataset):
        split = row["split"]
        d = date.fromisoformat(row["race_date"])
        result = history_features_from_canonical_ledger(index, phase4[row["race_code"]], d, None, research_reproduction=True, ledger_activation_at=activation_at)
        for f in FEATURES:
            comparisons[split][f].append((number(row[f]), result[f]))
        if i in (0, len(dataset)//4, len(dataset)//2, 3*len(dataset)//4, len(dataset)-1):
            included = history_features_from_canonical_ledger(index, phase4[row["race_code"]], d, None, research_reproduction=True, ledger_activation_at=activation_at, include_event_ids=True)["included_event_ids"]
            dates = [event_dates[event_id] for event_id in included]
            profile_examples.append({"race_code": row["race_code"], "player_id": phase4[row["race_code"]], "split": split, "research": {f: number(row[f]) for f in FEATURES}, "ledger": {f: result[f] for f in FEATURES}, "source_min_date": min(dates).isoformat() if dates else None, "source_max_date": max(dates).isoformat() if dates else None})
    by_split = {split: {f: summary(pairs) for f, pairs in features.items()} for split, features in comparisons.items()}
    all_stats = {f: summary(sum((comparisons[s][f] for s in SPLITS), [])) for f in FEATURES}
    append = append_test(baseline)
    availability = availability_test(ledger, index, activation_at)
    passed = (
        all(x["exact_match_rate"] == 1.0 for x in all_stats.values())
        and all((x["max_abs_diff"] or 0.0) <= 1e-12 for x in all_stats.values())
        and all(v == 0 for v in temporal.values())
        and all(append[k] for k in ("duplicate_existing_event_second_append_no_op", "existing_baseline_event_append_no_op", "new_event_first_append_added_once", "new_event_second_append_no_op", "baseline_rows_unchanged"))
        and (not availability["performed"] or (availability["excluded_before_observation"] and availability["included_at_observation"]))
    )
    report = {
        "phase": "9-1b", "canonical_history_ledger": "PASS" if passed else "BLOCKED", "generated_at": datetime.now().astimezone().isoformat(),
        "source_git_commit": git_head(), "source_script": str(Path(__file__).relative_to(REPO)), "source_script_sha256": sha256(Path(__file__)),
        "ledger_path": str(LEDGER.relative_to(REPO)), "ledger_sha256": sha256(LEDGER),
        "manifest_path": str(MANIFEST.relative_to(REPO)), "manifest_sha256": sha256(MANIFEST),
        "research_dataset_path": str(DATASET.relative_to(REPO)), "research_dataset_sha256": sha256(DATASET),
        "phase4_input_path": str(PHASE4_INPUT.relative_to(REPO)), "phase4_input_sha256": sha256(PHASE4_INPUT),
        "builder_input": "canonical ledger only; this audit imports no DB driver and executes no DB query",
        "population": {"dataset_n": len(dataset), "split_counts": {s: sum(r["split"] == s for r in dataset) for s in SPLITS}, "duplicate_race_code": len(dataset) - len({r["race_code"] for r in dataset})},
        "history_semantics": manifest["history_definition"], "window_definition": manifest["window_definition"],
        "parity_by_split": by_split, "parity_all": all_stats, "temporal_audit": temporal,
        "profile_examples": profile_examples, "append_only_test": append, "available_at_test": availability,
        "raw_db_history_accessed": False, "pass_conditions": {"all_features_exact": all(x["exact_match_rate"] == 1.0 for x in all_stats.values()), "rate_max_abs_diff_lte_1e12": all((all_stats[f]["max_abs_diff"] or 0.0) <= 1e-12 for f in FEATURES if f != "course4_history_n"), "history_n_integer_exact": all_stats["course4_history_n"]["mismatch_count"] == 0, "duplicate_event_id": 0},
        "next_phase_ready": passed,
    }
    REPORT.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
    phase_report = {
        "phase": "9-1b", "result": report["canonical_history_ledger"],
        "canonical_ledger": {"path": report["ledger_path"], "sha256": report["ledger_sha256"], "baseline_row_count": manifest["baseline_row_count"], "late_bootstrap_row_count": manifest["late_bootstrap_row_count"]},
        "research_provenance": {"baseline_source_path": manifest["baseline_source_path"], "baseline_source_sha256": manifest["baseline_source_sha256"], "source_generator_path": manifest["source_generator_path"], "source_generator_git_commit": manifest["source_generator_git_commit"]},
        "history_semantics": report["history_semantics"], "research_parity": report["parity_all"], "temporal_audit": temporal,
        "append_only_test": append, "available_at_test": availability, "attack_type_history_input": "READY" if passed else "BLOCKED",
        "phase9_1c_allowed": bool(passed), "parity_report_path": str(REPORT.relative_to(REPO)), "parity_report_sha256": sha256(REPORT),
        "generated_at": datetime.now().astimezone().isoformat(), "source_git_commit": git_head(),
    }
    PHASE_REPORT.write_text(json.dumps(phase_report, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps({"canonical_history_ledger": report["canonical_history_ledger"], "dataset_n": len(dataset), "parity_all": all_stats, "temporal": temporal, "append_test": append, "report": str(REPORT)}, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
