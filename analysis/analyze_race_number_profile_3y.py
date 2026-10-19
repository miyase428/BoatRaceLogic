#!/usr/bin/env python3

import csv
import statistics
from collections import defaultdict, Counter
from pathlib import Path

INPUT = Path("analysis/output/race_number_profile_3y.csv")
OUTPUT = Path("analysis/output/race_number_profile_summary_3y.csv")
STABILITY = Path("analysis/output/race_number_profile_stability_3y.csv")

PERIODS = ("YEAR1", "YEAR2", "YEAR3")


def to_int(v):
    try:
        return int(v)
    except (TypeError, ValueError):
        return None


def pct(num, den):
    return 100.0 * num / den if den else None


with INPUT.open(encoding="utf-8", newline="") as f:
    rows = list(csv.DictReader(f))

groups = defaultdict(list)

for r in rows:
    key = (r["stadium"], int(r["race_number"]))
    groups[(key[0], key[1], r["period"])].append(r)
    groups[(key[0], key[1], "ALL")].append(r)


def summarize(rr):
    n = len(rr)

    first = [to_int(r["first_course"]) for r in rr]
    first_valid = [x for x in first if x is not None]

    second = [to_int(r["second_course"]) for r in rr]
    third = [to_int(r["third_course"]) for r in rr]

    payouts = [
        to_int(r["trifecta_payout"])
        for r in rr
        if to_int(r["trifecta_payout"]) is not None
    ]

    techniques = Counter(
        r["winner_technique"].strip()
        for r in rr
        if r["winner_technique"].strip()
    )

    trifectas = Counter(
        r["trifecta_combination"].strip()
        for r in rr
        if r["trifecta_combination"].strip()
    )

    head_counts = Counter(first_valid)

    valid_top3 = [
        (a, b, c)
        for a, b, c in zip(first, second, third)
        if a is not None and b is not None and c is not None
    ]

    outer_in_top3 = sum(
        any(x in (4, 5, 6) for x in trio)
        for trio in valid_top3
    )

    top_trifecta, top_trifecta_n = ("", 0)
    if trifectas:
        top_trifecta, top_trifecta_n = trifectas.most_common(1)[0]

    return {
        "n": n,

        "head1_rate": pct(head_counts[1], len(first_valid)),
        "head2_rate": pct(head_counts[2], len(first_valid)),
        "head3_rate": pct(head_counts[3], len(first_valid)),
        "head4_rate": pct(head_counts[4], len(first_valid)),
        "head5_rate": pct(head_counts[5], len(first_valid)),
        "head6_rate": pct(head_counts[6], len(first_valid)),

        "non1_head_rate": pct(
            sum(head_counts[x] for x in (2, 3, 4, 5, 6)),
            len(first_valid),
        ),

        "outer_head_rate": pct(
            sum(head_counts[x] for x in (4, 5, 6)),
            len(first_valid),
        ),

        "outer_top3_rate": pct(
            outer_in_top3,
            len(valid_top3),
        ),

        "nige_rate": pct(techniques["逃げ"], n),
        "sashi_rate": pct(techniques["差し"], n),
        "makuri_rate": pct(techniques["まくり"], n),
        "makurizashi_rate": pct(techniques["まくり差し"], n),

        "trifecta_median": (
            statistics.median(payouts) if payouts else None
        ),

        "trifecta_mean": (
            sum(payouts) / len(payouts) if payouts else None
        ),

        "over_3000_rate": pct(
            sum(x >= 3000 for x in payouts),
            len(payouts),
        ),

        "over_5000_rate": pct(
            sum(x >= 5000 for x in payouts),
            len(payouts),
        ),

        "over_10000_rate": pct(
            sum(x >= 10000 for x in payouts),
            len(payouts),
        ),

        "under_2000_rate": pct(
            sum(x < 2000 for x in payouts),
            len(payouts),
        ),

        "top_trifecta": top_trifecta,

        "top_trifecta_rate": pct(
            top_trifecta_n,
            n,
        ),
    }


summary = {}

for key, rr in groups.items():
    summary[key] = summarize(rr)


fields = [
    "stadium",
    "race_number",
    "period",
    "n",
    "head1_rate",
    "head2_rate",
    "head3_rate",
    "head4_rate",
    "head5_rate",
    "head6_rate",
    "non1_head_rate",
    "outer_head_rate",
    "outer_top3_rate",
    "nige_rate",
    "sashi_rate",
    "makuri_rate",
    "makurizashi_rate",
    "trifecta_median",
    "trifecta_mean",
    "under_2000_rate",
    "over_3000_rate",
    "over_5000_rate",
    "over_10000_rate",
    "top_trifecta",
    "top_trifecta_rate",
]

with OUTPUT.open("w", encoding="utf-8", newline="") as f:
    w = csv.DictWriter(f, fieldnames=fields)
    w.writeheader()

    for stadium in sorted({k[0] for k in summary}):
        for race_number in range(1, 13):
            for period in (*PERIODS, "ALL"):
                s = summary.get((stadium, race_number, period))
                if not s:
                    continue

                row = {
                    "stadium": stadium,
                    "race_number": race_number,
                    "period": period,
                }
                row.update(s)

                for k, v in list(row.items()):
                    if isinstance(v, float):
                        row[k] = round(v, 2)

                w.writerow(row)


