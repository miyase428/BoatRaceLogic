#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
8/14以前で凍結した「現行本命⑤/⑥のみkimariteで2～4へ頭補正」ルールを、
8/15以降の完全未使用期間で前向き評価する。

学習:
- TRAIN_DATASET の全期間
- 現行Web honmei_head != 1 の正式分析対象
- 2C = 6month 差し率
- 3C/4C = 6month 攻め率（まくり + まくり差し）
- sample_n >= 10
- 帯: 0-5 / 5-10 / 10-15 / 15-20 / 20-25 / 25+
- 帯N >= 100 のみ採用、それ未満はコース基礎率へフォールバック

評価:
- HOLDOUT_DATASET の正式分析対象
- 現行本命⑤/⑥だけを補正
- 2～4の学習済みkimariteスコア最大を新頭にする
- ⑤⑥以外の本命は一切変更しない
- kiruは現行維持
- 新頭をfinal_rank先頭へ移動し、現行Web形式
  「2着=切る艇を除いた上位3艇 / 3着=切る艇以外全部」で買い目再構成

重要:
- HOLDOUT の actual_* は評価ラベルにのみ使用
- HOLDOUT の結果で閾値・方向・帯を再調整しない

Usage:
  python3 analysis/validate_honmei56_kimarite_pristine_holdout.py \
    TRAIN_DATASET_CSV HOLDOUT_DATASET_CSV HOLDOUT_BOATS_CSV
