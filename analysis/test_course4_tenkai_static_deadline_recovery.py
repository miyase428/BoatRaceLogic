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

def main():
  with tempfile.TemporaryDirectory() as td:
    root=Path(td); dl=root/'deadlines'; dl.mkdir(); calls=[]; cap=Capture()
    def prepare(prefix):
      calls.append(prefix)
      (dl/f'deadlines_{prefix}.json').write_text(json.dumps({'deadlines':{f'{prefix}AMG01':'10:00'}}),encoding='utf-8')
      return {'ok':True,'status':'READY'}
    b=CampaignBatch(capture=cap, guard=Guard(dl), campaign_path=root/'campaign.json', snapshot_root=root/'snapshots', deadline_preparer=prepare)
    one=b.run('2099-01-01','2099-01-01T09:00:00+09:00'); two=b.run('2099-01-01','2099-01-01T09:01:00+09:00')
    check(calls==['20990101'],'missing cache prepared once'); check(len(cap.calls)==2,'batch uses cache after preparation'); check(one.get('status') is None and two.get('status') is None,'prepared cache continues batch')
  with tempfile.TemporaryDirectory() as td:
    cap=Capture(); b=CampaignBatch(capture=cap,guard=Guard(td),campaign_path=Path(td)/'c',snapshot_root=Path(td)/'s',deadline_preparer=lambda _: {'ok':False,'status':'OFFICIAL_DEADLINE_UNAVAILABLE'})
    out=b.run('2099-01-01','2099-01-01T09:00:00+09:00'); check(out['status']=='OFFICIAL_DEADLINE_UNAVAILABLE' and cap.calls==[],'unavailable fails closed before capture')
  print('PASS 5 static deadline recovery cases')
if __name__=='__main__': main()
