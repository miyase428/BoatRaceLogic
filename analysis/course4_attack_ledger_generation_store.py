#!/usr/bin/env python3
"""Immutable, fail-closed storage for Attack Type ledger generations.

This module deliberately does not know the legacy fixed-path artifacts.  A
caller must choose ``GENERATION`` mode explicitly; an absent or malformed
CURRENT pointer is unavailable, never a request to fall back to legacy data.
"""
from __future__ import annotations

import contextlib
import dataclasses
import fcntl
import hashlib
import json
import os
import re
import stat
import uuid
from datetime import datetime, timezone
from pathlib import Path
from types import MappingProxyType
from typing import Any, Callable, Iterator, Mapping


MODE_LEGACY = "LEGACY"
MODE_GENERATION = "GENERATION"
GENERATION_SCHEMA = "course4-attack-ledger-generation/v1"
CURRENT_SCHEMA = "course4-attack-ledger-current/v1"
ACTIVATION_SCHEMA = "course4-attack-ledger-activation/v1"
GENERATION_ID_RE = re.compile(r"^g-[A-Za-z0-9][A-Za-z0-9._-]{0,95}$")
ACTIVATION_ID_RE = re.compile(r"^a-[A-Za-z0-9][A-Za-z0-9._-]{0,95}$")
SHA256_RE = re.compile(r"^[0-9a-f]{64}$")
FILE_NAMES = {"ledger": "ledger.csv", "manifest": "manifest.json", "state": "state.json"}


class GenerationStoreError(RuntimeError):
    """Base class for a store condition that must fail closed."""


class GenerationUnavailable(GenerationStoreError):
    """The requested generation is absent, invalid, or not active at cutoff."""


class GenerationValidationError(GenerationStoreError):
    """An on-disk record does not satisfy the immutable-generation contract."""


class GenerationModeError(GenerationStoreError):
    """A caller attempted to use a mode outside this store's explicit API."""


class PublicationIncompleteError(GenerationStoreError):
    """CURRENT was switched but no usable activation receipt was written."""


