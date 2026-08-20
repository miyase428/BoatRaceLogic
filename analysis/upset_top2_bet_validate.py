#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
STEP C4：穴候補Top2 + 穴買い目実用性検証

目的
----
C2で固定した穴警戒HIGH

  AI本命 = 1C
  CURRENT本命 != 1C
  インAI1着率 < 50%

かつ、C3で最良だった TRIO_OUTER を穴頭候補Top1として固定し、

  1. 穴頭候補をTop2へ広げる価値があるか
  2. HIGHレースだけ穴頭買いをした場合、的中率/点数/ROIがどうなるか

をTRAIN/P3完全未来で確認する。

穴頭候補
--------
TRIO_TOP1
    イン以外でAI3連対率1位。

TRIO_TOP2
    イン以外でAI3連対率上位2艇。

TRIO1_PLUS_CURRENT
    TRIO_TOP1 + CURRENT本命（同一なら1艇）。

買い目
------
CURRENT
    現行本命買い目。

CURRENT_OUTCOME
    CURRENT本命を頭に固定し、STEP3 P(2着|頭) 上位最大3艇を2着候補。
    cutは現行固定。

TRIO1_OUTCOME
    TRIO_TOP1を頭に固定し、相手は同じOUTCOME方式。

TRIO_TOP2_OUTCOME
    TRIO_TOP2の各艇を頭にしてOUTCOME買い目を作り、和集合。

TRIO1_CURRENT_OUTCOME
    TRIO_TOP1とCURRENT本命を頭にしてOUTCOME買い目を作り、和集合。

投資評価
--------
- 100円/点
- HIGH 1Rあたり1000円均等（全買い目へ均等配分する理論値）

重要
----
- HIGH条件、TRIO_OUTER選択はC2/C3から固定。P3で再調整しない。
- オッズによる購入選別はまだしない。
- 本番Web/PredictionLogicは変更しない。

Usage:
python3 analysis/upset_top2_bet_validate.py \
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


HEAD_METHODS = (
    "TRIO_TOP1",
    "TRIO_TOP2",
    "TRIO1_PLUS_CURRENT",
)

BET_METHODS = (
    "CURRENT",
    "CURRENT_OUTCOME",
    "TRIO1_OUTCOME",
    "TRIO_TOP2_OUTCOME",
    "TRIO1_CURRENT_OUTCOME",
)


def ranked_outer(values, in_lane):
    eligible = [lane for lane in range(1, 7) if lane != int(in_lane)]
    return sorted(
        eligible,
        key=lambda lane: (-float(values.get(lane, 0.0)), int(lane)),
    )


def make_outcome_union(record, boats, heads):
    bets = set()
    valid_heads = []
    for head in dict.fromkeys(int(h) for h in heads):
        s = b4.make_outcome_bets(record, boats, head, {}, None)
        if s is None or not s.get("bets"):
            continue
        valid_heads.append(head)
        bets |= set(s["bets"])
    if not bets:
        return None
    return {
        "heads": tuple(valid_heads),
        "bets": bets,
    }


def build_row(record, boats, payout):
    if boats is None or set(boats) != set(range(1, 7)):
        return None
    if payout is None or int(payout) <= 0:
        return None

    f = c1.make_features(record, boats)
    if f is None:
        return None

    current = int(f["current_head"])
    ai_head = int(f["ai_head"])
    in_lane = int(f["in_lane"])
    in_win_p = float(f["values"]["in_win_p"])

    # C2で固定したHIGH条件。
    if not (ai_head == in_lane and current != in_lane and in_win_p < 0.50):
        return None

    actual = b4.actual_trifecta(boats)
    if actual is None:
        return None
    actual_first = int(actual[0])

    trio_rank = ranked_outer(f["trio"], in_lane)
    if len(trio_rank) < 2:
        return None
    trio1, trio2 = int(trio_rank[0]), int(trio_rank[1])

    head_sets = {
        "TRIO_TOP1": (trio1,),
        "TRIO_TOP2": (trio1, trio2),
        "TRIO1_PLUS_CURRENT": tuple(dict.fromkeys((trio1, current))),
    }

    current_bets = b4.current_bets(boats)
    if current_bets is None or not current_bets.get("bets"):
        return None

    current_outcome = make_outcome_union(record, boats, (current,))
    trio1_outcome = make_outcome_union(record, boats, (trio1,))
    trio2_outcome = make_outcome_union(record, boats, (trio1, trio2))
    trio_current_outcome = make_outcome_union(record, boats, (trio1, current))
    if None in (current_outcome, trio1_outcome, trio2_outcome, trio_current_outcome):
        return None

    scenarios = {
        "CURRENT": {"heads": (current,), "bets": set(current_bets["bets"])},
        "CURRENT_OUTCOME": current_outcome,
        "TRIO1_OUTCOME": trio1_outcome,
        "TRIO_TOP2_OUTCOME": trio2_outcome,
        "TRIO1_CURRENT_OUTCOME": trio_current_outcome,
    }

    return {
        "race_code": str(record["race_code"]),
        "payout": int(payout),
        "actual": tuple(int(x) for x in actual),
        "actual_first": actual_first,
        "in_lane": in_lane,
        "in_failed": actual_first != in_lane,
        "head_sets": head_sets,
        "scenarios": scenarios,
        "course_by_lane": f["course_by_lane"],
        "trio": f["trio"],
        "current": current,
        "trio1": trio1,
        "trio2": trio2,
    }


