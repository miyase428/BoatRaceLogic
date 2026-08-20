#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
AI3連対率 STEP 2: 基礎3連対率 + 一次評価の追加効果を検証する。

方針
----
- STEP1で採用候補となった BB_MEDIUM RAW（Kpc=20, Kpvc=10）を基礎3連対率とする。
- 一次評価は既存の艇別CSVに保存されている first_total_score を使う。
- 一次総合スコアはレース内でZ標準化し、絶対水準ではなく同一レース内の強弱として使う。
- 学習期間(P1)でロジスティック回帰を学習し、後続期間(P2)を完全ホールドアウト評価する。
- 基礎率の単なる再校正と一次評価の追加価値を分けるため、以下を比較する。
    BASE_RAW          : BB_MEDIUM RAWそのまま
    BASE_CAL          : logit(BASE)だけをP1で再校正
    BASE_PLUS_PRIMARY : logit(BASE) + 一次総合スコアZをP1で学習
- 本番Webは変更しない。

Usage:
    python3 analysis/base_trio_primary_compare.py \
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


K_PC = 20.0
K_PVC = 10.0
L2 = 1e-4
EPS = 1e-9


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


def load_primary_csv(path: str):
    p = Path(path)
    if not p.is_file():
        raise RuntimeError(f"CSVが見つかりません: {path}")

    by_race = defaultdict(dict)
    dates = []
    input_rows = 0
    invalid_rows = 0

    with p.open("r", encoding="utf-8-sig", newline="") as fh:
        reader = csv.DictReader(fh)
        required = {"race_code", "race_date", "lane_number", "first_total_score"}
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
            score = as_float(row.get("first_total_score"))
            race_date_text = str(row.get("race_date") or "").strip()

            if not race_code or lane not in range(1, 7) or score is None:
                invalid_rows += 1
                continue

            try:
                race_date = parse_date(race_date_text)
            except Exception:
                invalid_rows += 1
                continue

            by_race[race_code][lane] = {
                "score": score,
                "csv_first_rank": as_int(row.get("first_rank")),
                "race_date": race_date,
            }
            dates.append(race_date)

    if not dates:
        raise RuntimeError(f"有効な一次評価行がありません: {path}")

    valid = {}
    incomplete_races = 0
    zero_sd_races = 0

    for race_code, lanes in by_race.items():
        if set(lanes) != {1, 2, 3, 4, 5, 6}:
            incomplete_races += 1
            continue

        scores = [float(lanes[lane]["score"]) for lane in range(1, 7)]
        mean = sum(scores) / 6.0
        variance = sum((x - mean) ** 2 for x in scores) / 6.0
        sd = math.sqrt(variance)
        if sd < 1e-12:
            zero_sd_races += 1
            sd = 1.0

        order = sorted(range(1, 7), key=lambda lane: (-lanes[lane]["score"], lane))
        rank_map = {lane: idx + 1 for idx, lane in enumerate(order)}

        enriched = {}
        for lane in range(1, 7):
            score = float(lanes[lane]["score"])
            enriched[lane] = {
                "score": score,
                "score_z": (score - mean) / sd,
                "rank": rank_map[lane],
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
        "zero_sd_races": zero_sd_races,
    }


def clip_prob(p: float) -> float:
    return min(max(float(p), 1e-8), 1.0 - 1e-8)


def logit(p: float) -> float:
    p = clip_prob(p)
    return math.log(p / (1.0 - p))


def sigmoid(x: float) -> float:
    if x >= 35.0:
        return 1.0 - 1e-15
    if x <= -35.0:
        return 1e-15
    return 1.0 / (1.0 + math.exp(-x))


