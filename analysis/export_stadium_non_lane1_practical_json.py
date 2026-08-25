#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
場別「1コースが勝てなかった時」の実戦表示用JSONを生成する。

対象:
- 1C敗戦率
- 1C敗戦時の勝ちコース分布（2C〜6C）
- 1C敗戦時の勝者決まり手
- 1C敗戦時の1C残り（2着 / 3着 / 2・3着合計 / 圏外）

Usage:
  python3 analysis/export_stadium_non_lane1_practical_json.py \
    analysis/output/kimarite_analysis_dataset_20250815_20260814.csv

出力:
  config/stadium_non_lane1_practical.local.json

表示専用。本番PredictionLogicには接続しない。
"""

from __future__ import annotations

import csv
import json
import re
import sys
from collections import Counter, defaultdict
from pathlib import Path

WINNER_COURSES = (2, 3, 4, 5, 6)
TECH_KEYS = ("差し", "まくり", "まくり差し", "抜き", "恵まれ")


def to_int(value, default=0):
    try:
        return int(float(value))
    except (TypeError, ValueError):
        return default


def pct(n: int, d: int) -> float:
    return round(100.0 * n / d, 2) if d else 0.0


def is_formal(row: dict[str, str]) -> bool:
    return (
        to_int(row.get("result_top3_course_complete")) == 1
        and to_int(row.get("result_boat_match")) == 1
    )


def norm_tech(value: str) -> str:
    v = (value or "").strip()
    return v if v in TECH_KEYS else "その他"


def date_range_from_filename(path: Path) -> tuple[str, str]:
    m = re.search(r"_(\d{8})_(\d{8})\.csv$", path.name)
    if not m:
        return "", ""

    def fmt(v: str) -> str:
        return f"{v[0:4]}-{v[4:6]}-{v[6:8]}"

    return fmt(m.group(1)), fmt(m.group(2))


def empty_stat() -> dict:
    return {
        "n": 0,
        "non1": 0,
        "winner_course": Counter(),
        "tech": Counter(),
        "lane1_second": 0,
        "lane1_third": 0,
        "lane1_top3": 0,
    }


def add_row(stat: dict, row: dict[str, str]) -> None:
    stat["n"] += 1
    winner = to_int(row.get("actual_1st_course"))
    if winner == 1:
        return

    stat["non1"] += 1
    if winner in WINNER_COURSES:
        stat["winner_course"][winner] += 1

    stat["tech"][norm_tech(row.get("winner_technique") or "")] += 1

    second = to_int(row.get("actual_2nd_course"))
    third = to_int(row.get("actual_3rd_course"))
    if second == 1:
        stat["lane1_second"] += 1
    if third == 1:
        stat["lane1_third"] += 1
    if second == 1 or third == 1:
        stat["lane1_top3"] += 1


def summarize(stat: dict) -> dict:
    n = int(stat["n"])
    non1 = int(stat["non1"])
    lane1_top3 = int(stat["lane1_top3"])

    winner_course = {
        str(c): {
            "count": int(stat["winner_course"][c]),
            "rate": pct(int(stat["winner_course"][c]), non1),
        }
        for c in WINNER_COURSES
    }

    tech = {
        key: {
            "count": int(stat["tech"][key]),
            "rate": pct(int(stat["tech"][key]), non1),
        }
        for key in (*TECH_KEYS, "その他")
    }

    ranking = sorted(
        (
            {
                "course": c,
                "count": int(stat["winner_course"][c]),
                "rate": pct(int(stat["winner_course"][c]), non1),
            }
            for c in WINNER_COURSES
        ),
        key=lambda row: (-row["rate"], row["course"]),
    )

    return {
        "n": n,
        "non1_count": non1,
        "non1_rate": pct(non1, n),
        "winner_course": winner_course,
        "winner_course_ranking": ranking,
        "technique": tech,
        "lane1_remain": {
            "second_count": int(stat["lane1_second"]),
            "second_rate": pct(int(stat["lane1_second"]), non1),
            "third_count": int(stat["lane1_third"]),
            "third_rate": pct(int(stat["lane1_third"]), non1),
            "top3_count": lane1_top3,
            "top3_rate": pct(lane1_top3, non1),
            "out_count": max(0, non1 - lane1_top3),
            "out_rate": pct(max(0, non1 - lane1_top3), non1),
        },
    }


def add_vs_all(venue: dict, overall: dict) -> None:
    venue_courses = venue.get("winner_course") or {}
    all_courses = overall.get("winner_course") or {}
    for c in WINNER_COURSES:
        key = str(c)
        row = venue_courses.get(key)
        base = all_courses.get(key)
        if isinstance(row, dict) and isinstance(base, dict):
            row["vs_all"] = round(
                float(row.get("rate", 0.0)) - float(base.get("rate", 0.0)),
                2,
            )


def main() -> None:
    if len(sys.argv) != 2:
        print(
            f"使用方法: python3 {sys.argv[0]} KIMARITE_DATASET_CSV",
            file=sys.stderr,
        )
        sys.exit(1)

    source_path = Path(sys.argv[1]).resolve()
    if not source_path.is_file():
        raise RuntimeError(f"CSVがありません: {source_path}")

    repo_root = Path(__file__).resolve().parent.parent
    affinity_path = repo_root / "config" / "stadium_affinity.json"
    if not affinity_path.is_file():
        raise RuntimeError(f"場コード対応JSONがありません: {affinity_path}")

    with affinity_path.open("r", encoding="utf-8") as f:
        affinity = json.load(f)

    name_to_code = {
        str(v.get("name", "")).strip(): str(code)
        for code, v in (affinity.get("stadiums") or {}).items()
        if isinstance(v, dict) and str(v.get("name", "")).strip()
    }

    with source_path.open("r", encoding="utf-8-sig", newline="") as f:
        rows = [row for row in csv.DictReader(f) if is_formal(row)]

    if not rows:
        raise RuntimeError("正式対象が0件です。")

    overall_stat = empty_stat()
    venues = defaultdict(empty_stat)
    for row in rows:
        name = (row.get("stadium_name") or "").strip()
        if not name:
            continue
        add_row(overall_stat, row)
        add_row(venues[name], row)

    overall = summarize(overall_stat)
    stadiums = {}
    for name, stat in sorted(venues.items()):
        code = name_to_code.get(name)
        if not code:
            continue
        row = summarize(stat)
        row["name"] = name
        row["non1_vs_all_diff"] = round(
            float(row["non1_rate"]) - float(overall["non1_rate"]),
            2,
        )
        add_vs_all(row, overall)
        stadiums[code] = row

    start_date, end_date = date_range_from_filename(source_path)
    output = {
        "meta": {
            "label": "過去1年",
            "start_date": start_date,
            "end_date": end_date,
            "source": source_path.name,
            "generated_from": "export_stadium_non_lane1_practical_json.py",
            "formal_races": len(rows),
            "stadium_count": len(stadiums),
            "overall_non1_rate": overall["non1_rate"],
            "overall_winner_course": overall["winner_course"],
            "note": "表示専用。PredictionLogic補正には未接続。",
        },
        "stadiums": stadiums,
    }

    output_path = repo_root / "config" / "stadium_non_lane1_practical.local.json"
    with output_path.open("w", encoding="utf-8") as f:
        json.dump(output, f, ensure_ascii=False, indent=2)
        f.write("\n")

    print("=" * 60)
    print("イン敗戦時 場特性JSON出力完了")
    print("=" * 60)
    print(f"正式対象   : {len(rows)}R")
    print(f"場数       : {len(stadiums)}")
    print(f"全場1C敗戦 : {overall['non1_rate']:.2f}%")
    print("内容       : 頭2C〜6C / 決まり手 / 1C残り")
    print(f"出力       : {output_path}")


if __name__ == "__main__":
    main()
