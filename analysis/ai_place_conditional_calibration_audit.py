#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""AI条件付き2着率・3着率 v1 の未使用期間キャリブレーション監査。"""

from __future__ import annotations

import json
import math
import os
from pathlib import Path

import joblib
os.environ.setdefault("MPLCONFIGDIR", "/tmp/boatrace-matplotlib")

import matplotlib
matplotlib.use("Agg")
import matplotlib.pyplot as plt
import numpy as np

import train_ai_place_v1 as place


ROOT = Path(__file__).resolve().parent.parent
REPORT_PATH = ROOT / "analysis/output/ai_place_conditional_calibration_audit_2026.json"
FIGURE_PATH = ROOT / "analysis/output/ai_place_conditional_calibration_audit_2026.png"
BOOTSTRAP_SAMPLES = 2000
SEED = 20260926
BIN_EDGES = np.asarray(
    [0.00, 0.05, 0.10, 0.15, 0.20, 0.25, 0.30, 0.40, 0.50, 0.60, 0.70, 1.000001],
    dtype=np.float64,
)


def prediction_arrays(races, predictions, target):
    candidates_per_race = 5 if target == "second" else 4
    probabilities = np.empty((len(races), candidates_per_race), dtype=np.float64)
    outcomes = np.zeros_like(probabilities)
    codes = []
    for index, race in enumerate(races):
        row = predictions[race["race_code"]]
        candidates = sorted(row)
        actual = race["actual"][1 if target == "second" else 2]
        probabilities[index] = [row[candidate] for candidate in candidates]
        outcomes[index] = [1.0 if candidate == actual else 0.0 for candidate in candidates]
        codes.append(race["race_code"])
    return probabilities, outcomes, codes


def reliability_bins(probabilities, outcomes):
    p = probabilities.ravel()
    y = outcomes.ravel()
    rows = []
    for low, high in zip(BIN_EDGES[:-1], BIN_EDGES[1:]):
        mask = (p >= low) & (p < high)
        if not mask.any():
            continue
        predicted = float(p[mask].mean())
        observed = float(y[mask].mean())
        rows.append({
            "low": float(low),
            "high": min(float(high), 1.0),
            "n": int(mask.sum()),
            "mean_predicted": predicted,
            "observed_rate": observed,
            "gap": observed - predicted,
        })
    return rows


def expected_calibration_error(probabilities, outcomes):
    rows = reliability_bins(probabilities, outcomes)
    total = sum(row["n"] for row in rows)
    return sum(row["n"] * abs(row["gap"]) for row in rows) / max(total, 1)


def scalar_metrics(probabilities, outcomes):
    actual_probability = (probabilities * outcomes).sum(axis=1)
    actual_index = outcomes.argmax(axis=1)
    predicted_index = probabilities.argmax(axis=1)
    return {
        "n_races": int(probabilities.shape[0]),
        "n_candidate_probabilities": int(probabilities.size),
        "nll": float((-np.log(np.clip(actual_probability, place.EPS, 1.0))).mean()),
        "brier": float(((probabilities - outcomes) ** 2).mean()),
        "top1": float((predicted_index == actual_index).mean()),
        "ece": float(expected_calibration_error(probabilities, outcomes)),
        "mean_actual_probability": float(actual_probability.mean()),
        "mean_max_probability": float(probabilities.max(axis=1).mean()),
    }


def bootstrap_comparison(base_p, current_p, outcomes):
    rng = np.random.default_rng(SEED)
    n = outcomes.shape[0]
    current_brier = []
    current_nll = []
    current_ece = []
    brier_gain = []
    nll_gain = []
    for _ in range(BOOTSTRAP_SAMPLES):
        sample = rng.integers(0, n, size=n)
        y = outcomes[sample]
        base = base_p[sample]
        current = current_p[sample]
        base_actual = (base * y).sum(axis=1)
        current_actual = (current * y).sum(axis=1)
        base_brier = float(((base - y) ** 2).mean())
        current_brier_value = float(((current - y) ** 2).mean())
        base_nll = float((-np.log(np.clip(base_actual, place.EPS, 1.0))).mean())
        current_nll_value = float((-np.log(np.clip(current_actual, place.EPS, 1.0))).mean())
        current_brier.append(current_brier_value)
        current_nll.append(current_nll_value)
        current_ece.append(expected_calibration_error(current, y))
        brier_gain.append(base_brier - current_brier_value)
        nll_gain.append(base_nll - current_nll_value)

    def interval(values):
        values = np.asarray(values, dtype=np.float64)
        return [float(v) for v in np.quantile(values, [0.025, 0.975])]

    return {
        "samples": BOOTSTRAP_SAMPLES,
        "current_brier_95ci": interval(current_brier),
        "current_nll_95ci": interval(current_nll),
        "current_ece_95ci": interval(current_ece),
        "brier_gain_baseline_minus_v1_95ci": interval(brier_gain),
        "nll_gain_baseline_minus_v1_95ci": interval(nll_gain),
        "probability_v1_brier_better": float(np.mean(np.asarray(brier_gain) > 0.0)),
        "probability_v1_nll_better": float(np.mean(np.asarray(nll_gain) > 0.0)),
    }


