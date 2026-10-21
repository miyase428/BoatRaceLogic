#!/usr/bin/env python3
"""Controlled Phase 9-1h capture tests: no production DB or snapshot writes."""
from __future__ import annotations

import copy
import fcntl
import hashlib
import json
import subprocess
import sys
import tempfile
from datetime import datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / "analysis" / "output"
sys.path.insert(0, str(ROOT / "analysis")); sys.path.insert(0, str(ROOT / "forecast"))
from capture_course4_tenkai_static_input_snapshot import Course4TenkaiStaticSnapshotCapture
from course4_tenkai_canonical_input import StaticSnapshotStore, sha256
from course34_wall_live_input_provider import WallRLiveInputProvider
from course4_attack_a5_live_input_provider import AttackA5LiveInputProvider
from course34_wall_live import WallRLiveInferenceCore
from course4_attack_a5_live import AttackA5LiveInferenceCore
from course4_attack_success_live import AttackSuccessLiveInferenceCore
from capture_course4_tenkai_static_input_daily import DailyStaticSnapshotBatch


class Guard:
    def __init__(self, deadline="2099-01-01T23:00:00+09:00", result=False): self.deadline_text, self.result = deadline, result
    def deadline(self, _): return datetime.fromisoformat(self.deadline_text) if self.deadline_text else None
    def result_exists(self, _): return self.result


def data(player4="4384", mutate=False):
    lanes = {}
    for lane in range(1, 7):
        lanes[str(lane)] = {"player_id": str(4000 + lane), "motor_number": str(10 + lane), "boat_number": str(20 + lane),
            "overall_average_st": .18, "course_entry_count": None, "course_average_st": None, "course_average_rank": None,
            "national_win_rate": 5.0, "local_win_rate": 4.0, "national_2ren_rate": 34.0, "local_2ren_rate": 30.0, "motor_2ren_rate": 35.0, "boat_2ren_rate": 34.0}
    lanes["3"].update({"player_id": "4370", "course_entry_count": 21.0, "course_average_st": .17, "course_average_rank": 3.1})
    lanes["4"].update({"player_id": player4, "course_entry_count": 17.0, "course_average_st": .21 if not mutate else .99, "course_average_rank": 4.5})
    return {"race_date": "2024-10-01", "term_info": "2404", "lanes": lanes}


class Loader:
    def __init__(self, ready=True): self.ready, self.mutate, self.calls = ready, False, 0
    def load(self, _):
        self.calls += 1
        if not self.ready: return None, "STATIC_SOURCE_NOT_READY"
        return data(mutate=self.mutate), None


class Exhibition:
    def build(self, *_):
        rows = []
        for course, player in ((3, "4370"), (4, "4384")):
            rows.append({"entry_course": course, "player_id": player, "course4_ex_st": .1 + .01 * course, "course4_exhibition_time": 6.8 + .01 * course,
                         "course4_lap_time": 37.0 + course, "course4_around_time": 5.8 + .1 * course, "course4_straight_time": 7.0 + .1 * course})
        return {"prediction_available": True, "raw_input": {"exhibition_rows": rows}, "entry_mapping": {"fixture": True}, "missing_reason": []}


def strip_time(v):
    v = copy.deepcopy(v); v.pop("generated_at", None); return v


