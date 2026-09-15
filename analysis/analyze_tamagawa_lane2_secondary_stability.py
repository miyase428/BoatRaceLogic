#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""多摩川2Cの一次候補に当日二次評価を重ね、候補条件を比較する。"""

from __future__ import annotations

import csv
import sys
from collections import OrderedDict
from datetime import date, timedelta
from pathlib import Path


def num(v):
    try: return float(v)
    except (TypeError, ValueError): return None


def integer(v):
    x = num(v)
    return None if x is None else int(x)


def pct(n, d): return 100.0 * n / d if d else 0.0


def months_ago(d: date, months: int) -> date:
    total = d.year * 12 + d.month - 1 - months
    y, m = divmod(total, 12)
    return date(y, m + 1, d.day)


def load(path: Path):
    rows = []
    with path.open("r", encoding="utf-8-sig", newline="") as f:
        for raw in csv.DictReader(f):
            rows.append({
                "date": date.fromisoformat(raw["race_date"]),
                "sashi": num(raw["p2_12_sashi"]), "makuri": num(raw["p2_12_makuri"]), "attack": num(raw["p2_12_attack"]),
                "win": num(raw["p2_12_win"]), "n": num(raw["p2_12_n"]),
                "score": num(raw["second_score"]), "rank": integer(raw["second_rank"]),
                "gap": num(raw["gap"]), "st": num(raw["st_score"]),
                "straight": num(raw["straight_score"]), "ex": num(raw["ex_score"]),
                "lap": num(raw["lap_score"]), "mawari": num(raw["mawari_score"]),
                "st21": raw["st21"], "first": integer(raw["first"]),
                "second": integer(raw["second"]), "third": integer(raw["third"]),
            })
    return rows


PRIMARY = OrderedDict([
    ("S10", "差し率10%以上"), ("W15", "過去1着率15%以上"),
    ("S10_ST", "差し率10%以上×ST上"), ("M5_ST", "まくり率5%以上×ST上"),
    ("A10_ST", "攻め率10%以上×ST上"),
    ("A15", "攻め率15%以上"),
])
SECONDARY = OrderedDict([
    ("R1", "二次順位1位"), ("R2", "二次順位2位以内"), ("R3", "二次順位3位以内"),
    ("LAP4", "周回評価4以上"), ("LAP5", "周回評価5"),
    ("STRAIGHT4", "直線評価4以上"), ("MAWARI4", "周り足評価4以上"),
    ("ST5", "ST評価5"), ("ATTACK9", "展示攻め成分9以上"),
    ("R3_OR_LAP4", "二次3位以内または周回4以上"),
    ("R3_AND_LAP4", "二次3位以内かつ周回4以上"),
    ("R1_OR_LAP4", "二次1位または周回4以上"),
])


def primary(r, key):
    if r["n"] is None or r["n"] <= 0: return False
    return {
        "S10": r["sashi"] >= 10, "W15": r["win"] >= 15,
        "S10_ST": r["sashi"] >= 10 and r["st21"] == "内側より上",
        "M5_ST": r["makuri"] >= 5 and r["st21"] == "内側より上",
        "A10_ST": r["attack"] >= 10 and r["st21"] == "内側より上",
        "A15": r["attack"] >= 15,
    }[key]


def secondary(r, key):
    if r["rank"] is None: return False
    return {
        "R1": r["rank"] == 1, "R2": r["rank"] <= 2, "R3": r["rank"] <= 3,
        "LAP4": r["lap"] is not None and r["lap"] >= 4,
        "LAP5": r["lap"] is not None and r["lap"] >= 5,
        "STRAIGHT4": r["straight"] is not None and r["straight"] >= 4,
        "MAWARI4": r["mawari"] is not None and r["mawari"] >= 4,
        "ST5": r["st"] is not None and r["st"] >= 5,
        "ATTACK9": r["attack"] is not None and r["attack"] >= 9,
        "R3_OR_LAP4": r["rank"] <= 3 or (r["lap"] is not None and r["lap"] >= 4),
        "R3_AND_LAP4": r["rank"] <= 3 and r["lap"] is not None and r["lap"] >= 4,
        "R1_OR_LAP4": r["rank"] == 1 or (r["lap"] is not None and r["lap"] >= 4),
    }[key]


