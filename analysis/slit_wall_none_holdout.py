#!/usr/bin/env python3
import sys
from collections import Counter

from slit_pattern_condition_analyze import prepare_races, extract_features_param
from slit_racer_compare import new_lane_counts, add_result, metrics, separation_score

BASE_DELAY = 0.02
WALL_NEW_DELAY = 0.01
LINE = 0.05

# ここまでの暫定基準:
# C_ST_RANK / 1・2先行をスロー先行より前へ
# 横一線・外側先行・ダッシュ先行・3号艇攻め・中ぶくれは現行定義維持
# 遅れ系は原則0.02。今回だけ wall_none=0.01 を固定候補としてHOLDOUTする。
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


def decide(features):
    for pid, key in PRIORITY:
        if features.get(key):
            return pid
    return 1


def mean(xs):
    return sum(xs) / len(xs)


def analyze(prepared, wall_delay):
    baseline = new_lane_counts()
    counts = {pid: new_lane_counts() for pid in range(1, 13)}
    freq = Counter()

    for _, st, finish in prepared:
        for c in range(1, 7):
            add_result(baseline, c, finish[c])

        # 他の遅れ系はすべて0.02固定
        f = extract_features_param(st, delay_th=BASE_DELAY, line_th=LINE)

        # 壁なしだけ個別thresholdで上書き
        avg_st = mean(st)
        st_diff = [x - avg_st for x in st]
        f["wall_none"] = st_diff[1] >= wall_delay

        pid = decide(f)
        freq[pid] += 1
        for c in range(1, 7):
            add_result(counts[pid], c, finish[c])

    sw, st3 = separation_score(counts, baseline)
    return baseline, counts, freq, sw, st3


def fmt(x):
    return f"{x * 100:+6.2f}"


def profile(counts, baseline, course):
    _, _, _, lw, lt = metrics(counts, baseline, course)
    return f"{fmt(lw)}/{fmt(lt)}"


def main():
    if len(sys.argv) != 3:
        print("Usage: python3 analysis/slit_wall_none_holdout.py YYYY-MM-DD YYYY-MM-DD")
        sys.exit(1)

    start, end = sys.argv[1], sys.argv[2]
    prepared, skip, terms = prepare_races(start, end)

    base = analyze(prepared, BASE_DELAY)
    new = analyze(prepared, WALL_NEW_DELAY)

    print("=" * 116)
    print("スリット体系 壁なし threshold HOLDOUT検証（固定候補: 0.02 vs 0.01）")
    print("=" * 116)
    print(f"期間       : {start} ～ {end}")
    print(f"使用期     : {', '.join(terms)}")
    print(f"処理レース : {len(prepared)}")
    for k in ["not_6_entry", "not_6_exhibition", "missing_ex_st", "bad_result", "missing_racer_term_or_st"]:
        print(f"skip {k:<27}: {skip[k]}")
    print("共通       : C_ST_RANK / 1・2先行UP / line=0.05 / 他の遅れ系=0.02")
    print("固定候補   : BASE=壁なし0.02 / NEW=壁なし0.01")

    base_baseline, base_counts, base_freq, base_sw, base_st3 = base
    new_baseline, new_counts, new_freq, new_sw, new_st3 = new

    print("\n【HOLDOUT結果】")
    print("方式       PID5件数   分離score 1着/3連      BASE差 1着/3連")
    print(f"BASE_02    {base_freq[5]:>7}    {base_sw*100:5.2f}/{base_st3*100:5.2f}pt       +0.00/ +0.00pt")
    print(
        f"WALL_01    {new_freq[5]:>7}    {new_sw*100:5.2f}/{new_st3*100:5.2f}pt       "
        f"{(new_sw-base_sw)*100:+6.2f}/{(new_st3-base_st3)*100:+6.2f}pt"
    )

    print("\n【PID5 壁なし profile】")
    print("方式       R数 |      1C          2C          3C          4C          5C          6C")
    for name, result in [("BASE_02", base), ("WALL_01", new)]:
        baseline, counts, freq, _, _ = result
        cells = [profile(counts[5], baseline, c) for c in range(1, 7)]
        print(f"{name:<10} {freq[5]:>5} | " + " ".join(f"{x:>13}" for x in cells))

    print("\n判定:")
    print("・WALL_01が1着/3連の両方でBASE以上なら採用候補")
    print("・どちらかが悪化したら0.01は確定しない")
    print("・このHOLDOUT期間ではthresholdを再調整しない（過学習防止）")
    print("=" * 116)


if __name__ == "__main__":
    main()
