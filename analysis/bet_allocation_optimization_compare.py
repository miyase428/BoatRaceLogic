#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
買い目最適化 STEP 2：固定構造に対する1R1000円・100円単位の配分比較

目的
----
STEP1でDEVだけを使って固定した各ファミリー代表について、
1R1000円を100円単位でどう配るかをDEVで選び、F1/F2では完全固定で前方評価する。

重要
----
- 構造（購入ゲート・3連単点数・2連単点数）はSTEP1と同じ方法でDEVだけから再選択する。
- 配分もDEVだけで選ぶ。F1/F2を見て配分は変えない。
- 全購入点へ最低100円を配る。
- 同一券種内は、確率順位が高い買い目ほど購入額が小さくならない単調配分だけを候補にする。
- MIXEDで2連単・3連単が同時的中した場合は払戻を合算する。
- 過去全候補オッズは使わないため、オッズ連動配分ではなく「順位別固定配分」の検証。
- Web / PredictionLogic / 本番買い目は変更しない。

Usage:
python3 analysis/bet_allocation_optimization_compare.py \
  analysis/output/final_prediction_boats_20260715_20260814.csv \
  analysis/output/final_prediction_boats_fast_cached_20260815_20260822.csv \
  analysis/output/final_prediction_boats_fast_cached_20260823_20260831.csv
