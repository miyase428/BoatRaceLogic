#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
1号艇1着時の2着率：選手×実コース ベイズ平滑化K比較。

目的
----
- 1号艇が実際に1着だったレースだけを評価する。
- 残り5艇について、未来情報を使わず以下を比較する。

  COURSE_ONLY:
      p0 = 過去の「1号艇1着時の2着実コース分布」

  K=5/10/20/30:
      p_pc = (選手の2着数 + K * p0) / (対象数 + K)

  各方式とも残り5艇を最後に100%正規化する。

- 選手履歴は各評価時点の直前100走。
- 選手×実コースの対象履歴は
    「1号艇が1着」かつ「今回と同じ実コース」
  のみ。
- p0も対象レースより前だけで更新する。
- 実コースは result_detail -> exhibition_live -> lane の順で復元する。
- 評価レースは1着・2着が一意、6艇、実コース1～6が復元できるものだけ。

固定評価期間
------------
P1: 2026-06-15 ～ 2026-07-14
P2: 2026-07-15 ～ 2026-08-14
POOLED: P1 + P2

Usage:
    python3 analysis/second_place_head1_k_compare.py
"""

from __future__ import annotations

import math
from collections import Counter, defaultdict, deque
from dataclasses import dataclass
from datetime import date, timedelta
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parent))
from slit_validate_v2 import connect_db


P1_START = date(2026, 6, 15)
P1_END = date(2026, 7, 14)
P2_START = date(2026, 7, 15)
P2_END = date(2026, 8, 14)
HISTORY_DAYS = 730
K_VALUES = (5.0, 10.0, 20.0, 30.0)
EPS = 1e-12


@dataclass
class CandidateSnapshot:
    lane: int
    course: int
    y: int
    p0: float
    n_pc: int
    w_pc: int


@dataclass
class RaceSnapshot:
    race_code: str
    race_date: object
    candidates: list[CandidateSnapshot]


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


def player_counts(history, target_course):
    n = 0
    w = 0
    for h in history:
        if not h["eligible_head1"]:
            continue
        if h["course"] != target_course:
            continue
        n += 1
        w += h["second"]
    return n, w


def prior_course_rate(second_course_hist, head1_hist_n, course):
    if head1_hist_n > 0:
        return second_course_hist[course] / head1_hist_n
    # 評価開始時点では730日分を先読みせず蓄積しているため通常ここには来ない。
    # 万一履歴ゼロなら全6コース一様を仮置きし、5艇正規化で均等になる。
    return 1.0 / 6.0


def period_name(race_date):
    if P1_START <= race_date <= P1_END:
        return "P1"
    if P2_START <= race_date <= P2_END:
        return "P2"
    return None


def load_snapshots():
    history_start = P1_START - timedelta(days=HISTORY_DAYS)
    eval_end = P2_END

    player_hist = defaultdict(lambda: deque(maxlen=100))
    second_course_hist = Counter()
    head1_hist_n = 0

    snapshots = {"P1": [], "P2": []}
    skipped = Counter()
    course_source = Counter()

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
        WHERE rm.race_date BETWEEN %s::date AND %s::date
        ORDER BY rm.race_date, re.race_code, re.lane_number
    """

    def process_race(race_date, race_code, rows):
        nonlocal head1_hist_n

        if not rows:
            return

        prepared = []
        for lane, player_id, rank, result_course, exhibition_course in rows:
            c, source = actual_course(result_course, exhibition_course, lane)
            if c is not None:
                course_source[source] += 1
            prepared.append({
                "lane": valid_course(lane),
                "player_id": str(player_id or "").strip(),
                "rank": as_int(rank),
                "course": c,
            })

        six_rows = len(prepared) == 6
        lanes = [r["lane"] for r in prepared]
        lanes_ok = six_rows and sorted(c for c in lanes if c is not None) == [1, 2, 3, 4, 5, 6]

        winners = [r for r in prepared if r["rank"] == 1]
        seconds = [r for r in prepared if r["rank"] == 2]
        top2_ok = len(winners) == 1 and len(seconds) == 1
        head1_win = top2_ok and winners[0]["lane"] == 1

        courses = [r["course"] for r in prepared]
        course_complete = (
            six_rows
            and all(c is not None for c in courses)
            and sorted(courses) == [1, 2, 3, 4, 5, 6]
        )

        pname = period_name(race_date)
        if pname is not None:
            if not lanes_ok:
                skipped[f"{pname}_entry_not_6"] += 1
            elif not top2_ok:
                skipped[f"{pname}_top2_not_unique"] += 1
            elif not head1_win:
                skipped[f"{pname}_not_head1_win"] += 1
            elif not course_complete:
                skipped[f"{pname}_course_incomplete"] += 1
            else:
                second_lane = seconds[0]["lane"]
                candidates = []
                for r in prepared:
                    if r["lane"] == 1:
                        continue
                    p0 = prior_course_rate(second_course_hist, head1_hist_n, r["course"])
                    n_pc, w_pc = player_counts(player_hist[r["player_id"]], r["course"])
                    candidates.append(CandidateSnapshot(
                        lane=r["lane"],
                        course=r["course"],
                        y=1 if r["lane"] == second_lane else 0,
                        p0=p0,
                        n_pc=n_pc,
                        w_pc=w_pc,
                    ))

                if len(candidates) == 5 and sum(c.y for c in candidates) == 1:
                    candidates.sort(key=lambda x: x.lane)
                    snapshots[pname].append(RaceSnapshot(race_code, race_date, candidates))
                else:
                    skipped[f"{pname}_snapshot_invalid"] += 1

        # ----- ここから現在レース終了後の履歴更新。予測には使わない -----
        # p0はクリーンな「1号艇1着 + 1/2着一意 + 実コース完全」だけで更新する。
        if lanes_ok and top2_ok and head1_win and course_complete:
            second_course = seconds[0]["course"]
            if second_course is not None:
                head1_hist_n += 1
                second_course_hist[second_course] += 1

        # 選手直近100走は全レースを走数として積む。
        # 条件付き2着率の機会として数えるかは eligible_head1 で後から判定する。
        eligible_head1 = lanes_ok and top2_ok and head1_win
        for r in prepared:
            pid = r["player_id"]
            if not pid or r["course"] is None:
                continue
            player_hist[pid].append({
                "course": r["course"],
                "eligible_head1": eligible_head1,
                "second": 1 if eligible_head1 and r["rank"] == 2 else 0,
            })

    with connect_db() as conn:
        cur = conn.cursor(name="head1_second_k_compare_stream")
        cur.itersize = 10000
        cur.execute(sql, (history_start.isoformat(), eval_end.isoformat()))

        current_code = None
        current_date = None
        rows = []

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

    meta = {
        "history_start": history_start,
        "history_head1_n": head1_hist_n,
        "history_second_course": dict(second_course_hist),
        "course_source": dict(course_source),
        "skipped": dict(skipped),
    }
    return snapshots, meta


