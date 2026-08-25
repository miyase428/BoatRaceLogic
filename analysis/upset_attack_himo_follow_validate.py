#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
穴目予想：現在の「展開候補」から2着・3着の連動先をP1/P2/P3で確認する。

目的
----
既存の穴警戒HIGHと、Web表示中の展開候補ルールをそのまま固定し、
展開候補コースが実際に1着になった場合に、どのコースが2着・3着へ
ついてきやすいかを診断する。

固定条件
--------
HIGH:
- AI本命 = 1C
- CURRENT本命 != 1C
- イン補正後1着率 < 50%

展開候補:
- 3C / 4C / 5C
- 6month point-in-time sample_n >= 10
- 攻め率 = まくり + まくり差し
- 攻め率最大コースを1つ選択（同率は内側優先）
- 表示技は同コース内で率の高い方

評価
----
- P1 / P2 / P3を分ける
- 展開候補が実際に1着だったレースだけで、実2着・実3着のコース分布を確認
- 1C / 直内 / 2つ内 / 直外 / 2つ外の連動率を確認
- 実2着-実3着ペアの上位を確認

重要
----
- 実1着条件は診断ラベルとしてのみ使う。購入条件には使わない。
- 閾値探索をしない。現行展開候補ルールをそのまま使う。
- 既存すじ舟券の15%/20%条件を再探索しない。
- P3を見て条件を変更しない。
- 本番Web/PredictionLogicは変更しない。

Usage:
python3 analysis/upset_attack_himo_follow_validate.py \
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

import upset_attack_scenario_validate as attack
import upset_in_remaining_validate as remain


def pct(n: int, d: int) -> float:
    return 100.0 * n / d if d else 0.0


def relation_courses(attacker: int) -> list[tuple[str, int]]:
    items: list[tuple[str, int]] = [("1C", 1)]
    for label, c in (
        ("直内", attacker - 1),
        ("2つ内", attacker - 2),
        ("直外", attacker + 1),
        ("2つ外", attacker + 2),
    ):
        if 1 <= c <= 6 and all(existing != c for _, existing in items):
            items.append((label, c))
    return items


def head_rows(rows: list[dict], attacker: int) -> list[dict]:
    return [
        r for r in rows
        if int(r.get("course", 0)) == attacker
        and int(r.get("actual_1st_course", 0)) == attacker
    ]


def print_course_distribution(rows: list[dict], attacker: int) -> None:
    n = len(rows)
    second = Counter(int(r.get("actual_2nd_course", 0)) for r in rows)
    third = Counter(int(r.get("actual_3rd_course", 0)) for r in rows)

    print("    実2着コース: " + " / ".join(
        f"{c}C={second[c]}({pct(second[c], n):.1f}%)"
        for c in range(1, 7) if c != attacker
    ))
    print("    実3着コース: " + " / ".join(
        f"{c}C={third[c]}({pct(third[c], n):.1f}%)"
        for c in range(1, 7) if c != attacker
    ))


def print_relation(rows: list[dict], attacker: int) -> None:
    n = len(rows)
    if n <= 0:
        return
    print("    連動位置（候補が実頭だった時）")
    print("      位置        コース   2着率   3着率   2・3着内率")
    print("      " + "-" * 50)
    for label, course in relation_courses(attacker):
        s = sum(1 for r in rows if int(r.get("actual_2nd_course", 0)) == course)
        t = sum(1 for r in rows if int(r.get("actual_3rd_course", 0)) == course)
        print(
            f"      {label:<8} {course:>2}C   {pct(s,n):>6.2f}%  "
            f"{pct(t,n):>6.2f}%    {pct(s+t,n):>6.2f}%"
        )


def print_top_pairs(rows: list[dict], limit: int = 8) -> None:
    n = len(rows)
    pairs = Counter(
        (int(r.get("actual_2nd_course", 0)), int(r.get("actual_3rd_course", 0)))
        for r in rows
    )
    print("    実2着-3着ペア上位:")
    if not pairs:
        print("      -")
        return
    for (s, t), cnt in pairs.most_common(limit):
        print(f"      {s}-{t}: {cnt}/{n} = {pct(cnt,n):.2f}%")


