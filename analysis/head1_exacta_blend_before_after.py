#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
イン逃げ時2着予想の最良方式を、2連単へ仮反映した前後比較。

目的
----
前段の head1_second_probability_4way_compare.py で、
P1だけで選んだ BASIC_AI_BLEND を「新2着率」候補とし、
現行 AI_FINAL の2連単買い方へ2着順位だけ仮反映する。

重要
----
- P(1C頭)ゲートは現行AI_FINALのまま固定する。
- 購入点数kも現行AI_FINALをP1だけで選んだものを固定する。
- 新方式でゲート/点数を再最適化しない。
- BASIC_AI_BLEND の重みwもP1だけで選び、P2では固定する。
- P2は完全ホールドアウト。
- 本番Web / PredictionLogic / 3連単は変更しない。

比較
----
BEFORE_AI_FINAL
    現行イン1着時2連単の2着順位。

AFTER_BASIC_AI_BLEND
    BASIC_K10 と AI_FINAL の幾何平均で統合した2着順位。

評価
----
- 購入R数 / P(1C頭)実現率
- 1C頭になった時の2着捕捉率（紐抜け率）
- 2連単的中率
- 100円/点ROI
- 1R1000円均等の理論ROI
- BEFORE→AFTERで拾ったR / 失ったR

Usage
-----
python3 analysis/head1_exacta_blend_before_after.py \
  analysis/output/final_prediction_boats_20260615_20260714.csv \
  analysis/output/final_prediction_boats_20260715_20260814.csv
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import base_trifecta_probability_compare as base_outcome
import head1_exacta_bet_strategy_compare as exacta
import head1_second_probability_4way_compare as fourway
import second_place_head1_k_compare as basic2
import trifecta_probability_order_compare as step3


def course1_boat(record) -> int | None:
    idx = base_outcome.PATTERN_INDEX.get((1, 2, 3))
    if idx is None:
        return None
    lanes = record.get('pattern_lanes', [])
    if idx >= len(lanes):
        return None
    triple = lanes[idx]
    if not triple:
        return None
    try:
        boat = int(triple[0])
    except (TypeError, ValueError):
        return None
    return boat if 1 <= boat <= 6 else None


def filter_boat1_in_course1(rows, record_map):
    out = []
    skipped = 0
    for row in rows:
        code = str(row['race_code'])
        record = record_map.get(code)
        if record is None or course1_boat(record) != 1:
            skipped += 1
            continue
        out.append(row)
    return out, skipped


def head_overlay(rows):
    return {str(r['race_code']): r for r in rows}


def method_probs(head_row, method: str, blend_weight: float):
    if method == 'BEFORE_AI_FINAL':
        return head_row['ai']
    if method == 'AFTER_BASIC_AI_BLEND':
        return fourway.blend_probs(head_row['basic'], head_row['ai'], blend_weight)
    raise ValueError(method)


def evaluate(rows, overlay, threshold: float, k: int, method: str, blend_weight: float):
    bet_races = 0
    head1_wins = 0
    hits = 0
    missing_head_overlay = 0
    invest_per_point = 0.0
    return_per_point = 0.0
    invest_fixed = 0.0
    return_fixed = 0.0

    for row in rows:
        if float(row['ai_head_mass']) < float(threshold):
            continue

        bet_races += 1
        invest_per_point += int(k) * 100.0
        invest_fixed += 1000.0

        # 頭が外れたレースはBEFORE/AFTERとも払戻0。
        # 2着統合モデルは頭が当たったレースだけあればROI差を厳密に比較できる。
        if int(row['actual_head']) != 1:
            continue

        head1_wins += 1
        code = str(row['race_code'])
        hrow = overlay.get(code)
        if hrow is None:
            missing_head_overlay += 1
            continue

        probs = method_probs(hrow, method, blend_weight)
        picks = fourway.topk(probs, int(k))
        actual_second = int(hrow['actual_second'])
        if actual_second not in picks:
            continue

        hits += 1
        payout = float(row['payout'])
        return_per_point += payout
        stake_each = 1000.0 / int(k)
        return_fixed += payout * (stake_each / 100.0)

    return {
        'bet_races': bet_races,
        'head1_wins': head1_wins,
        'head1_rate': head1_wins / bet_races if bet_races else 0.0,
        'hits': hits,
        'hit_rate': hits / bet_races if bet_races else 0.0,
        'second_capture': hits / head1_wins if head1_wins else 0.0,
        'second_miss': head1_wins - hits,
        'missing_head_overlay': missing_head_overlay,
        'roi_per_point': return_per_point / invest_per_point if invest_per_point else 0.0,
        'roi_fixed': return_fixed / invest_fixed if invest_fixed else 0.0,
        'invest_per_point': invest_per_point,
        'return_per_point': return_per_point,
        'invest_fixed': invest_fixed,
        'return_fixed': return_fixed,
    }


