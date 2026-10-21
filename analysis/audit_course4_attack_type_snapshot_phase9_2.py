#!/usr/bin/env python3
"""Phase 9-2 audit; no outcome evaluation and no snapshot rewriting."""
from __future__ import annotations
import hashlib, json, subprocess, sys, time
from datetime import datetime
from pathlib import Path
REPO=Path(__file__).resolve().parent.parent; OUT=REPO/'analysis'/'output'; STORE=REPO/'var'/'course4_attack_type_forward_snapshots'
sys.path.insert(0,str(REPO/'forecast'))
from capture_course4_attack_type_snapshot import ROOT, STORE_VERSION
CORE=REPO/'forecast'/'course4_attack_type_live.py'; CAPTURE=REPO/'forecast'/'capture_course4_attack_type_snapshot.py'; TEST=REPO/'analysis'/'test_course4_attack_type_snapshot_phase9_2.py'; EXISTING=REPO/'web'/'logic'/'PredictionForwardSnapshotStore.php'; REPORT=OUT/'course4_tenkai_phase9_2_report.json'
def sha(path): return hashlib.sha256(path.read_bytes()).hexdigest()
def git(): return subprocess.check_output(['git','rev-parse','HEAD'],cwd=REPO,text=True).strip()
def main():
    started=time.perf_counter()
    controlled=json.loads(subprocess.check_output([sys.executable,str(TEST)],cwd=REPO,text=True))
    existing_lint=subprocess.run(['php','-l',str(EXISTING)],cwd=REPO,text=True,capture_output=True,check=True).stdout.strip()
    existing_test=json.loads(subprocess.check_output(['php','analysis/test_strict_forward_accepted_race.php'],cwd=REPO,text=True))
    attempts=[]
    for path in sorted((STORE/'attempts').glob('*.jsonl')):
        attempts += [json.loads(line) for line in path.read_text(encoding='utf-8').splitlines() if line.strip()]
    strict=list((STORE/'strict_forward').glob('*.json')); late=list((STORE/'late_replay').glob('*.json'))
    source=CAPTURE.read_text(encoding='utf-8'); core=CORE.read_text(encoding='utf-8')
    checks={
      'first_write_immutable':controlled['checks']['strict_first_write_immutable'],
      'deadline_guard':controlled['checks']['deadline_guard'] and controlled['checks']['deadline_unknown_fail_closed'],
      'result_guard':controlled['checks']['result_guard'],
      'strict_late_separation':controlled['checks']['late_replay_separate_namespace'],
      'unavailable_coverage':controlled['checks']['non_wakunari_availability_record'] and controlled['checks']['missing_exhibition_availability_record'] and controlled['checks']['attempts_recorded'],
      'identity_fail_closed':controlled['checks']['artifact_hash_mismatch_blocks_save'] and controlled['checks']['contract_hash_mismatch_blocks_save'],
      'identity_and_raw_features_saved':controlled['checks']['identity_and_raw_features_saved'],
      'readback_exact':controlled['checks']['readback_exact'],
      'existing_snapshot_regression':existing_test.get('status')=='PASS',
      'shared_core_only':'AttackTypeLiveInferenceCore' in source and 'softmax' not in source and 'feature_vector' in source,
      'result_guard_outside_core':'race_result_detail' not in core and 'race_result_detail' in source,
    }
    report={'phase':'9-2','attack_type_strict_snapshot':'READY' if all(checks.values()) else 'BLOCKED','generated_at':datetime.now().astimezone().isoformat(),'source_git_commit':git(),
      'storage':{'design':'dedicated append-only file store; existing prediction_forward_snapshots DB schema is untouched','root':str(ROOT.relative_to(REPO)),'store_version':STORE_VERSION,'strict_namespace':'strict_forward','late_namespace':'late_replay','attempt_namespace':'attempts','strict_snapshot_n':len(strict),'late_replay_snapshot_n':len(late),'first_eligible_strict_forward_timestamp':None if not strict else min(json.loads(p.read_text())['capture_generated_at'] for p in strict)},
      'guard_contract':{'strict_requires':['official_deadline_available','prediction_cutoff_before_deadline','no race_result_detail row','LIVE prediction_available','identity complete','first write absent'],'deadline_source':'/tmp/boatrace_official_deadlines/deadlines_YYYYMMDD.json','result_guard_boundary':'snapshot orchestration only; inference core is result-table-free'},
      'controlled_tests':controlled,'existing_snapshot_regression':{'php_lint':existing_lint,'test':existing_test},'actual_capture_attempts':attempts,
      'sources':{str(CORE.relative_to(REPO)):sha(CORE),str(CAPTURE.relative_to(REPO)):sha(CAPTURE),str(TEST.relative_to(REPO)):sha(TEST),str(EXISTING.relative_to(REPO)):sha(EXISTING)},'ready_checks':checks,'timing_seconds':time.perf_counter()-started}
    REPORT.write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8')
    reread=json.loads(REPORT.read_text());
    if reread['attack_type_strict_snapshot']!=report['attack_type_strict_snapshot']: raise RuntimeError('report_reload_mismatch')
    print(json.dumps({'ready':report['attack_type_strict_snapshot'],'checks':checks,'strict_snapshot_n':len(strict),'attempt_n':len(attempts),'report':str(REPORT)},ensure_ascii=False,indent=2))
if __name__=='__main__': main()
