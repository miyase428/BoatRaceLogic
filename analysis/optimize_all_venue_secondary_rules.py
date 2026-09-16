#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""場別の★★／★★★（二次評価）条件を候補グリッドから探索する。

一次★は現行の場別最適閾値を適用し、直近6か月をホールドアウトとして
過去3ブロックとの安定性を確認する。出現数を増やすことよりも、各期間で
1着率・3連対率が基準を明確に上回る条件を優先する。結果の ``params`` は
設定生成器が画面APIへ引き渡す。
"""

from __future__ import annotations

import json
import sys
from collections import defaultdict
from datetime import date, timedelta
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from analyze_all_venue_lane_signals import PLACE_CODES, load_targets, profile_rates, windows  # noqa: E402
from analyze_tamagawa_boaters_hypothesis import (  # noqa: E402
    TechniqueHistoryIndex, load_history, load_racer_results, months_ago,
    parse_date, required_terms, term_info_for_date,
)
from analyze_all_venue_secondary_signals import (  # noqa: E402
    build_second_scores, connect_db, load_avg_exhibition, load_exhibition,
)
from analyze_tamagawa_lane4_makurizashi_vulnerability import (  # noqa: E402
    VulnerabilityIndex, load_lane1_vulnerability_history,
)

# 精度優先: 各6か月ブロックとホールドアウトで、3連対率だけでなく
# 1着率も明確に改善している候補だけを採用する。
MIN_DELTA_TOP3 = 5.0
MIN_DELTA_FIRST = 3.0
GRIDS = {
    "1_main_double": [("rank_max", x) for x in (1, 2, 3, 4)],
    "1_main_triple": [("rank_max", 1)],
    "2_sashi_double": [("rank_max", x) for x in (2, 3, 4)] + [("lap_min", x) for x in (3.5, 4.0, 4.5)],
    "2_sashi_triple": [("rank_max", 1)],
    "2_makuri_double": [("rank_max", x) for x in (2, 3, 4)] + [("lap_min", x) for x in (3.5, 4.0, 4.5)],
    "2_makuri_triple": [("rank_max", 1)],
    "3_main_double": [("score_min", s, "gap_max", g) for s in (28.0, 30.0, 32.0) for g in (2.0, 3.0, 4.0)],
    "3_main_triple": [("score_min", s, "gap_max", g, "straight_min", t, "mawari_min", m)
                       for s in (28.0, 30.0) for g in (2.0, 3.0) for t in (4.0, 5.0) for m in (3.0, 4.0, 5.0)],
    "4_main_double": [("score_min", s, "gap_max", g) for s in (22.0, 24.0, 26.0) for g in (4.0, 5.0, 6.0)],
    "4_main_triple": [("score_min", s, "gap_max", g, "straight_min", t)
                       for s in (26.0, 27.0, 28.0) for g in (4.0, 5.0) for t in (3.0, 4.0, 5.0)],
    "5_main_double": [("rank_max", x) for x in (2, 3, 4)] + [("lap_min", x) for x in (3.5, 4.0, 4.5)],
    "5_main_triple": [("rank_max", 1)],
    "6_main_double": [("rank_max", x) for x in (2, 3, 4)],
}


def pct(n: int, d: int) -> float:
    return 100.0 * n / d if d else 0.0


def blank() -> dict:
    return {"n": 0, "first": 0, "top2": 0, "top3": 0}


def add(s: dict, course: int, row: dict) -> None:
    s["n"] += 1
    s["first"] += row["first"] == course
    s["top2"] += course in (row["first"], row["second_course"])
    s["top3"] += course in (row["first"], row["second_course"], row["third_course"])


def summary(s: dict) -> dict:
    return {"n": s["n"], "first": pct(s["first"], s["n"]), "top2": pct(s["top2"], s["n"]), "top3": pct(s["top3"], s["n"])}


def primary_enabled(place: str, course: int, variant: str, config: dict) -> bool:
    rule = config.get("places", {}).get(place, {}).get(str(course), {})
    return bool(rule.get("primary_rules", {}).get(variant, {}).get("enabled", False))


def primary_threshold(place: str, course: int, variant: str, config: dict) -> float:
    rule = config.get("places", {}).get(place, {}).get(str(course), {})
    value = rule.get("primary_rules", {}).get(variant, {}).get("threshold")
    if isinstance(value, (int, float)):
        return float(value)
    return {(1, "main"): 55.0, (2, "sashi"): 10.0, (2, "makuri"): 5.0,
            (3, "main"): 15.0, (4, "main"): 15.0, (5, "main"): 10.0,
            (6, "main"): 5.0}.get((course, variant), 0.0)


def configured_rule(place: str, course: int, variant: str, config: dict) -> dict:
    return (config.get("places", {}).get(place, {}).get(str(course), {})
            .get("primary_rules", {}).get(variant, {}))


def primary_st_relation(place: str, course: int, variant: str, config: dict) -> str:
    relation = configured_rule(place, course, variant, config).get("st_relation")
    return str(relation) if relation in {"up", "same_or_better"} else "up"


def relation_matches(outer_rank: float, inner_rank: float, relation: str) -> bool:
    return outer_rank <= inner_rank if relation == "same_or_better" else outer_rank < inner_rank


def primary_match(row: dict, place: str, course: int, variant: str, config: dict) -> bool:
    if not primary_enabled(place, course, variant, config):
        return False
    p = row["profiles"][course]
    threshold = primary_threshold(place, course, variant, config)
    if course == 1:
        return p["nige"] >= threshold
    if course == 2 and variant == "sashi":
        return p["sashi"] >= threshold
    if course == 2 and variant == "makuri":
        if p["makuri"] < threshold:
            return False
        if not relation_matches(row["st_rank"][2], row["st_rank"][1], primary_st_relation(place, course, variant, config)):
            return False
        vuln = configured_rule(place, course, variant, config).get("vulnerability_threshold")
        return vuln is None or (row.get("lane1_vulnerability_rate") is not None and row["lane1_vulnerability_rate"] >= float(vuln))
    if course == 3 or course == 5:
        return p["attack"] >= threshold
    if course == 4:
        rule = configured_rule(place, course, variant, config)
        metric = p.get("attack" if rule.get("parameter") == "attack_rate" else "makuri", 0.0)
        if metric < threshold or not relation_matches(row["st_rank"][4], row["st_rank"][3], primary_st_relation(place, course, variant, config)):
            return False
        vuln = rule.get("vulnerability_threshold")
        return vuln is None or (row.get("lane1_vulnerability_rate") is not None and row["lane1_vulnerability_rate"] >= float(vuln))
    if course == 6:
        return p["attack"] >= threshold and relation_matches(row["st_rank"][6], row["st_rank"][5], primary_st_relation(place, course, variant, config))
    return False


def matches(second: dict, params: tuple) -> bool:
    d = dict(zip(params[::2], params[1::2]))
    rank, score, gap = int(second["second_rank"]), float(second["final_2nd_score"]), float(second["gap_to_top"])
    lap, straight, mawari = float(second["lap_score"]), float(second["straight_score"]), float(second["mawari_score"])
    if "rank_max" in d and rank > d["rank_max"]:
        return False
    if "lap_min" in d and lap < d["lap_min"]:
        return False
    if "score_min" in d and score < d["score_min"]:
        return False
    if "gap_max" in d and gap > d["gap_max"]:
        return False
    if "straight_min" in d and straight < d["straight_min"]:
        return False
    if "mawari_min" in d and mawari < d["mawari_min"]:
        return False
    return True


def evaluate(rows: list[dict], course: int, params: tuple, start: date | None = None, end: date | None = None) -> dict:
    s = blank()
    for row in rows:
        if start is not None and not start <= row["date"] <= end:
            continue
        if row["course"] == course and matches(row["second"], params):
            add(s, course, row)
    return summary(s)


def choose(rows: list[dict], key: str, end: date) -> dict:
    course = int(key.split("_")[0])
    level = key.rsplit("_", 1)[1]
    base = summary_stat(rows, course)
    min_n = 100 if level == "double" else 50
    candidates = []
    blocks = list(windows(end))
    for params in GRIDS[key]:
        overall = evaluate(rows, course, params)
        if overall["n"] < min_n:
            continue
        train = []
        valid = True
        for _, st, en in blocks[1:]:
            q = evaluate(rows, course, params, st, en)
            b = summary_stat(rows, course, st, en)
            if (q["n"] < (20 if level == "double" else 10)
                    or q["top3"] - b["top3"] < MIN_DELTA_TOP3
                    or q["first"] - b["first"] < MIN_DELTA_FIRST):
                valid = False
            train.append(q["top3"] - b["top3"])
        hold = evaluate(rows, course, params, blocks[0][1], blocks[0][2])
        hold_base = summary_stat(rows, course, blocks[0][1], blocks[0][2])
        hold_delta = hold["top3"] - hold_base["top3"]
        hold_delta_first = hold["first"] - hold_base["first"]
        if (hold["n"] < (20 if level == "double" else 10)
                or hold_delta < MIN_DELTA_TOP3
                or hold_delta_first < MIN_DELTA_FIRST):
            valid = False
        if valid:
            train_first = []
            for _, st, en in blocks[1:]:
                q = evaluate(rows, course, params, st, en)
                b = summary_stat(rows, course, st, en)
                train_first.append(q["first"] - b["first"])
            score = min(train_first) + 0.1 * min(train) + 0.05 * (overall["top3"] - base["top3"])
            candidates.append((score, params, overall, hold, train, train_first, hold_delta, hold_delta_first))
    if not candidates:
        return {"enabled": False, "reason": "no_stable_candidate", "baseline": base}
    score, params, overall, hold, train, train_first, hold_delta, hold_delta_first = max(candidates, key=lambda x: (x[0], x[2]["n"]))
    return {"enabled": True, "params": list(params), "overall": overall, "baseline": base,
            "holdout": hold, "holdout_delta_top3": round(hold_delta, 2),
            "holdout_delta_first": round(hold_delta_first, 2),
            "train_delta_top3": [round(x, 2) for x in train],
            "train_delta_first": [round(x, 2) for x in train_first], "score": round(score, 2)}


def summary_stat(rows: list[dict], course: int, start: date | None = None, end: date | None = None) -> dict:
    s = blank()
    for row in rows:
        if row["course"] == course and (start is None or start <= row["date"] <= end):
            add(s, course, row)
    return summary(s)


def load_rows(place: str, start: date, end: date, config: dict) -> list[dict]:
    races = load_targets(start, end, (place,))
    pids = sorted({b["player_id"] for r in races.values() for b in r["boats"]})
    racer = load_racer_results(required_terms(start, end))
    hist = TechniqueHistoryIndex(load_history(start, end, pids))
    vuln = VulnerabilityIndex(load_lane1_vulnerability_history(start, end, pids))
    avg_ex = load_avg_exhibition(place)
    ex_by_race = load_exhibition(start, end, place) if avg_ex is not None else {}
    rows = []
    for code, race in races.items():
        if len(race["boats"]) != 6 or code not in ex_by_race:
            continue
        by_course = {int(b["course"]): b for b in race["boats"] if b["course"] is not None}
        if set(by_course) != set(range(1, 7)):
            continue
        second_map = build_second_scores(ex_by_race[code], avg_ex)
        if second_map is None:
            continue
        term = term_info_for_date(race["date"])
        ranks = {}
        if any((rr := racer.get((term, by_course[c]["player_id"]))) is None or rr[c]["avg_rank"] is None for c in range(1, 7)):
            continue
        for c in range(1, 7):
            ranks[c] = float(racer[(term, by_course[c]["player_id"])][c]["avg_rank"])
        base = {"date": race["date"], "race_code": code, "first": race["first"], "second_course": race["second"], "third_course": race["third"],
                "profiles": {c: profile_rates(hist.profile(by_course[c]["player_id"], c, race["date"], 12)) for c in range(1, 7)},
                "st_rank": ranks,
                "st21": "上" if ranks[2] < ranks[1] else ("同じ" if ranks[2] == ranks[1] else "下"),
                "st43": "上" if ranks[4] < ranks[3] else ("同じ" if ranks[4] == ranks[3] else "下"),
                "st65": "上" if ranks[6] < ranks[5] else ("同じ" if ranks[6] == ranks[5] else "下"),
                "lane1_vulnerability_rate": None}
        p1 = vuln.profile(by_course[1]["player_id"], race["date"], 12)
        if p1["n"]:
            base["lane1_vulnerability_rate"] = 100.0 * (p1["makurare"] + p1["makurarezashi"]) / p1["n"]
        for c in range(1, 7):
            for variant in (("sashi", "makuri") if c == 2 else ("main",)):
                if primary_match(base, place, c, variant, config):
                    second = second_map.get(by_course[c]["player_id"])
                    if second is not None:
                        rows.append({**base, "course": c, "variant": variant, "second": second})
    return rows


def main() -> None:
    if len(sys.argv) not in (2, 3):
        raise SystemExit("Usage: python3 analysis/optimize_all_venue_secondary_rules.py END_DATE [PLACE_CODE]")
    end = parse_date(sys.argv[1])
    places = (sys.argv[2].upper(),) if len(sys.argv) == 3 else PLACE_CODES
    config = json.loads((Path(__file__).resolve().parents[1] / "config/course_signal_rules.json").read_text(encoding="utf-8"))
    start = months_ago(end, 24) + timedelta(days=1)
    result = {"version": 1, "period": f"{start}～{end}", "places": {}}
    for place in places:
        rows = load_rows(place, start, end, config)
        result["places"][place] = {}
        for key, grid in GRIDS.items():
            subset = [r for r in rows if r["variant"] == key.split("_", 2)[1] and r["course"] == int(key.split("_")[0])]
            chosen = choose(subset, key, end)
            result["places"][place][key] = chosen
            print(place, key, "enabled" if chosen.get("enabled") else "DISABLED", chosen.get("params", chosen.get("reason")), flush=True)
    suffix = places[0] if len(places) == 1 else "ALL"
    out = Path(__file__).resolve().parent / "output" / f"all_venue_secondary_optimized_{suffix}_{end:%Y%m%d}.json"
    out.write_text(json.dumps(result, ensure_ascii=False, indent=2, default=str) + "\n", encoding="utf-8")
    print(f"wrote {out}")


if __name__ == "__main__":
    main()
