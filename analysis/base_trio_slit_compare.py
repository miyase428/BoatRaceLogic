#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
AI3連対率 STEP 5: 基礎3連対率 + 一次 + 二次 にスリットを追加した独立効果を検証する。

方針
----
- 基礎3連対率: BB_MEDIUM RAW (Kpc=20, Kpvc=10)
- 一次: first_total_score のレース内Z値
- 二次: second_score のレース内Z値
- スリット:
    現行C_ST_RANK予測PID（選手コースprofile不足時は展示STのみへfallback）を使う。
    予測PID × 展示進入コースの3連対率と、展示進入コース全体3連対率との差を特徴量にする。
    対象日の当日は学習へ入れず、直前180日だけでローリング集計する。
- 母数不足対策として RAW / K20 / K40 / K80 を比較。
  各liftは現行スリットbuffと同じ考え方で ±0.08 にcapする。
- P1で各ロジスティック回帰を学習し、P1 Brier最良方式を1つ選ぶ。
- P2は完全ホールドアウト。最終判断はP1選択方式のP2成績で行う。
- スリット計算可能レースだけに母集団を揃え、BASE+PRIMARY+SECONDARYと公平比較する。
- 本番Webは変更しない。

Usage:
    python3 analysis/base_trio_slit_compare.py \
      analysis/output/final_prediction_boats_20260615_20260714_OLD.csv \
      analysis/output/final_prediction_boats_20260715_20260814_OLD.csv
