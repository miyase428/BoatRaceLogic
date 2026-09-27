#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""本命・対抗買い目の2着候補だけをAI着順率v1へ置換する完全ホールドアウト検証。"""

from __future__ import annotations

import argparse
import csv
import json
import math
import random
import sys
from collections import defaultdict
from datetime import date
from pathlib import Path

import numpy as np


ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "analysis"))
sys.path.insert(0, str(ROOT / "forecast"))

import final_prediction_head1_blend_second_compare as payout_util  # noqa: E402
import train_ai_place_v1 as place_train  # noqa: E402
from ai_place_features import vector  # noqa: E402


TEST_START = date(2026, 9, 11)
TEST_END = date(2026, 9, 21)
TRAIN_END = date(2026, 8, 31)
TEMPERATURE = 1.0
ALPHA = 0.75
EPS = 1.0e-12
BOOTSTRAP_N = 5000
BOOTSTRAP_SEED = 428

RACE_FILES = (
    ROOT / "analysis/output/final_prediction_races_fast_cached_20260901_20260917.csv",
    ROOT / "analysis/output/final_prediction_races_fast_cached_20260915_20260921.csv",
)
OUTPUT_PATHS = {
    "honmei": ROOT / "analysis/output/ai_place_v1_final_second_backtest_2026.json",
    "taikou": ROOT / "analysis/output/ai_place_v1_taikou_second_backtest_2026.json",
}
SIDE_LABELS = {"honmei": "本命", "taikou": "対抗"}


def parse_group(value: str) -> list[int]:
    return [int(ch) for ch in str(value) if ch in "123456"]


def parse_formation(value: str):
    parts = str(value or "").strip().split("-")
    if len(parts) != 3:
        return None
    heads = parse_group(parts[0])
    seconds = parse_group(parts[1])
    thirds = parse_group(parts[2])
    if len(heads) != 1 or not seconds or not thirds:
        return None
    head = heads[0]
    if head in seconds or head in thirds:
        return None
    if len(set(seconds)) != len(seconds) or len(set(thirds)) != len(thirds):
        return None
    return head, seconds, thirds


def expand(head: int, seconds: list[int], thirds: list[int]):
    return {
        (head, second, third)
        for second in seconds
        for third in thirds
        if second != third
    }


def load_current_races():
    rows = {}
    for path in RACE_FILES:
        with path.open("r", encoding="utf-8-sig", newline="") as handle:
            for row in csv.DictReader(handle):
                race_day = date.fromisoformat(str(row.get("race_date", "")))
                if TEST_START <= race_day <= TEST_END:
                    rows[str(row["race_code"])] = row
    return rows


def normalize(values: dict[int, float]):
    total = sum(max(float(value), EPS) for value in values.values())
    return {key: max(float(value), EPS) / total for key, value in values.items()}


def softmax(values):
    z = np.asarray(values, dtype=np.float64) / TEMPERATURE
    z -= z.max()
    out = np.exp(z)
    return out / out.sum()


def ml_second_probabilities(model, race, head: int):
    candidates = [boat for boat in range(1, 7) if boat != head]
    x = np.vstack([
        vector(
            race["boats"][candidate],
            race["boats"][head],
            race["race_number"],
            race["place_id"],
        )
        for candidate in candidates
    ])
    ml_values = softmax(model.predict(x))
    ml = {boat: float(value) for boat, value in zip(candidates, ml_values)}
    base = normalize({
        boat: float(race["boats"][boat]["v5_probability"])
        for boat in candidates
    })
    scores = {
        boat: (1.0 - ALPHA) * math.log(max(base[boat], EPS))
        + ALPHA * math.log(max(ml[boat], EPS))
        for boat in candidates
    }
    peak = max(scores.values())
    return normalize({boat: math.exp(score - peak) for boat, score in scores.items()})


