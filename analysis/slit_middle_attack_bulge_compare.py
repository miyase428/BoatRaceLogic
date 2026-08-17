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

# ここまでの暫定基準:
# C_ST_RANK / delay=0.02 / 1・2先行をスロー先行より前へ
# 横一線は spread<=0.05 / 最下位判定
# 外側先行・ダッシュ先行は現行条件を維持
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

# mode, gap
CANDIDATES = [
    ("BASE", "base", 0.00, "現行: attack=3C最速 / bulge=3C・4Cが上位2艇"),
    ("ATTACK_GAP01", "attack_gap", 0.01, "3C最速 + 2番手へ0.01秒差"),
    ("ATTACK_GAP02", "attack_gap", 0.02, "3C最速 + 2番手へ0.02秒差"),
    ("BULGE_GAP01", "bulge_gap", 0.01, "3C・4Cが上位2艇 + 3番手へ0.01秒差"),
    ("BULGE_GAP02", "bulge_gap", 0.02, "3C・4Cが上位2艇 + 3番手へ0.02秒差"),
    ("BULGE_34", "bulge_34", 0.00, "中ぶくれを 3C最速→4C 2番手 のみに限定"),
    ("BULGE_43", "bulge_43", 0.00, "中ぶくれを 4C最速→3C 2番手 のみに限定"),
]


def decide(features):
    for pid, key in PRIORITY:
        if features.get(key):
            return pid
    return 1


def apply_variant(st, features, mode, gap):
    order = sorted(range(6), key=lambda i: st[i])

    attack_base = order[0] == 2
    bulge_base = set(order[:2]) == {2, 3}

    attack = attack_base
    bulge = bulge_base

    if mode == "attack_gap":
        attack = attack_base and ((st[order[1]] - st[order[0]]) >= gap)
    elif mode == "bulge_gap":
        bulge = bulge_base and ((st[order[2]] - st[order[1]]) >= gap)
    elif mode == "bulge_34":
        bulge = order[0] == 2 and order[1] == 3
    elif mode == "bulge_43":
        bulge = order[0] == 3 and order[1] == 2

    features["middle_attack"] = attack
    features["middle_bulge"] = bulge
    return features


def analyze(prepared, mode, gap):
    baseline = new_lane_counts()
    counts = {pid: new_lane_counts() for pid in range(1, 13)}
    freq = Counter()

    for _, st, finish in prepared:
        for c in range(1, 7):
            add_result(baseline, c, finish[c])

        f = extract_features_param(st, delay_th=DELAY, line_th=LINE)
        f = apply_variant(st, f, mode, gap)
        pid = decide(f)
        freq[pid] += 1
        for c in range(1, 7):
            add_result(counts[pid], c, finish[c])

    sw, st3 = separation_score(counts, baseline)
    return baseline, counts, freq, sw, st3


def fmt(x):
    return f"{x*100:+6.2f}"


def profile_text(counts, baseline, course):
    _, _, _, lw, lt = metrics(counts, baseline, course)
    return f"{fmt(lw)}/{fmt(lt)}"


def main():
    if len(sys.argv) != 3:
        print("Usage: python3 analysis/slit_middle_attack_bulge_compare.py YYYY-MM-DD YYYY-MM-DD")
        sys.exit(1)

    start, end = sys.argv[1], sys.argv[2]
    prepared, skip, terms = prepare_races(start, end)

    print("=" * 124)
    print("スリット体系 3号艇攻め・中ぶくれ 条件定義比較（ONE_TWO_UP / C_ST_RANK / delay=0.02）")
    print("=" * 124)
    print(f"期間       : {start} ～ {end}")
    print(f"使用期     : {', '.join(terms)}")
    print(f"処理レース : {len(prepared)}")
    for k in ["not_6_entry", "not_6_exhibition", "missing_ex_st", "bad_result", "missing_racer_term_or_st"]:
        print(f"skip {k:<27}: {skip[k]}")
    print("基準       : 1・2先行UP / 横一線現行 / 外側先行・ダッシュ先行現行")

    results = {}
    print("\n【候補サマリ】")
    print("候補             PID8  PID9   分離score 1着/3連      BASE差 1着/3連   説明")

    base_sw = base_st3 = None
    for name, mode, gap, desc in CANDIDATES:
        baseline, counts, freq, sw, st3 = analyze(prepared, mode, gap)
        results[name] = (baseline, counts, freq, sw, st3)
        if name == "BASE":
            base_sw, base_st3 = sw, st3
        print(
            f"{name:<16} {freq[8]:>5} {freq[9]:>5}   "
            f"{sw*100:5.2f}/{st3*100:5.2f}pt      "
            f"{(sw-base_sw)*100:+6.2f}/{(st3-base_st3)*100:+6.2f}pt   {desc}"
        )

    for target_pid, title in [(8, "PID8 3号艇攻め"), (9, "PID9 中ぶくれ")]:
        print(f"\n【{title} 全6コース lift】")
        print("候補             R数 |      1C          2C          3C          4C          5C          6C")
        for name, *_ in CANDIDATES:
            baseline, counts, freq, _, _ = results[name]
            n = freq[target_pid]
            if not n:
                print(f"{name:<16} {0:>5} | -")
                continue
            cells = [profile_text(counts[target_pid], baseline, c) for c in range(1, 7)]
            print(f"{name:<16} {n:>5} | " + " ".join(f"{x:>13}" for x in cells))

    print("\n見るポイント:")
    print("・ATTACK_GAP系が2期間ともBASEを上回れば、3号艇攻めを『明確に最速』へ絞る候補")
    print("・BULGE_GAP系が2期間ともBASEを上回れば、中ぶくれに3番手との差を追加候補")
    print("・BULGE_34 / BULGE_43で片方だけ安定して強ければ、中ぶくれを順序で分離する候補")
    print("・全体scoreだけでなく、PID8/9の1Cマイナスと3C/4Cプラスが2期間で安定するかを見る")
    print("・候補が出ても同じ2期間では確定せず、必要ならHOLDOUTで再確認する")
    print("=" * 124)


if __name__ == "__main__":
    main()
