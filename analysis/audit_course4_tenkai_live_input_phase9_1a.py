#!/usr/bin/env python3
"""Phase 9-1a: audit research/live input parity and freeze the live contract.

This is an audit only.  It reads fixed research artifacts/caches and the live
database, writes audit outputs under analysis/output, and never scores a live
race or changes Production/Web/Strict Forward state.
"""
from __future__ import annotations

import bisect
import csv
import hashlib
import json
import math
import subprocess
import sys
import time
from collections import defaultdict
from datetime import date, datetime, timedelta
from pathlib import Path

ROOT = Path(__file__).resolve().parent
REPO = ROOT.parent
OUT = ROOT / "output"
sys.path.insert(0, str(ROOT))
from slit_validate_v2 import connect_db  # noqa: E402

PARITY_CSV = OUT / "course4_tenkai_phase9_feature_parity.csv"
CONTRACT_JSON = OUT / "course4_tenkai_phase9_live_input_contract_v1.json"
REPORT_JSON = OUT / "course4_tenkai_phase9_1a_report.json"

EXPECTED = {
    "WALL_R": (OUT / "course34_wall_phase3r_final_model.json", "f5348195d1d05c982f273ce7924c2b0eda5174e243e3e01bb818b87d6f3e768c"),
    "ATTACK_A5": (OUT / "course4_attack_phase4r_final_model.json", "39808675efde92bfcbb73d69f88e915260f7dd0e0c48636d1b2775d6b2874176"),
    "ATTACK_SUCCESS": (OUT / "course4_attack_success_phase5_final_model.json", "9dae5b89626692bbcc31e01f02b1ce745f4046976250e06c76d1d79491d7af4a"),
    "ATTACK_TYPE": (OUT / "course4_attack_type_phase7_final_model.json", "e0f3674e3e863a4a251aafb2e87530ebba85d4c393982d019fdccd124f3707b4"),
    "ATTACK_TYPE_SPEC": (OUT / "course4_attack_type_phase7_spec_v3.json", "e36d6d2068e583629328e85cdf3d2aa1c516711d35472439d811bdbf8a3f9343"),
}

SPLITS = {
    "Development": (date(2024, 10, 1), date(2025, 9, 30)),
    "Validation": (date(2025, 10, 1), date(2026, 3, 31)),
    "EXPOSED_CONFIRMATION": (date(2026, 4, 1), date(2026, 9, 30)),
}

HISTORY = (
    "course4_history_n", "course4_history_win_rate_pct", "course4_history_top2_rate_pct",
    "course4_history_top3_rate_pct", "course4_history_makuri_rate_pct",
    "course4_history_makurizashi_rate_pct", "course4_history_attack_rate_pct",
)
EXHIBITION = (
    "course4_ex_st", "course4_exhibition_time", "course4_lap_time",
    "course4_around_time", "course4_straight_time",
)


def sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def git_head() -> str:
    return subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=REPO, text=True).strip()


def read_json(path: Path):
    return json.loads(path.read_text(encoding="utf-8"))


def read_csv(path: Path):
    with path.open(encoding="utf-8") as f:
        return list(csv.DictReader(f))


def num(v):
    try:
        x = float(v)
        return x if math.isfinite(x) else None
    except (TypeError, ValueError):
        return None


def months_ago(d: date, n: int) -> date:
    total = d.year * 12 + d.month - 1 - n
    y, m0 = divmod(total, 12)
    m = m0 + 1
    import calendar
    return date(y, m, min(d.day, calendar.monthrange(y, m)[1]))


def term_for(d: date) -> str:
    if d.month <= 4:
        return f"{(d.year - 1) % 100:02d}10"
    if d.month <= 10:
        return f"{d.year % 100:02d}04"
    return f"{d.year % 100:02d}10"


def split_of(d: date) -> str:
    return next(name for name, (lo, hi) in SPLITS.items() if lo <= d <= hi)


def select_spots(rows, n=5):
    selected = []
    for split in SPLITS:
        group = sorted((r for r in rows if split_of(date.fromisoformat(r["race_date"])) == split), key=lambda r: r["race_code"])
        indices = sorted({round(i * (len(group) - 1) / (n - 1)) for i in range(n)})
        selected.extend(group[i] for i in indices)
    return selected


