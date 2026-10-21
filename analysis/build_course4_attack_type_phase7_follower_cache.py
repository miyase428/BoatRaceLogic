#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Phase 7-2a: fixed-boundary follower input cache only; no outcome analysis."""
from __future__ import annotations
import csv, hashlib, json, math, subprocess, time
from collections import Counter
from datetime import datetime
from pathlib import Path
import numpy as np

ROOT=Path(__file__).resolve().parent; OUT=ROOT/'output'
SEL_P=OUT/'course4_attack_type_phase7_selection_predictions.csv'; SEL_A=OUT/'course4_attack_type_phase7_selection_model.json'
FIN_P=OUT/'course4_attack_type_phase7_final_fit_predictions.csv'; CON_P=OUT/'course4_attack_type_phase7_confirmation_predictions.csv'; FIN_A=OUT/'course4_attack_type_phase7_final_model.json'
RACE=OUT/'course4_attack_success_phase6_race_cache_20251001_20260930.csv'
CACHE=OUT/'course4_attack_type_phase7_follower_race_cache_20251001_20260930.csv'; META=OUT/'course4_attack_type_phase7_follower_boundaries.json'
EXPECTED={SEL_P:'32327a96261f50be061f1c622439d03a10a5e7d490ad4576f4e4c32ec47ea4b2',SEL_A:'84e7e172c4377f57d4cf2cef5fbb4e324f3e2ef7024ffa57dc41ca3b9bb38eee',FIN_P:'bf0ce4c7c4c6315b0fb2071a25de6af2a7ce7d0c4af41cad84bad877bb465a21',CON_P:'5ad76673e8e7bf5adae09cf780897d24332e39a390eb5c3caa7c5a5be159bb70',FIN_A:'e0f3674e3e863a4a251aafb2e87530ebba85d4c393982d019fdccd124f3707b4',RACE:'9275720d6eb56f29bf216cf0daca71cc10a6db3c052e875df8d473f349a8a778'}
TYPES={'makuri': 'p_makuri_success','makurizashi':'p_makurizashi_success'}
BAND_LABELS=['0-19','20-39','40-59','60-79','80-100']
DECILE_LABELS=[f'D{i}' for i in range(1,11)]

def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def git():return subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT.parent,text=True).strip()
def read(p):return list(csv.DictReader(p.open(encoding='utf-8')))
def prob_stats(vals):
 a=np.asarray(vals,float);return {'n':int(len(a)),'missing_n':int(np.sum(~np.isfinite(a))),'outside_0_1_n':int(np.sum(np.isfinite(a)&((a<0)|(a>1)) )),'min':float(np.nanmin(a)),'max':float(np.nanmax(a)),'mean':float(np.nanmean(a))}
def boundaries(rows,col):
 a=np.asarray([float(r[col]) for r in rows],float)
 b5=np.quantile(a,[.2,.4,.6,.8],method='linear'); b10=np.quantile(a,[.1,.2,.3,.4,.5,.6,.7,.8,.9],method='linear')
 return {'development_n':len(a),'min_probability':float(a.min()),'max_probability':float(a.max()),'five_band_percentiles':[20,40,60,80],'five_band_boundaries':b5.tolist(),'decile_percentiles':[10,20,30,40,50,60,70,80,90],'decile_boundaries':b10.tolist(),'duplicate_five_band_boundary':bool(len(set(b5.tolist()))<len(b5)),'duplicate_decile_boundary':bool(len(set(b10.tolist()))<len(b10))}
def assign(p,b):return int(np.searchsorted(np.asarray(b,float),float(p),side='right'))

