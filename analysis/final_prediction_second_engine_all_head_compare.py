#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
共通2着確率③ AI_FINALを、1C頭だけでなく全頭コースへ一般化して検証する。

目的
----
現在までに、1C頭では
  ② CURRENT_FINAL_SECOND < ③ AI_FINAL
を確認済み。

最終予想と2連単で本当に同じ2着エンジンを使うには、現行本命が2C～6Cでも
  P(2着艇 | 現行本命のコースが1着)
として③を使えるか確認する必要がある。

今回の比較
----------
CURRENT_FINAL_SECOND
    現行最終順位から「現行本命・kiru」を除外した順。

AI_FINAL_ALL_HEAD
    現行120通り最終出目確率を、現行本命の今回コース頭で条件付けし、
    残り5艇の2着確率へ集約した順。

重要
----
- 頭は現行本命で固定し、頭選択ロジックは変更しない。
- kiruは現行固定。
- 3着候補は現行と同じ「非kiru・頭以外の全艇」で固定。
- 2着候補は最大3艇。
- 展示進入6艇完全なら展示進入を今回コースとして優先する。
- 展示進入とSTEP3履歴レコードのコースが違う時は、120通りの
  lane紐付けも展示進入へ組み直してから③を計算する。
- 未来情報は使わない。
- 本番Web / PredictionLogicは変更しない。

Usage
-----
python3 analysis/final_prediction_second_engine_all_head_compare.py

または

python3 analysis/final_prediction_second_engine_all_head_compare.py \
  analysis/output/final_prediction_boats_20260615_20260714_OLD.csv \
  analysis/output/final_prediction_boats_20260715_20260814_OLD.csv
"""

from __future__ import annotations

import math
import random
import sys
from collections import Counter, defaultdict
from copy import deepcopy
from datetime import datetime
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import base_trifecta_probability_compare as base_outcome
import final_prediction_ai_opponent_compare as final_aite
import final_prediction_head1_blend_second_compare as betutil
import trifecta_probability_order_compare as step3
from slit_validate_v2 import connect_db


FINAL_DELTA = 0.25
FINAL_GAMMA = 0.25
EPS = 1e-15
BOOTSTRAP_N = 2500
BOOTSTRAP_SEED = 428
METHODS = ('CURRENT_FINAL_SECOND', 'AI_FINAL_ALL_HEAD')


def resolve_csvs(argv):
    if len(argv) == 3:
        return argv[1], argv[2]
    if len(argv) != 1:
        raise SystemExit(
            'Usage: python3 analysis/final_prediction_second_engine_all_head_compare.py '
            '[P1_BOATS_CSV P2_BOATS_CSV]'
        )

    candidates = [
        (
            'analysis/output/final_prediction_boats_20260615_20260714_OLD.csv',
            'analysis/output/final_prediction_boats_20260715_20260814_OLD.csv',
        ),
        (
            'analysis/output/final_prediction_boats_20260615_20260714.csv',
            'analysis/output/final_prediction_boats_20260715_20260814.csv',
        ),
    ]
    for p1, p2 in candidates:
        if Path(p1).exists() and Path(p2).exists():
            return p1, p2
    raise FileNotFoundError('既定P1/P2 CSVが見つかりません。2ファイルを引数で指定してください。')


def valid_course_map(course_by_lane):
    if not isinstance(course_by_lane, dict) or set(course_by_lane) != set(range(1, 7)):
        return None
    out = {}
    for lane in range(1, 7):
        try:
            c = int(course_by_lane[lane])
        except (TypeError, ValueError, KeyError):
            return None
        if c < 1 or c > 6:
            return None
        out[lane] = c
    if set(out.values()) != set(range(1, 7)):
        return None
    return out


def load_exhibition_course_maps(start_date, end_date):
    """評価期間の展示進入をlane(艇番)->courseで取得。6艇完全だけ採用。"""
    sql = """
        SELECT
            rm.race_date,
            re.race_code,
            re.lane_number,
            el.entry_course
        FROM boat_race.race_entry re
        JOIN boat_race.race_master rm
          ON rm.race_code = re.race_code
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

    raw = defaultdict(dict)
    with connect_db() as conn:
        cur = conn.cursor(name='all_head_second_exhibition_courses')
        cur.itersize = 10000
        cur.execute(sql, (start_date.isoformat(), end_date.isoformat()))
        for _race_date, race_code, lane, course in cur:
            try:
                lane_i = int(lane)
                course_i = int(course) if course is not None else 0
            except (TypeError, ValueError):
                continue
            if 1 <= lane_i <= 6 and 1 <= course_i <= 6:
                raw[str(race_code)][lane_i] = course_i
        cur.close()

    out = {}
    for code, cmap in raw.items():
        fixed = valid_course_map(cmap)
        if fixed is not None:
            out[code] = fixed
    return out


