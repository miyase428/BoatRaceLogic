#!/usr/bin/env python3
from pathlib import Path
import sys

REPO_ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(Path(__file__).resolve().parent))

from slit_buff_rebuild_validate import (
    K,
    MAX_CAP,
    PATTERN_NAMES,
    load_settings,
    prepare_races,
    classify_prepared,
    calc_baseline,
    calc_pattern_rates,
    calc_buff,
    brier_eval,
    observed_test_lifts,
    direction_summary,
    top_cells,
    sign,
    pct,
)

# 既存スリット補正と同じ固定パラメータを使用し、ここでは再調整しない。
# TEST期間の直前180日程度をTRAINとして、2つの独立期間で再現性を確認する。
PERIODS = [
    {
        "name": "PERIOD1",
        "train_start": "2025-12-17",
        "train_end": "2026-06-14",
        "test_start": "2026-06-15",
        "test_end": "2026-07-14",
    },
    {
        "name": "PERIOD2",
        "train_start": "2026-01-16",
        "train_end": "2026-07-14",
        "test_start": "2026-07-15",
        "test_end": "2026-08-14",
    },
]

TARGET_METRICS = ("place2", "place3")
EPS = 1e-12


def evaluate_period(period, settings):
    train_prepared, train_skip, train_terms = prepare_races(
        period["train_start"], period["train_end"]
    )
    test_prepared, test_skip, test_terms = prepare_races(
        period["test_start"], period["test_end"]
    )

    train_rows, train_freq = classify_prepared(train_prepared, settings)
    test_rows, test_freq = classify_prepared(test_prepared, settings)

    train_baseline, _ = calc_baseline(train_rows)
    train_rates, train_counts = calc_pattern_rates(train_rows)
    full_buff = calc_buff(train_rates, train_baseline, train_counts)

    brier = brier_eval(test_rows, train_baseline, full_buff)
    test_lifts, _ = observed_test_lifts(test_rows)

    return {
        "period": period,
        "train_terms": train_terms,
        "test_terms": test_terms,
        "train_rows": train_rows,
        "test_rows": test_rows,
        "train_freq": train_freq,
        "test_freq": test_freq,
        "train_skip": train_skip,
        "test_skip": test_skip,
        "train_counts": train_counts,
        "buff": full_buff,
        "brier": brier,
        "test_lifts": test_lifts,
    }


def print_period_result(r):
    p = r["period"]
    print("=" * 118)
    print(f"{p['name']}  スリット2着率・3着率 HOLDOUT")
    print("=" * 118)
    print(
        f"TRAIN : {p['train_start']} ～ {p['train_end']} "
        f"/ races={len(r['train_rows'])} / terms={','.join(r['train_terms'])}"
    )
    print(
        f"TEST  : {p['test_start']} ～ {p['test_end']} "
        f"/ races={len(r['test_rows'])} / terms={','.join(r['test_terms'])}"
    )
    print("分類  : C_ST_RANK予測PID + 本番現行12パターン")
    print(f"補正  : train lift × n/(n+{int(K)}) / cap=±{MAX_CAP:.2f}")
    print("固定  : K/cap/分類条件は今回再調整しない")

    print("\n【Brier score】 小さいほど良い")
    print("指標       baseline      buff適用       差(buff-base)     改善率      判定")
    for metric in TARGET_METRICS:
        base = r["brier"][metric]["base"]
        new = r["brier"][metric]["buff"]
        diff = new - base
        improve = ((base - new) / base * 100.0) if base else 0.0
        ok = new < base - EPS
        print(
            f"{metric:<8} {base:>11.6f}  {new:>11.6f}  {diff:>+14.6f}  "
            f"{improve:>+8.3f}%    {'改善' if ok else '悪化/同等'}"
        )

    print("\n【方向の再現性】 TRAIN n>=40 かつ |buff|>=1pt")
    print("指標       一致セル/対象    件数加重一致率")
    for metric in TARGET_METRICS:
        cells, agree, wt, wa = direction_summary(
            r["buff"], r["train_counts"], r["test_lifts"], metric
        )
        cell_rate = agree / cells * 100.0 if cells else 0.0
        weighted_rate = wa / wt * 100.0 if wt else 0.0
        print(
            f"{metric:<8} {agree:>3}/{cells:<3} ({cell_rate:>6.2f}%)     "
            f"{weighted_rate:>6.2f}%"
        )

    for metric in TARGET_METRICS:
        print(f"\n【{metric} |buff| 上位10セル：TRAIN buff → TEST実lift】")
        print("PID 名称           C   TRAIN_N   buff       TEST lift   同方向")
        for _, b, n, pid, course, test_lift in top_cells(
            r["buff"], r["train_counts"], r["test_lifts"], metric, limit=10
        ):
            same = "○" if sign(b) == sign(test_lift) and sign(b) != 0 else "×"
            print(
                f"{pid:>2}  {PATTERN_NAMES[pid]:<12} {course}C  {n:>7}  "
                f"{pct(b):>9}  {pct(test_lift):>10}    {same}"
            )


def main():
    if len(sys.argv) != 1:
        print("Usage: python3 analysis/slit_place23_two_period_validate.py")
        sys.exit(1)

    settings = load_settings()
    results = [evaluate_period(period, settings) for period in PERIODS]

    print("=" * 118)
    print("スリット 2着率・3着率 2期間固定検証")
    print("=" * 118)
    print("主判定: 各指標について未使用TEST BrierがPERIOD1・PERIOD2の両方で改善すること")
    print("補助判定: buff方向一致率。主判定を後から変更する材料にはしない")
    print(f"固定値 : K={int(K)}, cap=±{MAX_CAP:.2f}")

    for r in results:
        print_period_result(r)

    print("\n" + "=" * 118)
    print("【2期間 最終判定】")
    print("指標       PERIOD1       PERIOD2       POOLED Brier(base→buff)          結論")

    for metric in TARGET_METRICS:
        period_ok = []
        weighted_base_sum = 0.0
        weighted_buff_sum = 0.0
        total_obs = 0

        for r in results:
            base = r["brier"][metric]["base"]
            new = r["brier"][metric]["buff"]
            period_ok.append(new < base - EPS)
            n_obs = len(r["test_rows"]) * 6
            weighted_base_sum += base * n_obs
            weighted_buff_sum += new * n_obs
            total_obs += n_obs

        pooled_base = weighted_base_sum / total_obs if total_obs else 0.0
        pooled_buff = weighted_buff_sum / total_obs if total_obs else 0.0
        pooled_improve = (
            (pooled_base - pooled_buff) / pooled_base * 100.0 if pooled_base else 0.0
        )
        passed = all(period_ok)

        print(
            f"{metric:<8} {'改善' if period_ok[0] else '悪化/同等':<12} "
            f"{'改善' if period_ok[1] else '悪化/同等':<12} "
            f"{pooled_base:.6f}→{pooled_buff:.6f} ({pooled_improve:+.3f}%)   "
            f"{'採用候補' if passed else '不採用'}"
        )

    print("\n判定ルール:")
    print("・place2 / place3 は別々に判定する")
    print("・2期間とも改善した指標だけ、本番buffとWeb表示へ進める")
    print("・片期間でも悪化/同等なら、その指標は0補正のまま維持する")
    print("・今回の結果を見てK/capを微調整しない")
    print("=" * 118)


if __name__ == "__main__":
    main()
