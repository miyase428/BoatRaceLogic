#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
STEP9.5 筋舟券の「実際にその決まり手で勝った後」の傾向を、
レース前のpoint-in-time決まり手率へ接続して予測利用できるか検証する。

目的:
- 画像由来の筋舟券を、そのまま信じず実データで再構成する
- 前半/後半で再現する展開筋だけ残す
- 芦屋6Rで見逃した 5-1-6 型を明示的に検証する

検証対象（実結果ラベルは評価にのみ使用）:
- 2Cまくり      : 2-3-* / 2-4-* / 2-{3,4}-*
- 2C差し        : 2-1-* / 2-3-*
- 3Cまくり      : 3-4-* / 3-1-* / 3-{1,4,5}-*
- 3Cまくり差し  : 3-1-* / 3-4-*
- 4Cまくり      : 4-5-* / 4-1-* / 4-5-{1,6}
- 4Cまくり差し  : 4-1-* / 4-3-* / 4-5-*
- 5Cまくり差し  : 5-1-* / 5-4-* / 5-1-6  ← 芦屋6R型
- 6Cまくり差し  : 6-1-*

条件:
- 対象コースの6か月sample_n >= 10
- レース前6か月決まり手率 >= 5 / 10 / 15 / 20%
- 正式分析対象のみ

Usage:
  python3 analysis/validate_suji_ticket_prerace_patterns.py \
    analysis/output/kimarite_analysis_dataset_20250815_20260814.csv
