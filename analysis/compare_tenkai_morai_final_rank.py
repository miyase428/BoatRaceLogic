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
            "first_total_score", "second_score",
            "three_in_rate_6m", "three_in_rate_3m",
            "actual_rank",
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

    return {lane: numeric.get(lane, 5.5) for lane in range(1, 7)}


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


def median6(values):
    s = sorted(values)
    return (s[2] + s[3]) / 2.0


def make_kiru(score_map, rate6, rate3, get_bonus_map):
    med = median6(list(score_map.values()))
    kiru = set()
    for lane in range(1, 7):
        if (
            get_bonus_map[lane] == 0
            and score_map[lane] < med
            and (rate6[lane] < 0.5 or rate3[lane] < 0.5)
        ):
            kiru.add(lane)
    return kiru


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
        print(
            "Usage: python3 analysis/compare_tenkai_morai_final_rank.py "
            "analysis/output/final_prediction_boats_YYYYMMDD_YYYYMMDD.csv"
        )
        sys.exit(1)

    csv_path = Path(sys.argv[1])
    if not csv_path.exists():
        raise RuntimeError(f"CSVがありません: {csv_path}")

    races, start_date, end_date = load_csv(csv_path)

    print("レース日基準の決まり手履歴を集計しています...")
    hist = load_historical_kimarite(start_date, end_date)
    entry_players = load_entry_players(start_date, end_date)

    stats = {
        "CURRENT_24": blank_head_stat(),
        "NO_TENKAI": blank_head_stat(),
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
    processed = 0
    any_order_changed = 0
    head_changed = 0
    head_h2h = Counter()
    win_switch = Counter()
    top3_switch = Counter()
    override = {"current": 0, "none": 0, "different": 0}

    kiru_total = {"current": 0, "none": 0}
    kiru_races = {"current": 0, "none": 0}
    winner_cut = {"current": 0, "none": 0}
    top3_cut_boats = {"current": 0, "none": 0}
    races_top3_cut = {"current": 0, "none": 0}
    cut_changed_races = 0

    protection_only_count = 0
    protection_only_lane = Counter()
    protection_only_outcome = blank_head_stat()

    newly_cut_24_count = 0
    newly_cut_24_lane = Counter()
    newly_cut_24_outcome = blank_head_stat()

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
        base_second = {}
        current_second = {}
        type_bonus = {}
        rate6 = {}
        rate3 = {}
        missing = False

        for lane in range(1, 7):
            first = as_float(rows[lane].get("first_total_score"))
            second = as_float(rows[lane].get("second_score"))
            r6 = as_float(rows[lane].get("three_in_rate_6m"))
            r3 = as_float(rows[lane].get("three_in_rate_3m"))
            if first is None or second is None or r6 is None or r3 is None:
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

            _, bonus = decide_type(h["course"], h["rates"])

            fixed_bonus = 1.0 if lane in (2, 4) else 0.0
            first_scores[lane] = first
            current_second[lane] = second
            base_second[lane] = second - fixed_bonus
            type_bonus[lane] = float(bonus)
            rate6[lane] = r6
            rate3[lane] = r3

        if missing:
            skip["missing_score_rate_or_history"] += 1
            continue

        score_current = {
            lane: current_second[lane] + type_bonus[lane]
            for lane in range(1, 7)
        }
        score_none = {
            lane: base_second[lane] + type_bonus[lane]
            for lane in range(1, 7)
        }

        rank_current, ov_cur = apply_step4(
            score_current, first_scores, current_second
        )
        rank_none, ov_none = apply_step4(
            score_none, first_scores, base_second
        )

        override["current"] += int(ov_cur)
        override["none"] += int(ov_none)
        override["different"] += int(ov_cur != ov_none)

        get_current = {lane: (1 if lane in (2, 4) else 0) for lane in range(1, 7)}
        get_none = {lane: 0 for lane in range(1, 7)}

        kiru_current = make_kiru(score_current, rate6, rate3, get_current)
        kiru_none = make_kiru(score_none, rate6, rate3, get_none)

        # 保護だけの効果を分離: スコアは現行のまま、getBonus条件だけ0にする
        kiru_current_no_protect = make_kiru(score_current, rate6, rate3, get_none)
        protected_only = kiru_current_no_protect - kiru_current
        for lane in sorted(protected_only):
            if lane in (2, 4):
                protection_only_count += 1
                protection_only_lane[lane] += 1
                add_head(protection_only_outcome, finish[lane])

        newly_cut_24 = {
            lane for lane in (2, 4)
            if lane in kiru_none and lane not in kiru_current
        }
        for lane in sorted(newly_cut_24):
            newly_cut_24_count += 1
            newly_cut_24_lane[lane] += 1
            add_head(newly_cut_24_outcome, finish[lane])

        processed += 1
        if rank_current != rank_none:
            any_order_changed += 1
        if kiru_current != kiru_none:
            cut_changed_races += 1

        variants = {
            "CURRENT_24": rank_current,
            "NO_TENKAI": rank_none,
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

        for name, cuts in [("current", kiru_current), ("none", kiru_none)]:
            kiru_total[name] += len(cuts)
            kiru_races[name] += int(bool(cuts))
            winner_cut[name] += int(actual_winner in cuts)
            top3_cut_boats[name] += len(cuts & actual_top3)
            races_top3_cut[name] += int(bool(cuts & actual_top3))

        head_cur = rank_current[0]
        head_no = rank_none[0]
        if head_cur != head_no:
            head_changed += 1
            r_cur = finish[head_cur]
            r_no = finish[head_no]
            if r_cur < r_no:
                head_h2h["CURRENT_better"] += 1
            elif r_no < r_cur:
                head_h2h["NO_TENKAI_better"] += 1
            else:
                head_h2h["tie"] += 1

            if r_cur == 1.0 and r_no != 1.0:
                win_switch["CURRENT_gain"] += 1
            elif r_no == 1.0 and r_cur != 1.0:
                win_switch["NO_TENKAI_gain"] += 1

            if r_cur <= 3.0 and r_no > 3.0:
                top3_switch["CURRENT_gain"] += 1
            elif r_no <= 3.0 and r_cur > 3.0:
                top3_switch["NO_TENKAI_gain"] += 1

    if processed == 0:
        raise RuntimeError("比較できるレースが0件です")

    cur = summarize_head(stats["CURRENT_24"])
    nob = summarize_head(stats["NO_TENKAI"])
    prot = summarize_head(protection_only_outcome)
    newcut = summarize_head(newly_cut_24_outcome)

    print("=" * 120)
    print("展開もらい 健康診断（現行: 2・4号艇+1 vs 全艇0）")
    print("=" * 120)
    print(f"CSV          : {csv_path}")
    print(f"期間         : {start_date} ～ {end_date}")
    print(f"処理レース   : {processed}")
    print("決まり手補正 : 現行±1を維持（各レース当時の直前6ヶ月で再計算）")
    print("現行         : 2号艇+1 / 4号艇+1、かつ2・4はgetBonus条件で切る艇から保護")
    print("比較         : 全艇+0、getBonus=0として切る艇も再計算")
    print("STEP4        : 各方式の二次スコアで現行の一次優勢昇格ルールを再適用")
    print("本番変更     : なし")

    print("\n【skip】")
    for k in [
        "not_6_csv_rows", "bad_lane_rows", "bad_result",
        "missing_score_rate_or_history",
    ]:
        print(f"{k:<32}: {skip[k]}")

    print("\n【最終1位の実成績：+1点の順位効果】")
    print("方式             1着      2連対      3連対    平均着順")
    print(
        f"現行2・4+1   {pct(cur['win'])}  {pct(cur['top2'])}  {pct(cur['top3'])}    {cur['avg_rank']:.3f}"
    )
    print(
        f"展開もらい0  {pct(nob['win'])}  {pct(nob['top2'])}  {pct(nob['top3'])}    {nob['avg_rank']:.3f}"
    )
    print(
        f"差(現行-0)   {pp(cur['win']-nob['win'])} / {pp(cur['top2']-nob['top2'])} / "
        f"{pp(cur['top3']-nob['top3'])} / {cur['avg_rank']-nob['avg_rank']:+.3f}着"
    )

    print("\n【順位全体の品質】")
    print("方式          勝者TOP1   勝者TOP2   勝者TOP3   予想TOP3内の実TOP3平均数   順位MAE")
    for key, label in [("CURRENT_24", "現行2・4+1"), ("NO_TENKAI", "展開もらい0")]:
        print(
            f"{label:<12} {pct(winner_capture[key][1]/processed)}  "
            f"{pct(winner_capture[key][2]/processed)}  {pct(winner_capture[key][3]/processed)}  "
            f"{top3_overlap_sum[key]/processed:.3f}/3                  {mae_sum[key]/processed:.3f}"
        )

    print("\n【予想順位別の実成績】")
    print("順位 | 現行2・4+1: 1着/3連/平均着順    | 展開もらい0: 1着/3連/平均着順")
    for pos in range(1, 7):
        a = summarize_head(pos_stats["CURRENT_24"][pos])
        b = summarize_head(pos_stats["NO_TENKAI"][pos])
        print(
            f" {pos}位 | {pct(a['win'])}/{pct(a['top3'])}/{a['avg_rank']:.3f}"
            f"      | {pct(b['win'])}/{pct(b['top3'])}/{b['avg_rank']:.3f}"
        )

    print("\n【順位が実際に変わったレース】")
    print(f"全順位の並びが変化 : {any_order_changed}/{processed} ({any_order_changed/processed*100:.2f}%)")
    print(f"最終1位が変化       : {head_changed}/{processed} ({head_changed/processed*100:.2f}%)")
    print(f"STEP4 現行該当      : {override['current']}/{processed} ({override['current']/processed*100:.2f}%)")
    print(f"STEP4 0補正該当     : {override['none']}/{processed} ({override['none']/processed*100:.2f}%)")
    print(f"STEP4判定自体が変化 : {override['different']}/{processed} ({override['different']/processed*100:.2f}%)")

    print("\n【最終1位が変わったレースだけ直接対決】")
    print(f"実着順で現行2・4+1が上 : {head_h2h['CURRENT_better']:>5}")
    print(f"展開もらい0が上         : {head_h2h['NO_TENKAI_better']:>5}")
    print(f"同着扱い                 : {head_h2h['tie']:>5}")
    print(
        f"1着を拾えた増減          : 現行+ {win_switch['CURRENT_gain']} / "
        f"0補正+ {win_switch['NO_TENKAI_gain']}"
    )
    print(
        f"3連対を拾えた増減        : 現行+ {top3_switch['CURRENT_gain']} / "
        f"0補正+ {top3_switch['NO_TENKAI_gain']}"
    )

    print("\n【切る艇：現行 vs 展開もらい0】")
    print("方式              平均切り数   切り発生R   1着艇を切る   実TOP3艇を切った数   実TOP3を1艇以上切るR")
    for name, label in [("current", "現行2・4+1"), ("none", "展開もらい0")]:
        print(
            f"{label:<14} {kiru_total[name]/processed:8.3f}   "
            f"{kiru_races[name]/processed*100:8.2f}%   "
            f"{winner_cut[name]/processed*100:9.2f}%   "
            f"{top3_cut_boats[name]/processed:12.3f}   "
            f"{races_top3_cut[name]/processed*100:13.2f}%"
        )
    print(f"切る艇構成が変化          : {cut_changed_races}/{processed} ({cut_changed_races/processed*100:.2f}%)")

    print("\n【2・4号艇の『切らない保護』だけを分離】")
    print("スコアは現行+1のまま、getBonus保護だけ外すと新たに切られる2・4号艇")
    print(f"件数       : {protection_only_count}")
    print(f"2号艇      : {protection_only_lane[2]}")
    print(f"4号艇      : {protection_only_lane[4]}")
    if protection_only_outcome["n"]:
        print(
            f"その実成績 : 1着 {pct(prot['win'])} / 2連 {pct(prot['top2'])} / "
            f"3連 {pct(prot['top3'])} / 平均着順 {prot['avg_rank']:.3f}"
        )
    else:
        print("その実成績 : 対象なし")

    print("\n【展開もらいを完全に0にした時、新たに切られる2・4号艇】")
    print(f"件数       : {newly_cut_24_count}")
    print(f"2号艇      : {newly_cut_24_lane[2]}")
    print(f"4号艇      : {newly_cut_24_lane[4]}")
    if newly_cut_24_outcome["n"]:
        print(
            f"その実成績 : 1着 {pct(newcut['win'])} / 2連 {pct(newcut['top2'])} / "
            f"3連 {pct(newcut['top3'])} / 平均着順 {newcut['avg_rank']:.3f}"
        )
    else:
        print("その実成績 : 対象なし")

    print("\n【判定の見方】")
    print("・順位側で現行2・4+1が良ければ、固定+1点を残す根拠になる")
    print("・切る艇側では、保護で救われる2・4号艇の実3連対率と、実TOP3を誤って切る割合を見る")
    print("・順位は良いが保護が悪い、またはその逆なら、+1点と切らない保護を別ルールにする余地がある")
    print("・ここでは+0.5や2号艇だけ等へ調整しない。まず現行フル仕様 vs 0だけを判定する")
    print("=" * 120)


if __name__ == "__main__":
    main()
