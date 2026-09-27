#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""現行「切る艇」判定の構成要素を、現在のAI買い目まで含めて監査する。"""

from __future__ import annotations

import csv
import json
import random
from collections import defaultdict
from datetime import date
from pathlib import Path

import numpy as np

import ai_place_v1_final_second_backtest_2026 as second_bt
import ai_place_v1_third_candidate_prune_backtest_2026 as third_bt


ROOT = Path(__file__).resolve().parent.parent
TRAIN_END = date(2026, 8, 31)
VALID_START = date(2026, 9, 1)
VALID_END = date(2026, 9, 10)
TEST_START = date(2026, 9, 11)
TEST_END = date(2026, 9, 21)
MAX_VALID_POINT_GROWTH = 0.03
BOOTSTRAP_N = 5000
BOOTSTRAP_SEED = 428
OUTPUT_PATH = ROOT / "analysis/output/current_cut_rule_audit_2026.json"

BOAT_FILES = (
    ROOT / "analysis/output/final_prediction_boats_fast_cached_20260901_20260917.csv",
    ROOT / "analysis/output/final_prediction_boats_fast_cached_20260915_20260921.csv",
)


SCENARIOS = {
    "CURRENT": {
        "label": "現行",
        "score_mode": "median",
        "rate_mode": "or",
        "rate_threshold": 0.50,
    },
    "BOTTOM2_OR50": {
        "label": "スコア下位2艇だけ・率OR50%",
        "score_mode": "bottom2",
        "rate_mode": "or",
        "rate_threshold": 0.50,
    },
    "BOTTOM1_OR50": {
        "label": "スコア最下位だけ・率OR50%",
        "score_mode": "bottom1",
        "rate_mode": "or",
        "rate_threshold": 0.50,
    },
    "MEDIAN_OR45": {
        "label": "中央値下・率OR45%",
        "score_mode": "median",
        "rate_mode": "or",
        "rate_threshold": 0.45,
    },
    "MEDIAN_OR40": {
        "label": "中央値下・率OR40%",
        "score_mode": "median",
        "rate_mode": "or",
        "rate_threshold": 0.40,
    },
    "MEDIAN_BOTH50": {
        "label": "中央値下・3か月6か月とも50%未満",
        "score_mode": "median",
        "rate_mode": "both",
        "rate_threshold": 0.50,
    },
    "BOTTOM2_OR45": {
        "label": "スコア下位2艇だけ・率OR45%",
        "score_mode": "bottom2",
        "rate_mode": "or",
        "rate_threshold": 0.45,
    },
    "PROTECT_1": {
        "label": "現行＋1号艇保護",
        "score_mode": "median",
        "rate_mode": "or",
        "rate_threshold": 0.50,
        "protect_1": True,
    },
    "PROTECT_1_HONMEI": {
        "label": "本命側だけ1号艇保護",
        "score_mode": "median",
        "rate_mode": "or",
        "rate_threshold": 0.50,
        "protect_1_side": "honmei",
    },
    "PROTECT_1_TAIKOU": {
        "label": "対抗側だけ1号艇保護",
        "score_mode": "median",
        "rate_mode": "or",
        "rate_threshold": 0.50,
        "protect_1_side": "taikou",
    },
    "PROTECT_1_NO_TYPE_BONUS": {
        "label": "1号艇保護＋切り判定のタイプ補正なし",
        "score_mode": "median",
        "rate_mode": "or",
        "rate_threshold": 0.50,
        "protect_1": True,
        "no_type_bonus": True,
    },
    "R3_BOTH": {
        "label": "一次評価3位保護を本命・対抗に適用",
        "score_mode": "median",
        "rate_mode": "or",
        "rate_threshold": 0.50,
        "r3_both": True,
    },
    "PROTECT_1_R3_BOTH": {
        "label": "1号艇保護＋一次3位保護を両側適用",
        "score_mode": "median",
        "rate_mode": "or",
        "rate_threshold": 0.50,
        "protect_1": True,
        "r3_both": True,
    },
    "NO_TYPE_BONUS": {
        "label": "タイプ補正なし",
        "score_mode": "median",
        "rate_mode": "or",
        "rate_threshold": 0.50,
        "no_type_bonus": True,
    },
    "NO_24_PROTECT": {
        "label": "2・4号艇固定保護なし（診断用）",
        "score_mode": "median",
        "rate_mode": "or",
        "rate_threshold": 0.50,
        "no_24_protect": True,
    },
    "NO_CUT": {
        "label": "切る艇なし（上限参考）",
        "no_cut": True,
    },
}

