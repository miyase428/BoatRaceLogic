#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
多摩川4コースの★★候補で、4コースが1着を取れなかった時の頭・残り方を見る。

前段のCSV:
  analysis/output/tamagawa_lane4_strong_condition_second_eval_YYYYMMDD_YYYYMMDD.csv

前提:
★ = 4コース攻めサイン
  - 過去4コースまくり率15%以上
  - 4が3より平均ST順位上

★★ = ★に加えて
  - 4号艇の二次スコア24以上
  - 二次トップとの差5以内

★★★はまだ未確定。ここでは定義しない。

主な出力:
- ★★総数 / 4頭率 / 4非1着数
- 4が負けた時の勝者コース分布
- 4が負けた時の4自身の2着・3着・4着以下
- 勝者コース別 × 4の残り方
- 2連単 / 3連単 上位

Usage:
  python3 analysis/analyze_tamagawa_lane4_double_star_losses.py \
    analysis/output/tamagawa_lane4_strong_condition_second_eval_20250901_20260909.csv
"""

from __future__ import annotations

import csv
import sys
from collections import Counter, defaultdict
from pathlib import Path

PROFILE_MONTHS = (12, 6)
WINNER_COURSES = (1, 2, 3, 5, 6)


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


def lane4_finish(second: int | None, third: int | None) -> str:
    if second == 4:
        return "2着"
    if third == 4:
        return "3着"
    return "4着以下"


def blank_stat() -> dict:
    return {
        "n": 0,
        "lane4_win": 0,
        "loss_n": 0,
        "winner": Counter(),
        "lane4_finish": Counter(),
        "winner_x_lane4_finish": Counter(),
        "exacta": Counter(),
        "trifecta": Counter(),
    }


def add_row(stat: dict, first: int, second: int, third: int | None) -> None:
    stat["n"] += 1
    if first == 4:
        stat["lane4_win"] += 1
        return

    stat["loss_n"] += 1
    stat["winner"][first] += 1

    finish = lane4_finish(second, third)
    stat["lane4_finish"][finish] += 1
    stat["winner_x_lane4_finish"][(first, finish)] += 1

    stat["exacta"][f"{first}-{second}"] += 1
    if third is not None:
        stat["trifecta"][f"{first}-{second}-{third}"] += 1


def print_ranked(counter: Counter, den: int, title: str, top_n: int = 15) -> None:
    print(f"\n■ {title}")
    if den <= 0:
        print("  N=0")
        return
    ranked = sorted(counter.items(), key=lambda kv: (-kv[1], str(kv[0])))[:top_n]
    for i, (key, n) in enumerate(ranked, start=1):
        print(f"  {i:2d}. {key:<7} N={n:3d}  構成率={pct(n, den):6.2f}%")


def print_month(months: int, stat: dict) -> None:
    n = stat["n"]
    loss_n = stat["loss_n"]

    print("\n" + "-" * 128)
    print(f"【過去{months}ヶ月profile：★★ = ★ + 二次24以上 + TOP差5以内】")
    print("-" * 128)
    print(
        f"★★ N={n}  "
        f"4頭={stat['lane4_win']} ({pct(stat['lane4_win'], n):.2f}%)  "
        f"4非1着={loss_n} ({pct(loss_n, n):.2f}%)"
    )

    print("\n■ ★★で4が負けた時：勝者コース分布")
    print("  " + " | ".join(
        f"{c}頭={pct(stat['winner'][c], loss_n):5.1f}%({stat['winner'][c]})"
        for c in WINNER_COURSES
    ))

    print("\n■ ★★で4が負けた時：4自身の残り方")
    for label in ("2着", "3着", "4着以下"):
        count = stat["lane4_finish"][label]
        print(f"  {label:<4} N={count:3d}  構成率={pct(count, loss_n):6.2f}%")

    print("\n■ 勝者コース別 × 4の残り方")
    for winner in WINNER_COURSES:
        wn = stat["winner"][winner]
        if wn <= 0:
            continue
        parts = []
        for label in ("2着", "3着", "4着以下"):
            c = stat["winner_x_lane4_finish"][(winner, label)]
            parts.append(f"4{label}={pct(c, wn):5.1f}%({c})")
        print(f"  {winner}頭 N={wn:3d}  " + " | ".join(parts))

    print_ranked(stat["exacta"], loss_n, "★★で4が負けた時：2連単 上位")
    print_ranked(stat["trifecta"], loss_n, "★★で4が負けた時：3連単 上位")


def main() -> None:
    if len(sys.argv) != 2:
        print(
            "Usage: python3 analysis/analyze_tamagawa_lane4_double_star_losses.py INPUT_CSV",
            file=sys.stderr,
        )
        sys.exit(1)

    path = Path(sys.argv[1])
    if not path.exists():
        raise FileNotFoundError(path)

    stats = {m: blank_stat() for m in PROFILE_MONTHS}
    raw_rows = 0
    adopted = defaultdict(int)

    with path.open("r", encoding="utf-8-sig", newline="") as f:
        reader = csv.DictReader(f)
        for row in reader:
            raw_rows += 1
            months = as_int(row.get("history_months"))
            if months not in stats:
                continue
            if not is_double_star(row):
                continue

            first = as_int(row.get("first_course"))
            second = as_int(row.get("second_course"))
            third = as_int(row.get("third_course"))
            if first not in range(1, 7) or second not in range(1, 7):
                continue

            add_row(stats[months], first, second, third)
            adopted[months] += 1

    print("=" * 128)
    print("多摩川4コース：★★条件で4が勝たなかった時に誰が頭になるか")
    print("★  = 4攻めサイン")
    print("★★ = ★ + 二次24以上 + TOP差5以内（4軸候補・頭もあり）")
    print("★★★ = まだ未定義")
    print("=" * 128)
    print(f"入力CSV    : {path}")
    print(f"CSV行数    : {raw_rows}")
    print("採用       : " + " / ".join(f"{m}ヶ月={adopted[m]}" for m in PROFILE_MONTHS))

    for months in PROFILE_MONTHS:
        print_month(months, stats[months])

    print("\n" + "=" * 128)
    print("※ 4非1着時の頭分布と、4が2・3着へ残るかを★★の相手構造として確認します。")
    print("※ ★★★条件はこの結果を見てから別途決めます。")
    print("=" * 128)


if __name__ == "__main__":
    main()
