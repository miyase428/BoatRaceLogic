#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
1逃げ時に、3～5コースのpoint-in-time攻め率帯から
「2着または3着に来る確率」を前半6か月で学習し、後半6か月で評価する。

目的:
- kimarite攻め率を単なる傾向ではなく、実際の相手順位付けに使えるか確認
- 常に3>4>5と並べる基礎順位と比較
- top1 / top2 の相手選び改善量を確認

学習特徴:
- 6month point-in-time 攻め率 = まくり + まくり差し
- 対象コース sample_n >= 10
- 攻め率帯: <5, 5-10, 10-15, 15-20, 20-25, 25+

評価:
- 前半6か月で帯別2or3率を作成
- 後半6か月の各レースで3,4,5コースをその確率順に並べる
- 常に基礎率順に並べる方法と比較

使い方:
  python3 analysis/validate_lane1_escape_opponent_ranking_holdout.py \
    analysis/output/kimarite_analysis_dataset_20250815_20260814.csv
"""

from __future__ import annotations

import csv
import sys
from collections import Counter, defaultdict
from datetime import date, datetime
from pathlib import Path


COURSES = (3, 4, 5)
MIN_SAMPLE = 10
MIN_BAND_N = 100


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


def parse_date(row):
    return datetime.strptime((row.get('race_date') or '').strip(), '%Y-%m-%d').date()


def is_formal(row):
    return (
        to_int(row.get('result_top3_course_complete')) == 1
        and to_int(row.get('result_boat_match')) == 1
    )


def is_lane1_escape(row):
    return (
        is_formal(row)
        and to_int(row.get('actual_1st_course')) == 1
        and (row.get('winner_technique') or '').strip() == '逃げ'
    )


def sample_ok(row, course):
    return to_int(row.get(f'c{course}_6m_sample_n')) >= MIN_SAMPLE


def attack(row, course):
    return (
        to_float(row.get(f'c{course}_6m_makuri'))
        + to_float(row.get(f'c{course}_6m_makurizashi'))
    )


def attack_band(value: float) -> str:
    if value < 5.0:
        return '0-5'
    if value < 10.0:
        return '5-10'
    if value < 15.0:
        return '10-15'
    if value < 20.0:
        return '15-20'
    if value < 25.0:
        return '20-25'
    return '25+'


def actual_followers(row):
    return {
        to_int(row.get('actual_2nd_course')),
        to_int(row.get('actual_3rd_course')),
    }


def train_model(rows):
    band_stats = {
        c: defaultdict(lambda: [0, 0])
        for c in COURSES
    }
    base_stats = {c: [0, 0] for c in COURSES}

    for row in rows:
        followers = actual_followers(row)
        for c in COURSES:
            if not sample_ok(row, c):
                continue
            hit = 1 if c in followers else 0
            base_stats[c][0] += hit
            base_stats[c][1] += 1
            b = attack_band(attack(row, c))
            band_stats[c][b][0] += hit
            band_stats[c][b][1] += 1

    base_prob = {
        c: (base_stats[c][0] / base_stats[c][1]) if base_stats[c][1] else 0.0
        for c in COURSES
    }

    band_prob = {c: {} for c in COURSES}
    for c in COURSES:
        for b, (hits, n) in band_stats[c].items():
            if n >= MIN_BAND_N:
                band_prob[c][b] = hits / n

    return base_prob, band_prob, base_stats, band_stats


def score_course(row, course, base_prob, band_prob):
    if not sample_ok(row, course):
        return base_prob[course]
    b = attack_band(attack(row, course))
    return band_prob[course].get(b, base_prob[course])


def evaluate(rows, base_prob, band_prob):
    baseline_order = tuple(sorted(COURSES, key=lambda c: (-base_prob[c], c)))

    stats = Counter()
    model_top1_counts = Counter()
    baseline_top1 = baseline_order[0]

    for row in rows:
        followers = actual_followers(row)
        model_order = tuple(sorted(
            COURSES,
            key=lambda c: (-score_course(row, c, base_prob, band_prob), c),
        ))

        model_top1 = model_order[0]
        model_top2 = set(model_order[:2])
        base_top2 = set(baseline_order[:2])

        model_top1_counts[model_top1] += 1
        stats['n'] += 1
        stats['model_top1_hit'] += int(model_top1 in followers)
        stats['base_top1_hit'] += int(baseline_top1 in followers)

        m_cov = len(model_top2 & followers)
        b_cov = len(base_top2 & followers)
        stats['model_top2_hits'] += m_cov
        stats['base_top2_hits'] += b_cov
        stats['model_top2_any'] += int(m_cov >= 1)
        stats['base_top2_any'] += int(b_cov >= 1)
        stats['model_top2_both'] += int(m_cov == 2)
        stats['base_top2_both'] += int(b_cov == 2)

        if model_top1 != baseline_top1:
            stats['top1_switched'] += 1
            stats['switch_model_hit'] += int(model_top1 in followers)
            stats['switch_base_hit'] += int(baseline_top1 in followers)

        if model_top2 != base_top2:
            stats['top2_switched'] += 1
            stats['switch_model_top2_hits'] += m_cov
            stats['switch_base_top2_hits'] += b_cov

    return baseline_order, stats, model_top1_counts


def print_training(base_prob, base_stats, band_stats):
    print('\n【前半6か月 学習結果】')
    print('コース  基礎2or3率    N')
    print('-' * 34)
    for c in COURSES:
        hits, n = base_stats[c]
        print(f'{c:>4}    {pct(hits, n):>8.2f}%  {n:>5}')

    print('\n帯別2or3率（N>=100のみ順位モデルへ採用）')
    print('コース  帯          N      2or3率')
    print('-' * 44)
    order = ['0-5', '5-10', '10-15', '15-20', '20-25', '25+']
    for c in COURSES:
        for b in order:
            hits, n = band_stats[c].get(b, (0, 0))
            if n:
                mark = '*' if n >= MIN_BAND_N else ' '
                print(f'{c:>4}  {b:<8} {n:>6}   {pct(hits, n):>7.2f}% {mark}')


def main():
    if len(sys.argv) != 2:
        print(
            'Usage: python3 analysis/validate_lane1_escape_opponent_ranking_holdout.py DATASET_CSV',
            file=sys.stderr,
        )
        sys.exit(1)

    path = Path(sys.argv[1]).resolve()
    if not path.is_file():
        raise RuntimeError(f'CSVがありません: {path}')

    rows = [r for r in load_rows(path) if is_lane1_escape(r)]
    if not rows:
        raise RuntimeError('1逃げ対象がありません')

    dates = sorted(parse_date(r) for r in rows)
    start = dates[0]
    end = dates[-1]
    split = date(2026, 2, 15)

    train = [r for r in rows if parse_date(r) < split]
    test = [r for r in rows if parse_date(r) >= split]

    base_prob, band_prob, base_stats, band_stats = train_model(train)
    baseline_order, stats, top1_counts = evaluate(test, base_prob, band_prob)

    print('\n' + '=' * 118)
    print('1逃げ時 kimarite相手順位 ホールドアウト検証')
    print('=' * 118)
    print(f'全期間       : {start} ～ {end}')
    print(f'学習         : {start} ～ 2026-02-14  N={len(train)}')
    print(f'評価         : 2026-02-15 ～ {end}  N={len(test)}')
    print(f'特徴         : 3～5C 6month point-in-time 攻め率帯')
    print(f'sample条件   : 各コース sample_n >= {MIN_SAMPLE}')
    print(f'帯採用最低N  : {MIN_BAND_N}')

    print_training(base_prob, base_stats, band_stats)

    n = stats['n']
    print('\n【後半6か月 評価】')
    print(f'基礎順位（常時） : {baseline_order[0]} > {baseline_order[1]} > {baseline_order[2]}')
    print('')
    print('TOP1（③④⑤から1艇選ぶ）')
    print(f'  基礎順位 : {stats["base_top1_hit"]}/{n} ({pct(stats["base_top1_hit"], n):.2f}%)')
    print(f'  kimarite : {stats["model_top1_hit"]}/{n} ({pct(stats["model_top1_hit"], n):.2f}%)')
    print(f'  差       : {pct(stats["model_top1_hit"], n)-pct(stats["base_top1_hit"], n):+.2f}pt')

    print('\nTOP2（③④⑤から2艇選ぶ）')
    print(f'  1艇以上的中 基礎 : {pct(stats["base_top2_any"], n):.2f}%')
    print(f'  1艇以上的中 kim  : {pct(stats["model_top2_any"], n):.2f}%')
    print(f'  2艇とも的中 基礎 : {pct(stats["base_top2_both"], n):.2f}%')
    print(f'  2艇とも的中 kim  : {pct(stats["model_top2_both"], n):.2f}%')
    print(f'  平均的中艇数 基礎 : {stats["base_top2_hits"]/n:.4f}')
    print(f'  平均的中艇数 kim  : {stats["model_top2_hits"]/n:.4f}')

    sw = stats['top1_switched']
    print('\nTOP1を基礎③から変更したレース')
    print(f'  変更数 : {sw}/{n} ({pct(sw, n):.2f}%)')
    if sw:
        print(f'  基礎③ 的中 : {pct(stats["switch_base_hit"], sw):.2f}%')
        print(f'  変更先 的中 : {pct(stats["switch_model_hit"], sw):.2f}%')
        print(f'  差          : {pct(stats["switch_model_hit"], sw)-pct(stats["switch_base_hit"], sw):+.2f}pt')

    sw2 = stats['top2_switched']
    print('\nTOP2集合を基礎{3,4}から変更したレース')
    print(f'  変更数 : {sw2}/{n} ({pct(sw2, n):.2f}%)')
    if sw2:
        print(f'  基礎 平均的中艇数 : {stats["switch_base_top2_hits"]/sw2:.4f}')
        print(f'  kim  平均的中艇数 : {stats["switch_model_top2_hits"]/sw2:.4f}')
        print(f'  差                : {(stats["switch_model_top2_hits"]-stats["switch_base_top2_hits"])/sw2:+.4f}')

    print('\nkimarite TOP1選択内訳')
    for c in COURSES:
        print(f'  {c}コース : {top1_counts[c]:>5} ({pct(top1_counts[c], n):.2f}%)')

    print('\n' + '=' * 118)
    print('ホールドアウト検証完了')
    print('判定: 後半評価でTOP1/TOP2が基礎順位を上回れば、相手順位付けへの実装候補')
    print('=' * 118)


if __name__ == '__main__':
    main()
