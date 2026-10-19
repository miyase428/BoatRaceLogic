#!/usr/bin/env python3
"""Monthly, resumable, read-only history cache for Phase 4 Attack profiles.

Usage: python3 analysis/build_course4_attack_history_phase4.py YYYY-MM
Only result-valid actual-4C rows for Phase-4 target 4C players are cached.
"""
from __future__ import annotations
import csv,sys,time
from datetime import date
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parent))
from slit_validate_v2 import connect_db
OUT=Path(__file__).resolve().parent/'output'; CACHE=OUT/'course4_attack_phase4_history'

def month(s):
 y,m=map(int,s.split('-'));return date(y,m,1),date(y+1,1,1) if m==12 else date(y,m+1,1)
def target_players():
 paths=[OUT/'course34_wall_phase3_development_input_20241001_20250331.csv',OUT/'course34_wall_phase3_development_input_20250401_20250930.csv',OUT/'course34_wall_phase1_races_20251001_20260930.csv']
 return sorted({r['course4_player_id'] for p in paths for r in csv.DictReader(p.open(encoding='utf8'))})
def main():
 if len(sys.argv)!=2:raise SystemExit('Usage: YYYY-MM')
 start,end=month(sys.argv[1]);path=CACHE/f'{start:%Y%m}.csv';CACHE.mkdir(parents=True,exist_ok=True)
 if path.exists():print('cache hit',path);return
 sql='''SELECT rm.race_date,re.race_code,re.player_id::text,
 COALESCE(rrd.entry_course,el.entry_course,re.lane_number) actual_course,
 rrd.rank,TRIM(COALESCE(rrd.technique,'')) technique
 FROM boat_race.race_master rm JOIN boat_race.race_entry re ON re.race_code=rm.race_code
 JOIN boat_race.race_result_detail rrd ON rrd.race_code=re.race_code AND rrd.player_id=re.player_id
 LEFT JOIN LATERAL (SELECT e.entry_course FROM boat_race.exhibition_live e WHERE e.race_code=re.race_code AND e.player_id=re.player_id ORDER BY e.created_date DESC NULLS LAST,e.entry_course LIMIT 1) el ON TRUE
 WHERE rm.race_date >= %s AND rm.race_date < %s AND re.player_id::text=ANY(%s)
 AND COALESCE(rrd.entry_course,el.entry_course,re.lane_number)=4 AND rrd.rank::text~'^[1-6]$'
 ORDER BY rm.race_date,re.race_code,re.player_id'''
 t=time.monotonic()
 with connect_db() as c,c.cursor() as cur:
  cur.execute(sql,(start,end,target_players())); rows=cur.fetchall()
 with path.open('w',newline='',encoding='utf8') as h:
  w=csv.writer(h);w.writerow(['race_date','race_code','player_id','actual_course','rank','technique']);w.writerows(rows)
 print(f'{start:%Y-%m} rows={len(rows)} seconds={time.monotonic()-t:.3f} cache={path}')
if __name__=='__main__':main()
