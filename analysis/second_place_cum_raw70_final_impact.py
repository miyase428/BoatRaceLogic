#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
③ AI_FINAL 2着候補 BALANCED = CUM_RAW_70 の最終影響確認。

目的
----
- 閾値は CUM_RAW_70 で固定し、ここでは再調整しない。
- FIXED_TOP3 と比べて、実際の3連単フォーメーション点数がどう変わるか確認する。
- 100円/点換算で1レース1,000円以内（10点以下）に収まるレース率も参考表示する。
- 削減した総点数に対して、Top3なら的中していたのにRAW70で失った的中が何件あるか確認する。
- 本番Web / 買い目ロジックは変更しない。

注意
----
「1,000円以内」は100円/点で全候補を均等に1点ずつ買う場合の参考値。
実際のオッズ配分・傾斜配分はここでは行わない。

Usage
-----
python3 analysis/second_place_cum_raw70_final_impact.py
"""

from __future__ import annotations

from collections import Counter

import second_place_candidate_count_optimize as opt
import final_prediction_head1_blend_second_compare as betutil


BASE_RULE = {"name": "FIXED_TOP3", "kind": "FIXED", "k": 3}
RAW70_RULE = {"name": "CUM_RAW_70", "kind": "CUM_RAW", "target": 0.70}
BUDGET_POINTS = 10  # 100円/点 × 10点 = 1,000円


def pct(n, d):
    return (n / d * 100.0) if d else 0.0


def build_tickets(row, rule):
    seconds = opt.choose_seconds(row, rule)
    tickets = betutil.expand_formation(int(row["head"]), seconds, list(row["thirds"]))
    return seconds, tickets


def analyze(rows):
    n = len(rows)
    base_hist = Counter()
    raw_hist = Counter()
    delta_hist = Counter()
    raw_second_count = Counter()

    base_points = 0
    raw_points = 0
    base_hits = 0
    raw_hits = 0
    base_payout = 0.0
    raw_payout = 0.0

    changed_races = 0
    saved_points = 0
    base_budget_ok = 0
    raw_budget_ok = 0
    became_budget_ok = 0

    lost_hits = []
    unexpected_gains = []

    for row in rows:
        base_seconds, base_tickets = build_tickets(row, BASE_RULE)
        raw_seconds, raw_tickets = build_tickets(row, RAW70_RULE)

        bp = len(base_tickets)
        rp = len(raw_tickets)
        delta = bp - rp

        base_hist[bp] += 1
        raw_hist[rp] += 1
        delta_hist[delta] += 1
        raw_second_count[len(raw_seconds)] += 1

        base_points += bp
        raw_points += rp

        base_ok = bp <= BUDGET_POINTS
        raw_ok = rp <= BUDGET_POINTS
        if base_ok:
            base_budget_ok += 1
        if raw_ok:
            raw_budget_ok += 1
        if (not base_ok) and raw_ok:
            became_budget_ok += 1

        if delta > 0:
            changed_races += 1
            saved_points += delta

        actual = row["actual"]
        payout = float(row.get("payout", 0.0) or 0.0)
        base_hit = actual in base_tickets
        raw_hit = actual in raw_tickets

        if base_hit:
            base_hits += 1
            if payout > 0.0:
                base_payout += payout
        if raw_hit:
            raw_hits += 1
            if payout > 0.0:
                raw_payout += payout

        if base_hit and not raw_hit:
            lost_hits.append({
                "race_code": str(row["race_code"]),
                "head": int(row["head"]),
                "head_course": int(row["head_course"]),
                "base_seconds": list(base_seconds),
                "raw_seconds": list(raw_seconds),
                "actual": tuple(actual),
                "base_points": bp,
                "raw_points": rp,
                "saved": delta,
                "payout": payout,
            })
        elif raw_hit and not base_hit:
            # RAW70はTop3の部分集合なので原理上0件のはず。念のため監視。
            unexpected_gains.append(str(row["race_code"]))

    base_invest = base_points * 100.0
    raw_invest = raw_points * 100.0
    lost_payout = max(0.0, base_payout - raw_payout)

    return {
        "races": n,
        "base_hist": base_hist,
        "raw_hist": raw_hist,
        "delta_hist": delta_hist,
        "raw_second_count": raw_second_count,
        "base_points": base_points,
        "raw_points": raw_points,
        "base_avg": base_points / n if n else 0.0,
        "raw_avg": raw_points / n if n else 0.0,
        "cut_rate": 1.0 - raw_points / base_points if base_points else 0.0,
        "changed_races": changed_races,
        "saved_points": saved_points,
        "avg_saved_changed": saved_points / changed_races if changed_races else 0.0,
        "base_budget_ok": base_budget_ok,
        "raw_budget_ok": raw_budget_ok,
        "became_budget_ok": became_budget_ok,
        "base_hits": base_hits,
        "raw_hits": raw_hits,
        "hit_retention": raw_hits / base_hits if base_hits else 0.0,
        "base_roi": base_payout / base_invest if base_invest else 0.0,
        "raw_roi": raw_payout / raw_invest if raw_invest else 0.0,
        "saved_invest": (base_points - raw_points) * 100.0,
        "lost_payout": lost_payout,
        "lost_hits": lost_hits,
        "unexpected_gains": unexpected_gains,
    }


def hist_text(counter):
    if not counter:
        return "なし"
    return "  ".join(f"{k}点={v}R" for k, v in sorted(counter.items()))


def delta_text(counter):
    if not counter:
        return "なし"
    return "  ".join(f"-{k}点={v}R" if k > 0 else f"±0点={v}R" for k, v in sorted(counter.items()))


def print_result(title, rows, show_lost=False):
    x = analyze(rows)
    n = x["races"]

    print("\n" + "=" * 118)
    print(title)
    print("=" * 118)
    print(
        f"対象={n}R  "
        f"BASE={x['base_avg']:.2f}点  RAW70={x['raw_avg']:.2f}点  "
        f"点数削減={x['cut_rate']*100:.2f}%  "
        f"Hit保持={x['hit_retention']*100:.2f}%  "
        f"ROI {x['base_roi']*100:.2f}%→{x['raw_roi']*100:.2f}%"
    )

    print("\n[買い目点数分布]")
    print("BASE : " + hist_text(x["base_hist"]))
    print("RAW70: " + hist_text(x["raw_hist"]))

    print("\n[RAW70による削減]")
    print(f"削減発生レース       : {x['changed_races']}R ({pct(x['changed_races'], n):.2f}%)")
    print(f"削減総点数           : {x['saved_points']}点 = 100円/点なら {x['saved_invest']:,.0f}円")
    print(f"削減発生時の平均削減 : {x['avg_saved_changed']:.2f}点/R")
    print("差分内訳             : " + delta_text(x["delta_hist"]))
    d = x["raw_second_count"]
    print(f"RAW70 2着候補数      : 1艇={d.get(1,0)}R / 2艇={d.get(2,0)}R / 3艇={d.get(3,0)}R")

    print("\n[1レース1,000円の参考：100円/点で10点以下]")
    print(f"BASE 10点以下        : {x['base_budget_ok']}R ({pct(x['base_budget_ok'], n):.2f}%)")
    print(f"RAW70 10点以下       : {x['raw_budget_ok']}R ({pct(x['raw_budget_ok'], n):.2f}%)")
    print(
        f"RAW70で新たに10点以下: {x['became_budget_ok']}R "
        f"({pct(x['became_budget_ok'], n):.2f}% / 全R)"
    )
    print(f"平均必要額(100円/点) : {x['base_avg']*100:.0f}円 → {x['raw_avg']*100:.0f}円")

    lost_n = len(x["lost_hits"])
    print("\n[的中との交換条件]")
    print(f"BASE的中             : {x['base_hits']}R")
    print(f"RAW70的中            : {x['raw_hits']}R")
    print(f"削減による失的中     : {lost_n}R ({pct(lost_n, n):.2f}% / 全R)")
    if lost_n:
        print(f"失的中1件あたり削減 : {x['saved_points']/lost_n:.1f}点")
    else:
        print("失的中1件あたり削減 : -")
    print(f"削減投資額           : {x['saved_invest']:,.0f}円（100円/点）")
    print(f"失った払戻合計       : {x['lost_payout']:,.0f}円（的中舟券100円換算）")

    if x["unexpected_gains"]:
        print("警告: RAW70だけ的中したレースあり（想定外）: " + ", ".join(x["unexpected_gains"][:10]))

    if show_lost and x["lost_hits"]:
        print("\n[削減で失った的中：払戻順 最大20件]")
        print("race_code        頭/C  BASE2着     RAW70 2着    実3連単    点数   削減   払戻")
        print("-" * 100)
        lost = sorted(x["lost_hits"], key=lambda r: (-r["payout"], r["race_code"]))[:20]
        for r in lost:
            bs = "-".join(map(str, r["base_seconds"]))
            rs = "-".join(map(str, r["raw_seconds"]))
            actual = "-".join(map(str, r["actual"]))
            print(
                f"{r['race_code']:<16} {r['head']}/{r['head_course']:<2} "
                f"{bs:<11} {rs:<11} {actual:<9} "
                f"{r['base_points']:>2}→{r['raw_points']:<2}  "
                f"-{r['saved']:<2}  {r['payout']:>8.0f}円"
            )

    return x


def main():
    opt.ensure_files()

    print("=" * 118)
    print("③ AI_FINAL：BALANCED CUM_RAW_70 買い目影響 最終確認")
    print("閾値固定 / 1C・NON1C分岐なし / 本番変更なし")
    print("1,000円参考 = 100円/点で10点以下かどうか。オッズ傾斜配分は未考慮。")
    print("=" * 118)

    _, old_rows, _, _ = opt.load_pair(opt.OLD_P1, opt.OLD_P2)
    _, fwd_rows, _, _ = opt.load_pair(opt.F1, opt.F2)

    print_result("OLD P1 DEV ALL", old_rows["P1"])
    print_result("OLD P2 VALID ALL", old_rows["P2"])
    print_result("F1 FORWARD ALL", fwd_rows["P1"])
    print_result("F2 FORWARD ALL", fwd_rows["P2"])

    combined = list(fwd_rows["P1"]) + list(fwd_rows["P2"])
    print_result("F1+F2 FORWARD ALL", combined, show_lost=True)

    print_result("F1+F2 FORWARD 1C", opt.subset(combined, "1C"))
    print_result("F1+F2 FORWARD NON1C", opt.subset(combined, "NON1C"))

    print("\n【判定の見方】")
    print("- 閾値はRAW70で凍結済み。この出力を見て65/75へ動かさない。")
    print("- 10点以下率がどこまで増えるかで、1,000円運用との相性を見る。")
    print("- 失的中1件あたり何点を削減できたかで、削減と的中低下の交換条件を見る。")
    print("- 払戻差は高配当1件で振れやすいため、ROIと同様に参考値として扱う。")
    print("- この確認後も本番Webにはまだ反映しない。")


if __name__ == "__main__":
    main()
