#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""AMG/TKY向け 補正後1着率の展示補正検証。

尼崎(AMG)・徳山(TKY)は historical exhibition_live の straight_time が
実質欠損しているため、既存EX_TOTALをそのまま評価できない。

本スクリプトでは本番ロジックを変更せず、次の固定案だけを検証する。

    EX_TOTAL3 = 展示タイム評価 + 周回評価 + 周り足評価
    beta = 0.10 固定

基本1着率・展示進入リマップ・展示タイム場平均などは
base_winrate_exhibition_compare.py と同一定義を利用する。

Usage:
    python3 analysis/base_winrate_amg_tky_ex3_compare.py 2026-06-15 2026-07-14
    python3 analysis/base_winrate_amg_tky_ex3_compare.py 2026-07-15 2026-08-14
"""

from __future__ import annotations

import sys
from datetime import datetime
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import base_winrate_exhibition_compare as base  # noqa: E402

TARGET_PLACES = ("AMG", "TKY")
SCORE_NAME = "EX_TOTAL3"
BETA = 0.10


def parse_date(value: str):
    return datetime.strptime(value, "%Y-%m-%d").date()


def parse_args():
    if len(sys.argv) != 3:
        print(
            "Usage: python3 analysis/base_winrate_amg_tky_ex3_compare.py "
            "YYYY-MM-DD YYYY-MM-DD"
        )
        raise SystemExit(1)

    start = parse_date(sys.argv[1])
    end = parse_date(sys.argv[2])
    if start > end:
        raise RuntimeError("開始日が終了日より後です")
    return start, end


def build_scores_no_straight(race_rows, venue_avg_ex):
    """straight_timeを要求せずEX_TOTAL3を作る。"""
    prepared = []

    for row in race_rows:
        (
            lane,
            player_id,
            rank,
            result_course,
            ex_course,
            ex_time,
            ex_st,
            lap,
            around,
            _straight,
        ) = row

        lane = base.valid_course(lane)
        ex_course = base.valid_course(ex_course)
        ex_time = base.as_float(ex_time)
        ex_st = base.as_float(ex_st)
        lap = base.as_float(lap)
        around = base.as_float(around)

        # 最終的にはスリット補正まで載せるため、STも完備条件に残す。
        if (
            lane is None
            or ex_course is None
            or ex_time is None
            or ex_st is None
            or lap is None
            or around is None
        ):
            return None

        prepared.append(
            {
                "lane": lane,
                "player_id": str(player_id or "").strip(),
                "rank": base.as_int(rank),
                "result_course": result_course,
                "ex_course": ex_course,
                "ex_time": ex_time,
                "ex_st": ex_st,
                "lap": lap,
                "around": around,
            }
        )

    if len(prepared) != 6:
        return None
    if {r["lane"] for r in prepared} != {1, 2, 3, 4, 5, 6}:
        return None
    if {r["ex_course"] for r in prepared} != {1, 2, 3, 4, 5, 6}:
        return None
    if venue_avg_ex is None or venue_avg_ex <= 0:
        return None

    avg_lap = sum(r["lap"] for r in prepared) / 6.0
    avg_around = sum(r["around"] for r in prepared) / 6.0

    for r in prepared:
        ex_score = base.calc_ex_score(r["ex_time"] - venue_avg_ex)
        lap_score = base.calc_lap_score(r["lap"], avg_lap)
        around_score = base.calc_around_score(r["around"], avg_around)

        r["scores"] = {
            SCORE_NAME: float(ex_score + lap_score + around_score),
        }

    return prepared


def place_of(race_code: str) -> str:
    return race_code[8:11] if len(race_code) >= 11 else "???"


def delta_pct(value: float, baseline: float) -> str:
    if baseline == 0:
        return "-"
    return f"{(value - baseline) / baseline * 100:+.3f}%"


def evaluate_group(races):
    if not races:
        return None

    base_lane = base.evaluate(races, "BASE_LANE")
    remap = base.evaluate(races, "REMAP_ONLY")
    ex3 = base.evaluate(races, "SCORE", SCORE_NAME, BETA)
    return base_lane, remap, ex3


def print_group(label: str, races):
    result = evaluate_group(races)
    if result is None:
        print(f"{label:<10}: 対象レース0")
        return

    base_lane, remap, ex3 = result

    print(f"\n【{label}】 N={len(races)}")
    print(
        "方式            Brier      vs基本      vsREMAP     "
        "LogLoss    Top1"
    )
    print("-" * 78)

    rows = [
        ("BASE_LANE", base_lane),
        ("REMAP_ONLY", remap),
        ("EX_TOTAL3", ex3),
    ]

    for name, m in rows:
        print(
            f"{name:<14} "
            f"{m['brier']:.6f}   "
            f"{delta_pct(m['brier'], base_lane['brier']):>9}   "
            f"{delta_pct(m['brier'], remap['brier']):>9}   "
            f"{m['logloss']:.6f}   "
            f"{m['top1'] * 100:>6.2f}%"
        )


def main():
    eval_start, eval_end = parse_args()

    # 既存STEP8-1の時系列構築を流用し、score生成だけstraight不要版へ差し替える。
    original_build_scores = base.build_scores
    base.build_scores = build_scores_no_straight
    try:
        snapshots, skipped, course_source = base.load_snapshots(eval_start, eval_end)
    finally:
        base.build_scores = original_build_scores

    target = [
        r
        for r in snapshots
        if eval_start <= r.race_date <= eval_end
        and place_of(r.race_code) in TARGET_PLACES
    ]

    by_place = {
        place: [r for r in target if place_of(r.race_code) == place]
        for place in TARGET_PLACES
    }

    print("=" * 92)
    print("AMG/TKY 補正後1着率：straight_timeなし展示補正の固定検証")
    print("=" * 92)
    print(f"評価期間      : {eval_start} ～ {eval_end}")
    print("対象場        : AMG(尼崎), TKY(徳山)")
    print("展示補正      : EX_TOTAL3 = 展示タイム + 周回 + 周り足")
    print(f"beta          : {BETA:.2f} 固定（再チューニングなし）")
    print("基本1着率      : BB_MEDIUM Kpc=20 / Kpvc=10")
    print("SUM/スリット  : この段階では不使用")
    print("本番変更      : なし")

    print_group("AMG+TKY", target)
    for place in TARGET_PLACES:
        print_group(place, by_place[place])

    print("\n【参考 skip（全場ストリーム）】")
    for key in sorted(skipped):
        print(f"{key:<28}: {skipped[key]}")

    print("\n【判定】")
    print("・最重要はEX_TOTAL3のBrierがREMAP_ONLYより小さいか")
    print("・2期間とも改善することを採用条件とする")
    print("・AMG/TKY個別でも大崩れしていないか確認する")
    print("・通過した場合だけ、次にSUM γ=2.0 + スリット α=0.25まで載せて検証する")
    print("=" * 92)


if __name__ == "__main__":
    main()
