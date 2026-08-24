#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
未来予測の固定済み全補正段階を、同一の未使用前方レース集合で比較する。

固定済み条件（ここでは再調整しない）:
- 基礎1着率: BB_MEDIUM Kpc=20 / Kpvc=10
- 展示進入リマップ
- EX_TOTAL beta=0.10
- SUM_RAW gamma=2.0
- スリット buff: 評価開始日前180日学習、alpha=0.25

Usage:
  python3 analysis/validate_future_prediction_full_forward.py 2026-08-15 2026-08-22
"""

from __future__ import annotations

import math
import sys
from datetime import datetime, timedelta

from base_winrate_exhibition_compare import (
    load_snapshots as load_ex_snapshots,
    apply_score,
)
from base_winrate_sum_compare import load_snapshots as load_sum_snapshots
from base_winrate_slit_compare import (
    inclusive_window_start,
    build_slit_records,
    learn_buff,
    load_lane_to_ex_course,
    attach_races,
    apply_slit_buff,
)

EX_TOTAL_BETA = 0.10
SLIT_ALPHA = 0.25
SLIT_BUFF_DAYS = 180


def parse_date(value: str):
    return datetime.strptime(value, "%Y-%m-%d").date()


def metrics(rows):
    if not rows:
        raise RuntimeError("評価対象が0件です")

    brier = 0.0
    logloss = 0.0
    top1 = 0

    for boats, probs in rows:
        for b, p in zip(boats, probs):
            cp = min(max(float(p), 1e-9), 1.0 - 1e-9)
            brier += (float(p) - int(b.y)) ** 2
            logloss += -(int(b.y) * math.log(cp) + (1 - int(b.y)) * math.log(1 - cp))

        best = sorted(range(6), key=lambda i: (-float(probs[i]), int(boats[i].lane)))[0]
        if int(boats[best].y) == 1:
            top1 += 1

    n = len(rows)
    return {
        "n": n,
        "brier": brier / (n * 6),
        "logloss": logloss / (n * 6),
        "top1": top1 / n,
    }


def print_row(label, m, prev=None):
    if prev is None:
        print(f"{label:<18} {m['brier']:.6f}     {m['logloss']:.6f}    {m['top1']*100:6.2f}%      -")
        return
    print(
        f"{label:<18} {m['brier']:.6f}     {m['logloss']:.6f}    {m['top1']*100:6.2f}%   "
        f"Brier {m['brier']-prev['brier']:+.6f} / LogLoss {m['logloss']-prev['logloss']:+.6f} / Top1 {(m['top1']-prev['top1'])*100:+.2f}pt"
    )


def main():
    if len(sys.argv) != 3:
        print("Usage: python3 analysis/validate_future_prediction_full_forward.py YYYY-MM-DD YYYY-MM-DD")
        sys.exit(1)

    start = parse_date(sys.argv[1])
    end = parse_date(sys.argv[2])
    if start > end:
        raise RuntimeError("開始日が終了日より後です")

    buff_end = start - timedelta(days=1)
    buff_start = inclusive_window_start(buff_end, SLIT_BUFF_DAYS)

    print("未来予測 全補正段階の前方検証データを構築中...", flush=True)

    ex_snaps, ex_skip, _ = load_ex_snapshots(start, end)
    sum_snaps, sum_skip, _ = load_sum_snapshots(start, end)

    records, slit_skip, _ = build_slit_records(buff_start, end)
    buff, buff_rows, _ = learn_buff(records, buff_start, buff_end)
    course_map = load_lane_to_ex_course(start, end)
    attached, attach_skip, methods = attach_races(
        sum_snaps, records, course_map, start, end
    )

    ex_map = {str(s.race_code): s for s in ex_snaps}

    stage_basic = []
    stage_remap = []
    stage_ex = []
    stage_sum = []
    stage_slit = []
    parity_max = 0.0
    missing_ex = 0

    for snap, pid, method, cmap, sum_probs in attached:
        rc = str(snap.race_code)
        ex = ex_map.get(rc)
        if ex is None:
            missing_ex += 1
            continue

        ex_boats = sorted(ex.boats, key=lambda b: b.lane)
        sum_boats = sorted(snap.boats, key=lambda b: b.lane)
        if [b.lane for b in ex_boats] != [b.lane for b in sum_boats]:
            raise RuntimeError(f"艇番順不一致: {rc}")

        basic = [float(b.base_lane) for b in ex_boats]
        remap = [float(b.base_remap) for b in ex_boats]
        scores = [float(b.scores["EX_TOTAL"]) for b in ex_boats]
        ex_probs = apply_score(remap, scores, EX_TOTAL_BETA)
        if ex_probs is None:
            continue

        # sum側が内部で持つEX_TOTAL後確率と、展示側から再計算した値の同一性を診断。
        sum_ex = [float(b.ex_total_prob) for b in sum_boats]
        parity_max = max(parity_max, max(abs(a - b) for a, b in zip(ex_probs, sum_ex)))

        final_probs = apply_slit_buff(
            sum_probs,
            sum_boats,
            int(pid),
            cmap,
            buff,
            SLIT_ALPHA,
        )
        if final_probs is None:
            continue

        stage_basic.append((sum_boats, basic))
        stage_remap.append((sum_boats, remap))
        stage_ex.append((sum_boats, ex_probs))
        stage_sum.append((sum_boats, list(sum_probs)))
        stage_slit.append((sum_boats, list(final_probs)))

    if not stage_slit:
        raise RuntimeError("共通評価可能レースが0件です")

    results = [
        ("BASIC_LANE", metrics(stage_basic)),
        ("REMAP_ONLY", metrics(stage_remap)),
        ("+EX_TOTAL", metrics(stage_ex)),
        ("+SUM_RAW", metrics(stage_sum)),
        ("+SLIT a=.25", metrics(stage_slit)),
    ]

    print("=" * 130)
    print("固定済み未来予測 全補正段階 前方ホールドアウト")
    print("=" * 130)
    print(f"評価期間       : {start} ～ {end}")
    print(f"共通評価レース : {len(stage_slit)}")
    print(f"slit buff学習  : {buff_start} ～ {buff_end} ({len(buff_rows)}R)")
    print("固定値         : EX_TOTAL beta=0.10 / SUM_RAW gamma=2.0 / slit alpha=0.25")
    print(f"EX_TOTAL parity: max abs diff={parity_max:.12f}")
    print(f"展示側欠落     : {missing_ex}")
    print("※ この前方結果から係数・閾値を再調整しない。")
    print()
    print("段階               Brier        LogLoss      Top1       前段との差")
    print("-" * 130)
    prev = None
    for label, m in results:
        print_row(label, m, prev)
        prev = m

    print("=" * 130)
    print("判定ポイント:")
    print("1. BASIC→REMAP→EX_TOTAL→SUMでBrier/LogLoss/Top1が概ね改善方向か。")
    print("2. slitは既知の通りBrier微改善・LogLoss微悪化でも、alpha=0.25の固定性を見る。")
    print("3. 一部段階が悪化しても、この8日間だけを見て係数を変更しない。")


if __name__ == "__main__":
    main()
