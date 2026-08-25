#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
穴目予想：橋渡し検証でP1/P2/P3すべてBASE超えだった残りの形成
  - 展開候補4C -> 4-1-*
  - 展開候補5C -> 5-1-*
について、120通り穴ヒモTop3への追加価値を固定条件で前方検証する。

固定
----
- HIGH: AI本命=1C / CURRENT本命!=1C / イン補正後1着率<50%
- 穴頭本命: TRIO_TOP1
- 展開候補: 3〜5Cの6month PIT攻め率最大 / sample_n>=10
- 現行cut維持。1Cがcutなら救済しない
- P3を見て条件・候補を変更しない

比較
----
BASE:
    TRIO_TOP1頭固定 + P(2着|頭) 上位最大3艇 + 現行cut

SUJI_ADD:
    穴頭本命と展開候補が対象攻めコースで一致し、1Cが非cutかつ
    BASE 2着Top3外の時だけ1Cを2着候補へ追加。3着候補はBASEのまま。

Usage:
python3 analysis/upset_attack_suji_remaining_complement_validate.py \
  analysis/output/final_prediction_boats_20260615_20260714_OLD.csv \
  analysis/output/final_prediction_boats_20260715_20260814_OLD.csv \
  analysis/output/final_prediction_boats_fast_cached_20260815_20260822.csv \
  analysis/output/kimarite_analysis_dataset_20250815_20260814.csv \
  analysis/output/kimarite_analysis_dataset_20260815_20260822.csv
