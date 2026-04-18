# predict_pattern.py
# race_code を渡すと、予測用スリットパターンIDを返す

import psycopg2
import json
import sys
from classify_slit_pattern import classify_slit_pattern

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
# ① race_code から出走表（選手ID・展示ST）を取得
#    出走表：race_result_detail
#    展示ST：exhibition_live.start_timing
# ------------------------------------------------------------
def load_entry_from_race_code(race_code):
    conn = connect_db()
    cur = conn.cursor()

    sql = """
        SELECT
            entry_course AS lane_number,
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

    player_ids = []
    ex_st = []

    for lane, pid, ex in rows:
        player_ids.append(pid)
        ex_st.append(float(ex) if ex is not None else 0.0)

    return player_ids, ex_st


# ------------------------------------------------------------
# ② 選手ごとの「展示→本番ST補正」を6人だけ計算
#    本番ST：race_result_detail.start_timing
#    展示ST：exhibition_live.start_timing
# ------------------------------------------------------------
def get_st_correction_for_players(player_ids):
    conn = connect_db()
    cur = conn.cursor()

    correction = {}

    for pid in player_ids:
        sql = """
            SELECT
                r.start_timing AS real_st,
                e.start_timing AS ex_st
            FROM boat_race.race_result_detail r
            JOIN boat_race.exhibition_live e
              ON r.race_code = e.race_code
             AND r.lane_number = e.entry_course
            WHERE r.player_id = %s
              AND r.start_timing IS NOT NULL
              AND e.start_timing IS NOT NULL
        """

        cur.execute(sql, (pid,))
        rows = cur.fetchall()

        if len(rows) == 0:
            # 本番ST or 展示ST が一度も揃っていない選手 → 補正 0
            correction[pid] = 0.0
            continue

        deltas = []
        for real_st, ex_st in rows:
            deltas.append(float(real_st) - float(ex_st))

        avg_delta = sum(deltas) / len(deltas)
        correction[pid] = avg_delta

    cur.close()
    conn.close()

    return correction


# ------------------------------------------------------------
# ③ 予測本番STを作る（展示ST + 補正値）
# ------------------------------------------------------------
def make_predicted_st(ex_st, player_ids, correction):
    predicted = []
    for i in range(6):
        pid = player_ids[i]
        delta = correction.get(pid, 0.0)
        predicted.append(ex_st[i] + delta)
    return predicted


# ------------------------------------------------------------
# ④ パターンIDを算出
# ------------------------------------------------------------
def predict_pattern(race_code):
    base_path = sys.path[0]
    settings_path = base_path + "/venue_slit_settings.json"

    with open(settings_path, "r", encoding="utf-8") as f:
        venue_settings = json.load(f)

    # まずは default を使う（将来は場別に切り替えも可）
    settings = venue_settings["default"]

    # 出走表＋展示ST
    player_ids, ex_st = load_entry_from_race_code(race_code)

    # 選手ごとの展示→本番ST補正
    correction = get_st_correction_for_players(player_ids)

    # 補正後の「予測本番ST」
    predicted_st = make_predicted_st(ex_st, player_ids, correction)

    # スリットパターンID
    pattern_id = classify_slit_pattern(predicted_st, settings)

    return {
        "race_code": race_code,
        "player_ids": player_ids,
        "exhibition_st": ex_st,
        "correction": correction,
        "predicted_st": predicted_st,
        "pattern_id": pattern_id
    }


# ------------------------------------------------------------
# ⑤ メイン
# ------------------------------------------------------------
if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Usage: python predict_pattern.py <race_code>")
        sys.exit(1)

    race_code = sys.argv[1]
    result = predict_pattern(race_code)

    print(json.dumps(result, indent=2, ensure_ascii=False))
