#!/usr/bin/env python3
import json
import sys
from collections import Counter
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
THEORY_DIR = REPO_ROOT / "theories" / "course_correction"
sys.path.insert(0, str(THEORY_DIR))
sys.path.insert(0, str(Path(__file__).resolve().parent))

from classify_slit_pattern import classify_slit_pattern
from slit_pattern_condition_analyze import prepare_races

METRICS = ("win", "place2", "place3", "trio")
K = 40.0
MAX_CAP = 0.08

PATTERN_NAMES = {
    1: "通常型", 2: "横一線", 3: "1・2先行", 4: "スロー先行",
    5: "壁なし", 6: "2・3遅れ", 7: "中凹み", 8: "3号艇攻め",
    9: "中ぶくれ", 10: "1号艇遅れ", 11: "外側先行", 12: "ダッシュ先行",
}


def load_settings():
    with (THEORY_DIR / "venue_slit_settings.json").open(encoding="utf-8") as f:
        return json.load(f)["default"]


def empty_metric_counts():
    return {m: 0 for m in METRICS} | {"total": 0}


def new_course_counts():
    return {c: empty_metric_counts() for c in range(1, 7)}


def new_pattern_counts():
    return {pid: new_course_counts() for pid in range(1, 13)}


def outcome_flags(rank):
    return {
        "win": 1 if rank == 1.0 else 0,
        "place2": 1 if rank == 2.0 else 0,
        "place3": 1 if rank == 3.0 else 0,
        "trio": 1 if rank <= 3.0 else 0,
    }


def add_finish(counts, course, rank):
    x = counts[course]
    x["total"] += 1
    flags = outcome_flags(rank)
    for m in METRICS:
        x[m] += flags[m]


def classify_prepared(prepared, settings):
    rows = []
    freq = Counter()
    for race_code, st, finish in prepared:
        pid, _ = classify_slit_pattern(st, settings)
        rows.append((race_code, pid, finish))
        freq[pid] += 1
    return rows, freq


def calc_baseline(rows):
    counts = new_course_counts()
    for _, _, finish in rows:
        for c in range(1, 7):
            add_finish(counts, c, finish[c])

    rates = {}
    for c in range(1, 7):
        n = counts[c]["total"]
        rates[c] = {m: (counts[c][m] / n if n else 0.0) for m in METRICS}
    return rates, counts


def calc_pattern_rates(rows):
    counts = new_pattern_counts()
    for _, pid, finish in rows:
        for c in range(1, 7):
            add_finish(counts[pid], c, finish[c])

    rates = {pid: {} for pid in range(1, 13)}
    for pid in range(1, 13):
        for c in range(1, 7):
            n = counts[pid][c]["total"]
            rates[pid][c] = {
                m: (counts[pid][c][m] / n if n else 0.0)
                for m in METRICS
            }
    return rates, counts


def calc_buff(pattern_rates, baseline, counts):
    buff = {pid: {} for pid in range(1, 13)}
    for pid in range(1, 13):
        for c in range(1, 7):
            n = counts[pid][c]["total"]
            weight = n / (n + K) if n else 0.0
            buff[pid][c] = {}
            for m in METRICS:
                raw = pattern_rates[pid][c][m] - baseline[c][m]
                shrunk = raw * weight
                buff[pid][c][m] = max(-MAX_CAP, min(MAX_CAP, shrunk))
    return buff


def clip01(x):
    return max(0.0, min(1.0, x))


def brier_eval(rows, train_baseline, buff):
    sums_base = {m: 0.0 for m in METRICS}
    sums_buff = {m: 0.0 for m in METRICS}
    n_obs = 0

    for _, pid, finish in rows:
        for c in range(1, 7):
            flags = outcome_flags(finish[c])
            for m in METRICS:
                p0 = train_baseline[c][m]
                p1 = clip01(p0 + buff[pid][c][m])
                y = flags[m]
                sums_base[m] += (p0 - y) ** 2
                sums_buff[m] += (p1 - y) ** 2
            n_obs += 1

    return {
        m: {
            "base": sums_base[m] / n_obs if n_obs else 0.0,
            "buff": sums_buff[m] / n_obs if n_obs else 0.0,
        }
        for m in METRICS
    }


def observed_test_lifts(rows):
    baseline, _ = calc_baseline(rows)
    pattern_rates, counts = calc_pattern_rates(rows)
    lifts = {pid: {} for pid in range(1, 13)}
    for pid in range(1, 13):
        for c in range(1, 7):
            lifts[pid][c] = {
                m: pattern_rates[pid][c][m] - baseline[c][m]
                for m in METRICS
            }
    return lifts, counts


def sign(x):
    if x > 0:
        return 1
    if x < 0:
        return -1
    return 0


def direction_summary(buff, train_counts, test_lifts, metric, min_n=40, min_abs=0.01):
    cells = 0
    agree = 0
    weighted_total = 0
    weighted_agree = 0

    for pid in range(1, 13):
        for c in range(1, 7):
            n = train_counts[pid][c]["total"]
            b = buff[pid][c][metric]
            if n < min_n or abs(b) < min_abs:
                continue
            cells += 1
            weighted_total += n
            ok = sign(b) == sign(test_lifts[pid][c][metric])
            if ok:
                agree += 1
                weighted_agree += n

    return cells, agree, weighted_total, weighted_agree


