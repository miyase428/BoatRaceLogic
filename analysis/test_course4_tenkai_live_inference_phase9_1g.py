#!/usr/bin/env python3
"""Phase 9-1g safety tests and complete canonical research-replay audit."""
from __future__ import annotations

import copy
import csv
import hashlib
import json
import math
import shutil
import sys
import tempfile
from datetime import datetime
from pathlib import Path

import numpy as np

REPO = Path(__file__).resolve().parent.parent
OUT = REPO / "analysis" / "output"
sys.path.insert(0, str(REPO / "forecast"))
from course4_tenkai_binary_live import sha256
from course34_wall_live import ARTIFACT as WALL_ARTIFACT, FEATURE_ORDER as WALL_FEATURES, WallRLiveInferenceCore
from course4_attack_a5_live import ARTIFACT as A5_ARTIFACT, FEATURE_ORDER as A5_FEATURES, AttackA5LiveInferenceCore
from course4_attack_success_live import ARTIFACT as SUCCESS_ARTIFACT, AttackSuccessLiveInferenceCore

ROUNDTRIP = OUT / "course4_tenkai_phase9_1g_roundtrip_report.json"
REPORT = OUT / "course4_tenkai_phase9_1g_report.json"


class FakeProvider:
    def __init__(self, artifact_sha, features, response=None):
        self.artifact_sha, self.features, self.response = artifact_sha, list(features), response
    def build(self, race_code, cutoff, mode):
        if self.response is not None: return copy.deepcopy(self.response)
        return {"feature_available": True, "feature_order": self.features, "feature_vector": [float(i + 1) for i in range(len(self.features))],
                "input_sha256": "fixture-input-sha", "artifact_identity": {"sha256": self.artifact_sha}, "missing_reason": []}


class FakeUpstream:
    def __init__(self, artifact_sha, probability_key, probability=None, blocked=None):
        self.artifact_sha, self.probability_key, self.probability, self.blocked = artifact_sha, probability_key, probability, blocked
        self.logic_version = "fixture-logic-" + probability_key
    def _value(self):
        if self.blocked: return {"prediction_available": False, "missing_reason": [self.blocked], "artifact_sha256": self.artifact_sha}
        return {"prediction_available": True, "artifact_sha256": self.artifact_sha, self.probability_key: self.probability,
                "input_sha256": "fixture-" + self.probability_key, "logic_version": self.logic_version}
    def predict(self, *_): return self._value()
    def predict_research_replay(self, *_): return self._value()


def strip_generated(value):
    value = copy.deepcopy(value); value.pop("generated_at", None); return value


def expected_binary(artifact, raw):
    vector = np.asarray(raw, dtype=float)
    median, mean, std = (np.asarray(artifact[key], dtype=float) for key in ("missing_fill_values", "scaler_mean", "scaler_std"))
    vector = np.where(np.isnan(vector), median, vector)
    logit = float(artifact["intercept"]) + float(np.dot((vector - mean) / std, np.asarray(artifact["coefficients"], dtype=float)))
    low, high = artifact["logit_clipping"]; return 1.0 / (1.0 + math.exp(-max(float(low), min(float(high), logit))))


def score(artifact, probability):
    import bisect
    return max(0, min(100, bisect.bisect_right(artifact["score_percentile_boundaries"], float(probability)) - 1))


def diff_summary(expected, actual):
    expected, actual = np.asarray(expected, dtype=float), np.asarray(actual, dtype=float)
    delta = np.abs(expected - actual)
    return {"n": int(len(delta)), "max_abs_diff": float(delta.max()), "mean_abs_diff": float(delta.mean()),
            "mismatch_count": int(np.sum(delta > 1e-12)), "exact_match_count": int(np.sum(delta == 0.0)),
            "within_1e12_count": int(np.sum(delta <= 1e-12))}