"""

from __future__ import annotations

import csv
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Callable

SPLIT_DATE = "2026-02-15"
THRESHOLDS = (5.0, 10.0, 15.0, 20.0)
MIN_SAMPLE = 10


def inum(row: dict[str, str], key: str, default: int = 0) -> int:
    try:
        return int(float(row.get(key, "") or default))
    except (TypeError, ValueError):
        return default


def fnum(row: dict[str, str], key: str, default: float = 0.0) -> float:
    try:
        return float(row.get(key, "") or default)
    except (TypeError, ValueError):
        return default


def formal(row: dict[str, str]) -> bool:
    return (
        inum(row, "result_top3_course_complete") == 1
        and inum(row, "result_boat_match") == 1
    )


def pct(n: int, d: int) -> float:
    return 100.0 * n / d if d else 0.0


def lift(cond_rate: float, base_rate: float) -> float:
    return cond_rate / base_rate if base_rate > 0 else 0.0


def top3(row: dict[str, str]) -> tuple[int, int, int]:
    return (
        inum(row, "actual_1st_course"),
        inum(row, "actual_2nd_course"),
        inum(row, "actual_3rd_course"),
    )


def feature(row: dict[str, str], course: int, technique: str) -> float:
    return fnum(row, f"c{course}_6m_{technique}")


def sample_ok(row: dict[str, str], course: int) -> bool:
    return inum(row, f"c{course}_6m_sample_n") >= MIN_SAMPLE


@dataclass(frozen=True)
class Pattern:
    label: str
    fn: Callable[[tuple[int, int, int]], bool]


@dataclass(frozen=True)
class Scenario:
    label: str
    course: int
    technique: str
    patterns: tuple[Pattern, ...]


def exact(a: int, b: int, c: int) -> Callable[[tuple[int, int, int]], bool]:
    return lambda t: t == (a, b, c)


def head_second(a: int, b: int) -> Callable[[tuple[int, int, int]], bool]:
    return lambda t: t[0] == a and t[1] == b


def head_second_in(a: int, seconds: set[int]) -> Callable[[tuple[int, int, int]], bool]:
    return lambda t: t[0] == a and t[1] in seconds


def head_second_third_in(
    a: int, b: int, thirds: set[int]
) -> Callable[[tuple[int, int, int]], bool]:
    return lambda t: t[0] == a and t[1] == b and t[2] in thirds


SCENARIOS = (
    Scenario(
        "2Cまくり",
        2,
        "makuri",
        (
            Pattern("2-3-*", head_second(2, 3)),
            Pattern("2-4-*", head_second(2, 4)),
            Pattern("2-{3,4}-*", head_second_in(2, {3, 4})),
        ),
    ),
    Scenario(
        "2C差し",
        2,
        "sashi",
        (
            Pattern("2-1-*", head_second(2, 1)),
            Pattern("2-3-*", head_second(2, 3)),
        ),
    ),
    Scenario(
        "3Cまくり",
        3,
        "makuri",
        (
            Pattern("3-4-*", head_second(3, 4)),
            Pattern("3-1-*", head_second(3, 1)),
            Pattern("3-{1,4,5}-*", head_second_in(3, {1, 4, 5})),
        ),
    ),
    Scenario(
        "3Cまくり差し",
        3,
        "makurizashi",
        (
            Pattern("3-1-*", head_second(3, 1)),
            Pattern("3-4-*", head_second(3, 4)),
        ),
    ),
    Scenario(
        "4Cまくり",
        4,
        "makuri",
        (
            Pattern("4-5-*", head_second(4, 5)),
            Pattern("4-1-*", head_second(4, 1)),
            Pattern("4-5-{1,6}", head_second_third_in(4, 5, {1, 6})),
        ),
    ),
    Scenario(
        "4Cまくり差し",
        4,
        "makurizashi",
        (
            Pattern("4-1-*", head_second(4, 1)),
            Pattern("4-3-*", head_second(4, 3)),
            Pattern("4-5-*", head_second(4, 5)),
        ),
    ),
    Scenario(
        "5Cまくり差し",
        5,
        "makurizashi",
        (
            Pattern("5-1-*", head_second(5, 1)),
            Pattern("5-4-*", head_second(5, 4)),
            Pattern("5-1-6【芦屋6R型】", exact(5, 1, 6)),
        ),
    ),
    Scenario(
        "6Cまくり差し",
        6,
        "makurizashi",
        (
            Pattern("6-1-*", head_second(6, 1)),
        ),
    ),
)


def load_rows(path: Path) -> list[dict[str, str]]:
    with path.open("r", encoding="utf-8-sig", newline="") as f:
        return [r for r in csv.DictReader(f) if formal(r)]


def period_rows(rows: list[dict[str, str]], period: str) -> list[dict[str, str]]:
    if period == "front":
        return [r for r in rows if (r.get("race_date") or "") < SPLIT_DATE]
    if period == "back":
        return [r for r in rows if (r.get("race_date") or "") >= SPLIT_DATE]
    return rows


def evaluate(
    rows: list[dict[str, str]], scenario: Scenario, threshold: float
) -> tuple[int, int, list[tuple[Pattern, int, int, float, float, float, float]]]:
    base = [r for r in rows if sample_ok(r, scenario.course)]
    cond = [
        r
        for r in base
        if feature(r, scenario.course, scenario.technique) >= threshold
    ]

    out = []
    for pattern in scenario.patterns:
        base_hits = sum(1 for r in base if pattern.fn(top3(r)))
        cond_hits = sum(1 for r in cond if pattern.fn(top3(r)))
        br = pct(base_hits, len(base))
        cr = pct(cond_hits, len(cond))
        out.append((
            pattern,
            base_hits,
            cond_hits,
            br,
            cr,
            cr - br,
            lift(cr, br),
        ))
    return len(base), len(cond), out


def main() -> None:
    if len(sys.argv) != 2:
        print(
            "Usage: python3 analysis/validate_suji_ticket_prerace_patterns.py DATASET_CSV",
            file=sys.stderr,
        )
        sys.exit(1)

    path = Path(sys.argv[1]).resolve()
    if not path.is_file():
        raise RuntimeError(f"CSVがありません: {path}")

    rows = load_rows(path)
    front = period_rows(rows, "front")
    back = period_rows(rows, "back")

    print("=" * 148)
    print("STEP9.5 筋舟券 pre-race決まり手率 → 出目筋 再現性検証")
    print("=" * 148)
    print(f"CSV          : {path.name}")
    print(f"正式分析対象 : {len(rows)}")
    print(f"前半         : {len(front)}  (～2026-02-14)")
    print(f"後半         : {len(back)}  (2026-02-15～)")
    print(f"sample条件   : 対象コース6m sample_n >= {MIN_SAMPLE}")
    print(f"閾値         : {' / '.join(f'>={int(x)}%' for x in THRESHOLDS)}")
    print("actual着順   : 評価ラベルとしてのみ使用")
    print("重点         : 5Cまくり差し率 → 5-1-* / 5-1-6（芦屋6R型）")

    for scenario in SCENARIOS:
        print("\n" + "=" * 148)
        print(f"【{scenario.label}】 feature=c{scenario.course}_6m_{scenario.technique}")
        print("=" * 148)

        for threshold in THRESHOLDS:
            print(f"\n--- 決まり手率 >= {threshold:.0f}% ---")
            print(
                "期間   BASE_N  条件N   出目筋                 BASE率    条件率     差pt    Lift   条件hit"
            )
            print("-" * 112)

            for period_label, source in (
                ("前半", front),
                ("後半", back),
                ("全体", rows),
            ):
                base_n, cond_n, metrics = evaluate(source, scenario, threshold)
                for idx, (pattern, _bh, ch, br, cr, delta, lf) in enumerate(metrics):
                    plabel = period_label if idx == 0 else ""
                    print(
                        f"{plabel:<4} {base_n:7d} {cond_n:6d}   "
                        f"{pattern.label:<22} "
                        f"{br:7.3f}% {cr:7.3f}% {delta:+7.3f} {lf:7.3f} {ch:8d}"
                    )

    print("\n" + "=" * 148)
    print("芦屋6R型の判断ポイント")
    print("=" * 148)
    print("1. 5Cまくり差し率が高いほど 5-1-* が前後半とも上がるか")
    print("2. 5-1-6 が前後半とも同方向で、十分な条件hit数を持つか")
    print("3. exact 5-1-6 が不安定でも 5-1-* が安定なら、穴シナリオは形成単位で残す")
    print("4. このSTEPでは本番ロジックへ入れず、再現する筋だけ次のWeb買い目検証へ進める")
    print("=" * 148)


if __name__ == "__main__":
    main()
