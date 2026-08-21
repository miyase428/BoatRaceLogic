# predict_pattern.py
# race_code を渡すと、予測用スリットパターンIDを返す

import json
import math
import sys
from datetime import datetime
from pathlib import Path
from statistics import pstdev

import psycopg2

REPO_ROOT = Path(__file__).resolve().parents[2]
if str(REPO_ROOT) not in sys.path:
    sys.path.insert(0, str(REPO_ROOT))

from common.db_config import load_db_config
from classify_slit_pattern import classify_slit_pattern

DB_CONFIG = load_db_config()

PREDICTION_METHOD = "C_ST_RANK"
FALLBACK_METHOD = "A_EX_FALLBACK"


def connect_db():
    return psycopg2.connect(
        host=DB_CONFIG["host"],
        port=DB_CONFIG["port"],
        dbname=DB_CONFIG["dbname"],
        user=DB_CONFIG["user"],
        password=DB_CONFIG["password"],
    )


def as_float(value):
    if value is None or value == "":
        return None
    try:
        x = float(value)
    except (TypeError, ValueError):
        return None
    return x if math.isfinite(x) else None


def mean(values):
    return sum(values) / len(values)


# ------------------------------------------------------------
# レース日から、プロジェクト既存ルールの term_info を決める
# 1-4月   -> 前年10月期
# 5-10月  -> 当年04月期
# 11-12月 -> 当年10月期
# ------------------------------------------------------------
def term_info_for_race_code(race_code):
    race_date = datetime.strptime(race_code[:8], "%Y%m%d").date()
    yy = race_date.year % 100

    if race_date.month <= 4:
        return f"{(race_date.year - 1) % 100:02d}10"
    if race_date.month <= 10:
        return f"{yy:02d}04"
    return f"{yy:02d}10"


# ------------------------------------------------------------
# ① race_code から今回の展示進入・選手ID・展示STを取得
# ------------------------------------------------------------
def load_entry_from_race_code(race_code):
    conn = connect_db()
    cur = conn.cursor()

    sql = """
        SELECT
            entry_course,
            player_id,
            start_timing AS exhibition_st
        FROM boat_race.exhibition_live
        WHERE race_code = %s
        ORDER BY entry_course
    """

    cur.execute(sql, (race_code,))
    rows = cur.fetchall()

    cur.close()
    conn.close()

    if len(rows) != 6:
        raise Exception(f"展示情報が6艇分揃っていません: {race_code} / rows={len(rows)}")

    entries = []
    seen_courses = set()

    for course, player_id, exhibition_st in rows:
        course = int(course)
        if course not in range(1, 7) or course in seen_courses:
            raise Exception(f"展示進入が不正です: {race_code} / entry_course={course}")
        seen_courses.add(course)

        ex_st = as_float(exhibition_st)
        if ex_st is None:
            raise Exception(
                f"展示STがありません: {race_code} / entry_course={course} / player_id={player_id}"
            )

        entries.append({
            "course": course,
            "player_id": str(player_id).strip(),
            "ex_st": ex_st,
        })

    if seen_courses != set(range(1, 7)):
        raise Exception(f"展示進入1～6が揃っていません: {race_code} / courses={sorted(seen_courses)}")

    return entries


# ------------------------------------------------------------
# ② racer_results から、今回コースの平均ST・平均ST順位を取得
# ------------------------------------------------------------
def load_course_profiles(term_info, entries):
    player_ids = [entry["player_id"] for entry in entries]

    columns = ["player_id", "average_start"]
    for course in range(1, 7):
        columns += [
            f"course{course}_average_start",
            f"course{course}_average_rank",
        ]

    sql = f"""
        SELECT {', '.join(columns)}
        FROM boat_race.racer_results
        WHERE term_info::text = %s
          AND player_id::text = ANY(%s)
    """

    conn = connect_db()
    cur = conn.cursor()
    cur.execute(sql, (term_info, player_ids))
    rows = cur.fetchall()
    cur.close()
    conn.close()

    racer_map = {}
    for row in rows:
        player_id = str(row[0]).strip()
        overall_st = as_float(row[1])
        idx = 2
        courses = {}

        for course in range(1, 7):
            avg_st = as_float(row[idx])
            avg_rank = as_float(row[idx + 1])
            idx += 2
            courses[course] = {
                "avg_st": avg_st,
                "avg_rank": avg_rank,
            }

        racer_map[player_id] = {
            "overall_st": overall_st,
            "courses": courses,
        }

    profiles = []
    missing_players = []

    for entry in entries:
        player_id = entry["player_id"]
        course = entry["course"]
        rr = racer_map.get(player_id)

        if rr is None:
            profiles.append(None)
            missing_players.append(player_id)
            continue

        course_data = rr["courses"][course]
        avg_st = course_data["avg_st"]
        if avg_st is None or avg_st <= 0:
            avg_st = rr["overall_st"]
        if avg_st is None or avg_st <= 0:
            profiles.append(None)
            missing_players.append(player_id)
            continue

        avg_rank = course_data["avg_rank"]
        if avg_rank is None or not (1.0 <= avg_rank <= 6.0):
            avg_rank = 3.5

        profiles.append({
            "player_id": player_id,
            "course": course,
            "avg_st": avg_st,
            "avg_rank": avg_rank,
        })

    return profiles, missing_players


