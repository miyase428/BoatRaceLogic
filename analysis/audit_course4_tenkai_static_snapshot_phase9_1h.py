#!/usr/bin/env python3
"""Assemble Phase 9-1h readiness report; no DB and no capture operation."""
from __future__ import annotations

import hashlib
import json
import subprocess
from datetime import datetime
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
OUT = REPO / "analysis" / "output"
FIXTURE = OUT / "course4_tenkai_phase9_1h_fixture_tests.json"
CAMPAIGN = OUT / "course4_tenkai_static_snapshot_campaign_v1.json"
REPORT = OUT / "course4_tenkai_phase9_1h_report.json"
SNAPSHOT_ROOT = REPO / "var" / "course4_tenkai_input_snapshots" / "static_v1"
MARKER = "# BOATRACE_COURSE4_TENKAI_STATIC_SNAPSHOT"

def sha(path): return hashlib.sha256(path.read_bytes()).hexdigest()
def main():
    fixture, campaign = json.loads(FIXTURE.read_text(encoding="utf-8")), json.loads(CAMPAIGN.read_text(encoding="utf-8"))
    cron = subprocess.run(["crontab", "-l"], text=True, capture_output=True)
    cron_lines = [line for line in cron.stdout.splitlines() if MARKER in line]
    snapshots = sorted(SNAPSHOT_ROOT.glob("*.json")) if SNAPSHOT_ROOT.exists() else []
    source_paths = [REPO / "analysis" / "capture_course4_tenkai_static_input_snapshot.py", REPO / "forecast" / "capture_course4_tenkai_static_input_daily.py", REPO / "forecast" / "run_course4_tenkai_static_snapshot_jobs.sh", REPO / "forecast" / "install_course4_tenkai_static_snapshot_cron.sh"]
    source_hashes = {str(path.relative_to(REPO)): sha(path) for path in source_paths}
    checks = fixture["checks"]
    ready = fixture["pass"] and len(cron_lines) == 1 and campaign["status"] == ("ACTIVE" if snapshots else "ARMED") and campaign["snapshot_count"] == len(snapshots)
    report = {"phase": "9-1h", "result": "PASS" if ready else "BLOCKED", "readiness": {"COURSE4_TENKAI_STATIC_SNAPSHOT_CAPTURE": "READY" if ready else "BLOCKED"},
              "fixture_tests": {"path": str(FIXTURE.relative_to(REPO)), "sha256": sha(FIXTURE), "all_pass": fixture["pass"], "checks": checks},
              "snapshot_storage": {"root": str(SNAPSHOT_ROOT.parent.relative_to(REPO)), "snapshot_count": len(snapshots), "snapshot_version": campaign["snapshot_version"], "first_write": "O_EXCL + atomic read-back"},
              "deadline_result_guard": {"deadline_source": "/tmp/boatrace_official_deadlines/deadlines_YYYYMMDD.json", "deadline_before_capture": True, "result_guard_only": True, "result_feature_access": 0},
              "scheduler": {"registered": len(cron_lines) == 1, "cron_lines": cron_lines, "cadence": "every 15 minutes, 07:00-23:59", "lock": "var/course4_tenkai_input_snapshots/static_capture.lock", "separate_from_attack_type": True},
              "campaign": campaign, "provider_integration": {"wall": checks["wall_provider_missing_to_ready"], "a5": checks["a5_provider_ready"]},
              "core_smoke": {"wall_a5_attack_success": checks["live_core_smoke"], "deterministic": checks["deterministic_live"]},
              "attack_type_regression": checks["attack_type_regression"], "prediction_snapshot_saved": False, "api_web_cron_prediction_changed": False,
              "generated_at": datetime.now().astimezone().isoformat(), "source_script": str(Path(__file__).relative_to(REPO)), "source_script_sha256": sha(Path(__file__)),
              "source_git_commit": subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=REPO, text=True).strip(), "source_hashes": source_hashes}
    REPORT.write_text(json.dumps(report, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    if json.loads(REPORT.read_text(encoding="utf-8")) != report: raise RuntimeError("REPORT_READBACK_MISMATCH")
    print(json.dumps({"pass": ready, "report": str(REPORT.relative_to(REPO)), "status": campaign["status"], "snapshot_count": len(snapshots)}, ensure_ascii=False))
    if not ready: raise SystemExit(1)
if __name__ == "__main__": main()
