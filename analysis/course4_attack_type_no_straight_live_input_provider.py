#!/usr/bin/env python3
"""No-straight Attack Type provider for SME/AMG/TKY.

All source queries and as-of rules remain in the shared provider.  This wrapper
only freezes the seven-feature artifact/contract identity.
"""
from __future__ import annotations

from pathlib import Path

from course4_attack_type_live_input_provider import AttackTypeLiveInputProvider

ROOT = Path(__file__).resolve().parent
OUT = ROOT / "output"
CONTRACT_VERSION = "course4-tenkai-attack-type-no-straight-live-input/v1"
FEATURE_ORDER = (
    "course4_history_n", "course4_history_makuri_rate_pct", "course4_history_makurizashi_rate_pct",
    "course4_ex_st", "course4_exhibition_time", "course4_lap_time", "course4_around_time",
)
ARTIFACT = OUT / "course4_attack_type_no_straight_v1_final_model.json"
SPEC = OUT / "course4_tenkai_no_straight_v1_spec.json"
EXPECTED_HASHES = {
    "artifact": "5d9cb206d2b439b13e9ee9c06b326dccdbcada3c2ecddea6a1c8752288946cb2",
    "spec": "faf382d38f63c2d443e37b8dd6219d7bd9785434f4aa0b7d995fb3700b4336c0",
    "ledger": "d4d97c47506e094d0093e99f2ad2fb84664db6fab09d0e90b1f2a0d56941b593",
}


class AttackTypeNoStraightLiveInputProvider(AttackTypeLiveInputProvider):
    def __init__(self):
        super().__init__(
            artifact_path=ARTIFACT,
            spec_path=SPEC,
            expected_hashes=EXPECTED_HASHES,
            feature_order=FEATURE_ORDER,
            contract_version=CONTRACT_VERSION,
            spec_feature_key="attack_type_feature_order",
        )
