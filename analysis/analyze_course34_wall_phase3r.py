#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Phase 3R: reproducible, research-only 3C→4C Wall model.

This script is intentionally independent of the non-reproducible Phase 3 W4
artifact.  It selects a linear model using Development/Validation only, writes
fully self-contained JSON artifacts, verifies artifact round-trips, then uses
the fixed FINAL artifact for confirmation and forward score sanity checks.
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
from datetime import date, datetime
from pathlib import Path

import numpy as np
from sklearn.metrics import average_precision_score, brier_score_loss, log_loss, roc_auc_score

ROOT = Path(__file__).resolve().parent
OUT = ROOT / "output"
START, DEV_END, VAL_END, CONF_END = date(2024, 10, 1), date(2025, 9, 30), date(2026, 3, 31), date(2026, 9, 30)
PRIMARY = "four_win_attack"
K_VALUES = (10.0, 20.0, 30.0)

# The deltas are C3 minus C4.  Positive therefore consistently means a C4
# advantage / weaker 3C wall.  Reliability is the corresponding C4-minus-C3
# certainty advantage after smoothing.
FEATURE_DEFINITIONS = {
    "smoothed_avg_st_delta_34": "smoothed(C3 course ST) - smoothed(C4 course ST); positive means C4 faster",
    "ex_st_delta_34": "C3 exhibition ST - C4 exhibition ST; positive means C4 faster",
    "avg_st_rank_delta_34": "C3 average-ST rank - C4 average-ST rank; positive means C4 better rank",
    "st_profile_reliability_advantage_34": "C4 smoothing weight - C3 smoothing weight; positive means more reliable C4 course-ST profile",
    "exhibition_time_delta_34": "C3 exhibition time - C4 exhibition time; positive means C4 faster",
    "lap_time_delta_34": "C3 lap time - C4 lap time; positive means C4 faster",
    "around_time_delta_34": "C3 around time - C4 around time; positive means C4 faster",
    "straight_time_delta_34": "C3 straight time - C4 straight time; positive means C4 faster",
}
MODELS = {
    "R0": ("smoothed_avg_st_delta_34",),
    "R1": ("smoothed_avg_st_delta_34", "ex_st_delta_34"),
    "R2": ("smoothed_avg_st_delta_34", "ex_st_delta_34", "avg_st_rank_delta_34", "st_profile_reliability_advantage_34"),
    "R3": ("smoothed_avg_st_delta_34", "ex_st_delta_34", "avg_st_rank_delta_34", "st_profile_reliability_advantage_34", "exhibition_time_delta_34", "lap_time_delta_34", "around_time_delta_34", "straight_time_delta_34"),
}


def f(v):
    try:
        x = float(v)
        return x if math.isfinite(x) else None
    except (TypeError, ValueError):
        return None


def yes(v):
    return str(v).lower() in {"1", "true", "t"}


def parse(s):
    return datetime.strptime(s, "%Y-%m-%d").date()


def term_for(d):
    return f"{(d.year - 1) % 100:02d}10" if d.month <= 4 else (f"{d.year % 100:02d}04" if d.month <= 10 else f"{d.year % 100:02d}10")


def read_csv(path):
    with path.open(encoding="utf-8") as h:
        return list(csv.DictReader(h))


def sha256_paths(paths):
    h = hashlib.sha256()
    for p in paths:
        h.update(p.name.encode("utf-8")); h.update(b"\0")
        h.update(p.read_bytes()); h.update(b"\0")
    return h.hexdigest()


def git_head():
    try:
        return subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT.parent, text=True).strip()
    except Exception:
        return None


def source_rows():
    paths = (
        OUT / "course34_wall_phase3_development_input_20241001_20250331.csv",
        OUT / "course34_wall_phase3_development_input_20250401_20250930.csv",
        OUT / "course34_wall_phase1_races_20251001_20260930.csv",
    )
    overall_path = OUT / "course34_wall_phase3_overall_st_20241001_20260930.csv"
    if not all(p.exists() for p in (*paths, overall_path)):
        raise RuntimeError("Phase 3R requires the existing read-only Phase 1/3 input caches")
    rows = sum((read_csv(p) for p in paths), [])
    overall = {(r["term"], r["player_id"]): f(r["overall_st"]) for r in read_csv(overall_path)}
    return rows, overall, (*paths, overall_path)


