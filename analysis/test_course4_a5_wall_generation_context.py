#!/usr/bin/env python3
"""TMPDIR-only fixture for A5/Wall generation-context isolation."""
from __future__ import annotations

import csv
import hashlib
import io
import json
import sys
import tempfile
from datetime import datetime, timedelta, timezone
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parent.parent
sys.path[:0] = [str(ROOT / "analysis"), str(ROOT / "forecast")]
from build_course4_attack_history_canonical_ledger_phase9_1b import FIELDS
from course4_attack_ledger_generation_store import MODE_GENERATION, AttackTypeLedgerGenerationStore
from course4_tenkai_canonical_input import CANONICAL_VERSION, SNAPSHOT_VERSION
import course4_attack_a5_live_input_provider as a5_mod
import course34_wall_live_input_provider as wall_mod

BASE_SHA = "b" * 64
A5_FEATURES = ("course4_history_n", "course4_history_win_rate_pct", "course4_history_top2_rate_pct", "course4_history_top3_rate_pct", "course4_history_makuri_rate_pct", "course4_history_makurizashi_rate_pct", "course4_history_attack_rate_pct", "smoothed_course4_st", "course4_average_st_rank", "course4_entry_count", "course4_ex_st", "course4_exhibition_time", "course4_lap_time", "course4_around_time", "course4_straight_time", "motor_2ren_rate", "boat_2ren_rate", "national_win_rate", "local_win_rate", "national_2ren_rate", "local_2ren_rate")
WALL_FEATURES = ("smoothed_avg_st_delta_34", "ex_st_delta_34", "avg_st_rank_delta_34", "st_profile_reliability_advantage_34", "exhibition_time_delta_34", "lap_time_delta_34", "around_time_delta_34", "straight_time_delta_34")


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def csv_bytes(rows):
    out = io.StringIO(newline="")
    writer = csv.DictWriter(out, fieldnames=FIELDS)
    writer.writeheader(); writer.writerows(rows)
    return out.getvalue().encode()


def row(event, day, player, *, baseline=False, observed="", rank=1):
    return {"event_id": event, "race_code": event.split(":")[0], "race_date": day, "player_id": player, "actual_course": 4,
            "final_rank": rank, "raw_technique": "", "normalized_technique": "", "is_history_eligible": 1,
            "is_makuri": int(rank == 1), "is_makurizashi": 0, "source_kind": "baseline" if baseline else "prospective",
            "source_path": "synthetic", "source_sha256": BASE_SHA, "ledger_version": "v1", "baseline_frozen": int(baseline),
            "availability_kind": "RESEARCH_BASELINE_LOGICAL" if baseline else "OBSERVED_AT_INGESTION", "race_finalized_at": "",
            "first_observed_at": observed, "ingested_at": observed or "2026-10-01T00:00:00+00:00"}


def publish(root, generation, rows, moment):
    store = AttackTypeLedgerGenerationStore(root, now=lambda: moment)
    ledger = csv_bytes(rows)
    manifest = {"ledger_id": "synthetic", "ledger_version": "v1", "baseline_source_sha256": BASE_SHA,
                "ledger_sha256": hashlib.sha256(ledger).hexdigest()}
    manifest_bytes = (json.dumps(manifest, sort_keys=True) + "\n").encode()
    state = {"generation_id": generation, "ledger_version": "v1", "ledger_sha256": hashlib.sha256(ledger).hexdigest(),
             "manifest_sha256": hashlib.sha256(manifest_bytes).hexdigest(), "baseline_source_sha256": BASE_SHA}
    store.create_and_publish(generation, ledger_bytes=ledger, manifest_bytes=manifest_bytes,
                             state_bytes=(json.dumps(state, sort_keys=True) + "\n").encode())
    return store


class SnapshotStore:
    def __init__(self, snapshot): self.snapshot = snapshot
    def read(self, _race): return self.snapshot
    def path(self, race): return Path("/synthetic") / f"{race}.json"


class Exhibition:
    def __init__(self, *, mismatch=False, available=True):
        self.mismatch, self.available, self.contexts = mismatch, available, []
    def build(self, _race, _cutoff, _mode, **kwargs):
        context = kwargs.get("generation_context"); self.contexts.append(context)
        if not self.available:
            return {"prediction_available": False, "missing_reason": ["EXHIBITION_AFTER_CUTOFF"], "ledger_identity": {}}
        identity = {"generation_id": "bad" if self.mismatch else context.generation_id, "sha256": context.ledger_sha256,
                    "manifest_sha256": context.manifest_sha256} if context else {}
        rows = []
        for course, player in ((1, "p1"), (2, "p2"), (3, "p3"), (4, "p4"), (5, "p5"), (6, "p6")):
            rows.append({"entry_course": course, "player_id": player, "course4_ex_st": .1 * course,
                         "course4_exhibition_time": 6.5 + course, "course4_lap_time": 35.0 + course,
                         "course4_around_time": 6.0 + course, "course4_straight_time": 7.0 + course})
        return {"prediction_available": True, "raw_input": {"exhibition_rows": rows}, "entry_mapping": {"entry_courses": [1,2,3,4,5,6]}, "ledger_identity": identity}


