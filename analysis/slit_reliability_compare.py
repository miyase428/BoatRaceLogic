#!/usr/bin/env python3
import sys
from collections import Counter
from datetime import datetime
from pathlib import Path

SCRIPT_DIR = Path(__file__).resolve().parent
sys.path.insert(0, str(SCRIPT_DIR))

from slit_racer_compare import (
    PATTERN_NAMES,
    add_result,
    build_finish,
    classify_slit_pattern,
    load_racer_results,
    load_races,
    load_settings,
    mean,
    metrics,
    new_lane_counts,
    rank_component_seconds,
    required_terms,
    safe_course_profile,
    separation_score,
    term_info_for_date,
)

K_VALUES = [5.0, 10.0, 20.0, 40.0]
METHODS = ["C_ST_RANK"] + [f"D_K{int(k)}" for k in K_VALUES]
KEY_PIDS = [3, 6, 8, 9, 10, 12]


def make_method_st(ex_st, profiles):
    course_st = [p["avg_st"] for p in profiles]
    course_rank = [p["avg_rank"] for p in profiles]

    # C: 展示ST + コース平均ST相対差 + 平均ST順位相対差
    m_course = mean(course_st)
    course_dev = [v - m_course for v in course_st]
    rank_sec = rank_component_seconds(course_rank, course_st)
    c = [ex_st[i] + course_dev[i] + rank_sec[i] for i in range(6)]

    out = {"C_ST_RANK": c}

    # D: Cの各コース成績を進入回数 n/(n+K) で平滑化
    for k in K_VALUES:
        smooth_st = []
        smooth_rank = []
        for p in profiles:
            n = p["n"]
            w = n / (n + k) if n > 0 else 0.0
            smooth_st.append(
                p["overall_st"] + w * (p["avg_st"] - p["overall_st"])
            )
            smooth_rank.append(3.5 + w * (p["avg_rank"] - 3.5))

        m_smooth = mean(smooth_st)
        smooth_dev = [v - m_smooth for v in smooth_st]
        smooth_rank_sec = rank_component_seconds(smooth_rank, smooth_st)
        out[f"D_K{int(k)}"] = [
            ex_st[i] + smooth_dev[i] + smooth_rank_sec[i]
            for i in range(6)
        ]

    return out


def analyze(start_date, end_date):
    start_dt = datetime.strptime(start_date, "%Y-%m-%d").date()
    end_dt = datetime.strptime(end_date, "%Y-%m-%d").date()
    start_ymd = start_dt.strftime("%Y%m%d")
    end_ymd = end_dt.strftime("%Y%m%d")

    settings = load_settings()
    terms = required_terms(start_dt, end_dt)
    racer = load_racer_results(terms)
    races = load_races(start_ymd, end_ymd)

    baseline = new_lane_counts()
    pattern_counts = {
        method: {pid: new_lane_counts() for pid in range(1, 13)}
        for method in METHODS
    }
    pattern_freq = {method: Counter() for method in METHODS}
    agree_c = {method: 0 for method in METHODS}
    fastest = {
        method: {"n": 0, "win": 0, "top3": 0}
        for method in METHODS
    }
    actual_match = {method: 0 for method in METHODS}
    actual_subset = 0

    skip = Counter()
    processed = 0

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
        missing_racer = False
        for c in range(1, 7):
            pid = by_course[c]["player_id"]
            rr = racer.get((term, pid))
            if rr is None:
                missing_racer = True
                break
            p = safe_course_profile(rr, c)
            if p is None:
                missing_racer = True
                break
            profiles.append(p)

        if missing_racer:
            skip["missing_racer_term_or_st"] += 1
            continue

        ex_st = [by_course[c]["ex_st"] for c in range(1, 7)]
        method_st = make_method_st(ex_st, profiles)

        for c in range(1, 7):
            add_result(baseline, c, finish[c])

        pids = {}
        for method, st_list in method_st.items():
            pid, _ = classify_slit_pattern(st_list, settings)
            pids[method] = pid
            pattern_freq[method][pid] += 1

            for c in range(1, 7):
                add_result(pattern_counts[method][pid], c, finish[c])

            fastest_course = min(range(1, 7), key=lambda c: st_list[c - 1])
            r = finish[fastest_course]
            fastest[method]["n"] += 1
            fastest[method]["win"] += int(r == 1.0)
            fastest[method]["top3"] += int(r <= 3.0)

        c_pid = pids["C_ST_RANK"]
        for method in METHODS:
            agree_c[method] += int(pids[method] == c_pid)

        real_complete = all(by_course[c]["real_st"] is not None for c in range(1, 7))
        if real_complete:
            real_st = [by_course[c]["real_st"] for c in range(1, 7)]
            actual_pid, _ = classify_slit_pattern(real_st, settings)
            actual_subset += 1
            for method in METHODS:
                actual_match[method] += int(pids[method] == actual_pid)

        processed += 1

    return {
        "start": start_date,
        "end": end_date,
        "terms": terms,
        "target": len(races),
        "processed": processed,
        "skip": skip,
        "baseline": baseline,
        "pattern_counts": pattern_counts,
        "pattern_freq": pattern_freq,
        "agree_c": agree_c,
        "fastest": fastest,
        "actual_subset": actual_subset,
        "actual_match": actual_match,
    }


