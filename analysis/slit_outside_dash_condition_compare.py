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
# 横一線は HOLDOUT 未通過のため現行どおり最下位判定（spread<=0.05）
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
# base       : 現行
# dash_gap   : dash_fastのみ「3番手と4番手の差」を要求
# outside_gap: outside_attackのみ「2番手と3番手の差」を要求
# outside_2of3: 上位3艇中2艇以上が4-6なら outside_attack
# outside_fast2of3: 最速が4-6、かつ上位3艇中2艇以上が4-6
CANDIDATES = [
    ("BASE", "base", 0.00, "現行: dash=4-6が上位3 / outside=上位2艇が4-6"),
    ("DASH_GAP01", "dash_gap", 0.01, "dash条件 + 4番手へ0.01秒差"),
    ("DASH_GAP02", "dash_gap", 0.02, "dash条件 + 4番手へ0.02秒差"),
    ("OUT_GAP01", "outside_gap", 0.01, "outside条件 + 3番手へ0.01秒差"),
    ("OUT_GAP02", "outside_gap", 0.02, "outside条件 + 3番手へ0.02秒差"),
    ("OUT_2OF3", "outside_2of3", 0.00, "上位3艇中2艇以上が4-6"),
    ("OUT_FAST2OF3", "outside_fast2of3", 0.00, "最速が4-6 + 上位3艇中2艇以上が4-6"),
]


def decide(features):
    for pid, key in PRIORITY:
        if features.get(key):
            return pid
    return 1


def apply_variant(st, features, mode, gap):
    order = sorted(range(6), key=lambda i: st[i])
    outer = {3, 4, 5}

    # 現行定義を明示的に再計算
    dash_base = set(order[:3]).issubset(outer)
    outside_base = order[0] in outer and order[1] in outer

    dash = dash_base
    outside = outside_base

    if mode == "dash_gap":
        dash = dash_base and ((st[order[3]] - st[order[2]]) >= gap)
    elif mode == "outside_gap":
        outside = outside_base and ((st[order[2]] - st[order[1]]) >= gap)
    elif mode == "outside_2of3":
        outside = sum(1 for i in order[:3] if i in outer) >= 2
    elif mode == "outside_fast2of3":
        outside = order[0] in outer and sum(1 for i in order[:3] if i in outer) >= 2

    features["dash_fast"] = dash
    features["outside_fast"] = dash
    features["outside_attack"] = outside
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
        print("Usage: python3 analysis/slit_outside_dash_condition_compare.py YYYY-MM-DD YYYY-MM-DD")
        sys.exit(1)

    start, end = sys.argv[1], sys.argv[2]
    prepared, skip, terms = prepare_races(start, end)

    print("=" * 124)
    print("スリット体系 外側先行・ダッシュ先行 条件定義比較（ONE_TWO_UP / C_ST_RANK / delay=0.02）")
    print("=" * 124)
    print(f"期間       : {start} ～ {end}")
    print(f"使用期     : {', '.join(terms)}")
    print(f"処理レース : {len(prepared)}")
    for k in ["not_6_entry", "not_6_exhibition", "missing_ex_st", "bad_result", "missing_racer_term_or_st"]:
        print(f"skip {k:<27}: {skip[k]}")
    print("横一線     : spread<=0.05 / 最下位判定（TOP_08はHOLDOUT未通過のため不採用）")

    results = {}
    print("\n【候補サマリ】")
    print("候補             PID11  PID12   分離score 1着/3連      BASE差 1着/3連   説明")

    base_sw = base_st3 = None
    for name, mode, gap, desc in CANDIDATES:
        baseline, counts, freq, sw, st3 = analyze(prepared, mode, gap)
        results[name] = (baseline, counts, freq, sw, st3)
        if name == "BASE":
            base_sw, base_st3 = sw, st3
        print(
            f"{name:<16} {freq[11]:>5} {freq[12]:>6}   "
            f"{sw*100:5.2f}/{st3*100:5.2f}pt      "
            f"{(sw-base_sw)*100:+6.2f}/{(st3-base_st3)*100:+6.2f}pt   {desc}"
        )

    print("\n【PID11 外側先行 全6コース lift】")
    print("候補             R数 |      1C          2C          3C          4C          5C          6C")
    for name, *_ in CANDIDATES:
        baseline, counts, freq, _, _ = results[name]
        n = freq[11]
        if not n:
            print(f"{name:<16} {0:>5} | -")
            continue
        cells = [profile_text(counts[11], baseline, c) for c in range(1, 7)]
        print(f"{name:<16} {n:>5} | " + " ".join(f"{x:>13}" for x in cells))

    print("\n【PID12 ダッシュ先行 全6コース lift】")
    print("候補             R数 |      1C          2C          3C          4C          5C          6C")
    for name, *_ in CANDIDATES:
        baseline, counts, freq, _, _ = results[name]
        n = freq[12]
        if not n:
            print(f"{name:<16} {0:>5} | -")
            continue
        cells = [profile_text(counts[12], baseline, c) for c in range(1, 7)]
        print(f"{name:<16} {n:>5} | " + " ".join(f"{x:>13}" for x in cells))

    print("\n見るポイント:")
    print("・DASH_GAP系が2期間ともBASEを上回るなら、ダッシュ先行を『明確に抜けた時だけ』へ絞る候補")
    print("・OUT_GAP系が2期間とも上回るなら、外側先行にも3番手との差を追加候補")
    print("・OUT_2OF3 / OUT_FAST2OF3が安定改善するなら、外側先行を少し広げる候補")
    print("・全体scoreだけでなく、PID11/12で4-6Cのliftが2期間で同方向に強まるかを見る")
    print("・候補が見つかっても、この2期間で確定せずHOLDOUTで再確認する")
    print("=" * 124)


if __name__ == "__main__":
    main()
