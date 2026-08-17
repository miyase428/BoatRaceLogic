#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
補正後1着率 STEP 8-1: 基本1着率 + 展示情報の補正比較

目的:
- STEP1～7で完成した「展示前の基本1着率」を固定したまま、展示情報を
  どの程度・どの形で反映すると1着確率が改善するかを時系列バックテストする。
- SUM / スリットはまだ使わない。
- 未来情報を使わず、評価期間直前の学習期間で補正強度 beta を選ぶ。

基本1着率:
    p0 = 場×コース
    p_pc = (wins_pc + 20 * p0) / (n_pc + 20)
    p_pvc = (wins_pvc + 10 * p_pc) / (n_pvc + 10)
    6艇で100%正規化

展示進入補正:
- BASE_LANE   : 今回コース=枠番（現行の基本1着率）
- REMAP_ONLY  : 今回コース=展示進入コースとして基本1着率を再計算

展示スコア候補（REMAP_ONLYに上乗せ）:
- EX_TIME     : 展示タイム評価
- ST_ONLY     : 展示ST評価
- FOOT        : 2*周回 + 2*周り足 + 2*直線
- EX_TOTAL    : 展示タイム + 周回 + 周り足 + 直線（既存O列相当）
- EX_SOUGOU   : 展示タイム + ST + 2*周回 + 2*周り足 + 2*直線
                （現在の ex_sougou と同じ重み）

確率補正:
    weight_i = exp(beta * (score_i - race_mean_score))
    p'_i = p_base_i * weight_i
    最後に6艇100%へ正規化

betaは学習期間でBrier最小となる値を候補グリッドから選ぶ。

展示タイムの場平均:
- 現在のWebは exhibition_avg_6m を使うが、過去検証で未来情報を混ぜないため、
  このスクリプトでは「そのレースより前の同場・直近183日」の展示タイム平均を使う。

Usage:
    python3 analysis/base_winrate_exhibition_compare.py 2026-06-15 2026-07-14
    python3 analysis/base_winrate_exhibition_compare.py 2026-07-15 2026-08-14
    python3 analysis/base_winrate_exhibition_compare.py 2026-07-15 2026-08-14 31
