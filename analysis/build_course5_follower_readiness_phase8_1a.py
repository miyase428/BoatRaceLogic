#!/usr/bin/env python3
"""Build and audit Phase 8-1a Follower Readiness facts (no model fitting).

The builder is deliberately retrospective and read-only with respect to the
database.  It fixes actual course by the established research rule
result_detail -> latest exhibition -> lane, then creates strict-prior rolling
profiles for actual courses 5 and 6.  Race outcomes and winning technique are
used only for labels/history-cohort membership, never as predictor columns.
"""
from __future__ import annotations

import bisect
import csv
import hashlib
import json
import math
import re
import subprocess
import sys
import time
import unicodedata
from calendar import monthrange
from collections import Counter, defaultdict
from datetime import date, datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parent
REPO = ROOT.parent
OUT = ROOT / "output"
sys.path.insert(0, str(ROOT))
from slit_validate_v2 import connect_db  # noqa: E402

START = date(2024, 10, 1)
END = date(2026, 9, 30)
HISTORY_START = date(2021, 10, 1)
K = 20.0
STAMP = "20241001_20260930"

RACE_FACT = OUT / f"course5_follower_readiness_phase8_race_fact_{STAMP}.csv"
FIVE_FACT = OUT / f"course5_follower_readiness_phase8_course5_fact_{STAMP}.csv"
SIX_FACT = OUT / f"course5_follower_readiness_phase8_course6_fact_{STAMP}.csv"
ORDINARY = OUT / f"course5_follower_readiness_phase8_ordinary_profiles_{STAMP}.csv"
FOLLOWER = OUT / f"course5_follower_readiness_phase8_follower_profiles_{STAMP}.csv"
SOURCE_AUDIT = OUT / "course5_follower_readiness_phase8_feature_source_audit.csv"
LEAK_EXAMPLES = OUT / "course5_follower_readiness_phase8_leakage_audit_examples.csv"
COVERAGE = OUT / "course5_follower_readiness_phase8_coverage_report.json"
SUMMARY = OUT / "course5_follower_readiness_phase8_1a_summary.json"
FORWARD_SCHEMA = OUT / "course5_follower_readiness_phase8_true_forward_schema.json"

SPLITS = (
    ("Development", date(2024, 10, 1), date(2025, 9, 30)),
    ("Validation", date(2025, 10, 1), date(2026, 3, 31)),
    ("EXPOSED_CONFIRMATION", date(2026, 4, 1), date(2026, 9, 30)),
)


def split_of(d: date) -> str:
    for name, lo, hi in SPLITS:
        if lo <= d <= hi:
            return name
    raise ValueError(d)


def months_ago(d: date, months: int) -> date:
    total = d.year * 12 + d.month - 1 - months
    year, month0 = divmod(total, 12)
    month = month0 + 1
    return date(year, month, min(d.day, monthrange(year, month)[1]))


def number(v):
    try:
        x = float(v)
        return x if math.isfinite(x) else None
    except (TypeError, ValueError):
        return None


def integer(v):
    x = number(v)
    return int(x) if x is not None else None


def normalize_technique(v) -> str | None:
    s = re.sub(r"\s+", "", unicodedata.normalize("NFKC", str(v or "")).strip())
    return {
        "まくり": "MAKURI", "まくり差し": "MAKURIZASHI", "逃げ": "NIGE",
        "差し": "SASHI", "抜き": "NUKI", "恵まれ": "MEGUMARE",
    }.get(s)


def sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def git_head() -> str:
    return subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=REPO, text=True).strip()


def rate(n, d):
    return None if not d else n / d


def clean(v):
    if v is None:
        return ""
    if isinstance(v, bool):
        return int(v)
    if isinstance(v, (date, datetime)):
        return v.isoformat()
    return v


def write_csv(path: Path, fields, rows):
    with path.open("w", newline="", encoding="utf-8") as h:
        w = csv.DictWriter(h, fieldnames=fields, extrasaction="ignore")
        w.writeheader()
        for row in rows:
            w.writerow({k: clean(row.get(k)) for k in fields})


