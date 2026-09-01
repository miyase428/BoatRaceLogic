#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
買い目最適化 STEP 1：3連単 / 2連単 / 混在の構造比較

目的
----
現在の最終120通り出目確率を使い、過去オッズを使わずに
「どの種類を何点買うか」という買い目構造そのものを比較する。

重要
----
- 過去の全候補オッズは保存していないため、ここでは合成オッズや期待値ゲートは検証しない。
- 実際の払戻（3連単・2連単）は race_payouts から取得し、ROIを評価する。
- 2連単と3連単が同時に的中した場合は払戻を合算する。
- 1R1000円均等は、構造比較段階では100円単位丸め前の理論均等配分を使う。
  点数が固まった後に100円単位の実配分を別STEPで検証する。
- 最終出目モデルの係数は既存STEP3固定値を使い、ここでは再学習しない。

探索
----
購入レースゲート:
  P(1C頭) >= なし / 40 / 45 / 50 / 55 / 60 / 65 / 70%

3連単:
  最終120通り確率 上位 2～8点

2連単:
  最終120通りを1着-2着へ集約した30通り確率 上位 1～4点

構造:
  - 3連単のみ
  - 2連単のみ
  - 3連単 + 2連単 混在
  合計10点以内

評価:
  - 購入R数 / 的中率
  - 予測確率カバー率（混在は最終結果状態のunion）
  - 100円/点 ROI
  - 1R1000円均等 理論ROI
  - 混在時の3連単的中 / 2連単的中 / 両方的中

期間:
  DEV で各ファミリーの方式を1つ選び、F1/F2は条件固定の完全前方検証。

Usage:
python3 analysis/bet_structure_optimization_compare.py \
  analysis/output/final_prediction_boats_20260715_20260814.csv \
  analysis/output/final_prediction_boats_fast_cached_20260815_20260822.csv \
  analysis/output/final_prediction_boats_fast_cached_20260823_20260831.csv
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import base_trifecta_probability_compare as base_outcome
import trifecta_probability_order_compare as step3
from slit_validate_v2 import connect_db


FINAL_DELTA = 0.25
FINAL_GAMMA = 0.25
HEAD_THRESHOLDS = (0.0, 0.40, 0.45, 0.50, 0.55, 0.60, 0.65, 0.70)
TRIFECTA_COUNTS = (0, 2, 3, 4, 5, 6, 7, 8)
EXACTA_COUNTS = (0, 1, 2, 3, 4)
MAX_TOTAL_POINTS = 10
MIN_DEV_BETS = 100


def payout_to_int(value):
    if value is None:
        return None
    s = re.sub(r'[^0-9]', '', str(value))
    if not s:
        return None
    try:
        v = int(s)
    except ValueError:
        return None
    return v if v > 0 else None


def detect_payout_columns(conn):
    cur = conn.cursor()
    cur.execute(
        """
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = 'boat_race'
          AND table_name = 'race_payouts'
        ORDER BY ordinal_position
        """
    )
    columns = [str(r[0]) for r in cur.fetchall()]
    cur.close()
    by_lower = {c.lower(): c for c in columns}

    tri_candidates = [
        'trifecta_payout', 'sanrentan_payout', 'sanren_tan_payout',
        '3rentan_payout', 'sanrentan', 'sanren_tan',
    ]
    exact_candidates = [
        'exacta_payout', 'niren_tan_payout', 'nirentan_payout',
        'niren_tan', 'nirentan', 'two_exacta_payout', '2rentan_payout',
    ]

    tri = None
    exact = None
    for c in tri_candidates:
        if c.lower() in by_lower:
            tri = by_lower[c.lower()]
            break
    for c in exact_candidates:
        if c.lower() in by_lower:
            exact = by_lower[c.lower()]
            break

    if tri is None:
        for c in columns:
            n = c.lower().replace('_', '')
            if ('trifecta' in n or 'sanrentan' in n or '3rentan' in n) and ('payout' in n or 'pay' in n or n.endswith('tan')):
                tri = c
                break

    if exact is None:
        for c in columns:
            n = c.lower().replace('_', '')
            if ('exacta' in n or 'nirentan' in n or '2rentan' in n) and ('payout' in n or 'pay' in n or n.endswith('tan')):
                exact = c
                break

    return tri, exact, columns


