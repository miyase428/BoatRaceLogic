#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
1コースが実際に「逃げ」で勝ったレースを対象に、
3～5コースの事前6month攻め率（まくり+まくり差し）を5%刻みで分け、
当該コース自身の2着・3着・2or3着率が段階的に上がるかを確認する。

目的:
- 10% / 20%という仮閾値の妥当性確認
- 攻め率の上昇に対して相手候補価値が単調に高まるかを見る
- 将来の「攻め弱・中・強」段階評価の土台にする

条件:
- result_top3_course_complete = 1
- result_boat_match = 1
- actual_1st_course = 1
- winner_technique = 逃げ
- 対象コース c3～c5 の 6month sample_n >= 10

使い方:
  python3 analysis/analyze_lane1_escape_attack_rate_bands.py \
    analysis/output/kimarite_analysis_dataset_20250815_20260814.csv
"""

from __future__ import annotations

import csv
import sys
from collections import Counter
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


def load_rows(path: Path) -> list[dict[str, str]]:
    with path.open('r', encoding='utf-8-sig', newline='') as f:
        return list(csv.DictReader(f))


def is_escape(row: dict[str, str]) -> bool:
    return (
        to_int(row.get('result_top3_course_complete')) == 1
        and to_int(row.get('result_boat_match')) == 1
        and to_int(row.get('actual_1st_course')) == 1
        and (row.get('winner_technique') or '').strip() == '逃げ'
    )


def attack(row: dict[str, str], course: int) -> float:
    return (
        to_float(row.get(f'c{course}_6m_makuri'))
        + to_float(row.get(f'c{course}_6m_makurizashi'))
    )


def sample_ok(row: dict[str, str], course: int) -> bool:
    return to_int(row.get(f'c{course}_6m_sample_n')) >= 10


def hit_stats(rows: list[dict[str, str]], course: int) -> tuple[int, int, int, int]:
    n = len(rows)
    second = sum(to_int(r.get('actual_2nd_course')) == course for r in rows)
    third = sum(to_int(r.get('actual_3rd_course')) == course for r in rows)
    top23 = second + third
    return n, second, third, top23


def main() -> None:
    if len(sys.argv) != 2:
        print(
            'Usage: python3 analysis/analyze_lane1_escape_attack_rate_bands.py DATASET_CSV',
            file=sys.stderr,
        )
        sys.exit(1)

    path = Path(sys.argv[1]).resolve()
    if not path.is_file():
        raise RuntimeError(f'CSVがありません: {path}')

    rows = load_rows(path)
    escape_rows = [r for r in rows if is_escape(r)]

    bands = [
        ('0～5%未満', 0.0, 5.0),
        ('5～10%未満', 5.0, 10.0),
        ('10～15%未満', 10.0, 15.0),
        ('15～20%未満', 15.0, 20.0),
        ('20～25%未満', 20.0, 25.0),
        ('25%以上', 25.0, None),
    ]

    print('\n' + '=' * 112)
    print('1逃げ時 3～5コース攻め率帯別 2着・3着成績')
    print('=' * 112)
    print(f'CSV行       : {len(rows)}')
    print(f'1逃げ母体   : {len(escape_rows)}')
    print('攻め率      : 6month point-in-time まくり率 + まくり差し率')
    print('sample条件  : 対象コース sample_n >= 10')

    for course in (3, 4, 5):
        eligible = [r for r in escape_rows if sample_ok(r, course)]
        base_n, base_2, base_3, base_23 = hit_stats(eligible, course)
        base_2r = pct(base_2, base_n)
        base_3r = pct(base_3, base_n)
        base_23r = pct(base_23, base_n)

        print('\n' + '-' * 112)
        print(f'【{course}コース】 sample_n>=10 母体={base_n}')
        print(f'母体成績: 2着 {base_2r:.2f}% / 3着 {base_3r:.2f}% / 2or3 {base_23r:.2f}%')
        print('-' * 112)
        print('攻め率帯          N      構成比     2着率   差pt     3着率   差pt     2or3率   差pt    Lift')
        print('-' * 112)

        for label, low, high in bands:
            selected = []
            for r in eligible:
                a = attack(r, course)
                if a < low:
                    continue
                if high is not None and a >= high:
                    continue
                selected.append(r)

            n, s2, s3, s23 = hit_stats(selected, course)
            r2 = pct(s2, n)
            r3 = pct(s3, n)
            r23 = pct(s23, n)
            lift = (r23 / base_23r) if base_23r else 0.0

            print(
                f'{label:<14} {n:>6}  {pct(n, base_n):>7.2f}%  '
                f'{r2:>7.2f}% {r2-base_2r:>+6.2f}  '
                f'{r3:>7.2f}% {r3-base_3r:>+6.2f}  '
                f'{r23:>8.2f}% {r23-base_23r:>+6.2f}  {lift:>6.3f}'
            )

    print('\n' + '=' * 112)
    print('攻め率帯別集計完了')
    print('次STEP: 単調性とNを見て実装候補の閾値（通常/高/かなり高）を固定')
    print('=' * 112)


if __name__ == '__main__':
    main()
