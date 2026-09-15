#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""全場のコースサイン一次条件を同じ手順で比較する。

多摩川で使った一次条件（逃げ率、差し率、攻め率、隣接ST順位差）を
全24場へ適用し、場ごとの母数・成績・6か月ブロック安定性を確認する。
ここでは展示・二次評価を使わず、まず一次条件を場別に採用できるか判定する。
着順は評価にのみ使い、条件判定はレース日前12か月の履歴と期別平均ST順位だけで行う。
"""

from __future__ import annotations

import csv
import sys
from collections import Counter, defaultdict
from datetime import date, timedelta
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from analyze_tamagawa_boaters_hypothesis import (  # noqa: E402
    TechniqueHistoryIndex,
    load_history,
    load_racer_results,
    months_ago,
    parse_date,
    required_terms,
    term_info_for_date,
)
from slit_validate_v2 import connect_db  # noqa: E402

PLACE_CODES = (
    "KRY", "TDA", "EDG", "HWJ", "TMG", "HMN", "GMG", "TKN", "TSU", "MKN", "BWK", "SME",
    "AMG", "NRT", "MRG", "KJM", "MYJ", "TKY", "SMS", "WKM", "ASY", "FKO", "KRT", "OMR",
)


def pct(num: int, den: int) -> float:
    return 100.0 * num / den if den else 0.0


def windows(end: date):
    anchor = end + timedelta(days=1)
    for i, label in enumerate(("0-6", "6-12", "12-18", "18-24")):
        yield label, months_ago(anchor, (i + 1) * 6), months_ago(anchor, i * 6) - timedelta(days=1)


def load_targets(start: date, end: date, places: tuple[str, ...]):
    sql = """
WITH target_races AS (
    SELECT race_code, race_date
    FROM boat_race.race_master
    WHERE race_date BETWEEN %s::date AND %s::date
      AND SUBSTRING(race_code, 9, 3) = ANY(%s)
), result_course AS (
    SELECT DISTINCT ON (rrd.race_code, rrd.player_id)
        rrd.race_code, rrd.player_id, rrd.entry_course::integer AS entry_course
    FROM boat_race.race_result_detail rrd
    JOIN target_races tr ON tr.race_code = rrd.race_code
    WHERE rrd.entry_course BETWEEN 1 AND 6
    ORDER BY rrd.race_code, rrd.player_id
), ex_course AS (
    SELECT DISTINCT ON (el.race_code, el.player_id)
        el.race_code, el.player_id, el.entry_course::integer AS entry_course
    FROM boat_race.exhibition_live el
    JOIN target_races tr ON tr.race_code = el.race_code
    WHERE el.entry_course BETWEEN 1 AND 6
    ORDER BY el.race_code, el.player_id, el.created_date DESC NULLS LAST
), finish AS (
    SELECT
        rrd.race_code,
        MAX(rrd.entry_course::integer) FILTER (WHERE TRIM(rrd.rank::text) = '1') AS first_course,
        MAX(rrd.entry_course::integer) FILTER (WHERE TRIM(rrd.rank::text) = '2') AS second_course,
        MAX(rrd.entry_course::integer) FILTER (WHERE TRIM(rrd.rank::text) = '3') AS third_course
    FROM boat_race.race_result_detail rrd
    JOIN target_races tr ON tr.race_code = rrd.race_code
    GROUP BY rrd.race_code
)
SELECT tr.race_code, tr.race_date, re.player_id::text,
       COALESCE(rc.entry_course, ec.entry_course)::integer AS entry_course,
       f.first_course, f.second_course, f.third_course
