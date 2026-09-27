#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""AI3着率v1で3着候補を1艇絞る、時系列分離済みバックテスト。"""

from __future__ import annotations

import csv
import json
import random
from collections import defaultdict
from datetime import date
from pathlib import Path

import numpy as np

import ai_place_v1_final_second_backtest_2026 as second_bt
from ai_place_features import vector


ROOT = Path(__file__).resolve().parent.parent
VALID_START = date(2026, 9, 1)
VALID_END = date(2026, 9, 10)
TEST_START = date(2026, 9, 11)
TEST_END = date(2026, 9, 21)
THIRD_TEMPERATURE = 0.8
MIN_THIRD_COUNT = 4
MIN_VALID_HIT_RETENTION = 1.0
THRESHOLDS = (-1.0,) + tuple(float(x) for x in np.linspace(0.01, 0.20, 20)) + (1.0,)
BOOTSTRAP_N = 5000
BOOTSTRAP_SEED = 428
OUTPUT_PATH = ROOT / "analysis/output/ai_place_v1_third_candidate_full_five_backtest_2026.json"


def load_prediction_rows(start: date, end: date):
    rows = {}
    for path in second_bt.RACE_FILES:
        with path.open("r", encoding="utf-8-sig", newline="") as handle:
            for row in csv.DictReader(handle):
                race_day = date.fromisoformat(str(row.get("race_date", "")))
                if start <= race_day <= end:
                    rows[str(row["race_code"])] = row
    return rows


def third_probabilities(model, race, head: int, second: int):
    candidates = [boat for boat in range(1, 7) if boat not in (head, second)]
    x = np.vstack([
        vector(
            race["boats"][candidate],
            race["boats"][head],
            race["race_number"],
            race["place_id"],
            race["boats"][second],
        )
        for candidate in candidates
    ])
    raw = np.asarray(model.predict(x), dtype=np.float64) / THIRD_TEMPERATURE
    raw -= raw.max()
    values = np.exp(raw)
    values /= values.sum()
    return {boat: float(value) for boat, value in zip(candidates, values)}


def make_side_record(second_model, third_model, race, current, payouts, side: str):
    formation = second_bt.parse_formation(current.get(f"{side}_kai", ""))
    if formation is None:
        return None, "formation_invalid"
    head, current_seconds, thirds = formation
    if head != int(float(current.get(f"{side}_head", 0) or 0)):
        return None, "head_mismatch"

    second_prob = second_bt.ml_second_probabilities(second_model, race, head)
    eligible_seconds = [boat for boat in thirds if boat != head]
    if len(eligible_seconds) < len(current_seconds):
        return None, "eligible_second_short"
    ml_seconds = sorted(
        eligible_seconds,
        key=lambda boat: (-second_prob[boat], boat),
    )[:len(current_seconds)]

    current_tickets = second_bt.expand(head, ml_seconds, thirds)
    third_score = {boat: 0.0 for boat in thirds}
    for second in ml_seconds:
        conditional = third_probabilities(third_model, race, head, second)
        for third in thirds:
            if third == second:
                continue
            third_score[third] += second_prob[second] * conditional.get(third, 0.0)

    score_total = sum(third_score.values())
    droppable = len(thirds) >= MIN_THIRD_COUNT and score_total > 0.0
    drop_boat = None
    drop_share = 1.0
    pruned_thirds = list(thirds)
    if droppable:
        drop_boat = min(thirds, key=lambda boat: (third_score[boat], -boat))
        drop_share = third_score[drop_boat] / score_total
        pruned_thirds = [boat for boat in thirds if boat != drop_boat]

    actual = (
        int(float(current.get("actual_1st", 0) or 0)),
        int(float(current.get("actual_2nd", 0) or 0)),
        int(float(current.get("actual_3rd", 0) or 0)),
    )
    if set(actual) - set(range(1, 7)) or len(set(actual)) != 3:
        return None, "actual_invalid"

    return {
        "race_code": race["race_code"],
        "race_date": race["race_date"],
        "head": head,
        "head_course": int(race["boats"][head]["course"]),
        "seconds": ml_seconds,
        "thirds": thirds,
        "pruned_thirds": pruned_thirds,
        "drop_boat": drop_boat,
        "drop_share": drop_share,
        "droppable": droppable,
        "current_tickets": current_tickets,
        "pruned_tickets": second_bt.expand(head, ml_seconds, pruned_thirds),
        "actual": actual,
        "payout": float(payouts.get(race["race_code"], 0.0)),
    }, "ready"


