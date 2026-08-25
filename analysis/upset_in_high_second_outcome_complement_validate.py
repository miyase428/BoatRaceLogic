#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
穴目予想：①残り「高」を、120通り穴ヒモTop3の2着候補へ追加する価値を固定条件で前方検証する。

固定条件
--------
- HIGH: AI本命=1C / CURRENT本命!=1C / イン補正後1着率<50%
- ①残り高: インAI3連対率 >=70%（既存表示ルール固定）
- 穴頭本命: TRIO_TOP1（イン以外AI3連対率1位）
- BASE: TRIO_TOP1頭固定 + P(2着|穴頭)上位最大3艇 + 現行cut
- 現行cutは維持。イン艇がcutなら救済しない
- P3を見て条件・閾値は変更しない

比較
----
BASE
    現在の120通り由来穴ヒモ候補。

IN_HIGH_ADD
    ①残り高のレースだけ、イン艇が非cutかつBASE 2着Top3外なら
    2着候補へ追加する。3着候補はBASEのまま。

重要
----
- 実際にインが負けたか、穴頭が当たったかでは絞らない。購入可能な事前条件だけで評価する。
- actual着順は評価ラベルにのみ使う。
- 本番Web/PredictionLogicは変更しない。

Usage:
python3 analysis/upset_in_high_second_outcome_complement_validate.py \
  analysis/output/final_prediction_boats_20260615_20260714_OLD.csv \
  analysis/output/final_prediction_boats_20260715_20260814_OLD.csv \
  analysis/output/final_prediction_boats_fast_cached_20260815_20260822.csv
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


def pct(n, d):
    return 100.0 * n / d if d else 0.0


def ranked_outer(trio, in_lane):
    return sorted(
        [lane for lane in range(1, 7) if lane != int(in_lane)],
        key=lambda lane: (-float(trio.get(lane, 0.0)), int(lane)),
    )


def build_row(record, boats, payout):
    if boats is None or set(boats) != set(range(1, 7)):
        return None, "boats_missing"
    if payout is None or int(payout) <= 0:
        return None, "payout_missing"

    f = c1.make_features(record, boats)
    if f is None:
        return None, "feature_invalid"

    in_lane = int(f["in_lane"])
    current = int(f["current_head"])
    ai_head = int(f["ai_head"])
    in_win_p = float(f["values"]["in_win_p"])

    # C2固定HIGH。
    if not (ai_head == in_lane and current != in_lane and in_win_p < 0.50):
        return None, "not_high"

    # 既存①残り表示の「高」だけを事前条件として固定。
    in_trio_p = float(f["trio"].get(in_lane, 0.0))
    if in_trio_p < 0.70:
        return None, "remain_not_high"

    outer = ranked_outer(f["trio"], in_lane)
    if not outer:
        return None, "trio_invalid"
    trio1 = int(outer[0])

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

    in_already = in_lane in base_seconds
    in_cut = in_lane in cut
    add_eligible = (not in_cut) and (not in_already)

    add_seconds = list(base_seconds)
    if add_eligible:
        add_seconds.append(in_lane)
    add_bets = b4.expand_bets(trio1, add_seconds, base_thirds)

    actual_in_second = actual[1] == in_lane
    actual_head_trio1 = actual[0] == trio1
    target_exact = actual_head_trio1 and actual_in_second

    return {
        "race_code": str(record["race_code"]),
        "payout": int(payout),
        "actual": actual,
        "in_lane": in_lane,
        "trio1": trio1,
        "in_trio_p": in_trio_p,
        "base_bets": set(base["bets"]),
        "add_bets": set(add_bets),
        "in_already": in_already,
        "in_cut": in_cut,
        "add_eligible": add_eligible,
        "actual_in_second": actual_in_second,
        "actual_head_trio1": actual_head_trio1,
        "target_exact": target_exact,
    }, "ready"


def build_rows(records, boats_map, payouts):
    rows = []
    skip = defaultdict(int)
    for record in records:
        code = str(record["race_code"])
        row, reason = build_row(record, boats_map.get(code), payouts.get(code))
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
        base_hit = r["actual"] in r["base_bets"]
        add_hit = r["actual"] in r["add_bets"]
        if add_hit and not base_hit:
            gained += 1
        elif base_hit and not add_hit:
            lost += 1
        elif base_hit and add_hit:
            both += 1
    return gained, lost, both


