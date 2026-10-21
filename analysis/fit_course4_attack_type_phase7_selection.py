#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Phase 7-1b: fixed-spec Development Selection fit and Validation only."""
from __future__ import annotations
import csv, hashlib, json, math, subprocess, time
from datetime import datetime
from pathlib import Path
import numpy as np
from sklearn.metrics import average_precision_score, brier_score_loss, roc_auc_score

ROOT=Path(__file__).resolve().parent; OUT=ROOT/'output'
SPEC=OUT/'course4_attack_type_phase7_spec_v3.json'
DATA=OUT/'course4_attack_type_phase7_dataset_20241001_20260930.csv'
ART=OUT/'course4_attack_type_phase7_selection_model.json'
PRED=OUT/'course4_attack_type_phase7_selection_predictions.csv'
EXPECTED_SPEC='e36d6d2068e583629328e85cdf3d2aa1c516711d35472439d811bdbf8a3f9343'
EXPECTED_DATA='b1c216568c5b2b771e54cc04c34c242a87349423ac41f7594b4aa5cf6812e3e4'
CLASSES=('NO_ATTACK_SUCCESS','MAKURI_SUCCESS','MAKURIZASHI_SUCCESS')
EPS=1e-15; LOGIT_CLIP=35.0; RIDGE=1e-6; MAX_ITER=50; TOL=1e-8

def sha(p): return hashlib.sha256(p.read_bytes()).hexdigest()
def git(): return subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT.parent,text=True).strip()
def num(v):
 try:
  x=float(v); return x if math.isfinite(x) else np.nan
 except (ValueError,TypeError): return np.nan
def sigmoid(z): return 1/(1+np.exp(-np.clip(z,-LOGIT_CLIP,LOGIT_CLIP)))
def softmax_ref(z,theta):
 logits=np.c_[np.zeros(len(z)),z@theta.T]; logits=np.clip(logits,-LOGIT_CLIP,LOGIT_CLIP)
 e=np.exp(logits-logits.max(1,keepdims=True)); return e/e.sum(1,keepdims=True)
def rankdata(x):
 o=np.argsort(x,kind='mergesort'); out=np.empty(len(x)); i=0
 while i<len(x):
  j=i
  while j+1<len(x) and x[o[j+1]]==x[o[i]]: j+=1
  out[o[i:j+1]]=(i+j+2)/2; i=j+1
 return out
def spearman(a,b): return float(np.corrcoef(rankdata(a),rankdata(b))[0,1])

def fit(x,y):
 z=np.c_[np.ones(len(x)),x]; d=z.shape[1]; theta=np.zeros((2,d)); eye=np.eye(2*d); eye[0,0]=0; eye[d,d]=0
 for it in range(1,MAX_ITER+1):
  p=softmax_ref(z,theta); g=np.concatenate([z.T@( (y==1)-p[:,1]),z.T@((y==2)-p[:,2])])
  h=np.zeros((2*d,2*d))
  for a in range(2):
   for b in range(2):
    w=p[:,a+1]*((1 if a==b else 0)-p[:,b+1])
    h[a*d:(a+1)*d,b*d:(b+1)*d]=(z.T*w)@z
  h+=RIDGE*eye
  step=np.linalg.solve(h,g); theta+=step.reshape(2,d)
  if np.max(np.abs(step))<TOL: return theta,{'converged':True,'iterations':it,'max_abs_step':float(np.max(np.abs(step)))}
 return theta,{'converged':False,'iterations':MAX_ITER,'max_abs_step':float(np.max(np.abs(step)))}

def calibration(y,p):
 q=np.clip(p,EPS,1-EPS); x=np.log(q/(1-q)); z=np.c_[np.ones(len(x)),x]; b=np.zeros(2)
 for it in range(1,MAX_ITER+1):
  pr=sigmoid(z@b); w=np.maximum(pr*(1-pr),1e-12); h=(z.T*w)@z; g=z.T@(y-pr)
  step=np.linalg.solve(h,g); b+=step
  if np.max(np.abs(step))<TOL: return {'intercept':float(b[0]),'slope':float(b[1]),'converged':True,'iterations':it}
 return {'intercept':float(b[0]),'slope':float(b[1]),'converged':False,'iterations':MAX_ITER}

def multiclass_metrics(y,p):
 one=np.eye(3)[y]; return {'logloss':float(-np.mean(np.log(np.clip(p[np.arange(len(y)),y],EPS,1)))), 'brier':float(np.mean(np.sum((p-one)**2,axis=1)))}
