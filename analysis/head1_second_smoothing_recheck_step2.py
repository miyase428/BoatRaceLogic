#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
イン逃げ時2着率 平滑化方式再検証 STEP2。

STEP1では、現行の
    p = (w + K*p0) / (n + K), K=10
という固定K縮約が、生率やコース基礎率だけより安定していることを確認した。
一方、P1で選んだK=15はP2でK=10を一貫して上回らなかった。

STEP2では「Kを何にするか」ではなく、縮約方式そのものを変えて比較する。

比較方式
--------
COURSE_ONLY
    全場コース基礎率のみ。

FIXED_K10
    現行方式。全場コース基礎率 + 選手×コース、K=10固定。

EB_GLOBAL
    全場コース基礎率 + 選手×コース。
    Kは各評価期間の開始前データだけから Beta-Binomial 周辺尤度最大化で推定。
    P1用K: 2026-06-14まで、P2用K: 2026-07-14まで。

VENUE_EB
    全場コース分布を親分布とし、場×コース分布をDirichlet-Multinomialで縮約。
    場階層の濃度Kvは各評価期間開始前だけから周辺尤度最大化で推定。
    選手補正はまだ入れない。

HIER_EB
    全場コース -> 場×コース -> 選手×コース の2段階縮約。
    場階層Kv、選手階層Kpはいずれも期間開始前データだけで推定。

重要
----
- ハイパーパラメータ推定に評価期間自身の結果を使わない。
- 各レースは予測を作ってから履歴更新する。
- P2は7/15開始時点で7/14までの履歴だけからKp/Kvを推定し、その後固定。
- 今回は③ AI_FINALとはまだ混ぜない。平滑化側だけを比較する。

評価
----
P1: 2026-06-15 ～ 2026-07-14
P2: 2026-07-15 ～ 2026-08-14（最重要）
LogLoss / Brier5 / 実2着平均P / Top1 / Top2 / Top3
P2ではEB_GLOBAL/HIER_EBとFIXED_K10のpaired bootstrap 95%CIも出す。

Usage
-----
python3 analysis/head1_second_smoothing_recheck_step2.py
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
BOOTSTRAP_N = 3000
BOOTSTRAP_SEED = 428


@dataclass
class Candidate:
    lane: int
    course: int
    y: int
    p_global: float
    venue_n: int
    venue_w: int
    player_n: int
    player_w: int


@dataclass
class RaceSnapshot:
    race_code: str
    race_date: date
    place_code: str
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


def safe_prob(p):
    return min(1.0 - 1e-8, max(1e-8, float(p)))


def global_prior(second_course_hist, head1_hist_n):
    if head1_hist_n <= 0:
        return {c: 1.0 / 6.0 for c in range(1, 7)}
    raw = {c: float(second_course_hist[c]) / float(head1_hist_n) for c in range(1, 7)}
    # 数値安定用に極小値を入れて再正規化。
    vals = [max(1e-8, raw[c]) for c in range(1, 7)]
    total = sum(vals)
    return {c: vals[c - 1] / total for c in range(1, 7)}


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


def beta_binom_ll(k, groups):
    if k <= 0:
        return -float('inf')
    ll = 0.0
    used = 0
    for n, w, p0 in groups:
        if n <= 0:
            continue
        p = safe_prob(p0)
        a = k * p
        b = k * (1.0 - p)
        ll += (
            math.lgamma(w + a)
            + math.lgamma(n - w + b)
            - math.lgamma(n + a + b)
            - math.lgamma(a)
            - math.lgamma(b)
            + math.lgamma(a + b)
        )
        used += 1
    return ll if used else -float('inf')


def dirichlet_multinom_ll(k, venue_counts, p_global):
    if k <= 0:
        return -float('inf')
    p = [max(1e-8, float(p_global[c])) for c in range(1, 7)]
    ps = sum(p)
    p = [x / ps for x in p]
    ll = 0.0
    used = 0
    for counts in venue_counts.values():
        vec = [int(counts[c]) for c in range(1, 7)]
        n = sum(vec)
        if n <= 0:
            continue
        ll += math.lgamma(k) - math.lgamma(k + n)
        for cnt, pi in zip(vec, p):
            alpha = k * pi
            ll += math.lgamma(alpha + cnt) - math.lgamma(alpha)
        used += 1
    return ll if used else -float('inf')


