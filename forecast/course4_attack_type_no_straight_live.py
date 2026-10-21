#!/usr/bin/env python3
"""Frozen seven-feature Attack Type live inference for no-straight venues."""
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
ANALYSIS = REPO / "analysis"
sys.path.insert(0, str(ANALYSIS))
sys.path.insert(0, str(Path(__file__).resolve().parent))

from course4_attack_type_live import AttackTypeLiveInferenceCore  # noqa: E402
from course4_attack_type_no_straight_live_input_provider import (  # noqa: E402
    ARTIFACT, CONTRACT_VERSION, FEATURE_ORDER, SPEC, AttackTypeNoStraightLiveInputProvider,
)
from course4_attack_type_live_input_provider import LEDGER, LEDGER_STATE, MANIFEST  # noqa: E402

CONTRACT = ANALYSIS / "output" / "course4_tenkai_no_straight_attack_type_live_input_contract_v1.json"
EXPECTED_IDENTITIES = {
    "contract": "72d67b35742b8b2d6e4632c0b3e96b071cbaf02e868323c76b14458809fdffc1",
    "artifact": "5d9cb206d2b439b13e9ee9c06b326dccdbcada3c2ecddea6a1c8752288946cb2",
    "spec": "faf382d38f63c2d443e37b8dd6219d7bd9785434f4aa0b7d995fb3700b4336c0",
    "ledger": "67c9fdd2ae1fe4a824a373d08600e029ffb45bf7badce858f339b20008be217d",
    "ledger_manifest": "af8910491981ff24260d4f23a890963febc85653b223cc6d2cdb535d60c9dcd6",
}


class AttackTypeNoStraightLiveInferenceCore(AttackTypeLiveInferenceCore):
    def __init__(self, provider=None, paths=None, expected_identities=None):
        variant_paths = {
            "contract": CONTRACT, "artifact": ARTIFACT, "spec": SPEC, "ledger": LEDGER,
            "ledger_manifest": MANIFEST, "ledger_state": LEDGER_STATE,
        }
        if paths:
            variant_paths.update(paths)
        super().__init__(
            provider=provider,
            paths=variant_paths,
            expected_identities=EXPECTED_IDENTITIES if expected_identities is None else expected_identities,
            feature_order=FEATURE_ORDER,
            contract_version=CONTRACT_VERSION,
            provider_factory=AttackTypeNoStraightLiveInputProvider,
            provider_source=ANALYSIS / "course4_attack_type_no_straight_live_input_provider.py",
            spec_feature_key="attack_type_feature_order",
            logic_source=Path(__file__),
        )


def predict(race_code, prediction_cutoff):
    return AttackTypeNoStraightLiveInferenceCore().predict(race_code, prediction_cutoff)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--race-code", required=True)
    parser.add_argument("--prediction-cutoff", required=True)
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args()
    print(json.dumps(predict(args.race_code, args.prediction_cutoff), ensure_ascii=False, sort_keys=True))
