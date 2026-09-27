#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""展示・評価情報の意味、閾値、重みを本番変更なしで監査する。"""

from __future__ import annotations

import json
import math
import sys
from collections import Counter, defaultdict
from datetime import date
from pathlib import Path

import numpy as np
import psycopg2
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import log_loss
from sklearn.tree import DecisionTreeRegressor

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "common"))
sys.path.insert(0, str(ROOT / "analysis"))

from db_config import load_db_config  # noqa: E402
from train_ai_place_v1 import load_v5  # noqa: E402


START = date(2026, 2, 15)
TRAIN_END = date(2026, 8, 31)
TEST_START = date(2026, 9, 1)
TEST_END = date(2026, 9, 21)
OUTPUT = ROOT / "analysis/output/exhibition_evaluation_audit_2026.json"
EPS = 1.0e-9

ITEMS = ("ex", "st", "lap", "around", "straight")
RAW_KEYS = {
    "ex": "ex_diff",
    "st": "start_timing",
    "lap": "lap_diff",
    "around": "around_diff",
    "straight": "straight_diff",
}


def number(value) -> float:
    try:
        out = float(value)
        return out if math.isfinite(out) else float("nan")
    except (TypeError, ValueError):
        return float("nan")


def score_ex(diff: float) -> int:
    if diff <= -0.10:
        return 5
    if diff <= -0.05:
        return 4
    if diff <= 0.05:
        return 3
    if diff <= 0.10:
        return 2
    return 1


def score_st(value: float) -> int:
    if value <= 0.00:
        return 3
    if value <= 0.12:
        return 5
    if value <= 0.20:
        return 3
    if value <= 0.30:
        return 2
    return 1


def score_lap(diff: float) -> int:
    if diff <= -0.30:
        return 5
    if diff <= -0.10:
        return 4
    if diff <= 0.10:
        return 3
    if diff <= 0.30:
        return 2
    return 1


def score_around(diff: float) -> int:
    if diff <= -0.20:
        return 5
    if diff <= -0.05:
        return 4
    if diff <= 0.05:
        return 3
    if diff <= 0.20:
        return 2
    return 1


def score_straight(diff: float) -> int:
    if diff <= -0.04:
        return 5
    if diff <= -0.01:
        return 4
    if diff <= 0.01:
        return 3
    if diff <= 0.04:
        return 2
    return 1


SCORE_FN = {
    "ex": score_ex,
    "st": score_st,
    "lap": score_lap,
    "around": score_around,
    "straight": score_straight,
}


def load_place_averages(conn) -> dict[str, float]:
    sql = """
SELECT sm.stadium_code, ea.avg_exhibition_time_6m
FROM boat_race.stadium_master sm
JOIN boat_race.exhibition_avg_6m ea
  ON ea.stadium_name = sm.stadium_name
"""
    with conn.cursor() as cur:
        cur.execute(sql)
        return {
            str(code): float(value)
            for code, value in cur.fetchall()
            if value is not None and float(value) > 0
        }


def load_raw_rows(conn) -> list[tuple]:
    sql = """
SELECT DISTINCT ON (re.race_code, re.player_id)
    re.race_code,
    rm.race_date,
    SUBSTRING(re.race_code, 9, 3) AS place,
    re.lane_number,
    el.entry_course,
    el.exhibition_time,
    el.start_timing,
    el.lap_time,
    el.around_time,
    el.straight_time,
    rrd.rank
FROM boat_race.race_entry re
JOIN boat_race.race_master rm
  ON rm.race_code = re.race_code
JOIN boat_race.exhibition_live el
  ON el.race_code = re.race_code
 AND el.player_id = re.player_id
LEFT JOIN boat_race.race_result_detail rrd
  ON rrd.race_code = re.race_code
 AND rrd.player_id = re.player_id
WHERE rm.race_date BETWEEN %(start)s::date AND %(end)s::date
ORDER BY re.race_code, re.player_id, el.created_date DESC NULLS LAST
"""
    with conn.cursor() as cur:
        cur.execute(sql, {"start": START.isoformat(), "end": TEST_END.isoformat()})
        return cur.fetchall()


