#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
統合2着率を2連単へ仮反映した際に発生した「頭的中レースの統合2着率不足」を診断する。

目的
----
head1_exacta_blend_before_after.py で BEFORE/AFTER 比較を行った際、
P(1C頭)ゲート通過かつ実際に1C頭だったレースの一部で BASIC_K10 側の
履歴スナップショットが見つからなかった。

そのレースを列挙し、主な原因を以下へ分類する。
- BASIC履歴スナップショット自体が無い
- 出走6艇不備
- 1/2着一意条件不備
- 1号艇1着条件不一致
- 実コース復元不完備
- CSV/AI側不備

このスクリプトは診断専用で、本番Web/PredictionLogicは変更しない。

Usage
-----
python3 analysis/head1_exacta_blend_missing_diagnose.py \
  analysis/output/final_prediction_boats_20260615_20260714.csv \
  analysis/output/final_prediction_boats_20260715_20260814.csv
"""

from __future__ import annotations

import sys
from collections import Counter
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import head1_exacta_bet_strategy_compare as exacta
import head1_exacta_blend_before_after as before_after
import head1_second_probability_4way_compare as fourway
import second_place_head1_k_compare as basic2
import trifecta_probability_order_compare as step3
from slit_validate_v2 import connect_db


def valid_course(v):
    try:
        c = int(v)
    except (TypeError, ValueError):
        return None
    return c if 1 <= c <= 6 else None


def diagnose_db(conn, race_code: str) -> dict:
    sql = """
        SELECT
            re.lane_number,
            re.player_id::text,
            rrd.rank,
            rrd.entry_course AS result_course,
            el.entry_course AS exhibition_course
        FROM boat_race.race_entry re
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
        WHERE re.race_code = %s
        ORDER BY re.lane_number
    """
    with conn.cursor() as cur:
        cur.execute(sql, (race_code,))
        rows = cur.fetchall()

    prepared = []
    for lane, player_id, rank, result_course, exhibition_course in rows:
        c, source = basic2.actual_course(result_course, exhibition_course, lane)
        prepared.append({
            'lane': valid_course(lane),
            'player_id': str(player_id or ''),
            'rank': basic2.as_int(rank),
            'course': c,
            'source': source,
            'result_course': valid_course(result_course),
            'exhibition_course': valid_course(exhibition_course),
        })

    six_rows = len(prepared) == 6
    lanes = [r['lane'] for r in prepared]
    lanes_ok = six_rows and sorted(c for c in lanes if c is not None) == [1, 2, 3, 4, 5, 6]

    winners = [r for r in prepared if r['rank'] == 1]
    seconds = [r for r in prepared if r['rank'] == 2]
    top2_ok = len(winners) == 1 and len(seconds) == 1
    head1_win = top2_ok and winners[0]['lane'] == 1

    courses = [r['course'] for r in prepared]
    course_complete = (
        six_rows
        and all(c is not None for c in courses)
        and sorted(courses) == [1, 2, 3, 4, 5, 6]
    )

    ex_courses = [r['exhibition_course'] for r in prepared]
    exhibition_complete = (
        six_rows
        and all(c is not None for c in ex_courses)
        and sorted(ex_courses) == [1, 2, 3, 4, 5, 6]
    )

    if not six_rows or not lanes_ok:
        reason = 'entry_not_6'
    elif not top2_ok:
        reason = 'top2_not_unique'
    elif not head1_win:
        reason = 'not_head1_win'
    elif not course_complete:
        reason = 'actual_course_incomplete'
    else:
        reason = 'conditions_ok_but_snapshot_missing'

    return {
        'reason': reason,
        'rows': len(prepared),
        'lanes_ok': lanes_ok,
        'top2_ok': top2_ok,
        'head1_win': head1_win,
        'course_complete': course_complete,
        'exhibition_complete': exhibition_complete,
        'sources': Counter(r['source'] for r in prepared),
    }


def main():
    if len(sys.argv) != 3:
        print('Usage: python3 analysis/head1_exacta_blend_missing_diagnose.py P1_BOATS_CSV P2_BOATS_CSV')
        return 1

    p1_csv, p2_csv = sys.argv[1], sys.argv[2]

    print('不足レースを再構築して原因診断中...')
    data = step3.build_common_records(p1_csv, p2_csv)
    payouts, _payout_column = exacta.load_exacta_payouts(data['p1_start'], data['p2_end'])
    all_rows = exacta.build_rows(data['records'], payouts)

    record_map = {
        str(record['race_code']): record
        for period in ('P1', 'P2')
        for record in data['records'][period]
    }
    p1_rows, _ = before_after.filter_boat1_in_course1(all_rows['P1'], record_map)
    p2_rows, _ = before_after.filter_boat1_in_course1(all_rows['P2'], record_map)

    basic2.P1_START = data['p1_start']
    basic2.P1_END = data['p1_end']
    basic2.P2_START = data['p2_start']
    basic2.P2_END = data['p2_end']
    snapshots, _meta = basic2.load_snapshots()
    snap_map = {
        period: {str(s.race_code): s for s in snapshots[period]}
        for period in ('P1', 'P2')
    }

    csv_races = fourway.final_aite.load_boats(p1_csv, p2_csv)
    head_rows, _skip = fourway.build_rows(data, snapshots, csv_races)
    overlays = {
        'P1': before_after.head_overlay(head_rows['P1']),
        'P2': before_after.head_overlay(head_rows['P2']),
    }

    # 現行AI_FINALのP1選択条件を再現。
    p1_grid = exacta.strategy_grid(p1_rows, 'ai')
    chosen = exacta.select_p1_strategy(p1_grid)
    _key, threshold, k, _selected = chosen
    threshold = float(threshold)
    k = int(k)

    print('=' * 132)
    print('統合2着率不足レース 診断')
    print('=' * 132)
    print(f"P1                  : {data['p1_start']} ～ {data['p1_end']}")
    print(f"P2                  : {data['p2_start']} ～ {data['p2_end']}")
    print(f"現行P1固定条件       : P(1C頭)>={threshold*100:.0f}% / 上位{k}点")
    print('不足定義             : ゲート通過 + 実1C頭 + 統合2着率overlayなし')

    with connect_db() as conn:
        for period, rows in (('P1', p1_rows), ('P2', p2_rows)):
            missing = []
            reasons = Counter()
            snap_presence = Counter()

            for row in rows:
                if float(row['ai_head_mass']) < threshold:
                    continue
                if int(row['actual_head']) != 1:
                    continue

                code = str(row['race_code'])
                if code in overlays[period]:
                    continue

                has_snapshot = code in snap_map[period]
                snap_presence['snapshotあり' if has_snapshot else 'snapshotなし'] += 1

                if not has_snapshot:
                    d = diagnose_db(conn, code)
                    reason = d['reason']
                elif code not in csv_races or set(csv_races[code]) != set(range(1, 7)):
                    d = None
                    reason = 'csv_missing_or_incomplete'
                else:
                    d = None
                    reason = 'snapshot_exists_but_fourway_excluded'

                reasons[reason] += 1
                missing.append((code, reason, d))

            print(f"\n【{period}】不足={len(missing)}R")
            print('snapshot: ' + ' / '.join(f'{k}={v}' for k, v in snap_presence.items()))
            print('原因内訳:')
            for reason, n in reasons.most_common():
                print(f'  {reason:<36} {n:>4d}')

            print('\nレース一覧:')
            print('race_code        reason                              ex完全  actual完全  source')
            print('-' * 112)
            for code, reason, d in missing:
                if d is None:
                    print(f'{code:<16} {reason:<36}    -        -       -')
                    continue
                source = ','.join(f'{k}:{v}' for k, v in sorted(d['sources'].items()))
                print(
                    f"{code:<16} {reason:<36} "
                    f"{str(d['exhibition_complete']):>7}  {str(d['course_complete']):>10}  {source}"
                )

    print('\n【次の判断】')
    print('1. 不足が現行BASIC検証スナップショットの作り方由来なら、2連単比較側を本番Head1SecondPlaceLogic相当へ揃える。')
    print('2. データ欠損そのものなら、そのレースを共通母集団から除外してBEFORE/AFTERを同条件で再比較する。')
    print('3. 原因を直すまでは現在のROI差を採用判断に使わない。')
    print('=' * 132)
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
