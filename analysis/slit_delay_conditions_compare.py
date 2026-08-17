#!/usr/bin/env python3
import sys
from collections import Counter

from slit_pattern_condition_analyze import prepare_races, extract_features_param
from slit_racer_compare import new_lane_counts, add_result, metrics, separation_score

BASE_DELAY = 0.02
LINE = 0.05
THRESHOLDS = [0.01, 0.02, 0.03, 0.04, 0.05]

# ここまでの暫定基準:
# C_ST_RANK / delay=0.02 / 1・2先行をスロー先行より前へ
# 横一線・外側先行・ダッシュ先行・3号艇攻め・中ぶくれは現行定義維持
PRIORITY = [
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

TARGETS = [
    ("inside_late", 10, "1号艇遅れ", [1, 2]),
    ("wall_none", 5, "壁なし", [1, 2, 3]),
    ("two_three_late", 6, "2・3遅れ", [1, 2, 3]),
    ("middle_hollow", 7, "中凹み", [1, 2, 3, 4]),
]


def decide(features):
    for pid, key in PRIORITY:
        if features.get(key):
            return pid
    return 1


def mean(xs):
    return sum(xs) / len(xs)


def override_target(st, features, target_key, threshold):
    avg_st = mean(st)
    d = [x - avg_st for x in st]

    if target_key == "inside_late":
        features[target_key] = d[0] >= threshold
    elif target_key == "wall_none":
        features[target_key] = d[1] >= threshold
    elif target_key == "two_three_late":
        features[target_key] = d[1] >= threshold and d[2] >= threshold
    elif target_key == "middle_hollow":
        features[target_key] = d[2] >= threshold and d[3] >= threshold
    else:
        raise ValueError(target_key)
    return features


def analyze(prepared, target_key=None, threshold=BASE_DELAY):
    baseline = new_lane_counts()
    counts = {pid: new_lane_counts() for pid in range(1, 13)}
    freq = Counter()

    for _, st, finish in prepared:
        for c in range(1, 7):
            add_result(baseline, c, finish[c])

        f = extract_features_param(st, delay_th=BASE_DELAY, line_th=LINE)
        if target_key is not None:
            f = override_target(st, f, target_key, threshold)

        pid = decide(f)
        freq[pid] += 1
        for c in range(1, 7):
            add_result(counts[pid], c, finish[c])

    sw, st3 = separation_score(counts, baseline)
    return baseline, counts, freq, sw, st3


def fmt(x):
    return f"{x * 100:+6.2f}"


def profile_text(counts, baseline, course):
    _, _, _, lw, lt = metrics(counts, baseline, course)
    return f"{fmt(lw)}/{fmt(lt)}"


def main():
    if len(sys.argv) != 3:
        print("Usage: python3 analysis/slit_delay_conditions_compare.py YYYY-MM-DD YYYY-MM-DD")
        sys.exit(1)

    start, end = sys.argv[1], sys.argv[2]
    prepared, skip, terms = prepare_races(start, end)

    base_baseline, base_counts, base_freq, base_sw, base_st3 = analyze(prepared)

    print("=" * 126)
    print("スリット体系 遅れ系4条件 個別threshold比較（ONE_TWO_UP / C_ST_RANK）")
    print("=" * 126)
    print(f"期間       : {start} ～ {end}")
    print(f"使用期     : {', '.join(terms)}")
    print(f"処理レース : {len(prepared)}")
    for k in ["not_6_entry", "not_6_exhibition", "missing_ex_st", "bad_result", "missing_racer_term_or_st"]:
        print(f"skip {k:<27}: {skip[k]}")
    print(f"基準       : 各遅れ条件 threshold={BASE_DELAY:.2f} / line={LINE:.2f} / 1・2先行UP")
    print(f"基準score  : {base_sw*100:.2f}/{base_st3*100:.2f}pt (1着/3連)")

    for key, pid, name, courses in TARGETS:
        print("\n" + "-" * 126)
        print(f"【{name} / PID{pid}】 この条件だけthreshold変更（他の遅れ条件は0.02固定）")
        print("th    PID件数   score 1着/3連       BASE差 1着/3連   profile(1着lift/3連lift)")

        for th in THRESHOLDS:
            baseline, counts, freq, sw, st3 = analyze(prepared, key, th)
            profiles = "  ".join(f"{c}C {profile_text(counts[pid], baseline, c)}" for c in courses)
            mark = " *BASE" if abs(th - BASE_DELAY) < 1e-9 else ""
            print(
                f"{th:0.02f}  {freq[pid]:>7}   {sw*100:5.2f}/{st3*100:5.2f}pt   "
                f"{(sw-base_sw)*100:+6.2f}/{(st3-base_st3)*100:+6.2f}pt   {profiles}{mark}"
            )

    print("\n見るポイント:")
    print("・1条件だけthresholdを変え、2期間とも1着/3連scoreがBASE以上になる値があるか")
    print("・1号艇遅れは1Cマイナス/2Cプラス、壁なしは2C低下と3C上昇など、展開profileが強まるか")
    print("・2・3遅れ/中凹みは件数を減らしすぎず、関連コースのlift方向が2期間で安定するか")
    print("・片期間だけ改善する値は採用しない。候補が出た場合だけ未使用期間HOLDOUTへ回す")
    print("=" * 126)


if __name__ == "__main__":
    main()