def positive_log_grid(lo=0.25, hi=10000.0, ratio=1.35):
    out = []
    x = lo
    while x <= hi:
        out.append(float(x))
        x *= ratio
    if out[-1] < hi:
        out.append(float(hi))
    return out


def maximize_1d(score_fn):
    grid = positive_log_grid()
    scored = [(x, score_fn(x)) for x in grid]
    best_i = max(range(len(scored)), key=lambda i: scored[i][1])
    best_x, best_score = scored[best_i]

    lo = scored[max(0, best_i - 1)][0]
    hi = scored[min(len(scored) - 1, best_i + 1)][0]
    if hi > lo:
        llo = math.log(lo)
        lhi = math.log(hi)
        for i in range(1, 40):
            x = math.exp(llo + (lhi - llo) * i / 40.0)
            s = score_fn(x)
            if s > best_score:
                best_x, best_score = x, s
    return float(best_x), float(best_score)


def estimate_hyperparams(player_hist, second_course_hist, head1_hist_n, venue_second):
    p0 = global_prior(second_course_hist, head1_hist_n)

    groups = []
    for hist in player_hist.values():
        for course in range(1, 7):
            n, w = player_counts(hist, course)
            if n > 0:
                groups.append((n, w, p0[course]))

    kp, kp_ll = maximize_1d(lambda k: beta_binom_ll(k, groups))
    kv, kv_ll = maximize_1d(lambda k: dirichlet_multinom_ll(k, venue_second, p0))
    return {
        'kp': kp,
        'kv': kv,
        'kp_ll': kp_ll,
        'kv_ll': kv_ll,
        'player_groups': len(groups),
        'venues': sum(1 for counts in venue_second.values() if sum(counts.values()) > 0),
        'history_head1_n': int(head1_hist_n),
    }


def load_snapshots():
    history_start = P1_START - timedelta(days=HISTORY_DAYS)
    player_hist = defaultdict(lambda: deque(maxlen=100))
    second_course_hist = Counter()
    head1_hist_n = 0
    venue_second = defaultdict(Counter)
    venue_n = Counter()

    snapshots = {'P1': [], 'P2': []}
    hyper = {}
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

    def ensure_hyper_before(race_date):
        if race_date >= P1_START and 'P1' not in hyper:
            hyper['P1'] = estimate_hyperparams(
                player_hist, second_course_hist, head1_hist_n, venue_second
            )
        if race_date >= P2_START and 'P2' not in hyper:
            hyper['P2'] = estimate_hyperparams(
                player_hist, second_course_hist, head1_hist_n, venue_second
            )

    def process_race(race_date, race_code, raw_rows):
        nonlocal head1_hist_n
        if not raw_rows:
            return

        ensure_hyper_before(race_date)
        place_code = str(race_code)[8:11].upper() if len(str(race_code)) >= 11 else 'UNK'

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
                p0 = global_prior(second_course_hist, head1_hist_n)
                second_lane = int(seconds[0]['lane'])
                candidates = []
                for r in prepared:
                    lane = int(r['lane'])
                    if lane == 1:
                        continue
                    course = int(target_map[lane])
                    pn, pw = player_counts(player_hist[r['player_id']], course)
                    candidates.append(Candidate(
                        lane=lane,
                        course=course,
                        y=1 if lane == second_lane else 0,
                        p_global=float(p0[course]),
                        venue_n=int(venue_n[place_code]),
                        venue_w=int(venue_second[place_code][course]),
                        player_n=int(pn),
                        player_w=int(pw),
                    ))
                if len(candidates) == 5 and sum(c.y for c in candidates) == 1:
                    candidates.sort(key=lambda x: x.lane)
                    snapshots[pname].append(RaceSnapshot(
                        str(race_code), race_date, place_code, candidates
                    ))
                else:
                    skipped[f'{pname}_snapshot_invalid'] += 1

        # ---- 現在レース終了後の履歴更新。予測には使わない ----
        if lanes_ok and top2_ok and head1_win and history_map is not None:
            second_course = history_map.get(int(seconds[0]['lane']))
            if second_course is not None:
                c2 = int(second_course)
                head1_hist_n += 1
                second_course_hist[c2] += 1
                venue_n[place_code] += 1
                venue_second[place_code][c2] += 1

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
        cur = conn.cursor(name='head1_second_smoothing_step2_stream')
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

    if 'P1' not in hyper:
        hyper['P1'] = estimate_hyperparams(player_hist, second_course_hist, head1_hist_n, venue_second)
    if 'P2' not in hyper:
        hyper['P2'] = estimate_hyperparams(player_hist, second_course_hist, head1_hist_n, venue_second)

    return snapshots, hyper, {
        'history_start': history_start,
        'target_source': dict(target_source),
        'skipped': dict(skipped),
    }


