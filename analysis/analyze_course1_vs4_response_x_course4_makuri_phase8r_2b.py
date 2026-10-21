#!/usr/bin/env python3
"""Phase 8R-2b fixed-boundary, Development/Validation-only descriptive grid."""
from __future__ import annotations

import csv
import hashlib
import json
import math
import subprocess
import time
from datetime import datetime
from pathlib import Path

import numpy as np

ROOT = Path(__file__).resolve().parent
REPO, OUT = ROOT.parent, ROOT / "output"
import sys
sys.path.insert(0, str(ROOT))
from slit_validate_v2 import connect_db  # noqa: E402

PRED = OUT / "course4_attack_type_phase7_selection_predictions.csv"
PRED_SHA = "32327a96261f50be061f1c622439d03a10a5e7d490ad4576f4e4c32ec47ea4b2"
MAKURI_BOUNDARIES = OUT / "course1_vulnerability_x_course4_makuri_phase8r_boundaries.json"
RESPONSE = OUT / "course1_vs4_makuri_response_phase8r_profile_20241001_20260930.csv"
RESPONSE_SHA = "1bbfc451f32fa535c4e1762536ae7ad4a3f74afb200f2c625ebca960e8a51282"
BOUNDARY = OUT / "course1_vs4_response_phase8r_2b_boundaries.json"
GRID = OUT / "course1_vs4_response_x_course4_makuri_phase8r_2b_grid.csv"
REPORT = OUT / "course1_vs4_response_x_course4_makuri_phase8r_2b_report.json"
SPLITS, BANDS = ("Development", "Validation"), ("LOW", "MID", "HIGH")
EXPECTED = {"Development": 43787, "Validation": 20595}

RAW_SQL = """
WITH target AS MATERIALIZED (
 SELECT rm.race_code FROM boat_race.race_master rm
 WHERE rm.race_date BETWEEN DATE '2024-10-01' AND DATE '2026-03-31'
), ex AS (
 SELECT DISTINCT ON(e.race_code,e.player_id) e.race_code,e.player_id,e.entry_course
 FROM boat_race.exhibition_live e JOIN target t ON t.race_code=e.race_code
 ORDER BY e.race_code,e.player_id,e.created_date DESC NULLS LAST,e.entry_course
), rd AS (
 SELECT DISTINCT ON(d.race_code,d.player_id) d.race_code,d.player_id,d.entry_course,d.rank
 FROM boat_race.race_result_detail d JOIN target t ON t.race_code=d.race_code
 ORDER BY d.race_code,d.player_id
)
SELECT t.race_code,COALESCE(rd.entry_course,ex.entry_course,re.lane_number)::int actual_course,rd.rank
FROM target t JOIN boat_race.race_entry re ON re.race_code=t.race_code
LEFT JOIN rd ON rd.race_code=re.race_code AND rd.player_id=re.player_id
LEFT JOIN ex ON ex.race_code=re.race_code AND ex.player_id=re.player_id
ORDER BY t.race_code,re.lane_number
"""


def sha(p: Path) -> str: return hashlib.sha256(p.read_bytes()).hexdigest()
def git() -> str: return subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=REPO, text=True).strip()
def num(v):
    try:
        x = float(v); return x if math.isfinite(x) else None
    except (TypeError, ValueError): return None
def integer(v):
    x = num(v); return int(x) if x is not None else None
def band(v, b): return "LOW" if v <= b[0] else ("MID" if v <= b[1] else "HIGH")


def outcomes():
    out, code, boats = {}, None, []
    with connect_db() as conn:
        cur = conn.cursor(name="phase8r_2b_outcomes"); cur.itersize = 20000; cur.execute(RAW_SQL)
        try:
            for raw in cur:
                race = str(raw[0])
                if code is not None and race != code:
                    put_outcome(out, code, boats); boats = []
                code = race; boats.append((integer(raw[1]), integer(raw[2])))
            if code is not None: put_outcome(out, code, boats)
        finally: cur.close()
    return out


