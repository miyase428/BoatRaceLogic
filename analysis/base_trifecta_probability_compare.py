#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
出目確率 STEP 1：場×3連単コースパターンの基礎確率を作る。

目的
----
AI3連対率まで完成した次段階として、まず選手能力や展示評価を追加しない
「基礎出目確率」を時系列で検証する。

1レースの3連単は 6P3 = 120 通り。
各パターンを「1着コース-2着コース-3着コース」として扱い、
対象レースより前の履歴だけから確率を作る。

方式
----
GLOBAL_ONLY
    全場の120パターン分布。

VENUE_RAW
    場別120パターンの生比率。ゼロ確率回避のため微小Dirichletのみ使用。

VENUE_K50 / K100 / K300 / K1000 / K3000
    場別分布を全場分布へKレース分だけ縮小する経験ベイズ。

    p(pattern) = (venue_count + K * p_global) / (venue_n + K)

全場分布にも Jeffreys 型の Dirichlet alpha=0.5 を入れ、
未観測パターンの確率0を防ぐ。

検証
----
- P1だけで最良Kを選択（Multiclass LogLoss優先、Brierを第2基準）
- P2は完全ホールドアウト
- 対象レース結果を履歴へ入れる前に予測するため未来情報なし
- 対象レースは展示進入1～6が完全なレースのみ
- 実際の1～3着艇を今回の展示進入コースへ写して正解パターンを作る
- 履歴更新用の過去実コースは result_detail -> exhibition_live -> 枠番
- 各レース120通り合計は必ず100%

評価指標
--------
- 120クラス Multiclass LogLoss
- 120クラス Brier（1レースあたり120クラスの二乗誤差合計）
- 正解出目へ割り当てた平均確率
- Top1 / Top3 / Top5 / Top10 / Top20 的中率
- 正解出目の平均順位 / 中央順位
- 120候補を二値として見た確率校正

Usage
-----
python3 analysis/base_trifecta_probability_compare.py \
  2026-06-15 2026-07-14 \
  2026-07-15 2026-08-14
