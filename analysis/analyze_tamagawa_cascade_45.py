#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""多摩川の展開連鎖「4Cまくり優位→5C浮上」を検証する。

対象日前の4C/5C決まり手履歴、期別平均ST順位、当日展示二次評価だけを
条件判定に使う。実着順・決まり手は結果集計だけに使い、払戻・オッズは使わない。
まず4Cを展開の起点、5Cを浮上コースに絞り、過学習を避けるため
4C★単独、5Cまくり差し単独、両者の組み合わせを時系列比較する。
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
from analyze_tamagawa_lane4_exacta_structure import load_targets  # noqa: E402
from analyze_tamagawa_lane4_strong_condition_second_eval import (  # noqa: E402
    build_second_scores, load_exhibition,
)
from analyze_tamagawa_lane2_primary_stability import RollingExhibitionAverage  # noqa: E402

VENUE_CODE = "TMG"
PROFILE_MONTHS = 12


def pct(num: int, den: int) -> float:
    return 100.0 * num / den if den else 0.0


def rates(profile: dict) -> dict[str, float]:
    n = int(profile.get("n", 0))
    tech = profile.get("tech", {})
    if n <= 0:
        return {"n": 0, "makuri": 0.0, "makurizashi": 0.0, "attack": 0.0}
    makuri = pct(int(tech.get("まくり", 0)), n)
    makurizashi = pct(int(tech.get("まくり差し", 0)), n)
    return {"n": n, "makuri": makuri, "makurizashi": makurizashi, "attack": makuri + makurizashi}


def calc_windows(end: date):
    anchor = end + timedelta(days=1)
    long = [("直近12ヶ月", months_ago(anchor, 12), end),
            ("直近18ヶ月", months_ago(anchor, 18), end),
            ("直近24ヶ月", months_ago(anchor, 24), end)]
    labels = ("直近0-6ヶ月", "6-12ヶ月前", "12-18ヶ月前", "18-24ヶ月前")
    short = []
    for i, label in enumerate(labels):
        short.append((label, months_ago(anchor, (i + 1) * 6), months_ago(anchor, i * 6) - timedelta(days=1)))
    return long, short


def blank() -> dict:
    return {"n": 0, "first4": 0, "first5": 0, "top2_5": 0, "top3_5": 0,
            "both_top3": 0, "exacta45": 0, "makuri4_wins": 0, "makuri4_wins_5top3": 0}


def add(stat: dict, row: dict) -> None:
    stat["n"] += 1
    first, second, third = row["first"], row["second"], row["third"]
    stat["first4"] += first == 4
    stat["first5"] += first == 5
    stat["top2_5"] += first == 5 or second == 5
    stat["top3_5"] += first == 5 or second == 5 or third == 5
    stat["both_top3"] += 4 in (first, second, third) and 5 in (first, second, third)
    stat["exacta45"] += first == 4 and second == 5
    if first == 4 and row.get("winner_technique") == "まくり":
        stat["makuri4_wins"] += 1
        stat["makuri4_wins_5top3"] += 5 in (second, third)


def summarize(stat: dict) -> dict:
    n = stat["n"]
    return {"n": n, "4head": pct(stat["first4"], n), "5head": pct(stat["first5"], n),
            "5top2": pct(stat["top2_5"], n), "5top3": pct(stat["top3_5"], n),
            "both_top3": pct(stat["both_top3"], n), "exacta45": pct(stat["exacta45"], n),
            "m4n": stat["makuri4_wins"], "m4_5top3": pct(stat["makuri4_wins_5top3"], stat["makuri4_wins"])}


CONDITIONS = (
    ("BASE", "4C/5C履歴あり"),
    ("L4", "4Cまくり率15%以上"),
    ("L4_ST", "4Cまくり率15%以上＋4Cが3CよりST上（4C★）"),
    ("MZ5", "5Cまくり差し率5%以上"),
    ("A10", "5C攻め率10%以上"),
    ("CHAIN_MZ", "4C★＋5Cまくり差し率5%以上"),
    ("CHAIN_A10", "4C★＋5C攻め率10%以上"),
    ("L4_MZ", "4Cまくり率15%以上＋5Cまくり差し率5%以上"),
)


def matches(row: dict, key: str) -> bool:
    p4, p5 = row["p4"], row["p5"]
    if p4["n"] <= 0 or p5["n"] <= 0:
        return False
    l4 = p4["makuri"] >= 15.0
    l4_st = l4 and row["st43"] == "内側より上"
    mz5 = p5["makurizashi"] >= 5.0
    a10 = p5["attack"] >= 10.0
    return {
        "BASE": True, "L4": l4, "L4_ST": l4_st, "MZ5": mz5, "A10": a10,
        "CHAIN_MZ": l4_st and mz5, "CHAIN_A10": l4_st and a10,
        "L4_MZ": l4 and mz5,
    }[key]


def load_winner_techniques(start: date, end: date) -> dict[str, str]:
    from slit_validate_v2 import connect_db
    sql = """
    SELECT DISTINCT ON (rrd.race_code)
        rrd.race_code, TRIM(COALESCE(rrd.technique, '')) AS technique
    FROM boat_race.race_result_detail rrd
    JOIN boat_race.race_master rm ON rm.race_code = rrd.race_code
    WHERE rm.race_date BETWEEN %s::date AND %s::date
      AND SUBSTRING(rm.race_code, 9, 3) = %s
      AND TRIM(rrd.rank::text) = '1'
    ORDER BY rrd.race_code
    """
    with connect_db() as conn:
        with conn.cursor() as cur:
            cur.execute(sql, (start, end, VENUE_CODE))
            return {str(code): str(technique or "").strip() for code, technique in cur.fetchall()}


