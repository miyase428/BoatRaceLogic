#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
現行Webが5号艇を本命にしたレースだけを対象に、
前半6か月で学習した3～5Cのkimarite攻め率帯別1着率で
5→3 / 5→4 へ変更するケースの安定性を診断する。

重要:
- この条件は後半6か月の方向別結果を見て発見したため、今回は診断用途。
- 後半6か月を新たなホールドアウトとして扱わない。
- actual_* は評価ラベルとしてのみ使用。

Usage:
  python3 analysis/analyze_web_honmei5_kimarite_demotion.py \
    analysis/output/kimarite_analysis_dataset_20250815_20260814.csv \
    analysis/output/final_prediction_boats_fast_cached_20250815_20260814.csv
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


def sample_ok(row, c):
    return to_int(row.get(f'c{c}_6m_sample_n')) >= MIN_SAMPLE


def attack(row, c):
    return (
        to_float(row.get(f'c{c}_6m_makuri'))
        + to_float(row.get(f'c{c}_6m_makurizashi'))
    )


def band(v):
    if v < 5:
        return '0-5'
    if v < 10:
        return '5-10'
    if v < 15:
        return '10-15'
    if v < 20:
        return '15-20'
    if v < 25:
        return '20-25'
    return '25+'


def train(rows):
    base = {c: [0, 0] for c in COURSES}
    bands = {c: defaultdict(lambda: [0, 0]) for c in COURSES}

    for r in rows:
        winner = to_int(r.get('actual_1st_course'))
        for c in COURSES:
            if not sample_ok(r, c):
                continue
            hit = int(winner == c)
            base[c][0] += hit
            base[c][1] += 1
            b = band(attack(r, c))
            bands[c][b][0] += hit
            bands[c][b][1] += 1

    base_p = {
        c: (base[c][0] / base[c][1]) if base[c][1] else 0.0
        for c in COURSES
    }
    band_p = {c: {} for c in COURSES}
    for c in COURSES:
        for b, (hits, n) in bands[c].items():
            if n >= MIN_BAND_N:
                band_p[c][b] = hits / n
    return base_p, band_p


def score(row, c, base_p, band_p):
    if not sample_ok(row, c):
        return base_p[c]
    return band_p[c].get(band(attack(row, c)), base_p[c])


def load_boats(path: Path):
    rows = read_csv(path)
    out = defaultdict(dict)
    for r in rows:
        rc = (r.get('race_code') or '').strip()
        lane = to_int(r.get('lane_number'))
        if rc and 1 <= lane <= 6:
            out[rc][lane] = r
    return out


def gap_band(g):
    if g < 0:
        return '<0'
    if g < 0.5:
        return '0-0.5'
    if g < 1:
        return '0.5-1'
    if g < 2:
        return '1-2'
    if g < 3:
        return '2-3'
    if g < 5:
        return '3-5'
    return '5+'


def stat_add(s, curr_hit, new_hit):
    s['n'] += 1
    s['curr'] += int(curr_hit)
    s['new'] += int(new_hit)
    if new_hit and not curr_hit:
        s['gain'] += 1
    if curr_hit and not new_hit:
        s['loss'] += 1


def print_stat(label, s):
    n = s['n']
    if not n:
        print(f'{label:<12} N=0')
        return
    print(
        f'{label:<12} N={n:>5}  '
        f'現行={pct(s["curr"], n):>6.2f}%  '
        f'変更={pct(s["new"], n):>6.2f}%  '
        f'差={pct(s["new"], n)-pct(s["curr"], n):+6.2f}pt  '
        f'純増={s["new"]-s["curr"]:+4d}  '
        f'gain={s["gain"]:>4} loss={s["loss"]:>4}'
    )


def main():
    if len(sys.argv) != 3:
        print(f'Usage: python3 {sys.argv[0]} DATASET_CSV BOATS_CSV', file=sys.stderr)
        sys.exit(1)

    dataset = Path(sys.argv[1]).resolve()
    boats_csv = Path(sys.argv[2]).resolve()
    if not dataset.is_file():
        raise RuntimeError(f'CSVがありません: {dataset}')
    if not boats_csv.is_file():
        raise RuntimeError(f'CSVがありません: {boats_csv}')

    rows = [r for r in read_csv(dataset) if formal(r) and to_int(r.get('honmei_head')) != 1]
    train_rows = [r for r in rows if parse_date(r) < SPLIT]
    test_rows = [r for r in rows if parse_date(r) >= SPLIT]
    base_p, band_p = train(train_rows)
    boats = load_boats(boats_csv)

    eligible = 0
    unchanged = 0
    switched = Counter()
    overall = Counter()
    direction = defaultdict(Counter)
    monthly = defaultdict(Counter)
    gaps = defaultdict(Counter)

    for r in test_rows:
        if to_int(r.get('honmei_head')) != 5:
            continue
        eligible += 1

        scores = {c: score(r, c, base_p, band_p) for c in COURSES}
        picked = min(COURSES, key=lambda c: (-scores[c], c))
        if picked == 5:
            unchanged += 1
            continue

        winner = to_int(r.get('actual_1st_course'))
        curr_hit = winner == 5
        new_hit = winner == picked
        stat_add(overall, curr_hit, new_hit)
        stat_add(direction[f'5->{picked}'], curr_hit, new_hit)
        switched[picked] += 1

        month = parse_date(r).strftime('%Y-%m')
        stat_add(monthly[month], curr_hit, new_hit)

        rc = (r.get('race_code') or '').strip()
        bmap = boats.get(rc, {})
        if 5 in bmap and picked in bmap:
            gap = to_float(bmap[5].get('final3')) - to_float(bmap[picked].get('final3'))
            stat_add(gaps[gap_band(gap)], curr_hit, new_hit)

    print('\n' + '=' * 124)
    print('現行Web本命⑤ × kimarite ③④への頭補正 安定性診断')
    print('=' * 124)
    print(f'学習 : 前半6か月 Web本命非1 N={len(train_rows)}')
    print(f'診断 : 後半6か月 Web本命非1 N={len(test_rows)}')
    print('注意 : 後半結果から発見した方向を掘る診断。新規ホールドアウトではない。')
    print(f'Web本命⑤対象 : {eligible}')
    print(f'kimariteでも⑤ : {unchanged}')
    print(f'③④へ変更     : {overall["n"]} ({pct(overall["n"], eligible):.2f}%)')

    print('\n【変更レース全体】')
    print_stat('5->3/4', overall)

    print('\n【変更方向別】')
    for k in ('5->3', '5->4'):
        print_stat(k, direction[k])

    print('\n【月別】')
    for m in sorted(monthly):
        print_stat(m, monthly[m])

    print('\n【現行final3(⑤) - 変更先final3 の差別】')
    print('※ <0 は、⑤のfinal3自体は変更先より低いのにSTEP4等で本命になっているケースを含む')
    for b in ('<0', '0-0.5', '0.5-1', '1-2', '2-3', '3-5', '5+'):
        print_stat(b, gaps[b])

    print('\n' + '=' * 124)
    print('診断完了')
    print('見る点: +42件が月をまたいで安定しているか、特定月/特定final3差だけの効果ではないか')
    print('=' * 124)


if __name__ == '__main__':
    main()
