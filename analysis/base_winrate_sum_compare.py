#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
補正後1着率 STEP 8-2: EX_TOTAL補正後 + SUM補正の追加効果比較

目的:
- STEP8-1で採用した「展示進入へリマップ + EX_TOTAL beta=0.10」を固定する。
- その上に、既存SUM理論（場ごとの3展示指標の合計差）の勝率バフ/デバフを
  加えるとBrierがさらに改善するかを、未来情報なしのローリング検証で確認する。
- SUMは情報重複が多いため、改善しなければ1着率補正には採用しない。

SUM定義:
- theories/new_sam/features.json の場別3指標を利用。
- 6艇の raw SUM 平均との差を既存8区間へ分類。
- 各レース時点より前だけを使い、場×展示進入コース×SUM区間の1着率と
  場×展示進入コース全体1着率との差をSUMスコアとする。

比較:
- EX_TOTAL_ONLY : STEP8-1採用形（展示進入リマップ + EX_TOTAL beta=0.10）
- SUM_RAW       : 現行SUMの生差
- SUM_K20       : 区間率をコース率へK=20で縮小
- SUM_K50       : 同 K=50
- SUM_K100      : 同 K=100

SUM補正:
    weight_i = exp(gamma * (sum_score_i - race_mean_sum_score))
    p'_i = p_ex_total_i * weight_i
    最後に6艇100%正規化

gammaは評価期間直前31日でBrier最小となる値を選ぶ。

Usage:
    python3 analysis/base_winrate_sum_compare.py 2026-06-15 2026-07-14
    python3 analysis/base_winrate_sum_compare.py 2026-07-15 2026-08-14
    python3 analysis/base_winrate_sum_compare.py 2026-07-15 2026-08-14 31