def print_technique_split(rows: list[dict], attacker: int) -> None:
    head = head_rows(rows, attacker)
    techs = ["まくり", "まくり差し"]
    parts = [(tech, [r for r in head if str(r.get("technique", "")) == tech]) for tech in techs]
    print("    表示技別（参考）")
    for tech, part in parts:
        n = len(part)
        if n == 0:
            print(f"      {tech}: N=0")
            continue
        second = Counter(int(r.get("actual_2nd_course", 0)) for r in part)
        top = sorted(second.items(), key=lambda x: (-x[1], x[0]))[:3]
        text = " / ".join(f"{c}C {pct(cnt,n):.1f}%" for c, cnt in top)
        print(f"      {tech}: N={n} / 2着上位 {text}")


def print_period(title: str, rows: list[dict]) -> None:
    print("\n" + "=" * 118)
    print(f"【{title}】 展開候補作成R={len(rows)}")
    print("=" * 118)

    for attacker in attack.ATTACK_COURSES:
        selected = [r for r in rows if int(r.get("course", 0)) == attacker]
        won = head_rows(rows, attacker)
        print(
            f"\n--- 展開候補 {attacker}C --- "
            f"候補R={len(selected)} / 実頭={len(won)} ({pct(len(won), len(selected)):.2f}%)"
        )
        if not won:
            continue
        print_course_distribution(won, attacker)
        print_relation(won, attacker)
        print_top_pairs(won)
        print_technique_split(rows, attacker)


def main() -> None:
    if len(sys.argv) != 6:
        print(
            "Usage: python3 analysis/upset_attack_himo_follow_validate.py "
            "P1_BOATS P2_BOATS P3_BOATS TRAIN_KIMARITE P3_KIMARITE"
        )
        sys.exit(1)

    p1, p2, p3, train_k, p3_k = sys.argv[1:]

    print("穴警戒HIGHと現行展開候補を固定し、ヒモ連動をP1/P2/P3で構築中...", flush=True)
    high = remain.build_all(p1, p2, p3)
    train_map = attack.load_kimarite(train_k)
    p3_map = attack.load_kimarite(p3_k)

    p1_rows, p1_skip = attack.attach(high["p1"], train_map)
    p2_rows, p2_skip = attack.attach(high["p2"], train_map)
    p3_rows, p3_skip = attack.attach(high["p3"], p3_map)

    print("=" * 118)
    print("穴目予想：現行展開候補 → 2着・3着ヒモ連動 P1/P2/P3")
    print("=" * 118)
    print(f"P1 : {high['train_start']} ～ {high['train_end']} の前半期間")
    print(f"P3 : {high['p3_start']} ～ {high['p3_end']} 完全未来")
    print("HIGH : AI本命=1C / CURRENT本命!=1C / イン補正後1着率<50%（固定）")
    print("展開候補 : 3〜5Cの6m攻め率最大 / sample_n>=10 / 閾値追加なし")
    print("実頭条件 : 診断ラベルのみ。購入条件ではない")
    print("本番Web/PredictionLogic変更: なし")

    print_period("P1", p1_rows)
    print_period("P2", p2_rows)
    print_period("P3完全未来", p3_rows)

    print("\n【join参考】")
    print("P1:", dict(p1_skip))
    print("P2:", dict(p2_skip))
    print("P3:", dict(p3_skip))

    print("\n【判断ポイント】")
    print("1. 展開候補が実頭だった時の2着連動先がP1/P2/P3で同じ方向か")
    print("2. 特に3C候補で直外4Cだけでなく2つ外5Cが安定して上がるか")
    print("3. 1C残り・直内・直外の優先順位が候補コースごとに再現するか")
    print("4. 技別は参考。小標本を見て新条件を作らない")
    print("5. 再現した連動だけを既存すじ舟券と次STEPで橋渡しする")
    print("=" * 118)


if __name__ == "__main__":
    main()
