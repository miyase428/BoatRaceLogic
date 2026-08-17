#!/usr/bin/env python3
import sys
from collections import Counter

from slit_pattern_condition_analyze import prepare_races, extract_features_param
from slit_racer_compare import new_lane_counts, add_result, metrics, separation_score

DELAY = 0.02

# ここまでの暫定本命: ONE_TWO_UP
BASE_PRIORITY = [
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

WITHOUT_LINE = [(pid, key) for pid, key in BASE_PRIORITY if key != "line_abreast"]
TOP_LINE = [(2, "line_abreast")] + WITHOUT_LINE

CANDIDATES = [
    ("BASE_BOTTOM05", 0.05, BASE_PRIORITY, "現行定義: spread<=0.05 / 最下位判定"),
    ("DROP_LINE", 0.05, WITHOUT_LINE, "横一線を削除"),
    ("TOP_04", 0.04, TOP_LINE, "spread<=0.04 を最優先"),
    ("TOP_05", 0.05, TOP_LINE, "spread<=0.05 を最優先"),
    ("TOP_06", 0.06, TOP_LINE, "spread<=0.06 を最優先"),
    ("TOP_07", 0.07, TOP_LINE, "spread<=0.07 を最優先"),
    ("TOP_08", 0.08, TOP_LINE, "spread<=0.08 を最優先"),
    ("TOP_10", 0.10, TOP_LINE, "spread<=0.10 を最優先"),
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


def analyze(prepared, line_th, priority):
    baseline = new_lane_counts()
    counts = {pid: new_lane_counts() for pid in range(1, 13)}
    freq = Counter()
    raw_line = 0

    for _, st, finish in prepared:
        for c in range(1, 7):
            add_result(baseline, c, finish[c])

        f = extract_features_param(st, delay_th=DELAY, line_th=line_th)
        if f.get("line_abreast"):
            raw_line += 1

        pid = decide(f, priority)
        freq[pid] += 1
        for c in range(1, 7):
            add_result(counts[pid], c, finish[c])

    sw, st3 = separation_score(counts, baseline)
    return baseline, counts, freq, raw_line, sw, st3


def fmt(x):
    return f"{x*100:+6.2f}"


def main():
    if len(sys.argv) != 3:
        print("Usage: python3 analysis/slit_line_abreast_compare.py YYYY-MM-DD YYYY-MM-DD")
        sys.exit(1)

    start, end = sys.argv[1], sys.argv[2]
    prepared, skip, terms = prepare_races(start, end)

    print("=" * 120)
    print("スリット体系 横一線 条件・優先順位比較（ONE_TWO_UP / C_ST_RANK / delay=0.02）")
    print("=" * 120)
    print(f"期間       : {start} ～ {end}")
    print(f"使用期     : {', '.join(terms)}")
    print(f"処理レース : {len(prepared)}")
    for k in ["not_6_entry", "not_6_exhibition", "missing_ex_st", "bad_result", "missing_racer_term_or_st"]:
        print(f"skip {k:<27}: {skip[k]}")

    results = {}
    print("\n【候補サマリ】")
    print("候補           line  raw成立  PID2件数  分離score 1着/3連   PID2 1C lift(1着/3連)   説明")
    for name, line_th, priority, desc in CANDIDATES:
        baseline, counts, freq, raw_line, sw, st3 = analyze(prepared, line_th, priority)
        results[name] = (baseline, counts, freq, raw_line, sw, st3)
        n = freq[2]
        if n:
            _, _, _, lw, lt = metrics(counts[2], baseline, 1)
            lift = f"{fmt(lw)}/{fmt(lt)}"
        else:
            lift = "-"
        print(
            f"{name:<14} {line_th:>4.2f} {raw_line:>7} {n:>8}   "
            f"{sw*100:5.2f}/{st3*100:5.2f}pt       {lift:>15}   {desc}"
        )

    print("\n【横一線PID2の全6コース profile】")
    print("候補             R数 |      1C          2C          3C          4C          5C          6C")
    for name, *_ in CANDIDATES:
        baseline, counts, freq, _, _, _ = results[name]
        n = freq[2]
        if not n:
            print(f"{name:<16} {0:>5} | -")
            continue
        cells = []
        for c in range(1, 7):
            _, _, _, lw, lt = metrics(counts[2], baseline, c)
            cells.append(f"{fmt(lw)}/{fmt(lt)}")
        print(f"{name:<16} {n:>5} | " + " ".join(f"{x:>13}" for x in cells))

    print("\n見るポイント:")
    print("・BASE_BOTTOM05とDROP_LINEがほぼ同じなら、現行の横一線は実質機能していない")
    print("・TOP系で2期間とも分離scoreが改善する閾値があれば、横一線は『最優先条件』として再定義候補")
    print("・閾値を広げて件数だけ増え、scoreが落ちるなら横一線は削除候補")
    print("・PID2の全6コースprofileが基準に近いなら『横一線=補正なし/通常型寄り』という扱いも検討")
    print("=" * 120)


if __name__ == "__main__":
    main()