def recover_target_lane_probs(record):
    """
    STEP3 recordの旧pattern_lanes/log ratioから、補正後1着率・AI3連対率相当を復元する。
    展示進入へpattern_lanesを組み直す時に同じlane信号を維持するために使う。
    """
    probs = record['probs']
    pattern_lanes = record['pattern_lanes']
    q_win = {lane: 0.0 for lane in range(1, 7)}
    q_trio = {lane: 0.0 for lane in range(1, 7)}

    for idx, lanes in enumerate(pattern_lanes):
        p = float(probs[idx])
        i, j, k = (int(x) for x in lanes)
        q_win[i] += p
        q_trio[i] += p
        q_trio[j] += p
        q_trio[k] += p

    target_win = {}
    target_trio = {}
    for lane in range(1, 7):
        target_win[lane] = max(q_win[lane], EPS) * math.exp(
            float(record['log_win_ratio'][lane])
        )
        target_trio[lane] = max(q_trio[lane], EPS) * math.exp(
            float(record['log_trio_ratio'][lane])
        )
    return target_win, target_trio


def align_record_to_course_map(record, course_by_lane):
    """STEP3の120通りlane紐付けを今回（展示優先）コースへ組み直す。"""
    cmap = valid_course_map(course_by_lane)
    if cmap is None:
        return None

    current = valid_course_map(record.get('course_by_lane', {}))
    if current == cmap:
        return record

    target_win, target_trio = recover_target_lane_probs(record)
    lane_by_course = {course: lane for lane, course in cmap.items()}

    new_pattern_lanes = []
    q_win = {lane: 0.0 for lane in range(1, 7)}
    q_trio = {lane: 0.0 for lane in range(1, 7)}

    for idx, pattern in enumerate(base_outcome.PATTERNS):
        lanes = tuple(int(lane_by_course[int(c)]) for c in pattern)
        new_pattern_lanes.append(lanes)
        p = float(record['probs'][idx])
        i, j, k = lanes
        q_win[i] += p
        q_trio[i] += p
        q_trio[j] += p
        q_trio[k] += p

    new_log_win = {}
    new_log_trio = {}
    for lane in range(1, 7):
        new_log_win[lane] = math.log(
            max(float(target_win[lane]), EPS) / max(float(q_win[lane]), EPS)
        )
        new_log_trio[lane] = math.log(
            max(float(target_trio[lane]), EPS) / max(float(q_trio[lane]), EPS)
        )

    out = deepcopy(record)
    out['course_by_lane'] = dict(cmap)
    out['pattern_lanes'] = new_pattern_lanes
    out['log_win_ratio'] = new_log_win
    out['log_trio_ratio'] = new_log_trio
    return out


def conditional_second_by_course(probs120, head_course):
    scores = {c: 0.0 for c in range(1, 7) if c != int(head_course)}
    head_mass = 0.0
    for idx, pattern in enumerate(base_outcome.PATTERNS):
        first_c, second_c, _third_c = (int(x) for x in pattern)
        if first_c != int(head_course):
            continue
        p = max(0.0, float(probs120[idx]))
        if second_c in scores:
            scores[second_c] += p
        head_mass += p

    if head_mass <= 0.0:
        return None, 0.0
    return {c: p / head_mass for c, p in scores.items()}, head_mass


