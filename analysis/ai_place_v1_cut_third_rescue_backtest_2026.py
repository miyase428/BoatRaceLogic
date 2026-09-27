#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""現行で切られた艇をAI3着率v1で3着候補だけへ最大1艇救済する検証。"""

from __future__ import annotations

import json
import random
from collections import defaultdict
from datetime import date
from pathlib import Path

import numpy as np

import ai_place_v1_final_second_backtest_2026 as second_bt
import ai_place_v1_third_candidate_prune_backtest_2026 as third_bt
import audit_current_cut_rule_2026 as cut_audit


ROOT = Path(__file__).resolve().parent.parent
TRAIN_END = date(2026, 8, 31)
VALID_START = date(2026, 9, 1)
VALID_END = date(2026, 9, 10)
TEST_START = date(2026, 9, 11)
TEST_END = date(2026, 9, 21)
THRESHOLDS = tuple(round(float(x), 3) for x in np.arange(0.05, 0.525, 0.025))
SCOPES = ("both", "honmei", "taikou")
MAX_VALID_POINT_GROWTH = 0.03
BOOTSTRAP_N = 5000
BOOTSTRAP_SEED = 428
OUTPUT_PATH = ROOT / "analysis/output/ai_place_v1_cut_third_rescue_backtest_2026.json"


def normalized_third_mass(
    second_model,
    third_model,
    race,
    head: int,
    seconds: list[int],
    cache: dict,
):
    if head not in cache["second"]:
        cache["second"][head] = second_bt.ml_second_probabilities(
            second_model, race, head
        )
    second_probability = cache["second"][head]
    score = {boat: 0.0 for boat in range(1, 7) if boat != head}
    for second in seconds:
        key = (head, second)
        if key not in cache["third"]:
            cache["third"][key] = third_bt.third_probabilities(
                third_model, race, head, second
            )
        conditional = cache["third"][key]
        for third in score:
            if third != second:
                score[third] += second_probability[second] * conditional.get(third, 0.0)
    total = sum(score.values())
    if total <= 0.0:
        return {boat: 0.0 for boat in score}
    return {boat: value / total for boat, value in score.items()}