def put_outcome(out, code, boats):
    courses = [x[0] for x in boats]
    if len(boats) != 6 or set(courses) != set(range(1, 7)): return
    by = dict(boats)
    if sorted(r for _, r in boats if r in (1, 2, 3)) != [1, 2, 3]: return
    result = {}
    for course, prefix in ((5, "five"), (6, "six")):
        rank = by[course]
        result.update({f"{prefix}_second": int(rank == 2), f"{prefix}_third": int(rank == 3), f"{prefix}_top3": int(rank in (1, 2, 3))})
    out[code] = result


def summarize(rows):
    r = {"N": len(rows)}
    for prefix in ("five", "six"):
        for outcome in ("second", "third", "top3"):
            count = sum(x[f"{prefix}_{outcome}"] for x in rows)
            r[f"{prefix}_{outcome}_count"] = count
            r[f"{prefix}_{outcome}_rate"] = count / len(rows) if rows else None
    for o in ("second", "third", "top3"):
        r[f"gap_{o}"] = r[f"five_{o}_rate"] - r[f"six_{o}_rate"] if rows else None
    events = [x["event_count"] for x in rows]
    r.update({"event_count_min": min(events) if events else None, "event_count_max": max(events) if events else None,
              "event_count_median": float(np.median(events)) if events else None, "event_count_mean": float(np.mean(events)) if events else None})
    return r


def rate_diff(a, b, key): return None if a[key] is None or b[key] is None else a[key] - b[key]


