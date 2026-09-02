#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
イン逃げ時2着予想 4方式比較。

目的
----
「1号艇が1Cにいて実際に1着」の共通母集団で、以下4方式を同じ5クラス確率として比較する。

1) BASIC_K10
   現行「1号艇1着時の2着率」。
   過去の1号艇1着時2着コース分布 p0 + 選手×今回コース直前100走をK=10で平滑化。

2) AI_FINAL
   現行「イン1着時 2連単」。
   STEP3最終120通り出目確率を P(2着 | 1C頭) へ集約。

3) DIRECT_EVAL
   BASIC_K10 を土台に、現在レースの一次評価・現行最終評価を2着専用補正として直接加える。
   p ∝ BASIC_K10 × exp(alpha * z(一次) + beta * z(final3))
   alpha / beta はP1だけで選択し、P2では固定。

4) BASIC_AI_BLEND
   BASIC_K10 と AI_FINAL を幾何平均で統合。
   p ∝ BASIC_K10^(1-w) × AI_FINAL^w
   wはP1だけで選択し、P2では固定。

重要
----
- 評価対象は「実際の1着コース=1C」かつ「その1C艇=1号艇」のレースのみ。
  進入変更で1号艇が1Cでないレースは除外し、①と③の条件を揃える。
- 未来情報は使わない。
- DIRECT_EVAL / BLEND のパラメータはP1だけで決定し、P2は完全ホールドアウト。
- ここでは確率・順位だけ比較し、2連単の買い方/払戻はまだ変更しない。
- 本番Web / PredictionLogic は変更しない。

評価指標
--------
- 5クラス LogLoss
- Brier5
- 実2着への平均付与確率
- Top1 / Top2 / Top3 捕捉率
- 実2着の平均順位
- P2でTop2が既存方式から何R拾い/失いになったか

Usage
-----
python3 analysis/head1_second_probability_4way_compare.py \
  analysis/output/final_prediction_boats_20260615_20260714_OLD.csv \
  analysis/output/final_prediction_boats_20260715_20260814_OLD.csv
