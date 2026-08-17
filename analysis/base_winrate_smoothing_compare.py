#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
基礎1着率 STEP 4: 平滑化方式比較

目的:
- 展示情報を使わない基礎1着率について、以下3階層を同時に扱う。
    1) 場×コース              : 全過去（対象レースより前のみ）
    2) 選手×コース            : 選手の直近100走の中から同コース
    3) 選手×場×コース        : 同じ直近100走の中から同場×同コース
- 6艇100%正規化はまだ行わない。
- 未来情報を使わず時系列でローリング検証する。

比較方式:
- VENUE_ONLY : 場×コースのみ
- RAW_HIER   : 同場同C raw > 選手同C raw > 場×C の順に採用（平滑化なし）
- BB_LIGHT   : Beta-Binomial階層平滑化 Kpc=10, Kpvc=3
- BB_MEDIUM  : Beta-Binomial階層平滑化 Kpc=20, Kpvc=10
- BB_STRONG  : Beta-Binomial階層平滑化 Kpc=40, Kpvc=20
- EB_AUTO    : 評価期間直前の学習期間でKpc/KpvcをBrier最小となるよう自動選択

階層平滑化:
    p0   = 場×コース過去1着率
    p_pc = (wins_pc + Kpc * p0) / (n_pc + Kpc)
    p    = (wins_pvc + Kpvc * p_pc) / (n_pvc + Kpvc)

Usage:
    python3 analysis/base_winrate_smoothing_compare.py 2026-06-15 2026-07-14
    python3 analysis/base_winrate_smoothing_compare.py 2026-07-15 2026-08-14

    # 学習期間日数を変える場合（既定31日）
    python3 analysis/base_winrate_smoothing_compare.py 2026-07-15 2026-08-14 31
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


SEED_BY_COURSE = {
    1: 0.5355,
    2: 0.1468,
    3: 0.1269,
    4: 0.1111,
    5: 0.0599,
    6: 0.0198,
}

K_PC_GRID = (5, 10, 20, 40, 80)
K_PVC_GRID = (1, 3, 5, 10, 20, 40)

FIXED_METHODS = {
    "BB_LIGHT": (10, 3),
    "BB_MEDIUM": (20, 10),
    "BB_STRONG": (40, 20),
}


@dataclass
class BoatSnapshot:
    lane: int
    y: int
    p0: float
    n_pc: int
    w_pc: int
    n_pvc: int
    w_pvc: int


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


def valid_course(v):
    v = as_int(v)
    return v if v in range(1, 7) else None


def parse_date(s):
    return datetime.strptime(s, "%Y-%m-%d").date()


