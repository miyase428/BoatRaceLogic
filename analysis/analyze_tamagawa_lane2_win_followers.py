#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""多摩川2Cが1着になった場合の2着・3着コース分布を条件別に集計する。"""

from __future__ import annotations

import csv
import sys
from collections import Counter, OrderedDict
from datetime import date, timedelta
from pathlib import Path


def num(v):
    try: return float(v)
    except (TypeError, ValueError): return None


def integer(v):
    x = num(v)
    return None if x is None else int(x)


def pct(n, d): return 100.0 * n / d if d else 0.0


def months_ago(d: date, months: int) -> date:
    total = d.year * 12 + d.month - 1 - months
    y, m = divmod(total, 12)
    return date(y, m + 1, d.day)


def load(path: Path):
    rows = []
    with path.open("r", encoding="utf-8-sig", newline="") as f:
        for raw in csv.DictReader(f):
            rows.append({
                "date": date.fromisoformat(raw["race_date"]),
                "n": num(raw["p2_12_n"]), "win": num(raw["p2_12_win"]),
                "sashi": num(raw["p2_12_sashi"]), "attack": num(raw["p2_12_attack"]),
                "rank": integer(raw["second_rank"]), "lap": num(raw["lap_score"]),
                "first": integer(raw["first"]), "second": integer(raw["second"]),
                "third": integer(raw["third"]), "st21": raw["st21"],
            })
    return rows


CONDITIONS = OrderedDict([
    ("BASE", "2C履歴あり（基準）"),
    ("STAR", "2C★ 差し率10%以上"),
    ("W15", "過去1着率15%以上"),
    ("DOUBLE", "2C★★ ★＋二次3位以内または周回4以上"),
    ("TRIPLE", "2C★★★ ★＋二次順位1位"),
])


def matches(r, key):
    base = r["n"] is not None and r["n"] > 0
    star = base and r["sashi"] is not None and r["sashi"] >= 10
    if key == "BASE": return base
    if key == "STAR": return star
    if key == "W15": return base and r["win"] is not None and r["win"] >= 15
    if key == "DOUBLE": return star and r["rank"] is not None and (r["rank"] <= 3 or (r["lap"] is not None and r["lap"] >= 4))
    if key == "TRIPLE": return star and r["rank"] == 1
    raise ValueError(key)


def aggregate(rows, start, end, key):
    selected = [r for r in rows if start <= r["date"] <= end and matches(r, key) and r["first"] == 2]
    second = Counter(r["second"] for r in selected if r["second"] in range(1, 7))
    third = Counter(r["third"] for r in selected if r["third"] in range(1, 7))
    exacta = Counter((r["second"],) for r in selected if r["second"] in range(1, 7))
    trifecta = Counter((r["second"], r["third"]) for r in selected if r["second"] in range(1, 7) and r["third"] in range(1, 7))
    return len(selected), second, third, exacta, trifecta


def print_result(title, result):
    n, second, third, exacta, trifecta = result
    def text(counter):
        return " / ".join(f"{c}:{v}件({pct(v, n):.1f}%)" for c, v in sorted(counter.items(), key=lambda x: (-x[1], x[0]))) or "-"
    print(f"\n{title}  2頭件数={n}")
    print("  2着: " + text(second)); print("  3着: " + text(third))
    ex = sorted(exacta.items(), key=lambda x: (-x[1], x[0]))[:6]
    tri = sorted(trifecta.items(), key=lambda x: (-x[1], x[0]))[:10]
    print("  2連単上位: " + " / ".join(f"2-{k[0]} {v}件({pct(v,n):.1f}%)" for k,v in ex))
    print("  3連単上位: " + " / ".join(f"2-{k[0]}-{k[1]} {v}件({pct(v,n):.1f}%)" for k,v in tri))


def main():
    if len(sys.argv) != 2: raise SystemExit("Usage: ... CSV_PATH")
    rows = load(Path(sys.argv[1])); end = max(r["date"] for r in rows); anchor = end + timedelta(days=1)
    windows = [("直近12ヶ月", months_ago(anchor, 12), end), ("直近24ヶ月", months_ago(anchor, 24), end)]
    print("=" * 150); print("多摩川2コース1着時：2着・3着コース分布"); print("=" * 150)
    for label, start, finish in windows:
        print(f"\n【{label}】 {start}～{finish}")
        for key, title in CONDITIONS.items(): print_result(title, aggregate(rows, start, finish, key))
    print("\n注意: 2C★★★は二次順位1位の母数が小さいため、相手候補は参考扱い。払戻・オッズは未使用。")


if __name__ == "__main__": main()
