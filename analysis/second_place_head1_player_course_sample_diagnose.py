#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
1号艇1着時の2着率：選手×実コースの過去100走サンプル数診断。

目的
----
- 2着率モデルで「選手×実コース」を使えるか、母数の薄さを確認する。
- 各評価レース時点で、その選手の直前100走だけを履歴として使う（未来情報なし）。
- 直前100走のうち、
    * 1号艇が実際に1着
    * その選手が今回と同じ実コース
  だった件数 n と、その中で2着だった件数 w を数える。
- まだ平滑化係数Kや予測式は決めない。母数診断だけを行う。

実コース復元優先順位
--------------------
result_detail.entry_course -> exhibition_live.entry_course -> lane_number

Usage
-----
python3 analysis/second_place_head1_player_course_sample_diagnose.py 2026-06-15 2026-08-14
"""

from __future__ import annotations

import sys
from collections import Counter, defaultdict, deque
from datetime import datetime, timedelta
from statistics import mean, median

from slit_validate_v2 import connect_db


HISTORY_DAYS = 730
LAST_N = 100


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


def percentile(values, q):
    if not values:
        return 0.0
    xs = sorted(values)
    if len(xs) == 1:
        return float(xs[0])
    pos = (len(xs) - 1) * q
    lo = int(pos)
    hi = min(lo + 1, len(xs) - 1)
    frac = pos - lo
    return xs[lo] * (1.0 - frac) + xs[hi] * frac


def bucket(n):
    if n == 0:
        return "0"
    if n <= 2:
        return "1-2"
    if n <= 5:
        return "3-5"
    if n <= 10:
        return "6-10"
    if n <= 20:
        return "11-20"
    return "21+"


def load_rows(history_start, end_date):
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
        ORDER BY rm.race_date, re.race_code, re.lane_number
    """

    with connect_db() as conn:
        with conn.cursor(name="head1_second_pc_sample") as cur:
            cur.itersize = 10000
            cur.execute(sql, (history_start.isoformat(), end_date.isoformat()))
            for row in cur:
                yield row


def group_races(rows):
    current_code = None
    current_date = None
    current_rows = []

    for row in rows:
        race_date = row[0]
        race_code = str(row[1])
        if current_code is None:
            current_code = race_code
            current_date = race_date
        if race_code != current_code:
            yield current_date, current_code, current_rows
            current_code = race_code
            current_date = race_date
            current_rows = []
        current_rows.append(row)

    if current_code is not None:
        yield current_date, current_code, current_rows


def prepare_race(rows):
    if len(rows) != 6:
        return None

    prepared = []
    lanes = []
    for r in rows:
        lane = valid_course(r[2])
        player_id = str(r[3] or "").strip()
        rank = as_int(r[4])
        result_course = valid_course(r[5])
        exhibition_course = valid_course(r[6])
        course = result_course or exhibition_course or lane

        lanes.append(lane)
        prepared.append({
            "lane": lane,
            "player_id": player_id,
            "rank": rank,
            "course": course,
        })

    if sorted(c for c in lanes if c is not None) != [1, 2, 3, 4, 5, 6]:
        return None

    winners = [r for r in prepared if r["rank"] == 1]
    seconds = [r for r in prepared if r["rank"] == 2]
    top2_valid = len(winners) == 1 and len(seconds) == 1
    head1_win = top2_valid and winners[0]["lane"] == 1

    return {
        "rows": prepared,
        "top2_valid": top2_valid,
        "head1_win": head1_win,
    }


