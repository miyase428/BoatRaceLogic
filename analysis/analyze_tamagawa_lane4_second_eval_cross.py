#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
多摩川4コース★強条件について、既に出力済みの
`tamagawa_lane4_strong_condition_second_eval_*.csv` を使い、
4号艇の二次スコア閾値と二次トップとの差をクロス集計する。

主目的:
- 二次24点以上 / 23点以下
- TOP差5以内 / TOP差6以上
の4区分で、4号艇の1着・2連対・3連対が分かれるか確認する。

Usage:
  python3 analysis/analyze_tamagawa_lane4_second_eval_cross.py \
    analysis/output/tamagawa_lane4_strong_condition_second_eval_20250901_20260909.csv
"""

from __future__ import annotations

import csv
import sys
from collections import defaultdict
from pathlib import Path


def pct(num: int, den: int) -> float:
    return 100.0 * num / den if den else 0.0


def blank_stat() -> dict:
    return {
        "n": 0,
        "first": 0,
        "second": 0,
        "third": 0,
        "top2": 0,
        "top3": 0,
    }


def add(stat: dict, first: int, second: int, third: int | None) -> None:
    stat["n"] += 1
    if first == 4:
        stat["first"] += 1
    if second == 4:
        stat["second"] += 1
    if third == 4:
        stat["third"] += 1
    if first == 4 or second == 4:
        stat["top2"] += 1
    if first == 4 or second == 4 or third == 4:
        stat["top3"] += 1


def score_group(score: float) -> str:
    return "二次24以上" if score >= 24.0 else "二次23以下"


def gap_group(gap: float) -> str:
    return "TOP差5以内" if gap <= 5.0 else "TOP差6以上"


def print_stat(label: str, stat: dict) -> None:
    n = stat["n"]
    print(
        f"{label:<28} N={n:4d}  "
        f"4頭={pct(stat['first'], n):6.2f}%  "
        f"42着={pct(stat['second'], n):6.2f}%  "
        f"43着={pct(stat['third'], n):6.2f}%  "
        f"4-2連対={pct(stat['top2'], n):6.2f}%  "
        f"4-3連対={pct(stat['top3'], n):6.2f}%"
    )


def parse_int(value: str) -> int | None:
    value = (value or "").strip()
    if not value:
        return None
    try:
        return int(float(value))
    except ValueError:
        return None


def main() -> None:
    if len(sys.argv) != 2:
        print("Usage: python3 analysis/analyze_tamagawa_lane4_second_eval_cross.py CSV_PATH", file=sys.stderr)
        sys.exit(1)

    path = Path(sys.argv[1])
    if not path.exists():
        raise FileNotFoundError(path)

    by_month = defaultdict(lambda: {
        "all": blank_stat(),
        "cross": defaultdict(blank_stat),
        "score": defaultdict(blank_stat),
        "gap": defaultdict(blank_stat),
    })

    with path.open("r", encoding="utf-8-sig", newline="") as f:
        reader = csv.DictReader(f)
        required = {
            "history_months", "lane4_second_score", "lane4_gap_to_top",
            "first_course", "second_course", "third_course",
        }
        missing = required - set(reader.fieldnames or [])
        if missing:
            raise RuntimeError(f"CSV列不足: {sorted(missing)}")

        for row in reader:
            months = parse_int(row.get("history_months", ""))
            if months not in (6, 12):
                continue
            try:
                score = float(row["lane4_second_score"])
                gap = float(row["lane4_gap_to_top"])
            except (TypeError, ValueError):
                continue

            first = parse_int(row.get("first_course", ""))
            second = parse_int(row.get("second_course", ""))
            third = parse_int(row.get("third_course", ""))
            if first not in range(1, 7) or second not in range(1, 7):
                continue

            sg = score_group(score)
            gg = gap_group(gap)
            key = f"{sg} × {gg}"
            stat = by_month[months]
            add(stat["all"], first, second, third)
            add(stat["score"][sg], first, second, third)
            add(stat["gap"][gg], first, second, third)
            add(stat["cross"][key], first, second, third)

    print("=" * 132)
    print("多摩川4コース：★強条件 × 二次スコア閾値 × 二次TOP差 クロス")
    print("仮説候補: 二次24以上かつTOP差5以内なら★強め / 二次23以下またはTOP差6以上なら★弱め")
    print("=" * 132)

    cross_order = (
        "二次24以上 × TOP差5以内",
        "二次24以上 × TOP差6以上",
        "二次23以下 × TOP差5以内",
        "二次23以下 × TOP差6以上",
    )

    for months in (12, 6):
        s = by_month[months]
        print("\n" + "-" * 132)
        print(f"【過去{months}ヶ月profile】")
        print("-" * 132)
        print("\n■ ★全体")
        print_stat("ALL", s["all"])

        print("\n■ 二次スコア単独")
        for key in ("二次24以上", "二次23以下"):
            print_stat(key, s["score"][key])

        print("\n■ TOP差単独")
        for key in ("TOP差5以内", "TOP差6以上"):
            print_stat(key, s["gap"][key])

        print("\n■ クロス")
        for key in cross_order:
            print_stat(key, s["cross"][key])

    print("\n" + "=" * 132)
    print("※ Nを必ず併記して判断してください。ここでは買い目・本命ロジックは変更しません。")
    print("=" * 132)


if __name__ == "__main__":
    main()