def replay_all():
    wall, a5 = WallRLiveInferenceCore(), AttackA5LiveInferenceCore()
    # Reuse the exact two core instances: the meta core must consume their
    # outputs, and this avoids constructing a second in-memory history ledger.
    success = AttackSuccessLiveInferenceCore(wall_core=wall, attack_core=a5)
    artifacts = [json.loads(path.read_text(encoding="utf-8")) for path in (WALL_ARTIFACT, A5_ARTIFACT, SUCCESS_ARTIFACT)]
    rows = []
    wall_expected, wall_actual, a5_expected, a5_actual, success_expected, success_actual = [], [], [], [], [], []
    wall_scores_expected, wall_scores_actual, a5_scores_expected, a5_scores_actual, success_scores_expected, success_scores_actual = [], [], [], [], [], []
    for race_code, baseline in wall._provider().baseline.items():
        cutoff = baseline["race_date"] + "T12:00:00+09:00"
        w_source = wall._provider().build(race_code, cutoff, "RESEARCH_REPLAY")
        a_source = a5._provider().build(race_code, cutoff, "RESEARCH_REPLAY")
        w = wall.predict_research_replay(race_code, cutoff); a = a5.predict_research_replay(race_code, cutoff); s = success.predict_research_replay(race_code, cutoff)
        if not (w["prediction_available"] and a["prediction_available"] and s["prediction_available"]):
            raise RuntimeError("unexpected research replay failure: " + race_code)
        ew, ea = expected_binary(artifacts[0], w_source["feature_vector"]), expected_binary(artifacts[1], a_source["feature_vector"])
        es = expected_binary(artifacts[2], [ew, ea])
        wall_expected.append(ew); wall_actual.append(w["wall_probability"]); a5_expected.append(ea); a5_actual.append(a["attack_probability"]); success_expected.append(es); success_actual.append(s["attack_success_probability"])
        wall_scores_expected.append(score(artifacts[0], ew)); wall_scores_actual.append(w["wall_score"]); a5_scores_expected.append(score(artifacts[1], ea)); a5_scores_actual.append(a["attack_score"]); success_scores_expected.append(score(artifacts[2], es)); success_scores_actual.append(s["attack_success_score"])
        rows.append((baseline["race_date"], race_code, w, a, s))
    def splits(index, expected, actual, expected_score, actual_score):
        out = {}
        for label, predicate in (("Development", lambda d: d <= "2025-09-30"), ("Validation", lambda d: "2025-10-01" <= d <= "2026-03-31"), ("EXPOSED_CONFIRMATION", lambda d: d >= "2026-04-01")):
            ix = [i for i, row in enumerate(rows) if predicate(row[0])]
            out[label] = {"probability": diff_summary([expected[i] for i in ix], [actual[i] for i in ix]),
                          "score_mismatch_count": int(sum(expected_score[i] != actual_score[i] for i in ix)), "n": len(ix)}
        out["overall"] = {"probability": diff_summary(expected, actual), "score_mismatch_count": int(sum(x != y for x, y in zip(expected_score, actual_score))), "n": len(expected)}
        return out
    saved = {row["race_code"]: row for row in csv.DictReader((OUT / "course34_wall_phase3r_exposed_confirmation_scores.csv").open(encoding="utf-8"))}
    saved_expected, saved_actual, saved_scores_expected, saved_scores_actual = [], [], [], []
    for date, code, w, _, _ in rows:
        if date >= "2026-04-01":
            row = saved[code]; saved_expected.append(float(row["wall_r_probability"])); saved_actual.append(w["wall_probability"]); saved_scores_expected.append(int(row["wall_r_score_0_100"])); saved_scores_actual.append(w["wall_score"])
    return {"rows": len(rows), "artifacts": {"wall": sha256(WALL_ARTIFACT), "a5": sha256(A5_ARTIFACT), "success": sha256(SUCCESS_ARTIFACT)},
            "wall_r": splits(2, wall_expected, wall_actual, wall_scores_expected, wall_scores_actual),
            "attack_a5": splits(3, a5_expected, a5_actual, a5_scores_expected, a5_scores_actual),
            "attack_success": splits(4, success_expected, success_actual, success_scores_expected, success_scores_actual),
            "wall_saved_confirmation": {"probability": diff_summary(saved_expected, saved_actual), "score_mismatch_count": int(sum(x != y for x, y in zip(saved_scores_expected, saved_scores_actual))), "n": len(saved_expected)}}


