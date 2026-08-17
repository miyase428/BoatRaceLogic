#!/usr/bin/env python3
import sys
from collections import Counter

from slit_pattern_condition_analyze import (
    prepare_races,
    extract_features_param,
    FEATURE_NAMES,
)
from slit_racer_compare import (
    new_lane_counts,
    add_result,
    metrics,
    separation_score,
)

DELAY = 0.02
LINE = 0.05

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

PID_NAMES = {
    1: "通常型", 2: "横一線", 3: "1・2先行", 4: "スロー先行",
    5: "壁なし", 6: "2・3遅れ", 7: "中凹み", 8: "3号艇攻め",
    9: "中ぶくれ", 10: "1号艇遅れ", 11: "外側先行", 12: "ダッシュ先行",
}


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
        f = extract_features_param(st, delay_th=DELAY, line_th=LINE)
        pid = decide(f, priority)
        freq[pid] += 1
        for c in range(1, 7):
            add_result(counts[pid], c, finish[c])

    sw, st3 = separation_score(counts, baseline)
    return baseline, counts, freq, sw, st3


def fmt(x):
    return f"{x*100:+6.2f}"


def without_key(key):
    return [(pid, k) for pid, k in PRIORITY if k != key]


def promoted_key(key):
    target = next((x for x in PRIORITY if x[1] == key), None)
    if target is None:
        return list(PRIORITY)
    return [target] + [x for x in PRIORITY if x[1] != key]


def print_profile(baseline, counts, freq):
    print("\n【CURRENT_02 最終PIDの全6コース profile】")
    print("各セル: 1着lift/3連lift (pt)")
    print("PID 名称           R数 |      1C          2C          3C          4C          5C          6C")
    for pid in range(1, 13):
        n = freq[pid]
        if not n:
            print(f"{pid:>2}  {PID_NAMES[pid]:<14} {0:>5} | -")
            continue
        cells = []
        for c in range(1, 7):
            _, _, _, lw, lt = metrics(counts[pid], baseline, c)
            cells.append(f"{fmt(lw)}/{fmt(lt)}")
        print(f"{pid:>2}  {PID_NAMES[pid]:<14} {n:>5} | " + " ".join(f"{x:>13}" for x in cells))


def main():
    if len(sys.argv) != 3:
        print("Usage: python3 analysis/slit_pattern_ablation_analyze.py YYYY-MM-DD YYYY-MM-DD")
        sys.exit(1)

    start, end = sys.argv[1], sys.argv[2]
    prepared, skip, terms = prepare_races(start, end)

    baseline, counts, freq, base_sw, base_st3 = analyze(prepared, PRIORITY)

    print("=" * 112)
    print("スリット体系 条件アブレーション・単独昇格診断（CURRENT_02基準）")
    print("=" * 112)
    print(f"期間       : {start} ～ {end}")
    print(f"使用期     : {', '.join(terms)}")
    print(f"処理レース : {len(prepared)}")
    for k in ["not_6_entry", "not_6_exhibition", "missing_ex_st", "bad_result", "missing_racer_term_or_st"]:
        print(f"skip {k:<27}: {skip[k]}")
    print(f"基準       : C_ST_RANK / delay={DELAY:.2f} / line={LINE:.2f} / 現行優先順位")
    print(f"基準score  : {base_sw*100:.2f}/{base_st3*100:.2f}pt (1着/3連)")

    print("\n【1条件ずつ削除した場合】")
    print("条件              元PID件数 | score 1着/3連      基準差 1着/3連")
    for pid, key in PRIORITY:
        _, _, _, sw, st3 = analyze(prepared, without_key(key))
        print(
            f"{PID_NAMES[pid]:<14} {freq[pid]:>7} | "
            f"{sw*100:5.2f}/{st3*100:5.2f}pt   "
            f"{(sw-base_sw)*100:+6.2f}/{(st3-base_st3)*100:+6.2f}pt"
        )

    print("\n【1条件だけ最上位へ昇格した場合】")
    print("条件              元順位 | score 1着/3連      基準差 1着/3連")
    for idx, (pid, key) in enumerate(PRIORITY, start=1):
        _, _, _, sw, st3 = analyze(prepared, promoted_key(key))
        print(
            f"{PID_NAMES[pid]:<14} {idx:>4}位 | "
            f"{sw*100:5.2f}/{st3*100:5.2f}pt   "
            f"{(sw-base_sw)*100:+6.2f}/{(st3-base_st3)*100:+6.2f}pt"
        )

    print_profile(baseline, counts, freq)

    print("\n見るポイント:")
    print("・削除で2期間ともscoreが上がる条件は削除/統合候補")
    print("・削除でscoreが下がる条件は、現行順位の中で独立情報を持つ")
    print("・単独昇格で2期間とも改善する条件だけ優先順位アップ候補")
    print("・1Cが弱くても外艇側のliftが強ければ、条件自体は残す価値がある")
    print("・横一線は件数が極小なら、削除または条件再定義を検討")
    print("=" * 112)


if __name__ == "__main__":
    main()
