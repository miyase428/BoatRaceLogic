#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
買い目最適化 STEP 2.5：固定3連単2点戦略の安定性・リスク確認

STEP1/STEP2で残った戦略を一切再探索せず、そのまま評価する。

固定条件
--------
- 最終 P(1C頭) >= 65%
- 最終120通り出目確率の上位2点
- 3連単のみ
- 1点500円（合計1000円）

目的
----
ROI 100%前後はブレの影響を受けやすいため、採用前に
- 日別ROI
- 日別黒字率
- 払戻/利益分布
- 連敗の長さ（race_code順の参考値）
- 累積最大ドローダウン（race_code順の参考値）
- 50R / 100RローリングROI
- 日単位ブートストラップ95%区間
を確認する。

重要
----
- 条件・点数・配分は固定。ここでは最適化しない。
- 過去全候補オッズは使用しない。
- race_code順は同日内の厳密な発売時刻順ではないため、連敗/ドローダウンは参考値。
- 本番Web/PredictionLogic/買い目ロジックは変更しない。

Usage:
python3 analysis/bet_strategy_risk_profile.py \
  analysis/output/final_prediction_boats_20260715_20260814.csv \
  analysis/output/final_prediction_boats_fast_cached_20260815_20260822.csv \
  analysis/output/final_prediction_boats_fast_cached_20260823_20260831.csv
