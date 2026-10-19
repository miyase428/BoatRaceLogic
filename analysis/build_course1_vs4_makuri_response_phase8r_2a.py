#!/usr/bin/env python3
"""Phase 8R-2a: strict-as-of 1C vs 4C Makuri Response Proxy profiles.

This builder deliberately creates no 5C/6C outcome, probability join, bands,
model, smoothing, or inference.  RETAIN/COLLAPSE are final-rank response
proxies, not observations of physical resistance in a race.
"""
from __future__ import annotations

import bisect
import csv
import hashlib
import json
import math
import re
import subprocess
import time
import unicodedata
from calendar import monthrange
from collections import Counter, defaultdict
from datetime import date, datetime
from pathlib import Path

import sys
ROOT = Path(__file__).resolve().parent
REPO = ROOT.parent
OUT = ROOT / "output"
sys.path.insert(0, str(ROOT))
from slit_validate_v2 import connect_db  # noqa: E402

TARGET_PROFILE = OUT / "course1_makuri_vulnerability_phase8r_profile_20241001_20260930.csv"
TARGET_PROFILE_SHA256 = "6742e78502db468827bc5dc198570e4a66d20f65c313823637af68487f4f86e1"
STAMP = "20241001_20260930"
PROFILE = OUT / f"course1_vs4_makuri_response_phase8r_profile_{STAMP}.csv"
AUDIT = OUT / "course1_vs4_makuri_response_phase8r_audit.json"
SUMMARY = OUT / "course1_vs4_makuri_response_phase8r_2a_summary.json"
HISTORY_START, HISTORY_END = date(2021, 10, 1), date(2026, 9, 30)
SPLITS = (("Development", date(2024, 10, 1), date(2025, 9, 30)),
          ("Validation", date(2025, 10, 1), date(2026, 3, 31)),
          ("EXPOSED_CONFIRMATION", date(2026, 4, 1), date(2026, 9, 30)))

RAW_SQL = """
WITH target AS MATERIALIZED (
  SELECT rm.race_code,rm.race_date FROM boat_race.race_master rm
  WHERE rm.race_date BETWEEN %s AND %s
), ex AS (
  SELECT DISTINCT ON (e.race_code,e.player_id) e.race_code,e.player_id,e.entry_course
  FROM boat_race.exhibition_live e JOIN target t ON t.race_code=e.race_code
  ORDER BY e.race_code,e.player_id,e.created_date DESC NULLS LAST,e.entry_course
), rd AS (
  SELECT DISTINCT ON (d.race_code,d.player_id) d.race_code,d.player_id,d.entry_course,d.rank,d.technique
  FROM boat_race.race_result_detail d JOIN target t ON t.race_code=d.race_code
  ORDER BY d.race_code,d.player_id
)
SELECT t.race_code,t.race_date,re.lane_number,re.player_id::text,rd.entry_course,ex.entry_course,
       COALESCE(rd.entry_course,ex.entry_course,re.lane_number)::int actual_course,rd.rank,TRIM(COALESCE(rd.technique,''))
FROM target t JOIN boat_race.race_entry re ON re.race_code=t.race_code
LEFT JOIN rd ON rd.race_code=re.race_code AND rd.player_id=re.player_id
LEFT JOIN ex ON ex.race_code=re.race_code AND ex.player_id=re.player_id
ORDER BY t.race_code,re.lane_number
"""


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def git() -> str:
    return subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=REPO, text=True).strip()


def dt(v: str) -> date:
    return date.fromisoformat(v)


def integer(v):
    try:
        return int(float(v))
    except (TypeError, ValueError):
        return None


def norm_technique(v) -> str | None:
    s = re.sub(r"\s+", "", unicodedata.normalize("NFKC", str(v or "")).strip())
    return {"まくり": "MAKURI", "まくり差し": "MAKURIZASHI", "逃げ": "NIGE", "差し": "SASHI", "抜き": "NUKI", "恵まれ": "MEGUMARE"}.get(s)


def months_ago(d: date, months: int) -> date:
    total = d.year * 12 + d.month - 1 - months
    year, month0 = divmod(total, 12)
    month = month0 + 1
    return date(year, month, min(d.day, monthrange(year, month)[1]))


def split_of(d: date) -> str:
    for name, lo, hi in SPLITS:
        if lo <= d <= hi:
            return name
    raise ValueError(d)


def clean(v):
    if isinstance(v, (date, datetime)):
        return v.isoformat()
    return "" if v is None else v