def valid_rank(value) -> int | None:
    try:
        rank = int(str(value).strip())
        return rank if 1 <= rank <= 6 else None
    except (TypeError, ValueError):
        return None


def average(values) -> float:
    good = [float(value) for value in values if math.isfinite(number(value))]
    return float(np.mean(good)) if good else float("nan")


def build_dataset() -> tuple[list[dict], dict]:
    v5 = load_v5()
    with psycopg2.connect(**load_db_config()) as conn:
        place_avg = load_place_averages(conn)
        raw = load_raw_rows(conn)
    grouped = defaultdict(list)
    for row in raw:
        grouped[str(row[0])].append(row)
    boats = []
    skip = Counter()
    type_counts = Counter()
    for code, rows in grouped.items():
        if len(rows) != 6:
            skip["not_six_rows"] += 1
            continue
        race_date = rows[0][1]
        place = str(rows[0][2])
        if place not in place_avg:
            skip["place_average_missing"] += 1
            continue
        courses = [int(row[4]) for row in rows if row[4] is not None]
        raw_ranks = [valid_rank(row[10]) for row in rows]
        top_ranks = sorted(rank for rank in raw_ranks if rank in (1, 2, 3))
        if sorted(courses) != [1, 2, 3, 4, 5, 6] or top_ranks != [1, 2, 3]:
            skip["course_or_result_invalid"] += 1
            continue
        lap_avg = average(row[7] for row in rows)
        around_avg = average(row[8] for row in rows)
        straight_avg = average(row[9] for row in rows)
        staged = []
        complete = True
        for row, raw_rank in zip(rows, raw_ranks):
            # result_detailは多くのレースで上位4艇まで。今回の目的は
            # 1着・2連対・3連対なので、4着以下/欠損は同じ非該当として扱う。
            rank = int(raw_rank) if raw_rank in (1, 2, 3) else 4
            lane = int(row[3])
            course = int(row[4])
            probability = v5.get((code, course))
            if probability is None or not math.isfinite(float(probability)):
                complete = False
                break
            ex = number(row[5])
            st = number(row[6])
            lap = number(row[7])
            around = number(row[8])
            straight = number(row[9])
            ex_diff = ex - place_avg[place] if math.isfinite(ex) else float("nan")
            lap_diff = lap - lap_avg if math.isfinite(lap) and math.isfinite(lap_avg) else float("nan")
            around_diff = around - around_avg if math.isfinite(around) and math.isfinite(around_avg) else float("nan")
            straight_diff = (
                straight - straight_avg
                if math.isfinite(straight) and math.isfinite(straight_avg)
                else float("nan")
            )
            scores = {
                "ex": 3 if not math.isfinite(ex_diff) else score_ex(ex_diff),
                "st": 3 if not math.isfinite(st) else score_st(st),
                "lap": 3 if not math.isfinite(lap_diff) else score_lap(lap_diff),
                "around": 3 if not math.isfinite(around_diff) else score_around(around_diff),
                "straight": 3 if not math.isfinite(straight_diff) else score_straight(straight_diff),
            }
            ex_total = scores["ex"] + scores["lap"] + scores["around"] + scores["straight"]
            attack = scores["st"] + scores["straight"]
            stable = scores["lap"] + scores["around"]
            total = ex_total + attack + stable
            if scores["lap"] == 5 or (scores["straight"] >= 4 and ex_total >= 16):
                dtype = "超伸び型"
            elif scores["straight"] >= ex_total + 2 and scores["st"] >= 4:
                dtype = "攻め型"
            elif ex_total >= scores["straight"] + 2 and scores["around"] >= 4:
                dtype = "差し型"
            else:
                dtype = "バランス"
            staged.append({
                "race_code": code,
                "race_date": race_date,
                "place": place,
                "lane": lane,
                "course": course,
                "rank": int(rank),
                "v5": float(probability),
                "ex_diff": ex_diff,
                "start_timing": st,
                "lap_diff": lap_diff,
                "around_diff": around_diff,
                "straight_diff": straight_diff,
                **{f"{key}_score": value for key, value in scores.items()},
                "ex_total": ex_total,
                "attack_potential": attack,
                "stable_score": stable,
                "current_total": total,
                "dtype": dtype,
            })
        if not complete:
            skip["v5_missing"] += 1
            continue
        if len(staged) != 6:
            skip["incomplete"] += 1
            continue
        boats.extend(staged)
        type_counts.update(row["dtype"] for row in staged)
        skip["ready_races"] += 1
    return boats, {"skip": dict(skip), "type_counts": dict(type_counts)}


