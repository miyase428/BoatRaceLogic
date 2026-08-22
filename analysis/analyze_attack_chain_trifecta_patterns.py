#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
STEP7: 3～5Cの攻め率条件から、具体的な3連単コース出目へ落とす探索分析。

前STEPで前後半とも再現した
  - 攻め艇本人の上昇
  - 直内コースの低下
  - 1C1着率の低下
を、実際の1-2-3着コース並び（例: 4-1-5）で確認する。

重要:
- 条件は事前情報の6month point-in-time決まり手率だけを使用。
- actual_1st_course / actual_2nd_course / actual_3rd_course は評価ラベルのみ。
- 比較母体は同じ攻め起点コースで sample_n >= 10 のレース。
- まず >=15% を主条件、>=20% を強条件として探索する。
- このスクリプトは探索用。出た出目をそのまま本番実装しない。

Usage:
  python3 analysis/analyze_attack_chain_trifecta_patterns.py \
    analysis/output/kimarite_analysis_dataset_20250815_20260814.csv
"""

from __future__ import annotations

import csv
import sys
from collections import Counter
from pathlib import Path

ATTACK_COURSES = (3, 4, 5)
THRESHOLDS = (15.0, 20.0)
MIN_SAMPLE = 10
TOP_N = 15


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


def formal(row):
    return (
        to_int(row.get('result_top3_course_complete')) == 1
        and to_int(row.get('result_boat_match')) == 1
    )


def sample_ok(row, course):
    return to_int(row.get(f'c{course}_6m_sample_n')) >= MIN_SAMPLE


def attack_rate(row, course):
    return (
        to_float(row.get(f'c{course}_6m_makuri'))
        + to_float(row.get(f'c{course}_6m_makurizashi'))
    )


def trifecta(row):
    return (
        to_int(row.get('actual_1st_course')),
        to_int(row.get('actual_2nd_course')),
        to_int(row.get('actual_3rd_course')),
    )


def valid_trifecta(t):
    return (
        len(t) == 3
        and all(1 <= x <= 6 for x in t)
        and len(set(t)) == 3
    )


def fmt_tri(t):
    return f'{t[0]}-{t[1]}-{t[2]}'


def grouped_flags(t, attack_course):
    inside = attack_course - 1
    outside = attack_course + 1 if attack_course < 6 else 0
    return {
        '攻め艇1着': t[0] == attack_course,
        '攻め艇2着': t[1] == attack_course,
        '攻め艇3着': t[2] == attack_course,
        '攻め艇TOP2': attack_course in t[:2],
        '攻め艇TOP3': attack_course in t,
        '直内TOP3': inside in t,
        '直内着外': inside not in t,
        '1C1着': t[0] == 1,
        '1C着外': 1 not in t,
        '攻め艇1着×直内着外': t[0] == attack_course and inside not in t,
        '攻め艇1着×1C相手残り': t[0] == attack_course and 1 in t[1:],
        '攻め艇TOP2×直内着外': attack_course in t[:2] and inside not in t,
        '直外TOP3': outside in t if outside else False,
    }


def summarize_grouped(base_rows, cond_rows, attack_course):
    keys = list(grouped_flags((1, 2, 3), attack_course).keys())
    base_counts = Counter()
    cond_counts = Counter()

    for row in base_rows:
        t = trifecta(row)
        if not valid_trifecta(t):
            continue
        for k, hit in grouped_flags(t, attack_course).items():
            if hit:
                base_counts[k] += 1

    for row in cond_rows:
        t = trifecta(row)
        if not valid_trifecta(t):
            continue
        for k, hit in grouped_flags(t, attack_course).items():
            if hit:
                cond_counts[k] += 1

    print('グループ出目')
    print('指標                         BASE率     条件率      差pt    Lift')
    print('-' * 72)
    for k in keys:
        bp = pct(base_counts[k], len(base_rows))
        cp = pct(cond_counts[k], len(cond_rows))
        lift = (cp / bp) if bp > 0 else 0.0
        print(f'{k:<28} {bp:7.2f}%   {cp:7.2f}%   {cp-bp:+7.2f}   {lift:5.3f}')


def exact_stats(base_rows, cond_rows):
    base = Counter()
    cond = Counter()

    for row in base_rows:
        t = trifecta(row)
        if valid_trifecta(t):
            base[t] += 1

    for row in cond_rows:
        t = trifecta(row)
        if valid_trifecta(t):
            cond[t] += 1

    rows = []
    all_keys = set(base) | set(cond)
    for t in all_keys:
        bn = base[t]
        cn = cond[t]
        bp = pct(bn, len(base_rows))
        cp = pct(cn, len(cond_rows))
        delta = cp - bp
        lift = (cp / bp) if bp > 0 else 0.0
        rows.append((t, bn, cn, bp, cp, delta, lift))

    return rows


def print_exact_tables(base_rows, cond_rows):
    rows = exact_stats(base_rows, cond_rows)

    # 条件側の実数が少なすぎる出目は上振れしやすいので最低件数を要求。
    min_hits = 20 if len(cond_rows) >= 4000 else 10

    rise = [r for r in rows if r[2] >= min_hits and r[5] > 0]
    rise.sort(key=lambda r: (r[5], r[6], r[2]), reverse=True)

    fall = [r for r in rows if r[1] >= min_hits and r[5] < 0]
    fall.sort(key=lambda r: (r[5], -r[1]))

    common = [r for r in rows if r[2] >= min_hits]
    common.sort(key=lambda r: (r[4], r[2]), reverse=True)

    def emit(title, data):
        print('\n' + title)
        print('出目        BASE件   条件件    BASE率    条件率      差pt    Lift')
        print('-' * 76)
        for t, bn, cn, bp, cp, delta, lift in data[:TOP_N]:
            print(
                f'{fmt_tri(t):<9} {bn:7d} {cn:7d} '
                f'{bp:8.3f}% {cp:8.3f}% {delta:+8.3f} {lift:6.3f}'
            )

    emit(f'増えた出目 TOP{TOP_N}（条件側{min_hits}件以上）', rise)
    emit(f'条件時の頻出出目 TOP{TOP_N}（条件側{min_hits}件以上）', common)
    emit(f'減った出目 TOP{TOP_N}（BASE側{min_hits}件以上）', fall)


def main():
    if len(sys.argv) != 2:
        print(
            f'Usage: python3 {sys.argv[0]} KIMARITE_ANALYSIS_DATASET.csv',
            file=sys.stderr,
        )
        sys.exit(1)

    path = Path(sys.argv[1]).resolve()
    if not path.is_file():
        raise RuntimeError(f'CSVがありません: {path}')

    all_rows = read_csv(path)
    rows = [r for r in all_rows if formal(r)]

    dates = sorted((r.get('race_date') or '').strip() for r in rows if (r.get('race_date') or '').strip())
    start = dates[0] if dates else '-'
    end = dates[-1] if dates else '-'

    print('=' * 132)
    print('STEP7 攻め率条件 → 具体的な3連単コース出目')
    print('=' * 132)
    print(f'CSV          : {path.name}')
    print(f'期間         : {start} ～ {end}')
    print(f'CSV全行      : {len(all_rows)}')
    print(f'正式分析対象 : {len(rows)}')
    print('特徴         : 3～5C 6month point-in-time 攻め率 = まくり + まくり差し')
    print(f'sample条件   : 攻め起点 sample_n >= {MIN_SAMPLE}')
    print('主条件       : >=15% / 強条件: >=20%')
    print('actual着順   : ラベルとしてのみ使用')
    print('注意         : 探索分析。候補出目は次に期間分割してから採用判断。')

    for attack_course in ATTACK_COURSES:
        base_rows = [r for r in rows if sample_ok(r, attack_course)]
        print('\n' + '=' * 132)
        print(f'【{attack_course}C攻め起点】 基礎母体 N={len(base_rows)}')
        print('=' * 132)

        for threshold in THRESHOLDS:
            cond_rows = [r for r in base_rows if attack_rate(r, attack_course) >= threshold]
            print('\n' + '-' * 132)
            print(
                f'【{attack_course}C攻め率 >= {threshold:.0f}%】 '
                f'N={len(cond_rows)}/{len(base_rows)} ({pct(len(cond_rows), len(base_rows)):.2f}%)'
            )
            print('-' * 132)
            summarize_grouped(base_rows, cond_rows, attack_course)
            print_exact_tables(base_rows, cond_rows)

    print('\n' + '=' * 132)
    print('STEP7 出目探索完了')
    print('次STEP: 増加幅・Lift・件数が十分な出目だけを候補化し、前半/後半で再現性を確認する。')
    print('=' * 132)


if __name__ == '__main__':
    main()
