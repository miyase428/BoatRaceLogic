#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
基礎1着率 STEP 2: 選手×コース（直近100走）

目的:
- 展示性能を使わず、対象レース6艇について「選手×今回コース」の生1着率を確認する。
- 各選手の対象レース以前の直近100走を母集団とし、その中から今回コースと同じ進入だけを集計する。
- この段階では平滑化・場補正・6艇100%正規化・本番組込みは行わない。

コースの復元優先順:
1) race_result_detail.entry_course
2) exhibition_live.entry_course（位置情報としてのみ利用）
3) race_entry.lane_number（フォールバック）

着順:
- 1/2/3着はそのまま集計。
- 4着以下、または一部場で結果行が保存されていない艇は着外として扱う。

Usage:
    python3 analysis/base_winrate_player_course.py 20260816TSU12
"""

from __future__ import annotations

import sys
from collections import Counter
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


def valid_course(v):
    v = as_int(v)
    return v if v in range(1, 7) else None


def load_target(conn, race_code):
    sql = """
        SELECT
            rm.race_date,
            re.lane_number,
            re.player_id::text,
            re.player_name
        FROM boat_race.race_entry re
        JOIN boat_race.race_master rm
          ON rm.race_code = re.race_code
        WHERE re.race_code = %s
        ORDER BY re.lane_number
    """
    with conn.cursor() as cur:
        cur.execute(sql, (race_code,))
        rows = cur.fetchall()

    if len(rows) != 6:
        raise RuntimeError(f"対象レースの出走艇が6艇ではありません: {len(rows)}艇")

    race_date = rows[0][0]
    boats = []
    for _, lane, player_id, player_name in rows:
        lane = as_int(lane)
        if lane not in range(1, 7):
            raise RuntimeError(f"不正なlane_number: {lane}")
        boats.append({
            "lane": lane,
            "course": lane,  # 展示不使用の基礎段階では枠番=今回コースとして扱う
            "player_id": str(player_id).strip(),
            "player_name": str(player_name or "").strip(),
        })

    return race_date, boats


def load_last_100(conn, player_id, target_date, target_race_code):
    sql = """
        SELECT
            re.race_code,
            rm.race_date,
            re.lane_number,
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
            SELECT entry_course
            FROM boat_race.exhibition_live x
            WHERE x.race_code = re.race_code
              AND x.player_id = re.player_id
            LIMIT 1
        ) el ON TRUE
        WHERE re.player_id::text = %s
          AND (
                rm.race_date < %s::date
                OR (rm.race_date = %s::date AND re.race_code < %s)
              )
        ORDER BY rm.race_date DESC, re.race_code DESC
        LIMIT 100
    """

    with conn.cursor() as cur:
        cur.execute(
            sql,
            (player_id, target_date.isoformat(), target_date.isoformat(), target_race_code),
        )
        rows = cur.fetchall()

    out = []
    for race_code, race_date, lane, rank, result_course, exhibition_course in rows:
        rc = valid_course(result_course)
        ec = valid_course(exhibition_course)
        lc = valid_course(lane)

        if rc is not None:
            course = rc
            source = "result"
        elif ec is not None:
            course = ec
            source = "exhibition"
        else:
            course = lc
            source = "lane_fallback"

        out.append({
            "race_code": str(race_code),
            "race_date": race_date,
            "rank": as_int(rank),
            "course": course,
            "course_source": source,
        })

    return out


def summarize(history, target_course):
    same = [r for r in history if r["course"] == target_course]
    finish = Counter()
    sources = Counter(r["course_source"] for r in same)

    for r in same:
        rank = r["rank"]
        if rank == 1:
            finish["first"] += 1
        elif rank == 2:
            finish["second"] += 1
        elif rank == 3:
            finish["third"] += 1
        else:
            finish["outside"] += 1

    n = len(same)
    wins = finish["first"]
    top3 = finish["first"] + finish["second"] + finish["third"]

    return {
        "history_n": len(history),
        "same_n": n,
        "first": wins,
        "second": finish["second"],
        "third": finish["third"],
        "outside": finish["outside"],
        "win_rate": wins / n * 100.0 if n else 0.0,
        "top3_rate": top3 / n * 100.0 if n else 0.0,
        "sources": sources,
    }


def main():
    if len(sys.argv) != 2:
        print("Usage: python3 analysis/base_winrate_player_course.py RACE_CODE")
        sys.exit(1)

    race_code = sys.argv[1].strip()

    with connect_db() as conn:
        target_date, boats = load_target(conn, race_code)

        results = []
        for boat in boats:
            history = load_last_100(
                conn,
                boat["player_id"],
                target_date,
                race_code,
            )
            summary = summarize(history, boat["course"])
            results.append({**boat, **summary})

    print("=" * 132)
    print("基礎1着率 STEP 2：選手×コース（直近100走）")
    print("=" * 132)
    print(f"対象レース      : {race_code}")
    print(f"対象日          : {target_date}")
    print("履歴母集団      : 各選手の対象レース以前・直近100走")
    print("今回コース      : 展示不使用のため枠番=コース")
    print("平滑化          : なし")
    print("100%正規化      : なし")
    print("本番変更        : なし")

    print("\n【6艇の選手×今回コース実績】")
    print(
        "艇  選手ID   選手名             今回C  履歴N  同C N   1着  2着  3着  着外   生1着率   3連対率   コース元(result/ex/lane)"
    )
    print("-" * 132)

    for r in results:
        s = r["sources"]
        source_text = f"{s['result']}/{s['exhibition']}/{s['lane_fallback']}"
        print(
            f"{r['lane']:>1}   "
            f"{r['player_id']:<8} "
            f"{r['player_name'][:16]:<16} "
            f"{r['course']:>3}C   "
            f"{r['history_n']:>3}   "
            f"{r['same_n']:>3}   "
            f"{r['first']:>3}  {r['second']:>3}  {r['third']:>3}  {r['outside']:>3}   "
            f"{r['win_rate']:>7.2f}%   {r['top3_rate']:>7.2f}%   "
            f"{source_text}"
        )

    print("\n【確認ポイント】")
    print("・直近100走の中で今回と同じコースが何走あるか（same N）を最優先で見る")
    print("・生1着率は母数が少ないほど振れやすいので、この時点では高低だけで評価しない")
    print("・lane_fallback が多い場合は、実進入コース復元の精度を次に検討する")
    print("・ここでデータ取得が妥当と確認できてから平滑化へ進む")
    print("=" * 132)


if __name__ == "__main__":
    main()