"""

from __future__ import annotations

import sys
from collections import defaultdict
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import final_prediction_ai_opponent_compare as b2
import final_prediction_ai_bet_integration_compare as b4
import trifecta_probability_order_compare as step3
import upset_probability_validate as c1
import upset_attack_scenario_validate as attack

SCENARIOS = (
    ("SUJI41", 4, 1, "4-1-*"),
    ("SUJI51", 5, 1, "5-1-*"),
)


def pct(n, d):
    return 100.0 * n / d if d else 0.0


def ranked_outer(trio, in_lane):
    return sorted(
        [lane for lane in range(1, 7) if lane != int(in_lane)],
        key=lambda lane: (-float(trio.get(lane, 0.0)), lane),
    )


def lane_for_course(course_by_lane, target_course):
    for lane, course in course_by_lane.items():
        if int(course) == int(target_course):
            return int(lane)
    return 0


def build_row(record, boats, payout, kimarite_row, attacker_course, follow_course):
    if boats is None or set(boats) != set(range(1, 7)):
        return None, "boats_missing"
    if payout is None or int(payout) <= 0:
        return None, "payout_missing"
    if kimarite_row is None:
        return None, "kimarite_missing"

    f = c1.make_features(record, boats)
    if f is None:
        return None, "feature_invalid"

    in_lane = int(f["in_lane"])
    current = int(f["current_head"])
    ai_head = int(f["ai_head"])
    in_win_p = float(f["values"]["in_win_p"])
    if not (ai_head == in_lane and current != in_lane and in_win_p < 0.50):
        return None, "not_high"

    picked = attack.pick_attack(kimarite_row)
    if picked is None:
        return None, "attack_missing"
    if int(picked["course"]) != int(attacker_course):
        return None, "attack_other"

    outer = ranked_outer(f["trio"], in_lane)
    if not outer:
        return None, "trio_invalid"
    trio1 = int(outer[0])

    course_by_lane = {int(k): int(v) for k, v in f["course_by_lane"].items()}
    attack_boat = lane_for_course(course_by_lane, attacker_course)
    follow_boat = lane_for_course(course_by_lane, follow_course)
    if attack_boat <= 0 or follow_boat <= 0:
        return None, "course_map_invalid"

    # 穴頭本命と展開候補が同じ攻めコースのときだけ評価する。
    if trio1 != attack_boat:
        return None, "head_attack_disagree"

    actual = b4.actual_trifecta(boats)
    if actual is None:
        return None, "actual_invalid"
    actual = tuple(int(x) for x in actual)

    base = b4.make_outcome_bets(record, boats, trio1, {}, None)
    if base is None or not base.get("bets"):
        return None, "base_empty"

    base_seconds = list(base["second"])
    base_thirds = list(base["third"])
    cut = set(base["cut"])
    follow_cut = follow_boat in cut
    follow_already = follow_boat in base_seconds
    complement_eligible = (not follow_cut) and (not follow_already)

    suji_seconds = list(base_seconds)
    if complement_eligible:
        suji_seconds.append(follow_boat)
    suji_bets = b4.expand_bets(trio1, suji_seconds, base_thirds)

    actual_course = tuple(course_by_lane.get(x, 0) for x in actual)
    actual_suji = (
        actual_course[0] == int(attacker_course)
        and actual_course[1] == int(follow_course)
    )

    return {
        "race_code": str(record["race_code"]),
        "payout": int(payout),
        "actual": actual,
        "actual_course": actual_course,
        "base_bets": set(base["bets"]),
        "suji_bets": set(suji_bets),
        "base_points": len(base["bets"]),
        "suji_points": len(suji_bets),
        "follow_already": follow_already,
        "follow_cut": follow_cut,
        "complement_eligible": complement_eligible,
        "actual_suji": actual_suji,
    }, "ready"


def build_rows(records, boats_map, payouts, kimarite_map, attacker_course, follow_course):
    rows = []
    skip = defaultdict(int)
    for record in records:
        code = str(record["race_code"])
        row, reason = build_row(
            record,
            boats_map.get(code),
            payouts.get(code),
            kimarite_map.get(code),
            attacker_course,
            follow_course,
        )
        skip[reason] += 1
        if row is not None:
            rows.append(row)
    return rows, skip


def bet_stats(rows, key):
    n = len(rows)
    if not n:
        return {
            "n": 0, "avg_points": 0.0, "hits": 0, "hit_rate": 0.0,
            "roi100": 0.0, "roi_fixed": 0.0,
        }

    points = hits = 0
    invest100 = ret100 = 0.0
    invest_fixed = ret_fixed = 0.0

    for r in rows:
        bets = r[key]
        cnt = len(bets)
        if cnt <= 0:
            continue
        points += cnt
        invest100 += cnt * 100.0
        invest_fixed += 1000.0
        if r["actual"] in bets:
            hits += 1
            payout = float(r["payout"])
            ret100 += payout
            ret_fixed += payout * (1000.0 / (cnt * 100.0))

    return {
        "n": n,
        "avg_points": points / n if n else 0.0,
        "hits": hits,
        "hit_rate": pct(hits, n),
        "roi100": 100.0 * ret100 / invest100 if invest100 else 0.0,
        "roi_fixed": 100.0 * ret_fixed / invest_fixed if invest_fixed else 0.0,
    }


def compare(rows):
    gained = lost = both = 0
    for r in rows:
        b = r["actual"] in r["base_bets"]
        s = r["actual"] in r["suji_bets"]
        if s and not b:
            gained += 1
        elif b and not s:
            lost += 1
        elif b and s:
            both += 1
    return gained, lost, both


def print_period(title, rows, skip, attacker_course, follow_course, label):
    print("\n" + "=" * 122)
    print(
        f"【{title} / {label}】 穴頭{attacker_course}C=展開候補{attacker_course}C "
        f"一致R={len(rows)}"
    )
    print("=" * 122)
    if not rows:
        print("対象なし")
        print("skip:", dict(skip))
        return

    already = sum(r["follow_already"] for r in rows)
    cut = sum(r["follow_cut"] for r in rows)
    eligible = sum(r["complement_eligible"] for r in rows)
    actual_suji = sum(r["actual_suji"] for r in rows)
    actual_suji_base_miss = sum(
        r["actual_suji"] and r["actual"] not in r["base_bets"] for r in rows
    )

    print(
        f"{follow_course}Cの現状: OUTCOME 2着Top3内={already}/{len(rows)} ({pct(already,len(rows)):.2f}%) / "
        f"cut={cut}/{len(rows)} ({pct(cut,len(rows)):.2f}%) / "
        f"追加可能={eligible}/{len(rows)} ({pct(eligible,len(rows)):.2f}%)"
    )
    print(
        f"実{label}={actual_suji}/{len(rows)} ({pct(actual_suji,len(rows)):.2f}%) / "
        f"うちBASE取りこぼし={actual_suji_base_miss}"
    )

    base = bet_stats(rows, "base_bets")
    suji = bet_stats(rows, "suji_bets")
    gain, lost, both = compare(rows)

    print("\n方式              平均点数   的中率   100円/点ROI   1000円均等ROI")
    print("-" * 78)
    print(
        f"BASE              {base['avg_points']:>7.2f}  {base['hit_rate']:>7.2f}%"
        f"      {base['roi100']:>8.2f}%        {base['roi_fixed']:>8.2f}%"
    )
    print(
        f"SUJI_ADD          {suji['avg_points']:>7.2f}  {suji['hit_rate']:>7.2f}%"
        f"      {suji['roi100']:>8.2f}%        {suji['roi_fixed']:>8.2f}%"
    )
    print(
        f"差                {suji['avg_points']-base['avg_points']:+7.2f}  "
        f"{suji['hit_rate']-base['hit_rate']:+7.2f}pt"
        f"      {suji['roi100']-base['roi100']:+8.2f}pt       "
        f"{suji['roi_fixed']-base['roi_fixed']:+8.2f}pt"
    )
    print(f"拾い={gain} / 失い={lost} / 両方的中={both}")
    print("skip参考:", dict(skip))


def main():
    if len(sys.argv) != 6:
        print(
            "Usage: python3 analysis/upset_attack_suji_remaining_complement_validate.py "
            "P1_BOATS P2_BOATS P3_BOATS TRAIN_KIMARITE P3_KIMARITE",
            file=sys.stderr,
        )
        sys.exit(1)

    p1, p2, p3, train_k, p3_k = sys.argv[1:]

    print("4-1-* / 5-1-* と120通り穴ヒモの補完を固定条件で検証中...", flush=True)
    train = step3.build_common_records(p1, p2)
    future = step3.build_common_records(p2, p3)
    boats_map = b2.load_boats(p1, p2, p3)
    train_k_map = attack.load_kimarite(train_k)
    p3_k_map = attack.load_kimarite(p3_k)

    p1_payouts = b4.load_payouts(train["p1_start"], train["p1_end"])
    p2_payouts = b4.load_payouts(train["p2_start"], train["p2_end"])
    p3_payouts = b4.load_payouts(future["p2_start"], future["p2_end"])

    print("=" * 122)
    print("穴目予想：残りの展開連動すじ × 120通り穴ヒモTop3 補完 P1/P2/P3")
    print("=" * 122)
    print("固定: HIGH / TRIO_TOP1 / 現行展開候補 / 現行cut維持")
    print("候補: 4C→4-1-* と 5C→5-1-* の2本を同時に固定検証")
    print("本番変更: なし")

    periods = (
        ("P1", train["records"]["P1"], p1_payouts, train_k_map),
        ("P2", train["records"]["P2"], p2_payouts, train_k_map),
        ("P3完全未来", future["records"]["P2"], p3_payouts, p3_k_map),
    )

    for _sid, attacker_course, follow_course, label in SCENARIOS:
        print("\n" + "#" * 122)
        print(f"# 固定候補 {label}")
        print("#" * 122)
        for title, records, payouts, kmap in periods:
            rows, skip = build_rows(
                records,
                boats_map,
                payouts,
                kmap,
                attacker_course,
                follow_course,
            )
            print_period(
                title, rows, skip, attacker_course, follow_course, label
            )

    print("\n【判断ポイント】")
    print("1. 4-1-* / 5-1-* を同じ固定方式でP1/P2/P3評価する")
    print("2. 新規的中が複数期間で再現し、点数増に対してROIも維持・改善するか")
    print("3. P3を見て候補や条件を追加・変更しない")
    print("4. 通らなければ、すじ形成は表示参考に留め120通りヒモへ追加しない")
    print("=" * 122)


if __name__ == "__main__":
    main()