"""

from __future__ import annotations

import math
import statistics
import sys
from collections import Counter
from dataclasses import dataclass, field
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import base_trifecta_probability_compare as base_outcome
import final_prediction_ai_opponent_compare as final_aite
import head1_exacta_probability_validate as head1_exacta
import second_place_head1_k_compare as basic2
import trifecta_probability_order_compare as step3


K_PC = 10.0
FINAL_DELTA = 0.25
FINAL_GAMMA = 0.25
EPS = 1e-15

# P1だけで選ぶ。符号は「評価が高いほど2着寄り」に限定し、過学習を抑える。
DIRECT_GRID = (0.0, 0.25, 0.50, 0.75, 1.00, 1.25, 1.50)
BLEND_GRID = tuple(i / 10.0 for i in range(0, 11))


@dataclass
class Metrics:
    races: int = 0
    logloss_sum: float = 0.0
    brier_sum: float = 0.0
    actual_prob_sum: float = 0.0
    top_hits: dict = field(default_factory=lambda: {1: 0, 2: 0, 3: 0})
    ranks: list = field(default_factory=list)
    sum_error_max: float = 0.0

    def add(self, probs_by_lane: dict[int, float], actual_second: int) -> None:
        lanes = sorted(probs_by_lane)
        if len(lanes) != 5 or actual_second not in probs_by_lane:
            return

        probs = [float(probs_by_lane[l]) for l in lanes]
        total = sum(probs)
        self.sum_error_max = max(self.sum_error_max, abs(total - 1.0))

        p_actual = min(max(float(probs_by_lane[actual_second]), EPS), 1.0)
        self.logloss_sum += -math.log(p_actual)
        self.actual_prob_sum += float(probs_by_lane[actual_second])

        brier = 0.0
        for lane in lanes:
            y = 1.0 if lane == actual_second else 0.0
            p = float(probs_by_lane[lane])
            brier += (p - y) ** 2
        self.brier_sum += brier

        ordered = sorted(lanes, key=lambda l: (-float(probs_by_lane[l]), l))
        rank = ordered.index(actual_second) + 1
        self.ranks.append(rank)
        for n in (1, 2, 3):
            if rank <= n:
                self.top_hits[n] += 1

        self.races += 1

    def summary(self) -> dict | None:
        if self.races <= 0:
            return None
        return {
            'races': self.races,
            'logloss': self.logloss_sum / self.races,
            'brier': self.brier_sum / self.races,
            'actual_prob': self.actual_prob_sum / self.races,
            'top1': self.top_hits[1] / self.races,
            'top2': self.top_hits[2] / self.races,
            'top3': self.top_hits[3] / self.races,
            'mean_rank': sum(self.ranks) / len(self.ranks),
            'median_rank': statistics.median(self.ranks),
            'max_sum_error': self.sum_error_max,
        }


def normalize(scores: dict[int, float]) -> dict[int, float]:
    if not scores:
        return {}
    clipped = {int(k): max(float(v), EPS) for k, v in scores.items()}
    total = sum(clipped.values())
    if total <= 0:
        u = 1.0 / len(clipped)
        return {k: u for k in clipped}
    return {k: v / total for k, v in clipped.items()}


def zscores(values: dict[int, float]) -> dict[int, float]:
    if not values:
        return {}
    xs = [float(v) for v in values.values()]
    mean = sum(xs) / len(xs)
    var = sum((x - mean) ** 2 for x in xs) / len(xs)
    sd = math.sqrt(var)
    if sd <= 1e-12:
        return {k: 0.0 for k in values}
    return {k: (float(v) - mean) / sd for k, v in values.items()}


def basic_probs(snapshot) -> dict[int, float]:
    scores = {}
    for c in snapshot.candidates:
        p = (float(c.w_pc) + K_PC * float(c.p0)) / (float(c.n_pc) + K_PC)
        scores[int(c.lane)] = p
    return normalize(scores)


def ai_final_probs(record, snapshot) -> tuple[dict[int, float] | None, float]:
    probs120 = step3.order_adjusted_probs(record, FINAL_DELTA, FINAL_GAMMA)
    dist_by_course, head_mass = head1_exacta.conditional_second_distribution(probs120)
    if dist_by_course is None:
        return None, float(head_mass)

    out = {}
    for c in snapshot.candidates:
        lane = int(c.lane)
        course = int(c.course)
        if course not in dist_by_course:
            return None, float(head_mass)
        out[lane] = float(dist_by_course[course])
    return normalize(out), float(head_mass)


def direct_eval_probs(base: dict[int, float], boats: dict[int, dict], alpha: float, beta: float) -> dict[int, float]:
    lanes = sorted(base)
    primary = {l: float(boats[l]['first_score']) for l in lanes}
    final3 = {l: float(boats[l]['final3']) for l in lanes}
    z_primary = zscores(primary)
    z_final3 = zscores(final3)

    scores = {}
    for lane in lanes:
        signal = float(alpha) * z_primary[lane] + float(beta) * z_final3[lane]
        signal = max(-20.0, min(20.0, signal))
        scores[lane] = max(float(base[lane]), EPS) * math.exp(signal)
    return normalize(scores)


def blend_probs(base: dict[int, float], ai: dict[int, float], weight: float) -> dict[int, float]:
    w = float(weight)
    scores = {}
    for lane in sorted(base):
        b = max(float(base[lane]), EPS)
        a = max(float(ai[lane]), EPS)
        scores[lane] = math.exp((1.0 - w) * math.log(b) + w * math.log(a))
    return normalize(scores)


def build_rows(data, snapshots, csv_races):
    rows = {'P1': [], 'P2': []}
    skip = Counter()

    snap_map = {
        period: {str(s.race_code): s for s in snapshots[period]}
        for period in ('P1', 'P2')
    }

    for period in ('P1', 'P2'):
        for record in data['records'][period]:
            code = str(record['race_code'])
            snapshot = snap_map[period].get(code)
            if snapshot is None:
                skip[f'{period}_basic_snapshot_missing'] += 1
                continue

            boats = csv_races.get(code)
            if boats is None or set(boats) != set(range(1, 7)):
                skip[f'{period}_csv_missing'] += 1
                continue

            actual_idx = int(record['actual_idx'])
            actual_courses = base_outcome.PATTERNS[actual_idx]
            actual_lanes = record['pattern_lanes'][actual_idx]
            actual_head_course = int(actual_courses[0])
            actual_head_lane = int(actual_lanes[0])
            actual_second_lane = int(actual_lanes[1])

            # 共通条件: 1号艇=1C かつその1C/1号艇が実際に1着。
            if actual_head_course != 1:
                skip[f'{period}_actual_head_not_1c'] += 1
                continue
            if actual_head_lane != 1:
                skip[f'{period}_head1_boat_not_lane1'] += 1
                continue

            candidate_lanes = sorted(int(c.lane) for c in snapshot.candidates)
            if candidate_lanes != [2, 3, 4, 5, 6]:
                skip[f'{period}_candidate_lanes_invalid'] += 1
                continue
            if actual_second_lane not in candidate_lanes:
                skip[f'{period}_actual_second_invalid'] += 1
                continue

            base = basic_probs(snapshot)
            ai, head_mass = ai_final_probs(record, snapshot)
            if ai is None or set(ai) != set(base):
                skip[f'{period}_ai_dist_invalid'] += 1
                continue

            rows[period].append({
                'race_code': code,
                'actual_second': actual_second_lane,
                'basic': base,
                'ai': ai,
                'head_mass': head_mass,
                'boats': boats,
            })
            skip[f'{period}_ready'] += 1

    return rows, skip


def evaluate(rows, method: str, direct_params=(0.0, 0.0), blend_weight=0.0) -> Metrics:
    metric = Metrics()
    alpha, beta = direct_params

    for row in rows:
        if method == 'BASIC_K10':
            probs = row['basic']
        elif method == 'AI_FINAL':
            probs = row['ai']
        elif method == 'DIRECT_EVAL':
            probs = direct_eval_probs(row['basic'], row['boats'], alpha, beta)
        elif method == 'BASIC_AI_BLEND':
            probs = blend_probs(row['basic'], row['ai'], blend_weight)
        else:
            raise ValueError(method)

        metric.add(probs, int(row['actual_second']))

    return metric


def metric_key(metric: Metrics, complexity: float = 0.0):
    s = metric.summary()
    return (
        float(s['logloss']),
        float(s['brier']),
        -float(s['top2']),
        float(complexity),
    )


def tune_direct(rows):
    table = []
    for alpha in DIRECT_GRID:
        for beta in DIRECT_GRID:
            m = evaluate(rows, 'DIRECT_EVAL', direct_params=(alpha, beta))
            table.append((metric_key(m, alpha + beta), alpha, beta, m))
    table.sort(key=lambda x: x[0])
    return table[0], table


def tune_blend(rows):
    table = []
    for w in BLEND_GRID:
        m = evaluate(rows, 'BASIC_AI_BLEND', blend_weight=w)
        table.append((metric_key(m, abs(w - 0.5)), w, m))
    table.sort(key=lambda x: x[0])
    return table[0], table


def topk(probs: dict[int, float], k: int) -> list[int]:
    return sorted(probs, key=lambda l: (-float(probs[l]), int(l)))[:k]


def method_probs(row, method, direct_params, blend_weight):
    if method == 'BASIC_K10':
        return row['basic']
    if method == 'AI_FINAL':
        return row['ai']
    if method == 'DIRECT_EVAL':
        return direct_eval_probs(row['basic'], row['boats'], *direct_params)
    if method == 'BASIC_AI_BLEND':
        return blend_probs(row['basic'], row['ai'], blend_weight)
    raise ValueError(method)


def compare_top2(rows, base_method, new_method, direct_params, blend_weight):
    changed = gained = lost = same_hit = same_miss = 0
    for row in rows:
        actual = int(row['actual_second'])
        bp = method_probs(row, base_method, direct_params, blend_weight)
        np = method_probs(row, new_method, direct_params, blend_weight)
        b = set(topk(bp, 2))
        n = set(topk(np, 2))
        if b != n:
            changed += 1
        bh = actual in b
        nh = actual in n
        if nh and not bh:
            gained += 1
        elif bh and not nh:
            lost += 1
        elif bh and nh:
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
    }


def print_metrics(title, rows, direct_params, blend_weight):
    print(f'\n【{title}】')
    print('方式                 R数   LogLoss   Brier5   正解平均P   Top1    Top2    Top3   平均順位  中央順位')
    print('-' * 112)
    out = {}
    for method in ('BASIC_K10', 'AI_FINAL', 'DIRECT_EVAL', 'BASIC_AI_BLEND'):
        m = evaluate(rows, method, direct_params, blend_weight)
        s = m.summary()
        out[method] = m
        print(
            f"{method:<20} {s['races']:>5d}  {s['logloss']:.6f}  {s['brier']:.6f}  "
            f"{s['actual_prob']*100:>8.3f}%  {s['top1']*100:>6.2f}%  "
            f"{s['top2']*100:>6.2f}%  {s['top3']*100:>6.2f}%  "
            f"{s['mean_rank']:>7.2f}  {s['median_rank']:>7.1f}"
        )
    return out


def print_top_tuning(direct_table, blend_table):
    print('\n【P1で選んだ DIRECT_EVAL 上位5】')
    print('順位  alpha  beta    LogLoss   Brier5   Top2')
    for i, (_key, alpha, beta, m) in enumerate(direct_table[:5], 1):
        s = m.summary()
        print(f"{i:>2d}   {alpha:>4.2f}  {beta:>4.2f}   {s['logloss']:.6f}  {s['brier']:.6f}  {s['top2']*100:>6.2f}%")

    print('\n【P1で選んだ BASIC_AI_BLEND 上位5】')
    print('順位    w     LogLoss   Brier5   Top2')
    for i, (_key, w, m) in enumerate(blend_table[:5], 1):
        s = m.summary()
        print(f"{i:>2d}   {w:>4.2f}   {s['logloss']:.6f}  {s['brier']:.6f}  {s['top2']*100:>6.2f}%")


def main():
    if len(sys.argv) != 3:
        print('Usage: python3 analysis/head1_second_probability_4way_compare.py P1_BOATS_CSV P2_BOATS_CSV')
        return 1

    p1_csv, p2_csv = sys.argv[1], sys.argv[2]

    print('STEP3出目モデル・基本2着率履歴・現行最終評価CSVを共通化中...')
    data = step3.build_common_records(p1_csv, p2_csv)

    # second_place_head1_k_compare の未来情報を使わない履歴ローダを、
    # 今回指定したP1/P2期間へ合わせて再利用する。
    basic2.P1_START = data['p1_start']
    basic2.P1_END = data['p1_end']
    basic2.P2_START = data['p2_start']
    basic2.P2_END = data['p2_end']
    snapshots, basic_meta = basic2.load_snapshots()

    csv_races = final_aite.load_boats(p1_csv, p2_csv)
    rows, skip = build_rows(data, snapshots, csv_races)

    if not rows['P1'] or not rows['P2']:
        raise RuntimeError('4方式の共通評価レースがありません')

    direct_best, direct_table = tune_direct(rows['P1'])
    _direct_key, best_alpha, best_beta, _direct_metric = direct_best
    blend_best, blend_table = tune_blend(rows['P1'])
    _blend_key, best_w, _blend_metric = blend_best
    direct_params = (float(best_alpha), float(best_beta))
    blend_weight = float(best_w)

    print('=' * 132)
    print('イン逃げ時2着予想：4方式比較')
    print('=' * 132)
    print(f"P1                  : {data['p1_start']} ～ {data['p1_end']}")
    print(f"P2完全ホールドアウト: {data['p2_start']} ～ {data['p2_end']}")
    print('共通評価条件         : 1号艇=1C かつ実際に1着 / 実2着一意 / 5候補共通')
    print('BASIC_K10            : 現行1号艇1着時2着率（選手×C、K=10）')
    print('AI_FINAL             : 現行イン1着時2連単（STEP3最終120通りから集約）')
    print(f'DIRECT_EVAL          : BASIC × exp({best_alpha:.2f}*一次Z + {best_beta:.2f}*final3Z) ※P1選択')
    print(f'BASIC_AI_BLEND       : BASIC^(1-w) × AI^w / w={best_w:.2f} ※P1選択')
    print('本番Web変更          : なし')
    print(f"共通評価             : P1={len(rows['P1'])}R / P2={len(rows['P2'])}R")

    print_top_tuning(direct_table, blend_table)
    print_metrics('P1 パラメータ選択用', rows['P1'], direct_params, blend_weight)
    p2_metrics = print_metrics('P2 ホールドアウト（最重要）', rows['P2'], direct_params, blend_weight)

    print('\n【P2 Top2の拾い/失い】')
    for base_method in ('BASIC_K10', 'AI_FINAL'):
        for new_method in ('DIRECT_EVAL', 'BASIC_AI_BLEND'):
            d = compare_top2(rows['P2'], base_method, new_method, direct_params, blend_weight)
            print(
                f'{base_method:<12} -> {new_method:<14} '
                f"変更={d['changed']:>4d}R / 拾い={d['gained']:>3d} / 失い={d['lost']:>3d} / 純増={d['net']:+d}"
            )

    print('\n【P2順位】')
    ranking = []
    for method, metric in p2_metrics.items():
        s = metric.summary()
        ranking.append((s['logloss'], s['brier'], -s['top2'], method, s))
    ranking.sort()
    for i, (_ll, _br, _t2, method, s) in enumerate(ranking, 1):
        print(
            f"{i}. {method:<16} LogLoss={s['logloss']:.6f} / Brier5={s['brier']:.6f} "
            f"/ Top2={s['top2']*100:.2f}% / Top3={s['top3']*100:.2f}%"
        )

    print('\n【共通化スキップ】')
    for key in sorted(skip):
        print(f'{key:<36}: {skip[key]}')

    print('\n【判断方針】')
    print('1. 最重要はP2のLogLoss/Brier5。Top2は実戦2連単の紐抜け改善指標として併記する。')
    print('2. DIRECT_EVAL / BLENDがP1だけ良くP2で悪化なら採用しない。')
    print('3. P2で改善が確認できた方式だけ、次工程で2連単Top1/Top2/Top3の買い方へ仮反映する。')
    print('4. その2連単で現行AI_FINALとの的中率・回収率・紐抜けを前後比較する。')
    print('5. この段階ではWeb/PredictionLogic/3連単は一切変更しない。')
    print('=' * 132)

    return 0


if __name__ == '__main__':
    raise SystemExit(main())
