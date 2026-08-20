#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
STEP C3：穴警戒HIGH時の穴頭候補比較

目的
----
C2で再現した穴警戒HIGH

  AI本命 = 1C
  CURRENT本命 != 1C
  インAI1着率 < 50%

のレースだけに絞り、「穴頭候補を1艇だけ出すなら誰が良いか」を比較する。

比較候補
--------
CURRENT_HEAD
    現行最終予想の本命。

WIN_OUTER
    イン艇以外で補正後1着率が最も高い艇。

OUTCOME_OUTER
    イン艇以外でSTEP3最終出目モデルの1着周辺確率が最も高い艇。

TRIO_OUTER
    イン艇以外でAI3連対率が最も高い艇（頭モデルではないため参考）。

PRIMARY_OUTER
    イン艇以外で一次評価スコアが最も高い艇。

SECONDARY_OUTER
    イン艇以外で二次評価スコアが最も高い艇。

FINAL3_OUTER
    イン艇以外で現行final3が最も高い艇。

評価
----
- HIGH全体での実1着率
- HIGH全体での実3連対率
- 1C敗退レースだけでの実1着捕捉率（最重要）
- CURRENT_HEADから候補を変えたときの拾い/失い
- 候補艇の進入コース分布

TRAINで方式を比較し、P3完全未来で同方向に再現するかを見る。
P3側で方式や閾値は再調整しない。

Usage:
python3 analysis/upset_head_candidate_compare.py \
  analysis/output/final_prediction_boats_20260615_20260714_OLD.csv \
  analysis/output/final_prediction_boats_20260715_20260814_OLD.csv \
  analysis/output/final_prediction_boats_20260815_20260819.csv
