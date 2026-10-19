#!/usr/bin/env python3
"""Deterministic Phase 7 Attack-Type input provider (no model inference).

RESEARCH_REPLAY reconstructs frozen research inputs from the canonical ledger
and Phase 4 fixed input cache.  LIVE reads only race_master, race_entry and
exhibition_live, plus the canonical ledger.  It never queries result tables.
"""
from __future__ import annotations

import csv
import hashlib
import json
import math
import sys
from datetime import datetime, timedelta, timezone
from io import StringIO
from pathlib import Path

ROOT = Path(__file__).resolve().parent
REPO = ROOT.parent
OUT = ROOT / "output"
sys.path.insert(0, str(ROOT))
from build_course4_attack_history_canonical_ledger_phase9_1b import (  # noqa: E402
    build_ledger_index,
    history_features_from_canonical_ledger,
)
from course4_attack_ledger_generation_store import MODE_GENERATION, MODE_LEGACY, GenerationContext  # noqa: E402

CONTRACT_VERSION = "course4-tenkai-attack-type-live-input-v2"
FEATURE_ORDER = (
    "course4_history_n", "course4_history_makuri_rate_pct", "course4_history_makurizashi_rate_pct",
    "course4_ex_st", "course4_exhibition_time", "course4_lap_time", "course4_around_time", "course4_straight_time",
)
ARTIFACT = OUT / "course4_attack_type_phase7_final_model.json"
SPEC = OUT / "course4_attack_type_phase7_spec_v3.json"
LEDGER = OUT / "course4_attack_history_canonical_ledger_v1.csv"
MANIFEST = OUT / "course4_attack_history_canonical_ledger_v1_manifest.json"
LEDGER_STATE = OUT / "course4_attack_history_canonical_ledger_v1_live_state.json"
PHASE4_INPUT = OUT / "course4_attack_phase4_input_20241001_20260930.csv"
EXPECTED_HASHES = {
    "artifact": "e0f3674e3e863a4a251aafb2e87530ebba85d4c393982d019fdccd124f3707b4",
    "spec": "e36d6d2068e583629328e85cdf3d2aa1c516711d35472439d811bdbf8a3f9343",
    "ledger": "d4d97c47506e094d0093e99f2ad2fb84664db6fab09d0e90b1f2a0d56941b593",
}
FAIL_REASONS = (
    "ARTIFACT_HASH_MISMATCH", "LEDGER_HASH_MISMATCH", "LEDGER_NOT_AVAILABLE_AT_CUTOFF",
    "EXHIBITION_NOT_COMPLETE", "EXHIBITION_AFTER_CUTOFF", "ENTRY_MAPPING_INCOMPLETE",
    "NON_WAKUNARI", "COURSE4_NOT_RESOLVABLE", "MISSING_REQUIRED_FEATURE", "INPUT_CONTRACT_VERSION_MISMATCH",
    "RESEARCH_SOURCE_NOT_FOUND", "FEATURE_SCHEMA_MISMATCH", "INVALID_MODE", "GENERATION_CONTEXT_REQUIRED",
)


def sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def num(value):
    try:
        x = float(value)
        return x if math.isfinite(x) else None
    except (TypeError, ValueError):
        return None


def read_csv(path: Path):
    with path.open(encoding="utf-8") as f:
        return list(csv.DictReader(f))


def read_csv_bytes(data: bytes):
    try:
        return list(csv.DictReader(StringIO(data.decode("utf-8"))))
    except UnicodeDecodeError as error:
        raise ValueError("GENERATION_LEDGER_ENCODING_INVALID") from error


def canonical_json(value) -> str:
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"), allow_nan=False)


def display_path(path: Path) -> str:
    try:
        return str(path.relative_to(REPO))
    except ValueError:
        return str(path)


def parse_cutoff(value) -> datetime:
    if isinstance(value, datetime):
        parsed = value
    else:
        parsed = datetime.fromisoformat(str(value))
    if parsed.tzinfo is None:
        raise ValueError("prediction_cutoff must include an explicit timezone offset")
    return parsed


