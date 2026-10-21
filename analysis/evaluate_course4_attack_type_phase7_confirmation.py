#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Phase 7-1d: one-time EXPOSED_CONFIRMATION inference from frozen FINAL."""
from __future__ import annotations
import csv, hashlib, json, math, subprocess, sys, time
from datetime import datetime
from pathlib import Path
import numpy as np
from sklearn.metrics import average_precision_score, brier_score_loss, roc_auc_score

ROOT=Path(__file__).resolve().parent; OUT=ROOT/'output'; sys.path.insert(0,str(ROOT))
from fit_course4_attack_type_phase7_selection import calibration, multiclass_metrics, per_class, rankdata, softmax_ref, EPS

SPEC=OUT/'course4_attack_type_phase7_spec_v3.json'; FINAL=OUT/'course4_attack_type_phase7_final_model.json'; SEL=OUT/'course4_attack_type_phase7_selection_model.json'; DATA=OUT/'course4_attack_type_phase7_dataset_20241001_20260930.csv'
PRED=OUT/'course4_attack_type_phase7_confirmation_predictions.csv'; REPORT=OUT/'course4_attack_type_phase7_confirmation_report.json'
EXPECTED={'spec':'e36d6d2068e583629328e85cdf3d2aa1c516711d35472439d811bdbf8a3f9343','final':'e0f3674e3e863a4a251aafb2e87530ebba85d4c393982d019fdccd124f3707b4','selection':'84e7e172c4377f57d4cf2cef5fbb4e324f3e2ef7024ffa57dc41ca3b9bb38eee','dataset':'b1c216568c5b2b771e54cc04c34c242a87349423ac41f7594b4aa5cf6812e3e4'}
CLASSES=('NO_ATTACK_SUCCESS','MAKURI_SUCCESS','MAKURIZASHI_SUCCESS')

def sha(p): return hashlib.sha256(p.read_bytes()).hexdigest()
def git(): return subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT.parent,text=True).strip()
def num(v):
 try:
  x=float(v); return x if math.isfinite(x) else np.nan
 except (TypeError,ValueError): return np.nan
def spearman(a,b): return float(np.corrcoef(rankdata(a),rankdata(b))[0,1])
def infer(a,x):
 med=np.asarray(a['development_medians']); mean=np.asarray(a['development_means']); sd=np.asarray(a['development_stds'])
 z=(np.where(np.isnan(x),med,x)-mean)/sd; theta=np.c_[np.asarray(a['intercepts']),np.asarray(a['coefficients'])]
 return softmax_ref(np.c_[np.ones(len(z)),z],theta)

