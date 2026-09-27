#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""Web/App用 AI展開予想 v1。JSONを標準入力で受け取る。"""

from __future__ import annotations

import json
import sys
from collections import Counter, defaultdict
from datetime import date
from pathlib import Path

import joblib
import psycopg2

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "analysis"))
sys.path.insert(0, str(ROOT / "common"))

import ai_tenkai_model_tournament_2026 as core  # noqa: E402
from db_config import load_db_config  # noqa: E402

MODEL_PATH = Path(__file__).resolve().parent / "models" / "ai_tenkai_v1.joblib"


VENUE_SQL = """
SELECT
    rrd.entry_course::integer AS course,
    TRIM(COALESCE(rrd.technique, '')) AS technique,
    COUNT(*)::integer AS n
FROM boat_race.race_result_detail rrd
JOIN boat_race.race_master rm
  ON rm.race_code = rrd.race_code
WHERE TRIM(rrd.rank) = '1'
  AND rrd.entry_course BETWEEN 1 AND 6
  AND rm.race_date >= %(target_date)s::date - INTERVAL '12 months'
  AND rm.race_date < %(target_date)s::date
  AND SUBSTRING(rm.race_code FROM 9 FOR 3) = %(place)s
GROUP BY rrd.entry_course, TRIM(COALESCE(rrd.technique, ''))
"""


def payload_row(values: dict, key: int) -> dict:
    row = values.get(str(key), values.get(key, {}))
    return row if isinstance(row, dict) else {}


def counts_from_period(period: dict, course: int) -> tuple[int, Counter[str]]:
    raw = period.get("_counts", {}) if isinstance(period, dict) else {}
    raw = raw if isinstance(raw, dict) else {}
    wins = max(0, int(raw.get("win", 0) or 0))
    counts: Counter[str] = Counter()
    if course == 1:
        counts["nige"] = max(0, min(wins, int(raw.get("nige", 0) or 0)))
        counts["other"] = max(0, wins - counts["nige"])
    else:
        for source, target in (
            ("sashi", "sashi"),
            ("makuri", "makuri"),
            ("makurizashi", "makurizashi"),
        ):
            counts[target] = max(0, int(raw.get(source, 0) or 0))
        known = min(wins, sum(counts.values()))
        counts["other"] = max(0, wins - known)
    return wins, counts


def history_from_payload(kimarite: dict, course: int) -> dict:
    root = payload_row(kimarite, course)
    six = root.get("6month", {}) if isinstance(root.get("6month", {}), dict) else {}
    year = root.get("1year", {}) if isinstance(root.get("1year", {}), dict) else {}
    wins6, counts6 = counts_from_period(six, course)
    wins12, counts12 = counts_from_period(year, course)
    return {
        "starts6": max(0, int(six.get("_sample_n", 0) or 0)),
        "starts12": max(0, int(year.get("_sample_n", 0) or 0)),
        "wins6": wins6,
        "wins12": wins12,
        "counts6": counts6,
        "counts12": counts12,
    }


def load_venue(target_date: date, place: str) -> dict:
    by_course: dict[int, Counter[str]] = defaultdict(Counter)
    total = 0
    with psycopg2.connect(**load_db_config()) as conn:
        with conn.cursor() as cursor:
            cursor.execute(VENUE_SQL, {"target_date": target_date, "place": place})
            for course, technique, count in cursor.fetchall():
                course = int(course)
                count = int(count)
                by_course[course][core.canonical_method(course, str(technique))] += count
                total += count
    return {"total": total, "courses": by_course}


