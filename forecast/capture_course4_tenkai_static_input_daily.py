#!/usr/bin/env python3
"""Independent, lock-run daily batch for formal Course4 Tenkai static inputs."""
from __future__ import annotations

import argparse
import json
import subprocess
import sys
from datetime import datetime
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(REPO / "analysis"))
from capture_course4_tenkai_static_input_snapshot import (CAPTURE_CONTRACT_VERSION, RETRYABLE, Course4TenkaiStaticSnapshotCapture, StaticSnapshotGuard)
from course4_tenkai_canonical_input import CANONICAL_VERSION, SNAPSHOT_ROOT, SNAPSHOT_VERSION, sha256

CAMPAIGN = REPO / "analysis" / "output" / "course4_tenkai_static_snapshot_campaign_v1.json"


def now_iso(): return datetime.now().astimezone().isoformat()
def git_head(): return subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=REPO, text=True).strip()


class DailyStaticSnapshotBatch:
    def __init__(self, capture=None, guard=None, campaign_path=CAMPAIGN, snapshot_root=SNAPSHOT_ROOT, deadline_preparer=None):
        self.guard, self.capture, self.campaign_path, self.snapshot_root = guard or StaticSnapshotGuard(), capture or Course4TenkaiStaticSnapshotCapture(), Path(campaign_path), Path(snapshot_root)
        self.deadline_preparer = deadline_preparer or self._prepare_missing_deadline_cache

    def _prepare_missing_deadline_cache(self, date_text):
        command = ["/usr/bin/php", str(REPO / "analysis" / "prepare_course4_tenkai_deadline_cache.php"), f"--date={date_text}"]
        try:
            result = subprocess.run(command, cwd=REPO, text=True, capture_output=True, timeout=300, check=False)
            payload = json.loads(result.stdout) if result.stdout.strip() else {}
        except (OSError, subprocess.SubprocessError, ValueError):
            return {"ok": False, "status": "OFFICIAL_DEADLINE_UNAVAILABLE"}
        return payload if result.returncode == 0 and isinstance(payload, dict) else {"ok": False, "status": str(payload.get("status", "OFFICIAL_DEADLINE_UNAVAILABLE"))}

    @staticmethod
    def _read_cache(path):
        try:
            data = json.loads(path.read_text(encoding="utf-8"))
            return data if isinstance(data, dict) and isinstance(data.get("deadlines"), dict) else None
        except (OSError, ValueError):
            return None
    def _campaign(self, results):
        root = self.snapshot_root / "static_v1"; snapshots = sorted(root.glob("*.json")) if root.exists() else []
        first = None
        for path in snapshots:
            try:
                item = json.loads(path.read_text(encoding="utf-8"))
                candidate = (item.get("captured_at"), item.get("race_code"))
                if candidate[0] and (first is None or candidate < first): first = candidate
            except (OSError, ValueError): continue
        prior = json.loads(self.campaign_path.read_text(encoding="utf-8")) if self.campaign_path.exists() else {}
        try: root_name = str(self.snapshot_root.relative_to(REPO))
        except ValueError: root_name = str(self.snapshot_root)
        payload = {"campaign_id": "course4-tenkai-static-snapshot/v1", "status": "ACTIVE" if snapshots else "ARMED", "snapshot_root": root_name,
                   "snapshot_version": SNAPSHOT_VERSION, "capture_contract_version": CAPTURE_CONTRACT_VERSION, "wall_canonical_version": CANONICAL_VERSION,
                   "a5_canonical_version": CANONICAL_VERSION, "snapshot_count": len(snapshots), "first_formal_snapshot_at": first[0] if first else None,
                   "first_race_code": first[1] if first else None, "last_batch_at": now_iso(), "last_batch_summary": results,
                   "source_script": str(Path(__file__).relative_to(REPO)), "source_script_sha256": sha256(Path(__file__)), "source_git_commit": git_head()}
        # A campaign start marker is immutable once an actual formal capture has occurred.
        if prior.get("first_formal_snapshot_at") and payload["first_formal_snapshot_at"] != prior["first_formal_snapshot_at"]:
            raise RuntimeError("FORMAL_CAMPAIGN_START_MUTATION")
        tmp = self.campaign_path.with_suffix(".tmp"); tmp.write_text(json.dumps(payload, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8"); tmp.replace(self.campaign_path)
        return payload
    def run(self, date_text, prediction_cutoff=None):
        prefix = str(date_text).replace("-", "")
        cutoff = prediction_cutoff or now_iso()
        cache = self.guard.deadline_dir / f"deadlines_{prefix}.json"
        data = self._read_cache(cache)
        preparation = None
        if data is None:
            preparation = self.deadline_preparer(prefix)
            data = self._read_cache(cache)
        if data is None:
            summary = {"created": 0, "existing": 0, "retryable_unavailable": 0, "permanent_unavailable": 1, "deadline_passed": 0, "result_finalized": 0, "error": 0}
            campaign = self._campaign(summary)
            return {"date": str(date_text), "status": "OFFICIAL_DEADLINE_UNAVAILABLE", "deadline_preparation": preparation, "results": [], "summary": summary, "campaign": campaign}
        codes = sorted(str(code).upper() for code in data.get("deadlines", {}) if str(code).upper().startswith(prefix))
        results = []
        for code in codes:
            try: results.append(self.capture.capture(code, cutoff))
            except Exception as error: results.append({"race_code": code, "status": "ERROR", "missing_reason": [type(error).__name__]})
        summary = {"created": 0, "existing": 0, "retryable_unavailable": 0, "permanent_unavailable": 0, "deadline_passed": 0, "result_finalized": 0, "error": 0}
        for item in results:
            reason = (item.get("missing_reason") or [""])[0]
            if item.get("status") == "SAVED": summary["created"] += 1
            elif item.get("status") == "ALREADY_EXISTS": summary["existing"] += 1
            elif item.get("status") == "ERROR": summary["error"] += 1
            elif item.get("status") == "UNAVAILABLE" and reason in RETRYABLE: summary["retryable_unavailable"] += 1
            else:
                summary["permanent_unavailable"] += 1; summary["deadline_passed"] += reason == "DEADLINE_PASSED"; summary["result_finalized"] += reason == "RESULT_ALREADY_EXISTS"
        campaign = self._campaign(summary)
        return {"date": str(date_text), "prediction_cutoff": cutoff, "race_n": len(codes), "results": results, "summary": summary, "campaign": campaign}


def main():
    parser = argparse.ArgumentParser(); parser.add_argument("--date", default=datetime.now().astimezone().date().isoformat()); parser.add_argument("--prediction-cutoff")
    args = parser.parse_args(); print(json.dumps(DailyStaticSnapshotBatch().run(args.date, args.prediction_cutoff), ensure_ascii=False, sort_keys=True))
if __name__ == "__main__": main()
