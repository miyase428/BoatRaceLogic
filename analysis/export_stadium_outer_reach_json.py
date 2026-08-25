#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
5C・6Cの外枠到達率を場別に集計し、実戦表示用JSONを生成する。

対象コンテキスト:
- 全レース
- 1逃げ時
- 1C敗戦時

各コンテキストで5C/6Cの2着率・3着率・3連対率を集計する。
3連対率は1〜3着に入った割合。1逃げ時は5C/6Cが頭にならないため2着+3着と同義。

Usage:
  python3 analysis/export_stadium_outer_reach_json.py \
    analysis/output/kimarite_analysis_dataset_20250815_20260814.csv

出力:
  config/stadium_outer_reach.local.json

表示専用。PredictionLogicには接続しない。
"""

from __future__ import annotations

import csv
import json
import re
import sys
from collections import defaultdict
from pathlib import Path

OUTER_COURSES = (5, 6)
CONTEXTS = ("all", "escape", "non1")


def as_int(row: dict[str, str], key: str) -> int:
    try:
        return int((row.get(key) or "0").strip() or 0)
    except (TypeError, ValueError):
        return 0


def pct(n: int, d: int) -> float:
    return round(100.0 * n / d, 2) if d > 0 else 0.0


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


def empty_context() -> dict:
    return {
        "n": 0,
        "courses": {
            str(course): {
                "first_count": 0,
                "second_count": 0,
                "third_count": 0,
                "top3_count": 0,
            }
            for course in OUTER_COURSES
        },
        "outer_any_top3_count": 0,
    }


def empty_stat() -> dict:
    return {context: empty_context() for context in CONTEXTS}


def add_to_context(ctx: dict, c1: int, c2: int, c3: int) -> None:
    ctx["n"] += 1
    top3 = {c1, c2, c3}

    for course in OUTER_COURSES:
        row = ctx["courses"][str(course)]
        if c1 == course:
            row["first_count"] += 1
        if c2 == course:
            row["second_count"] += 1
        if c3 == course:
            row["third_count"] += 1
        if course in top3:
            row["top3_count"] += 1

    if 5 in top3 or 6 in top3:
        ctx["outer_any_top3_count"] += 1


def add_row(stat: dict, row: dict[str, str]) -> None:
    c1 = as_int(row, "actual_1st_course")
    c2 = as_int(row, "actual_2nd_course")
    c3 = as_int(row, "actual_3rd_course")
    tech = (row.get("winner_technique") or "").strip()

    if not (1 <= c1 <= 6 and 1 <= c2 <= 6 and 1 <= c3 <= 6):
        return

    add_to_context(stat["all"], c1, c2, c3)

    if c1 == 1 and tech == "逃げ":
        add_to_context(stat["escape"], c1, c2, c3)

    if c1 != 1:
        add_to_context(stat["non1"], c1, c2, c3)


def finalize_context(ctx: dict) -> dict:
    n = int(ctx["n"])
    courses = {}

    for course in OUTER_COURSES:
        src = ctx["courses"][str(course)]
        courses[str(course)] = {
            "first_count": int(src["first_count"]),
            "first_rate": pct(int(src["first_count"]), n),
            "second_count": int(src["second_count"]),
            "second_rate": pct(int(src["second_count"]), n),
            "third_count": int(src["third_count"]),
            "third_rate": pct(int(src["third_count"]), n),
            "top3_count": int(src["top3_count"]),
            "top3_rate": pct(int(src["top3_count"]), n),
        }

    return {
        "n": n,
        "courses": courses,
        "outer_any_top3_count": int(ctx["outer_any_top3_count"]),
        "outer_any_top3_rate": pct(int(ctx["outer_any_top3_count"]), n),
    }


def finalize(stat: dict) -> dict:
    return {context: finalize_context(stat[context]) for context in CONTEXTS}


def add_vs_all(venue: dict, overall: dict) -> None:
    for context in CONTEXTS:
        vctx = venue.get(context) or {}
        gctx = overall.get(context) or {}
        vcourses = vctx.get("courses") or {}
        gcourses = gctx.get("courses") or {}

        vctx["outer_any_top3_vs_all"] = round(
            float(vctx.get("outer_any_top3_rate", 0.0))
            - float(gctx.get("outer_any_top3_rate", 0.0)),
            2,
        )

        for course in OUTER_COURSES:
            key = str(course)
            v = vcourses.get(key)
            g = gcourses.get(key)
            if not isinstance(v, dict) or not isinstance(g, dict):
                continue
            v["vs_all"] = {
                metric: round(
                    float(v.get(f"{metric}_rate", 0.0))
                    - float(g.get(f"{metric}_rate", 0.0)),
                    2,
                )
                for metric in ("second", "third", "top3")
            }


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

    grouped: dict[str, dict] = defaultdict(empty_stat)
    overall_raw = empty_stat()

    for row in rows:
        name = (row.get("stadium_name") or "").strip()
        if not name:
            continue
        add_row(grouped[name], row)
        add_row(overall_raw, row)

    overall = finalize(overall_raw)
    stadiums: dict[str, dict] = {}

    for name, raw_stat in sorted(grouped.items()):
        code = name_to_code.get(name)
        if not code:
            continue
        stat = finalize(raw_stat)
        stat["name"] = name
        add_vs_all(stat, overall)
        stadiums[code] = stat

    start_date, end_date = date_range_from_filename(source_path)
    output = {
        "meta": {
            "label": "過去1年",
            "start_date": start_date,
            "end_date": end_date,
            "source": source_path.name,
            "generated_from": "export_stadium_outer_reach_json.py",
            "formal_races": len(rows),
            "stadium_count": len(stadiums),
            "overall": overall,
            "note": "5C/6Cの外枠到達率。表示専用でPredictionLogic補正には未接続。",
        },
        "stadiums": stadiums,
    }

    output_path = repo_root / "config" / "stadium_outer_reach.local.json"
    with output_path.open("w", encoding="utf-8") as f:
        json.dump(output, f, ensure_ascii=False, indent=2)
        f.write("\n")

    print("=" * 60)
    print("外枠到達率 場特性JSON出力完了")
    print("=" * 60)
    print(f"正式対象 : {len(rows)}R")
    print(f"場数     : {len(stadiums)}")
    print(f"全場5C3連対 : {overall['all']['courses']['5']['top3_rate']:.2f}%")
    print(f"全場6C3連対 : {overall['all']['courses']['6']['top3_rate']:.2f}%")
    print("内容     : 全レース / 1逃げ時 / イン敗戦時 の5C・6C到達率")
    print(f"出力     : {output_path}")
    if len(stadiums) < 24:
        print("注意     : 24場未満です。元CSVの開催場を確認してください。")


if __name__ == "__main__":
    main()
