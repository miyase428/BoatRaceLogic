#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""AI2着率・AI3着率で、Excel由来の展示加工値と生展示特徴量を比較する。

本番モデルや画面は変更せず、複数の時系列分割で次の4構成を比較する。

- CURRENT_DERIVED: 現行v1と同じ特徴量（展示加工値を含む）
- CURRENT_PLUS_RAW: 現行特徴量 + 生展示値・レース内相対値
- RAW_WITHOUT_DERIVED: 展示加工値を外し、生展示値・相対値を使用
- NO_EXTRA_EXHIBITION: 展示加工値も追加の生展示値も使わない

注: 全構成がAI1着率v5を土台にするため、NO_EXTRA_EXHIBITIONにもv5を通じた
展示情報は間接的に含まれる。
"""

from __future__ import annotations

import json
import math
import sys
from collections import defaultdict
from datetime import date
from pathlib import Path

import lightgbm as lgb
import numpy as np
import psycopg2

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "common"))
sys.path.insert(0, str(ROOT / "forecast"))
sys.path.insert(0, str(ROOT / "analysis"))

from db_config import load_db_config  # noqa: E402
import train_ai_place_v1 as current  # noqa: E402
from ai_place_features import vector as current_vector  # noqa: E402


OUTPUT_PATH = ROOT / "analysis/output/ai_place_exhibition_ablation_2026.json"
REPORT_PURPOSE = "現行を残したまま、展示加工値と生展示情報の追加価値を比較"
EPS = 1.0e-12

FOLDS = (
    {
        "name": "AUG_EARLY",
        "train_end": date(2026, 7, 31),
        "valid_start": date(2026, 8, 1),
        "valid_end": date(2026, 8, 7),
        "test_start": date(2026, 8, 8),
        "test_end": date(2026, 8, 14),
    },
    {
        "name": "AUG_LATE",
        "train_end": date(2026, 8, 14),
        "valid_start": date(2026, 8, 15),
        "valid_end": date(2026, 8, 22),
        "test_start": date(2026, 8, 23),
        "test_end": date(2026, 8, 31),
    },
    {
        "name": "SEP_FORWARD",
        "train_end": date(2026, 8, 31),
        "valid_start": date(2026, 9, 1),
        "valid_end": date(2026, 9, 10),
        "test_start": date(2026, 9, 11),
        "test_end": date(2026, 9, 21),
    },
)

CORE_KEYS = (
    "v5_probability",
    "v5_rank",
    "first_score",
    "first_rank",
    "rate6",
    "rate3",
    "lane",
    "course",
)

DERIVED_KEYS = (
    "second_score",
    "second_rank",
    "final3",
    "final_rank",
)

RAW_KEYS = (
    "raw_exhibition_time",
    "raw_start_timing",
    "raw_lap_time",
    "raw_around_time",
    "raw_straight_time",
    "raw_exhibition_relative",
    "raw_start_relative",
    "raw_lap_relative",
    "raw_around_relative",
    "raw_straight_relative",
    "raw_exhibition_rank",
    "raw_start_rank",
    "raw_lap_rank",
    "raw_around_rank",
    "raw_straight_rank",
)

COMPONENT_KEYS = (
    "ex_score",
    "st_score",
    "lap_score",
    "around_score",
    "straight_score",
    "ex_total",
    "attack_potential",
    "stable_score",
)

VARIANTS = {
    "CURRENT_DERIVED": {"current": True, "derived": True, "raw": False, "components": False},
    "CURRENT_PLUS_RAW": {"current": False, "derived": True, "raw": True, "components": False},
    "RAW_WITHOUT_DERIVED": {"current": False, "derived": False, "raw": True, "components": False},
    "NO_EXTRA_EXHIBITION": {"current": False, "derived": False, "raw": False, "components": False},
}


def finite(value) -> float:
    try:
        out = float(value)
        return out if math.isfinite(out) else float("nan")
    except (TypeError, ValueError):
        return float("nan")


def load_raw_exhibition(start: date, end: date) -> dict[tuple[str, int], dict]:
    sql = """
SELECT
    re.race_code,
    re.lane_number,
    el.exhibition_time,
    el.start_timing,
    el.lap_time,
    el.around_time,
    el.straight_time
