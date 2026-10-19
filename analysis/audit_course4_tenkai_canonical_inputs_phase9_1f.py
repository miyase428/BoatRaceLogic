#!/usr/bin/env python3
"""Full Phase 9-1f canonical baseline/provider parity audit; no DB access."""
from __future__ import annotations

import csv
import hashlib
import json
import subprocess
import sys
import tempfile
from datetime import datetime
from pathlib import Path

import numpy as np

ROOT = Path(__file__).resolve().parent
REPO = ROOT.parent
OUT = ROOT / "output"
REPORT = OUT / "course4_tenkai_phase9_1f_report.json"
PARITY = OUT / "course4_tenkai_phase9_1f_parity_report.json"
sys.path.insert(0, str(ROOT))
from course4_tenkai_canonical_input import SNAPSHOT_VERSION, StaticSnapshotStore, canonical_json, sha256, value
from course34_wall_live_input_provider import WallRLiveInputProvider
from course4_attack_a5_live_input_provider import AttackA5LiveInputProvider


def stats(expected, actual, tolerance=1e-12):
    a, b = np.asarray(expected, dtype=np.float64), np.asarray(actual, dtype=np.float64)
    both_missing = np.isnan(a) & np.isnan(b)
    numeric = ~np.isnan(a) & ~np.isnan(b)
    one_missing = np.isnan(a) ^ np.isnan(b)
    diff = np.abs(a[numeric] - b[numeric])
    exact = int(both_missing.sum() + (diff == 0).sum())
    within = int(both_missing.sum() + (diff <= tolerance).sum())
    return {"n": int(len(a)), "numeric_pair_n": int(numeric.sum()), "both_missing_n": int(both_missing.sum()), "one_side_missing_n": int(one_missing.sum()),
            "exact_match_count": exact, "exact_match_rate": exact / len(a), "within_tolerance_count": within, "within_tolerance_rate": within / len(a),
            "max_abs_diff": None if not len(diff) else float(diff.max()), "mean_abs_diff": None if not len(diff) else float(diff.mean()),
            "mismatch_count": int(len(a) - within), "tolerance": tolerance}


def split_of(row):
    d = row["race_date"]
    return "Development" if d <= "2025-09-30" else ("Validation" if d <= "2026-03-31" else "EXPOSED_CONFIRMATION")


def provider_parity(provider, baseline_path, prefix):
    with baseline_path.open(encoding="utf-8") as handle:
        rows = list(csv.DictReader(handle))
    expected_order = provider.feature_order
    actual, expected, splits = [[] for _ in expected_order], [[] for _ in expected_order], {name: [] for name in ("Development", "Validation", "EXPOSED_CONFIRMATION")}
    cutoff = "2026-10-08T12:00:00+09:00"
    for row in rows:
        output = provider.build(row["race_code"], cutoff, "RESEARCH_REPLAY")
        if not output.get("feature_available") or tuple(output.get("feature_order", ())) != expected_order:
            raise RuntimeError(f"provider replay failed: {row['race_code']} {output.get('missing_reason')}")
        vectors = output["feature_vector"]
        index = len(splits[split_of(row)])
        splits[split_of(row)].append((row, vectors))
        for i, feature in enumerate(expected_order):
            expected[i].append(value(row[f"{prefix}{feature}"]))
            actual[i].append(value(vectors[i]))
    overall = {feature: stats(expected[i], actual[i]) for i, feature in enumerate(expected_order)}
    by_split = {}
    for split, pair_rows in splits.items():
        by_split[split] = {}
        for i, feature in enumerate(expected_order):
            by_split[split][feature] = stats([value(row[f"{prefix}{feature}"]) for row, _ in pair_rows], [value(vector[i]) for _, vector in pair_rows])
    return {"n": len(rows), "feature_order": list(expected_order), "overall": overall, "by_split": by_split,
            "pass": all(result["mismatch_count"] == 0 for result in overall.values())}