def main():
    started = time.perf_counter()
    if sha(PRED) != PRED_SHA: raise RuntimeError("selection prediction hash mismatch")
    if sha(RESPONSE) != RESPONSE_SHA: raise RuntimeError("response profile hash mismatch")
    frozen = json.loads(MAKURI_BOUNDARIES.read_text(encoding="utf-8"))
    makuri_bounds = frozen["p_makuri_success"]["boundaries"]
    if len(makuri_bounds) != 2 or makuri_bounds[0] == makuri_bounds[1]: raise RuntimeError("invalid frozen Makuri boundaries")
    pred = [x for x in csv.DictReader(PRED.open(encoding="utf-8")) if x["split"] in SPLITS]
    if {s: sum(x["split"] == s for x in pred) for s in SPLITS} != EXPECTED or len(pred) != len({x["race_code"] for x in pred}): raise RuntimeError("prediction audit mismatch")
    response_rows = list(csv.DictReader(RESPONSE.open(encoding="utf-8")))
    if len(response_rows) != len({x["race_code"] for x in response_rows}): raise RuntimeError("response race_code duplicate")
    response = {x["race_code"]: x for x in response_rows}
    outcome = outcomes()
    audit = {"prediction_duplicate_race_code": 0, "response_duplicate_race_code": 0, "outcome_duplicate_race_code": 0}
    joined = []
    for p in pred:
        r, o = response.get(p["race_code"]), outcome.get(p["race_code"])
        if r is None or o is None: continue
        prob, tendency, events = num(p["p_makuri_success"]), num(r["36m_1c_vs4_makuri_retain_third_rate"]), integer(r["36m_1c_vs4_makuri_event_count"])
        if prob is None: raise RuntimeError(f"p_makuri_success missing: {p['race_code']}")
        joined.append({"race_code": p["race_code"], "split": p["split"], "p_makuri_success": prob, "retain_third_rate": tendency, "event_count": events, **o})
    for s in SPLITS:
        pp, jj = [x for x in pred if x["split"] == s], [x for x in joined if x["split"] == s]
        audit.update({f"{s}_prediction_N": len(pp), f"{s}_response_profile_missing": sum(x["race_code"] not in response for x in pp),
                      f"{s}_outcome_missing": sum(x["race_code"] not in outcome for x in pp if x["race_code"] in response), f"{s}_joined_N": len(jj),
                      f"{s}_p_makuri_success_missing": sum(num(x["p_makuri_success"]) is None for x in pp),
                      f"{s}_response_rate_missing_before_eligibility": sum(x["retain_third_rate"] is None for x in jj),
                      f"{s}_excluded_event_count_lt_3": sum(x["event_count"] is None or x["event_count"] < 3 for x in jj)})
    dev_eligible = [x for x in joined if x["split"] == "Development" and x["event_count"] >= 3 and x["retain_third_rate"] is not None]
    response_bounds = [float(np.quantile([x["retain_third_rate"] for x in dev_eligible], q, method="linear")) for q in (1 / 3, 2 / 3)]
    if response_bounds[0] == response_bounds[1]: raise RuntimeError(f"equal response tertile boundary: {response_bounds}")
    usable = [x for x in joined if x["event_count"] is not None and x["event_count"] >= 3 and x["retain_third_rate"] is not None]
    for x in usable:
        x["makuri_band"] = band(x["p_makuri_success"], makuri_bounds)
        x["response_band"] = band(x["retain_third_rate"], response_bounds)
    boundary_doc = {"phase": "Phase 8R-2b", "method": "numpy.quantile(method='linear')", "quantiles": [1 / 3, 2 / 3],
                    "fixed_makuri_probability": {"source": str(MAKURI_BOUNDARIES.relative_to(REPO)), "source_sha256": sha(MAKURI_BOUNDARIES), "source_split": "Development", "boundaries": makuri_bounds},
                    "retain_third_response": {"source_split": "Development", "eligibility": "36m_1c_vs4_makuri_event_count >= 3", "source_N": len(dev_eligible), "boundaries": response_bounds},
                    "response_profile_path": str(RESPONSE.relative_to(REPO)), "response_profile_sha256": sha(RESPONSE), "prediction_path": str(PRED.relative_to(REPO)), "prediction_sha256": sha(PRED),
                    "generated_at": datetime.now().astimezone().isoformat(), "source_script": str(Path(__file__).relative_to(REPO)), "source_script_sha256": sha(Path(__file__)), "source_git_commit": git()}
    BOUNDARY.write_text(json.dumps(boundary_doc, ensure_ascii=False, indent=2), encoding="utf-8")
    grid, lookup = [], {}
    for s in SPLITS:
        for mb in BANDS:
            for rb in BANDS:
                row = {"split": s, "makuri_band": mb, "response_band": rb, **summarize([x for x in usable if x["split"] == s and x["makuri_band"] == mb and x["response_band"] == rb])}
                grid.append(row); lookup[(s, mb, rb)] = row
    fields = ["split", "makuri_band", "response_band", "N"]
    for prefix in ("five", "six"):
        for o in ("second", "third", "top3"): fields += [f"{prefix}_{o}_count", f"{prefix}_{o}_rate"]
    fields += ["gap_second", "gap_third", "gap_top3", "event_count_min", "event_count_median", "event_count_mean", "event_count_max"]
    with GRID.open("w", newline="", encoding="utf-8") as h:
        w = csv.DictWriter(h, fieldnames=fields); w.writeheader(); w.writerows(grid)
    contrasts, diagnostic = {}, {}
    for s in SPLITS:
        high, low = lookup[(s, "HIGH", "HIGH")], lookup[(s, "HIGH", "LOW")]
        five_lift, six_lift = rate_diff(high, low, "five_second_rate"), rate_diff(high, low, "six_second_rate")
        contrasts[s] = {"high_makuri_response_high_vs_low": {"five_second_lift": five_lift, "six_second_lift": six_lift, "five_specific_second_contrast": five_lift - six_lift if five_lift is not None and six_lift is not None else None, "second_gap_high": high["gap_second"], "second_gap_low": low["gap_second"], "high_cell_N": high["N"], "low_cell_N": low["N"]}}
        diagnostic[s] = {rb: {k: lookup[(s, "HIGH", rb)][k] for k in ("N", "five_second_rate", "six_second_rate", "gap_second", "event_count_min", "event_count_median", "event_count_mean", "event_count_max")} for rb in BANDS}
    val = contrasts["Validation"]["high_makuri_response_high_vs_low"]
    tests = {"five_second_response_high_gt_low": val["five_second_lift"] is not None and val["five_second_lift"] > 0, "five_second_lift_gt_0": val["five_second_lift"] is not None and val["five_second_lift"] > 0, "five_specific_second_contrast_gt_0": val["five_specific_second_contrast"] is not None and val["five_specific_second_contrast"] > 0, "second_gap_high_gt_low": val["second_gap_high"] is not None and val["second_gap_low"] is not None and val["second_gap_high"] > val["second_gap_low"]}
    gate = "PASS" if all(tests.values()) else "FAIL"
    reread = list(csv.DictReader(GRID.open(encoding="utf-8")))
    total = {s: sum(int(x["N"]) for x in reread if x["split"] == s) for s in SPLITS}
    rate_max_diff = 0.0
    for x in reread:
        N = int(x["N"])
        for p in ("five", "six"):
            for o in ("second", "third", "top3"):
                stored = float(x[f"{p}_{o}_rate"]) if x[f"{p}_{o}_rate"] else None
                rebuilt = int(x[f"{p}_{o}_count"]) / N if N else None
                if stored is not None: rate_max_diff = max(rate_max_diff, abs(stored - rebuilt))
    reload_audit = {"input_sha256_match": sha(PRED) == PRED_SHA and sha(RESPONSE) == RESPONSE_SHA, "fixed_makuri_boundaries_exact_match": json.loads(BOUNDARY.read_text())["fixed_makuri_probability"]["boundaries"] == makuri_bounds, "response_boundaries_exact_match": json.loads(BOUNDARY.read_text())["retain_third_response"]["boundaries"] == response_bounds, "analysis_N": total, "grid_N_exact_match": total == {s: sum(x["split"] == s for x in usable) for s in SPLITS}, "rate_recalculation_max_abs_diff": rate_max_diff}
    if not all((reload_audit["input_sha256_match"], reload_audit["fixed_makuri_boundaries_exact_match"], reload_audit["response_boundaries_exact_match"], reload_audit["grid_N_exact_match"], rate_max_diff <= 1e-12)): raise RuntimeError(f"reload audit failed: {reload_audit}")
    report = {"phase": "Phase 8R-2b", "hypothesis": "historical 1C RETAIN_THIRD response proxy; not causal resistance or 5C technique claim", "primary_outcome": "actual-course 5C second", "primary_control": "actual-course 6C second", "primary_response_reason": "pre-specified: a 4C win with 1C final rank 3 is the result-based proxy closest to the hypothesized other-boat second-place pattern", "splits_used": list(SPLITS), "confirmation_evaluated": False, "join_audit": audit, "eligibility": "36m_1c_vs4_makuri_event_count >= 3", "boundaries": {"p_makuri_success": makuri_bounds, "retain_third_rate": response_bounds}, "grid_path": str(GRID.relative_to(REPO)), "grid_sha256": sha(GRID), "contrasts": contrasts, "high_makuri_response_proxy_diagnostic": diagnostic, "gate_tests": tests, "H8R_2_SECOND_DIRECTION": gate, "reload_audit": reload_audit, "generated_at": boundary_doc["generated_at"], "source_script": boundary_doc["source_script"], "source_script_sha256": boundary_doc["source_script_sha256"], "source_git_commit": boundary_doc["source_git_commit"], "timing_seconds": {"total": time.perf_counter() - started}}
    REPORT.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps({"boundary": str(BOUNDARY), "grid": str(GRID), "report": str(REPORT), "gate": gate, "reload_audit": reload_audit, "timing_seconds": report["timing_seconds"]}, ensure_ascii=False, indent=2))


if __name__ == "__main__": main()
