#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""AI1着率 v2（HGB + XGBoost）の2026年ウォークフォワード検証。"""

from __future__ import annotations

import argparse
import gc
import json
import sys
from datetime import date
from pathlib import Path

import numpy as np

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "forecast"))

from ai_winrate_features import normalized_race_probabilities  # noqa: E402
from ai_winrate_v2_models import (  # noqa: E402
    blend_probabilities,
    make_hist_gradient_boosting,
    make_xgboost,
)
from train_ai_winrate import (  # noqa: E402
    COURSE,
    IS_WINNER,
    PLACE_CODE,
    RACE_CODE,
    RACE_DATE,
    build_training_matrix,
    load_rows,
)


def following_month(value: date) -> date:
    return date(value.year + (value.month == 12), (value.month % 12) + 1, 1)


def score(raw_probabilities: np.ndarray, rows: list[tuple]) -> dict:
    codes = [str(row[RACE_CODE]) for row in rows]
    probabilities = normalized_race_probabilities(raw_probabilities, codes)
    labels = np.asarray([bool(row[IS_WINNER]) for row in rows], dtype=np.int8)
    by_race: dict[str, list[int]] = {}
    for index, code in enumerate(codes):
        by_race.setdefault(code, []).append(index)

    hits = 0
    top_boats: dict[str, int] = {}
    for code, indices in by_race.items():
        top = max(indices, key=lambda i: (probabilities[i], -int(rows[i][COURSE])))
        hits += int(labels[top])
        top_boats[code] = int(rows[top][COURSE])

    winner_probabilities = probabilities[labels == 1]
    return {
        "races": len(by_race),
        "hits": hits,
        "top1_rate": hits / len(by_race),
        "brier": float(np.mean((probabilities - labels) ** 2)),
        "winner_nll": float(-np.mean(np.log(np.maximum(winner_probabilities, 1e-12)))),
        "top_boats": top_boats,
    }


def blank_result() -> dict:
    return {"races": 0, "hits": 0, "brier_sum": 0.0, "nll_sum": 0.0, "months": []}


def add_result(target: dict, metric: dict, month: str) -> None:
    target["races"] += metric["races"]
    target["hits"] += metric["hits"]
    target["brier_sum"] += metric["brier"] * metric["races"]
    target["nll_sum"] += metric["winner_nll"] * metric["races"]
    target["months"].append({
        "month": month,
        **{key: value for key, value in metric.items() if key != "top_boats"},
    })


def finalize(record: dict) -> dict:
    races = record["races"]
    return {
        "races": races,
        "hits": record["hits"],
        "top1_rate": record["hits"] / races,
        "brier": record["brier_sum"] / races,
        "winner_nll": record["nll_sum"] / races,
        "months": record["months"],
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--start", default="2026-01-01")
    parser.add_argument("--end-exclusive", default="2026-09-23")
    parser.add_argument("--history-start", default="2025-01-01")
    parser.add_argument("--hist-weight", type=float, default=0.5)
    args = parser.parse_args()

    start = date.fromisoformat(args.start)
    end_exclusive = date.fromisoformat(args.end_exclusive)
    records = {name: blank_result() for name in ("hist_gradient_boosting", "xgboost_cpu", "ai_winrate_v2")}
    pair_counts = {"both_hit": 0, "v2_only": 0, "hgb_only": 0, "both_miss": 0, "same_pick": 0}

    current = start
    while current < end_exclusive:
        month_end = min(following_month(current), end_exclusive)
        month_name = current.strftime("%Y-%m")
        print(f"{month_name} の学習・検証データを読み込んでいます…", flush=True)
        rows = load_rows(args.history_start, month_end.isoformat())
        places = sorted({str(row[PLACE_CODE]) for row in rows})
        place_to_id = {place: index + 1 for index, place in enumerate(places)}
        train_rows = [row for row in rows if str(row[RACE_DATE]) < current.isoformat()]
        test_rows = [
            row for row in rows
            if current.isoformat() <= str(row[RACE_DATE]) < month_end.isoformat()
        ]
        x_train = build_training_matrix(train_rows, place_to_id)
        x_test = build_training_matrix(test_rows, place_to_id)
        y_train = np.asarray([bool(row[IS_WINNER]) for row in train_rows], dtype=np.int8)

        print(f"{month_name} のHGBとXGBoostを学習しています…", flush=True)
        hist_model = make_hist_gradient_boosting()
        xgb_model = make_xgboost()
        hist_model.fit(x_train, y_train)
        xgb_model.fit(x_train, y_train)
        hist_raw = hist_model.predict_proba(x_test)[:, 1]
        xgb_raw = xgb_model.predict_proba(x_test)[:, 1]
        test_codes = [str(row[RACE_CODE]) for row in test_rows]
        v2_raw = blend_probabilities(hist_raw, xgb_raw, test_codes, args.hist_weight)

        month_metrics = {
            "hist_gradient_boosting": score(hist_raw, test_rows),
            "xgboost_cpu": score(xgb_raw, test_rows),
            "ai_winrate_v2": score(v2_raw, test_rows),
        }
        for name, metric in month_metrics.items():
            add_result(records[name], metric, month_name)

        labels_by_code = {
            str(row[RACE_CODE]): int(row[COURSE])
            for row in test_rows if bool(row[IS_WINNER])
        }
        hgb_picks = month_metrics["hist_gradient_boosting"]["top_boats"]
        v2_picks = month_metrics["ai_winrate_v2"]["top_boats"]
        for code, winner in labels_by_code.items():
            hgb_hit = hgb_picks[code] == winner
            v2_hit = v2_picks[code] == winner
            pair_counts["both_hit" if hgb_hit and v2_hit else "v2_only" if v2_hit else "hgb_only" if hgb_hit else "both_miss"] += 1
            pair_counts["same_pick"] += int(hgb_picks[code] == v2_picks[code])

        print(
            f"{month_name}  {month_metrics['ai_winrate_v2']['races']}R  "
            f"HGB {month_metrics['hist_gradient_boosting']['top1_rate'] * 100:.2f}%  "
            f"XGB {month_metrics['xgboost_cpu']['top1_rate'] * 100:.2f}%  "
            f"v2 {month_metrics['ai_winrate_v2']['top1_rate'] * 100:.2f}%",
            flush=True,
        )
        del rows, train_rows, test_rows, x_train, x_test, y_train
        del hist_model, xgb_model, hist_raw, xgb_raw, v2_raw, month_metrics
        gc.collect()
        current = month_end

    final = {name: finalize(record) for name, record in records.items()}
    final["pair_v2_vs_hgb"] = pair_counts
    final["hist_weight"] = args.hist_weight
    print("RESULT_JSON=" + json.dumps(final, ensure_ascii=False), flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
