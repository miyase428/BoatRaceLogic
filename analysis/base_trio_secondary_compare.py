#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
AI3連対率 STEP 3: 基礎3連対率 + 一次評価 + 二次評価の追加効果を検証する。

方針
----
- STEP1で採用した BB_MEDIUM RAW（Kpc=20, Kpvc=10）を基礎3連対率とする。
- STEP2で追加価値が確認できた一次評価 first_total_score をレース内Z値で使う。
- 今回は二次評価 second_score もレース内Z値にして追加する。
- P1でロジスティック回帰を学習し、P2を完全ホールドアウト評価する。
- 比較:
    BASE_PLUS_PRIMARY
        logit(BASE) + primary_z
    BASE_PLUS_PRIMARY_SECONDARY
        logit(BASE) + primary_z + secondary_z
- 本番Webは変更しない。

Usage:
    python3 analysis/base_trio_secondary_compare.py \
      analysis/output/final_prediction_boats_20260615_20260714_OLD.csv \
      analysis/output/final_prediction_boats_20260715_20260814_OLD.csv
"""

from __future__ import annotations

import csv
import math
import sys
from collections import defaultdict
from datetime import datetime
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import base_trio_rate_compare as trio
import base_trio_primary_compare as primary_step


K_PC = 20.0
K_PVC = 10.0
L2 = 1e-4


def parse_date(value: str):
    return datetime.strptime(value, "%Y-%m-%d").date()


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


def z_map(values_by_lane):
    vals = [float(values_by_lane[lane]) for lane in range(1, 7)]
    mean = sum(vals) / 6.0
    variance = sum((x - mean) ** 2 for x in vals) / 6.0
    sd = math.sqrt(variance)
    zero_sd = sd < 1e-12
    if zero_sd:
        sd = 1.0
    return {
        lane: (float(values_by_lane[lane]) - mean) / sd
        for lane in range(1, 7)
    }, zero_sd


def rank_map(values_by_lane):
    order = sorted(
        range(1, 7),
        key=lambda lane: (-float(values_by_lane[lane]), lane),
    )
    return {lane: idx + 1 for idx, lane in enumerate(order)}


def load_feature_csv(path: str):
    p = Path(path)
    if not p.is_file():
        raise RuntimeError(f"CSVが見つかりません: {path}")

    by_race = defaultdict(dict)
    dates = []
    input_rows = 0
    invalid_rows = 0

    with p.open("r", encoding="utf-8-sig", newline="") as fh:
        reader = csv.DictReader(fh)
        required = {
            "race_code",
            "race_date",
            "lane_number",
            "first_total_score",
            "second_score",
        }
        fields = set(reader.fieldnames or [])
        missing = required - fields
        if missing:
            raise RuntimeError(
                f"CSV必須列がありません: {', '.join(sorted(missing))} / {path}"
            )

        for row in reader:
            input_rows += 1
            race_code = str(row.get("race_code") or "").strip()
            lane = as_int(row.get("lane_number"))
            primary_score = as_float(row.get("first_total_score"))
            secondary_score = as_float(row.get("second_score"))
            race_date_text = str(row.get("race_date") or "").strip()

            if (
                not race_code
                or lane not in range(1, 7)
                or primary_score is None
                or secondary_score is None
            ):
                invalid_rows += 1
                continue

            try:
                race_date = parse_date(race_date_text)
            except Exception:
                invalid_rows += 1
                continue

            by_race[race_code][lane] = {
                "primary_score": primary_score,
                "secondary_score": secondary_score,
                "race_date": race_date,
            }
            dates.append(race_date)

    if not dates:
        raise RuntimeError(f"有効な特徴量行がありません: {path}")

    valid = {}
    incomplete_races = 0
    primary_zero_sd_races = 0
    secondary_zero_sd_races = 0

    for race_code, lanes in by_race.items():
        if set(lanes) != {1, 2, 3, 4, 5, 6}:
            incomplete_races += 1
            continue

        primary_values = {
            lane: lanes[lane]["primary_score"]
            for lane in range(1, 7)
        }
        secondary_values = {
            lane: lanes[lane]["secondary_score"]
            for lane in range(1, 7)
        }

        primary_z, primary_zero = z_map(primary_values)
        secondary_z, secondary_zero = z_map(secondary_values)
        if primary_zero:
            primary_zero_sd_races += 1
        if secondary_zero:
            secondary_zero_sd_races += 1

        primary_ranks = rank_map(primary_values)
        secondary_ranks = rank_map(secondary_values)

        enriched = {}
        for lane in range(1, 7):
            enriched[lane] = {
                "primary_score": float(primary_values[lane]),
                "primary_z": float(primary_z[lane]),
                "primary_rank": int(primary_ranks[lane]),
                "secondary_score": float(secondary_values[lane]),
                "secondary_z": float(secondary_z[lane]),
                "secondary_rank": int(secondary_ranks[lane]),
                "race_date": lanes[lane]["race_date"],
            }
        valid[race_code] = enriched

    return {
        "path": str(p),
        "races": valid,
        "start": min(dates),
        "end": max(dates),
        "input_rows": input_rows,
        "invalid_rows": invalid_rows,
        "input_races": len(by_race),
        "valid_races": len(valid),
        "incomplete_races": incomplete_races,
        "primary_zero_sd_races": primary_zero_sd_races,
        "secondary_zero_sd_races": secondary_zero_sd_races,
    }


def build_joined_races(snapshots, feature_map, start, end):
    races = []
    missing_features = 0
    invalid_join = 0

    for snap in snapshots:
        if snap.race_date < start or snap.race_date > end:
            continue

        features = feature_map.get(str(snap.race_code))
        if features is None:
            missing_features += 1
            continue

        boats = []
        for b in snap.boats:
            lane = int(b.lane)
            feat = features.get(lane)
            if feat is None:
                continue

            base_p = trio.prob_beta(b, K_PC, K_PVC)
            boats.append(
                {
                    "lane": lane,
                    "y": int(b.y),
                    "base_p": float(base_p),
                    "primary_score": float(feat["primary_score"]),
                    "primary_z": float(feat["primary_z"]),
                    "primary_rank": int(feat["primary_rank"]),
                    "secondary_score": float(feat["secondary_score"]),
                    "secondary_z": float(feat["secondary_z"]),
                    "secondary_rank": int(feat["secondary_rank"]),
                }
            )

        if len(boats) != 6 or sum(x["y"] for x in boats) != 3:
            invalid_join += 1
            continue

        boats.sort(key=lambda x: x["lane"])
        races.append(
            {
                "race_code": str(snap.race_code),
                "race_date": snap.race_date,
                "boats": boats,
            }
        )

    return races, missing_features, invalid_join


def flatten(races):
    return [boat for race in races for boat in race["boats"]]


def feature_vector(row, model_name):
    base_logit = primary_step.logit(row["base_p"])

    if model_name == "BASE_PLUS_PRIMARY":
        return [
            1.0,
            base_logit,
            float(row["primary_z"]),
        ]

    if model_name == "BASE_PLUS_PRIMARY_SECONDARY":
        return [
            1.0,
            base_logit,
            float(row["primary_z"]),
            float(row["secondary_z"]),
        ]

    raise RuntimeError(f"unknown model: {model_name}")


def fit_logistic(rows, model_name, max_iter=60):
    dim = 3 if model_name == "BASE_PLUS_PRIMARY" else 4
    beta = [0.0] * dim
    beta[1] = 1.0

    for iteration in range(1, max_iter + 1):
        grad = [0.0] * dim
        hess = [[0.0] * dim for _ in range(dim)]

        for row in rows:
            x = feature_vector(row, model_name)
            eta = sum(b * xi for b, xi in zip(beta, x))
            p = primary_step.sigmoid(eta)
            y = float(row["y"])
            diff = y - p
            w = max(p * (1.0 - p), 1e-10)

            for j in range(dim):
                grad[j] += x[j] * diff
                for k in range(dim):
                    hess[j][k] += w * x[j] * x[k]

        for j in range(1, dim):
            grad[j] -= L2 * beta[j]
            hess[j][j] += L2

        delta = primary_step.solve_linear(hess, grad)
        beta = [b + d for b, d in zip(beta, delta)]

        if max(abs(d) for d in delta) < 1e-9:
            return beta, iteration

    return beta, max_iter


def predict_row(row, model_name, beta):
    x = feature_vector(row, model_name)
    eta = sum(b * xi for b, xi in zip(beta, x))
    return primary_step.clip_prob(primary_step.sigmoid(eta))


def calibration(boat_rows):
    total_n = len(boat_rows)
    weighted_gap = 0.0
    table = []

    for low, high in trio.CALIB_BINS:
        selected = [r for r in boat_rows if low <= r["p"] < high]
        n = len(selected)
        if n == 0:
            table.append((low, high, 0, 0, None, None, None))
            continue

        pred = sum(r["p"] for r in selected) / n
        hits = sum(r["y"] for r in selected)
        actual = hits / n
        gap = actual - pred
        weighted_gap += n * abs(gap)
        table.append((low, high, n, hits, pred, actual, gap))

    ece = weighted_gap / total_n if total_n else float("nan")
    return table, ece


def evaluate(races, model_name, beta):
    if not races:
        raise RuntimeError("評価対象レースがありません")

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
            p = predict_row(b, model_name, beta)
            y = int(b["y"])
            cp = primary_step.clip_prob(p)

            brier += (p - y) ** 2
            logloss += -(
                y * math.log(cp)
                + (1 - y) * math.log(1.0 - cp)
            )
            boat_n += 1
            predicted.append((p, int(b["lane"]), y))
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
        predicted_set = {x[1] for x in top3_pred}

        captured += len(predicted_set & actual_top3)
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
        "top3_capture": captured / (len(races) * 3),
        "top3_exact": exact / len(races),
        "boat_rows": boat_rows,
        "calibration": cal_table,
    }


def secondary_rank_residual(races):
    rows = flatten(races)
    out = []

    for rank in range(1, 7):
        selected = [
            r for r in rows
            if r["secondary_rank"] == rank
        ]
        if not selected:
            continue

        n = len(selected)
        actual = sum(r["y"] for r in selected) / n
        avg_secondary = (
            sum(r["secondary_score"] for r in selected) / n
        )
        avg_primary_model_input = (
            sum(r["primary_z"] for r in selected) / n
        )

        out.append(
            (
                rank,
                n,
                avg_secondary,
                avg_primary_model_input,
                actual,
            )
        )

    return out


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
            (rank, n, pred, actual, actual - pred)
        )

    return out


def print_metrics_table(title, metrics_map):
    print(f"\n【{title}】")
    print(
        "方式                              R数     Brier     LogLoss"
        "      ECE    平均Σ6   上位3艇捕捉  Top3完全一致"
    )
    print("-" * 126)

    for name, m in metrics_map.items():
        print(
            f"{name:<32}"
            f"{m['races']:>6d}"
            f"   {m['brier']:.6f}"
            f"   {m['logloss']:.6f}"
            f"   {m['ece'] * 100:6.2f}pt"
            f"   {m['avg_sum6'] * 100:7.2f}%"
            f"      {m['top3_capture'] * 100:6.2f}%"
            f"         {m['top3_exact'] * 100:6.2f}%"
        )


def print_calibration(metrics):
    print("\n【P2 BASE+PRIMARY+SECONDARY calibration】")
    print(
        "確率帯       舟数   実Top3   平均予測"
        "    実3連対率    実績-予測"
    )
    print("-" * 78)

    for low, high, n, hits, pred, actual, gap in metrics["calibration"]:
        label = (
            f"{int(low * 100):>2d}-{int(min(high, 1.0) * 100):<3d}%"
        )
        if n == 0:
            print(f"{label:<10}{0:>7d}")
            continue

        print(
            f"{label:<10}"
            f"{n:>7d}"
            f"{hits:>9d}"
            f"{pred * 100:>11.2f}%"
            f"{actual * 100:>12.2f}%"
            f"{gap * 100:>+12.2f}pt"
        )

    print(f"ECE: {metrics['ece'] * 100:.3f}pt")


def print_model_rank(metrics):
    print("\n【P2 BASE+PRIMARY+SECONDARY 確率順位別 実3連対率】")
    print(
        "順位     舟数    平均予測    実3連対率    実績-予測"
    )
    print("-" * 66)

    for rank, n, pred, actual, gap in model_rank_table(metrics):
        print(
            f"{rank:>2d}位"
            f"{n:>10d}"
            f"{pred * 100:>12.2f}%"
            f"{actual * 100:>12.2f}%"
            f"{gap * 100:>+12.2f}pt"
        )


def main():
    if len(sys.argv) != 3:
        print(
            "Usage: python3 analysis/base_trio_secondary_compare.py "
            "P1_BOATS_CSV P2_BOATS_CSV"
        )
        return 2

    p1_csv = load_feature_csv(sys.argv[1])
    p2_csv = load_feature_csv(sys.argv[2])

    if p1_csv["end"] >= p2_csv["start"]:
        raise RuntimeError(
            "P1とP2が時系列で分離されていません。"
            "P1終了日 < P2開始日にしてください。"
        )

    eval_start = p1_csv["start"]
    eval_end = p2_csv["end"]

    print("DBから基礎3連対率snapshotを1回構築中...")
    snapshots, course_source, skipped = trio.load_snapshots(
        eval_start,
        eval_end,
    )

    p1_races, p1_missing, p1_invalid = build_joined_races(
        snapshots,
        p1_csv["races"],
        p1_csv["start"],
        p1_csv["end"],
    )
    p2_races, p2_missing, p2_invalid = build_joined_races(
        snapshots,
        p2_csv["races"],
        p2_csv["start"],
        p2_csv["end"],
    )

    if not p1_races or not p2_races:
        raise RuntimeError("P1/P2の結合後レースがありません")

    p1_rows = flatten(p1_races)

    beta_primary, iter_primary = fit_logistic(
        p1_rows,
        "BASE_PLUS_PRIMARY",
    )
    beta_secondary, iter_secondary = fit_logistic(
        p1_rows,
        "BASE_PLUS_PRIMARY_SECONDARY",
    )

    p1_metrics = {
        "BASE+PRIMARY": evaluate(
            p1_races,
            "BASE_PLUS_PRIMARY",
            beta_primary,
        ),
        "BASE+PRIMARY+SECONDARY": evaluate(
            p1_races,
            "BASE_PLUS_PRIMARY_SECONDARY",
            beta_secondary,
        ),
    }

    p2_metrics = {
        "BASE+PRIMARY": evaluate(
            p2_races,
            "BASE_PLUS_PRIMARY",
            beta_primary,
        ),
        "BASE+PRIMARY+SECONDARY": evaluate(
            p2_races,
            "BASE_PLUS_PRIMARY_SECONDARY",
            beta_secondary,
        ),
    }

    before = p2_metrics["BASE+PRIMARY"]
    after = p2_metrics["BASE+PRIMARY+SECONDARY"]

    print("=" * 128)
    print("AI3連対率 STEP 3：基礎3連対率 + 一次評価 + 二次評価 追加効果")
    print("=" * 128)
    print(f"学習CSV              : {p1_csv['path']}")
    print(
        f"学習期間(P1)         : "
        f"{p1_csv['start']} ～ {p1_csv['end']}"
    )
    print(f"評価CSV              : {p2_csv['path']}")
    print(
        f"評価期間(P2)         : "
        f"{p2_csv['start']} ～ {p2_csv['end']}"
    )
    print("基礎3連対率          : BB_MEDIUM RAW (Kpc=20, Kpvc=10)")
    print("一次特徴量            : first_total_score のレース内Z値")
    print("二次特徴量            : second_score のレース内Z値")
    print("学習                  : P1のみ / ロジスティック回帰")
    print("評価                  : P2完全ホールドアウト")
    print("300%強制正規化        : なし")
    print("本番Web変更           : なし")

    print("\n【CSV / snapshot結合】")
    print(
        f"P1 CSV: 入力{p1_csv['input_races']}R"
        f" / 6艇完備{p1_csv['valid_races']}R"
        f" / 結合{len(p1_races)}R"
        f" / 特徴量不足{p1_missing}R"
        f" / 結合不正{p1_invalid}R"
    )
    print(
        f"P2 CSV: 入力{p2_csv['input_races']}R"
        f" / 6艇完備{p2_csv['valid_races']}R"
        f" / 結合{len(p2_races)}R"
        f" / 特徴量不足{p2_missing}R"
        f" / 結合不正{p2_invalid}R"
    )
    print(
        "二次score標準偏差0R  : "
        f"P1={p1_csv['secondary_zero_sd_races']}"
        f" / P2={p2_csv['secondary_zero_sd_races']}"
    )
    print(
        "履歴実コースsource    : "
        + " / ".join(
            f"{k}={v}"
            for k, v in sorted(course_source.items())
        )
    )
    if skipped:
        print(
            "snapshot skip         : "
            + " / ".join(
                f"{k}={v}"
                for k, v in sorted(skipped.items())
            )
        )

    print("\n【P1で学習した係数】")
    print(
        "BASE+PRIMARY            : "
        f"intercept={beta_primary[0]:+.6f}"
        f" / base_logit={beta_primary[1]:+.6f}"
        f" / primary_z={beta_primary[2]:+.6f}"
        f" (iter={iter_primary})"
    )
    print(
        "BASE+PRIMARY+SECONDARY  : "
        f"intercept={beta_secondary[0]:+.6f}"
        f" / base_logit={beta_secondary[1]:+.6f}"
        f" / primary_z={beta_secondary[2]:+.6f}"
        f" / secondary_z={beta_secondary[3]:+.6f}"
        f" (iter={iter_secondary})"
    )
    print(
        "※ secondary_z が正なら、基礎率と一次評価が同程度でも"
        "二次評価が高い艇ほど3連対しやすい方向。"
    )

    print_metrics_table(
        "P1 学習期間内（参考）",
        p1_metrics,
    )
    print_metrics_table(
        "P2 ホールドアウト（最重要）",
        p2_metrics,
    )

    print(
        "\n【最重要: P2 BASE+PRIMARY → "
        "BASE+PRIMARY+SECONDARY の追加効果】"
    )
    print(
        f"Brier差              : "
        f"{after['brier'] - before['brier']:+.6f}"
        "  （マイナスが改善）"
    )
    print(
        f"LogLoss差            : "
        f"{after['logloss'] - before['logloss']:+.6f}"
        "  （マイナスが改善）"
    )
    print(
        f"ECE差                : "
        f"{(after['ece'] - before['ece']) * 100:+.2f}pt"
        "  （マイナスが改善）"
    )
    print(
        f"平均Σ6差             : "
        f"{(after['avg_sum6'] - before['avg_sum6']) * 100:+.2f}pt"
    )
    print(
        f"上位3艇捕捉率差      : "
        f"{(after['top3_capture'] - before['top3_capture']) * 100:+.2f}pt"
    )
    print(
        f"Top3完全一致率差     : "
        f"{(after['top3_exact'] - before['top3_exact']) * 100:+.2f}pt"
    )

    print("\n【P2 二次順位別 実3連対率（参考）】")
    print(
        "二次順位   舟数   平均二次score"
        "   平均primary_z   実3連対率"
    )
    print("-" * 82)
    for rank, n, avg_sec, avg_primary_z, actual in secondary_rank_residual(p2_races):
        print(
            f"{rank:>3d}位"
            f"{n:>8d}"
            f"{avg_sec:>15.3f}"
            f"{avg_primary_z:>16.3f}"
            f"{actual * 100:>13.2f}%"
        )

    print_calibration(after)
    print_model_rank(after)

    print("\n【判断方針】")
    print(
        "1. P2でBASE+PRIMARY+SECONDARYが"
        "BASE+PRIMARYよりBrier/LogLossの両方を改善するか"
    )
    print("2. ECEが大きく悪化していないか")
    print("3. 上位3艇捕捉率・Top3完全一致が維持または改善するか")
    print("4. secondary_z係数の符号と大きさがP2でも妥当そうか")
    print(
        "5. 追加価値が確認できたら二次評価を正式特徴量候補とし、"
        "次に展示/SUM/スリットを追加検証する"
    )
    print("=" * 128)

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
