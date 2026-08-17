#!/usr/bin/env python3
import sys
from collections import Counter

from slit_pattern_condition_analyze import prepare_races, extract_features_param
from slit_racer_compare import new_lane_counts, add_result, metrics, separation_score

DELAY = 0.02
LINE = 0.05

# ここまでの確定/暫定基準:
# - C_ST_RANK
# - 遅れ系 threshold はすべて 0.02（壁なし0.01はHOLDOUT不通過）
# - 1・2先行をスロー先行より前へ
# - 横一線は spread<=0.05 / 最下位判定
# - 外側先行・ダッシュ先行・3号艇攻め・中ぶくれは現行定義
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
    ("BASE", "base", 0.00, "現行: 1-3号艇が上位3艇すべて"),
    ("SLOW_GAP01", "base_gap", 0.01, "現行条件 + 4番手へ0.01秒差"),
    ("SLOW_GAP02", "base_gap", 0.02, "現行条件 + 4番手へ0.02秒差"),
    ("TOP2_SLOW", "top2_slow", 0.00, "上位2艇がともに1-3号艇"),
    ("FAST_2OF3", "fast_2of3", 0.00, "最速が1-3号艇 + 上位3艇中2艇以上が1-3"),
    ("SLOW_2OF3", "slow_2of3", 0.00, "上位3艇中2艇以上が1-3号艇"),
]


def decide(features):
    for pid, key in PRIORITY:
        if features.get(key):
            return pid
    return 1


def apply_variant(st, features, mode, gap):
    order = sorted(range(6), key=lambda i: st[i])
    slow = {0, 1, 2}

    base = set(order[:3]).issubset(slow)

    if mode == "base":
        inside = base
    elif mode == "base_gap":
        inside = base and ((st[order[3]] - st[order[2]]) >= gap)
    elif mode == "top2_slow":
        inside = order[0] in slow and order[1] in slow
    elif mode == "fast_2of3":
        inside = order[0] in slow and sum(1 for i in order[:3] if i in slow) >= 2
    elif mode == "slow_2of3":
        inside = sum(1 for i in order[:3] if i in slow) >= 2
    else:
        raise ValueError(mode)

    features["inside_fast"] = inside
    return features


def analyze(prepared, mode, gap):
    baseline = new_lane_counts()
    counts = {pid: new_lane_counts() for pid in range(1, 13)}
    freq = Counter()

    # BASE条件が成立するレースを最速艇別に見る（1・2先行で先に取られるかは問わない raw）
    raw_base_fastest = Counter()
    raw_base_counts = {1: new_lane_counts(), 2: new_lane_counts(), 3: new_lane_counts()}

    for _, st, finish in prepared:
        for c in range(1, 7):
            add_result(baseline, c, finish[c])

        order = sorted(range(6), key=lambda i: st[i])
        if set(order[:3]).issubset({0, 1, 2}):
            fastest_course = order[0] + 1
            raw_base_fastest[fastest_course] += 1
            for c in range(1, 7):
                add_result(raw_base_counts[fastest_course], c, finish[c])

        f = extract_features_param(st, delay_th=DELAY, line_th=LINE)
        f = apply_variant(st, f, mode, gap)
        pid = decide(f)
        freq[pid] += 1
        for c in range(1, 7):
            add_result(counts[pid], c, finish[c])

    sw, st3 = separation_score(counts, baseline)
    return baseline, counts, freq, sw, st3, raw_base_fastest, raw_base_counts


def fmt(x):
    return f"{x*100:+6.2f}"


def profile_text(counts, baseline, course):
    _, _, _, lw, lt = metrics(counts, baseline, course)
    return f"{fmt(lw)}/{fmt(lt)}"


def main():
    if len(sys.argv) != 3:
        print("Usage: python3 analysis/slit_inside_fast_condition_compare.py YYYY-MM-DD YYYY-MM-DD")
        sys.exit(1)

    start, end = sys.argv[1], sys.argv[2]
    prepared, skip, terms = prepare_races(start, end)

    print("=" * 126)
    print("スリット体系 スロー先行 条件定義比較（ONE_TWO_UP / C_ST_RANK / delay=0.02）")
    print("=" * 126)
    print(f"期間       : {start} ～ {end}")
    print(f"使用期     : {', '.join(terms)}")
    print(f"処理レース : {len(prepared)}")
    for k in ["not_6_entry", "not_6_exhibition", "missing_ex_st", "bad_result", "missing_racer_term_or_st"]:
        print(f"skip {k:<27}: {skip[k]}")
    print("基準       : 1・2先行UP / 横一線現行 / 遅れ系すべて0.02 / その他条件現行")

    results = {}
    print("\n【候補サマリ】")
    print("候補             PID4件数   分離score 1着/3連      BASE差 1着/3連   PID4 1C lift(1着/3連)   説明")

    base_sw = base_st3 = None
    for name, mode, gap, desc in CANDIDATES:
        baseline, counts, freq, sw, st3, raw_fastest, raw_counts = analyze(prepared, mode, gap)
        results[name] = (baseline, counts, freq, sw, st3, raw_fastest, raw_counts)
        if name == "BASE":
            base_sw, base_st3 = sw, st3
        n = freq[4]
        if n:
            _, _, _, l1w, l1t = metrics(counts[4], baseline, 1)
            lift = f"{fmt(l1w)}/{fmt(l1t)}"
        else:
            lift = "-"
        print(
            f"{name:<16} {n:>7}   {sw*100:5.2f}/{st3*100:5.2f}pt      "
            f"{(sw-base_sw)*100:+6.2f}/{(st3-base_st3)*100:+6.2f}pt      {lift:>15}   {desc}"
        )

    print("\n【PID4 スロー先行 全6コース lift】")
    print("候補             R数 |      1C          2C          3C          4C          5C          6C")
    for name, *_ in CANDIDATES:
        baseline, counts, freq, *_ = results[name]
        n = freq[4]
        if not n:
            print(f"{name:<16} {0:>5} | -")
            continue
        cells = [profile_text(counts[4], baseline, c) for c in range(1, 7)]
        print(f"{name:<16} {n:>5} | " + " ".join(f"{x:>13}" for x in cells))

    # 現行raw条件そのものの性質を、最速艇が1/2/3のどれかで分解
    base = results["BASE"]
    baseline, _, _, _, _, raw_fastest, raw_counts = base
    print("\n【現行raw『1-3号艇が上位3艇』を最速艇別に分解】")
    print("最速   R数 | 1C 1着/3連lift   2C 1着/3連lift   3C 1着/3連lift")
    for fastest in [1, 2, 3]:
        n = raw_fastest[fastest]
        if not n:
            print(f"{fastest}C  {0:>5} | -")
            continue
        cells = [profile_text(raw_counts[fastest], baseline, c) for c in [1, 2, 3]]
        print(f"{fastest}C  {n:>5} | " + "  ".join(f"{x:>13}" for x in cells))

    print("\n見るポイント:")
    print("・GAP系が2期間ともBASEを上回れば、スロー勢が明確に抜けた時だけへ絞る候補")
    print("・TOP2_SLOW / FAST_2OF3 / SLOW_2OF3が2期間とも改善するなら、現行より少し広げる候補")
    print("・全体scoreだけでなく、PID4で1-3Cが有利/4-6Cが不利という展開profileが安定するかを見る")
    print("・raw最速艇別で性質が大きく違うなら、スロー先行を1つにまとめる妥当性も再検討する")
    print("・候補が出ても同じ2期間では確定せず、未使用期間HOLDOUTへ回す")
    print("=" * 126)


if __name__ == "__main__":
    main()
