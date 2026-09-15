#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""多摩川のコース間展開連鎖を横並び比較する。

対象は内側から外側への全10通り（2→3〜6、3→4〜6、4→5〜6、5→6）。条件は既存の各コース★相当を基本にし、
起点単独・浮上先単独・両者同時の成績を比較する。条件判定は対象日前の
決まり手履歴と期別平均ST順位だけで行い、着順は評価にのみ使用する。
"""

from __future__ import annotations

import sys
from collections import OrderedDict
from datetime import date, timedelta
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from analyze_tamagawa_boaters_hypothesis import (  # noqa: E402
    HISTORY_MONTHS, TechniqueHistoryIndex, load_history, load_racer_results,
    months_ago, parse_date, required_terms, term_info_for_date,
)
from analyze_tamagawa_lane4_exacta_structure import load_targets  # noqa: E402

VENUE_CODE = "TMG"
PAIRS = tuple((source, target) for source in range(2, 6) for target in range(source + 1, 7))


def pct(num: int, den: int) -> float:
    return 100.0 * num / den if den else 0.0


def rates(profile: dict) -> dict[str, float]:
    n = int(profile.get("n", 0))
    tech = profile.get("tech", {})
    if n <= 0:
        return {"n": 0, "makuri": 0.0, "makurizashi": 0.0, "attack": 0.0, "sashi": 0.0}
    makuri = pct(int(tech.get("まくり", 0)), n)
    makurizashi = pct(int(tech.get("まくり差し", 0)), n)
    return {
        "n": n,
        "makuri": makuri,
        "makurizashi": makurizashi,
        "attack": makuri + makurizashi,
        "sashi": pct(int(tech.get("差し", 0)), n),
    }


def windows(end: date):
    anchor = end + timedelta(days=1)
    labels = ("直近0-6ヶ月", "6-12ヶ月前", "12-18ヶ月前", "18-24ヶ月前")
    short = []
    for i, label in enumerate(labels):
        short.append((label, months_ago(anchor, (i + 1) * 6), months_ago(anchor, i * 6) - timedelta(days=1)))
    return short


def conditions(row: dict, source: int, target: int) -> tuple[bool, bool, bool]:
    p_s = row[f"p{source}"]
    p_t = row[f"p{target}"]
    if p_s["n"] <= 0 or p_t["n"] <= 0:
        return False, False, False

    # 各コースの既存★相当を、隣接連鎖の起点/浮上先に利用する。
    if source == 2:
        source_star = p_s["sashi"] >= 10.0 or (p_s["makuri"] >= 5.0 and row["st21"] == "上")
    elif source == 3:
        source_star = p_s["attack"] >= 15.0
    elif source == 4:
        source_star = p_s["makuri"] >= 15.0 and row["st43"] == "上"
    else:  # 5C
        source_star = p_s["attack"] >= 10.0

    if target == 3:
        target_star = p_t["attack"] >= 15.0
    elif target == 4:
        target_star = p_t["makuri"] >= 15.0 and row["st43"] == "上"
    elif target == 5:
        target_star = p_t["makurizashi"] >= 5.0
    else:  # 6C
        target_star = p_t["attack"] >= 5.0 and row["st65"] == "上"
    return True, source_star, target_star


def add(stat: dict, row: dict, source: int, target: int) -> None:
    stat["n"] += 1
    first, second, third = row["first"], row["second"], row["third"]
    target_top3 = target in (first, second, third)
    source_top3 = source in (first, second, third)
    stat["target_head"] += first == target
    stat["target_top2"] += target in (first, second)
    stat["target_top3"] += target_top3
    stat["source_target_top3"] += source_top3 and target_top3
    stat["source_target_exacta"] += first == source and second == target


def blank() -> dict:
    return {"n": 0, "target_head": 0, "target_top2": 0, "target_top3": 0,
            "source_target_top3": 0, "source_target_exacta": 0}


def summary(stat: dict) -> dict:
    n = stat["n"]
    return {"n": n,
            "head": pct(stat["target_head"], n),
            "top2": pct(stat["target_top2"], n),
            "top3": pct(stat["target_top3"], n),
            "both": pct(stat["source_target_top3"], n),
            "exacta": pct(stat["source_target_exacta"], n)}


def load_rows(start: date, end: date):
    races = load_targets(start, end)
    pids = sorted({boat["player_id"] for race in races.values() for boat in race["boats"]})
    hist = TechniqueHistoryIndex(load_history(start, end, pids))
    racer = load_racer_results(required_terms(start, end))
    rows, skips = [], {"bad_entry": 0, "missing_rank": 0}
    for code, race in races.items():
        by_course = {}
        for boat in race["boats"]:
            course = int(boat["course"] or 0)
            if course not in range(1, 7) or course in by_course:
                by_course = {}
                break
            by_course[course] = boat
        if set(by_course) != set(range(1, 7)):
            skips["bad_entry"] += 1
            continue
        term = term_info_for_date(race["date"])
        ranks = {}
        for course in range(1, 7):
            result = racer.get((term, by_course[course]["player_id"]))
            ranks[course] = None if result is None else result[course]["avg_rank"]
        if any(ranks[c] is None for c in range(1, 7)):
            skips["missing_rank"] += 1
            continue
        row = {"race_date": race["date"], "first": int(race["first"]),
               "second": int(race["second"]),
               "third": int(race["third"]) if race["third"] is not None else None}
        for c in range(2, 7):
            row[f"p{c}"] = rates(hist.profile(by_course[c]["player_id"], c, race["date"], 12))
        # 平均ST順位の大小は数値が小さいほど上。隣接関係だけ明示する。
        row["st21"] = "上" if ranks[2] < ranks[1] else ("同じ" if ranks[2] == ranks[1] else "下")
        row["st43"] = "上" if ranks[4] < ranks[3] else ("同じ" if ranks[4] == ranks[3] else "下")
        row["st65"] = "上" if ranks[6] < ranks[5] else ("同じ" if ranks[6] == ranks[5] else "下")
        rows.append(row)
    return rows, skips


def aggregate(rows, start, end, source, target):
    out = {"BASE": blank(), "SOURCE": blank(), "TARGET": blank(), "CHAIN": blank()}
    for row in rows:
        if not start <= row["race_date"] <= end:
            continue
        valid, source_star, target_star = conditions(row, source, target)
        if not valid:
            continue
        add(out["BASE"], row, source, target)
        if source_star:
            add(out["SOURCE"], row, source, target)
        if target_star:
            add(out["TARGET"], row, source, target)
        if source_star and target_star:
            add(out["CHAIN"], row, source, target)
    return {key: summary(stat) for key, stat in out.items()}


def print_pair(label, source, target, rows, start, end):
    values = aggregate(rows, start, end, source, target)
    print(f"\n■ {label} {start}～{end}")
    for key, title in (("BASE", "履歴あり基準"), ("SOURCE", f"{source}C起点★"),
                       ("TARGET", f"{target}C浮上先条件"), ("CHAIN", "両者同時（展開連鎖候補）")):
        s = values[key]
        print(f"{key:<7} {title:<24} N={s['n']:4d}  {target}頭={s['head']:6.2f}% "
              f"{target}2連={s['top2']:6.2f}% {target}3連={s['top3']:6.2f}% "
              f"両方TOP3={s['both']:6.2f}% {source}→{target}={s['exacta']:6.2f}%")
    return values


def main() -> None:
    if len(sys.argv) != 2:
        raise SystemExit("Usage: python3 analysis/analyze_tamagawa_cascade_matrix.py END_DATE")
    end = parse_date(sys.argv[1])
    short = windows(end)
    start = min(item[1] for item in short)
    rows, skips = load_rows(start, end)
    print("=" * 170)
    print("多摩川 展開連鎖マトリクス（全コース間）")
    print("=" * 170)
    print(f"対象={start}～{end} / 採用行={len(rows)} / スキップ={skips}")
    pairs = PAIRS
    print("\n【24ヶ月全体】")
    all_values = {}
    for source, target in pairs:
        all_values[(source, target)] = print_pair(f"{source}C→{target}C", source, target, rows, start, end)
    print("\n【6ヶ月ブロック安定性：CHAINの浮上先3連対率】")
    for source, target in pairs:
        vals = []
        for label, st, en in short:
            value = aggregate(rows, st, en, source, target)
            chain, base = value["CHAIN"], value["BASE"]
            vals.append((label, chain["n"], chain["top3"], base["top3"], chain["both"], base["both"]))
        print(f"\n{source}C→{target}C")
        for label, n, top3, base_top3, both, base_both in vals:
            print(f"  {label:<10} N={n:3d} {target}3連={top3:6.2f}% (基準{base_top3:6.2f}%) "
                  f"両TOP3={both:6.2f}% (基準{base_both:6.2f}%)")


if __name__ == "__main__":
    main()
