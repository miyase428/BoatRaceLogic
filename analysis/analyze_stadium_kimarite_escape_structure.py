#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
場×決まり手 + 1逃げ時の2・3着分布を24場で比較する。

目的:
- 現行Webの場別相性差が、各場の決まり手構造で説明できるかを見る
- 特に1逃げ成立時の2着/3着コース分布が場ごとに違うか確認する
- 徳山/芦屋/下関/多摩川/大村/戸田/津/江戸川を重点表示する

Usage:
  python3 analysis/analyze_stadium_kimarite_escape_structure.py \
    analysis/output/kimarite_analysis_dataset_20250815_20260814.csv

注意:
- result_top3_course_complete=1 AND result_boat_match=1 の正式対象のみ。
- winner_technique / actual_* は結果ラベルとしてのみ使用。
- ここではWebロジックを変更しない。場特性の把握だけを行う。
"""

from __future__ import annotations

import csv
import sys
from collections import Counter, defaultdict
from pathlib import Path

TECHNIQUES = ("逃げ", "差し", "まくり", "まくり差し")
FOCUS = ("徳山", "芦屋", "下関", "多摩川", "大村", "戸田", "津", "江戸川")


def load_rows(path: Path) -> list[dict[str, str]]:
    with path.open("r", encoding="utf-8-sig", newline="") as f:
        return list(csv.DictReader(f))


def as_int(row: dict[str, str], key: str) -> int:
    try:
        return int((row.get(key) or "0").strip() or 0)
    except (TypeError, ValueError):
        return 0


def pct(n: int, d: int) -> float:
    return (100.0 * n / d) if d > 0 else 0.0


def formal(row: dict[str, str]) -> bool:
    return (
        as_int(row, "result_top3_course_complete") == 1
        and as_int(row, "result_boat_match") == 1
    )


def stadium_name(row: dict[str, str]) -> str:
    return (row.get("stadium_name") or "").strip() or "不明"


def summarize(rows: list[dict[str, str]]) -> dict:
    technique = Counter()
    lane1_win = 0
    escapes = []
    second = Counter()
    third = Counter()
    patterns = Counter()

    for row in rows:
        tech = (row.get("winner_technique") or "").strip()
        if tech:
            technique[tech] += 1

        c1 = as_int(row, "actual_1st_course")
        if c1 == 1:
            lane1_win += 1
        if c1 == 1 and tech == "逃げ":
            escapes.append(row)
            c2 = as_int(row, "actual_2nd_course")
            c3 = as_int(row, "actual_3rd_course")
            if 2 <= c2 <= 6:
                second[c2] += 1
            if 2 <= c3 <= 6:
                third[c3] += 1
            if 2 <= c2 <= 6 and 2 <= c3 <= 6 and c2 != c3:
                patterns[f"1-{c2}-{c3}"] += 1

    n = len(rows)
    esc_n = len(escapes)
    known_tech = sum(technique.values())
    other = known_tech - sum(technique[t] for t in TECHNIQUES)

    return {
        "n": n,
        "lane1_win": lane1_win,
        "esc_n": esc_n,
        "technique": technique,
        "tech_known": known_tech,
        "tech_other": other,
        "second": second,
        "third": third,
        "patterns": patterns,
    }


def dist_text(counter: Counter, total: int) -> str:
    return "/".join(f"{pct(counter[c], total):.1f}" for c in range(2, 7))


def main() -> None:
    if len(sys.argv) != 2:
        print(
            "Usage: python3 analysis/analyze_stadium_kimarite_escape_structure.py DATASET_CSV",
            file=sys.stderr,
        )
        sys.exit(1)

    path = Path(sys.argv[1]).resolve()
    if not path.is_file():
        raise RuntimeError(f"CSVがありません: {path}")

    rows = [r for r in load_rows(path) if formal(r)]
    if not rows:
        raise RuntimeError("正式対象が0件です")

    grouped: dict[str, list[dict[str, str]]] = defaultdict(list)
    for row in rows:
        grouped[stadium_name(row)].append(row)

    all_stat = summarize(rows)
    venue_stats = {name: summarize(vrows) for name, vrows in grouped.items()}

    print("=" * 190)
    print("場×決まり手 + 1逃げ時2・3着分布（1年）")
    print("=" * 190)
    print(f"正式対象: {len(rows)}R / {len(venue_stats)}場")
    print(
        "全場: "
        f"1C1着={pct(all_stat['lane1_win'], all_stat['n']):.2f}% / "
        f"1逃げ={pct(all_stat['esc_n'], all_stat['n']):.2f}% / "
        f"差し={pct(all_stat['technique']['差し'], all_stat['tech_known']):.2f}% / "
        f"まくり={pct(all_stat['technique']['まくり'], all_stat['tech_known']):.2f}% / "
        f"まくり差し={pct(all_stat['technique']['まくり差し'], all_stat['tech_known']):.2f}%"
    )
    print("1逃げ時 2着/3着分布の並びは 2C/3C/4C/5C/6C (%)")
    print()

    print("=" * 190)
    print("24場比較（1逃げ率順）")
    print("=" * 190)
    print(
        f"{'場':<8} {'N':>6} {'1C勝':>7} {'1逃げ':>7} {'差し':>7} {'まくり':>7} {'ま差':>7} "
        f"{'1逃げ2着 2/3/4/5/6':>31} {'1逃げ3着 2/3/4/5/6':>31}"
    )
    print("-" * 190)

    ordered = sorted(
        venue_stats.items(),
        key=lambda kv: pct(kv[1]["esc_n"], kv[1]["n"]),
        reverse=True,
    )

    for name, s in ordered:
        tech_n = s["tech_known"]
        print(
            f"{name:<8} {s['n']:>6} "
            f"{pct(s['lane1_win'], s['n']):>6.2f}% "
            f"{pct(s['esc_n'], s['n']):>6.2f}% "
            f"{pct(s['technique']['差し'], tech_n):>6.2f}% "
            f"{pct(s['technique']['まくり'], tech_n):>6.2f}% "
            f"{pct(s['technique']['まくり差し'], tech_n):>6.2f}% "
            f"{dist_text(s['second'], s['esc_n']):>31} "
            f"{dist_text(s['third'], s['esc_n']):>31}"
        )

    print()
    print("=" * 190)
    print("重点8場：1逃げ時の出目TOP8")
    print("=" * 190)

    for name in FOCUS:
        s = venue_stats.get(name)
        if not s:
            continue
        top = s["patterns"].most_common(8)
        top_text = ", ".join(
            f"{pat} {n}R({pct(n, s['esc_n']):.1f}%)" for pat, n in top
        ) or "-"
        print(
            f"{name:<6} N={s['n']:>4} 1逃げ={s['esc_n']:>4}({pct(s['esc_n'], s['n']):.1f}%)  "
            f"2着={dist_text(s['second'], s['esc_n'])}  3着={dist_text(s['third'], s['esc_n'])}"
        )
        print(f"       TOP: {top_text}")

    print()
    print("判断ポイント:")
    print("1. Web上位場と下位場で、逃げ率だけでなく非逃げ決まり手の比率が違うかを見る。")
    print("2. 1逃げ時の2着・3着分布に場固有差があれば、将来の相手候補補正に使える可能性がある。")
    print("3. 大村/津/宮島のような1C勝率に対してWeb本命1率が低い場は、逃げ構造を重点確認する。")
    print("4. 戸田/江戸川のような難場は、非逃げ決まり手と外コース頭の構造を重点確認する。")


if __name__ == "__main__":
    main()