def build_rows(records, boats_map, payouts):
    rows = []
    skip = defaultdict(int)
    for record in records:
        code = str(record["race_code"])
        row = build_row(record, boats_map.get(code), payouts.get(code))
        if row is None:
            skip["not_ready_or_not_high"] += 1
            continue
        rows.append(row)
        skip["ready_high"] += 1
    return rows, skip


def evaluate_heads(rows, method):
    n = len(rows)
    captured_all = 0
    fail_n = 0
    captured_fail = 0
    avg_candidates = 0.0
    course_counts = Counter()

    for r in rows:
        heads = tuple(r["head_sets"][method])
        avg_candidates += len(heads)
        if r["actual_first"] in heads:
            captured_all += 1
        if r["in_failed"]:
            fail_n += 1
            if r["actual_first"] in heads:
                captured_fail += 1
        for h in heads:
            c = int(r["course_by_lane"].get(int(h), 0))
            course_counts[c] += 1

    return {
        "n": n,
        "avg_candidates": avg_candidates / n if n else 0.0,
        "capture_all": captured_all / n if n else 0.0,
        "fail_n": fail_n,
        "capture_fail_n": captured_fail,
        "capture_fail": captured_fail / fail_n if fail_n else 0.0,
        "course_counts": course_counts,
    }


def evaluate_bets(rows, method):
    n = hits = points = 0
    invest100 = ret100 = 0.0
    invest_fixed = ret_fixed = 0.0
    hit_payout_sum = 0.0

    for r in rows:
        bets = r["scenarios"][method]["bets"]
        cnt = len(bets)
        if cnt <= 0:
            continue
        n += 1
        points += cnt
        invest100 += cnt * 100.0
        invest_fixed += 1000.0

        if r["actual"] in bets:
            hits += 1
            payout = float(r["payout"])
            hit_payout_sum += payout
            ret100 += payout
            ret_fixed += payout * ((1000.0 / cnt) / 100.0)

    return {
        "n": n,
        "hits": hits,
        "hit_rate": hits / n if n else 0.0,
        "avg_points": points / n if n else 0.0,
        "roi100": ret100 / invest100 if invest100 else 0.0,
        "roi_fixed": ret_fixed / invest_fixed if invest_fixed else 0.0,
        "ret100": ret100,
        "invest100": invest100,
        "avg_hit_payout": hit_payout_sum / hits if hits else 0.0,
    }


def compare_bets(rows, base_method, new_method):
    changed = gained = lost = both = neither = 0
    for r in rows:
        base_bets = r["scenarios"][base_method]["bets"]
        new_bets = r["scenarios"][new_method]["bets"]
        if base_bets != new_bets:
            changed += 1
        bh = r["actual"] in base_bets
        nh = r["actual"] in new_bets
        if nh and not bh:
            gained += 1
        elif bh and not nh:
            lost += 1
        elif bh and nh:
            both += 1
        else:
            neither += 1
    return {
        "changed": changed,
        "gained": gained,
        "lost": lost,
        "both": both,
        "neither": neither,
    }


def print_head_section(title, rows):
    print(f"\n【{title}：穴頭候補数と頭捕捉】")
    print("方式                    平均候補数   HIGH全体頭捕捉   1C敗退R   1C敗退時頭捕捉")
    print("-" * 92)
    results = {}
    for method in HEAD_METHODS:
        m = evaluate_heads(rows, method)
        results[method] = m
        print(
            f"{method:<24} {m['avg_candidates']:>8.2f}艇   "
            f"{m['capture_all']*100:>8.2f}%   {m['fail_n']:>7d}   "
            f"{m['capture_fail_n']:>4d}/{m['fail_n']:<4d} {m['capture_fail']*100:>7.2f}%"
        )
    return results


def print_bet_section(title, rows):
    print(f"\n【{title}：HIGH限定3連単買い目】")
    print("方式                         R数   平均点数   的中率   100円/点ROI   1000円均等ROI   的中平均払戻")
    print("-" * 112)
    results = {}
    for method in BET_METHODS:
        m = evaluate_bets(rows, method)
        results[method] = m
        print(
            f"{method:<28} {m['n']:>5d}   {m['avg_points']:>7.2f}   "
            f"{m['hit_rate']*100:>6.2f}%     {m['roi100']*100:>8.2f}%       "
            f"{m['roi_fixed']*100:>8.2f}%       {m['avg_hit_payout']:>8.0f}円"
        )

    print("\nCURRENTからの的中差")
    print("方式                         買目変更   拾い   失い   両方的中")
    print("-" * 74)
    for method in BET_METHODS:
        if method == "CURRENT":
            continue
        c = compare_bets(rows, "CURRENT", method)
        print(
            f"{method:<28} {c['changed']:>8d}   {c['gained']:>4d}   "
            f"{c['lost']:>4d}   {c['both']:>8d}"
        )
    return results


