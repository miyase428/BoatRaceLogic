#!/usr/bin/env python3
import sys
from collections import Counter
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from compare_tenkai_morai_final_rank import (
    as_float,
    load_csv,
    load_entry_players,
    build_finish,
    make_kiru,
)
from kimarite_bonus_validate import load_historical_kimarite, decide_type


def pct(v):
    return f"{v * 100:6.2f}%"


def blank_outcome():
    return {"n": 0, "win": 0, "top2": 0, "top3": 0, "rank_sum": 0.0}


def add_outcome(stat, rank):
    stat["n"] += 1
    stat["win"] += int(rank == 1.0)
    stat["top2"] += int(rank <= 2.0)
    stat["top3"] += int(rank <= 3.0)
    stat["rank_sum"] += rank


def summarize_outcome(stat):
    n = stat["n"] or 1
    return {
        "win": stat["win"] / n,
        "top2": stat["top2"] / n,
        "top3": stat["top3"] / n,
        "outside": 1.0 - stat["top3"] / n,
        "avg_rank": stat["rank_sum"] / n,
    }


def main():
    if len(sys.argv) != 2:
        print(
            "Usage: python3 analysis/compare_tenkai_protection_only.py "
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

    skip = Counter()
    processed = 0
    changed_races = 0

    modes = ("PROTECT_24", "NO_PROTECT")
    cut_total = {m: 0 for m in modes}
    cut_races = {m: 0 for m in modes}
    winner_cut = {m: 0 for m in modes}
    top3_cut_boats = {m: 0 for m in modes}
    races_top3_cut = {m: 0 for m in modes}

    newly_cut = blank_outcome()
    newly_cut_lane = Counter()
    newly_cut_by_finish = Counter()
    newly_cut_per_race = Counter()

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

        score = {}
        rate6 = {}
        rate3 = {}
        missing = False

        for lane in range(1, 7):
            second = as_float(rows[lane].get("second_score"))
            r6 = as_float(rows[lane].get("three_in_rate_6m"))
            r3 = as_float(rows[lane].get("three_in_rate_3m"))
            csv_get_bonus = as_float(rows[lane].get("get_bonus"), 0.0)

            if second is None or r6 is None or r3 is None:
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

            _, type_bonus = decide_type(h["course"], h["rates"])

            base_second = second - float(csv_get_bonus or 0.0)

            score[lane] = base_second + float(type_bonus)
            rate6[lane] = r6
            rate3[lane] = r3

        if missing:
            skip["missing_score_rate_or_history"] += 1
            continue

        get_protect = {lane: (1 if lane in (2, 4) else 0) for lane in range(1, 7)}
        get_none = {lane: 0 for lane in range(1, 7)}

        cuts_protect = make_kiru(score, rate6, rate3, get_protect)
        cuts_none = make_kiru(score, rate6, rate3, get_none)

        processed += 1

        if cuts_protect != cuts_none:
            changed_races += 1

        actual_winner = next(lane for lane, rank in finish.items() if rank == 1.0)
        actual_top3 = {lane for lane, rank in finish.items() if rank <= 3.0}

        for mode, cuts in (
            ("PROTECT_24", cuts_protect),
            ("NO_PROTECT", cuts_none),
        ):
            cut_total[mode] += len(cuts)
            cut_races[mode] += int(bool(cuts))
            winner_cut[mode] += int(actual_winner in cuts)
            top3_cut_boats[mode] += len(cuts & actual_top3)
            races_top3_cut[mode] += int(bool(cuts & actual_top3))

        extra = cuts_none - cuts_protect
        newly_cut_per_race[len(extra)] += 1

        for lane in sorted(extra):
            newly_cut_lane[lane] += 1
            rank = finish[lane]
            add_outcome(newly_cut, rank)

            if rank == 1.0:
                newly_cut_by_finish["1着"] += 1
            elif rank == 2.0:
                newly_cut_by_finish["2着"] += 1
            elif rank == 3.0:
                newly_cut_by_finish["3着"] += 1
            else:
                newly_cut_by_finish["着外"] += 1

    if processed == 0:
        raise RuntimeError("比較できるレースが0件です")

    outcome = summarize_outcome(newly_cut)

    print("=" * 122)
    print("2・4号艇『切らない保護』単独検証（展開もらい点数は全艇0）")
    print("=" * 122)
    print(f"CSV          : {csv_path}")
    print(f"期間         : {start_date} ～ {end_date}")
    print(f"処理レース   : {processed}")
    print("決まり手補正 : 現行±1を維持（各レース当時の直前6ヶ月で再計算）")
    print("展開もらい点 : 全艇0（CSVのget_bonus分をsecond_scoreから除去）")
    print("比較A        : 2・4号艇だけ切る艇判定から保護")
    print("比較B        : 全艇同じ条件で切る艇判定")
    print("最終順位     : 比較しない（両方式でスコアが完全に同一）")
    print("本番変更     : なし")

    print("\n【skip】")
    for key in [
        "not_6_csv_rows",
        "bad_lane_rows",
        "bad_result",
        "missing_score_rate_or_history",
    ]:
        print(f"{key:<32}: {skip[key]}")

    print("\n【切る艇比較：保護だけON/OFF】")
    print(
        "方式                  平均切り数   切り発生R   1着艇を切る   "
        "実TOP3艇を切った数/R   実TOP3を1艇以上切るR   切り艇の着外率"
    )

    labels = {
        "PROTECT_24": "2・4保護あり",
        "NO_PROTECT": "保護なし",
    }

    for mode in modes:
        total = cut_total[mode]
        safe_rate = ((total - top3_cut_boats[mode]) / total if total else 0.0)
        print(
            f"{labels[mode]:<20}"
            f"{cut_total[mode] / processed:>10.3f}   "
            f"{pct(cut_races[mode] / processed)}     "
            f"{pct(winner_cut[mode] / processed)}          "
            f"{top3_cut_boats[mode] / processed:>7.3f}              "
            f"{pct(races_top3_cut[mode] / processed)}          "
            f"{pct(safe_rate)}"
        )

    print(f"\n切る艇構成が変化 : {changed_races}/{processed} ({changed_races/processed*100:.2f}%)")

    print("\n【保護を外したとき新たに切られる2・4号艇】")
    print(f"件数       : {newly_cut['n']}")
    print(f"2号艇      : {newly_cut_lane[2]}")
    print(f"4号艇      : {newly_cut_lane[4]}")
    if newly_cut["n"]:
        print(
            "その実成績 : "
            f"1着 {pct(outcome['win'])} / "
            f"2連 {pct(outcome['top2'])} / "
            f"3連 {pct(outcome['top3'])} / "
            f"着外 {pct(outcome['outside'])} / "
            f"平均着順 {outcome['avg_rank']:.3f}"
        )
        print(
            "実着内訳   : "
            f"1着 {newly_cut_by_finish['1着']} / "
            f"2着 {newly_cut_by_finish['2着']} / "
            f"3着 {newly_cut_by_finish['3着']} / "
            f"着外 {newly_cut_by_finish['着外']}"
        )

    print("\n【1レースあたり新たに切られる艇数】")
    for n in sorted(newly_cut_per_race):
        print(f"{n}艇増 : {newly_cut_per_race[n]:>6}R")

    print("\n【判定の見方】")
    print("・+1点の順位効果は完全に除去しているため、ここで差が出るのは『切らない保護』だけ")
    print("・保護ありで実TOP3誤切りが減るのは当然なので、同時に『切り艇の着外率』を見る")
    print("・保護を外して新たに切られる2・4号艇が大半着外なら、無条件保護は弱い根拠になる")
    print("・逆に新たに切られる艇の3連対率が高ければ、保護を残す根拠になる")
    print("・ここでは本番コードは変更しない。まず保護単独の有効性だけ判定する")
    print("=" * 122)


if __name__ == "__main__":
    main()
