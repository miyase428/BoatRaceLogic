#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""切る艇をAI2着率v1で再評価し、2着候補最下位と入れ替える検証。"""

from __future__ import annotations

import json
import random
from collections import defaultdict
from datetime import date
from pathlib import Path

import numpy as np

import ai_place_v1_final_second_backtest_2026 as second_bt
import audit_current_cut_rule_2026 as cut_audit


ROOT = Path(__file__).resolve().parent.parent
TRAIN_END = date(2026, 8, 31)
VALID_START = date(2026, 9, 1)
VALID_END = date(2026, 9, 10)
TEST_START = date(2026, 9, 11)
TEST_END = date(2026, 9, 21)
MARGINS = (1.0, 1.05, 1.10, 1.15, 1.25, 1.50, 2.00)
SCOPES = ("both", "honmei", "taikou")
MAX_VALID_POINT_GROWTH = 0.01
BOOTSTRAP_N = 5000
BOOTSTRAP_SEED = 428
OUTPUT_PATH = ROOT / "analysis/output/ai_place_v1_cut_second_swap_backtest_2026.json"


def attach_swap_candidates(current_rows, feature_races, second_model):
    feature_by_code = {race["race_code"]: race for race in feature_races}
    rows = []
    skipped = defaultdict(int)

    for current in current_rows:
        race = feature_by_code.get(current["race_code"])
        if race is None:
            skipped["feature_missing"] += 1
            continue
        probability_cache = {}
        sides = {}
        for side_name in ("honmei", "taikou"):
            base = current[side_name]
            head = int(base["head"])
            seconds = list(base["seconds"])
            cuts = sorted(boat for boat in base["cuts"] if boat != head)
            if head not in probability_cache:
                probability_cache[head] = second_bt.ml_second_probabilities(
                    second_model, race, head
                )
            probability = probability_cache[head]

            rescue_boat = None
            rescue_probability = 0.0
            removed_boat = None
            removed_probability = 0.0
            ratio = 0.0
            if cuts and seconds:
                rescue_boat = max(cuts, key=lambda boat: (probability[boat], -boat))
                removed_boat = min(seconds, key=lambda boat: (probability[boat], -boat))
                rescue_probability = float(probability[rescue_boat])
                removed_probability = float(probability[removed_boat])
                if removed_probability > 0.0:
                    ratio = rescue_probability / removed_probability

            sides[side_name] = {
                "head": head,
                "base_seconds": seconds,
                "thirds": list(base["thirds"]),
                "base_tickets": set(base["tickets"]),
                "cut_boats": cuts,
                "rescue_boat": rescue_boat,
                "rescue_probability": rescue_probability,
                "removed_boat": removed_boat,
                "removed_probability": removed_probability,
                "ratio": ratio,
            }

        rows.append({
            "race_code": current["race_code"],
            "race_date": current["race_date"],
            "actual": current["actual"],
            "payout": current["payout"],
            "current_tickets": current["tickets"],
            "honmei": sides["honmei"],
            "taikou": sides["taikou"],
        })
        skipped["ready"] += 1
    return rows, dict(skipped)


def side_candidate(side, margin: float):
    applied = (
        side["rescue_boat"] is not None
        and side["removed_boat"] is not None
        and side["rescue_boat"] not in side["base_seconds"]
        and side["ratio"] >= margin
    )
    seconds = list(side["base_seconds"])
    if applied:
        seconds = [
            side["rescue_boat"] if boat == side["removed_boat"] else boat
            for boat in seconds
        ]
        seconds = sorted(set(seconds))
    tickets = second_bt.expand(side["head"], seconds, side["thirds"])
    return tickets, applied, side["rescue_boat"] if applied else None, side["removed_boat"] if applied else None


