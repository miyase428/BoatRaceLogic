#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Phase 7-1c: mechanical FINAL refit, without Confirmation access."""
from __future__ import annotations
import csv, hashlib, json, math, subprocess, sys, time
from datetime import datetime
from pathlib import Path
import numpy as np

ROOT=Path(__file__).resolve().parent; OUT=ROOT/'output'; sys.path.insert(0,str(ROOT))
from fit_course4_attack_type_phase7_selection import fit, softmax_ref, rankdata, RIDGE, MAX_ITER, TOL, LOGIT_CLIP, EPS

SPEC=OUT/'course4_attack_type_phase7_spec_v3.json'; SEL=OUT/'course4_attack_type_phase7_selection_model.json'; DATA=OUT/'course4_attack_type_phase7_dataset_20241001_20260930.csv'
ART=OUT/'course4_attack_type_phase7_final_model.json'; PRED=OUT/'course4_attack_type_phase7_final_fit_predictions.csv'
EXPECTED={'spec':'e36d6d2068e583629328e85cdf3d2aa1c516711d35472439d811bdbf8a3f9343','selection':'84e7e172c4377f57d4cf2cef5fbb4e324f3e2ef7024ffa57dc41ca3b9bb38eee','dataset':'b1c216568c5b2b771e54cc04c34c242a87349423ac41f7594b4aa5cf6812e3e4'}

def sha(p): return hashlib.sha256(p.read_bytes()).hexdigest()
def git(): return subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT.parent,text=True).strip()
def num(v):
 try:
  x=float(v); return x if math.isfinite(x) else np.nan
 except (TypeError,ValueError): return np.nan
def spearman(a,b): return float(np.corrcoef(rankdata(a),rankdata(b))[0,1])
def rebuild(a,x):
 med=np.asarray(a['development_medians']); mean=np.asarray(a['development_means']); sd=np.asarray(a['development_stds'])
 z=(np.where(np.isnan(x),med,x)-mean)/sd; theta=np.c_[np.asarray(a['intercepts']),np.asarray(a['coefficients'])]
 return softmax_ref(np.c_[np.ones(len(z)),z],theta)

