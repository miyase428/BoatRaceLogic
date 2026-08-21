#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
STEP D3：穴警戒HIGHの配当帯をレース前情報だけで識別できるか検証

目的
----
D1/D2では、結果側の配当帯で分けると実頭コース分布に再現性のある差が見えた。
特に、

- MIDDLE（5,000～9,999円）は2～4C頭が多い
- HIGH_PLUS（10,000円以上）は4～6C頭が増える

という傾向がTRAIN→P3で再現した。

ただし実際の払戻はレース前には分からない。
そこでD3では、C2固定の穴警戒HIGHだけを母集団にして、
既存Webでレース前に取得済みの特徴量だけから

- 5,000円以上
- 10,000円以上（主）
- 20,000円以上

を順位付けできるか確認する。

重要
----
- オッズは使わない。
- TRAINで学習し、P3では係数や閾値を調整しない。
- 主評価はAUCと高スコア帯の実発生率（lift）。
- 10,000円モデルについて、P3の予測スコア帯と実頭4～6C率の連動も確認する。
- 本番Web/PredictionLogic/買い目は変更しない。

Usage:
python3 analysis/upset_payout_band_predict_validate.py \
  analysis/output/final_prediction_boats_20260615_20260714_OLD.csv \
  analysis/output/final_prediction_boats_20260715_20260814_OLD.csv \
  analysis/output/final_prediction_boats_20260815_20260820.csv
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
import upset_probability_validate as c1


THRESHOLDS = (5000, 10000, 20000)
PRIMARY_THRESHOLD = 10000


def format_elapsed(seconds: float) -> str:
    sec = max(0, int(round(seconds)))
    m, s = divmod(sec, 60)
    h, m = divmod(m, 60)
    if h:
        return f"{h}時間{m}分{s}秒"
    if m:
        return f"{m}分{s}秒"
    return f"{s}秒"


def stage(label: str, fn):
    print(f"{label}...", flush=True)
    t0 = time.perf_counter()
    result = fn()
    dt = time.perf_counter() - t0
    print(f"{label} 完了 ({format_elapsed(dt)})", flush=True)
    return result


def build_high_rows(records, boats_map, payouts, period):
    rows = []
    skip = defaultdict(int)

    for record in records:
        code = str(record["race_code"])
        boats = boats_map.get(code)
        payout = payouts.get(code)

        if boats is None or set(boats) != set(range(1, 7)):
            skip["csv_missing"] += 1
            continue
        if payout is None or int(payout) <= 0:
            skip["payout_missing"] += 1
            continue

        f = c1.make_features(record, boats)
        if f is None:
            skip["feature_invalid"] += 1
            continue

        current = int(f["current_head"])
        ai_head = int(f["ai_head"])
        in_lane = int(f["in_lane"])
        in_win_p = float(f["values"]["in_win_p"])

        # C2固定HIGH条件。
        if not (ai_head == in_lane and current != in_lane and in_win_p < 0.50):
            skip["not_high"] += 1
            continue

        actual = b4.actual_trifecta(boats)
        if actual is None:
            skip["actual_invalid"] += 1
            continue
        actual_first = int(actual[0])
        course = int(f["course_by_lane"].get(actual_first, 0))

        rows.append({
            "period": period,
            "race_code": code,
            "payout": int(payout),
            "x": list(f["x"]),
            "values": dict(f["values"]),
            "actual_first": actual_first,
            "actual_course": course,
            "in_lane": in_lane,
            "in_failed": actual_first != in_lane,
        })
        skip["ready_high"] += 1

    return rows, skip


def auc_metrics(rows, probs, threshold):
    m = c1.metrics(rows, probs, threshold)
    if m is None:
        return {
            "n": 0,
            "events": 0,
            "rate": 0.0,
            "brier": 0.0,
            "logloss": 0.0,
            "auc": 0.5,
        }
    return m


def quantile_table(title, rows, probs, threshold, buckets=5):
    pairs = sorted(zip(probs, rows), key=lambda x: x[0])
    n = len(pairs)
    print(f"\n【{title}：予測スコア帯（低→高）】")
    print("帯      R数   平均予測   実発生率   実件数")
    print("-" * 56)
    for b in range(buckets):
        lo = n * b // buckets
        hi = n * (b + 1) // buckets
        part = pairs[lo:hi]
        if not part:
            continue
        avgp = sum(p for p, _ in part) / len(part)
        ev = sum(1 for _, r in part if int(r["payout"]) >= threshold)
        rate = ev / len(part)
        print(
            f"Q{b+1:<2}    {len(part):>4d}   {avgp*100:>7.2f}%   "
            f"{rate*100:>7.2f}%   {ev:>4d}"
        )


