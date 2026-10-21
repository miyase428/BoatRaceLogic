#!/usr/bin/env python3
"""Read-only Phase 9-3 audit for the 4C Attack Type PHP adapter."""
from __future__ import annotations

import hashlib
import json
import os
import subprocess
from datetime import datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
API = ROOT / "web/course4_attack_type_api.php"
CORE = ROOT / "forecast/course4_attack_type_live.py"
SCHEMA = ROOT / "analysis/output/course4_attack_type_api_schema_v1.json"
SNAPSHOT_ROOT = ROOT / "var/course4_attack_type_forward_snapshots"
CAMPAIGN = ROOT / "analysis/output/course4_attack_type_true_forward_campaign_v1.json"
OUT = ROOT / "analysis/output/course4_tenkai_phase9_3_report.json"
VALID_RACE = "20261006WKM12"


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def run(command: list[str], *, env: dict[str, str] | None = None) -> subprocess.CompletedProcess[str]:
    return subprocess.run(command, cwd=ROOT, text=True, capture_output=True, env=env, check=False)


def invoke_api(race_code: str) -> tuple[dict, str]:
    source = '$_GET=["race_code"=>$argv[1]]; include "web/course4_attack_type_api.php";'
    result = run(["php", "-r", source, race_code])
    if result.returncode != 0:
        raise RuntimeError(f"API invocation failed: {result.stderr.strip()}")
    return json.loads(result.stdout), result.stderr


def tree_identity(path: Path) -> dict:
    files = sorted(p for p in path.rglob("*") if p.is_file()) if path.exists() else []
    digest = hashlib.sha256()
    for file in files:
        digest.update(str(file.relative_to(path)).encode())
        digest.update(sha256(file).encode())
    return {"file_count": len(files), "sha256": digest.hexdigest()}


def file_identity(path: Path) -> dict:
    return {"exists": path.exists(), "sha256": sha256(path) if path.exists() else None,
            "content": json.loads(path.read_text()) if path.exists() else None}


def php_lint(path: Path) -> dict:
    if not path.exists():
        return {"path": str(path.relative_to(ROOT)), "status": "NOT_PRESENT"}
    result = run(["php", "-l", str(path.relative_to(ROOT))])
    return {"path": str(path.relative_to(ROOT)), "status": "PASS" if result.returncode == 0 else "FAIL",
            "output": result.stdout.strip()}


