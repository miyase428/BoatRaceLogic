#!/usr/bin/env python3
"""Fully isolated synthetic fixture for immutable Attack Type ledger generations."""
from __future__ import annotations

import hashlib
import json
import os
import sys
import tempfile
from datetime import datetime, timedelta, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "analysis"))
from course4_attack_ledger_generation_store import (  # noqa: E402
    MODE_GENERATION,
    AttackTypeLedgerGenerationStore,
    GenerationModeError,
    GenerationStoreError,
    GenerationUnavailable,
    GenerationValidationError,
    PublicationIncompleteError,
)


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def encoded(value: dict) -> bytes:
    return (json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")) + "\n").encode()


class Clock:
    def __init__(self, *values: datetime):
        self.values = list(values)
    def __call__(self) -> datetime:
        if not self.values:
            raise AssertionError("clock exhausted")
        return self.values.pop(0)


def material(generation_id: str, label: str = "one") -> tuple[bytes, bytes, bytes]:
    ledger = f"event_id,race_date\n{label},2099-01-01\n".encode()
    manifest = {"ledger_sha256": digest(ledger), "ledger_version": "v1", "baseline_source_sha256": "b" * 64}
    manifest_bytes = encoded(manifest)
    state = {"generation_id": generation_id, "ledger_sha256": digest(ledger), "manifest_sha256": digest(manifest_bytes), "ledger_version": "v1"}
    return ledger, manifest_bytes, encoded(state)


def expect(error_type, callback) -> None:
    try:
        callback()
    except error_type:
        return
    raise AssertionError(f"expected {error_type.__name__}")


class ReceiptFailureStore(AttackTypeLedgerGenerationStore):
    def _write_activation_receipt(self, _activation_id, _receipt):
        raise OSError("synthetic receipt failure")


def make_store(root: Path, clock: Clock | None = None) -> AttackTypeLedgerGenerationStore:
    return AttackTypeLedgerGenerationStore(root, now=clock) if clock else AttackTypeLedgerGenerationStore(root)


def acquire_competing_lock(root: Path) -> None:
    with make_store(root).writer_lock():
        pass


def main() -> None:
    checks: dict[str, bool] = {}
    # TMPDIR is injected by the caller; every fixture file remains under it.
    with tempfile.TemporaryDirectory(prefix="attack-ledger-generation-") as directory:
        fixture_root = Path(directory) / "store"
        base = datetime(2099, 1, 1, 12, 0, tzinfo=timezone.utc)
        clock = Clock(base, base + timedelta(seconds=2), base + timedelta(seconds=5), base + timedelta(seconds=8), base + timedelta(seconds=11), base + timedelta(seconds=14))
        store = make_store(fixture_root, clock)
        ledger1, manifest1, state1 = material("g-one", "one")
        first = store.create_and_publish("g-one", ledger_bytes=ledger1, manifest_bytes=manifest1, state_bytes=state1)
        checks["initial_generation_creation"] = first.publication_kind == "INITIAL" and fixture_root.joinpath("generations", "g-one").is_dir()
        checks["activation_is_post_current"] = first.activated_at == base + timedelta(seconds=5)
        old_context = store.resolve(mode=MODE_GENERATION, prediction_cutoff=first.activated_at)
        checks["normal_generation_publication"] = old_context.generation_id == "g-one" and old_context.ledger_bytes == ledger1

        ledger2, manifest2, state2 = material("g-two", "two")
        second = store.create_and_publish("g-two", ledger_bytes=ledger2, manifest_bytes=manifest2, state_bytes=state2)
        new_context = store.resolve(mode=MODE_GENERATION, prediction_cutoff=second.activated_at)
        checks["current_switch_keeps_old_context"] = old_context.generation_id == "g-one" and old_context.ledger_bytes == ledger1 and new_context.generation_id == "g-two"
        checks["old_generation_is_not_deleted"] = fixture_root.joinpath("generations", "g-one", "ledger.csv").read_bytes() == ledger1
        expect(GenerationUnavailable, lambda: store.resolve(mode=MODE_GENERATION, prediction_cutoff=second.activated_at - timedelta(microseconds=1)))
        checks["cutoff_before_activation_rejected"] = True
        checks["cutoff_at_activation_available"] = new_context.generation_id == "g-two"
        expect(GenerationModeError, lambda: store.resolve(mode="LEGACY", prediction_cutoff=second.activated_at))
        checks["legacy_never_falls_back"] = True

        # Corrupt each immutable member only inside this synthetic directory.
        current_file = fixture_root / "CURRENT.json"
        original_current = current_file.read_bytes()
        generation_dir = fixture_root / "generations" / "g-two"
        original_ledger = generation_dir.joinpath("ledger.csv").read_bytes()
        generation_dir.joinpath("ledger.csv").write_bytes(b"tampered\n")
        expect(GenerationValidationError, lambda: store.resolve(mode=MODE_GENERATION, prediction_cutoff=second.activated_at))
        checks["ledger_sha_mismatch"] = True
        generation_dir.joinpath("ledger.csv").write_bytes(original_ledger)
        original_manifest = generation_dir.joinpath("manifest.json").read_bytes()
        generation_dir.joinpath("manifest.json").write_bytes(b"{}\n")
        expect(GenerationValidationError, lambda: store.resolve(mode=MODE_GENERATION, prediction_cutoff=second.activated_at))
        checks["manifest_sha_mismatch"] = True
        generation_dir.joinpath("manifest.json").write_bytes(original_manifest)
        original_state = generation_dir.joinpath("state.json").read_bytes()
        generation_dir.joinpath("state.json").write_bytes(b"{}\n")
        expect(GenerationValidationError, lambda: store.resolve(mode=MODE_GENERATION, prediction_cutoff=second.activated_at))
        checks["state_sha_mismatch"] = True
        generation_dir.joinpath("state.json").write_bytes(original_state)
        pointer = json.loads(original_current)
        pointer["generation_descriptor_sha256"] = "0" * 64
        current_file.write_bytes(encoded(pointer))
        expect(GenerationValidationError, lambda: store.resolve(mode=MODE_GENERATION, prediction_cutoff=second.activated_at))
        checks["descriptor_sha_mismatch"] = True
        current_file.write_bytes(original_current)
        current_file.write_bytes(b"not-json\n")
        expect(GenerationValidationError, lambda: store.resolve(mode=MODE_GENERATION, prediction_cutoff=second.activated_at))
        checks["current_corruption_rejected"] = True
        current_file.unlink()
        expect(GenerationUnavailable, lambda: store.resolve(mode=MODE_GENERATION, prediction_cutoff=second.activated_at))
        checks["current_missing_rejected"] = True
        current_file.write_bytes(original_current)

        # A competing writer cannot acquire the shared filesystem lock.
        with store.writer_lock():
            expect(GenerationStoreError, lambda: acquire_competing_lock(fixture_root))
        checks["writer_contention_rejected"] = True
        expect(GenerationValidationError, lambda: store.create_and_publish("g-bad", ledger_bytes=b"x", manifest_bytes=encoded({}), state_bytes=encoded({})))
        checks["pre_publish_failure_keeps_current"] = json.loads(current_file.read_text())["generation_id"] == "g-two"

        failed_root = Path(directory) / "receipt-failure"
        fail_clock = Clock(base, base + timedelta(seconds=1), base + timedelta(seconds=2))
        failing = ReceiptFailureStore(failed_root, now=fail_clock)
        ledger3, manifest3, state3 = material("g-three", "three")
        expect(PublicationIncompleteError, lambda: failing.create_and_publish("g-three", ledger_bytes=ledger3, manifest_bytes=manifest3, state_bytes=state3))
        expect(GenerationUnavailable, lambda: failing.resolve(mode=MODE_GENERATION, prediction_cutoff=base + timedelta(days=1)))
        checks["missing_post_publish_receipt_fails_closed"] = True
        expect(GenerationValidationError, lambda: store.create_and_publish("../escape", ledger_bytes=ledger3, manifest_bytes=manifest3, state_bytes=state3))
        checks["path_traversal_generation_id_rejected"] = True

        allowed_tmp = Path(tempfile.gettempdir()).resolve()
        checks["all_fixture_paths_under_tmpdir"] = fixture_root.resolve().is_relative_to(allowed_tmp)
    if not all(checks.values()):
        raise SystemExit(json.dumps(checks, ensure_ascii=False, sort_keys=True))
    print(json.dumps({"pass": True, "case_count": len(checks), "checks": checks}, ensure_ascii=False, sort_keys=True))


if __name__ == "__main__":
    main()
