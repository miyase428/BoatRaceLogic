#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""補正後1着率 SUM_RAW 用の履歴Factを構築する。

現行 corrected_winrate_live_exact.py / corrected_winrate_live_exact_amg_tky.py と
同じ時系列定義で、各レースの「実コース×SUM区間×勝敗」を事前計算して保存する。

重要:
- 場平均展示タイムは各レース処理時点の直前183日だけを使用
- 当該レースの展示値は、そのレースを処理した後に履歴へ追加（未来情報なし）
- unique winner + 6艇/6コース/必要展示項目完備のレースだけFactへ保存
- AMG/TKYだけ straight_time 欠損を許可
- features.json の現在の場別3項目を使用

Usage:
  python3 analysis/rebuild_sum_history_fact.py OMR
  python3 analysis/rebuild_sum_history_fact.py ALL
"""

from __future__ import annotations

from collections import deque
from datetime import timedelta
from pathlib import Path
import json
import math
import sys
import time

REPO_ROOT = Path(__file__).resolve().parent.parent
FORECAST_DIR = REPO_ROOT / "forecast"
ANALYSIS_DIR = REPO_ROOT / "analysis"
THEORY_DIR = REPO_ROOT / "theories" / "course_correction"
sys.path.insert(0, str(FORECAST_DIR))
sys.path.insert(0, str(ANALYSIS_DIR))
sys.path.insert(0, str(THEORY_DIR))

from slit_validate_v2 import connect_db  # noqa: E402
from base_winrate_sum_compare import load_sum_features, sum_interval_label  # noqa: E402

ROLLING_EX_DAYS = 183
SPECIAL_PLACES = {"AMG", "TKY"}


def as_float(value):
    if value is None or value == "":
        return None
    try:
        x = float(value)
    except (TypeError, ValueError):
        return None
    return x if math.isfinite(x) else None


def valid_course(value):
    try:
        c = int(value)
    except (TypeError, ValueError):
        return None
    return c if 1 <= c <= 6 else None


def rank_int(value):
    if value is None or value == "":
        return None
    try:
        return int(value)
    except (TypeError, ValueError):
        return None


def feature_value(row: dict, name: str) -> float:
    mapping = {
        "exhibition_time": "exhibition_time",
        "lap_time": "lap_time",
        "around_time": "around_time",
        "straight_time": "straight_time",
    }
    key = mapping.get(name)
    if key is None:
        raise RuntimeError(f"未対応SUM feature: {name}")
    value = row.get(key)
    if value is None:
        raise RuntimeError(f"SUM feature欠損: {name}")
    return float(value)


def create_table(conn) -> None:
    sql = """
        CREATE TABLE IF NOT EXISTS boat_race.sum_history_fact (
            race_code          text NOT NULL,
            race_date          date NOT NULL,
            place_code         varchar(3) NOT NULL,
            course             smallint NOT NULL,
            interval_label     text NOT NULL,
            win                boolean NOT NULL,
            feature_signature  text NOT NULL,
            rebuilt_at         timestamptz NOT NULL DEFAULT now(),
            PRIMARY KEY (race_code, course)
        )
    """
    indexes = [
        "CREATE INDEX IF NOT EXISTS idx_sum_history_fact_place_date_code ON boat_race.sum_history_fact (place_code, race_date, race_code)",
        "CREATE INDEX IF NOT EXISTS idx_sum_history_fact_place_course_interval_date ON boat_race.sum_history_fact (place_code, course, interval_label, race_date, race_code)",
    ]
    with conn.cursor() as cur:
        cur.execute(sql)
        for stmt in indexes:
            cur.execute(stmt)
    conn.commit()


def load_place_rows(conn, place_code: str):
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
        ORDER BY rm.race_date, re.race_code, re.lane_number
    """
    cur = conn.cursor(name=f"sum_fact_{place_code.lower()}")
    cur.itersize = 10000
    cur.execute(sql, (place_code,))
    return cur


