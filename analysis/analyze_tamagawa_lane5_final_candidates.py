#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""多摩川5コースの最終候補を、一次条件・二次順位・profile長で比較する。

探索済みの多数条件から、意味が明確で母数を確保できた候補だけを固定して
時系列比較する。着順は評価にのみ使用する。

Usage:
  python3 analysis/analyze_tamagawa_lane5_final_candidates.py \
    analysis/output/tamagawa_lane5_primary_stability_20260909.csv
"""

from __future__ import annotations

import csv
import sys
from collections import OrderedDict
from datetime import date, timedelta
from pathlib import Path


def pct(num: int, den: int) -> float:
    return 100.0 * num / den if den else 0.0


def months_ago(value: date, months: int) -> date:
    year = value.year
    month = value.month - months
    while month <= 0:
        year -= 1
        month += 12
    return date(year, month, value.day)


def number(value: str) -> float | None:
    try:
        return float(value)
    except (TypeError, ValueError):
        return None


def integer(value: str) -> int | None:
    value = number(value)
    return None if value is None else int(value)


def load_rows(path: Path) -> list[dict]:
    fields = [
        "p5_12_n", "p5_6_n",
        "p5_12_win", "p5_12_makurizashi", "p5_12_attack",
        "p5_6_win", "p5_6_makurizashi", "p5_6_attack",
    ]
    rows = []
    with path.open("r", encoding="utf-8-sig", newline="") as f:
        reader = csv.DictReader(f)
        for raw in reader:
            row = {
                "race_date": date.fromisoformat(raw["race_date"]),
                "rank": integer(raw["second_rank"]),
                "lap": number(raw["lap_score"]),
                "st54": raw["st54"],
                "first": integer(raw["first"]),
                "second": integer(raw["second"]),
                "third": integer(raw["third"]),
            }
            row.update({field: number(raw[field]) for field in fields})
            rows.append(row)
    return rows


LABELS = OrderedDict([
    ("BASE", "5C履歴あり・二次あり"),
    ("W10", "過去1着率10%以上"),
    ("W15", "過去1着率15%以上"),
    ("MZ10", "まくり差し率10%以上"),
    ("A10", "攻め率10%以上（★候補）"),
    ("A15", "攻め率15%以上"),
    ("A10_R3", "攻め率10%以上×二次3位以内"),
    ("A10_UNION", "攻め率10%以上×（二次3位以内または周回4以上）"),
    ("A10_R1", "攻め率10%以上×二次1位（★★候補）"),
    ("A10_LAP4", "攻め率10%以上×周回4以上"),
    ("A10_BOTH", "攻め率10%以上×二次3位以内×周回4以上"),
    ("W10_R1", "1着率10%以上×二次1位"),
    ("W15_R1", "1着率15%以上×二次1位"),
    ("MZ10_R1", "まくり差し10%以上×二次1位"),
    ("A15_R1", "攻め率15%以上×二次1位"),
    ("A10_ST_R1", "攻め率10%以上×ST上×二次1位"),
])


def enabled(row: dict, key: str, months: int) -> bool:
    history_n = row[f"p5_{months}_n"]
    win = row[f"p5_{months}_win"]
    mz = row[f"p5_{months}_makurizashi"]
    attack = row[f"p5_{months}_attack"]
    rank = row["rank"]
    if history_n is None or history_n <= 0 or attack is None or rank is None:
        return False
    flags = {
        "BASE": True,
        "W10": win >= 10.0,
        "W15": win >= 15.0,
        "MZ10": mz >= 10.0,
        "A10": attack >= 10.0,
        "A15": attack >= 15.0,
        "A10_R3": attack >= 10.0 and rank <= 3,
        "A10_UNION": attack >= 10.0 and (rank <= 3 or row["lap"] >= 4.0),
        "A10_R1": attack >= 10.0 and rank <= 1,
        "A10_LAP4": attack >= 10.0 and row["lap"] >= 4.0,
        "A10_BOTH": attack >= 10.0 and rank <= 3 and row["lap"] >= 4.0,
        "W10_R1": win >= 10.0 and rank <= 1,
        "W15_R1": win >= 15.0 and rank <= 1,
        "MZ10_R1": mz >= 10.0 and rank <= 1,
        "A15_R1": attack >= 15.0 and rank <= 1,
        "A10_ST_R1": attack >= 10.0 and row["st54"] == "内側より上" and rank <= 1,
    }
    return flags[key]


def blank() -> dict:
    return {"n": 0, "first": 0, "second": 0, "third": 0}


def stats(rows: list[dict], start: date, end: date, months: int) -> OrderedDict[str, dict]:
    result = OrderedDict((key, blank()) for key in LABELS)
    for row in rows:
        if not start <= row["race_date"] <= end:
            continue
        for key in result:
            if enabled(row, key, months):
                s = result[key]
                s["n"] += 1
                s["first"] += row["first"] == 5
                s["second"] += row["second"] == 5
                s["third"] += row["third"] == 5
    for value in result.values():
        n = value["n"]
        value["head"] = pct(value["first"], n)
        value["top2"] = pct(value["first"] + value["second"], n)
        value["top3"] = pct(value["first"] + value["second"] + value["third"], n)
    return result


def print_stats(title: str, values: OrderedDict[str, dict], compare: str = "BASE") -> None:
    base = values[compare]
    print(f"\n■ {title}")
    for key, value in values.items():
        print(
            f"{key:<12} {LABELS[key]:<31} N={value['n']:4d}  "
            f"5頭={value['head']:6.2f}% ({value['head'] - base['head']:+6.2f}pt)  "
            f"2連対={value['top2']:6.2f}% ({value['top2'] - base['top2']:+6.2f}pt)  "
            f"3連対={value['top3']:6.2f}% ({value['top3'] - base['top3']:+6.2f}pt)"
        )


def main() -> None:
    if len(sys.argv) != 2:
        raise SystemExit("Usage: python3 analysis/analyze_tamagawa_lane5_final_candidates.py CSV_PATH")
    rows = load_rows(Path(sys.argv[1]))
    end = max(row["race_date"] for row in rows)
    anchor = end + timedelta(days=1)
    start12 = months_ago(anchor, 12)
    start24 = months_ago(anchor, 24)

    print("=" * 174)
    print("多摩川5コース：最終候補の固定比較")
    print("一次は対象日前のコース別履歴、二次順位・周回評価は当日展示。結果は条件に不使用。")
    print("=" * 174)
    values24 = stats(rows, start24, end, 12)
    print_stats(f"24ヶ月 / 過去12ヶ月profile  {start24}～{end}", values24)

    print("\n【同一の直近12ヶ月でprofile長を比較】")
    values_by_profile = {}
    for months in (12, 6):
        value = stats(rows, start12, end, months)
        values_by_profile[months] = value
        print_stats(f"直近12ヶ月 / 過去{months}ヶ月profile", value)

    print("\n【過去12ヶ月profile：6ヶ月×4非重複ブロック】")
    blocks = []
    for idx, label in enumerate(("直近0-6ヶ月", "6-12ヶ月前", "12-18ヶ月前", "18-24ヶ月前")):
        start = months_ago(anchor, (idx + 1) * 6)
        finish = months_ago(anchor, idx * 6) - timedelta(days=1)
        value = stats(rows, start, finish, 12)
        blocks.append(value)
        print_stats(f"{label}  {start}～{finish}", value, "A10")

    print("\n【候補再現性：各区間の★（A10）との比較、N>=20のみ】")
    for key in ("A10_R3", "A10_UNION", "A10_R1", "A10_LAP4", "A10_BOTH", "W10_R1", "W15_R1", "MZ10_R1", "A15_R1", "A10_ST_R1"):
        valid = [value for value in blocks if value[key]["n"] >= 20]
        head = sum(value[key]["head"] > value["A10"]["head"] for value in valid)
        top2 = sum(value[key]["top2"] > value["A10"]["top2"] for value in valid)
        top3 = sum(value[key]["top3"] > value["A10"]["top3"] for value in valid)
        v = values24[key]
        base = values24["A10"]
        print(
            f"{key:<12} {LABELS[key]:<31} N24={v['n']:4d}  "
            f"Δ頭={v['head'] - base['head']:+6.2f}pt  Δ2連={v['top2'] - base['top2']:+6.2f}pt  "
            f"Δ3連={v['top3'] - base['top3']:+6.2f}pt  区間超え=頭{head}/{len(valid)}・2連{top2}/{len(valid)}・3連{top3}/{len(valid)}"
        )


if __name__ == "__main__":
    main()