def write_csv(path: Path, fields, rows) -> None:
    with path.open("w", newline="", encoding="utf-8") as handle:
        writer = csv.DictWriter(handle, fieldnames=fields, extrasaction="ignore")
        writer.writeheader()
        for row in rows:
            writer.writerow({key: clean(row.get(key)) for key in fields})


def stream(conn):
    cur = conn.cursor(name="phase8r_2a_history")
    cur.itersize = 20000
    cur.execute(RAW_SQL, (HISTORY_START, HISTORY_END))
    try:
        yield from cur
    finally:
        cur.close()


def resolved(boats):
    courses = [b["course"] for b in boats]
    if len(boats) != 6 or set(courses) != set(range(1, 7)):
        return None, "actual_course_not_unique_1_6"
    by_course = {b["course"]: b for b in boats}
    winners = [b for b in boats if b["rank"] == 1]
    if len(winners) != 1:
        return (by_course, None), "winner_rank_unavailable_or_ambiguous"
    return (by_course, winners[0]), None


def load_history(conn):
    history, audit, code, boats = defaultdict(list), Counter(), None, []
    examples = defaultdict(list)

    def consume(race_code, values):
        audit["raw_races"] += 1
        pair, reason = resolved(values)
        if pair is None:
            audit["mapping_failure"] += 1
            if len(examples["ACTUAL_COURSE_MAPPING_FAILURE"]) < 20:
                examples["ACTUAL_COURSE_MAPPING_FAILURE"].append({"race_code": race_code, "reason": reason})
            return
        by_course, winner = pair
        c1 = by_course[1]
        if winner is None:
            audit["winner_rank_unavailable"] += 1
            return
        technique = norm_technique(winner["technique"])
        eligible = technique is not None
        if not eligible:
            audit["winner_technique_unavailable"] += 1
        event = bool(eligible and winner["course"] == 4 and technique == "MAKURI")
        response = None
        if event:
            if c1["rank"] == 2:
                response = "RETAIN_SECOND"
            elif c1["rank"] == 3:
                response = "RETAIN_THIRD"
            elif c1["rank"] is not None and c1["rank"] >= 4:
                response = "COLLAPSE"
            else:
                response = "RESULT_UNAVAILABLE"
            audit["four_makuri_event"] += 1
            audit["four_makuri_c1_rank_one"] += int(c1["rank"] == 1)
            audit[f"response_{response.lower()}"] += 1
        if eligible:
            history[c1["player_id"]].append({"date": c1["date"], "race_code": race_code, "rank": c1["rank"],
                                               "winner_course": winner["course"], "winner_technique_raw": winner["technique"],
                                               "winner_technique_normalized": technique, "event": event, "response": response})
            audit["eligible_actual1_start"] += 1

    for raw in stream(conn):
        race_code = str(raw[0])
        if code is not None and code != race_code:
            consume(code, boats); boats = []
        code = race_code
        boats.append({"date": raw[1], "player_id": str(raw[3]).strip(), "course": integer(raw[6]),
                      "rank": integer(raw[7]), "technique": str(raw[8] or "").strip()})
    if code is not None:
        consume(code, boats)
    for values in history.values():
        values.sort(key=lambda r: (r["date"], r["race_code"]))
    return history, audit, examples


def source_slice(rows, lo, hi):
    keys = [(r["date"], r["race_code"]) for r in rows]
    return rows[bisect.bisect_left(keys, (lo, "")):bisect.bisect_left(keys, (hi, ""))]


def rate(n, d):
    return n / d if d else None


