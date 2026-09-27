#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""画面表示用のAI1着率 v1。保存済みモデルを読み、今回の6艇を推論する。"""

from __future__ import annotations

import json
import sys
from pathlib import Path

import joblib
import numpy as np
import psycopg2

from ai_winrate_features import build_matrix, normalized_race_probabilities

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "common"))
from db_config import load_db_config  # noqa: E402

MODEL_PATH = Path(__file__).resolve().parent / "models" / "ai_winrate_v1.joblib"


def valid_virtual_map(text: str | None) -> dict[int, int] | None:
    if text is None:
        return None
    if len(text) != 6 or sorted(text) != list("123456"):
        raise ValueError("仮想進入は1～6を各1回使う6桁で指定してください")
    return {boat: int(text[boat - 1]) for boat in range(1, 7)}


def fetch_target_rows(conn, race_code: str) -> list[dict]:
    sql = """
        SELECT
            re.race_code, SUBSTRING(re.race_code, 9, 3) AS place_code,
            re.lane_number AS boat, re.player_id::text AS player_id, re.motor_number,
            el.entry_course AS actual_course,
            el.exhibition_time, el.start_timing, el.lap_time, el.around_time, el.straight_time,
            ps.national_win_rate, ps.national_exacta_rate,
            ps.local_win_rate, ps.local_exacta_rate
        FROM boat_race.race_entry re
        JOIN boat_race.exhibition_live el
          ON el.race_code = re.race_code
         AND el.player_id = re.player_id
        LEFT JOIN boat_race.player_stats ps
          ON ps.race_code = re.race_code
         AND ps.player_id = re.player_id
        WHERE re.race_code = %s
        ORDER BY re.lane_number
    """
    with conn.cursor() as cur:
        cur.execute(sql, (race_code,))
        columns = [column.name for column in cur.description]
        return [dict(zip(columns, row)) for row in cur.fetchall()]


def history_stats(conn, row: dict, course: int) -> dict[str, float | None]:
    """対象レースより前だけの選手・コース・モーター成績を読む。"""
    sql = """
        WITH player_history AS (
            SELECT (rrd.rank = '1')::int AS win
            FROM boat_race.race_entry re
            JOIN boat_race.exhibition_live el
              ON el.race_code = re.race_code AND el.player_id = re.player_id
            JOIN boat_race.race_result_detail rrd
              ON rrd.race_code = re.race_code AND rrd.player_id = re.player_id
            WHERE re.player_id::text = %(player_id)s
              AND re.race_code < %(race_code)s
            ORDER BY re.race_code DESC
            LIMIT 100
        ), player_course_history AS (
            SELECT (rrd.rank = '1')::int AS win
            FROM boat_race.race_entry re
            JOIN boat_race.exhibition_live el
              ON el.race_code = re.race_code AND el.player_id = re.player_id
            JOIN boat_race.race_result_detail rrd
              ON rrd.race_code = re.race_code AND rrd.player_id = re.player_id
            WHERE re.player_id::text = %(player_id)s
              AND el.entry_course = %(course)s
              AND re.race_code < %(race_code)s
            ORDER BY re.race_code DESC
            LIMIT 50
        ), motor_history AS (
            SELECT (rrd.rank = '1')::int AS win
            FROM boat_race.race_entry re
            JOIN boat_race.race_result_detail rrd
              ON rrd.race_code = re.race_code AND rrd.player_id = re.player_id
            WHERE SUBSTRING(re.race_code, 9, 3) = %(place_code)s
              AND re.motor_number = %(motor_number)s
              AND re.race_code < %(race_code)s
            ORDER BY re.race_code DESC
            LIMIT 50
        )
        SELECT
            (SELECT AVG(win)::float FROM player_history),
            (SELECT COUNT(*)::int FROM player_history),
            (SELECT AVG(win)::float FROM player_course_history),
            (SELECT COUNT(*)::int FROM player_course_history),
            (SELECT AVG(win)::float FROM motor_history),
            (SELECT COUNT(*)::int FROM motor_history)
    """
    params = {**row, "course": course}
    with conn.cursor() as cur:
        cur.execute(sql, params)
        values = cur.fetchone()
    names = [
        "player_win_rate_100", "player_win_count_100",
        "player_course_win_rate_50", "player_course_count_50",
        "motor_win_rate_50", "motor_count_50",
    ]
    return dict(zip(names, values))


