#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""補正後1着率 SUM_RAW の旧集計とSUM Factを比較する。

Usage:
  python3 analysis/sum_fact_benchmark.py 20260820OMR01
"""

from __future__ import annotations

from pathlib import Path
import math
import sys
import time

REPO_ROOT = Path(__file__).resolve().parent.parent
FORECAST_DIR = REPO_ROOT / "forecast"
ANALYSIS_DIR = REPO_ROOT / "analysis"
THEORY_DIR = REPO_ROOT / "theories" / "course_correction"
sys.path.insert(0, str(FORECAST_DIR))
sys.path.insert(0, str(ANALYSIS_DIR))
sys.path.insert(0, str(THEORY_DIR))

from slit_validate_v2 import connect_db  # noqa: E402
from base_winrate_sum_compare import load_sum_features  # noqa: E402
import corrected_winrate_live_exact as exact  # noqa: E402
import corrected_winrate_live_exact_amg_tky as special_exact  # noqa: E402

LABELS = [
    "-0.6未満", "-0.6--0.4", "-0.4--0.2", "-0.2-0.0",
    "0.0-0.2", "0.2-0.4", "0.4-0.6", "0.6以上",
]
SPECIAL_PLACES = {"AMG", "TKY"}


def parse_race_code(code: str):
    code = code.strip().upper()
    if len(code) != 13:
        raise RuntimeError("race_codeは13文字で指定してください")
    from datetime import datetime
    target_date = datetime.strptime(code[:8], "%Y%m%d").date()
    return code, target_date, code[8:11]


def load_fact_stats(conn, race_code: str, target_date, place_code: str, feature_signature: str):
    sql = """
        WITH filtered AS (
            SELECT course, interval_label, win
            FROM boat_race.sum_history_fact
            WHERE place_code = %s
              AND feature_signature = %s
              AND (
                    race_date < %s::date
                    OR (race_date = %s::date AND race_code < %s)
                  )
        ),
        course_counts AS (
            SELECT course, COUNT(*) AS n, COUNT(*) FILTER (WHERE win) AS w
            FROM filtered
            GROUP BY course
        ),
        interval_counts AS (
            SELECT course, interval_label, COUNT(*) AS n, COUNT(*) FILTER (WHERE win) AS w
            FROM filtered
            GROUP BY course, interval_label
        )
        SELECT
            i.course,
            i.interval_label,
            i.n AS interval_n,
            i.w AS interval_w,
            c.n AS course_n,
            c.w AS course_w
        FROM interval_counts i
        JOIN course_counts c USING (course)
        ORDER BY i.course, i.interval_label
    """
    t0 = time.perf_counter()
    with conn.cursor() as cur:
        cur.execute(
            sql,
            (
                place_code,
                feature_signature,
                target_date.isoformat(),
                target_date.isoformat(),
                race_code,
            ),
        )
        rows = cur.fetchall()
    ms = (time.perf_counter() - t0) * 1000.0

    stats = {c: {} for c in range(1, 7)}
    for course, label, inn, inw, cn, cw in rows:
        course = int(course)
        inn = int(inn or 0)
        inw = int(inw or 0)
        cn = int(cn or 0)
        cw = int(cw or 0)
        score = ((inw / inn) - (cw / cn)) if cn > 0 and inn > 0 else 0.0
        stats[course][str(label)] = {
            "score": score,
            "interval_n": inn,
            "interval_w": inw,
            "course_n": cn,
            "course_w": cw,
        }

    # 旧関数と同じく、存在しない区間も0で埋める。
    for c in range(1, 7):
        course_n = 0
        course_w = 0
        if stats[c]:
            first = next(iter(stats[c].values()))
            course_n = int(first["course_n"])
            course_w = int(first["course_w"])
        for label in LABELS:
            stats[c].setdefault(label, {
                "score": 0.0,
                "interval_n": 0,
                "interval_w": 0,
                "course_n": course_n,
                "course_w": course_w,
            })
    return stats, ms


def same_stats(old: dict, new: dict):
    diffs = []
    for c in range(1, 7):
        for label in LABELS:
            a = old.get(c, {}).get(label, {})
            b = new.get(c, {}).get(label, {})
            for key in ("interval_n", "interval_w", "course_n", "course_w"):
                av = int(a.get(key, 0) or 0)
                bv = int(b.get(key, 0) or 0)
                if av != bv:
                    diffs.append((c, label, key, av, bv))
            avs = float(a.get("score", 0.0) or 0.0)
            bvs = float(b.get("score", 0.0) or 0.0)
            if not math.isclose(avs, bvs, rel_tol=0.0, abs_tol=1e-12):
                diffs.append((c, label, "score", avs, bvs))
    return len(diffs) == 0, diffs


def main() -> int:
    if len(sys.argv) != 2:
        print("Usage: python3 analysis/sum_fact_benchmark.py YYYYMMDDXXXRR", file=sys.stderr)
        return 1

    try:
        race_code, target_date, place_code = parse_race_code(sys.argv[1])
        features = load_sum_features()
        feature_cols = list(features.get(place_code, []))
        if len(feature_cols) != 3:
            raise RuntimeError(f"SUM features設定がありません: {place_code}")
        feature_signature = "+".join(feature_cols)

        print("=" * 92)
        print("補正後1着率 SUM Fact 速度・一致比較")
        print("=" * 92)
        print(f"race_code : {race_code}")
        print(f"target    : {target_date} / {place_code}")
        print(f"features  : {feature_signature}\n")

        with connect_db() as conn:
            with conn.cursor() as cur:
                cur.execute("SELECT to_regclass('boat_race.sum_history_fact')")
                if cur.fetchone()[0] is None:
                    raise RuntimeError("sum_history_factがありません。先にrebuild_sum_history_fact.pyを実行してください")

            t0 = time.perf_counter()
            if place_code in SPECIAL_PLACES:
                old = special_exact.load_sum_stats_exact_no_straight(
                    conn, race_code, target_date, place_code, feature_cols
                )
            else:
                old = exact.load_sum_stats_exact(
                    conn, race_code, target_date, place_code, feature_cols
                )
            old_ms = (time.perf_counter() - t0) * 1000.0

            new, new_ms = load_fact_stats(
                conn, race_code, target_date, place_code, feature_signature
            )

        same, diffs = same_stats(old, new)

        print(f"OLD load_sum_stats : {old_ms:10.1f} ms  ({old_ms / 1000.0:6.2f} sec)")
        print(f"FACT aggregate     : {new_ms:10.1f} ms  ({new_ms / 1000.0:6.2f} sec)")
        if new_ms > 0:
            print(f"速度比             : {old_ms / new_ms:10.2f}x")
        print(f"集計一致           : {'YES' if same else 'NO'}")

        if not same:
            print(f"差分件数           : {len(diffs)}")
            print("先頭20差分:")
            for diff in diffs[:20]:
                c, label, key, oldv, newv = diff
                print(f"  {c}C {label:12s} {key:10s} OLD={oldv} FACT={newv}")
        else:
            print("6コース×8区間の n/w/score がすべて一致しました。")

        print("=" * 92)
        return 0 if same else 2

    except Exception as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
