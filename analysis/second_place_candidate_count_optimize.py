#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
③ AI_FINAL の2着確率を使い、2着候補数を1～3艇で動的に変える検証。

目的
----
- 現在は③の上位3艇を2着候補として使っている。
- ③の確率分布に明確な強弱があるレースでは、2着候補を1～2艇へ減らして
  点数を抑えられる可能性がある。
- 逆に確率が割れているレースは3艇を残す。

今回のルール
------------
FIXED_TOP1 / TOP2 / TOP3
    常に固定艇数。

CUM_RAW_xx
    ③の元確率を上位から足し、累積が target に達したところで止める。
    最大3艇。kiru候補へ割り当てられた確率は再配分しない。

CUM_ELIG_xx
    kiruを除外した買える候補だけで③確率を再正規化し、
    上位から累積が target に達したところで止める。最大3艇。

開発手順
--------
1. OLD P1 (2026-06-15～07-14) だけで候補ルールを選ぶ。
2. OLD P2 (2026-07-15～08-14) で再現性を見る。
3. F1/F2 (2026-08-15～08-31) は前方参考として固定ルールを確認する。
4. 本番Web/買い目ロジックは変更しない。

選定プロファイル
----------------
CONSERVATIVE : 固定Top3の3連単的中を98%以上保持しつつ、平均点数を最小化。
BALANCED     : 95%以上保持。
AGGRESSIVE   : 90%以上保持。

ROIは100円/点の固定購入で参考表示するが、ルール選定の最優先にはしない。