def ai_probs_by_boat(record, course_by_lane, head):
    aligned = align_record_to_course_map(record, course_by_lane)
    if aligned is None:
        return None, None, 0.0

    head_course = int(course_by_lane[int(head)])
    probs120 = step3.order_adjusted_probs(aligned, FINAL_DELTA, FINAL_GAMMA)
    dist_course, head_mass = conditional_second_by_course(probs120, head_course)
    if dist_course is None:
        return None, head_course, float(head_mass)

    out = {}
    for lane in range(1, 7):
        if lane == int(head):
            continue
        course = int(course_by_lane[lane])
        if course not in dist_course:
            return None, head_course, float(head_mass)
        out[lane] = float(dist_course[course])

    total = sum(out.values())
    if total <= 0.0:
        return None, head_course, float(head_mass)
    out = {lane: p / total for lane, p in out.items()}
    return out, head_course, float(head_mass)


def current_order(rank_boats, head, kiru):
    return [
        int(b) for b in rank_boats
        if int(b) != int(head) and int(b) not in kiru
    ]


def score_order(scores, eligible):
    return sorted(
        [int(b) for b in eligible],
        key=lambda b: (-float(scores.get(int(b), 0.0)), int(b)),
    )


def build_rows(data, csv_races, exhibition_maps, payouts):
    out = {'P1': [], 'P2': []}
    skip = Counter()
    source = Counter()

    for period in ('P1', 'P2'):
        for record in data['records'][period]:
            code = str(record['race_code'])
            boats = csv_races.get(code)
            if boats is None or set(boats) != set(range(1, 7)):
                skip[f'{period}_csv_missing'] += 1
                continue

            rank_boats, head = final_aite.current_order_and_head(boats)
            if not rank_boats or head is None or int(head) not in range(1, 7):
                skip[f'{period}_current_invalid'] += 1
                continue
            head = int(head)

            if code in exhibition_maps:
                course_map = exhibition_maps[code]
                source[f'{period}_exhibition_complete'] += 1
            else:
                course_map = valid_course_map(record.get('course_by_lane', {}))
                source[f'{period}_history_fallback_complete'] += 1
            if course_map is None:
                skip[f'{period}_course_map_invalid'] += 1
                continue

            ai_probs, head_course, head_mass = ai_probs_by_boat(record, course_map, head)
            if ai_probs is None or head_course is None or set(ai_probs) != (set(range(1, 7)) - {head}):
                skip[f'{period}_ai_invalid'] += 1
                continue

            kiru = {lane for lane, b in boats.items() if int(b.get('kiru', 0)) == 1}
            eligible = [lane for lane in range(1, 7) if lane != head and lane not in kiru]
            if not eligible:
                skip[f'{period}_eligible_empty'] += 1
                continue

            current = current_order(rank_boats, head, kiru)
            ai_order = score_order(ai_probs, eligible)
            if not current or not ai_order:
                skip[f'{period}_order_empty'] += 1
                continue

            k = min(3, len(eligible))
            seconds = {
                'CURRENT_FINAL_SECOND': current[:k],
                'AI_FINAL_ALL_HEAD': ai_order[:k],
            }
            thirds = list(eligible)
            tickets = {
                method: betutil.expand_formation(head, seconds[method], thirds)
                for method in METHODS
            }
            if any(not tickets[m] for m in METHODS):
                skip[f'{period}_formation_empty'] += 1
                continue

            first_lanes = [lane for lane, b in boats.items() if float(b.get('actual_rank', 99)) == 1.0]
            second_lanes = [lane for lane, b in boats.items() if float(b.get('actual_rank', 99)) == 2.0]
            third_lanes = [lane for lane, b in boats.items() if float(b.get('actual_rank', 99)) == 3.0]
            if len(first_lanes) != 1 or len(second_lanes) != 1 or len(third_lanes) != 1:
                skip[f'{period}_actual_top3_invalid'] += 1
                continue

            actual = (int(first_lanes[0]), int(second_lanes[0]), int(third_lanes[0]))
            actual_second = int(actual[1])
            race_day = datetime.strptime(code[:8], '%Y%m%d').date()

            out[period].append({
                'race_code': code,
                'race_date': race_day,
                'head': head,
                'head_course': int(head_course),
                'head_mass': float(head_mass),
                'actual': actual,
                'head_won': actual[0] == head,
                'actual_second': actual_second,
                'actual_second_cut': actual_second in kiru,
                'orders': {
                    'CURRENT_FINAL_SECOND': current,
                    'AI_FINAL_ALL_HEAD': ai_order,
                },
                'seconds': seconds,
                'thirds': thirds,
                'tickets': tickets,
                'ai_probs': ai_probs,
                'payout': float(payouts.get(code, 0.0)),
                'course_source': 'exhibition_complete' if code in exhibition_maps else 'history_fallback_complete',
            })
            skip[f'{period}_ready'] += 1

    return out, skip, source


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