def build_side_records(second_model, third_model, races, prediction_rows, payouts, side):
    race_by_code = {race["race_code"]: race for race in races}
    records = {}
    skipped = defaultdict(int)
    for code, current in sorted(prediction_rows.items()):
        race = race_by_code.get(code)
        if race is None:
            skipped["feature_missing"] += 1
            continue
        record, reason = make_side_record(
            second_model, third_model, race, current, payouts, side
        )
        skipped[reason] += 1
        if record is not None:
            records[code] = record
    return records, dict(skipped)


def apply_threshold(record, threshold: float, only_full_five: bool = False):
    if (
        threshold < 0.0
        or not record["droppable"]
        or (only_full_five and len(record["thirds"]) != 5)
        or record["drop_share"] > threshold
    ):
        return record["current_tickets"], False
    return record["pruned_tickets"], True


def build_combined_rows(honmei, taikou, threshold: float, only_full_five: bool = False):
    rows = []
    for code in sorted(set(honmei) & set(taikou)):
        h = honmei[code]
        t = taikou[code]
        h_candidate, h_pruned = apply_threshold(h, threshold, only_full_five)
        t_candidate, t_pruned = apply_threshold(t, threshold, only_full_five)
        current_tickets = h["current_tickets"] | t["current_tickets"]
        candidate_tickets = h_candidate | t_candidate
        rows.append({
            "race_code": code,
            "race_date": h["race_date"],
            "actual": h["actual"],
            "payout": h["payout"],
            "current_tickets": current_tickets,
            "candidate_tickets": candidate_tickets,
            "current_points": len(current_tickets),
            "candidate_points": len(candidate_tickets),
            "honmei_pruned": h_pruned,
            "taikou_pruned": t_pruned,
        })
    return rows


