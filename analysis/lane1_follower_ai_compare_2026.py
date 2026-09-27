#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""AI本命①における「場別1逃げ相手補正」の追加価値を検証する。

各レースの2着候補数・3着候補数と切る艇を固定し、同一点数で比較する。

- AI_ONLY: AI2着・AI3着 v1だけ
- VENUE_ONLY: 現行の場別1逃げフォロワー順位だけ
- AI_VENUE_BLEND: AI確率と場別実績確率の対数ブレンド
- CURRENT_ORDER_CORE: 場別で3着候補を作った後、AIで2着候補を上書き

9/1～9/10でブレンド重みを選び、9/11～9/21は未使用の前方評価に固定する。
"""

from __future__ import annotations

import json
import math
import subprocess
import sys
from datetime import date
from pathlib import Path

import numpy as np

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "analysis"))
sys.path.insert(0, str(ROOT / "forecast"))

import ai_final_bet_same_points_ablation_2026 as ab  # noqa: E402
import train_ai_place_v1 as place_v1  # noqa: E402
from final_prediction_ai_bet_integration_compare import load_payouts  # noqa: E402


REPORT = ROOT / "analysis/output/lane1_follower_ai_compare_2026.json"
EPS = 1.0e-12
ALPHAS = (0.25, 0.50, 0.75)
PLACE_NAMES = {
    "KRY": "桐生", "TDA": "戸田", "EDG": "江戸川", "HWJ": "平和島",
    "TMG": "多摩川", "HMN": "浜名湖", "GMG": "蒲郡", "TKN": "常滑",
    "TSU": "津", "MKN": "三国", "BWK": "びわこ", "SME": "住之江",
    "AMG": "尼崎", "NRT": "鳴門", "MRG": "丸亀", "KJM": "児島",
    "MYJ": "宮島", "TKY": "徳山", "SMS": "下関", "WKM": "若松",
    "ASY": "芦屋", "FKO": "福岡", "KRT": "唐津", "OMR": "大村",
}


def load_venue_model():
    command = [
        "php", "-r",
        '$m=require "config/lane1_escape_follower_model.php"; '
        'echo json_encode($m, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);',
    ]
    raw = subprocess.check_output(command, cwd=ROOT, text=True)
    return json.loads(raw)["stadiums"]


def normalize(values):
    total = sum(max(float(v), EPS) for v in values.values())
    return {k: max(float(v), EPS) / total for k, v in values.items()}


def blend(ai, venue, alpha):
    ai = normalize(ai)
    venue = normalize(venue)
    scores = {
        boat: (1.0 - alpha) * math.log(ai[boat]) + alpha * math.log(venue[boat])
        for boat in ai
    }
    top = max(scores.values())
    return normalize({boat: math.exp(value - top) for boat, value in scores.items()})


def venue_probability(spec, key, race, candidates):
    counts = spec[key]
    values = {
        boat: float(counts.get(str(race["boats"][boat]["course"]), 0.0)) + 0.5
        for boat in candidates
    }
    return normalize(values)


def candidate_counts(export):
    order = sorted(range(1, 7), key=lambda b: (export[b]["final_rank"], b))
    cut = {b for b in range(1, 7) if int(export[b]["kiru"]) == 1}
    cut.discard(1)
    eligible = [b for b in order if b != 1 and b not in cut]
    return order, eligible, min(3, len(eligible)), len(eligible)


def formation(head, second, third):
    return {
        (head, b, c)
        for b in second
        for c in third
        if len({head, b, c}) == 3
    }


def with_second_in_third(second, third_order, third_count):
    out = list(second)
    for boat in third_order:
        if len(out) >= third_count:
            break
        if boat not in out:
            out.append(boat)
    return out


def venue_orders(race, export, eligible, spec):
    def ordered(rank_key):
        ranks = spec[rank_key]
        return sorted(
            eligible,
            key=lambda b: (
                export[b]["final_rank"]
                + int(ranks.get(str(race["boats"][b]["course"]), 99)),
                export[b]["final_rank"],
                b,
            ),
        )
    return ordered("second_rank"), ordered("third_rank")


def ai_probabilities(cache, race, eligible):
    code = race["race_code"]
    p2_all = cache["second"][(code, 1)]
    p2 = {b: p2_all[b] for b in eligible}
    p3 = {b: 0.0 for b in eligible}
    for second, second_prob in p2_all.items():
        for third, third_prob in cache["third"][(code, 1, second)].items():
            if third in p3:
                p3[third] += float(second_prob) * float(third_prob)
    return normalize(p2), normalize(p3)


def make_row(race, export, cache, venues, alpha):
    order = sorted(
        range(1, 7),
        key=lambda b: (-race["boats"][b]["v5_probability"], b),
    )
    if order[0] != 1 or int(race["boats"][1]["course"]) != 1:
        return None
    _rank, eligible, second_count, third_count = candidate_counts(export)
    if second_count <= 0 or third_count <= 1:
        return None
    stadium = PLACE_NAMES.get(race["race_code"][8:11])
    spec = venues.get(stadium or "")
    if not spec:
        return None

    venue_second_order, venue_third_order = venue_orders(race, export, eligible, spec)
    ai2, ai3 = ai_probabilities(cache, race, eligible)
    ai_second_order = sorted(eligible, key=lambda b: (-ai2[b], b))
    ai_third_order = sorted(eligible, key=lambda b: (-ai3[b], b))

    ai_second = ai_second_order[:second_count]
    ai_third = with_second_in_third(ai_second, ai_third_order, third_count)

    venue_second = venue_second_order[:second_count]
    venue_third = with_second_in_third(venue_second, venue_third_order, third_count)

    # 実運用の核: 場別で3着集合を作り、2着は後段AIが上書き。
    current_second = sorted(venue_third, key=lambda b: (-ai2[b], b))[:second_count]
    current_third = list(venue_third)

    venue2 = venue_probability(spec, "second_counts", race, eligible)
    venue3 = venue_probability(spec, "third_counts", race, eligible)
    hybrid2 = blend(ai2, venue2, alpha)
    hybrid3 = blend(ai3, venue3, alpha)
    hybrid_second = sorted(eligible, key=lambda b: (-hybrid2[b], b))[:second_count]
    hybrid_third = with_second_in_third(
        hybrid_second,
        sorted(eligible, key=lambda b: (-hybrid3[b], b)),
        third_count,
    )

    scenarios = {
        "AI_ONLY": formation(1, ai_second, ai_third),
        "VENUE_ONLY": formation(1, venue_second, venue_third),
        "AI_VENUE_BLEND": formation(1, hybrid_second, hybrid_third),
        "CURRENT_ORDER_CORE": formation(1, current_second, current_third),
    }
    points = {len(bets) for bets in scenarios.values()}
    if len(points) != 1:
        raise RuntimeError(f"{race['race_code']}: 同点数になりません {points}")
    return {
        "race_code": race["race_code"],
        "race_date": race["race_date"].isoformat(),
        "actual": tuple(race["actual"]),
        "points": points.pop(),
        "scenarios": scenarios,
    }


def evaluate(rows, name, payouts):
    hits = points = returned = investment = 0
    for row in rows:
        bets = row["scenarios"][name]
        hit = row["actual"] in bets
        hits += int(hit)
        points += len(bets)
        payout = payouts.get(row["race_code"])
        if payout is not None:
            investment += len(bets) * 100
            if hit:
                returned += int(payout)
    return {
        "races": len(rows),
        "hits": hits,
        "hit_rate": hits / len(rows) if rows else 0.0,
        "avg_points": points / len(rows) if rows else 0.0,
        "roi": returned / investment if investment else None,
    }


def paired(rows, payouts, candidate, reference="AI_ONLY"):
    gain = loss = changed = 0
    delta = []
    return_delta = []
    investment = []
    for row in rows:
        a = row["actual"] in row["scenarios"][candidate]
        b = row["actual"] in row["scenarios"][reference]
        gain += int(a and not b)
        loss += int(b and not a)
        changed += int(row["scenarios"][candidate] != row["scenarios"][reference])
        delta.append(int(a) - int(b))
        payout = float(payouts.get(row["race_code"], 0.0) or 0.0)
        return_delta.append((int(a) - int(b)) * payout)
        investment.append(len(row["scenarios"][candidate]) * 100.0)
    rng = np.random.default_rng(20260926)
    values = np.asarray(delta, dtype=np.float64)
    sampled = values[rng.integers(0, len(values), size=(3000, len(values)))]
    means = sampled.mean(axis=1)
    returns = np.asarray(return_delta, dtype=np.float64)
    costs = np.asarray(investment, dtype=np.float64)
    indexes = rng.integers(0, len(values), size=(3000, len(values)))
    return_means = returns[indexes].sum(axis=1)
    cost_sums = costs[indexes].sum(axis=1)
    roi_delta = np.divide(
        return_means,
        cost_sums,
        out=np.zeros_like(return_means),
        where=cost_sums > 0,
    ) * 100.0
    return {
        "changed": changed,
        "gained": gain,
        "lost": loss,
        "net": gain - loss,
        "improvement_probability": float(np.mean(means > 0.0)),
        "ci95_pt": [
            float(np.quantile(means, 0.025) * 100.0),
            float(np.quantile(means, 0.975) * 100.0),
        ],
        "roi_improvement_probability": float(np.mean(roi_delta > 0.0)),
        "roi_delta_ci95_pt": [
            float(np.quantile(roi_delta, 0.025)),
            float(np.quantile(roi_delta, 0.975)),
        ],
    }


def main():
    print("データとAI特徴量を読み込み中…", flush=True)
    races, _places, skip = place_v1.build_races()
    export_all = ab.load_export_rows()
    venues = load_venue_model()
    train = [r for r in races if r["race_date"] <= date(2026, 8, 31)]
    design = [r for r in races if date(2026, 9, 1) <= r["race_date"] <= date(2026, 9, 10)]
    test = [r for r in races if date(2026, 9, 11) <= r["race_date"] <= date(2026, 9, 21)]
    print(f"train={len(train):,} design={len(design):,} test={len(test):,} skip={dict(skip)}", flush=True)

    columns2 = np.arange(len(ab.feature_names(False)), dtype=np.int32)
    columns3 = np.arange(len(ab.feature_names(True)), dtype=np.int32)
    spec2 = ab.fit_target(train, design, test, "second", columns2)
    spec3 = ab.fit_target(train, design, test, "third", columns3)
    specs = {"second": spec2, "third": spec3}
    caches = {
        "second": ab.batch_conditional_cache(design + test, spec2, "second"),
        "third": ab.batch_conditional_cache(design + test, spec3, "third"),
    }
    payouts = load_payouts(date(2026, 9, 1), date(2026, 9, 21))

    design_by_alpha = {}
    rows_by_alpha = {}
    for alpha in ALPHAS:
        rows = []
        for race in design:
            export = {b: export_all.get((race["race_code"], b)) for b in range(1, 7)}
            if any(value is None for value in export.values()):
                continue
            row = make_row(race, export, caches, venues, alpha)
            if row:
                rows.append(row)
        rows_by_alpha[alpha] = rows
        design_by_alpha[alpha] = evaluate(rows, "AI_VENUE_BLEND", payouts)

    selected_alpha = max(
        ALPHAS,
        key=lambda a: (
            design_by_alpha[a]["hits"],
            design_by_alpha[a]["roi"] or -1.0,
            -a,
        ),
    )
    design_rows = rows_by_alpha[selected_alpha]
    test_rows = []
    for race in test:
        export = {b: export_all.get((race["race_code"], b)) for b in range(1, 7)}
        if any(value is None for value in export.values()):
            continue
        row = make_row(race, export, caches, venues, selected_alpha)
        if row:
            test_rows.append(row)

    names = ("AI_ONLY", "VENUE_ONLY", "AI_VENUE_BLEND", "CURRENT_ORDER_CORE")
    report = {
        "name": "lane1_follower_ai_compare_2026",
        "condition": "AI1着率v5本命①・①展示1C・同点数",
        "selected_alpha": selected_alpha,
        "design_alpha_results": {str(a): design_by_alpha[a] for a in ALPHAS},
        "design": {name: evaluate(design_rows, name, payouts) for name in names},
        "forward": {name: evaluate(test_rows, name, payouts) for name in names},
        "forward_vs_ai_only": {
            name: paired(test_rows, payouts, name) for name in names if name != "AI_ONLY"
        },
        "model": {
            "second_temperature": spec2["temperature"],
            "second_alpha": spec2["alpha"],
            "third_temperature": spec3["temperature"],
            "third_alpha": spec3["alpha"],
        },
    }
    REPORT.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")

    print(f"\n選択ブレンド重み: 場別 {selected_alpha:.2f} / AI {1-selected_alpha:.2f}")
    for period, rows in (("DESIGN 09/01-10", design_rows), ("FORWARD 09/11-21", test_rows)):
        print(f"\n【{period}】 {len(rows):,}R")
        for name in names:
            result = evaluate(rows, name, payouts)
            roi = "-" if result["roi"] is None else f"{result['roi']*100:.2f}%"
            print(
                f"{name:<20} 平均{result['avg_points']:5.2f}点 "
                f"的中 {result['hits']:4d} ({result['hit_rate']*100:6.2f}%) ROI {roi}"
            )
            if period.startswith("FORWARD") and name != "AI_ONLY":
                comp = paired(rows, payouts, name)
                print(
                    f"  vs AI: 変更{comp['changed']} 拾い{comp['gained']} "
                    f"落とし{comp['lost']} 純増{comp['net']:+d} "
                    f"改善確率{comp['improvement_probability']*100:.1f}% "
                    f"ROI改善確率{comp['roi_improvement_probability']*100:.1f}%"
                )
    print(f"\n保存: {REPORT}")


if __name__ == "__main__":
    main()