def checks():
    output = {}
    wall = WallRLiveInferenceCore(provider=FakeProvider(sha256(WALL_ARTIFACT), WALL_FEATURES))
    a5 = AttackA5LiveInferenceCore(provider=FakeProvider(sha256(A5_ARTIFACT), A5_FEATURES))
    output["valid_wall"] = wall.predict("fixture", "2026-10-08T12:00:00+09:00")["prediction_available"]
    output["valid_a5"] = a5.predict("fixture", "2026-10-08T12:00:00+09:00")["prediction_available"]
    one, two = wall.predict("fixture", "2026-10-08T12:00:00+09:00"), wall.predict("fixture", "2026-10-08T12:00:00+09:00")
    output["deterministic_repeat"] = strip_generated(one) == strip_generated(two)
    nonfinite = FakeProvider(sha256(WALL_ARTIFACT), WALL_FEATURES, {"feature_available": True, "feature_order": list(WALL_FEATURES), "feature_vector": [None] * len(WALL_FEATURES), "input_sha256": "x", "artifact_identity": {"sha256": sha256(WALL_ARTIFACT)}})
    output["live_missing_never_median_filled"] = WallRLiveInferenceCore(provider=nonfinite).predict("fixture", "2026-10-08T12:00:00+09:00")["missing_reason"] == ["NONFINITE_INPUT"]
    for name, reason in (("prospective_snapshot_missing", "PROSPECTIVE_SNAPSHOT_NOT_FOUND"), ("non_wakunari", "NON_WAKUNARI"), ("exhibition_incomplete", "EXHIBITION_NOT_COMPLETE")):
        provider = FakeProvider(sha256(WALL_ARTIFACT), WALL_FEATURES, {"feature_available": False, "missing_reason": [reason], "blocked_reason": [reason]})
        output[name] = WallRLiveInferenceCore(provider=provider).predict("fixture", "2026-10-08T12:00:00+09:00")["missing_reason"] == [reason]
    with tempfile.TemporaryDirectory() as directory:
        target = Path(directory) / "artifact.json"; shutil.copy2(WALL_ARTIFACT, target); target.write_text("{}", encoding="utf-8")
        output["artifact_hash_mismatch"] = WallRLiveInferenceCore(paths={"artifact": target}, provider=FakeProvider("x", WALL_FEATURES)).load_error == "ARTIFACT_HASH_MISMATCH"
    with tempfile.TemporaryDirectory() as directory:
        target = Path(directory) / "artifact.json"; artifact=json.loads(WALL_ARTIFACT.read_text()); artifact["feature_names"] = list(reversed(WALL_FEATURES)); target.write_text(json.dumps(artifact), encoding="utf-8")
        output["feature_order_mismatch"] = WallRLiveInferenceCore(paths={"artifact": target}, expected_artifact=sha256(target), provider=FakeProvider(sha256(target), WALL_FEATURES)).load_error == "FEATURE_ORDER_MISMATCH"
    success = AttackSuccessLiveInferenceCore(wall_core=FakeUpstream(sha256(WALL_ARTIFACT), "wall_probability", .1), attack_core=FakeUpstream(sha256(A5_ARTIFACT), "attack_probability", .2))
    output["valid_attack_success"] = success.predict("fixture", "2026-10-08T12:00:00+09:00")["prediction_available"]
    output["wall_upstream_unavailable"] = AttackSuccessLiveInferenceCore(wall_core=FakeUpstream(sha256(WALL_ARTIFACT), "wall_probability", blocked="X"), attack_core=FakeUpstream(sha256(A5_ARTIFACT), "attack_probability", .2)).predict("fixture", "2026-10-08T12:00:00+09:00")["missing_reason"] == ["WALL_INPUT_UNAVAILABLE"]
    output["a5_upstream_unavailable"] = AttackSuccessLiveInferenceCore(wall_core=FakeUpstream(sha256(WALL_ARTIFACT), "wall_probability", .1), attack_core=FakeUpstream(sha256(A5_ARTIFACT), "attack_probability", blocked="X")).predict("fixture", "2026-10-08T12:00:00+09:00")["missing_reason"] == ["ATTACK_INPUT_UNAVAILABLE"]
    sources = [REPO / "forecast" / name for name in ("course34_wall_live.py", "course4_attack_a5_live.py", "course4_attack_success_live.py", "course4_tenkai_binary_live.py")] + [REPO / "analysis" / name for name in ("course34_wall_live_input_provider.py", "course4_attack_a5_live_input_provider.py")]
    output["result_table_access_zero"] = all("race_result_detail" not in path.read_text(encoding="utf-8") and "race_payouts" not in path.read_text(encoding="utf-8") for path in sources)
    real = {"wall": WallRLiveInferenceCore().predict("20261008WKM12", "2026-10-08T12:00:00+09:00"), "a5": AttackA5LiveInferenceCore().predict("20261008WKM12", "2026-10-08T12:00:00+09:00"), "success": AttackSuccessLiveInferenceCore().predict("20261008WKM12", "2026-10-08T12:00:00+09:00")}
    output["live_dry_run_missing_snapshot_fail_closed"] = all(not item["prediction_available"] for item in real.values())
    return output, {key: {"prediction_available": value["prediction_available"], "missing_reason": value.get("missing_reason")} for key, value in real.items()}