FROM boat_race.race_entry re
JOIN boat_race.race_master rm
  ON rm.race_code = re.race_code
JOIN LATERAL (
    SELECT
        x.exhibition_time,
        x.start_timing,
        x.lap_time,
        x.around_time,
        x.straight_time
    FROM boat_race.exhibition_live x
    WHERE x.race_code = re.race_code
      AND x.player_id = re.player_id
    ORDER BY x.created_date DESC NULLS LAST
    LIMIT 1
) el ON TRUE
WHERE rm.race_date BETWEEN %(start)s::date AND %(end)s::date
ORDER BY re.race_code, re.lane_number
"""
    out: dict[tuple[str, int], dict] = {}
    with psycopg2.connect(**load_db_config()) as conn:
        with conn.cursor() as cur:
            cur.execute(sql, {"start": start.isoformat(), "end": end.isoformat()})
            for row in cur.fetchall():
                out[(str(row[0]), int(row[1]))] = {
                    "raw_exhibition_time": finite(row[2]),
                    "raw_start_timing": finite(row[3]),
                    "raw_lap_time": finite(row[4]),
                    "raw_around_time": finite(row[5]),
                    "raw_straight_time": finite(row[6]),
                }
    return out


def load_place_exhibition_averages() -> dict[str, float]:
    sql = """
SELECT sm.stadium_code, ea.avg_exhibition_time_6m
FROM boat_race.stadium_master sm
JOIN boat_race.exhibition_avg_6m ea
  ON ea.stadium_name = sm.stadium_name
