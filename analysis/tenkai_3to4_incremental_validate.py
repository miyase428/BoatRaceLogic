#!/usr/bin/env python3
import sys
from collections import Counter
from datetime import datetime
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from kimarite_bonus_validate import load_historical_kimarite
from slit_racer_compare import (
    required_terms,
    term_info_for_date,
    load_racer_results,
    load_races,
    build_finish,
    safe_course_profile,
    make_method_st,
)

THRESHOLD = 0.05


def blank_stat():
    return {"n": 0, "win": 0, "top2": 0, "top3": 0, "rank_sum": 0.0}


def add_stat(stat, rank):
    stat["n"] += 1
    stat["win"] += int(rank == 1.0)
    stat["top2"] += int(rank <= 2.0)
    stat["top3"] += int(rank <= 3.0)
    stat["rank_sum"] += rank


def rate(stat, key):
    return stat[key] / stat["n"] if stat["n"] else 0.0


def avg_rank(stat):
    return stat["rank_sum"] / stat["n"] if stat["n"] else 0.0


def pct(v):
    return f"{v * 100:6.2f}%"


def pp(v):
    return f"{v * 100:+6.2f}pt"


def show_row(label, stat):
    if stat["n"] == 0:
        print(f"{label:<32} {0:>6}      -         -         -        -")
        return
    print(
        f"{label:<32} {stat['n']:>6}  "
        f"{pct(rate(stat,'win'))}  {pct(rate(stat,'top2'))}  "
        f"{pct(rate(stat,'top3'))}    {avg_rank(stat):.3f}"
    )


