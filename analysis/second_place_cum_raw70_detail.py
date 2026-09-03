#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""CUM_RAW_70 の詳細検証。

目的
----
- 2着候補が1/2/3艇になったときの成績を分解する。
- FIXED_TOP3 と比べて削ったことで失った3連単的中を確認する。
- 本命1着時の2着取りこぼしについて、実2着艇のAI_FINAL順位・確率を確認する。
- ALL / 1C / NON1C、OLD P1 / OLD P2 / F1 / F2 / F1+F2 で同じ指標を見る。

本番ロジックは変更しない。
"""

from __future__ import annotations

from collections import Counter, defaultdict

import second_place_candidate_count_optimize as opt


RULE70 = {"name": "CUM_RAW_70", "kind": "CUM_RAW", "target": 0.70}
BASE = {"name": "FIXED_TOP3", "kind": "FIXED", "k": 3}


def pct(a, b):
    return 100.0 * a / b if b else 0.0


def avg(xs):
    return sum(xs) / len(xs) if xs else 0.0


def selected_count_detail(rows):
    buckets = defaultdict(list)
    for row in rows:
        seconds = opt.choose_seconds(row, RULE70)
        buckets[len(seconds)].append(row)

    print("\n  [2着候補数別]")
    print("  艇数      R   構成比  本命1着率  2着評価N  2着捕捉  3連単的中  平均点数      ROI")
    print("  " + "-" * 88)
    for k in (1, 2, 3):
        rr = buckets.get(k, [])
        ev = opt.evaluate(rr, RULE70) if rr else None
        if not rr:
            print(f"  {k}艇 {0:6d}   {0:6.2f}%")
            continue
        head_wins = sum(1 for r in rr if r["head_won"])
        print(
            f"  {k}艇 {len(rr):6d}   {pct(len(rr), len(rows)):6.2f}%   "
            f"{pct(head_wins, len(rr)):7.2f}%   {ev['second_eval_n']:7d}   "
            f"{ev['second_rate']*100:7.2f}%   {ev['tri_rate']*100:8.2f}%   "
            f"{ev['avg_points']:7.2f}   {ev['roi']*100:7.2f}%"
        )


def lost_hits_detail(rows):
    lost = []
    saved_points = []
    second_misses = []

    for row in rows:
        s70 = opt.choose_seconds(row, RULE70)
        s3 = opt.choose_seconds(row, BASE)
        t70 = opt.betutil.expand_formation(int(row["head"]), s70, list(row["thirds"]))
        t3 = opt.betutil.expand_formation(int(row["head"]), s3, list(row["thirds"]))
        saved_points.append(len(t3) - len(t70))

        if row["actual"] in t3 and row["actual"] not in t70:
            lost.append((row, s70, s3))

        if row["head_won"] and not row["actual_second_cut"] and int(row["actual_second"]) not in s70:
            second_misses.append((row, s70, s3))

    print("\n  [Top3固定から削ったことによる影響]")
    print(f"  Top3なら的中・RAW70では不的中 : {len(lost)}R ({pct(len(lost), len(rows)):.2f}% / 全R)")
    print(f"  1R平均削減点数                  : {avg(saved_points):.2f}点")

    rank_counter = Counter()
    selected_counter = Counter()
    prob_by_rank = defaultdict(list)
    cum_before_miss = []

    for row, s70, s3 in second_misses:
        order = [int(x) for x in row["orders"]["AI_FINAL_ALL_HEAD"]]
        actual_second = int(row["actual_second"])
        try:
            rank = order.index(actual_second) + 1
        except ValueError:
            rank = 99
        rank_counter[rank] += 1
        selected_counter[len(s70)] += 1
        p = float(row["ai_probs"].get(actual_second, 0.0))
        prob_by_rank[rank].append(p)
        cum_before_miss.append(sum(float(row["ai_probs"].get(b, 0.0)) for b in s70))

    print("\n  [本命1着時・実2着の取りこぼし]")
    eval_n = sum(1 for r in rows if r["head_won"] and not r["actual_second_cut"])
    print(f"  評価対象N       : {eval_n}")
    print(f"  取りこぼし     : {len(second_misses)} ({pct(len(second_misses), eval_n):.2f}%)")
    print(f"  候補数内訳1/2/3: {selected_counter.get(1,0)}/{selected_counter.get(2,0)}/{selected_counter.get(3,0)}")
    print("  実2着のAI_FINAL順位:")
    for rank in sorted(rank_counter):
        vals = prob_by_rank[rank]
        label = f"{rank}位" if rank != 99 else "圏外"
        print(f"    {label:<4} {rank_counter[rank]:5d}R  平均確率={avg(vals)*100:6.2f}%")
    if cum_before_miss:
        print(f"  取りこぼし時の選択済み累積確率平均: {avg(cum_before_miss)*100:.2f}%")

    print("\n  [代表的な取りこぼし 最大20件]")
    print("  race_code        頭/C  選択       実2着  実2着順位  実2着P   選択累積P")
    print("  " + "-" * 78)
    def miss_key(item):
        row, s70, _ = item
        actual_second = int(row["actual_second"])
        return float(row["ai_probs"].get(actual_second, 0.0))
    for row, s70, _ in sorted(second_misses, key=miss_key, reverse=True)[:20]:
        order = [int(x) for x in row["orders"]["AI_FINAL_ALL_HEAD"]]
        actual_second = int(row["actual_second"])
        rank = order.index(actual_second) + 1 if actual_second in order else 99
        p2 = float(row["ai_probs"].get(actual_second, 0.0))
        csum = sum(float(row["ai_probs"].get(b, 0.0)) for b in s70)
        sel = "-".join(str(x) for x in s70)
        print(
            f"  {row['race_code']:<16} {int(row['head'])}/{int(row['head_course'])}   "
            f"{sel:<9}  {actual_second:>3d}      {rank:>3d}位    {p2*100:6.2f}%    {csum*100:7.2f}%"
        )


def overall_compare(rows):
    b = opt.evaluate(rows, BASE)
    x = opt.evaluate(rows, RULE70)
    retention = x["tri_hits"] / b["tri_hits"] if b["tri_hits"] else 0.0
    cut = 1.0 - x["avg_points"] / b["avg_points"] if b["avg_points"] else 0.0
    print(
        f"  BASE Top3 : 3連単={b['tri_rate']*100:.2f}%  平均点数={b['avg_points']:.2f}  "
        f"ROI={b['roi']*100:.2f}%  2着捕捉={b['second_rate']*100:.2f}%"
    )
    print(
        f"  RAW70     : 3連単={x['tri_rate']*100:.2f}%  平均点数={x['avg_points']:.2f}  "
        f"ROI={x['roi']*100:.2f}%  2着捕捉={x['second_rate']*100:.2f}%"
    )
    print(f"  Hit保持={retention*100:.2f}%  点数削減={cut*100:.2f}%")


def show(title, rows):
    print("\n" + "=" * 108)
    print(title)
    print("=" * 108)
    overall_compare(rows)
    selected_count_detail(rows)
    lost_hits_detail(rows)


def main():
    opt.ensure_files()
    old_data, old_rows, _, _ = opt.load_pair(opt.OLD_P1, opt.OLD_P2)
    fwd_data, fwd_rows, _, _ = opt.load_pair(opt.F1, opt.F2)

    periods = [
        ("OLD P1 DEV", old_rows["P1"]),
        ("OLD P2 VALID", old_rows["P2"]),
        ("F1 FORWARD", fwd_rows["P1"]),
        ("F2 FORWARD", fwd_rows["P2"]),
        ("F1+F2 FORWARD", list(fwd_rows["P1"]) + list(fwd_rows["P2"])),
    ]

    print("=" * 108)
    print("③ AI_FINAL：CUM_RAW_70 詳細検証")
    print("本番変更なし / 閾値再調整なし")
    print("=" * 108)

    for name, rows in periods:
        show(name + " ALL", rows)

    fwd_all = list(fwd_rows["P1"]) + list(fwd_rows["P2"])
    show("F1+F2 FORWARD 1C", opt.subset(fwd_all, "1C"))
    show("F1+F2 FORWARD NON1C", opt.subset(fwd_all, "NON1C"))


if __name__ == "__main__":
    main()
