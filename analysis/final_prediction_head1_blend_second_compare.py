#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
最終予想：1号艇頭時の2着候補だけ BASIC+AI統合へ差し替える前後比較。

目的
----
前段で以下を確認済み。
- ② 現行最終予想2着より、④ BASIC_AI_BLEND の2着順位付けはP2で明確に良い。
- 一方、③ 現行2連単(AI_FINAL)を④へ差し替えてもP2では改善しなかった。

そこで本スクリプトでは、2連単は触らず、最終予想だけを対象にする。

BEFORE_CURRENT
    現行 PredictionLogic::buildSummary() 相当。
    頭・kiru・3着候補は現行のまま。
    2着候補は現行最終順位から上位最大3艇。

AFTER_BLEND_SECOND
    頭・kiru・3着候補は完全固定。
    現行本命が1号艇、かつ1号艇=1Cのときだけ、
    2着候補の順位を BASIC_AI_BLEND へ差し替える。

重要
----
- BASIC_AI_BLEND の重みは、展示進入優先修正版P1で選ばれた w=0.90 を固定。
  本スクリプトでは再調整しない。
- BASIC_K10 は各レース開始前までの履歴だけで作る。
- 評価対象レース自身の結果は履歴更新より前に予測を作るためリークしない。
- 2着候補は現行と同じ非kiru集合から同じ点数だけ選ぶ。
- 3着候補は現行と同じ「非kiruかつ頭以外の全艇」。
- 本番Web / PredictionLogicは変更しない。

評価
----
- 現行本命=1号艇 かつ 1号艇=1C のレース
- 頭実勝率
- 頭的中時の2着候補捕捉率
- 3連単的中率
- 平均点数
- 100円/点ROI
- BEFORE→AFTER の拾い/失い

Usage
-----
python3 analysis/final_prediction_head1_blend_second_compare.py \
  analysis/output/final_prediction_boats_20260615_20260714.csv \
  analysis/output/final_prediction_boats_20260715_20260814.csv