Usage
-----
python3 analysis/second_place_candidate_count_optimize.py
"""

from __future__ import annotations

from collections import Counter
from pathlib import Path

import final_prediction_ai_opponent_compare as final_aite
import final_prediction_head1_blend_second_compare as betutil
import final_prediction_second_engine_all_head_compare as allhead
import trifecta_probability_order_compare as step3


OLD_P1 = "analysis/output/final_prediction_boats_20260615_20260714_OLD.csv"
OLD_P2 = "analysis/output/final_prediction_boats_20260715_20260814_OLD.csv"
F1 = "analysis/output/final_prediction_boats_fast_cached_20260815_20260822.csv"
F2 = "analysis/output/final_prediction_boats_fast_cached_20260823_20260831.csv"

TARGETS = (0.50, 0.55, 0.60, 0.65, 0.70, 0.75, 0.80)
PROFILES = (
    ("CONSERVATIVE", 0.98),
    ("BALANCED", 0.95),
    ("AGGRESSIVE", 0.90),
)


def ensure_files():
    missing = [p for p in (OLD_P1, OLD_P2, F1, F2) if not Path(p).exists()]
    if missing:
        raise FileNotFoundError("必要CSVがありません: " + ", ".join(missing))


def load_pair(p1_csv, p2_csv):
    data = step3.build_common_records(p1_csv, p2_csv)
    csv_races = final_aite.load_boats(p1_csv, p2_csv)
    maps = allhead.load_exhibition_course_maps(data["p1_start"], data["p2_end"])
    payouts = betutil.load_trifecta_payouts(data["p1_start"], data["p2_end"])
    rows, skip, source = allhead.build_rows(data, csv_races, maps, payouts)
    return data, rows, skip, source


def build_rules():
    rules = [
        {"name": "FIXED_TOP1", "kind": "FIXED", "k": 1},
        {"name": "FIXED_TOP2", "kind": "FIXED", "k": 2},
        {"name": "FIXED_TOP3", "kind": "FIXED", "k": 3},
    ]
    for target in TARGETS:
        pct = int(round(target * 100))
        rules.append({"name": f"CUM_RAW_{pct}", "kind": "CUM_RAW", "target": target})
        rules.append({"name": f"CUM_ELIG_{pct}", "kind": "CUM_ELIG", "target": target})
    return rules


def choose_seconds(row, rule):
    order = [int(x) for x in row["orders"]["AI_FINAL_ALL_HEAD"]]
    if not order:
        return []

    if rule["kind"] == "FIXED":
        return order[: min(int(rule["k"]), len(order))]

    target = float(rule["target"])
    probs = row["ai_probs"]
    denom = 1.0
    if rule["kind"] == "CUM_ELIG":
        denom = sum(max(0.0, float(probs.get(b, 0.0))) for b in order)
        if denom <= 0.0:
            denom = 1.0

    selected = []
    cumulative = 0.0
    for b in order[:3]:
        selected.append(b)
        cumulative += max(0.0, float(probs.get(b, 0.0))) / denom
        if cumulative >= target:
            break
    return selected


def evaluate(rows, rule):
    races = len(rows)
    points = 0
    tri_hits = 0
    payout_sum = 0.0
    payout_missing_hits = 0
    second_eval_n = 0
    second_hits = 0
    count_dist = Counter()

    for row in rows:
        seconds = choose_seconds(row, rule)
        count_dist[len(seconds)] += 1
        tickets = betutil.expand_formation(int(row["head"]), seconds, list(row["thirds"]))
        points += len(tickets)

        if row["head_won"] and not row["actual_second_cut"]:
            second_eval_n += 1
            if int(row["actual_second"]) in seconds:
                second_hits += 1

        if row["actual"] in tickets:
            tri_hits += 1
            if float(row["payout"]) > 0.0:
                payout_sum += float(row["payout"])
            else:
                payout_missing_hits += 1

    invest = points * 100.0
    avg_seconds = sum(k * v for k, v in count_dist.items()) / races if races else 0.0
    return {
        "name": rule["name"],
        "races": races,
        "second_eval_n": second_eval_n,
        "second_hits": second_hits,
        "second_rate": second_hits / second_eval_n if second_eval_n else 0.0,
        "tri_hits": tri_hits,
        "tri_rate": tri_hits / races if races else 0.0,
        "points": points,
        "avg_points": points / races if races else 0.0,
        "avg_seconds": avg_seconds,
        "roi": payout_sum / invest if invest else 0.0,
        "payout_missing_hits": payout_missing_hits,
        "count_dist": dict(count_dist),
    }


def print_grid(title, rows, rules):
    results = [evaluate(rows, r) for r in rules]
    base = next(x for x in results if x["name"] == "FIXED_TOP3")

    print(f"\n【{title}】")
    print(
        "方式              2着艇数  2着捕捉   3連単的中  Hit保持  平均点数  点数削減    ROI      艇数内訳(1/2/3)"
    )
    print("-" * 116)
    for x in results:
        hit_ret = x["tri_hits"] / base["tri_hits"] if base["tri_hits"] else 0.0
        point_cut = 1.0 - (x["avg_points"] / base["avg_points"]) if base["avg_points"] else 0.0
        d = x["count_dist"]
        print(
            f"{x['name']:<17} "
            f"{x['avg_seconds']:>6.2f}   "
            f"{x['second_rate']*100:>6.2f}%   "
            f"{x['tri_rate']*100:>7.2f}%   "
            f"{hit_ret*100:>6.2f}%   "
            f"{x['avg_points']:>6.2f}   "
            f"{point_cut*100:>+7.2f}%   "
            f"{x['roi']*100:>7.2f}%   "
            f"{d.get(1,0):>4d}/{d.get(2,0):>4d}/{d.get(3,0):>4d}"
        )
    return {x["name"]: x for x in results}


def select_profiles(dev_results, rules_by_name):
    base = dev_results["FIXED_TOP3"]
    selected = {}

    for profile, min_retention in PROFILES:
        candidates = []
        for name, x in dev_results.items():
            if name.startswith("FIXED_"):
                continue
            retention = x["tri_hits"] / base["tri_hits"] if base["tri_hits"] else 0.0
            if retention < min_retention:
                continue
            if x["avg_points"] >= base["avg_points"]:
                continue
            candidates.append((
                x["avg_points"],
                -retention,
                -x["roi"],
                name,
            ))

        if not candidates:
            selected[profile] = None
            continue

        candidates.sort()
        best_name = candidates[0][3]
        selected[profile] = rules_by_name[best_name]
    return selected


def subset(rows, mode):
    if mode == "ALL":
        return list(rows)
    if mode == "1C":
        return [r for r in rows if int(r["head_course"]) == 1]
    if mode == "NON1C":
        return [r for r in rows if int(r["head_course"]) != 1]
    raise ValueError(mode)


def print_selected_period(title, rows, selected):
    base_rule = {"name": "FIXED_TOP3", "kind": "FIXED", "k": 3}
    base = evaluate(rows, base_rule)

    print(f"\n【{title}：OLD P1だけで固定した候補】")
    print("Profile       方式              3連単的中  Hit保持  平均点数  点数削減    ROI    2着捕捉")
    print("-" * 96)
    print(
        f"{'BASE':<13} {'FIXED_TOP3':<17} {base['tri_rate']*100:>7.2f}%  "
        f"{100.0:>6.2f}%  {base['avg_points']:>6.2f}   {0.0:>+7.2f}%  "
        f"{base['roi']*100:>7.2f}%  {base['second_rate']*100:>7.2f}%"
    )

    for profile, rule in selected.items():
        if rule is None:
            print(f"{profile:<13} {'該当なし':<17}")
            continue
        x = evaluate(rows, rule)
        retention = x["tri_hits"] / base["tri_hits"] if base["tri_hits"] else 0.0
        point_cut = 1.0 - x["avg_points"] / base["avg_points"] if base["avg_points"] else 0.0
        print(
            f"{profile:<13} {rule['name']:<17} {x['tri_rate']*100:>7.2f}%  "
            f"{retention*100:>6.2f}%  {x['avg_points']:>6.2f}   {point_cut*100:>+7.2f}%  "
            f"{x['roi']*100:>7.2f}%  {x['second_rate']*100:>7.2f}%"
        )


def print_profile_detail(periods, selected):
    print("\n【固定候補の頭コース別確認】")
    for period_name, rows in periods:
        for mode in ("1C", "NON1C"):
            rr = subset(rows, mode)
            if not rr:
                continue
            print_selected_period(f"{period_name} {mode}", rr, selected)


def main():
    ensure_files()
    rules = build_rules()
    rules_by_name = {r["name"]: r for r in rules}

    print("=" * 132)
    print("③ AI_FINAL：2着候補数 1～3艇 動的最適化")
    print("=" * 132)
    print("基準       : ③ AI_FINALの2着確率")
    print("頭/kiru    : 現行固定")
    print("3着候補    : 現行と同じ非kiru・頭以外の全艇")
    print("投資       : 100円/点")
    print("本番変更   : なし")
    print("ルール選定 : OLD P1だけ。OLD P2/F1/F2では再調整しない")

    old_data, old_rows, old_skip, _ = load_pair(OLD_P1, OLD_P2)
    fwd_data, fwd_rows, fwd_skip, _ = load_pair(F1, F2)

    print("\n【期間】")
    print(f"OLD P1 DEV       : {old_data['p1_start']} ～ {old_data['p1_end']} / {len(old_rows['P1'])}R")
    print(f"OLD P2 VALID     : {old_data['p2_start']} ～ {old_data['p2_end']} / {len(old_rows['P2'])}R")
    print(f"F1 FORWARD REF   : {fwd_data['p1_start']} ～ {fwd_data['p1_end']} / {len(fwd_rows['P1'])}R")
    print(f"F2 FORWARD REF   : {fwd_data['p2_start']} ～ {fwd_data['p2_end']} / {len(fwd_rows['P2'])}R")

    dev_results = print_grid("OLD P1 DEV：全ルール", old_rows["P1"], rules)
    print_grid("OLD P2 VALID：全ルール（再調整なし）", old_rows["P2"], rules)

    selected = select_profiles(dev_results, rules_by_name)
    print("\n【OLD P1で固定した3プロファイル】")
    for profile, min_ret in PROFILES:
        rule = selected[profile]
        if rule is None:
            print(f"{profile:<13}: 該当なし（Top3的中保持 {min_ret*100:.0f}% 条件）")
        else:
            print(f"{profile:<13}: {rule['name']}  （Top3的中保持 >= {min_ret*100:.0f}% をDEVで要求）")

    periods = [
        ("OLD P1 DEV", old_rows["P1"]),
        ("OLD P2 VALID", old_rows["P2"]),
        ("F1 FORWARD", fwd_rows["P1"]),
        ("F2 FORWARD", fwd_rows["P2"]),
        ("F1+F2 FORWARD", fwd_rows["P1"] + fwd_rows["P2"]),
    ]
    for name, rows in periods:
        print_selected_period(name, rows, selected)

    print_profile_detail(periods[1:], selected)

    print("\n【スキップ】")
    for label, skip in (("OLD", old_skip), ("FORWARD", fwd_skip)):
        ready = {k: v for k, v in skip.items() if k.endswith("_ready")}
        others = {k: v for k, v in skip.items() if not k.endswith("_ready") and v}
        print(f"{label} ready : {ready}")
        if others:
            print(f"{label} other : {others}")

    print("\n【判断方針】")
    print("1. まず固定Top3に対して、何%の3連単的中を残しながら何%点数を削減できるかを見る。")
    print("2. ROIは高配当1本で動きやすいので、候補選定は的中保持率と点数削減を優先する。")
    print("3. OLD P1で選んだルールがOLD P2とF1/F2でも同じ方向なら、動的候補数に再現性あり。")
    print("4. 1C/NON1Cで差が大きければ、次段階で頭コース別ルールを検討する。")
    print("5. この結果だけでは本番へ入れず、採用候補を1つに固定して新しい未来期間でも確認する。")
    print("=" * 132)


if __name__ == "__main__":
    main()
