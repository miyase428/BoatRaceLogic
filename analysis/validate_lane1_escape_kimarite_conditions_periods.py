#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
1逃げ成立時に有望だった3〜5コース攻め率条件について、
1年データを前半6か月 / 後半6か月に分けて再現性を確認する。

対象条件:
- 3コース攻め率 >= 10%, 20%
- 4コース攻め率 >= 10%, 20%
- 5コース攻め率 >= 10%, 20%

攻め率 = 6month point-in-time の まくり率 + まくり差し率
対象コース sample_n >= 10

使い方:
  python3 analysis/validate_lane1_escape_kimarite_conditions_periods.py \
    analysis/output/kimarite_analysis_dataset_20250815_20260814.csv
"""

from __future__ import annotations

import csv
import sys
from datetime import datetime, timedelta
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
    return (100.0 * n / d) if d else 0.0


def add_months(d, months: int):
    total = d.year * 12 + (d.month - 1) + months
    year = total // 12
    month = total % 12 + 1
    # 今回は15日始まりなので月末補正は実質不要だが安全側で処理
    from calendar import monthrange
    day = min(d.day, monthrange(year, month)[1])
    return d.replace(year=year, month=month, day=day)


def load_rows(path: Path):
    with path.open('r', encoding='utf-8-sig', newline='') as f:
        return list(csv.DictReader(f))


def race_date(row):
    return datetime.strptime((row.get('race_date') or '').strip(), '%Y-%m-%d').date()


def is_formal(row):
    return (
        to_int(row.get('result_top3_course_complete')) == 1
        and to_int(row.get('result_boat_match')) == 1
    )


def is_escape(row):
    return (
        is_formal(row)
        and to_int(row.get('actual_1st_course')) == 1
        and (row.get('winner_technique') or '').strip() == '逃げ'
    )


def attack(row, course: int) -> float:
    return (
        to_float(row.get(f'c{course}_6m_makuri'))
        + to_float(row.get(f'c{course}_6m_makurizashi'))
    )


def sample_ok(row, course: int, minimum: int = 10) -> bool:
    return to_int(row.get(f'c{course}_6m_sample_n')) >= minimum


def target_rates(rows, course: int):
    n = len(rows)
    second = sum(to_int(r.get('actual_2nd_course')) == course for r in rows)
    third = sum(to_int(r.get('actual_3rd_course')) == course for r in rows)
    return {
        'n': n,
        'second_n': second,
        'third_n': third,
        'second': pct(second, n),
        'third': pct(third, n),
        'top23': pct(second + third, n),
    }


def print_one(period_label, period_rows, course: int, threshold: float):
    base = target_rates(period_rows, course)
    selected = [
        r for r in period_rows
        if sample_ok(r, course) and attack(r, course) >= threshold
    ]
    cur = target_rates(selected, course)

    def lift(cur_rate, base_rate):
        return (cur_rate / base_rate) if base_rate else 0.0

    print(
        f'{period_label:<20} '
        f'{course}C>={threshold:>4.0f}%  '
        f'N={cur["n"]:>5}/{base["n"]:<5}  '
        f'2着 {cur["second"]:>6.2f}% ({cur["second"]-base["second"]:>+6.2f}pt L{lift(cur["second"], base["second"]):.3f})  '
        f'3着 {cur["third"]:>6.2f}% ({cur["third"]-base["third"]:>+6.2f}pt L{lift(cur["third"], base["third"]):.3f})  '
        f'2or3 {cur["top23"]:>6.2f}% ({cur["top23"]-base["top23"]:>+6.2f}pt L{lift(cur["top23"], base["top23"]):.3f})'
    )


def main():
    if len(sys.argv) != 2:
        print(
            'Usage: python3 analysis/validate_lane1_escape_kimarite_conditions_periods.py DATASET_CSV',
            file=sys.stderr,
        )
        sys.exit(1)

    path = Path(sys.argv[1]).resolve()
    if not path.is_file():
        raise RuntimeError(f'CSVがありません: {path}')

    rows = load_rows(path)
    escape_rows = [r for r in rows if is_escape(r)]
    if not escape_rows:
        raise RuntimeError('1逃げ分析対象がありません')

    dates = [race_date(r) for r in escape_rows]
    start = min(dates)
    end = max(dates)
    second_start = add_months(start, 6)
    first_end = second_start - timedelta(days=1)

    periods = [
        ('全1年', start, end),
        ('前半6か月', start, first_end),
        ('後半6か月', second_start, end),
    ]

    print('\n' + '=' * 150)
    print('1逃げ時 kimarite攻め率条件 期間分割再現性確認')
    print('=' * 150)
    print(f'データ期間   : {start} ～ {end}')
    print(f'前半6か月   : {start} ～ {first_end}')
    print(f'後半6か月   : {second_start} ～ {end}')
    print(f'1逃げ総数   : {len(escape_rows)}')
    print('条件         : 6month point-in-time 攻め率（まくり+まくり差し）、sample_n >= 10')
    print()

    for course in (3, 4, 5):
        print('-' * 150)
        print(f'【{course}コース】')
        print('-' * 150)
        for threshold in (10.0, 20.0):
            for label, p_start, p_end in periods:
                subset = [r for r in escape_rows if p_start <= race_date(r) <= p_end]
                print_one(label, subset, course, threshold)
            print()

    print('=' * 150)
    print('期間分割検証完了')
    print('判定目安: 前半・後半とも同方向で、Nを確保しつつ差pt/Liftが維持される条件を実装候補へ')
    print('=' * 150)


if __name__ == '__main__':
    main()
