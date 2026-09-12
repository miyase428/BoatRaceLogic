#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
多摩川4コースの★★内で、C1〜C10の★★★候補を長期・短期の両面で一括比較する。

前段CSV:
  analysis/output/tamagawa_lane4_star_stability_YYYYMMDD.csv

★★定義:
  - ★
  - 二次スコア >= 24
  - 二次トップ差 <= 5

候補:
  C1  二次30+
  C2  二次27+ × TOP差2以内
  C3  二次27+ × 攻め7+
  C4  TOP差2以内 × 攻め7+
  C5  二次30+ × 攻め7+
  C6  二次27+ × TOP差2以内 × 攻め7+
  C7  二次27+ × ST5
  C8  二次27+ × 直線4+
  C9  TOP差2以内 × ST5
  C10 TOP差2以内 × 直線4+

確認:
- 長期: 18ヶ月 / 24ヶ月
- 短期: 6ヶ月 × 4非重複ブロック
- 各候補のN、4頭、2連対、3連対、1or4頭
- 各期間の★★比4頭率差
- 6ヶ月4ブロックで★★を上回った回数

Usage:
  python3 analysis/analyze_tamagawa_lane4_triple_star_candidate_stability_compare.py \
    analysis/output/tamagawa_lane4_star_stability_20260909.csv 2026-09-09
