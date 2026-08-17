#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""Web用 補正後1着率。

STEP8で採用した式を、結果未確定の現在レースへ適用する。

採用式:
1. BB_MEDIUM Kpc=20 / Kpvc=10 を展示進入コースへリマップ
2. EX_TOTAL beta=0.10
3. SUM_RAW gamma=2.0
4. スリット predicted PID×展示進入C win buff alpha=0.25
5. 各段階で6艇100%正規化

スリットbuffは対象日前180日で学習し、日付単位で/tmpキャッシュする。
"""

from __future__ import annotations

import json
import math
import subprocess
import sys
from datetime import timedelta
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
ANALYSIS_DIR = REPO_ROOT / "analysis"
THEORY_DIR = REPO_ROOT / "theories" / "course_correction"
sys.path.insert(0, str(ANALYSIS_DIR))
sys.path.insert(0, str(THEORY_DIR))

from slit_validate_v2 import connect_db  # noqa: E402
from base_winrate_race import (  # noqa: E402
    K_PC,
    K_PVC,
    load_target,
    load_venue_course_prior,
    load_last_100,
    player_counts,
)
from base_winrate_exhibition_compare import (  # noqa: E402
    ROLLING_EX_DAYS,
    calc_ex_score,
    calc_lap_score,
    calc_around_score,
    calc_straight_score,
    normalize,
)
from base_winrate_sum_compare import (  # noqa: E402
    EX_TOTAL_BETA,
    load_sum_features,
    sum_interval_label,
    apply_centered_score,
)

SUM_GAMMA = 2.0
SLIT_ALPHA = 0.25


def as_float(value):
    if value is None or value == "":
        return None
    try:
        x = float(value)
    except (TypeError, ValueError):
        return None
    return x if math.isfinite(x) else None


def valid_course(value):
    try:
        c = int(value)
    except (TypeError, ValueError):
        return None
    return c if 1 <= c <= 6 else None


def load_current_exhibition(conn, race_code):
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
        raise RuntimeError(f"展示情報が6艇分揃っていません: rows={len(rows)}")

    out = {}
    courses = set()
    for lane, player_id, course, ex, st, lap, around, straight in rows:
        lane = valid_course(lane)
        course = valid_course(course)
        vals = [as_float(ex), as_float(st), as_float(lap), as_float(around), as_float(straight)]
        if lane is None or course is None or any(v is None for v in vals):
            raise RuntimeError("展示情報が未取得または不完全です")
        if lane in out or course in courses:
            raise RuntimeError("展示進入が重複しています")
        courses.add(course)
        out[lane] = {
            "lane": lane,
            "player_id": str(player_id or "").strip(),
            "course": course,
            "exhibition_time": vals[0],
            "start_timing": vals[1],
            "lap_time": vals[2],
            "around_time": vals[3],
            "straight_time": vals[4],
        }

    if set(out) != set(range(1, 7)) or courses != set(range(1, 7)):
        raise RuntimeError("展示進入1～6コースが揃っていません")
    return out


def venue_exhibition_average(conn, race_code, target_date, place_code):
    cutoff = target_date - timedelta(days=ROLLING_EX_DAYS)
    sql = """
        SELECT AVG(el.exhibition_time::double precision)
        FROM boat_race.exhibition_live el
        JOIN boat_race.race_master rm
          ON rm.race_code = el.race_code
        WHERE SUBSTRING(el.race_code, 9, 3) = %s
          AND el.exhibition_time IS NOT NULL
          AND el.exhibition_time::double precision > 0
          AND rm.race_date >= %s::date
          AND (
                rm.race_date < %s::date
                OR (rm.race_date = %s::date AND el.race_code < %s)
              )
    """
    with conn.cursor() as cur:
        cur.execute(
            sql,
            (
                place_code,
                cutoff.isoformat(),
                target_date.isoformat(),
                target_date.isoformat(),
                race_code,
            ),
        )
        value = cur.fetchone()[0]
    avg = as_float(value)
    if avg is None or avg <= 0:
        raise RuntimeError("対象レース以前183日の場平均展示タイムを取得できません")
    return avg


def build_remapped_base(conn, race_code, target_date, place_code, boats, exhibition):
    venue = load_venue_course_prior(conn, race_code, target_date, place_code)
    raw = []
    detail = {}

    for boat in sorted(boats, key=lambda r: r["lane"]):
        lane = int(boat["lane"])
        course = exhibition[lane]["course"]
        history = load_last_100(conn, boat["player_id"], target_date, race_code)
        counts = player_counts(history, course, place_code)
        p0 = venue[course]["rate"]
        p_pc = (counts["pc_w"] + K_PC * p0) / (counts["pc_n"] + K_PC)
        p_final = (counts["pvc_w"] + K_PVC * p_pc) / (counts["pvc_n"] + K_PVC)
        raw.append(p_final)
        detail[lane] = {
            "course": course,
            "p0": p0,
            "p_pc": p_pc,
            "p_final_raw": p_final,
        }

    probs = normalize(raw)
    if probs is None:
        raise RuntimeError("展示進入リマップ後の基本1着率を正規化できません")
    return probs, detail


def calc_ex_total_scores(exhibition, venue_avg_ex):
    rows = [exhibition[lane] for lane in range(1, 7)]
    avg_lap = sum(r["lap_time"] for r in rows) / 6.0
    avg_around = sum(r["around_time"] for r in rows) / 6.0
    avg_straight = sum(r["straight_time"] for r in rows) / 6.0

    scores = []
    detail = {}
    for r in rows:
        ex_score = calc_ex_score(r["exhibition_time"] - venue_avg_ex)
        lap_score = calc_lap_score(r["lap_time"], avg_lap)
        around_score = calc_around_score(r["around_time"], avg_around)
        straight_score = calc_straight_score(r["straight_time"], avg_straight)
        ex_total = float(ex_score + lap_score + around_score + straight_score)
        scores.append(ex_total)
        detail[r["lane"]] = {
            "ex_score": ex_score,
            "lap_score": lap_score,
            "around_score": around_score,
            "straight_score": straight_score,
            "ex_total": ex_total,
        }
    return scores, detail


def feature_sql_name(name):
    mapping = {
        "exhibition_time": "exhibition_time",
        "lap_time": "lap_time",
        "around_time": "around_time",
        "straight_time": "straight_time",
    }
    if name not in mapping:
        raise RuntimeError(f"未対応SUM feature: {name}")
    return mapping[name]


def load_sum_stats(conn, race_code, target_date, place_code, feature_cols):
    cols = [feature_sql_name(name) for name in feature_cols]
    sum_expr = " + ".join(f"b.{c}::double precision" for c in cols)

    sql = f"""
        WITH base_rows AS (
            SELECT
                rm.race_date,
                re.race_code,
                re.lane_number,
                re.player_id::text AS player_id,
                rrd.rank,
                el.entry_course,
                el.exhibition_time,
                el.start_timing,
                el.lap_time,
                el.around_time,
                el.straight_time
            FROM boat_race.race_entry re
            JOIN boat_race.race_master rm
              ON rm.race_code = re.race_code
            LEFT JOIN boat_race.race_result_detail rrd
              ON rrd.race_code = re.race_code
             AND rrd.player_id = re.player_id
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
            WHERE SUBSTRING(re.race_code, 9, 3) = %s
              AND (
                    rm.race_date < %s::date
                    OR (rm.race_date = %s::date AND re.race_code < %s)
                  )
        ),
        valid_races AS (
            SELECT race_code
            FROM base_rows
            GROUP BY race_code
            HAVING COUNT(*) = 6
               AND COUNT(DISTINCT lane_number) = 6
               AND COUNT(entry_course) = 6
               AND COUNT(DISTINCT entry_course) = 6
               AND COUNT(exhibition_time) = 6
               AND COUNT(start_timing) = 6
               AND COUNT(lap_time) = 6
               AND COUNT(around_time) = 6
               AND COUNT(straight_time) = 6
               AND COUNT(*) FILTER (WHERE rank = '1') = 1
        ),
        scored AS (
            SELECT
                b.*,
                ({sum_expr}) AS sum_raw
            FROM base_rows b
            JOIN valid_races v USING (race_code)
        ),
        with_avg AS (
            SELECT
                s.*,
                AVG(sum_raw) OVER (PARTITION BY race_code) AS avg_sum
            FROM scored s
        ),
        bucketed AS (
            SELECT
                entry_course::int AS course,
                CASE
                    WHEN sum_raw - avg_sum < -0.6 THEN '-0.6未満'
                    WHEN sum_raw - avg_sum < -0.4 THEN '-0.6--0.4'
                    WHEN sum_raw - avg_sum < -0.2 THEN '-0.4--0.2'
                    WHEN sum_raw - avg_sum <  0.0 THEN '-0.2-0.0'
                    WHEN sum_raw - avg_sum <  0.2 THEN '0.0-0.2'
                    WHEN sum_raw - avg_sum <  0.4 THEN '0.2-0.4'
                    WHEN sum_raw - avg_sum <  0.6 THEN '0.4-0.6'
                    ELSE '0.6以上'
                END AS interval_label,
                CASE WHEN rank = '1' THEN 1 ELSE 0 END AS win
            FROM with_avg
            WHERE entry_course::int BETWEEN 1 AND 6
        ),
        course_counts AS (
            SELECT course, COUNT(*) AS n, SUM(win) AS w
            FROM bucketed
            GROUP BY course
        ),
        interval_counts AS (
            SELECT course, interval_label, COUNT(*) AS n, SUM(win) AS w
            FROM bucketed
            GROUP BY course, interval_label
        )
        SELECT
            i.course,
            i.interval_label,
            i.n AS interval_n,
            i.w AS interval_w,
            c.n AS course_n,
            c.w AS course_w
        FROM interval_counts i
        JOIN course_counts c USING (course)
        ORDER BY i.course, i.interval_label
    """

    stats = {c: {} for c in range(1, 7)}
    with conn.cursor() as cur:
        cur.execute(
            sql,
            (
                place_code,
                target_date.isoformat(),
                target_date.isoformat(),
                race_code,
            ),
        )
        for course, label, inn, inw, cn, cw in cur.fetchall():
            c = valid_course(course)
            if c is None:
                continue
            inn = int(inn or 0)
            inw = int(inw or 0)
            cn = int(cn or 0)
            cw = int(cw or 0)
            if cn <= 0 or inn <= 0:
                score = 0.0
            else:
                score = (inw / inn) - (cw / cn)
            stats[c][str(label)] = {
                "score": score,
                "interval_n": inn,
                "interval_w": inw,
                "course_n": cn,
                "course_w": cw,
            }
    return stats


def current_sum_scores(exhibition, feature_cols, sum_stats):
    key_map = {
        "exhibition_time": "exhibition_time",
        "lap_time": "lap_time",
        "around_time": "around_time",
        "straight_time": "straight_time",
    }
    rows = [exhibition[lane] for lane in range(1, 7)]
    raw = []
    for r in rows:
        raw.append(sum(r[key_map[name]] for name in feature_cols))
    avg = sum(raw) / 6.0

    scores = []
    detail = {}
    for idx, r in enumerate(rows):
        diff = raw[idx] - avg
        label = sum_interval_label(diff)
        stat = sum_stats.get(r["course"], {}).get(label)
        score = float(stat["score"]) if stat else 0.0
        scores.append(score)
        detail[r["lane"]] = {
            "sum_raw": raw[idx],
            "sum_diff": diff,
            "interval": label,
            "sum_score": score,
            "interval_n": int(stat["interval_n"]) if stat else 0,
            "course_n": int(stat["course_n"]) if stat else 0,
        }
    return scores, detail


def run_json_script(args, timeout):
    proc = subprocess.run(
        args,
        capture_output=True,
        text=True,
        timeout=timeout,
        check=False,
    )
    text = (proc.stdout or "").strip()
    try:
        data = json.loads(text)
    except json.JSONDecodeError as exc:
        err = (proc.stderr or "").strip()
        raise RuntimeError(f"補助スクリプトJSON取得失敗: {exc}; {err or text[:300]}")
    if data.get("error"):
        raise RuntimeError(str(data["error"]))
    return data


def slit_prediction_and_buff(race_code, target_date):
    predict = run_json_script(
        [sys.executable, str(THEORY_DIR / "predict_pattern.py"), race_code],
        timeout=30,
    )
    pid = int(predict.get("pattern_id", 0))
    if pid not in range(1, 13):
        raise RuntimeError("スリットPIDを取得できません")

    buff_data = run_json_script(
        [sys.executable, str(THEORY_DIR / "live_win_buff.py"), target_date.isoformat()],
        timeout=180,
    )
    buff = buff_data.get("buff", {}).get(str(pid), {})
    if not buff:
        raise RuntimeError(f"PID={pid} のrolling slit buffがありません")
    return predict, buff_data, buff


def apply_slit(sum_probs, exhibition, buff):
    adjusted = []
    raw_buffs = []
    for idx, lane in enumerate(range(1, 7)):
        course = exhibition[lane]["course"]
        cell = buff.get(str(course), {})
        delta = as_float(cell.get("win"))
        if delta is None:
            delta = 0.0
        raw_buffs.append(delta)
        adjusted.append(max(1e-6, sum_probs[idx] + SLIT_ALPHA * delta))
    probs = normalize(adjusted)
    if probs is None:
        raise RuntimeError("スリット補正後確率を正規化できません")
    return probs, raw_buffs


def main():
    if len(sys.argv) != 2:
        print(json.dumps({"status": "error", "error": "Usage: corrected_winrate_live.py RACE_CODE"}, ensure_ascii=False))
        return 1

    race_code = sys.argv[1].strip().upper()
    if len(race_code) < 13:
        print(json.dumps({"status": "error", "error": "race_codeが不正です"}, ensure_ascii=False))
        return 1

    try:
        with connect_db() as conn:
            target_date, place_code, stadium_name, boats = load_target(conn, race_code)
            exhibition = load_current_exhibition(conn, race_code)

            base_remap, base_detail = build_remapped_base(
                conn, race_code, target_date, place_code, boats, exhibition
            )

            venue_avg_ex = venue_exhibition_average(conn, race_code, target_date, place_code)
            ex_scores, ex_detail = calc_ex_total_scores(exhibition, venue_avg_ex)
            ex_probs = apply_centered_score(base_remap, ex_scores, EX_TOTAL_BETA)
            if ex_probs is None:
                raise RuntimeError("EX_TOTAL補正後確率を正規化できません")

            features = load_sum_features()
            feature_cols = features.get(place_code)
            if not isinstance(feature_cols, list) or len(feature_cols) != 3:
                raise RuntimeError(f"SUM features設定がありません: {place_code}")
            sum_stats = load_sum_stats(conn, race_code, target_date, place_code, feature_cols)
            sum_scores, sum_detail = current_sum_scores(exhibition, feature_cols, sum_stats)
            sum_probs = apply_centered_score(ex_probs, sum_scores, SUM_GAMMA)
            if sum_probs is None:
                raise RuntimeError("SUM補正後確率を正規化できません")

        predict, buff_data, slit_buff = slit_prediction_and_buff(race_code, target_date)
        final_probs, raw_buffs = apply_slit(sum_probs, exhibition, slit_buff)

        boat_by_lane = {int(b["lane"]): b for b in boats}
        result = {}
        for idx, lane in enumerate(range(1, 7)):
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
                "corrected_rate": final_probs[idx] * 100.0,
                "base_detail": base_detail[lane],
                "ex_detail": ex_detail[lane],
                "sum_detail": sum_detail[lane],
            }

        payload = {
            "status": "ok",
            "race_code": race_code,
            "target_date": target_date.isoformat(),
            "place_code": place_code,
            "stadium_name": stadium_name,
            "method": {
                "base": "BB_MEDIUM Kpc=20/Kpvc=10 + exhibition course remap",
                "ex_total_beta": EX_TOTAL_BETA,
                "sum_method": "SUM_RAW",
                "sum_gamma": SUM_GAMMA,
                "slit_alpha": SLIT_ALPHA,
                "slit_pattern_id": int(predict["pattern_id"]),
                "slit_prediction_method": predict.get("prediction_method", ""),
                "slit_training_start": buff_data.get("training_start"),
                "slit_training_end": buff_data.get("training_end"),
                "slit_training_races": buff_data.get("training_races"),
                "venue_exhibition_avg_183d": venue_avg_ex,
                "sum_features": feature_cols,
            },
            "boats": result,
            "totals": {
                "remap": sum(base_remap) * 100.0,
                "ex_total": sum(ex_probs) * 100.0,
                "sum": sum(sum_probs) * 100.0,
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