def print_fixed_summary(train_h, p3_h, train_b, p3_b):
    print("\n【C3固定TRIO_TOP1の再現 + Top2の追加効果】")
    print("指標                                  TRAIN        P3")
    print("-" * 70)
    print(
        f"TRIO_TOP1 1C敗退時頭捕捉          "
        f"{train_h['TRIO_TOP1']['capture_fail']*100:>7.2f}%   "
        f"{p3_h['TRIO_TOP1']['capture_fail']*100:>7.2f}%"
    )
    print(
        f"TRIO_TOP2 1C敗退時頭捕捉          "
        f"{train_h['TRIO_TOP2']['capture_fail']*100:>7.2f}%   "
        f"{p3_h['TRIO_TOP2']['capture_fail']*100:>7.2f}%"
    )
    print(
        f"Top2追加による頭捕捉差             "
        f"{(train_h['TRIO_TOP2']['capture_fail']-train_h['TRIO_TOP1']['capture_fail'])*100:+7.2f}pt   "
        f"{(p3_h['TRIO_TOP2']['capture_fail']-p3_h['TRIO_TOP1']['capture_fail'])*100:+7.2f}pt"
    )
    print(
        f"TRIO1_OUTCOME 1000円均等ROI         "
        f"{train_b['TRIO1_OUTCOME']['roi_fixed']*100:>7.2f}%   "
        f"{p3_b['TRIO1_OUTCOME']['roi_fixed']*100:>7.2f}%"
    )
    print(
        f"TRIO_TOP2_OUTCOME 1000円均等ROI     "
        f"{train_b['TRIO_TOP2_OUTCOME']['roi_fixed']*100:>7.2f}%   "
        f"{p3_b['TRIO_TOP2_OUTCOME']['roi_fixed']*100:>7.2f}%"
    )


def main():
    if len(sys.argv) != 4:
        print("Usage: python3 analysis/upset_top2_bet_validate.py P1_BOATS_CSV P2_BOATS_CSV P3_BOATS_CSV")
        sys.exit(1)

    p1_csv, p2_csv, p3_csv = sys.argv[1], sys.argv[2], sys.argv[3]

    print("C2/C3の条件を固定し、穴候補Top2とHIGH限定買い目を再構築中...")
    train_data = step3.build_common_records(p1_csv, p2_csv)
    future_data = step3.build_common_records(p2_csv, p3_csv)

    p1_records = train_data["records"]["P1"]
    p2_records = train_data["records"]["P2"]
    p3_records = future_data["records"]["P2"]

    boats_map = b2.load_boats(p1_csv, p2_csv, p3_csv)
    payouts = b4.load_payouts(train_data["p1_start"], future_data["p2_end"])

    p1_rows, _ = build_rows(p1_records, boats_map, payouts)
    p2_rows, _ = build_rows(p2_records, boats_map, payouts)
    p3_rows, _ = build_rows(p3_records, boats_map, payouts)
    train_rows = p1_rows + p2_rows

    print("=" * 126)
    print("STEP C4：穴候補Top2 + 穴買い目実用性検証")
    print("=" * 126)
    print(f"TRAIN : {train_data['p1_start']} ～ {train_data['p2_end']}")
    print(f"P3    : {future_data['p2_start']} ～ {future_data['p2_end']} 完全未来")
    print(f"HIGH対象: TRAIN={len(train_rows)}R / P3={len(p3_rows)}R")
    print("HIGH条件: AI本命=1C & CURRENT本命!=1C & インAI1着率<50%（固定）")
    print("穴Top1 : TRIO_OUTER（C3固定）")
    print("相手   : OUTCOME P(2着|穴頭) 上位最大3艇 / 現行cut固定")
    print("本番Web変更: なし")

    train_h = print_head_section("TRAIN", train_rows)
    p3_h = print_head_section("P3完全未来（最重要）", p3_rows)
    train_b = print_bet_section("TRAIN", train_rows)
    p3_b = print_bet_section("P3完全未来（最重要）", p3_rows)
    print_fixed_summary(train_h, p3_h, train_b, p3_b)

    print("\n【判断方針】")
    print("1. 穴頭候補1艇の初版はC3どおりTRIO_TOP1を固定する")
    print("2. Top2で1C敗退時の頭捕捉が大きく増えるか、候補2艇化の価値を見る")
    print("3. 穴買い目はP3でも的中率だけでなくROIが維持されるかを見る")
    print("4. Top2で的中率だけ上がりROIが落ちるなら、Web表示はTop1中心のままにする")
    print("5. CURRENT併用が高配当を拾い戻すなら、穴保険として別枠表示を検討する")
    print("6. ここでもオッズ選別は入れていない。次段階で必要なら期待値/購入条件へ進む")
    print("7. この段階では本番Web/PredictionLogicへは組み込まない")
    print("=" * 126)


if __name__ == "__main__":
    main()
