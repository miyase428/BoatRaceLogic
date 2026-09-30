#!/usr/bin/env python3
"""Evaluate only predictions that were immutably saved before race results."""

from __future__ import annotations

import argparse
import csv
import json
import math
import random
import sys
from collections import defaultdict
from pathlib import Path

import numpy as np
import psycopg2

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "common"))
from db_config import load_db_config

OUTPUT = ROOT / "analysis" / "output" / "ai_winrate_forward"
MODELS = {
    "v6_final": "v6_probability",
    "v6_autogluon25": "blend_autogluon25_probability",
    "v6_ebm05": "blend_ebm05_probability",
    "autogluon_reference": "autogluon_probability",
    "ebm_reference": "ebm_probability",
}
PRIMARY = ("v6_final", "v6_autogluon25", "v6_ebm05")
CHECKPOINTS = (100, 300, 500, 1000)


def read_predictions(root: Path) -> list[dict]:
    rows = []
    for path in sorted(root.glob("20??????/prediction.csv")):
        with path.open(encoding="utf-8", newline="") as handle:
            rows.extend(row for row in csv.DictReader(handle) if row["date"] >= "2026-10-01")
    return rows


def results(codes: list[str]) -> dict[tuple[str, str], int]:
    if not codes:
        return {}
    sql = """
SELECT race_code, player_id::text, TRIM(rank::text)
FROM boat_race.race_result_detail
WHERE race_code = ANY(%s) AND TRIM(rank::text) ~ '^[1-6]$'
"""
    with psycopg2.connect(**load_db_config()) as conn:
        with conn.cursor() as cursor:
            cursor.execute(sql, (codes,))
            return {(str(code), str(player).strip()): int(rank) for code, player, rank in cursor.fetchall()}


def complete_races(rows: list[dict], finish: dict[tuple[str, str], int]) -> list[list[dict]]:
    grouped = defaultdict(list)
    for row in rows:
        grouped[row["race_code"]].append(row)
    output = []
    for code, values in sorted(grouped.items(), key=lambda item: (item[1][0]["date"], item[0])):
        if len(values) != 6 or len({int(v["boat_number"]) for v in values}) != 6:
            continue
        for row in values:
            row["finish_order"] = finish.get((code, str(row["player_id"]).strip()))
        if sorted(row["finish_order"] for row in values if row["finish_order"] is not None) != list(range(1, 7)):
            continue
        output.append(sorted(values, key=lambda row: int(row["boat_number"])))
    return output


def race_errors(race: list[dict], model: str) -> tuple[float, float, float, int, float, float]:
    column = MODELS[model]
    p = np.asarray([float(row[column]) for row in race], dtype=np.float64)
    y = np.asarray([int(row["finish_order"] == 1) for row in race], dtype=np.float64)
    winner = int(np.argmax(y))
    return float(np.mean((p-y)**2)), float(np.sum((p-y)**2)), float(-math.log(max(p[winner], 1e-12))), int(np.argmax(p) == winner), float(p[winner]), float(p.sum())


def metric(races: list[list[dict]], model: str) -> dict:
    values = [race_errors(race, model) for race in races]
    if not values:
        return {"races": 0, "race_brier_mean": None, "race_brier_sum": None, "winner_log_loss": None, "top1_accuracy": None, "winner_probability_mean": None, "probability_sum_mean": None, "probability_sum_max_abs_error": None}
    data = np.asarray(values, dtype=np.float64)
    return {"races": len(races), "race_brier_mean": float(data[:,0].mean()), "race_brier_sum": float(data[:,1].mean()), "winner_log_loss": float(data[:,2].mean()), "top1_accuracy": float(data[:,3].mean()), "winner_probability_mean": float(data[:,4].mean()), "probability_sum_mean": float(data[:,5].mean()), "probability_sum_max_abs_error": float(np.max(np.abs(data[:,5]-1.0)))}


def write_csv(path: Path, rows: list[dict], fields: list[str]) -> None:
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fields, lineterminator="\n")
        writer.writeheader(); writer.writerows(rows)


def scoped_rows(races: list[list[dict]], scope: str) -> list[dict]:
    keys = {
        "daily": lambda race: race[0]["date"],
        "venue": lambda race: race[0]["place"],
        "course": lambda race: str(next(int(row["entry_course"]) for row in race if int(row["finish_order"]) == 1)),
        "month": lambda race: race[0]["date"][:7],
    }
    grouped = defaultdict(list)
    for race in races: grouped[keys[scope](race)].append(race)
    output = []
    for value, selected in sorted(grouped.items()):
        for model in PRIMARY:
            item = {"scope": scope, "scope_value": value, "model": model, **metric(selected, model)}
            if model != "v6_final": item["brier_delta_vs_v6"] = item["race_brier_mean"] - metric(selected, "v6_final")["race_brier_mean"]
            else: item["brier_delta_vs_v6"] = 0.0
            output.append(item)
    return output


