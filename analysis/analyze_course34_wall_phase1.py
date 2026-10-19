#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""3→4 wall hypothesis, Phase 1 (research only; no production/ML use).

Tests, in frame-order races only, whether a slower course-3 relative to course-4
is associated with 4C attack results and with a distinctive 5C (not merely 6C)
benefit.  Real ST columns are explicitly oracle/research-only diagnostics.

Usage:
  python3 analysis/analyze_course34_wall_phase1.py [START_DATE END_DATE]

With no dates, the script uses the latest completed-result date and the prior
12 calendar months.  It writes race-level and long-format summary CSV files to
analysis/output/.  It only reads the database.
"""
from __future__ import annotations

import bisect
import csv
import math
import sys
from calendar import monthrange
from collections import Counter, defaultdict
from datetime import date, datetime, timedelta
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from slit_validate_v2 import connect_db  # existing shared DB connection helper

OUT = Path(__file__).resolve().parent / "output"
TECH_MAKURI = "まくり"
TECH_MAKURIZASHI = "まくり差し"


def num(v):
    try:
        x = float(v)
        return x if math.isfinite(x) else None
    except (TypeError, ValueError):
        return None


def integer(v):
    x = num(v)
    return int(x) if x is not None else None


def term_for(d: date) -> str:
    if d.month <= 4:
        return f"{(d.year - 1) % 100:02d}10"
    if d.month <= 10:
        return f"{d.year % 100:02d}04"
    return f"{d.year % 100:02d}10"


def months_ago(d: date, months: int) -> date:
    total = d.year * 12 + d.month - 1 - months
    year, month0 = divmod(total, 12)
    month = month0 + 1
    return date(year, month, min(d.day, monthrange(year, month)[1]))


def pct(n, d):
    return None if not d else 100.0 * n / d


def latest_result_date():
    with connect_db() as conn, conn.cursor() as cur:
        cur.execute("""SELECT MAX(rm.race_date) FROM boat_race.race_master rm
                       JOIN boat_race.race_result_detail r ON r.race_code=rm.race_code
                       WHERE TRIM(r.rank::text)='1'""")
        return cur.fetchone()[0]


def load_targets(start: date, end: date):
    """One read-only query.  actual_course ordering follows existing analysis."""
    sql = """
WITH ex AS (
 SELECT DISTINCT ON (race_code, player_id) race_code, player_id, entry_course,
   exhibition_time, start_timing, lap_time, around_time, straight_time
 FROM boat_race.exhibition_live ORDER BY race_code, player_id, created_date DESC NULLS LAST, entry_course
), rd AS (
 SELECT DISTINCT ON (race_code, player_id) race_code, player_id, entry_course, start_timing, rank
 FROM boat_race.race_result_detail ORDER BY race_code, player_id
), winners AS (
 SELECT DISTINCT ON (race_code) race_code, TRIM(COALESCE(technique,'')) technique
 FROM boat_race.race_result_detail WHERE TRIM(rank::text)='1' ORDER BY race_code
)
SELECT rm.race_code, rm.race_date, SUBSTRING(rm.race_code,9,3), re.lane_number,
 re.player_id::text, COALESCE(rd.entry_course, ex.entry_course, re.lane_number) actual_course,
 ex.start_timing, rd.start_timing, rd.rank, ex.exhibition_time, ex.lap_time, ex.around_time,
 ex.straight_time, w.technique
FROM boat_race.race_master rm JOIN boat_race.race_entry re ON re.race_code=rm.race_code
JOIN winners w ON w.race_code=rm.race_code
LEFT JOIN rd ON rd.race_code=re.race_code AND rd.player_id=re.player_id
LEFT JOIN ex ON ex.race_code=re.race_code AND ex.player_id=re.player_id
WHERE rm.race_date BETWEEN %s AND %s ORDER BY rm.race_code, re.lane_number
"""
    races = defaultdict(list)
    with connect_db() as conn, conn.cursor() as cur:
        cur.execute(sql, (start, end))
        for r in cur.fetchall():
            races[str(r[0])].append({"date": r[1], "venue": str(r[2]), "lane": integer(r[3]),
                "pid": str(r[4]).strip(), "course": integer(r[5]), "ex_st": num(r[6]),
                "real_st": num(r[7]), "rank": integer(r[8]), "exhibition_time": num(r[9]),
                "lap_time": num(r[10]), "around_time": num(r[11]), "straight_time": num(r[12]),
                "technique": str(r[13] or '').strip()})
    return races


def load_racer(terms):
    cols = ["term_info", "player_id"]
    for c in range(1, 7): cols += [f"course{c}_entry", f"course{c}_average_start", f"course{c}_average_rank"]
    out = {}
    with connect_db() as conn, conn.cursor() as cur:
        cur.execute(f"SELECT {', '.join(cols)} FROM boat_race.racer_results WHERE term_info::text=ANY(%s)", (terms,))
        for r in cur.fetchall():
            vals, i = {}, 2
            for c in range(1, 7):
                vals[c] = {"entry": max(0, integer(r[i]) or 0), "avg": num(r[i+1]), "rank": num(r[i+2])}; i += 3
            out[(str(r[0]).strip(), str(r[1]).strip())] = vals
    return out


def load_history(history_start: date, end: date, pids):
    """4C results for target people. Profiles are later sliced strictly before race_date."""
    sql = """
