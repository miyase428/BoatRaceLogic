#!/usr/bin/env python3
"""Phase 4 research-only 4C Attack candidates (no Wall/3C/result features)."""
from __future__ import annotations
import bisect,csv,math,glob
from datetime import date,datetime
from pathlib import Path
import numpy as np
from sklearn.metrics import roc_auc_score,average_precision_score,brier_score_loss,log_loss

OUT=Path(__file__).resolve().parent/'output'; P=OUT/'course4_attack_phase4_input_20241001_20260930.csv'
START=date(2024,10,1);DEVEND=date(2025,9,30);VALEND=date(2026,3,31);END=date(2026,9,30)
def d(s):return datetime.strptime(s,'%Y-%m-%d').date()
def n(x):
 try:return float(x) if x not in ('',None) else np.nan
 except:return np.nan
def yes(x):return str(x).lower() in ('true','1','t')
class Logit:
 def fit(self,X,y):
  self.med=np.nanmedian(X,0);X=np.where(np.isnan(X),self.med,X);self.mu=X.mean(0);self.sd=X.std(0);self.sd[self.sd<1e-10]=1
  Z=np.c_[np.ones(len(X)),(X-self.mu)/self.sd];self.b=np.zeros(Z.shape[1])
  for _ in range(25):
   p=1/(1+np.exp(-np.clip(Z@self.b,-35,35)));w=np.maximum(p*(1-p),1e-8);g=Z.T@(y-p);h=(Z.T*w)@Z+np.diag(np.r_[0,np.full(len(self.b)-1,1e-6)]);step=np.linalg.solve(h,g);self.b+=step
   if max(abs(step))<1e-8:break
  return self
 def pred(self,X):
  X=np.where(np.isnan(X),self.med,X);Z=np.c_[np.ones(len(X)),(X-self.mu)/self.sd];return 1/(1+np.exp(-np.clip(Z@self.b,-35,35)))
