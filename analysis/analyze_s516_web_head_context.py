#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
STEP9.5 芦屋6R型（5Cまくり差し>=15%）を、事前Web本命別に絞って検証する。

固定条件:
- 正式分析対象のみ
- 5C 6month sample_n >= 10
- 5C まくり差し率 >= 15%

比較:
- S5Z15: 5-1-*（3着=2,3,4,6 の4点）
- S516 : 5-1-6（1点）

特に「事前Web本命=1」を芦屋6R型として確認する。
⑤⑥kimarite頭補正は元本命5/6にだけ発動するため、元本命1の集合は
本番CURRENTでも本命1のまま。A3/A4/H3も頭は変えないので、この集合は
現行Webの「本命1」条件としてそのまま使える。

払戻CSVが6か月分だけの場合、ROIは払戻が存在するレースだけで計算する。

Usage:
  python3 analysis/analyze_s516_web_head_context.py \
    analysis/output/kimarite_analysis_dataset_20250815_20260814.csv \
    analysis/output/trifecta_payouts_20260215_20260814.csv
"""

from __future__ import annotations

import csv
import sys
from collections import defaultdict
from datetime import date
from pathlib import Path

SPLIT_DATE = date(2026, 2, 15)


def to_int(v, default=0):
    try:
        return int(float(v))
    except (TypeError, ValueError):
        return default


def to_float(v, default=0.0):
    try:
        return float(v)
    except (TypeError, ValueError):
        return default


def load_csv(path: Path):
    with path.open("r", encoding="utf-8-sig", newline="") as f:
        return list(csv.DictReader(f))


def load_payouts(path: Path):
    out = {}
    with path.open("r", encoding="utf-8-sig", newline="") as f:
        for r in csv.DictReader(f):
            rc = (r.get("race_code") or "").strip()
            p = to_int(r.get("trifecta_payout"), -1)
            if rc and p >= 0:
                out[rc] = p
    return out


def formal(r):
    return (
        to_int(r.get("result_top3_course_complete")) == 1
        and to_int(r.get("result_boat_match")) == 1
    )


def condition(r):
    return (
        to_int(r.get("c5_6m_sample_n")) >= 10
        and to_float(r.get("c5_6m_makurizashi")) >= 15.0
    )


def period_name(r):
    d = date.fromisoformat((r.get("race_date") or "").strip())
    return "前半" if d < SPLIT_DATE else "後半"


def group_names(r):
    h = to_int(r.get("honmei_head"))
    return [
        "ALL",
        "WEB本命1" if h == 1 else "WEB本命1以外",
    ]


def ticket_hit(r, kind):
    a1 = to_int(r.get("actual_1st"))
    a2 = to_int(r.get("actual_2nd"))
    a3 = to_int(r.get("actual_3rd"))
    if kind == "S516":
        return (a1, a2, a3) == (5, 1, 6)
    if kind == "S5Z15":
        return a1 == 5 and a2 == 1 and a3 in {2, 3, 4, 6}
    raise ValueError(kind)


def empty_stat():
    return {
        "n": 0,
        "hits": 0,
        "payout_n": 0,
        "payout_hits": 0,
        "return": 0,
        "hit_payouts": [],
    }


def add_stat(s, r, kind, payout):
    s["n"] += 1
    hit = ticket_hit(r, kind)
    s["hits"] += int(hit)
    if payout is not None:
        s["payout_n"] += 1
        if hit:
            s["payout_hits"] += 1
            s["return"] += payout
            s["hit_payouts"].append(payout)


def metrics(s, points):
    n = s["n"]
    hits = s["hits"]
    hit_rate = 100.0 * hits / n if n else 0.0

    payout_n = s["payout_n"]
    stake = payout_n * points * 100
    roi = 100.0 * s["return"] / stake if stake else None

    hp = s["hit_payouts"]
    avg_payout = sum(hp) / len(hp) if hp else None
    manshu = sum(1 for p in hp if p >= 10000)
    manshu_rate = 100.0 * manshu / len(hp) if hp else None

    return hit_rate, roi, avg_payout, manshu_rate


def fmt(v, suffix=""):
    return "-" if v is None else f"{v:.2f}{suffix}"


def main():
    if len(sys.argv) != 3:
        print(f"Usage: python3 {sys.argv[0]} DATASET_CSV PAYOUT_CSV", file=sys.stderr)
        raise SystemExit(1)

    dataset_path = Path(sys.argv[1])
    payout_path = Path(sys.argv[2])
    if not dataset_path.is_file():
        raise RuntimeError(f"datasetがありません: {dataset_path}")
    if not payout_path.is_file():
        raise RuntimeError(f"払戻CSVがありません: {payout_path}")

    rows = [r for r in load_csv(dataset_path) if formal(r) and condition(r)]
    payouts = load_payouts(payout_path)

    kinds = {
        "S516": {"label": "5-1-6", "points": 1},
        "S5Z15": {"label": "5-1-*", "points": 4},
    }

    # stats[(period, group, kind)]
    stats = defaultdict(empty_stat)

    for r in rows:
        rc = (r.get("race_code") or "").strip()
        payout = payouts.get(rc)
        p = period_name(r)
        for g in group_names(r):
            for kind in kinds:
                add_stat(stats[(p, g, kind)], r, kind, payout)
                add_stat(stats[("全期間", g, kind)], r, kind, payout)

    print("=" * 132)
    print("STEP9.5 芦屋6R型 S516 / S5Z15 × Web本命コンテキスト")
    print("=" * 132)
    print(f"正式条件成立R : {len(rows)}")
    print("固定条件      : 5C 6m sample>=10 / まくり差し>=15%")
    print("重点          : WEB本命1の時に 5-1-6 を1点穴保険として成立させられるか")
    print("ROI注意       : 払戻CSVに存在するレースだけで計算")

    for period in ["前半", "後半", "全期間"]:
        print("\n" + "-" * 132)
        print(f"【{period}】")
        print("-" * 132)
        print(
            f"{'グループ':<16} {'券種':<8} {'発動R':>7} {'的中':>7} {'的中率':>9} "
            f"{'払戻有R':>8} {'ROI':>9} {'的中平均払戻':>13} {'万舟率':>9}"
        )
        print("-" * 132)

        for group in ["ALL", "WEB本命1", "WEB本命1以外"]:
            for kind, meta in kinds.items():
                s = stats[(period, group, kind)]
                hit_rate, roi, avg_payout, manshu_rate = metrics(s, meta["points"])
                print(
                    f"{group:<16} {meta['label']:<8} {s['n']:>7} {s['hits']:>7} "
                    f"{hit_rate:>8.3f}% {s['payout_n']:>8} {fmt(roi, '%'):>9} "
                    f"{fmt(avg_payout, '円'):>13} {fmt(manshu_rate, '%'):>9}"
                )

    # 後半のWEB本命1で、S516的中レースを確認用に列挙
    hits = []
    for r in rows:
        if period_name(r) != "後半":
            continue
        if to_int(r.get("honmei_head")) != 1:
            continue
        if not ticket_hit(r, "S516"):
            continue
        rc = (r.get("race_code") or "").strip()
        hits.append((
            r.get("race_date", ""),
            r.get("stadium_name", ""),
            r.get("race_number", ""),
            rc,
            payouts.get(rc),
            to_float(r.get("c5_6m_makurizashi")),
            to_int(r.get("c5_6m_sample_n")),
        ))

    print("\n" + "=" * 132)
    print("後半6か月：WEB本命1 × S516 的中レース")
    print("=" * 132)
    if not hits:
        print("該当なし")
    else:
        for d, stadium, rn, rc, payout, rate, sample in sorted(hits, reverse=True):
            ptxt = "払戻なし" if payout is None else f"{payout}円"
            print(
                f"{d} {stadium} {str(rn).rstrip('R')}R {rc}  "
                f"5Cまくり差し={rate:.1f}% N={sample}  5-1-6  {ptxt}"
            )

    print("\n判断:")
    print("1. WEB本命1のS516 ROIが100%超か、ALLより改善するかを見る")
    print("2. 件数が少ないので、ROIだけでなく前後半の的中率方向も確認する")
    print("3. ここでは閾値15%を固定し、追加の閾値チューニングはしない")
    print("4. 良ければ次にWeb表示用の『穴筋候補』へ進み、本命買い目自体は変更しない")


if __name__ == "__main__":
    main()
