#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Phase 7-2b: fixed-band high/low follower analysis and cluster bootstrap."""
from __future__ import annotations
import csv, hashlib, json, subprocess, time
from collections import Counter, defaultdict
from datetime import datetime
from pathlib import Path
import numpy as np

ROOT=Path(__file__).resolve().parent; OUT=ROOT/'output'
CACHE=OUT/'course4_attack_type_phase7_follower_race_cache_20251001_20260930.csv'; META=OUT/'course4_attack_type_phase7_follower_boundaries.json'
CSV_OUT=OUT/'course4_attack_type_phase7_follower_high_low.csv'; BOOT_OUT=OUT/'course4_attack_type_phase7_follower_bootstrap_2000.json'
EXPECTED_CACHE='50fca8d0cfb678adfecc219a413b04f9fb9a39571e62a4fe3b4c0deb07ddc03a'; EXPECTED_META='2191713a1d4452e5b7cbfc7df95c81f852cf415b59fad75d6f28b66b4ce7e5bf'
SEED=20261007; ITERATIONS=2000; OUTCOMES=('second','third','top3'); TYPES={'H7-M':'makuri','H7-MS':'makurizashi'}
SPLITS=('Validation','EXPOSED_CONFIRMATION')

def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def git():return subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT.parent,text=True).strip()
def n(v):return int(float(v))
def rate(s,c):return s/c if c else float('nan')
def calc(low_n,high_n,low5,high5,low6,high6):
 f_low,f_high=rate(low5,low_n),rate(high5,high_n); s_low,s_high=rate(low6,low_n),rate(high6,high_n)
 return {'low_N':int(low_n),'high_N':int(high_n),'five_low_rate':f_low,'five_high_rate':f_high,'five_lift':f_high-f_low,'six_low_rate':s_low,'six_high_rate':s_high,'six_lift':s_high-s_low,'follower_gap':(f_high-f_low)-(s_high-s_low)}
def direct(rows,band,outcome):
 low=[r for r in rows if r[band]=='0-19']; high=[r for r in rows if r[band]=='80-100']
 return calc(len(low),len(high),sum(n(r['five_'+outcome]) for r in low),sum(n(r['five_'+outcome]) for r in high),sum(n(r['six_'+outcome]) for r in low),sum(n(r['six_'+outcome]) for r in high))
def paired(rows,band,outcome):
 low=[r for r in rows if r[band]=='0-19']; high=[r for r in rows if r[band]=='80-100']; col='pair_'+outcome
 return sum(n(r[col]) for r in high)/len(high)-sum(n(r[col]) for r in low)/len(low)

def bootstrap(rows,band,seed):
 clusters=defaultdict(list)
 for r in rows:clusters[(r['stadium'],r['race_date'])].append(r)
 keys=list(clusters); c=len(keys); # columns: lowN/highN; then for each outcome low5/high5/low6/high6
 mat=np.zeros((c,2+4*len(OUTCOMES)),dtype=np.int64)
 for i,k in enumerate(keys):
  for r in clusters[k]:
   islow=r[band]=='0-19'; ishigh=r[band]=='80-100'
   if not(islow or ishigh):continue
   if islow:mat[i,0]+=1
   else:mat[i,1]+=1
   for j,o in enumerate(OUTCOMES):
    off=2+4*j
    if islow:
     mat[i,off]+=n(r['five_'+o]);mat[i,off+2]+=n(r['six_'+o])
    else:
     mat[i,off+1]+=n(r['five_'+o]);mat[i,off+3]+=n(r['six_'+o])
 rng=np.random.default_rng(seed); values={o:{'five_lift':[],'six_lift':[],'follower_gap':[]} for o in OUTCOMES}
 for _ in range(ITERATIONS):
  s=mat[rng.integers(0,c,size=c)].sum(0); ln,hn=s[:2]
  for j,o in enumerate(OUTCOMES):
   off=2+4*j; z=calc(ln,hn,s[off],s[off+1],s[off+2],s[off+3])
   for q in values[o]:values[o][q].append(z[q])
 result={}
 for o,d in values.items():
  lo,hi=(.0125,.9875) if o=='top3' else (.025,.975)
  result[o]={q:{'bootstrap_mean':float(np.mean(v)),'ci_lower':float(np.quantile(v,lo,method='linear')),'ci_upper':float(np.quantile(v,hi,method='linear')),'ci_level':97.5 if o=='top3' else 95.0,'percentiles':[100*lo,100*hi]} for q,v in d.items()}
 return result,{'unique_venue_date_clusters':c,'resample_draw_count_per_iteration':c,'iterations_with_expected_draw_count':ITERATIONS}