FROM target_races tr
JOIN boat_race.race_entry re ON re.race_code = tr.race_code
LEFT JOIN result_course rc ON rc.race_code = re.race_code AND rc.player_id = re.player_id
LEFT JOIN ex_course ec ON ec.race_code = re.race_code AND ec.player_id = re.player_id
JOIN finish f ON f.race_code = tr.race_code
WHERE f.first_course BETWEEN 1 AND 6 AND f.second_course BETWEEN 1 AND 6
ORDER BY tr.race_date, tr.race_code, entry_course NULLS LAST
"""
    races = {}
    with connect_db() as conn:
        with conn.cursor() as cur:
            cur.execute(sql, (start, end, list(places)))
            for code, race_date, pid, course, first, second, third in cur.fetchall():
                race = races.setdefault(str(code), {"date": race_date, "boats": [], "first": int(first), "second": int(second), "third": int(third) if third is not None else None})
                race["boats"].append({"player_id": str(pid).strip(), "course": int(course) if course is not None else None})
    return races


def profile_rates(profile: dict) -> dict[str, float]:
    n = int(profile.get("n", 0))
    tech = profile.get("tech", {})
    if n <= 0:
        return {"n": 0, "nige": 0.0, "sashi": 0.0, "makuri": 0.0, "attack": 0.0}
    get = lambda key: pct(int(tech.get(key, 0)), n)
    return {"n": n, "nige": get("逃げ"), "sashi": get("差し"), "makuri": get("まくり"), "attack": get("まくり") + get("まくり差し")}


def primary_match(row: dict, course: int) -> bool:
    p = row[course]
    if course == 1:
        return p["nige"] >= 55.0
    if course == 2:
        return p["sashi"] >= 10.0 or (p["makuri"] >= 5.0 and row["st21"] == "上")
    if course == 3:
        return p["attack"] >= 15.0
    if course == 4:
        return p["makuri"] >= 15.0 and row["st43"] == "上"
    if course == 5:
        return p["attack"] >= 10.0
    return p["attack"] >= 5.0 and row["st65"] == "上"


def blank():
    return {"n": 0, "first": 0, "top2": 0, "top3": 0}


def add(stat: dict, course: int, first: int, second: int, third: int | None):
    stat["n"] += 1
    stat["first"] += first == course
    stat["top2"] += course in (first, second)
    stat["top3"] += course in (first, second, third)


def summarize(stat: dict) -> dict:
    n = stat["n"]
    return {"n": n, "first": pct(stat["first"], n), "top2": pct(stat["top2"], n), "top3": pct(stat["top3"], n)}


def main() -> None:
    if len(sys.argv) not in (2, 3):
        raise SystemExit("Usage: python3 analysis/analyze_all_venue_lane_signals.py END_DATE [PLACE_CODE]")
    end = parse_date(sys.argv[1])
    places = (sys.argv[2].strip().upper(),) if len(sys.argv) == 3 else PLACE_CODES
    if any(place not in PLACE_CODES for place in places):
        raise SystemExit(f"unknown place: {places}")
    start = months_ago(end, 24) + timedelta(days=1)
    print(f"対象期間 {start}～{end}", flush=True)
    races = load_targets(start, end, places)
    pids = sorted({b["player_id"] for r in races.values() for b in r["boats"]})
    print(f"レース {len(races):,} / 選手 {len(pids):,}", flush=True)
    racer = load_racer_results(required_terms(start, end))
    history = TechniqueHistoryIndex(load_history(start, end, pids))

    by_place = defaultdict(lambda: {c: {"BASE": blank(), "STAR": blank()} for c in range(1, 7)})
    block = defaultdict(lambda: {c: {"BASE": blank(), "STAR": blank()} for c in range(1, 7)})
    skips = Counter()
    for race_code, race in races.items():
        if len(race["boats"]) != 6:
            skips["not6"] += 1
            continue
        by_course = {}
        for boat in race["boats"]:
            c = boat["course"]
            if c not in range(1, 7) or c in by_course:
                by_course = {}
                break
            by_course[c] = boat
        if set(by_course) != set(range(1, 7)):
            skips["bad_entry"] += 1
            continue
        race_date = race["date"]
        term = term_info_for_date(race_date)
        ranks = {}
        if any((rr := racer.get((term, by_course[c]["player_id"]))) is None or rr[c]["avg_rank"] is None for c in range(1, 7)):
            skips["missing_rank"] += 1
            continue
        for c in range(1, 7):
            ranks[c] = float(racer[(term, by_course[c]["player_id"])][c]["avg_rank"])
        row = {c: profile_rates(history.profile(by_course[c]["player_id"], c, race_date, 12)) for c in range(1, 7)}
        row["st21"] = "上" if ranks[2] < ranks[1] else ("同じ" if ranks[2] == ranks[1] else "下")
        row["st43"] = "上" if ranks[4] < ranks[3] else ("同じ" if ranks[4] == ranks[3] else "下")
        row["st65"] = "上" if ranks[6] < ranks[5] else ("同じ" if ranks[6] == ranks[5] else "下")
        place = str(race_code)[8:11]
        if place not in places:
            skips["unknown_place"] += 1
            continue
        st = by_place[place]
        for c in range(1, 7):
            add(st[c]["BASE"], c, race["first"], race["second"], race["third"])
            if primary_match(row, c):
                add(st[c]["STAR"], c, race["first"], race["second"], race["third"])
        for label, block_start, block_end in windows(end):
            if not block_start <= race_date <= block_end:
                continue
            bst = block[(place, label)]
            for c in range(1, 7):
                add(bst[c]["BASE"], c, race["first"], race["second"], race["third"])
                if primary_match(row, c):
                    add(bst[c]["STAR"], c, race["first"], race["second"], race["third"])

    out_dir = Path(__file__).resolve().parent / "output"
    out_dir.mkdir(parents=True, exist_ok=True)
    suffix = places[0] if len(places) == 1 else "ALL"
    out_path = out_dir / f"all_venue_lane_signals_{suffix}_{end:%Y%m%d}.csv"
    with out_path.open("w", newline="", encoding="utf-8-sig") as fp:
        writer = csv.writer(fp)
        writer.writerow(["place", "course", "scope", "n", "first_rate", "top2_rate", "top3_rate", "delta_first", "delta_top2", "delta_top3"])
        for place in places:
            for c in range(1, 7):
                base = summarize(by_place[place][c]["BASE"])
                star = summarize(by_place[place][c]["STAR"])
                writer.writerow([place, c, "24m_base", base["n"], f"{base['first']:.4f}", f"{base['top2']:.4f}", f"{base['top3']:.4f}", "", "", ""])
                writer.writerow([place, c, "24m_star", star["n"], f"{star['first']:.4f}", f"{star['top2']:.4f}", f"{star['top3']:.4f}", f"{star['first']-base['first']:.4f}", f"{star['top2']-base['top2']:.4f}", f"{star['top3']-base['top3']:.4f}"])
                for label, _, _ in windows(end):
                    b = summarize(block[(place, label)][c]["BASE"])
                    s = summarize(block[(place, label)][c]["STAR"])
                    writer.writerow([place, c, f"{label}_base", b["n"], f"{b['first']:.4f}", f"{b['top2']:.4f}", f"{b['top3']:.4f}", "", "", ""])
                    writer.writerow([place, c, f"{label}_star", s["n"], f"{s['first']:.4f}", f"{s['top2']:.4f}", f"{s['top3']:.4f}", f"{s['first']-b['first']:.4f}", f"{s['top2']-b['top2']:.4f}", f"{s['top3']-b['top3']:.4f}"])

    print("place course N★ 1着率(基準比) 2連対率(基準比) 3連対率(基準比) 安定ブロック", flush=True)
    for place in places:
        for c in range(1, 7):
            base = summarize(by_place[place][c]["BASE"])
            star = summarize(by_place[place][c]["STAR"])
            checks = []
            for label, _, _ in windows(end):
                b = summarize(block[(place, label)][c]["BASE"])
                s = summarize(block[(place, label)][c]["STAR"])
                checks.append(s["n"] >= 30 and s["top3"] >= b["top3"])
            print(f"{place} {c}C N={star['n']:4d} 1着 {star['first']:6.2f}% ({star['first']-base['first']:+6.2f}) "
                  f"2連 {star['top2']:6.2f}% ({star['top2']-base['top2']:+6.2f}) "
                  f"3連 {star['top3']:6.2f}% ({star['top3']-base['top3']:+6.2f}) "
                  f"安定{sum(checks)}/4", flush=True)
    print(f"CSV: {out_path}")
    print(f"スキップ: {dict(skips)}")


if __name__ == "__main__":
    main()
