#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
1コースが実際に「逃げ」で勝ったレースに限定し、
場別の2着・3着コース分布と 1-x-y 出目を全場基準と比較する。

使い方:
  python3 analysis/analyze_lane1_escape_followers_by_stadium.py \
    analysis/output/kimarite_analysis_dataset_20250815_20260814.csv

対象条件:
- result_top3_course_complete = 1
- result_boat_match = 1
- actual_1st_course = 1
- winner_technique = 逃げ

目的:
- 各場の1逃げ時フォロワー傾向を全場平均との差(pt)で確認する
- 2着/3着の外枠到達率を確認する
- 1-x-y 出目の場特有傾向を確認する

注意:
- 場別結果は記述分析。結果を見て後付けで除外条件や予想条件を作らない。
- Nが小さい場は参考値として扱う。
"""

from __future__ import annotations

import csv
import sys
from collections import Counter, defaultdict
from pathlib import Path


def load_rows(path: Path) -> list[dict[str, str]]:
    with path.open("r", encoding="utf-8-sig", newline="") as f:
        return list(csv.DictReader(f))


def as_int(row: dict[str, str], key: str) -> int:
    try:
        return int((row.get(key) or "0").strip() or 0)
    except ValueError:
        return 0


def pct_value(n: int, d: int) -> float:
    return (n / d * 100.0) if d > 0 else 0.0


def pct(n: int, d: int) -> str:
    return f"{pct_value(n, d):.2f}%" if d > 0 else "-"


def diff_pt(n: int, d: int, base: float) -> str:
    if d <= 0:
        return "-"
    return f"{pct_value(n, d) - base:+.2f}pt"


def valid_escape(row: dict[str, str]) -> bool:
    if as_int(row, "result_top3_course_complete") != 1:
        return False
    if as_int(row, "result_boat_match") != 1:
        return False
    if as_int(row, "actual_1st_course") != 1:
        return False
    return (row.get("winner_technique") or "").strip() == "逃げ"


def summarize(rows: list[dict[str, str]]) -> dict:
    second = Counter()
    third = Counter()
    patterns = Counter()
    pairs = Counter()

    for row in rows:
        c2 = as_int(row, "actual_2nd_course")
        c3 = as_int(row, "actual_3rd_course")
        if 1 <= c2 <= 6:
            second[c2] += 1
        if 1 <= c3 <= 6:
            third[c3] += 1
        if 1 <= c2 <= 6 and 1 <= c3 <= 6:
            patterns[f"1-{c2}-{c3}"] += 1
            pairs[(c2, c3)] += 1

    return {
        "n": len(rows),
        "second": second,
        "third": third,
        "patterns": patterns,
        "pairs": pairs,
    }


def main() -> None:
    if len(sys.argv) != 2:
        print(
            "Usage: python3 analysis/analyze_lane1_escape_followers_by_stadium.py DATASET_CSV",
            file=sys.stderr,
        )
        sys.exit(1)

    path = Path(sys.argv[1]).resolve()
    if not path.is_file():
        raise RuntimeError(f"CSVがありません: {path}")

    rows = load_rows(path)
    escapes = [row for row in rows if valid_escape(row)]
    if not escapes:
        raise RuntimeError("1コース逃げの正式対象がありません")

    base = summarize(escapes)
    base_n = base["n"]
    base_second = {
        c: pct_value(base["second"][c], base_n)
        for c in range(2, 7)
    }
    base_third = {
        c: pct_value(base["third"][c], base_n)
        for c in range(2, 7)
    }
    base_patterns = {
        p: pct_value(n, base_n)
        for p, n in base["patterns"].items()
    }

    by_stadium: dict[str, list[dict[str, str]]] = defaultdict(list)
    for row in escapes:
        stadium = (row.get("stadium_name") or "").strip() or "(不明)"
        by_stadium[stadium].append(row)

    print()
    print("=" * 128)
    print("1逃げ成立時の2着・3着コース分布（場別 × 全場比較）")
    print("=" * 128)
    print(f"CSV行          : {len(rows)}")
    print(f"全場1逃げ対象  : {base_n}")
    print(f"場数           : {len(by_stadium)}")

    print()
    print("【全場基準】")
    print("2着 : " + " / ".join(
        f"{c}={base_second[c]:.2f}%" for c in range(2, 7)
    ))
    print("3着 : " + " / ".join(
        f"{c}={base_third[c]:.2f}%" for c in range(2, 7)
    ))

    for stadium in sorted(by_stadium):
        stat = summarize(by_stadium[stadium])
        n = stat["n"]

        print()
        print("-" * 128)
        print(f"【{stadium}】 1逃げ N={n}")

        print("  2着コース")
        print("    コース      件数       構成比      全場差")
        for c in range(2, 7):
            count = stat["second"][c]
            print(
                f"      {c:<2}      {count:>6}      {pct(count, n):>8}      "
                f"{diff_pt(count, n, base_second[c]):>9}"
            )

        print("  3着コース")
        print("    コース      件数       構成比      全場差")
        for c in range(2, 7):
            count = stat["third"][c]
            print(
                f"      {c:<2}      {count:>6}      {pct(count, n):>8}      "
                f"{diff_pt(count, n, base_third[c]):>9}"
            )

        second_outer = sum(stat["second"][c] for c in (5, 6))
        third_outer = sum(stat["third"][c] for c in (5, 6))
        base_second_outer = base_second[5] + base_second[6]
        base_third_outer = base_third[5] + base_third[6]
        print(
            "  外枠到達(5・6C) : "
            f"2着={pct(second_outer, n)} ({diff_pt(second_outer, n, base_second_outer)}) / "
            f"3着={pct(third_outer, n)} ({diff_pt(third_outer, n, base_third_outer)})"
        )

        print("  1-x-y 出目 TOP10")
        for pattern, count in stat["patterns"].most_common(10):
            local = pct_value(count, n)
            base_rate = base_patterns.get(pattern, 0.0)
            print(
                f"    {pattern:<8} {count:>5}件  {local:>6.2f}%  "
                f"全場差={local - base_rate:+.2f}pt"
            )

        print("  2着コース別 → 3着コース内訳 TOP3")
        for second_course in range(2, 7):
            total_second = stat["second"][second_course]
            if total_second == 0:
                continue
            followers = Counter()
            for (c2, c3), count in stat["pairs"].items():
                if c2 == second_course:
                    followers[c3] += count
            text = ", ".join(
                f"{c3}C {count}件({pct(count, total_second)})"
                for c3, count in followers.most_common(3)
            )
            print(f"    2着={second_course}C N={total_second:>4} → {text}")

    print()
    print("=" * 128)
    print("場別比較完了")
    print("※ 全場差は各場の構成比 - 全場構成比。場別Nが小さい場合は参考値として扱う。")
    print("※ この結果を見て後付けの場除外・閾値条件を作らず、まず場特性の記述情報として利用する。")
    print("=" * 128)


if __name__ == "__main__":
    main()