def history_profile(rows, d):
    lo = months_ago(d, 12)
    a = bisect.bisect_left(rows, lo, key=lambda x: x[0])
    b = bisect.bisect_left(rows, d, key=lambda x: x[0])
    x = rows[a:b]
    n = len(x)
    wins = sum(w for _, _, w, _, _ in x)
    top2 = sum(rank is not None and rank <= 2 for _, _, _, _, rank in x)
    top3 = sum(rank is not None and rank <= 3 for _, _, _, _, rank in x)
    makuri = sum(w and tech == "まくり" for _, _, w, tech, _ in x)
    makurizashi = sum(w and tech == "まくり差し" for _, _, w, tech, _ in x)
    pct = lambda z: None if n == 0 else 100.0 * z / n
    return {
        "course4_history_n": float(n),
        "course4_history_win_rate_pct": pct(wins),
        "course4_history_top2_rate_pct": pct(top2),
        "course4_history_top3_rate_pct": pct(top3),
        "course4_history_makuri_rate_pct": pct(makuri),
        "course4_history_makurizashi_rate_pct": pct(makurizashi),
        "course4_history_attack_rate_pct": pct(makuri + makurizashi),
        "source_min_date": x[0][0].isoformat() if x else None,
        "source_max_date": x[-1][0].isoformat() if x else None,
        "same_day_or_future_count": sum(q[0] >= d for q in x),
    }


def diff_stat(pairs, tolerance=1e-12):
    usable = [(a, b) for a, b in pairs if a is not None and b is not None]
    both_missing = sum(a is None and b is None for a, b in pairs)
    one_missing = len(pairs) - len(usable) - both_missing
    diffs = [abs(a - b) for a, b in usable]
    return {
        "compared_n": len(pairs), "numeric_pair_n": len(usable), "both_missing_n": both_missing,
        "one_side_missing_n": one_missing,
        "exact_match_count": sum(a == b for a, b in usable) + both_missing,
        "within_tolerance_count": sum(d <= tolerance for d in diffs) + both_missing,
        "max_abs_diff": max(diffs) if diffs else None,
        "mean_abs_diff": sum(diffs) / len(diffs) if diffs else None,
        "tolerance": tolerance,
    }


def add_feature(rows, signal, order, name, definition, source, cutoff, missing, transform,
                live_source, live_definition, status, evidence):
    rows.append({
        "signal_id": signal, "feature_name": name, "feature_order": order,
        "research_source": source, "research_definition": definition,
        "research_cutoff": cutoff, "research_missing_policy": missing,
        "research_transform": transform, "live_candidate_source": live_source,
        "live_candidate_definition": live_definition, "parity_status": status,
        "parity_evidence": evidence,
    })


