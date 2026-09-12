#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
多摩川3コース 第二段階：主軸サイン候補比較。

第一段階 analyze_tamagawa_lane3_attack_hypothesis.py の集計をそのまま使い、
3コースの攻めサインを何で定義するのがよいかを12ヶ月 / 6ヶ月profileで比較する。

比較候補:
- BASELINE
- 3が2より平均ST順位 上
- 3コースまくり率15%以上
- 3コースまくり率15%以上 × ST上
- 3コースまくり差し率15%以上
- 3コースまくり差し率15%以上 × ST上
- 3コース攻め率（まくり+まくり差し）15%以上
- 3コース攻め率15%以上 × ST上

この段階では表示・買い目・予想ロジックは変更しない。

Usage:
  python3 analysis/analyze_tamagawa_lane3_primary_signal_compare.py 2025-09-01 2026-09-09
"""

from __future__ import annotations

import sys
from datetime import date
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from analyze_tamagawa_boaters_hypothesis import HISTORY_MONTHS, parse_date  # noqa: E402
from analyze_tamagawa_lane3_attack_hypothesis import analyze, pct  # noqa: E402


CANDIDATES = (
    ("BASELINE", "基準", "baseline", None, None),
    ("ST_UP", "3が2より平均ST順位 上", "relation", None, "内側より上"),
    ("M15", "まくり15%以上", "attack", "makuri", None),
    ("M15_ST", "まくり15%以上 × ST上", "cross", "makuri", "内側より上"),
    ("MZ15", "まくり差し15%以上", "attack", "makurizashi", None),
    ("MZ15_ST", "まくり差し15%以上 × ST上", "cross", "makurizashi", "内側より上"),
    ("A15", "攻め率15%以上", "attack", "attack_sum", None),
    ("A15_ST", "攻め率15%以上 × ST上", "cross", "attack_sum", "内側より上"),
)


def rates(stat: dict) -> dict[str, float]:
    n = int(stat["n"])
    first = int(stat["lane3_first"])
    second = int(stat["lane3_second"])
    third = int(stat["lane3_third"])
    return {
        "n": n,
        "head": pct(first, n),
        "top2": pct(first + second, n),
        "top3": pct(first + second + third, n),
    }


def resolve_stat(months: int, kind: str, attack_key: str | None, relation: str | None,
                 baseline: dict, by_relation: dict, by_attack_band: dict, by_cross: dict) -> dict:
    if kind == "baseline":
        return baseline[months]
    if kind == "relation":
        return by_relation[(months, relation)]
    if kind == "attack":
        return by_attack_band[(months, attack_key, "15.0+")]
    if kind == "cross":
        return by_cross[(months, attack_key, relation, "15.0+")]
    raise ValueError(kind)


def print_row(code: str, label: str, r: dict[str, float], base: dict[str, float]) -> None:
    print(
        f"{code:<8} {label:<28} "
        f"N={int(r['n']):4d}  "
        f"3頭={r['head']:6.2f}% ({r['head'] - base['head']:+6.2f}pt)  "
        f"2連対={r['top2']:6.2f}% ({r['top2'] - base['top2']:+6.2f}pt)  "
        f"3連対={r['top3']:6.2f}% ({r['top3'] - base['top3']:+6.2f}pt)"
    )


def main() -> None:
    if len(sys.argv) != 3:
        print(
            "Usage: python3 analysis/analyze_tamagawa_lane3_primary_signal_compare.py START_DATE END_DATE",
            file=sys.stderr,
        )
        sys.exit(1)

    start_date: date = parse_date(sys.argv[1])
    end_date: date = parse_date(sys.argv[2])
    if start_date > end_date:
        raise RuntimeError("開始日が終了日より後です")

    processed, skips, baseline, by_relation, by_attack_band, by_cross = analyze(start_date, end_date)

    print("\n" + "=" * 154)
    print("多摩川3コース 第二段階：主軸サイン候補比較")
    print("まくり / まくり差し / 合算攻め率と、3 vs 2 平均ST順位を同じ土俵で比較")
    print("=" * 154)
    print(f"対象期間   : {start_date} ～ {end_date}")
    print(f"採用候補   : {processed}")
    if skips:
        print("スキップ   : " + ", ".join(f"{k}={v}" for k, v in skips.items()))

    all_results: dict[tuple[int, str], dict[str, float]] = {}

    for months in HISTORY_MONTHS:
        base = rates(baseline[months])
        print("\n" + "-" * 154)
        print(f"【過去{months}ヶ月profile】 BASELINE: N={int(base['n'])} / 3頭={base['head']:.2f}% / 2連対={base['top2']:.2f}% / 3連対={base['top3']:.2f}%")
        print("-" * 154)

        for code, label, kind, attack_key, relation in CANDIDATES:
            stat = resolve_stat(
                months, kind, attack_key, relation,
                baseline, by_relation, by_attack_band, by_cross,
            )
            r = rates(stat)
            all_results[(months, code)] = r
            print_row(code, label, r, base)

    print("\n" + "=" * 154)
    print("【12ヶ月 / 6ヶ月profile 再現性比較】")
    print("=" * 154)
    print("※ Nは各profile条件に該当したレース数。12ヶ月と6ヶ月は同じ対象レースを使うため独立標本ではありません。")
    print("※ ここでは『強さ』だけでなく、Nが十分あるか・両profileで同じ方向かを確認します。")

    for code, label, *_ in CANDIDATES:
        r12 = all_results.get((12, code))
        r6 = all_results.get((6, code))
        if not r12 or not r6:
            continue
        print(
            f"{code:<8} {label:<28} "
            f"N12={int(r12['n']):4d} / N6={int(r6['n']):4d}  "
            f"3頭={r12['head']:5.2f}%→{r6['head']:5.2f}%  "
            f"差={r6['head'] - r12['head']:+5.2f}pt  "
            f"3連対={r12['top3']:5.2f}%→{r6['top3']:5.2f}%"
        )

    print("\n" + "=" * 154)
    print("見るポイント")
    print("  1) 合算攻め率15%以上が、まくり単独よりNを確保しながら安定して上振れるか")
    print("  2) 純まくり15%以上は、主軸というより『さらに強い攻め』の上位サインとして使えるか")
    print("  3) ST上を足した時に、N減少以上の改善が12ヶ月/6ヶ月の両方で再現するか")
    print("※ この結果だけではまだ3コース★条件を確定しません。次に主軸候補を絞って時系列安定性を確認します。")
    print("=" * 154)


if __name__ == "__main__":
    main()
