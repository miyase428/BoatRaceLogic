#!/usr/bin/env python3
"""Phase 9-1g frozen A5 live inference core; no feature construction."""
from __future__ import annotations
import argparse, json, sys
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent; ANALYSIS = REPO / "analysis"; sys.path.insert(0, str(ANALYSIS))
from course4_attack_a5_live_input_provider import ARTIFACT, EXPECTED_ARTIFACT_SHA256, AttackA5LiveInputProvider
from course4_attack_ledger_generation_store import MODE_GENERATION, MODE_LEGACY
from course4_tenkai_binary_live import BinaryLiveInferenceCore

FEATURE_ORDER = ("course4_history_n", "course4_history_win_rate_pct", "course4_history_top2_rate_pct", "course4_history_top3_rate_pct", "course4_history_makuri_rate_pct", "course4_history_makurizashi_rate_pct", "course4_history_attack_rate_pct", "smoothed_course4_st", "course4_average_st_rank", "course4_entry_count", "course4_ex_st", "course4_exhibition_time", "course4_lap_time", "course4_around_time", "course4_straight_time", "motor_2ren_rate", "boat_2ren_rate", "national_win_rate", "local_win_rate", "national_2ren_rate", "local_2ren_rate")

class AttackA5LiveInferenceCore(BinaryLiveInferenceCore):
    def __init__(self, provider=None, paths=None, expected_artifact=None, *, ledger_mode=MODE_LEGACY,
                 generation_root=None, generation_store_factory=None):
        if ledger_mode not in (MODE_LEGACY, MODE_GENERATION):
            raise ValueError("INVALID_LEDGER_MODE")
        provider_kwargs = {"ledger_mode": ledger_mode, "generation_root": generation_root}
        if generation_store_factory is not None:
            provider_kwargs["generation_store_factory"] = generation_store_factory
        super().__init__(signal_id="ATTACK_A5", probability_key="attack_probability", score_key="attack_score", artifact_path=ARTIFACT,
                         expected_artifact_sha256=EXPECTED_ARTIFACT_SHA256, expected_feature_order=FEATURE_ORDER,
                         provider_factory=lambda: AttackA5LiveInputProvider(**provider_kwargs), provider_source=ANALYSIS / "course4_attack_a5_live_input_provider.py",
                         logic_source=Path(__file__), provider=provider, paths=paths, expected_artifact=expected_artifact)

def predict(race_code, prediction_cutoff): return AttackA5LiveInferenceCore().predict(race_code, prediction_cutoff)
if __name__ == "__main__":
    parser=argparse.ArgumentParser(); parser.add_argument("--race-code",required=True); parser.add_argument("--prediction-cutoff",required=True); args=parser.parse_args()
    print(json.dumps(predict(args.race_code,args.prediction_cutoff),ensure_ascii=False,sort_keys=True))