SELECTION_CANDIDATES = (
    "BOTTOM2_OR50",
    "BOTTOM1_OR50",
    "MEDIAN_OR45",
    "MEDIAN_OR40",
    "MEDIAN_BOTH50",
    "BOTTOM2_OR45",
    "PROTECT_1",
    "PROTECT_1_HONMEI",
    "PROTECT_1_TAIKOU",
    "NO_TYPE_BONUS",
    "PROTECT_1_NO_TYPE_BONUS",
    "R3_BOTH",
    "PROTECT_1_R3_BOTH",
)


def fnum(value, default=0.0):
    try:
        return float(value)
    except (TypeError, ValueError):
        return float(default)


def inum(value, default=0):
    try:
        return int(float(value))
    except (TypeError, ValueError):
        return int(default)


def load_boat_rows(start: date, end: date):
    races = {}
    for path in BOAT_FILES:
        with path.open("r", encoding="utf-8-sig", newline="") as handle:
            for row in csv.DictReader(handle):
                race_day = date.fromisoformat(str(row.get("race_date", "")))
                if not start <= race_day <= end:
                    continue
                lane = inum(row.get("lane_number"))
                if 1 <= lane <= 6:
                    races.setdefault(str(row["race_code"]), {})[lane] = row
    return races


def load_prediction_rows(start: date, end: date):
    rows = {}
    for path in second_bt.RACE_FILES:
        with path.open("r", encoding="utf-8-sig", newline="") as handle:
            for row in csv.DictReader(handle):
                race_day = date.fromisoformat(str(row.get("race_date", "")))
                if start <= race_day <= end:
                    rows[str(row["race_code"])] = row
    return rows


def score_flags(boats, scenario):
    if scenario.get("no_cut"):
        return {boat: False for boat in boats}

    scores = {
        boat: fnum(row.get("second_score" if scenario.get("no_type_bonus") else "final3"))
        for boat, row in boats.items()
    }
    mode = scenario["score_mode"]
    if mode == "median":
        values = sorted(scores.values())
        median = (values[2] + values[3]) / 2.0
        return {boat: score < median for boat, score in scores.items()}

    bottom_n = 2 if mode == "bottom2" else 1
    lowest = {
        boat for boat, _score in sorted(scores.items(), key=lambda item: (item[1], -item[0]))[:bottom_n]
    }
    return {boat: boat in lowest for boat in boats}


def make_cut_set(boats, scenario, side):
    score_low = score_flags(boats, scenario)
    cuts = set()
    if scenario.get("no_cut"):
        return cuts

    threshold = float(scenario["rate_threshold"])
    for boat, row in boats.items():
        rate6 = fnum(row.get("three_in_rate_6m"))
        rate3 = fnum(row.get("three_in_rate_3m"))
        if scenario["rate_mode"] == "both":
            rate_low = rate6 < threshold and rate3 < threshold
        else:
            rate_low = rate6 < threshold or rate3 < threshold

        protected = False
        if not scenario.get("no_24_protect") and boat in (2, 4):
            protected = True
        protect_1_side = scenario.get("protect_1_side")
        if boat == 1 and (
            scenario.get("protect_1")
            or protect_1_side == side
        ):
            protected = True
        if inum(row.get("first_rank")) == 3 and (side == "honmei" or scenario.get("r3_both")):
            protected = True

        if score_low[boat] and rate_low and not protected:
            cuts.add(boat)
    return cuts


def apply_third_prune(third_model, race, head, seconds, thirds, second_prob, prediction_cache):
    if len(thirds) != 5:
        return list(thirds), False, None, None
    third_score = {boat: 0.0 for boat in thirds}
    for second in seconds:
        cache_key = (head, second)
        if cache_key not in prediction_cache["third"]:
            prediction_cache["third"][cache_key] = third_bt.third_probabilities(
                third_model, race, head, second
            )
        conditional = prediction_cache["third"][cache_key]
        for third in thirds:
            if third != second:
                third_score[third] += second_prob[second] * conditional.get(third, 0.0)
    total = sum(third_score.values())
    if total <= 0.0:
        return list(thirds), False, None, None
    drop = min(thirds, key=lambda boat: (third_score[boat], -boat))
    share = third_score[drop] / total
    if share <= 0.03:
        return [boat for boat in thirds if boat != drop], True, drop, share
    return list(thirds), False, drop, share