def print_period(title, rows, skip):
    print("\n" + "=" * 122)
    print(f"【{title}】 HIGHかつ①残り高={len(rows)}R")
    print("=" * 122)
    if not rows:
        print("対象なし")
        print("skip参考:", dict(skip))
        return

    already = sum(r["in_already"] for r in rows)
    cut = sum(r["in_cut"] for r in rows)
    eligible = sum(r["add_eligible"] for r in rows)
    actual_second = sum(r["actual_in_second"] for r in rows)
    head_hit = sum(r["actual_head_trio1"] for r in rows)
    target = sum(r["target_exact"] for r in rows)
    target_base_miss = sum(
        r["target_exact"] and r["actual"] not in r["base_bets"]
        for r in rows
    )

    print(
        f"①の現状: OUTCOME 2着Top3内={already}/{len(rows)} ({pct(already,len(rows)):.2f}%) / "
        f"cut={cut}/{len(rows)} ({pct(cut,len(rows)):.2f}%) / "
        f"追加可能={eligible}/{len(rows)} ({pct(eligible,len(rows)):.2f}%)"
    )
    print(
        f"実①2着={actual_second}/{len(rows)} ({pct(actual_second,len(rows)):.2f}%) / "
        f"実穴頭TRIO1={head_hit}/{len(rows)} ({pct(head_hit,len(rows)):.2f}%) / "
        f"実TRIO1-①-*={target}/{len(rows)} ({pct(target,len(rows)):.2f}%)"
    )
    print(f"実TRIO1-①-* のうちBASE取りこぼし={target_base_miss}")

    base = bet_stats(rows, "base_bets")
    add = bet_stats(rows, "add_bets")
    gain, lost, both = compare(rows)

    print("\n方式              平均点数   的中率   100円/点ROI   1000円均等ROI")
    print("-" * 78)
    print(
        f"BASE              {base['avg_points']:>7.2f}  {base['hit_rate']:>7.2f}%"
        f"      {base['roi100']:>8.2f}%        {base['roi_fixed']:>8.2f}%"
    )
    print(
        f"IN_HIGH_ADD       {add['avg_points']:>7.2f}  {add['hit_rate']:>7.2f}%"
        f"      {add['roi100']:>8.2f}%        {add['roi_fixed']:>8.2f}%"
    )
    print(
        f"差                {add['avg_points']-base['avg_points']:+7.2f}  "
        f"{add['hit_rate']-base['hit_rate']:+7.2f}pt"
        f"      {add['roi100']-base['roi100']:+8.2f}pt       "
        f"{add['roi_fixed']-base['roi_fixed']:+8.2f}pt"
    )
    print(f"拾い={gain} / 失い={lost} / 両方的中={both}")
    print("skip参考:", dict(skip))


def main():
    if len(sys.argv) != 4:
        print(
            "Usage: python3 analysis/upset_in_high_second_outcome_complement_validate.py "
            "P1_BOATS P2_BOATS P3_BOATS",
            file=sys.stderr,
        )
        sys.exit(1)

    p1, p2, p3 = sys.argv[1:]

    print("①残り高と120通り穴ヒモTop3の2着補完を固定条件で検証中...", flush=True)
    train = step3.build_common_records(p1, p2)
    future = step3.build_common_records(p2, p3)
    boats_map = b2.load_boats(p1, p2, p3)
    payouts = b4.load_payouts(train["p1_start"], future["p2_end"])

    p1_rows, p1_skip = build_rows(train["records"]["P1"], boats_map, payouts)
    p2_rows, p2_skip = build_rows(train["records"]["P2"], boats_map, payouts)
    p3_rows, p3_skip = build_rows(future["records"]["P2"], boats_map, payouts)

    print("=" * 122)
    print("穴目予想：①残り高 × 120通り穴ヒモTop3 2着補完 P1/P2/P3")
    print("=" * 122)
    print("固定: HIGH / ①残り高(インAI3連対>=70%) / TRIO_TOP1 / 現行cut維持")
    print("追加: ①が非cutかつP(2着|穴頭) Top3外のときだけ2着候補へ追加")
    print("実イン敗戦・実穴頭的中では絞らない。レース前条件だけで評価")
    print("本番変更: なし")

    print_period("P1", p1_rows, p1_skip)
    print_period("P2", p2_rows, p2_skip)
    print_period("P3完全未来", p3_rows, p3_skip)

    print("\n【判断ポイント】")
    print("1. ①残り高の①がすでにOUTCOME 2着Top3へ十分入っているか")
    print("2. IN_HIGH_ADDがP1/P2/P3で新規的中を拾うか")
    print("3. 点数増に対して1000円均等ROIが維持・改善するか")
    print("4. 現行cutは救済しない")
    print("5. P3を見て条件・閾値を変更しない")
    print("=" * 122)


if __name__ == "__main__":
    main()
