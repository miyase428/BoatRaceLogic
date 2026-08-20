#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""Web本番用の通常22場向け Fact 高速化ラッパー。

corrected_winrate_live_exact.py の検証済み定義はそのまま利用し、
以下の重い履歴集計だけFactを優先して参照する。

- SUM統計       : boat_race.sum_history_fact
- 場×コースprior: boat_race.race_history_fact (winner_valid / c1)

安全策:
- Factテーブル/必要列/対象場/feature_signature が無ければ従来exactへフォールバック
- Fact最終レースより後に元テーブル側の対象場レースが存在する場合は
  staleの可能性があるため従来exactへフォールバック
- Fact利用時も対象レース直前までの race_date/race_code 条件を維持
"""

from __future__ import annotations

import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))

import corrected_winrate_live_exact as exact  # noqa: E402

live = exact.live

# monkey patch後の再帰を避けるため、従来関数を先に退避する。
_LEGACY_VENUE_PRIOR = exact.load_venue_course_prior_exact

LABELS = [
    "-0.6未満", "-0.6--0.4", "-0.4--0.2", "-0.2-0.0",
    "0.0-0.2", "0.2-0.4", "0.4-0.6", "0.6以上",
]


def _source_has_newer_place_race(
    conn,
    place_code,
    target_date,
    race_code,
    max_fact_date,
    max_fact_code,
):
    """Fact最終レースより新しい対象場レースが元テーブルにあればTrue。"""
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT EXISTS (
                SELECT 1
                FROM boat_race.race_entry re
                JOIN boat_race.race_master rm
                  ON rm.race_code = re.race_code
                WHERE SUBSTRING(re.race_code, 9, 3) = %s
                  AND (
                        rm.race_date > %s::date
                        OR (rm.race_date = %s::date AND re.race_code > %s)
                      )
                  AND (
                        rm.race_date < %s::date
                        OR (rm.race_date = %s::date AND re.race_code < %s)
                      )
                LIMIT 1
            )
            """,
            (
                place_code,
                max_fact_date.isoformat(),
                max_fact_date.isoformat(),
                max_fact_code,
                target_date.isoformat(),
                target_date.isoformat(),
                race_code,
            ),
        )
        return bool(cur.fetchone()[0])


