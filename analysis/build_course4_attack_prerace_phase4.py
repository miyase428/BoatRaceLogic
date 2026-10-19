#!/usr/bin/env python3
"""Monthly read-only pre-race feature cache for Phase 4 A2/A4/A5."""
from __future__ import annotations
import csv,sys,time
from datetime import date
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parent))
from slit_validate_v2 import connect_db
OUT=Path(__file__).resolve().parent/'output';INP=OUT/'course4_attack_phase4_input_20241001_20260930.csv';CACHE=OUT/'course4_attack_phase4_prerace'
def month(s):
 y,m=map(int,s.split('-'));return date(y,m,1),date(y+1,1,1) if m==12 else date(y,m+1,1)
def term(d):return f'{(d.year-1)%100:02d}10' if d.month<=4 else (f'{d.year%100:02d}04' if d.month<=10 else f'{d.year%100:02d}10')
def main():
 if len(sys.argv)!=2:raise SystemExit('Usage YYYY-MM')
 lo,hi=month(sys.argv[1]);CACHE.mkdir(exist_ok=True);path=CACHE/f'{lo:%Y%m}.csv'
 if path.exists():print('cache hit',path);return
 targets=[r for r in csv.DictReader(INP.open(encoding='utf8')) if lo<=date.fromisoformat(r['race_date'])<hi];keys={(r['race_code'],r['course4_player_id']) for r in targets};pids=sorted({x[1] for x in keys});terms=sorted({term(date.fromisoformat(r['race_date'])) for r in targets})
 sql='''SELECT re.race_code,re.player_id::text,re.motor_number,ps.national_win_rate,ps.local_win_rate,ps.national_exacta_rate,ps.local_exacta_rate,es.motor_exacta_rate,es.boat_exacta_rate FROM boat_race.race_entry re JOIN boat_race.race_master rm ON rm.race_code=re.race_code LEFT JOIN boat_race.player_stats ps ON ps.race_code=re.race_code AND ps.player_id=re.player_id LEFT JOIN boat_race.engine_specs es ON es.race_code=re.race_code AND es.motor_number=re.motor_number WHERE rm.race_date >= %s AND rm.race_date < %s AND re.player_id::text=ANY(%s)'''
 t=time.monotonic();out={}
 with connect_db() as c,c.cursor() as cur:
  cur.execute(sql,(lo,hi,pids))
  for x in cur.fetchall():
   if (str(x[0]),str(x[1])) in keys:out[(str(x[0]),str(x[1]))]=x[2:]
  cur.execute('SELECT term_info::text,player_id::text,average_start FROM boat_race.racer_results WHERE term_info::text=ANY(%s) AND player_id::text=ANY(%s)',(terms,pids));overall={(str(a),str(b)):c for a,b,c in cur.fetchall()}
 rows=[]
 for r in targets:
  x=out.get((r['race_code'],r['course4_player_id']),(None,)*7);rows.append({'race_code':r['race_code'],'player_id':r['course4_player_id'],'overall_average_st':overall.get((term(date.fromisoformat(r['race_date'])),r['course4_player_id'])),'motor_2ren_rate':x[5],'boat_2ren_rate':x[6],'national_win_rate':x[1],'local_win_rate':x[2],'national_2ren_rate':x[3],'local_2ren_rate':x[4]})
 with path.open('w',newline='',encoding='utf8') as h:w=csv.DictWriter(h,fieldnames=rows[0].keys());w.writeheader();w.writerows(rows)
 print(lo,len(rows),time.monotonic()-t)
if __name__=='__main__':main()
