#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""AI1着率 v4 の確率校正を、過去情報だけでウォークフォワード検証する。

本番値（v2 15% + v4 85%）の確率帯別信頼性を調べ、さらに最終確率へ
Power temperature / Platt / Isotonic を適用した場合を同条件で比較する。
各補正器は直前3か月までの out-of-fold 予測だけで学習する。
"""

from __future__ import annotations

import argparse
import csv
import gc
import gzip
import json
import os
import sys
from collections import defaultdict, deque
from dataclasses import dataclass
from datetime import date
from pathlib import Path

import numpy as np
from scipy.optimize import minimize_scalar
from sklearn.isotonic import IsotonicRegression
from sklearn.linear_model import LogisticRegression

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "forecast"))
sys.path.insert(0, str(ROOT / "analysis"))

from ai_winrate_features import normalized_race_probabilities  # noqa: E402
from ai_winrate_v2_models import make_hist_gradient_boosting, make_xgboost  # noqa: E402
from feature_ablation_tournament_2026 import (  # noqa: E402
    BASE_FEATURES,
    build_matrix,
    load_rows,
    variant_features,
)
from ranking_model_tournament_2026 import (  # noqa: E402
    calibrate_temperature,
    following_month,
    make_lightgbm_ranker,
    previous_month,
    race_groups,
    rank_probabilities,
)
from train_ai_winrate import COURSE, IS_WINNER, PLACE_CODE, RACE_CODE, RACE_DATE  # noqa: E402

CALIBRATION_BINS = np.asarray(
    [0.00, 0.05, 0.10, 0.15, 0.20, 0.30, 0.40, 0.50, 0.60, 0.70, 0.80, 0.90, 1.0000001]
)
TOP1_BINS = np.asarray(
    [0.00, 0.25, 0.30, 0.35, 0.40, 0.50, 0.60, 0.70, 0.80, 0.90, 1.0000001]
)
METHODS = ("baseline", "power_temperature", "platt", "isotonic")
V2_WEIGHT = 0.15
V4_WEIGHT = 0.85


@dataclass
class PredictionBlock:
    month: str
    probabilities: np.ndarray
    labels: np.ndarray
    groups: list[np.ndarray]
    rows: list[tuple]


def normalize_groups(values: np.ndarray, groups: list[np.ndarray]) -> np.ndarray:
    result = np.empty(len(values), dtype=np.float64)
    clean = np.maximum(np.asarray(values, dtype=np.float64), 1e-12)
    for indices in groups:
        subtotal = float(clean[indices].sum())
        result[indices] = clean[indices] / subtotal
    return result


def fit_v4_scores(
    train_rows: list[tuple],
    predict_rows: list[tuple],
    columns: dict[str, int],
    place_to_id: dict[str, int],
    v4_features: tuple[str, ...],
) -> tuple[np.ndarray, list[np.ndarray], np.ndarray]:
    y_train = np.asarray([bool(row[IS_WINNER]) for row in train_rows], dtype=np.int8)
    labels = np.asarray([bool(row[IS_WINNER]) for row in predict_rows], dtype=np.int8)
    _, _, train_group_sizes = race_groups(train_rows)
    _, groups, _ = race_groups(predict_rows)
    x_train = build_matrix(train_rows, columns, v4_features, place_to_id)
    x_predict = build_matrix(predict_rows, columns, v4_features, place_to_id)
    model = make_lightgbm_ranker()
    model.fit(x_train, y_train, group=train_group_sizes, categorical_feature=[0, 1])
    scores = np.asarray(model.predict(x_predict), dtype=np.float64)
    del y_train, x_train, x_predict, model
    gc.collect()
    return scores, groups, labels


def fit_baseline_block(
    month: str,
    train_rows: list[tuple],
    predict_rows: list[tuple],
    columns: dict[str, int],
    place_to_id: dict[str, int],
    v4_features: tuple[str, ...],
    v4_temperature: float,
) -> tuple[PredictionBlock, np.ndarray]:
    y_train = np.asarray([bool(row[IS_WINNER]) for row in train_rows], dtype=np.int8)
    x_base_train = build_matrix(train_rows, columns, BASE_FEATURES, place_to_id)
    x_base_predict = build_matrix(predict_rows, columns, BASE_FEATURES, place_to_id)
    race_codes = [str(row[RACE_CODE]) for row in predict_rows]

    hist = make_hist_gradient_boosting()
    xgb = make_xgboost()
    hist.fit(x_base_train, y_train)
    xgb.fit(x_base_train, y_train)
    hist_probability = normalized_race_probabilities(
        hist.predict_proba(x_base_predict)[:, 1], race_codes
    )
    xgb_probability = normalized_race_probabilities(
        xgb.predict_proba(x_base_predict)[:, 1], race_codes
    )
    v2_probability = 0.5 * hist_probability + 0.5 * xgb_probability

    v4_scores, groups, labels = fit_v4_scores(
        train_rows, predict_rows, columns, place_to_id, v4_features
    )
    v4_probability = rank_probabilities(v4_scores, groups, v4_temperature)
    probability = V2_WEIGHT * v2_probability + V4_WEIGHT * v4_probability
    probability = normalize_groups(probability, groups)

    del y_train, x_base_train, x_base_predict, hist, xgb
    del hist_probability, xgb_probability, v2_probability, v4_probability
    gc.collect()
    return PredictionBlock(month, probability, labels, groups, predict_rows), v4_scores


class PowerCalibrator:
    def __init__(self) -> None:
        self.power = 1.0

    def fit(self, probability: np.ndarray, labels: np.ndarray, groups: list[np.ndarray]):
        winner_indices = np.asarray(
            [indices[np.flatnonzero(labels[indices] == 1)[0]] for indices in groups],
            dtype=np.int64,
        )

        def objective(log_power: float) -> float:
            adjusted = normalize_groups(probability ** float(np.exp(log_power)), groups)
            return float(-np.mean(np.log(np.maximum(adjusted[winner_indices], 1e-12))))

        result = minimize_scalar(
            objective, bounds=(np.log(0.20), np.log(5.0)), method="bounded"
        )
        self.power = float(np.exp(result.x))
        return self

    def predict(self, probability: np.ndarray, groups: list[np.ndarray]) -> np.ndarray:
        return normalize_groups(probability**self.power, groups)


class PlattCalibrator:
    def __init__(self) -> None:
        self.model = LogisticRegression(C=1e6, solver="lbfgs", max_iter=1000)

    @staticmethod
    def matrix(probability: np.ndarray) -> np.ndarray:
        clipped = np.clip(probability, 1e-8, 1.0 - 1e-8)
        return np.log(clipped / (1.0 - clipped)).reshape(-1, 1)

    def fit(self, probability: np.ndarray, labels: np.ndarray, groups: list[np.ndarray]):
        del groups
        self.model.fit(self.matrix(probability), labels)
        return self

    def predict(self, probability: np.ndarray, groups: list[np.ndarray]) -> np.ndarray:
        adjusted = self.model.predict_proba(self.matrix(probability))[:, 1]
        return normalize_groups(adjusted, groups)


class IsotonicCalibrator:
    def __init__(self) -> None:
        self.model = IsotonicRegression(out_of_bounds="clip", y_min=1e-6, y_max=1.0)

    def fit(self, probability: np.ndarray, labels: np.ndarray, groups: list[np.ndarray]):
        del groups
        self.model.fit(probability, labels)
        return self

    def predict(self, probability: np.ndarray, groups: list[np.ndarray]) -> np.ndarray:
        adjusted = np.maximum(self.model.predict(probability), 1e-12)
        return normalize_groups(adjusted, groups)


def combine_blocks(blocks: list[PredictionBlock]) -> tuple[np.ndarray, np.ndarray, list[np.ndarray]]:
    probability_parts: list[np.ndarray] = []
    label_parts: list[np.ndarray] = []
    groups: list[np.ndarray] = []
    offset = 0
    for block in blocks:
        probability_parts.append(block.probabilities)
        label_parts.append(block.labels)
        groups.extend(indices + offset for indices in block.groups)
        offset += len(block.probabilities)
    return np.concatenate(probability_parts), np.concatenate(label_parts), groups


def fit_calibrators(blocks: list[PredictionBlock]) -> dict[str, object]:
    probability, labels, groups = combine_blocks(blocks)
    return {
        "power_temperature": PowerCalibrator().fit(probability, labels, groups),
        "platt": PlattCalibrator().fit(probability, labels, groups),
        "isotonic": IsotonicCalibrator().fit(probability, labels, groups),
    }


def calibration_table(
    probability: np.ndarray, labels: np.ndarray, bins: np.ndarray
) -> tuple[list[dict], float, float]:
    table: list[dict] = []
    ece_sum = 0.0
    max_gap = 0.0
    total = len(probability)
    for low, high in zip(bins[:-1], bins[1:]):
        selected = (probability >= low) & (probability < high)
        n = int(selected.sum())
        if not n:
            table.append({"low": float(low), "high": float(min(high, 1.0)), "n": 0})
            continue
        predicted = float(probability[selected].mean())
        actual = float(labels[selected].mean())
        gap = actual - predicted
        ece_sum += n * abs(gap)
        max_gap = max(max_gap, abs(gap))
        table.append(
            {
                "low": float(low),
                "high": float(min(high, 1.0)),
                "n": n,
                "wins": int(labels[selected].sum()),
                "predicted": predicted,
                "actual": actual,
                "gap": gap,
            }
        )
    return table, ece_sum / total, max_gap


def metrics(
    probability: np.ndarray,
    labels: np.ndarray,
    groups: list[np.ndarray],
) -> dict:
    top_indices = np.asarray(
        [indices[np.argmax(probability[indices])] for indices in groups], dtype=np.int64
    )
    winner_indices = np.asarray(
        [indices[np.flatnonzero(labels[indices] == 1)[0]] for indices in groups],
        dtype=np.int64,
    )
    table, ece, mce = calibration_table(probability, labels, CALIBRATION_BINS)
    top_table, top_ece, top_mce = calibration_table(
        probability[top_indices], labels[top_indices], TOP1_BINS
    )
    clipped = np.clip(probability, 1e-12, 1.0 - 1e-12)
    binary_logloss = float(
        np.mean(-(labels * np.log(clipped) + (1 - labels) * np.log(1 - clipped)))
    )
    return {
        "races": len(groups),
        "boats": len(probability),
        "hits": int(labels[top_indices].sum()),
        "top1_rate": float(labels[top_indices].mean()),
        "brier": float(np.mean((probability - labels) ** 2)),
        "binary_logloss": binary_logloss,
        "winner_nll": float(-np.mean(np.log(np.maximum(probability[winner_indices], 1e-12)))),
        "ece": ece,
        "mce": mce,
        "top1_ece": top_ece,
        "top1_mce": top_mce,
        "mean_top1_probability": float(probability[top_indices].mean()),
        "calibration": table,
        "top1_calibration": top_table,
    }


def subgroup_metrics(records: list[dict], key: str) -> dict[str, dict]:
    grouped: dict[str, list[dict]] = defaultdict(list)
    for record in records:
        grouped[str(record[key])].append(record)
    result: dict[str, dict] = {}
    for name, subset in sorted(grouped.items()):
        probability = np.asarray([row["probability"] for row in subset], dtype=np.float64)
        labels = np.asarray([row["label"] for row in subset], dtype=np.int8)
        _, ece, mce = calibration_table(probability, labels, CALIBRATION_BINS)
        result[name] = {
            "boats": len(subset),
            "wins": int(labels.sum()),
            "mean_probability": float(probability.mean()),
            "actual_rate": float(labels.mean()),
            "brier": float(np.mean((probability - labels) ** 2)),
            "ece": ece,
            "mce": mce,
        }
    return result


def write_predictions(path: Path, records: list[dict]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fields = (
        "race_code", "race_date", "month", "place", "course", "label",
        "baseline", "power_temperature", "platt", "isotonic",
    )
    with gzip.open(path, "wt", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fields)
        writer.writeheader()
        writer.writerows({key: row[key] for key in fields} for row in records)


def plot_reliability(path: Path, results: dict[str, dict]) -> None:
    os.environ.setdefault("MPLCONFIGDIR", "/tmp/boatrace-matplotlib")
    import matplotlib.pyplot as plt

    colors = {
        "baseline": "#222222",
        "power_temperature": "#0072B2",
        "platt": "#E69F00",
        "isotonic": "#009E73",
    }
    labels = {
        "baseline": "Current v4 blend",
        "power_temperature": "Power temperature",
        "platt": "Platt",
        "isotonic": "Isotonic",
    }
    fig, axes = plt.subplots(1, 2, figsize=(13, 5.5))
    for name in METHODS:
        table = [row for row in results[name]["calibration"] if row["n"]]
        axes[0].plot(
            [row["predicted"] for row in table],
            [row["actual"] for row in table],
            marker="o", linewidth=1.8, color=colors[name], label=labels[name],
        )
        top_table = [row for row in results[name]["top1_calibration"] if row["n"]]
        axes[1].plot(
            [row["predicted"] for row in top_table],
            [row["actual"] for row in top_table],
            marker="o", linewidth=1.8, color=colors[name], label=labels[name],
        )
    for axis, title in zip(axes, ("All boats", "Top pick only")):
        axis.plot([0, 1], [0, 1], "--", color="#999999", linewidth=1)
        axis.set_title(title)
        axis.set_xlabel("Mean predicted probability")
        axis.set_ylabel("Observed win rate")
        axis.grid(alpha=0.25)
        axis.set_aspect("equal", adjustable="box")
    axes[0].set_xlim(0, 0.85)
    axes[0].set_ylim(0, 0.85)
    axes[1].set_xlim(0.20, 0.95)
    axes[1].set_ylim(0.20, 0.95)
    axes[1].legend(loc="lower right", fontsize=8)
    fig.suptitle("AI Win Rate v4 Reliability — 2026 Walk-forward")
    fig.tight_layout()
    path.parent.mkdir(parents=True, exist_ok=True)
    fig.savefig(path, dpi=160, bbox_inches="tight")
    plt.close(fig)


def print_summary(results: dict[str, dict]) -> None:
    print("\n【全期間比較】")
    print("方式                 的中率      Brier     勝者NLL    舟ECE   本命ECE")
    print("-" * 82)
    for name in METHODS:
        item = results[name]
        print(
            f"{name:<20} {item['top1_rate']*100:>7.3f}%  {item['brier']:.9f}  "
            f"{item['winner_nll']:.9f}  {item['ece']*100:>6.3f}pt  "
            f"{item['top1_ece']*100:>7.3f}pt"
        )


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--start", default="2026-01-01")
    parser.add_argument("--end-exclusive", default="2026-09-23")
    parser.add_argument("--history-start", default="2025-01-01")
    parser.add_argument("--calibration-months", type=int, default=3)
    parser.add_argument(
        "--output-prefix",
        default=str(ROOT / "analysis" / "output" / "ai_winrate_v4_reliability_2026"),
    )
    args = parser.parse_args()
    start = date.fromisoformat(args.start)
    end_exclusive = date.fromisoformat(args.end_exclusive)
    if args.calibration_months < 1:
        raise ValueError("calibration-months は1以上が必要です")

    output_prefix = Path(args.output_prefix)
    json_path = output_prefix.with_suffix(".json")
    csv_path = output_prefix.with_name(output_prefix.name + "_predictions.csv.gz")
    plot_path = output_prefix.with_suffix(".png")
    v4_features = variant_features()["v3_all_features"]

    print("v4信頼性検証用データを構築しています…", flush=True)
    rows, columns = load_rows(args.history_start, args.end_exclusive)
    places = sorted({str(row[PLACE_CODE]) for row in rows})
    place_to_id = {place: index + 1 for index, place in enumerate(places)}

    december = previous_month(start)
    november = previous_month(december)
    november_train = [row for row in rows if str(row[RACE_DATE]) < november.isoformat()]
    november_rows = [
        row for row in rows
        if november.isoformat() <= str(row[RACE_DATE]) < december.isoformat()
    ]
    print(f"{november:%Y-%m} で初期v4温度を求めています…", flush=True)
    november_scores, november_groups, november_labels = fit_v4_scores(
        november_train, november_rows, columns, place_to_id, v4_features
    )
    v4_temperature = calibrate_temperature(
        november_scores, november_labels, november_groups
    )
    del november_train, november_rows, november_scores, november_groups, november_labels
    gc.collect()

    december_train = [row for row in rows if str(row[RACE_DATE]) < december.isoformat()]
    december_rows = [
        row for row in rows
        if december.isoformat() <= str(row[RACE_DATE]) < start.isoformat()
    ]
    print(f"{december:%Y-%m} のout-of-fold確率を作っています…", flush=True)
    december_block, december_scores = fit_baseline_block(
        december.strftime("%Y-%m"), december_train, december_rows, columns,
        place_to_id, v4_features, v4_temperature,
    )
    v4_temperature = calibrate_temperature(
        december_scores, december_block.labels, december_block.groups
    )
    calibration_blocks: deque[PredictionBlock] = deque(
        [december_block], maxlen=args.calibration_months
    )
    del december_train, december_rows, december_scores
    gc.collect()

    prediction_records: list[dict] = []
    method_parts: dict[str, list[np.ndarray]] = {name: [] for name in METHODS}
    label_parts: list[np.ndarray] = []
    all_groups: list[np.ndarray] = []
    month_results: dict[str, dict] = {}
    group_offset = 0
    current = start
    while current < end_exclusive:
        month_end = min(following_month(current), end_exclusive)
        month_name = current.strftime("%Y-%m")
        train_rows = [row for row in rows if str(row[RACE_DATE]) < current.isoformat()]
        test_rows = [
            row for row in rows
            if current.isoformat() <= str(row[RACE_DATE]) < month_end.isoformat()
        ]
        if not train_rows or not test_rows:
            break

        calibrators = fit_calibrators(list(calibration_blocks))
        print(
            f"{month_name} を検証中（補正学習: "
            f"{calibration_blocks[0].month}～{calibration_blocks[-1].month}）…",
            flush=True,
        )
        block, v4_scores = fit_baseline_block(
            month_name, train_rows, test_rows, columns, place_to_id,
            v4_features, v4_temperature,
        )
        probabilities = {"baseline": block.probabilities}
        probabilities.update(
            {
                name: calibrator.predict(block.probabilities, block.groups)
                for name, calibrator in calibrators.items()
            }
        )
        month_results[month_name] = {
            name: metrics(probability, block.labels, block.groups)
            for name, probability in probabilities.items()
        }
        print(
            "  " + " / ".join(
                f"{name}: Brier {month_results[month_name][name]['brier']:.6f}, "
                f"ECE {month_results[month_name][name]['ece']*100:.2f}pt"
                for name in METHODS
            ),
            flush=True,
        )

        for name in METHODS:
            method_parts[name].append(probabilities[name])
        label_parts.append(block.labels)
        all_groups.extend(indices + group_offset for indices in block.groups)
        group_offset += len(block.labels)

        for index, row in enumerate(block.rows):
            prediction_records.append(
                {
                    "race_code": str(row[RACE_CODE]),
                    "race_date": str(row[RACE_DATE]),
                    "month": month_name,
                    "place": str(row[PLACE_CODE]),
                    "course": int(row[COURSE]),
                    "label": int(block.labels[index]),
                    **{name: float(probabilities[name][index]) for name in METHODS},
                }
            )

        v4_temperature = calibrate_temperature(v4_scores, block.labels, block.groups)
        calibration_blocks.append(block)
        del train_rows, test_rows, calibrators, probabilities, v4_scores
        gc.collect()
        current = month_end

    labels = np.concatenate(label_parts)
    combined = {name: np.concatenate(parts) for name, parts in method_parts.items()}
    results = {
        name: metrics(probability, labels, all_groups)
        for name, probability in combined.items()
    }

    baseline_records = [
        {
            "place": row["place"],
            "course": row["course"],
            "month": row["month"],
            "label": row["label"],
            "probability": row["baseline"],
        }
        for row in prediction_records
    ]
    subgroup = {
        "course": subgroup_metrics(baseline_records, "course"),
        "place": subgroup_metrics(baseline_records, "place"),
        "month": subgroup_metrics(baseline_records, "month"),
    }
    report = {
        "configuration": {
            "start": args.start,
            "end_exclusive": args.end_exclusive,
            "history_start": args.history_start,
            "v2_weight": V2_WEIGHT,
            "v4_weight": V4_WEIGHT,
            "calibration_window_months": args.calibration_months,
            "calibration_policy": "past out-of-fold months only; race-normalized after calibration",
        },
        "results": results,
        "months": month_results,
        "baseline_subgroups": subgroup,
    }
    json_path.parent.mkdir(parents=True, exist_ok=True)
    json_path.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
    write_predictions(csv_path, prediction_records)
    plot_reliability(plot_path, results)
    print_summary(results)
    print(f"\nJSON: {json_path}")
    print(f"予測明細: {csv_path}")
    print(f"信頼性曲線: {plot_path}")
    print("RESULT_JSON=" + json.dumps(results, ensure_ascii=False), flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
