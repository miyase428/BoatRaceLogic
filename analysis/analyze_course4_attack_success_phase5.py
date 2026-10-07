#!/usr/bin/env python3
"""Phase 5 research-only freeze for 4C Attack Success Probability."""
from __future__ import annotations
import bisect,csv,hashlib,json,subprocess
from datetime import datetime
from pathlib import Path
import numpy as np
from sklearn.metrics import roc_auc_score,average_precision_score,brier_score_loss,log_loss

ROOT=Path(__file__).resolve().parent; OUT=ROOT/'output'; LABEL='four_win_attack'

def yes(v): return str(v).lower() in ('true','1','t')
def read(path):
 with path.open(encoding='utf8') as h:return list(csv.DictReader(h))
def write(path,rows):
 keys=sorted({k for r in rows for k in r})
 with path.open('w',newline='',encoding='utf8') as h:w=csv.DictWriter(h,fieldnames=keys);w.writeheader();w.writerows(rows)
def sha(path):return hashlib.sha256(path.read_bytes()).hexdigest()
def head():return subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT.parent,text=True).strip()
def phash(p):return hashlib.sha256(np.asarray(p,dtype='<f8').tobytes()).hexdigest()
def dist(p):return {'n':int(len(p)),'min':float(min(p)),'max':float(max(p)),'mean':float(np.mean(p)),'std':float(np.std(p)),'quantiles':{str(q):float(np.quantile(p,q)) for q in (0,.01,.05,.1,.25,.5,.75,.9,.95,.99,1)}}
def metrics(y,p):return {'roc_auc':float(roc_auc_score(y,p)),'pr_auc':float(average_precision_score(y,p)),'brier':float(brier_score_loss(y,p)),'log_loss':float(log_loss(y,p,labels=[0,1]))}
def boundaries(p):return [float(np.quantile(p,q/100,method='linear')) for q in range(101)]
def score(p,b):return [max(0,min(100,bisect.bisect_right(b,float(x))-1)) for x in p]
def ranks(x):
 o=np.argsort(x,kind='mergesort');r=np.empty(len(x));i=0
 while i<len(x):
  j=i
  while j+1<len(x) and x[o[j+1]]==x[o[i]]:j+=1
  r[o[i:j+1]]=(i+j+2)/2;i=j+1
 return r
def spearman(a,b):return float(np.corrcoef(ranks(a),ranks(b))[0,1])

class MetaLogit:
 """Fixed no-interaction logistic meta model over two frozen base probabilities."""
 def __init__(self):self.ridge=1e-6;self.max_iter=25;self.tolerance=1e-8;self.clip=35.0
 def fit(self,x,y):
  self.median_=np.nanmedian(x,0);x=np.where(np.isnan(x),self.median_,x);self.mean_=x.mean(0);self.scale_=x.std(0);self.scale_[self.scale_<1e-10]=1
  z=np.c_[np.ones(len(x)),(x-self.mean_)/self.scale_];self.beta_=np.zeros(z.shape[1]);self.iterations_=0
  for i in range(self.max_iter):
   p=self.predict_z(z);w=np.maximum(p*(1-p),1e-8);g=z.T@(y-p);h=(z.T*w)@z+np.diag(np.r_[0,np.full(len(self.beta_)-1,self.ridge)]);step=np.linalg.solve(h,g);self.beta_+=step;self.iterations_=i+1
   if np.max(abs(step))<self.tolerance:break
  return self
 def predict_z(self,z):return 1/(1+np.exp(-np.clip(z@self.beta_,-self.clip,self.clip)))
 def predict(self,x):
  x=np.where(np.isnan(x),self.median_,x);return self.predict_z(np.c_[np.ones(len(x)),(x-self.mean_)/self.scale_])

def predict_artifact(a,x):
 med,mean,sd=(np.asarray(a[k],dtype=float) for k in ('missing_fill_values','scaler_mean','scaler_std'));x=np.where(np.isnan(x),med,x);z=np.c_[np.ones(len(x)),(x-mean)/sd]@np.r_[a['intercept'],a['coefficients']];lo,hi=a['logit_clipping'];return 1/(1+np.exp(-np.clip(z,lo,hi)))