def audit_target(train, valid, test, target):
    selected = place.train_and_evaluate(train, valid, test, target)
    base_predictions = place.baseline_predictions(test, target)
    ml_predictions = place.grouped_predictions(
        selected["model"], test, target, selected["temperature"]
    )
    current_predictions = place.blend_predictions(
        base_predictions, ml_predictions, selected["alpha"]
    )
    base_p, outcomes, codes = prediction_arrays(test, base_predictions, target)
    current_p, current_outcomes, current_codes = prediction_arrays(test, current_predictions, target)
    if codes != current_codes or not np.array_equal(outcomes, current_outcomes):
        raise RuntimeError(f"{target}: prediction rows do not align")

    production = joblib.load(place.MODEL_PATH)
    frozen = production[target]
    selected_parameters_match_production = (
        math.isclose(float(selected["temperature"]), float(frozen["temperature"]))
        and math.isclose(float(selected["alpha"]), float(frozen["alpha"]))
    )
    return {
        "target": target,
        "temperature": float(selected["temperature"]),
        "alpha": float(selected["alpha"]),
        "selected_parameters_match_production": selected_parameters_match_production,
        "baseline": scalar_metrics(base_p, outcomes),
        "v1": scalar_metrics(current_p, outcomes),
        "v1_reliability_bins": reliability_bins(current_p, outcomes),
        "bootstrap": bootstrap_comparison(base_p, current_p, outcomes),
    }


def draw_figure(results):
    fig, axes = plt.subplots(1, 2, figsize=(12, 5), sharex=True, sharey=True)
    for axis, target in zip(axes, ("second", "third")):
        rows = results[target]["v1_reliability_bins"]
        x = [row["mean_predicted"] for row in rows]
        y = [row["observed_rate"] for row in rows]
        size = [max(24, row["n"] * 0.35) for row in rows]
        axis.plot([0, 0.75], [0, 0.75], linestyle="--", color="#777777", label="ideal")
        axis.plot(x, y, color="#6e55a3", linewidth=1.8)
        axis.scatter(x, y, s=size, color="#6e55a3", alpha=0.78)
        axis.set_title(f"Conditional {target} probability v1")
        axis.set_xlabel("Mean predicted probability")
        axis.grid(alpha=0.2)
        axis.set_xlim(0, 0.75)
        axis.set_ylim(0, 0.75)
    axes[0].set_ylabel("Observed frequency")
    axes[0].legend(loc="upper left")
    fig.suptitle("Untouched test: 2026-09-11 to 2026-09-21")
    fig.tight_layout()
    fig.savefig(FIGURE_PATH, dpi=160, bbox_inches="tight")
    plt.close(fig)


def main():
    print("条件付き2着率・3着率の未使用期間監査データを構築中…", flush=True)
    races, _place_to_id, skip = place.build_races()
    train = [race for race in races if race["race_date"] <= place.TRAIN_END]
    valid = [
        race for race in races
        if place.VALID_START <= race["race_date"] <= place.VALID_END
    ]
    test = [
        race for race in races
        if place.TEST_START <= race["race_date"] <= place.TEST_END
    ]
    if not train or not valid or not test:
        raise RuntimeError("時系列分割後のデータが不足しています")

    results = {
        target: audit_target(train, valid, test, target)
        for target in ("second", "third")
    }
    report = {
        "audit": "ai_place_conditional_calibration_v1",
        "method": {
            "train_end": place.TRAIN_END.isoformat(),
            "validation": [place.VALID_START.isoformat(), place.VALID_END.isoformat()],
            "untouched_test": [place.TEST_START.isoformat(), place.TEST_END.isoformat()],
            "train_races": len(train),
            "validation_races": len(valid),
            "test_races": len(test),
            "leakage_note": (
                "The production artifact was retrained through the test end after model selection. "
                "This audit recreates the selected specification using only training data through "
                "2026-08-31 and evaluates the untouched 2026-09-11..21 period."
            ),
            "skip": skip,
        },
        "second": results["second"],
        "third": results["third"],
    }
    REPORT_PATH.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
    draw_figure(results)

    for target in ("second", "third"):
        row = results[target]
        print(
            f"{target}: N={row['v1']['n_races']} "
            f"Brier={row['v1']['brier']:.6f} "
            f"NLL={row['v1']['nll']:.6f} "
            f"ECE={row['v1']['ece']:.6f} "
            f"Top1={row['v1']['top1'] * 100:.2f}% "
            f"params_match={row['selected_parameters_match_production']}"
        )
    print(f"保存: {REPORT_PATH}")
    print(f"保存: {FIGURE_PATH}")


if __name__ == "__main__":
    main()