def build_joined_races(snapshots, primary_map, start, end):
    races = []
    missing_primary = 0
    invalid_join = 0

    for snap in snapshots:
        if snap.race_date < start or snap.race_date > end:
            continue

        primary = primary_map.get(str(snap.race_code))
        if primary is None:
            missing_primary += 1
            continue

        boats = []
        for b in snap.boats:
            lane = int(b.lane)
            pr = primary.get(lane)
            if pr is None:
                continue
            base_p = trio.prob_beta(b, K_PC, K_PVC)
            boats.append(
                {
                    "lane": lane,
                    "y": int(b.y),
                    "base_p": float(base_p),
                    "score": float(pr["score"]),
                    "score_z": float(pr["score_z"]),
                    "first_rank": int(pr["rank"]),
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

    return races, missing_primary, invalid_join


def flatten(races):
    return [boat for race in races for boat in race["boats"]]


def feature_vector(row, model_name):
    base_logit = logit(row["base_p"])
    if model_name == "BASE_CAL":
        return [1.0, base_logit]
    if model_name == "BASE_PLUS_PRIMARY":
        return [1.0, base_logit, float(row["score_z"])]
    raise RuntimeError(f"unknown model: {model_name}")


def solve_linear(matrix, vector):
    n = len(vector)
    a = [list(map(float, matrix[i])) + [float(vector[i])] for i in range(n)]

    for col in range(n):
        pivot = max(range(col, n), key=lambda r: abs(a[r][col]))
        if abs(a[pivot][col]) < 1e-12:
            raise RuntimeError("ロジスティック回帰の行列が特異です")
        if pivot != col:
            a[col], a[pivot] = a[pivot], a[col]

        pv = a[col][col]
        for j in range(col, n + 1):
            a[col][j] /= pv

        for r in range(n):
            if r == col:
                continue
            factor = a[r][col]
            if abs(factor) < 1e-18:
                continue
            for j in range(col, n + 1):
                a[r][j] -= factor * a[col][j]

    return [a[i][n] for i in range(n)]


def fit_logistic(rows, model_name, max_iter=60):
    dim = 2 if model_name == "BASE_CAL" else 3
    beta = [0.0] * dim
    beta[1] = 1.0  # まずは基礎率のlogitをそのまま使う位置から開始

    for iteration in range(1, max_iter + 1):
        grad = [0.0] * dim
        hess = [[0.0] * dim for _ in range(dim)]

        for row in rows:
            x = feature_vector(row, model_name)
            eta = sum(b * xi for b, xi in zip(beta, x))
            p = sigmoid(eta)
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

        delta = solve_linear(hess, grad)
        beta = [b + d for b, d in zip(beta, delta)]

        if max(abs(d) for d in delta) < 1e-9:
            return beta, iteration

    return beta, max_iter


def predict_row(row, model_name, beta=None):
    if model_name == "BASE_RAW":
        return clip_prob(row["base_p"])

    if beta is None:
        raise RuntimeError(f"{model_name} の係数がありません")

    x = feature_vector(row, model_name)
    eta = sum(b * xi for b, xi in zip(beta, x))
    return clip_prob(sigmoid(eta))


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


def evaluate(races, model_name, beta=None):
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
        actual_top3 = {b["lane"] for b in race["boats"] if b["y"] == 1}

        for b in race["boats"]:
            p = predict_row(b, model_name, beta)
            y = int(b["y"])
            cp = clip_prob(p)
            brier += (p - y) ** 2
            logloss += -(y * math.log(cp) + (1 - y) * math.log(1.0 - cp))
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
        top3_pred = sorted(predicted, key=lambda x: (-x[0], x[1]))[:3]
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


def primary_rank_residual(races):
    rows = flatten(races)
    out = []
    for rank in range(1, 7):
        selected = [r for r in rows if r["first_rank"] == rank]
        if not selected:
            continue
        n = len(selected)
        actual = sum(r["y"] for r in selected) / n
        base = sum(r["base_p"] for r in selected) / n
        avg_score = sum(r["score"] for r in selected) / n
        out.append((rank, n, avg_score, base, actual, actual - base))
    return out


def model_rank_table(metrics):
    by_race = defaultdict(list)
    for row in metrics["boat_rows"]:
        by_race[row["race_code"]].append(row)

    rank_rows = defaultdict(list)
    for rows in by_race.values():
        ordered = sorted(rows, key=lambda r: (-r["p"], r["lane"]))
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
        out.append((rank, n, pred, actual, actual - pred))
    return out


def pct(v):
    return f"{v * 100:6.2f}%"


def print_metrics(title, metrics_by_model):
    print(f"\n【{title}】")
    print("方式                 R数     Brier     LogLoss      ECE    平均Σ6   上位3艇捕捉  Top3完全一致")
    print("-" * 116)
    for name, m in metrics_by_model.items():
        print(
            f"{name:<20} {m['races']:>5}   {m['brier']:.6f}   {m['logloss']:.6f}   "
            f"{m['ece']*100:>6.2f}pt   {m['avg_sum6']*100:>7.2f}%     "
            f"{m['top3_capture']*100:>7.2f}%       {m['top3_exact']*100:>7.2f}%"
        )


def print_calibration(table):
    print("確率帯       舟数   実Top3   平均予測    実3連対率    実績-予測")
    print("-" * 78)
    for low, high, n, hits, pred, actual, gap in table:
        label = f"{low*100:>2.0f}-{min(high,1.0)*100:<3.0f}%"
        if n == 0:
            print(f"{label:<10} {0:>6} {0:>8}       -          -          -")
            continue
        print(
            f"{label:<10} {n:>6} {hits:>8}   {pred*100:>8.2f}%   "
            f"{actual*100:>8.2f}%   {gap*100:>+9.2f}pt"
        )


def main():
    if len(sys.argv) != 3:
        print(
            "Usage: python3 analysis/base_trio_primary_compare.py "
            "TRAIN_BOATS.csv TEST_BOATS.csv"
        )
        sys.exit(2)

    train_csv = load_primary_csv(sys.argv[1])
    test_csv = load_primary_csv(sys.argv[2])

    if train_csv["end"] >= test_csv["start"]:
        raise RuntimeError(
            "学習CSVの期間終了が評価CSV開始以降です。P1→P2の時系列順で指定してください。"
        )

    print("DBから基礎3連対率snapshotを1回構築中...")
    snapshots, course_source, skipped = trio.load_snapshots(
        train_csv["start"],
        test_csv["end"],
    )

    train_races, train_missing, train_invalid = build_joined_races(
        snapshots,
        train_csv["races"],
        train_csv["start"],
        train_csv["end"],
    )
    test_races, test_missing, test_invalid = build_joined_races(
        snapshots,
        test_csv["races"],
        test_csv["start"],
        test_csv["end"],
    )

    if not train_races or not test_races:
        raise RuntimeError("学習または評価の結合レースが0件です")

    train_rows = flatten(train_races)
    beta_base, iter_base = fit_logistic(train_rows, "BASE_CAL")
    beta_primary, iter_primary = fit_logistic(train_rows, "BASE_PLUS_PRIMARY")

    train_metrics = {
        "BASE_RAW": evaluate(train_races, "BASE_RAW"),
        "BASE_CAL": evaluate(train_races, "BASE_CAL", beta_base),
        "BASE+PRIMARY": evaluate(train_races, "BASE_PLUS_PRIMARY", beta_primary),
    }
    test_metrics = {
        "BASE_RAW": evaluate(test_races, "BASE_RAW"),
        "BASE_CAL": evaluate(test_races, "BASE_CAL", beta_base),
        "BASE+PRIMARY": evaluate(test_races, "BASE_PLUS_PRIMARY", beta_primary),
    }

    print("=" * 124)
    print("AI3連対率 STEP 2：基礎3連対率 + 一次評価 追加効果")
    print("=" * 124)
    print(f"学習CSV              : {train_csv['path']}")
    print(f"学習期間(P1)         : {train_csv['start']} ～ {train_csv['end']}")
    print(f"評価CSV              : {test_csv['path']}")
    print(f"評価期間(P2)         : {test_csv['start']} ～ {test_csv['end']}")
    print("基礎3連対率          : BB_MEDIUM RAW (Kpc=20, Kpvc=10)")
    print("一次特徴量            : first_total_score のレース内Z値")
    print("学習                  : P1のみ / ロジスティック回帰")
    print("評価                  : P2完全ホールドアウト")
    print("300%強制正規化        : なし")
    print("本番Web変更           : なし")

    print("\n【CSV / snapshot結合】")
    print(
        f"P1 CSV: 入力{train_csv['input_races']}R / 6艇完備{train_csv['valid_races']}R / "
        f"結合{len(train_races)}R / primary不足{train_missing}R / 結合不正{train_invalid}R"
    )
    print(
        f"P2 CSV: 入力{test_csv['input_races']}R / 6艇完備{test_csv['valid_races']}R / "
        f"結合{len(test_races)}R / primary不足{test_missing}R / 結合不正{test_invalid}R"
    )
    print(
        "履歴実コースsource    : "
        + " / ".join(f"{k}={v}" for k, v in sorted(course_source.items()))
    )
    if skipped:
        print("snapshot skip         : " + " / ".join(f"{k}={v}" for k, v in sorted(skipped.items())))

    print("\n【P1で学習した係数】")
    print(
        f"BASE_CAL          : intercept={beta_base[0]:+.6f} / base_logit={beta_base[1]:+.6f} "
        f"(iter={iter_base})"
    )
    print(
        f"BASE+PRIMARY      : intercept={beta_primary[0]:+.6f} / base_logit={beta_primary[1]:+.6f} / "
        f"primary_z={beta_primary[2]:+.6f} (iter={iter_primary})"
    )
    print("※ primary_z が正なら、基礎3連対率が同じでも一次スコア上位ほど3連対しやすい方向。")

    print_metrics("P1 学習期間内（参考）", train_metrics)
    print_metrics("P2 ホールドアウト（最重要）", test_metrics)

    base_cal = test_metrics["BASE_CAL"]
    primary = test_metrics["BASE+PRIMARY"]
    print("\n【最重要: P2 BASE_CAL → BASE+PRIMARY の追加効果】")
    print(f"Brier差              : {primary['brier'] - base_cal['brier']:+.6f}  （マイナスが改善）")
    print(f"LogLoss差            : {primary['logloss'] - base_cal['logloss']:+.6f}  （マイナスが改善）")
    print(f"ECE差                : {(primary['ece'] - base_cal['ece'])*100:+.2f}pt  （マイナスが改善）")
    print(f"平均Σ6差             : {(primary['avg_sum6'] - base_cal['avg_sum6'])*100:+.2f}pt")
    print(f"上位3艇捕捉率差      : {(primary['top3_capture'] - base_cal['top3_capture'])*100:+.2f}pt")
    print(f"Top3完全一致率差     : {(primary['top3_exact'] - base_cal['top3_exact'])*100:+.2f}pt")

    print("\n【P2 一次順位別：基礎3連対率が取り切れていない差】")
    print("一次順位   舟数  平均一次score  平均基礎率   実3連対率   実績-基礎")
    print("-" * 82)
    for rank, n, avg_score, base, actual, gap in primary_rank_residual(test_races):
        print(
            f"{rank:>3}位   {n:>6}     {avg_score:>8.3f}    {base*100:>8.2f}%   "
            f"{actual*100:>8.2f}%   {gap*100:>+9.2f}pt"
        )

    print("\n【P2 BASE+PRIMARY calibration】")
    print_calibration(primary["calibration"])
    print(f"ECE: {primary['ece']*100:.3f}pt")

    print("\n【P2 BASE+PRIMARY 確率順位別 実3連対率】")
    print("順位     舟数    平均予測    実3連対率    実績-予測")
    print("-" * 66)
    for rank, n, pred, actual, gap in model_rank_table(primary):
        print(
            f"{rank:>2}位    {n:>6}    {pred*100:>8.2f}%   {actual*100:>8.2f}%   {gap*100:>+9.2f}pt"
        )

    print("\n【判断方針】")
    print("1. P2でBASE+PRIMARYがBASE_CALよりBrier/LogLossの両方を改善するか")
    print("2. ECEが大きく悪化していないか")
    print("3. 上位3艇捕捉率・Top3完全一致も維持または改善するか")
    print("4. primary_z係数が正で、P2一次順位別の残差にも同じ方向性があるか")
    print("5. 追加価値が確認できたら一次評価をAI3連対率の正式特徴量候補とし、次に二次評価を追加検証する")
    print("=" * 124)


if __name__ == "__main__":
    main()
