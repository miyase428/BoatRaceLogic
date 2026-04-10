import sys
import json
import psycopg2
from collections import defaultdict
from argparse import ArgumentParser

# PostgreSQL 接続情報
DB_CONFIG = {
    "host": "192.168.0.208",
    "port": 5432,
    "dbname": "devdb",
    "user": "miyase428",
    "password": "herunia0113",
}

# sum 区間定義（ラベルと境界）
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
    """features.json を読み込む"""
    with open(path, "r", encoding="utf-8") as f:
        return json.load(f)


def get_sum_interval_label(value: float) -> str:
    """sum の差分値を区間ラベルに変換"""
    for label, low, high in SUM_INTERVALS:
        if low <= value < high:
            return label
    return "unknown"


def connect_db():
    """PostgreSQL に接続"""
    return psycopg2.connect(
        host=DB_CONFIG["host"],
        port=DB_CONFIG["port"],
        dbname=DB_CONFIG["dbname"],
        user=DB_CONFIG["user"],
        password=DB_CONFIG["password"],
    )


def fetch_exhibition_data(conn, jyo: str):
    """展示データ取得"""
    sql = """
        SELECT
            race_code,
            entry_course,
            exhibition_time,
            lap_time,
            around_time,
            straight_time
        FROM boat_race.exhibition_live
        WHERE SUBSTRING(race_code, 9, 3) = %s
    """
    cur = conn.cursor()
    cur.execute(sql, (jyo,))
    rows = cur.fetchall()
    cur.close()
    return rows


def fetch_result_data(conn, jyo: str):
    """レース結果データ取得"""
    sql = """
        SELECT
            race_code,
            entry_course,
            rank
        FROM boat_race.race_result_detail
        WHERE SUBSTRING(race_code, 9, 3) = %s
    """
    cur = conn.cursor()
    cur.execute(sql, (jyo,))
    rows = cur.fetchall()
    cur.close()
    return rows


def build_race_dicts(exhibition_rows, result_rows):
    """展示データと結果データを race_code 単位に整理"""
    exhibitions_by_race = defaultdict(list)
    for race_code, entry_course, ex_time, lap, around, straight in exhibition_rows:
        exhibitions_by_race[race_code].append(
            {
                "race_code": race_code,
                "entry_course": int(entry_course),
                "exhibition_time": float(ex_time) if ex_time is not None else None,
                "lap_time": float(lap) if lap is not None else None,
                "around_time": float(around) if around is not None else None,
                "straight_time": float(straight) if straight is not None else None,
            }
        )

    rank_by_race_course = {}
    for race_code, entry_course, rank in result_rows:
        key = (race_code, int(entry_course))
        rank_by_race_course[key] = int(rank) if rank is not None else None

    return exhibitions_by_race, rank_by_race_course