def parse_args():
    if len(sys.argv) not in (3, 4):
        print(
            "Usage: python3 analysis/base_winrate_smoothing_compare.py "
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


def load_snapshots(train_start, eval_end):
    """DBを時系列で1回走査し、学習期間＋評価期間の特徴量だけ保存する。"""

    venue_n = defaultdict(lambda: {c: 0 for c in range(1, 7)})
    venue_w = defaultdict(lambda: {c: 0 for c in range(1, 7)})
    global_n = {c: 0 for c in range(1, 7)}
    global_w = {c: 0 for c in range(1, 7)}
    player_hist = defaultdict(lambda: deque(maxlen=100))

    snapshots = []
    course_source = defaultdict(int)
    skipped = defaultdict(int)

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
            SELECT entry_course
            FROM boat_race.exhibition_live x
            WHERE x.race_code = re.race_code
              AND x.player_id = re.player_id
            LIMIT 1
        ) el ON TRUE
        WHERE rm.race_date <= %s::date
        ORDER BY rm.race_date, re.race_code, re.lane_number
    """

    with connect_db() as conn:
        # サーバーサイドカーソルで全履歴をメモリへ載せずに順次処理する。
        cur = conn.cursor(name="base_winrate_smoothing_stream")
        cur.itersize = 10000
        cur.execute(sql, (eval_end.isoformat(),))

        current_code = None
        current_date = None
        rows = []

        def process_race(race_date, race_code, race_rows):
            if not race_rows:
                return

            place_code = race_code[8:11] if len(race_code) >= 11 else "???"

            # 対象艇6艇が揃わないレースは予測評価には使わないが、
            # 取得できた各艇履歴は後段で更新する。
            valid_lanes = [valid_course(r[0]) for r in race_rows]
            lane_set = {x for x in valid_lanes if x is not None}

            winners = []
            for r in race_rows:
                lane, player_id, rank, result_course, exhibition_course = r
                if as_int(rank) == 1:
                    winners.append(r)

            unique_winner = len(winners) == 1
            winner_lane = valid_course(winners[0][0]) if unique_winner else None

            if race_date >= train_start and len(race_rows) == 6 and lane_set == {1,2,3,4,5,6} and unique_winner and winner_lane is not None:
                boats = []
                for lane, player_id, rank, result_course, exhibition_course in race_rows:
                    lane = valid_course(lane)
                    if lane is None:
                        continue
                    pid = str(player_id or "").strip()
                    p0 = prior_rate(place_code, lane, venue_n, venue_w, global_n, global_w)
                    n_pc, w_pc, n_pvc, w_pvc = hist_counts(player_hist[pid], lane, place_code)
                    boats.append(
                        BoatSnapshot(
                            lane=lane,
                            y=1 if lane == winner_lane else 0,
                            p0=p0,
                            n_pc=n_pc,
                            w_pc=w_pc,
                            n_pvc=n_pvc,
                            w_pvc=w_pvc,
                        )
                    )

                if len(boats) == 6:
                    boats.sort(key=lambda x: x.lane)
                    snapshots.append(RaceSnapshot(race_code, race_date, boats))
                else:
                    skipped["snapshot_not_6"] += 1
            elif race_date >= train_start:
                if len(race_rows) != 6 or lane_set != {1,2,3,4,5,6}:
                    skipped["entry_not_6"] += 1
                elif not unique_winner:
                    skipped["winner_not_unique"] += 1

            # ---- レース終了後に場×コース実績を更新（未来情報混入防止） ----
            if unique_winner:
                w_lane, w_pid, w_rank, w_rc, w_ec = winners[0]
                winner_course, _ = actual_course(w_rc, w_ec, w_lane)
                if winner_course is not None:
                    for c in range(1, 7):
                        venue_n[place_code][c] += 1
                        global_n[c] += 1
                    venue_w[place_code][winner_course] += 1
                    global_w[winner_course] += 1

            # ---- 各選手の直近100走を更新 ----
            for lane, player_id, rank, result_course, exhibition_course in race_rows:
                pid = str(player_id or "").strip()
                if not pid:
                    continue
                c, source = actual_course(result_course, exhibition_course, lane)
                if c is None:
                    skipped["player_course_missing"] += 1
                    continue
                course_source[source] += 1
                player_hist[pid].append({
                    "place": place_code,
                    "course": c,
                    "win": 1 if as_int(rank) == 1 else 0,
                })

        for race_date, race_code, lane, player_id, rank, result_course, exhibition_course in cur:
            race_code = str(race_code)
            if current_code is None:
                current_code = race_code
                current_date = race_date

            if race_code != current_code:
                process_race(current_date, current_code, rows)
                rows = []
                current_code = race_code
                current_date = race_date

            rows.append((lane, player_id, rank, result_course, exhibition_course))

        if current_code is not None:
            process_race(current_date, current_code, rows)

        cur.close()

    return snapshots, course_source, skipped


def prob_venue(b):
    return b.p0


def prob_raw(b):
    if b.n_pvc > 0:
        return b.w_pvc / b.n_pvc
    if b.n_pc > 0:
        return b.w_pc / b.n_pc
    return b.p0


def prob_beta(b, k_pc, k_pvc):
    p_pc = (b.w_pc + k_pc * b.p0) / (b.n_pc + k_pc)
    p = (b.w_pvc + k_pvc * p_pc) / (b.n_pvc + k_pvc)
    return p


def clipped(p):
    return min(max(p, 1e-6), 1.0 - 1e-6)


def evaluate(races, method_name, params=None):
    if not races:
        return None

    brier_sum = 0.0
    logloss_sum = 0.0
    boat_n = 0
    top1_hit = 0
    sum_prob_total = 0.0

    for race in races:
        probs = []
        for b in race.boats:
            if method_name == "VENUE_ONLY":
                p = prob_venue(b)
            elif method_name == "RAW_HIER":
                p = prob_raw(b)
            else:
                k_pc, k_pvc = params
                p = prob_beta(b, k_pc, k_pvc)

            cp = clipped(p)
            brier_sum += (p - b.y) ** 2
            logloss_sum += -(b.y * math.log(cp) + (1 - b.y) * math.log(1 - cp))
            boat_n += 1
            probs.append((p, b.lane, b.y))

        sum_prob_total += sum(x[0] for x in probs)
        # 同値なら艇番の小さい方を採用（固定ルール）
        best = sorted(probs, key=lambda x: (-x[0], x[1]))[0]
        if best[2] == 1:
            top1_hit += 1

    return {
        "races": len(races),
        "boats": boat_n,
        "brier": brier_sum / boat_n,
        "logloss": logloss_sum / boat_n,
        "top1": top1_hit / len(races),
        "avg_sum6": sum_prob_total / len(races),
    }


def tune_eb(train_races):
    best = None
    for k_pc in K_PC_GRID:
        for k_pvc in K_PVC_GRID:
            m = evaluate(train_races, "BETA", (k_pc, k_pvc))
            if m is None:
                continue
            key = (m["brier"], m["logloss"], k_pc, k_pvc)
            if best is None or key < best[0]:
                best = (key, k_pc, k_pvc, m)
    if best is None:
        raise RuntimeError("EB_AUTO用の学習レースがありません")
    return best[1], best[2], best[3]


def fmt_delta(value, base):
    if base == 0:
        return "-"
    return f"{(value - base) / base * 100:+.3f}%"


def main():
    eval_start, eval_end, train_start, train_end, train_days = parse_args()

    print("基礎1着率 STEP4 平滑化比較用の時系列データを構築しています...")
    snapshots, sources, skipped = load_snapshots(train_start, eval_end)

    train_races = [r for r in snapshots if train_start <= r.race_date <= train_end]
    eval_races = [r for r in snapshots if eval_start <= r.race_date <= eval_end]

    if not train_races:
        raise RuntimeError("学習期間の評価可能レースが0件です")
    if not eval_races:
        raise RuntimeError("評価期間の評価可能レースが0件です")

    eb_kpc, eb_kpvc, eb_train = tune_eb(train_races)

    methods = [
        ("VENUE_ONLY", None),
        ("RAW_HIER", None),
        ("BB_LIGHT", FIXED_METHODS["BB_LIGHT"]),
        ("BB_MEDIUM", FIXED_METHODS["BB_MEDIUM"]),
        ("BB_STRONG", FIXED_METHODS["BB_STRONG"]),
        ("EB_AUTO", (eb_kpc, eb_kpvc)),
    ]

    eval_metrics = []
    for name, params in methods:
        if name in ("VENUE_ONLY", "RAW_HIER"):
            m = evaluate(eval_races, name)
        else:
            m = evaluate(eval_races, "BETA", params)
        eval_metrics.append((name, params, m))

    venue_base = eval_metrics[0][2]

    print("=" * 118)
    print("基礎1着率 STEP 4：平滑化方式比較")
    print("=" * 118)
    print(f"学習期間          : {train_start} ～ {train_end} ({train_days}日)")
    print(f"評価期間          : {eval_start} ～ {eval_end}")
    print(f"学習レース        : {len(train_races)}")
    print(f"評価レース        : {len(eval_races)}")
    print("展示性能指標      : 不使用")
    print("今回コース        : 枠番=コース")
    print("選手履歴          : 各対象レースより前の直近100走")
    print("場×コース        : 各対象レースより前の全履歴")
    print("100%正規化        : なし")
    print("本番変更          : なし")

    print("\n【EB_AUTO 学習結果】")
    print(f"選択Kpc/Kpvc      : {eb_kpc} / {eb_kpvc}")
    print(f"学習Brier         : {eb_train['brier']:.6f}")
    print(f"学習Top1          : {eb_train['top1']*100:.2f}%")
    print(f"学習6艇合計       : {eb_train['avg_sum6']*100:.2f}%")

    print("\n【評価期間 比較】")
    print("方式          Kpc/Kpvc      Brier      vs VENUE      LogLoss     Top1率      6艇合計平均")
    print("-" * 118)
    for name, params, m in sorted(eval_metrics, key=lambda x: x[2]["brier"]):
        ktxt = "-" if params is None else f"{params[0]}/{params[1]}"
        print(
            f"{name:<13} {ktxt:<12} "
            f"{m['brier']:.6f}   {fmt_delta(m['brier'], venue_base['brier']):>10}   "
            f"{m['logloss']:.6f}   {m['top1']*100:>6.2f}%      {m['avg_sum6']*100:>7.2f}%"
        )

    # 母数診断（評価期間のみ）
    pc_ns = []
    pvc_ns = []
    zero_pvc = 0
    for race in eval_races:
        for b in race.boats:
            pc_ns.append(b.n_pc)
            pvc_ns.append(b.n_pvc)
            if b.n_pvc == 0:
                zero_pvc += 1

    def avg(xs):
        return sum(xs) / len(xs) if xs else 0.0

    print("\n【母数診断】")
    print(f"選手×コース N平均          : {avg(pc_ns):.2f}")
    print(f"選手×場×コース N平均      : {avg(pvc_ns):.2f}")
    print(f"選手×場×コース N=0        : {zero_pvc}/{len(pvc_ns)} ({zero_pvc/len(pvc_ns)*100:.2f}%)")
    print(
        "コース復元(result/ex/lane) : "
        f"{sources['result']}/{sources['exhibition']}/{sources['lane_fallback']}"
    )

    print("\n【skip】")
    for key in sorted(skipped):
        print(f"{key:<28}: {skipped[key]}")

    print("\n【判定の見方】")
    print("・Brierは小さいほど、1着確率として正確")
    print("・Top1率は6艇の未正規化確率で最大の艇が実際に1着した割合")
    print("・6艇合計平均は100%に近いほど自然だが、この段階では正規化していないので補助指標")
    print("・RAW_HIERが悪化し、Beta-Binomial系が改善するなら少数標本の平滑化が有効")
    print("・1期間だけで決めず、予定どおり別期間でも同じ傾向か確認して方式を確定する")
    print("=" * 118)


if __name__ == "__main__":
    main()
