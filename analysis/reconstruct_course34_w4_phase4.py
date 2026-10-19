#!/usr/bin/env python3
"""Deterministic reconstruction/audit of fixed Phase-3 W4 only."""
import csv,json,hashlib
from datetime import datetime,date
from pathlib import Path
import numpy as np
from analyze_course34_wall_phase3 import FEATURES,LinearLogit,load_model_cache,xy,START,DEV_END,VAL_END,END,parse
O=Path(__file__).resolve().parent/'output';SRC=O/'course34_wall_phase3_model_input_20241001_20260930.csv'
def main():
 rows=load_model_cache(SRC);fs=FEATURES['W4'];dev=[r for r in rows if START<=parse(r['race_date'])<=DEV_END];val=[r for r in rows if DEV_END<parse(r['race_date'])<=VAL_END];hold=[r for r in rows if VAL_END<parse(r['race_date'])<=END]
 # Selection model is Development-only; FINAL expands only the fit population.
 m1=LinearLogit().fit(*xy(dev,fs));m2=LinearLogit().fit(*xy(dev,fs));mf=LinearLogit().fit(*xy(dev+val,fs));pdev=m1.predict_proba(xy(dev,fs)[0])[:,1]
 old={r['race_code']:float(r['wall_probability_candidate']) for r in csv.DictReader(open(O/'course34_wall_phase3_race_scores_20241001_20260930.csv',encoding='utf8'))}
 audit=[]
 for split,rr in [('development',dev),('validation',val),('exposed_holdout',hold)]:
  use=m1 if split!='exposed_holdout' else mf;p=use.predict_proba(xy(rr,fs)[0])[:,1];audit += [{'race_code':r['race_code'],'race_date':r['race_date'],'stadium':r['stadium'],'split':split,'wall_probability':float(q),'four_win_attack':r['four_win_attack']} for r,q in zip(rr,p)]
 hp={r['race_code']:r['wall_probability'] for r in audit if r['split']=='exposed_holdout'};diff=np.array([hp[k]-old[k] for k in hp if k in old])
 art={'artifact_version':'1','reconstructed':True,'reconstructed_from_phase3_code':True,'model_name':'W4-FINAL','fit_period':'2024-10-01..2026-03-31','feature_names':fs,'imputation':'per-feature fit median','standardization':'fit mean/std','st_smoothing_k':20,'intercept':float(mf.beta_[0]),'coefficients':list(map(float,mf.beta_[1:])),'missing_fill_values':list(map(float,mf.median_)),'scaler_mean':list(map(float,mf.mean_)),'scaler_std':list(map(float,mf.scale_)),'input_sha256':hashlib.sha256(SRC.read_bytes()).hexdigest(),'determinism_max_beta_diff':float(np.max(abs(m1.beta_-m2.beta_))),'holdout_joined_n':len(diff),'holdout_max_abs_diff':float(max(abs(diff))),'holdout_mean_abs_diff':float(np.mean(abs(diff)))}
 with (O/'course34_wall_phase3_w4_final_model.json').open('w') as h:json.dump(art,h,ensure_ascii=False)
 with (O/'course34_wall_phase3_w4_reconstructed_scores_20241001_20260930.csv').open('w',newline='') as h:w=csv.DictWriter(h,fieldnames=audit[0]);w.writeheader();w.writerows(audit)
 print(json.dumps({k:art[k] for k in ('determinism_max_beta_diff','holdout_joined_n','holdout_max_abs_diff','holdout_mean_abs_diff')},ensure_ascii=False))
if __name__=='__main__':main()
