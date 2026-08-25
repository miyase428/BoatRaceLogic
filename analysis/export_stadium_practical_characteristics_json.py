#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
場特性の実戦表示用JSONを生成する。

対象:
- 場×コース別 1着率 / 2連対率 / 3連対率（全場平均との差つき）
- 1C勝率 / 1逃げ率
- 決まり手傾向
- 1逃げ時の2着・3着コース分布
- 1逃げ時の出目TOP5

Usage:
  python3 analysis/export_stadium_practical_characteristics_json.py \
    analysis/output/kimarite_analysis_dataset_20250815_20260814.csv

出力:
  config/stadium_practical_characteristics.local.json

local.json はGit管理外。画面表示専用で、本番PredictionLogicは変更しない。
"""

from __future__ import annotations

import csv
import json
import re
import sys
from collections import Counter, defaultdict
from pathlib import Path

TECHNIQUES = ("逃げ", "差し", "まくり", "まくり差し")
NON_ESCAPE_TECHNIQUES = ("差し", "まくり", "まくり差し")


def as_int(row: dict[str, str], key: str) -> int:
    try:
        return int((row.get(key) or "0").strip() or 0)
    except (TypeError, ValueError):
        return 0


def pct(n: int, d: int) -> float:
    return round((100.0 * n / d), 2) if d > 0 else 0.0


def formal(row: dict[str, str]) -> bool:
    return (
        as_int(row, "result_top3_course_complete") == 1
        and as_int(row, "result_boat_match") == 1
    )


def date_range_from_filename(path: Path) -> tuple[str, str]:
    m = re.search(r"_(\d{8})_(\d{8})\.csv$", path.name)
    if not m:
        return "", ""

    def fmt(v: str) -> str:
        return f"{v[0:4]}-{v[4:6]}-{v[6:8]}"

    return fmt(m.group(1)), fmt(m.group(2))


def summarize(rows: list[dict[str, str]]) -> dict:
    technique = Counter()
    lane1_win = 0
    escape_n = 0
    second = Counter()
    third = Counter()
    patterns = Counter()
    course_first = Counter()
    course_second = Counter()
    course_third = Counter()

    for row in rows:
        tech = (row.get("winner_technique") or "").strip()
        if tech:
            technique[tech] += 1

        c1 = as_int(row, "actual_1st_course")
        c2 = as_int(row, "actual_2nd_course")
        c3 = as_int(row, "actual_3rd_course")

        if 1 <= c1 <= 6:
            course_first[c1] += 1
        if 1 <= c2 <= 6:
            course_second[c2] += 1
        if 1 <= c3 <= 6:
            course_third[c3] += 1

        if c1 == 1:
            lane1_win += 1

        if c1 == 1 and tech == "逃げ":
            escape_n += 1
            if 2 <= c2 <= 6:
                second[c2] += 1
            if 2 <= c3 <= 6:
                third[c3] += 1
            if 2 <= c2 <= 6 and 2 <= c3 <= 6 and c2 != c3:
                patterns[f"1-{c2}-{c3}"] += 1

    n = len(rows)
    known_tech = sum(technique.values())
    technique_rates = {t: pct(technique[t], known_tech) for t in TECHNIQUES}
    non_escape_top = max(
        NON_ESCAPE_TECHNIQUES,
        key=lambda t: (technique_rates.get(t, 0.0), -NON_ESCAPE_TECHNIQUES.index(t)),
    )

    course_results = {}
    for course in range(1, 7):
        first_n = course_first[course]
        second_n = course_second[course]
        third_n = course_third[course]
        top2_n = first_n + second_n
        top3_n = top2_n + third_n
        course_results[str(course)] = {
            "first_count": first_n,
            "first_rate": pct(first_n, n),
            "top2_count": top2_n,
            "top2_rate": pct(top2_n, n),
            "top3_count": top3_n,
            "top3_rate": pct(top3_n, n),
        }

    return {
        "n": n,
        "lane1_win_count": lane1_win,
        "lane1_win_rate": pct(lane1_win, n),
        "escape_count": escape_n,
        "escape_rate": pct(escape_n, n),
        "course_results": course_results,
        "technique_known_n": known_tech,
        "technique_rates": technique_rates,
        "non_escape_top": {
            "name": non_escape_top,
            "rate": technique_rates.get(non_escape_top, 0.0),
        },
        "escape_second": {
            str(c): {"count": second[c], "rate": pct(second[c], escape_n)}
            for c in range(2, 7)
        },
        "escape_third": {
            str(c): {"count": third[c], "rate": pct(third[c], escape_n)}
            for c in range(2, 7)
        },
        "escape_patterns": [
            {"pattern": pattern, "count": count, "rate": pct(count, escape_n)}
            for pattern, count in patterns.most_common(5)
        ],
    }


def add_course_vs_all(stat: dict, overall: dict) -> None:
    venue_courses = stat.get("course_results") or {}
    all_courses = overall.get("course_results") or {}

    for course in range(1, 7):
        key = str(course)
        venue = venue_courses.get(key)
        base = all_courses.get(key)
        if not isinstance(venue, dict) or not isinstance(base, dict):
            continue

        venue["vs_all"] = {
            "first": round(
                float(venue.get("first_rate", 0.0))
                - float(base.get("first_rate", 0.0)),
                2,
            ),
            "top2": round(
                float(venue.get("top2_rate", 0.0))
                - float(base.get("top2_rate", 0.0)),
                2,
            ),
            "top3": round(
                float(venue.get("top3_rate", 0.0))
                - float(base.get("top3_rate", 0.0)),
                2,
            ),
        }


def strength_label(diff: float) -> str:
    if diff >= 5.0:
        return "強い"
    if diff >= 2.0:
        return "やや強い"
    if diff > -2.0:
        return "標準"
    if diff > -5.0:
        return "やや弱い"
    return "弱い"


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
        rows = [row for row in csv.DictReader(f) if formal(row)]

    if not rows:
        raise RuntimeError("正式対象が0件です。")

    grouped: dict[str, list[dict[str, str]]] = defaultdict(list)
    for row in rows:
        name = (row.get("stadium_name") or "").strip()
        if name:
            grouped[name].append(row)

    overall = summarize(rows)
    start_date, end_date = date_range_from_filename(source_path)
    stadiums: dict[str, dict] = {}

    for name, venue_rows in sorted(grouped.items()):
        code = name_to_code.get(name)
        if not code:
            continue

        stat = summarize(venue_rows)
        diff = round(stat["lane1_win_rate"] - overall["lane1_win_rate"], 2)
        stat["name"] = name
        stat["lane1_vs_all_diff"] = diff
        stat["lane1_strength"] = strength_label(diff)
        add_course_vs_all(stat, overall)
        stadiums[code] = stat

    output = {
        "meta": {
            "label": "過去1年",
            "start_date": start_date,
            "end_date": end_date,
            "source": source_path.name,
            "generated_from": "export_stadium_practical_characteristics_json.py",
            "formal_races": len(rows),
            "stadium_count": len(stadiums),
            "overall_lane1_win_rate": overall["lane1_win_rate"],
            "overall_escape_rate": overall["escape_rate"],
            "overall_course_results": overall["course_results"],
            "note": "表示専用。PredictionLogic補正には未接続。",
        },
        "stadiums": stadiums,
    }

    output_path = repo_root / "config" / "stadium_practical_characteristics.local.json"
    with output_path.open("w", encoding="utf-8") as f:
        json.dump(output, f, ensure_ascii=False, indent=2)
        f.write("\n")

    print("=" * 60)
    print("場特性 実戦表示用JSON出力完了")
    print("=" * 60)
    print(f"正式対象 : {len(rows)}R")
    print(f"場数     : {len(stadiums)}")
    print(f"全場1C勝 : {overall['lane1_win_rate']:.2f}%")
    print("コース成績: 1着率 / 2連対率 / 3連対率 を追加")
    print(f"出力     : {output_path}")
    if len(stadiums) < 24:
        print("注意     : 24場未満です。元CSVの開催場を確認してください。")


if __name__ == "__main__":
    main()
