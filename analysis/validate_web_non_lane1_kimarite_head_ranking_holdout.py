#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
現行Webが事前に1号艇を本命にしていないレースを対象に、
3～5コースのpoint-in-time決まり手「攻め率帯」から自身1着率を前半6か月で学習し、
後半6か月で現行Webの頭順位を保守的に補正できるか検証する。

方針:
- 抽出条件は事前情報のみ: honmei_head != 1
- actual_1st_course は学習ラベル/評価ラベルとしてのみ使用
- kimarite特徴は 6month point-in-time の makuri + makurizashi
- sample_n >= 10 のコースだけ帯モデルを利用
- 攻め率帯: <5, 5-10, 10-15, 15-20, 20-25, 25+
- 前半6か月でコース別・帯別の自身1着率を学習
- 後半6か月では、現行本命が3/4/5の時だけ3～5C内で再順位付け
- 現行本命が2/6の時は変更しない（2/6用の同等特徴をまだ作っていないため）

目的:
- kimariteを現行Web頭順位の全面置換ではなく、3～5C間の補助順位付けとして使えるか確認
- 全体本命1着率、変更レースだけの改善量、変更方向を確認

使い方:
  python3 analysis/validate_web_non_lane1_kimarite_head_ranking_holdout.py \
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
SPLIT = date(2026, 2, 15)
BAND_ORDER = ('0-5', '5-10', '10-15', '15-20', '20-25', '25+')


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


def is_web_non_lane1(row):
    return is_formal(row) and to_int(row.get('honmei_head')) in (2, 3, 4, 5, 6)


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


def train_model(rows):
    base = {c: [0, 0] for c in COURSES}
    bands = {c: defaultdict(lambda: [0, 0]) for c in COURSES}

    for row in rows:
        actual = to_int(row.get('actual_1st_course'))
        for c in COURSES:
            if not sample_ok(row, c):
                continue
            hit = 1 if actual == c else 0
            base[c][0] += hit
            base[c][1] += 1
            b = attack_band(attack(row, c))
            bands[c][b][0] += hit
            bands[c][b][1] += 1

    base_prob = {
        c: (hits / n) if n else 0.0
        for c, (hits, n) in base.items()
    }

    band_prob = {c: {} for c in COURSES}
    for c in COURSES:
        for band, (hits, n) in bands[c].items():
            if n >= MIN_BAND_N:
                band_prob[c][band] = hits / n

    return base, bands, base_prob, band_prob


def score_course(row, course, base_prob, band_prob):
    if not sample_ok(row, course):
        return base_prob[course]
    band = attack_band(attack(row, course))
    return band_prob[course].get(band, base_prob[course])


def choose_kimarite_head(row, current_head, base_prob, band_prob):
    if current_head not in COURSES:
        return current_head, False, {}

    scores = {
        c: score_course(row, c, base_prob, band_prob)
        for c in COURSES
    }

    # 同率なら現行本命を維持。kimariteが明確に上回る時だけ変更。
    best = min(
        COURSES,
        key=lambda c: (
            -scores[c],
            0 if c == current_head else 1,
            c,
        ),
    )

    if scores[best] <= scores[current_head]:
        best = current_head

    return best, best != current_head, scores


def print_training(base, bands):
    print('\n【前半6か月 学習結果】')
    print('コース  基礎1着率      N')
    print('-' * 34)
    for c in COURSES:
        hits, n = base[c]
        print(f'{c:>4}    {pct(hits, n):>8.2f}%  {n:>6}')

    print('\n帯別自身1着率（N>=100をモデル採用）')
    print('コース  帯          N      1着率')
    print('-' * 46)
    for c in COURSES:
        for band in BAND_ORDER:
            hits, n = bands[c].get(band, (0, 0))
            if not n:
                continue
            mark = '*' if n >= MIN_BAND_N else ' '
            print(f'{c:>4}  {band:<8} {n:>6}   {pct(hits, n):>7.2f}% {mark}')


def evaluate(rows, base_prob, band_prob):
    s = Counter()
    switches = Counter()

    for row in rows:
        current = to_int(row.get('honmei_head'))
        actual = to_int(row.get('actual_1st_course'))
        new_head, switched, _ = choose_kimarite_head(
            row, current, base_prob, band_prob
        )

        s['n'] += 1
        s['current_hit'] += int(current == actual)
        s['new_hit'] += int(new_head == actual)

        if current in COURSES:
            s['current_345_n'] += 1
            s['current_345_hit'] += int(current == actual)
            s['new_345_hit'] += int(new_head == actual)

        if switched:
            s['switched'] += 1
            s['switch_current_hit'] += int(current == actual)
            s['switch_new_hit'] += int(new_head == actual)
            switches[f'{current}->{new_head}'] += 1
            if current == actual and new_head != actual:
                switches[f'{current}->{new_head}:loss'] += 1
            if current != actual and new_head == actual:
                switches[f'{current}->{new_head}:gain'] += 1

    return s, switches


