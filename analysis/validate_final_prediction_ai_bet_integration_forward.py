#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
120通り出目確率を使った既存B4買い目統合を、固定ルールのままP3へ前方検証する。

目的
----
既存の final_prediction_ai_bet_integration_compare.py (B4-1) を作り直さず、
P1でcut救済閾値を選び、P2とP3では一切再選択せずに再現性を見る。

比較
----
CURRENT
    現行本命買い目。
WIN_HEAD
    本命だけ補正後1着率1位へ変更。
WIN_HEAD_OUTCOME_AITE
    補正後1着率1位を頭にし、STEP3 120通り出目確率の
    P(2着|頭)上位最大3艇を2着候補にする。cutは現行固定。
AI_FULL_Rxx
    上記に加え、P1で選んだ艇別3連対周辺確率thresholdでcut救済。

重要
----
- P1でthresholdを選択する。
- P2/P3ではthreshold・方式を変更しない。
- P3を見て閾値を動かさない。
- 本番Web/PredictionLogicは変更しない。

Usage:
python3 analysis/validate_final_prediction_ai_bet_integration_forward.py \
  analysis/output/final_prediction_boats_20260615_20260714_OLD.csv \
  analysis/output/final_prediction_boats_20260715_20260814_OLD.csv \
  analysis/output/final_prediction_boats_fast_cached_20260815_20260822.csv
