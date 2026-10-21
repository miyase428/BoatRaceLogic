#!/usr/bin/env python3
"""Controlled, outcome-free tests for the dedicated Attack Type snapshot store."""
from __future__ import annotations
import json, sys, tempfile
from pathlib import Path
ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "forecast"))
from capture_course4_attack_type_snapshot import AttackTypeSnapshotCapture, ForwardSnapshotStore, Guard, STAGE

FEATURES = ["course4_history_n", "course4_history_makuri_rate_pct", "course4_history_makurizashi_rate_pct", "course4_ex_st", "course4_exhibition_time", "course4_lap_time", "course4_around_time", "course4_straight_time"]

class FakeCore:
    logic_version = "fixture-logic"
    artifact = {"feature_order": FEATURES}
    load_error = None
    def __init__(self, available=True, reason="EXHIBITION_NOT_COMPLETE"): self.available, self.reason = available, reason
    def predict(self, race_code, cutoff):
        base={"race_code":race_code,"prediction_cutoff":cutoff,"stage":STAGE,"logic_version":self.logic_version,"artifact_sha256":"a"*64,"spec_sha256":"b"*64,"ledger_sha256":"c"*64,"ledger_manifest_sha256":"d"*64,"input_contract_version":"fixture-contract","input_contract_sha256":"e"*64,"generated_at":"2099-01-01T10:00:00+09:00"}
        if not self.available: return base | {"prediction_available":False,"missing_reason":[self.reason],"blocked_reason":[self.reason]}
        return base | {"prediction_available":True,"missing_reason":[],"blocked_reason":[],"input_sha256":"f"*64,"input_feature_vector":[1,2,3,.1,6.8,37,5.8,7.1],"p_no_attack_success":.9,"p_makuri_success":.06,"p_makurizashi_success":.04}

class FakeGuard:
    def __init__(self, deadline="2099-01-01T23:59:00+09:00", result=False): self._deadline, self._result = deadline, result
    def deadline(self, _race):
        from datetime import datetime
        return datetime.fromisoformat(self._deadline) if self._deadline else None
    def result_exists(self, _race): return self._result

def cap(root, core=None, guard=None): return AttackTypeSnapshotCapture(core or FakeCore(), ForwardSnapshotStore(root), guard or FakeGuard())
def assert_ok(x, text):
    if not x: raise AssertionError(text)

def main():
    checks={}; race="20990101TST01"; cutoff="2099-01-01T10:00:00+09:00"
    with tempfile.TemporaryDirectory() as d:
        root=Path(d); c=cap(root)
        first=c.capture(race,cutoff); checks["valid_strict_first_write"]=first["status"]=="SAVED"
        saved=json.loads(Path(first["path"]).read_text()); checks["readback_exact"]=saved["snapshot_hash"]==first["snapshot_hash"] and saved["prediction_available"]
        checks["identity_and_raw_features_saved"]=(saved["feature_order"]==FEATURES and len(saved["input_feature_vector"])==8 and saved["input_sha256"]=="f"*64 and saved["missing_feature_mask"]==[False]*8)
        again=c.capture(race,cutoff); checks["strict_first_write_immutable"]=again["status"]=="ALREADY_EXISTS"
        late=c.capture(race, "2099-01-02T10:00:00+09:00", "LATE_REPLAY"); checks["late_replay_separate_namespace"]=late["status"]=="SAVED" and "/late_replay/" in late["path"]
        after=cap(root,guard=FakeGuard()).capture("20990101TST02","2099-01-02T00:00:00+09:00"); checks["deadline_guard"]=after["missing_reason"]==["DEADLINE_PASSED"]
        result=cap(root,guard=FakeGuard(result=True)).capture("20990101TST03",cutoff); checks["result_guard"]=result["missing_reason"]==["RESULT_ALREADY_EXISTS"]
        no_deadline=cap(root,guard=FakeGuard(deadline=None)).capture("20990101TST04",cutoff); checks["deadline_unknown_fail_closed"]=no_deadline["missing_reason"]==["OFFICIAL_DEADLINE_UNAVAILABLE"]
        wakunari=cap(root,FakeCore(False,"NON_WAKUNARI")).capture("20990101TST05",cutoff); checks["non_wakunari_availability_record"]=wakunari["status"]=="UNAVAILABLE" and wakunari["missing_reason"]==["NON_WAKUNARI"]
        exhibition=cap(root,FakeCore(False,"EXHIBITION_NOT_COMPLETE")).capture("20990101TST06",cutoff); checks["missing_exhibition_availability_record"]=exhibition["status"]=="UNAVAILABLE"
        broken=FakeCore(); broken.logic_version=None; broken.load_error="ARTIFACT_HASH_MISMATCH"; artifact=cap(root,broken).capture("20990101TST07",cutoff); checks["artifact_hash_mismatch_blocks_save"]=artifact["status"]=="BLOCKED"
        broken2=FakeCore(); broken2.logic_version=None; broken2.load_error="INPUT_CONTRACT_VERSION_MISMATCH"; contract=cap(root,broken2).capture("20990101TST08",cutoff); checks["contract_hash_mismatch_blocks_save"]=contract["status"]=="BLOCKED"
        attempt_files=list((root/"attempts").glob("*.json"))
        checks["attempts_recorded"]=len(attempt_files) >= 6 and all(json.loads(path.read_text())["attempt_number"] >= 1 for path in attempt_files)
    assert_ok(all(checks.values()), json.dumps(checks)); print(json.dumps({"pass":True,"checks":checks},sort_keys=True))
if __name__ == "__main__": main()