def _load_venue_course_prior_from_fact(conn, race_code, target_date, place_code):
    """winner_valid/c1から現行exactと同じ場×コース勝率を作る。"""
    with conn.cursor() as cur:
        cur.execute("SELECT to_regclass('boat_race.race_history_fact')")
        if cur.fetchone()[0] is None:
            return None

        # 古いFact定義へ接続した場合は従来exactへ戻す。
        cur.execute(
            """
            SELECT EXISTS (
                SELECT 1
                FROM information_schema.columns
                WHERE table_schema = 'boat_race'
                  AND table_name = 'race_history_fact'
                  AND column_name = 'winner_valid'
            )
            """
        )
        if not bool(cur.fetchone()[0]):
            return None

        cur.execute(
            """
            SELECT race_date, race_code
            FROM boat_race.race_history_fact
            WHERE place_code = %s
              AND (
                    race_date < %s::date
                    OR (race_date = %s::date AND race_code < %s)
                  )
            ORDER BY race_date DESC, race_code DESC
            LIMIT 1
            """,
            (
                place_code,
                target_date.isoformat(),
                target_date.isoformat(),
                race_code,
            ),
        )
        latest = cur.fetchone()
        if latest is None:
            return None
        max_fact_date, max_fact_code = latest

    if _source_has_newer_place_race(
        conn,
        place_code,
        target_date,
        race_code,
        max_fact_date,
        str(max_fact_code),
    ):
        return None

    counts = {c: 0 for c in range(1, 7)}
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT c1, COUNT(*)::int AS wins
            FROM boat_race.race_history_fact
            WHERE place_code = %s
              AND winner_valid
              AND (
                    race_date < %s::date
                    OR (race_date = %s::date AND race_code < %s)
                  )
            GROUP BY c1
            ORDER BY c1
            """,
            (
                place_code,
                target_date.isoformat(),
                target_date.isoformat(),
                race_code,
            ),
        )
        rows = cur.fetchall()

    for course, wins in rows:
        c = live.valid_course(course)
        if c is not None:
            counts[c] = int(wins or 0)

    total = sum(counts.values())
    if total <= 0:
        return None

    return {
        c: {
            "n": total,
            "wins": counts[c],
            "rate": counts[c] / total,
        }
        for c in range(1, 7)
    }


def load_venue_course_prior_fact_first(conn, race_code, target_date, place_code):
    prior = _load_venue_course_prior_from_fact(
        conn,
        race_code,
        target_date,
        place_code,
    )
    if prior is not None:
        return prior
    return _LEGACY_VENUE_PRIOR(
        conn,
        race_code,
        target_date,
        place_code,
    )


def _load_sum_stats_from_fact(conn, race_code, target_date, place_code, feature_cols):
    signature = "+".join(feature_cols)

    with conn.cursor() as cur:
        cur.execute("SELECT to_regclass('boat_race.sum_history_fact')")
        if cur.fetchone()[0] is None:
            return None

        cur.execute(
            """
            SELECT MAX(race_date)
            FROM boat_race.sum_history_fact
            WHERE place_code = %s
              AND feature_signature = %s
              AND (
                    race_date < %s::date
                    OR (race_date = %s::date AND race_code < %s)
                  )
            """,
            (
                place_code,
                signature,
                target_date.isoformat(),
                target_date.isoformat(),
                race_code,
            ),
        )
        max_fact_date = cur.fetchone()[0]
        if max_fact_date is None:
            return None

        # Fact構築後に対象場のレースが追加されている場合は、
        # 未反映の可能性を優先して従来exactへ戻す。
        cur.execute("SELECT to_regclass('boat_race.race_history_fact')")
        if cur.fetchone()[0] is not None:
            cur.execute(
                """
                SELECT EXISTS (
                    SELECT 1
                    FROM boat_race.race_history_fact
                    WHERE place_code = %s
                      AND race_date > %s::date
                      AND (
                            race_date < %s::date
                            OR (race_date = %s::date AND race_code < %s)
                          )
                )
                """,
                (
                    place_code,
                    max_fact_date.isoformat(),
                    target_date.isoformat(),
                    target_date.isoformat(),
                    race_code,
                ),
            )
            if bool(cur.fetchone()[0]):
                return None

        cur.execute(
            """
            SELECT
                course,
                interval_label,
                COUNT(*)::int AS n,
                COUNT(*) FILTER (WHERE win)::int AS w
            FROM boat_race.sum_history_fact
            WHERE place_code = %s
              AND feature_signature = %s
              AND (
                    race_date < %s::date
                    OR (race_date = %s::date AND race_code < %s)
                  )
            GROUP BY course, interval_label
            ORDER BY course, interval_label
            """,
            (
                place_code,
                signature,
                target_date.isoformat(),
                target_date.isoformat(),
                race_code,
            ),
        )
        rows = cur.fetchall()

    if not rows:
        return None

    interval_n = {c: {label: 0 for label in LABELS} for c in range(1, 7)}
    interval_w = {c: {label: 0 for label in LABELS} for c in range(1, 7)}
    course_n = {c: 0 for c in range(1, 7)}
    course_w = {c: 0 for c in range(1, 7)}

    for course, label, n, w in rows:
        c = live.valid_course(course)
        label = str(label)
        if c is None or label not in interval_n[c]:
            continue
        n = int(n or 0)
        w = int(w or 0)
        interval_n[c][label] = n
        interval_w[c][label] = w
        course_n[c] += n
        course_w[c] += w

    if sum(course_n.values()) <= 0:
        return None

    stats = {c: {} for c in range(1, 7)}
    for c in range(1, 7):
        cn = course_n[c]
        cw = course_w[c]
        for label in LABELS:
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


def load_sum_stats_fact_first(conn, race_code, target_date, place_code, feature_cols):
    stats = _load_sum_stats_from_fact(
        conn,
        race_code,
        target_date,
        place_code,
        feature_cols,
    )
    if stats is not None:
        return stats
    return exact.load_sum_stats_exact(
        conn,
        race_code,
        target_date,
        place_code,
        feature_cols,
    )


# exact.build_remapped_base_exact() は同一module内の
# load_venue_course_prior_exact を実行時に参照するため、ここをFact優先へ差し替える。
exact.load_venue_course_prior_exact = load_venue_course_prior_fact_first
live.load_sum_stats = load_sum_stats_fact_first


if __name__ == "__main__":
    raise SystemExit(live.main())
