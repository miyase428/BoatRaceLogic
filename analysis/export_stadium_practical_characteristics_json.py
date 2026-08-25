#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
場特性の実戦表示用JSONを生成する。

対象:
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

    for row in rows:
        tech = (row.get("winner_technique") or "").strip()
        if tech:
            technique[tech] += 1

        c1 = as_int(row, "actual_1st_course")
        c2 = as_int(row, "actual_2nd_course")
        c3 = as_int(row, "actual_3rd_course")

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

    known_tech = sum(technique.values())
    technique_rates = {t: pct(technique[t], known_tech) for t in TECHNIQUES}
    non_escape_top = max(
        NON_ESCAPE_TECHNIQUES,
        key=lambda t: (technique_rates.get(t, 0.0), -NON_ESCAPE_TECHNIQUES.index(t)),
    )

    return {
        "n": len(rows),
        "lane1_win_count": lane1_win,
        "lane1_win_rate": pct(lane1_win, len(rows)),
        "escape_count": escape_n,
        "escape_rate": pct(escape_n, len(rows)),
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
    print(f"出力     : {output_path}")
    if len(stadiums) < 24:
        print("注意     : 24場未満です。元CSVの開催場を確認してください。")


if __name__ == "__main__":
    main()
