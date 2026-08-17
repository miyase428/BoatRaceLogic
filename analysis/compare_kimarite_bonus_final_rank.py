#!/usr/bin/env python3
import csv
import math
import sys
from collections import Counter, defaultdict
from datetime import datetime
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from kimarite_bonus_validate import load_historical_kimarite, decide_type
from slit_validate_v2 import connect_db


def as_float(v, default=None):
    if v is None:
        return default
    s = str(v).strip()
    if s == "":
        return default
    try:
        x = float(s)
    except (TypeError, ValueError):
        return default
    return x if math.isfinite(x) else default


def load_csv(path):
    races = defaultdict(list)
    min_date = None
    max_date = None

    with open(path, "r", encoding="utf-8-sig", newline="") as f:
        reader = csv.DictReader(f)
        required = {
            "race_code", "race_date", "lane_number",
            "first_total_score", "second_score", "actual_rank",
        }
        missing = required - set(reader.fieldnames or [])
        if missing:
            raise RuntimeError(f"CSVに必要列がありません: {sorted(missing)}")

        for row in reader:
            race_code = str(row["race_code"]).strip()
            if not race_code:
                continue
            races[race_code].append(row)

            d = str(row["race_date"]).strip()
            if d:
                min_date = d if min_date is None or d < min_date else min_date
                max_date = d if max_date is None or d > max_date else max_date

    if not races:
        raise RuntimeError("CSVにレースがありません")
    if not min_date or not max_date:
        raise RuntimeError("race_dateを取得できません")

    datetime.strptime(min_date, "%Y-%m-%d")
    datetime.strptime(max_date, "%Y-%m-%d")
    return races, min_date, max_date


def load_entry_players(start_date, end_date):
    sql = """
        SELECT re.race_code, re.lane_number, re.player_id::text
        FROM boat_race.race_entry re
        JOIN boat_race.race_master rm
          ON rm.race_code = re.race_code
        WHERE rm.race_date BETWEEN %s::date AND %s::date
          AND re.lane_number BETWEEN 1 AND 6
    """
    out = {}
    with connect_db() as conn:
        with conn.cursor() as cur:
            cur.execute(sql, (start_date, end_date))
            for race_code, lane, player_id in cur.fetchall():
                out[(str(race_code), int(lane))] = str(player_id).strip()
    return out


def build_finish(rows_by_lane):
    numeric = {}
    for lane in range(1, 7):
        r = as_float(rows_by_lane[lane].get("actual_rank"))
        if r is not None and 1 <= r <= 6:
            numeric[lane] = r

    top4 = sorted(r for r in numeric.values() if r in {1.0, 2.0, 3.0, 4.0})
    if top4 != [1.0, 2.0, 3.0, 4.0]:
        return None

    finish = {}
    for lane in range(1, 7):
        finish[lane] = numeric.get(lane, 5.5)
    return finish


def sort_lanes(score_map):
    return sorted(range(1, 7), key=lambda lane: (-score_map[lane], lane))


def apply_step4(score_map, first_scores, second_scores):
    rank_boats = sort_lanes(score_map)
    primary = sort_lanes(first_scores)
    secondary = sort_lanes(second_scores)

    primary_gap = first_scores[primary[0]] - first_scores[primary[1]]
    second_gap = second_scores[secondary[0]] - second_scores[secondary[1]]

    override_condition = (
        5.0 <= primary_gap < 10.0
        and 1.0 <= second_gap < 2.0
    )

    if override_condition:
        primary1 = primary[0]
        if rank_boats[0] != primary1:
            rank_boats = [primary1] + [b for b in rank_boats if b != primary1]

    return rank_boats, override_condition


def blank_head_stat():
    return {"n": 0, "win": 0, "top2": 0, "top3": 0, "rank_sum": 0.0}


def add_head(stat, rank):
    stat["n"] += 1
    stat["win"] += int(rank == 1.0)
    stat["top2"] += int(rank <= 2.0)
    stat["top3"] += int(rank <= 3.0)
    stat["rank_sum"] += rank


def summarize_head(stat):
    n = stat["n"] or 1
    return {
        "win": stat["win"] / n,
        "top2": stat["top2"] / n,
        "top3": stat["top3"] / n,
        "avg_rank": stat["rank_sum"] / n,
    }


def pct(v):
    return f"{v * 100:6.2f}%"


def pp(v):
    return f"{v * 100:+6.2f}pt"