# =========================================
# YEAR1→YEAR2→YEAR3 安定性
# =========================================

stability_rows = []

for stadium in sorted({k[0] for k in summary}):
    all_base = [
        summary[(stadium, r, "ALL")]
        for r in range(1, 13)
        if (stadium, r, "ALL") in summary
    ]

    if not all_base:
        continue

    stadium_head1 = sum(x["head1_rate"] for x in all_base) / len(all_base)
    stadium_10k = sum(x["over_10000_rate"] for x in all_base) / len(all_base)
    stadium_median = statistics.median(
        x["trifecta_median"]
        for x in all_base
        if x["trifecta_median"] is not None
    )

    for race_number in range(1, 13):
        yearly = [
            summary.get((stadium, race_number, p))
            for p in PERIODS
        ]

        if any(x is None for x in yearly):
            continue

        total = summary[(stadium, race_number, "ALL")]

        # 3年間すべて、その場平均よりインが弱い
        weak_in_years = sum(
            x["head1_rate"] < stadium_head1
            for x in yearly
        )

        # 3年間すべて、その場平均より万舟率が高い
        high_10k_years = sum(
            x["over_10000_rate"] > stadium_10k
            for x in yearly
        )

        # 3年間すべて、配当中央値が場平均より高い
        high_median_years = sum(
            x["trifecta_median"] > stadium_median
            for x in yearly
        )

        # 3年間すべて、その場平均よりインが強い
        strong_in_years = sum(
            x["head1_rate"] > stadium_head1
            for x in yearly
        )

        # 3年間すべて、2000円未満率が高いか
        stadium_under2k = sum(
            x["under_2000_rate"] for x in all_base
        ) / len(all_base)

        hard_years = sum(
            x["under_2000_rate"] > stadium_under2k
            for x in yearly
        )

        stability_rows.append({
            "stadium": stadium,
            "race_number": race_number,
            "n_all": total["n"],

            "head1_rate": total["head1_rate"],
            "non1_head_rate": total["non1_head_rate"],
            "outer_head_rate": total["outer_head_rate"],
            "outer_top3_rate": total["outer_top3_rate"],

            "trifecta_median": total["trifecta_median"],
            "under_2000_rate": total["under_2000_rate"],
            "over_5000_rate": total["over_5000_rate"],
            "over_10000_rate": total["over_10000_rate"],

            "weak_in_years": weak_in_years,
            "strong_in_years": strong_in_years,
            "high_10k_years": high_10k_years,
            "high_median_years": high_median_years,
            "hard_years": hard_years,
        })


stability_fields = list(stability_rows[0].keys())

with STABILITY.open("w", encoding="utf-8", newline="") as f:
    w = csv.DictWriter(f, fieldnames=stability_fields)
    w.writeheader()

    for r in stability_rows:
        out = {}
        for k, v in r.items():
            out[k] = round(v, 2) if isinstance(v, float) else v
        w.writerow(out)


print("=" * 90)
print("3年 場×R番号分析")
print("=" * 90)
print(f"入力レース : {len(rows):,}")
print(f"場数       : {len(set(r['stadium'] for r in rows))}")
print(f"詳細出力   : {OUTPUT}")
print(f"安定性出力 : {STABILITY}")
print()


# =========================================
# 画面用 TOP表示
# =========================================

def show(title, items, key, reverse=True, n=20):
    print()
    print("=" * 90)
    print(title)
    print("=" * 90)

    items = sorted(
        items,
        key=lambda x: x[key],
        reverse=reverse,
    )[:n]

    for r in items:
        print(
            f"{r['stadium']} {r['race_number']:2d}R "
            f"N={r['n_all']:4d} "
            f"1頭={r['head1_rate']:5.1f}% "
            f"外頭={r['outer_head_rate']:5.1f}% "
            f"3単中央値={r['trifecta_median']:7.0f}円 "
            f"5000+={r['over_5000_rate']:5.1f}% "
            f"万舟={r['over_10000_rate']:5.1f}%"
        )


stable_rough = [
    r for r in stability_rows
    if r["weak_in_years"] == 3
    and r["high_10k_years"] == 3
]

stable_hard = [
    r for r in stability_rows
    if r["strong_in_years"] == 3
    and r["hard_years"] == 3
]


show(
    "★ 3年間一貫してイン弱め＋万舟率高め",
    stable_rough,
    "over_10000_rate",
)

show(
    "★ 3年間一貫してイン強め＋2000円未満率高め",
    stable_hard,
    "under_2000_rate",
)

show(
    "★ 全288場×R番号 万舟率TOP20",
    stability_rows,
    "over_10000_rate",
)

show(
    "★ 全288場×R番号 イン1着率TOP20",
    stability_rows,
    "head1_rate",
)
