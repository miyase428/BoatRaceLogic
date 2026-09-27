#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""固定v4/v5/v6モデルを完全未使用期間で比較する前方評価モニター。"""

from __future__ import annotations

import argparse
import csv
import gzip
import hashlib
import json
import sys
from collections import defaultdict
from datetime import date
from pathlib import Path

import joblib
import numpy as np

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "forecast"))
sys.path.insert(0, str(ROOT / "analysis"))

from ai_winrate_features import normalized_race_probabilities  # noqa: E402
from ai_winrate_v2_models import blend_probabilities  # noqa: E402
from ai_winrate_v4_rating_ablation_2026 import PAIR_FEATURES, load_metadata  # noqa: E402
from ai_winrate_motor_reset_ablation_2026 import (  # noqa: E402
    RESET_PAIR_FEATURES,
    load_motor_reset_dates,
)
from feature_ablation_tournament_2026 import (  # noqa: E402
    BASE_FEATURES,
    build_matrix,
    load_rows,
    variant_features,
)
from ranking_model_tournament_2026 import (  # noqa: E402
    race_groups,
    rank_probabilities,
    score_probabilities,
)
from train_ai_winrate import COURSE, IS_WINNER, PLACE_CODE, RACE_CODE, RACE_DATE  # noqa: E402

DEFAULT_FREEZE = ROOT / "forecast" / "models" / "frozen" / "2026-09-24"
CALIBRATION_BINS = np.asarray(
    [0.00, 0.05, 0.10, 0.15, 0.20, 0.30, 0.40, 0.50, 0.60, 0.70, 0.80, 0.90, 1.0000001]
)
TOP1_BINS = np.asarray(
    [0.00, 0.25, 0.30, 0.35, 0.40, 0.50, 0.60, 0.70, 0.80, 0.90, 1.0000001]
)


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def load_frozen_artifacts(directory: Path) -> tuple[dict, dict, dict, dict, dict]:
    manifest = json.loads((directory / "manifest.json").read_text(encoding="utf-8"))
    artifacts: list[dict] = []
    for name in (
        "ai_winrate_v2.joblib",
        "ai_winrate_v4.joblib",
        "ai_winrate_v5.joblib",
        "ai_winrate_v6.joblib",
    ):
        path = directory / name
        expected = manifest["models"][name]["sha256"]
        actual = sha256(path)
        if actual != expected:
            raise RuntimeError(f"固定モデルのSHA256不一致: {name}")
        artifacts.append(joblib.load(path))
    return artifacts[0], artifacts[1], artifacts[2], artifacts[3], manifest


def append_frozen_rating_features(
    rows: list[tuple],
    columns: dict[str, int],
    metadata: dict[tuple[str, int], tuple[str, str, int, int, int | None]],
    artifact: dict,
    state_key: str,
    feature_names: tuple[str, ...],
    reset_dates: set[tuple[str, str]] | None = None,
) -> tuple[list[tuple], dict[str, int]]:
    state = artifact[state_key]
    player_state = state.get("player", {})
    motor_state = dict(state.get("motor", {}))
    course_state = np.asarray(state.get("course", [0.0] * 7), dtype=np.float64)
    resets_by_date: dict[str, set[str]] = defaultdict(set)
    for reset_date, place in reset_dates or set():
        resets_by_date[reset_date].add(place)
    additions: dict[tuple[str, int], tuple[float, ...]] = {}
    position = 0
    current_date: str | None = None
    while position < len(rows):
        race_code = str(rows[position][RACE_CODE])
        end = position
        while end < len(rows) and str(rows[end][RACE_CODE]) == race_code:
            end += 1
        race_rows = rows[position:end]
        race_date = str(race_rows[0][RACE_DATE])
        if race_date != current_date:
            venues = resets_by_date.get(race_date, set())
            if venues:
                motor_state = {
                    key: value
                    for key, value in motor_state.items()
                    if key.split(":", 1)[0] not in venues
                }
            current_date = race_date
        race_metadata = [
            metadata[(race_code, int(row[columns["course"]]))]
            for row in race_rows
        ]
        players = np.asarray(
            [float(player_state.get(item[0], 0.0)) for item in race_metadata],
            dtype=np.float64,
        )
        motors = np.asarray(
            [
                float(motor_state.get(f"{item[1]}:{item[2]}", 0.0))
                for item in race_metadata
            ],
            dtype=np.float64,
        )
        combined = players + motors
        scores = np.asarray(
            [
                combined[index] + course_state[int(row[columns["course"]])]
                for index, row in enumerate(race_rows)
            ],
            dtype=np.float64,
        )
        scores -= scores.max()
        probabilities = np.exp(scores)
        probabilities /= probabilities.sum()
        for index, row in enumerate(race_rows):
            key = (race_code, int(row[columns["course"]]))
            additions[key] = (
                float(players[index] - players.mean()),
                float(motors[index] - motors.mean()),
                float(combined[index] - combined.mean()),
                float(probabilities[index]),
            )
        position = end

    next_columns = dict(columns)
    next_columns.update(
        {name: len(rows[0]) + index for index, name in enumerate(feature_names)}
    )
    return [
        tuple(row) + additions[(str(row[RACE_CODE]), int(row[columns["course"]]))]
        for row in rows
    ], next_columns


