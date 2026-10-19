#!/usr/bin/env python3
"""Phase 6A-1a-I: read-only 5C/6C pre-race enrichment builder.

Usage: python3 analysis/build_course4_attack_success_phase6_boat56_enrichment.py YYYY-MM
It writes one month only; a later Phase may concatenate validated months.
"""
import csv,sys,time
from datetime import date
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parent))
from slit_validate_v2 import connect_db
OUT=Path(__file__).resolve().parent/'output'; CACHE=OUT/'course4_attack_success_phase6_boat56_enrichment'
def month(s):
 y,m=map(int,s.split('-'));return date(y,m,1),date(y+1,1,1) if m==12 else date(y,m+1,1)
def term(d):return f'{(d.year-1)%100:02d}10' if d.month<=4 else (f'{d.year%100:02d}04' if d.month<=10 else f'{d.year%100:02d}10')
def main():
 if len(sys.argv)!=2:raise SystemExit('Usage: YYYY-MM')
 lo,hi=month(sys.argv[1]);CACHE.mkdir(exist_ok=True);path=CACHE/f'{lo:%Y%m}.csv';t=time.monotonic()
 # actual-course is resolved exactly as Phase 1: result detail, then latest
 # exhibition, then lane.  The Phase-6 consumer will intersect this with its
 # already-fixed valid/frame-order race universe; no result fields are selected.
 sql='''WITH target AS MATERIALIZED (SELECT rm.race_code,rm.race_date FROM boat_race.race_master rm WHERE rm.race_date>=%s AND rm.race_date<%s),
 ex AS (SELECT DISTINCT ON (race_code,player_id) race_code,player_id,entry_course FROM boat_race.exhibition_live ORDER BY race_code,player_id,created_date DESC NULLS LAST),
 rd AS (SELECT DISTINCT ON (race_code,player_id) race_code,player_id,entry_course FROM boat_race.race_result_detail ORDER BY race_code,player_id)
 SELECT t.race_code,t.race_date,c.course,re.player_id::text,ps.national_win_rate,ps.local_win_rate,ps.national_exacta_rate,ps.local_exacta_rate,rr.course_average_st,es.motor_exacta_rate,es.boat_exacta_rate
 FROM target t JOIN race_entry re ON re.race_code=t.race_code LEFT JOIN rd ON rd.race_code=re.race_code AND rd.player_id=re.player_id LEFT JOIN ex ON ex.race_code=re.race_code AND ex.player_id=re.player_id
 JOIN LATERAL (SELECT COALESCE(rd.entry_course,ex.entry_course,re.lane_number)::int course) c ON c.course IN (5,6)
 LEFT JOIN player_stats ps ON ps.race_code=re.race_code AND ps.player_id=re.player_id
 LEFT JOIN engine_specs es ON es.race_code=re.race_code AND es.motor_number=re.motor_number
 LEFT JOIN LATERAL (SELECT CASE c.course WHEN 5 THEN r.course5_average_start WHEN 6 THEN r.course6_average_start END course_average_st FROM racer_results r WHERE r.player_id=re.player_id AND r.term_info::text=%s LIMIT 1) rr ON TRUE
 ORDER BY t.race_code,c.course'''
 rows={};q=time.monotonic()
 with connect_db() as con,con.cursor() as cur:
  # term is per race month; execute month separately so one term is valid.
  cur.execute(sql,(lo,hi,term(lo)))
  for x in cur.fetchall():
   code,d,c,*v=x;rows.setdefault(str(code),{'race_code':str(code),'race_date':d.isoformat()});p='course'+str(c)+'_';keys=('player_id','national_win_rate','local_win_rate','national_2ren_rate','local_2ren_rate','average_st','motor_2ren_rate','boat_2ren_rate');rows[str(code)].update({p+k:v[i] for i,k in enumerate(keys)})
 qt=time.monotonic()-q;data=list(rows.values());fields=['race_code','race_date']+[f'course{c}_{k}' for c in (5,6) for k in ('player_id','national_win_rate','local_win_rate','national_2ren_rate','local_2ren_rate','average_st','motor_2ren_rate','boat_2ren_rate')]
 with path.open('w',newline='',encoding='utf8') as h:w=csv.DictWriter(h,fieldnames=fields);w.writeheader();w.writerows(data)
 dup=len(data)-len({r['race_code'] for r in data});print({'path':str(path),'rows':len(data),'duplicate_race_code':dup,'db_query_seconds':qt,'merge_seconds':0.0,'total_seconds':time.monotonic()-t,'coverage':{k:sum(r.get(k) not in (None,'') for r in data) for k in fields[2:]}})
if __name__=='__main__':main()