def load_payouts(start_date, end_date):
    with connect_db() as conn:
        tri_col, exact_col, columns = detect_payout_columns(conn)
        if tri_col is None or exact_col is None:
            print('race_payouts列一覧: ' + ', '.join(columns))
            raise RuntimeError(
                f'払戻列を自動判定できません。3連単={tri_col} / 2連単={exact_col}'
            )

        sql = f"""
            SELECT rp.race_code, rp.{tri_col}, rp.{exact_col}
            FROM boat_race.race_payouts rp
            JOIN boat_race.race_master rm
              ON rm.race_code = rp.race_code
            WHERE rm.race_date >= %s::date
              AND rm.race_date <= %s::date
        """
        cur = conn.cursor()
        cur.execute(sql, (str(start_date), str(end_date)))
        out = {}
        for race_code, tri, exact in cur.fetchall():
            tp = payout_to_int(tri)
            ep = payout_to_int(exact)
            if tp is None or ep is None:
                continue
            out[str(race_code)] = {'trifecta': tp, 'exacta': ep}
        cur.close()

    return out, tri_col, exact_col


def final_probs(record):
    return step3.order_adjusted_probs(record, FINAL_DELTA, FINAL_GAMMA)


def exacta_distribution(probs):
    out = {}
    for idx, pattern in enumerate(base_outcome.PATTERNS):
        a, b, _c = pattern
        key = (int(a), int(b))
        out[key] = out.get(key, 0.0) + float(probs[idx])
    return out


def head1_mass(probs):
    return sum(
        float(probs[idx])
        for idx, pattern in enumerate(base_outcome.PATTERNS)
        if int(pattern[0]) == 1
    )


def build_rows(records, payouts):
    rows = []
    for record in records:
        code = str(record['race_code'])
        payout = payouts.get(code)
        if payout is None:
            continue

        probs = final_probs(record)
        tri_order = sorted(
            range(len(probs)),
            key=lambda i: (-float(probs[i]), base_outcome.PATTERNS[i]),
        )
        exact_probs = exacta_distribution(probs)
        exact_order = sorted(
            exact_probs,
            key=lambda x: (-float(exact_probs[x]), x),
        )
        actual_idx = int(record['actual_idx'])
        actual_pattern = tuple(int(x) for x in base_outcome.PATTERNS[actual_idx])
        actual_exacta = (actual_pattern[0], actual_pattern[1])

        rows.append({
            'race_code': code,
            'probs': probs,
            'tri_order': tri_order,
            'exact_probs': exact_probs,
            'exact_order': exact_order,
            'head1_mass': float(head1_mass(probs)),
            'actual_idx': actual_idx,
            'actual_exacta': actual_exacta,
            'trifecta_payout': int(payout['trifecta']),
            'exacta_payout': int(payout['exacta']),
        })
    return rows


def union_coverage(row, tri_count, exact_count):
    tri_set = set(row['tri_order'][:tri_count]) if tri_count > 0 else set()
    exact_set = set(row['exact_order'][:exact_count]) if exact_count > 0 else set()
    total = 0.0
    for idx, pattern in enumerate(base_outcome.PATTERNS):
        a, b, _c = pattern
        if idx in tri_set or (int(a), int(b)) in exact_set:
            total += float(row['probs'][idx])
    return total


