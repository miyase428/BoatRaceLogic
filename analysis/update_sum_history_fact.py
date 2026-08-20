#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""SUM履歴Factの日次/随時差分更新。

初回:
- Fact未構築の場は rebuild_sum_history_fact.rebuild_place() でフル構築。

通常:
- 全場共通の元データ最終日から直近N日を更新対象とする。
- 更新対象日の183日前から展示履歴をウォームアップし、
  Factへ書き戻すのは直近N日だけ。
- これにより現行exactの「直前183日展示平均→レース処理後に履歴追加」を維持する。

Usage:
  python3 analysis/update_sum_history_fact.py
  python3 analysis/update_sum_history_fact.py 7
"""

from __future__ import annotations

from collections import deque
from datetime import timedelta
from pathlib import Path
import sys
import time

from psycopg2.extras import execute_values

HERE = Path(__file__).resolve().parent
REPO_ROOT = HERE.parent
sys.path.insert(0, str(HERE))
sys.path.insert(0, str(REPO_ROOT / "analysis"))

import rebuild_sum_history_fact as full  # noqa: E402
from base_winrate_sum_compare import load_sum_features, sum_interval_label  # noqa: E402
from slit_validate_v2 import connect_db  # noqa: E402

DEFAULT_LOOKBACK_DAYS = 7


def source_bounds(conn, place_code: str):
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT MIN(rm.race_date), MAX(rm.race_date)
            FROM boat_race.race_entry re
            JOIN boat_race.race_master rm ON rm.race_code = re.race_code
            WHERE SUBSTRING(re.race_code, 9, 3) = %s
            """,
            (place_code,),
        )
        return cur.fetchone()


def global_source_max(conn):
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT MAX(rm.race_date)
            FROM boat_race.race_entry re
            JOIN boat_race.race_master rm ON rm.race_code = re.race_code
            """
        )
        return cur.fetchone()[0]


def fact_state(conn, place_code: str):
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT
                COUNT(*)::int,
                MIN(race_date),
                MAX(race_date),
                ARRAY_AGG(DISTINCT feature_signature ORDER BY feature_signature)
            FROM boat_race.sum_history_fact
            WHERE place_code = %s
            """,
            (place_code,),
        )
        return cur.fetchone()


