# generate_stats_slit.py
# スリットパターン × コース × 着順率 → stats_slit.json / baseline_slit.json / buff_debuff_slit.json を生成

import json
import os
import psycopg2
from classify_slit_pattern import classify_slit_pattern

# ------------------------------------------------------------
# PostgreSQL 接続情報（new_sam.py と統一）
# ------------------------------------------------------------
DB_CONFIG = {
    "host": "192.168.0.208",
    "port": 5432,
    "dbname": "devdb",
    "user": "miyase428",
    "password": "herunia0113",
}

def connect_db():
    return psycopg2.connect(
        host=DB_CONFIG["host"],
        port=DB_CONFIG["port"],
        dbname=DB_CONFIG["dbname"],
        user=DB_CONFIG["user"],
        password=DB_CONFIG["password"],
    )


# ------------------------------------------------------------
# 1. レースデータ読み込み（縦持ち → 6艇まとめ）
# ------------------------------------------------------------
def load_race_data():
    conn = connect_db()
    cur = conn.cursor()

    sql = """
        SELECT
            race_code,
            lane_number,
            start_timing,
            rank
        FROM boat_race.race_result_detail
        WHERE start_timing IS NOT NULL
        ORDER BY race_code, lane_number
    """

    cur.execute(sql)
    rows = cur.fetchall()

    races = []
    current_race = None
    current_code = None

    for race_code, lane, st, rank in rows:
        if race_code != current_code:
            if current_race and all(v is not None for v in current_race["st"]):
                races.append(current_race)

            current_code = race_code
            current_race = {
                "race_code": race_code,
                "st": [None]*6,
                "finish": [None]*6
            }

        idx = lane - 1
        current_race["st"][idx] = float(st)
        current_race["finish"][idx] = int(rank) if rank is not None else None

    if current_race and all(v is not None for v in current_race["st"]):
        races.append(current_race)

    cur.close()
    conn.close()

    return races


# ------------------------------------------------------------
# 2. コース基準着順率（baseline）
# ------------------------------------------------------------
def calc_baseline_rates(races):
    counts = {c: {"win":0, "place2":0, "place3":0, "trio":0, "total":0} for c in range(1,7)}

    for race in races:
        finish = race["finish"]
        for lane, pos in enumerate(finish, start=1):

            counts[lane]["total"] += 1

            if pos is None:
                continue

            if pos == 1:
                counts[lane]["win"] += 1
            if pos == 2:
                counts[lane]["place2"] += 1
            if pos == 3:
                counts[lane]["place3"] += 1
            if pos <= 3:
                counts[lane]["trio"] += 1

    baseline = {}
    for lane in range(1,7):
        t = counts[lane]["total"]
        if t == 0:
            baseline[lane] = {"win":0, "place2":0, "place3":0, "trio":0}
        else:
            baseline[lane] = {
                "win": counts[lane]["win"] / t,
                "place2": counts[lane]["place2"] / t,
                "place3": counts[lane]["place3"] / t,
                "trio": counts[lane]["trio"] / t
            }

    return baseline


# ------------------------------------------------------------
# 3. スリットパターン別着順率（stats）
# ------------------------------------------------------------
def calc_pattern_rates(races):
    counts = {
        pid: {
            lane: {"win":0, "place2":0, "place3":0, "trio":0, "total":0}
            for lane in range(1,7)
        }
        for pid in range(1,13)
    }

    for race in races:
        pid = race["pattern_id"]
        finish = race["finish"]

        for lane, pos in enumerate(finish, start=1):

            counts[pid][lane]["total"] += 1

            if pos is None:
                continue

            if pos == 1:
                counts[pid][lane]["win"] += 1
            if pos == 2:
                counts[pid][lane]["place2"] += 1
            if pos == 3:
                counts[pid][lane]["place3"] += 1
            if pos <= 3:
                counts[pid][lane]["trio"] += 1

    stats = {}
    for pid in range(1,13):
        stats[pid] = {}
        for lane in range(1,7):
            t = counts[pid][lane]["total"]
            if t == 0:
                stats[pid][lane] = {"win":0, "place2":0, "place3":0, "trio":0}
            else:
                stats[pid][lane] = {
                    "win": counts[pid][lane]["win"] / t,
                    "place2": counts[pid][lane]["place2"] / t,
                    "place3": counts[pid][lane]["place3"] / t,
                    "trio": counts[pid][lane]["trio"] / t
                }

    return stats


# ------------------------------------------------------------
# 4. バフデバフ（stats - baseline）
# ------------------------------------------------------------
def calc_buff_debuff(stats, baseline):
    buff = {}

    for pid in range(1, 13):
        buff[pid] = {}
        for lane in range(1, 7):
            buff[pid][lane] = {
                "win": stats[pid][lane]["win"] - baseline[lane]["win"],
                "place2": stats[pid][lane]["place2"] - baseline[lane]["place2"],
                "place3": stats[pid][lane]["place3"] - baseline[lane]["place3"],
                "trio": stats[pid][lane]["trio"] - baseline[lane]["trio"]
            }

    return buff


# ------------------------------------------------------------
# 5. メイン処理（完全統合版）
# ------------------------------------------------------------
def main():

    # venue_slit_settings.json を読み込む
    base_path = os.path.dirname(__file__)
    settings_path = os.path.join(base_path, "venue_slit_settings.json")

    with open(settings_path, "r", encoding="utf-8") as f:
        venue_settings = json.load(f)

    settings = venue_settings["default"]

    # レースデータ読み込み
    races = load_race_data()

    # パターンID付与
    for race in races:
        race["pattern_id"] = classify_slit_pattern(race["st"], settings)

    # 基準着順率
    baseline = calc_baseline_rates(races)

    # パターン別着順率
    stats = calc_pattern_rates(races)

    # バフデバフ
    buff = calc_buff_debuff(stats, baseline)

    # JSON 出力
    out_stats = os.path.join(base_path, "stats_slit.json")
    out_base = os.path.join(base_path, "baseline_slit.json")
    out_buff = os.path.join(base_path, "buff_debuff_slit.json")

    with open(out_stats, "w", encoding="utf-8") as f:
        json.dump(stats, f, indent=2, ensure_ascii=False)

    with open(out_base, "w", encoding="utf-8") as f:
        json.dump(baseline, f, indent=2, ensure_ascii=False)

    with open(out_buff, "w", encoding="utf-8") as f:
        json.dump(buff, f, indent=2, ensure_ascii=False)

    print("stats_slit.json / baseline_slit.json / buff_debuff_slit.json を生成しました。")


if __name__ == "__main__":
    main()