def evaluate(rows, threshold, tri_count, exact_count):
    points_per_race = int(tri_count) + int(exact_count)
    if points_per_race <= 0:
        return None

    bet_races = 0
    hit_races = 0
    tri_hits = 0
    exact_hits = 0
    both_hits = 0
    coverage_sum = 0.0

    invest_per_point = 0.0
    return_per_point = 0.0
    invest_fixed = 0.0
    return_fixed = 0.0

    for row in rows:
        if float(row['head1_mass']) < float(threshold):
            continue

        tri_set = set(row['tri_order'][:tri_count]) if tri_count > 0 else set()
        exact_set = set(row['exact_order'][:exact_count]) if exact_count > 0 else set()

        tri_hit = row['actual_idx'] in tri_set
        exact_hit = row['actual_exacta'] in exact_set
        any_hit = tri_hit or exact_hit

        bet_races += 1
        coverage_sum += union_coverage(row, tri_count, exact_count)
        invest_per_point += points_per_race * 100.0
        invest_fixed += 1000.0

        if tri_hit:
            tri_hits += 1
            return_per_point += float(row['trifecta_payout'])
        if exact_hit:
            exact_hits += 1
            return_per_point += float(row['exacta_payout'])
        if both_hits:
            pass
        if tri_hit and exact_hit:
            both_hits += 1
        if any_hit:
            hit_races += 1

        stake_each = 1000.0 / points_per_race
        if tri_hit:
            return_fixed += float(row['trifecta_payout']) * (stake_each / 100.0)
        if exact_hit:
            return_fixed += float(row['exacta_payout']) * (stake_each / 100.0)

    if bet_races <= 0:
        return {
            'bet_races': 0,
            'hit_races': 0,
            'hit_rate': 0.0,
            'tri_hits': 0,
            'exact_hits': 0,
            'both_hits': 0,
            'coverage': 0.0,
            'points': points_per_race,
            'roi_per_point': 0.0,
            'roi_fixed': 0.0,
        }

    return {
        'bet_races': bet_races,
        'hit_races': hit_races,
        'hit_rate': hit_races / bet_races,
        'tri_hits': tri_hits,
        'exact_hits': exact_hits,
        'both_hits': both_hits,
        'coverage': coverage_sum / bet_races,
        'points': points_per_race,
        'roi_per_point': return_per_point / invest_per_point if invest_per_point else 0.0,
        'roi_fixed': return_fixed / invest_fixed if invest_fixed else 0.0,
    }


def family_of(tri_count, exact_count):
    if tri_count > 0 and exact_count == 0:
        return 'TRIFECTA_ONLY'
    if tri_count == 0 and exact_count > 0:
        return 'EXACTA_ONLY'
    if tri_count > 0 and exact_count > 0:
        return 'MIXED'
    return 'INVALID'


def strategy_label(threshold, tri_count, exact_count):
    gate = 'ALL' if threshold <= 0 else f'H1>={threshold*100:.0f}%'
    return f'{gate} / T3={tri_count} / E2={exact_count}'


def all_strategies(rows):
    out = []
    for th in HEAD_THRESHOLDS:
        for tri in TRIFECTA_COUNTS:
            for exact in EXACTA_COUNTS:
                if tri == 0 and exact == 0:
                    continue
                if tri + exact > MAX_TOTAL_POINTS:
                    continue
                # 3連単0点なら2連単のみ、2連単0点なら3連単のみとして許可。
                r = evaluate(rows, th, tri, exact)
                if r is None:
                    continue
                out.append({
                    'threshold': th,
                    'tri': tri,
                    'exact': exact,
                    'family': family_of(tri, exact),
                    'result': r,
                })
    return out


def select_family(dev_grid, family):
    candidates = [x for x in dev_grid if x['family'] == family]
    if not candidates:
        return None

    def key(x):
        r = x['result']
        eligible = r['bet_races'] >= MIN_DEV_BETS
        return (
            0 if eligible else 1,
            -r['roi_fixed'],
            -r['roi_per_point'],
            -r['hit_rate'],
            -r['coverage'],
            -r['bet_races'],
            r['points'],
            x['threshold'],
            x['tri'],
            x['exact'],
        )

    return sorted(candidates, key=key)[0]