def ece(probability: np.ndarray, labels: np.ndarray, bins: np.ndarray) -> float:
    weighted = 0.0
    for low, high in zip(bins[:-1], bins[1:]):
        selected = (probability >= low) & (probability < high)
        n = int(selected.sum())
        if n:
            weighted += n * abs(float(probability[selected].mean() - labels[selected].mean()))
    return weighted / len(probability)


def extended_metrics(probability: np.ndarray, rows: list[tuple]) -> dict:
    base = score_probabilities(probability, rows)
    _, groups, _ = race_groups(rows)
    labels = np.asarray([bool(row[IS_WINNER]) for row in rows], dtype=np.int8)
    top_indices = np.asarray(
        [indices[np.argmax(probability[indices])] for indices in groups], dtype=np.int64
    )
    return {
        **{key: value for key, value in base.items() if key != "picks"},
        "ece": ece(probability, labels, CALIBRATION_BINS),
        "top1_ece": ece(
            probability[top_indices].reshape(-1, 1),
            labels[top_indices].reshape(-1, 1),
            TOP1_BINS,
        ),
        "mean_top1_probability": float(probability[top_indices].mean()),
    }


def paired_bootstrap(
    baseline_probability: np.ndarray,
    candidate_probability: np.ndarray,
    rows: list[tuple],
    samples: int,
) -> dict[str, dict]:
    _, groups, _ = race_groups(rows)
    labels = np.asarray([bool(row[IS_WINNER]) for row in rows], dtype=np.int8)
    brier_delta: list[float] = []
    nll_delta: list[float] = []
    hit_delta: list[float] = []
    for indices in groups:
        y = labels[indices]
        baseline = baseline_probability[indices]
        candidate = candidate_probability[indices]
        winner = int(np.argmax(y))
        brier_delta.append(
            float(np.mean((candidate - y) ** 2 - (baseline - y) ** 2))
        )
        nll_delta.append(
            float(
                -np.log(max(candidate[winner], 1e-12))
                + np.log(max(baseline[winner], 1e-12))
            )
        )
        hit_delta.append(
            float(y[np.argmax(candidate)] - y[np.argmax(baseline)])
        )
    rng = np.random.default_rng(20260924)
    result: dict[str, dict] = {}
    for name, values, higher_is_better in (
        ("brier", np.asarray(brier_delta), False),
        ("winner_nll", np.asarray(nll_delta), False),
        ("hit_rate", np.asarray(hit_delta), True),
    ):
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
                np.mean(means > 0) if higher_is_better else np.mean(means < 0)
            ),
        }
    return result