def met(y,p):return {'roc_auc':roc_auc_score(y,p),'pr_auc':average_precision_score(y,p),'brier':brier_score_loss(y,p),'log_loss':log_loss(y,p,labels=[0,1])}
def main():
 rows=list(csv.DictReader(P.open(encoding='utf8')))
 extras={}
 for path in glob.glob(str(OUT/'course4_attack_phase4_prerace'/'*.csv')):
  for r in csv.DictReader(open(path,encoding='utf8')):extras[(r['race_code'],r['player_id'])]=r
 # Only course-4 own pre-race columns; all time fields are own, not 3-vs-4 deltas.
 base=['course4_history_n','course4_history_win_rate_pct','course4_history_top2_rate_pct','course4_history_top3_rate_pct','course4_history_makuri_rate_pct','course4_history_makurizashi_rate_pct','course4_history_attack_rate_pct']
 st=['course4_average_st','course4_average_st_rank','course4_entry_count']
 tenji=['course4_ex_st','course4_exhibition_time','course4_lap_time','course4_around_time','course4_straight_time']
 # `overall_average_st` was intentionally absent from Phase-4 input; A2 therefore
 # uses entry count as the predeclared reliability feature, without changing course ST.
 models={'A0':base,'A1':base+st,'A2':base+['smoothed_course4_st','course4_average_st_rank','course4_entry_count'],'A3':base+['smoothed_course4_st','course4_average_st_rank','course4_entry_count']+tenji,'A4':base+['smoothed_course4_st','course4_average_st_rank','course4_entry_count']+tenji+['motor_2ren_rate','boat_2ren_rate'],'A5':base+['smoothed_course4_st','course4_average_st_rank','course4_entry_count']+tenji+['motor_2ren_rate','boat_2ren_rate','national_win_rate','local_win_rate','national_2ren_rate','local_2ren_rate']}
 for r in rows:
  r['course4_history_n']=n(r['course4_history_n']);r['course4_history_win_rate_pct']=n(r['course4_history_win_rate_pct']);r['course4_history_top2_rate_pct']=n(r['course4_history_top2_rate_pct']);r['course4_history_top3_rate_pct']=n(r['course4_history_top3_rate_pct']);r['course4_history_makuri_rate_pct']=n(r['course4_history_makuri_rate_pct']);r['course4_history_makurizashi_rate_pct']=n(r['course4_history_makurizashi_rate_pct']);r['course4_history_attack_rate_pct']=n(r['course4_history_attack_rate_pct'])
  for k in st+tenji:r[k]=n(r.get(k))
  r['course4_st_profile_reliability']=r['course4_entry_count']/(r['course4_entry_count']+20) if not np.isnan(r['course4_entry_count']) else np.nan
  e=extras.get((r['race_code'],r['course4_player_id']),{})
  for k in ('overall_average_st','motor_2ren_rate','boat_2ren_rate','national_win_rate','local_win_rate','national_2ren_rate','local_2ren_rate'):r[k]=n(e.get(k))
  # K=20 is predeclared from the existing ST reliability convention; no Holdout selection.
  w=r['course4_entry_count']/(r['course4_entry_count']+20) if not np.isnan(r['course4_entry_count']) else np.nan
  r['smoothed_course4_st']=r['overall_average_st']+w*(r['course4_average_st']-r['overall_average_st']) if not np.isnan(w) and not np.isnan(r['overall_average_st']) and not np.isnan(r['course4_average_st']) else np.nan
 dev=[r for r in rows if START<=d(r['race_date'])<=DEVEND];val=[r for r in rows if DEVEND<d(r['race_date'])<=VALEND];hold=[r for r in rows if VALEND<d(r['race_date'])<=END]
 cov=[]
 for split,rr in [('development',dev),('validation',val),('exposed_holdout',hold)]:
  for k in ('overall_average_st','motor_2ren_rate','boat_2ren_rate','national_win_rate','local_win_rate','national_2ren_rate','local_2ren_rate'):
   z=sum(not np.isnan(r[k]) for r in rr);cov.append({'split':split,'feature':k,'n':len(rr),'non_null_n':z,'coverage_pct':100*z/len(rr)})
 out=[];fits={}
 for name,fs in models.items():
  X=lambda rr:np.array([[r[x] for x in fs] for r in rr]);y=lambda rr:np.array([yes(r['four_win_attack']) for r in rr],int)
  m=Logit().fit(X(dev),y(dev));fits[name]=(m,fs)
  for split,rr in [('development',dev),('validation',val),('final_holdout',hold)]:out.append({'model':name,'split':split,'n':len(rr),'features':'|'.join(fs),**met(y(rr),m.pred(X(rr)))})
 # Validation-selected A3; no Holdout-directed feature edit.
 name='A3';m,fs=fits[name];dv=dev+val;X=lambda rr:np.array([[r[x] for x in fs] for r in rr]);y=lambda rr:np.array([yes(r['four_win_attack']) for r in rr],int);m=Logit().fit(X(dv),y(dv));p=m.pred(X(hold));order=np.argsort(p);grp=np.empty(len(hold),int);grp[order]=np.minimum(10,np.arange(len(hold))*10//len(hold)+1)
 dec=[]
 for q in range(1,11):
  ix=np.where(grp==q)[0];rr=[hold[i] for i in ix];dec.append({'decile':q,'n':len(rr),'mean_probability':float(p[ix].mean()),'four_win_attack_rate_pct':100*y(hold)[ix].mean(),'four_first_rate_pct':100*sum(yes(r['four_first']) for r in rr)/len(rr),'four_top2_rate_pct':100*sum(yes(r['four_top2']) for r in rr)/len(rr),'four_top3_rate_pct':100*sum(yes(r['four_top3']) for r in rr)/len(rr)})
 co=[]
 for f,z in zip(fs,m.b[1:]):co.append({'model':'A3','feature':f,'standardized_coefficient':z,'sign':'positive' if z>0 else 'negative'})
 scores=[{'race_code':r['race_code'],'race_date':r['race_date'],'stadium':r['stadium'],'attack_probability_a5':q,'four_win_attack':r['four_win_attack'],'four_first':r['four_first'],'four_top2':r['four_top2'],'four_top3':r['four_top3']} for r,q in zip(hold,p)]
 for path,data in ((OUT/'course4_attack_phase4_models_20241001_20260930.csv',out),(OUT/'course4_attack_phase4_deciles_20241001_20260930.csv',dec),(OUT/'course4_attack_phase4_coefficients_20241001_20260930.csv',co),(OUT/'course4_attack_phase4_coverage_20241001_20260930.csv',cov),(OUT/'course4_attack_phase4_race_scores_20260401_20260930.csv',scores)):
  with path.open('w',newline='',encoding='utf8') as h:w=csv.DictWriter(h,fieldnames=sorted({k for r in data for k in r}));w.writeheader();w.writerows(data)
 print('N',len(dev),len(val),len(hold));print(out);print(dec)
if __name__=='__main__':main()