def build_comparison(model, races, current_rows, payouts, side: str):
    out = []
    skipped = defaultdict(int)
    race_map = {race["race_code"]: race for race in races}

    for code, current in sorted(current_rows.items()):
        race = race_map.get(code)
        if race is None:
            skipped["ai_feature_missing"] += 1
            continue
        formation = parse_formation(current.get(f"{side}_kai", ""))
        if formation is None:
            skipped["current_formation_invalid"] += 1
            continue
        head, current_seconds, thirds = formation
        if head != int(float(current.get(f"{side}_head", 0) or 0)):
            skipped["head_mismatch"] += 1
            continue

        probabilities = ml_second_probabilities(model, race, head)
        eligible = [boat for boat in thirds if boat != head]
        if not set(current_seconds).issubset(set(eligible)):
            skipped["current_second_outside_thirds"] += 1
            continue
        if len(eligible) < len(current_seconds):
            skipped["eligible_short"] += 1
            continue
        ml_order = sorted(eligible, key=lambda boat: (-probabilities[boat], boat))
        ml_seconds = ml_order[:len(current_seconds)]

        current_tickets = expand(head, current_seconds, thirds)
        ml_tickets = expand(head, ml_seconds, thirds)
        if len(current_tickets) != len(ml_tickets):
            skipped["point_mismatch"] += 1
            continue

        actual = (
            int(float(current.get("actual_1st", 0) or 0)),
            int(float(current.get("actual_2nd", 0) or 0)),
            int(float(current.get("actual_3rd", 0) or 0)),
        )
        if set(actual) - set(range(1, 7)) or len(set(actual)) != 3:
            skipped["actual_invalid"] += 1
            continue

        head_course = int(race["boats"][head]["course"])
        out.append({
            "race_code": code,
            "race_date": race["race_date"],
            "head": head,
            "head_course": head_course,
            "actual": actual,
            "head_won": actual[0] == head,
            "actual_second_eligible": actual[1] in eligible,
            "current_seconds": current_seconds,
            "ml_seconds": ml_seconds,
            "thirds": thirds,
            "current_tickets": current_tickets,
            "ml_tickets": ml_tickets,
            "points": len(current_tickets),
            "payout": float(payouts.get(code, 0.0)),
        })
        skipped["ready"] += 1
    return out, dict(skipped)


def evaluate(rows, method: str):
    tickets_key = f"{method}_tickets"
    seconds_key = f"{method}_seconds"
    points = sum(row["points"] for row in rows)
    hits = 0
    payout = 0.0
    eligible_head_wins = 0
    second_hits = 0
    for row in rows:
        if row["actual"] in row[tickets_key]:
            hits += 1
            payout += row["payout"]
        if row["head_won"] and row["actual_second_eligible"]:
            eligible_head_wins += 1
            second_hits += int(row["actual"][1] in row[seconds_key])
    investment = points * 100.0
    return {
        "races": len(rows),
        "points": points,
        "average_points": points / len(rows) if rows else 0.0,
        "trifecta_hits": hits,
        "trifecta_hit_rate": hits / len(rows) if rows else 0.0,
        "return_yen": payout,
        "investment_yen": investment,
        "roi": payout / investment if investment else 0.0,
        "eligible_head_wins": eligible_head_wins,
        "second_hits": second_hits,
        "second_hit_rate": second_hits / eligible_head_wins if eligible_head_wins else 0.0,
    }


def pair_change(rows):
    changed = gained = lost = 0
    gained_payouts = []
    lost_payouts = []
    for row in rows:
        if row["current_tickets"] != row["ml_tickets"]:
            changed += 1
        before = row["actual"] in row["current_tickets"]
        after = row["actual"] in row["ml_tickets"]
        gained += int(after and not before)
        lost += int(before and not after)
        if after and not before:
            gained_payouts.append(float(row["payout"]))
        if before and not after:
            lost_payouts.append(float(row["payout"]))
    gained_return = sum(gained_payouts)
    lost_return = sum(lost_payouts)
    return {
        "changed": changed,
        "gained": gained,
        "lost": lost,
        "net": gained - lost,
        "gained_return_yen": gained_return,
        "lost_return_yen": lost_return,
        "return_net_yen": gained_return - lost_return,
        "gained_payout_median_yen": float(np.median(gained_payouts)) if gained_payouts else 0.0,
        "lost_payout_median_yen": float(np.median(lost_payouts)) if lost_payouts else 0.0,
        "gained_payout_max_yen": max(gained_payouts, default=0.0),
        "lost_payout_max_yen": max(lost_payouts, default=0.0),
    }


def summarize(rows):
    current = evaluate(rows, "current")
    ml = evaluate(rows, "ml")
    return {
        "current": current,
        "ml_v1": ml,
        "change": pair_change(rows),
        "delta": {
            "trifecta_hits": ml["trifecta_hits"] - current["trifecta_hits"],
            "trifecta_hit_rate": ml["trifecta_hit_rate"] - current["trifecta_hit_rate"],
            "second_hit_rate": ml["second_hit_rate"] - current["second_hit_rate"],
            "roi": ml["roi"] - current["roi"],
        },
    }


def percentile(values, q):
    return float(np.quantile(np.asarray(values, dtype=np.float64), q))


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
        summary = summarize(sample)
        hit_deltas.append(summary["delta"]["trifecta_hit_rate"])
        roi_deltas.append(summary["delta"]["roi"])
    return {
        "days": len(days),
        "iterations": BOOTSTRAP_N,
        "trifecta_hit_rate_delta": {
            "median": percentile(hit_deltas, 0.50),
            "ci95": [percentile(hit_deltas, 0.025), percentile(hit_deltas, 0.975)],
            "improvement_probability": sum(x > 0 for x in hit_deltas) / len(hit_deltas),
        },
        "roi_delta": {
            "median": percentile(roi_deltas, 0.50),
            "ci95": [percentile(roi_deltas, 0.025), percentile(roi_deltas, 0.975)],
            "improvement_probability": sum(x > 0 for x in roi_deltas) / len(roi_deltas),
        },
    }