def evaluate(rows, method: str):
    tickets_key = f"{method}_tickets"
    points_key = f"{method}_points"
    points = sum(row[points_key] for row in rows)
    hits = sum(row["actual"] in row[tickets_key] for row in rows)
    returns = sum(
        row["payout"] for row in rows if row["actual"] in row[tickets_key]
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
    lost_payouts = [
        row["payout"]
        for row in rows
        if row["actual"] in row["current_tickets"]
        and row["actual"] not in row["candidate_tickets"]
    ]
    return {
        "current_all_thirds": current,
        "ai_pruned": candidate,
        "pruned_races": sum(row["honmei_pruned"] or row["taikou_pruned"] for row in rows),
        "honmei_pruned_races": sum(row["honmei_pruned"] for row in rows),
        "taikou_pruned_races": sum(row["taikou_pruned"] for row in rows),
        "delta": {
            "points": candidate["points"] - current["points"],
            "point_reduction_rate": 1.0 - candidate["points"] / current["points"] if current["points"] else 0.0,
            "hits": candidate["hits"] - current["hits"],
            "hit_retention": candidate["hits"] / current["hits"] if current["hits"] else 1.0,
            "hit_rate": candidate["hit_rate"] - current["hit_rate"],
            "hits_per_1000_points": candidate["hits_per_1000_points"] - current["hits_per_1000_points"],
            "roi": candidate["roi"] - current["roi"],
            "lost_hit_count": len(lost_payouts),
            "lost_return_yen": sum(lost_payouts),
            "lost_payout_median_yen": float(np.median(lost_payouts)) if lost_payouts else 0.0,
            "lost_payout_max_yen": max(lost_payouts, default=0.0),
        },
    }


def tune_threshold(honmei, taikou, only_full_five: bool = False):
    table = []
    for threshold in THRESHOLDS:
        summary = summarize(build_combined_rows(honmei, taikou, threshold, only_full_five))
        table.append({"threshold": threshold, **summary})
    eligible = [
        row for row in table
        if row["delta"]["hit_retention"] >= MIN_VALID_HIT_RETENTION
    ]
    selected = max(
        eligible,
        key=lambda row: (
            row["delta"]["point_reduction_rate"],
            row["delta"]["hit_retention"],
            -row["threshold"],
        ),
    )
    return float(selected["threshold"]), table


def bootstrap(rows):
    by_day = defaultdict(list)
    for row in rows:
        by_day[row["race_date"]].append(row)
    days = sorted(by_day)
    rng = random.Random(BOOTSTRAP_SEED)
    point_reductions = []
    hit_retentions = []
    roi_deltas = []
    for _ in range(BOOTSTRAP_N):
        sample = []
        for _index in range(len(days)):
            sample.extend(by_day[rng.choice(days)])
        delta = summarize(sample)["delta"]
        point_reductions.append(delta["point_reduction_rate"])
        hit_retentions.append(delta["hit_retention"])
        roi_deltas.append(delta["roi"])
    metric = lambda values: {
        "median": float(np.median(values)),
        "ci95": [float(np.quantile(values, 0.025)), float(np.quantile(values, 0.975))],
    }
    return {
        "days": len(days),
        "iterations": BOOTSTRAP_N,
        "point_reduction_rate": metric(point_reductions),
        "hit_retention": metric(hit_retentions),
        "roi_delta": {
            **metric(roi_deltas),
            "improvement_probability": sum(value > 0 for value in roi_deltas) / len(roi_deltas),
        },
    }


def print_summary(label, summary):
    current = summary["current_all_thirds"]
    candidate = summary["ai_pruned"]
    delta = summary["delta"]
    print(f"\n【{label}】")
    print("方式             的中数  的中率  平均点数  1000点当り的中      ROI")
    for name, row in (("CURRENT", current), ("AI_PRUNED", candidate)):
        print(
            f"{name:<16} {row['hits']:>5d}  {row['hit_rate']*100:>6.2f}%  "
            f"{row['average_points']:>7.2f}  {row['hits_per_1000_points']:>12.2f}  "
            f"{row['roi']*100:>7.2f}%"
        )
    print(
        f"点数削減={delta['point_reduction_rate']*100:.2f}% / "
        f"的中維持={delta['hit_retention']*100:.2f}% / "
        f"的中差={delta['hits']:+d} / ROI差={delta['roi']*100:+.2f}pt"
    )


def main():
    print("AI2着・AI3着率v1の8月末時点モデルを再学習しています…", flush=True)
    races, _place_to_id, source_skip = second_bt.place_train.build_races()
    train = [race for race in races if race["race_date"] <= second_bt.TRAIN_END]
    valid = [race for race in races if VALID_START <= race["race_date"] <= VALID_END]
    test = [race for race in races if TEST_START <= race["race_date"] <= TEST_END]

    x2, y2, g2 = second_bt.place_train.matrices(train, "second")
    second_model = second_bt.place_train.make_ranker()
    second_model.fit(x2, y2, group=g2)
    x3, y3, g3 = second_bt.place_train.matrices(train, "third")
    third_model = second_bt.place_train.make_ranker()
    third_model.fit(x3, y3, group=g3)

    valid_predictions = load_prediction_rows(VALID_START, VALID_END)
    test_predictions = load_prediction_rows(TEST_START, TEST_END)
    test_payouts = second_bt.payout_util.load_trifecta_payouts(TEST_START, TEST_END)

    valid_h, valid_h_skip = build_side_records(
        second_model, third_model, valid, valid_predictions, {}, "honmei"
    )
    valid_t, valid_t_skip = build_side_records(
        second_model, third_model, valid, valid_predictions, {}, "taikou"
    )
    selected_threshold, tuning_table = tune_threshold(valid_h, valid_t, True)

    test_h, test_h_skip = build_side_records(
        second_model, third_model, test, test_predictions, test_payouts, "honmei"
    )
    test_t, test_t_skip = build_side_records(
        second_model, third_model, test, test_predictions, test_payouts, "taikou"
    )
    selected_rows = build_combined_rows(test_h, test_t, selected_threshold, True)
    always_rows = build_combined_rows(test_h, test_t, 1.0, True)
    selected_summary = summarize(selected_rows)
    always_summary = summarize(always_rows)
    bootstrap_result = bootstrap(selected_rows)

    report = {
        "purpose": "切る艇なし相当の3着候補5艇時だけ、AI3着率v1最下位を1艇絞れるか検証",
        "production_changed": False,
        "periods": {
            "train_end": second_bt.TRAIN_END.isoformat(),
            "tuning": [VALID_START.isoformat(), VALID_END.isoformat()],
            "test": [TEST_START.isoformat(), TEST_END.isoformat()],
        },
        "policy": {
            "third_temperature": THIRD_TEMPERATURE,
            "minimum_third_candidates_before_prune": MIN_THIRD_COUNT,
            "scope": "third_candidates_exactly_5_only",
            "minimum_validation_hit_retention": MIN_VALID_HIT_RETENTION,
            "selected_drop_probability_share_threshold": selected_threshold,
            "meaning": "3着候補内の最下位艇が持つ選択買い目確率質量の比率が閾値以下なら1艇だけ外す",
        },
        "coverage": {
            "valid_feature_races": len(valid),
            "test_feature_races": len(test),
            "valid_compared_races": len(set(valid_h) & set(valid_t)),
            "test_compared_races": len(selected_rows),
            "source_skip": source_skip,
            "valid_honmei_skip": valid_h_skip,
            "valid_taikou_skip": valid_t_skip,
            "test_honmei_skip": test_h_skip,
            "test_taikou_skip": test_t_skip,
        },
        "tuning_candidates": tuning_table,
        "test_selected_policy": selected_summary,
        "test_selected_pruned_races": [
            {
                "race_code": row["race_code"],
                "honmei_pruned": row["honmei_pruned"],
                "taikou_pruned": row["taikou_pruned"],
                "current_points": row["current_points"],
                "candidate_points": row["candidate_points"],
            }
            for row in selected_rows
            if row["honmei_pruned"] or row["taikou_pruned"]
        ],
        "test_always_drop_one": always_summary,
        "bootstrap_selected_policy": bootstrap_result,
    }
    OUTPUT_PATH.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")

    print("=" * 96)
    print("AI3着率v1：3着候補5艇時だけ4艇へ絞る検証（合算最終買い目）")
    print("=" * 96)
    print(
        f"調整期間で選択した閾値={selected_threshold:.3f} / "
        f"テスト={len(selected_rows):,}R"
    )
    print_summary("選択ポリシー", selected_summary)
    print_summary("常に1艇削る参考", always_summary)
    boot = bootstrap_result
    print(
        "\n日単位bootstrap: "
        f"点数削減={boot['point_reduction_rate']['median']*100:.2f}% "
        f"(95%CI {boot['point_reduction_rate']['ci95'][0]*100:.2f}～{boot['point_reduction_rate']['ci95'][1]*100:.2f}%) / "
        f"的中維持={boot['hit_retention']['median']*100:.2f}% "
        f"(95%CI {boot['hit_retention']['ci95'][0]*100:.2f}～{boot['hit_retention']['ci95'][1]*100:.2f}%)"
    )
    print(f"保存: {OUTPUT_PATH}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