def main():
    test_checks, live_dry = checks()
    roundtrip = replay_all(); roundtrip.update({"generated_at": datetime.now().astimezone().isoformat(), "source_script": str(Path(__file__).relative_to(REPO)), "source_script_sha256": sha256(Path(__file__)), "source_git_commit": __import__("subprocess").check_output(["git", "rev-parse", "HEAD"], cwd=REPO, text=True).strip()})
    ROUNDTRIP.write_text(json.dumps(roundtrip, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    ready = all(test_checks.values()) and all(item["overall"]["probability"]["mismatch_count"] == 0 and item["overall"]["score_mismatch_count"] == 0 for item in (roundtrip["wall_r"], roundtrip["attack_a5"], roundtrip["attack_success"])) and roundtrip["wall_saved_confirmation"]["probability"]["mismatch_count"] == 0 and roundtrip["wall_saved_confirmation"]["score_mismatch_count"] == 0
    report = {"phase": "9-1g", "result": "PASS" if ready else "BLOCKED", "readiness": {"WALL_R_LIVE_INFERENCE_CORE": "READY" if ready else "BLOCKED", "ATTACK_A5_LIVE_INFERENCE_CORE": "READY" if ready else "BLOCKED", "ATTACK_SUCCESS_LIVE_INFERENCE_CORE": "READY" if ready else "BLOCKED"}, "tests": test_checks, "live_dry_run": live_dry, "roundtrip_path": str(ROUNDTRIP.relative_to(REPO)), "roundtrip_sha256": sha256(ROUNDTRIP), "snapshot_prediction_saved": False, "api_web_cron_changed": False, "true_forward_evaluated": False, "generated_at": datetime.now().astimezone().isoformat()}
    REPORT.write_text(json.dumps(report, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    if not ready: raise SystemExit(json.dumps({"pass": False, "tests": test_checks}, ensure_ascii=False))
    print(json.dumps({"pass": True, "report": str(REPORT.relative_to(REPO)), "readiness": report["readiness"]}, ensure_ascii=False))


if __name__ == "__main__": main()