def apply_policy(rows, margin: float, scope: str):
    output = []
    for row in rows:
        tickets = set()
        applied_sides = []
        rescued_boats = []
        removed_boats = []
        for side_name in ("honmei", "taikou"):
            side = row[side_name]
            if scope == "both" or scope == side_name:
                side_tickets, applied, rescue_boat, removed_boat = side_candidate(side, margin)
            else:
                side_tickets = side["base_tickets"]
                applied = False
                rescue_boat = None
                removed_boat = None
            tickets |= side_tickets
            if applied:
                applied_sides.append(side_name)
                rescued_boats.append(rescue_boat)
                removed_boats.append(removed_boat)
        actual = row["actual"]
        output.append({
            "race_code": row["race_code"],
            "race_date": row["race_date"],
            "actual": actual,
            "payout": row["payout"],
            "current_tickets": row["current_tickets"],
            "candidate_tickets": tickets,
            "current_points": len(row["current_tickets"]),
            "candidate_points": len(tickets),
            "applied_sides": applied_sides,
            "rescued_boats": sorted(set(rescued_boats)),
            "removed_boats": sorted(set(removed_boats)),
            "rescued_actual_second": actual[1] in rescued_boats,
            "removed_actual_second": actual[1] in removed_boats,
        })
    return output


def evaluate(rows, method: str):
    ticket_key = f"{method}_tickets"
    point_key = f"{method}_points"
    points = sum(row[point_key] for row in rows)
    hits = sum(row["actual"] in row[ticket_key] for row in rows)
    returns = sum(
        row["payout"] for row in rows if row["actual"] in row[ticket_key]
    )
    investment = points * 100.0
    return {
        "races": len(rows),
        "points": points,
        "average_points": points / len(rows) if rows else 0.0,
        "hits": hits,
        "hit_rate": hits / len(rows) if rows else 0.0,
        "hits_per_1000_points": hits * 1000.0 / points if points else 0.0,
        "return_yen": returns,
        "investment_yen": investment,
        "roi": returns / investment if investment else 0.0,
    }


def summarize(rows):
    current = evaluate(rows, "current")
    candidate = evaluate(rows, "candidate")
    gained = []
    lost = []
    for row in rows:
        before = row["actual"] in row["current_tickets"]
        after = row["actual"] in row["candidate_tickets"]
        detail = {
            "race_code": row["race_code"],
            "actual": list(row["actual"]),
            "payout_yen": row["payout"],
            "rescued_boats": row["rescued_boats"],
            "removed_boats": row["removed_boats"],
            "applied_sides": row["applied_sides"],
        }
        if after and not before:
            gained.append(detail)
        if before and not after:
            lost.append(detail)
    return {
        "current": current,
        "ai_second_swap": candidate,
        "changed_races": sum(bool(row["applied_sides"]) for row in rows),
        "honmei_changed_races": sum("honmei" in row["applied_sides"] for row in rows),
        "taikou_changed_races": sum("taikou" in row["applied_sides"] for row in rows),
        "rescued_actual_second_races": sum(row["rescued_actual_second"] for row in rows),
        "removed_actual_second_races": sum(row["removed_actual_second"] for row in rows),
        "gained_hits": len(gained),
        "lost_hits": len(lost),
        "gained_hit_details": gained,
        "lost_hit_details": lost,
        "delta": {
            "points": candidate["points"] - current["points"],
            "point_growth_rate": candidate["points"] / current["points"] - 1.0 if current["points"] else 0.0,
            "hits": candidate["hits"] - current["hits"],
            "hit_rate": candidate["hit_rate"] - current["hit_rate"],
            "hits_per_1000_points": candidate["hits_per_1000_points"] - current["hits_per_1000_points"],
            "roi": candidate["roi"] - current["roi"],
            "return_yen": candidate["return_yen"] - current["return_yen"],
        },
    }