def bootstrap(races: list[list[dict]], model: str, samples: int = 5000, seed: int = 20261001) -> dict:
    if len(races) < 100:
        return {"model": model, "status": "waiting_for_100_races", "races": len(races), "resamples": samples, "mean_delta": None, "ci_lower": None, "ci_upper": None, "improvement_fraction": None}
    deltas = np.asarray([race_errors(race, model)[0] - race_errors(race, "v6_final")[0] for race in races])
    rng = np.random.default_rng(seed)
    means = np.asarray([deltas[rng.integers(0, len(deltas), len(deltas))].mean() for _ in range(samples)])
    return {"model": model, "status": "reference" if len(races) < 500 else "trend_check", "races": len(races), "resamples": samples, "mean_delta": float(deltas.mean()), "ci_lower": float(np.quantile(means, .025)), "ci_upper": float(np.quantile(means, .975)), "improvement_fraction": float(np.mean(means < 0))}


def main() -> int:
    parser = argparse.ArgumentParser(); parser.add_argument("--output-root", default=str(OUTPUT)); args = parser.parse_args()
    root = Path(args.output_root); root.mkdir(parents=True, exist_ok=True)
    predictions = read_predictions(root)
    finish = results(sorted({row["race_code"] for row in predictions}))
    races = complete_races(predictions, finish)
    summary = []
    baseline = metric(races, "v6_final")
    for model in MODELS:
        item = {"model": model, **metric(races, model)}
        item["brier_delta_vs_v6"] = None if not races else item["race_brier_mean"] - baseline["race_brier_mean"]
        summary.append(item)
    metric_fields = ["scope", "scope_value", "model", "races", "race_brier_mean", "race_brier_sum", "winner_log_loss", "top1_accuracy", "winner_probability_mean", "probability_sum_mean", "probability_sum_max_abs_error", "brier_delta_vs_v6"]
    summary_fields = ["model", "races", "race_brier_mean", "race_brier_sum", "winner_log_loss", "top1_accuracy", "winner_probability_mean", "probability_sum_mean", "probability_sum_max_abs_error", "brier_delta_vs_v6"]
    write_csv(root / "forward_summary.csv", summary, summary_fields)
    for scope in ("daily", "venue", "course", "month"):
        write_csv(root / f"forward_{scope}.csv", scoped_rows(races, scope), metric_fields)
    boot = [bootstrap(races, model) for model in PRIMARY[1:]]
    write_csv(root / "forward_bootstrap.csv", boot, list(boot[0]))

    cumulative = []
    dates = sorted({race[0]["date"] for race in races})
    for value in dates:
        selected = [race for race in races if race[0]["date"] <= value]
        base, ag, ebm = (metric(selected, model)["race_brier_mean"] for model in PRIMARY)
        cumulative.append({"date": value, "cumulative_races": len(selected), "v6_cumulative_brier": base, "autogluon25_cumulative_brier": ag, "ebm05_cumulative_brier": ebm, "autogluon25_delta": ag-base, "ebm05_delta": ebm-base})
    write_csv(root / "forward_cumulative.csv", cumulative, ["date", "cumulative_races", "v6_cumulative_brier", "autogluon25_cumulative_brier", "ebm05_cumulative_brier", "autogluon25_delta", "ebm05_delta"])
    checkpoint = []
    for count in CHECKPOINTS:
        selected = races[:count] if len(races) >= count else []
        checkpoint.append({"checkpoint": count, "status": "reached" if selected else "waiting", "available_races": len(races), "v6_brier": metric(selected, "v6_final")["race_brier_mean"], "autogluon25_brier": metric(selected, "v6_autogluon25")["race_brier_mean"], "ebm05_brier": metric(selected, "v6_ebm05")["race_brier_mean"]})
    write_csv(root / "forward_checkpoints.csv", checkpoint, list(checkpoint[0]))
    print(json.dumps({"status": "ok", "saved_prediction_rows": len(predictions), "evaluated_races": len(races), "note": "100R/300R are reference only; evaluate trends from 500R and emphasize 1000R."}, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
