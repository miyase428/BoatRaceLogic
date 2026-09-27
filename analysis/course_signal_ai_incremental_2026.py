#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""固定済みコースサインがAI着順率へ追加価値を持つかを前方検証する。

AIモデル:
  学習 ～2026-08-31 / 校正 09-01～09-10
サイン補正:
  設計 09-11～09-14 / 完全前方 09-15～09-21

サイン艇について、AI1着確率・AI3連対確率に対する実績残差を確認し、
設計期間だけで選んだ単純なlogit補正を前方期間へ固定適用する。
"""

from __future__ import annotations

import json
import math
import sys
from collections import defaultdict
from datetime import date
from pathlib import Path

import numpy as np

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "analysis"))
sys.path.insert(0, str(ROOT / "forecast"))

import ai_final_bet_same_points_ablation_2026 as ab  # noqa: E402
import evaluate_september_course_signals as sig  # noqa: E402
import train_ai_place_v1 as place_v1  # noqa: E402
from ai_place_features import vector  # noqa: E402


REPORT = ROOT / "analysis/output/course_signal_ai_incremental_2026.json"
EPS = 1.0e-9
DESIGN_START = date(2026, 9, 11)
DESIGN_END = date(2026, 9, 14)
TEST_START = date(2026, 9, 15)
TEST_END = date(2026, 9, 21)


def normalize(values):
    total = sum(max(float(value), EPS) for value in values.values())
    return {key: max(float(value), EPS) / total for key, value in values.items()}


def all_head_cache(races, spec, target):
    vectors = []
    metadata = []
    for race in races:
        for head in range(1, 7):
            seconds = [None] if target == "second" else [
                boat for boat in range(1, 7) if boat != head
            ]
            for second in seconds:
                candidates = [
                    boat for boat in range(1, 7)
                    if boat != head and boat != second
                ]
                start = len(vectors)
                vectors.extend([
                    vector(
                        race["boats"][candidate],
                        race["boats"][head],
                        race["race_number"],
                        race["place_id"],
                        race["boats"][second] if second is not None else None,
                    )[spec["columns"]]
                    for candidate in candidates
                ])
                metadata.append((race["race_code"], head, second, candidates, start, len(vectors), race))

    raw_all = np.asarray(spec["model"].predict(np.vstack(vectors)), dtype=np.float64)
    out = {}
    for code, head, second, candidates, start, end, race in metadata:
        ml_values = ab.softmax(raw_all[start:end], spec["temperature"])
        ml = {boat: float(value) for boat, value in zip(candidates, ml_values)}
        base = normalize({
            boat: race["boats"][boat]["v5_probability"] for boat in candidates
        })
        key = (code, head) if target == "second" else (code, head, second)
        out[key] = ab.blend(base, ml, spec["alpha"])
    return out


def ai_probabilities(races, second_cache, third_cache):
    out = {}
    for race in races:
        code = race["race_code"]
        first = normalize({
            boat: race["boats"][boat]["v5_probability"] for boat in range(1, 7)
        })
        trio = {boat: first[boat] for boat in range(1, 7)}
        for head in range(1, 7):
            p2 = second_cache[(code, head)]
            for second, p_second in p2.items():
                trio[second] += first[head] * p_second
                for third, p_third in third_cache[(code, head, second)].items():
                    trio[third] += first[head] * p_second * p_third
        first_order = sorted(first, key=lambda b: (-first[b], b))
        trio_order = sorted(trio, key=lambda b: (-trio[b], b))
        out[code] = {
            boat: {
                "first_p": first[boat],
                "first_rank": first_order.index(boat) + 1,
                "trio_p": trio[boat],
                "trio_rank": trio_order.index(boat) + 1,
            }
            for boat in range(1, 7)
        }
    return out


def logit(p):
    p = min(max(float(p), EPS), 1.0 - EPS)
    return math.log(p / (1.0 - p))


def logistic(value):
    if value >= 0:
        z = math.exp(-value)
        return 1.0 / (1.0 + z)
    z = math.exp(value)
    return z / (1.0 + z)


def metrics(rows, probability_key, target_key, offsets=None):
    nll = brier = predicted = actual = 0.0
    offsets = offsets or {}
    for row in rows:
        p = float(row[probability_key])
        beta = float(offsets.get(row["label"], 0.0))
        adjusted = logistic(logit(p) + beta)
        y = float(row[target_key])
        nll += -(y * math.log(max(adjusted, EPS)) + (1.0 - y) * math.log(max(1.0 - adjusted, EPS)))
        brier += (adjusted - y) ** 2
        predicted += adjusted
        actual += y
    n = len(rows)
    return {
        "n": n,
        "nll": nll / n if n else None,
        "brier": brier / n if n else None,
        "mean_probability": predicted / n if n else None,
        "actual_rate": actual / n if n else None,
        "residual_pt": 100.0 * (actual - predicted) / n if n else None,
    }


def select_offsets(rows, probability_key, target_key):
    offsets = {}
    labels = sorted({row["label"] for row in rows})
    grid = np.linspace(-1.5, 1.5, 61)
    for label in labels:
        selected = [row for row in rows if row["label"] == label]
        best = min(
            grid,
            key=lambda beta: metrics(
                selected, probability_key, target_key, {label: float(beta)}
            )["nll"],
        )
        # 小標本の過剰補正を避けるため、N=50で半減する縮小を入れる。
        shrink = len(selected) / (len(selected) + 50.0)
        offsets[label] = float(best * shrink)
    return offsets


def bootstrap_residual(rows, probability_key, target_key, seed):
    values = np.asarray([
        float(row[target_key]) - float(row[probability_key]) for row in rows
    ], dtype=np.float64)
    if values.size == 0:
        return None
    rng = np.random.default_rng(seed)
    indexes = rng.integers(0, values.size, size=(3000, values.size))
    means = values[indexes].mean(axis=1) * 100.0
    return {
        "positive_probability": float(np.mean(means > 0.0)),
        "ci95_pt": [float(np.quantile(means, 0.025)), float(np.quantile(means, 0.975))],
    }


def summarize_groups(rows):
    result = {}
    for label in ["ALL", *sorted({row["label"] for row in rows})]:
        selected = rows if label == "ALL" else [row for row in rows if row["label"] == label]
        item = {
            "first": metrics(selected, "first_p", "first"),
            "trio": metrics(selected, "trio_p", "top3"),
            "first_residual_bootstrap": bootstrap_residual(selected, "first_p", "first", 428),
            "trio_residual_bootstrap": bootstrap_residual(selected, "trio_p", "top3", 842),
            "ai_trio_top3": metrics(
                [row for row in selected if row["trio_rank"] <= 3], "trio_p", "top3"
            ),
            "ai_trio_outside3": metrics(
                [row for row in selected if row["trio_rank"] > 3], "trio_p", "top3"
            ),
        }
        result[label] = item
    return result


def main():
    print("AI着順モデルを前方条件で構築中…", flush=True)
    races, _places, skip = place_v1.build_races()
    train = [race for race in races if race["race_date"] <= date(2026, 8, 31)]
    valid = [race for race in races if date(2026, 9, 1) <= race["race_date"] <= date(2026, 9, 10)]
    target = [race for race in races if DESIGN_START <= race["race_date"] <= TEST_END]
    cols2 = np.arange(len(ab.feature_names(False)), dtype=np.int32)
    cols3 = np.arange(len(ab.feature_names(True)), dtype=np.int32)
    spec2 = ab.fit_target(train, valid, target, "second", cols2)
    spec3 = ab.fit_target(train, valid, target, "third", cols3)
    second_cache = all_head_cache(target, spec2, "second")
    third_cache = all_head_cache(target, spec3, "third")
    ai = ai_probabilities(target, second_cache, third_cache)

    print("固定済みコースサインを再現中…", flush=True)
    signal_eval = sig.evaluate(DESIGN_START, TEST_END, DESIGN_START)
    signal_keys = {}
    for row in signal_eval["signals"]:
        if row["period"] != "post_adoption":
            continue
        signal_keys[(row["race_code"], int(row["course"]), row["label"])] = row

    race_map = {race["race_code"]: race for race in target}
    rows = []
    for (code, boat, label), signal_row in signal_keys.items():
        if code not in ai or code not in race_map:
            continue
        if signal_row["actual_rank"] is None:
            continue
        actual_rank = int(signal_row["actual_rank"])
        item = ai[code][boat]
        rows.append({
            "race_code": code,
            "race_date": signal_row["race_date"],
            "label": label,
            "boat": boat,
            "actual_rank": actual_rank,
            "first": int(actual_rank == 1),
            "top3": int(actual_rank <= 3),
            **item,
        })

    design_rows = [row for row in rows if str(DESIGN_START) <= row["race_date"] <= str(DESIGN_END)]
    test_rows = [row for row in rows if str(TEST_START) <= row["race_date"] <= str(TEST_END)]
    first_offsets = select_offsets(design_rows, "first_p", "first")
    trio_offsets = select_offsets(design_rows, "trio_p", "top3")

    report = {
        "name": "course_signal_ai_incremental_2026",
        "definition": {
            "ai_train_end": "2026-08-31",
            "ai_calibration": ["2026-09-01", "2026-09-10"],
            "signal_adjustment_design": [str(DESIGN_START), str(DESIGN_END)],
            "forward_test": [str(TEST_START), str(TEST_END)],
            "signal_rule_version": signal_eval["definition"]["rule_version"],
            "signal_rule_generated_at": signal_eval["definition"]["rule_generated_at"],
        },
        "data": {
            "ready_races": len(races),
            "design_signals": len(design_rows),
            "test_signals": len(test_rows),
            "skip": dict(skip),
        },
        "design": summarize_groups(design_rows),
        "forward": summarize_groups(test_rows),
        "calibration_offsets": {
            "first": first_offsets,
            "trio": trio_offsets,
        },
        "forward_calibration": {
            "first_raw": metrics(test_rows, "first_p", "first"),
            "first_signal_adjusted": metrics(test_rows, "first_p", "first", first_offsets),
            "trio_raw": metrics(test_rows, "trio_p", "top3"),
            "trio_signal_adjusted": metrics(test_rows, "trio_p", "top3", trio_offsets),
        },
    }
    REPORT.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")

    print(f"\n設計サイン={len(design_rows):,} / 前方サイン={len(test_rows):,}")
    print("\n【前方期間：サイン艇のAI予測に対する残差】")
    for label, item in report["forward"].items():
        first = item["first"]
        trio = item["trio"]
        print(
            f"{label:<10} N={first['n']:4d} "
            f"1着 実{first['actual_rate']*100:6.2f}% AI{first['mean_probability']*100:6.2f}% 残差{first['residual_pt']:+6.2f}pt | "
            f"3連対 実{trio['actual_rate']*100:6.2f}% AI{trio['mean_probability']*100:6.2f}% 残差{trio['residual_pt']:+6.2f}pt"
        )

    print("\n【サイン補正の確率品質】")
    for target_name in ("first", "trio"):
        raw = report["forward_calibration"][f"{target_name}_raw"]
        adjusted = report["forward_calibration"][f"{target_name}_signal_adjusted"]
        print(
            f"{target_name:<5} Brier {raw['brier']:.6f} → {adjusted['brier']:.6f} / "
            f"NLL {raw['nll']:.6f} → {adjusted['nll']:.6f}"
        )
    print(f"\n保存: {REPORT}")


if __name__ == "__main__":
    main()