def top_cells(buff, train_counts, test_lifts, metric, limit=12):
    rows = []
    for pid in range(1, 13):
        for c in range(1, 7):
            n = train_counts[pid][c]["total"]
            b = buff[pid][c][metric]
            rows.append((abs(b), b, n, pid, c, test_lifts[pid][c][metric]))
    rows.sort(reverse=True)
    return rows[:limit]


def pct(x):
    return f"{x * 100:+6.2f}pt"


def print_skip(label, skip):
    print(label)
    for key in ["not_6_entry", "not_6_exhibition", "missing_ex_st", "bad_result", "missing_racer_term_or_st"]:
        print(f"  {key:<27}: {skip[key]}")


def main():
    if len(sys.argv) != 5:
        print(
            "Usage: python3 analysis/slit_buff_rebuild_validate.py "
            "TRAIN_START TRAIN_END TEST_START TEST_END"
        )
        sys.exit(1)

    train_start, train_end, test_start, test_end = sys.argv[1:5]
    settings = load_settings()

    train_prepared, train_skip, train_terms = prepare_races(train_start, train_end)
    test_prepared, test_skip, test_terms = prepare_races(test_start, test_end)

    train_rows, train_freq = classify_prepared(train_prepared, settings)
    test_rows, test_freq = classify_prepared(test_prepared, settings)

    train_baseline, _ = calc_baseline(train_rows)
    train_rates, train_counts = calc_pattern_rates(train_rows)
    buff = calc_buff(train_rates, train_baseline, train_counts)

    brier = brier_eval(test_rows, train_baseline, buff)
    test_lifts, _ = observed_test_lifts(test_rows)

    print("=" * 118)
    print("スリット buff/debuff 再構築検証（C_ST_RANK予測PID → 実着順）")
    print("=" * 118)
    print(f"TRAIN : {train_start} ～ {train_end} / terms={','.join(train_terms)} / races={len(train_rows)}")
    print(f"TEST  : {test_start} ～ {test_end} / terms={','.join(test_terms)} / races={len(test_rows)}")
    print("分類  : 本番現行12パターン / venue_slit_settings.json を使用")
    print(f"buff  : train lift × n/(n+{int(K)}) / cap=±{MAX_CAP:.2f}")
    print_skip("TRAIN skip:", train_skip)
    print_skip("TEST skip:", test_skip)

    print("\n【PID件数】")
    print("PID 名称           TRAIN   TEST")
    for pid in range(1, 13):
        print(f"{pid:>2}  {PATTERN_NAMES[pid]:<12} {train_freq[pid]:>6} {test_freq[pid]:>6}")

    print("\n【未使用TESTでのBrier score】 小さいほど良い")
    print("指標       baseline      buff適用       差(buff-base)     改善率")
    for m in METRICS:
        base = brier[m]["base"]
        new = brier[m]["buff"]
        diff = new - base
        improve = ((base - new) / base * 100.0) if base else 0.0
        print(f"{m:<8} {base:>11.6f}  {new:>11.6f}  {diff:>+14.6f}  {improve:>+8.3f}%")

    print("\n【buff方向の再現性】 train n>=40 かつ |buff|>=1pt のセル")
    print("指標       一致セル/対象    件数加重一致率")
    for m in METRICS:
        cells, agree, wt, wa = direction_summary(buff, train_counts, test_lifts, m)
        cell_rate = agree / cells * 100.0 if cells else 0.0
        weighted_rate = wa / wt * 100.0 if wt else 0.0
        print(f"{m:<8} {agree:>3}/{cells:<3} ({cell_rate:>6.2f}%)     {weighted_rate:>6.2f}%")

    for m in ("win", "trio"):
        print(f"\n【{m} |buff| 上位12セル：TRAIN buff → TEST実lift】")
        print("PID 名称           C   TRAIN_N   buff       TEST lift   同方向")
        for _, b, n, pid, c, tl in top_cells(buff, train_counts, test_lifts, m):
            same = "○" if sign(b) == sign(tl) and sign(b) != 0 else "×"
            print(
                f"{pid:>2}  {PATTERN_NAMES[pid]:<12} {c}C  {n:>7}  "
                f"{pct(b):>9}  {pct(tl):>10}    {same}"
            )

    all_better = all(brier[m]["buff"] <= brier[m]["base"] for m in METRICS)
    core_better = brier["win"]["buff"] <= brier["win"]["base"] and brier["trio"]["buff"] <= brier["trio"]["base"]

    print("\n判定:")
    print("・主判定は未使用TESTのBrier。buff適用で win/trio がともに改善するかを見る")
    print("・place2/place3も改善すれば、そのまま4指標buffとして採用しやすい")
    print("・方向一致率と上位セルは、どのPID×コースが再現しているかの補助確認")
    if all_better:
        print("=> 4指標すべてBrier改善。このTRAIN→TESTでは新buffは良好")
    elif core_better:
        print("=> win/trioは改善。ただしplace2/place3に悪化あり。指標別採用を検討")
    else:
        print("=> win/trioの両方は改善していない。このTRAIN→TESTだけでは本番buff生成へ進めない")
    print("=" * 118)


if __name__ == "__main__":
    main()