def main():
    if len(sys.argv) != 3:
        print("Usage: python3 analysis/tenkai_3to4_incremental_validate.py YYYY-MM-DD YYYY-MM-DD")
        sys.exit(1)

    start_date, end_date = sys.argv[1:3]
    start_dt = datetime.strptime(start_date, "%Y-%m-%d").date()
    end_dt = datetime.strptime(end_date, "%Y-%m-%d").date()
    if start_dt > end_dt:
        raise RuntimeError("開始日が終了日より後です")

    print("レース日基準の決まり手履歴を集計しています...")
    hist = load_historical_kimarite(start_date, end_date)

    print("C_ST_RANK用の選手コースプロフィールを読み込んでいます...")
    terms = required_terms(start_dt, end_dt)
    racer = load_racer_results(terms)
    races = load_races(start_dt.strftime("%Y%m%d"), end_dt.strftime("%Y%m%d"))

    stats = {
        "ALL_4_MZ": blank_stat(),
        "FLAG_A": blank_stat(),
        "OTHER_B": blank_stat(),
        "3_MAKURI_ONLY": blank_stat(),
        "3_AHEAD_ONLY": blank_stat(),
        "NEITHER": blank_stat(),
    }

    skip = Counter()
    processed = 0
    eligible = 0
    margin_a_sum = 0.0
    margin_b_sum = 0.0
    margin_a_n = 0
    margin_b_n = 0

    for race_code in sorted(races):
        boats = races[race_code]
        if len(boats) != 6 or len({b["player_id"] for b in boats}) != 6:
            skip["not_6_entry"] += 1
            continue

        by_course = {}
        bad_course = False
        for b in boats:
            c = b["course"]
            if c not in range(1, 7) or c in by_course:
                bad_course = True
                break
            by_course[c] = b
        if bad_course or set(by_course) != set(range(1, 7)):
            skip["not_6_exhibition_course"] += 1
            continue

        finish = build_finish(by_course)
        if finish is None:
            skip["bad_result"] += 1
            continue

        try:
            race_dt = datetime.strptime(race_code[:8], "%Y%m%d").date()
        except ValueError:
            skip["bad_race_date"] += 1
            continue

        term = term_info_for_date(race_dt)
        ex_st = []
        profiles = []
        missing_profile = False
        for c in range(1, 7):
            b = by_course[c]
            if b["ex_st"] is None:
                missing_profile = True
                break
            rr = racer.get((term, b["player_id"]))
            if rr is None:
                missing_profile = True
                break
            p = safe_course_profile(rr, c)
            if p is None:
                missing_profile = True
                break
            ex_st.append(b["ex_st"])
            profiles.append(p)
        if missing_profile:
            skip["missing_c_st_rank_profile"] += 1
            continue

        h3 = hist.get((race_code, by_course[3]["player_id"]))
        h4 = hist.get((race_code, by_course[4]["player_id"]))
        if h3 is None or h4 is None or h3["course"] != 3 or h4["course"] != 4:
            skip["missing_kimarite_history"] += 1
            continue

        processed += 1

        # 母集団を「4コースにまくり差し傾向あり」に固定する。
        if h4["rates"]["makurizashi"] < THRESHOLD:
            continue

        eligible += 1
        c_st = make_method_st(ex_st, profiles)["C_ST_RANK"]
        cond_3_makuri = h3["rates"]["makuri"] >= THRESHOLD
        cond_3_ahead_2 = c_st[2] < c_st[1]
        margin = c_st[1] - c_st[2]  # +ほど3が2より先行
        rank4 = finish[4]

        add_stat(stats["ALL_4_MZ"], rank4)

        # 第一候補フラグA：3にまくり傾向 AND 予測STで3<2
        if cond_3_makuri and cond_3_ahead_2:
            add_stat(stats["FLAG_A"], rank4)
            margin_a_sum += margin
            margin_a_n += 1
        else:
            add_stat(stats["OTHER_B"], rank4)
            margin_b_sum += margin
            margin_b_n += 1

        # 診断用2x2。採用条件を増やすためではなく、A/B差の内訳を見るだけ。
        if cond_3_makuri and not cond_3_ahead_2:
            add_stat(stats["3_MAKURI_ONLY"], rank4)
        elif (not cond_3_makuri) and cond_3_ahead_2:
            add_stat(stats["3_AHEAD_ONLY"], rank4)
        elif (not cond_3_makuri) and (not cond_3_ahead_2):
            add_stat(stats["NEITHER"], rank4)

    a = stats["FLAG_A"]
    b = stats["OTHER_B"]
    if a["n"] == 0 or b["n"] == 0:
        raise RuntimeError("A/Bを比較できる件数がありません")

    print("=" * 122)
    print("展開もらい増分検証：4まくり差し傾向を固定した上で 3攻め条件 A vs その他 B")
    print("=" * 122)
    print(f"期間              : {start_date} ～ {end_date}")
    print(f"処理レース        : {processed}")
    print(f"4まくり差し母集団 : {eligible}")
    print("決まり手履歴      : 各レース当時の直前6ヶ月")
    print("決まり手閾値      : 5%")
    print("スリット予測      : C_ST_RANK（現行本番方式）")
    print("本番変更          : なし")

    print("\n【比較定義】")
    print("母集団 : 4コースの過去6ヶ月・同コース『まくり差し率』 >= 5%")
    print("A      : 上記の中で、3コースまくり率 >= 5% AND 予測STで3 < 2")
    print("B      : 上記の中で、Aに該当しない全レース")
    print("目的   : 4自身のまくり差し適性を固定した後でも、3攻め条件に増分効果があるか確認")

    print("\n【skip】")
    for key in [
        "not_6_entry", "not_6_exhibition_course", "bad_result",
        "bad_race_date", "missing_c_st_rank_profile", "missing_kimarite_history",
    ]:
        print(f"{key:<31}: {skip[key]}")

    print("\n【直接比較：4コース実成績】")
    print("条件                             N      1着      2連対      3連対    平均着順")
    show_row("母集団: 4まくり差し傾向", stats["ALL_4_MZ"])
    show_row("A: 3まくり + 予測STで3<2", a)
    show_row("B: A以外", b)

    print("\n【増分 A - B】")
    print(f"1着      : {pp(rate(a,'win') - rate(b,'win'))}")
    print(f"2連対    : {pp(rate(a,'top2') - rate(b,'top2'))}")
    print(f"3連対    : {pp(rate(a,'top3') - rate(b,'top3'))}")
    print(f"平均着順 : {avg_rank(a) - avg_rank(b):+.3f}着  ※マイナスほどAが良い")

    print("\n【A/Bの予測ST差】")
    if margin_a_n:
        print(f"A 平均(2ST-3ST) : {margin_a_sum/margin_a_n:+.4f}秒")
    if margin_b_n:
        print(f"B 平均(2ST-3ST) : {margin_b_sum/margin_b_n:+.4f}秒")

    print("\n【参考：Bの内訳（条件探索には使わない）】")
    print("条件                             N      1着      2連対      3連対    平均着順")
    show_row("3まくり有 / 3<2ではない", stats["3_MAKURI_ONLY"])
    show_row("3まくり無 / 3<2", stats["3_AHEAD_ONLY"])
    show_row("3まくり無 / 3<2でもない", stats["NEITHER"])

    print("\n【判定の見方】")
    print("・最重要は『増分 A-B』。特に2連対・3連対・平均着順を見る")
    print("・AがBより2連/3連で安定して上なら、3→4展開もらいフラグの増分根拠になる")
    print("・AがBと同等以下なら、前回の好成績は主に4自身のまくり差し適性による可能性が高い")
    print("・これは関連の増分検証であり、3の攻めが原因だと断定する検証ではない")
    print("=" * 122)


if __name__ == "__main__":
    main()