def print_result_line(name, s, r):
    print(
        f"{name:<17} {strategy_label(s['threshold'], s['tri'], s['exact']):<26} "
        f"{r['bet_races']:>5d}  {r['points']:>2d}  {r['coverage']*100:>6.2f}%  "
        f"{r['hit_rate']*100:>6.2f}%  {r['tri_hits']:>4d}  {r['exact_hits']:>4d}  "
        f"{r['both_hits']:>4d}  {r['roi_per_point']*100:>8.2f}%  {r['roi_fixed']*100:>8.2f}%"
    )


def print_selected(title, rows, selected):
    print(f"\n【{title}】")
    print(
        '方式              条件                       購入R 点数  Cover   的中率  T3的中 E2的中 両方  '
        '100円/点ROI 1000円均等ROI'
    )
    print('-' * 134)
    for family, s in selected.items():
        if s is None:
            continue
        r = evaluate(rows, s['threshold'], s['tri'], s['exact'])
        print_result_line(family, s, r)


def print_dev_top(dev_grid, limit=15):
    eligible = [x for x in dev_grid if x['result']['bet_races'] >= MIN_DEV_BETS]
    eligible.sort(
        key=lambda x: (
            -x['result']['roi_fixed'],
            -x['result']['roi_per_point'],
            -x['result']['hit_rate'],
            x['result']['points'],
        )
    )
    print(f"\n【DEV 上位{limit}方式（購入{MIN_DEV_BETS}R以上）】")
    print(
        '順位 Family            条件                       購入R 点数  Cover   的中率  T3的中 E2的中 両方  '
        '100円/点ROI 1000円均等ROI'
    )
    print('-' * 142)
    for i, x in enumerate(eligible[:limit], start=1):
        r = x['result']
        print(
            f"{i:>2d}   {x['family']:<17} {strategy_label(x['threshold'], x['tri'], x['exact']):<26} "
            f"{r['bet_races']:>5d}  {r['points']:>2d}  {r['coverage']*100:>6.2f}%  "
            f"{r['hit_rate']*100:>6.2f}%  {r['tri_hits']:>4d}  {r['exact_hits']:>4d}  "
            f"{r['both_hits']:>4d}  {r['roi_per_point']*100:>8.2f}%  {r['roi_fixed']*100:>8.2f}%"
        )


def print_simple_benchmarks(title, rows):
    print(f"\n【{title} 基準比較 / 購入ゲートなし】")
    print('構造                購入R 点数  Cover   的中率  T3的中 E2的中 両方  100円/点ROI 1000円均等ROI')
    print('-' * 112)
    specs = [
        ('T3 Top3', 3, 0),
        ('T3 Top5', 5, 0),
        ('T3 Top6', 6, 0),
        ('T3 Top8', 8, 0),
        ('E2 Top1', 0, 1),
        ('E2 Top2', 0, 2),
        ('E2 Top3', 0, 3),
        ('MIX T3=4 E2=2', 4, 2),
        ('MIX T3=5 E2=2', 5, 2),
        ('MIX T3=6 E2=2', 6, 2),
    ]
    for name, tri, exact in specs:
        r = evaluate(rows, 0.0, tri, exact)
        print(
            f"{name:<20} {r['bet_races']:>5d}  {r['points']:>2d}  {r['coverage']*100:>6.2f}%  "
            f"{r['hit_rate']*100:>6.2f}%  {r['tri_hits']:>4d}  {r['exact_hits']:>4d}  "
            f"{r['both_hits']:>4d}  {r['roi_per_point']*100:>8.2f}%  {r['roi_fixed']*100:>8.2f}%"
        )


def combine_rows(*parts):
    out = []
    for p in parts:
        out.extend(p)
    return out


