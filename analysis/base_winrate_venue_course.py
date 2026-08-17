#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
基礎1着率 STEP 1: 場×コースの実績1着率

目的:
- 展示タイム、展示ST、周回、周り足、直線などの展示性能指標を一切使わず、
  過去結果から「場×進入コース」の素の1着率を確認する。
- まだ選手補正・平滑化・6艇正規化・本番ロジックへの組込みは行わない。

進入コース:
- race_entry の6艇を母集団とし、exhibition_live.entry_course を
  「実際の進入コースを復元するための位置情報」としてのみ利用する。
- 展示のタイム/ST/足指標は使用しない。
- 6艇すべての進入コースが1～6で一意に揃うレースだけ採用する。

結果:
- race_result_detail の1着艇だけを使用する。
- 5/6着が保存されていない場でも分母は race_entry 6艇なので影響しない。

Usage:
    # DB内の全期間
    python3 analysis/base_winrate_venue_course.py

    # 期間指定
    python3 analysis/base_winrate_venue_course.py 2026-01-01 2026-08-14
"""

from __future__ import annotations

import sys
from collections import Counter, defaultdict
from datetime import datetime
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from slit_validate_v2 import connect_db


def as_int(v):
    if v is None or v == "":
        return None
    try:
        return int(v)
    except (TypeError, ValueError):
        return None


def parse_args():
    if len(sys.argv) == 1:
        return None, None
    if len(sys.argv) == 3:
        start_date = datetime.strptime(sys.argv[1], "%Y-%m-%d").date()
        end_date = datetime.strptime(sys.argv[2], "%Y-%m-%d").date()
        if start_date > end_date:
            raise RuntimeError("開始日が終了日より後です")
        return start_date, end_date

    print(
        "Usage:\n"
        "  python3 analysis/base_winrate_venue_course.py\n"
        "  python3 analysis/base_winrate_venue_course.py YYYY-MM-DD YYYY-MM-DD"
    )
    sys.exit(1)


def load_rows(start_date, end_date):
    where = []
    params = []

    if start_date is not None:
        where.append("rm.race_date >= %s::date")
        params.append(start_date.isoformat())
    if end_date is not None:
        where.append("rm.race_date <= %s::date")
        params.append(end_date.isoformat())

    where_sql = ""
    if where:
        where_sql = "WHERE " + " AND ".join(where)

    sql = f"""
        SELECT
            rm.race_date,
            rm.stadium_name,
            re.race_code,
            re.player_id::text,
            el.entry_course,
            rrd.rank
        FROM boat_race.race_entry re
        JOIN boat_race.race_master rm
          ON rm.race_code = re.race_code
        LEFT JOIN boat_race.exhibition_live el
          ON el.race_code = re.race_code
         AND el.player_id = re.player_id
        LEFT JOIN boat_race.race_result_detail rrd
          ON rrd.race_code = re.race_code
         AND rrd.player_id = re.player_id
        {where_sql}
        ORDER BY re.race_code, re.player_id
    """

    races = defaultdict(list)
    min_date = None
    max_date = None

    with connect_db() as conn:
        with conn.cursor() as cur:
            cur.execute(sql, tuple(params))
            for race_date, stadium_name, race_code, player_id, entry_course, rank in cur.fetchall():
                d = race_date
                if min_date is None or d < min_date:
                    min_date = d
                if max_date is None or d > max_date:
                    max_date = d

                races[str(race_code)].append({
                    "race_date": d,
                    "stadium_name": str(stadium_name or "").strip(),
                    "player_id": str(player_id or "").strip(),
                    "course": as_int(entry_course),
                    "rank": as_int(rank),
                })

    return races, min_date, max_date


def main():
    start_date, end_date = parse_args()

    print("場×コースの基礎1着率を集計しています...")
    races, min_date, max_date = load_rows(start_date, end_date)

    if not races:
        raise RuntimeError("対象レースがありません")

    # stats[(place_code, stadium_name)][course] = {n, wins}
    stats = defaultdict(lambda: {c: {"n": 0, "wins": 0} for c in range(1, 7)})
    eligible_races_by_place = Counter()
    total_races_by_place = Counter()
    skip = Counter()

    for race_code in sorted(races):
        rows = races[race_code]
        place_code = race_code[8:11] if len(race_code) >= 11 else "???"
        stadium_name = rows[0]["stadium_name"] if rows else ""
        place_key = (place_code, stadium_name)
        total_races_by_place[place_key] += 1

        if len(rows) != 6 or len({r["player_id"] for r in rows}) != 6:
            skip["not_6_entries"] += 1
            continue

        courses = [r["course"] for r in rows]
        # entry_course が NULL / 空 / 1～6以外なら sorted() 前に除外する。
        # None を含むリストを sorted() すると Python 3 では TypeError になるため。
        if any(c not in range(1, 7) for c in courses):
            skip["missing_or_invalid_course"] += 1
            continue
        if sorted(courses) != [1, 2, 3, 4, 5, 6]:
            skip["not_6_unique_courses"] += 1
            continue

        winners = [r for r in rows if r["rank"] == 1]
        if len(winners) != 1:
            skip["winner_not_unique"] += 1
            continue

        winner_course = winners[0]["course"]
        if winner_course not in range(1, 7):
            skip["winner_course_invalid"] += 1
            continue

        eligible_races_by_place[place_key] += 1

        # 1レースにつき各コース1艇ずつなので、各コースの分母を1増やす。
        for c in range(1, 7):
            stats[place_key][c]["n"] += 1
        stats[place_key][winner_course]["wins"] += 1

    eligible_total = sum(eligible_races_by_place.values())
    if eligible_total == 0:
        raise RuntimeError("採用できるレースが0件です")

    print("=" * 118)
    print("基礎1着率 STEP 1：場×進入コース")
    print("=" * 118)
    print(f"DB対象期間        : {min_date} ～ {max_date}")
    print(f"全レース          : {len(races)}")
    print(f"採用レース        : {eligible_total}")
    print(f"採用率            : {eligible_total / len(races) * 100:.2f}%")
    print("展示性能指標      : 不使用")
    print("進入位置          : exhibition_live.entry_course を位置情報としてのみ利用")
    print("本番変更          : なし")

    print("\n【skip】")
    for key in [
        "not_6_entries",
        "missing_or_invalid_course",
        "not_6_unique_courses",
        "winner_not_unique",
        "winner_course_invalid",
    ]:
        print(f"{key:<28}: {skip[key]}")

    print("\n【場×コース 1着率】")
    header = (
        "場       採用R/全R       "
        "1C            2C            3C            4C            5C            6C        合計"
    )
    print(header)
    print("-" * 118)

    all_course = {c: {"n": 0, "wins": 0} for c in range(1, 7)}

    for place_key in sorted(stats, key=lambda x: x[0]):
        code, name = place_key
        er = eligible_races_by_place[place_key]
        tr = total_races_by_place[place_key]
        label = f"{code}:{name}" if name else code

        cells = []
        rate_sum = 0.0
        for c in range(1, 7):
            s = stats[place_key][c]
            n = s["n"]
            w = s["wins"]
            r = (w / n * 100.0) if n else 0.0
            rate_sum += r
            cells.append(f"{r:6.2f}%({w:>4}/{n:<4})")
            all_course[c]["n"] += n
            all_course[c]["wins"] += w

        print(
            f"{label:<13} {er:>5}/{tr:<5}  "
            + "  ".join(cells)
            + f"  {rate_sum:6.2f}%"
        )

    print("-" * 118)
    all_cells = []
    all_sum = 0.0
    for c in range(1, 7):
        s = all_course[c]
        r = s["wins"] / s["n"] * 100.0 if s["n"] else 0.0
        all_sum += r
        all_cells.append(f"{r:6.2f}%({s['wins']:>4}/{s['n']:<4})")
    print(f"ALL           {eligible_total:>5}/{len(races):<5}  " + "  ".join(all_cells) + f"  {all_sum:6.2f}%")

    print("\n【確認ポイント】")
    print("・同一場では採用レースごとに1～6コースが1艇ずつなので、6コース1着率の合計は原則100%になる")
    print("・まず採用率と各場の件数を確認し、進入データ不足で特定場だけ偏っていないかを見る")
    print("・この段階では選手要素・平滑化・正規化・展示補正は一切入れない")
    print("=" * 118)


if __name__ == "__main__":
    main()
