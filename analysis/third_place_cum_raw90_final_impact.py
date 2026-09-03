#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
3着 BALANCED THIRD_CUM_RAW_90 の買い目影響を最終確認する。

前提
----
- 頭は現行本命固定。
- 2着は CUM_RAW_70 固定。
- 3着は THIRD_CUM_RAW_90 固定。
- 閾値はこのスクリプトで再調整しない。
- 本番Webは変更しない。

確認内容
--------
- RAW70+3着ALL と RAW70+RAW90 の点数分布
- 追加削減レース率・削減点数
- 100円/点で10点以下になるレース率
- RAW70+3着ALLなら当たっていたがRAW90で失った的中
- 元Top3固定から見た累積的中保持
- 失的中1件あたり削減点数
- F1+F2の失的中具体例

Usage
-----
python3 analysis/third_place_cum_raw90_final_impact.py
"""

from __future__ import annotations

from collections import Counter

import second_place_candidate_count_optimize as second_opt
import third_place_candidate_count_optimize as third_opt


THIRD_RAW90 = {"name": "THIRD_CUM_RAW_90", "kind": "CUM_RAW", "target": 0.90}
THIRD_ALL = third_opt.THIRD_ALL
BASE_TOP3 = {"name": "FIXED_TOP3", "kind": "FIXED", "k": 3}


def evaluate_detail(rows, contexts, third_rule):
    races = 0
    points = 0
    hits = 0
    payout_sum = 0.0
    point_dist = Counter()
    second_count = Counter()
    branch_count = Counter()
    per_race = {}

    for row in rows:
        code = str(row["race_code"])
        context = contexts.get(code)
        if context is None:
            continue

        tickets, seconds, branch_counts, branch_thirds = third_opt.build_tickets(row, context, third_rule)
        if not tickets:
            continue

        races += 1
        n = len(tickets)
        points += n
        point_dist[n] += 1
        second_count[len(seconds)] += 1
        for bc in branch_counts:
            branch_count[int(bc)] += 1

        actual = tuple(int(x) for x in row["actual"])
        hit = actual in tickets
        if hit:
            hits += 1
            if float(row["payout"]) > 0:
                payout_sum += float(row["payout"])

        per_race[code] = {
            "race_code": code,
            "head": int(row["head"]),
            "head_course": int(row["head_course"]),
            "actual": actual,
            "payout": float(row["payout"]),
            "points": n,
            "hit": hit,
            "seconds": list(seconds),
            "branch_thirds": {int(k): list(v) for k, v in branch_thirds.items()},
        }

    invest = points * 100.0
    return {
        "races": races,
        "points": points,
        "avg_points": points / races if races else 0.0,
        "hits": hits,
        "hit_rate": hits / races if races else 0.0,
        "roi": payout_sum / invest if invest else 0.0,
        "payout_sum": payout_sum,
        "point_dist": dict(point_dist),
        "second_count": dict(second_count),
        "branch_count": dict(branch_count),
        "per_race": per_race,
    }


def evaluate_original_top3(rows):
    return second_opt.evaluate(rows, BASE_TOP3)


def fmt_dist(d):
    return "  ".join(f"{k}点={d[k]}R" for k in sorted(d))


def under_10(x):
    return sum(v for k, v in x["point_dist"].items() if int(k) <= 10)


def print_period(title, rows, contexts, show_losses=False):
    original = evaluate_original_top3(rows)
    base = evaluate_detail(rows, contexts, THIRD_ALL)
    raw90 = evaluate_detail(rows, contexts, THIRD_RAW90)

    common_codes = set(base["per_race"]) & set(raw90["per_race"])
    reduced_races = 0
    reduced_points = 0
    diff_dist = Counter()
    lost = []

    for code in common_codes:
        b = base["per_race"][code]
        r = raw90["per_race"][code]
        diff = int(r["points"]) - int(b["points"])
        diff_dist[diff] += 1
        if diff < 0:
            reduced_races += 1
            reduced_points += -diff
        if b["hit"] and not r["hit"]:
            lost.append({
                **r,
                "base_points": int(b["points"]),
                "raw90_points": int(r["points"]),
                "cut": int(b["points"]) - int(r["points"]),
            })

    lost.sort(key=lambda x: (-float(x["payout"]), x["race_code"]))
    lost_payout = sum(float(x["payout"]) for x in lost if float(x["payout"]) > 0)

    base_under = under_10(base)
    raw_under = under_10(raw90)
    newly_under = sum(
        1 for code in common_codes
        if int(base["per_race"][code]["points"]) > 10
        and int(raw90["per_race"][code]["points"]) <= 10
    )

    raw_ret = raw90["hits"] / base["hits"] if base["hits"] else 0.0
    orig_ret = raw90["hits"] / original["tri_hits"] if original["tri_hits"] else 0.0
    total_cut = 1.0 - raw90["avg_points"] / original["avg_points"] if original["avg_points"] else 0.0
    add_cut = 1.0 - raw90["avg_points"] / base["avg_points"] if base["avg_points"] else 0.0

    print("\n" + "=" * 122)
    print(title)
    print("=" * 122)
    print(
        f"対象={raw90['races']}R  RAW70+ALL={base['avg_points']:.2f}点  RAW70+RAW90={raw90['avg_points']:.2f}点  "
        f"追加削減={add_cut*100:.2f}%  元Top3から総削減={total_cut*100:.2f}%"
    )
    print(
        f"3連単={base['hit_rate']*100:.2f}%→{raw90['hit_rate']*100:.2f}%  "
        f"RAW70保持={raw_ret*100:.2f}%  元Top3保持={orig_ret*100:.2f}%  "
        f"ROI={base['roi']*100:.2f}%→{raw90['roi']*100:.2f}%"
    )

    print("\n[買い目点数分布]")
    print("RAW70+ALL  : " + fmt_dist(base["point_dist"]))
    print("RAW70+RAW90: " + fmt_dist(raw90["point_dist"]))

    print("\n[RAW90による追加削減]")
    print(f"削減発生レース       : {reduced_races}R ({reduced_races/raw90['races']*100:.2f}%)" if raw90["races"] else "削減発生レース       : 0R")
    print(f"追加削減総点数       : {reduced_points}点 = 100円/点なら {reduced_points*100:,}円")
    print(f"削減発生時の平均削減 : {reduced_points/reduced_races:.2f}点/R" if reduced_races else "削減発生時の平均削減 : -")
    print("差分内訳             : " + "  ".join(f"{k:+d}点={v}R" for k, v in sorted(diff_dist.items())))

    print("\n[3着枝の候補数]")
    print("ALL  : " + " / ".join(f"{k}艇={v}枝" for k, v in sorted(base["branch_count"].items())))
    print("RAW90: " + " / ".join(f"{k}艇={v}枝" for k, v in sorted(raw90["branch_count"].items())))

    print("\n[1レース1,000円参考：100円/点で10点以下]")
    print(f"RAW70+ALL  10点以下   : {base_under}R ({base_under/base['races']*100:.2f}%)" if base["races"] else "-")
    print(f"RAW70+RAW90 10点以下  : {raw_under}R ({raw_under/raw90['races']*100:.2f}%)" if raw90["races"] else "-")
    print(f"RAW90で新たに10点以下: {newly_under}R ({newly_under/raw90['races']*100:.2f}% / 全R)" if raw90["races"] else "-")
    print(f"平均必要額(100円/点) : {base['avg_points']*100:.0f}円 → {raw90['avg_points']*100:.0f}円")

    print("\n[的中との交換条件]")
    print(f"RAW70+ALL的中         : {base['hits']}R")
    print(f"RAW70+RAW90的中       : {raw90['hits']}R")
    print(f"追加削減による失的中 : {len(lost)}R ({len(lost)/raw90['races']*100:.2f}% / 全R)" if raw90["races"] else "-")
    print(f"失的中1件あたり削減 : {reduced_points/len(lost):.1f}点" if lost else "失的中1件あたり削減 : -")
    print(f"追加削減投資額       : {reduced_points*100:,}円（100円/点）")
    print(f"失った払戻合計       : {lost_payout:,.0f}円（的中舟券100円換算）")

    if show_losses:
        print("\n[RAW90追加削減で失った的中：払戻順 最大20件]")
        print("race_code        頭/C  実3連単    点数     削減   払戻    2着候補と3着候補")
        print("-" * 122)
        for x in lost[:20]:
            branch = " ".join(
                f"{s}->" + "".join(str(t) for t in ts)
                for s, ts in sorted(x["branch_thirds"].items())
            )
            actual = "-".join(str(v) for v in x["actual"])
            print(
                f"{x['race_code']:<16} {x['head']}/{x['head_course']}   {actual:<9} "
                f"{x['base_points']:>2}->{x['raw90_points']:<2}   -{x['cut']:<3}  {x['payout']:>7.0f}円  {branch}"
            )


def main():
    second_opt.ensure_files()

    old_data, old_rows, old_ctx, _, _ = third_opt.load_pair_with_context(second_opt.OLD_P1, second_opt.OLD_P2)
    fwd_data, fwd_rows, fwd_ctx, _, _ = third_opt.load_pair_with_context(second_opt.F1, second_opt.F2)

    print("=" * 122)
    print("③ AI_FINAL：BALANCED 3着 THIRD_CUM_RAW_90 買い目影響 最終確認")
    print("2着RAW70固定 / 3着RAW90固定 / 閾値再調整なし / 本番変更なし")
    print("=" * 122)

    print_period("OLD P1 DEV ALL", old_rows["P1"], old_ctx)
    print_period("OLD P2 VALID ALL", old_rows["P2"], old_ctx)
    print_period("F1 FORWARD ALL", fwd_rows["P1"], fwd_ctx)
    print_period("F2 FORWARD ALL", fwd_rows["P2"], fwd_ctx)

    combined = list(fwd_rows["P1"]) + list(fwd_rows["P2"])
    print_period("F1+F2 FORWARD ALL", combined, fwd_ctx, show_losses=True)
    print_period("F1+F2 FORWARD 1C", second_opt.subset(combined, "1C"), fwd_ctx)
    print_period("F1+F2 FORWARD NON1C", second_opt.subset(combined, "NON1C"), fwd_ctx)

    print("\n【判定の見方】")
    print("- 3着閾値はRAW90で固定。この出力を見て85/90などへ差し替えない。")
    print("- RAW70+3着ALLに対する追加ダメージと追加点数削減の交換条件を見る。")
    print("- 元Top3固定からの累積Hit保持も確認する。")
    print("- 3着はユーザー判断で残す余地を前提に、AIの削減候補として扱う。")
    print("- この確認後も本番Webへはまだ反映しない。")


if __name__ == "__main__":
    main()
