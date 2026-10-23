#!/usr/bin/env python3
"""Isolated tests for deadline-cache recovery; no DB, network, or repo output writes."""
import json, sys, tempfile
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / 'forecast'))
from capture_course4_tenkai_static_input_daily import DailyStaticSnapshotBatch
class Guard:
    def __init__(self, path): self.deadline_dir=Path(path)
class Capture:
    def __init__(self): self.calls=[]
    def capture(self, code, cutoff): self.calls.append((code,cutoff)); return {'status':'SAVED','race_code':code,'missing_reason':[]}
class CampaignBatch(DailyStaticSnapshotBatch):
    def _campaign(self, results): return {'summary':results}
def check(v,n):
    if not v: raise AssertionError(n)
def prep(dl, prefix, codes, status='READY'):
    (dl/f'deadlines_{prefix}.json').write_text(json.dumps({'deadlines':{code:'23:00' for code in codes}}),encoding='utf-8')
    return {'ok':status in ('READY','PARTIAL_READY'), 'status':status, 'ready_race_codes':codes}
def main():
  with tempfile.TemporaryDirectory() as td:
    root=Path(td); dl=root/'deadlines'; dl.mkdir(); cap=Capture(); calls=[]
    def prepare(prefix): calls.append(prefix); return prep(dl,prefix,[f'{prefix}AMG01'])
    b=CampaignBatch(capture=cap,guard=Guard(dl),campaign_path=root/'c',snapshot_root=root/'s',deadline_preparer=prepare)
    one=b.run('2099-01-01','2099-01-01T09:00:00+09:00'); two=b.run('2099-01-01','2099-01-01T09:01:00+09:00')
    check(calls==['20990101','20990101'],'each cron validates without assuming new HTTP'); check(len(cap.calls)==2 and one['status']=='OK','complete cache capture')
  with tempfile.TemporaryDirectory() as td:
    dl=Path(td); cap=Capture()
    def partial(prefix): return prep(dl,prefix,[f'{prefix}AMG01',f'{prefix}AMG02'],'PARTIAL_READY')
    out=CampaignBatch(capture=cap,guard=Guard(dl),campaign_path=dl/'c',snapshot_root=dl/'s',deadline_preparer=partial).run('2099-01-01','2099-01-01T09:00:00+09:00')
    check(out['status']=='OFFICIAL_DEADLINE_PARTIAL' and [x[0] for x in cap.calls]==['20990101AMG01','20990101AMG02'],'partial cache only captures verified venues')
  with tempfile.TemporaryDirectory() as td:
    cap=Capture(); b=CampaignBatch(capture=cap,guard=Guard(td),campaign_path=Path(td)/'c',snapshot_root=Path(td)/'s',deadline_preparer=lambda _: {'ok':False,'status':'RETRY_WAIT','ready_race_codes':[]})
    out=b.run('2099-01-01','2099-01-01T09:00:00+09:00'); check(out['status']=='RETRY_WAIT' and cap.calls==[],'retry wait does not capture')
  print('PASS 5 static deadline recovery cases')
if __name__=='__main__': main()
