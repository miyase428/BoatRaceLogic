#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
共通2着確率エンジン候補の最終比較。

目的
----
今後は「2連単用」「最終予想用」で別の2着ロジックを持たず、
1つの共通2着確率を両方へ使う方針とする。

比較対象
--------
② CURRENT_FINAL_SECOND
    現行 PredictionLogic::buildSummary() 相当。
    現行最終順位から本命・kiruを除外して上位最大3艇。

③ AI_FINAL
    現行「イン1着時 2連単」と同じ、STEP3最終120通りから
    P(2着艇 | 1C頭) を集約した2着確率順。

④ BASIC_AI_BLEND
    BASIC_K10 と AI_FINAL の幾何平均。
    展示進入優先修正版P1で選ばれた w=0.90 を固定。

比較条件
--------
- 対象は「現行本命=1号艇」かつ「1号艇=1C」のレース。
- 頭は現行本命=1号艇で固定。
- kiruは現行のまま固定。
- 3着候補は現行と同じ「非kiru・頭以外の全艇」で固定。
- 2着候補数は現行と同じ最大3艇。
- 100円/点で3連単的中率・ROIも比較。
- ③④の確率品質は、頭的中レースで LogLoss / Brier5 も比較。
- 未来情報は使わない。
- 本番Web / PredictionLogicは変更しない。

Usage
-----
python3 analysis/final_prediction_second_engine_compare.py \
  analysis/output/final_prediction_boats_20260615_20260714.csv \
  analysis/output/final_prediction_boats_20260715_20260814.csv
