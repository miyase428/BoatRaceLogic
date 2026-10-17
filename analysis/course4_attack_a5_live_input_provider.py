#!/usr/bin/env python3
"""Phase 9-1f A5 feature provider.  It never performs model inference."""
from __future__ import annotations

import bisect
import csv
import hashlib
import json
import sys
from calendar import monthrange
from datetime import date, datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parent
REPO = ROOT.parent
OUT = ROOT / "output"
sys.path.insert(0, str(ROOT))
from build_course4_attack_history_canonical_ledger_phase9_1b import build_ledger_index
from course4_tenkai_canonical_input import CANONICAL_VERSION, SNAPSHOT_VERSION, StaticSnapshotStore, canonical_json, parse_cutoff, sha256, value  # noqa: E402

CONTRACT_VERSION = "course4-attack-a5-live-input/v1"
ARTIFACT = OUT / "course4_attack_phase4r_final_model.json"
BASELINE = OUT / "course4_attack_a5_canonical_input_v1.csv"
MANIFEST = OUT / "course4_attack_a5_canonical_input_v1_manifest.json"
LEDGER = OUT / "course4_attack_history_canonical_ledger_v1.csv"
EXPECTED_ARTIFACT_SHA256 = "39808675efde92bfcbb73d69f88e915260f7dd0e0c48636d1b2775d6b2874176"


def twelve_months_before(target: date) -> date:
    return date(target.year - 1, target.month, min(target.day, monthrange(target.year - 1, target.month)[1]))