def print_summary(label, summary):
    current = summary["current"]
    ml = summary["ml_v1"]
    change = summary["change"]
    print(f"\n【{label}】 N={current['races']:,}")
    print("方式       的中数  的中率  平均点数  2着捕捉率      ROI")
    for name, result in (("CURRENT", current), ("ML_V1", ml)):
        print(
            f"{name:<10} {result['trifecta_hits']:>5d}  "
            f"{result['trifecta_hit_rate']*100:>6.2f}%  "
            f"{result['average_points']:>7.2f}  "
            f"{result['second_hit_rate']*100:>8.2f}%  "
            f"{result['roi']*100:>7.2f}%"
        )
    print(
        f"拾い/失い : 変更={change['changed']}R / 拾い={change['gained']} / "
        f"失い={change['lost']} / 純増={change['net']:+d}"
    )


def parse_args():
    parser = argparse.ArgumentParser()
    parser.add_argument(
        "--side",
        choices=sorted(SIDE_LABELS),
        default="honmei",
        help="検証する買い目側（honmei / taikou）",
    )
    return parser.parse_args()


def main():
    args = parse_args()
    side = args.side
    side_label = SIDE_LABELS[side]
    output_path = OUTPUT_PATHS[side]
    print("AI着順率v1の8月末時点モデルを再学習しています…", flush=True)
    races, _place_to_id, source_skip = place_train.build_races()
    train_races = [race for race in races if race["race_date"] <= TRAIN_END]
    test_races = [race for race in races if TEST_START <= race["race_date"] <= TEST_END]
    x, y, groups = place_train.matrices(train_races, "second")
    model = place_train.make_ranker()
    model.fit(x, y, group=groups)

    current_rows = load_current_races()
    payouts = payout_util.load_trifecta_payouts(TEST_START, TEST_END)
    rows, skipped = build_comparison(model, test_races, current_rows, payouts, side)
    if not rows:
        raise RuntimeError("比較可能な完全ホールドアウトレースがありません")

    all_summary = summarize(rows)
    one_course = summarize([row for row in rows if row["head_course"] == 1])
    non_one_course = summarize([row for row in rows if row["head_course"] != 1])
    by_course = {
        str(course): summarize([row for row in rows if row["head_course"] == course])
        for course in range(1, 7)
    }
    bootstrap_result = bootstrap(rows)

    report = {
        "side": side,
        "purpose": f"{side_label}頭・切る艇・3着候補・2着候補数を固定し、2着順位だけAI着順率v1へ置換",
        "production_changed": False,
        "periods": {
            "train_end": TRAIN_END.isoformat(),
            "tuning": ["2026-09-01", "2026-09-10"],
            "test": [TEST_START.isoformat(), TEST_END.isoformat()],
        },
        "model": {"temperature": TEMPERATURE, "alpha": ALPHA},
        "coverage": {
            "feature_test_races": len(test_races),
            "current_prediction_races": len(current_rows),
            "compared_races": len(rows),
            "source_skip": source_skip,
            "comparison_skip": skipped,
        },
        "same_points_verified": all(
            len(row["current_tickets"]) == len(row["ml_tickets"])
            for row in rows
        ),
        "all": all_summary,
        "head_1c": one_course,
        "head_non_1c": non_one_course,
        "by_head_course": by_course,
        "bootstrap": bootstrap_result,
    }
    output_path.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")

    print("=" * 88)
    print(f"{side_label}買い目2着候補：現行 vs AI着順率v1（同一点数・完全ホールドアウト）")
    print("=" * 88)
    print(f"学習 <= {TRAIN_END} / 調整 9/1-10 / テスト {TEST_START}～{TEST_END}")
    print(f"比較可能 {len(rows):,}R / 同一点数={report['same_points_verified']}")
    print_summary("ALL", all_summary)
    print_summary("1C頭", one_course)
    print_summary("非1C頭", non_one_course)
    boot = bootstrap_result["trifecta_hit_rate_delta"]
    print(
        "\n日単位bootstrap 的中率差: "
        f"median={boot['median']*100:+.3f}pt / "
        f"95%CI=[{boot['ci95'][0]*100:+.3f}, {boot['ci95'][1]*100:+.3f}]pt / "
        f"改善確率={boot['improvement_probability']*100:.2f}%"
    )
    print(f"保存: {output_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
