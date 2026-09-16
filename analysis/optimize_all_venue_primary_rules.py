#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""場別の一次★条件を精度優先で候補閾値から探索する。

多摩川条件を固定適用するのではなく、各場・各コースについて候補閾値を比較する。
過去18か月で候補を選び、直近6か月を未使用ホールドアウトとして確認する。
短期の偶然や表示乱立を避けるため、1着率・3連対率の改善幅を従来より厳しくする。
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
    TechniqueHistoryIndex,
    load_history,
    load_racer_results,
    months_ago,
    parse_date,
    required_terms,
    term_info_for_date,
)

PLACE_NAMES = {
    "KRY": "桐生", "TDA": "戸田", "EDG": "江戸川", "HWJ": "平和島", "TMG": "多摩川", "HMN": "浜名湖",
    "GMG": "蒲郡", "TKN": "常滑", "TSU": "津", "MKN": "三国", "BWK": "びわこ", "SME": "住之江",
    "AMG": "尼崎", "NRT": "鳴門", "MRG": "丸亀", "KJM": "児島", "MYJ": "宮島", "TKY": "徳山",
    "SMS": "下関", "WKM": "若松", "ASY": "芦屋", "FKO": "福岡", "KRT": "唐津", "OMR": "大村",
}

GRIDS = {
    "1_main": [("nige_min", x) for x in (50.0, 55.0, 60.0, 65.0)],
    "2_sashi": [("sashi_min", x) for x in (8.0, 10.0, 12.0, 15.0)],
    "2_makuri": [("makuri_min", x) for x in (4.0, 5.0, 6.0, 8.0)],
    "3_main": [("attack_min", x) for x in (10.0, 12.0, 15.0, 18.0, 20.0)],
    "4_main": [("makuri_min", x) for x in (10.0, 12.0, 15.0, 18.0, 20.0)],
    "5_main": [("attack_min", x) for x in (7.0, 10.0, 12.0, 15.0)],
    "6_main": [("attack_min", x) for x in (3.0, 5.0, 7.0, 10.0)],
}
# 今回は全コースを同じ精度基準で見直す。6Cも攻め率＋5CとのST順位を
# 例外扱いせず、過去18か月の期間安定性と直近ホールドアウトで判定する。
REVIEW_COURSES = {1, 2, 3, 4, 5, 6}

# 安定しているだけでなく、無条件時からの3連対率改善が実戦上も確認できる最低幅。
MIN_DELTA_TOP3 = 5.0
MIN_DELTA_FIRST = 3.0
MIN_RECENT_N = 10
MIN_HOLDOUT_N = 30
MIN_BLOCK_N = 20


def pct(num: int, den: int) -> float:
    return 100.0 * num / den if den else 0.0


def blank() -> dict:
    return {"n": 0, "first": 0, "top2": 0, "top3": 0}


def add(stat: dict, course: int, row: dict) -> None:
    stat["n"] += 1
    stat["first"] += row["first"] == course
    stat["top2"] += course in (row["first"], row["second"])
    stat["top3"] += course in (row["first"], row["second"], row["third"])


def summary(stat: dict) -> dict:
    n = stat["n"]
    return {"n": n, "first": pct(stat["first"], n), "top2": pct(stat["top2"], n), "top3": pct(stat["top3"], n)}


def matches(row: dict, key: str, value: float) -> bool:
    course = int(key.split("_")[0])
    variant = key.split("_", 1)[1]
    p = row["profiles"][course]
    if key == "1_main":
        return p["nige"] >= value
    if key == "2_sashi":
        return p["sashi"] >= value
    if key == "2_makuri":
        return p["makuri"] >= value and row["st21"] == "上"
    if key == "3_main":
        return p["attack"] >= value
    if key == "4_main":
        return p["makuri"] >= value and row["st43"] == "上"
    if key == "5_main":
        return p["attack"] >= value
    if key == "6_main":
        return p["attack"] >= value and row["st65"] == "上"
    raise ValueError((course, variant))


def evaluate(rows: list[dict], key: str, value: float, start: date | None = None, end: date | None = None) -> dict:
    course = int(key.split("_")[0])
    stat = blank()
    for row in rows:
        if start is not None and not start <= row["date"] <= end:
            continue
        if row["profiles"][course]["n"] > 0 and matches(row, key, value):
            add(stat, course, row)
    return summary(stat)


def load_feature_rows(place: str, start: date, end: date) -> list[dict]:
    races = load_targets(start, end, (place,))
    pids = sorted({b["player_id"] for r in races.values() for b in r["boats"]})
    racer = load_racer_results(required_terms(start, end))
    hist = TechniqueHistoryIndex(load_history(start, end, pids))
    out = []
    for code, race in races.items():
        if len(race["boats"]) != 6:
            continue
        by_course = {int(b["course"]): b for b in race["boats"] if b["course"] is not None}
        if set(by_course) != set(range(1, 7)):
            continue
        term = term_info_for_date(race["date"])
        ranks = {}
        bad = False
        for c in range(1, 7):
            rr = racer.get((term, by_course[c]["player_id"]))
            if rr is None or rr[c]["avg_rank"] is None:
                bad = True
                break
            ranks[c] = float(rr[c]["avg_rank"])
        if bad:
            continue
        out.append({
            "date": race["date"], "race_code": code, "first": race["first"], "second": race["second"], "third": race["third"],
            "profiles": {c: profile_rates(hist.profile(by_course[c]["player_id"], c, race["date"], 12)) for c in range(1, 7)},
            "st21": "上" if ranks[2] < ranks[1] else ("同じ" if ranks[2] == ranks[1] else "下"),
            "st43": "上" if ranks[4] < ranks[3] else ("同じ" if ranks[4] == ranks[3] else "下"),
            "st65": "上" if ranks[6] < ranks[5] else ("同じ" if ranks[6] == ranks[5] else "下"),
        })
    return out


