#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""多摩川1コースの逃げ信頼条件を探索し、時系列安定性を確認する。

1Cは外コースの攻め率ではなく、1C選手の過去逃げ率・平均ST順位、
当日二次評価、2C/3Cの攻め率を組み合わせて評価する。
条件判定に使うのは対象日前の履歴・期別平均ST順位・対象日展示だけで、
着順は成績集計にのみ使用する。払戻・オッズは使用しない。

Usage: python3 analysis/analyze_tamagawa_lane1_primary_stability.py 2026-09-09
"""

from __future__ import annotations

import bisect
import csv
import sys
from collections import Counter
from datetime import date, timedelta
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from analyze_tamagawa_boaters_hypothesis import (  # noqa: E402
    HISTORY_MONTHS,
    TechniqueHistoryIndex,
    load_history,
    load_racer_results,
    months_ago,
    parse_date,
    required_terms,
    relation_label,
    term_info_for_date,
)
from analyze_tamagawa_lane4_exacta_structure import load_targets, pct  # noqa: E402
from analyze_tamagawa_lane4_strong_condition_second_eval import (  # noqa: E402
    build_second_scores,
    load_exhibition,
)
from analyze_tamagawa_lane2_primary_stability import RollingExhibitionAverage  # noqa: E402

PROFILE_MONTHS = 12


class NogashiHistoryIndex:
    """選手×2Cの過去履歴から、1Cを逃がした割合をpoint-in-timeで返す。"""

    def __init__(self, rows: list[dict]):
        grouped = {}
        for row in rows:
            if int(row.get("course", 0)) != 2:
                continue
            grouped.setdefault(str(row["player_id"]), []).append(row)
        self.data = {}
        for pid, items in grouped.items():
            items.sort(key=lambda item: item["date"])
            dates = [item["date"] for item in items]
            prefix_n = [0]
            prefix_nogashi = [0]
            for item in items:
                prefix_n.append(prefix_n[-1] + 1)
                is_nogashi = (not item["won"]) and int(item.get("winner_course", 0)) == 1
                prefix_nogashi.append(prefix_nogashi[-1] + (1 if is_nogashi else 0))
            self.data[pid] = (dates, prefix_n, prefix_nogashi)

    def rate(self, player_id: str, target_date: date, months: int) -> float | None:
        item = self.data.get(str(player_id))
        if item is None:
            return None
        dates, prefix_n, prefix_nogashi = item
        lo = bisect.bisect_left(dates, months_ago(target_date, months))
        hi = bisect.bisect_left(dates, target_date)
        n = hi - lo
        return pct(prefix_nogashi[hi] - prefix_nogashi[lo], n) if n else None

CONDITIONS = (
    ("BASE", "1C履歴あり"),
    ("E50", "1C逃げ率50%以上"),
    ("E55", "1C逃げ率55%以上"),
    ("E60", "1C逃げ率60%以上"),
    ("E65", "1C逃げ率65%以上"),
    ("ST_UP", "1Cが2Cより平均ST順位上"),
    ("ST_TOP2", "1C平均ST順位2位以内"),
    ("E55_ST", "逃げ率55%以上×ST上"),
    ("E60_ST", "逃げ率60%以上×ST上"),
    ("SEC_TOP3", "二次順位3位以内"),
    ("SEC_RANK1", "二次順位1位"),
    ("SEC24_GAP5", "二次24以上×TOP差5以内"),
    ("SEC27_GAP5", "二次27以上×TOP差5以内"),
    ("LAP4", "周回評価4以上"),
    ("STRAIGHT4", "直線評価4以上"),
    ("E55_SEC3", "逃げ率55%以上×二次順位3位以内"),
    ("E60_SEC3", "逃げ率60%以上×二次順位3位以内"),
    ("E55_ST_SEC3", "逃げ率55%以上×ST上×二次順位3位以内"),
    ("E60_ST_SEC3", "逃げ率60%以上×ST上×二次順位3位以内"),
    ("LOW2_10", "2C攻め率10%未満"),
    ("LOW3_15", "3C攻め率15%未満"),
    ("E55_LOW23", "逃げ率55%以上×2C攻め10%未満×3C攻め15%未満"),
    ("N40", "2C逃し率40%以上"),
    ("N50", "2C逃し率50%以上"),
    ("N60", "2C逃し率60%以上"),
    ("E55_N50", "逃げ率55%以上×2C逃し率50%以上"),
    ("E60_N50", "逃げ率60%以上×2C逃し率50%以上"),
    ("E55_N60", "逃げ率55%以上×2C逃し率60%以上"),
)


def rates(profile: dict) -> dict[str, float]:
    n = int(profile.get("n", 0))
    if n <= 0:
        return {"n": 0, "escape": 0.0, "attack": 0.0}
    tech = profile.get("tech", {})
    attack = int(tech.get("まくり", 0)) + int(tech.get("まくり差し", 0))
    return {
        "n": n,
        "escape": pct(int(tech.get("逃げ", 0)), n),
        "attack": pct(attack, n),
    }


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
        short.append((label, months_ago(anchor, (idx + 1) * 6), months_ago(anchor, idx * 6) - timedelta(days=1)))
    return long, short


def matches(row: dict, key: str, months: int = PROFILE_MONTHS) -> bool:
    p1 = row[f"p1_{months}"]
    if key == "BASE":
        return p1["n"] > 0
    if p1["n"] <= 0:
        return False
    escape = p1["escape"]
    st_up = row["st12"] == "1C上"
    st_top2 = row["rank1"] is not None and row["rank1"] <= 2.0
    sec3 = row["second_rank"] is not None and row["second_rank"] <= 3
    sec1 = row["second_rank"] == 1
    sec24 = row["second_score"] is not None and row["gap"] is not None and row["second_score"] >= 24.0 and row["gap"] <= 5.0
    sec27 = row["second_score"] is not None and row["gap"] is not None and row["second_score"] >= 27.0 and row["gap"] <= 5.0
    lap4 = row["lap_score"] is not None and row["lap_score"] >= 4.0
    straight4 = row["straight_score"] is not None and row["straight_score"] >= 4.0
    low2 = row[f"p2_{months}"]["attack"] < 10.0
    low3 = row[f"p3_{months}"]["attack"] < 15.0
    nogashi = row[f"p2_{months}"].get("nogashi")
    return {
        "E50": escape >= 50.0,
        "E55": escape >= 55.0,
        "E60": escape >= 60.0,
        "E65": escape >= 65.0,
        "ST_UP": st_up,
        "ST_TOP2": st_top2,
        "E55_ST": escape >= 55.0 and st_up,
        "E60_ST": escape >= 60.0 and st_up,
        "SEC_TOP3": sec3,
        "SEC_RANK1": sec1,
        "SEC24_GAP5": sec24,
        "SEC27_GAP5": sec27,
        "LAP4": lap4,
        "STRAIGHT4": straight4,
        "E55_SEC3": escape >= 55.0 and sec3,
        "E60_SEC3": escape >= 60.0 and sec3,
        "E55_ST_SEC3": escape >= 55.0 and st_up and sec3,
        "E60_ST_SEC3": escape >= 60.0 and st_up and sec3,
        "LOW2_10": low2,
        "LOW3_15": low3,
        "E55_LOW23": escape >= 55.0 and low2 and low3,
        "N40": nogashi is not None and nogashi >= 40.0,
        "N50": nogashi is not None and nogashi >= 50.0,
        "N60": nogashi is not None and nogashi >= 60.0,
        "E55_N50": escape >= 55.0 and nogashi is not None and nogashi >= 50.0,
        "E60_N50": escape >= 60.0 and nogashi is not None and nogashi >= 50.0,
        "E55_N60": escape >= 55.0 and nogashi is not None and nogashi >= 60.0,
    }.get(key, False)


def blank() -> dict:
    return {"n": 0, "first": 0, "second": 0, "third": 0}


def add(stat: dict, row: dict) -> None:
    stat["n"] += 1
    stat["first"] += row["first"] == 1
    stat["second"] += row["second"] == 1
    stat["third"] += row["third"] == 1


def summary(stat: dict) -> dict:
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
        if not start <= row["race_date"] <= end:
            continue
        for key, _ in CONDITIONS:
            if matches(row, key, months):
                add(stats[key], row)
    return {key: summary(value) for key, value in stats.items()}


def print_window(label, start, end, rows, months=PROFILE_MONTHS):
    values = aggregate(rows, start, end, months)
    base = values["BASE"]
    print(f"\n■ {label} {start}～{end} / 過去{months}ヶ月profile")
    for key, title in CONDITIONS:
        stat = values[key]
        small = " [N小]" if 0 < stat["n"] < 20 else ""
        print(
            f"{key:<12} {title:<43} N={stat['n']:4d}  "
            f"1着={stat['head']:6.2f}% ({stat['head'] - base['head']:+6.2f}pt)  "
            f"2連対={stat['top2']:6.2f}% ({stat['top2'] - base['top2']:+6.2f}pt)  "
            f"3連対={stat['top3']:6.2f}% ({stat['top3'] - base['top3']:+6.2f}pt){small}"
        )
    return values


def build_rows(start: date, end: date):
    print("多摩川対象レースを読み込み中...", flush=True)
    races = load_targets(start, end)
    pids = sorted({boat["player_id"] for race in races.values() for boat in race["boats"]})
    history = load_history(start, end, pids)
    hist = TechniqueHistoryIndex(history)
    nogashi_hist = NogashiHistoryIndex(history)
    racer = load_racer_results(required_terms(start, end))
    exhibition = load_exhibition(months_ago(start, 6), end)
    rolling = RollingExhibitionAverage(exhibition)
    rows, skips = [], Counter()

    for code, race in races.items():
        by_course = {}
        for boat in race["boats"]:
            course = int(boat["course"] or 0)
            if course not in range(1, 7) or course in by_course:
                by_course = {}
                break
            by_course[course] = boat
        if set(by_course) != set(range(1, 7)):
            skips["bad_entry_course"] += 1
            continue

        term = term_info_for_date(race["date"])
        rank = {}
        for course in (1, 2, 3):
            result = racer.get((term, by_course[course]["player_id"]))
            rank[course] = None if result is None else result[course]["avg_rank"]
        st12 = None
        if rank[1] is not None and rank[2] is not None:
            st12 = "1C上" if float(rank[1]) < float(rank[2]) else ("同じ" if float(rank[1]) == float(rank[2]) else "1C下")

        profiles = {}
        for months in HISTORY_MONTHS:
            profiles[f"p1_{months}"] = rates(hist.profile(by_course[1]["player_id"], 1, race["date"], months))
            profiles[f"p2_{months}"] = rates(hist.profile(by_course[2]["player_id"], 2, race["date"], months))
            profiles[f"p2_{months}"]["nogashi"] = nogashi_hist.rate(by_course[2]["player_id"], race["date"], months)
            profiles[f"p3_{months}"] = rates(hist.profile(by_course[3]["player_id"], 3, race["date"], months))

        avg_exhibition = rolling.value(race["date"])
        score_map = None if avg_exhibition is None else build_second_scores(exhibition.get(code, []), avg_exhibition)
        second = None if score_map is None else score_map.get(by_course[1]["player_id"])
        row = {
            "race_code": code,
            "race_date": race["date"],
            "st12": st12,
            "rank1": rank[1],
            "rank2": rank[2],
            "rank3": rank[3],
            "second_score": None,
            "second_rank": None,
            "gap": None,
            "lap_score": None,
            "straight_score": None,
            "first": int(race["first"]),
            "second": int(race["second"]),
            "third": int(race["third"]) if race["third"] is not None else None,
            **profiles,
        }
        if second is not None:
            row.update({
                "second_score": float(second["final_2nd_score"]),
                "second_rank": int(second["second_rank"]),
                "gap": float(second["gap_to_top"]),
                "lap_score": float(second["lap_score"]),
                "straight_score": float(second["straight_score"]),
            })
        rows.append(row)
    return races, rows, skips


def write_csv(end: date, rows: list[dict]) -> Path:
    path = Path(__file__).resolve().parent / "output" / f"tamagawa_lane1_primary_stability_{end:%Y%m%d}.csv"
    fields = ["race_code", "race_date", "st12", "rank1", "rank2", "rank3"]
    for course in (1, 2, 3):
        for months in HISTORY_MONTHS:
            fields += [f"p{course}_{months}_{name}" for name in ("n", "escape", "attack")]
            if course == 2:
                fields.append(f"p{course}_{months}_nogashi")
    fields += ["second_score", "second_rank", "gap", "lap_score", "straight_score", "first", "second", "third"]
    with path.open("w", encoding="utf-8-sig", newline="") as f:
        writer = csv.DictWriter(f, fieldnames=fields)
        writer.writeheader()
        for row in rows:
            out = {key: row.get(key, "") for key in fields}
            out["race_date"] = row["race_date"].isoformat()
            for course in (1, 2, 3):
                for months in HISTORY_MONTHS:
                    profile = row[f"p{course}_{months}"]
                    for name in ("n", "escape", "attack"):
                        out[f"p{course}_{months}_{name}"] = profile[name]
                    if course == 2:
                        out[f"p{course}_{months}_nogashi"] = profile.get("nogashi")
            writer.writerow(out)
    return path


def main() -> None:
    if len(sys.argv) != 2:
        raise SystemExit("Usage: python3 analysis/analyze_tamagawa_lane1_primary_stability.py END_DATE")
    end = parse_date(sys.argv[1])
    long, short = calc_windows(end)
    start = min(item[1] for item in long + short)
    races, rows, skips = build_rows(start, end)
    print("=" * 190)
    print("多摩川1コース：逃げ信頼条件比較と時系列安定性")
    print("=" * 190)
    print(f"対象={start}～{end} / レース={len(races)} / 採用行={len(rows)}")
    if skips:
        print("スキップ: " + ", ".join(f"{key}={value}" for key, value in skips.items()))
    print("\n【同一12ヶ月対象でprofile長を比較】")
    for months in HISTORY_MONTHS:
        print_window("直近12ヶ月", long[0][1], long[0][2], rows, months)
    print("\n【長期：過去12ヶ月profile固定】")
    long_values = {label: print_window(label, st, en, rows) for label, st, en in long[1:]}
    print("\n【短期：6ヶ月×4非重複ブロック】")
    short_values = {label: print_window(label, st, en, rows) for label, st, en in short}
    print("\n【再現性まとめ】")
    for key, title in CONDITIONS[1:]:
        valid = [value for value in short_values.values() if value[key]["n"] > 0]
        wins = sum(value[key]["head"] > value["BASE"]["head"] for value in valid)
        n24 = long_values["直近24ヶ月"][key]["n"]
        delta24 = long_values["直近24ヶ月"][key]["head"] - long_values["直近24ヶ月"]["BASE"]["head"]
        print(f"{key:<12} {title:<43} 6ヶ月基準超え={wins}/{len(valid)} N24={n24:4d} 24ヶ月差={delta24:+.2f}pt")
    print(f"\nCSV出力: {write_csv(end, rows)}")
    print("注意: 展示二次評価は対象日を含めない過去6ヶ月平均と当日展示から算出。")


if __name__ == "__main__":
    main()
