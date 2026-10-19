#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""3→4 Wall Phase 2: fixed-bin stability and 4→5 research-only diagnostics.

Reads the Phase 1 race-level artifact and never changes production behaviour.
All conditions involving a 4C race result are labelled research_only.
Usage: python3 analysis/analyze_course34_wall_phase2.py [START_DATE END_DATE]
"""
from __future__ import annotations
import csv, math, random, sys
from collections import defaultdict
from datetime import date, datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parent
sys.path.insert(0, str(ROOT))
from analyze_course34_wall_phase1 import (build_rows, load_history, load_racer, load_targets, months_ago, term_for)

OUT = ROOT / "output"
METRICS = ("four_first", "four_win_attack", "four_top2", "four_top3", "five_second", "five_third", "five_top2", "five_top3", "six_second", "six_third", "six_top2", "six_top3")

def f(v):
    try: return float(v) if v not in (None, "") else None
    except (TypeError, ValueError): return None
def b(v): return str(v).lower() in ("true", "1", "t")
def pct(x, n): return None if not n else 100 * x / n
def rate(rows, field): return pct(sum(b(r.get(field)) for r in rows), len(rows))
def parse(s): return datetime.strptime(s, "%Y-%m-%d").date()

def load_phase1(start, end):
    p = OUT / f"course34_wall_phase1_races_{start:%Y%m%d}_{end:%Y%m%d}.csv"
    if not p.exists(): raise RuntimeError(f"Phase 1 race CSV がありません: {p}")
    rows=[]
    numeric = ("avg_st_delta_34", "ex_st_delta_34", "real_st_delta_34_research_only", "course4_history_12m_makuri_rate_pct", "finish_4", "finish_5", "finish_6")
    with p.open(encoding="utf-8") as fh:
        for r in csv.DictReader(fh):
            for k in numeric: r[k]=f(r.get(k))
            rows.append(r)
    return rows

def cutpoints(rows, field, n):
    vals=sorted(r[field] for r in rows if r.get(field) is not None)
    return [vals[min(len(vals)-1, math.ceil(i*len(vals)/n))] for i in range(1,n)]
def fixed_group(x, cuts):
    if x is None: return None
    return 1 + sum(x > cut for cut in cuts)  # boundary stays on lower group
def attach_fixed(rows, avg5, ex5, avg3, ex3, attack3, real5):
    for r in rows:
        r["avg_q5_fixed"] = fixed_group(r.get("avg_st_delta_34"),avg5)
        r["ex_q5_fixed"] = fixed_group(r.get("ex_st_delta_34"),ex5)
        r["avg_q3_fixed"] = fixed_group(r.get("avg_st_delta_34"),avg3)
        r["ex_q3_fixed"] = fixed_group(r.get("ex_st_delta_34"),ex3)
        r["attack_q3_fixed"] = fixed_group(r.get("course4_history_12m_makuri_rate_pct"),attack3)
        r["real_q5_fixed"] = fixed_group(r.get("real_st_delta_34_research_only"),real5)
        # successful attack is explicitly retrospective/research-only.
        if b(r.get("four_win_makuri")): r["successful_attack_research_only"]="makuri_win"
        elif b(r.get("four_win_makurizashi")): r["successful_attack_research_only"]="makurizashi_win"
        elif b(r.get("four_first")): r["successful_attack_research_only"]="other_win"
        else: r["successful_attack_research_only"]="not_win"

def row_summary(kind, group, rows, extra=None):
    d={"analysis":kind,"group":group,"n":len(rows), **(extra or {})}
    for m in METRICS: d[m+"_rate_pct"]=rate(rows,m)
    return d

def fixed_bin_stability(rows, blocks, field, label):
    out=[]
    for block, lo, hi in blocks:
        rr=[r for r in rows if lo <= parse(r["race_date"]) <= hi and r.get(field)]
        for q in range(1,6):
            g=[r for r in rr if r[field]==q]
            out.append(row_summary(label, f"{block}:Q{q}", g, {"block":block,"bin":q}))
    return out

def ex_added_value(rows):
    out=[]
    # Fixed whole-period ex terciles, nested in fixed avg quintiles.
    for aq in range(1,6):
        for eq in range(1,4):
            g=[r for r in rows if r.get("avg_q5_fixed")==aq and r.get("ex_q3_fixed")==eq]
            out.append(row_summary("avg_q5_x_ex_q3_fixed",f"avgQ{aq}_exQ{eq}",g,{"avg_q":aq,"ex_q":eq,"n_small":len(g)<50}))
    return out

def interaction(rows):
    # Weak wall=highest avg delta tertile; strong=lowest; strong attack=highest profile tertile.
    def cell(w,a): return [r for r in rows if r.get("avg_q3_fixed")==w and r.get("attack_q3_fixed")==a]
    cells={(w,a):cell(w,a) for w in (1,3) for a in (1,3)}
    out=[]
    for y in ("four_win_attack","four_top2"):
        rr={k:rate(v,y) for k,v in cells.items()}
        lift=(rr[(3,3)]-rr[(1,3)])-(rr[(3,1)]-rr[(1,1)])
        lo,hi=cluster_bootstrap(cells,y)
        out.append({"analysis":"wall_x_attack_interaction_fixed_tertile","outcome":y,"interaction_lift_pct_point":lift,
                    "bootstrap_95ci_low":lo,"bootstrap_95ci_high":hi,
                    "weak_attack_strong_rate_pct":rr[(3,3)],"strongwall_attack_strong_rate_pct":rr[(1,3)],
                    "weak_attack_weak_rate_pct":rr[(3,1)],"strongwall_attack_weak_rate_pct":rr[(1,1)],
                    **{f"n_wall{w}_attack{a}":len(cells[(w,a)]) for w in (1,3) for a in (1,3)}})
    return out, cells

def cluster_bootstrap(cells, field, draws=1000, seed=20261007):
    """Venue-date cluster bootstrap; fixed groups are resampled independently only through clusters."""
    clusters=defaultdict(list)
    for key, rows in cells.items():
        for r in rows: clusters[(r["stadium"],r["race_date"])].append((key,b(r.get(field))))
    keys=list(clusters); rng=random.Random(seed); values=[]
    if not keys: return None,None
    for _ in range(draws):
        n=defaultdict(int); s=defaultdict(int)
        for k in (rng.choice(keys) for __ in keys):
            for cell,y in clusters[k]: n[cell]+=1; s[cell]+=y
        if all(n[x] for x in ((1,1),(1,3),(3,1),(3,3))):
            v=(100*s[(3,3)]/n[(3,3)]-100*s[(1,3)]/n[(1,3)])-(100*s[(3,1)]/n[(3,1)]-100*s[(1,1)]/n[(1,1)])
            values.append(v)
    values.sort(); return values[int(.025*(len(values)-1))],values[int(.975*(len(values)-1))]

def follower_rows(rows, condition_name, condition):
    base=[r for r in rows if condition(r)]
    base5,base6=rate(base,"five_top3"),rate(base,"six_top3")
    base5s,base6s=rate(base,"five_second"),rate(base,"six_second")
    out=[]
    for cat in ("makuri_win","makurizashi_win","other_win","not_win"):
        g=[r for r in base if r["successful_attack_research_only"]==cat]
        five3,six3=rate(g,"five_top3"),rate(g,"six_top3"); five2,six2=rate(g,"five_second"),rate(g,"six_second")
        d=row_summary("followers_successful_attack_research_only",f"{condition_name}:{cat}",g,{"condition":condition_name,"successful_attack_research_only":cat,
            "five_top3_lift_pct_point": None if five3 is None else five3-base5,"six_top3_lift_pct_point":None if six3 is None else six3-base6,
            "follower_gap_top3_pct_point":None if five3 is None else (five3-base5)-(six3-base6),
            "five_second_lift_pct_point":None if five2 is None else five2-base5s,"six_second_lift_pct_point":None if six2 is None else six2-base6s,
            "follower_gap_second_pct_point":None if five2 is None else (five2-base5s)-(six2-base6s)})
        out.append(d)
    return out

def real_st_stability(rows, blocks):
    out=[]
    for label,lo,hi in blocks:
        rr=[r for r in rows if lo<=parse(r['race_date'])<=hi and r.get('real_q5_fixed')]
        for q in range(1,6): out.append(row_summary("real_st_research_only_stability",f"{label}:Q{q}",[r for r in rr if r['real_q5_fixed']==q],{"block":label,"bin":q,"research_only":True}))
    return out

def csvout(path, rows):
    keys=sorted({k for x in rows for k in x})
    with path.open("w",newline="",encoding="utf8") as fh:
        w=csv.DictWriter(fh,fieldnames=keys);w.writeheader();w.writerows(rows)

def external_rows(start,end):
    """Optional prior 12m replication, retaining Phase 1 definitions and profiles."""
    races=load_targets(start,end); terms=sorted({term_for(date.fromordinal(start.toordinal()+i)) for i in range((end-start).days+1)})
    racer=load_racer(terms); pids={x['pid'] for rs in races.values() for x in rs}; hist=load_history(months_ago(start,12),end,pids)
    from collections import Counter
    return build_rows(races,racer,hist,Counter())

def run(start,end):
    rows=load_phase1(start,end)
    avg5,ex5=[cutpoints(rows,x,5) for x in ("avg_st_delta_34","ex_st_delta_34")]
    avg3,ex3,atk3=[cutpoints(rows,x,3) for x in ("avg_st_delta_34","ex_st_delta_34","course4_history_12m_makuri_rate_pct")]
    real5=cutpoints(rows,"real_st_delta_34_research_only",5)
    attach_fixed(rows,avg5,ex5,avg3,ex3,atk3,real5)
    mid=date(2026,3,31); blocks=[("2025H2",start,mid),("2026H1",date(2026,4,1),end)]
    stability=fixed_bin_stability(rows,blocks,"avg_q5_fixed","avg_st_fixed_quintile_stability") + fixed_bin_stability(rows,blocks,"ex_q5_fixed","ex_st_fixed_quintile_stability")
    stability += real_st_stability(rows,blocks)
    stability += ex_added_value(rows)
    inter,cells=interaction(rows); followers=[]
    followers += follower_rows(rows,"all_frame_order",lambda r:True)
    followers += follower_rows(rows,"wall_weak_fixed",lambda r:r.get("avg_q3_fixed")==3)
    followers += follower_rows(rows,"wall_weak_x_attack_strong_fixed",lambda r:r.get("avg_q3_fixed")==3 and r.get("attack_q3_fixed")==3)
    # Prior-year external replication only when a full 12 months of DB records is available.
    ext_start=date(2024,10,1); ext_end=date(2025,9,30)
    try:
        ext=external_rows(ext_start,ext_end)
        attach_fixed(ext,avg5,ex5,avg3,ex3,atk3,real5)
        stability += fixed_bin_stability(ext,[("external_2024H2",ext_start,date(2025,3,31)),("external_2025H1",date(2025,4,1),ext_end)],"avg_q5_fixed","external_avg_st_fixed_quintile")
        external_note=f"external replication included: {len(ext)} R"
    except Exception as exc:
        external_note=f"external replication unavailable: {type(exc).__name__}"
    OUT.mkdir(exist_ok=True); stamp=f"{start:%Y%m%d}_{end:%Y%m%d}"
    p1=OUT/f"course34_wall_phase2_stability_{stamp}.csv";p2=OUT/f"course34_wall_phase2_interaction_{stamp}.csv";p3=OUT/f"course34_wall_phase2_followers_{stamp}.csv"
    csvout(p1,stability);csvout(p2,inter);csvout(p3,followers)
    print("Phase 2 research only",start,end,"N",len(rows)); print("fixed avg Q cuts",avg5,"ex Q cuts",ex5);print(external_note)
    for x in stability:
        if x['analysis']=="avg_st_fixed_quintile_stability": print(x['group'],"N",x['n'],"attack",x['four_win_attack_rate_pct'],"top2",x['four_top2_rate_pct'])
    for x in inter: print("interaction",x['outcome'],x['interaction_lift_pct_point'],"95%CI",x['bootstrap_95ci_low'],x['bootstrap_95ci_high'])
    for x in followers:
        if x['condition'] in ("all_frame_order","wall_weak_fixed","wall_weak_x_attack_strong_fixed") and x['successful_attack_research_only'] in ("makuri_win","makurizashi_win"):
            print(x['group'],"N",x['n'],"5T3",x['five_top3_rate_pct'],"6T3",x['six_top3_rate_pct'],"gap",x['follower_gap_top3_pct_point'])
    print(p1);print(p2);print(p3)

if __name__=='__main__':
    if len(sys.argv)==1: start,end=date(2025,10,1),date(2026,9,30)
    elif len(sys.argv)==3: start,end=parse(sys.argv[1]),parse(sys.argv[2])
    else: raise SystemExit("Usage: python3 analysis/analyze_course34_wall_phase2.py [START_DATE END_DATE]")
    run(start,end)
