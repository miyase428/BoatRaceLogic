#!/usr/bin/env python3
"""Seven-feature WALL-R input provider for SME/AMG/TKY."""
from __future__ import annotations

from pathlib import Path

from course34_wall_live_input_provider import WallRLiveInputProvider
from course4_attack_type_no_straight_live_input_provider import AttackTypeNoStraightLiveInputProvider

ROOT = Path(__file__).resolve().parent
OUT = ROOT / "output"
CONTRACT_VERSION = "course34-wall-no-straight-live-input/v1"
ARTIFACT = OUT / "course34_wall_no_straight_v1_final_model.json"
EXPECTED_ARTIFACT_SHA256 = "50896b1c5debba80cf39abc6e0608a5e05ba059b8e9c544ee173da9ddfb6e76c"
FULL_MANIFEST_FEATURE_ORDER = (
    "smoothed_avg_st_delta_34", "ex_st_delta_34", "avg_st_rank_delta_34",
    "st_profile_reliability_advantage_34", "exhibition_time_delta_34", "lap_time_delta_34",
    "around_time_delta_34", "straight_time_delta_34",
)


class WallRNoStraightLiveInputProvider(WallRLiveInputProvider):
    def __init__(self, snapshot_store=None, exhibition_provider=None):
        super().__init__(
            snapshot_store=snapshot_store,
            exhibition_provider=exhibition_provider or AttackTypeNoStraightLiveInputProvider(),
            artifact_path=ARTIFACT,
            expected_artifact_sha256=EXPECTED_ARTIFACT_SHA256,
            contract_version=CONTRACT_VERSION,
            manifest_feature_order=FULL_MANIFEST_FEATURE_ORDER,
            require_manifest_artifact=False,
        )
