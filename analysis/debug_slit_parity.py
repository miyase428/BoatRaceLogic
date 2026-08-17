#!/usr/bin/env python3
# -*- coding: utf-8 -*-

from __future__ import annotations

import sys
from datetime import timedelta
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
ANALYSIS_DIR = REPO_ROOT / "analysis"
FORECAST_DIR = REPO_ROOT / "forecast"
sys.path.insert(0, str(ANALYSIS_DIR))
sys.path.insert(0, str(FORECAST_DIR))

from base_winrate_sum_compare import load_snapshots
from base_winrate_slit_compare import (
    SLIT_BUFF_DAYS,
    inclusive_window_start,
    build_slit_records,
    learn_buff,
    load_lane_to_ex_course,
    sum_corrected_probs,
    apply_slit_buff,
)
from base_winrate_corrected_race import SLIT_ALPHA
import corrected_winrate_live as live


def main():
    if len(sys.argv) != 2:
        print("Usage: python3 analysis/debug_slit_parity.py RACE_CODE")
        return 1

    race_code = sys.argv[1].strip()
    target_date = live.datetime.strptime(race_code[:8], "%Y%m%d").date() if hasattr(live, "datetime") else None
    if target_date is None:
        from datetime import datetime
        target_date = datetime.strptime(race_code[:8], "%Y%m%d").date()

    snapshots, _, _ = load_snapshots(target_date, target_date)
    snap = next((s for s in snapshots if str(s.race_code) == race_code), None)
    if snap is None:
        raise RuntimeError("STEP8-4 snapshot not found")

    boats = sorted(snap.boats, key=lambda b: b.lane)
    sum_probs = sum_corrected_probs(snap)

    buff_end = target_date - timedelta(days=1)
    buff_start = inclusive_window_start(buff_end, SLIT_BUFF_DAYS)
    records, _, _ = build_slit_records(buff_start, target_date)
    step_buff, rows, _ = learn_buff(records, buff_start, buff_end)
    rec = records[race_code]

    cmap = load_lane_to_ex_course(target_date, target_date)[race_code]
    step_final = apply_slit_buff(sum_probs, boats, rec["pid"], cmap, step_buff, SLIT_ALPHA)

    predict, buff_data, live_buff = live.slit_prediction_and_buff(race_code, target_date)
    exhibition = {lane: {"course": cmap[lane]} for lane in range(1, 7)}
    live_final, live_raw = live.apply_slit(sum_probs, exhibition, live_buff)

    print("=" * 120)
    print("Slit parity diagnostic: STEP8-4 direct buff vs Web live buff")
    print("=" * 120)
    print(f"race             : {race_code}")
    print(f"pid              : STEP={rec['pid']} LIVE={predict.get('pattern_id')}")
    print(f"training races   : STEP={len(rows)} LIVE={buff_data.get('training_races')}")
    print(f"alpha            : {SLIT_ALPHA}")
    print()
    print("艇 C STEP buff            LIVE buff            buff差               STEP final          LIVE final          final差")
    print("-" * 120)
    for idx, b in enumerate(boats):
        lane = b.lane
        course = cmap[lane]
        sb = float(step_buff[rec["pid"]][course]["win"])
        lb = float(live_raw[idx])
        sf = float(step_final[idx])
        lf = float(live_final[idx])
        print(
            f"{lane}  {course} {sb:+.15f}  {lb:+.15f}  {lb-sb:+.15f}  "
            f"{sf*100:12.8f}%  {lf*100:12.8f}%  {(lf-sf)*100:+.8f}pt"
        )
    print("=" * 120)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
