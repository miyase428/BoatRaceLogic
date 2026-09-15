#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""全場の一次★に対する二次評価昇格条件を検証する。"""

from __future__ import annotations

import json
import sys
from collections import Counter, defaultdict
from datetime import date, timedelta
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from analyze_all_venue_lane_signals import PLACE_CODES, load_targets, primary_match, profile_rates, windows  # noqa: E402
from analyze_tamagawa_boaters_hypothesis import (  # noqa: E402
    TechniqueHistoryIndex,
    load_history,
    load_racer_results,
    months_ago,
    parse_date,
    required_terms,
    term_info_for_date,
)
from analyze_tamagawa_lane4_strong_condition_second_eval import build_second_scores  # noqa: E402
from slit_validate_v2 import connect_db  # noqa: E402

PLACE_NAMES = {
    "KRY": "桐生", "TDA": "戸田", "EDG": "江戸川", "HWJ": "平和島", "TMG": "多摩川", "HMN": "浜名湖",
    "GMG": "蒲郡", "TKN": "常滑", "TSU": "津", "MKN": "三国", "BWK": "びわこ", "SME": "住之江",
    "AMG": "尼崎", "NRT": "鳴門", "MRG": "丸亀", "KJM": "児島", "MYJ": "宮島", "TKY": "徳山",
    "SMS": "下関", "WKM": "若松", "ASY": "芦屋", "FKO": "福岡", "KRT": "唐津", "OMR": "大村",
}


def pct(num: int, den: int) -> float:
    return 100.0 * num / den if den else 0.0


def load_avg_exhibition(place: str) -> float | None:
    with connect_db() as conn:
        with conn.cursor() as cur:
            cur.execute("SELECT avg_exhibition_time_6m FROM boat_race.exhibition_avg_6m WHERE stadium_name = %s LIMIT 1", (PLACE_NAMES[place],))
            row = cur.fetchone()
    return float(row[0]) if row and row[0] is not None and float(row[0]) > 0 else None


def load_exhibition(start: date, end: date, place: str) -> dict[str, list[dict]]:
    sql = """
SELECT DISTINCT ON (el.race_code, el.player_id)
    el.race_code, re.lane_number::integer AS lane, el.player_id::text,
    el.exhibition_time, el.start_timing, el.lap_time, el.around_time, el.straight_time
FROM boat_race.exhibition_live el
JOIN boat_race.race_master rm ON rm.race_code = el.race_code
JOIN boat_race.race_entry re ON re.race_code = el.race_code AND re.player_id = el.player_id
WHERE rm.race_date BETWEEN %s::date AND %s::date
  AND SUBSTRING(el.race_code, 9, 3) = %s
ORDER BY el.race_code, el.player_id, el.created_date DESC NULLS LAST
"""
    out = defaultdict(list)
    with connect_db() as conn:
        with conn.cursor() as cur:
            cur.execute(sql, (start, end, place))
            for code, lane, pid, ex, st, lap, mawari, straight in cur.fetchall():
                out[str(code)].append({
                    "lane": int(lane), "player_id": str(pid).strip(), "exhibition_time": ex,
                    "start_timing": st, "lap_time": lap, "around_time": mawari, "straight_time": straight,
                })
    return out


def blank() -> dict:
    return {"n": 0, "first": 0, "top2": 0, "top3": 0}


def add(stat: dict, course: int, first: int, second: int, third: int | None) -> None:
    stat["n"] += 1
    stat["first"] += first == course
    stat["top2"] += course in (first, second)
    stat["top3"] += course in (first, second, third)


def summary(stat: dict) -> dict:
    n = stat["n"]
    return {"n": n, "first": pct(stat["first"], n), "top2": pct(stat["top2"], n), "top3": pct(stat["top3"], n)}


def variants(row: dict, course: int):
    if course == 2:
        return [("sashi", row[2]["sashi"] >= 10.0), ("makuri", row[2]["makuri"] >= 5.0 and row["st21"] == "上")]
    return [("main", primary_match(row, course))]


def double_triple(course: int, second: dict, variant: str) -> tuple[bool, bool]:
    rank = int(second["second_rank"])
    score = float(second["final_2nd_score"])
    gap = float(second["gap_to_top"])
    lap = float(second["lap_score"])
    straight = float(second["straight_score"])
    mawari = float(second["mawari_score"])
    if course == 1:
        double = rank <= 3
        triple = rank == 1
    elif course == 2:
        double = rank <= 3 or lap >= 4.0 if variant == "sashi" else lap >= 4.0
        triple = rank == 1
    elif course == 3:
        double = score >= 30.0 and gap <= 2.0
        triple = double and straight >= 5.0 and mawari >= 4.0
    elif course == 4:
        double = score >= 24.0 and gap <= 5.0
        triple = double and score >= 27.0 and straight >= 4.0
    elif course == 5:
        double = rank <= 3 or lap >= 4.0
        triple = rank == 1
    else:
        double = rank <= 3
        triple = False
    return double, triple