def stat(rows, start, end, pkey, skey=None):
    selected = [r for r in rows if start <= r["date"] <= end and primary(r, pkey)
                and r["rank"] is not None and (skey is None or secondary(r, skey))]
    n = len(selected); first = sum(r["first"] == 2 for r in selected)
    second = sum(r["second"] == 2 for r in selected); third = sum(r["third"] == 2 for r in selected)
    return {"n": n, "head": pct(first, n), "top2": pct(first + second, n), "top3": pct(first + second + third, n)}


def windows(end):
    anchor = end + timedelta(days=1)
    longs = [("24ヶ月", months_ago(anchor, 24), end), ("18ヶ月", months_ago(anchor, 18), end), ("12ヶ月", months_ago(anchor, 12), end)]
    blocks = []
    for i, label in enumerate(("0-6ヶ月", "6-12ヶ月", "12-18ヶ月", "18-24ヶ月")):
        blocks.append((label, months_ago(anchor, (i + 1) * 6), months_ago(anchor, i * 6) - timedelta(days=1)))
    return longs, blocks


def print_candidate(rows, start, end, pkey, skey, base):
    s = stat(rows, start, end, pkey, skey)
    print(f"{pkey:<7}+{skey:<13} N={s['n']:4d}  2頭={s['head']:6.2f}% ({s['head']-base['head']:+6.2f}pt)  2連={s['top2']:6.2f}% ({s['top2']-base['top2']:+6.2f}pt)  3連={s['top3']:6.2f}% ({s['top3']-base['top3']:+6.2f}pt)")
    return s


def main():
    if len(sys.argv) != 2: raise SystemExit("Usage: ... CSV_PATH")
    rows = load(Path(sys.argv[1])); end = max(r["date"] for r in rows)
    longs, blocks = windows(end)
    print("=" * 150); print("多摩川2コース：一次条件×二次評価の候補比較と安定性"); print("=" * 150)
    for label, start, finish in longs:
        print(f"\n■ {label} {start}～{finish}")
        for pkey, ptitle in PRIMARY.items():
            base = stat(rows, start, finish, pkey)
            print(f"\n{pkey} {ptitle}（二次評価あり） 基準 N={base['n']} 2頭={base['head']:.2f}% 2連={base['top2']:.2f}% 3連={base['top3']:.2f}%")
            for skey, stitle in SECONDARY.items():
                print_candidate(rows, start, finish, pkey, skey, base)
    print("\n【6ヶ月×4：各一次条件に対する二次候補の再現性】")
    for pkey, ptitle in PRIMARY.items():
        print(f"\n{pkey} {ptitle}")
        for skey, stitle in SECONDARY.items():
            vals = [(stat(rows, s, e, pkey, skey), stat(rows, s, e, pkey)) for _, s, e in blocks]
            valid = [(x, b) for x, b in vals if x["n"] >= 20]
            wins = sum(x["head"] > b["head"] for x, b in valid)
            top2 = sum(x["top2"] > b["top2"] for x, b in valid)
            top3 = sum(x["top3"] > b["top3"] for x, b in valid)
            v24 = stat(rows, longs[0][1], longs[0][2], pkey, skey)
            print(f"{skey:<13} {stitle:<22} N24={v24['n']:4d} 区間超え=頭{wins}/{len(valid)}・2連{top2}/{len(valid)}・3連{top3}/{len(valid)}")
    print("\n注意: 二次評価が取得できた行のみ。N<20の区間は安定性判定から除外。")


if __name__ == "__main__": main()
