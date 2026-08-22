#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
STEP6で見えた「攻め艇本人↑ / 直内コース↓ / 1C↓」の着順連動を
前半6か月・後半6か月に分けて再現性確認する。

対象:
- 正式分析対象のみ
- 攻め起点: 3C / 4C / 5C
- 6month point-in-time 攻め率 = makuri + makurizashi
- 攻め起点コース sample_n >= 10
- 閾値: >=10 / >=15 / >=20%

見る指標:
- 1C 1着率
- 攻め艇本人 1着率 / 3連対率
- 直内コース 3連対率
- 直外コース 3連対率（連動有無の参考）

Usage:
  python3 analysis/validate_attack_chain_finish_distribution_periods.py \
    analysis/output/kimarite_analysis_dataset_20250815_20260814.csv
"""

from __future__ import annotations

import csv
import sys
from datetime import date, datetime
from pathlib import Path

SPLIT = date(2026, 2, 15)
ATTACKERS = (3, 4, 5)
THRESHOLDS = (10.0, 15.0, 20.0)
MIN_SAMPLE = 10


def to_int(v, default=0):
    try:
        return int(float(v))
    except (TypeError, ValueError):
        return default


def to_float(v, default=0.0):
    try:
        return float(v)
    except (TypeError, ValueError):
        return default


def pct(n, d):
    return 100.0 * n / d if d else 0.0


def parse_date(row):
    return datetime.strptime((row.get('race_date') or '').strip(), '%Y-%m-%d').date()


def formal(row):
    return (
        to_int(row.get('result_top3_course_complete')) == 1
        and to_int(row.get('result_boat_match')) == 1
    )


def sample_ok(row, c):
    return to_int(row.get(f'c{c}_6m_sample_n')) >= MIN_SAMPLE


def attack(row, c):
    return (
        to_float(row.get(f'c{c}_6m_makuri'))
        + to_float(row.get(f'c{c}_6m_makurizashi'))
    )


def top3_hit(row, c):
    return c in (
        to_int(row.get('actual_1st_course')),
        to_int(row.get('actual_2nd_course')),
        to_int(row.get('actual_3rd_course')),
    )


def first_hit(row, c):
    return to_int(row.get('actual_1st_course')) == c


def calc(rows, attacker):
    n = len(rows)
    inner = attacker - 1
    outer = attacker + 1
    return {
        'n': n,
        'c1_win': pct(sum(first_hit(r, 1) for r in rows), n),
        'self_win': pct(sum(first_hit(r, attacker) for r in rows), n),
        'self_top3': pct(sum(top3_hit(r, attacker) for r in rows), n),
        'inner_top3': pct(sum(top3_hit(r, inner) for r in rows), n),
        'outer_top3': pct(sum(top3_hit(r, outer) for r in rows), n),
    }


def fmt_delta(v, base):
    return f'{v:6.2f}% ({v-base:+6.2f}pt)'


def print_period(label, rows):
    print('\n' + '=' * 132)
    print(f'【{label}】 N={len(rows)}')
    print('=' * 132)

    for attacker in ATTACKERS:
        base_rows = [r for r in rows if sample_ok(r, attacker)]
        base = calc(base_rows, attacker)

        print(f'\n--- {attacker}C攻め起点 / 基礎N={base["n"]} ---')
        print(
            '条件       N       1C1着               '
            f'{attacker}C本人1着          {attacker}C本人3連対       '
            f'{attacker-1}C直内3連対       {attacker+1}C直外3連対'
        )
        print('-' * 132)
        print(
            f'BASE   {base["n"]:>7}   '
            f'{base["c1_win"]:6.2f}%             '
            f'{base["self_win"]:6.2f}%             '
            f'{base["self_top3"]:6.2f}%             '
            f'{base["inner_top3"]:6.2f}%             '
            f'{base["outer_top3"]:6.2f}%'
        )

        for th in THRESHOLDS:
            cond_rows = [r for r in base_rows if attack(r, attacker) >= th]
            s = calc(cond_rows, attacker)
            print(
                f'>={int(th):>2}%  {s["n"]:>7}   '
                f'{fmt_delta(s["c1_win"], base["c1_win"]):<20} '
                f'{fmt_delta(s["self_win"], base["self_win"]):<20} '
                f'{fmt_delta(s["self_top3"], base["self_top3"]):<20} '
                f'{fmt_delta(s["inner_top3"], base["inner_top3"]):<20} '
                f'{fmt_delta(s["outer_top3"], base["outer_top3"]):<20}'
            )


def main():
    if len(sys.argv) != 2:
        print(
            'Usage: python3 analysis/validate_attack_chain_finish_distribution_periods.py DATASET_CSV',
            file=sys.stderr,
        )
        sys.exit(1)

    path = Path(sys.argv[1]).resolve()
    if not path.is_file():
        raise RuntimeError(f'CSVがありません: {path}')

    with path.open('r', encoding='utf-8-sig', newline='') as f:
        rows = [r for r in csv.DictReader(f) if formal(r)]

    front = [r for r in rows if parse_date(r) < SPLIT]
    back = [r for r in rows if parse_date(r) >= SPLIT]

    dates = sorted(parse_date(r) for r in rows)

    print('\n' + '=' * 132)
    print('STEP6 攻め率 → 着順連動 期間分割再現性検証')
    print('=' * 132)
    print(f'全期間     : {dates[0]} ～ {dates[-1]}')
    print(f'正式対象   : {len(rows)}')
    print(f'前半       : {len(front)}  ({dates[0]} ～ 2026-02-14)')
    print(f'後半       : {len(back)}  (2026-02-15 ～ {dates[-1]})')
    print('特徴       : 3～5C 6month point-in-time 攻め率 = まくり + まくり差し')
    print(f'sample条件 : 攻め起点 sample_n >= {MIN_SAMPLE}')
    print('確認       : 本人↑・直内↓・1C↓が前半/後半とも同方向か。直外は連動有無の参考。')

    print_period('前半6か月', front)
    print_period('後半6か月', back)

    print('\n' + '=' * 132)
    print('期間分割検証完了')
    print('次STEP: 前後半で再現した連動だけをSTEP7の具体的な3連単出目条件へ落とす。')
    print('=' * 132)


if __name__ == '__main__':
    main()