def venue_prior(c, kv):
    return (float(c.venue_w) + float(kv) * float(c.p_global)) / (float(c.venue_n) + float(kv))


def method_probs(race, method, hp):
    kp = float(hp['kp'])
    kv = float(hp['kv'])
    scores = []
    for c in race.candidates:
        if method == 'COURSE_ONLY':
            s = c.p_global
        elif method == 'FIXED_K10':
            s = (c.player_w + 10.0 * c.p_global) / (c.player_n + 10.0)
        elif method == 'EB_GLOBAL':
            s = (c.player_w + kp * c.p_global) / (c.player_n + kp)
        elif method == 'VENUE_EB':
            s = venue_prior(c, kv)
        elif method == 'HIER_EB':
            pv = venue_prior(c, kv)
            s = (c.player_w + kp * pv) / (c.player_n + kp)
        else:
            raise ValueError(method)
        scores.append(float(s))
    return normalize(scores)


def per_race_metrics(race, method, hp):
    probs = method_probs(race, method, hp)
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


def evaluate(races, method, hp):
    rows = [per_race_metrics(r, method, hp) for r in races]
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


def percentile(values, q):
    if not values:
        return 0.0
    xs = sorted(values)
    pos = (len(xs) - 1) * q
    lo = int(math.floor(pos))
    hi = int(math.ceil(pos))
    if lo == hi:
        return xs[lo]
    w = pos - lo
    return xs[lo] * (1.0 - w) + xs[hi] * w


def bootstrap_diff(base_rows, new_rows):
    if len(base_rows) != len(new_rows) or not base_rows:
        return {}
    rng = random.Random(BOOTSTRAP_SEED)
    n = len(base_rows)
    keys = ('logloss', 'brier', 'top1', 'top2', 'top3')
    dist = {k: [] for k in keys}
    for _ in range(BOOTSTRAP_N):
        idxs = [rng.randrange(n) for _ in range(n)]
        for key in keys:
            d = sum(new_rows[i][key] - base_rows[i][key] for i in idxs) / n
            dist[key].append(d)
    return {
        key: (
            percentile(vals, 0.50),
            percentile(vals, 0.025),
            percentile(vals, 0.975),
        )
        for key, vals in dist.items()
    }


def print_period(title, races, hp):
    methods = ('COURSE_ONLY', 'FIXED_K10', 'EB_GLOBAL', 'VENUE_EB', 'HIER_EB')
    results = {m: evaluate(races, m, hp) for m in methods}
    print(f'\n【{title}】')
    print('方式              R数   LogLoss   Brier5   実2着平均P   Top1    Top2    Top3')
    print('-' * 96)
    for m in methods:
        r = results[m]
        print(
            f"{m:<16} {r['n']:>5d}  {r['logloss']:.6f}  {r['brier']:.6f}  "
            f"{r['actual_p']*100:>8.3f}%  {r['top1']*100:>6.2f}%  "
            f"{r['top2']*100:>6.2f}%  {r['top3']*100:>6.2f}%"
        )
    return results


