#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
穴目予想：展開候補の「技表示」を検証する。

対象は既存C2 HIGHかつ、3～5C攻め起点候補が実際に1着だったレース。
候補コースの6m point-in-time決まり手率から
「まくり / まくり差し」のどちらを表示するかを比較する。

比較方式:
- HIGHER_RATE      : まくり率とまくり差し率の高い方（現候補）
- ALWAYS_MAKURI    : 常にまくり
- ALWAYS_MAKURIZASHI: 常にまくり差し

方式選択はTRAIN(P1+P2)の一致率で行い、P3では再選択しない。
本番Web変更なし。

Usage:
python3 analysis/upset_attack_technique_validate.py \
  analysis/output/final_prediction_boats_20260615_20260714_OLD.csv \
  analysis/output/final_prediction_boats_20260715_20260814_OLD.csv \
  analysis/output/final_prediction_boats_fast_cached_20260815_20260822.csv \
  analysis/output/kimarite_analysis_dataset_20250815_20260814.csv \
  analysis/output/kimarite_analysis_dataset_20260815_20260822.csv
"""

from __future__ import annotations

import sys
from collections import Counter
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import upset_in_remaining_validate as remain
import upset_attack_scenario_validate as attack

METHODS = ("HIGHER_RATE", "ALWAYS_MAKURI", "ALWAYS_MAKURIZASHI")
TARGET_TECHS = {"まくり", "まくり差し"}


def build_rows(high_rows, kimarite_map):
    rows = []
    skip = Counter()
    for h in high_rows:
        code = str(h["race_code"])
        k = kimarite_map.get(code)
        if k is None:
            skip["kimarite_missing"] += 1
            continue
        picked = attack.pick_attack(k)
        if picked is None:
            skip["attack_sample_missing"] += 1
            continue

        actual1 = attack.to_int(k.get("actual_1st_course"))
        candidate_course = int(picked["course"])
        if actual1 != candidate_course:
            skip["candidate_not_winner"] += 1
            continue

        winner_tech = attack.normalize_technique(k.get("winner_technique"))
        _total, makuri, makurizashi = attack.attack_value(k, candidate_course)
        higher = "まくり" if makuri >= makurizashi else "まくり差し"

        rows.append({
            "race_code": code,
            "course": candidate_course,
            "winner_technique": winner_tech,
            "makuri": float(makuri),
            "makurizashi": float(makurizashi),
            "pred": {
                "HIGHER_RATE": higher,
                "ALWAYS_MAKURI": "まくり",
                "ALWAYS_MAKURIZASHI": "まくり差し",
            },
        })
        skip["ready_candidate_win"] += 1
    return rows, skip


def evaluate(rows, method):
    n = len(rows)
    exact = sum(1 for r in rows if r["pred"][method] == r["winner_technique"])
    eligible = [r for r in rows if r["winner_technique"] in TARGET_TECHS]
    eligible_exact = sum(1 for r in eligible if r["pred"][method] == r["winner_technique"])
    return {
        "n": n,
        "exact": exact,
        "exact_rate": exact / n if n else 0.0,
        "eligible_n": len(eligible),
        "eligible_exact": eligible_exact,
        "eligible_rate": eligible_exact / len(eligible) if eligible else 0.0,
    }


def print_period(title, rows):
    print(f"\n【{title}】 candidate-course実1着={len(rows)}R")
    techs = Counter(r["winner_technique"] for r in rows)
    print("実決まり手分布: " + " / ".join(f"{k or '-'}={v}" for k, v in techs.most_common()))
    print("方式                  全候補勝利R一致          まくり/まくり差し限定一致")
    print("-" * 86)
    out = {}
    for method in METHODS:
        m = evaluate(rows, method)
        out[method] = m
        print(
            f"{method:<22} {m['exact']:>4d}/{m['n']:<4d} {m['exact_rate']*100:>7.2f}%      "
            f"{m['eligible_exact']:>4d}/{m['eligible_n']:<4d} {m['eligible_rate']*100:>7.2f}%"
        )
    return out


def print_by_course(title, rows, method):
    print(f"\n【{title}: {method} コース別】")
    print("候補C    R数   全一致率   対象技限定一致率")
    print("-" * 50)
    for c in (3, 4, 5):
        part = [r for r in rows if r["course"] == c]
        m = evaluate(part, method)
        print(
            f"{c}C      {m['n']:>4d}    {m['exact_rate']*100:>7.2f}%       {m['eligible_rate']*100:>7.2f}%"
        )


def select_method(results):
    ranked = sorted(
        METHODS,
        key=lambda method: (
            -results[method]["exact_rate"],
            -results[method]["eligible_rate"],
            METHODS.index(method),
        ),
    )
    return ranked[0]


def main():
    if len(sys.argv) != 6:
        print("Usage: python3 analysis/upset_attack_technique_validate.py P1 P2 P3 TRAIN_KIMARITE P3_KIMARITE")
        sys.exit(1)

    p1, p2, p3, train_k, p3_k = sys.argv[1:]
    high = remain.build_all(p1, p2, p3)
    train_map = attack.load_kimarite(train_k)
    p3_map = attack.load_kimarite(p3_k)

    p1_rows, p1_skip = build_rows(high["p1"], train_map)
    p2_rows, p2_skip = build_rows(high["p2"], train_map)
    p3_rows, p3_skip = build_rows(high["p3"], p3_map)
    train_rows = p1_rows + p2_rows

    print("=" * 104)
    print("穴目予想：展開候補 技表示 TRAIN選択 / 完全未来固定検証")
    print("=" * 104)
    print(f"P1    : {high['train_start']} ～ 2026-07-14")
    print("P2    : 2026-07-15 ～ 2026-08-14")
    print(f"P3    : {high['p3_start']} ～ {high['p3_end']} 完全未来")
    print("対象  : HIGH内で、3～5C攻め起点候補が実際に1着だったレース")
    print("選択  : TRAIN(P1+P2)一致率優先 / P3再選択なし")
    print("本番Web変更: なし")

    p1_result = print_period("P1", p1_rows)
    p2_result = print_period("P2", p2_rows)
    train_result = print_period("TRAIN(P1+P2)", train_rows)
    selected = select_method(train_result)
    p3_result = print_period("P3完全未来", p3_rows)

    print(f"\n【TRAINで固定する方式】 {selected}")
    print(
        f"TRAIN 全候補勝利R一致={train_result[selected]['exact_rate']*100:.2f}% / "
        f"P3={p3_result[selected]['exact_rate']*100:.2f}%"
    )
    print(
        f"TRAIN 対象技限定一致={train_result[selected]['eligible_rate']*100:.2f}% / "
        f"P3={p3_result[selected]['eligible_rate']*100:.2f}%"
    )

    print_by_course("TRAIN", train_rows, selected)
    print_by_course("P3完全未来", p3_rows, selected)

    print("\n【join参考】")
    print("P1:", dict(p1_skip))
    print("P2:", dict(p2_skip))
    print("P3:", dict(p3_skip))

    print("\n【判断ポイント】")
    print("1. HIGHER_RATEがP1/P2双方で固定方式として妥当か")
    print("2. TRAINで選んだ方式がP3でも同方向に再現するか")
    print("3. 技表示が弱ければ、展開候補は『4C攻め』のようにコースだけ表示する")
    print("4. P3を見て方式を選び直さない")
    print("=" * 104)


if __name__ == "__main__":
    main()
