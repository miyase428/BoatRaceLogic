#!/usr/bin/env python3
"""Read-only, leakage-audited 12-month 4C profiles for Phase 4.

The history predicate is intentionally half-open: [race_date - 12 months,
race_date).  Thus same-day, target-race, and future results are excluded.
"""
from __future__ import annotations
import bisect,csv,sys
from calendar import monthrange
from collections import defaultdict
from datetime import date,datetime
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parent))
from slit_validate_v2 import connect_db

OUT=Path(__file__).resolve().parent/'output'
def months_ago(d,n):
 t=d.year*12+d.month-1-n;y,m=divmod(t,12);m+=1
 return date(y,m,min(d.day,monthrange(y,m)[1]))
def parse(s):return datetime.strptime(s,'%Y-%m-%d').date()
def f(v):
 try:return float(v) if v not in (None,'') else None
 except:return None

def targets():
 files=[OUT/'course34_wall_phase3_development_input_20241001_20250331.csv',OUT/'course34_wall_phase3_development_input_20250401_20250930.csv',OUT/'course34_wall_phase1_races_20251001_20260930.csv']
 out=[]
 for p in files:
  with p.open(encoding='utf8') as h:out+=list(csv.DictReader(h))
 return out

def histories(pids,lo,hi):
 """All records are later sliced per target date; query itself contains no future of `hi`."""
 sql='''WITH cand AS MATERIALIZED (SELECT rm.race_code,rm.race_date,re.player_id,re.lane_number FROM boat_race.race_master rm JOIN boat_race.race_entry re ON re.race_code=rm.race_code WHERE rm.race_date >= %s AND rm.race_date < %s AND re.player_id::text=ANY(%s)),
 rd AS (SELECT DISTINCT ON (d.race_code,d.player_id) d.race_code,d.player_id,d.entry_course,d.rank FROM boat_race.race_result_detail d JOIN cand c ON c.race_code=d.race_code AND c.player_id=d.player_id ORDER BY d.race_code,d.player_id),
 ex AS (SELECT DISTINCT ON (e.race_code,e.player_id) e.race_code,e.player_id,e.entry_course FROM boat_race.exhibition_live e JOIN cand c ON c.race_code=e.race_code AND c.player_id=e.player_id ORDER BY e.race_code,e.player_id,e.created_date DESC NULLS LAST),
 win AS (SELECT DISTINCT ON (d.race_code) d.race_code,d.player_id::text winner_pid,TRIM(COALESCE(d.technique,'')) tech FROM boat_race.race_result_detail d JOIN (SELECT DISTINCT race_code FROM cand) c ON c.race_code=d.race_code WHERE TRIM(d.rank::text)='1' ORDER BY d.race_code)
SELECT c.race_date,c.race_code,c.player_id::text,COALESCE(rd.entry_course,ex.entry_course,c.lane_number) actual_course,rd.rank,win.winner_pid,win.tech FROM cand c JOIN win ON win.race_code=c.race_code LEFT JOIN rd ON rd.race_code=c.race_code AND rd.player_id=c.player_id LEFT JOIN ex ON ex.race_code=c.race_code AND ex.player_id=c.player_id'''
 out=defaultdict(list)
 with connect_db() as c, c.cursor() as cur:
  cur.execute(sql,(lo,hi,sorted(pids)))
  for d,code,pid,course,rank,winner,tech in cur.fetchall():
   if str(course)=='4':out[str(pid)].append((d,str(code),str(winner)==str(pid),str(tech or '').strip(),int(rank) if str(rank).isdigit() else None))
 for x in out.values():x.sort(key=lambda r:(r[0],r[1]))
 return out

def cached_histories():
    out=defaultdict(list)
    for p in sorted((OUT/'course4_attack_phase4_history').glob('*.csv')):
        with p.open(encoding='utf8') as h:
            for r in csv.DictReader(h):out[r['player_id']].append((parse(r['race_date']),r['race_code'],str(r['rank'])=='1',r['technique'],int(r['rank'])))
    for x in out.values():x.sort(key=lambda r:(r[0],r[1]))
    return out

def profile(h,pid,d):
 rows=h.get(pid,[]); a=bisect.bisect_left(rows,months_ago(d,12),key=lambda x:x[0]); z=bisect.bisect_left(rows,d,key=lambda x:x[0]); x=rows[a:z];n=len(x)
 mak=sum(w and t=='まくり' for _,_,w,t,_ in x);mz=sum(w and t=='まくり差し' for _,_,w,t,_ in x);wins=sum(w for _,_,w,t,_ in x);top2=sum((rank or 9)<=2 for *_,rank in x);top3=sum((rank or 9)<=3 for *_,rank in x)
 pc=lambda q:None if not n else 100*q/n
 return {'history_n':n,'history_win_rate_pct':pc(wins),'history_top2_rate_pct':pc(top2),'history_top3_rate_pct':pc(top3),'history_makuri_rate_pct':pc(mak),'history_makurizashi_rate_pct':pc(mz),'history_attack_rate_pct':pc(mak+mz),'source_min_date':x[0][0].isoformat() if x else None,'source_max_date':x[-1][0].isoformat() if x else None,'same_day_or_future_n':sum(q[0]>=d for q in x)}

def main():
 rows=targets()
 if len(sys.argv)==3:
  start,end=parse(sys.argv[1]),parse(sys.argv[2]);rows=[r for r in rows if start<=parse(r['race_date'])<=end]
 else:start,end=min(parse(r['race_date']) for r in rows),max(parse(r['race_date']) for r in rows)
 pids={r['course4_player_id'] for r in rows}; lo=months_ago(min(parse(r['race_date']) for r in rows),12);hi=max(parse(r['race_date']) for r in rows)+__import__('datetime').timedelta(days=1)
 h=cached_histories(); audits=[]
 for i,r in enumerate(rows):
  p=profile(h,r['course4_player_id'],parse(r['race_date']));r.update({f'course4_{k}':v for k,v in p.items()})
  if i in (0,len(rows)//4,len(rows)//2,3*len(rows)//4,len(rows)-1):audits.append({'race_code':r['race_code'],'target_date':r['race_date'],'player_id':r['course4_player_id'],'window_start':months_ago(parse(r['race_date']),12),'window_end_exclusive':r['race_date'],**p})
 OUT.mkdir(exist_ok=True)
 stamp=f'{start:%Y%m%d}_{end:%Y%m%d}'
 for path,data in ((OUT/f'course4_attack_phase4_input_{stamp}.csv',rows),(OUT/f'course4_attack_phase4_profile_spot_checks_{stamp}.csv',audits)):
  with path.open('w',newline='',encoding='utf8') as fh:w=csv.DictWriter(fh,fieldnames=sorted({k for x in data for k in x}));w.writeheader();w.writerows(data)
 print('rows',len(rows),'history players',len(h),'spot checks',audits)
if __name__=='__main__':main()
