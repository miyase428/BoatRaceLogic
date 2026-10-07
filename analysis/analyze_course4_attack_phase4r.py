#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Phase 4R: freeze fixed A5 and diagnose it with frozen WALL-R.

Research-only.  This never reads legacy W4 scores and never changes the
existing Phase 1--4 sources or outputs.  A5's historical fixed implementation
is reproduced exactly; the only new artifacts are Phase 4R artifacts/results.
"""
from __future__ import annotations

import argparse
import bisect
import csv
import hashlib
import json
import math
import subprocess
from collections import defaultdict
from datetime import date, datetime, timedelta
from pathlib import Path

import numpy as np
from sklearn.metrics import average_precision_score, brier_score_loss, log_loss, roc_auc_score

ROOT = Path(__file__).resolve().parent
OUT = ROOT / "output"
START, DEV_END, VAL_END, CONF_END = date(2024, 10, 1), date(2025, 9, 30), date(2026, 3, 31), date(2026, 9, 30)
PRIMARY = "four_win_attack"
A5_K = 20.0
BASE = ("course4_history_n", "course4_history_win_rate_pct", "course4_history_top2_rate_pct", "course4_history_top3_rate_pct", "course4_history_makuri_rate_pct", "course4_history_makurizashi_rate_pct", "course4_history_attack_rate_pct")
A5_FEATURES = BASE + ("smoothed_course4_st", "course4_average_st_rank", "course4_entry_count", "course4_ex_st", "course4_exhibition_time", "course4_lap_time", "course4_around_time", "course4_straight_time", "motor_2ren_rate", "boat_2ren_rate", "national_win_rate", "local_win_rate", "national_2ren_rate", "local_2ren_rate")
WALL_FEATURES = ("smoothed_avg_st_delta_34", "ex_st_delta_34", "avg_st_rank_delta_34", "st_profile_reliability_advantage_34", "exhibition_time_delta_34", "lap_time_delta_34", "around_time_delta_34", "straight_time_delta_34")

FEATURE_DEFINITIONS = {
    "course4_history_n": "4C starts in strictly prior 12 calendar months", "course4_history_win_rate_pct": "4C prior-12-month win rate", "course4_history_top2_rate_pct": "4C prior-12-month top-2 rate", "course4_history_top3_rate_pct": "4C prior-12-month top-3 rate", "course4_history_makuri_rate_pct": "4C prior-12-month makuri-win rate", "course4_history_makurizashi_rate_pct": "4C prior-12-month makurizashi-win rate", "course4_history_attack_rate_pct": "4C prior-12-month makuri + makurizashi win rate",
    "smoothed_course4_st": "K=20 shrinkage of own C4 ST toward own overall ST", "course4_average_st_rank": "own C4 average-ST rank", "course4_entry_count": "own C4 entry count", "course4_ex_st": "own exhibition start timing", "course4_exhibition_time": "own exhibition time", "course4_lap_time": "own lap time", "course4_around_time": "own around time", "course4_straight_time": "own straight time", "motor_2ren_rate": "motor 2-ren rate", "boat_2ren_rate": "boat 2-ren rate", "national_win_rate": "own national win rate", "local_win_rate": "own local win rate", "national_2ren_rate": "own national 2-ren rate", "local_2ren_rate": "own local 2-ren rate",
}


def number(v):
    try:
        x = float(v)
        return x if math.isfinite(x) else np.nan
    except (TypeError, ValueError):
        return np.nan


def yes(v): return str(v).lower() in {"true", "1", "t"}
def parsedate(v): return datetime.strptime(v, "%Y-%m-%d").date()
def term_for(d): return f"{(d.year-1)%100:02d}10" if d.month <= 4 else (f"{d.year%100:02d}04" if d.month <= 10 else f"{d.year%100:02d}10")


def read_csv(path):
    with path.open(encoding="utf-8") as h: return list(csv.DictReader(h))


def write_csv(path, rows):
    keys = sorted({k for r in rows for k in r})
    with path.open("w", newline="", encoding="utf-8") as h:
        w = csv.DictWriter(h, fieldnames=keys); w.writeheader(); w.writerows(rows)


def paths_hash(paths):
    h = hashlib.sha256()
    for p in paths:
        h.update(p.name.encode()); h.update(b"\0"); h.update(p.read_bytes()); h.update(b"\0")
    return h.hexdigest()


def git_head():
    return subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT.parent, text=True).strip()


def source_rows():
    base_path = OUT / "course4_attack_phase4_input_20241001_20260930.csv"
    cache_paths = sorted((OUT / "course4_attack_phase4_prerace").glob("*.csv"))
    if not base_path.exists() or len(cache_paths) != 24: raise RuntimeError("complete Phase 4 input and 24 monthly pre-race caches are required")
    extras = {}
    for p in cache_paths:
        for r in read_csv(p): extras[(r["race_code"], r["player_id"])] = r
    return prepare_a5(read_csv(base_path), extras), (base_path, *cache_paths)


def prepare_a5(rows, extras):
    result = []
    raw_numeric = (*BASE, "course4_average_st", "course4_average_st_rank", "course4_entry_count", "course4_ex_st", "course4_exhibition_time", "course4_lap_time", "course4_around_time", "course4_straight_time")
    extra_names = ("overall_average_st", "motor_2ren_rate", "boat_2ren_rate", "national_win_rate", "local_win_rate", "national_2ren_rate", "local_2ren_rate")
    for raw in rows:
        r = dict(raw)
        for k in raw_numeric: r[k] = number(r.get(k))
        ex = extras.get((r["race_code"], r["course4_player_id"]), {})
        for k in extra_names: r[k] = number(ex.get(k))
        count = r["course4_entry_count"]
        weight = count/(count+A5_K) if not np.isnan(count) else np.nan
        r["smoothed_course4_st"] = r["overall_average_st"] + weight*(r["course4_average_st"]-r["overall_average_st"]) if not (np.isnan(weight) or np.isnan(r["overall_average_st"]) or np.isnan(r["course4_average_st"])) else np.nan
        result.append(r)
    return result


def split(rows):
    return ([r for r in rows if START <= parsedate(r["race_date"]) <= DEV_END], [r for r in rows if DEV_END < parsedate(r["race_date"]) <= VAL_END], [r for r in rows if VAL_END < parsedate(r["race_date"]) <= CONF_END])


def x_of(rows, features): return np.asarray([[r.get(k, np.nan) for k in features] for r in rows], dtype=np.float64)
def y_of(rows): return np.asarray([int(yes(r[PRIMARY])) for r in rows], dtype=np.int64)


class A5Logit:
    """Exact fixed A5 solver: preserved iteration, ridge-Hessian, and clipping."""
    def __init__(self): self.ridge, self.max_iter, self.tolerance, self.clip = 1e-6, 25, 1e-8, 35.0
    def fit(self, x, y):
        self.median_ = np.nanmedian(x, 0); x = np.where(np.isnan(x), self.median_, x)
        self.mean_ = x.mean(0); self.scale_ = x.std(0, ddof=0); self.scale_[self.scale_ < 1e-10] = 1.0
        z = np.c_[np.ones(len(x)), (x-self.mean_)/self.scale_]; self.beta_ = np.zeros(z.shape[1]); self.iterations_ = 0
        for i in range(self.max_iter):
            p = self._sigmoid(z @ self.beta_); w = np.maximum(p*(1-p), 1e-8)
            gradient = z.T @ (y-p)
            hessian = (z.T*w) @ z + np.diag(np.r_[0.0, np.full(len(self.beta_)-1, self.ridge)])
            step = np.linalg.solve(hessian, gradient); self.beta_ += step; self.iterations_ = i+1
            if np.max(np.abs(step)) < self.tolerance: break
        return self
    def _sigmoid(self, z): return 1/(1+np.exp(-np.clip(z, -self.clip, self.clip)))
    def predict(self, x):
        x = np.where(np.isnan(x), self.median_, x)
        return self._sigmoid(np.c_[np.ones(len(x)), (x-self.mean_)/self.scale_] @ self.beta_)


class MetaLogit(A5Logit):
    """Same deterministic logistic form, used only for fixed W/A/WA comparison."""


def metrics(y, p): return {"roc_auc":float(roc_auc_score(y,p)), "pr_auc":float(average_precision_score(y,p)), "brier":float(brier_score_loss(y,p)), "log_loss":float(log_loss(y,p,labels=[0,1]))}
def prob_hash(p): return hashlib.sha256(np.asarray(p, dtype="<f8").tobytes()).hexdigest()
def distribution(p): return {"n":int(len(p)), "min":float(np.min(p)), "max":float(np.max(p)), "mean":float(np.mean(p)), "std":float(np.std(p)), "quantiles":{str(q):float(np.quantile(p,q)) for q in (0,.01,.05,.1,.25,.5,.75,.9,.95,.99,1)}}
def boundaries(p): return [float(np.quantile(p,q/100,method="linear")) for q in range(101)]
def scores(p, b): return [max(0,min(100,bisect.bisect_right(b,float(v))-1)) for v in p]


def make_artifact(role, model, input_hash, fit_p, b, reference_p=None):
    a = {"artifact_version":"phase4r-a5-v1", "model_role":role, "model_name":"A5", "created_at":datetime.now().astimezone().isoformat(), "fit_period":"2024-10-01..2025-09-30" if role.endswith("SELECTION") else "2024-10-01..2026-03-31", "validation_period":"2025-10-01..2026-03-31", "feature_names":list(A5_FEATURES), "feature_definitions":FEATURE_DEFINITIONS, "st_smoothing_k":A5_K, "missing_fill_values":model.median_.tolist(), "scaler_mean":model.mean_.tolist(), "scaler_std":model.scale_.tolist(), "scaler_ddof":0, "intercept":float(model.beta_[0]), "coefficients":model.beta_[1:].tolist(), "model_type":"deterministic_newton_logistic_regression", "optimizer":"Newton-Raphson full Hessian", "regularization":{"type":"ridge Hessian damping", "lambda":model.ridge, "intercept_regularized":False}, "tolerance":model.tolerance, "max_iterations":model.max_iter, "iterations_used":model.iterations_, "logit_clipping":[-model.clip,model.clip], "source_script":"analysis/analyze_course4_attack_phase4r.py", "source_git_commit":git_head(), "input_dataset_sha256":input_hash, "fit_prediction_sha256":prob_hash(fit_p), "fit_probability_distribution":distribution(fit_p), "score_percentile_boundaries":b}
    if reference_p is not None: a.update({"score_reference_population":"Development + Validation", "score_reference_prediction_sha256":prob_hash(reference_p), "score_reference_probability_distribution":distribution(reference_p)})
    return a


def predict_artifact(a, x):
    med, mean, sd = (np.asarray(a[k],dtype=np.float64) for k in ("missing_fill_values","scaler_mean","scaler_std")); x=np.where(np.isnan(x),med,x)
    z=np.c_[np.ones(len(x)),(x-mean)/sd] @ np.r_[a["intercept"],np.asarray(a["coefficients"])]
    lo,hi=a["logit_clipping"]; return 1/(1+np.exp(-np.clip(z,lo,hi)))


def ranks(x):
    order=np.argsort(x,kind="mergesort"); out=np.empty(len(x)); i=0
    while i<len(x):
        j=i
        while j+1<len(x) and x[order[j+1]]==x[order[i]]: j+=1
        out[order[i:j+1]]=(i+j+2)/2; i=j+1
    return out
def spearman(a,b): return float(np.corrcoef(ranks(a),ranks(b))[0,1])
def strength(v): return "weak" if abs(v)<.3 else ("moderate" if abs(v)<.6 else "strong")


def roundtrip(a, named):
    out={}
    for name,(x,original) in named.items():
        rebuilt=predict_artifact(a,x); d=np.abs(rebuilt-original)
        out[name]={"n":len(d),"max_abs_diff":float(d.max()),"mean_abs_diff":float(d.mean()),"spearman":spearman(original,rebuilt),"exact_match_count":int(np.sum(original==rebuilt)),"within_1e12_count":int(np.sum(d<=1e-12))}
    return out


def add_wall_features(rows, overall_override=None):
    cache=read_csv(OUT/"course34_wall_phase3_overall_st_20241001_20260930.csv")
    overall={(r["term"],r["player_id"]):number(r["overall_st"]) for r in cache}; result=[]
    if overall_override: overall.update({k:number(v) for k,v in overall_override.items()})
    for raw in rows:
        r=dict(raw); k=30.0
        for c in (3,4):
            for n in ("average_st","entry_count","average_st_rank","ex_st","exhibition_time","lap_time","around_time","straight_time"): r[f"course{c}_{n}"]=number(r.get(f"course{c}_{n}"))
            count=r[f"course{c}_entry_count"]; weight=count/(count+k) if not np.isnan(count) else 0.0
            base=overall.get((term_for(parsedate(r["race_date"])),r[f"course{c}_player_id"])); own=r[f"course{c}_average_st"]
            r[f"_wall_smoothed_{c}"]=base+weight*(own-base) if base is not None and not np.isnan(base) and not np.isnan(own) else own; r[f"_wall_weight_{c}"]=weight
        def delta(n):
            a,b=r[f"course3_{n}"],r[f"course4_{n}"]; return a-b if not(np.isnan(a) or np.isnan(b)) else np.nan
        r["smoothed_avg_st_delta_34"]=r["_wall_smoothed_3"]-r["_wall_smoothed_4"] if not(np.isnan(r["_wall_smoothed_3"]) or np.isnan(r["_wall_smoothed_4"])) else np.nan
        r["st_profile_reliability_advantage_34"]=r["_wall_weight_4"]-r["_wall_weight_3"]
        for n in ("ex_st","average_st_rank","exhibition_time","lap_time","around_time","straight_time"):
            target="avg_st_rank_delta_34" if n=="average_st_rank" else f"{n}_delta_34"; r[target]=delta(n)
        result.append(r)
    return result


def thirds(p, b):
    lo,hi=b[33],b[67]
    return ["Low" if v<=lo else ("Mid" if v<=hi else "High") for v in p]


def cross_rows(rows, wall_p, attack_p, wall_b, attack_b, label):
    wg,ag=thirds(wall_p,wall_b),thirds(attack_p,attack_b); out=[]
    for w in ("Low","Mid","High"):
        for a in ("Low","Mid","High"):
            ix=[i for i in range(len(rows)) if wg[i]==w and ag[i]==a]; d={"dataset":label,"wall_band":w,"attack_band":a,"n":len(ix)}
            for key in ("four_win_attack","four_first","four_top2","four_top3"): d[key+"_rate_pct"]=None if not ix else 100*sum(yes(rows[i][key]) for i in ix)/len(ix)
            out.append(d)
    return out,wg,ag


def deciles(rows,p,label):
    order=sorted(range(len(rows)),key=lambda i:(p[i],rows[i]["race_code"])); group={i:min(10,j*10//len(rows)+1) for j,i in enumerate(order)}; out=[]
    for q in range(1,11):
        ix=[i for i in range(len(rows)) if group[i]==q]; d={"dataset":label,"decile":q,"n":len(ix),"mean_probability":float(np.mean(p[ix]))}
        for key in ("four_win_attack","four_first","four_top2","four_top3"): d[key+"_rate_pct"]=100*sum(yes(rows[i][key]) for i in ix)/len(ix)
        out.append(d)
    return out


def attack_bands(rows,p,b,label):
    out=[]
    for lo,hi in ((0,19),(20,39),(40,59),(60,79),(80,100)):
        sc=scores(p,b); ix=[i for i,s in enumerate(sc) if lo<=s<=hi]; d={"dataset":label,"score_band":f"{lo}-{hi}","n":len(ix)}
        d["four_win_attack_rate_pct"]=None if not ix else 100*sum(yes(rows[i]["four_win_attack"]) for i in ix)/len(ix)
        d["four_first_non_attack_rate_pct"]=None if not ix else 100*sum(yes(rows[i]["four_first"]) and not yes(rows[i]["four_win_attack"]) for i in ix)/len(ix)
        for key in ("four_first","four_top2","four_top3"): d[key+"_rate_pct"]=None if not ix else 100*sum(yes(rows[i][key]) for i in ix)/len(ix)
        out.append(d)
    return out


def attack_venues(rows,p,b,label):
    sc=scores(p,b);out=[]
    for venue in sorted({r["stadium"] for r in rows}):
        low=[i for i,(r,s) in enumerate(zip(rows,sc)) if r["stadium"]==venue and s<20]; high=[i for i,(r,s) in enumerate(zip(rows,sc)) if r["stadium"]==venue and s>=80]
        rate=lambda ids: None if not ids else 100*sum(yes(rows[i][PRIMARY]) for i in ids)/len(ids); l,h=rate(low),rate(high)
        out.append({"dataset":label,"stadium":venue,"n":sum(r["stadium"]==venue for r in rows),"low_attack_n":len(low),"high_attack_n":len(high),"low_attack_rate_pct":l,"high_attack_rate_pct":h,"high_minus_low_pct_point":None if l is None or h is None else h-l,"direction_high_gt_low":None if l is None or h is None else h>l})
    return out


def meta_compare(train_y, train_w, train_a, test_rows, test_w, test_a, label):
    out=[]; dec=[]; coefficients={}
    for name,train_x,test_x in (("W",train_w[:,None],test_w[:,None]),("A",train_a[:,None],test_a[:,None]),("WA",np.c_[train_w,train_a],np.c_[test_w,test_a])):
        m=MetaLogit().fit(train_x,train_y); p=m.predict(test_x); out.append({"dataset":label,"model":name,"n":len(test_rows),**metrics(y_of(test_rows),p)})
        dec += [dict(x,model=name) for x in deciles(test_rows,p,label)]
        if name=="WA":
            raw=m.beta_[1:]/m.scale_; raw_intercept=m.beta_[0]-np.sum(m.beta_[1:]*m.mean_/m.scale_)
            coefficients={"standardized_intercept":float(m.beta_[0]),"standardized_wall_coefficient":float(m.beta_[1]),"standardized_attack_coefficient":float(m.beta_[2]),"raw_intercept":float(raw_intercept),"raw_wall_coefficient":float(raw[0]),"raw_attack_coefficient":float(raw[1]),"both_standardized_coefficients_positive":bool(m.beta_[1]>0 and m.beta_[2]>0)}
    return out,dec,coefficients


def run_research():
    rows, paths=source_rows(); source_hash=paths_hash(paths); dev,val,conf=split(rows); xdev,xval,xconf=x_of(dev,A5_FEATURES),x_of(val,A5_FEATURES),x_of(conf,A5_FEATURES); ydev,yval,yconf=y_of(dev),y_of(val),y_of(conf)
    selection=A5Logit().fit(xdev,ydev); pdev,pval=selection.predict(xdev),selection.predict(xval); selection_b=boundaries(pdev)
    selection_art=make_artifact("A5-SELECTION",selection,source_hash,pdev,selection_b); (OUT/"course4_attack_phase4r_selection_model.json").write_text(json.dumps(selection_art,ensure_ascii=False,indent=2),encoding="utf-8")
    selection_rt=roundtrip(selection_art,{"development":(xdev,pdev),"validation":(xval,pval)})
    final=A5Logit().fit(np.vstack((xdev,xval)),np.r_[ydev,yval]); pfinal_dev,pdv,pconf=final.predict(xdev),final.predict(np.vstack((xdev,xval))),final.predict(xconf); final_b=boundaries(pdv)
    final_art=make_artifact("A5-FINAL",final,source_hash,pfinal_dev,final_b,pdv); (OUT/"course4_attack_phase4r_final_model.json").write_text(json.dumps(final_art,ensure_ascii=False,indent=2),encoding="utf-8")
    final_rt=roundtrip(final_art,{"development_plus_validation":(np.vstack((xdev,xval)),pdv),"exposed_confirmation":(xconf,pconf)})
    if max(v["max_abs_diff"] for v in (*selection_rt.values(),*final_rt.values()))>1e-12: raise RuntimeError("A5 artifact round-trip failed; do not continue")
    # Independently score frozen Wall artifacts over the same rows and ensure matching roles.
    wrows=add_wall_features(rows); wdev,wval,wconf=split(wrows); wall_sel=json.loads((OUT/"course34_wall_phase3r_selection_model.json").read_text()); wall_final=json.loads((OUT/"course34_wall_phase3r_final_model.json").read_text())
    wdev_sel,wval_sel=predict_artifact(wall_sel,x_of(wdev,WALL_FEATURES)),predict_artifact(wall_sel,x_of(wval,WALL_FEATURES)); wdev_final,wval_final,wconf_final=predict_artifact(wall_final,x_of(wdev,WALL_FEATURES)),predict_artifact(wall_final,x_of(wval,WALL_FEATURES)),predict_artifact(wall_final,x_of(wconf,WALL_FEATURES))
    cross_v,wgv,agv=cross_rows(val,wval_sel,pval,selection_b,selection_b,"validation")
    cross_c,wgc,agc=cross_rows(conf,wconf_final,pconf,wall_final["score_percentile_boundaries"],final_b,"exposed_confirmation")
    meta_v,meta_v_dec,meta_v_coef=meta_compare(ydev,wdev_sel,pdev,val,wval_sel,pval,"validation")
    meta_c,meta_c_dec,meta_c_coef=meta_compare(np.r_[ydev,yval],np.r_[wdev_final,wval_final],pdv,conf,wconf_final,pconf,"exposed_confirmation")
    av=attack_bands(conf,pconf,final_b,"exposed_confirmation"); venues=attack_venues(conf,pconf,final_b,"exposed_confirmation")
    # Secondary venue reference: both scores use their frozen fit-distribution thirds.
    venue_cross=[]
    for venue in sorted({r["stadium"] for r in conf}):
        low=[i for i,r in enumerate(conf) if r["stadium"]==venue and wgc[i]=="Low" and agc[i]=="Low"]; high=[i for i,r in enumerate(conf) if r["stadium"]==venue and wgc[i]=="High" and agc[i]=="High"]
        rate=lambda ids:None if not ids else 100*sum(yes(conf[i][PRIMARY]) for i in ids)/len(ids)
        venue_cross.append({"stadium":venue,"low_wall_low_attack_n":len(low),"low_wall_low_attack_rate_pct":rate(low),"high_wall_high_attack_n":len(high),"high_wall_high_attack_rate_pct":rate(high)})
    write_csv(OUT/"course4_attack_phase4r_cross_validation.csv",cross_v); write_csv(OUT/"course4_attack_phase4r_cross_exposed_confirmation.csv",cross_c); write_csv(OUT/"course4_attack_phase4r_meta_models.csv",meta_v+meta_c); write_csv(OUT/"course4_attack_phase4r_meta_deciles.csv",meta_v_dec+meta_c_dec); write_csv(OUT/"course4_attack_phase4r_attack_score_bands.csv",av); write_csv(OUT/"course4_attack_phase4r_attack_venues.csv",venues); write_csv(OUT/"course4_attack_phase4r_wall_attack_venue_reference.csv",venue_cross)
    report={"a5_selection_validation":metrics(yval,pval),"a5_final_exposed_confirmation":metrics(yconf,pconf),"a5_selection_roundtrip":selection_rt,"a5_final_roundtrip":final_rt,"wall_attack_spearman":{"validation":{"value":spearman(wval_sel,pval),"strength":strength(spearman(wval_sel,pval))},"exposed_confirmation":{"value":spearman(wconf_final,pconf),"strength":strength(spearman(wconf_final,pconf))}},"meta_validation":meta_v,"meta_exposed_confirmation":meta_c,"wa_coefficients":{"validation":meta_v_coef,"exposed_confirmation":meta_c_coef},"attack_score_bands":av,"attack_venue_direction":{"venues":len(venues),"high_gt_low_count":sum(r["direction_high_gt_low"] is True for r in venues)},"input_dataset_sha256":source_hash,"source_git_commit":git_head()}
    (OUT/"course4_attack_phase4r_report.json").write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding="utf-8"); print(json.dumps(report,ensure_ascii=False,indent=2))


def forward_history(pids, lo, hi):
    from slit_validate_v2 import connect_db
    sql="""WITH ex AS (SELECT DISTINCT ON (race_code,player_id) race_code,player_id,entry_course FROM boat_race.exhibition_live ORDER BY race_code,player_id,created_date DESC NULLS LAST)
    SELECT rm.race_date,re.race_code,re.player_id::text,rrd.rank,TRIM(COALESCE(rrd.technique,''))
    FROM boat_race.race_master rm JOIN boat_race.race_entry re ON re.race_code=rm.race_code JOIN boat_race.race_result_detail rrd ON rrd.race_code=re.race_code AND rrd.player_id=re.player_id LEFT JOIN ex ON ex.race_code=re.race_code AND ex.player_id=re.player_id
    WHERE rm.race_date >= %s AND rm.race_date <= %s AND re.player_id::text=ANY(%s) AND COALESCE(rrd.entry_course,ex.entry_course,re.lane_number)=4 AND rrd.rank::text~'^[1-6]$' ORDER BY rm.race_date,re.race_code"""
    out=defaultdict(list)
    with connect_db() as c,c.cursor() as cur:
        cur.execute(sql,(lo,hi,sorted(pids)))
        for d,code,pid,rank,tech in cur.fetchall(): out[str(pid)].append((d,str(code),int(rank)==1,str(tech or ''),int(rank)))
    return out


def profile(history,pid,d):
    def months_ago(x,n):
        total=x.year*12+x.month-1-n; y,m=divmod(total,12); return date(y,m+1,min(x.day, [31,29 if y%4==0 and (y%100!=0 or y%400==0) else 28,31,30,31,30,31,31,30,31,30,31][m]))
    rows=[x for x in history.get(pid,[]) if months_ago(d,12)<=x[0]<d]; n=len(rows); pc=lambda q:np.nan if not n else 100*q/n
    wins=sum(x[2] for x in rows); mak=sum(x[2] and x[3]=='まくり' for x in rows); mz=sum(x[2] and x[3]=='まくり差し' for x in rows)
    return {"course4_history_n":n,"course4_history_win_rate_pct":pc(wins),"course4_history_top2_rate_pct":pc(sum(x[4]<=2 for x in rows)),"course4_history_top3_rate_pct":pc(sum(x[4]<=3 for x in rows)),"course4_history_makuri_rate_pct":pc(mak),"course4_history_makurizashi_rate_pct":pc(mz),"course4_history_attack_rate_pct":pc(mak+mz)}


def run_forward():
    from analyze_course34_wall_phase1 import build_rows, latest_result_date, load_racer, load_targets
    a5=json.loads((OUT/"course4_attack_phase4r_final_model.json").read_text()); wall=json.loads((OUT/"course34_wall_phase3r_final_model.json").read_text()); start=date(2026,10,1); end=latest_result_date()
    if end is None or end<start: raise RuntimeError("no completed TRUE_FORWARD rows")
    races=load_targets(start,end); terms=sorted({term_for(start+timedelta(days=i)) for i in range((end-start).days+1)}); raw=build_rows(races,load_racer(terms),{},defaultdict(int)); pids={r["course4_player_id"] for r in raw}; all_pids={r[x] for r in raw for x in ("course3_player_id","course4_player_id")}; hist=forward_history(pids,date(2025,10,1),end)
    from slit_validate_v2 import connect_db
    extras={}
    with connect_db() as c,c.cursor() as cur:
        cur.execute("""SELECT re.race_code,re.player_id::text,ps.national_win_rate,ps.local_win_rate,ps.national_exacta_rate,ps.local_exacta_rate,es.motor_exacta_rate,es.boat_exacta_rate FROM boat_race.race_entry re JOIN boat_race.race_master rm ON rm.race_code=re.race_code LEFT JOIN boat_race.player_stats ps ON ps.race_code=re.race_code AND ps.player_id=re.player_id LEFT JOIN boat_race.engine_specs es ON es.race_code=re.race_code AND es.motor_number=re.motor_number WHERE rm.race_date >= %s AND rm.race_date <= %s AND re.player_id::text=ANY(%s)""",(start,end,sorted(pids)))
        for code,pid,nw,lw,n2,l2,m2,b2 in cur.fetchall(): extras[(str(code),str(pid))]={"national_win_rate":nw,"local_win_rate":lw,"national_2ren_rate":n2,"local_2ren_rate":l2,"motor_2ren_rate":m2,"boat_2ren_rate":b2}
        cur.execute("SELECT term_info::text,player_id::text,average_start FROM boat_race.racer_results WHERE term_info::text=ANY(%s) AND player_id::text=ANY(%s)",(terms,sorted(all_pids))); overall={(str(t).strip(),str(pid).strip()):st for t,pid,st in cur.fetchall()}
    for r in raw:
        r.update(profile(hist,r["course4_player_id"],parsedate(r["race_date"]))); ex=extras.get((r["race_code"],r["course4_player_id"]),{}); ex["overall_average_st"]=overall.get((term_for(parsedate(r["race_date"])),r["course4_player_id"])); extras[(r["race_code"],r["course4_player_id"])]=ex
    attack_rows=prepare_a5(raw,extras); wall_rows=add_wall_features(raw,overall); xa,xw=x_of(attack_rows,A5_FEATURES),x_of(wall_rows,WALL_FEATURES); pa,pw=predict_artifact(a5,xa),predict_artifact(wall,xw); sa,sw=scores(pa,a5["score_percentile_boundaries"]),scores(pw,wall["score_percentile_boundaries"])
    stamp=f"{start:%Y%m%d}_{end:%Y%m%d}"; missing_a=np.isnan(xa); missing_w=np.isnan(xw)
    write_csv(OUT/f"course4_attack_phase4r_true_forward_scores_{stamp}.csv",[{"race_code":r["race_code"],"race_date":r["race_date"],"stadium":r["stadium"],"wall_r_probability":float(qw),"wall_r_score_0_100":ww,"attack_a5_probability":float(qa),"attack_a5_score_0_100":aa,"wall_missing_feature_count":int(mw.sum()),"attack_missing_feature_count":int(ma.sum())} for r,qw,ww,qa,aa,mw,ma in zip(raw,pw,sw,pa,sa,missing_w,missing_a)])
    summary={"period":f"{start}..{end}","n":len(raw),"score_generation_rate_pct":100.0,"wall":{"rows_with_missing":int(np.sum(missing_w.any(1))),"cell_missing_rate_pct":float(100*missing_w.mean()),"missing_by_feature":{f:int(missing_w[:,i].sum()) for i,f in enumerate(WALL_FEATURES)},"score_distribution":distribution(np.asarray(sw))},"attack":{"rows_with_missing":int(np.sum(missing_a.any(1))),"cell_missing_rate_pct":float(100*missing_a.mean()),"missing_by_feature":{f:int(missing_a[:,i].sum()) for i,f in enumerate(A5_FEATURES)},"score_distribution":distribution(np.asarray(sa))},"wall_attack_spearman":spearman(pw,pa)}
    (OUT/f"course4_attack_phase4r_true_forward_sanity_{stamp}.json").write_text(json.dumps(summary,ensure_ascii=False,indent=2),encoding="utf-8"); print(json.dumps(summary,ensure_ascii=False,indent=2))


if __name__=="__main__":
    parser=argparse.ArgumentParser(); parser.add_argument("--forward",action="store_true"); args=parser.parse_args(); run_forward() if args.forward else run_research()
