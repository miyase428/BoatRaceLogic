#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
穴目予想：穴頭Top1が実際に1着だったレースで、2着・3着構造を直接検証する。

目的
----
C2/C3で固定したHIGH + TRIO_TOP1をそのまま使い、
「穴頭が当たった後のヒモ」を120通りSTEP3確率でどこまで拾えるかを見る。

評価
----
1. TRIO_TOP1を頭へ固定した現行順位ベースの相手構成
2. TRIO_TOP1を頭へ固定したOUTCOME P(2着|頭)方式
3. STEP3の同一頭20通りを直接順位付けしたときの実2-3着ペア順位
4. 現行cutが実2・3着を遮断している割合

重要
----
- 対象は「HIGHかつTRIO_TOP1が実際に1着だったレース」という診断母集団。
- ここでの的中率は購入成績ではなく、ヒモ選定能力の診断値。
- P3を見て閾値・点数・cut条件を変更しない。
- すじ舟券/直内直外連動は別テーマとして後で扱う。
- 本番Web/PredictionLogicは変更しない。

Usage:
python3 analysis/upset_head_hit_himo_structure_validate.py \
  analysis/output/final_prediction_boats_20260615_20260714_OLD.csv \
  analysis/output/final_prediction_boats_20260715_20260814_OLD.csv \
  analysis/output/final_prediction_boats_fast_cached_20260815_20260822.csv
