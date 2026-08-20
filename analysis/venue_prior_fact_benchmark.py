#!/usr/bin/env python3
# -*- coding: utf-8 -*-

from __future__ import annotations

import sys
import time
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
FORECAST_DIR = REPO_ROOT / "forecast"
ANALYSIS_DIR = REPO_ROOT / "analysis"
THEORY_DIR = REPO_ROOT / "theories" / "course_correction"
sys.path.insert(0, str(FORECAST_DIR))
sys.path.insert(0, str(ANALYSIS_DIR))
sys.path.insert(0, str(THEORY_DIR))

from slit_validate_v2 import connect_db  # noqa: E402
import corrected_winrate_live_exact as exact  # noqa: E402


def load_fact_prior(conn, race_code, target_date, place_code):
    sql = """
        SELECT c1, COUNT(*)
        FROM boat_race.race_history_fact
        WHERE winner_valid
          AND place_code = %s
          AND (
                race_date < %s::date
                OR (race_date = %s::date AND race_code < %s)
              )
        GROUP BY c1
        ORDER BY c1
    """
    counts = {c: 0 for c in range(1, 7)}
    with conn.cursor() as cur:
        cur.execute(
            sql,
            (
                place_code,
                target_date.isoformat(),
                target_date.isoformat(),
                race_code,
            ),
        )
        for course, wins in cur.fetchall():
            c = int(course)
            if 1 <= c <= 6:
                counts[c] = int(wins or 0)
    total = sum(counts.values())
    if total <= 0:
        raise RuntimeError("Fact側の場×コースprior母集団がありません")
    return {
        c: {"n": total, "wins": counts[c], "rate": counts[c] / total}
        for c in range(1, 7)
    }


def canonical(prior):
    return {
        c: (
            int(prior[c]["n"]),
            int(prior[c]["wins"]),
            round(float(prior[c]["rate"]), 15),
        )
        for c in range(1, 7)
    }


def main():
    if len(sys.argv) != 2:
        print("Usage: python3 analysis/venue_prior_fact_benchmark.py RACE_CODE")
        return 1

    race_code = sys.argv[1].strip().upper()
    with connect_db() as conn:
        target_date, place_code, stadium_name, _ = exact.live.load_target(conn, race_code)

        with conn.cursor() as cur:
            cur.execute("""
                SELECT EXISTS (
                    SELECT 1
                    FROM information_schema.columns
                    WHERE table_schema='boat_race'
                      AND table_name='race_history_fact'
                      AND column_name='winner_valid'
                )
            """)
            if not cur.fetchone()[0]:
                raise RuntimeError("race_history_fact.winner_valid がありません。先にFact再構築を実行してください")

        t0 = time.perf_counter()
        old = exact.load_venue_course_prior_exact(conn, race_code, target_date, place_code)
        old_ms = (time.perf_counter() - t0) * 1000.0

        t1 = time.perf_counter()
        new = load_fact_prior(conn, race_code, target_date, place_code)
        new_ms = (time.perf_counter() - t1) * 1000.0

    same = canonical(old) == canonical(new)

    print("=" * 92)
    print("補正後1着率 場×コースprior Fact 速度・一致比較")
    print("=" * 92)
    print(f"race_code : {race_code}")
    print(f"target    : {target_date} / {place_code}:{stadium_name}")
    print()
    print(f"OLD prior : {old_ms:10.1f} ms  ({old_ms / 1000.0:6.2f} sec)")
    print(f"FACT prior: {new_ms:10.1f} ms  ({new_ms / 1000.0:6.2f} sec)")
    print(f"速度比    : {(old_ms / new_ms if new_ms > 0 else 0):10.2f}x")
    print(f"集計一致  : {'YES' if same else 'NO'}")
    print()
    print("C   OLD wins/total(rate)        FACT wins/total(rate)       一致")
    print("-" * 76)
    for c in range(1, 7):
        o = old[c]
        n = new[c]
        row_same = canonical(old)[c] == canonical(new)[c]
        print(
            f"{c}C  {int(o['wins']):6d}/{int(o['n']):<6d}({float(o['rate'])*100:6.2f}%)   "
            f"{int(n['wins']):6d}/{int(n['n']):<6d}({float(n['rate'])*100:6.2f}%)   "
            f"{'YES' if row_same else 'NO'}"
        )
    print("=" * 92)
    return 0 if same else 2


if __name__ == "__main__":
    raise SystemExit(main())
