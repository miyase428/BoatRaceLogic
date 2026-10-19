#!/usr/bin/env python3
"""Synthetic-only contract tests for the Attack Type generation writer."""
from __future__ import annotations

import csv
import io
import json
import os
import tempfile
import sys
from datetime import date, datetime, timedelta, timezone
from pathlib import Path

FORECAST = Path(__file__).resolve().parent.parent / "forecast"
if str(FORECAST) not in sys.path:
    sys.path.insert(0, str(FORECAST))

from build_course4_attack_history_canonical_ledger_phase9_1b import FIELDS, GenerationBuilderError
from course4_attack_ledger_generation_store import AttackTypeLedgerGenerationStore, GenerationStoreError, GenerationUnavailable, PublicationIncompleteError
from update_course4_attack_history_ledger import GenerationUpdateError, update_generation

TODAY = date(2026, 10, 19)
BASELINE_SHA = "a" * 64
BASELINE_MANIFEST = [{"path": "synthetic/baseline.csv", "sha256": BASELINE_SHA, "format": "synthetic"}]


def baseline_row() -> dict:
    return {
        "event_id": "2026093001:base-4", "race_code": "2026093001", "race_date": "2026-09-30", "player_id": "base-4",
        "actual_course": 4, "final_rank": 1, "raw_technique": "まくり", "normalized_technique": "MAKURI",
        "is_history_eligible": 1, "is_makuri": 1, "is_makurizashi": 0,
        "source_kind": "frozen_research_cache", "source_path": "synthetic/baseline.csv", "source_sha256": BASELINE_SHA,
        "ledger_version": "v1", "baseline_frozen": 1, "availability_kind": "RESEARCH_BASELINE_LOGICAL",
        "race_finalized_at": "", "first_observed_at": "", "ingested_at": "2026-10-01T00:00:00+00:00",
    }


def race(code: str, day: str, *, c4_player: str = "p4", complete: bool = True) -> list[dict]:
    rows = []
    count = 6 if complete else 5
    for lane in range(1, count + 1):
        player = c4_player if lane == 4 else f"{code}-p{lane}"
        rows.append({"race_code": code, "race_date": day, "player_id": player, "actual_course": lane,
                     "final_rank": lane, "raw_technique": "まくり" if lane == 1 else "差し",
                     "race_finalized_at": f"{day}T12:00:00+09:00"})
    return rows


def kwargs(root: Path, generation_id: str, *, through: str, rows: list[dict], now: datetime | None = None, **extra):
    return dict(generation_root=root, generation_id=generation_id, date_value=extra.pop("date_value", None),
                through_date_value=through, today_jst=TODAY, baseline_rows=extra.pop("baseline_rows", [baseline_row()]),
                baseline_source_manifest=extra.pop("baseline_source_manifest", BASELINE_MANIFEST),
                baseline_source_sha256=extra.pop("baseline_source_sha256", BASELINE_SHA),
                finalized_race_rows=rows, db_observed_at=extra.pop("db_observed_at", (now or datetime(2026, 10, 10, tzinfo=timezone.utc)).isoformat()),
                now=now or datetime(2026, 10, 10, tzinfo=timezone.utc), **extra)


def expect_error(label: str, fn, allowed: tuple[type[BaseException], ...]) -> None:
    try:
        fn()
    except allowed:
        return
    raise AssertionError(f"{label}: expected {allowed}")


def read_rows(root: Path, cutoff: datetime) -> list[dict]:
    context = AttackTypeLedgerGenerationStore(root).resolve(mode="GENERATION", prediction_cutoff=cutoff)
    reader = csv.DictReader(io.StringIO(context.ledger_bytes.decode("utf-8")))
    assert tuple(reader.fieldnames or ()) == FIELDS
    return list(reader)


class ReceiptFailureStore(AttackTypeLedgerGenerationStore):
    def _write_activation_receipt(self, activation_id, receipt):
        raise OSError("synthetic receipt failure")


class StagingFailureStore(AttackTypeLedgerGenerationStore):
    def stage_generation(self, *args, **kwargs):
        raise GenerationStoreError("SYNTHETIC_STAGING_FAILURE")


