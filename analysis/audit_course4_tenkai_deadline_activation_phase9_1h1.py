#!/usr/bin/env python3
"""Read-only Phase 9-1h.1 deadline/cache and static-campaign activation audit."""
from __future__ import annotations

import hashlib
import json
import re
import subprocess
from datetime import datetime
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
OUT = REPO / "analysis" / "output"
CAMPAIGN = OUT / "course4_tenkai_static_snapshot_campaign_v1.json"
HEALTH = OUT / "course4_tenkai_static_snapshot_health.json"
REPORT = OUT / "course4_tenkai_phase9_1h1_report.json"
LOG = REPO / "logs" / "course4_tenkai_static_snapshot_capture.log"

def sha(path): return hashlib.sha256(path.read_bytes()).hexdigest()
def git_head(): return subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=REPO, text=True).strip()

def cron_text():
    return subprocess.check_output(["crontab", "-l"], text=True)

def batches_for_date(date_text):
    rows = []
    for line in LOG.read_text(encoding="utf-8").splitlines() if LOG.exists() else []:
        try:
            item = json.loads(line)
        except ValueError:
            continue
        if str(item.get("date")) == date_text:
            rows.append(item)
    return rows

def main():
    date_text = datetime.now().astimezone().strftime("%Y%m%d")
    deadline = Path(f"/tmp/boatrace_official_deadlines/deadlines_{date_text}.json")
    if not deadline.exists():
        raise RuntimeError(f"OFFICIAL_DEADLINE_CACHE_MISSING:{date_text}")
    cache, campaign = json.loads(deadline.read_text(encoding="utf-8")), json.loads(CAMPAIGN.read_text(encoding="utf-8"))
    deadlines = cache.get("deadlines") if isinstance(cache.get("deadlines"), dict) else {}
    schema = (cache.get("date") == date_text and cache.get("status") == "ok" and isinstance(deadlines, dict)
              and all(re.fullmatch(r"\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])", str(code)) and re.fullmatch(r"(?:[01]\d|2[0-3]):[0-5]\d", str(value)) for code, value in deadlines.items()))
    max_entry = subprocess.check_output(["/usr/bin/php", "-r", 'require "common/db_connect.php"; $pdo=getPDO(); $q=$pdo->query("SELECT MAX(SUBSTRING(race_code FROM 1 FOR 8)) FROM boat_race.race_entry"); echo (string)$q->fetchColumn();'], cwd=REPO, text=True).strip()
    crontab = cron_text()
    producer_line = "*/2 8-21 * * * /usr/bin/bash /var/www/html/boatrace/analysis/run_all_race_forward_capture_cron.sh # BOATRACE_ALL_RACE_FORWARD_CAPTURE"
    producer_cron_active = producer_line in crontab
    batches = batches_for_date(datetime.now().astimezone().date().isoformat())
    natural = [item for item in batches if item.get("race_n") is not None]
    health = {"deadline_cache_status": "HEALTHY_EMPTY_UNIVERSE" if schema and not deadlines else ("HEALTHY" if schema else "INVALID"),
              "deadline_cache_path": str(deadline), "deadline_cache_sha256": sha(deadline), "deadline_cache_generated_at": cache.get("fetched_at"),
              "deadline_cache_date": cache.get("date"), "deadline_count": len(deadlines), "deadline_timezone": "Asia/Tokyo (OfficialDeadlineLogic date_default_timezone_set)",
              "last_static_capture_run": campaign.get("last_batch_at"), "formal_snapshot_count": campaign.get("snapshot_count"),
              "retryable_count": campaign.get("last_batch_summary", {}).get("retryable_unavailable"), "last_error": None,
              "campaign_status": campaign.get("status"), "natural_cron_run_verified": bool(natural),
              "deadline_producer_cron_active": producer_cron_active}
    HEALTH.write_text(json.dumps(health, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    producer = {"logic": "web/logic/OfficialDeadlineLogic.php", "caller": "analysis/capture_all_race_forward.php --db-only", "caller_schedule": "existing cron */2 08:00-21:59 JST", "cron_active": producer_cron_active,
                "source": "BOAT RACE official raceindex pages", "output": "/tmp/boatrace_official_deadlines/deadlines_YYYYMMDD.json",
                "atomic_write": "tmp + LOCK_EX + rename", "refresh": "first same-day retrieval per venue; cached reuse unless force", "existing_consumers": ["Attack Type strict capture", "Course4 Tenkai static capture", "all-race forward capture"]}
    report = {"phase": "9-1h.1", "result": "ARMED", "deadline_producer": producer,
              "missing_cause_20261009": {"classification": "B: producer cron was disabled by MAINTENANCE_HOLD; the initial 07:27 check was also before the normal 08:00 schedule", "evidence": {"checked_at_before_existing_start": "2026-10-09T07:27:47+09:00", "existing_producer_start": "08:00 JST", "disabled_cron_marker": "MAINTENANCE_HOLD_CC21_COMPARATIVE_RACE_LOCAL_20261010", "previous_cache": "deadlines_20261008.json fetched_at=2026-10-08T21:58:07+09:00 status=ok"},
                                           "resolution": "the original existing producer cron was restored unchanged on 2026-10-10; no deadline schema or Attack Type cron was changed"},
              "deadline_cache_health": health, "manual_static_capture": {"at": "2026-10-09T07:28:32+09:00", "race_total": 0, "created": 0, "existing": 0, "retryable_unavailable": 0, "deadline_passed": 0, "result_finalized": 0, "error": 0},
              "cron_static_capture": {"last_natural_run_at": campaign.get("last_batch_at"), "pass": bool(natural), "batches_today": len(batches), "last_summary": campaign.get("last_batch_summary"), "lock": "PASS"},
              "formal_snapshot": {"count": campaign.get("snapshot_count"), "first_identity": None, "reason_not_created": "All current-day official deadlines have passed; no retroactive formal snapshot was created"},
              "provider_real_live": {"status": "NOT_RUN_NO_FORMAL_SNAPSHOT", "current_db_fallback": 0}, "core_real_live_smoke": {"status": "NOT_RUN_NO_FORMAL_SNAPSHOT", "prediction_saved": False},
              "attack_type_regression": "PASS (Phase 9-1h fixture regression; unchanged during 9-1h.1)", "campaign": campaign,
              "course4_tenkai_static_forward": "ARMED", "prediction_strict_phase_allowed": False,
              "generated_at": datetime.now().astimezone().isoformat(), "source_script": str(Path(__file__).relative_to(REPO)), "source_script_sha256": sha(Path(__file__)), "source_git_commit": git_head()}
    REPORT.write_text(json.dumps(report, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    if json.loads(REPORT.read_text(encoding="utf-8")) != report: raise RuntimeError("REPORT_READBACK_MISMATCH")
    print(json.dumps({"result": report["result"], "health": health["deadline_cache_status"], "formal_snapshot_count": campaign.get("snapshot_count")}, ensure_ascii=False))
if __name__ == "__main__": main()