def rebuild_place(conn, place_code: str, feature_cols: list[str]) -> dict:
    special = place_code in SPECIAL_PLACES
    feature_signature = "+".join(feature_cols)

    with conn.cursor() as cur:
        cur.execute("DELETE FROM boat_race.sum_history_fact WHERE place_code = %s", (place_code,))
    conn.commit()

    ex_hist = deque()
    ex_sum = 0.0
    batch = []
    inserted = 0
    race_seen = 0
    race_fact = 0
    min_date = None
    max_date = None

    def flush_batch():
        nonlocal inserted, batch
        if not batch:
            return
        from psycopg2.extras import execute_values
        sql = """
            INSERT INTO boat_race.sum_history_fact (
                race_code, race_date, place_code, course,
                interval_label, win, feature_signature, rebuilt_at
            ) VALUES %s
            ON CONFLICT (race_code, course) DO UPDATE SET
                race_date = EXCLUDED.race_date,
                place_code = EXCLUDED.place_code,
                interval_label = EXCLUDED.interval_label,
                win = EXCLUDED.win,
                feature_signature = EXCLUDED.feature_signature,
                rebuilt_at = now()
        """
        with conn.cursor() as cur:
            execute_values(cur, sql, batch, page_size=5000)
        conn.commit()
        inserted += len(batch)
        batch = []

    def process_race(race_date, race_code: str, rows: list[dict]):
        nonlocal ex_sum, race_seen, race_fact, min_date, max_date, batch
        if not rows:
            return
        race_seen += 1
        min_date = race_date if min_date is None or race_date < min_date else min_date
        max_date = race_date if max_date is None or race_date > max_date else max_date

        cutoff = race_date - timedelta(days=ROLLING_EX_DAYS)
        while ex_hist and ex_hist[0][0] < cutoff:
            _, old = ex_hist.popleft()
            ex_sum -= old

        venue_avg_ex = ex_sum / len(ex_hist) if ex_hist else None
        winners = [r for r in rows if rank_int(r["rank"]) == 1]
        unique_winner = len(winners) == 1

        prepared = []
        valid = venue_avg_ex is not None and venue_avg_ex > 0
        seen_lanes = set()
        seen_courses = set()

        if valid:
            for r in rows:
                lane = valid_course(r["lane"])
                course = valid_course(r["entry_course"])
                ex = as_float(r["exhibition_time"])
                st = as_float(r["start_timing"])
                lap = as_float(r["lap_time"])
                around = as_float(r["around_time"])
                straight = as_float(r["straight_time"])

                required_ok = (
                    lane is not None
                    and course is not None
                    and ex is not None
                    and st is not None
                    and lap is not None
                    and around is not None
                    and (special or straight is not None)
                )
                if not required_ok or lane in seen_lanes or course in seen_courses:
                    valid = False
                    break

                seen_lanes.add(lane)
                seen_courses.add(course)
                prepared.append({
                    "lane": lane,
                    "course": course,
                    "rank": rank_int(r["rank"]),
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
            raw = [sum(feature_value(r, name) for name in feature_cols) for r in prepared]
            avg_raw = sum(raw) / 6.0
            for r, sum_raw in zip(prepared, raw):
                r["interval"] = sum_interval_label(sum_raw - avg_raw)

        # exact定義と同じく、当該レースの展示値は処理後に履歴へ追加する。
        for r in rows:
            ex = as_float(r["exhibition_time"])
            if ex is not None and ex > 0:
                ex_hist.append((race_date, ex))
                ex_sum += ex

        if unique_winner and valid:
            race_fact += 1
            for r in prepared:
                batch.append((
                    race_code,
                    race_date,
                    place_code,
                    r["course"],
                    r["interval"],
                    r["rank"] == 1,
                    feature_signature,
                ))
            if len(batch) >= 5000:
                flush_batch()

    cur = load_place_rows(conn, place_code)
    current_code = None
    current_date = None
    rows = []
    try:
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
                process_race(current_date, current_code, rows)
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
            process_race(current_date, current_code, rows)
    finally:
        cur.close()

    flush_batch()
    with conn.cursor() as cur2:
        cur2.execute("ANALYZE boat_race.sum_history_fact")
    conn.commit()

    return {
        "place": place_code,
        "features": feature_cols,
        "feature_signature": feature_signature,
        "race_seen": race_seen,
        "fact_races": race_fact,
        "rows": inserted,
        "min_date": str(min_date) if min_date else "-",
        "max_date": str(max_date) if max_date else "-",
    }


def main() -> int:
    arg = (sys.argv[1] if len(sys.argv) >= 2 else "").strip().upper()
    if not arg:
        print("Usage: python3 analysis/rebuild_sum_history_fact.py OMR|ALL", file=sys.stderr)
        return 1

    features = load_sum_features()
    if not isinstance(features, dict) or not features:
        print("SUM features設定を読み込めません", file=sys.stderr)
        return 1

    if arg == "ALL":
        places = sorted(str(k).upper() for k in features.keys())
    else:
        if arg not in features:
            print(f"SUM features設定に場がありません: {arg}", file=sys.stderr)
            return 1
        places = [arg]

    print("=" * 92)
    print("補正後1着率 SUM履歴Fact 再構築")
    print("=" * 92)
    print("対象:", ", ".join(places))

    t0 = time.perf_counter()
    with connect_db() as conn:
        create_table(conn)
        for place in places:
            pt0 = time.perf_counter()
            stat = rebuild_place(conn, place, list(features[place]))
            elapsed = time.perf_counter() - pt0
            print(
                f"{place}: {elapsed:7.2f} sec / races={stat['race_seen']} / "
                f"fact_races={stat['fact_races']} / rows={stat['rows']} / "
                f"features={stat['feature_signature']} / {stat['min_date']}～{stat['max_date']}"
            )

    print("-" * 92)
    print(f"総時間: {time.perf_counter() - t0:.2f} sec")
    print("=" * 92)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
