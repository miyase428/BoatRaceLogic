#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
現行Web本命が5/6のレースに対して、前半6か月で学習した
2C差し率 / 3C・4C攻め率の帯別1着率から新しい頭を2～4内で選び、
後半6か月で「頭的中」だけでなく現行Web形式の本命買い目まで通して検証する。

重要:
- 学習: 2025-08-15～2026-02-14 の Web本命非1
- 評価: 2026-02-15～2026-08-14
- actual_* は評価ラベルのみ
- 頭変更時は選んだ艇を最終順位の先頭へ移動し、他艇の相対順は維持
- kiru判定は現行Webのまま
- 2着候補は頭・切る艇を除く最終順位上位3艇
- 3着候補は頭・切る艇を除く全艇
- 5/6本命以外は変更しない

Usage:
  python3 analysis/validate_honmei56_kimarite_end_to_end_bet.py \
    analysis/output/kimarite_analysis_dataset_20250815_20260814.csv \
    analysis/output/final_prediction_boats_fast_cached_20250815_20260814.csv
"""

from __future__ import annotations

import csv
import sys
from collections import Counter, defaultdict
from datetime import date, datetime
from pathlib import Path

MIN_SAMPLE = 10
MIN_BAND_N = 100
SPLIT = date(2026, 2, 15)
CANDIDATES = (2, 3, 4)


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


def feature_value(row, c):
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


def train(rows):
    base = {c: [0, 0] for c in CANDIDATES}
    bands = {c: defaultdict(lambda: [0, 0]) for c in CANDIDATES}

    for r in rows:
        actual = to_int(r.get('actual_1st_course'))
        for c in CANDIDATES:
            if not sample_ok(r, c):
                continue
            hit = int(actual == c)
            base[c][0] += hit
            base[c][1] += 1
            b = band(feature_value(r, c))
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
    return band_p[c].get(band(feature_value(row, c)), base_p[c])


def choose_head(row, base_p, band_p):
    scores = {c: score(row, c, base_p, band_p) for c in CANDIDATES}
    return min(CANDIDATES, key=lambda c: (-scores[c], c))


def load_boats(path: Path):
    rows = read_csv(path)
    out = defaultdict(dict)
    for r in rows:
        rc = (r.get('race_code') or '').strip()
        lane = to_int(r.get('lane_number'))
        if rc and 1 <= lane <= 6:
            out[rc][lane] = r
    return out


def rank_boats(bmap):
    rows = list(bmap.values())
    rows.sort(key=lambda r: (to_int(r.get('final_rank'), 99), to_int(r.get('lane_number'), 99)))
    return [to_int(r.get('lane_number')) for r in rows]


def move_to_front(ranks, head):
    return [head] + [b for b in ranks if b != head]


def build_bet(ranks, bmap, head):
    aite = []
    third = []
    for b in ranks:
        if b == head:
            continue
        if to_int(bmap[b].get('kiru')) == 1:
            continue
        third.append(b)
        if len(aite) < 3:
            aite.append(b)
    return {
        'aite': set(aite),
        'third': set(third),
        'kai': f'{head}-{"".join(map(str, sorted(aite)))}-{"".join(map(str, sorted(third)))}',
    }


def hit_head(row, head):
    return to_int(row.get('actual_1st_course')) == head


def hit_bet(row, bet):
    a1 = to_int(row.get('actual_1st_course'))
    a2 = to_int(row.get('actual_2nd_course'))
    a3 = to_int(row.get('actual_3rd_course'))
    head = int(bet['kai'].split('-', 1)[0])
    return a1 == head and a2 in bet['aite'] and a3 in bet['third']


def stat_add(s, curr_head_hit, new_head_hit, curr_bet_hit, new_bet_hit):
    s['n'] += 1
    s['curr_head'] += int(curr_head_hit)
    s['new_head'] += int(new_head_hit)
    s['curr_bet'] += int(curr_bet_hit)
    s['new_bet'] += int(new_bet_hit)
    if new_head_hit and not curr_head_hit:
        s['head_gain'] += 1
    if curr_head_hit and not new_head_hit:
        s['head_loss'] += 1
    if new_bet_hit and not curr_bet_hit:
        s['bet_gain'] += 1
    if curr_bet_hit and not new_bet_hit:
        s['bet_loss'] += 1


def print_stat(label, s):
    n = s['n']
    if not n:
        print(f'{label:<10} N=0')
        return
    print(f'{label:<10} N={n:>5}')
    print(
        f'  頭   現行={pct(s["curr_head"], n):6.2f}%  修正={pct(s["new_head"], n):6.2f}%  '
        f'差={pct(s["new_head"], n)-pct(s["curr_head"], n):+6.2f}pt  '
        f'純増={s["new_head"]-s["curr_head"]:+4d}  gain={s["head_gain"]:>4} loss={s["head_loss"]:>4}'
    )
    print(
        f'  買目 現行={pct(s["curr_bet"], n):6.2f}%  修正={pct(s["new_bet"], n):6.2f}%  '
        f'差={pct(s["new_bet"], n)-pct(s["curr_bet"], n):+6.2f}pt  '
        f'純増={s["new_bet"]-s["curr_bet"]:+4d}  gain={s["bet_gain"]:>4} loss={s["bet_loss"]:>4}'
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

    rows = [r for r in read_csv(dataset) if formal(r)]
    train_rows = [r for r in rows if parse_date(r) < SPLIT and to_int(r.get('honmei_head')) != 1]
    test_rows = [r for r in rows if parse_date(r) >= SPLIT]
    base_p, band_p = train(train_rows)
    boats = load_boats(boats_csv)

    all_stats = Counter()
    target_stats = Counter()
    by_current = defaultdict(Counter)
    by_new = defaultdict(Counter)
    monthly = defaultdict(Counter)
    reconstruct = Counter()

    for r in test_rows:
        rc = (r.get('race_code') or '').strip()
        bmap = boats.get(rc, {})
        if len(bmap) != 6:
            continue

        ranks = rank_boats(bmap)
        current_head = to_int(r.get('honmei_head'))
        if not ranks or ranks[0] != current_head:
            continue

        curr_bet = build_bet(ranks, bmap, current_head)
        reconstruct['n'] += 1
        if curr_bet['kai'] == (r.get('honmei_kai') or '').strip():
            reconstruct['match'] += 1

        if current_head in (5, 6):
            new_head = choose_head(r, base_p, band_p)
            new_ranks = move_to_front(ranks, new_head)
            new_bet = build_bet(new_ranks, bmap, new_head)
        else:
            new_head = current_head
            new_bet = curr_bet

        curr_head_hit = hit_head(r, current_head)
        new_head_hit = hit_head(r, new_head)
        curr_bet_hit = hit_bet(r, curr_bet)
        new_bet_hit = hit_bet(r, new_bet)

        stat_add(all_stats, curr_head_hit, new_head_hit, curr_bet_hit, new_bet_hit)

        if current_head in (5, 6):
            stat_add(target_stats, curr_head_hit, new_head_hit, curr_bet_hit, new_bet_hit)
            stat_add(by_current[current_head], curr_head_hit, new_head_hit, curr_bet_hit, new_bet_hit)
            stat_add(by_new[new_head], curr_head_hit, new_head_hit, curr_bet_hit, new_bet_hit)
            month = parse_date(r).strftime('%Y-%m')
            stat_add(monthly[month], curr_head_hit, new_head_hit, curr_bet_hit, new_bet_hit)

    print('\n' + '=' * 128)
    print('現行Web本命⑤⑥ × kimarite頭補正 エンドツーエンド買い目検証')
    print('=' * 128)
    print('学習 : 2025-08-15 ～ 2026-02-14 のWeb本命非1')
    print('評価 : 2026-02-15 ～ 2026-08-14')
    print('補正 : 現行本命⑤/⑥だけ、2C差し率・3/4C攻め率の学習帯別1着率で頭を2～4から選択')
    print('買目 : 選択頭を最終順位先頭へ移動。kiruは現行維持。2着上位3艇・3着切る艇以外。')
    print('注意 : ⑤⑥を下げる方向自体は既存診断から発見済み。完全な未使用holdoutではない。')
    print(
        f'買い目再構成一致 : {reconstruct["match"]}/{reconstruct["n"]} '
        f'({pct(reconstruct["match"], reconstruct["n"]):.2f}%)'
    )

    print('\n【評価期間 全対象への影響】')
    print_stat('全対象', all_stats)

    print('\n【現行本命⑤⑥だけ】')
    print_stat('⑤⑥合計', target_stats)
    print_stat('本命⑤', by_current[5])
    print_stat('本命⑥', by_current[6])

    print('\n【kimarite新頭別】')
    for c in CANDIDATES:
        print_stat(f'新頭{c}', by_new[c])

    print('\n【月別：現行本命⑤⑥】')
    for m in sorted(monthly):
        print_stat(m, monthly[m])

    print('\n' + '=' * 128)
    print('エンドツーエンド検証完了')
    print('判定: 頭的中の改善が本命買い目的中にも残るか、⑤/⑥・月別の両方で確認する。')
    print('=' * 128)


if __name__ == '__main__':
    main()
