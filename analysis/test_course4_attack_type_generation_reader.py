#!/usr/bin/env python3
"""TMPDIR-only integration fixture for Attack Type generation readers."""
from __future__ import annotations

import csv
import hashlib
import io
import json
import os
import sys
import tempfile
from datetime import date, datetime, timedelta, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "forecast"))
sys.path.insert(0, str(ROOT / "analysis"))
from build_course4_attack_history_canonical_ledger_phase9_1b import FIELDS
from course4_attack_ledger_generation_store import MODE_GENERATION, AttackTypeLedgerGenerationStore
from course4_attack_type_live_input_provider import AttackTypeLiveInputProvider, FEATURE_ORDER, canonical_json
from course4_attack_type_live import AttackTypeLiveInferenceCore, sha256


BASE_SHA = "b" * 64


def json_bytes(value):
    return (json.dumps(value, sort_keys=True, separators=(",", ":")) + "\n").encode()


def csv_bytes(rows):
    out = io.StringIO(newline="")
    writer = csv.DictWriter(out, fieldnames=FIELDS)
    writer.writeheader()
    writer.writerows(rows)
    return out.getvalue().encode()


def row(event_id, race_date, player, *, baseline=False, observed="", rank=1):
    return {"event_id": event_id, "race_code": event_id.split(":")[0], "race_date": race_date, "player_id": player,
            "actual_course": 4, "final_rank": rank, "raw_technique": "まくり", "normalized_technique": "MAKURI",
            "is_history_eligible": 1, "is_makuri": int(rank == 1), "is_makurizashi": 0,
            "source_kind": "baseline" if baseline else "prospective", "source_path": "synthetic", "source_sha256": BASE_SHA,
            "ledger_version": "v1", "baseline_frozen": int(baseline),
            "availability_kind": "RESEARCH_BASELINE_LOGICAL" if baseline else "OBSERVED_AT_INGESTION",
            "race_finalized_at": "", "first_observed_at": observed, "ingested_at": observed or "2026-10-01T00:00:00+00:00"}


def publish(root, generation_id, rows, moment):
    store = AttackTypeLedgerGenerationStore(root, now=lambda: moment)
    ledger = csv_bytes(rows)
    manifest = {"ledger_id": "synthetic", "ledger_version": "v1", "baseline_source_sha256": BASE_SHA,
                "ledger_sha256": hashlib.sha256(ledger).hexdigest(), "ledger_activation_at": moment.isoformat()}
    manifest_bytes = json_bytes(manifest)
    state = {"generation_id": generation_id, "ledger_version": "v1", "ledger_sha256": hashlib.sha256(ledger).hexdigest(),
             "manifest_sha256": hashlib.sha256(manifest_bytes).hexdigest(), "baseline_source_sha256": BASE_SHA}
    store.create_and_publish(generation_id, ledger_bytes=ledger, manifest_bytes=manifest_bytes, state_bytes=json_bytes(state))
    return store


def fake_files(temp):
    feature = list(FEATURE_ORDER)
    artifact = {"feature_order": feature, "class_order": ["NO_ATTACK_SUCCESS", "MAKURI_SUCCESS", "MAKURIZASHI_SUCCESS"],
                "reference_class": 0, "development_medians": [0.0] * 8, "development_means": [0.0] * 8,
                "development_stds": [1.0] * 8, "intercepts": [0.0, 0.0, 0.0], "coefficients": [[0.0] * 8] * 3}
    spec = {"final_feature_order": feature}
    artifact_path, spec_path, contract_path = temp / "artifact.json", temp / "spec.json", temp / "contract.json"
    artifact_path.write_text(json.dumps(artifact), encoding="utf-8")
    spec_path.write_text(json.dumps(spec), encoding="utf-8")
    contract = {"contract_version": "course4-tenkai-attack-type-live-input-v2", "feature_order": feature,
                "artifact": {"sha256": sha256(artifact_path)}, "spec": {"sha256": sha256(spec_path)}}
    contract_path.write_text(json.dumps(contract), encoding="utf-8")
    return {"artifact": artifact_path, "spec": spec_path}, {"contract": contract_path, "artifact": artifact_path, "spec": spec_path}, {"contract": sha256(contract_path), "artifact": sha256(artifact_path), "spec": sha256(spec_path)}