def artifacts(temp, module, features, prefix):
    artifact = temp / f"{prefix}-artifact.json"; baseline = temp / f"{prefix}-baseline.csv"; manifest = temp / f"{prefix}-manifest.json"
    artifact.write_text(json.dumps({"feature_names": list(features)}), encoding="utf-8")
    if prefix == "a5":
        fields = ["race_code", "race_date", "course4_player_id", "source_kind", "baseline_frozen"] + ["raw_" + name for name in ("course4_average_st", "overall_average_st", "course4_entry_count", "course4_average_st_rank", "course4_ex_st", "course4_exhibition_time", "course4_lap_time", "course4_around_time", "course4_straight_time", "motor_2ren_rate", "boat_2ren_rate", "national_win_rate", "local_win_rate", "national_2ren_rate", "local_2ren_rate")]
        record = {name: "1" for name in fields}; record.update({"race_code": "REPLAY", "race_date": "2026-10-10", "course4_player_id": "p4", "source_kind": "frozen", "baseline_frozen": "1"})
    else:
        fields = ["race_code", "source_kind", "baseline_frozen", "course3_player_id", "course4_player_id"] + ["feature_" + name for name in features]
        record = {name: "1" for name in fields}; record.update({"race_code": "REPLAY", "source_kind": "frozen", "baseline_frozen": "1", "course3_player_id": "p3", "course4_player_id": "p4"})
    with baseline.open("w", newline="", encoding="utf-8") as handle:
        writer = csv.DictWriter(handle, fieldnames=fields); writer.writeheader(); writer.writerow(record)
    manifest.write_text(json.dumps({"artifact_sha256": digest(artifact), "baseline_sha256": digest(baseline), "canonical_version": CANONICAL_VERSION, "feature_order": list(features)}), encoding="utf-8")
    return artifact, baseline, manifest


def snapshot():
    common = {"overall_average_st": 0.2, "course_average_st": 0.15, "course_entry_count": 30, "course_average_rank": 2,
              "motor_2ren_rate": 30, "boat_2ren_rate": 31, "national_win_rate": 6, "local_win_rate": 5, "national_2ren_rate": 40, "local_2ren_rate": 38}
    return {"snapshot_version": SNAPSHOT_VERSION, "canonical_version": CANONICAL_VERSION, "captured_at": "2026-10-08T00:00:00+00:00", "race_date": "2026-10-10", "snapshot_sha256": "s" * 64,
            "lanes": {"3": {**common, "player_id": "p3"}, "4": {**common, "player_id": "p4"}}}


