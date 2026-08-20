#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
最終予想 AI活用 STEP B4-2：イン1着時2連単の買い方検証

目的
----
Webに表示している「イン1着時 2連単（場平均 vs AI）」を、
実際の購入ルールへ落としたときの的中率・点数・回収率をP1/P2で検証する。

検証する買い方
--------------
- AIの P(1C頭) が threshold 以上のレースだけ購入
- 2着候補は AI_FINAL の条件付き分布 P(2着C | 1C頭) の上位 k 点
- threshold: なし / 40 / 45 / 50 / 55 / 60 / 65 / 70%
- k: 1 / 2 / 3点

比較
----
AI_FINAL
    STEP3最終120通りを1C頭条件で2着別へ集約したAI分布。

VENUE_BASE_SAME_GATE
    同じ購入レース（AIのP(1C頭)ゲート）で、2着順位だけVENUE_K3000場平均を使う。
    これにより「AIの2着順位付け自体」の価値を確認する。

投資評価
--------
1) 100円/点
2) 1R=1000円を購入点数へ均等配分した理論値（100円単位丸めなし）

重要
----
- 実際の1着コースが1Cかどうかも含めて購入成績を評価する。
- 公式決まり手「逃げ」限定ではなく、展示進入ベースで1Cにいた艇が1着したかを使う。
- P1で買い方を選択し、P2では固定して完全ホールドアウト評価する。
- 本番Web/PredictionLogicは変更しない。

Usage:
python3 analysis/head1_exacta_bet_strategy_compare.py \
  analysis/output/final_prediction_boats_20260615_20260714_OLD.csv \
  analysis/output/final_prediction_boats_20260715_20260814_OLD.csv
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import base_trifecta_probability_compare as base_outcome
import head1_exacta_probability_validate as head1
import trifecta_probability_order_compare as step3
from slit_validate_v2 import connect_db


FINAL_DELTA = 0.25
FINAL_GAMMA = 0.25
THRESHOLDS = (0.0, 0.40, 0.45, 0.50, 0.55, 0.60, 0.65, 0.70)
TOP_K = (1, 2, 3)
MIN_P1_BETS = 100


def detect_exacta_payout_column(conn):
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

    exact_candidates = [
        'exacta_payout',
        'niren_tan_payout',
        'nirentan_payout',
        'niren_tan',
        'nirentan',
        'two_exacta_payout',
        '2rentan_payout',
    ]
    by_lower = {c.lower(): c for c in columns}
    for candidate in exact_candidates:
        if candidate.lower() in by_lower:
            return by_lower[candidate.lower()], columns

    # exacta系を優先。
    for c in columns:
        n = c.lower().replace('_', '')
        if 'exacta' in n and ('payout' in n or 'pay' in n):
            return c, columns

    # 2連単 / niren-tan 系の名前をフォールバック検出。
    for c in columns:
        n = c.lower().replace('_', '')
        has_niren = ('nirentan' in n or '2rentan' in n or ('niren' in n and 'tan' in n))
        if has_niren and ('payout' in n or 'pay' in n or n.endswith('tan')):
            return c, columns

    return None, columns


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


def load_exacta_payouts(start_date, end_date):
    with connect_db() as conn:
        column, columns = detect_exacta_payout_column(conn)
        if column is None:
            print('race_payouts列一覧: ' + ', '.join(columns))
            raise RuntimeError(
                '2連単払戻列を自動判定できませんでした。上のrace_payouts列一覧を貼ってください。'
            )

        sql = f"""
            SELECT rp.race_code, rp.{column}
            FROM boat_race.race_payouts rp
            JOIN boat_race.race_master rm
              ON rm.race_code = rp.race_code
            WHERE rm.race_date >= %s::date
              AND rm.race_date <= %s::date
        """
        cur = conn.cursor()
        cur.execute(sql, (str(start_date), str(end_date)))
        out = {}
        for race_code, payout in cur.fetchall():
            p = payout_to_int(payout)
            if p is not None:
                out[str(race_code)] = p
        cur.close()
    return out, column


