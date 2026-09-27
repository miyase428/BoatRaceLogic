#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""AI1着率v4のコース別・場別縮約補正を前方検証する。

入力は reliability 検証で保存した月次out-of-fold予測。各評価月より前の
最大3か月だけで補正値を推定する。最初の月は補正学習期間がないため、
候補比較は2か月目以降を対象にする。
"""

from __future__ import annotations

import argparse
import csv
import gzip
import json
import os
from collections import defaultdict
from dataclasses import dataclass
from pathlib import Path

import numpy as np
from scipy.optimize import minimize_scalar

ROOT = Path(__file__).resolve().parent.parent
DEFAULT_INPUT = ROOT / "analysis" / "output" / "ai_winrate_v4_reliability_2026_predictions.csv.gz"
DEFAULT_OUTPUT = ROOT / "analysis" / "output" / "ai_winrate_v4_segment_calibration_2026"

COURSE_STRENGTHS = (100, 250, 500, 1000, 2000)
VENUE_STRENGTHS = (100, 250, 500, 1000)
COMBINATIONS = ((250, 250), (250, 500), (500, 250), (500, 500), (500, 1000), (1000, 500), (1000, 1000))
CALIBRATION_BINS = np.asarray(
    [0.00, 0.05, 0.10, 0.15, 0.20, 0.30, 0.40, 0.50, 0.60, 0.70, 0.80, 0.90, 1.0000001]
)
TOP1_BINS = np.asarray(
    [0.00, 0.25, 0.30, 0.35, 0.40, 0.50, 0.60, 0.70, 0.80, 0.90, 1.0000001]
)


@dataclass
class RaceBatch:
    month: str
    race_codes: np.ndarray
    places: np.ndarray
    courses: np.ndarray
    labels: np.ndarray
    probabilities: np.ndarray

    @property
    def races(self) -> int:
        return self.probabilities.shape[0]


def load_batches(path: Path) -> dict[str, RaceBatch]:
    grouped: dict[str, dict[str, list[dict]]] = defaultdict(lambda: defaultdict(list))
    with gzip.open(path, "rt", encoding="utf-8", newline="") as handle:
        for row in csv.DictReader(handle):
            grouped[row["month"]][row["race_code"]].append(row)

    batches: dict[str, RaceBatch] = {}
    for month, races in sorted(grouped.items()):
        codes: list[str] = []
        places: list[str] = []
        courses: list[list[int]] = []
        labels: list[list[int]] = []
        probabilities: list[list[float]] = []
        for race_code, rows in sorted(races.items()):
            ordered = sorted(rows, key=lambda row: int(row["course"]))
            if len(ordered) != 6:
                raise RuntimeError(f"{race_code}: 6艇ではありません")
            codes.append(race_code)
            places.append(ordered[0]["place"])
            courses.append([int(row["course"]) for row in ordered])
            labels.append([int(row["label"]) for row in ordered])
            probabilities.append([float(row["baseline"]) for row in ordered])
        batch = RaceBatch(
            month=month,
            race_codes=np.asarray(codes),
            places=np.asarray(places),
            courses=np.asarray(courses, dtype=np.int8),
            labels=np.asarray(labels, dtype=np.int8),
            probabilities=np.asarray(probabilities, dtype=np.float64),
        )
        if np.any(batch.labels.sum(axis=1) != 1):
            raise RuntimeError(f"{month}: 勝者が1艇でないレースがあります")
        batches[month] = batch
    return batches


def combine_batches(batches: list[RaceBatch]) -> RaceBatch:
    return RaceBatch(
        month=f"{batches[0].month}..{batches[-1].month}",
        race_codes=np.concatenate([batch.race_codes for batch in batches]),
        places=np.concatenate([batch.places for batch in batches]),
        courses=np.concatenate([batch.courses for batch in batches]),
        labels=np.concatenate([batch.labels for batch in batches]),
        probabilities=np.concatenate([batch.probabilities for batch in batches]),
    )


def normalize(values: np.ndarray) -> np.ndarray:
    clean = np.maximum(np.asarray(values, dtype=np.float64), 1e-12)
    return clean / clean.sum(axis=1, keepdims=True)


def learn_course_factors(batch: RaceBatch, strength: float) -> np.ndarray:
    factors = np.ones(7, dtype=np.float64)
    for course in range(1, 7):
        selected = batch.courses == course
        n = int(selected.sum())
        predicted_mean = float(batch.probabilities[selected].mean())
        wins = float(batch.labels[selected].sum())
        shrunk_rate = (wins + strength * predicted_mean) / (n + strength)
        factors[course] = shrunk_rate / predicted_mean
    return factors


def apply_course(probabilities: np.ndarray, courses: np.ndarray, factors: np.ndarray) -> np.ndarray:
    return normalize(probabilities * factors[courses])


def optimal_power(probabilities: np.ndarray, labels: np.ndarray) -> float:
    winner_indices = np.argmax(labels, axis=1)
    rows = np.arange(len(probabilities))

    def objective(log_power: float) -> float:
        adjusted = normalize(probabilities ** float(np.exp(log_power)))
        return float(-np.mean(np.log(np.maximum(adjusted[rows, winner_indices], 1e-12))))

    result = minimize_scalar(
        objective, bounds=(np.log(0.40), np.log(2.50)), method="bounded"
    )
    return float(np.exp(result.x))


def learn_venue_powers(
    batch: RaceBatch,
    probabilities: np.ndarray,
    strength: float,
) -> dict[str, float]:
    result: dict[str, float] = {}
    for place in np.unique(batch.places):
        selected = batch.places == place
        n = int(selected.sum())
        raw_power = optimal_power(probabilities[selected], batch.labels[selected])
        credibility = n / (n + strength)
        result[str(place)] = float(np.exp(credibility * np.log(raw_power)))
    return result


def apply_venue(probabilities: np.ndarray, places: np.ndarray, powers: dict[str, float]) -> np.ndarray:
    result = np.empty_like(probabilities)
    for place in np.unique(places):
        selected = places == place
        result[selected] = normalize(probabilities[selected] ** powers.get(str(place), 1.0))
    return result


def ece(probability: np.ndarray, labels: np.ndarray, bins: np.ndarray) -> float:
    flat_p = probability.ravel()
    flat_y = labels.ravel()
    total = len(flat_p)
    weighted_gap = 0.0
    for low, high in zip(bins[:-1], bins[1:]):
        selected = (flat_p >= low) & (flat_p < high)
        n = int(selected.sum())
        if n:
            weighted_gap += n * abs(float(flat_p[selected].mean() - flat_y[selected].mean()))
    return weighted_gap / total


def score(probability: np.ndarray, labels: np.ndarray) -> dict:
    rows = np.arange(len(probability))
    top = np.argmax(probability, axis=1)
    winner = np.argmax(labels, axis=1)
    top_probability = probability[rows, top].reshape(-1, 1)
    top_labels = labels[rows, top].reshape(-1, 1)
    return {
        "races": len(probability),
        "hits": int(labels[rows, top].sum()),
        "top1_rate": float(labels[rows, top].mean()),
        "brier": float(np.mean((probability - labels) ** 2)),
        "winner_nll": float(-np.mean(np.log(np.maximum(probability[rows, winner], 1e-12)))),
        "ece": ece(probability, labels, CALIBRATION_BINS),
        "top1_ece": ece(top_probability, top_labels, TOP1_BINS),
        "mean_top1_probability": float(top_probability.mean()),
    }


def candidate_names() -> list[str]:
    names = ["baseline"]
    names.extend(f"course_s{strength}" for strength in COURSE_STRENGTHS)
    names.extend(f"venue_power_s{strength}" for strength in VENUE_STRENGTHS)
    names.extend(f"course_s{course}_venue_s{venue}" for course, venue in COMBINATIONS)
    return names


def make_predictions(calibration: RaceBatch, test: RaceBatch) -> tuple[dict[str, np.ndarray], dict]:
    result = {"baseline": test.probabilities}
    learned: dict[str, dict] = {"course_factors": {}, "venue_powers": {}}

    course_calibration: dict[int, np.ndarray] = {}
    course_test: dict[int, np.ndarray] = {}
    for strength in COURSE_STRENGTHS:
        factors = learn_course_factors(calibration, strength)
        course_calibration[strength] = apply_course(
            calibration.probabilities, calibration.courses, factors
        )
        course_test[strength] = apply_course(test.probabilities, test.courses, factors)
        result[f"course_s{strength}"] = course_test[strength]
        learned["course_factors"][str(strength)] = factors[1:].tolist()

    for strength in VENUE_STRENGTHS:
        powers = learn_venue_powers(calibration, calibration.probabilities, strength)
        result[f"venue_power_s{strength}"] = apply_venue(
            test.probabilities, test.places, powers
        )
        learned["venue_powers"][f"baseline_s{strength}"] = powers

    for course_strength, venue_strength in COMBINATIONS:
        powers = learn_venue_powers(
            calibration, course_calibration[course_strength], venue_strength
        )
        name = f"course_s{course_strength}_venue_s{venue_strength}"
        result[name] = apply_venue(course_test[course_strength], test.places, powers)
        learned["venue_powers"][name] = powers
    return result, learned


def aggregate(parts: dict[str, list[tuple[np.ndarray, np.ndarray]]]) -> dict[str, dict]:
    return {
        name: score(
            np.concatenate([item[0] for item in values]),
            np.concatenate([item[1] for item in values]),
        )
        for name, values in parts.items()
    }


def choose_candidate(results: dict[str, dict], months: dict[str, dict]) -> str:
    baseline = results["baseline"]
    eligible: list[str] = []
    for name, item in results.items():
        if name == "baseline":
            continue
        brier_wins = sum(
            month[name]["brier"] < month["baseline"]["brier"]
            for month in months.values()
        )
        if (
            item["brier"] < baseline["brier"]
            and item["winner_nll"] < baseline["winner_nll"]
            and brier_wins >= (len(months) + 1) // 2
        ):
            eligible.append(name)
    return min(eligible, key=lambda name: results[name]["brier"], default="baseline")


def paired_bootstrap(
    baseline_parts: list[tuple[np.ndarray, np.ndarray]],
    candidate_parts: list[tuple[np.ndarray, np.ndarray]],
    samples: int = 10_000,
) -> dict[str, dict]:
    baseline = np.concatenate([item[0] for item in baseline_parts])
    candidate = np.concatenate([item[0] for item in candidate_parts])
    labels = np.concatenate([item[1] for item in baseline_parts])
    rows = np.arange(len(labels))
    winner = np.argmax(labels, axis=1)
    baseline_top = np.argmax(baseline, axis=1)
    candidate_top = np.argmax(candidate, axis=1)
    differences = {
        "brier": np.mean((candidate - labels) ** 2 - (baseline - labels) ** 2, axis=1),
        "winner_nll": (
            -np.log(np.maximum(candidate[rows, winner], 1e-12))
            + np.log(np.maximum(baseline[rows, winner], 1e-12))
        ),
        "hit_rate": (
            labels[rows, candidate_top] - labels[rows, baseline_top]
        ).astype(np.float64),
    }
    rng = np.random.default_rng(20260924)
    result: dict[str, dict] = {}
    for name, values in differences.items():
        means = np.empty(samples, dtype=np.float64)
        for index in range(samples):
            selected = rng.integers(0, len(values), len(values))
            means[index] = float(values[selected].mean())
        low, high = np.quantile(means, [0.025, 0.975])
        result[name] = {
            "difference": float(values.mean()),
            "ci95_low": float(low),
            "ci95_high": float(high),
            "probability_improves": float(np.mean(means < 0 if name != "hit_rate" else means > 0)),
        }
    return result


def plot_monthly(path: Path, month_results: dict[str, dict], names: list[str]) -> None:
    os.environ.setdefault("MPLCONFIGDIR", "/tmp/boatrace-matplotlib")
    import matplotlib.pyplot as plt

    months = list(month_results)
    fig, axis = plt.subplots(figsize=(11, 5.5))
    for name in names:
        delta = [
            (month_results[month][name]["brier"] - month_results[month]["baseline"]["brier"])
            * 1_000_000
            for month in months
        ]
        axis.plot(months, delta, marker="o", linewidth=1.8, label=name)
    axis.axhline(0, color="#777777", linestyle="--", linewidth=1)
    axis.set_title("Segment calibration: monthly Brier delta vs current v4")
    axis.set_ylabel("Brier delta × 1,000,000 (lower is better)")
    axis.set_xlabel("Evaluation month")
    axis.grid(alpha=0.25)
    axis.legend(fontsize=8)
    fig.tight_layout()
    path.parent.mkdir(parents=True, exist_ok=True)
    fig.savefig(path, dpi=160, bbox_inches="tight")
    plt.close(fig)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", type=Path, default=DEFAULT_INPUT)
    parser.add_argument("--output-prefix", type=Path, default=DEFAULT_OUTPUT)
    parser.add_argument("--history-months", type=int, default=3)
    args = parser.parse_args()
    if args.history_months < 1:
        raise ValueError("history-months は1以上が必要です")

    print(f"予測キャッシュを読み込んでいます: {args.input}", flush=True)
    batches = load_batches(args.input)
    month_names = sorted(batches)
    if len(month_names) < 2:
        raise RuntimeError("前方検証には2か月以上必要です")

    names = candidate_names()
    parts: dict[str, list[tuple[np.ndarray, np.ndarray]]] = {name: [] for name in names}
    month_results: dict[str, dict] = {}
    learned_by_month: dict[str, dict] = {}
    for index, month in enumerate(month_names[1:], start=1):
        history_names = month_names[max(0, index - args.history_months):index]
        calibration = combine_batches([batches[name] for name in history_names])
        test = batches[month]
        print(f"{month}: 補正学習 {history_names[0]}～{history_names[-1]}", flush=True)
        predictions, learned = make_predictions(calibration, test)
        month_results[month] = {
            name: score(probability, test.labels)
            for name, probability in predictions.items()
        }
        learned_by_month[month] = learned
        for name in names:
            parts[name].append((predictions[name], test.labels))

    results = aggregate(parts)
    evaluation_months = list(month_results)
    split_index = len(evaluation_months) // 2
    development_months = evaluation_months[:split_index]
    holdout_months = evaluation_months[split_index:]
    development_parts = {
        name: values[:split_index] for name, values in parts.items()
    }
    holdout_parts = {
        name: values[split_index:] for name, values in parts.items()
    }
    development_results = aggregate(development_parts)
    holdout_results = aggregate(holdout_parts)
    selected = choose_candidate(
        development_results,
        {month: month_results[month] for month in development_months},
    )
    holdout_confirmed = (
        selected != "baseline"
        and holdout_results[selected]["brier"] < holdout_results["baseline"]["brier"]
        and holdout_results[selected]["winner_nll"] < holdout_results["baseline"]["winner_nll"]
    )
    uncertainty = (
        paired_bootstrap(holdout_parts["baseline"], holdout_parts[selected])
        if selected != "baseline"
        else {}
    )
    baseline = results["baseline"]
    ranking = sorted(
        (
            {
                "name": name,
                **item,
                "brier_delta": item["brier"] - baseline["brier"],
                "nll_delta": item["winner_nll"] - baseline["winner_nll"],
                "brier_month_wins": sum(
                    month[name]["brier"] < month["baseline"]["brier"]
                    for month in month_results.values()
                ),
            }
            for name, item in results.items()
        ),
        key=lambda item: (item["brier"], item["winner_nll"]),
    )
    report = {
        "configuration": {
            "input": str(args.input),
            "history_months": args.history_months,
            "evaluation_months": month_names[1:],
            "excluded_initial_month": month_names[0],
            "note": "Every correction is learned from prior out-of-fold months only.",
        },
        "development_months": development_months,
        "holdout_months": holdout_months,
        "selected_on_development": selected,
        "holdout_confirmed": holdout_confirmed,
        "decision": (
            "candidate_needs_new_forward_data"
            if holdout_confirmed
            else "keep_baseline"
        ),
        "holdout_uncertainty": uncertainty,
        "ranking": ranking,
        "results": results,
        "development_results": development_results,
        "holdout_results": holdout_results,
        "months": month_results,
        "learned": learned_by_month,
    }
    json_path = args.output_prefix.with_suffix(".json")
    plot_path = args.output_prefix.with_suffix(".png")
    json_path.parent.mkdir(parents=True, exist_ok=True)
    json_path.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")

    display_names = ["course_s100", "venue_power_s1000", "course_s500_venue_s1000"]
    plot_monthly(plot_path, month_results, display_names)

    print("\n【2026-02～09 前方検証】")
    print("方式                              Brier       ΔBrier      勝者NLL      ΔNLL    舟ECE  月勝")
    print("-" * 112)
    for item in ranking:
        print(
            f"{item['name']:<32} {item['brier']:.9f}  {item['brier_delta']:+.9f}  "
            f"{item['winner_nll']:.9f}  {item['nll_delta']:+.9f}  "
            f"{item['ece']*100:>5.3f}pt  {item['brier_month_wins']}/{len(month_results)}"
        )
    print(f"\n開発期間 {development_months[0]}～{development_months[-1]} で選択: {selected}")
    print(f"未使用確認期間 {holdout_months[0]}～{holdout_months[-1]}: " + ("改善を確認" if holdout_confirmed else "改善せず"))
    if uncertainty:
        for name, item in uncertainty.items():
            print(
                f"  {name}: 差 {item['difference']:+.9f} / "
                f"95%CI [{item['ci95_low']:+.9f}, {item['ci95_high']:+.9f}] / "
                f"改善確率 {item['probability_improves']*100:.1f}%"
            )
    print("判定: 新しい前方期間で再確認するv4.1候補（本番は現行維持）")
    print(f"JSON: {json_path}")
    print(f"月別グラフ: {plot_path}")
    print(
        "RESULT_JSON="
        + json.dumps(
            {
                "selected": selected,
                "holdout_confirmed": holdout_confirmed,
                "holdout_uncertainty": uncertainty,
                "ranking": ranking,
            },
            ensure_ascii=False,
        )
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
