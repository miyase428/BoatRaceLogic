#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
補正後1着率の「確率としての信憑性」を確認するホールドアウト検証。

見るもの:
- Brier / LogLoss / Top1率
- 確率帯ごとの 平均予測率 vs 実1着率（calibration）
- ECE (Expected Calibration Error)
- 補正後1着率順位1～6位の実1着率
- Top1艇の表示確率帯ごとの実的中率
- 場別 Brier / Top1 / ECE

重要:
- スリットbuffは評価期間より前180日だけで学習し、評価期間へ固定適用する。
  評価期間の結果をbuff学習には使わない（未来情報混入防止）。
- 通常22場は EX_TOTAL(展示+周回+周り足+直線) beta=0.10。
- AMG/TKYは本番仕様どおり EX_TOTAL3(展示+周回+周り足) beta=0.10。
- SUM_RAW gamma=2.0、slit alpha=0.25 は本番固定値。
- 本番Webロジックは変更しない。読み取り検証のみ。

Usage:
  python3 analysis/corrected_winrate_credibility_validate.py 2026-06-15 2026-07-14
  python3 analysis/corrected_winrate_credibility_validate.py 2026-07-15 2026-08-14
"""

from __future__ import annotations

import math
import sys
from collections import Counter, defaultdict
from datetime import datetime, timedelta
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import base_winrate_sum_compare as sumv
from base_winrate_amg_tky_final_compare import build_prepared_no_straight
from base_winrate_slit_compare import (
    SLIT_BUFF_DAYS,
    apply_slit_buff,
    attach_races,
    build_slit_records,
    inclusive_window_start,
    learn_buff,
    load_lane_to_ex_course,
)

SPECIAL_PLACES = {"AMG", "TKY"}
SUM_GAMMA = 2.0
SLIT_ALPHA = 0.25

# 舟単位のcalibration。高確率側はサンプルが減るので少し広めにする。
CALIB_BINS = (
    (0.00, 0.05),
    (0.05, 0.10),
    (0.10, 0.15),
    (0.15, 0.20),
    (0.20, 0.30),
    (0.30, 0.40),
    (0.40, 0.50),
    (0.50, 0.60),
    (0.60, 0.70),
    (0.70, 1.0000001),
)

TOP1_BINS = (
    (0.00, 0.25),
    (0.25, 0.30),
    (0.30, 0.35),
    (0.35, 0.40),
    (0.40, 0.50),
    (0.50, 0.60),
    (0.60, 1.0000001),
)


def parse_date(value: str):
    return datetime.strptime(value, "%Y-%m-%d").date()


def place_of(race_code: str) -> str:
    code = str(race_code)
    return code[8:11] if len(code) >= 11 else "???"


def pct(value: float) -> str:
    return f"{value * 100:6.2f}%"


def fmt_delta(value: float, base: float) -> str:
    if base == 0:
        return "-"
    return f"{(value - base) / base * 100:+.3f}%"


def load_exact_snapshots(eval_start, eval_end):
    """本番の場別チェーンに合わせた評価snapshotを作る。"""
    print("通常22場 snapshot構築中...")
    normal_all, normal_skip, _ = sumv.load_snapshots(eval_start, eval_end)
    normal = [s for s in normal_all if place_of(s.race_code) not in SPECIAL_PLACES]

    print("AMG/TKY snapshot構築中...")
    original_build_prepared = sumv.build_prepared
    sumv.build_prepared = build_prepared_no_straight
    try:
        special_all, special_skip, _ = sumv.load_snapshots(eval_start, eval_end)
    finally:
        sumv.build_prepared = original_build_prepared

    special = [s for s in special_all if place_of(s.race_code) in SPECIAL_PLACES]
    return normal, special, normal_skip, special_skip


def attach_exact(normal, special, records, course_map, eval_start, eval_end):
    normal_attached, nskip, nmethods = attach_races(
        normal, records, course_map, eval_start, eval_end
    )
    special_attached, sskip, smethods = attach_races(
        special, records, course_map, eval_start, eval_end
    )
    attached = normal_attached + special_attached
    methods = Counter(nmethods)
    methods.update(smethods)
    skip = Counter()
    for prefix, src in (("normal", nskip), ("special", sskip)):
        for key, value in src.items():
            skip[f"{prefix}_{key}"] += int(value)
    return attached, skip, methods


def probs_for_stage(item, stage: str, buff):
    snap, pid, method, cmap, sum_probs = item
    boats = sorted(snap.boats, key=lambda b: b.lane)

    if stage == "EX":
        return [float(b.ex_total_prob) for b in boats]
    if stage == "SUM":
        return [float(x) for x in sum_probs]
    if stage == "FINAL":
        p = apply_slit_buff(sum_probs, boats, pid, cmap, buff, SLIT_ALPHA)
        return None if p is None else [float(x) for x in p]
    raise RuntimeError(f"unknown stage: {stage}")


def stage_metrics(attached, stage: str, buff):
    brier = 0.0
    logloss = 0.0
    top1 = 0
    winner_nll = 0.0
    winner_prob_sum = 0.0
    race_n = 0

    for item in attached:
        snap = item[0]
        boats = sorted(snap.boats, key=lambda b: b.lane)
        probs = probs_for_stage(item, stage, buff)
        if probs is None:
            continue

        for p, b in zip(probs, boats):
            cp = min(max(p, 1e-9), 1.0 - 1e-9)
            brier += (p - b.y) ** 2
            logloss += -(b.y * math.log(cp) + (1 - b.y) * math.log(1 - cp))

        best = sorted(range(6), key=lambda i: (-probs[i], boats[i].lane))[0]
        if boats[best].y == 1:
            top1 += 1

        winner_idx = next((i for i, b in enumerate(boats) if b.y == 1), None)
        if winner_idx is None:
            continue
        wp = min(max(probs[winner_idx], 1e-12), 1.0)
        winner_prob_sum += wp
        winner_nll += -math.log(wp)
        race_n += 1

    if race_n == 0:
        return None

    return {
        "races": race_n,
        "brier": brier / (race_n * 6),
        "logloss": logloss / (race_n * 6),
        "top1": top1 / race_n,
        "winner_prob": winner_prob_sum / race_n,
        "winner_nll": winner_nll / race_n,
    }


def collect_final_rows(attached, buff):
    boat_rows = []
    race_rows = []
    max_total_error = 0.0

    for item in attached:
        snap, pid, method, cmap, sum_probs = item
        boats = sorted(snap.boats, key=lambda b: b.lane)
        probs = probs_for_stage(item, "FINAL", buff)
        if probs is None:
            continue

        max_total_error = max(max_total_error, abs(sum(probs) - 1.0))
        order = sorted(range(6), key=lambda i: (-probs[i], boats[i].lane))
        rank_by_index = {idx: rank + 1 for rank, idx in enumerate(order)}
        top_idx = order[0]

        race_rows.append({
            "race_code": str(snap.race_code),
            "place": place_of(snap.race_code),
            "top_prob": probs[top_idx],
            "top_hit": int(boats[top_idx].y == 1),
            "pid": int(pid),
            "method": method,
        })

        for idx, (p, b) in enumerate(zip(probs, boats)):
            boat_rows.append({
                "race_code": str(snap.race_code),
                "place": place_of(snap.race_code),
                "lane": int(b.lane),
                "y": int(b.y),
                "p": float(p),
                "prob_rank": rank_by_index[idx],
            })

    return boat_rows, race_rows, max_total_error


def in_bin(value: float, low: float, high: float) -> bool:
    return low <= value < high


def calibration(rows, bins=CALIB_BINS):
    table = []
    total_n = len(rows)
    weighted_gap = 0.0
    max_gap = 0.0

    for low, high in bins:
        selected = [r for r in rows if in_bin(r["p"], low, high)]
        n = len(selected)
        if n == 0:
            table.append((low, high, 0, None, None, None, 0))
            continue
        pred = sum(r["p"] for r in selected) / n
        wins = sum(r["y"] for r in selected)
        actual = wins / n
        gap = actual - pred
        weighted_gap += n * abs(gap)
        max_gap = max(max_gap, abs(gap))
        table.append((low, high, n, pred, actual, gap, wins))

    ece = weighted_gap / total_n if total_n else float("nan")
    return table, ece, max_gap


def calibration_from_top1(race_rows):
    table = []
    for low, high in TOP1_BINS:
        selected = [r for r in race_rows if in_bin(r["top_prob"], low, high)]
        n = len(selected)
        if n == 0:
            table.append((low, high, 0, None, None, None, 0))
            continue
        pred = sum(r["top_prob"] for r in selected) / n
        hits = sum(r["top_hit"] for r in selected)
        actual = hits / n
        table.append((low, high, n, pred, actual, actual - pred, hits))
    return table


def brier_from_rows(rows):
    if not rows:
        return float("nan")
    return sum((r["p"] - r["y"]) ** 2 for r in rows) / len(rows)


def print_calibration(table):
    print("確率帯       舟数    勝数    平均予測    実1着率      実績-予測")
    print("-" * 78)
    for low, high, n, pred, actual, gap, wins in table:
        label = f"{low*100:>2.0f}-{min(high,1.0)*100:<3.0f}%"
        if n == 0:
            print(f"{label:<10} {0:>6}  {0:>6}       -          -          -")
            continue
        print(
            f"{label:<10} {n:>6}  {wins:>6}   {pred*100:>8.2f}%   "
            f"{actual*100:>8.2f}%   {gap*100:>+9.2f}pt"
        )


def print_rank_table(boat_rows):
    print("順位     舟数    勝数    平均予測    実1着率      実績-予測")
    print("-" * 74)
    for rank in range(1, 7):
        rows = [r for r in boat_rows if r["prob_rank"] == rank]
        n = len(rows)
        if n == 0:
            continue
        wins = sum(r["y"] for r in rows)
        pred = sum(r["p"] for r in rows) / n
        actual = wins / n
        print(
            f"{rank:>2}位    {n:>6}  {wins:>6}   {pred*100:>8.2f}%   "
            f"{actual*100:>8.2f}%   {(actual-pred)*100:>+9.2f}pt"
        )


def print_top1_table(table):
    print("Top1表示率帯  R数   的中   平均表示    実Top1的中率   実績-表示")
    print("-" * 82)
    for low, high, n, pred, actual, gap, hits in table:
        label = f"{low*100:>2.0f}-{min(high,1.0)*100:<3.0f}%"
        if n == 0:
            print(f"{label:<12} {0:>5} {0:>6}       -            -           -")
            continue
        print(
            f"{label:<12} {n:>5} {hits:>6}   {pred*100:>8.2f}%      "
            f"{actual*100:>8.2f}%   {gap*100:>+9.2f}pt"
        )


def print_place_table(boat_rows, race_rows):
    places = sorted({r["place"] for r in race_rows})
    print("場     R数    Top1率     Brier      ECE      平均Top1表示")
    print("-" * 72)
    for place in places:
        br = [r for r in boat_rows if r["place"] == place]
        rr = [r for r in race_rows if r["place"] == place]
        if not rr:
            continue
        _, ece, _ = calibration(br)
        top1 = sum(r["top_hit"] for r in rr) / len(rr)
        avg_top = sum(r["top_prob"] for r in rr) / len(rr)
        print(
            f"{place:<4} {len(rr):>6}   {top1*100:>7.2f}%   "
            f"{brier_from_rows(br):.6f}   {ece*100:>6.2f}pt   {avg_top*100:>8.2f}%"
        )


def main():
    if len(sys.argv) != 3:
        print(
            "Usage: python3 analysis/corrected_winrate_credibility_validate.py "
            "YYYY-MM-DD YYYY-MM-DD"
        )
        sys.exit(2)

    eval_start = parse_date(sys.argv[1])
    eval_end = parse_date(sys.argv[2])
    if eval_start > eval_end:
        raise RuntimeError("開始日が終了日より後です")

    buff_end = eval_start - timedelta(days=1)
    buff_start = inclusive_window_start(buff_end, SLIT_BUFF_DAYS)

    print("補正後1着率 信憑性検証データを構築しています...")
    normal, special, normal_skip, special_skip = load_exact_snapshots(eval_start, eval_end)

    print("スリットPID/buff構築中...")
    records, slit_skip, _ = build_slit_records(buff_start, eval_end)
    buff, buff_rows, _ = learn_buff(records, buff_start, buff_end)
    course_map = load_lane_to_ex_course(eval_start, eval_end)

    attached, attach_skip, methods = attach_exact(
        normal, special, records, course_map, eval_start, eval_end
    )
    if not attached:
        raise RuntimeError("最終補正まで評価可能なレースが0件です")

    attached.sort(key=lambda x: str(x[0].race_code))

    m_ex = stage_metrics(attached, "EX", buff)
    m_sum = stage_metrics(attached, "SUM", buff)
    m_final = stage_metrics(attached, "FINAL", buff)
    boat_rows, race_rows, max_total_error = collect_final_rows(attached, buff)
    calib_table, ece, mce = calibration(boat_rows)
    top1_table = calibration_from_top1(race_rows)

    line = "=" * 112
    print("\n" + line)
    print("補正後1着率：確率としての信憑性検証")
    print(line)
    print(f"評価期間          : {eval_start} ～ {eval_end}")
    print(f"評価レース        : {len(race_rows)}")
    print(f"評価舟数          : {len(boat_rows)}")
    print(f"通常22場          : {len([x for x in attached if place_of(x[0].race_code) not in SPECIAL_PLACES])}R")
    print(f"AMG/TKY           : {len([x for x in attached if place_of(x[0].race_code) in SPECIAL_PLACES])}R")
    print(f"スリットbuff学習 : {buff_start} ～ {buff_end} / {len(buff_rows)}R")
    print("本番固定値        : beta=0.10 / SUM gamma=2.0 / slit alpha=0.25")
    print(f"100%合計最大誤差 : {max_total_error:.12f}")
    print("未来情報          : スリットbuffは評価期間前のみで学習")

    print("\n【1. チェーン全体の予測性能】")
    print("段階                 Brier      vs前段      LogLoss    Top1率   実勝者平均p   勝者NLL")
    print("-" * 104)
    print(
        f"EX_TOTAL/EX3        {m_ex['brier']:.6f}       -       {m_ex['logloss']:.6f}   "
        f"{m_ex['top1']*100:>6.2f}%    {m_ex['winner_prob']*100:>7.2f}%   {m_ex['winner_nll']:.5f}"
    )
    print(
        f"+ SUM_RAW            {m_sum['brier']:.6f}   {fmt_delta(m_sum['brier'], m_ex['brier']):>9}   "
        f"{m_sum['logloss']:.6f}   {m_sum['top1']*100:>6.2f}%    "
        f"{m_sum['winner_prob']*100:>7.2f}%   {m_sum['winner_nll']:.5f}"
    )
    print(
        f"FINAL + SLIT         {m_final['brier']:.6f}   {fmt_delta(m_final['brier'], m_sum['brier']):>9}   "
        f"{m_final['logloss']:.6f}   {m_final['top1']*100:>6.2f}%    "
        f"{m_final['winner_prob']*100:>7.2f}%   {m_final['winner_nll']:.5f}"
    )

    print("\n【2. 補正後1着率のcalibration（最重要）】")
    print_calibration(calib_table)
    print(f"\nECE（舟単位） : {ece*100:.3f}pt")
    print(f"MCE（参考）   : {mce*100:.3f}pt  ※少数帯の影響を受けやすい")

    print("\n【3. 補正後1着率順位ごとの実1着率】")
    print_rank_table(boat_rows)

    print("\n【4. 1着率1位艇の表示確率はどこまで信用できるか】")
    print_top1_table(top1_table)

    print("\n【5. 場別】")
    print_place_table(boat_rows, race_rows)

    print("\n【PID予測方式】")
    total_methods = sum(methods.values())
    for name in sorted(methods):
        n = methods[name]
        ratio = n / total_methods * 100 if total_methods else 0.0
        print(f"{name:<20}: {n:>6} ({ratio:6.2f}%)")

    print("\n【skip概要】")
    merged = Counter()
    for prefix, src in (
        ("normal", normal_skip),
        ("special", special_skip),
        ("slit", slit_skip),
        ("attach", attach_skip),
    ):
        for key, value in src.items():
            merged[f"{prefix}_{key}"] += int(value)
    if merged:
        for key in sorted(merged):
            print(f"{key:<44}: {merged[key]}")
    else:
        print("なし")

    print("\n【見方】")
    print("・確率帯の『平均予測』と『実1着率』が近いほど、表示%をそのまま信用しやすい")
    print("・ECEは全舟を重み付けした平均ズレ。小さいほどcalibrationが良い")
    print("・Top1率は順位付け能力。calibrationとは別物なので両方見る")
    print("・高確率帯や場別はNが小さいとブレるため、2期間で同じ傾向かを確認する")
    print("・この検証だけで定数を再調整しない。まず現状の信憑性を把握する")
    print(line)


if __name__ == "__main__":
    main()
