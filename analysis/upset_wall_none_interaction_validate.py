#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
穴目予想：固定済みスリット「壁なし」と、HIGH時のイン敗戦・外頭構造の相互作用をP1/P2/P3で確認する。

固定条件
--------
- HIGH: AI本命=1C / CURRENT本命!=1C / イン補正後1着率<50%
- スリット: 現行C_ST_RANKで作った予測ST
- wall_none: 現行 big_delay_threshold=0.04 のまま
- line_abreast: 現行 spread<=0.05 のまま
- 最終PIDの優先順位も現行維持

見るもの
--------
1. HIGH全体と wall_none成立時で、イン敗戦率が変わるか
2. イン敗戦時に①が2着 / 3着 / 4着以下のどこへ行くか
3. 実頭が3C / 4-6C / 5-6Cへ寄るか
4. wall_noneフラグは上位PIDに隠れる場合があるため、最終PID5も参考表示する

重要
----
- スリット閾値は変更しない。
- actual着順は評価ラベルにのみ使用する。
- P3を見て新しい条件や閾値を作らない。
- 本番Web/PredictionLogicは変更しない。

Usage:
python3 analysis/upset_wall_none_interaction_validate.py \
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
import slit_pattern_condition_analyze as slit

DELAY_TH = 0.04
LINE_TH = 0.05


def pct(n, d):
    return 100.0 * n / d if d else 0.0


def build_slit_map(start_date, end_date):
    # step3.build_common_records() は datetime.date を返すため、
    # 既存 slit.prepare_races() が要求する YYYY-MM-DD 文字列へ揃える。
    if hasattr(start_date, "isoformat"):
        start_date = start_date.isoformat()
    if hasattr(end_date, "isoformat"):
        end_date = end_date.isoformat()

    prepared, skip, terms = slit.prepare_races(start_date, end_date)
    out = {}
    for race_code, predicted_st, _finish in prepared:
        features = slit.extract_features_param(predicted_st, DELAY_TH, LINE_TH)
        pid = slit.decide_pattern(features)
        out[str(race_code)] = {
            "wall_none": bool(features.get("wall_none")),
            "pid": int(pid),
        }
    return out, skip, terms


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

        actual = b4.actual_trifecta(boats)
        if actual is None:
            skip["actual_invalid"] += 1
            continue
        actual = tuple(int(x) for x in actual)

        course_by_lane = {int(k): int(v) for k, v in f["course_by_lane"].items()}
        actual_courses = tuple(course_by_lane.get(x, 0) for x in actual)
        if not all(c in range(1, 7) for c in actual_courses):
            skip["course_map_invalid"] += 1
            continue

        in_failed = actual[0] != in_lane
        if actual[1] == in_lane:
            in_pos = 2
        elif actual[2] == in_lane:
            in_pos = 3
        else:
            in_pos = 4

        rows.append({
            "race_code": code,
            "wall_none": bool(s["wall_none"]),
            "pid": int(s["pid"]),
            "in_failed": in_failed,
            "in_pos": in_pos,
            "head_course": int(actual_courses[0]),
        })
        skip["ready"] += 1

    return rows, skip


def metrics(rows):
    n = len(rows)
    fail = [r for r in rows if r["in_failed"]]
    fn = len(fail)
    in2 = sum(r["in_pos"] == 2 for r in fail)
    in3 = sum(r["in_pos"] == 3 for r in fail)
    fly = sum(r["in_pos"] == 4 for r in fail)
    h2 = sum(r["head_course"] == 2 for r in fail)
    h3 = sum(r["head_course"] == 3 for r in fail)
    h46 = sum(r["head_course"] in (4, 5, 6) for r in fail)
    h56 = sum(r["head_course"] in (5, 6) for r in fail)
    return {
        "n": n,
        "fail_n": fn,
        "fail_rate": pct(fn, n),
        "in2": pct(in2, fn),
        "in3": pct(in3, fn),
        "fly": pct(fly, fn),
        "h2": pct(h2, fn),
        "h3": pct(h3, fn),
        "h46": pct(h46, fn),
        "h56": pct(h56, fn),
    }


