#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
決まり手集計の「現行方式 vs race_entry再構築方式」を固定2期間で比較する高速版。

目的
- 現行: race_result_detail の本人行だけを母集団にする方式を再現。
- 再構築: race_entry を母集団にし、完了レースのみ、実進入は
  race_result_detail を優先し、本人行欠損時だけ exhibition_live で補う。
- 各対象レース日より前6ヶ月だけで履歴率を作り、対象レースの実際の決まり手を
  未来データとして Brier score で比較する。

高速化方針
- SQLで「対象レースごと × 過去6ヶ月」を都度Range JOINしない。
- 対象期間に必要な履歴をDBから各1回だけ取得する。
- Python側で選手×コースごとに日付順prefixを作り、bisectで6ヶ月窓を切る。

固定期間（事前固定）
P1: 2026-06-15 ～ 2026-07-14
P2: 2026-07-15 ～ 2026-08-14

一次判定基準
- 全決まり手観測をまとめた Overall Brier が BOTH periods で改善すること。
- 閾値や期間は結果を見て変更しない。

本番変更は行わない。
"""

from __future__ import annotations

from bisect import bisect_left
from calendar import monthrange
from collections import Counter, defaultdict
from datetime import date, datetime
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parent))
from slit_validate_v2 import connect_db  # noqa: E402

PERIODS = [
    ("P1", "2026-06-15", "2026-07-14"),
    ("P2", "2026-07-15", "2026-08-14"),
]

# P1開始日の6ヶ月前。固定期間を動かさない前提で固定。
HISTORY_START = "2025-12-15"
HISTORY_END = "2026-08-14"
TARGET_START = PERIODS[0][1]
TARGET_END = PERIODS[-1][2]

METRICS_BY_COURSE = {
    1: ("nige", "sasare", "makurare", "makurarezashi"),
    2: ("nogashi", "sashi", "makuri", "makurizashi"),
    3: ("sashi", "makuri", "makurizashi"),
    4: ("sashi", "makuri", "makurizashi"),
    5: ("sashi", "makuri", "makurizashi"),
    6: ("sashi", "makuri", "makurizashi"),
}

METRIC_LABEL = {
    "nige": "逃げ",
    "sasare": "差され",
    "makurare": "まくられ",
    "makurarezashi": "まくられ差",
    "nogashi": "逃がし",
    "sashi": "差し",
    "makuri": "まくり",
    "makurizashi": "まくり差し",
}

THRESHOLDS = {
    "nige": 0.20,
    "sashi": 0.05,
    "makuri": 0.05,
    "makurizashi": 0.05,
    "nogashi": 0.40,
    "sasare": 0.20,
    "makurarezashi": 0.20,
}

KEYS = (
    "nige",
    "sasare",
    "makurare",
    "makurarezashi",
    "nogashi",
    "sashi",
    "makuri",
    "makurizashi",
)


def parse_date(value: str) -> date:
    return datetime.strptime(value, "%Y-%m-%d").date()


def months_ago(d: date, months: int) -> date:
    total = d.year * 12 + (d.month - 1) - months
    year = total // 12
    month = total % 12 + 1
    day = min(d.day, monthrange(year, month)[1])
    return date(year, month, day)


def blank_counts() -> dict[str, int]:
    return {k: 0 for k in KEYS}


def event_counts_current(course: int, rank: str, technique: str, winner_course: int) -> dict[str, int]:
    c = blank_counts()
    if course == 1 and rank == "1":
        c["nige"] = 1
    elif course == 1 and rank != "1" and technique == "差し":
        c["sasare"] = 1
    elif course == 1 and rank != "1" and technique == "まくり":
        c["makurare"] = 1
    elif course == 1 and rank != "1" and technique == "まくり差し":
        c["makurarezashi"] = 1
    elif course == 2 and rank != "1" and winner_course == 1:
        c["nogashi"] = 1
    elif rank == "1" and technique == "差し":
        c["sashi"] = 1
    elif rank == "1" and technique == "まくり":
        c["makuri"] = 1
    elif rank == "1" and technique == "まくり差し":
        c["makurizashi"] = 1
    return c


def event_counts_rebuilt(
    player_id: str,
    course: int,
    winner_player_id: str,
    winner_course: int,
    winner_technique: str,
) -> dict[str, int]:
    c = blank_counts()
    won = winner_player_id == player_id

    if course == 1 and won:
        c["nige"] = 1
    elif course == 1 and not won and winner_technique == "差し":
        c["sasare"] = 1
    elif course == 1 and not won and winner_technique == "まくり":
        c["makurare"] = 1
    elif course == 1 and not won and winner_technique == "まくり差し":
        c["makurarezashi"] = 1
    elif course == 2 and not won and winner_course == 1:
        c["nogashi"] = 1
    elif course != 1 and won and winner_technique == "差し":
        c["sashi"] = 1
    elif course != 1 and won and winner_technique == "まくり":
        c["makuri"] = 1
    elif course != 1 and won and winner_technique == "まくり差し":
        c["makurizashi"] = 1
    return c


def load_targets(conn) -> list[dict]:
    sql = """
