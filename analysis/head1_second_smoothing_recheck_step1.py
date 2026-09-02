#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
イン逃げ時2着率：平滑化方式の再検証 STEP1。

目的
----
現行 BASIC_K10 の
  p = (w + K*p0) / (n + K), K=10
という「コース基礎率へ固定Kで縮約する」考え方を、いったん再点検する。

このSTEP1では、まだ場階層やDirichlet多項モデルまでは入れない。
まず現在の Beta-Binomial 型の枠組み自体に価値があるか、
K=10 が妥当かを同じ母集団・同じ評価指標で確認する。

比較
----
COURSE_ONLY
    選手補正なし。過去の1号艇1着時コース別2着率のみ。

RAW_FALLBACK
    選手×コースの生率 w/n。n=0だけp0へフォールバック。
    実質「平滑化なし」の比較用。

LAPLACE
    Beta(1,1) の非情報的平滑化。

JEFFREYS
    Beta(0.5,0.5) のJeffreys平滑化。

K=10
    現行方式。

K=P1_BEST
    COURSE prior を使う同じ方式のKだけをP1で選択し、P2へ固定。
    K候補は粗い事前固定グリッドのみ。P2を見て調整しない。

評価条件
--------
- 1号艇=1C
- 実際に1号艇が1着
- 実1着/2着が一意
- 6艇・今回コース1～6が復元可能
- 今回コースは展示進入6艇完全なら展示を一括採用
- 過去履歴は result_detail -> exhibition_live -> lane
- 各レースの予測を作ってから履歴更新（リーク防止）

評価
----
P1: 2026-06-15 ～ 2026-07-14
P2: 2026-07-15 ～ 2026-08-14（最重要ホールドアウト）

LogLoss / Brier5 / 実2着平均P / Top1 / Top2 / Top3
さらにP2で、K=10 と P1_BEST_K の差を paired bootstrap 95%CI で確認する。