def diagnosis(points,boots):
 v=points['Validation']['top3']; c=points['EXPOSED_CONFIRMATION']['top3']; ci=boots['EXPOSED_CONFIRMATION']['top3']['follower_gap']['ci_lower']
 if c['five_lift']<0 or c['follower_gap']<0:return 'NEGATIVE'
 if v['five_lift']>0 and v['follower_gap']>0 and c['five_lift']>0 and c['follower_gap']>0:
  return 'STRONG_PROMISING' if ci>0 else 'PROMISING'
 return 'MIXED'

def main():
 started=time.perf_counter(); load_start=time.perf_counter()
 if sha(CACHE)!=EXPECTED_CACHE or sha(META)!=EXPECTED_META:raise RuntimeError('fixed input SHA256 mismatch')
 rows=list(csv.DictReader(CACHE.open(encoding='utf-8'))); load_s=time.perf_counter()-load_start
 if len(rows)!=42717 or len({r['race_code'] for r in rows})!=42717 or any(not r['race_code'] for r in rows):raise RuntimeError('cache race_code audit failed')
 if Counter(r['split'] for r in rows)!={'Validation':20595,'EXPOSED_CONFIRMATION':22122}:raise RuntimeError('split count mismatch')
 prep_start=time.perf_counter(); bysplit={s:[r for r in rows if r['split']==s] for s in SPLITS}
 for rs in bysplit.values():
  for r in rs:
   if not r['p_makuri_success'] or not r['p_makurizashi_success'] or not r['makuri_band'] or not r['makurizashi_band']:raise RuntimeError('prediction/band missing')
 prep_s=time.perf_counter()-prep_start; outputs=[]; boot={}; runtime={}
 for ti,(typ,prefix) in enumerate(TYPES.items()):
  band=prefix+'_band'; point={s:{} for s in SPLITS}; boot[typ]={}
  for s in SPLITS:
   for o in OUTCOMES:
    z=direct(bysplit[s],band,o); pd=paired(bysplit[s],band,o); z['paired_high_low']=pd;z['paired_minus_follower_gap']=pd-z['follower_gap'];z['paired_diagnostic_pass']=abs(pd-z['follower_gap'])<=1e-12
    point[s][o]=z; outputs.append({'type':typ,'split':s,'outcome':o,**z})
  for si,s in enumerate(SPLITS):
   t=time.perf_counter(); b,a=bootstrap(bysplit[s],band,SEED+ti*100+si);runtime[f'{typ}_{s}']=time.perf_counter()-t;boot[typ][s]=b;boot[typ][s]['cluster_audit']=a
  boot[typ]['provisional_diagnosis']=diagnosis(point,boot[typ]); boot[typ]['point_estimates']=point
 with CSV_OUT.open('w',newline='',encoding='utf-8') as h:
  fields=['type','split','outcome','low_N','high_N','five_low_rate','five_high_rate','five_lift','six_low_rate','six_high_rate','six_lift','follower_gap','paired_high_low','paired_minus_follower_gap','paired_diagnostic_pass'];w=csv.DictWriter(h,fieldnames=fields);w.writeheader();w.writerows(outputs)
 report={'phase':'Phase 7-2b','purpose':'fixed-band High-Low follower comparison plus venue-date cluster bootstrap only','follower_race_cache_path':str(CACHE.relative_to(ROOT.parent)),'follower_race_cache_sha256':sha(CACHE),'boundary_metadata_path':str(META.relative_to(ROOT.parent)),'boundary_metadata_sha256':sha(META),'seed':SEED,'iterations':ITERATIONS,'cluster_definition':'stadium × race_date; all races in the venue-date sampled together','splits':{s:{'n':len(bysplit[s])} for s in SPLITS},'primary_ci_percentile':{'outcome':'top3','ci_level':97.5,'percentiles':[1.25,98.75]},'secondary_ci_percentile':{'outcomes':['second','third'],'ci_level':95.0,'percentiles':[2.5,97.5]},'bootstrap':boot,'high_low_csv_path':str(CSV_OUT.relative_to(ROOT.parent)),'high_low_csv_sha256':sha(CSV_OUT),'generated_at':datetime.now().astimezone().isoformat(),'source_script':'analysis/analyze_course4_attack_type_phase7_follower_high_low.py','source_script_sha256':sha(Path(__file__)),'source_git_commit':git(),'timing_seconds':{'cache_load':load_s,'preprocessing':prep_s,**runtime,'total':time.perf_counter()-started}}
 BOOT_OUT.write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8')
 print(json.dumps({'diagnosis':{t:boot[t]['provisional_diagnosis'] for t in TYPES},'csv':str(CSV_OUT),'json':str(BOOT_OUT),'runtime':report['timing_seconds']},ensure_ascii=False,indent=2))
if __name__=='__main__':main()
