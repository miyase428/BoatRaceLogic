#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
現行Webがイン飛びを予想（honmei_head != 1）したレースを対象に、
まだ未整理だった2コース・6コースの頭向けkimarite指標が
自身1着率とどれだけ連動するかを前半/後半で確認する。

確認する特徴:
- 2C 差し率
- 6C まくり差し率
- 6C 攻め率 = まくり + まくり差し

条件:
- 6month point-in-time
- 対象コース sample_n >= 10
- actual_* は評価ラベルとしてのみ利用

Usage:
  python3 analysis/validate_web_non_lane1_course2_6_head_kimarite_periods.py \
    analysis/output/kimarite_analysis_dataset_20250815_20260814.csv
"""

from __future__ import annotations

import csv
import sys
from datetime import date, datetime
from pathlib import Path

MIN_SAMPLE = 10
THRESHOLDS = (5, 10, 15, 20, 25)
SPLIT = date(2026, 2, 15)


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


def read_csv(path: Path):
    with path.open('r', encoding='utf-8-sig', newline='') as f:
        return list(csv.DictReader(f))


def parse_date(row):
    return datetime.strptime((row.get('race_date') or '').strip(), '%Y-%m-%d').date()


def formal(row):
    return (
        to_int(row.get('result_top3_course_complete')) == 1
        and to_int(row.get('result_boat_match')) == 1
    )


def sample_ok(row, course):
    return to_int(row.get(f'c{course}_6m_sample_n')) >= MIN_SAMPLE


def feature_value(row, name):
    if name == '2C差し率':
        return to_float(row.get('c2_6m_sashi'))
    if name == '6Cまくり差し率':
        return to_float(row.get('c6_6m_makurizashi'))
    if name == '6C攻め率':
        return (
            to_float(row.get('c6_6m_makuri'))
            + to_float(row.get('c6_6m_makurizashi'))
        )
    raise ValueError(name)


def target_course(name):
    return 2 if name.startswith('2C') else 6


def analyze(rows, name):
    course = target_course(name)
    eligible = [r for r in rows if sample_ok(r, course)]
    base_n = len(eligible)
    base_hit = sum(to_int(r.get('actual_1st_course')) == course for r in eligible)
    base_rate = pct(base_hit, base_n)

    out = []
    for th in THRESHOLDS:
        selected = [r for r in eligible if feature_value(r, name) >= th]
        n = len(selected)
        hit = sum(to_int(r.get('actual_1st_course')) == course for r in selected)
        rate = pct(hit, n)
        lift = (rate / base_rate) if base_rate else 0.0
        out.append((th, n, base_n, rate, base_rate, rate - base_rate, lift))
    return out


def print_section(name, periods):
    print('\n' + '-' * 140)
    print(f'【{name}】')
    print('-' * 140)
    print('期間          条件             N/母体       自身1着率   基礎率      差pt     Lift')
    print('-' * 140)
    for label, rows in periods:
        for th, n, base_n, rate, base_rate, diff, lift in analyze(rows, name):
            print(
                f'{label:<12}  >= {th:>2}%      '
                f'{n:>5}/{base_n:<5}    '
                f'{rate:>7.2f}%   {base_rate:>6.2f}%   '
                f'{diff:+7.2f}   {lift:>6.3f}'
            )


def main():
    if len(sys.argv) != 2:
        print(f'Usage: python3 {sys.argv[0]} DATASET_CSV', file=sys.stderr)
        sys.exit(1)

    path = Path(sys.argv[1]).resolve()
    if not path.is_file():
        raise RuntimeError(f'CSVがありません: {path}')

    rows = [
        r for r in read_csv(path)
        if formal(r) and to_int(r.get('honmei_head')) != 1
    ]
    if not rows:
        raise RuntimeError('対象データがありません')

    dates = sorted(parse_date(r) for r in rows)
    front = [r for r in rows if parse_date(r) < SPLIT]
    back = [r for r in rows if parse_date(r) >= SPLIT]

    periods = [
        ('全1年', rows),
        ('前半6か月', front),
        ('後半6か月', back),
    ]

    print('\n' + '=' * 140)
    print('現行Webイン飛び予想時 2C/6C 頭向けkimarite 期間分割検証')
    print('=' * 140)
    print(f'データ期間 : {dates[0]} ～ {dates[-1]}')
    print(f'Web本命非1 : {len(rows)}')
    print(f'前半       : {len(front)}')
    print(f'後半       : {len(back)}')
    print(f'条件       : 6month point-in-time / sample_n >= {MIN_SAMPLE}')
    print('見る点     : 前半・後半とも自身1着率が上昇するか、6Cは「まくり差し単独」と「攻め率」のどちらが素直か')

    for name in ('2C差し率', '6Cまくり差し率', '6C攻め率'):
        print_section(name, periods)

    print('\n' + '=' * 140)
    print('検証完了')
    print('次STEP: 再現した2C/6C特徴を3～5C攻め率と合わせ、外頭候補2～6の統合順位モデル候補へ')
    print('=' * 140)


if __name__ == '__main__':
    main()
