#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""engine_specsの事前情報から、場別モーター更新日候補を監査する。

新モーター初回開催では、出走表に掲載されるモーター2連対率が多数の
モーターで一斉に0へ戻る。このスクリプトはその日をDBから抽出し、
連続する初回開催日を1つの更新イベントへまとめる。
"""

from __future__ import annotations

import argparse
import csv
import json
import sys
from dataclasses import dataclass
from datetime import date, timedelta
from pathlib import Path

import psycopg2

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "common"))

from db_config import load_db_config  # noqa: E402


DAILY_SQL = """
SELECT
    es.stadium_code,
    sm.stadium_name,
    es.race_date,
    COUNT(DISTINCT es.motor_number) AS motor_count,
    AVG((es.motor_exacta_rate = 0)::int)::float8 AS zero_share,
    AVG(es.motor_exacta_rate)::float8 AS mean_rate,
    MIN(es.motor_exacta_rate)::float8 AS min_rate,
    MAX(es.motor_exacta_rate)::float8 AS max_rate
FROM boat_race.engine_specs es
LEFT JOIN boat_race.stadium_master sm
  ON sm.stadium_code = es.stadium_code
WHERE es.race_date >= %(start_date)s::date
  AND es.race_date < %(end_date)s::date
  AND es.motor_exacta_rate IS NOT NULL
GROUP BY es.stadium_code, sm.stadium_name, es.race_date
ORDER BY es.stadium_code, es.race_date
"""


@dataclass(frozen=True)
class DailyMotorSummary:
    stadium_code: str
    stadium_name: str
    race_date: date
    motor_count: int
    zero_share: float
    mean_rate: float
    min_rate: float
    max_rate: float


def load_daily(start_date: str, end_date: str) -> list[DailyMotorSummary]:
    with psycopg2.connect(**load_db_config()) as conn:
        with conn.cursor() as cursor:
            cursor.execute(
                DAILY_SQL,
                {"start_date": start_date, "end_date": end_date},
            )
            return [DailyMotorSummary(*row) for row in cursor.fetchall()]


def detect_events(
    daily: list[DailyMotorSummary],
    min_motors: int,
    zero_share_threshold: float,
    max_episode_gap_days: int,
) -> list[dict]:
    by_stadium: dict[str, list[DailyMotorSummary]] = {}
    for row in daily:
        by_stadium.setdefault(row.stadium_code, []).append(row)

    events: list[dict] = []
    for stadium_code, rows in sorted(by_stadium.items()):
        candidates = [
            row
            for row in rows
            if row.motor_count >= min_motors
            and row.zero_share >= zero_share_threshold
        ]
        episodes: list[list[DailyMotorSummary]] = []
        for row in candidates:
            if (
                not episodes
                or (row.race_date - episodes[-1][-1].race_date).days
                > max_episode_gap_days
            ):
                episodes.append([row])
            else:
                episodes[-1].append(row)

        row_positions = {row.race_date: index for index, row in enumerate(rows)}
        for episode in episodes:
            first = episode[0]
            position = row_positions[first.race_date]
            previous = rows[position - 1] if position else None
            events.append(
                {
                    "stadium_code": stadium_code,
                    "stadium_name": first.stadium_name,
                    "replacement_first_race_date": first.race_date.isoformat(),
                    "initial_series_last_date": episode[-1].race_date.isoformat(),
                    "initial_series_race_days": len(episode),
                    "first_day_motor_count": first.motor_count,
                    "first_day_zero_share": first.zero_share,
                    "first_day_mean_rate": first.mean_rate,
                    "previous_race_date": (
                        previous.race_date.isoformat() if previous else None
                    ),
                    "previous_day_motor_count": (
                        previous.motor_count if previous else None
                    ),
                    "previous_day_mean_rate": (
                        previous.mean_rate if previous else None
                    ),
                }
            )
    return events


def write_csv(path: Path, events: list[dict]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    if not events:
        path.write_text("", encoding="utf-8")
        return
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=list(events[0]))
        writer.writeheader()
        writer.writerows(events)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--start", default="2023-01-01")
    parser.add_argument(
        "--end-exclusive",
        default=(date.today() + timedelta(days=1)).isoformat(),
    )
    parser.add_argument("--min-motors", type=int, default=25)
    parser.add_argument("--zero-share-threshold", type=float, default=0.90)
    parser.add_argument("--max-episode-gap-days", type=int, default=3)
    parser.add_argument(
        "--output-prefix",
        type=Path,
        default=ROOT / "analysis" / "output" / "motor_replacement_dates",
    )
    args = parser.parse_args()

    daily = load_daily(args.start, args.end_exclusive)
    events = detect_events(
        daily,
        min_motors=args.min_motors,
        zero_share_threshold=args.zero_share_threshold,
        max_episode_gap_days=args.max_episode_gap_days,
    )
    report = {
        "configuration": {
            "start": args.start,
            "end_exclusive": args.end_exclusive,
            "min_motors": args.min_motors,
            "zero_share_threshold": args.zero_share_threshold,
            "max_episode_gap_days": args.max_episode_gap_days,
            "source": "boat_race.engine_specs (pre-race motor_exacta_rate)",
            "interpretation": (
                "A venue-wide reset candidate is the first race date of an episode "
                "where at least min_motors have a zero share at or above the threshold."
            ),
        },
        "daily_summary_rows": len(daily),
        "events": events,
    }
    json_path = args.output_prefix.with_suffix(".json")
    csv_path = args.output_prefix.with_suffix(".csv")
    json_path.parent.mkdir(parents=True, exist_ok=True)
    json_path.write_text(
        json.dumps(report, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )
    write_csv(csv_path, events)

    print("場   場名       更新後の初走日  直前の開催日   初日台数  0率")
    print("-" * 72)
    for event in events:
        print(
            f"{event['stadium_code']:<4} {event['stadium_name']:<8} "
            f"{event['replacement_first_race_date']}  "
            f"{str(event['previous_race_date']):<12}  "
            f"{event['first_day_motor_count']:>3}      "
            f"{event['first_day_zero_share'] * 100:>5.1f}%"
        )
    print(f"JSON: {json_path}")
    print(f"CSV : {csv_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