"""

from __future__ import annotations

import json
import math
import sys
from collections import defaultdict, deque
from dataclasses import dataclass
from datetime import datetime, timedelta
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from slit_validate_v2 import connect_db
from base_winrate_exhibition_compare import (
    K_PC,
    K_PVC,
    ROLLING_EX_DAYS,
    actual_course,
    as_float,
    as_int,
    base_prob,
    calc_around_score,
    calc_ex_score,
    calc_lap_score,
    calc_straight_score,
    normalize,
    prune_venue_ex_history,
    valid_course,
)


EX_TOTAL_BETA = 0.100
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

GAMMA_GRID = (
    -10.0, -6.0, -4.0, -2.0, -1.0,
      0.0,
      1.0,  2.0,  3.0,  4.0,  5.0, 6.0, 8.0, 10.0, 12.0,
)


@dataclass
class BoatSnapshot:
    lane: int
    y: int
    ex_total_prob: float
    sum_scores: dict[str, float]
    sum_interval_n: int
    sum_course_n: int


@dataclass
class RaceSnapshot:
    race_code: str
    race_date: object
    boats: list[BoatSnapshot]


def parse_date(s):
    return datetime.strptime(s, "%Y-%m-%d").date()


def parse_args():
    if len(sys.argv) not in (3, 4):
        print(
            "Usage: python3 analysis/base_winrate_sum_compare.py "
            "YYYY-MM-DD YYYY-MM-DD [TRAIN_DAYS]"
        )
        sys.exit(1)

    start = parse_date(sys.argv[1])
    end = parse_date(sys.argv[2])
    if start > end:
        raise RuntimeError("開始日が終了日より後です")

    train_days = int(sys.argv[3]) if len(sys.argv) == 4 else 31
    if train_days < 7:
        raise RuntimeError("TRAIN_DAYSは7日以上にしてください")

    train_start = start - timedelta(days=train_days)
    train_end = start - timedelta(days=1)
    return start, end, train_start, train_end, train_days


def load_sum_features():
    path = Path(__file__).resolve().parent.parent / "theories" / "new_sam" / "features.json"
    with path.open("r", encoding="utf-8") as f:
        data = json.load(f)

    for place, cols in data.items():
        if not isinstance(cols, list) or len(cols) != 3:
            raise RuntimeError(f"SUM features設定が不正です: {place}={cols}")
    return data


def sum_interval_label(value):
    for label, low, high in SUM_INTERVALS:
        if low <= value < high:
            return label
    raise RuntimeError(f"SUM区間判定失敗: {value}")


def feature_value(row, name):
    if name == "exhibition_time":
        return row["ex_time"]
    if name == "lap_time":
        return row["lap"]
    if name == "around_time":
        return row["around"]
    if name == "straight_time":
        return row["straight"]
    raise RuntimeError(f"未対応SUM feature: {name}")


def apply_centered_score(base_probs, scores, strength):
    mean_score = sum(scores) / len(scores)
    weighted = []
    for p, score in zip(base_probs, scores):
        weighted.append(p * math.exp(strength * (score - mean_score)))
    return normalize(weighted)


def build_prepared(race_rows, venue_avg_ex, feature_cols):
    if venue_avg_ex is None or venue_avg_ex <= 0:
        return None

    prepared = []
    for row in race_rows:
        lane, player_id, rank, result_course, ex_course, ex_time, ex_st, lap, around, straight = row
        lane = valid_course(lane)
        ex_course = valid_course(ex_course)
        ex_time = as_float(ex_time)
        ex_st = as_float(ex_st)
        lap = as_float(lap)
        around = as_float(around)
        straight = as_float(straight)

        # STEP8-1と同じ母集団に揃えるため、STも含めて展示全項目完備を要求する。
        if (
            lane is None or ex_course is None
            or ex_time is None or ex_st is None
            or lap is None or around is None or straight is None
        ):
            return None

        prepared.append({
            "lane": lane,
            "player_id": str(player_id or "").strip(),
            "rank": as_int(rank),
            "result_course": result_course,
            "ex_course": ex_course,
            "ex_time": ex_time,
            "ex_st": ex_st,
            "lap": lap,
            "around": around,
            "straight": straight,
        })

    if len(prepared) != 6:
        return None
    if {r["lane"] for r in prepared} != {1, 2, 3, 4, 5, 6}:
        return None
    if {r["ex_course"] for r in prepared} != {1, 2, 3, 4, 5, 6}:
        return None

    avg_lap = sum(r["lap"] for r in prepared) / 6.0
    avg_around = sum(r["around"] for r in prepared) / 6.0
    avg_straight = sum(r["straight"] for r in prepared) / 6.0

    for r in prepared:
        ex_score = calc_ex_score(r["ex_time"] - venue_avg_ex)
        lap_score = calc_lap_score(r["lap"], avg_lap)
        around_score = calc_around_score(r["around"], avg_around)
        straight_score = calc_straight_score(r["straight"], avg_straight)
        r["ex_total"] = float(ex_score + lap_score + around_score + straight_score)

        raw_sum = sum(feature_value(r, col) for col in feature_cols)
        r["sum_raw"] = raw_sum

    race_mean_sum = sum(r["sum_raw"] for r in prepared) / 6.0
    for r in prepared:
        r["sum_diff"] = r["sum_raw"] - race_mean_sum
        r["sum_interval"] = sum_interval_label(r["sum_diff"])

    return prepared


def sum_scores_for_boat(place_code, course, interval_label, course_n, course_w, interval_n, interval_w):
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


def load_snapshots(train_start, eval_end):
    features = load_sum_features()

    # 基本1着率用
    venue_n = defaultdict(lambda: {c: 0 for c in range(1, 7)})
    venue_w = defaultdict(lambda: {c: 0 for c in range(1, 7)})
    global_n = {c: 0 for c in range(1, 7)}
    global_w = {c: 0 for c in range(1, 7)}
    player_hist = defaultdict(lambda: deque(maxlen=100))

    # 展示タイム場平均用
    venue_ex_hist = defaultdict(deque)
    venue_ex_sum = defaultdict(float)

    # 時系列SUM統計用
    sum_course_n = defaultdict(lambda: {c: 0 for c in range(1, 7)})
    sum_course_w = defaultdict(lambda: {c: 0 for c in range(1, 7)})
    sum_interval_n = defaultdict(
        lambda: {
            c: {label: 0 for label, _, _ in SUM_INTERVALS}
            for c in range(1, 7)
        }
    )
    sum_interval_w = defaultdict(
        lambda: {
            c: {label: 0 for label, _, _ in SUM_INTERVALS}
            for c in range(1, 7)
        }
    )

    snapshots = []
    skipped = defaultdict(int)
    course_source = defaultdict(int)

    sql = """
        SELECT
            rm.race_date,
            re.race_code,
            re.lane_number,
            re.player_id::text,
            rrd.rank,
            rrd.entry_course AS result_course,
            el.entry_course AS exhibition_course,
            el.exhibition_time,
            el.start_timing,
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
                start_timing,
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
        cur = conn.cursor(name="base_winrate_sum_stream")
        cur.itersize = 10000
        cur.execute(sql, (eval_end.isoformat(),))

        current_code = None
        current_date = None
        rows = []

        def process_race(race_date, race_code, race_rows):
            if not race_rows:
                return

            place_code = race_code[8:11] if len(race_code) >= 11 else "???"
            feature_cols = features.get(place_code)
            if not feature_cols:
                skipped["sum_features_missing"] += 1
                return

            prune_venue_ex_history(
                place_code,
                race_date,
                venue_ex_hist,
                venue_ex_sum,
            )
            ex_hist_n = len(venue_ex_hist[place_code])
            venue_avg_ex = venue_ex_sum[place_code] / ex_hist_n if ex_hist_n > 0 else None

            winners = [r for r in race_rows if as_int(r[2]) == 1]
            unique_winner = len(winners) == 1
            winner_lane = valid_course(winners[0][0]) if unique_winner else None

            prepared = build_prepared(race_rows, venue_avg_ex, feature_cols)

            # ----------------------------------------------------------
            # 学習/評価スナップショット（SUM統計更新より前）
            # ----------------------------------------------------------
            if race_date >= train_start:
                if not unique_winner or winner_lane is None:
                    skipped["winner_not_unique"] += 1
                elif prepared is None:
                    skipped["exhibition_incomplete"] += 1
                else:
                    base_raw = []
                    staged = sorted(prepared, key=lambda x: x["lane"])
                    for r in staged:
                        pid = r["player_id"]
                        base_raw.append(
                            base_prob(
                                player_hist[pid],
                                place_code,
                                r["ex_course"],
                                venue_n,
                                venue_w,
                                global_n,
                                global_w,
                            )
                        )

                    base_remap = normalize(base_raw)
                    if base_remap is None:
                        skipped["base_normalize_failed"] += 1
                    else:
                        ex_scores = [r["ex_total"] for r in staged]
                        ex_probs = apply_centered_score(base_remap, ex_scores, EX_TOTAL_BETA)
                        if ex_probs is None:
                            skipped["ex_normalize_failed"] += 1
                        else:
                            boats = []
                            for idx, r in enumerate(staged):
                                ss, interval_n_now, course_n_now = sum_scores_for_boat(
                                    place_code,
                                    r["ex_course"],
                                    r["sum_interval"],
                                    sum_course_n,
                                    sum_course_w,
                                    sum_interval_n,
                                    sum_interval_w,
                                )
                                boats.append(
                                    BoatSnapshot(
                                        lane=r["lane"],
                                        y=1 if r["lane"] == winner_lane else 0,
                                        ex_total_prob=ex_probs[idx],
                                        sum_scores=ss,
                                        sum_interval_n=interval_n_now,
                                        sum_course_n=course_n_now,
                                    )
                                )
                            snapshots.append(RaceSnapshot(race_code, race_date, boats))

            # ----------------------------------------------------------
            # レース終了後に基本1着率履歴を更新
            # ----------------------------------------------------------
            if unique_winner:
                w_lane, w_pid, w_rank, w_rc, w_ec, *_ = winners[0]
                winner_course, _ = actual_course(w_rc, w_ec, w_lane)
                if winner_course is not None:
                    for c in range(1, 7):
                        venue_n[place_code][c] += 1
                        global_n[c] += 1
                    venue_w[place_code][winner_course] += 1
                    global_w[winner_course] += 1

            for r in race_rows:
                lane, player_id, rank, result_course, exhibition_course, ex_time, *_ = r
                pid = str(player_id or "").strip()
                if pid:
                    c, source = actual_course(result_course, exhibition_course, lane)
                    if c is not None:
                        course_source[source] += 1
                        player_hist[pid].append({
                            "place": place_code,
                            "course": c,
                            "win": 1 if as_int(rank) == 1 else 0,
                        })

                ex = as_float(ex_time)
                if ex is not None and ex > 0:
                    venue_ex_hist[place_code].append((race_date, ex))
                    venue_ex_sum[place_code] += ex

            # ----------------------------------------------------------
            # レース終了後にSUM統計を更新（未来情報混入防止）
            # ----------------------------------------------------------
            if unique_winner and prepared is not None:
                for r in prepared:
                    c = r["ex_course"]
                    label = r["sum_interval"]
                    sum_course_n[place_code][c] += 1
                    sum_interval_n[place_code][c][label] += 1
                    if r["rank"] == 1:
                        sum_course_w[place_code][c] += 1
                        sum_interval_w[place_code][c][label] += 1

        for (
            race_date,
            race_code,
            lane,
            player_id,
            rank,
            result_course,
            exhibition_course,
            exhibition_time,
            start_timing,
            lap_time,
            around_time,
            straight_time,
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

            rows.append((
                lane,
                player_id,
                rank,
                result_course,
                exhibition_course,
                exhibition_time,
                start_timing,
                lap_time,
                around_time,
                straight_time,
            ))

        if current_code is not None:
            process_race(current_date, current_code, rows)

        cur.close()

    return snapshots, skipped, course_source


def evaluate(races, method=None, gamma=0.0):
    if not races:
        return None

    brier = 0.0
    logloss = 0.0
    top1_hit = 0
    score_top1_hit = 0
    race_n = 0

    for race in races:
        boats = sorted(race.boats, key=lambda b: b.lane)
        base = [b.ex_total_prob for b in boats]

        if method is None:
            probs = base
        else:
            scores = [b.sum_scores[method] for b in boats]
            probs = apply_centered_score(base, scores, gamma)
            if probs is None:
                continue

            sidx = sorted(range(6), key=lambda i: (-scores[i], boats[i].lane))[0]
            if boats[sidx].y == 1:
                score_top1_hit += 1

        for p, b in zip(probs, boats):
            cp = min(max(p, 1e-9), 1.0 - 1e-9)
            brier += (p - b.y) ** 2
            logloss += -(b.y * math.log(cp) + (1 - b.y) * math.log(1 - cp))

        best_idx = sorted(range(6), key=lambda i: (-probs[i], boats[i].lane))[0]
        if boats[best_idx].y == 1:
            top1_hit += 1
        race_n += 1

    if race_n == 0:
        return None

    return {
        "races": race_n,
        "brier": brier / (race_n * 6),
        "logloss": logloss / (race_n * 6),
        "top1": top1_hit / race_n,
        "score_top1": (score_top1_hit / race_n) if method is not None else None,
    }


def tune_gamma(train_races, method):
    best = None
    for gamma in GAMMA_GRID:
        m = evaluate(train_races, method, gamma)
        if m is None:
            continue
        key = (m["brier"], m["logloss"], abs(gamma), gamma)
        if best is None or key < best[0]:
            best = (key, gamma, m)

    if best is None:
        raise RuntimeError(f"{method} のgamma学習に使えるレースがありません")
    return best[1], best[2]


def fmt_delta(value, base):
    if base == 0:
        return "-"
    return f"{(value - base) / base * 100:+.3f}%"


def main():
    eval_start, eval_end, train_start, train_end, train_days = parse_args()

    print("補正後1着率 STEP8-2 SUM補正比較用の時系列データを構築しています...")
    snapshots, skipped, course_source = load_snapshots(train_start, eval_end)

    train_races = [r for r in snapshots if train_start <= r.race_date <= train_end]
    eval_races = [r for r in snapshots if eval_start <= r.race_date <= eval_end]

    if not train_races:
        raise RuntimeError("学習期間の評価可能レースが0件です")
    if not eval_races:
        raise RuntimeError("評価期間の評価可能レースが0件です")

    ex_base_train = evaluate(train_races)
    ex_base_eval = evaluate(eval_races)

    tuned = {}
    rows = [("EX_TOTAL_ONLY", "-", ex_base_eval)]
    for method in SUM_METHODS:
        gamma, train_m = tune_gamma(train_races, method)
        eval_m = evaluate(eval_races, method, gamma)
        tuned[method] = (gamma, train_m)
        rows.append((method, f"{gamma:+.1f}", eval_m))

    interval_ns = [b.sum_interval_n for r in eval_races for b in r.boats]
    course_ns = [b.sum_course_n for r in eval_races for b in r.boats]
    zero_interval = sum(1 for n in interval_ns if n == 0)

    print("=" * 132)
    print("補正後1着率 STEP 8-2：EX_TOTAL補正後 + SUM補正 比較")
    print("=" * 132)
    print(f"学習期間          : {train_start} ～ {train_end} ({train_days}日)")
    print(f"評価期間          : {eval_start} ～ {eval_end}")
    print(f"学習レース        : {len(train_races)}（展示6艇完備）")
    print(f"評価レース        : {len(eval_races)}（展示6艇完備）")
    print("基準補正          : 展示進入リマップ + EX_TOTAL beta=+0.100")
    print("SUM統計           : 各レース時点より前の同場×展示進入C×SUM区間")
    print("SUM場別3指標      : theories/new_sam/features.json")
    print("スリット           : 不使用")
    print("本番変更          : なし")

    print("\n【学習期間で選ばれたSUM補正強度 gamma】")
    print("方式          gamma      学習Brier   vs EX_TOTAL   学習Top1   SUM単独Top1")
    print("-" * 100)
    for method in SUM_METHODS:
        gamma, m = tuned[method]
        print(
            f"{method:<12} {gamma:+5.1f}      "
            f"{m['brier']:.6f}   {fmt_delta(m['brier'], ex_base_train['brier']):>10}   "
            f"{m['top1']*100:>6.2f}%      {m['score_top1']*100:>6.2f}%"
        )

    print("\n【評価期間 比較】")
    print("方式          gamma        Brier      vs EX_TOTAL    LogLoss     Top1率     SUM単独Top1")
    print("-" * 132)
    for name, gamma_txt, m in sorted(rows, key=lambda x: x[2]["brier"]):
        score_top1 = "-" if m["score_top1"] is None else f"{m['score_top1']*100:.2f}%"
        print(
            f"{name:<14} {gamma_txt:<9} "
            f"{m['brier']:.6f}   {fmt_delta(m['brier'], ex_base_eval['brier']):>11}   "
            f"{m['logloss']:.6f}   {m['top1']*100:>6.2f}%      {score_top1:>8}"
        )

    print("\n【SUM母数診断・評価期間】")
    print(f"場×C×区間 N平均            : {sum(interval_ns)/len(interval_ns):.2f}")
    print(f"場×C N平均                 : {sum(course_ns)/len(course_ns):.2f}")
    print(
        f"場×C×区間 N=0             : {zero_interval}/{len(interval_ns)} "
        f"({zero_interval/len(interval_ns)*100:.2f}%)"
    )

    print("\n【コース復元】")
    print(
        "過去履歴(result/ex/lane) : "
        f"{course_source['result']}/{course_source['exhibition']}/{course_source['lane_fallback']}"
    )

    print("\n【skip】")
    if skipped:
        for key in sorted(skipped):
            print(f"{key:<28}: {skipped[key]}")
    else:
        print("なし")

    print("\n【判定の見方】")
    print("・最重要はEX_TOTAL_ONLYよりBrierがさらに下がるか")
    print("・gamma=0ならSUMを1着率へ追加する価値はほぼない")
    print("・RAWよりK20/K50/K100が安定するならSUM区間率も平滑化した方がよい")
    print("・2期間とも改善する方式だけを採用候補にする")
    print("・改善しなければSUMは既存どおり補助表示に残し、補正後1着率には入れない")
    print("=" * 132)


if __name__ == "__main__":
    main()
