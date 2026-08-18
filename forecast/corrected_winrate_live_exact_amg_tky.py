#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""AMG/TKY専用 Web補正後1着率 exactラッパー。

尼崎(AMG)・徳山(TKY)は straight_time が実質存在しないため、
2期間検証で採用した固定チェーンを本番Webへ適用する。

- 基本1着率: BB_MEDIUM Kpc=20 / Kpvc=10 + 展示進入リマップ
- 展示補正: EX_TOTAL3 = 展示タイム + 周回 + 周り足 / beta=0.10
- SUM: exhibition_time + lap_time + around_time / SUM_RAW gamma=2.0
- スリット: 既存 exact と同じ / alpha=0.25

既存22場用 corrected_winrate_live_exact.py は変更しない。
"""

from __future__ import annotations

from collections import defaultdict, deque
from datetime import timedelta
from pathlib import Path
import sys

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))

import corrected_winrate_live_exact as exact  # noqa: E402

live = exact.live
TARGET_PLACES = {"AMG", "TKY"}


def load_current_exhibition_no_straight(conn, race_code):
    place_code = str(race_code)[8:11]
    if place_code not in TARGET_PLACES:
        raise RuntimeError(f"AMG/TKY専用スクリプトへ対象外の場が渡されました: {place_code}")

    sql = """
        SELECT
            re.lane_number,
            re.player_id::text,
            el.entry_course,
            el.exhibition_time,
            el.start_timing,
            el.lap_time,
            el.around_time,
            el.straight_time
        FROM boat_race.race_entry re
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
        WHERE re.race_code = %s
        ORDER BY re.lane_number
    """

    with conn.cursor() as cur:
        cur.execute(sql, (race_code,))
        rows = cur.fetchall()

    if len(rows) != 6:
        raise RuntimeError(f"展示情報が6艇分揃っていません: rows={len(rows)}")

    out = {}
    courses = set()
    for lane, player_id, course, ex, st, lap, around, straight in rows:
        lane = live.valid_course(lane)
        course = live.valid_course(course)
        ex = live.as_float(ex)
        st = live.as_float(st)
        lap = live.as_float(lap)
        around = live.as_float(around)
        straight = live.as_float(straight)

        # AMG/TKYではstraight_timeだけ欠損を許可する。
        if lane is None or course is None or ex is None or st is None or lap is None or around is None:
            raise RuntimeError("展示情報が未取得または不完全です")
        if lane in out or course in courses:
            raise RuntimeError("展示進入が重複しています")

        courses.add(course)
        out[lane] = {
            "lane": lane,
            "player_id": str(player_id or "").strip(),
            "course": course,
            "exhibition_time": ex,
            "start_timing": st,
            "lap_time": lap,
            "around_time": around,
            "straight_time": straight,
        }

    if set(out) != set(range(1, 7)) or courses != set(range(1, 7)):
        raise RuntimeError("展示進入1～6コースが揃っていません")
    return out


def calc_ex_total3_scores(exhibition, venue_avg_ex):
    rows = [exhibition[lane] for lane in range(1, 7)]
    avg_lap = sum(r["lap_time"] for r in rows) / 6.0
    avg_around = sum(r["around_time"] for r in rows) / 6.0

    scores = []
    detail = {}
    for r in rows:
        ex_score = live.calc_ex_score(r["exhibition_time"] - venue_avg_ex)
        lap_score = live.calc_lap_score(r["lap_time"], avg_lap)
        around_score = live.calc_around_score(r["around_time"], avg_around)
        ex_total = float(ex_score + lap_score + around_score)
        scores.append(ex_total)
        detail[r["lane"]] = {
            "ex_score": ex_score,
            "lap_score": lap_score,
            "around_score": around_score,
            "straight_score": None,
            "ex_total": ex_total,
            "ex_method": "EX_TOTAL3",
        }
    return scores, detail


def load_sum_stats_exact_no_straight(conn, race_code, target_date, place_code, feature_cols):
    """AMG/TKY検証と同じ時系列順序でSUM_RAW統計を構築する。"""
    if place_code not in TARGET_PLACES:
        raise RuntimeError(f"AMG/TKY専用SUMへ対象外の場が渡されました: {place_code}")
    if "straight_time" in feature_cols:
        raise RuntimeError(f"AMG/TKY SUM featuresにstraight_timeが残っています: {feature_cols}")

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
        winners = [r for r in rows if exact._rank_int(r["rank"]) == 1]
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

                # 検証と同じくstraightだけ欠損許可。
                if lane is None or course is None or ex is None or st is None or lap is None or around is None:
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
                    "rank": exact._rank_int(r["rank"]),
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
            raw = [sum(exact._feature_value(r, name) for name in feature_cols) for r in prepared]
            avg_raw = sum(raw) / 6.0
            for r, sum_raw in zip(prepared, raw):
                r["interval"] = live.sum_interval_label(sum_raw - avg_raw)

        # 未来情報を入れないため、このレースの展示値は処理後に履歴へ追加。
        for r in rows:
            ex = live.as_float(r["exhibition_time"])
            if ex is not None and ex > 0:
                ex_hist.append((race_date, ex))
                ex_sum += ex

        if unique_winner and valid:
            for r in prepared:
                c = r["course"]
                label = r["interval"]
                course_n[c] += 1
                interval_n[c][label] += 1
                if r["rank"] == 1:
                    course_w[c] += 1
                    interval_w[c][label] += 1

    with conn.cursor(name="corrected_winrate_sum_exact_amg_tky") as cur:
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


# corrected_winrate_live_exact.pyが既に基本率exact版を差し替えている。
# AMG/TKY専用として展示取得・EX_TOTAL・SUMだけ追加差し替えする。
live.load_current_exhibition = load_current_exhibition_no_straight
live.calc_ex_total_scores = calc_ex_total3_scores
live.load_sum_stats = load_sum_stats_exact_no_straight


if __name__ == "__main__":
    raise SystemExit(live.main())
