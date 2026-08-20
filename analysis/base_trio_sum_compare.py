#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
AI3連対率 STEP 4: 基礎3連対率 + 一次 + 二次 に SUM を追加した独立効果を検証する。

方針
----
- 基礎3連対率: BB_MEDIUM RAW (Kpc=20, Kpvc=10)
- 一次: first_total_score のレース内Z値
- 二次: second_score のレース内Z値
- SUM:
    theories/new_sam/features.json の場別3展示指標を使う。
    6艇のSUM平均との差を既存8区間に分類し、
    「場×展示進入コース×SUM区間」の3連対率と
    「場×展示進入コース全体」の3連対率との差をローリングで作る。
    対象レース結果を統計へ入れる前に特徴量を作るため未来情報なし。
- SUMの母数不足を考慮して RAW / K20 / K50 / K100 を比較。
- P1で各ロジスティック回帰を学習し、P1のBrier最良SUM方式を1つ選ぶ。
- P2は完全ホールドアウト。最終判断はP1選択方式のP2成績で行う。
- SUMを計算できるレースだけに母集団を揃え、
  BASE+PRIMARY+SECONDARY と +SUM を公平比較する。
- 本番Webは変更しない。

Usage:
    python3 analysis/base_trio_sum_compare.py \
      analysis/output/final_prediction_boats_20260615_20260714_OLD.csv \
      analysis/output/final_prediction_boats_20260715_20260814_OLD.csv
