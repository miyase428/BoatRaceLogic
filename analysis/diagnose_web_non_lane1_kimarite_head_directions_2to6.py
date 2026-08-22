#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
現行Webが1号艇を本命にしていないレースについて、
2～6コースのpoint-in-time決まり手特徴からコース別・帯別の自身1着率を前半6か月で学習し、
後半6か月で「現行Web本命をどの方向へ変更すると増減するか」を診断する。

重要:
- これは全面置換モデルの実装判断ではなく、方向別の探索診断。
- 現行Webの一次/二次/展示/STEP4等を無視してkimariteだけで置換するため、全体成績が悪化しても不思議ではない。
- 狙いは 2->3, 5->2, 6->4 など変更方向ごとの gain/loss を洗い出すこと。
- actual_1st_course は学習/評価ラベルとしてのみ使用。

特徴:
- 2C: 6month point-in-time 差し率
- 3C: 6month point-in-time 攻め率 = まくり + まくり差し
- 4C: 同上
- 5C: 同上
- 6C: 同上（まくり差し単独よりNを確保できるため）
- 各コース sample_n >= 10
- 特徴帯: <5, 5-10, 10-15, 15-20, 20-25, 25+
- 学習帯N>=100のみ採用。未採用帯はコース基礎1着率へフォールバック。

Usage:
  python3 analysis/diagnose_web_non_lane1_kimarite_head_directions_2to6.py \
    analysis/output/kimarite_analysis_dataset_20250815_20260814.csv
