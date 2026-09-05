#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""実質5艇立て向け 補正後1着率。

通常6艇ロジックの係数は変えず、展示5指標が全NULLの欠場艇だけを除外して
残る5艇で以下を計算する。

- BB_MEDIUM + 展示進入リマップ
- EX_TOTAL（AMG/TKY/SMEはEX_TOTAL3） beta=0.10
- SUM_RAW gamma=2.0
- スリット補正は6艇STが必要なため、取得できる場合だけ alpha=0.25 を適用
- RAW_TEMPは同じ固定式を5艇正規化後へ適用

このスクリプトは実質5艇立て専用。通常6艇では呼ばない。
"""

from __future__ import annotations

import json
import math
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))

NO_STRAIGHT_PLACES = {"AMG", "TKY", "SME"}
GLOBAL_TAU = 0.90
RAW_K = 0.80
TAU_MIN = 0.45
TAU_MAX = 1.60


def normalize(values):
    vals = [max(float(v), 1.0e-15) for v in values]
    total = sum(vals)
    if total <= 0 or not math.isfinite(total):
        raise RuntimeError("5艇正規化前合計が不正です")
    return [v / total for v in vals]


def parse_active(text: str) -> list[int]:
    values = []
    for token in text.replace(" ", "").split(","):
        if token == "":
            continue
        try:
            boat = int(token)
        except ValueError as exc:
            raise RuntimeError("active_boatsが不正です") from exc
        if boat < 1 or boat > 6 or boat in values:
            raise RuntimeError("active_boatsは1～6の重複なしで指定してください")
        values.append(boat)
    values.sort()
    if len(values) != 5:
        raise RuntimeError("実質5艇立ては有効艇5艇を指定してください")
    return values


def load_current_exhibition(conn, live, race_code: str, active_boats: list[int], no_straight: bool):
    sql = """
        SELECT
            re.lane_number,
            re.player_id::text,
            el.entry_course,
            el.exhibition_time,
            el.start_timing,
            el.lap_time,
            el.around_time,
            el.straight_time
        FROM boat_race.race_entry re
        LEFT JOIN LATERAL (
            SELECT
                x.entry_course,
                x.exhibition_time,
                x.start_timing,
                x.lap_time,
                x.around_time,
                x.straight_time
            FROM boat_race.exhibition_live x
            WHERE x.race_code = re.race_code
              AND x.player_id = re.player_id
            LIMIT 1
        ) el ON TRUE
        WHERE re.race_code = %s
        ORDER BY re.lane_number
    """
    with conn.cursor() as cur:
        cur.execute(sql, (race_code,))
        rows = cur.fetchall()

    if len(rows) != 6:
        raise RuntimeError(f"対象レースのrace_entryが6艇分ありません: rows={len(rows)}")

    active_set = set(active_boats)
    exhibition = {}
    course_by_lane = {}
    seen_active_courses = set()

    for lane, player_id, course, ex, st, lap, around, straight in rows:
        lane = live.valid_course(lane)
        course = live.valid_course(course)
        if lane is None:
            raise RuntimeError("lane_numberが不正です")
        if course is not None:
            course_by_lane[lane] = course

        if lane not in active_set:
            continue

        ex = live.as_float(ex)
        st = live.as_float(st)
        lap = live.as_float(lap)
        around = live.as_float(around)
        straight = live.as_float(straight)

        required_ok = (
            course is not None
            and ex is not None
            and st is not None
            and lap is not None
            and around is not None
            and (no_straight or straight is not None)
        )
        if not required_ok:
            raise RuntimeError(f"有効艇{lane}号艇の展示情報が不完全です")
        if course in seen_active_courses:
            raise RuntimeError("有効艇の展示進入が重複しています")
        seen_active_courses.add(course)

        exhibition[lane] = {
            "lane": lane,
            "player_id": str(player_id or "").strip(),
            "course": course,
            "exhibition_time": ex,
            "start_timing": st,
            "lap_time": lap,
            "around_time": around,
            "straight_time": straight,
        }

    if set(exhibition) != active_set:
        raise RuntimeError("有効5艇の展示情報が揃っていません")
    return exhibition, course_by_lane


def calc_ex_scores(live, exhibition, active_boats, venue_avg_ex, no_straight):
    rows = [exhibition[lane] for lane in active_boats]
    n = float(len(rows))
    avg_lap = sum(r["lap_time"] for r in rows) / n
    avg_around = sum(r["around_time"] for r in rows) / n
    avg_straight = None if no_straight else sum(r["straight_time"] for r in rows) / n

    scores = []
    detail = {}
    for r in rows:
        ex_score = live.calc_ex_score(r["exhibition_time"] - venue_avg_ex)
        lap_score = live.calc_lap_score(r["lap_time"], avg_lap)
        around_score = live.calc_around_score(r["around_time"], avg_around)
        if no_straight:
            straight_score = None
            total = float(ex_score + lap_score + around_score)
            method = "EX_TOTAL3_EFFECTIVE5"
        else:
            straight_score = live.calc_straight_score(r["straight_time"], avg_straight)
            total = float(ex_score + lap_score + around_score + straight_score)
            method = "EX_TOTAL_EFFECTIVE5"
        scores.append(total)
        detail[r["lane"]] = {
            "ex_score": ex_score,
            "lap_score": lap_score,
            "around_score": around_score,
            "straight_score": straight_score,
            "ex_total": total,
            "ex_method": method,
        }
    return scores, detail


def current_sum_scores(live, exhibition, active_boats, feature_cols, sum_stats):
    key_map = {
        "exhibition_time": "exhibition_time",
        "lap_time": "lap_time",
        "around_time": "around_time",
        "straight_time": "straight_time",
    }
    rows = [exhibition[lane] for lane in active_boats]
    raw = [sum(r[key_map[name]] for name in feature_cols) for r in rows]
    avg = sum(raw) / len(raw)

    scores = []
    detail = {}
    for r, sum_raw in zip(rows, raw):
        diff = sum_raw - avg
        label = live.sum_interval_label(diff)
        stat = sum_stats.get(r["course"], {}).get(label)
        score = float(stat["score"]) if stat else 0.0
        scores.append(score)
        detail[r["lane"]] = {
            "sum_raw": sum_raw,
            "sum_diff": diff,
            "interval": label,
            "sum_score": score,
            "interval_n": int(stat["interval_n"]) if stat else 0,
            "course_n": int(stat["course_n"]) if stat else 0,
        }
    return scores, detail


def apply_slit_active(live, probs, exhibition, active_boats, buff):
    adjusted = []
    raw_buffs = []
    for idx, lane in enumerate(active_boats):
        course = exhibition[lane]["course"]
        cell = buff.get(str(course), {})
        delta = live.as_float(cell.get("win"))
        if delta is None:
            delta = 0.0
        raw_buffs.append(delta)
        adjusted.append(max(1.0e-6, probs[idx] + live.SLIT_ALPHA * delta))
    return normalize(adjusted), raw_buffs


def apply_raw_temp(probs, raw_total):
    if raw_total <= 0 or not math.isfinite(raw_total):
        raise RuntimeError("RAW_TEMP raw_totalが不正です")
    tau = min(TAU_MAX, max(TAU_MIN, GLOBAL_TAU + RAW_K * math.log(raw_total)))
    powered = [max(float(p), 1.0e-15) ** tau for p in probs]
    return normalize(powered), tau


def main() -> int:
    if len(sys.argv) != 3:
        print(json.dumps({
            "status": "error",
            "boats": {},
            "error": "Usage: corrected_winrate_live_effective.py RACE_CODE ACTIVE_BOATS",
        }, ensure_ascii=False))
        return 1

    race_code = sys.argv[1].strip().upper()
    try:
        active_boats = parse_active(sys.argv[2])
        if len(race_code) < 13:
            raise RuntimeError("race_codeが不正です")
        place_code = race_code[8:11]
        no_straight = place_code in NO_STRAIGHT_PLACES

        if no_straight:
            import corrected_winrate_live_exact_no_straight_fact as chain  # noqa: E402
        else:
            import corrected_winrate_live_exact_fact as chain  # noqa: E402
        live = chain.live

        with live.connect_db() as conn:
            target_date, loaded_place, stadium_name, boats_all = live.load_target(conn, race_code)
            if loaded_place != place_code:
                place_code = loaded_place
            exhibition, _course_by_lane = load_current_exhibition(
                conn, live, race_code, active_boats, no_straight
            )
            active_set = set(active_boats)
            boats = [b for b in boats_all if int(b["lane"]) in active_set]
            if len(boats) != 5:
                raise RuntimeError("有効5艇の出走表データを取得できません")

            base_remap, base_detail = live.build_remapped_base(
                conn, race_code, target_date, place_code, boats, exhibition
            )
            venue_avg_ex = live.venue_exhibition_average(conn, race_code, target_date, place_code)
            ex_scores, ex_detail = calc_ex_scores(
                live, exhibition, active_boats, venue_avg_ex, no_straight
            )
            ex_probs = live.apply_centered_score(base_remap, ex_scores, live.EX_TOTAL_BETA)
            if ex_probs is None:
                raise RuntimeError("5艇EX_TOTAL補正後確率を正規化できません")

            features = live.load_sum_features()
            feature_cols = features.get(place_code)
            if not isinstance(feature_cols, list) or len(feature_cols) != 3:
                raise RuntimeError(f"SUM features設定がありません: {place_code}")
            sum_stats = live.load_sum_stats(conn, race_code, target_date, place_code, feature_cols)
            sum_scores, sum_detail = current_sum_scores(
                live, exhibition, active_boats, feature_cols, sum_stats
            )
            sum_probs = live.apply_centered_score(ex_probs, sum_scores, live.SUM_GAMMA)
            if sum_probs is None:
                raise RuntimeError("5艇SUM補正後確率を正規化できません")

        slit_applied = False
        slit_error = ""
        slit_pattern_id = 0
        raw_buffs = [0.0 for _ in active_boats]
        before_temp = sum_probs
        try:
            predict, buff_data, slit_buff = live.slit_prediction_and_buff(race_code, target_date)
            before_temp, raw_buffs = apply_slit_active(
                live, sum_probs, exhibition, active_boats, slit_buff
            )
            slit_applied = True
            slit_pattern_id = int(predict.get("pattern_id", 0))
        except Exception as exc:
            # 5艇立てでは欠場艇のSTがNULLのため通常スリット分類が成立しないことがある。
            # その場合は展示+SUMまでを確定値として使い、スリットだけ中立でスキップする。
            slit_error = str(exc)

        raw_total = sum(float(base_detail[lane]["p_final_raw"]) for lane in active_boats)
        final_probs, tau = apply_raw_temp(before_temp, raw_total)

        boat_by_lane = {int(b["lane"]): b for b in boats}
        result = {}
        for idx, lane in enumerate(active_boats):
            b = boat_by_lane[lane]
            result[str(lane)] = {
                "lane": lane,
                "player_id": b["player_id"],
                "player_name": b["player_name"],
                "exhibition_course": exhibition[lane]["course"],
                "remap_rate": base_remap[idx] * 100.0,
                "ex_total_score": ex_scores[idx],
                "ex_total_rate": ex_probs[idx] * 100.0,
                "sum_score": sum_scores[idx],
                "sum_rate": sum_probs[idx] * 100.0,
                "slit_raw_buff": raw_buffs[idx],
                "corrected_rate_before_raw_temp": before_temp[idx] * 100.0,
                "corrected_rate": final_probs[idx] * 100.0,
                "base_detail": base_detail[lane],
                "ex_detail": ex_detail[lane],
                "sum_detail": sum_detail[lane],
            }

        excluded = [b for b in range(1, 7) if b not in active_boats]
        payload = {
            "status": "ok",
            "race_code": race_code,
            "target_date": target_date.isoformat(),
            "place_code": place_code,
            "stadium_name": stadium_name,
            "active_boats": active_boats,
            "excluded_boats": excluded,
            "method": {
                "effective_boat_mode": "EFFECTIVE5",
                "base": "BB_MEDIUM + exhibition course remap / active5 normalize",
                "ex_total_beta": live.EX_TOTAL_BETA,
                "sum_method": "SUM_RAW_ACTIVE5",
                "sum_gamma": live.SUM_GAMMA,
                "slit_alpha": live.SLIT_ALPHA if slit_applied else 0.0,
                "slit_applied": slit_applied,
                "slit_pattern_id": slit_pattern_id,
                "slit_skip_reason": slit_error,
                "raw_temp": {
                    "formula": "clamp(0.90 + 0.80 * ln(raw_total), 0.45, 1.60)",
                    "raw_total": raw_total,
                    "tau": tau,
                },
                "venue_exhibition_avg_183d": venue_avg_ex,
                "sum_features": feature_cols,
            },
            "boats": result,
            "totals": {
                "remap": sum(base_remap) * 100.0,
                "ex_total": sum(ex_probs) * 100.0,
                "sum": sum(sum_probs) * 100.0,
                "corrected_before_raw_temp": sum(before_temp) * 100.0,
                "corrected": sum(final_probs) * 100.0,
            },
            "error": "",
        }
        print(json.dumps(payload, ensure_ascii=False))
        return 0
    except Exception as exc:
        print(json.dumps({"status": "error", "boats": {}, "error": str(exc)}, ensure_ascii=False))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