Usage
-----
python3 analysis/head1_second_smoothing_recheck_step1.py
"""

from __future__ import annotations

import math
import random
from collections import Counter, defaultdict, deque
from dataclasses import dataclass
from datetime import date, timedelta
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parent))
from slit_validate_v2 import connect_db


P1_START = date(2026, 6, 15)
P1_END = date(2026, 7, 14)
P2_START = date(2026, 7, 15)
P2_END = date(2026, 8, 14)
HISTORY_DAYS = 730
EPS = 1e-12
K_GRID = (0.5, 1.0, 2.0, 3.0, 5.0, 7.0, 10.0, 15.0, 20.0, 30.0, 50.0, 80.0, 120.0)
BOOTSTRAP_N = 3000
BOOTSTRAP_SEED = 428


@dataclass
class Candidate:
    lane: int
    course: int
    y: int
    p0: float
    n: int
    w: int


@dataclass
class RaceSnapshot:
    race_code: str
    race_date: date
    candidates: list[Candidate]


def as_int(v):
    if v is None or v == '':
        return None
    try:
        return int(v)
    except (TypeError, ValueError):
        return None


def valid_course(v):
    c = as_int(v)
    return c if c is not None and 1 <= c <= 6 else None


def actual_course(result_course, exhibition_course, lane):
    rc = valid_course(result_course)
    if rc is not None:
        return rc
    ec = valid_course(exhibition_course)
    if ec is not None:
        return ec
    return valid_course(lane)


def complete_map(prepared, key):
    if len(prepared) != 6:
        return None
    out = {}
    vals = []
    for r in prepared:
        lane = valid_course(r['lane'])
        c = valid_course(r.get(key))
        if lane is None or c is None:
            return None
        out[lane] = c
        vals.append(c)
    if sorted(vals) != [1, 2, 3, 4, 5, 6]:
        return None
    return out


def period_name(d):
    if P1_START <= d <= P1_END:
        return 'P1'
    if P2_START <= d <= P2_END:
        return 'P2'
    return None


def normalize(scores):
    vals = [max(0.0, float(x)) for x in scores]
    total = sum(vals)
    if total <= 0:
        return [1.0 / len(vals)] * len(vals)
    return [x / total for x in vals]


def prior_course_rate(second_course_hist, head1_hist_n, course):
    if head1_hist_n > 0:
        return float(second_course_hist[course]) / float(head1_hist_n)
    return 1.0 / 6.0


def player_counts(history, target_course):
    n = 0
    w = 0
    for h in history:
        if not h['eligible_head1']:
            continue
        if int(h['course']) != int(target_course):
            continue
        n += 1
        w += int(h['second'])
    return n, w


def load_snapshots():
    history_start = P1_START - timedelta(days=HISTORY_DAYS)
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
            prepared.append({
                'lane': valid_course(lane),
                'player_id': str(player_id or '').strip(),
                'rank': as_int(rank),
                'history_course': actual_course(result_course, exhibition_course, lane),
                'exhibition_course': valid_course(exhibition_course),
            })

        six_rows = len(prepared) == 6
        lanes = [r['lane'] for r in prepared]
        lanes_ok = six_rows and sorted(c for c in lanes if c is not None) == [1, 2, 3, 4, 5, 6]

        winners = [r for r in prepared if r['rank'] == 1]
        seconds = [r for r in prepared if r['rank'] == 2]
        top2_ok = len(winners) == 1 and len(seconds) == 1
        head1_win = top2_ok and winners[0]['lane'] == 1

        history_map = complete_map(prepared, 'history_course')
        exhibition_map = complete_map(prepared, 'exhibition_course')
        if exhibition_map is not None:
            target_map = exhibition_map
            source = 'exhibition_complete'
        elif history_map is not None:
            target_map = history_map
            source = 'history_fallback_complete'
        else:
            target_map = None
            source = 'target_incomplete'

        pname = period_name(race_date)
        if pname is not None:
            if not lanes_ok:
                skipped[f'{pname}_entry_not_6'] += 1
            elif not top2_ok:
                skipped[f'{pname}_top2_not_unique'] += 1
            elif not head1_win:
                skipped[f'{pname}_not_head1_win'] += 1
            elif target_map is None:
                skipped[f'{pname}_target_course_incomplete'] += 1
            elif int(target_map.get(1, 0)) != 1:
                skipped[f'{pname}_boat1_not_course1'] += 1
            else:
                target_source[f'{pname}_{source}'] += 1
                second_lane = int(seconds[0]['lane'])
                candidates = []
                for r in prepared:
                    lane = int(r['lane'])
                    if lane == 1:
                        continue
                    course = int(target_map[lane])
                    p0 = prior_course_rate(second_course_hist, head1_hist_n, course)
                    n, w = player_counts(player_hist[r['player_id']], course)
                    candidates.append(Candidate(
                        lane=lane,
                        course=course,
                        y=1 if lane == second_lane else 0,
                        p0=p0,
                        n=n,
                        w=w,
                    ))
                if len(candidates) == 5 and sum(c.y for c in candidates) == 1:
                    candidates.sort(key=lambda x: x.lane)
                    snapshots[pname].append(RaceSnapshot(str(race_code), race_date, candidates))
                else:
                    skipped[f'{pname}_snapshot_invalid'] += 1

        # ---- 現在レース終了後の履歴更新。予測には使わない ----
        if lanes_ok and top2_ok and head1_win and history_map is not None:
            second_course = history_map.get(int(seconds[0]['lane']))
            if second_course is not None:
                head1_hist_n += 1
                second_course_hist[int(second_course)] += 1

        eligible_head1 = lanes_ok and top2_ok and head1_win
        for r in prepared:
            pid = r['player_id']
            c = valid_course(r['history_course'])
            if not pid or c is None:
                continue
            player_hist[pid].append({
                'course': c,
                'eligible_head1': eligible_head1,
                'second': 1 if eligible_head1 and r['rank'] == 2 else 0,
            })

    with connect_db() as conn:
        cur = conn.cursor(name='head1_second_smoothing_recheck_stream')
        cur.itersize = 10000
        cur.execute(sql, (history_start.isoformat(), P2_END.isoformat()))
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
        'history_start': history_start,
        'history_head1_n': head1_hist_n,
        'target_source': dict(target_source),
        'skipped': dict(skipped),
    }


def method_probs(race, method, k=None):
    scores = []
    for c in race.candidates:
        if method == 'COURSE_ONLY':
            s = c.p0
        elif method == 'RAW_FALLBACK':
            s = (c.w / c.n) if c.n > 0 else c.p0
        elif method == 'LAPLACE':
            s = (c.w + 1.0) / (c.n + 2.0)
        elif method == 'JEFFREYS':
            s = (c.w + 0.5) / (c.n + 1.0)
        elif method == 'K':
            kk = float(k)
            s = (c.w + kk * c.p0) / (c.n + kk)
        else:
            raise ValueError(method)
        scores.append(s)
    return normalize(scores)


def per_race_metrics(race, method, k=None):
    probs = method_probs(race, method, k)
    actual_idx = next((i for i, c in enumerate(race.candidates) if c.y == 1), None)
    if actual_idx is None:
        return None
    p_actual = max(float(probs[actual_idx]), EPS)
    brier = sum((float(p) - float(c.y)) ** 2 for p, c in zip(probs, race.candidates)) / 5.0
    ranked = sorted(
        ((float(p), int(c.lane), int(c.y)) for p, c in zip(probs, race.candidates)),
        key=lambda x: (-x[0], x[1]),
    )
    actual_rank = next((i + 1 for i, row in enumerate(ranked) if row[2] == 1), 99)
    return {
        'logloss': -math.log(p_actual),
        'brier': brier,
        'actual_p': float(probs[actual_idx]),
        'top1': 1.0 if actual_rank <= 1 else 0.0,
        'top2': 1.0 if actual_rank <= 2 else 0.0,
        'top3': 1.0 if actual_rank <= 3 else 0.0,
    }


def evaluate(races, method, k=None):
    rows = [per_race_metrics(r, method, k) for r in races]
    rows = [r for r in rows if r is not None]
    n = len(rows)
    if n == 0:
        return None
    return {
        'n': n,
        'logloss': sum(r['logloss'] for r in rows) / n,
        'brier': sum(r['brier'] for r in rows) / n,
        'actual_p': sum(r['actual_p'] for r in rows) / n,
        'top1': sum(r['top1'] for r in rows) / n,
        'top2': sum(r['top2'] for r in rows) / n,
        'top3': sum(r['top3'] for r in rows) / n,
        'rows': rows,
    }


def tune_k_p1(races):
    table = []
    for k in K_GRID:
        r = evaluate(races, 'K', k)
        table.append((float(k), r))
    # 主目的は確率品質。LogLoss -> Brier -> Top2 の順で決定。
    best = min(table, key=lambda x: (x[1]['logloss'], x[1]['brier'], -x[1]['top2'], x[0]))
    return best, table


def percentile(vals, q):
    if not vals:
        return float('nan')
    xs = sorted(vals)
    pos = (len(xs) - 1) * q
    lo = int(math.floor(pos))
    hi = int(math.ceil(pos))
    if lo == hi:
        return xs[lo]
    frac = pos - lo
    return xs[lo] * (1.0 - frac) + xs[hi] * frac


def paired_bootstrap(races, k_new, n_boot=BOOTSTRAP_N):
    base_rows = [per_race_metrics(r, 'K', 10.0) for r in races]
    new_rows = [per_race_metrics(r, 'K', k_new) for r in races]
    pairs = [(a, b) for a, b in zip(base_rows, new_rows) if a is not None and b is not None]
    n = len(pairs)
    if n == 0:
        return {}

    keys = ('logloss', 'brier', 'top1', 'top2', 'top3')
    diffs = {k: [] for k in keys}
    rng = random.Random(BOOTSTRAP_SEED)

    for _ in range(n_boot):
        sums = {k: 0.0 for k in keys}
        for _j in range(n):
            a, b = pairs[rng.randrange(n)]
            for key in keys:
                sums[key] += float(b[key]) - float(a[key])
        for key in keys:
            diffs[key].append(sums[key] / n)

    out = {}
    for key in keys:
        vals = diffs[key]
        out[key] = {
            'lo': percentile(vals, 0.025),
            'hi': percentile(vals, 0.975),
            'median': percentile(vals, 0.5),
        }
    return out


def print_result_row(label, r):
    print(
        f"{label:<18} {r['n']:>5d}  {r['logloss']:.6f}  {r['brier']:.6f}  "
        f"{r['actual_p']*100:>8.3f}%  {r['top1']*100:>6.2f}%  "
        f"{r['top2']*100:>6.2f}%  {r['top3']*100:>6.2f}%"
    )


def print_period(title, races, best_k):
    print(f'\n【{title}】')
    print('方式                 R数   LogLoss   Brier5   実2着平均P   Top1    Top2    Top3')
    print('-' * 96)
    methods = [
        ('COURSE_ONLY', 'COURSE_ONLY', None),
        ('RAW_FALLBACK', 'RAW_FALLBACK', None),
        ('LAPLACE', 'LAPLACE', None),
        ('JEFFREYS', 'JEFFREYS', None),
        ('FIXED_K10', 'K', 10.0),
        (f'P1_BEST_K={best_k:g}', 'K', best_k),
    ]
    results = {}
    for label, method, k in methods:
        r = evaluate(races, method, k)
        results[label] = r
        print_result_row(label, r)
    return results


def main():
    print('未来情報なしでイン逃げ時2着率の平滑化を再検証中...')
    snapshots, meta = load_snapshots()
    p1 = snapshots['P1']
    p2 = snapshots['P2']
    if not p1 or not p2:
        raise RuntimeError('P1/P2の評価レースがありません')

    (best_k, best_p1), k_table = tune_k_p1(p1)

    print('=' * 124)
    print('イン逃げ時2着率 平滑化方式再検証 STEP1：固定K方式そのものを再点検')
    print('=' * 124)
    print(f'P1                  : {P1_START} ～ {P1_END}')
    print(f'P2完全ホールドアウト: {P2_START} ～ {P2_END}')
    print('評価対象             : 1号艇=1C かつ実際に1号艇1着')
    print('現在方式             : COURSE prior + 選手×コース / K=10')
    print('今回の目的           : K=10を含む現在のBeta-Binomial型縮約に価値があるか再確認')
    print('今回まだやらないこと : 場階層 / 真の経験ベイズhyperparameter推定 / Dirichlet多項')
    print(f'評価R                : P1={len(p1)}R / P2={len(p2)}R')
    print(f'P1で選んだK          : {best_k:g}（P2では固定）')

    print('\n【P1 Kグリッド：LogLoss順】')
    print('順位      K    LogLoss   Brier5   Top1    Top2    Top3')
    print('-' * 72)
    for rank, (k, r) in enumerate(sorted(k_table, key=lambda x: (x[1]['logloss'], x[1]['brier']))[:10], 1):
        print(
            f'{rank:>2d}   {k:>6g}  {r["logloss"]:.6f}  {r["brier"]:.6f}  '
            f'{r["top1"]*100:>6.2f}%  {r["top2"]*100:>6.2f}%  {r["top3"]*100:>6.2f}%'
        )

    print_period('P1 参考', p1, best_k)
    p2_results = print_period('P2 ホールドアウト（最重要）', p2, best_k)

    print('\n【P2 paired bootstrap：P1_BEST_K - FIXED_K10 の95%CI】')
    print('※ LogLoss/Brierはマイナスが改善、Topはプラスが改善')
    ci = paired_bootstrap(p2, best_k)
    labels = {
        'logloss': 'LogLoss差',
        'brier': 'Brier差',
        'top1': 'Top1差',
        'top2': 'Top2差',
        'top3': 'Top3差',
    }
    for key in ('logloss', 'brier', 'top1', 'top2', 'top3'):
        x = ci[key]
        scale = 100.0 if key.startswith('top') else 1.0
        suffix = 'pt' if key.startswith('top') else ''
        print(
            f"{labels[key]:<12}: median={x['median']*scale:+.4f}{suffix}  "
            f"95%CI=[{x['lo']*scale:+.4f}, {x['hi']*scale:+.4f}]{suffix}"
        )

    fixed = p2_results['FIXED_K10']
    best = p2_results[f'P1_BEST_K={best_k:g}']
    print('\n【STEP1判断メモ】')
    if best['logloss'] < fixed['logloss'] and best['brier'] < fixed['brier']:
        print('・P1選択KはP2でもK10より確率品質が改善。固定K=10は再検討余地あり。')
    else:
        print('・P1選択KはP2でK10を一貫して上回らない。K=10だけの問題とは言い切れない。')

    course = p2_results['COURSE_ONLY']
    if fixed['logloss'] < course['logloss'] and fixed['brier'] < course['brier']:
        print('・K10はCOURSE_ONLYよりP2で確率品質が良く、選手×コース縮約自体には価値あり。')
    else:
        print('・K10がCOURSE_ONLYをP2で一貫して上回らず、選手×コース縮約の構造自体を要再検討。')

    raw = p2_results['RAW_FALLBACK']
    if raw['logloss'] > fixed['logloss']:
        print('・生率よりK10のLogLossが良く、母数不足への平滑化は必要と考えやすい。')
    else:
        print('・生率がK10以上。現在の縮約が強すぎる可能性も次工程で確認する。')

    print('\n【次工程】')
    print('STEP2で「固定Kを何にするか」ではなく、方式を変える。')
    print('候補: データから縮約強度を推定する経験ベイズ / 場×コースを挟む階層型。')
    print('STEP2の勝者を固定した③ AI_FINALと組み合わせ、③単独を超えるか確認する。')

    print('\n【今回コースsource】')
    for key, value in sorted(meta['target_source'].items()):
        print(f'{key:<36}: {value}')

    print('\n【除外内訳】')
    for key, value in sorted(meta['skipped'].items()):
        print(f'{key:<36}: {value}')

    print('=' * 124)
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
