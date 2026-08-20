#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
最終予想 AI活用 STEP B2：相手候補選定

目的
----
本命と切る艇は現行のまま固定し、2着候補（相手候補）だけを
AI情報で並べ替えると改善するかをP1/P2で比較する。

比較
----
CURRENT_AITE
    現行buildSummary()と同じく、現行rank_boatsから
    本命・切る艇を除外して上位最大3艇。

TRIO_AITE
    同じ候補集合からENTRY_MODE AI3連対率の高い順に最大3艇。

OUTCOME_SECOND_AITE
    STEP3最終120通りから、現行本命を1着に固定したときの
    2着周辺確率 P(2着艇 | head) が高い順に最大3艇。

重要
----
- 本命は現行本命のまま。
- kiruも現行CSVのまま。
- 今回は「相手候補の順位付け」だけを比較する。
- 実2着がkiruの場合はB2では救済しない（B3で検証）。
- 本番ロジックは変更しない。

Usage:
python3 analysis/final_prediction_ai_opponent_compare.py \
  analysis/output/final_prediction_boats_20260615_20260714_OLD.csv \
  analysis/output/final_prediction_boats_20260715_20260814_OLD.csv
"""

from __future__ import annotations

import csv
import sys
from collections import Counter, defaultdict
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import final_prediction_ai_favorite_compare as b1
import trifecta_probability_order_compare as step3

ORDER_DELTA = 0.25
ORDER_GAMMA = 0.25


def fnum(v, default=0.0):
    try:
        if v is None or str(v).strip() == "":
            return default
        return float(v)
    except (TypeError, ValueError):
        return default


def inum(v, default=0):
    try:
        if v is None or str(v).strip() == "":
            return default
        return int(float(v))
    except (TypeError, ValueError):
        return default


def load_boats(*paths):
    races = {}
    required = {
        "race_code", "lane_number",
        "first_total_score", "first_rank",
        "second_score", "second_rank",
        "final_rank", "final3", "kiru", "actual_rank",
    }
    for path in paths:
        with open(path, "r", encoding="utf-8-sig", newline="") as fh:
            reader = csv.DictReader(fh)
            fields = set(reader.fieldnames or [])
            missing = sorted(required - fields)
            if missing:
                raise RuntimeError(f"{path}: 必須列不足: {', '.join(missing)}")
            for row in reader:
                code = str(row.get("race_code", "")).strip()
                lane = inum(row.get("lane_number"))
                if not code or lane not in range(1, 7):
                    continue
                races.setdefault(code, {})[lane] = {
                    "lane": lane,
                    "first_score": fnum(row.get("first_total_score")),
                    "first_rank": inum(row.get("first_rank")),
                    "second_score": fnum(row.get("second_score")),
                    "second_rank": inum(row.get("second_rank")),
                    "final_rank": inum(row.get("final_rank")),
                    "final3": fnum(row.get("final3")),
                    "kiru": inum(row.get("kiru")),
                    "actual_rank": fnum(row.get("actual_rank"), 0.0),
                }
    return races


def current_order_and_head(boats):
    if set(boats) != set(range(1, 7)):
        return None, None

    ordered = sorted(
        boats.values(),
        key=lambda x: (x["final_rank"] if x["final_rank"] > 0 else 999, -x["final3"], x["lane"]),
    )
    rank_boats = [int(x["lane"]) for x in ordered]

    first1 = next((b for b in boats.values() if b["first_rank"] == 1), None)
    first2 = next((b for b in boats.values() if b["first_rank"] == 2), None)
    second1 = next((b for b in boats.values() if b["second_rank"] == 1), None)
    second2 = next((b for b in boats.values() if b["second_rank"] == 2), None)
    if None in (first1, first2, second1, second2) or not rank_boats:
        return None, None

    first_gap = first1["first_score"] - first2["first_score"]
    second_gap = second1["second_score"] - second2["second_score"]
    if 5.0 <= first_gap < 10.0 and 1.0 <= second_gap < 2.0:
        p = int(first1["lane"])
        if rank_boats[0] != p:
            rank_boats = [p] + [x for x in rank_boats if x != p]

    return rank_boats, rank_boats[0]


def outcome_second_scores(record, head):
    probs = step3.order_adjusted_probs(record, ORDER_DELTA, ORDER_GAMMA)
    scores = {lane: 0.0 for lane in range(1, 7) if lane != head}
    mass = 0.0
    for idx, lanes in enumerate(record["pattern_lanes"]):
        if int(lanes[0]) != head:
            continue
        p = float(probs[idx])
        mass += p
        second = int(lanes[1])
        if second != head:
            scores[second] = scores.get(second, 0.0) + p
    if mass > 0:
        scores = {lane: p / mass for lane, p in scores.items()}
    return scores


def select_current(rank_boats, head, kiru, k):
    return [b for b in rank_boats if b != head and b not in kiru][:k]


def select_score(scores, eligible, k):
    return sorted(eligible, key=lambda b: (-float(scores.get(b, 0.0)), b))[:k]


def build_rows(common, csv_races):
    out = {"P1": [], "P2": []}
    skip = defaultdict(int)

    for period in ("P1", "P2"):
        for record in common[period]:
            code = str(record["race_code"])
            boats = csv_races.get(code)
            if boats is None or set(boats) != set(range(1, 7)):
                skip[f"{period}_csv_missing"] += 1
                continue

            rank_boats, head = current_order_and_head(boats)
            if not rank_boats or head is None:
                skip[f"{period}_current_invalid"] += 1
                continue

            second_lanes = [lane for lane, b in boats.items() if b["actual_rank"] == 2.0]
            if len(second_lanes) != 1:
                skip[f"{period}_actual_second_invalid"] += 1
                continue
            actual_second = int(second_lanes[0])

            actual_head_rank = float(boats[head]["actual_rank"])
            kiru = {lane for lane, b in boats.items() if b["kiru"] == 1}
            eligible = [lane for lane in range(1, 7) if lane != head and lane not in kiru]
            if not eligible:
                skip[f"{period}_eligible_empty"] += 1
                continue

            k = min(3, len(eligible))
            current = select_current(rank_boats, head, kiru, k)

            _, trio, _ = b1.marginal_signals(record)
            outcome_second = outcome_second_scores(record, head)

            trio_pick = select_score(trio, eligible, k)
            outcome_pick = select_score(outcome_second, eligible, k)

            out[period].append({
                "race_code": code,
                "head": head,
                "head_won": actual_head_rank == 1.0,
                "actual_second": actual_second,
                "actual_second_cut": actual_second in kiru,
                "eligible": eligible,
                "k": k,
                "CURRENT_AITE": current,
                "TRIO_AITE": trio_pick,
                "OUTCOME_SECOND_AITE": outcome_pick,
            })
            skip[f"{period}_ready"] += 1

    return out, skip


def evaluate(rows, method, predicate=lambda r: True):
    selected = [r for r in rows if predicate(r)]
    n = len(selected)
    hit = sum(1 for r in selected if r["actual_second"] in r[method])
    return n, hit, (hit / n if n else 0.0)


def compare_to_current(rows, method, predicate=lambda r: True):
    changed = gained = lost = same_hit = same_miss = 0
    for r in rows:
        if not predicate(r):
            continue
        cur = set(r["CURRENT_AITE"])
        new = set(r[method])
        if cur != new:
            changed += 1
        c = r["actual_second"] in cur
        a = r["actual_second"] in new
        if a and not c:
            gained += 1
        elif c and not a:
            lost += 1
        elif a and c:
            same_hit += 1
        else:
            same_miss += 1
    return changed, gained, lost, same_hit, same_miss


def rank_capture(rows, method, predicate=lambda r: True):
    counts = Counter()
    n = 0
    for r in rows:
        if not predicate(r):
            continue
        n += 1
        try:
            pos = r[method].index(r["actual_second"]) + 1
        except ValueError:
            pos = 0
        if pos:
            counts[pos] += 1
    top1 = counts[1] / n if n else 0.0
    top2 = (counts[1] + counts[2]) / n if n else 0.0
    top3 = (counts[1] + counts[2] + counts[3]) / n if n else 0.0
    return n, top1, top2, top3


def print_period(title, rows):
    print(f"\n【{title}】")
    print("方式                     全R捕捉   head1着時捕捉   head1着&非cut捕捉   Top1    Top2    Top3")
    print("-" * 108)

    p_head = lambda r: r["head_won"]
    p_pure = lambda r: r["head_won"] and not r["actual_second_cut"]

    for method in ("CURRENT_AITE", "TRIO_AITE", "OUTCOME_SECOND_AITE"):
        n_all, h_all, p_all = evaluate(rows, method)
        n_h, h_h, p_h = evaluate(rows, method, p_head)
        n_p, h_p, p_p = evaluate(rows, method, p_pure)
        _, t1, t2, t3 = rank_capture(rows, method, p_pure)
        print(
            f"{method:<25} {h_all:>4d}/{n_all:<4d} {p_all*100:>6.2f}%   "
            f"{h_h:>4d}/{n_h:<4d} {p_h*100:>6.2f}%      "
            f"{h_p:>4d}/{n_p:<4d} {p_p*100:>6.2f}%      "
            f"{t1*100:>6.2f}% {t2*100:>6.2f}% {t3*100:>6.2f}%"
        )

    print("\nCURRENTからの差（head1着時）")
    for method in ("TRIO_AITE", "OUTCOME_SECOND_AITE"):
        ch, gain, lost, both, miss = compare_to_current(rows, method, p_head)
        _, _, cur_rate = evaluate(rows, "CURRENT_AITE", p_head)
        _, _, new_rate = evaluate(rows, method, p_head)
        print(
            f"{method:<25} 変更={ch}R / 拾い={gain}R / 失い={lost}R "
            f"/ 捕捉率差={(new_rate-cur_rate)*100:+.2f}pt"
        )

    head_n = sum(1 for r in rows if r["head_won"])
    cut_n = sum(1 for r in rows if r["head_won"] and r["actual_second_cut"])
    print(
        f"head1着レース={head_n}R / そのうち実2着が現行kiru={cut_n}R "
        f"({(cut_n/head_n*100 if head_n else 0.0):.2f}%)"
    )


def main():
    if len(sys.argv) != 3:
        print("Usage: python3 analysis/final_prediction_ai_opponent_compare.py P1_BOATS_CSV P2_BOATS_CSV")
        sys.exit(1)

    p1_csv, p2_csv = sys.argv[1], sys.argv[2]
    print("STEP3共通AIデータと現行最終予想CSVを結合中...")
    data = step3.build_common_records(p1_csv, p2_csv)
    csv_races = load_boats(p1_csv, p2_csv)
    rows, skip = build_rows(data["records"], csv_races)

    print("=" * 126)
    print("最終予想 AI活用 STEP B2：相手候補選定")
    print("=" * 126)
    print(f"P1                  : {data['p1_start']} ～ {data['p1_end']}")
    print(f"P2完全ホールドアウト: {data['p2_start']} ～ {data['p2_end']}")
    print("本命                 : CURRENT固定")
    print("切る艇               : 現行kiru固定")
    print("CURRENT_AITE         : 現行最終順位から上位最大3艇")
    print("TRIO_AITE            : AI3連対率から上位最大3艇")
    print("OUTCOME_SECOND_AITE  : STEP3出目モデルのP(2着|現行本命頭)上位最大3艇")
    print("本番Web変更          : なし")
    print(f"\n【共通評価母集団】P1={len(rows['P1'])}R / P2={len(rows['P2'])}R")

    print_period("P1 参考", rows["P1"])
    print_period("P2 ホールドアウト（最重要）", rows["P2"])

    print("\n【判断方針】")
    print("1. 最重要はP2のhead1着時2着捕捉率")
    print("2. head1着&実2着非cutは、相手候補順位付けだけを純粋比較する指標")
    print("3. AI方式で拾い>失い、捕捉率が改善するか確認")
    print("4. 実2着がkiruだった割合はB3の救済余地として別管理")
    print("5. B2では本命・kiru・本番ロジックを変更しない")
    print("=" * 126)


if __name__ == "__main__":
    main()
