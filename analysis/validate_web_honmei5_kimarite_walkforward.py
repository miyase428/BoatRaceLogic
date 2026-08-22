#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
現行Web本命⑤に対する kimarite ③④への頭補正を、
各評価月より前のデータだけで学習する月次ウォークフォワードで確認する。

目的:
- 後半6か月で発見した 5->3 / 5->4 の改善が、時間順でも維持されるか診断
- 固定の前半6か月モデルではなく、各月の直前までのデータだけで帯別1着率を再学習
- 現行final3差 < 1 の特殊/拮抗ケースを除外する安全ガードも併記

重要:
- 「⑤本命を③④へ下げる」という方向自体は後半結果を見て発見したため、
  この検証も完全な未使用ホールドアウトではない。
- ただし各評価月のモデル学習には、その月以降のactual結果を一切使わない。
- kimarite特徴量は6month point-in-timeのみを使用。

Usage:
  python3 analysis/validate_web_honmei5_kimarite_walkforward.py \
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
EVAL_START = date(2026, 2, 1)
GAP_GUARD_MIN = 1.0


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


def month_key(d: date):
    return f'{d.year:04d}-{d.month:02d}'


def first_day_next_month(d: date):
    if d.month == 12:
        return date(d.year + 1, 1, 1)
    return date(d.year, d.month + 1, 1)


def stat_add(s, curr_hit, new_hit):
    s['n'] += 1
    s['curr'] += int(curr_hit)
    s['new'] += int(new_hit)
    if new_hit and not curr_hit:
        s['gain'] += 1
    if curr_hit and not new_hit:
        s['loss'] += 1


def merge_stat(dst, src):
    for k in ('n', 'curr', 'new', 'gain', 'loss'):
        dst[k] += src[k]


def print_stat(label, s):
    n = s['n']
    if not n:
        print(f'{label:<14} N=0')
        return
    print(
        f'{label:<14} N={n:>5}  '
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

    rows = [
        r for r in read_csv(dataset)
        if formal(r) and to_int(r.get('honmei_head')) != 1
    ]
    rows.sort(key=parse_date)
    boats = load_boats(boats_csv)

    end_date = max(parse_date(r) for r in rows)
    eval_months = []
    cur = EVAL_START
    while cur <= end_date:
        eval_months.append(cur)
        cur = first_day_next_month(cur)

    overall_all = Counter()
    overall_guard = Counter()
    dir_all = defaultdict(Counter)
    dir_guard = defaultdict(Counter)

    print('\n' + '=' * 132)
    print('現行Web本命⑤ × kimarite③④頭補正 月次ウォークフォワード検証')
    print('=' * 132)
    print(f'評価開始     : {EVAL_START}')
    print(f'最終日       : {end_date}')
    print(f'学習方式     : 各評価月より前の全Web本命非1データのみ')
    print(f'特徴         : 3～5C 6month point-in-time 攻め率帯 / sample_n>={MIN_SAMPLE}')
    print(f'帯採用最低N  : {MIN_BAND_N}')
    print(f'Guard版      : 現行final3(⑤)-変更先final3 >= {GAP_GUARD_MIN:.1f} の時だけ変更')
    print('注意         : ⑤を下げる方向自体は既存診断から発見済み。完全な未使用holdoutではない。')

    print('\n【月別】')
    print('-' * 132)

    for month_start in eval_months:
        month_end = first_day_next_month(month_start)
        train_rows = [r for r in rows if parse_date(r) < month_start]
        test_rows = [
            r for r in rows
            if month_start <= parse_date(r) < month_end
            and to_int(r.get('honmei_head')) == 5
        ]

        if not train_rows or not test_rows:
            continue

        base_p, band_p = train(train_rows)
        s_all = Counter()
        s_guard = Counter()
        changed_all = 0
        changed_guard = 0
        no_boat_gap = 0

        for r in test_rows:
            scores = {c: score(r, c, base_p, band_p) for c in COURSES}
            picked = min(COURSES, key=lambda c: (-scores[c], c))
            if picked == 5:
                continue

            winner = to_int(r.get('actual_1st_course'))
            curr_hit = winner == 5
            new_hit = winner == picked

            stat_add(s_all, curr_hit, new_hit)
            stat_add(dir_all[f'5->{picked}'], curr_hit, new_hit)
            changed_all += 1

            rc = (r.get('race_code') or '').strip()
            bmap = boats.get(rc, {})
            if 5 not in bmap or picked not in bmap:
                no_boat_gap += 1
                continue

            gap = to_float(bmap[5].get('final3')) - to_float(bmap[picked].get('final3'))
            if gap >= GAP_GUARD_MIN:
                stat_add(s_guard, curr_hit, new_hit)
                stat_add(dir_guard[f'5->{picked}'], curr_hit, new_hit)
                changed_guard += 1

        merge_stat(overall_all, s_all)
        merge_stat(overall_guard, s_guard)

        key = month_key(month_start)
        print(
            f'{key}  学習N={len(train_rows):>5}  ⑤本命N={len(test_rows):>4}  '
            f'変更={changed_all:>4}  純増={s_all["new"]-s_all["curr"]:+4d}  '
            f'Guard変更={changed_guard:>4}  Guard純増={s_guard["new"]-s_guard["curr"]:+4d}'
        )
        if no_boat_gap:
            print(f'            final3差取得不可={no_boat_gap}')

    print('\n【全月合計：全変更】')
    print_stat('5->3/4', overall_all)
    print('方向別')
    for k in ('5->3', '5->4'):
        print_stat(k, dir_all[k])

    print(f'\n【全月合計：Guard final3差>={GAP_GUARD_MIN:.1f}】')
    print_stat('5->3/4 Guard', overall_guard)
    print('方向別')
    for k in ('5->3', '5->4'):
        print_stat(k, dir_guard[k])

    print('\n' + '=' * 132)
    print('ウォークフォワード検証完了')
    print('見る点: 月ごとの純増が継続するか、Guard版で悪化月を抑えられるか、5->3/5->4双方が維持されるか')
    print('=' * 132)


if __name__ == '__main__':
    main()
