#!/usr/bin/env python3
"""Phase 9-1f A5 feature provider.  It never performs model inference."""
from __future__ import annotations

import bisect
import csv
import hashlib
import io
import json
import sys
from calendar import monthrange
from datetime import date, datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parent
REPO = ROOT.parent
OUT = ROOT / "output"
sys.path.insert(0, str(ROOT))
from build_course4_attack_history_canonical_ledger_phase9_1b import build_ledger_index
from course4_attack_ledger_generation_store import MODE_GENERATION, MODE_LEGACY, AttackTypeLedgerGenerationStore, GenerationContext, GenerationStoreError  # noqa: E402
from course4_tenkai_canonical_input import CANONICAL_VERSION, SNAPSHOT_VERSION, StaticSnapshotStore, canonical_json, parse_cutoff, sha256, value  # noqa: E402

CONTRACT_VERSION = "course4-attack-a5-live-input/v1"
ARTIFACT = OUT / "course4_attack_phase4r_final_model.json"
BASELINE = OUT / "course4_attack_a5_canonical_input_v1.csv"
MANIFEST = OUT / "course4_attack_a5_canonical_input_v1_manifest.json"
LEDGER = OUT / "course4_attack_history_canonical_ledger_v1.csv"
LEDGER_MANIFEST = OUT / "course4_attack_history_canonical_ledger_v1_manifest.json"
EXPECTED_ARTIFACT_SHA256 = "39808675efde92bfcbb73d69f88e915260f7dd0e0c48636d1b2775d6b2874176"


def twelve_months_before(target: date) -> date:
    return date(target.year - 1, target.month, min(target.day, monthrange(target.year - 1, target.month)[1]))


def _read_csv_bytes(payload: bytes) -> list[dict]:
    try:
        return list(csv.DictReader(io.StringIO(payload.decode("utf-8"))))
    except (UnicodeDecodeError, csv.Error) as error:
        raise ValueError("LEDGER_HASH_MISMATCH") from error


def _aware_timestamp(raw: object, reason: str) -> datetime:
    try:
        value_at = datetime.fromisoformat(str(raw))
    except (TypeError, ValueError) as error:
        raise ValueError(reason) from error
    if value_at.tzinfo is None or value_at.utcoffset() is None:
        raise ValueError(reason)
    return value_at.astimezone(timezone.utc)


