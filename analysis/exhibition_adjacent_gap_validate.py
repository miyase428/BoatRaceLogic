#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""隣接進入コース間の展示タイム差と着順成績を検証する。"""

from __future__ import annotations

import argparse
import json
import math
import sys
from collections import Counter, defaultdict
from datetime import date
from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "analysis"))

from slit_validate_v2 import connect_db  # noqa: E402


DEFAULT_START = "2025-01-01"
DEFAULT_END = "2026-09-25"
THRESHOLDS = (0.05, 0.10, 0.15, 0.20)
EPSILON = 1.0e-9


def parse_args():
    parser = argparse.ArgumentParser()
    parser.add_argument("--start-date", default=DEFAULT_START)
    parser.add_argument("--end-date", default=DEFAULT_END)
    parser.add_argument(
        "--output",
        default=str(ROOT / "analysis/output/exhibition_adjacent_gap_validate_2026.json"),
    )
    return parser.parse_args()


def fetch_rows(start_date: str, end_date: str):
    sql = """
        WITH boats AS (
            SELECT
                el.race_code,
                rm.race_date,
                SUBSTRING(el.race_code, 9, 3) AS place_code,
                el.entry_course::integer AS course,
                el.exhibition_time::double precision AS exhibition_time,
                CASE
                    WHEN rrd.rank ~ '^[1-6]$' THEN rrd.rank::integer
                    ELSE NULL
                END AS finish_rank
            FROM boat_race.exhibition_live el
            JOIN boat_race.race_master rm
              ON rm.race_code = el.race_code
            LEFT JOIN boat_race.race_result_detail rrd
              ON rrd.race_code = el.race_code
             AND rrd.player_id = el.player_id
            WHERE rm.race_date BETWEEN %s::date AND %s::date
              AND el.entry_course BETWEEN 1 AND 6
              AND el.exhibition_time IS NOT NULL
              AND el.exhibition_time::double precision BETWEEN 5.0 AND 9.0
        ), valid_races AS (
            SELECT race_code
            FROM boats
            GROUP BY race_code
            HAVING COUNT(*) = 6
               AND COUNT(DISTINCT course) = 6
               AND COUNT(*) FILTER (WHERE finish_rank = 1) = 1
               AND COUNT(*) FILTER (WHERE finish_rank = 2) = 1
               AND COUNT(*) FILTER (WHERE finish_rank = 3) = 1
        )
        SELECT
            b.race_code, b.race_date, b.place_code, b.course,
            b.exhibition_time, b.finish_rank
        FROM boats b
        JOIN valid_races v USING (race_code)
        ORDER BY b.race_code, b.course
    """
    with connect_db() as conn:
        with conn.cursor() as cursor:
            cursor.execute(sql, (start_date, end_date))
            return cursor.fetchall()


def build_races(rows):
    races = {}
    for race_code, race_date, place_code, course, exhibition_time, finish_rank in rows:
        race = races.setdefault(
            str(race_code),
            {"date": race_date, "place": str(place_code), "boats": {}},
        )
        race["boats"][int(course)] = {
            "time": float(exhibition_time),
            "rank": int(finish_rank) if finish_rank is not None else 99,
        }
    return {
        code: race
        for code, race in races.items()
        if set(race["boats"]) == set(range(1, 7))
    }


def rates(records):
    n = len(records)
    if n == 0:
        return {"n": 0, "win_rate": None, "top2_rate": None, "top3_rate": None}
    return {
        "n": n,
        "win_rate": sum(row["rank"] == 1 for row in records) / n,
        "top2_rate": sum(row["rank"] <= 2 for row in records) / n,
        "top3_rate": sum(row["rank"] <= 3 for row in records) / n,
    }


def wilson_interval(successes: int, total: int, z: float = 1.959963984540054):
    if total <= 0:
        return None
    p = successes / total
    denominator = 1.0 + z * z / total
    center = (p + z * z / (2.0 * total)) / denominator
    margin = z * math.sqrt((p * (1.0 - p) + z * z / (4.0 * total)) / total) / denominator
    return [center - margin, center + margin]


