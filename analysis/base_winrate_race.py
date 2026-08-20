#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
基礎1着率 STEP 5: 1レース6艇の基礎1着率を算出

目的:
- STEP1～4で決めた3階層とBB_MEDIUM平滑化を、実際の1レース6艇へ適用する。
- この段階では6艇合計100%への正規化はまだ行わない。
- 展示性能は一切使わず、今回コースは枠番=コースとして扱う。

採用式:
    p0 = 場×コースの過去1着率

    p_pc = (wins_pc + 20 * p0) / (n_pc + 20)
      ※ pc = 選手×コース、選手の対象レース以前・直近100走

    p_final = (wins_pvc + 10 * p_pc) / (n_pvc + 10)
      ※ pvc = 選手×場×コース、同じ直近100走の中から今回場×今回コース

Usage:
    python3 analysis/base_winrate_race.py 20260816TSU12
"""

from __future__ import annotations

import sys
from collections import Counter
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from slit_validate_v2 import connect_db

K_PC = 20.0
K_PVC = 10.0


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
               COALESCE(
                   rm.race_date,
                   TO_DATE(SUBSTRING(re.race_code, 1, 8), 'YYYYMMDD')
               ) AS race_date,
               rm.stadium_name,
               re.lane_number,
               re.player_id::text,
               re.player_name
        FROM boat_race.race_entry re
        LEFT JOIN boat_race.race_master rm ON rm.race_code = re.race_code
        WHERE re.race_code = %s
        ORDER BY re.lane_number
    """
    with conn.cursor() as cur:
        cur.execute(sql, (race_code,))
        rows = cur.fetchall()
    if len(rows) != 6:
        raise RuntimeError(f"対象レースの出走艇が6艇ではありません: {len(rows)}艇")
    target_date = rows[0][0]
    stadium_name = str(rows[0][1] or "").strip()
    place_code = race_code[8:11] if len(race_code) >= 11 else "???"
    boats = []
    for _, _, lane, player_id, player_name in rows:
        lane = valid_course(lane)
        if lane is None:
            raise RuntimeError("対象レースに不正なlane_numberがあります")
        boats.append({"lane": lane, "course": lane, "player_id": str(player_id or "").strip(), "player_name": str(player_name or "").strip()})
    return target_date, place_code, stadium_name, boats


def load_venue_course_prior(conn, race_code, target_date, place_code):
    sql = """
        WITH winner_rows AS (
            SELECT rrd.race_code, COUNT(*) AS winner_count,
                   MIN(rrd.entry_course) AS winner_course
            FROM boat_race.race_result_detail rrd
            JOIN boat_race.race_master rm ON rm.race_code = rrd.race_code
            WHERE rrd.rank = '1'
              AND SUBSTRING(rrd.race_code, 9, 3) = %s
              AND (rm.race_date < %s::date
                   OR (rm.race_date = %s::date AND rrd.race_code < %s))
            GROUP BY rrd.race_code
        )
        SELECT winner_course, COUNT(*)
        FROM winner_rows
        WHERE winner_count = 1
          AND winner_course BETWEEN 1 AND 6
        GROUP BY winner_course
        ORDER BY winner_course
    """
    counts = {c: 0 for c in range(1, 7)}
    with conn.cursor() as cur:
        cur.execute(sql, (place_code, target_date.isoformat(), target_date.isoformat(), race_code))
        for course, wins in cur.fetchall():
            c = valid_course(course)
            if c is not None:
                counts[c] = int(wins)
    total = sum(counts.values())
    if total <= 0:
        raise RuntimeError(f"{place_code} の対象レース以前の場×コース履歴がありません")
    return {c: {"n": total, "wins": counts[c], "rate": counts[c] / total} for c in range(1, 7)}


def load_last_100(conn, player_id, target_date, target_race_code):
    sql = """
        SELECT re.race_code, re.lane_number, rrd.rank,
               rrd.entry_course AS result_course,
               el.entry_course AS exhibition_course
        FROM boat_race.race_entry re
        JOIN boat_race.race_master rm ON rm.race_code = re.race_code
        LEFT JOIN boat_race.race_result_detail rrd
          ON rrd.race_code = re.race_code AND rrd.player_id = re.player_id
        LEFT JOIN LATERAL (
            SELECT entry_course
            FROM boat_race.exhibition_live x
            WHERE x.race_code = re.race_code AND x.player_id = re.player_id
            LIMIT 1
        ) el ON TRUE
        WHERE re.player_id::text = %s
          AND (rm.race_date < %s::date
               OR (rm.race_date = %s::date AND re.race_code < %s))
        ORDER BY rm.race_date DESC, re.race_code DESC
        LIMIT 100
    """
    with conn.cursor() as cur:
        cur.execute(sql, (player_id, target_date.isoformat(), target_date.isoformat(), target_race_code))
        rows = cur.fetchall()
    out = []
    for race_code, lane, rank, result_course, exhibition_course in rows:
        rc = valid_course(result_course)
        ec = valid_course(exhibition_course)
        lc = valid_course(lane)
        if rc is not None:
            course, source = rc, "result"
        elif ec is not None:
            course, source = ec, "exhibition"
        else:
            course, source = lc, "lane_fallback"
        if course is None:
            continue
        code = str(race_code)
        out.append({"race_code": code, "place": code[8:11] if len(code) >= 11 else "???", "course": course, "win": 1 if as_int(rank) == 1 else 0, "source": source})
    return out