def build_rows(records, payouts):
    out = {'P1': [], 'P2': []}

    for period in ('P1', 'P2'):
        for record in records[period]:
            code = str(record['race_code'])
            payout = payouts.get(code)
            if payout is None:
                continue

            base_probs = list(record['probs'])
            ai_probs = step3.order_adjusted_probs(record, FINAL_DELTA, FINAL_GAMMA)
            base_dist, base_head_mass = head1.conditional_second_distribution(base_probs)
            ai_dist, ai_head_mass = head1.conditional_second_distribution(ai_probs)
            if base_dist is None or ai_dist is None:
                continue

            actual_pattern = base_outcome.PATTERNS[int(record['actual_idx'])]
            actual_head = int(actual_pattern[0])
            actual_second = int(actual_pattern[1])

            out[period].append({
                'race_code': code,
                'payout': int(payout),
                'actual_head': actual_head,
                'actual_second': actual_second,
                'base_head_mass': float(base_head_mass),
                'ai_head_mass': float(ai_head_mass),
                'base_dist': {int(c): float(p) for c, p in base_dist.items()},
                'ai_dist': {int(c): float(p) for c, p in ai_dist.items()},
            })

    return out


def ranked_courses(dist):
    return sorted(dist, key=lambda c: (-float(dist[c]), int(c)))


def evaluate(rows, threshold, k, source='ai'):
    total = len(rows)
    bet_races = 0
    hits = 0
    head1_wins = 0
    points = 0
    invest_per_point = 0.0
    return_per_point = 0.0
    invest_fixed = 0.0
    return_fixed = 0.0

    for row in rows:
        # 購入レースの判定は本番で使えるAIのP(1C頭)だけで行う。
        if float(row['ai_head_mass']) < float(threshold):
            continue

        dist = row['ai_dist'] if source == 'ai' else row['base_dist']
        picks = ranked_courses(dist)[: int(k)]
        cnt = len(picks)
        if cnt <= 0:
            continue

        bet_races += 1
        points += cnt
        invest_per_point += cnt * 100.0
        invest_fixed += 1000.0

        head1 = int(row['actual_head']) == 1
        if head1:
            head1_wins += 1

        hit = head1 and int(row['actual_second']) in picks
        if hit:
            hits += 1
            payout = float(row['payout'])
            return_per_point += payout
            stake_each = 1000.0 / cnt
            return_fixed += payout * (stake_each / 100.0)

    return {
        'total': total,
        'bet_races': bet_races,
        'purchase_rate': bet_races / total if total else 0.0,
        'hits': hits,
        'hit_rate': hits / bet_races if bet_races else 0.0,
        'head1_wins': head1_wins,
        'head1_rate': head1_wins / bet_races if bet_races else 0.0,
        'second_capture': hits / head1_wins if head1_wins else 0.0,
        'avg_points': points / bet_races if bet_races else 0.0,
        'roi_per_point': return_per_point / invest_per_point if invest_per_point else 0.0,
        'roi_fixed': return_fixed / invest_fixed if invest_fixed else 0.0,
    }


def strategy_grid(rows, source='ai'):
    result = {}
    for th in THRESHOLDS:
        for k in TOP_K:
            result[(th, k)] = evaluate(rows, th, k, source)
    return result


def select_p1_strategy(grid):
    candidates = []
    for (th, k), r in grid.items():
        eligible = r['bet_races'] >= MIN_P1_BETS
        key = (
            0 if eligible else 1,
            -r['roi_fixed'],
            -r['roi_per_point'],
            -r['hit_rate'],
            -r['bet_races'],
            k,
            th,
        )
        candidates.append((key, th, k, r))
    candidates.sort(key=lambda x: x[0])
    return candidates[0]


def print_grid(title, rows):
    print(f"\n【{title}】")
    print(
        'P(1C頭)閾値  点数  購入R   購入率  1C実勝率  2着捕捉  的中率  '
        '100円/点ROI  1000円均等ROI'
    )
    print('-' * 112)
    grid = strategy_grid(rows, 'ai')
    for th in THRESHOLDS:
        for k in TOP_K:
            r = grid[(th, k)]
            label = '全て' if th <= 0 else f'>={th*100:.0f}%'
            print(
                f"{label:<12} {k:>2d}   {r['bet_races']:>5d}  {r['purchase_rate']*100:>6.2f}%  "
                f"{r['head1_rate']*100:>7.2f}%  {r['second_capture']*100:>6.2f}%  "
                f"{r['hit_rate']*100:>6.2f}%    {r['roi_per_point']*100:>8.2f}%      "
                f"{r['roi_fixed']*100:>8.2f}%"
            )
    return grid