def main():
    if len(sys.argv) != 2:
        print("Usage: python3 analysis/compare_kimarite_bonus_final_rank.py analysis/output/final_prediction_boats_YYYYMMDD_YYYYMMDD.csv")
        sys.exit(1)

    csv_path = Path(sys.argv[1])
    if not csv_path.exists():
        raise RuntimeError(f"CSVがありません: {csv_path}")

    races, start_date, end_date = load_csv(csv_path)

    print("レース日基準の決まり手履歴を集計しています...")
    hist = load_historical_kimarite(start_date, end_date)
    entry_players = load_entry_players(start_date, end_date)

    stats = {
        "CURRENT_PM1": blank_head_stat(),
        "NO_BONUS": blank_head_stat(),
    }
    pos_stats = {
        key: {p: blank_head_stat() for p in range(1, 7)}
        for key in stats
    }
    winner_capture = {
        key: {1: 0, 2: 0, 3: 0}
        for key in stats
    }
    top3_overlap_sum = {key: 0 for key in stats}
    mae_sum = {key: 0.0 for key in stats}

    skip = Counter()
    bonus_dist = Counter()
    type_dist = Counter()
    processed = 0
    any_order_changed = 0
    head_changed = 0
    head_h2h = Counter()
    win_switch = Counter()
    top3_switch = Counter()
    override_count = 0

    for race_code in sorted(races):
        raw_rows = races[race_code]
        if len(raw_rows) != 6:
            skip["not_6_csv_rows"] += 1
            continue

        rows = {}
        invalid = False
        for row in raw_rows:
            lane_f = as_float(row.get("lane_number"))
            if lane_f is None:
                invalid = True
                break
            lane = int(lane_f)
            if lane not in range(1, 7) or lane in rows:
                invalid = True
                break
            rows[lane] = row
        if invalid or set(rows) != set(range(1, 7)):
            skip["bad_lane_rows"] += 1
            continue

        finish = build_finish(rows)
        if finish is None:
            skip["bad_result"] += 1
            continue

        first_scores = {}
        second_scores = {}
        bonus_by_lane = {}
        missing = False

        for lane in range(1, 7):
            first = as_float(rows[lane].get("first_total_score"))
            second = as_float(rows[lane].get("second_score"))
            if first is None or second is None:
                missing = True
                break

            pid = str(rows[lane].get("player_id") or "").strip()
            if not pid:
                pid = entry_players.get((race_code, lane), "")
            if not pid:
                missing = True
                break

            h = hist.get((race_code, pid))
            if h is None:
                missing = True
                break

            typ, bonus = decide_type(h["course"], h["rates"])
            first_scores[lane] = first
            second_scores[lane] = second
            bonus_by_lane[lane] = bonus
            bonus_dist[bonus] += 1
            type_dist[typ] += 1

        if missing:
            skip["missing_score_or_history"] += 1
            continue

        score_current = {
            lane: second_scores[lane] + bonus_by_lane[lane]
            for lane in range(1, 7)
        }
        score_no = dict(second_scores)

        rank_current, ov1 = apply_step4(score_current, first_scores, second_scores)
        rank_no, ov0 = apply_step4(score_no, first_scores, second_scores)
        if ov1 or ov0:
            override_count += 1

        processed += 1
        if rank_current != rank_no:
            any_order_changed += 1

        variants = {
            "CURRENT_PM1": rank_current,
            "NO_BONUS": rank_no,
        }

        actual_winner = next(l for l, r in finish.items() if r == 1.0)
        actual_top3 = {l for l, r in finish.items() if r <= 3.0}

        for key, ranking in variants.items():
            head = ranking[0]
            add_head(stats[key], finish[head])

            pred_rank = {lane: idx + 1 for idx, lane in enumerate(ranking)}
            mae_sum[key] += sum(
                abs(pred_rank[lane] - finish[lane]) for lane in range(1, 7)
            ) / 6.0

            for pos, lane in enumerate(ranking, start=1):
                add_head(pos_stats[key][pos], finish[lane])

            for k in (1, 2, 3):
                if actual_winner in ranking[:k]:
                    winner_capture[key][k] += 1

            top3_overlap_sum[key] += len(set(ranking[:3]) & actual_top3)

        head_cur = rank_current[0]
        head_no = rank_no[0]
        if head_cur != head_no:
            head_changed += 1
            r_cur = finish[head_cur]
            r_no = finish[head_no]
            if r_cur < r_no:
                head_h2h["CURRENT_better"] += 1
            elif r_no < r_cur:
                head_h2h["NO_BONUS_better"] += 1
            else:
                head_h2h["tie"] += 1

            if r_cur == 1.0 and r_no != 1.0:
                win_switch["CURRENT_gain"] += 1
            elif r_no == 1.0 and r_cur != 1.0:
                win_switch["NO_BONUS_gain"] += 1

            if r_cur <= 3.0 and r_no > 3.0:
                top3_switch["CURRENT_gain"] += 1
            elif r_no <= 3.0 and r_cur > 3.0:
                top3_switch["NO_BONUS_gain"] += 1

    if processed == 0:
        raise RuntimeError("比較できるレースが0件です")

    cur = summarize_head(stats["CURRENT_PM1"])
    nob = summarize_head(stats["NO_BONUS"])

    print("=" * 118)
    print("決まり手補正 最終順位比較（現在±1 vs 補正なし）")
    print("=" * 118)
    print(f"CSV          : {csv_path}")
    print(f"期間         : {start_date} ～ {end_date}")
    print(f"処理レース   : {processed}")
    print("typeBonus    : 各レース当時の直前6ヶ月で再計算")
    print("比較条件     : 一次/二次/展開もらいは固定、決まり手typeBonusだけON/OFF")
    print("STEP4        : 両方式とも現行の一次優勢昇格ルールを再適用")
    print("本番変更     : なし")

    print("\n【skip】")
    for k in ["not_6_csv_rows", "bad_lane_rows", "bad_result", "missing_score_or_history"]:
        print(f"{k:<30}: {skip[k]}")

    print("\n【決まり手分類件数】")
    print(f"+1: {bonus_dist[1]:>7}   0: {bonus_dist[0]:>7}   -1: {bonus_dist[-1]:>7}")
    for typ in ["逃げ型", "差し型", "攻め型", "脆い型", "無色"]:
        print(f"{typ:<6}: {type_dist[typ]:>7}")

    print("\n【最終1位の実成績】")
    print("方式             1着      2連対      3連対    平均着順")
    print(
        f"現在±1       {pct(cur['win'])}  {pct(cur['top2'])}  {pct(cur['top3'])}    {cur['avg_rank']:.3f}"
    )
    print(
        f"補正なし     {pct(nob['win'])}  {pct(nob['top2'])}  {pct(nob['top3'])}    {nob['avg_rank']:.3f}"
    )
    print(
        f"差(現在-なし) {pp(cur['win']-nob['win'])} / {pp(cur['top2']-nob['top2'])} / "
        f"{pp(cur['top3']-nob['top3'])} / {cur['avg_rank']-nob['avg_rank']:+.3f}着"
    )

    print("\n【順位全体の品質】")
    print("方式          勝者TOP1   勝者TOP2   勝者TOP3   予想TOP3内の実TOP3平均数   順位MAE")
    for key, label in [("CURRENT_PM1", "現在±1"), ("NO_BONUS", "補正なし")]:
        print(
            f"{label:<10}  {pct(winner_capture[key][1]/processed)}  "
            f"{pct(winner_capture[key][2]/processed)}  {pct(winner_capture[key][3]/processed)}  "
            f"{top3_overlap_sum[key]/processed:.3f}/3                  {mae_sum[key]/processed:.3f}"
        )

    print("\n【予想順位別の実成績】")
    print("順位 | 現在±1: 1着/3連/平均着順       | 補正なし: 1着/3連/平均着順")
    for pos in range(1, 7):
        a = summarize_head(pos_stats["CURRENT_PM1"][pos])
        b = summarize_head(pos_stats["NO_BONUS"][pos])
        print(
            f" {pos}位 | {pct(a['win'])}/{pct(a['top3'])}/{a['avg_rank']:.3f}"
            f"       | {pct(b['win'])}/{pct(b['top3'])}/{b['avg_rank']:.3f}"
        )

    print("\n【順位が実際に変わったレース】")
    print(f"全順位の並びが変化 : {any_order_changed}/{processed} ({any_order_changed/processed*100:.2f}%)")
    print(f"最終1位が変化       : {head_changed}/{processed} ({head_changed/processed*100:.2f}%)")
    print(f"STEP4条件該当       : {override_count}/{processed} ({override_count/processed*100:.2f}%)")

    if head_changed:
        print("\n【最終1位が変わったレースだけ直接対決】")
        print(
            f"実着順で現在±1が上 : {head_h2h['CURRENT_better']:>5} "
            f"({head_h2h['CURRENT_better']/head_changed*100:.2f}%)"
        )
        print(
            f"補正なしが上        : {head_h2h['NO_BONUS_better']:>5} "
            f"({head_h2h['NO_BONUS_better']/head_changed*100:.2f}%)"
        )
        print(
            f"同着扱い            : {head_h2h['tie']:>5} "
            f"({head_h2h['tie']/head_changed*100:.2f}%)"
        )
        print(
            f"1着を拾えた増減     : 現在+ {win_switch['CURRENT_gain']} / 補正なし+ {win_switch['NO_BONUS_gain']}"
        )
        print(
            f"3連対を拾えた増減   : 現在+ {top3_switch['CURRENT_gain']} / 補正なし+ {top3_switch['NO_BONUS_gain']}"
        )

    print("\n【判定の見方】")
    print("・現在±1の最終1位成績と順位全体品質が補正なし以上なら、±1を残す根拠になる")
    print("・特に『最終1位が変わったレースだけ』で現在±1が勝つかを見る")
    print("・ここでは±0.5等へ調整しない。まず現行±1が0補正より有効かだけ判定する")
    print("=" * 118)


if __name__ == "__main__":
    main()
