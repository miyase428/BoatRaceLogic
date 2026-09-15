#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""多摩川6Cが1着になったときの2着・3着コース分布を条件別に集計する。"""

from __future__ import annotations

import csv
import sys
from collections import Counter, OrderedDict
from pathlib import Path


def number(value: str) -> float | None:
    try:
        return float(value)
    except (TypeError, ValueError):
        return None


def integer(value: str) -> int | None:
    n = number(value)
    return None if n is None else int(n)


def pct(num: int, den: int) -> float:
    return 100.0 * num / den if den else 0.0


def load_rows(path: Path) -> list[dict]:
    rows = []
    with path.open("r", encoding="utf-8-sig", newline="") as f:
        for raw in csv.DictReader(f):
            rows.append({
                "race_date": raw["race_date"],
                "history_n": integer(raw["p6_12_n"]),
                "attack": number(raw["p6_12_attack"]),
                "rank": integer(raw["second_rank"]),
                "lap": number(raw["lap_score"]),
                "first": integer(raw["first"]),
                "second": integer(raw["second"]),
                "third": integer(raw["third"]),
            })
    return rows


CONDITIONS = OrderedDict([
    ("BASE", "6C履歴あり（基準）"),
    ("STAR", "★ 攻め率5%以上"),
    ("DOUBLE", "★★ ★＋周回評価4以上"),
    ("TRIPLE", "★★★ ★＋二次順位1位（参考）"),
])


def matches(row: dict, key: str) -> bool:
    base = row["history_n"] is not None and row["history_n"] > 0
    star = base and row["attack"] is not None and row["attack"] >= 5.0
    if key == "BASE":
        return base
    if key == "STAR":
        return star
    if key == "DOUBLE":
        return star and row["lap"] is not None and row["lap"] >= 4.0
    if key == "TRIPLE":
        return star and row["rank"] == 1
    raise ValueError(key)


def aggregate(rows: list[dict], key: str) -> dict:
    selected = [r for r in rows if matches(r, key) and r["first"] == 6]
    second = Counter(r["second"] for r in selected if r["second"] in range(1, 7))
    third = Counter(r["third"] for r in selected if r["third"] in range(1, 7))
    pair = Counter((r["second"], r["third"]) for r in selected
                   if r["second"] in range(1, 7) and r["third"] in range(1, 7))
    return {"n": len(selected), "second": second, "third": third, "pair": pair}


def print_counter(label: str, values: Counter, n: int) -> None:
    ordered = sorted(values.items(), key=lambda item: (-item[1], item[0]))
    text = " / ".join(f"{course}:{count}件({pct(count, n):.1f}%)" for course, count in ordered)
    print(f"  {label}: {text or '-'}")


def main() -> None:
    if len(sys.argv) != 2:
        raise SystemExit("Usage: python3 analysis/analyze_tamagawa_lane6_win_followers.py CSV_PATH")
    rows = load_rows(Path(sys.argv[1]))
    print("=" * 140)
    print("多摩川6コース1着時：2着・3着コース分布")
    print("=" * 140)
    for key, title in CONDITIONS.items():
        result = aggregate(rows, key)
        n = result["n"]
        print(f"\n{title}  6頭件数={n}")
        print_counter("2着", result["second"], n)
        print_counter("3着", result["third"], n)
        pairs = sorted(result["pair"].items(), key=lambda item: (-item[1], item[0]))
        print("  2着-3着上位: " + " / ".join(f"{a}-{b} {count}件({pct(count, n):.1f}%)" for (a, b), count in pairs[:10]))


if __name__ == "__main__":
    main()