"""

from __future__ import annotations

import sys
from collections import Counter, defaultdict
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import final_prediction_ai_opponent_compare as b2
import final_prediction_ai_bet_integration_compare as b4
import trifecta_probability_order_compare as step3
import upset_probability_validate as c1


METHODS = (
    "CURRENT_HEAD",
    "WIN_OUTER",
    "OUTCOME_OUTER",
    "TRIO_OUTER",
    "PRIMARY_OUTER",
    "SECONDARY_OUTER",
    "FINAL3_OUTER",
)


def pick_best(values, eligible):
    return min(
        eligible,
        key=lambda lane: (-float(values.get(lane, 0.0)), int(lane)),
    )


def build_candidate_row(record, boats):
    if boats is None or set(boats) != set(range(1, 7)):
        return None

    f = c1.make_features(record, boats)
    if f is None:
        return None

    current = int(f["current_head"])
    ai_head = int(f["ai_head"])
    in_lane = int(f["in_lane"])
    in_win_p = float(f["values"]["in_win_p"])

    # C2で固定したHIGH条件。ここでは一切変更しない。
    if not (
        ai_head == in_lane
        and current != in_lane
        and in_win_p < 0.50
    ):
        return None

    actual = b4.actual_trifecta(boats)
    if actual is None:
        return None
    actual_first = int(actual[0])
    actual_top3 = {int(x) for x in actual}

    eligible = [lane for lane in range(1, 7) if lane != in_lane]
    if not eligible:
        return None

    primary = {lane: float(boats[lane].get("first_score", 0.0)) for lane in eligible}
    secondary = {lane: float(boats[lane].get("second_score", 0.0)) for lane in eligible}
    final3 = {lane: float(boats[lane].get("final3", 0.0)) for lane in eligible}

    candidates = {
        "CURRENT_HEAD": current,
        "WIN_OUTER": pick_best(f["win"], eligible),
        "OUTCOME_OUTER": pick_best(f["outcome_head"], eligible),
        "TRIO_OUTER": pick_best(f["trio"], eligible),
        "PRIMARY_OUTER": pick_best(primary, eligible),
        "SECONDARY_OUTER": pick_best(secondary, eligible),
        "FINAL3_OUTER": pick_best(final3, eligible),
    }

    course_by_lane = f["course_by_lane"]

    return {
        "race_code": str(record["race_code"]),
        "in_lane": in_lane,
        "in_failed": actual_first != in_lane,
        "actual_first": actual_first,
        "actual_top3": actual_top3,
        "candidates": candidates,
        "course_by_lane": course_by_lane,
        "in_win_p": in_win_p,
    }


def build_rows(records, boats_map):
    rows = []
    skip = defaultdict(int)
    for record in records:
        code = str(record["race_code"])
        boats = boats_map.get(code)
        if boats is None or set(boats) != set(range(1, 7)):
            skip["csv_missing"] += 1
            continue
        row = build_candidate_row(record, boats)
        if row is None:
            # HIGH外もここに入るので、異常とは扱わない。
            skip["not_ready_or_not_high"] += 1
            continue
        rows.append(row)
        skip["ready_high"] += 1
    return rows, skip


def evaluate(rows, method):
    n = len(rows)
    wins = 0
    top3 = 0
    in_fail = 0
    in_fail_wins = 0
    course_counts = Counter()

    for r in rows:
        cand = int(r["candidates"][method])
        if cand == r["actual_first"]:
            wins += 1
        if cand in r["actual_top3"]:
            top3 += 1
        if r["in_failed"]:
            in_fail += 1
            if cand == r["actual_first"]:
                in_fail_wins += 1
        c = int(r["course_by_lane"].get(cand, 0))
        course_counts[c] += 1

    return {
        "n": n,
        "wins": wins,
        "win_rate": wins / n if n else 0.0,
        "top3": top3,
        "top3_rate": top3 / n if n else 0.0,
        "in_fail": in_fail,
        "in_fail_wins": in_fail_wins,
        "in_fail_win_rate": in_fail_wins / in_fail if in_fail else 0.0,
        "course_counts": course_counts,
    }


def compare_current(rows, method):
    changed = gained = lost = same_win = same_miss = 0
    changed_fail = gained_fail = lost_fail = 0

    for r in rows:
        cur = int(r["candidates"]["CURRENT_HEAD"])
        new = int(r["candidates"][method])
        if cur != new:
            changed += 1
            if r["in_failed"]:
                changed_fail += 1

        cur_hit = cur == r["actual_first"]
        new_hit = new == r["actual_first"]
        if new_hit and not cur_hit:
            gained += 1
            if r["in_failed"]:
                gained_fail += 1
        elif cur_hit and not new_hit:
            lost += 1
            if r["in_failed"]:
                lost_fail += 1
        elif cur_hit and new_hit:
            same_win += 1
        else:
            same_miss += 1

    return {
        "changed": changed,
        "gained": gained,
        "lost": lost,
        "same_win": same_win,
        "same_miss": same_miss,
        "changed_fail": changed_fail,
        "gained_fail": gained_fail,
        "lost_fail": lost_fail,
    }


def course_text(result):
    n = result["n"]
    parts = []
    for c in range(2, 7):
        cnt = result["course_counts"].get(c, 0)
        if cnt:
            parts.append(f"{c}C={cnt/n*100:.1f}%")
    return " / ".join(parts) if parts else "-"


def print_period(title, rows):
    print(f"\n【{title}】")
    print(f"HIGH対象={len(rows)}R")
    print("方式                 R数   実1着率   実3連対率   1C敗退R   1C敗退時頭捕捉")
    print("-" * 92)

    results = {}
    for method in METHODS:
        r = evaluate(rows, method)
        results[method] = r
        print(
            f"{method:<20} {r['n']:>5d}   {r['win_rate']*100:>7.2f}%   "
            f"{r['top3_rate']*100:>8.2f}%   {r['in_fail']:>7d}   "
            f"{r['in_fail_wins']:>4d}/{r['in_fail']:<4d} {r['in_fail_win_rate']*100:>7.2f}%"
        )

    print("\nCURRENT_HEADからの差")
    print("方式                 変更R   拾い   失い   1C敗退変更   1C敗退拾い   1C敗退失い")
    print("-" * 94)
    for method in METHODS:
        if method == "CURRENT_HEAD":
            continue
        c = compare_current(rows, method)
        print(
            f"{method:<20} {c['changed']:>5d}   {c['gained']:>4d}   {c['lost']:>4d}   "
            f"{c['changed_fail']:>9d}   {c['gained_fail']:>10d}   {c['lost_fail']:>10d}"
        )

    print("\n候補艇の進入コース分布")
    for method in METHODS:
        print(f"{method:<20}: {course_text(results[method])}")

    return results


def select_train_method(results):
    # 穴頭候補なので、最重要は「1C敗退時に実頭を拾う率」。
    # 同率ならHIGH全体1着率→3連対率→CURRENTからの変更が少ない順。
    ranked = []
    for method, r in results.items():
        key = (
            -r["in_fail_win_rate"],
            -r["win_rate"],
            -r["top3_rate"],
            0 if method == "CURRENT_HEAD" else 1,
            method,
        )
        ranked.append((key, method))
    ranked.sort(key=lambda x: x[0])
    return ranked[0][1]


def print_fixed_comparison(train_results, p3_results, selected):
    cur_tr = train_results["CURRENT_HEAD"]
    sel_tr = train_results[selected]
    cur_p3 = p3_results["CURRENT_HEAD"]
    sel_p3 = p3_results[selected]

    print("\n【TRAINで選んだ方式をP3へ固定】")
    print(f"選択方式: {selected}")
    print("指標                         TRAIN差       P3差")
    print("-" * 62)
    print(
        f"HIGH全体 実1着率          "
        f"{(sel_tr['win_rate']-cur_tr['win_rate'])*100:+7.2f}pt   "
        f"{(sel_p3['win_rate']-cur_p3['win_rate'])*100:+7.2f}pt"
    )
    print(
        f"HIGH全体 実3連対率        "
        f"{(sel_tr['top3_rate']-cur_tr['top3_rate'])*100:+7.2f}pt   "
        f"{(sel_p3['top3_rate']-cur_p3['top3_rate'])*100:+7.2f}pt"
    )
    print(
        f"1C敗退時 実頭捕捉率       "
        f"{(sel_tr['in_fail_win_rate']-cur_tr['in_fail_win_rate'])*100:+7.2f}pt   "
        f"{(sel_p3['in_fail_win_rate']-cur_p3['in_fail_win_rate'])*100:+7.2f}pt"
    )


def main():
    if len(sys.argv) != 4:
        print("Usage: python3 analysis/upset_head_candidate_compare.py P1_BOATS_CSV P2_BOATS_CSV P3_BOATS_CSV")
        sys.exit(1)

    p1_csv, p2_csv, p3_csv = sys.argv[1], sys.argv[2], sys.argv[3]

    print("C2のHIGH条件を固定し、既存AI/評価から穴頭候補を再構築中...")
    train_data = step3.build_common_records(p1_csv, p2_csv)
    future_data = step3.build_common_records(p2_csv, p3_csv)

    p1_records = train_data["records"]["P1"]
    p2_records = train_data["records"]["P2"]
    p3_records = future_data["records"]["P2"]

    boats_map = b2.load_boats(p1_csv, p2_csv, p3_csv)

    p1_rows, _ = build_rows(p1_records, boats_map)
    p2_rows, _ = build_rows(p2_records, boats_map)
    p3_rows, _ = build_rows(p3_records, boats_map)
    train_rows = p1_rows + p2_rows

    print("=" * 126)
    print("STEP C3：穴警戒HIGH時の穴頭候補比較")
    print("=" * 126)
    print(f"TRAIN : {train_data['p1_start']} ～ {train_data['p2_end']}")
    print(f"P3    : {future_data['p2_start']} ～ {future_data['p2_end']} 完全未来")
    print("HIGH  : AI本命=1C & CURRENT本命!=1C & インAI1着率<50%（C2固定）")
    print("穴候補: CURRENT / 外艇WIN / 外艇OUTCOME / 外艇TRIO / 一次 / 二次 / final3")
    print("本番Web変更: なし")

    train_results = print_period("TRAIN", train_rows)
    p3_results = print_period("P3完全未来（最重要）", p3_rows)

    selected = select_train_method(train_results)
    print_fixed_comparison(train_results, p3_results, selected)

    print("\n【判断方針】")
    print("1. 最重要はP3の『1C敗退時 実頭捕捉率』")
    print("2. TRAINで良くてもP3でCURRENT_HEADを下回る方式は採用しない")
    print("3. 実1着率だけでなく3連対率も確認し、穴頭/相手どちらにも使えるかを見る")
    print("4. CURRENT_HEADが最良なら、初版WebはCURRENT本命を穴頭候補としてそのまま採用する")
    print("5. 別方式が安定改善なら、その方式を穴頭候補初版へ採用候補とする")
    print("6. 1艇選択で限界があれば、次STEPで穴候補Top2または展開/ST/場特性を追加する")
    print("=" * 126)


if __name__ == "__main__":
    main()