def profile_row(target, history, violations):
    d, player, code = target["race_date"], target["player_id"], target["race_code"]
    out = {**target}
    for months in (36, 12):
        values = source_slice(history.get(player, []), months_ago(d, months), d)
        for h in values:
            if h["race_code"] == code: violations["TARGET_SELF_LEAK"].append({"race_code": code, "feature": f"{months}m", "source": h})
            if h["date"] == d: violations["SAME_DAY_RESULT_LEAK"].append({"race_code": code, "feature": f"{months}m", "source": h})
            if h["date"] > d: violations["FUTURE_SOURCE"].append({"race_code": code, "feature": f"{months}m", "source": h})
            if h["date"] >= d: violations["SOURCE_MAX_DATE_VIOLATION"].append({"race_code": code, "feature": f"{months}m", "source": h})
        events = [h for h in values if h["event"]]
        resolved_events = [h for h in events if h["response"] != "RESULT_UNAVAILABLE"]
        second = sum(h["response"] == "RETAIN_SECOND" for h in events)
        third = sum(h["response"] == "RETAIN_THIRD" for h in events)
        collapse = sum(h["response"] == "COLLAPSE" for h in events)
        unavailable = sum(h["response"] == "RESULT_UNAVAILABLE" for h in events)
        p = f"{months}m_1c"
        out.update({f"{p}_window_start": months_ago(d, months), f"{p}_window_end_exclusive": d,
                    f"{p}_start_count": len(values), f"{p}_vs4_makuri_event_count": len(events),
                    f"{p}_vs4_makuri_retain_second_count": second, f"{p}_vs4_makuri_retain_third_count": third,
                    f"{p}_vs4_makuri_retain_top3_count": second + third, f"{p}_vs4_makuri_collapse_count": collapse,
                    f"{p}_vs4_makuri_result_unavailable_count": unavailable,
                    f"{p}_vs4_makuri_event_rate": rate(len(events), len(values)),
                    f"{p}_vs4_makuri_retain_second_rate": rate(second, len(resolved_events)),
                    f"{p}_vs4_makuri_retain_third_rate": rate(third, len(resolved_events)),
                    f"{p}_vs4_makuri_retain_top3_rate": rate(second + third, len(resolved_events)),
                    f"{p}_vs4_makuri_collapse_rate": rate(collapse, len(resolved_events)),
                    f"{p}_source_min_date": values[0]["date"] if values else None,
                    f"{p}_source_max_date": values[-1]["date"] if values else None,
                    f"_{months}m_event_rows": events})
    return out


def numeric_summary(values):
    values = sorted(values)
    if not values:
        return {"min": None, "median": None, "mean": None, "p75": None, "p90": None, "p95": None, "max": None}
    def q(frac):
        pos = (len(values) - 1) * frac; lo = math.floor(pos); hi = math.ceil(pos)
        return values[lo] + (values[hi] - values[lo]) * (pos - lo)
    return {"min": values[0], "median": q(.5), "mean": sum(values) / len(values), "p75": q(.75), "p90": q(.9), "p95": q(.95), "max": values[-1]}


def sparse_audit(rows, months):
    field = f"{months}m_1c_vs4_makuri_event_count"
    vals = [r[field] for r in rows]
    n = len(vals)
    return {"N": n, "count_buckets": {"0": sum(v == 0 for v in vals), "1": sum(v == 1 for v in vals), "2": sum(v == 2 for v in vals), "3": sum(v == 3 for v in vals), "4": sum(v == 4 for v in vals), "5+": sum(v >= 5 for v in vals), "10+": sum(v >= 10 for v in vals)},
            "percent_buckets": {"0": 100 * sum(v == 0 for v in vals) / n, "1": 100 * sum(v == 1 for v in vals) / n, "2": 100 * sum(v == 2 for v in vals) / n, "3": 100 * sum(v == 3 for v in vals) / n, "4": 100 * sum(v == 4 for v in vals) / n, "5+": 100 * sum(v >= 5 for v in vals) / n, "10+": 100 * sum(v >= 10 for v in vals) / n},
            "summary": numeric_summary(vals), "coverage": {">=1": sum(v >= 1 for v in vals), ">=3": sum(v >= 3 for v in vals), ">=5": sum(v >= 5 for v in vals), ">=10": sum(v >= 10 for v in vals)}}


def response_audit(rows):
    rr = [r for r in rows if r["36m_1c_vs4_makuri_event_count"] > 0]
    return {"event_count_gt_zero_N": len(rr), "rate_distributions_available_case": {k: numeric_summary([r[k] for r in rr if r[k] is not None]) for k in ("36m_1c_vs4_makuri_retain_second_rate", "36m_1c_vs4_makuri_retain_third_rate", "36m_1c_vs4_makuri_retain_top3_rate", "36m_1c_vs4_makuri_collapse_rate")}}


def choose_spots(rows):
    chosen, total_events = [], 0
    for split, _, _ in SPLITS:
        candidates = sorted((r for r in rows if r["split"] == split), key=lambda r: r["36m_1c_vs4_makuri_event_count"], reverse=True)
        for candidate in candidates:
            events = candidate["_36m_event_rows"]
            if events:
                chosen.append({"target_race_code": candidate["race_code"], "target_race_date": candidate["race_date"], "split": split, "actual1_player_id": candidate["player_id"],
                               "event_count": len(events), "events": [{"history_date": h["date"], "history_race_code": h["race_code"], "target_player_actual_course": 1, "winner_actual_course": h["winner_course"], "winner_technique": h["winner_technique_normalized"], "actual1_final_rank": h["rank"], "response_classification": h["response"]} for h in events]})
                total_events += len(events); break
    if total_events < 6:
        for candidate in sorted(rows, key=lambda r: r["36m_1c_vs4_makuri_event_count"], reverse=True):
            if any(x["target_race_code"] == candidate["race_code"] for x in chosen):
                continue
            events = candidate["_36m_event_rows"]
            if events:
                chosen.append({"target_race_code": candidate["race_code"], "target_race_date": candidate["race_date"], "split": candidate["split"], "actual1_player_id": candidate["player_id"], "event_count": len(events), "events": [{"history_date": h["date"], "history_race_code": h["race_code"], "target_player_actual_course": 1, "winner_actual_course": h["winner_course"], "winner_technique": h["winner_technique_normalized"], "actual1_final_rank": h["rank"], "response_classification": h["response"]} for h in events]})
                total_events += len(events)
            if total_events >= 6: break
    return chosen, total_events


