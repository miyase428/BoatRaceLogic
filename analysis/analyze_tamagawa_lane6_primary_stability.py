#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""多摩川6コースの一次サイン候補を比較し、6ヶ月ブロックで安定性を確認する。

条件判定は対象日前の決まり手履歴と期別平均ST順位だけで行い、展示・着順は
二次評価と成績集計にのみ使う。払戻・オッズは使用しない。
"""

from __future__ import annotations

import csv
import sys
from collections import Counter
from datetime import date, timedelta
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from analyze_tamagawa_boaters_hypothesis import (  # noqa: E402
    HISTORY_MONTHS, TechniqueHistoryIndex, load_history, load_racer_results,
    months_ago, parse_date, relation_label, required_terms, term_info_for_date,
)
from analyze_tamagawa_lane4_exacta_structure import load_targets, pct  # noqa: E402
from analyze_tamagawa_lane4_strong_condition_second_eval import (  # noqa: E402
    build_second_scores, load_exhibition,
)
from analyze_tamagawa_lane5_primary_stability import RollingExhibitionAverage  # noqa: E402

LANE = 6
PROFILE_MONTHS = 12

CONDITIONS = (
    ("BASE", "6C履歴あり"),
    ("W5", "6C過去1着率5%以上"),
    ("W10", "6C過去1着率10%以上"),
    ("M5", "6Cまくり率5%以上"),
    ("MZ5", "6Cまくり差し率5%以上"),
    ("MZ10", "6Cまくり差し率10%以上"),
    ("A5", "6C攻め率5%以上"),
    ("A10", "6C攻め率10%以上"),
    ("A15", "6C攻め率15%以上"),
    ("ST_UP", "6が5より平均ST順位上"),
    ("MZ5_ST", "まくり差し5%以上×ST上"),
    ("MZ10_ST", "まくり差し10%以上×ST上"),
    ("A5_ST", "攻め率5%以上×ST上"),
    ("A10_ST", "攻め率10%以上×ST上"),
    ("A15_ST", "攻め率15%以上×ST上"),
)


def rates(profile: dict) -> dict[str, float]:
    n = int(profile["n"])
    if n <= 0:
        return {"n": 0, "win": 0.0, "makuri": 0.0, "makurizashi": 0.0, "attack": 0.0}
    makuri = pct(int(profile["tech"].get("まくり", 0)), n)
    makurizashi = pct(int(profile["tech"].get("まくり差し", 0)), n)
    return {
        "n": n,
        "win": pct(int(profile["win"]), n),
        "makuri": makuri,
        "makurizashi": makurizashi,
        "attack": makuri + makurizashi,
    }


def windows(end: date):
    anchor = end + timedelta(days=1)
    long = [("直近12ヶ月", months_ago(anchor, 12), end),
            ("直近18ヶ月", months_ago(anchor, 18), end),
            ("直近24ヶ月", months_ago(anchor, 24), end)]
    labels = ("直近0-6ヶ月", "6-12ヶ月前", "12-18ヶ月前", "18-24ヶ月前")
    short = []
    for i, label in enumerate(labels):
        short.append((label, months_ago(anchor, (i + 1) * 6),
                      months_ago(anchor, i * 6) - timedelta(days=1)))
    return long, short


def matches(row: dict, key: str) -> bool:
    p = row["p6_12"]
    if key == "BASE":
        return p["n"] > 0
    if p["n"] <= 0:
        return False
    st_up = row["st65"] == "内側より上"
    return {
        "W5": p["win"] >= 5.0,
        "W10": p["win"] >= 10.0,
        "M5": p["makuri"] >= 5.0,
        "MZ5": p["makurizashi"] >= 5.0,
        "MZ10": p["makurizashi"] >= 10.0,
        "A5": p["attack"] >= 5.0,
        "A10": p["attack"] >= 10.0,
        "A15": p["attack"] >= 15.0,
        "ST_UP": st_up,
        "MZ5_ST": p["makurizashi"] >= 5.0 and st_up,
        "MZ10_ST": p["makurizashi"] >= 10.0 and st_up,
        "A5_ST": p["attack"] >= 5.0 and st_up,
        "A10_ST": p["attack"] >= 10.0 and st_up,
        "A15_ST": p["attack"] >= 15.0 and st_up,
    }.get(key, False)


def blank() -> dict:
    return {"n": 0, "first": 0, "second": 0, "third": 0}


def add(stat: dict, row: dict) -> None:
    stat["n"] += 1
    stat["first"] += row["first"] == LANE
    stat["second"] += row["second"] == LANE
    stat["third"] += row["third"] == LANE


def summary(stat: dict) -> dict:
    n = stat["n"]
    return {"n": n, "head": pct(stat["first"], n),
            "top2": pct(stat["first"] + stat["second"], n),
            "top3": pct(stat["first"] + stat["second"] + stat["third"], n)}


def aggregate(rows: list[dict], start: date, end: date) -> dict:
    stats = {key: blank() for key, _ in CONDITIONS}
    for row in rows:
        if not start <= row["race_date"] <= end:
            continue
        for key, _ in CONDITIONS:
            if matches(row, key):
                add(stats[key], row)
    return {key: summary(value) for key, value in stats.items()}


def print_window(label: str, start: date, end: date, rows: list[dict]) -> dict:
    values = aggregate(rows, start, end)
    base = values["BASE"]
    print(f"\n■ {label} {start}～{end} / 過去{PROFILE_MONTHS}ヶ月profile")
    for key, title in CONDITIONS:
        s = values[key]
        small = " [N小]" if 0 < s["n"] < 20 else ""
        print(f"{key:<9} {title:<28} N={s['n']:4d}  "
              f"6頭={s['head']:6.2f}% ({s['head']-base['head']:+6.2f}pt)  "
              f"2連対={s['top2']:6.2f}% ({s['top2']-base['top2']:+6.2f}pt)  "
              f"3連対={s['top3']:6.2f}% ({s['top3']-base['top3']:+6.2f}pt){small}")
    return values


def build_rows(start: date, end: date) -> tuple[list[dict], Counter]:
    races = load_targets(start, end)
    pids = sorted({b["player_id"] for race in races.values() for b in race["boats"]})
    racer = load_racer_results(required_terms(start, end))
    hist = TechniqueHistoryIndex(load_history(start, end, pids))
    exhibition = load_exhibition(months_ago(start, 6), end)
    rolling_exhibition = RollingExhibitionAverage(exhibition)
    rows, skips = [], Counter()
    for code, race in races.items():
        by_course = {}
        for boat in race["boats"]:
            course = boat.get("course")
            if course in range(1, 7) and course not in by_course:
                by_course[course] = boat
            else:
                by_course = {}
                break
        if set(by_course) != set(range(1, 7)):
            skips["bad_entry_course"] += 1
            continue
        term = term_info_for_date(race["date"])
        rr5 = racer.get((term, by_course[5]["player_id"]))
        rr6 = racer.get((term, by_course[6]["player_id"]))
        if rr5 is None or rr6 is None or rr5[5]["avg_rank"] is None or rr6[6]["avg_rank"] is None:
            skips["missing_st_rank"] += 1
            continue
        pid6 = by_course[6]["player_id"]
        secondary = None
        avg_exhibition = rolling_exhibition.value(race["date"])
        if avg_exhibition is not None:
            score_map = build_second_scores(exhibition.get(code, []), avg_exhibition)
            if score_map is not None:
                secondary = score_map.get(str(pid6))
        rows.append({
            "race_code": code, "race_date": race["date"],
            "st65": relation_label(float(rr6[6]["avg_rank"]), float(rr5[5]["avg_rank"])),
            "p6_12": rates(hist.profile(pid6, 6, race["date"], 12)),
            "second_score": None if secondary is None else float(secondary["final_2nd_score"]),
            "second_rank": None if secondary is None else int(secondary["second_rank"]),
            "gap": None if secondary is None else float(secondary["gap_to_top"]),
            "attack_potential": None if secondary is None else float(secondary["attack_potential"]),
            "st_score": None if secondary is None else float(secondary["st_score"]),
            "straight_score": None if secondary is None else float(secondary["straight_score"]),
            "ex_score": None if secondary is None else float(secondary["ex_score"]),
            "lap_score": None if secondary is None else float(secondary["lap_score"]),
            "mawari_score": None if secondary is None else float(secondary["mawari_score"]),
            "first": int(race["first"]), "second": int(race["second"]),
            "third": int(race["third"]) if race["third"] is not None else None,
        })
    return rows, skips


def write_csv(end: date, rows: list[dict]) -> Path:
    path = Path(__file__).resolve().parent / "output" / f"tamagawa_lane6_primary_stability_{end:%Y%m%d}.csv"
    fields = ["race_code", "race_date", "st65", "p6_12_n", "p6_12_win",
              "p6_12_makuri", "p6_12_makurizashi", "p6_12_attack",
              "second_score", "second_rank", "gap", "attack_potential",
              "st_score", "straight_score", "ex_score", "lap_score", "mawari_score",
              "first", "second", "third"]
    with path.open("w", newline="", encoding="utf-8-sig") as f:
        writer = csv.DictWriter(f, fieldnames=fields)
        writer.writeheader()
        for row in rows:
            out = {key: row.get(key, "") for key in fields}
            out["race_date"] = row["race_date"].isoformat()
            p = row["p6_12"]
            for name in ("n", "win", "makuri", "makurizashi", "attack"):
                out[f"p6_12_{name}"] = p[name]
            writer.writerow(out)
    return path


def main() -> None:
    if len(sys.argv) != 2:
        raise SystemExit("Usage: python3 analysis/analyze_tamagawa_lane6_primary_stability.py END_DATE")
    end = parse_date(sys.argv[1])
    long, short = windows(end)
    start = min(item[1] for item in long + short)
    rows, skips = build_rows(start, end)
    print("=" * 150)
    print("多摩川6コース：主軸サイン候補比較と時系列安定性")
    print("=" * 150)
    print(f"対象={start}～{end} / 採用行={len(rows)}")
    if skips:
        print("スキップ: " + ", ".join(f"{k}={v}" for k, v in skips.items()))
    print("\n【長期：過去12ヶ月profile固定】")
    long_values = {label: print_window(label, st, en, rows) for label, st, en in long}
    print("\n【短期：6ヶ月×4非重複ブロック】")
    short_values = {label: print_window(label, st, en, rows) for label, st, en in short}
    print("\n【再現性まとめ】")
    for key, title in CONDITIONS[1:]:
        vals = [v for v in short_values.values() if v[key]["n"] >= 20]
        wins = sum(v[key]["head"] > v["BASE"]["head"] for v in vals)
        n24 = long_values["直近24ヶ月"][key]["n"]
        delta = long_values["直近24ヶ月"][key]["head"] - long_values["直近24ヶ月"]["BASE"]["head"]
        print(f"{key:<9} {title:<28} 6ヶ月頭超え={wins}/{len(vals)} N24={n24:4d} 24ヶ月差={delta:+.2f}pt")
    print(f"\nCSV出力: {write_csv(end, rows)}")


if __name__ == "__main__":
    main()