"""

from __future__ import annotations

import math
import sys
from collections import Counter
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import final_prediction_ai_opponent_compare as final_aite
import final_prediction_head1_blend_second_compare as basecmp
import head1_exacta_blend_before_after as exacta_before
import head1_second_probability_4way_compare as fourway
import trifecta_probability_order_compare as step3


BLEND_WEIGHT = 0.90
EPS = 1e-15
METHODS = (
    'CURRENT_FINAL_SECOND',
    'AI_FINAL',
    'BASIC_AI_BLEND',
)


def current_order(rank_boats, head, kiru):
    return [
        int(b) for b in rank_boats
        if int(b) != int(head) and int(b) not in kiru
    ]


def score_order(scores, eligible):
    return sorted(
        [int(b) for b in eligible],
        key=lambda b: (-float(scores.get(b, 0.0)), int(b)),
    )


def build_rows(data, csv_races, snapshots, payouts):
    snap_map = {
        period: {str(s.race_code): s for s in snapshots[period]}
        for period in ('P1', 'P2')
    }
    out = {'P1': [], 'P2': []}
    skip = Counter()

    for period in ('P1', 'P2'):
        for record in data['records'][period]:
            code = str(record['race_code'])
            boats = csv_races.get(code)
            if boats is None or set(boats) != set(range(1, 7)):
                skip[f'{period}_csv_missing'] += 1
                continue

            rank_boats, head = final_aite.current_order_and_head(boats)
            if not rank_boats or head is None:
                skip[f'{period}_current_invalid'] += 1
                continue

            if int(head) != 1:
                skip[f'{period}_current_head_not_1'] += 1
                continue

            if exacta_before.course1_boat(record) != 1:
                skip[f'{period}_boat1_not_course1'] += 1
                continue

            snapshot = snap_map[period].get(code)
            if snapshot is None:
                skip[f'{period}_basic_snapshot_missing'] += 1
                continue

            basic = fourway.basic_probs(snapshot)
            ai, _head_mass = fourway.ai_final_probs(record, snapshot)
            if ai is None or set(ai) != set(basic):
                skip[f'{period}_ai_dist_invalid'] += 1
                continue
            blend = fourway.blend_probs(basic, ai, BLEND_WEIGHT)

            kiru = {lane for lane, b in boats.items() if int(b['kiru']) == 1}
            eligible = [lane for lane in range(1, 7) if lane != 1 and lane not in kiru]
            if not eligible:
                skip[f'{period}_eligible_empty'] += 1
                continue

            current = current_order(rank_boats, 1, kiru)
            ai_order = score_order(ai, eligible)
            blend_order = score_order(blend, eligible)

            k = min(3, len(eligible))
            orders = {
                'CURRENT_FINAL_SECOND': current,
                'AI_FINAL': ai_order,
                'BASIC_AI_BLEND': blend_order,
            }
            seconds = {method: order[:k] for method, order in orders.items()}
            thirds = list(eligible)
            tickets = {
                method: basecmp.expand_formation(1, seconds[method], thirds)
                for method in METHODS
            }
            if any(not tickets[m] for m in METHODS):
                skip[f'{period}_formation_empty'] += 1
                continue

            first_lanes = [lane for lane, b in boats.items() if float(b['actual_rank']) == 1.0]
            second_lanes = [lane for lane, b in boats.items() if float(b['actual_rank']) == 2.0]
            third_lanes = [lane for lane, b in boats.items() if float(b['actual_rank']) == 3.0]
            if len(first_lanes) != 1 or len(second_lanes) != 1 or len(third_lanes) != 1:
                skip[f'{period}_actual_top3_invalid'] += 1
                continue

            actual = (int(first_lanes[0]), int(second_lanes[0]), int(third_lanes[0]))
            actual_second = actual[1]

            out[period].append({
                'race_code': code,
                'actual': actual,
                'head_won': actual[0] == 1,
                'actual_second': actual_second,
                'actual_second_cut': actual_second in kiru,
                'orders': orders,
                'seconds': seconds,
                'thirds': thirds,
                'tickets': tickets,
                'ai_probs': ai,
                'blend_probs': blend,
                'payout': float(payouts.get(code, 0.0)),
            })
            skip[f'{period}_ready'] += 1

    return out, skip


def evaluate_method(rows, method):
    races = len(rows)
    head_wins = 0
    pure_second_n = 0
    top_hits = {1: 0, 2: 0, 3: 0}
    trifecta_hits = 0
    points = 0
    payout_sum = 0.0
    payout_missing_hits = 0

    for row in rows:
        order = row['orders'][method]
        ticket_set = row['tickets'][method]
        points += len(ticket_set)

        if row['head_won']:
            head_wins += 1
            if not row['actual_second_cut']:
                pure_second_n += 1
                actual_second = int(row['actual_second'])
                for k in (1, 2, 3):
                    if actual_second in order[:k]:
                        top_hits[k] += 1

        if row['actual'] in ticket_set:
            trifecta_hits += 1
            if row['payout'] > 0:
                payout_sum += row['payout']
            else:
                payout_missing_hits += 1

    invest = points * 100.0
    return {
        'races': races,
        'head_wins': head_wins,
        'head_rate': head_wins / races if races else 0.0,
        'pure_second_n': pure_second_n,
        'top1': top_hits[1] / pure_second_n if pure_second_n else 0.0,
        'top2': top_hits[2] / pure_second_n if pure_second_n else 0.0,
        'top3': top_hits[3] / pure_second_n if pure_second_n else 0.0,
        'trifecta_hits': trifecta_hits,
        'trifecta_hit_rate': trifecta_hits / races if races else 0.0,
        'points': points,
        'avg_points': points / races if races else 0.0,
        'roi': payout_sum / invest if invest else 0.0,
        'payout_missing_hits': payout_missing_hits,
    }


def probability_metrics(rows, key):
    n = 0
    logloss = 0.0
    brier = 0.0
    actual_prob = 0.0

    for row in rows:
        if not row['head_won']:
            continue
        actual = int(row['actual_second'])
        probs = row[key]
        if actual not in probs:
            continue

        p_actual = max(EPS, min(1.0, float(probs[actual])))
        logloss += -math.log(p_actual)
        actual_prob += float(probs[actual])

        for lane, p in probs.items():
            y = 1.0 if int(lane) == actual else 0.0
            brier += (float(p) - y) ** 2
        n += 1

    if n == 0:
        return None
    return {
        'races': n,
        'logloss': logloss / n,
        'brier5': brier / n,
        'actual_prob': actual_prob / n,
    }


def compare_pair(rows, before_method, after_method):
    result = {
        1: {'changed': 0, 'gained': 0, 'lost': 0},
        2: {'changed': 0, 'gained': 0, 'lost': 0},
        3: {'changed': 0, 'gained': 0, 'lost': 0},
        'trifecta': {'changed': 0, 'gained': 0, 'lost': 0},
    }

    for row in rows:
        before_order = row['orders'][before_method]
        after_order = row['orders'][after_method]

        if row['head_won'] and not row['actual_second_cut']:
            actual = int(row['actual_second'])
            for k in (1, 2, 3):
                bset = set(before_order[:k])
                aset = set(after_order[:k])
                if bset != aset:
                    result[k]['changed'] += 1
                bhit = actual in bset
                ahit = actual in aset
                if ahit and not bhit:
                    result[k]['gained'] += 1
                elif bhit and not ahit:
                    result[k]['lost'] += 1

        bt = row['tickets'][before_method]
        at = row['tickets'][after_method]
        if bt != at:
            result['trifecta']['changed'] += 1
        bhit = row['actual'] in bt
        ahit = row['actual'] in at
        if ahit and not bhit:
            result['trifecta']['gained'] += 1
        elif bhit and not ahit:
            result['trifecta']['lost'] += 1

    return result


def print_period(title, rows):
    metrics = {method: evaluate_method(rows, method) for method in METHODS}

    print(f'\n【{title}】')
    print('方式                      R数  頭実勝率   Top1    Top2    Top3   3連単的中  平均点数   ROI')
    print('-' * 110)
    for method in METHODS:
        m = metrics[method]
        print(
            f"{method:<25} {m['races']:>5d}  {m['head_rate']*100:>7.2f}%  "
            f"{m['top1']*100:>6.2f}%  {m['top2']*100:>6.2f}%  {m['top3']*100:>6.2f}%  "
            f"{m['trifecta_hit_rate']*100:>7.2f}%  {m['avg_points']:>7.2f}点  {m['roi']*100:>7.2f}%"
        )

    ai_p = probability_metrics(rows, 'ai_probs')
    blend_p = probability_metrics(rows, 'blend_probs')
    print('\n確率品質（現行頭が実際に1着したレース / ③④のみ）')
    print('方式                 R数   LogLoss   Brier5   実2着平均P')
    print('-' * 70)
    for name, p in (('AI_FINAL', ai_p), ('BASIC_AI_BLEND', blend_p)):
        if p is None:
            continue
        print(
            f"{name:<20} {p['races']:>5d}  {p['logloss']:.6f}  {p['brier5']:.6f}  {p['actual_prob']*100:>8.3f}%"
        )

    print('\n拾い/失い（実2着非kiruの頭的中レース。3連単は全対象R）')
    for before, after in (
        ('CURRENT_FINAL_SECOND', 'AI_FINAL'),
        ('CURRENT_FINAL_SECOND', 'BASIC_AI_BLEND'),
        ('AI_FINAL', 'BASIC_AI_BLEND'),
    ):
        d = compare_pair(rows, before, after)
        print(f'{before} -> {after}')
        for k in (1, 2, 3):
            x = d[k]
            print(
                f"  Top{k}: 変更={x['changed']}R / 拾い={x['gained']} / 失い={x['lost']} / 純増={x['gained']-x['lost']:+d}"
            )
        x = d['trifecta']
        print(
            f"  3連単: 変更={x['changed']}R / 拾い={x['gained']} / 失い={x['lost']} / 純増={x['gained']-x['lost']:+d}"
        )

    missing = max(m['payout_missing_hits'] for m in metrics.values())
    if missing:
        print(f'注意: 的中したが3連単払戻0/欠損={missing}R')

    return metrics, ai_p, blend_p


def main():
    if len(sys.argv) != 3:
        print('Usage: python3 analysis/final_prediction_second_engine_compare.py P1_BOATS_CSV P2_BOATS_CSV')
        return 1

    p1_csv, p2_csv = sys.argv[1], sys.argv[2]

    print('共通2着確率候補②③④を、現行頭・kiru・3着固定で共通化中...')
    data = step3.build_common_records(p1_csv, p2_csv)
    csv_races = final_aite.load_boats(p1_csv, p2_csv)
    snapshots, snap_meta = basecmp.load_all_prerace_basic_snapshots(
        data['p1_start'], data['p1_end'], data['p2_start'], data['p2_end']
    )
    payouts = basecmp.load_trifecta_payouts(data['p1_start'], data['p2_end'])
    rows, skip = build_rows(data, csv_races, snapshots, payouts)

    if not rows['P1'] or not rows['P2']:
        raise RuntimeError('②③④の共通評価レースがありません')

    print('=' * 136)
    print('共通2着確率エンジン候補：② 現行最終予想2着 vs ③ AI_FINAL vs ④ BASIC_AI_BLEND')
    print('=' * 136)
    print(f"P1                  : {data['p1_start']} ～ {data['p1_end']}")
    print(f"P2完全ホールドアウト: {data['p2_start']} ～ {data['p2_end']}")
    print('対象                 : 現行本命=1号艇 かつ 1号艇=1C')
    print('頭                   : 現行本命で固定')
    print('kiru                 : 現行固定')
    print('3着候補              : 現行固定（非kiru・頭以外の全艇）')
    print('② CURRENT_FINAL_SECOND: 現行最終順位')
    print('③ AI_FINAL           : 現行イン1着時2連単と同じ2着確率')
    print(f'④ BASIC_AI_BLEND     : BASIC_K10 + AI_FINAL / w={BLEND_WEIGHT:.2f}固定')
    print('2着候補              : 各方式の上位最大3艇')
    print('投資                 : 100円/点（同じ候補数・同じ3着集合）')
    print('本番Web変更          : なし')
    print(f"共通評価             : P1={len(rows['P1'])}R / P2={len(rows['P2'])}R")

    print('\n【今回コースsource】')
    for key, value in sorted(snap_meta['target_source'].items()):
        print(f'{key:<36}: {value}')

    print_period('P1 参考', rows['P1'])
    p2_metrics, p2_ai_p, p2_blend_p = print_period('P2 ホールドアウト（最重要）', rows['P2'])

    print('\n【共通化スキップ】')
    for key in sorted(skip):
        print(f'{key:<36}: {skip[key]}')

    print('\n【判断方針】')
    print('1. ②は現状基準。③④が②より2着Top1/Top2/Top3と3連単で改善するかを見る。')
    print('2. 共通エンジン候補の最終比較は③vs④。最重要はP2。')
    print('3. ③④がほぼ同等なら、既に2連単で使っている単純な③ AI_FINALを優先する。')
    print('4. ④がP2で2着順位・確率品質・3連単を一貫して上回る場合だけ④を共通化候補にする。')
    print('5. 採用後は同じ共通2着確率を、最終予想の並び替えと「イン1着時2連単」表示の両方へ使う。')
    print('6. この検証ではWeb/PredictionLogicは変更しない。')

    ai = p2_metrics['AI_FINAL']
    blend = p2_metrics['BASIC_AI_BLEND']
    if (
        blend['top2'] > ai['top2']
        and blend['trifecta_hit_rate'] > ai['trifecta_hit_rate']
        and blend['roi'] >= ai['roi']
        and p2_blend_p is not None
        and p2_ai_p is not None
        and p2_blend_p['logloss'] < p2_ai_p['logloss']
        and p2_blend_p['brier5'] <= p2_ai_p['brier5']
    ):
        print('判定候補             : ④ BASIC_AI_BLEND を共通2着確率候補として次工程へ。')
    else:
        print('判定候補             : ③ AI_FINAL を共通2着確率の第一候補として比較結果を確認。')

    print('=' * 136)
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