"""

from __future__ import annotations

import math
import statistics
import sys
from collections import defaultdict
from dataclasses import dataclass, field
from datetime import datetime
from itertools import permutations
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from slit_validate_v2 import connect_db


PATTERNS = list(permutations(range(1, 7), 3))
PATTERN_INDEX = {p: i for i, p in enumerate(PATTERNS)}
M = len(PATTERNS)  # 120
GLOBAL_ALPHA = 0.5
RAW_ALPHA = 1e-3

METHODS = [
    ("GLOBAL_ONLY", None),
    ("VENUE_RAW", 0.0),
    ("VENUE_K50", 50.0),
    ("VENUE_K100", 100.0),
    ("VENUE_K300", 300.0),
    ("VENUE_K1000", 1000.0),
    ("VENUE_K3000", 3000.0),
]

TOP_NS = (1, 3, 5, 10, 20)

# 120候補×レースを二値予測として校正を見るための確率帯。
CAL_BINS = [
    (0.000, 0.0025),
    (0.0025, 0.005),
    (0.005, 0.010),
    (0.010, 0.020),
    (0.020, 0.030),
    (0.030, 0.050),
    (0.050, 0.100),
    (0.100, 1.000001),
]


def parse_date(s: str):
    return datetime.strptime(s, "%Y-%m-%d").date()


def valid_course(v):
    if v is None or v == "":
        return None
    try:
        c = int(v)
    except (TypeError, ValueError):
        return None
    return c if 1 <= c <= 6 else None


def rank_int(v):
    if v is None or v == "":
        return None
    try:
        r = int(str(v).strip())
    except (TypeError, ValueError):
        return None
    return r if 1 <= r <= 6 else None


def place_of(race_code: str) -> str:
    s = str(race_code)
    return s[8:11] if len(s) >= 11 else "???"


def actual_course(result_course, exhibition_course, lane):
    c = valid_course(result_course)
    if c is not None:
        return c, "result"
    c = valid_course(exhibition_course)
    if c is not None:
        return c, "exhibition"
    c = valid_course(lane)
    if c is not None:
        return c, "lane"
    return None, "missing"


def normalize(values):
    total = sum(values)
    if total <= 0:
        return None
    return [max(0.0, v) / total for v in values]


def global_probs(global_counts, global_n):
    den = global_n + GLOBAL_ALPHA * M
    return [
        (global_counts[i] + GLOBAL_ALPHA) / den
        for i in range(M)
    ]


def method_probs(method_name, k, global_counts, global_n, venue_counts, venue_n):
    gp = global_probs(global_counts, global_n)

    if method_name == "GLOBAL_ONLY":
        return gp

    if method_name == "VENUE_RAW":
        # 生比率のゼロ確率だけを避ける最小限のDirichlet。
        den = venue_n + RAW_ALPHA * M
        if den <= 0:
            return gp
        return [
            (venue_counts[i] + RAW_ALPHA) / den
            for i in range(M)
        ]

    if venue_n <= 0:
        return gp

    den = venue_n + float(k)
    p = [
        (venue_counts[i] + float(k) * gp[i]) / den
        for i in range(M)
    ]
    # 浮動小数誤差だけ整える。
    return normalize(p) or gp


@dataclass
class CalibrationBin:
    n: int = 0
    positives: int = 0
    pred_sum: float = 0.0


@dataclass
class Metrics:
    races: int = 0
    logloss_sum: float = 0.0
    brier_sum: float = 0.0
    actual_prob_sum: float = 0.0
    top_hits: dict = field(default_factory=lambda: {n: 0 for n in TOP_NS})
    actual_ranks: list = field(default_factory=list)
    bins: list = field(default_factory=lambda: [CalibrationBin() for _ in CAL_BINS])
    prob_sum_error_max: float = 0.0

    def add(self, probs, actual_idx):
        if len(probs) != M:
            raise RuntimeError("出目確率が120通りではありません")

        s = sum(probs)
        self.prob_sum_error_max = max(self.prob_sum_error_max, abs(s - 1.0))

        cp = min(max(float(probs[actual_idx]), 1e-15), 1.0)
        self.logloss_sum += -math.log(cp)
        self.actual_prob_sum += probs[actual_idx]

        brier = 0.0
        for i, p in enumerate(probs):
            y = 1.0 if i == actual_idx else 0.0
            brier += (p - y) ** 2
            for bi, (lo, hi) in enumerate(CAL_BINS):
                if lo <= p < hi:
                    b = self.bins[bi]
                    b.n += 1
                    b.pred_sum += p
                    if i == actual_idx:
                        b.positives += 1
                    break
        self.brier_sum += brier

        ordered = sorted(range(M), key=lambda i: (-probs[i], PATTERNS[i]))
        rank = ordered.index(actual_idx) + 1
        self.actual_ranks.append(rank)
        for n in TOP_NS:
            if rank <= n:
                self.top_hits[n] += 1

        self.races += 1

    def summary(self):
        if self.races <= 0:
            return None
        ranks = self.actual_ranks
        return {
            "races": self.races,
            "logloss": self.logloss_sum / self.races,
            "brier": self.brier_sum / self.races,
            "actual_prob": self.actual_prob_sum / self.races,
            "top": {n: self.top_hits[n] / self.races for n in TOP_NS},
            "mean_rank": sum(ranks) / len(ranks),
            "median_rank": statistics.median(ranks),
            "max_sum_error": self.prob_sum_error_max,
        }


def calibration_ece(metrics: Metrics):
    total = sum(b.n for b in metrics.bins)
    if total <= 0:
        return 0.0
    ece = 0.0
    for b in metrics.bins:
        if b.n <= 0:
            continue
        pred = b.pred_sum / b.n
        actual = b.positives / b.n
        ece += (b.n / total) * abs(actual - pred)
    return ece


def load_and_evaluate(p1_start, p1_end, p2_start, p2_end):
    global_counts = [0] * M
    global_n = 0
    venue_counts = defaultdict(lambda: [0] * M)
    venue_n = defaultdict(int)

    metrics = {
        "P1": {name: Metrics() for name, _ in METHODS},
        "P2": {name: Metrics() for name, _ in METHODS},
    }

    stats = defaultdict(int)
    course_source = defaultdict(int)
    venue_n_at_eval = {"P1": [], "P2": []}

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
        cur = conn.cursor(name="base_trifecta_probability_stream")
        cur.itersize = 10000
        cur.execute(sql, (p2_end.isoformat(),))

        current_code = None
        current_date = None
        rows = []

        def process_race(race_date, race_code, race_rows):
            nonlocal global_n
            if not race_rows:
                return

            stats["races_seen"] += 1
            place = place_of(race_code)

            prepared = []
            lanes = []
            rank_to_lane = {}
            rank_counts = defaultdict(int)

            for raw in race_rows:
                lane = valid_course(raw["lane"])
                rank = rank_int(raw["rank"])
                ex_course = valid_course(raw["exhibition_course"])
                ac, src = actual_course(
                    raw["result_course"],
                    raw["exhibition_course"],
                    raw["lane"],
                )
                if ac is not None:
                    course_source[src] += 1

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

            lane_complete = (
                len(prepared) == 6
                and sorted(lanes) == [1, 2, 3, 4, 5, 6]
            )
            top3_valid = all(rank_counts[r] == 1 for r in (1, 2, 3))

            by_lane = {r["lane"]: r for r in prepared if r["lane"] is not None}

            # ------------------------------------------------------------
            # 評価: 現在レースを履歴へ入れる前
            # ------------------------------------------------------------
            period = None
            if p1_start <= race_date <= p1_end:
                period = "P1"
            elif p2_start <= race_date <= p2_end:
                period = "P2"

            if period is not None:
                stats[f"{period}_seen"] += 1

                ex_courses = [r["ex_course"] for r in prepared]
                entry_complete = (
                    lane_complete
                    and all(c is not None for c in ex_courses)
                    and sorted(ex_courses) == [1, 2, 3, 4, 5, 6]
                )

                if not lane_complete:
                    stats[f"{period}_lane_incomplete"] += 1
                elif not top3_valid:
                    stats[f"{period}_top3_invalid"] += 1
                elif not entry_complete:
                    stats[f"{period}_entry_incomplete"] += 1
                elif global_n <= 0:
                    stats[f"{period}_history_empty"] += 1
                else:
                    # 正解は「今回展示進入で見た1着C-2着C-3着C」。
                    actual_pattern = tuple(
                        by_lane[rank_to_lane[r]]["ex_course"]
                        for r in (1, 2, 3)
                    )
                    actual_idx = PATTERN_INDEX.get(actual_pattern)
                    if actual_idx is None:
                        stats[f"{period}_label_invalid"] += 1
                    else:
                        stats[f"{period}_evaluated"] += 1
                        venue_n_at_eval[period].append(venue_n[place])
                        vc = venue_counts[place]
                        vn = venue_n[place]
                        for name, k in METHODS:
                            probs = method_probs(
                                name,
                                k,
                                global_counts,
                                global_n,
                                vc,
                                vn,
                            )
                            metrics[period][name].add(probs, actual_idx)

            # ------------------------------------------------------------
            # 履歴更新: 現在レース結果を予測後に追加
            # ------------------------------------------------------------
            if lane_complete and top3_valid:
                actual_courses = []
                ok = True
                for r in (1, 2, 3):
                    lane = rank_to_lane.get(r)
                    row = by_lane.get(lane)
                    c = row["actual_course"] if row else None
                    if c is None:
                        ok = False
                        break
                    actual_courses.append(c)

                pattern = tuple(actual_courses) if ok else None
                idx = PATTERN_INDEX.get(pattern) if pattern is not None else None
                if idx is not None and len(set(actual_courses)) == 3:
                    global_counts[idx] += 1
                    global_n += 1
                    venue_counts[place][idx] += 1
                    venue_n[place] += 1
                    stats["history_updated"] += 1
                else:
                    stats["history_pattern_invalid"] += 1
            else:
                stats["history_result_invalid"] += 1

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

    return metrics, stats, course_source, venue_n_at_eval, global_n


def print_table(period, metrics):
    print(f"\n【{period}】")
    print(
        "方式             R数   LogLoss   Brier120  正解平均P  "
        "Top1   Top3   Top5   Top10  Top20  平均順位  中央順位"
    )
    print("-" * 126)
    for name, _ in METHODS:
        s = metrics[name].summary()
        if s is None:
            continue
        print(
            f"{name:<14} {s['races']:>5}  {s['logloss']:.6f}  {s['brier']:.6f}  "
            f"{s['actual_prob']*100:>7.3f}%  "
            f"{s['top'][1]*100:>5.2f}%  {s['top'][3]*100:>5.2f}%  "
            f"{s['top'][5]*100:>5.2f}%  {s['top'][10]*100:>5.2f}%  "
            f"{s['top'][20]*100:>5.2f}%  {s['mean_rank']:>7.2f}  {s['median_rank']:>7.1f}"
        )


def print_calibration(title, metric):
    print(f"\n【{title} calibration：120候補を二値として集計】")
    print("確率帯             件数     平均予測      実的中率      実績-予測")
    print("-" * 78)
    for (lo, hi), b in zip(CAL_BINS, metric.bins):
        if b.n <= 0:
            continue
        pred = b.pred_sum / b.n
        actual = b.positives / b.n
        if hi >= 1.0:
            label = f">={lo*100:.1f}%"
        else:
            label = f"{lo*100:.2f}-{hi*100:.2f}%"
        print(
            f"{label:<16} {b.n:>8}   {pred*100:>8.3f}%   "
            f"{actual*100:>8.3f}%   {(actual-pred)*100:>+9.3f}pt"
        )
    print(f"ECE: {calibration_ece(metric)*100:.3f}pt")


def main():
    if len(sys.argv) != 5:
        print(
            "Usage: python3 analysis/base_trifecta_probability_compare.py "
            "P1_START P1_END P2_START P2_END"
        )
        sys.exit(1)

    p1_start = parse_date(sys.argv[1])
    p1_end = parse_date(sys.argv[2])
    p2_start = parse_date(sys.argv[3])
    p2_end = parse_date(sys.argv[4])

    if not (p1_start <= p1_end < p2_start <= p2_end):
        raise RuntimeError("期間は P1開始 <= P1終了 < P2開始 <= P2終了 にしてください")

    print("DBから120通りの基礎出目確率をローリング構築中...")
    metrics, stats, course_source, venue_n_at_eval, final_global_n = load_and_evaluate(
        p1_start, p1_end, p2_start, p2_end
    )

    p1_rows = []
    for name, _ in METHODS:
        s = metrics["P1"][name].summary()
        if s is not None:
            p1_rows.append((s["logloss"], s["brier"], name))
    if not p1_rows:
        raise RuntimeError("P1評価可能レースが0件です")

    p1_rows.sort()
    selected = p1_rows[0][2]

    print("=" * 132)
    print("出目確率 STEP 1：場×3連単コースパターン 基礎確率")
    print("=" * 132)
    print(f"学習/方式選択(P1) : {p1_start} ～ {p1_end}")
    print(f"完全ホールドアウト(P2): {p2_start} ～ {p2_end}")
    print("出目数             : 6P3 = 120通り")
    print("基礎軸             : 1着C-2着C-3着C の場別履歴")
    print("全場prior          : Dirichlet alpha=0.5")
    print("場別平滑化候補     : GLOBAL / RAW / K50 / K100 / K300 / K1000 / K3000")
    print("方式選択           : P1 Multiclass LogLoss優先、Brier第2基準")
    print("対象進入           : 展示進入1～6完全レース")
    print("履歴実コース       : result_detail -> exhibition_live -> 枠番")
    print("未来情報           : 対象結果を履歴更新する前に予測")
    print("120通り合計        : 100%")
    print("本番Web変更        : なし")

    print("\n【評価母集団 / coverage】")
    for p in ("P1", "P2"):
        seen = stats[f"{p}_seen"]
        eva = stats[f"{p}_evaluated"]
        pct = eva / seen * 100 if seen else 0.0
        venue_samples = venue_n_at_eval[p]
        avg_vn = sum(venue_samples) / len(venue_samples) if venue_samples else 0.0
        print(
            f"{p}: seen={seen}R / evaluated={eva}R ({pct:.2f}%) / "
            f"lane不足={stats[f'{p}_lane_incomplete']} / "
            f"Top3不正={stats[f'{p}_top3_invalid']} / "
            f"展示進入不足={stats[f'{p}_entry_incomplete']} / "
            f"平均場履歴N={avg_vn:.1f}"
        )

    print(
        "履歴実コースsource  : "
        + " / ".join(f"{k}={v}" for k, v in sorted(course_source.items()))
    )
    print(f"最終global履歴N     : {final_global_n}")
    print(f"履歴更新レース      : {stats['history_updated']}")
    print(f"履歴pattern不正     : {stats['history_pattern_invalid']}")
    print(f"履歴結果不正        : {stats['history_result_invalid']}")

    print_table("P1 方式選択用", metrics["P1"])
    print(f"\n【P1で選択した方式】 {selected}")

    print_table("P2 ホールドアウト（最重要）", metrics["P2"])

    p2_selected = metrics["P2"][selected].summary()
    global_p2 = metrics["P2"]["GLOBAL_ONLY"].summary()
    if p2_selected and global_p2:
        print(f"\n【最重要: P2 GLOBAL_ONLY → {selected}】")
        print(f"LogLoss差       : {p2_selected['logloss']-global_p2['logloss']:+.6f} （マイナスが改善）")
        print(f"Brier120差      : {p2_selected['brier']-global_p2['brier']:+.6f} （マイナスが改善）")
        print(f"正解平均P差     : {(p2_selected['actual_prob']-global_p2['actual_prob'])*100:+.3f}pt")
        for n in TOP_NS:
            print(
                f"Top{n:<2}的中率差   : "
                f"{(p2_selected['top'][n]-global_p2['top'][n])*100:+.2f}pt"
            )
        print(f"平均順位差       : {p2_selected['mean_rank']-global_p2['mean_rank']:+.2f} （マイナスが改善）")
        print(f"120通り合計誤差max: {p2_selected['max_sum_error']:.3e}")

    print_calibration(f"P2 {selected}", metrics["P2"][selected])

    print("\n【判断方針】")
    print("1. P1だけで場別平滑化Kを選ぶ")
    print("2. P2でGLOBAL_ONLYよりLogLoss/Brierが改善するか")
    print("3. Top5/Top10/Top20で場別情報の追加価値があるか")
    print("4. 120候補の校正が極端に崩れていないか")
    print("5. 基礎出目確率を固定後、STEP2で補正後1着率とAI3連対率を追加する")
    print("=" * 132)


if __name__ == "__main__":
    main()