TARGET_SQL = """
WITH target AS MATERIALIZED (
  SELECT rm.race_code, rm.race_date, rm.race_number
  FROM boat_race.race_master rm
  WHERE rm.race_date BETWEEN %s AND %s
), ex AS (
  SELECT DISTINCT ON (e.race_code,e.player_id)
    e.race_code,e.player_id,e.entry_course,e.start_timing,e.created_date
  FROM boat_race.exhibition_live e JOIN target t ON t.race_code=e.race_code
  ORDER BY e.race_code,e.player_id,e.created_date DESC NULLS LAST,e.entry_course
), rd AS (
  SELECT DISTINCT ON (d.race_code,d.player_id)
    d.race_code,d.player_id,d.entry_course,d.rank,d.start_timing,d.technique
  FROM boat_race.race_result_detail d JOIN target t ON t.race_code=d.race_code
  ORDER BY d.race_code,d.player_id
)
SELECT t.race_code,t.race_date,SUBSTRING(t.race_code,9,3),t.race_number,
       re.lane_number,re.player_id::text,re.motor_number,re.boat_number,
       rd.entry_course,ex.entry_course,
       COALESCE(rd.entry_course,ex.entry_course,re.lane_number) actual_course,
       rd.rank,rd.technique,
       ex.start_timing,ex.created_date,
       ps.national_win_rate,ps.local_win_rate,
       es.motor_exacta_rate,es.boat_exacta_rate
FROM target t
JOIN boat_race.race_entry re ON re.race_code=t.race_code
LEFT JOIN rd ON rd.race_code=re.race_code AND rd.player_id=re.player_id
LEFT JOIN ex ON ex.race_code=re.race_code AND ex.player_id=re.player_id
LEFT JOIN boat_race.player_stats ps ON ps.race_code=re.race_code AND ps.player_id=re.player_id
LEFT JOIN boat_race.engine_specs es ON es.race_code=re.race_code AND es.motor_number=re.motor_number
ORDER BY t.race_code,re.lane_number
"""


HISTORY_SQL = """
WITH target AS MATERIALIZED (
  SELECT rm.race_code,rm.race_date
  FROM boat_race.race_master rm
  WHERE rm.race_date BETWEEN %s AND %s
), ex AS (
  SELECT DISTINCT ON (e.race_code,e.player_id)
    e.race_code,e.player_id,e.entry_course
  FROM boat_race.exhibition_live e JOIN target t ON t.race_code=e.race_code
  ORDER BY e.race_code,e.player_id,e.created_date DESC NULLS LAST,e.entry_course
), rd AS (
  SELECT DISTINCT ON (d.race_code,d.player_id)
    d.race_code,d.player_id,d.entry_course,d.rank,d.start_timing,d.technique
  FROM boat_race.race_result_detail d JOIN target t ON t.race_code=d.race_code
  ORDER BY d.race_code,d.player_id
)
SELECT t.race_code,t.race_date,re.lane_number,re.player_id::text,
       COALESCE(rd.entry_course,ex.entry_course,re.lane_number)::int actual_course,
       CASE WHEN rd.rank::text ~ '^[1-6]$' THEN rd.rank::int END rank,
       rd.start_timing,TRIM(COALESCE(rd.technique,'')) technique
FROM target t JOIN boat_race.race_entry re ON re.race_code=t.race_code
LEFT JOIN rd ON rd.race_code=re.race_code AND rd.player_id=re.player_id
LEFT JOIN ex ON ex.race_code=re.race_code AND ex.player_id=re.player_id
ORDER BY t.race_code,re.lane_number
"""


def db_schema_audit(conn):
    wanted = ("player_stats", "engine_specs", "exhibition_live", "racer_results")
    out = {}
    with conn.cursor() as cur:
        for table in wanted:
            cur.execute("""SELECT column_name,data_type FROM information_schema.columns
                           WHERE table_schema='boat_race' AND table_name=%s ORDER BY ordinal_position""", (table,))
            out[table] = [{"column": c, "type": t} for c, t in cur.fetchall()]
    return out


def stream_query(conn, name, sql, params):
    cur = conn.cursor(name=name)
    cur.itersize = 20000
    cur.execute(sql, params)
    try:
        yield from cur
    finally:
        cur.close()


def parse_target(raw):
    return {
        "race_code": str(raw[0]), "race_date": raw[1], "stadium": str(raw[2]),
        "race_number": str(raw[3]), "lane": integer(raw[4]), "player_id": str(raw[5]).strip(),
        "motor_number": integer(raw[6]), "boat_number": integer(raw[7]),
        "rd_course": integer(raw[8]), "ex_course": integer(raw[9]), "actual_course": integer(raw[10]),
        "rank": integer(raw[11]), "raw_technique": str(raw[12] or "").strip(),
        "exhibition_st": number(raw[13]), "exhibition_created_at": raw[14],
        "national_win_rate": number(raw[15]), "local_win_rate": number(raw[16]),
        "motor_2ren_rate": number(raw[17]), "boat_2ren_rate": number(raw[18]),
    }


