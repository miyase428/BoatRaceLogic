#!/usr/bin/env python3
"""Read-only Phase 9-4a audit: UI contract and API side-effect isolation."""
from __future__ import annotations

import hashlib
import json
import subprocess
from datetime import datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SNAPSHOTS = ROOT / "var/course4_attack_type_forward_snapshots"
CAMPAIGN = ROOT / "analysis/output/course4_attack_type_true_forward_campaign_v1.json"
OUT = ROOT / "analysis/output/course4_tenkai_phase9_4a_report.json"


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def tree(path: Path) -> dict[str, object]:
    files = sorted(file for file in path.rglob("*") if file.is_file()) if path.exists() else []
    digest = hashlib.sha256()
    for file in files:
        digest.update(str(file.relative_to(path)).encode())
        digest.update(sha256(file).encode())
    return {"file_count": len(files), "sha256": digest.hexdigest()}


def campaign() -> dict[str, object]:
    return {"exists": CAMPAIGN.exists(), "sha256": sha256(CAMPAIGN) if CAMPAIGN.exists() else None,
            "content": json.loads(CAMPAIGN.read_text()) if CAMPAIGN.exists() else None}


def command(args: list[str]) -> subprocess.CompletedProcess[str]:
    return subprocess.run(args, cwd=ROOT, text=True, capture_output=True, check=False)


def main() -> None:
    before_snapshots, before_campaign = tree(SNAPSHOTS), campaign()
    api_call = command([
        "php", "-r",
        '$_GET=["race_code"=>"20261006WKM12"]; include "web/course4_attack_type_api.php";'
    ])
    api = json.loads(api_call.stdout) if api_call.returncode == 0 else {}
    after_snapshots, after_campaign = tree(SNAPSHOTS), campaign()

    static_test = command(["python3", "analysis/test_course4_attack_type_race_detail_ui_phase9_4a.py"])
    lint_paths = ["web/index.php", "web/app.php", "web/course4_attack_type_api.php",
                  "web/views/index_view.php", "web/views/app_view.php"]
    lints = []
    for path in lint_paths:
        result = command(["php", "-l", path])
        lints.append({"path": path, "pass": result.returncode == 0, "output": result.stdout.strip()})

    phase93 = json.loads((ROOT / "analysis/output/course4_tenkai_phase9_3_report.json").read_text())
    report = {
        "phase": "9-4a",
        "attack_type_race_detail_ui": "READY",
        "changed_files": [
            "web/views/index_view.php", "web/views/app_view.php",
            "web/assets/js/course4_attack_type_race_detail.js",
            "web/assets/css/course4_attack_type_race_detail.css"
        ],
        "display_location": "展示情報の直後（PC）および基本情報内の展示情報直後（mobile）",
        "api_endpoint": "web/course4_attack_type_api.php",
        "display_fields": ["まくり成功見込み", "まくり差し成功見込み", "攻撃成功なし"],
        "reason_mapping": {
            "NON_WAKUNARI": "進入変化のため対象外",
            "EXHIBITION_NOT_COMPLETE|ENTRY_MAPPING_INCOMPLETE|MISSING_REQUIRED_FEATURE": "展示データ待ち",
            "system_error": "展開予測を取得できませんでした"
        },
        "valid_display_api": {"race_code": "20261006WKM12", "pass": api.get("success") is True and api.get("prediction_available") is True},
        "unavailable_and_error_contract": {
            "ui_static_contract_pass": static_test.returncode == 0,
            "phase9_3_timeout_nonzero_malformed_pass": phase93.get("fixture_tests", {}).get("pass") is True
        },
        "mobile_check": "PASS" if static_test.returncode == 0 else "FAIL",
        "snapshot_isolation": {"before": before_snapshots, "after": after_snapshots,
                               "pass": before_snapshots == after_snapshots},
        "campaign_isolation": {"before": before_campaign, "after": after_campaign,
                               "pass": before_campaign == after_campaign},
        "existing_ui_regression": {"pass": all(item["pass"] for item in lints), "lints": lints},
        "generated_at": datetime.now().astimezone().isoformat(),
        "source_git_commit": command(["git", "rev-parse", "HEAD"]).stdout.strip(),
    }
    ready_checks = [
        report["valid_display_api"]["pass"], report["unavailable_and_error_contract"]["ui_static_contract_pass"],
        report["unavailable_and_error_contract"]["phase9_3_timeout_nonzero_malformed_pass"],
        report["snapshot_isolation"]["pass"], report["campaign_isolation"]["pass"],
        report["existing_ui_regression"]["pass"]
    ]
    report["attack_type_race_detail_ui"] = "READY" if all(ready_checks) else "BLOCKED"
    OUT.write_text(json.dumps(report, ensure_ascii=False, indent=2, sort_keys=True) + "\n")
    if json.loads(OUT.read_text()) != report:
        raise RuntimeError("report reload mismatch")
    print(json.dumps({"report": str(OUT.relative_to(ROOT)),
                      "attack_type_race_detail_ui": report["attack_type_race_detail_ui"]}, ensure_ascii=False))
    if report["attack_type_race_detail_ui"] != "READY":
        raise SystemExit(1)


if __name__ == "__main__":
    main()
