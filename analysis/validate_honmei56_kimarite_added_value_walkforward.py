#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
現行Web本命⑤⑥に対し、②③④へ下げる際のkimarite追加価値を
月次ウォークフォワードで確認する。

各評価月より前の全Web本命非1データのみで学習し、評価月の本命⑤⑥について比較する。

比較:
- 現行Web本命⑤/⑥
- Web最上位2/3/4（final_rank最上位）
- 学習側の基礎1着率が最大の静的2/3/4
- kimarite帯別1着率で毎レース選ぶ2/3/4

特徴:
- 2C: 6month point-in-time 差し率
- 3C/4C: 6month point-in-time 攻め率（まくり+まくり差し）
- sample_n >= 10
- 帯: 0-5, 5-10, 10-15, 15-20, 20-25, 25+
- 帯N < 100 はコース基礎率へフォールバック

Usage:
  python3 analysis/validate_honmei56_kimarite_added_value_walkforward.py \
    analysis/output/kimarite_analysis_dataset_20250815_20260814.csv \
    analysis/output/final_prediction_boats_fast_cached_20250815_20260814.csv
"""

from __future__ import annotations

import csv
import sys
from collections import Counter, defaultdict
from datetime import date, datetime
from pathlib import Path

CANDIDATES = (2, 3, 4)
MIN_SAMPLE = 10
MIN_BAND_N = 100
EVAL_START = date(2026, 2, 1)


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
        and to_int(row.get('honmei_head')) in (2, 3, 4, 5, 6)
    )


def sample_ok(row, c):
    return to_int(row.get(f'c{c}_6m_sample_n')) >= MIN_SAMPLE


def feature(row, c):
    if c == 2:
        return to_float(row.get('c2_6m_sashi'))
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


def train_model(rows):
    base = {c: [0, 0] for c in CANDIDATES}
    bands = {c: defaultdict(lambda: [0, 0]) for c in CANDIDATES}

    for r in rows:
        winner = to_int(r.get('actual_1st_course'))
        for c in CANDIDATES:
            if not sample_ok(r, c):
                continue
            hit = int(winner == c)
            base[c][0] += hit
            base[c][1] += 1
            b = band(feature(r, c))
            bands[c][b][0] += hit
            bands[c][b][1] += 1

    base_p = {
        c: (base[c][0] / base[c][1]) if base[c][1] else 0.0
        for c in CANDIDATES
    }
    band_p = {c: {} for c in CANDIDATES}
    for c in CANDIDATES:
        for b, (hits, n) in bands[c].items():
            if n >= MIN_BAND_N:
                band_p[c][b] = hits / n

    return base_p, band_p


def score(row, c, base_p, band_p):
    if not sample_ok(row, c):
        return base_p[c]
    return band_p[c].get(band(feature(row, c)), base_p[c])


def choose_kimarite(row, base_p, band_p):
    scores = {c: score(row, c, base_p, band_p) for c in CANDIDATES}
    return min(CANDIDATES, key=lambda c: (-scores[c], c))


def load_web234(path: Path):
    rows = read_csv(path)
    grouped = defaultdict(dict)
    for r in rows:
        rc = (r.get('race_code') or '').strip()
        lane = to_int(r.get('lane_number'))
        if rc and lane in CANDIDATES:
            grouped[rc][lane] = r

    out = {}
    for rc, bmap in grouped.items():
        if len(bmap) != 3:
            continue
        out[rc] = min(
            CANDIDATES,
            key=lambda c: (
                to_int(bmap[c].get('final_rank'), 999),
                c,
            ),
        )
    return out


def month_start(d):
    return date(d.year, d.month, 1)


def next_month(d):
    if d.month == 12:
        return date(d.year + 1, 1, 1)
    return date(d.year, d.month + 1, 1)


def add_compare(s, actual, current, web, static, kimi):
    s['n'] += 1
    s['current'] += int(actual == current)
    s['web'] += int(actual == web)
    s['static'] += int(actual == static)
    s['kimi'] += int(actual == kimi)

    if actual == kimi and actual != web:
        s['kimi_vs_web_gain'] += 1
    if actual == web and actual != kimi:
        s['kimi_vs_web_loss'] += 1
    if actual == kimi and actual != static:
        s['kimi_vs_static_gain'] += 1
    if actual == static and actual != kimi:
        s['kimi_vs_static_loss'] += 1


def print_summary(label, s):
    n = s['n']
    if not n:
        print(f'{label}: N=0')
        return
    print(f'{label} N={n}')
    print(f'  現行Web   : {s["current"]:>4}/{n} = {pct(s["current"], n):6.2f}%')
    print(f'  Web234    : {s["web"]:>4}/{n} = {pct(s["web"], n):6.2f}%')
    print(f'  静的234   : {s["static"]:>4}/{n} = {pct(s["static"], n):6.2f}%')
    print(f'  kimarite  : {s["kimi"]:>4}/{n} = {pct(s["kimi"], n):6.2f}%')
    print(
        f'  kim-Web234: 純増={s["kimi_vs_web_gain"]-s["kimi_vs_web_loss"]:+4d} '
        f'(gain={s["kimi_vs_web_gain"]} loss={s["kimi_vs_web_loss"]})'
    )
    print(
        f'  kim-静的  : 純増={s["kimi_vs_static_gain"]-s["kimi_vs_static_loss"]:+4d} '
        f'(gain={s["kimi_vs_static_gain"]} loss={s["kimi_vs_static_loss"]})'
    )


def main():
    if len(sys.argv) != 3:
        print(f'Usage: python3 {sys.argv[0]} DATASET_CSV BOATS_CSV', file=sys.stderr)
        sys.exit(1)

    dataset = Path(sys.argv[1]).resolve()
    boats = Path(sys.argv[2]).resolve()
    if not dataset.is_file():
        raise RuntimeError(f'CSVがありません: {dataset}')
    if not boats.is_file():
        raise RuntimeError(f'CSVがありません: {boats}')

    rows = [r for r in read_csv(dataset) if formal(r)]
    web234 = load_web234(boats)
    if not rows:
        raise RuntimeError('正式分析対象がありません')

    end = max(parse_date(r) for r in rows)
    m = EVAL_START

    monthly = []
    overall = Counter()
    by_head = defaultdict(Counter)

    while m <= end:
        nm = next_month(m)
        train_rows = [r for r in rows if parse_date(r) < m]
        eval_rows = [
            r for r in rows
            if m <= parse_date(r) < nm
            and to_int(r.get('honmei_head')) in (5, 6)
        ]

        if not train_rows or not eval_rows:
            m = nm
            continue

        base_p, band_p = train_model(train_rows)
        static = min(CANDIDATES, key=lambda c: (-base_p[c], c))
        s = Counter()

        for r in eval_rows:
            rc = (r.get('race_code') or '').strip()
            web = web234.get(rc)
            if web not in CANDIDATES:
                continue

            current = to_int(r.get('honmei_head'))
            actual = to_int(r.get('actual_1st_course'))
            kimi = choose_kimarite(r, base_p, band_p)

            add_compare(s, actual, current, web, static, kimi)
            add_compare(overall, actual, current, web, static, kimi)
            add_compare(by_head[current], actual, current, web, static, kimi)

        monthly.append((m.strftime('%Y-%m'), len(train_rows), static, s))
        m = nm

    print('\n' + '=' * 132)
    print('現行Web本命⑤⑥：kimarite追加価値 月次ウォークフォワード検証')
    print('=' * 132)
    print(f'評価開始     : {EVAL_START}')
    print(f'最終日       : {end}')
    print('学習方式     : 各評価月より前の全Web本命非1データのみ')
    print('対象         : 評価月の現行本命⑤/⑥')
    print('比較         : 現行 / Web最上位2-4 / 学習基礎率の静的2-4 / kimarite帯別2-4')
    print('特徴         : 2C=差し率 / 3C・4C=攻め率（6month point-in-time）')
    print(f'sample条件   : sample_n >= {MIN_SAMPLE} / 帯採用最低N={MIN_BAND_N}')
    print('注意         : ⑤⑥を下げる方向は既存診断から発見済み。完全な未使用holdoutではない。')

    print('\n【月別】')
    print('-' * 132)
    print('月       学習N   N   静的  現行%  Web234%  kim%   kim-Web純増  kim-静的純増')
    print('-' * 132)
    for month, train_n, static, s in monthly:
        n = s['n']
        kw = s['kimi_vs_web_gain'] - s['kimi_vs_web_loss']
        ks = s['kimi_vs_static_gain'] - s['kimi_vs_static_loss']
        print(
            f'{month} {train_n:>7} {n:>4}    {static}   '
            f'{pct(s["current"], n):>6.2f}  {pct(s["web"], n):>7.2f}  '
            f'{pct(s["kimi"], n):>6.2f}   {kw:+6d}       {ks:+6d}'
        )

    print('\n【全月合計】')
    print_summary('⑤⑥合計', overall)

    print('\n【現行本命別】')
    print_summary('本命⑤', by_head[5])
    print_summary('本命⑥', by_head[6])

    print('\n' + '=' * 132)
    print('ウォークフォワード検証完了')
    print('見る点: kimariteがWeb234を複数月で継続的に上回るか、⑤・⑥の双方で追加価値が残るか')
    print('=' * 132)


if __name__ == '__main__':
    main()
