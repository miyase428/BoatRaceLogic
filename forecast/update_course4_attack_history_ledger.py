#!/usr/bin/env python3
"""Fail-closed writer for immutable Attack Type ledger generations.

The legacy fixed-path updater is deliberately not invoked here.  A generation
is created only from a complete, explicitly supplied input provider.  This
phase supplies a fixture-only JSON adapter; production DB integration remains
unavailable until its six-boat finality predicate is independently verified.
"""
from __future__ import annotations

import argparse
import csv
import hashlib
import io
import json
import sys
import uuid
from datetime import date, datetime, timedelta, timezone
from pathlib import Path
from typing import Any, Mapping

REPO = Path(__file__).resolve().parent.parent
ANALYSIS = REPO / "analysis"
if str(ANALYSIS) not in sys.path:
    sys.path.insert(0, str(ANALYSIS))

from build_course4_attack_history_canonical_ledger_phase9_1b import (  # noqa: E402
    BASELINE_END,
    FIELDS,
    GenerationBuilderError,
    build_generation_ledger,
    resolve_generation_through_date,
)
from course4_attack_ledger_generation_store import (  # noqa: E402
    MODE_GENERATION,
    AttackTypeLedgerGenerationStore,
    GenerationStoreError,
    GenerationUnavailable,
    PublicationIncompleteError,
)

STATE_SCHEMA = "course4-attack-ledger-generation-state/v1"
INPUT_SCHEMA = "course4-attack-ledger-generation-input/v1"


class GenerationUpdateError(RuntimeError):
    """A writer update that must not be represented as a successful run."""


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def sha256_file(path: Path) -> str:
    return sha256_bytes(path.read_bytes())


