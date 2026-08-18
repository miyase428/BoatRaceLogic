#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
決まり手集計の「現行方式 vs race_entry再構築方式」を固定2期間で比較する。

目的
- 現行: race_result_detail の本人行だけを母集団にする方式を再現。
- 再構築: race_entry を母集団にし、完了レースのみ、実進入は
  race_result_detail を優先し、本人行欠損時だけ exhibition_live で補う。
- 各対象レース日より前6ヶ月だけで履歴率を作り、対象レースの実際の決まり手を
  未来データとして Brier score で比較する。

固定期間（事前固定）
P1: 2026-06-15 ～ 2026-07-14
P2: 2026-07-15 ～ 2026-08-14

一次判定基準
- 全決まり手観測をまとめた Overall Brier が BOTH periods で改善すること。
- 閾値や期間は結果を見て変更しない。

本番変更は行わない。
"""

from __future__ import annotations

from collections import Counter, defaultdict
from datetime import datetime
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parent))
from slit_validate_v2 import connect_db  # noqa: E402

PERIODS = [
    ("P1", "2026-06-15", "2026-07-14"),
    ("P2", "2026-07-15", "2026-08-14"),
]

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

# SQL SELECT の決まり手列順と必ず一致させる。
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


def parse_date(value: str):
    return datetime.strptime(value, "%Y-%m-%d").date()


def _target_cte() -> str:
    return r"""
valid_races AS (
    SELECT re.race_code
    FROM boat_race.race_entry re
    JOIN boat_race.race_master rm
      ON rm.race_code = re.race_code
    JOIN LATERAL (
        SELECT el.entry_course
        FROM boat_race.exhibition_live el
        WHERE el.race_code = re.race_code
          AND el.player_id = re.player_id
          AND el.entry_course BETWEEN 1 AND 6
        LIMIT 1
    ) ex ON TRUE
    WHERE rm.race_date BETWEEN %s::date AND %s::date
    GROUP BY re.race_code
    HAVING COUNT(*) = 6
       AND COUNT(DISTINCT ex.entry_course) = 6
),
target AS (
    SELECT
        re.race_code,
        re.player_id::text AS player_id,
        ex.entry_course::integer AS target_course,
        rm.race_date AS target_date,
        w.player_id::text AS winner_player_id,
        w.entry_course::integer AS winner_course,
        TRIM(COALESCE(w.technique, '')) AS winner_technique
    FROM boat_race.race_entry re
    JOIN valid_races vr
      ON vr.race_code = re.race_code
    JOIN boat_race.race_master rm
      ON rm.race_code = re.race_code
    JOIN LATERAL (
        SELECT el.entry_course
        FROM boat_race.exhibition_live el
        WHERE el.race_code = re.race_code
          AND el.player_id = re.player_id
          AND el.entry_course BETWEEN 1 AND 6
        LIMIT 1
    ) ex ON TRUE
    JOIN LATERAL (
        SELECT rrd.player_id, rrd.entry_course, rrd.technique
        FROM boat_race.race_result_detail rrd
        WHERE rrd.race_code = re.race_code
          AND TRIM(rrd.rank) = '1'
        LIMIT 1
    ) w ON TRUE
    WHERE rm.race_date BETWEEN %s::date AND %s::date
)
"""


def load_old_profiles(conn, start: str, end: str) -> dict:
    sql = (
        "WITH "
        + _target_cte()
        + r""",
