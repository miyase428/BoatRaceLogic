#!/usr/bin/env python3
"""One-shot, read-only morning health audit for Course4 Tenkai static inputs."""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import subprocess
from collections import Counter
from datetime import datetime
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
OUT = REPO / "analysis" / "output"
CAMPAIGN = OUT / "course4_tenkai_static_snapshot_campaign_v1.json"
LOG = REPO / "logs" / "course4_tenkai_static_snapshot_capture.log"
SNAPSHOT_ROOT = REPO / "var" / "course4_tenkai_input_snapshots" / "static_v1"


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def canonical_json(value: object) -> str:
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"), allow_nan=False)


def db_summary(prefix: str) -> dict:
    # PHP uses the repository's existing read-only DB connection helper.  All
    # statements below are SELECTs; this audit never mutates database state.
    code = r'''require "common/db_connect.php";
$pdo=getPDO(); $prefix=$argv[1];
$base="WITH entry_counts AS (SELECT race_code, count(*) AS row_count,
 count(DISTINCT lane_number) AS lane_n,
 count(DISTINCT NULLIF(trim(player_id::text), '')) AS player_n,
 min(lane_number) AS min_lane, max(lane_number) AS max_lane
 FROM boat_race.race_entry WHERE race_code LIKE :p GROUP BY race_code),
 complete AS (SELECT race_code FROM entry_counts WHERE row_count=6 AND lane_n=6 AND player_n=6 AND min_lane=1 AND max_lane=6)
 ";
$q=$pdo->prepare($base."SELECT (SELECT count(DISTINCT race_code) FROM boat_race.race_master WHERE race_code LIKE :p) AS race_master_race_count,
 (SELECT count(*) FROM entry_counts) AS race_entry_distinct_race_count,
 (SELECT count(*) FROM complete) AS race_entry_complete_race_count,
 (SELECT count(*) FROM complete c LEFT JOIN boat_race.race_master rm ON rm.race_code=c.race_code WHERE rm.race_code IS NULL) AS entry_complete_but_race_master_missing_count");
$q->execute([":p"=>$prefix."%"]); $summary=$q->fetch(PDO::FETCH_ASSOC);
$q=$pdo->prepare($base."SELECT c.race_code FROM complete c LEFT JOIN boat_race.race_master rm ON rm.race_code=c.race_code WHERE rm.race_code IS NULL ORDER BY c.race_code");
$q->execute([":p"=>$prefix."%"]); $summary["entry_complete_but_race_master_missing_race_codes"]=$q->fetchAll(PDO::FETCH_COLUMN);
echo json_encode($summary, JSON_UNESCAPED_UNICODE);'''
    completed = subprocess.run(["/usr/bin/php", "-r", code, prefix], cwd=REPO, text=True, capture_output=True, check=True)
    return json.loads(completed.stdout)


def cache_health(prefix: str) -> dict:
    path = Path("/tmp/boatrace_official_deadlines") / f"deadlines_{prefix}.json"
    if not path.exists():
        return {"exists": False, "schema_status": "MISSING", "path": str(path)}
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
        deadlines = data.get("deadlines") if isinstance(data.get("deadlines"), dict) else {}
        code_ok = all(re.fullmatch(r"\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])", str(key)) for key in deadlines)
        time_ok = all(re.fullmatch(r"(?:[01]\d|2[0-3]):[0-5]\d", str(value)) for value in deadlines.values())
        passed = data.get("status") == "ok" and data.get("date") == prefix and code_ok and time_ok
        return {"exists": True, "path": str(path), "sha256": sha256(path), "fetched_at": data.get("fetched_at"),
                "race_count": len(deadlines), "errors": data.get("errors", []), "schema_status": "PASS" if passed else "FAIL"}
    except (OSError, ValueError) as error:
        return {"exists": True, "path": str(path), "schema_status": "FAIL", "parse_error": type(error).__name__}


def batch_rows(date_text: str) -> list[dict]:
    rows = []
    if not LOG.exists():
        return rows
    for line in LOG.read_text(encoding="utf-8", errors="replace").splitlines():
        try:
            item = json.loads(line)
        except ValueError:
            continue
        if item.get("date") == date_text and item.get("race_n") is not None:
            rows.append(item)
    return rows


def deadline_producer_cron_status() -> str:
    line = "*/2 8-21 * * * /usr/bin/bash /var/www/html/boatrace/analysis/run_all_race_forward_capture_cron.sh # BOATRACE_ALL_RACE_FORWARD_CAPTURE"
    try:
        crontab = subprocess.check_output(["crontab", "-l"], text=True)
    except subprocess.CalledProcessError:
        return "UNVERIFIED"
    return "ACTIVE" if line in crontab else "INACTIVE"


