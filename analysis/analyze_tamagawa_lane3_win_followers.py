#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""多摩川3コース強条件で3が1着になった時の相手コースを集計する。

入力は analyze_tamagawa_lane3_triple_star_stability.py の、各レース時点の
過去12ヶ月profileで作ったCSV。従って結果は抽出後の集計専用であり、
条件判定に着順・払戻を混入させない。

Usage:
  python3 analysis/analyze_tamagawa_lane3_win_followers.py INPUT_CSV END_DATE
"""

from __future__ import annotations

import csv
import sys
from collections import Counter
from datetime import date, timedelta
from pathlib import Path

FOLLOWERS = (1, 2, 4, 5, 6)
CONDITIONS = (
    ("STAR", "★ 攻め率15%以上", lambda r: True),
    ("DOUBLE", "★★ 二次30+ × TOP差2以内", lambda r: r["score"] >= 30 and r["gap"] <= 2),
    ("STRICT", "強化 直線5 × 周り足4+", lambda r: r["score"] >= 30 and r["gap"] <= 2 and r["straight"] >= 5 and r["mawari"] >= 4),
)


def pct(x, n):
    return 100 * x / n if n else 0.0


def windows(end: date):
    # 他の安定性検証と同じく、暦月単位・終点を含む区間に揃える。
    anchor = end + timedelta(days=1)
    def months_ago(d: date, months: int) -> date:
        year = d.year
        month = d.month - months
        while month <= 0:
            year -= 1
            month += 12
        # 月末起点でなければ日付はそのまま使えるが、汎用化のため安全に調整する。
        import calendar
        return date(year, month, min(d.day, calendar.monthrange(year, month)[1]))
    return (("直近12ヶ月", months_ago(anchor, 12), end), ("直近24ヶ月", months_ago(anchor, 24), end))


def blank():
    return {"n": 0, "second": Counter(), "third": Counter(), "pair": Counter()}


def aggregate(rows, start, end, predicate):
    out = blank()
    for r in rows:
        if not start <= r["race_date"] <= end or r["first"] != 3 or not predicate(r):
            continue
        if r["second"] not in FOLLOWERS or r["third"] not in FOLLOWERS or r["second"] == r["third"]:
            continue
        out["n"] += 1
        out["second"][r["second"]] += 1
        out["third"][r["third"]] += 1
        out["pair"][(r["second"], r["third"])] += 1
    return out


def show(label, stat):
    n = stat["n"]
    print(f"{label:<42} N={n}")
    print("  2着: " + " | ".join(f"{c}:{pct(stat['second'][c], n):5.1f}%({stat['second'][c]})" for c in FOLLOWERS))
    print("  3着: " + " | ".join(f"{c}:{pct(stat['third'][c], n):5.1f}%({stat['third'][c]})" for c in FOLLOWERS))
    print("  3-X-Y上位: " + " | ".join(
        f"3-{a}-{b}:{pct(count, n):.1f}%({count})"
        for (a, b), count in sorted(stat["pair"].items(), key=lambda item: (-item[1], item[0]))[:8]
    ))


def write_csv(input_path, results):
    path = input_path.with_name("tamagawa_lane3_win_followers_20260909.csv")
    with path.open("w", newline="", encoding="utf-8-sig") as f:
        w = csv.writer(f)
        w.writerow(("window", "condition", "win_n", "metric", "value", "count", "rate_pct"))
        for window, condition, stat in results:
            for c in FOLLOWERS:
                w.writerow((window, condition, stat["n"], "second_course", c, stat["second"][c], f"{pct(stat['second'][c], stat['n']):.4f}"))
                w.writerow((window, condition, stat["n"], "third_course", c, stat["third"][c], f"{pct(stat['third'][c], stat['n']):.4f}"))
            for (second, third), count in sorted(stat["pair"].items()):
                w.writerow((window, condition, stat["n"], "pair", f"3-{second}-{third}", count, f"{pct(count, stat['n']):.4f}"))
    return path


def main():
    if len(sys.argv) != 3:
        raise SystemExit("Usage: python3 analysis/analyze_tamagawa_lane3_win_followers.py INPUT_CSV END_DATE")
    input_path = Path(sys.argv[1])
    end = date.fromisoformat(sys.argv[2])
    rows = []
    with input_path.open(encoding="utf-8-sig", newline="") as f:
        for raw in csv.DictReader(f):
            rows.append({
                "race_date": date.fromisoformat(raw["race_date"]),
                "score": float(raw["score"]), "gap": float(raw["gap"]),
                "straight": float(raw["straight"]), "mawari": float(raw["mawari"]),
                "first": int(raw["first"]), "second": int(raw["second"]), "third": int(raw["third"]),
            })
    print("=" * 138)
    print("多摩川3コース1着時：★/★★/強化条件ごとの2着・3着コース分布")
    print("=" * 138)
    results = []
    for window, start, finish in windows(end):
        print(f"\n【{window}: {start} ～ {finish}】")
        for code, title, predicate in CONDITIONS:
            stat = aggregate(rows, start, finish, predicate)
            show(f"{code} {title}", stat)
            results.append((window, code, stat))
    print(f"\nCSV出力: {write_csv(input_path, results)}")


if __name__ == "__main__":
    main()
