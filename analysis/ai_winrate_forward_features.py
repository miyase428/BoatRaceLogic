#!/usr/bin/env python3
"""Build the fixed post-exhibition 170 features for forward inference.

Target-race outcomes are never queried.  Technique/vulnerability histories are
loaded only through the day before ``target_date``.
"""

from __future__ import annotations

import sys
from datetime import date, timedelta
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "analysis"))
sys.path.insert(0, str(ROOT / "forecast"))

import tamagawa_center_signal_live_v1 as live
from analyze_all_venue_lane_signals import profile_rates
from analyze_all_venue_secondary_signals import load_avg_exhibition, load_exhibition
from analyze_tamagawa_boaters_hypothesis import TechniqueHistoryIndex, load_history, load_racer_results, term_info_for_date
from analyze_tamagawa_lane4_makurizashi_vulnerability import VulnerabilityIndex, load_lane1_vulnerability_history
from analyze_tamagawa_lane4_strong_condition_second_eval import build_second_scores
from audit_tamagawa_course_signals_zero_base_ml import PROFILE_KEYS, RAW_EXHIBITION_KEYS, SECOND_KEYS, relative_values, safe_float

PLACES = ("ASY", "AMG")


def build_forward_feature_rows(target_date: date, place: str, feature_names: list[str]) -> dict[str, list[dict]]:
    if place not in PLACES:
        raise ValueError(f"forward対象外の場です: {place}")
    if len(feature_names) != 170:
        raise ValueError(f"固定特徴数が170ではありません: {len(feature_names)}")

    live.PLACE = place
    races = live.load_entries(target_date)
    if not races:
        return {}
    player_ids = sorted({boat["player_id"] for race in races.values() for boat in race["boats"]})
    history_end = target_date - timedelta(days=1)
    history = TechniqueHistoryIndex(load_history(target_date, history_end, player_ids))
    vulnerability = VulnerabilityIndex(load_lane1_vulnerability_history(target_date, history_end, player_ids))
    racer = load_racer_results([term_info_for_date(target_date)])
    exhibition_by_race = load_exhibition(target_date, target_date, place)
    average_exhibition = load_avg_exhibition(place)
    term = term_info_for_date(target_date)
    output: dict[str, list[dict]] = {}

    for race_code, race in sorted(races.items()):
        boats = race["boats"]
        by_course = {int(boat["course"]): boat for boat in boats if 1 <= int(boat["course"] or 0) <= 6}
        if len(boats) != 6 or set(by_course) != set(range(1, 7)):
            continue
        profiles = {c: profile_rates(history.profile(by_course[c]["player_id"], c, target_date, 12)) for c in range(1, 7)}
        lane1 = vulnerability.profile(by_course[1]["player_id"], target_date, 12)
        shared: dict[str, float] = {
            "lane1_vulnerability_n": float(lane1["n"]),
            "lane1_vulnerability_rate": 100.0 * (lane1["makurare"] + lane1["makurarezashi"]) / lane1["n"] if lane1["n"] else float("nan"),
        }
        for course in range(1, 7):
            boat = by_course[course]
            pid = boat["player_id"]
            shared[f"c{course}_history_n"] = float(profiles[course]["n"])
            for key in PROFILE_KEYS:
                shared[f"c{course}_{key}"] = float(profiles[course][key])
            rr = racer.get((term, pid), {}).get(course, {})
            shared[f"c{course}_st_rank"] = safe_float(rr.get("avg_rank"))
            for key in ("national_win_rate", "national_exacta_rate", "local_win_rate", "local_exacta_rate", "motor_exacta_rate", "boat_exacta_rate", "average_start"):
                shared[f"c{course}_{key}"] = safe_float(boat.get(key))

        exhibition_rows = exhibition_by_race.get(race_code, [])
        second_map = build_second_scores(exhibition_rows, average_exhibition) if average_exhibition is not None and len(exhibition_rows) == 6 else None
        if second_map is None:
            continue
        relative = {key: relative_values(exhibition_rows, key) for key in RAW_EXHIBITION_KEYS}
        ready = True
        for course in range(1, 7):
            pid = by_course[course]["player_id"]
            second = second_map.get(pid)
            if second is None:
                ready = False
                break
            for key in RAW_EXHIBITION_KEYS:
                shared[f"c{course}_{key}_relative"] = relative[key].get(pid, float("nan"))
            for key in SECOND_KEYS:
                shared[f"c{course}_{key}"] = safe_float(second.get(key))
        if not ready:
            continue
        values = [shared.get(name, float("nan")) for name in feature_names]
        output[race_code] = [{
            "race_code": race_code,
            "place": place,
            "race_number": int(race_code[-2:]),
            "boat_number": int(by_course[c]["lane_number"]),
            "player_id": by_course[c]["player_id"],
            "entry_course": c,
            "features": values,
        } for c in range(1, 7)]
    return output