"""
    with psycopg2.connect(**load_db_config()) as conn:
        with conn.cursor() as cur:
            cur.execute(sql)
            return {
                str(code): float(value)
                for code, value in cur.fetchall()
                if value is not None and float(value) > 0
            }


def component_score(item: str, value: float) -> float:
    if not math.isfinite(value):
        return 3.0
    if item == "st":
        if value <= 0.00:
            return 3.0
        if value <= 0.12:
            return 5.0
        if value <= 0.20:
            return 3.0
        if value <= 0.30:
            return 2.0
        return 1.0
    bounds = {
        "ex": (-0.10, -0.05, 0.05, 0.10),
        "lap": (-0.30, -0.10, 0.10, 0.30),
        "around": (-0.20, -0.05, 0.05, 0.20),
        "straight": (-0.04, -0.01, 0.01, 0.04),
    }[item]
    if value <= bounds[0]:
        return 5.0
    if value <= bounds[1]:
        return 4.0
    if value <= bounds[2]:
        return 3.0
    if value <= bounds[3]:
        return 2.0
    return 1.0


def enrich_raw(races: list[dict]) -> tuple[list[dict], dict[str, int]]:
    first_date = min(race["race_date"] for race in races)
    last_date = max(race["race_date"] for race in races)
    raw = load_raw_exhibition(first_date, last_date)
    place_averages = load_place_exhibition_averages()
    ready: list[dict] = []
    skip = defaultdict(int)
    metric_map = {
        "raw_exhibition_time": "raw_exhibition",
        "raw_start_timing": "raw_start",
        "raw_lap_time": "raw_lap",
        "raw_around_time": "raw_around",
        "raw_straight_time": "raw_straight",
    }
    required = (
        "raw_exhibition_time",
        "raw_start_timing",
        "raw_lap_time",
        "raw_around_time",
    )
    for race in races:
        code = race["race_code"]
        rows = {boat: raw.get((code, boat)) for boat in range(1, 7)}
        if any(rows[boat] is None for boat in range(1, 7)):
            skip["raw_rows_incomplete"] += 1
            continue
        if any(
            not math.isfinite(float(rows[boat][key]))
            for boat in range(1, 7)
            for key in required
        ):
            skip["required_raw_missing"] += 1
            continue
        copied = dict(race)
        copied["boats"] = {boat: dict(race["boats"][boat]) for boat in range(1, 7)}
        for key, prefix in metric_map.items():
            values = np.asarray([float(rows[boat][key]) for boat in range(1, 7)])
            valid = np.isfinite(values)
            mean = float(np.mean(values[valid])) if valid.any() else float("nan")
            std = float(np.std(values[valid])) if valid.any() else float("nan")
            for index, boat in enumerate(range(1, 7)):
                value = float(values[index])
                copied["boats"][boat][key] = value
                if math.isfinite(value) and math.isfinite(std) and std > 0:
                    relative = (mean - value) / std
                else:
                    relative = float("nan")
                rank = (
                    1 + int(np.sum(values[valid] < value))
                    if math.isfinite(value)
                    else float("nan")
                )
                copied["boats"][boat][f"{prefix}_relative"] = relative
                copied["boats"][boat][f"{prefix}_rank"] = rank
        place_average = place_averages.get(str(race["place"]))
        if place_average is None:
            skip["place_average_missing"] += 1
            continue
        lap_mean = float(np.mean([
            copied["boats"][boat]["raw_lap_time"] for boat in range(1, 7)
        ]))
        around_mean = float(np.mean([
            copied["boats"][boat]["raw_around_time"] for boat in range(1, 7)
        ]))
        straight_values = np.asarray([
            copied["boats"][boat]["raw_straight_time"] for boat in range(1, 7)
        ], dtype=np.float64)
        straight_mean = (
            float(np.mean(straight_values[np.isfinite(straight_values)]))
            if np.isfinite(straight_values).any()
            else float("nan")
        )
        for boat in range(1, 7):
            row = copied["boats"][boat]
            straight = float(row["raw_straight_time"])
            scores = {
                "ex_score": component_score(
                    "ex", float(row["raw_exhibition_time"]) - place_average
                ),
                "st_score": component_score("st", float(row["raw_start_timing"])),
                "lap_score": component_score(
                    "lap", float(row["raw_lap_time"]) - lap_mean
                ),
                "around_score": component_score(
                    "around", float(row["raw_around_time"]) - around_mean
                ),
                "straight_score": component_score(
                    "straight", straight - straight_mean
                ) if math.isfinite(straight) and math.isfinite(straight_mean) else 3.0,
            }
            scores["ex_total"] = (
                scores["ex_score"] + scores["lap_score"]
                + scores["around_score"] + scores["straight_score"]
            )
            scores["attack_potential"] = scores["st_score"] + scores["straight_score"]
            scores["stable_score"] = scores["lap_score"] + scores["around_score"]
            row.update(scores)
        ready.append(copied)
        skip["ready"] += 1
    return ready, dict(skip)


def scaled(key: str, value) -> float:
    x = finite(value)
    if not math.isfinite(x):
        return x
    if key == "v5_probability":
        return x
    if key.endswith("_rank") or key in {"v5_rank", "first_rank", "lane", "course"}:
        return x / 6.0
    if key in {"rate6", "rate3"}:
        return x
    if key in {"first_score", "second_score", "final3"}:
        return x / 100.0
    return x


def custom_vector(
    row: dict,
    head: dict,
    race_number: int,
    place_id: int,
    second: dict | None,
    include_derived: bool,
    include_raw: bool,
    include_components: bool,
) -> np.ndarray:
    keys = list(CORE_KEYS)
    if include_derived:
        keys.extend(DERIVED_KEYS)
    if include_raw:
        keys.extend(RAW_KEYS)
    if include_components:
        keys.extend(COMPONENT_KEYS)
    values: list[float] = []
    contexts = [row, head] + ([second] if second is not None else [])
    for context in contexts:
        values.extend(scaled(key, context.get(key)) for key in keys)
        p = max(finite(context.get("v5_probability")), EPS)
        values.append(math.log(p))
    diff_keys = ["v5_probability", "first_score", "course"]
    if include_derived:
        diff_keys.extend(["second_score", "final3"])
    if include_raw:
        diff_keys.extend(RAW_KEYS)
    if include_components:
        diff_keys.extend(COMPONENT_KEYS)
    for context in [head] + ([second] if second is not None else []):
        for key in diff_keys:
            values.append(scaled(key, row.get(key)) - scaled(key, context.get(key)))
    values.extend([float(race_number) / 12.0, float(place_id)])
    return np.asarray(values, dtype=np.float64)


def vector_for(variant: str, candidate: dict, head: dict, race: dict, second=None):
    spec = VARIANTS[variant]
    if spec["current"]:
        return current_vector(
            candidate,
            head,
            race["race_number"],
            race["place_id"],
            second,
        )
    return custom_vector(
        candidate,
        head,
        race["race_number"],
        race["place_id"],
        second,
        bool(spec["derived"]),
        bool(spec["raw"]),
        bool(spec.get("components", False)),
    )


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


def matrices(races: list[dict], target: str, variant: str):
    x, y, groups = [], [], []
    actual_index = 1 if target == "second" else 2
    for race in races:
        head, second, _ = race["actual"]
        candidates = [boat for boat in range(1, 7) if boat != head]
        context_second = None
        if target == "third":
            candidates = [boat for boat in candidates if boat != second]
            context_second = race["boats"][second]
        groups.append(len(candidates))
        for candidate in candidates:
            x.append(vector_for(
                variant,
                race["boats"][candidate],
                race["boats"][head],
                race,
                context_second,
            ))
            y.append(1 if candidate == race["actual"][actual_index] else 0)
    return np.vstack(x), np.asarray(y, dtype=np.int32), groups


def softmax(values: np.ndarray, temperature: float) -> np.ndarray:
    z = np.asarray(values, dtype=np.float64) / max(float(temperature), 1.0e-6)
    z -= z.max()
    out = np.exp(z)
    return out / out.sum()


def normalize(values: dict[int, float]) -> dict[int, float]:
    total = sum(max(float(value), EPS) for value in values.values())
    return {key: max(float(value), EPS) / total for key, value in values.items()}


def blend(base: dict[int, float], ml: dict[int, float], alpha: float) -> dict[int, float]:
    scores = {
        boat: (1.0 - alpha) * math.log(max(base[boat], EPS))
        + alpha * math.log(max(ml[boat], EPS))
        for boat in base
    }
    peak = max(scores.values())
    return normalize({boat: math.exp(value - peak) for boat, value in scores.items()})


def batch_context_scores(model, variant, races, target, all_contexts=False):
    """候補行を一括推論し、コンテキストごとの生スコアへ戻す。"""
    vectors = []
    metadata = []
    for race in races:
        actual_head, actual_second, _ = race["actual"]
        if target == "second":
            contexts = [(head, None) for head in range(1, 7)] if all_contexts else [
                (actual_head, None)
            ]
        elif all_contexts:
            contexts = [
                (head, second)
                for head in range(1, 7)
                for second in range(1, 7)
                if second != head
            ]
        else:
            contexts = [(actual_head, actual_second)]
        for head, second in contexts:
            candidates = [
                boat for boat in range(1, 7)
                if boat != head and boat != second
            ]
            context_second = race["boats"][second] if second is not None else None
            start = len(vectors)
            vectors.extend([
                vector_for(
                    variant,
                    race["boats"][candidate],
                    race["boats"][head],
                    race,
                    context_second,
                )
                for candidate in candidates
            ])
            metadata.append((race["race_code"], head, second, candidates, start, len(vectors)))
    raw = model.predict(np.vstack(vectors))
    return {
        (code, head, second): (candidates, np.asarray(raw[start:end], dtype=np.float64))
        for code, head, second, candidates, start, end in metadata
    }


def probabilities_from_scores(races, scores, temperature, alpha):
    race_by_code = {race["race_code"]: race for race in races}
    out = {}
    for context, (candidates, raw) in scores.items():
        code, _head, _second = context
        race = race_by_code[code]
        ml_values = softmax(raw, temperature)
        ml = {boat: float(prob) for boat, prob in zip(candidates, ml_values)}
        base = normalize({
            boat: float(race["boats"][boat]["v5_probability"])
            for boat in candidates
        })
        out[context] = blend(base, ml, alpha)
    return out


def conditional_predictions(races, target, probabilities):
    out = {}
    for race in races:
        head, second, _ = race["actual"]
        context = (race["race_code"], head, second if target == "third" else None)
        out[race["race_code"]] = probabilities[context]
    return out


def conditional_metrics(races, predictions, target):
    nll, brier, ranks = [], [], []
    actual_index = 1 if target == "second" else 2
    for race in races:
        actual = race["actual"][actual_index]
        row = predictions[race["race_code"]]
        nll.append(-math.log(max(row[actual], EPS)))
        brier.append(sum(
            (prob - (1.0 if boat == actual else 0.0)) ** 2
            for boat, prob in row.items()
        ) / len(row))
        order = sorted(row, key=lambda boat: (-row[boat], boat))
        ranks.append(order.index(actual) + 1)
    arr = np.asarray(ranks)
    return {
        "n": len(races),
        "nll": float(np.mean(nll)),
        "brier": float(np.mean(brier)),
        "top1": float(np.mean(arr <= 1)),
        "top2": float(np.mean(arr <= 2)),
        "top3": float(np.mean(arr <= 3)),
    }


def tune(model, variant, valid, target):
    scores = batch_context_scores(model, variant, valid, target, all_contexts=False)
    best = None
    for temperature in np.linspace(0.55, 1.85, 27):
        for alpha in (0.25, 0.50, 0.75, 1.00):
            context_probabilities = probabilities_from_scores(
                valid, scores, float(temperature), float(alpha)
            )
            pred = conditional_predictions(valid, target, context_probabilities)
            metric = conditional_metrics(valid, pred, target)
            key = (metric["nll"], metric["brier"], -metric["top1"])
            if best is None or key < best[0]:
                best = (key, float(temperature), float(alpha))
    return best[1], best[2]


def train_target(train, valid, test, target, variant):
    x, y, groups = matrices(train, target, variant)
    model = make_ranker()
    model.fit(x, y, group=groups)
    temperature, alpha = tune(model, variant, valid, target)
    test_scores = batch_context_scores(model, variant, test, target, all_contexts=False)
    context_probabilities = probabilities_from_scores(
        test, test_scores, temperature, alpha
    )
    predictions = conditional_predictions(test, target, context_probabilities)
    return model, temperature, alpha, conditional_metrics(test, predictions, target)


def joint_metrics(races, variant, second_spec, third_spec):
    second_model, second_t, second_alpha = second_spec
    third_model, third_t, third_alpha = third_spec
    second_scores = batch_context_scores(
        second_model, variant, races, "second", all_contexts=True
    )
    third_scores = batch_context_scores(
        third_model, variant, races, "third", all_contexts=True
    )
    second_probabilities = probabilities_from_scores(
        races, second_scores, second_t, second_alpha
    )
    third_probabilities = probabilities_from_scores(
        races, third_scores, third_t, third_alpha
    )
    nll, brier = [], []
    hits = {6: 0, 12: 0, 20: 0}
    for race in races:
        first = normalize({
            boat: float(race["boats"][boat]["v5_probability"])
            for boat in range(1, 7)
        })
        combinations = []
        for head in range(1, 7):
            seconds = second_probabilities[(race["race_code"], head, None)]
            for second, p2 in seconds.items():
                thirds = third_probabilities[(race["race_code"], head, second)]
                for third, p3 in thirds.items():
                    combinations.append(((head, second, third), first[head] * p2 * p3))
        combinations.sort(key=lambda item: (-item[1], item[0]))
        probability = {key: value for key, value in combinations}
        actual = tuple(race["actual"])
        nll.append(-math.log(max(probability[actual], EPS)))
        brier.append(sum(
            (value - (1.0 if key == actual else 0.0)) ** 2
            for key, value in combinations
        ) / 120.0)
        order = [key for key, _ in combinations]
        actual_rank = order.index(actual) + 1
        for points in hits:
            hits[points] += int(actual_rank <= points)
    return {
        "n": len(races),
        "nll": float(np.mean(nll)),
        "brier": float(np.mean(brier)),
        **{f"top{points}_hits": count for points, count in hits.items()},
        **{f"top{points}_rate": count / len(races) for points, count in hits.items()},
    }


def aggregate(folds: list[dict]) -> dict:
    result = {}
    for variant in VARIANTS:
        target_out = {}
        for target in ("second", "third", "joint120"):
            rows = [fold["variants"][variant][target] for fold in folds]
            n = sum(row["n"] for row in rows)
            merged = {"n": n}
            mean_keys = ["nll", "brier"]
            if target != "joint120":
                mean_keys += ["top1", "top2", "top3"]
            else:
                mean_keys += ["top6_rate", "top12_rate", "top20_rate"]
                for points in (6, 12, 20):
                    merged[f"top{points}_hits"] = sum(
                        row[f"top{points}_hits"] for row in rows
                    )
            for key in mean_keys:
                merged[key] = sum(row[key] * row["n"] for row in rows) / n
            target_out[target] = merged
        result[variant] = target_out
    return result


def main() -> int:
    print("現行AI着順データを読み込んでいます…", flush=True)
    races, _place_to_id, source_skip = current.build_races()
    print("生の展示情報を対応付けています…", flush=True)
    races, raw_skip = enrich_raw(races)
    print(
        f"比較可能 {len(races):,}R / 元データ {source_skip.get('ready', 0):,}R "
        f"/ 展示除外 {sum(v for k, v in raw_skip.items() if k != 'ready'):,}R",
        flush=True,
    )

    fold_results = []
    for fold in FOLDS:
        train = [race for race in races if race["race_date"] <= fold["train_end"]]
        valid = [
            race for race in races
            if fold["valid_start"] <= race["race_date"] <= fold["valid_end"]
        ]
        test = [
            race for race in races
            if fold["test_start"] <= race["race_date"] <= fold["test_end"]
        ]
        print(
            f"\n{fold['name']}: train={len(train):,} valid={len(valid):,} test={len(test):,}",
            flush=True,
        )
        if not train or not valid or not test:
            raise RuntimeError(f"{fold['name']}: 時系列分割後のデータが不足しています")
        fold_out = {
            "name": fold["name"],
            "period": {
                key: value.isoformat() if isinstance(value, date) else value
                for key, value in fold.items()
                if key != "name"
            },
            "train_races": len(train),
            "valid_races": len(valid),
            "test_races": len(test),
            "variants": {},
        }
        for variant in VARIANTS:
            print(f"  {variant}: 2着モデル", flush=True)
            second_model, second_t, second_alpha, second_metric = train_target(
                train, valid, test, "second", variant
            )
            print(f"  {variant}: 3着モデル", flush=True)
            third_model, third_t, third_alpha, third_metric = train_target(
                train, valid, test, "third", variant
            )
            print(f"  {variant}: 120通り評価", flush=True)
            joint = joint_metrics(
                test,
                variant,
                (second_model, second_t, second_alpha),
                (third_model, third_t, third_alpha),
            )
            fold_out["variants"][variant] = {
                "parameters": {
                    "second_temperature": second_t,
                    "second_alpha": second_alpha,
                    "third_temperature": third_t,
                    "third_alpha": third_alpha,
                },
                "second": second_metric,
                "third": third_metric,
                "joint120": joint,
            }
            print(
                f"    2着NLL={second_metric['nll']:.5f} "
                f"3着NLL={third_metric['nll']:.5f} "
                f"Top20={joint['top20_rate'] * 100:.2f}%",
                flush=True,
            )
        fold_results.append(fold_out)

    combined = aggregate(fold_results)
    report = {
        "purpose": REPORT_PURPOSE,
        "production_changed": False,
        "source_skip": source_skip,
        "raw_coverage": raw_skip,
        "variants": VARIANTS,
        "folds": fold_results,
        "aggregate": combined,
        "notes": [
            "全構成がAI1着率v5を土台にするため、展示情報はv5経由でも間接反映される",
            "温度とv5ブレンド率は各foldの検証期間だけで選択した",
            "本番モデル・画面・買い目は変更していない",
        ],
    }
    OUTPUT_PATH.parent.mkdir(parents=True, exist_ok=True)
    OUTPUT_PATH.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")

    print("\n【3期間合算】")
    for variant, row in combined.items():
        print(
            f"{variant:<22} "
            f"2着 NLL={row['second']['nll']:.6f} Br={row['second']['brier']:.6f} "
            f"| 3着 NLL={row['third']['nll']:.6f} Br={row['third']['brier']:.6f} "
            f"| 120 Top6={row['joint120']['top6_rate']*100:.2f}% "
            f"Top12={row['joint120']['top12_rate']*100:.2f}% "
            f"Top20={row['joint120']['top20_rate']*100:.2f}%"
        )
    print(f"保存: {OUTPUT_PATH}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
