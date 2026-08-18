#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""仮想進入用スリットパターン予測。

引数2は「艇番 -> コース」の6桁文字列。
例: 124563 = 1号艇1C / 2号艇2C / 3号艇4C / 4号艇5C / 5号艇6C / 6号艇3C。

通常の predict_pattern.py は変更せず、仮想進入モード時だけこのラッパーを使う。
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))

import predict_pattern as base  # noqa: E402


def parse_lane_to_course(value: str) -> dict[int, int]:
    value = str(value or "").strip()
    if len(value) != 6 or sorted(value) != list("123456"):
        raise RuntimeError("仮想進入は1～6を1回ずつ使う6桁で指定してください")
    return {lane: int(value[lane - 1]) for lane in range(1, 7)}


def load_virtual_entries(race_code: str, lane_to_course: dict[int, int]) -> list[dict]:
    conn = base.connect_db()
    cur = conn.cursor()
    cur.execute(
        """
        SELECT
            re.lane_number,
            re.player_id::text,
            el.start_timing
        FROM boat_race.race_entry re
        JOIN boat_race.exhibition_live el
          ON el.race_code = re.race_code
         AND el.player_id = re.player_id
        WHERE re.race_code = %s
        ORDER BY re.lane_number
        """,
        (race_code,),
    )
    rows = cur.fetchall()
    cur.close()
    conn.close()

    if len(rows) != 6:
        raise RuntimeError(f"展示情報が6艇分揃っていません: {race_code} / rows={len(rows)}")

    by_course = {}
    seen_lanes = set()
    for lane, player_id, exhibition_st in rows:
        lane = int(lane)
        if lane not in range(1, 7) or lane in seen_lanes:
            raise RuntimeError("艇番1～6を正しく取得できません")
        seen_lanes.add(lane)

        course = lane_to_course[lane]
        ex_st = base.as_float(exhibition_st)
        if ex_st is None:
            raise RuntimeError(f"展示STがありません: {race_code} / {lane}号艇")

        by_course[course] = {
            "course": course,
            "player_id": str(player_id).strip(),
            "ex_st": ex_st,
        }

    if set(by_course) != set(range(1, 7)):
        raise RuntimeError("仮想進入1～6コースが揃っていません")

    return [by_course[c] for c in range(1, 7)]


def predict_virtual(race_code: str, lane_to_course_text: str) -> dict:
    lane_to_course = parse_lane_to_course(lane_to_course_text)

    settings_path = HERE / "venue_slit_settings.json"
    with settings_path.open("r", encoding="utf-8") as f:
        venue_settings = json.load(f)
    settings = venue_settings["default"]

    entries = load_virtual_entries(race_code, lane_to_course)
    term_info = base.term_info_for_race_code(race_code)
    profiles, missing_players = base.load_course_profiles(term_info, entries)

    if missing_players:
        prediction_method = base.FALLBACK_METHOD
        predicted = base.make_exhibition_fallback(entries, profiles)
    else:
        prediction_method = base.PREDICTION_METHOD
        predicted = base.make_predicted_st(entries, profiles)

    pattern_id, features = base.classify_slit_pattern(predicted["predicted_st"], settings)
    player_ids = [entry["player_id"] for entry in entries]
    entry_courses = [entry["course"] for entry in entries]
    correction = {
        player_ids[i]: predicted["total_adjustment"][i]
        for i in range(6)
    }

    return {
        "race_code": race_code,
        "virtual_lane_to_course": lane_to_course_text,
        "prediction_method": prediction_method,
        "term_info": term_info,
        "missing_racer_profiles": missing_players,
        "entry_courses": entry_courses,
        "player_ids": player_ids,
        "exhibition_st": predicted["exhibition_st"],
        "course_average_st": predicted["course_average_st"],
        "course_average_rank": predicted["course_average_rank"],
        "course_st_adjustment": predicted["course_st_adjustment"],
        "rank_adjustment": predicted["rank_adjustment"],
        "correction": correction,
        "predicted_st": predicted["predicted_st"],
        "pattern_id": pattern_id,
        "features": features,
    }


def main() -> int:
    if len(sys.argv) != 3:
        print(json.dumps({"error": "Usage: predict_pattern_virtual.py RACE_CODE LANE_TO_COURSE"}, ensure_ascii=False))
        return 1

    try:
        result = predict_virtual(sys.argv[1].strip().upper(), sys.argv[2].strip())
        print(json.dumps(result, ensure_ascii=False))
        return 0
    except Exception as exc:
        print(json.dumps({"error": str(exc)}, ensure_ascii=False))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
