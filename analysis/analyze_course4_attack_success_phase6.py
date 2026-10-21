#!/usr/bin/env python3
"""Phase 6 read-only follower-benefit diagnostics from frozen Phase 5 scores."""
import csv,json,random
from pathlib import Path
import numpy as np
from analyze_course4_attack_success_phase5 import base_scores,predict_artifact,boundaries,score,yes,metrics
OUT=Path(__file__).resolve().parent/'output'
def write(p,x):
 k=sorted({z for r in x for z in r});
 with open(p,'w',newline='',encoding='utf8') as h:w=csv.DictWriter(h,fieldnames=k);w.writeheader();w.writerows(x)
def rate(rows,k):return 100*sum(yes(r[k]) for r in rows)/len(rows) if rows else None
def groups(rows,p,b,n=5):
 if n==5:return [score(p,b)[i]//20 for i in range(len(p))]
 o=sorted(range(len(p)),key=lambda i:(p[i],rows[i]['race_code']));g=[0]*len(p)
 for j,i in enumerate(o):g[i]=j*n//len(p)
 return g
def tab(rows,p,b,label):
 g=groups(rows,p,b);out=[]
 for q in range(5):
  rr=[r for i,r in enumerate(rows) if g[i]==q];d={'dataset':label,'band':f'{q*20}-{q*20+19 if q<4 else 100}','n':len(rr)}
  for x in ('five_first','five_second','five_third','five_top2','five_top3','six_first','six_second','six_third','six_top2','six_top3'):d[x+'_rate_pct']=rate(rr,x)
  d['pair_second_mean']=np.mean([yes(r['five_second'])-yes(r['six_second']) for r in rr]);d['pair_third_mean']=np.mean([yes(r['five_third'])-yes(r['six_third']) for r in rr]);d['pair_top3_mean']=np.mean([yes(r['five_top3'])-yes(r['six_top3']) for r in rr]);out.append(d)
 base=out[0]
 for d in out:
  for x in ('second','third','top3'):
   d['follower_gap_'+x+'_pct_point']=(d['five_'+x+'_rate_pct']-base['five_'+x+'_rate_pct'])-(d['six_'+x+'_rate_pct']-base['six_'+x+'_rate_pct'])
 return out
def bootstrap(rows,p,b,B=500):
 import time
 t=time.monotonic();g=groups(rows,p,b);keys=sorted({(r['stadium'],r['race_date']) for r in rows});pos={k:i for i,k in enumerate(keys)};a=np.zeros((len(keys),5,7),float)
 # columns N, five-second, five-third, five-top3, six-second, six-third, six-top3
 for r,q in zip(rows,g):
  x=a[pos[(r['stadium'],r['race_date'])],q];x[0]+=1;x[1]+=yes(r['five_second']);x[2]+=yes(r['five_third']);x[3]+=yes(r['five_top3']);x[4]+=yes(r['six_second']);x[5]+=yes(r['six_third']);x[6]+=yes(r['six_top3'])
 prep=time.monotonic()-t;rng=np.random.default_rng(20261007);parts=[]
 for z in range(0,B,25):
  idx=rng.integers(0,len(keys),(min(25,B-z),len(keys)));parts.append(a[idx].sum(1))
 tot=np.concatenate(parts)
 def lift(col):return 100*(tot[:,4,col]/tot[:,4,0]-tot[:,0,col]/tot[:,0,0])
 ftop,stop,fsec,ssec=lift(3),lift(6),lift(1),lift(4);q=lambda x:[float(np.quantile(x,.025)),float(np.quantile(x,.975))]
 return {'five_top3_high_low_lift_ci':q(ftop),'six_top3_high_low_lift_ci':q(stop),'follower_gap_top3_ci':q(ftop-stop),'five_second_high_low_lift_ci':q(fsec),'six_second_high_low_lift_ci':q(ssec),'follower_gap_second_ci':q(fsec-ssec),'bootstrap_reps':B,'cluster':'stadium-race_date','cluster_count':len(keys),'preprocessing_seconds':prep,'bootstrap_seconds':time.monotonic()-t-prep,'total_seconds':time.monotonic()-t}
def main():
 (dev,val,conf),(ds,vs,dfs,vfs,cs),(yd,yv,yc),_=base_scores();s=json.loads((OUT/'course4_attack_success_phase5_selection_model.json').read_text());f=json.loads((OUT/'course4_attack_success_phase5_final_model.json').read_text());pv=predict_artifact(s,vs);pc=predict_artifact(f,cs);tv,tc=tab(val,pv,s['score_percentile_boundaries'],'validation'),tab(conf,pc,f['score_percentile_boundaries'],'exposed_confirmation');write(OUT/'course4_attack_success_phase6_bands.csv',tv+tc);rep={'validation_bands':tv,'confirmation_bands':tc,'confirmation_bootstrap':bootstrap(conf,pc,f['score_percentile_boundaries'])};(OUT/'course4_attack_success_phase6_report.json').write_text(json.dumps(rep,ensure_ascii=False,indent=2),encoding='utf8');print(json.dumps(rep,ensure_ascii=False,indent=2))
if __name__=='__main__':main()
