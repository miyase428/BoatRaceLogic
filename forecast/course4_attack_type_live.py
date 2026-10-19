#!/usr/bin/env python3
"""Read-only POST_EXHIBITION inference core for the Phase 7 Attack Type model.

This module deliberately owns only identity checks, frozen-artifact inference,
and machine-readable responses.  Feature construction and every LIVE database
query remain exclusively in ``course4_attack_type_live_input_provider``.
"""
from __future__ import annotations

import argparse
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
from course4_attack_type_live_input_provider import (  # noqa: E402
    ARTIFACT, CONTRACT_VERSION, FEATURE_ORDER, LEDGER, LEDGER_STATE, MANIFEST, SPEC,
    AttackTypeLiveInputProvider, canonical_json, parse_cutoff,
)
from course4_attack_ledger_generation_store import (  # noqa: E402
    MODE_GENERATION, MODE_LEGACY, AttackTypeLedgerGenerationStore, GenerationStoreError,
)

CONTRACT = ANALYSIS / "output" / "course4_tenkai_phase9_attack_type_live_input_contract_v2.json"
EXPECTED_IDENTITIES = {
    "contract": "d4ea961722561e44e1313cdc3572cee8cb6403df1ef6ce934e9da62ccba17f7a",
    "artifact": "e0f3674e3e863a4a251aafb2e87530ebba85d4c393982d019fdccd124f3707b4",
    "spec": "e36d6d2068e583629328e85cdf3d2aa1c516711d35472439d811bdbf8a3f9343",
    "ledger": "d4d97c47506e094d0093e99f2ad2fb84664db6fab09d0e90b1f2a0d56941b593",
    "ledger_manifest": "0e9aa5a5e20b3f3c65a2579422c11ac8867aaff160f0826ab149764d56849823",
}
CLASS_ORDER = ("NO_ATTACK_SUCCESS", "MAKURI_SUCCESS", "MAKURIZASHI_SUCCESS")


def sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def now_iso() -> str:
    return datetime.now().astimezone().isoformat()