def make_side(
    second_model, third_model, race, prediction, boats, scenario, side, prediction_cache
):
    formation = second_bt.parse_formation(prediction.get(f"{side}_kai", ""))
    if formation is None:
        return None
    head, current_seconds, _current_thirds = formation
    if head != inum(prediction.get(f"{side}_head")):
        return None

    cuts = make_cut_set(boats, scenario, side)
    thirds = [boat for boat in range(1, 7) if boat != head and boat not in cuts]
    if not thirds:
        return None
    if head not in prediction_cache["second"]:
        prediction_cache["second"][head] = second_bt.ml_second_probabilities(
            second_model, race, head
        )
    probabilities = prediction_cache["second"][head]
    second_count = min(len(current_seconds), len(thirds))
    seconds = sorted(thirds, key=lambda boat: (-probabilities[boat], boat))[:second_count]
    pruned_thirds, pruned, drop, drop_share = apply_third_prune(
        third_model, race, head, seconds, thirds, probabilities, prediction_cache
    )
    return {
        "head": head,
        "cuts": cuts,
        "seconds": seconds,
        "thirds_before_prune": thirds,
        "thirds": pruned_thirds,
        "pruned": pruned,
        "drop": drop,
        "drop_share": drop_share,
        "tickets": second_bt.expand(head, seconds, pruned_thirds),
    }


def build_period(second_model, third_model, feature_races, prediction_rows, boat_rows, payouts):
    feature_by_code = {race["race_code"]: race for race in feature_races}
    scenario_rows = {name: [] for name in SCENARIOS}
    skip = defaultdict(int)
    current_match = {"compared": 0, "mismatch_boats": 0, "mismatch_races": 0}

    for code, prediction in sorted(prediction_rows.items()):
        race = feature_by_code.get(code)
        boats = boat_rows.get(code)
        if race is None:
            skip["feature_missing"] += 1
            continue
        if boats is None or len(boats) != 6:
            skip["boat_rows_missing"] += 1
            continue

        actual = (
            inum(prediction.get("actual_1st")),
            inum(prediction.get("actual_2nd")),
            inum(prediction.get("actual_3rd")),
        )
        if set(actual) - set(range(1, 7)) or len(set(actual)) != 3:
            skip["actual_invalid"] += 1
            continue

        # 再構築した現行本命用cutがCSVのkiruと一致することを監査する。
        reconstructed = make_cut_set(boats, SCENARIOS["CURRENT"], "honmei")
        exported = {boat for boat, row in boats.items() if inum(row.get("kiru")) == 1}
        current_match["compared"] += 1
        if reconstructed != exported:
            current_match["mismatch_races"] += 1
            current_match["mismatch_boats"] += len(reconstructed ^ exported)

        prediction_cache = {"second": {}, "third": {}}
        for name, scenario in SCENARIOS.items():
            honmei = make_side(
                second_model, third_model, race, prediction, boats, scenario, "honmei", prediction_cache
            )
            taikou = make_side(
                second_model, third_model, race, prediction, boats, scenario, "taikou", prediction_cache
            )
            if honmei is None or taikou is None:
                skip[f"{name}_formation_invalid"] += 1
                continue
            tickets = honmei["tickets"] | taikou["tickets"]
            unique_cuts = honmei["cuts"] | taikou["cuts"]
            scenario_rows[name].append({
                "race_code": code,
                "race_date": race["race_date"],
                "actual": actual,
                "payout": float(payouts.get(code, 0.0)),
                "tickets": tickets,
                "points": len(tickets),
                "hit": actual in tickets,
                "honmei": honmei,
                "taikou": taikou,
                "unique_cuts": unique_cuts,
                "cut_top3": len(unique_cuts & set(actual)),
                "cut_actual3": actual[2] in unique_cuts,
            })
        skip["ready"] += 1
    return scenario_rows, dict(skip), current_match


def summarize(rows):
    points = sum(row["points"] for row in rows)
    hits = sum(row["hit"] for row in rows)
    returns = sum(row["payout"] for row in rows if row["hit"])
    investment = points * 100.0
    unique_cut_boats = sum(len(row["unique_cuts"]) for row in rows)
    cut_top3 = sum(row["cut_top3"] for row in rows)
    cut_actual3_races = sum(row["cut_actual3"] for row in rows)
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
        "unique_cut_boats": unique_cut_boats,
        "cut_top3_boats": cut_top3,
        "cut_top3_rate": cut_top3 / unique_cut_boats if unique_cut_boats else 0.0,
        "races_cut_actual_third": cut_actual3_races,
        "ai_third_pruned_races": sum(
            row["honmei"]["pruned"] or row["taikou"]["pruned"] for row in rows
        ),
    }


