#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""v5の1着確率に接続する条件付きAI2着率・AI3着率 v1を学習する。"""

from __future__ import annotations

import csv
import json
import math
import sys
from collections import defaultdict
from datetime import date
from pathlib import Path

import joblib
import lightgbm as lgb
import numpy as np
import pandas as pd

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "analysis"))
sys.path.insert(0, str(ROOT / "forecast"))

import final_prediction_second_engine_all_head_compare as course_util  # noqa: E402
from ai_place_features import feature_names, vector  # noqa: E402


V5_PATH = ROOT / "analysis/output/ai_winrate_motor_reset_ensemble_2026_predictions.csv.gz"
V5_COLUMN = "trio_v2_15_base_21_pair_64_course_s500"
FINAL_FILES = (
    ROOT / "analysis/output/final_prediction_boats_fast_cached_20260215_20260814.csv",
    ROOT / "analysis/output/final_prediction_boats_fast_cached_20260815_20260822.csv",
    ROOT / "analysis/output/final_prediction_boats_fast_cached_20260823_20260831.csv",
    ROOT / "analysis/output/final_prediction_boats_fast_cached_20260901_20260917.csv",
    ROOT / "analysis/output/final_prediction_boats_fast_cached_20260915_20260921.csv",
)
MODEL_PATH = ROOT / "forecast/models/ai_place_v1.joblib"
REPORT_PATH = ROOT / "analysis/output/ai_place_v1_2026.json"

TRAIN_END = date(2026, 8, 31)
VALID_START = date(2026, 9, 1)
VALID_END = date(2026, 9, 10)
TEST_START = date(2026, 9, 11)
TEST_END = date(2026, 9, 21)
EPS = 1.0e-12


def num(value, default=float("nan")):
    try:
        out = float(value)
        return out if math.isfinite(out) else default
    except (TypeError, ValueError):
        return default


def integer(value, default=0):
    try:
        return int(float(value))
    except (TypeError, ValueError):
        return default


def load_final_rows():
    rows: dict[tuple[str, int], dict] = {}
    for path in FINAL_FILES:
        if not path.is_file():
            raise FileNotFoundError(path)
        with path.open("r", encoding="utf-8-sig", newline="") as fh:
            for raw in csv.DictReader(fh):
                code = str(raw.get("race_code", "")).strip()
                lane = integer(raw.get("lane_number"))
                if not code or lane not in range(1, 7):
                    continue
                rows[(code, lane)] = {
                    "race_code": code,
                    "race_date": date.fromisoformat(str(raw.get("race_date"))),
                    "lane": lane,
                    "first_score": num(raw.get("first_total_score"), 0.0),
                    "first_rank": integer(raw.get("first_rank")),
                    "second_score": num(raw.get("second_score"), 0.0),
                    "second_rank": integer(raw.get("second_rank")),
                    "final3": num(raw.get("final3"), 0.0),
                    "final_rank": integer(raw.get("final_rank")),
                    "rate6": num(raw.get("three_in_rate_6m"), 0.0),
                    "rate3": num(raw.get("three_in_rate_3m"), 0.0),
                    "actual_rank": integer(raw.get("actual_rank"), 99),
                }
    grouped = defaultdict(dict)
    for (code, lane), row in rows.items():
        grouped[code][lane] = row
    return grouped


def load_v5():
    data = pd.read_csv(
        V5_PATH,
        usecols=["race_code", "race_date", "place", "course", V5_COLUMN],
    )
    return {
        (str(row.race_code), int(row.course)): float(getattr(row, V5_COLUMN))
        for row in data.itertuples(index=False)
    }


def build_races():
    final = load_final_rows()
    v5 = load_v5()
    all_dates = [row[1]["race_date"] for race in final.values() for row in race.items()]
    start, end = min(all_dates), max(all_dates)
    maps = course_util.load_exhibition_course_maps(start, end)
    place_codes = sorted({code[8:11] for code in final})
    place_to_id = {place: index + 1 for index, place in enumerate(place_codes)}

    races = []
    skip = defaultdict(int)
    for code, boats in sorted(final.items()):
        if set(boats) != set(range(1, 7)):
            skip["boats_incomplete"] += 1
            continue
        cmap = maps.get(code)
        if cmap is None:
            skip["course_missing"] += 1
            continue
        ranked = sorted(boats, key=lambda b: boats[b]["actual_rank"])
        if [boats[b]["actual_rank"] for b in ranked[:3]] != [1, 2, 3]:
            skip["result_invalid"] += 1
            continue

        probabilities = {}
        complete = True
        for lane in range(1, 7):
            p = v5.get((code, int(cmap[lane])))
            if p is None or not math.isfinite(p):
                complete = False
                break
            probabilities[lane] = float(p)
        if not complete:
            skip["v5_missing"] += 1
            continue

        order = sorted(range(1, 7), key=lambda b: (-probabilities[b], b))
        for lane in range(1, 7):
            boats[lane] = dict(boats[lane])
            boats[lane]["course"] = int(cmap[lane])
            boats[lane]["v5_probability"] = probabilities[lane]
            boats[lane]["v5_rank"] = order.index(lane) + 1

        races.append({
            "race_code": code,
            "race_date": boats[1]["race_date"],
            "place": code[8:11],
            "place_id": place_to_id[code[8:11]],
            "race_number": int(code[-2:]),
            "boats": boats,
            "actual": tuple(ranked[:3]),
        })
        skip["ready"] += 1
    return races, place_to_id, dict(skip)


