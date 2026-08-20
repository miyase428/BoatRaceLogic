#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
最終予想 AI活用 STEP B5：完全未来期間検証

目的
----
STEP B1～B4で決めたAI活用ルールを一切再調整せず、
直後の未来期間で再現するか確認する。

固定する採用候補
----------------
本命
    補正後1着率1位（WIN_HEAD）

2着候補
    STEP3最終出目モデル P(2着 | 本命頭) 上位最大3艇
    （WIN_HEAD_OUTCOME_AITE）

cut
    現行kiruをそのまま使用。AI救済はしない。

出目モデル固定値
----------------
- VENUE_K3000
- win alpha = 1.00
- trio beta = 1.25
- order delta = 0.25
- order gamma = 0.25

重要
----
- 第1引数は直前までの既存検証CSV（例: 2026-07-15～2026-08-14）。
- 第2引数が完全未来CSV（例: 2026-08-15～2026-08-19）。
- 既存のbuild_common_records()上では第2引数側をP2として構築するが、
  このスクリプトではそれをP3（完全未来）としてだけ評価する。
- P3の結果を使った閾値・係数の再選択は行わない。
- 本番Web/PredictionLogicは変更しない。

Usage:
python3 analysis/final_prediction_ai_future_validate.py \
  analysis/output/final_prediction_boats_20260715_20260814_OLD.csv \
  analysis/output/final_prediction_boats_20260815_20260819.csv
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import final_prediction_ai_favorite_compare as b1
import final_prediction_ai_opponent_compare as b2
import final_prediction_ai_bet_integration_compare as b4
import trifecta_probability_order_compare as step3


def empty_favorite_stats():
    return {
        "n": 0,
        "r1": 0,
        "r2": 0,
        "r3": 0,
        "rank_sum": 0.0,
    }


def add_favorite(stats, actual_rank):
    if actual_rank <= 0:
        return
    stats["n"] += 1
    stats["rank_sum"] += actual_rank
    if actual_rank <= 1:
        stats["r1"] += 1
    if actual_rank <= 2:
        stats["r2"] += 1
    if actual_rank <= 3:
        stats["r3"] += 1


def favorite_summary(stats):
    n = stats["n"]
    if n <= 0:
        return {
            "n": 0,
            "r1": 0.0,
            "r2": 0.0,
            "r3": 0.0,
            "avg": 0.0,
        }
    return {
        "n": n,
        "r1": stats["r1"] / n,
        "r2": stats["r2"] / n,
        "r3": stats["r3"] / n,
        "avg": stats["rank_sum"] / n,
    }


def evaluate_future_favorite(records, csv_races):
    current_stats = empty_favorite_stats()
    win_stats = empty_favorite_stats()

    changed = 0
    better = 0
    worse = 0
    same = 0
    picked_win = 0
    lost_win = 0
    skipped = 0

    for record in records:
        code = str(record["race_code"])
        boats = csv_races.get(code)
        if boats is None or set(boats) != set(range(1, 7)):
            skipped += 1
            continue

        rank_boats, current_head = b2.current_order_and_head(boats)
        if not rank_boats or current_head is None:
            skipped += 1
            continue

        win, _trio, _outcome_head = b1.marginal_signals(record)
        win_head, _win_p, _win_gap = b1.top_info(win)

        current_actual = float(boats[current_head].get("actual_rank", 0.0))
        win_actual = float(boats[win_head].get("actual_rank", 0.0))

        # 公平比較に必要な2艇の着順だけ必須。
        if current_actual <= 0 or win_actual <= 0:
            skipped += 1
            continue

        add_favorite(current_stats, current_actual)
        add_favorite(win_stats, win_actual)

        if int(current_head) != int(win_head):
            changed += 1
            if win_actual < current_actual:
                better += 1
            elif win_actual > current_actual:
                worse += 1
            else:
                same += 1

            if win_actual == 1 and current_actual != 1:
                picked_win += 1
            if current_actual == 1 and win_actual != 1:
                lost_win += 1

    return {
        "current": favorite_summary(current_stats),
        "win": favorite_summary(win_stats),
        "changed": changed,
        "better": better,
        "worse": worse,
        "same": same,
        "picked_win": picked_win,
        "lost_win": lost_win,
        "skipped": skipped,
    }


def print_favorite(result):
    print("\n【P3 本命選定】")
    print("方式             R数    1着率    2連対    3連対   平均着順")
    print("-" * 72)
    for label, key in (("CURRENT", "current"), ("WIN_HEAD", "win")):
        s = result[key]
        print(
            f"{label:<16} {s['n']:>5d}  "
            f"{s['r1']*100:>6.2f}%  {s['r2']*100:>6.2f}%  "
            f"{s['r3']*100:>6.2f}%   {s['avg']:>6.3f}"
        )

    c = result["current"]
    w = result["win"]
    print("\nCURRENT → WIN_HEAD")
    print(f"1着率差     : {(w['r1'] - c['r1'])*100:+.2f}pt")
    print(f"2連対差     : {(w['r2'] - c['r2'])*100:+.2f}pt")
    print(f"3連対差     : {(w['r3'] - c['r3'])*100:+.2f}pt")
    print(f"平均着順差  : {w['avg'] - c['avg']:+.3f} （マイナスが改善）")
    print(f"本命変更     : {result['changed']}R")
    print(
        f"変更で上位化 : {result['better']}R / 下位化 : {result['worse']}R "
        f"/ 同着 : {result['same']}R"
    )
    print(
        f"1着を拾う    : {result['picked_win']}R / "
        f"1着を失う : {result['lost_win']}R"
    )
    print(f"本命比較skip : {result['skipped']}R")