WITH tr AS (
    SELECT race_code, race_date
    FROM boat_race.race_master
    WHERE race_date BETWEEN %s::date AND %s::date
),
ex_map AS (
    SELECT DISTINCT ON (el.race_code, el.player_id)
        el.race_code,
        el.player_id,
        el.entry_course::integer AS entry_course
    FROM boat_race.exhibition_live el
    JOIN tr ON tr.race_code = el.race_code
    WHERE el.entry_course BETWEEN 1 AND 6
    ORDER BY el.race_code, el.player_id
),
winner AS (
    SELECT DISTINCT ON (rrd.race_code)
        rrd.race_code,
        rrd.player_id::text AS winner_player_id,
        rrd.entry_course::integer AS winner_course,
        TRIM(COALESCE(rrd.technique, '')) AS winner_technique
    FROM boat_race.race_result_detail rrd
    JOIN tr ON tr.race_code = rrd.race_code
    WHERE TRIM(rrd.rank) = '1'
    ORDER BY rrd.race_code
),
valid AS (
    SELECT re.race_code
    FROM boat_race.race_entry re
    JOIN tr ON tr.race_code = re.race_code
    JOIN ex_map ex
      ON ex.race_code = re.race_code
     AND ex.player_id = re.player_id
    JOIN winner w
      ON w.race_code = re.race_code
    GROUP BY re.race_code
    HAVING COUNT(*) = 6
       AND COUNT(DISTINCT ex.entry_course) = 6
)
SELECT
    re.race_code,
    re.player_id::text,
    ex.entry_course,
    tr.race_date,
    w.winner_player_id,
    w.winner_course,
    w.winner_technique
FROM boat_race.race_entry re
JOIN valid v ON v.race_code = re.race_code
JOIN tr ON tr.race_code = re.race_code
JOIN ex_map ex
  ON ex.race_code = re.race_code
 AND ex.player_id = re.player_id
JOIN winner w ON w.race_code = re.race_code
ORDER BY tr.race_date, re.race_code, ex.entry_course
"""
    out = []
    with conn.cursor() as cur:
        cur.execute(sql, (TARGET_START, TARGET_END))
        for row in cur.fetchall():
            race_code, pid, course, race_date, winner_pid, winner_course, winner_tech = row
            out.append(
                {
                    "race_code": str(race_code),
                    "player_id": str(pid).strip(),
                    "course": int(course),
                    "date": race_date,
                    "winner_player_id": str(winner_pid).strip(),
                    "winner_course": int(winner_course or 0),
                    "winner_technique": str(winner_tech or "").strip(),
                }
            )
    return out


def load_old_history(conn) -> list[dict]:
    sql = """
WITH hr AS (
    SELECT race_code, race_date
    FROM boat_race.race_master
    WHERE race_date BETWEEN %s::date AND %s::date
),
winner AS (
    SELECT DISTINCT ON (rrd.race_code)
        rrd.race_code,
        rrd.entry_course::integer AS winner_course
    FROM boat_race.race_result_detail rrd
    JOIN hr ON hr.race_code = rrd.race_code
    WHERE TRIM(rrd.rank) = '1'
    ORDER BY rrd.race_code
)
SELECT
    rrd.player_id::text,
    rrd.entry_course::integer,
    hr.race_date,
    TRIM(COALESCE(rrd.rank, '')) AS rank,
    TRIM(COALESCE(rrd.technique, '')) AS technique,
    COALESCE(w.winner_course, 0)