def main():
 started=time.perf_counter()
 for p,h in EXPECTED.items():
  if sha(p)!=h:raise RuntimeError(f'SHA256 mismatch: {p}')
 sp=read(SEL_P); fp=read(FIN_P); cp=read(CON_P); rr=read(RACE)
 sd=[r for r in sp if r['split']=='Development']; sv=[r for r in sp if r['split']=='Validation']; fd=[r for r in fp if r['split']=='Development']
 if (len(sd),len(sv),len(fd),len(cp))!=(43787,20595,43787,22122):raise RuntimeError('prediction split count mismatch')
 bounds={'quantile_method':'numpy.quantile(method="linear")','h7_m':{'selection_development':boundaries(sd,TYPES['makuri']),'final_development':boundaries(fd,TYPES['makuri'])},'h7_ms':{'selection_development':boundaries(sd,TYPES['makurizashi']),'final_development':boundaries(fd,TYPES['makurizashi'])}}
 selmap={r['race_code']:r for r in sv}; conmap={r['race_code']:r for r in cp}
 if len(selmap)!=len(sv) or len(conmap)!=len(cp):raise RuntimeError('prediction duplicate race_code')
 fields=['race_code','race_date','stadium','split','p_makuri_success','makuri_band','makuri_decile','p_makurizashi_success','makurizashi_band','makurizashi_decile','five_first','five_second','five_third','five_top2','five_top3','five_national_win_rate','five_local_win_rate','five_average_st','five_motor_2ren_rate','five_boat_2ren_rate','six_first','six_second','six_third','six_top2','six_top3','six_national_win_rate','six_local_win_rate','six_average_st','six_motor_2ren_rate','six_boat_2ren_rate','pair_second','pair_third','pair_top3']
 source_cols={'five_first':'course5_first','five_second':'course5_second','five_third':'course5_third','five_top2':'course5_top2','five_top3':'course5_top3','five_national_win_rate':'course5_national_win_rate','five_local_win_rate':'course5_local_win_rate','five_average_st':'course5_average_st','five_motor_2ren_rate':'course5_motor_2ren_rate','five_boat_2ren_rate':'course5_boat_2ren_rate','six_first':'course6_first','six_second':'course6_second','six_third':'course6_third','six_top2':'course6_top2','six_top3':'course6_top3','six_national_win_rate':'course6_national_win_rate','six_local_win_rate':'course6_local_win_rate','six_average_st':'course6_average_st','six_motor_2ren_rate':'course6_motor_2ren_rate','six_boat_2ren_rate':'course6_boat_2ren_rate','pair_second':'pair_second','pair_third':'pair_third','pair_top3':'pair_top3'}
 out=[]; merges={}
 for split,mp,bkey in [('Validation',selmap,'selection_development'),('EXPOSED_CONFIRMATION',conmap,'final_development')]:
  base=[r for r in rr if r['split']==split]; matched=0; unmatched=[]
  for r in base:
   pr=mp.get(r['race_code'])
   if pr is None:unmatched.append(r['race_code']);continue
   matched+=1; o={k:r[k] for k in ('race_code','race_date','stadium','split')}
   for short,col in TYPES.items():
    b=bounds['h7_m' if short=='makuri' else 'h7_ms'][bkey]; v=float(pr[col]); o[col]=repr(v);o[f'{short}_band']=BAND_LABELS[assign(v,b['five_band_boundaries'])];o[f'{short}_decile']=DECILE_LABELS[assign(v,b['decile_boundaries'])]
   for dst,src in source_cols.items():o[dst]=r[src]
   out.append(o)
  merges[split]={'phase6_race_rows':len(base),'prediction_rows':len(mp),'matched_rows':matched,'unmatched_rows':len(unmatched),'unmatched_race_codes':unmatched}
 if any(m['unmatched_rows'] for m in merges.values()) or len(out)!=42717:raise RuntimeError('merge mismatch')
 if len({r['race_code'] for r in out})!=len(out) or any(not r['race_code'] for r in out):raise RuntimeError('output race_code audit failed')
 with CACHE.open('w',newline='',encoding='utf-8') as h:w=csv.DictWriter(h,fieldnames=fields);w.writeheader();w.writerows(out)
 reread=read(CACHE)
 race_by_code={r['race_code']:r for r in rr}
 outcome_exact={dst:all(a[dst]==race_by_code[a['race_code']][src] for a in out) for dst,src in source_cols.items()}
 # No aggregation of outcome values; only exact row-wise preservation audit.
 assignment={}; probabilities={}
 for split in ('Validation','EXPOSED_CONFIRMATION'):
  sub=[r for r in reread if r['split']==split]; assignment[split]={}; probabilities[split]={}
  for short,col in TYPES.items():
   probabilities[split][short]=prob_stats([r[col] for r in sub]); assignment[split][short]={'five_band_counts':dict(Counter(r[f'{short}_band'] for r in sub)),'decile_counts':dict(Counter(r[f'{short}_decile'] for r in sub)),'band_missing_n':sum(not r[f'{short}_band'] for r in sub),'decile_missing_n':sum(not r[f'{short}_decile'] for r in sub)}
 meta={'phase':'Phase 7-2a','purpose':'artifact-specific Development-fixed probability boundaries and follower race-level input cache only; no follower outcome analysis','quantile_method':'numpy.quantile(method="linear")','sources':{'selection_prediction_path':str(SEL_P.relative_to(ROOT.parent)),'selection_prediction_sha256':sha(SEL_P),'selection_artifact_path':str(SEL_A.relative_to(ROOT.parent)),'selection_artifact_sha256':sha(SEL_A),'final_fit_prediction_path':str(FIN_P.relative_to(ROOT.parent)),'final_fit_prediction_sha256':sha(FIN_P),'confirmation_prediction_path':str(CON_P.relative_to(ROOT.parent)),'confirmation_prediction_sha256':sha(CON_P),'final_artifact_path':str(FIN_A.relative_to(ROOT.parent)),'final_artifact_sha256':sha(FIN_A),'phase6_race_cache_path':str(RACE.relative_to(ROOT.parent)),'phase6_race_cache_sha256':sha(RACE)},'boundaries':bounds,'merge_audit':merges,'probability_audit':probabilities,'assignment_audit':assignment,'outcome_and_ability_exact_match':outcome_exact,'cache_path':str(CACHE.relative_to(ROOT.parent)),'cache_sha256':sha(CACHE),'cache_reload_audit':{'row_count':len(reread),'duplicate_race_code':len(reread)-len({r['race_code'] for r in reread}),'missing_race_code':sum(not r['race_code'] for r in reread),'split_counts':dict(Counter(r['split'] for r in reread)),'prediction_missing_n':sum(not r['p_makuri_success'] or not r['p_makurizashi_success'] for r in reread),'band_or_decile_missing_n':sum(not r[k] for r in reread for k in ('makuri_band','makuri_decile','makurizashi_band','makurizashi_decile'))},'generated_at':datetime.now().astimezone().isoformat(),'source_script':'analysis/build_course4_attack_type_phase7_follower_cache.py','source_script_sha256':sha(Path(__file__)),'source_git_commit':git(),'timing_seconds':{'total':time.perf_counter()-started}}
 META.write_text(json.dumps(meta,ensure_ascii=False,indent=2),encoding='utf-8')
 print(json.dumps({'cache':str(CACHE),'cache_sha256':meta['cache_sha256'],'merge':merges,'probability_audit':probabilities,'assignment_audit':assignment,'outcome_exact_match':all(outcome_exact.values()),'reload_audit':meta['cache_reload_audit'],'metadata':str(META),'total_seconds':meta['timing_seconds']['total']},ensure_ascii=False,indent=2))
if __name__=='__main__':main()