hist_winner AS (
    SELECT
        rrd.race_code,
        MAX(rrd.entry_course) FILTER (WHERE TRIM(rrd.rank) = '1') AS winner_course
    FROM boat_race.race_result_detail rrd
    JOIN boat_race.race_master rm
      ON rm.race_code = rrd.race_code
    WHERE rm.race_date >= %s::date - INTERVAL '6 months'
      AND rm.race_date <  %s::date + INTERVAL '1 day'
    GROUP BY rrd.race_code
),
past AS (
    SELECT
        rrd.race_code,
        rrd.player_id::text AS player_id,
        rrd.entry_course::integer AS entry_course,
        TRIM(rrd.rank) AS rank,
        TRIM(COALESCE(rrd.technique, '')) AS technique,
        rm.race_date,
        hw.winner_course
    FROM boat_race.race_result_detail rrd
    JOIN boat_race.race_master rm
      ON rm.race_code = rrd.race_code
    LEFT JOIN hist_winner hw
      ON hw.race_code = rrd.race_code
    WHERE rm.race_date >= %s::date - INTERVAL '6 months'
      AND rm.race_date <  %s::date + INTERVAL '1 day'
)
SELECT
    t.race_code,
    t.player_id,
    t.target_course,
    t.winner_player_id,
    t.winner_course,
    t.winner_technique,
    COUNT(p.race_code) AS sample_n,
    COUNT(*) FILTER (WHERE p.entry_course = 1 AND p.rank = '1') AS nige,
    COUNT(*) FILTER (WHERE p.entry_course = 1 AND p.rank <> '1' AND p.technique = '差し') AS sasare,
    COUNT(*) FILTER (WHERE p.entry_course = 1 AND p.rank <> '1' AND p.technique = 'まくり') AS makurare,
    COUNT(*) FILTER (WHERE p.entry_course = 1 AND p.rank <> '1' AND p.technique = 'まくり差し') AS makurarezashi,
    COUNT(*) FILTER (WHERE p.entry_course = 2 AND p.rank <> '1' AND p.winner_course = 1) AS nogashi,
    COUNT(*) FILTER (WHERE p.rank = '1' AND p.technique = '差し') AS sashi,
    COUNT(*) FILTER (WHERE p.rank = '1' AND p.technique = 'まくり') AS makuri,
    COUNT(*) FILTER (WHERE p.rank = '1' AND p.technique = 'まくり差し') AS makurizashi
FROM target t
LEFT JOIN past p
  ON p.player_id = t.player_id
 AND p.entry_course = t.target_course
 AND p.race_date >= t.target_date - INTERVAL '6 months'
 AND p.race_date <  t.target_date
GROUP BY
    t.race_code, t.player_id, t.target_course,
    t.winner_player_id, t.winner_course, t.winner_technique
ORDER BY t.race_code, t.target_course
"""
    )
    params = (
        start,
        end,
        start,
        end,
        start,
        end,
        start,
        end,
    )
    return _fetch_profiles(conn, sql, params)


def load_rebuilt_profiles(conn, start: str, end: str) -> dict:
    sql = (
        "WITH "
        + _target_cte()
        + r""",
past AS (
    SELECT
        re.race_code,
        re.player_id::text AS player_id,
        COALESCE(rd.entry_course, ex.entry_course)::integer AS entry_course,
        rm.race_date,
        w.player_id::text AS winner_player_id,
        w.entry_course::integer AS winner_course,
        TRIM(COALESCE(w.technique, '')) AS winner_technique
    FROM boat_race.race_entry re
    JOIN boat_race.race_master rm
      ON rm.race_code = re.race_code
    LEFT JOIN LATERAL (
        SELECT rrd.entry_course
        FROM boat_race.race_result_detail rrd
        WHERE rrd.race_code = re.race_code
          AND rrd.player_id = re.player_id
          AND rrd.entry_course BETWEEN 1 AND 6
        LIMIT 1
    ) rd ON TRUE
    LEFT JOIN LATERAL (
        SELECT el.entry_course
        FROM boat_race.exhibition_live el
        WHERE el.race_code = re.race_code
          AND el.player_id = re.player_id
          AND el.entry_course BETWEEN 1 AND 6
        LIMIT 1
    ) ex ON TRUE
    JOIN LATERAL (
        SELECT rrd.player_id, rrd.entry_course, rrd.technique
        FROM boat_race.race_result_detail rrd
        WHERE rrd.race_code = re.race_code
          AND TRIM(rrd.rank) = '1'
        LIMIT 1
    ) w ON TRUE
    WHERE rm.race_date >= %s::date - INTERVAL '6 months'
      AND rm.race_date <  %s::date + INTERVAL '1 day'
)
SELECT
    t.race_code,
    t.player_id,
    t.target_course,
    t.winner_player_id,
    t.winner_course,
    t.winner_technique,
    COUNT(p.race_code) AS sample_n,
    COUNT(*) FILTER (
        WHERE t.target_course = 1
          AND p.winner_player_id = t.player_id
    ) AS nige,
    COUNT(*) FILTER (
        WHERE t.target_course = 1
          AND p.winner_player_id <> t.player_id
          AND p.winner_technique = '差し'
    ) AS sasare,
    COUNT(*) FILTER (
        WHERE t.target_course = 1
          AND p.winner_player_id <> t.player_id
          AND p.winner_technique = 'まくり'
    ) AS makurare,
    COUNT(*) FILTER (
        WHERE t.target_course = 1
          AND p.winner_player_id <> t.player_id
          AND p.winner_technique = 'まくり差し'
    ) AS makurarezashi,
    COUNT(*) FILTER (
        WHERE t.target_course = 2
          AND p.winner_player_id <> t.player_id
          AND p.winner_course = 1
    ) AS nogashi,
    COUNT(*) FILTER (
        WHERE t.target_course <> 1
          AND p.winner_player_id = t.player_id
          AND p.winner_technique = '差し'
    ) AS sashi,
    COUNT(*) FILTER (
        WHERE t.target_course <> 1
          AND p.winner_player_id = t.player_id
          AND p.winner_technique = 'まくり'
    ) AS makuri,
    COUNT(*) FILTER (
        WHERE t.target_course <> 1
          AND p.winner_player_id = t.player_id
          AND p.winner_technique = 'まくり差し'
    ) AS makurizashi
