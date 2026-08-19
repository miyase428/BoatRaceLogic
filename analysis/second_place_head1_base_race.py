#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
1号艇1着時の「基本2着率」を1レース単位で算出する。

採用仕様
--------
- 条件: 1号艇を1着固定したとき、残り2～6号艇の2着確率を出す。
- p0: 対象レースより前の「1号艇1着時の2着実コース分布」（全場共通）。
- 選手補正: 各選手の対象レース直前100走のうち、
    「1号艇が1着」かつ「今回と同じ実コース」
  だった履歴の2着率を使用する。
- 平滑化: K=10 固定。
    p_pc = (w_pc + 10 * p0) / (n_pc + 10)
- 最後に残り5艇を100%正規化する。
- 展示/SUM/スリット等の当日補正はまだ使わない。
- 履歴の実コースは result_detail -> exhibition_live -> lane の順で復元する。
- 今回コースは、展示進入が6艇分かつ1～6の完全な並びなら展示進入を使用し、
  それ以外は6艇すべて枠=コースへフォールバックする。

Usage:
    python3 analysis/second_place_head1_base_race.py 20260818TMG04
"""

from __future__ import annotations

import sys
from collections import Counter, defaultdict, deque
from datetime import datetime, timedelta
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from slit_validate_v2 import connect_db


K_PC = 10.0
HISTORY_DAYS = 730


def as_int(v):
    if v is None or v == "":
        return None
    try:
        return int(v)
    except (TypeError, ValueError):
        return None


def valid_course(v):
    c = as_int(v)
    return c if c is not None and 1 <= c <= 6 else None


def actual_course(result_course, exhibition_course, lane):
    rc = valid_course(result_course)
    if rc is not None:
        return rc, "result"
    ec = valid_course(exhibition_course)
    if ec is not None:
        return ec, "exhibition"
    lc = valid_course(lane)
    if lc is not None:
        return lc, "lane_fallback"
    return None, "missing"


def parse_race_code(race_code):
    race_code = str(race_code or "").strip().upper()
    if len(race_code) != 13 or not race_code[:8].isdigit() or not race_code[11:13].isdigit():
        raise RuntimeError(f"race_codeの形式が不正です: {race_code}")
    try:
        target_date = datetime.strptime(race_code[:8], "%Y%m%d").date()
    except ValueError as exc:
        raise RuntimeError(f"race_codeの日付が不正です: {race_code}") from exc
    return race_code, target_date


def load_target(conn, race_code):
    sql = """
        SELECT
            re.lane_number,
            re.player_id::text,
            re.player_name,
            el.entry_course AS exhibition_course
        FROM boat_race.race_entry re
        LEFT JOIN LATERAL (
            SELECT x.entry_course
            FROM boat_race.exhibition_live x
            WHERE x.race_code = re.race_code
              AND x.player_id = re.player_id
            LIMIT 1
        ) el ON TRUE
        WHERE re.race_code = %s
        ORDER BY re.lane_number
    """
    with conn.cursor() as cur:
        cur.execute(sql, (race_code,))
        rows = cur.fetchall()

    if len(rows) != 6:
        raise RuntimeError(f"対象レースの出走艇が6艇ではありません: {len(rows)}艇")

    lanes = [valid_course(r[0]) for r in rows]
    if sorted(c for c in lanes if c is not None) != [1, 2, 3, 4, 5, 6]:
        raise RuntimeError("対象レースのlane_numberが1～6で揃っていません")

    exhibition_courses = [valid_course(r[3]) for r in rows]
    exhibition_complete = (
        all(c is not None for c in exhibition_courses)
        and sorted(exhibition_courses) == [1, 2, 3, 4, 5, 6]
    )

    boats = []
    for lane, player_id, player_name, exhibition_course in rows:
        lane = valid_course(lane)
        course = valid_course(exhibition_course) if exhibition_complete else lane
        boats.append({
            "lane": lane,
            "player_id": str(player_id or "").strip(),
            "player_name": str(player_name or "").strip(),
            "course": course,
        })

    return boats, "exhibition" if exhibition_complete else "lane_fallback"


def load_history(conn, target_date, target_race_code, target_player_ids):
    history_start = target_date - timedelta(days=HISTORY_DAYS)

    player_hist = defaultdict(lambda: deque(maxlen=100))
    second_course_hist = Counter()
    head1_hist_n = 0
    source_counts = Counter()

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
        WHERE rm.race_date >= %s::date
          AND (
                rm.race_date < %s::date
                OR (rm.race_date = %s::date AND re.race_code < %s)
              )
        ORDER BY rm.race_date, re.race_code, re.lane_number
    """

    def process_race(rows):
        nonlocal head1_hist_n
        if not rows:
            return

        prepared = []
        for lane, player_id, rank, result_course, exhibition_course in rows:
            c, source = actual_course(result_course, exhibition_course, lane)
            if c is not None:
                source_counts[source] += 1
            prepared.append({
                "lane": valid_course(lane),
                "player_id": str(player_id or "").strip(),
                "rank": as_int(rank),
                "course": c,
            })

        six_rows = len(prepared) == 6
        lanes = [r["lane"] for r in prepared]
        lanes_ok = six_rows and sorted(c for c in lanes if c is not None) == [1, 2, 3, 4, 5, 6]

        winners = [r for r in prepared if r["rank"] == 1]
        seconds = [r for r in prepared if r["rank"] == 2]
        top2_ok = len(winners) == 1 and len(seconds) == 1
        head1_win = top2_ok and winners[0]["lane"] == 1

        courses = [r["course"] for r in prepared]
        course_complete = (
            six_rows
            and all(c is not None for c in courses)
            and sorted(courses) == [1, 2, 3, 4, 5, 6]
        )

        # p0はK比較と同じく、1号艇1着 + Top2一意 + 実コース完全レースだけで更新。
        if lanes_ok and top2_ok and head1_win and course_complete:
            second_course = seconds[0]["course"]
            if second_course is not None:
                head1_hist_n += 1
                second_course_hist[second_course] += 1

        # 選手の「直前100走」は全出走を走数として積む。
        # 条件付き2着率に採用するかはeligible_head1で後から絞る。
        eligible_head1 = lanes_ok and top2_ok and head1_win
        for r in prepared:
            pid = r["player_id"]
            if pid not in target_player_ids or r["course"] is None:
                continue
            player_hist[pid].append({
                "course": r["course"],
                "eligible_head1": eligible_head1,
                "second": 1 if eligible_head1 and r["rank"] == 2 else 0,
            })

    with conn.cursor(name="head1_second_base_race_stream") as cur:
        cur.itersize = 10000
        cur.execute(
            sql,
            (
                history_start.isoformat(),
                target_date.isoformat(),
                target_date.isoformat(),
                target_race_code,
            ),
        )

        current_code = None
        rows = []
        for _race_date, race_code, lane, player_id, rank, result_course, exhibition_course in cur:
            race_code = str(race_code)
            if current_code is None:
                current_code = race_code
            if race_code != current_code:
                process_race(rows)
                rows = []
                current_code = race_code
            rows.append((lane, player_id, rank, result_course, exhibition_course))

        if current_code is not None:
            process_race(rows)

    return player_hist, second_course_hist, head1_hist_n, source_counts, history_start