class StubExhibitionProvider:
    def __init__(self, c3, c4): self.c3, self.c4 = c3, c4
    def build(self, race_code, cutoff, mode):
        rows = []
        for course, player in ((3, self.c3), (4, self.c4)):
            rows.append({"player_id": player, "entry_course": course, "course4_ex_st": 0.12 if course == 3 else 0.11,
                         "course4_exhibition_time": 6.90 if course == 3 else 6.88, "course4_lap_time": 37.8 if course == 3 else 37.7,
                         "course4_around_time": 5.9 if course == 3 else 5.8, "course4_straight_time": 7.2 if course == 3 else 7.1})
        return {"prediction_available": True, "raw_input": {"exhibition_rows": rows}, "entry_mapping": {"wakunari": True}}


def prospective_contract_test():
    wall_row = next(csv.DictReader((OUT / "course34_wall_canonical_input_v1.csv").open(encoding="utf-8")))
    a5_row = next(csv.DictReader((OUT / "course4_attack_a5_canonical_input_v1.csv").open(encoding="utf-8")))
    lane3 = {"player_id": wall_row["course3_player_id"], "overall_average_st": value(wall_row["raw_course3_overall_average_st"]), "course_average_st": value(wall_row["raw_course3_average_st"]), "course_entry_count": value(wall_row["raw_course3_entry_count"]), "course_average_rank": value(wall_row["raw_course3_average_st_rank"])}
    lane4 = {"player_id": a5_row["course4_player_id"], "overall_average_st": value(a5_row["raw_overall_average_st"]), "course_average_st": value(a5_row["raw_course4_average_st"]), "course_entry_count": value(a5_row["raw_course4_entry_count"]), "course_average_rank": value(a5_row["raw_course4_average_st_rank"]), "motor_2ren_rate": value(a5_row["raw_motor_2ren_rate"]), "boat_2ren_rate": value(a5_row["raw_boat_2ren_rate"]), "national_win_rate": value(a5_row["raw_national_win_rate"]), "local_win_rate": value(a5_row["raw_local_win_rate"]), "national_2ren_rate": value(a5_row["raw_national_2ren_rate"]), "local_2ren_rate": value(a5_row["raw_local_2ren_rate"])}
    with tempfile.TemporaryDirectory() as tmp:
        store = StaticSnapshotStore(Path(tmp))
        payload = {"snapshot_version": SNAPSHOT_VERSION, "canonical_version": "v1", "race_code": "20990101TST01", "race_date": a5_row["race_date"], "term_info": a5_row["term_info"], "prediction_cutoff": "2024-10-01T08:00:00+09:00", "official_deadline": "2099-01-01T12:00:00+09:00", "captured_at": "2024-10-01T08:00:00+09:00", "capture_kind": "TEST", "source_contract": "test", "lanes": {"3": lane3, "4": lane4}}
        first, second = store.first_write(payload), store.first_write(payload)
        stub = StubExhibitionProvider(lane3["player_id"], lane4["player_id"])
        cutoff = "2026-10-08T12:00:00+09:00"
        wall = WallRLiveInputProvider(snapshot_store=store, exhibition_provider=stub).build("20990101TST01", cutoff, "LIVE")
        a5 = AttackA5LiveInputProvider(snapshot_store=store, exhibition_provider=stub).build("20990101TST01", cutoff, "LIVE")
        missing_store = StaticSnapshotStore(Path(tmp) / "missing")
        missing = WallRLiveInputProvider(snapshot_store=missing_store, exhibition_provider=stub).build("20990101TST02", cutoff, "LIVE")
    return {"first_write_saved": first["saved"], "second_write_noop": second["already_exists"],
            "wall_live_vector_available": wall.get("feature_available") is True and len(wall.get("feature_vector") or []) == 8,
            "a5_live_vector_available": a5.get("feature_available") is True and len(a5.get("feature_vector") or []) == 21,
            "missing_snapshot_fail_closed": missing.get("feature_available") is False and missing.get("missing_reason") == ["PROSPECTIVE_SNAPSHOT_NOT_FOUND"]}


def git_head(): return subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=REPO, text=True).strip()


