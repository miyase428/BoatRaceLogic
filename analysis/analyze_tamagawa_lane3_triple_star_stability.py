#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""多摩川3コース★★★候補を、過去12ヶ月profile固定で時系列検証する。

★   : 3Cの過去12ヶ月「まくり+まくり差し」率 >= 15%
★★  : ★ + 二次評価30以上 + 二次TOPとの差2点以内
★★★候補は、★★内の事後探索で上振れた展示条件を、24ヶ月を4つの
非重複6ヶ月ブロックに分けて比較する。結果・払戻は条件判定に用いない。

Usage: python3 analysis/analyze_tamagawa_lane3_triple_star_stability.py 2026-09-09
"""

from __future__ import annotations

import csv
import sys
from collections import Counter
from datetime import date, timedelta
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from analyze_tamagawa_boaters_hypothesis import (  # noqa: E402
    TechniqueHistoryIndex, load_history, months_ago, parse_date,
)
from analyze_tamagawa_lane4_exacta_structure import load_targets, pct  # noqa: E402
from analyze_tamagawa_lane4_strong_condition_second_eval import (  # noqa: E402
    build_second_scores, load_avg_exhibition, load_exhibition,
)

PROFILE_MONTHS = 12
STAR_ATTACK_RATE = 15.0

CONDITIONS = (
    ("STAR", "★ 攻め率15%以上"),
    ("DOUBLE", "★★ 二次30+ × TOP差2以内"),
    ("LINE5", "★★★候補 直線評価5"),
    ("S32_LINE5", "参考 二次32+ × 直線5"),
    ("S34", "参考 二次34+"),
    ("LINE5_M4", "参考 直線5 × 周り足4+"),
)


def windows(end_date: date):
    anchor = end_date + timedelta(days=1)
    start24 = months_ago(anchor, 24)
    start18 = months_ago(anchor, 18)
    long = [("直近18ヶ月", start18, end_date), ("直近24ヶ月", start24, end_date)]
    labels = ("直近0-6ヶ月", "6-12ヶ月前", "12-18ヶ月前", "18-24ヶ月前")
    short = []
    for i, label in enumerate(labels):
        block_end = months_ago(anchor, i * 6) - timedelta(days=1)
        block_start = months_ago(anchor, (i + 1) * 6)
        short.append((label, block_start, block_end))
    return long, short


def blank():
    return {"n": 0, "first": 0, "second": 0, "third": 0}


def add(stat: dict, first: int, second: int, third: int | None):
    stat["n"] += 1
    stat["first"] += first == 3
    stat["second"] += second == 3
    stat["third"] += third == 3


def summary(stat: dict):
    n = stat["n"]
    return {
        "n": n,
        "head": pct(stat["first"], n),
        "top2": pct(stat["first"] + stat["second"], n),
        "top3": pct(stat["first"] + stat["second"] + stat["third"], n),
    }


def matches(row: dict, key: str) -> bool:
    if key == "STAR":
        return True
    if key == "DOUBLE":
        return row["score"] >= 30.0 and row["gap"] <= 2.0
    if key == "LINE5":
        return row["straight"] >= 5.0
    if key == "S32_LINE5":
        return row["score"] >= 32.0 and row["straight"] >= 5.0
    if key == "S34":
        return row["score"] >= 34.0
    if key == "LINE5_M4":
        return row["straight"] >= 5.0 and row["mawari"] >= 4.0
    raise ValueError(key)


def aggregate(rows, start, end):
    stats = {key: blank() for key, _ in CONDITIONS}
    for row in rows:
        if not start <= row["race_date"] <= end:
            continue
        for key, _ in CONDITIONS:
            if matches(row, key):
                add(stats[key], row["first"], row["second"], row["third"])
    return {key: summary(stat) for key, stat in stats.items()}


def print_window(label, start, end, rows):
    values = aggregate(rows, start, end)
    base = values["DOUBLE"]
    print(f"\n■ {label}  {start} ～ {end}")
    for key, title in CONDITIONS:
        s = values[key]
        marker = " [N小]" if 0 < s["n"] < 15 else ""
        print(
            f"{key:<11} {title:<30} N={s['n']:4d}  "
            f"3頭={s['head']:6.2f}% ({s['head'] - base['head']:+6.2f}pt)  "
            f"2連対={s['top2']:6.2f}% ({s['top2'] - base['top2']:+6.2f}pt)  "
            f"3連対={s['top3']:6.2f}% ({s['top3'] - base['top3']:+6.2f}pt){marker}"
        )
    return values


def make_rows(start: date, end: date):
    races = load_targets(start, end)
    pids = sorted({b["player_id"] for r in races.values() for b in r["boats"]})
    history = load_history(start, end, pids)
    hist = TechniqueHistoryIndex(history)
    exhibition = load_exhibition(start, end)
    avg_exhibition = load_avg_exhibition()
    rows, skips = [], Counter()
    for code, race in races.items():
        by_course = {}
        for boat in race["boats"]:
            try:
                course = int(boat["course"])
            except (TypeError, ValueError):
                continue
            if course in by_course or course not in range(1, 7):
                by_course = {}
                break
            by_course[course] = boat
        if set(by_course) != set(range(1, 7)):
            skips["bad_entry_course"] += 1
            continue
        pid3 = str(by_course[3]["player_id"])
        profile = hist.profile(pid3, 3, race["date"], PROFILE_MONTHS)
        n = int(profile["n"])
        if n <= 0:
            skips["history0_12m"] += 1
            continue
        attack_rate = pct(int(profile["tech"].get("まくり", 0)), n) + pct(int(profile["tech"].get("まくり差し", 0)), n)
        if attack_rate < STAR_ATTACK_RATE:
            continue
        score_map = build_second_scores(exhibition.get(code, []), avg_exhibition)
        if score_map is None or pid3 not in score_map:
            skips["missing_or_bad_exhibition"] += 1
            continue
        s = score_map[pid3]
        rows.append({
            "race_code": code, "race_date": race["date"], "attack_rate": attack_rate,
            "score": float(s["final_2nd_score"]), "gap": float(s["gap_to_top"]),
            "straight": float(s["straight_score"]), "mawari": float(s["mawari_score"]),
            "first": int(race["first"]), "second": int(race["second"]),
            "third": int(race["third"]) if race["third"] is not None else None,
        })
    return races, rows, skips, avg_exhibition


def write_csv(end_date, rows):
    path = Path(__file__).resolve().parent / "output" / f"tamagawa_lane3_triple_star_stability_{end_date:%Y%m%d}.csv"
    with path.open("w", newline="", encoding="utf-8-sig") as f:
        fields = ("race_code", "race_date", "attack_rate", "score", "gap", "straight", "mawari", "first", "second", "third")
        w = csv.DictWriter(f, fieldnames=fields)
        w.writeheader()
        for row in rows:
            w.writerow({**row, "race_date": row["race_date"].isoformat()})
    return path


def main():
    if len(sys.argv) != 2:
        raise SystemExit("Usage: python3 analysis/analyze_tamagawa_lane3_triple_star_stability.py END_DATE")
    end_date = parse_date(sys.argv[1])
    long, short = windows(end_date)
    start = min(x[1] for x in long + short)
    print("多摩川3コース★★★候補の時系列安定性を集計中...", flush=True)
    races, rows, skips, avg = make_rows(start, end_date)
    print("=" * 166)
    print("多摩川3コース：★★★候補の長期・6ヶ月ブロック安定性")
    print("★=過去12ヶ月攻め率15%以上 / ★★=★+二次30以上+TOP差2以内")
    print("★★★本体候補=★★+直線評価5。ほかは候補探索で得た参考条件。")
    print("=" * 166)
    print(f"対象期間={start}～{end_date}  対象レース={len(races)}  ★採用行={len(rows)}  展示平均={avg:.3f}")
    if skips:
        print("スキップ: " + ", ".join(f"{k}={v}" for k, v in skips.items()))
    print("\n【長期確認】")
    for item in long:
        print_window(*item, rows)
    print("\n【短期確認：6ヶ月×4非重複ブロック】")
    short_results = {label: print_window(label, st, en, rows) for label, st, en in short}
    print("\n【★★を上回った6ヶ月ブロック数（3頭率）】")
    for key, title in CONDITIONS[2:]:
        wins = sum(v[key]["head"] > v["DOUBLE"]["head"] for v in short_results.values() if v[key]["n"] > 0)
        valid = sum(v[key]["n"] > 0 for v in short_results.values())
        print(f"{key:<11} {title:<30} {wins}/{valid}")
    print(f"\nCSV出力: {write_csv(end_date, rows)}")
    print("注意: 展示総合の基準値は既存二次評価と同じ現行6ヶ月平均であり、完全な時点別再現ではない。")


if __name__ == "__main__":
    main()
