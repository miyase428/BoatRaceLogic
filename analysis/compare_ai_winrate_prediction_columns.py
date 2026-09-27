#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""AI1着率の予測明細2列を、レース単位のpaired bootstrapで比較する。"""

from __future__ import annotations

import argparse
import csv
import gzip
import json
from pathlib import Path

import numpy as np


def load_columns(
    path: Path,
    baseline_column: str,
    candidate_column: str,
) -> tuple[np.ndarray, np.ndarray, np.ndarray]:
    race_codes: list[str] = []
    labels: list[int] = []
    baseline: list[float] = []
    candidate: list[float] = []
    with gzip.open(path, "rt", encoding="utf-8", newline="") as handle:
        reader = csv.DictReader(handle)
        fields = set(reader.fieldnames or [])
        required = {"race_code", "label", baseline_column, candidate_column}
        missing = required - fields
        if missing:
            raise RuntimeError(f"予測明細に列がありません: {sorted(missing)}")
        for row in reader:
            race_codes.append(row["race_code"])
            labels.append(int(row["label"]))
            baseline.append(float(row[baseline_column]))
            candidate.append(float(row[candidate_column]))

    if len(labels) % 6:
        raise RuntimeError("予測明細の行数が6の倍数ではありません")
    code_matrix = np.asarray(race_codes, dtype=object).reshape(-1, 6)
    if np.any(code_matrix != code_matrix[:, :1]):
        raise RuntimeError("同一レースの6艇が連続していません")
    label_matrix = np.asarray(labels, dtype=np.int8).reshape(-1, 6)
    if np.any(label_matrix.sum(axis=1) != 1):
        raise RuntimeError("各レースの1着ラベルが1艇ではありません")
    return (
        label_matrix,
        np.asarray(baseline, dtype=np.float64).reshape(-1, 6),
        np.asarray(candidate, dtype=np.float64).reshape(-1, 6),
    )


def paired_differences(
    labels: np.ndarray,
    baseline: np.ndarray,
    candidate: np.ndarray,
) -> dict[str, np.ndarray]:
    rows = np.arange(len(labels))
    winner = np.argmax(labels, axis=1)
    baseline_top = np.argmax(baseline, axis=1)
    candidate_top = np.argmax(candidate, axis=1)
    return {
        "brier": np.mean(
            (candidate - labels) ** 2 - (baseline - labels) ** 2,
            axis=1,
        ),
        "winner_nll": (
            -np.log(np.maximum(candidate[rows, winner], 1e-12))
            + np.log(np.maximum(baseline[rows, winner], 1e-12))
        ),
        "hit_rate": (
            labels[rows, candidate_top] - labels[rows, baseline_top]
        ).astype(np.float64),
    }


def bootstrap(
    differences: dict[str, np.ndarray],
    samples: int,
    seed: int,
) -> dict[str, dict]:
    rng = np.random.default_rng(seed)
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
            "probability_improves": float(
                np.mean(means > 0) if name == "hit_rate" else np.mean(means < 0)
            ),
        }
    return result


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("prediction_file", type=Path)
    parser.add_argument("baseline_column")
    parser.add_argument("candidate_column")
    parser.add_argument("--samples", type=int, default=10_000)
    parser.add_argument("--seed", type=int, default=20260924)
    parser.add_argument("--output", type=Path)
    args = parser.parse_args()

    labels, baseline, candidate = load_columns(
        args.prediction_file,
        args.baseline_column,
        args.candidate_column,
    )
    result = {
        "configuration": {
            "prediction_file": str(args.prediction_file),
            "baseline_column": args.baseline_column,
            "candidate_column": args.candidate_column,
            "races": len(labels),
            "samples": args.samples,
            "seed": args.seed,
            "unit": "race-level paired bootstrap",
        },
        "paired_bootstrap": bootstrap(
            paired_differences(labels, baseline, candidate),
            args.samples,
            args.seed,
        ),
    }
    if args.output:
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(
            json.dumps(result, ensure_ascii=False, indent=2),
            encoding="utf-8",
        )
    print(json.dumps(result, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
