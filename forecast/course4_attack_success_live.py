#!/usr/bin/env python3
"""Phase 9-1g frozen Attack Success inference from the two live base cores."""
from __future__ import annotations

import argparse
import bisect
import hashlib
import json
import math
import sys
from datetime import datetime
from pathlib import Path

import numpy as np

REPO = Path(__file__).resolve().parent.parent
ANALYSIS = REPO / "analysis"
sys.path.insert(0, str(ANALYSIS))
from course4_tenkai_binary_live import canonical_json, sha256
from course34_wall_live import WallRLiveInferenceCore
from course4_attack_a5_live import AttackA5LiveInferenceCore

ARTIFACT = ANALYSIS / "output" / "course4_attack_success_phase5_final_model.json"
EXPECTED_ARTIFACT_SHA256 = "9dae5b89626692bbcc31e01f02b1ce745f4046976250e06c76d1d79491d7af4a"
EXPECTED_FEATURE_ORDER = ("wall_r_probability", "attack_a5_probability")
EXPECTED_WALL_SHA256 = "f5348195d1d05c982f273ce7924c2b0eda5174e243e3e01bb818b87d6f3e768c"
EXPECTED_A5_SHA256 = "39808675efde92bfcbb73d69f88e915260f7dd0e0c48636d1b2775d6b2874176"


def now_iso(): return datetime.now().astimezone().isoformat()


