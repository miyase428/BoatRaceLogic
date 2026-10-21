#!/usr/bin/env python3
"""Immutable STRICT_FORWARD / LATE_REPLAY capture for 4C Attack Type.

The inference core remains outcome-blind.  This orchestration layer alone
performs deadline and result guards before it permits a STRICT write.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(REPO / "forecast"))
sys.path.insert(0, str(REPO / "analysis"))
from course4_attack_type_live import AttackTypeLiveInferenceCore, canonical_json, sha256  # noqa: E402

STORE_VERSION = "course4-attack-type-forward-snapshot/v1"
STAGE = "POST_EXHIBITION"
ROOT = REPO / "var" / "course4_attack_type_forward_snapshots"
CAMPAIGN = REPO / "analysis" / "output" / "course4_attack_type_true_forward_campaign_v1.json"


def now_iso(): return datetime.now().astimezone().isoformat()
def git_head(): return subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=REPO, text=True).strip()
def source_sha(): return sha256(Path(__file__))
def canonical_hash(value): return hashlib.sha256(canonical_json(value).encode("utf-8")).hexdigest()


def parse_cutoff(value):
    parsed = datetime.fromisoformat(str(value))
    if parsed.tzinfo is None: raise ValueError("PREDICTION_CUTOFF_TIMEZONE_REQUIRED")
    return parsed


class ForwardSnapshotStore:
    """Separate append-only file namespace; it never changes existing snapshots."""
    def __init__(self, root=ROOT): self.root = Path(root)
    def snapshot_path(self, race_code, logic_version, kind):
        return self.root / kind.lower() / f"{race_code}__{STAGE}__{logic_version}.json"
    def attempt_path(self, race_code):
        return self.root / "attempts" / f"{race_code}.json"
    def exists(self, race_code, logic_version, kind="STRICT_FORWARD"):
        return self.snapshot_path(race_code, logic_version, kind).is_file()
    def first_write(self, payload):
        path = self.snapshot_path(payload["race_code"], payload["logic_version"], payload["sample_kind"])
        path.parent.mkdir(parents=True, exist_ok=True)
        encoded = (json.dumps(payload, ensure_ascii=False, sort_keys=True, separators=(",", ":"), allow_nan=False) + "\n").encode()
        try:
            fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o640)
        except FileExistsError:
            return {"saved": False, "already_exists": True, "path": str(path)}
        with os.fdopen(fd, "wb") as handle:
            handle.write(encoded); handle.flush(); os.fsync(handle.fileno())
        return {"saved": True, "already_exists": False, "path": str(path)}
    def record_attempt(self, record):
        path = self.attempt_path(record["race_code"]); path.parent.mkdir(parents=True, exist_ok=True)
        prior = json.loads(path.read_text(encoding="utf-8")) if path.exists() else {}
        record["attempt_number"] = int(prior.get("attempt_number", 0)) + 1
        tmp = path.with_suffix(".tmp"); tmp.write_text(json.dumps(record, ensure_ascii=False, sort_keys=True, separators=(",", ":"), allow_nan=False), encoding="utf-8"); tmp.replace(path)


class Guard:
    def __init__(self, deadline_dir=None, connect=None):
        self.deadline_dir = Path(deadline_dir or "/tmp/boatrace_official_deadlines")
        self.connect = connect
    def deadline(self, race_code):
        path = self.deadline_dir / f"deadlines_{race_code[:8]}.json"
        try: data = json.loads(path.read_text(encoding="utf-8"))
        except (OSError, ValueError): return None
        raw = data.get("deadlines", {}).get(race_code)
        if not isinstance(raw, str) or len(raw) != 5 or raw[2] != ":": return None
        try: return datetime.fromisoformat(f"{race_code[:4]}-{race_code[4:6]}-{race_code[6:8]}T{raw}:00+09:00")
        except ValueError: return None
    def result_exists(self, race_code):
        if self.connect is None:
            from slit_validate_v2 import connect_db
            connect = connect_db
        else: connect = self.connect
        with connect() as connection, connection.cursor() as cursor:
            cursor.execute("SELECT EXISTS (SELECT 1 FROM boat_race.race_result_detail WHERE race_code=%s)", (race_code,))
            return bool(cursor.fetchone()[0])


class AttackTypeSnapshotCapture:
    def __init__(self, core=None, store=None, guard=None):
        self.core = core or AttackTypeLiveInferenceCore()
        self.store = store or ForwardSnapshotStore()
        self.guard = guard or Guard()
    def _attempt(self, race_code, cutoff, kind, status, reason, **extra):
        record = {"record_kind": "AVAILABILITY_ATTEMPT", "store_version": STORE_VERSION, "race_code": race_code,
                  "prediction_cutoff": str(cutoff), "sample_kind": kind, "stage": STAGE, "attempted_at": now_iso(),
                  "status": status, "prediction_available": False, "missing_reason": [reason], "blocked_reason": [reason], **extra}
        self.store.record_attempt(record); return record
    def capture(self, race_code, prediction_cutoff, sample_kind="STRICT_FORWARD"):
        race_code, kind = str(race_code).upper().strip(), str(sample_kind).upper().strip()
        if kind not in ("STRICT_FORWARD", "LATE_REPLAY"):
            raise ValueError("INVALID_SAMPLE_KIND")
        cutoff = parse_cutoff(prediction_cutoff)
        if not self.core.logic_version:
            return self._attempt(race_code, cutoff.isoformat(), kind, "BLOCKED", self.core.load_error or "INFERENCE_ARTIFACT_LOAD_ERROR")
        if kind == "STRICT_FORWARD":
            deadline = self.guard.deadline(race_code)
            if deadline is None: return self._attempt(race_code, cutoff.isoformat(), kind, "BLOCKED", "OFFICIAL_DEADLINE_UNAVAILABLE")
            if cutoff >= deadline: return self._attempt(race_code, cutoff.isoformat(), kind, "BLOCKED", "DEADLINE_PASSED", official_deadline=deadline.isoformat())
            if self.guard.result_exists(race_code): return self._attempt(race_code, cutoff.isoformat(), kind, "BLOCKED", "RESULT_ALREADY_EXISTS", official_deadline=deadline.isoformat())
            if self.store.exists(race_code, self.core.logic_version):
                return self._attempt(race_code, cutoff.isoformat(), kind, "ALREADY_EXISTS", "STRICT_SNAPSHOT_ALREADY_EXISTS", official_deadline=deadline.isoformat())
        else: deadline = self.guard.deadline(race_code)
        prediction = self.core.predict(race_code, cutoff.isoformat())
        if not prediction.get("prediction_available"):
            return self._attempt(race_code, cutoff.isoformat(), kind, "UNAVAILABLE", (prediction.get("missing_reason") or ["INFERENCE_UNAVAILABLE"])[0], inference=prediction)
        required = ("logic_version", "artifact_sha256", "spec_sha256", "input_contract_version", "input_sha256", "ledger_sha256", "input_feature_vector")
        if any(prediction.get(key) in (None, "", []) for key in required):
            return self._attempt(race_code, cutoff.isoformat(), kind, "BLOCKED", "SNAPSHOT_IDENTITY_INCOMPLETE", inference=prediction)
        vector = prediction["input_feature_vector"]
        if len(vector) != 8: return self._attempt(race_code, cutoff.isoformat(), kind, "BLOCKED", "SNAPSHOT_FEATURE_VECTOR_INVALID", inference=prediction)
        identity = {key: prediction[key] for key in ("race_code", "prediction_cutoff", "stage", "logic_version", "artifact_sha256", "spec_sha256", "ledger_sha256", "ledger_manifest_sha256", "input_contract_version", "input_contract_sha256", "input_sha256", "p_no_attack_success", "p_makuri_success", "p_makurizashi_success")}
        identity["feature_order"] = list(self.core.artifact["feature_order"]); identity["input_feature_vector"] = vector
        payload = {"store_version": STORE_VERSION, "capture_version": "phase9-2-v1", "sample_kind": kind, "race_code": race_code,
                   "prediction_generated_at": prediction["generated_at"], "capture_generated_at": now_iso(), "prediction_cutoff": prediction["prediction_cutoff"],
                   "official_deadline": deadline.isoformat() if deadline else None, "stage": STAGE, "prediction_available": True,
                   "missing_reason": [], "blocked_reason": [], "p_no_attack_success": prediction["p_no_attack_success"], "p_makuri_success": prediction["p_makuri_success"], "p_makurizashi_success": prediction["p_makurizashi_success"],
                   "logic_version": prediction["logic_version"], "artifact_sha256": prediction["artifact_sha256"], "spec_sha256": prediction["spec_sha256"], "ledger_sha256": prediction["ledger_sha256"], "ledger_manifest_sha256": prediction["ledger_manifest_sha256"], "input_contract_version": prediction["input_contract_version"], "input_contract_sha256": prediction["input_contract_sha256"], "input_sha256": prediction["input_sha256"],
                   "feature_order": identity["feature_order"], "input_feature_vector": vector, "missing_feature_mask": [False] * 8,
                   "canonical_prediction_payload_hash": canonical_hash(identity), "source_script": str(Path(__file__).relative_to(REPO)), "source_script_sha256": source_sha(), "source_git_commit": git_head()}
        hash_input = {key: value for key, value in payload.items() if key not in ("prediction_generated_at", "capture_generated_at", "snapshot_hash")}
        payload["snapshot_hash"] = canonical_hash(hash_input)
        write = self.store.first_write(payload)
        if write["already_exists"]: return self._attempt(race_code, cutoff.isoformat(), kind, "ALREADY_EXISTS", "STRICT_SNAPSHOT_ALREADY_EXISTS")
        saved = json.loads(Path(write["path"]).read_text(encoding="utf-8"))
        if saved != payload or saved.get("snapshot_hash") != canonical_hash({key: value for key, value in saved.items() if key not in ("prediction_generated_at", "capture_generated_at", "snapshot_hash")}):
            raise RuntimeError("SNAPSHOT_READBACK_MISMATCH")
        if kind == "STRICT_FORWARD" and not CAMPAIGN.exists():
            campaign={"campaign_id":"course4-attack-type-true-forward/v1","signal_id":"ATTACK_TYPE","status":"ACTIVE","started_at":payload["capture_generated_at"],"first_race_code":race_code,"logic_version":payload["logic_version"],"artifact_sha256":payload["artifact_sha256"],"spec_sha256":payload["spec_sha256"],"input_contract_version":payload["input_contract_version"],"initial_ledger_sha256":payload["ledger_sha256"],"strict_snapshot_root":str(ROOT.relative_to(REPO)),"performance_locked":True}
            tmp=CAMPAIGN.with_suffix('.tmp'); tmp.write_text(json.dumps(campaign,ensure_ascii=False,indent=2),encoding='utf-8'); tmp.replace(CAMPAIGN)
        return {"status": "SAVED", "prediction_available": True, "sample_kind": kind, "path": write["path"], "snapshot_hash": payload["snapshot_hash"], "canonical_prediction_payload_hash": payload["canonical_prediction_payload_hash"]}

    def capture_date(self, date_text, prediction_cutoff=None, sample_kind="STRICT_FORWARD"):
        """Manual batch readiness path.  No scheduler or cron registration."""
        date_prefix = str(date_text).replace("-", "")
        if len(date_prefix) != 8 or not date_prefix.isdigit():
            raise ValueError("INVALID_DATE")
        cache = self.guard.deadline_dir / f"deadlines_{date_prefix}.json"
        try: data = json.loads(cache.read_text(encoding="utf-8"))
        except (OSError, ValueError): return {"date": str(date_text), "status": "OFFICIAL_DEADLINE_UNAVAILABLE", "results": []}
        race_codes = sorted(str(code).upper() for code in data.get("deadlines", {}) if str(code).startswith(date_prefix))
        cutoff = prediction_cutoff or now_iso()
        results = []
        for race_code in race_codes:
            try: results.append(self.capture(race_code, cutoff, sample_kind))
            except Exception as error: results.append({"race_code": race_code, "status": "ERROR", "error": type(error).__name__})
        summary = {status: sum(result.get("status") == status for result in results) for status in ("SAVED", "ALREADY_EXISTS", "UNAVAILABLE", "BLOCKED", "ERROR")}
        return {"date": str(date_text), "sample_kind": sample_kind, "prediction_cutoff": str(cutoff), "race_n": len(race_codes), "summary": summary, "results": results}


def main():
    parser = argparse.ArgumentParser()
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--race-code"); group.add_argument("--date")
    parser.add_argument("--prediction-cutoff")
    parser.add_argument("--sample-kind", default="STRICT_FORWARD", choices=("STRICT_FORWARD", "LATE_REPLAY")); parser.add_argument("--json", action="store_true")
    args = parser.parse_args(); capture = AttackTypeSnapshotCapture()
    if args.race_code:
        if not args.prediction_cutoff: parser.error("--prediction-cutoff is required with --race-code")
        result = capture.capture(args.race_code, args.prediction_cutoff, args.sample_kind)
    else:
        result = capture.capture_date(args.date, args.prediction_cutoff, args.sample_kind)
    print(json.dumps(result, ensure_ascii=False, sort_keys=True))

if __name__ == "__main__": main()
