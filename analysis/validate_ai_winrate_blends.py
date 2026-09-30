#!/usr/bin/env python3
"""Validate fixed pairwise AI win-rate blends using VALID-selected weights only.

This analysis consumes the prediction stage artifacts produced by
``compare_ai_winrate_systems.py``.  It does not fit a model, alter a production
artifact, or use TEST to select a blend weight.
"""

from __future__ import annotations

import csv
import gzip
import hashlib
import json
import math
import platform
import resource
import subprocess
import time
from collections import defaultdict
from pathlib import Path

import numpy as np


ROOT = Path(__file__).resolve().parents[1]
OUTPUT = ROOT / "analysis" / "output"
STAGE_DIR = Path("/tmp/boatrace-ai-winrate-system-compare")
SOURCE_STEM = "ai_winrate_system_compare_asy_amg_20260927"
OUTPUT_STEM = "ai_winrate_blend_validation_asy_amg_20260927"
SEED = 428
BOOTSTRAP_RESAMPLES = 5_000
TIE_TOLERANCE = 1e-10
WEIGHTS = tuple(round(value * 0.05, 2) for value in range(21))

MODELS = (
    "v6_pre_course",
    "v6_final",
    "ebm_course_first",
    "autogluon_course_first",
    "ngboost_course_first",
)

BLENDS = (
    ("v6_pre_course__v6_final", "v6_pre_course", "v6_final"),
    ("v6_pre_course__ebm", "v6_pre_course", "ebm_course_first"),
    ("v6_final__ebm", "v6_final", "ebm_course_first"),
    ("v6_pre_course__autogluon", "v6_pre_course", "autogluon_course_first"),
    ("v6_final__autogluon", "v6_final", "autogluon_course_first"),
    ("v6_pre_course__ngboost", "v6_pre_course", "ngboost_course_first"),
    ("v6_final__ngboost", "v6_final", "ngboost_course_first"),
)


def read_csv(path: Path) -> list[dict[str, str]]:
    opener = gzip.open if path.suffix == ".gz" else open
    with opener(path, "rt", encoding="utf-8", newline="") as handle:
        return list(csv.DictReader(handle))


def write_csv(path: Path, rows: list[dict]) -> None:
    if not rows:
        raise RuntimeError(f"empty output: {path}")
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=list(rows[0]), lineterminator="\n")
        writer.writeheader()
        writer.writerows(rows)


def git_sha() -> str:
    return subprocess.check_output(
        ["git", "rev-parse", "HEAD"], cwd=ROOT, text=True
    ).strip()


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def normalized(values: np.ndarray) -> np.ndarray:
    values = np.maximum(np.asarray(values, dtype=float), 1e-12)
    total = float(values.sum())
    if not math.isfinite(total) or total <= 0:
        raise RuntimeError("invalid probability sum")
    return values / total


