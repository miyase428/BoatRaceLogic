#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""6C一次サインに展示二次評価を重ね、★★・★★★候補を比較する。"""

from __future__ import annotations

import csv
import sys
from collections import OrderedDict
from datetime import date, timedelta
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
                "race_date": date.fromisoformat(raw["race_date"]),
                "st_up": raw["st65"] == "内側より上",
                "attack": number(raw["p6_12_attack"]),
                "win_rate": number(raw["p6_12_win"]),
                "makuri": number(raw["p6_12_makuri"]),
                "makurizashi": number(raw["p6_12_makurizashi"]),
                "score": number(raw["second_score"]),
                "rank": integer(raw["second_rank"]),
                "gap": number(raw["gap"]),
                "attack_potential": number(raw["attack_potential"]),
                "straight": number(raw["straight_score"]),
                "lap": number(raw["lap_score"]),
                "mawari": number(raw["mawari_score"]),
                "first": integer(raw["first"]),
                "second": integer(raw["second"]),
                "third": integer(raw["third"]),
            })
    return rows


BASES = OrderedDict([
    ("A5", "★ 攻め率5%以上"),
    ("A5_ST", "★ 攻め率5%以上×6が5よりST上"),
    ("W5", "参考 6C過去1着率5%以上"),
    ("M5", "参考 6Cまくり率5%以上"),
])

CONDITIONS = OrderedDict([
    ("STAR", "★本体"),
    ("R3", "★★候補 二次順位3位以内"),
    ("L4", "★★候補 周回評価4以上"),
    ("R3_OR_L4", "★★候補 二次3位以内 or 周回4以上"),
    ("S24_G5", "参考 二次24以上×TOP差5以内"),
    ("S27_G5", "参考 二次27以上×TOP差5以内"),
    ("STRAIGHT4", "参考 直線評価4以上"),
    ("MAWARI4", "参考 周り足評価4以上"),
    ("R1", "★★★候補 二次順位1位"),
])


def base_match(row: dict, key: str) -> bool:
    if row["attack"] is None:
        return False
    if key == "A5":
        return row["attack"] >= 5.0
    if key == "A5_ST":
        return row["attack"] >= 5.0 and row["st_up"]
    if key == "W5":
        return row["win_rate"] is not None and row["win_rate"] >= 5.0
    if key == "M5":
        return row["makuri"] is not None and row["makuri"] >= 5.0
    raise ValueError(key)


def condition_match(row: dict, key: str) -> bool:
    if key == "STAR":
        return True
    if row["score"] is None:
        return False
    if key == "R3":
        return row["rank"] is not None and row["rank"] <= 3
    if key == "L4":
        return row["lap"] is not None and row["lap"] >= 4
    if key == "R3_OR_L4":
        return ((row["rank"] is not None and row["rank"] <= 3)
                or (row["lap"] is not None and row["lap"] >= 4))
    if key == "S24_G5":
        return row["score"] >= 24 and row["gap"] is not None and row["gap"] <= 5
    if key == "S27_G5":
        return row["score"] >= 27 and row["gap"] is not None and row["gap"] <= 5
    if key == "STRAIGHT4":
        return row["straight"] is not None and row["straight"] >= 4
    if key == "MAWARI4":
        return row["mawari"] is not None and row["mawari"] >= 4
    if key == "R1":
        return row["rank"] == 1
    raise ValueError(key)


def summarize(rows: list[dict], start: date, end: date, base_key: str, cond_key: str) -> dict:
    selected = [r for r in rows if start <= r["race_date"] <= end
                and base_match(r, base_key) and condition_match(r, cond_key)]
    n = len(selected)
    first = sum(r["first"] == 6 for r in selected)
    second = sum(r["second"] == 6 for r in selected)
    third = sum(r["third"] == 6 for r in selected)
    return {"n": n, "head": pct(first, n), "top2": pct(first + second, n),
            "top3": pct(first + second + third, n)}


def months_ago(value: date, months: int) -> date:
    year, month = value.year, value.month - months
    while month <= 0:
        year -= 1
        month += 12
    return date(year, month, value.day)


def print_window(label: str, start: date, end: date, rows: list[dict], base_key: str) -> dict:
    values = {key: summarize(rows, start, end, base_key, key) for key in CONDITIONS}
    base = values["STAR"]
    print(f"\n■ {base_key} / {label} {start}～{end}")
    for key, title in CONDITIONS.items():
        s = values[key]
        small = " [N小]" if 0 < s["n"] < 20 else ""
        print(f"{key:<10} {title:<29} N={s['n']:4d}  "
              f"6頭={s['head']:6.2f}% ({s['head']-base['head']:+6.2f}pt)  "
              f"2連対={s['top2']:6.2f}% ({s['top2']-base['top2']:+6.2f}pt)  "
              f"3連対={s['top3']:6.2f}% ({s['top3']-base['top3']:+6.2f}pt){small}")
    return values


def main() -> None:
    if len(sys.argv) != 2:
        raise SystemExit("Usage: python3 analysis/analyze_tamagawa_lane6_secondary_stability.py CSV_PATH")
    rows = load_rows(Path(sys.argv[1]))
    end = max(r["race_date"] for r in rows)
    anchor = end + timedelta(days=1)
    long = [("直近12ヶ月", months_ago(anchor, 12), end),
            ("直近18ヶ月", months_ago(anchor, 18), end),
            ("直近24ヶ月", months_ago(anchor, 24), end)]
    blocks = []
    labels = ("直近0-6ヶ月", "6-12ヶ月前", "12-18ヶ月前", "18-24ヶ月前")
    for i, label in enumerate(labels):
        blocks.append((label, months_ago(anchor, (i + 1) * 6),
                       months_ago(anchor, i * 6) - timedelta(days=1)))

    print("=" * 160)
    print("多摩川6コース：一次サイン×二次評価　★★・★★★候補比較")
    print("★候補は過去12ヶ月profile、展示評価は対象日当日の二次評価。")
    print("=" * 160)
    for base_key, _ in BASES.items():
        print(f"\n{'#' * 20} {BASES[base_key]} {'#' * 20}")
        long_values = {label: print_window(label, start, finish, rows, base_key) for label, start, finish in long}
        block_values = {label: print_window(label, start, finish, rows, base_key) for label, start, finish in blocks}
        print("\n【再現性】")
        for key, title in list(CONDITIONS.items())[1:]:
            valid = [v for v in block_values.values() if v[key]["n"] >= 20]
            wins = sum(v[key]["head"] > v["STAR"]["head"] for v in valid)
            n24 = long_values["直近24ヶ月"][key]["n"]
            print(f"{key:<10} {title:<29} 6ヶ月頭超え={wins}/{len(valid)} N24={n24:4d}")


if __name__ == "__main__":
    main()
