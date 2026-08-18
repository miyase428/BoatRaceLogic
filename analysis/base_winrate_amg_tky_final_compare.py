#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
AMG/TKY 補正後1着率 最終固定検証。

straight_time が実質存在しない AMG/TKY について、再チューニングせず
以下の固定チェーンを同一評価レース上で比較する。

1) EX_TOTAL3      : 展示進入リマップ + (展示タイム + 周回 + 周り足) beta=0.10
2) EX3 + SUM      : 1) + SUM_RAW gamma=2.0
3) FINAL          : 2) + スリット buff alpha=0.25

SUM features は theories/new_sam/features.json を使用する。
AMG/TKY は exhibition_time + lap_time + around_time に設定済み。

Usage:
  python3 analysis/base_winrate_amg_tky_final_compare.py 2026-06-15 2026-07-14
  python3 analysis/base_winrate_amg_tky_final_compare.py 2026-07-15 2026-08-14
"""

from __future__ import annotations

import math
import sys
from collections import Counter
from datetime import datetime, timedelta
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import base_winrate_sum_compare as sumv
from base_winrate_slit_compare import (
    SLIT_BUFF_DAYS,
    inclusive_window_start,
    build_slit_records,
    learn_buff,
    load_lane_to_ex_course,
    attach_races,
    apply_slit_buff,
)

TARGET_PLACES = ("AMG", "TKY")
SLIT_ALPHA = 0.25


def parse_date(s: str):
    return datetime.strptime(s, "%Y-%m-%d").date()


def place_of(race_code: str) -> str:
    code = str(race_code)
    return code[8:11] if len(code) >= 11 else "???"


def fmt_delta(value: float, base: float) -> str:
    if base == 0:
        return "-"
    return f"{(value - base) / base * 100:+.3f}%"


def build_prepared_no_straight(race_rows, venue_avg_ex, feature_cols):
    """AMG/TKY用。straight不要でEX_TOTAL3とSUM区間を構築する。"""
    if venue_avg_ex is None or venue_avg_ex <= 0:
        return None

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
            straight,
        ) = row

        lane = sumv.valid_course(lane)
        ex_course = sumv.valid_course(ex_course)
        ex_time = sumv.as_float(ex_time)
        ex_st = sumv.as_float(ex_st)
        lap = sumv.as_float(lap)
        around = sumv.as_float(around)
        straight = sumv.as_float(straight)

        # straight_timeだけは欠損を許可する。
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
                "rank": sumv.as_int(rank),
                "result_course": result_course,
                "ex_course": ex_course,
                "ex_time": ex_time,
                "ex_st": ex_st,
                "lap": lap,
                "around": around,
                "straight": straight,
            }
        )

    if len(prepared) != 6:
        return None
    if {r["lane"] for r in prepared} != set(range(1, 7)):
        return None
    if {r["ex_course"] for r in prepared} != set(range(1, 7)):
        return None

    avg_lap = sum(r["lap"] for r in prepared) / 6.0
    avg_around = sum(r["around"] for r in prepared) / 6.0

    for r in prepared:
        ex_score = sumv.calc_ex_score(r["ex_time"] - venue_avg_ex)
        lap_score = sumv.calc_lap_score(r["lap"], avg_lap)
        around_score = sumv.calc_around_score(r["around"], avg_around)

        # AMG/TKY専用展示補正。既存EX_TOTALのstraightだけ外す。
        r["ex_total"] = float(ex_score + lap_score + around_score)

        vals = []
        for col in feature_cols:
            value = sumv.feature_value(r, col)
            if value is None:
                return None
            vals.append(float(value))
        r["sum_raw"] = sum(vals)

    race_mean_sum = sum(r["sum_raw"] for r in prepared) / 6.0
    for r in prepared:
        r["sum_diff"] = r["sum_raw"] - race_mean_sum
        r["sum_interval"] = sumv.sum_interval_label(r["sum_diff"])

    return prepared


def metrics_for(attached, stage: str, buff=None):
    if not attached:
        return None

    brier = 0.0
    logloss = 0.0
    top1 = 0
    n = 0

    for snap, pid, method, cmap, sum_probs in attached:
        boats = sorted(snap.boats, key=lambda b: b.lane)

        if stage == "EX_TOTAL3":
            probs = [b.ex_total_prob for b in boats]
        elif stage == "EX3_SUM":
            probs = sum_probs
        elif stage == "FINAL":
            probs = apply_slit_buff(sum_probs, boats, pid, cmap, buff, SLIT_ALPHA)
        else:
            raise RuntimeError(f"unknown stage: {stage}")

        if probs is None:
            continue

        for p, b in zip(probs, boats):
            cp = min(max(p, 1e-9), 1.0 - 1e-9)
            brier += (p - b.y) ** 2
            logloss += -(b.y * math.log(cp) + (1 - b.y) * math.log(1 - cp))

        best = sorted(range(6), key=lambda i: (-probs[i], boats[i].lane))[0]
        if boats[best].y == 1:
            top1 += 1
        n += 1

    if n == 0:
        return None

    return {
        "races": n,
        "brier": brier / (n * 6),
        "logloss": logloss / (n * 6),
        "top1": top1 / n,
    }


def print_group(name: str, attached, buff):
    if not attached:
        print(f"\n【{name}】 N=0")
        return

    m_ex = metrics_for(attached, "EX_TOTAL3")
    m_sum = metrics_for(attached, "EX3_SUM")
    m_final = metrics_for(attached, "FINAL", buff)

    print(f"\n【{name}】 N={m_ex['races']}")
    print("方式                 Brier      vs EX3       LogLoss    Top1")
    print("-" * 78)
    print(
        f"EX_TOTAL3           {m_ex['brier']:.6f}   {fmt_delta(m_ex['brier'], m_ex['brier']):>10}   "
        f"{m_ex['logloss']:.6f}   {m_ex['top1']*100:>6.2f}%"
    )
    print(
        f"EX3 + SUM_RAW       {m_sum['brier']:.6f}   {fmt_delta(m_sum['brier'], m_ex['brier']):>10}   "
        f"{m_sum['logloss']:.6f}   {m_sum['top1']*100:>6.2f}%"
    )
    print(
        f"FINAL + SLIT        {m_final['brier']:.6f}   {fmt_delta(m_final['brier'], m_ex['brier']):>10}   "
        f"{m_final['logloss']:.6f}   {m_final['top1']*100:>6.2f}%"
    )
    print(
        f"  SUM追加効果       Brier {fmt_delta(m_sum['brier'], m_ex['brier'])}, "
        f"LogLoss {fmt_delta(m_sum['logloss'], m_ex['logloss'])}"
    )
    print(
        f"  Slit追加効果      Brier {fmt_delta(m_final['brier'], m_sum['brier'])}, "
        f"LogLoss {fmt_delta(m_final['logloss'], m_sum['logloss'])}"
    )


def main():
    if len(sys.argv) != 3:
        print(
            "Usage: python3 analysis/base_winrate_amg_tky_final_compare.py "
            "YYYY-MM-DD YYYY-MM-DD"
        )
        sys.exit(1)

    eval_start = parse_date(sys.argv[1])
    eval_end = parse_date(sys.argv[2])
    if eval_start > eval_end:
        raise RuntimeError("開始日が終了日より後です")

    buff_end = eval_start - timedelta(days=1)
    buff_start = inclusive_window_start(buff_end, SLIT_BUFF_DAYS)

    print("AMG/TKY 最終固定チェーン検証データを構築しています...")

    # SUM検証ストリームの計算順序は維持し、展示部分だけstraight不要版へ差し替える。
    original_build_prepared = sumv.build_prepared
    sumv.build_prepared = build_prepared_no_straight
    try:
        snapshots, sum_skip, _ = sumv.load_snapshots(eval_start, eval_end)
    finally:
        sumv.build_prepared = original_build_prepared

    target_snapshots = [
        s
        for s in snapshots
        if eval_start <= s.race_date <= eval_end
        and place_of(s.race_code) in TARGET_PLACES
    ]
    if not target_snapshots:
        raise RuntimeError("AMG/TKYの評価可能スナップショットが0件です")

    records, slit_skip, _ = build_slit_records(buff_start, eval_end)
    buff, buff_rows, buff_freq = learn_buff(records, buff_start, buff_end)
    course_map = load_lane_to_ex_course(eval_start, eval_end)

    attached, attach_skip, methods = attach_races(
        target_snapshots,
        records,
        course_map,
        eval_start,
        eval_end,
    )
    if not attached:
        raise RuntimeError("AMG/TKYのスリットまで比較可能なレースが0件です")

    by_place = {
        place: [a for a in attached if place_of(a[0].race_code) == place]
        for place in TARGET_PLACES
    }

    print("=" * 102)
    print("AMG/TKY 補正後1着率：最終固定チェーン検証")
    print("=" * 102)
    print(f"評価期間        : {eval_start} ～ {eval_end}")
    print(f"対象場          : AMG(尼崎), TKY(徳山)")
    print("展示補正        : EX_TOTAL3 = 展示タイム + 周回 + 周り足 / beta=0.10")
    print("SUM             : SUM_RAW / gamma=2.0")
    print("スリット        : PID×展示進入C win buff / K=40 cap±0.08 / alpha=0.25")
    print(f"スリットbuff学習: {buff_start} ～ {buff_end} ({SLIT_BUFF_DAYS}日)")
    print(f"buff学習レース  : {len(buff_rows)}")
    print(f"評価レース      : {len(attached)}（3段階すべて比較可能な同一母集団）")
    print("本番変更        : なし")

    print_group("AMG+TKY", attached, buff)
    for place in TARGET_PLACES:
        print_group(place, by_place[place], buff)

    total_methods = sum(methods.values())
    print("\n【PID予測方式】")
    for name in ("C_ST_RANK", "A_EX_FALLBACK"):
        n = methods[name]
        pct = n / total_methods * 100 if total_methods else 0.0
        print(f"{name:<16}: {n:>5} ({pct:6.2f}%)")

    print("\n【skip】")
    merged = Counter()
    for prefix, src in (("sum", sum_skip), ("slit", slit_skip), ("attach", attach_skip)):
        for key, value in src.items():
            merged[f"{prefix}_{key}"] += int(value)
    if merged:
        for key in sorted(merged):
            print(f"{key:<40}: {merged[key]}")
    else:
        print("なし")

    print("\n【判定】")
    print("・EX_TOTAL3は前段でREMAP_ONLYに対する2期間改善を確認済み")
    print("・この検証ではSUM追加とスリット追加を同一母集団で確認する")
    print("・最終FINALがEX_TOTAL3より2期間ともBrier改善するかを最重要とする")
    print("・AMG/TKY個別で継続悪化する段階があれば、その補正を場別に外す候補にする")
    print("・本番反映は2期間の結果を確認してから行う")
    print("=" * 102)


if __name__ == "__main__":
    main()
