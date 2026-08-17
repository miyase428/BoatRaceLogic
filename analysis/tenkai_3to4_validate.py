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


THRESHOLD = 0.05  # 現行決まり手タイプ判定と同じ5%


def blank_stat():
    return {
        "n": 0,
        "win": 0,
        "top2": 0,
        "top3": 0,
        "rank_sum": 0.0,
    }


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


def main():
    if len(sys.argv) != 3:
        print("Usage: python3 analysis/tenkai_3to4_validate.py YYYY-MM-DD YYYY-MM-DD")
        sys.exit(1)

    start_date, end_date = sys.argv[1:3]
    start_dt = datetime.strptime(start_date, "%Y-%m-%d").date()
    end_dt = datetime.strptime(end_date, "%Y-%m-%d").date()

    if start_dt > end_dt:
        raise RuntimeError("開始日が終了日より後です")

    start_ymd = start_dt.strftime("%Y%m%d")
    end_ymd = end_dt.strftime("%Y%m%d")

    print("レース日基準の決まり手履歴を集計しています...")
    hist = load_historical_kimarite(start_date, end_date)

    print("C_ST_RANK用の選手コースプロフィールを読み込んでいます...")
    terms = required_terms(start_dt, end_dt)
    racer = load_racer_results(terms)
    races = load_races(start_ymd, end_ymd)

    stats = {
        "BASE_4C": blank_stat(),
        "3_MAKURI": blank_stat(),
        "4_MAKURIZASHI": blank_stat(),
        "BOTH": blank_stat(),
        "BOTH_3AHEAD2": blank_stat(),
        "BOTH_3FASTEST": blank_stat(),
    }

    skip = Counter()
    processed = 0
    st_margin_sum = 0.0
    st_margin_n = 0

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
            ex = b["ex_st"]
            if ex is None:
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

            ex_st.append(ex)
            profiles.append(p)

        if missing_profile:
            skip["missing_c_st_rank_profile"] += 1
            continue

        h3 = hist.get((race_code, by_course[3]["player_id"]))
        h4 = hist.get((race_code, by_course[4]["player_id"]))
        if h3 is None or h4 is None or h3["course"] != 3 or h4["course"] != 4:
            skip["missing_kimarite_history"] += 1
            continue

        c_st = make_method_st(ex_st, profiles)["C_ST_RANK"]

        r3_makuri = h3["rates"]["makuri"]
        r4_makurizashi = h4["rates"]["makurizashi"]

        cond_3_makuri = r3_makuri >= THRESHOLD
        cond_4_makurizashi = r4_makurizashi >= THRESHOLD
        cond_both = cond_3_makuri and cond_4_makurizashi

        # STは小さいほど速い。
        cond_3_ahead_2 = c_st[2] < c_st[1]
        cond_3_fastest = c_st[2] == min(c_st)

        rank4 = finish[4]
        add_stat(stats["BASE_4C"], rank4)

        if cond_3_makuri:
            add_stat(stats["3_MAKURI"], rank4)
        if cond_4_makurizashi:
            add_stat(stats["4_MAKURIZASHI"], rank4)
        if cond_both:
            add_stat(stats["BOTH"], rank4)
        if cond_both and cond_3_ahead_2:
            add_stat(stats["BOTH_3AHEAD2"], rank4)
            st_margin_sum += c_st[1] - c_st[2]
            st_margin_n += 1
        if cond_both and cond_3_fastest:
            add_stat(stats["BOTH_3FASTEST"], rank4)

        processed += 1

    base = stats["BASE_4C"]
    if base["n"] == 0:
        raise RuntimeError("比較できるレースが0件です")

    base_win = rate(base, "win")
    base_top2 = rate(base, "top2")
    base_top3 = rate(base, "top3")
    base_avg = avg_rank(base)

    print("=" * 120)
    print("展開もらい候補検証：3コース攻め → 4コースまくり差し")
    print("=" * 120)
    print(f"期間              : {start_date} ～ {end_date}")
    print(f"処理レース        : {processed}")
    print("決まり手履歴      : 各レース当時の直前6ヶ月")
    print("スリット予測      : C_ST_RANK（現行本番方式）")
    print("決まり手閾値      : 5%（現行タイプ判定と同じ）")
    print("本番変更          : なし")

    print("\n【今回の第一候補】")
    print("1) 3コースの過去6ヶ月・同コース『まくり率』 >= 5%")
    print("2) 4コースの過去6ヶ月・同コース『まくり差し率』 >= 5%")
    print("3) C_ST_RANK予測STで3コースが2コースより速い")
    print("→ 3条件すべて成立したとき、4コースを『展開もらい候補』とみなす")

    print("\n【skip】")
    for key in [
        "not_6_entry",
        "not_6_exhibition_course",
        "bad_result",
        "bad_race_date",
        "missing_c_st_rank_profile",
        "missing_kimarite_history",
    ]:
        print(f"{key:<31}: {skip[key]}")

    print("\n【4コース実成績：条件を1つずつ重ねる】")
    print("条件                         N      1着      2連対      3連対    平均着順 | 基準差 1着/2連/3連/着順")

    rows = [
        ("BASE_4C", "全4コース"),
        ("3_MAKURI", "3にまくり傾向"),
        ("4_MAKURIZASHI", "4にまくり差し傾向"),
        ("BOTH", "3まくり AND 4まくり差し"),
        ("BOTH_3AHEAD2", "上記 + 予測STで3<2"),
        ("BOTH_3FASTEST", "上記傾向 + 3が予測最速"),
    ]

    for key, label in rows:
        s = stats[key]
        if s["n"] == 0:
            print(f"{label:<28} {0:>6}      -         -         -        -")
            continue

        w = rate(s, "win")
        t2 = rate(s, "top2")
        t3 = rate(s, "top3")
        ar = avg_rank(s)

        print(
            f"{label:<28} {s['n']:>6}  {pct(w)}  {pct(t2)}  {pct(t3)}    {ar:.3f} | "
            f"{pp(w-base_win)} / {pp(t2-base_top2)} / {pp(t3-base_top3)} / {ar-base_avg:+.3f}着"
        )

    if st_margin_n:
        print("\n【第一候補成立時の予測ST差】")
        print(f"件数              : {st_margin_n}")
        print(f"平均 (2ST - 3ST)  : {st_margin_sum/st_margin_n:+.4f}秒  ※プラスほど3が2より先行")

    print("\n【判定の見方】")
    print("・最重要は『3まくり AND 4まくり差し + 予測STで3<2』の4コース成績")
    print("・全4コース基準より1着/2連/3連が揃って上がれば、展開もらいフラグ候補として有望")
    print("・今回は+1点を与えない。まずフラグ条件そのものに予測力があるかだけを見る")
    print("・『3が予測最速』は参考比較。第一候補を後から都合よく厳しくしないため別表示に留める")
    print("=" * 120)


if __name__ == "__main__":
    main()
