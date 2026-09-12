#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
多摩川4コースの★★条件から、さらに4頭を強く見られる★★★候補を探す。

前提:
★  = 4コース攻めサイン
  - 過去4コースまくり率15%以上
  - 4が3より平均ST順位上
★★ = ★ + 二次24以上 + TOP差5以内
★★★ = 未確定。本スクリプトで候補探索する。

入力CSV:
  analysis/output/tamagawa_lane4_strong_condition_second_eval_YYYYMMDD_YYYYMMDD.csv

主な確認:
- ★★全体
- 二次スコア帯 24-26 / 27-29 / 30+
- TOP差 0 / 1-2 / 3-5
- 攻め成分（展示ST点+直線点）
- 展示ST点
- 直線点
- 主要クロス
- 事前に定義した★★★候補条件の12ヶ月/6ヶ月再現性

Usage:
  python3 analysis/analyze_tamagawa_lane4_triple_star_candidates.py \
    analysis/output/tamagawa_lane4_strong_condition_second_eval_20250901_20260909.csv
"""

from __future__ import annotations

import csv
import sys
from collections import defaultdict
from pathlib import Path

PROFILE_MONTHS = (12, 6)


def pct(num: int, den: int) -> float:
    return 100.0 * num / den if den else 0.0


def as_float(value) -> float | None:
    if value is None or value == "":
        return None
    try:
        return float(value)
    except (TypeError, ValueError):
        return None


def as_int(value) -> int | None:
    if value is None or value == "":
        return None
    try:
        return int(float(value))
    except (TypeError, ValueError):
        return None


def is_double_star(row: dict) -> bool:
    score = as_float(row.get("lane4_second_score"))
    gap = as_float(row.get("lane4_gap_to_top"))
    return score is not None and gap is not None and score >= 24.0 and gap <= 5.0


def blank_stat() -> dict:
    return {
        "n": 0,
        "first": 0,
        "second": 0,
        "third": 0,
        "top2": 0,
        "top3": 0,
    }


def add_outcome(stat: dict, first: int, second: int, third: int | None) -> None:
    stat["n"] += 1
    if first == 4:
        stat["first"] += 1
    if second == 4:
        stat["second"] += 1
    if third == 4:
        stat["third"] += 1
    if first == 4 or second == 4:
        stat["top2"] += 1
    if first == 4 or second == 4 or third == 4:
        stat["top3"] += 1


def score_band(score: float) -> str:
    if score >= 30.0:
        return "二次30+"
    if score >= 27.0:
        return "二次27-29"
    return "二次24-26"


def gap_band(gap: float) -> str:
    if gap < 1e-9:
        return "TOP差0"
    if gap <= 2.0:
        return "TOP差1-2"
    return "TOP差3-5"


def attack_band(value: float) -> str:
    if value >= 9.0:
        return "攻め9-10"
    if value >= 7.0:
        return "攻め7-8"
    if value >= 5.0:
        return "攻め5-6"
    return "攻め2-4"


def point_label(prefix: str, value: float) -> str:
    # 現行二次ロジックの点数は基本1～5（STは4点が出ない）。
    if abs(value - round(value)) < 1e-9:
        return f"{prefix}{int(round(value))}"
    return f"{prefix}{value:.1f}"


def print_stat(label: str, stat: dict) -> None:
    n = stat["n"]
    print(
        f"{label:<38} N={n:3d}  "
        f"4頭={pct(stat['first'], n):6.2f}%  "
        f"42着={pct(stat['second'], n):6.2f}%  "
        f"43着={pct(stat['third'], n):6.2f}%  "
        f"4-2連対={pct(stat['top2'], n):6.2f}%  "
        f"4-3連対={pct(stat['top3'], n):6.2f}%"
    )


def candidate_definitions() -> list[tuple[str, callable]]:
    return [
        ("C1 二次30+", lambda r: r["score"] >= 30.0),
        ("C2 二次27+ × TOP差2以内", lambda r: r["score"] >= 27.0 and r["gap"] <= 2.0),
        ("C3 二次27+ × 攻め7+", lambda r: r["score"] >= 27.0 and r["attack"] >= 7.0),
        ("C4 TOP差2以内 × 攻め7+", lambda r: r["gap"] <= 2.0 and r["attack"] >= 7.0),
        ("C5 二次30+ × 攻め7+", lambda r: r["score"] >= 30.0 and r["attack"] >= 7.0),
        ("C6 二次27+ × TOP差2以内 × 攻め7+", lambda r: r["score"] >= 27.0 and r["gap"] <= 2.0 and r["attack"] >= 7.0),
        ("C7 二次27+ × ST5", lambda r: r["score"] >= 27.0 and r["st"] >= 5.0),
        ("C8 二次27+ × 直線4+", lambda r: r["score"] >= 27.0 and r["straight"] >= 4.0),
        ("C9 TOP差2以内 × ST5", lambda r: r["gap"] <= 2.0 and r["st"] >= 5.0),
        ("C10 TOP差2以内 × 直線4+", lambda r: r["gap"] <= 2.0 and r["straight"] >= 4.0),
    ]


def print_month(months: int, rows: list[dict]) -> None:
    print("\n" + "-" * 146)
    print(f"【過去{months}ヶ月profile：★★内の★★★候補探索】")
    print("-" * 146)

    all_stat = blank_stat()
    by_score = defaultdict(blank_stat)
    by_gap = defaultdict(blank_stat)
    by_attack = defaultdict(blank_stat)
    by_st = defaultdict(blank_stat)
    by_straight = defaultdict(blank_stat)
    by_score_gap = defaultdict(blank_stat)
    by_score_attack = defaultdict(blank_stat)
    by_gap_attack = defaultdict(blank_stat)

    for r in rows:
        first, second, third = r["first"], r["second"], r["third"]
        add_outcome(all_stat, first, second, third)

        sb = score_band(r["score"])
        gb = gap_band(r["gap"])
        ab = attack_band(r["attack"])
        stb = point_label("ST", r["st"])
        strb = point_label("直線", r["straight"])

        add_outcome(by_score[sb], first, second, third)
        add_outcome(by_gap[gb], first, second, third)
        add_outcome(by_attack[ab], first, second, third)
        add_outcome(by_st[stb], first, second, third)
        add_outcome(by_straight[strb], first, second, third)
        add_outcome(by_score_gap[f"{sb} × {gb}"], first, second, third)
        add_outcome(by_score_attack[f"{sb} × {ab}"], first, second, third)
        add_outcome(by_gap_attack[f"{gb} × {ab}"], first, second, third)

    print("\n■ ★★全体")
    print_stat("★★", all_stat)

    print("\n■ 二次スコア帯")
    for key in ("二次24-26", "二次27-29", "二次30+"):
        print_stat(key, by_score.get(key, blank_stat()))

    print("\n■ TOP差")
    for key in ("TOP差0", "TOP差1-2", "TOP差3-5"):
        print_stat(key, by_gap.get(key, blank_stat()))

    print("\n■ 攻め成分（展示ST点＋直線点）")
    for key in ("攻め9-10", "攻め7-8", "攻め5-6", "攻め2-4"):
        print_stat(key, by_attack.get(key, blank_stat()))

    print("\n■ 展示ST点")
    for key in sorted(by_st, key=lambda x: float(x.replace("ST", "")), reverse=True):
        print_stat(key, by_st[key])

    print("\n■ 直線点")
    for key in sorted(by_straight, key=lambda x: float(x.replace("直線", "")), reverse=True):
        print_stat(key, by_straight[key])

    print("\n■ 二次スコア帯 × TOP差")
    for sb in ("二次24-26", "二次27-29", "二次30+"):
        for gb in ("TOP差0", "TOP差1-2", "TOP差3-5"):
            key = f"{sb} × {gb}"
            print_stat(key, by_score_gap.get(key, blank_stat()))

    print("\n■ 二次スコア帯 × 攻め成分")
    for sb in ("二次24-26", "二次27-29", "二次30+"):
        for ab in ("攻め9-10", "攻め7-8", "攻め5-6", "攻め2-4"):
            key = f"{sb} × {ab}"
            print_stat(key, by_score_attack.get(key, blank_stat()))

    print("\n■ TOP差 × 攻め成分")
    for gb in ("TOP差0", "TOP差1-2", "TOP差3-5"):
        for ab in ("攻め9-10", "攻め7-8", "攻め5-6", "攻め2-4"):
            key = f"{gb} × {ab}"
            print_stat(key, by_gap_attack.get(key, blank_stat()))

    print("\n■ ★★★候補条件")
    for label, cond in candidate_definitions():
        stat = blank_stat()
        for r in rows:
            if cond(r):
                add_outcome(stat, r["first"], r["second"], r["third"])
        print_stat(label, stat)


def main() -> None:
    if len(sys.argv) != 2:
        print(
            "Usage: python3 analysis/analyze_tamagawa_lane4_triple_star_candidates.py INPUT_CSV",
            file=sys.stderr,
        )
        sys.exit(1)

    path = Path(sys.argv[1])
    if not path.exists():
        raise FileNotFoundError(path)

    rows_by_month = {m: [] for m in PROFILE_MONTHS}
    raw_rows = 0

    with path.open("r", encoding="utf-8-sig", newline="") as f:
        reader = csv.DictReader(f)
        for row in reader:
            raw_rows += 1
            months = as_int(row.get("history_months"))
            if months not in rows_by_month:
                continue
            if not is_double_star(row):
                continue

            score = as_float(row.get("lane4_second_score"))
            gap = as_float(row.get("lane4_gap_to_top"))
            attack = as_float(row.get("lane4_attack_potential"))
            st = as_float(row.get("lane4_st_score"))
            straight = as_float(row.get("lane4_straight_score"))
            first = as_int(row.get("first_course"))
            second = as_int(row.get("second_course"))
            third = as_int(row.get("third_course"))

            if None in (score, gap, attack, st, straight):
                continue
            if first not in range(1, 7) or second not in range(1, 7):
                continue

            rows_by_month[months].append({
                "score": float(score),
                "gap": float(gap),
                "attack": float(attack),
                "st": float(st),
                "straight": float(straight),
                "first": int(first),
                "second": int(second),
                "third": int(third) if third in range(1, 7) else None,
            })

    print("=" * 146)
    print("多摩川4コース：★★から★★★候補を探す")
    print("★   = 4攻めサイン")
    print("★★  = ★ + 二次24以上 + TOP差5以内（4軸・頭もあり）")
    print("★★★ = 4頭を強く見てよい条件を探索中")
    print("=" * 146)
    print(f"入力CSV    : {path}")
    print(f"CSV行数    : {raw_rows}")
    print("★★採用     : " + " / ".join(f"{m}ヶ月={len(rows_by_month[m])}" for m in PROFILE_MONTHS))

    for months in PROFILE_MONTHS:
        print_month(months, rows_by_month[months])

    print("\n" + "=" * 146)
    print("判定の目安: 4頭率だけでなくN・2連対・3連対も確認し、12ヶ月/6ヶ月の両方で方向が再現する条件を★★★候補にします。")
    print("※ 12ヶ月/6ヶ月profileは重複期間を含むため、独立サンプルとはみなしません。")
    print("※ この段階では表示・買い目・本命ロジックは変更しません。")
    print("=" * 146)


if __name__ == "__main__":
    main()
