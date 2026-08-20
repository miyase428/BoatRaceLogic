#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
出目確率 STEP 2：基礎出目確率へ補正後1着率とAI3連対率を追加する。

目的
----
STEP1で採用した VENUE_K3000 の120通り基礎出目確率へ、
現在採用済みの
  - 補正後1着率
  - ENTRY_MODE AI3連対率
を追加したとき、P2完全ホールドアウトで出目確率が改善するか検証する。

二重計上を避ける補正
--------------------
基礎120出目から各艇の周辺確率を逆算する。
  q_win[i]  = 基礎120出目から見た艇iの1着確率
  q_trio[i] = 基礎120出目から見た艇iの3連対確率

現在モデルとの差を比率として使う。
  r_win[i]  = corrected_win[i] / q_win[i]
  r_trio[i] = ai_trio[i]      / q_trio[i]

出目 (1着=i, 2着=j, 3着=k) を

  score = p_base
          * r_win[i]^alpha
          * r_trio[j]^beta
          * r_trio[k]^beta

で再重み付けし、最後に120通り100%へ正規化する。

※1着艇のtrio比は入れない。1着率と3連対率の二重計上を弱めるため。
※2着/3着の順序差はSTEP1の場×コース出目分布に任せる。

比較
----
BASE
WIN_ONLY
TRIO_ONLY
WIN_PLUS_TRIO

alpha / beta はP1だけでグリッド選択し、P2では固定する。

補正後1着率
-----------
採用済み構成を過去検証用に再現する。
- 展示進入リマップ
- EX_TOTAL beta=0.10
- SUM_RAW gamma=2.0
- スリット win buff: K=40 / cap ±0.08 / alpha=0.25
- P1/P2それぞれ評価開始日前180日でbuffを学習し、評価期間中は固定
  （未来情報なし。既存固定alphaホールドアウト検証と同じ考え方）

AI3連対率
---------
ENTRY_MODE固定係数:
  intercept   = +0.033713
  base_logit  = +0.828225
  primary_z   = +0.433483
  secondary_z = +0.286814

Usage
-----
python3 analysis/trifecta_probability_ai_compare.py \
  analysis/output/final_prediction_boats_20260615_20260714_OLD.csv \
  analysis/output/final_prediction_boats_20260715_20260814_OLD.csv