FROM boat_race.race_result_detail rrd
JOIN hr ON hr.race_code = rrd.race_code
LEFT JOIN winner w ON w.race_code = rrd.race_code
WHERE rrd.entry_course BETWEEN 1 AND 6
ORDER BY rrd.player_id, rrd.entry_course, hr.race_date
"""
    out = []
    with conn.cursor() as cur:
        cur.execute(sql, (HISTORY_START, HISTORY_END))
        for pid, course, race_date, rank, technique, winner_course in cur.fetchall():
            course = int(course)
            out.append(
                {
                    "player_id": str(pid).strip(),
                    "course": course,
                    "date": race_date,
                    "counts": event_counts_current(
                        course,
                        str(rank or "").strip(),
                        str(technique or "").strip(),
                        int(winner_course or 0),
                    ),
                }
            )
    return out


def load_rebuilt_history(conn) -> list[dict]:
    sql = """
WITH hr AS (
    SELECT race_code, race_date
    FROM boat_race.race_master
    WHERE race_date BETWEEN %s::date AND %s::date
),
rd_map AS (
    SELECT DISTINCT ON (rrd.race_code, rrd.player_id)
        rrd.race_code,
        rrd.player_id,
        rrd.entry_course::integer AS entry_course
    FROM boat_race.race_result_detail rrd
    JOIN hr ON hr.race_code = rrd.race_code
    WHERE rrd.entry_course BETWEEN 1 AND 6
    ORDER BY rrd.race_code, rrd.player_id
),
ex_map AS (
    SELECT DISTINCT ON (el.race_code, el.player_id)
        el.race_code,
        el.player_id,
        el.entry_course::integer AS entry_course
    FROM boat_race.exhibition_live el
    JOIN hr ON hr.race_code = el.race_code
    WHERE el.entry_course BETWEEN 1 AND 6
    ORDER BY el.race_code, el.player_id
),
winner AS (
    SELECT DISTINCT ON (rrd.race_code)
        rrd.race_code,
        rrd.player_id::text AS winner_player_id,
        rrd.entry_course::integer AS winner_course,
        TRIM(COALESCE(rrd.technique, '')) AS winner_technique
    FROM boat_race.race_result_detail rrd
    JOIN hr ON hr.race_code = rrd.race_code
    WHERE TRIM(rrd.rank) = '1'
    ORDER BY rrd.race_code
)
SELECT
    re.player_id::text,
    COALESCE(rd.entry_course, ex.entry_course) AS resolved_course,
    hr.race_date,
    w.winner_player_id,
    w.winner_course,
    w.winner_technique
FROM boat_race.race_entry re
JOIN hr ON hr.race_code = re.race_code
LEFT JOIN rd_map rd
  ON rd.race_code = re.race_code
 AND rd.player_id = re.player_id
LEFT JOIN ex_map ex
  ON ex.race_code = re.race_code
 AND ex.player_id = re.player_id