def parse_payload(payload: dict, artifact: dict) -> tuple[str, dict]:
    race_code = str(payload.get("race_code", "")).strip().upper()
    if len(race_code) != 13 or not race_code[:8].isdigit():
        raise RuntimeError("AI展開予想: race_codeが不正です")
    ai_win = payload.get("ai_win_boats") or {}
    course_by_boat = payload.get("course_by_boat") or {}
    kimarite = payload.get("kimarite_data") or {}
    predictions: dict[tuple[str, int], float] = {}
    requested_courses: dict[int, int] = {}
    for boat in range(1, 7):
        win_row = payload_row(ai_win, boat)
        course = course_by_boat.get(str(boat), course_by_boat.get(boat, win_row.get("course")))
        if course is None or win_row.get("ai_rate") is None:
            raise RuntimeError("AI展開予想: AI1着率・進入が6艇分ありません")
        requested_courses[boat] = int(course)
        predictions[(race_code, int(course))] = float(win_row["ai_rate"]) / 100.0
    if set(requested_courses.values()) != set(range(1, 7)):
        raise RuntimeError("AI展開予想: 進入コースが不完全です")

    raw_rows = []
    for original in core.load_preview_rows(race_code):
        row = list(original)
        row[6] = requested_courses[int(row[4])]
        raw_rows.append(tuple(row))
    built = core.build_race_rows(raw_rows, predictions, require_winner=False)
    if race_code not in built:
        raise RuntimeError("AI展開予想: 展示情報が6艇分ありません")
    race = built[race_code]
    venue = load_venue(race["race_date"], race["place"])
    aligned = {boat["course"]: boat for boat in race["boats"]}
    for boat in race["boats"]:
        course = int(boat["course"])
        history = history_from_payload(kimarite, course)
        prior, venue_wins = core.venue_distribution(venue, course)
        boat["history"] = history
        boat["venue_prior"] = prior
        boat["venue_course_wins"] = venue_wins
        boat["venue_races"] = int(venue["total"])
        boat["current_conditional"] = core.conditional_distribution(
            history,
            prior,
            course,
            core.CURRENT_RECENT_WEIGHT,
            core.CURRENT_PRIOR_K,
        )
        boat["aligned"] = aligned
    core.PLACE_CODES = tuple(artifact["place_codes"])
    if race["place"] not in core.PLACE_CODES:
        raise RuntimeError("AI展開予想: 未学習の競艇場です")
    return race_code, race


def calculate(payload: dict) -> dict:
    if not MODEL_PATH.is_file():
        return {"status": "waiting", "error": "AI展開予想 v1モデルが未学習です"}
    artifact = joblib.load(MODEL_PATH)
    if artifact.get("version") != "ai_tenkai_v1":
        raise RuntimeError("AI展開予想モデルの形式が一致しません")
    race_code, race = parse_payload(payload, artifact)
    models = {"inner": artifact["inner"], "outer": artifact["outer"]}
    core.attach_ml_predictions([race], models)
    events = core.event_distribution(race, "ml", models)
    by_course = {int(boat["course"]): boat for boat in race["boats"]}
    all_events = []
    for (course, method), probability in events.items():
        boat = by_course[course]
        venue_average = 0.0
        if boat["venue_races"] > 0:
            venue_average = (
                float(boat["venue_prior"].get(method, 0.0))
                * float(boat["venue_course_wins"])
                / float(boat["venue_races"])
            )
        all_events.append(
            {
                "boat": int(boat["boat"]),
                "course": course,
                "tech_key": method,
                "tech": core.METHOD_JA[method],
                "prob": probability * 100.0,
                "venue_average": venue_average * 100.0,
                "diff": (probability - venue_average) * 100.0,
                "win_share": float(boat["ml_conditional"][method]) * 100.0,
            }
        )
    all_events.sort(key=lambda row: (-row["prob"], row["course"], row["tech_key"]))
    visible = [row for row in all_events if row["tech_key"] != "other"]
    return {
        "status": "ok",
        "race_code": race_code,
        "events": visible,
        "all_events": all_events,
        "top5": visible[:5],
        "venue_races": int(race["boats"][0]["venue_races"]),
        "probability_source": "ai_tenkai_v1",
        "model_version": "v1",
        "method": {
            "name": "AI展開予想 v1（条件付きLightGBM）",
            "win_probability": "AI1着率 v5",
        },
        "training": artifact.get("training", {}),
    }


def main() -> int:
    try:
        payload = json.load(sys.stdin)
        print(json.dumps(calculate(payload), ensure_ascii=False))
        return 0
    except Exception as exc:
        print(json.dumps({"status": "error", "error": str(exc)}, ensure_ascii=False))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