"""

from __future__ import annotations

import csv
import sys
from collections import Counter, defaultdict
from datetime import date, datetime
from pathlib import Path

COURSES = (2, 3, 4, 5, 6)
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
    return is_formal(row) and to_int(row.get('honmei_head')) in COURSES


def sample_ok(row, course):
    return to_int(row.get(f'c{course}_6m_sample_n')) >= MIN_SAMPLE


def feature_name(course):
    return '差し率' if course == 2 else '攻め率'


def feature_value(row, course):
    if course == 2:
        return to_float(row.get('c2_6m_sashi'))
    return (
        to_float(row.get(f'c{course}_6m_makuri'))
        + to_float(row.get(f'c{course}_6m_makurizashi'))
    )


def band(value: float) -> str:
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
            hit = int(actual == c)
            base[c][0] += hit
            base[c][1] += 1
            b = band(feature_value(row, c))
            bands[c][b][0] += hit
            bands[c][b][1] += 1

    base_prob = {
        c: (hits / n) if n else 0.0
        for c, (hits, n) in base.items()
    }

    band_prob = {c: {} for c in COURSES}
    for c in COURSES:
        for b, (hits, n) in bands[c].items():
            if n >= MIN_BAND_N:
                band_prob[c][b] = hits / n

    return base, bands, base_prob, band_prob


def score_course(row, course, base_prob, band_prob):
    if not sample_ok(row, course):
        return base_prob[course]
    b = band(feature_value(row, course))
    return band_prob[course].get(b, base_prob[course])


def choose_kimarite_head(row, current_head, base_prob, band_prob):
    scores = {
        c: score_course(row, c, base_prob, band_prob)
        for c in COURSES
    }

    # 同率なら現行本命を維持。その次にコース番号順。
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

    return best, scores


def print_training(base, bands):
    print('\n【前半6か月 学習結果】')
    print('コース  特徴      基礎1着率      N')
    print('-' * 44)
    for c in COURSES:
        hits, n = base[c]
        print(f'{c:>4}  {feature_name(c):<6}  {pct(hits, n):>8.2f}%  {n:>6}')

    print('\n帯別自身1着率（N>=100のみモデル採用）')
    print('コース  特徴      帯          N      1着率')
    print('-' * 58)
    for c in COURSES:
        for b in BAND_ORDER:
            hits, n = bands[c].get(b, (0, 0))
            if not n:
                continue
            mark = '*' if n >= MIN_BAND_N else ' '
            print(
                f'{c:>4}  {feature_name(c):<6}  {b:<8} '
                f'{n:>6}   {pct(hits, n):>7.2f}% {mark}'
            )


def evaluate(rows, base_prob, band_prob):
    stats = Counter()
    directions = Counter()
    picked = Counter()
    current_counts = Counter()

    for row in rows:
        current = to_int(row.get('honmei_head'))
        actual = to_int(row.get('actual_1st_course'))
        new_head, _ = choose_kimarite_head(row, current, base_prob, band_prob)

        stats['n'] += 1
        stats['current_hit'] += int(current == actual)
        stats['new_hit'] += int(new_head == actual)
        current_counts[current] += 1
        picked[new_head] += 1

        if new_head == current:
            stats['unchanged'] += 1
            continue

        stats['switched'] += 1
        curr_hit = current == actual
        new_hit = new_head == actual
        stats['switch_current_hit'] += int(curr_hit)
        stats['switch_new_hit'] += int(new_hit)

        key = f'{current}->{new_head}'
        directions[f'{key}:n'] += 1
        if new_hit and not curr_hit:
            directions[f'{key}:gain'] += 1
        if curr_hit and not new_hit:
            directions[f'{key}:loss'] += 1

    return stats, directions, current_counts, picked


def print_direction_table(directions):
    rows = []
    for curr in COURSES:
        for new in COURSES:
            if curr == new:
                continue
            key = f'{curr}->{new}'
            n = directions.get(f'{key}:n', 0)
            if not n:
                continue
            gain = directions.get(f'{key}:gain', 0)
            loss = directions.get(f'{key}:loss', 0)
            rows.append((gain - loss, n, curr, new, gain, loss))

    rows.sort(key=lambda x: (-x[0], -x[1], x[2], x[3]))

    print('\n【変更方向別】')
    print('方向       N    純増   gain   loss')
    print('-' * 42)
    if not rows:
        print('変更なし')
        return
    for net, n, curr, new, gain, loss in rows:
        print(f'{curr}->{new:<2}  {n:>6}  {net:+5d}  {gain:>5}  {loss:>5}')


def print_head_breakdown(current_counts, picked, n):
    print('\n【頭選択内訳】')
    print('コース   現行Web        kimarite')
    print('-' * 44)
    for c in COURSES:
        print(
            f'{c:>4}   {current_counts[c]:>6} ({pct(current_counts[c], n):>6.2f}%)  '
            f'{picked[c]:>6} ({pct(picked[c], n):>6.2f}%)'
        )


def main():
    if len(sys.argv) != 2:
        print(
            f'Usage: python3 {sys.argv[0]} DATASET_CSV',
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
    stats, directions, current_counts, picked = evaluate(test, base_prob, band_prob)

    dates = sorted(parse_date(r) for r in rows)
    start = dates[0]
    end = dates[-1]

    print('\n' + '=' * 132)
    print('現行Webイン飛び予想 × kimarite 2～6C頭方向 診断ホールドアウト')
    print('=' * 132)
    print(f'全期間       : {start} ～ {end}')
    print(f'学習         : {start} ～ 2026-02-14  N={len(train)}')
    print(f'評価         : 2026-02-15 ～ {end}  N={len(test)}')
    print('抽出         : 現行Web honmei_head != 1（事前条件のみ）')
    print('特徴         : 2C=差し率 / 3～6C=攻め率（6month point-in-time）')
    print(f'sample条件   : 各コース sample_n >= {MIN_SAMPLE}')
    print(f'帯採用最低N  : {MIN_BAND_N}')
    print('注意         : kimariteだけで2～6を全面置換する診断。全体改善より「変更方向」を見る。')

    print_training(base, bands)

    n = stats['n']
    sw = stats['switched']
    print('\n【後半6か月 評価】')
    print(f'対象 : {n}')
    print('\n本命1着的中率（参考）')
    print(f'  現行Web : {stats["current_hit"]}/{n} ({pct(stats["current_hit"], n):.2f}%)')
    print(f'  kimarite: {stats["new_hit"]}/{n} ({pct(stats["new_hit"], n):.2f}%)')
    print(
        f'  差      : {stats["new_hit"]-stats["current_hit"]:+d}件 / '
        f'{pct(stats["new_hit"], n)-pct(stats["current_hit"], n):+.3f}pt'
    )

    print('\n実際に頭を変更したレース')
    print(f'  変更数  : {sw}/{n} ({pct(sw, n):.2f}%)')
    if sw:
        print(
            f'  現行的中: {stats["switch_current_hit"]}/{sw} '
            f'({pct(stats["switch_current_hit"], sw):.2f}%)'
        )
        print(
            f'  修正的中: {stats["switch_new_hit"]}/{sw} '
            f'({pct(stats["switch_new_hit"], sw):.2f}%)'
        )
        print(
            f'  差      : {stats["switch_new_hit"]-stats["switch_current_hit"]:+d}件 / '
            f'{pct(stats["switch_new_hit"], sw)-pct(stats["switch_current_hit"], sw):+.2f}pt'
        )

    print_direction_table(directions)
    print_head_breakdown(current_counts, picked, n)

    print('\n' + '=' * 132)
    print('診断完了')
    print('次の判断: プラス方向だけを候補として凍結し、マイナス方向は現行Webを維持。方向ごとに時間安定性を再確認する。')
    print('=' * 132)


if __name__ == '__main__':
    main()