def period_table(scenario_rows):
    current = summarize(scenario_rows["CURRENT"])
    table = {}
    for name, rows in scenario_rows.items():
        result = summarize(rows)
        result["label"] = SCENARIOS[name]["label"]
        result["delta"] = {
            "points": result["points"] - current["points"],
            "point_growth_rate": result["points"] / current["points"] - 1.0 if current["points"] else 0.0,
            "hits": result["hits"] - current["hits"],
            "hit_rate": result["hit_rate"] - current["hit_rate"],
            "hits_per_1000_points": result["hits_per_1000_points"] - current["hits_per_1000_points"],
            "roi": result["roi"] - current["roi"],
            "cut_top3_boats": result["cut_top3_boats"] - current["cut_top3_boats"],
            "races_cut_actual_third": result["races_cut_actual_third"] - current["races_cut_actual_third"],
        }
        table[name] = result
    return table


def select_candidate(validation_table):
    current = validation_table["CURRENT"]
    eligible = []
    for name in SELECTION_CANDIDATES:
        row = validation_table[name]
        if (
            row["hits"] >= current["hits"]
            and row["delta"]["point_growth_rate"] <= MAX_VALID_POINT_GROWTH
            and row["cut_top3_boats"] <= current["cut_top3_boats"]
        ):
            eligible.append(name)
    if not eligible:
        return "CURRENT", []
    selected = max(
        eligible,
        key=lambda name: (
            validation_table[name]["hits"],
            validation_table[name]["hits_per_1000_points"],
            -validation_table[name]["points"],
        ),
    )
    return selected, eligible


def pair_change(current_rows, candidate_rows):
    current = {row["race_code"]: row for row in current_rows}
    candidate = {row["race_code"]: row for row in candidate_rows}
    gained = lost = changed = 0
    gained_payouts = []
    lost_payouts = []
    gained_races = []
    lost_races = []
    for code in sorted(set(current) & set(candidate)):
        before = current[code]
        after = candidate[code]
        changed += int(before["tickets"] != after["tickets"])
        gained += int(after["hit"] and not before["hit"])
        lost += int(before["hit"] and not after["hit"])
        if after["hit"] and not before["hit"]:
            gained_payouts.append(after["payout"])
            gained_races.append({
                "race_code": code,
                "actual": list(after["actual"]),
                "payout_yen": after["payout"],
                "points_before": before["points"],
                "points_after": after["points"],
            })
        if before["hit"] and not after["hit"]:
            lost_payouts.append(before["payout"])
            lost_races.append({
                "race_code": code,
                "actual": list(after["actual"]),
                "payout_yen": before["payout"],
                "points_before": before["points"],
                "points_after": after["points"],
            })
    return {
        "changed_races": changed,
        "gained_hits": gained,
        "lost_hits": lost,
        "net_hits": gained - lost,
        "gained_return_yen": sum(gained_payouts),
        "lost_return_yen": sum(lost_payouts),
        "return_net_yen": sum(gained_payouts) - sum(lost_payouts),
        "gained_payout_median_yen": float(np.median(gained_payouts)) if gained_payouts else 0.0,
        "lost_payout_median_yen": float(np.median(lost_payouts)) if lost_payouts else 0.0,
        "gained_races": gained_races,
        "lost_races": lost_races,
    }


def bootstrap(current_rows, candidate_rows):
    current = {row["race_code"]: row for row in current_rows}
    candidate = {row["race_code"]: row for row in candidate_rows}
    by_day = defaultdict(list)
    for code in sorted(set(current) & set(candidate)):
        by_day[current[code]["race_date"]].append(code)
    days = sorted(by_day)
    rng = random.Random(BOOTSTRAP_SEED)
    hit_deltas = []
    point_growth = []
    roi_deltas = []
    for _ in range(BOOTSTRAP_N):
        codes = []
        for _index in range(len(days)):
            codes.extend(by_day[rng.choice(days)])
        cur_rows = [current[code] for code in codes]
        can_rows = [candidate[code] for code in codes]
        cur = summarize(cur_rows)
        can = summarize(can_rows)
        hit_deltas.append(can["hit_rate"] - cur["hit_rate"])
        point_growth.append(can["points"] / cur["points"] - 1.0 if cur["points"] else 0.0)
        roi_deltas.append(can["roi"] - cur["roi"])

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