class AttackTypeLiveInferenceCore:
    """Frozen-artifact core; no ledger append and no feature/DB logic."""

    def __init__(self, provider=None, paths=None, expected_identities=None, *, ledger_mode=MODE_LEGACY,
                 generation_root=None, generation_store_factory=AttackTypeLedgerGenerationStore, provider_paths=None):
        if ledger_mode not in (MODE_LEGACY, MODE_GENERATION):
            raise ValueError("INVALID_LEDGER_MODE")
        paths = paths or {}
        self.ledger_mode = ledger_mode
        self.generation_root = Path(generation_root).absolute() if generation_root is not None else None
        self.generation_store_factory = generation_store_factory
        self.provider_paths = provider_paths
        self.paths = {
            "contract": Path(paths.get("contract", CONTRACT)),
            "artifact": Path(paths.get("artifact", ARTIFACT)),
            "spec": Path(paths.get("spec", SPEC)),
            "ledger": Path(paths.get("ledger", LEDGER)),
            "ledger_manifest": Path(paths.get("ledger_manifest", MANIFEST)), "ledger_state": Path(paths.get("ledger_state", LEDGER_STATE)),
        }
        self.expected = dict(EXPECTED_IDENTITIES if expected_identities is None else expected_identities)
        self.provider = provider
        self.contract = None
        self.artifact = None
        self.manifest = None
        self.identities = {}
        self.logic_version = None
        self.load_error = self._load_and_verify()

    def _load_and_verify(self):
        try:
            static_paths = self.paths if self.ledger_mode == MODE_LEGACY else {
                "contract": self.paths["contract"], "artifact": self.paths["artifact"], "spec": self.paths["spec"],
            }
            self.identities = {key: sha256(path) if path.exists() else None for key, path in static_paths.items()}
            if self.ledger_mode == MODE_GENERATION:
                self.identities.update({"ledger": None, "ledger_manifest": None, "ledger_state": None})
            if self.ledger_mode == MODE_GENERATION and self.generation_root is None:
                return "GENERATION_CONTEXT_REQUIRED"
            for key, expected in self.expected.items():
                if self.ledger_mode == MODE_GENERATION and key in ("ledger", "ledger_manifest"):
                    continue
                if key in ("ledger", "ledger_manifest") and self.paths["ledger_state"].exists():
                    continue
                if self.identities.get(key) != expected:
                    if key == "contract":
                        return "INPUT_CONTRACT_VERSION_MISMATCH"
                    if key == "artifact":
                        return "ARTIFACT_HASH_MISMATCH"
                    if key in ("ledger", "ledger_manifest"):
                        return "LEDGER_HASH_MISMATCH"
                    return "INFERENCE_FEATURE_ORDER_MISMATCH"
            self.contract = json.loads(self.paths["contract"].read_text(encoding="utf-8"))
            self.artifact = json.loads(self.paths["artifact"].read_text(encoding="utf-8"))
            spec = json.loads(self.paths["spec"].read_text(encoding="utf-8"))
            self.manifest = json.loads(self.paths["ledger_manifest"].read_text(encoding="utf-8")) if self.ledger_mode == MODE_LEGACY else None
            state = json.loads(self.paths["ledger_state"].read_text(encoding="utf-8")) if self.ledger_mode == MODE_LEGACY and self.paths["ledger_state"].exists() else None
            contract_ledger = self.contract.get("ledger", {})
            identities_ok = (
                self.contract.get("contract_version") == CONTRACT_VERSION
                and self.contract.get("artifact", {}).get("sha256") == self.identities["artifact"]
                and self.contract.get("spec", {}).get("sha256") == self.identities["spec"]
            )
            if self.ledger_mode == MODE_LEGACY:
                identities_ok = identities_ok and ((contract_ledger.get("sha256") == self.identities["ledger"] and contract_ledger.get("manifest_sha256") == self.identities["ledger_manifest"]) or state is not None) and self.manifest.get("ledger_sha256") == self.identities["ledger"] and (state is None or (state.get("ledger_sha256") == self.identities["ledger"] and state.get("manifest_sha256") == self.identities["ledger_manifest"] and state.get("baseline_source_sha256") == self.manifest.get("baseline_source_sha256") and state.get("ledger_version") == "v1"))
            feature_ok = (
                tuple(self.contract.get("feature_order", ())) == FEATURE_ORDER
                and tuple(self.artifact.get("feature_order", ())) == FEATURE_ORDER
                and tuple(spec.get("final_feature_order", ())) == FEATURE_ORDER
                and tuple(self.artifact.get("class_order", ())) == CLASS_ORDER
                and self.artifact.get("reference_class") == 0
            )
            if not identities_ok:
                return "INPUT_CONTRACT_VERSION_MISMATCH"
            if not feature_ok:
                return "INFERENCE_FEATURE_ORDER_MISMATCH"
            version_payload = {
                "core_source_sha256": sha256(Path(__file__)),
                "provider_source_sha256": sha256(ANALYSIS / "course4_attack_type_live_input_provider.py"),
                "contract_sha256": self.identities["contract"],
                "artifact_sha256": self.identities["artifact"],
                "spec_sha256": self.identities["spec"],
                "ledger_mode": self.ledger_mode,
                "ledger_id": self.manifest.get("ledger_id") if self.manifest else None,
                "ledger_version": self.manifest.get("ledger_version") if self.manifest else None,
            }
            # Deliberately excludes the mutable ledger content SHA.
            self.logic_version = hashlib.sha256(canonical_json(version_payload).encode("utf-8")).hexdigest()
            return None
        except (OSError, ValueError, TypeError, json.JSONDecodeError):
            return "INFERENCE_ARTIFACT_LOAD_ERROR"

    def _base_response(self, race_code, prediction_cutoff, generation_context=None):
        response = {
            "race_code": str(race_code), "prediction_cutoff": str(prediction_cutoff),
            "stage": "POST_EXHIBITION", "artifact_sha256": self.identities.get("artifact"),
            "spec_sha256": self.identities.get("spec"), "ledger_sha256": self.identities.get("ledger"),
            "ledger_manifest_sha256": self.identities.get("ledger_manifest"),
            "input_contract_version": self.contract.get("contract_version") if self.contract else CONTRACT_VERSION,
            "input_contract_sha256": self.identities.get("contract"), "logic_version": self.logic_version,
            "generated_at": now_iso(),
        }
        if generation_context is not None:
            response.update({"ledger_sha256": generation_context.ledger_sha256,
                             "ledger_manifest_sha256": generation_context.manifest_sha256,
                             "ledger_generation": {"generation_id": generation_context.generation_id,
                                                   "descriptor_sha256": generation_context.descriptor_sha256,
                                                   "state_sha256": generation_context.state_sha256,
                                                   "activation_id": generation_context.activation_id,
                                                   "activated_at": generation_context.activated_at.isoformat()}})
        return response

    def _failure(self, race_code, cutoff, reason, provider_response=None, generation_context=None):
        response = self._base_response(race_code, cutoff, generation_context)
        response.update({"prediction_available": False, "missing_reason": [reason], "blocked_reason": [reason]})
        if provider_response:
            reasons = provider_response.get("missing_reason") or provider_response.get("blocked_reason") or [reason]
            response["missing_reason"] = list(reasons)
            response["blocked_reason"] = list(reasons)
            response["input_sha256"] = provider_response.get("input_sha256")
        return response

    def _provider(self):
        if self.provider is None:
            self.provider = AttackTypeLiveInputProvider(ledger_mode=self.ledger_mode, paths=self.provider_paths)
        return self.provider

    def _resolve_generation_context(self, cutoff):
        if self.ledger_mode != MODE_GENERATION:
            return None
        try:
            return self.generation_store_factory(self.generation_root).resolve(mode=MODE_GENERATION, prediction_cutoff=cutoff)
        except GenerationStoreError as error:
            return error

    def _calculate(self, features, allow_replay_median):
        vector = np.asarray(features, dtype=float)
        if vector.shape != (len(FEATURE_ORDER),):
            raise ValueError("INFERENCE_FEATURE_ORDER_MISMATCH")
        if not allow_replay_median and not np.isfinite(vector).all():
            raise ValueError("INFERENCE_NONFINITE_INPUT")
        med = np.asarray(self.artifact["development_medians"], dtype=float)
        mean = np.asarray(self.artifact["development_means"], dtype=float)
        std = np.asarray(self.artifact["development_stds"], dtype=float)
        if not (np.isfinite(med).all() and np.isfinite(mean).all() and np.isfinite(std).all() and np.all(std > 0)):
            raise ValueError("INFERENCE_ARTIFACT_LOAD_ERROR")
        if allow_replay_median:
            vector = np.where(np.isnan(vector), med, vector)
        z = (vector - mean) / std
        if not np.isfinite(z).all():
            raise ValueError("INFERENCE_NONFINITE_INPUT")
        theta = np.c_[np.asarray(self.artifact["intercepts"], dtype=float), np.asarray(self.artifact["coefficients"], dtype=float)]
        logits = np.r_[0.0, np.r_[1.0, z] @ theta.T]
        logits = np.clip(logits, -35.0, 35.0)
        exp = np.exp(logits - np.max(logits))
        probabilities = exp / np.sum(exp)
        if not (np.isfinite(probabilities).all() and np.all((0.0 <= probabilities) & (probabilities <= 1.0)) and abs(float(probabilities.sum()) - 1.0) <= 1e-12):
            raise ValueError("INFERENCE_INVALID_PROBABILITY")
        return vector.tolist(), probabilities.tolist()

    def _predict_mode(self, race_code, prediction_cutoff, mode):
        if self.load_error:
            return self._failure(race_code, prediction_cutoff, self.load_error)
        try:
            cutoff = parse_cutoff(prediction_cutoff)
        except ValueError:
            return self._failure(race_code, prediction_cutoff, "INPUT_CONTRACT_VERSION_MISMATCH")
        generation_context = self._resolve_generation_context(cutoff)
        if isinstance(generation_context, GenerationStoreError):
            return self._failure(race_code, prediction_cutoff, "LEDGER_HASH_MISMATCH")
        provider_response = self._provider().build(race_code, cutoff, mode, CONTRACT_VERSION, generation_context=generation_context)
        if not provider_response.get("prediction_available"):
            return self._failure(race_code, prediction_cutoff, "MISSING_REQUIRED_FEATURE", provider_response, generation_context)
        if tuple(provider_response.get("feature_order", ())) != FEATURE_ORDER:
            return self._failure(race_code, prediction_cutoff, "INFERENCE_FEATURE_ORDER_MISMATCH", provider_response, generation_context)
        try:
            vector, probabilities = self._calculate(provider_response.get("feature_vector"), allow_replay_median=(mode == "RESEARCH_REPLAY"))
        except ValueError as error:
            return self._failure(race_code, prediction_cutoff, str(error), provider_response, generation_context)
        response = self._base_response(race_code, prediction_cutoff, generation_context)
        response.update({
            "prediction_available": True, "missing_reason": [], "blocked_reason": [],
            "p_no_attack_success": probabilities[0], "p_makuri_success": probabilities[1],
            "p_makurizashi_success": probabilities[2], "input_feature_vector": vector,
            "input_sha256": provider_response["input_sha256"],
        })
        if self.ledger_mode == MODE_GENERATION:
            # Preserve the provider's exact context-derived identity alongside
            # the core provenance; callers can verify both without re-reading
            # CURRENT or any generation file.
            response["input_ledger_identity"] = provider_response.get("ledger_identity")
        return response

    def predict(self, race_code, prediction_cutoff):
        """Official LIVE entry point; missing inputs are never median-filled."""
        return self._predict_mode(race_code, prediction_cutoff, "LIVE")

    def predict_research_replay(self, race_code, prediction_cutoff):
        """Audit-only replay entry point; never a LIVE prediction API."""
        return self._predict_mode(race_code, prediction_cutoff, "RESEARCH_REPLAY")


def predict(race_code, prediction_cutoff):
    return AttackTypeLiveInferenceCore().predict(race_code, prediction_cutoff)


def main():
    parser = argparse.ArgumentParser(description="Phase 7 4C Attack Type LIVE inference core")
    parser.add_argument("--race-code", required=True)
    parser.add_argument("--prediction-cutoff", required=True)
    parser.add_argument("--json", action="store_true", help="emit JSON only")
    args = parser.parse_args()
    result = predict(args.race_code, args.prediction_cutoff)
    print(json.dumps(result, ensure_ascii=False, sort_keys=True))


if __name__ == "__main__":
    main()