"""

from __future__ import annotations

import statistics
import sys
from collections import defaultdict
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import final_prediction_ai_opponent_compare as b2
import final_prediction_ai_bet_integration_compare as b4
import trifecta_probability_order_compare as step3
import upset_probability_validate as c1

ORDER_DELTA = 0.25
ORDER_GAMMA = 0.25
TOP_KS = (1, 3, 5, 10)


def ranked_outer(trio, in_lane):
    return sorted(
        [lane for lane in range(1, 7) if lane != int(in_lane)],
        key=lambda lane: (-float(trio.get(lane, 0.0)), lane),
    )


def ranked_pairs(record, head, allowed=None):
    probs = step3.order_adjusted_probs(record, ORDER_DELTA, ORDER_GAMMA)
    rows = []
    allowed_set = set(allowed) if allowed is not None else None

    for idx, lanes in enumerate(record["pattern_lanes"]):
        h, s, t = (int(lanes[0]), int(lanes[1]), int(lanes[2]))
        if h != int(head):
            continue
        if allowed_set is not None and (s not in allowed_set or t not in allowed_set):
            continue
        rows.append(((s, t), float(probs[idx])))

    rows.sort(key=lambda x: (-x[1], x[0][0], x[0][1]))
    return rows


def pair_rank(ranked, actual_pair):
    for i, (pair, _p) in enumerate(ranked, start=1):
        if tuple(pair) == tuple(actual_pair):
            return i
    return 0


def build_rows(records, boats_map):
    rows = []
    skip = defaultdict(int)

    for record in records:
        code = str(record["race_code"])
        boats = boats_map.get(code)
        if boats is None or set(boats) != set(range(1, 7)):
            skip["csv_missing"] += 1
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
        skip["high"] += 1

        actual = b4.actual_trifecta(boats)
        if actual is None:
            skip["actual_invalid"] += 1
            continue
        actual = tuple(int(x) for x in actual)

        outer = ranked_outer(f["trio"], in_lane)
        if not outer:
            skip["trio_invalid"] += 1
            continue
        trio1 = int(outer[0])

        if actual[0] != trio1:
            skip["trio1_not_winner"] += 1
            continue

        current_like = b4.make_win_head_bets(boats, trio1)
        outcome = b4.make_outcome_bets(record, boats, trio1, {}, None)
        if current_like is None or outcome is None:
            skip["bet_invalid"] += 1
            continue

        cut = b4.current_cut(boats)
        cut.discard(trio1)
        allowed = [lane for lane in range(1, 7) if lane != trio1 and lane not in cut]

        actual_pair = (actual[1], actual[2])
        unrestricted = ranked_pairs(record, trio1, None)
        constrained = ranked_pairs(record, trio1, allowed)

        rows.append({
            "race_code": code,
            "actual": actual,
            "trio1": trio1,
            "actual_pair": actual_pair,
            "cut": set(cut),
            "actual_has_cut": actual[1] in cut or actual[2] in cut,
            "current_like_points": len(current_like["bets"]),
            "outcome_points": len(outcome["bets"]),
            "current_like_hit": actual in current_like["bets"],
            "outcome_hit": actual in outcome["bets"],
            "rank_all": pair_rank(unrestricted, actual_pair),
            "rank_nocut": pair_rank(constrained, actual_pair),
        })
        skip["ready_head_hit"] += 1

    return rows, skip


def pct(n, d):
    return 100.0 * n / d if d else 0.0


def rank_summary(rows, key):
    ranks = [int(r[key]) for r in rows if int(r[key]) > 0]
    n = len(rows)
    valid = len(ranks)
    out = {
        "n": n,
        "valid": valid,
        "mean": (sum(ranks) / valid) if valid else 0.0,
        "median": statistics.median(ranks) if ranks else 0.0,
    }
    for k in TOP_KS:
        out[f"top{k}"] = sum(1 for x in ranks if x <= k)
    return out


def compare_hits(rows):
    gained = lost = both = neither = 0
    for r in rows:
        c = bool(r["current_like_hit"])
        o = bool(r["outcome_hit"])
        if o and not c:
            gained += 1
        elif c and not o:
            lost += 1
        elif c and o:
            both += 1
        else:
            neither += 1
    return gained, lost, both, neither


def print_period(title, rows, skip):
    n = len(rows)
    high_n = int(skip.get("high", 0))
    print(f"\n【{title}】")
    print(
        f"HIGH={high_n}R / TRIO_TOP1実頭で診断可能={n}R "
        f"({pct(n, high_n):.2f}% of HIGH)"
    )

    if not rows:
        return

    cur_hit = sum(1 for r in rows if r["current_like_hit"])
    out_hit = sum(1 for r in rows if r["outcome_hit"])
    cur_pts = sum(r["current_like_points"] for r in rows) / n
    out_pts = sum(r["outcome_points"] for r in rows) / n
    gain, lost, both, neither = compare_hits(rows)

    print("\nTRIO_TOP1頭固定時のヒモ形成")
    print("方式                    平均点数   ヒモ完全一致   CURRENT順位方式との差")
    print("-" * 76)
    print(f"CURRENT_RANK_AITE        {cur_pts:>7.2f}    {cur_hit:>4d}/{n:<4d} {pct(cur_hit,n):>7.2f}%      基準")
    print(
        f"OUTCOME_SECOND_AITE      {out_pts:>7.2f}    {out_hit:>4d}/{n:<4d} {pct(out_hit,n):>7.2f}%      "
        f"拾い={gain} / 失い={lost} / 差={pct(out_hit-cur_hit,n):+.2f}pt"
    )

    cut_n = sum(1 for r in rows if r["actual_has_cut"])
    print(f"\n実2・3着のどちらかが現行cut: {cut_n}/{n} = {pct(cut_n,n):.2f}%")

    all_s = rank_summary(rows, "rank_all")
    nocut_rows = [r for r in rows if not r["actual_has_cut"]]
    nocut_s = rank_summary(nocut_rows, "rank_nocut")

    print("\nSTEP3 120通り：TRIO_TOP1頭固定後の実2-3着ペア順位")
    print("母集団              R数   Top1    Top3    Top5    Top10   平均順位  中央順位")
    print("-" * 82)
    print(
        f"cut無視20通り      {all_s['n']:>4d}  "
        f"{pct(all_s['top1'],all_s['n']):>6.2f}%  {pct(all_s['top3'],all_s['n']):>6.2f}%  "
        f"{pct(all_s['top5'],all_s['n']):>6.2f}%  {pct(all_s['top10'],all_s['n']):>6.2f}%  "
        f"{all_s['mean']:>8.2f}  {all_s['median']:>8.1f}"
    )
    print(
        f"実ヒモ非cutのみ    {nocut_s['n']:>4d}  "
        f"{pct(nocut_s['top1'],nocut_s['n']):>6.2f}%  {pct(nocut_s['top3'],nocut_s['n']):>6.2f}%  "
        f"{pct(nocut_s['top5'],nocut_s['n']):>6.2f}%  {pct(nocut_s['top10'],nocut_s['n']):>6.2f}%  "
        f"{nocut_s['mean']:>8.2f}  {nocut_s['median']:>8.1f}"
    )


def main():
    if len(sys.argv) != 4:
        print("Usage: python3 analysis/upset_head_hit_himo_structure_validate.py P1 P2 P3")
        sys.exit(1)

    p1, p2, p3 = sys.argv[1:]

    print("STEP3共通データを再構築中...", flush=True)
    train = step3.build_common_records(p1, p2)
    print("P1/P2構築完了。P3前方データを再構築中...", flush=True)
    future = step3.build_common_records(p2, p3)
    print("共通データ構築完了。穴頭的中時のヒモ構造を集計中...", flush=True)

    boats_map = b2.load_boats(p1, p2, p3)

    p1_rows, p1_skip = build_rows(train["records"]["P1"], boats_map)
    p2_rows, p2_skip = build_rows(train["records"]["P2"], boats_map)
    p3_rows, p3_skip = build_rows(future["records"]["P2"], boats_map)

    print("=" * 118)
    print("穴目予想：穴頭Top1的中時の2着・3着ヒモ構造 P1/P2/P3")
    print("=" * 118)
    print(f"P1 : {train['p1_start']} ～ {train['p1_end']}")
    print(f"P2 : {train['p2_start']} ～ {train['p2_end']}")
    print(f"P3 : {future['p2_start']} ～ {future['p2_end']} 完全未来")
    print("HIGH : AI本命=1C / CURRENT本命!=1C / イン補正後1着率<50%（固定）")
    print("頭   : TRIO_TOP1固定。実際にその艇が1着だったレースだけでヒモ能力を診断")
    print("STEP3: alpha=1.00 / beta=1.25 / delta=0.25 / gamma=0.25 固定")
    print("本番Web/PredictionLogic変更: なし")

    print_period("P1", p1_rows, p1_skip)
    print_period("P2", p2_rows, p2_skip)
    print_period("P3完全未来", p3_rows, p3_skip)

    print("\n【判断ポイント】")
    print("1. OUTCOME相手化がP1/P2/P3でCURRENT順位方式よりヒモ完全一致を改善するか")
    print("2. 実2-3着ペアがSTEP3条件付きTop5/Top10へどの程度入るか")
    print("3. cut遮断率が高ければ、ヒモ不足の一部は順位付けではなくcutが原因")
    print("4. ここは診断なので、実頭条件を購入ルールとして使わない")
    print("5. すじ舟券・直内直外はこの結果を見た後の別テーマ")
    print("=" * 118)


if __name__ == "__main__":
    main()