class AttackA5LiveInputProvider:
    """Build A5 inputs from either the legacy ledger or one pinned generation."""

    def __init__(self, snapshot_store=None, exhibition_provider=None, *, ledger_mode=MODE_LEGACY,
                 generation_root=None, generation_store_factory=AttackTypeLedgerGenerationStore):
        if ledger_mode not in (MODE_LEGACY, MODE_GENERATION):
            raise ValueError("INVALID_LEDGER_MODE")
        self.ledger_mode = ledger_mode
        self.generation_root = Path(generation_root).absolute() if generation_root is not None else None
        self.generation_store_factory = generation_store_factory
        self.artifact = json.loads(ARTIFACT.read_text(encoding="utf-8"))
        self.manifest = json.loads(MANIFEST.read_text(encoding="utf-8"))
        with BASELINE.open(encoding="utf-8") as handle:
            self.baseline = {row["race_code"]: row for row in csv.DictReader(handle)}
        self.ledger = None
        self.index = {}
        self.history_dates = {}
        if self.ledger_mode == MODE_LEGACY:
            with LEDGER.open(encoding="utf-8") as handle:
                self.ledger = list(csv.DictReader(handle))
            self.index = build_ledger_index(self.ledger)
            self.history_dates = {player_id: [date.fromisoformat(row["race_date"]) for row in rows] for player_id, rows in self.index.items()}
        self.store = snapshot_store or StaticSnapshotStore()
        self.exhibition_provider = exhibition_provider
        self.feature_order = tuple(self.artifact["feature_names"])
        self.identities = {"artifact": sha256(ARTIFACT), "baseline": sha256(BASELINE), "manifest": sha256(MANIFEST),
                           "ledger": sha256(LEDGER) if self.ledger_mode == MODE_LEGACY else None}

    def _errors(self):
        errors = []
        if self.identities["artifact"] != EXPECTED_ARTIFACT_SHA256 or self.manifest.get("artifact_sha256") != self.identities["artifact"]:
            errors.append("ARTIFACT_HASH_MISMATCH")
        if self.manifest.get("baseline_sha256") != self.identities["baseline"] or self.manifest.get("canonical_version") != CANONICAL_VERSION:
            errors.append("CANONICAL_BASELINE_MISMATCH")
        if tuple(self.manifest.get("feature_order", ())) != self.feature_order:
            errors.append("FEATURE_SCHEMA_MISMATCH")
        if self.ledger_mode == MODE_GENERATION and self.generation_root is None:
            errors.append("GENERATION_CONTEXT_REQUIRED")
        return errors

    def _ledger_identity(self, context: GenerationContext | None):
        if self.ledger_mode == MODE_LEGACY:
            return {"path": str(LEDGER.relative_to(REPO)), "sha256": self.identities["ledger"]}
        if context is None:
            return {"mode": MODE_GENERATION, "unavailable": True}
        return {"mode": MODE_GENERATION, "generation_id": context.generation_id, "sha256": context.ledger_sha256,
                "manifest_sha256": context.manifest_sha256, "state_sha256": context.state_sha256,
                "descriptor_sha256": context.descriptor_sha256, "activation_id": context.activation_id,
                "activated_at": context.activated_at.isoformat(), "ledger_version": context.manifest.get("ledger_version")}

    def _response(self, race_code, cutoff, mode, available, reasons, vector=None, raw=None, mapping=None, snapshot=None, context=None):
        payload = {"contract_version": CONTRACT_VERSION, "signal_id": "ATTACK_A5", "stage": "POST_EXHIBITION", "mode": mode,
                   "race_code": race_code, "prediction_cutoff": cutoff.isoformat(), "feature_order": list(self.feature_order),
                   "feature_vector": vector, "feature_available": available, "missing_reason": sorted(set(reasons)), "blocked_reason": sorted(set(reasons)),
                   "artifact_identity": {"path": str(ARTIFACT.relative_to(REPO)), "sha256": self.identities["artifact"]},
                   "canonical_identity": {"path": str(BASELINE.relative_to(REPO)), "sha256": self.identities["baseline"], "version": CANONICAL_VERSION},
                   "ledger_identity": self._ledger_identity(context),
                   "raw_input": raw or {}, "entry_mapping": mapping or {}, "snapshot_identity": snapshot or {}}
        payload["input_sha256"] = hashlib.sha256(canonical_json(payload).encode()).hexdigest()
        return payload

    @staticmethod
    def _history_vector(rows):
        try:
            n = len(rows)
            rate = lambda count: None if n == 0 else 100.0 * count / n
            makuri = sum(int(row["is_makuri"]) for row in rows)
            makurizashi = sum(int(row["is_makurizashi"]) for row in rows)
            return [float(n), rate(sum(int(row["final_rank"]) == 1 for row in rows)), rate(sum(int(row["final_rank"]) <= 2 for row in rows)),
                    rate(sum(int(row["final_rank"]) <= 3 for row in rows)), rate(makuri), rate(makurizashi), rate(makuri + makurizashi)]
        except (KeyError, TypeError, ValueError):
            return None

    def _legacy_research_history(self, player_id, target):
        rows = self.index.get(str(player_id), [])
        dates = self.history_dates.get(str(player_id), [])
        return self._history_vector(rows[bisect.bisect_left(dates, twelve_months_before(target)):bisect.bisect_left(dates, target)])

    def _live_history(self, player_id, target, cutoff, context):
        try:
            if self.ledger_mode == MODE_GENERATION:
                if context is None or context.mode != MODE_GENERATION:
                    return None, "GENERATION_CONTEXT_REQUIRED"
                if hashlib.sha256(context.ledger_bytes).hexdigest() != context.ledger_sha256:
                    return None, "LEDGER_HASH_MISMATCH"
                if context.manifest.get("ledger_sha256") != context.ledger_sha256 or context.state.get("generation_id") != context.generation_id:
                    return None, "GENERATION_CONTEXT_MISMATCH"
                activation = _aware_timestamp(context.activated_at, "LEDGER_NOT_AVAILABLE_AT_CUTOFF")
                rows = build_ledger_index(_read_csv_bytes(context.ledger_bytes)).get(str(player_id), [])
            else:
                ledger_manifest = json.loads(LEDGER_MANIFEST.read_text(encoding="utf-8"))
                activation = _aware_timestamp(ledger_manifest.get("ledger_activation_at"), "LEDGER_NOT_AVAILABLE_AT_CUTOFF")
                rows = self.index.get(str(player_id), [])
            cutoff = _aware_timestamp(cutoff, "PREDICTION_CUTOFF_TIMEZONE_REQUIRED")
        except (OSError, TypeError, ValueError, json.JSONDecodeError):
            return None, "LEDGER_NOT_AVAILABLE_AT_CUTOFF"
        if cutoff < activation:
            return None, "LEDGER_NOT_AVAILABLE_AT_CUTOFF"
        included = []
        try:
            lo = twelve_months_before(target)
            for row in rows:
                event_date = date.fromisoformat(str(row["race_date"]))
                if not (lo <= event_date < target):
                    continue
                if str(row.get("baseline_frozen")) not in ("1", "True", "true", "1"):
                    if _aware_timestamp(row.get("first_observed_at"), "LEDGER_OBSERVATION_TIME_INVALID") > cutoff:
                        continue
                included.append(row)
        except (KeyError, TypeError, ValueError):
            return None, "LEDGER_OBSERVATION_TIME_INVALID"
        history = self._history_vector(included)
        return (history, None) if history is not None else (None, "LEDGER_HISTORY_INVALID")

    def _static_vector(self, record, exhibit, history):
        course, overall, count = value(record.get("course_average_st")), value(record.get("overall_average_st")), value(record.get("course_entry_count"))
        smoothed = None if course is None or overall is None or count is None else overall + count / (count + 20.0) * (course - overall)
        return history + [smoothed, value(record.get("course_average_rank")), count,
            value(exhibit.get("course4_ex_st")), value(exhibit.get("course4_exhibition_time")), value(exhibit.get("course4_lap_time")), value(exhibit.get("course4_around_time")), value(exhibit.get("course4_straight_time")),
            value(record.get("motor_2ren_rate")), value(record.get("boat_2ren_rate")), value(record.get("national_win_rate")), value(record.get("local_win_rate")), value(record.get("national_2ren_rate")), value(record.get("local_2ren_rate"))]

    def _research(self, race_code, cutoff):
        row = self.baseline.get(race_code)
        if row is None:
            return self._response(race_code, cutoff, "RESEARCH_REPLAY", False, ["RESEARCH_SOURCE_NOT_FOUND"])
        history = self._legacy_research_history(row["course4_player_id"], date.fromisoformat(row["race_date"]))
        if history is None:
            return self._response(race_code, cutoff, "RESEARCH_REPLAY", False, ["LEDGER_HISTORY_INVALID"])
        record = {key.removeprefix("raw_"): value(val) for key, val in row.items() if key.startswith("raw_")}
        record.update({"course_average_st": record.get("course4_average_st"), "course_entry_count": record.get("course4_entry_count"), "course_average_rank": record.get("course4_average_st_rank")})
        exhibit = {f: record.get(f) for f in ("course4_ex_st", "course4_exhibition_time", "course4_lap_time", "course4_around_time", "course4_straight_time")}
        return self._response(race_code, cutoff, "RESEARCH_REPLAY", True, [], self._static_vector(record, exhibit, history), raw={"source_kind": row["source_kind"], "baseline_frozen": row["baseline_frozen"]}, mapping={"course4_player_id": row["course4_player_id"]})

    def _exhibition(self, race_code, cutoff, context):
        if self.exhibition_provider is None:
            from course4_attack_type_live_input_provider import AttackTypeLiveInputProvider
            provider = AttackTypeLiveInputProvider(ledger_mode=self.ledger_mode)
        else:
            provider = self.exhibition_provider
        if self.ledger_mode == MODE_GENERATION:
            result = provider.build(race_code, cutoff.isoformat(), "LIVE", generation_context=context)
            identity = result.get("ledger_identity", {})
            if context is None or identity.get("generation_id") != context.generation_id or identity.get("sha256") != context.ledger_sha256 or identity.get("manifest_sha256") != context.manifest_sha256:
                return None, result, ["GENERATION_CONTEXT_MISMATCH"]
        else:
            result = provider.build(race_code, cutoff.isoformat(), "LIVE")
        if not result.get("prediction_available"):
            return None, result, result.get("missing_reason") or ["EXHIBITION_CONTRACT_UNAVAILABLE"]
        record = next((item for item in result.get("raw_input", {}).get("exhibition_rows", []) if item.get("entry_course") == 4), None)
        return (record, result, []) if record is not None else (None, result, ["COURSE4_NOT_RESOLVABLE"])

    def _live(self, race_code, cutoff, context):
        try:
            snapshot = self.store.read(race_code)
        except ValueError as error:
            return self._response(race_code, cutoff, "LIVE", False, [str(error)], context=context)
        if snapshot is None:
            return self._response(race_code, cutoff, "LIVE", False, ["PROSPECTIVE_SNAPSHOT_NOT_FOUND"], context=context)
        reasons = []
        if snapshot.get("snapshot_version") != SNAPSHOT_VERSION or snapshot.get("canonical_version") != CANONICAL_VERSION:
            reasons.append("PROSPECTIVE_SNAPSHOT_VERSION_MISMATCH")
        try:
            if _aware_timestamp(snapshot["captured_at"], "SNAPSHOT_NOT_AVAILABLE_AT_CUTOFF") > cutoff:
                reasons.append("SNAPSHOT_NOT_AVAILABLE_AT_CUTOFF")
        except (KeyError, ValueError):
            reasons.append("SNAPSHOT_NOT_AVAILABLE_AT_CUTOFF")
        static = snapshot.get("lanes", {}).get("4")
        if not isinstance(static, dict):
            reasons.append("PROSPECTIVE_SNAPSHOT_INCOMPLETE")
        exhibit, exhibition_payload, exhibit_reasons = self._exhibition(race_code, cutoff, context)
        reasons.extend(exhibit_reasons)
        snapshot_identity = {"path": str(self.store.path(race_code)), "sha256": snapshot.get("snapshot_sha256")}
        if reasons:
            return self._response(race_code, cutoff, "LIVE", False, reasons, raw={"snapshot_static": static}, mapping=exhibition_payload.get("entry_mapping", {}), snapshot=snapshot_identity, context=context)
        if str(static.get("player_id")) != str(exhibit.get("player_id")):
            return self._response(race_code, cutoff, "LIVE", False, ["SNAPSHOT_ENTRY_MAPPING_MISMATCH"], snapshot=snapshot_identity, context=context)
        try:
            target = date.fromisoformat(str(snapshot["race_date"]))
        except (KeyError, ValueError):
            return self._response(race_code, cutoff, "LIVE", False, ["PROSPECTIVE_SNAPSHOT_INCOMPLETE"], snapshot=snapshot_identity, context=context)
        history, history_error = self._live_history(static["player_id"], target, cutoff, context)
        if history is None:
            return self._response(race_code, cutoff, "LIVE", False, [history_error], snapshot=snapshot_identity, context=context)
        vector = self._static_vector(static, exhibit, history)
        missing = [name for name, item in zip(self.feature_order, vector) if item is None]
        if missing:
            return self._response(race_code, cutoff, "LIVE", False, ["MISSING_REQUIRED_FEATURE"], vector, raw={"missing_features": missing}, mapping=exhibition_payload.get("entry_mapping", {}), snapshot=snapshot_identity, context=context)
        return self._response(race_code, cutoff, "LIVE", True, [], vector, raw={"history_source": "canonical_history_ledger_v1", "static_source": "prospective_first_write", "exhibition_source": "attack_type_post_exhibition_contract"}, mapping=exhibition_payload.get("entry_mapping", {}), snapshot=snapshot_identity, context=context)

    def _resolve_context(self, cutoff):
        if self.ledger_mode != MODE_GENERATION:
            return None
        try:
            return self.generation_store_factory(self.generation_root).resolve(mode=MODE_GENERATION, prediction_cutoff=cutoff)
        except GenerationStoreError:
            return None

    def build(self, race_code, prediction_cutoff, mode):
        race_code, mode = str(race_code).upper().strip(), str(mode).upper().strip()
        try:
            cutoff = parse_cutoff(prediction_cutoff)
        except ValueError:
            return {"feature_available": False, "missing_reason": ["PREDICTION_CUTOFF_TIMEZONE_REQUIRED"]}
        errors = self._errors()
        if self.ledger_mode == MODE_GENERATION and mode != "LIVE":
            errors.append("INVALID_MODE")
        if errors:
            return self._response(race_code, cutoff, mode, False, errors)
        if mode == "RESEARCH_REPLAY":
            return self._research(race_code, cutoff)
        if mode != "LIVE":
            return self._response(race_code, cutoff, mode, False, ["INVALID_MODE"])
        context = self._resolve_context(cutoff)
        if self.ledger_mode == MODE_GENERATION and context is None:
            return self._response(race_code, cutoff, mode, False, ["GENERATION_CONTEXT_REQUIRED"])
        return self._live(race_code, cutoff, context)