def add_features(rows, overall, k):
    out = []
    for raw in rows:
        r = dict(raw)
        for c in (3, 4):
            for name in ("average_st", "entry_count", "ex_st", "average_st_rank", "exhibition_time", "lap_time", "around_time", "straight_time"):
                r[f"course{c}_{name}"] = f(r.get(f"course{c}_{name}"))
            base = overall.get((term_for(parse(r["race_date"])), r[f"course{c}_player_id"]))
            n = r[f"course{c}_entry_count"] or 0.0
            weight = n / (n + k) if n > 0 else 0.0
            course = r[f"course{c}_average_st"]
            r[f"_smoothed{c}"] = base + weight * (course - base) if base is not None and course is not None else course
            r[f"_weight{c}"] = weight
        r["smoothed_avg_st_delta_34"] = subtract(r["_smoothed3"], r["_smoothed4"])
        r["st_profile_reliability_advantage_34"] = r["_weight4"] - r["_weight3"]
        r["avg_st_rank_delta_34"] = subtract(r["course3_average_st_rank"], r["course4_average_st_rank"])
        for name in ("ex_st", "exhibition_time", "lap_time", "around_time", "straight_time"):
            r[f"{name}_delta_34"] = subtract(r[f"course3_{name}"], r[f"course4_{name}"])
        out.append(r)
    return out


def subtract(a, b):
    return a - b if a is not None and b is not None else None


def split(rows):
    return (
        [r for r in rows if START <= parse(r["race_date"]) <= DEV_END],
        [r for r in rows if DEV_END < parse(r["race_date"]) <= VAL_END],
        [r for r in rows if VAL_END < parse(r["race_date"]) <= CONF_END],
    )


def xy(rows, features):
    return (np.asarray([[np.nan if r.get(x) is None else r[x] for x in features] for r in rows], dtype=np.float64),
            np.asarray([int(yes(r[PRIMARY])) for r in rows], dtype=np.int64))


class LinearLogit:
    """Deterministic Newton logistic regression with explicit preprocessing."""
    def __init__(self, ridge=1e-6, max_iter=50, tolerance=1e-10, clip=35.0):
        self.ridge, self.max_iter, self.tolerance, self.clip = ridge, max_iter, tolerance, clip

    def fit(self, x, y):
        self.median_ = np.nanmedian(x, axis=0)
        x = np.where(np.isnan(x), self.median_, x)
        self.mean_ = x.mean(axis=0)
        self.scale_ = x.std(axis=0, ddof=0)
        self.scale_[self.scale_ < 1e-12] = 1.0
        z = np.column_stack((np.ones(len(x)), (x-self.mean_)/self.scale_))
        beta = np.zeros(z.shape[1], dtype=np.float64)
        self.iterations_ = 0
        for i in range(self.max_iter):
            p = self._sigmoid(z @ beta)
            w = np.maximum(p*(1-p), 1e-8)
            gradient = z.T @ (y-p) - self.ridge*np.r_[0.0, beta[1:]]
            hessian = (z.T*w) @ z + np.diag(np.r_[0.0, np.full(len(beta)-1, self.ridge)])
            step = np.linalg.solve(hessian, gradient)
            beta += step; self.iterations_ = i+1
            if np.max(np.abs(step)) < self.tolerance:
                break
        self.beta_ = beta
        return self

    def _sigmoid(self, z):
        return 1.0/(1.0+np.exp(-np.clip(z, -self.clip, self.clip)))

    def predict(self, x):
        x = np.where(np.isnan(x), self.median_, x)
        z = np.column_stack((np.ones(len(x)), (x-self.mean_)/self.scale_))
        return self._sigmoid(z @ self.beta_)


def metric_dict(y, p):
    return {"roc_auc": float(roc_auc_score(y, p)), "pr_auc": float(average_precision_score(y, p)), "brier": float(brier_score_loss(y, p)), "log_loss": float(log_loss(y, p, labels=[0, 1]))}