def choose(rows: list[dict], key: str, end: date) -> dict:
    course = int(key.split("_")[0])
    base_stat = blank()
    for row in rows:
        if row["profiles"][course]["n"] > 0:
            add(base_stat, course, row)
    base = summary(base_stat)
    blocks = list(windows(end))
    recent_rows = sorted(rows, key=lambda r: (r["date"], r.get("race_code", "")), reverse=True)[:120]
    recent_base = summary_stat(recent_rows, course)
    candidates = []
    for _, value in GRIDS[key]:
        overall = evaluate(rows, key, value)
        if overall["n"] < 100:
            continue
        train_deltas = []
        train_first_deltas = []
        train_ns = []
        for label, st, en in blocks[1:]:
            s = evaluate(rows, key, value, st, en)
            b = summary_stat(rows, course, st, en)
            train_deltas.append(s["top3"] - b["top3"])
            train_first_deltas.append(s["first"] - b["first"])
            train_ns.append(s["n"])
        hold = evaluate(rows, key, value, blocks[0][1], blocks[0][2])
        hold_base = summary_stat(rows, course, blocks[0][1], blocks[0][2])
        hold_delta = hold["top3"] - hold_base["top3"]
        hold_first_delta = hold["first"] - hold_base["first"]
        recent = evaluate(recent_rows, key, value)
        if recent["n"] < MIN_RECENT_N:
            continue
        if hold["n"] < MIN_HOLDOUT_N or any(n < MIN_BLOCK_N for n in train_ns):
            continue
        if hold_delta < MIN_DELTA_TOP3 or min(train_deltas) < MIN_DELTA_TOP3:
            continue
        if hold_first_delta < MIN_DELTA_FIRST or min(train_first_deltas) < MIN_DELTA_FIRST:
            continue
        # ホールドアウトは採否確認だけに使い、候補順位は過去18か月の訓練期間で決める。
        train_first_rates = []
        for _, st, en in blocks[1:]:
            train_first_rates.append(evaluate(rows, key, value, st, en)["first"])
        score = min(train_first_rates) + 0.1 * min(train_deltas)
        candidates.append((score, value, overall, hold, recent, train_deltas, hold_delta, train_first_deltas, hold_first_delta))
    if not candidates:
        return {"enabled": False, "reason": "no_stable_candidate", "baseline": base}
    score, value, overall, hold, recent, train_deltas, hold_delta, train_first_deltas, hold_first_delta = max(candidates, key=lambda item: (item[0], item[2]["n"]))
    return {
        "enabled": True, "value": value, "overall": overall, "baseline": base,
        "recent120": recent, "recent120_baseline": recent_base,
        "holdout": hold, "holdout_delta_top3": round(hold_delta, 2),
        "train_delta_top3": [round(x, 2) for x in train_deltas],
        "holdout_delta_first": round(hold_first_delta, 2),
        "train_delta_first": [round(x, 2) for x in train_first_deltas], "score": round(score, 2),
    }


def summary_stat(rows: list[dict], course: int, start: date | None = None, end: date | None = None) -> dict:
    stat = blank()
    for row in rows:
        if (start is None or start <= row["date"] <= end) and row["profiles"][course]["n"] > 0:
            add(stat, course, row)
    return summary(stat)


def main() -> None:
    if len(sys.argv) not in (2, 3):
        raise SystemExit("Usage: python3 analysis/optimize_all_venue_primary_rules.py END_DATE [PLACE_CODE]")
    end = parse_date(sys.argv[1])
    places = (sys.argv[2].upper(),) if len(sys.argv) == 3 else PLACE_CODES
    start = months_ago(end, 24) + timedelta(days=1)
    result = {"version": 1, "period": f"{start}～{end}", "places": {}}
    for place in places:
        if place not in PLACE_CODES:
            raise SystemExit(f"unknown place: {place}")
        rows = load_feature_rows(place, start, end)
        print(f"=== {place} {PLACE_NAMES[place]} rows={len(rows)} ===", flush=True)
        result["places"][place] = {}
        for key in GRIDS:
            if int(key.split("_")[0]) not in REVIEW_COURSES:
                old_path = Path(__file__).resolve().parent / "output" / f"all_venue_primary_optimized_{place}_{end:%Y%m%d}.json"
                old = json.loads(old_path.read_text(encoding="utf-8")) if old_path.exists() else {}
                result["places"][place][key] = old.get("places", {}).get(place, {}).get(key) or {"enabled": False, "reason": "not_reviewed"}
                continue
            chosen = choose(rows, key, end)
            result["places"][place][key] = chosen
            if chosen.get("enabled"):
                print(f"{place} {key} value={chosen['value']:.1f} N={chosen['overall']['n']} Δ3={chosen['overall']['top3']-chosen['baseline']['top3']:+.2f} holdoutΔ3={chosen['holdout_delta_top3']:+.2f}", flush=True)
            else:
                print(f"{place} {key} DISABLED ({chosen.get('reason')})", flush=True)
    suffix = places[0] if len(places) == 1 else "ALL"
    out = Path(__file__).resolve().parent / "output" / f"all_venue_primary_optimized_{suffix}_{end:%Y%m%d}.json"
    out.write_text(json.dumps(result, ensure_ascii=False, indent=2, default=str) + "\n", encoding="utf-8")
    print(f"wrote {out}")


if __name__ == "__main__":
    main()
