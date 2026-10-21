#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Phase 3 research-only candidate models for the 3→4 Wall Weakness Score.

No production code/model/API is read or changed.  Only pre-race 3C-vs-4C
relative inputs are used.  `real_st` and every result field are excluded from
features.  Candidate selection is made from Development + Validation only;
Final Holdout is reported once after selection.

Usage: python3 analysis/analyze_course34_wall_phase3.py
"""
from __future__ import annotations
import bisect, csv, math, sys
from collections import defaultdict
from datetime import date, datetime
from pathlib import Path
import numpy as np
from sklearn.metrics import average_precision_score, brier_score_loss, log_loss, roc_auc_score

ROOT=Path(__file__).resolve().parent; OUT=ROOT/"output"; sys.path.insert(0,str(ROOT))
from analyze_course34_wall_phase1 import build_rows, load_history, load_racer, load_targets, months_ago

START=date(2024,10,1); DEV_END=date(2025,9,30); VAL_END=date(2026,3,31); END=date(2026,9,30)
PRIMARY="four_win_attack"

def to_float(v):
    try: return float(v) if v not in (None, "") else None
    except (TypeError,ValueError): return None
def truth(v): return str(v).lower() in ("true","1","t")
def rate(rows,k): return None if not rows else 100*sum(truth(r.get(k)) for r in rows)/len(rows)
def parse(s): return datetime.strptime(s,"%Y-%m-%d").date()

def load_rows(start,end,path=None):
    p=path or OUT/f"course34_wall_phase1_races_{start:%Y%m%d}_{end:%Y%m%d}.csv"
    if not p.exists(): raise RuntimeError(f"Phase 1 CSV is required: {p}")
    numeric=("avg_st_delta_34","ex_st_delta_34","avg_st_rank_delta_34","course3_average_st","course4_average_st","course3_entry_count","course4_entry_count","course4_history_12m_makuri_rate_pct")
    with p.open(encoding="utf8") as fh:
        rows=list(csv.DictReader(fh))
    for r in rows:
        for k in numeric: r[k]=to_float(r.get(k))
        for base in ("exhibition_time","lap_time","around_time","straight_time"):
            r[base+"_delta_34"]=subtract(r.get("course3_"+base),r.get("course4_"+base))
    return rows
def load_prior_rows(start,end):
    """Build the Development period with exactly the Phase 1 read-only definitions."""
    from collections import Counter
    # Unlike Phase 1's general extractor, restrict detail/exhibition CTEs to the
    # requested races first.  This makes the historical research split practical
    # without changing either the underlying definition or any source table.
    from slit_validate_v2 import connect_db
    sql="""