class AttackTypeLiveInputProvider:
    """Build inputs from either legacy files or one supplied immutable context.

    GENERATION mode intentionally has no Store instance and cannot resolve
    CURRENT.  The inference core supplies exactly one verified context for a
    request, preventing a provider-side generation switch.
    """

    def __init__(self, *, ledger_mode: str = MODE_LEGACY, paths=None, expected_hashes=None):
        if ledger_mode not in (MODE_LEGACY, MODE_GENERATION):
            raise ValueError("INVALID_LEDGER_MODE")
        paths = paths or {}
        self.ledger_mode = ledger_mode
        self.expected_hashes = dict(EXPECTED_HASHES if expected_hashes is None else expected_hashes)
        self.paths = {
            "artifact": Path(paths.get("artifact", ARTIFACT)), "spec": Path(paths.get("spec", SPEC)),
            "ledger": Path(paths.get("ledger", LEDGER)), "manifest": Path(paths.get("manifest", MANIFEST)),
            "state": Path(paths.get("state", LEDGER_STATE)), "research": Path(paths.get("research", PHASE4_INPUT)),
        }
        self.artifact = json.loads(self.paths["artifact"].read_text(encoding="utf-8"))
        self.spec = json.loads(self.paths["spec"].read_text(encoding="utf-8"))
        self.hashes = {"artifact": sha256(self.paths["artifact"]), "spec": sha256(self.paths["spec"])}
        self.manifest = None
        self.ledger = []
        self.ledger_index = {}
        self.research = {}
        self.ledger_state = None
        if self.ledger_mode == MODE_LEGACY:
            self.manifest = json.loads(self.paths["manifest"].read_text(encoding="utf-8"))
            self.ledger = read_csv(self.paths["ledger"])
            self.ledger_index = build_ledger_index(self.ledger)
            self.research = {r["race_code"]: r for r in read_csv(self.paths["research"])}
            self.hashes.update({"ledger": sha256(self.paths["ledger"]), "ledger_manifest": sha256(self.paths["manifest"]), "research_input": sha256(self.paths["research"])})
            self.ledger_state = json.loads(self.paths["state"].read_text(encoding="utf-8")) if self.paths["state"].exists() else None

    def _identity_errors(self, generation_context: GenerationContext | None = None):
        errors = []
        if self.hashes["artifact"] != self.expected_hashes["artifact"] or self.hashes["spec"] != self.expected_hashes["spec"]:
            errors.append("ARTIFACT_HASH_MISMATCH")
        if self.ledger_mode == MODE_LEGACY:
            state_ok = self.ledger_state and self.ledger_state.get("ledger_sha256") == self.hashes["ledger"] and self.ledger_state.get("manifest_sha256") == self.hashes["ledger_manifest"] and self.ledger_state.get("baseline_source_sha256") == self.manifest.get("baseline_source_sha256") and self.ledger_state.get("ledger_version") == "v1"
            if (self.hashes["ledger"] != self.expected_hashes["ledger"] and not state_ok) or self.manifest.get("ledger_sha256") != self.hashes["ledger"]:
                errors.append("LEDGER_HASH_MISMATCH")
            manifest = self.manifest
        else:
            manifest = generation_context.manifest if generation_context is not None else None
            if generation_context is None:
                errors.append("GENERATION_CONTEXT_REQUIRED")
            else:
                try:
                    manifest_ok = (
                        generation_context.mode == MODE_GENERATION
                        and hashlib.sha256(generation_context.ledger_bytes).hexdigest() == generation_context.ledger_sha256
                        and manifest.get("ledger_sha256") == generation_context.ledger_sha256
                        and generation_context.state.get("generation_id") == generation_context.generation_id
                        and generation_context.state.get("ledger_sha256") == generation_context.ledger_sha256
                        and generation_context.state.get("manifest_sha256") == generation_context.manifest_sha256
                        and generation_context.descriptor.get("generation_id") == generation_context.generation_id
                    )
                except (AttributeError, TypeError):
                    manifest_ok = False
                if not manifest_ok:
                    errors.append("LEDGER_HASH_MISMATCH")
        if tuple(self.artifact.get("feature_order", [])) != FEATURE_ORDER or tuple(self.spec.get("final_feature_order", [])) != FEATURE_ORDER:
            errors.append("FEATURE_SCHEMA_MISMATCH")
        if manifest is None or manifest.get("ledger_version") != "v1":
            errors.append("LEDGER_HASH_MISMATCH")
        return errors

    def _ledger_identity(self, generation_context: GenerationContext | None):
        if self.ledger_mode == MODE_LEGACY:
            return {"path": display_path(self.paths["ledger"]), "sha256": self.hashes["ledger"], "manifest_path": display_path(self.paths["manifest"]), "manifest_sha256": self.hashes["ledger_manifest"], "ledger_version": self.manifest.get("ledger_version")}
        if generation_context is None:
            return {"mode": MODE_GENERATION, "unavailable": True}
        return {"mode": MODE_GENERATION, "generation_id": generation_context.generation_id,
                "sha256": generation_context.ledger_sha256, "manifest_sha256": generation_context.manifest_sha256,
                "state_sha256": generation_context.state_sha256, "descriptor_sha256": generation_context.descriptor_sha256,
                "activation_id": generation_context.activation_id, "activated_at": generation_context.activated_at.isoformat(),
                "ledger_version": generation_context.manifest.get("ledger_version")}

    def _response(self, *, race_code, cutoff, mode, available, reasons, features=None, raw_input=None, mapping=None, history=None, generation_context=None):
        payload = {
            "contract_version": CONTRACT_VERSION, "signal_id": "ATTACK_TYPE", "stage": "POST_EXHIBITION",
            "mode": mode, "race_code": race_code, "prediction_cutoff": cutoff.isoformat() if isinstance(cutoff, datetime) else str(cutoff),
            "artifact_identity": {"path": display_path(self.paths["artifact"]), "sha256": self.hashes["artifact"]},
            "spec_identity": {"path": display_path(self.paths["spec"]), "sha256": self.hashes["spec"]},
            "ledger_identity": self._ledger_identity(generation_context),
            "feature_order": list(FEATURE_ORDER), "raw_input_payload": raw_input or {}, "feature_vector": features,
            "entry_mapping": mapping or {}, "history_audit": history or {},
            "prediction_available": available, "missing_reason": reasons, "blocked_reason": reasons,
        }
        payload["input_sha256"] = hashlib.sha256(canonical_json(payload).encode("utf-8")).hexdigest()
        return payload

    def _history(self, player_id, race_date, cutoff, mode, generation_context=None):
        if self.ledger_mode == MODE_GENERATION:
            if generation_context is None:
                return None, {"reason": "GENERATION_CONTEXT_REQUIRED"}
            try:
                activation = generation_context.activated_at
                if activation.tzinfo is None or activation.utcoffset() is None:
                    raise ValueError("activation timezone")
                ledger = read_csv_bytes(generation_context.ledger_bytes)
                for row in ledger:
                    if str(row.get("baseline_frozen")) not in ("1", "True", "true"):
                        observed = datetime.fromisoformat(str(row.get("first_observed_at", "")))
                        if observed.tzinfo is None or observed.utcoffset() is None:
                            raise ValueError("observation timezone")
                index = build_ledger_index(ledger)
            except (TypeError, ValueError):
                return None, {"reason": "LEDGER_HASH_MISMATCH"}
            manifest = generation_context.manifest
        else:
            activation = datetime.fromisoformat(self.manifest["ledger_activation_at"])
            index = self.ledger_index
            manifest = self.manifest
        if activation.tzinfo is None or activation.utcoffset() is None:
            return None, {"reason": "LEDGER_NOT_AVAILABLE_AT_CUTOFF"}
        if mode == "LIVE" and cutoff < activation:
            return None, {"reason": "LEDGER_NOT_AVAILABLE_AT_CUTOFF", "ledger_activation_at": activation.isoformat()}
        result = history_features_from_canonical_ledger(
            index, player_id, race_date, cutoff if mode == "LIVE" else None,
            research_reproduction=(mode == "RESEARCH_REPLAY"), ledger_activation_at=activation,
        )
        audit = {"ledger_mode": "baseline_research_replay" if mode == "RESEARCH_REPLAY" else "live_available_at", "ledger_activation_at": activation.isoformat()}
        if self.ledger_mode == MODE_GENERATION:
            audit.update({"generation_id": generation_context.generation_id, "ledger_sha256": generation_context.ledger_sha256,
                          "manifest_sha256": generation_context.manifest_sha256})
        return result, audit

    def build(self, race_code: str, prediction_cutoff, mode: str, input_contract_version: str = CONTRACT_VERSION, *, generation_context: GenerationContext | None = None):
        mode = str(mode).upper()
        try:
            cutoff = parse_cutoff(prediction_cutoff)
        except ValueError:
            # Keep an explicit caller-supplied value in the fail-closed payload.
            return self._response(race_code=race_code, cutoff=prediction_cutoff, mode=mode, available=False, reasons=["INPUT_CONTRACT_VERSION_MISMATCH"], raw_input={"cutoff_parse_error": True}, generation_context=generation_context)
        errors = self._identity_errors(generation_context)
        if input_contract_version != CONTRACT_VERSION:
            errors.append("INPUT_CONTRACT_VERSION_MISMATCH")
        if mode not in ("RESEARCH_REPLAY", "LIVE") or (self.ledger_mode == MODE_GENERATION and mode != "LIVE"):
            errors.append("INVALID_MODE")
        if errors:
            return self._response(race_code=race_code, cutoff=cutoff, mode=mode, available=False, reasons=sorted(set(errors)), generation_context=generation_context)
        if mode == "RESEARCH_REPLAY":
            return self._research_replay(race_code, cutoff)
        return self._live(race_code, cutoff, generation_context=generation_context)

    def _research_replay(self, race_code, cutoff):
        source = self.research.get(race_code)
        if source is None:
            return self._response(race_code=race_code, cutoff=cutoff, mode="RESEARCH_REPLAY", available=False, reasons=["RESEARCH_SOURCE_NOT_FOUND"])
        race_date = datetime.strptime(source["race_date"], "%Y-%m-%d").date()
        player_id = source["course4_player_id"]
        history, audit = self._history(player_id, race_date, cutoff, "RESEARCH_REPLAY")
        features = [float(history["course4_history_n"]), history["course4_history_makuri_rate_pct"], history["course4_history_makurizashi_rate_pct"]]
        for f in FEATURE_ORDER[3:]:
            features.append(num(source[f]))
        missing = [f for f, v in zip(FEATURE_ORDER, features) if v is None]
        # Missing research exhibition values are preserved exactly; they are a
        # valid replay of the frozen training payload, not a live prediction.
        return self._response(race_code=race_code, cutoff=cutoff, mode="RESEARCH_REPLAY", available=True, reasons=[], features=features,
                              raw_input={"research_source": str(PHASE4_INPUT.relative_to(REPO)), "research_source_sha256": self.hashes["research_input"], "course4_player_id": player_id},
                              mapping={"research_fixed_population": True, "course4_player_id": player_id}, history={**audit, "missing_research_values": missing})

    def _live(self, race_code, cutoff, *, generation_context=None):
        # The live query intentionally reads no outcome/result table.
        sys.path.insert(0, str(ROOT))
        from slit_validate_v2 import connect_db  # noqa: WPS433
        local_cutoff = cutoff.astimezone(timezone(timedelta(hours=9))).replace(tzinfo=None)
        query = """
          WITH entries AS (
            SELECT re.race_code,rm.race_date,re.lane_number,re.player_id::text
            FROM boat_race.race_entry re JOIN boat_race.race_master rm ON rm.race_code=re.race_code
            WHERE re.race_code=%s
          ), ex_before AS (
            SELECT DISTINCT ON (e.race_code,e.player_id)
                   e.race_code,e.player_id::text,e.entry_course,e.start_timing,e.exhibition_time,
                   e.lap_time,e.around_time,e.straight_time,e.created_date
            FROM boat_race.exhibition_live e WHERE e.race_code=%s AND e.created_date <= %s
            ORDER BY e.race_code,e.player_id,e.created_date DESC NULLS LAST,e.entry_course
          ), ex_after AS (
            SELECT count(*)::int FROM boat_race.exhibition_live e WHERE e.race_code=%s AND e.created_date > %s
          )
          SELECT en.race_date,en.lane_number,en.player_id,ex.entry_course,ex.start_timing,ex.exhibition_time,
                 ex.lap_time,ex.around_time,ex.straight_time,ex.created_date,(SELECT * FROM ex_after)
          FROM entries en LEFT JOIN ex_before ex ON ex.race_code=en.race_code AND ex.player_id=en.player_id
          ORDER BY en.lane_number
        """
        with connect_db() as conn, conn.cursor() as cur:
            cur.execute(query, (race_code, race_code, local_cutoff, race_code, local_cutoff))
            rows = cur.fetchall()
        if not rows:
            return self._response(race_code=race_code, cutoff=cutoff, mode="LIVE", available=False, reasons=["ENTRY_MAPPING_INCOMPLETE"], generation_context=generation_context)
        race_date = rows[0][0]
        records, reasons = [], []
        for row in rows:
            records.append({"race_date": row[0].isoformat(), "lane": row[1], "player_id": str(row[2]).strip(), "entry_course": row[3],
                            "course4_ex_st": num(row[4]), "course4_exhibition_time": num(row[5]), "course4_lap_time": num(row[6]),
                            "course4_around_time": num(row[7]), "course4_straight_time": num(row[8]), "created_date": row[9].isoformat() if row[9] else None})
        after_count = int(rows[0][10] or 0)
        if len(records) != 6 or any(r["entry_course"] is None for r in records) or len({r["player_id"] for r in records}) != 6:
            reasons.append("ENTRY_MAPPING_INCOMPLETE")
        courses = [r["entry_course"] for r in records if r["entry_course"] is not None]
        if set(courses) != set(range(1, 7)) or len(courses) != 6:
            reasons.append("EXHIBITION_NOT_COMPLETE")
        if courses and any(r["entry_course"] != r["lane"] for r in records if r["entry_course"] is not None):
            reasons.append("NON_WAKUNARI")
        course4 = next((r for r in records if r["entry_course"] == 4), None)
        if course4 is None:
            reasons.append("COURSE4_NOT_RESOLVABLE")
        if after_count and not any(r["created_date"] is not None for r in records):
            reasons.append("EXHIBITION_AFTER_CUTOFF")
        if course4 is not None:
            missing_ex = [f for f in FEATURE_ORDER[3:] if course4[f] is None]
            if missing_ex:
                reasons.append("MISSING_REQUIRED_FEATURE")
        else:
            missing_ex = list(FEATURE_ORDER[3:])
        history, history_audit = self._history(course4["player_id"], race_date, cutoff, "LIVE", generation_context=generation_context) if course4 else (None, {"reason": "COURSE4_NOT_RESOLVABLE"})
        if history is None:
            reasons.append(history_audit["reason"])
        if reasons:
            return self._response(race_code=race_code, cutoff=cutoff, mode="LIVE", available=False, reasons=sorted(set(reasons)), raw_input={"exhibition_rows": records, "after_cutoff_row_count": after_count},
                                  mapping={"entry_courses": courses, "wakunari": False}, history=history_audit, generation_context=generation_context)
        features = [float(history["course4_history_n"]), history["course4_history_makuri_rate_pct"], history["course4_history_makurizashi_rate_pct"]] + [course4[f] for f in FEATURE_ORDER[3:]]
        return self._response(race_code=race_code, cutoff=cutoff, mode="LIVE", available=True, reasons=[], features=features,
                              raw_input={"exhibition_rows": records, "after_cutoff_row_count": after_count},
                              mapping={"entry_courses": courses, "wakunari": True, "course4_player_id": course4["player_id"]}, history=history_audit, generation_context=generation_context)


def build(race_code: str, prediction_cutoff, mode: str, input_contract_version: str = CONTRACT_VERSION):
    """Convenience entry point; callers must explicitly supply a cutoff."""
    return AttackTypeLiveInputProvider().build(race_code, prediction_cutoff, mode, input_contract_version)
