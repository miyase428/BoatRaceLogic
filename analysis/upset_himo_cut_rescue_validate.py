#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
穴目予想：HIGH × TRIO_TOP1頭のヒモ専用cut救済を固定ルールで検証する。

背景
----
upset_head_hit_himo_structure_validate.py では、TRIO_TOP1が実頭だったとき
OUTCOME P(2着|頭)方式がP1/P2/P3すべてで現行順位方式を改善した。
一方、OUTCOME方式で残った外れの約半分は、実2・3着のどちらかが現行cutだった。

ここでは結果を見て閾値調整せず、説明可能な固定ルールだけを試す。

固定救済ルール
------------
HIGH:
  AI本命=1C / CURRENT本命!=1C / イン補正後1着率<50%
頭:
  TRIO_TOP1（イン以外AI3連対率1位）
BASE:
  現行cutを維持し、OUTCOME P(2着|TRIO_TOP1)上位最大3艇を2着候補。
RESCUE_TOP3:
  頭を固定したP(2着|TRIO_TOP1)をcut込み全艇で順位付けし、
  上位3艇に入ったcut艇だけ救済してから同じOUTCOME買い目を作る。

重要
----
- 閾値探索なし。Top3は既存相手候補数と同じ固定値。
- P1/P2/P3で同一ルールをそのまま評価する。
- 実頭条件は診断表示だけに使い、購入ルールには使わない。
- 本番Web/PredictionLogicは変更しない。

Usage:
python3 analysis/upset_himo_cut_rescue_validate.py P1_BOATS_CSV P2_BOATS_CSV P3_BOATS_CSV
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


def ranked_outer(trio, in_lane):
    return sorted(
        [lane for lane in range(1, 7) if lane != int(in_lane)],
        key=lambda lane: (-float(trio.get(lane, 0.0)), lane),
    )


def make_rescue_top3_bets(record, boats, head):
    head = int(head)
    cut = b4.current_cut(boats)
    cut.discard(head)

    scores = b2.outcome_second_scores(record, head)
    all_others = [lane for lane in range(1, 7) if lane != head]
    ranked_all = sorted(all_others, key=lambda lane: (-float(scores.get(lane, 0.0)), lane))
    top3_all = set(ranked_all[: min(3, len(ranked_all))])

    rescued = set(cut) & top3_all
    cut_after = set(cut) - rescued

    eligible = [lane for lane in range(1, 7) if lane != head and lane not in cut_after]
    if not eligible:
        return None

    second = b2.select_score(scores, eligible, min(3, len(eligible)))
    third = list(eligible)
    bets = b4.expand_bets(head, second, third)
    if not bets:
        return None

    return {
        "head": head,
        "cut_before": cut,
        "cut": cut_after,
        "rescued": rescued,
        "second": second,
        "third": third,
        "bets": bets,
    }


def build_rows(records, boats_map, payouts):
    rows = []
    skip = defaultdict(int)

    for record in records:
        code = str(record["race_code"])
        boats = boats_map.get(code)
        if boats is None or set(boats) != set(range(1, 7)):
            skip["csv_missing"] += 1
            continue

        payout = payouts.get(code)
        if payout is None or int(payout) <= 0:
            skip["payout_missing"] += 1
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

        outer = ranked_outer(f["trio"], in_lane)
        if not outer:
            skip["trio_invalid"] += 1
            continue
        trio1 = int(outer[0])

        actual = b4.actual_trifecta(boats)
        if actual is None:
            skip["actual_invalid"] += 1
            continue
        actual = tuple(int(x) for x in actual)

        base = b4.make_outcome_bets(record, boats, trio1, {}, None)
        rescue = make_rescue_top3_bets(record, boats, trio1)
        if base is None or rescue is None or not base.get("bets") or not rescue.get("bets"):
            skip["bet_invalid"] += 1
            continue

        original_cut = b4.current_cut(boats)
        original_cut.discard(trio1)
        actual_has_cut = actual[1] in original_cut or actual[2] in original_cut

        rows.append({
            "race_code": code,
            "payout": int(payout),
            "actual": actual,
            "trio1": trio1,
            "trio1_won": actual[0] == trio1,
            "actual_has_cut": actual_has_cut,
            "base_bets": set(base["bets"]),
            "rescue_bets": set(rescue["bets"]),
            "rescued": set(rescue["rescued"]),
        })
        skip["ready"] += 1

    return rows, skip


def evaluate(rows, key):
    n = hits = points = 0
    invest100 = ret100 = 0.0
    invest_fixed = ret_fixed = 0.0

    for r in rows:
        bets = r[key]
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
            ret100 += payout
            ret_fixed += payout * ((1000.0 / cnt) / 100.0)

    return {
        "n": n,
        "hits": hits,
        "hit_rate": hits / n if n else 0.0,
        "avg_points": points / n if n else 0.0,
        "roi100": ret100 / invest100 if invest100 else 0.0,
        "roi_fixed": ret_fixed / invest_fixed if invest_fixed else 0.0,
    }