def attach_rescue_candidates(
    current_rows,
    feature_races,
    second_model,
    third_model,
):
    feature_by_code = {race["race_code"]: race for race in feature_races}
    rows = []
    skipped = defaultdict(int)

    for current in current_rows:
        race = feature_by_code.get(current["race_code"])
        if race is None:
            skipped["feature_missing"] += 1
            continue
        cache = {"second": {}, "third": {}}
        sides = {}
        for side in ("honmei", "taikou"):
            base = current[side]
            head = int(base["head"])
            seconds = list(base["seconds"])
            cuts = sorted(boat for boat in base["cuts"] if boat != head)
            mass = normalized_third_mass(
                second_model, third_model, race, head, seconds, cache
            )
            rescue_boat = None
            rescue_share = 0.0
            if cuts:
                rescue_boat = max(cuts, key=lambda boat: (mass.get(boat, 0.0), -boat))
                rescue_share = float(mass.get(rescue_boat, 0.0))
            sides[side] = {
                "head": head,
                "seconds": seconds,
                "base_thirds": list(base["thirds"]),
                "base_tickets": set(base["tickets"]),
                "cut_boats": cuts,
                "rescue_boat": rescue_boat,
                "rescue_share": rescue_share,
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


def side_candidate(side, threshold: float):
    rescue_boat = side["rescue_boat"]
    applied = rescue_boat is not None and side["rescue_share"] >= threshold
    thirds = list(side["base_thirds"])
    if applied and rescue_boat not in thirds:
        thirds.append(rescue_boat)
        thirds.sort()
    tickets = second_bt.expand(side["head"], side["seconds"], thirds)
    return tickets, applied, rescue_boat if applied else None


def apply_threshold(rows, threshold: float, scope: str = "both"):
    output = []
    for row in rows:
        tickets = set()
        applied_sides = []
        rescued_boats = []
        for side_name in ("honmei", "taikou"):
            side = row[side_name]
            if scope == "both" or scope == side_name:
                side_tickets, applied, rescue_boat = side_candidate(side, threshold)
            else:
                side_tickets = side["base_tickets"]
                applied = False
                rescue_boat = None
            tickets |= side_tickets
            if applied:
                applied_sides.append(side_name)
                rescued_boats.append(rescue_boat)
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
            "rescued_actual_third": actual[2] in rescued_boats,
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
    added_points = candidate["points"] - current["points"]
    gained = []
    for row in rows:
        before = row["actual"] in row["current_tickets"]
        after = row["actual"] in row["candidate_tickets"]
        if after and not before:
            gained.append({
                "race_code": row["race_code"],
                "actual": list(row["actual"]),
                "payout_yen": row["payout"],
                "rescued_boats": row["rescued_boats"],
                "applied_sides": row["applied_sides"],
            })
    return {
        "current": current,
        "ai_third_rescue": candidate,
        "rescued_races": sum(bool(row["applied_sides"]) for row in rows),
        "honmei_rescued_races": sum("honmei" in row["applied_sides"] for row in rows),
        "taikou_rescued_races": sum("taikou" in row["applied_sides"] for row in rows),
        "rescued_actual_third_races": sum(row["rescued_actual_third"] for row in rows),
        "gained_hits": len(gained),
        "gained_hit_details": gained,
        "delta": {
            "points": added_points,
            "point_growth_rate": candidate["points"] / current["points"] - 1.0 if current["points"] else 0.0,
            "hits": candidate["hits"] - current["hits"],
            "hit_rate": candidate["hit_rate"] - current["hit_rate"],
            "hits_per_1000_points": candidate["hits_per_1000_points"] - current["hits_per_1000_points"],
            "roi": candidate["roi"] - current["roi"],
            "incremental_hits_per_1000_points": len(gained) * 1000.0 / added_points if added_points else 0.0,
        },
    }


def tune_threshold(rows):
    table = []
    for scope in SCOPES:
        for threshold in THRESHOLDS:
            result = summarize(apply_threshold(rows, threshold, scope))
            table.append({"scope": scope, "threshold": threshold, **result})

    current_efficiency = table[0]["current"]["hits_per_1000_points"]
    eligible = [
        row for row in table
        if row["delta"]["hits"] > 0
        and row["delta"]["point_growth_rate"] <= MAX_VALID_POINT_GROWTH
        and row["delta"]["incremental_hits_per_1000_points"] >= current_efficiency
    ]
    if not eligible:
        return None, None, table, []
    selected = max(
        eligible,
        key=lambda row: (
            row["delta"]["hits"],
            row["delta"]["incremental_hits_per_1000_points"],
            row["threshold"],
        ),
    )
    return (
        str(selected["scope"]),
        float(selected["threshold"]),
        table,
        [{"scope": row["scope"], "threshold": row["threshold"]} for row in eligible],
    )


def bootstrap(rows):
    by_day = defaultdict(list)
    for row in rows:
        by_day[row["race_date"]].append(row)
    days = sorted(by_day)
    rng = random.Random(BOOTSTRAP_SEED)
    hit_deltas = []
    point_growth = []
    roi_deltas = []
    for _ in range(BOOTSTRAP_N):
        sample = []
        for _index in range(len(days)):
            sample.extend(by_day[rng.choice(days)])
        result = summarize(sample)
        hit_deltas.append(result["delta"]["hit_rate"])
        point_growth.append(result["delta"]["point_growth_rate"])
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
        "point_growth_rate": metric(point_growth),
        "roi_delta": {
            **metric(roi_deltas),
            "improvement_probability": sum(value > 0 for value in roi_deltas) / len(roi_deltas),
        },
    }


def print_summary(label, result):
    print(f"\n【{label}】")
    print("方式             的中数  的中率  平均点数  千点的中      ROI")
    for name, row in (("CURRENT", result["current"]), ("AI_RESCUE", result["ai_third_rescue"])):
        print(
            f"{name:<16} {row['hits']:>5d}  {row['hit_rate']*100:>6.2f}%  "
            f"{row['average_points']:>8.2f}  {row['hits_per_1000_points']:>8.2f}  "
            f"{row['roi']*100:>7.2f}%"
        )
    delta = result["delta"]
    print(
        f"救済={result['rescued_races']}R / 実3着救済={result['rescued_actual_third_races']}R / "
        f"的中純増={delta['hits']:+d} / 点数={delta['points']:+d} "
        f"({delta['point_growth_rate']*100:+.2f}%) / 追加千点的中={delta['incremental_hits_per_1000_points']:.2f}"
    )