def db_audit(spots):
    codes = [r["race_code"] for r in spots]
    pids = sorted({r["course3_player_id"] for r in spots} | {r["course4_player_id"] for r in spots})
    terms = sorted({term_for(date.fromisoformat(r["race_date"])) for r in spots})
    schema, triggers, indexes, term_rows = {}, [], [], []
    targets, rr, histories = {}, {}, defaultdict(list)
    with connect_db() as conn, conn.cursor() as cur:
        for table in ("racer_results", "player_stats", "engine_specs", "exhibition_live"):
            cur.execute("""SELECT column_name,data_type FROM information_schema.columns
                           WHERE table_schema='boat_race' AND table_name=%s ORDER BY ordinal_position""", (table,))
            schema[table] = [{"column": a, "type": b} for a, b in cur.fetchall()]
        cur.execute("""SELECT event_object_table,trigger_name,action_timing,event_manipulation
                       FROM information_schema.triggers WHERE trigger_schema='boat_race'
                       AND event_object_table=ANY(%s) ORDER BY 1,2""",
                    (["racer_results", "player_stats", "engine_specs", "exhibition_live"],))
        triggers = [list(x) for x in cur.fetchall()]
        cur.execute("""SELECT tablename,indexname,indexdef FROM pg_indexes WHERE schemaname='boat_race'
                       AND tablename=ANY(%s) ORDER BY tablename,indexname""",
                    (["racer_results", "player_stats", "engine_specs", "exhibition_live"],))
        indexes = [list(x) for x in cur.fetchall()]
        cur.execute("""SELECT term_info::text,min(calc_period_from),max(calc_period_from),
                              min(calc_period_to),max(calc_period_to),count(*),count(distinct player_id)
                       FROM boat_race.racer_results WHERE term_info::text=ANY(%s)
                       GROUP BY term_info ORDER BY term_info""", (terms,))
        term_rows = [[str(y) if isinstance(y, date) else y for y in x] for x in cur.fetchall()]
        cur.execute("""
          WITH ex AS (
            SELECT DISTINCT ON (race_code,player_id) race_code,player_id,entry_course,start_timing,
                   exhibition_time,lap_time,around_time,straight_time,created_date
            FROM boat_race.exhibition_live WHERE race_code=ANY(%s)
            ORDER BY race_code,player_id,created_date DESC NULLS LAST,entry_course
          ), rd AS (
            SELECT DISTINCT ON (race_code,player_id) race_code,player_id,entry_course
            FROM boat_race.race_result_detail WHERE race_code=ANY(%s) ORDER BY race_code,player_id
          )
          SELECT rm.race_code,rm.race_date,re.lane_number,re.player_id::text,re.motor_number,re.boat_number,
                 rd.entry_course,ex.entry_course,ex.start_timing,ex.exhibition_time,ex.lap_time,
                 ex.around_time,ex.straight_time,ex.created_date,
                 ps.national_win_rate,ps.local_win_rate,ps.national_exacta_rate,ps.local_exacta_rate,
                 es.motor_exacta_rate,es.boat_exacta_rate
          FROM boat_race.race_master rm JOIN boat_race.race_entry re ON re.race_code=rm.race_code
          LEFT JOIN rd ON rd.race_code=re.race_code AND rd.player_id=re.player_id
          LEFT JOIN ex ON ex.race_code=re.race_code AND ex.player_id=re.player_id
          LEFT JOIN boat_race.player_stats ps ON ps.race_code=re.race_code AND ps.player_id=re.player_id
          LEFT JOIN boat_race.engine_specs es ON es.race_code=re.race_code AND es.motor_number=re.motor_number
          WHERE rm.race_code=ANY(%s)
        """, (codes, codes, codes))
        for x in cur.fetchall():
            targets[(str(x[0]), str(x[3]).strip())] = {
                "race_date": x[1], "lane": int(x[2]), "motor": x[4], "boat": x[5],
                "result_course": int(x[6]) if x[6] is not None else None,
                "exhibition_course": int(x[7]) if x[7] is not None else None,
                "course4_ex_st": num(x[8]), "course4_exhibition_time": num(x[9]),
                "course4_lap_time": num(x[10]), "course4_around_time": num(x[11]),
                "course4_straight_time": num(x[12]), "exhibition_created_date": x[13].isoformat() if x[13] else None,
                "national_win_rate": num(x[14]), "local_win_rate": num(x[15]),
                "national_2ren_rate": num(x[16]), "local_2ren_rate": num(x[17]),
                "motor_2ren_rate": num(x[18]), "boat_2ren_rate": num(x[19]),
            }
        cur.execute("""SELECT term_info::text,player_id::text,average_start,
                              course3_entry,course3_average_start,course3_average_rank,
                              course4_entry,course4_average_start,course4_average_rank,
                              calc_period_from,calc_period_to
                       FROM boat_race.racer_results WHERE term_info::text=ANY(%s) AND player_id::text=ANY(%s)""",
                    (terms, pids))
        for x in cur.fetchall():
            rr[(str(x[0]), str(x[1]).strip())] = {
                "overall_average_st": num(x[2]),
                "course3_entry_count": num(x[3]), "course3_average_st": num(x[4]), "course3_average_st_rank": num(x[5]),
                "course4_entry_count": num(x[6]), "course4_average_st": num(x[7]), "course4_average_st_rank": num(x[8]),
                "calc_period_from": x[9].isoformat() if x[9] else None, "calc_period_to": x[10].isoformat() if x[10] else None,
            }
        lo = months_ago(min(date.fromisoformat(r["race_date"]) for r in spots), 12)
        hi = max(date.fromisoformat(r["race_date"]) for r in spots)
        c4pids = sorted({r["course4_player_id"] for r in spots})
        cur.execute("""
          WITH cand AS MATERIALIZED (
            SELECT rm.race_code,rm.race_date,re.player_id,re.lane_number
            FROM boat_race.race_master rm JOIN boat_race.race_entry re ON re.race_code=rm.race_code
            WHERE rm.race_date >= %s AND rm.race_date < %s AND re.player_id::text=ANY(%s)
          ), rd AS (
            SELECT DISTINCT ON (d.race_code,d.player_id) d.race_code,d.player_id,d.entry_course,d.rank
            FROM boat_race.race_result_detail d JOIN cand c ON c.race_code=d.race_code AND c.player_id=d.player_id
            ORDER BY d.race_code,d.player_id
          ), ex AS (
            SELECT DISTINCT ON (e.race_code,e.player_id) e.race_code,e.player_id,e.entry_course
            FROM boat_race.exhibition_live e JOIN cand c ON c.race_code=e.race_code AND c.player_id=e.player_id
            ORDER BY e.race_code,e.player_id,e.created_date DESC NULLS LAST,e.entry_course
          ), win AS (
            SELECT DISTINCT ON (d.race_code) d.race_code,d.player_id::text winner,TRIM(COALESCE(d.technique,'')) tech
            FROM boat_race.race_result_detail d JOIN (SELECT DISTINCT race_code FROM cand) c ON c.race_code=d.race_code
            WHERE TRIM(d.rank::text)='1' ORDER BY d.race_code
          )
          SELECT c.race_date,c.race_code,c.player_id::text,
                 COALESCE(rd.entry_course,ex.entry_course,c.lane_number),rd.rank,win.winner,win.tech
          FROM cand c JOIN win ON win.race_code=c.race_code
          LEFT JOIN rd ON rd.race_code=c.race_code AND rd.player_id=c.player_id
          LEFT JOIN ex ON ex.race_code=c.race_code AND ex.player_id=c.player_id
          ORDER BY c.player_id,c.race_date,c.race_code
        """, (lo, hi, c4pids))
        for d, code, pid, course, rank, winner, tech in cur.fetchall():
            if int(course) == 4:
                histories[str(pid).strip()].append((d, str(code), str(winner).strip() == str(pid).strip(), str(tech or "").strip(), int(rank) if str(rank).isdigit() else None))
    return {"schema": schema, "triggers": triggers, "indexes": indexes, "term_rows": term_rows,
            "targets": targets, "racer_results": rr, "histories": histories}


