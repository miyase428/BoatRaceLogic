#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
スリット体系 v2 健康診断

目的:
- 本番STが6艇揃わない場も含め、24場で「予測スリットパターン → 実着順」の有効性を検証する。
- 予測方法を2方式比較する。
    A) EX_ONLY      : 展示STのみ
    B) PLAYER_CORR  : 展示ST + 選手別の過去 展示→本番ST 平均差
- PLAYER_CORR は対象レース日より前のデータだけを使用し、未来情報の混入を防ぐ。
- 展示STと本番STの結合は race_code + player_id で行う。
- race_result_detail が上位4着までしかない場では、結果に存在しない艇を 5.5 着扱いする。
  ただし 1～4着が揃っているレースのみ評価対象とする。
- 本番STが6艇揃うレースについては、補助診断として予測パターンと実パターンの一致率も出す。

Usage:
    python3 analysis/slit_validate_v2.py 2026-06-15 2026-07-14
"""

from __future__ import annotations

import bisect
import json
import os
import sys
from collections import defaultdict
from datetime import datetime

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
COURSE_DIR = os.path.join(ROOT, "theories", "course_correction")
sys.path.insert(0, COURSE_DIR)

from classify_slit_pattern import classify_slit_pattern  # noqa: E402
from predict_pattern import connect_db  # noqa: E402


PATTERN_NAMES = {
    1: "内側先行",
    2: "横一線",
    3: "1・2先行",
    4: "スロー先行",
    5: "壁なし",
    6: "2・3号艇遅れ",
    7: "中凹み",
    8: "3号艇攻め",
    9: "中ぶくれ",
    10: "1号艇遅れ",
    11: "外側先行",
    12: "ダッシュ先行",
}

METHODS = ("EX_ONLY", "PLAYER_CORR")
BOOL_FEATURES = (
    "inside_fast",
    "wall_none",
    "middle_attack",
    "dash_fast",
    "inside_late",
    "line_abreast",
    "two_three_late",
    "middle_hollow",
    "middle_bulge",
    "one_two_fast",
    "outside_attack",
)


def ymd(s: str) -> str:
    dt = datetime.strptime(s, "%Y-%m-%d")
    return dt.strftime("%Y%m%d")


def to_float(v):
    try:
        if v is None or v == "":
            return None
        return float(v)
    except (TypeError, ValueError):
        return None


def to_rank(v):
    try:
        if v is None or v == "":
            return None
        return int(v)
    except (TypeError, ValueError):
        return None


def load_settings():
    path = os.path.join(COURSE_DIR, "venue_slit_settings.json")
    with open(path, encoding="utf-8") as f:
        return json.load(f)["default"]


def fetch_target_data(start_ymd: str, end_ymd: str):
    conn = connect_db()
    cur = conn.cursor()

    cur.execute(
        """
        SELECT race_code, player_id, entry_course, start_timing
        FROM boat_race.exhibition_live
        WHERE SUBSTRING(race_code, 1, 8) BETWEEN %s AND %s
        ORDER BY race_code, entry_course
        """,
        (start_ymd, end_ymd),
    )
    exhibition_rows = cur.fetchall()

    cur.execute(
        """
        SELECT race_code, player_id, entry_course, start_timing, rank
        FROM boat_race.race_result_detail
        WHERE SUBSTRING(race_code, 1, 8) BETWEEN %s AND %s
        ORDER BY race_code, entry_course, player_id
        """,
        (start_ymd, end_ymd),
    )
    result_rows = cur.fetchall()

    cur.close()
    conn.close()
    return exhibition_rows, result_rows


def fetch_player_delta_history(end_ymd: str):
    """選手ごとの (日付, 本番ST - 展示ST) 履歴を作る。"""
    conn = connect_db()
    cur = conn.cursor()
    cur.execute(
        """
        SELECT
            r.race_code,
            r.player_id,
            r.start_timing AS real_st,
            e.start_timing AS exhibition_st
        FROM boat_race.race_result_detail r
        JOIN boat_race.exhibition_live e
          ON r.race_code = e.race_code
         AND r.player_id = e.player_id
        WHERE r.start_timing IS NOT NULL
          AND e.start_timing IS NOT NULL
          AND SUBSTRING(r.race_code, 1, 8) <= %s
        ORDER BY r.player_id, r.race_code
        """,
        (end_ymd,),
    )
    rows = cur.fetchall()
    cur.close()
    conn.close()

    raw = defaultdict(list)
    for race_code, player_id, real_st, ex_st in rows:
        real = to_float(real_st)
        ex = to_float(ex_st)
        if real is None or ex is None:
            continue
        raw[str(player_id)].append((str(race_code)[:8], real - ex))

    history = {}
    for pid, vals in raw.items():
        vals.sort(key=lambda x: x[0])
        dates = []
        prefix = [0.0]
        for d, delta in vals:
            dates.append(d)
            prefix.append(prefix[-1] + delta)
        history[pid] = (dates, prefix)

    return history


def prior_player_delta(history, player_id: str, target_date: str):
    item = history.get(str(player_id))
    if not item:
        return 0.0, 0
    dates, prefix = item
    # 対象レース当日は全部除外し、前日までだけ使う。
    n = bisect.bisect_left(dates, target_date)
    if n <= 0:
        return 0.0, 0
    return prefix[n] / n, n


def group_target(exhibition_rows, result_rows):
    ex_by_race = defaultdict(list)
    for race_code, player_id, entry_course, st in exhibition_rows:
        ex_by_race[str(race_code)].append(
            {
                "player_id": str(player_id),
                "entry_course": int(entry_course) if entry_course is not None else None,
                "st": to_float(st),
            }
        )

    res_by_race = defaultdict(list)
    for race_code, player_id, entry_course, st, rank in result_rows:
        res_by_race[str(race_code)].append(
            {
                "player_id": str(player_id),
                "entry_course": int(entry_course) if entry_course is not None else None,
                "st": to_float(st),
                "rank": to_rank(rank),
            }
        )

    return ex_by_race, res_by_race


def new_stat():
    return {"n": 0, "win": 0, "top2": 0, "top3": 0, "sum_rank": 0.0}


def add_stat(stat, rank: float):
    stat["n"] += 1
    stat["sum_rank"] += rank
    if rank == 1:
        stat["win"] += 1
    if rank <= 2:
        stat["top2"] += 1
    if rank <= 3:
        stat["top3"] += 1


def rates(stat):
    n = stat["n"]
    if n == 0:
        return {"win": 0.0, "top2": 0.0, "top3": 0.0, "avg": 0.0}
    return {
        "win": stat["win"] / n,
        "top2": stat["top2"] / n,
        "top3": stat["top3"] / n,
        "avg": stat["sum_rank"] / n,
    }


def valid_exhibition(ex_rows):
    if len(ex_rows) != 6:
        return False
    courses = [r["entry_course"] for r in ex_rows]
    if sorted(courses) != [1, 2, 3, 4, 5, 6]:
        return False
    if any(r["st"] is None for r in ex_rows):
        return False
    if len({r["player_id"] for r in ex_rows}) != 6:
        return False
    return True


def valid_top4_results(res_rows):
    ranks = [r["rank"] for r in res_rows if r["rank"] is not None]
    return all(x in ranks for x in (1, 2, 3, 4))


def build_actual_pattern(res_rows, settings):
    usable = [
        r for r in res_rows
        if r["entry_course"] in (1, 2, 3, 4, 5, 6) and r["st"] is not None
    ]
    if len(usable) != 6:
        return None
    by_course = {r["entry_course"]: r["st"] for r in usable}
    if sorted(by_course.keys()) != [1, 2, 3, 4, 5, 6]:
        return None
    st = [by_course[c] for c in range(1, 7)]
    return classify_slit_pattern(st, settings)


def evaluate(start_ymd: str, end_ymd: str):
    settings = load_settings()
    exhibition_rows, result_rows = fetch_target_data(start_ymd, end_ymd)
    history = fetch_player_delta_history(end_ymd)
    ex_by_race, res_by_race = group_target(exhibition_rows, result_rows)

    baseline = {c: new_stat() for c in range(1, 7)}
    cells = {method: defaultdict(new_stat) for method in METHODS}
    pattern_races = {method: defaultdict(int) for method in METHODS}

    exact_match = {method: {"match": 0, "total": 0} for method in METHODS}
    feature_match = {
        method: {f: {"match": 0, "total": 0} for f in BOOL_FEATURES}
        for method in METHODS
    }

    counters = defaultdict(int)
    corr_history_counts = []

    all_races = sorted(ex_by_race.keys())
    counters["target_races"] = len(all_races)

    for race_code in all_races:
        ex_rows = sorted(ex_by_race[race_code], key=lambda r: r["entry_course"] or 99)
        if not valid_exhibition(ex_rows):
            counters["skip_exhibition_not6"] += 1
            continue

        res_rows = res_by_race.get(race_code, [])
        if not valid_top4_results(res_rows):
            counters["skip_result_top4_missing"] += 1
            continue

        result_by_player = {}
        for r in res_rows:
            result_by_player[r["player_id"]] = r["rank"]

        target_date = race_code[:8]
        ex_st = [r["st"] for r in ex_rows]
        corrected_st = []
        for r in ex_rows:
            delta, n_hist = prior_player_delta(history, r["player_id"], target_date)
            corrected_st.append(r["st"] + delta)
            corr_history_counts.append(n_hist)

        pred = {
            "EX_ONLY": classify_slit_pattern(ex_st, settings),
            "PLAYER_CORR": classify_slit_pattern(corrected_st, settings),
        }

        # 同じ処理レース集合でコース基準成績を作る。
        for r in ex_rows:
            rank = result_by_player.get(r["player_id"])
            rank_value = float(rank) if rank is not None else 5.5
            add_stat(baseline[r["entry_course"]], rank_value)

        for method in METHODS:
            pattern_id, features = pred[method]
            pattern_races[method][pattern_id] += 1

            for r in ex_rows:
                rank = result_by_player.get(r["player_id"])
                rank_value = float(rank) if rank is not None else 5.5
                add_stat(cells[method][(pattern_id, r["entry_course"])], rank_value)

        # 本番STが6艇揃うレースだけ、パターン再現率を補助診断する。
        actual = build_actual_pattern(res_rows, settings)
        if actual is not None:
            actual_pid, actual_features = actual
            counters["actual_st6_races"] += 1
            for method in METHODS:
                pred_pid, pred_features = pred[method]
                exact_match[method]["total"] += 1
                if pred_pid == actual_pid:
                    exact_match[method]["match"] += 1
                for f in BOOL_FEATURES:
                    feature_match[method][f]["total"] += 1
                    if bool(pred_features.get(f)) == bool(actual_features.get(f)):
                        feature_match[method][f]["match"] += 1

        counters["processed_races"] += 1

    return {
        "settings": settings,
        "baseline": baseline,
        "cells": cells,
        "pattern_races": pattern_races,
        "exact_match": exact_match,
        "feature_match": feature_match,
        "counters": counters,
        "corr_history_counts": corr_history_counts,
    }


def pct(x):
    return f"{x * 100:6.2f}%"


def print_report(start_date: str, end_date: str, data):
    baseline = data["baseline"]
    cells = data["cells"]
    pattern_races = data["pattern_races"]
    exact_match = data["exact_match"]
    feature_match = data["feature_match"]
    counters = data["counters"]
    history_counts = data["corr_history_counts"]

    base_rates = {c: rates(baseline[c]) for c in range(1, 7)}

    print("========================================")
    print("スリット体系 v2 健康診断")
    print("========================================")
    print(f"期間            : {start_date} ～ {end_date}")
    print("結果対応        : race_code + player_id")
    print("欠損着順        : 1～4着確認後、結果に無い艇=5.5")
    print("補正履歴        : 対象レース日の前日まで（未来情報なし）")
    print("比較            : EX_ONLY vs PLAYER_CORR")
    print()

    print("【処理状況】")
    print(f"対象レース      : {counters['target_races']}")
    print(f"処理レース      : {counters['processed_races']}")
    print(f"展示6艇不足skip : {counters['skip_exhibition_not6']}")
    print(f"1～4着不足skip  : {counters['skip_result_top4_missing']}")
    print(f"本番ST6艇レース : {counters['actual_st6_races']}  ※一致率診断用")
    if history_counts:
        zero = sum(1 for n in history_counts if n == 0)
        avg_hist = sum(history_counts) / len(history_counts)
        print(f"選手補正履歴0件 : {zero}/{len(history_counts)} ({zero/len(history_counts)*100:.2f}%)")
        print(f"平均補正履歴件数: {avg_hist:.2f}")
    print()

    print("【コース基準成績（同一評価レース集合）】")
    for c in range(1, 7):
        r = base_rates[c]
        print(
            f"C{c} N={baseline[c]['n']:5d} "
            f"1着={pct(r['win'])} 2連={pct(r['top2'])} 3連={pct(r['top3'])} 平均={r['avg']:.3f}"
        )
    print()

    for method in METHODS:
        print("========================================")
        print(f"方式: {method}")
        print("========================================")
        print("【予測パターン分布】")
        total_patterns = sum(pattern_races[method].values())
        for pid in range(1, 13):
            n = pattern_races[method].get(pid, 0)
            share = n / total_patterns if total_patterns else 0.0
            print(f"P{pid:02d} {PATTERN_NAMES[pid]:10s} N={n:5d} ({share*100:5.2f}%)")
        print()

        eligible = []
        weighted_n = 0
        weighted_abs_win = 0.0
        weighted_abs_top3 = 0.0

        for (pid, course), stat in cells[method].items():
            if stat["n"] < 50:
                continue
            r = rates(stat)
            b = base_rates[course]
            win_lift = r["win"] - b["win"]
            top3_lift = r["top3"] - b["top3"]
            score = abs(win_lift) + abs(top3_lift)
            eligible.append((score, pid, course, stat, r, win_lift, top3_lift))
            weighted_n += stat["n"]
            weighted_abs_win += stat["n"] * abs(win_lift)
            weighted_abs_top3 += stat["n"] * abs(top3_lift)

        print("【シグナル要約（N>=50 の pattern×course）】")
        if weighted_n:
            print(f"加重平均 |1着lift| : {weighted_abs_win/weighted_n*100:.2f}pt")
            print(f"加重平均 |3連lift| : {weighted_abs_top3/weighted_n*100:.2f}pt")
        else:
            print("対象セルなし")
        print()

        print("【影響が大きい pattern×course 上位15セル（N>=50）】")
        eligible.sort(reverse=True, key=lambda x: x[0])
        for _, pid, course, stat, r, win_lift, top3_lift in eligible[:15]:
            print(
                f"P{pid:02d} {PATTERN_NAMES[pid]:10s} C{course} "
                f"N={stat['n']:4d} "
                f"1着={pct(r['win'])} lift={win_lift*100:+6.2f}pt "
                f"3連={pct(r['top3'])} lift={top3_lift*100:+6.2f}pt "
                f"平均={r['avg']:.3f}"
            )
        print()

        print("【1コースへの影響（N>=30）】")
        for pid in range(1, 13):
            stat = cells[method].get((pid, 1))
            if not stat or stat["n"] < 30:
                continue
            r = rates(stat)
            b = base_rates[1]
            print(
                f"P{pid:02d} {PATTERN_NAMES[pid]:10s} "
                f"N={stat['n']:4d} "
                f"1着={pct(r['win'])} lift={(r['win']-b['win'])*100:+6.2f}pt "
                f"3連={pct(r['top3'])} lift={(r['top3']-b['top3'])*100:+6.2f}pt "
                f"平均={r['avg']:.3f}"
            )
        print()

    print("========================================")
    print("本番ST6艇があるレースでの補助診断")
    print("========================================")
    for method in METHODS:
        m = exact_match[method]
        acc = m["match"] / m["total"] if m["total"] else 0.0
        print(f"{method:11s} パターン完全一致 : {m['match']}/{m['total']} = {acc*100:.2f}%")

        feature_total = 0
        feature_match_total = 0
        for f in BOOL_FEATURES:
            fm = feature_match[method][f]
            feature_total += fm["total"]
            feature_match_total += fm["match"]
        feature_acc = feature_match_total / feature_total if feature_total else 0.0
        print(f"{method:11s} 特徴フラグ一致   : {feature_match_total}/{feature_total} = {feature_acc*100:.2f}%")
    print()

    print("見るポイント:")
    print("1) 2期間で同じ pattern×course の lift 符号・大きさが再現するか")
    print("2) EX_ONLY と PLAYER_CORR のどちらが安定してシグナルを分けるか")
    print("3) 本番ST6艇サブセットでは、どちらのパターン一致率が高いか")
    print("4) PLAYER_CORR が弱ければ、上位4着中心の本番ST履歴による選択バイアスを疑う")
    print("========================================")


def main():
    if len(sys.argv) != 3:
        print("Usage: python3 analysis/slit_validate_v2.py YYYY-MM-DD YYYY-MM-DD")
        sys.exit(1)

    start_date = sys.argv[1]
    end_date = sys.argv[2]
    start_ymd = ymd(start_date)
    end_ymd = ymd(end_date)
    if start_ymd > end_ymd:
        raise SystemExit("開始日は終了日以前にしてください")

    data = evaluate(start_ymd, end_ymd)
    print_report(start_date, end_date, data)


if __name__ == "__main__":
    main()
