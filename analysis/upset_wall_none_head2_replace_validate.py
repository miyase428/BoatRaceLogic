#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
穴目予想：壁なしスリット時に、穴頭TRIO_TOP1が2Cなら維持すべきか、
AI3連対率の次点外艇へ差し替えるべきかをP1/P2/P3で固定検証する。

固定条件
--------
- HIGH: AI本命=1C / CURRENT本命!=1C / イン補正後1着率<50%
- wall_none: 現行C_ST_RANK / big_delay=0.04 / line=0.05 の特徴フラグ
- 穴頭本命: イン以外AI3連対率1位（TRIO_TOP1）
- 対象: WALL_FLAG成立かつTRIO_TOP1の進入コースが2C
- ALT_HEAD: AI3連対率順位で次に高い3〜6C艇
- 閾値・スリット定義・HIGH条件は変更しない

見るもの
--------
1. BASE_HEAD(2C)とALT_HEAD(3〜6C次点)の実1着捕捉率
2. BASE→ALTで拾う/失う件数
3. 実頭コース分布

重要
----
- P3を見て追加条件や閾値を作らない。
- 本番Web/PredictionLogicは変更しない。
- まず頭候補の診断だけを行い、買い目ROIはこの結果が通った場合のみ次STEP。

Usage:
python3 analysis/upset_wall_none_head2_replace_validate.py \
  analysis/output/final_prediction_boats_20260615_20260714_OLD.csv \
  analysis/output/final_prediction_boats_20260715_20260814_OLD.csv \
  analysis/output/final_prediction_boats_fast_cached_20260815_20260822.csv
