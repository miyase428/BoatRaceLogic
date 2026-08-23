#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
STEP9.5 筋舟券 基礎検証。

ユーザー提示の筋舟券表のうち、既存の1年決まり手分析データだけで
客観的に検証できる「2～6コースの勝者決まり手 → 2着/3着候補」を確認する。

重要:
- winner_technique / actual_* は実結果ラベルとしてのみ使用する。
- 今回は「そのコースが実際にその決まり手で1着になったレース」を条件にする。
- 画像の「購入検討」列は、攻め艇が勝たず外隣が頭になる展開も含むため、
  winner_techniqueだけでは直接判定できない。次段階でpre-race決まり手率/ST等から検証する。
- 1号艇欄の「ST揃う / 2が凹む / 3が凹む / 23が凹む」は
  スリット/STデータが必要なので今回は対象外。

画像から読み取った基礎筋:
- 2Cまくり      : 2着=34 / 3着=3456
- 2C差し        : 2着=13 / 3着=1345
- 3Cまくり      : 2着=45 / 3着=1456
- 3Cまくり差し  : 2着=14 / 3着=全
- 4Cまくり      : 2着=56 / 3着=156
- 4Cまくり差し  : 2着=15 / 3着=全
- 5Cまくり差し  : 2着=16 / 3着=全
- 6Cまくり差し  : 2着=15 / 3着=全

Usage:
  python3 analysis/validate_suji_ticket_baseline.py \
    analysis/output/kimarite_analysis_dataset_20250815_20260814.csv