def main():
    started = time.perf_counter(); OUT.mkdir(exist_ok=True)
    if not TARGET_PROFILE.exists() or sha(TARGET_PROFILE) != TARGET_PROFILE_SHA256:
        raise RuntimeError("fixed Phase 8R-1a target profile missing or hash mismatch")
    targets = []
    for r in csv.DictReader(TARGET_PROFILE.open(encoding="utf-8")):
        targets.append({"race_code": r["race_code"], "race_date": dt(r["race_date"]), "split": r["split"], "player_id": r["player_id"], "actual_course": 1, "actual_course_mapping_source": r["actual_course_mapping_source"]})
    if len(targets) != len({r["race_code"] for r in targets}):
        raise RuntimeError("target race_code duplicate")
    history_started = time.perf_counter()
    with connect_db() as conn:
        history, history_audit, mapping_examples = load_history(conn)
    history_seconds = time.perf_counter() - history_started
    violations = defaultdict(list)
    rows = [profile_row(t, history, violations) for t in targets]
    fields = ["race_code", "race_date", "split", "player_id", "actual_course", "actual_course_mapping_source"]
    for months in (36, 12):
        p = f"{months}m_1c"
        fields += [f"{p}_window_start", f"{p}_window_end_exclusive", f"{p}_start_count", f"{p}_vs4_makuri_event_count", f"{p}_vs4_makuri_retain_second_count", f"{p}_vs4_makuri_retain_third_count", f"{p}_vs4_makuri_retain_top3_count", f"{p}_vs4_makuri_collapse_count", f"{p}_vs4_makuri_result_unavailable_count", f"{p}_vs4_makuri_event_rate", f"{p}_vs4_makuri_retain_second_rate", f"{p}_vs4_makuri_retain_third_rate", f"{p}_vs4_makuri_retain_top3_rate", f"{p}_vs4_makuri_collapse_rate", f"{p}_source_min_date", f"{p}_source_max_date"]
    write_csv(PROFILE, fields, rows)
    consistency = Counter()
    for r in rows:
        for months in (36, 12):
            p = f"{months}m_1c"
            consistency["retain_top3_equals_second_plus_third_violation"] += int(r[f"{p}_vs4_makuri_retain_top3_count"] != r[f"{p}_vs4_makuri_retain_second_count"] + r[f"{p}_vs4_makuri_retain_third_count"])
            resolved_events = r[f"{p}_vs4_makuri_event_count"] - r[f"{p}_vs4_makuri_result_unavailable_count"]
            consistency["resolved_equals_retain_top3_plus_collapse_violation"] += int(resolved_events != r[f"{p}_vs4_makuri_retain_top3_count"] + r[f"{p}_vs4_makuri_collapse_count"])
            if resolved_events:
                consistency["rates_sum_to_one_violation"] += int(abs((r[f"{p}_vs4_makuri_retain_top3_rate"] + r[f"{p}_vs4_makuri_collapse_rate"]) - 1.0) > 1e-12)
    split_audit = {}
    for name, _, _ in SPLITS:
        rr = [r for r in rows if r["split"] == name]
        split_audit[name] = {"profile_rows": len(rr), "36m_sparsity": sparse_audit(rr, 36), "12m_sparsity": sparse_audit(rr, 12), "36m_response_distribution": response_audit(rr)}
    spots, spot_event_count = choose_spots(rows)
    for r in rows:
        r.pop("_36m_event_rows", None); r.pop("_12m_event_rows", None)
    leakage = {"TARGET_SELF_LEAK_COUNT": len(violations["TARGET_SELF_LEAK"]), "SAME_DAY_RESULT_LEAK_COUNT": len(violations["SAME_DAY_RESULT_LEAK"]), "FUTURE_SOURCE_COUNT": len(violations["FUTURE_SOURCE"]), "SOURCE_MAX_DATE_VIOLATION": len(violations["SOURCE_MAX_DATE_VIOLATION"])}
    audit = {"phase": "Phase 8R-2a", "interpretation": "final-rank response proxy only; not a causal resistance measurement", "inputs": {"target_profile_path": str(TARGET_PROFILE.relative_to(REPO)), "target_profile_sha256": sha(TARGET_PROFILE)}, "definitions": {"actual_course_rule": "race_result_detail.entry_course -> latest exhibition_live.entry_course -> race_entry.lane_number; exhibition fallback uses entry_course only", "event": "target player actual-course=1 AND winner actual-course=4 AND normalized winner technique=MAKURI", "eligible_start_denominator": "actual 1C histories with a uniquely resolved winner rank/course and normalized winner technique", "response": {"RETAIN_SECOND": "actual 1C rank=2", "RETAIN_THIRD": "actual 1C rank=3", "RETAIN_TOP3": "rank=2 or 3", "COLLAPSE": "rank>=4", "RESULT_UNAVAILABLE": "rank unavailable; excluded from response-rate denominator"}, "windows": {"primary": "[race_date-36 months,race_date)", "secondary": "[race_date-12 months,race_date)"}, "no_smoothing": True}, "history_source": dict(history_audit), "mapping_examples": dict(mapping_examples), "leakage": leakage, "leakage_examples": {k: v[:20] for k, v in violations.items()}, "classification_consistency": dict(consistency), "split_audit": split_audit, "spot_checks": spots, "spot_check_event_count": spot_event_count, "source_script": str(Path(__file__).relative_to(REPO)), "source_script_sha256": sha(Path(__file__)), "source_git_commit": git(), "generated_at": datetime.now().astimezone().isoformat(), "timing_seconds": {"history_query": history_seconds, "total": time.perf_counter() - started}}
    AUDIT.write_text(json.dumps(audit, ensure_ascii=False, indent=2, default=lambda v: v.isoformat() if isinstance(v, (date, datetime)) else str(v)), encoding="utf-8")
    all_ok = (len(rows) == len({r["race_code"] for r in rows}) and all(v == 0 for v in leakage.values()) and all(v == 0 for v in consistency.values()) and history_audit["four_makuri_c1_rank_one"] == 0 and spot_event_count >= 6)
    usable = sum(r["36m_1c_vs4_makuri_event_count"] >= 3 for r in rows) / len(rows)
    predictor_usability = "SUFFICIENT" if usable >= .5 else ("SPARSE" if usable >= .1 else "VERY_SPARSE")
    summary = {"PHASE": "8R-2a", "PROFILE_BUILD": "PASS" if all_ok else "BLOCKED", "ACTUAL_COURSE_MAPPING": "PASS" if rows else "BLOCKED", "PROFILE_PATH": str(PROFILE.relative_to(REPO)), "PROFILE_SHA256": sha(PROFILE), "PROFILE_ROWS": len(rows), "DUPLICATE_RACE_CODE_COUNT": len(rows) - len({r["race_code"] for r in rows}), **leakage, "CLASSIFICATION_CONSISTENCY": "PASS" if all(v == 0 for v in consistency.values()) else "FAIL", "ONE_C_RANK_ONE_IN_EVENT": history_audit["four_makuri_c1_rank_one"], "PREDICTOR_USABILITY": predictor_usability, "PHASE_8R_2A_RESULT": "PASS" if all_ok else "BLOCKED", "AUDIT_PATH": str(AUDIT.relative_to(REPO)), "AUDIT_SHA256": sha(AUDIT), "source_script": str(Path(__file__).relative_to(REPO)), "source_script_sha256": sha(Path(__file__)), "source_git_commit": git(), "generated_at": audit["generated_at"], "timing_seconds": audit["timing_seconds"], "commit": "NO", "push": "NO"}
    SUMMARY.write_text(json.dumps(summary, ensure_ascii=False, indent=2), encoding="utf-8")
    reread = list(csv.DictReader(PROFILE.open(encoding="utf-8")))
    reload_audit = {"row_count": len(reread), "duplicate_race_code": len(reread) - len({r["race_code"] for r in reread}), "profile_sha256_match": sha(PROFILE) == summary["PROFILE_SHA256"], "summary_result": json.loads(SUMMARY.read_text(encoding="utf-8"))["PHASE_8R_2A_RESULT"]}
    print(json.dumps({"profile": str(PROFILE), "audit": str(AUDIT), "summary": str(SUMMARY), "result": summary["PHASE_8R_2A_RESULT"], "predictor_usability": predictor_usability, "reload_audit": reload_audit, "timing_seconds": audit["timing_seconds"]}, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