"""

from __future__ import annotations

import sys
from collections import Counter
from pathlib import Path

ANALYSIS_DIR = Path(__file__).resolve().parent
if str(ANALYSIS_DIR) not in sys.path:
    sys.path.insert(0, str(ANALYSIS_DIR))

import final_prediction_ai_opponent_compare as b2
import final_prediction_ai_bet_integration_compare as b4
import trifecta_probability_order_compare as step3
import upset_probability_validate as c1
import upset_wall_none_interaction_validate as wall


def pct(n, d):
    return 100.0 * n / d if d else 0.0


def ranked_outer(trio, in_lane):
    return sorted(
        [lane for lane in range(1, 7) if lane != int(in_lane)],
        key=lambda lane: (-float(trio.get(lane, 0.0)), int(lane)),
    )


def build_rows(records, boats_map, slit_map):
    rows = []
    skip = Counter()

    for record in records:
        code = str(record["race_code"])
        boats = boats_map.get(code)
        if boats is None or set(boats) != set(range(1, 7)):
            skip["boats_missing"] += 1
            continue

        f = c1.make_features(record, boats)
        if f is None:
            skip["feature_invalid"] += 1
            continue

        in_lane = int(f["in_lane"])
        current = int(f["current_head"])
        ai_head = int(f["ai_head"])
        in_win_p = float(f["values"]["in_win_p"])
        if not (ai_head == in_lane and current != in_lane and in_win_p < 0.50):
            skip["not_high"] += 1
            continue

        s = slit_map.get(code)
        if s is None:
            skip["slit_missing"] += 1
            continue
        if not bool(s.get("wall_none")):
            skip["not_wall"] += 1
            continue

        course_by_lane = {int(k): int(v) for k, v in f["course_by_lane"].items()}
        rank = ranked_outer(f["trio"], in_lane)
        if len(rank) < 2:
            skip["trio_invalid"] += 1
            continue

        base_head = int(rank[0])
        base_course = int(course_by_lane.get(base_head, 0))
        if base_course != 2:
            skip["base_not2c"] += 1
            continue

        alt_head = 0
        alt_course = 0
        for lane in rank[1:]:
            c = int(course_by_lane.get(int(lane), 0))
            if c in (3, 4, 5, 6):
                alt_head = int(lane)
                alt_course = c
                break
        if alt_head <= 0:
            skip["alt_missing"] += 1
            continue

        actual = b4.actual_trifecta(boats)
        if actual is None:
            skip["actual_invalid"] += 1
            continue
        actual = tuple(int(x) for x in actual)
        actual_first = int(actual[0])
        actual_course = int(course_by_lane.get(actual_first, 0))
        if actual_course not in range(1, 7):
            skip["course_map_invalid"] += 1
            continue

        rows.append({
            "race_code": code,
            "base_head": base_head,
            "base_course": base_course,
            "alt_head": alt_head,
            "alt_course": alt_course,
            "actual_first": actual_first,
            "actual_course": actual_course,
            "base_hit": actual_first == base_head,
            "alt_hit": actual_first == alt_head,
        })
        skip["ready"] += 1

    return rows, skip


def print_period(title, rows, skip):
    print("\n" + "=" * 118)
    print(f"【{title}】 WALL_FLAGかつ穴頭TRIO_TOP1=2C 対象R={len(rows)}")
    print("=" * 118)
    if not rows:
        print("対象なし")
        print("skip参考:", dict(skip))
        return

    n = len(rows)
    base_hit = sum(r["base_hit"] for r in rows)
    alt_hit = sum(r["alt_hit"] for r in rows)
    gained = sum((not r["base_hit"]) and r["alt_hit"] for r in rows)
    lost = sum(r["base_hit"] and (not r["alt_hit"]) for r in rows)
    neither = sum((not r["base_hit"]) and (not r["alt_hit"]) for r in rows)

    alt_courses = Counter(r["alt_course"] for r in rows)
    actual_courses = Counter(r["actual_course"] for r in rows)

    print(f"BASE_HEAD(2C) 実1着捕捉 : {base_hit}/{n} = {pct(base_hit,n):.2f}%")
    print(f"ALT_HEAD(3〜6C次点)捕捉 : {alt_hit}/{n} = {pct(alt_hit,n):.2f}%")
    print(f"差                      : {pct(alt_hit,n)-pct(base_hit,n):+.2f}pt")
    print(f"BASE→ALT 拾い={gained} / 失い={lost} / どちらも外れ={neither}")

    print("\nALT_HEADコース分布:")
    print("  " + " / ".join(f"{c}C={k}({pct(k,n):.1f}%)" for c, k in sorted(alt_courses.items())))
    print("実頭コース分布:")
    print("  " + " / ".join(f"{c}C={k}({pct(k,n):.1f}%)" for c, k in sorted(actual_courses.items())))
    print("skip参考:", dict(skip))


def main():
    if len(sys.argv) != 4:
        print(
            "Usage: python3 analysis/upset_wall_none_head2_replace_validate.py P1_BOATS P2_BOATS P3_BOATS",
            file=sys.stderr,
        )
        sys.exit(1)

    p1, p2, p3 = sys.argv[1:]

    print("壁なし時の2C穴頭維持 vs 3〜6C次点差し替えをP1/P2/P3で固定検証中...", flush=True)
    train = step3.build_common_records(p1, p2)
    future = step3.build_common_records(p2, p3)
    boats_map = b2.load_boats(p1, p2, p3)

    print("P1スリットを一括構築中...", flush=True)
    p1_slit, _, _ = wall.build_slit_map(train["p1_start"], train["p1_end"])
    print("P2スリットを一括構築中...", flush=True)
    p2_slit, _, _ = wall.build_slit_map(train["p2_start"], train["p2_end"])
    print("P3スリットを一括構築中...", flush=True)
    p3_slit, _, _ = wall.build_slit_map(future["p2_start"], future["p2_end"])

    p1_rows, p1_skip = build_rows(train["records"]["P1"], boats_map, p1_slit)
    p2_rows, p2_skip = build_rows(train["records"]["P2"], boats_map, p2_slit)
    p3_rows, p3_skip = build_rows(future["records"]["P2"], boats_map, p3_slit)

    print("=" * 118)
    print("穴目予想：壁なし × 穴頭2C → 次点外艇差し替え P1/P2/P3")
    print("=" * 118)
    print("固定: HIGH / WALL_FLAG / TRIO_TOP1=2C / ALT=AI3連対率の次点3〜6C")
    print("本番変更: なし")

    print_period("P1", p1_rows, p1_skip)
    print_period("P2", p2_rows, p2_skip)
    print_period("P3完全未来", p3_rows, p3_skip)

    print("\n【判断ポイント】")
    print("1. ALT_HEADの実1着捕捉がP1/P2/P3すべてでBASE_HEAD(2C)を上回るか")
    print("2. 拾い>失いが3期間で再現するか")
    print("3. P3を見てALTの条件やコース範囲を変更しない")
    print("4. 通れば次STEPで穴ヒモ/買い目ROIへ接続、通らなければ壁なしは参考表示に留める")
    print("=" * 118)


if __name__ == "__main__":
    main()
