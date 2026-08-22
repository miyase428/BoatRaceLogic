#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
現行の決まり手分析用1年データセットから、
1コースが1着ではなかったレース（イン飛び）を対象に、
3～5コースの事前6month攻め率（まくり+まくり差し）が
各コースの1着率へどう効くかを確認する。

目的:
- kimariteが「1逃げ時の相手」より「イン飛び時の頭候補」に強く効くか確認
- 3C/4C/5Cの攻め率帯ごとに、2～6コースの実1着分布を比較
- 条件コース自身だけでなく、隣接コースの連動も確認

注意:
- 条件に使うのは対象レースより前だけの6month point-in-time kimarite値。
- actual_* は結果ラベルとしてのみ使用。
- 正式結果完備かつ艇番一致レースのみ使用。

使い方:
  python3 analysis/analyze_non_lane1_winner_kimarite_conditions.py \
    analysis/output/kimarite_analysis_dataset_20250815_20260814.csv
"""

from __future__ import annotations

import csv
import sys
from collections import Counter
from pathlib import Path

COURSES = (2, 3, 4, 5, 6)
ATTACK_COURSES = (3, 4, 5)
MIN_SAMPLE = 10
BANDS = (
    ("0-5", 0.0, 5.0),
    ("5-10", 5.0, 10.0),
    ("10-15", 10.0, 15.0),
    ("15-20", 15.0, 20.0),
    ("20-25", 20.0, 25.0),
    ("25+", 25.0, None),
)


def to_int(value, default=0):
    try:
        return int(float(value))
    except (TypeError, ValueError):
        return default


def to_float(value, default=0.0):
    try:
        return float(value)
    except (TypeError, ValueError):
        return default


def pct(n: int, d: int) -> float:
    return 100.0 * n / d if d else 0.0


def load_rows(path: Path):
    with path.open("r", encoding="utf-8-sig", newline="") as f:
        return list(csv.DictReader(f))


def is_formal(row):
    return (
        to_int(row.get("result_top3_course_complete")) == 1
        and to_int(row.get("result_boat_match")) == 1
    )


def sample_ok(row, course: int):
    return to_int(row.get(f"c{course}_6m_sample_n")) >= MIN_SAMPLE


def attack(row, course: int):
    return (
        to_float(row.get(f"c{course}_6m_makuri"))
        + to_float(row.get(f"c{course}_6m_makurizashi"))
    )


def in_band(value: float, low: float, high):
    if high is None:
        return value >= low
    return low <= value < high


def winner_dist(rows):
    c = Counter(to_int(r.get("actual_1st_course")) for r in rows)
    n = len(rows)
    return {course: pct(c.get(course, 0), n) for course in COURSES}


def print_dist(title: str, rows, baseline):
    n = len(rows)
    print(f"\n{title}  N={n}")
    print("コース     条件1着率    基礎1着率      差pt      Lift")
    print("-" * 62)
    cur = winner_dist(rows)
    for c in COURSES:
        base = baseline[c]
        rate = cur[c]
        lift = rate / base if base else 0.0
        print(f"{c:>4}      {rate:>8.2f}%      {base:>8.2f}%   {rate-base:>+7.2f}   {lift:>6.3f}")


def main():
    if len(sys.argv) != 2:
        print(
            "Usage: python3 analysis/analyze_non_lane1_winner_kimarite_conditions.py DATASET_CSV",
            file=sys.stderr,
        )
        sys.exit(1)

    path = Path(sys.argv[1]).resolve()
    if not path.is_file():
        raise RuntimeError(f"CSVがありません: {path}")

    rows = [r for r in load_rows(path) if is_formal(r)]
    non1 = [r for r in rows if to_int(r.get("actual_1st_course")) != 1]

    if not non1:
        raise RuntimeError("イン飛び対象がありません")

    base = winner_dist(non1)

    print("\n" + "=" * 126)
    print("イン飛び時 kimarite攻め率帯別 頭候補分布")
    print("=" * 126)
    print(f"正式分析対象 : {len(rows)}")
    print(f"イン飛び     : {len(non1)} / {len(rows)} ({pct(len(non1), len(rows)):.2f}%)")
    print(f"条件         : 3～5C 6month point-in-time 攻め率、sample_n>={MIN_SAMPLE}")

    print("\n【イン飛び時の基礎1着分布】")
    print("コース      件数相当率")
    print("-" * 30)
    for c in COURSES:
        print(f"{c:>4}       {base[c]:>8.2f}%")

    for attack_course in ATTACK_COURSES:
        eligible = [r for r in non1 if sample_ok(r, attack_course)]
        print("\n" + "=" * 126)
        print(f"【{attack_course}コース攻め率帯】 sample_n>={MIN_SAMPLE} 母体={len(eligible)}")
        print("=" * 126)

        for label, low, high in BANDS:
            selected = [
                r for r in eligible
                if in_band(attack(r, attack_course), low, high)
            ]
            if not selected:
                continue
            print_dist(f"{attack_course}C攻め {label}", selected, base)

    print("\n" + "=" * 126)
    print("集計完了")
    print("見る点: 条件コース自身の1着率上昇、隣接コース連動、攻め率帯の単調性")
    print("=" * 126)


if __name__ == "__main__":
    main()
