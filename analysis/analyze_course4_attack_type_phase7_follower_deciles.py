#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Phase 7-2c: fixed-decile descriptive follower analysis only."""
from __future__ import annotations
import csv, hashlib, json, subprocess, time
from collections import Counter
from datetime import datetime
from pathlib import Path
import numpy as np

ROOT=Path(__file__).resolve().parent; OUT=ROOT/'output'
CACHE=OUT/'course4_attack_type_phase7_follower_race_cache_20251001_20260930.csv'; META=OUT/'course4_attack_type_phase7_follower_boundaries.json'; HIGHLOW=OUT/'course4_attack_type_phase7_follower_bootstrap_2000.json'
CSV_OUT=OUT/'course4_attack_type_phase7_follower_deciles.csv'; REPORT=OUT/'course4_attack_type_phase7_follower_deciles_report.json'
EXPECTED_CACHE='50fca8d0cfb678adfecc219a413b04f9fb9a39571e62a4fe3b4c0deb07ddc03a'; EXPECTED_META='2191713a1d4452e5b7cbfc7df95c81f852cf415b59fad75d6f28b66b4ce7e5bf'
OUTCOMES=('first','second','third','top2','top3'); TYPES={'H7-M':'makuri','H7-MS':'makurizashi'}; SPLITS=('Validation','EXPOSED_CONFIRMATION')
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def git():return subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT.parent,text=True).strip()
def n(v):return int(float(v))
def rankdata(x):
 a=np.asarray(x,float);o=np.argsort(a,kind='mergesort');z=np.empty(len(a));i=0
 while i<len(a):
  j=i
  while j+1<len(a) and a[o[j+1]]==a[o[i]]:j+=1
  z[o[i:j+1]]=(i+j+2)/2;i=j+1
 return z
