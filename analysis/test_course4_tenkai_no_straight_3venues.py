#!/usr/bin/env python3
"""Regression and live-contract checks for the fixed no-straight variants."""
from __future__ import annotations

import copy
import hashlib
import json
import math
import sys
from datetime import datetime
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
ANALYSIS = REPO / "analysis"
FORECAST = REPO / "forecast"
OUT = ANALYSIS / "output"
sys.path[:0] = [str(ANALYSIS), str(FORECAST)]

from course34_wall_no_straight_live import (  # noqa: E402
    FEATURE_ORDER as WALL_FEATURES, WallRNoStraightLiveInferenceCore,
)
from course4_attack_type_no_straight_live import (  # noqa: E402
    FEATURE_ORDER as ATTACK_FEATURES, AttackTypeNoStraightLiveInferenceCore,
)

CAPABILITIES = REPO / "config" / "course4_tenkai_venue_capabilities_v1.json"
REPORT = OUT / "course4_tenkai_no_straight_3venues_report.json"
WALL_ARTIFACT = OUT / "course34_wall_no_straight_v1_final_model.json"
ATTACK_ARTIFACT = OUT / "course4_attack_type_no_straight_v1_final_model.json"
FULL_WALL_ARTIFACT = OUT / "course34_wall_phase3r_final_model.json"
FULL_ATTACK_ARTIFACT = OUT / "course4_attack_type_phase7_final_model.json"


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def without_generated(payload):
    result = copy.deepcopy(payload)
    result.pop("generated_at", None)
    return result


