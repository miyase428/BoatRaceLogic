#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Phase 7-1a: build and audit the fixed 3-class Attack Type research input.

This is deliberately a data-only step: it scores already-frozen Wall-R/A5
artifacts and labels completed races, but does not fit, tune, or evaluate a
new model.  It never accesses 5C/6C follower outcome columns.
"""
from __future__ import annotations

import csv
import hashlib
import json
import math
import re
import subprocess
import sys
import time
import unicodedata
from collections import Counter, defaultdict
from datetime import datetime
from pathlib import Path

import numpy as np

ROOT = Path(__file__).resolve().parent
OUT = ROOT / "output"
sys.path.insert(0, str(ROOT))
sys.path.insert(0, str(ROOT.parent))
sys.path.insert(0, str(ROOT.parent / "theories" / "course_correction"))
from analyze_course4_attack_phase4r import (  # noqa: E402
    A5_FEATURES, WALL_FEATURES, add_wall_features, parsedate, predict_artifact,
    source_rows, split, x_of,
)
from theories.course_correction.predict_pattern import connect_db  # noqa: E402

DATASET = OUT / "course4_attack_type_phase7_dataset_20241001_20260930.csv"
AUDIT = OUT / "course4_attack_type_phase7_dataset_audit.json"
UNAVAILABLE = OUT / "course4_attack_type_phase7_label_unavailable.csv"
FEATURES = (
    "wall_r_probability", "attack_a5_probability", "course4_history_n",
    "course4_history_makuri_rate_pct", "course4_history_makurizashi_rate_pct",
    "smoothed_course4_st", "course4_average_st_rank", "course4_entry_count",
    "course4_ex_st", "course4_exhibition_time", "course4_lap_time",
    "course4_around_time", "course4_straight_time",
)
LABELS = {0: "NO_ATTACK_SUCCESS", 1: "MAKURI_SUCCESS", 2: "MAKURIZASHI_SUCCESS"}
EXPECTED = {"Development": 43787, "Validation": 20595, "EXPOSED_CONFIRMATION": 22122}
ARTIFACTS = {
    "wall_selection": (OUT / "course34_wall_phase3r_selection_model.json", "cff52c943be9f1246dec4d2539aac092ca4be79c608fa5752932c4bbf3cf613a"),
    "wall_final": (OUT / "course34_wall_phase3r_final_model.json", "f5348195d1d05c982f273ce7924c2b0eda5174e243e3e01bb818b87d6f3e768c"),
    "a5_selection": (OUT / "course4_attack_phase4r_selection_model.json", "55e324df1f7ba6e70233821bca797ae5a08fe0e73e4b015fc5a71aafaee1b574"),
    "a5_final": (OUT / "course4_attack_phase4r_final_model.json", "39808675efde92bfcbb73d69f88e915260f7dd0e0c48636d1b2775d6b2874176"),
}


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def number(v):
    try:
        x = float(v)
        return x if math.isfinite(x) else np.nan
    except (TypeError, ValueError):
        return np.nan


def yes(v):
    return str(v).lower() in {"true", "1", "t"}


def git_head():
    return subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT.parent, text=True).strip()


def normalize_technique(raw: str) -> str | None:
    s = re.sub(r"\s+", "", unicodedata.normalize("NFKC", raw or "").strip())
    return {"まくり": "MAKURI", "まくり差し": "MAKURIZASHI", "逃げ": "NIGE",
            "差し": "SASHI", "抜き": "NUKI", "恵まれ": "MEGUMARE"}.get(s)


FEATURE_PROVENANCE = {
    "wall_r_probability": {"source": "analysis/output/course34_wall_phase3r_{selection,final}_model.json", "column": "artifact prediction", "join_key": "race_code", "formula": "frozen Wall-R deterministic logistic prediction; selection for Development/Validation, final for Confirmation", "historical_window": "artifact-defined pre-race inputs", "target_day_included": False, "future_data": "frozen artifact provenance; see term-static caveat", "missing_current_value": "none (artifact median handling already frozen)", "artifact": "Wall-R; hashes recorded below"},
    "attack_a5_probability": {"source": "analysis/output/course4_attack_phase4r_{selection,final}_model.json", "column": "artifact prediction", "join_key": "race_code", "formula": "frozen A5 deterministic logistic prediction; selection for Development/Validation, final for Confirmation", "historical_window": "artifact-defined pre-race inputs", "target_day_included": False, "future_data": "frozen artifact provenance; see term-static caveat", "missing_current_value": "none (artifact median handling already frozen)", "artifact": "A5; hashes recorded below"},
    "course4_history_n": {"source": "analysis/output/course4_attack_phase4_input_20241001_20260930.csv", "column": "course4_history_n", "join_key": "race_code + course4_player_id", "formula": "count of actual-4C starts in [target_date - 12 calendar months, target_date)", "historical_window": "strictly prior 12 calendar months", "target_day_included": False, "future_data": "PASS: source_max_date < target_date and same_day_or_future_n=0", "missing_current_value": "blank/NaN; no fill"},
    "course4_history_makuri_rate_pct": {"source": "same Phase4 input / historical race results", "column": "course4_history_makuri_rate_pct", "join_key": "race_code + course4_player_id", "formula": "100*prior-12m makuri wins/course4_history_n", "historical_window": "strictly prior 12 calendar months", "target_day_included": False, "future_data": "same strict-history audit", "missing_current_value": "blank/NaN when denominator unavailable; no fill"},
    "course4_history_makurizashi_rate_pct": {"source": "same Phase4 input / historical race results", "column": "course4_history_makurizashi_rate_pct", "join_key": "race_code + course4_player_id", "formula": "100*prior-12m makurizashi wins/course4_history_n", "historical_window": "strictly prior 12 calendar months", "target_day_included": False, "future_data": "same strict-history audit", "missing_current_value": "blank/NaN when denominator unavailable; no fill"},
    "smoothed_course4_st": {"source": "Phase4 input plus analysis/output/course4_attack_phase4_prerace/*.csv", "column": "course4_average_st, course4_entry_count, overall_average_st", "join_key": "race_code + course4_player_id", "formula": "overall_average_st + entry_count/(entry_count+20)*(course4_average_st-overall_average_st)", "historical_window": "term_info racer_results values", "target_day_included": "not verifiable at row as-of level", "future_data": "POTENTIAL term-static leakage; gate blocked", "missing_current_value": "blank/NaN; no fill"},
    "course4_average_st_rank": {"source": "boat_race.racer_results via Phase4 input", "column": "course4_average_st_rank", "join_key": "term_info + player_id", "formula": "stored course-4 average ST rank", "historical_window": "term-static", "target_day_included": "not verifiable at row as-of level", "future_data": "POTENTIAL term-static leakage; gate blocked", "missing_current_value": "blank/NaN; no fill"},
    "course4_entry_count": {"source": "boat_race.racer_results via Phase4 input", "column": "course4_entry_count", "join_key": "term_info + player_id", "formula": "stored course-4 entry count", "historical_window": "term-static", "target_day_included": "not verifiable at row as-of level", "future_data": "POTENTIAL term-static leakage; gate blocked", "missing_current_value": "blank/NaN; no fill"},
    "course4_ex_st": {"source": "boat_race.exhibition_live via Phase4 input", "column": "start_timing", "join_key": "race_code + player_id (actual 4C)", "formula": "raw exhibition start timing", "historical_window": "same-race pre-race exhibition", "target_day_included": True, "future_data": "no completed-race result used", "missing_current_value": "blank/NaN; no fill"},
    "course4_exhibition_time": {"source": "boat_race.exhibition_live via Phase4 input", "column": "exhibition_time", "join_key": "race_code + player_id (actual 4C)", "formula": "raw exhibition time", "historical_window": "same-race pre-race exhibition", "target_day_included": True, "future_data": "no completed-race result used", "missing_current_value": "blank/NaN; no fill"},
    "course4_lap_time": {"source": "boat_race.exhibition_live via Phase4 input", "column": "lap_time", "join_key": "race_code + player_id (actual 4C)", "formula": "raw exhibition lap time", "historical_window": "same-race pre-race exhibition", "target_day_included": True, "future_data": "no completed-race result used", "missing_current_value": "blank/NaN; no fill"},
    "course4_around_time": {"source": "boat_race.exhibition_live via Phase4 input", "column": "around_time", "join_key": "race_code + player_id (actual 4C)", "formula": "raw exhibition around time", "historical_window": "same-race pre-race exhibition", "target_day_included": True, "future_data": "no completed-race result used", "missing_current_value": "blank/NaN; no fill"},
    "course4_straight_time": {"source": "boat_race.exhibition_live via Phase4 input", "column": "straight_time", "join_key": "race_code + player_id (actual 4C)", "formula": "raw exhibition straight time", "historical_window": "same-race pre-race exhibition", "target_day_included": True, "future_data": "no completed-race result used", "missing_current_value": "blank/NaN; no fill"},
}


def load_winner_techniques():
    """Read only raw winning techniques; no follower outcomes are requested."""
    sql = """
        SELECT rm.race_code::text, TRIM(COALESCE(rrd.technique, ''))
        FROM boat_race.race_master rm
        JOIN boat_race.race_result_detail rrd ON rrd.race_code = rm.race_code
        WHERE rm.race_date >= %s AND rm.race_date <= %s
          AND TRIM(rrd.rank::text) = '1'
        ORDER BY rm.race_code
    """
    grouped = defaultdict(list)
    started = time.perf_counter()
    with connect_db() as conn, conn.cursor() as cur:
        cur.execute(sql, ("2024-10-01", "2026-09-30"))
        for code, technique in cur.fetchall():
            grouped[str(code)].append(str(technique or ""))
    return grouped, time.perf_counter() - started


def rankdata(x):
    order = np.argsort(x, kind="mergesort"); out = np.empty(len(x)); i = 0
    while i < len(x):
        j = i
        while j + 1 < len(x) and x[order[j + 1]] == x[order[i]]: j += 1
        out[order[i:j + 1]] = (i + j + 2) / 2; i = j + 1
    return out


def stats(values):
    a = np.asarray(values, dtype=float); good = a[np.isfinite(a)]
    return {"n": int(len(a)), "non_null_n": int(len(good)), "coverage_pct": float(100 * len(good) / len(a)) if len(a) else None,
            "min": float(np.min(good)) if len(good) else None, "max": float(np.max(good)) if len(good) else None,
            "mean": float(np.mean(good)) if len(good) else None, "median": float(np.median(good)) if len(good) else None}


def history_vs_entry(rows):
    by_split = {}
    for name in EXPECTED:
        pairs = [(number(r["course4_history_n"]), number(r["course4_entry_count"])) for r in rows if r["split"] == name]
        pairs = [(a, b) for a, b in pairs if np.isfinite(a) and np.isfinite(b)]
        a, b = np.array([p[0] for p in pairs]), np.array([p[1] for p in pairs])
        diff = a - b
        pearson = float(np.corrcoef(a, b)[0, 1]) if len(a) > 1 else None
        spearman = float(np.corrcoef(rankdata(a), rankdata(b))[0, 1]) if len(a) > 1 else None
        exact = int(np.sum(a == b))
        by_split[name] = {"definitions": {"course4_history_n": "strictly-prior rolling 12-calendar-month actual-4C history", "course4_entry_count": "term_info + player_id racer_results course-4 entry count"},
                          "n": int(len(a)), "history": stats(a), "entry": stats(b), "exact_match_n": exact,
                          "exact_match_pct": float(100 * exact / len(a)), "pearson": pearson, "spearman": spearman,
                          "difference_unique_count": int(len(np.unique(diff))), "classification": "distinct" if exact / len(a) < .10 else "near-duplicate"}
    return by_split


def clean(v):
    return "" if not math.isfinite(float(v)) else repr(float(v))


def main():
    started = time.perf_counter()
    artifact_hashes = {}
    artifacts = {}
    for key, (path, expected_hash) in ARTIFACTS.items():
        actual = sha256(path)
        if actual != expected_hash: raise RuntimeError(f"artifact hash mismatch: {path}: {actual}")
        artifact_hashes[key] = actual; artifacts[key] = json.loads(path.read_text(encoding="utf-8"))

    rows, source_paths = source_rows()
    raw_by_split = dict(zip(EXPECTED, split(rows)))
    if {k: len(v) for k, v in raw_by_split.items()} != EXPECTED: raise RuntimeError("fixed base sample count mismatch")
    wall_rows = add_wall_features(rows)
    wall_by_split = dict(zip(EXPECTED, split(wall_rows)))
    probabilities = {}
    for name in EXPECTED:
        a5_art = artifacts["a5_final" if name == "EXPOSED_CONFIRMATION" else "a5_selection"]
        wall_art = artifacts["wall_final" if name == "EXPOSED_CONFIRMATION" else "wall_selection"]
        probabilities[name] = (predict_artifact(wall_art, x_of(wall_by_split[name], WALL_FEATURES)), predict_artifact(a5_art, x_of(raw_by_split[name], A5_FEATURES)))

    techniques, query_seconds = load_winner_techniques()
    records, unavailable, technique_audit = [], [], Counter()
    history_violations = Counter()
    for split_name in EXPECTED:
        for r, wall_p, a5_p in zip(raw_by_split[split_name], *probabilities[split_name]):
            four_won = yes(r.get("four_first"))
            raw_values = techniques.get(r["race_code"], [])
            raw_distinct = sorted(set(raw_values))
            normalized = normalize_technique(raw_distinct[0]) if len(raw_distinct) == 1 else None
            if four_won:
                reason = None
                if len(raw_values) != 1: reason = "winner_row_count_not_one"
                elif normalized is None: reason = "missing_or_unrecognized_winner_technique"
                technique_audit[(split_name, raw_distinct[0] if raw_distinct else "", normalized or "UNAVAILABLE")] += 1
                if reason:
                    unavailable.append({"race_code": r["race_code"], "race_date": r["race_date"], "stadium": r["stadium"], "split": split_name, "raw_winner_technique": " | ".join(raw_distinct), "normalization": normalized or "", "reason": reason})
                    continue
                label_id = 1 if normalized == "MAKURI" else (2 if normalized == "MAKURIZASHI" else 0)
            else:
                label_id = 0
            # The following explicit selection makes it impossible to export/access follower outcomes.
            out = {"race_code": r["race_code"], "race_date": r["race_date"], "stadium": r["stadium"], "split": split_name, "label": LABELS[label_id], "label_id": label_id,
                   "wall_r_probability": wall_p, "attack_a5_probability": a5_p}
            for f in FEATURES[2:]: out[f] = number(r.get(f))
            records.append(out)
            if int(number(r.get("course4_same_day_or_future_n"))) if np.isfinite(number(r.get("course4_same_day_or_future_n"))) else 0:
                history_violations["same_day_or_future_n"] += 1
            max_date = r.get("course4_source_max_date", "")
            if max_date and max_date >= r["race_date"]: history_violations["source_max_date_not_prior"] += 1

    fields = ("race_code", "race_date", "stadium", "split", "label", "label_id", *FEATURES)
    with DATASET.open("w", newline="", encoding="utf-8") as h:
        w = csv.DictWriter(h, fieldnames=fields); w.writeheader()
        for r in records: w.writerow({k: clean(v) if k in FEATURES else r[k] for k, v in r.items()})
    with UNAVAILABLE.open("w", newline="", encoding="utf-8") as h:
        w = csv.DictWriter(h, fieldnames=("race_code", "race_date", "stadium", "split", "raw_winner_technique", "normalization", "reason")); w.writeheader(); w.writerows(unavailable)

    with DATASET.open(encoding="utf-8") as h: reread = list(csv.DictReader(h))
    counts = {s: len([r for r in reread if r["split"] == s]) for s in EXPECTED}
    dup = len(reread) - len({r["race_code"] for r in reread})
    missing = sum(not r["race_code"] for r in reread)
    per_split = {}
    for s in EXPECTED:
        rr = [r for r in reread if r["split"] == s]
        label_counts = dict(Counter(r["label"] for r in rr))
        probability_audit = {}
        for f in ("wall_r_probability", "attack_a5_probability"):
            values = np.asarray([number(r[f]) for r in rr], dtype=float)
            probability_audit[f] = {"missing_n": int(np.sum(~np.isfinite(values))), "invalid_outside_0_1_n": int(np.sum(np.isfinite(values) & ((values < 0) | (values > 1)))), "range_check": "PASS" if np.all(np.isfinite(values) & (values >= 0) & (values <= 1)) else "FAIL"}
        per_split[s] = {"row_count": len(rr), "label_counts": label_counts,
                        "label_prevalence_pct": {k: 100 * v / len(rr) for k, v in label_counts.items()},
                        "feature_coverage": {f: stats([number(r[f]) for r in rr]) for f in FEATURES},
                        "probability_audit": probability_audit}
    base_hashes = {str(p.relative_to(ROOT.parent)): sha256(p) for p in source_paths}
    audit = {
        "phase": "Phase 7-1a", "purpose": "fixed 3-class label generation and availability/provenance/temporal audit only; no model fit", "generated_at": datetime.now().astimezone().isoformat(), "source_git_commit": git_head(),
        "dataset_path": str(DATASET.relative_to(ROOT.parent)), "dataset_sha256": sha256(DATASET), "dataset_row_count": len(reread), "duplicate_race_code": dup, "missing_race_code": missing,
        "base_sample_expected": EXPECTED, "base_sample_input_counts": {k: len(v) for k, v in raw_by_split.items()}, "dataset_split_counts": counts,
        "label_definition": {"0": "non-4C winner", "1": "actual 4C first and normalized raw winner technique=まくり", "2": "actual 4C first and normalized raw winner technique=まくり差し", "label_unavailable": "actual 4C first with missing/unrecognized/ambiguous raw technique; excluded"},
        "label_unavailable_path": str(UNAVAILABLE.relative_to(ROOT.parent)), "label_unavailable_count": len(unavailable), "label_unavailable_sha256": sha256(UNAVAILABLE),
        "winner_technique_query_seconds": query_seconds, "winner_technique_raw_normalized_counts": [{"split": s, "raw": raw, "normalized": norm, "count": n} for (s, raw, norm), n in sorted(technique_audit.items())],
        "features_fixed_order": list(FEATURES), "feature_provenance": FEATURE_PROVENANCE, "split_audit": per_split, "history_n_vs_entry_count": history_vs_entry(records),
        "history_temporal_audit": {"rule": "course4_history_n source max date must be strictly before target race_date; same-day/future count must be zero", "violations": dict(history_violations), "status": "PASS" if not history_violations else "FAIL"},
        "provenance": {"base_input_files_sha256": base_hashes, "artifact_sha256": artifact_hashes,
          "actual_course_rule": "fixed Phase 1-6 rule: result_detail -> latest exhibition -> lane", "fixed_sample_rule": "finalized / 6 boats / unique actual courses 1..6 / frame order 123456",
          "feature_sources": {"wall_r_probability": "frozen Wall-R selection (Development/Validation) or final (Confirmation) artifact", "attack_a5_probability": "frozen A5 selection (Development/Validation) or final (Confirmation) artifact", "course4_history_*": "strictly-prior rolling 12-calendar-month 4C history", "course4_entry_count/course4_average_st_rank/smoothed_course4_st": "racer_results joined by term_info + player_id; term-static values", "course4_ex_*": "pre-race exhibition_live values"}},
        "temporal_leakage_gate": {"status": "BLOCKED_PENDING_ASOF_EVIDENCE", "reason": "course4_entry_count and course4_average_st_rank are term-static racer_results fields; this audit cannot prove an as-of timestamp/version for those values. They are retained unchanged because the 13-feature specification is fixed, but Phase 7-1b fitting must not begin without an explicit research decision or an as-of reconstruction.", "history_fields": "PASS", "future_outcome_or_follower_fields_accessed": False},
        "timing_seconds": {"total": time.perf_counter() - started},
    }
    AUDIT.write_text(json.dumps(audit, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps({"dataset": str(DATASET), "rows": len(reread), "duplicates": dup, "missing": missing, "split_counts": counts, "unavailable": len(unavailable), "dataset_sha256": audit["dataset_sha256"], "audit": str(AUDIT), "temporal_gate": audit["temporal_leakage_gate"]["status"], "timing": audit["timing_seconds"]}, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
