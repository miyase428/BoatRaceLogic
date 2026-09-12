#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
多摩川3コース★条件について、既に出力済みの
`tamagawa_lane3_strong_condition_second_eval_*.csv` を使い、
★★候補となる二次スコア閾値 × 二次TOP差を比較する。

3コースの第一集計では、
- 二次30以上
- TOP差0～2
が12ヶ月/6ヶ月の両profileで強かったため、4コース時と同じ
「二次スコア × TOP差」の考え方で候補を絞る。

比較する主な候補:
- SCORE30: 二次30以上
- GAP2: TOP差2以内
- C1: 二次30以上 × TOP差2以内
- C2: 二次30以上 × TOP差5以内
- C3: 二次27以上 × TOP差2以内
- C4: 二次27以上 × TOP差5以内

参考として、直線5・攻め成分9以上も単独集計する。

Usage:
  python3 analysis/analyze_tamagawa_lane3_second_eval_cross.py \
    analysis/output/tamagawa_lane3_strong_condition_second_eval_20250901_20260909.csv
"""

from __future__ import annotations

import csv
import sys
from collections import defaultdict
from pathlib import Path


def pct(num: int, den: int) -> float:
    return 100.0 * num / den if den else 0.0


def blank_stat() -> dict:
    return {
        "n": 0,
        "first": 0,
        "second": 0,
        "third": 0,
        "top2": 0,
        "top3": 0,
    }


def add(stat: dict, first: int, second: int, third: int | None) -> None:
    stat["n"] += 1
    if first == 3:
        stat["first"] += 1
    if second == 3:
        stat["second"] += 1
    if third == 3:
        stat["third"] += 1
    if first == 3 or second == 3:
        stat["top2"] += 1
    if first == 3 or second == 3 or third == 3:
        stat["top3"] += 1


def parse_int(value: str) -> int | None:
    value = (value or "").strip()
    if not value:
        return None
    try:
        return int(float(value))
    except ValueError:
        return None


def print_stat(label: str, stat: dict, base: dict | None = None) -> None:
    n = stat["n"]
    head = pct(stat["first"], n)
    top2 = pct(stat["top2"], n)
    top3 = pct(stat["top3"], n)
    suffix = ""
    if base is not None and base["n"]:
        suffix = (
            f"  Δ頭={head - pct(base['first'], base['n']):+6.2f}pt"
            f"  Δ2連={top2 - pct(base['top2'], base['n']):+6.2f}pt"
            f"  Δ3連={top3 - pct(base['top3'], base['n']):+6.2f}pt"
        )
    print(
        f"{label:<30} N={n:4d}  "
        f"3頭={head:6.2f}%  "
        f"32着={pct(stat['second'], n):6.2f}%  "
        f"33着={pct(stat['third'], n):6.2f}%  "
        f"3-2連対={top2:6.2f}%  "
        f"3-3連対={top3:6.2f}%{suffix}"
    )


def candidate_flags(score: float, gap: float, straight: float, attack: float) -> dict[str, bool]:
    return {
        "SCORE30": score >= 30.0,
        "SCORE27": score >= 27.0,
        "GAP2": gap <= 2.0,
        "GAP5": gap <= 5.0,
        "C1_30_G2": score >= 30.0 and gap <= 2.0,
        "C2_30_G5": score >= 30.0 and gap <= 5.0,
        "C3_27_G2": score >= 27.0 and gap <= 2.0,
        "C4_27_G5": score >= 27.0 and gap <= 5.0,
        "STRAIGHT5": straight >= 5.0,
        "ATTACK9": attack >= 9.0,
    }


def main() -> None:
    if len(sys.argv) != 2:
        print("Usage: python3 analysis/analyze_tamagawa_lane3_second_eval_cross.py CSV_PATH", file=sys.stderr)
        sys.exit(1)

    path = Path(sys.argv[1])
    if not path.exists():
        raise FileNotFoundError(path)

    by_month = defaultdict(lambda: {
        "all": blank_stat(),
        "candidates": defaultdict(blank_stat),
    })

    with path.open("r", encoding="utf-8-sig", newline="") as f:
        reader = csv.DictReader(f)
        required = {
            "history_months",
            "lane3_second_score",
            "lane3_gap_to_top",
            "lane3_straight_score",
            "lane3_attack_potential",
            "first_course",
            "second_course",
            "third_course",
        }
        missing = required - set(reader.fieldnames or [])
        if missing:
            raise RuntimeError(f"CSV列不足: {sorted(missing)}")

        for row in reader:
            months = parse_int(row.get("history_months", ""))
            if months not in (6, 12):
                continue

            try:
                score = float(row["lane3_second_score"])
                gap = float(row["lane3_gap_to_top"])
                straight = float(row["lane3_straight_score"])
                attack = float(row["lane3_attack_potential"])
            except (TypeError, ValueError):
                continue

            first = parse_int(row.get("first_course", ""))
            second = parse_int(row.get("second_course", ""))
            third = parse_int(row.get("third_course", ""))
            if first not in range(1, 7) or second not in range(1, 7):
                continue

            s = by_month[months]
            add(s["all"], first, second, third)
            for key, enabled in candidate_flags(score, gap, straight, attack).items():
                if enabled:
                    add(s["candidates"][key], first, second, third)

    labels = {
        "SCORE30": "二次30以上",
        "SCORE27": "二次27以上",
        "GAP2": "TOP差2以内",
        "GAP5": "TOP差5以内",
        "C1_30_G2": "C1 二次30以上 × TOP差2以内",
        "C2_30_G5": "C2 二次30以上 × TOP差5以内",
        "C3_27_G2": "C3 二次27以上 × TOP差2以内",
        "C4_27_G5": "C4 二次27以上 × TOP差5以内",
        "STRAIGHT5": "参考 直線5",
        "ATTACK9": "参考 攻め成分9以上",
    }
    order = (
        "SCORE30", "SCORE27", "GAP2", "GAP5",
        "C1_30_G2", "C2_30_G5", "C3_27_G2", "C4_27_G5",
        "STRAIGHT5", "ATTACK9",
    )

    print("=" * 164)
    print("多摩川3コース：★ × 二次スコア × TOP差　★★候補クロス比較")
    print("★ = 過去profileの3Cまくり+まくり差し率15%以上")
    print("狙い = 『3軸・頭もあり』として、Nを残しつつ3頭/2連対/3連対が安定して上がる条件を探す")
    print("=" * 164)

    for months in (12, 6):
        s = by_month[months]
        print("\n" + "-" * 164)
        print(f"【過去{months}ヶ月profile】")
        print("-" * 164)
        print_stat("★ ALL", s["all"])
        print()
        for key in order:
            print_stat(labels[key], s["candidates"][key], s["all"])

    print("\n" + "=" * 164)
    print("判断基準")
    print("  1) 12ヶ月/6ヶ月の両方で★より3頭率・2連対率が上がる")
    print("  2) Nを削りすぎず、3連対率も大きく崩さない")
    print("  3) 同程度なら単純な条件を優先する")
    print("※ ここでは★★条件をまだ確定・表示反映しません。候補を絞った後、長期/6ヶ月非重複ブロックで安定性確認します。")
    print("=" * 164)


if __name__ == "__main__":
    main()
