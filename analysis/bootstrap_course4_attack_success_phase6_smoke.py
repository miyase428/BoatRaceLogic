#!/usr/bin/env python3
"""Phase 6B-1: 100-iteration venue-date cluster-bootstrap smoke test only."""
from __future__ import annotations

import csv
import datetime
import hashlib
import json
import subprocess
import time
from collections import defaultdict
from pathlib import Path

import numpy as np


ROOT = Path(__file__).resolve().parent
OUT = ROOT / "output"
CLUSTER_CACHE = OUT / "course4_attack_success_phase6_cluster_cache_20251001_20260930.csv"
CLUSTER_SHA256 = "4dfebd476498bb0ac536f9a6747701853cf2935579afb648388c572613e56894"
RESULT = OUT / "course4_attack_success_phase6_bootstrap_smoke_100.json"
SEED = 20261007
SPLITS = ("Validation", "EXPOSED_CONFIRMATION")
EXPECTED_CLUSTERS = {"Validation": 2194, "EXPOSED_CONFIRMATION": 2320}
COUNT_COLUMNS = (
    "five_second_count", "five_third_count", "five_top3_count",
    "six_second_count", "six_third_count", "six_top3_count",
    "pair_second_sum", "pair_third_sum", "pair_top3_sum",
)
LIFT_NAMES = (
    "five_second_lift", "five_third_lift", "five_top3_lift",
    "six_second_lift", "six_third_lift", "six_top3_lift",
    "follower_gap_second", "follower_gap_third", "follower_gap_top3",
    "paired_second_high_low", "paired_third_high_low", "paired_top3_high_low",
)


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def read_csv(path: Path) -> list[dict[str, str]]:
    with path.open(encoding="utf-8", newline="") as handle:
        return list(csv.DictReader(handle))


def differences(values: np.ndarray) -> np.ndarray:
    """Return High-minus-Low percentage-point metrics from [Low, High, cols]."""
    low, high = values[0], values[1]
    if low[0] <= 0 or high[0] <= 0:
        raise RuntimeError("a bootstrap resample has no Low or High observations")
    rates = 100.0 * (high[1:] / high[0] - low[1:] / low[0])
    five, six, paired = rates[:3], rates[3:6], rates[6:9]
    return np.r_[five, six, five - six, paired]


def one_split(rows: list[dict[str, str]], split: str, iterations: int) -> dict[str, object]:
    prep_started = time.perf_counter()
    grouped: dict[tuple[str, str], dict[str, np.ndarray]] = defaultdict(dict)
    for row in rows:
        if row["split"] != split:
            continue
        key = (row["stadium"], row["race_date"])
        # Retain every venue-date in the split.  A cluster with no Low or High
        # row is still sampled, contributing a zero vector for that band.
        grouped[key]
        if row["success_band"] not in {"0-19", "80-100"}:
            continue
        vector = np.asarray([int(row["N"]), *(int(row[column]) for column in COUNT_COLUMNS)], dtype=float)
        grouped[key][row["success_band"]] = vector
    keys = sorted(grouped)
    if len(keys) != EXPECTED_CLUSTERS[split]:
        raise RuntimeError(f"unexpected {split} cluster count: {len(keys)}")
    # One row per venue-date; Low/High entries absent in a venue-date remain zero.
    clusters = np.zeros((len(keys), 2, 1 + len(COUNT_COLUMNS)), dtype=float)
    for index, key in enumerate(keys):
        clusters[index, 0] = grouped[key].get("0-19", 0.0)
        clusters[index, 1] = grouped[key].get("80-100", 0.0)
    point = differences(clusters.sum(axis=0))
    full = clusters.sum(axis=0)
    rates = {
        name: {
            "low_rate_pct": float(100.0 * full[0, index] / full[0, 0]),
            "high_rate_pct": float(100.0 * full[1, index] / full[1, 0]),
        }
        for index, name in enumerate(("five_second", "five_third", "five_top3", "six_second", "six_third", "six_top3"), start=1)
    }
    preprocessing_seconds = time.perf_counter() - prep_started

    boot_started = time.perf_counter()
    rng = np.random.default_rng(SEED)
    samples = np.empty((iterations, len(LIFT_NAMES)), dtype=float)
    resample_cluster_counts: list[int] = []
    for iteration in range(iterations):
        selected = rng.integers(0, len(keys), size=len(keys))
        if len(selected) != len(keys):
            raise RuntimeError("resample did not preserve the split cluster count")
        resample_cluster_counts.append(len(selected))
        samples[iteration] = differences(clusters[selected].sum(axis=0))
    bootstrap_seconds = time.perf_counter() - boot_started

    summary = {}
    for index, name in enumerate(LIFT_NAMES):
        summary[name] = {
            "point_estimate_pct_point": float(point[index]),
            "bootstrap_mean_pct_point": float(np.mean(samples[:, index])),
            "percentile_95_ci_pct_point": [float(np.quantile(samples[:, index], 0.025)), float(np.quantile(samples[:, index], 0.975))],
        }
    diagnostic = {
        "second_max_abs_follower_gap_minus_paired": float(np.max(np.abs(samples[:, 6] - samples[:, 9]))),
        "third_max_abs_follower_gap_minus_paired": float(np.max(np.abs(samples[:, 7] - samples[:, 10]))),
        "top3_max_abs_follower_gap_minus_paired": float(np.max(np.abs(samples[:, 8] - samples[:, 11]))),
        "point_second_abs_difference": float(abs(point[6] - point[9])),
        "point_third_abs_difference": float(abs(point[7] - point[10])),
        "point_top3_abs_difference": float(abs(point[8] - point[11])),
        "status": "PASS" if np.allclose(samples[:, 6:9], samples[:, 9:12], atol=1e-12, rtol=0.0) else "FAIL",
    }
    return {
        "split": split,
        "cluster_count": len(keys),
        "resample_cluster_count_all_iterations_match": all(count == len(keys) for count in resample_cluster_counts),
        "low_high_rates": rates,
        "preprocessing_seconds": preprocessing_seconds,
        "bootstrap_seconds": bootstrap_seconds,
        "metrics": summary,
        "paired_diagnostic": diagnostic,
    }


