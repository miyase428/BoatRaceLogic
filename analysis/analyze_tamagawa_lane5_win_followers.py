#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""多摩川で5コース1着時の2着・3着コース分布を条件別に集計する。

Usage:
  python3 analysis/analyze_tamagawa_lane5_win_followers.py \
    analysis/output/tamagawa_lane5_primary_stability_20260909.csv
"""

from __future__ import annotations

import csv
import sys
from collections import Counter, OrderedDict
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
    rows = []
    with path.open("r", encoding="utf-8-sig", newline="") as f:
        for raw in csv.DictReader(f):
            rows.append({
                "race_code": raw["race_code"],
                "race_date": date.fromisoformat(raw["race_date"]),
                "history_n": integer(raw["p5_12_n"]),
                "attack": number(raw["p5_12_attack"]),
                "rank": integer(raw["second_rank"]),
                "first": integer(raw["first"]),
                "second": integer(raw["second"]),
                "third": integer(raw["third"]),
            })
    return rows


CONDITIONS = OrderedDict([
    ("BASE", "5C履歴あり（基準）"),
    ("STAR", "★ 攻め率10%以上"),
    ("BROAD", "★＋二次3位以内"),
    ("DOUBLE", "★★ 攻め率10%以上＋二次1位"),
])


def matches(row: dict, key: str) -> bool:
    base = row["history_n"] is not None and row["history_n"] > 0
    star = base and row["attack"] is not None and row["attack"] >= 10.0
    if key == "BASE":
        return base
    if key == "STAR":
        return star
    if key == "BROAD":
        return star and row["rank"] is not None and row["rank"] <= 3
    if key == "DOUBLE":
        return star and row["rank"] == 1
    raise ValueError(key)


def aggregate(rows: list[dict], start: date, end: date, key: str) -> dict:
    selected = [
        row for row in rows
        if start <= row["race_date"] <= end and matches(row, key) and row["first"] == 5
    ]
    second = Counter(row["second"] for row in selected if row["second"] in range(1, 7))
    third = Counter(row["third"] for row in selected if row["third"] in range(1, 7))
    exacta = Counter((row["second"],) for row in selected if row["second"] in range(1, 7))
    trifecta = Counter(
        (row["second"], row["third"])
        for row in selected
        if row["second"] in range(1, 7) and row["third"] in range(1, 7)
    )
    return {"n": len(selected), "second": second, "third": third, "exacta": exacta, "trifecta": trifecta}


def print_counter(label: str, values: Counter, n: int) -> None:
    ordered = sorted(values.items(), key=lambda item: (-item[1], item[0]))
    text = " / ".join(f"{course}:{count}件({pct(count, n):.1f}%)" for course, count in ordered)
    print(f"  {label}: {text or '-'}")


def print_result(label: str, result: dict) -> None:
    n = result["n"]
    print(f"\n{label}  5頭件数={n}")
    print_counter("2着", result["second"], n)
    print_counter("3着", result["third"], n)
    exacta = sorted(result["exacta"].items(), key=lambda item: (-item[1], item[0]))
    trifecta = sorted(result["trifecta"].items(), key=lambda item: (-item[1], item[0]))
    print("  2連単上位: " + " / ".join(f"5-{key[0]} {count}件({pct(count, n):.1f}%)" for key, count in exacta[:5]))
    print("  3連単上位: " + " / ".join(f"5-{key[0]}-{key[1]} {count}件({pct(count, n):.1f}%)" for key, count in trifecta[:10]))


def main() -> None:
    if len(sys.argv) != 2:
        raise SystemExit("Usage: python3 analysis/analyze_tamagawa_lane5_win_followers.py CSV_PATH")
    rows = load_rows(Path(sys.argv[1]))
    end = max(row["race_date"] for row in rows)
    anchor = end + timedelta(days=1)
    windows = (
        ("直近12ヶ月", months_ago(anchor, 12), end),
        ("直近24ヶ月", months_ago(anchor, 24), end),
    )
    print("=" * 146)
    print("多摩川5コース1着時：2着・3着コース分布")
    print("★ = 対象日前12ヶ月の5C攻め率10%以上 / ★★ = ★かつ当日展示の二次評価1位")
    print("=" * 146)
    for window, start, finish in windows:
        print(f"\n{'-' * 146}\n【{window}】 {start}～{finish}\n{'-' * 146}")
        for key, title in CONDITIONS.items():
            print_result(title, aggregate(rows, start, finish, key))

    print("\n注意: ★★の5頭件数は小さいため、相手順位は参考。払戻・オッズは条件にも集計にも使用していない。")


if __name__ == "__main__":
    main()
