#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""SUM履歴Factが極端に少ない場の原因を診断する。

現行 corrected_winrate_live_exact.py のSUM履歴有効条件に沿って、
場ごとに以下を確認する。
- 元レース数 / 期間
- 6艇・1着一意・展示進入6コース一意
- 展示/ST/周回/周り足/直線の6艇完備件数
- 現行SUM条件を満たす概算レース数
- sum_history_fact 保存件数

Usage:
  python3 analysis/sum_fact_place_diagnose.py SME
"""

from __future__ import annotations

from pathlib import Path
import sys

REPO_ROOT = Path(__file__).resolve().parent.parent
ANALYSIS_DIR = REPO_ROOT / "analysis"
THEORY_DIR = REPO_ROOT / "theories" / "course_correction"
sys.path.insert(0, str(ANALYSIS_DIR))
sys.path.insert(0, str(THEORY_DIR))

from slit_validate_v2 import connect_db  # noqa: E402
from base_winrate_sum_compare import load_sum_features  # noqa: E402

SPECIAL_PLACES = {"AMG", "TKY"}


def main() -> int:
    place = (sys.argv[1] if len(sys.argv) >= 2 else "SME").strip().upper()
    features = load_sum_features()
    if place not in features:
        print(f"SUM features設定に場がありません: {place}", file=sys.stderr)
        return 1

    special = place in SPECIAL_PLACES
    required_straight = not special

    sql = """
        WITH rows AS (
            SELECT
                rm.race_date,
                re.race_code,
                re.lane_number,
                CASE WHEN rrd.rank::text ~ '^[1-6]$' THEN rrd.rank::int END AS rank_num,
                CASE WHEN el.entry_course::text ~ '^[1-6]$' THEN el.entry_course::int END AS entry_course,
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
        ), race AS (
            SELECT
                race_code,
                MIN(race_date) AS race_date,
                COUNT(*) AS row_n,
                COUNT(DISTINCT lane_number) AS lane_n,
                COUNT(*) FILTER (WHERE rank_num = 1) AS rank1_n,
                COUNT(*) FILTER (WHERE entry_course BETWEEN 1 AND 6) AS course_value_n,
                COUNT(DISTINCT entry_course) FILTER (WHERE entry_course BETWEEN 1 AND 6) AS course_n,
                COUNT(*) FILTER (WHERE exhibition_time IS NOT NULL) AS exhibition_n,
                COUNT(*) FILTER (WHERE start_timing IS NOT NULL) AS start_n,
                COUNT(*) FILTER (WHERE lap_time IS NOT NULL) AS lap_n,
                COUNT(*) FILTER (WHERE around_time IS NOT NULL) AS around_n,
                COUNT(*) FILTER (WHERE straight_time IS NOT NULL) AS straight_n
            FROM rows
            GROUP BY race_code
        )
        SELECT
            COUNT(*) AS all_races,
            MIN(race_date) AS min_date,
            MAX(race_date) AS max_date,
            COUNT(*) FILTER (WHERE row_n = 6 AND lane_n = 6) AS six_boat,
            COUNT(*) FILTER (WHERE rank1_n = 1) AS unique_winner,
            COUNT(*) FILTER (WHERE course_value_n = 6 AND course_n = 6) AS six_course,
            COUNT(*) FILTER (WHERE exhibition_n = 6) AS exhibition6,
            COUNT(*) FILTER (WHERE start_n = 6) AS start6,
            COUNT(*) FILTER (WHERE lap_n = 6) AS lap6,
            COUNT(*) FILTER (WHERE around_n = 6) AS around6,
            COUNT(*) FILTER (WHERE straight_n = 6) AS straight6,
            COUNT(*) FILTER (
                WHERE row_n = 6
                  AND lane_n = 6
                  AND rank1_n = 1
                  AND course_value_n = 6
                  AND course_n = 6
                  AND exhibition_n = 6
                  AND start_n = 6
                  AND lap_n = 6
                  AND around_n = 6
                  AND (%s = false OR straight_n = 6)
            ) AS exact_condition_races
        FROM race
    """

    with connect_db() as conn:
        with conn.cursor() as cur:
            cur.execute(sql, (place, required_straight))
            row = cur.fetchone()

            cur.execute("SELECT to_regclass('boat_race.sum_history_fact')")
            fact_exists = cur.fetchone()[0] is not None
            fact_races = fact_rows = 0
            fact_min = fact_max = None
            if fact_exists:
                cur.execute(
                    """
                    SELECT COUNT(DISTINCT race_code), COUNT(*), MIN(race_date), MAX(race_date)
                    FROM boat_race.sum_history_fact
                    WHERE place_code = %s
                    """,
                    (place,),
                )
                fact_races, fact_rows, fact_min, fact_max = cur.fetchone()

    (
        all_races, min_date, max_date, six_boat, unique_winner, six_course,
        exhibition6, start6, lap6, around6, straight6, exact_condition_races,
    ) = row

    print("=" * 92)
    print("SUM履歴Fact 場別診断")
    print("=" * 92)
    print(f"place          : {place}")
    print(f"features       : {'+'.join(features[place])}")
    print(f"special rule   : {'YES (straight欠損許可)' if special else 'NO (straight必須)'}")
    print(f"元レース       : {all_races} / {min_date} ～ {max_date}")
    print("-" * 92)
    print(f"6艇/6枠        : {six_boat}")
    print(f"1着一意        : {unique_winner}")
    print(f"展示進入6C一意 : {six_course}")
    print(f"展示time 6艇   : {exhibition6}")
    print(f"ST 6艇         : {start6}")
    print(f"周回 6艇       : {lap6}")
    print(f"周り足 6艇     : {around6}")
    print(f"直線 6艇       : {straight6}")
    print("-" * 92)
    print(f"現行SUM条件概算: {exact_condition_races}")
    print(f"Factレース     : {fact_races}")
    print(f"Fact行数       : {fact_rows}")
    print(f"Fact期間       : {fact_min or '-'} ～ {fact_max or '-'}")
    if not special and straight6 < max(10, int(all_races or 0) // 10):
        print("判定           : straight_time完備が極端に少ない可能性があります。")
    elif int(exact_condition_races or 0) <= 10:
        print("判定           : 現行SUM有効条件のどこかで大半が除外されています。")
    else:
        print("判定           : 有効件数は一定数あります。Fact件数との差を確認してください。")
    print("=" * 92)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
