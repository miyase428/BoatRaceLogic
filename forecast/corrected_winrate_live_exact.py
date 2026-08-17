#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""Web用 補正後1着率をSTEP8-4と完全同定義で構築するラッパー。

corrected_winrate_live.py の表示/API骨格を利用しつつ、
以下をSTEP8-4の時系列検証定義へ揃える。

- 場×コース基準: 勝者コースを result -> exhibition -> lane の順で復元
- SUM統計: レースを時系列で処理し、レース終了後に統計更新

展示補正・スリット補正の式自体は元liveをそのまま利用する。
"""

from __future__ import annotations

from collections import defaultdict, deque
from datetime import timedelta
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))

import corrected_winrate_live as live  # noqa: E402


def _rank_int(value):
    if value is None or value == "":
        return None
    try:
        return int(value)
    except (TypeError, ValueError):
        return None


def _feature_value(row, name):
    mapping = {
        "exhibition_time": "exhibition_time",
        "lap_time": "lap_time",
        "around_time": "around_time",
        "straight_time": "straight_time",
    }
    key = mapping.get(name)
    if key is None:
        raise RuntimeError(f"未対応SUM feature: {name}")
    return row[key]


def load_venue_course_prior_exact(conn, race_code, target_date, place_code):
    """STEP8-4と同じ場×コース基準勝率を対象レース直前までで作る。"""
    sql = """
        WITH base_rows AS (
            SELECT
                re.race_code,
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
                SELECT x.entry_course
                FROM boat_race.exhibition_live x
                WHERE x.race_code = re.race_code
                  AND x.player_id = re.player_id
                LIMIT 1
            ) el ON TRUE
            WHERE SUBSTRING(re.race_code, 9, 3) = %s
              AND (
                    rm.race_date < %s::date
                    OR (rm.race_date = %s::date AND re.race_code < %s)
                  )
        ),
        winner_rows AS (
            SELECT
                race_code,
                COUNT(*) FILTER (WHERE rank = '1') AS winner_count,
                MAX(
                    CASE WHEN rank = '1' THEN
                        CASE
                            WHEN result_course::text ~ '^[1-6]$'
                                THEN result_course::int
                            WHEN exhibition_course::text ~ '^[1-6]$'
                                THEN exhibition_course::int
                            WHEN lane_number::text ~ '^[1-6]$'
                                THEN lane_number::int
                            ELSE NULL
                        END
                    ELSE NULL END
                ) AS winner_course
            FROM base_rows
            GROUP BY race_code
        )
        SELECT winner_course, COUNT(*) AS wins
        FROM winner_rows
        WHERE winner_count = 1
          AND winner_course BETWEEN 1 AND 6
        GROUP BY winner_course
        ORDER BY winner_course
    """

    counts = {c: 0 for c in range(1, 7)}
    with conn.cursor() as cur:
        cur.execute(
            sql,
            (
                place_code,
                target_date.isoformat(),
                target_date.isoformat(),
                race_code,
            ),
        )
        for course, wins in cur.fetchall():
            c = live.valid_course(course)
            if c is not None:
                counts[c] = int(wins or 0)

    total = sum(counts.values())
    if total <= 0:
        raise RuntimeError(f"{place_code} の対象レース以前の場×コース履歴がありません")

    return {
        c: {
            "n": total,
            "wins": counts[c],
            "rate": counts[c] / total,
        }
        for c in range(1, 7)
    }


def build_remapped_base_exact(conn, race_code, target_date, place_code, boats, exhibition):
    """STEP8-4と同じ場基準を使い、展示進入へ基本1着率をリマップする。"""
    venue = load_venue_course_prior_exact(
        conn,
        race_code,
        target_date,
        place_code,
    )

    raw = []
    detail = {}

    for boat in sorted(boats, key=lambda r: r["lane"]):
        lane = int(boat["lane"])
        course = exhibition[lane]["course"]
        history = live.load_last_100(
            conn,
            boat["player_id"],
            target_date,
            race_code,
        )
        counts = live.player_counts(history, course, place_code)
        p0 = venue[course]["rate"]
        p_pc = (counts["pc_w"] + live.K_PC * p0) / (counts["pc_n"] + live.K_PC)
        p_final = (counts["pvc_w"] + live.K_PVC * p_pc) / (counts["pvc_n"] + live.K_PVC)
        raw.append(p_final)
        detail[lane] = {
            "course": course,
            "p0": p0,
            "p_pc": p_pc,
            "p_final_raw": p_final,
        }

    probs = live.normalize(raw)
    if probs is None:
        raise RuntimeError("展示進入リマップ後の基本1着率を正規化できません")
    return probs, detail


def load_sum_stats_exact(conn, race_code, target_date, place_code, feature_cols):
    """STEP8-4/load_snapshots と同じ順序で対象レース直前までのSUM統計を作る。"""
    sql = """
        SELECT
            rm.race_date,
            re.race_code,
            re.lane_number,
            re.player_id::text,
            rrd.rank,
            el.entry_course,
            el.exhibition_time,
            el.start_timing,
            el.lap_time,
            el.around_time,
            el.straight_time
        FROM boat_race.race_entry re
        JOIN boat_race.race_master rm
          ON rm.race_code = re.race_code
        LEFT JOIN boat_race.race_result_detail rrd
          ON rrd.race_code = re.race_code
         AND rrd.player_id = re.player_id
        LEFT JOIN LATERAL (
            SELECT
                x.entry_course,
                x.exhibition_time,
                x.start_timing,
                x.lap_time,
                x.around_time,
                x.straight_time
            FROM boat_race.exhibition_live x
            WHERE x.race_code = re.race_code
              AND x.player_id = re.player_id
            LIMIT 1
        ) el ON TRUE
        WHERE SUBSTRING(re.race_code, 9, 3) = %s
          AND (
                rm.race_date < %s::date
                OR (rm.race_date = %s::date AND re.race_code < %s)
              )
        ORDER BY rm.race_date, re.race_code, re.lane_number
    """

    course_n = {c: 0 for c in range(1, 7)}
    course_w = {c: 0 for c in range(1, 7)}
    interval_n = defaultdict(lambda: defaultdict(int))
    interval_w = defaultdict(lambda: defaultdict(int))

    # STEP8-4と同じ「同場・直近183日・対象レースより前」の展示平均履歴。
    ex_hist = deque()
    ex_sum = 0.0

    def process_race(race_date, rows):
        nonlocal ex_sum
        if not rows:
            return

        cutoff = race_date - timedelta(days=live.ROLLING_EX_DAYS)
        while ex_hist and ex_hist[0][0] < cutoff:
            _, old = ex_hist.popleft()
            ex_sum -= old

        venue_avg_ex = ex_sum / len(ex_hist) if ex_hist else None

        winners = [r for r in rows if _rank_int(r["rank"]) == 1]
        unique_winner = len(winners) == 1

        prepared = []
        valid = venue_avg_ex is not None and venue_avg_ex > 0
        seen_lanes = set()
        seen_courses = set()

        if valid:
            for r in rows:
                lane = live.valid_course(r["lane"])
                course = live.valid_course(r["entry_course"])
                ex = live.as_float(r["exhibition_time"])
                st = live.as_float(r["start_timing"])
                lap = live.as_float(r["lap_time"])
                around = live.as_float(r["around_time"])
                straight = live.as_float(r["straight_time"])

                if (
                    lane is None or course is None
                    or ex is None or st is None or lap is None
                    or around is None or straight is None
                ):
                    valid = False
                    break

                if lane in seen_lanes or course in seen_courses:
                    valid = False
                    break
                seen_lanes.add(lane)
                seen_courses.add(course)

                prepared.append({
                    "lane": lane,
                    "course": course,
                    "rank": _rank_int(r["rank"]),
                    "exhibition_time": ex,
                    "start_timing": st,
                    "lap_time": lap,
                    "around_time": around,
                    "straight_time": straight,
                })

            if len(prepared) != 6:
                valid = False
            if seen_lanes != set(range(1, 7)) or seen_courses != set(range(1, 7)):
                valid = False

        if valid:
            raw = [sum(_feature_value(r, name) for name in feature_cols) for r in prepared]
            avg_raw = sum(raw) / 6.0
            for r, sum_raw in zip(prepared, raw):
                r["interval"] = live.sum_interval_label(sum_raw - avg_raw)

        # STEP8-4と同じく、このレースの展示値は「処理後」に履歴へ追加する。
        for r in rows:
            ex = live.as_float(r["exhibition_time"])
            if ex is not None and ex > 0:
                ex_hist.append((race_date, ex))
                ex_sum += ex

        # STEP8-4と同じく unique winner + prepared 完備時だけSUM統計更新。
        if unique_winner and valid:
            for r in prepared:
                c = r["course"]
                label = r["interval"]
                course_n[c] += 1
                interval_n[c][label] += 1
                if r["rank"] == 1:
                    course_w[c] += 1
                    interval_w[c][label] += 1

    with conn.cursor(name="corrected_winrate_sum_exact") as cur:
        cur.itersize = 10000
        cur.execute(
            sql,
            (
                place_code,
                target_date.isoformat(),
                target_date.isoformat(),
                race_code,
            ),
        )

        current_code = None
        current_date = None
        rows = []

        for (
            race_date,
            code,
            lane,
            player_id,
            rank,
            entry_course,
            exhibition_time,
            start_timing,
            lap_time,
            around_time,
            straight_time,
        ) in cur:
            code = str(code)
            if current_code is None:
                current_code = code
                current_date = race_date

            if code != current_code:
                process_race(current_date, rows)
                rows = []
                current_code = code
                current_date = race_date

            rows.append({
                "lane": lane,
                "player_id": str(player_id or "").strip(),
                "rank": rank,
                "entry_course": entry_course,
                "exhibition_time": exhibition_time,
                "start_timing": start_timing,
                "lap_time": lap_time,
                "around_time": around_time,
                "straight_time": straight_time,
            })

        if current_code is not None:
            process_race(current_date, rows)

    stats = {c: {} for c in range(1, 7)}
    labels = [
        "-0.6未満", "-0.6--0.4", "-0.4--0.2", "-0.2-0.0",
        "0.0-0.2", "0.2-0.4", "0.4-0.6", "0.6以上",
    ]
    for c in range(1, 7):
        cn = course_n[c]
        cw = course_w[c]
        for label in labels:
            inn = interval_n[c][label]
            inw = interval_w[c][label]
            score = ((inw / inn) - (cw / cn)) if cn > 0 and inn > 0 else 0.0
            stats[c][label] = {
                "score": score,
                "interval_n": inn,
                "interval_w": inw,
                "course_n": cn,
                "course_w": cw,
            }

    return stats


# 元liveのmain()が参照する関数をSTEP8-4互換版へ差し替える。
live.build_remapped_base = build_remapped_base_exact
live.load_sum_stats = load_sum_stats_exact


if __name__ == "__main__":
    raise SystemExit(live.main())
