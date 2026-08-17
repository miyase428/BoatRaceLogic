#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
補正後1着率 STEP 8-4: 1レースで採用済み補正を通し計算

採用済み:
- 基本1着率: BB_MEDIUM Kpc=20 / Kpvc=10 → 6艇100%正規化
- 展示進入へリマップ
- EX_TOTAL beta=+0.100
- SUM_RAW gamma=+2.0
- スリット win buff: 過去180日学習、K=40、cap=±0.08、alpha=0.25

このスクリプトは過去レースで途中値を確認する診断用。
本番Webロジックはまだ変更しない。

Usage:
    python3 analysis/base_winrate_corrected_race.py 20260816TSU12
"""

from __future__ import annotations

import sys
from datetime import timedelta
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from slit_validate_v2 import connect_db
from base_winrate_race import (
    K_PC,
    K_PVC,
    load_target,
    load_venue_course_prior,
    load_last_100,
    player_counts,
)
from base_winrate_sum_compare import load_snapshots
from base_winrate_slit_compare import (
    SLIT_BUFF_DAYS,
    build_slit_records,
    inclusive_window_start,
    learn_buff,
    load_lane_to_ex_course,
    sum_corrected_probs,
    apply_slit_buff,
)

SLIT_ALPHA = 0.25


def pct(x):
    return f"{x * 100:6.2f}%"


def build_basic_rates(race_code):
    with connect_db() as conn:
        target_date, place_code, stadium_name, boats = load_target(conn, race_code)
        venue = load_venue_course_prior(conn, race_code, target_date, place_code)

        rows = []
        for boat in boats:
            history = load_last_100(conn, boat["player_id"], target_date, race_code)
            c = player_counts(history, boat["course"], place_code)
            p0 = venue[boat["course"]]["rate"]
            p_pc = (c["pc_w"] + K_PC * p0) / (c["pc_n"] + K_PC)
            p_final = (c["pvc_w"] + K_PVC * p_pc) / (c["pvc_n"] + K_PVC)
            rows.append({**boat, "p_final": p_final})

    total = sum(r["p_final"] for r in rows)
    if total <= 0:
        raise RuntimeError("基本1着率の合計が0以下です")
    for r in rows:
        r["p_basic"] = r["p_final"] / total

    return target_date, place_code, stadium_name, rows


def main():
    if len(sys.argv) != 2:
        print("Usage: python3 analysis/base_winrate_corrected_race.py RACE_CODE")
        sys.exit(1)

    race_code = sys.argv[1].strip()
    target_date, place_code, stadium_name, basics = build_basic_rates(race_code)

    print("補正後1着率 STEP8-4 1レース通し計算用データを構築しています...")

    # STEP8-2と同じ時系列計算から、対象レース時点のEX_TOTAL/SUMを取得。
    snapshots, sum_skip, _ = load_snapshots(target_date, target_date)
    snap = next((s for s in snapshots if str(s.race_code) == race_code), None)
    if snap is None:
        raise RuntimeError(
            "対象レースの時系列スナップショットが作れません。"
            "展示6艇完備・一意な勝者がある過去レースで確認してください。"
        )

    snap_boats = sorted(snap.boats, key=lambda b: b.lane)
    ex_probs = [b.ex_total_prob for b in snap_boats]
    sum_probs = sum_corrected_probs(snap)
    if sum_probs is None:
        raise RuntimeError("SUM補正後確率の正規化に失敗しました")

    # 対象日前180日だけでスリットbuffを学習。
    buff_end = target_date - timedelta(days=1)
    buff_start = inclusive_window_start(buff_end, SLIT_BUFF_DAYS)
    records, slit_skip, _ = build_slit_records(buff_start, target_date)
    buff, buff_rows, buff_freq = learn_buff(records, buff_start, buff_end)

    rec = records.get(race_code)
    if rec is None:
        raise RuntimeError("対象レースのスリットPIDを作れません")

    course_map_all = load_lane_to_ex_course(target_date, target_date)
    cmap = course_map_all.get(race_code, {})
    if set(cmap) != set(range(1, 7)) or set(cmap.values()) != set(range(1, 7)):
        raise RuntimeError(f"展示進入マップが不完全です: {cmap}")

    final_probs = apply_slit_buff(
        sum_probs,
        snap_boats,
        rec["pid"],
        cmap,
        buff,
        SLIT_ALPHA,
    )
    if final_probs is None:
        raise RuntimeError("スリット補正後確率の正規化に失敗しました")

    basic_map = {r["lane"]: r for r in basics}

    print("=" * 150)
    print("補正後1着率 STEP 8-4：採用補正の1レース通し計算")
    print("=" * 150)
    print(f"対象レース        : {race_code}")
    print(f"対象日            : {target_date}")
    print(f"対象場            : {place_code}:{stadium_name}")
    print("基本1着率          : BB_MEDIUM Kpc=20 / Kpvc=10 → 100%正規化")
    print("展示補正           : 展示進入リマップ + EX_TOTAL beta=+0.100")
    print("SUM補正            : SUM_RAW gamma=+2.0")
    print(f"スリット補正       : PID={rec['pid']} / {rec['method']} / alpha={SLIT_ALPHA:.2f}")
    print(f"スリットbuff学習  : {buff_start} ～ {buff_end} ({len(buff_rows)}R)")
    print("本番変更           : なし")

    print("\n【6艇の確率推移】")
    print("艇  選手ID   選手名             展示進入   基本1着率   EX_TOTAL後   SUM後      slit元buff   最終補正後   基本差")
    print("-" * 150)

    rows = []
    for idx, b in enumerate(snap_boats):
        lane = b.lane
        basic = basic_map[lane]
        course = cmap[lane]
        slit_raw = float(buff[rec["pid"]][course]["win"])
        delta = final_probs[idx] - basic["p_basic"]
        rows.append((lane, final_probs[idx]))
        print(
            f"{lane:>1}   {basic['player_id']:<8} {basic['player_name'][:16]:<16} "
            f"{course}C       {pct(basic['p_basic'])}    {pct(ex_probs[idx])}    "
            f"{pct(sum_probs[idx])}    {slit_raw*100:+7.2f}pt    {pct(final_probs[idx])}    {delta*100:+7.2f}pt"
        )

    print("\n【100%確認】")
    print(f"基本1着率合計      : {sum(r['p_basic'] for r in basics) * 100:.2f}%")
    print(f"EX_TOTAL後合計     : {sum(ex_probs) * 100:.2f}%")
    print(f"SUM後合計          : {sum(sum_probs) * 100:.2f}%")
    print(f"最終補正後合計     : {sum(final_probs) * 100:.2f}%")

    ordered = sorted(rows, key=lambda x: (-x[1], x[0]))
    print("最終順位           : " + " > ".join(f"{lane}号艇({p*100:.2f}%)" for lane, p in ordered))

    print("\n【診断】")
    print(f"SUM skip件数種別   : {len(sum_skip)}")
    print(f"slit skip件数種別  : {len(slit_skip)}")
    print("・slit元buffはalpha適用前のPID×展示進入C win buff")
    print("・実際にはalpha=0.25を掛けてから6艇を再正規化")
    print("・この結果確認後にWeb用の補正後1着率ロジックへ進む")
    print("=" * 150)


if __name__ == "__main__":
    main()
