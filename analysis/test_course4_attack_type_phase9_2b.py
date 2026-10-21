#!/usr/bin/env python3
"""Pre-cron temporal and state-transition fixtures for Phase 9-2b."""
from datetime import date,datetime,timezone,timedelta
import sys
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent;sys.path.insert(0,str(ROOT/'analysis'));sys.path.insert(0,str(ROOT/'forecast'))
from build_course4_attack_history_canonical_ledger_phase9_1b import build_ledger_index,history_features_from_canonical_ledger
from test_course4_attack_type_snapshot_phase9_2 import FakeCore,FakeGuard,cap
def row(code,day,pid): return {'event_id':code+':'+pid,'race_code':code,'race_date':day,'player_id':pid,'is_history_eligible':'1','baseline_frozen':'0','first_observed_at':'2099-01-01T00:00:00+09:00','is_makuri':'1','is_makurizashi':'0'}
def main():
 d=date(2099,1,2); cut=datetime(2099,1,2,10,tzinfo=timezone(timedelta(hours=9))); same=build_ledger_index([row('A','2099-01-02','1')]); empty=build_ledger_index([])
 a=history_features_from_canonical_ledger(same,'1',d,cut,research_reproduction=False,ledger_activation_at=cut);b=history_features_from_canonical_ledger(empty,'1',d,cut,research_reproduction=False,ledger_activation_at=cut)
 prev=history_features_from_canonical_ledger(build_ledger_index([row('A','2099-01-02','1')]),'1',date(2099,1,3),cut,research_reproduction=False,ledger_activation_at=cut)
 import tempfile
 with tempfile.TemporaryDirectory() as tmp:
  c=cap(Path(tmp)); first=c.capture('20990101TST01','2099-01-01T10:00:00+09:00'); again=c.capture('20990101TST01','2099-01-01T10:01:00+09:00')
  retry=cap(Path(tmp),FakeCore(False,'ENTRY_MAPPING_INCOMPLETE')).capture('20990101TST02','2099-01-01T10:00:00+09:00'); recovered=cap(Path(tmp)).capture('20990101TST02','2099-01-01T10:01:00+09:00'); no=cap(Path(tmp)).capture('20990101TST02','2099-01-01T10:02:00+09:00')
  deadline=cap(Path(tmp),FakeCore(),FakeGuard('2099-01-01T09:59:00+09:00')).capture('20990101TST03','2099-01-01T10:00:00+09:00'); result=cap(Path(tmp),FakeCore(),FakeGuard(result=True)).capture('20990101TST04','2099-01-01T10:00:00+09:00')
 checks={'same_day_history_exclusion':a==b and a['course4_history_n']==0,'previous_day_history_use':prev['course4_history_n']==1,'capture_double_run':first['status']=='SAVED' and again['status']=='ALREADY_EXISTS','retryable_recovery':retry['status']=='UNAVAILABLE' and recovered['status']=='SAVED' and no['status']=='ALREADY_EXISTS','deadline_transition':deadline['missing_reason']==['DEADLINE_PASSED'],'result_transition':result['missing_reason']==['RESULT_ALREADY_EXISTS']}
 if not all(checks.values()): raise SystemExit(str(checks))
 import json;print(json.dumps({'pass':True,'checks':checks},sort_keys=True))
if __name__=='__main__':main()