def top_fraction(rows, probs, threshold, fraction=0.20):
    pairs = sorted(zip(probs, rows), key=lambda x: x[0], reverse=True)
    n = len(pairs)
    k = max(1, int(round(n * fraction))) if n else 0
    part = pairs[:k]
    if not part:
        return {"n": 0, "rate": 0.0, "lift": 0.0, "avgp": 0.0}

    base = sum(1 for r in rows if int(r["payout"]) >= threshold) / n if n else 0.0
    ev = sum(1 for _, r in part if int(r["payout"]) >= threshold)
    rate = ev / len(part)
    return {
        "n": len(part),
        "rate": rate,
        "lift": rate / base if base > 0 else 0.0,
        "avgp": sum(p for p, _ in part) / len(part),
    }


def print_feature_coefficients(model, limit=10):
    items = []
    for i, name in enumerate(c1.FEATURE_NAMES, start=1):
        coef = float(model.coef[i])
        items.append((abs(coef), coef, name))
    items.sort(reverse=True)

    print("\n【10,000円以上モデル：標準化係数 上位】")
    print("特徴                     係数       高配当方向")
    print("-" * 62)
    for _abs_c, coef, name in items[:limit]:
        arrow = "↑" if coef > 0 else "↓"
        print(f"{name:<24} {coef:+9.4f}      {arrow}")


def score_course_link(title, rows, probs, threshold=PRIMARY_THRESHOLD, buckets=3):
    # 1C敗退レースだけで、予測高配当スコアが上がるほど4～6C頭も増えるかを見る。
    pairs = [
        (p, r)
        for p, r in zip(probs, rows)
        if bool(r["in_failed"])
    ]
    pairs.sort(key=lambda x: x[0])
    n = len(pairs)

    print(f"\n【{title}：10,000円予測スコア × 1C敗退時の実頭コース】")
    print("帯      R数   平均予測   >=1万円率   2-3C頭   4C頭   5-6C頭   4-6C頭")
    print("-" * 92)

    for b in range(buckets):
        lo = n * b // buckets
        hi = n * (b + 1) // buckets
        part = pairs[lo:hi]
        if not part:
            continue

        rs = [r for _, r in part]
        avgp = sum(p for p, _ in part) / len(part)
        high_rate = sum(1 for r in rs if int(r["payout"]) >= threshold) / len(rs)
        c23 = sum(1 for r in rs if int(r["actual_course"]) in (2, 3)) / len(rs)
        c4 = sum(1 for r in rs if int(r["actual_course"]) == 4) / len(rs)
        c56 = sum(1 for r in rs if int(r["actual_course"]) in (5, 6)) / len(rs)
        c46 = sum(1 for r in rs if int(r["actual_course"]) in (4, 5, 6)) / len(rs)

        print(
            f"Q{b+1:<2}    {len(rs):>4d}   {avgp*100:>7.2f}%   {high_rate*100:>8.2f}%   "
            f"{c23*100:>6.2f}%   {c4*100:>5.2f}%   {c56*100:>6.2f}%   {c46*100:>6.2f}%"
        )