def main():
    if len(sys.argv) != 3:
        print("Usage: python3 analysis/second_place_head1_player_course_sample_diagnose.py YYYY-MM-DD YYYY-MM-DD")
        return 1

    start_date = parse_date(sys.argv[1])
    end_date = parse_date(sys.argv[2])
    if start_date > end_date:
        raise RuntimeError("開始日が終了日より後です")

    history_start = start_date - timedelta(days=HISTORY_DAYS)

    # 選手ごとの直前100走。現在レースを評価してから追加するので未来情報なし。
    histories = defaultdict(lambda: deque(maxlen=LAST_N))

    target_head1_races = 0
    target_candidates = 0
    no_player_id = 0
    no_course = 0

    sample_ns = []
    sample_ws = []
    buckets = Counter()
    by_course_ns = defaultdict(list)
    by_course_buckets = defaultdict(Counter)

    for race_date, race_code, rows in group_races(load_rows(history_start, end_date)):
        race = prepare_race(rows)
        if race is None:
            continue

        in_target = start_date <= race_date <= end_date

        # 評価対象：1号艇が実際に1着したレースの残り5艇。
        if in_target and race["head1_win"]:
            target_head1_races += 1

            for r in race["rows"]:
                if r["lane"] == 1:
                    continue

                player_id = r["player_id"]
                course = r["course"]
                if not player_id:
                    no_player_id += 1
                    continue
                if course is None:
                    no_course += 1
                    continue

                hist = histories[player_id]
                matched = [
                    h for h in hist
                    if h["top2_valid"]
                    and h["head1_win"]
                    and h["course"] == course
                ]
                n = len(matched)
                w = sum(1 for h in matched if h["second"])

                target_candidates += 1
                sample_ns.append(n)
                sample_ws.append(w)
                buckets[bucket(n)] += 1
                by_course_ns[course].append(n)
                by_course_buckets[course][bucket(n)] += 1

        # 現在レースを各選手の履歴へ追加。100走窓そのものには結果欠損レースも含める。
        for r in race["rows"]:
            player_id = r["player_id"]
            if not player_id:
                continue
            histories[player_id].append({
                "course": r["course"],
                "top2_valid": race["top2_valid"],
                "head1_win": race["head1_win"],
                "second": r["rank"] == 2,
            })

    print("=" * 110)
    print("1号艇1着時の2着率：選手×実コース 過去100走サンプル数診断")
    print("=" * 110)
    print(f"評価期間                  : {start_date} ～ {end_date}")
    print(f"履歴読込開始              : {history_start}（最大{HISTORY_DAYS}日前から）")
    print(f"選手履歴                  : 各評価時点の直前{LAST_N}走")
    print(f"評価対象1号艇1着レース    : {target_head1_races}")
    print(f"評価対象候補艇            : {target_candidates}")
    print(f"player_id欠損              : {no_player_id}")
    print(f"実コース欠損              : {no_course}")

    if not sample_ns:
        print("評価対象がありません")
        return 0

    print("\n【1. 過去100走から残る『1号艇勝ち×同じ実コース』サンプル数】")
    print(f"平均                      : {mean(sample_ns):.2f}")
    print(f"中央値                    : {median(sample_ns):.2f}")
    print(f"P75                       : {percentile(sample_ns, 0.75):.2f}")
    print(f"P90                       : {percentile(sample_ns, 0.90):.2f}")
    print(f"最大                      : {max(sample_ns)}")
    print(f"n>=5                      : {sum(n >= 5 for n in sample_ns)} ({sum(n >= 5 for n in sample_ns) / len(sample_ns) * 100:.2f}%)")
    print(f"n>=10                     : {sum(n >= 10 for n in sample_ns)} ({sum(n >= 10 for n in sample_ns) / len(sample_ns) * 100:.2f}%)")

    print("\n【2. サンプル数帯】")
    print("帯        件数       構成比")
    print("-" * 36)
    order = ["0", "1-2", "3-5", "6-10", "11-20", "21+"]
    for key in order:
        n = buckets[key]
        pct = n / len(sample_ns) * 100
        print(f"{key:<7} {n:>8}    {pct:>7.2f}%")

    print("\n【3. 今回実コース別のサンプル数】")
    print("course   件数    平均n   中央n   P75    P90    n>=5    n>=10")
    print("-" * 78)
    for course in range(1, 7):
        xs = by_course_ns.get(course, [])
        if not xs:
            print(f" {course}C        0       -       -      -      -       -        -")
            continue
        ge5 = sum(n >= 5 for n in xs)
        ge10 = sum(n >= 10 for n in xs)
        print(
            f" {course}C  {len(xs):>7}  {mean(xs):>7.2f}  {median(xs):>6.2f}  "
            f"{percentile(xs, 0.75):>5.1f}  {percentile(xs, 0.90):>5.1f}  "
            f"{ge5 / len(xs) * 100:>6.2f}%  {ge10 / len(xs) * 100:>7.2f}%"
        )

    print("\n【4. 実コース別サンプル数帯】")
    for course in range(1, 7):
        total = len(by_course_ns.get(course, []))
        if total == 0:
            continue
        parts = []
        for key in order:
            n = by_course_buckets[course][key]
            parts.append(f"{key}:{n}({n / total * 100:.1f}%)")
        print(f"{course}C  " + "  ".join(parts))

    print("\n【判断用メモ】")
    print("・nが小さい艇が多いほど、選手×実コースの生率を直接使うのは危険")
    print("・次工程では course別p0 を母平均にしてベイズ平滑化する")
    print("・Kはまだ固定しない。今回の母数分布を見て候補を絞り、その後2期間で検証する")
    print("・最後に残り5艇を100%正規化する")
    print("=" * 110)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