def load_common_predictions() -> tuple[dict[str, list[dict]], dict]:
    paths = {
        name: STAGE_DIR / f"{name}.csv" for name in ("core", "agng", "v6")
    }
    missing = [str(path) for path in paths.values() if not path.exists()]
    if missing:
        raise RuntimeError(
            "VALID prediction artifacts are missing; rerun the corresponding "
            f"stages of compare_ai_winrate_systems.py first: {missing}"
        )

    tables = {name: read_csv(path) for name, path in paths.items()}
    indexed: dict[str, dict[tuple[str, str, int], dict]] = {}
    for name, rows in tables.items():
        table = {}
        for row in rows:
            key = (row["split"], row["race_code"], int(row["course"]))
            if key in table:
                raise RuntimeError(f"duplicate stage key in {name}: {key}")
            table[key] = row
        indexed[name] = table

    common = set.intersection(*(set(table) for table in indexed.values()))
    rows: list[dict] = []
    for key in sorted(common):
        core = indexed["core"][key]
        agng = indexed["agng"][key]
        v6 = indexed["v6"][key]
        labels = {int(core["actual_win"]), int(agng["actual_win"]), int(v6["actual_win"])}
        if len(labels) != 1:
            raise RuntimeError(f"label mismatch: {key}")
        rows.append(
            {
                "split": key[0],
                "race_code": key[1],
                "date": v6["date"],
                "place": v6["place"],
                "race_number": int(v6["race_number"]),
                "boat_number": int(v6["boat_number"]),
                "course": key[2],
                "actual_win": labels.pop(),
                "v6_pre_course": float(v6["v6_pre_course"]),
                "v6_final": float(v6["v6_final"]),
                "ebm_course_first": float(core["ebm_course_first"]),
                "autogluon_course_first": float(agng["autogluon_course_first"]),
                "ngboost_course_first": float(agng["ngboost_course_first"]),
            }
        )

    grouped: dict[tuple[str, str], list[dict]] = defaultdict(list)
    for row in rows:
        grouped[(row["split"], row["race_code"])].append(row)

    complete: dict[str, list[dict]] = {"valid": [], "test": []}
    dropped = 0
    for (split, _race_code), race_rows in grouped.items():
        if split not in complete:
            continue
        if len(race_rows) != 6 or sum(row["actual_win"] for row in race_rows) != 1:
            dropped += 1
            continue
        race_rows.sort(key=lambda row: row["course"])
        for model in ("ebm_course_first", "autogluon_course_first", "ngboost_course_first"):
            probabilities = normalized(np.asarray([row[model] for row in race_rows]))
            for row, probability in zip(race_rows, probabilities):
                row[model] = float(probability)
        complete[split].extend(race_rows)

    for split, split_rows in complete.items():
        seen = set()
        for row in split_rows:
            key = (row["race_code"], row["boat_number"])
            if key in seen:
                raise RuntimeError(f"duplicate race/boat in {split}: {key}")
            seen.add(key)
            for model in MODELS:
                if not math.isfinite(float(row[model])):
                    raise RuntimeError(f"NaN/Inf in {split} {model}: {key}")

    return complete, {
        "stage_files": {name: {"path": str(path), "sha256": sha256(path)} for name, path in paths.items()},
        "dropped_incomplete_races": dropped,
    }


def by_race(rows: list[dict]) -> list[list[dict]]:
    grouped: dict[str, list[dict]] = defaultdict(list)
    for row in rows:
        grouped[row["race_code"]].append(row)
    races = []
    for race_code in sorted(grouped):
        race_rows = sorted(grouped[race_code], key=lambda row: row["course"])
        if len(race_rows) != 6 or sum(row["actual_win"] for row in race_rows) != 1:
            raise RuntimeError(f"invalid race: {race_code}")
        races.append(race_rows)
    return races


def probability_matrix(races: list[list[dict]], model: str) -> np.ndarray:
    return np.asarray([[float(row[model]) for row in race] for race in races])


def target_matrix(races: list[list[dict]]) -> np.ndarray:
    return np.asarray([[int(row["actual_win"]) for row in race] for race in races], dtype=np.int8)


def blend_matrix(base: np.ndarray, challenger: np.ndarray, weight: float) -> np.ndarray:
    blend = (1.0 - weight) * base + weight * challenger
    blend = np.maximum(blend, 1e-12)
    return blend / blend.sum(axis=1, keepdims=True)


def metrics(probability: np.ndarray, target: np.ndarray) -> dict[str, float | int]:
    if probability.shape != target.shape or probability.shape[1] != 6:
        raise RuntimeError("metrics require one six-boat row per race")
    winner_probability = probability[target.astype(bool)]
    race_brier = np.mean((probability - target) ** 2, axis=1)
    return {
        "races": int(len(probability)),
        "race_brier_mean": float(race_brier.mean()),
        "race_brier_sum": float((race_brier * 6).mean()),
        "winner_log_loss": float(-np.log(np.maximum(winner_probability, 1e-12)).mean()),
        "top1_accuracy": float((probability.argmax(axis=1) == target.argmax(axis=1)).mean()),
        "actual_winner_probability_mean": float(winner_probability.mean()),
        "probability_sum_mean": float(probability.sum(axis=1).mean()),
        "probability_sum_max_abs_error": float(np.abs(probability.sum(axis=1) - 1).max()),
    }