def probability_metrics(rows):
    n = 0
    ll = 0.0
    brier = 0.0
    actual_p = 0.0
    for row in rows:
        if not row['head_won']:
            continue
        actual = int(row['actual_second'])
        probs = row['ai_probs']
        if actual not in probs:
            continue
        p_actual = max(EPS, min(1.0, float(probs[actual])))
        ll += -math.log(p_actual)
        actual_p += float(probs[actual])
        for boat, p in probs.items():
            y = 1.0 if int(boat) == actual else 0.0
            brier += (float(p) - y) ** 2
        n += 1

    if n == 0:
        return None
    return {
        'races': n,
        'logloss': ll / n,
        'brier5': brier / (n * 5.0),
        'actual_prob': actual_p / n,
    }


def compare_pair(rows):
    result = {
        1: {'changed': 0, 'gained': 0, 'lost': 0},
        2: {'changed': 0, 'gained': 0, 'lost': 0},
        3: {'changed': 0, 'gained': 0, 'lost': 0},
        'trifecta': {'changed': 0, 'gained': 0, 'lost': 0},
    }
    for row in rows:
        before = row['orders']['CURRENT_FINAL_SECOND']
        after = row['orders']['AI_FINAL_ALL_HEAD']

        if row['head_won'] and not row['actual_second_cut']:
            actual = int(row['actual_second'])
            for k in (1, 2, 3):
                bset = set(before[:k])
                aset = set(after[:k])
                if bset != aset:
                    result[k]['changed'] += 1
                bh = actual in bset
                ah = actual in aset
                if ah and not bh:
                    result[k]['gained'] += 1
                elif bh and not ah:
                    result[k]['lost'] += 1

        bt = row['tickets']['CURRENT_FINAL_SECOND']
        at = row['tickets']['AI_FINAL_ALL_HEAD']
        if bt != at:
            result['trifecta']['changed'] += 1
        bh = row['actual'] in bt
        ah = row['actual'] in at
        if ah and not bh:
            result['trifecta']['gained'] += 1
        elif bh and not ah:
            result['trifecta']['lost'] += 1
    return result


def subset(rows, mode):
    if mode == 'ALL':
        return list(rows)
    if mode == 'NON1C':
        return [r for r in rows if int(r['head_course']) != 1]
    if isinstance(mode, int):
        return [r for r in rows if int(r['head_course']) == mode]
    raise ValueError(mode)


def print_compare_block(title, rows):
    print(f'\n【{title}】')
    if not rows:
        print('対象なし')
        return None

    cur = evaluate_method(rows, 'CURRENT_FINAL_SECOND')
    ai = evaluate_method(rows, 'AI_FINAL_ALL_HEAD')
    pm = probability_metrics(rows)
    d = compare_pair(rows)

    print('方式                      R数  頭的中R  評価2着R   Top1    Top2    Top3   3連単的中  平均点数    ROI')
    print('-' * 116)
    for name, m in (('CURRENT_FINAL_SECOND', cur), ('AI_FINAL_ALL_HEAD', ai)):
        print(
            f"{name:<25} {m['races']:>5d}  {m['head_wins']:>6d}  {m['pure_second_n']:>7d}  "
            f"{m['top1']*100:>6.2f}%  {m['top2']*100:>6.2f}%  {m['top3']*100:>6.2f}%  "
            f"{m['trifecta_hit_rate']*100:>7.2f}%  {m['avg_points']:>7.2f}点  {m['roi']*100:>7.2f}%"
        )

    print(
        '差 AI-CURRENT          : '
        f"Top1={(ai['top1']-cur['top1'])*100:+.2f}pt / "
        f"Top2={(ai['top2']-cur['top2'])*100:+.2f}pt / "
        f"Top3={(ai['top3']-cur['top3'])*100:+.2f}pt / "
        f"3連単={(ai['trifecta_hit_rate']-cur['trifecta_hit_rate'])*100:+.2f}pt / "
        f"ROI={(ai['roi']-cur['roi'])*100:+.2f}pt"
    )

    print('拾い/失い              :')
    for k in (1, 2, 3):
        x = d[k]
        print(
            f"  Top{k}: 変更={x['changed']}R / 拾い={x['gained']} / 失い={x['lost']} / 純増={x['gained']-x['lost']:+d}"
        )
    x = d['trifecta']
    print(
        f"  3連単: 変更={x['changed']}R / 拾い={x['gained']} / 失い={x['lost']} / 純増={x['gained']-x['lost']:+d}"
    )

    if pm is not None:
        print(
            f"AI確率品質（頭的中R） : N={pm['races']} / LogLoss={pm['logloss']:.6f} / "
            f"Brier5={pm['brier5']:.6f} / 実2着平均P={pm['actual_prob']*100:.3f}%"
        )

    missing = max(cur['payout_missing_hits'], ai['payout_missing_hits'])
    if missing:
        print(f'注意: 的中したが払戻0/欠損={missing}R')

    return {'current': cur, 'ai': ai, 'prob': pm, 'pair': d}