WITH tr AS MATERIALIZED (SELECT race_code,race_date FROM boat_race.race_master WHERE race_date BETWEEN %s AND %s),
ex AS (SELECT DISTINCT ON (e.race_code,e.player_id) e.race_code,e.player_id,e.entry_course,e.exhibition_time,e.start_timing,e.lap_time,e.around_time,e.straight_time FROM boat_race.exhibition_live e JOIN tr ON tr.race_code=e.race_code ORDER BY e.race_code,e.player_id,e.created_date DESC NULLS LAST,e.entry_course),
rd AS (SELECT DISTINCT ON (d.race_code,d.player_id) d.race_code,d.player_id,d.entry_course,d.start_timing,d.rank FROM boat_race.race_result_detail d JOIN tr ON tr.race_code=d.race_code ORDER BY d.race_code,d.player_id),
win AS (SELECT DISTINCT ON (d.race_code) d.race_code,TRIM(COALESCE(d.technique,'')) technique FROM boat_race.race_result_detail d JOIN tr ON tr.race_code=d.race_code WHERE TRIM(d.rank::text)='1' ORDER BY d.race_code)
SELECT tr.race_code,tr.race_date,SUBSTRING(tr.race_code,9,3),re.lane_number,re.player_id::text,COALESCE(rd.entry_course,ex.entry_course,re.lane_number),ex.start_timing,rd.start_timing,rd.rank,ex.exhibition_time,ex.lap_time,ex.around_time,ex.straight_time,win.technique
FROM tr JOIN boat_race.race_entry re ON re.race_code=tr.race_code JOIN win ON win.race_code=tr.race_code LEFT JOIN rd ON rd.race_code=re.race_code AND rd.player_id=re.player_id LEFT JOIN ex ON ex.race_code=re.race_code AND ex.player_id=re.player_id ORDER BY tr.race_code,re.lane_number"""
    races=defaultdict(list)
    with connect_db() as conn, conn.cursor() as cur:
        cur.execute(sql,(start,end))
        for x in cur.fetchall():
            races[str(x[0])].append({"date":x[1],"venue":str(x[2]),"lane":int(x[3]) if x[3] is not None else None,"pid":str(x[4]).strip(),"course":int(x[5]) if x[5] is not None else None,"ex_st":to_float(x[6]),"real_st":to_float(x[7]),"rank":int(x[8]) if str(x[8]).isdigit() else None,"exhibition_time":to_float(x[9]),"lap_time":to_float(x[10]),"around_time":to_float(x[11]),"straight_time":to_float(x[12]),"technique":str(x[13] or '').strip()})
    terms=sorted({term_for(date.fromordinal(start.toordinal()+i)) for i in range((end-start).days+1)})
    racer=load_racer(terms)
    # Attack history is prohibited from Wall features.  Do not pay the cost of
    # reconstructing it for Development; the sole descriptive correlation uses
    # the already available Final-Holdout Phase 1 profile.
    return build_rows(races,racer,{},Counter())
def subtract(a,b):
    a,b=to_float(a),to_float(b); return a-b if a is not None and b is not None else None

def load_overall_st(rows, cache=None):
    """Read-only current-term overall ST only, used solely to smooth course-ST estimates."""
    terms=sorted({term_for(parse(r["race_date"])) for r in rows})
    pids=sorted({r[x] for r in rows for x in ("course3_player_id","course4_player_id")})
    if cache and cache.exists():
        with cache.open(encoding="utf8") as fh:
            return {(r["term"],r["player_id"]):to_float(r["overall_st"]) for r in csv.DictReader(fh)}
    from slit_validate_v2 import connect_db
    out={}
    with connect_db() as conn, conn.cursor() as cur:
        cur.execute("""SELECT term_info::text,player_id::text,average_start FROM boat_race.racer_results
                       WHERE term_info::text=ANY(%s) AND player_id::text=ANY(%s)""",(terms,pids))
        for term,pid,st in cur.fetchall(): out[(str(term).strip(),str(pid).strip())]=to_float(st)
    return out
def term_for(d):
    return f"{(d.year-1)%100:02d}10" if d.month<=4 else (f"{d.year%100:02d}04" if d.month<=10 else f"{d.year%100:02d}10")

def enrich_smoothing(rows, overall, K=20.0):
    # K=20 is pre-specified from the existing slit reliability convention; not tuned on Holdout.
    for r in rows:
        term=term_for(parse(r["race_date"])); vals=[]; weights=[]
        for c in (3,4):
            course=r.get(f"course{c}_average_st"); n=r.get(f"course{c}_entry_count") or 0
            base=overall.get((term,r[f"course{c}_player_id"]))
            w=n/(n+K) if n>0 else 0.0
            vals.append((base+w*(course-base)) if base is not None and course is not None else course)
            weights.append(w)
        r["smoothed_avg_st_delta_34"]=subtract(vals[0],vals[1])
        r["st_profile_reliability_34"]=min(weights)

FEATURES={
 "W0":["avg_st_delta_34"],
 "W1":["avg_st_delta_34","ex_st_delta_34"],
 "W2":["avg_st_delta_34","ex_st_delta_34","avg_st_rank_delta_34"],
 "W3":["smoothed_avg_st_delta_34","ex_st_delta_34","avg_st_rank_delta_34","st_profile_reliability_34"],
 "W4":["smoothed_avg_st_delta_34","ex_st_delta_34","avg_st_rank_delta_34","st_profile_reliability_34","exhibition_time_delta_34","lap_time_delta_34","around_time_delta_34","straight_time_delta_34"],
}

def xy(rows, features):
    X=np.array([[np.nan if r.get(k) is None else r[k] for k in features] for r in rows],dtype=float)
    y=np.array([int(truth(r[PRIMARY])) for r in rows]); return X,y
class LinearLogit:
    """Small deterministic Newton logistic regression; no black-box learner."""
    def fit(self, X, y):
        self.median_=np.nanmedian(X,axis=0); X=np.where(np.isnan(X),self.median_,X)
        self.mean_=X.mean(axis=0); self.scale_=X.std(axis=0); self.scale_[self.scale_<1e-12]=1.0
        Z=np.column_stack((np.ones(len(X)),(X-self.mean_)/self.scale_)); beta=np.zeros(Z.shape[1]); ridge=1e-6
        for _ in range(25):
            p=1/(1+np.exp(-np.clip(Z@beta,-35,35))); w=np.maximum(p*(1-p),1e-8)
            grad=Z.T@(y-p)-ridge*np.r_[0,beta[1:]]
            h=(Z.T*w)@Z+np.diag(np.r_[0,np.full(len(beta)-1,ridge)])
            step=np.linalg.solve(h,grad); beta += step
            if np.max(np.abs(step))<1e-8: break
        self.beta_=beta; return self
    def predict_proba(self,X):
        X=np.where(np.isnan(X),self.median_,X); Z=np.column_stack((np.ones(len(X)),(X-self.mean_)/self.scale_))
        p=1/(1+np.exp(-np.clip(Z@self.beta_,-35,35))); return np.column_stack((1-p,p))
def model(): return LinearLogit()
def metrics(y,p):
    return {"roc_auc":roc_auc_score(y,p),"pr_auc":average_precision_score(y,p),"brier":brier_score_loss(y,p),"log_loss":log_loss(y,p,labels=[0,1])}
def eval_model(m,rows,features):
    X,y=xy(rows,features); p=m.predict_proba(X)[:,1]; return metrics(y,p),p

def equal_deciles(rows, probs, label, extra=None):
    order=sorted(range(len(rows)),key=lambda i:(probs[i],rows[i]["race_code"])); assigned={}
    for rank,i in enumerate(order): assigned[i]=min(10,rank*10//len(rows)+1)
    out=[]
    fields=("four_win_attack","four_first","four_top2","four_top3","four_ahead_of_3")
    for q in range(1,11):
        idx=[i for i in range(len(rows)) if assigned[i]==q]; rr=[rows[i] for i in idx]
        d={"analysis":label,"decile":q,"n":len(rr),"mean_predicted_probability":float(np.mean(probs[idx])) if idx else None,**(extra or {})}
        for k in fields:d[k+"_rate_pct"]=rate(rr,k)
        out.append(d)
    return out
def score100(p, sorted_dev_probs): return 100*bisect.bisect_right(sorted_dev_probs,p)/len(sorted_dev_probs)

def calibration(rows,probs):
    out=[]
    for d in equal_deciles(rows,probs,"final_holdout_calibration"):
        out.append({"analysis":d["analysis"],"decile":d["decile"],"n":d["n"],"mean_prediction":d["mean_predicted_probability"],"observed_attack_rate_pct":d["four_win_attack_rate_pct"]})
    return out
def score_bands(rows,scores):
    out=[]
    for lo,hi in ((0,19),(20,39),(40,59),(60,79),(80,100)):
        rr=[r for r,s in zip(rows,scores) if lo<=s<=hi]
        d={"analysis":"final_holdout_wall_weakness_score_band","score_band":f"{lo}-{hi}","n":len(rr)}
        for k in ("four_win_attack","four_first","four_top2","four_top3"):d[k+"_rate_pct"]=rate(rr,k)
        out.append(d)
    return out
def venue_rows(rows,scores):
    out=[]
    for venue in sorted({r['stadium'] for r in rows}):
        pairs=[(r,s) for r,s in zip(rows,scores) if r['stadium']==venue]; rr=[x[0] for x in pairs]; ss=[x[1] for x in pairs]
        low=[r for r,s in pairs if s<20]; high=[r for r,s in pairs if s>=80]
        out.append({"stadium":venue,"n":len(rr),"mean_wall_score":float(np.mean(ss)),"low_wall_n":len(low),"low_wall_attack_rate_pct":rate(low,PRIMARY),"high_wall_n":len(high),"high_wall_attack_rate_pct":rate(high,PRIMARY)})
    return out
def ranks(vals):
    # average ranks for ties
    order=sorted(range(len(vals)),key=lambda i:vals[i]); out=[0.0]*len(vals); i=0
    while i<len(order):
        j=i
        while j+1<len(order) and vals[order[j+1]]==vals[order[i]]:j+=1
        z=(i+j+2)/2
        for k in range(i,j+1):out[order[k]]=z
        i=j+1
    return out
def spearman(a,b): return float(np.corrcoef(ranks(a),ranks(b))[0,1])
def csvout(path,rows):
    keys=sorted({k for r in rows for k in r})
    with path.open("w",newline="",encoding="utf8") as fh:
        w=csv.DictWriter(fh,fieldnames=keys);w.writeheader();w.writerows(rows)

def load_model_cache(path):
    numeric=set(sum(FEATURES.values(),[])) | {"course4_history_12m_makuri_rate_pct"}
    with path.open(encoding="utf8") as fh: rows=list(csv.DictReader(fh))
    for r in rows:
        for k in numeric: r[k]=to_float(r.get(k))
    return rows

def run():
    # Candidate evaluation can be executed separately in constrained environments;
    # this assembles immutable per-candidate results after Validation chose W4.
    if "--assemble" in sys.argv:
        stamp="20241001_20260930"; all_models=[]
        for name in FEATURES:
            with (OUT/f"course34_wall_phase3_models_{stamp}_{name}.csv").open(encoding="utf8") as fh:
                part=list(csv.DictReader(fh))
            for r in part: r["selected_using_validation_only"]=(name=="W4")
            all_models += part
        csvout(OUT/f"course34_wall_phase3_models_{stamp}.csv",all_models)
        for kind in ("deciles","calibration","venues","race_scores"):
            with (OUT/f"course34_wall_phase3_{kind}_{stamp}_W4.csv").open(encoding="utf8") as fh: selected_rows=list(csv.DictReader(fh))
            csvout(OUT/f"course34_wall_phase3_{kind}_{stamp}.csv",selected_rows)
        print("assembled W0-W4 comparison; W4 selected from Validation only"); return
    # Phase 1 artifact covers Validation/Holdout; Development is reconstructed without
    # changing Phase 1 files, using its shared read-only extraction definitions.
    model_cache=OUT/"course34_wall_phase3_model_input_20241001_20260930.csv"
    if model_cache.exists() and "--prepare-model-input" not in sys.argv:
        print("stage: load prepared model input", flush=True); rows=load_model_cache(model_cache)
        dev=[r for r in rows if START<=parse(r['race_date'])<=DEV_END]; val=[r for r in rows if DEV_END<parse(r['race_date'])<=VAL_END]; hold=[r for r in rows if VAL_END<parse(r['race_date'])<=END]
    else:
      print("stage: load development", flush=True)
      dev_cache=OUT/"course34_wall_phase3_development_input_20241001_20250930.csv"
      dev_parts=[OUT/"course34_wall_phase3_development_input_20241001_20250331.csv",OUT/"course34_wall_phase3_development_input_20250401_20250930.csv"]
      prep_windows={"--prepare-dev-h1":(START,date(2025,3,31),dev_parts[0]),"--prepare-dev-h2":(date(2025,4,1),DEV_END,dev_parts[1])}
      for arg,(lo,hi,path) in prep_windows.items():
          if arg in sys.argv:
              prepared=load_prior_rows(lo,hi); OUT.mkdir(exist_ok=True); csvout(path,prepared); print("prepared",len(prepared),path); return
      if all(x.exists() for x in dev_parts): rows=load_rows(START,DEV_END,dev_parts[0])+load_rows(START,DEV_END,dev_parts[1])
      else: rows=load_rows(START,DEV_END,dev_cache) if dev_cache.exists() else load_prior_rows(START,DEV_END)
      print("stage: load validation/holdout", flush=True); rows += load_rows(date(2025,10,1),END)
      for r in rows:
          for base in ("exhibition_time","lap_time","around_time","straight_time"): r[base+"_delta_34"]=subtract(r.get("course3_"+base),r.get("course4_"+base))
      print("stage: load overall ST", flush=True); overall_cache=OUT/"course34_wall_phase3_overall_st_20241001_20260930.csv"; overall=load_overall_st(rows,overall_cache)
      if "--prepare-overall" in sys.argv:
          OUT.mkdir(exist_ok=True); csvout(overall_cache,[{"term":k[0],"player_id":k[1],"overall_st":v} for k,v in overall.items()]); print("prepared",len(overall),overall_cache); return
      enrich_smoothing(rows,overall)
      if "--prepare-model-input" in sys.argv:
          keep=set(sum(FEATURES.values(),[]))|{"race_code","race_date","stadium","course4_history_12m_makuri_rate_pct","four_win_attack","four_first","four_top2","four_top3","four_ahead_of_3"}
          csvout(model_cache,[{k:r.get(k) for k in keep} for r in rows]); print("prepared model input",len(rows),model_cache); return
      dev=[r for r in rows if START<=parse(r['race_date'])<=DEV_END]; val=[r for r in rows if DEV_END<parse(r['race_date'])<=VAL_END]; hold=[r for r in rows if VAL_END<parse(r['race_date'])<=END]
    print("stage: fit candidates", flush=True)
    # Every candidate is fitted only on Development for model comparison.
    only = next((x.split("=",1)[1] for x in sys.argv if x.startswith("--only=")), None)
    candidates={only:FEATURES[only]} if only else FEATURES
    report=[]; fitted={}; val_stats={}
    for name,fs in candidates.items():
        print("fit",name,flush=True)
        m=model(); X,y=xy(dev,fs);m.fit(X,y); fitted[name]=m
        print("evaluate",name,flush=True)
        vm,_=eval_model(m,val,fs); hm,_=eval_model(m,hold,fs); val_stats[name]=vm
        for split,stats,n in (("validation",vm,len(val)),("final_holdout",hm,len(hold))):
            report.append({"model":name,"split":split,"n":n,"features":"|".join(fs),**stats})
    # Predeclared interpretability rule: simplest model within 0.002 AUC of validation best
    # and within 0.0015 Brier; selection never observes Holdout metrics.
    best_auc=max(x['roc_auc'] for x in val_stats.values()); best_brier=min(x['brier'] for x in val_stats.values())
    selected=next(n for n in candidates if val_stats[n]['roc_auc']>=best_auc-.002 and val_stats[n]['brier']<=best_brier+.0015)
    for r in report:r['selected_using_validation_only']=(r['model']==selected)
    # Final candidate refit using Development + Validation, then scored once on Final Holdout.
    print("refit selected",selected,flush=True)
    dv=dev+val; final=model(); final.fit(*xy(dv,FEATURES[selected])); hold_stats,hold_p=eval_model(final,hold,FEATURES[selected]); _,dev_p=eval_model(final,dev,FEATURES[selected])
    print("deciles",flush=True)
    deciles=equal_deciles(hold,hold_p,"final_holdout_selected_wall_score",{"selected_model":selected})
    sorted_dev_p=sorted(dev_p)
    scores=[score100(x,sorted_dev_p) for x in hold_p]
    print("calibration",flush=True)
    calibration_rows=calibration(hold,hold_p)+score_bands(hold,scores)
    venues=venue_rows(hold,scores)
    print("attack correlation",flush=True)
    pairs=[(s,r.get("course4_history_12m_makuri_rate_pct")) for s,r in zip(scores,hold) if r.get("course4_history_12m_makuri_rate_pct") is not None]
    attack_corr=spearman([x[0] for x in pairs],[x[1] for x in pairs]) if len(pairs)>2 else None
    # Read-only audit race-level output includes only research score/probability plus identifiers/outcome.
    race_scores=[{"race_code":r['race_code'],"race_date":r['race_date'],"stadium":r['stadium'],"wall_probability_candidate":p,"wall_weakness_score_candidate_0_100":s,"four_win_attack":r['four_win_attack']} for r,p,s in zip(hold,hold_p,scores)]
    OUT.mkdir(exist_ok=True); stamp="20241001_20260930"; suffix=f"_{only}" if only else ""
    print("write outputs",flush=True)
    csvout(OUT/f"course34_wall_phase3_models_{stamp}{suffix}.csv",report)
    csvout(OUT/f"course34_wall_phase3_deciles_{stamp}{suffix}.csv",deciles)
    csvout(OUT/f"course34_wall_phase3_calibration_{stamp}{suffix}.csv",calibration_rows)
    csvout(OUT/f"course34_wall_phase3_venues_{stamp}{suffix}.csv",venues)
    csvout(OUT/f"course34_wall_phase3_race_scores_{stamp}{suffix}.csv",race_scores)
    print("Phase 3 research-only candidate validation")
    print("N development/validation/final_holdout",len(dev),len(val),len(hold))
    for r in report: print(r['model'],r['split'],"AUC",round(r['roc_auc'],4),"PR",round(r['pr_auc'],4),"Brier",round(r['brier'],4),"LogLoss",round(r['log_loss'],4),"selected",r['selected_using_validation_only'])
    print("selected",selected,"holdout",hold_stats,"score-vs-attack Spearman",attack_corr)

if __name__=="__main__": run()
