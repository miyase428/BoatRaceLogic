import json
import os
import sys
from argparse import ArgumentParser
from collections import defaultdict
from pathlib import Path

import psycopg2

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
REPO_ROOT = Path(__file__).resolve().parents[2]
if str(REPO_ROOT) not in sys.path:
    sys.path.insert(0, str(REPO_ROOT))

from common.db_config import load_db_config

DB_CONFIG = load_db_config()

SUM_INTERVALS = [
    ("-0.6未満", float("-inf"), -0.6),
    ("-0.6--0.4", -0.6, -0.4),
    ("-0.4--0.2", -0.4, -0.2),
    ("-0.2-0.0", -0.2, 0.0),
    ("0.0-0.2", 0.0, 0.2),
    ("0.2-0.4", 0.2, 0.4),
    ("0.4-0.6", 0.4, 0.6),
    ("0.6以上", 0.6, float("inf")),
]


def load_features(path: str = "features.json") -> dict:
    full_path = os.path.join(BASE_DIR, path)
    with open(full_path, "r", encoding="utf-8") as f:
        return json.load(f)


def get_sum_interval_label(value: float) -> str:
    for label, low, high in SUM_INTERVALS:
        if low <= value < high:
            return label
    return "unknown"


def connect_db():
    return psycopg2.connect(**DB_CONFIG)


def fetch_exhibition_data(conn, jyo: str):
    sql = """
        SELECT el.race_code, el.player_id, el.entry_course,
               el.exhibition_time, el.lap_time, el.around_time, el.straight_time
        FROM boat_race.exhibition_live el
        WHERE SUBSTRING(el.race_code, 9, 3) = %s
        ORDER BY el.race_code, el.entry_course
    """
    cur = conn.cursor()
    cur.execute(sql, (jyo,))
    rows = cur.fetchall()
    cur.close()
    return rows


def fetch_result_data(conn, jyo: str):
    sql = """
        SELECT re.race_code, re.player_id, rrd.rank
        FROM boat_race.race_entry re
        LEFT JOIN boat_race.race_result_detail rrd
          ON rrd.race_code = re.race_code
         AND rrd.player_id = re.player_id
        WHERE SUBSTRING(re.race_code, 9, 3) = %s
          AND EXISTS (
                SELECT 1
                FROM boat_race.race_result_detail winner
                WHERE winner.race_code = re.race_code
                  AND winner.rank = '1'
          )
        ORDER BY re.race_code, re.lane_number
    """
    cur = conn.cursor()
    cur.execute(sql, (jyo,))
    rows = cur.fetchall()
    cur.close()
    return rows


def normalize_rank(rank):
    if rank is None or rank == "":
        return 5.5
    try:
        value = int(rank)
    except (TypeError, ValueError):
        return None
    return float(value) if 1 <= value <= 6 else None


def build_race_dicts(exhibition_rows, result_rows):
    exhibitions_by_race = defaultdict(list)
    for race_code, player_id, entry_course, ex_time, lap, around, straight in exhibition_rows:
        exhibitions_by_race[race_code].append({
            "race_code": race_code,
            "player_id": str(player_id),
            "entry_course": int(entry_course),
            "exhibition_time": float(ex_time) if ex_time is not None else None,
            "lap_time": float(lap) if lap is not None else None,
            "around_time": float(around) if around is not None else None,
            "straight_time": float(straight) if straight is not None else None,
        })

    rank_by_race_player = {}
    for race_code, player_id, rank in result_rows:
        normalized = normalize_rank(rank)
        if normalized is not None:
            rank_by_race_player[(race_code, str(player_id))] = normalized
    return exhibitions_by_race, rank_by_race_player


def new_count_bucket():
    return {"total": 0, "win": 0, "place2": 0, "place3": 0, "trio": 0}


def add_rank(bucket: dict, rank: float):
    bucket["total"] += 1
    if rank == 1.0:
        bucket["win"] += 1
    if rank == 2.0:
        bucket["place2"] += 1
    if rank == 3.0:
        bucket["place3"] += 1
    if rank <= 3.0:
        bucket["trio"] += 1


