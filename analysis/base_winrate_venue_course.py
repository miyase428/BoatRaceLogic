#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
基礎1着率 STEP 1: 場×コースの実績1着率

目的:
- 展示タイム、展示ST、周回、周り足、直線などの展示性能指標を一切使わず、
  過去結果から「場×実進入コース」の素の1着率を確認する。
- まだ選手補正・平滑化・6艇正規化・本番ロジックへの組込みは行わない。

考え方:
- 1レースには実進入1～6コースが1艇ずつ存在するため、
  場×コースの1着率を出すだけなら6艇全員の実進入を復元する必要はない。
- race_result_detail の1着艇について entry_course が分かれば、
  そのレースは各コースの分母を1ずつ増やし、1着艇の実進入コースだけ勝数を1増やせる。
- これにより、保存期間の短い exhibition_live に依存せず全履歴を使える。
- 場の集計キーは stadium_name ではなく race_code 内の3文字場コードを使用する。
  過去の名称表記揺れ（例: BWK の「琵琶湖」「びわこ」）は同一場として統合する。

結果:
- race_result_detail の1着艇だけを使用する。
- 5/6着が保存されていない場でも影響しない。
- 1着艇が一意で、かつ実進入コースが1～6のレースだけ採用する。

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


PLACE_NAMES = {
    "KRY": "桐生", "TDA": "戸田", "EDG": "江戸川", "HWJ": "平和島",
    "TMG": "多摩川", "HMN": "浜名湖", "GMG": "蒲郡", "TKN": "常滑",
    "TSU": "津", "MKN": "三国", "BWK": "びわこ", "SME": "住之江",
    "AMG": "尼崎", "NRT": "鳴門", "MRG": "丸亀", "KJM": "児島",
    "MYJ": "宮島", "TKY": "徳山", "SMS": "下関", "WKM": "若松",
    "ASY": "芦屋", "FKO": "福岡", "KRT": "唐津", "OMR": "大村",
}


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

    # race_master を母集団にして、結果行は全件LEFT JOINする。
    # Python側で rank=1 を一意に確認することで、欠損や重複も診断できる。
    sql = f"""
        SELECT
            rm.race_date,
            rm.race_code,
            rrd.player_id::text,
            rrd.rank,
            rrd.entry_course
        FROM boat_race.race_master rm
        LEFT JOIN boat_race.race_result_detail rrd
          ON rrd.race_code = rm.race_code
        {where_sql}
        ORDER BY rm.race_code, rrd.rank NULLS LAST, rrd.player_id
    """

    races = defaultdict(list)
    min_date = None
    max_date = None

    with connect_db() as conn:
        with conn.cursor() as cur:
            cur.execute(sql, tuple(params))
            for race_date, race_code, player_id, rank, entry_course in cur.fetchall():
                d = race_date
                if min_date is None or d < min_date:
                    min_date = d
                if max_date is None or d > max_date:
                    max_date = d

                races[str(race_code)].append({
                    "race_date": d,
                    "player_id": str(player_id or "").strip(),
                    "rank": as_int(rank),
                    "course": as_int(entry_course),
                })

    return races, min_date, max_date


def main():
    start_date, end_date = parse_args()

    print("場×コースの基礎1着率を集計しています...")
    races, min_date, max_date = load_rows(start_date, end_date)

    if not races:
        raise RuntimeError("対象レースがありません")

    # stats[place_code][course] = {n, wins}
    # stadium_name は過去に表記揺れがあるため集計キーに使用しない。
    stats = defaultdict(lambda: {c: {"n": 0, "wins": 0} for c in range(1, 7)})
    eligible_races_by_place = Counter()
    total_races_by_place = Counter()
    skip = Counter()

    for race_code in sorted(races):
        rows = races[race_code]
        place_code = race_code[8:11] if len(race_code) >= 11 else "???"
        total_races_by_place[place_code] += 1

        winners = [r for r in rows if r["rank"] == 1]
        if len(winners) == 0:
            skip["winner_missing"] += 1
            continue
        if len(winners) != 1:
            skip["winner_not_unique"] += 1
            continue

        winner_course = winners[0]["course"]
        if winner_course not in range(1, 7):
            skip["winner_course_missing_or_invalid"] += 1
            continue

        eligible_races_by_place[place_code] += 1

        # 各レースには実進入1～6コースが1艇ずつ存在するため、
        # 採用レース1件につき全コースの分母を1増やす。
        for c in range(1, 7):
            stats[place_code][c]["n"] += 1
        stats[place_code][winner_course]["wins"] += 1

    eligible_total = sum(eligible_races_by_place.values())
    if eligible_total == 0:
        raise RuntimeError("採用できるレースが0件です")

    print("=" * 118)
    print("基礎1着率 STEP 1：場×実進入コース")
    print("=" * 118)
    print(f"DB対象期間        : {min_date} ～ {max_date}")
    print(f"全レース          : {len(races)}")
    print(f"採用レース        : {eligible_total}")
    print(f"採用率            : {eligible_total / len(races) * 100:.2f}%")
    print("展示性能指標      : 不使用")
    print("進入位置          : race_result_detail.entry_course（1着艇のみ）")
    print("場集計キー        : race_code の3文字場コード（名称表記揺れを統合）")
    print("本番変更          : なし")

    print("\n【skip】")
    for key in [
        "winner_missing",
        "winner_not_unique",
        "winner_course_missing_or_invalid",
    ]:
        print(f"{key:<34}: {skip[key]}")

    print("\n【場×コース 1着率】")
    header = (
        "場       採用R/全R       "
        "1C            2C            3C            4C            5C            6C        合計"
    )
    print(header)
    print("-" * 118)

    all_course = {c: {"n": 0, "wins": 0} for c in range(1, 7)}

    for code in sorted(stats):
        name = PLACE_NAMES.get(code, "")
        er = eligible_races_by_place[code]
        tr = total_races_by_place[code]
        label = f"{code}:{name}" if name else code

        cells = []
        rate_sum = 0.0
        for c in range(1, 7):
            s = stats[code][c]
            n = s["n"]
            w = s["wins"]
            r = (w / n * 100.0) if n else 0.0
            rate_sum += r
            cells.append(f"{r:6.2f}%({w:>5}/{n:<5})")
            all_course[c]["n"] += n
            all_course[c]["wins"] += w

        print(
            f"{label:<13} {er:>6}/{tr:<6}  "
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
        all_cells.append(f"{r:6.2f}%({s['wins']:>5}/{s['n']:<5})")
    print(f"ALL           {eligible_total:>6}/{len(races):<6}  " + "  ".join(all_cells) + f"  {all_sum:6.2f}%")

    print("\n【確認ポイント】")
    print("・1着艇の実進入コースだけを使うので、exhibition_liveの保存期間には依存しない")
    print("・採用レースごとに1～6コースの分母を1ずつ増やすため、6コース1着率の合計は原則100%になる")
    print("・場は3文字コード単位で集計するため、過去の名称変更・表記揺れでは分裂しない")
    print("・この段階では選手要素・平滑化・正規化・展示補正は一切入れない")
    print("=" * 118)


if __name__ == "__main__":
    main()
