#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Phase 7-2d: symmetric 5C/6C Base-vs-Plus ability adjustment only."""
from __future__ import annotations
import csv, datetime, hashlib, json, math, subprocess, time
from pathlib import Path
import numpy as np
from analyze_course4_attack_success_phase5 import MetaLogit, metrics, predict_artifact

ROOT=Path(__file__).resolve().parent; OUT=ROOT/'output'
CACHE=OUT/'course4_attack_type_phase7_follower_race_cache_20251001_20260930.csv'; METRICS_OUT=OUT/'course4_attack_type_phase7_adjustment_metrics.csv'; REPORT_OUT=OUT/'course4_attack_type_phase7_adjustment_report.json'
EXPECTED='50fca8d0cfb678adfecc219a413b04f9fb9a39571e62a4fe3b4c0deb07ddc03a'
BASE=('national_win_rate','local_win_rate','average_st','motor_2ren_rate','boat_2ren_rate'); PLUS=('p_makuri_success','p_makurizashi_success'); OUTCOMES=('second','third','top3')
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def num(v):
 try:
  x=float(v);return x if math.isfinite(x) else np.nan
 except (ValueError,TypeError):return np.nan
def git():return subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT.parent,text=True).strip()
def matrix(rows,features):return np.asarray([[num(r[f]) for f in features] for r in rows],float)
def target(rows,course,outcome):
 prefix='five' if course==5 else 'six'
 return np.asarray([int(r[f'{prefix}_{outcome}']) for r in rows],int)
def artifact(m,features):return {'feature_order':list(features),'missing_fill_values':m.median_.tolist(),'scaler_mean':m.mean_.tolist(),'scaler_std':m.scale_.tolist(),'scaler_ddof':0,'intercept':float(m.beta_[0]),'coefficients':m.beta_[1:].tolist(),'solver':'Phase 5 MetaLogit deterministic Newton-Raphson full Hessian','regularization':{'type':'ridge Hessian damping','lambda':m.ridge,'intercept_regularized':False},'tolerance':m.tolerance,'max_iterations':m.max_iter,'iterations_used':m.iterations_,'logit_clipping':[-m.clip,m.clip]}
def good(d):return {'auc':d['roc_auc']>0,'pr_auc':d['pr_auc']>0,'brier':d['brier']<0,'log_loss':d['log_loss']<0}
def worse(d):return d['roc_auc']<0 and d['pr_auc']<0 and d['brier']>0 and d['log_loss']>0
def one(fit,ev,course,outcome,plus):
 features=tuple(f'{"five" if course==5 else "six"}_{x}' for x in BASE)+(PLUS if plus else ())
 xf,xe=matrix(fit,features),matrix(ev,features);yf,ye=target(fit,course,outcome),target(ev,course,outcome)
 if plus and (np.isnan(xf[:,-2:]).sum() or np.isnan(xe[:,-2:]).sum()):raise RuntimeError('Attack Type probability missing')
 m=MetaLogit().fit(xf,yf);pf,pe=m.predict(xf),m.predict(xe);a=artifact(m,features);re=predict_artifact(a,xe);d=np.abs(pe-re)
 coefs={p:{'standardized_coefficient':float(m.beta_[len(BASE)+i+1]),'sign':'positive' if m.beta_[len(BASE)+i+1]>0 else ('negative' if m.beta_[len(BASE)+i+1]<0 else 'zero')} for i,p in enumerate(PLUS)} if plus else None
 return {'features':list(features),'model':a,'validation_metrics':metrics(yf,pf),'confirmation_metrics':metrics(ye,pe),'validation_missing_raw':{f:int(np.isnan(xf[:,i]).sum()) for i,f in enumerate(features)},'confirmation_imputed_with_validation_median':{f:int(np.isnan(xe[:,i]).sum()) for i,f in enumerate(features)},'roundtrip_confirmation':{'max_abs_diff':float(d.max()),'mean_abs_diff':float(d.mean()),'exact_match_count':int(np.sum(pe==re)),'within_1e12_count':int(np.sum(d<=1e-12))},'type_probability_coefficients':coefs}