def per_class(y,p,k):
 yy=(y==k).astype(int); return {'prevalence':float(yy.mean()),'auc':float(roc_auc_score(yy,p[:,k])),'pr_auc':float(average_precision_score(yy,p[:,k])),'brier':float(brier_score_loss(yy,p[:,k]))}
def predict_from_art(a,x):
 med=np.array(a['development_medians']); mean=np.array(a['development_means']); sd=np.array(a['development_stds'])
 xx=np.where(np.isnan(x),med,x); z=(xx-mean)/sd
 theta=np.c_[np.asarray(a['intercepts'],dtype=float),np.asarray(a['coefficients'],dtype=float)]
 return softmax_ref(np.c_[np.ones(len(z)),z],theta)

def main():
 started=time.perf_counter()
 if sha(SPEC)!=EXPECTED_SPEC: raise RuntimeError('spec SHA256 mismatch')
 if sha(DATA)!=EXPECTED_DATA: raise RuntimeError('dataset SHA256 mismatch')
 spec=json.loads(SPEC.read_text()); fs=spec['final_feature_order']
 if len(fs)!=8 or set(fs)&set(spec['blocked_model_input_features']): raise RuntimeError('fixed feature whitelist invalid')
 dev=[]; val=[]
 with DATA.open(encoding='utf-8') as h:
  for r in csv.DictReader(h):
   # Confirmation rows are deliberately not stored, transformed, scored, or evaluated.
   if r['split']=='Development': dev.append(r)
   elif r['split']=='Validation': val.append(r)
 if len(dev)!=43787 or len(val)!=20595: raise RuntimeError('fixed split counts mismatch')
 ydev=np.array([int(r['label_id']) for r in dev]); yval=np.array([int(r['label_id']) for r in val])
 counts_dev=np.bincount(ydev,minlength=3); counts_val=np.bincount(yval,minlength=3)
 if counts_dev.tolist()!=[40648,2014,1125] or counts_val.tolist()!=[19074,1004,517]: raise RuntimeError('label counts mismatch')
 xdev=np.array([[num(r[f]) for f in fs] for r in dev]); xval=np.array([[num(r[f]) for f in fs] for r in val])
 missing_dev=np.sum(np.isnan(xdev),axis=0); missing_val=np.sum(np.isnan(xval),axis=0)
 med=np.nanmedian(xdev,axis=0); xd=np.where(np.isnan(xdev),med,xdev); xv=np.where(np.isnan(xval),med,xval)
 mean=xd.mean(0); sd=xd.std(0,ddof=0)
 if np.any(~np.isfinite(sd)) or np.any(sd<=1e-10): raise RuntimeError('SELECTION_FIT_FAIL: zero/non-finite Development standard deviation')
 xds=(xd-mean)/sd; xvs=(xv-mean)/sd
 theta,conv=fit(xds,ydev)
 if not conv['converged']: raise RuntimeError('SELECTION_FIT_FAIL: deterministic Newton did not converge')
 pdev=softmax_ref(np.c_[np.ones(len(xds)),xds],theta); pval=softmax_ref(np.c_[np.ones(len(xvs)),xvs],theta)
 sumdev=np.abs(pdev.sum(1)-1); sumval=np.abs(pval.sum(1)-1)
 baseline=counts_dev/len(ydev); basep=np.tile(baseline,(len(yval),1))
 cls={CLASSES[k]:per_class(yval,pval,k) for k in (1,2)}
 cal={CLASSES[k]:calibration((yval==k).astype(int),pval[:,k]) for k in (1,2)}
 multi=multiclass_metrics(yval,pval); base_multi=multiclass_metrics(yval,basep)
 artifact={'artifact_version':'phase7-selection-v1','model_role':'SELECTION','model_name':'Phase 7 Attack Type 3-class multinomial softmax logistic regression','spec_path':'analysis/output/course4_attack_type_phase7_spec_v3.json','spec_sha256':sha(SPEC),'dataset_path':'analysis/output/course4_attack_type_phase7_dataset_20241001_20260930.csv','dataset_sha256':sha(DATA),'fit_split':'Development','evaluation_split':'Validation','confirmation_accessed':False,'class_order':list(CLASSES),'reference_class':0,'feature_order':fs,'development_medians':med.tolist(),'development_means':mean.tolist(),'development_stds':sd.tolist(),'scaler_ddof':0,'solver':{'family':'deterministic Newton/IRLS multinomial reference-class softmax','ridge':RIDGE,'intercept_regularized':False,'max_iter':MAX_ITER,'tolerance':TOL,'logit_clip':[-LOGIT_CLIP,LOGIT_CLIP],'probability_epsilon':EPS,'class_weight':None},'convergence':conv,'intercepts':[float(theta[0,0]),float(theta[1,0])],'coefficients':theta[:,1:].tolist(),'development_class_counts':counts_dev.tolist(),'development_class_prevalence':baseline.tolist(),'preprocessing_audit':[{'feature':f,'development_missing_n':int(missing_dev[i]),'validation_missing_n':int(missing_val[i]),'development_median':float(med[i]),'development_mean':float(mean[i]),'development_std':float(sd[i])} for i,f in enumerate(fs)],'validation_metrics':{'per_class':cls,'multiclass':multi},'validation_intercept_only_metrics':base_multi,'validation_metric_deltas_vs_intercept_only':{'logloss':multi['logloss']-base_multi['logloss'],'brier':multi['brier']-base_multi['brier']},'validation_calibration':cal,'probability_audit':{'development_probability_generation_rate':float(np.isfinite(pdev).all()),'validation_probability_generation_rate':float(np.isfinite(pval).all()),'development_max_abs_sum_minus_one':float(sumdev.max()),'validation_max_abs_sum_minus_one':float(sumval.max())},'generated_at':datetime.now().astimezone().isoformat(),'source_script':'analysis/fit_course4_attack_type_phase7_selection.py','source_script_sha256':sha(Path(__file__)),'source_git_commit':git()}
 # Persist prediction rows for Development/Validation only.
 with PRED.open('w',newline='',encoding='utf-8') as h:
  w=csv.DictWriter(h,fieldnames=['race_code','split','label_id','p_no_attack_success','p_makuri_success','p_makurizashi_success']);w.writeheader()
  for rows,pp in ((dev,pdev),(val,pval)):
   for r,q in zip(rows,pp):w.writerow({'race_code':r['race_code'],'split':r['split'],'label_id':r['label_id'],'p_no_attack_success':repr(float(q[0])),'p_makuri_success':repr(float(q[1])),'p_makurizashi_success':repr(float(q[2]))})
 ART.write_text(json.dumps(artifact,ensure_ascii=False,indent=2),encoding='utf-8')
 # Round-trip from frozen artifact only.
 a=json.loads(ART.read_text()); rd=predict_from_art(a,xdev); rv=predict_from_art(a,xval); d=np.abs(np.r_[pdev.flatten()-rd.flatten(),pval.flatten()-rv.flatten()]); original=np.r_[pdev.flatten(),pval.flatten()]; rebuilt=np.r_[rd.flatten(),rv.flatten()]
 rt={'n':int(len(d)),'max_abs_diff':float(d.max()),'mean_abs_diff':float(d.mean()),'exact_match_count':int(np.sum(original==rebuilt)),'spearman':spearman(original,rebuilt)}
 gate={'makuri_auc':cls[CLASSES[1]]['auc']>.60,'makuri_pr_auc':cls[CLASSES[1]]['pr_auc']>cls[CLASSES[1]]['prevalence'],'makuri_calibration':.8<=cal[CLASSES[1]]['slope']<=1.2,'makurizashi_auc':cls[CLASSES[2]]['auc']>.60,'makurizashi_pr_auc':cls[CLASSES[2]]['pr_auc']>cls[CLASSES[2]]['prevalence'],'makurizashi_calibration':.8<=cal[CLASSES[2]]['slope']<=1.2,'logloss':multi['logloss']<base_multi['logloss'],'brier':multi['brier']<base_multi['brier'],'probability_generation':bool(np.isfinite(pdev).all() and np.isfinite(pval).all()),'probability_sum':bool(sumdev.max()<=1e-12 and sumval.max()<=1e-12),'round_trip':rt['max_abs_diff']<=1e-12}
 artifact['round_trip']=rt; artifact['selection_gate_checks']=gate; artifact['selection_gate']='PASS' if all(gate.values()) else 'FAIL'; artifact['prediction_path']='analysis/output/course4_attack_type_phase7_selection_predictions.csv'; artifact['prediction_sha256']=sha(PRED); artifact['timing_seconds']={'total':time.perf_counter()-started}
 ART.write_text(json.dumps(artifact,ensure_ascii=False,indent=2),encoding='utf-8')
 print(json.dumps({'selection_gate':artifact['selection_gate'],'gate_checks':gate,'validation_metrics':artifact['validation_metrics'],'calibration':cal,'baseline':base_multi,'round_trip':rt,'artifact':str(ART),'predictions':str(PRED),'total_seconds':artifact['timing_seconds']['total']},ensure_ascii=False,indent=2))
if __name__=='__main__':main()
