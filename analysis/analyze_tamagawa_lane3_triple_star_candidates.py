#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
多摩川3コースの★★条件から、さらに3頭を強く見られる★★★候補を探す。

前提:
★
  - 過去3コースの攻め率（まくり+まくり差し） >= 15%
★★
  - ★
  - 3号艇の現行二次スコア >= 30
  - 二次トップとの差 <= 2
★★★
  - 未確定。本スクリプトで候補探索する。

入力CSV:
  analysis/output/tamagawa_lane3_strong_condition_second_eval_YYYYMMDD_YYYYMMDD.csv

主な確認:
- ★★全体
- 二次スコア帯（30-31 / 32-33 / 34+）
- TOP差（0 / 1-2）
- 攻め成分（展示ST点+直線点）
- 展示ST点
- 直線点
- 展示タイム点 / 周回点 / 周り足点
- 主要クロス
- 事前定義した★★★候補条件の12ヶ月/6ヶ月再現性

Usage:
  python3 analysis/analyze_tamagawa_lane3_triple_star_candidates.py \
    analysis/output/tamagawa_lane3_strong_condition_second_eval_20250901_20260909.csv
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
    score = as_float(row.get("lane3_second_score"))
    gap = as_float(row.get("lane3_gap_to_top"))
    return score is not None and gap is not None and score >= 30.0 and gap <= 2.0


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


def score_band(score: float) -> str:
    if score >= 34.0:
        return "二次34+"
    if score >= 32.0:
        return "二次32-33"
    return "二次30-31"


def gap_band(gap: float) -> str:
    return "TOP差0" if gap < 1e-9 else "TOP差1-2"


def attack_band(value: float) -> str:
    if value >= 9.0:
        return "攻め9-10"
    if value >= 7.0:
        return "攻め7-8"
    if value >= 5.0:
        return "攻め5-6"
    return "攻め2-4"


def point_label(prefix: str, value: float) -> str:
    if abs(value - round(value)) < 1e-9:
        return f"{prefix}{int(round(value))}"
    return f"{prefix}{value:.1f}"


def print_stat(label: str, stat: dict, base: dict | None = None) -> None:
    n = stat["n"]
    head = pct(stat["first"], n)
    top2 = pct(stat["top2"], n)
    top3 = pct(stat["top3"], n)
    delta = ""
    if base is not None and base["n"] > 0:
        bhead = pct(base["first"], base["n"])
        delta = f"  Δ頭={head - bhead:+6.2f}pt"
    small = "  [N小]" if 0 < n < 10 else ""
    print(
        f"{label:<40} N={n:3d}  "
        f"3頭={head:6.2f}%  "
        f"32着={pct(stat['second'], n):6.2f}%  "
        f"33着={pct(stat['third'], n):6.2f}%  "
        f"3-2連対={top2:6.2f}%  "
        f"3-3連対={top3:6.2f}%{delta}{small}"
    )


def candidate_definitions() -> list[tuple[str, callable]]:
    return [
        ("C1 二次32+", lambda r: r["score"] >= 32.0),
        ("C2 二次34+", lambda r: r["score"] >= 34.0),
        ("C3 直線5", lambda r: r["straight"] >= 5.0),
        ("C4 攻め9+", lambda r: r["attack"] >= 9.0),
        ("C5 二次32+ × 直線5", lambda r: r["score"] >= 32.0 and r["straight"] >= 5.0),
        ("C6 TOP差0 × 直線5", lambda r: r["gap"] < 1e-9 and r["straight"] >= 5.0),
        ("C7 二次32+ × 攻め9+", lambda r: r["score"] >= 32.0 and r["attack"] >= 9.0),
        ("C8 TOP差0 × 攻め9+", lambda r: r["gap"] < 1e-9 and r["attack"] >= 9.0),
        ("C9 二次32+ × ST5", lambda r: r["score"] >= 32.0 and r["st"] >= 5.0),
        ("C10 TOP差0 × ST5", lambda r: r["gap"] < 1e-9 and r["st"] >= 5.0),
        ("C11 直線5 × 周り足4+", lambda r: r["straight"] >= 5.0 and r["mawari"] >= 4.0),
        ("C12 直線5 × 周回4+", lambda r: r["straight"] >= 5.0 and r["lap"] >= 4.0),
    ]