def tune_policy(rows):
    table = []
    for scope in SCOPES:
        for margin in MARGINS:
            result = summarize(apply_policy(rows, margin, scope))
            table.append({"scope": scope, "margin": margin, **result})
    current_efficiency = table[0]["current"]["hits_per_1000_points"]
    eligible = [
        row for row in table
        if row["delta"]["hits"] > 0
        and row["delta"]["point_growth_rate"] <= MAX_VALID_POINT_GROWTH
        and row["ai_second_swap"]["hits_per_1000_points"] >= current_efficiency
    ]
    if not eligible:
        return None, None, table, []
    selected = max(
        eligible,
        key=lambda row: (
            row["delta"]["hits"],
            -row["lost_hits"],
            row["ai_second_swap"]["hits_per_1000_points"],
            row["margin"],
        ),
    )
    return (
        str(selected["scope"]),
        float(selected["margin"]),
        table,
        [{"scope": row["scope"], "margin": row["margin"]} for row in eligible],
    )


def bootstrap(rows):
    by_day = defaultdict(list)
    for row in rows:
        by_day[row["race_date"]].append(row)
    days = sorted(by_day)
    rng = random.Random(BOOTSTRAP_SEED)
    hit_deltas = []
    roi_deltas = []
    for _ in range(BOOTSTRAP_N):
        sample = []
        for _index in range(len(days)):
            sample.extend(by_day[rng.choice(days)])
        result = summarize(sample)
        hit_deltas.append(result["delta"]["hit_rate"])
        roi_deltas.append(result["delta"]["roi"])

    def metric(values):
        return {
            "median": float(np.median(values)),
            "ci95": [float(np.quantile(values, 0.025)), float(np.quantile(values, 0.975))],
        }

    return {
        "days": len(days),
        "iterations": BOOTSTRAP_N,
        "hit_rate_delta": {
            **metric(hit_deltas),
            "improvement_probability": sum(value > 0 for value in hit_deltas) / len(hit_deltas),
            "non_degradation_probability": sum(value >= 0 for value in hit_deltas) / len(hit_deltas),
        },
        "roi_delta": {
            **metric(roi_deltas),
            "improvement_probability": sum(value > 0 for value in roi_deltas) / len(roi_deltas),
        },
    }


def print_summary(label, result):
    print(f"\n【{label}】")
    print("方式             的中数  的中率  平均点数  千点的中      ROI")
    for name, row in (("CURRENT", result["current"]), ("AI_SWAP", result["ai_second_swap"])):
        print(
            f"{name:<16} {row['hits']:>5d}  {row['hit_rate']*100:>6.2f}%  "
            f"{row['average_points']:>8.2f}  {row['hits_per_1000_points']:>8.2f}  "
            f"{row['roi']*100:>7.2f}%"
        )
    print(
        f"変更={result['changed_races']}R / 拾い={result['gained_hits']} / "
        f"失い={result['lost_hits']} / 純増={result['delta']['hits']:+d} / "
        f"点数={result['delta']['points']:+d} ({result['delta']['point_growth_rate']*100:+.2f}%)"
    )


