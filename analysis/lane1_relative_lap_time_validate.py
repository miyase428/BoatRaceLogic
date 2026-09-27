#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""1号艇の周回タイム相対差（GOD／DEATH仮説）を検証する。

検証する仮説
------------
- DEATH: 1号艇の周回タイムが同一レース6艇平均より 0.10 秒以上遅い。
- GOD  : 1号艇の周回タイムが同一レース6艇平均より 0.10 秒以上速い。

ここでの成績は、公式決まり手の「逃げ」ではなく、まず再現性の高い
「1号艇が1着」を使う。公式の逃げ率とは同一ではないため、数値を直接比較しない。
展示進入変更の影響を分けるため、全1号艇と実進入1Cの両方を出力する。

Usage:
  python3 analysis/lane1_relative_lap_time_validate.py
  python3 analysis/lane1_relative_lap_time_validate.py --start-date 2025-01-01 --end-date 2026-09-25
"""

from __future__ import annotations

import argparse
import json
import math
import sys
from collections import defaultdict
from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "analysis"))

from slit_validate_v2 import connect_db  # noqa: E402


THRESHOLD = 0.10
SENSITIVITY_THRESHOLDS = tuple(round(step / 100.0, 2) for step in range(2, 82, 2))
DEFAULT_START = "2025-01-01"
DEFAULT_END = "2026-09-25"


def parse_args():
    parser = argparse.ArgumentParser()
    parser.add_argument("--start-date", default=DEFAULT_START)
    parser.add_argument("--end-date", default=DEFAULT_END)
    parser.add_argument(
        "--output",
        default=str(ROOT / "analysis/output/lane1_relative_lap_time_validate_2026.json"),
    )
    return parser.parse_args()


def fetch_rows(start_date: str, end_date: str):
    """1号艇と6艇周回が揃うレースを、1レース1行で読む。"""
    sql = """
        WITH boats AS (
            SELECT
                el.race_code,
                SUBSTRING(el.race_code, 9, 3) AS place_code,
                re.lane_number::integer AS lane,
                el.entry_course::integer AS entry_course,
                el.lap_time::double precision AS lap_time,
                (TRIM(rrd.rank) = '1') AS won,
                (TRIM(rrd.rank) = '1' AND TRIM(COALESCE(rrd.technique, '')) = '逃げ') AS escaped
            FROM boat_race.exhibition_live el
            JOIN boat_race.race_entry re
              ON re.race_code = el.race_code
             AND re.player_id = el.player_id
            LEFT JOIN boat_race.race_result_detail rrd
              ON rrd.race_code = el.race_code
             AND rrd.player_id = el.player_id
            WHERE SUBSTRING(el.race_code, 1, 8) BETWEEN REPLACE(%s::text, '-', '')
                                                       AND REPLACE(%s::text, '-', '')
              AND el.entry_course BETWEEN 1 AND 6
              AND re.lane_number BETWEEN 1 AND 6
              AND el.lap_time IS NOT NULL
              AND el.lap_time::double precision BETWEEN 20.0 AND 60.0
        )
        SELECT
            race_code,
            SUBSTRING(race_code, 1, 8) AS race_ymd,
            MAX(place_code) AS place_code,
            AVG(lap_time) AS avg_lap_time,
            MAX(lap_time) FILTER (WHERE lane = 1) AS lane1_lap_time,
            MAX(entry_course) FILTER (WHERE lane = 1) AS lane1_entry_course,
            BOOL_OR(won) FILTER (WHERE lane = 1) AS lane1_won,
            BOOL_OR(escaped) FILTER (WHERE lane = 1) AS lane1_escaped
        FROM boats
        GROUP BY race_code
        HAVING COUNT(*) = 6
           AND COUNT(DISTINCT lane) = 6
           AND COUNT(DISTINCT entry_course) = 6
           AND COUNT(*) FILTER (WHERE won) = 1
    """
    with connect_db() as conn:
        with conn.cursor() as cur:
            cur.execute(sql, (start_date, end_date))
            return cur.fetchall()


def build_records(rows):
    records = []
    for race_code, race_ymd, place, avg_lap, lane1_lap, entry_course, lane1_won, lane1_escaped in rows:
        if any(value is None for value in (avg_lap, lane1_lap, entry_course, lane1_won, lane1_escaped)):
            continue
        delta = float(lane1_lap) - float(avg_lap)  # plus means lane1 is slow.
        if not math.isfinite(delta):
            continue
        if delta >= THRESHOLD:
            zone = "death"
        elif delta <= -THRESHOLD:
            zone = "god"
        else:
            zone = "middle"
        records.append({
            "race_code": str(race_code),
            "year": str(race_ymd)[:4],
            "place": str(place),
            "entry_course": int(entry_course),
            "win": bool(lane1_won),
            "escape": bool(lane1_escaped),
            "delta_seconds": delta,
            "zone": zone,
        })
    return records


def summarize(records):
    n = len(records)
    wins = sum(row["win"] for row in records)
    escapes = sum(row["escape"] for row in records)
    return {
        "n": n,
        "wins": wins,
        "win_rate": wins / n if n else None,
        "escapes": escapes,
        "escape_rate": escapes / n if n else None,
        "mean_delta_seconds": sum(row["delta_seconds"] for row in records) / n if n else None,
    }


def zone_summary(records):
    return {zone: summarize([row for row in records if row["zone"] == zone]) for zone in ("death", "middle", "god")}


def add_lifts(summary):
    baseline = summary["baseline"]["win_rate"]
    summary["zones"] = {
        zone: {
            **values,
            "win_rate_lift_points": (values["win_rate"] - summary["baseline"]["win_rate"]) * 100.0 if values["win_rate"] is not None else None,
            "escape_rate_lift_points": (values["escape_rate"] - summary["baseline"]["escape_rate"]) * 100.0 if values["escape_rate"] is not None else None,
        }
        for zone, values in summary["zones"].items()
    }
    return summary


def make_summary(records):
    return add_lifts({"baseline": summarize(records), "zones": zone_summary(records)})


def threshold_curve(records, direction: str):
    """閾値ごとに片側条件を集計する。2025探索→2026確認に使う。"""
    if direction not in {"death", "god"}:
        raise ValueError(f"unknown direction: {direction}")

    baseline = summarize(records)
    periods = {
        "all": records,
        "2025_dev": [row for row in records if row["year"] == "2025"],
        "2026_validate": [row for row in records if row["year"] == "2026"],
    }
    curve = []
    for threshold in SENSITIVITY_THRESHOLDS:
        values = {"threshold_seconds": threshold}
        for label, period_rows in periods.items():
            if direction == "death":
                selected = [row for row in period_rows if row["delta_seconds"] >= threshold]
            else:
                selected = [row for row in period_rows if row["delta_seconds"] <= -threshold]
            selected_summary = summarize(selected)
            period_baseline = summarize(period_rows)
            values[label] = {
                **selected_summary,
                "win_rate_lift_points": (
                    (selected_summary["win_rate"] - period_baseline["win_rate"]) * 100.0
                    if selected_summary["win_rate"] is not None else None
                ),
                "escape_rate_lift_points": (
                    (selected_summary["escape_rate"] - period_baseline["escape_rate"]) * 100.0
                    if selected_summary["escape_rate"] is not None else None
                ),
            }
        curve.append(values)
    return curve


def main():
    args = parse_args()
    records = build_records(fetch_rows(args.start_date, args.end_date))
    if not records:
        raise RuntimeError("検証できる6艇立てレースがありません")

    by_year = defaultdict(list)
    by_place = defaultdict(list)
    for row in records:
        by_year[row["year"]].append(row)
        by_place[row["place"]].append(row)

    result = {
        "definition": {
            "target": "1号艇（枠番1）",
            "relative_value": "1号艇の周回タイム - 同一レース6艇平均",
            "death": f"+{THRESHOLD:.2f}秒以上（1号艇が平均より遅い）",
            "god": f"-{THRESHOLD:.2f}秒以下（1号艇が平均より速い）",
            "outcome": "1号艇1着率と、1号艇1着かつ公式決まり手が『逃げ』の逃げ率を併記。",
        },
        "period": {"start": args.start_date, "end": args.end_date},
        "valid_races": len(records),
        "all_lane1": make_summary(records),
        "entry_1c_only": make_summary([row for row in records if row["entry_course"] == 1]),
        "by_year": {year: make_summary(rows) for year, rows in sorted(by_year.items())},
        "by_place": {place: make_summary(rows) for place, rows in sorted(by_place.items())},
        "threshold_sensitivity": {
            "note": "2025年を探索、2026年を独立した確認期間として扱う。",
            "death": threshold_curve(records, "death"),
            "god": threshold_curve(records, "god"),
        },
        "toda_threshold_sensitivity": {
            "note": "元仮説の戸田だけを同じ定義で再確認する。サンプルが小さいため全場結果より優先しない。",
            "death": threshold_curve(by_place.get("TDA", []), "death"),
            "god": threshold_curve(by_place.get("TDA", []), "god"),
        },
    }

    out = Path(args.output)
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding="utf-8")

    def rate(value):
        return "-" if value is None else f"{value * 100:.2f}%"

    print("1号艇 周回タイム相対差（GOD／DEATH仮説）検証")
    print(f"期間: {args.start_date} ～ {args.end_date} / 有効{len(records):,}R")
    for label, summary in (("全1号艇", result["all_lane1"]), ("実進入1C", result["entry_1c_only"])):
        print(
            f"\n{label}: 1着 {rate(summary['baseline']['win_rate'])}"
            f" / 逃げ {rate(summary['baseline']['escape_rate'])}"
            f" (N={summary['baseline']['n']:,})"
        )
        for zone in ("death", "middle", "god"):
            item = summary["zones"][zone]
            print(
                f"  {zone:6} 1着 {rate(item['win_rate'])} ({item['win_rate_lift_points']:+.2f}pt)"
                f" / 逃げ {rate(item['escape_rate'])} ({item['escape_rate_lift_points']:+.2f}pt)"
                f" / N={item['n']:,}"
            )
    toda = result["by_place"].get("TDA")
    if toda:
        print(
            f"\n戸田（TDA）: 1着 {rate(toda['baseline']['win_rate'])}"
            f" / 逃げ {rate(toda['baseline']['escape_rate'])}"
            f" (N={toda['baseline']['n']:,})"
        )
        for zone in ("death", "middle", "god"):
            item = toda["zones"][zone]
            print(
                f"  {zone:6} 1着 {rate(item['win_rate'])} ({item['win_rate_lift_points']:+.2f}pt)"
                f" / 逃げ {rate(item['escape_rate'])} ({item['escape_rate_lift_points']:+.2f}pt)"
                f" / N={item['n']:,}"
            )
    print("\n閾値感度（逃げ率の基準差、2025探索 / 2026確認）")
    for direction in ("death", "god"):
        print(f"  {direction}:")
        for item in result["threshold_sensitivity"][direction]:
            threshold = item["threshold_seconds"]
            if threshold not in {0.06, 0.10, 0.14, 0.20, 0.30, 0.40, 0.60, 0.80}:
                continue
            dev = item["2025_dev"]
            valid = item["2026_validate"]
            print(
                f"    {threshold:.2f}s: 2025 {dev['escape_rate_lift_points']:+.2f}pt (N={dev['n']:,})"
                f" / 2026 {valid['escape_rate_lift_points']:+.2f}pt (N={valid['n']:,})"
            )
    print("\n戸田の閾値感度（逃げ率の基準差、全期間）")
    for direction in ("death", "god"):
        print(f"  {direction}:")
        for item in result["toda_threshold_sensitivity"][direction]:
            threshold = item["threshold_seconds"]
            if threshold not in {0.06, 0.10, 0.14, 0.20, 0.30, 0.40, 0.60, 0.80}:
                continue
            total = item["all"]
            print(f"    {threshold:.2f}s: {total['escape_rate_lift_points']:+.2f}pt (N={total['n']:,})")
    print(f"\nJSON: {out}")


if __name__ == "__main__":
    main()