def baseline_tables(rows: list[dict], target_rank: int):
    global_mean = np.mean([row["rank"] <= target_rank for row in rows])
    course_counts = defaultdict(lambda: [0, 0])
    place_course_counts = defaultdict(lambda: [0, 0])
    for row in rows:
        y = int(row["rank"] <= target_rank)
        course_counts[row["course"]][0] += y
        course_counts[row["course"]][1] += 1
        place_course_counts[(row["place"], row["course"])][0] += y
        place_course_counts[(row["place"], row["course"])][1] += 1
    course_rate = {
        key: (wins + 100 * global_mean) / (count + 100)
        for key, (wins, count) in course_counts.items()
    }
    place_course_rate = {}
    for key, (wins, count) in place_course_counts.items():
        prior = course_rate[key[1]]
        place_course_rate[key] = (wins + 200 * prior) / (count + 200)
    return global_mean, course_rate, place_course_rate


def baseline_probability(row, tables) -> float:
    global_mean, course_rate, place_course_rate = tables
    return float(place_course_rate.get(
        (row["place"], row["course"]),
        course_rate.get(row["course"], global_mean),
    ))


def probability_metrics(y, probability) -> dict:
    y = np.asarray(y, dtype=np.int8)
    probability = np.clip(np.asarray(probability, dtype=np.float64), EPS, 1.0 - EPS)
    return {
        "n": int(len(y)),
        "nll": float(log_loss(y, probability, labels=[0, 1])),
        "brier": float(np.mean((probability - y) ** 2)),
    }


def score_band_audit(train, test, item: str, target_rank: int, tables) -> dict:
    score_key = f"{item}_score"
    train_stats = defaultdict(lambda: [0.0, 0])
    for row in train:
        y = int(row["rank"] <= target_rank)
        residual = y - baseline_probability(row, tables)
        stat = train_stats[int(row[score_key])]
        stat[0] += residual
        stat[1] += 1
    effects = {
        score: total / count if count else 0.0
        for score, (total, count) in train_stats.items()
    }
    bands = {}
    y_test, p_test = [], []
    for score in range(1, 6):
        selected = [row for row in test if int(row[score_key]) == score]
        y = [int(row["rank"] <= target_rank) for row in selected]
        bands[str(score)] = {
            "n": len(selected),
            "actual_rate": float(np.mean(y)) if y else None,
            "train_adjusted_effect": effects.get(score, 0.0),
        }
    for row in test:
        base = baseline_probability(row, tables)
        y_test.append(int(row["rank"] <= target_rank))
        p_test.append(np.clip(base + effects.get(int(row[score_key]), 0.0), EPS, 1.0 - EPS))
    return {"bands": bands, "metrics": probability_metrics(y_test, p_test)}


