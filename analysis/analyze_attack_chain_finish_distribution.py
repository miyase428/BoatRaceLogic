#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
STEP6 残り: 3～5コースの事前「攻め率」が高い時に、6コース全部の着順分布がどう動くかを見る。

狙い:
- 攻めた本人の1着率だけでなく、内側/外側コースへの連動を確認する
- 例: 4C攻め高 → 5Cの2・3着率上昇 / 3C低下 / 1C1着率低下 など
- STEP7 の具体的な出目（4-5-*, 4-*-5 等）分析へつなぐ

特徴量:
- 6month point-in-time
- 攻め率 = makuri + makurizashi
- sample_n >= 10
- 条件は事前情報だけ。actual_* は着順ラベルとしてのみ使用する

比較:
- 各攻めコースごとに sample_n>=10 の全レースを基礎母体にする
- 攻め率 >= 10 / 15 / 20% の条件群について、1～6Cそれぞれの
  1着率 / 2着率 / 3着率 / 3連対率 と基礎母体との差(pt)を表示する

使い方:
  python3 analysis/analyze_attack_chain_finish_distribution.py \
    analysis/output/kimarite_analysis_dataset_20250815_20260814.csv
"""

from __future__ import annotations

import csv
import sys
from datetime import datetime
from pathlib import Path

ATTACK_COURSES = (3, 4, 5)
TARGET_COURSES = (1, 2, 3, 4, 5, 6)
THRESHOLDS = (10.0, 15.0, 20.0)
MIN_SAMPLE = 10


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
    with path.open('r', encoding='utf-8-sig', newline='') as f:
        return list(csv.DictReader(f))


def is_formal(row) -> bool:
    return (
        to_int(row.get('result_top3_course_complete')) == 1
        and to_int(row.get('result_boat_match')) == 1
    )


def sample_ok(row, course: int) -> bool:
    return to_int(row.get(f'c{course}_6m_sample_n')) >= MIN_SAMPLE


def attack_rate(row, course: int) -> float:
    return (
        to_float(row.get(f'c{course}_6m_makuri'))
        + to_float(row.get(f'c{course}_6m_makurizashi'))
    )


def parse_date(row):
    value = (row.get('race_date') or '').strip()
    if not value:
        return None
    return datetime.strptime(value, '%Y-%m-%d').date()


def finish_stats(rows):
    out = {
        c: {'first': 0, 'second': 0, 'third': 0, 'top3': 0}
        for c in TARGET_COURSES
    }

    for row in rows:
        c1 = to_int(row.get('actual_1st_course'))
        c2 = to_int(row.get('actual_2nd_course'))
        c3 = to_int(row.get('actual_3rd_course'))

        if c1 in out:
            out[c1]['first'] += 1
            out[c1]['top3'] += 1
        if c2 in out:
            out[c2]['second'] += 1
            out[c2]['top3'] += 1
        if c3 in out:
            out[c3]['third'] += 1
            out[c3]['top3'] += 1

    return out


def rates(stats, n):
    return {
        c: {
            k: pct(v, n)
            for k, v in stats[c].items()
        }
        for c in TARGET_COURSES
    }


def cell(cond: float, base: float) -> str:
    return f'{cond:5.2f}%({cond-base:+5.2f})'


def print_condition(attacker, threshold, base_rows, base_rates, cond_rows):
    n_base = len(base_rows)
    n_cond = len(cond_rows)
    cond_rates = rates(finish_stats(cond_rows), n_cond)

    print('\n' + '-' * 132)
    print(
        f'【{attacker}C攻め率 >= {threshold:.0f}%】 '
        f'N={n_cond}/{n_base} ({pct(n_cond, n_base):.2f}%)'
    )
    print('-' * 132)
    print('対象C      1着率(差pt)        2着率(差pt)        3着率(差pt)        3連対率(差pt)')
    print('-' * 132)

    for c in TARGET_COURSES:
        print(
            f'{c:>4}C   '
            f'{cell(cond_rates[c]["first"], base_rates[c]["first"]):>17}  '
            f'{cell(cond_rates[c]["second"], base_rates[c]["second"]):>17}  '
            f'{cell(cond_rates[c]["third"], base_rates[c]["third"]):>17}  '
            f'{cell(cond_rates[c]["top3"], base_rates[c]["top3"]):>17}'
        )

    # 次のSTEPで見たい連動を、攻め艇本人・内側・外側・1Cに絞って要約。
    print('\n連動要約')
    print(
        f'  1C1着        : {cond_rates[1]["first"]:6.2f}% '
        f'({cond_rates[1]["first"]-base_rates[1]["first"]:+6.2f}pt)'
    )
    print(
        f'  {attacker}C本人1着   : {cond_rates[attacker]["first"]:6.2f}% '
        f'({cond_rates[attacker]["first"]-base_rates[attacker]["first"]:+6.2f}pt)'
    )

    inner = attacker - 1
    outer = attacker + 1

    if inner >= 1:
        print(
            f'  内側{inner}C 3連対 : {cond_rates[inner]["top3"]:6.2f}% '
            f'({cond_rates[inner]["top3"]-base_rates[inner]["top3"]:+6.2f}pt)'
        )
    if outer <= 6:
        print(
            f'  外側{outer}C 2着   : {cond_rates[outer]["second"]:6.2f}% '
            f'({cond_rates[outer]["second"]-base_rates[outer]["second"]:+6.2f}pt)'
        )
        print(
            f'  外側{outer}C 3着   : {cond_rates[outer]["third"]:6.2f}% '
            f'({cond_rates[outer]["third"]-base_rates[outer]["third"]:+6.2f}pt)'
        )
        print(
            f'  外側{outer}C 3連対 : {cond_rates[outer]["top3"]:6.2f}% '
            f'({cond_rates[outer]["top3"]-base_rates[outer]["top3"]:+6.2f}pt)'
        )


def main():
    if len(sys.argv) != 2:
        print(
            'Usage: python3 analysis/analyze_attack_chain_finish_distribution.py DATASET_CSV',
            file=sys.stderr,
        )
        sys.exit(1)

    path = Path(sys.argv[1]).resolve()
    if not path.is_file():
        raise RuntimeError(f'CSVがありません: {path}')

    all_rows = load_rows(path)
    rows = [r for r in all_rows if is_formal(r)]
    if not rows:
        raise RuntimeError('正式分析対象がありません')

    dates = sorted(d for d in (parse_date(r) for r in rows) if d is not None)

    print('\n' + '=' * 132)
    print('STEP6 攻め率条件 → 6コース全部の着順連動分布')
    print('=' * 132)
    print(f'CSV          : {path.name}')
    if dates:
        print(f'期間         : {dates[0]} ～ {dates[-1]}')
    print(f'CSV全行      : {len(all_rows)}')
    print(f'正式分析対象 : {len(rows)}')
    print('特徴         : 3～5C 6month point-in-time 攻め率 = まくり + まくり差し')
    print(f'sample条件   : 攻めコース sample_n >= {MIN_SAMPLE}')
    print('比較         : 同じsample条件の基礎母体 vs 攻め率 >= 10/15/20%')
    print('actual着順   : ラベルとしてのみ使用')

    for attacker in ATTACK_COURSES:
        base_rows = [r for r in rows if sample_ok(r, attacker)]
        base_n = len(base_rows)
        if not base_rows:
            continue

        base_rates = rates(finish_stats(base_rows), base_n)

        print('\n' + '=' * 132)
        print(f'【{attacker}Cを攻め起点にした分析】 基礎母体 N={base_n}')
        print('=' * 132)
        print('基礎着順率')
        print('対象C      1着率       2着率       3着率       3連対率')
        print('-' * 70)
        for c in TARGET_COURSES:
            print(
                f'{c:>4}C   '
                f'{base_rates[c]["first"]:8.2f}%  '
                f'{base_rates[c]["second"]:8.2f}%  '
                f'{base_rates[c]["third"]:8.2f}%  '
                f'{base_rates[c]["top3"]:9.2f}%'
            )

        for threshold in THRESHOLDS:
            cond_rows = [
                r for r in base_rows
                if attack_rate(r, attacker) >= threshold
            ]
            print_condition(
                attacker,
                threshold,
                base_rows,
                base_rates,
                cond_rows,
            )

    print('\n' + '=' * 132)
    print('分析完了')
    print('次STEP: 再現性のある連動だけを期間分割し、その後 STEP7 で具体的な3連単出目へ落とす。')
    print('=' * 132)


if __name__ == '__main__':
    main()
