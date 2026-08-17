#!/usr/bin/env python3
import sys
from collections import Counter
from datetime import datetime

from slit_pattern_condition_analyze import extract_features_param
from slit_racer_compare import (
    load_racer_results,
    load_races,
    required_terms,
    term_info_for_date,
    safe_course_profile,
    build_finish,
    make_method_st,
    new_lane_counts,
    add_result,
    separation_score,
)

METHODS = ["A_EX", "B_COURSE_ST", "C_ST_RANK", "D_RELIABLE"]

OLD_PRIORITY = [
    (12, "dash_fast"),
    (4, "inside_fast"),
    (11, "outside_attack"),
    (6, "two_three_late"),
    (7, "middle_hollow"),
    (5, "wall_none"),
    (9, "middle_bulge"),
    (8, "middle_attack"),
    (3, "one_two_fast"),
    (10, "inside_late"),
    (2, "line_abreast"),
]

FINAL_PRIORITY = [
    (12, "dash_fast"),
    (3, "one_two_fast"),
    (4, "inside_fast"),
    (11, "outside_attack"),
    (6, "two_three_late"),
    (7, "middle_hollow"),
    (5, "wall_none"),
    (9, "middle_bulge"),
    (8, "middle_attack"),
    (10, "inside_late"),
    (2, "line_abreast"),
]

RULES = [
    ("OLD_04", 0.04, OLD_PRIORITY, "C_ST_RANK + 旧priority + delay0.04"),
    ("DELAY_02", 0.02, OLD_PRIORITY, "C_ST_RANK + 旧priority + delay0.02"),
    ("FINAL", 0.02, FINAL_PRIORITY, "C_ST_RANK + delay0.02 + 1・2先行UP"),
]


def decide(features, priority):
    for pid, key in priority:
        if features.get(key):
            return pid
    return 1


def prepare(start_date, end_date):
    start_dt = datetime.strptime(start_date, "%Y-%m-%d").date()
    end_dt = datetime.strptime(end_date, "%Y-%m-%d").date()
    terms = required_terms(start_dt, end_dt)
    racer = load_racer_results(terms)
    races = load_races(start_dt.strftime("%Y%m%d"), end_dt.strftime("%Y%m%d"))

    prepared = []
    skip = Counter()

    for race_code in sorted(races):
        boats = races[race_code]
        if len(boats) != 6 or len({b["player_id"] for b in boats}) != 6:
            skip["not_6_entry"] += 1
            continue

        by_course = {}
        bad = False
        for b in boats:
            c = b["course"]
            if c not in range(1, 7) or c in by_course:
                bad = True
                break
            by_course[c] = b
        if bad or set(by_course) != set(range(1, 7)):
            skip["not_6_exhibition"] += 1
            continue
        if any(by_course[c]["ex_st"] is None for c in range(1, 7)):
            skip["missing_ex_st"] += 1
            continue

        finish = build_finish(by_course)
        if finish is None:
            skip["bad_result"] += 1
            continue

        race_dt = datetime.strptime(race_code[:8], "%Y%m%d").date()
        term = term_info_for_date(race_dt)
        profiles = []
        missing = False
        for c in range(1, 7):
            rr = racer.get((term, by_course[c]["player_id"]))
            if rr is None:
                missing = True
                break
            p = safe_course_profile(rr, c)
            if p is None:
                missing = True
                break
            profiles.append(p)
        if missing:
            skip["missing_racer_term_or_st"] += 1
            continue

        ex_st = [by_course[c]["ex_st"] for c in range(1, 7)]
        method_st = make_method_st(ex_st, profiles)
        prepared.append((race_code, method_st, finish))

    return prepared, skip, terms, len(races)


def main():
    if len(sys.argv) != 3:
        print("Usage: python3 analysis/slit_final_holdout.py YYYY-MM-DD YYYY-MM-DD")
        sys.exit(1)

    start, end = sys.argv[1], sys.argv[2]
    prepared, skip, terms, target = prepare(start, end)

    baseline = new_lane_counts()
    fastest = {m: {"n": 0, "win": 0, "top3": 0} for m in METHODS}
    rule_counts = {
        name: {pid: new_lane_counts() for pid in range(1, 13)}
        for name, *_ in RULES
    }
    rule_freq = {name: Counter() for name, *_ in RULES}

    for _, method_st, finish in prepared:
        for c in range(1, 7):
            add_result(baseline, c, finish[c])

        for method in METHODS:
            st = method_st[method]
            fc = min(range(1, 7), key=lambda c: st[c - 1])
            fastest[method]["n"] += 1
            if finish[fc] == 1.0:
                fastest[method]["win"] += 1
            if finish[fc] <= 3.0:
                fastest[method]["top3"] += 1

        c_st = method_st["C_ST_RANK"]
        for name, delay, priority, _ in RULES:
            f = extract_features_param(c_st, delay_th=delay, line_th=0.05)
            pid = decide(f, priority)
            rule_freq[name][pid] += 1
            for c in range(1, 7):
                add_result(rule_counts[name][pid], c, finish[c])

    print("=" * 118)
    print("スリット体系 最終HOLDOUT検証")
    print("=" * 118)
    print(f"期間       : {start} ～ {end}")
    print(f"使用期     : {', '.join(terms)}")
    print(f"対象レース : {target}")
    print(f"処理レース : {len(prepared)}")
    for k in ["not_6_entry", "not_6_exhibition", "missing_ex_st", "bad_result", "missing_racer_term_or_st"]:
        print(f"skip {k:<27}: {skip[k]}")

    print("\n【予測ST方式 HOLDOUT】")
    print("方式             最速評価艇 1着/3連")
    for m in METHODS:
        x = fastest[m]
        n = x["n"]
        w = x["win"] / n if n else 0.0
        t = x["top3"] / n if n else 0.0
        print(f"{m:<16} {w*100:6.2f}% / {t*100:6.2f}%")

    print("\n【パターンルール HOLDOUT（C_ST_RANK固定）】")
    print("方式        分離score 1着/3連      OLD_04差 1着/3連   有効PID  最小PID件数   説明")
    old_sw = old_st = None
    for name, _, _, desc in RULES:
        sw, st3 = separation_score(rule_counts[name], baseline)
        if old_sw is None:
            old_sw, old_st = sw, st3
        active = [n for n in rule_freq[name].values() if n > 0]
        print(
            f"{name:<11} {sw*100:5.2f}/{st3*100:5.2f}pt      "
            f"{(sw-old_sw)*100:+6.2f}/{(st3-old_st)*100:+6.2f}pt   "
            f"{len(active):>3}PID      {min(active) if active else 0:>5}   {desc}"
        )

    print("\n判定の目安:")
    print("・C_ST_RANKがA_EX/B_COURSE_STより最速評価艇成績を維持/改善するか")
    print("・FINALがOLD_04より1着/3連の両方で分離scoreを維持/改善するか")
    print("・FINALがDELAY_02も上回れば、1・2先行UPのHOLDOUT再現性も確認できる")
    print("・この期間では閾値や優先順位を再調整しない")
    print("=" * 118)


if __name__ == "__main__":
    main()