def base_scores():
 # Import only frozen/research helpers; no Phase 1--4R file is modified.
 from analyze_course4_attack_phase4r import source_rows,split,x_of,y_of,A5_FEATURES,WALL_FEATURES,add_wall_features,predict_artifact as base_predict
 rows,_=source_rows();dev,val,conf=split(rows);wd,wv,wc=split(add_wall_features(rows))
 ws=json.loads((OUT/'course34_wall_phase3r_selection_model.json').read_text());wf=json.loads((OUT/'course34_wall_phase3r_final_model.json').read_text());aa=json.loads((OUT/'course4_attack_phase4r_selection_model.json').read_text());af=json.loads((OUT/'course4_attack_phase4r_final_model.json').read_text())
 ds=np.c_[base_predict(ws,x_of(wd,WALL_FEATURES)),base_predict(aa,x_of(dev,A5_FEATURES))]
 vs=np.c_[base_predict(ws,x_of(wv,WALL_FEATURES)),base_predict(aa,x_of(val,A5_FEATURES))]
 dfs=np.c_[base_predict(wf,x_of(wd,WALL_FEATURES)),base_predict(af,x_of(dev,A5_FEATURES))]
 vfs=np.c_[base_predict(wf,x_of(wv,WALL_FEATURES)),base_predict(af,x_of(val,A5_FEATURES))]
 cs=np.c_[base_predict(wf,x_of(wc,WALL_FEATURES)),base_predict(af,x_of(conf,A5_FEATURES))]
 return (dev,val,conf),(ds,vs,dfs,vfs,cs),(y_of(dev),y_of(val),y_of(conf)),(ws,wf,aa,af)

def artifact(role,m,fitp,b,deps,input_hash,reference=None):
 a={'artifact_version':'phase5-v1','model_role':role,'model_name':'COURSE4_ATTACK_SUCCESS','created_at':datetime.now().astimezone().isoformat(),'fit_period':'2024-10-01..2025-09-30' if role.endswith('SELECTION') else '2024-10-01..2026-03-31','label_definition':'four_win_attack = actual 4C first AND technique in {makuri, makurizashi}','input_features_ordered':['wall_r_probability','attack_a5_probability'],'interaction':False,'base_artifacts':deps,'intercept':float(m.beta_[0]),'coefficients':m.beta_[1:].tolist(),'wall_coefficient_standardized':float(m.beta_[1]),'attack_coefficient_standardized':float(m.beta_[2]),'standardization':True,'missing_fill_values':m.median_.tolist(),'scaler_mean':m.mean_.tolist(),'scaler_std':m.scale_.tolist(),'scaler_ddof':0,'model_type':'deterministic_newton_logistic_regression','optimizer':'Newton-Raphson full Hessian','regularization':{'type':'ridge Hessian damping','lambda':m.ridge,'intercept_regularized':False},'tolerance':m.tolerance,'max_iterations':m.max_iter,'iterations_used':m.iterations_,'logit_clipping':[-m.clip,m.clip],'source_script':'analysis/analyze_course4_attack_success_phase5.py','source_git_commit':head(),'input_dataset_sha256':input_hash,'fit_prediction_sha256':phash(fitp),'fit_probability_distribution':dist(fitp),'score_percentile_boundaries':b}
 if reference is not None:a.update({'score_reference_population':'Development + Validation','score_reference_prediction_sha256':phash(reference),'score_reference_probability_distribution':dist(reference)})
 return a

def rt(a,named):
 out={}
 for k,(x,p) in named.items():
  z=predict_artifact(a,x);d=abs(z-p);out[k]={'n':len(p),'max_abs_diff':float(d.max()),'mean_abs_diff':float(d.mean()),'spearman':spearman(p,z),'exact_match_count':int(sum(z==p)),'within_1e12_count':int(sum(d<=1e-12))}
 return out