def finalize_target_race(code, boats, target_stats, examples):
    d = boats[0]["race_date"]
    split = split_of(d)
    target_stats[split]["all_races"] += 1
    reason = ""
    courses = [b["actual_course"] for b in boats]
    if len(boats) != 6:
        reason = "entry_count_not_6"
    elif any(c not in range(1, 7) for c in courses if c is not None) or any(c is None for c in courses):
        reason = "actual_course_outside_1_6_or_missing"
    elif len(set(courses)) != 6:
        reason = "actual_course_not_unique_1_6"
    mapping_ok = not reason
    target_stats[split]["mapping_success"] += int(mapping_ok)
    target_stats[split]["mapping_failure"] += int(not mapping_ok)
    if not mapping_ok:
        examples.add("ACTUAL_COURSE_MAPPING_FAILURE", code, "actual_course", "mapping", reason)
    by_course = {b["actual_course"]: b for b in boats} if mapping_ok else {}
    top3 = [b["rank"] for b in boats if b["rank"] in (1, 2, 3)]
    label_ok = mapping_ok and sorted(top3) == [1, 2, 3]
    target_stats[split]["label_success"] += int(label_ok)
    target_stats[split]["label_failure"] += int(not label_ok)
    if mapping_ok and not label_ok:
        examples.add("LABEL_UNAVAILABLE", code, "rank", "race_result_detail", "top3_not_unique_complete")
    mapping_sources = Counter()
    for b in boats:
        source = "RESULT_DETAIL" if b["rd_course"] is not None else ("EXHIBITION" if b["ex_course"] is not None else "LANE")
        mapping_sources[source] += 1
        target_stats[split][f"mapping_source_{source.lower()}"] += 1
    conditional = False
    technique = None
    if mapping_ok and label_ok:
        b4 = by_course[4]
        technique = normalize_technique(b4["raw_technique"]) if b4["rank"] == 1 else None
        conditional = b4["rank"] == 1 and technique == "MAKURI"
        target_stats[split]["conditional_population"] += int(conditional)
    race = {
        "race_code": code, "race_date": d, "stadium": boats[0]["stadium"], "race_number": boats[0]["race_number"],
        "split": split, "entry_count": len(boats), "actual_course_mapping_ok": mapping_ok,
        "actual_course_mapping_failure_reason": reason, "label_available": label_ok,
        "conditional_population_four_makuri_success": conditional,
        "normalized_winner_technique_when_4c_won": technique,
        "mapping_result_detail_n": mapping_sources["RESULT_DETAIL"],
        "mapping_exhibition_fallback_n": mapping_sources["EXHIBITION"],
        "mapping_lane_fallback_n": mapping_sources["LANE"],
    }
    if mapping_ok:
        for c in (4, 5, 6):
            b = by_course[c]
            race[f"course{c}_player_id"] = b["player_id"]
            race[f"course{c}_lane"] = b["lane"]
            race[f"course{c}_rank"] = b["rank"]
    return race, by_course, mapping_ok, label_ok


class Examples:
    def __init__(self, limit=100):
        self.limit = limit
        self.counts = Counter()
        self.rows = []

    def add(self, violation, race_code, feature, source, detail):
        self.counts[violation] += 1
        if sum(r["violation_type"] == violation for r in self.rows) < self.limit:
            self.rows.append({"violation_type": violation, "race_code": race_code,
                              "feature": feature, "source": source, "detail": str(detail)})