def canonical_json_bytes(value: Mapping[str, Any]) -> bytes:
    return (json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"), allow_nan=False) + "\n").encode("utf-8")


def parse_csv_bytes(data: bytes) -> list[dict[str, str]]:
    try:
        reader = csv.DictReader(io.StringIO(data.decode("utf-8")))
    except UnicodeDecodeError as error:
        raise GenerationUpdateError("EXISTING_LEDGER_INVALID_ENCODING") from error
    if tuple(reader.fieldnames or ()) != FIELDS:
        raise GenerationUpdateError("EXISTING_LEDGER_SCHEMA_MISMATCH")
    return list(reader)


def _aware_now(now: datetime | None = None) -> datetime:
    value = now or datetime.now(timezone.utc)
    if value.tzinfo is None or value.utcoffset() is None:
        raise GenerationUpdateError("WRITER_CLOCK_TIMEZONE_REQUIRED")
    return value.astimezone(timezone.utc)


class _PublicationClock:
    """A testable monotonic clock for Store durability/activation timestamps.

    The state records the writer's creation time, while the Store samples this
    clock again after the durable CURRENT replacement.  It must therefore not
    reuse the pre-publication timestamp as an activation receipt.
    """

    def __init__(self, initial: datetime):
        self._value = initial

    def __call__(self) -> datetime:
        self._value = self._value + timedelta(microseconds=1)
        return self._value


def _state_bytes(*, generation_id: str, ledger_bytes: bytes, manifest_bytes: bytes, baseline_source_sha256: str,
                 through_date: date, db_observed_at: str, builder_sha256: str, updater_sha256: str,
                 append: Mapping[str, Any], created_at: datetime) -> bytes:
    state = {
        "schema_version": STATE_SCHEMA,
        "generation_id": generation_id,
        "ledger_version": "v1",
        "ledger_sha256": sha256_bytes(ledger_bytes),
        "manifest_sha256": sha256_bytes(manifest_bytes),
        "baseline_source_sha256": baseline_source_sha256,
        "generation_through_date": through_date.isoformat(),
        "db_observed_at": db_observed_at,
        "generation_created_at": created_at.isoformat(),
        "builder_source_sha256": builder_sha256,
        "updater_source_sha256": updater_sha256,
        "append_run": dict(append),
    }
    return canonical_json_bytes(state)


def _existing_context(store: AttackTypeLedgerGenerationStore, *, now: datetime):
    """Resolve an existing immutable generation once while the writer lock is held."""
    if not store.current_path.exists() and not store.current_path.is_symlink():
        return None
    try:
        return store.resolve(mode=MODE_GENERATION, prediction_cutoff=now)
    except GenerationUnavailable as error:
        raise GenerationUpdateError("EXISTING_GENERATION_UNAVAILABLE") from error


def update_generation(*, generation_root: Path | str, generation_id: str, date_value: str | None,
                      through_date_value: str | None, today_jst: date, baseline_rows: list[dict],
                      baseline_source_manifest: list[dict], baseline_source_sha256: str,
                      finalized_race_rows: list[dict], db_observed_at: str, now: datetime | None = None,
                      store_factory=AttackTypeLedgerGenerationStore) -> dict[str, Any]:
    """Create one generation from injected, complete finalized-race inputs.

    This function performs no database, subprocess, legacy-ledger, or model
    access.  A production input adapter is intentionally absent until its DB
    finality contract is proved; callers must supply normalized six-entrant
    races and a factual observation timestamp.
    """
    through_date = resolve_generation_through_date(date_value, through_date_value, today_jst=today_jst)
    created_at = _aware_now(now)
    builder_path = ANALYSIS / "build_course4_attack_history_canonical_ledger_phase9_1b.py"
    updater_path = Path(__file__)
    builder_sha = sha256_file(builder_path)
    updater_sha = sha256_file(updater_path)
    # The Store obtains its activation time only after CURRENT is durable.  A
    # supplied ``now`` is used solely to make isolated fixtures deterministic;
    # production samples the real UTC clock at each Store transition.
    store_clock = _PublicationClock(created_at) if now is not None else None
    store = store_factory(generation_root, now=store_clock) if store_clock is not None else store_factory(generation_root)
    with store.writer_lock():
        context = _existing_context(store, now=created_at)
        existing_rows = [] if context is None else parse_csv_bytes(context.ledger_bytes)
        ledger_bytes, manifest_bytes, append = build_generation_ledger(
            baseline_rows=baseline_rows,
            baseline_source_manifest=baseline_source_manifest,
            baseline_source_sha256=baseline_source_sha256,
            existing_rows=existing_rows,
            finalized_race_rows=finalized_race_rows,
            through_date=through_date,
            observed_at=db_observed_at,
            builder_sha256=builder_sha,
        )
        state_bytes = _state_bytes(
            generation_id=generation_id,
            ledger_bytes=ledger_bytes,
            manifest_bytes=manifest_bytes,
            baseline_source_sha256=baseline_source_sha256,
            through_date=through_date,
            db_observed_at=db_observed_at,
            builder_sha256=builder_sha,
            updater_sha256=updater_sha,
            append=append,
            created_at=created_at,
        )
        store.stage_generation(generation_id, ledger_bytes=ledger_bytes, manifest_bytes=manifest_bytes, state_bytes=state_bytes)
        try:
            publication = store.publish_generation(generation_id)
        except PublicationIncompleteError as error:
            # CURRENT may already name this generation but no receipt authorizes readers.
            raise GenerationUpdateError("PUBLISHED_UNACTIVATED") from error
    return {
        "status": "PASS", "generation_id": publication.generation_id,
        "activation_id": publication.activation_id, "activated_at": publication.activated_at.isoformat(),
        "publication_kind": publication.publication_kind, "ledger_sha256": sha256_bytes(ledger_bytes),
        "manifest_sha256": sha256_bytes(manifest_bytes), "state_sha256": sha256_bytes(state_bytes),
        "through_date": through_date.isoformat(), "append_run": append,
    }


def _load_fixture_input(path: Path) -> dict[str, Any]:
    """Fixture-only adapter; it keeps production DB access disabled in this phase."""
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeDecodeError, json.JSONDecodeError) as error:
        raise GenerationUpdateError("FIXTURE_INPUT_UNREADABLE") from error
    if not isinstance(value, dict) or value.get("schema_version") != INPUT_SCHEMA:
        raise GenerationUpdateError("FIXTURE_INPUT_SCHEMA_INVALID")
    return value


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--generation-root", type=Path, required=True)
    parser.add_argument("--generation-id")
    parser.add_argument("--date")
    parser.add_argument("--through-date")
    parser.add_argument("--fixture-input", type=Path,
                        help="synthetic input only; no production DB adapter is configured")
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args()
    if args.fixture_input is None:
        raise GenerationUpdateError("GENERATION_INPUT_PROVIDER_NOT_CONFIGURED")
    supplied = _load_fixture_input(args.fixture_input)
    today = date.today()
    result = update_generation(
        generation_root=args.generation_root,
        generation_id=args.generation_id or f"g-{uuid.uuid4().hex}",
        date_value=args.date,
        through_date_value=args.through_date,
        today_jst=today,
        baseline_rows=supplied["baseline_rows"],
        baseline_source_manifest=supplied["baseline_source_manifest"],
        baseline_source_sha256=supplied["baseline_source_sha256"],
        finalized_race_rows=supplied["finalized_race_rows"],
        db_observed_at=supplied["db_observed_at"],
    )
    print(json.dumps(result, ensure_ascii=False, sort_keys=True) if args.json else json.dumps(result, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
