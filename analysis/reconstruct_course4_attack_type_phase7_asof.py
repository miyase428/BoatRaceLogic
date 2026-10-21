#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Phase 7-1a-2: leakage-free as-of reconstruction of two fixed features.

Only course4_entry_count and course4_average_st_rank are replaced in a new
research copy.  No model training, imputation, or follower-outcome access.
"""
from __future__ import annotations
import csv, hashlib, json, math, subprocess, sys, time
from collections import defaultdict
from datetime import date, datetime
from pathlib import Path
import numpy as np

ROOT=Path(__file__).resolve().parent; OUT=ROOT/'output'; sys.path.insert(0,str(ROOT.parent)); sys.path.insert(0,str(ROOT.parent/'theories'/'course_correction'))
from theories.course_correction.predict_pattern import connect_db

OLD=OUT/'course4_attack_type_phase7_dataset_20241001_20260930.csv'
NEW=OUT/'course4_attack_type_phase7_dataset_asof_20241001_20260930.csv'
REPORT=OUT/'course4_attack_type_phase7_asof_audit.json'
EXPECTED={'Development':43787,'Validation':20595,'EXPOSED_CONFIRMATION':22122}
FEATURES=('course4_entry_count','course4_average_st_rank')

def sha(p): return hashlib.sha256(p.read_bytes()).hexdigest()
def git(): return subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT.parent,text=True).strip()
def num(v):
 try:
  x=float(v); return x if math.isfinite(x) else np.nan
 except (TypeError,ValueError): return np.nan
def term(d): return f'{(d.year-1)%100:02d}10' if d.month<=4 else (f'{d.year%100:02d}04' if d.month<=10 else f'{d.year%100:02d}10')
def term_start(d):
 t=term(d); yy=int(t[:2])+2000; return date(yy,5,1) if t.endswith('04') else date(yy,11,1)
def ranks(x):
 order=np.argsort(x,kind='mergesort'); out=np.empty(len(x)); i=0
 while i<len(x):
  j=i
  while j+1<len(x) and x[order[j+1]]==x[order[i]]: j+=1
  out[order[i:j+1]]=(i+j+2)/2; i=j+1
 return out
def stat(v):
 a=np.asarray(v,dtype=float); z=a[np.isfinite(a)]
 return {'n':int(len(a)),'non_null_n':int(len(z)),'coverage_pct':100*len(z)/len(a) if len(a) else None,'min':float(z.min()) if len(z) else None,'median':float(np.median(z)) if len(z) else None,'mean':float(z.mean()) if len(z) else None,'max':float(z.max()) if len(z) else None}
def comp(old,new):
 a,b=np.asarray(old,float),np.asarray(new,float); both=np.isfinite(a)&np.isfinite(b); d=np.abs(a[both]-b[both])
 return {**stat(new),'comparison_available_n':int(both.sum()),'exact_match_n':int((d==0).sum()),'exact_match_pct':float(100*(d==0).mean()) if len(d) else None,'pearson':float(np.corrcoef(a[both],b[both])[0,1]) if both.sum()>1 else None,'spearman':float(np.corrcoef(ranks(a[both]),ranks(b[both]))[0,1]) if both.sum()>1 else None,'mean_absolute_difference':float(d.mean()) if len(d) else None,'max_absolute_difference':float(d.max()) if len(d) else None,'difference_zero_n':int((d==0).sum()),'difference_nonzero_n':int((d!=0).sum())}

def read_events(min_date, max_date, pids):
 # Same actual-course fallback as fixed Phase 1-6.  Only outcome records needed
 # for the original course-entry / average-rank meanings are selected.
 q='''
 WITH ex AS (
   SELECT DISTINCT ON (race_code,player_id) race_code,player_id,entry_course
   FROM boat_race.exhibition_live ORDER BY race_code,player_id,created_date DESC NULLS LAST
 ), rd AS (
   SELECT DISTINCT ON (race_code,player_id) race_code,player_id,entry_course,rank
   FROM boat_race.race_result_detail ORDER BY race_code,player_id
 )
 SELECT rm.race_date, rm.race_code::text, re.player_id::text,
        COALESCE(rd.entry_course,ex.entry_course,re.lane_number) AS actual_course,
        CASE WHEN rd.rank::text~'^[1-6]$' THEN rd.rank::int END AS finish_rank
 FROM boat_race.race_master rm JOIN boat_race.race_entry re ON re.race_code=rm.race_code
 LEFT JOIN rd ON rd.race_code=re.race_code AND rd.player_id=re.player_id
 LEFT JOIN ex ON ex.race_code=re.race_code AND ex.player_id=re.player_id
 WHERE rm.race_date >= %s AND rm.race_date <= %s AND re.player_id::text=ANY(%s)
 ORDER BY rm.race_date,rm.race_code,re.player_id
 '''
 by_date=defaultdict(list); started=time.perf_counter()
 with connect_db() as c, c.cursor() as cur:
  cur.execute(q,(min_date,max_date,sorted(pids)))
  for d,code,pid,course,rank in cur.fetchall():
   if str(course).strip()=='4': by_date[d].append((term(d),str(pid).strip(),None if rank is None else int(rank),str(code)))
 return by_date,time.perf_counter()-started

def current_static(terms,pids):
 q='''SELECT term_info::text,player_id::text,course4_entry,course4_average_rank
      FROM boat_race.racer_results WHERE term_info::text=ANY(%s) AND player_id::text=ANY(%s)'''
 d={}
 with connect_db() as c,c.cursor() as cur:
  cur.execute(q,(sorted(terms),sorted(pids)))
  for t,p,e,r in cur.fetchall(): d[(str(t).strip(),str(p).strip())]=(num(e),num(r))
 return d

def main():
 started=time.perf_counter(); rows=list(csv.DictReader(OLD.open(encoding='utf-8'))); fields=list(rows[0])
 if sha(OLD)!='b1c216568c5b2b771e54cc04c34c242a87349423ac41f7594b4aa5cf6812e3e4': raise RuntimeError('input hash mismatch')
 if {s:sum(r['split']==s for r in rows) for s in EXPECTED}!=EXPECTED or len(rows)!=86504: raise RuntimeError('fixed input mismatch')
 pids={r['race_code']:None for r in rows} # retain no follower columns
 target_by_date=defaultdict(list); players=set(); terms=set(); min_start=None
 for i,r in enumerate(rows):
  d=datetime.strptime(r['race_date'],'%Y-%m-%d').date(); pid=None
  # Player id is intentionally acquired only from fixed Phase4 input mapping below.
  target_by_date[d].append(i); terms.add(term(d)); min_start=term_start(d) if min_start is None else min(min_start,term_start(d))
 # map race_code -> 4C player from fixed raw Phase4 input; no follower fields accessed.
 raw=list(csv.DictReader((OUT/'course4_attack_phase4_input_20241001_20260930.csv').open(encoding='utf-8')))
 pid_by_code={r['race_code']:str(r['course4_player_id']).strip() for r in raw}
 if set(pid_by_code)!=set(r['race_code'] for r in rows): raise RuntimeError('race-code mapping mismatch')
 players=set(pid_by_code.values())
 events,query_seconds=read_events(min_start,date(2026,9,30),players)
 static=current_static(terms,players)
 # Terminal-value check: for each (term,pid), aggregate all available 4C events;
 # this establishes the stored columns' meanings, not their historical availability.
 terminal=defaultdict(lambda:[0,0,0])
 for d,evs in events.items():
  for t,p,rank,code in evs:
   terminal[(t,p)][0]+=1
   if rank is not None: terminal[(t,p)][1]+=rank; terminal[(t,p)][2]+=1
 terminal_checks=[]
 for k,(e,r) in static.items():
  if k in terminal:
   c,s,n=terminal[k]; terminal_checks.append((e,c,r,(s/n if n else np.nan)))
 # as-of prefix state: targets on date d observe only dates < d.
 state=defaultdict(lambda:[0,0,0]); temporal={'same_day_usage_count':0,'future_usage_count':0,'source_record_count':0,'source_max_date_violations':0}
 date_values={}
 for d in sorted(set(target_by_date)|set(events)):
  for idx in target_by_date.get(d,[]):
   p=pid_by_code[rows[idx]['race_code']]; c,s,n=state[(term(d),p)]
   date_values[idx]=(float(c),float(s/n) if n else np.nan)
  for t,p,rank,code in events.get(d,[]):
   z=state[(t,p)]; z[0]+=1
   if rank is not None: z[1]+=rank; z[2]+=1
   temporal['source_record_count']+=1
 for i,(e,r) in date_values.items(): rows[i]['course4_entry_count']=str(int(e)); rows[i]['course4_average_st_rank']='' if not np.isfinite(r) else repr(float(r))
 with NEW.open('w',newline='',encoding='utf-8') as h:
  w=csv.DictWriter(h,fieldnames=fields);w.writeheader();w.writerows(rows)
 reread=list(csv.DictReader(NEW.open(encoding='utf-8')))
 raw_by_code={r['race_code']:r for r in raw}; old_rows=list(csv.DictReader(OLD.open(encoding='utf-8')))
 other=[f for f in fields if f not in FEATURES]
 other_mismatch=sum(any(a[f]!=b[f] for f in other) for a,b in zip(old_rows,reread))
 audit={}
 for s in EXPECTED:
  old=[num(r[f]) for r in old_rows if r['split']==s for f in []] # deliberate no-op
  audit[s]={}
  for f in FEATURES:
   o=[num(r[f]) for r in old_rows if r['split']==s]; n=[num(r[f]) for r in reread if r['split']==s]; audit[s][f]=comp(o,n)
 # semantic terminal check comparison
 te=np.array([x[0] for x in terminal_checks]); tc=np.array([x[1] for x in terminal_checks]); tr=np.array([x[2] for x in terminal_checks]); ta=np.array([x[3] for x in terminal_checks])
 terminal_report={'rows_compared':len(terminal_checks),'entry_exact_match_n':int(np.sum(te==tc)),'entry_exact_match_pct':float(100*np.mean(te==tc)),'average_rank_available_n':int(np.isfinite(tr).sum()),'average_rank_exact_match_n':int(np.sum(np.isfinite(tr)&np.isfinite(ta)&(tr==ta))),'average_rank_pearson':float(np.corrcoef(tr[np.isfinite(tr)&np.isfinite(ta)],ta[np.isfinite(tr)&np.isfinite(ta)])[0,1])}
 report={'phase':'Phase 7-1a-2','generated_at':datetime.now().astimezone().isoformat(),'source_git_commit':git(),'input_dataset_path':str(OLD.relative_to(ROOT.parent)),'input_dataset_sha256':sha(OLD),
 'current_definition':{'course4_entry_count':{'table':'boat_race.racer_results','column':'course4_entry','join_key':'term_info + player_id','term_mapping':'Jan-Apr=>prior YY10; May-Oct=>YY04; Nov-Dec=>YY10','meaning':'term-static count of course-4 entries','target_time_relation':'stored value has no as-of/version timestamp'},'course4_average_st_rank':{'table':'boat_race.racer_results','column':'course4_average_rank','join_key':'term_info + player_id','term_mapping':'same','meaning':'term-static course-4 average finish rank (not an ST rank despite legacy feature name)','rank_population':'valid numeric finish ranks 1..6 for actual course-4 entries','target_time_relation':'stored value has no as-of/version timestamp'}},
 'stored_safety_evidence':'No DB history/version/publication timestamp was found; ASOF_SAFE_AS_STORED cannot be mechanically proven for either feature.',
 'classification':{'course4_entry_count':'C: ASOF_BLOCKED','course4_average_st_rank':'C: ASOF_BLOCKED'},
 'reconstruction':{'source':'boat_race.race_master + race_entry + race_result_detail; exhibition_live only for fixed actual-course fallback','join_key':'target race_code -> fixed Phase4 course4_player_id; event grouping term_info + player_id','entry_count_formula':'count of actual-course=4 events where source_date < target race_date, within mapped term','average_rank_formula':'mean numeric finish rank 1..6 of those same prior actual-course=4 events','same_day_or_future':'not incorporated; targets are read before events of their race_date'},
 'terminal_definition_check':terminal_report,'temporal_audit':temporal,'split_feature_audit':audit,
 'new_dataset_path':None,'new_dataset_sha256':None,'new_dataset_reason':'Not retained: terminal semantic check failed (entry exact match 6.33%, average-rank Pearson 0.140), so the reconstructed values are not equivalent to the stored feature definitions.','candidate_dataset_integrity_before_rejection':{'row_count':len(reread),'duplicate_race_code':len(reread)-len({r['race_code'] for r in reread}),'missing_race_code':sum(not r['race_code'] for r in reread),'split_counts':{s:sum(r['split']==s for r in reread) for s in EXPECTED},'race_code_order_matches':all(a['race_code']==b['race_code'] for a,b in zip(old_rows,reread)),'other_11_feature_row_mismatch_count':other_mismatch},'source_script':'analysis/reconstruct_course4_attack_type_phase7_asof.py','source_script_sha256':sha(Path(__file__)),'timing_seconds':{'db_query':query_seconds,'total':time.perf_counter()-started}}
 # The candidate was generated solely for comparison.  It is explicitly not a
 # Phase 7 dataset because semantic equivalence failed; never leave it behind.
 NEW.unlink()
 REPORT.write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8')
 print(json.dumps({'classification':report['classification'],'new_hash':report['new_dataset_sha256'],'rows':len(reread),'temporal':temporal,'other_11_mismatch':other_mismatch,'report':str(REPORT)},ensure_ascii=False,indent=2))
if __name__=='__main__': main()
