#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
③ AI_FINAL の120通り最終出目確率を使い、3着候補を枝ごとに動的に絞る検証。

前提
----
- 頭は現行本命で固定。
- 2着候補は仕様凍結候補 BALANCED CUM_RAW_70 を固定。
- 3着だけを追加で最適化する。
- 3着確率はレース共通ではなく、
    P(3着 | 現行本命が1着, その2着候補が2着)
  を120通りから直接集計する。
- kiru艇へ割り当てられた3着確率は再配分しない（RAW方式）。
- 本番Webは変更しない。

開発手順
--------
1. OLD P1 (2026-06-15～07-14) だけで3着ルールを選ぶ。
2. OLD P2 (2026-07-15～08-14) で再現性を見る。
3. F1/F2 (2026-08-15～08-31) は前方参考として固定ルールを確認する。
4. F1/F2を見て閾値を差し替えない。

3着は2着RAW70の後段なので、追加削減による的中低下を抑えるため
保持条件を2着最適化より厳しくする。

CONSERVATIVE : RAW70+3着ALL の3連単的中を99%以上保持
BALANCED     : 97%以上保持
AGGRESSIVE   : 95%以上保持

Usage
-----
python3 analysis/third_place_candidate_count_optimize.py
"""

from __future__ import annotations

from collections import Counter

import base_trifecta_probability_compare as base_outcome
import final_prediction_second_engine_all_head_compare as allhead
import second_place_candidate_count_optimize as second_opt
import trifecta_probability_order_compare as step3


TARGETS = (0.50, 0.55, 0.60, 0.65, 0.70, 0.75, 0.80, 0.85, 0.90)
PROFILES = (
    ("CONSERVATIVE", 0.99),
    ("BALANCED", 0.97),
    ("AGGRESSIVE", 0.95),
)

SECOND_RAW70 = {"name": "CUM_RAW_70", "kind": "CUM_RAW", "target": 0.70}
THIRD_ALL = {"name": "THIRD_ALL", "kind": "ALL"}


def build_rules():
    rules = [THIRD_ALL]
    for target in TARGETS:
        pct = int(round(target * 100))
        rules.append({"name": f"THIRD_CUM_RAW_{pct}", "kind": "CUM_RAW", "target": target})
    return rules


def build_contexts(data, exhibition_maps):
    """race_code -> 今回進入へ整列済み120通り確率と艇番->コース。"""
    out = {}
    for period in ("P1", "P2"):
        for record in data["records"][period]:
            code = str(record["race_code"])
            if code in exhibition_maps:
                course_map = exhibition_maps[code]
            else:
                course_map = allhead.valid_course_map(record.get("course_by_lane", {}))
            if course_map is None:
                continue

            aligned = allhead.align_record_to_course_map(record, course_map)
            if aligned is None:
                continue
            probs120 = step3.order_adjusted_probs(aligned, allhead.FINAL_DELTA, allhead.FINAL_GAMMA)
            out[code] = {
                "course_map": dict(course_map),
                "probs120": probs120,
            }
    return out


def load_pair_with_context(p1_csv, p2_csv):
    data, rows, skip, source = second_opt.load_pair(p1_csv, p2_csv)
    exhibition_maps = allhead.load_exhibition_course_maps(data["p1_start"], data["p2_end"])
    contexts = build_contexts(data, exhibition_maps)
    return data, rows, contexts, skip, source


def conditional_third_probs(row, context, second_lane):
    """
    P(3着艇 | head=1着, second_lane=2着) を艇番で返す。
    ここではkiru除外前のRAW分布を返す。
    """
    head = int(row["head"])
    second_lane = int(second_lane)
    cmap = context["course_map"]
    probs120 = context["probs120"]

    head_course = int(cmap[head])
    second_course = int(cmap[second_lane])
    lane_by_course = {int(c): int(lane) for lane, c in cmap.items()}

    scores_course = {
        c: 0.0 for c in range(1, 7)
        if c not in (head_course, second_course)
    }
    branch_mass = 0.0

    for idx, pattern in enumerate(base_outcome.PATTERNS):
        first_c, second_c, third_c = (int(x) for x in pattern)
        if first_c != head_course or second_c != second_course:
            continue
        p = max(0.0, float(probs120[idx]))
        if third_c in scores_course:
            scores_course[third_c] += p
        branch_mass += p

    if branch_mass <= 0.0:
        return None

    out = {}
    for course, score in scores_course.items():
        lane = lane_by_course.get(int(course))
        if lane is None:
            return None
        out[int(lane)] = float(score) / branch_mass

    total = sum(out.values())
    if total <= 0.0:
        return None
    # 数値誤差だけ補正。kiru除外はこの後なのでここでは全4艇で1になる。
    return {lane: p / total for lane, p in out.items()}


def choose_thirds(row, context, second_lane, rule):
    second_lane = int(second_lane)
    eligible = [
        int(b) for b in row["thirds"]
        if int(b) != second_lane and int(b) != int(row["head"])
    ]
    if not eligible:
        return []

    probs = conditional_third_probs(row, context, second_lane)
    if probs is None:
        return list(eligible)

    order = sorted(eligible, key=lambda b: (-float(probs.get(b, 0.0)), b))
    if rule["kind"] == "ALL":
        return order

    selected = []
    cumulative = 0.0
    target = float(rule["target"])
    for b in order:
        selected.append(int(b))
        # RAW: kiru艇の確率を買える艇へ再配分しない。
        cumulative += max(0.0, float(probs.get(int(b), 0.0)))
        if cumulative >= target:
            break
    return selected


def build_tickets(row, context, third_rule):
    seconds = second_opt.choose_seconds(row, SECOND_RAW70)
    tickets = set()
    branch_counts = []
    branch_thirds = {}

    for second in seconds:
        thirds = choose_thirds(row, context, int(second), third_rule)
        branch_thirds[int(second)] = list(thirds)
        branch_counts.append(len(thirds))
        for third in thirds:
            if int(third) in (int(row["head"]), int(second)):
                continue
            tickets.add((int(row["head"]), int(second), int(third)))

    return tickets, seconds, branch_counts, branch_thirds


def evaluate(rows, contexts, third_rule):
    races = 0
    points = 0
    tri_hits = 0
    payout_sum = 0.0
    third_eval_n = 0
    third_hits = 0
    branch_dist = Counter()
    race_point_dist = Counter()
    missing_context = 0

    for row in rows:
        code = str(row["race_code"])
        context = contexts.get(code)
        if context is None:
            missing_context += 1
            continue

        tickets, seconds, branch_counts, branch_thirds = build_tickets(row, context, third_rule)
        if not tickets:
            continue

        races += 1
        points += len(tickets)
        race_point_dist[len(tickets)] += 1
        for n in branch_counts:
            branch_dist[int(n)] += 1

        actual = tuple(int(x) for x in row["actual"])
        actual_second = int(actual[1])
        actual_third = int(actual[2])

        # 3着エンジン単体の捕捉率：頭と2着RAW70が当たっており、実3着が非kiruの時だけ評価。
        if row["head_won"] and actual_second in seconds and actual_third in [int(x) for x in row["thirds"]]:
            third_eval_n += 1
            if actual_third in branch_thirds.get(actual_second, []):
                third_hits += 1

        if actual in tickets:
            tri_hits += 1
            if float(row["payout"]) > 0.0:
                payout_sum += float(row["payout"])

    invest = points * 100.0
    branches = sum(branch_dist.values())
    avg_thirds = (
        sum(k * v for k, v in branch_dist.items()) / branches
        if branches else 0.0
    )
    return {
        "name": third_rule["name"],
        "races": races,
        "points": points,
        "avg_points": points / races if races else 0.0,
        "tri_hits": tri_hits,
        "tri_rate": tri_hits / races if races else 0.0,
        "roi": payout_sum / invest if invest else 0.0,
        "third_eval_n": third_eval_n,
        "third_hits": third_hits,
        "third_rate": third_hits / third_eval_n if third_eval_n else 0.0,
        "avg_thirds": avg_thirds,
        "branch_dist": dict(branch_dist),
        "race_point_dist": dict(race_point_dist),
        "missing_context": missing_context,
    }


def print_grid(title, rows, contexts, rules):
    results = [evaluate(rows, contexts, rule) for rule in rules]
    base = results[0]

    print(f"\n【{title}】")
    print(
        "方式                   3着艇数  3着捕捉  3連単的中  RAW70保持  平均点数  追加削減    ROI      枝内訳(1/2/3/4)"
    )
    print("-" * 126)
    for x in results:
        retention = x["tri_hits"] / base["tri_hits"] if base["tri_hits"] else 0.0
        point_cut = 1.0 - x["avg_points"] / base["avg_points"] if base["avg_points"] else 0.0
        d = x["branch_dist"]
        print(
            f"{x['name']:<22} "
            f"{x['avg_thirds']:>6.2f}   "
            f"{x['third_rate']*100:>6.2f}%   "
            f"{x['tri_rate']*100:>7.2f}%   "
            f"{retention*100:>7.2f}%   "
            f"{x['avg_points']:>6.2f}   "
            f"{point_cut*100:>+7.2f}%   "
            f"{x['roi']*100:>7.2f}%   "
            f"{d.get(1,0):>4d}/{d.get(2,0):>4d}/{d.get(3,0):>4d}/{d.get(4,0):>4d}"
        )
    return {x["name"]: x for x in results}


def select_profiles(dev_results, rules_by_name):
    base = dev_results["THIRD_ALL"]
    selected = {}

    for profile, min_retention in PROFILES:
        candidates = []
        for name, x in dev_results.items():
            if not name.startswith("THIRD_CUM_RAW_"):
                continue
            retention = x["tri_hits"] / base["tri_hits"] if base["tri_hits"] else 0.0
            if retention < min_retention:
                continue
            if x["avg_points"] >= base["avg_points"]:
                continue
            candidates.append((x["avg_points"], -retention, -x["roi"], name))

        if not candidates:
            selected[profile] = THIRD_ALL
            continue
        candidates.sort()
        selected[profile] = rules_by_name[candidates[0][3]]

    return selected


def print_selected_period(title, rows, contexts, selected):
    raw70_base = evaluate(rows, contexts, THIRD_ALL)
    original_top3 = second_opt.evaluate(rows, {"name": "FIXED_TOP3", "kind": "FIXED", "k": 3})

    print(f"\n【{title}：OLD P1だけで固定した3着候補】")
    print(
        "Profile       方式                   3連単的中  RAW70保持  元Top3保持  平均点数  追加削減  総削減     ROI    3着捕捉"
    )
    print("-" * 122)

    raw_vs_orig = raw70_base["tri_hits"] / original_top3["tri_hits"] if original_top3["tri_hits"] else 0.0
    raw_total_cut = 1.0 - raw70_base["avg_points"] / original_top3["avg_points"] if original_top3["avg_points"] else 0.0
    print(
        f"{'RAW70_BASE':<13} {'THIRD_ALL':<22} {raw70_base['tri_rate']*100:>7.2f}%  "
        f"{100.0:>7.2f}%   {raw_vs_orig*100:>7.2f}%   {raw70_base['avg_points']:>6.2f}   "
        f"{0.0:>+7.2f}%  {raw_total_cut*100:>+7.2f}%  {raw70_base['roi']*100:>7.2f}%  "
        f"{raw70_base['third_rate']*100:>7.2f}%"
    )

    for profile, _min_retention in PROFILES:
        rule = selected[profile]
        x = evaluate(rows, contexts, rule)
        raw_ret = x["tri_hits"] / raw70_base["tri_hits"] if raw70_base["tri_hits"] else 0.0
        orig_ret = x["tri_hits"] / original_top3["tri_hits"] if original_top3["tri_hits"] else 0.0
        add_cut = 1.0 - x["avg_points"] / raw70_base["avg_points"] if raw70_base["avg_points"] else 0.0
        total_cut = 1.0 - x["avg_points"] / original_top3["avg_points"] if original_top3["avg_points"] else 0.0
        print(
            f"{profile:<13} {rule['name']:<22} {x['tri_rate']*100:>7.2f}%  "
            f"{raw_ret*100:>7.2f}%   {orig_ret*100:>7.2f}%   {x['avg_points']:>6.2f}   "
            f"{add_cut*100:>+7.2f}%  {total_cut*100:>+7.2f}%  {x['roi']*100:>7.2f}%  "
            f"{x['third_rate']*100:>7.2f}%"
        )


def main():
    second_opt.ensure_files()
    rules = build_rules()
    rules_by_name = {rule["name"]: rule for rule in rules}

    print("=" * 140)
    print("③ AI_FINAL：3着候補数 動的最適化（2着はBALANCED CUM_RAW_70固定）")
    print("=" * 140)
    print("3着確率   : P(3着 | 現行本命=1着, 各RAW70 2着候補=2着) を120通りから集計")
    print("kiru確率  : 再配分しないRAW方式")
    print("ルール選定: OLD P1だけ。OLD P2/F1/F2では再調整しない")
    print("保持条件   : CONSERVATIVE 99% / BALANCED 97% / AGGRESSIVE 95%（RAW70+3着ALL比）")
    print("本番変更   : なし")

    old_data, old_rows, old_ctx, _, _ = load_pair_with_context(second_opt.OLD_P1, second_opt.OLD_P2)
    fwd_data, fwd_rows, fwd_ctx, _, _ = load_pair_with_context(second_opt.F1, second_opt.F2)

    print("\n【期間】")
    print(f"OLD P1 DEV       : {old_data['p1_start']} ～ {old_data['p1_end']} / {len(old_rows['P1'])}R")
    print(f"OLD P2 VALID     : {old_data['p2_start']} ～ {old_data['p2_end']} / {len(old_rows['P2'])}R")
    print(f"F1 FORWARD REF   : {fwd_data['p1_start']} ～ {fwd_data['p1_end']} / {len(fwd_rows['P1'])}R")
    print(f"F2 FORWARD REF   : {fwd_data['p2_start']} ～ {fwd_data['p2_end']} / {len(fwd_rows['P2'])}R")

    dev_results = print_grid("OLD P1 DEV：全3着ルール", old_rows["P1"], old_ctx, rules)
    print_grid("OLD P2 VALID：全3着ルール（再調整なし）", old_rows["P2"], old_ctx, rules)

    selected = select_profiles(dev_results, rules_by_name)
    print("\n【OLD P1で固定した3プロファイル】")
    for profile, min_retention in PROFILES:
        rule = selected[profile]
        print(f"{profile:<13}: {rule['name']}（RAW70+3着ALL 的中保持 {min_retention*100:.0f}% 条件）")

    periods = [
        ("OLD P1 DEV", old_rows["P1"], old_ctx),
        ("OLD P2 VALID", old_rows["P2"], old_ctx),
        ("F1 FORWARD", fwd_rows["P1"], fwd_ctx),
        ("F2 FORWARD", fwd_rows["P2"], fwd_ctx),
    ]
    for title, rows, ctx in periods:
        print_selected_period(title, rows, ctx, selected)

    combined = list(fwd_rows["P1"]) + list(fwd_rows["P2"])
    print_selected_period("F1+F2 FORWARD", combined, fwd_ctx, selected)

    print("\n【判定メモ】")
    print("- 3着は2着RAW70の後段なので、RAW70比の保持率を最優先する。")
    print("- BALANCEDは追加段階のため97%保持を基準にした。")
    print("- 元Top3保持も併記し、2着削減+3着削減の累積ダメージを見る。")
    print("- F1/F2で良く見えても閾値は差し替えない。")
    print("- この結果だけで本番Webへは反映しない。")


if __name__ == "__main__":
    main()
