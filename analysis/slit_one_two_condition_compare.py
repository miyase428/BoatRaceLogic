#!/usr/bin/env python3
import sys
from collections import Counter

from slit_pattern_condition_analyze import prepare_races, extract_features_param
from slit_racer_compare import new_lane_counts, add_result, metrics, separation_score

DELAY = 0.02
LINE = 0.05

# ONE_TWO_UP を基準にする
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

CANDIDATES = [
    ("EXACT_12", "exact", 0.00, "現行: 1C最速→2C 2番手"),
    ("TOP2_ANY", "top2", 0.00, "1C/2Cが上位2艇なら順不同"),
    ("EXACT_GAP01", "exact", 0.01, "EXACT_12 + 3番手へ0.01秒差"),
    ("EXACT_GAP02", "exact", 0.02, "EXACT_12 + 3番手へ0.02秒差"),
    ("TOP2_GAP01", "top2", 0.01, "TOP2_ANY + 3番手へ0.01秒差"),
    ("TOP2_GAP02", "top2", 0.02, "TOP2_ANY + 3番手へ0.02秒差"),
]


def decide(features):
    for pid, key in PRIORITY:
        if features.get(key):
            return pid
    return 1


def one_two_condition(st, mode, gap):
    order = sorted(range(6), key=lambda i: st[i])
    if mode == "exact":
        ok = order[0] == 0 and order[1] == 1
    else:
        ok = set(order[:2]) == {0, 1}
    if not ok:
        return False
    if gap > 0:
        # STは小さいほど速い。3番手 - 2番手 が正の値なら上位2艇が先行。
        return (st[order[2]] - st[order[1]]) >= gap
    return True


def analyze(prepared, mode, gap):
    baseline = new_lane_counts()
    counts = {pid: new_lane_counts() for pid in range(1, 13)}
    freq = Counter()
    order_split = Counter()
    split_counts = {
        "12": new_lane_counts(),
        "21": new_lane_counts(),
    }

    for _, st, finish in prepared:
        for c in range(1, 7):
            add_result(baseline, c, finish[c])

        f = extract_features_param(st, delay_th=DELAY, line_th=LINE)
        f["one_two_fast"] = one_two_condition(st, mode, gap)

        order = sorted(range(6), key=lambda i: st[i])
        if set(order[:2]) == {0, 1}:
            label = "12" if order[0] == 0 else "21"
            order_split[label] += 1
            for c in range(1, 7):
                add_result(split_counts[label], c, finish[c])

        pid = decide(f)
        freq[pid] += 1
        for c in range(1, 7):
            add_result(counts[pid], c, finish[c])

    sw, st3 = separation_score(counts, baseline)
    return baseline, counts, freq, sw, st3, order_split, split_counts


def fmt(x):
    return f"{x*100:+6.2f}"


def main():
    if len(sys.argv) != 3:
        print("Usage: python3 analysis/slit_one_two_condition_compare.py YYYY-MM-DD YYYY-MM-DD")
        sys.exit(1)

    start, end = sys.argv[1], sys.argv[2]
    prepared, skip, terms = prepare_races(start, end)

    print("=" * 116)
    print("スリット体系 1・2先行 条件定義比較（ONE_TWO_UP / C_ST_RANK / delay=0.02）")
    print("=" * 116)
    print(f"期間       : {start} ～ {end}")
    print(f"使用期     : {', '.join(terms)}")
    print(f"処理レース : {len(prepared)}")
    for k in ["not_6_entry", "not_6_exhibition", "missing_ex_st", "bad_result", "missing_racer_term_or_st"]:
        print(f"skip {k:<27}: {skip[k]}")

    results = {}
    print("\n【候補サマリ】")
    print("候補          PID3件数  分離score 1着/3連   PID3 1C lift(1着/3連)   説明")
    for name, mode, gap, desc in CANDIDATES:
        baseline, counts, freq, sw, st3, order_split, split_counts = analyze(prepared, mode, gap)
        results[name] = (baseline, counts, freq, sw, st3, order_split, split_counts)
        n = freq[3]
        if n:
            _, _, _, lw, lt = metrics(counts[3], baseline, 1)
            lift_text = f"{fmt(lw)}/{fmt(lt)}"
        else:
            lift_text = "-"
        print(f"{name:<13} {n:>7}   {sw*100:5.2f}/{st3*100:5.2f}pt       {lift_text:>15}   {desc}")

    # TOP2の中で 1→2 と 2→1 の性質を直接見る（候補定義とは独立）
    base = results["TOP2_ANY"]
    baseline, _, _, _, _, order_split, split_counts = base
    print("\n【1C/2Cが上位2艇のときの順序別成績】")
    print("順序     R数 | 1C 1着lift/3連lift | 2C 1着lift/3連lift")
    for label, desc in [("12", "1→2"), ("21", "2→1")]:
        n = order_split[label]
        if not n:
            print(f"{desc:<6} {0:>5} | -")
            continue
        _, _, _, l1w, l1t = metrics(split_counts[label], baseline, 1)
        _, _, _, l2w, l2t = metrics(split_counts[label], baseline, 2)
        print(f"{desc:<6} {n:>5} | {fmt(l1w)}/{fmt(l1t)} | {fmt(l2w)}/{fmt(l2t)}")

    print("\n見るポイント:")
    print("・TOP2_ANYが2期間ともEXACT_12を上回るなら、2→1も『1・2先行』へ含める")
    print("・2→1で1C成績が明確に弱いなら、現行EXACT_12を維持する")
    print("・GAP01/GAP02が2期間とも改善するなら、3番手との差を条件へ追加する")
    print("・差が小さい場合は、最も単純な条件を優先する")
    print("=" * 116)


if __name__ == "__main__":
    main()