def build_rows(start: date, end: date):
    print("多摩川対象レースを読み込み中...", flush=True)
    races = load_targets(start, end)
    pids = sorted({boat["player_id"] for race in races.values() for boat in race["boats"]})
    history = load_history(start, end, pids)
    hist = TechniqueHistoryIndex(history)
    racer = load_racer_results(required_terms(start, end))
    exhibition = load_exhibition(months_ago(start, 6), end)
    rolling = RollingExhibitionAverage(exhibition)
    winner_techniques = load_winner_techniques(start, end)
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
        ranks = {}
        missing = False
        for course in (3, 4, 5):
            result = racer.get((term, by_course[course]["player_id"]))
            ranks[course] = None if result is None else result[course]["avg_rank"]
            if ranks[course] is None:
                missing = True
        if missing:
            skips["missing_avg_rank"] += 1
            continue
        st43 = relation_label(float(ranks[4]), float(ranks[3]))
        profiles = {
            "p4": rates(hist.profile(by_course[4]["player_id"], 4, race["date"], PROFILE_MONTHS)),
            "p5": rates(hist.profile(by_course[5]["player_id"], 5, race["date"], PROFILE_MONTHS)),
        }
        avg_exhibition = rolling.value(race["date"])
        second = None
        if avg_exhibition is not None:
            score_map = build_second_scores(exhibition.get(code, []), avg_exhibition)
            if score_map is not None:
                second = {
                    "p4": score_map.get(by_course[4]["player_id"]),
                    "p5": score_map.get(by_course[5]["player_id"]),
                }
        rows.append({
            "race_code": code, "race_date": race["date"], "st43": st43,
            "rank3": ranks[3], "rank4": ranks[4], "rank5": ranks[5],
            **profiles,
            "second4_rank": None if not second or second["p4"] is None else second["p4"]["second_rank"],
            "second5_rank": None if not second or second["p5"] is None else second["p5"]["second_rank"],
            "first": int(race["first"]), "second": int(race["second"]),
            "third": int(race["third"]) if race["third"] is not None else None,
            "winner_technique": winner_techniques.get(code, ""),
        })
    return rows, skips


def aggregate(rows, start, end):
    stats = {key: blank() for key, _ in CONDITIONS}
    for row in rows:
        if not start <= row["race_date"] <= end:
            continue
        for key, _ in CONDITIONS:
            if matches(row, key):
                add(stats[key], row)
    return {key: summarize(stat) for key, stat in stats.items()}


def print_window(label, start, end, rows):
    values = aggregate(rows, start, end)
    base = values["BASE"]
    print(f"\n■ {label} {start}～{end}")
    for key, title in CONDITIONS:
        s = values[key]
        print(f"{key:<10} {title:<42} N={s['n']:4d} 4頭={s['4head']:6.2f}% 5頭={s['5head']:6.2f}% "
              f"5-2連={s['5top2']:6.2f}% 5-3連={s['5top3']:6.2f}% "
              f"4/5共にTOP3={s['both_top3']:6.2f}% 4-5={s['exacta45']:6.2f}% "
              f"4まくり勝N={s['m4n']:3d}→5連={s['m4_5top3']:6.2f}%")
    return values


def main() -> None:
    if len(sys.argv) != 2:
        raise SystemExit("Usage: python3 analysis/analyze_tamagawa_cascade_45.py END_DATE")
    end = parse_date(sys.argv[1])
    long, short = calc_windows(end)
    start = min(item[1] for item in long + short)
    rows, skips = build_rows(start, end)
    print("=" * 180)
    print("多摩川 展開連鎖：4Cまくり優位→5C浮上")
    print("=" * 180)
    print(f"対象={start}～{end} / 採用行={len(rows)}")
    if skips:
        print("スキップ: " + ", ".join(f"{k}={v}" for k, v in skips.items()))
    print("\n【長期：過去12ヶ月profile固定】")
    long_values = {label: print_window(label, st, en, rows) for label, st, en in long}
    print("\n【短期：6ヶ月×4非重複ブロック】")
    short_values = {label: print_window(label, st, en, rows) for label, st, en in short}
    print("\n【再現性まとめ：4C★＋5Cまくり差し率5%以上】")
    for key in ("L4_ST", "MZ5", "CHAIN_MZ", "CHAIN_A10", "L4_MZ"):
        pairs = [(v[key], v["BASE"]) for v in short_values.values() if v[key]["n"] > 0]
        wins = sum(s["5top3"] > b["5top3"] for s, b in pairs)
        fourfive = sum(s["both_top3"] > b["both_top3"] for s, b in pairs)
        print(f"{key:<10} 5C-3連基準超え={wins}/{len(pairs)} / 4・5共TOP3基準超え={fourfive}/{len(pairs)} "
              f"N24={long_values['直近24ヶ月'][key]['n']:4d}")


if __name__ == "__main__":
    main()
