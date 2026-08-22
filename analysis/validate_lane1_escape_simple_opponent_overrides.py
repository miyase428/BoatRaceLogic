#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
1逃げ時のkimarite相手順位改善を、Webへ実装しやすい単純な差し替えルールへ落とせるか検証する。

前半6か月のみで閾値を探索し、後半6か月は完全固定で評価する。

検証ルール:
1) TOP1: 基礎③を④へ変更
   条件 = 3C攻め率 < A かつ 4C攻め率 >= B
2) TOP2: 基礎{3,4}の④を⑤へ変更
   条件 = 4C攻め率 < A かつ 5C攻め率 >= B

攻め率 = 6month point-in-time (まくり + まくり差し)
sample_n >= 10
候補閾値 = 5,10,15,20,25%
学習側で対象N>=100を満たし、正解数の純増が最大のルールを採用。

使い方:
  python3 analysis/validate_lane1_escape_simple_opponent_overrides.py \
    analysis/output/kimarite_analysis_dataset_20250815_20260814.csv
"""

from __future__ import annotations

import csv
import sys
from datetime import date, datetime
from pathlib import Path

MIN_SAMPLE = 10
MIN_TRAIN_N = 100
THRESHOLDS = (5.0, 10.0, 15.0, 20.0, 25.0)


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


def followers(row):
    return {
        to_int(row.get('actual_2nd_course')),
        to_int(row.get('actual_3rd_course')),
    }


def top1_rule_match(row, weak3, strong4):
    return (
        sample_ok(row, 3)
        and sample_ok(row, 4)
        and attack(row, 3) < weak3
        and attack(row, 4) >= strong4
    )


def top2_rule_match(row, weak4, strong5):
    return (
        sample_ok(row, 4)
        and sample_ok(row, 5)
        and attack(row, 4) < weak4
        and attack(row, 5) >= strong5
    )


def eval_top1_subset(rows, weak3, strong4):
    n = base_hits = new_hits = 0
    for row in rows:
        if not top1_rule_match(row, weak3, strong4):
            continue
        f = followers(row)
        n += 1
        base_hits += int(3 in f)
        new_hits += int(4 in f)
    return n, base_hits, new_hits


def eval_top2_subset(rows, weak4, strong5):
    n = base_hit_sum = new_hit_sum = base_any = new_any = base_both = new_both = 0
    for row in rows:
        if not top2_rule_match(row, weak4, strong5):
            continue
        f = followers(row)
        b = len({3, 4} & f)
        m = len({3, 5} & f)
        n += 1
        base_hit_sum += b
        new_hit_sum += m
        base_any += int(b >= 1)
        new_any += int(m >= 1)
        base_both += int(b == 2)
        new_both += int(m == 2)
    return n, base_hit_sum, new_hit_sum, base_any, new_any, base_both, new_both


def choose_top1_rule(train):
    candidates = []
    for a in THRESHOLDS:
        for b in THRESHOLDS:
            n, bh, nh = eval_top1_subset(train, a, b)
            if n < MIN_TRAIN_N:
                continue
            gain = nh - bh
            candidates.append((gain, pct(nh, n) - pct(bh, n), n, a, b, bh, nh))
    candidates.sort(reverse=True)
    return candidates[0] if candidates else None, candidates[:10]


def choose_top2_rule(train):
    candidates = []
    for a in THRESHOLDS:
        for b in THRESHOLDS:
            vals = eval_top2_subset(train, a, b)
            n, bh, nh, ba, na, bb, nb = vals
            if n < MIN_TRAIN_N:
                continue
            gain = nh - bh
            candidates.append((gain, (nh-bh)/n, n, a, b, vals))
    candidates.sort(reverse=True)
    return candidates[0] if candidates else None, candidates[:10]


def print_top1_candidates(cands):
    print('\n【学習側 TOP1候補 上位】')
    print('条件: 3C攻め<A かつ 4C攻め>=B')
    print('A    B       N    基礎③率  変更④率   差pt   正解純増')
    print('-' * 68)
    for gain, diff, n, a, b, bh, nh in cands:
        print(f'{a:>2.0f}% {b:>3.0f}%  {n:>6}   {pct(bh,n):>7.2f}%  {pct(nh,n):>7.2f}%  {diff:>+6.2f}  {gain:>+7}')


def print_top2_candidates(cands):
    print('\n【学習側 TOP2候補 上位】')
    print('条件: 4C攻め<A かつ 5C攻め>=B / {3,4}->{3,5}')
    print('A    B       N    基礎平均  変更平均   差/レース  的中艇純増')
    print('-' * 74)
    for gain, diff, n, a, b, vals in cands:
        _, bh, nh, *_ = vals
        print(f'{a:>2.0f}% {b:>3.0f}%  {n:>6}    {bh/n:>7.4f}    {nh/n:>7.4f}    {diff:>+8.4f}  {gain:>+8}')


def main():
    if len(sys.argv) != 2:
        print('Usage: python3 analysis/validate_lane1_escape_simple_opponent_overrides.py DATASET_CSV', file=sys.stderr)
        sys.exit(1)

    path = Path(sys.argv[1]).resolve()
    if not path.is_file():
        raise RuntimeError(f'CSVがありません: {path}')

    rows = [r for r in load_rows(path) if is_lane1_escape(r)]
    if not rows:
        raise RuntimeError('1逃げ対象がありません')

    split = date(2026, 2, 15)
    train = [r for r in rows if parse_date(r) < split]
    test = [r for r in rows if parse_date(r) >= split]

    best1, cand1 = choose_top1_rule(train)
    best2, cand2 = choose_top2_rule(train)

    print('\n' + '=' * 118)
    print('1逃げ時 kimarite相手差し替え 単純ルール ホールドアウト検証')
    print('=' * 118)
    print(f'学習 : 2025-08-15 ～ 2026-02-14  N={len(train)}')
    print(f'評価 : 2026-02-15 ～ 2026-08-14  N={len(test)}')
    print(f'特徴 : 6month point-in-time 攻め率 / sample_n>={MIN_SAMPLE}')
    print(f'候補 : {", ".join(str(int(x)) for x in THRESHOLDS)}%')
    print(f'学習側最低N : {MIN_TRAIN_N}')

    print_top1_candidates(cand1)
    print_top2_candidates(cand2)

    if best1 is not None:
        _, _, _, a, b, _, _ = best1
        tr = eval_top1_subset(train, a, b)
        te = eval_top1_subset(test, a, b)
        print('\n' + '-' * 118)
        print(f'【採用TOP1ルール】3C攻め < {a:.0f}% かつ 4C攻め >= {b:.0f}% → ③から④へ変更')
        print('-' * 118)
        for label, vals in [('学習', tr), ('評価', te)]:
            n, bh, nh = vals
            print(f'{label}: N={n:>5}  ③的中={pct(bh,n):>6.2f}%  ④的中={pct(nh,n):>6.2f}%  差={pct(nh,n)-pct(bh,n):>+6.2f}pt  純増={nh-bh:+d}')

        n_all = len(test)
        base_all = sum(int(3 in followers(r)) for r in test)
        model_all = base_all
        for row in test:
            if top1_rule_match(row, a, b):
                f = followers(row)
                model_all += int(4 in f) - int(3 in f)
        print(f'評価全体: 基礎③={pct(base_all,n_all):.2f}% → 単純ルール={pct(model_all,n_all):.2f}%  差={pct(model_all,n_all)-pct(base_all,n_all):+.2f}pt')

    if best2 is not None:
        _, _, _, a, b, _ = best2
        tr = eval_top2_subset(train, a, b)
        te = eval_top2_subset(test, a, b)
        print('\n' + '-' * 118)
        print(f'【採用TOP2ルール】4C攻め < {a:.0f}% かつ 5C攻め >= {b:.0f}% → {{3,4}}から{{3,5}}へ変更')
        print('-' * 118)
        for label, vals in [('学習', tr), ('評価', te)]:
            n, bh, nh, ba, na, bb, nb = vals
            print(f'{label}: N={n:>5}  平均的中艇数 {bh/n if n else 0:.4f}->{nh/n if n else 0:.4f}  差={(nh-bh)/n if n else 0:+.4f}  純増={nh-bh:+d}')
            print(f'      1艇以上 {pct(ba,n):.2f}%->{pct(na,n):.2f}% / 2艇とも {pct(bb,n):.2f}%->{pct(nb,n):.2f}%')

    print('\n' + '=' * 118)
    print('単純ルール検証完了')
    print('判定: 学習で選んだ固定条件が後半評価でも同方向なら、Web実装候補として扱う')
    print('=' * 118)


if __name__ == '__main__':
    main()