def print_table(title, table, names):
    print(f"\n【{title}】")
    print("方式                         切艇数  誤切3内  3着誤切R   的中数  平均点数  千点的中      ROI")
    for name in names:
        row = table[name]
        print(
            f"{name:<28} {row['unique_cut_boats']:>6d}  {row['cut_top3_boats']:>7d}  "
            f"{row['races_cut_actual_third']:>8d}  {row['hits']:>7d}  "
            f"{row['average_points']:>8.2f}  {row['hits_per_1000_points']:>8.2f}  {row['roi']*100:>7.2f}%"
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

    valid_predictions = load_prediction_rows(VALID_START, VALID_END)
    test_predictions = load_prediction_rows(TEST_START, TEST_END)
    valid_boats = load_boat_rows(VALID_START, VALID_END)
    test_boats = load_boat_rows(TEST_START, TEST_END)
    valid_payouts = second_bt.payout_util.load_trifecta_payouts(VALID_START, VALID_END)
    test_payouts = second_bt.payout_util.load_trifecta_payouts(TEST_START, TEST_END)

    valid_rows, valid_skip, valid_match = build_period(
        second_model, third_model, valid_features, valid_predictions, valid_boats, valid_payouts
    )
    valid_table = period_table(valid_rows)
    selected, eligible = select_candidate(valid_table)

    test_rows, test_skip, test_match = build_period(
        second_model, third_model, test_features, test_predictions, test_boats, test_payouts
    )
    test_table = period_table(test_rows)
    test_change = pair_change(test_rows["CURRENT"], test_rows[selected])
    bootstrap_result = bootstrap(test_rows["CURRENT"], test_rows[selected])

    report = {
        "purpose": "現行の切る艇判定を構成要素別に監査し、誤切りを減らす候補を未使用期間で検証",
        "production_changed": False,
        "periods": {
            "model_train_end": TRAIN_END.isoformat(),
            "tuning": [VALID_START.isoformat(), VALID_END.isoformat()],
            "test": [TEST_START.isoformat(), TEST_END.isoformat()],
        },
        "selection_policy": {
            "candidates": list(SELECTION_CANDIDATES),
            "maximum_validation_point_growth_rate": MAX_VALID_POINT_GROWTH,
            "requirements": "現行以上の的中、点数増3%以内、誤切り3連対数が現行以下",
            "priority": "的中数、1000点当たり的中、総点数の順",
            "eligible": eligible,
            "selected": selected,
        },
        "scenario_definitions": SCENARIOS,
        "coverage": {
            "source_skip": source_skip,
            "validation": {
                "feature_races": len(valid_features),
                "prediction_races": len(valid_predictions),
                "boat_races": len(valid_boats),
                "skip": valid_skip,
                "current_reconstruction": valid_match,
            },
            "test": {
                "feature_races": len(test_features),
                "prediction_races": len(test_predictions),
                "boat_races": len(test_boats),
                "skip": test_skip,
                "current_reconstruction": test_match,
            },
        },
        "validation": valid_table,
        "test": test_table,
        "test_selected_change": test_change,
        "test_selected_bootstrap": bootstrap_result,
    }
    OUTPUT_PATH.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")

    print("=" * 108)
    print("現行『切る艇』判定 構成要素監査（AI2着v1・AI3着絞りv1込み）")
    print("=" * 108)
    print(
        f"調整={VALID_START}～{VALID_END} / テスト={TEST_START}～{TEST_END} / "
        f"調整期間で選択={selected}"
    )
    names = list(SCENARIOS)
    print_table("調整期間", valid_table, names)
    print_table("未使用テスト期間", test_table, names)
    print(
        f"\n選択案 {selected}: 拾い={test_change['gained_hits']} / "
        f"失い={test_change['lost_hits']} / 純増={test_change['net_hits']:+d} / "
        f"変更={test_change['changed_races']}R"
    )
    boot = bootstrap_result["hit_rate_delta"]
    print(
        "日単位bootstrap 的中率差: "
        f"median={boot['median']*100:+.3f}pt / "
        f"95%CI=[{boot['ci95'][0]*100:+.3f}, {boot['ci95'][1]*100:+.3f}]pt / "
        f"改善確率={boot['improvement_probability']*100:.2f}%"
    )
    print(f"保存: {OUTPUT_PATH}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