def metric_value(rows, method, key):
    m = evaluate_method(rows, method)
    if key in ('top1', 'top2', 'top3') and m['pure_second_n'] <= 0:
        return None
    return float(m[key])


def percentile(vals, q):
    vals = sorted(vals)
    if not vals:
        return float('nan')
    pos = (len(vals) - 1) * q
    lo = int(math.floor(pos))
    hi = int(math.ceil(pos))
    if lo == hi:
        return float(vals[lo])
    frac = pos - lo
    return float(vals[lo]) * (1.0 - frac) + float(vals[hi]) * frac


def day_block_bootstrap(rows):
    by_day = defaultdict(list)
    for r in rows:
        by_day[r['race_date']].append(r)
    days = sorted(by_day)
    if len(days) < 2:
        return None

    rng = random.Random(BOOTSTRAP_SEED)
    keys = ('top1', 'top2', 'top3', 'trifecta_hit_rate')
    diffs = {k: [] for k in keys}

    for _ in range(BOOTSTRAP_N):
        sample = []
        for _i in range(len(days)):
            d = rng.choice(days)
            sample.extend(by_day[d])
        for key in keys:
            a = metric_value(sample, 'AI_FINAL_ALL_HEAD', key)
            b = metric_value(sample, 'CURRENT_FINAL_SECOND', key)
            if a is not None and b is not None:
                diffs[key].append(a - b)

    return {
        key: (
            percentile(vals, 0.50),
            percentile(vals, 0.025),
            percentile(vals, 0.975),
        )
        for key, vals in diffs.items()
        if vals
    }


def print_bootstrap(title, result):
    print(f'\n【P2 日単位block bootstrap：{title} / AI-CURRENT】')
    if not result:
        print('計算不可')
        return
    print('※ 全指標ともプラスがAI_FINAL_ALL_HEADの改善')
    for key in ('top1', 'top2', 'top3', 'trifecta_hit_rate'):
        if key not in result:
            continue
        med, lo, hi = result[key]
        label = '3連単' if key == 'trifecta_hit_rate' else key
        print(f'{label:<8}: median={med*100:+.3f}pt  95%CI=[{lo*100:+.3f}, {hi*100:+.3f}]pt')


def print_course_summary(rows):
    print('\n【P2 頭コース別サマリ】')
    print('頭C   R数  頭的中R  評価2着R  CURRENT Top1  AI Top1   ΔTop1   CURRENT Top3  AI Top3   ΔTop3   Δ3連単')
    print('-' * 118)
    for course in range(1, 7):
        rr = subset(rows, course)
        if not rr:
            print(f'{course}C      0')
            continue
        cur = evaluate_method(rr, 'CURRENT_FINAL_SECOND')
        ai = evaluate_method(rr, 'AI_FINAL_ALL_HEAD')
        print(
            f"{course}C  {len(rr):>5d}  {cur['head_wins']:>7d}  {cur['pure_second_n']:>8d}  "
            f"{cur['top1']*100:>10.2f}%  {ai['top1']*100:>7.2f}%  {(ai['top1']-cur['top1'])*100:>+7.2f}  "
            f"{cur['top3']*100:>11.2f}%  {ai['top3']*100:>7.2f}%  {(ai['top3']-cur['top3'])*100:>+7.2f}  "
            f"{(ai['trifecta_hit_rate']-cur['trifecta_hit_rate'])*100:>+7.2f}"
        )