"""

from __future__ import annotations

import csv
import sys
from collections import Counter, defaultdict
from pathlib import Path

COURSES = (2, 3, 4)
MIN_SAMPLE = 10
MIN_BAND_N = 100
BAND_ORDER = ('0-5', '5-10', '10-15', '15-20', '20-25', '25+')


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
    base = {c: [0, 0] for c in COURSES}
    bands = {c: defaultdict(lambda: [0, 0]) for c in COURSES}

    for r in rows:
        if not formal(r):
            continue
        if to_int(r.get('honmei_head')) == 1:
            continue

        winner = to_int(r.get('actual_1st_course'))
        for c in COURSES:
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
        for c in COURSES
    }
    band_p = {c: {} for c in COURSES}
    for c in COURSES:
        for b, (hits, n) in bands[c].items():
            if n >= MIN_BAND_N:
                band_p[c][b] = hits / n

    return base, bands, base_p, band_p


def score(row, c, base_p, band_p):
    if not sample_ok(row, c):
        return base_p[c]
    return band_p[c].get(band(feature(row, c)), base_p[c])


def choose_new_head(row, current, base_p, band_p):
    if current not in (5, 6):
        return current
    scores = {c: score(row, c, base_p, band_p) for c in COURSES}
    return min(COURSES, key=lambda c: (-scores[c], c))


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
    rows.sort(key=lambda r: (to_int(r.get('final_rank'), 999), to_int(r.get('lane_number'), 999)))
    return [to_int(r.get('lane_number')) for r in rows]


def kiru_map(bmap):
    return {lane: (to_int(r.get('kiru')) == 1) for lane, r in bmap.items()}


def move_head(rank, head):
    out = [x for x in rank if x != head]
    return [head] + out


def build_bet(rank, kiru, head):
    aite = []
    third = []
    for b in rank:
        if b == head:
            continue
        if kiru.get(b, False):
            continue
        third.append(b)
        if len(aite) < 3:
            aite.append(b)

    aite_set = sorted(aite)
    third_set = sorted(third)
    return {
        'aite': aite_set,
        'third': third_set,
        'kai': f"{head}-{' '.join(map(str, aite_set)).replace(' ', '')}-{' '.join(map(str, third_set)).replace(' ', '')}",
    }


def head_hit(row, head):
    return to_int(row.get('actual_1st')) == head


def bet_hit(row, bet, head):
    return (
        to_int(row.get('actual_1st')) == head
        and to_int(row.get('actual_2nd')) in bet['aite']
        and to_int(row.get('actual_3rd')) in bet['third']
    )


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
        print(f'{label:<14} N=0')
        return
    print(f'{label:<14} N={n:>5}')
    print(
        f'  頭   現行={pct(s["curr_head"], n):>6.2f}%  '
        f'修正={pct(s["new_head"], n):>6.2f}%  '
        f'差={pct(s["new_head"], n)-pct(s["curr_head"], n):+6.2f}pt  '
        f'純増={s["new_head"]-s["curr_head"]:+4d}  '
        f'gain={s["head_gain"]:>4} loss={s["head_loss"]:>4}'
    )
    print(
        f'  買目 現行={pct(s["curr_bet"], n):>6.2f}%  '
        f'修正={pct(s["new_bet"], n):>6.2f}%  '
        f'差={pct(s["new_bet"], n)-pct(s["curr_bet"], n):+6.2f}pt  '
        f'純増={s["new_bet"]-s["curr_bet"]:+4d}  '
        f'gain={s["bet_gain"]:>4} loss={s["bet_loss"]:>4}'
    )


def main():
    if len(sys.argv) != 4:
        print(
            f'Usage: python3 {sys.argv[0]} TRAIN_DATASET_CSV HOLDOUT_DATASET_CSV HOLDOUT_BOATS_CSV',
            file=sys.stderr,
        )
        sys.exit(1)

    train_path = Path(sys.argv[1]).resolve()
    holdout_path = Path(sys.argv[2]).resolve()
    boats_path = Path(sys.argv[3]).resolve()

    for p in (train_path, holdout_path, boats_path):
        if not p.is_file():
            raise RuntimeError(f'CSVがありません: {p}')

    train_rows = read_csv(train_path)
    holdout_rows = [r for r in read_csv(holdout_path) if formal(r)]
    boats = load_boats(boats_path)

    base, bands, base_p, band_p = train_model(train_rows)

    overall = Counter()
    target56 = Counter()
    by_current = defaultdict(Counter)
    by_new = defaultdict(Counter)
    reconstruct = 0
    reconstruct_n = 0
    skipped_boats = 0

    for r in holdout_rows:
        rc = (r.get('race_code') or '').strip()
        bmap = boats.get(rc, {})
        if len(bmap) != 6:
            skipped_boats += 1
            continue

        rank = rank_boats(bmap)
        if len(rank) != 6:
            skipped_boats += 1
            continue

        current = to_int(r.get('honmei_head'))
        if not (1 <= current <= 6):
            continue

        # CSVの現行本命とfinal_rank先頭が一致することを要求
        if rank[0] != current:
            continue

        kiru = kiru_map(bmap)
        curr_bet = build_bet(rank, kiru, current)

        reconstruct_n += 1
        if curr_bet['kai'] == (r.get('honmei_kai') or '').strip():
            reconstruct += 1

        new_head = choose_new_head(r, current, base_p, band_p)
        new_rank = move_head(rank, new_head) if new_head != current else rank
        new_bet = build_bet(new_rank, kiru, new_head)

        ch = head_hit(r, current)
        nh = head_hit(r, new_head)
        cb = bet_hit(r, curr_bet, current)
        nb = bet_hit(r, new_bet, new_head)

        stat_add(overall, ch, nh, cb, nb)

        if current in (5, 6):
            stat_add(target56, ch, nh, cb, nb)
            stat_add(by_current[current], ch, nh, cb, nb)
            stat_add(by_new[new_head], ch, nh, cb, nb)

    print('\n' + '=' * 128)
    print('現行Web本命⑤⑥ × kimarite頭補正 完全未使用ホールドアウト検証')
    print('=' * 128)
    print(f'学習CSV       : {train_path.name}')
    print(f'評価CSV       : {holdout_path.name}')
    print(f'評価boats     : {boats_path.name}')
    print('凍結ルール     : 現行本命⑤/⑥のみ、2C差し率・3/4C攻め率の学習帯別1着率で2～4から新頭を選択')
    print(f'sample条件    : sample_n>={MIN_SAMPLE} / 帯採用N>={MIN_BAND_N}')
    print('重要           : 評価期間の結果で条件・閾値・方向は一切変更しない')
    print(f'買い目再構成一致: {reconstruct}/{reconstruct_n} ({pct(reconstruct, reconstruct_n):.2f}%)')
    print(f'boats不足skip  : {skipped_boats}')

    print('\n【学習側2～4基礎率】')
    for c in COURSES:
        hits, n = base[c]
        name = '差し率' if c == 2 else '攻め率'
        print(f'{c}C {name:<4}: {pct(hits, n):6.2f}%  N={n}')

    print('\n【評価期間 全対象】')
    print_stat('全対象', overall)

    print('\n【現行本命⑤⑥】')
    print_stat('⑤⑥合計', target56)
    print_stat('本命⑤', by_current[5])
    print_stat('本命⑥', by_current[6])

    print('\n【kimarite新頭別】')
    for h in (2, 3, 4):
        print_stat(f'新頭{h}', by_new[h])

    print('\n' + '=' * 128)
    print('完全未使用ホールドアウト検証完了')
    print('判定: ここで頭・買い目ともプラスなら⑤⑥本命補正をWeb実装候補として確定。マイナスなら条件を触らず保留。')
    print('=' * 128)


if __name__ == '__main__':
    main()