JOIN winner w ON w.race_code = re.race_code
WHERE COALESCE(rd.entry_course, ex.entry_course) BETWEEN 1 AND 6
ORDER BY re.player_id, resolved_course, hr.race_date
"""
    out = []
    with conn.cursor() as cur:
        cur.execute(sql, (HISTORY_START, HISTORY_END))
        for pid, course, race_date, winner_pid, winner_course, winner_tech in cur.fetchall():
            pid = str(pid).strip()
            course = int(course)
            out.append(
                {
                    "player_id": pid,
                    "course": course,
                    "date": race_date,
                    "counts": event_counts_rebuilt(
                        pid,
                        course,
                        str(winner_pid).strip(),
                        int(winner_course or 0),
                        str(winner_tech or "").strip(),
                    ),
                }
            )
    return out


class HistoryIndex:
    def __init__(self, rows: list[dict]):
        grouped = defaultdict(list)
        for r in rows:
            grouped[(r["player_id"], r["course"])].append(r)

        self.data = {}
        for key, items in grouped.items():
            items.sort(key=lambda x: x["date"])
            dates = [x["date"] for x in items]
            prefix_n = [0]
            prefix = {k: [0] for k in KEYS}

            for item in items:
                prefix_n.append(prefix_n[-1] + 1)
                for k in KEYS:
                    prefix[k].append(prefix[k][-1] + int(item["counts"].get(k, 0)))

            self.data[key] = {
                "dates": dates,
                "prefix_n": prefix_n,
                "prefix": prefix,
            }

    def profile(self, player_id: str, course: int, target_date: date) -> dict:
        item = self.data.get((player_id, course))
        if item is None:
            return {"n": 0, "counts": blank_counts(), "rates": {k: 0.0 for k in KEYS}}

        lower = months_ago(target_date, 6)
        dates = item["dates"]
        lo = bisect_left(dates, lower)
        hi = bisect_left(dates, target_date)

        n = item["prefix_n"][hi] - item["prefix_n"][lo]
        counts = {
            k: item["prefix"][k][hi] - item["prefix"][k][lo]
            for k in KEYS
        }
        rates = {k: (counts[k] / n if n else 0.0) for k in KEYS}
        return {"n": n, "counts": counts, "rates": rates}


def actual_event(target: dict, metric: str) -> int:
    course = target["course"]
    pid = target["player_id"]
    winner_pid = target["winner_player_id"]
    winner_course = target["winner_course"]
    technique = target["winner_technique"]

    if metric == "nige":
        return int(course == 1 and winner_pid == pid)
    if metric == "sasare":
        return int(course == 1 and winner_pid != pid and technique == "差し")
    if metric == "makurare":
        return int(course == 1 and winner_pid != pid and technique == "まくり")
    if metric == "makurarezashi":
        return int(course == 1 and winner_pid != pid and technique == "まくり差し")
    if metric == "nogashi":
        return int(course == 2 and winner_pid != pid and winner_course == 1)
    if metric == "sashi":
        return int(course != 1 and winner_pid == pid and technique == "差し")
    if metric == "makuri":
        return int(course != 1 and winner_pid == pid and technique == "まくり")
    if metric == "makurizashi":
        return int(course != 1 and winner_pid == pid and technique == "まくり差し")
    raise KeyError(metric)


def decide_type(course: int, rates: dict) -> tuple[str, int]:
    if course == 1 and rates.get("nige", 0.0) >= 0.20:
        return "逃げ型", 1
    if rates.get("sashi", 0.0) >= 0.05:
        return "差し型", 1
    if rates.get("makuri", 0.0) >= 0.05 or rates.get("makurizashi", 0.0) >= 0.05:
        return "攻め型", 1
    if rates.get("sasare", 0.0) >= 0.20 or rates.get("makurarezashi", 0.0) >= 0.20:
        return "脆い型", -1
    return "無色", 0


def brier(values: list[tuple[float, int]]) -> float:
    return sum((p - y) ** 2 for p, y in values) / len(values) if values else 0.0


def evaluate_period(targets: list[dict], old_idx: HistoryIndex, new_idx: HistoryIndex, start: str, end: str) -> dict:
    start_d = parse_date(start)
    end_d = parse_date(end)

    overall_old = []
    overall_new = []
    by_metric_old = defaultdict(list)
    by_metric_new = defaultdict(list)
    n_delta = []
    bonus_changes = Counter()
    type_changes = Counter()
    threshold_changes = Counter()

    used_profiles = 0
    used_races = set()

    for target in targets:
        if not (start_d <= target["date"] <= end_d):
            continue

        pid = target["player_id"]
        course = target["course"]
        old = old_idx.profile(pid, course, target["date"])
        new = new_idx.profile(pid, course, target["date"])

        used_profiles += 1
        used_races.add(target["race_code"])
        n_delta.append(new["n"] - old["n"])

        old_type, old_bonus = decide_type(course, old["rates"])
        new_type, new_bonus = decide_type(course, new["rates"])
        if old_type != new_type:
            type_changes[(old_type, new_type)] += 1
        if old_bonus != new_bonus:
            bonus_changes[(old_bonus, new_bonus)] += 1

        for metric in METRICS_BY_COURSE[course]:
            y = actual_event(target, metric)
            po = old["rates"].get(metric, 0.0)
            pn = new["rates"].get(metric, 0.0)
            overall_old.append((po, y))
            overall_new.append((pn, y))
            by_metric_old[metric].append((po, y))
            by_metric_new[metric].append((pn, y))

            if metric in THRESHOLDS:
                th = THRESHOLDS[metric]
                old_on = po >= th
                new_on = pn >= th
                if old_on != new_on:
                    threshold_changes[(metric, old_on, new_on)] += 1

    return {
        "races": len(used_races),
        "profiles": used_profiles,
        "obs": len(overall_old),
        "old_brier": brier(overall_old),
        "new_brier": brier(overall_new),
        "metric_old": {m: brier(v) for m, v in by_metric_old.items()},
        "metric_new": {m: brier(v) for m, v in by_metric_new.items()},
        "metric_n": {m: len(v) for m, v in by_metric_old.items()},
        "n_delta": n_delta,
        "bonus_changes": bonus_changes,
        "type_changes": type_changes,
        "threshold_changes": threshold_changes,
    }


def print_period(label: str, start: str, end: str, r: dict) -> None:
    old_b = r["old_brier"]
    new_b = r["new_brier"]
    rel = ((old_b - new_b) / old_b * 100.0) if old_b else 0.0
    improved = new_b < old_b

    deltas = r["n_delta"]
    inc = sum(1 for x in deltas if x > 0)
    same = sum(1 for x in deltas if x == 0)
    dec = sum(1 for x in deltas if x < 0)
    avg_delta = sum(deltas) / len(deltas) if deltas else 0.0

    print("=" * 112)
    print(f"{label}: {start} ～ {end}")
    print("=" * 112)
    print(f"対象レース          : {r['races']}")
    print(f"対象profile         : {r['profiles']}")
    print(f"Brier観測数         : {r['obs']}")
    print(f"履歴N変化           : 増 {inc} / 同 {same} / 減 {dec} / 平均 {avg_delta:+.2f}")
    print(f"Overall Brier 現行  : {old_b:.6f}")
    print(f"Overall Brier 再構築: {new_b:.6f}")
    print(f"改善率              : {rel:+.3f}%  {'改善' if improved else '悪化'}")

    print("\n【決まり手別 Brier】")
    print("項目            N       現行       再構築       差(new-old)")
    print("-" * 74)
    for metric in KEYS:
        if metric not in r["metric_n"]:
            continue
        n = r["metric_n"][metric]
        ob = r["metric_old"][metric]
        nb = r["metric_new"][metric]
        print(f"{METRIC_LABEL[metric]:<12} {n:>7}  {ob:.6f}  {nb:.6f}  {nb-ob:+.6f}")

    print("\n【本番閾値のON/OFF変更】")
    if r["threshold_changes"]:
        for (metric, old_on, new_on), n in r["threshold_changes"].most_common():
            print(
                f"{METRIC_LABEL[metric]:<12} "
                f"{'ON' if old_on else 'OFF'}→{'ON' if new_on else 'OFF'} : {n}"
            )
    else:
        print("なし")

    print("\n【typeBonus変更】")
    if r["bonus_changes"]:
        for (old_bns, new_bns), n in r["bonus_changes"].most_common():
            print(f"{old_bns:+d} → {new_bns:+d} : {n}")
    else:
        print("なし")
    print()


def main() -> None:
    for _, start, end in PERIODS:
        parse_date(start)
        parse_date(end)

    with connect_db() as conn:
        print("対象レースを読み込み中...", flush=True)
        targets = load_targets(conn)
        print(f"  対象profile候補: {len(targets)}", flush=True)

        print("現行方式の履歴を1回だけ読み込み中...", flush=True)
        old_rows = load_old_history(conn)
        print(f"  現行履歴行: {len(old_rows)}", flush=True)

        print("再構築方式の履歴を1回だけ読み込み中...", flush=True)
        new_rows = load_rebuilt_history(conn)
        print(f"  再構築履歴行: {len(new_rows)}", flush=True)

    print("履歴インデックスを作成中...", flush=True)
    old_idx = HistoryIndex(old_rows)
    new_idx = HistoryIndex(new_rows)

    results = []
    for label, start, end in PERIODS:
        print(f"{label} を評価中...", flush=True)
        r = evaluate_period(targets, old_idx, new_idx, start, end)
        results.append((label, start, end, r))

    print("\n")
    for label, start, end, r in results:
        print_period(label, start, end, r)

    both = all(r["new_brier"] < r["old_brier"] for _, _, _, r in results)

    pooled_old_num = sum(r["old_brier"] * r["obs"] for _, _, _, r in results)
    pooled_new_num = sum(r["new_brier"] * r["obs"] for _, _, _, r in results)
    pooled_n = sum(r["obs"] for _, _, _, r in results)
    pooled_old = pooled_old_num / pooled_n if pooled_n else 0.0
    pooled_new = pooled_new_num / pooled_n if pooled_n else 0.0
    pooled_rel = ((pooled_old - pooled_new) / pooled_old * 100.0) if pooled_old else 0.0

    print("=" * 112)
    print("固定2期間 最終判定")
    print("=" * 112)
    print(f"Pooled Brier 現行   : {pooled_old:.6f}")
    print(f"Pooled Brier 再構築 : {pooled_new:.6f}")
    print(f"Pooled改善率        : {pooled_rel:+.3f}%")
    print("事前基準             : Overall Brier が BOTH periods で改善")
    print(f"判定                 : {'ADOPT候補' if both else 'REJECT / 要再確認'}")
    print("本番変更             : なし")
    print("=" * 112)


if __name__ == "__main__":
    main()
