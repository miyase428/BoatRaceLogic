#!/usr/bin/env python3
from __future__ import annotations

import json
import tempfile
from datetime import datetime
from pathlib import Path
from zoneinfo import ZoneInfo

import sys
ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "analysis"))
sys.path.insert(0, str(ROOT / "forecast"))
from course4_tenkai_canonical_input import StaticSnapshotStore  # noqa: E402
from tenkai_ai_postrace_reference import PostRaceReference, ReferenceDataLoader  # noqa: E402


class Loader:
    def __init__(self, records): self.records = records
    def load(self, race_code, analysis_at):
        return {"records": self.records, "audit": {"source": "official_tamagawa", "fetched_at": "2026-10-23T11:04:05+00:00", "db_saved_at": "2026-10-23T11:04:06+00:00"}}, None


pid4 = "9004"
records = []
for lane in range(1, 7):
    records.append({"lane": lane, "player_id": pid4 if lane == 4 else str(9000 + lane), "entry_course": lane,
                    "course4_ex_st": -.04 if lane == 4 else .1 + lane / 100, "course4_exhibition_time": 6.7 + lane / 100,
                    "course4_lap_time": 36.5 + lane / 10, "course4_around_time": 5.4 + lane / 100,
                    "course4_straight_time": 6.8 + lane / 100, "created_date": "2026-10-23T20:04:00+09:00"})

checks = {}


class AttackProvider:
    feature_order = ("course4_history_n", "course4_history_makuri_rate_pct", "course4_history_makurizashi_rate_pct", "course4_ex_st", "course4_exhibition_time", "course4_lap_time", "course4_around_time", "course4_straight_time")
    def _history(self, player_id, race_date, cutoff, mode):
        checks["history_fixed_to_official_deadline"] = cutoff.isoformat() == "2026-10-23T11:00:00+09:00"
        return {"course4_history_n": 12, "course4_history_makuri_rate_pct": 10.0, "course4_history_makurizashi_rate_pct": 5.0}, {"safe": True}


class WallProvider:
    feature_order = ("a", "b")
    identities = {"artifact": "fixture-wall"}
    def _features(self, c3, c4, exhibit): return [.1, -.2]


class AttackCore:
    def __init__(self, provider): self.provider = provider
    def predict(self, race, cutoff):
        payload = self.provider.build()
        return {"prediction_available": True, "p_no_attack_success": .7, "p_makuri_success": .2, "p_makurizashi_success": .1, "logic_version": "fixture-attack", "artifact_sha256": "a"}


class WallCore:
    def __init__(self, provider): self.provider = provider
    def predict(self, race, cutoff):
        self.provider.build()
        return {"prediction_available": True, "wall_probability": .4, "logic_version": "fixture-wall", "artifact_sha256": "b"}


def variants(model_variant, store):
    return AttackProvider(), WallProvider(), AttackCore, WallCore


