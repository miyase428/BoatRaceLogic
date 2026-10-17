#!/usr/bin/env python3
"""Phase 9-1f WALL-R feature provider.  It never performs model inference."""
from __future__ import annotations

import csv
import hashlib
import json
import sys
from datetime import datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parent
REPO = ROOT.parent
OUT = ROOT / "output"
sys.path.insert(0, str(ROOT))
from course4_tenkai_canonical_input import CANONICAL_VERSION, SNAPSHOT_VERSION, StaticSnapshotStore, canonical_json, parse_cutoff, sha256, value  # noqa: E402

CONTRACT_VERSION = "course34-wall-live-input/v1"
ARTIFACT = OUT / "course34_wall_phase3r_final_model.json"
BASELINE = OUT / "course34_wall_canonical_input_v1.csv"
MANIFEST = OUT / "course34_wall_canonical_input_v1_manifest.json"
EXPECTED_ARTIFACT_SHA256 = "f5348195d1d05c982f273ce7924c2b0eda5174e243e3e01bb818b87d6f3e768c"


def subtract(left, right):
    return None if left is None or right is None else left - right


class WallRLiveInputProvider:
    def __init__(self, snapshot_store=None, exhibition_provider=None):
        self.artifact = json.loads(ARTIFACT.read_text(encoding="utf-8"))
        self.manifest = json.loads(MANIFEST.read_text(encoding="utf-8"))
        with BASELINE.open(encoding="utf-8") as handle:
            self.baseline = {row["race_code"]: row for row in csv.DictReader(handle)}
        self.store = snapshot_store or StaticSnapshotStore()
        self.exhibition_provider = exhibition_provider
        self.feature_order = tuple(self.artifact["feature_names"])
        self.identities = {"artifact": sha256(ARTIFACT), "baseline": sha256(BASELINE), "manifest": sha256(MANIFEST)}

    def _errors(self):
        errors = []
        if self.identities["artifact"] != EXPECTED_ARTIFACT_SHA256 or self.manifest.get("artifact_sha256") != self.identities["artifact"]:
            errors.append("ARTIFACT_HASH_MISMATCH")
        if self.manifest.get("baseline_sha256") != self.identities["baseline"] or self.manifest.get("canonical_version") != CANONICAL_VERSION:
            errors.append("CANONICAL_BASELINE_MISMATCH")
        if tuple(self.manifest.get("feature_order", ())) != self.feature_order:
            errors.append("FEATURE_SCHEMA_MISMATCH")
        return errors

    def _response(self, race_code, cutoff, mode, available, reasons, vector=None, raw=None, mapping=None, snapshot=None):
        payload = {
            "contract_version": CONTRACT_VERSION, "signal_id": "WALL_R", "stage": "POST_EXHIBITION",
            "mode": mode, "race_code": race_code, "prediction_cutoff": cutoff.isoformat(),
            "feature_order": list(self.feature_order), "feature_vector": vector, "feature_available": available,
            "missing_reason": sorted(set(reasons)), "blocked_reason": sorted(set(reasons)),
            "artifact_identity": {"path": str(ARTIFACT.relative_to(REPO)), "sha256": self.identities["artifact"]},
            "canonical_identity": {"path": str(BASELINE.relative_to(REPO)), "sha256": self.identities["baseline"], "version": CANONICAL_VERSION},
            "manifest_identity": {"path": str(MANIFEST.relative_to(REPO)), "sha256": self.identities["manifest"]},
            "raw_input": raw or {}, "entry_mapping": mapping or {}, "snapshot_identity": snapshot or {},
        }
        payload["input_sha256"] = hashlib.sha256(canonical_json(payload).encode()).hexdigest()
        return payload

    def _features(self, c3, c4, exhibit):
        def smooth(row):
            base, course, count = value(row.get("overall_average_st")), value(row.get("course_average_st")), value(row.get("course_entry_count"))
            weight = 0.0 if count is None else count / (count + 30.0)
            return course if base is None or course is None else base + weight * (course - base), weight
        s3, w3 = smooth(c3); s4, w4 = smooth(c4)
        return [
            subtract(s3, s4), subtract(value(exhibit[3].get("course4_ex_st")), value(exhibit[4].get("course4_ex_st"))),
            subtract(value(c3.get("course_average_rank")), value(c4.get("course_average_rank"))), w4 - w3,
            subtract(value(exhibit[3].get("course4_exhibition_time")), value(exhibit[4].get("course4_exhibition_time"))),
            subtract(value(exhibit[3].get("course4_lap_time")), value(exhibit[4].get("course4_lap_time"))),
            subtract(value(exhibit[3].get("course4_around_time")), value(exhibit[4].get("course4_around_time"))),
            subtract(value(exhibit[3].get("course4_straight_time")), value(exhibit[4].get("course4_straight_time"))),
        ]

    def _research(self, race_code, cutoff):
        row = self.baseline.get(race_code)
        if row is None:
            return self._response(race_code, cutoff, "RESEARCH_REPLAY", False, ["RESEARCH_SOURCE_NOT_FOUND"])
        vector = [value(row.get(f"feature_{name}")) for name in self.feature_order]
        return self._response(race_code, cutoff, "RESEARCH_REPLAY", True, [], vector, raw={"source_kind": row["source_kind"], "baseline_frozen": row["baseline_frozen"]}, mapping={"course3_player_id": row["course3_player_id"], "course4_player_id": row["course4_player_id"]})

    def _exhibition(self, race_code, cutoff):
        if self.exhibition_provider is None:
            from course4_attack_type_live_input_provider import AttackTypeLiveInputProvider
            provider = AttackTypeLiveInputProvider()
        else:
            provider = self.exhibition_provider
        result = provider.build(race_code, cutoff.isoformat(), "LIVE")
        if not result.get("prediction_available"):
            return None, result, result.get("missing_reason") or ["EXHIBITION_CONTRACT_UNAVAILABLE"]
        records = result.get("raw_input", {}).get("exhibition_rows", [])
        by_course = {int(record["entry_course"]): record for record in records if record.get("entry_course") in (3, 4)}
        if set(by_course) != {3, 4}:
            return None, result, ["ENTRY_MAPPING_INCOMPLETE"]
        return by_course, result, []

    def _live(self, race_code, cutoff):
        try:
            snapshot = self.store.read(race_code)
        except ValueError as error:
            return self._response(race_code, cutoff, "LIVE", False, [str(error)])
        if snapshot is None:
            return self._response(race_code, cutoff, "LIVE", False, ["PROSPECTIVE_SNAPSHOT_NOT_FOUND"])
        reasons = []
        if snapshot.get("snapshot_version") != SNAPSHOT_VERSION or snapshot.get("canonical_version") != CANONICAL_VERSION:
            reasons.append("PROSPECTIVE_SNAPSHOT_VERSION_MISMATCH")
        if datetime.fromisoformat(snapshot["captured_at"]) > cutoff:
            reasons.append("SNAPSHOT_NOT_AVAILABLE_AT_CUTOFF")
        lanes = snapshot.get("lanes", {})
        c3, c4 = lanes.get("3"), lanes.get("4")
        if not isinstance(c3, dict) or not isinstance(c4, dict):
            reasons.append("PROSPECTIVE_SNAPSHOT_INCOMPLETE")
        exhibit, exhibit_payload, exhibit_reasons = self._exhibition(race_code, cutoff)
        reasons.extend(exhibit_reasons)
        if reasons:
            return self._response(race_code, cutoff, "LIVE", False, reasons, raw={"snapshot_static": snapshot.get("lanes", {})}, mapping=exhibit_payload.get("entry_mapping", {}), snapshot={"path": str(self.store.path(race_code)), "sha256": snapshot.get("snapshot_sha256")})
        if str(c3.get("player_id")) != str(exhibit[3].get("player_id")) or str(c4.get("player_id")) != str(exhibit[4].get("player_id")):
            return self._response(race_code, cutoff, "LIVE", False, ["SNAPSHOT_ENTRY_MAPPING_MISMATCH"], snapshot={"path": str(self.store.path(race_code)), "sha256": snapshot.get("snapshot_sha256")})
        vector = self._features(c3, c4, exhibit)
        missing = [name for name, item in zip(self.feature_order, vector) if item is None]
        if missing:
            return self._response(race_code, cutoff, "LIVE", False, ["MISSING_REQUIRED_FEATURE"], vector, raw={"missing_features": missing}, mapping=exhibit_payload.get("entry_mapping", {}), snapshot={"path": str(self.store.path(race_code)), "sha256": snapshot.get("snapshot_sha256")})
        return self._response(race_code, cutoff, "LIVE", True, [], vector, raw={"static_source": "prospective_first_write", "exhibition_source": "attack_type_post_exhibition_contract"}, mapping=exhibit_payload.get("entry_mapping", {}), snapshot={"path": str(self.store.path(race_code)), "sha256": snapshot.get("snapshot_sha256")})

    def build(self, race_code, prediction_cutoff, mode):
        race_code, mode = str(race_code).upper().strip(), str(mode).upper().strip()
        try:
            cutoff = parse_cutoff(prediction_cutoff)
        except ValueError:
            return {"feature_available": False, "missing_reason": ["PREDICTION_CUTOFF_TIMEZONE_REQUIRED"]}
        errors = self._errors()
        if errors:
            return self._response(race_code, cutoff, mode, False, errors)
        if mode == "RESEARCH_REPLAY":
            return self._research(race_code, cutoff)
        if mode == "LIVE":
            return self._live(race_code, cutoff)
        return self._response(race_code, cutoff, mode, False, ["INVALID_MODE"])