def normalize(scores):
    total = sum(max(0.0, s) for s in scores)
    if total <= 0:
        return [1.0 / len(scores)] * len(scores)
    return [max(0.0, s) / total for s in scores]


def method_probs(race, method):
    scores = []
    if method == "COURSE_ONLY":
        scores = [c.p0 for c in race.candidates]
    else:
        k = float(method.split("=", 1)[1])
        for c in race.candidates:
            p = (c.w_pc + k * c.p0) / (c.n_pc + k)
            scores.append(p)
    return normalize(scores)


def evaluate(races, method):
    if not races:
        return None

    brier_sum = 0.0
    logloss_sum = 0.0
    top1_hit = 0
    top2_hit = 0
    actual_prob_sum = 0.0

    for race in races:
        probs = method_probs(race, method)
        rows = []
        actual_idx = None

        for idx, (c, p) in enumerate(zip(race.candidates, probs)):
            brier_sum += (p - c.y) ** 2
            rows.append((p, c.lane, c.y))
            if c.y == 1:
                actual_idx = idx

        if actual_idx is None:
            continue

        p_actual = max(probs[actual_idx], EPS)
        actual_prob_sum += probs[actual_idx]
        logloss_sum += -math.log(p_actual)

        ranked = sorted(rows, key=lambda x: (-x[0], x[1]))
        if ranked[0][2] == 1:
            top1_hit += 1
        if any(x[2] == 1 for x in ranked[:2]):
            top2_hit += 1

    n = len(races)
    return {
        "races": n,
        "brier": brier_sum / (n * 5),
        "logloss": logloss_sum / n,
        "top1": top1_hit / n,
        "top2": top2_hit / n,
        "actual_p": actual_prob_sum / n,
    }


