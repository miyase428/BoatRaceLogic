#!/usr/bin/env python3
"""Focused automatic safety tests for the Phase 9-1d inference core."""
from __future__ import annotations

import copy
import json
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "forecast"))
from course4_attack_type_live import (  # noqa: E402
    ARTIFACT, CONTRACT, EXPECTED_IDENTITIES, FEATURE_ORDER, LEDGER, MANIFEST, SPEC,
    AttackTypeLiveInferenceCore, sha256,
)


class FakeProvider:
    def __init__(self, response): self.response = response
    def build(self, *_args, **_kwargs): return copy.deepcopy(self.response)


class PostCutoffFixtureProvider:
    """Represents a source where a later exhibition revision is ignored."""
    def __init__(self): self.post_cutoff_revision_present = False
    def build(self, *_args, **_kwargs):
        response = base_provider_response()
        response["raw_input_payload"] = {"post_cutoff_revision_present": self.post_cutoff_revision_present}
        # The explicit historical cutoff always selects the same pre-cutoff row.
        return response


def base_provider_response(features=None):
    return {
        "prediction_available": True, "feature_order": list(FEATURE_ORDER),
        "feature_vector": features if features is not None else [12.0, 5.0, 2.0, 0.12, 6.8, 35.0, 6.0, 7.0],
        "input_sha256": "fixture-input", "missing_reason": [], "blocked_reason": [],
    }


def copied_paths(temp: Path):
    names = {"contract": CONTRACT, "artifact": ARTIFACT, "spec": SPEC, "ledger": LEDGER, "ledger_manifest": MANIFEST}
    out = {}
    for key, source in names.items():
        target = temp / source.name; target.write_bytes(source.read_bytes()); out[key] = target
    return out


def no_generated(value):
    value = dict(value); value.pop("generated_at", None); return value


def main():
    checks = {}
    normal = AttackTypeLiveInferenceCore(provider=FakeProvider(base_provider_response()))
    ok = normal._predict_mode("fixture", "2026-10-08T12:00:00+09:00", "LIVE")
    checks["valid_wakunari"] = ok["prediction_available"]
    checks["probability_sum"] = abs(sum(ok[k] for k in ("p_no_attack_success", "p_makuri_success", "p_makurizashi_success")) - 1.0) <= 1e-12
    again = normal._predict_mode("fixture", "2026-10-08T12:00:00+09:00", "LIVE")
    checks["deterministic_repeat"] = no_generated(ok) == no_generated(again)
    historical = PostCutoffFixtureProvider()
    before_update = AttackTypeLiveInferenceCore(provider=historical).predict("fixture", "2026-10-08T12:00:00+09:00")
    historical.post_cutoff_revision_present = True
    after_update = AttackTypeLiveInferenceCore(provider=historical).predict("fixture", "2026-10-08T12:00:00+09:00")
    checks["post_cutoff_update_does_not_change_prior_prediction"] = (
        no_generated(before_update) == no_generated(after_update)
    )
    for name, reason in (("non_wakunari", "NON_WAKUNARI"), ("missing_exhibition", "EXHIBITION_NOT_COMPLETE"), ("post_cutoff_exhibition_ignored", "EXHIBITION_AFTER_CUTOFF"), ("ledger_availability_cutoff", "LEDGER_NOT_AVAILABLE_AT_CUTOFF")):
        blocked = base_provider_response(); blocked.update({"prediction_available": False, "missing_reason": [reason], "blocked_reason": [reason]})
        result = AttackTypeLiveInferenceCore(provider=FakeProvider(blocked)).predict("fixture", "2026-10-08T12:00:00+09:00")
        checks[name] = not result["prediction_available"] and result["missing_reason"] == [reason]
    nonfinite = AttackTypeLiveInferenceCore(provider=FakeProvider(base_provider_response([12, 5, 2, None, 6.8, 35, 6, 7]))).predict("fixture", "2026-10-08T12:00:00+09:00")
    checks["live_missing_never_median_filled"] = nonfinite["missing_reason"] == ["INFERENCE_NONFINITE_INPUT"]
    with tempfile.TemporaryDirectory() as directory:
        paths = copied_paths(Path(directory))
        paths["artifact"].write_text("{}", encoding="utf-8")
        checks["artifact_hash_mismatch"] = AttackTypeLiveInferenceCore(paths=paths, provider=FakeProvider(base_provider_response())).load_error == "ARTIFACT_HASH_MISMATCH"
    with tempfile.TemporaryDirectory() as directory:
        paths = copied_paths(Path(directory))
        paths["contract"].write_text("{}", encoding="utf-8")
        checks["contract_hash_mismatch"] = AttackTypeLiveInferenceCore(paths=paths, provider=FakeProvider(base_provider_response())).load_error == "INPUT_CONTRACT_VERSION_MISMATCH"
    with tempfile.TemporaryDirectory() as directory:
        paths = copied_paths(Path(directory))
        contract = json.loads(paths["contract"].read_text(encoding="utf-8")); contract["feature_order"] = list(reversed(FEATURE_ORDER)); paths["contract"].write_text(json.dumps(contract), encoding="utf-8")
        expected = dict(EXPECTED_IDENTITIES); expected["contract"] = sha256(paths["contract"])
        checks["feature_order_mismatch"] = AttackTypeLiveInferenceCore(paths=paths, expected_identities=expected, provider=FakeProvider(base_provider_response())).load_error == "INFERENCE_FEATURE_ORDER_MISMATCH"
    source = (ROOT / "analysis" / "course4_attack_type_live_input_provider.py").read_text(encoding="utf-8")
    core_source = (ROOT / "forecast" / "course4_attack_type_live.py").read_text(encoding="utf-8")
    checks["result_table_access_zero"] = "race_result_detail" not in source and "race_payouts" not in source and "race_result_detail" not in core_source and "race_payouts" not in core_source
    if not all(checks.values()):
        raise SystemExit(json.dumps(checks, ensure_ascii=False))
    print(json.dumps({"pass": True, "checks": checks}, ensure_ascii=False, sort_keys=True))


if __name__ == "__main__": main()