def matrices(races, target):
    x, y, groups = [], [], []
    for race in races:
        head, second, third = race["actual"]
        candidates = [b for b in range(1, 7) if b != head]
        if target == "third":
            candidates = [b for b in candidates if b != second]
        groups.append(len(candidates))
        for candidate in candidates:
            x.append(vector(
                race["boats"][candidate],
                race["boats"][head],
                race["race_number"],
                race["place_id"],
                race["boats"][second] if target == "third" else None,
            ))
            y.append(1 if candidate == (second if target == "second" else third) else 0)
    return np.vstack(x), np.asarray(y, dtype=np.int32), groups


def make_ranker():
    return lgb.LGBMRanker(
        objective="lambdarank",
        metric="ndcg",
        n_estimators=360,
        learning_rate=0.035,
        num_leaves=23,
        min_child_samples=45,
        subsample=0.9,
        colsample_bytree=0.9,
        reg_lambda=1.5,
        random_state=428,
        verbosity=-1,
    )


def softmax(values, temperature):
    z = np.asarray(values, dtype=np.float64) / max(float(temperature), 1.0e-6)
    z -= z.max()
    out = np.exp(z)
    return out / out.sum()


def grouped_scores(model, races, target):
    metadata = []
    vectors = []
    for race in races:
        head, second, _third = race["actual"]
        candidates = [b for b in range(1, 7) if b != head]
        context_second = None
        if target == "third":
            candidates = [b for b in candidates if b != second]
            context_second = race["boats"][second]
        start = len(vectors)
        vectors.extend([
            vector(
                race["boats"][candidate],
                race["boats"][head],
                race["race_number"],
                race["place_id"],
                context_second,
            )
            for candidate in candidates
        ])
        metadata.append((race["race_code"], candidates, start, len(vectors)))
    raw = model.predict(np.vstack(vectors))
    return {
        code: (candidates, np.asarray(raw[start:end], dtype=np.float64))
        for code, candidates, start, end in metadata
    }


def grouped_predictions(model, races, target, temperature, scores=None):
    scores = grouped_scores(model, races, target) if scores is None else scores
    result = {}
    for race in races:
        candidates, raw = scores[race["race_code"]]
        p = softmax(raw, temperature)
        result[race["race_code"]] = {b: float(v) for b, v in zip(candidates, p)}
    return result


def baseline_predictions(races, target):
    out = {}
    for race in races:
        head, second, _ = race["actual"]
        candidates = [b for b in range(1, 7) if b != head and (target != "third" or b != second)]
        total = sum(max(float(race["boats"][b]["v5_probability"]), EPS) for b in candidates)
        out[race["race_code"]] = {
            b: max(float(race["boats"][b]["v5_probability"]), EPS) / total
            for b in candidates
        }
    return out


def blend_predictions(base, ml, alpha):
    out = {}
    for code, base_row in base.items():
        scores = {
            b: (1.0 - alpha) * math.log(max(p, EPS))
            + alpha * math.log(max(ml[code][b], EPS))
            for b, p in base_row.items()
        }
        m = max(scores.values())
        exp = {b: math.exp(v - m) for b, v in scores.items()}
        total = sum(exp.values())
        out[code] = {b: v / total for b, v in exp.items()}
    return out


def metrics(races, predictions, target):
    nll, brier, ranks = [], [], []
    for race in races:
        actual = race["actual"][1 if target == "second" else 2]
        row = predictions[race["race_code"]]
        nll.append(-math.log(max(row[actual], EPS)))
        brier.append(sum((p - (1.0 if b == actual else 0.0)) ** 2 for b, p in row.items()) / len(row))
        order = sorted(row, key=lambda b: (-row[b], b))
        ranks.append(order.index(actual) + 1)
    return {
        "n": len(races),
        "nll": float(np.mean(nll)),
        "brier": float(np.mean(brier)),
        "top1": float(np.mean(np.asarray(ranks) <= 1)),
        "top2": float(np.mean(np.asarray(ranks) <= 2)),
        "top3": float(np.mean(np.asarray(ranks) <= 3)),
    }