def gained_lost(rows, overlay, threshold: float, k: int, blend_weight: float):
    changed = gained = lost = same_hit = same_miss = missing = 0

    for row in rows:
        if float(row['ai_head_mass']) < float(threshold):
            continue
        if int(row['actual_head']) != 1:
            continue

        hrow = overlay.get(str(row['race_code']))
        if hrow is None:
            missing += 1
            continue

        actual = int(hrow['actual_second'])
        before = set(fourway.topk(hrow['ai'], int(k)))
        after_probs = fourway.blend_probs(hrow['basic'], hrow['ai'], blend_weight)
        after = set(fourway.topk(after_probs, int(k)))

        if before != after:
            changed += 1
        b = actual in before
        a = actual in after
        if a and not b:
            gained += 1
        elif b and not a:
            lost += 1
        elif a and b:
            same_hit += 1
        else:
            same_miss += 1

    return {
        'changed': changed,
        'gained': gained,
        'lost': lost,
        'net': gained - lost,
        'same_hit': same_hit,
        'same_miss': same_miss,
        'missing': missing,
    }


def print_compare(title, rows, overlay, threshold, k, blend_weight):
    before = evaluate(rows, overlay, threshold, k, 'BEFORE_AI_FINAL', blend_weight)
    after = evaluate(rows, overlay, threshold, k, 'AFTER_BASIC_AI_BLEND', blend_weight)

    print(f'\n【{title}】')
    print('方式                    購入R  1C実勝率  2着捕捉  紐抜け  的中率  100円/点ROI  1000円均等ROI')
    print('-' * 112)
    for name, r in (('BEFORE_AI_FINAL', before), ('AFTER_BASIC_AI_BLEND', after)):
        print(
            f"{name:<25} {r['bet_races']:>5d}   {r['head1_rate']*100:>7.2f}%  "
            f"{r['second_capture']*100:>6.2f}%  {r['second_miss']:>5d}  "
            f"{r['hit_rate']*100:>6.2f}%    {r['roi_per_point']*100:>8.2f}%      "
            f"{r['roi_fixed']*100:>8.2f}%"
        )

    d = gained_lost(rows, overlay, threshold, k, blend_weight)
    print(
        f"AFTERの差              : 変更={d['changed']}R / 拾い={d['gained']}R / "
        f"失い={d['lost']}R / 純増={d['net']:+d}R"
    )
    print(
        f"的中率差               : {(after['hit_rate']-before['hit_rate'])*100:+.2f}pt\n"
        f"2着捕捉率差            : {(after['second_capture']-before['second_capture'])*100:+.2f}pt\n"
        f"100円/点ROI差          : {(after['roi_per_point']-before['roi_per_point'])*100:+.2f}pt\n"
        f"1000円均等ROI差        : {(after['roi_fixed']-before['roi_fixed'])*100:+.2f}pt"
    )

    if before['missing_head_overlay'] or after['missing_head_overlay'] or d['missing']:
        print(
            f"注意: 頭的中レースの統合2着率不足={max(before['missing_head_overlay'], after['missing_head_overlay'], d['missing'])}R"
        )

    return before, after