"""

from __future__ import annotations

import csv
import sys
from datetime import date, timedelta
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from analyze_tamagawa_boaters_hypothesis import months_ago, parse_date  # noqa: E402


def pct(num: int, den: int) -> float:
    return 100.0 * num / den if den else 0.0


def f(row: dict, key: str) -> float:
    try:
        return float(row.get(key, ""))
    except (TypeError, ValueError):
        return float("nan")


def i(row: dict, key: str) -> int | None:
    try:
        v = row.get(key, "")
        if v in (None, ""):
            return None
        return int(float(v))
    except (TypeError, ValueError):
        return None


def is_double_star(row: dict) -> bool:
    score = f(row, "lane4_second_score")
    gap = f(row, "lane4_gap_to_top")
    return score >= 24.0 and gap <= 5.0


CANDIDATES = {
    "C1 二次30+": lambda r: f(r, "lane4_second_score") >= 30.0,
    "C2 二次27+×差2以内": lambda r: f(r, "lane4_second_score") >= 27.0 and f(r, "lane4_gap_to_top") <= 2.0,
    "C3 二次27+×攻め7+": lambda r: f(r, "lane4_second_score") >= 27.0 and f(r, "lane4_attack_potential") >= 7.0,
    "C4 差2以内×攻め7+": lambda r: f(r, "lane4_gap_to_top") <= 2.0 and f(r, "lane4_attack_potential") >= 7.0,
    "C5 二次30+×攻め7+": lambda r: f(r, "lane4_second_score") >= 30.0 and f(r, "lane4_attack_potential") >= 7.0,
    "C6 二次27+×差2以内×攻め7+": lambda r: f(r, "lane4_second_score") >= 27.0 and f(r, "lane4_gap_to_top") <= 2.0 and f(r, "lane4_attack_potential") >= 7.0,
    "C7 二次27+×ST5": lambda r: f(r, "lane4_second_score") >= 27.0 and f(r, "lane4_st_score") >= 5.0,
    "C8 二次27+×直線4+": lambda r: f(r, "lane4_second_score") >= 27.0 and f(r, "lane4_straight_score") >= 4.0,
    "C9 差2以内×ST5": lambda r: f(r, "lane4_gap_to_top") <= 2.0 and f(r, "lane4_st_score") >= 5.0,
    "C10 差2以内×直線4+": lambda r: f(r, "lane4_gap_to_top") <= 2.0 and f(r, "lane4_straight_score") >= 4.0,
}


def blank_stat() -> dict:
    return {"n": 0, "first": 0, "second": 0, "third": 0, "top2": 0, "top3": 0, "one_or_four": 0}


def add(stat: dict, row: dict) -> None:
    first = i(row, "first_course")
    second = i(row, "second_course")
    third = i(row, "third_course")
    if first is None or second is None:
        return
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
    if first in (1, 4):
        stat["one_or_four"] += 1


def calc_windows(end_date: date):
    anchor = end_date + timedelta(days=1)
    long_windows = [
        ("直近18ヶ月", months_ago(anchor, 18), end_date),
        ("直近24ヶ月", months_ago(anchor, 24), end_date),
    ]
    labels = ("直近0-6ヶ月", "6-12ヶ月前", "12-18ヶ月前", "18-24ヶ月前")
    short = []
    for idx, label in enumerate(labels):
        end_ex = months_ago(anchor, idx * 6)
        start = months_ago(anchor, (idx + 1) * 6)
        short.append((label, start, end_ex - timedelta(days=1)))
    return long_windows, short


def load_rows(path: Path) -> list[dict]:
    out = []
    with path.open("r", encoding="utf-8-sig", newline="") as fh:
        for row in csv.DictReader(fh):
            try:
                row["_date"] = parse_date(row["race_date"])
            except Exception:
                continue
            if is_double_star(row):
                out.append(row)
    return out


def aggregate(rows: list[dict], start: date, end: date, pred=None) -> dict:
    s = blank_stat()
    for row in rows:
        d = row["_date"]
        if not (start <= d <= end):
            continue
        if pred is not None and not pred(row):
            continue
        add(s, row)
    return s


def fmt_stat(s: dict) -> str:
    n = s["n"]
    small = " [N小]" if 0 < n < 10 else ""
    return (
        f"N={n:3d}  4頭={pct(s['first'], n):6.2f}%  "
        f"2連対={pct(s['top2'], n):6.2f}%  3連対={pct(s['top3'], n):6.2f}%  "
        f"1or4頭={pct(s['one_or_four'], n):6.2f}%{small}"
    )


def print_window(label: str, start: date, end: date, rows: list[dict]) -> dict[str, float]:
    base = aggregate(rows, start, end)
    base_rate = pct(base["first"], base["n"])
    print(f"\n■ {label}  {start} ～ {end}")
    print(f"★★ {fmt_stat(base)}")
    deltas = {}
    for name, pred in CANDIDATES.items():
        s = aggregate(rows, start, end, pred)
        rate = pct(s["first"], s["n"])
        delta = rate - base_rate if s["n"] > 0 and base["n"] > 0 else 0.0
        deltas[name] = delta
        print(f"{name:<27} {fmt_stat(s)}  Δ4頭={delta:+6.2f}pt")
    return deltas


def main() -> None:
    if len(sys.argv) != 3:
        print("Usage: python3 analysis/analyze_tamagawa_lane4_triple_star_candidate_stability_compare.py INPUT_CSV END_DATE", file=sys.stderr)
        sys.exit(1)

    path = Path(sys.argv[1])
    if not path.exists():
        raise FileNotFoundError(path)
    end_date = parse_date(sys.argv[2])
    rows = load_rows(path)
    long_windows, short_windows = calc_windows(end_date)

    print("=" * 150)
    print("多摩川4コース：★★★候補 C1〜C10 長期・6ヶ月ブロック安定性比較")
    print("★★ = ★ + 二次24以上 + TOP差5以内")
    print("目的 = ★★より4頭率が安定して上がる条件だけを★★★候補として残す")
    print("=" * 150)
    print(f"入力CSV : {path}")
    print(f"★★行数 : {len(rows)}")

    print("\n" + "-" * 150)
    print("【長期確認】")
    print("-" * 150)
    long_deltas = {}
    for label, start, end in long_windows:
        long_deltas[label] = print_window(label, start, end, rows)

    print("\n" + "-" * 150)
    print("【短期確認：6ヶ月 × 4 非重複ブロック】")
    print("-" * 150)
    block_deltas = []
    for label, start, end in short_windows:
        block_deltas.append((label, print_window(label, start, end, rows)))

    print("\n" + "-" * 150)
    print("【候補まとめ】")
    print("-" * 150)
    print("候補                         18m差    24m差   6ヶ月で上回る回数   6ヶ月で同等以上")
    summary = []
    for name in CANDIDATES:
        d18 = long_deltas["直近18ヶ月"][name]
        d24 = long_deltas["直近24ヶ月"][name]
        wins = sum(1 for _, ds in block_deltas if ds[name] > 1e-9)
        nonloss = sum(1 for _, ds in block_deltas if ds[name] >= -1e-9)
        summary.append((wins, nonloss, d24, d18, name))
        print(f"{name:<28} {d18:+7.2f}pt {d24:+7.2f}pt       {wins}/4              {nonloss}/4")

    print("\n■ 参考順位（6ヶ月で上回る回数 → 24ヶ月差 → 18ヶ月差）")
    for idx, (_, _, d24, d18, name) in enumerate(sorted(summary, key=lambda x: (-x[0], -x[2], -x[3], x[4])), start=1):
        print(f"  {idx:2d}. {name}  24m={d24:+.2f}pt / 18m={d18:+.2f}pt")

    print("\n判定目安:")
    print("  - まず6ヶ月4ブロック中3以上で★★の4頭率を上回るか")
    print("  - 18ヶ月・24ヶ月の両方でもプラスか")
    print("  - Nが極端に小さくないか、2連対・3連対が崩れていないかも併記して判断")
    print("  - 条件探索結果なので、最終採用前に前方検証も行う")
    print("=" * 150)


if __name__ == "__main__":
    main()