def print_bets(rows):
    scenarios = (
        "CURRENT",
        "WIN_HEAD",
        "WIN_HEAD_OUTCOME_AITE",
    )

    print("\n【P3 3連単買い目（最重要）】")
    print(
        "方式                         R数   平均点数   的中率    "
        "100円/点ROI   1000円均等ROI   買目変更   拾い  失い"
    )
    print("-" * 122)

    results = {}
    for name in scenarios:
        r = b4.evaluate(rows, name)
        results[name] = r
        ch, gain, lost, _both, _neither = b4.compare_hits(rows, name)
        print(
            f"{name:<28} {r['n']:>5d}   {r['avg_points']:>7.2f}   "
            f"{r['hit_rate']*100:>6.2f}%     {r['roi_per_point']*100:>8.2f}%       "
            f"{r['roi_fixed']*100:>8.2f}%   {ch:>7d}  {gain:>5d} {lost:>5d}"
        )

    current = results["CURRENT"]
    win = results["WIN_HEAD"]
    full = results["WIN_HEAD_OUTCOME_AITE"]

    print("\n【P3 CURRENT → WIN_HEAD】")
    print(f"平均点数差      : {win['avg_points'] - current['avg_points']:+.2f}点/R")
    print(f"的中率差        : {(win['hit_rate'] - current['hit_rate'])*100:+.2f}pt")
    print(f"100円/点ROI差   : {(win['roi_per_point'] - current['roi_per_point'])*100:+.2f}pt")
    print(f"1000円均等ROI差 : {(win['roi_fixed'] - current['roi_fixed'])*100:+.2f}pt")

    print("\n【P3 CURRENT → WIN_HEAD_OUTCOME_AITE】")
    print(f"平均点数差      : {full['avg_points'] - current['avg_points']:+.2f}点/R")
    print(f"的中率差        : {(full['hit_rate'] - current['hit_rate'])*100:+.2f}pt")
    print(f"100円/点ROI差   : {(full['roi_per_point'] - current['roi_per_point'])*100:+.2f}pt")
    print(f"1000円均等ROI差 : {(full['roi_fixed'] - current['roi_fixed'])*100:+.2f}pt")

    return results


def main():
    if len(sys.argv) != 3:
        print(
            "Usage: python3 analysis/final_prediction_ai_future_validate.py "
            "HISTORY_BOATS_CSV FUTURE_BOATS_CSV"
        )
        sys.exit(1)

    history_csv, future_csv = sys.argv[1], sys.argv[2]

    print("固定済みAIモデルを直前期間+完全未来期間で再構築中...")
    data = step3.build_common_records(history_csv, future_csv)

    future_records = data["records"]["P2"]
    if not future_records:
        raise RuntimeError("完全未来期間の共通AI評価レースがありません")

    csv_races = b2.load_boats(history_csv, future_csv)

    favorite = evaluate_future_favorite(future_records, csv_races)

    payouts = b4.load_payouts(data["p1_start"], data["p2_end"])
    bet_rows, bet_skip = b4.build_rows(data["records"], csv_races, payouts)
    future_bets = bet_rows["P2"]
    if not future_bets:
        raise RuntimeError("完全未来期間の3連単買い目評価レースがありません")

    print("=" * 126)
    print("最終予想 AI活用 STEP B5：完全未来期間検証")
    print("=" * 126)
    print(f"直前履歴CSV期間       : {data['p1_start']} ～ {data['p1_end']}")
    print(f"P3 完全未来期間       : {data['p2_start']} ～ {data['p2_end']}")
    print("本命                 : 補正後1着率1位（WIN_HEAD）")
    print("相手                 : STEP3 P(2着|WIN_HEAD) 上位最大3艇")
    print("cut                  : 現行kiru固定 / AI救済なし")
    print("出目モデル           : K3000 / alpha=1 / beta=1.25 / delta=.25 / gamma=.25 固定")
    print("P3での再調整         : 一切なし")
    print("本番Web変更          : なし")
    print(
        f"\n【P3母集団】AI共通={len(future_records)}R "
        f"/ 3連単払戻・着順込み={len(future_bets)}R"
    )

    print_favorite(favorite)
    results = print_bets(future_bets)

    current = results["CURRENT"]
    win = results["WIN_HEAD"]
    full = results["WIN_HEAD_OUTCOME_AITE"]

    print("\n【判断方針】")
    print("1. P3でもWIN_HEADの本命1着率・2連対・3連対・平均着順がCURRENTより改善するか")
    print("2. P3でもWIN_HEADの3連単的中率とROIがCURRENTに対して維持または改善するか")
    print("3. OUTCOME_AITE追加で的中率が上がる一方、ROIを大きく壊していないか")
    print("4. P3は短期なのでROI単独の上下より、本命精度・的中率の再現性を重視する")
    print("5. B1～B4と同方向なら、本命=WIN_HEAD / 相手=OUTCOME_AITE / cut=現行で本番実装候補とする")
    print("6. 2連単はB4-2結論どおり自動買い目化せず、予想補助表示のままとする")

    # 自動判定は参考だけ。短期ROIだけで採否を決めない。
    favorite_ok = (
        favorite["win"]["r1"] > favorite["current"]["r1"]
        and favorite["win"]["avg"] < favorite["current"]["avg"]
    )
    hit_ok = full["hit_rate"] > current["hit_rate"]
    print("\n【簡易再現チェック（参考）】")
    print(f"本命改善方向 : {'OK' if favorite_ok else '要確認'}")
    print(f"統合的中改善 : {'OK' if hit_ok else '要確認'}")
    print("※短期P3のROIだけでは採否を自動決定しない")
    print("=" * 126)


if __name__ == "__main__":
    main()