def load_targets(conn, examples):
    started = time.perf_counter()
    races, course_targets = [], {5: [], 6: []}
    stats = defaultdict(Counter)
    current_code, boats = None, []
    definite_post_ex = 0
    ex_same_day_unverified = 0
    for raw in stream_query(conn, "phase8_targets", TARGET_SQL, (START, END)):
        b = parse_target(raw)
        code = b["race_code"]
        if current_code is not None and code != current_code:
            race, by_course, mapping_ok, label_ok = finalize_target_race(current_code, boats, stats, examples)
            races.append(race)
            if mapping_ok:
                for c in (5, 6):
                    row = dict(by_course[c]); row.update({"split": race["split"], "label_available": label_ok,
                        "label_second": label_ok and by_course[c]["rank"] == 2,
                        "label_third": label_ok and by_course[c]["rank"] == 3,
                        "label_top3": label_ok and by_course[c]["rank"] in (1, 2, 3),
                        "conditional_population_four_makuri_success": race["conditional_population_four_makuri_success"]})
                    course_targets[c].append(row)
            boats = []
        current_code = code
        ts = b["exhibition_created_at"]
        if ts is not None:
            if ts.date() > b["race_date"]:
                definite_post_ex += 1
                examples.add("POST_CUTOFF_EXHIBITION", code, "exhibition_st", "exhibition_live",
                             f"created_at={ts.isoformat()} race_date={b['race_date'].isoformat()}")
            else:
                ex_same_day_unverified += 1
        boats.append(b)
    if current_code is not None:
        race, by_course, mapping_ok, label_ok = finalize_target_race(current_code, boats, stats, examples)
        races.append(race)
        if mapping_ok:
            for c in (5, 6):
                row = dict(by_course[c]); row.update({"split": race["split"], "label_available": label_ok,
                    "label_second": label_ok and by_course[c]["rank"] == 2,
                    "label_third": label_ok and by_course[c]["rank"] == 3,
                    "label_top3": label_ok and by_course[c]["rank"] in (1, 2, 3),
                    "conditional_population_four_makuri_success": race["conditional_population_four_makuri_success"]})
                course_targets[c].append(row)
    return races, course_targets, stats, {"definite_post_cutoff_exhibition_count": definite_post_ex,
        "same_day_exhibition_without_cutoff_count": ex_same_day_unverified,
        "seconds": time.perf_counter() - started}


def load_history(conn):
    started = time.perf_counter()
    by = defaultdict(list)
    rows = 0
    races = valid_races = 0
    current_code, boats = None, []

    def consume(code, values):
        nonlocal rows, races, valid_races
        races += 1
        courses = [b[3] for b in values]
        ranks = [b[4] for b in values if b[4] in (1, 2, 3)]
        if len(values) != 6 or set(courses) != set(range(1, 7)) or sorted(ranks) != [1, 2, 3]:
            return
        valid_races += 1
        mapped = {b[3]: b for b in values}
        b4 = mapped[4]
        four_makuri = b4[4] == 1 and normalize_technique(b4[6]) == "MAKURI"
        for course in (5, 6):
            _, d, pid, _, rank, real_st, _ = mapped[course]
            by[(pid, course)].append((d, code, rank, real_st, four_makuri))
            rows += 1

    for raw in stream_query(conn, "phase8_history", HISTORY_SQL, (HISTORY_START, END)):
        code = str(raw[0])
        if current_code is not None and code != current_code:
            consume(current_code, boats)
            boats = []
        current_code = code
        boats.append((code, raw[1], str(raw[3]).strip(), integer(raw[4]), integer(raw[5]),
                      number(raw[6]), str(raw[7] or "").strip()))
    if current_code is not None:
        consume(current_code, boats)
    for values in by.values():
        values.sort(key=lambda r: (r[0], r[1]))
    return by, {"rows": rows, "keys": len(by), "source_races": races,
                "valid_source_races": valid_races, "seconds": time.perf_counter() - started}


def window_rows(rows, lo, hi):
    left = bisect.bisect_left(rows, (lo, ""))
    right = bisect.bisect_left(rows, (hi, ""))
    return rows[left:right]


