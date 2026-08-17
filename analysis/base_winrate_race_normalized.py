#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
基礎1着率 STEP 6: 1レース6艇の基礎1着率を100%正規化

目的:
- STEP5で算出したBB_MEDIUM平滑化後の基礎1着率を、6艇合計100%になるよう比例正規化する。
- 順位や相対比は変えず、表示用の最終基礎1着率を作る。
- 展示性能はまだ一切使わない。

正規化:
    normalized_i = p_final_i / sum(p_final_1 ... p_final_6)

Usage:
    python3 analysis/base_winrate_race_normalized.py 20260816TSU12
"""

from __future__ import annotations

import sys
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
    raw_rate,
)


def pct(v):
    return "   -   " if v is None else f"{v * 100:7.2f}%"


def main():
    if len(sys.argv) != 2:
        print("Usage: python3 analysis/base_winrate_race_normalized.py RACE_CODE")
        sys.exit(1)

    race_code = sys.argv[1].strip()

    with connect_db() as conn:
        target_date, place_code, stadium_name, boats = load_target(conn, race_code)
        venue = load_venue_course_prior(conn, race_code, target_date, place_code)

        results = []
        for boat in boats:
            history = load_last_100(conn, boat["player_id"], target_date, race_code)
            c = player_counts(history, boat["course"], place_code)

            p0 = venue[boat["course"]]["rate"]
            p_pc = (c["pc_w"] + K_PC * p0) / (c["pc_n"] + K_PC)
            p_final = (c["pvc_w"] + K_PVC * p_pc) / (c["pvc_n"] + K_PVC)

            results.append({
                **boat,
                **c,
                "venue_n": venue[boat["course"]]["n"],
                "venue_w": venue[boat["course"]]["wins"],
                "p0": p0,
                "pc_raw": raw_rate(c["pc_w"], c["pc_n"]),
                "p_pc": p_pc,
                "pvc_raw": raw_rate(c["pvc_w"], c["pvc_n"]),
                "p_final": p_final,
            })

    total = sum(r["p_final"] for r in results)
    if total <= 0:
        raise RuntimeError("基礎1着率の6艇合計が0以下です")

    for r in results:
        r["p_normalized"] = r["p_final"] / total
        r["delta"] = r["p_normalized"] - r["p_final"]

    normalized_total = sum(r["p_normalized"] for r in results)
    ordered = sorted(results, key=lambda r: (-r["p_normalized"], r["lane"]))

    print("=" * 160)
    print("基礎1着率 STEP 6：1レース6艇の基礎1着率（100%正規化後）")
    print("=" * 160)
    print(f"対象レース      : {race_code}")
    print(f"対象日          : {target_date}")
    print(f"対象場          : {place_code}:{stadium_name}")
    print("今回コース      : 展示不使用のため枠番=コース")
    print("平滑化方式      : BB_MEDIUM")
    print(f"Kpc / Kpvc      : {int(K_PC)} / {int(K_PVC)}")
    print("正規化方式      : 6艇の平滑化後確率を比例配分して合計100%")
    print("本番変更        : なし")

    print("\n【6艇の基礎1着率】")
    print(
        "艇  選手ID   選手名             C   p0       選手×C平滑後   選手×場×C平滑後   正規化後      補正差"
    )
    print("-" * 160)

    for r in results:
        delta_sign = "+" if r["delta"] >= 0 else ""
        print(
            f"{r['lane']:>1}   "
            f"{r['player_id']:<8} "
            f"{r['player_name'][:16]:<16} "
            f"{r['course']}C  "
            f"{pct(r['p0'])}   "
            f"{pct(r['p_pc'])}         "
            f"{pct(r['p_final'])}          "
            f"{pct(r['p_normalized'])}   "
            f"{delta_sign}{r['delta']*100:.2f}pt"
        )

    print("\n【正規化サマリー】")
    print(f"正規化前6艇合計  : {total * 100:.2f}%")
    print(f"正規化後6艇合計  : {normalized_total * 100:.2f}%")
    print("順位             : " + " > ".join(
        f"{r['lane']}号艇({r['p_normalized']*100:.2f}%)" for r in ordered
    ))

    print("\n【確認ポイント】")
    print("・正規化は全艇を同じ倍率で比例補正するため、艇間の順位は変わらない")
    print("・STEP5の6艇合計が100%に近いほど補正差は小さい")
    print("・この正規化後の値を、展示情報を入れる前の『基本1着率』として画面表示候補にする")
    print("・展示/SUM/スリット補正はまだ加えていない")
    print("=" * 160)


if __name__ == "__main__":
    main()
