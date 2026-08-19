#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
AI3連対率 STEP 1: 展示なし基礎3連対率・平滑化/300%正規化比較

目的
----
- 展示性能を使わず、各艇が「3着以内に入る確率」の基準値を作る。
- 未来情報を使わず、対象レース以前のデータだけでローリング評価する。
- 基礎1着率と同じ3階層を使う。
    1) 場×コース             : 対象レース以前の全履歴
    2) 選手×コース           : 選手の直前100走の中から同コース
    3) 選手×場×コース       : 同じ直前100走の中から同場×同コース
- 3連対 = 実着順1～3着。4着以下/NULLは0扱い。
- 今回コースは「展示なし基礎」なので枠番=コース。
- 過去実コースは result_detail -> exhibition_live -> lane の順で復元する。

比較方式
--------
- VENUE_ONLY : 場×コースのみ
- RAW_HIER   : 選手×場×C raw > 選手×C raw > 場×C（参考）
- BB_LIGHT   : Kpc=10, Kpvc=3
- BB_MEDIUM  : Kpc=20, Kpvc=10
- BB_STRONG  : Kpc=40, Kpvc=20

各方式について
- RAW     : 6艇を正規化しない
- NORM300 : 6艇合計を300%へ正規化（各艇100%上限の制約付き）
を比較する。

主な評価
--------
- Brier / LogLoss / ECE
- 6艇確率合計
- 予測上位3艇に実Top3が何艇含まれるか
- 上位3艇セット完全一致率
- 確率順位1～6位の実3連対率
- 確率帯 calibration

Usage:
    python3 analysis/base_trio_rate_compare.py 2026-06-15 2026-07-14
    python3 analysis/base_trio_rate_compare.py 2026-07-15 2026-08-14