def load_place_rows_from(conn, place_code: str, start_date):
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
          AND rm.race_date >= %s::date
        ORDER BY rm.race_date, re.race_code, re.lane_number
    """
    cur = conn.cursor(name=f"sum_fact_update_{place_code.lower()}")
    cur.itersize = 10000
    cur.execute(sql, (place_code, start_date.isoformat()))
    return cur


def update_place_range(conn, place_code: str, feature_cols: list[str], recompute_from) -> dict:
    special = place_code in full.SPECIAL_PLACES
    signature = "+".join(feature_cols)
    warmup_from = recompute_from - timedelta(days=full.ROLLING_EX_DAYS)

    with conn.cursor() as cur:
        cur.execute(
            "DELETE FROM boat_race.sum_history_fact WHERE place_code = %s AND race_date >= %s::date",
            (place_code, recompute_from.isoformat()),
        )
        deleted = cur.rowcount

    ex_hist = deque()
    ex_sum = 0.0
    batch = []
    inserted = 0
    race_seen = 0
    race_updated = 0

    def flush_batch():
        nonlocal batch, inserted
        if not batch:
            return
        sql = """
            INSERT INTO boat_race.sum_history_fact (
                race_code, race_date, place_code, course,
                interval_label, win, feature_signature
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
        inserted += len(batch)
        batch = []

    def process_race(race_date, race_code: str, rows: list[dict]):
        nonlocal ex_sum, race_seen, race_updated, batch
        if not rows:
            return
        race_seen += 1

        cutoff = race_date - timedelta(days=full.ROLLING_EX_DAYS)
        while ex_hist and ex_hist[0][0] < cutoff:
            _, old = ex_hist.popleft()
            ex_sum -= old

        venue_avg_ex = ex_sum / len(ex_hist) if ex_hist else None
        winners = [r for r in rows if full.rank_int(r["rank"]) == 1]
        unique_winner = len(winners) == 1

        prepared = []
        valid = venue_avg_ex is not None and venue_avg_ex > 0
        seen_lanes = set()
        seen_courses = set()

        if valid:
            for r in rows:
                lane = full.valid_course(r["lane"])
                course = full.valid_course(r["entry_course"])
                ex = full.as_float(r["exhibition_time"])
                st = full.as_float(r["start_timing"])
                lap = full.as_float(r["lap_time"])
                around = full.as_float(r["around_time"])
                straight = full.as_float(r["straight_time"])

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
                prepared.append(
                    {
                        "lane": lane,
                        "course": course,
                        "rank": full.rank_int(r["rank"]),
                        "exhibition_time": ex,
                        "start_timing": st,
                        "lap_time": lap,
                        "around_time": around,
                        "straight_time": straight,
                    }
                )

            if len(prepared) != 6:
                valid = False
            if seen_lanes != set(range(1, 7)) or seen_courses != set(range(1, 7)):
                valid = False

        if valid:
            raw = [sum(full.feature_value(r, name) for name in feature_cols) for r in prepared]
            avg_raw = sum(raw) / 6.0
            for r, sum_raw in zip(prepared, raw):
                r["interval"] = sum_interval_label(sum_raw - avg_raw)

        # 現行exactと同じく、当該レース展示はレース処理後に履歴へ追加する。
        for r in rows:
            ex = full.as_float(r["exhibition_time"])
            if ex is not None and ex > 0:
                ex_hist.append((race_date, ex))
                ex_sum += ex

        if race_date >= recompute_from and unique_winner and valid:
            race_updated += 1
            for r in prepared:
                batch.append(
                    (
                        race_code,
                        race_date,
                        place_code,
                        r["course"],
                        r["interval"],
                        r["rank"] == 1,
                        signature,
                    )
                )
            if len(batch) >= 5000:
                flush_batch()

    cur = load_place_rows_from(conn, place_code, warmup_from)
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

            rows.append(
                {
                    "lane": lane,
                    "player_id": str(player_id or "").strip(),
                    "rank": rank,
                    "entry_course": entry_course,
                    "exhibition_time": exhibition_time,
                    "start_timing": start_timing,
                    "lap_time": lap_time,
                    "around_time": around_time,
                    "straight_time": straight_time,
                }
            )

        if current_code is not None:
            process_race(current_date, current_code, rows)
    finally:
        try:
            cur.close()
        except Exception:
            pass

    flush_batch()
    conn.commit()

    return {
        "place": place_code,
        "signature": signature,
        "warmup_from": warmup_from,
        "recompute_from": recompute_from,
        "deleted": deleted,
        "inserted": inserted,
        "race_seen": race_seen,
        "race_updated": race_updated,
    }


def main() -> int:
    lookback_days = DEFAULT_LOOKBACK_DAYS
    if len(sys.argv) >= 2:
        try:
            lookback_days = max(1, int(sys.argv[1]))
        except ValueError:
            print("Usage: python3 analysis/update_sum_history_fact.py [LOOKBACK_DAYS]", file=sys.stderr)
            return 1

    features = load_sum_features()
    if not isinstance(features, dict) or not features:
        print("SUM features設定を読み込めません", file=sys.stderr)
        return 1

    print("=" * 100)
    print("SUM履歴Fact 差分更新")
    print("=" * 100)
    print(f"lookback : {lookback_days}日")

    total0 = time.perf_counter()
    with connect_db() as conn:
        full.create_table(conn)
        source_max = global_source_max(conn)
        if source_max is None:
            print("元レースデータがありません", file=sys.stderr)
            return 1
        recompute_from = source_max - timedelta(days=lookback_days - 1)
        print(f"元データ最終日: {source_max}")
        print(f"差分更新開始日: {recompute_from}")
        print("-" * 100)

        full_count = 0
        diff_count = 0
        skip_count = 0

        for place in sorted(str(k).upper() for k in features.keys()):
            cols = list(features[place])
            signature = "+".join(cols)
            src_min, src_max = source_bounds(conn, place)
            if src_max is None:
                print(f"{place}: SKIP 元データなし")
                skip_count += 1
                continue

            fact_n, fact_min, fact_max, signatures = fact_state(conn, place)
            signatures = list(signatures or [])

            if fact_n <= 0 or signatures != [signature]:
                t0 = time.perf_counter()
                reason = "未構築" if fact_n <= 0 else "features変更"
                stat = full.rebuild_place(conn, place, cols)
                print(
                    f"{place}: FULL {time.perf_counter() - t0:6.2f}s / {reason} / "
                    f"fact_races={stat['fact_races']} rows={stat['rows']}"
                )
                full_count += 1
                continue

            # 直近N日に開催がない場は更新不要。
            if src_max < recompute_from:
                print(f"{place}: SKIP 最終開催={src_max}")
                skip_count += 1
                continue

            place_from = recompute_from if src_min is None or src_min < recompute_from else src_min
            t0 = time.perf_counter()
            stat = update_place_range(conn, place, cols, place_from)
            print(
                f"{place}: DIFF {time.perf_counter() - t0:6.2f}s / "
                f"from={stat['recompute_from']} warmup={stat['warmup_from']} / "
                f"delete={stat['deleted']} insert={stat['inserted']}"
            )
            diff_count += 1

        with conn.cursor() as cur:
            cur.execute("ANALYZE boat_race.sum_history_fact")
        conn.commit()

    print("-" * 100)
    print(f"FULL={full_count} / DIFF={diff_count} / SKIP={skip_count}")
    print(f"総時間: {time.perf_counter() - total0:.2f} sec")
    print("=" * 100)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