def player_counts(history, target_course, place_code):
    pc = [h for h in history if h["course"] == target_course]
    pvc = [h for h in pc if h["place"] == place_code]
    return {"history_n": len(history), "pc_n": len(pc), "pc_w": sum(h["win"] for h in pc), "pvc_n": len(pvc), "pvc_w": sum(h["win"] for h in pvc), "sources": Counter(h["source"] for h in pc)}


def raw_rate(wins, n):
    return wins / n if n else None


def pct(v):
    return "   -   " if v is None else f"{v * 100:7.2f}%"


def main():
    if len(sys.argv) != 2:
        print("Usage: python3 analysis/base_winrate_race.py RACE_CODE")
        sys.exit(1)
    race_code = sys.argv[1].strip()
    with connect_db() as conn:
        target_date, place_code, stadium_name, boats = load_target(conn, race_code)
        venue = load_venue_course_prior(conn, race_code, target_date, place_code)
        results = []
        for boat in boats:
            history = load_last_100(conn, boat["player_id"], target_date, race_code)
            c = player_counts(history, boat["course"], place_code)
            p0 = venue[boat["course"]]["rate"]
            p_pc = (c["pc_w"] + K_PC * p0) / (c["pc_n"] + K_PC)
            p_final = (c["pvc_w"] + K_PVC * p_pc) / (c["pvc_n"] + K_PVC)
            results.append({**boat, **c, "venue_n": venue[boat["course"]]["n"], "venue_w": venue[boat["course"]]["wins"], "p0": p0, "pc_raw": raw_rate(c["pc_w"], c["pc_n"]), "p_pc": p_pc, "pvc_raw": raw_rate(c["pvc_w"], c["pvc_n"]), "p_final": p_final})
    print("=" * 174)
    print("基礎1着率 STEP 5：1レース6艇の基礎1着率（正規化前）")
    print("=" * 174)
    print(f"対象レース      : {race_code}")
    print(f"対象日          : {target_date}")
    print(f"対象場          : {place_code}:{stadium_name}")
    print("今回コース      : 展示不使用のため枠番=コース")
    print("平滑化方式      : BB_MEDIUM")
    print(f"Kpc / Kpvc      : {int(K_PC)} / {int(K_PVC)}")
    print("100%正規化      : まだ行わない")
    print("本番変更        : なし")
    print("\n【6艇の基礎1着率】")
    print("艇  選手ID   選手名             C   場×C(N/勝)      p0      選手×C(N/勝)  raw      平滑後p_pc   選手×場×C(N/勝) raw      基礎1着率")
    print("-" * 174)
    for r in results:
        print(f"{r['lane']:>1}   {r['player_id']:<8} {r['player_name'][:16]:<16} {r['course']}C  {r['venue_n']:>6}/{r['venue_w']:<5} {pct(r['p0'])}   {r['pc_n']:>3}/{r['pc_w']:<3}     {pct(r['pc_raw'])}   {pct(r['p_pc'])}      {r['pvc_n']:>3}/{r['pvc_w']:<3}          {pct(r['pvc_raw'])}   {pct(r['p_final'])}")
    total = sum(r["p_final"] for r in results)
    ordered = sorted(results, key=lambda r: (-r["p_final"], r["lane"]))
    print("\n【正規化前サマリー】")
    print(f"6艇合計          : {total * 100:.2f}%")
    print("順位             : " + " > ".join(f"{r['lane']}号艇({r['p_final']*100:.2f}%)" for r in ordered))
    print("\n【確認ポイント】")
    print("・p0は対象レースより前だけを使った場×コース1着率")
    print("・選手×コースは対象レース以前の直近100走から今回コースだけを抽出")
    print("・選手×場×コースも同じ直近100走から今回場×今回コースだけを抽出")
    print("・少数標本はKpc=20/Kpvc=10で段階的に広い母集団へ戻している")
    print("・6艇合計はまだ100%に調整していない。STEP6で正規化する")
    print("=" * 174)


if __name__ == "__main__":
    main()