def compare(rows):
    changed = gained = lost = both = neither = 0
    for r in rows:
        b = r["actual"] in r["base_bets"]
        n = r["actual"] in r["rescue_bets"]
        if r["base_bets"] != r["rescue_bets"]:
            changed += 1
        if n and not b:
            gained += 1
        elif b and not n:
            lost += 1
        elif b and n:
            both += 1
        else:
            neither += 1
    return changed, gained, lost, both, neither


def print_period(title, rows, skip):
    base = evaluate(rows, "base_bets")
    new = evaluate(rows, "rescue_bets")
    ch, gain, lost, both, neither = compare(rows)

    rescue_r = sum(1 for r in rows if r["rescued"])
    rescue_boats = sum(len(r["rescued"]) for r in rows)

    print(f"\n【{title}】")
    print(f"HIGH={skip.get('high', 0)}R / 評価可能={len(rows)}R")
    print("方式                 平均点数   的中率   100円/点ROI   1000円均等ROI")
    print("-" * 78)
    print(
        f"BASE                 {base['avg_points']:>7.2f}   {base['hit_rate']*100:>6.2f}%      "
        f"{base['roi100']*100:>8.2f}%       {base['roi_fixed']*100:>8.2f}%"
    )
    print(
        f"RESCUE_TOP3          {new['avg_points']:>7.2f}   {new['hit_rate']*100:>6.2f}%      "
        f"{new['roi100']*100:>8.2f}%       {new['roi_fixed']*100:>8.2f}%"
    )
    print(
        f"差                   {new['avg_points']-base['avg_points']:+7.2f}   "
        f"{(new['hit_rate']-base['hit_rate'])*100:+6.2f}pt     "
        f"{(new['roi100']-base['roi100'])*100:+8.2f}pt      "
        f"{(new['roi_fixed']-base['roi_fixed'])*100:+8.2f}pt"
    )
    print(
        f"買目変更={ch}R / 拾い={gain} / 失い={lost} / rescue発生={rescue_r}R / rescue艇数={rescue_boats}"
    )

    # 実頭時は診断だけ。購入条件には使わない。
    head_rows = [r for r in rows if r["trio1_won"]]
    if head_rows:
        b_hit = sum(1 for r in head_rows if r["actual"] in r["base_bets"])
        n_hit = sum(1 for r in head_rows if r["actual"] in r["rescue_bets"])
        cut_rows = [r for r in head_rows if r["actual_has_cut"]]
        cut_recovered = sum(1 for r in cut_rows if r["actual"] in r["rescue_bets"])
        print(
            f"実頭診断: N={len(head_rows)} / BASE={b_hit}/{len(head_rows)} ({b_hit/len(head_rows)*100:.2f}%) / "
            f"RESCUE={n_hit}/{len(head_rows)} ({n_hit/len(head_rows)*100:.2f}%)"
        )
        print(
            f"実頭かつ実ヒモにcut={len(cut_rows)}R / そのうち救済で的中={cut_recovered}R"
        )


def main():
    if len(sys.argv) != 4:
        print("Usage: python3 analysis/upset_himo_cut_rescue_validate.py P1 P2 P3")
        sys.exit(1)

    p1, p2, p3 = sys.argv[1:]

    print("STEP3共通データを再構築中...", flush=True)
    train = step3.build_common_records(p1, p2)
    print("P1/P2構築完了。P3前方データを再構築中...", flush=True)
    future = step3.build_common_records(p2, p3)
    print("CSV・払戻を読込中...", flush=True)

    boats_map = b2.load_boats(p1, p2, p3)
    payouts = b4.load_payouts(train["p1_start"], future["p2_end"])

    p1_rows, p1_skip = build_rows(train["records"]["P1"], boats_map, payouts)
    p2_rows, p2_skip = build_rows(train["records"]["P2"], boats_map, payouts)
    p3_rows, p3_skip = build_rows(future["records"]["P2"], boats_map, payouts)

    print("=" * 118)
    print("穴目予想：HIGH × TRIO_TOP1 ヒモ専用cut救済 固定Top3前方検証")
    print("=" * 118)
    print(f"P1 : {train['p1_start']} ～ {train['p1_end']}")
    print(f"P2 : {train['p2_start']} ～ {train['p2_end']}")
    print(f"P3 : {future['p2_start']} ～ {future['p2_end']} 完全未来")
    print("救済条件: P(2着|TRIO_TOP1)をcut込みで並べ、Top3に入ったcut艇だけ救済")
    print("閾値探索・P3再調整: なし")
    print("本番Web/PredictionLogic変更: なし")

    print_period("P1", p1_rows, p1_skip)
    print_period("P2", p2_rows, p2_skip)
    print_period("P3完全未来", p3_rows, p3_skip)

    print("\n【判断ポイント】")
    print("1. P1/P2/P3で的中差が同方向か")
    print("2. 点数増に対して1000円均等ROIが維持・改善するか")
    print("3. 実頭時のcut遮断レースをどの程度救えるか")
    print("4. P3を見てTop3や条件を変更しない")
    print("5. 再現しなければ現行cutを維持し、穴ヒモ候補は表示専用とする")
    print("=" * 118)


if __name__ == "__main__":
    main()