def calibrate_temperature(model, races, target):
    scores = grouped_scores(model, races, target)
    best = None
    for temperature in np.linspace(0.45, 2.50, 83):
        pred = grouped_predictions(model, races, target, float(temperature), scores)
        score = metrics(races, pred, target)["nll"]
        if best is None or score < best[0]:
            best = (score, float(temperature))
    return best[1]


def select_alpha(base, ml, races, target):
    best = None
    for alpha in (0.25, 0.50, 0.75, 1.00):
        pred = blend_predictions(base, ml, alpha)
        result = metrics(races, pred, target)
        key = (result["nll"], result["brier"], -result["top1"])
        if best is None or key < best[0]:
            best = (key, float(alpha), result)
    return best[1], best[2]


def train_and_evaluate(train, valid, test, target):
    x, y, groups = matrices(train, target)
    model = make_ranker()
    model.fit(x, y, group=groups)
    temperature = calibrate_temperature(model, valid, target)
    valid_base = baseline_predictions(valid, target)
    valid_ml = grouped_predictions(model, valid, target, temperature)
    alpha, valid_blend_metrics = select_alpha(valid_base, valid_ml, valid, target)
    test_base = baseline_predictions(test, target)
    test_ml = grouped_predictions(model, test, target, temperature)
    test_blend = blend_predictions(test_base, test_ml, alpha)
    return {
        "model": model,
        "temperature": temperature,
        "alpha": alpha,
        "valid": {
            "baseline": metrics(valid, valid_base, target),
            "ml": metrics(valid, valid_ml, target),
            "blend": valid_blend_metrics,
        },
        "test": {
            "baseline": metrics(test, test_base, target),
            "ml": metrics(test, test_ml, target),
            "blend": metrics(test, test_blend, target),
        },
    }


def print_result(target, result):
    print(f"\n【{target.upper()}】 T={result['temperature']:.3f} alpha={result['alpha']:.2f}")
    for period in ("valid", "test"):
        print(period.upper())
        for name, row in result[period].items():
            print(
                f"  {name:<8} N={row['n']:>5d} NLL={row['nll']:.6f} "
                f"Br={row['brier']:.6f} T1={row['top1']*100:6.2f}% "
                f"T2={row['top2']*100:6.2f}% T3={row['top3']*100:6.2f}%"
            )


def main():
    print("AI2着率・AI3着率 v1 学習データを構築中…", flush=True)
    races, place_to_id, skip = build_races()
    train = [r for r in races if r["race_date"] <= TRAIN_END]
    valid = [r for r in races if VALID_START <= r["race_date"] <= VALID_END]
    test = [r for r in races if TEST_START <= r["race_date"] <= TEST_END]
    print(f"ready={len(races)} / train={len(train)} / valid={len(valid)} / test={len(test)} / skip={skip}")
    if not train or not valid or not test:
        raise RuntimeError("時系列分割後のデータが不足しています")

    second = train_and_evaluate(train, valid, test, "second")
    third = train_and_evaluate(train, valid, test, "third")
    print_result("second", second)
    print_result("third", third)

    # 評価後は同じ固定仕様のまま全既知期間を使って本番モデルを再学習する。
    all_known = train + valid + test
    production = {}
    for target, selected in (("second", second), ("third", third)):
        x, y, groups = matrices(all_known, target)
        model = make_ranker()
        model.fit(x, y, group=groups)
        production[target] = {
            "model": model,
            "temperature": selected["temperature"],
            "alpha": selected["alpha"],
            "feature_names": feature_names(target == "third"),
        }

    artifact = {
        "version": "ai_place_v1",
        "v5_probability_column": V5_COLUMN,
        "place_to_id": place_to_id,
        "second": production["second"],
        "third": production["third"],
        "training": {
            "start": min(r["race_date"] for r in all_known).isoformat(),
            "end": max(r["race_date"] for r in all_known).isoformat(),
            "races": len(all_known),
            "train_end": TRAIN_END.isoformat(),
            "valid": [VALID_START.isoformat(), VALID_END.isoformat()],
            "test": [TEST_START.isoformat(), TEST_END.isoformat()],
        },
        "evaluation": {
            "second": {k: v for k, v in second.items() if k != "model"},
            "third": {k: v for k, v in third.items() if k != "model"},
        },
    }
    MODEL_PATH.parent.mkdir(parents=True, exist_ok=True)
    REPORT_PATH.parent.mkdir(parents=True, exist_ok=True)
    joblib.dump(artifact, MODEL_PATH)
    REPORT_PATH.write_text(
        json.dumps({k: v for k, v in artifact.items() if k not in {"second", "third"}} | {
            "second": {k: v for k, v in artifact["second"].items() if k != "model"},
            "third": {k: v for k, v in artifact["third"].items() if k != "model"},
        }, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )
    print(f"保存: {MODEL_PATH}")
    print(f"保存: {REPORT_PATH}")


if __name__ == "__main__":
    main()
