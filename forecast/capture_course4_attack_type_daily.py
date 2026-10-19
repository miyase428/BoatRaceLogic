#!/usr/bin/env python3
"""Manual/cron-ready daily STRICT capture; one race failure never stops batch."""
from __future__ import annotations
import argparse,json,sys
from datetime import datetime
from pathlib import Path
REPO=Path(__file__).resolve().parent.parent; sys.path.insert(0,str(REPO/'forecast'))
from capture_course4_attack_type_snapshot import AttackTypeSnapshotCapture
RETRYABLE={'EXHIBITION_NOT_COMPLETE','ENTRY_MAPPING_INCOMPLETE','MISSING_REQUIRED_FEATURE','EXHIBITION_AFTER_CUTOFF'}
PERMANENT={'NON_WAKUNARI','DEADLINE_PASSED','RESULT_ALREADY_EXISTS','OFFICIAL_DEADLINE_UNAVAILABLE','ARTIFACT_HASH_MISMATCH','INPUT_CONTRACT_VERSION_MISMATCH','LEDGER_HASH_MISMATCH'}
def main():
 p=argparse.ArgumentParser();p.add_argument('--date',default=datetime.now().astimezone().date().isoformat());p.add_argument('--prediction-cutoff');p.add_argument('--json',action='store_true');a=p.parse_args()
 result=AttackTypeSnapshotCapture().capture_date(a.date,a.prediction_cutoff,'STRICT_FORWARD'); counts={k:0 for k in ('strict_existing','strict_created','retryable_unavailable','permanent_unavailable','deadline_passed','result_finalized','errors')}
 for row in result.get('results',[]):
  status=row.get('status'); reason=(row.get('missing_reason') or [''])[0]
  if status=='SAVED': counts['strict_created']+=1
  elif status=='ALREADY_EXISTS': counts['strict_existing']+=1
  elif status=='ERROR': counts['errors']+=1
  elif reason in RETRYABLE: counts['retryable_unavailable']+=1
  else:
   counts['permanent_unavailable']+=1; counts['deadline_passed']+=reason=='DEADLINE_PASSED'; counts['result_finalized']+=reason=='RESULT_ALREADY_EXISTS'
 result['operational_summary']=counts; result['retryable_reasons']=sorted(RETRYABLE); result['permanent_reasons']=sorted(PERMANENT);print(json.dumps(result,ensure_ascii=False,sort_keys=True))
if __name__=='__main__':main()
