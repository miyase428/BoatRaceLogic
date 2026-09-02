#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
イン逃げ時2着予想：統合2着率の2連単前後比較（展示進入優先修正版）。

背景
----
旧比較では、評価レースの今回コースを
  result_detail -> exhibition_live -> lane
の艇単位フォールバックで復元していたため、
result_detailが4艇、exhibitionが2艇のようなレースで、
各値は有効でも6艇全体が1～6の完全な並びにならず、
BASIC_K10スナップショットが欠落するケースがあった。

本番Head1SecondPlaceLogicは、今回レースの進入が6艇完全なら
外部から渡されたcourseByBoat（展示進入）を今回コースとして使う。
そこで本スクリプトでは評価対象レースの「今回コース」だけを
展示進入6艇完全時は展示進入へ統一する。

過去履歴側は従来どおり
  result_detail -> exhibition_live -> lane
で復元し、未来情報を使わない。

比較
----
BEFORE_AI_FINAL
    現行「イン1着時 2連単」の順位。

AFTER_BASIC_AI_BLEND
    BASIC_K10 と AI_FINAL の幾何平均。
    重みwはP1だけで選び、P2では固定。

購入条件
--------
- 現行AI_FINALだけでP1から選んだ P(1C頭)ゲート / 点数を固定。
- AFTER側では再最適化しない。
- P2は完全ホールドアウト。
- 本番Web / PredictionLogicは変更しない。

Usage
-----
python3 analysis/head1_exacta_blend_before_after_fixed.py \\
  analysis/output/final_prediction_boats_20260615_20260714.csv \\
  analysis/output/final_prediction_boats_20260715_20260814.csv
