#!/usr/bin/env python3
"""Shared immutable input primitives for Phase 9-1f; no model inference."""
from __future__ import annotations

import hashlib
import json
import math
import os
from datetime import date, datetime
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
OUT = REPO / "analysis" / "output"
SNAPSHOT_ROOT = REPO / "var" / "course4_tenkai_input_snapshots"
CANONICAL_VERSION = "v1"
SNAPSHOT_VERSION = "course4-tenkai-static-input-snapshot/v1"


def sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def canonical_json(value) -> str:
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"), allow_nan=False)


def value(raw):
    try:
        number = float(raw)
        return number if math.isfinite(number) else None
    except (TypeError, ValueError):
        return None


def serializable(raw):
    number = value(raw)
    return number


def term_for(target: date) -> str:
    """Research-fixed term mapping: Jan-Apr previous Oct; May-Oct Apr; Nov-Dec Oct."""
    if target.month <= 4:
        return f"{(target.year - 1) % 100:02d}10"
    if target.month <= 10:
        return f"{target.year % 100:02d}04"
    return f"{target.year % 100:02d}10"


def parse_cutoff(raw) -> datetime:
    cutoff = datetime.fromisoformat(str(raw))
    if cutoff.tzinfo is None:
        raise ValueError("PREDICTION_CUTOFF_TIMEZONE_REQUIRED")
    return cutoff


class StaticSnapshotStore:
    """Append-only static source snapshots; never a prediction/snapshot campaign store."""
    def __init__(self, root: Path = SNAPSHOT_ROOT):
        self.root = Path(root)

    def path(self, race_code: str) -> Path:
        return self.root / "static_v1" / f"{race_code}.json"

    def read(self, race_code: str):
        path = self.path(race_code)
        if not path.exists():
            return None
        payload = json.loads(path.read_text(encoding="utf-8"))
        expected = payload.get("snapshot_sha256")
        body = {key: val for key, val in payload.items() if key != "snapshot_sha256"}
        if expected != hashlib.sha256(canonical_json(body).encode()).hexdigest():
            raise ValueError("PROSPECTIVE_SNAPSHOT_HASH_MISMATCH")
        return payload

    def first_write(self, payload: dict):
        race_code = str(payload["race_code"]).upper()
        path = self.path(race_code)
        path.parent.mkdir(parents=True, exist_ok=True)
        body = dict(payload)
        body.pop("snapshot_sha256", None)
        payload = {**body, "snapshot_sha256": hashlib.sha256(canonical_json(body).encode()).hexdigest()}
        encoded = (canonical_json(payload) + "\n").encode()
        try:
            # Static snapshots are immutable data consumed by the read-only
            # Apache inference API.  They contain no credentials and must be
            # readable by that service user after the cron first-write.
            fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o644)
        except FileExistsError:
            saved = self.read(race_code)
            return {"saved": False, "already_exists": True, "path": str(path), "snapshot_sha256": saved["snapshot_sha256"]}
        with os.fdopen(fd, "wb") as handle:
            handle.write(encoded)
            handle.flush()
            os.fsync(handle.fileno())
        if self.read(race_code) != payload:
            raise RuntimeError("PROSPECTIVE_SNAPSHOT_READBACK_MISMATCH")
        return {"saved": True, "already_exists": False, "path": str(path), "snapshot_sha256": payload["snapshot_sha256"]}