def pct_value(num, den):
    return (num / den * 100.0) if den else 0.0


def lift_1c(r, method, pid):
    n, win, top3, lift_win, lift_top3 = metrics(
        r["pattern_counts"][method][pid], r["baseline"], 1
    )
    return n, win, top3, lift_win, lift_top3


def print_result(r):
    p = r["processed"]
    print("=" * 96)
    print("スリット体系 進入回数信頼度 K 比較（C vs D5/D10/D20/D40）")
    print("=" * 96)
    print(f"期間       : {r['start']} ～ {r['end']}")
    print(f"使用期     : {', '.join(r['terms'])}")
    print(f"対象レース : {r['target']}")
    print(f"処理レース : {p}")
    print(f"skip not_6_entry              : {r['skip']['not_6_entry']}")
    print(f"skip not_6_exhibition         : {r['skip']['not_6_exhibition']}")
    print(f"skip missing_ex_st            : {r['skip']['missing_ex_st']}")
    print(f"skip bad_result               : {r['skip']['bad_result']}")
    print(f"skip missing_racer_term_or_st : {r['skip']['missing_racer_term_or_st']}")

    print("\n【方式】")
    print("C_ST_RANK : 展示ST + コース平均ST + 平均ST順位（平滑化なし）")
    for k in K_VALUES:
        print(f"D_K{int(k):<2}     : Cのコース成績を n/(n+{int(k)}) で平滑化")

    print("\n【方式サマリ】")
    print("方式        CとPID一致  最速評価艇1着/3連   分離score(1着/3連)   実PID一致※")
    for method in METHODS:
        f = r["fastest"][method]
        sw, st = separation_score(r["pattern_counts"][method], r["baseline"])
        if r["actual_subset"]:
            actual = f"{r['actual_match'][method]}/{r['actual_subset']}={pct_value(r['actual_match'][method], r['actual_subset']):.2f}%"
        else:
            actual = "-"
        print(
            f"{method:<11} "
            f"{pct_value(r['agree_c'][method], p):>9.2f}%   "
            f"{pct_value(f['win'], f['n']):>6.2f}%/{pct_value(f['top3'], f['n']):>6.2f}%   "
            f"{sw*100:>6.2f}/{st*100:>6.2f}pt      {actual}"
        )
    print("※実PID一致は6艇分の本番STがDBに存在するレースだけの補助評価")

    print("\n【主要PIDの1号艇 lift 比較】")
    print("PID 名称         " + "  ".join(f"{m:>11}" for m in METHODS))
    for pid in KEY_PIDS:
        cells = []
        for method in METHODS:
            n, _, _, lw, _ = lift_1c(r, method, pid)
            cells.append(f"{lw*100:+6.2f}({n:>3})")
        print(f"{pid:>2}  {PATTERN_NAMES[pid]:<10} " + "  ".join(f"{x:>11}" for x in cells))

    print("\n【主要PIDの1号艇 3連lift 比較】")
    print("PID 名称         " + "  ".join(f"{m:>11}" for m in METHODS))
    for pid in KEY_PIDS:
        cells = []
        for method in METHODS:
            n, _, _, _, lt = lift_1c(r, method, pid)
            cells.append(f"{lt*100:+6.2f}({n:>3})")
        print(f"{pid:>2}  {PATTERN_NAMES[pid]:<10} " + "  ".join(f"{x:>11}" for x in cells))

    print("\n見るポイント:")
    print("・最速評価艇1着/3連と分離scoreがCより安定して改善するKがあるか")
    print("・PID3/6/8/9/10/12のlift方向が2期間で崩れないか")
    print("・改善が小さい/不安定なら、平滑化なしCを採用する")
    print("・K決定後に12パターンの条件・優先順位を見直す")
    print("=" * 96)


def main():
    if len(sys.argv) != 3:
        print("Usage: python3 analysis/slit_reliability_compare.py YYYY-MM-DD YYYY-MM-DD")
        sys.exit(1)

    try:
        result = analyze(sys.argv[1], sys.argv[2])
    except Exception as e:
        print(f"ERROR: {e}")
        raise

    print_result(result)


if __name__ == "__main__":
    main()
