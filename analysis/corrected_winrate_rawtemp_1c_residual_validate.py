#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
RAW_TEMP後に残る1C 20～40%帯の較正ズレを切り分ける診断。

目的
----
RAW_TEMP後、1C全体と40%以上帯は大きく改善した一方、
1C 30～40%帯には負のcalibration gapが残る。
この残差が
  A) 1CがTop1のレースで起きているのか
  B) 1Cが2位以下のレースで起きているのか
  C) 特定の基本raw合計帯に集中しているのか
を確認する。

本番Webは変更しない。GLOBAL tau / RAW k は評価期間直前31日だけで選択。

Usage:
  python3 analysis/corrected_winrate_rawtemp_1c_residual_validate.py 2026-06-15 2026-07-14
  python3 analysis/corrected_winrate_rawtemp_1c_residual_validate.py 2026-07-15 2026-08-14
"""

from __future__ import annotations

import sys
from collections import defaultdict
from datetime import datetime, timedelta
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import corrected_winrate_rawtotal_temperature_compare as temp
import corrected_winrate_rawtemp_course_validate as coursev

CALIB_DAYS = 31
RAW_BINS = (
    (0.00, 0.20),
    (0.20, 0.30),
    (0.30, 0.40),
    (0.40, 0.50),
    (0.50, 0.60),
    (0.60, 1.0000001),
)
TOTAL_BINS = (
    (0.00, 0.90),
    (0.90, 1.00),
    (1.00, 1.10),
    (1.10, 10.0),
)


def parse_date(value: str):
    return datetime.strptime(value, "%Y-%m-%d").date()


def in_bin(x: float, low: float, high: float) -> bool:
    return low <= x < high


def band(low: float, high: float, pct=True) -> str:
    if high >= 9.0:
        return f"{low*100:.0f}%+"
    return f"{low*100:.0f}-{high*100:.0f}%"


def summarize(rows):
    if not rows:
        return None
    n = len(rows)
    wins = sum(int(r["y"]) for r in rows)
    pred = sum(float(r["raw"]) for r in rows) / n
    actual = wins / n
    return n, wins, pred, actual, actual - pred


def add_raw_rank(rows):
    by_race = defaultdict(list)
    for r in rows:
        by_race[r["race_code"]].append(r)

    for race_rows in by_race.values():
        order = sorted(
            race_rows,
            key=lambda r: (-float(r["raw"]), int(r["lane"]))
        )
        for rank, r in enumerate(order, start=1):
            r["raw_rank"] = rank


def print_summary(one):
    print("\n【1. 1C RAW_TEMP表示帯：残差の全体像】")
    print("RAW表示帯      舟数  勝数    RAW平均    実1着率    実績-表示    Top1比率")
    print("-" * 86)
    for low, high in RAW_BINS:
        selected = [r for r in one if in_bin(r["raw"], low, high)]
        s = summarize(selected)
        if s is None:
            continue
        n, wins, pred, actual, gap = s
        top_share = sum(int(r["raw_rank"] == 1) for r in selected) / n
        print(
            f"{band(low, high):<11} {n:>6} {wins:>5}    {pred*100:>7.2f}%    "
            f"{actual*100:>7.2f}%    {gap*100:+8.2f}pt    {top_share*100:>7.2f}%"
        )


def rank_group(rank: int) -> str:
    if rank == 1:
        return "Top1"
    if rank == 2:
        return "Rank2"
    return "Rank3+"


def print_rank_split(one):
    print("\n【2. 1C低中確率帯 × RAW_TEMP順位】")
    print("対象帯       順位群    舟数  勝数    RAW平均    実1着率    実績-表示")
    print("-" * 82)
    for low, high in ((0.20, 0.30), (0.30, 0.40)):
        target = [r for r in one if in_bin(r["raw"], low, high)]
        for group in ("Top1", "Rank2", "Rank3+"):
            selected = [r for r in target if rank_group(int(r["raw_rank"])) == group]
            s = summarize(selected)
            if s is None:
                continue
            n, wins, pred, actual, gap = s
            print(
                f"{band(low, high):<10} {group:<7} {n:>6} {wins:>5}    "
                f"{pred*100:>7.2f}%    {actual*100:>7.2f}%    {gap*100:+8.2f}pt"
            )


def print_total_split(one):
    print("\n【3. 1C RAW 30～40% × 基本raw6艇合計】")
    print("raw合計帯     舟数  勝数   平均raw合計   RAW平均    実1着率    実績-表示   Top1比率")
    print("-" * 100)
    target = [r for r in one if in_bin(r["raw"], 0.30, 0.40)]
    for low, high in TOTAL_BINS:
        selected = [r for r in target if in_bin(r["raw_total"], low, high)]
        s = summarize(selected)
        if s is None:
            continue
        n, wins, pred, actual, gap = s
        avg_total = sum(float(r["raw_total"]) for r in selected) / n
        top_share = sum(int(r["raw_rank"] == 1) for r in selected) / n
        label = f"{low*100:.0f}-{high*100:.0f}%" if high < 9.0 else f"{low*100:.0f}%+"
        print(
            f"{label:<12} {n:>6} {wins:>5}      {avg_total*100:>7.2f}%    "
            f"{pred*100:>7.2f}%    {actual*100:>7.2f}%    {gap*100:+8.2f}pt   {top_share*100:>7.2f}%"
        )


def print_top1_focus(one):
    print("\n【4. 1CがRAW_TEMP Top1のときだけ】")
    print("RAW表示帯      R数   的中    RAW平均    実Top1率    実績-表示")
    print("-" * 74)
    top = [r for r in one if int(r["raw_rank"]) == 1]
    for low, high in RAW_BINS:
        selected = [r for r in top if in_bin(r["raw"], low, high)]
        s = summarize(selected)
        if s is None:
            continue
        n, wins, pred, actual, gap = s
        print(
            f"{band(low, high):<11} {n:>6} {wins:>6}    {pred*100:>7.2f}%    "
            f"{actual*100:>7.2f}%    {gap*100:+8.2f}pt"
        )


def main():
    if len(sys.argv) != 3:
        print(
            "Usage: python3 analysis/corrected_winrate_rawtemp_1c_residual_validate.py "
            "YYYY-MM-DD YYYY-MM-DD"
        )
        sys.exit(1)

    eval_start = parse_date(sys.argv[1])
    eval_end = parse_date(sys.argv[2])
    if eval_start > eval_end:
        raise RuntimeError("開始日が終了日より後です")

    calib_end = eval_start - timedelta(days=1)
    calib_start = eval_start - timedelta(days=CALIB_DAYS)

    print("1C残差診断データを構築しています...")
    print(f"較正期間: {calib_start} ～ {calib_end} ({CALIB_DAYS}日)")
    print(f"評価期間: {eval_start} ～ {eval_end}")

    print("\n[1/2] 較正期間でtau/kを選択中...")
    calib_rows, _ = temp.build_rows(calib_start, calib_end)
    global_tau, _, _ = temp.tune_global(calib_rows)
    raw_k, _, _ = temp.tune_raw(calib_rows, global_tau)

    print("\n[2/2] 評価期間をcourse付きで構築中...")
    boat_rows, race_rows, meta = coursev.build_eval_rows(
        eval_start, eval_end, global_tau, raw_k
    )
    if not boat_rows:
        raise RuntimeError("評価可能データがありません")

    add_raw_rank(boat_rows)
    one = [r for r in boat_rows if int(r["course"]) == 1]

    print("\n" + "=" * 112)
    print("補正後1着率：RAW_TEMP後 1C低中確率帯の残差診断")
    print("=" * 112)
    print(f"較正期間       : {calib_start} ～ {calib_end} ({len(calib_rows)}R)")
    print(f"評価期間       : {eval_start} ～ {eval_end} ({len(race_rows)}R)")
    print(f"GLOBAL tau     : {global_tau:.2f}")
    print(f"RAW k          : {raw_k:.2f}")
    print(f"1C舟数          : {len(one)}")
    print(f"Top1順位変更   : {meta['top_changed']}R")
    print("本番変更       : なし")

    print_summary(one)
    print_rank_split(one)
    print_total_split(one)
    print_top1_focus(one)

    print("\n【5. 判定の見方】")
    print("・1C 30～40%の負gapがTop1だけに集中 → 本命確率として追加対策を検討")
    print("・Rank2以下に集中 → 順位付けは保ちつつ表示較正の問題として扱う")
    print("・特定raw合計帯だけに集中 → RAW_TEMP式の残差であり、1C一律補正は避ける")
    print("・raw合計帯を跨いで2期間とも同程度 → 1C低中確率固有の較正候補")
    print("・この診断でも本番式は変更しない。2期間の再現性を確認して最終判断する")
    print("=" * 112)


if __name__ == "__main__":
    main()
