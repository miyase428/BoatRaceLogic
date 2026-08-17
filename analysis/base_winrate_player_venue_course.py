#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
基礎1着率 STEP 3: 選手×場×コース（直近100走）

目的:
- 展示性能を使わず、対象レース6艇について「選手×今回場×今回コース」の生1着率を確認する。
- 各選手について、対象レース以前に「今回場かつ今回コース」で走った履歴を新しい順に最大100走集計する。
- この段階では平滑化・6艇100%正規化・本番組込みは行わない。

今回コース:
- 基礎段階では展示情報を使わないため、対象レースの枠番=今回コースとして扱う。

過去実進入コースの復元優先順:
1) race_result_detail.entry_course
2) exhibition_live.entry_course（位置情報としてのみ利用）
3) race_entry.lane_number（フォールバック）

着順:
- 1/2/3着はそのまま集計。
- 4着以下、または一部場で結果行が保存されていない艇は着外として扱う。

Usage:
    python3 analysis/base_winrate_player_venue_course.py 20260816TSU12
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
            rm.stadium_name,
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
    stadium_name = str(rows[0][1] or "").strip()
    place_code = race_code[8:11] if len(race_code) >= 11 else "???"

    boats = []
    for _, _, lane, player_id, player_name in rows:
        lane = as_int(lane)
        if lane not in range(1, 7):
            raise RuntimeError(f"不正なlane_number: {lane}")
        boats.append({
            "lane": lane,
            "course": lane,
            "player_id": str(player_id).strip(),
            "player_name": str(player_name or "").strip(),
        })

    return race_date, place_code, stadium_name, boats


def load_prior_at_venue(conn, player_id, place_code, target_date, target_race_code):
    """対象場での過去出走を新しい順に取得する。

    race_codeの8～10文字目の3文字場コードで統一し、場名称の表記揺れを避ける。
    ここではSQL側でコースを絞らず、実進入を復元した後にPython側で今回コースへ絞る。
    """
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
          AND SUBSTRING(re.race_code, 9, 3) = %s
          AND (
                rm.race_date < %s::date
                OR (rm.race_date = %s::date AND re.race_code < %s)
              )
        ORDER BY rm.race_date DESC, re.race_code DESC
    """

    with conn.cursor() as cur:
        cur.execute(
            sql,
            (
                player_id,
                place_code,
                target_date.isoformat(),
                target_date.isoformat(),
                target_race_code,
            ),
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
    # 「今回場×今回コース」に合う履歴を新しい順に最大100走。
    same = [r for r in history if r["course"] == target_course][:100]
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

    oldest = same[-1]["race_date"] if same else None
    newest = same[0]["race_date"] if same else None

    return {
        "venue_history_n": len(history),
        "same_n": n,
        "first": wins,
        "second": finish["second"],
        "third": finish["third"],
        "outside": finish["outside"],
        "win_rate": wins / n * 100.0 if n else 0.0,
        "top3_rate": top3 / n * 100.0 if n else 0.0,
        "sources": sources,
        "oldest": oldest,
        "newest": newest,
    }


def main():
    if len(sys.argv) != 2:
        print("Usage: python3 analysis/base_winrate_player_venue_course.py RACE_CODE")
        sys.exit(1)

    race_code = sys.argv[1].strip()

    with connect_db() as conn:
        target_date, place_code, stadium_name, boats = load_target(conn, race_code)

        results = []
        for boat in boats:
            history = load_prior_at_venue(
                conn,
                boat["player_id"],
                place_code,
                target_date,
                race_code,
            )
            summary = summarize(history, boat["course"])
            results.append({**boat, **summary})

    print("=" * 150)
    print("基礎1着率 STEP 3：選手×場×コース（直近100走）")
    print("=" * 150)
    print(f"対象レース      : {race_code}")
    print(f"対象日          : {target_date}")
    print(f"対象場          : {place_code}:{stadium_name}")
    print("履歴母集団      : 対象レース以前・同じ場×同じ実進入コースの直近最大100走")
    print("今回コース      : 展示不使用のため枠番=コース")
    print("平滑化          : なし")
    print("100%正規化      : なし")
    print("本番変更        : なし")

    print("\n【6艇の選手×今回場×今回コース実績】")
    print(
        "艇  選手ID   選手名             今回C  同場全N  同場同C N  1着  2着  3着  着外   生1着率   3連対率   履歴期間              コース元(result/ex/lane)"
    )
    print("-" * 150)

    for r in results:
        s = r["sources"]
        source_text = f"{s['result']}/{s['exhibition']}/{s['lane_fallback']}"
        period = "-"
        if r["newest"] is not None and r["oldest"] is not None:
            period = f"{r['oldest']}~{r['newest']}"

        print(
            f"{r['lane']:>1}   "
            f"{r['player_id']:<8} "
            f"{r['player_name'][:16]:<16} "
            f"{r['course']:>3}C   "
            f"{r['venue_history_n']:>5}    "
            f"{r['same_n']:>5}    "
            f"{r['first']:>3}  {r['second']:>3}  {r['third']:>3}  {r['outside']:>3}   "
            f"{r['win_rate']:>7.2f}%   {r['top3_rate']:>7.2f}%   "
            f"{period:<23} "
            f"{source_text}"
        )

    print("\n【確認ポイント】")
    print("・同場×同コースのNがどの程度確保できるかを最優先で見る")
    print("・Nが少ない選手ほど生1着率は大きく振れるため、この時点では値そのものを最終評価に使わない")
    print("・履歴期間が極端に長くなる場合は、平滑化だけでなく鮮度の扱いも後で検討する")
    print("・lane_fallbackが多い場合は、実進入コース復元の精度を再確認する")
    print("・ここまでの3要素を確認してから、STEP4で平滑化方式を比較する")
    print("=" * 150)


if __name__ == "__main__":
    main()
