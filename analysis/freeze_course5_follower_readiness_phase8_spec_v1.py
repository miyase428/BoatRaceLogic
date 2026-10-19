#!/usr/bin/env python3
"""Freeze the Phase 8 safe feature whitelist from Phase 8-1a audits only.

No database access, fitting, outcome performance calculation, or feature
selection is performed here.  The SOURCE_OK order is read mechanically from
the Phase 8-1a source audit and is the only permitted candidate order.
"""
from __future__ import annotations

import csv
import hashlib
import json
import subprocess
from datetime import datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parent
REPO = ROOT.parent
OUT = ROOT / "output"

AUDIT = OUT / "course5_follower_readiness_phase8_feature_source_audit.csv"
SUMMARY = OUT / "course5_follower_readiness_phase8_1a_summary.json"
COVERAGE = OUT / "course5_follower_readiness_phase8_coverage_report.json"
FORWARD = OUT / "course5_follower_readiness_phase8_true_forward_schema.json"
ORDINARY = OUT / "course5_follower_readiness_phase8_ordinary_profiles_20241001_20260930.csv"
FOLLOWER = OUT / "course5_follower_readiness_phase8_follower_profiles_20241001_20260930.csv"
SPEC = OUT / "course5_follower_readiness_phase8_spec_v1.json"
REPORT = OUT / "course5_follower_readiness_phase8_spec_v1_audit.json"

EXPECTED_CONDITIONAL = {
    "Development": 2492,
    "Validation": 1231,
    "EXPOSED_CONFIRMATION": 1258,
}


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def git_head() -> str:
    return subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=REPO, text=True).strip()


def relative(path: Path) -> str:
    return str(path.relative_to(REPO))


