# generate_stats_slit.py
# スリットパターン × コース × 着順率 → stats_slit.json / baseline_slit.json / buff_debuff_slit.json を生成
# （サンプル数に応じたベイズ平滑化 ＆ キャップ処理を搭載）

import json
import os
import sys
from pathlib import Path

import psycopg2
from classify_slit_pattern import classify_slit_pattern

REPO_ROOT = Path(__file__).resolve().parents[2]
if str(REPO_ROOT) not in sys.path:
    sys.path.insert(0, str(REPO_ROOT))

from common.db_config import load_db_config

DB_CONFIG = load_db_config()


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
# 3. スリットパターン別着順率（stats）＆生カウント保持
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

    return stats, counts


# ------------------------------------------------------------
# 4. バフデバフ（ベイズ平滑化 ＆ 上下限キャップ付き）
# ------------------------------------------------------------
def calc_buff_debuff(stats, baseline, counts_total):
    buff = {}

    K = 40
    MAX_CAP = 0.08

    for pid in range(1, 13):
        buff[pid] = {}
        for lane in range(1, 7):
            n = counts_total[pid][lane]["total"]
            shrinkage_weight = n / (n + K)

            raw_win = stats[pid][lane]["win"] - baseline[lane]["win"]
            raw_place2 = stats[pid][lane]["place2"] - baseline[lane]["place2"]
            raw_place3 = stats[pid][lane]["place3"] - baseline[lane]["place3"]
            raw_trio = stats[pid][lane]["trio"] - baseline[lane]["trio"]

            shrunk_win = raw_win * shrinkage_weight
            shrunk_place2 = raw_place2 * shrinkage_weight
            shrunk_place3 = raw_place3 * shrinkage_weight
            shrunk_trio = raw_trio * shrinkage_weight

            buff[pid][lane] = {
                "win": max(-MAX_CAP, min(MAX_CAP, shrunk_win)),
                "place2": max(-MAX_CAP, min(MAX_CAP, shrunk_place2)),
                "place3": max(-MAX_CAP, min(MAX_CAP, shrunk_place3)),
                "trio": max(-MAX_CAP, min(MAX_CAP, shrunk_trio))
            }

    return buff


# ------------------------------------------------------------
# 5. メイン処理（完全統合版）
# ------------------------------------------------------------
def main():
    base_path = os.path.dirname(__file__)
    settings_path = os.path.join(base_path, "venue_slit_settings.json")

    with open(settings_path, "r", encoding="utf-8") as f:
        venue_settings = json.load(f)

    settings = venue_settings["default"]

    print("レースデータを読み込んでいます...")
    races = load_race_data()

    print("スリットパターンを分類しています...")
    for race in races:
        race["pattern_id"], _ = classify_slit_pattern(race["st"], settings)

    baseline = calc_baseline_rates(races)
    stats, counts_total = calc_pattern_rates(races)
    buff = calc_buff_debuff(stats, baseline, counts_total)

    out_stats = os.path.join(base_path, "stats_slit.json")
    out_base = os.path.join(base_path, "baseline_slit.json")
    out_buff = os.path.join(base_path, "buff_debuff_slit.json")

    with open(out_stats, "w", encoding="utf-8") as f:
        json.dump(stats, f, indent=2, ensure_ascii=False)

    with open(out_base, "w", encoding="utf-8") as f:
        json.dump(baseline, f, indent=2, ensure_ascii=False)

    with open(out_buff, "w", encoding="utf-8") as f:
        json.dump(buff, f, indent=2, ensure_ascii=False)

    print("修正完了: stats_slit.json / baseline_slit.json / buff_debuff_slit.json を生成しました。")


if __name__ == "__main__":
    main()