"""

from __future__ import annotations

import math
import sys
from collections import defaultdict
from datetime import timedelta
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import base_trifecta_probability_compare as base_outcome
import base_trio_entry_mode_compare as trio_entry
import base_trio_secondary_compare as trio_secondary
import base_winrate_sum_compare as win_sum
import base_winrate_slit_compare as win_slit
from slit_validate_v2 import connect_db


VENUE_K = 3000.0
SLIT_ALPHA = 0.25
ENTRY_BETA = [0.033713, 0.828225, 0.433483, 0.286814]
GRID = (0.0, 0.25, 0.50, 0.75, 1.00, 1.25, 1.50, 2.00)
EPS = 1e-12


def period_of(d, p1_start, p1_end, p2_start, p2_end):
    if p1_start <= d <= p1_end:
        return "P1"
    if p2_start <= d <= p2_end:
        return "P2"
    return None


def load_base_outcomes(p1_start, p1_end, p2_start, p2_end):
    """STEP1 VENUE_K3000を同じ時系列定義で再構築し、レース別確率を返す。"""
    M = base_outcome.M
    global_counts = [0] * M
    global_n = 0
    venue_counts = defaultdict(lambda: [0] * M)
    venue_n = defaultdict(int)
    out = {}
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
        cur = conn.cursor(name="trifecta_ai_base_stream")
        cur.itersize = 10000
        cur.execute(sql, (p2_end.isoformat(),))

        current_code = None
        current_date = None
        rows = []

        def process_race(race_date, race_code, race_rows):
            nonlocal global_n
            if not race_rows:
                return

            place = base_outcome.place_of(race_code)
            prepared = []
            lanes = []
            rank_to_lane = {}
            rank_counts = defaultdict(int)

            for raw in race_rows:
                lane = base_outcome.valid_course(raw["lane"])
                rank = base_outcome.rank_int(raw["rank"])
                ex_course = base_outcome.valid_course(raw["exhibition_course"])
                ac, _ = base_outcome.actual_course(
                    raw["result_course"], raw["exhibition_course"], raw["lane"]
                )
                prepared.append({
                    "lane": lane,
                    "rank": rank,
                    "ex_course": ex_course,
                    "actual_course": ac,
                })
                if lane is not None:
                    lanes.append(lane)
                if rank is not None:
                    rank_counts[rank] += 1
                    if lane is not None:
                        rank_to_lane[rank] = lane

            lane_complete = len(prepared) == 6 and sorted(lanes) == [1, 2, 3, 4, 5, 6]
            top3_valid = all(rank_counts[r] == 1 for r in (1, 2, 3))
            by_lane = {r["lane"]: r for r in prepared if r["lane"] is not None}

            period = period_of(race_date, p1_start, p1_end, p2_start, p2_end)
            if period is not None:
                stats[f"{period}_seen"] += 1
                ex_courses = [r["ex_course"] for r in prepared]
                entry_complete = (
                    lane_complete
                    and all(c is not None for c in ex_courses)
                    and sorted(ex_courses) == [1, 2, 3, 4, 5, 6]
                )
                if lane_complete and top3_valid and entry_complete and global_n > 0:
                    actual_pattern = tuple(
                        by_lane[rank_to_lane[r]]["ex_course"] for r in (1, 2, 3)
                    )
                    actual_idx = base_outcome.PATTERN_INDEX.get(actual_pattern)
                    if actual_idx is not None:
                        probs = base_outcome.method_probs(
                            "VENUE_K3000",
                            VENUE_K,
                            global_counts,
                            global_n,
                            venue_counts[place],
                            venue_n[place],
                        )
                        course_by_lane = {
                            int(r["lane"]): int(r["ex_course"])
                            for r in prepared
                        }
                        out[str(race_code)] = {
                            "race_code": str(race_code),
                            "race_date": race_date,
                            "period": period,
                            "probs": probs,
                            "actual_idx": actual_idx,
                            "course_by_lane": course_by_lane,
                        }
                        stats[f"{period}_ready"] += 1
                    else:
                        stats[f"{period}_label_invalid"] += 1
                else:
                    stats[f"{period}_not_ready"] += 1

            # 現在レースを予測後に履歴へ追加。
            if lane_complete and top3_valid:
                actual_courses = []
                ok = True
                for rank_no in (1, 2, 3):
                    lane = rank_to_lane.get(rank_no)
                    row = by_lane.get(lane)
                    c = row["actual_course"] if row else None
                    if c is None:
                        ok = False
                        break
                    actual_courses.append(c)
                pattern = tuple(actual_courses) if ok else None
                idx = base_outcome.PATTERN_INDEX.get(pattern) if pattern is not None else None
                if idx is not None and len(set(actual_courses)) == 3:
                    global_counts[idx] += 1
                    global_n += 1
                    venue_counts[place][idx] += 1
                    venue_n[place] += 1

        for row in cur:
            race_date, race_code, lane, player_id, rank, result_course, exhibition_course = row
            code = str(race_code)
            if current_code is None:
                current_code = code
                current_date = race_date
            if code != current_code:
                process_race(current_date, current_code, rows)
                rows = []
                current_code = code
                current_date = race_date
            rows.append({
                "lane": lane,
                "player_id": player_id,
                "rank": rank,
                "result_course": result_course,
                "exhibition_course": exhibition_course,
            })

        if rows:
            process_race(current_date, current_code, rows)
        cur.close()

    return out, stats


def load_ai_trio_map(p1_csv, p2_csv, p1_start, p2_end):
    p1 = trio_secondary.load_feature_csv(p1_csv)
    p2 = trio_secondary.load_feature_csv(p2_csv)
    features = dict(p1["races"])
    features.update(p2["races"])

    snapshots, course_source, snap_stats = trio_entry.load_dual_mode_snapshots(
        p1_start, p2_end
    )
    joined, join_counts = trio_entry.join_csv_features(
        snapshots, features, p1_start, p2_end
    )

    out = {}
    for race in joined:
        probs = {}
        for b in race["boats"]:
            lane = int(b["lane"])
            probs[lane] = trio_entry.predict_row(
                b, trio_entry.ENTRY_MODE, ENTRY_BETA
            )
        if set(probs) == set(range(1, 7)):
            out[str(race["race_code"])] = probs

    return out, p1, p2, course_source, snap_stats, join_counts


def load_corrected_win_map(p1_start, p1_end, p2_start, p2_end):
    """採用済み補正後1着率を固定期間ホールドアウト定義で作る。"""
    snapshots, sum_skip, course_source = win_sum.load_snapshots(p1_start, p2_end)

    p1_buff_end = p1_start - timedelta(days=1)
    p1_buff_start = win_slit.inclusive_window_start(
        p1_buff_end, win_slit.SLIT_BUFF_DAYS
    )
    p2_buff_end = p2_start - timedelta(days=1)
    p2_buff_start = win_slit.inclusive_window_start(
        p2_buff_end, win_slit.SLIT_BUFF_DAYS
    )
    record_start = min(p1_buff_start, p2_buff_start)

    records, slit_skip, methods = win_slit.build_slit_records(record_start, p2_end)
    p1_buff, p1_rows, _ = win_slit.learn_buff(records, p1_buff_start, p1_buff_end)
    p2_buff, p2_rows, _ = win_slit.learn_buff(records, p2_buff_start, p2_buff_end)
    course_map = win_slit.load_lane_to_ex_course(p1_start, p2_end)

    out = {}
    stats = defaultdict(int)
    for snap in snapshots:
        period = period_of(snap.race_date, p1_start, p1_end, p2_start, p2_end)
        if period is None:
            continue
        stats[f"{period}_snapshot"] += 1

        rec = records.get(str(snap.race_code))
        cmap = course_map.get(str(snap.race_code), {})
        if rec is None:
            stats[f"{period}_slit_missing"] += 1
            continue
        if set(cmap) != set(range(1, 7)) or set(cmap.values()) != set(range(1, 7)):
            stats[f"{period}_course_missing"] += 1
            continue

        sum_probs = win_slit.sum_corrected_probs(snap)
        if sum_probs is None:
            stats[f"{period}_sum_failed"] += 1
            continue

        buff = p1_buff if period == "P1" else p2_buff
        boats = sorted(snap.boats, key=lambda b: b.lane)
        final_probs = win_slit.apply_slit_buff(
            sum_probs,
            boats,
            rec["pid"],
            cmap,
            buff,
            SLIT_ALPHA,
        )
        if final_probs is None:
            stats[f"{period}_slit_failed"] += 1
            continue

        probs = {int(b.lane): float(p) for b, p in zip(boats, final_probs)}
        if set(probs) == set(range(1, 7)):
            out[str(snap.race_code)] = probs
            stats[f"{period}_ready"] += 1

    extra = {
        "p1_buff_start": p1_buff_start,
        "p1_buff_end": p1_buff_end,
        "p1_buff_races": len(p1_rows),
        "p2_buff_start": p2_buff_start,
        "p2_buff_end": p2_buff_end,
        "p2_buff_races": len(p2_rows),
        "sum_skip": sum_skip,
        "slit_skip": slit_skip,
        "methods": methods,
        "course_source": course_source,
    }
    return out, stats, extra


def build_records(base_map, win_map, trio_map):
    records = {"P1": [], "P2": []}
    miss = defaultdict(int)

    for code, base in base_map.items():
        period = base["period"]
        win = win_map.get(code)
        trio = trio_map.get(code)
        if win is None:
            miss[f"{period}_win_missing"] += 1
            continue
        if trio is None:
            miss[f"{period}_trio_missing"] += 1
            continue

        course_by_lane = base["course_by_lane"]
        if set(course_by_lane) != set(range(1, 7)):
            miss[f"{period}_course_invalid"] += 1
            continue
        lane_by_course = {c: lane for lane, c in course_by_lane.items()}
        if set(lane_by_course) != set(range(1, 7)):
            miss[f"{period}_course_invalid"] += 1
            continue

        q_win = {lane: 0.0 for lane in range(1, 7)}
        q_trio = {lane: 0.0 for lane in range(1, 7)}
        pattern_lanes = []

        for idx, pattern in enumerate(base_outcome.PATTERNS):
            p = float(base["probs"][idx])
            lanes = tuple(lane_by_course[c] for c in pattern)
            pattern_lanes.append(lanes)
            q_win[lanes[0]] += p
            q_trio[lanes[0]] += p
            q_trio[lanes[1]] += p
            q_trio[lanes[2]] += p

        log_win_ratio = {}
        log_trio_ratio = {}
        for lane in range(1, 7):
            qw = max(q_win[lane], EPS)
            qt = max(q_trio[lane], EPS)
            pw = max(float(win[lane]), EPS)
            pt = max(float(trio[lane]), EPS)
            log_win_ratio[lane] = math.log(pw / qw)
            log_trio_ratio[lane] = math.log(pt / qt)

        records[period].append({
            **base,
            "pattern_lanes": pattern_lanes,
            "log_win_ratio": log_win_ratio,
            "log_trio_ratio": log_trio_ratio,
            "win_sum": sum(float(win[l]) for l in range(1, 7)),
            "trio_sum": sum(float(trio[l]) for l in range(1, 7)),
        })
        miss[f"{period}_ready"] += 1

    return records, miss


def adjusted_probs(record, alpha, beta):
    logs = []
    for idx, lanes in enumerate(record["pattern_lanes"]):
        i, j, k = lanes
        base_p = max(float(record["probs"][idx]), EPS)
        value = (
            math.log(base_p)
            + alpha * record["log_win_ratio"][i]
            + beta * record["log_trio_ratio"][j]
            + beta * record["log_trio_ratio"][k]
        )
        logs.append(value)

    mx = max(logs)
    weights = [math.exp(v - mx) for v in logs]
    total = sum(weights)
    if total <= 0:
        return list(record["probs"])
    return [w / total for w in weights]


def evaluate(records, alpha, beta):
    metric = base_outcome.Metrics()
    for r in records:
        metric.add(adjusted_probs(r, alpha, beta), int(r["actual_idx"]))
    return metric


def tune(records):
    table = []
    for alpha in GRID:
        for beta in GRID:
            m = evaluate(records, alpha, beta)
            s = m.summary()
            key = (
                s["logloss"],
                s["brier"],
                abs(alpha) + abs(beta),
                alpha,
                beta,
            )
            table.append((key, alpha, beta, m))

    best_all = min(table, key=lambda x: x[0])
    best_win = min((x for x in table if x[2] == 0.0), key=lambda x: x[0])
    best_trio = min((x for x in table if x[1] == 0.0), key=lambda x: x[0])
    return {
        "BASE": (0.0, 0.0),
        "WIN_ONLY": (best_win[1], best_win[2]),
        "TRIO_ONLY": (best_trio[1], best_trio[2]),
        "WIN_PLUS_TRIO": (best_all[1], best_all[2]),
    }


def print_metrics(title, rows):
    print(f"\n【{title}】")
    print(
        "方式                 alpha beta    R数   LogLoss   Brier120  正解平均P  "
        "Top1   Top3   Top5   Top10  Top20  平均順位  中央順位"
    )
    print("-" * 145)
    for name, alpha, beta, metric in rows:
        s = metric.summary()
        if s is None:
            continue
        print(
            f"{name:<21} {alpha:>4.2f}  {beta:>4.2f}  {s['races']:>5d}  "
            f"{s['logloss']:.6f}  {s['brier']:.6f}  {s['actual_prob']*100:>7.3f}%  "
            f"{s['top'][1]*100:>5.2f}%  {s['top'][3]*100:>5.2f}%  "
            f"{s['top'][5]*100:>5.2f}%  {s['top'][10]*100:>5.2f}%  "
            f"{s['top'][20]*100:>5.2f}%  {s['mean_rank']:>7.2f}  {s['median_rank']:>7.1f}"
        )


def print_delta(base_m, new_m):
    b = base_m.summary()
    n = new_m.summary()
    print(f"LogLoss差       : {n['logloss'] - b['logloss']:+.6f} （マイナスが改善）")
    print(f"Brier120差      : {n['brier'] - b['brier']:+.6f} （マイナスが改善）")
    print(f"正解平均P差     : {(n['actual_prob'] - b['actual_prob'])*100:+.3f}pt")
    for topn in base_outcome.TOP_NS:
        print(
            f"Top{topn:<2}的中率差   : "
            f"{(n['top'][topn] - b['top'][topn])*100:+.2f}pt"
        )
    print(f"平均順位差       : {n['mean_rank'] - b['mean_rank']:+.2f} （マイナスが改善）")
    print(f"120通り合計誤差max: {n['max_sum_error']:.3e}")


def main():
    if len(sys.argv) != 3:
        print(
            "Usage: python3 analysis/trifecta_probability_ai_compare.py "
            "P1_BOATS_CSV P2_BOATS_CSV"
        )
        sys.exit(1)

    p1_csv, p2_csv = sys.argv[1], sys.argv[2]
    p1_meta = trio_secondary.load_feature_csv(p1_csv)
    p2_meta = trio_secondary.load_feature_csv(p2_csv)
    p1_start, p1_end = p1_meta["start"], p1_meta["end"]
    p2_start, p2_end = p2_meta["start"], p2_meta["end"]

    print("基礎出目・補正後1着率・AI3連対率を構築中...（DB走査が複数回あるため時間がかかる場合があります）")

    base_map, base_stats = load_base_outcomes(p1_start, p1_end, p2_start, p2_end)
    win_map, win_stats, win_extra = load_corrected_win_map(
        p1_start, p1_end, p2_start, p2_end
    )
    trio_map, _, _, trio_course_source, trio_snap_stats, trio_join = load_ai_trio_map(
        p1_csv, p2_csv, p1_start, p2_end
    )

    records, miss = build_records(base_map, win_map, trio_map)
    if not records["P1"]:
        raise RuntimeError("P1共通評価レースが0件です")
    if not records["P2"]:
        raise RuntimeError("P2共通評価レースが0件です")

    selected = tune(records["P1"])

    p1_rows = []
    p2_rows = []
    for name in ("BASE", "WIN_ONLY", "TRIO_ONLY", "WIN_PLUS_TRIO"):
        alpha, beta = selected[name]
        p1_rows.append((name, alpha, beta, evaluate(records["P1"], alpha, beta)))
        p2_rows.append((name, alpha, beta, evaluate(records["P2"], alpha, beta)))

    p1_win_sums = [r["win_sum"] for r in records["P1"]]
    p2_win_sums = [r["win_sum"] for r in records["P2"]]
    p1_trio_sums = [r["trio_sum"] for r in records["P1"]]
    p2_trio_sums = [r["trio_sum"] for r in records["P2"]]

    print("=" * 146)
    print("出目確率 STEP 2：VENUE_K3000 + 補正後1着率 + AI3連対率")
    print("=" * 146)
    print(f"P1                  : {p1_start} ～ {p1_end}")
    print(f"P2完全ホールドアウト: {p2_start} ～ {p2_end}")
    print("基礎出目            : VENUE_K3000 / 120通り100%")
    print("1着補正             : 補正後1着率 / 基礎出目の1着周辺率との比")
    print("3連対補正           : ENTRY_MODE AI3連対率 / 基礎出目の3連対周辺率との比")
    print("出目式               : BASE × win_ratio^alpha × trio_ratio(2着)^beta × trio_ratio(3着)^beta")
    print(f"alpha/beta候補       : {', '.join(f'{x:.2f}' for x in GRID)}")
    print("方式選択             : P1 Multiclass LogLoss優先 / P2では再調整なし")
    print("本番Web変更          : なし")

    print("\n【共通評価母集団 / coverage】")
    for period in ("P1", "P2"):
        print(
            f"{period}: 基礎出目ready={base_stats.get(period + '_ready', 0)}R"
            f" / 補正後1着ready={win_stats.get(period + '_ready', 0)}R"
            f" / 共通評価={miss.get(period + '_ready', 0)}R"
            f" / win不足={miss.get(period + '_win_missing', 0)}R"
            f" / trio不足={miss.get(period + '_trio_missing', 0)}R"
        )
    print(
        f"1着率slit buff P1    : {win_extra['p1_buff_start']} ～ {win_extra['p1_buff_end']}"
        f" / {win_extra['p1_buff_races']}R"
    )
    print(
        f"1着率slit buff P2    : {win_extra['p2_buff_start']} ～ {win_extra['p2_buff_end']}"
        f" / {win_extra['p2_buff_races']}R"
    )
    print(
        f"平均補正後1着率Σ6    : P1={sum(p1_win_sums)/len(p1_win_sums)*100:.2f}%"
        f" / P2={sum(p2_win_sums)/len(p2_win_sums)*100:.2f}%"
    )
    print(
        f"平均AI3連対率Σ6      : P1={sum(p1_trio_sums)/len(p1_trio_sums)*100:.2f}%"
        f" / P2={sum(p2_trio_sums)/len(p2_trio_sums)*100:.2f}%"
    )

    print("\n【P1で選択したalpha / beta】")
    for name in ("WIN_ONLY", "TRIO_ONLY", "WIN_PLUS_TRIO"):
        a, b = selected[name]
        print(f"{name:<20}: alpha={a:.2f} / beta={b:.2f}")

    print_metrics("P1 方式選択用", p1_rows)
    print_metrics("P2 ホールドアウト（最重要）", p2_rows)

    p2_dict = {name: m for name, a, b, m in p2_rows}
    print("\n【最重要: P2 BASE → WIN_PLUS_TRIO】")
    print_delta(p2_dict["BASE"], p2_dict["WIN_PLUS_TRIO"])

    print("\n【P2 WIN_ONLY / TRIO_ONLY の追加価値を見る】")
    print("WIN_ONLY:")
    print_delta(p2_dict["BASE"], p2_dict["WIN_ONLY"])
    print("TRIO_ONLY:")
    print_delta(p2_dict["BASE"], p2_dict["TRIO_ONLY"])

    best_a, best_b = selected["WIN_PLUS_TRIO"]
    best_p2 = evaluate(records["P2"], best_a, best_b)
    base_outcome.print_calibration("P2 WIN_PLUS_TRIO", best_p2)

    print("\n【判断方針】")
    print("1. alpha/betaはP1だけで選び、P2を見て選び直さない")
    print("2. P2でLogLoss/Brier120の両方が改善するか")
    print("3. Top5/Top10/Top20と正解平均Pが改善するか")
    print("4. WIN_ONLY/TRIO_ONLYで、どちらの追加価値が大きいか確認する")
    print("5. 両方が有効ならSTEP3で2着/3着の順序を条件付き確率として精密化する")
    print("6. 片方が弱ければ無理に入れず、効く側だけを出目モデルへ残す")
    print("=" * 146)


if __name__ == "__main__":
    main()