def main():
    if len(sys.argv) != 4:
        print('Usage: python3 analysis/bet_structure_optimization_compare.py DEV_BOATS_CSV F1_BOATS_CSV F2_BOATS_CSV')
        sys.exit(1)

    dev_csv, f1_csv, f2_csv = sys.argv[1], sys.argv[2], sys.argv[3]

    print('最終出目モデルを固定したまま、DEV/F1/F2の買い目構造を再構築中...')
    d1 = step3.build_common_records(dev_csv, f1_csv)
    d2 = step3.build_common_records(dev_csv, f2_csv)

    dev_records = d1['records']['P1']
    f1_records = d1['records']['P2']
    f2_records = d2['records']['P2']
    if not dev_records or not f1_records or not f2_records:
        raise RuntimeError('DEV/F1/F2の共通評価レースがありません')

    start = d1['p1_start']
    end = d2['p2_end']
    payouts, tri_col, exact_col = load_payouts(start, end)

    dev = build_rows(dev_records, payouts)
    f1 = build_rows(f1_records, payouts)
    f2 = build_rows(f2_records, payouts)
    if not dev or not f1 or not f2:
        raise RuntimeError('払戻結合後のDEV/F1/F2評価レースがありません')

    dev_grid = all_strategies(dev)
    selected = {
        family: select_family(dev_grid, family)
        for family in ('TRIFECTA_ONLY', 'EXACTA_ONLY', 'MIXED')
    }

    print('=' * 148)
    print('買い目最適化 STEP 1：3連単 / 2連単 / 混在 構造比較')
    print('=' * 148)
    print(f"DEV                 : {d1['p1_start']} ～ {d1['p1_end']} / 評価={len(dev)}R")
    print(f"F1 完全前方         : {d1['p2_start']} ～ {d1['p2_end']} / 評価={len(f1)}R")
    print(f"F2 完全前方         : {d2['p2_start']} ～ {d2['p2_end']} / 評価={len(f2)}R")
    print(f"3連単払戻列         : boat_race.race_payouts.{tri_col}")
    print(f"2連単払戻列         : boat_race.race_payouts.{exact_col}")
    print('候補抽出             : 最終120通り出目確率の上位 / 2連単は1-2着へ集約')
    print('購入ゲート           : 最終P(1C頭) なし～70%')
    print('点数上限             : 合計10点')
    print('1000円均等           : 構造比較用の理論均等配分（100円単位丸め前）')
    print('過去オッズ           : 未使用。合成オッズ/期待値ゲートは次STEP')
    print('方式選択             : DEVのみで各ファミリー1方式を固定し、F1/F2では変更しない')

    print_simple_benchmarks('DEV', dev)
    print_dev_top(dev_grid, 15)

    print('\n【DEVで固定した各ファミリー代表】')
    for family, s in selected.items():
        if s is None:
            print(f'{family}: 候補なし')
            continue
        r = s['result']
        print(
            f"{family}: {strategy_label(s['threshold'], s['tri'], s['exact'])} / "
            f"購入={r['bet_races']}R / 的中={r['hit_rate']*100:.2f}% / "
            f"1000円均等ROI={r['roi_fixed']*100:.2f}%"
        )

    print_selected('DEV 参考', dev, selected)
    print_selected('F1 完全前方', f1, selected)
    print_selected('F2 完全前方', f2, selected)
    print_selected('F1+F2 前方合算', combine_rows(f1, f2), selected)

    print('\n【判断方針】')
    print('1. DEV最高ROIだけで採用せず、F1/F2の方向が揃うかを最優先する。')
    print('2. MIXEDは2連単+3連単が同時的中した場合の払戻を合算済み。')
    print('3. F1/F2で構造が安定したら、次に1R1000円を100円単位でどう配るかを検証する。')
    print('4. 過去全候補オッズが無いため、合成オッズ2.3倍などの購入ゲートはこのSTEPでは検証しない。')
    print('5. 本番Web/PredictionLogic/買い目ロジックは変更しない。')
    print('=' * 148)


if __name__ == '__main__':
    main()
