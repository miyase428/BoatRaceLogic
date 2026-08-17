#!/usr/bin/env python3
# -*- coding: utf-8 -*-

from __future__ import annotations

import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
ANALYSIS_DIR = REPO_ROOT / "analysis"
FORECAST_DIR = REPO_ROOT / "forecast"
sys.path.insert(0, str(ANALYSIS_DIR))
sys.path.insert(0, str(FORECAST_DIR))

from slit_validate_v2 import connect_db
from base_winrate_sum_compare import load_snapshots, load_sum_features
from corrected_winrate_live import (
    load_current_exhibition,
    load_sum_stats,
    current_sum_scores,
)
from base_winrate_race import load_target


def main():
    if len(sys.argv) != 2:
        print("Usage: python3 analysis/debug_sum_parity.py RACE_CODE")
        return 1

    race_code = sys.argv[1].strip().upper()

    with connect_db() as conn:
        target_date, place_code, stadium_name, _ = load_target(conn, race_code)
        exhibition = load_current_exhibition(conn, race_code)
        features = load_sum_features()
        feature_cols = features[place_code]
        live_stats = load_sum_stats(conn, race_code, target_date, place_code, feature_cols)
        live_scores, live_detail = current_sum_scores(exhibition, feature_cols, live_stats)

    snapshots, _, _ = load_snapshots(target_date, target_date)
    snap = next((s for s in snapshots if str(s.race_code) == race_code), None)
    if snap is None:
        raise RuntimeError("STEP8-4側snapshotが見つかりません")

    boats = sorted(snap.boats, key=lambda b: b.lane)

    print("=" * 132)
    print("SUM parity diagnostic: STEP8-4 load_snapshots vs Web live SQL")
    print("=" * 132)
    print(f"race       : {race_code}")
    print(f"date/place : {target_date} / {place_code}:{stadium_name}")
    print(f"features   : {feature_cols}")
    print()
    print("艇 C 区間             STEP score    LIVE score    score差       STEP intervalN LIVE intervalN  STEP courseN LIVE courseN")
    print("-" * 132)

    for i, b in enumerate(boats):
        lane = b.lane
        d = live_detail[lane]
        course = exhibition[lane]["course"]
        step_score = float(b.sum_scores["SUM_RAW"])
        live_score = float(live_scores[i])
        print(
            f"{lane:>1}  {course} {d['interval']:<14} "
            f"{step_score:+.9f}  {live_score:+.9f}  {live_score-step_score:+.9f}  "
            f"{b.sum_interval_n:>10} {d['interval_n']:>13}  "
            f"{b.sum_course_n:>10} {d['course_n']:>12}"
        )

    print("=" * 132)
    print("・intervalN/courseNが違えば母集団条件の差")
    print("・Nが同じでscoreだけ違えば勝数集計条件の差")
    print("・全て同じならSUM適用順/正規化側を確認")
    print("=" * 132)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