def first_snapshot() -> dict | None:
    candidates = []
    for path in SNAPSHOT_ROOT.glob("*.json") if SNAPSHOT_ROOT.exists() else []:
        try:
            item = json.loads(path.read_text(encoding="utf-8"))
            candidates.append((item.get("captured_at"), path, item))
        except (OSError, ValueError):
            continue
    if not candidates:
        return None
    _, path, item = sorted(candidates, key=lambda row: (str(row[0]), str(row[1])))[0]
    body = {key: value for key, value in item.items() if key != "snapshot_sha256"}
    stored = item.get("snapshot_sha256")
    calculated = hashlib.sha256(canonical_json(body).encode()).hexdigest()
    try:
        before_deadline = datetime.fromisoformat(item["captured_at"]) < datetime.fromisoformat(item["official_deadline"])
    except (KeyError, ValueError):
        before_deadline = False
    return {"race_code": item.get("race_code"), "path": str(path.relative_to(REPO)), "captured_at": item.get("captured_at"),
            "official_deadline": item.get("official_deadline"), "captured_before_deadline": before_deadline,
            "result_guard": "PASS_BY_CAPTURE_CONTRACT", "snapshot_sha256": stored, "snapshot_sha256_readback_match": stored == calculated,
            "input_sha256": item.get("input_sha256")}


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--date", help="JST date, YYYY-MM-DD; defaults to current local date")
    args = parser.parse_args()
    date_text = args.date or datetime.now().astimezone().date().isoformat()
    prefix = date_text.replace("-", "")
    cache = cache_health(prefix)
    producer_cron = deadline_producer_cron_status()
    campaign = json.loads(CAMPAIGN.read_text(encoding="utf-8")) if CAMPAIGN.exists() else {}
    rows = batch_rows(date_text)
    last = rows[-1] if rows else {}
    last_summary = last.get("summary", {})
    all_errors = sum((row.get("summary") or {}).get("error", 0) for row in rows)
    reasons = Counter((item.get("missing_reason") or ["NONE"])[0] for item in last.get("results", []))
    db = db_summary(prefix)
    first = first_snapshot()
    formal_status = "ACTIVE" if campaign.get("status") == "ACTIVE" and int(campaign.get("snapshot_count") or 0) >= 1 else "ARMED"
    if int(db["entry_complete_but_race_master_missing_count"]) > 0:
        master_issue, next_action = "PERSISTING", "INVESTIGATE_CAPTURE_CONTRACT"
    else:
        master_issue, next_action = "RESOLVED_OR_NO_LONGER_BLOCKING", "WAIT"
    if formal_status == "ARMED" and cache["schema_status"] == "MISSING":
        zero_reason = "D: deadline cache missing"
    elif formal_status == "ARMED" and all_errors:
        zero_reason = "F: static cron failure"
    elif formal_status == "ARMED" and int(db["entry_complete_but_race_master_missing_count"]) > 0:
        zero_reason = "A: race_master missing despite complete race_entry"
    elif formal_status == "ARMED" and reasons.get("ENTRY_INCOMPLETE"):
        zero_reason = "B: race_entry incomplete"
    elif formal_status == "ARMED" and reasons.get("STATIC_SOURCE_NOT_READY"):
        zero_reason = "C: static source incomplete"
    elif formal_status == "ARMED" and reasons.get("DEADLINE_PASSED"):
        zero_reason = "E: deadline passed"
    else:
        zero_reason = "NONE" if formal_status == "ACTIVE" else "G: no capture attempt evidence"
    report = {"phase": "9-1h.1-morning-read-only", "observed_at": datetime.now().astimezone().isoformat(), "date": date_text,
              "deadline_cache": {**cache, "producer_cron_status": producer_cron}, "static_capture": {"today_batch_count": len(rows), "last_cron_run": campaign.get("last_batch_at"),
                  "last_summary": campaign.get("last_batch_summary", last_summary), "error_count_today": all_errors,
                  "lock_status": "NO_LOGGED_LOCK_ANOMALY", "latest_attempt_reasons": dict(reasons)},
              "campaign": {key: campaign.get(key) for key in ("status", "snapshot_count", "first_formal_snapshot_at", "first_race_code")},
              "race_master_vs_entry": db, "formal_snapshot": first, "FORMAL_SNAPSHOT_STATUS": formal_status,
              "FORMAL_SNAPSHOT_ZERO_REASON": zero_reason, "RACE_MASTER_CONTRACT_ISSUE": master_issue, "NEXT_ACTION": next_action,
              "read_only_guarantees": ["no DB write", "no snapshot generation", "no capture contract change", "no cron change"]}
    report_path = OUT / f"course4_tenkai_morning_health_{prefix}.json"
    text_path = OUT / f"course4_tenkai_morning_health_{prefix}.txt"
    report_path.write_text(json.dumps(report, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    text_path.write_text("\n".join([
        f"observed_at: {report['observed_at']}", f"FORMAL_SNAPSHOT_STATUS: {formal_status}",
        f"RACE_MASTER_CONTRACT_ISSUE: {master_issue}", f"NEXT_ACTION: {next_action}",
        f"deadline_cache: {cache['schema_status']} / producer_cron={producer_cron} / races={cache.get('race_count', 0)} / fetched_at={cache.get('fetched_at')}",
        f"static_capture: last={campaign.get('last_batch_at')} / errors={all_errors}",
        f"entry_complete_but_race_master_missing: {db['entry_complete_but_race_master_missing_count']}",
        f"formal_snapshot_count: {campaign.get('snapshot_count', 0)} / zero_reason={zero_reason}",
    ]) + "\n", encoding="utf-8")
    if json.loads(report_path.read_text(encoding="utf-8")) != report:
        raise RuntimeError("MORNING_REPORT_READBACK_MISMATCH")
    print(json.dumps({"report": str(report_path.relative_to(REPO)), "FORMAL_SNAPSHOT_STATUS": formal_status,
                      "RACE_MASTER_CONTRACT_ISSUE": master_issue, "NEXT_ACTION": next_action}, ensure_ascii=False))


if __name__ == "__main__":
    main()