def write_predictions(
    path: Path,
    rows: list[tuple],
    v4_probability: np.ndarray,
    v5_probability: np.ndarray,
    v6_probability: np.ndarray,
) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with gzip.open(path, "wt", encoding="utf-8", newline="") as handle:
        fields = (
            "race_code",
            "race_date",
            "place",
            "course",
            "label",
            "v4",
            "v5",
            "v6",
        )
        writer = csv.DictWriter(handle, fieldnames=fields)
        writer.writeheader()
        for row, p4, p5, p6 in zip(
            rows,
            v4_probability,
            v5_probability,
            v6_probability,
        ):
            writer.writerow(
                {
                    "race_code": str(row[RACE_CODE]),
                    "race_date": str(row[RACE_DATE]),
                    "place": str(row[PLACE_CODE]),
                    "course": int(row[COURSE]),
                    "label": int(bool(row[IS_WINNER])),
                    "v4": float(p4),
                    "v5": float(p5),
                    "v6": float(p6),
                }
            )


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--freeze-dir", type=Path, default=DEFAULT_FREEZE)
    parser.add_argument("--history-start", default="2025-01-01")
    parser.add_argument("--start")
    parser.add_argument("--end-exclusive", default=date.today().isoformat())
    parser.add_argument("--bootstrap-samples", type=int, default=10_000)
    parser.add_argument(
        "--output-prefix",
        type=Path,
        default=ROOT / "analysis" / "output" / "ai_winrate_forward_20260924",
    )
    args = parser.parse_args()
    v2_artifact, v4_artifact, v5_artifact, v6_artifact, manifest = (
        load_frozen_artifacts(args.freeze_dir)
    )
    start = date.fromisoformat(args.start or manifest["forward_start"])
    end_exclusive = date.fromisoformat(args.end_exclusive)
    if end_exclusive <= start:
        print(
            json.dumps(
                {
                    "status": "waiting",
                    "reason": "評価開始日以降の完了日がまだありません",
                    "start": start.isoformat(),
                    "end_exclusive": end_exclusive.isoformat(),
                },
                ensure_ascii=False,
            )
        )
        return 0

    print("固定モデルの前方評価データを構築しています…", flush=True)
    all_rows, columns = load_rows(args.history_start, end_exclusive.isoformat())
    rows = [
        row for row in all_rows
        if start.isoformat() <= str(row[RACE_DATE]) < end_exclusive.isoformat()
    ]
    if not rows:
        print(
            json.dumps(
                {
                    "status": "waiting",
                    "reason": "評価可能な完了レースがありません",
                    "start": start.isoformat(),
                    "end_exclusive": end_exclusive.isoformat(),
                },
                ensure_ascii=False,
            )
        )
        return 0
    valid_keys = {(str(row[RACE_CODE]), int(row[columns["course"]])) for row in rows}
    metadata = load_metadata(start.isoformat(), end_exclusive.isoformat(), valid_keys)
    if valid_keys - metadata.keys():
        raise RuntimeError("前方評価用Ratingメタデータが不足しています")
    rows, columns = append_frozen_rating_features(
        rows,
        columns,
        metadata,
        v5_artifact,
        "pair_rating_state",
        PAIR_FEATURES,
    )
    reset_dates = {
        item
        for item in load_motor_reset_dates(
            args.history_start,
            end_exclusive.isoformat(),
        )
        if item[0] >= start.isoformat()
    }
    rows, columns = append_frozen_rating_features(
        rows,
        columns,
        metadata,
        v6_artifact,
        "reset_pair_rating_state",
        RESET_PAIR_FEATURES,
        reset_dates,
    )

    race_codes = [str(row[RACE_CODE]) for row in rows]
    _, groups, _ = race_groups(rows)
    x_v2 = build_matrix(rows, columns, BASE_FEATURES, v2_artifact["place_to_id"])
    hist_probability = v2_artifact["hist_model"].predict_proba(x_v2)[:, 1]
    xgb_probability = v2_artifact["xgb_model"].predict_proba(x_v2)[:, 1]
    hist_weight = float(v2_artifact.get("blend", {}).get("hist_weight", 0.5))
    v2_probability = blend_probabilities(
        hist_probability, xgb_probability, race_codes, hist_weight
    )

    v4_features = variant_features()["v3_all_features"]
    x_v4 = build_matrix(rows, columns, v4_features, v4_artifact["place_to_id"])
    v4_rank_probability = rank_probabilities(
        v4_artifact["model"].predict(x_v4), groups, float(v4_artifact["temperature"])
    )
    old_blend = v4_artifact["blend"]
    v4_probability = normalized_race_probabilities(
        float(old_blend["v2_weight"]) * v2_probability
        + float(old_blend["v4_weight"]) * v4_rank_probability,
        race_codes,
    )

    x_v5 = build_matrix(
        rows,
        columns,
        v4_features + PAIR_FEATURES,
        v5_artifact["place_to_id"],
    )
    rating_probability = rank_probabilities(
        v5_artifact["model"].predict(x_v5), groups, float(v5_artifact["temperature"])
    )
    new_blend = v5_artifact["blend"]
    v5_probability = (
        float(new_blend["v2_weight"]) * v2_probability
        + float(new_blend["v4_weight"]) * v4_rank_probability
        + float(new_blend["rating_v4_weight"]) * rating_probability
    )
    factors = v5_artifact["course_calibration"]["factors"]
    v5_probability *= np.asarray(
        [float(factors.get(str(int(row[COURSE])), 1.0)) for row in rows]
    )
    v5_probability = normalized_race_probabilities(v5_probability, race_codes)

    x_v6 = build_matrix(
        rows,
        columns,
        v4_features + RESET_PAIR_FEATURES,
        v6_artifact["place_to_id"],
    )
    reset_rating_probability = rank_probabilities(
        v6_artifact["model"].predict(x_v6),
        groups,
        float(v6_artifact["temperature"]),
    )
    v6_blend = v6_artifact["blend"]
    v6_probability = (
        float(v6_blend["v2_weight"]) * v2_probability
        + float(v6_blend["v4_weight"]) * v4_rank_probability
        + float(v6_blend["rating_v4_weight"]) * reset_rating_probability
    )
    v6_factors = v6_artifact["course_calibration"]["factors"]
    v6_probability *= np.asarray(
        [float(v6_factors.get(str(int(row[COURSE])), 1.0)) for row in rows]
    )
    v6_probability = normalized_race_probabilities(v6_probability, race_codes)

    metrics = {
        "v4": extended_metrics(v4_probability, rows),
        "v5": extended_metrics(v5_probability, rows),
        "v6": extended_metrics(v6_probability, rows),
    }
    uncertainty = {
        "v5_vs_v4": paired_bootstrap(
            v4_probability,
            v5_probability,
            rows,
            args.bootstrap_samples,
        ),
        "v6_vs_v5": paired_bootstrap(
            v5_probability,
            v6_probability,
            rows,
            args.bootstrap_samples,
        ),
    }
    monthly: dict[str, dict] = {}
    for month in sorted({str(row[RACE_DATE])[:7] for row in rows}):
        selected = np.asarray(
            [str(row[RACE_DATE]).startswith(month) for row in rows], dtype=bool
        )
        month_rows = [row for row, keep in zip(rows, selected) if keep]
        monthly[month] = {
            "v4": extended_metrics(v4_probability[selected], month_rows),
            "v5": extended_metrics(v5_probability[selected], month_rows),
            "v6": extended_metrics(v6_probability[selected], month_rows),
        }
    report = {
        "status": "ok",
        "frozen_manifest": manifest,
        "period": {
            "start": start.isoformat(),
            "end_exclusive": end_exclusive.isoformat(),
            "races": len(rows) // 6,
        },
        "metrics": metrics,
        "paired_bootstrap": uncertainty,
        "months": monthly,
        "decision_gate": {
            "minimum_races": 7000,
            "enough_data": len(rows) // 6 >= 7000,
            "brier_improvement_probability_target": 0.95,
            "nll_improvement_probability_target": 0.95,
        },
    }
    json_path = args.output_prefix.with_suffix(".json")
    prediction_path = args.output_prefix.with_name(
        args.output_prefix.name + "_predictions.csv.gz"
    )
    json_path.parent.mkdir(parents=True, exist_ok=True)
    json_path.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
    write_predictions(
        prediction_path,
        rows,
        v4_probability,
        v5_probability,
        v6_probability,
    )
    print(json.dumps(report, ensure_ascii=False, indent=2), flush=True)
    print(f"JSON: {json_path}")
    print(f"予測明細: {prediction_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
