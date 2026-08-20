#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""住之江(SME)の直線なしSUM exact と Fact の一致・速度確認。

引数なしなら住之江の最新レースを対象にする。
Usage:
  python3 analysis/sme_no_straight_fact_benchmark.py
  python3 analysis/sme_no_straight_fact_benchmark.py 20260818SME12
"""

from __future__ import annotations

from pathlib import Path
import math
import sys
import time

HERE = Path(__file__).resolve().parent
ROOT = HERE.parent
sys.path.insert(0, str(HERE))
sys.path.insert(0, str(ROOT / "forecast"))

from slit_validate_v2 import connect_db  # noqa: E402
from base_winrate_sum_compare import load_sum_features  # noqa: E402
import corrected_winrate_live_exact_no_straight_fact as prod  # noqa: E402

PLACE = "SME"


def pick_race_code(conn) -> str:
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT re.race_code
            FROM boat_race.race_entry re
            JOIN boat_race.race_master rm ON rm.race_code = re.race_code
            WHERE SUBSTRING(re.race_code, 9, 3) = %s
            GROUP BY re.race_code, rm.race_date
            HAVING COUNT(*) = 6
            ORDER BY rm.race_date DESC, re.race_code DESC
            LIMIT 1
            """,
            (PLACE,),
        )
        row = cur.fetchone()
    if row is None:
        raise RuntimeError("住之江の対象レースがありません")
    return str(row[0])


def stats_equal(old, new) -> tuple[bool, str]:
    labels = prod.fact.LABELS
    for c in range(1, 7):
        for label in labels:
            a = old[c][label]
            b = new[c][label]
            for key in ("interval_n", "interval_w", "course_n", "course_w"):
                if int(a[key]) != int(b[key]):
                    return False, f"{c}C {label} {key}: OLD={a[key]} FACT={b[key]}"
            if not math.isclose(float(a["score"]), float(b["score"]), rel_tol=0.0, abs_tol=1e-15):
                return False, f"{c}C {label} score: OLD={a['score']} FACT={b['score']}"
    return True, "6コース×8区間の n/w/score がすべて一致しました。"


def main() -> int:
    features = load_sum_features()
    cols = list(features[PLACE])

    with connect_db() as conn:
        race_code = sys.argv[1].strip().upper() if len(sys.argv) >= 2 else pick_race_code(conn)
        target_date, place_code, _stadium_name, _boats = prod.live.load_target(conn, race_code)
        if place_code != PLACE:
            raise RuntimeError(f"住之江以外が指定されています: {place_code}")

        t0 = time.perf_counter()
        old = prod._LEGACY_NO_STRAIGHT_SUM(conn, race_code, target_date, place_code, cols)
        old_ms = (time.perf_counter() - t0) * 1000.0

        t0 = time.perf_counter()
        fact_stats = prod.fact._load_sum_stats_from_fact(conn, race_code, target_date, place_code, cols)
        fact_ms = (time.perf_counter() - t0) * 1000.0

    print("=" * 92)
    print("住之江 直線なしSUM exact / Fact 速度・一致比較")
    print("=" * 92)
    print(f"race_code : {race_code}")
    print(f"target    : {target_date} / {place_code}")
    print(f"features  : {'+'.join(cols)}")
    print()
    print(f"OLD no-straight exact : {old_ms:10.1f} ms  ({old_ms / 1000.0:6.2f} sec)")

    if fact_stats is None:
        print("FACT                  : 利用不可")
        print("一致確認              : NO")
        print("住之江SUM Factを直線なし条件でフル再構築してください。")
        print("=" * 92)
        return 2

    print(f"FACT                  : {fact_ms:10.1f} ms  ({fact_ms / 1000.0:6.2f} sec)")
    if fact_ms > 0:
        print(f"速度比                : {old_ms / fact_ms:10.2f}x")

    ok, detail = stats_equal(old, fact_stats)
    print(f"集計一致              : {'YES' if ok else 'NO'}")
    print(detail)
    print("=" * 92)
    return 0 if ok else 3


if __name__ == "__main__":
    raise SystemExit(main())