def previous_overall() -> dict[tuple[str, str], dict]:
    rows = read_csv(OUTPUT / f"{SOURCE_STEM}_summary.csv")
    return {
        (row["split"], row["model"]): row
        for row in rows
        if row["scope"] == "overall" and row["model"] in MODELS
    }


def audit_predictions(data: dict[str, list[dict]]) -> dict:
    previous = previous_overall()
    checks = []
    maximum_metric_difference = 0.0
    maximum_probability_difference = 0.0
    maximum_probability_sum_error = 0.0
    for split in ("valid", "test"):
        races = by_race(data[split])
        target = target_matrix(races)
        for model in MODELS:
            probability = probability_matrix(races, model)
            current = metrics(probability, target)
            maximum_probability_sum_error = max(
                maximum_probability_sum_error,
                float(current["probability_sum_max_abs_error"]),
            )
            expected = previous[(split, model)]
            for field in (
                "race_brier_mean",
                "race_brier_sum",
                "winner_log_loss",
                "top1_accuracy",
                "actual_winner_probability_mean",
            ):
                difference = abs(float(current[field]) - float(expected[field]))
                maximum_metric_difference = max(maximum_metric_difference, difference)
                if difference > 2e-12:
                    raise RuntimeError(
                        f"previous summary mismatch: {split} {model} {field} {difference}"
                    )
            checks.append({"split": split, "model": model, **current})

    # The saved v6 probabilities include float32 model output; sub-micro errors
    # are expected and are normalized again before every blend.
    if maximum_probability_sum_error > 1e-6:
        raise RuntimeError(f"race probability sum mismatch: {maximum_probability_sum_error}")

    committed = read_csv(OUTPUT / f"{SOURCE_STEM}_predictions.csv.gz")
    committed_index = {
        (row["race_code"], int(row["boat_number"])): row for row in committed
    }
    for row in data["test"]:
        old = committed_index[(row["race_code"], row["boat_number"])]
        for model in MODELS:
            difference = abs(float(row[model]) - float(old[f"{model}_normalized_probability"]))
            maximum_probability_difference = max(maximum_probability_difference, difference)
            if difference > 2e-12:
                raise RuntimeError(f"committed TEST prediction mismatch: {model}")

    return {
        "valid_races": len(data["valid"]) // 6,
        "test_races": len(data["test"]) // 6,
        "boats_per_race": 6,
        "winner_count_per_race": 1,
        "duplicate_race_boat_rows": 0,
        "nan_or_inf": 0,
        "maximum_summary_metric_difference": maximum_metric_difference,
        "maximum_committed_test_probability_difference": maximum_probability_difference,
        "maximum_probability_sum_error": maximum_probability_sum_error,
        "checks": checks,
    }


def choose_weight(grid_rows: list[dict]) -> dict:
    minimum_brier = min(float(row["valid_brier"]) for row in grid_rows)
    candidates = [
        row for row in grid_rows
        if float(row["valid_brier"]) <= minimum_brier + TIE_TOLERANCE
    ]
    minimum_log_loss = min(float(row["valid_log_loss"]) for row in candidates)
    candidates = [
        row for row in candidates
        if float(row["valid_log_loss"]) <= minimum_log_loss + TIE_TOLERANCE
    ]
    return min(candidates, key=lambda row: float(row["weight"]))


def paired_bootstrap(
    blend: np.ndarray,
    baseline: np.ndarray,
    target: np.ndarray,
    seed: int,
) -> dict:
    blend_error = np.mean((blend - target) ** 2, axis=1)
    baseline_error = np.mean((baseline - target) ** 2, axis=1)
    race_delta = blend_error - baseline_error
    rng = np.random.default_rng(seed)
    sample_means = np.empty(BOOTSTRAP_RESAMPLES, dtype=float)
    batch = 250
    for start in range(0, BOOTSTRAP_RESAMPLES, batch):
        count = min(batch, BOOTSTRAP_RESAMPLES - start)
        indices = rng.integers(0, len(race_delta), size=(count, len(race_delta)))
        sample_means[start:start + count] = race_delta[indices].mean(axis=1)
    return {
        "mean_delta": float(race_delta.mean()),
        "median_delta": float(np.median(sample_means)),
        "ci95_lower": float(np.quantile(sample_means, 0.025)),
        "ci95_upper": float(np.quantile(sample_means, 0.975)),
        "bootstrap_improvement_fraction": float(np.mean(sample_means < 0)),
    }