"""

from __future__ import annotations

import sys
from collections import Counter, defaultdict, deque
from datetime import timedelta
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import head1_exacta_bet_strategy_compare as exacta
import head1_exacta_blend_before_after as old_compare
import head1_second_probability_4way_compare as fourway
import second_place_head1_k_compare as basic2
import trifecta_probability_order_compare as step3
from slit_validate_v2 import connect_db


HISTORY_DAYS = 730


def period_name(race_date, p1_start, p1_end, p2_start, p2_end):
    if p1_start <= race_date <= p1_end:
        return 'P1'
    if p2_start <= race_date <= p2_end:
        return 'P2'
    return None


def complete_course_map(prepared, key):
    if len(prepared) != 6:
        return None
    vals = [basic2.valid_course(r.get(key)) for r in prepared]
    if any(v is None for v in vals) or sorted(vals) != [1, 2, 3, 4, 5, 6]:
        return None
    return {int(r['lane']): int(v) for r, v in zip(prepared, vals)}


def load_snapshots_production_aligned(p1_start, p1_end, p2_start, p2_end):
    """
    BASIC_K10の未来情報なしスナップショットを作る。

    違いは評価対象レースの今回コースだけ。
    展示進入が6艇完全なら展示進入を6艇一括採用し、
    本番Head1SecondPlaceLogicのcourseByBoat指定に合わせる。

    過去履歴の更新は従来ローダと同じ艇単位フォールバックを維持する。
    """
    history_start = p1_start - timedelta(days=HISTORY_DAYS)
    eval_end = p2_end

    player_hist = defaultdict(lambda: deque(maxlen=100))
    second_course_hist = Counter()
    head1_hist_n = 0
    snapshots = {'P1': [], 'P2': []}
    skipped = Counter()
    target_course_source = Counter()

    sql = """
        SELECT
            rm.race_date,
            re.race_code,
            re.lane_number,
            re.player_id::text,
            rrd.rank,
            rrd.entry_course AS result_course,
            el.entry_course AS exhibition_course
        FROM boat_race.race_entry re
        JOIN boat_race.race_master rm
          ON rm.race_code = re.race_code
        LEFT JOIN boat_race.race_result_detail rrd
          ON rrd.race_code = re.race_code
         AND rrd.player_id = re.player_id
        LEFT JOIN LATERAL (
            SELECT x.entry_course
            FROM boat_race.exhibition_live x
            WHERE x.race_code = re.race_code
              AND x.player_id = re.player_id
            LIMIT 1
        ) el ON TRUE
        WHERE rm.race_date BETWEEN %s::date AND %s::date
        ORDER BY rm.race_date, re.race_code, re.lane_number
    """

    def process_race(race_date, race_code, raw_rows):
        nonlocal head1_hist_n
        if not raw_rows:
            return

        prepared = []
        for lane, player_id, rank, result_course, exhibition_course in raw_rows:
            history_course, _src = basic2.actual_course(result_course, exhibition_course, lane)
            prepared.append({
                'lane': basic2.valid_course(lane),
                'player_id': str(player_id or '').strip(),
                'rank': basic2.as_int(rank),
                'history_course': history_course,
                'exhibition_course': basic2.valid_course(exhibition_course),
            })

        six_rows = len(prepared) == 6
        lanes = [r['lane'] for r in prepared]
        lanes_ok = six_rows and sorted(c for c in lanes if c is not None) == [1, 2, 3, 4, 5, 6]

        winners = [r for r in prepared if r['rank'] == 1]
        seconds = [r for r in prepared if r['rank'] == 2]
        top2_ok = len(winners) == 1 and len(seconds) == 1
        head1_win = top2_ok and winners[0]['lane'] == 1

        history_map = complete_course_map(prepared, 'history_course')
        exhibition_map = complete_course_map(prepared, 'exhibition_course')

        # 本番の「今回コース」に合わせる。
        # 展示進入が完全なら6艇一括で展示進入、なければ従来復元が完全な場合だけ使う。
        if exhibition_map is not None:
            target_map = exhibition_map
            target_source = 'exhibition_complete'
        elif history_map is not None:
            target_map = history_map
            target_source = 'history_fallback_complete'
        else:
            target_map = None
            target_source = 'target_incomplete'

        pname = period_name(race_date, p1_start, p1_end, p2_start, p2_end)
        if pname is not None:
            if not lanes_ok:
                skipped[f'{pname}_entry_not_6'] += 1
            elif not top2_ok:
                skipped[f'{pname}_top2_not_unique'] += 1
            elif not head1_win:
                skipped[f'{pname}_not_head1_win'] += 1
            elif target_map is None:
                skipped[f'{pname}_target_course_incomplete'] += 1
            else:
                target_course_source[f'{pname}_{target_source}'] += 1
                second_lane = int(seconds[0]['lane'])
                candidates = []
                for r in prepared:
                    lane = int(r['lane'])
                    if lane == 1:
                        continue
                    target_course = int(target_map[lane])
                    p0 = basic2.prior_course_rate(second_course_hist, head1_hist_n, target_course)
                    n_pc, w_pc = basic2.player_counts(player_hist[r['player_id']], target_course)
                    candidates.append(basic2.CandidateSnapshot(
                        lane=lane,
                        course=target_course,
                        y=1 if lane == second_lane else 0,
                        p0=p0,
                        n_pc=n_pc,
                        w_pc=w_pc,
                    ))

                if len(candidates) == 5 and sum(c.y for c in candidates) == 1:
                    candidates.sort(key=lambda x: x.lane)
                    snapshots[pname].append(basic2.RaceSnapshot(race_code, race_date, candidates))
                else:
                    skipped[f'{pname}_snapshot_invalid'] += 1

        # ---- ここからレース終了後の履歴更新。評価レース自身の予測には使わない ----
        # 過去母集団は従来どおりhistory_courseの完全レースだけで更新。
        if lanes_ok and top2_ok and head1_win and history_map is not None:
            second_course = history_map.get(int(seconds[0]['lane']))
            if second_course is not None:
                head1_hist_n += 1
                second_course_hist[int(second_course)] += 1

        eligible_head1 = lanes_ok and top2_ok and head1_win
        for r in prepared:
            pid = r['player_id']
            history_course = basic2.valid_course(r['history_course'])
            if not pid or history_course is None:
                continue
            player_hist[pid].append({
                'course': int(history_course),
                'eligible_head1': eligible_head1,
                'second': 1 if eligible_head1 and r['rank'] == 2 else 0,
            })

    with connect_db() as conn:
        cur = conn.cursor(name='head1_second_prod_aligned_stream')
        cur.itersize = 10000
        cur.execute(sql, (history_start.isoformat(), eval_end.isoformat()))

        current_code = None
        current_date = None
        rows = []
        for race_date, race_code, lane, player_id, rank, result_course, exhibition_course in cur:
            race_code = str(race_code)
            if current_code is None:
                current_code = race_code
                current_date = race_date
            if race_code != current_code:
                process_race(current_date, current_code, rows)
                rows = []
                current_code = race_code
                current_date = race_date
            rows.append((lane, player_id, rank, result_course, exhibition_course))

        if current_code is not None:
            process_race(current_date, current_code, rows)
        cur.close()

    return snapshots, {
        'skipped': dict(skipped),
        'target_course_source': dict(target_course_source),
        'history_head1_n': head1_hist_n,
    }


def print_point_compare(p2_rows, p2_overlay, threshold, blend_weight):
    print('\n【P2：同じP(1C頭)ゲートで点数だけ1～3点比較】')
    print('点数   BEFORE的中  AFTER的中   差     BEFORE ROI  AFTER ROI   ROI差   2着捕捉差')
    print('-' * 96)
    for k in (1, 2, 3):
        before = old_compare.evaluate(
            p2_rows, p2_overlay, threshold, k,
            'BEFORE_AI_FINAL', blend_weight,
        )
        after = old_compare.evaluate(
            p2_rows, p2_overlay, threshold, k,
            'AFTER_BASIC_AI_BLEND', blend_weight,
        )
        print(
            f" {k}     {before['hit_rate']*100:>7.2f}%  {after['hit_rate']*100:>7.2f}%  "
            f"{(after['hit_rate']-before['hit_rate'])*100:+6.2f}   "
            f"{before['roi_fixed']*100:>8.2f}%  {after['roi_fixed']*100:>8.2f}%  "
            f"{(after['roi_fixed']-before['roi_fixed'])*100:+7.2f}   "
            f"{(after['second_capture']-before['second_capture'])*100:+7.2f}pt"
        )


def main():
    if len(sys.argv) != 3:
        print('Usage: python3 analysis/head1_exacta_blend_before_after_fixed.py P1_BOATS_CSV P2_BOATS_CSV')
        return 1

    p1_csv, p2_csv = sys.argv[1], sys.argv[2]
    print('展示進入優先でBASIC_K10を再構築し、2連単を前後比較中...')

    data = step3.build_common_records(p1_csv, p2_csv)
    payouts, payout_column = exacta.load_exacta_payouts(data['p1_start'], data['p2_end'])
    all_rows = exacta.build_rows(data['records'], payouts)

    record_map = {
        str(record['race_code']): record
        for period in ('P1', 'P2')
        for record in data['records'][period]
    }
    p1_rows, p1_c1_skip = old_compare.filter_boat1_in_course1(all_rows['P1'], record_map)
    p2_rows, p2_c1_skip = old_compare.filter_boat1_in_course1(all_rows['P2'], record_map)

    snapshots, snap_meta = load_snapshots_production_aligned(
        data['p1_start'], data['p1_end'], data['p2_start'], data['p2_end']
    )
    csv_races = fourway.final_aite.load_boats(p1_csv, p2_csv)
    head_rows, head_skip = fourway.build_rows(data, snapshots, csv_races)
    p1_overlay = old_compare.head_overlay(head_rows['P1'])
    p2_overlay = old_compare.head_overlay(head_rows['P2'])

    if not head_rows['P1'] or not head_rows['P2']:
        raise RuntimeError('展示進入優先修正版の統合2着率が作れませんでした')

    # 統合重みは修正版BASICでP1から選び直し、P2固定。
    blend_best, blend_table = fourway.tune_blend(head_rows['P1'])
    _blend_key, blend_weight, _blend_metric = blend_best
    blend_weight = float(blend_weight)

    # 購入条件は現行AI_FINALだけでP1選択。AFTERでは再最適化しない。
    p1_grid = exacta.strategy_grid(p1_rows, 'ai')
    chosen = exacta.select_p1_strategy(p1_grid)
    _key, threshold, k, selected_p1 = chosen
    threshold = float(threshold)
    k = int(k)
    label = '全て' if threshold <= 0 else f'{threshold*100:.0f}%以上'

    print('=' * 132)
    print('イン逃げ時2着予想：統合2着率を2連単へ仮反映 前後比較【展示進入優先修正版】')
    print('=' * 132)
    print(f"P1                  : {data['p1_start']} ～ {data['p1_end']}")
    print(f"P2完全ホールドアウト: {data['p2_start']} ～ {data['p2_end']}")
    print(f"2連単払戻列          : boat_race.race_payouts.{payout_column}")
    print('今回コース           : 展示進入6艇完全なら展示進入を6艇一括採用')
    print('過去履歴コース       : result_detail -> exhibition_live -> lane（従来どおり）')
    print(f"統合2着率            : BASIC_K10^(1-w) × AI_FINAL^w / w={blend_weight:.2f}（修正版P1選択）")
    print(f"購入条件             : 現行AI_FINALのP1選択を固定 → P(1C頭)={label} / 上位{k}点")
    print('AFTERで再最適化      : しない')
    print('本番Web変更          : なし')
    print(f"評価母集団           : P1={len(p1_rows)}R / P2={len(p2_rows)}R")
    print(f"1号艇!=1C除外        : P1={p1_c1_skip}R / P2={p2_c1_skip}R")
    print(
        f"現行P1選択時成績     : 購入={selected_p1['bet_races']}R / "
        f"的中={selected_p1['hit_rate']*100:.2f}% / 1000円均等ROI={selected_p1['roi_fixed']*100:.2f}%"
    )

    print('\n【修正版BASICの今回コースsource】')
    for key in sorted(snap_meta['target_course_source']):
        print(f"{key:<36}: {snap_meta['target_course_source'][key]}")

    print('\n【修正版 共通化】')
    print(f"P1 統合2着率overlay={len(p1_overlay)}R")
    print(f"P2 統合2着率overlay={len(p2_overlay)}R")
    for key in sorted(head_skip):
        print(f"{key:<36}: {head_skip[key]}")

    print('\n【P1で選んだ統合重み 上位5】')
    print('順位    w     LogLoss   Brier5   Top2')
    for i, (_k, w, metric) in enumerate(blend_table[:5], 1):
        s = metric.summary()
        print(f"{i:>2d}   {w:>4.2f}   {s['logloss']:.6f}  {s['brier']:.6f}  {s['top2']*100:>6.2f}%")

    old_compare.print_compare('P1 参考', p1_rows, p1_overlay, threshold, k, blend_weight)
    p2_before, p2_after = old_compare.print_compare(
        'P2 ホールドアウト（最重要）', p2_rows, p2_overlay, threshold, k, blend_weight
    )
    print_point_compare(p2_rows, p2_overlay, threshold, blend_weight)

    missing_p1 = max(
        old_compare.evaluate(p1_rows, p1_overlay, threshold, k, 'BEFORE_AI_FINAL', blend_weight)['missing_head_overlay'],
        old_compare.evaluate(p1_rows, p1_overlay, threshold, k, 'AFTER_BASIC_AI_BLEND', blend_weight)['missing_head_overlay'],
    )
    missing_p2 = max(
        p2_before['missing_head_overlay'],
        p2_after['missing_head_overlay'],
    )

    print('\n【判定メモ】')
    if missing_p1 == 0 and missing_p2 == 0:
        print('統合2着率不足        : P1=0R / P2=0R → 前後比較の母集団差は解消')
        print('次はP2の2着捕捉・的中率・ROI・拾い/失いで採用可否を判断する。')
    else:
        print(f'統合2着率不足        : P1={missing_p1}R / P2={missing_p2}R')
        print('まだ不足が残るため、採用判断は保留する。')

    print('=' * 132)
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