def print_bootstrap(label, ci):
    print(f'\n{label}')
    print('※ LogLoss/Brierはマイナスが改善、Topはプラスが改善')
    for key, name, scale in (
        ('logloss', 'LogLoss差', 1.0),
        ('brier', 'Brier差  ', 1.0),
        ('top1', 'Top1差   ', 100.0),
        ('top2', 'Top2差   ', 100.0),
        ('top3', 'Top3差   ', 100.0),
    ):
        med, lo, hi = ci[key]
        unit = 'pt' if key.startswith('top') else ''
        print(f'{name}: median={med*scale:+.4f}{unit}  95%CI=[{lo*scale:+.4f}, {hi*scale:+.4f}]{unit}')


def main():
    print('期間開始前だけで経験ベイズ濃度を推定し、階層平滑化を再検証中...')
    snapshots, hyper, meta = load_snapshots()

    print('=' * 124)
    print('イン逃げ時2着率 平滑化方式再検証 STEP2：経験ベイズ推定 / 場階層')
    print('=' * 124)
    print(f'P1                  : {P1_START} ～ {P1_END}')
    print(f'P2完全ホールドアウト: {P2_START} ～ {P2_END}')
    print('評価対象             : 1号艇=1C かつ実際に1号艇1着')
    print('現行基準             : FIXED_K10')
    print('EB_GLOBAL            : KpをBeta-Binomial周辺尤度で期間開始前に推定')
    print('VENUE_EB             : KvをDirichlet-Multinomial周辺尤度で期間開始前に推定')
    print('HIER_EB              : 全場 -> 場 -> 選手 の2段階縮約')
    print('③ AI_FINAL           : 今回は固定したまま未使用。STEP2勝者決定後に比較')
    print(f"評価R                : P1={len(snapshots['P1'])}R / P2={len(snapshots['P2'])}R")

    print('\n【期間開始前に推定したハイパーパラメータ】')
    print('期間   Kp(選手)   Kv(場)     player groups   venues   prior head1 N')
    print('-' * 82)
    for p in ('P1', 'P2'):
        h = hyper[p]
        print(
            f"{p:<4}  {h['kp']:>9.3f}  {h['kv']:>9.3f}  "
            f"{h['player_groups']:>13d}  {h['venues']:>7d}  {h['history_head1_n']:>13d}"
        )
    print('※ P2のKp/Kvは7/15開始前、つまり7/14までだけで推定。P2結果は不使用。')

    p1 = print_period('P1 参考', snapshots['P1'], hyper['P1'])
    p2 = print_period('P2 ホールドアウト（最重要）', snapshots['P2'], hyper['P2'])

    ci_eb = bootstrap_diff(p2['FIXED_K10']['rows'], p2['EB_GLOBAL']['rows'])
    ci_hier = bootstrap_diff(p2['FIXED_K10']['rows'], p2['HIER_EB']['rows'])
    print_bootstrap('【P2 paired bootstrap：EB_GLOBAL - FIXED_K10】', ci_eb)
    print_bootstrap('【P2 paired bootstrap：HIER_EB - FIXED_K10】', ci_hier)

    print('\n【STEP2判断メモ】')
    print('・まずP2のLogLoss/Brierを主指標に、固定K10よりEB_GLOBAL/HIER_EBが改善するかを見る。')
    print('・Top1～3は順位品質。確率品質と同方向なら採用根拠が強い。')
    print('・95%CIが0をまたぐ場合は「明確な差」とは扱わない。')
    print('・場階層が効かなければ、複雑化せずGLOBAL系を優先する。')
    print('・STEP2勝者だけを、次工程で固定した③ AI_FINALと組み合わせて③単独と比較する。')

    print('\n【今回コースsource】')
    for key, value in sorted(meta['target_source'].items()):
        print(f'{key:<40}: {value}')

    print('\n【除外内訳】')
    for key, value in sorted(meta['skipped'].items()):
        print(f'{key:<40}: {value}')

    print('=' * 124)
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
