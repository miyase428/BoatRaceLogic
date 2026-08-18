#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""SUM理論: 場ごとの展示項目欠損診断。

Usage:
  python3 analysis/debug_sam_missing_features.py AMG TKY
"""

from __future__ import annotations

import sys
from pathlib import Path
from collections import defaultdict

REPO_ROOT = Path(__file__).resolve().parent.parent
THEORY_DIR = REPO_ROOT / "theories" / "new_sam"
sys.path.insert(0, str(THEORY_DIR))

from new_sam import connect_db, load_features  # noqa: E402


FIELDS = [
    "exhibition_time",
    "lap_time",
    "around_time",
    "straight_time",
]


def diagnose_place(conn, place: str, features: dict) -> None:
    selected = features.get(place, [])

    sql = """
        SELECT
            el.race_code,
            el.player_id,
            el.entry_course,
            el.exhibition_time,
            el.lap_time,
            el.around_time,
            el.straight_time
        FROM boat_race.exhibition_live el
        WHERE SUBSTRING(el.race_code, 9, 3) = %s
        ORDER BY el.race_code, el.entry_course
    """

    with conn.cursor() as cur:
        cur.execute(sql, (place,))
        rows = cur.fetchall()

    by_race = defaultdict(list)
    row_nonnull = {f: 0 for f in FIELDS}
    row_total = len(rows)

    for row in rows:
        race_code, player_id, course, ex, lap, around, straight = row
        vals = {
            "exhibition_time": ex,
            "lap_time": lap,
            "around_time": around,
            "straight_time": straight,
        }
        for f in FIELDS:
            if vals[f] is not None:
                row_nonnull[f] += 1
        by_race[str(race_code)].append(vals)

    race_total = len(by_race)
    race_complete = {f: 0 for f in FIELDS}
    selected_complete = 0
    exactly_six = 0

    for race_code, boats in by_race.items():
        if len(boats) == 6:
            exactly_six += 1
        for f in FIELDS:
            if len(boats) == 6 and all(b[f] is not None for b in boats):
                race_complete[f] += 1
        if (
            len(boats) == 6
            and selected
            and all(all(b[f] is not None for f in selected) for b in boats)
        ):
            selected_complete += 1

    print("=" * 76)
    print(f"{place} SUM展示項目欠損診断")
    print("=" * 76)
    print(f"features.json   : {selected}")
    print(f"展示行数         : {row_total}")
    print(f"展示レース数     : {race_total}")
    print(f"6艇展示レース    : {exactly_six}")
    print()
    print("項目                 非NULL行        非NULL率      6艇完備R")
    print("-" * 76)
    for f in FIELDS:
        n = row_nonnull[f]
        pct = (n / row_total * 100.0) if row_total else 0.0
        print(f"{f:<20} {n:>10}   {pct:>8.2f}%   {race_complete[f]:>8}")
    print("-" * 76)
    print(f"features指定3項目が6艇完備: {selected_complete}R")
    print()


def main() -> int:
    if len(sys.argv) < 2:
        print("Usage: python3 analysis/debug_sam_missing_features.py PLACE [PLACE ...]")
        return 1

    places = [p.strip().upper() for p in sys.argv[1:] if p.strip()]
    features = load_features()

    with connect_db() as conn:
        for place in places:
            diagnose_place(conn, place, features)

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