def print_period(title, rows, skip):
    print("\n" + "=" * 126)
    print(f"【{title}】 HIGH連結済み={len(rows)}R")
    print("=" * 126)

    groups = [
        ("ALL_HIGH", rows),
        ("NO_WALL", [r for r in rows if not r["wall_none"]]),
        ("WALL_FLAG", [r for r in rows if r["wall_none"]]),
        ("PID5_ONLY", [r for r in rows if r["pid"] == 5]),
    ]

    print("群             R数   イン敗戦   敗戦時①2着  ①3着   飛び    2C頭    3C頭   4-6C頭  5-6C頭")
    print("-" * 112)
    result = {}
    for name, part in groups:
        m = metrics(part)
        result[name] = m
        print(
            f"{name:<12} {m['n']:>5d}  {m['fail_rate']:>7.2f}%    "
            f"{m['in2']:>7.2f}% {m['in3']:>7.2f}% {m['fly']:>7.2f}%  "
            f"{m['h2']:>7.2f}% {m['h3']:>7.2f}% {m['h46']:>7.2f}% {m['h56']:>7.2f}%"
        )

    base = result["ALL_HIGH"]
    wall = result["WALL_FLAG"]
    print("\nWALL_FLAG - ALL_HIGH 差")
    print(
        f"イン敗戦 {wall['fail_rate']-base['fail_rate']:+.2f}pt / "
        f"①2着 {wall['in2']-base['in2']:+.2f}pt / "
        f"①3着 {wall['in3']-base['in3']:+.2f}pt / "
        f"飛び {wall['fly']-base['fly']:+.2f}pt / "
        f"3C頭 {wall['h3']-base['h3']:+.2f}pt / "
        f"4-6C頭 {wall['h46']-base['h46']:+.2f}pt / "
        f"5-6C頭 {wall['h56']-base['h56']:+.2f}pt"
    )
    print("skip参考:", dict(skip))
    return result


def main():
    if len(sys.argv) != 4:
        print(
            "Usage: python3 analysis/upset_wall_none_interaction_validate.py P1_BOATS P2_BOATS P3_BOATS",
            file=sys.stderr,
        )
        sys.exit(1)

    p1, p2, p3 = sys.argv[1:]

    print("固定済みスリット『壁なし』と穴警戒HIGHの相互作用をP1/P2/P3で検証中...", flush=True)
    train = step3.build_common_records(p1, p2)
    future = step3.build_common_records(p2, p3)
    boats_map = b2.load_boats(p1, p2, p3)

    print("P1スリットを一括構築中...", flush=True)
    p1_slit, p1_slit_skip, p1_terms = build_slit_map(train["p1_start"], train["p1_end"])
    print("P2スリットを一括構築中...", flush=True)
    p2_slit, p2_slit_skip, p2_terms = build_slit_map(train["p2_start"], train["p2_end"])
    print("P3スリットを一括構築中...", flush=True)
    p3_slit, p3_slit_skip, p3_terms = build_slit_map(future["p2_start"], future["p2_end"])

    p1_rows, p1_skip = build_rows(train["records"]["P1"], boats_map, p1_slit)
    p2_rows, p2_skip = build_rows(train["records"]["P2"], boats_map, p2_slit)
    p3_rows, p3_skip = build_rows(future["records"]["P2"], boats_map, p3_slit)

    print("=" * 126)
    print("穴目予想：壁なしスリット × HIGH イン敗戦・外頭構造 P1/P2/P3")
    print("=" * 126)
    print("HIGH     : AI本命=1C / CURRENT本命!=1C / イン補正後1着率<50%（固定）")
    print("スリット : C_ST_RANK / big_delay=0.04 / line=0.05 / 現行優先順位固定")
    print("WALL_FLAG: 最終PIDに隠れていても wall_none 条件が成立した全レース")
    print("PID5_ONLY: 最終パターンIDが5『壁なし』になったレース")
    print("本番変更 : なし")

    p1_m = print_period("P1", p1_rows, p1_skip)
    p2_m = print_period("P2", p2_rows, p2_skip)
    p3_m = print_period("P3完全未来", p3_rows, p3_skip)

    print("\n【スリット構築参考】")
    print(f"P1 terms={p1_terms} / prepared={len(p1_slit)} / skip={dict(p1_slit_skip)}")
    print(f"P2 terms={p2_terms} / prepared={len(p2_slit)} / skip={dict(p2_slit_skip)}")
    print(f"P3 terms={p3_terms} / prepared={len(p3_slit)} / skip={dict(p3_slit_skip)}")

    print("\n【判断ポイント】")
    print("1. WALL_FLAGでイン敗戦率・4-6C頭率がP1/P2/P3とも同方向に動くか")
    print("2. ①2着/3着/飛びの位置変化が3期間で再現するか")
    print("3. PID5は参考。小標本ならWALL_FLAGを主に見る")
    print("4. P3を見てスリット閾値・HIGH条件・新条件を変更しない")
    print("5. 再現しなければ壁なしは既存スリット表示のまま、穴目レイヤーへ追加しない")
    print("=" * 126)


if __name__ == "__main__":
    main()
