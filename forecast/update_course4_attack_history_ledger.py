#!/usr/bin/env python3
"""Separate append-only prospective Canonical Ledger update job."""
from __future__ import annotations
import argparse, hashlib, json, subprocess, sys, time
from datetime import datetime, date
from pathlib import Path
REPO=Path(__file__).resolve().parent.parent; OUT=REPO/'analysis'/'output'; BUILDER=REPO/'analysis'/'build_course4_attack_history_canonical_ledger_phase9_1b.py'; LEDGER=OUT/'course4_attack_history_canonical_ledger_v1.csv'; MANIFEST=OUT/'course4_attack_history_canonical_ledger_v1_manifest.json'; STATE=OUT/'course4_attack_history_canonical_ledger_v1_live_state.json'
def sha(p): return hashlib.sha256(p.read_bytes()).hexdigest()
def main():
 p=argparse.ArgumentParser(); p.add_argument('--date'); p.add_argument('--through-date'); p.add_argument('--json',action='store_true'); a=p.parse_args()
 # Current canonical builder is the single source of append semantics. Date
 # arguments are validated operational metadata; it never backfills baseline.
 for value in (a.date,a.through_date):
  if value: date.fromisoformat(value)
 before=sha(LEDGER); mbefore=sha(MANIFEST); before_rows=sum(1 for _ in LEDGER.open())-1
 run=subprocess.run([sys.executable,str(BUILDER)],cwd=REPO,text=True,capture_output=True,check=True)
 manifest=json.loads(MANIFEST.read_text()); after=sha(LEDGER); mafter=sha(MANIFEST); rows=sum(1 for _ in LEDGER.open())-1
 state={'state_version':'course4-canonical-ledger-live-state/v1','ledger_version':'v1','ledger_path':str(LEDGER.relative_to(REPO)),'ledger_sha256':after,'manifest_path':str(MANIFEST.relative_to(REPO)),'manifest_sha256':mafter,'baseline_source_sha256':manifest['baseline_source_sha256'],'baseline_row_count':manifest['baseline_row_count'],'row_count':rows,'prospective_row_count':rows-manifest['baseline_row_count'],'updated_at':datetime.now().astimezone().isoformat(),'source_script':str(Path(__file__).relative_to(REPO)),'source_script_sha256':sha(Path(__file__)),'source_git_commit':subprocess.check_output(['git','rev-parse','HEAD'],cwd=REPO,text=True).strip()}
 tmp=STATE.with_suffix('.tmp'); tmp.write_text(json.dumps(state,ensure_ascii=False,indent=2),encoding='utf-8'); tmp.replace(STATE)
 result={'ledger_update':'PASS','before_sha256':before,'after_sha256':after,'manifest_before_sha256':mbefore,'manifest_after_sha256':mafter,'before_rows':before_rows,'after_rows':rows,'added_count':manifest['append_run']['added_n'],'duplicate_no_op_count':manifest['append_run']['no_op_duplicate_n'],'baseline_row_count':manifest['baseline_row_count'],'prospective_row_count':state['prospective_row_count'],'state_path':str(STATE.relative_to(REPO)),'state_sha256':sha(STATE),'builder_output':json.loads(run.stdout)}
 print(json.dumps(result,ensure_ascii=False,sort_keys=True))
if __name__=='__main__': main()