class SyntheticProvider(AttackTypeLiveInputProvider):
    """Exercises real context/history handling without DB or exhibition access."""
    def _live(self, race_code, cutoff, *, generation_context=None):
        history, audit = self._history("p4", date(2026, 10, 10), cutoff, "LIVE", generation_context=generation_context)
        if history is None:
            return self._response(race_code=race_code, cutoff=cutoff, mode="LIVE", available=False,
                                  reasons=[audit["reason"]], history=audit, generation_context=generation_context)
        features = [float(history["course4_history_n"]), history["course4_history_makuri_rate_pct"],
                    history["course4_history_makurizashi_rate_pct"], 0.1, 6.7, 35.0, 6.0, 7.0]
        return self._response(race_code=race_code, cutoff=cutoff, mode="LIVE", available=True, reasons=[],
                              features=features, history=audit, mapping={"synthetic": True},
                              raw_input={"synthetic": True}, generation_context=generation_context)


def core_for(root, files, core_paths, expected):
    provider = SyntheticProvider(ledger_mode=MODE_GENERATION, paths=files,
                                 expected_hashes={"artifact": expected["artifact"], "spec": expected["spec"]})
    return AttackTypeLiveInferenceCore(provider=provider, paths=core_paths, expected_identities=expected,
                                       ledger_mode=MODE_GENERATION, generation_root=root)


def expect_unavailable(result):
    assert not result["prediction_available"], result


