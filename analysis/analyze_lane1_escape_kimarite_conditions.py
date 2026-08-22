#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
1コースが実際に「逃げ」で勝ったレースについて、
事前（point-in-time）kimarite条件を掛けた時の2着・3着コース分布を
全体基礎分布と比較する。

目的:
- 2コース逃がし率が高い時に2コース残りが増えるか
- 3〜5コースの攻め率（まくり+まくり差し）が高い時に
  2・3着の連動がどう変わるか

注意:
- 条件に使うのは対象レースより前だけで集計した6month kimarite値。
- actual_* / winner_technique は結果ラベルであり、条件判定には使わない。
- まずは固定閾値で探索し、後段で期間分割/holdout確認する。

使い方:
  python3 analysis/analyze_lane1_escape_kimarite_conditions.py \
    analysis/output/kimarite_analysis_dataset_20250815_20260814.csv
"""

from __future__ import annotations

import csv
import sys
from collections import Counter
from pathlib import Path
from typing import Callable


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
    return (100.0 * n / d) if d else 0.0


def load_rows(path: Path) -> list[dict[str, str]]:
    with path.open('r', encoding='utf-8-sig', newline='') as f:
        return list(csv.DictReader(f))


def is_formal_row(row: dict[str, str]) -> bool:
    return (
        to_int(row.get('result_top3_course_complete')) == 1
        and to_int(row.get('result_boat_match')) == 1
    )


def is_lane1_escape(row: dict[str, str]) -> bool:
    return (
        is_formal_row(row)
        and to_int(row.get('actual_1st_course')) == 1
        and (row.get('winner_technique') or '').strip() == '逃げ'
    )


def attack(row: dict[str, str], course: int) -> float:
    return (
        to_float(row.get(f'c{course}_6m_makuri'))
        + to_float(row.get(f'c{course}_6m_makurizashi'))
    )


def sample_ok(row: dict[str, str], course: int, minimum: int = 10) -> bool:
    return to_int(row.get(f'c{course}_6m_sample_n')) >= minimum


def distribution(rows: list[dict[str, str]], rank: int) -> dict[int, float]:
    total = len(rows)
    counts = Counter(to_int(r.get(f'actual_{rank}nd_course' if rank == 2 else 'actual_3rd_course')) for r in rows)
    # rank==2 の列名だけ特殊なので明示的に上書き
    if rank == 2:
        counts = Counter(to_int(r.get('actual_2nd_course')) for r in rows)
    else:
        counts = Counter(to_int(r.get('actual_3rd_course')) for r in rows)
    return {course: pct(counts.get(course, 0), total) for course in range(2, 7)}


def exact_patterns(rows: list[dict[str, str]]) -> Counter[str]:
    c = Counter()
    for r in rows:
        c2 = to_int(r.get('actual_2nd_course'))
        c3 = to_int(r.get('actual_3rd_course'))
        if 2 <= c2 <= 6 and 2 <= c3 <= 6 and c2 != c3:
            c[f'1-{c2}-{c3}'] += 1
    return c


def print_condition(
    label: str,
    selected: list[dict[str, str]],
    baseline_second: dict[int, float],
    baseline_third: dict[int, float],
    baseline_patterns: Counter[str],
    baseline_n: int,
) -> None:
    n = len(selected)
    print('\n' + '=' * 118)
    print(label)
    print('=' * 118)
    print(f'N = {n} / {baseline_n} ({pct(n, baseline_n):.2f}%)')

    if n == 0:
        print('対象なし')
        return

    sec = distribution(selected, 2)
    third = distribution(selected, 3)

    print('\n【2着コース：基礎との差】')
    print('コース     条件率      基礎率       差pt      Lift')
    print('-' * 58)
    for course in range(2, 7):
        base = baseline_second[course]
        cur = sec[course]
        lift = (cur / base) if base else 0.0
        print(f'{course:>4}   {cur:>8.2f}%   {base:>8.2f}%   {cur-base:>+7.2f}   {lift:>6.3f}')

    print('\n【3着コース：基礎との差】')
    print('コース     条件率      基礎率       差pt      Lift')
    print('-' * 58)
    for course in range(2, 7):
        base = baseline_third[course]
        cur = third[course]
        lift = (cur / base) if base else 0.0
        print(f'{course:>4}   {cur:>8.2f}%   {base:>8.2f}%   {cur-base:>+7.2f}   {lift:>6.3f}')

    patterns = exact_patterns(selected)
    print('\n【出目TOP8：条件率 / 基礎率 / Lift】')
    print('出目          件数      条件率      基礎率      Lift')
    print('-' * 64)
    for pattern, count in patterns.most_common(8):
        cur_rate = pct(count, n)
        base_rate = pct(baseline_patterns.get(pattern, 0), baseline_n)
        lift = (cur_rate / base_rate) if base_rate else 0.0
        print(f'{pattern:<10} {count:>7}   {cur_rate:>8.2f}%   {base_rate:>8.2f}%   {lift:>6.3f}')


def main() -> None:
    if len(sys.argv) != 2:
        print(
            'Usage: python3 analysis/analyze_lane1_escape_kimarite_conditions.py DATASET_CSV',
            file=sys.stderr,
        )
        sys.exit(1)

    path = Path(sys.argv[1]).resolve()
    if not path.is_file():
        raise RuntimeError(f'CSVがありません: {path}')

    rows = load_rows(path)
    escape_rows = [r for r in rows if is_lane1_escape(r)]

    baseline_n = len(escape_rows)
    baseline_second = distribution(escape_rows, 2)
    baseline_third = distribution(escape_rows, 3)
    baseline_patterns = exact_patterns(escape_rows)

    print('\n' + '=' * 118)
    print('1逃げ時 kimarite条件別 2着・3着分布（6month point-in-time）')
    print('=' * 118)
    print(f'CSV行       : {len(rows)}')
    print(f'1逃げ分析母体: {baseline_n}')
    print('条件共通    : 対象コース sample_n >= 10')
    print('攻め率      : まくり率 + まくり差し率')

    conditions: list[tuple[str, Callable[[dict[str, str]], bool]]] = [
        (
            '2コース逃がし >= 40%',
            lambda r: sample_ok(r, 2) and to_float(r.get('c2_6m_nogashi')) >= 40.0,
        ),
        (
            '2コース逃がし >= 50%',
            lambda r: sample_ok(r, 2) and to_float(r.get('c2_6m_nogashi')) >= 50.0,
        ),
        (
            '2コース逃がし >= 60%',
            lambda r: sample_ok(r, 2) and to_float(r.get('c2_6m_nogashi')) >= 60.0,
        ),
        (
            '3コース攻め率 >= 10%',
            lambda r: sample_ok(r, 3) and attack(r, 3) >= 10.0,
        ),
        (
            '3コース攻め率 >= 20%',
            lambda r: sample_ok(r, 3) and attack(r, 3) >= 20.0,
        ),
        (
            '4コース攻め率 >= 10%',
            lambda r: sample_ok(r, 4) and attack(r, 4) >= 10.0,
        ),
        (
            '4コース攻め率 >= 20%',
            lambda r: sample_ok(r, 4) and attack(r, 4) >= 20.0,
        ),
        (
            '5コース攻め率 >= 10%',
            lambda r: sample_ok(r, 5) and attack(r, 5) >= 10.0,
        ),
        (
            '5コース攻め率 >= 20%',
            lambda r: sample_ok(r, 5) and attack(r, 5) >= 20.0,
        ),
    ]

    for label, fn in conditions:
        selected = [r for r in escape_rows if fn(r)]
        print_condition(
            label,
            selected,
            baseline_second,
            baseline_third,
            baseline_patterns,
            baseline_n,
        )

    print('\n' + '=' * 118)
    print('条件別集計完了')
    print('次STEP: 効果が大きくサンプルも十分な条件だけ残し、期間分割で再現性確認')
    print('=' * 118)


if __name__ == '__main__':
    main()