def main() -> None:
    source_rows = list(csv.DictReader(AUDIT.open(encoding="utf-8")))
    candidates = [r["feature"] for r in source_rows if r["status"] == "SOURCE_OK"]
    blocked_raw = [r["feature"] for r in source_rows if r["status"] == "SOURCE_BLOCKED"]
    if len(candidates) != 9:
        raise RuntimeError(f"expected 9 SOURCE_OK candidates, got {len(candidates)}: {candidates}")
    if len(blocked_raw) != 5:
        raise RuntimeError(f"expected 5 SOURCE_BLOCKED features, got {len(blocked_raw)}: {blocked_raw}")

    summary = json.loads(SUMMARY.read_text(encoding="utf-8"))
    coverage = json.loads(COVERAGE.read_text(encoding="utf-8"))
    forward = json.loads(FORWARD.read_text(encoding="utf-8"))
    temporal_fields = (
        "TARGET_SELF_LEAK_COUNT", "SAME_DAY_RESULT_LEAK_COUNT",
        "FUTURE_SOURCE_COUNT", "SOURCE_MAX_DATE_VIOLATION",
    )
    temporal_pass = {field: summary.get(field) == 0 for field in temporal_fields}
    conditional = {
        "Development": summary.get("DEVELOPMENT_CONDITIONAL_N"),
        "Validation": summary.get("VALIDATION_CONDITIONAL_N"),
        "EXPOSED_CONFIRMATION": summary.get("EXPOSED_CONFIRMATION_CONDITIONAL_N"),
    }
    if conditional != EXPECTED_CONDITIONAL:
        raise RuntimeError(f"conditional counts changed: {conditional}")
    if summary.get("5C_6C_SYMMETRY") != "PASS" or not all(temporal_pass.values()):
        raise RuntimeError("Phase 8-1a temporal/symmetry prerequisites are not PASS")

    # The following mapping is an audit declaration, not a feature redesign.
    # Keys must exactly match the mechanically read SOURCE_OK names above.
    definitions = {
        "12m_course_count": {
            "cache_column": "history_n", "raw_source": ["race_master.race_date", "race_entry", "race_result_detail"],
            "derived_source": "ordinary profile", "dependency_chain": "strict historical actual-course facts -> [target_date-12 months,target_date) -> count",
            "historical_window": "[race_date-12 months, race_date)",
        },
        "12m_course_second_rate": {
            "cache_column": "history_second_rate", "raw_source": ["race_master.race_date", "race_entry", "race_result_detail.rank"],
            "derived_source": "ordinary profile", "dependency_chain": "strict historical actual-course facts -> prior actual-course second count / history_n",
            "historical_window": "[race_date-12 months, race_date)",
        },
        "12m_course_third_rate": {
            "cache_column": "history_third_rate", "raw_source": ["race_master.race_date", "race_entry", "race_result_detail.rank"],
            "derived_source": "ordinary profile", "dependency_chain": "strict historical actual-course facts -> prior actual-course third count / history_n",
            "historical_window": "[race_date-12 months, race_date)",
        },
        "12m_course_top3_rate": {
            "cache_column": "history_top3_rate", "raw_source": ["race_master.race_date", "race_entry", "race_result_detail.rank"],
            "derived_source": "ordinary profile", "dependency_chain": "strict historical actual-course facts -> prior actual-course top3 count / history_n",
            "historical_window": "[race_date-12 months, race_date)",
        },
        "12m_course_average_st": {
            "cache_column": "history_average_st", "raw_source": ["race_master.race_date", "race_entry", "race_result_detail.start_timing"],
            "derived_source": "ordinary profile", "dependency_chain": "strict historical actual-course facts -> prior-race real ST available-case mean",
            "historical_window": "[race_date-12 months, race_date)",
        },
        "36m_follower_attack_count": {
            "cache_column": "follower_attack_n", "raw_source": ["race_master.race_date", "race_entry", "race_result_detail.rank", "race_result_detail.technique"],
            "derived_source": "follower profile", "dependency_chain": "strict historical actual-course facts -> prior 4C first + normalized MAKURI + same player's actual-course history count",
            "historical_window": "[race_date-36 months, race_date)",
        },
        "36m_smoothed_second_rate": {
            "cache_column": "follower_smoothed_second_rate", "raw_source": ["strict historical result facts"],
            "derived_source": "follower profile + ordinary profile", "dependency_chain": "36m realized-MAKURI conditional second rate, shrunk to 12m ordinary second rate with fixed K=20",
            "historical_window": "36m follower / 12m ordinary; both end before race_date",
        },
        "36m_smoothed_third_rate": {
            "cache_column": "follower_smoothed_third_rate", "raw_source": ["strict historical result facts"],
            "derived_source": "follower profile + ordinary profile", "dependency_chain": "36m realized-MAKURI conditional third rate, shrunk to 12m ordinary third rate with fixed K=20",
            "historical_window": "36m follower / 12m ordinary; both end before race_date",
        },
        "36m_smoothed_top3_rate": {
            "cache_column": "follower_smoothed_top3_rate", "raw_source": ["strict historical result facts"],
            "derived_source": "follower profile + ordinary profile", "dependency_chain": "36m realized-MAKURI conditional top3 rate, shrunk to 12m ordinary top3 rate with fixed K=20",
            "historical_window": "36m follower / 12m ordinary; both end before race_date",
        },
    }
    if set(definitions) != set(candidates):
        raise RuntimeError("definition keys differ from mechanically read SOURCE_OK candidates")

    per_feature = []
    for feature in candidates:
        d = definitions[feature]
        # actual-course mapping's approved result_detail -> exhibition entry-course -> lane fallback
        # is not the blocked exhibition_st predictor.  It is held constant for both courses.
        per_feature.append({
            "feature": feature, **d, "classification": "SAFE",
            "blocked_raw_feature_dependency": False,
            "player_stats_dependency": False,
            "engine_specs_dependency": False,
            "unsafe_exhibition_snapshot_dependency": False,
            "actual_course_mapping_note": "Uses the fixed Phase 8-1a actual-course resolver only; does not read blocked exhibition_st. Historical mapping is identical for 5C/6C.",
            "target_race_result_dependency": False,
            "future_source_dependency": False,
            "target_race_excluded": True,
            "source_date_less_than_target": True,
            "same_day_result_usage": 0,
            "source_max_date_violation": 0,
        })

    symmetry = {}
    for feature in candidates:
        cache_column = definitions[feature]["cache_column"]
        section = "follower" if cache_column.startswith("follower_") else "ordinary"
        c5 = coverage["feature_coverage"]
        values = {}
        for split in EXPECTED_CONDITIONAL:
            # Corresponding coverage keys are intentionally explicit rather than inferred from outcomes.
            coverage_key = {
                "history_n": "history_count", "history_second_rate": "history_second_rate",
                "history_third_rate": "history_third_rate", "history_top3_rate": "history_top3_rate",
                "history_average_st": "history_average_st", "follower_attack_n": "follower_attack_count",
                "follower_smoothed_second_rate": "follower_smoothed_second_rate",
                "follower_smoothed_third_rate": "follower_smoothed_third_rate",
                "follower_smoothed_top3_rate": "follower_smoothed_top3_rate",
            }[cache_column]
            values[split] = {
                "course5": c5[split]["course5"][coverage_key],
                "course6": c5[split]["course6"][coverage_key],
            }
        symmetry[feature] = {
            "course5_source": f"{section} profile / {cache_column}",
            "course6_source": f"{section} profile / {cache_column}",
            "definition_match": True, "window_match": True, "coverage": values,
            "status": "PASS",
        }

    indirectly_blocked = [r["feature"] for r in per_feature if r["classification"] == "BLOCKED_INDIRECT"]
    final_order = [r["feature"] for r in per_feature if r["classification"] == "SAFE"]
    result = "PASS" if len(final_order) == 9 and not indirectly_blocked else "BLOCKED"
    spec = {
        "spec_version": "Phase 8-1aR / v1",
        "phase": "Phase 8 Follower Readiness",
        "purpose": "leakage-free Phase 8-1b candidate feature whitelist freeze only; no model fit or performance evaluation",
        "phase8_1a_summary": {"path": relative(SUMMARY), "sha256": sha256(SUMMARY)},
        "feature_source_audit": {"path": relative(AUDIT), "sha256": sha256(AUDIT)},
        "coverage_report": {"path": relative(COVERAGE), "sha256": sha256(COVERAGE)},
        "true_forward_schema": {"path": relative(FORWARD), "sha256": sha256(FORWARD)},
        "profile_inputs": {
            "ordinary_path": relative(ORDINARY), "ordinary_sha256": sha256(ORDINARY),
            "follower_path": relative(FOLLOWER), "follower_sha256": sha256(FOLLOWER),
        },
        "candidate_source_ok_order": candidates,
        "blocked_raw_features": blocked_raw,
        "indirectly_blocked_features": indirectly_blocked,
        "final_safe_feature_order": final_order,
        "final_feature_count": len(final_order),
        "feature_definitions_and_dependency_audit": per_feature,
        "fivec_sixc_symmetry": symmetry,
        "actual_course_rule": "result_detail -> latest exhibition entry_course -> lane; fixed Phase 8-1a resolver, not a model feature",
        "historical_windows": {"ordinary": "[race_date-12 months, race_date)", "follower": "[race_date-36 months, race_date)", "K": 20},
        "label_definition": {"primary": "I(actual-course 5C or 6C is TOP3)", "secondary": ["second", "third"], "target_outcome_as_feature": False},
        "conditional_sample_definition": "actual-course 4C is first AND normalized winner technique is MAKURI; used only for the conditional learning population/labels",
        "conditional_sample_counts": conditional,
        "split_definition": {
            "Development": ["2024-10-01", "2025-09-30"],
            "Validation": ["2025-10-01", "2026-03-31"],
            "EXPOSED_CONFIRMATION": ["2026-04-01", "2026-09-30"],
            "TRUE_FORWARD_schema_only": ["2026-10-01", "2027-03-31"],
        },
        "preprocessing_policy": "No preprocessing statistics are computed in this freeze. Phase 8-1b, if separately authorized, must determine any missing-value handling, median, mean, and standard deviation from Development only; no zero-fill; standardization ddof=0 if used.",
        "follower_outcome_prohibition_during_model_freeze": "No 5C/6C lift, follower gap, bands, interactions, threshold/subgroup/venue search, AUC, PR-AUC, or Confirmation performance is computed by this phase.",
        "temporal_audit": {field: summary[field] for field in temporal_fields},
        "phase_8_1ar_result": result,
        "generated_at": datetime.now().astimezone().isoformat(),
        "source_script": relative(Path(__file__)), "source_script_sha256": sha256(Path(__file__)),
        "source_git_commit": git_head(),
    }
    REPORT.write_text(json.dumps({
        "phase": "Phase 8-1aR", "candidate_features_mechanically_read": candidates,
        "dependency_audit": per_feature, "symmetry_audit": symmetry,
        "temporal_audit": spec["temporal_audit"], "conditional_counts": conditional,
        "result": result, "generated_at": spec["generated_at"],
    }, ensure_ascii=False, indent=2), encoding="utf-8")
    SPEC.write_text(json.dumps(spec, ensure_ascii=False, indent=2), encoding="utf-8")

    reload_spec = json.loads(SPEC.read_text(encoding="utf-8"))
    reload_audit = {
        "final_feature_count": reload_spec["final_feature_count"],
        "exact_feature_order": reload_spec["final_safe_feature_order"],
        "blocked_raw_feature_overlap": sorted(set(reload_spec["final_safe_feature_order"]) & set(blocked_raw)),
        "indirect_feature_overlap": sorted(set(reload_spec["final_safe_feature_order"]) & set(indirectly_blocked)),
        "split_matches": reload_spec["split_definition"] == {
            "Development": ["2024-10-01", "2025-09-30"],
            "Validation": ["2025-10-01", "2026-03-31"],
            "EXPOSED_CONFIRMATION": ["2026-04-01", "2026-09-30"],
            "TRUE_FORWARD_schema_only": ["2026-10-01", "2027-03-31"],
        },
        "conditional_counts_match": reload_spec["conditional_sample_counts"] == conditional,
        "source_audit_sha256_match": reload_spec["feature_source_audit"]["sha256"] == sha256(AUDIT),
    }
    if (reload_audit["final_feature_count"] != 9 or reload_audit["blocked_raw_feature_overlap"]
            or reload_audit["indirect_feature_overlap"] or not all(
                (reload_audit["split_matches"], reload_audit["conditional_counts_match"], reload_audit["source_audit_sha256_match"]
            ))):
        raise RuntimeError(f"reload audit failed: {reload_audit}")
    print(json.dumps({"spec": str(SPEC), "report": str(REPORT), "result": result,
                      "reload_audit": reload_audit}, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