def rates_with_ci(records):
    result = rates(records)
    n = result["n"]
    if not n:
        return result
    for key, cutoff in (("win", 1), ("top2", 2), ("top3", 3)):
        successes = sum(row["rank"] <= cutoff for row in records)
        result[f"{key}_count"] = successes
        result[f"{key}_95ci"] = wilson_interval(successes, n)
    return result


def weighted_course_baseline(records, course_baseline):
    counts = Counter(row["course"] for row in records)
    total = sum(counts.values())
    result = {"n": total, "course_counts": dict(sorted(counts.items()))}
    for metric in ("win_rate", "top2_rate", "top3_rate"):
        result[metric] = sum(
            count * course_baseline[course][metric]
            for course, count in counts.items()
        ) / max(total, 1)
    return result


def summarize(records, course_baseline):
    actual = rates_with_ci(records)
    expected = weighted_course_baseline(records, course_baseline)
    return {
        "actual": actual,
        "course_weighted_baseline": expected,
        "lift_percentage_points": {
            metric: (actual[metric] - expected[metric]) * 100.0
            for metric in ("win_rate", "top2_rate", "top3_rate")
        },
    }


def pair_events(races, threshold):
    events = []
    for race_code, race in races.items():
        boats = race["boats"]
        for inner_course in range(1, 6):
            outer_course = inner_course + 1
            inner = boats[inner_course]
            outer = boats[outer_course]
            absolute_gap = abs(inner["time"] - outer["time"])
            if absolute_gap + EPSILON < threshold:
                continue
            if inner["time"] < outer["time"]:
                faster_course, slower_course, direction = inner_course, outer_course, "inner_faster"
            elif outer["time"] < inner["time"]:
                faster_course, slower_course, direction = outer_course, inner_course, "outer_faster"
            else:
                continue
            events.append({
                "race_code": race_code,
                "date": race["date"],
                "year": race["date"].year,
                "place": race["place"],
                "pair": f"{inner_course}-{outer_course}",
                "direction": direction,
                "pair_direction": f"{inner_course}-{outer_course}:{direction}",
                "course": faster_course,
                "rank": boats[faster_course]["rank"],
                "slower_course": slower_course,
                "slower_rank": boats[slower_course]["rank"],
                "gap": absolute_gap,
            })
    return events


def deduplicate_alerted_boats(events):
    unique = {}
    for event in events:
        key = (event["race_code"], event["course"])
        current = unique.get(key)
        if current is None or event["gap"] > current["gap"]:
            unique[key] = event
    return list(unique.values())


def opposing_boats(events):
    """比較相手側を、通常のcourse/rank形式へ変換する。"""
    return [
        {
            **event,
            "course": event["slower_course"],
            "rank": event["slower_rank"],
        }
        for event in events
    ]


def group_summary(records, key, course_baseline, minimum=1):
    grouped = defaultdict(list)
    for row in records:
        grouped[str(row[key])].append(row)
    return {
        name: summarize(group, course_baseline)
        for name, group in sorted(grouped.items())
        if len(group) >= minimum
    }