class AttackA5LiveInputProvider:
    def __init__(self, snapshot_store=None, exhibition_provider=None):
        self.artifact = json.loads(ARTIFACT.read_text(encoding="utf-8"))
        self.manifest = json.loads(MANIFEST.read_text(encoding="utf-8"))
        with BASELINE.open(encoding="utf-8") as handle:
            self.baseline = {row["race_code"]: row for row in csv.DictReader(handle)}
        with LEDGER.open(encoding="utf-8") as handle:
            self.ledger = list(csv.DictReader(handle))
        self.index = build_ledger_index(self.ledger)
        self.history_dates = {player_id: [date.fromisoformat(row["race_date"]) for row in rows] for player_id, rows in self.index.items()}
        self.store = snapshot_store or StaticSnapshotStore()
        self.exhibition_provider = exhibition_provider
        self.feature_order = tuple(self.artifact["feature_names"])
        self.identities = {"artifact": sha256(ARTIFACT), "baseline": sha256(BASELINE), "manifest": sha256(MANIFEST), "ledger": sha256(LEDGER)}

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
        payload = {"contract_version": CONTRACT_VERSION, "signal_id": "ATTACK_A5", "stage": "POST_EXHIBITION", "mode": mode,
                   "race_code": race_code, "prediction_cutoff": cutoff.isoformat(), "feature_order": list(self.feature_order),
                   "feature_vector": vector, "feature_available": available, "missing_reason": sorted(set(reasons)), "blocked_reason": sorted(set(reasons)),
                   "artifact_identity": {"path": str(ARTIFACT.relative_to(REPO)), "sha256": self.identities["artifact"]},
                   "canonical_identity": {"path": str(BASELINE.relative_to(REPO)), "sha256": self.identities["baseline"], "version": CANONICAL_VERSION},
                   "ledger_identity": {"path": str(LEDGER.relative_to(REPO)), "sha256": self.identities["ledger"]},
                   "raw_input": raw or {}, "entry_mapping": mapping or {}, "snapshot_identity": snapshot or {}}
        payload["input_sha256"] = hashlib.sha256(canonical_json(payload).encode()).hexdigest()
        return payload

    def _history(self, player_id, target):
        rows = self.index.get(str(player_id), [])
        dates = self.history_dates.get(str(player_id), [])
        subset = rows[bisect.bisect_left(dates, twelve_months_before(target)):bisect.bisect_left(dates, target)]
        n = len(subset)
        rate = lambda count: None if n == 0 else 100.0 * count / n
        makuri = sum(int(row["is_makuri"]) for row in subset)
        makurizashi = sum(int(row["is_makurizashi"]) for row in subset)
        return [float(n), rate(sum(int(row["final_rank"]) == 1 for row in subset)), rate(sum(int(row["final_rank"]) <= 2 for row in subset)), rate(sum(int(row["final_rank"]) <= 3 for row in subset)), rate(makuri), rate(makurizashi), rate(makuri + makurizashi)]

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
        target = date.fromisoformat(row["race_date"])
        history = self._history(row["course4_player_id"], target)
        # The frozen baseline deliberately retains the Phase 4R source names
        # (``course4_*``).  The prospective snapshot has lane-local names.
        # Normalize only at this provider boundary so both paths construct the
        # identical, artifact-ordered A5 vector without changing the baseline.
        record = {key.removeprefix("raw_"): value(val) for key, val in row.items() if key.startswith("raw_")}
        record.update({
            "course_average_st": record.get("course4_average_st"),
            "course_entry_count": record.get("course4_entry_count"),
            "course_average_rank": record.get("course4_average_st_rank"),
        })
        exhibit = {f: record.get(f) for f in ("course4_ex_st", "course4_exhibition_time", "course4_lap_time", "course4_around_time", "course4_straight_time")}
        vector = self._static_vector(record, exhibit, history)
        return self._response(race_code, cutoff, "RESEARCH_REPLAY", True, [], vector, raw={"source_kind": row["source_kind"], "baseline_frozen": row["baseline_frozen"]}, mapping={"course4_player_id": row["course4_player_id"]})

    def _exhibition(self, race_code, cutoff):
        if self.exhibition_provider is None:
            from course4_attack_type_live_input_provider import AttackTypeLiveInputProvider
            provider = AttackTypeLiveInputProvider()
        else:
            provider = self.exhibition_provider
        result = provider.build(race_code, cutoff.isoformat(), "LIVE")
        if not result.get("prediction_available"):
            return None, result, result.get("missing_reason") or ["EXHIBITION_CONTRACT_UNAVAILABLE"]
        record = next((item for item in result.get("raw_input", {}).get("exhibition_rows", []) if item.get("entry_course") == 4), None)
        return (record, result, []) if record is not None else (None, result, ["COURSE4_NOT_RESOLVABLE"])

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
        static = snapshot.get("lanes", {}).get("4")
        if not isinstance(static, dict):
            reasons.append("PROSPECTIVE_SNAPSHOT_INCOMPLETE")
        exhibit, exhibition_payload, exhibit_reasons = self._exhibition(race_code, cutoff)
        reasons.extend(exhibit_reasons)
        if reasons:
            return self._response(race_code, cutoff, "LIVE", False, reasons, raw={"snapshot_static": static}, mapping=exhibition_payload.get("entry_mapping", {}), snapshot={"path": str(self.store.path(race_code)), "sha256": snapshot.get("snapshot_sha256")})
        if str(static.get("player_id")) != str(exhibit.get("player_id")):
            return self._response(race_code, cutoff, "LIVE", False, ["SNAPSHOT_ENTRY_MAPPING_MISMATCH"], snapshot={"path": str(self.store.path(race_code)), "sha256": snapshot.get("snapshot_sha256")})
        history = self._history(static["player_id"], date.fromisoformat(snapshot["race_date"]))
        vector = self._static_vector(static, exhibit, history)
        missing = [name for name, item in zip(self.feature_order, vector) if item is None]
        if missing:
            return self._response(race_code, cutoff, "LIVE", False, ["MISSING_REQUIRED_FEATURE"], vector, raw={"missing_features": missing}, mapping=exhibition_payload.get("entry_mapping", {}), snapshot={"path": str(self.store.path(race_code)), "sha256": snapshot.get("snapshot_sha256")})
        return self._response(race_code, cutoff, "LIVE", True, [], vector, raw={"history_source": "canonical_history_ledger_v1", "static_source": "prospective_first_write", "exhibition_source": "attack_type_post_exhibition_contract"}, mapping=exhibition_payload.get("entry_mapping", {}), snapshot={"path": str(self.store.path(race_code)), "sha256": snapshot.get("snapshot_sha256")})

    def build(self, race_code, prediction_cutoff, mode):
        race_code, mode = str(race_code).upper().strip(), str(mode).upper().strip()
        try: cutoff = parse_cutoff(prediction_cutoff)
        except ValueError: return {"feature_available": False, "missing_reason": ["PREDICTION_CUTOFF_TIMEZONE_REQUIRED"]}
        errors = self._errors()
        if errors: return self._response(race_code, cutoff, mode, False, errors)
        if mode == "RESEARCH_REPLAY": return self._research(race_code, cutoff)
        if mode == "LIVE": return self._live(race_code, cutoff)
        return self._response(race_code, cutoff, mode, False, ["INVALID_MODE"])