def print_month(months: int, rows: list[dict]) -> None:
    print("\n" + "-" * 156)
    print(f"【過去{months}ヶ月profile：★★内の★★★候補探索】")
    print("-" * 156)

    all_stat = blank_stat()
    by_score = defaultdict(blank_stat)
    by_gap = defaultdict(blank_stat)
    by_attack = defaultdict(blank_stat)
    by_st = defaultdict(blank_stat)
    by_straight = defaultdict(blank_stat)
    by_ex = defaultdict(blank_stat)
    by_lap = defaultdict(blank_stat)
    by_mawari = defaultdict(blank_stat)
    by_score_straight = defaultdict(blank_stat)
    by_gap_straight = defaultdict(blank_stat)

    for r in rows:
        first, second, third = r["first"], r["second"], r["third"]
        add_outcome(all_stat, first, second, third)

        sb = score_band(r["score"])
        gb = gap_band(r["gap"])
        ab = attack_band(r["attack"])
        stb = point_label("ST", r["st"])
        strb = point_label("直線", r["straight"])
        exb = point_label("展示", r["ex"])
        lapb = point_label("周回", r["lap"])
        mb = point_label("周り足", r["mawari"])

        add_outcome(by_score[sb], first, second, third)
        add_outcome(by_gap[gb], first, second, third)
        add_outcome(by_attack[ab], first, second, third)
        add_outcome(by_st[stb], first, second, third)
        add_outcome(by_straight[strb], first, second, third)
        add_outcome(by_ex[exb], first, second, third)
        add_outcome(by_lap[lapb], first, second, third)
        add_outcome(by_mawari[mb], first, second, third)
        add_outcome(by_score_straight[f"{sb} × {strb}"], first, second, third)
        add_outcome(by_gap_straight[f"{gb} × {strb}"], first, second, third)

    print("\n■ ★★全体")
    print_stat("★★", all_stat)

    print("\n■ 二次スコア帯")
    for key in ("二次30-31", "二次32-33", "二次34+"):
        print_stat(key, by_score.get(key, blank_stat()), all_stat)

    print("\n■ TOP差")
    for key in ("TOP差0", "TOP差1-2"):
        print_stat(key, by_gap.get(key, blank_stat()), all_stat)

    print("\n■ 攻め成分（展示ST点＋直線点）")
    for key in ("攻め9-10", "攻め7-8", "攻め5-6", "攻め2-4"):
        print_stat(key, by_attack.get(key, blank_stat()), all_stat)

    def print_points(title: str, bucket: dict, prefix: str):
        print(f"\n■ {title}")
        for key in sorted(bucket, key=lambda x: float(x.replace(prefix, "")), reverse=True):
            print_stat(key, bucket[key], all_stat)

    print_points("展示ST点", by_st, "ST")
    print_points("直線点", by_straight, "直線")
    print_points("展示タイム点", by_ex, "展示")
    print_points("周回点", by_lap, "周回")
    print_points("周り足点", by_mawari, "周り足")

    print("\n■ 二次スコア帯 × 直線点")
    for sb in ("二次30-31", "二次32-33", "二次34+"):
        for strb in ("直線5", "直線4", "直線3", "直線2", "直線1"):
            key = f"{sb} × {strb}"
            print_stat(key, by_score_straight.get(key, blank_stat()), all_stat)

    print("\n■ TOP差 × 直線点")
    for gb in ("TOP差0", "TOP差1-2"):
        for strb in ("直線5", "直線4", "直線3", "直線2", "直線1"):
            key = f"{gb} × {strb}"
            print_stat(key, by_gap_straight.get(key, blank_stat()), all_stat)

    print("\n■ ★★★候補条件")
    for label, cond in candidate_definitions():
        stat = blank_stat()
        for r in rows:
            if cond(r):
                add_outcome(stat, r["first"], r["second"], r["third"])
        print_stat(label, stat, all_stat)


def main() -> None:
    if len(sys.argv) != 2:
        print(
            "Usage: python3 analysis/analyze_tamagawa_lane3_triple_star_candidates.py INPUT_CSV",
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

            score = as_float(row.get("lane3_second_score"))
            gap = as_float(row.get("lane3_gap_to_top"))
            attack = as_float(row.get("lane3_attack_potential"))
            st = as_float(row.get("lane3_st_score"))
            straight = as_float(row.get("lane3_straight_score"))
            ex = as_float(row.get("lane3_ex_score"))
            lap = as_float(row.get("lane3_lap_score"))
            mawari = as_float(row.get("lane3_mawari_score"))
            first = as_int(row.get("first_course"))
            second = as_int(row.get("second_course"))
            third = as_int(row.get("third_course"))

            if None in (score, gap, attack, st, straight, ex, lap, mawari):
                continue
            if first not in range(1, 7) or second not in range(1, 7):
                continue

            rows_by_month[months].append({
                "score": float(score),
                "gap": float(gap),
                "attack": float(attack),
                "st": float(st),
                "straight": float(straight),
                "ex": float(ex),
                "lap": float(lap),
                "mawari": float(mawari),
                "first": int(first),
                "second": int(second),
                "third": int(third) if third in range(1, 7) else None,
            })

    print("=" * 156)
    print("多摩川3コース：★★から★★★候補を探す")
    print("★   = 3攻めサイン（3Cまくり+まくり差し率15%以上）")
    print("★★  = ★ + 二次30以上 + TOP差2以内（3軸・頭もあり）")
    print("★★★ = 3頭を強く見てよい条件を探索中")
    print("=" * 156)
    print(f"入力CSV    : {path}")
    print(f"CSV行数    : {raw_rows}")
    print("★★採用     : " + " / ".join(f"{m}ヶ月={len(rows_by_month[m])}" for m in PROFILE_MONTHS))

    for months in PROFILE_MONTHS:
        print_month(months, rows_by_month[months])

    print("\n" + "=" * 156)
    print("判定の目安: 3頭率だけでなくN・2連対・3連対も確認し、12ヶ月/6ヶ月の両方で方向が再現する条件を★★★候補にします。")
    print("※ 直線5は有力候補ですが、今回の探索結果を見てから採用可否を決めます。")
    print("※ 12ヶ月/6ヶ月profileは重複期間を含むため独立サンプルではありません。")
    print("※ この段階では表示・買い目・本命ロジックは変更しません。")
    print("=" * 156)


if __name__ == "__main__":
    main()