def main():
 started=time.perf_counter()
 for k,p in [('spec',SPEC),('selection',SEL),('dataset',DATA)]:
  if sha(p)!=EXPECTED[k]: raise RuntimeError(f'{k} SHA256 mismatch')
 spec=json.loads(SPEC.read_text()); sel=json.loads(SEL.read_text()); fs=spec['final_feature_order']
 if fs!=sel['feature_order'] or len(fs)!=8: raise RuntimeError('Selection/spec feature order mismatch')
 if set(fs)&set(spec['blocked_model_input_features']): raise RuntimeError('blocked feature in whitelist')
 required_solver={'ridge':RIDGE,'max_iter':MAX_ITER,'tolerance':TOL,'logit_clip':[-LOGIT_CLIP,LOGIT_CLIP],'probability_epsilon':EPS,'class_weight':None}
 for k,v in required_solver.items():
  if sel['solver'].get(k)!=v: raise RuntimeError(f'Selection solver mismatch: {k}')
 rows=[]
 with DATA.open(encoding='utf-8') as h:
  for r in csv.DictReader(h):
   # Confirmation is intentionally neither retained nor transformed.
   if r['split'] in ('Development','Validation'): rows.append(r)
 if len(rows)!=64382: raise RuntimeError('FINAL fit N mismatch')
 y=np.asarray([int(r['label_id']) for r in rows]); counts=np.bincount(y,minlength=3)
 if counts.tolist()!=[59722,3018,1642]: raise RuntimeError('FINAL class-count mismatch')
 x=np.asarray([[num(r[f]) for f in fs] for r in rows])
 med=np.asarray(sel['development_medians']); mean=np.asarray(sel['development_means']); sd=np.asarray(sel['development_stds'])
 if np.any(sd<=1e-10) or not(np.isfinite(med).all() and np.isfinite(mean).all() and np.isfinite(sd).all()): raise RuntimeError('invalid Selection preprocessing')
 z=(np.where(np.isnan(x),med,x)-mean)/sd
 theta,conv=fit(z,y)
 if not conv['converged']: raise RuntimeError('FINAL_FIT_FAIL: deterministic Newton did not converge')
 p=softmax_ref(np.c_[np.ones(len(z)),z],theta); sums=np.abs(p.sum(1)-1)
 probability_audit={'prediction_generation_rate':float(np.isfinite(p).all()),'nan_count':int(np.isnan(p).sum()),'inf_count':int(np.isinf(p).sum()),'outside_0_1_count':int(np.sum((p<0)|(p>1))),'max_abs_sum_minus_one':float(sums.max())}
 with PRED.open('w',newline='',encoding='utf-8') as h:
  w=csv.DictWriter(h,fieldnames=['race_code','split','label_id','p_no_attack_success','p_makuri_success','p_makurizashi_success']);w.writeheader()
  for r,q in zip(rows,p):w.writerow({'race_code':r['race_code'],'split':r['split'],'label_id':r['label_id'],'p_no_attack_success':repr(float(q[0])),'p_makuri_success':repr(float(q[1])),'p_makurizashi_success':repr(float(q[2]))})
 identical_preprocessing=all(np.array_equal(np.asarray(sel[k]),v) for k,v in [('development_medians',med),('development_means',mean),('development_stds',sd)])
 artifact={'artifact_version':'phase7-final-v1','model_role':'FINAL','model_name':'Phase 7 Attack Type 3-class multinomial softmax logistic regression','spec_path':'analysis/output/course4_attack_type_phase7_spec_v3.json','spec_sha256':sha(SPEC),'selection_artifact_path':'analysis/output/course4_attack_type_phase7_selection_model.json','selection_artifact_sha256':sha(SEL),'dataset_path':'analysis/output/course4_attack_type_phase7_dataset_20241001_20260930.csv','dataset_sha256':sha(DATA),'fit_sample':'Development + Validation','fit_n':len(rows),'fit_split_counts':{'Development':43787,'Validation':20595},'confirmation_accessed':False,'class_order':sel['class_order'],'reference_class':0,'feature_order':fs,'development_medians':med.tolist(),'development_means':mean.tolist(),'development_stds':sd.tolist(),'scaler_ddof':0,'solver':sel['solver'],'convergence':conv,'intercepts':theta[:,0].tolist(),'coefficients':theta[:,1:].tolist(),'fit_class_counts':counts.tolist(),'fit_class_prevalence':(counts/len(y)).tolist(),'probability_audit':probability_audit,'selection_specification_audit':{'feature_count_equal':len(fs)==len(sel['feature_order']),'feature_order_equal':fs==sel['feature_order'],'class_order_equal':sel['class_order']==['NO_ATTACK_SUCCESS','MAKURI_SUCCESS','MAKURIZASHI_SUCCESS'],'development_medians_equal':identical_preprocessing,'development_means_equal':identical_preprocessing,'development_stds_equal':identical_preprocessing,'solver_equal':sel['solver']==required_solver|{'family':sel['solver']['family'],'intercept_regularized':False},'class_weight_equal':sel['solver']['class_weight'] is None},'prediction_path':'analysis/output/course4_attack_type_phase7_final_fit_predictions.csv','prediction_sha256':sha(PRED),'generated_at':datetime.now().astimezone().isoformat(),'source_script':'analysis/fit_course4_attack_type_phase7_final.py','source_script_sha256':sha(Path(__file__)),'source_git_commit':git()}
 ART.write_text(json.dumps(artifact,ensure_ascii=False,indent=2),encoding='utf-8')
 a=json.loads(ART.read_text()); rebuilt=rebuild(a,x); d=np.abs(p-rebuilt); rt={'n':int(d.size),'max_abs_diff':float(d.max()),'mean_abs_diff':float(d.mean()),'exact_match_count':int(np.sum(p==rebuilt)),'spearman':spearman(p.ravel(),rebuilt.ravel())}
 artifact['round_trip']=rt; artifact['timing_seconds']={'total':time.perf_counter()-started}; ART.write_text(json.dumps(artifact,ensure_ascii=False,indent=2),encoding='utf-8')
 print(json.dumps({'fit_n':len(rows),'class_counts':counts.tolist(),'convergence':conv,'probability_audit':probability_audit,'round_trip':rt,'selection_specification_audit':artifact['selection_specification_audit'],'confirmation_accessed':False,'artifact':str(ART),'predictions':str(PRED),'total_seconds':artifact['timing_seconds']['total']},ensure_ascii=False,indent=2))
if __name__=='__main__': main()
