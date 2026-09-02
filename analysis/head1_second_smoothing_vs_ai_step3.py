#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
イン逃げ時2着率 平滑化再検証 STEP3。

STEP1/2で平滑化方式を再点検した結果を受け、現行の FIXED_K10 を
固定した③ AI_FINAL と同じ共通母集団で最終比較する。

比較
----
BASIC_K10
    全場コース基礎率 + 選手×コース直近100走を K=10 で縮約。
    今回コースは展示進入6艇完全なら展示を一括採用し、そうでなければ
    result_detail -> exhibition_live -> lane の完全マップへフォールバック。

AI_FINAL
    現行「イン1着時 2連単」。STEP3最終120通り出目確率を
    P(2着 | 1C頭) に集約した③。

BLEND_P1
    BASIC_K10 と AI_FINAL の幾何平均。
    w は P1 のみで 0.0～1.0（0.1刻み）から選択し、P2では固定。
    p ∝ BASIC^(1-w) * AI^w

重要
----
- ③ AI_FINAL の中身・重みは変更しない。
- BASIC側も STEP2 を受けて K=10 のまま固定する。
- P2は完全ホールドアウト。
- P2の差は日単位block bootstrap 95%CIも出す。
- Web / PredictionLogic は変更しない。

Usage
-----
引数なしなら既定CSVを自動探索:
  python3 analysis/head1_second_smoothing_vs_ai_step3.py

明示指定も可:
  python3 analysis/head1_second_smoothing_vs_ai_step3.py P1_BOATS_CSV P2_BOATS_CSV
