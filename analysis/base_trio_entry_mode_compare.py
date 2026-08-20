#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
AI3連対率 STEP 6: 枠番基準 vs 展示進入基準を比較する。

目的
----
現在のAI3連対率は、STEP1～3の検証条件に合わせて
「今回コース = 枠番」として基礎3連対率を作っている。
本スクリプトでは、今回コースだけを展示進入へ置き換えた場合に
AI3連対率が改善するかを時系列ホールドアウトで検証する。

比較
----
FRAME_MODE
    基礎3連対率の今回コース = 枠番

ENTRY_MODE
    基礎3連対率の今回コース = exhibition_live.entry_course

共通
----
- 基礎3連対率: BB_MEDIUM RAW (Kpc=20, Kpvc=10)
- 一次: first_total_score のレース内Z値
- 二次: second_score のレース内Z値
- P1で各モードを別々にロジスティック回帰学習
- P2は完全ホールドアウト
- 6艇300%への強制正規化なし
- 展示進入が1～6で完全にそろったレースだけを公平比較
- 対象レース結果を履歴へ入れる前に基礎率を作るため未来情報なし
- 過去実コースは result_detail -> exhibition_live -> lane の順で復元
- 選手履歴は直前100走
- P2は全体に加えて「展示進入が枠番から変わったレース」も別集計
- 本番Webは変更しない

Usage:
    python3 analysis/base_trio_entry_mode_compare.py \
      analysis/output/final_prediction_boats_20260615_20260714_OLD.csv \
      analysis/output/final_prediction_boats_20260715_20260814_OLD.csv
