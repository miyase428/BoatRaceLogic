#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
1コースが実際に「逃げ」で勝ったレースに限定し、
2着・3着コース分布と 1-x-y の出目分布を集計する。

使い方:
  python3 analysis/analyze_lane1_escape_followers.py \
    analysis/output/kimarite_analysis_dataset_20250815_20260814.csv

対象条件:
- result_top3_course_complete = 1
- result_boat_match = 1
- actual_1st_course = 1
- winner_technique = 逃げ

このSTEPでは条件別kimariteはまだ掛けず、全体の基礎分布だけを見る。
次STEPで c2_nogashi / c3-c5 の攻め率などを重ねる。
"""

from __future__ import annotations

import csv
import sys
from collections import Counter
from pathlib import Path


def pct(n: int, d: int) -> str:
    if d <= 0:
        return "-"
    return f"{n / d * 100:.2f}%"


def load_rows(path: Path) -> list[dict[str, str]]:
    with path.open("r", encoding="utf-8-sig", newline="") as f:
        return list(csv.DictReader(f))


def as_int(row: dict[str, str], key: str) -> int:
    try:
        return int((row.get(key) or "0").strip() or 0)
    except ValueError:
        return 0


def main() -> None:
    if len(sys.argv) != 2:
        print(
            "Usage: python3 analysis/analyze_lane1_escape_followers.py DATASET_CSV",
            file=sys.stderr,
        )
        sys.exit(1)

    path = Path(sys.argv[1]).resolve()
    if not path.is_file():
        raise RuntimeError(f"CSVがありません: {path}")

    rows = load_rows(path)

    valid = []
    lane1_wins = []
    escapes = []

    for row in rows:
        if as_int(row, "result_top3_course_complete") != 1:
            continue
        if as_int(row, "result_boat_match") != 1:
            continue

        valid.append(row)

        if as_int(row, "actual_1st_course") == 1:
            lane1_wins.append(row)

            technique = (row.get("winner_technique") or "").strip()
            if technique == "逃げ":
                escapes.append(row)

    second = Counter()
    third = Counter()
    patterns = Counter()
    second_third_pair = Counter()

    for row in escapes:
        c2 = as_int(row, "actual_2nd_course")
        c3 = as_int(row, "actual_3rd_course")

        if 1 <= c2 <= 6:
            second[c2] += 1
        if 1 <= c3 <= 6:
            third[c3] += 1
        if 1 <= c2 <= 6 and 1 <= c3 <= 6:
            patterns[f"1-{c2}-{c3}"] += 1
            second_third_pair[(c2, c3)] += 1

    print()
    print("=" * 92)
    print("1逃げ成立時の2着・3着コース分布（全場・基礎分布）")
    print("=" * 92)
    print(f"CSV行                 : {len(rows)}")
    print(f"正式分析対象           : {len(valid)}")
    print(f"1コース1着             : {len(lane1_wins)} / {len(valid)} ({pct(len(lane1_wins), len(valid))})")
    print(
        f"1コース逃げ             : {len(escapes)} / {len(valid)} ({pct(len(escapes), len(valid))})"
    )
    print(
        f"1コース1着のうち逃げ    : {len(escapes)} / {len(lane1_wins)} ({pct(len(escapes), len(lane1_wins))})"
    )

    print()
    print("【1逃げ時 2着コース分布】")
    print("コース      件数       構成比")
    print("-" * 34)
    for course in range(2, 7):
        n = second[course]
        print(f"{course:>3}        {n:>6}      {pct(n, len(escapes)):>8}")

    print()
    print("【1逃げ時 3着コース分布】")
    print("コース      件数       構成比")
    print("-" * 34)
    for course in range(2, 7):
        n = third[course]
        print(f"{course:>3}        {n:>6}      {pct(n, len(escapes)):>8}")

    print()
    print("【1逃げ時 1-x-y 出目 TOP20】")
    print("出目           件数       構成比")
    print("-" * 40)
    for pattern, n in patterns.most_common(20):
        print(f"{pattern:<10}    {n:>6}      {pct(n, len(escapes)):>8}")

    print()
    print("【2着コース別 → 3着コース内訳 TOP3】")
    print("-" * 68)
    for second_course in range(2, 7):
        total_second = second[second_course]
        if total_second == 0:
            continue

        followers = Counter()
        for (c2, c3), n in second_third_pair.items():
            if c2 == second_course:
                followers[c3] += n

        top3 = followers.most_common(3)
        text = ", ".join(
            f"{c3}コース {n}件({pct(n, total_second)})"
            for c3, n in top3
        )
        print(
            f"2着={second_course}コース  N={total_second:>5}  →  {text}"
        )

    print()
    print("=" * 92)
    print("基礎分布集計完了")
    print("次STEP: 事前kimarite条件を掛け、基礎分布からの増減(pt/Lift)を比較")
    print("=" * 92)


if __name__ == "__main__":
    main()