def spearman(x,y):return float(np.corrcoef(rankdata(x),rankdata(y))[0,1])
def sign(v):return 'positive' if v>0 else ('negative' if v<0 else 'zero')
def main():
 started=time.perf_counter()
 if sha(CACHE)!=EXPECTED_CACHE or sha(META)!=EXPECTED_META:raise RuntimeError('fixed input SHA256 mismatch')
 rows=list(csv.DictReader(CACHE.open(encoding='utf-8')))
 if len(rows)!=42717 or len({r['race_code'] for r in rows})!=42717 or any(not r['race_code'] for r in rows):raise RuntimeError('race cache audit failed')
 if Counter(r['split'] for r in rows)!={'Validation':20595,'EXPOSED_CONFIRMATION':22122}:raise RuntimeError('split count mismatch')
 bysplit={s:[r for r in rows if r['split']==s] for s in SPLITS}; output=[]; summaries={}
 for typ,prefix in TYPES.items():
  dcol=prefix+'_decile'; bcol=prefix+'_band'; summaries[typ]={}
  for split in SPLITS:
   sub=bysplit[split]
   if any(not r[dcol] or not r[bcol] or not r['p_'+prefix+'_success'] for r in sub):raise RuntimeError('prediction/decile/band missing')
   dec=[]
   for i in range(1,11):
    q=[r for r in sub if r[dcol]==f'D{i}']; rec={'type':typ,'split':split,'decile':f'D{i}','N':len(q)}
    for side in ('five','six'):
     for o in OUTCOMES:
      c=sum(n(r[side+'_'+o]) for r in q);rec[f'{side}_{o}_count']=c;rec[f'{side}_{o}_rate']=c/len(q)
    output.append(rec);dec.append(rec)
   if sum(x['N'] for x in dec)!=len(sub):raise RuntimeError('decile N mismatch')
   lifts={};trends={}
   for o in ('second','third','top3'):
    d1,d10=dec[0],dec[-1]; five=d10['five_'+o+'_rate']-d1['five_'+o+'_rate'];six=d10['six_'+o+'_rate']-d1['six_'+o+'_rate']
    lifts[o]={'five_d1_rate':d1['five_'+o+'_rate'],'five_d10_rate':d10['five_'+o+'_rate'],'five_lift':five,'six_d1_rate':d1['six_'+o+'_rate'],'six_d10_rate':d10['six_'+o+'_rate'],'six_lift':six,'follower_gap':five-six}
    trends[o]={'five_spearman':spearman(range(1,11),[x['five_'+o+'_rate'] for x in dec]),'six_spearman':spearman(range(1,11),[x['six_'+o+'_rate'] for x in dec])}
   summaries[typ][split]={'decile_N':[x['N'] for x in dec],'d10_minus_d1':lifts,'spearman_trend':trends}
 # Phase 7-2b report is used only to compare already-fixed top3 directional results.
 high=json.load(HIGHLOW.open())['bootstrap']; comparisons={}
 for typ in TYPES:
  comparisons[typ]={}
  components=[]
  for split in SPLITS:
   hp=high[typ]['point_estimates'][split]['top3']; dp=summaries[typ][split]['d10_minus_d1']['top3']; tr=summaries[typ][split]['spearman_trend']['top3']
   row={'high_low_five_lift':hp['five_lift'],'high_low_follower_gap':hp['follower_gap'],'d10_d1_five_lift':dp['five_lift'],'d10_d1_follower_gap':dp['follower_gap'],'five_top3_spearman':tr['five_spearman'],'six_top3_spearman':tr['six_spearman'],'five_lift_sign_match':sign(hp['five_lift'])==sign(dp['five_lift']),'follower_gap_sign_match':sign(hp['follower_gap'])==sign(dp['follower_gap'])}
   comparisons[typ][split]=row;components += [row['high_low_five_lift'],row['high_low_follower_gap'],row['d10_d1_five_lift'],row['d10_d1_follower_gap'],row['five_top3_spearman']]
  diag='CONSISTENT_POSITIVE' if all(x>0 for x in components) else ('CONSISTENT_NEGATIVE' if all(x<0 for x in components) else 'MIXED')
  comparisons[typ]['provisional_decile_diagnosis']=diag
 fields=['type','split','decile','N']+[f'{s}_{o}_{x}' for s in ('five','six') for o in OUTCOMES for x in ('count','rate')]
 with CSV_OUT.open('w',newline='',encoding='utf-8') as h:w=csv.DictWriter(h,fieldnames=fields);w.writeheader();w.writerows(output)
 report={'phase':'Phase 7-2c','purpose':'fixed-decile descriptive follower outcomes only; no boundary recalculation or inferential adjustment','follower_race_cache_path':str(CACHE.relative_to(ROOT.parent)),'follower_race_cache_sha256':sha(CACHE),'boundary_metadata_path':str(META.relative_to(ROOT.parent)),'boundary_metadata_sha256':sha(META),'phase7_2b_comparison_source':str(HIGHLOW.relative_to(ROOT.parent)),'split_audit':{s:{'n':len(bysplit[s]),'prediction_missing_n':sum(not r['p_makuri_success'] or not r['p_makurizashi_success'] for r in bysplit[s]),'decile_missing_n':sum(not r['makuri_decile'] or not r['makurizashi_decile'] for r in bysplit[s])} for s in SPLITS},'duplicate_race_code':len(rows)-len({r['race_code'] for r in rows}),'decile_analysis':summaries,'high_low_direction_consistency':comparisons,'decile_csv_path':str(CSV_OUT.relative_to(ROOT.parent)),'decile_csv_sha256':sha(CSV_OUT),'generated_at':datetime.now().astimezone().isoformat(),'source_script':'analysis/analyze_course4_attack_type_phase7_follower_deciles.py','source_script_sha256':sha(Path(__file__)),'source_git_commit':git(),'timing_seconds':{'total':time.perf_counter()-started}}
 REPORT.write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8')
 print(json.dumps({'diagnoses':{t:comparisons[t]['provisional_decile_diagnosis'] for t in TYPES},'csv':str(CSV_OUT),'report':str(REPORT),'total_seconds':report['timing_seconds']['total']},ensure_ascii=False,indent=2))
if __name__=='__main__':main()