"""

from __future__ import annotations

import math
import sys
from collections import defaultdict, deque
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import base_trio_rate_compare as trio
import base_trio_primary_compare as primary_step
import base_trio_secondary_compare as secondary_step
from slit_validate_v2 import connect_db


K_PC = 20.0
K_PVC = 10.0
L2 = 1e-4

FRAME_MODE = "FRAME_MODE"
ENTRY_MODE = "ENTRY_MODE"


def top3_result_is_valid(rows):
    ranks = [trio.as_int(r["rank"]) for r in rows]
    return (
        ranks.count(1) == 1
        and ranks.count(2) == 1
        and ranks.count(3) == 1
    )


def beta_prob(p0, n_pc, w_pc, n_pvc, w_pvc):
    p_pc = (w_pc + K_PC * p0) / (n_pc + K_PC)
    return (w_pvc + K_PVC * p_pc) / (n_pvc + K_PVC)


def load_dual_mode_snapshots(eval_start, eval_end):
    """
    DBを時系列で1回走査し、同じレース・同じ履歴時点から
    FRAME_MODE / ENTRY_MODE の基礎3連対率を同時生成する。

    展示進入は対象レース前に判明する情報なので特徴量として利用可。
    対象レース結果はsnapshot作成後に履歴へ追加する。
    """
    venue_n = defaultdict(lambda: {c: 0 for c in range(1, 7)})
    venue_w = defaultdict(lambda: {c: 0 for c in range(1, 7)})
    global_n = {c: 0 for c in range(1, 7)}
    global_w = {c: 0 for c in range(1, 7)}
    player_hist = defaultdict(lambda: deque(maxlen=100))

    snapshots = []
    course_source = defaultdict(int)
    stats = defaultdict(int)

    sql = """
        SELECT
            rm.race_date,
            re.race_code,
            re.lane_number,
            re.player_id::text,
            rrd.rank,
            rrd.entry_course AS result_course,
            el.entry_course AS exhibition_course
        FROM boat_race.race_entry re
        JOIN boat_race.race_master rm
          ON rm.race_code = re.race_code
        LEFT JOIN boat_race.race_result_detail rrd
          ON rrd.race_code = re.race_code
         AND rrd.player_id = re.player_id
        LEFT JOIN LATERAL (
            SELECT x.entry_course
            FROM boat_race.exhibition_live x
            WHERE x.race_code = re.race_code
              AND x.player_id = re.player_id
            LIMIT 1
        ) el ON TRUE
        WHERE rm.race_date <= %s::date
        ORDER BY rm.race_date, re.race_code, re.lane_number
    """

    with connect_db() as conn:
        cur = conn.cursor(name="base_trio_entry_mode_stream")
        cur.itersize = 10000
        cur.execute(sql, (eval_end.isoformat(),))

        current_code = None
        current_date = None
        rows = []

        def process_race(race_date, race_code, race_rows):
            if not race_rows:
                return

            place_code = trio.place_of(race_code)
            lanes = [trio.valid_course(r["lane"]) for r in race_rows]
            lane_complete = (
                len(race_rows) == 6
                and all(c is not None for c in lanes)
                and sorted(lanes) == [1, 2, 3, 4, 5, 6]
            )
            top3_valid = top3_result_is_valid(race_rows)

            # 履歴更新用に実コースを先に復元するが、まだ統計へは入れない。
            prepared = []
            for r in race_rows:
                actual_c, source = trio.actual_course(
                    r["result_course"],
                    r["exhibition_course"],
                    r["lane"],
                )
                prepared.append(
                    {
                        "lane": trio.valid_course(r["lane"]),
                        "player_id": str(r["player_id"] or "").strip(),
                        "rank": trio.as_int(r["rank"]),
                        "result_course": trio.valid_course(r["result_course"]),
                        "exhibition_course": trio.valid_course(r["exhibition_course"]),
                        "actual_course": actual_c,
                        "actual_source": source,
                    }
                )

            # --------------------------------------------------------
            # 予測snapshot: 現在レースの結果を入れる前に作る
            # --------------------------------------------------------
            if eval_start <= race_date <= eval_end:
                stats["target_seen"] += 1

                if not lane_complete:
                    stats["target_lane_incomplete"] += 1
                if not top3_valid:
                    stats["target_top3_invalid"] += 1

                if lane_complete and top3_valid:
                    stats["target_base_candidate"] += 1

                    ex_courses = [r["exhibition_course"] for r in prepared]
                    entry_complete = (
                        len(prepared) == 6
                        and all(c is not None for c in ex_courses)
                        and sorted(ex_courses) == [1, 2, 3, 4, 5, 6]
                    )

                    if not entry_complete:
                        stats["target_entry_incomplete"] += 1
                    else:
                        boats = []
                        entry_changed = False

                        for r in prepared:
                            lane = r["lane"]
                            pid = r["player_id"]
                            entry_course = r["exhibition_course"]
                            if lane is None or entry_course is None or not pid:
                                continue

                            if lane != entry_course:
                                entry_changed = True

                            p0_frame = trio.prior_rate(
                                place_code,
                                lane,
                                venue_n,
                                venue_w,
                                global_n,
                                global_w,
                            )
                            f_n_pc, f_w_pc, f_n_pvc, f_w_pvc = trio.hist_counts(
                                player_hist[pid],
                                lane,
                                place_code,
                            )
                            p_frame = beta_prob(
                                p0_frame,
                                f_n_pc,
                                f_w_pc,
                                f_n_pvc,
                                f_w_pvc,
                            )

                            p0_entry = trio.prior_rate(
                                place_code,
                                entry_course,
                                venue_n,
                                venue_w,
                                global_n,
                                global_w,
                            )
                            e_n_pc, e_w_pc, e_n_pvc, e_w_pvc = trio.hist_counts(
                                player_hist[pid],
                                entry_course,
                                place_code,
                            )
                            p_entry = beta_prob(
                                p0_entry,
                                e_n_pc,
                                e_w_pc,
                                e_n_pvc,
                                e_w_pvc,
                            )

                            boats.append(
                                {
                                    "lane": lane,
                                    "entry_course": entry_course,
                                    "y": 1 if r["rank"] in (1, 2, 3) else 0,
                                    "base_frame": float(p_frame),
                                    "base_entry": float(p_entry),
                                    "p0_frame": float(p0_frame),
                                    "p0_entry": float(p0_entry),
                                }
                            )

                        if len(boats) == 6 and sum(b["y"] for b in boats) == 3:
                            boats.sort(key=lambda x: x["lane"])
                            snapshots.append(
                                {
                                    "race_code": str(race_code),
                                    "race_date": race_date,
                                    "entry_changed": bool(entry_changed),
                                    "boats": boats,
                                }
                            )
                            stats["target_entry_ready"] += 1
                            if entry_changed:
                                stats["target_entry_changed"] += 1
                        else:
                            stats["target_snapshot_invalid"] += 1

            # --------------------------------------------------------
            # 場×コースprior更新
            # --------------------------------------------------------
            actual_courses = [r["actual_course"] for r in prepared]
            actual_complete = (
                len(prepared) == 6
                and all(c is not None for c in actual_courses)
                and sorted(actual_courses) == [1, 2, 3, 4, 5, 6]
            )

            if lane_complete and top3_valid and actual_complete:
                for r in prepared:
                    c = r["actual_course"]
                    y = 1 if r["rank"] in (1, 2, 3) else 0
                    venue_n[place_code][c] += 1
                    venue_w[place_code][c] += y
                    global_n[c] += 1
                    global_w[c] += y
                stats["history_prior_updated"] += 1
            elif top3_valid and not actual_complete:
                stats["history_actual_course_incomplete"] += 1

            # --------------------------------------------------------
            # 選手直前100走更新
            # --------------------------------------------------------
            for r in prepared:
                pid = r["player_id"]
                c = r["actual_course"]
                if not pid or c is None:
                    stats["history_player_course_missing"] += 1
                    continue

                course_source[r["actual_source"]] += 1
                player_hist[pid].append(
                    {
                        "place": place_code,
                        "course": c,
                        "top3": 1 if r["rank"] in (1, 2, 3) else 0,
                    }
                )

        for (
            race_date,
            race_code,
            lane,
            player_id,
            rank,
            result_course,
            exhibition_course,
        ) in cur:
            race_code = str(race_code)

            if current_code is None:
                current_code = race_code
                current_date = race_date

            if race_code != current_code:
                process_race(current_date, current_code, rows)
                rows = []
                current_code = race_code
                current_date = race_date

            rows.append(
                {
                    "lane": lane,
                    "player_id": player_id,
                    "rank": rank,
                    "result_course": result_course,
                    "exhibition_course": exhibition_course,
                }
            )

        if current_code is not None:
            process_race(current_date, current_code, rows)

        cur.close()

    return snapshots, course_source, stats


def join_csv_features(snapshots, feature_map, start, end):
    races = []
    counts = defaultdict(int)

    for snap in snapshots:
        if snap["race_date"] < start or snap["race_date"] > end:
            continue

        counts["snapshot_races"] += 1
        features = feature_map.get(str(snap["race_code"]))
        if features is None:
            counts["csv_missing"] += 1
            continue

        boats = []
        for b in snap["boats"]:
            lane = int(b["lane"])
            feat = features.get(lane)
            if feat is None:
                continue

            boats.append(
                {
                    **b,
                    "primary_score": float(feat["primary_score"]),
                    "primary_z": float(feat["primary_z"]),
                    "secondary_score": float(feat["secondary_score"]),
                    "secondary_z": float(feat["secondary_z"]),
                }
            )

        if len(boats) != 6 or sum(b["y"] for b in boats) != 3:
            counts["join_invalid"] += 1
            continue

        boats.sort(key=lambda x: x["lane"])
        races.append(
            {
                "race_code": str(snap["race_code"]),
                "race_date": snap["race_date"],
                "entry_changed": bool(snap["entry_changed"]),
                "boats": boats,
            }
        )
        counts["joined"] += 1
        if snap["entry_changed"]:
            counts["entry_changed"] += 1

    return races, counts


def flatten(races):
    return [boat for race in races for boat in race["boats"]]


def base_key_for_mode(mode):
    if mode == FRAME_MODE:
        return "base_frame"
    if mode == ENTRY_MODE:
        return "base_entry"
    raise RuntimeError(f"unknown mode: {mode}")


def feature_vector(row, mode):
    base_key = base_key_for_mode(mode)
    return [
        1.0,
        primary_step.logit(float(row[base_key])),
        float(row["primary_z"]),
        float(row["secondary_z"]),
    ]


def fit_logistic(rows, mode, max_iter=60):
    dim = 4
    beta = [0.0] * dim
    beta[1] = 1.0

    for iteration in range(1, max_iter + 1):
        grad = [0.0] * dim
        hess = [[0.0] * dim for _ in range(dim)]

        for row in rows:
            x = feature_vector(row, mode)
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


def predict_row(row, mode, beta):
    x = feature_vector(row, mode)
    eta = sum(b * xi for b, xi in zip(beta, x))
    return primary_step.clip_prob(primary_step.sigmoid(eta))


def evaluate(races, mode, beta):
    if not races:
        return None

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
            int(b["lane"])
            for b in race["boats"]
            if int(b["y"]) == 1
        }

        for b in race["boats"]:
            p = predict_row(b, mode, beta)
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

    cal_table, ece = secondary_step.calibration(boat_rows)

    return {
        "races": len(races),
        "boats": boat_n,
        "brier": brier / boat_n,
        "logloss": logloss / boat_n,
        "ece": ece,
        "avg_sum6": sum6 / len(races),
        "top3_capture": captured / (len(races) * 3),
        "top3_exact": exact / len(races),
        "calibration": cal_table,
        "boat_rows": boat_rows,
    }


def evaluate_base_only(races, base_key):
    if not races:
        return None

    brier = 0.0
    logloss = 0.0
    boat_n = 0
    sum6 = 0.0
    captured = 0
    exact = 0

    for race in races:
        predicted = []
        actual_top3 = {
            int(b["lane"])
            for b in race["boats"]
            if int(b["y"]) == 1
        }

        for b in race["boats"]:
            p = float(b[base_key])
            y = int(b["y"])
            cp = primary_step.clip_prob(p)
            brier += (p - y) ** 2
            logloss += -(
                y * math.log(cp)
                + (1 - y) * math.log(1.0 - cp)
            )
            boat_n += 1
            predicted.append((p, int(b["lane"]), y))

        sum6 += sum(x[0] for x in predicted)
        top3_pred = sorted(predicted, key=lambda x: (-x[0], x[1]))[:3]
        predicted_set = {x[1] for x in top3_pred}
        captured += len(predicted_set & actual_top3)
        if predicted_set == actual_top3:
            exact += 1

    return {
        "races": len(races),
        "brier": brier / boat_n,
        "logloss": logloss / boat_n,
        "avg_sum6": sum6 / len(races),
        "top3_capture": captured / (len(races) * 3),
        "top3_exact": exact / len(races),
    }


def changed_only(races):
    return [r for r in races if r["entry_changed"]]


def print_metrics(title, frame, entry, include_ece=True):
    print(f"\n【{title}】")
    if include_ece:
        print(
            "方式                 R数     Brier     LogLoss      ECE"
            "    平均Σ6   上位3艇捕捉  Top3完全一致"
        )
        print("-" * 112)
        for name, m in ((FRAME_MODE, frame), (ENTRY_MODE, entry)):
            if m is None:
                continue
            print(
                f"{name:<20}"
                f"{m['races']:>6d}"
                f"   {m['brier']:.6f}"
                f"   {m['logloss']:.6f}"
                f"   {m['ece'] * 100:6.2f}pt"
                f"   {m['avg_sum6'] * 100:7.2f}%"
                f"      {m['top3_capture'] * 100:6.2f}%"
                f"         {m['top3_exact'] * 100:6.2f}%"
            )
    else:
        print(
            "方式                 R数     Brier     LogLoss"
            "    平均Σ6   上位3艇捕捉  Top3完全一致"
        )
        print("-" * 98)
        for name, m in ((FRAME_MODE, frame), (ENTRY_MODE, entry)):
            if m is None:
                continue
            print(
                f"{name:<20}"
                f"{m['races']:>6d}"
                f"   {m['brier']:.6f}"
                f"   {m['logloss']:.6f}"
                f"   {m['avg_sum6'] * 100:7.2f}%"
                f"      {m['top3_capture'] * 100:6.2f}%"
                f"         {m['top3_exact'] * 100:6.2f}%"
            )


def print_delta(title, frame, entry):
    print(f"\n【{title}】")
    if frame is None or entry is None:
        print("比較対象なし")
        return

    print(
        f"Brier差              : {entry['brier'] - frame['brier']:+.6f}"
        "  （マイナスがENTRY改善）"
    )
    print(
        f"LogLoss差            : {entry['logloss'] - frame['logloss']:+.6f}"
        "  （マイナスがENTRY改善）"
    )
    if "ece" in frame and "ece" in entry:
        print(
            f"ECE差                : {(entry['ece'] - frame['ece']) * 100:+.2f}pt"
            "  （マイナスがENTRY改善）"
        )
    print(
        f"平均Σ6差             : {(entry['avg_sum6'] - frame['avg_sum6']) * 100:+.2f}pt"
    )
    print(
        f"上位3艇捕捉率差      : {(entry['top3_capture'] - frame['top3_capture']) * 100:+.2f}pt"
    )
    print(
        f"Top3完全一致率差     : {(entry['top3_exact'] - frame['top3_exact']) * 100:+.2f}pt"
    )


def print_calibration(metrics, label):
    if metrics is None:
        return

    print(f"\n【P2 {label} calibration】")
    print(
        "確率帯       舟数   実Top3   平均予測"
        "    実3連対率    実績-予測"
    )
    print("-" * 78)

    for low, high, n, hits, pred, actual, gap in metrics["calibration"]:
        band = f"{int(low * 100):>2d}-{int(min(high, 1.0) * 100):<3d}%"
        if n == 0:
            print(f"{band:<10}{0:>7d}")
            continue
        print(
            f"{band:<10}"
            f"{n:>7d}"
            f"{hits:>9d}"
            f"{pred * 100:>11.2f}%"
            f"{actual * 100:>12.2f}%"
            f"{gap * 100:>+12.2f}pt"
        )
    print(f"ECE: {metrics['ece'] * 100:.3f}pt")


def main():
    if len(sys.argv) != 3:
        print(
            "Usage: python3 analysis/base_trio_entry_mode_compare.py "
            "P1_BOATS_CSV P2_BOATS_CSV"
        )
        return 2

    p1_csv_path = sys.argv[1]
    p2_csv_path = sys.argv[2]

    p1 = secondary_step.load_feature_csv(p1_csv_path)
    p2 = secondary_step.load_feature_csv(p2_csv_path)

    if p1["end"] >= p2["start"]:
        raise RuntimeError("P1終了日 < P2開始日にしてください")

    combined_start = p1["start"]
    combined_end = p2["end"]

    print("DBから枠番基準/展示進入基準の基礎3連対率snapshotを同時構築中...")
    snapshots, course_source, stats = load_dual_mode_snapshots(
        combined_start,
        combined_end,
    )

    p1_races, p1_counts = join_csv_features(
        snapshots,
        p1["races"],
        p1["start"],
        p1["end"],
    )
    p2_races, p2_counts = join_csv_features(
        snapshots,
        p2["races"],
        p2["start"],
        p2["end"],
    )

    if not p1_races or not p2_races:
        raise RuntimeError("P1/P2の比較対象レースがありません")

    p1_rows = flatten(p1_races)

    beta_frame, iter_frame = fit_logistic(p1_rows, FRAME_MODE)
    beta_entry, iter_entry = fit_logistic(p1_rows, ENTRY_MODE)

    p1_frame = evaluate(p1_races, FRAME_MODE, beta_frame)
    p1_entry = evaluate(p1_races, ENTRY_MODE, beta_entry)
    p2_frame = evaluate(p2_races, FRAME_MODE, beta_frame)
    p2_entry = evaluate(p2_races, ENTRY_MODE, beta_entry)

    p2_changed = changed_only(p2_races)
    p2_changed_frame = evaluate(p2_changed, FRAME_MODE, beta_frame)
    p2_changed_entry = evaluate(p2_changed, ENTRY_MODE, beta_entry)

    p2_base_frame = evaluate_base_only(p2_races, "base_frame")
    p2_base_entry = evaluate_base_only(p2_races, "base_entry")
    p2_changed_base_frame = evaluate_base_only(p2_changed, "base_frame")
    p2_changed_base_entry = evaluate_base_only(p2_changed, "base_entry")

    sep = "=" * 136
    print(sep)
    print("AI3連対率 STEP 6：枠番基準 vs 展示進入基準")
    print(sep)
    print(f"学習CSV              : {p1_csv_path}")
    print(f"学習期間(P1)         : {p1['start']} ～ {p1['end']}")
    print(f"評価CSV              : {p2_csv_path}")
    print(f"評価期間(P2)         : {p2['start']} ～ {p2['end']}")
    print("基礎3連対率          : BB_MEDIUM RAW (Kpc=20, Kpvc=10)")
    print("FRAME_MODE            : 今回コース=枠番")
    print("ENTRY_MODE            : 今回コース=展示進入")
    print("一次特徴量            : first_total_score のレース内Z値")
    print("二次特徴量            : second_score のレース内Z値")
    print("学習                  : 各モードをP1で別学習")
    print("評価                  : P2完全ホールドアウト")
    print("比較母集団            : 展示進入が6艇完全な同一レース")
    print("300%強制正規化        : なし")
    print("本番Web変更           : なし")

    print("\n【評価母集団 / coverage】")
    p1_base = p1_counts["snapshot_races"] + stats.get("target_entry_incomplete", 0)
    print(
        f"P1: 比較対象={p1_counts['joined']}R / "
        f"進入変更={p1_counts['entry_changed']}R "
        f"({p1_counts['entry_changed'] / p1_counts['joined'] * 100:.2f}%) / "
        f"CSV不足={p1_counts['csv_missing']}R / 結合不正={p1_counts['join_invalid']}R"
    )
    print(
        f"P2: 比較対象={p2_counts['joined']}R / "
        f"進入変更={p2_counts['entry_changed']}R "
        f"({p2_counts['entry_changed'] / p2_counts['joined'] * 100:.2f}%) / "
        f"CSV不足={p2_counts['csv_missing']}R / 結合不正={p2_counts['join_invalid']}R"
    )
    print(
        f"対象期間: 基礎候補={stats['target_base_candidate']}R / "
        f"展示進入不足={stats['target_entry_incomplete']}R / "
        f"展示進入ready={stats['target_entry_ready']}R / "
        f"進入変更={stats['target_entry_changed']}R"
    )
    print(
        "履歴実コースsource    : "
        + " / ".join(
            f"{k}={course_source[k]}"
            for k in ("result", "exhibition", "lane_fallback")
        )
    )
    print(
        f"履歴prior更新         : {stats['history_prior_updated']}R / "
        f"実コース不完全={stats['history_actual_course_incomplete']}R"
    )

    print("\n【P1で学習した係数】")
    print(
        "FRAME_MODE : "
        f"intercept={beta_frame[0]:+.6f} / "
        f"base_logit={beta_frame[1]:+.6f} / "
        f"primary_z={beta_frame[2]:+.6f} / "
        f"secondary_z={beta_frame[3]:+.6f} (iter={iter_frame})"
    )
    print(
        "ENTRY_MODE : "
        f"intercept={beta_entry[0]:+.6f} / "
        f"base_logit={beta_entry[1]:+.6f} / "
        f"primary_z={beta_entry[2]:+.6f} / "
        f"secondary_z={beta_entry[3]:+.6f} (iter={iter_entry})"
    )

    print_metrics("P1 学習期間内（参考）", p1_frame, p1_entry)
    print_metrics("P2 ホールドアウト（最重要）", p2_frame, p2_entry)
    print_delta("最重要: P2 FRAME_MODE → ENTRY_MODE", p2_frame, p2_entry)

    print_metrics(
        "P2 基礎3連対率だけの比較（参考）",
        p2_base_frame,
        p2_base_entry,
        include_ece=False,
    )
    print_delta(
        "P2 基礎率だけ FRAME → ENTRY（参考）",
        p2_base_frame,
        p2_base_entry,
    )

    print_metrics(
        "P2 進入変更ありレースだけ（最重要サブセット）",
        p2_changed_frame,
        p2_changed_entry,
    )
    print_delta(
        "P2 進入変更あり FRAME_MODE → ENTRY_MODE",
        p2_changed_frame,
        p2_changed_entry,
    )

    print_metrics(
        "P2 進入変更あり・基礎率だけ（参考）",
        p2_changed_base_frame,
        p2_changed_base_entry,
        include_ece=False,
    )

    print_calibration(p2_entry, ENTRY_MODE)

    print("\n【判断方針】")
    print("1. 最終判断はP2全体のFRAME vs ENTRYで行う")
    print("2. Brier/LogLossが両方改善し、ECEが大きく悪化しないか")
    print("3. 上位3艇捕捉率・Top3完全一致が維持または改善するか")
    print("4. 進入変更ありレースだけでも同じ方向に改善するかを重視する")
    print("5. ENTRYが有利なら、そのP1学習係数で本番AI3連対率を進入対応へ更新する")
    print("6. ENTRYが不安定なら現行FRAME_MODEを維持し、出目確率へ進む")
    print(sep)

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
