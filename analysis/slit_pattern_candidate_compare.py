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

# 現行優先順位
CURRENT_PRIORITY = [
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

# 強い特徴を前へ。弱い条件も残す版
STRONG_PRIORITY = [
    (10, "inside_late"),
    (3, "one_two_fast"),
    (9, "middle_bulge"),
    (8, "middle_attack"),
    (6, "two_three_late"),
    (7, "middle_hollow"),
    (4, "inside_fast"),
    (5, "wall_none"),
    (12, "dash_fast"),
    (11, "outside_attack"),
]

# 2期間で方向が比較的安定した条件だけ残す版
COMPACT_PRIORITY = [
    (10, "inside_late"),
    (3, "one_two_fast"),
    (9, "middle_bulge"),
    (8, "middle_attack"),
    (6, "two_three_late"),
    (7, "middle_hollow"),
    (4, "inside_fast"),
]

CANDIDATES = [
    ("CURRENT_04", 0.04, CURRENT_PRIORITY, "現行12条件/現行優先順位"),
    ("CURRENT_02", 0.02, CURRENT_PRIORITY, "現行優先順位＋delay0.02"),
    ("STRONG_04", 0.04, STRONG_PRIORITY, "強い特徴を優先、弱条件は残す"),
    ("STRONG_02", 0.02, STRONG_PRIORITY, "強い特徴優先＋delay0.02"),
    ("COMPACT_04", 0.04, COMPACT_PRIORITY, "安定7条件＋通常型"),
    ("COMPACT_02", 0.02, COMPACT_PRIORITY, "安定7条件＋通常型＋delay0.02"),
]

PID_NAMES = {
    1:"通常型", 3:"1・2先行", 4:"スロー先行", 5:"壁なし", 6:"2・3遅れ",
    7:"中凹み", 8:"3号艇攻め", 9:"中ぶくれ", 10:"1号艇遅れ",
    11:"外側先行", 12:"ダッシュ先行",
}


def decide(features, priority):
    for pid, key in priority:
        if features.get(key):
            return pid
    return 1


def analyze_candidate(prepared, delay, priority):
    baseline = new_lane_counts()
    counts = {pid: new_lane_counts() for pid in range(1, 13)}
    freq = Counter()
    for _, st, finish in prepared:
        for c in range(1, 7):
            add_result(baseline, c, finish[c])
        f = extract_features_param(st, delay_th=delay, line_th=0.05)
        pid = decide(f, priority)
        freq[pid] += 1
        for c in range(1, 7):
            add_result(counts[pid], c, finish[c])
    sw, st3 = separation_score(counts, baseline)
    return baseline, counts, freq, sw, st3


def fmt_lift(x):
    return f"{x*100:+6.2f}"


def main():
    if len(sys.argv) != 3:
        print("Usage: python3 analysis/slit_pattern_candidate_compare.py YYYY-MM-DD YYYY-MM-DD")
        sys.exit(1)

    start, end = sys.argv[1], sys.argv[2]
    prepared, skip, terms = prepare_races(start, end)
    total = len(prepared)

    print("="*104)
    print("スリット体系 パターン候補比較（C_ST_RANK基準）")
    print("="*104)
    print(f"期間       : {start} ～ {end}")
    print(f"使用期     : {', '.join(terms)}")
    print(f"処理レース : {total}")
    for k in ["not_6_entry","not_6_exhibition","missing_ex_st","bad_result","missing_racer_term_or_st"]:
        print(f"skip {k:<27}: {skip[k]}")

    results = {}
    print("\n【候補サマリ】")
    print("候補          delay  有効PID  分離score 1着/3連   通常型率   最小PID件数")
    for name, delay, priority, desc in CANDIDATES:
        baseline, counts, freq, sw, st3 = analyze_candidate(prepared, delay, priority)
        results[name] = (baseline, counts, freq)
        active = [n for pid, n in freq.items() if n > 0]
        effective = len(active)
        normal_rate = freq[1] / total if total else 0
        min_n = min(active) if active else 0
        print(f"{name:<13} {delay:>4.2f}   {effective:>3}PID    {sw*100:5.2f}/{st3*100:5.2f}pt   {normal_rate*100:6.2f}%      {min_n:>4}")
        print(f"  └ {desc}")

    focus = [3,6,8,9,10,4,7,12,11,5]
    print("\n【主要PID 1号艇1着lift】")
    print("PID 名称        " + " ".join(f"{name:>12}" for name, *_ in CANDIDATES))
    for pid in focus:
        row = [f"{pid:>2}  {PID_NAMES.get(pid,str(pid)):<10}"]
        for name, *_ in CANDIDATES:
            baseline, counts, freq = results[name]
            n = freq[pid]
            if n == 0:
                row.append(f"{'-':>12}")
            else:
                _, _, _, lw, _ = metrics(counts[pid], baseline, 1)
                row.append(f"{fmt_lift(lw)}({n})")
        print(" ".join(row))

    print("\n【主要PID 1号艇3連lift】")
    print("PID 名称        " + " ".join(f"{name:>12}" for name, *_ in CANDIDATES))
    for pid in focus:
        row = [f"{pid:>2}  {PID_NAMES.get(pid,str(pid)):<10}"]
        for name, *_ in CANDIDATES:
            baseline, counts, freq = results[name]
            n = freq[pid]
            if n == 0:
                row.append(f"{'-':>12}")
            else:
                _, _, _, _, lt = metrics(counts[pid], baseline, 1)
                row.append(f"{fmt_lift(lt)}({n})")
        print(" ".join(row))

    print("\n見るポイント:")
    print("・CURRENT_02で閾値変更だけの効果を見る")
    print("・STRONG系で強い条件を先に出す効果を見る")
    print("・COMPACT系で弱い/不安定な条件を落としても分離力が落ちないかを見る")
    print("・2期間とも同じ候補が優位なら、その候補を次の本命ルールにする")
    print("="*104)

if __name__ == "__main__":
    main()