"""

from __future__ import annotations

import math
import sys
from collections import defaultdict, deque
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from slit_validate_v2 import connect_db


FIXED_METHODS = {
    "BB_LIGHT": (10.0, 3.0),
    "BB_MEDIUM": (20.0, 10.0),
    "BB_STRONG": (40.0, 20.0),
}

CALIB_BINS = (
    (0.00, 0.10),
    (0.10, 0.20),
    (0.20, 0.30),
    (0.30, 0.40),
    (0.40, 0.50),
    (0.50, 0.60),
    (0.60, 0.70),
    (0.70, 0.80),
    (0.80, 0.90),
    (0.90, 1.0000001),
)


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
    c = as_int(v)
    return c if c is not None and 1 <= c <= 6 else None


def parse_date(value: str):
    return datetime.strptime(value, "%Y-%m-%d").date()


def parse_args():
    if len(sys.argv) != 3:
        print(
            "Usage: python3 analysis/base_trio_rate_compare.py "
            "YYYY-MM-DD YYYY-MM-DD"
        )
        sys.exit(2)

    start = parse_date(sys.argv[1])
    end = parse_date(sys.argv[2])
    if start > end:
        raise RuntimeError("開始日が終了日より後です")
    return start, end


def place_of(race_code: str) -> str:
    code = str(race_code or "")
    return code[8:11] if len(code) >= 11 else "???"


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

    # 全6艇の3連対確率合計は300%なので、履歴ゼロ時の中立値は50%。
    return 0.5


def hist_counts(history, course, place_code):
    n_pc = 0
    w_pc = 0
    n_pvc = 0
    w_pvc = 0

    for h in history:
        if h["course"] != course:
            continue

        n_pc += 1
        w_pc += h["top3"]

        if h["place"] == place_code:
            n_pvc += 1
            w_pvc += h["top3"]

    return n_pc, w_pc, n_pvc, w_pvc


def top3_result_is_valid(race_rows):
    ranks = [as_int(r[2]) for r in race_rows]
    return (
        ranks.count(1) == 1
        and ranks.count(2) == 1
        and ranks.count(3) == 1
    )


def load_snapshots(eval_start, eval_end):
    """
    DBを時系列で1回走査する。
    予測snapshotを作った後にそのレース結果を履歴へ追加するため未来情報は入らない。
    """

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
        cur = conn.cursor(name="base_trio_rate_stream")
        cur.itersize = 10000
        cur.execute(sql, (eval_end.isoformat(),))

        current_code = None
        current_date = None
        rows = []

        def process_race(race_date, race_code, race_rows):
            if not race_rows:
                return

            place_code = place_of(race_code)
            lanes = [valid_course(r[0]) for r in race_rows]
            lane_complete = (
                len(race_rows) == 6
                and all(c is not None for c in lanes)
                and sorted(lanes) == [1, 2, 3, 4, 5, 6]
            )
            top3_valid = top3_result_is_valid(race_rows)

            # ---- 予測snapshot（レース結果を履歴へ入れる前） ----
            if race_date >= eval_start:
                if lane_complete and top3_valid:
                    boats = []
                    for lane, player_id, rank, result_course, exhibition_course in race_rows:
                        lane = valid_course(lane)
                        pid = str(player_id or "").strip()
                        if lane is None or not pid:
                            continue

                        # 展示なし基礎なので今回コースは枠番=コース。
                        target_course = lane
                        p0 = prior_rate(
                            place_code,
                            target_course,
                            venue_n,
                            venue_w,
                            global_n,
                            global_w,
                        )
                        n_pc, w_pc, n_pvc, w_pvc = hist_counts(
                            player_hist[pid],
                            target_course,
                            place_code,
                        )
                        boats.append(
                            BoatSnapshot(
                                lane=lane,
                                y=1 if as_int(rank) in (1, 2, 3) else 0,
                                p0=p0,
                                n_pc=n_pc,
                                w_pc=w_pc,
                                n_pvc=n_pvc,
                                w_pvc=w_pvc,
                            )
                        )

                    if len(boats) == 6:
                        boats.sort(key=lambda x: x.lane)
                        if sum(b.y for b in boats) == 3:
                            snapshots.append(
                                RaceSnapshot(
                                    race_code=str(race_code),
                                    race_date=race_date,
                                    boats=boats,
                                )
                            )
                        else:
                            skipped["snapshot_top3_not_3"] += 1
                    else:
                        skipped["snapshot_not_6"] += 1
                else:
                    if not lane_complete:
                        skipped["entry_not_6"] += 1
                    if not top3_valid:
                        skipped["top3_result_invalid"] += 1

            # ---- 場×コース prior 更新 ----
            # 実進入が1～6で完全に復元でき、実Top3も一意なレースだけ採用。
            prepared = []
            for lane, player_id, rank, result_course, exhibition_course in race_rows:
                c, source = actual_course(result_course, exhibition_course, lane)
                prepared.append(
                    {
                        "player_id": str(player_id or "").strip(),
                        "rank": as_int(rank),
                        "course": c,
                        "source": source,
                    }
                )

            actual_courses = [r["course"] for r in prepared]
            actual_complete = (
                len(prepared) == 6
                and all(c is not None for c in actual_courses)
                and sorted(actual_courses) == [1, 2, 3, 4, 5, 6]
            )

            if lane_complete and top3_valid and actual_complete:
                for r in prepared:
                    c = r["course"]
                    y = 1 if r["rank"] in (1, 2, 3) else 0
                    venue_n[place_code][c] += 1
                    venue_w[place_code][c] += y
                    global_n[c] += 1
                    global_w[c] += y
            elif top3_valid and not actual_complete:
                skipped["venue_actual_course_incomplete"] += 1

            # ---- 各選手の直前100走更新 ----
            # 100走の母数は全出走。rank NULLは4着以下と同様にtop3=0。
            for r in prepared:
                pid = r["player_id"]
                c = r["course"]
                if not pid or c is None:
                    skipped["player_course_missing"] += 1
                    continue

                course_source[r["source"]] += 1
                player_hist[pid].append(
                    {
                        "place": place_code,
                        "course": c,
                        "top3": 1 if r["rank"] in (1, 2, 3) else 0,
                    }
                )

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

            rows.append(
                (lane, player_id, rank, result_course, exhibition_course)
            )

        if current_code is not None:
            process_race(current_date, current_code, rows)

        cur.close()

    return snapshots, course_source, skipped


def prob_venue(b):
    return b.p0


def prob_raw_hier(b):
    if b.n_pvc > 0:
        return b.w_pvc / b.n_pvc
    if b.n_pc > 0:
        return b.w_pc / b.n_pc
    return b.p0


def prob_beta(b, k_pc, k_pvc):
    p_pc = (b.w_pc + k_pc * b.p0) / (b.n_pc + k_pc)
    return (b.w_pvc + k_pvc * p_pc) / (b.n_pvc + k_pvc)


def method_probs(race, method_name):
    out = []
    for b in race.boats:
        if method_name == "VENUE_ONLY":
            p = prob_venue(b)
        elif method_name == "RAW_HIER":
            p = prob_raw_hier(b)
        else:
            p = prob_beta(b, *FIXED_METHODS[method_name])
        out.append(float(p))
    return out


def normalize_300(probs):
    """
    比率をできるだけ保ちながら sum=3.0、各艇0～1の制約へ投影する。
    単純な3/sum倍率で100%を超える艇が出る場合は100%に固定し、
    残りを再配分する。
    """
    n = len(probs)
    if n == 0:
        return []

    weights = [max(0.0, float(p)) for p in probs]
    out = [0.0] * n
    remaining = set(range(n))
    target = min(3.0, float(n))

    while remaining:
        if target <= 1e-12:
            break

        total_w = sum(weights[i] for i in remaining)
        if total_w <= 1e-12:
            equal = target / len(remaining)
            equal = min(1.0, equal)
            for i in remaining:
                out[i] = equal
            target -= equal * len(remaining)
            break

        scale = target / total_w
        capped = [i for i in remaining if weights[i] * scale >= 1.0]

        if not capped:
            for i in remaining:
                out[i] = weights[i] * scale
            target = 0.0
            break

        for i in capped:
            out[i] = 1.0
            target -= 1.0
            remaining.remove(i)

    # 浮動小数誤差の最終補正（通常は不要）。
    diff = 3.0 - sum(out)
    if abs(diff) > 1e-10:
        adjustable = [i for i, p in enumerate(out) if 0.0 < p < 1.0]
        if adjustable:
            per = diff / len(adjustable)
            for i in adjustable:
                out[i] = min(1.0, max(0.0, out[i] + per))

    return out


def clipped(p):
    return min(max(float(p), 1e-9), 1.0 - 1e-9)


def in_bin(value, low, high):
    return low <= value < high


def evaluate(races, method_name, normalized):
    rows = []
    race_rows = []
    brier_sum = 0.0
    logloss_sum = 0.0
    top3_slot_hits = 0
    exact_set_hits = 0
    sum6_total = 0.0

    for race in races:
        probs = method_probs(race, method_name)
        if normalized:
            probs = normalize_300(probs)

        sum6_total += sum(probs)

        order = sorted(range(6), key=lambda i: (-probs[i], race.boats[i].lane))
        rank_by_idx = {idx: rank + 1 for rank, idx in enumerate(order)}

        pred_top3 = set(order[:3])
        actual_top3 = {i for i, b in enumerate(race.boats) if b.y == 1}
        top3_slot_hits += len(pred_top3 & actual_top3)
        exact_set_hits += int(pred_top3 == actual_top3)

        for idx, (p, b) in enumerate(zip(probs, race.boats)):
            cp = clipped(p)
            brier_sum += (p - b.y) ** 2
            logloss_sum += -(b.y * math.log(cp) + (1 - b.y) * math.log(1 - cp))
            rows.append(
                {
                    "p": p,
                    "y": b.y,
                    "lane": b.lane,
                    "prob_rank": rank_by_idx[idx],
                    "race_code": race.race_code,
                }
            )

        race_rows.append(
            {
                "race_code": race.race_code,
                "slot_hits": len(pred_top3 & actual_top3),
                "exact": int(pred_top3 == actual_top3),
            }
        )

    boat_n = len(rows)
    race_n = len(races)
    if boat_n == 0 or race_n == 0:
        return None

    _, ece = calibration(rows)

    return {
        "method": method_name,
        "normalized": normalized,
        "races": race_n,
        "boats": boat_n,
        "brier": brier_sum / boat_n,
        "logloss": logloss_sum / boat_n,
        "ece": ece,
        "avg_sum6": sum6_total / race_n,
        "top3_slot": top3_slot_hits / (race_n * 3),
        "exact_set": exact_set_hits / race_n,
        "rows": rows,
        "race_rows": race_rows,
    }


def calibration(rows):
    table = []
    total_n = len(rows)
    weighted_gap = 0.0

    for low, high in CALIB_BINS:
        selected = [r for r in rows if in_bin(r["p"], low, high)]
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


def print_summary(results):
    print("\n【方式比較】")
    print(
        "方式             正規化   R数     Brier     LogLoss      ECE    "
        "平均Σ6    上位3艇捕捉率  Top3完全一致"
    )
    print("-" * 116)

    for m in results:
        norm = "300%" if m["normalized"] else "RAW"
        print(
            f"{m['method']:<16} {norm:<6} "
            f"{m['races']:>5}   "
            f"{m['brier']:.6f}   "
            f"{m['logloss']:.6f}   "
            f"{m['ece']*100:>6.2f}pt   "
            f"{m['avg_sum6']*100:>7.2f}%   "
            f"{m['top3_slot']*100:>8.2f}%      "
            f"{m['exact_set']*100:>8.2f}%"
        )


def print_raw_vs_norm(results):
    by_method = defaultdict(dict)
    for m in results:
        by_method[m["method"]]["NORM" if m["normalized"] else "RAW"] = m

    print("\n【RAW → 300%正規化の差】")
    print("方式             Brier差     LogLoss差     ECE差      Top3捕捉差   完全一致差")
    print("-" * 94)
    for method in ("VENUE_ONLY", "RAW_HIER", "BB_LIGHT", "BB_MEDIUM", "BB_STRONG"):
        raw = by_method[method].get("RAW")
        norm = by_method[method].get("NORM")
        if raw is None or norm is None:
            continue
        print(
            f"{method:<16} "
            f"{norm['brier']-raw['brier']:+.6f}   "
            f"{norm['logloss']-raw['logloss']:+.6f}   "
            f"{(norm['ece']-raw['ece'])*100:+7.2f}pt   "
            f"{(norm['top3_slot']-raw['top3_slot'])*100:+8.2f}pt   "
            f"{(norm['exact_set']-raw['exact_set'])*100:+8.2f}pt"
        )


def print_calibration(rows):
    table, ece = calibration(rows)
    print("\n【最良方式の確率calibration】")
    print("確率帯       舟数    実Top3   平均予測    実3連対率    実績-予測")
    print("-" * 78)
    for low, high, n, hits, pred, actual, gap in table:
        label = f"{low*100:>2.0f}-{min(high,1.0)*100:<3.0f}%"
        if n == 0:
            print(f"{label:<10} {0:>6} {0:>8}       -          -          -")
            continue
        print(
            f"{label:<10} {n:>6} {hits:>8}   "
            f"{pred*100:>8.2f}%   {actual*100:>8.2f}%   {gap*100:>+9.2f}pt"
        )
    print(f"ECE: {ece*100:.3f}pt")


def print_rank_table(rows):
    print("\n【最良方式の確率順位別 実3連対率】")
    print("順位     舟数   実Top3   平均予測    実3連対率    実績-予測")
    print("-" * 76)
    for rank in range(1, 7):
        selected = [r for r in rows if r["prob_rank"] == rank]
        if not selected:
            continue
        n = len(selected)
        hits = sum(r["y"] for r in selected)
        pred = sum(r["p"] for r in selected) / n
        actual = hits / n
        print(
            f"{rank:>2}位   {n:>6}   {hits:>6}   "
            f"{pred*100:>8.2f}%   {actual*100:>8.2f}%   "
            f"{(actual-pred)*100:>+9.2f}pt"
        )


def main():
    eval_start, eval_end = parse_args()

    print("=" * 118)
    print("AI3連対率 STEP 1：展示なし基礎3連対率 平滑化 / 300%正規化比較")
    print("=" * 118)
    print(f"評価期間              : {eval_start} ～ {eval_end}")
    print("今回コース            : 枠番=コース（展示性能・展示進入は基礎段階では不使用）")
    print("過去実コース          : result_detail → exhibition_live → lane")
    print("選手履歴              : 対象レース直前100走")
    print("3連対label             : 1～3着=1 / 4着以下・NULL=0")
    print("評価                   : Brier / LogLoss / ECE / 上位3艇捕捉 / Top3完全一致")
    print("本番Web変更            : なし")

    snapshots, course_source, skipped = load_snapshots(eval_start, eval_end)

    if not snapshots:
        raise RuntimeError("評価可能なレースがありません")

    results = []
    for method in ("VENUE_ONLY", "RAW_HIER", "BB_LIGHT", "BB_MEDIUM", "BB_STRONG"):
        for normalized in (False, True):
            m = evaluate(snapshots, method, normalized)
            if m is not None:
                results.append(m)

    print(f"\n評価可能レース        : {len(snapshots)}")
    print(
        "履歴実コースsource    : "
        f"result={course_source['result']} / "
        f"exhibition={course_source['exhibition']} / "
        f"lane={course_source['lane_fallback']}"
    )
    if skipped:
        print("skip                   : " + ", ".join(
            f"{k}={v}" for k, v in sorted(skipped.items())
        ))

    print_summary(results)
    print_raw_vs_norm(results)

    # 基礎確率としてBrier最小を第一基準、同値ならLogLoss/ECEで選ぶ。
    best = min(
        results,
        key=lambda m: (m["brier"], m["logloss"], m["ece"], m["method"], m["normalized"]),
    )
    print(
        "\n【現時点の最良（Brier優先）】 "
        f"{best['method']} / {'NORM300' if best['normalized'] else 'RAW'} "
        f"/ Brier={best['brier']:.6f} / LogLoss={best['logloss']:.6f} "
        f"/ ECE={best['ece']*100:.2f}pt"
    )

    print_calibration(best["rows"])
    print_rank_table(best["rows"])

    print("\n【判断の順番】")
    print("1. P1/P2の両期間でBB系がVENUE_ONLY/RAW_HIERを上回るか")
    print("2. RAWとNORM300のどちらがBrier・LogLoss・ECEで安定するか")
    print("3. KはLIGHT/MEDIUM/STRONGのうち両期間で安定するものを採用する")
    print("4. ここで基礎3連対率を確定してから、一次/二次/展示/SUM/スリットをAI特徴量へ追加する")
    print("=" * 118)


if __name__ == "__main__":
    main()
