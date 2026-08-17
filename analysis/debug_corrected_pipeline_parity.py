#!/usr/bin/env python3
# -*- coding: utf-8 -*-

from __future__ import annotations

import sys
from datetime import datetime
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
ANALYSIS_DIR = REPO_ROOT / "analysis"
FORECAST_DIR = REPO_ROOT / "forecast"
sys.path.insert(0, str(ANALYSIS_DIR))
sys.path.insert(0, str(FORECAST_DIR))

from slit_validate_v2 import connect_db
from base_winrate_sum_compare import load_snapshots
from base_winrate_slit_compare import sum_corrected_probs
import corrected_winrate_live as live
import corrected_winrate_live_exact as exact


def main():
    if len(sys.argv) != 2:
        print("Usage: python3 analysis/debug_corrected_pipeline_parity.py RACE_CODE")
        return 1

    race_code = sys.argv[1].strip().upper()
    target_date = datetime.strptime(race_code[:8], "%Y%m%d").date()

    snapshots, _, _ = load_snapshots(target_date, target_date)
    snap = next((s for s in snapshots if str(s.race_code) == race_code), None)
    if snap is None:
        raise RuntimeError("STEP8-4 snapshot not found")

    step_boats = sorted(snap.boats, key=lambda b: b.lane)
    step_ex = [float(b.ex_total_prob) for b in step_boats]
    step_sum = [float(x) for x in sum_corrected_probs(snap)]

    with connect_db() as conn:
        target_date2, place_code, stadium_name, boats = live.load_target(conn, race_code)
        exhibition = live.load_current_exhibition(conn, race_code)
        live_base, _ = live.build_remapped_base(
            conn, race_code, target_date2, place_code, boats, exhibition
        )
        venue_avg = live.venue_exhibition_average(conn, race_code, target_date2, place_code)
        ex_scores, _ = live.calc_ex_total_scores(exhibition, venue_avg)
        live_ex = live.apply_centered_score(live_base, ex_scores, live.EX_TOTAL_BETA)

        features = live.load_sum_features()
        feature_cols = features[place_code]
        sum_stats = exact.load_sum_stats_exact(
            conn, race_code, target_date2, place_code, feature_cols
        )
        sum_scores, _ = live.current_sum_scores(exhibition, feature_cols, sum_stats)
        live_sum = live.apply_centered_score(live_ex, sum_scores, live.SUM_GAMMA)

    print("=" * 160)
    print("Corrected pipeline parity: STEP8-4 snapshot vs Web exact live")
    print("=" * 160)
    print(f"race       : {race_code}")
    print(f"date/place : {target_date2} / {place_code}:{stadium_name}")
    print()
    print("艇   STEP EX              LIVE EX              EX差                 STEP SUM             LIVE SUM             SUM差")
    print("-" * 160)
    for i in range(6):
        print(
            f"{i+1}  {step_ex[i]:.15f}  {live_ex[i]:.15f}  {live_ex[i]-step_ex[i]:+.15f}  "
            f"{step_sum[i]:.15f}  {live_sum[i]:.15f}  {live_sum[i]-step_sum[i]:+.15f}"
        )

    print("=" * 160)
    print("※ STEP8-4 snapshotにはEX_TOTAL後とSUM後が保存される。")
    print("※ EX差が0でSUM差だけあればSUM適用側、EX差があれば基本リマップ/EX_TOTAL以前の差。")
    print("=" * 160)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