def player_counts(history, target_course):
    n = 0
    w = 0
    for h in history:
        if not h["eligible_head1"]:
            continue
        if h["course"] != target_course:
            continue
        n += 1
        w += h["second"]
    return n, w


def prior_course_rate(second_course_hist, head1_hist_n, course):
    if head1_hist_n > 0:
        return second_course_hist[course] / head1_hist_n
    return 1.0 / 6.0


def normalize(scores):
    total = sum(max(0.0, s) for s in scores)
    if total <= 0:
        return [1.0 / len(scores)] * len(scores)
    return [max(0.0, s) / total for s in scores]


def pct(v):
    return f"{v * 100:7.2f}%"


def main():
    if len(sys.argv) != 2:
        print("Usage: python3 analysis/second_place_head1_base_race.py RACE_CODE")
        return 1

    race_code, target_date = parse_race_code(sys.argv[1])

    with connect_db() as conn:
        boats, target_course_source = load_target(conn, race_code)
        target_player_ids = {b["player_id"] for b in boats if b["player_id"]}
        player_hist, second_course_hist, head1_hist_n, source_counts, history_start = load_history(
            conn,
            target_date,
            race_code,
            target_player_ids,
        )

    candidates = []
    for b in boats:
        if b["lane"] == 1:
            continue
        p0 = prior_course_rate(second_course_hist, head1_hist_n, b["course"])
        n_pc, w_pc = player_counts(player_hist[b["player_id"]], b["course"])
        raw = (w_pc / n_pc) if n_pc else None
        p_pc = (w_pc + K_PC * p0) / (n_pc + K_PC)
        candidates.append({
            **b,
            "p0": p0,
            "n_pc": n_pc,
            "w_pc": w_pc,
            "raw": raw,
            "p_pc": p_pc,
        })

    normalized = normalize([c["p_pc"] for c in candidates])
    for c, p in zip(candidates, normalized):
        c["normalized"] = p

    ranked = sorted(candidates, key=lambda x: (-x["normalized"], x["lane"]))
    boat1 = next(b for b in boats if b["lane"] == 1)

    print("=" * 132)
    print("1号艇1着時の基本2着率：1レース計算")
    print("=" * 132)
    print(f"対象レース              : {race_code}")
    print(f"対象日                  : {target_date}")
    print(f"履歴読込開始            : {history_start}")
    print(f"固定1着艇               : 1号艇 {boat1['player_name']} / 今回{boat1['course']}C")
    print(f"今回コースsource        : {target_course_source}")
    print(f"p0母集団                : 過去の1号艇1着レース {head1_hist_n}件")
    print(f"選手履歴                : 各選手の直前100走")
    print(f"平滑化K                 : {int(K_PC)}")
    print("最終                     : 2～6号艇を100%正規化")

    if boat1["course"] != 1:
        print("注意                     : 今回1号艇が1Cではありません。過去の1号艇1着はほぼ1Cのため参考値扱いです。")

    print("\n【過去の1号艇1着時 2着実コース分布 p0】")
    print("course    2着数      p0")
    print("-" * 34)
    for course in range(1, 7):
        w = second_course_hist[course]
        p0 = prior_course_rate(second_course_hist, head1_hist_n, course)
        print(f" {course}C     {w:>6}   {pct(p0)}")

    print("\n【今回の基本2着率】")
    print("艇  選手ID   選手名             C      p0     選手×C(N/2着)   raw      平滑後      最終2着率")
    print("-" * 132)
    for c in candidates:
        raw_text = "   -   " if c["raw"] is None else pct(c["raw"])
        print(
            f"{c['lane']}   {c['player_id']:<8} {c['player_name'][:16]:<16} "
            f"{c['course']}C  {pct(c['p0'])}   "
            f"{c['n_pc']:>3}/{c['w_pc']:<3}       {raw_text}   "
            f"{pct(c['p_pc'])}   {pct(c['normalized'])}"
        )

    print("\n【順位】")
    print(" > ".join(f"{c['lane']}号艇({c['normalized']*100:.2f}%)" for c in ranked))
    print(f"2～6号艇合計            : {sum(c['normalized'] for c in candidates) * 100:.6f}%")

    print("\n【履歴実コース復元source】")
    for key in ("result", "exhibition", "lane_fallback"):
        print(f"{key:<14}: {source_counts[key]}")

    print("\n【次の確認】")
    print("・p0 → 選手×実コース → K=10平滑化 → 5艇100% が妥当な値になっているかを見る")
    print("・この段階では展示性能/SUM/スリットなどの2着補正はまだ加えない")
    print("・単発計算が問題なければ、次にWeb表示用ロジックへ移植する")
    print("=" * 132)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