def main():
    if len(sys.argv) != 2:
        print(
            'Usage: python3 analysis/validate_web_non_lane1_kimarite_head_ranking_holdout.py DATASET_CSV',
            file=sys.stderr,
        )
        sys.exit(1)

    path = Path(sys.argv[1]).resolve()
    if not path.is_file():
        raise RuntimeError(f'CSVがありません: {path}')

    rows = [r for r in load_rows(path) if is_web_non_lane1(r)]
    if not rows:
        raise RuntimeError('Web本命非1の正式分析対象がありません')

    train = [r for r in rows if parse_date(r) < SPLIT]
    test = [r for r in rows if parse_date(r) >= SPLIT]

    base, bands, base_prob, band_prob = train_model(train)
    stats, switches = evaluate(test, base_prob, band_prob)

    dates = sorted(parse_date(r) for r in rows)
    start = dates[0]
    end = dates[-1]

    print('\n' + '=' * 126)
    print('現行Webイン飛び予想 × kimarite 3～5C頭順位 ホールドアウト検証')
    print('=' * 126)
    print(f'全期間       : {start} ～ {end}')
    print(f'学習         : {start} ～ 2026-02-14  N={len(train)}')
    print(f'評価         : 2026-02-15 ～ {end}  N={len(test)}')
    print('抽出         : 現行Web honmei_head != 1（事前条件のみ）')
    print('特徴         : 3～5C 6month point-in-time 攻め率帯')
    print(f'sample条件   : 各コース sample_n >= {MIN_SAMPLE}')
    print(f'帯採用最低N  : {MIN_BAND_N}')
    print('適用         : 現行本命が3/4/5の時だけ3～5C内で補正。2/6本命は変更しない。')

    print_training(base, bands)

    n = stats['n']
    print('\n【後半6か月 評価】')
    print(f'全Web本命非1対象 : {n}')
    print('')
    print('本命1着的中率（全対象）')
    print(f'  現行Web : {stats["current_hit"]}/{n} ({pct(stats["current_hit"], n):.2f}%)')
    print(f'  修正後  : {stats["new_hit"]}/{n} ({pct(stats["new_hit"], n):.2f}%)')
    print(f'  差      : {stats["new_hit"]-stats["current_hit"]:+d}件 / '
          f'{pct(stats["new_hit"], n)-pct(stats["current_hit"], n):+.3f}pt')

    n345 = stats['current_345_n']
    print('\n現行本命が③④⑤だったレース')
    print(f'  対象    : {n345}/{n} ({pct(n345, n):.2f}%)')
    print(f'  現行Web : {stats["current_345_hit"]}/{n345} ({pct(stats["current_345_hit"], n345):.2f}%)')
    print(f'  修正後  : {stats["new_345_hit"]}/{n345} ({pct(stats["new_345_hit"], n345):.2f}%)')
    print(f'  差      : {stats["new_345_hit"]-stats["current_345_hit"]:+d}件 / '
          f'{pct(stats["new_345_hit"], n345)-pct(stats["current_345_hit"], n345):+.3f}pt')

    sw = stats['switched']
    print('\n実際に頭を変更したレース')
    print(f'  変更数  : {sw}/{n} ({pct(sw, n):.2f}%)')
    if sw:
        print(f'  現行的中: {stats["switch_current_hit"]}/{sw} ({pct(stats["switch_current_hit"], sw):.2f}%)')
        print(f'  修正的中: {stats["switch_new_hit"]}/{sw} ({pct(stats["switch_new_hit"], sw):.2f}%)')
        print(f'  差      : {stats["switch_new_hit"]-stats["switch_current_hit"]:+d}件 / '
              f'{pct(stats["switch_new_hit"], sw)-pct(stats["switch_current_hit"], sw):+.2f}pt')

    print('\n変更方向')
    directions = sorted(
        (k, v) for k, v in switches.items()
        if ':' not in k
    )
    if not directions:
        print('  変更なし')
    else:
        for direction, count in directions:
            gain = switches.get(f'{direction}:gain', 0)
            loss = switches.get(f'{direction}:loss', 0)
            print(f'  {direction:<5} N={count:>5}  純増={gain-loss:+4d}  gain={gain:>4} loss={loss:>4}')

    print('\n' + '=' * 126)
    print('ホールドアウト検証完了')
    print('判定: 後半評価で全体/変更レースとも現行Webを上回るなら、3～5C頭順位補正を実装候補へ')
    print('=' * 126)


if __name__ == '__main__':
    main()
