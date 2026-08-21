#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
STEP D2：穴警戒HIGHの配当帯別・実頭特徴分解

目的
----
D1では配当帯ごとの「最良頭候補方式」がTRAIN→P3で安定しなかった一方、
中配当と高配当以上で実1着コース分布には差が見えた。

そこでD2では、頭候補方式をすぐ固定せず、実際に勝った艇が
各レース前指標で何位だったか、どのコース帯から出ていたかを分解する。

対象母集団
----------
C2固定の穴警戒HIGH：
    AI本命 = 1C
    CURRENT本命 != 1C
    インAI1着率 < 50%

配当帯
------
MIDDLE     : 5,000～9,999円
HIGH       : 10,000～19,999円
VERY_HIGH  : 20,000円以上
HIGH_PLUS  : 10,000円以上

評価
----
- 1C敗退レースだけを主対象にする
- 実1着コース帯（2-3C / 4C / 5-6C / 4-6C）
- 実1着艇が各指標で外艇Top1 / Top2に入っていた割合
  WIN / OUTCOME / TRIO / PRIMARY / SECONDARY / FINAL3
- TRAINで最良だったTop2指標をP3へ固定した再現

重要
----
- 結果の払戻で帯分けしているため、まだ本番表示には使わない。
- P3を見て閾値や指標を再調整しない。
- D2は「中配当・高配当で頭になりやすい艇の性質」を診断する段階。
- 本番Web/PredictionLogic/買い目は変更しない。

Usage:
python3 analysis/upset_payout_band_winner_profile.py \
  analysis/output/final_prediction_boats_20260615_20260714_OLD.csv \
  analysis/output/final_prediction_boats_20260715_20260814_OLD.csv \
  analysis/output/final_prediction_boats_20260815_20260820.csv
