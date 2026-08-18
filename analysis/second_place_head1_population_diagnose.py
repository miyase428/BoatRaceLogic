#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
1号艇1着時の2着率を作る前の母集団診断。

目的
----
- 「1号艇を1着固定で買う」条件に対応する過去母集団を確認する。
- 1号艇が実際に1着だったレースだけを抽出し、2着の艇番・実進入コース分布を見る。
- 進入変更時は result_course -> exhibition_course -> lane の順で実コースを復元する。
- まだ2着率モデルは作らない。母数と基本分布の確認だけを行う。

Usage:
  python3 analysis/second_place_head1_population_diagnose.py 2026-06-15 2026-08-14
"""

from __future__ import annotations

import sys
from collections import Counter, defaultdict
from datetime import datetime

from slit_validate_v2 import connect_db


def parse_date(value: str):
    return datetime.strptime(value, "%Y-%m-%d").date()


def as_int(value):
    if value is None or value == "":
        return None
    try:
        return int(value)
    except (TypeError, ValueError):
        return None


def valid_course(value):
    c = as_int(value)
    return c if c is not None and 1 <= c <= 6 else None


def load_rows(start_date, end_date):
    sql = """
        SELECT
            rm.race_date,
            re.race_code,
            re.lane_number,
            re.player_id::text,
            rrd.rank,
            rrd.entry_course AS result_course,
            el.entry_course AS exhibition_course
        FROM boat_race.race_entry re
        JOIN boat_race.race_master rm
          ON rm.race_code = re.race_code
        LEFT JOIN boat_race.race_result_detail rrd
          ON rrd.race_code = re.race_code
         AND rrd.player_id = re.player_id
        LEFT JOIN LATERAL (
            SELECT x.entry_course
            FROM boat_race.exhibition_live x
            WHERE x.race_code = re.race_code
              AND x.player_id = re.player_id
            LIMIT 1
        ) el ON TRUE
        WHERE rm.race_date BETWEEN %s::date AND %s::date
        ORDER BY re.race_code, re.lane_number
    """

    with connect_db() as conn:
        with conn.cursor(name="head1_second_population") as cur:
            cur.itersize = 10000
            cur.execute(sql, (start_date.isoformat(), end_date.isoformat()))
            for row in cur:
                yield row


def group_races(rows):
    current_code = None
    current_rows = []
    for row in rows:
        code = str(row[1])
        if current_code is None:
            current_code = code
        if code != current_code:
            yield current_code, current_rows
            current_code = code
            current_rows = []
        current_rows.append(row)
    if current_code is not None:
        yield current_code, current_rows


def main():
    if len(sys.argv) != 3:
        print("Usage: python3 analysis/second_place_head1_population_diagnose.py YYYY-MM-DD YYYY-MM-DD")
        return 1

    start_date = parse_date(sys.argv[1])
    end_date = parse_date(sys.argv[2])
    if start_date > end_date:
        raise RuntimeError("開始日が終了日より後です")

    total_races = 0
    six_boat_races = 0
    top2_complete = 0
    head1_races = 0
    course_complete = 0
    entry_changed = 0

    winner_course = Counter()
    second_lane = Counter()
    second_course = Counter()
    candidate_course_n = Counter()
    candidate_course_second = Counter()
    candidate_lane_n = Counter()
    candidate_lane_second = Counter()
    place_head1 = Counter()

    invalid_examples = []

    for race_code, rows in group_races(load_rows(start_date, end_date)):
        total_races += 1
        if len(rows) != 6:
            continue

        lanes = [valid_course(r[2]) for r in rows]
        if sorted(c for c in lanes if c is not None) != [1, 2, 3, 4, 5, 6]:
            continue
        six_boat_races += 1

        prepared = []
        for r in rows:
            lane = valid_course(r[2])
            rank = as_int(r[4])
            result_course = valid_course(r[5])
            exhibition_course = valid_course(r[6])
            course = result_course or exhibition_course or lane
            prepared.append({
                "lane": lane,
                "rank": rank,
                "course": course,
            })

        winners = [r for r in prepared if r["rank"] == 1]
        seconds = [r for r in prepared if r["rank"] == 2]
        if len(winners) != 1 or len(seconds) != 1:
            if len(invalid_examples) < 5:
                invalid_examples.append((race_code, len(winners), len(seconds)))
            continue
        top2_complete += 1

        winner = winners[0]
        second = seconds[0]
        if winner["lane"] != 1:
            continue

        head1_races += 1
        place = race_code[8:11] if len(race_code) >= 11 else "???"
        place_head1[place] += 1

        if winner["course"] is not None:
            winner_course[winner["course"]] += 1
        second_lane[second["lane"]] += 1
        candidate_lane_second[second["lane"]] += 1

        for r in prepared:
            if r["lane"] == 1:
                continue
            candidate_lane_n[r["lane"]] += 1

        courses = [r["course"] for r in prepared]
        if all(c is not None for c in courses) and sorted(courses) == [1, 2, 3, 4, 5, 6]:
            course_complete += 1
            if any(r["course"] != r["lane"] for r in prepared):
                entry_changed += 1

            second_course[second["course"]] += 1
            candidate_course_second[second["course"]] += 1
            for r in prepared:
                if r["lane"] == 1:
                    continue
                candidate_course_n[r["course"]] += 1

    print("=" * 100)
    print("1号艇1着時の2着率：母集団診断")
    print("=" * 100)
    print(f"期間                  : {start_date} ～ {end_date}")
    print(f"全レース              : {total_races}")
    print(f"6艇出走確認            : {six_boat_races}")
    print(f"1着・2着一意確認       : {top2_complete}")
    print(f"1号艇1着レース         : {head1_races}")
    if top2_complete:
        print(f"1号艇1着率             : {head1_races / top2_complete * 100:.2f}%")
    print(f"実コース1～6復元完備   : {course_complete}")
    if course_complete:
        print(f"進入変更あり           : {entry_changed} ({entry_changed / course_complete * 100:.2f}%)")

    print("\n【1. 1号艇が1着だったときの2着艇番分布】")
    print("艇番    2着数    構成比")
    print("-" * 32)
    for lane in range(2, 7):
        n = second_lane[lane]
        pct = n / head1_races * 100 if head1_races else 0.0
        print(f"{lane}号艇  {n:>6}   {pct:>7.2f}%")

    print("\n【2. 1号艇が1着だったときの2着実コース分布】")
    print("course   2着数    構成比")
    print("-" * 32)
    denom_course = sum(second_course.values())
    for course in range(1, 7):
        n = second_course[course]
        pct = n / denom_course * 100 if denom_course else 0.0
        print(f" {course}C     {n:>6}   {pct:>7.2f}%")

    print("\n【3. 残り5艇の『2着機会数に対する2着率』：艇番】")
    print("艇番    機会数    2着数    率")
    print("-" * 38)
    for lane in range(2, 7):
        n = candidate_lane_n[lane]
        w = candidate_lane_second[lane]
        rate = w / n * 100 if n else 0.0
        print(f"{lane}号艇  {n:>6}   {w:>6}   {rate:>7.2f}%")

    print("\n【4. 残り5艇の『2着機会数に対する2着率』：実コース】")
    print("course   機会数    2着数    率")
    print("-" * 40)
    for course in range(1, 7):
        n = candidate_course_n[course]
        w = candidate_course_second[course]
        rate = w / n * 100 if n else 0.0
        print(f" {course}C    {n:>6}   {w:>6}   {rate:>7.2f}%")

    print("\n【5. 1号艇1着時の1号艇実コース】")
    print("course   レース数    構成比")
    print("-" * 36)
    denom_winner_course = sum(winner_course.values())
    for course in range(1, 7):
        n = winner_course[course]
        pct = n / denom_winner_course * 100 if denom_winner_course else 0.0
        print(f" {course}C     {n:>6}    {pct:>7.2f}%")

    print("\n【6. 場別の1号艇1着レース数（母数確認だけ）】")
    for place, n in sorted(place_head1.items(), key=lambda x: (-x[1], x[0])):
        print(f"{place}: {n}")

    if invalid_examples:
        print("\n参考: 1着/2着が一意でなく除外した例（最大5件）")
        for code, w1, w2 in invalid_examples:
            print(f"  {code}: rank1={w1}, rank2={w2}")

    print("\n【次の判断】")
    print("・まず2着艇番/実コースの偏りと母数を見る")
    print("・母数が十分なら、次に『選手×実コース』の条件付き2着率を作る")
    print("・その段階でベイズ平滑化し、残り5艇を100%正規化する")
    print("・場別細分化はこの時点では行わない")
    print("=" * 100)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
