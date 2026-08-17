#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
補正後1着率 STEP 8-3b: スリット alpha 固定値の未使用評価比較

目的:
- STEP8-3でスリット追加効果自体は2期間ともBrier改善した。
- ただし改善幅が小さく、LogLossは悪化したため、本番固定alphaを最後に確認する。
- 評価期間直前180日でbuffを学習し、評価期間ではalphaを再調整せず
  0.00 / 0.25 / 0.50 / 0.75 / 1.00 をそのまま比較する。

固定済み基準:
- 基本1着率: BB_MEDIUM Kpc=20 / Kpvc=10
- 展示進入リマップ + EX_TOTAL beta=0.10
- SUM_RAW gamma=2.0
- スリットbuff: predicted PID × 展示進入C win lift × n/(n+40), cap ±0.08

Usage:
  python3 analysis/base_winrate_slit_alpha_holdout.py 2026-06-15 2026-07-14
  python3 analysis/base_winrate_slit_alpha_holdout.py 2026-07-15 2026-08-14
"""

from __future__ import annotations

import sys
from datetime import datetime, timedelta
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from base_winrate_sum_compare import load_snapshots
from base_winrate_slit_compare import (
    SLIT_BUFF_DAYS,
    inclusive_window_start,
    build_slit_records,
    learn_buff,
    load_lane_to_ex_course,
    attach_races,
    evaluate,
)

ALPHAS = (0.00, 0.25, 0.50, 0.75, 1.00)


def parse_date(s: str):
    return datetime.strptime(s, "%Y-%m-%d").date()


def fmt_delta(value: float, base: float) -> str:
    if base == 0:
        return "-"
    return f"{(value - base) / base * 100:+.3f}%"


def main():
    if len(sys.argv) != 3:
        print("Usage: python3 analysis/base_winrate_slit_alpha_holdout.py YYYY-MM-DD YYYY-MM-DD")
        sys.exit(1)

    eval_start = parse_date(sys.argv[1])
    eval_end = parse_date(sys.argv[2])
    if eval_start > eval_end:
        raise RuntimeError("開始日が終了日より後です")

    buff_end = eval_start - timedelta(days=1)
    buff_start = inclusive_window_start(buff_end, SLIT_BUFF_DAYS)

    print("補正後1着率 STEP8-3b 固定alpha未使用評価データを構築しています...")

    # EX_TOTAL + SUM_RAW の評価用スナップショット。
    # load_snapshotsはeval_start以前の履歴も内部で時系列更新するため、未来情報は混入しない。
    snapshots, sum_skip, _ = load_snapshots(eval_start, eval_end)

    # スリットbuffは評価期間直前180日だけで学習。
    records, slit_skip, _ = build_slit_records(buff_start, eval_end)
    buff, buff_rows, buff_freq = learn_buff(records, buff_start, buff_end)

    course_map = load_lane_to_ex_course(eval_start, eval_end)
    attached, attach_skip, methods = attach_races(
        snapshots,
        records,
        course_map,
        eval_start,
        eval_end,
    )
    if not attached:
        raise RuntimeError("評価可能レースが0件です")

    base = evaluate(attached)
    rows = []
    for alpha in ALPHAS:
        if alpha == 0.0:
            m = base
        else:
            m = evaluate(attached, buff, alpha)
        rows.append((alpha, m))

    best_brier = min(rows, key=lambda x: (x[1]["brier"], x[1]["logloss"], x[0]))
    best_logloss = min(rows, key=lambda x: (x[1]["logloss"], x[1]["brier"], x[0]))

    print("=" * 118)
    print("補正後1着率 STEP 8-3b：スリット固定alpha 未使用評価比較")
    print("=" * 118)
    print(f"buff学習          : {buff_start} ～ {buff_end} ({SLIT_BUFF_DAYS}日)")
    print(f"評価期間          : {eval_start} ～ {eval_end}")
    print(f"buff学習レース    : {len(buff_rows)}")
    print(f"評価レース        : {len(attached)}")
    print("基準補正          : 展示進入リマップ + EX_TOTAL beta=+0.100 + SUM_RAW gamma=+2.0")
    print("スリットbuff      : predicted PID × 展示進入C win lift × n/(n+40), cap=±0.08")
    print("alpha             : 評価期間では再調整せず固定値を直接比較")
    print("本番変更          : なし")

    print("\n【固定alpha 比較】")
    print("alpha       Brier      vs 基準       LogLoss     vs 基準       Top1率")
    print("-" * 92)
    for alpha, m in rows:
        print(
            f"{alpha:>5.2f}    {m['brier']:.6f}   {fmt_delta(m['brier'], base['brier']):>10}   "
            f"{m['logloss']:.6f}   {fmt_delta(m['logloss'], base['logloss']):>10}   "
            f"{m['top1']*100:>6.2f}%"
        )

    print("\n【参考】")
    print(f"Brier最小 alpha   : {best_brier[0]:.2f}")
    print(f"LogLoss最小 alpha : {best_logloss[0]:.2f}")

    total_methods = sum(methods.values())
    print("\n【PID予測方式】")
    for name in ("C_ST_RANK", "A_EX_FALLBACK"):
        n = methods[name]
        pct = n / total_methods * 100 if total_methods else 0.0
        print(f"{name:<16}: {n:>5} ({pct:6.2f}%)")

    print("\n【skip】")
    merged = {}
    for prefix, src in (("sum", sum_skip), ("slit", slit_skip), ("attach", attach_skip)):
        for key, value in src.items():
            merged[f"{prefix}_{key}"] = int(value)
    if merged:
        for key in sorted(merged):
            print(f"{key:<38}: {merged[key]}")
    else:
        print("なし")

    print("\n【判定の見方】")
    print("・2期間ともBrier改善する固定alphaを本番候補にする")
    print("・改善幅が近いなら、LogLoss悪化が小さい弱めのalphaを優先する")
    print("・alpha=0.25で十分なら過補正回避を優先し、0.50以上へ無理に上げない")
    print("・ここで固定alphaを決めた後、STEP8-4の1レース計算とWeb実装をその値へ揃える")
    print("=" * 118)


if __name__ == "__main__":
    main()