# ------------------------------------------------------------
# 平均ST順位を、今回6艇のコース平均STと同じ秒スケールへ変換
# 検証済み C_ST_RANK と同じ計算
# ------------------------------------------------------------
def rank_component_seconds(avg_ranks, course_avg_st):
    mean_rank = mean(avg_ranks)
    sd_rank = pstdev(avg_ranks)
    sd_st = pstdev(course_avg_st)

    if sd_rank < 1e-9 or sd_st < 1e-9:
        return [0.0] * 6

    scale = sd_st / sd_rank
    return [(rank - mean_rank) * scale for rank in avg_ranks]


# ------------------------------------------------------------
# ③ C_ST_RANK で予測STを作る
#   展示ST
#   + (今回コース平均ST - 6艇のコース平均ST平均)
#   + コース平均ST順位の相対差（秒スケール変換）
# ------------------------------------------------------------
def make_predicted_st(entries, profiles):
    ex_st = [entry["ex_st"] for entry in entries]
    course_avg_st = [profile["avg_st"] for profile in profiles]
    course_avg_rank = [profile["avg_rank"] for profile in profiles]

    mean_course_st = mean(course_avg_st)
    course_st_adjustment = [value - mean_course_st for value in course_avg_st]
    rank_adjustment = rank_component_seconds(course_avg_rank, course_avg_st)

    predicted_st = [
        ex_st[i] + course_st_adjustment[i] + rank_adjustment[i]
        for i in range(6)
    ]

    total_adjustment = [predicted_st[i] - ex_st[i] for i in range(6)]

    return {
        "exhibition_st": ex_st,
        "course_average_st": course_avg_st,
        "course_average_rank": course_avg_rank,
        "course_st_adjustment": course_st_adjustment,
        "rank_adjustment": rank_adjustment,
        "total_adjustment": total_adjustment,
        "predicted_st": predicted_st,
    }


def make_exhibition_fallback(entries, profiles):
    ex_st = [entry["ex_st"] for entry in entries]

    return {
        "exhibition_st": ex_st,
        "course_average_st": [profile["avg_st"] if profile else None for profile in profiles],
        "course_average_rank": [profile["avg_rank"] if profile else None for profile in profiles],
        "course_st_adjustment": [0.0] * 6,
        "rank_adjustment": [0.0] * 6,
        "total_adjustment": [0.0] * 6,
        "predicted_st": list(ex_st),
    }


# ------------------------------------------------------------
# ④ パターンIDを算出
# ------------------------------------------------------------
def predict_pattern(race_code):
    base_path = sys.path[0]
    settings_path = base_path + "/venue_slit_settings.json"

    with open(settings_path, "r", encoding="utf-8") as f:
        venue_settings = json.load(f)

    # 12パターン条件・優先順位・big_delay_threshold は現行維持
    settings = venue_settings["default"]

    entries = load_entry_from_race_code(race_code)
    term_info = term_info_for_race_code(race_code)
    profiles, missing_players = load_course_profiles(term_info, entries)

    if missing_players:
        # C_ST_RANKに必要な6艇分の期別コース情報が揃わない場合は、
        # 検証済みベースラインの展示STのみへ安全にフォールバックする。
        prediction_method = FALLBACK_METHOD
        predicted = make_exhibition_fallback(entries, profiles)
    else:
        prediction_method = PREDICTION_METHOD
        predicted = make_predicted_st(entries, profiles)

    pattern_id, features = classify_slit_pattern(predicted["predicted_st"], settings)

    player_ids = [entry["player_id"] for entry in entries]
    entry_courses = [entry["course"] for entry in entries]

    correction = {
        player_ids[i]: predicted["total_adjustment"][i]
        for i in range(6)
    }

    return {
        "race_code": race_code,
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


# ------------------------------------------------------------
# ⑤ メイン
# ------------------------------------------------------------
if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Usage: python predict_pattern.py <race_code>")
        sys.exit(1)

    result = predict_pattern(sys.argv[1])
    print(json.dumps(result, indent=2, ensure_ascii=False))
