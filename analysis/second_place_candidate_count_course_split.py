#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
③ AI_FINAL 2着候補数：1C / NON1C 分岐の追加検証。

目的
----
CUM_RAW_70 の前方詳細検証で、Top3固定から削ったことによる失点が
1C側に偏る可能性が見えたため、頭コース別に閾値を分ける価値があるか確認する。

重要
----
- 本番Webは変更しない。
- 分岐ルールの選定は OLD P1 DEV だけで行う。
- OLD P2 / F1 / F2 では一切再調整しない。
- 候補は既に検証済みの CUM_RAW 系に限定し、65 / 70 / 75 の3本だけを見る。
- BALANCED と同じく、各サブグループで Top3 的中保持 95%以上を条件に、
  平均点数が最小のルールを選ぶ。条件を満たさなければ FIXED_TOP3 に戻す。

Usage
-----
python3 analysis/second_place_candidate_count_course_split.py
"""

from __future__ import annotations

from collections import Counter

import second_place_candidate_count_optimize as opt
import final_prediction_head1_blend_second_compare as betutil


BALANCED_RETENTION = 0.95
RAW_NAMES = ("CUM_RAW_65", "CUM_RAW_70", "CUM_RAW_75")
BASE_RULE = {"name": "FIXED_TOP3", "kind": "FIXED", "k": 3}


def rule_map():
    rules = opt.build_rules()
    return {r["name"]: r for r in rules}


def evaluate_rows(rows, choose_rule):
    races = len(rows)
    points = 0
    tri_hits = 0
    payout_sum = 0.0
    second_eval_n = 0
    second_hits = 0
    count_dist = Counter()

    for row in rows:
        rule = choose_rule(row)
        seconds = opt.choose_seconds(row, rule)
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

    invest = points * 100.0
    return {
        "races": races,
        "tri_hits": tri_hits,
        "tri_rate": tri_hits / races if races else 0.0,
        "points": points,
        "avg_points": points / races if races else 0.0,
        "roi": payout_sum / invest if invest else 0.0,
        "second_eval_n": second_eval_n,
        "second_hits": second_hits,
        "second_rate": second_hits / second_eval_n if second_eval_n else 0.0,
        "count_dist": dict(count_dist),
    }


def choose_dev_rule(rows, rules_by_name, label):
    base = opt.evaluate(rows, BASE_RULE)
    candidates = []

    print(f"\n【OLD P1 DEV {label}：分岐候補】")
    print("方式              3連単的中  Hit保持  平均点数  点数削減    ROI    2着捕捉")
    print("-" * 88)
    print(
        f"{'FIXED_TOP3':<17} {base['tri_rate']*100:>7.2f}%  {100.0:>6.2f}%  "
        f"{base['avg_points']:>6.2f}   {0.0:>+7.2f}%  {base['roi']*100:>7.2f}%  "
        f"{base['second_rate']*100:>7.2f}%"
    )

    for name in RAW_NAMES:
        x = opt.evaluate(rows, rules_by_name[name])
        retention = x["tri_hits"] / base["tri_hits"] if base["tri_hits"] else 0.0
        point_cut = 1.0 - x["avg_points"] / base["avg_points"] if base["avg_points"] else 0.0
        print(
            f"{name:<17} {x['tri_rate']*100:>7.2f}%  {retention*100:>6.2f}%  "
            f"{x['avg_points']:>6.2f}   {point_cut*100:>+7.2f}%  {x['roi']*100:>7.2f}%  "
            f"{x['second_rate']*100:>7.2f}%"
        )
        if retention >= BALANCED_RETENTION and x["avg_points"] < base["avg_points"]:
            candidates.append((x["avg_points"], -retention, -x["roi"], name))

    if not candidates:
        print(f"→ {label} 採用: FIXED_TOP3（95%保持を満たすRAW候補なし）")
        return BASE_RULE

    candidates.sort()
    selected = rules_by_name[candidates[0][3]]
    print(f"→ {label} 採用: {selected['name']}（OLD P1のみで固定）")
    return selected


def print_compare(title, rows, global_rule, rule_1c, rule_non1c):
    base = evaluate_rows(rows, lambda _r: BASE_RULE)
    global_x = evaluate_rows(rows, lambda _r: global_rule)
    split_x = evaluate_rows(
        rows,
        lambda r: rule_1c if int(r["head_course"]) == 1 else rule_non1c,
    )

    print(f"\n{'=' * 112}\n{title}\n{'=' * 112}")
    print("方式                     3連単的中  Hit保持  平均点数  点数削減    ROI    2着捕捉  艇数内訳(1/2/3)")
    print("-" * 112)

    for name, x in (
        ("BASE Top3", base),
        ("GLOBAL CUM_RAW_70", global_x),
        (f"SPLIT {rule_1c['name']}/{rule_non1c['name']}", split_x),
    ):
        retention = x["tri_hits"] / base["tri_hits"] if base["tri_hits"] else 0.0
        point_cut = 1.0 - x["avg_points"] / base["avg_points"] if base["avg_points"] else 0.0
        d = x["count_dist"]
        print(
            f"{name:<25} {x['tri_rate']*100:>7.2f}%  {retention*100:>6.2f}%  "
            f"{x['avg_points']:>6.2f}   {point_cut*100:>+7.2f}%  {x['roi']*100:>7.2f}%  "
            f"{x['second_rate']*100:>7.2f}%  "
            f"{d.get(1,0):>4d}/{d.get(2,0):>4d}/{d.get(3,0):>4d}"
        )

    return base, global_x, split_x


def main():
    opt.ensure_files()
    rules_by_name = rule_map()
    global_rule = rules_by_name["CUM_RAW_70"]

    print("=" * 112)
    print("③ AI_FINAL：2着候補数 1C / NON1C 分岐検証")
    print("本番変更なし / 選定はOLD P1のみ / OLD P2・F1・F2で再調整なし")
    print("候補: CUM_RAW_65 / 70 / 75、保持条件: 各サブグループでTop3的中の95%以上")
    print("=" * 112)

    old_data, old_rows, _, _ = opt.load_pair(opt.OLD_P1, opt.OLD_P2)
    fwd_data, fwd_rows, _, _ = opt.load_pair(opt.F1, opt.F2)

    old_p1_1c = opt.subset(old_rows["P1"], "1C")
    old_p1_non1c = opt.subset(old_rows["P1"], "NON1C")

    rule_1c = choose_dev_rule(old_p1_1c, rules_by_name, "1C")
    rule_non1c = choose_dev_rule(old_p1_non1c, rules_by_name, "NON1C")

    print("\n【OLD P1だけで仕様固定した分岐】")
    print(f"1C    : {rule_1c['name']}")
    print(f"NON1C : {rule_non1c['name']}")

    print_compare("OLD P1 DEV ALL", old_rows["P1"], global_rule, rule_1c, rule_non1c)
    print_compare("OLD P2 VALID ALL", old_rows["P2"], global_rule, rule_1c, rule_non1c)
    print_compare("F1 FORWARD ALL", fwd_rows["P1"], global_rule, rule_1c, rule_non1c)
    print_compare("F2 FORWARD ALL", fwd_rows["P2"], global_rule, rule_1c, rule_non1c)

    combined = list(fwd_rows["P1"]) + list(fwd_rows["P2"])
    print_compare("F1+F2 FORWARD ALL", combined, global_rule, rule_1c, rule_non1c)
    print_compare("F1+F2 FORWARD 1C", opt.subset(combined, "1C"), global_rule, rule_1c, rule_non1c)
    print_compare("F1+F2 FORWARD NON1C", opt.subset(combined, "NON1C"), global_rule, rule_1c, rule_non1c)

    print("\n【判定メモ】")
    print("- SPLITはOLD P1だけで選んでいる。OLD P2/F1/F2を見てルールを差し替えない。")
    print("- GLOBAL RAW70よりSPLITが複数期間でHit保持を改善し、点数削減も残るなら分岐価値あり。")
    print("- 改善がOLD P1だけ、または期間ごとに逆転するならGLOBAL RAW70を優先する。")
    print("- 本番Webへの反映はこの検証だけでは行わない。")


if __name__ == "__main__":
    main()
