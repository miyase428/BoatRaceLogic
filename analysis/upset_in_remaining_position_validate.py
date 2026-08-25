#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
穴目予想：①残りラベル（低/中/高）ごとに、イン敗戦時の実着順位置と相手構造をP1/P2/P3で確認する。

固定条件
--------
- HIGH: AI本命=1C / CURRENT本命!=1C / イン補正後1着率<50%
- ①残りラベル: 低 <60% / 中 60-70% / 高 >=70%（インAI3連対率）
- 閾値は変更しない

見るもの
--------
1. イン敗戦時に①が2着 / 3着 / 4着以下のどこへ入るか
2. ①が2着のときの頭コース・3着コース分布
3. ①が3着のときの頭コース・2着コース分布

重要
----
- actual着順は評価ラベルにのみ使用する。
- P3を見て帯境界や条件を変更しない。
- 本番Web/PredictionLogicは変更しない。

Usage:
python3 analysis/upset_in_remaining_position_validate.py \
  analysis/output/final_prediction_boats_20260615_20260714_OLD.csv \
  analysis/output/final_prediction_boats_20260715_20260814_OLD.csv \
  analysis/output/final_prediction_boats_fast_cached_20260815_20260822.csv
"""

from __future__ import annotations

import sys
from collections import Counter
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import final_prediction_ai_opponent_compare as b2
import final_prediction_ai_bet_integration_compare as b4
import trifecta_probability_order_compare as step3
import upset_probability_validate as c1


def pct(n, d):
    return 100.0 * n / d if d else 0.0


def remain_level(in_trio_p):
    x = float(in_trio_p)
    if x < 0.60:
        return "LOW"
    if x < 0.70:
        return "MIDDLE"
    return "HIGH"


def build_rows(records, boats_map):
    rows = []
    skip = Counter()

    for record in records:
        code = str(record["race_code"])
        boats = boats_map.get(code)
        if boats is None or set(boats) != set(range(1, 7)):
            skip["csv_missing"] += 1
            continue

        f = c1.make_features(record, boats)
        if f is None:
            skip["feature_invalid"] += 1
            continue

        in_boat = int(f["in_lane"])
        current = int(f["current_head"])
        ai_head = int(f["ai_head"])
        in_win_p = float(f["values"]["in_win_p"])
        if not (ai_head == in_boat and current != in_boat and in_win_p < 0.50):
            skip["not_high"] += 1
            continue

        actual = b4.actual_trifecta(boats)
        if actual is None:
            skip["actual_invalid"] += 1
            continue
        actual = tuple(int(x) for x in actual)

        # 今回はイン敗戦時だけ。
        if actual[0] == in_boat:
            skip["in_win"] += 1
            continue

        in_trio_p = float(f["trio"].get(in_boat, 0.0))
        level = remain_level(in_trio_p)
        course_by_lane = {int(k): int(v) for k, v in f["course_by_lane"].items()}
        actual_courses = tuple(course_by_lane.get(x, 0) for x in actual)
        if not all(c in range(1, 7) for c in actual_courses):
            skip["course_map_invalid"] += 1
            continue

        if actual[1] == in_boat:
            in_pos = 2
        elif actual[2] == in_boat:
            in_pos = 3
        else:
            in_pos = 4

        rows.append({
            "race_code": code,
            "level": level,
            "in_trio_p": in_trio_p,
            "in_pos": in_pos,
            "actual": actual,
            "actual_courses": actual_courses,
            "head_course": actual_courses[0],
            "second_course": actual_courses[1],
            "third_course": actual_courses[2],
        })
        skip["ready_fail"] += 1

    return rows, skip


def fmt_counter(counter, total, limit=5):
    if total <= 0:
        return "-"
    parts = []
    for course, n in sorted(counter.items(), key=lambda x: (-x[1], x[0]))[:limit]:
        parts.append(f"{course}C={n}({pct(n,total):.1f}%)")
    return " / ".join(parts) if parts else "-"


def print_period(title, rows, skip):
    print("\n" + "=" * 122)
    print(f"【{title}】 イン敗戦診断R={len(rows)}")
    print("=" * 122)

    print("ラベル      敗戦R    ①2着       ①3着       飛び(4着以下)   ①残り合計")
    print("-" * 88)
    for level in ("LOW", "MIDDLE", "HIGH"):
        part = [r for r in rows if r["level"] == level]
        n = len(part)
        p2 = sum(r["in_pos"] == 2 for r in part)
        p3 = sum(r["in_pos"] == 3 for r in part)
        fly = sum(r["in_pos"] == 4 for r in part)
        remain = p2 + p3
        print(
            f"{level:<10} {n:>5d}   "
            f"{p2:>4d}({pct(p2,n):>5.1f}%)   "
            f"{p3:>4d}({pct(p3,n):>5.1f}%)   "
            f"{fly:>4d}({pct(fly,n):>5.1f}%)      "
            f"{remain:>4d}({pct(remain,n):>5.1f}%)"
        )

    for level in ("LOW", "MIDDLE", "HIGH"):
        part = [r for r in rows if r["level"] == level]
        second_rows = [r for r in part if r["in_pos"] == 2]
        third_rows = [r for r in part if r["in_pos"] == 3]

        print(f"\n--- {level}: ①が2着だった時 N={len(second_rows)} ---")
        head = Counter(r["head_course"] for r in second_rows)
        third = Counter(r["third_course"] for r in second_rows)
        print("頭コース  : " + fmt_counter(head, len(second_rows)))
        print("3着コース : " + fmt_counter(third, len(second_rows)))

        print(f"--- {level}: ①が3着だった時 N={len(third_rows)} ---")
        head = Counter(r["head_course"] for r in third_rows)
        second = Counter(r["second_course"] for r in third_rows)
        print("頭コース  : " + fmt_counter(head, len(third_rows)))
        print("2着コース : " + fmt_counter(second, len(third_rows)))

    print("skip参考:", dict(skip))


def main():
    if len(sys.argv) != 4:
        print(
            "Usage: python3 analysis/upset_in_remaining_position_validate.py P1_BOATS P2_BOATS P3_BOATS",
            file=sys.stderr,
        )
        sys.exit(1)

    p1, p2, p3 = sys.argv[1:]

    print("①残りラベル固定のまま、2着/3着位置と相手構造をP1/P2/P3で検証中...", flush=True)
    train = step3.build_common_records(p1, p2)
    future = step3.build_common_records(p2, p3)
    boats_map = b2.load_boats(p1, p2, p3)

    p1_rows, p1_skip = build_rows(train["records"]["P1"], boats_map)
    p2_rows, p2_skip = build_rows(train["records"]["P2"], boats_map)
    p3_rows, p3_skip = build_rows(future["records"]["P2"], boats_map)

    print("=" * 122)
    print("穴目予想：①残り 低/中/高 → 実2着/3着位置・相手構造 P1/P2/P3")
    print("=" * 122)
    print("HIGH : AI本命=1C / CURRENT本命!=1C / イン補正後1着率<50%（固定）")
    print("ラベル: 低<60% / 中60-70% / 高>=70%（インAI3連対率、固定）")
    print("対象 : HIGHかつイン敗戦時")
    print("本番変更: なし")

    print_period("P1", p1_rows, p1_skip)
    print_period("P2", p2_rows, p2_skip)
    print_period("P3完全未来", p3_rows, p3_skip)

    print("\n【判断ポイント】")
    print("1. HIGHほど①2着/3着のどちらが増えるかがP1/P2/P3で再現するか")
    print("2. ①が2着時・3着時の相手コース分布に再現性があるか")
    print("3. 小標本セルを見て新しい条件や閾値を作らない")
    print("4. 再現する構造だけ次STEPで120通りヒモ候補との重なりを確認する")
    print("=" * 122)


if __name__ == "__main__":
    main()
