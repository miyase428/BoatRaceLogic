#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""補正後1着率 build_remapped_base の内部処理を分解計測する。

Usage:
  python3 analysis/base_remap_stage_profile.py 20260820OMR01
"""

from __future__ import annotations

import sys
import time
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
FORECAST_DIR = REPO_ROOT / "forecast"
ANALYSIS_DIR = REPO_ROOT / "analysis"
sys.path.insert(0, str(FORECAST_DIR))
sys.path.insert(0, str(ANALYSIS_DIR))

from slit_validate_v2 import connect_db  # noqa: E402
import corrected_winrate_live as live  # noqa: E402
import corrected_winrate_live_exact as exact  # noqa: E402


def timed(label, fn):
    t0 = time.perf_counter()
    value = fn()
    ms = (time.perf_counter() - t0) * 1000.0
    print(f"{label:<38} {ms:10.1f} ms  ({ms/1000.0:6.2f} sec)")
    return value, ms


def main() -> int:
    race_code = (sys.argv[1] if len(sys.argv) >= 2 else "").strip().upper()
    if len(race_code) < 13:
        print("Usage: python3 analysis/base_remap_stage_profile.py RACE_CODE", file=sys.stderr)
        return 1

    print("=" * 92)
    print("補正後1着率 build_remapped_base 内部 段階計測")
    print("=" * 92)
    print(f"race_code : {race_code}\n")

    with connect_db() as conn:
        (target, target_ms) = timed(
            "1 load_target",
            lambda: live.load_target(conn, race_code),
        )
        target_date, place_code, stadium_name, boats = target

        exhibition, exhibition_ms = timed(
            "2 load_current_exhibition",
            lambda: live.load_current_exhibition(conn, race_code),
        )

        venue, venue_ms = timed(
            "3 load_venue_course_prior_exact",
            lambda: exact.load_venue_course_prior_exact(
                conn, race_code, target_date, place_code
            ),
        )

        player_ms_total = 0.0
        print("\n【選手直近100走】")
        for boat in sorted(boats, key=lambda r: r["lane"]):
            lane = int(boat["lane"])
            course = exhibition[lane]["course"]
            history, ms = timed(
                f"4-{lane} {lane}号艇 load_last_100",
                lambda boat=boat: live.load_last_100(
                    conn, boat["player_id"], target_date, race_code
                ),
            )
            player_ms_total += ms
            counts = live.player_counts(history, course, place_code)
            print(
                f"     -> {lane}号艇 {course}C history={counts['history_n']} "
                f"pc={counts['pc_n']}/{counts['pc_w']} pvc={counts['pvc_n']}/{counts['pvc_w']}"
            )

    total = target_ms + exhibition_ms + venue_ms + player_ms_total
    print("\n" + "-" * 92)
    print(f"場×コースprior     : {venue_ms/1000.0:.2f} sec")
    print(f"6選手last100合計   : {player_ms_total/1000.0:.2f} sec")
    print(f"計測対象合計       : {total/1000.0:.2f} sec")
    print("=" * 92)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
