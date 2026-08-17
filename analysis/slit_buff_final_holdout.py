#!/usr/bin/env python3
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(Path(__file__).resolve().parent))

from slit_buff_rebuild_validate import (
    METRICS,
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
    print_skip,
)


SELECTIVE_METRICS = {"win", "trio"}


def selective_buff(full_buff):
    out = {pid: {} for pid in range(1, 13)}
    for pid in range(1, 13):
        for c in range(1, 7):
            out[pid][c] = {}
            for m in METRICS:
                out[pid][c][m] = full_buff[pid][c][m] if m in SELECTIVE_METRICS else 0.0
    return out


def main():
    if len(sys.argv) != 5:
        print(
            "Usage: python3 analysis/slit_buff_final_holdout.py "
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
    full_buff = calc_buff(train_rates, train_baseline, train_counts)
    wt_buff = selective_buff(full_buff)

    brier_full = brier_eval(test_rows, train_baseline, full_buff)
    brier_wt = brier_eval(test_rows, train_baseline, wt_buff)
    test_lifts, _ = observed_test_lifts(test_rows)

    print("=" * 122)
    print("スリット buff/debuff 最終HOLDOUT（採用候補: win/trioのみ）")
    print("=" * 122)
    print(f"TRAIN : {train_start} ～ {train_end} / terms={','.join(train_terms)} / races={len(train_rows)}")
    print(f"TEST  : {test_start} ～ {test_end} / terms={','.join(test_terms)} / races={len(test_rows)}")
    print("分類  : C_ST_RANK予測PID + 本番現行12パターン")
    print(f"buff  : train lift × n/(n+{int(K)}) / cap=±{MAX_CAP:.2f}")
    print("候補  : win/trioのみbuff適用、place2/place3は0補正")
    print_skip("TRAIN skip:", train_skip)
    print_skip("TEST skip:", test_skip)

    print("\n【PID件数】")
    print("PID 名称           TRAIN   TEST")
    for pid in range(1, 13):
        print(f"{pid:>2}  {PATTERN_NAMES[pid]:<12} {train_freq[pid]:>6} {test_freq[pid]:>6}")

    print("\n【最終HOLDOUT Brier score】 小さいほど良い")
    print("指標       baseline      FULL4buff      WIN_TRIOのみ     WT差(base)    WT改善率")
    for m in METRICS:
        base = brier_wt[m]["base"]
        full = brier_full[m]["buff"]
        wt = brier_wt[m]["buff"]
        diff = wt - base
        improve = ((base - wt) / base * 100.0) if base else 0.0
        print(
            f"{m:<8} {base:>11.6f}  {full:>11.6f}  {wt:>13.6f}  "
            f"{diff:>+12.6f}  {improve:>+8.3f}%"
        )

    print("\n【win/trio buff方向の再現性】 train n>=40 かつ |buff|>=1pt")
    print("指標       一致セル/対象    件数加重一致率")
    for m in ("win", "trio"):
        cells, agree, wt_n, wa = direction_summary(full_buff, train_counts, test_lifts, m)
        cell_rate = agree / cells * 100.0 if cells else 0.0
        weighted_rate = wa / wt_n * 100.0 if wt_n else 0.0
        print(f"{m:<8} {agree:>3}/{cells:<3} ({cell_rate:>6.2f}%)     {weighted_rate:>6.2f}%")

    win_ok = brier_wt["win"]["buff"] <= brier_wt["win"]["base"]
    trio_ok = brier_wt["trio"]["buff"] <= brier_wt["trio"]["base"]
    p2_unchanged = abs(brier_wt["place2"]["buff"] - brier_wt["place2"]["base"]) < 1e-12
    p3_unchanged = abs(brier_wt["place3"]["buff"] - brier_wt["place3"]["base"]) < 1e-12

    print("\n判定:")
    print("・このHOLDOUTではK/cap/採用指標を再調整しない")
    print("・win/trioが両方改善し、place2/place3が0補正で悪化しないことを確認する")
    if win_ok and trio_ok and p2_unchanged and p3_unchanged:
        print("=> 最終候補通過: win/trioのみの新buffを本番生成候補にできる")
    else:
        print("=> 最終候補不通過: 本番buff_debuff_slit.jsonはまだ更新しない")
    print("=" * 122)


if __name__ == "__main__":
    main()