class AttackSuccessLiveInferenceCore:
    def __init__(self, wall_core=None, attack_core=None, paths=None, expected_artifact=None):
        paths = paths or {}
        self.artifact_path = Path(paths.get("artifact", ARTIFACT))
        self.expected_artifact = EXPECTED_ARTIFACT_SHA256 if expected_artifact is None else expected_artifact
        self.wall_core = wall_core or WallRLiveInferenceCore()
        self.attack_core = attack_core or AttackA5LiveInferenceCore()
        self.artifact, self.identities, self.logic_version = None, {}, None
        self.load_error = self._load_and_verify()

    def _load_and_verify(self):
        try:
            self.identities["artifact"] = sha256(self.artifact_path) if self.artifact_path.exists() else None
            if self.identities["artifact"] != self.expected_artifact:
                return "ARTIFACT_HASH_MISMATCH"
            self.artifact = json.loads(self.artifact_path.read_text(encoding="utf-8"))
            if tuple(self.artifact.get("input_features_ordered", ())) != EXPECTED_FEATURE_ORDER:
                return "FEATURE_ORDER_MISMATCH"
            dependencies = self.artifact.get("base_artifacts", {})
            if dependencies.get("wall_final", {}).get("sha256") != EXPECTED_WALL_SHA256 or dependencies.get("attack_final", {}).get("sha256") != EXPECTED_A5_SHA256:
                return "UPSTREAM_IDENTITY_MISMATCH"
            for key in ("missing_fill_values", "scaler_mean", "scaler_std", "coefficients"):
                if len(self.artifact.get(key, ())) != 2:
                    return "FEATURE_ORDER_MISMATCH"
            arrays = [np.asarray(self.artifact[key], dtype=float) for key in ("missing_fill_values", "scaler_mean", "scaler_std", "coefficients")]
            if not all(np.isfinite(values).all() for values in arrays) or not np.all(arrays[2] > 0) or not math.isfinite(float(self.artifact.get("intercept", float("nan")))):
                return "INFERENCE_ARTIFACT_LOAD_ERROR"
            version_payload = {"core_source_sha256": sha256(Path(__file__)), "artifact_sha256": self.identities["artifact"],
                               "wall_logic_version": self.wall_core.logic_version, "attack_logic_version": self.attack_core.logic_version,
                               "wall_artifact_sha256": EXPECTED_WALL_SHA256, "attack_artifact_sha256": EXPECTED_A5_SHA256}
            self.logic_version = hashlib.sha256(canonical_json(version_payload).encode()).hexdigest()
            return None
        except (OSError, ValueError, TypeError, json.JSONDecodeError):
            return "INFERENCE_ARTIFACT_LOAD_ERROR"

    def _base(self, race_code, cutoff, mode):
        return {"signal_id": "ATTACK_SUCCESS", "race_code": str(race_code), "prediction_cutoff": str(cutoff), "mode": mode,
                "stage": "POST_EXHIBITION", "artifact_sha256": self.identities.get("artifact"), "logic_version": self.logic_version,
                "generated_at": now_iso()}

    def _failure(self, race_code, cutoff, mode, reasons, wall=None, attack=None):
        response = self._base(race_code, cutoff, mode)
        response.update({"prediction_available": False, "missing_reason": sorted(set(reasons)), "blocked_reason": sorted(set(reasons))})
        response["upstream"] = {"wall_input_sha256": (wall or {}).get("input_sha256"), "attack_input_sha256": (attack or {}).get("input_sha256"),
                                "wall_logic_version": (wall or {}).get("logic_version"), "attack_logic_version": (attack or {}).get("logic_version")}
        return response

    def _calculate(self, wall_probability, attack_probability):
        vector = np.asarray([wall_probability, attack_probability], dtype=float)
        if not np.isfinite(vector).all(): raise ValueError("NONFINITE_INPUT")
        if np.any(vector < 0.0) or np.any(vector > 1.0): raise ValueError("INVALID_PROBABILITY")
        mean, std = np.asarray(self.artifact["scaler_mean"], dtype=float), np.asarray(self.artifact["scaler_std"], dtype=float)
        logit = float(self.artifact["intercept"]) + float(np.dot((vector - mean) / std, np.asarray(self.artifact["coefficients"], dtype=float)))
        lo, hi = self.artifact["logit_clipping"]
        probability = 1.0 / (1.0 + math.exp(-max(float(lo), min(float(hi), logit))))
        if not math.isfinite(probability) or not 0.0 <= probability <= 1.0: raise ValueError("INVALID_PROBABILITY")
        boundaries = self.artifact.get("score_percentile_boundaries", [])
        if len(boundaries) != 101 or not np.isfinite(np.asarray(boundaries, dtype=float)).all(): raise ValueError("INFERENCE_ARTIFACT_LOAD_ERROR")
        return probability, max(0, min(100, bisect.bisect_right(boundaries, probability) - 1))

    def _predict(self, race_code, cutoff, mode):
        if self.load_error:
            return self._failure(race_code, cutoff, mode, [self.load_error])
        wall = self.wall_core.predict_research_replay(race_code, cutoff) if mode == "RESEARCH_REPLAY" else self.wall_core.predict(race_code, cutoff)
        attack = self.attack_core.predict_research_replay(race_code, cutoff) if mode == "RESEARCH_REPLAY" else self.attack_core.predict(race_code, cutoff)
        reasons = []
        if not wall.get("prediction_available"): reasons.append("WALL_INPUT_UNAVAILABLE")
        if not attack.get("prediction_available"): reasons.append("ATTACK_INPUT_UNAVAILABLE")
        if reasons: return self._failure(race_code, cutoff, mode, reasons, wall, attack)
        if wall.get("artifact_sha256") != EXPECTED_WALL_SHA256 or attack.get("artifact_sha256") != EXPECTED_A5_SHA256:
            return self._failure(race_code, cutoff, mode, ["UPSTREAM_IDENTITY_MISMATCH"], wall, attack)
        try:
            probability, score = self._calculate(wall.get("wall_probability"), attack.get("attack_probability"))
        except ValueError as error:
            return self._failure(race_code, cutoff, mode, [str(error)], wall, attack)
        response = self._base(race_code, cutoff, mode)
        response.update({"prediction_available": True, "missing_reason": [], "blocked_reason": [], "attack_success_probability": probability,
                         "attack_success_score": score, "wall_probability": wall["wall_probability"], "attack_probability": attack["attack_probability"],
                         "upstream_input_sha256": hashlib.sha256(canonical_json({"wall_input_sha256": wall["input_sha256"], "attack_input_sha256": attack["input_sha256"], "wall_probability": wall["wall_probability"], "attack_probability": attack["attack_probability"]}).encode("utf-8")).hexdigest(),
                         "upstream": {"wall_input_sha256": wall["input_sha256"], "attack_input_sha256": attack["input_sha256"],
                                      "wall_logic_version": wall["logic_version"], "attack_logic_version": attack["logic_version"],
                                      "wall_artifact_sha256": wall["artifact_sha256"], "attack_artifact_sha256": attack["artifact_sha256"]}})
        return response

    def predict(self, race_code, prediction_cutoff): return self._predict(race_code, prediction_cutoff, "LIVE")
    def predict_research_replay(self, race_code, prediction_cutoff): return self._predict(race_code, prediction_cutoff, "RESEARCH_REPLAY")


def predict(race_code, prediction_cutoff): return AttackSuccessLiveInferenceCore().predict(race_code, prediction_cutoff)
if __name__ == "__main__":
    parser=argparse.ArgumentParser(); parser.add_argument("--race-code",required=True); parser.add_argument("--prediction-cutoff",required=True); args=parser.parse_args()
    print(json.dumps(predict(args.race_code,args.prediction_cutoff),ensure_ascii=False,sort_keys=True))