def main():
    checks = {}; race = "20990101TST01"; cutoff = "2099-01-01T10:00:00+09:00"
    with tempfile.TemporaryDirectory() as directory:
        root = Path(directory) / "snapshots"; store = StaticSnapshotStore(root); loader = Loader(); capture = Course4TenkaiStaticSnapshotCapture(store=store, guard=Guard(), loader=loader, clock=lambda: "2099-01-01T09:59:00+09:00")
        first = capture.capture(race, cutoff); checks["valid_fixture_first_write"] = first["status"] == "SAVED"
        saved = store.read(race); checks["snapshot_hash_readback"] = saved["snapshot_sha256"] == first["snapshot_sha256"] and bool(saved["input_sha256"])
        checks["deadline_before_save"] = saved["captured_at"] < saved["official_deadline"]
        loader.mutate = True; again = capture.capture(race, cutoff); checks["second_no_op"] = again["status"] == "ALREADY_EXISTS" and store.read(race) == saved
        checks["source_mutation_isolation"] = store.read(race)["lanes"]["4"]["course_average_st"] == .21
        checks["deadline_after_blocks"] = Course4TenkaiStaticSnapshotCapture(store=StaticSnapshotStore(Path(directory)/"late"), guard=Guard(), loader=Loader()).capture("20990101TST02", "2099-01-02T10:00:00+09:00")["missing_reason"] == ["DEADLINE_PASSED"]
        checks["result_guard_blocks"] = Course4TenkaiStaticSnapshotCapture(store=StaticSnapshotStore(Path(directory)/"result"), guard=Guard(result=True), loader=Loader()).capture("20990101TST03", cutoff)["missing_reason"] == ["RESULT_ALREADY_EXISTS"]
        retry_loader = Loader(ready=False); retry = Course4TenkaiStaticSnapshotCapture(store=StaticSnapshotStore(Path(directory)/"retry"), guard=Guard(), loader=retry_loader)
        checks["static_incomplete_retryable"] = retry.capture("20990101TST04", cutoff)["status"] == "UNAVAILABLE"
        retry_loader.ready = True; checks["retry_to_saved"] = retry.capture("20990101TST04", cutoff)["status"] == "SAVED"
        # Formal snapshot supplies LIVE providers without any current DB fallback.
        wall_p = WallRLiveInputProvider(snapshot_store=store, exhibition_provider=Exhibition()); a5_p = AttackA5LiveInputProvider(snapshot_store=store, exhibition_provider=Exhibition())
        before = WallRLiveInputProvider(snapshot_store=StaticSnapshotStore(Path(directory)/"empty"), exhibition_provider=Exhibition()).build(race, cutoff, "LIVE")
        wall, a5 = wall_p.build(race, cutoff, "LIVE"), a5_p.build(race, cutoff, "LIVE")
        checks["wall_provider_missing_to_ready"] = not before["feature_available"] and before["missing_reason"] == ["PROSPECTIVE_SNAPSHOT_NOT_FOUND"] and wall["feature_available"]
        checks["a5_provider_ready"] = a5["feature_available"]
        wall_core, a5_core = WallRLiveInferenceCore(provider=wall_p), AttackA5LiveInferenceCore(provider=a5_p); success_core = AttackSuccessLiveInferenceCore(wall_core=wall_core, attack_core=a5_core)
        w, a, s = wall_core.predict(race, cutoff), a5_core.predict(race, cutoff), success_core.predict(race, cutoff)
        checks["live_core_smoke"] = w["prediction_available"] and a["prediction_available"] and s["prediction_available"]
        checks["deterministic_live"] = strip_time(success_core.predict(race, cutoff)) == strip_time(s) and w["input_sha256"] == wall_p.build(race, cutoff, "LIVE")["input_sha256"]
        # Batch uses a fixture deadline file and the same first-write store.
        deadline_dir = Path(directory) / "deadlines"; deadline_dir.mkdir(); (deadline_dir / "deadlines_20990101.json").write_text(json.dumps({"deadlines": {"20990101TST05": "23:00"}}), encoding="utf-8")
        batch_store = StaticSnapshotStore(Path(directory)/"batchsnap"); batch_cap = Course4TenkaiStaticSnapshotCapture(store=batch_store, guard=Guard(), loader=Loader(), clock=lambda: "2099-01-01T09:59:00+09:00")
        batch = DailyStaticSnapshotBatch(capture=batch_cap, guard=type("G", (), {"deadline_dir": deadline_dir})(), campaign_path=Path(directory)/"campaign.json", snapshot_root=Path(directory)/"batchsnap")
        one, two = batch.run("2099-01-01", cutoff), batch.run("2099-01-01", cutoff)
        checks["batch_double_run_safe"] = one["summary"]["created"] == 1 and two["summary"]["existing"] == 1 and two["campaign"]["status"] == "ACTIVE"
        lock = Path(directory) / "capture.lock"
        with lock.open("w") as handle:
            fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
            other = lock.open("w")
            try:
                try: fcntl.flock(other, fcntl.LOCK_EX | fcntl.LOCK_NB); checks["lock_overlap_safe"] = False
                except BlockingIOError: checks["lock_overlap_safe"] = True
            finally: other.close()
    source = (ROOT / "analysis" / "capture_course4_tenkai_static_input_snapshot.py").read_text(encoding="utf-8")
    loader_source = source[source.index("class StaticSourceLoader"):source.index("def source_ready")]
    checks["result_table_feature_access_zero"] = "race_result_detail" not in loader_source and "race_payouts" not in loader_source
    checks["future_static_loader_uses_race_code_date"] = "race_master" not in loader_source and "date.fromisoformat" in loader_source
    attack_provider_source = (ROOT / "analysis" / "course4_attack_type_live_input_provider.py").read_text(encoding="utf-8")
    attack_live_source = attack_provider_source[attack_provider_source.index("    def _live"):]
    checks["attack_type_future_loader_uses_race_code_date"] = "JOIN boat_race.race_master" not in attack_live_source and "race_date_from_code" in attack_live_source
    runner = (ROOT / "forecast" / "run_course4_tenkai_static_snapshot_jobs.sh").read_text(encoding="utf-8")
    installer = (ROOT / "forecast" / "install_course4_tenkai_static_snapshot_cron.sh").read_text(encoding="utf-8")
    checks["scheduler_configuration"] = "flock -n" in runner and "*/15 7-23" in installer and "BOATRACE_COURSE4_TENKAI_STATIC_SNAPSHOT" in installer
    attack_regression = subprocess.run([sys.executable, str(ROOT / "analysis" / "test_course4_attack_type_live_inference_phase9_1d.py")], cwd=ROOT, capture_output=True, text=True)
    checks["attack_type_regression"] = attack_regression.returncode == 0
    report = {"phase": "9-1h", "pass": all(checks.values()), "checks": checks,
              "generated_at": datetime.now().astimezone().isoformat(), "source_script": str(Path(__file__).relative_to(ROOT)),
              "source_script_sha256": hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
              "source_git_commit": subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT, text=True).strip()}
    path = OUT / "course4_tenkai_phase9_1h_fixture_tests.json"
    path.write_text(json.dumps(report, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    if not report["pass"]: raise SystemExit(json.dumps(checks, ensure_ascii=False, sort_keys=True))
    print(json.dumps({"pass": True, "checks": checks, "report": str(path.relative_to(ROOT))}, ensure_ascii=False, sort_keys=True))
if __name__ == "__main__": main()