WITH ex AS (SELECT DISTINCT ON (race_code,player_id) race_code,player_id,entry_course
 FROM boat_race.exhibition_live ORDER BY race_code,player_id,created_date DESC NULLS LAST),
 rd AS (SELECT DISTINCT ON (race_code,player_id) race_code,player_id,entry_course,rank
 FROM boat_race.race_result_detail ORDER BY race_code,player_id),
 win AS (SELECT DISTINCT ON (race_code) race_code,player_id,TRIM(COALESCE(technique,'')) technique
 FROM boat_race.race_result_detail WHERE TRIM(rank::text)='1' ORDER BY race_code)
SELECT rm.race_date, re.player_id::text, COALESCE(rd.entry_course,ex.entry_course,re.lane_number),
       CASE WHEN rd.rank::text~'^[1-6]$' THEN rd.rank::int END, win.player_id::text, win.technique
FROM boat_race.race_master rm JOIN boat_race.race_entry re ON re.race_code=rm.race_code
LEFT JOIN rd ON rd.race_code=re.race_code AND rd.player_id=re.player_id
LEFT JOIN ex ON ex.race_code=re.race_code AND ex.player_id=re.player_id
LEFT JOIN win ON win.race_code=rm.race_code
WHERE rm.race_date >= %s AND rm.race_date <= %s AND re.player_id::text=ANY(%s)
"""
    by = defaultdict(list)
    with connect_db() as conn, conn.cursor() as cur:
        cur.execute(sql, (history_start, end, list(pids)))
        for d, pid, course, rank, winner, tech in cur.fetchall():
            if integer(course) == 4:
                by[str(pid)].append((d, integer(rank), str(winner or '').strip() == str(pid).strip(), str(tech or '').strip()))
    for rows in by.values(): rows.sort(key=lambda x: x[0])
    return by


def profile(history, pid, race_date):
    rows = history.get(pid, [])
    # Both date bounds make this exact even when a target span exceeds 12 months.
    lo, hi = months_ago(race_date, 12), race_date
    a = bisect.bisect_left(rows, lo, key=lambda x: x[0]); b = bisect.bisect_left(rows, hi, key=lambda x: x[0])
    rows = rows[a:b]
    n = len(rows); wins = [x for x in rows if x[2]]
    mak = sum(x[3] == TECH_MAKURI for x in wins); mz = sum(x[3] == TECH_MAKURIZASHI for x in wins)
    return {"n": n, "wins": len(wins), "makuri_n": mak, "makurizashi_n": mz,
            "makuri_rate": pct(mak, n), "makurizashi_rate": pct(mz, n), "win_rate": pct(len(wins), n)}


def outcome(boats):
    ranks = {c: b["rank"] for c, b in boats.items() if b["rank"] in range(1, 7)}
    if sorted(v for v in ranks.values() if v <= 3) != [1, 2, 3]: return None
    finish = {c: ranks.get(c, 5.5) for c in range(1, 7)}
    return finish


def qgroups(rows, field, n=5):
    """Equal-count groups; stable ties are deliberate so all rows remain assigned."""
    valid = [r for r in rows if r.get(field) is not None]
    valid.sort(key=lambda r: (r[field], r["race_code"]))
    boundaries = []
    for i, r in enumerate(valid):
        r[field + "_q"] = min(n, i * n // len(valid) + 1)
    for q in range(1, n): boundaries.append(valid[min(len(valid)-1, math.ceil(q*len(valid)/n))][field])
    return valid, boundaries


METRICS = ("four_first","four_second","four_third","four_top2","four_top3","four_ahead_of_3",
           "four_win_makuri","four_win_makurizashi","four_win_attack","five_second","five_third",
           "five_top2","five_top3","six_top2","six_top3")


def add_summary(out, analysis, group, rows, extra=None):
    d = {"analysis": analysis, "group": group, "n": len(rows), **(extra or {})}
    for f in METRICS: d[f + "_rate_pct"] = pct(sum(bool(r.get(f)) for r in rows), len(rows))
    wins = sum(bool(r.get("four_first")) for r in rows)
    d["four_win_makuri_share_of_four_wins_pct"] = pct(sum(bool(r.get("four_win_makuri")) for r in rows), wins)
    d["four_win_makurizashi_share_of_four_wins_pct"] = pct(sum(bool(r.get("four_win_makurizashi")) for r in rows), wins)
    out.append(d)


def build_rows(races, racer, hist, skips):
    rows = []
    for code, raw in races.items():
        if len(raw) != 6: skips["not_6_boats"] += 1; continue
        boats = {}
        bad = False
        for b in raw:
            c = b["course"]
            if c not in range(1,7) or c in boats: bad=True; break
            boats[c] = b
        if bad or set(boats) != set(range(1,7)): skips["actual_course_not_unique_1_to_6"] += 1; continue
        if any(boats[c]["lane"] != c for c in range(1,7)): skips["entry_change_not_frame_order"] += 1; continue
        finish = outcome(boats)
        if finish is None: skips["results_top3_not_unique"] += 1; continue
        b3, b4 = boats[3], boats[4]; term = term_for(b3["date"])
        p3, p4 = racer.get((term,b3["pid"]),{}).get(3), racer.get((term,b4["pid"]),{}).get(4)
        h = profile(hist, b4["pid"], b3["date"])
        r = {"race_code": code, "race_date": b3["date"].isoformat(), "stadium": b3["venue"],
             "course3_player_id":b3["pid"], "course4_player_id":b4["pid"],
             "course3_average_st": p3 and p3["avg"], "course4_average_st": p4 and p4["avg"],
             "course3_average_st_rank": p3 and p3["rank"], "course4_average_st_rank": p4 and p4["rank"],
             "course3_entry_count": p3 and p3["entry"], "course4_entry_count": p4 and p4["entry"],
             "course4_history_12m_n": h["n"], "course4_history_12m_makuri_n":h["makuri_n"],
             "course4_history_12m_makuri_rate_pct":h["makuri_rate"], "course4_history_12m_makurizashi_n":h["makurizashi_n"],
             "course4_history_12m_makurizashi_rate_pct":h["makurizashi_rate"], "course4_history_12m_win_rate_pct":h["win_rate"]}
        for c, name in ((3,"course3"),(4,"course4")):
            for k in ("ex_st","real_st","exhibition_time","lap_time","around_time","straight_time"): r[f"{name}_{k}"] = boats[c][k]
        r["avg_st_delta_34"] = (r["course3_average_st"]-r["course4_average_st"] if r["course3_average_st"] is not None and r["course4_average_st"] is not None else None)
        r["avg_st_rank_delta_34"] = (r["course3_average_st_rank"]-r["course4_average_st_rank"] if r["course3_average_st_rank"] is not None and r["course4_average_st_rank"] is not None else None)
        r["ex_st_delta_34"] = (r["course3_ex_st"]-r["course4_ex_st"] if r["course3_ex_st"] is not None and r["course4_ex_st"] is not None else None)
        r["real_st_delta_34_research_only"] = (r["course3_real_st"]-r["course4_real_st"] if r["course3_real_st"] is not None and r["course4_real_st"] is not None else None)
        for c, prefix in ((4,"four"),(5,"five"),(6,"six")):
            f = finish[c]; r[f"finish_{c}"]=f
            for pos in (1,2,3): r[f"{prefix}_{['first','second','third'][pos-1]}"] = f == pos
            r[f"{prefix}_top2"] = f <= 2; r[f"{prefix}_top3"] = f <= 3
        r["four_ahead_of_3"] = finish[4] < finish[3]
        r["four_win_makuri"] = finish[4] == 1 and b4["technique"] == TECH_MAKURI
        r["four_win_makurizashi"] = finish[4] == 1 and b4["technique"] == TECH_MAKURIZASHI
        r["four_win_attack"] = r["four_win_makuri"] or r["four_win_makurizashi"]
        rows.append(r)
    return rows


def csv_write(path, rows):
    fields = sorted({k for r in rows for k in r}) if rows else []
    with path.open("w", newline="", encoding="utf-8") as f:
        w=csv.DictWriter(f, fieldnames=fields); w.writeheader(); w.writerows(rows)


def run(start, end):
    print(f"3→4 wall Phase 1 / research only: {start} ～ {end}")
    races = load_targets(start,end); terms=sorted({term_for(d) for d in (start + timedelta(days=i) for i in range((end-start).days+1))})
    racer=load_racer(terms); pids={b['pid'] for rr in races.values() for b in rr}; hist=load_history(months_ago(start,12),end,pids)
    skips=Counter(); rows=build_rows(races,racer,hist,skips); summary=[]
    print(f"DB結果期間: {latest_result_date()}  全候補R={len(races)} 枠なり対象R={len(rows)}")
    for field,label in (("avg_st_delta_34","avg_st_delta_34_quintile"),("ex_st_delta_34","ex_st_delta_34_quintile"),("real_st_delta_34_research_only","real_st_delta_34_research_only_quintile")):
        valid,bounds=qgroups(rows,field,5); print(f"{label}: N={len(valid)} boundaries={bounds}")
        for r in valid: r[field + "_q5"] = r[field + "_q"]
        for q in range(1,6):
            g=[r for r in valid if r[field+'_q']==q]; add_summary(summary,label,f"Q{q}",g,{"avg_delta":sum(r[field] for r in g)/len(g),"boundaries":'|'.join(map(str,bounds))})
    av,_=qgroups(rows,"avg_st_delta_34",3); ex,_=qgroups(rows,"ex_st_delta_34",3)
    both=[r for r in rows if r.get("avg_st_delta_34_q") and r.get("ex_st_delta_34_q")]
    for a in range(1,4):
        for e in range(1,4): add_summary(summary,"avg_x_ex_tertile",f"avgQ{a}_exQ{e}",[r for r in both if r['avg_st_delta_34_q']==a and r['ex_st_delta_34_q']==e])
    wall,_=qgroups(rows,"avg_st_delta_34",3); attack,_=qgroups(rows,"course4_history_12m_makuri_rate_pct",3)
    wa=[r for r in rows if r.get("avg_st_delta_34_q") and r.get("course4_history_12m_makuri_rate_pct_q")]
    wa_base_5 = pct(sum(r["five_top3"] for r in wa), len(wa))
    wa_base_6 = pct(sum(r["six_top3"] for r in wa), len(wa))
    for w in range(1,4):
        for a in range(1,4):
            g=[r for r in wa if r['avg_st_delta_34_q']==w and r['course4_history_12m_makuri_rate_pct_q']==a]
            add_summary(summary,"wall_x_attack_tertile",f"wallQ{w}_attackQ{a}",g,{
                "five_top3_lift_vs_wall_attack_all_pct_point": (pct(sum(r["five_top3"] for r in g),len(g)) - wa_base_5) if g else None,
                "six_top3_lift_vs_wall_attack_all_pct_point": (pct(sum(r["six_top3"] for r in g),len(g)) - wa_base_6) if g else None,
            })
    # National wall boundaries/groups are intentionally reused by venue; no per-venue tuning.
    for venue in sorted({r['stadium'] for r in rows}):
        for q in range(1,6): add_summary(summary,"venue_avg_delta_national_quintile",f"{venue}:Q{q}",[r for r in rows if r['stadium']==venue and r.get('avg_st_delta_34_q5')==q],{"stadium":venue})
    OUT.mkdir(exist_ok=True); stamp=f"{start:%Y%m%d}_{end:%Y%m%d}"; race_path=OUT/f"course34_wall_phase1_races_{stamp}.csv"; sum_path=OUT/f"course34_wall_phase1_summary_{stamp}.csv"
    csv_write(race_path,rows); csv_write(sum_path,summary)
    print("各分析使用N:", "avg",sum(r.get('avg_st_delta_34') is not None for r in rows), "ex",sum(r.get('ex_st_delta_34') is not None for r in rows), "real(research-only)",sum(r.get('real_st_delta_34_research_only') is not None for r in rows), "wall×attack",len(wa))
    print("skip理由:", dict(skips)); print("CSV:",race_path,"\n     ",sum_path)
    # Compact, auditable 5C vs 6C comparison for the requested four corners.
    for w,a in ((1,1),(1,3),(3,1),(3,3)):
        g=[r for r in wa if r['avg_st_delta_34_q']==w and r['course4_history_12m_makuri_rate_pct_q']==a]
        print(f"wallQ{w} attackQ{a}: N={len(g)} 5C top3={pct(sum(r['five_top3'] for r in g),len(g))}%, 6C top3={pct(sum(r['six_top3'] for r in g),len(g))}%")


if __name__ == '__main__':
    if len(sys.argv) == 1:
        end=latest_result_date(); start=months_ago(end + timedelta(days=1),12)
    elif len(sys.argv) == 3:
        start=datetime.strptime(sys.argv[1],'%Y-%m-%d').date(); end=datetime.strptime(sys.argv[2],'%Y-%m-%d').date()
    else: raise SystemExit('Usage: python3 analysis/analyze_course34_wall_phase1.py [START_DATE END_DATE]')
    run(start,end)