"""

from __future__ import annotations

import math
import sys
from collections import Counter, defaultdict, deque
from datetime import timedelta
from pathlib import Path

ANALYSIS_DIR = Path(__file__).resolve().parent
sys.path.insert(0, str(ANALYSIS_DIR))

import base_trio_rate_compare as trio
import base_trio_primary_compare as primary_step
import base_trio_secondary_compare as secondary_step
from base_winrate_slit_compare import (
    PATTERN_NAMES,
    build_slit_records,
    load_lane_to_ex_course,
)


K_PC = 20.0
K_PVC = 10.0
L2 = 1e-4
SLIT_HISTORY_DAYS = 180
SLIT_CAP = 0.08

SLIT_METHODS = {
    "SLIT_RAW": None,
    "SLIT_K20": 20.0,
    "SLIT_K40": 40.0,
    "SLIT_K80": 80.0,
}

BASE_MODEL = "BASE+PRIMARY+SECONDARY"


def clip_lift(value: float) -> float:
    return max(-SLIT_CAP, min(SLIT_CAP, float(value)))


def new_course_counts():
    return {
        c: {"n": 0, "trio": 0}
        for c in range(1, 7)
    }


def new_pattern_counts():
    return {
        pid: new_course_counts()
        for pid in range(1, 13)
    }


def add_history(course_counts, pattern_counts, pid, finish, sign=1):
    for course in range(1, 7):
        rank = float(finish[course])
        y = 1 if rank <= 3.0 else 0
        course_counts[course]["n"] += sign
        course_counts[course]["trio"] += sign * y
        pattern_counts[pid][course]["n"] += sign
        pattern_counts[pid][course]["trio"] += sign * y


def score_for_cell(course_counts, pattern_counts, pid, course):
    base_n = course_counts[course]["n"]
    base_w = course_counts[course]["trio"]
    pat_n = pattern_counts[pid][course]["n"]
    pat_w = pattern_counts[pid][course]["trio"]

    if base_n <= 0:
        base_rate = 0.0
        raw = 0.0
    else:
        base_rate = base_w / base_n
        if pat_n > 0:
            raw = pat_w / pat_n - base_rate
        else:
            raw = 0.0

    out = {"SLIT_RAW": clip_lift(raw)}
    for method, k in SLIT_METHODS.items():
        if method == "SLIT_RAW":
            continue
        weight = pat_n / (pat_n + k) if pat_n > 0 else 0.0
        out[method] = clip_lift(raw * weight)

    return out, pat_n, base_n, raw


def build_rolling_slit_map(start_date, end_date):
    """対象日より前180日だけで予測PID×展示進入コースのtrio liftを作る。"""
    history_start = start_date - timedelta(days=SLIT_HISTORY_DAYS)

    records, build_skip, build_methods = build_slit_records(
        history_start,
        end_date,
    )
    lane_to_course = load_lane_to_ex_course(start_date, end_date)

    by_date = defaultdict(list)
    for race_code, rec in records.items():
        by_date[rec["date"]].append((str(race_code), rec))

    for d in by_date:
        by_date[d].sort(key=lambda x: x[0])

    course_counts = new_course_counts()
    pattern_counts = new_pattern_counts()
    history_queue = deque()

    slit_map = {}
    stats = Counter()
    target_pattern_freq = Counter()
    target_method_freq = Counter()

    all_dates = sorted(by_date)
    for race_date in all_dates:
        cutoff = race_date - timedelta(days=SLIT_HISTORY_DAYS)
        while history_queue and history_queue[0][0] < cutoff:
            old_date, old_pid, old_finish = history_queue.popleft()
            add_history(
                course_counts,
                pattern_counts,
                old_pid,
                old_finish,
                sign=-1,
            )

        day_rows = by_date[race_date]

        # 当日の結果はまだ使わず、前日までの履歴だけで特徴量を作る。
        if start_date <= race_date <= end_date:
            for race_code, rec in day_rows:
                stats["target_prediction_ready"] += 1
                target_pattern_freq[int(rec["pid"])] += 1
                target_method_freq[str(rec["method"])] += 1

                cmap = lane_to_course.get(race_code, {})
                if (
                    set(cmap) != {1, 2, 3, 4, 5, 6}
                    or set(cmap.values()) != {1, 2, 3, 4, 5, 6}
                ):
                    stats["target_course_map_incomplete"] += 1
                    continue

                pid = int(rec["pid"])
                lanes = {}
                nonzero = False
                for lane in range(1, 7):
                    course = int(cmap[lane])
                    scores, pattern_n, baseline_n, raw = score_for_cell(
                        course_counts,
                        pattern_counts,
                        pid,
                        course,
                    )
                    if any(abs(scores[m]) > 1e-12 for m in SLIT_METHODS):
                        nonzero = True

                    lanes[lane] = {
                        **scores,
                        "pid": pid,
                        "method": str(rec["method"]),
                        "ex_course": course,
                        "pattern_n": int(pattern_n),
                        "baseline_n": int(baseline_n),
                        "raw_lift": float(raw),
                    }

                slit_map[race_code] = lanes
                stats["target_slit_ready"] += 1
                if nonzero:
                    stats["target_slit_nonzero"] += 1

        # その日の全レースの特徴量を作り終えてから履歴へ追加。
        for race_code, rec in day_rows:
            finish = rec.get("finish")
            if finish is None:
                stats["history_bad_finish"] += 1
                continue
            pid = int(rec["pid"])
            add_history(
                course_counts,
                pattern_counts,
                pid,
                finish,
                sign=1,
            )
            history_queue.append((race_date, pid, finish))
            stats["history_updated"] += 1

    stats["records_total"] = len(records)
    return (
        slit_map,
        stats,
        target_pattern_freq,
        target_method_freq,
        build_skip,
        build_methods,
    )


def build_joined_races(
    snapshots,
    feature_map,
    slit_map,
    start_date,
    end_date,
):
    races = []
    counts = Counter()

    for snap in snapshots:
        if not (start_date <= snap.race_date <= end_date):
            continue

        counts["base_snapshot"] += 1
        csv_feat = feature_map.get(str(snap.race_code))
        if csv_feat is None:
            counts["csv_missing"] += 1
            continue

        slit_feat = slit_map.get(str(snap.race_code))
        if slit_feat is None:
            counts["slit_missing"] += 1
            continue

        boats = []
        for boat in snap.boats:
            lane = int(boat.lane)
            feat = csv_feat.get(lane)
            sf = slit_feat.get(lane)
            if feat is None or sf is None:
                continue

            boats.append(
                {
                    "lane": lane,
                    "y": int(boat.y),
                    "base_p": float(trio.prob_beta(boat, K_PC, K_PVC)),
                    "primary_score": float(feat["primary_score"]),
                    "primary_z": float(feat["primary_z"]),
                    "secondary_score": float(feat["secondary_score"]),
                    "secondary_z": float(feat["secondary_z"]),
                    "SLIT_RAW": float(sf["SLIT_RAW"]),
                    "SLIT_K20": float(sf["SLIT_K20"]),
                    "SLIT_K40": float(sf["SLIT_K40"]),
                    "SLIT_K80": float(sf["SLIT_K80"]),
                    "pid": int(sf["pid"]),
                    "slit_method": str(sf["method"]),
                    "ex_course": int(sf["ex_course"]),
                    "pattern_n": int(sf["pattern_n"]),
                    "baseline_n": int(sf["baseline_n"]),
                    "raw_lift": float(sf["raw_lift"]),
                }
            )

        if len(boats) != 6 or sum(b["y"] for b in boats) != 3:
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
        counts["joined"] += 1

    return races, counts


def flatten(races):
    return [b for race in races for b in race["boats"]]


def feature_vector(row, slit_method=None):
    x = [
        1.0,
        primary_step.logit(row["base_p"]),
        float(row["primary_z"]),
        float(row["secondary_z"]),
    ]
    if slit_method is not None:
        x.append(float(row[slit_method]))
    return x


def fit_logistic(rows, slit_method=None, max_iter=60):
    dim = 4 if slit_method is None else 5
    beta = [0.0] * dim
    beta[1] = 1.0

    for iteration in range(1, max_iter + 1):
        grad = [0.0] * dim
        hess = [[0.0] * dim for _ in range(dim)]

        for row in rows:
            x = feature_vector(row, slit_method)
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


def predict_row(row, beta, slit_method=None):
    x = feature_vector(row, slit_method)
    eta = sum(b * xi for b, xi in zip(beta, x))
    return primary_step.clip_prob(primary_step.sigmoid(eta))


def evaluate(races, beta, slit_method=None):
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
            b["lane"] for b in race["boats"] if b["y"] == 1
        }

        for row in race["boats"]:
            p = predict_row(row, beta, slit_method)
            y = int(row["y"])
            cp = primary_step.clip_prob(p)

            brier += (p - y) ** 2
            logloss += -(y * math.log(cp) + (1 - y) * math.log(1 - cp))
            boat_n += 1
            predicted.append((p, int(row["lane"])))
            boat_rows.append({**row, "race_code": race["race_code"], "p": p})

        sum6 += sum(p for p, _ in predicted)
        top3 = sorted(predicted, key=lambda x: (-x[0], x[1]))[:3]
        pred_set = {lane for _, lane in top3}
        captured += len(pred_set & actual_top3)
        if pred_set == actual_top3:
            exact += 1

    cal_table, ece = secondary_step.calibration(boat_rows)
    n_races = len(races)
    return {
        "races": n_races,
        "boats": boat_n,
        "brier": brier / boat_n,
        "logloss": logloss / boat_n,
        "ece": ece,
        "avg_sum6": sum6 / n_races,
        "top3_capture": captured / (n_races * 3),
        "top3_exact": exact / n_races,
        "boat_rows": boat_rows,
        "calibration": cal_table,
    }


def score_band_rows(metrics, method):
    rows = metrics["boat_rows"]
    bands = (
        ("<-5pt", float("-inf"), -0.05),
        ("-5～-2pt", -0.05, -0.02),
        ("-2～0pt", -0.02, 0.0),
        ("0～+2pt", 0.0, 0.02),
        ("+2～+5pt", 0.02, 0.05),
        (">=+5pt", 0.05, float("inf")),
    )
    out = []
    for label, low, high in bands:
        selected = [r for r in rows if low <= r[method] < high]
        if not selected:
            out.append((label, 0, 0.0, 0.0, 0.0, 0.0))
            continue
        n = len(selected)
        avg_score = sum(r[method] for r in selected) / n
        actual = sum(r["y"] for r in selected) / n
        avg_pat_n = sum(r["pattern_n"] for r in selected) / n
        avg_base_n = sum(r["baseline_n"] for r in selected) / n
        out.append((label, n, avg_score, actual, avg_pat_n, avg_base_n))
    return out


def print_coeff(name, beta, iteration, slit_method=None):
    text = (
        f"{name:<30}: intercept={beta[0]:+.6f} / "
        f"base_logit={beta[1]:+.6f} / primary_z={beta[2]:+.6f} / "
        f"secondary_z={beta[3]:+.6f}"
    )
    if slit_method is not None:
        text += f" / slit_score={beta[4]:+.6f}"
    text += f" (iter={iteration})"
    print(text)


def print_calibration(title, metrics):
    print(f"\n【{title}】")
    print("確率帯       舟数   実Top3   平均予測    実3連対率    実績-予測")
    print("-" * 78)
    for low, high, n, hits, pred, actual, gap in metrics["calibration"]:
        label = f"{int(low * 100):>2d}-{int(min(high, 1.0) * 100):<3d}%"
        if n == 0:
            print(f"{label:<10}{0:>7d}")
            continue
        print(
            f"{label:<10}{n:>7d}{hits:>9d}{pred * 100:>11.2f}%"
            f"{actual * 100:>12.2f}%{gap * 100:>+12.2f}pt"
        )
    print(f"ECE: {metrics['ece'] * 100:.3f}pt")


def print_rank_table(title, metrics):
    print(f"\n【{title}】")
    print("順位     舟数    平均予測    実3連対率    実績-予測")
    print("-" * 66)
    for rank, n, pred, actual, gap in secondary_step.model_rank_table(metrics):
        print(
            f"{rank:>2d}位{n:>10d}{pred * 100:>12.2f}%"
            f"{actual * 100:>12.2f}%{gap * 100:>+12.2f}pt"
        )


def main():
    if len(sys.argv) != 3:
        print(
            "Usage: python3 analysis/base_trio_slit_compare.py "
            "P1_BOATS_CSV P2_BOATS_CSV"
        )
        return 2

    p1 = secondary_step.load_feature_csv(sys.argv[1])
    p2 = secondary_step.load_feature_csv(sys.argv[2])
    if p1["end"] >= p2["start"]:
        raise RuntimeError("P1終了日 < P2開始日にしてください")

    combined_start = p1["start"]
    combined_end = p2["end"]

    print("DBから基礎3連対率snapshotを1回構築中...")
    snapshots, course_source, base_skip = trio.load_snapshots(
        combined_start,
        combined_end,
    )

    print("スリット予測PIDと直前180日trio liftを構築中...")
    (
        slit_map,
        slit_stats,
        pattern_freq,
        method_freq,
        slit_build_skip,
        slit_build_methods,
    ) = build_rolling_slit_map(combined_start, combined_end)

    p1_races, p1_counts = build_joined_races(
        snapshots,
        p1["races"],
        slit_map,
        p1["start"],
        p1["end"],
    )
    p2_races, p2_counts = build_joined_races(
        snapshots,
        p2["races"],
        slit_map,
        p2["start"],
        p2["end"],
    )
    if not p1_races or not p2_races:
        raise RuntimeError("P1/P2のスリット結合後レースがありません")

    p1_rows = flatten(p1_races)

    base_beta, base_iter = fit_logistic(p1_rows, None)
    slit_betas = {}
    slit_iters = {}
    for method in SLIT_METHODS:
        beta, iteration = fit_logistic(p1_rows, method)
        slit_betas[method] = beta
        slit_iters[method] = iteration

    p1_metrics = {
        BASE_MODEL: evaluate(p1_races, base_beta, None)
    }
    for method in SLIT_METHODS:
        p1_metrics[method] = evaluate(
            p1_races,
            slit_betas[method],
            method,
        )

    selected = min(
        SLIT_METHODS,
        key=lambda m: (
            p1_metrics[m]["brier"],
            p1_metrics[m]["logloss"],
            p1_metrics[m]["ece"],
        ),
    )

    p2_metrics = {
        BASE_MODEL: evaluate(p2_races, base_beta, None)
    }
    for method in SLIT_METHODS:
        p2_metrics[method] = evaluate(
            p2_races,
            slit_betas[method],
            method,
        )

    p2_base = p2_metrics[BASE_MODEL]
    p2_sel = p2_metrics[selected]

    print("=" * 136)
    print("AI3連対率 STEP 5：基礎3連対率 + 一次 + 二次 + スリット 追加効果")
    print("=" * 136)
    print(f"学習CSV              : {p1['path']}")
    print(f"学習期間(P1)         : {p1['start']} ～ {p1['end']}")
    print(f"評価CSV              : {p2['path']}")
    print(f"評価期間(P2)         : {p2['start']} ～ {p2['end']}")
    print("基礎3連対率          : BB_MEDIUM RAW (Kpc=20, Kpvc=10)")
    print("一次特徴量            : first_total_score のレース内Z値")
    print("二次特徴量            : second_score のレース内Z値")
    print("スリットPID           : C_ST_RANK予測 / profile不足時は展示ST fallback")
    print("スリット特徴量        : 予測PID×展示進入コースtrio率 - 展示進入コースtrio率")
    print(f"スリット履歴          : 直前{SLIT_HISTORY_DAYS}日 / 当日結果は除外")
    print("スリット候補          : RAW / K20 / K40 / K80 / cap ±8pt")
    print("方式選択              : P1 Brier優先のみで選択")
    print("評価                  : P2完全ホールドアウト")
    print("300%強制正規化        : なし")
    print("本番Web変更           : なし")

    print("\n【評価母集団 / coverage】")
    print(
        f"P1: 基礎snapshot={p1_counts['base_snapshot']}R / "
        f"スリット評価可能={p1_counts['joined']}R / "
        f"スリット不足={p1_counts['slit_missing']}R / "
        f"CSV不足={p1_counts['csv_missing']}R / 結合不正={p1_counts['join_invalid']}R"
    )
    print(
        f"P2: 基礎snapshot={p2_counts['base_snapshot']}R / "
        f"スリット評価可能={p2_counts['joined']}R / "
        f"スリット不足={p2_counts['slit_missing']}R / "
        f"CSV不足={p2_counts['csv_missing']}R / 結合不正={p2_counts['join_invalid']}R"
    )
    print(
        f"スリットcoverage      : P1={p1_counts['joined'] / p1_counts['base_snapshot'] * 100:.2f}% / "
        f"P2={p2_counts['joined'] / p2_counts['base_snapshot'] * 100:.2f}%"
    )
    print(
        f"target prediction ready={slit_stats['target_prediction_ready']}R / "
        f"slit ready={slit_stats['target_slit_ready']}R / "
        f"nonzero={slit_stats['target_slit_nonzero']}R / "
        f"course map不足={slit_stats['target_course_map_incomplete']}R"
    )
    print(
        "履歴実コースsource    : "
        f"exhibition={course_source['exhibition']} / "
        f"lane_fallback={course_source['lane_fallback']} / "
        f"result={course_source['result']}"
    )
    print(
        "基礎snapshot skip     : "
        f"top3_result_invalid={base_skip['top3_result_invalid']} / "
        f"venue_actual_course_incomplete={base_skip['venue_actual_course_incomplete']}"
    )

    print("\n【対象期間スリット予測方式】")
    total_method = sum(method_freq.values())
    for method, n in method_freq.most_common():
        pct = n / total_method * 100 if total_method else 0.0
        print(f"{method:<20}: {n:>6}R ({pct:6.2f}%)")

    print("\n【対象期間PID件数】")
    print("PID 名称              件数")
    for pid in range(1, 13):
        print(f"{pid:>2}  {PATTERN_NAMES[pid]:<14} {pattern_freq[pid]:>6}")

    print("\n【P1で学習した係数】")
    print_coeff(BASE_MODEL, base_beta, base_iter)
    for method in SLIT_METHODS:
        print_coeff(method, slit_betas[method], slit_iters[method], method)

    secondary_step.print_metrics_table(
        "P1 学習期間内（スリット方式選択用）",
        p1_metrics,
    )

    print(
        f"\n【P1で選択したスリット方式】 {selected} / "
        f"Brier={p1_metrics[selected]['brier']:.6f} / "
        f"LogLoss={p1_metrics[selected]['logloss']:.6f} / "
        f"ECE={p1_metrics[selected]['ece'] * 100:.2f}pt"
    )

    secondary_step.print_metrics_table(
        "P2 ホールドアウト（最重要）",
        p2_metrics,
    )

    print(
        f"\n【最重要: P2 {BASE_MODEL} → +{selected} の追加効果】"
    )
    print(f"Brier差                : {p2_sel['brier'] - p2_base['brier']:+.6f}  （マイナスが改善）")
    print(f"LogLoss差              : {p2_sel['logloss'] - p2_base['logloss']:+.6f}  （マイナスが改善）")
    print(f"ECE差                  : {(p2_sel['ece'] - p2_base['ece']) * 100:+.2f}pt  （マイナスが改善）")
    print(f"平均Σ6差               : {(p2_sel['avg_sum6'] - p2_base['avg_sum6']) * 100:+.2f}pt")
    print(f"上位3艇捕捉率差        : {(p2_sel['top3_capture'] - p2_base['top3_capture']) * 100:+.2f}pt")
    print(f"Top3完全一致率差       : {(p2_sel['top3_exact'] - p2_base['top3_exact']) * 100:+.2f}pt")
    print(f"スリット係数           : {slit_betas[selected][4]:+.6f}")
    print("※ 正なら、基礎・一次・二次が同程度でもスリットtrio buffが高い艇ほど3連対しやすい方向。")

    print(f"\n【P2 {selected} スコア帯別 実3連対率】")
    print("スリット帯      舟数    平均score   実3連対率   平均PID×C N   平均C N")
    print("-" * 86)
    for label, n, avg_score, actual, avg_pat_n, avg_base_n in score_band_rows(p2_sel, selected):
        if n == 0:
            print(f"{label:<12}{0:>7}")
            continue
        print(
            f"{label:<12}{n:>7}{avg_score * 100:>12.2f}pt"
            f"{actual * 100:>12.2f}%{avg_pat_n:>14.1f}{avg_base_n:>12.1f}"
        )

    print_calibration(f"P2 +{selected} calibration", p2_sel)
    print_rank_table(f"P2 +{selected} 確率順位別 実3連対率", p2_sel)

    print("\n【判断方針】")
    print("1. スリット方式はP1だけで選び、P2を見て方式を選び直さない")
    print("2. P2でBrier/LogLossの両方が改善するか")
    print("3. ECEが大きく悪化しないか")
    print("4. 上位3艇捕捉率・Top3完全一致が維持または改善するか")
    print("5. スリット係数の符号とP2スコア帯別実3連対率が整合するか")
    print("6. 改善が小さい/不安定なら二次評価との重複としてAI3連対率へは入れない")
    print("=" * 136)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
