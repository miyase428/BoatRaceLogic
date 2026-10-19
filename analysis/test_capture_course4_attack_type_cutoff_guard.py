#!/usr/bin/env python3
"""Synthetic regression coverage for Attack Type STRICT_FORWARD deadline guards.

The test replaces the live inference module before importing the capture module.
It uses no DB, HTTP, model artifact, deadline cache, or production snapshot path.
"""
from __future__ import annotations

import hashlib
import importlib.util
import json
import os
import sys
import tempfile
import types
from datetime import datetime
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent


def canonical_json(value):
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"), allow_nan=False)


def sha256(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()


stub = types.ModuleType("course4_attack_type_live")
stub.canonical_json = canonical_json
stub.sha256 = sha256
stub.AttackTypeLiveInferenceCore = object
sys.modules[stub.__name__] = stub
spec = importlib.util.spec_from_file_location("attack_type_capture_under_test", REPO / "forecast" / "capture_course4_attack_type_snapshot.py")
module = importlib.util.module_from_spec(spec)
assert spec.loader is not None
spec.loader.exec_module(module)
module.git_head = lambda: "synthetic-test"


class FakeCore:
    logic_version = "logic-v1"
    load_error = None
    artifact = {"feature_order": ["f1", "f2", "f3", "f4", "f5", "f6", "f7", "f8"]}

    def predict(self, race_code, cutoff):
        return {
            "prediction_available": True,
            "race_code": race_code,
            "prediction_cutoff": cutoff,
            "generated_at": cutoff,
            "stage": "POST_EXHIBITION",
            "logic_version": self.logic_version,
            "artifact_sha256": "artifact",
            "spec_sha256": "spec",
            "ledger_sha256": "ledger",
            "ledger_manifest_sha256": "manifest",
            "input_contract_version": "contract-v1",
            "input_contract_sha256": "contract",
            "input_sha256": "input",
            "input_feature_vector": [0.0] * 8,
            "p_no_attack_success": 0.1,
            "p_makuri_success": 0.2,
            "p_makurizashi_success": 0.7,
        }


class FakeGuard:
    def __init__(self, deadlines, deadline_dir, existing_results=None, result_error=None, deadline_sequences=None):
        self.deadlines = deadlines
        self.deadline_dir = deadline_dir
        self.existing_results = set(existing_results or ())
        self.result_error = result_error
        self.deadline_sequences = {race_code: list(values) for race_code, values in (deadline_sequences or {}).items()}

    def deadline(self, race_code):
        sequence = self.deadline_sequences.get(race_code)
        if sequence:
            return sequence.pop(0)
        return self.deadlines.get(race_code)

    def result_exists(self, race_code):
        if self.result_error is not None:
            raise self.result_error
        return race_code in self.existing_results


class Clock:
    def __init__(self, *values):
        self.values = list(values)
        self.index = 0

    def __call__(self):
        value = self.values[min(self.index, len(self.values) - 1)]
        self.index += 1
        return value


def timestamp(value):
    return datetime.fromisoformat(value)


def assert_blocked(result, reason):
    assert result["status"] == "BLOCKED", result
    assert result["blocked_reason"] == [reason], result


def capture(state, name, race_code, deadline, clock_values, cutoff, store=None, deadlines=None, deadline_dir=None, existing_results=None, result_error=None, deadline_sequences=None):
    store = store or module.ForwardSnapshotStore(state / name)
    guard = FakeGuard(
        deadlines if deadlines is not None else {race_code: deadline},
        deadline_dir or state / "deadlines",
        existing_results=existing_results,
        result_error=result_error,
        deadline_sequences=deadline_sequences,
    )
    instance = module.AttackTypeSnapshotCapture(FakeCore(), store, guard, Clock(*clock_values))
    return instance.capture(race_code, cutoff), store


def main():
    with tempfile.TemporaryDirectory(prefix="attack-type-cutoff-guard-") as temp:
        state = Path(temp)
        module.CAMPAIGN = state / "campaign.json"
        before = "2026-10-19T11:59:00+09:00"
        after = "2026-10-19T12:00:01+09:00"
        deadline = timestamp("2026-10-19T12:00:00+09:00")
        cutoff = "2026-10-19T11:58:00+09:00"
        race = "20261019A01"

        # 1. Both checks before deadline: immutable first write succeeds.
        saved, store = capture(state, "case1", race, deadline, [before, before], cutoff)
        assert saved["status"] == "SAVED", saved
        payload_path = Path(saved["path"])
        original_payload = json.loads(payload_path.read_text(encoding="utf-8"))
        assert original_payload["prediction_cutoff"] == cutoff
        assert original_payload["input_feature_vector"] == [0.0] * 8

        # 2. Start before deadline, pre-write check after deadline: no snapshot.
        crossed, _ = capture(state, "case2", race, deadline, [before, after], cutoff)
        assert_blocked(crossed, "DEADLINE_PASSED")
        assert not list((state / "case2" / "strict_forward").glob("*.json"))

        # 3. Deadline unavailable: fail closed.
        missing, _ = capture(state, "case3", race, None, [before], cutoff, deadlines={})
        assert_blocked(missing, "OFFICIAL_DEADLINE_UNAVAILABLE")

        # 4. Existing snapshot stays byte-for-byte unchanged.
        existing, _ = capture(state, "case1", race, deadline, [before], cutoff, store=store)
        assert existing["status"] == "ALREADY_EXISTS", existing
        assert json.loads(payload_path.read_text(encoding="utf-8")) == original_payload

        # 5/6. Race-level checks remain independent under a shared batch cutoff.
        early_race, late_race = "20261019A02", "20261019A03"
        deadlines = {
            early_race: timestamp("2026-10-19T12:05:00+09:00"),
            late_race: deadline,
        }
        shared_store = module.ForwardSnapshotStore(state / "case5")
        early, _ = capture(state, "case5", early_race, deadlines[early_race], ["2026-10-19T12:01:00+09:00"] * 2, cutoff, store=shared_store, deadlines=deadlines)
        late, _ = capture(state, "case5", late_race, deadlines[late_race], ["2026-10-19T12:01:00+09:00"], cutoff, store=shared_store, deadlines=deadlines)
        assert early["status"] == "SAVED", early
        assert_blocked(late, "DEADLINE_PASSED")

        # 7. Exactly at the deadline is rejected.
        boundary, _ = capture(state, "case7", race, deadline, [deadline.isoformat()], cutoff)
        assert_blocked(boundary, "DEADLINE_PASSED")

        # 8. A future input cutoff is never accepted as a forward capture.
        future, _ = capture(state, "case8", race, deadline, [before], "2026-10-19T11:59:30+09:00")
        assert_blocked(future, "PREDICTION_CUTOFF_IN_FUTURE")

        # 9. An invalid current time fails closed.
        bad_clock, _ = capture(state, "case9", race, deadline, ["invalid-time"], cutoff)
        assert_blocked(bad_clock, "CAPTURE_TIME_UNAVAILABLE")

        # 10. capture_date preserves one input cutoff but guards every race independently.
        batch_race1, batch_race2 = "20261019B01", "20261019B02"
        batch_deadlines = {
            batch_race1: timestamp("2026-10-19T12:10:00+09:00"),
            batch_race2: timestamp("2026-10-19T12:00:00+09:00"),
        }
        deadline_dir = state / "batch-deadlines"
        deadline_dir.mkdir()
        (deadline_dir / "deadlines_20261019.json").write_text(json.dumps({"deadlines": {batch_race1: "12:10", batch_race2: "12:00"}}), encoding="utf-8")
        batch = module.AttackTypeSnapshotCapture(
            FakeCore(),
            module.ForwardSnapshotStore(state / "case10"),
            FakeGuard(batch_deadlines, deadline_dir),
            Clock("2026-10-19T12:01:00+09:00", "2026-10-19T12:01:00+09:00", "2026-10-19T12:01:00+09:00"),
        )
        result = batch.capture_date("2026-10-19", cutoff)
        assert result["prediction_cutoff"] == cutoff
        assert result["summary"]["SAVED"] == 1, result
        assert result["summary"]["BLOCKED"] == 1, result
        assert all(str(path.resolve()).startswith(str(state.resolve())) for path in state.rglob("*"))

        # 11. A persisted race result blocks a new STRICT snapshot before inference.
        result_exists, _ = capture(
            state, "case11", race, deadline, [before], cutoff,
            existing_results={race},
        )
        assert_blocked(result_exists, "RESULT_ALREADY_EXISTS")
        assert not list((state / "case11" / "strict_forward").glob("*.json"))

        # 12. Failure to check results fails closed before inference and leaves no snapshot.
        error_store = module.ForwardSnapshotStore(state / "case12")
        try:
            capture(
                state, "case12", race, deadline, [before], cutoff,
                store=error_store,
                result_error=RuntimeError("synthetic-result-lookup-failure"),
            )
            raise AssertionError("result lookup failure must not continue to inference")
        except RuntimeError as error:
            assert str(error) == "synthetic-result-lookup-failure", error
        assert not list((state / "case12" / "strict_forward").glob("*.json"))

        # 13. A deadline that moves earlier between the two checks blocks the first write.
        changed_deadline, _ = capture(
            state, "case13", race, deadline, [before, before], cutoff,
            deadline_sequences={race: [timestamp("2026-10-19T12:10:00+09:00"), timestamp("2026-10-19T11:58:00+09:00")]},
        )
        assert_blocked(changed_deadline, "DEADLINE_PASSED")
        assert not list((state / "case13" / "strict_forward").glob("*.json"))

        # 14. A timezone-less capture clock is rejected instead of comparing naive times.
        timezone_missing, _ = capture(state, "case14", race, deadline, ["2026-10-19T11:59:00"], cutoff)
        assert_blocked(timezone_missing, "CAPTURE_TIME_UNAVAILABLE")
        assert not list((state / "case14" / "strict_forward").glob("*.json"))

    print("PASS: 14 synthetic cutoff-guard cases")


if __name__ == "__main__":
    main()