def main():
    print("AI2着率v1の8月末固定モデルを再学習しています…", flush=True)
    all_races, _place_to_id, source_skip = second_bt.place_train.build_races()
    train = [race for race in all_races if race["race_date"] <= TRAIN_END]
    valid_features = [race for race in all_races if VALID_START <= race["race_date"] <= VALID_END]
    test_features = [race for race in all_races if TEST_START <= race["race_date"] <= TEST_END]

    x2, y2, g2 = second_bt.place_train.matrices(train, "second")
    second_model = second_bt.place_train.make_ranker()
    second_model.fit(x2, y2, group=g2)

    # 現行のAI3着絞りまで含む買い目を再現するため、固定済み3着モデルも使用する。
    x3, y3, g3 = second_bt.place_train.matrices(train, "third")
    third_model = second_bt.place_train.make_ranker()
    third_model.fit(x3, y3, group=g3)

    valid_predictions = cut_audit.load_prediction_rows(VALID_START, VALID_END)
    valid_boats = cut_audit.load_boat_rows(VALID_START, VALID_END)
    valid_payouts = second_bt.payout_util.load_trifecta_payouts(VALID_START, VALID_END)
    valid_scenarios, valid_base_skip, valid_match = cut_audit.build_period(
        second_model, third_model, valid_features, valid_predictions, valid_boats, valid_payouts
    )
    valid_rows, valid_swap_skip = attach_swap_candidates(
        valid_scenarios["CURRENT"], valid_features, second_model
    )
    selected_scope, selected_margin, tuning_table, eligible = tune_policy(valid_rows)

    test_predictions = cut_audit.load_prediction_rows(TEST_START, TEST_END)
    test_boats = cut_audit.load_boat_rows(TEST_START, TEST_END)
    test_payouts = second_bt.payout_util.load_trifecta_payouts(TEST_START, TEST_END)
    test_scenarios, test_base_skip, test_match = cut_audit.build_period(
        second_model, third_model, test_features, test_predictions, test_boats, test_payouts
    )
    test_rows, test_swap_skip = attach_swap_candidates(
        test_scenarios["CURRENT"], test_features, second_model
    )

    if selected_scope is None or selected_margin is None:
        selected_rows = apply_policy(test_rows, 999.0, "both")
        selected_summary = summarize(selected_rows)
        bootstrap_result = None
    else:
        selected_rows = apply_policy(test_rows, selected_margin, selected_scope)
        selected_summary = summarize(selected_rows)
        bootstrap_result = bootstrap(selected_rows)

    no_margin_reference = summarize(apply_policy(test_rows, 0.0, "both"))
    report = {
        "purpose": "現行切る艇のAI2着率が既存2着候補最下位を上回る場合に、2着候補だけを入れ替える",
        "production_changed": False,
        "periods": {
            "model_train_end": TRAIN_END.isoformat(),
            "tuning": [VALID_START.isoformat(), VALID_END.isoformat()],
            "test": [TEST_START.isoformat(), TEST_END.isoformat()],
        },
        "policy": {
            "head_changed": False,
            "third_candidates_changed": False,
            "cut_rule_changed": False,
            "maximum_swaps_per_side": 1,
            "selected_scope": selected_scope,
            "selected_probability_ratio_margin": selected_margin,
            "maximum_validation_point_growth_rate": MAX_VALID_POINT_GROWTH,
            "eligible_policies": eligible,
        },
        "coverage": {
            "source_skip": source_skip,
            "validation": {
                "feature_races": len(valid_features),
                "base_skip": valid_base_skip,
                "swap_skip": valid_swap_skip,
                "current_reconstruction": valid_match,
            },
            "test": {
                "feature_races": len(test_features),
                "base_skip": test_base_skip,
                "swap_skip": test_swap_skip,
                "current_reconstruction": test_match,
            },
        },
        "tuning_candidates": tuning_table,
        "test_selected": selected_summary,
        "test_no_margin_reference": no_margin_reference,
        "bootstrap": bootstrap_result,
    }
    OUTPUT_PATH.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")

    print("=" * 100)
    print("切る艇のAI2着率v1・2着候補入れ替え検証（現在のAI買い目込み）")
    print("=" * 100)
    print(
        f"調整={VALID_START}～{VALID_END} / テスト={TEST_START}～{TEST_END} / "
        f"選択={selected_scope} / 倍率={selected_margin}"
    )
    if selected_scope is None or selected_margin is None:
        print("調整期間で採用条件を満たす入れ替え基準はありませんでした。")
    else:
        validation_selected = next(
            row for row in tuning_table
            if row["scope"] == selected_scope and row["margin"] == selected_margin
        )
        print_summary("調整期間・選択基準", validation_selected)
        print_summary("未使用テスト期間", selected_summary)
    print_summary("参考：倍率条件なしで両側入れ替え", no_margin_reference)
    if bootstrap_result is not None:
        boot = bootstrap_result["hit_rate_delta"]
        print(
            "\n日単位bootstrap 的中率差: "
            f"median={boot['median']*100:+.3f}pt / "
            f"95%CI=[{boot['ci95'][0]*100:+.3f}, {boot['ci95'][1]*100:+.3f}]pt / "
            f"改善確率={boot['improvement_probability']*100:.2f}%"
        )
    print(f"保存: {OUTPUT_PATH}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
