#!/usr/bin/env python3
"""Read-only post-deadline reference analysis.

This is deliberately separate from LIVE/STRICT capture.  It never writes a
snapshot or ledger row, never reads a result table, and fixes history/static
features at the official deadline while allowing a later, audited exhibition
observation to be used as reference input.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import sys
from datetime import date, datetime
from pathlib import Path
from zoneinfo import ZoneInfo

REPO = Path(__file__).resolve().parent.parent
ANALYSIS = REPO / "analysis"
sys.path.insert(0, str(ANALYSIS))
sys.path.insert(0, str(REPO / "forecast"))

from course4_tenkai_canonical_input import StaticSnapshotStore, canonical_json  # noqa: E402
from course34_wall_live import WallRLiveInferenceCore  # noqa: E402
from course34_wall_live_input_provider import WallRLiveInputProvider  # noqa: E402
from course4_attack_type_live import AttackTypeLiveInferenceCore  # noqa: E402
from course4_attack_type_live_input_provider import AttackTypeLiveInputProvider  # noqa: E402
from course34_wall_no_straight_live import WallRNoStraightLiveInferenceCore  # noqa: E402
from course34_wall_no_straight_live_input_provider import WallRNoStraightLiveInputProvider  # noqa: E402
from course4_attack_type_no_straight_live import AttackTypeNoStraightLiveInferenceCore  # noqa: E402
from course4_attack_type_no_straight_live_input_provider import AttackTypeNoStraightLiveInputProvider  # noqa: E402

API_VERSION = "tenkai-ai-postrace-reference/v1"
OFFICIAL_SOURCES = {"official_amagasaki", "official_tamagawa", "official_suminoe", "official_omura"}


def now_iso() -> str:
    return datetime.now().astimezone().isoformat()


def digest(value) -> str:
    return hashlib.sha256(canonical_json(value).encode("utf-8")).hexdigest()


class VectorProvider:
    def __init__(self, payload):
        self.payload = payload

    def build(self, *_args):
        return dict(self.payload)


class ReferenceDataLoader:
    """Loads entries/exhibition plus its successful official audit; no outcomes."""
    def __init__(self, connect=None):
        self.connect = connect

    def load(self, race_code: str, analysis_at: datetime):
        if self.connect is None:
            from slit_validate_v2 import connect_db
            connect = connect_db
        else:
            connect = self.connect
        local_at = analysis_at.astimezone(ZoneInfo("Asia/Tokyo")).replace(tzinfo=None)
        with connect() as connection, connection.cursor() as cursor:
            cursor.execute(
                """
                SELECT source,result_status,fetched_at,db_saved_at,after_rows
                FROM boat_race.exhibition_source_audit_v1
                WHERE race_code=%s AND result_status='saved'
                ORDER BY db_saved_at DESC
                """,
                (race_code,),
            )
            audits = cursor.fetchall()
            cursor.execute(
                """
                SELECT re.lane_number,re.player_id::text,e.entry_course,e.start_timing,
                       e.exhibition_time,e.lap_time,e.around_time,e.straight_time,e.created_date
                FROM boat_race.race_entry re
                LEFT JOIN boat_race.exhibition_live e
                  ON e.race_code=re.race_code AND e.player_id=re.player_id
                 AND e.created_date=(
                    SELECT max(e2.created_date) FROM boat_race.exhibition_live e2
                    WHERE e2.race_code=e.race_code AND e2.player_id=e.player_id
                      AND e2.created_date<=%s
                 )
                WHERE re.race_code=%s ORDER BY re.lane_number
                """,
                (local_at, race_code),
            )
            rows = cursor.fetchall()
        expected_source = {"AMG": "official_amagasaki", "TMG": "official_tamagawa", "SME": "official_suminoe", "OMR": "official_omura"}.get(race_code[8:11])
        if expected_source is None:
            return None, "OFFICIAL_EXHIBITION_AUDIT_SOURCE_MISMATCH"
        official = None
        for candidate in audits:
            if str(candidate[0]) != expected_source:
                continue
            observed = candidate[2]
            saved = candidate[3]
            if observed is None or saved is None:
                continue
            if observed.tzinfo is None:
                observed = observed.replace(tzinfo=ZoneInfo("Asia/Tokyo"))
            if saved.tzinfo is None:
                saved = saved.replace(tzinfo=ZoneInfo("Asia/Tokyo"))
            if observed <= analysis_at and saved <= analysis_at:
                official = candidate
                break
        if official is None:
            return None, "OFFICIAL_EXHIBITION_AUDIT_NOT_FOUND"
        if len(rows) != 6:
            return None, "ENTRY_MAPPING_INCOMPLETE"
        records = []
        for row in rows:
            records.append({
                "lane": int(row[0]), "player_id": str(row[1]).strip(), "entry_course": row[2],
                "course4_ex_st": float(row[3]) if row[3] is not None else None,
                "course4_exhibition_time": float(row[4]) if row[4] is not None else None,
                "course4_lap_time": float(row[5]) if row[5] is not None else None,
                "course4_around_time": float(row[6]) if row[6] is not None else None,
                "course4_straight_time": float(row[7]) if row[7] is not None else None,
                "created_date": row[8].isoformat() if row[8] is not None else None,
            })
        courses = [record["entry_course"] for record in records]
        if len({record["player_id"] for record in records}) != 6 or set(courses) != set(range(1, 7)):
            return None, "EXHIBITION_NOT_COMPLETE"
        if any(record["created_date"] is None for record in records):
            return None, "EXHIBITION_NOT_COMPLETE"
        if any(record["entry_course"] != record["lane"] for record in records):
            return None, "NON_WAKUNARI"
        audit_rows = official[4]
        if isinstance(audit_rows, str):
            try: audit_rows = json.loads(audit_rows)
            except ValueError: audit_rows = None
        if not isinstance(audit_rows, list) or len(audit_rows) != 6:
            return None, "OFFICIAL_EXHIBITION_AUDIT_DATA_MISMATCH"
        audit_by_player = {}
        for row in audit_rows:
            if not isinstance(row, dict):
                return None, "OFFICIAL_EXHIBITION_AUDIT_DATA_MISMATCH"
            player = str(row.get("player_id", "")).strip()
            if not player or player in audit_by_player:
                return None, "OFFICIAL_EXHIBITION_AUDIT_DATA_MISMATCH"
            audit_by_player[player] = row
        for record in records:
            audit_row = audit_by_player.get(record["player_id"])
            if not isinstance(audit_row, dict) or int(audit_row.get("lane", 0)) != record["lane"] or int(audit_row.get("entry_course", 0)) != record["entry_course"]:
                return None, "OFFICIAL_EXHIBITION_AUDIT_DATA_MISMATCH"
            for field, live_field in (("exhibition_time", "course4_exhibition_time"), ("start_timing", "course4_ex_st"), ("lap_time", "course4_lap_time"), ("around_time", "course4_around_time")):
                value = audit_row.get(field)
                if not isinstance(value, (int, float)) or abs(float(value) - float(record[live_field])) > 0.00001:
                    return None, "OFFICIAL_EXHIBITION_AUDIT_DATA_MISMATCH"
            audit_straight = audit_row.get("straight_time")
            live_straight = record["course4_straight_time"]
            if (audit_straight is None) != (live_straight is None):
                return None, "OFFICIAL_EXHIBITION_AUDIT_DATA_MISMATCH"
            if audit_straight is not None and (not isinstance(audit_straight, (int, float)) or abs(float(audit_straight) - float(live_straight)) > 0.00001):
                return None, "OFFICIAL_EXHIBITION_AUDIT_DATA_MISMATCH"
        return {
            "records": records,
            "audit": {"source": str(official[0]), "fetched_at": official[2].isoformat(), "db_saved_at": official[3].isoformat()},
        }, None


class PostRaceReference:
    def __init__(self, loader=None, snapshot_store=None, clock=None, variant_factory=None):
        self.loader = loader or ReferenceDataLoader()
        self.snapshot_store = snapshot_store or StaticSnapshotStore()
        self.clock = clock or (lambda: datetime.now().astimezone())
        self.variant_factory = variant_factory

    @staticmethod
    def unavailable(reason):
        return {"prediction_available": False, "reason_code": reason}

    def analyze(self, race_code: str, official_deadline: str, model_variant: str):
        try:
            deadline = datetime.fromisoformat(official_deadline)
            analysis_at = self.clock()
            race_date = date.fromisoformat(f"{race_code[:4]}-{race_code[4:6]}-{race_code[6:8]}")
        except (TypeError, ValueError):
            return {"success": True, "api_version": API_VERSION, "analysis_kind": "POST_RACE_REFERENCE", "race_code": race_code,
                    "components": {"wall": self.unavailable("OFFICIAL_DEADLINE_UNAVAILABLE"), "attack": self.unavailable("OFFICIAL_DEADLINE_UNAVAILABLE")}}
        if deadline.tzinfo is None or analysis_at <= deadline:
            reason = "REFERENCE_ONLY_AFTER_DEADLINE"
            return {"success": True, "api_version": API_VERSION, "analysis_kind": "POST_RACE_REFERENCE", "race_code": race_code,
                    "components": {"wall": self.unavailable(reason), "attack": self.unavailable(reason)}}
        source, unavailable = self.loader.load(race_code, analysis_at)
        if unavailable:
            return {"success": True, "api_version": API_VERSION, "analysis_kind": "POST_RACE_REFERENCE", "race_code": race_code,
                    "components": {"wall": self.unavailable(unavailable), "attack": self.unavailable(unavailable)}}

        no_straight = model_variant == "NO_STRAIGHT"
        if self.variant_factory is not None:
            attack_provider, wall_provider, attack_core_factory, wall_core_factory = self.variant_factory(model_variant, self.snapshot_store)
        else:
            attack_provider = AttackTypeNoStraightLiveInputProvider() if no_straight else AttackTypeLiveInputProvider()
            wall_provider = WallRNoStraightLiveInputProvider(snapshot_store=self.snapshot_store) if no_straight else WallRLiveInputProvider(snapshot_store=self.snapshot_store)
            attack_core_factory = (lambda provider: AttackTypeNoStraightLiveInferenceCore(provider=provider)) if no_straight else (lambda provider: AttackTypeLiveInferenceCore(provider=provider))
            wall_core_factory = (lambda provider: WallRNoStraightLiveInferenceCore(provider=provider)) if no_straight else (lambda provider: WallRLiveInferenceCore(provider=provider))
        course4 = next(record for record in source["records"] if record["entry_course"] == 4)
        history, history_audit = attack_provider._history(course4["player_id"], race_date, deadline, "LIVE")
        attack_reason = None
        attack_result = None
        if history is None:
            attack_reason = str(history_audit.get("reason", "MISSING_REQUIRED_FEATURE"))
        else:
            attack_features = [float(history["course4_history_n"]), history["course4_history_makuri_rate_pct"], history["course4_history_makurizashi_rate_pct"]]
            attack_features += [course4[name] for name in attack_provider.feature_order[3:]]
            if any(value is None for value in attack_features):
                attack_reason = "EXHIBITION_NOT_COMPLETE"
            else:
                payload = {"prediction_available": True, "feature_order": list(attack_provider.feature_order),
                           "feature_vector": attack_features, "input_sha256": digest({"features": attack_features, "history_cutoff": deadline.isoformat()})}
                core = attack_core_factory(VectorProvider(payload))
                attack_result = core.predict(race_code, analysis_at.isoformat())

        wall_reason = None
        wall_result = None
        try:
            snapshot = self.snapshot_store.read(race_code)
        except (OSError, ValueError):
            snapshot = None
            wall_reason = "PROSPECTIVE_SNAPSHOT_INVALID"
        if snapshot is None and wall_reason is None:
            wall_reason = "PROSPECTIVE_SNAPSHOT_NOT_FOUND"
        if snapshot is not None:
            try:
                captured_at = datetime.fromisoformat(str(snapshot["captured_at"]))
                snap_deadline = datetime.fromisoformat(str(snapshot["official_deadline"]))
            except (KeyError, TypeError, ValueError):
                wall_reason = "PROSPECTIVE_SNAPSHOT_INVALID"
            else:
                if captured_at >= deadline or snap_deadline != deadline or snapshot.get("capture_kind") != "FORMAL_PROSPECTIVE_STATIC_PRE_DEADLINE":
                    wall_reason = "PROSPECTIVE_SNAPSHOT_NOT_PREDEADLINE"
                else:
                    lanes = snapshot.get("lanes", {})
                    exhibit = {record["entry_course"]: record for record in source["records"] if record["entry_course"] in (3, 4)}
                    c3, c4 = lanes.get("3"), lanes.get("4")
                    if not isinstance(c3, dict) or not isinstance(c4, dict) or set(exhibit) != {3, 4}:
                        wall_reason = "PROSPECTIVE_SNAPSHOT_INCOMPLETE"
                    elif str(c3.get("player_id")) != exhibit[3]["player_id"] or str(c4.get("player_id")) != exhibit[4]["player_id"]:
                        wall_reason = "SNAPSHOT_ENTRY_MAPPING_MISMATCH"
                    else:
                        wall_features = wall_provider._features(c3, c4, exhibit)
                        if any(value is None for value in wall_features):
                            wall_reason = "MISSING_REQUIRED_FEATURE"
                        else:
                            payload = {"feature_available": True, "feature_order": list(wall_provider.feature_order),
                                       "feature_vector": wall_features, "artifact_identity": {"sha256": wall_provider.identities["artifact"]},
                                       "input_sha256": digest({"features": wall_features, "snapshot": snapshot.get("snapshot_sha256")})}
                            core = wall_core_factory(VectorProvider(payload))
                            wall_result = core.predict(race_code, analysis_at.isoformat())

        wall = self.unavailable(wall_reason or "MISSING_REQUIRED_FEATURE")
        if wall_result and wall_result.get("prediction_available"):
            wall = {"prediction_available": True, "wall_probability": wall_result["wall_probability"],
                    "logic_version": wall_result.get("logic_version"), "artifact_sha256": wall_result.get("artifact_sha256")}
        attack = self.unavailable(attack_reason or "MISSING_REQUIRED_FEATURE")
        if attack_result and attack_result.get("prediction_available"):
            attack = {"prediction_available": True,
                      "probabilities": {"no_attack_success": attack_result["p_no_attack_success"], "makuri_success": attack_result["p_makuri_success"], "makurizashi_success": attack_result["p_makurizashi_success"]},
                      "logic_version": attack_result.get("logic_version"), "artifact_sha256": attack_result.get("artifact_sha256")}
        return {"success": True, "api_version": API_VERSION, "analysis_kind": "POST_RACE_REFERENCE", "strict_forward_eligible": False,
                "persisted": False, "model_context": "CURRENT_MODEL_AT_CALCULATION", "race_code": race_code, "official_deadline": deadline.isoformat(), "calculated_at": analysis_at.isoformat(),
                "input_provenance": {"static_cutoff": deadline.isoformat(), "history_cutoff": deadline.isoformat(), "exhibition_observed_at": source["audit"]["fetched_at"], "exhibition_source": source["audit"]["source"], "result_tables_read": False},
                "components": {"wall": wall, "attack": attack}}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--race-code", required=True)
    parser.add_argument("--official-deadline", required=True)
    parser.add_argument("--model-variant", required=True, choices=("FULL", "NO_STRAIGHT"))
    args = parser.parse_args()
    print(json.dumps(PostRaceReference().analyze(args.race_code.upper(), args.official_deadline, args.model_variant), ensure_ascii=False, sort_keys=True))


if __name__ == "__main__":
    main()