def main():
    if len(sys.argv) != 3:
        print('Usage: python3 analysis/head1_exacta_blend_before_after.py P1_BOATS_CSV P2_BOATS_CSV')
        return 1

    p1_csv, p2_csv = sys.argv[1], sys.argv[2]

    print('現行2連単・統合2着率・払戻を共通化中...')
    data = step3.build_common_records(p1_csv, p2_csv)
    payouts, payout_column = exacta.load_exacta_payouts(data['p1_start'], data['p2_end'])
    all_rows = exacta.build_rows(data['records'], payouts)

    record_map = {
        str(record['race_code']): record
        for period in ('P1', 'P2')
        for record in data['records'][period]
    }

    p1_rows, p1_c1_skip = filter_boat1_in_course1(all_rows['P1'], record_map)
    p2_rows, p2_c1_skip = filter_boat1_in_course1(all_rows['P2'], record_map)
    if not p1_rows or not p2_rows:
        raise RuntimeError('1号艇=1Cの2連単評価レースがありません')

    # BASIC_K10は未来情報なしの既存ローダを再利用する。
    basic2.P1_START = data['p1_start']
    basic2.P1_END = data['p1_end']
    basic2.P2_START = data['p2_start']
    basic2.P2_END = data['p2_end']
    snapshots, _basic_meta = basic2.load_snapshots()

    csv_races = fourway.final_aite.load_boats(p1_csv, p2_csv)
    head_rows, head_skip = fourway.build_rows(data, snapshots, csv_races)
    p1_overlay = head_overlay(head_rows['P1'])
    p2_overlay = head_overlay(head_rows['P2'])

    # 統合重みは前段と同じ選択規則でP1だけから選ぶ。
    blend_best, _blend_table = fourway.tune_blend(head_rows['P1'])
    _blend_key, blend_weight, _blend_metric = blend_best
    blend_weight = float(blend_weight)

    # 現行AI_FINALだけでP1の購入条件を選択し、その条件をAFTERにも固定。
    p1_grid = exacta.strategy_grid(p1_rows, 'ai')
    chosen = exacta.select_p1_strategy(p1_grid)
    _key, threshold, k, selected_p1 = chosen
    threshold = float(threshold)
    k = int(k)

    label = '全て' if threshold <= 0 else f'{threshold*100:.0f}%以上'

    print('=' * 132)
    print('イン逃げ時2着予想：統合2着率を2連単へ仮反映 前後比較')
    print('=' * 132)
    print(f"P1                  : {data['p1_start']} ～ {data['p1_end']}")
    print(f"P2完全ホールドアウト: {data['p2_start']} ～ {data['p2_end']}")
    print(f"2連単払戻列          : boat_race.race_payouts.{payout_column}")
    print('対象                 : 1号艇=1C のレースだけ')
    print(f"統合2着率            : BASIC_K10^(1-w) × AI_FINAL^w / w={blend_weight:.2f}（P1選択）")
    print(f"購入条件             : 現行AI_FINALのP1選択を固定 → P(1C頭)={label} / 上位{k}点")
    print('AFTERで再最適化      : しない')
    print('投資                 : 100円/点 + 1R1000円均等理論値')
    print('本番Web変更          : なし')
    print(f"評価母集団           : P1={len(p1_rows)}R / P2={len(p2_rows)}R")
    print(f"1号艇!=1C除外        : P1={p1_c1_skip}R / P2={p2_c1_skip}R")
    print(
        f"現行P1選択時成績     : 購入={selected_p1['bet_races']}R / "
        f"的中={selected_p1['hit_rate']*100:.2f}% / 1000円均等ROI={selected_p1['roi_fixed']*100:.2f}%"
    )

    print_compare('P1 参考', p1_rows, p1_overlay, threshold, k, blend_weight)
    p2_before, p2_after = print_compare('P2 ホールドアウト（最重要）', p2_rows, p2_overlay, threshold, k, blend_weight)

    print('\n【P2：同じP(1C頭)ゲートで点数だけ1～3点比較】')
    print('点数   BEFORE的中  AFTER的中   差     BEFORE ROI  AFTER ROI   ROI差   2着捕捉差')
    print('-' * 96)
    for kk in (1, 2, 3):
        b = evaluate(p2_rows, p2_overlay, threshold, kk, 'BEFORE_AI_FINAL', blend_weight)
        a = evaluate(p2_rows, p2_overlay, threshold, kk, 'AFTER_BASIC_AI_BLEND', blend_weight)
        print(
            f" {kk}     {b['hit_rate']*100:>7.2f}%   {a['hit_rate']*100:>7.2f}%  "
            f"{(a['hit_rate']-b['hit_rate'])*100:>+6.2f}  "
            f"{b['roi_fixed']*100:>9.2f}%  {a['roi_fixed']*100:>8.2f}%  "
            f"{(a['roi_fixed']-b['roi_fixed'])*100:>+7.2f}  "
            f"{(a['second_capture']-b['second_capture'])*100:>+8.2f}pt"
        )

    print('\n【判断方針】')
    print('1. 最重要はP2。同じ購入レース・同じ点数でAFTERが改善するかを見る。')
    print('2. まず紐抜け/2着捕捉が改善し、回収率も悪化しないことを確認する。')
    print('3. P1だけ良くP2で悪化なら2連単へは反映しない。')
    print('4. 改善確認後に初めてWebの2連単表示へ統合2着率を反映する。')
    print('5. 3連単・最終予想への反映は、その後に別比較する。')

    if p2_before['missing_head_overlay'] or p2_after['missing_head_overlay']:
        print('判定保留             : P2頭的中レースに統合2着率不足があります。先に原因確認が必要です。')
    else:
        print('判定可能             : P2頭的中レースの統合2着率は揃っています。')

    print('\n【共通化参考】')
    for key in sorted(head_skip):
        print(f'{key:<36}: {head_skip[key]}')

    print('=' * 132)
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
