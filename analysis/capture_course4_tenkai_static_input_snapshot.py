#!/usr/bin/env python3
"""Formal pre-deadline static input capture for Wall-R / A5; no inference.

``race_result_detail`` is used only by ``StaticSnapshotGuard`` to reject a
completed race. It is never read by the static feature source loader.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import subprocess
import sys
from datetime import datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parent
REPO = ROOT.parent
sys.path.insert(0, str(ROOT))
from course4_tenkai_canonical_input import CANONICAL_VERSION, SNAPSHOT_VERSION, StaticSnapshotStore, canonical_json, parse_cutoff, sha256, term_for, value  # noqa: E402
from course34_wall_live_input_provider import CONTRACT_VERSION as WALL_CONTRACT_VERSION
from course4_attack_a5_live_input_provider import CONTRACT_VERSION as A5_CONTRACT_VERSION

CAPTURE_CONTRACT_VERSION = "course4-tenkai-static-capture/v1"
SOURCE_CONTRACT = "race_master+race_entry+racer_results(target-date term)+player_stats+engine_specs; result guard separate"
RETRYABLE = {"ENTRY_INCOMPLETE", "STATIC_SOURCE_NOT_READY", "PLAYER_PROFILE_NOT_READY", "ENGINE_INPUT_NOT_READY"}
PERMANENT = {"DEADLINE_PASSED", "RESULT_ALREADY_EXISTS", "OFFICIAL_DEADLINE_UNAVAILABLE", "SNAPSHOT_CONTRACT_MISMATCH", "PROSPECTIVE_SNAPSHOT_HASH_MISMATCH"}


def now_iso(): return datetime.now().astimezone().isoformat()
def git_head(): return subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=REPO, text=True).strip()


class StaticSnapshotGuard:
    """Timing and final-result guards. Result data never leaves this class."""
    def __init__(self, deadline_dir=None, connect=None):
        self.deadline_dir, self.connect = Path(deadline_dir or "/tmp/boatrace_official_deadlines"), connect
    def deadline(self, race_code):
        path = self.deadline_dir / f"deadlines_{race_code[:8]}.json"
        try: raw = json.loads(path.read_text(encoding="utf-8")).get("deadlines", {}).get(race_code)
        except (OSError, ValueError): return None
        if not isinstance(raw, str) or len(raw) != 5 or raw[2] != ":": return None
        try: return datetime.fromisoformat(f"{race_code[:4]}-{race_code[4:6]}-{race_code[6:8]}T{raw}:00+09:00")
        except ValueError: return None
    def result_exists(self, race_code):
        connect = self.connect
        if connect is None:
            from slit_validate_v2 import connect_db
            connect = connect_db
        with connect() as connection, connection.cursor() as cursor:
            # Guard only: no result values are selected or exposed.
            cursor.execute("SELECT EXISTS (SELECT 1 FROM boat_race.race_result_detail WHERE race_code=%s)", (race_code,))
            return bool(cursor.fetchone()[0])


class StaticSourceLoader:
    """Loads only pre-race static sources; no result/outcome tables."""
    def __init__(self, connect=None): self.connect = connect
    def load(self, race_code):
        connect = self.connect
        if connect is None:
            from slit_validate_v2 import connect_db
            connect = connect_db
        with connect() as connection, connection.cursor() as cursor:
            cursor.execute("SELECT race_date FROM boat_race.race_master WHERE race_code=%s", (race_code,))
            item = cursor.fetchone()
            if not item: return None, "ENTRY_INCOMPLETE"
            race_date, term = item[0], term_for(item[0])
            cursor.execute("""
                SELECT re.lane_number,re.player_id::text,re.motor_number,re.boat_number,
                       rr.average_start,rr.course3_entry,rr.course3_average_start,rr.course3_average_rank,
                       rr.course4_entry,rr.course4_average_start,rr.course4_average_rank,
                       ps.national_win_rate,ps.local_win_rate,ps.national_exacta_rate,ps.local_exacta_rate,
                       es.motor_exacta_rate,es.boat_exacta_rate
                FROM boat_race.race_entry re
                LEFT JOIN boat_race.racer_results rr ON rr.term_info::text=%s AND rr.player_id=re.player_id
                LEFT JOIN boat_race.player_stats ps ON ps.race_code=re.race_code AND ps.player_id=re.player_id
                LEFT JOIN boat_race.engine_specs es ON es.race_code=re.race_code AND es.motor_number=re.motor_number
                WHERE re.race_code=%s ORDER BY re.lane_number
            """, (term, race_code))
            rows = cursor.fetchall()
        if len(rows) != 6 or {int(row[0]) for row in rows if row[0] is not None} != set(range(1, 7)) or len({str(row[1]).strip() for row in rows if row[1] is not None}) != 6:
            return None, "ENTRY_INCOMPLETE"
        lanes = {}
        for row in rows:
            lane = int(row[0]); course_entry, course_st, course_rank = (row[5], row[6], row[7]) if lane == 3 else ((row[8], row[9], row[10]) if lane == 4 else (None, None, None))
            lanes[str(lane)] = {"player_id": str(row[1]).strip(), "motor_number": str(row[2]) if row[2] is not None else None,
                "boat_number": str(row[3]) if row[3] is not None else None, "overall_average_st": value(row[4]), "course_entry_count": value(course_entry),
                "course_average_st": value(course_st), "course_average_rank": value(course_rank), "national_win_rate": value(row[11]),
                "local_win_rate": value(row[12]), "national_2ren_rate": value(row[13]), "local_2ren_rate": value(row[14]),
                "motor_2ren_rate": value(row[15]), "boat_2ren_rate": value(row[16])}
        return {"race_date": race_date.isoformat(), "term_info": term, "lanes": lanes}, None


def source_ready(data):
    lanes = data["lanes"]
    for lane in ("3", "4"):
        static = lanes.get(lane, {})
        if any(static.get(key) is None for key in ("player_id", "overall_average_st", "course_entry_count", "course_average_st", "course_average_rank")):
            return "STATIC_SOURCE_NOT_READY"
    four = lanes["4"]
    if any(four.get(key) is None for key in ("national_win_rate", "local_win_rate", "national_2ren_rate", "local_2ren_rate")):
        return "PLAYER_PROFILE_NOT_READY"
    if any(four.get(key) is None for key in ("motor_number", "boat_number", "motor_2ren_rate", "boat_2ren_rate")):
        return "ENGINE_INPUT_NOT_READY"
    return None


class Course4TenkaiStaticSnapshotCapture:
    def __init__(self, store=None, guard=None, loader=None, clock=now_iso):
        self.store, self.guard, self.loader, self.clock = store or StaticSnapshotStore(), guard or StaticSnapshotGuard(), loader or StaticSourceLoader(), clock
    def capture(self, race_code, cutoff_text):
        race_code = str(race_code).upper().strip()
        try: cutoff = parse_cutoff(cutoff_text)
        except ValueError: return {"status": "BLOCKED", "race_code": race_code, "missing_reason": ["PREDICTION_CUTOFF_TIMEZONE_REQUIRED"]}
        try: existing = self.store.read(race_code)
        except ValueError as error: return {"status": "BLOCKED", "race_code": race_code, "missing_reason": [str(error)]}
        if existing is not None:
            if existing.get("snapshot_version") != SNAPSHOT_VERSION or existing.get("canonical_version") != CANONICAL_VERSION:
                return {"status": "BLOCKED", "race_code": race_code, "missing_reason": ["SNAPSHOT_CONTRACT_MISMATCH"]}
            return {"status": "ALREADY_EXISTS", "race_code": race_code, "snapshot_path": str(self.store.path(race_code)), "snapshot_sha256": existing["snapshot_sha256"], "missing_reason": []}
        deadline = self.guard.deadline(race_code)
        if deadline is None: return {"status": "BLOCKED", "race_code": race_code, "missing_reason": ["OFFICIAL_DEADLINE_UNAVAILABLE"]}
        if cutoff >= deadline: return {"status": "BLOCKED", "race_code": race_code, "official_deadline": deadline.isoformat(), "missing_reason": ["DEADLINE_PASSED"]}
        if self.guard.result_exists(race_code): return {"status": "BLOCKED", "race_code": race_code, "official_deadline": deadline.isoformat(), "missing_reason": ["RESULT_ALREADY_EXISTS"]}
        data, unavailable = self.loader.load(race_code)
        if unavailable: return {"status": "UNAVAILABLE", "race_code": race_code, "official_deadline": deadline.isoformat(), "missing_reason": [unavailable]}
        unavailable = source_ready(data)
        if unavailable: return {"status": "UNAVAILABLE", "race_code": race_code, "official_deadline": deadline.isoformat(), "missing_reason": [unavailable]}
        static_payload = {"race_code": race_code, "race_date": data["race_date"], "term_info": data["term_info"], "lanes": data["lanes"]}
        payload = {"snapshot_version": SNAPSHOT_VERSION, "canonical_version": CANONICAL_VERSION, "capture_contract_version": CAPTURE_CONTRACT_VERSION,
            "wall_input_contract_version": WALL_CONTRACT_VERSION, "a5_input_contract_version": A5_CONTRACT_VERSION, "race_code": race_code,
            "race_date": data["race_date"], "term_info": data["term_info"], "prediction_cutoff": cutoff.isoformat(), "official_deadline": deadline.isoformat(),
            "captured_at": self.clock(), "capture_kind": "FORMAL_PROSPECTIVE_STATIC_PRE_DEADLINE", "source_contract": SOURCE_CONTRACT,
            "source_identity": {"capture_source_script": str(Path(__file__).relative_to(REPO)), "capture_source_script_sha256": sha256(Path(__file__)), "source_git_commit": git_head()},
            "lanes": data["lanes"], "input_sha256": hashlib.sha256(canonical_json(static_payload).encode()).hexdigest()}
        write = self.store.first_write(payload)
        return {"status": "SAVED" if write["saved"] else "ALREADY_EXISTS", "race_code": race_code, "official_deadline": deadline.isoformat(), "snapshot_path": write["path"], "snapshot_sha256": write["snapshot_sha256"], "input_sha256": payload["input_sha256"], "missing_reason": []}


def capture(race_code, cutoff_text, store=None, guard=None, loader=None):
    return Course4TenkaiStaticSnapshotCapture(store=store, guard=guard, loader=loader).capture(race_code, cutoff_text)


def main():
    parser = argparse.ArgumentParser(); parser.add_argument("--race-code", required=True); parser.add_argument("--prediction-cutoff", required=True)
    args = parser.parse_args(); print(json.dumps(capture(args.race_code, args.prediction_cutoff), ensure_ascii=False, sort_keys=True))
if __name__ == "__main__": main()