def deciles(rows, p, label, extra):
    order = sorted(range(len(rows)), key=lambda i: (p[i], rows[i]["race_code"]))
    bucket = {i: min(10, rank*10//len(rows)+1) for rank, i in enumerate(order)}
    out = []
    for q in range(1, 11):
        idx = [i for i in range(len(rows)) if bucket[i] == q]
        d = {"dataset": label, "decile": q, "n": len(idx), "mean_probability": float(np.mean(p[idx])), **extra}
        for key in ("four_win_attack", "four_first", "four_top2", "four_top3"):
            d[key+"_rate_pct"] = 100*sum(yes(rows[i][key]) for i in idx)/len(idx)
        out.append(d)
    return out


def score_bands(rows, scores, extra):
    out = []
    for lo, hi in ((0,19),(20,39),(40,59),(60,79),(80,100)):
        idx = [i for i,s in enumerate(scores) if lo <= s <= hi]
        d = {"score_band": f"{lo}-{hi}", "n": len(idx), **extra}
        for key in ("four_win_attack", "four_first", "four_top2", "four_top3"):
            d[key+"_rate_pct"] = None if not idx else 100*sum(yes(rows[i][key]) for i in idx)/len(idx)
        out.append(d)
    return out


def venue_direction(rows, scores, extra):
    out = []
    for venue in sorted({r["stadium"] for r in rows}):
        low = [i for i,(r,s) in enumerate(zip(rows,scores)) if r["stadium"] == venue and s < 20]
        high = [i for i,(r,s) in enumerate(zip(rows,scores)) if r["stadium"] == venue and s >= 80]
        rate = lambda ids: None if not ids else 100*sum(yes(rows[i][PRIMARY]) for i in ids)/len(ids)
        lo, hi = rate(low), rate(high)
        out.append({"stadium": venue, "low_n": len(low), "low_attack_rate_pct": lo, "high_n": len(high), "high_attack_rate_pct": hi, "high_minus_low_pct_point": None if lo is None or hi is None else hi-lo, "direction_high_gt_low": None if lo is None or hi is None else hi > lo, **extra})
    return out


def prob_hash(p):
    return hashlib.sha256(np.asarray(p, dtype="<f8").tobytes()).hexdigest()


def percentile_boundaries(p):
    return [float(np.quantile(p, q/100.0, method="linear")) for q in range(101)]


def to_score(p, boundaries):
    return [max(0, min(100, bisect.bisect_right(boundaries, float(x))-1)) for x in p]


def distribution(p):
    return {"n": int(len(p)), "min": float(np.min(p)), "max": float(np.max(p)), "mean": float(np.mean(p)), "std": float(np.std(p)), "quantiles": {str(q): float(np.quantile(p, q)) for q in (0,.01,.05,.1,.25,.5,.75,.9,.95,.99,1)}}


def artifact(role, name, features, k, model, input_hash, dev_p, boundaries=None, score_reference_p=None):
    a = {
        "artifact_version": "phase3r-v1", "model_role": role, "model_name": name,
        "created_at": datetime.now().astimezone().isoformat(),
        "development_period": "2024-10-01..2025-09-30", "validation_period": "2025-10-01..2026-03-31",
        "feature_names": list(features), "feature_definitions": {x: FEATURE_DEFINITIONS[x] for x in features},
        "sign_convention": "positive = C4 advantage / weaker C3 wall", "st_smoothing_k": k,
        "missing_fill_values": model.median_.tolist(), "scaler_mean": model.mean_.tolist(), "scaler_std": model.scale_.tolist(), "scaler_ddof": 0,
        "intercept": float(model.beta_[0]), "coefficients": model.beta_[1:].tolist(),
        "model_type": "deterministic_newton_logistic_regression", "optimizer": "Newton-Raphson full Hessian",
        "regularization": {"type": "L2 ridge", "lambda": model.ridge, "intercept_regularized": False},
        "tolerance": model.tolerance, "max_iterations": model.max_iter, "iterations_used": model.iterations_,
        "logit_clipping": [-model.clip, model.clip], "source_script": str(Path(__file__).relative_to(ROOT.parent)),
        "source_git_commit": git_head(), "input_dataset_sha256": input_hash,
        "development_prediction_sha256": prob_hash(dev_p), "development_probability_distribution": distribution(dev_p),
        "score_percentile_boundaries": boundaries,
    }
    if score_reference_p is not None:
        a["score_reference_population"] = "Development + Validation"
        a["score_reference_prediction_sha256"] = prob_hash(score_reference_p)
        a["score_reference_probability_distribution"] = distribution(score_reference_p)
    return a


def predict_artifact(a, x):
    median, mean, scale = (np.asarray(a[k], dtype=np.float64) for k in ("missing_fill_values", "scaler_mean", "scaler_std"))
    x = np.where(np.isnan(x), median, x)
    beta = np.r_[a["intercept"], np.asarray(a["coefficients"], dtype=np.float64)]
    z = np.column_stack((np.ones(len(x)), (x-mean)/scale)) @ beta
    lo, hi = a["logit_clipping"]
    return 1/(1+np.exp(-np.clip(z, lo, hi)))


def roundtrip(a, data):
    values = {}
    for label, (x, original) in data.items():
        rebuilt = predict_artifact(a, x); delta = np.abs(original-rebuilt)
        values[label] = {"n": int(len(delta)), "max_abs_diff": float(np.max(delta)), "mean_abs_diff": float(np.mean(delta)), "spearman": float(spearman(original, rebuilt)), "exact_match_count": int(np.sum(original == rebuilt)), "within_1e12_count": int(np.sum(delta <= 1e-12))}
    return values


def spearman(a, b):
    # There are no practical ties in probabilities, but this deterministic rank
    # calculation also handles them without depending on a runtime library.
    def ranks(x):
        order = np.argsort(x, kind="mergesort"); r = np.empty(len(x)); i = 0
        while i < len(x):
            j = i
            while j+1 < len(x) and x[order[j+1]] == x[order[i]]: j += 1
            r[order[i:j+1]] = (i+j+2)/2; i = j+1
        return r
    return np.corrcoef(ranks(a), ranks(b))[0,1]


def write_csv(path, rows):
    keys = sorted({k for r in rows for k in r})
    with path.open("w", newline="", encoding="utf-8") as h:
        w = csv.DictWriter(h, fieldnames=keys); w.writeheader(); w.writerows(rows)


def run_research():
    raw, overall, paths = source_rows(); input_hash = sha256_paths(paths)
    comparisons, all_prepared, validation_probabilities = [], {}, {}
    for k in K_VALUES:
        prepared = add_features(raw, overall, k); all_prepared[k] = prepared
        dev, val, _ = split(prepared)
        for name, features in MODELS.items():
            m = LinearLogit().fit(*xy(dev, features)); vp = m.predict(xy(val, features)[0])
            validation_probabilities[(k, name)] = vp
            comparisons.append({"k": k, "model": name, "features": "|".join(features), "development_n": len(dev), "validation_n": len(val), **metric_dict(xy(val, features)[1], vp)})
    # Predeclared rule: highest Validation ROC-AUC; ties within 1e-12 then lower
    # model complexity and lower K.  Confirmation is never inspected here.
    best = sorted(comparisons, key=lambda r: (-r["roc_auc"], len(MODELS[r["model"]]), r["k"]))[0]
    k, name, features = best["k"], best["model"], MODELS[best["model"]]
    dev, val, confirmation = split(all_prepared[k])
    xdev, ydev, xval, yval, xconf, yconf = *xy(dev,features), *xy(val,features), *xy(confirmation,features)
    m1, m2 = LinearLogit().fit(xdev,ydev), LinearLogit().fit(xdev,ydev)
    pdev, pval = m1.predict(xdev), m1.predict(xval)
    reproducibility = {"intercept_max_abs_diff": float(abs(m1.beta_[0]-m2.beta_[0])), "coefficient_max_abs_diff": float(np.max(np.abs(m1.beta_[1:]-m2.beta_[1:]))), "median_max_abs_diff": float(np.max(np.abs(m1.median_-m2.median_))), "mean_max_abs_diff": float(np.max(np.abs(m1.mean_-m2.mean_))), "std_max_abs_diff": float(np.max(np.abs(m1.scale_-m2.scale_))), "development_prediction_max_abs_diff": float(np.max(np.abs(pdev-m2.predict(xdev))))}
    selection = artifact("WALL-R-SELECTION", name, features, k, m1, input_hash, pdev)
    selection_path = OUT/"course34_wall_phase3r_selection_model.json"; selection_path.write_text(json.dumps(selection, ensure_ascii=False, indent=2), encoding="utf-8")
    selection_rt = roundtrip(selection, {"development": (xdev,pdev), "validation": (xval,pval)})
    final = LinearLogit().fit(np.vstack((xdev,xval)), np.r_[ydev,yval])
    pfinal_dev, pdv, pconf = final.predict(xdev), final.predict(np.vstack((xdev,xval))), final.predict(xconf)
    boundaries = percentile_boundaries(pdv)
    final_art = artifact("WALL-R-FINAL", name, features, k, final, input_hash, pfinal_dev, boundaries, pdv)
    final_path = OUT/"course34_wall_phase3r_final_model.json"; final_path.write_text(json.dumps(final_art, ensure_ascii=False, indent=2), encoding="utf-8")
    final_rt = roundtrip(final_art, {"development_plus_validation": (np.vstack((xdev,xval)),pdv), "exposed_confirmation": (xconf,pconf)})
    conf_scores = to_score(pconf, boundaries)
    validation_deciles = []
    for r in comparisons:
        _, vv, _ = split(all_prepared[r["k"]])
        validation_deciles += deciles(vv, validation_probabilities[(r["k"], r["model"])], "validation", {"k":r["k"],"model":r["model"]})
    write_csv(OUT/"course34_wall_phase3r_candidates.csv", comparisons)
    write_csv(OUT/"course34_wall_phase3r_validation_deciles.csv", validation_deciles)
    write_csv(OUT/"course34_wall_phase3r_exposed_confirmation_deciles.csv", deciles(confirmation,pconf,"exposed_confirmation",{"model":name,"k":k}))
    write_csv(OUT/"course34_wall_phase3r_exposed_confirmation_score_bands.csv", score_bands(confirmation,conf_scores,{"model":name,"k":k}))
    write_csv(OUT/"course34_wall_phase3r_exposed_confirmation_venues.csv", venue_direction(confirmation,conf_scores,{"model":name,"k":k}))
    write_csv(OUT/"course34_wall_phase3r_exposed_confirmation_scores.csv", [{"race_code":r["race_code"],"race_date":r["race_date"],"stadium":r["stadium"],"wall_r_probability":float(p),"wall_r_score_0_100":s} for r,p,s in zip(confirmation,pconf,conf_scores)])
    report = {"selection_rule":"highest Validation ROC-AUC; exact ties -> simpler model then lower K", "selection":best, "selection_validation_metrics":metric_dict(yval,pval), "fit_reproducibility":reproducibility, "selection_roundtrip":selection_rt, "final_refit":{"fit_population":"Development + Validation","n":len(ydev)+len(yval)}, "final_roundtrip":final_rt, "exposed_confirmation":{"n":len(yconf), **metric_dict(yconf,pconf), "score_bands":score_bands(confirmation,conf_scores,{})}, "input_dataset_sha256":input_hash, "source_git_commit":git_head()}
    (OUT/"course34_wall_phase3r_report.json").write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding="utf-8")
    print(json.dumps(report,ensure_ascii=False,indent=2))


def run_forward():
    art_path = OUT/"course34_wall_phase3r_final_model.json"
    if not art_path.exists(): raise RuntimeError("run research first: FINAL artifact is absent")
    artifact_data = json.loads(art_path.read_text(encoding="utf-8"))
    # This uses the fixed artifact only.  The legacy Phase 1 extractor is read
    # only and outcomes are deliberately discarded before any score is written.
    from analyze_course34_wall_phase1 import build_rows, latest_result_date, load_racer, load_targets
    end = latest_result_date(); start = date(2026,10,1)
    if end is None or end < start: raise RuntimeError("no completed TRUE_FORWARD races available")
    races = load_targets(start,end); terms = sorted({term_for(date.fromordinal(start.toordinal()+i)) for i in range((end-start).days+1)})
    rows = build_rows(races, load_racer(terms), {}, defaultdict(int))
    # Use present racer-results overall ST for the same read-only term lookup.
    from slit_validate_v2 import connect_db
    pids = sorted({r[x] for r in rows for x in ("course3_player_id","course4_player_id")})
    overall = {}
    with connect_db() as conn, conn.cursor() as cur:
        cur.execute("SELECT term_info::text,player_id::text,average_start FROM boat_race.racer_results WHERE term_info::text=ANY(%s) AND player_id::text=ANY(%s)",(terms,pids))
        overall = {(str(t).strip(),str(pid).strip()):f(st) for t,pid,st in cur.fetchall()}
    prepared = add_features(rows,overall,artifact_data["st_smoothing_k"]); features = artifact_data["feature_names"]
    x,_ = xy(prepared,features); p = predict_artifact(artifact_data,x); scores = to_score(p,artifact_data["score_percentile_boundaries"])
    missing = np.isnan(x).sum(axis=1)
    stamp=f"{start:%Y%m%d}_{end:%Y%m%d}"
    write_csv(OUT/f"course34_wall_phase3r_true_forward_scores_{stamp}.csv", [{"race_code":r["race_code"],"race_date":r["race_date"],"stadium":r["stadium"],"wall_r_probability":float(q),"wall_r_score_0_100":s,"missing_feature_count":int(m)} for r,q,s,m in zip(prepared,p,scores,missing)])
    summary={"period":f"{start}..{end}","n":len(prepared),"score_generation_success_rate_pct":100.0,"rows_with_any_missing_feature":int(np.sum(missing>0)),"missing_feature_value_rate_pct":float(100*np.isnan(x).sum()/x.size),"score_distribution":{"min":int(min(scores)),"max":int(max(scores)),"mean":float(np.mean(scores)),"std":float(np.std(scores)),"counts":{str(i):int(scores.count(i)) for i in range(101) if scores.count(i)}}}
    (OUT/f"course34_wall_phase3r_true_forward_sanity_{stamp}.json").write_text(json.dumps(summary,ensure_ascii=False,indent=2),encoding="utf-8")
    print(json.dumps(summary,ensure_ascii=False,indent=2))


if __name__ == "__main__":
    parser = argparse.ArgumentParser(); parser.add_argument("--forward", action="store_true"); args=parser.parse_args()
    run_forward() if args.forward else run_research()