"""

from __future__ import annotations

import sys
import time
from collections import Counter, defaultdict
from datetime import datetime
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import final_prediction_ai_opponent_compare as b2
import final_prediction_ai_bet_integration_compare as b4
import trifecta_probability_order_compare as step3
import upset_probability_validate as c1


BANDS = (
    ("MIDDLE", lambda p: 5000 <= p < 10000),
    ("HIGH", lambda p: 10000 <= p < 20000),
    ("VERY_HIGH", lambda p: p >= 20000),
    ("HIGH_PLUS", lambda p: p >= 10000),
)

SIGNALS = (
    "WIN",
    "OUTCOME",
    "TRIO",
    "PRIMARY",
    "SECONDARY",
    "FINAL3",
)


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


def ranked_outer(values, in_lane):
    eligible = [lane for lane in range(1, 7) if lane != int(in_lane)]
    return sorted(
        eligible,
        key=lambda lane: (-float(values.get(lane, 0.0)), int(lane)),
    )


def build_row(record, boats, payout):
    if boats is None or set(boats) != set(range(1, 7)):
        return None
    if payout is None or int(payout) <= 0:
        return None

    f = c1.make_features(record, boats)
    if f is None:
        return None

    current = int(f["current_head"])
    ai_head = int(f["ai_head"])
    in_lane = int(f["in_lane"])
    in_win_p = float(f["values"]["in_win_p"])

    if not (ai_head == in_lane and current != in_lane and in_win_p < 0.50):
        return None

    actual = b4.actual_trifecta(boats)
    if actual is None:
        return None
    actual_first = int(actual[0])
    in_failed = actual_first != in_lane

    primary = {lane: float(boats[lane].get("first_score", 0.0)) for lane in range(1, 7)}
    secondary = {lane: float(boats[lane].get("second_score", 0.0)) for lane in range(1, 7)}
    final3 = {lane: float(boats[lane].get("final3", 0.0)) for lane in range(1, 7)}

    raw = {
        "WIN": f["win"],
        "OUTCOME": f["outcome_head"],
        "TRIO": f["trio"],
        "PRIMARY": primary,
        "SECONDARY": secondary,
        "FINAL3": final3,
    }
    rankings = {name: ranked_outer(vals, in_lane) for name, vals in raw.items()}

    return {
        "race_code": str(record["race_code"]),
        "payout": int(payout),
        "in_lane": in_lane,
        "in_failed": in_failed,
        "actual_first": actual_first,
        "actual_course": int(f["course_by_lane"].get(actual_first, 0)),
        "rankings": rankings,
    }


def build_rows(records, boats_map, payouts):
    rows = []
    skip = defaultdict(int)
    for record in records:
        code = str(record["race_code"])
        row = build_row(record, boats_map.get(code), payouts.get(code))
        if row is None:
            skip["not_ready_or_not_high"] += 1
            continue
        rows.append(row)
        skip["ready_high"] += 1
    return rows, skip


def only_band(rows, pred):
    return [r for r in rows if pred(int(r["payout"])) and r["in_failed"]]


def course_profile(rows):
    n = len(rows)
    c = Counter(int(r["actual_course"]) for r in rows)
    def rate(courses):
        return sum(c.get(x, 0) for x in courses) / n if n else 0.0
    return {
        "n": n,
        "2_3": rate((2, 3)),
        "4": rate((4,)),
        "5_6": rate((5, 6)),
        "2_4": rate((2, 3, 4)),
        "4_6": rate((4, 5, 6)),
        "counts": c,
    }


def signal_capture(rows, signal):
    n = len(rows)
    top1 = 0
    top2 = 0
    rank_sum = 0.0
    for r in rows:
        rank = list(r["rankings"][signal])
        winner = int(r["actual_first"])
        if winner not in rank:
            continue
        pos = rank.index(winner) + 1
        rank_sum += pos
        if pos <= 1:
            top1 += 1
        if pos <= 2:
            top2 += 1
    return {
        "n": n,
        "top1": top1 / n if n else 0.0,
        "top2": top2 / n if n else 0.0,
        "avg_rank": rank_sum / n if n else 0.0,
    }


def print_course_table(title, rows):
    print(f"\n【{title}：1C敗退時の実頭コース帯】")
    print("配当帯       R数    2-3C     4C     5-6C    2-4C    4-6C")
    print("-" * 76)
    out = {}
    for label, pred in BANDS:
        part = only_band(rows, pred)
        m = course_profile(part)
        out[label] = m
        print(
            f"{label:<11} {m['n']:>4d}  {m['2_3']*100:>6.2f}%  {m['4']*100:>6.2f}%  "
            f"{m['5_6']*100:>6.2f}%  {m['2_4']*100:>6.2f}%  {m['4_6']*100:>6.2f}%"
        )
    return out


def print_signal_table(title, rows):
    all_results = {}
    for band_label, pred in BANDS:
        part = only_band(rows, pred)
        print(f"\n--- {title} {band_label}  1C敗退={len(part)}R ---")
        print("指標         Top1捕捉   Top2捕捉   実頭平均順位")
        print("-" * 58)
        band_result = {}
        for signal in SIGNALS:
            m = signal_capture(part, signal)
            band_result[signal] = m
            print(
                f"{signal:<11} {m['top1']*100:>7.2f}%   {m['top2']*100:>7.2f}%      {m['avg_rank']:>6.3f}位"
            )
        all_results[band_label] = band_result
    return all_results


def train_best_top2(results, band):
    items = []
    for signal, m in results[band].items():
        items.append((
            -m["top2"],
            -m["top1"],
            m["avg_rank"],
            signal,
        ))
    items.sort()
    return items[0][3]


def print_reproduction(train, p3):
    print("\n【TRAINで選んだTop2指標をP3へ固定】")
    print("配当帯       TRAIN選択   TRAIN Top1  TRAIN Top2   P3 Top1   P3 Top2")
    print("-" * 88)
    for band, _pred in BANDS:
        signal = train_best_top2(train, band)
        tr = train[band][signal]
        te = p3[band][signal]
        print(
            f"{band:<11} {signal:<11}   {tr['top1']*100:>7.2f}%    {tr['top2']*100:>7.2f}%   "
            f"{te['top1']*100:>7.2f}%   {te['top2']*100:>7.2f}%"
        )


def main():
    if len(sys.argv) != 4:
        print(
            "Usage: python3 analysis/upset_payout_band_winner_profile.py "
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

    def make_all_rows():
        p1_rows, _ = build_rows(p1_records, boats_map, payouts)
        p2_rows, _ = build_rows(p2_records, boats_map, payouts)
        p3_rows, _ = build_rows(p3_records, boats_map, payouts)
        return p1_rows + p2_rows, p3_rows

    train_rows, p3_rows = stage("穴警戒HIGH行を構築", make_all_rows)

    print("=" * 126)
    print("STEP D2：穴警戒HIGHの配当帯別・実頭特徴分解")
    print("=" * 126)
    print(f"TRAIN : {train_data['p1_start']} ～ {train_data['p2_end']}")
    print(f"P3    : {future_data['p2_start']} ～ {future_data['p2_end']} 完全未来")
    print(f"HIGH対象: TRAIN={len(train_rows)}R / P3={len(p3_rows)}R")
    print("主評価: 各配当帯の1C敗退レースで、実頭のコース帯と各指標Top1/Top2捕捉を見る")
    print("本番Web/PredictionLogic変更: なし")

    print_course_table("TRAIN", train_rows)
    print_course_table("P3完全未来", p3_rows)

    train_signal = print_signal_table("TRAIN", train_rows)
    p3_signal = print_signal_table("P3", p3_rows)
    print_reproduction(train_signal, p3_signal)

    print("\n【判断方針】")
    print("1. D1の最良Top1方式が不安定だったので、D2ではTop2捕捉と実頭コース帯を重視する")
    print("2. MIDDLEで2～4C優勢、VERY_HIGHで4～6C優勢がTRAIN→P3で再現するか確認する")
    print("3. TRAINで選んだTop2指標がP3でも高捕捉なら、配当帯別の頭候補集合に使える")
    print("4. P3小標本の単独Top1率だけでは採用しない")
    print("5. この段階では配当帯そのものを事前予測していないためWeb表示にはまだ使わない")
    print("=" * 126)

    total_dt = time.perf_counter() - total_t0
    print(f"終了時刻 : {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"総所要時間 : {format_elapsed(total_dt)}")


if __name__ == "__main__":
    main()