def main():
    print("AI2着・AI3着率v1の8月末固定モデルを再学習しています…", flush=True)
    all_races, _place_to_id, source_skip = second_bt.place_train.build_races()
    train = [race for race in all_races if race["race_date"] <= TRAIN_END]
    valid_features = [race for race in all_races if VALID_START <= race["race_date"] <= VALID_END]
    test_features = [race for race in all_races if TEST_START <= race["race_date"] <= TEST_END]

    x2, y2, g2 = second_bt.place_train.matrices(train, "second")
    second_model = second_bt.place_train.make_ranker()
    second_model.fit(x2, y2, group=g2)
    x3, y3, g3 = second_bt.place_train.matrices(train, "third")
    third_model = second_bt.place_train.make_ranker()
    third_model.fit(x3, y3, group=g3)

    valid_predictions = cut_audit.load_prediction_rows(VALID_START, VALID_END)
    valid_boats = cut_audit.load_boat_rows(VALID_START, VALID_END)
    valid_payouts = second_bt.payout_util.load_trifecta_payouts(VALID_START, VALID_END)
    valid_scenarios, valid_base_skip, valid_match = cut_audit.build_period(
        second_model,
        third_model,
        valid_features,
        valid_predictions,
        valid_boats,
        valid_payouts,
    )
    valid_rows, valid_rescue_skip = attach_rescue_candidates(
        valid_scenarios["CURRENT"], valid_features, second_model, third_model
    )
    selected_scope, selected_threshold, tuning_table, eligible_policies = tune_threshold(valid_rows)

    test_predictions = cut_audit.load_prediction_rows(TEST_START, TEST_END)
    test_boats = cut_audit.load_boat_rows(TEST_START, TEST_END)
    test_payouts = second_bt.payout_util.load_trifecta_payouts(TEST_START, TEST_END)
    test_scenarios, test_base_skip, test_match = cut_audit.build_period(
        second_model,
        third_model,
        test_features,
        test_predictions,
        test_boats,
        test_payouts,
    )
    test_rows, test_rescue_skip = attach_rescue_candidates(
        test_scenarios["CURRENT"], test_features, second_model, third_model
    )

    if selected_threshold is None or selected_scope is None:
        selected_rows = apply_threshold(test_rows, 1.0, "both")
        selected_summary = summarize(selected_rows)
        side_summaries = {}
        bootstrap_result = None
    else:
        selected_rows = apply_threshold(test_rows, selected_threshold, selected_scope)
        selected_summary = summarize(selected_rows)
        side_summaries = {
            side: summarize(apply_threshold(test_rows, selected_threshold, side))
            for side in ("honmei", "taikou")
        }
        bootstrap_result = bootstrap(selected_rows)

    all_rescue_summary = summarize(apply_threshold(test_rows, 0.0, "both"))
    report = {
        "purpose": "現行で切られた艇を、頭・2着候補を変えずAI3着率v1で3着だけ最大1艇救済",
        "production_changed": False,
        "periods": {
            "model_train_end": TRAIN_END.isoformat(),
            "tuning": [VALID_START.isoformat(), VALID_END.isoformat()],
            "test": [TEST_START.isoformat(), TEST_END.isoformat()],
        },
        "policy": {
            "maximum_rescue_boats_per_side": 1,
            "head_changed": False,
            "second_candidates_changed": False,
            "cut_rule_changed": False,
            "rescue_position": "third_only",
            "score": "現行頭・AI2着候補を条件としたAI3着確率質量を全候補内で正規化",
            "maximum_validation_point_growth_rate": MAX_VALID_POINT_GROWTH,
            "minimum_incremental_efficiency": "現行の1000点当たり的中以上",
            "selected_probability_share_threshold": selected_threshold,
            "selected_scope": selected_scope,
            "eligible_policies": eligible_policies,
        },
        "coverage": {
            "source_skip": source_skip,
            "validation": {
                "feature_races": len(valid_features),
                "base_skip": valid_base_skip,
                "rescue_skip": valid_rescue_skip,
                "current_reconstruction": valid_match,
            },
            "test": {
                "feature_races": len(test_features),
                "base_skip": test_base_skip,
                "rescue_skip": test_rescue_skip,
                "current_reconstruction": test_match,
            },
        },
        "tuning_candidates": tuning_table,
        "test_selected": selected_summary,
        "test_selected_by_side": side_summaries,
        "test_rescue_every_cut_reference": all_rescue_summary,
        "bootstrap": bootstrap_result,
    }
    OUTPUT_PATH.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")

    print("=" * 100)
    print("切る艇のAI3着率v1・3着限定救済（現在のAI買い目込み）")
    print("=" * 100)
    print(
        f"調整={VALID_START}～{VALID_END} / テスト={TEST_START}～{TEST_END} / "
        f"選択={selected_scope} / 閾値={selected_threshold}"
    )
    if selected_threshold is None:
        print("調整期間で採用条件を満たす救済基準はありませんでした。")
    else:
        validation_selected = next(
            row for row in tuning_table
            if row["scope"] == selected_scope
            and abs(row["threshold"] - selected_threshold) < 1.0e-12
        )
        print_summary("調整期間・選択基準", validation_selected)
        print_summary("未使用テスト期間", selected_summary)
        print_summary("未使用テスト・本命側だけ", side_summaries["honmei"])
        print_summary("未使用テスト・対抗側だけ", side_summaries["taikou"])
    print_summary("参考：切る艇があれば常に1艇救済", all_rescue_summary)
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