def main(iterations: int, output: Path) -> None:
    total_started = time.perf_counter()
    load_started = time.perf_counter()
    if sha256(CLUSTER_CACHE) != CLUSTER_SHA256:
        raise RuntimeError("cluster cache SHA256 mismatch")
    rows = read_csv(CLUSTER_CACHE)
    load_seconds = time.perf_counter() - load_started
    keys = {(row["stadium"], row["race_date"]) for row in rows}
    duplicate = len(rows) - len({(row["split"], row["stadium"], row["race_date"], row["success_band"]) for row in rows})
    total_n = sum(int(row["N"]) for row in rows)
    if len(rows) != 19125 or len(keys) != 4514 or duplicate != 0 or total_n != 42717:
        raise RuntimeError("cluster cache pre-bootstrap audit mismatch")
    result = {
        "metadata": {
            "cluster_cache_path": "analysis/output/course4_attack_success_phase6_cluster_cache_20251001_20260930.csv",
            "cluster_cache_sha256": CLUSTER_SHA256,
            "seed": SEED,
            "iterations": iterations,
            "cluster_definition": "stadium × race_date; all success_band rows resampled together within split",
            "low_band": "0-19",
            "high_band": "80-100",
            "splits": list(SPLITS),
            "source_script": "analysis/bootstrap_course4_attack_success_phase6_smoke.py",
            "source_script_sha256": sha256(Path(__file__).resolve()),
            "source_git_commit": subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT.parent, text=True).strip(),
            "generated_at": datetime.datetime.now().astimezone().isoformat(),
        },
        "pre_bootstrap_audit": {"cluster_cache_rows": len(rows), "unique_venue_date": len(keys), "duplicate_key": duplicate, "N_total": total_n},
        "timing": {"cluster_cache_load_seconds": load_seconds},
        "splits": {split: one_split(rows, split, iterations) for split in SPLITS},
    }
    result["timing"]["total_seconds"] = time.perf_counter() - total_started
    output.write_text(json.dumps(result, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(result, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument("--iterations", type=int, default=100)
    parser.add_argument("--output", type=Path)
    args = parser.parse_args()
    if args.iterations <= 0:
        raise ValueError("iterations must be positive")
    main(args.iterations, args.output or OUT / f"course4_attack_success_phase6_bootstrap_{args.iterations}.json")