def main():
    if len(sys.argv) != 4:
        print(
            "Usage: python3 analysis/upset_payout_band_predict_validate.py "
            "P1_BOATS_CSV P2_BOATS_CSV P3_BOATS_CSV"
        )
        sys.exit(1)

    p1_csv, p2_csv, p3_csv = sys.argv[1], sys.argv[2], sys.argv[3]
    total_t0 = time.perf_counter()
    print(f"開始時刻 : {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}", flush=True)

    train_data, future_data = stage(
        "共通レコード構築",
        lambda: (
            step3.build_common_records(p1_csv, p2_csv),
            step3.build_common_records(p2_csv, p3_csv),
        ),
    )

    def load_inputs():
        boats_map = b2.load_boats(p1_csv, p2_csv, p3_csv)
        payouts = b4.load_payouts(train_data["p1_start"], future_data["p2_end"])
        return boats_map, payouts

    boats_map, payouts = stage("CSV・払戻読込", load_inputs)

    p1_records = train_data["records"]["P1"]
    p2_records = train_data["records"]["P2"]
    p3_records = future_data["records"]["P2"]

    def make_rows():
        p1_rows, _ = build_high_rows(p1_records, boats_map, payouts, "P1")
        p2_rows, _ = build_high_rows(p2_records, boats_map, payouts, "P2")
        p3_rows, _ = build_high_rows(p3_records, boats_map, payouts, "P3")
        return p1_rows + p2_rows, p3_rows

    train_rows, p3_rows = stage("穴警戒HIGH行を構築", make_rows)

    print("=" * 126)
    print("STEP D3：穴警戒HIGHの配当帯事前予測検証")
    print("=" * 126)
    print(f"TRAIN : {train_data['p1_start']} ～ {train_data['p2_end']}")
    print(f"P3    : {future_data['p2_start']} ～ {future_data['p2_end']} 完全未来")
    print(f"HIGH対象: TRAIN={len(train_rows)}R / P3={len(p3_rows)}R")
    print("使用情報: オッズなし / レース前の既存AI・一次/二次・最終評価・進入・cutのみ")
    print("主指標: 10,000円以上を中配当側と高配当側に分けられるか")
    print("本番Web/PredictionLogic変更: なし")

    models = {}
    probs_train = {}
    probs_p3 = {}

    def fit_and_score():
        for threshold in THRESHOLDS:
            ys = [1 if int(r["payout"]) >= threshold else 0 for r in train_rows]
            model = c1.LogisticModel(l2=4.0, max_iter=35)
            model.fit([r["x"] for r in train_rows], ys)
            models[threshold] = model
            probs_train[threshold] = [model.predict_proba(r["x"]) for r in train_rows]
            probs_p3[threshold] = [model.predict_proba(r["x"]) for r in p3_rows]

    stage("配当帯モデル学習・P3評価", fit_and_score)

    print("\n【配当閾値ごとの識別性能】")
    print("閾値          TRAIN 発生率  TRAIN AUC  P3 発生率   P3 AUC   P3 Brier   P3上位20%率  Lift")
    print("-" * 108)

    for threshold in THRESHOLDS:
        trm = auc_metrics(train_rows, probs_train[threshold], threshold)
        p3m = auc_metrics(p3_rows, probs_p3[threshold], threshold)
        top = top_fraction(p3_rows, probs_p3[threshold], threshold, 0.20)
        print(
            f">={threshold:>6,d}円   {trm['rate']*100:>8.2f}%    {trm['auc']:>7.3f}    "
            f"{p3m['rate']*100:>8.2f}%   {p3m['auc']:>7.3f}    {p3m['brier']:>8.4f}      "
            f"{top['rate']*100:>8.2f}%   x{top['lift']:.2f}"
        )

    quantile_table("TRAIN 10,000円以上", train_rows, probs_train[PRIMARY_THRESHOLD], PRIMARY_THRESHOLD, 5)
    quantile_table("P3完全未来 10,000円以上", p3_rows, probs_p3[PRIMARY_THRESHOLD], PRIMARY_THRESHOLD, 5)
    print_feature_coefficients(models[PRIMARY_THRESHOLD], 10)

    score_course_link(
        "TRAIN",
        train_rows,
        probs_train[PRIMARY_THRESHOLD],
        PRIMARY_THRESHOLD,
        3,
    )
    score_course_link(
        "P3完全未来",
        p3_rows,
        probs_p3[PRIMARY_THRESHOLD],
        PRIMARY_THRESHOLD,
        3,
    )

    print("\n【判断方針】")
    print("1. 10,000円モデルのP3 AUCが0.5付近なら、配当帯事前予測はまだ使わない")
    print("2. P3でも高スコア帯ほど>=1万円率が上がるかを最重要視する")
    print("3. さらに高スコア帯ほど1C敗退時4～6C頭率が上がれば、D2の頭コース帯と接続できる")
    print("4. 5,000円/20,000円モデルは補助。P3小標本なので単独で閾値は決めない")
    print("5. この段階ではWeb表示・買い目へ接続しない")
    print("=" * 126)

    total_dt = time.perf_counter() - total_t0
    print(f"終了時刻 : {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"総所要時間 : {format_elapsed(total_dt)}")


if __name__ == "__main__":
    main()
