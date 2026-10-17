#!/usr/bin/env python3
"""Shared frozen-artifact binary inference for Phase 9-1g.

Feature construction deliberately remains in the canonical input providers.
This module only verifies identities and applies the preprocessing serialized in
an immutable research artifact.
"""
from __future__ import annotations

import bisect
import hashlib
import json
import math
from datetime import datetime
from pathlib import Path

import numpy as np

REPO = Path(__file__).resolve().parent.parent


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def canonical_json(value) -> str:
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"), allow_nan=False)


def now_iso() -> str:
    return datetime.now().astimezone().isoformat()


class BinaryLiveInferenceCore:
    """Fail-closed inference over one immutable binary logistic artifact."""

    def __init__(self, *, signal_id, probability_key, score_key, artifact_path, expected_artifact_sha256,
                 expected_feature_order, provider_factory, provider_source, logic_source, provider=None,
                 paths=None, expected_artifact=None):
        self.signal_id, self.probability_key, self.score_key = signal_id, probability_key, score_key
        paths = paths or {}
        self.artifact_path = Path(paths.get("artifact", artifact_path))
        self.expected_artifact = expected_artifact_sha256 if expected_artifact is None else expected_artifact
        self.expected_feature_order = tuple(expected_feature_order)
        self.provider_factory, self.provider_source, self.logic_source = provider_factory, Path(provider_source), Path(logic_source)
        self.provider = provider
        self.artifact, self.identities, self.logic_version = None, {}, None
        self.load_error = self._load_and_verify()

    def _load_and_verify(self):
        try:
            self.identities["artifact"] = sha256(self.artifact_path) if self.artifact_path.exists() else None
            if self.identities["artifact"] != self.expected_artifact:
                return "ARTIFACT_HASH_MISMATCH"
            self.artifact = json.loads(self.artifact_path.read_text(encoding="utf-8"))
            names = tuple(self.artifact.get("feature_names", self.artifact.get("input_features_ordered", ())))
            if names != self.expected_feature_order:
                return "FEATURE_ORDER_MISMATCH"
            for key in ("missing_fill_values", "scaler_mean", "scaler_std", "coefficients"):
                if len(self.artifact.get(key, ())) != len(names):
                    return "FEATURE_ORDER_MISMATCH"
            arrays = [np.asarray(self.artifact[key], dtype=float) for key in ("missing_fill_values", "scaler_mean", "scaler_std", "coefficients")]
            if not all(np.isfinite(values).all() for values in arrays) or not np.all(arrays[2] > 0) or not math.isfinite(float(self.artifact.get("intercept", float("nan")))):
                return "INFERENCE_ARTIFACT_LOAD_ERROR"
            provider_contract = getattr(__import__(self.provider_source.stem), "CONTRACT_VERSION", None)
            version_payload = {
                "shared_core_source_sha256": sha256(Path(__file__)), "signal_core_source_sha256": sha256(self.logic_source),
                "provider_source_sha256": sha256(self.provider_source), "artifact_sha256": self.identities["artifact"],
                "provider_contract_version": provider_contract, "canonical_input_version": "v1",
            }
            self.logic_version = hashlib.sha256(canonical_json(version_payload).encode("utf-8")).hexdigest()
            return None
        except (OSError, ValueError, TypeError, json.JSONDecodeError):
            return "INFERENCE_ARTIFACT_LOAD_ERROR"

    def _base(self, race_code, cutoff, mode):
        return {
            "signal_id": self.signal_id, "race_code": str(race_code), "prediction_cutoff": str(cutoff), "mode": mode,
            "stage": "POST_EXHIBITION", "artifact_sha256": self.identities.get("artifact"),
            "canonical_input_version": "v1", "logic_version": self.logic_version, "generated_at": now_iso(),
        }

    def _failure(self, race_code, cutoff, mode, reason, source=None):
        response = self._base(race_code, cutoff, mode)
        reasons = [reason]
        if source is not None:
            reasons = source.get("missing_reason") or source.get("blocked_reason") or reasons
            response["input_sha256"] = source.get("input_sha256")
            response["feature_order"] = source.get("feature_order")
        response.update({"prediction_available": False, "missing_reason": sorted(set(reasons)), "blocked_reason": sorted(set(reasons))})
        return response

    def _provider(self):
        if self.provider is None:
            self.provider = self.provider_factory()
        return self.provider

    def _score(self, probability):
        boundaries = self.artifact.get("score_percentile_boundaries")
        if not isinstance(boundaries, list) or len(boundaries) != 101 or not np.isfinite(np.asarray(boundaries, dtype=float)).all():
            raise ValueError("INFERENCE_ARTIFACT_LOAD_ERROR")
        return max(0, min(100, bisect.bisect_right(boundaries, float(probability)) - 1))

    def _calculate(self, values, research_replay):
        vector = np.asarray(values, dtype=float)
        if vector.shape != (len(self.expected_feature_order),):
            raise ValueError("FEATURE_ORDER_MISMATCH")
        if np.isinf(vector).any():
            raise ValueError("NONFINITE_INPUT")
        if np.isnan(vector).any():
            if not research_replay:
                raise ValueError("NONFINITE_INPUT")
            vector = np.where(np.isnan(vector), np.asarray(self.artifact["missing_fill_values"], dtype=float), vector)
        if not np.isfinite(vector).all():
            raise ValueError("NONFINITE_INPUT")
        mean, std = (np.asarray(self.artifact[key], dtype=float) for key in ("scaler_mean", "scaler_std"))
        logit = float(self.artifact["intercept"]) + float(np.dot((vector - mean) / std, np.asarray(self.artifact["coefficients"], dtype=float)))
        low, high = self.artifact["logit_clipping"]
        probability = 1.0 / (1.0 + math.exp(-max(float(low), min(float(high), logit))))
        if not math.isfinite(probability) or not 0.0 <= probability <= 1.0:
            raise ValueError("INVALID_PROBABILITY")
        return vector.tolist(), probability, self._score(probability)

    def _predict(self, race_code, prediction_cutoff, mode):
        if self.load_error:
            return self._failure(race_code, prediction_cutoff, mode, self.load_error)
        source = self._provider().build(race_code, prediction_cutoff, mode)
        if not source.get("feature_available"):
            return self._failure(race_code, prediction_cutoff, mode, "MISSING_REQUIRED_FEATURE", source)
        if tuple(source.get("feature_order", ())) != self.expected_feature_order:
            return self._failure(race_code, prediction_cutoff, mode, "FEATURE_ORDER_MISMATCH", source)
        if source.get("artifact_identity", {}).get("sha256") != self.identities["artifact"]:
            return self._failure(race_code, prediction_cutoff, mode, "UPSTREAM_IDENTITY_MISMATCH", source)
        try:
            vector, probability, score = self._calculate(source.get("feature_vector"), mode == "RESEARCH_REPLAY")
        except ValueError as error:
            return self._failure(race_code, prediction_cutoff, mode, str(error), source)
        response = self._base(race_code, prediction_cutoff, mode)
        response.update({
            "prediction_available": True, "missing_reason": [], "blocked_reason": [],
            self.probability_key: probability, self.score_key: score,
            "feature_vector": vector, "feature_order": list(self.expected_feature_order),
            "input_sha256": source["input_sha256"], "provider_logic_identity": source.get("contract_version"),
        })
        return response

    def predict(self, race_code, prediction_cutoff):
        return self._predict(race_code, prediction_cutoff, "LIVE")

    def predict_research_replay(self, race_code, prediction_cutoff):
        return self._predict(race_code, prediction_cutoff, "RESEARCH_REPLAY")
