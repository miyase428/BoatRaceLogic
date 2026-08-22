#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
STEP7 探索で増加した3連単コース出目を、前半/後半6か月で再現性確認する。

対象:
- 正式分析対象のみ
- 3～5Cの6month point-in-time 攻め率 = まくり + まくり差し
- 攻め起点 sample_n >= 10
- 主条件 >=15%、強条件 >=20%

候補:
- 買い方へ落としやすい formation（1-攻め艇-* / 攻め艇-1-* 等）
- STEP7全期間探索で増加幅・Lift・件数が比較的良かった exact 出目

注意:
- 候補自体は全期間探索から選んでいるため、この期間分割は再現性確認。
- actual_* は評価ラベルとしてのみ使用する。

Usage:
  python3 analysis/validate_attack_chain_trifecta_patterns_periods.py \
    analysis/output/kimarite_analysis_dataset_20250815_20260814.csv
"""

from __future__ import annotations

import csv
import sys
from datetime import date, datetime
from pathlib import Path

ATTACKERS = (3, 4, 5)
THRESHOLDS = (15.0, 20.0)
MIN_SAMPLE = 10
SPLIT_DATE = date(2026, 2, 15)

EXACT_CANDIDATES = {
    3: (
        "1-3-2", "1-3-4", "1-3-5", "1-3-6",
        "3-1-2", "3-1-4", "3-1-5", "3-1-6",
    ),
    4: (
        "1-4-2", "1-4-5", "1-4-6",
        "4-1-5", "4-1-6",
        "4-5-1", "4-5-2", "4-5-6",
    ),
    5: (
        "1-5-2", "1-5-3", "1-5-4",
        "1-2-5", "1-3-5",
        "5-1-2", "5-1-6",
    ),
}


def to_int(v, default=0):
    try:
        return int(float(v))
    except (TypeError, ValueError):
        return default


def to_float(v, default=0.0):
    try:
        return float(v)
    except (TypeError, ValueError):
        return default


def pct(n, d):
    return 100.0 * n / d if d else 0.0


def read_csv(path: Path):
    with path.open("r", encoding="utf-8-sig", newline="") as f:
        return list(csv.DictReader(f))


def formal(row):
    return (
        to_int(row.get("result_top3_course_complete")) == 1
        and to_int(row.get("result_boat_match")) == 1
    )


def row_date(row):
    s = (row.get("race_date") or "").strip()
    return datetime.strptime(s, "%Y-%m-%d").date()


def sample_ok(row, attacker):
    return to_int(row.get(f"c{attacker}_6m_sample_n")) >= MIN_SAMPLE


def attack_rate(row, attacker):
    return (
        to_float(row.get(f"c{attacker}_6m_makuri"))
        + to_float(row.get(f"c{attacker}_6m_makurizashi"))
    )


def result_tuple(row):
    return (
        to_int(row.get("actual_1st_course")),
        to_int(row.get("actual_2nd_course")),
        to_int(row.get("actual_3rd_course")),
    )


def exact_match(result, pattern):
    try:
        p = tuple(int(x) for x in pattern.split("-"))
    except ValueError:
        return False
    return result == p


def formation_match(result, attacker, key):
    a, b, c = result
    if key == "1-A-*":
        return a == 1 and b == attacker
    if key == "A-1-*":
        return a == attacker and b == 1
    if key == "1-*-A":
        return a == 1 and c == attacker
    if key == "A-*-1":
        return a == attacker and c == 1
    if key == "A-head":
        return a == attacker
    if key == "A-TOP2":
        return attacker in (a, b)
    if key == "A-TOP3":
        return attacker in (a, b, c)
    if key == "A-next-*":
        return attacker == 4 and a == 4 and b == 5
    return False


def calc(rows, attacker, threshold, matcher):
    base_rows = [r for r in rows if sample_ok(r, attacker)]
    cond_rows = [r for r in base_rows if attack_rate(r, attacker) >= threshold]

    base_hit = sum(1 for r in base_rows if matcher(result_tuple(r)))
    cond_hit = sum(1 for r in cond_rows if matcher(result_tuple(r)))

    base_p = base_hit / len(base_rows) if base_rows else 0.0
    cond_p = cond_hit / len(cond_rows) if cond_rows else 0.0
    delta = 100.0 * (cond_p - base_p)
    lift = (cond_p / base_p) if base_p > 0 else 0.0

    return {
        "base_n": len(base_rows),
        "cond_n": len(cond_rows),
        "base_hit": base_hit,
        "cond_hit": cond_hit,
        "base_pct": 100.0 * base_p,
        "cond_pct": 100.0 * cond_p,
        "delta": delta,
        "lift": lift,
    }


def print_row(label, s):
    print(
        f"{label:<12} "
        f"BASE={s['base_hit']:>5}/{s['base_n']:<5} {s['base_pct']:>6.3f}%  "
        f"COND={s['cond_hit']:>4}/{s['cond_n']:<5} {s['cond_pct']:>6.3f}%  "
        f"差={s['delta']:+6.3f}pt  Lift={s['lift']:.3f}"
    )


def section(rows, label):
    print("\n" + "=" * 142)
    print(f"【{label}】 N={len(rows)}")
    print("=" * 142)

    for attacker in ATTACKERS:
        print(f"\n--- {attacker}C攻め起点 ---")

        for threshold in THRESHOLDS:
            print(f"\n  [攻め率 >= {int(threshold)}%]")

            formation_keys = [
                "A-head", "A-TOP2", "A-TOP3",
                "1-A-*", "A-1-*", "1-*-A", "A-*-1",
            ]
            if attacker == 4:
                formation_keys.append("A-next-*")

            print("  formation")
            for key in formation_keys:
                stat = calc(
                    rows,
                    attacker,
                    threshold,
                    lambda result, a=attacker, k=key: formation_match(result, a, k),
                )
                label_name = key.replace("A", str(attacker))
                if key == "A-next-*":
                    label_name = "4-5-*"
                print("    ", end="")
                print_row(label_name, stat)

            print("  exact candidates")
            for pattern in EXACT_CANDIDATES[attacker]:
                stat = calc(
                    rows,
                    attacker,
                    threshold,
                    lambda result, p=pattern: exact_match(result, p),
                )
                print("    ", end="")
                print_row(pattern, stat)


def main():
    if len(sys.argv) != 2:
        print(
            f"Usage: python3 {sys.argv[0]} KIMARITE_ANALYSIS_DATASET_CSV",
            file=sys.stderr,
        )
        sys.exit(1)

    path = Path(sys.argv[1]).resolve()
    if not path.is_file():
        raise RuntimeError(f"CSVがありません: {path}")

    all_rows = [r for r in read_csv(path) if formal(r)]
    front = [r for r in all_rows if row_date(r) < SPLIT_DATE]
    back = [r for r in all_rows if row_date(r) >= SPLIT_DATE]

    dates = [row_date(r) for r in all_rows]

    print("=" * 142)
    print("STEP7 攻め率条件 → 3連単出目候補 期間分割再現性検証")
    print("=" * 142)
    print(f"CSV        : {path.name}")
    print(f"全期間     : {min(dates)} ～ {max(dates)}")
    print(f"正式対象   : {len(all_rows)}")
    print(f"前半       : {len(front)}  ({min(row_date(r) for r in front)} ～ {max(row_date(r) for r in front)})")
    print(f"後半       : {len(back)}  ({min(row_date(r) for r in back)} ～ {max(row_date(r) for r in back)})")
    print("特徴       : 3～5C 6month point-in-time 攻め率 = まくり + まくり差し")
    print(f"sample条件 : 攻め起点 sample_n >= {MIN_SAMPLE}")
    print("条件       : >=15% / >=20%")
    print("注意       : 候補は全期間探索から選択済み。ここでは前後半の方向・Lift・件数の再現性だけを見る。")

    section(front, "前半6か月")
    section(back, "後半6か月")

    print("\n" + "=" * 142)
    print("期間分割検証完了")
    print("次STEP: 前後半で同方向かつ件数が十分な formation / exact 出目だけを買い目候補として残す。")
    print("=" * 142)


if __name__ == "__main__":
    main()
