#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
現行Webが事前に1号艇を本命にしていないレース（= Web上のイン飛び予想）に限定し、
3～5コースのpoint-in-time攻め率が、そのコース自身の1着率と結び付くかを確認する。

目的:
- 「実際にインが飛んだレース」で見えたkimarite効果を、実運用トリガーへ接続する
- 前半6か月 / 後半6か月で再現するか確認
- 次段の頭順位補正に進めるか判断する

条件:
- result_top3_course_complete=1 and result_boat_match=1
- honmei_head != 1（現行Webが事前にイン飛びを予想）
- 対象コース sample_n >= 10
- 攻め率 = 6month point-in-time (まくり + まくり差し)

Usage:
  python3 analysis/validate_web_predicted_non_lane1_attack_head_rates.py \
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


def is_web_non_lane1(row: dict[str, str]) -> bool:
    return is_formal(row) and to_int(row.get('honmei_head')) not in (0, 1)


def sample_ok(row: dict[str, str], course: int) -> bool:
    return to_int(row.get(f'c{course}_6m_sample_n')) >= MIN_SAMPLE


def attack(row: dict[str, str], course: int) -> float:
    return (
        to_float(row.get(f'c{course}_6m_makuri'))
        + to_float(row.get(f'c{course}_6m_makurizashi'))
    )


def period_rows(rows: list[dict[str, str]], which: str) -> list[dict[str, str]]:
    if which == 'all':
        return rows
    if which == 'first':
        return [r for r in rows if parse_date(r) < SPLIT]
    if which == 'second':
        return [r for r in rows if parse_date(r) >= SPLIT]
    raise ValueError(which)


def stats_for(rows: list[dict[str, str]], course: int, threshold: float | None):
    eligible = [r for r in rows if sample_ok(r, course)]
    if threshold is None:
        selected = eligible
    else:
        selected = [r for r in eligible if attack(r, course) >= threshold]

    hits = sum(1 for r in selected if to_int(r.get('actual_1st_course')) == course)
    return hits, len(selected), len(eligible)


def main() -> None:
    if len(sys.argv) != 2:
        print(
            'Usage: python3 analysis/validate_web_predicted_non_lane1_attack_head_rates.py DATASET_CSV',
            file=sys.stderr,
        )
        sys.exit(1)

    path = Path(sys.argv[1]).resolve()
    if not path.is_file():
        raise RuntimeError(f'CSVがありません: {path}')

    all_rows = load_rows(path)
    rows = [r for r in all_rows if is_web_non_lane1(r)]
    if not rows:
        raise RuntimeError('現行Web本命非1の正式分析対象がありません')

    dates = sorted(parse_date(r) for r in rows)
    first = period_rows(rows, 'first')
    second = period_rows(rows, 'second')

    actual_non1 = sum(1 for r in rows if to_int(r.get('actual_1st_course')) != 1)
    actual_1 = len(rows) - actual_non1

    print('\n' + '=' * 142)
    print('現行Web「イン飛び予想」時 3～5コース攻め率 → 自身1着率 期間分割検証')
    print('=' * 142)
    print(f'データ期間       : {dates[0]} ～ {dates[-1]}')
    print(f'Web本命非1       : {len(rows)}')
    print(f'  実際もイン飛び : {actual_non1} ({pct(actual_non1, len(rows)):.2f}%)')
    print(f'  実際は①1着    : {actual_1} ({pct(actual_1, len(rows)):.2f}%)')
    print(f'前半             : {len(first)}')
    print(f'後半             : {len(second)}')
    print('条件             : 6month point-in-time 攻め率（まくり+まくり差し）、sample_n>=10')
    print('重要             : actual_1st_course は評価ラベルのみ。抽出条件は事前Web本命だけ。')

    periods = [
        ('全1年', rows),
        ('前半6か月', first),
        ('後半6か月', second),
    ]

    for course in COURSES:
        print('\n' + '-' * 142)
        print(f'【{course}コース】')
        print('-' * 142)
        print('期間          条件             N/対象母体       自身1着率   基礎率      差pt     Lift')
        print('-' * 142)

        for period_label, pr in periods:
            base_hits, base_n, _ = stats_for(pr, course, None)
            base_rate = pct(base_hits, base_n)

            for threshold in THRESHOLDS:
                hits, n, eligible_n = stats_for(pr, course, threshold)
                cur_rate = pct(hits, n)
                diff = cur_rate - base_rate
                lift = (cur_rate / base_rate) if base_rate else 0.0
                print(
                    f'{period_label:<12}  攻め率>={threshold:>2.0f}%  '
                    f'{n:>6}/{eligible_n:<6}  '
                    f'{cur_rate:>8.2f}%  {base_rate:>7.2f}%  '
                    f'{diff:>+7.2f}  {lift:>7.3f}'
                )

    print('\n' + '=' * 142)
    print('Web事前トリガー接続検証完了')
    print('判定: 後半6か月でも攻め率上昇に伴う自身1着率上昇が残れば、次STEPで現行Web頭順位との統合をホールドアウト検証')
    print('=' * 142)


if __name__ == '__main__':
    main()