def main():
    wall_provider, a5_provider = WallRLiveInputProvider(), AttackA5LiveInputProvider()
    wall_parity = provider_parity(wall_provider, OUT / "course34_wall_canonical_input_v1.csv", "feature_")
    a5_parity = provider_parity(a5_provider, OUT / "course4_attack_a5_canonical_input_v1.csv", "expected_")
    contract = prospective_contract_test()
    dry_cutoff = "2026-10-08T12:00:00+09:00"
    dry_wall = wall_provider.build("20261008WKM12", dry_cutoff, "LIVE")
    dry_a5 = a5_provider.build("20261008WKM12", dry_cutoff, "LIVE")
    dry = {"race_code": "20261008WKM12", "wall": {"feature_available": dry_wall.get("feature_available"), "missing_reason": dry_wall.get("missing_reason")},
           "a5": {"feature_available": dry_a5.get("feature_available"), "missing_reason": dry_a5.get("missing_reason")},
           "pass": dry_wall.get("feature_available") is False and dry_a5.get("feature_available") is False and dry_wall.get("missing_reason") == ["PROSPECTIVE_SNAPSHOT_NOT_FOUND"] and dry_a5.get("missing_reason") == ["PROSPECTIVE_SNAPSHOT_NOT_FOUND"]}
    capture_source = (ROOT / "capture_course4_tenkai_static_input_snapshot.py").read_text(encoding="utf-8")
    no_result_table = "race_result" not in capture_source
    readiness = {"WALL_R_CANONICAL_INPUT": "READY" if wall_parity["pass"] and all(contract.values()) and dry["pass"] else "BLOCKED",
                 "ATTACK_A5_CANONICAL_INPUT": "READY" if a5_parity["pass"] and all(contract.values()) and dry["pass"] else "BLOCKED"}
    readiness["ATTACK_SUCCESS_UPSTREAM_INPUT"] = "READY" if all(value == "READY" for value in readiness.values()) else "BLOCKED"
    parity = {"wall_r": wall_parity, "attack_a5": a5_parity, "prospective_contract_test": contract, "live_provider_dry_run": dry,
              "capture_source_result_table_absent": no_result_table, "readiness": readiness}
    PARITY.write_text(json.dumps(parity, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    report = {"phase": "9-1f", "result": "PASS" if all(value == "READY" for value in readiness.values()) and no_result_table else "BLOCKED",
              "wall_baseline": {"path": "analysis/output/course34_wall_canonical_input_v1.csv", "sha256": sha256(OUT / "course34_wall_canonical_input_v1.csv"), "rows": wall_parity["n"]},
              "a5_baseline": {"path": "analysis/output/course4_attack_a5_canonical_input_v1.csv", "sha256": sha256(OUT / "course4_attack_a5_canonical_input_v1.csv"), "rows": a5_parity["n"]},
              "manifests": {"wall": sha256(OUT / "course34_wall_canonical_input_v1_manifest.json"), "a5": sha256(OUT / "course4_attack_a5_canonical_input_v1_manifest.json")},
              "parity_report": {"path": str(PARITY.relative_to(REPO)), "sha256": sha256(PARITY)}, "readiness": readiness,
              "prospective_snapshot_contract": {"path": "var/course4_tenkai_input_snapshots/static_v1/<race_code>.json", "first_write": "O_EXCL", "prediction_saved": False, "result_table_used": False},
              "provider_paths": ["analysis/course34_wall_live_input_provider.py", "analysis/course4_attack_a5_live_input_provider.py"],
              "live_inference_implemented": False, "api_web_cron_changed": False, "generated_at": datetime.now().astimezone().isoformat(), "source_git_commit": git_head()}
    REPORT.write_text(json.dumps(report, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    if json.loads(REPORT.read_text()) != report or json.loads(PARITY.read_text()) != parity: raise RuntimeError("report reload mismatch")
    print(json.dumps({"report": str(REPORT.relative_to(REPO)), **readiness}, ensure_ascii=False))
    if report["result"] != "PASS": raise SystemExit(1)


if __name__ == "__main__": main()