def deciles(rows,p,label):
 o=sorted(range(len(rows)),key=lambda i:(p[i],rows[i]['race_code']));g={i:min(10,j*10//len(rows)+1) for j,i in enumerate(o)};out=[]
 for q in range(1,11):
  ix=[i for i in range(len(rows)) if g[i]==q];rate=lambda k:100*sum(yes(rows[i][k]) for i in ix)/len(ix);out.append({'dataset':label,'decile':q,'n':len(ix),'mean_attack_success_probability':float(np.mean(p[ix])),'observed_attack_rate_pct':rate('four_win_attack'),'calibration_gap_pct_point':rate('four_win_attack')-100*np.mean(p[ix]),'four_first_rate_pct':rate('four_first'),'four_top2_rate_pct':rate('four_top2'),'four_top3_rate_pct':rate('four_top3')})
 return out
def bands(rows,p,b):
 s=score(p,b);out=[]
 for lo,hi in ((0,19),(20,39),(40,59),(60,79),(80,100)):
  ix=[i for i,x in enumerate(s) if lo<=x<=hi];rate=lambda k:100*sum(yes(rows[i][k]) for i in ix)/len(ix);out.append({'score_band':f'{lo}-{hi}','n':len(ix),'four_win_attack_rate_pct':rate('four_win_attack'),'four_win_makuri_rate_pct':rate('four_win_makuri'),'four_win_makurizashi_rate_pct':rate('four_win_makurizashi'),'four_first_rate_pct':rate('four_first'),'four_top2_rate_pct':rate('four_top2'),'four_top3_rate_pct':rate('four_top3')})
 return out

def run():
 (dev,val,conf),(ds,vs,dfs,vfs,cs),(yd,yv,yc),(ws,wf,aa,af)=base_scores()
 deps={'wall_selection':{'path':'analysis/output/course34_wall_phase3r_selection_model.json','sha256':sha(OUT/'course34_wall_phase3r_selection_model.json'),'artifact_version':ws['artifact_version'],'fit_prediction_sha256':ws['development_prediction_sha256']},'wall_final':{'path':'analysis/output/course34_wall_phase3r_final_model.json','sha256':sha(OUT/'course34_wall_phase3r_final_model.json'),'artifact_version':wf['artifact_version'],'fit_prediction_sha256':wf['development_prediction_sha256']},'attack_selection':{'path':'analysis/output/course4_attack_phase4r_selection_model.json','sha256':sha(OUT/'course4_attack_phase4r_selection_model.json'),'artifact_version':aa['artifact_version'],'fit_prediction_sha256':aa['fit_prediction_sha256']},'attack_final':{'path':'analysis/output/course4_attack_phase4r_final_model.json','sha256':sha(OUT/'course4_attack_phase4r_final_model.json'),'artifact_version':af['artifact_version'],'fit_prediction_sha256':af['fit_prediction_sha256']}}
 ih=hashlib.sha256(np.c_[ds,yd].astype('<f8').tobytes()).hexdigest();sel=MetaLogit().fit(ds,yd);pd,pv=sel.predict(ds),sel.predict(vs);sb=boundaries(pd);sa=artifact('ATTACK-SUCCESS-SELECTION',sel,pd,sb,deps,ih);(OUT/'course4_attack_success_phase5_selection_model.json').write_text(json.dumps(sa,ensure_ascii=False,indent=2),encoding='utf8');srt=rt(sa,{'development':(ds,pd),'validation':(vs,pv)})
 final=MetaLogit().fit(np.vstack((dfs,vfs)),np.r_[yd,yv]);pfd,pdv,pc=final.predict(dfs),final.predict(np.vstack((dfs,vfs))),final.predict(cs);fb=boundaries(pdv);fa=artifact('ATTACK-SUCCESS-FINAL',final,pfd,fb,deps,ih,pdv);(OUT/'course4_attack_success_phase5_final_model.json').write_text(json.dumps(fa,ensure_ascii=False,indent=2),encoding='utf8');frt=rt(fa,{'development_plus_validation':(np.vstack((dfs,vfs)),pdv),'exposed_confirmation':(cs,pc)})
 if max(x['max_abs_diff'] for x in (*srt.values(),*frt.values()))>1e-12:raise RuntimeError('round-trip failure')
 dv,dc=deciles(val,pv,'validation'),deciles(conf,pc,'exposed_confirmation');bb=bands(conf,pc,fb);write(OUT/'course4_attack_success_phase5_deciles.csv',dv+dc);write(OUT/'course4_attack_success_phase5_exposed_bands.csv',bb)
 report={'selection_coefficients':{'intercept':float(sel.beta_[0]),'wall_standardized':float(sel.beta_[1]),'attack_standardized':float(sel.beta_[2])},'final_coefficients':{'intercept':float(final.beta_[0]),'wall_standardized':float(final.beta_[1]),'attack_standardized':float(final.beta_[2])},'roundtrip_selection':srt,'roundtrip_final':frt,'validation':metrics(yv,pv),'exposed_confirmation':metrics(yc,pc),'calibration_validation':dv,'calibration_exposed_confirmation':dc,'exposed_bands':bb,'dependencies':deps,'source_git_commit':head()};(OUT/'course4_attack_success_phase5_report.json').write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf8');print(json.dumps(report,ensure_ascii=False,indent=2))

def inference_function(wall_probability,attack_probability):
 """Research-only one-race inference from the FINAL artifact and two base scores."""
 a=json.loads((OUT/'course4_attack_success_phase5_final_model.json').read_text());x=np.asarray([[wall_probability,attack_probability]],float);p=float(predict_artifact(a,x)[0]);return {'attack_success_probability':p,'attack_success_score_0_100':score([p],a['score_percentile_boundaries'])[0]}
def forward():
 a=json.loads((OUT/'course4_attack_success_phase5_final_model.json').read_text());files=sorted(OUT.glob('course4_attack_phase4r_true_forward_scores_*.csv'));p=files[-1];rows=read(p);x=np.asarray([[float(r['wall_r_probability']),float(r['attack_a5_probability'])] for r in rows]);prob=predict_artifact(a,x);sc=score(prob,a['score_percentile_boundaries']);complete=[int(r['wall_missing_feature_count'])==0 and int(r['attack_missing_feature_count'])==0 for r in rows]
 groups=[]
 for label,want in (('both_exhibition_complete',True),('either_missing_base_feature',False)):
  ix=[i for i,v in enumerate(complete) if v==want];groups.append({'group':label,'n':len(ix),'mean_probability':float(np.mean(prob[ix])),'score_distribution':dist(np.asarray([sc[i] for i in ix],float))})
 stamp=p.stem.split('_')[-2]+'_'+p.stem.split('_')[-1];summary={'source_forward_base_scores':p.name,'n':len(rows),'score_generation_rate_pct':100.0,'mean_probability':float(np.mean(prob)),'std_probability':float(np.std(prob)),'min_probability':float(min(prob)),'max_probability':float(max(prob)),'success_score_distribution':dist(np.asarray(sc,float)),'missing_groups':groups};write(OUT/f'course4_attack_success_phase5_true_forward_scores_{stamp}.csv',[{'race_code':r['race_code'],'race_date':r['race_date'],'stadium':r['stadium'],'attack_success_probability':float(q),'attack_success_score_0_100':s,'wall_missing_feature_count':r['wall_missing_feature_count'],'attack_missing_feature_count':r['attack_missing_feature_count']} for r,q,s in zip(rows,prob,sc)]);(OUT/f'course4_attack_success_phase5_true_forward_sanity_{stamp}.json').write_text(json.dumps(summary,ensure_ascii=False,indent=2),encoding='utf8');print(json.dumps(summary,ensure_ascii=False,indent=2))

if __name__=='__main__':
 import argparse
 q=argparse.ArgumentParser();q.add_argument('--forward',action='store_true');z=q.parse_args();forward() if z.forward else run()