def run():
    checks = {}
    with tempfile.TemporaryDirectory(prefix="attack-type-generation-reader-") as directory:
        temp = Path(directory)
        files, core_paths, expected = fake_files(temp)
        t1 = datetime(2026, 10, 5, tzinfo=timezone.utc)
        baseline = row("2026093001:p4", "2026-09-30", "p4", baseline=True)
        observed_before = row("2026100501:p4", "2026-10-05", "p4", observed="2026-10-05T00:00:00+00:00")
        observed_after = row("2026100901:p4", "2026-10-09", "p4", observed="2026-10-12T00:00:00+00:00")
        same_day = row("2026101001:p4", "2026-10-10", "p4", observed="2026-10-05T00:00:00+00:00")
        root = temp / "root"
        store = publish(root, "g-one", [baseline, observed_before, observed_after, same_day], t1)
        cutoff = t1 + timedelta(days=2)
        core = core_for(root, files, core_paths, expected)
        normal = core.predict("synthetic", cutoff.isoformat())
        checks["normal_generation_prediction"] = normal["prediction_available"]
        checks["core_provider_ledger_sha"] = normal["ledger_sha256"] == normal["input_ledger_identity"]["sha256"]
        checks["core_provider_manifest_sha"] = normal["ledger_manifest_sha256"] == normal["input_ledger_identity"]["manifest_sha256"]
        checks["feature_order_unchanged"] = len(normal["input_feature_vector"]) == len(FEATURE_ORDER)
        checks["input_provenance_same_generation"] = normal["ledger_generation"]["generation_id"] == normal["input_sha256"][:0] + "g-one"
        checks["first_observed_after_cutoff_excluded"] = normal["input_feature_vector"][0] == 2.0
        # The target-day event is excluded by the shared canonical history rule.
        checks["same_day_history_excluded"] = normal["input_feature_vector"][0] == 2.0

        # A provider switches CURRENT after it receives g-one's context; the same request remains g-one.
        class SwitchingProvider(SyntheticProvider):
            switched = False
            def _live(self, race_code, cutoff, *, generation_context=None):
                if not self.switched:
                    self.switched = True
                    publish(root, "g-two", [baseline, observed_before], t1 + timedelta(days=3))
                return super()._live(race_code, cutoff, generation_context=generation_context)
        switching = SwitchingProvider(ledger_mode=MODE_GENERATION, paths=files, expected_hashes={"artifact": expected["artifact"], "spec": expected["spec"]})
        core_switch = AttackTypeLiveInferenceCore(provider=switching, paths=core_paths, expected_identities=expected, ledger_mode=MODE_GENERATION, generation_root=root)
        before_switch = core_switch.predict("synthetic", cutoff.isoformat())
        checks["request_pins_context_during_current_switch"] = before_switch["ledger_generation"]["generation_id"] == "g-one"
        after_switch = core_switch.predict("synthetic", (t1 + timedelta(days=4)).isoformat())
        checks["next_request_uses_new_current"] = after_switch["ledger_generation"]["generation_id"] == "g-two"

        missing = core_for(temp / "missing", files, core_paths, expected).predict("x", cutoff.isoformat())
        checks["missing_current_fail_closed"] = not missing["prediction_available"]
        corrupt_root = temp / "corrupt"; corrupt_root.mkdir(); (corrupt_root / "CURRENT.json").write_text("{", encoding="utf-8")
        checks["corrupt_current_fail_closed"] = not core_for(corrupt_root, files, core_paths, expected).predict("x", cutoff.isoformat())["prediction_available"]
        receipt_root = temp / "receipt"; receipt_store = publish(receipt_root, "g-receipt", [baseline], t1); os.unlink(next((receipt_root / "activations").glob("*.json")))
        checks["missing_receipt_fail_closed"] = not core_for(receipt_root, files, core_paths, expected).predict("x", cutoff.isoformat())["prediction_available"]
        descriptor_root = temp / "descriptor"; publish(descriptor_root, "g-desc", [baseline], t1); desc = descriptor_root / "generations" / "g-desc" / "generation.json"; data = json.loads(desc.read_text()); data["generation_id"] = "g-other"; desc.write_text(json.dumps(data), encoding="utf-8")
        checks["descriptor_mismatch_fail_closed"] = not core_for(descriptor_root, files, core_paths, expected).predict("x", cutoff.isoformat())["prediction_available"]
        ledger_root = temp / "ledger"; publish(ledger_root, "g-ledger", [baseline], t1); (ledger_root / "generations" / "g-ledger" / "ledger.csv").write_text("bad\n", encoding="utf-8")
        checks["ledger_sha_mismatch_fail_closed"] = not core_for(ledger_root, files, core_paths, expected).predict("x", cutoff.isoformat())["prediction_available"]
        checks["activation_cutoff_rejected"] = not core_for(root, files, core_paths, expected).predict("x", (t1 - timedelta(microseconds=1)).isoformat())["prediction_available"]
        checks["baseline_activation_condition"] = not core_for(root, files, core_paths, expected).predict("x", (t1 - timedelta(microseconds=1)).isoformat())["prediction_available"]
        checks["legacy_fallback_prohibited"] = not missing["prediction_available"] and missing["ledger_sha256"] is None

        # Legacy replay remains a separate legacy-only contract; no generation root is used.
        class LegacyReplayProvider:
            def build(self, race_code, cutoff_value, mode, *_args, **_kwargs):
                return {"prediction_available": True, "feature_order": list(FEATURE_ORDER), "feature_vector": [1.0, 2.0, 3.0, .1, 6.7, 35.0, 6.0, 7.0], "input_sha256": "legacy", "missing_reason": [], "blocked_reason": []}
        legacy_ledger = temp / "legacy.csv"; legacy_ledger.write_text("x\n", encoding="utf-8")
        legacy_manifest = temp / "legacy-manifest.json"; legacy_manifest.write_text(json.dumps({"ledger_sha256": sha256(legacy_ledger), "ledger_version": "v1", "baseline_source_sha256": BASE_SHA}), encoding="utf-8")
        legacy_paths = {**core_paths, "ledger": legacy_ledger, "ledger_manifest": legacy_manifest, "ledger_state": temp / "absent-state.json"}
        legacy_contract = json.loads(core_paths["contract"].read_text(encoding="utf-8"))
        legacy_contract["ledger"] = {"sha256": sha256(legacy_ledger), "manifest_sha256": sha256(legacy_manifest)}
        core_paths["contract"].write_text(json.dumps(legacy_contract), encoding="utf-8")
        legacy_expected = {**expected, "contract": sha256(core_paths["contract"]), "ledger": sha256(legacy_ledger), "ledger_manifest": sha256(legacy_manifest)}
        legacy = AttackTypeLiveInferenceCore(provider=LegacyReplayProvider(), paths=legacy_paths, expected_identities=legacy_expected)
        checks["research_replay_legacy_compatible"] = legacy.predict_research_replay("legacy", cutoff.isoformat())["prediction_available"]

    assert len(checks) == 18, checks
    assert all(checks.values()), checks
    print(json.dumps({"pass": True, "case_count": len(checks), "checks": checks}, sort_keys=True))


if __name__ == "__main__":
    run()
