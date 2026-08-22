#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
1逃げ成立時について、3～5コースの攻め率（6month point-in-time
まくり率 + まくり差し率）の閾値候補を前半/後半で再検証する。

目的:
- 10% / 15% / 20% / 25% などの候補から、Nと再現性を両立する閾値を固定する。
- 対象コース自身の2着率・3着率・2or3率を、同期間のsample_n>=10母体と比較する。

使い方:
  python3 analysis/validate_lane1_escape_attack_threshold_candidates.py \
    analysis/output/kimarite_analysis_dataset_20250815_20260814.csv
"""

from __future__ import annotations

import csv
import sys
from calendar import monthrange
from datetime import date, datetime, timedelta
from pathlib import Path


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


def months_after(d: date, months: int) -> date:
    total = d.year * 12 + (d.month - 1) + months
    year = total // 12
    month = total % 12 + 1
    day = min(d.day, monthrange(year, month)[1])
    return date(year, month, day)


def load_rows(path: Path) -> list[dict[str, str]]:
    with path.open("r", encoding="utf-8-sig", newline="") as f:
        return list(csv.DictReader(f))


def row_date(row: dict[str, str]) -> date:
    return datetime.strptime((row.get("race_date") or "").strip(), "%Y-%m-%d").date()


def is_formal(row: dict[str, str]) -> bool:
    return (
        to_int(row.get("result_top3_course_complete")) == 1
        and to_int(row.get("result_boat_match")) == 1
    )


def is_escape(row: dict[str, str]) -> bool:
    return (
        is_formal(row)
        and to_int(row.get("actual_1st_course")) == 1
        and (row.get("winner_technique") or "").strip() == "逃げ"
    )


def sample_ok(row: dict[str, str], course: int) -> bool:
    return to_int(row.get(f"c{course}_6m_sample_n")) >= 10


def attack(row: dict[str, str], course: int) -> float:
    return (
        to_float(row.get(f"c{course}_6m_makuri"))
        + to_float(row.get(f"c{course}_6m_makurizashi"))
    )


def outcome_rates(rows: list[dict[str, str]], course: int) -> tuple[float, float, float]:
    n = len(rows)
    if not n:
        return 0.0, 0.0, 0.0
    second = sum(to_int(r.get("actual_2nd_course")) == course for r in rows)
    third = sum(to_int(r.get("actual_3rd_course")) == course for r in rows)
    return pct(second, n), pct(third, n), pct(second + third, n)


def print_one_period(
    label: str,
    period_rows: list[dict[str, str]],
    course: int,
    threshold: float,
) -> None:
    base = [r for r in period_rows if sample_ok(r, course)]
    selected = [r for r in base if attack(r, course) >= threshold]

    b2, b3, b23 = outcome_rates(base, course)
    s2, s3, s23 = outcome_rates(selected, course)
    lift = s23 / b23 if b23 else 0.0

    print(
        f"{label:<12} "
        f"N={len(selected):>5}/{len(base):<5} ({pct(len(selected), len(base)):>6.2f}%)  "
        f"2着 {s2:>6.2f}% ({s2-b2:>+6.2f}pt)  "
        f"3着 {s3:>6.2f}% ({s3-b3:>+6.2f}pt)  "
        f"2or3 {s23:>6.2f}% ({s23-b23:>+6.2f}pt L{lift:>5.3f})"
    )


def main() -> None:
    if len(sys.argv) != 2:
        print(
            "Usage: python3 analysis/validate_lane1_escape_attack_threshold_candidates.py DATASET_CSV",
            file=sys.stderr,
        )
        sys.exit(1)

    path = Path(sys.argv[1]).resolve()
    if not path.is_file():
        raise RuntimeError(f"CSVがありません: {path}")

    rows = load_rows(path)
    escape_rows = [r for r in rows if is_escape(r)]
    if not escape_rows:
        raise RuntimeError("1逃げ対象がありません")

    dates = [row_date(r) for r in escape_rows]
    start = min(dates)
    end = max(dates)
    second_start = months_after(start, 6)
    first_end = second_start - timedelta(days=1)

    first = [r for r in escape_rows if start <= row_date(r) <= first_end]
    second = [r for r in escape_rows if second_start <= row_date(r) <= end]

    candidates = {
        3: [10, 15, 20],
        4: [10, 15, 20, 25],
        5: [5, 10, 15, 20, 25],
    }

    print("\n" + "=" * 142)
    print("1逃げ時 3～5コース攻め率 閾値候補の期間分割検証")
    print("=" * 142)
    print(f"データ期間 : {start} ～ {end}")
    print(f"前半       : {start} ～ {first_end}  1逃げN={len(first)}")
    print(f"後半       : {second_start} ～ {end}  1逃げN={len(second)}")
    print("条件       : 6month point-in-time 攻め率、対象コース sample_n>=10")

    for course, thresholds in candidates.items():
        print("\n" + "-" * 142)
        print(f"【{course}コース】")
        print("-" * 142)
        for th in thresholds:
            print(f"\n{course}C 攻め率 >= {th}%")
            print_one_period("全1年", escape_rows, course, th)
            print_one_period("前半6か月", first, course, th)
            print_one_period("後半6か月", second, course, th)

    print("\n" + "=" * 142)
    print("閾値候補検証完了")
    print("判定目安: 前半・後半とも同方向、Nを十分確保、上位閾値で効果が維持される境界を採用")
    print("=" * 142)


if __name__ == "__main__":
    main()