def relative_features(rows: list[dict], key: str, target: str) -> None:
    if any(row[key] is None for row in rows):
        # 直線タイム非公表場などは、学習器の欠損値処理へ任せる。
        for row in rows:
            row[target] = float("nan")
        return
    values = np.asarray([float(row[key]) for row in rows], dtype=np.float64)
    std = float(values.std())
    mean = float(values.mean())
    for row, value in zip(rows, values):
        row[target] = (mean - value) / std if std > 0 else 0.0


def calculate(race_code: str, virtual_lane_to_course: str | None = None) -> dict:
    if not MODEL_PATH.is_file():
        return {"status": "waiting", "boats": {}, "error": "AI1着率モデルがまだ学習されていません"}

    virtual_map = valid_virtual_map(virtual_lane_to_course)
    artifact = joblib.load(MODEL_PATH)
    if artifact.get("version") != "ai_winrate_v1":
        raise RuntimeError("AI1着率モデルの形式が一致しません")

    with psycopg2.connect(**load_db_config()) as conn:
        rows = fetch_target_rows(conn, race_code)
        if len(rows) != 6 or {int(row["boat"]) for row in rows} != set(range(1, 7)):
            return {"status": "waiting", "boats": {}, "error": "AI1着率：展示情報待ち"}
        required = ["actual_course", "exhibition_time", "start_timing", "lap_time", "around_time"]
        if any(row[key] is None for row in rows for key in required):
            return {"status": "waiting", "boats": {}, "error": "AI1着率：展示情報待ち"}

        for row in rows:
            boat = int(row["boat"])
            row["course"] = virtual_map[boat] if virtual_map else int(row["actual_course"])
            row.update(history_stats(conn, row, int(row["course"])))

    relative_features(rows, "exhibition_time", "exhibition_relative")
    relative_features(rows, "start_timing", "st_relative")
    relative_features(rows, "lap_time", "lap_relative")
    relative_features(rows, "around_time", "around_relative")
    relative_features(rows, "straight_time", "straight_relative")

    matrix = build_matrix(rows, artifact["place_to_id"])
    raw = artifact["model"].predict_proba(matrix)[:, 1]
    probabilities = normalized_race_probabilities(raw, [race_code] * len(rows))
    boats = {}
    for row, probability in zip(rows, probabilities):
        boats[str(row["boat"])] = {
            "ai_rate": float(probability * 100.0),
            "course": int(row["course"]),
        }

    training = artifact.get("training", {})
    return {
        "status": "ok",
        "boats": boats,
        "totals": {"ai": float(probabilities.sum() * 100.0)},
        "method": {"name": "HistGradientBoosting v1", "features": artifact.get("feature_names", [])},
        "training": training,
        "virtual_entry": virtual_map is not None,
    }


def main() -> int:
    if len(sys.argv) not in (2, 3):
        print(json.dumps({"status": "error", "boats": {}, "error": "Usage: ai_winrate_live.py RACE_CODE [LANE_TO_COURSE]"}, ensure_ascii=False))
        return 1
    try:
        data = calculate(sys.argv[1].strip().upper(), sys.argv[2].strip() if len(sys.argv) == 3 else None)
        print(json.dumps(data, ensure_ascii=False))
        return 0 if data["status"] == "ok" else 1
    except Exception as exc:
        print(json.dumps({"status": "error", "boats": {}, "error": str(exc)}, ensure_ascii=False))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