"""

from __future__ import annotations

import math
import random
import sys
from collections import Counter, defaultdict
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import base_trifecta_probability_compare as base_outcome
import head1_exacta_probability_validate as head1_exacta
import head1_second_smoothing_recheck_step1 as smooth1
import trifecta_probability_order_compare as step3

K_PC = 10.0
FINAL_DELTA = 0.25
FINAL_GAMMA = 0.25
EPS = 1e-15
BLEND_GRID = tuple(i / 10.0 for i in range(0, 11))
BOOTSTRAP_N = 3000
BOOTSTRAP_SEED = 428


def normalize(scores: dict[int, float]) -> dict[int, float]:
    clipped = {int(k): max(EPS, float(v)) for k, v in scores.items()}
    total = sum(clipped.values())
    if total <= 0:
        u = 1.0 / max(1, len(clipped))
        return {k: u for k in clipped}
    return {k: v / total for k, v in clipped.items()}


def basic_k10_probs(snapshot) -> dict[int, float]:
    scores = {}
    for c in snapshot.candidates:
        p = (float(c.w) + K_PC * float(c.p0)) / (float(c.n) + K_PC)
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


def blend_probs(base: dict[int, float], ai: dict[int, float], w: float) -> dict[int, float]:
    scores = {}
    for lane in sorted(base):
        b = max(EPS, float(base[lane]))
        a = max(EPS, float(ai[lane]))
        scores[lane] = math.exp((1.0 - float(w)) * math.log(b) + float(w) * math.log(a))
    return normalize(scores)


def row_metrics(probs: dict[int, float], actual_second: int) -> dict:
    lanes = sorted(probs)
    p_actual = max(EPS, min(1.0, float(probs[actual_second])))
    brier = 0.0
    for lane in lanes:
        y = 1.0 if lane == actual_second else 0.0
        brier += (float(probs[lane]) - y) ** 2
    brier /= max(1, len(lanes))

    ordered = sorted(lanes, key=lambda l: (-float(probs[l]), int(l)))
    rank = ordered.index(actual_second) + 1
    return {
        'logloss': -math.log(p_actual),
        'brier': brier,
        'actual_p': p_actual,
        'top1': 1.0 if rank <= 1 else 0.0,
        'top2': 1.0 if rank <= 2 else 0.0,
        'top3': 1.0 if rank <= 3 else 0.0,
        'rank': float(rank),
    }


def probs_for(row, method: str, blend_w: float) -> dict[int, float]:
    if method == 'BASIC_K10':
        return row['basic']
    if method == 'AI_FINAL':
        return row['ai']
    if method == 'BLEND_P1':
        return blend_probs(row['basic'], row['ai'], blend_w)
    raise ValueError(method)


def evaluate(rows: list[dict], method: str, blend_w: float = 1.0) -> dict:
    vals = [row_metrics(probs_for(r, method, blend_w), int(r['actual_second'])) for r in rows]
    n = len(vals)
    if n == 0:
        raise RuntimeError('評価Rが0件です')
    return {
        'n': n,
        'logloss': sum(v['logloss'] for v in vals) / n,
        'brier': sum(v['brier'] for v in vals) / n,
        'actual_p': sum(v['actual_p'] for v in vals) / n,
        'top1': sum(v['top1'] for v in vals) / n,
        'top2': sum(v['top2'] for v in vals) / n,
        'top3': sum(v['top3'] for v in vals) / n,
        'mean_rank': sum(v['rank'] for v in vals) / n,
    }


def tune_blend_p1(rows: list[dict]):
    table = []
    for w in BLEND_GRID:
        m = evaluate(rows, 'BLEND_P1', w)
        # 確率品質を最優先。同点ならAI単独(w=1)に近い単純側を優先。
        key = (m['logloss'], m['brier'], -m['top2'], abs(1.0 - w))
        table.append((key, w, m))
    table.sort(key=lambda x: x[0])
    return float(table[0][1]), table


def build_rows(data, snapshots):
    rows = {'P1': [], 'P2': []}
    skip = Counter()
    snap_map = {
        p: {str(s.race_code): s for s in snapshots[p]}
        for p in ('P1', 'P2')
    }

    for period in ('P1', 'P2'):
        for record in data['records'][period]:
            code = str(record['race_code'])
            snapshot = snap_map[period].get(code)
            if snapshot is None:
                skip[f'{period}_smoothing_snapshot_missing'] += 1
                continue

            actual_idx = int(record['actual_idx'])
            actual_courses = base_outcome.PATTERNS[actual_idx]
            actual_lanes = record['pattern_lanes'][actual_idx]
            if int(actual_courses[0]) != 1:
                skip[f'{period}_actual_head_not_1c'] += 1
                continue
            if int(actual_lanes[0]) != 1:
                skip[f'{period}_actual_head_not_boat1'] += 1
                continue

            actual_second = int(actual_lanes[1])
            snap_actual = [int(c.lane) for c in snapshot.candidates if int(c.y) == 1]
            if len(snap_actual) != 1 or int(snap_actual[0]) != actual_second:
                skip[f'{period}_actual_second_mismatch'] += 1
                continue

            candidate_lanes = sorted(int(c.lane) for c in snapshot.candidates)
            if candidate_lanes != [2, 3, 4, 5, 6]:
                skip[f'{period}_candidate_lanes_invalid'] += 1
                continue

            basic = basic_k10_probs(snapshot)
            ai, head_mass = ai_final_probs(record, snapshot)
            if ai is None or set(ai) != set(basic):
                skip[f'{period}_ai_invalid'] += 1
                continue

            rows[period].append({
                'race_code': code,
                'race_date': snapshot.race_date,
                'actual_second': actual_second,
                'basic': basic,
                'ai': ai,
                'head_mass': head_mass,
            })
            skip[f'{period}_ready'] += 1

    return rows, skip


def percentile(xs: list[float], q: float) -> float:
    ys = sorted(xs)
    if not ys:
        return float('nan')
    pos = (len(ys) - 1) * q
    lo = int(math.floor(pos))
    hi = int(math.ceil(pos))
    if lo == hi:
        return ys[lo]
    frac = pos - lo
    return ys[lo] * (1.0 - frac) + ys[hi] * frac


def metric_diff(sample: list[dict], new_method: str, base_method: str, blend_w: float, key: str) -> float:
    new = evaluate(sample, new_method, blend_w)[key]
    base = evaluate(sample, base_method, blend_w)[key]
    return float(new) - float(base)


def day_block_bootstrap(rows: list[dict], new_method: str, base_method: str, blend_w: float):
    by_day = defaultdict(list)
    for row in rows:
        by_day[row['race_date']].append(row)
    days = sorted(by_day)
    rng = random.Random(BOOTSTRAP_SEED)
    diffs = {k: [] for k in ('logloss', 'brier', 'top1', 'top2', 'top3')}

    for _ in range(BOOTSTRAP_N):
        sampled_days = [rng.choice(days) for _ in days]
        sample = []
        for d in sampled_days:
            sample.extend(by_day[d])
        for key in diffs:
            diffs[key].append(metric_diff(sample, new_method, base_method, blend_w, key))

    return {
        key: (
            percentile(vals, 0.50),
            percentile(vals, 0.025),
            percentile(vals, 0.975),
        )
        for key, vals in diffs.items()
    }


def print_metrics(title: str, rows: list[dict], blend_w: float):
    print(f'\n【{title}】')
    print('方式            R数   LogLoss   Brier5   実2着平均P   Top1    Top2    Top3   平均順位')
    print('-' * 100)
    out = {}
    for method in ('BASIC_K10', 'AI_FINAL', 'BLEND_P1'):
        m = evaluate(rows, method, blend_w)
        out[method] = m
        print(
            f"{method:<14} {m['n']:>5d}  {m['logloss']:.6f}  {m['brier']:.6f}  "
            f"{m['actual_p']*100:>8.3f}%  {m['top1']*100:>6.2f}%  {m['top2']*100:>6.2f}%  "
            f"{m['top3']*100:>6.2f}%  {m['mean_rank']:>7.3f}"
        )
    return out


def print_bootstrap(title: str, result: dict):
    print(f'\n【P2 日単位block bootstrap：{title}】')
    print('※ LogLoss/Brierはマイナスが改善、Topはプラスが改善')
    for key in ('logloss', 'brier', 'top1', 'top2', 'top3'):
        med, lo, hi = result[key]
        if key.startswith('top'):
            print(f'{key:<8}: median={med*100:+.4f}pt  95%CI=[{lo*100:+.4f}, {hi*100:+.4f}]pt')
        else:
            print(f'{key:<8}: median={med:+.6f}  95%CI=[{lo:+.6f}, {hi:+.6f}]')


def resolve_csvs(argv):
    if len(argv) == 3:
        return argv[1], argv[2]
    if len(argv) != 1:
        raise SystemExit('Usage: python3 analysis/head1_second_smoothing_vs_ai_step3.py [P1_BOATS_CSV P2_BOATS_CSV]')

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


def main():
    p1_csv, p2_csv = resolve_csvs(sys.argv)

    print('③ AI_FINAL と再検証済み平滑化K10を、未来情報なしの共通母集団へ揃えています...')
    data = step3.build_common_records(p1_csv, p2_csv)

    # STEP1のproduction-aligned今回コース復元を、そのまま今回のP1/P2へ合わせて使う。
    smooth1.P1_START = data['p1_start']
    smooth1.P1_END = data['p1_end']
    smooth1.P2_START = data['p2_start']
    smooth1.P2_END = data['p2_end']
    snapshots, smooth_meta = smooth1.load_snapshots()

    rows, skip = build_rows(data, snapshots)
    if not rows['P1'] or not rows['P2']:
        raise RuntimeError('BASIC_K10とAI_FINALの共通評価レースがありません')

    best_w, blend_table = tune_blend_p1(rows['P1'])

    print('=' * 122)
    print('イン逃げ時2着率 平滑化再検証 STEP3：BASIC_K10 vs ③ AI_FINAL')
    print('=' * 122)
    print(f"P1                  : {data['p1_start']} ～ {data['p1_end']}")
    print(f"P2完全ホールドアウト: {data['p2_start']} ～ {data['p2_end']}")
    print(f'P1 CSV               : {p1_csv}')
    print(f'P2 CSV               : {p2_csv}')
    print('BASIC_K10            : STEP1/2再検証後も残った現行固定K=10平滑化')
    print('③ AI_FINAL           : 現行120通り最終確率から P(2着 | 1C頭) を集約。変更なし')
    print(f'BLEND_P1             : BASIC^(1-w) × AI^w / P1選択 w={best_w:.1f}')
    print('今回コース           : 展示6艇完全を優先。なければ履歴完全マップへfallback')
    print('本番変更             : なし')
    print(f"共通評価             : P1={len(rows['P1'])}R / P2={len(rows['P2'])}R")

    print('\n【P1 BLEND weight候補：LogLoss順】')
    print('順位   w    LogLoss   Brier5   Top1    Top2    Top3')
    print('-' * 72)
    for i, (_key, w, m) in enumerate(blend_table, 1):
        print(
            f"{i:>2d}   {w:>3.1f}  {m['logloss']:.6f}  {m['brier']:.6f}  "
            f"{m['top1']*100:>6.2f}%  {m['top2']*100:>6.2f}%  {m['top3']*100:>6.2f}%"
        )

    print_metrics('P1 参考', rows['P1'], best_w)
    p2 = print_metrics('P2 ホールドアウト（最重要）', rows['P2'], best_w)

    bs_ai_basic = day_block_bootstrap(rows['P2'], 'AI_FINAL', 'BASIC_K10', best_w)
    bs_blend_ai = day_block_bootstrap(rows['P2'], 'BLEND_P1', 'AI_FINAL', best_w)
    print_bootstrap('AI_FINAL - BASIC_K10', bs_ai_basic)
    print_bootstrap('BLEND_P1 - AI_FINAL', bs_blend_ai)

    print('\n【STEP3判断メモ】')
    ai = p2['AI_FINAL']
    basic = p2['BASIC_K10']
    blend = p2['BLEND_P1']
    print('・まずAI_FINALがBASIC_K10よりP2のLogLoss/Brier/Top1～3で上かを確認する。')
    print('・BLENDはAI_FINALを明確に上回る場合だけ候補。差が小さい/CIが0をまたぐなら③単独を優先する。')
    print('・平滑化は「無意味」ではなく、BASIC側の母数不足対策として残せる。共通2着エンジン採用とは別判断。')
    print(
        f"・P2差 AI-BASIC: LL={ai['logloss']-basic['logloss']:+.6f}, "
        f"Brier={ai['brier']-basic['brier']:+.6f}, Top1={(ai['top1']-basic['top1'])*100:+.2f}pt, "
        f"Top2={(ai['top2']-basic['top2'])*100:+.2f}pt, Top3={(ai['top3']-basic['top3'])*100:+.2f}pt"
    )
    print(
        f"・P2差 BLEND-AI: LL={blend['logloss']-ai['logloss']:+.6f}, "
        f"Brier={blend['brier']-ai['brier']:+.6f}, Top1={(blend['top1']-ai['top1'])*100:+.2f}pt, "
        f"Top2={(blend['top2']-ai['top2'])*100:+.2f}pt, Top3={(blend['top3']-ai['top3'])*100:+.2f}pt"
    )

    print('\n【今回コースsource】')
    for key in sorted(smooth_meta.get('target_source', {})):
        print(f"{key:<40}: {smooth_meta['target_source'][key]}")

    print('\n【共通化スキップ】')
    for key in sorted(skip):
        print(f'{key:<40}: {skip[key]}')

    print('=' * 122)
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