def compute_stats_for_jyo(jyo: str, features: dict):
    feature_cols = features.get(jyo)
    if not feature_cols or len(feature_cols) != 3:
        raise ValueError(f"features.json に場コード {jyo} の3項目設定がありません。")

    conn = connect_db()
    try:
        exhibition_rows = fetch_exhibition_data(conn, jyo)
        result_rows = fetch_result_data(conn, jyo)
    finally:
        conn.close()

    exhibitions_by_race, rank_by_race_player = build_race_dicts(exhibition_rows, result_rows)
    course_counts = {c: new_count_bucket() for c in range(1, 7)}
    interval_course_counts = {
        c: {label: new_count_bucket() for label, _, _ in SUM_INTERVALS}
        for c in range(1, 7)
    }

    processed_races = 0
    skipped_not_6 = 0
    skipped_missing_feature = 0
    skipped_missing_result = 0

    for race_code, boats in exhibitions_by_race.items():
        if len(boats) != 6:
            skipped_not_6 += 1
            continue

        sum_rows = []
        invalid_feature = False
        for boat in boats:
            vals = []
            for col in feature_cols:
                value = boat.get(col)
                if value is None:
                    invalid_feature = True
                    break
                vals.append(float(value))
            if invalid_feature:
                break
            sum_rows.append({
                "player_id": boat["player_id"],
                "entry_course": boat["entry_course"],
                "sum_raw": sum(vals),
            })

        if invalid_feature or len(sum_rows) != 6:
            skipped_missing_feature += 1
            continue

        ranks = {}
        invalid_result = False
        for row in sum_rows:
            key = (race_code, row["player_id"])
            if key not in rank_by_race_player:
                invalid_result = True
                break
            ranks[row["player_id"]] = rank_by_race_player[key]

        if invalid_result or len(ranks) != 6:
            skipped_missing_result += 1
            continue

        avg_sum = sum(row["sum_raw"] for row in sum_rows) / 6.0
        for row in sum_rows:
            course = row["entry_course"]
            rank = ranks[row["player_id"]]
            label = get_sum_interval_label(row["sum_raw"] - avg_sum)
            add_rank(course_counts[course], rank)
            add_rank(interval_course_counts[course][label], rank)
        processed_races += 1

    course_rates = {}
    for c in range(1, 7):
        total = course_counts[c]["total"]
        if total == 0:
            course_rates[c] = {"win": 0.0, "place2": 0.0, "place3": 0.0, "trio": 0.0}
        else:
            course_rates[c] = {
                "win": course_counts[c]["win"] / total,
                "place2": course_counts[c]["place2"] / total,
                "place3": course_counts[c]["place3"] / total,
                "trio": course_counts[c]["trio"] / total,
            }

    stats_for_jyo = {}
    for c in range(1, 7):
        stats_for_jyo[str(c)] = {}
        base = course_rates[c]
        for label, _, _ in SUM_INTERVALS:
            istat = interval_course_counts[c][label]
            total = istat["total"]
            if total == 0:
                stats_for_jyo[str(c)][label] = {"win": 0.0, "place2": 0.0, "place3": 0.0, "trio": 0.0}
                continue
            win = istat["win"] / total
            place2 = istat["place2"] / total
            place3 = istat["place3"] / total
            trio = istat["trio"] / total
            stats_for_jyo[str(c)][label] = {
                "win": round(win - base["win"], 4),
                "place2": round(place2 - base["place2"], 4),
                "place3": round(place3 - base["place3"], 4),
                "trio": round(trio - base["trio"], 4),
            }

    return {
        jyo: stats_for_jyo,
        "_meta": {
            "venue": jyo,
            "features": feature_cols,
            "result_mapping": "race_entry + race_code+player_id",
            "null_rank": 5.5,
            "completed_race_filter": "winner rank=1 exists",
            "processed_races": processed_races,
            "skipped_not_6_exhibition": skipped_not_6,
            "skipped_missing_feature": skipped_missing_feature,
            "skipped_missing_result": skipped_missing_result,
        },
    }


def save_stats_to_json(stats: dict, jyo: str):
    filename = os.path.join(BASE_DIR, f"stats_{jyo}.json")
    with open(filename, "w", encoding="utf-8") as f:
        json.dump(stats, f, ensure_ascii=False, indent=2)


def print_stats_as_json(stats: dict):
    print(json.dumps(stats, ensure_ascii=False, indent=2))


def main():
    parser = ArgumentParser(description="Generate new SUM stats (buff/debuff) for a given stadium.")
    parser.add_argument("jyo", type=str, help="Stadium code (e.g., OMR, TDA, KRY)")
    args = parser.parse_args()
    jyo = args.jyo.upper()
    features = load_features()
    stats = compute_stats_for_jyo(jyo, features)
    save_stats_to_json(stats, jyo)
    print_stats_as_json(stats)


if __name__ == "__main__":
    main()