def compute_stats_for_jyo(jyo: str, features: dict):
    """指定場の統計を計算して stats 構造を返す"""

    feature_cols = features.get(jyo)
    if not feature_cols:
        raise ValueError(f"features.json に場コード {jyo} の設定がありません。")

    conn = connect_db()
    try:
        exhibition_rows = fetch_exhibition_data(conn, jyo)
        result_rows = fetch_result_data(conn, jyo)
    finally:
        conn.close()

    exhibitions_by_race, rank_by_race_course = build_race_dicts(
        exhibition_rows, result_rows
    )

    # コース基準カウンタ（win, 2着, 3着, 3連対）
    course_counts = {
        c: {"total": 0, "win": 0, "place2": 0, "place3": 0, "trio": 0}
        for c in range(1, 7)
    }

    # 区間 × コースのカウンタ
    interval_course_counts = {
        c: {
            label: {"total": 0, "win": 0, "place2": 0, "place3": 0, "trio": 0}
            for (label, _, _) in SUM_INTERVALS
        }
        for c in range(1, 7)
    }

    # レース単位で sum_raw → avg_sum → 差分 sum を計算
    for race_code, boats in exhibitions_by_race.items():
        if not boats:
            continue

        sum_raw_list = []
        for b in boats:
            try:
                vals = [float(b[col]) for col in feature_cols if b[col] is not None]
            except KeyError:
                continue

            if len(vals) != len(feature_cols):
                continue

            sum_raw = sum(vals)
            sum_raw_list.append((b["entry_course"], sum_raw))

        if not sum_raw_list:
            continue

        avg_sum = sum(v for _, v in sum_raw_list) / len(sum_raw_list)

        for entry_course, sum_raw in sum_raw_list:
            key = (race_code, entry_course)
            rank = rank_by_race_course.get(key)
            if rank is None:
                continue

            sum_diff = sum_raw - avg_sum
            interval_label = get_sum_interval_label(sum_diff)

            # コース基準カウンタ
            cstat = course_counts[entry_course]
            cstat["total"] += 1
            if rank == 1:
                cstat["win"] += 1
            if rank == 2:
                cstat["place2"] += 1
            if rank == 3:
                cstat["place3"] += 1
            if rank <= 3:
                cstat["trio"] += 1

            # 区間別カウンタ
            istat = interval_course_counts[entry_course][interval_label]
            istat["total"] += 1
            if rank == 1:
                istat["win"] += 1
            if rank == 2:
                istat["place2"] += 1
            if rank == 3:
                istat["place3"] += 1
            if rank <= 3:
                istat["trio"] += 1

    # コース基準着順率
    course_rates = {}
    for c in range(1, 7):
        total = course_counts[c]["total"]
        if total == 0:
            course_rates[c] = {"win": 0.0, "place2": 0.0, "place3": 0.0, "trio": 0.0}
            continue

        win = course_counts[c]["win"] / total
        place2 = course_counts[c]["place2"] / total
        place3 = course_counts[c]["place3"] / total
        trio = course_counts[c]["trio"] / total  # 3連対率

        course_rates[c] = {
            "win": win,
            "place2": place2,
            "place3": place3,
            "trio": trio,
        }

    # バフデバフ計算
    stats_for_jyo = {}
    for c in range(1, 7):
        stats_for_jyo[str(c)] = {}
        base = course_rates[c]

        for label, _, _ in SUM_INTERVALS:
            istat = interval_course_counts[c][label]
            total = istat["total"]

            if total == 0:
                stats_for_jyo[str(c)][label] = {
                    "win": 0.0,
                    "place2": 0.0,
                    "place3": 0.0,
                    "trio": 0.0,
                }
                continue

            win = istat["win"] / total
            place2 = istat["place2"] / total
            place3 = istat["place3"] / total
            trio = istat["trio"] / total  # 3連対率

            stats_for_jyo[str(c)][label] = {
                "win": round(win - base["win"], 4),
                "place2": round(place2 - base["place2"], 4),
                "place3": round(place3 - base["place3"], 4),
                "trio": round(trio - base["trio"], 4),
            }

    return {jyo: stats_for_jyo}


def save_stats_to_json(stats: dict, jyo: str):
    filename = f"stats_{jyo}.json"
    with open(filename, "w", encoding="utf-8") as f:
        json.dump(stats, f, ensure_ascii=False, indent=2)


def print_stats_as_json(stats: dict):
    print(json.dumps(stats, ensure_ascii=False, indent=2))


def main():
    parser = ArgumentParser(description="Generate new SAM stats (buff/debuff) for a given stadium.")
    parser.add_argument("jyo", type=str, help="Stadium code (e.g., OMR, TDA, KRY)")
    args = parser.parse_args()

    jyo = args.jyo
    features = load_features()

    stats = compute_stats_for_jyo(jyo, features)
    save_stats_to_json(stats, jyo)
    print_stats_as_json(stats)


if __name__ == "__main__":
    main()