"""

from __future__ import annotations

import sys
from collections import Counter, defaultdict, deque
from datetime import timedelta
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import final_prediction_ai_opponent_compare as final_aite
import head1_exacta_blend_before_after as exacta_before
import head1_exacta_blend_before_after_fixed as fixed
import head1_second_probability_4way_compare as fourway
import second_place_head1_k_compare as basic2
import trifecta_probability_order_compare as step3
from slit_validate_v2 import connect_db


HISTORY_DAYS = 730
BLEND_WEIGHT = 0.90  # 展示進入優先修正版P1で選択済み。ここでは固定。


def period_name(race_date, p1_start, p1_end, p2_start, p2_end):
    if p1_start <= race_date <= p1_end:
        return 'P1'
    if p2_start <= race_date <= p2_end:
        return 'P2'
    return None


def complete_course_map(prepared, key):
    return fixed.complete_course_map(prepared, key)


def load_all_prerace_basic_snapshots(p1_start, p1_end, p2_start, p2_end):
    """
    全評価レースに対して、レース開始前時点の BASIC_K10 を作る。

    評価レースを「実際に1号艇が勝ったレース」に限定しない点が重要。
    予測は結果を見る前に作り、その後で履歴だけ更新する。
    """
    history_start = p1_start - timedelta(days=HISTORY_DAYS)
    eval_end = p2_end

    player_hist = defaultdict(lambda: deque(maxlen=100))
    second_course_hist = Counter()
    head1_hist_n = 0

    snapshots = {'P1': [], 'P2': []}
    skipped = Counter()
    target_source = Counter()

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

        if exhibition_map is not None:
            target_map = exhibition_map
            source = 'exhibition_complete'
        elif history_map is not None:
            target_map = history_map
            source = 'history_fallback_complete'
        else:
            target_map = None
            source = 'target_incomplete'

        pname = period_name(race_date, p1_start, p1_end, p2_start, p2_end)

        # ---- 予測作成：ここでは当該レースの勝敗を条件にしない ----
        if pname is not None:
            if not lanes_ok:
                skipped[f'{pname}_entry_not_6'] += 1
            elif target_map is None:
                skipped[f'{pname}_target_course_incomplete'] += 1
            else:
                target_source[f'{pname}_{source}'] += 1
                second_lane = int(seconds[0]['lane']) if len(seconds) == 1 else None
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
                        y=1 if second_lane is not None and lane == second_lane else 0,
                        p0=p0,
                        n_pc=n_pc,
                        w_pc=w_pc,
                    ))

                if len(candidates) == 5:
                    candidates.sort(key=lambda x: x.lane)
                    snapshots[pname].append(basic2.RaceSnapshot(race_code, race_date, candidates))
                else:
                    skipped[f'{pname}_snapshot_invalid'] += 1

        # ---- レース終了後の履歴更新。上の予測には使われない ----
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
        cur = conn.cursor(name='final_head1_blend_prerace_stream')
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
        'target_source': dict(target_source),
        'history_head1_n': head1_hist_n,
    }


def load_trifecta_payouts(start_date, end_date):
    payouts = {}
    sql = """
        SELECT rp.race_code, rp.trifecta_payout
        FROM boat_race.race_payouts rp
        JOIN boat_race.race_master rm
          ON rm.race_code = rp.race_code
        WHERE rm.race_date BETWEEN %s::date AND %s::date
    """
    with connect_db() as conn:
        with conn.cursor() as cur:
            cur.execute(sql, (start_date.isoformat(), end_date.isoformat()))
            for race_code, payout in cur.fetchall():
                try:
                    payouts[str(race_code)] = float(payout or 0.0)
                except (TypeError, ValueError):
                    payouts[str(race_code)] = 0.0
    return payouts


def expand_formation(head, seconds, thirds):
    out = set()
    for second in seconds:
        for third in thirds:
            if third == second or third == head or second == head:
                continue
            out.add((int(head), int(second), int(third)))
    return out


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

            # 今回の変更対象は「現行本命=1号艇」だけ。
            if int(head) != 1:
                skip[f'{period}_current_head_not_1'] += 1
                continue

            # さらに1号艇が1Cにいる標準進入だけ。
            if exacta_before.course1_boat(record) != 1:
                skip[f'{period}_boat1_not_course1'] += 1
                continue

            snapshot = snap_map[period].get(code)
            if snapshot is None:
                skip[f'{period}_basic_snapshot_missing'] += 1
                continue

            base = fourway.basic_probs(snapshot)
            ai, _head_mass = fourway.ai_final_probs(record, snapshot)
            if ai is None or set(ai) != set(base):
                skip[f'{period}_ai_dist_invalid'] += 1
                continue
            blend = fourway.blend_probs(base, ai, BLEND_WEIGHT)

            kiru = {lane for lane, b in boats.items() if int(b['kiru']) == 1}
            eligible = [lane for lane in range(1, 7) if lane != 1 and lane not in kiru]
            if not eligible:
                skip[f'{period}_eligible_empty'] += 1
                continue

            k = min(3, len(eligible))
            current_seconds = final_aite.select_current(rank_boats, 1, kiru, k)
            blend_seconds = sorted(eligible, key=lambda b: (-float(blend.get(b, 0.0)), b))[:k]
            thirds = list(eligible)

            before_tickets = expand_formation(1, current_seconds, thirds)
            after_tickets = expand_formation(1, blend_seconds, thirds)
            if not before_tickets or not after_tickets:
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
            actual_third = actual[2]

            out[period].append({
                'race_code': code,
                'head_won': actual[0] == 1,
                'actual': actual,
                'actual_second': actual_second,
                'actual_third': actual_third,
                'actual_second_cut': actual_second in kiru,
                'actual_third_cut': actual_third in kiru,
                'current_seconds': current_seconds,
                'blend_seconds': blend_seconds,
                'thirds': thirds,
                'before_tickets': before_tickets,
                'after_tickets': after_tickets,
                'payout': float(payouts.get(code, 0.0)),
            })
            skip[f'{period}_ready'] += 1

    return out, skip


def evaluate(rows, ticket_key, second_key):
    races = len(rows)
    head_wins = 0
    second_base = 0
    second_hits = 0
    hits = 0
    points = 0
    payout_sum = 0.0
    payout_missing_hits = 0

    for r in rows:
        tickets = r[ticket_key]
        points += len(tickets)

        if r['head_won']:
            head_wins += 1
            # 2着順位付けの純粋比較：実2着が現行kiruでないレースだけ。
            if not r['actual_second_cut']:
                second_base += 1
                if r['actual_second'] in r[second_key]:
                    second_hits += 1

        if r['actual'] in tickets:
            hits += 1
            if r['payout'] > 0:
                payout_sum += r['payout']
            else:
                payout_missing_hits += 1

    invest = points * 100.0
    return {
        'races': races,
        'head_wins': head_wins,
        'head_rate': head_wins / races if races else 0.0,
        'second_base': second_base,
        'second_hits': second_hits,
        'second_capture': second_hits / second_base if second_base else 0.0,
        'hits': hits,
        'hit_rate': hits / races if races else 0.0,
        'points': points,
        'avg_points': points / races if races else 0.0,
        'payout': payout_sum,
        'roi': payout_sum / invest if invest else 0.0,
        'payout_missing_hits': payout_missing_hits,
    }


def compare_changes(rows):
    second_changed = second_gained = second_lost = 0
    trifecta_changed = trifecta_gained = trifecta_lost = 0

    for r in rows:
        cur_second = set(r['current_seconds'])
        new_second = set(r['blend_seconds'])
        if cur_second != new_second:
            second_changed += 1

        if r['head_won'] and not r['actual_second_cut']:
            c = r['actual_second'] in cur_second
            n = r['actual_second'] in new_second
            if n and not c:
                second_gained += 1
            elif c and not n:
                second_lost += 1

        before_hit = r['actual'] in r['before_tickets']
        after_hit = r['actual'] in r['after_tickets']
        if r['before_tickets'] != r['after_tickets']:
            trifecta_changed += 1
        if after_hit and not before_hit:
            trifecta_gained += 1
        elif before_hit and not after_hit:
            trifecta_lost += 1

    return {
        'second_changed': second_changed,
        'second_gained': second_gained,
        'second_lost': second_lost,
        'trifecta_changed': trifecta_changed,
        'trifecta_gained': trifecta_gained,
        'trifecta_lost': trifecta_lost,
    }


def print_period(title, rows):
    before = evaluate(rows, 'before_tickets', 'current_seconds')
    after = evaluate(rows, 'after_tickets', 'blend_seconds')
    diff = compare_changes(rows)

    print(f'\n【{title}】')
    print('方式                    対象R  頭実勝率  2着捕捉   的中率   平均点数   100円/点ROI')
    print('-' * 96)
    for name, r in (('BEFORE_CURRENT', before), ('AFTER_BLEND_SECOND', after)):
        print(
            f"{name:<24} {r['races']:>5d}   {r['head_rate']*100:>7.2f}%  "
            f"{r['second_capture']*100:>7.2f}%  {r['hit_rate']*100:>7.2f}%  "
            f"{r['avg_points']:>8.2f}点   {r['roi']*100:>9.2f}%"
        )

    print(
        f"2着候補差              : 変更={diff['second_changed']}R / "
        f"拾い={diff['second_gained']}R / 失い={diff['second_lost']}R / "
        f"純増={diff['second_gained']-diff['second_lost']:+d}R"
    )
    print(
        f"3連単差                : 変更={diff['trifecta_changed']}R / "
        f"拾い={diff['trifecta_gained']}R / 失い={diff['trifecta_lost']}R / "
        f"純増={diff['trifecta_gained']-diff['trifecta_lost']:+d}R"
    )
    print(
        f"2着捕捉率差            : {(after['second_capture']-before['second_capture'])*100:+.2f}pt\n"
        f"3連単的中率差          : {(after['hit_rate']-before['hit_rate'])*100:+.2f}pt\n"
        f"100円/点ROI差          : {(after['roi']-before['roi'])*100:+.2f}pt"
    )

    missing = max(before['payout_missing_hits'], after['payout_missing_hits'])
    if missing:
        print(f'注意: 的中したが3連単払戻0/欠損={missing}R')

    return before, after


def main():
    if len(sys.argv) != 3:
        print('Usage: python3 analysis/final_prediction_head1_blend_second_compare.py P1_BOATS_CSV P2_BOATS_CSV')
        return 1

    p1_csv, p2_csv = sys.argv[1], sys.argv[2]

    print('全レースの事前BASIC_K10・STEP3出目モデル・現行最終予想を共通化中...')
    data = step3.build_common_records(p1_csv, p2_csv)
    csv_races = final_aite.load_boats(p1_csv, p2_csv)
    snapshots, snap_meta = load_all_prerace_basic_snapshots(
        data['p1_start'], data['p1_end'], data['p2_start'], data['p2_end']
    )
    payouts = load_trifecta_payouts(data['p1_start'], data['p2_end'])
    rows, skip = build_rows(data, csv_races, snapshots, payouts)

    if not rows['P1'] or not rows['P2']:
        raise RuntimeError('最終予想の共通評価レースがありません')

    print('=' * 132)
    print('最終予想：1号艇頭時の2着候補だけ統合2着率へ差し替え 前後比較')
    print('=' * 132)
    print(f"P1                  : {data['p1_start']} ～ {data['p1_end']}")
    print(f"P2完全ホールドアウト: {data['p2_start']} ～ {data['p2_end']}")
    print('対象                 : 現行本命=1号艇 かつ 1号艇=1C')
    print('頭                   : 現行固定')
    print('kiru                 : 現行固定')
    print('3着候補              : 現行固定（非kiruの全相手）')
    print('BEFORE 2着           : 現行最終順位から上位最大3艇')
    print(f'AFTER 2着            : BASIC_AI_BLEND 上位最大3艇 / w={BLEND_WEIGHT:.2f}固定')
    print('AFTER側再最適化      : なし')
    print('投資                 : 現行/AFTERとも100円/点（同一候補数・同一3着集合）')
    print('本番Web変更          : なし')
    print(f"共通評価             : P1={len(rows['P1'])}R / P2={len(rows['P2'])}R")

    print('\n【今回コースsource】')
    for key, value in sorted(snap_meta['target_source'].items()):
        print(f'{key:<36}: {value}')

    print_period('P1 参考', rows['P1'])
    p2_before, p2_after = print_period('P2 ホールドアウト（最重要）', rows['P2'])

    print('\n【共通化スキップ】')
    for key in sorted(skip):
        print(f'{key:<36}: {skip[key]}')

    print('\n【判断方針】')
    print('1. 最重要はP2。頭・kiru・3着・点数を固定したまま、2着差し替えだけの効果を見る。')
    print('2. P2で2着捕捉率が改善し、3連単的中率も改善することを優先する。')
    print('3. ROIは悪化しないことを確認する。高配当数件だけの上振れなら採用しない。')
    print('4. P2で改善が確認できた場合だけ、PredictionLogicへの実装を検討する。')
    print('5. 2連単AI_FINALは今回の結果では変更しない。')

    if (
        p2_after['second_capture'] > p2_before['second_capture']
        and p2_after['hit_rate'] > p2_before['hit_rate']
        and p2_after['roi'] >= p2_before['roi']
    ):
        print('判定候補             : ④を最終予想2着へ採用する方向で次工程へ。')
    else:
        print('判定候補             : ④の最終予想2着への採用は保留。P2差を確認する。')

    print('=' * 132)
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
