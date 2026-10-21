#!/usr/bin/env python3
"""Phase 6C-3: integrate saved Phase 6 reports without new analysis or fitting."""
from __future__ import annotations

import datetime
import hashlib
import json
import subprocess
from pathlib import Path


ROOT = Path(__file__).resolve().parent
OUT = ROOT / "output"
FINAL = OUT / "course4_attack_success_phase6_final_report.json"
SOURCES = {
    "race_cache_metadata": OUT / "course4_attack_success_phase6_race_cache_meta.json",
    "cluster_cache_metadata": OUT / "course4_attack_success_phase6_cluster_cache_meta.json",
    "bootstrap_1000": OUT / "course4_attack_success_phase6_bootstrap_1000.json",
    "decile_analysis": OUT / "course4_attack_success_phase6_deciles_report.json",
    "confounding_descriptive": OUT / "course4_attack_success_phase6_confounding_report.json",
    "symmetric_adjustment": OUT / "course4_attack_success_phase6_adjustment_report.json",
}
EXPECTED_SHA256 = {
    "race_cache_metadata": "c928f20670c101d2cf4c7b87e4f4aa874fe9ebda2b9872a311165fd54f80ddfe",
    "cluster_cache_metadata": "e3c999687e70cdf984a24f0ead7ba2e8f296f4ce204d0378f3a18e3595d3a298",
    "bootstrap_1000": "d45ae6ce6e03db1d0c757080abb9123f26bae3432d220fd2603458756ae7d18b",
    "decile_analysis": "ba4ccad100795460717fe2295ace2a4b492d6e6eb09ca2281a00f2d9e10345e8",
    "confounding_descriptive": "e2957bb55dc7adb09f39f4952052fab8b9d74cb1ea84c7ad155b9a904f775212",
    "symmetric_adjustment": "2560a47258feff95ccaca303d8d40b931d84449a7bf7f46e6e6fd42d430c4fb5",
}


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def load(name: str) -> dict:
    path = SOURCES[name]
    digest = sha256(path)
    if digest != EXPECTED_SHA256[name]:
        raise RuntimeError(f"saved Phase 6 source changed: {name}")
    return json.loads(path.read_text(encoding="utf-8"))