def diagnostic(five,six,typeprob):
 fd,sd=five['confirmation_delta'],six['confirmation_delta'];fc=five['plus']['type_probability_coefficients'][typeprob]['standardized_coefficient'];sc=six['plus']['type_probability_coefficients'][typeprob]['standardized_coefficient']
 dominance=fd['roc_auc']>sd['roc_auc'] and fd['pr_auc']>sd['pr_auc'] and fd['brier']<sd['brier'] and fd['log_loss']<sd['log_loss']
 if fc>0 and all(good(fd).values()) and dominance:return 'POSITIVE_SPECIFIC'
 if fc<0 or worse(fd):return 'NEGATIVE'
 if (fc>0)==(sc>0) and good(fd)==good(sd):return 'GENERAL'
 return 'MIXED'
def main():
 started=time.perf_counter()
 if sha(CACHE)!=EXPECTED:raise RuntimeError('follower cache SHA256 mismatch')
 rows=list(csv.DictReader(CACHE.open(encoding='utf-8')));keys=[r['race_code'] for r in rows]
 if len(rows)!=42717 or len(keys)!=len(set(keys)) or any(not k for k in keys):raise RuntimeError('cache audit mismatch')
 fit=[r for r in rows if r['split']=='Validation'];ev=[r for r in rows if r['split']=='EXPOSED_CONFIRMATION']
 if len(fit)!=20595 or len(ev)!=22122:raise RuntimeError('split count mismatch')
 results={};csvrows=[]
 for outcome in OUTCOMES:
  rr={}
  for course,key in ((5,'five'),(6,'six')):
   base=one(fit,ev,course,outcome,False);plus=one(fit,ev,course,outcome,True)
   vd={k:plus['validation_metrics'][k]-base['validation_metrics'][k] for k in base['validation_metrics']};cd={k:plus['confirmation_metrics'][k]-base['confirmation_metrics'][k] for k in base['confirmation_metrics']}
   rr[key]={'base':base,'plus':plus,'validation_delta':vd,'confirmation_delta':cd}
   for split,met,delta in [('Validation','validation_metrics',vd),('EXPOSED_CONFIRMATION','confirmation_metrics',cd)]:
    for name,obj in [('base',base),('plus',plus)]:csvrows.append({'row_type':'model','outcome':outcome,'course':course,'split':split,'model':name,**obj[met],**({f'{p}_coefficient':obj['type_probability_coefficients'][p]['standardized_coefficient'] for p in PLUS} if name=='plus' else {})})
    csvrows.append({'row_type':'plus_minus_base','outcome':outcome,'course':course,'split':split,'model':'plus_minus_base',**delta,**{f'{p}_coefficient':plus['type_probability_coefficients'][p]['standardized_coefficient'] for p in PLUS}})
  rr['internal_diagnostic']={p:diagnostic(rr['five'],rr['six'],p) for p in PLUS};results[outcome]=rr
 fields=['row_type','outcome','course','split','model','roc_auc','pr_auc','brier','log_loss','p_makuri_success_coefficient','p_makurizashi_success_coefficient']
 with METRICS_OUT.open('w',newline='',encoding='utf-8') as h:w=csv.DictWriter(h,fieldnames=fields);w.writeheader();w.writerows(csvrows)
 report={'phase':'Phase 7-2d','follower_cache_path':str(CACHE.relative_to(ROOT.parent)),'follower_cache_sha256':sha(CACHE),'fit_split':{'name':'Validation','N':len(fit)},'evaluation_split':{'name':'EXPOSED_CONFIRMATION','N':len(ev)},'base_features':list(BASE),'plus_features':list(PLUS),'plus_policy':'Both Attack Type probabilities are jointly included in every PLUS model; no interactions, bands, deciles, or nonlinear terms.','solver':{'implementation':'Phase 5 MetaLogit','ridge':1e-6,'max_iter':25,'tolerance':1e-8,'clip':[-35,35],'class_weight':None},'results':results,'metrics_csv_path':str(METRICS_OUT.relative_to(ROOT.parent)),'metrics_csv_sha256':sha(METRICS_OUT),'generated_at':datetime.datetime.now().astimezone().isoformat(),'source_script':'analysis/analyze_course4_attack_type_phase7_adjustment.py','source_script_sha256':sha(Path(__file__)),'source_git_commit':git(),'timing_seconds':{'total':time.perf_counter()-started}}
 REPORT_OUT.write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8')
 print(json.dumps({'diagnostics':{o:results[o]['internal_diagnostic'] for o in OUTCOMES},'csv':str(METRICS_OUT),'report':str(REPORT_OUT),'total_seconds':report['timing_seconds']['total']},ensure_ascii=False,indent=2))
if __name__=='__main__':main()
