#!/usr/bin/env python3
import json
import sys
from collections import Counter, defaultdict
from datetime import datetime
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
THEORY_DIR = REPO_ROOT / "theories" / "course_correction"
sys.path.insert(0, str(THEORY_DIR))
sys.path.insert(0, str(Path(__file__).resolve().parent))

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
    metrics,
    separation_score,
)

PATTERN_NAMES = {
    1: "通常型", 2: "横一線", 3: "1・2先行", 4: "スロー先行",
    5: "壁なし", 6: "2・3遅れ", 7: "中凹み", 8: "3号艇攻め",
    9: "中ぶくれ", 10: "1号艇遅れ", 11: "外側先行", 12: "ダッシュ先行",
}

FEATURE_NAMES = {
    "inside_fast": "スロー先行",
    "wall_none": "壁なし",
    "middle_attack": "3号艇攻め",
    "dash_fast": "ダッシュ先行",
    "inside_late": "1号艇遅れ",
    "line_abreast": "横一線",
    "two_three_late": "2・3遅れ",
    "middle_hollow": "中凹み",
    "middle_bulge": "中ぶくれ",
    "one_two_fast": "1・2先行",
    "outside_attack": "外側先行",
}

# 現行 decide_pattern() の優先順位
PRIORITY = [
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


def mean(xs):
    return sum(xs) / len(xs)


def extract_features_param(st_list, delay_th=0.04, line_th=0.05):
    avg_st = mean(st_list)
    st_diff = [st - avg_st for st in st_list]
    order = sorted(range(len(st_list)), key=lambda i: st_list[i])
    spread = max(st_list) - min(st_list)

    f = {k: False for k in FEATURE_NAMES}

    if st_diff[0] >= delay_th:
        f["inside_late"] = True
    if st_diff[1] >= delay_th and st_diff[2] >= delay_th:
        f["two_three_late"] = True
    if st_diff[2] >= delay_th and st_diff[3] >= delay_th:
        f["middle_hollow"] = True
    if set(order[:2]) == {2, 3}:
        f["middle_bulge"] = True
    if order[0] == 0 and order[1] == 1:
        f["one_two_fast"] = True
    if order[0] in {3, 4, 5} and order[1] in {3, 4, 5}:
        f["outside_attack"] = True
    if spread <= line_th:
        f["line_abreast"] = True
    if set(order[:3]).issubset({0, 1, 2}):
        f["inside_fast"] = True
    if set(order[:3]).issubset({3, 4, 5}):
        f["dash_fast"] = True
    if st_diff[1] >= delay_th:
        f["wall_none"] = True
    if order[0] == 2:
        f["middle_attack"] = True

    return f


def decide_pattern(features):
    for pid, key in PRIORITY:
        if features.get(key):
            return pid
    return 1


def pct(v):
    return f"{v * 100:6.2f}%"


def pp(v):
    return f"{v * 100:+6.2f}pt"


def prepare_races(start_date, end_date):
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
        c_st = make_method_st(ex_st, profiles)["C_ST_RANK"]
        prepared.append((race_code, c_st, finish))

    return prepared, skip, terms


def feature_analysis(prepared, delay_th=0.04, line_th=0.05):
    baseline = new_lane_counts()
    feature_counts = {k: new_lane_counts() for k in FEATURE_NAMES}
    feature_races = Counter()
    overlap_pairs = Counter()
    overlap_degree = Counter()
    masked_by = {k: Counter() for k in FEATURE_NAMES}
    final_freq = Counter()
    final_counts = {pid: new_lane_counts() for pid in range(1, 13)}

    for _, st_list, finish in prepared:
        for c in range(1, 7):
            add_result(baseline, c, finish[c])

        f = extract_features_param(st_list, delay_th, line_th)
        active = [k for k, v in f.items() if v]
        overlap_degree[len(active)] += 1
        for i in range(len(active)):
            for j in range(i + 1, len(active)):
                overlap_pairs[tuple(sorted((active[i], active[j])))] += 1

        pid = decide_pattern(f)
        final_freq[pid] += 1
        for c in range(1, 7):
            add_result(final_counts[pid], c, finish[c])

        winner_key = next((key for p, key in PRIORITY if p == pid), None)
        for key in active:
            feature_races[key] += 1
            for c in range(1, 7):
                add_result(feature_counts[key], c, finish[c])
            if key != winner_key and winner_key is not None:
                masked_by[key][winner_key] += 1

    return {
        "baseline": baseline,
        "feature_counts": feature_counts,
        "feature_races": feature_races,
        "overlap_pairs": overlap_pairs,
        "overlap_degree": overlap_degree,
        "masked_by": masked_by,
        "final_freq": final_freq,
        "final_counts": final_counts,
    }


def print_feature_table(a):
    print("\n【特徴フラグ単体の成績（最終PIDに関係なく条件成立で集計）】")
    print("特徴              R数 | 1C 1着/lift       1C 3連/lift       最強外艇(2-6) 1着/lift")
    for key in FEATURE_NAMES:
        n = a["feature_races"][key]
        if n == 0:
            print(f"{FEATURE_NAMES[key]:<14} {0:>5} | -")
            continue
        _, w1, t31, lw1, lt31 = metrics(a["feature_counts"][key], a["baseline"], 1)
        best = None
        for c in range(2, 7):
            _, w, _, lw, _ = metrics(a["feature_counts"][key], a["baseline"], c)
            cand = (lw, w, c)
            if best is None or cand > best:
                best = cand
        bl, bw, bc = best
        print(
            f"{FEATURE_NAMES[key]:<14} {n:>5} | "
            f"{pct(w1)}/{pp(lw1):>9}  {pct(t31)}/{pp(lt31):>9}  "
            f"{bc}C {pct(bw)}/{pp(bl)}"
        )


def print_overlap(a, total):
    print("\n【条件重複数】")
    for degree in sorted(a["overlap_degree"]):
        n = a["overlap_degree"][degree]
        print(f"成立条件 {degree:>2}個 : {n:>5}R ({n/total*100:6.2f}%)")

    print("\n【重複条件 上位20ペア】")
    for (a1, a2), n in a["overlap_pairs"].most_common(20):
        print(f"{FEATURE_NAMES[a1]:<14} × {FEATURE_NAMES[a2]:<14} : {n:>5}R")


def print_masking(a):
    print("\n【現在の優先順位で隠される条件】")
    print("条件              成立R   最終PIDになれなかった主因")
    for key in FEATURE_NAMES:
        total = a["feature_races"][key]
        masked = sum(a["masked_by"][key].values())
        if total == 0:
            continue
        top = a["masked_by"][key].most_common(3)
        reason = ", ".join(f"{FEATURE_NAMES[k]}={n}" for k, n in top) if top else "なし"
        print(f"{FEATURE_NAMES[key]:<14} {total:>5}  masked={masked:>5} ({masked/total*100:5.1f}%)  {reason}")


def threshold_sweep(prepared):
    print("\n【閾値感度: big_delay_threshold × line_abreast_threshold】")
    print("delay line | 分離score 1着/3連 | PID10(1遅れ) R/lift | PID5(壁なし) R/lift | PID2(横一線) R")
    for delay in [0.02, 0.03, 0.04, 0.05, 0.06]:
        for line in [0.03, 0.04, 0.05, 0.06, 0.07]:
            a = feature_analysis(prepared, delay, line)
            sw, st = separation_score(a["final_counts"], a["baseline"])
            n10 = a["final_freq"][10]
            _, _, _, l10, _ = metrics(a["final_counts"][10], a["baseline"], 1) if n10 else (0,0,0,0,0)
            n5 = a["final_freq"][5]
            _, _, _, l5, _ = metrics(a["final_counts"][5], a["baseline"], 1) if n5 else (0,0,0,0,0)
            n2 = a["final_freq"][2]
            mark = " *current" if abs(delay-0.04)<1e-9 and abs(line-0.05)<1e-9 else ""
            print(
                f"{delay:>4.2f} {line:>4.2f} | {sw*100:5.2f}/{st*100:5.2f}pt | "
                f"{n10:>4}/{l10*100:+6.2f} | {n5:>4}/{l5*100:+6.2f} | {n2:>4}{mark}"
            )


def print_final_patterns(a, total):
    print("\n【現在条件・優先順位での最終PID】")
    print("PID 名称           R数  構成比 | 1C 1着/lift     1C 3連/lift")
    for pid in range(1, 13):
        n = a["final_freq"][pid]
        if n == 0:
            print(f"{pid:>2}  {PATTERN_NAMES[pid]:<14} {0:>5} {0:>6.2f}% | -")
            continue
        _, w, t3, lw, lt = metrics(a["final_counts"][pid], a["baseline"], 1)
        print(f"{pid:>2}  {PATTERN_NAMES[pid]:<14} {n:>5} {n/total*100:>6.2f}% | {pct(w)}/{pp(lw)}  {pct(t3)}/{pp(lt)}")


def main():
    if len(sys.argv) != 3:
        print("Usage: python3 analysis/slit_pattern_condition_analyze.py YYYY-MM-DD YYYY-MM-DD")
        sys.exit(1)

    start_date, end_date = sys.argv[1], sys.argv[2]
    prepared, skip, terms = prepare_races(start_date, end_date)
    total = len(prepared)

    print("=" * 100)
    print("スリット体系 12パターン 条件・優先順位 健康診断（C_ST_RANK基準）")
    print("=" * 100)
    print(f"期間       : {start_date} ～ {end_date}")
    print(f"使用期     : {', '.join(terms)}")
    print(f"処理レース : {total}")
    for key in ["not_6_entry", "not_6_exhibition", "missing_ex_st", "bad_result", "missing_racer_term_or_st"]:
        print(f"skip {key:<27}: {skip[key]}")
    print("基準方式   : C_ST_RANK（展示ST + コース平均ST + 平均ST順位、進入回数平滑化なし）")
    print("現行閾値   : big_delay=0.04 / line_abreast spread<=0.05")

    if total == 0:
        return

    current = feature_analysis(prepared, 0.04, 0.05)
    print_feature_table(current)
    print_overlap(current, total)
    print_masking(current)
    print_final_patterns(current, total)
    threshold_sweep(prepared)

    print("\n見るポイント:")
    print("・単体では強いのに優先順位で頻繁に隠れる条件がないか")
    print("・単体成績が弱い/2期間で方向が反転する条件はないか")
    print("・ほぼ常に同時成立する条件は統合候補")
    print("・閾値変更で分離scoreと主要PID liftが2期間とも改善する領域があるか")
    print("・この診断後に、条件削除/統合/優先順位変更を候補化して再比較する")
    print("=" * 100)


if __name__ == "__main__":
    main()