def main():
 started=time.perf_counter()
 for k,p in [('spec',SPEC),('final',FINAL),('selection',SEL),('dataset',DATA)]:
  if sha(p)!=EXPECTED[k]: raise RuntimeError(f'{k} SHA256 mismatch')
 spec=json.loads(SPEC.read_text()); final=json.loads(FINAL.read_text()); sel=json.loads(SEL.read_text()); fs=spec['final_feature_order']
 if fs!=final['feature_order'] or final['class_order']!=list(CLASSES): raise RuntimeError('FINAL/spec mismatch')
 rows=[]
 with DATA.open(encoding='utf-8') as h:
  for r in csv.DictReader(h):
   if r['split']=='EXPOSED_CONFIRMATION': rows.append(r)
 if len(rows)!=22122: raise RuntimeError('Confirmation N mismatch')
 y=np.asarray([int(r['label_id']) for r in rows]); counts=np.bincount(y,minlength=3)
 if counts.tolist()!=[20548,1015,559]: raise RuntimeError('Confirmation class count mismatch')
 x=np.asarray([[num(r[f]) for f in fs] for r in rows]); p=infer(final,x); sums=np.abs(p.sum(1)-1)
 probability_audit={'prediction_generation_rate':float(np.isfinite(p).all()),'nan_count':int(np.isnan(p).sum()),'inf_count':int(np.isinf(p).sum()),'outside_0_1_count':int(np.sum((p<0)|(p>1))),'max_abs_sum_minus_one':float(sums.max())}
 with PRED.open('w',newline='',encoding='utf-8') as h:
  w=csv.DictWriter(h,fieldnames=['race_code','race_date','stadium','split','label_id','p_no_attack_success','p_makuri_success','p_makurizashi_success']);w.writeheader()
  for r,q in zip(rows,p):w.writerow({'race_code':r['race_code'],'race_date':r['race_date'],'stadium':r['stadium'],'split':r['split'],'label_id':r['label_id'],'p_no_attack_success':repr(float(q[0])),'p_makuri_success':repr(float(q[1])),'p_makurizashi_success':repr(float(q[2]))})
 cls={CLASSES[k]:per_class(y,p,k) for k in (1,2)}; cal={CLASSES[k]:calibration((y==k).astype(int),p[:,k]) for k in (1,2)}
 multi=multiclass_metrics(y,p); baseline=np.asarray(final['fit_class_prevalence']); basep=np.tile(baseline,(len(y),1)); base_multi=multiclass_metrics(y,basep)
 reloaded=json.loads(FINAL.read_text()); pr=infer(reloaded,x); d=np.abs(p-pr); rt={'n':int(d.size),'max_abs_diff':float(d.max()),'mean_abs_diff':float(d.mean()),'exact_match_count':int(np.sum(p==pr)),'spearman':spearman(p.ravel(),pr.ravel())}
 global_checks={'logloss':multi['logloss']<base_multi['logloss'],'brier':multi['brier']<base_multi['brier'],'prediction_generation':bool(np.isfinite(p).all()),'probability_sum':bool(sums.max()<=1e-12),'round_trip':rt['max_abs_diff']<=1e-12}
 raw_type={}
 for k,name in ((1,CLASSES[1]),(2,CLASSES[2])):
  raw_type[name]={'auc':cls[name]['auc']>.60,'pr_auc':cls[name]['pr_auc']>cls[name]['prevalence'],'calibration':.8<=cal[name]['slope']<=1.2}
 # A type can proceed only if its own requirements and all global requirements pass.
 type_gate={name:('PASS' if all(raw_type[name].values()) and all(global_checks.values()) else 'FAIL') for name in raw_type}
 if type_gate[CLASSES[1]]=='PASS' and type_gate[CLASSES[2]]=='PASS': phase='PASS_BOTH'
 elif type_gate[CLASSES[1]]=='PASS': phase='PASS_MAKURI_ONLY'
 elif type_gate[CLASSES[2]]=='PASS': phase='PASS_MAKURIZASHI_ONLY'
 else: phase='FAIL_BOTH'
 comparison={}
 for name in (CLASSES[1],CLASSES[2]):
  comparison[name]={'validation_selection':{'auc':sel['validation_metrics']['per_class'][name]['auc'],'pr_auc':sel['validation_metrics']['per_class'][name]['pr_auc'],'calibration_slope':sel['validation_calibration'][name]['slope']},'confirmation_final':{'auc':cls[name]['auc'],'pr_auc':cls[name]['pr_auc'],'calibration_slope':cal[name]['slope']}}
 report={'phase':'Phase 7-1d','spec_sha256':sha(SPEC),'final_artifact_sha256':sha(FINAL),'dataset_sha256':sha(DATA),'confirmation_n':len(rows),'class_counts':counts.tolist(),'class_prevalence':(counts/len(y)).tolist(),'feature_order':fs,'confirmation_inference_only':True,'probability_audit':probability_audit,'class_metrics':cls,'calibration':cal,'multiclass_metrics':multi,'intercept_only_baseline':{'source':'FINAL fit Development + Validation class prevalence','probabilities':baseline.tolist(),**base_multi},'delta_vs_baseline':{'logloss':multi['logloss']-base_multi['logloss'],'brier':multi['brier']-base_multi['brier']},'round_trip':rt,'type_criteria':raw_type,'global_criteria':global_checks,'type_gate':{'MAKURI_PREDICTOR_GATE':type_gate[CLASSES[1]],'MAKURIZASHI_PREDICTOR_GATE':type_gate[CLASSES[2]]},'phase7_1_gate':phase,'selection_vs_confirmation':comparison,'prediction_path':'analysis/output/course4_attack_type_phase7_confirmation_predictions.csv','prediction_sha256':sha(PRED),'generated_at':datetime.now().astimezone().isoformat(),'source_script':'analysis/evaluate_course4_attack_type_phase7_confirmation.py','source_script_sha256':sha(Path(__file__)),'source_git_commit':git(),'timing_seconds':{'total':time.perf_counter()-started}}
 REPORT.write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8')
 print(json.dumps({'class_metrics':cls,'calibration':cal,'multiclass':multi,'baseline':base_multi,'deltas':report['delta_vs_baseline'],'probability_audit':probability_audit,'round_trip':rt,'type_gate':report['type_gate'],'phase7_1_gate':phase,'report':str(REPORT),'prediction':str(PRED),'total_seconds':report['timing_seconds']['total']},ensure_ascii=False,indent=2))
if __name__=='__main__':main()