def main():
    p1_csv, p2_csv = resolve_csvs(sys.argv)

    print('全頭コース版③ AI_FINALを、展示進入優先で再構築して比較中...')
    data = step3.build_common_records(p1_csv, p2_csv)
    csv_races = final_aite.load_boats(p1_csv, p2_csv)
    exhibition_maps = load_exhibition_course_maps(data['p1_start'], data['p2_end'])
    payouts = betutil.load_trifecta_payouts(data['p1_start'], data['p2_end'])
    rows, skip, source = build_rows(data, csv_races, exhibition_maps, payouts)

    if not rows['P1'] or not rows['P2']:
        raise RuntimeError('全頭コース比較の共通評価レースがありません')

    print('=' * 140)
    print('共通2着確率③ AI_FINAL：全頭コース一般化検証')
    print('=' * 140)
    print(f"P1                  : {data['p1_start']} ～ {data['p1_end']}")
    print(f"P2完全ホールドアウト: {data['p2_start']} ～ {data['p2_end']}")
    print(f'P1 CSV               : {p1_csv}')
    print(f'P2 CSV               : {p2_csv}')
    print('頭                   : 現行本命で固定')
    print('CURRENT              : 現行最終順位から2着候補最大3艇')
    print('AI_FINAL_ALL_HEAD    : P(2着 | 現行本命の今回コース頭)')
    print('今回コース           : 展示進入6艇完全を優先、なければSTEP3履歴mapへfallback')
    print('120通りlane紐付け    : 展示進入が違う場合は今回mapへ再構築')
    print('kiru / 3着候補       : 現行固定')
    print('投資                 : 100円/点')
    print('本番Web変更          : なし')
    print(f"共通評価             : P1={len(rows['P1'])}R / P2={len(rows['P2'])}R")

    print('\n【今回コースsource】')
    for key, value in sorted(source.items()):
        print(f'{key:<40}: {value}')

    print_compare_block('P1 ALL（参考）', rows['P1'])
    print_compare_block('P1 NON1C（参考）', subset(rows['P1'], 'NON1C'))

    p2_all = print_compare_block('P2 ALL（最重要）', rows['P2'])
    p2_1c = print_compare_block('P2 1C頭（既検証範囲の再確認）', subset(rows['P2'], 1))
    p2_non1 = print_compare_block('P2 NON1C頭（今回の本丸）', subset(rows['P2'], 'NON1C'))

    print_course_summary(rows['P2'])

    print_bootstrap('ALL', day_block_bootstrap(rows['P2']))
    print_bootstrap('NON1C', day_block_bootstrap(subset(rows['P2'], 'NON1C')))

    print('\n【共通化スキップ】')
    for key in sorted(skip):
        print(f'{key:<40}: {skip[key]}')

    print('\n【判断方針】')
    print('1. 1C頭は既に③採用候補。今回はNON1Cで現行2着順位より改善するかが本丸。')
    print('2. NON1CのTop1/Top2/Top3と3連単的中を優先し、ROIは参考値として扱う。')
    print('3. 頭コース別は母数が小さくなりやすいため、まずNON1C合算とbootstrapを重視する。')
    print('4. NON1Cでも改善方向が再現すれば、③を全頭共通2着エンジンへ進める。')
    print('5. NON1Cで悪化するなら、1Cだけ③・非1Cは別途設計し、無理に一本化しない。')
    print('6. この段階ではWeb/PredictionLogic/買い目は変更しない。')

    if p2_non1 is not None:
        cur = p2_non1['current']
        ai = p2_non1['ai']
        if (
            ai['top1'] >= cur['top1']
            and ai['top2'] >= cur['top2']
            and ai['top3'] >= cur['top3']
            and ai['trifecta_hit_rate'] >= cur['trifecta_hit_rate']
        ):
            print('暫定判定             : NON1Cでも③が全主要指標で現行以上。全頭共通化候補として前進。')
        else:
            print('暫定判定             : NON1Cは指標が混在。bootstrapと頭コース別を見て慎重に判断。')

    print('=' * 140)
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