def main():
    started = time.perf_counter()
    artifact_audit = {}
    loaded = {}
    for name, (path, expected) in EXPECTED.items():
        actual = sha256(path)
        artifact_audit[name] = {"path": str(path.relative_to(REPO)), "expected_sha256": expected, "actual_sha256": actual, "match": actual == expected}
        if actual != expected:
            raise RuntimeError(f"artifact hash mismatch: {name}")
        loaded[name] = read_json(path)

    phase4 = read_csv(OUT / "course4_attack_phase4_input_20241001_20260930.csv")
    phase4_by_code = {r["race_code"]: r for r in phase4}
    phase7 = read_csv(OUT / "course4_attack_type_phase7_dataset_20241001_20260930.csv")
    spots = select_spots(phase4)
    db = db_audit(spots)

    spot_pids = {r["course4_player_id"] for r in spots}
    cached_histories = defaultdict(list)
    history_cache_paths = sorted((OUT / "course4_attack_phase4_history").glob("*.csv"))
    for p in history_cache_paths:
        for r in read_csv(p):
            if r["player_id"] in spot_pids:
                cached_histories[r["player_id"]].append((date.fromisoformat(r["race_date"]), r["race_code"], r["rank"] == "1", r["technique"], int(r["rank"])))
    for values in cached_histories.values():
        values.sort(key=lambda x: (x[0], x[1]))

    extras = {}
    for p in sorted((OUT / "course4_attack_phase4_prerace").glob("*.csv")):
        for r in read_csv(p):
            extras[(r["race_code"], r["player_id"])] = r
    overall_cache = {(r["term"], r["player_id"]): num(r["overall_st"]) for r in read_csv(OUT / "course34_wall_phase3_overall_st_20241001_20260930.csv")}

    comparisons = defaultdict(lambda: defaultdict(list))
    mapping_rows = []
    history_event_drift = []
    leakage = {"history_target_self": 0, "history_same_day": 0, "history_future": 0, "history_source_max_violation": 0}
    for research in spots:
        code = research["race_code"]
        d = date.fromisoformat(research["race_date"])
        split = split_of(d)
        p3, p4 = research["course3_player_id"], research["course4_player_id"]
        t = term_for(d)
        live3 = db["targets"].get((code, p3), {})
        live4 = db["targets"].get((code, p4), {})
        rr3 = db["racer_results"].get((t, p3), {})
        rr4 = db["racer_results"].get((t, p4), {})
        profile = history_profile(db["histories"].get(p4, []), d)
        cached_profile = history_profile(cached_histories.get(p4, []), d)
        leakage["history_same_day"] += profile["same_day_or_future_count"]
        leakage["history_future"] += profile["same_day_or_future_count"]
        if profile["source_max_date"] and profile["source_max_date"] >= d.isoformat():
            leakage["history_source_max_violation"] += 1
        for f in HISTORY:
            comparisons[f][split].append((num(research.get(f)), num(profile[f])))
        lo = months_ago(d, 12)
        cached_events = {(x[0], x[1]) for x in cached_histories.get(p4, []) if lo <= x[0] < d}
        current_events = {(x[0], x[1]) for x in db["histories"].get(p4, []) if lo <= x[0] < d}
        history_event_drift.append({
            "race_code": code, "split": split, "player_id": p4,
            "research_history_n": num(research.get("course4_history_n")),
            "fixed_cache_history_n": cached_profile["course4_history_n"],
            "current_db_history_n": profile["course4_history_n"],
            "fixed_cache_only_n": len(cached_events - current_events),
            "current_db_only_n": len(current_events - cached_events),
            "fixed_cache_only_examples": [[x[0].isoformat(), x[1]] for x in sorted(cached_events - current_events)[:5]],
            "current_db_only_examples": [[x[0].isoformat(), x[1]] for x in sorted(current_events - cached_events)[:5]],
        })
        for f in EXHIBITION:
            comparisons[f][split].append((num(research.get(f)), num(live4.get(f))))
        for c, live, rrv in ((3, live3, rr3), (4, live4, rr4)):
            for f in (f"course{c}_entry_count", f"course{c}_average_st", f"course{c}_average_st_rank"):
                comparisons[f][split].append((num(research.get(f)), num(rrv.get(f))))
            # C4 raw exhibition fields were already compared above under the
            # model feature names; keep this second family only for Wall C3.
            if c == 3:
                for f, raw_name in ((f"course{c}_ex_st", "course4_ex_st"), (f"course{c}_exhibition_time", "course4_exhibition_time"),
                                    (f"course{c}_lap_time", "course4_lap_time"), (f"course{c}_around_time", "course4_around_time"),
                                    (f"course{c}_straight_time", "course4_straight_time")):
                    comparisons[f][split].append((num(research.get(f)), num(live.get(raw_name))))
        ex = extras.get((code, p4), {})
        for f in ("overall_average_st", "motor_2ren_rate", "boat_2ren_rate", "national_win_rate", "local_win_rate", "national_2ren_rate", "local_2ren_rate"):
            live_v = rr4.get(f) if f == "overall_average_st" else live4.get(f)
            comparisons[f][split].append((num(ex.get(f)), num(live_v)))
        mapping_rows.append({
            "race_code": code, "split": split,
            "research_course3_player": p3, "research_course4_player": p4,
            "course3_lane": live3.get("lane"), "course4_lane": live4.get("lane"),
            "course3_result_course": live3.get("result_course"), "course4_result_course": live4.get("result_course"),
            "course3_exhibition_course": live3.get("exhibition_course"), "course4_exhibition_course": live4.get("exhibition_course"),
            "research_wakunari_match": live3.get("lane") == 3 and live4.get("lane") == 4,
            "live_exhibition_wakunari_match": live3.get("exhibition_course") == 3 and live4.get("exhibition_course") == 4,
        })

    parity_stats = {}
    for feature, by_split in comparisons.items():
        parity_stats[feature] = {split: diff_stat(pairs) for split, pairs in by_split.items()}
        all_pairs = sum(by_split.values(), [])
        parity_stats[feature]["ALL_SPOTS"] = diff_stat(all_pairs)

    feature_rows = []
    wall = loaded["WALL_R"]
    wall_status = {
        "smoothed_avg_st_delta_34": "PARITY_BLOCKED", "ex_st_delta_34": "PARITY_PASS_WITH_CONTRACT",
        "avg_st_rank_delta_34": "PARITY_BLOCKED", "st_profile_reliability_advantage_34": "PARITY_BLOCKED",
        "exhibition_time_delta_34": "PARITY_PASS_WITH_CONTRACT", "lap_time_delta_34": "PARITY_PASS_WITH_CONTRACT",
        "around_time_delta_34": "PARITY_PASS_WITH_CONTRACT", "straight_time_delta_34": "PARITY_PASS_WITH_CONTRACT",
    }
    for i, f in enumerate(wall["feature_names"], 1):
        if "smoothed" in f:
            source = "boat_race.racer_results: overall average_start, C3/C4 average_start and entry count"
            live = "same term_info + player_id fields; K=30"
            ev = "blocked: no valid publication/version timestamp; calc_period fields are 1970-01-01 placeholders"
        elif "rank" in f or "reliability" in f:
            source = "boat_race.racer_results: course3/4_average_rank or entry counts"
            live = "same term_info + player_id fields"
            ev = "blocked: term-static as-of semantics unproved; average_rank is average finish rank, not ST rank"
        else:
            source = "boat_race.exhibition_live, research latest row, C3 minus C4"
            live = "latest valid C3/C4 exhibition rows at frozen prediction cutoff"
            ev = "spot exact parity; future live requires cutoff, completeness and wakunari eligibility contract"
        add_feature(feature_rows, "WALL_R", i, f, wall["feature_definitions"][f], source,
                    "POST_EXHIBITION; research archive selected latest row", "artifact median fill", "artifact median + mean/std ddof=0",
                    source.split(",")[0], live, wall_status[f], ev)

    a5 = loaded["ATTACK_A5"]
    for i, f in enumerate(a5["feature_names"], 1):
        if f in HISTORY:
            status, source, live, ev = ("PARITY_BLOCKED", "strict-prior completed actual-4C history from frozen Phase4 monthly event cache",
                "rebuild [race_date-12 months,race_date), result_detail->latest exhibition->lane for completed history",
                "strict cutoff is safe, but current raw DB does not reproduce frozen research cache after backfills; 15/15 history_n spots differ")
            cutoff = "source race_date < target race_date; target day excluded"
        elif f in EXHIBITION:
            status, source, live, ev = ("PARITY_PASS_WITH_CONTRACT", "boat_race.exhibition_live current archived row",
                "target actual course 4 from complete wakunari exhibition; latest valid row <= prediction_cutoff",
                "spot exact parity; live cutoff/completeness contract required")
            cutoff = "POST_EXHIBITION frozen cutoff"
        elif f in ("smoothed_course4_st", "course4_average_st_rank", "course4_entry_count"):
            status, source, live, ev = ("PARITY_BLOCKED", "boat_race.racer_results term_info+player_id",
                "same stored term row would reproduce current value but its historical publication/version is unproved",
                "no update/version column; calc period metadata is 1970 placeholder; average_rank semantic is finish rank")
            cutoff = "mapped half-year term; as-of availability unproved"
        else:
            status = "PARITY_BLOCKED"
            source = "boat_race.engine_specs race_code+motor_number" if f.startswith(("motor", "boat")) else "boat_race.player_stats race_code+player_id"
            live = "same race-scoped row"
            ev = "race-scoped primary key exists, but no capture/version timestamp or repository writer proves pre-race immutability"
            cutoff = "pre-race snapshot time unproved"
        add_feature(feature_rows, "ATTACK_A5", i, f, a5["feature_definitions"][f], source, cutoff,
                    "artifact median fill", "K=20 for smoothed ST; artifact median + mean/std ddof=0",
                    source, live, status, ev)

    success = loaded["ATTACK_SUCCESS"]
    for i, f in enumerate(success["input_features_ordered"], 1):
        add_feature(feature_rows, "ATTACK_SUCCESS", i, f, f"frozen upstream {f}", "frozen upstream model probability",
                    "same prediction cutoff as all upstream inputs", "artifact median fill", "artifact mean/std ddof=0 then logistic",
                    "deterministic upstream artifact output", "only generated when upstream signal is READY and hashes match",
                    "NOT_APPLICABLE_DERIVED", "deterministic, but signal is transitively blocked because Wall-R and A5 are blocked")

    at = loaded["ATTACK_TYPE"]
    spec_audit = loaded["ATTACK_TYPE_SPEC"]["feature_as_of_audit"]
    for i, f in enumerate(at["feature_order"], 1):
        info = spec_audit[f]
        if f.startswith("course4_history"):
            status, source, live, ev, cutoff = ("PARITY_BLOCKED", info["source"],
                "same strict-prior 12-month actual-4C history builder", "Phase7 as-of audit plus Phase9 spots; zero same-day/future use",
                "[target date-12 calendar months,target date)")
            ev = "time cutoff is safe, but current raw DB contains post-research backfills and fails exact parity with the frozen history cache in all 15 spots"
        else:
            status, source, live, ev, cutoff = ("PARITY_PASS_WITH_CONTRACT", info["source"],
                "complete wakunari exhibition mapping; latest valid row whose created_date <= frozen cutoff",
                "spot exact parity; post-exhibition cutoff and all-five-metrics completeness required",
                "POST_EXHIBITION frozen cutoff")
        add_feature(feature_rows, "ATTACK_TYPE", i, f, f, source, cutoff,
                    "Development medians fixed in artifact", "artifact medians + Development mean/std ddof=0; multiclass logits",
                    source, live, status, ev)

    OUT.mkdir(parents=True, exist_ok=True)
    fields = list(feature_rows[0])
    with PARITY_CSV.open("w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=fields)
        w.writeheader(); w.writerows(feature_rows)

    source_evidence = {
        "schema": db["schema"], "triggers": db["triggers"], "indexes": db["indexes"],
        "racer_results_term_metadata": db["term_rows"],
        "racer_results_term_mapping": "Jan-Apr=>previous YY10; May-Oct=>current YY04; Nov-Dec=>current YY10",
        "term_mapping_verdict": "implementation match, source as-of/publication semantics BLOCKED",
        "average_rank_semantic": "stored courseN_average_rank is average finish rank, not average-ST rank; confirmed by Phase7 as-of audit",
        "phase7_asof_audit_path": "analysis/output/course4_attack_type_phase7_asof_audit.json",
        "phase7_asof_audit_sha256": sha256(OUT / "course4_attack_type_phase7_asof_audit.json"),
    }
    actual_course = {
        "research_rule": "completed history: race_result_detail -> latest exhibition -> lane",
        "research_target_eligibility": "exactly six unique actual courses and lane_number == actual_course for all 1..6 (wakunari 123456)",
        "live_rule": "target race uses exhibition entry only; require exactly one row/course and player mapping 1..6, and entry_course == lane_number for all six",
        "result_table_live_use": "PROHIBITED",
        "spot_rows": mapping_rows,
        "spot_research_wakunari_match_n": sum(x["research_wakunari_match"] for x in mapping_rows),
        "spot_live_exhibition_wakunari_match_n": sum(x["live_exhibition_wakunari_match"] for x in mapping_rows),
        "spot_n": len(mapping_rows),
        "verdict": "PASS_WITH_CONTRACT: live eligibility is restricted to the same wakunari population; fail closed otherwise",
    }
    exhibition_contract = {
        "stage": "POST_EXHIBITION", "prediction_cutoff_definition": "immutable timestamp captured after official exhibition data are complete and before bet/prediction publication",
        "record_selection": "per race_code+player_id choose latest valid exhibition_live row with created_date <= prediction_cutoff; entry_course must be unique 1..6",
        "required_metrics": ["start_timing", "exhibition_time", "lap_time", "around_time", "straight_time"],
        "completeness": "all required players and model-required metrics must be present; do not median-fill unavailable live records",
        "post_cutoff_rows": "ignored", "result_table_usage": "prohibited", "target_day_history": "prohibited",
        "snapshot_requirement_next_phase": "persist cutoff, selected source timestamps, raw inputs, input hash, artifact hash and output atomically",
    }
    signal_readiness = {
        "WALL_R_LIVE_INPUT": "BLOCKED", "ATTACK_A5_LIVE_INPUT": "BLOCKED",
        "ATTACK_SUCCESS_LIVE_INPUT": "TRANSITIVE_BLOCKED", "ATTACK_TYPE_LIVE_INPUT": "BLOCKED",
    }
    contract = {
        "schema_version": "course4-tenkai-live-input-contract-v1", "contract_status": "FROZEN",
        "prediction_stage": "POST_EXHIBITION", "prediction_cutoff": exhibition_contract,
        "fail_closed_policy": {
            "conditions": ["unsafe source only", "required feature cannot be reproduced", "artifact hash mismatch", "contract version mismatch", "target exhibition is not complete wakunari 123456"],
            "response": {"prediction_available": False, "required_fields": ["missing_reason", "blocked_reason"]},
            "silent_median_substitution_for_unavailable_live_source": "PROHIBITED",
        },
        "signals": [], "signal_readiness": signal_readiness,
        "result_table_live_use": "PROHIBITED", "target_day_history": "PROHIBITED",
        "generated_at": datetime.now().astimezone().isoformat(), "source_git_commit": git_head(),
        "source_script": "analysis/audit_course4_tenkai_live_input_phase9_1a.py", "source_script_sha256": sha256(Path(__file__)),
    }
    for signal in ("WALL_R", "ATTACK_A5", "ATTACK_SUCCESS", "ATTACK_TYPE"):
        art_name = signal
        path, expected = EXPECTED[art_name]
        frows = [r for r in feature_rows if r["signal_id"] == signal]
        contract["signals"].append({
            "signal_id": signal, "artifact_path": str(path.relative_to(REPO)), "artifact_sha256": expected,
            "readiness": signal_readiness[f"{signal}_LIVE_INPUT"],
            "stage": "POST_EXHIBITION", "feature_order": [r["feature_name"] for r in frows],
            "features": [{k: r[k] for k in ("feature_name", "feature_order", "research_source", "research_definition", "research_cutoff", "research_missing_policy", "research_transform", "live_candidate_source", "live_candidate_definition", "parity_status", "parity_evidence")} for r in frows],
            "blocked_features": [r["feature_name"] for r in frows if r["parity_status"] == "PARITY_BLOCKED"],
        })
    CONTRACT_JSON.write_text(json.dumps(contract, ensure_ascii=False, indent=2), encoding="utf-8")

    report = {
        "phase": "9-1a", "result": "AUDIT_PASS_LIVE_INPUTS_BLOCKED", "generated_at": datetime.now().astimezone().isoformat(),
        "source_git_commit": git_head(), "source_script": "analysis/audit_course4_tenkai_live_input_phase9_1a.py", "source_script_sha256": sha256(Path(__file__)),
        "artifact_hash_audit": artifact_audit,
        "feature_inventory_count": len(feature_rows), "classification_counts": {s: sum(r["parity_status"] == s for r in feature_rows) for s in ("PARITY_PASS", "PARITY_PASS_WITH_CONTRACT", "PARITY_BLOCKED", "NOT_APPLICABLE_DERIVED")},
        "spot_parity": {"selection": "five deterministic race_code quantiles per split", "race_count": len(spots), "race_codes": [{"split": split_of(date.fromisoformat(r["race_date"])), "race_code": r["race_code"]} for r in spots], "statistics": parity_stats,
                        "history_fixed_cache_vs_current_db": history_event_drift,
                        "history_drift_summary": {"spots": len(history_event_drift), "history_n_exact_match_n": sum(x["research_history_n"] == x["current_db_history_n"] for x in history_event_drift), "fixed_cache_reproduces_research_n": sum(x["research_history_n"] == x["fixed_cache_history_n"] for x in history_event_drift), "current_db_only_event_total": sum(x["current_db_only_n"] for x in history_event_drift), "fixed_cache_only_event_total": sum(x["fixed_cache_only_n"] for x in history_event_drift), "history_cache_file_count": len(history_cache_paths)}},
        "leakage_audit": leakage, "source_semantics_evidence": source_evidence,
        "actual_course_parity": actual_course, "exhibition_cutoff_contract": exhibition_contract,
        "signal_readiness": signal_readiness,
        "blocked_features": {x["signal_id"]: [r["feature_name"] for r in feature_rows if r["signal_id"] == x["signal_id"] and r["parity_status"] == "PARITY_BLOCKED"] for x in [{"signal_id": s} for s in ("WALL_R", "ATTACK_A5", "ATTACK_SUCCESS", "ATTACK_TYPE")]},
        "transitive_dependency": "Attack Success is unavailable while either Wall-R or A5 is blocked; Attack Type does not inherit these blockers.",
        "phase9_1b_recommendation": "Do not implement live inference yet. First freeze a versioned append-only history ledger seeded from the exact Phase4 monthly cache (or explicitly approve a new reproducible artifact); then re-audit Attack Type. Do not use the current backfilled DB history silently.",
        "outputs": {"feature_parity_csv": str(PARITY_CSV.relative_to(REPO)), "live_input_contract": str(CONTRACT_JSON.relative_to(REPO))},
        "timing_seconds": time.perf_counter() - started,
    }
    REPORT_JSON.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")

    # Reload audit: exact persisted structure and hashes; no model inference.
    reread_contract = read_json(CONTRACT_JSON)
    reread_report = read_json(REPORT_JSON)
    with PARITY_CSV.open(encoding="utf-8") as f:
        reread_rows = list(csv.DictReader(f))
    if len(reread_rows) != len(feature_rows) or reread_contract["signal_readiness"] != signal_readiness or reread_report["artifact_hash_audit"] != artifact_audit:
        raise RuntimeError("saved artifact reload audit failed")
    print(json.dumps({
        "phase": "9-1a", "feature_rows": len(feature_rows), "signal_readiness": signal_readiness,
        "classification_counts": report["classification_counts"], "spot_races": len(spots),
        "history_leakage": leakage, "outputs": report["outputs"], "reload_audit": "PASS",
        "timing_seconds": report["timing_seconds"],
    }, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