def run():
    checks = {}
    with tempfile.TemporaryDirectory(prefix="a5-wall-generation-") as directory:
        temp = Path(directory); a_art, a_base, a_manifest = artifacts(temp, a5_mod, A5_FEATURES, "a5"); w_art, w_base, w_manifest = artifacts(temp, wall_mod, WALL_FEATURES, "wall")
        legacy_ledger = temp / "legacy.csv"; legacy_ledger.write_bytes(csv_bytes([row("b:p4", "2026-09-20", "p4", baseline=True)]))
        legacy_manifest = temp / "legacy-ledger.json"; legacy_manifest.write_text(json.dumps({"ledger_activation_at": "2026-10-01T00:00:00+00:00"}), encoding="utf-8")
        t0 = datetime(2026, 10, 5, tzinfo=timezone.utc)
        rows = [row("b:p4", "2026-09-20", "p4", baseline=True), row("before:p4", "2026-10-01", "p4", observed="2026-10-06T00:00:00+00:00"), row("after:p4", "2026-10-02", "p4", observed="2026-10-12T00:00:00+00:00"), row("same:p4", "2026-10-10", "p4", observed="2026-10-06T00:00:00+00:00")]
        root = temp / "root"; publish(root, "g-one", rows, t0)
        cutoff = (t0 + timedelta(days=4)).isoformat()
        patches = [patch.object(a5_mod, "REPO", temp), patch.object(a5_mod, "ARTIFACT", a_art), patch.object(a5_mod, "BASELINE", a_base), patch.object(a5_mod, "MANIFEST", a_manifest), patch.object(a5_mod, "LEDGER", legacy_ledger), patch.object(a5_mod, "LEDGER_MANIFEST", legacy_manifest), patch.object(a5_mod, "EXPECTED_ARTIFACT_SHA256", digest(a_art)), patch.object(wall_mod, "REPO", temp), patch.object(wall_mod, "ARTIFACT", w_art), patch.object(wall_mod, "BASELINE", w_base), patch.object(wall_mod, "MANIFEST", w_manifest), patch.object(wall_mod, "EXPECTED_ARTIFACT_SHA256", digest(w_art))]
        with patches[0], patches[1], patches[2], patches[3], patches[4], patches[5], patches[6], patches[7], patches[8], patches[9], patches[10], patches[11]:
            legacy = a5_mod.AttackA5LiveInputProvider(snapshot_store=SnapshotStore(snapshot()), exhibition_provider=Exhibition())
            checks["a5_legacy_research_compatible"] = legacy.build("REPLAY", cutoff, "RESEARCH_REPLAY")["feature_available"]
            checks["a5_legacy_call_contract"] = legacy.build("x", cutoff, "LIVE")["feature_available"]
            ex = Exhibition(); gen = a5_mod.AttackA5LiveInputProvider(snapshot_store=SnapshotStore(snapshot()), exhibition_provider=ex, ledger_mode=MODE_GENERATION, generation_root=root)
            normal = gen.build("x", cutoff, "LIVE")
            checks["a5_generation_live"] = normal["feature_available"]
            checks["a5_before_cutoff_included"] = normal["feature_vector"][0] == 2.0
            checks["a5_after_cutoff_excluded"] = normal["feature_vector"][0] == 2.0
            checks["a5_same_day_excluded"] = normal["feature_vector"][0] == 2.0
            checks["a5_seven_history_order"] = normal["feature_vector"][:7] == [2.0, 100.0, 100.0, 100.0, 100.0, 0.0, 100.0]
            checks["a5_context_to_exhibition"] = ex.contexts[0].generation_id == normal["ledger_identity"]["generation_id"]
            checks["a5_sha_matches_exhibition"] = ex.contexts[0].ledger_sha256 == normal["ledger_identity"]["sha256"]
            publish(root, "g-two", rows[:2], t0 + timedelta(days=5))
            checks["a5_context_pinned_through_current_switch"] = normal["ledger_identity"]["generation_id"] == "g-one" and ex.contexts[0].generation_id == "g-one"
            checks["a5_missing_current_no_fallback"] = not a5_mod.AttackA5LiveInputProvider(snapshot_store=SnapshotStore(snapshot()), exhibition_provider=Exhibition(), ledger_mode=MODE_GENERATION, generation_root=temp / "missing").build("x", cutoff, "LIVE")["feature_available"]
            checks["a5_context_mismatch_rejected"] = not a5_mod.AttackA5LiveInputProvider(snapshot_store=SnapshotStore(snapshot()), exhibition_provider=Exhibition(mismatch=True), ledger_mode=MODE_GENERATION, generation_root=root).build("x", cutoff, "LIVE")["feature_available"]
            checks["a5_post_cutoff_exhibit_rejected"] = not a5_mod.AttackA5LiveInputProvider(snapshot_store=SnapshotStore(snapshot()), exhibition_provider=Exhibition(available=False), ledger_mode=MODE_GENERATION, generation_root=root).build("x", cutoff, "LIVE")["feature_available"]
            bad_root = temp / "bad"; publish(bad_root, "g-bad", [row("bad:p4", "2026-10-01", "p4", observed="no-zone")], t0)
            checks["a5_bad_observation_fail_closed"] = not a5_mod.AttackA5LiveInputProvider(snapshot_store=SnapshotStore(snapshot()), exhibition_provider=Exhibition(), ledger_mode=MODE_GENERATION, generation_root=bad_root).build("x", cutoff, "LIVE")["feature_available"]
            checks["a5_activation_condition"] = not gen.build("x", (t0 - timedelta(seconds=1)).isoformat(), "LIVE")["feature_available"]
            wall_legacy = wall_mod.WallRLiveInputProvider(snapshot_store=SnapshotStore(snapshot()), exhibition_provider=Exhibition())
            checks["wall_legacy_research_compatible"] = wall_legacy.build("REPLAY", cutoff, "RESEARCH_REPLAY")["feature_available"]
            checks["wall_legacy_call_contract"] = wall_legacy.build("x", cutoff, "LIVE")["feature_available"]
            wall_ex = Exhibition(); wall = wall_mod.WallRLiveInputProvider(snapshot_store=SnapshotStore(snapshot()), exhibition_provider=wall_ex, ledger_mode=MODE_GENERATION, generation_root=root)
            wall_result = wall.build("x", (t0 + timedelta(days=6)).isoformat(), "LIVE")
            checks["wall_generation_explicit_context"] = wall_result["feature_available"] and wall_ex.contexts[0].generation_id == wall_result["ledger_identity"]["generation_id"]
            checks["wall_course34_mapping"] = wall_result["entry_mapping"]["entry_courses"] == [1,2,3,4,5,6]
            checks["wall_eight_feature_order"] = len(wall_result["feature_vector"]) == 8
            checks["wall_upstream_fail_closed"] = not wall_mod.WallRLiveInputProvider(snapshot_store=SnapshotStore(snapshot()), exhibition_provider=Exhibition(available=False), ledger_mode=MODE_GENERATION, generation_root=root).build("x", cutoff, "LIVE")["feature_available"]
            checks["wall_generation_mismatch_rejected"] = not wall_mod.WallRLiveInputProvider(snapshot_store=SnapshotStore(snapshot()), exhibition_provider=Exhibition(mismatch=True), ledger_mode=MODE_GENERATION, generation_root=root).build("x", cutoff, "LIVE")["feature_available"]
    assert len(checks) == 22, checks
    assert all(checks.values()), checks
    print(json.dumps({"pass": True, "case_count": len(checks), "checks": checks}, sort_keys=True))


if __name__ == "__main__":
    run()
