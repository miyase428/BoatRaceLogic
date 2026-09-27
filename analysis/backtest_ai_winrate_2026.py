#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""AI1着率 v1 の2026年ウォークフォワード検証。

各月のモデルは当月より前の完了レースだけで学習する。
当月の結果・選手成績・モーター成績を学習へ混ぜないため、実運用に近い
過去検証になる。比較対象は展示進入の1コース固定。
"""

from __future__ import annotations

import argparse
import json
import sys
from datetime import date
from pathlib import Path

import numpy as np

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "forecast"))

from ai_winrate_features import normalized_race_probabilities  # noqa: E402
from train_ai_winrate import (  # noqa: E402
    COURSE,
    IS_WINNER,
    PLACE_CODE,
    RACE_CODE,
    RACE_DATE,
    build_training_matrix,
    load_rows,
    make_model,
)


def month_starts(start: date, end_exclusive: date) -> list[date]:
    months = []
    current = date(start.year, start.month, 1)
    while current < end_exclusive:
        months.append(current)
        current = date(current.year + (current.month == 12), (current.month % 12) + 1, 1)
    return months


def following_month(value: date) -> date:
    return date(value.year + (value.month == 12), (value.month % 12) + 1, 1)


def score_month(model, rows: list[tuple], place_to_id: dict[str, int]) -> dict:
    matrix = build_training_matrix(rows, place_to_id)
    probabilities = model.predict_proba(matrix)[:, 1]
    codes = [str(row[RACE_CODE]) for row in rows]
    normalized = normalized_race_probabilities(probabilities, codes)
    labels = np.asarray([bool(row[IS_WINNER]) for row in rows], dtype=np.int8)

    by_race: dict[str, list[int]] = {}
    for index, race_code in enumerate(codes):
        by_race.setdefault(race_code, []).append(index)

    ai_hits = 0
    course1_hits = 0
    top_probabilities = []
    for indices in by_race.values():
        # 同確率時は進入の内側を優先して、毎回同じ結果にする。
        top = max(indices, key=lambda i: (normalized[i], -int(rows[i][COURSE])))
        ai_hits += int(labels[top])
        course1 = next((i for i in indices if int(rows[i][COURSE]) == 1), None)
        course1_hits += int(course1 is not None and labels[course1])
        top_probabilities.append(float(normalized[top]))

    races = len(by_race)
    return {
        "races": races,
        "ai_hits": ai_hits,
        "course1_hits": course1_hits,
        "ai_top1_rate": ai_hits / races if races else 0.0,
        "course1_rate": course1_hits / races if races else 0.0,
        "ai_mean_top_probability": float(np.mean(top_probabilities)) if top_probabilities else 0.0,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--start", default="2026-01-01")
    parser.add_argument("--end-exclusive", default="2026-09-23")
    parser.add_argument("--history-start", default="2025-01-01")
    args = parser.parse_args()

    start = date.fromisoformat(args.start)
    end_exclusive = date.fromisoformat(args.end_exclusive)
    if start >= end_exclusive:
        raise SystemExit("start は end-exclusive より前にしてください")

    results = []
    for month in month_starts(start, end_exclusive):
        next_month = min(following_month(month), end_exclusive)
        # 年間全行を持ち続けず、当月の学習/評価に必要な範囲だけ読む。
        # 特徴量の履歴窓はtrain_ai_winrate側で別途過去展示まで遡るため、
        # ここでの開始日は学習対象を切るだけで未来情報にはならない。
        print(f"{month:%Y-%m} の学習・検証データを読み込んでいます…", flush=True)
        rows = load_rows(args.history_start, next_month.isoformat())
        places = sorted({str(row[PLACE_CODE]) for row in rows})
        place_to_id = {place: index + 1 for index, place in enumerate(places)}
        train_rows = [row for row in rows if str(row[RACE_DATE]) < month.isoformat()]
        test_rows = [
            row for row in rows
            if month.isoformat() <= str(row[RACE_DATE]) < next_month.isoformat()
        ]
        if not train_rows or not test_rows:
            continue

        model = make_model()
        model.fit(
            build_training_matrix(train_rows, place_to_id),
            np.asarray([bool(row[IS_WINNER]) for row in train_rows], dtype=np.int8),
        )
        metrics = score_month(model, test_rows, place_to_id)
        metrics["month"] = month.strftime("%Y-%m")
        metrics["training_races"] = len(train_rows) // 6
        results.append(metrics)
        print(
            f"{metrics['month']}  {metrics['races']}R  "
            f"AI {metrics['ai_top1_rate'] * 100:.2f}%  "
            f"1C {metrics['course1_rate'] * 100:.2f}%  "
            f"差 {(metrics['ai_top1_rate'] - metrics['course1_rate']) * 100:+.2f}pt",
            flush=True,
        )

    total_races = sum(row["races"] for row in results)
    total_ai = sum(row["ai_hits"] for row in results)
    total_course1 = sum(row["course1_hits"] for row in results)
    summary = {
        "period": f"{start.isoformat()} to {(end_exclusive.fromordinal(end_exclusive.toordinal() - 1)).isoformat()}",
        "months": results,
        "total": {
            "races": total_races,
            "ai_hits": total_ai,
            "course1_hits": total_course1,
            "ai_top1_rate": total_ai / total_races if total_races else 0.0,
            "course1_rate": total_course1 / total_races if total_races else 0.0,
            "difference_points": (total_ai - total_course1) * 100.0 / total_races if total_races else 0.0,
        },
    }
    print("RESULT_JSON=" + json.dumps(summary, ensure_ascii=False), flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