"""

from __future__ import annotations

import itertools
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import bet_structure_optimization_compare as step1
import trifecta_probability_order_compare as step3


BUDGET_UNITS = 10  # 100円 × 10 = 1000円
MIN_UNIT = 1
TOP_ALLOCATIONS_TO_SHOW = 8


def positive_compositions(total, n):
    """正整数n個でtotalを作る全組合せ。"""
    if n <= 0:
        return
    if n == 1:
        if total >= 1:
            yield (total,)
        return
    for first in range(1, total - n + 2):
        for rest in positive_compositions(total - first, n - 1):
            yield (first,) + rest


def monotone_within_types(units, tri_count, exact_count):
    tri = units[:tri_count]
    exact = units[tri_count:tri_count + exact_count]
    if tri and any(tri[i] < tri[i + 1] for i in range(len(tri) - 1)):
        return False
    if exact and any(exact[i] < exact[i + 1] for i in range(len(exact) - 1)):
        return False
    return True


def allocation_candidates(tri_count, exact_count):
    n = int(tri_count) + int(exact_count)
    if n <= 0 or n > BUDGET_UNITS:
        return []
    out = []
    for units in positive_compositions(BUDGET_UNITS, n):
        if monotone_within_types(units, int(tri_count), int(exact_count)):
            out.append(tuple(int(x) for x in units))
    return out


def balanced_units(tri_count, exact_count):
    """100円単位でなるべく均等。余りは券種内上位から交互に配る。"""
    tri_count = int(tri_count)
    exact_count = int(exact_count)
    n = tri_count + exact_count
    if n <= 0:
        return tuple()

    base = BUDGET_UNITS // n
    units = [base] * n
    rem = BUDGET_UNITS - base * n

    order = []
    max_len = max(tri_count, exact_count)
    for i in range(max_len):
        if i < tri_count:
            order.append(i)
        if i < exact_count:
            order.append(tri_count + i)
    for idx in order[:rem]:
        units[idx] += 1

    # 念のため券種内単調性を満たす形へ並べ直す。
    if tri_count:
        units[:tri_count] = sorted(units[:tri_count], reverse=True)
    if exact_count:
        units[tri_count:] = sorted(units[tri_count:], reverse=True)
    return tuple(units)


def allocation_label(units, tri_count, exact_count):
    tri = units[:tri_count]
    exact = units[tri_count:tri_count + exact_count]
    parts = []
    if tri_count:
        parts.append('T3=' + '/'.join(str(x * 100) for x in tri))
    if exact_count:
        parts.append('E2=' + '/'.join(str(x * 100) for x in exact))
    return ' '.join(parts)


def evaluate_allocation(rows, strategy, units):
    th = float(strategy['threshold'])
    tri_count = int(strategy['tri'])
    exact_count = int(strategy['exact'])
    expected_len = tri_count + exact_count
    if len(units) != expected_len or sum(units) != BUDGET_UNITS:
        raise ValueError('配分unitsが構造と一致しません')

    bet_races = 0
    hit_races = 0
    plus_races = 0
    tri_hits = 0
    exact_hits = 0
    both_hits = 0
    invest = 0.0
    returns = 0.0

    tri_budget = sum(units[:tri_count]) * 100
    exact_budget = sum(units[tri_count:]) * 100

    for row in rows:
        if float(row['head1_mass']) < th:
            continue

        tri_order = row['tri_order'][:tri_count]
        exact_order = row['exact_order'][:exact_count]

        tri_pos = None
        if tri_count:
            try:
                tri_pos = tri_order.index(row['actual_idx'])
            except ValueError:
                tri_pos = None

        exact_pos = None
        if exact_count:
            try:
                exact_pos = exact_order.index(row['actual_exacta'])
            except ValueError:
                exact_pos = None

        tri_hit = tri_pos is not None
        exact_hit = exact_pos is not None
        any_hit = tri_hit or exact_hit

        payout = 0.0
        if tri_hit:
            stake_units = units[int(tri_pos)]
            payout += float(row['trifecta_payout']) * stake_units
            tri_hits += 1
        if exact_hit:
            stake_units = units[tri_count + int(exact_pos)]
            payout += float(row['exacta_payout']) * stake_units
            exact_hits += 1
        if tri_hit and exact_hit:
            both_hits += 1

        bet_races += 1
        invest += 1000.0
        returns += payout
        if any_hit:
            hit_races += 1
        if payout >= 1000.0:
            plus_races += 1

    return {
        'bet_races': bet_races,
        'hit_races': hit_races,
        'hit_rate': hit_races / bet_races if bet_races else 0.0,
        'plus_races': plus_races,
        'plus_rate': plus_races / bet_races if bet_races else 0.0,
        'tri_hits': tri_hits,
        'exact_hits': exact_hits,
        'both_hits': both_hits,
        'tri_budget': tri_budget,
        'exact_budget': exact_budget,
        'investment': invest,
        'return': returns,
        'roi': returns / invest if invest else 0.0,
    }


def tune_allocation(dev_rows, strategy):
    tri = int(strategy['tri'])
    exact = int(strategy['exact'])
    candidates = allocation_candidates(tri, exact)
    if not candidates:
        return None, []

    rows = []
    for units in candidates:
        r = evaluate_allocation(dev_rows, strategy, units)
        # DEV ROI優先。同率ならプラス率、的中率、均等配分への近さ、上位集中し過ぎない方を優先。
        spread = max(units) - min(units)
        key = (
            -r['roi'],
            -r['plus_rate'],
            -r['hit_rate'],
            spread,
            -min(units),
            units,
        )
        rows.append((key, units, r))
    rows.sort(key=lambda x: x[0])
    return rows[0], rows


def print_top_allocations(family, strategy, ranked):
    print(f"\n【{family} DEV 配分上位{TOP_ALLOCATIONS_TO_SHOW}】")
    print(f"構造: {step1.strategy_label(strategy['threshold'], strategy['tri'], strategy['exact'])}")
    print('順位 配分                                      購入R 的中率  プラス率  T3予算 E2予算   ROI')
    print('-' * 112)
    for i, (_key, units, r) in enumerate(ranked[:TOP_ALLOCATIONS_TO_SHOW], start=1):
        label = allocation_label(units, int(strategy['tri']), int(strategy['exact']))
        print(
            f"{i:>2d}   {label:<42} {r['bet_races']:>5d}  {r['hit_rate']*100:>6.2f}%  "
            f"{r['plus_rate']*100:>7.2f}%  {r['tri_budget']:>5d}  {r['exact_budget']:>5d}  {r['roi']*100:>7.2f}%"
        )


def print_period(title, rows, selected, allocations, balanced):
    print(f"\n【{title}】")
    print('Family            種別       配分                                      購入R 的中率 プラス率   ROI')
    print('-' * 118)
    for family in ('TRIFECTA_ONLY', 'EXACTA_ONLY', 'MIXED'):
        s = selected.get(family)
        if s is None:
            continue
        for kind, units in (('均等100円', balanced[family]), ('DEV最適', allocations[family])):
            r = evaluate_allocation(rows, s, units)
            label = allocation_label(units, int(s['tri']), int(s['exact']))
            print(
                f"{family:<17} {kind:<10} {label:<42} {r['bet_races']:>5d}  "
                f"{r['hit_rate']*100:>6.2f}% {r['plus_rate']*100:>7.2f}%  {r['roi']*100:>7.2f}%"
            )


def combine_rows(*parts):
    out = []
    for p in parts:
        out.extend(p)
    return out


def main():
    if len(sys.argv) != 4:
        print('Usage: python3 analysis/bet_allocation_optimization_compare.py DEV_BOATS_CSV F1_BOATS_CSV F2_BOATS_CSV')
        sys.exit(1)

    dev_csv, f1_csv, f2_csv = sys.argv[1], sys.argv[2], sys.argv[3]
    print('STEP1固定構造を再現し、1000円・100円単位の配分をDEVだけで探索中...')

    d1 = step3.build_common_records(dev_csv, f1_csv)
    d2 = step3.build_common_records(dev_csv, f2_csv)
    dev_records = d1['records']['P1']
    f1_records = d1['records']['P2']
    f2_records = d2['records']['P2']
    if not dev_records or not f1_records or not f2_records:
        raise RuntimeError('DEV/F1/F2の共通評価レースがありません')

    payouts, tri_col, exact_col = step1.load_payouts(d1['p1_start'], d2['p2_end'])
    dev = step1.build_rows(dev_records, payouts)
    f1 = step1.build_rows(f1_records, payouts)
    f2 = step1.build_rows(f2_records, payouts)
    if not dev or not f1 or not f2:
        raise RuntimeError('払戻結合後のDEV/F1/F2評価レースがありません')

    dev_grid = step1.all_strategies(dev)
    selected = {
        family: step1.select_family(dev_grid, family)
        for family in ('TRIFECTA_ONLY', 'EXACTA_ONLY', 'MIXED')
    }

    allocations = {}
    balanced = {}
    ranked_map = {}
    for family, s in selected.items():
        if s is None:
            continue
        best, ranked = tune_allocation(dev, s)
        if best is None:
            raise RuntimeError(f'{family}の配分候補がありません')
        allocations[family] = best[1]
        ranked_map[family] = ranked
        balanced[family] = balanced_units(int(s['tri']), int(s['exact']))

    print('=' * 154)
    print('買い目最適化 STEP 2：1R1000円・100円単位 配分比較')
    print('=' * 154)
    print(f"DEV                 : {d1['p1_start']} ～ {d1['p1_end']} / 評価={len(dev)}R")
    print(f"F1 完全前方         : {d1['p2_start']} ～ {d1['p2_end']} / 評価={len(f1)}R")
    print(f"F2 完全前方         : {d2['p2_start']} ～ {d2['p2_end']} / 評価={len(f2)}R")
    print(f"3連単払戻列         : boat_race.race_payouts.{tri_col}")
    print(f"2連単払戻列         : boat_race.race_payouts.{exact_col}")
    print('構造選択             : STEP1と同じくDEVのみ')
    print('配分選択             : DEVのみ。全点100円以上、券種内は確率順位順に単調非増加')
    print('F1/F2                : 構造・配分とも完全固定')
    print('過去オッズ           : 未使用。順位別固定配分の検証')

    print('\n【STEP1から再現した固定構造】')
    for family, s in selected.items():
        if s is None:
            continue
        print(f"{family:<17}: {step1.strategy_label(s['threshold'], s['tri'], s['exact'])}")

    for family in ('TRIFECTA_ONLY', 'EXACTA_ONLY', 'MIXED'):
        if family in ranked_map:
            print_top_allocations(family, selected[family], ranked_map[family])

    print('\n【DEVで固定した配分】')
    for family in ('TRIFECTA_ONLY', 'EXACTA_ONLY', 'MIXED'):
        if family not in allocations:
            continue
        s = selected[family]
        print(
            f"{family:<17}: {allocation_label(allocations[family], int(s['tri']), int(s['exact']))} "
            f"/ 均等={allocation_label(balanced[family], int(s['tri']), int(s['exact']))}"
        )

    print_period('DEV 参考', dev, selected, allocations, balanced)
    print_period('F1 完全前方', f1, selected, allocations, balanced)
    print_period('F2 完全前方', f2, selected, allocations, balanced)
    print_period('F1+F2 前方合算', combine_rows(f1, f2), selected, allocations, balanced)

    print('\n【判断方針】')
    print('1. DEVでROIが高い配分でも、F1/F2で均等配分を安定して上回るかを重視する。')
    print('2. F1/F2で逆転するなら、順位別固定配分は過学習の可能性が高く均等配分を優先する。')
    print('3. STEP1で前方が最も安定したTRIFECTA_ONLYも、配分は別問題として独立評価する。')
    print('4. 次STEPでオッズ取得時の合成オッズ・期待値ゲートを現在レース運用へつなぐ。')
    print('5. 本番Web/PredictionLogic/買い目ロジックはまだ変更しない。')
    print('=' * 154)


if __name__ == '__main__':
    main()