def delta_pct(value, base):
    if base == 0:
        return 0.0
    return (value - base) / base * 100.0


def print_period(title, races):
    methods = ["COURSE_ONLY"] + [f"K={int(k)}" for k in K_VALUES]
    results = {m: evaluate(races, m) for m in methods}
    base = results["COURSE_ONLY"]

    print("\n" + "=" * 118)
    print(title)
    print("=" * 118)
    print(f"評価レース: {len(races)}")
    print("method        Brier      vsCourse    LogLoss     vsCourse    Top1      Top2      実2着平均P")
    print("-" * 118)

    for m in methods:
        r = results[m]
        if r is None:
            continue
        db = 0.0 if m == "COURSE_ONLY" else delta_pct(r["brier"], base["brier"])
        dl = 0.0 if m == "COURSE_ONLY" else delta_pct(r["logloss"], base["logloss"])
        print(
            f"{m:<12} "
            f"{r['brier']:.6f}  {db:+8.3f}%  "
            f"{r['logloss']:.6f}  {dl:+8.3f}%  "
            f"{r['top1']*100:7.2f}%  "
            f"{r['top2']*100:7.2f}%  "
            f"{r['actual_p']*100:9.2f}%"
        )

    ranked = sorted(
        ((results[m]["brier"], results[m]["logloss"], m) for m in methods if results[m] is not None),
        key=lambda x: (x[0], x[1], x[2]),
    )
    print(f"Brier最良: {ranked[0][2]}")
    return results


def pooled_races(snapshots):
    return snapshots["P1"] + snapshots["P2"]


def main():
    print("=" * 118)
    print("1号艇1着時の2着率：選手×実コース K固定比較（2期間）")
    print("=" * 118)
    print(f"P1: {P1_START} ～ {P1_END}")
    print(f"P2: {P2_START} ～ {P2_END}")
    print(f"履歴読込開始: {P1_START - timedelta(days=HISTORY_DAYS)}")
    print("選手履歴: 各評価時点の直前100走")
    print("K候補: 5 / 10 / 20 / 30（この比較では固定）")
    print("場別細分化: なし")
    print("最終出力: 残り5艇を100%正規化")

    snapshots, meta = load_snapshots()

    p1 = print_period("P1 2026-06-15 ～ 2026-07-14", snapshots["P1"])
    p2 = print_period("P2 2026-07-15 ～ 2026-08-14", snapshots["P2"])
    pp = print_period("POOLED P1+P2", pooled_races(snapshots))

    methods = [f"K={int(k)}" for k in K_VALUES]
    consistent = []
    for m in methods:
        if (
            p1[m]["brier"] < p1["COURSE_ONLY"]["brier"]
            and p2[m]["brier"] < p2["COURSE_ONLY"]["brier"]
            and p1[m]["logloss"] <= p1["COURSE_ONLY"]["logloss"]
            and p2[m]["logloss"] <= p2["COURSE_ONLY"]["logloss"]
        ):
            consistent.append(m)

    print("\n" + "=" * 118)
    print("固定K判断メモ")
    print("=" * 118)
    if consistent:
        best = min(consistent, key=lambda m: (pp[m]["brier"], pp[m]["logloss"], m))
        print("両期間で COURSE_ONLY より Brier改善、かつ LogLoss非悪化:", ", ".join(consistent))
        print(f"その中のPOOLED最良候補: {best}")
    else:
        print("両期間で安定して COURSE_ONLY を上回る固定Kはありません。選手補正は保留候補。")

    print("\n【評価対象数】")
    print(f"P1: {len(snapshots['P1'])}")
    print(f"P2: {len(snapshots['P2'])}")
    print(f"POOLED: {len(pooled_races(snapshots))}")

    print("\n【除外内訳】")
    for key, value in sorted(meta["skipped"].items()):
        print(f"{key}: {value}")

    print("\n【実コース復元source（履歴全体）】")
    for key, value in sorted(meta["course_source"].items()):
        print(f"{key}: {value}")

    print("\n【注意】")
    print("・ここでは展示/SUM/スリット等の当日補正はまだ入れていない")
    print("・まず『コース基礎率 + 選手×実コース』だけで選手補正が再現性を持つかを見る")
    print("・Kはこの結果を見て1つだけ採用し、追加の細かいK探索は原則しない")
    print("=" * 118)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
