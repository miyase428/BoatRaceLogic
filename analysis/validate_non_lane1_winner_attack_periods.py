#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
イン飛び時に、3～5コースのpoint-in-time攻め率が
そのコース自身の1着率へ与える影響を期間分割で確認する。

目的:
- 全1年で見えた「攻め率が高いほど自身1着率が上がる」傾向の再現性確認
- 前半6か月 / 後半6か月で同方向か確認
- 将来のイン飛び時頭候補補正に使える閾値候補を絞る

条件:
- 正式結果完備
- 実際の1着コース != 1（イン飛び）
- 3～5コースの6month point-in-time 攻め率 = まくり + まくり差し
- 対象コース sample_n >= 10

使い方:
  python3 analysis/validate_non_lane1_winner_attack_periods.py \
    analysis/output/kimarite_analysis_dataset_20250815_20260814.csv
"""

from __future__ import annotations

import csv
import sys
from datetime import date, datetime
from pathlib import Path

COURSES = (3, 4, 5)
THRESHOLDS = (10.0, 15.0, 20.0, 25.0)
MIN_SAMPLE = 10
SPLIT = date(2026, 2, 15)


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


def load_rows(path: Path) -> list[dict[str, str]]:
    with path.open('r', encoding='utf-8-sig', newline='') as f:
        return list(csv.DictReader(f))


def parse_date(row: dict[str, str]) -> date:
    return datetime.strptime((row.get('race_date') or '').strip(), '%Y-%m-%d').date()


def is_formal(row: dict[str, str]) -> bool:
    return (
        to_int(row.get('result_top3_course_complete')) == 1
        and to_int(row.get('result_boat_match')) == 1
    )


def is_non_lane1_winner(row: dict[str, str]) -> bool:
    return is_formal(row) and to_int(row.get('actual_1st_course')) in (2, 3, 4, 5, 6)


def sample_ok(row: dict[str, str], course: int) -> bool:
    return to_int(row.get(f'c{course}_6m_sample_n')) >= MIN_SAMPLE


def attack(row: dict[str, str], course: int) -> float:
    return (
        to_float(row.get(f'c{course}_6m_makuri'))
        + to_float(row.get(f'c{course}_6m_makurizashi'))
    )


def period_rows(rows: list[dict[str, str]], mode: str) -> list[dict[str, str]]:
    if mode == 'all':
        return rows
    if mode == 'first':
        return [r for r in rows if parse_date(r) < SPLIT]
    if mode == 'second':
        return [r for r in rows if parse_date(r) >= SPLIT]
    raise ValueError(mode)


def self_win_rate(rows: list[dict[str, str]], course: int) -> tuple[int, int, float]:
    n = len(rows)
    wins = sum(1 for r in rows if to_int(r.get('actual_1st_course')) == course)
    return wins, n, pct(wins, n)


def print_course(rows: list[dict[str, str]], course: int) -> None:
    print('\n' + '-' * 140)
    print(f'【{course}コース】')
    print('-' * 140)
    print('期間          条件             N/母体       自身1着率   基礎率      差pt     Lift')
    print('-' * 140)

    for label, mode in (
        ('全1年', 'all'),
        ('前半6か月', 'first'),
        ('後半6か月', 'second'),
    ):
        pr = period_rows(rows, mode)
        base = [r for r in pr if sample_ok(r, course)]
        _, base_n, base_rate = self_win_rate(base, course)

        for th in THRESHOLDS:
            selected = [r for r in base if attack(r, course) >= th]
            wins, n, rate = self_win_rate(selected, course)
            lift = (rate / base_rate) if base_rate else 0.0
            print(
                f'{label:<12}  攻め率>={th:>2.0f}%   '
                f'{n:>5}/{base_n:<5}  '
                f'{rate:>8.2f}%  {base_rate:>7.2f}%  '
                f'{rate-base_rate:>+7.2f}  {lift:>7.3f}'
            )


def main() -> None:
    if len(sys.argv) != 2:
        print(
            'Usage: python3 analysis/validate_non_lane1_winner_attack_periods.py DATASET_CSV',
            file=sys.stderr,
        )
        sys.exit(1)

    path = Path(sys.argv[1]).resolve()
    if not path.is_file():
        raise RuntimeError(f'CSVがありません: {path}')

    all_rows = load_rows(path)
    rows = [r for r in all_rows if is_non_lane1_winner(r)]
    if not rows:
        raise RuntimeError('イン飛び対象がありません')

    dates = sorted(parse_date(r) for r in rows)
    first = period_rows(rows, 'first')
    second = period_rows(rows, 'second')

    print('\n' + '=' * 140)
    print('イン飛び時 3～5コース攻め率 → 自身1着率 期間分割再現性確認')
    print('=' * 140)
    print(f'データ期間 : {dates[0]} ～ {dates[-1]}')
    print(f'イン飛びN  : {len(rows)}')
    print(f'前半       : {dates[0]} ～ 2026-02-14  N={len(first)}')
    print(f'後半       : 2026-02-15 ～ {dates[-1]}  N={len(second)}')
    print(f'条件       : 6month point-in-time 攻め率（まくり+まくり差し）、sample_n>={MIN_SAMPLE}')
    print('判定       : 前半・後半とも同方向で、上位閾値でも自身1着率が維持/上昇するかを見る')

    for course in COURSES:
        print_course(rows, course)

    print('\n' + '=' * 140)
    print('期間分割検証完了')
    print('次STEP: 再現したコース/閾値だけを、現行Webの事前「イン飛び候補」レースへ接続して頭順位改善を検証')
    print('=' * 140)


if __name__ == '__main__':
    main()
