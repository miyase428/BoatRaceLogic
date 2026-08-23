#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
場別「1コースが勝てなかった時」の崩れ方を比較する。

目的:
- 場ごとのイン敗戦率を比較
- イン敗戦時にどのコースが頭になるかを見る
- 勝者の決まり手（差し/まくり/まくり差し等）を比較
- 1コースが2・3着に残る割合を比較

このSTEPでは予測補正を作らず、場特性の構造把握だけを行う。

使い方:
  python3 analysis/analyze_stadium_non_lane1_structure.py \
    analysis/output/kimarite_analysis_dataset_20250815_20260814.csv
"""

from __future__ import annotations

import csv
import sys
from collections import Counter, defaultdict
from pathlib import Path

FOCUS_VENUES = ("徳山", "下関", "多摩川", "大村", "津", "宮島", "戸田", "江戸川")
WINNER_COURSES = (2, 3, 4, 5, 6)
TECH_KEYS = ("差し", "まくり", "まくり差し", "抜き", "恵まれ")


def to_int(value, default=0):
    try:
        return int(float(value))
    except (TypeError, ValueError):
        return default


def pct(n: int, d: int) -> float:
    return 100.0 * n / d if d else 0.0


def load_rows(path: Path):
    with path.open("r", encoding="utf-8-sig", newline="") as f:
        return list(csv.DictReader(f))


def is_formal(row):
    return (
        to_int(row.get("result_top3_course_complete")) == 1
        and to_int(row.get("result_boat_match")) == 1
    )


def norm_tech(value: str) -> str:
    v = (value or "").strip()
    if v in TECH_KEYS:
        return v
    return "その他"


def empty_stat():
    return {
        "n": 0,
        "non1": 0,
        "winner_course": Counter(),
        "tech": Counter(),
        "lane1_second": 0,
        "lane1_third": 0,
        "lane1_top3": 0,
    }


def add_row(stat, row):
    stat["n"] += 1
    winner = to_int(row.get("actual_1st_course"))
    if winner == 1:
        return

    stat["non1"] += 1
    if winner in WINNER_COURSES:
        stat["winner_course"][winner] += 1

    stat["tech"][norm_tech(row.get("winner_technique") or "")] += 1

    second = to_int(row.get("actual_2nd_course"))
    third = to_int(row.get("actual_3rd_course"))
    if second == 1:
        stat["lane1_second"] += 1
    if third == 1:
        stat["lane1_third"] += 1
    if second == 1 or third == 1:
        stat["lane1_top3"] += 1


def course_text(stat):
    d = stat["non1"]
    return "/".join(f"{pct(stat['winner_course'][c], d):.1f}" for c in WINNER_COURSES)


def tech_text(stat):
    d = stat["non1"]
    vals = [pct(stat["tech"][k], d) for k in ("差し", "まくり", "まくり差し")]
    return "/".join(f"{v:.1f}" for v in vals)


def print_focus_detail(venue: str, s):
    d = s["non1"]
    print("-" * 126)
    print(f"【{venue}】 全体N={s['n']} / 1C敗戦={d} ({pct(d, s['n']):.2f}%)")
    print(
        "  頭コース  : "
        + ", ".join(
            f"{c}C {s['winner_course'][c]}R({pct(s['winner_course'][c], d):.1f}%)"
            for c in WINNER_COURSES
        )
    )
    print(
        "  決まり手  : "
        + ", ".join(
            f"{k} {s['tech'][k]}R({pct(s['tech'][k], d):.1f}%)"
            for k in ("差し", "まくり", "まくり差し", "抜き", "恵まれ", "その他")
            if s['tech'][k] > 0
        )
    )
    print(
        f"  1C残り    : 2着 {pct(s['lane1_second'], d):.1f}% / "
        f"3着 {pct(s['lane1_third'], d):.1f}% / "
        f"2・3着合計 {pct(s['lane1_top3'], d):.1f}% / "
        f"圏外 {100.0-pct(s['lane1_top3'], d):.1f}%"
    )


def main():
    if len(sys.argv) != 2:
        print(
            "Usage: python3 analysis/analyze_stadium_non_lane1_structure.py DATASET_CSV",
            file=sys.stderr,
        )
        sys.exit(1)

    path = Path(sys.argv[1]).resolve()
    if not path.is_file():
        raise RuntimeError(f"CSVがありません: {path}")

    rows = [r for r in load_rows(path) if is_formal(r)]
    if not rows:
        raise RuntimeError("正式分析対象がありません")

    venues = defaultdict(empty_stat)
    global_stat = empty_stat()
    for row in rows:
        venue = (row.get("stadium_name") or "").strip() or "不明"
        add_row(venues[venue], row)
        add_row(global_stat, row)

    ranked = sorted(
        venues.items(),
        key=lambda kv: pct(kv[1]["non1"], kv[1]["n"]),
        reverse=True,
    )

    g = global_stat
    print("=" * 170)
    print("場別 イン敗戦時の頭コース・決まり手・1C残り構造（1年）")
    print("=" * 170)
    print(f"正式対象: {len(rows)}R / {len(venues)}場")
    print(
        f"全場: 1C敗戦={pct(g['non1'], g['n']):.2f}% / "
        f"敗戦時1C残り={pct(g['lane1_top3'], g['non1']):.2f}% / "
        f"1C圏外={100.0-pct(g['lane1_top3'], g['non1']):.2f}%"
    )
    print("頭コース欄 = 2C/3C/4C/5C/6C (%)、決まり手欄 = 差し/まくり/まくり差し (%)")

    print("\n" + "=" * 170)
    print("24場比較（イン敗戦率が高い順）")
    print("=" * 170)
    print("順 場             N  1C敗戦             頭2/3/4/5/6       差/まく/ま差 1C残り 1C圏外")
    print("-" * 170)
    for i, (venue, s) in enumerate(ranked, 1):
        d = s["non1"]
        remain = pct(s["lane1_top3"], d)
        print(
            f"{i:>2} {venue:<8} {s['n']:>5} {pct(d, s['n']):>7.2f}% "
            f"{course_text(s):>24} {tech_text(s):>17} "
            f"{remain:>7.2f}% {100.0-remain:>7.2f}%"
        )

    print("\n" + "=" * 126)
    print("重点8場 詳細")
    print("=" * 126)
    for venue in FOCUS_VENUES:
        if venue in venues:
            print_focus_detail(venue, venues[venue])

    print("\n判断ポイント:")
    print("1. 戸田/江戸川が、単に1C敗戦率が高いだけか、頭コースや決まり手まで特徴的かを見る。")
    print("2. 多摩川/下関などWeb相性の良い場と、イン敗戦時の構造差を比較する。")
    print("3. 1C残り率に場差が大きければ、将来の穴目警報『①残り』へ使える可能性がある。")
    print("4. この段階では場補正・買い目変更・閾値設定は行わない。")


if __name__ == "__main__":
    main()
