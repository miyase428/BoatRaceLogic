#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""多摩川5コースの主軸条件を探索し、長期・時系列安定性を確認する。

条件判定に使うのは対象日前の決まり手履歴、期別平均ST順位、当日展示のみ。
着順は成績集計だけに使い、払戻は使用しない。

Usage: python3 analysis/analyze_tamagawa_lane5_primary_stability.py 2026-09-09
"""

from __future__ import annotations

import csv
import bisect
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

PROFILE_MONTHS = 12

CONDITIONS = (
    ("BASE", "基準"),
    ("W10", "5C過去1着率10%以上"),
    ("W15", "5C過去1着率15%以上"),
    ("M5", "5Cまくり率5%以上"),
    ("MZ5", "5Cまくり差し率5%以上"),
    ("MZ10", "5Cまくり差し率10%以上"),
    ("A10", "5C攻め率10%以上"),
    ("A15", "5C攻め率15%以上"),
    ("ST_UP", "5が4より平均ST順位上"),
    ("MZ5_ST", "まくり差し5%以上×ST上"),
    ("MZ10_ST", "まくり差し10%以上×ST上"),
    ("A10_ST", "攻め率10%以上×ST上"),
    ("A15_ST", "攻め率15%以上×ST上"),
    ("L4_STRONG", "4C強攻め"),
    ("MZ5_L4", "まくり差し5%以上×4C強攻め"),
    ("A10_L4", "攻め率10%以上×4C強攻め"),
)


def calc_windows(end_date: date):
    anchor = end_date + timedelta(days=1)
    long = [
        ("直近12ヶ月", months_ago(anchor, 12), end_date),
        ("直近18ヶ月", months_ago(anchor, 18), end_date),
        ("直近24ヶ月", months_ago(anchor, 24), end_date),
    ]
    labels = ("直近0-6ヶ月", "6-12ヶ月前", "12-18ヶ月前", "18-24ヶ月前")
    short = []
    for idx, label in enumerate(labels):
        block_end = months_ago(anchor, idx * 6) - timedelta(days=1)
        block_start = months_ago(anchor, (idx + 1) * 6)
        short.append((label, block_start, block_end))
    return long, short


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


class RollingExhibitionAverage:
    """対象日を含めない、場全体の過去6ヶ月展示タイム平均。"""

    def __init__(self, exhibition: dict[str, list[dict]]):
        items = []
        for race_code, boats in exhibition.items():
            try:
                race_date = date(int(race_code[:4]), int(race_code[4:6]), int(race_code[6:8]))
            except (TypeError, ValueError):
                continue
            for boat in boats:
                try:
                    value = float(boat["exhibition_time"])
                except (TypeError, ValueError):
                    continue
                if value > 0:
                    items.append((race_date, value))
        items.sort()
        self.dates = [item[0] for item in items]
        self.prefix = [0.0]
        for _, value in items:
            self.prefix.append(self.prefix[-1] + value)

    def value(self, target_date: date) -> float | None:
        lower = months_ago(target_date, 6)
        lo = bisect.bisect_left(self.dates, lower)
        hi = bisect.bisect_left(self.dates, target_date)
        n = hi - lo
        return (self.prefix[hi] - self.prefix[lo]) / n if n else None


def matches(row: dict, key: str, months: int = PROFILE_MONTHS) -> bool:
    p5 = row[f"p5_{months}"]
    if key == "BASE":
        return True
    if key == "W10":
        return p5["win"] >= 10.0
    if key == "W15":
        return p5["win"] >= 15.0
    if key == "M5":
        return p5["makuri"] >= 5.0
    if key == "MZ5":
        return p5["makurizashi"] >= 5.0
    if key == "MZ10":
        return p5["makurizashi"] >= 10.0
    if key == "A10":
        return p5["attack"] >= 10.0
    if key == "A15":
        return p5["attack"] >= 15.0
    if key == "ST_UP":
        return row["st54"] == "内側より上"
    if key == "MZ5_ST":
        return p5["makurizashi"] >= 5.0 and row["st54"] == "内側より上"
    if key == "MZ10_ST":
        return p5["makurizashi"] >= 10.0 and row["st54"] == "内側より上"
    if key == "A10_ST":
        return p5["attack"] >= 10.0 and row["st54"] == "内側より上"
    if key == "A15_ST":
        return p5["attack"] >= 15.0 and row["st54"] == "内側より上"
    l4 = row[f"p4_{months}"]["makuri"] >= 15.0 and row["st43"] == "内側より上"
    if key == "L4_STRONG":
        return l4
    if key == "MZ5_L4":
        return p5["makurizashi"] >= 5.0 and l4
    if key == "A10_L4":
        return p5["attack"] >= 10.0 and l4
    raise ValueError(key)


def blank():
    return {"n": 0, "first": 0, "second": 0, "third": 0}


def add(stat: dict, row: dict):
    stat["n"] += 1
    stat["first"] += row["first"] == 5
    stat["second"] += row["second"] == 5
    stat["third"] += row["third"] == 5


def summary(stat: dict):
    n = stat["n"]
    return {
        "n": n,
        "head": pct(stat["first"], n),
        "top2": pct(stat["first"] + stat["second"], n),
        "top3": pct(stat["first"] + stat["second"] + stat["third"], n),
    }


def aggregate(rows, start, end, months=PROFILE_MONTHS):
    stats = {key: blank() for key, _ in CONDITIONS}
    for row in rows:
        if not start <= row["race_date"] <= end or row[f"p5_{months}"]["n"] <= 0:
            continue
        for key, _ in CONDITIONS:
            if matches(row, key, months):
                add(stats[key], row)
    return {key: summary(stat) for key, stat in stats.items()}


def print_window(label, start, end, rows, months=PROFILE_MONTHS):
    values = aggregate(rows, start, end, months)
    base = values["BASE"]
    print(f"\n■ {label}  {start} ～ {end} / 過去{months}ヶ月profile")
    for key, title in CONDITIONS:
        s = values[key]
        small = " [N小]" if 0 < s["n"] < 20 else ""
        print(
            f"{key:<10} {title:<31} N={s['n']:4d}  "
            f"5頭={s['head']:6.2f}% ({s['head'] - base['head']:+6.2f}pt)  "
            f"2連対={s['top2']:6.2f}% ({s['top2'] - base['top2']:+6.2f}pt)  "
            f"3連対={s['top3']:6.2f}% ({s['top3'] - base['top3']:+6.2f}pt){small}"
        )
    return values


def build_rows(start: date, end: date):
    print("多摩川対象レースを読み込み中...", flush=True)
    races = load_targets(start, end)
    pids = sorted({b["player_id"] for race in races.values() for b in race["boats"]})
    terms = required_terms(start, end)
    racer = load_racer_results(terms)
    history = load_history(start, end, pids)
    hist = TechniqueHistoryIndex(history)
    exhibition_start = months_ago(start, 6)
    exhibition = load_exhibition(exhibition_start, end)
    rolling_exhibition = RollingExhibitionAverage(exhibition)
    target_exhibition_count = sum(code in races for code in exhibition)
    rolling_values = []
    rows, skips = [], Counter()

    for code, race in races.items():
        by_course = {}
        for boat in race["boats"]:
            try:
                course = int(boat["course"])
            except (TypeError, ValueError):
                continue
            if course not in range(1, 7) or course in by_course:
                by_course = {}
                break
            by_course[course] = boat
        if set(by_course) != set(range(1, 7)):
            skips["bad_entry_course"] += 1
            continue

        term = term_info_for_date(race["date"])
        avg_ranks = {}
        for course in (3, 4, 5):
            rr = racer.get((term, by_course[course]["player_id"]))
            avg_ranks[course] = None if rr is None else rr[course]["avg_rank"]
        st43 = None
        st54 = None
        if avg_ranks[3] is not None and avg_ranks[4] is not None:
            st43 = relation_label(float(avg_ranks[4]), float(avg_ranks[3]))
        if avg_ranks[4] is not None and avg_ranks[5] is not None:
            st54 = relation_label(float(avg_ranks[5]), float(avg_ranks[4]))

        pid4 = by_course[4]["player_id"]
        pid5 = by_course[5]["player_id"]
        profiles = {}
        for months in HISTORY_MONTHS:
            profiles[f"p4_{months}"] = rates(hist.profile(pid4, 4, race["date"], months))
            profiles[f"p5_{months}"] = rates(hist.profile(pid5, 5, race["date"], months))

        secondary = None
        avg_exhibition = rolling_exhibition.value(race["date"])
        if avg_exhibition is not None:
            rolling_values.append(avg_exhibition)
        score_map = None if avg_exhibition is None else build_second_scores(exhibition.get(code, []), avg_exhibition)
        if score_map is not None:
            secondary = score_map.get(str(pid5))

        row = {
            "race_code": code,
            "race_date": race["date"],
            "st43": st43,
            "st54": st54,
            "rank3": avg_ranks[3],
            "rank4": avg_ranks[4],
            "rank5": avg_ranks[5],
            "second_score": None,
            "second_rank": None,
            "gap": None,
            "attack_potential": None,
            "st_score": None,
            "straight_score": None,
            "ex_score": None,
            "lap_score": None,
            "mawari_score": None,
            "first": int(race["first"]),
            "second": int(race["second"]),
            "third": int(race["third"]) if race["third"] is not None else None,
            **profiles,
        }
        if secondary is not None:
            row.update({
                "second_score": float(secondary["final_2nd_score"]),
                "second_rank": int(secondary["second_rank"]),
                "gap": float(secondary["gap_to_top"]),
                "attack_potential": float(secondary["attack_potential"]),
                "st_score": float(secondary["st_score"]),
                "straight_score": float(secondary["straight_score"]),
                "ex_score": float(secondary["ex_score"]),
                "lap_score": float(secondary["lap_score"]),
                "mawari_score": float(secondary["mawari_score"]),
            })
        rows.append(row)
    avg_summary = {
        "min": min(rolling_values) if rolling_values else None,
        "max": max(rolling_values) if rolling_values else None,
        "mean": sum(rolling_values) / len(rolling_values) if rolling_values else None,
    }
    return races, rows, skips, target_exhibition_count, avg_summary


def write_csv(end_date: date, rows: list[dict]):
    path = Path(__file__).resolve().parent / "output" / f"tamagawa_lane5_primary_stability_{end_date:%Y%m%d}.csv"
    profile_fields = []
    for course in (4, 5):
        for months in HISTORY_MONTHS:
            profile_fields += [f"p{course}_{months}_n", f"p{course}_{months}_win", f"p{course}_{months}_makuri", f"p{course}_{months}_makurizashi", f"p{course}_{months}_attack"]
    fields = [
        "race_code", "race_date", "st43", "st54", "rank3", "rank4", "rank5",
        *profile_fields, "second_score", "second_rank", "gap", "attack_potential",
        "st_score", "straight_score", "ex_score", "lap_score", "mawari_score",
        "first", "second", "third",
    ]
    with path.open("w", newline="", encoding="utf-8-sig") as f:
        w = csv.DictWriter(f, fieldnames=fields)
        w.writeheader()
        for row in rows:
            out = {k: row.get(k, "") for k in fields}
            out["race_date"] = row["race_date"].isoformat()
            for course in (4, 5):
                for months in HISTORY_MONTHS:
                    p = row[f"p{course}_{months}"]
                    for name in ("n", "win", "makuri", "makurizashi", "attack"):
                        out[f"p{course}_{months}_{name}"] = p[name]
            w.writerow(out)
    return path


def main():
    if len(sys.argv) != 2:
        raise SystemExit("Usage: python3 analysis/analyze_tamagawa_lane5_primary_stability.py END_DATE")
    end_date = parse_date(sys.argv[1])
    long, short = calc_windows(end_date)
    start = min(x[1] for x in long + short)
    races, rows, skips, ex_count, avg = build_rows(start, end_date)
    print("=" * 170)
    print("多摩川5コース：主軸サイン候補比較と時系列安定性")
    print("=" * 170)
    avg_text = "なし" if avg["mean"] is None else f"平均{avg['mean']:.3f}（{avg['min']:.3f}～{avg['max']:.3f}）"
    print(f"対象={start}～{end_date} / レース={len(races)} / 採用行={len(rows)} / 展示レース={ex_count} / 時点別展示平均={avg_text}")
    if skips:
        print("スキップ: " + ", ".join(f"{k}={v}" for k, v in skips.items()))
    print("\n【同一12ヶ月対象でprofile長を比較】")
    for months in HISTORY_MONTHS:
        print_window("直近12ヶ月", long[0][1], long[0][2], rows, months)
    print("\n【長期：過去12ヶ月profile固定】")
    long_values = {label: print_window(label, st, en, rows) for label, st, en in long[1:]}
    print("\n【短期：6ヶ月×4非重複ブロック】")
    short_values = {label: print_window(label, st, en, rows) for label, st, en in short}
    print("\n【再現性まとめ】")
    for key, title in CONDITIONS[1:]:
        wins = sum(v[key]["head"] > v["BASE"]["head"] for v in short_values.values() if v[key]["n"] > 0)
        valid = sum(v[key]["n"] > 0 for v in short_values.values())
        n24 = long_values["直近24ヶ月"][key]["n"]
        delta24 = long_values["直近24ヶ月"][key]["head"] - long_values["直近24ヶ月"]["BASE"]["head"]
        print(f"{key:<10} {title:<31} 6ヶ月で基準超え={wins}/{valid}  N24={n24:4d}  24ヶ月差={delta24:+.2f}pt")
    print(f"\nCSV出力: {write_csv(end_date, rows)}")
    print("注意: 展示6ヶ月平均は対象日を除外したpoint-in-time集計。期別平均ST順位は探索比較にのみ使用し、最終候補には不使用。")


if __name__ == "__main__":
    main()