def main() -> None:
    race_meta = load("race_cache_metadata")
    cluster_meta = load("cluster_cache_metadata")
    bootstrap = load("bootstrap_1000")
    deciles = load("decile_analysis")
    confounding = load("confounding_descriptive")
    adjustment = load("symmetric_adjustment")

    bootstrap_evidence = {}
    for split in ("Validation", "EXPOSED_CONFIRMATION"):
        metrics = bootstrap["splits"][split]["metrics"]
        bootstrap_evidence[split] = {
            outcome: {
                "five_high_minus_low_pct_point": metrics[f"five_{outcome}_lift"]["point_estimate_pct_point"],
                "five_percentile_95_ci": metrics[f"five_{outcome}_lift"]["percentile_95_ci_pct_point"],
                "six_high_minus_low_pct_point": metrics[f"six_{outcome}_lift"]["point_estimate_pct_point"],
                "six_percentile_95_ci": metrics[f"six_{outcome}_lift"]["percentile_95_ci_pct_point"],
                "follower_gap_pct_point": metrics[f"follower_gap_{outcome}"]["point_estimate_pct_point"],
                "follower_gap_percentile_95_ci": metrics[f"follower_gap_{outcome}"]["percentile_95_ci_pct_point"],
            }
            for outcome in ("second", "third", "top3")
        }

    adjustment_evidence = {}
    for outcome in ("second", "third", "top3"):
        adjustment_evidence[outcome] = {
            "course5_attack_standardized_coefficient": adjustment["results"][outcome]["course5"]["attack_standardized_coefficient"],
            "course5_confirmation_plus_minus_base": adjustment["results"][outcome]["course5"]["confirmation_delta"],
            "course6_attack_standardized_coefficient": adjustment["results"][outcome]["course6"]["attack_standardized_coefficient"],
            "course6_confirmation_plus_minus_base": adjustment["results"][outcome]["course6"]["confirmation_delta"],
            "phase6c2_internal_label": adjustment["results"][outcome]["internal_diagnostic_label"],
        }

    report = {
        "phase": "Phase 6C-3 final integration",
        "final_classification": "D",
        "classification_definition": "No evidence supporting pre-race Attack Success as a 5C-specific follower-benefit predictor, or major outcomes point in the opposite direction.",
        "decision_basis": [
            "In both splits, 5C third and top3 High-minus-Low effects are negative; 5C top3 percentile CIs exclude zero on the negative side.",
            "The 5C-minus-6C follower gaps for third and top3 are negative in both splits, although their CIs include zero.",
            "Fixed raw-probability deciles reproduce negative 5C third/top3 D10-minus-D1 effects in both splits, so the result is not limited to the five-band boundary.",
            "High Attack Success races have slightly stronger, not weaker, 5C/6C pre-race ability composition; a simple weak-5C compositional explanation is not supported.",
            "After symmetric pre-race ability adjustment, the 5C Attack Success coefficients are negative for third and top3. Metric increments are extremely small.",
            "Second is small and mixed and does not outweigh the opposite-direction evidence in the broader third/top3 outcomes.",
        ],
        "bootstrap_1000": bootstrap_evidence,
        "decile_d10_minus_d1": deciles["d10_minus_d1_lifts_pct_point"],
        "confounding_conclusion": {
            "summary": "Attack Success correlations with own 5C/6C pre-race ability are small, while High-band ability is generally slightly stronger.",
            "fixed_threshold_stratification": confounding["fixed_threshold_stratification"],
        },
        "symmetric_adjustment": adjustment_evidence,
        "phase2_relationship": {
            "contradiction": False,
            "phase2_estimand": "Post-result association conditional on realized 4C attack success: follower benefit can occur after an attack actually succeeds.",
            "phase6_estimand": "Pre-race predictive value: whether a higher frozen Attack Success probability alone predicts a 5C-specific follower benefit.",
            "interpretation": "A realized mechanism may exist without being recoverable from one pre-race probability. Phase 6 does not negate the Phase 2 post-result phenomenon.",
        },
        "chain_status": {
            "predictive_chain_supported": ["3C Wall", "4C Attack", "4C Attack Success"],
            "post_result_association_retained": "realized 4C attack success → observed 5C follower benefit",
            "unsupported_extension": "pre-race COURSE4_ATTACK_SUCCESS probability → 5C-specific follower benefit",
            "causal_caution": "The supported pre-race links are predictive artifacts; Phase 6 does not establish a causal chain.",
        },
        "phase7_candidates_not_implemented": [
            "Pre-register separate makuri and makurizashi attack-type probabilities.",
            "Pre-register 5C-versus-4C relative ST.",
            "Pre-register a 5C following-suitability construct.",
            "Pre-register 5C-versus-6C relative ability.",
            "Pre-register attack direction and 5C positional compatibility.",
        ],
        "prohibited_post_hoc_actions_confirmed_absent": [
            "No subgroup search", "No threshold search", "No feature addition", "No additional model",
            "No additional bootstrap", "No Production/Web/Strict Forward change", "No Phase 7 implementation",
        ],
        "phase6a_provenance": {
            "race_cache_sha256": race_meta["cache_sha256"],
            "race_cache_rows": race_meta["cache_row_count"],
            "cluster_cache_sha256": cluster_meta["cluster_cache_sha256"],
            "cluster_cache_rows": cluster_meta["cluster_cache_row_count"],
        },
        "source_reports": {name: {"path": str(path.relative_to(ROOT.parent)), "sha256": EXPECTED_SHA256[name]} for name, path in SOURCES.items()},
        "source_script": "analysis/finalize_course4_attack_success_phase6.py",
        "source_script_sha256": sha256(Path(__file__).resolve()),
        "source_git_commit": subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT.parent, text=True).strip(),
        "generated_at": datetime.datetime.now().astimezone().isoformat(),
    }
    FINAL.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    saved = json.loads(FINAL.read_text(encoding="utf-8"))
    if saved["final_classification"] != "D" or saved["phase2_relationship"]["contradiction"] is not False:
        raise RuntimeError("final report round-trip audit failed")
    print(json.dumps({
        "final_report": str(FINAL.resolve()),
        "classification": saved["final_classification"],
        "source_report_hashes_verified": True,
        "race_cache_rows": saved["phase6a_provenance"]["race_cache_rows"],
        "cluster_cache_rows": saved["phase6a_provenance"]["cluster_cache_rows"],
        "roundtrip": "PASS",
        "final_report_sha256": sha256(FINAL),
    }, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