def main() -> None:
    if len(sys.argv) not in (2, 3):
        raise SystemExit("Usage: python3 analysis/analyze_all_venue_secondary_signals.py END_DATE [PLACE_CODE]")
    end = parse_date(sys.argv[1])
    places = (sys.argv[2].strip().upper(),) if len(sys.argv) == 3 else PLACE_CODES
    if any(p not in PLACE_CODES for p in places):
        raise SystemExit(f"unknown place: {places}")
    start = months_ago(end, 24) + timedelta(days=1)

    for place in places:
        print(f"=== {place} {PLACE_NAMES[place]} ===", flush=True)
        races = load_targets(start, end, (place,))
        pids = sorted({b["player_id"] for r in races.values() for b in r["boats"]})
        racer = load_racer_results(required_terms(start, end))
        hist = TechniqueHistoryIndex(load_history(start, end, pids))
        avg_ex = load_avg_exhibition(place)
        ex_by_race = load_exhibition(start, end, place) if avg_ex is not None else {}
        all_stats = defaultdict(lambda: {"base": blank(), "double": blank(), "triple": blank()})
        blocks = defaultdict(lambda: {"base": blank(), "double": blank(), "triple": blank()})
        skips = Counter()

        for code, race in races.items():
            if len(race["boats"]) != 6 or code not in ex_by_race:
                skips["missing_race_data"] += 1
                continue
            by_course = {int(b["course"]): b for b in race["boats"] if b["course"] is not None}
            if set(by_course) != set(range(1, 7)):
                skips["bad_entry"] += 1
                continue
            second_map = build_second_scores(ex_by_race[code], avg_ex)
            if second_map is None:
                skips["bad_exhibition"] += 1
                continue
            race_date = race["date"]
            term = term_info_for_date(race_date)
            if any(racer.get((term, by_course[c]["player_id"])) is None or racer[(term, by_course[c]["player_id"])][c]["avg_rank"] is None for c in range(1, 7)):
                skips["missing_rank"] += 1
                continue
            ranks = {c: float(racer[(term, by_course[c]["player_id"])][c]["avg_rank"]) for c in range(1, 7)}
            row = {c: profile_rates(hist.profile(by_course[c]["player_id"], c, race_date, 12)) for c in range(1, 7)}
            row["st21"] = "上" if ranks[2] < ranks[1] else ("同じ" if ranks[2] == ranks[1] else "下")
            row["st43"] = "上" if ranks[4] < ranks[3] else ("同じ" if ranks[4] == ranks[3] else "下")
            row["st65"] = "上" if ranks[6] < ranks[5] else ("同じ" if ranks[6] == ranks[5] else "下")
            for course in range(1, 7):
                pid = by_course[course]["player_id"]
                second = second_map.get(pid)
                if second is None:
                    continue
                for variant, primary in variants(row, course):
                    if not primary:
                        continue
                    key = (course, variant)
                    add(all_stats[key]["base"], course, race["first"], race["second"], race["third"])
                    is_double, is_triple = double_triple(course, second, variant)
                    if is_double:
                        add(all_stats[key]["double"], course, race["first"], race["second"], race["third"])
                    if is_triple:
                        add(all_stats[key]["triple"], course, race["first"], race["second"], race["third"])
                    for label, st, en in windows(end):
                        if not st <= race_date <= en:
                            continue
                        bst = blocks[(label, course, variant)]
                        add(bst["base"], course, race["first"], race["second"], race["third"])
                        if is_double:
                            add(bst["double"], course, race["first"], race["second"], race["third"])
                        if is_triple:
                            add(bst["triple"], course, race["first"], race["second"], race["third"])

        result = {"place": place, "period": f"{start}～{end}", "courses": {}, "skips": dict(skips)}
        for (course, variant), values in sorted(all_stats.items()):
            base = summary(values["base"])
            item = {"base": base}
            for level in ("double", "triple"):
                s = summary(values[level])
                stable = 0
                for label, _, _ in windows(end):
                    b = summary(blocks[(label, course, variant)]["base"])
                    q = summary(blocks[(label, course, variant)][level])
                    if q["n"] >= 20 and q["top3"] >= b["top3"]:
                        stable += 1
                item[level] = {**s, "delta_top3": round(s["top3"] - base["top3"], 2), "stability_blocks": stable}
            result["courses"][f"{course}_{variant}"] = item
            print(f"{place} {course}C {variant} baseN={base['n']:4d} ★★N={item['double']['n']:4d} Δ3={item['double']['delta_top3']:+6.2f} 安定{item['double']['stability_blocks']}/4 ★★★N={item['triple']['n']:4d} Δ3={item['triple']['delta_top3']:+6.2f} 安定{item['triple']['stability_blocks']}/4", flush=True)
        out = Path(__file__).resolve().parent / "output" / f"all_venue_secondary_{place}_{end:%Y%m%d}.json"
        out.write_text(json.dumps(result, ensure_ascii=False, indent=2, default=str) + "\n", encoding="utf-8")
        print(f"CSV-like JSON: {out} / races={len(races)} ex={len(ex_by_race)} skips={dict(skips)}", flush=True)


if __name__ == "__main__":
    main()
