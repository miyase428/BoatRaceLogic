#!/usr/bin/env python3
import sys
from collections import Counter

from slit_pattern_condition_analyze import prepare_races, extract_features_param
from slit_racer_compare import new_lane_counts, add_result, metrics, separation_score

DELAY = 0.02
LINE = 0.05

PID_NAMES = {
    1: "通常型", 2: "横一線", 3: "1・2先行", 4: "スロー先行",
    5: "壁なし", 6: "2・3遅れ", 7: "中凹み", 8: "3号艇攻め",
    9: "中ぶくれ", 10: "1号艇遅れ", 11: "外側先行", 12: "ダッシュ先行",
}

# CURRENT_02 の優先順位
BASE = [
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

# 1・2先行をスロー先行より前へ。
# dash_fast と one_two_fast は原理上ほぼ競合しないため、実質的に「上位化」の最小変更。
ONE_TWO_UP = [
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

# 中凹みを 2・3遅れより前へ（小さな昇格）
HOLLOW_UP1 = [
    (12, "dash_fast"),
    (4, "inside_fast"),
    (11, "outside_attack"),
    (7, "middle_hollow"),
    (6, "two_three_late"),
    (5, "wall_none"),
    (9, "middle_bulge"),
    (8, "middle_attack"),
    (3, "one_two_fast"),
    (10, "inside_late"),
    (2, "line_abreast"),
]

# 中凹みを外側先行より前へ（やや強い昇格）
HOLLOW_UP2 = [
    (12, "dash_fast"),
    (4, "inside_fast"),
    (7, "middle_hollow"),
    (11, "outside_attack"),
    (6, "two_three_late"),
    (5, "wall_none"),
    (9, "middle_bulge"),
    (8, "middle_attack"),
    (3, "one_two_fast"),
    (10, "inside_late"),
    (2, "line_abreast"),
]

# 1・2先行を上げた上で、中凹みを2・3遅れより前へ
COMBO_UP1 = [
    (12, "dash_fast"),
    (3, "one_two_fast"),
    (4, "inside_fast"),
    (11, "outside_attack"),
    (7, "middle_hollow"),
    (6, "two_three_late"),
    (5, "wall_none"),
    (9, "middle_bulge"),
    (8, "middle_attack"),
    (10, "inside_late"),
    (2, "line_abreast"),
]

# 1・2先行を上げた上で、中凹みを外側先行より前へ
COMBO_UP2 = [
    (12, "dash_fast"),
    (3, "one_two_fast"),
    (4, "inside_fast"),
    (7, "middle_hollow"),
    (11, "outside_attack"),
    (6, "two_three_late"),
    (5, "wall_none"),
    (9, "middle_bulge"),
    (8, "middle_attack"),
    (10, "inside_late"),
    (2, "line_abreast"),
]

CANDIDATES = [
    ("BASE", BASE, "CURRENT_02そのまま"),
    ("ONE_TWO_UP", ONE_TWO_UP, "1・2先行をスロー先行より前へ"),
    ("HOLLOW_UP1", HOLLOW_UP1, "中凹みを2・3遅れより前へ"),
    ("HOLLOW_UP2", HOLLOW_UP2, "中凹みを外側先行より前へ"),
    ("COMBO_UP1", COMBO_UP1, "1・2先行UP + 中凹みを2・3遅れより前へ"),
    ("COMBO_UP2", COMBO_UP2, "1・2先行UP + 中凹みを外側先行より前へ"),
]


def decide(features, priority):
    for pid, key in priority:
        if features.get(key):
            return pid
    return 1


def analyze(prepared, priority):
    baseline = new_lane_counts()
    counts = {pid: new_lane_counts() for pid in range(1, 13)}
    freq = Counter()

    for _, st, finish in prepared:
        for c in range(1, 7):
            add_result(baseline, c, finish[c])
        features = extract_features_param(st, delay_th=DELAY, line_th=LINE)
        pid = decide(features, priority)
        freq[pid] += 1
        for c in range(1, 7):
            add_result(counts[pid], c, finish[c])

    sw, st3 = separation_score(counts, baseline)
    return baseline, counts, freq, sw, st3


def fmt(x):
    return f"{x*100:+6.2f}"


def main():
    if len(sys.argv) != 3:
        print("Usage: python3 analysis/slit_priority_refine_compare.py YYYY-MM-DD YYYY-MM-DD")
        sys.exit(1)

    start, end = sys.argv[1], sys.argv[2]
    prepared, skip, terms = prepare_races(start, end)

    print("=" * 112)
    print("スリット体系 優先順位 局所改善比較（CURRENT_02基準）")
    print("=" * 112)
    print(f"期間       : {start} ～ {end}")
    print(f"使用期     : {', '.join(terms)}")
    print(f"処理レース : {len(prepared)}")
    for k in ["not_6_entry", "not_6_exhibition", "missing_ex_st", "bad_result", "missing_racer_term_or_st"]:
        print(f"skip {k:<27}: {skip[k]}")
    print(f"共通条件   : C_ST_RANK / delay={DELAY:.2f} / line={LINE:.2f}")

    results = {}
    base_sw = base_st3 = None

    print("\n【候補サマリ】")
    print("候補          score 1着/3連       BASE差 1着/3連   通常型率  最小PID件数")
    for name, priority, desc in CANDIDATES:
        baseline, counts, freq, sw, st3 = analyze(prepared, priority)
        results[name] = (baseline, counts, freq, sw, st3)
        if name == "BASE":
            base_sw, base_st3 = sw, st3
        active = [n for n in freq.values() if n > 0]
        normal_rate = freq[1] / len(prepared) if prepared else 0
        min_n = min(active) if active else 0
        dsw = 0.0 if base_sw is None else sw - base_sw
        dst = 0.0 if base_st3 is None else st3 - base_st3
        print(
            f"{name:<13} {sw*100:5.2f}/{st3*100:5.2f}pt   "
            f"{dsw*100:+6.2f}/{dst*100:+6.2f}pt   "
            f"{normal_rate*100:6.2f}%      {min_n:>4}"
        )
        print(f"  └ {desc}")

    focus = [3, 7, 6, 4, 11, 5, 8, 9, 10, 12]
    print("\n【主要PIDの1号艇 1着lift】")
    print("PID 名称        " + " ".join(f"{name:>13}" for name, *_ in CANDIDATES))
    for pid in focus:
        row = [f"{pid:>2}  {PID_NAMES[pid]:<10}"]
        for name, *_ in CANDIDATES:
            baseline, counts, freq, _, _ = results[name]
            n = freq[pid]
            if n == 0:
                row.append(f"{'-':>13}")
            else:
                _, _, _, lw, _ = metrics(counts[pid], baseline, 1)
                row.append(f"{fmt(lw)}({n})")
        print(" ".join(row))

    print("\n【主要PIDの1号艇 3連lift】")
    print("PID 名称        " + " ".join(f"{name:>13}" for name, *_ in CANDIDATES))
    for pid in focus:
        row = [f"{pid:>2}  {PID_NAMES[pid]:<10}"]
        for name, *_ in CANDIDATES:
            baseline, counts, freq, _, _ = results[name]
            n = freq[pid]
            if n == 0:
                row.append(f"{'-':>13}")
            else:
                _, _, _, _, lt = metrics(counts[pid], baseline, 1)
                row.append(f"{fmt(lt)}({n})")
        print(" ".join(row))

    print("\n見るポイント:")
    print("・ONE_TWO_UPが2期間ともBASEを上回れば、1・2先行の局所昇格を採用候補にする")
    print("・HOLLOW_UP1/UP2は改善が小さい場合、無理に変更しない")
    print("・COMBO系が2期間ともONE_TWO_UPを上回る場合だけ、中凹み昇格を追加検討する")
    print("・1号艇遅れ等は今回動かさない。アブレーションで『残すが上げない』ことが確認済み")
    print("=" * 112)


if __name__ == "__main__":
    main()