with tempfile.TemporaryDirectory() as tmp:
    store = StaticSnapshotStore(Path(tmp))
    deadline = "2026-10-23T11:00:00+09:00"
    payload = {"snapshot_version": "course4-tenkai-static-input-snapshot/v1", "canonical_version": "v1",
               "capture_kind": "FORMAL_PROSPECTIVE_STATIC_PRE_DEADLINE", "race_code": "20261023TMG02",
               "captured_at": "2026-10-23T09:00:00+09:00", "official_deadline": deadline, "lanes": {}}
    for lane in range(1, 7):
        payload["lanes"][str(lane)] = {"player_id": records[lane - 1]["player_id"], "overall_average_st": .15,
            "course_entry_count": 20, "course_average_st": .14 + lane / 1000, "course_average_rank": 3.0 + lane / 10}
    store.first_write(payload)
    engine = PostRaceReference(loader=Loader(records), snapshot_store=store, variant_factory=variants,
                               clock=lambda: datetime(2026, 10, 23, 20, 5, tzinfo=ZoneInfo("Asia/Tokyo")))
    result = engine.analyze("20261023TMG02", deadline, "FULL")
    checks["reference_never_persists_or_enters_strict"] = result["persisted"] is False and result["strict_forward_eligible"] is False
    checks["current_model_is_explicit"] = result["model_context"] == "CURRENT_MODEL_AT_CALCULATION"
    checks["result_tables_never_read"] = result["input_provenance"]["result_tables_read"] is False
    checks["wall_uses_predeadline_static_snapshot"] = result["components"]["wall"]["prediction_available"] is True
    checks["attack_uses_deadline_history_and_audited_exhibition"] = result["components"]["attack"]["prediction_available"] is True
    checks["f_start_is_numeric_input_not_result"] = records[3]["course4_ex_st"] == -.04
    missing = PostRaceReference(loader=Loader(records), snapshot_store=StaticSnapshotStore(Path(tmp) / "missing"), variant_factory=variants,
                                clock=lambda: datetime(2026, 10, 23, 20, 5, tzinfo=ZoneInfo("Asia/Tokyo"))).analyze("20261023TMG02", deadline, "FULL")
    checks["missing_static_blocks_wall_only"] = missing["components"]["wall"]["reason_code"] == "PROSPECTIVE_SNAPSHOT_NOT_FOUND" and missing["components"]["attack"]["prediction_available"] is True
    before = PostRaceReference(loader=Loader(records), snapshot_store=store, variant_factory=variants,
                               clock=lambda: datetime(2026, 10, 23, 10, 0, tzinfo=ZoneInfo("Asia/Tokyo"))).analyze("20261023TMG02", deadline, "FULL")
    checks["reference_cannot_run_before_deadline"] = before["components"]["wall"]["reason_code"] == "REFERENCE_ONLY_AFTER_DEADLINE"

source = (ROOT / "forecast/tenkai_ai_postrace_reference.py").read_text(encoding="utf-8")
checks["no_result_or_payout_query"] = "race_result" not in source and "payout" not in source and "INSERT " not in source and "UPDATE " not in source


class FakeCursor:
    def __init__(self, audits, rows): self.audits, self.rows, self.result = audits, rows, []
    def __enter__(self): return self
    def __exit__(self, *_args): pass
    def execute(self, sql, _params): self.result = self.audits if "exhibition_source_audit_v1" in sql else self.rows
    def fetchall(self): return self.result


class FakeConnection:
    def __init__(self, audits, rows): self.audits, self.rows = audits, rows
    def __enter__(self): return self
    def __exit__(self, *_args): pass
    def cursor(self): return FakeCursor(self.audits, self.rows)


observed = datetime(2026, 10, 23, 20, 4, tzinfo=ZoneInfo("Asia/Tokyo"))
db_rows = [(r["lane"], r["player_id"], r["entry_course"], r["course4_ex_st"], r["course4_exhibition_time"],
            r["course4_lap_time"], r["course4_around_time"], r["course4_straight_time"], observed) for r in records]
audit_rows = [{"lane": r["lane"], "player_id": r["player_id"], "entry_course": r["entry_course"],
               "start_timing": r["course4_ex_st"], "exhibition_time": r["course4_exhibition_time"],
               "lap_time": r["course4_lap_time"], "around_time": r["course4_around_time"],
               "straight_time": r["course4_straight_time"]} for r in records]
audits = [("official_tamagawa", "saved", observed, observed, audit_rows)]
loader = ReferenceDataLoader(connect=lambda: FakeConnection(audits, db_rows))
loaded, reason = loader.load("20261023TMG02", datetime(2026, 10, 23, 20, 5, tzinfo=ZoneInfo("Asia/Tokyo")))
checks["audit_values_match_live_rows"] = loaded is not None and reason is None
tampered = json.loads(json.dumps(audit_rows)); tampered[0]["exhibition_time"] += .1
rejected, reason = ReferenceDataLoader(connect=lambda: FakeConnection([("official_tamagawa", "saved", observed, observed, tampered)], db_rows)).load(
    "20261023TMG02", datetime(2026, 10, 23, 20, 5, tzinfo=ZoneInfo("Asia/Tokyo")))
checks["audit_value_mismatch_rejected"] = rejected is None and reason == "OFFICIAL_EXHIBITION_AUDIT_DATA_MISMATCH"
if not all(checks.values()): raise SystemExit(json.dumps(checks, ensure_ascii=False))
print(json.dumps({"passed": sum(checks.values()), "total": len(checks), "checks": checks}, ensure_ascii=False))