def print_selected_compare(title, rows, threshold, k):
    ai = evaluate(rows, threshold, k, 'ai')
    venue = evaluate(rows, threshold, k, 'venue')
    label = '全て' if threshold <= 0 else f'>={threshold*100:.0f}%'

    print(f"\n【{title}：P(1C頭){label} / 上位{k}点】")
    print('方式                    購入R  1C実勝率  2着捕捉  的中率  100円/点ROI  1000円均等ROI')
    print('-' * 100)
    for name, r in (('VENUE_BASE_SAME_GATE', venue), ('AI_FINAL', ai)):
        print(
            f"{name:<24} {r['bet_races']:>5d}   {r['head1_rate']*100:>7.2f}%  "
            f"{r['second_capture']*100:>6.2f}%  {r['hit_rate']*100:>6.2f}%    "
            f"{r['roi_per_point']*100:>8.2f}%      {r['roi_fixed']*100:>8.2f}%"
        )
    return venue, ai


def main():
    if len(sys.argv) != 3:
        print('Usage: python3 analysis/head1_exacta_bet_strategy_compare.py P1_BOATS_CSV P2_BOATS_CSV')
        sys.exit(1)

    p1_csv, p2_csv = sys.argv[1], sys.argv[2]

    print('STEP3最終出目モデルと2連単払戻を結合中...')
    data = step3.build_common_records(p1_csv, p2_csv)
    payouts, payout_column = load_exacta_payouts(data['p1_start'], data['p2_end'])
    rows = build_rows(data['records'], payouts)

    if not rows['P1'] or not rows['P2']:
        raise RuntimeError('P1/P2の2連単評価レースがありません')

    print('=' * 126)
    print('最終予想 AI活用 STEP B4-2：イン1着時2連単の買い方')
    print('=' * 126)
    print(f"P1                  : {data['p1_start']} ～ {data['p1_end']}")
    print(f"P2完全ホールドアウト: {data['p2_start']} ～ {data['p2_end']}")
    print(f"2連単払戻列          : boat_race.race_payouts.{payout_column}")
    print('購入条件             : AIのP(1C頭)閾値 × AI条件付き2着上位k点')
    print('投資方式             : 100円/点 + 1R1000円均等理論値')
    print('本番Web変更          : なし')
    print(f"\n【払戻あり共通母集団】P1={len(rows['P1'])}R / P2={len(rows['P2'])}R / 払戻={len(payouts)}R")

    p1_grid = print_grid('P1 閾値・点数選択用', rows['P1'])
    chosen = select_p1_strategy(p1_grid)
    _, selected_th, selected_k, selected_p1 = chosen
    label = '全て' if selected_th <= 0 else f'{selected_th*100:.0f}%以上'
    print(
        f"\nP1の1R1000円均等ROIを最優先して選択: "
        f"P(1C頭)={label} / AI上位{selected_k}点 "
        f"(購入={selected_p1['bet_races']}R)"
    )

    # P2表は参考として全条件を表示するが、再選択はしない。
    print_grid('P2 ホールドアウト参考（ここでは再選択しない）', rows['P2'])

    print_selected_compare('P1 選択条件で場平均 vs AI', rows['P1'], selected_th, selected_k)
    venue_p2, ai_p2 = print_selected_compare(
        'P2 完全ホールドアウト（最重要）', rows['P2'], selected_th, selected_k
    )

    print('\n【P2 同一購入レースで VENUE_BASE → AI_FINAL】')
    print(f"2着捕捉率差      : {(ai_p2['second_capture'] - venue_p2['second_capture'])*100:+.2f}pt")
    print(f"的中率差          : {(ai_p2['hit_rate'] - venue_p2['hit_rate'])*100:+.2f}pt")
    print(f"100円/点ROI差     : {(ai_p2['roi_per_point'] - venue_p2['roi_per_point'])*100:+.2f}pt")
    print(f"1000円均等ROI差   : {(ai_p2['roi_fixed'] - venue_p2['roi_fixed'])*100:+.2f}pt")

    print('\n【判断方針】')
    print('1. P1で選んだP(1C頭)閾値と点数をP2で変更しない')
    print('2. P2で購入レース数が極端に少なくないことを確認する')
    print('3. 同じ購入レースで場平均よりAIの2着捕捉・ROIが改善するか確認する')
    print('4. ROIが低ければ、2連単は予想補助表示のままにして自動買い目には使わない')
    print('5. このスクリプトは検証のみで本番Web/PredictionLogicは変更しない')
    print('=' * 126)


if __name__ == '__main__':
    main()
