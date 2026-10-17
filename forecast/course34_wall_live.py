#!/usr/bin/env python3
"""Phase 9-1g frozen WALL-R live inference core; no feature construction."""
from __future__ import annotations
import argparse, json, sys
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent; ANALYSIS = REPO / "analysis"; sys.path.insert(0, str(ANALYSIS))
from course34_wall_live_input_provider import ARTIFACT, EXPECTED_ARTIFACT_SHA256, WallRLiveInputProvider
from course4_tenkai_binary_live import BinaryLiveInferenceCore

FEATURE_ORDER = ("smoothed_avg_st_delta_34", "ex_st_delta_34", "avg_st_rank_delta_34", "st_profile_reliability_advantage_34", "exhibition_time_delta_34", "lap_time_delta_34", "around_time_delta_34", "straight_time_delta_34")

class WallRLiveInferenceCore(BinaryLiveInferenceCore):
    def __init__(self, provider=None, paths=None, expected_artifact=None):
        super().__init__(signal_id="WALL_R", probability_key="wall_probability", score_key="wall_score", artifact_path=ARTIFACT,
                         expected_artifact_sha256=EXPECTED_ARTIFACT_SHA256, expected_feature_order=FEATURE_ORDER,
                         provider_factory=WallRLiveInputProvider, provider_source=ANALYSIS / "course34_wall_live_input_provider.py",
                         logic_source=Path(__file__), provider=provider, paths=paths, expected_artifact=expected_artifact)

def predict(race_code, prediction_cutoff): return WallRLiveInferenceCore().predict(race_code, prediction_cutoff)
if __name__ == "__main__":
    parser=argparse.ArgumentParser(); parser.add_argument("--race-code",required=True); parser.add_argument("--prediction-cutoff",required=True); args=parser.parse_args()
    print(json.dumps(predict(args.race_code,args.prediction_cutoff),ensure_ascii=False,sort_keys=True))