def subset_indices(races: list[list[dict]], field: str, value: str | int) -> np.ndarray:
    return np.asarray(
        [index for index, race in enumerate(races) if race[0][field] == value],
        dtype=int,
    )


def main() -> None:
    started = time.perf_counter()
    data, source_audit = load_common_predictions()
    audit = audit_predictions(data)
    race_data = {split: by_race(rows) for split, rows in data.items()}
    targets = {split: target_matrix(races) for split, races in race_data.items()}
    matrices = {
        split: {model: probability_matrix(races, model) for model in MODELS}
        for split, races in race_data.items()
    }

    weight_grid: list[dict] = []
    selected: dict[str, dict] = {}
    for blend_name, baseline, challenger in BLENDS:
        for weight in WEIGHTS:
            probability = blend_matrix(
                matrices["valid"][baseline], matrices["valid"][challenger], weight
            )
            result = metrics(probability, targets["valid"])
            weight_grid.append(
                {
                    "blend_name": blend_name,
                    "baseline": baseline,
                    "challenger": challenger,
                    "weight": weight,
                    "valid_brier": result["race_brier_mean"],
                    "valid_log_loss": result["winner_log_loss"],
                    "valid_top1": result["top1_accuracy"],
                    "winner_probability_mean": result["actual_winner_probability_mean"],
                    "probability_sum_max_abs_error": result["probability_sum_max_abs_error"],
                }
            )
        blend_rows = [row for row in weight_grid if row["blend_name"] == blend_name]
        selected[blend_name] = choose_weight(blend_rows)

    summary: list[dict] = []
    monthly: list[dict] = []
    course: list[dict] = []
    bootstrap: list[dict] = []
    selected_predictions: dict[tuple[str, str], np.ndarray] = {}

    for blend_index, (blend_name, baseline, challenger) in enumerate(BLENDS):
        weight = float(selected[blend_name]["weight"])
        blend_grid = [row for row in weight_grid if row["blend_name"] == blend_name]
        valid_baseline_brier = metrics(
            matrices["valid"][baseline], targets["valid"]
        )["race_brier_mean"]
        improving = [
            float(row["weight"]) for row in blend_grid
            if float(row["valid_brier"]) < float(valid_baseline_brier) - TIE_TOLERANCE
        ]
        neighbors = [
            row for row in blend_grid
            if abs(float(row["weight"]) - weight) <= 0.1000001
        ]
        neighbor_deltas = {
            f"w_{float(row['weight']):.2f}": float(row["valid_brier"]) - float(valid_baseline_brier)
            for row in neighbors
        }

        split_results = {}
        for split_index, split in enumerate(("valid", "test")):
            probability = blend_matrix(
                matrices[split][baseline], matrices[split][challenger], weight
            )
            selected_predictions[(blend_name, split)] = probability
            blend_metrics = metrics(probability, targets[split])
            baseline_metrics = metrics(matrices[split][baseline], targets[split])
            split_results[split] = (blend_metrics, baseline_metrics)
            bootstrap.append(
                {
                    "blend_name": blend_name,
                    "baseline": baseline,
                    "challenger": challenger,
                    "selected_weight": weight,
                    "split": split,
                    "races": len(probability),
                    "resamples": BOOTSTRAP_RESAMPLES,
                    "seed": SEED + blend_index * 10 + split_index,
                    **paired_bootstrap(
                        probability,
                        matrices[split][baseline],
                        targets[split],
                        SEED + blend_index * 10 + split_index,
                    ),
                }
            )

        valid_metrics, valid_baseline = split_results["valid"]
        test_metrics, test_baseline = split_results["test"]
        valid_challenger = metrics(matrices["valid"][challenger], targets["valid"])
        test_challenger = metrics(matrices["test"][challenger], targets["test"])
        summary.append(
            {
                "row_type": "blend",
                "blend_name": blend_name,
                "baseline": baseline,
                "challenger": challenger,
                "selected_weight_valid_only": weight,
                "valid_brier": valid_metrics["race_brier_mean"],
                "valid_baseline_brier": valid_baseline["race_brier_mean"],
                "valid_challenger_brier": valid_challenger["race_brier_mean"],
                "valid_brier_delta": valid_metrics["race_brier_mean"] - valid_baseline["race_brier_mean"],
                "valid_log_loss": valid_metrics["winner_log_loss"],
                "valid_top1": valid_metrics["top1_accuracy"],
                "test_brier": test_metrics["race_brier_mean"],
                "test_baseline_brier": test_baseline["race_brier_mean"],
                "test_challenger_brier": test_challenger["race_brier_mean"],
                "test_brier_delta": test_metrics["race_brier_mean"] - test_baseline["race_brier_mean"],
                "test_log_loss": test_metrics["winner_log_loss"],
                "test_top1": test_metrics["top1_accuracy"],
                "test_winner_probability_mean": test_metrics["actual_winner_probability_mean"],
                "valid_improving_weight_min": min(improving) if improving else "",
                "valid_improving_weight_max": max(improving) if improving else "",
                "valid_improving_grid_points": len(improving),
                "weight_stability": "unstable" if len(improving) <= 1 else ("narrow" if len(improving) <= 2 else ("moderate" if len(improving) <= 4 else "broad")),
                "valid_probability_sum_max_abs_error": valid_metrics["probability_sum_max_abs_error"],
                "test_probability_sum_max_abs_error": test_metrics["probability_sum_max_abs_error"],
                "selected_neighborhood_brier_deltas_json": json.dumps(neighbor_deltas, sort_keys=True),
            }
        )

        test_races = race_data["test"]
        test_probability = selected_predictions[(blend_name, "test")]
        for month in sorted({race[0]["date"][:7] for race in test_races}):
            indices = np.asarray(
                [i for i, race in enumerate(test_races) if race[0]["date"][:7] == month],
                dtype=int,
            )
            blend_metrics = metrics(test_probability[indices], targets["test"][indices])
            baseline_metrics = metrics(
                matrices["test"][baseline][indices], targets["test"][indices]
            )
            monthly.append(
                {
                    "blend_name": blend_name,
                    "baseline": baseline,
                    "challenger": challenger,
                    "selected_weight": weight,
                    "month": month,
                    "races": len(indices),
                    "brier": blend_metrics["race_brier_mean"],
                    "baseline_brier": baseline_metrics["race_brier_mean"],
                    "brier_delta": blend_metrics["race_brier_mean"] - baseline_metrics["race_brier_mean"],
                    "log_loss": blend_metrics["winner_log_loss"],
                    "top1": blend_metrics["top1_accuracy"],
                }
            )

        for course_number in range(1, 7):
            column = course_number - 1
            blend_values = test_probability[:, column]
            baseline_values = matrices["test"][baseline][:, column]
            target_values = targets["test"][:, column]
            course.append(
                {
                    "blend_name": blend_name,
                    "baseline": baseline,
                    "challenger": challenger,
                    "selected_weight": weight,
                    "course": course_number,
                    "races": len(test_probability),
                    "per_boat_brier": float(np.mean((blend_values - target_values) ** 2)),
                    "baseline_per_boat_brier": float(np.mean((baseline_values - target_values) ** 2)),
                    "brier_delta": float(np.mean((blend_values - target_values) ** 2) - np.mean((baseline_values - target_values) ** 2)),
                }
            )

    venue_rows = []
    for blend_name, baseline, challenger in BLENDS:
        weight = float(selected[blend_name]["weight"])
        probability = selected_predictions[(blend_name, "test")]
        for venue in ("ASY", "AMG"):
            indices = subset_indices(race_data["test"], "place", venue)
            blend_metrics = metrics(probability[indices], targets["test"][indices])
            baseline_metrics = metrics(
                matrices["test"][baseline][indices], targets["test"][indices]
            )
            venue_rows.append(
                {
                    "blend_name": blend_name,
                    "venue": venue,
                    "races": len(indices),
                    "brier": blend_metrics["race_brier_mean"],
                    "baseline_brier": baseline_metrics["race_brier_mean"],
                    "brier_delta": blend_metrics["race_brier_mean"] - baseline_metrics["race_brier_mean"],
                    "log_loss": blend_metrics["winner_log_loss"],
                    "top1": blend_metrics["top1_accuracy"],
                }
            )

    experiment = {
        "experiment": "ai_winrate_v6_pairwise_blend_validation",
        "created_at": time.strftime("%Y-%m-%dT%H:%M:%S%z"),
        "git_sha_before_analysis": git_sha(),
        "source_prediction_commit": "98cd3d2d6addc140d504670463ab07122ed69190",
        "analysis_script_sha256": sha256(Path(__file__)),
        "environment": {
            "python": platform.python_version(),
            "numpy": np.__version__,
        },
        "source_prediction_artifacts": source_audit,
        "period": {
            "valid": "2025-09-01 to 2026-02-28",
            "test": "2026-03-01 to 2026-09-27",
        },
        "places": ["ASY", "AMG"],
        "models": list(MODELS),
        "blends": [
            {"blend_name": name, "baseline": base, "challenger": challenger}
            for name, base, challenger in BLENDS
        ],
        "formula": "normalize((1-w)*baseline_normalized_probability + w*challenger_normalized_probability)",
        "weight_grid": list(WEIGHTS),
        "selection": "minimum VALID race-level Brier mean/boat; tie by VALID winner log loss, then smaller challenger weight",
        "tie_tolerance": TIE_TOLERANCE,
        "test_used_for_weight_selection": False,
        "bootstrap": {
            "unit": "race",
            "paired": True,
            "resamples": BOOTSTRAP_RESAMPLES,
            "base_seed": SEED,
        },
        "prediction_audit": audit,
        "selected_weights": {
            name: float(row["weight"]) for name, row in selected.items()
        },
        "venue_diagnostics": venue_rows,
        "runtime_seconds": time.perf_counter() - started,
        "max_rss_kb": resource.getrusage(resource.RUSAGE_SELF).ru_maxrss,
        "production_changed": False,
    }

    summary_output = list(summary)
    for model in ("v6_pre_course", "v6_final"):
        valid_metrics = metrics(matrices["valid"][model], targets["valid"])
        test_metrics = metrics(matrices["test"][model], targets["test"])
        summary_output.append(
            {
                "row_type": "baseline",
                "blend_name": model,
                "baseline": model,
                "challenger": "",
                "selected_weight_valid_only": 0.0,
                "valid_brier": valid_metrics["race_brier_mean"],
                "valid_baseline_brier": valid_metrics["race_brier_mean"],
                "valid_challenger_brier": "",
                "valid_brier_delta": 0.0,
                "valid_log_loss": valid_metrics["winner_log_loss"],
                "valid_top1": valid_metrics["top1_accuracy"],
                "test_brier": test_metrics["race_brier_mean"],
                "test_baseline_brier": test_metrics["race_brier_mean"],
                "test_challenger_brier": "",
                "test_brier_delta": 0.0,
                "test_log_loss": test_metrics["winner_log_loss"],
                "test_top1": test_metrics["top1_accuracy"],
                "test_winner_probability_mean": test_metrics["actual_winner_probability_mean"],
                "valid_improving_weight_min": "",
                "valid_improving_weight_max": "",
                "valid_improving_grid_points": 0,
                "weight_stability": "baseline",
                "valid_probability_sum_max_abs_error": valid_metrics["probability_sum_max_abs_error"],
                "test_probability_sum_max_abs_error": test_metrics["probability_sum_max_abs_error"],
                "selected_neighborhood_brier_deltas_json": "{}",
            }
        )

    write_csv(OUTPUT / f"{OUTPUT_STEM}_weight_grid.csv", weight_grid)
    write_csv(OUTPUT / f"{OUTPUT_STEM}_summary.csv", summary_output)
    write_csv(OUTPUT / f"{OUTPUT_STEM}_monthly.csv", monthly)
    write_csv(OUTPUT / f"{OUTPUT_STEM}_course.csv", course)
    write_csv(OUTPUT / f"{OUTPUT_STEM}_venue.csv", venue_rows)
    write_csv(OUTPUT / f"{OUTPUT_STEM}_bootstrap.csv", bootstrap)
    (OUTPUT / f"{OUTPUT_STEM}_experiment.json").write_text(
        json.dumps(experiment, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    write_report(summary, monthly, course, bootstrap, venue_rows, audit, experiment)
    print(
        json.dumps(
            {
                "status": "ok",
                "valid_races": audit["valid_races"],
                "test_races": audit["test_races"],
                "selected_weights": experiment["selected_weights"],
                "runtime_seconds": experiment["runtime_seconds"],
            },
            ensure_ascii=False,
        )
    )


def write_report(
    summary: list[dict],
    monthly: list[dict],
    course: list[dict],
    bootstrap: list[dict],
    venue: list[dict],
    audit: dict,
    experiment: dict,
) -> None:
    by_name = {row["blend_name"]: row for row in summary}
    boot = {(row["blend_name"], row["split"]): row for row in bootstrap}

    def improved(name: str, split: str) -> bool:
        return float(by_name[name][f"{split}_brier_delta"]) < 0

    ebm_names = ("v6_pre_course__ebm", "v6_final__ebm")
    ag_names = ("v6_pre_course__autogluon", "v6_final__autogluon")
    ng_names = ("v6_pre_course__ngboost", "v6_final__ngboost")
    course_blend = by_name["v6_pre_course__v6_final"]
    course_intermediate_improved = (
        0 < float(course_blend["selected_weight_valid_only"]) < 1
        and float(course_blend["test_brier"]) < float(course_blend["test_challenger_brier"])
    )

    lines = [
        "# AI1着率 v6 pairwise blend validation",
        "",
        "## 結論",
        "",
        f"1. v6 + EBMはVALIDで改善: **{'はい' if any(improved(name, 'valid') for name in ebm_names) else 'いいえ'}**。",
        f"2. VALID固定weightをTESTへ適用して改善: **{'はい' if any(improved(name, 'test') for name in ebm_names) else 'いいえ'}**（EBM pair）。",
        f"3. v6 + AutoGluonはVALID改善pair {sum(improved(name, 'valid') for name in ag_names)}/2、TEST改善pair {sum(improved(name, 'test') for name in ag_names)}/2。",
        f"4. v6 + NGBoostはVALID改善pair {sum(improved(name, 'valid') for name in ng_names)}/2、TEST改善pair {sum(improved(name, 'test') for name in ng_names)}/2。",
        f"5. course補正の中間blendは現行100%補正よりBrierが良い: **{'はい' if course_intermediate_improved else 'いいえ'}**。VALID最適はw={float(course_blend['selected_weight_valid_only']):.2f}で、中間値ではない。",
        "6. forward主候補は現行course補正を残す `v6_final + AutoGluon 25%`、小変更候補は `v6_final + EBM 5%`。`v6_pre_course + AutoGluon 30%` はcourse補正を外す構成比較候補、NGBoostは参考追跡とする。",
        "",
        "これは本番採用判断ではなく、既に観察済みhistorical TESTを用いたforward候補固定である。",
        "",
        "## baseline",
        "",
        "|model|VALID Brier|TEST Brier|TEST LogLoss|TEST Top1|",
        "|---|---:|---:|---:|---:|",
    ]
    for model in ("v6_pre_course", "v6_final"):
        matching = next(row for row in experiment["prediction_audit"]["checks"] if row["split"] == "valid" and row["model"] == model)
        test_matching = next(row for row in experiment["prediction_audit"]["checks"] if row["split"] == "test" and row["model"] == model)
        lines.append(
            f"|{model}|{float(matching['race_brier_mean']):.8f}|"
            f"{float(test_matching['race_brier_mean']):.8f}|"
            f"{float(test_matching['winner_log_loss']):.6f}|"
            f"{float(test_matching['top1_accuracy'])*100:.2f}%|"
        )
    lines += [
        "",
        "## 保存済み予測監査",
        "",
        f"- VALID {audit['valid_races']:,}R / TEST {audit['test_races']:,}R、各6艇・勝者1艇。",
        f"- 前回summaryとの最大差: {audit['maximum_summary_metric_difference']:.3e}。",
        f"- コミット済みTEST確率との最大差: {audit['maximum_committed_test_probability_difference']:.3e}。",
        f"- 元モデルのレース確率和最大誤差: {audit['maximum_probability_sum_error']:.3e}。",
        "- 重複、NaN、Infなし。TESTはweight選択に未使用。モデル再学習なし。",
        f"- 選択blendの確率和最大誤差: {max(float(row['test_probability_sum_max_abs_error']) for row in summary):.3e}。",
        "",
        "## VALIDで固定したweightとTEST評価",
        "",
        "|blend|w|VALID ΔBrier|TEST ΔBrier|TEST LogLoss|TEST Top1|VALID改善grid|安定性|",
        "|---|---:|---:|---:|---:|---:|---:|---|",
    ]
    for row in summary:
        lines.append(
            f"|{row['blend_name']}|{float(row['selected_weight_valid_only']):.2f}|"
            f"{float(row['valid_brier_delta']):+.8f}|{float(row['test_brier_delta']):+.8f}|"
            f"{float(row['test_log_loss']):.6f}|{float(row['test_top1'])*100:.2f}%|"
            f"{row['valid_improving_grid_points']}|{row['weight_stability']}|"
        )

    lines += [
        "",
        "## paired race bootstrap",
        "",
        "|blend|split|mean Δ|95% CI|改善bootstrap割合|",
        "|---|---|---:|---:|---:|",
    ]
    for row in bootstrap:
        lines.append(
            f"|{row['blend_name']}|{row['split']}|{float(row['mean_delta']):+.8f}|"
            f"[{float(row['ci95_lower']):+.8f}, {float(row['ci95_upper']):+.8f}]|"
            f"{float(row['bootstrap_improvement_fraction'])*100:.1f}%|"
        )

    lines += ["", "## 月別安定性", ""]
    for row in summary:
        selected_months = [item for item in monthly if item["blend_name"] == row["blend_name"]]
        count = sum(float(item["brier_delta"]) < 0 for item in selected_months)
        lines.append(f"- `{row['blend_name']}`: {count}/{len(selected_months)}か月改善。")

    lines += ["", "## venue別", ""]
    for row in summary:
        parts = []
        for item in venue:
            if item["blend_name"] == row["blend_name"]:
                parts.append(f"{item['venue']} Δ{float(item['brier_delta']):+.8f}")
        lines.append(f"- `{row['blend_name']}`: " + " / ".join(parts))

    lines += ["", "## course別 diagnostic", ""]
    for row in summary:
        parts = []
        for item in course:
            if item["blend_name"] == row["blend_name"]:
                parts.append(f"{item['course']}C {float(item['brier_delta']):+.8f}")
        lines.append(f"- `{row['blend_name']}`: " + " / ".join(parts))

    lines += [
        "",
        "## weight近傍",
        "",
    ]
    for row in summary:
        lines.append(
            f"- `{row['blend_name']}`: 改善grid "
            f"{row['valid_improving_weight_min']}〜{row['valid_improving_weight_max']} "
            f"({row['valid_improving_grid_points']}点); 近傍Δ "
            f"`{row['selected_neighborhood_brier_deltas_json']}`"
        )

    lines += [
        "",
        "## 注意",
        "",
        "- 全weightはVALIDだけで固定し、TEST結果による再選択・微調整はしていない。",
        "- course別・venue別のweightやroutingは作成していない。",
        "- calibration、3-way blend、本番コード・artifact・PHP・画面・買い目は変更していない。",
        "- `v6_final + AutoGluon 25%` はVALID改善が強く広い一方、weighted ensembleを追加する運用負荷がある。`v6_final + EBM 5%` は現行v6からの変更が小さいが、VALID改善幅が小さく改善gridも1点のため不安定候補として両者をforward比較する。",
        f"- bootstrapはrace単位paired {BOOTSTRAP_RESAMPLES:,}回、base seed {SEED}。",
        f"- 実行時間 {float(experiment['runtime_seconds']):.2f}秒。",
        "",
    ]
    (OUTPUT / f"{OUTPUT_STEM}.md").write_text("\n".join(lines), encoding="utf-8")


if __name__ == "__main__":
    main()