def _sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def _canonical_json(value: Mapping[str, Any]) -> bytes:
    return (json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"), allow_nan=False) + "\n").encode("utf-8")


def _json_object(data: bytes, label: str) -> dict[str, Any]:
    try:
        value = json.loads(data.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError) as error:
        raise GenerationValidationError(f"{label}_INVALID_JSON") from error
    if not isinstance(value, dict):
        raise GenerationValidationError(f"{label}_NOT_OBJECT")
    return value


def _freeze_mapping(value: Mapping[str, Any]) -> Mapping[str, Any]:
    """The context exposes validated metadata, never a mutable store reference."""
    return MappingProxyType(dict(value))


@dataclasses.dataclass(frozen=True)
class GenerationContext:
    """One fully verified immutable generation, fixed for an entire request."""

    mode: str
    root: Path
    generation_id: str
    generation_dir: Path
    ledger_path: Path
    manifest_path: Path
    state_path: Path
    descriptor_path: Path
    activation_path: Path
    ledger_bytes: bytes
    manifest: Mapping[str, Any]
    state: Mapping[str, Any]
    descriptor: Mapping[str, Any]
    ledger_sha256: str
    manifest_sha256: str
    state_sha256: str
    descriptor_sha256: str
    current_sha256: str
    activation_id: str
    activated_at: datetime


@dataclasses.dataclass(frozen=True)
class PublicationResult:
    generation_id: str
    activation_id: str
    current_sha256: str
    activated_at: datetime
    publication_kind: str


class AttackTypeLedgerGenerationStore:
    """Writer and reader API for immutable Attack Type ledger generations."""

    def __init__(self, root: Path | str, *, now: Callable[[], datetime] | None = None):
        self.root = Path(root).absolute()
        self._now = now or (lambda: datetime.now(timezone.utc))
        self._writer_lock_held = False

    @property
    def current_path(self) -> Path:
        return self.root / "CURRENT.json"

    @property
    def generations_path(self) -> Path:
        return self.root / "generations"

    @property
    def activations_path(self) -> Path:
        return self.root / "activations"

    @property
    def writer_lock_path(self) -> Path:
        return self.root / ".writer.lock"

    def _now_aware(self) -> datetime:
        value = self._now()
        if not isinstance(value, datetime) or value.tzinfo is None or value.utcoffset() is None:
            raise GenerationValidationError("CLOCK_TIMEZONE_REQUIRED")
        return value.astimezone(timezone.utc)

    @staticmethod
    def _validate_id(value: str, pattern: re.Pattern[str], label: str) -> str:
        if not isinstance(value, str) or not pattern.fullmatch(value):
            raise GenerationValidationError(f"INVALID_{label}")
        return value

    @staticmethod
    def _validate_sha(value: Any, label: str) -> str:
        if not isinstance(value, str) or not SHA256_RE.fullmatch(value):
            raise GenerationValidationError(f"INVALID_{label}_SHA256")
        return value

    @staticmethod
    def _assert_real_directory(path: Path, *, create: bool = False) -> None:
        if create:
            path.mkdir(mode=0o750, parents=True, exist_ok=True)
        try:
            mode = path.lstat().st_mode
        except FileNotFoundError as error:
            raise GenerationUnavailable("STORE_DIRECTORY_MISSING") from error
        if stat.S_ISLNK(mode) or not stat.S_ISDIR(mode):
            raise GenerationValidationError("STORE_DIRECTORY_INVALID")

    @staticmethod
    def _assert_real_file(path: Path) -> None:
        try:
            mode = path.lstat().st_mode
        except FileNotFoundError as error:
            raise GenerationUnavailable("STORE_FILE_MISSING") from error
        if stat.S_ISLNK(mode) or not stat.S_ISREG(mode):
            raise GenerationValidationError("STORE_FILE_INVALID")

    @staticmethod
    def _fsync_directory(path: Path) -> None:
        try:
            fd = os.open(path, os.O_RDONLY | getattr(os, "O_DIRECTORY", 0))
        except OSError as error:
            raise GenerationStoreError("DIRECTORY_FSYNC_OPEN_FAILED") from error
        try:
            os.fsync(fd)
        except OSError as error:
            raise GenerationStoreError("DIRECTORY_FSYNC_FAILED") from error
        finally:
            os.close(fd)

    @staticmethod
    def _read_regular_bytes(path: Path) -> bytes:
        AttackTypeLedgerGenerationStore._assert_real_file(path)
        flags = os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0)
        try:
            fd = os.open(path, flags)
        except OSError as error:
            raise GenerationUnavailable("STORE_FILE_UNREADABLE") from error
        try:
            mode = os.fstat(fd).st_mode
            if not stat.S_ISREG(mode):
                raise GenerationValidationError("STORE_FILE_INVALID")
            chunks: list[bytes] = []
            while True:
                chunk = os.read(fd, 1024 * 1024)
                if not chunk:
                    break
                chunks.append(chunk)
            return b"".join(chunks)
        finally:
            os.close(fd)

    @staticmethod
    def _write_new_file(path: Path, data: bytes) -> None:
        if path.exists() or path.is_symlink():
            raise GenerationStoreError("IMMUTABLE_FILE_ALREADY_EXISTS")
        flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0)
        try:
            fd = os.open(path, flags, 0o640)
        except OSError as error:
            raise GenerationStoreError("IMMUTABLE_FILE_CREATE_FAILED") from error
        try:
            view = memoryview(data)
            while view:
                written = os.write(fd, view)
                view = view[written:]
            os.fsync(fd)
        finally:
            os.close(fd)

    def _atomic_replace(self, path: Path, data: bytes) -> None:
        self._assert_real_directory(path.parent)
        temp = path.parent / f".{path.name}.tmp-{uuid.uuid4().hex}"
        self._write_new_file(temp, data)
        try:
            os.replace(temp, path)
            self._fsync_directory(path.parent)
        except OSError as error:
            raise GenerationStoreError("ATOMIC_REPLACE_FAILED") from error

    def _ensure_writer_layout(self) -> None:
        self._assert_real_directory(self.root, create=True)
        self._assert_real_directory(self.generations_path, create=True)
        self._assert_real_directory(self.activations_path, create=True)

    def _require_writer_lock(self) -> None:
        if not self._writer_lock_held:
            raise GenerationStoreError("WRITER_LOCK_REQUIRED")

    @contextlib.contextmanager
    def writer_lock(self) -> Iterator[None]:
        """Exclusive writer lock; readers intentionally never take this lock."""
        if self._writer_lock_held:
            raise GenerationStoreError("WRITER_LOCK_REENTRANT")
        self._ensure_writer_layout()
        if self.writer_lock_path.exists() and self.writer_lock_path.is_symlink():
            raise GenerationValidationError("WRITER_LOCK_SYMLINK")
        flags = os.O_RDWR | os.O_CREAT | getattr(os, "O_NOFOLLOW", 0)
        fd = os.open(self.writer_lock_path, flags, 0o600)
        try:
            try:
                fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError as error:
                raise GenerationStoreError("WRITER_LOCK_HELD") from error
            self._writer_lock_held = True
            yield
        finally:
            self._writer_lock_held = False
            fcntl.flock(fd, fcntl.LOCK_UN)
            os.close(fd)

    def _generation_dir(self, generation_id: str) -> Path:
        generation_id = self._validate_id(generation_id, GENERATION_ID_RE, "GENERATION_ID")
        return self.generations_path / generation_id

    def _activation_path(self, activation_id: str) -> Path:
        activation_id = self._validate_id(activation_id, ACTIVATION_ID_RE, "ACTIVATION_ID")
        return self.activations_path / f"{activation_id}.json"

    @staticmethod
    def _validate_membership(ledger: bytes, manifest: Mapping[str, Any], state: Mapping[str, Any], generation_id: str, manifest_sha: str) -> None:
        ledger_sha = _sha256(ledger)
        if manifest.get("ledger_sha256") != ledger_sha:
            raise GenerationValidationError("MANIFEST_LEDGER_HASH_MISMATCH")
        if state.get("generation_id") != generation_id:
            raise GenerationValidationError("STATE_GENERATION_ID_MISMATCH")
        if state.get("ledger_sha256") != ledger_sha:
            raise GenerationValidationError("STATE_LEDGER_HASH_MISMATCH")
        if state.get("manifest_sha256") != manifest_sha:
            raise GenerationValidationError("STATE_MANIFEST_HASH_MISMATCH")
        if state.get("ledger_version") != manifest.get("ledger_version"):
            raise GenerationValidationError("STATE_LEDGER_VERSION_MISMATCH")

    def stage_generation(self, generation_id: str, *, ledger_bytes: bytes, manifest_bytes: bytes, state_bytes: bytes) -> Mapping[str, Any]:
        """Persist and verify an unpublished generation under the writer lock."""
        self._require_writer_lock()
        self._ensure_writer_layout()
        generation_id = self._validate_id(generation_id, GENERATION_ID_RE, "GENERATION_ID")
        if not isinstance(ledger_bytes, bytes) or not ledger_bytes:
            raise GenerationValidationError("LEDGER_BYTES_REQUIRED")
        manifest = _json_object(manifest_bytes, "MANIFEST")
        state = _json_object(state_bytes, "STATE")
        manifest_sha = _sha256(manifest_bytes)
        self._validate_membership(ledger_bytes, manifest, state, generation_id, manifest_sha)
        target = self._generation_dir(generation_id)
        if target.exists() or target.is_symlink():
            raise GenerationStoreError("GENERATION_ALREADY_EXISTS")
        staging = self.generations_path / f".stage-{generation_id}-{uuid.uuid4().hex}"
        staging.mkdir(mode=0o750)
        try:
            self._write_new_file(staging / FILE_NAMES["ledger"], ledger_bytes)
            self._write_new_file(staging / FILE_NAMES["manifest"], manifest_bytes)
            self._write_new_file(staging / FILE_NAMES["state"], state_bytes)
            descriptor = {
                "schema_version": GENERATION_SCHEMA,
                "generation_id": generation_id,
                "created_at": self._now_aware().isoformat(),
                "ledger_version": manifest.get("ledger_version"),
                "baseline_source_sha256": manifest.get("baseline_source_sha256"),
                "files": {
                    "ledger": {"name": FILE_NAMES["ledger"], "sha256": _sha256(ledger_bytes)},
                    "manifest": {"name": FILE_NAMES["manifest"], "sha256": manifest_sha},
                    "state": {"name": FILE_NAMES["state"], "sha256": _sha256(state_bytes)},
                },
            }
            descriptor_bytes = _canonical_json(descriptor)
            self._write_new_file(staging / "generation.json", descriptor_bytes)
            self._fsync_directory(staging)
            os.replace(staging, target)
            self._fsync_directory(self.generations_path)
        except Exception:
            # Staging is intentionally retained for forensic review; never delete it here.
            raise
        loaded = self._load_generation(generation_id)
        return loaded["descriptor"]

    def _load_generation(self, generation_id: str, expected_descriptor_sha: str | None = None) -> dict[str, Any]:
        self._assert_real_directory(self.root)
        self._assert_real_directory(self.generations_path)
        generation_id = self._validate_id(generation_id, GENERATION_ID_RE, "GENERATION_ID")
        directory = self._generation_dir(generation_id)
        self._assert_real_directory(directory)
        paths = {key: directory / filename for key, filename in FILE_NAMES.items()}
        descriptor_path = directory / "generation.json"
        ledger = self._read_regular_bytes(paths["ledger"])
        manifest_bytes = self._read_regular_bytes(paths["manifest"])
        state_bytes = self._read_regular_bytes(paths["state"])
        descriptor_bytes = self._read_regular_bytes(descriptor_path)
        manifest = _json_object(manifest_bytes, "MANIFEST")
        state = _json_object(state_bytes, "STATE")
        descriptor = _json_object(descriptor_bytes, "GENERATION_DESCRIPTOR")
        descriptor_sha = _sha256(descriptor_bytes)
        if expected_descriptor_sha is not None and descriptor_sha != self._validate_sha(expected_descriptor_sha, "DESCRIPTOR"):
            raise GenerationValidationError("GENERATION_DESCRIPTOR_HASH_MISMATCH")
        if descriptor.get("schema_version") != GENERATION_SCHEMA or descriptor.get("generation_id") != generation_id:
            raise GenerationValidationError("GENERATION_DESCRIPTOR_IDENTITY_MISMATCH")
        files = descriptor.get("files")
        if not isinstance(files, dict) or set(files) != set(FILE_NAMES):
            raise GenerationValidationError("GENERATION_DESCRIPTOR_FILES_INVALID")
        actual = {"ledger": _sha256(ledger), "manifest": _sha256(manifest_bytes), "state": _sha256(state_bytes)}
        for key, filename in FILE_NAMES.items():
            item = files.get(key)
            if not isinstance(item, dict) or item.get("name") != filename or item.get("sha256") != actual[key]:
                raise GenerationValidationError("GENERATION_DESCRIPTOR_FILE_HASH_MISMATCH")
        self._validate_membership(ledger, manifest, state, generation_id, actual["manifest"])
        if descriptor.get("ledger_version") != manifest.get("ledger_version"):
            raise GenerationValidationError("GENERATION_DESCRIPTOR_LEDGER_VERSION_MISMATCH")
        if descriptor.get("baseline_source_sha256") != manifest.get("baseline_source_sha256"):
            raise GenerationValidationError("GENERATION_DESCRIPTOR_BASELINE_MISMATCH")
        return {
            "directory": directory,
            "paths": paths,
            "descriptor_path": descriptor_path,
            "ledger": ledger,
            "manifest": manifest,
            "state": state,
            "descriptor": descriptor,
            "hashes": {**actual, "descriptor": descriptor_sha},
        }

    def _read_current_pointer(self) -> tuple[dict[str, Any], bytes]:
        data = self._read_regular_bytes(self.current_path)
        pointer = _json_object(data, "CURRENT")
        if pointer.get("schema_version") != CURRENT_SCHEMA:
            raise GenerationValidationError("CURRENT_SCHEMA_MISMATCH")
        self._validate_id(pointer.get("generation_id"), GENERATION_ID_RE, "GENERATION_ID")
        self._validate_id(pointer.get("activation_id"), ACTIVATION_ID_RE, "ACTIVATION_ID")
        self._validate_sha(pointer.get("generation_descriptor_sha256"), "DESCRIPTOR")
        if pointer.get("publication_kind") not in ("INITIAL", "UPDATE"):
            raise GenerationValidationError("CURRENT_PUBLICATION_KIND_INVALID")
        prior = pointer.get("prior_generation_id")
        if prior is not None:
            self._validate_id(prior, GENERATION_ID_RE, "PRIOR_GENERATION_ID")
        return pointer, data

    def _write_activation_receipt(self, activation_id: str, receipt: Mapping[str, Any]) -> None:
        path = self._activation_path(activation_id)
        if path.exists() or path.is_symlink():
            raise GenerationStoreError("ACTIVATION_RECEIPT_ALREADY_EXISTS")
        self._atomic_replace(path, _canonical_json(receipt))

    def publish_generation(self, generation_id: str) -> PublicationResult:
        """Atomically publish a staged generation, then activate it by receipt."""
        self._require_writer_lock()
        self._ensure_writer_layout()
        loaded = self._load_generation(generation_id)
        prior_generation_id: str | None = None
        if self.current_path.exists() or self.current_path.is_symlink():
            pointer, _ = self._read_current_pointer()
            # A malformed pre-existing pointer must not be overwritten by a writer.
            self._load_generation(pointer["generation_id"], pointer["generation_descriptor_sha256"])
            prior_generation_id = pointer["generation_id"]
        activation_id = f"a-{uuid.uuid4().hex}"
        publication_kind = "INITIAL" if prior_generation_id is None else "UPDATE"
        pointer = {
            "schema_version": CURRENT_SCHEMA,
            "generation_id": generation_id,
            "generation_descriptor_sha256": loaded["hashes"]["descriptor"],
            "activation_id": activation_id,
            "publication_kind": publication_kind,
            "prior_generation_id": prior_generation_id,
        }
        before_pointer = self._now_aware()
        self._atomic_replace(self.current_path, _canonical_json(pointer))
        current_bytes = self._read_regular_bytes(self.current_path)
        current_sha = _sha256(current_bytes)
        activated_at = self._now_aware()
        if activated_at < before_pointer:
            raise PublicationIncompleteError("ACTIVATION_CLOCK_REGRESSION")
        receipt = {
            "schema_version": ACTIVATION_SCHEMA,
            "activation_id": activation_id,
            "generation_id": generation_id,
            "generation_descriptor_sha256": loaded["hashes"]["descriptor"],
            "current_sha256": current_sha,
            # Captured only after the CURRENT replacement and directory fsync return.
            "activated_at": activated_at.isoformat(),
        }
        try:
            self._write_activation_receipt(activation_id, receipt)
        except Exception as error:
            raise PublicationIncompleteError("ACTIVATION_RECEIPT_UNAVAILABLE") from error
        return PublicationResult(generation_id, activation_id, current_sha, activated_at, publication_kind)

    def create_and_publish(self, generation_id: str, *, ledger_bytes: bytes, manifest_bytes: bytes, state_bytes: bytes) -> PublicationResult:
        with self.writer_lock():
            self.stage_generation(generation_id, ledger_bytes=ledger_bytes, manifest_bytes=manifest_bytes, state_bytes=state_bytes)
            return self.publish_generation(generation_id)

    def resolve(self, *, mode: str, prediction_cutoff: datetime) -> GenerationContext:
        """Resolve CURRENT exactly once and return a fully verified context."""
        if mode == MODE_LEGACY:
            raise GenerationModeError("LEGACY_MODE_REQUIRES_A_SEPARATE_READER")
        if mode != MODE_GENERATION:
            raise GenerationModeError("UNKNOWN_LEDGER_MODE")
        if not isinstance(prediction_cutoff, datetime) or prediction_cutoff.tzinfo is None or prediction_cutoff.utcoffset() is None:
            raise GenerationValidationError("PREDICTION_CUTOFF_TIMEZONE_REQUIRED")
        cutoff = prediction_cutoff.astimezone(timezone.utc)
        self._assert_real_directory(self.root)
        self._assert_real_directory(self.activations_path)
        pointer, pointer_bytes = self._read_current_pointer()
        loaded = self._load_generation(pointer["generation_id"], pointer["generation_descriptor_sha256"])
        activation_path = self._activation_path(pointer["activation_id"])
        receipt = _json_object(self._read_regular_bytes(activation_path), "ACTIVATION_RECEIPT")
        if receipt.get("schema_version") != ACTIVATION_SCHEMA:
            raise GenerationValidationError("ACTIVATION_RECEIPT_SCHEMA_MISMATCH")
        if (receipt.get("activation_id") != pointer["activation_id"]
                or receipt.get("generation_id") != pointer["generation_id"]
                or receipt.get("generation_descriptor_sha256") != loaded["hashes"]["descriptor"]
                or receipt.get("current_sha256") != _sha256(pointer_bytes)):
            raise GenerationValidationError("ACTIVATION_RECEIPT_BINDING_MISMATCH")
        try:
            activated_at = datetime.fromisoformat(str(receipt["activated_at"]))
        except (KeyError, TypeError, ValueError) as error:
            raise GenerationValidationError("ACTIVATION_TIME_INVALID") from error
        if activated_at.tzinfo is None or activated_at.utcoffset() is None:
            raise GenerationValidationError("ACTIVATION_TIME_TIMEZONE_REQUIRED")
        activated_at = activated_at.astimezone(timezone.utc)
        if cutoff < activated_at:
            raise GenerationUnavailable("GENERATION_NOT_AVAILABLE_AT_CUTOFF")
        return GenerationContext(
            mode=MODE_GENERATION,
            root=self.root,
            generation_id=pointer["generation_id"],
            generation_dir=loaded["directory"],
            ledger_path=loaded["paths"]["ledger"],
            manifest_path=loaded["paths"]["manifest"],
            state_path=loaded["paths"]["state"],
            descriptor_path=loaded["descriptor_path"],
            activation_path=activation_path,
            ledger_bytes=loaded["ledger"],
            manifest=_freeze_mapping(loaded["manifest"]),
            state=_freeze_mapping(loaded["state"]),
            descriptor=_freeze_mapping(loaded["descriptor"]),
            ledger_sha256=loaded["hashes"]["ledger"],
            manifest_sha256=loaded["hashes"]["manifest"],
            state_sha256=loaded["hashes"]["state"],
            descriptor_sha256=loaded["hashes"]["descriptor"],
            current_sha256=_sha256(pointer_bytes),
            activation_id=pointer["activation_id"],
            activated_at=activated_at,
        )
