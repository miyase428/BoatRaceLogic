#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""Web本番用の通常22場向け SUM Fact 高速化ラッパー。

corrected_winrate_live_exact.py の検証済み定義はそのまま利用し、
SUM統計の履歴集計だけ boat_race.sum_history_fact を優先して参照する。

安全策:
- Factテーブル/対象場/feature_signature が無ければ従来exactへフォールバック
- Fact最終日より後に race_history_fact 上の対象場レースが存在する場合も
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

LABELS = [
    "-0.6未満", "-0.6--0.4", "-0.4--0.2", "-0.2-0.0",
    "0.0-0.2", "0.2-0.4", "0.4-0.6", "0.6以上",
]


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


live.load_sum_stats = load_sum_stats_fact_first


if __name__ == "__main__":
    raise SystemExit(live.main())