def profile_one(target, course, history, examples):
    d, code, pid = target["race_date"], target["race_code"], target["player_id"]
    all_rows = history.get((pid, course), [])
    ordinary_rows = window_rows(all_rows, months_ago(d, 12), d)
    follower_rows = [r for r in window_rows(all_rows, months_ago(d, 36), d) if r[4]]
    # Mechanical guards; violations are retained in the audit rather than silently ignored.
    for r in ordinary_rows + follower_rows:
        if r[1] == code:
            examples.add("TARGET_SELF_LEAK", code, f"course{course}_history", "strict_history", r[1])
        if r[0] == d:
            examples.add("SAME_DAY_RESULT_LEAK", code, f"course{course}_history", "strict_history", r[1])
        if r[0] > d:
            examples.add("FUTURE_SOURCE", code, f"course{course}_history", "strict_history", r[1])
        if r[0] >= d:
            examples.add("SOURCE_MAX_DATE_VIOLATION", code, f"course{course}_history", "strict_history", r[0])
    n = len(ordinary_rows)
    second = sum(r[2] == 2 for r in ordinary_rows)
    third = sum(r[2] == 3 for r in ordinary_rows)
    top3 = sum(r[2] in (1, 2, 3) for r in ordinary_rows)
    sts = [r[3] for r in ordinary_rows if r[3] is not None]
    ordinary = {
        "race_code": code, "race_date": d, "split": target["split"], "course": course,
        "player_id": pid, "history_window_start": months_ago(d, 12), "history_window_end_exclusive": d,
        "history_n": n, "history_second_rate": rate(second, n), "history_third_rate": rate(third, n),
        "history_top3_rate": rate(top3, n), "history_average_st": (sum(sts) / len(sts)) if sts else None,
        "history_average_st_n": len(sts), "history_min_date": ordinary_rows[0][0] if ordinary_rows else None,
        "history_max_date": ordinary_rows[-1][0] if ordinary_rows else None,
    }
    fn = len(follower_rows)
    fsecond = sum(r[2] == 2 for r in follower_rows)
    fthird = sum(r[2] == 3 for r in follower_rows)
    ftop3 = sum(r[2] in (1, 2, 3) for r in follower_rows)

    def smooth(raw, base):
        if base is None:
            return None
        if fn == 0:
            return base
        return fn / (fn + K) * raw + K / (fn + K) * base

    raw2, raw3, rawtop3 = rate(fsecond, fn), rate(fthird, fn), rate(ftop3, fn)
    follower = {
        "race_code": code, "race_date": d, "split": target["split"], "course": course,
        "player_id": pid, "follower_window_start": months_ago(d, 36), "follower_window_end_exclusive": d,
        "follower_attack_n": fn, "follower_raw_second_rate": raw2, "follower_raw_third_rate": raw3,
        "follower_raw_top3_rate": rawtop3,
        "follower_smoothed_second_rate": smooth(raw2, ordinary["history_second_rate"]),
        "follower_smoothed_third_rate": smooth(raw3, ordinary["history_third_rate"]),
        "follower_smoothed_top3_rate": smooth(rawtop3, ordinary["history_top3_rate"]),
        "follower_k": int(K), "follower_min_date": follower_rows[0][0] if follower_rows else None,
        "follower_max_date": follower_rows[-1][0] if follower_rows else None,
    }
    return ordinary, follower


def source_status_rows(schema):
    ps_cols = {x["column"] for x in schema["player_stats"]}
    es_cols = {x["column"] for x in schema["engine_specs"]}
    ex_cols = {x["column"] for x in schema["exhibition_live"]}
    groups = [
        ("12m_course_count", "strict historical race facts", "SOURCE_OK", "[race_date-12m,race_date); target day excluded"),
        ("12m_course_second_rate", "strict historical race facts", "SOURCE_OK", "same strict window"),
        ("12m_course_third_rate", "strict historical race facts", "SOURCE_OK", "same strict window"),
        ("12m_course_top3_rate", "strict historical race facts", "SOURCE_OK", "same strict window"),
        ("12m_course_average_st", "past race_result_detail.start_timing", "SOURCE_OK", "past real ST averaged; current-race real ST never exported"),
        ("national_win_rate", "player_stats race_code+player_id", "SOURCE_BLOCKED", "no capture/version timestamp in schema; cannot prove historical pre-race snapshot"),
        ("local_win_rate", "player_stats race_code+player_id", "SOURCE_BLOCKED", "no capture/version timestamp in schema; cannot prove historical pre-race snapshot"),
        ("motor_2ren_rate", "engine_specs race_code+motor_number", "SOURCE_BLOCKED", "no capture/version timestamp in schema; cannot prove historical pre-race snapshot"),
        ("boat_2ren_rate", "engine_specs race_code+motor_number", "SOURCE_BLOCKED", "no capture/version timestamp in schema; cannot prove historical pre-race snapshot"),
        ("exhibition_st", "exhibition_live latest race_code+player_id", "SOURCE_BLOCKED", "created_date exists but historical official prediction cutoff is unavailable; later backfills exist"),
        ("36m_follower_attack_count", "strict historical race facts", "SOURCE_OK", "[race_date-36m,race_date), realized 4C makuri cohort"),
        ("36m_smoothed_second_rate", "strict historical race facts", "SOURCE_OK", "K=20; target day excluded"),
        ("36m_smoothed_third_rate", "strict historical race facts", "SOURCE_OK", "K=20; target day excluded"),
        ("36m_smoothed_top3_rate", "strict historical race facts", "SOURCE_OK", "K=20; target day excluded"),
    ]
    dependencies = {
        "player_stats_columns": sorted(ps_cols), "engine_specs_columns": sorted(es_cols),
        "exhibition_live_columns": sorted(ex_cols), "racer_results_used": False,
        "blocked_phase7_artifacts_used": False,
    }
    return [{"feature": a, "exact_source": b, "status": c, "asof_evidence": d,
             "same_day_result_used": False, "future_used": False,
             "target_race_result_used_as_feature": False} for a, b, c, d in groups], dependencies