"""

from __future__ import annotations

import sys
import time
from collections import defaultdict
from datetime import datetime
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import final_prediction_ai_opponent_compare as b2
import final_prediction_ai_bet_integration_compare as b4
import trifecta_probability_order_compare as step3


def fmt_elapsed(seconds: float) -> str:
    sec = max(0, int(round(seconds)))
    m, s = divmod(sec, 60)
    h, m = divmod(m, 60)
    if h:
        return f"{h}時間{m}分{s}秒"
    if m:
        return f"{m}分{s}秒"
    return f"{s}秒"


def stage(label, fn):
    print(f"{label}...", flush=True)
    t0 = time.perf_counter()
    result = fn()
    print(f"{label} 完了 ({fmt_elapsed(time.perf_counter() - t0)})", flush=True)
    return result


def build_period(records, boats_map, payouts):
    rows = []
    skip = defaultdict(int)

    for record in records:
        code = str(record["race_code"])
        boats = boats_map.get(code)
        if boats is None or set(boats) != set(range(1, 7)):
            skip["csv_missing"] += 1
            continue

        actual = b4.actual_trifecta(boats)
        if actual is None:
            skip["actual_invalid"] += 1
            continue

        payout = payouts.get(code)
        if payout is None or int(payout) <= 0:
            skip["payout_missing"] += 1
            continue

        cur = b4.current_bets(boats)
        if cur is None or not cur.get("bets"):
            skip["current_empty"] += 1
            continue

        _win, _trio, _outcome_head, win_head, outcome_top3 = b4.ai_signals(record)
        win_only = b4.make_win_head_bets(boats, win_head)
        outcome = b4.make_outcome_bets(record, boats, win_head, outcome_top3, None)
        if win_only is None or outcome is None or not win_only.get("bets") or not outcome.get("bets"):
            skip["ai_empty"] += 1
            continue

        scenarios = {
            "CURRENT": cur,
            "WIN_HEAD": win_only,
            "WIN_HEAD_OUTCOME_AITE": outcome,
        }

        complete = True
        for th in b4.RESCUE_THRESHOLDS:
            s = b4.make_outcome_bets(record, boats, win_head, outcome_top3, th)
            if s is None or not s.get("bets"):
                complete = False
                break
            scenarios[f"AI_FULL_R{int(th * 100)}"] = s

        if not complete:
            skip["scenario_incomplete"] += 1
            continue

        rows.append({
            "race_code": code,
            "actual": tuple(int(x) for x in actual),
            "payout": int(payout),
            "scenarios": scenarios,
        })
        skip["ready"] += 1

    return rows, skip


def print_metrics(title, rows, names):
    print(f"\n【{title}】")
    print("方式                         R数   平均点数   的中率   100円/点ROI   1000円均等ROI   拾い   失い")
    print("-" * 108)
    out = {}
    for name in names:
        r = b4.evaluate(rows, name)
        out[name] = r
        _changed, gained, lost, _both, _neither = b4.compare_hits(rows, name)
        print(
            f"{name:<28} {r['n']:>5d}   {r['avg_points']:>7.2f}   "
            f"{r['hit_rate']*100:>6.2f}%     {r['roi_per_point']*100:>8.2f}%       "
            f"{r['roi_fixed']*100:>8.2f}%   {gained:>4d}   {lost:>4d}"
        )
    return out


def delta(base, new):
    return {
        "points": new["avg_points"] - base["avg_points"],
        "hit": (new["hit_rate"] - base["hit_rate"]) * 100.0,
        "roi100": (new["roi_per_point"] - base["roi_per_point"]) * 100.0,
        "roi_fixed": (new["roi_fixed"] - base["roi_fixed"]) * 100.0,
    }


def print_delta(title, results, target):
    d = delta(results["CURRENT"], results[target])
    print(
        f"{title:<10} 点数 {d['points']:+.2f} / 的中 {d['hit']:+.2f}pt / "
        f"100円ROI {d['roi100']:+.2f}pt / 1000円ROI {d['roi_fixed']:+.2f}pt"
    )


def main():
    if len(sys.argv) != 4:
        print(
            "Usage: python3 analysis/validate_final_prediction_ai_bet_integration_forward.py "
            "P1_BOATS P2_BOATS P3_BOATS"
        )
        sys.exit(1)

    p1_csv, p2_csv, p3_csv = sys.argv[1:]
    total_t0 = time.perf_counter()
    print(f"開始時刻 : {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}", flush=True)

    train_data, future_data = stage(
        "共通レコード構築",
        lambda: (
            step3.build_common_records(p1_csv, p2_csv),
            step3.build_common_records(p2_csv, p3_csv),
        ),
    )

    boats_map = stage("CSV読込", lambda: b2.load_boats(p1_csv, p2_csv, p3_csv))
    payouts = stage(
        "払戻読込",
        lambda: b4.load_payouts(train_data["p1_start"], future_data["p2_end"]),
    )

    def make_rows():
        p1_rows, p1_skip = build_period(train_data["records"]["P1"], boats_map, payouts)
        p2_rows, p2_skip = build_period(train_data["records"]["P2"], boats_map, payouts)
        p3_rows, p3_skip = build_period(future_data["records"]["P2"], boats_map, payouts)
        return p1_rows, p2_rows, p3_rows, p1_skip, p2_skip, p3_skip

    p1_rows, p2_rows, p3_rows, p1_skip, p2_skip, p3_skip = stage("買い目行構築", make_rows)

    # P1だけでcut救済thresholdを選択。
    p1_all = {
        "CURRENT": b4.evaluate(p1_rows, "CURRENT"),
        "WIN_HEAD": b4.evaluate(p1_rows, "WIN_HEAD"),
        "WIN_HEAD_OUTCOME_AITE": b4.evaluate(p1_rows, "WIN_HEAD_OUTCOME_AITE"),
    }
    for th in b4.RESCUE_THRESHOLDS:
        name = f"AI_FULL_R{int(th * 100)}"
        p1_all[name] = b4.evaluate(p1_rows, name)

    selected_th, selected_name = b4.select_p1_threshold(p1_all)
    names = ["CURRENT", "WIN_HEAD", "WIN_HEAD_OUTCOME_AITE", selected_name]

    print("=" * 118)
    print("120通り出目確率 × 現行買い目統合：P1選択 / P2・P3固定前方検証")
    print("=" * 118)
    print(f"P1 : {train_data['p1_start']} ～ {train_data['p1_end']}")
    print(f"P2 : {train_data['p2_start']} ～ {train_data['p2_end']}")
    print(f"P3 : {future_data['p2_start']} ～ {future_data['p2_end']} 前方確認")
    print("P1でのみcut救済thresholdを選択。P2/P3では再選択しない。")
    print(f"P1選択threshold: {selected_th:.2f} → {selected_name}")
    print("本番Web/PredictionLogic変更: なし")

    p1_res = print_metrics("P1", p1_rows, names)
    p2_res = print_metrics("P2固定", p2_rows, names)
    p3_res = print_metrics("P3固定前方", p3_rows, names)

    print("\n【CURRENTとの差：120通り相手化】")
    for label, res in (("P1", p1_res), ("P2", p2_res), ("P3", p3_res)):
        print_delta(label, res, "WIN_HEAD_OUTCOME_AITE")

    print(f"\n【CURRENTとの差：P1固定 {selected_name}】")
    for label, res in (("P1", p1_res), ("P2", p2_res), ("P3", p3_res)):
        print_delta(label, res, selected_name)

    print("\n【join参考】")
    print("P1:", dict(p1_skip))
    print("P2:", dict(p2_skip))
    print("P3:", dict(p3_skip))

    print("\n【判断ポイント】")
    print("1. WIN_HEAD_OUTCOME_AITEがP2/P3ともCURRENTより的中率またはROIを改善するか")
    print("2. P1で選んだcut救済thresholdがP2/P3でも同方向に再現するか")
    print("3. P3を見てthresholdを変更しない")
    print("4. 再現しなければ120通り確率は表示用のまま維持し、現行買い目へ接続しない")
    print("=" * 118)
    print(f"総所要時間 : {fmt_elapsed(time.perf_counter() - total_t0)}", flush=True)


if __name__ == "__main__":
    main()