"""

from __future__ import annotations

import json
import math
import sys
from collections import defaultdict
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import base_trio_rate_compare as trio
import base_trio_primary_compare as primary_step
import base_trio_secondary_compare as secondary_step
from slit_validate_v2 import connect_db


K_PC = 20.0
K_PVC = 10.0
L2 = 1e-4

SUM_INTERVALS = (
    ("-0.6未満", float("-inf"), -0.6),
    ("-0.6--0.4", -0.6, -0.4),
    ("-0.4--0.2", -0.4, -0.2),
    ("-0.2-0.0", -0.2, 0.0),
    ("0.0-0.2", 0.0, 0.2),
    ("0.2-0.4", 0.2, 0.4),
    ("0.4-0.6", 0.4, 0.6),
    ("0.6以上", 0.6, float("inf")),
)

SUM_METHODS = {
    "SUM_RAW": None,
    "SUM_K20": 20.0,
    "SUM_K50": 50.0,
    "SUM_K100": 100.0,
}

BASE_MODEL = "BASE+PRIMARY+SECONDARY"


def as_int(value):
    try:
        if value is None or value == "":
            return None
        return int(value)
    except (TypeError, ValueError):
        return None


def as_float(value):
    try:
        if value is None or value == "":
            return None
        return float(value)
    except (TypeError, ValueError):
        return None


def valid_course(value):
    c = as_int(value)
    return c if c is not None and 1 <= c <= 6 else None


def place_of(race_code: str) -> str:
    code = str(race_code or "")
    return code[8:11] if len(code) >= 11 else "???"


def load_sum_features():
    path = (
        Path(__file__).resolve().parent.parent
        / "theories"
        / "new_sam"
        / "features.json"
    )
    with path.open("r", encoding="utf-8") as fh:
        data = json.load(fh)

    for place, cols in data.items():
        if not isinstance(cols, list) or len(cols) != 3:
            raise RuntimeError(
                f"SUM features設定が不正です: {place}={cols}"
            )
    return data


def sum_interval_label(value: float) -> str:
    for label, low, high in SUM_INTERVALS:
        if low <= value < high:
            return label
    raise RuntimeError(f"SUM区間判定失敗: {value}")


def feature_value(row: dict, name: str):
    mapping = {
        "exhibition_time": "exhibition_time",
        "lap_time": "lap_time",
        "around_time": "around_time",
        "straight_time": "straight_time",
    }
    key = mapping.get(name)
    if key is None:
        raise RuntimeError(f"未対応SUM feature: {name}")
    return row.get(key)


def top3_result_is_valid(rows):
    ranks = [as_int(r["rank"]) for r in rows]
    return (
        ranks.count(1) == 1
        and ranks.count(2) == 1
        and ranks.count(3) == 1
    )


def prepare_sum_race(race_rows, feature_cols):
    if len(race_rows) != 6:
        return None

    prepared = []
    for row in race_rows:
        lane = valid_course(row["lane"])
        ex_course = valid_course(row["ex_course"])
        if lane is None or ex_course is None:
            return None

        values = []
        for col in feature_cols:
            v = as_float(feature_value(row, col))
            if v is None:
                return None
            values.append(v)

        prepared.append(
            {
                "lane": lane,
                "rank": as_int(row["rank"]),
                "ex_course": ex_course,
                "sum_raw": sum(values),
            }
        )

    if {r["lane"] for r in prepared} != {1, 2, 3, 4, 5, 6}:
        return None
    if {r["ex_course"] for r in prepared} != {1, 2, 3, 4, 5, 6}:
        return None

    mean_sum = sum(r["sum_raw"] for r in prepared) / 6.0
    for row in prepared:
        row["sum_diff"] = row["sum_raw"] - mean_sum
        row["sum_interval"] = sum_interval_label(row["sum_diff"])

    prepared.sort(key=lambda r: r["lane"])
    return prepared


def sum_scores_for_boat(
    place_code,
    course,
    interval_label,
    course_n,
    course_w,
    interval_n,
    interval_w,
):
    cn = course_n[place_code][course]
    cw = course_w[place_code][course]
    inn = interval_n[place_code][course][interval_label]
    inw = interval_w[place_code][course][interval_label]

    if cn <= 0:
        base = 0.0
        raw = 0.0
    else:
        base = cw / cn
        raw = (inw / inn - base) if inn > 0 else 0.0

    scores = {"SUM_RAW": raw}

    for method, k in SUM_METHODS.items():
        if method == "SUM_RAW":
            continue
        if cn <= 0:
            delta = 0.0
        else:
            smoothed = (inw + k * base) / (inn + k)
            delta = smoothed - base
        scores[method] = delta

    return scores, inn, cn


def load_rolling_sum_map(start_date, end_date):
    """
    start_date～end_dateの各レースについて、レース結果を統計へ入れる前の
    SUM 3連対バフ/デバフを作る。
    """
    features = load_sum_features()

    course_n = defaultdict(
        lambda: {c: 0 for c in range(1, 7)}
    )
    course_w = defaultdict(
        lambda: {c: 0 for c in range(1, 7)}
    )
    interval_n = defaultdict(
        lambda: {
            c: {label: 0 for label, _, _ in SUM_INTERVALS}
            for c in range(1, 7)
        }
    )
    interval_w = defaultdict(
        lambda: {
            c: {label: 0 for label, _, _ in SUM_INTERVALS}
            for c in range(1, 7)
        }
    )

    sum_map = {}
    stats = defaultdict(int)

    sql = """
        SELECT
            rm.race_date,
            re.race_code,
            re.lane_number,
            rrd.rank,
            el.entry_course,
            el.exhibition_time,
            el.lap_time,
            el.around_time,
            el.straight_time
        FROM boat_race.race_entry re
        JOIN boat_race.race_master rm
          ON rm.race_code = re.race_code
        LEFT JOIN boat_race.race_result_detail rrd
          ON rrd.race_code = re.race_code
         AND rrd.player_id = re.player_id
        LEFT JOIN LATERAL (
            SELECT
                entry_course,
                exhibition_time,
                lap_time,
                around_time,
                straight_time
            FROM boat_race.exhibition_live x
            WHERE x.race_code = re.race_code
              AND x.player_id = re.player_id
            LIMIT 1
        ) el ON TRUE
        WHERE rm.race_date <= %s::date
        ORDER BY rm.race_date, re.race_code, re.lane_number
    """

    with connect_db() as conn:
        cur = conn.cursor(name="base_trio_sum_stream")
        cur.itersize = 10000
        cur.execute(sql, (end_date.isoformat(),))

        current_code = None
        current_date = None
        rows = []

        def process_race(race_date, race_code, race_rows):
            if not race_rows:
                return

            place_code = place_of(race_code)
            feature_cols = features.get(place_code)
            if not feature_cols:
                stats["sum_features_missing_races"] += 1
                return

            prepared = prepare_sum_race(
                race_rows,
                feature_cols,
            )
            top3_valid = top3_result_is_valid(race_rows)

            if start_date <= race_date <= end_date:
                stats["target_races_seen"] += 1

                if prepared is None:
                    stats["target_sum_incomplete"] += 1
                elif not top3_valid:
                    stats["target_top3_invalid"] += 1
                else:
                    lanes = {}
                    nonzero = False
                    for row in prepared:
                        scores, inn, cn = sum_scores_for_boat(
                            place_code,
                            row["ex_course"],
                            row["sum_interval"],
                            course_n,
                            course_w,
                            interval_n,
                            interval_w,
                        )
                        if any(
                            abs(scores[m]) > 1e-12
                            for m in SUM_METHODS
                        ):
                            nonzero = True

                        lanes[row["lane"]] = {
                            **scores,
                            "sum_diff": float(row["sum_diff"]),
                            "interval_n": int(inn),
                            "course_n": int(cn),
                            "ex_course": int(row["ex_course"]),
                            "interval": row["sum_interval"],
                        }

                    if set(lanes) == {1, 2, 3, 4, 5, 6}:
                        sum_map[str(race_code)] = lanes
                        stats["target_sum_ready"] += 1
                        if nonzero:
                            stats["target_sum_nonzero_races"] += 1
                    else:
                        stats["target_sum_not_6"] += 1

            if prepared is None:
                stats["history_sum_incomplete"] += 1
                return
            if not top3_valid:
                stats["history_top3_invalid"] += 1
                return

            for row in prepared:
                c = row["ex_course"]
                label = row["sum_interval"]
                y = 1 if row["rank"] in (1, 2, 3) else 0

                course_n[place_code][c] += 1
                course_w[place_code][c] += y
                interval_n[place_code][c][label] += 1
                interval_w[place_code][c][label] += y

            stats["history_updated_races"] += 1

        for (
            race_date,
            race_code,
            lane,
            rank,
            ex_course,
            exhibition_time,
            lap_time,
            around_time,
            straight_time,
        ) in cur:
            race_code = str(race_code)

            if current_code is None:
                current_code = race_code
                current_date = race_date

            if race_code != current_code:
                process_race(
                    current_date,
                    current_code,
                    rows,
                )
                rows = []
                current_code = race_code
                current_date = race_date

            rows.append(
                {
                    "lane": lane,
                    "rank": rank,
                    "ex_course": ex_course,
                    "exhibition_time": exhibition_time,
                    "lap_time": lap_time,
                    "around_time": around_time,
                    "straight_time": straight_time,
                }
            )

        if current_code is not None:
            process_race(
                current_date,
                current_code,
                rows,
            )

        cur.close()

    return sum_map, stats


def build_joined_races(
    base_snapshots,
    csv_feature_map,
    sum_map,
    start,
    end,
):
    races = []
    counts = defaultdict(int)

    for snap in base_snapshots:
        if snap.race_date < start or snap.race_date > end:
            continue

        counts["base_snapshot_races"] += 1

        csv_features = csv_feature_map.get(str(snap.race_code))
        if csv_features is None:
            counts["csv_missing"] += 1
            continue

        sum_features = sum_map.get(str(snap.race_code))
        if sum_features is None:
            counts["sum_missing"] += 1
            continue

        boats = []
        for b in snap.boats:
            lane = int(b.lane)
            feat = csv_features.get(lane)
            sf = sum_features.get(lane)
            if feat is None or sf is None:
                continue

            boats.append(
                {
                    "lane": lane,
                    "y": int(b.y),
                    "base_p": float(
                        trio.prob_beta(b, K_PC, K_PVC)
                    ),
                    "primary_score": float(
                        feat["primary_score"]
                    ),
                    "primary_z": float(
                        feat["primary_z"]
                    ),
                    "secondary_score": float(
                        feat["secondary_score"]
                    ),
                    "secondary_z": float(
                        feat["secondary_z"]
                    ),
                    "SUM_RAW": float(sf["SUM_RAW"]),
                    "SUM_K20": float(sf["SUM_K20"]),
                    "SUM_K50": float(sf["SUM_K50"]),
                    "SUM_K100": float(sf["SUM_K100"]),
                    "sum_diff": float(sf["sum_diff"]),
                    "sum_interval_n": int(sf["interval_n"]),
                    "sum_course_n": int(sf["course_n"]),
                    "ex_course": int(sf["ex_course"]),
                }
            )

        if (
            len(boats) != 6
            or sum(x["y"] for x in boats) != 3
        ):
            counts["join_invalid"] += 1
            continue

        boats.sort(key=lambda x: x["lane"])
        races.append(
            {
                "race_code": str(snap.race_code),
                "race_date": snap.race_date,
                "boats": boats,
            }
        )
        counts["joined_races"] += 1

    return races, counts


def flatten(races):
    return [
        boat
        for race in races
        for boat in race["boats"]
    ]


def feature_vector(row, model_name):
    x = [
        1.0,
        primary_step.logit(row["base_p"]),
        float(row["primary_z"]),
        float(row["secondary_z"]),
    ]

    if model_name == BASE_MODEL:
        return x

    if model_name in SUM_METHODS:
        return x + [float(row[model_name])]

    raise RuntimeError(f"unknown model: {model_name}")


def fit_logistic(rows, model_name, max_iter=60):
    dim = 4 if model_name == BASE_MODEL else 5
    beta = [0.0] * dim
    beta[1] = 1.0

    for iteration in range(1, max_iter + 1):
        grad = [0.0] * dim
        hess = [[0.0] * dim for _ in range(dim)]

        for row in rows:
            x = feature_vector(row, model_name)
            eta = sum(
                b * xi
                for b, xi in zip(beta, x)
            )
            p = primary_step.sigmoid(eta)
            y = float(row["y"])
            diff = y - p
            w = max(p * (1.0 - p), 1e-10)

            for j in range(dim):
                grad[j] += x[j] * diff
                for k in range(dim):
                    hess[j][k] += (
                        w * x[j] * x[k]
                    )

        for j in range(1, dim):
            grad[j] -= L2 * beta[j]
            hess[j][j] += L2

        delta = primary_step.solve_linear(
            hess,
            grad,
        )
        beta = [
            b + d
            for b, d in zip(beta, delta)
        ]

        if max(abs(d) for d in delta) < 1e-9:
            return beta, iteration

    return beta, max_iter


def predict_row(row, model_name, beta):
    x = feature_vector(row, model_name)
    eta = sum(
        b * xi
        for b, xi in zip(beta, x)
    )
    return primary_step.clip_prob(
        primary_step.sigmoid(eta)
    )


def calibration(boat_rows):
    total_n = len(boat_rows)
    weighted_gap = 0.0
    table = []

    for low, high in trio.CALIB_BINS:
        selected = [
            r
            for r in boat_rows
            if low <= r["p"] < high
        ]
        n = len(selected)

        if n == 0:
            table.append(
                (low, high, 0, 0, None, None, None)
            )
            continue

        pred = sum(r["p"] for r in selected) / n
        hits = sum(r["y"] for r in selected)
        actual = hits / n
        gap = actual - pred
        weighted_gap += n * abs(gap)

        table.append(
            (
                low,
                high,
                n,
                hits,
                pred,
                actual,
                gap,
            )
        )

    ece = (
        weighted_gap / total_n
        if total_n
        else float("nan")
    )
    return table, ece


def evaluate(races, model_name, beta):
    if not races:
        raise RuntimeError(
            "評価対象レースがありません"
        )

    brier = 0.0
    logloss = 0.0
    boat_n = 0
    sum6 = 0.0
    captured = 0
    exact = 0
    boat_rows = []

    for race in races:
        predicted = []
        actual_top3 = {
            b["lane"]
            for b in race["boats"]
            if b["y"] == 1
        }

        for b in race["boats"]:
            p = predict_row(
                b,
                model_name,
                beta,
            )
            y = int(b["y"])
            cp = primary_step.clip_prob(p)

            brier += (p - y) ** 2
            logloss += -(
                y * math.log(cp)
                + (1 - y) * math.log(1.0 - cp)
            )
            boat_n += 1

            predicted.append(
                (p, int(b["lane"]), y)
            )
            boat_rows.append(
                {
                    **b,
                    "race_code": race["race_code"],
                    "p": p,
                    "y": y,
                }
            )

        sum6 += sum(x[0] for x in predicted)

        top3_pred = sorted(
            predicted,
            key=lambda x: (-x[0], x[1]),
        )[:3]
        predicted_set = {
            x[1]
            for x in top3_pred
        }

        captured += len(
            predicted_set & actual_top3
        )
        if predicted_set == actual_top3:
            exact += 1

    cal_table, ece = calibration(boat_rows)

    return {
        "races": len(races),
        "boats": boat_n,
        "brier": brier / boat_n,
        "logloss": logloss / boat_n,
        "ece": ece,
        "avg_sum6": sum6 / len(races),
        "top3_capture": (
            captured / (len(races) * 3)
        ),
        "top3_exact": exact / len(races),
        "boat_rows": boat_rows,
        "calibration": cal_table,
    }


def model_rank_table(metrics):
    by_race = defaultdict(list)

    for row in metrics["boat_rows"]:
        by_race[row["race_code"]].append(row)

    rank_rows = defaultdict(list)
    for rows in by_race.values():
        ordered = sorted(
            rows,
            key=lambda r: (-r["p"], r["lane"]),
        )
        for rank, row in enumerate(ordered, 1):
            rank_rows[rank].append(row)

    out = []
    for rank in range(1, 7):
        rows = rank_rows[rank]
        if not rows:
            continue

        n = len(rows)
        pred = sum(r["p"] for r in rows) / n
        actual = sum(r["y"] for r in rows) / n

        out.append(
            (
                rank,
                n,
                pred,
                actual,
                actual - pred,
            )
        )

    return out


def sum_score_groups(races, method):
    rows = flatten(races)

    groups = (
        ("<-5pt", lambda s: s < -0.05),
        ("-5～-2pt", lambda s: -0.05 <= s < -0.02),
        ("-2～0pt", lambda s: -0.02 <= s < 0.0),
        ("0～+2pt", lambda s: 0.0 <= s < 0.02),
        ("+2～+5pt", lambda s: 0.02 <= s < 0.05),
        (">=+5pt", lambda s: s >= 0.05),
    )

    out = []
    for label, predicate in groups:
        selected = [
            r
            for r in rows
            if predicate(float(r[method]))
        ]
        if not selected:
            out.append(
                (label, 0, None, None, None, None)
            )
            continue

        n = len(selected)
        avg_score = (
            sum(float(r[method]) for r in selected)
            / n
        )
        actual = sum(r["y"] for r in selected) / n
        avg_interval_n = (
            sum(r["sum_interval_n"] for r in selected)
            / n
        )
        avg_course_n = (
            sum(r["sum_course_n"] for r in selected)
            / n
        )

        out.append(
            (
                label,
                n,
                avg_score,
                actual,
                avg_interval_n,
                avg_course_n,
            )
        )

    return out


def print_metrics_table(title, metrics_map):
    print(f"\n【{title}】")
    print(
        "方式                              R数     Brier"
        "     LogLoss      ECE    平均Σ6"
        "   上位3艇捕捉  Top3完全一致"
    )
    print("-" * 128)

    for name, m in metrics_map.items():
        print(
            f"{name:<33}"
            f"{m['races']:6d}  "
            f"{m['brier']:9.6f}  "
            f"{m['logloss']:9.6f}  "
            f"{m['ece'] * 100:7.2f}pt  "
            f"{m['avg_sum6'] * 100:8.2f}%  "
            f"{m['top3_capture'] * 100:10.2f}%  "
            f"{m['top3_exact'] * 100:13.2f}%"
        )


def print_calibration(metrics):
    print("確率帯       舟数   実Top3   平均予測    実3連対率    実績-予測")
    print("-" * 78)

    for (
        low,
        high,
        n,
        hits,
        pred,
        actual,
        gap,
    ) in metrics["calibration"]:
        if high > 1.0:
            label = "90-100%"
        else:
            label = (
                f"{int(low * 100):2d}-"
                f"{int(high * 100):2d} %"
            )

        if n == 0:
            print(
                f"{label:<10}{0:6d}"
                f"{0:9d}{'-':>12}{'-':>13}{'-':>13}"
            )
            continue

        print(
            f"{label:<10}{n:6d}{hits:9d}"
            f"{pred * 100:11.2f}%"
            f"{actual * 100:12.2f}%"
            f"{gap * 100:+12.2f}pt"
        )

    print(
        f"ECE: {metrics['ece'] * 100:.3f}pt"
    )


def print_rank_table(metrics):
    print("順位     舟数    平均予測    実3連対率    実績-予測")
    print("-" * 66)

    for (
        rank,
        n,
        pred,
        actual,
        gap,
    ) in model_rank_table(metrics):
        print(
            f"{rank:2d}位"
            f"{n:10d}"
            f"{pred * 100:12.2f}%"
            f"{actual * 100:11.2f}%"
            f"{gap * 100:+12.2f}pt"
        )


def delta_line(label, value):
    print(
        f"{label:<22}: {value:+.6f}  （マイナスが改善）"
    )


def main():
    if len(sys.argv) != 3:
        print(
            "Usage: python3 analysis/base_trio_sum_compare.py "
            "P1_BOATS_CSV P2_BOATS_CSV"
        )
        sys.exit(2)

    p1_csv = sys.argv[1]
    p2_csv = sys.argv[2]

    p1 = secondary_step.load_feature_csv(p1_csv)
    p2 = secondary_step.load_feature_csv(p2_csv)

    if p1["end"] >= p2["start"]:
        raise RuntimeError(
            "P1学習期間はP2評価期間より前にしてください"
        )

    combined_start = p1["start"]
    combined_end = p2["end"]

    print(
        "DBから基礎3連対率snapshotを1回構築中..."
    )
    base_snapshots, course_source, base_skipped = (
        trio.load_snapshots(
            combined_start,
            combined_end,
        )
    )

    print(
        "DBからローリングSUM 3連対スコアを構築中..."
    )
    sum_map, sum_stats = load_rolling_sum_map(
        combined_start,
        combined_end,
    )

    p1_races, p1_join = build_joined_races(
        base_snapshots,
        p1["races"],
        sum_map,
        p1["start"],
        p1["end"],
    )
    p2_races, p2_join = build_joined_races(
        base_snapshots,
        p2["races"],
        sum_map,
        p2["start"],
        p2["end"],
    )

    if not p1_races or not p2_races:
        raise RuntimeError(
            "P1/P2のSUM評価可能レースがありません"
        )

    p1_rows = flatten(p1_races)

    model_names = [
        BASE_MODEL,
        *SUM_METHODS.keys(),
    ]

    betas = {}
    iterations = {}
    for model in model_names:
        beta, it = fit_logistic(
            p1_rows,
            model,
        )
        betas[model] = beta
        iterations[model] = it

    p1_metrics = {
        model: evaluate(
            p1_races,
            model,
            betas[model],
        )
        for model in model_names
    }

    selected_sum = min(
        SUM_METHODS.keys(),
        key=lambda name: (
            p1_metrics[name]["brier"],
            p1_metrics[name]["logloss"],
            p1_metrics[name]["ece"],
        ),
    )

    p2_metrics = {
        model: evaluate(
            p2_races,
            model,
            betas[model],
        )
        for model in model_names
    }

    baseline = p2_metrics[BASE_MODEL]
    selected = p2_metrics[selected_sum]

    print("=" * 136)
    print(
        "AI3連対率 STEP 4：基礎3連対率 + 一次 + 二次 + SUM 追加効果"
    )
    print("=" * 136)
    print(
        f"学習CSV              : {p1_csv}"
    )
    print(
        f"学習期間(P1)         : {p1['start']} ～ {p1['end']}"
    )
    print(
        f"評価CSV              : {p2_csv}"
    )
    print(
        f"評価期間(P2)         : {p2['start']} ～ {p2['end']}"
    )
    print(
        "基礎3連対率          : BB_MEDIUM RAW "
        "(Kpc=20, Kpvc=10)"
    )
    print(
        "一次特徴量            : first_total_score のレース内Z値"
    )
    print(
        "二次特徴量            : second_score のレース内Z値"
    )
    print(
        "SUM特徴量             : 場×展示進入コース×SUM区間の"
        "3連対率 - 場×展示進入コース3連対率"
    )
    print(
        "SUM統計               : 対象レースより前だけでローリング"
    )
    print(
        "SUM候補               : RAW / K20 / K50 / K100"
    )
    print(
        "SUM方式選択            : P1 Brier優先のみで選択"
    )
    print(
        "評価                  : P2完全ホールドアウト"
    )
    print(
        "300%強制正規化        : なし"
    )
    print(
        "本番Web変更           : なし"
    )

    print("\n【評価母集団 / coverage】")
    print(
        f"P1: 基礎snapshot={p1_join['base_snapshot_races']}R"
        f" / SUM評価可能={p1_join['joined_races']}R"
        f" / SUM不足={p1_join['sum_missing']}R"
        f" / CSV不足={p1_join['csv_missing']}R"
        f" / 結合不正={p1_join['join_invalid']}R"
    )
    print(
        f"P2: 基礎snapshot={p2_join['base_snapshot_races']}R"
        f" / SUM評価可能={p2_join['joined_races']}R"
        f" / SUM不足={p2_join['sum_missing']}R"
        f" / CSV不足={p2_join['csv_missing']}R"
        f" / 結合不正={p2_join['join_invalid']}R"
    )
    p1_cov = (
        p1_join["joined_races"]
        / p1_join["base_snapshot_races"]
        if p1_join["base_snapshot_races"]
        else 0.0
    )
    p2_cov = (
        p2_join["joined_races"]
        / p2_join["base_snapshot_races"]
        if p2_join["base_snapshot_races"]
        else 0.0
    )
    print(
        f"SUM coverage           : P1={p1_cov * 100:.2f}%"
        f" / P2={p2_cov * 100:.2f}%"
    )
    print(
        "SUM対象期間ready      : "
        f"{sum_stats['target_sum_ready']}R"
        f" / nonzero={sum_stats['target_sum_nonzero_races']}R"
        f" / 展示・進入不足={sum_stats['target_sum_incomplete']}R"
        f" / Top3不正={sum_stats['target_top3_invalid']}R"
    )
    print(
        "履歴実コースsource    : "
        + " / ".join(
            f"{k}={v}"
            for k, v in sorted(course_source.items())
        )
    )
    print(
        "基礎snapshot skip     : "
        + " / ".join(
            f"{k}={v}"
            for k, v in sorted(base_skipped.items())
            if v
        )
    )

    print("\n【P1で学習した係数】")
    for model in model_names:
        beta = betas[model]
        text = (
            f"{model:<33}: "
            f"intercept={beta[0]:+.6f}"
            f" / base_logit={beta[1]:+.6f}"
            f" / primary_z={beta[2]:+.6f}"
            f" / secondary_z={beta[3]:+.6f}"
        )
        if model != BASE_MODEL:
            text += f" / sum_score={beta[4]:+.6f}"
        text += f" (iter={iterations[model]})"
        print(text)

    print_metrics_table(
        "P1 学習期間内（SUM方式選択用）",
        p1_metrics,
    )

    print(
        "\n【P1で選択したSUM方式】 "
        f"{selected_sum} "
        f"/ Brier={p1_metrics[selected_sum]['brier']:.6f}"
        f" / LogLoss={p1_metrics[selected_sum]['logloss']:.6f}"
        f" / ECE={p1_metrics[selected_sum]['ece'] * 100:.2f}pt"
    )

    print_metrics_table(
        "P2 ホールドアウト（最重要）",
        p2_metrics,
    )

    print(
        f"\n【最重要: P2 {BASE_MODEL} → +{selected_sum} の追加効果】"
    )
    delta_line(
        "Brier差",
        selected["brier"] - baseline["brier"],
    )
    delta_line(
        "LogLoss差",
        selected["logloss"] - baseline["logloss"],
    )
    print(
        f"{'ECE差':<22}: "
        f"{(selected['ece'] - baseline['ece']) * 100:+.2f}pt"
        "  （マイナスが改善）"
    )
    print(
        f"{'平均Σ6差':<22}: "
        f"{(selected['avg_sum6'] - baseline['avg_sum6']) * 100:+.2f}pt"
    )
    print(
        f"{'上位3艇捕捉率差':<22}: "
        f"{(selected['top3_capture'] - baseline['top3_capture']) * 100:+.2f}pt"
    )
    print(
        f"{'Top3完全一致率差':<22}: "
        f"{(selected['top3_exact'] - baseline['top3_exact']) * 100:+.2f}pt"
    )

    selected_beta = betas[selected_sum]
    print(
        f"SUM係数              : {selected_beta[4]:+.6f}"
    )
    print(
        "※ 正なら、一次・二次まで同程度でもSUMの3連対バフが"
        "高い艇ほど3連対しやすい方向。"
    )

    print(
        f"\n【P2 {selected_sum} スコア帯別 実3連対率】"
    )
    print(
        "SUM帯        舟数    平均SUMscore   実3連対率"
        "   平均区間N   平均コースN"
    )
    print("-" * 82)
    for (
        label,
        n,
        avg_score,
        actual,
        avg_interval_n,
        avg_course_n,
    ) in sum_score_groups(p2_races, selected_sum):
        if n == 0:
            print(
                f"{label:<11}{0:7d}"
                f"{'-':>15}{'-':>13}{'-':>12}{'-':>13}"
            )
            continue

        print(
            f"{label:<11}{n:7d}"
            f"{avg_score * 100:+14.2f}pt"
            f"{actual * 100:12.2f}%"
            f"{avg_interval_n:12.1f}"
            f"{avg_course_n:13.1f}"
        )

    print(
        f"\n【P2 +{selected_sum} calibration】"
    )
    print_calibration(selected)

    print(
        f"\n【P2 +{selected_sum} 確率順位別 実3連対率】"
    )
    print_rank_table(selected)

    print("\n【判断方針】")
    print(
        "1. SUM方式はP1だけで選び、P2の結果を見て方式を選び直さない"
    )
    print(
        "2. P2でBrier/LogLossの両方が改善するか"
    )
    print(
        "3. ECEが大きく悪化しないか"
    )
    print(
        "4. 上位3艇捕捉率・Top3完全一致が維持または改善するか"
    )
    print(
        "5. SUM係数の符号と、P2のSUMスコア帯別実3連対率が整合するか"
    )
    print(
        "6. 改善が小さい/不安定なら、二次評価との情報重複としてSUMはAI3連対率へ入れない"
    )
    print("=" * 136)


if __name__ == "__main__":
    main()