def main() -> None:
    schema = json.loads(SCHEMA.read_text())
    before_snapshots = tree_identity(SNAPSHOT_ROOT)
    before_campaign = file_identity(CAMPAIGN)

    api, _ = invoke_api(VALID_RACE)
    if not api.get("success") or not api.get("prediction_available"):
        raise RuntimeError(f"valid parity case unavailable: {api}")
    cutoff = api["prediction_cutoff"]
    direct_result = run(["/usr/bin/python3", str(CORE), "--race-code", VALID_RACE,
                         "--prediction-cutoff", cutoff, "--json"])
    if direct_result.returncode != 0:
        raise RuntimeError(f"core failed: {direct_result.stderr.strip()}")
    core = json.loads(direct_result.stdout)
    after_snapshots = tree_identity(SNAPSHOT_ROOT)
    after_campaign = file_identity(CAMPAIGN)

    probability_pairs = {
        "no_attack_success": (api["probabilities"]["no_attack_success"], core["p_no_attack_success"]),
        "makuri_success": (api["probabilities"]["makuri_success"], core["p_makuri_success"]),
        "makurizashi_success": (api["probabilities"]["makurizashi_success"], core["p_makurizashi_success"]),
    }
    probability_max_abs_diff = max(abs(left - right) for left, right in probability_pairs.values())
    identity_keys = ["logic_version", "artifact_sha256", "spec_sha256", "ledger_sha256",
                     "input_contract_version", "input_sha256"]
    identity_match = {key: api["identity"][key] == core[key] for key in identity_keys}
    parity = {
        "case": {"race_code": VALID_RACE, "prediction_cutoff": cutoff, "stage": api.get("stage")},
        "race_code": api["race_code"] == core["race_code"] == VALID_RACE,
        "stage": api["stage"] == core["stage"] == "POST_EXHIBITION",
        "prediction_cutoff": api["prediction_cutoff"] == core["prediction_cutoff"],
        "prediction_available": api["prediction_available"] == core["prediction_available"] is True,
        "probability_pairs": probability_pairs,
        "probability_max_abs_diff": probability_max_abs_diff,
        "identity_match": identity_match,
        "pass": probability_max_abs_diff <= 1e-12 and all(identity_match.values())
    }

    unavailable_api, _ = invoke_api("20261008WKM12")
    unavailable_core_result = run(["/usr/bin/python3", str(CORE), "--race-code", "20261008WKM12",
                                   "--prediction-cutoff", unavailable_api["prediction_cutoff"], "--json"])
    unavailable_core = json.loads(unavailable_core_result.stdout)
    unavailable = {
        "api_reason_code": unavailable_api.get("reason_code"),
        "core_reason_code": (unavailable_core.get("missing_reason") or [None])[0],
        "pass": unavailable_api.get("success") is True and unavailable_api.get("prediction_available") is False
                and unavailable_api.get("reason_code") == (unavailable_core.get("missing_reason") or [None])[0],
    }

    fixture_result = run(["/usr/bin/python3", "analysis/test_course4_attack_type_api_phase9_3.py"])
    fixtures = {"pass": fixture_result.returncode == 0,
                "stdout": fixture_result.stdout.strip(), "stderr": fixture_result.stderr.strip()}
    snapshot_isolation = {"before": before_snapshots, "after": after_snapshots,
                          "pass": before_snapshots == after_snapshots}
    campaign_isolation = {"before": before_campaign, "after": after_campaign,
                          "pass": before_campaign == after_campaign}

    lint_paths = [API, ROOT / "web/calc_scores.php", ROOT / "web/tenji_api.php",
                  ROOT / "web/kimarite_api.php", ROOT / "web/slit_api.php",
                  ROOT / "web/tamagawa_lane4_star_api.php", ROOT / "web/forward_status_api.php",
                  ROOT / "web/app.php"]
    lints = [php_lint(path) for path in lint_paths]
    regression_pass = all(item["status"] != "FAIL" for item in lints)

    source = API.read_text()
    security = {
        "race_code_regex": "preg_match('/^\\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/'" in source,
        "array_process_command": "proc_open($cmd" in source and "$cmd=[$python,$script" in source,
        "fixed_working_directory": "$root);" in source,
        "timeout_15_seconds": ">15" in source,
        "stderr_separated": "2=>['pipe','w']" in source,
        "client_internal_detail_absent": "error_message'=>'inference service" in source,
        "snapshot_or_campaign_write_absent": "snapshot" not in source.lower() and "campaign" not in source.lower(),
    }
    security["pass"] = all(security.values())
    invalid_api, _ = invoke_api("invalid")
    schema_audit = {
        "schema_sha256": sha256(SCHEMA),
        "schema_version": schema.get("schema_version"),
        "success_response": (
            api.get("success") is True and api.get("prediction_available") is True
            and isinstance(api.get("race_code"), str) and isinstance(api.get("stage"), str)
            and isinstance(api.get("prediction_cutoff"), str) and isinstance(api.get("generated_at"), str)
            and all(isinstance(api["probabilities"].get(key), (float, int)) for key in probability_pairs)
            and all(isinstance(api["identity"].get(key), str) for key in identity_keys)
        ),
        "unavailable_response": (
            unavailable_api.get("success") is True and unavailable_api.get("prediction_available") is False
            and isinstance(unavailable_api.get("reason_code"), str)
            and isinstance(unavailable_api.get("reason_message"), str)
            and (unavailable_api.get("generated_at") is None or isinstance(unavailable_api.get("generated_at"), str))
        ),
        "validation_error_response": (
            invalid_api.get("success") is False and invalid_api.get("error_code") == "INVALID_RACE_CODE"
            and isinstance(invalid_api.get("error_message"), str)
        ),
        "system_error_response": fixtures["pass"],
    }
    schema_audit["pass"] = all(value is True or key in {"schema_sha256", "schema_version"}
                               for key, value in schema_audit.items())

    ready = all([parity["pass"], unavailable["pass"], fixtures["pass"], snapshot_isolation["pass"],
                 campaign_isolation["pass"], regression_pass, security["pass"], schema_audit["pass"]])
    report = {
        "phase": "9-3", "attack_type_api": "READY" if ready else "BLOCKED",
        "endpoint": str(API.relative_to(ROOT)),
        "api_schema": {"path": str(SCHEMA.relative_to(ROOT)), "sha256": sha256(SCHEMA)},
        "python_core": {"path": str(CORE.relative_to(ROOT)), "identity": core.get("logic_version")},
        "valid_parity": parity, "unavailable_reason_parity": unavailable,
        "fixture_tests": fixtures, "snapshot_isolation": snapshot_isolation,
        "campaign_isolation": campaign_isolation, "security_audit": security,
        "existing_api_regression": {"pass": regression_pass, "lints": lints},
        "schema_reload_audit": schema_audit,
        "generated_at": datetime.now().astimezone().isoformat(),
        "source_git_commit": run(["git", "rev-parse", "HEAD"]).stdout.strip(),
    }
    OUT.write_text(json.dumps(report, ensure_ascii=False, indent=2, sort_keys=True) + "\n")
    reloaded = json.loads(OUT.read_text())
    if reloaded != json.loads(json.dumps(report)):
        raise RuntimeError("report reload mismatch")
    print(json.dumps({"report": str(OUT.relative_to(ROOT)), "attack_type_api": report["attack_type_api"],
                      "probability_max_abs_diff": probability_max_abs_diff}, ensure_ascii=False))
    if not ready:
        raise SystemExit(1)


if __name__ == "__main__":
    main()