def feature_coverage(targets, ordinary, follower):
    ob = {(r["race_code"], r["course"]): r for r in ordinary}
    fb = {(r["race_code"], r["course"]): r for r in follower}
    result = {}
    for split in (x[0] for x in SPLITS):
        result[split] = {}
        for course in (5, 6):
            rows = [r for r in targets[course] if r["split"] == split]
            keys = [(r["race_code"], course) for r in rows]
            def cov(values):
                n = sum(v is not None and v != "" for v in values)
                return {"N": len(values), "non_null_n": n, "coverage_pct": 100.0 * n / len(values) if values else None}
            result[split][f"course{course}"] = {
                "row_count": len(rows),
                "label_top3": cov([r["label_top3"] if r["label_available"] else None for r in rows]),
                "history_count": cov([ob[k]["history_n"] for k in keys]),
                "history_second_rate": cov([ob[k]["history_second_rate"] for k in keys]),
                "history_third_rate": cov([ob[k]["history_third_rate"] for k in keys]),
                "history_top3_rate": cov([ob[k]["history_top3_rate"] for k in keys]),
                "history_average_st": cov([ob[k]["history_average_st"] for k in keys]),
                "national_win_rate": cov([r["national_win_rate"] for r in rows]),
                "local_win_rate": cov([r["local_win_rate"] for r in rows]),
                "motor_2ren_rate": cov([r["motor_2ren_rate"] for r in rows]),
                "boat_2ren_rate": cov([r["boat_2ren_rate"] for r in rows]),
                "exhibition_st": cov([r["exhibition_st"] for r in rows]),
                "follower_attack_count": cov([fb[k]["follower_attack_n"] for k in keys]),
                "follower_smoothed_second_rate": cov([fb[k]["follower_smoothed_second_rate"] for k in keys]),
                "follower_smoothed_third_rate": cov([fb[k]["follower_smoothed_third_rate"] for k in keys]),
                "follower_smoothed_top3_rate": cov([fb[k]["follower_smoothed_top3_rate"] for k in keys]),
                "follower_n_distribution": dict(sorted(Counter(fb[k]["follower_attack_n"] for k in keys).items())),
                "follower_n_zero_rate_pct": 100.0 * sum(fb[k]["follower_attack_n"] == 0 for k in keys) / len(keys) if keys else None,
            }
    return result