"""

from __future__ import annotations

import math
import sys
from collections import defaultdict, deque
from dataclasses import dataclass
from datetime import datetime, timedelta
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from slit_validate_v2 import connect_db


K_PC = 20.0
K_PVC = 10.0
ROLLING_EX_DAYS = 183

SEED_BY_COURSE = {
    1: 0.5355,
    2: 0.1468,
    3: 0.1269,
    4: 0.1111,
    5: 0.0599,
    6: 0.0198,
}

BETA_GRID = (
    -0.050, -0.030, -0.020, -0.010, -0.005,
     0.000,
     0.005,  0.010,  0.015,  0.020,  0.030,
     0.040,  0.050,  0.070,  0.100,
)

SCORE_NAMES = ("EX_TIME", "ST_ONLY", "FOOT", "EX_TOTAL", "EX_SOUGOU")


@dataclass
class BoatSnapshot:
    lane: int
    y: int
    base_lane: float
    base_remap: float
    scores: dict[str, float]


@dataclass
class RaceSnapshot:
    race_code: str
    race_date: object
    boats: list[BoatSnapshot]


def as_int(v):
    if v is None or v == "":
        return None
    try:
        return int(v)
    except (TypeError, ValueError):
        return None


def as_float(v):
    if v is None or v == "":
        return None
    try:
        x = float(v)
    except (TypeError, ValueError):
        return None
    return x if math.isfinite(x) else None


def valid_course(v):
    v = as_int(v)
    return v if v in range(1, 7) else None


def parse_date(s):
    return datetime.strptime(s, "%Y-%m-%d").date()


def parse_args():
    if len(sys.argv) not in (3, 4):
        print(
            "Usage: python3 analysis/base_winrate_exhibition_compare.py "
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


def calc_ex_score(diff):
    if diff <= -0.10:
        return 5
    if diff <= -0.05:
        return 4
    if diff <= 0.05:
        return 3
    if diff <= 0.10:
        return 2
    return 1


def calc_st_score(st):
    if st <= 0.00:
        return 3
    if st <= 0.12:
        return 5
    if st <= 0.20:
        return 3
    if st <= 0.30:
        return 2
    return 1


def calc_lap_score(v, avg):
    diff = v - avg
    if diff <= -0.30:
        return 5
    if diff <= -0.10:
        return 4
    if diff <= 0.10:
        return 3
    if diff <= 0.30:
        return 2
    return 1


def calc_around_score(v, avg):
    diff = v - avg
    if diff <= -0.20:
        return 5
    if diff <= -0.05:
        return 4
    if diff <= 0.05:
        return 3
    if diff <= 0.20:
        return 2
    return 1


def calc_straight_score(v, avg):
    diff = v - avg
    if diff <= -0.04:
        return 5
    if diff <= -0.01:
        return 4
    if diff <= 0.01:
        return 3
    if diff <= 0.04:
        return 2
    return 1


def actual_course(result_course, exhibition_course, lane):
    rc = valid_course(result_course)
    if rc is not None:
        return rc, "result"
    ec = valid_course(exhibition_course)
    if ec is not None:
        return ec, "exhibition"
    lc = valid_course(lane)
    if lc is not None:
        return lc, "lane_fallback"
    return None, "missing"


def prior_rate(place_code, course, venue_n, venue_w, global_n, global_w):
    vn = venue_n[place_code][course]
    if vn > 0:
        return venue_w[place_code][course] / vn

    gn = global_n[course]
    if gn > 0:
        return global_w[course] / gn

    return SEED_BY_COURSE[course]


def hist_counts(history, course, place_code):
    n_pc = 0
    w_pc = 0
    n_pvc = 0
    w_pvc = 0

    for h in history:
        if h["course"] != course:
            continue
        n_pc += 1
        w_pc += h["win"]
        if h["place"] == place_code:
            n_pvc += 1
            w_pvc += h["win"]

    return n_pc, w_pc, n_pvc, w_pvc


def base_prob(player_history, place_code, course, venue_n, venue_w, global_n, global_w):
    p0 = prior_rate(place_code, course, venue_n, venue_w, global_n, global_w)
    n_pc, w_pc, n_pvc, w_pvc = hist_counts(player_history, course, place_code)
    p_pc = (w_pc + K_PC * p0) / (n_pc + K_PC)
    p_pvc = (w_pvc + K_PVC * p_pc) / (n_pvc + K_PVC)
    return p_pvc


def normalize(probs):
    total = sum(probs)
    if total <= 0:
        return None
    return [p / total for p in probs]


def prune_venue_ex_history(place_code, race_date, venue_ex_hist, venue_ex_sum):
    cutoff = race_date - timedelta(days=ROLLING_EX_DAYS)
    dq = venue_ex_hist[place_code]
    while dq and dq[0][0] < cutoff:
        _, old = dq.popleft()
        venue_ex_sum[place_code] -= old


def build_scores(race_rows, venue_avg_ex):
    """6艇分の展示スコアを返す。全項目が揃わない場合はNone。"""
    prepared = []
    for r in race_rows:
        lane, player_id, rank, result_course, ex_course, ex_time, ex_st, lap, around, straight = r
        lane = valid_course(lane)
        ex_course = valid_course(ex_course)
        ex_time = as_float(ex_time)
        ex_st = as_float(ex_st)
        lap = as_float(lap)
        around = as_float(around)
        straight = as_float(straight)

        if (
            lane is None
            or ex_course is None
            or ex_time is None
            or ex_st is None
            or lap is None
            or around is None
            or straight is None
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

    if venue_avg_ex is None or venue_avg_ex <= 0:
        return None

    avg_lap = sum(r["lap"] for r in prepared) / 6.0
    avg_around = sum(r["around"] for r in prepared) / 6.0
    avg_straight = sum(r["straight"] for r in prepared) / 6.0

    for r in prepared:
        ex_score = calc_ex_score(r["ex_time"] - venue_avg_ex)
        st_score = calc_st_score(r["ex_st"])
        lap_score = calc_lap_score(r["lap"], avg_lap)
        around_score = calc_around_score(r["around"], avg_around)
        straight_score = calc_straight_score(r["straight"], avg_straight)

        r["scores"] = {
            "EX_TIME": float(ex_score),
            "ST_ONLY": float(st_score),
            "FOOT": float(2 * lap_score + 2 * around_score + 2 * straight_score),
            "EX_TOTAL": float(ex_score + lap_score + around_score + straight_score),
            "EX_SOUGOU": float(
                ex_score
                + st_score
                + 2 * lap_score
                + 2 * around_score
                + 2 * straight_score
            ),
        }

    return prepared


def load_snapshots(train_start, eval_end):
    venue_n = defaultdict(lambda: {c: 0 for c in range(1, 7)})
    venue_w = defaultdict(lambda: {c: 0 for c in range(1, 7)})
    global_n = {c: 0 for c in range(1, 7)}
    global_w = {c: 0 for c in range(1, 7)}
    player_hist = defaultdict(lambda: deque(maxlen=100))

    venue_ex_hist = defaultdict(deque)
    venue_ex_sum = defaultdict(float)

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
        cur = conn.cursor(name="base_winrate_exhibition_stream")
        cur.itersize = 10000
        cur.execute(sql, (eval_end.isoformat(),))

        current_code = None
        current_date = None
        rows = []

        def process_race(race_date, race_code, race_rows):
            if not race_rows:
                return

            place_code = race_code[8:11] if len(race_code) >= 11 else "???"
            prune_venue_ex_history(
                place_code,
                race_date,
                venue_ex_hist,
                venue_ex_sum,
            )

            ex_hist_n = len(venue_ex_hist[place_code])
            venue_avg_ex = (
                venue_ex_sum[place_code] / ex_hist_n
                if ex_hist_n > 0
                else None
            )

            winners = [r for r in race_rows if as_int(r[2]) == 1]
            unique_winner = len(winners) == 1
            winner_lane = valid_course(winners[0][0]) if unique_winner else None

            # ----------------------------------------------------------
            # 学習/評価用スナップショット
            # ----------------------------------------------------------
            if race_date >= train_start:
                if not unique_winner or winner_lane is None:
                    skipped["winner_not_unique"] += 1
                else:
                    scored = build_scores(race_rows, venue_avg_ex)
                    if scored is None:
                        skipped["exhibition_incomplete"] += 1
                    else:
                        lane_raw = []
                        remap_raw = []
                        staged = []

                        for r in sorted(scored, key=lambda x: x["lane"]):
                            pid = r["player_id"]
                            p_lane = base_prob(
                                player_hist[pid],
                                place_code,
                                r["lane"],
                                venue_n,
                                venue_w,
                                global_n,
                                global_w,
                            )
                            p_remap = base_prob(
                                player_hist[pid],
                                place_code,
                                r["ex_course"],
                                venue_n,
                                venue_w,
                                global_n,
                                global_w,
                            )
                            lane_raw.append(p_lane)
                            remap_raw.append(p_remap)
                            staged.append(r)

                        lane_norm = normalize(lane_raw)
                        remap_norm = normalize(remap_raw)

                        if lane_norm is None or remap_norm is None:
                            skipped["base_normalize_failed"] += 1
                        else:
                            boats = []
                            for idx, r in enumerate(staged):
                                boats.append(
                                    BoatSnapshot(
                                        lane=r["lane"],
                                        y=1 if r["lane"] == winner_lane else 0,
                                        base_lane=lane_norm[idx],
                                        base_remap=remap_norm[idx],
                                        scores=r["scores"],
                                    )
                                )
                            snapshots.append(RaceSnapshot(race_code, race_date, boats))

            # ----------------------------------------------------------
            # レース終了後に基本1着率用履歴を更新（未来情報混入防止）
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


def apply_score(base_probs, scores, beta):
    mean_score = sum(scores) / len(scores)
    weighted = []
    for p, score in zip(base_probs, scores):
        w = math.exp(beta * (score - mean_score))
        weighted.append(p * w)
    return normalize(weighted)


def evaluate(races, mode, score_name=None, beta=0.0):
    if not races:
        return None

    brier = 0.0
    logloss = 0.0
    top1_hit = 0
    race_n = 0
    score_top1_hit = 0

    for race in races:
        boats = sorted(race.boats, key=lambda b: b.lane)

        if mode == "BASE_LANE":
            probs = [b.base_lane for b in boats]
        elif mode == "REMAP_ONLY":
            probs = [b.base_remap for b in boats]
        elif mode == "SCORE":
            base = [b.base_remap for b in boats]
            scores = [b.scores[score_name] for b in boats]
            probs = apply_score(base, scores, beta)
            if probs is None:
                continue
        else:
            raise RuntimeError(f"unknown mode: {mode}")

        for p, b in zip(probs, boats):
            cp = min(max(p, 1e-9), 1.0 - 1e-9)
            brier += (p - b.y) ** 2
            logloss += -(b.y * math.log(cp) + (1 - b.y) * math.log(1 - cp))

        best_idx = sorted(range(6), key=lambda i: (-probs[i], boats[i].lane))[0]
        if boats[best_idx].y == 1:
            top1_hit += 1

        if score_name is not None:
            raw_scores = [b.scores[score_name] for b in boats]
            sidx = sorted(range(6), key=lambda i: (-raw_scores[i], boats[i].lane))[0]
            if boats[sidx].y == 1:
                score_top1_hit += 1

        race_n += 1

    if race_n == 0:
        return None

    boat_n = race_n * 6
    return {
        "races": race_n,
        "brier": brier / boat_n,
        "logloss": logloss / boat_n,
        "top1": top1_hit / race_n,
        "score_top1": (score_top1_hit / race_n) if score_name is not None else None,
    }


def tune_beta(train_races, score_name):
    best = None
    for beta in BETA_GRID:
        m = evaluate(train_races, "SCORE", score_name, beta)
        if m is None:
            continue
        key = (m["brier"], m["logloss"], abs(beta), beta)
        if best is None or key < best[0]:
            best = (key, beta, m)

    if best is None:
        raise RuntimeError(f"{score_name} のbeta学習に使えるレースがありません")
    return best[1], best[2]


def fmt_delta(value, base):
    if base == 0:
        return "-"
    return f"{(value - base) / base * 100:+.3f}%"


def main():
    eval_start, eval_end, train_start, train_end, train_days = parse_args()

    print("補正後1着率 STEP8-1 展示補正比較用の時系列データを構築しています...")
    snapshots, skipped, course_source = load_snapshots(train_start, eval_end)

    train_races = [r for r in snapshots if train_start <= r.race_date <= train_end]
    eval_races = [r for r in snapshots if eval_start <= r.race_date <= eval_end]

    if not train_races:
        raise RuntimeError("学習期間の展示完備レースが0件です")
    if not eval_races:
        raise RuntimeError("評価期間の展示完備レースが0件です")

    tuned = {}
    for score_name in SCORE_NAMES:
        beta, train_m = tune_beta(train_races, score_name)
        tuned[score_name] = (beta, train_m)

    base_lane = evaluate(eval_races, "BASE_LANE")
    remap_only = evaluate(eval_races, "REMAP_ONLY")

    rows = [
        ("BASE_LANE", "-", None, base_lane),
        ("REMAP_ONLY", "-", None, remap_only),
    ]

    for score_name in SCORE_NAMES:
        beta, _ = tuned[score_name]
        m = evaluate(eval_races, "SCORE", score_name, beta)
        rows.append((score_name, f"{beta:+.3f}", score_name, m))

    print("=" * 126)
    print("補正後1着率 STEP 8-1：基本1着率 + 展示情報 補正比較")
    print("=" * 126)
    print(f"学習期間          : {train_start} ～ {train_end} ({train_days}日)")
    print(f"評価期間          : {eval_start} ～ {eval_end}")
    print(f"学習レース        : {len(train_races)}（展示6艇完備）")
    print(f"評価レース        : {len(eval_races)}（展示6艇完備）")
    print("基本1着率          : BB_MEDIUM Kpc=20 / Kpvc=10 → 6艇100%正規化")
    print("展示タイム場平均  : 各レース以前・同場直近183日の展示タイム")
    print("SUM / スリット    : 不使用")
    print("本番変更          : なし")

    print("\n【学習期間で選ばれた展示補正強度 beta】")
    print("指標          beta       学習Brier   学習Top1   展示指標単独Top1")
    print("-" * 86)
    for score_name in SCORE_NAMES:
        beta, m = tuned[score_name]
        print(
            f"{score_name:<12} {beta:+.3f}      "
            f"{m['brier']:.6f}     {m['top1']*100:>6.2f}%      {m['score_top1']*100:>6.2f}%"
        )

    print("\n【評価期間 比較】")
    print("方式          beta         Brier      vs 基本       LogLoss     Top1率    展示指標単独Top1")
    print("-" * 126)
    for name, beta_txt, score_name, m in sorted(rows, key=lambda x: x[3]["brier"]):
        score_top1 = "-" if m["score_top1"] is None else f"{m['score_top1']*100:.2f}%"
        print(
            f"{name:<12} {beta_txt:<10} "
            f"{m['brier']:.6f}   {fmt_delta(m['brier'], base_lane['brier']):>10}   "
            f"{m['logloss']:.6f}   {m['top1']*100:>6.2f}%      {score_top1:>8}"
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
    print("・最重要はBrier。BASE_LANEより小さければ基本1着率から改善")
    print("・REMAP_ONLY改善なら、展示進入コースへの置換自体に価値がある")
    print("・beta=0ならその展示指標は基本1着率へ追加する価値がほぼない")
    print("・beta>0で2期間とも改善する指標だけを補正後1着率へ採用候補にする")
    print("・展示指標単独Top1は補助指標。基本1着率との組合せBrierを優先する")
    print("・1期間だけで決めず、前STEPと同じ2期間で再現するか確認する")
    print("=" * 126)


if __name__ == "__main__":
    main()