"""

from __future__ import annotations

import random
import statistics
import sys
from collections import defaultdict
from datetime import datetime
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import bet_structure_optimization_compare as step1


THRESHOLD = 0.65
TRI_COUNT = 2
STAKE_EACH = 500
BET_PER_RACE = 1000
BOOTSTRAP_N = 5000
BOOTSTRAP_SEED = 20260901


def parse_date_from_code(code: str):
    return datetime.strptime(str(code)[:8], "%Y%m%d").date()


def build_bets(rows):
    out = []
    for row in rows:
        if float(row["head1_mass"]) < THRESHOLD:
            continue

        picks = list(row["tri_order"][:TRI_COUNT])
        actual_idx = int(row["actual_idx"])
        hit = actual_idx in picks
        payout = int(row["trifecta_payout"])
        returned = payout * (STAKE_EACH / 100.0) if hit else 0.0
        profit = returned - BET_PER_RACE

        out.append({
            "race_code": str(row["race_code"]),
            "race_date": parse_date_from_code(row["race_code"]),
            "hit": bool(hit),
            "payout": payout if hit else 0,
            "return": float(returned),
            "profit": float(profit),
            "cover": step1.union_coverage(row, TRI_COUNT, 0),
            "head1_mass": float(row["head1_mass"]),
        })

    out.sort(key=lambda x: x["race_code"])
    return out


def percentile(values, q):
    if not values:
        return 0.0
    xs = sorted(float(x) for x in values)
    if len(xs) == 1:
        return xs[0]
    pos = (len(xs) - 1) * float(q)
    lo = int(pos)
    hi = min(lo + 1, len(xs) - 1)
    frac = pos - lo
    return xs[lo] * (1.0 - frac) + xs[hi] * frac


def longest_loss_streak(bets):
    best = 0
    cur = 0
    for b in bets:
        if b["profit"] < 0:
            cur += 1
            best = max(best, cur)
        else:
            cur = 0
    return best


def max_drawdown(bets):
    equity = 0.0
    peak = 0.0
    max_dd = 0.0
    for b in bets:
        equity += float(b["profit"])
        peak = max(peak, equity)
        max_dd = max(max_dd, peak - equity)
    return max_dd


def rolling_roi(bets, window):
    if len(bets) < window:
        return []
    profits = [float(b["profit"]) for b in bets]
    prefix = [0.0]
    for p in profits:
        prefix.append(prefix[-1] + p)
    out = []
    invest = window * BET_PER_RACE
    for i in range(window, len(bets) + 1):
        profit = prefix[i] - prefix[i - window]
        out.append((invest + profit) / invest)
    return out


def daily_rows(bets):
    by_date = defaultdict(list)
    for b in bets:
        by_date[b["race_date"]].append(b)

    rows = []
    for d in sorted(by_date):
        items = by_date[d]
        n = len(items)
        hits = sum(1 for x in items if x["hit"])
        profit = sum(float(x["profit"]) for x in items)
        invest = n * BET_PER_RACE
        returned = invest + profit
        rows.append({
            "date": d,
            "bets": n,
            "hits": hits,
            "hit_rate": hits / n if n else 0.0,
            "profit": profit,
            "roi": returned / invest if invest else 0.0,
        })
    return rows


def bootstrap_daily_roi(daily, n=BOOTSTRAP_N, seed=BOOTSTRAP_SEED):
    if not daily:
        return []
    rng = random.Random(seed)
    values = []
    for _ in range(n):
        sampled = [rng.choice(daily) for _ in range(len(daily))]
        invest = sum(int(x["bets"]) * BET_PER_RACE for x in sampled)
        profit = sum(float(x["profit"]) for x in sampled)
        values.append((invest + profit) / invest if invest else 0.0)
    return values


def summarize(bets):
    n = len(bets)
    if n <= 0:
        return None
    hits = sum(1 for b in bets if b["hit"])
    invest = n * BET_PER_RACE
    returned = sum(float(b["return"]) for b in bets)
    profit = returned - invest
    hit_returns = [float(b["return"]) for b in bets if b["hit"]]
    daily = daily_rows(bets)
    boot = bootstrap_daily_roi(daily)

    return {
        "bets": n,
        "hits": hits,
        "hit_rate": hits / n,
        "invest": invest,
        "return": returned,
        "profit": profit,
        "roi": returned / invest,
        "avg_cover": statistics.mean(float(b["cover"]) for b in bets),
        "avg_head1": statistics.mean(float(b["head1_mass"]) for b in bets),
        "positive_days": sum(1 for d in daily if d["profit"] > 0),
        "break_even_days": sum(1 for d in daily if abs(d["profit"]) < 1e-9),
        "days": len(daily),
        "daily": daily,
        "max_loss_streak": longest_loss_streak(bets),
        "max_drawdown": max_drawdown(bets),
        "hit_return_median": statistics.median(hit_returns) if hit_returns else 0.0,
        "hit_return_p10": percentile(hit_returns, 0.10),
        "hit_return_p90": percentile(hit_returns, 0.90),
        "roll50": rolling_roi(bets, 50),
        "roll100": rolling_roi(bets, 100),
        "boot_lo": percentile(boot, 0.025),
        "boot_med": percentile(boot, 0.50),
        "boot_hi": percentile(boot, 0.975),
    }


def print_summary(title, bets):
    s = summarize(bets)
    print(f"\n【{title}】")
    if s is None:
        print("対象なし")
        return

    print(f"購入R             : {s['bets']}R")
    print(f"的中               : {s['hits']}R / {s['hit_rate']*100:.2f}%")
    print(f"投資               : {s['invest']:,.0f}円")
    print(f"払戻               : {s['return']:,.0f}円")
    print(f"収支               : {s['profit']:+,.0f}円")
    print(f"ROI                : {s['roi']*100:.2f}%")
    print(f"平均P(1C頭)        : {s['avg_head1']*100:.2f}%")
    print(f"上位2点平均Cover   : {s['avg_cover']*100:.2f}%")
    print(
        f"日別黒字           : {s['positive_days']}/{s['days']}日 "
        f"({s['positive_days']/s['days']*100:.2f}%)"
    )
    print(f"的中時払戻中央値   : {s['hit_return_median']:,.0f}円 / P10={s['hit_return_p10']:,.0f}円 / P90={s['hit_return_p90']:,.0f}円")
    print(f"最大連敗(参考)     : {s['max_loss_streak']}R")
    print(f"最大DD(参考)       : {s['max_drawdown']:,.0f}円")
    if s["roll50"]:
        print(
            f"50RローリングROI   : min={min(s['roll50'])*100:.2f}% / "
            f"median={statistics.median(s['roll50'])*100:.2f}% / max={max(s['roll50'])*100:.2f}%"
        )
    if s["roll100"]:
        print(
            f"100RローリングROI  : min={min(s['roll100'])*100:.2f}% / "
            f"median={statistics.median(s['roll100'])*100:.2f}% / max={max(s['roll100'])*100:.2f}%"
        )
    print(
        f"日単位Bootstrap95% : {s['boot_lo']*100:.2f}% ～ {s['boot_hi']*100:.2f}% "
        f"(median={s['boot_med']*100:.2f}%)"
    )


def print_daily(title, bets):
    daily = daily_rows(bets)
    print(f"\n【{title} 日別】")
    print("日付         購入R 的中 的中率    収支       ROI")
    print("-" * 60)
    for d in daily:
        print(
            f"{d['date']}  {d['bets']:>5d} {d['hits']:>4d} "
            f"{d['hit_rate']*100:>6.2f}%  {d['profit']:>+9,.0f}円  {d['roi']*100:>7.2f}%"
        )


def main():
    if len(sys.argv) != 4:
        print("Usage: python3 analysis/bet_strategy_risk_profile.py DEV_BOATS_CSV F1_BOATS_CSV F2_BOATS_CSV")
        sys.exit(1)

    dev_csv, f1_csv, f2_csv = sys.argv[1], sys.argv[2], sys.argv[3]

    print("固定戦略を再現し、安定性・リスク指標を計算中...")
    d1 = step1.step3.build_common_records(dev_csv, f1_csv)
    d2 = step1.step3.build_common_records(dev_csv, f2_csv)

    dev_records = d1["records"]["P1"]
    f1_records = d1["records"]["P2"]
    f2_records = d2["records"]["P2"]
    if not dev_records or not f1_records or not f2_records:
        raise RuntimeError("DEV/F1/F2の共通評価レースがありません")

    payouts, tri_col, exact_col = step1.load_payouts(d1["p1_start"], d2["p2_end"])
    dev_rows = step1.build_rows(dev_records, payouts)
    f1_rows = step1.build_rows(f1_records, payouts)
    f2_rows = step1.build_rows(f2_records, payouts)

    dev = build_bets(dev_rows)
    f1 = build_bets(f1_rows)
    f2 = build_bets(f2_rows)
    forward = sorted(f1 + f2, key=lambda x: x["race_code"])
    all_bets = sorted(dev + forward, key=lambda x: x["race_code"])

    print("=" * 130)
    print("買い目最適化 STEP 2.5：固定3連単2点戦略 安定性・リスク")
    print("=" * 130)
    print(f"DEV                 : {d1['p1_start']} ～ {d1['p1_end']}")
    print(f"F1 完全前方         : {d1['p2_start']} ～ {d1['p2_end']}")
    print(f"F2 完全前方         : {d2['p2_start']} ～ {d2['p2_end']}")
    print(f"固定条件             : P(1C頭)>=65% × 最終3連単上位2点")
    print(f"固定配分             : 500円 / 500円 = 1R1000円")
    print(f"3連単払戻列         : boat_race.race_payouts.{tri_col}")
    print("再探索               : なし")
    print("過去候補オッズ       : 未使用")

    print_summary("DEV 参考", dev)
    print_summary("F1 完全前方", f1)
    print_summary("F2 完全前方", f2)
    print_summary("F1+F2 前方合算（最重要）", forward)
    print_summary("DEV+前方 参考", all_bets)

    print_daily("F1+F2 前方合算", forward)

    print("\n【判断方針】")
    print("1. ROI 100%超だけでなく、日別・ローリング・Bootstrap区間の広さを見る。")
    print("2. 95%区間が100%を跨ぐ場合は『優位性確定』とは扱わず、実戦候補として継続観察する。")
    print("3. 固定500/500はSTEP2でDEV最適かつF1/F2固定済みなので、ここでは配分を変更しない。")
    print("4. 次段階は現在オッズを使った合成オッズ/モデル期待値の前方記録。過去へ遡って閾値最適化しない。")
    print("5. 本番Web/PredictionLogic/買い目ロジックは変更しない。")
    print("=" * 130)


if __name__ == "__main__":
    main()