def run() -> int:
    checks: list[str] = []
    with tempfile.TemporaryDirectory(prefix="attack-generation-writer-") as temp:
        root = Path(temp) / "generations-root"
        t1 = datetime(2026, 10, 10, tzinfo=timezone.utc)
        initial = update_generation(**kwargs(root, "g-initial", through="2026-10-02", rows=race("2026100201", "2026-10-02"), now=t1))
        assert initial["publication_kind"] == "INITIAL" and initial["append_run"]["added_n"] == 1
        initial_context = AttackTypeLedgerGenerationStore(root).resolve(mode="GENERATION", prediction_cutoff=t1 + timedelta(seconds=1))
        assert datetime.fromisoformat(str(initial_context.state["generation_created_at"])) < initial_context.activated_at
        checks.append("normal_initial_generation")

        before = read_rows(root, t1 + timedelta(seconds=1))
        t2 = datetime(2026, 10, 11, tzinfo=timezone.utc)
        second = update_generation(**kwargs(root, "g-update", through="2026-10-03", rows=race("2026100301", "2026-10-03"), now=t2))
        after = read_rows(root, t2 + timedelta(seconds=1))
        assert second["publication_kind"] == "UPDATE" and len(after) == len(before) + 1
        checks.append("normal_additional_generation")

        t3 = datetime(2026, 10, 12, tzinfo=timezone.utc)
        duplicate = update_generation(**kwargs(root, "g-duplicate", through="2026-10-03", rows=race("2026100301", "2026-10-03"), now=t3))
        duplicate_rows = read_rows(root, t3 + timedelta(seconds=1))
        assert duplicate["append_run"]["added_n"] == 0 and len(duplicate_rows) == len(after)
        checks.append("duplicate_event_excluded")

        assert before[0]["event_id"] == duplicate_rows[0]["event_id"] and before[0] == duplicate_rows[0]
        checks.append("existing_history_immutable")
        prospective_before = [row for row in after if row["baseline_frozen"] == "0"][0]
        prospective_after = [row for row in duplicate_rows if row["event_id"] == prospective_before["event_id"]][0]
        assert prospective_before["first_observed_at"] == prospective_after["first_observed_at"]
        checks.append("first_observed_at_immutable")

        expect_error("out_of_range", lambda: update_generation(**kwargs(Path(temp) / "range", "g-range", through="2026-10-02", rows=race("2026100302", "2026-10-03"))), (GenerationBuilderError,))
        checks.append("through_date_exact_range")
        expect_error("future", lambda: update_generation(**kwargs(Path(temp) / "future", "g-future", through="2026-10-20", rows=[])), (GenerationBuilderError,))
        checks.append("future_date_rejected")
        expect_error("mismatch", lambda: update_generation(**kwargs(Path(temp) / "mismatch", "g-mismatch", through="2026-10-02", rows=[], date_value="2026-10-03")), (GenerationBuilderError,))
        checks.append("date_alias_mismatch_rejected")
        expect_error("builder_incomplete", lambda: update_generation(**kwargs(Path(temp) / "failed-builder", "g-failed-builder", through="2026-10-02", rows=race("2026100202", "2026-10-02", complete=False))), (GenerationBuilderError,))
        checks.append("builder_failure_fail_closed")

        lock_root = Path(temp) / "lock"
        store = AttackTypeLedgerGenerationStore(lock_root)
        with store.writer_lock():
            expect_error("lock", lambda: next(AttackTypeLedgerGenerationStore(lock_root).writer_lock().__enter__() for _ in [0]), (GenerationStoreError,))
        checks.append("writer_lock_exclusion")

        bad_root = Path(temp) / "bad-membership"
        bad_store = AttackTypeLedgerGenerationStore(bad_root)
        with bad_store.writer_lock():
            ledger = b"event_id\n"
            manifest = {"ledger_version": "v1", "ledger_sha256": "0" * 64, "baseline_source_sha256": BASELINE_SHA}
            state = {"generation_id": "g-bad-manifest", "ledger_version": "v1", "ledger_sha256": "0" * 64, "manifest_sha256": "0" * 64}
            expect_error("manifest", lambda: bad_store.stage_generation("g-bad-manifest", ledger_bytes=ledger, manifest_bytes=json.dumps(manifest).encode(), state_bytes=json.dumps(state).encode()), (GenerationStoreError,))
        checks.append("manifest_sha_mismatch_rejected")

        state_root = Path(temp) / "bad-state"
        state_store = AttackTypeLedgerGenerationStore(state_root)
        with state_store.writer_lock():
            ledger = b"event_id\n"
            manifest_bytes = json.dumps({"ledger_version": "v1", "ledger_sha256": __import__("hashlib").sha256(ledger).hexdigest(), "baseline_source_sha256": BASELINE_SHA}).encode()
            state = {"generation_id": "g-bad-state", "ledger_version": "v1", "ledger_sha256": __import__("hashlib").sha256(ledger).hexdigest(), "manifest_sha256": "0" * 64}
            expect_error("state", lambda: state_store.stage_generation("g-bad-state", ledger_bytes=ledger, manifest_bytes=manifest_bytes, state_bytes=json.dumps(state).encode()), (GenerationStoreError,))
        checks.append("state_sha_mismatch_rejected")

        stage_root = Path(temp) / "staging-failure"
        expect_error("staging", lambda: update_generation(**kwargs(stage_root, "g-stage", through="2026-10-02", rows=race("2026100203", "2026-10-02"), store_factory=StagingFailureStore)), (GenerationStoreError,))
        assert not (stage_root / "CURRENT.json").exists()
        checks.append("staging_failure_preserves_current")

        receipt_root = Path(temp) / "receipt-failure"
        expect_error("receipt", lambda: update_generation(**kwargs(receipt_root, "g-receipt", through="2026-10-02", rows=race("2026100204", "2026-10-02"), store_factory=ReceiptFailureStore)), (GenerationUpdateError,))
        assert (receipt_root / "CURRENT.json").exists()
        expect_error("receipt-resolve", lambda: AttackTypeLedgerGenerationStore(receipt_root).resolve(mode="GENERATION", prediction_cutoff=t1 + timedelta(days=1)), (GenerationUnavailable,))
        checks.append("published_unactivated_fail_closed")

        expect_error("baseline", lambda: update_generation(**kwargs(Path(temp) / "no-baseline", "g-none", through="2026-10-02", rows=[], baseline_rows=[])), (GenerationBuilderError,))
        checks.append("initial_generation_requires_baseline")
        changed_baseline = baseline_row()
        changed_baseline["raw_technique"] = "差し"
        expect_error("baseline-change", lambda: update_generation(**kwargs(root, "g-baseline-change", through="2026-10-03", rows=[], now=datetime(2026, 10, 13, tzinfo=timezone.utc), baseline_rows=[changed_baseline])), (GenerationBuilderError,))
        checks.append("existing_baseline_change_rejected")
        expect_error("observed-time", lambda: update_generation(**kwargs(Path(temp) / "naive-observed", "g-naive", through="2026-10-02", rows=[], db_observed_at="2026-10-02T12:00:00")), (GenerationBuilderError,))
        checks.append("observation_time_timezone_required")
        expect_error("outside", lambda: update_generation(**kwargs(Path(temp) / "outside", "g-outside", through="2026-10-03", rows=race("2026093002", "2026-09-30"))), (GenerationBuilderError,))
        checks.append("outside_period_rejected")
        expect_error("not-final", lambda: update_generation(**kwargs(Path(temp) / "incomplete", "g-incomplete", through="2026-10-02", rows=race("2026100205", "2026-10-02", complete=False))), (GenerationBuilderError,))
        checks.append("incomplete_race_rejected")

        legacy = Path(temp) / "legacy-fixed.csv"
        legacy.write_text("must remain untouched\n", encoding="utf-8")
        legacy_before = legacy.read_bytes()
        independent_root = Path(temp) / "independent"
        update_generation(**kwargs(independent_root, "g-independent", through="2026-10-02", rows=race("2026100206", "2026-10-02")))
        assert legacy.read_bytes() == legacy_before
        checks.append("legacy_fixed_file_untouched")

    expected = 20
    assert len(checks) == expected, checks
    print("PASS", len(checks), ",".join(checks))
    return 0


if __name__ == "__main__":
    raise SystemExit(run())