FROM target t
LEFT JOIN past p
  ON p.player_id = t.player_id
 AND p.entry_course = t.target_course
 AND p.race_date >= t.target_date - INTERVAL '6 months'
 AND p.race_date <  t.target_date
GROUP BY
    t.race_code, t.player_id, t.target_course,
    t.winner_player_id, t.winner_course, t.winner_technique
ORDER BY t.race_code, t.target_course
"""
    )
    params = (
        start,
        end,
        start,
        end,
        start,
        end,
    )
    return _fetch_profiles(conn, sql, params)


def _fetch_profiles(conn, sql: str, params: tuple) -> dict:
    out = {}
    with conn.cursor() as cur:
        cur.execute(sql, params)
        for row in cur.fetchall():
            race_code, player_id, course, winner_pid, winner_course, winner_technique, total, *vals = row
            total = int(total or 0)
            counts = {key: int(vals[i] or 0) for i, key in enumerate(KEYS)}
            rates = {key: (counts[key] / total if total else 0.0) for key in KEYS}
            out[(str(race_code), str(player_id).strip())] = {
                "course": int(course),
                "winner_player_id": str(winner_pid).strip(),
                "winner_course": int(winner_course or 0),
                "winner_technique": str(winner_technique or "").strip(),
                "n": total,
                "counts": counts,
                "rates": rates,
            }
    return out


def actual_event(profile: dict, metric: str) -> int:
    course = profile["course"]
    pid = profile["player_id"]
    winner_pid = profile["winner_player_id"]
    winner_course = profile["winner_course"]
    technique = profile["winner_technique"]

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


def brier(values):
    return sum((p - y) ** 2 for p, y in values) / len(values) if values else 0.0


def evaluate_period(old: dict, new: dict):
    common = sorted(set(old) & set(new))
    overall_old = []
    overall_new = []
    by_metric_old = defaultdict(list)
    by_metric_new = defaultdict(list)
    n_delta = []
    bonus_changes = Counter()
    type_changes = Counter()
    threshold_changes = Counter()

    used_profiles = 0
    for key in common:
        o = old[key]
        n = new[key]
        if o["course"] != n["course"]:
            continue

        base = dict(n)
        base["player_id"] = key[1]
        course = n["course"]
        used_profiles += 1
        n_delta.append(n["n"] - o["n"])

        old_type, old_bonus = decide_type(course, o["rates"])
        new_type, new_bonus = decide_type(course, n["rates"])
        if old_type != new_type:
            type_changes[(old_type, new_type)] += 1
        if old_bonus != new_bonus:
            bonus_changes[(old_bonus, new_bonus)] += 1

        for metric in METRICS_BY_COURSE[course]:
            y = actual_event(base, metric)
            po = o["rates"].get(metric, 0.0)
            pn = n["rates"].get(metric, 0.0)
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


def print_period(label: str, start: str, end: str, r: dict):
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
    print(f"対象profile        : {r['profiles']}")
    print(f"Brier観測数        : {r['obs']}")
    print(f"履歴N変化           : 増 {inc} / 同 {same} / 減 {dec} / 平均 {avg_delta:+.2f}")
    print(f"Overall Brier 現行 : {old_b:.6f}")
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


def main():
    for _, start, end in PERIODS:
        parse_date(start)
        parse_date(end)

    results = []
    with connect_db() as conn:
        for label, start, end in PERIODS:
            print(f"{label} 現行方式を集計中...", flush=True)
            old = load_old_profiles(conn, start, end)
            print(f"{label} 再構築方式を集計中...", flush=True)
            new = load_rebuilt_profiles(conn, start, end)
            r = evaluate_period(old, new)
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