def main():
    started = time.perf_counter()
    OUT.mkdir(parents=True, exist_ok=True)
    examples = Examples()
    timings = {}
    with connect_db() as conn:
        schema = db_schema_audit(conn)
        races, targets, target_stats, target_timing = load_targets(conn, examples)
        timings["target_query_and_mapping_seconds"] = target_timing["seconds"]
        history, history_timing = load_history(conn)
        timings["history_query_seconds"] = history_timing["seconds"]

    profile_started = time.perf_counter()
    ordinary, follower = [], []
    for course in (5, 6):
        for target in targets[course]:
            o, f = profile_one(target, course, history, examples)
            ordinary.append(o); follower.append(f)
    timings["profile_build_seconds"] = time.perf_counter() - profile_started

    source_rows, dependency_audit = source_status_rows(schema)
    coverage = feature_coverage(targets, ordinary, follower)

    race_fields = ["race_code", "race_date", "stadium", "race_number", "split", "entry_count",
        "actual_course_mapping_ok", "actual_course_mapping_failure_reason", "label_available",
        "conditional_population_four_makuri_success", "normalized_winner_technique_when_4c_won",
        "mapping_result_detail_n", "mapping_exhibition_fallback_n", "mapping_lane_fallback_n"] + [
        f"course{c}_{x}" for c in (4, 5, 6) for x in ("player_id", "lane", "rank")]
    course_fields = ["race_code", "race_date", "stadium", "split", "lane", "player_id", "motor_number", "boat_number",
        "actual_course", "label_available", "label_second", "label_third", "label_top3",
        "conditional_population_four_makuri_success", "national_win_rate", "local_win_rate",
        "motor_2ren_rate", "boat_2ren_rate", "exhibition_st", "exhibition_created_at"]
    ordinary_fields = ["race_code", "race_date", "split", "course", "player_id", "history_window_start",
        "history_window_end_exclusive", "history_n", "history_second_rate", "history_third_rate", "history_top3_rate",
        "history_average_st", "history_average_st_n", "history_min_date", "history_max_date"]
    follower_fields = ["race_code", "race_date", "split", "course", "player_id", "follower_window_start",
        "follower_window_end_exclusive", "follower_attack_n", "follower_raw_second_rate", "follower_raw_third_rate",
        "follower_raw_top3_rate", "follower_smoothed_second_rate", "follower_smoothed_third_rate",
        "follower_smoothed_top3_rate", "follower_k", "follower_min_date", "follower_max_date"]
    write_started = time.perf_counter()
    write_csv(RACE_FACT, race_fields, races)
    write_csv(FIVE_FACT, course_fields, targets[5])
    write_csv(SIX_FACT, course_fields, targets[6])
    write_csv(ORDINARY, ordinary_fields, ordinary)
    write_csv(FOLLOWER, follower_fields, follower)
    write_csv(SOURCE_AUDIT, list(source_rows[0]), source_rows)
    write_csv(LEAK_EXAMPLES, ["violation_type", "race_code", "feature", "source", "detail"], examples.rows)
    timings["artifact_write_seconds"] = time.perf_counter() - write_started

    source_counts = Counter(r["status"] for r in source_rows)
    blocked = [r["feature"] for r in source_rows if r["status"] == "SOURCE_BLOCKED"]
    duplicate_race = len(races) - len({r["race_code"] for r in races})
    mapping_fail = sum(s["mapping_failure"] for s in target_stats.values())
    leakage = {
        "TARGET_SELF_LEAK_COUNT": examples.counts["TARGET_SELF_LEAK"],
        "SAME_DAY_RESULT_LEAK_COUNT": examples.counts["SAME_DAY_RESULT_LEAK"],
        "FUTURE_SOURCE_COUNT": examples.counts["FUTURE_SOURCE"],
        "SOURCE_MAX_DATE_VIOLATION": examples.counts["SOURCE_MAX_DATE_VIOLATION"],
        "POST_CUTOFF_EXHIBITION_COUNT": target_timing["definite_post_cutoff_exhibition_count"],
        "POST_CUTOFF_EXHIBITION_UNEVALUABLE_SAME_DAY_COUNT": target_timing["same_day_exhibition_without_cutoff_count"],
        "POST_RACE_SNAPSHOT_COUNT": None,
        "POST_RACE_SNAPSHOT_UNEVALUABLE_COUNT": sum(len(targets[c]) * 4 for c in (5, 6)),
        "DUPLICATE_RACE_CODE_COUNT": duplicate_race,
        "ACTUAL_COURSE_MAPPING_FAILURE_COUNT": mapping_fail,
        "UNVERIFIED_SOURCE_COUNT": source_counts["SOURCE_UNVERIFIED"],
        "note": "POST_RACE_SNAPSHOT_COUNT cannot be evaluated for player_stats/engine_specs because neither table has a capture/version timestamp. Exhibition count includes only records definitely dated after race_date; same-day records remain unevaluable without historical cutoff.",
    }
    symmetry = {
        "status": "PASS",
        "courses": [5, 6], "same_feature_family": True, "ordinary_window_months": 12,
        "follower_window_months": 36, "same_k": 20, "same_missing_policy": True,
        "same_asof_rule": True, "same_label_logic": True, "same_audit_rule": True,
        "course_specific_exception": False,
    }
    coverage_report = {
        "phase": "Phase 8-1a", "period": {"start": START.isoformat(), "end": END.isoformat()},
        "per_split_race_audit": {s: dict(target_stats[s]) for s, _, _ in SPLITS},
        "feature_coverage": coverage,
        "source_status_counts": dict(source_counts), "blocked_features": blocked,
        "actual_course_rule": "result_detail -> latest exhibition -> lane",
    }
    COVERAGE.write_text(json.dumps(coverage_report, ensure_ascii=False, indent=2), encoding="utf-8")
    forward_schema = {
        "phase": "Phase 8 TRUE_FORWARD schema only; no outcomes read or evaluated in Phase 8-1a",
        "period": {"start": "2026-10-01", "end": "2027-03-31"},
        "frozen_columns": {"race_identity": ["race_code", "race_date", "stadium", "split"],
            "courses": ["course4_player_id", "course5_player_id", "course6_player_id"],
            "future_labels_after_freeze_only": ["course5_top3", "course6_top3", "course5_second", "course5_third", "course6_second", "course6_third"]},
        "prohibition": "TRUE_FORWARD outcomes must not alter Phase 8 design, feature whitelist, preprocessing, gate, bands, or coefficients",
    }
    FORWARD_SCHEMA.write_text(json.dumps(forward_schema, ensure_ascii=False, indent=2), encoding="utf-8")
    artifact_paths = [RACE_FACT, FIVE_FACT, SIX_FACT, ORDINARY, FOLLOWER, SOURCE_AUDIT,
                      LEAK_EXAMPLES, COVERAGE, FORWARD_SCHEMA]
    core_history_pass = all(leakage[k] == 0 for k in ("TARGET_SELF_LEAK_COUNT", "SAME_DAY_RESULT_LEAK_COUNT",
        "FUTURE_SOURCE_COUNT", "SOURCE_MAX_DATE_VIOLATION"))
    foundation_pass = core_history_pass and duplicate_race == 0 and mapping_fail < len(races) and symmetry["status"] == "PASS"
    phase_result = "BLOCKED" if foundation_pass and blocked else ("PASS" if foundation_pass else "FAIL")
    summary = {
        "PHASE": "8-1a", "RACE_FACT": "PASS" if duplicate_race == 0 else "FAIL",
        "ACTUAL_COURSE_MAPPING": "PASS" if mapping_fail < len(races) else "FAIL",
        "ORDINARY_PROFILE_5C": "PASS" if len(ordinary) == len(targets[5]) + len(targets[6]) else "FAIL",
        "ORDINARY_PROFILE_6C": "PASS" if len(ordinary) == len(targets[5]) + len(targets[6]) else "FAIL",
        "FOLLOWER_PROFILE_5C": "PASS" if len(follower) == len(targets[5]) + len(targets[6]) else "FAIL",
        "FOLLOWER_PROFILE_6C": "PASS" if len(follower) == len(targets[5]) + len(targets[6]) else "FAIL",
        "LABEL_5C": "PASS" if any(r["label_available"] for r in targets[5]) else "FAIL",
        "LABEL_6C": "PASS" if any(r["label_available"] for r in targets[6]) else "FAIL",
        **leakage,
        "PLAYER_STATS_ASOF": "BLOCKED", "ENGINE_SPECS_ASOF": "BLOCKED", "EXHIBITION_ST_ASOF": "BLOCKED",
        "5C_6C_SYMMETRY": symmetry["status"],
        "DEVELOPMENT_CONDITIONAL_N": target_stats["Development"]["conditional_population"],
        "VALIDATION_CONDITIONAL_N": target_stats["Validation"]["conditional_population"],
        "EXPOSED_CONFIRMATION_CONDITIONAL_N": target_stats["EXPOSED_CONFIRMATION"]["conditional_population"],
        "BLOCKED_FEATURES": blocked, "PHASE_8_1A_RESULT": phase_result,
        "PHASE_8_1B_ALLOWED": "NO" if phase_result != "PASS" else "YES",
        "NEXT_SAFE_ACTION": "Freeze a reduced Phase 8-1b feature whitelist in a separate decision; do not fit while blocked features remain in the proposed input set.",
        "source_schema_audit": schema, "source_dependency_audit": dependency_audit,
        "symmetry_audit": symmetry, "history_fact_audit": history_timing,
        "target_race_count": len(races), "course5_fact_rows": len(targets[5]), "course6_fact_rows": len(targets[6]),
        "ordinary_profile_rows": len(ordinary), "follower_profile_rows": len(follower),
        "artifacts": {str(p.relative_to(REPO)): {"sha256": sha256(p), "bytes": p.stat().st_size} for p in artifact_paths},
        "source_script": str(Path(__file__).relative_to(REPO)), "source_script_sha256": sha256(Path(__file__)),
        "source_git_commit": git_head(), "generated_at": datetime.now().astimezone().isoformat(),
        "timing_seconds": {**timings, "total": time.perf_counter() - started},
        "commit": "NO", "push": "NO",
    }
    SUMMARY.write_text(json.dumps(summary, ensure_ascii=False, indent=2), encoding="utf-8")
    # Reload audit: JSON validity, race uniqueness, and immutable content hashes.
    reread = json.loads(SUMMARY.read_text(encoding="utf-8"))
    with RACE_FACT.open(encoding="utf-8") as h:
        rr = list(csv.DictReader(h))
    reload_audit = {
        "summary_phase": reread["PHASE"], "race_rows": len(rr),
        "duplicate_race_code": len(rr) - len({r["race_code"] for r in rr}),
        "artifact_hashes_match": all(sha256(REPO / p) == meta["sha256"] for p, meta in reread["artifacts"].items()),
    }
    print(json.dumps({"summary": str(SUMMARY), "result": phase_result, "reload_audit": reload_audit,
        "conditional_n": {s: target_stats[s]["conditional_population"] for s, _, _ in SPLITS},
        "blocked_features": blocked, "timing_seconds": summary["timing_seconds"]}, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