def main():
    args = parse_args()
    rows = fetch_rows(args.start_date, args.end_date)
    races = build_races(rows)
    if not races:
        raise RuntimeError("検証できる6艇立てレースがありません")

    all_boats = []
    for race_code, race in races.items():
        for course, boat in race["boats"].items():
            all_boats.append({
                "race_code": race_code,
                "course": course,
                "rank": boat["rank"],
            })
    course_baseline = {
        course: rates([row for row in all_boats if row["course"] == course])
        for course in range(1, 7)
    }

    threshold_results = {}
    selected_events = None
    for threshold in THRESHOLDS:
        events = pair_events(races, threshold)
        alerted_boats = deduplicate_alerted_boats(events)
        threshold_results[f"{threshold:.2f}"] = {
            "adjacent_pair_events": len(events),
            "unique_alerted_boats": len(alerted_boats),
            "races_with_alert": len({row["race_code"] for row in events}),
            "alerted_boat_result": summarize(alerted_boats, course_baseline),
            "faster_finished_ahead_of_slower_rate": (
                sum(row["rank"] < row["slower_rank"] for row in events) / len(events)
                if events else None
            ),
        }
        if math.isclose(threshold, 0.10):
            selected_events = events

    if selected_events is None:
        raise RuntimeError("0.10秒差の検証結果を生成できません")
    selected_alerted = deduplicate_alerted_boats(selected_events)

    direction_summary = group_summary(selected_events, "direction", course_baseline)
    for direction, rows_for_direction in (
        (name, [row for row in selected_events if row["direction"] == name])
        for name in ("inner_faster", "outer_faster")
    ):
        direction_summary[direction]["faster_finished_ahead_of_slower_rate"] = (
            sum(row["rank"] < row["slower_rank"] for row in rows_for_direction)
            / len(rows_for_direction)
        )
        direction_summary[direction]["slower_neighbor_result"] = summarize(
            opposing_boats(rows_for_direction), course_baseline
        )

    outer_faster_events = [
        row for row in selected_events if row["direction"] == "outer_faster"
    ]
    inner_faster_events = [
        row for row in selected_events if row["direction"] == "inner_faster"
    ]

    result = {
        "analysis": "exhibition_adjacent_gap_validate",
        "definition": {
            "adjacent": "展示進入コース順の1-2、2-3、3-4、4-5、5-6",
            "faster": "展示タイムが小さい艇",
            "primary_threshold_seconds": 0.10,
            "baseline": "同期間・同じ進入コースの全レース成績を、発生コース構成で加重",
            "pair_event_note": "同じ艇が左右両方に0.10秒以上速い場合はpair eventでは2件、unique alerted boatでは1艇",
        },
        "period": {
            "start": args.start_date,
            "end": args.end_date,
            "valid_races": len(races),
            "valid_boats": len(all_boats),
        },
        "course_baseline": course_baseline,
        "threshold_comparison": threshold_results,
        "primary_0_10": {
            "all_unique_alerted_boats": summarize(selected_alerted, course_baseline),
            "direction_pair_events": direction_summary,
            "when_outer_faster_inner_neighbor": {
                "all": summarize(opposing_boats(outer_faster_events), course_baseline),
                "by_pair": group_summary(
                    opposing_boats(outer_faster_events), "pair", course_baseline
                ),
            },
            "when_inner_faster_outer_neighbor": {
                "all": summarize(opposing_boats(inner_faster_events), course_baseline),
                "by_pair": group_summary(
                    opposing_boats(inner_faster_events), "pair", course_baseline
                ),
            },
            "course_pair_events": group_summary(selected_events, "pair", course_baseline),
            "course_pair_by_direction_events": group_summary(
                selected_events, "pair_direction", course_baseline
            ),
            "faster_course_pair_events": group_summary(selected_events, "course", course_baseline),
            "year_unique_alerted_boats": group_summary(selected_alerted, "year", course_baseline),
            "venue_unique_alerted_boats_n_ge_150": group_summary(
                selected_alerted, "place", course_baseline, minimum=150
            ),
        },
    }

    output = Path(args.output)
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(result, ensure_ascii=False, indent=2, default=str), encoding="utf-8")

    primary = result["primary_0_10"]["all_unique_alerted_boats"]
    actual = primary["actual"]
    lift = primary["lift_percentage_points"]
    print(
        f"valid races={len(races):,} / 0.10s alerted boats={actual['n']:,} / "
        f"win={actual['win_rate'] * 100:.2f}% ({lift['win_rate']:+.2f}pt) / "
        f"top2={actual['top2_rate'] * 100:.2f}% ({lift['top2_rate']:+.2f}pt) / "
        f"top3={actual['top3_rate'] * 100:.2f}% ({lift['top3_rate']:+.2f}pt)"
    )
    print(f"保存: {output}")


if __name__ == "__main__":
    main()