"""

from __future__ import annotations

import csv
import sys
from collections import Counter
from datetime import date, datetime
from pathlib import Path

SPLIT = date(2026, 2, 15)

RULES = [
    ("2Cまくり", 2, "まくり", {3, 4}, {3, 4, 5, 6}),
    ("2C差し", 2, "差し", {1, 3}, {1, 3, 4, 5}),
    ("3Cまくり", 3, "まくり", {4, 5}, {1, 4, 5, 6}),
    ("3Cまくり差し", 3, "まくり差し", {1, 4}, None),
    ("4Cまくり", 4, "まくり", {5, 6}, {1, 5, 6}),
    ("4Cまくり差し", 4, "まくり差し", {1, 5}, None),
    ("5Cまくり差し", 5, "まくり差し", {1, 6}, None),
    ("6Cまくり差し", 6, "まくり差し", {1, 5}, None),
]


def to_int(v, default=0):
    try:
        return int(float(v))
    except (TypeError, ValueError):
        return default


def pct(n, d):
    return 100.0 * n / d if d else 0.0


def parse_date(row):
    try:
        return datetime.strptime((row.get("race_date") or "").strip(), "%Y-%m-%d").date()
    except ValueError:
        return None


def formal(row):
    return (
        to_int(row.get("result_top3_course_complete")) == 1
        and to_int(row.get("result_boat_match")) == 1
    )


def normalize_technique(v):
    s = (v or "").strip()
    # データ側の表記ゆれを最低限吸収
    s = s.replace("まくりざし", "まくり差し")
    return s


def second_third_sets(head, second_set, third_set):
    # 「全」は頭以外の全コースとして評価する
    if third_set is None:
        third_set = {c for c in range(1, 7) if c != head}
    return set(second_set), set(third_set)


def evaluate(rows, head, technique, second_set, third_set):
    second_set, third_set = second_third_sets(head, second_set, third_set)

    n = 0
    second_hit = 0
    third_hit = 0
    both_hit = 0
    second_dist = Counter()
    third_dist = Counter()
    exact = Counter()

    for r in rows:
        if not formal(r):
            continue
        if to_int(r.get("actual_1st_course")) != head:
            continue
        if normalize_technique(r.get("winner_technique")) != technique:
            continue

        a2 = to_int(r.get("actual_2nd_course"))
        a3 = to_int(r.get("actual_3rd_course"))
        if not (1 <= a2 <= 6 and 1 <= a3 <= 6):
            continue

        n += 1
        second_dist[a2] += 1
        third_dist[a3] += 1
        exact[(head, a2, a3)] += 1

        h2 = a2 in second_set
        h3 = a3 in third_set
        second_hit += int(h2)
        third_hit += int(h3)
        both_hit += int(h2 and h3)

    return {
        "n": n,
        "second_hit": second_hit,
        "third_hit": third_hit,
        "both_hit": both_hit,
        "second_dist": second_dist,
        "third_dist": third_dist,
        "exact": exact,
        "second_set": second_set,
        "third_set": third_set,
    }


def fmt_set(values):
    return "".join(str(x) for x in sorted(values)) or "-"


def dist_line(counter, n, exclude):
    parts = []
    for c in range(1, 7):
        if c == exclude:
            continue
        parts.append(f"{c}C={pct(counter[c], n):5.1f}%({counter[c]})")
    return " / ".join(parts)


def top_exact(counter, n, limit=5):
    if not n:
        return "-"
    rows = []
    for (a1, a2, a3), cnt in counter.most_common(limit):
        rows.append(f"{a1}-{a2}-{a3} {pct(cnt, n):.1f}%({cnt})")
    return " / ".join(rows)


def print_rule(label, rule, result):
    name, head, technique, _, _ = rule
    n = result["n"]
    print(f"\n{name}  [{label}]  N={n}")
    print("-" * 118)
    print(
        f"画像筋: 2着={fmt_set(result['second_set'])} / "
        f"3着={fmt_set(result['third_set'])}"
    )
    print(
        f"筋カバー: 2着={pct(result['second_hit'], n):6.2f}%  "
        f"3着={pct(result['third_hit'], n):6.2f}%  "
        f"両方={pct(result['both_hit'], n):6.2f}%"
    )
    print("実2着分布: " + dist_line(result["second_dist"], n, head))
    print("実3着分布: " + dist_line(result["third_dist"], n, head))
    print("上位出目  : " + top_exact(result["exact"], n))


def main():
    if len(sys.argv) != 2:
        print(f"Usage: python3 {sys.argv[0]} DATASET_CSV", file=sys.stderr)
        sys.exit(1)

    path = Path(sys.argv[1]).resolve()
    if not path.is_file():
        raise RuntimeError(f"CSVがありません: {path}")

    with path.open("r", encoding="utf-8-sig", newline="") as f:
        rows = list(csv.DictReader(f))

    formal_rows = [r for r in rows if formal(r)]
    front = [r for r in formal_rows if (parse_date(r) or date.min) < SPLIT]
    back = [r for r in formal_rows if (parse_date(r) or date.min) >= SPLIT]

    print("=" * 118)
    print("STEP9.5 筋舟券 基礎パターン実データ検証")
    print("=" * 118)
    print(f"CSV          : {path.name}")
    print(f"正式分析対象 : {len(formal_rows)}")
    print(f"前半         : {len(front)}  (～2026-02-14)")
    print(f"後半         : {len(back)}  (2026-02-15～)")
    print("判定         : 実際に該当コースが指定決まり手で1着になったレース")
    print("注意         : 購入検討列・1号艇ST筋はこのSTEPでは未検証")

    periods = [("前半", front), ("後半", back), ("全期間", formal_rows)]

    summary = []
    for rule in RULES:
        name, head, technique, second_set, third_set = rule
        results = {}
        for label, period_rows in periods:
            result = evaluate(period_rows, head, technique, second_set, third_set)
            results[label] = result
            print_rule(label, rule, result)

        summary.append((rule, results))

    print("\n" + "=" * 118)
    print("期間再現性サマリー")
    print("=" * 118)
    print("筋                  前半N  後半N | 2着筋 前半→後半 | 両方筋 前半→後半")
    print("-" * 118)
    for rule, results in summary:
        name = rule[0]
        f = results["前半"]
        b = results["後半"]
        print(
            f"{name:<18} {f['n']:>6} {b['n']:>6} | "
            f"{pct(f['second_hit'], f['n']):6.2f}%→{pct(b['second_hit'], b['n']):6.2f}% | "
            f"{pct(f['both_hit'], f['n']):6.2f}%→{pct(b['both_hit'], b['n']):6.2f}%"
        )

    print("=" * 118)
    print("次判断: 前後半で同じ2着傾向が出る筋だけを、pre-race決まり手率条件へ接続して実戦検証する。")
    print("=" * 118)


if __name__ == "__main__":
    main()