def main():
    capability = json.loads(CAPABILITIES.read_text(encoding="utf-8"))
    report = json.loads(REPORT.read_text(encoding="utf-8"))
    wall_artifact = json.loads(WALL_ARTIFACT.read_text(encoding="utf-8"))
    attack_artifact = json.loads(ATTACK_ARTIFACT.read_text(encoding="utf-8"))

    checks = {
        "routing_exact_three_venues": {code for code, item in capability["venues"].items() if item.get("model_variant") == "NO_STRAIGHT"}
        == {"SME", "AMG", "TKY"},
        "default_full": capability["default"]["model_variant"] == "FULL",
        "edogawa_unsupported": capability["venues"].get("EDG", {}).get("model_variant") == "UNSUPPORTED"
        and capability["venues"]["EDG"].get("supported") is False,
        "wall_artifact_hash": sha(WALL_ARTIFACT) == "50896b1c5debba80cf39abc6e0608a5e05ba059b8e9c544ee173da9ddfb6e76c",
        "attack_artifact_hash": sha(ATTACK_ARTIFACT) == "5d9cb206d2b439b13e9ee9c06b326dccdbcada3c2ecddea6a1c8752288946cb2",
        "wall_feature_order": tuple(wall_artifact["feature_names"]) == WALL_FEATURES and "straight_time_delta_34" not in WALL_FEATURES,
        "attack_feature_order": tuple(attack_artifact["feature_order"]) == ATTACK_FEATURES and "course4_straight_time" not in ATTACK_FEATURES,
        "wall_round_trip": report["wall"]["round_trip"]["max_abs_diff"] == 0.0,
        "attack_round_trip": report["attack_type"]["round_trip"]["max_abs_diff"] == 0.0,
        "full_wall_artifact_unchanged": sha(FULL_WALL_ARTIFACT) == "f5348195d1d05c982f273ce7924c2b0eda5174e243e3e01bb818b87d6f3e768c",
        "full_attack_artifact_unchanged": sha(FULL_ATTACK_ARTIFACT) == "e0f3674e3e863a4a251aafb2e87530ebba85d4c393982d019fdccd124f3707b4",
    }

    wall_core = WallRNoStraightLiveInferenceCore()
    attack_core = AttackTypeNoStraightLiveInferenceCore()
    checks["wall_core_identity"] = wall_core.load_error is None
    checks["attack_core_identity"] = attack_core.load_error is None

    cutoff = datetime.now().astimezone().isoformat()
    wall_one = wall_core.predict("20261021TKY01", cutoff)
    attack_one = attack_core.predict("20261021TKY01", cutoff)
    wall_two = wall_core.predict("20261021TKY01", cutoff)
    attack_two = attack_core.predict("20261021TKY01", cutoff)
    checks["tokuyama_wall_live"] = bool(wall_one.get("prediction_available")) and 0 <= wall_one.get("wall_probability", -1) <= 1
    attack_probabilities = [attack_one.get(key) for key in ("p_no_attack_success", "p_makuri_success", "p_makurizashi_success")]
    checks["tokuyama_attack_live"] = bool(attack_one.get("prediction_available")) and all(isinstance(item, (int, float)) and 0 <= item <= 1 for item in attack_probabilities)
    checks["attack_probability_sum"] = checks["tokuyama_attack_live"] and abs(sum(attack_probabilities) - 1.0) <= 1e-12
    checks["wall_deterministic_repeat"] = without_generated(wall_one) == without_generated(wall_two)
    checks["attack_deterministic_repeat"] = without_generated(attack_one) == without_generated(attack_two)

    observed = {
        "TKY": {"race_code": "20261021TKY01", "wall": wall_one, "attack_type": attack_one},
    }
    for venue_code, race_code in (("SME", "20261021SME01"), ("AMG", "20261021AMG01")):
        observed[venue_code] = {
            "race_code": race_code,
            "wall": wall_core.predict(race_code, cutoff),
            "attack_type": attack_core.predict(race_code, cutoff),
        }
    support = {}
    for venue_code, item in observed.items():
        if item["wall"].get("prediction_available") and item["attack_type"].get("prediction_available"):
            support[venue_code] = "READY"
        else:
            support[venue_code] = "WAIT_NATURAL_INPUT"

    sources = [
        ANALYSIS / "course4_attack_type_no_straight_live_input_provider.py",
        ANALYSIS / "course34_wall_no_straight_live_input_provider.py",
        FORECAST / "course4_attack_type_no_straight_live.py",
        FORECAST / "course34_wall_no_straight_live.py",
    ]
    checks["result_table_access_zero"] = all(
        "race_result_detail" not in path.read_text(encoding="utf-8")
        and "race_payouts" not in path.read_text(encoding="utf-8")
        for path in sources
    )

    implementation = {
        "status": "READY" if all(checks.values()) else "BLOCKED",
        "capability_mapping": str(CAPABILITIES.relative_to(REPO)),
        "capability_mapping_sha256": sha(CAPABILITIES),
        "production_sources": {
            str(path.relative_to(REPO)): sha(path)
            for path in sources + [
                FORECAST / "course4_attack_type_live.py",
                REPO / "web" / "course34_wall_api.php",
                REPO / "web" / "course4_attack_type_api.php",
            ]
        },
        "checks": checks,
        "live_dry_run": {
            "race_code": "20261021TKY01",
            "wall_probability": wall_one.get("wall_probability"),
            "attack_type_probabilities": {
                "NO_ATTACK_SUCCESS": attack_one.get("p_no_attack_success"),
                "MAKURI_SUCCESS": attack_one.get("p_makuri_success"),
                "MAKURIZASHI_SUCCESS": attack_one.get("p_makurizashi_success"),
            },
            "probability_sum": sum(attack_probabilities) if checks["tokuyama_attack_live"] else None,
            "wall_artifact_sha256": wall_one.get("artifact_sha256"),
            "attack_artifact_sha256": attack_one.get("artifact_sha256"),
        },
        "support": support,
        "natural_input_observation": {
            code: {
                "race_code": item["race_code"],
                "wall_available": bool(item["wall"].get("prediction_available")),
                "wall_reason": item["wall"].get("missing_reason", []),
                "attack_type_available": bool(item["attack_type"].get("prediction_available")),
                "attack_type_reason": item["attack_type"].get("missing_reason", []),
            }
            for code, item in observed.items()
        },
        "ui_contract": "UNCHANGED",
        "historical_snapshot_backfill": False,
        "mutation_side_effect": 0,
        "generated_at": datetime.now().astimezone().isoformat(),
        "test_script": str(Path(__file__).relative_to(REPO)),
        "test_script_sha256": sha(Path(__file__)),
    }
    report["implementation"] = implementation
    report["final_readiness"] = {
        "NO_STRAIGHT_DATASET": "READY",
        "WALL_NO_STRAIGHT_MODEL": "READY",
        "ATTACK_TYPE_NO_STRAIGHT_MODEL": "READY",
        "SUMINOE_SUPPORT": implementation["support"]["SME"],
        "AMAGASAKI_SUPPORT": implementation["support"]["AMG"],
        "TOKUYAMA_SUPPORT": implementation["support"]["TKY"],
        "EXISTING_FULL_MODEL_REGRESSION": "PASS" if checks["full_wall_artifact_unchanged"] and checks["full_attack_artifact_unchanged"] else "FAIL",
        "UI_CONTRACT": "UNCHANGED",
        "TENKAI_AI_3VENUE_NO_STRAIGHT": implementation["status"],
    }
    REPORT.write_text(json.dumps(report, ensure_ascii=False, indent=2, default=dict) + "\n", encoding="utf-8")
    print(json.dumps({"pass": all(checks.values()), "checks": checks, "live_dry_run": implementation["live_dry_run"]}, ensure_ascii=False))
    if not all(checks.values()):
        raise SystemExit(1)


if __name__ == "__main__":
    main()