def tree_threshold_audit(train, test, item: str, target_rank: int, tables) -> dict:
    raw_key = RAW_KEYS[item]
    train_rows = [row for row in train if math.isfinite(float(row[raw_key]))]
    test_rows = [row for row in test if math.isfinite(float(row[raw_key]))]
    x_train = np.asarray([[float(row[raw_key])] for row in train_rows])
    residual_train = np.asarray([
        int(row["rank"] <= target_rank) - baseline_probability(row, tables)
        for row in train_rows
    ])
    tree = DecisionTreeRegressor(
        max_leaf_nodes=5,
        min_samples_leaf=max(300, len(train_rows) // 100),
        random_state=428,
    )
    tree.fit(x_train, residual_train)
    leaves = tree.apply(x_train)
    effects = {}
    for leaf in np.unique(leaves):
        values = residual_train[leaves == leaf]
        effects[int(leaf)] = float(np.mean(values))
    x_test = np.asarray([[float(row[raw_key])] for row in test_rows])
    test_leaves = tree.apply(x_test)
    y = [int(row["rank"] <= target_rank) for row in test_rows]
    probability = [
        np.clip(
            baseline_probability(row, tables) + effects[int(leaf)],
            EPS,
            1.0 - EPS,
        )
        for row, leaf in zip(test_rows, test_leaves)
    ]
    thresholds = sorted(
        float(value)
        for value in tree.tree_.threshold
        if value != -2.0
    )
    return {
        "thresholds": thresholds,
        "metrics": probability_metrics(y, probability),
    }


def design_matrix(rows, places, extra_keys):
    matrix = []
    for row in rows:
        probability = np.clip(float(row["v5"]), 1.0e-6, 1.0 - 1.0e-6)
        values = [math.log(probability / (1.0 - probability))]
        values.extend(float(row[key]) for key in extra_keys)
        values.extend(1.0 if row["course"] == course else 0.0 for course in range(2, 7))
        values.extend(1.0 if row["place"] == place else 0.0 for place in places[1:])
        matrix.append(values)
    return np.asarray(matrix, dtype=np.float64)


def logistic_variant(train, test, target_rank, extra_keys, places):
    x_train = design_matrix(train, places, extra_keys)
    x_test = design_matrix(test, places, extra_keys)
    y_train = np.asarray([row["rank"] <= target_rank for row in train], dtype=np.int8)
    y_test = np.asarray([row["rank"] <= target_rank for row in test], dtype=np.int8)
    numeric_count = 1 + len(extra_keys)
    mean = x_train[:, :numeric_count].mean(axis=0)
    std = x_train[:, :numeric_count].std(axis=0)
    std[std == 0] = 1.0
    x_train[:, :numeric_count] = (x_train[:, :numeric_count] - mean) / std
    x_test[:, :numeric_count] = (x_test[:, :numeric_count] - mean) / std
    model = LogisticRegression(C=1.0, max_iter=500, solver="lbfgs")
    model.fit(x_train, y_train)
    probability = model.predict_proba(x_test)[:, 1]
    coefficients = {
        key: float(model.coef_[0][1 + index] / std[1 + index])
        for index, key in enumerate(extra_keys)
    }
    return probability_metrics(y_test, probability), coefficients


def type_audit(rows: list[dict]) -> dict:
    out = {}
    for dtype in ("超伸び型", "攻め型", "差し型", "バランス"):
        selected = [row for row in rows if row["dtype"] == dtype]
        out[dtype] = {
            "n": len(selected),
            "first_rate": float(np.mean([row["rank"] == 1 for row in selected])) if selected else None,
            "top2_rate": float(np.mean([row["rank"] <= 2 for row in selected])) if selected else None,
            "top3_rate": float(np.mean([row["rank"] <= 3 for row in selected])) if selected else None,
        }
    return out


def main() -> int:
    print("展示評価監査データを構築しています…", flush=True)
    rows, coverage = build_dataset()
    train = [row for row in rows if row["race_date"] <= TRAIN_END]
    test = [row for row in rows if TEST_START <= row["race_date"] <= TEST_END]
    if not train or not test:
        raise RuntimeError("学習・テスト期間のデータが不足しています")
    print(
        f"ready={coverage['skip'].get('ready_races', 0):,}R "
        f"train={len(train)//6:,}R test={len(test)//6:,}R",
        flush=True,
    )

    thresholds = {}
    for target_rank in (1, 2, 3):
        print(f"閾値監査: {target_rank}着以内", flush=True)
        tables = baseline_tables(train, target_rank)
        target = {}
        for item in ITEMS:
            current_band = score_band_audit(train, test, item, target_rank, tables)
            learned = tree_threshold_audit(train, test, item, target_rank, tables)
            target[item] = {
                "current": current_band,
                "learned_tree": learned,
                "nll_improvement": (
                    current_band["metrics"]["nll"] - learned["metrics"]["nll"]
                ),
                "brier_improvement": (
                    current_band["metrics"]["brier"] - learned["metrics"]["brier"]
                ),
            }
        thresholds[f"top{target_rank}"] = target

    print("重み・合成方法を監査しています…", flush=True)
    places = sorted({row["place"] for row in rows})
    variants = {
        "BASE_V5": (),
        "EX_TOTAL": ("ex_total",),
        "CURRENT_TOTAL": ("current_total",),
        "THREE_PARTS": ("ex_total", "attack_potential", "stable_score"),
        "FIVE_COMPONENTS": tuple(f"{item}_score" for item in ITEMS),
    }
    weight_results = {}
    for target_rank in (2, 3):
        target = {}
        for name, keys in variants.items():
            metric, coefficients = logistic_variant(
                train, test, target_rank, keys, places
            )
            target[name] = {"metrics": metric, "coefficients": coefficients}
        weight_results[f"top{target_rank}"] = target

    report = {
        "period": {
            "start": START.isoformat(),
            "train_end": TRAIN_END.isoformat(),
            "test_start": TEST_START.isoformat(),
            "test_end": TEST_END.isoformat(),
        },
        "production_changed": False,
        "coverage": coverage,
        "current_formula": {
            "attack_potential": "st_score + straight_score",
            "stable_score": "lap_score + around_score",
            "ex_total": "ex_score + lap_score + around_score + straight_score",
            "current_total_expanded": "ex + st + 2*lap + 2*around + 2*straight",
            "implied_weights": {"ex": 1, "st": 1, "lap": 2, "around": 2, "straight": 2},
        },
        "threshold_audit": thresholds,
        "weight_audit": weight_results,
        "type_audit_test": type_audit(test),
        "notes": [
            "閾値候補は2026-08-31以前だけで学習し、2026-09-01以降で評価",
            "コース・場の基礎差を差し引いて閾値効果を比較",
            "重み監査はAI1着率v5・コース・場を共通土台にして追加価値を比較",
            "exhibition_avg_6mは現行画面と同じ現在の場別テーブルを使用",
        ],
    }
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    OUTPUT.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")

    print("\n【閾値候補によるNLL改善（正なら改善）】")
    for target_name, target in thresholds.items():
        print(target_name)
        for item, row in target.items():
            print(
                f"  {item:<8} ΔNLL={row['nll_improvement']:+.6f} "
                f"閾値={','.join(f'{x:.4f}' for x in row['learned_tree']['thresholds'])}"
            )
    print("\n【重み・合成方法】")
    for target_name, target in weight_results.items():
        print(target_name)
        for name, row in target.items():
            metric = row["metrics"]
            print(f"  {name:<16} NLL={metric['nll']:.6f} Br={metric['brier']:.6f}")
        coefficients = target["FIVE_COMPONENTS"]["coefficients"]
        print("  学習係数=" + json.dumps(coefficients, ensure_ascii=False))
    print("\n【展示タイプ件数（テスト）】")
    print(json.dumps(report["type_audit_test"], ensure_ascii=False))
    print(f"保存: {OUTPUT}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
