#!/usr/bin/env python3
import sys

from slit_pattern_condition_analyze import prepare_races
from slit_line_abreast_compare import analyze, BASE_PRIORITY, TOP_LINE
from slit_racer_compare import metrics

BASE_LINE = 0.05
NEW_LINE = 0.08


def fmt(x):
    return f"{x * 100:+6.2f}"


def main():
    if len(sys.argv) != 3:
        print("Usage: python3 analysis/slit_line_abreast_holdout.py YYYY-MM-DD YYYY-MM-DD")
        sys.exit(1)

    start, end = sys.argv[1], sys.argv[2]
    prepared, skip, terms = prepare_races(start, end)

    print("=" * 116)
    print("スリット体系 横一線 HOLDOUT検証（固定候補: BASE vs TOP_08）")
    print("=" * 116)
    print(f"期間       : {start} ～ {end}")
    print(f"使用期     : {', '.join(terms)}")
    print(f"処理レース : {len(prepared)}")
    for k in ["not_6_entry", "not_6_exhibition", "missing_ex_st", "bad_result", "missing_racer_term_or_st"]:
        print(f"skip {k:<27}: {skip[k]}")
    print("共通       : C_ST_RANK / delay=0.02 / 1・2先行をスロー先行より前へ")
    print("固定候補   : BASE=横一線 spread<=0.05 を最下位判定 / NEW=spread<=0.08 を最優先判定")

    base = analyze(prepared, BASE_LINE, BASE_PRIORITY)
    new = analyze(prepared, NEW_LINE, TOP_LINE)

    b_base, b_counts, b_freq, b_raw, b_sw, b_st3 = base
    n_base, n_counts, n_freq, n_raw, n_sw, n_st3 = new

    print("\n【HOLDOUT結果】")
    print("方式      raw横一線 PID2件数   分離score 1着/3連      BASE差 1着/3連")
    print(
        f"BASE       {b_raw:>7} {b_freq[2]:>8}   {b_sw*100:5.2f}/{b_st3*100:5.2f}pt      "
        f"{0.0:+6.2f}/{0.0:+6.2f}pt"
    )
    print(
        f"TOP_08     {n_raw:>7} {n_freq[2]:>8}   {n_sw*100:5.2f}/{n_st3*100:5.2f}pt      "
        f"{(n_sw-b_sw)*100:+6.2f}/{(n_st3-b_st3)*100:+6.2f}pt"
    )

    print("\n【TOP_08 横一線PID2 全6コース profile】")
    print("各セル: 1着lift/3連lift (pt)")
    if n_freq[2] == 0:
        print("PID2件数=0")
    else:
        cells = []
        for c in range(1, 7):
            _, _, _, lw, lt = metrics(n_counts[2], n_base, c)
            cells.append(f"{fmt(lw)}/{fmt(lt)}")
        print("      1C          2C          3C          4C          5C          6C")
        print(" ".join(f"{x:>13}" for x in cells))

    print("\n判定:")
    d1 = (n_sw - b_sw) * 100
    d3 = (n_st3 - b_st3) * 100
    if d1 > 0 and d3 > 0:
        print("・HOLDOUTでも1着/3連の両方が改善 → TOP_08を横一線の本命候補として維持")
    elif d1 >= 0 and d3 >= 0:
        print("・悪化なし → TOP_08は維持候補。ただし改善幅が小さければ追加期間で確認")
    else:
        print("・どちらかが悪化 → 0.08最優先は確定せず、横一線は再検討")
    print("・このHOLDOUT期間では閾値を再調整しない（過学習防止）")
    print("=" * 116)


if __name__ == "__main__":
    main()
