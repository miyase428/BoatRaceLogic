#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""9月の展示前コースサインを、実際の着順で再現検証する。

TOPの朝スナップショットと同じく、対象選手は枠番=仮コースで決める。
条件に使う決まり手履歴は対象日を含まない直前12か月、ST順位は当該期の
racer_results、判定ルールは現在の course_signal_rules.json を使う。

ルール生成日（2026-09-09）以前はインサンプルを含むため、全期間とは別に
2026-09-10以降を採用後期間として集計する。
"""

from __future__ import annotations

import bisect
import csv
import json
import math
import sys
from collections import defaultdict
from dataclasses import dataclass
from datetime import date, datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(Path(__file__).resolve().parent))

from analyze_tamagawa_boaters_hypothesis import (  # noqa: E402
    load_racer_results,
    months_ago,
    required_terms,
    term_info_for_date,
)
from slit_validate_v2 import connect_db  # noqa: E402

TECHNIQUES = ("逃げ", "差し", "まくり", "まくり差し", "抜き", "恵まれ")
DEFAULT_THRESHOLDS = {
    (1, "main"): 55.0,
    (2, "sashi"): 10.0,
    (2, "makuri"): 5.0,
    (3, "main"): 15.0,
    (4, "main"): 15.0,
    (5, "main"): 10.0,
    (6, "main"): 5.0,
}
LABELS = {
    (1, "main"): "1C逃げ",
    (2, "sashi"): "2C差し",
    (2, "makuri"): "2Cまくり",
    (3, "main"): "3C攻め",
    (4, "main"): "4C攻め",
    (5, "main"): "5C攻め",
    (6, "main"): "6C攻め",
}


def parse_date(value: str) -> date:
    return datetime.strptime(value, "%Y-%m-%d").date()


def pct(num: int | float, den: int | float) -> float:
    return 100.0 * num / den if den else 0.0


def wilson(success: int, total: int) -> list[float]:
    if total <= 0:
        return [0.0, 0.0]
    z = 1.959963984540054
    p = success / total
    denom = 1.0 + z * z / total
    center = (p + z * z / (2.0 * total)) / denom
    margin = z * math.sqrt((p * (1.0 - p) + z * z / (4.0 * total)) / total) / denom
    return [100.0 * max(0.0, center - margin), 100.0 * min(1.0, center + margin)]


def load_targets(start: date, end: date) -> dict[str, dict]:
    sql = """
WITH target_races AS (
    SELECT race_code, race_date
    FROM boat_race.race_master
    WHERE race_date BETWEEN %s::date AND %s::date
), ranks AS (
    SELECT
        rrd.race_code,
        rrd.player_id::text AS player_id,
        NULLIF(regexp_replace(TRIM(rrd.rank::text), '[^0-9]', '', 'g'), '')::int AS actual_rank
    FROM boat_race.race_result_detail rrd
    JOIN target_races tr ON tr.race_code = rrd.race_code
), completed AS (
    SELECT race_code
    FROM ranks
    GROUP BY race_code
    HAVING COUNT(DISTINCT actual_rank) FILTER (WHERE actual_rank BETWEEN 1 AND 3) = 3
)
SELECT
    tr.race_code,
    tr.race_date,
    SUBSTRING(tr.race_code, 9, 3) AS place_code,
    re.lane_number::integer AS course,
    re.player_id::text AS player_id,
    r.actual_rank
FROM target_races tr
JOIN completed c ON c.race_code = tr.race_code
JOIN boat_race.race_entry re ON re.race_code = tr.race_code
LEFT JOIN ranks r ON r.race_code = re.race_code AND r.player_id = re.player_id::text
WHERE re.lane_number BETWEEN 1 AND 6
ORDER BY tr.race_date, tr.race_code, re.lane_number
"""
    races: dict[str, dict] = {}
    with connect_db() as conn:
        with conn.cursor() as cur:
            cur.execute(sql, (start, end))
            for code, race_date, place, course, player_id, actual_rank in cur.fetchall():
                race = races.setdefault(str(code), {
                    "date": race_date,
                    "place": str(place),
                    "boats": {},
                })
                race["boats"][int(course)] = {
                    "player_id": str(player_id).strip(),
                    "actual_rank": int(actual_rank) if actual_rank is not None else None,
                }
    return {code: row for code, row in races.items() if set(row["boats"]) == set(range(1, 7))}


def load_history(start: date, end: date, player_ids: list[str]) -> list[dict]:
    history_start = months_ago(start, 12)
    sql = """
WITH hr AS (
    SELECT race_code, race_date
    FROM boat_race.race_master
    WHERE race_date >= %s::date AND race_date < %s::date + INTERVAL '1 day'
), rd_map AS (
    SELECT DISTINCT ON (rrd.race_code, rrd.player_id)
        rrd.race_code, rrd.player_id, rrd.entry_course::integer AS entry_course
    FROM boat_race.race_result_detail rrd
    JOIN hr ON hr.race_code = rrd.race_code
    WHERE rrd.entry_course BETWEEN 1 AND 6
    ORDER BY rrd.race_code, rrd.player_id
), ex_map AS (
    SELECT DISTINCT ON (el.race_code, el.player_id)
        el.race_code, el.player_id, el.entry_course::integer AS entry_course
    FROM boat_race.exhibition_live el
    JOIN hr ON hr.race_code = el.race_code
    WHERE el.entry_course BETWEEN 1 AND 6
    ORDER BY el.race_code, el.player_id, el.created_date DESC NULLS LAST
), winner AS (
    SELECT DISTINCT ON (rrd.race_code)
        rrd.race_code,
        rrd.player_id::text AS winner_player_id,
        TRIM(COALESCE(rrd.technique, '')) AS winner_technique
    FROM boat_race.race_result_detail rrd
    JOIN hr ON hr.race_code = rrd.race_code
    WHERE TRIM(rrd.rank::text) = '1'
    ORDER BY rrd.race_code
)
SELECT
    re.player_id::text,
    COALESCE(rd.entry_course, ex.entry_course)::integer AS entry_course,
    hr.race_date,
    w.winner_player_id,
    w.winner_technique
FROM boat_race.race_entry re
JOIN hr ON hr.race_code = re.race_code
LEFT JOIN rd_map rd ON rd.race_code = re.race_code AND rd.player_id = re.player_id
LEFT JOIN ex_map ex ON ex.race_code = re.race_code AND ex.player_id = re.player_id
JOIN winner w ON w.race_code = re.race_code
WHERE re.player_id::text = ANY(%s)
  AND COALESCE(rd.entry_course, ex.entry_course) BETWEEN 1 AND 6
ORDER BY re.player_id, entry_course, hr.race_date
"""
    out = []
    with connect_db() as conn:
        with conn.cursor() as cur:
            cur.execute(sql, (history_start, end, player_ids))
            for pid, course, race_date, winner_pid, technique in cur.fetchall():
                pid = str(pid).strip()
                winner_pid = str(winner_pid).strip()
                out.append({
                    "player_id": pid,
                    "course": int(course),
                    "date": race_date,
                    "won": pid == winner_pid,
                    "winner_technique": str(technique or "").strip(),
                })
    return out


class HistoryIndex:
    def __init__(self, rows: list[dict]):
        grouped = defaultdict(list)
        for row in rows:
            grouped[(row["player_id"], row["course"])].append(row)
        self.data = {}
        for key, items in grouped.items():
            items.sort(key=lambda x: x["date"])
            dates = [x["date"] for x in items]
            prefix_n = [0]
            prefix_tech = {t: [0] for t in TECHNIQUES}
            prefix_vulnerable = [0]
            for item in items:
                prefix_n.append(prefix_n[-1] + 1)
                for technique in TECHNIQUES:
                    prefix_tech[technique].append(
                        prefix_tech[technique][-1]
                        + int(item["won"] and item["winner_technique"] == technique)
                    )
                prefix_vulnerable.append(
                    prefix_vulnerable[-1]
                    + int((not item["won"]) and item["winner_technique"] in ("まくり", "まくり差し"))
                )
            self.data[key] = {
                "dates": dates,
                "n": prefix_n,
                "tech": prefix_tech,
                "vulnerable": prefix_vulnerable,
            }

    def profile(self, player_id: str, course: int, target_date: date) -> dict:
        item = self.data.get((player_id, course))
        if item is None:
            return {"n": 0, "nige": 0.0, "sashi": 0.0, "makuri": 0.0, "attack": 0.0, "vulnerability": 0.0}
        lo = bisect.bisect_left(item["dates"], months_ago(target_date, 12))
        hi = bisect.bisect_left(item["dates"], target_date)
        n = hi - lo
        if n <= 0:
            return {"n": 0, "nige": 0.0, "sashi": 0.0, "makuri": 0.0, "attack": 0.0, "vulnerability": 0.0}

        def count(technique: str) -> int:
            return item["tech"][technique][hi] - item["tech"][technique][lo]

        makuri = count("まくり")
        makurizashi = count("まくり差し")
        vulnerable = item["vulnerable"][hi] - item["vulnerable"][lo]
        return {
            "n": n,
            "nige": pct(count("逃げ"), n),
            "sashi": pct(count("差し"), n),
            "makuri": pct(makuri, n),
            "attack": pct(makuri + makurizashi, n),
            "vulnerability": pct(vulnerable, n),
        }


def rules_for(config: dict, place: str, course: int, variant: str) -> dict:
    course_data = config.get("places", {}).get(place, {}).get(str(course), {})
    rule = course_data.get("primary_rules", {}).get(variant, {})
    enabled = rule.get("enabled")
    if enabled is None:
        enabled = course_data.get("primary_enabled", True)
    threshold = rule.get("threshold")
    if not isinstance(threshold, (int, float)):
        threshold = DEFAULT_THRESHOLDS[(course, variant)]
    return {**rule, "enabled": bool(enabled), "threshold": float(threshold)}


def relation_matches(subject: float | None, inner: float | None, relation: str) -> bool:
    if subject is None or inner is None:
        return False
    return subject <= inner if relation == "same_or_better" else subject < inner


def signal_matches(
    config: dict,
    place: str,
    course: int,
    variant: str,
    profiles: dict[int, dict],
    ranks: dict[int, float | None],
) -> bool:
    rule = rules_for(config, place, course, variant)
    if not rule["enabled"]:
        return False
    profile = profiles[course]
    threshold = rule["threshold"]
    if course == 1:
        return profile["nige"] >= threshold
    if course == 2 and variant == "sashi":
        return profile["sashi"] >= threshold
    if course == 2:
        matched = profile["makuri"] >= threshold and relation_matches(
            ranks.get(2), ranks.get(1), str(rule.get("st_relation") or "up")
        )
        vulnerability = rule.get("vulnerability_threshold")
        return matched and (
            not isinstance(vulnerability, (int, float))
            or profiles[1]["vulnerability"] >= float(vulnerability)
        )
    if course == 3:
        return profile["attack"] >= threshold
    if course == 4:
        metric = str(rule.get("parameter") or "makuri_rate")
        rate = profile["attack"] if metric == "attack_rate" else profile["makuri"]
        vulnerability = rule.get("vulnerability_threshold")
        return (
            rate >= threshold
            and relation_matches(ranks.get(4), ranks.get(3), "up")
            and (
                not isinstance(vulnerability, (int, float))
                or profiles[1]["vulnerability"] >= float(vulnerability)
            )
        )
    if course == 5:
        return profile["attack"] >= threshold
    return profile["attack"] >= threshold and relation_matches(ranks.get(6), ranks.get(5), "up")


@dataclass
class Stat:
    n: int = 0
    first: int = 0
    top2: int = 0
    top3: int = 0

    def add(self, rank: int | None) -> None:
        self.n += 1
        self.first += int(rank == 1)
        self.top2 += int(rank is not None and rank <= 2)
        self.top3 += int(rank is not None and rank <= 3)

    def summary(self) -> dict:
        return {
            "n": self.n,
            "first": self.first,
            "top2": self.top2,
            "top3": self.top3,
            "first_rate": pct(self.first, self.n),
            "top2_rate": pct(self.top2, self.n),
            "top3_rate": pct(self.top3, self.n),
            "top3_ci95": wilson(self.top3, self.n),
        }


def evaluate(start: date, end: date, adopted: date) -> dict:
    config = json.loads((ROOT / "config/course_signal_rules.json").read_text(encoding="utf-8"))
    races = load_targets(start, end)
    player_ids = sorted({boat["player_id"] for race in races.values() for boat in race["boats"].values()})
    history = HistoryIndex(load_history(start, end, player_ids))
    racer = load_racer_results(required_terms(start, end))

    periods = {
        "september_all": lambda d: start <= d <= end,
        "post_adoption": lambda d: adopted <= d <= end,
    }
    variants = [(1, "main"), (2, "sashi"), (2, "makuri"), (3, "main"), (4, "main"), (5, "main"), (6, "main")]
    baselines = defaultdict(Stat)
    signals = defaultdict(Stat)
    unique_signals: dict[tuple[str, str, int], int | None] = {}
    signal_rows = []
    skipped_missing_rank = 0

    for race_code, race in races.items():
        race_date = race["date"]
        place = race["place"]
        term = term_info_for_date(race_date)
        profiles = {
            course: history.profile(boat["player_id"], course, race_date)
            for course, boat in race["boats"].items()
        }
        ranks = {}
        for course, boat in race["boats"].items():
            row = racer.get((term, boat["player_id"]))
            rank = row.get(course, {}).get("avg_rank") if row else None
            ranks[course] = float(rank) if rank is not None else None

        for period, includes in periods.items():
            if not includes(race_date):
                continue
            for course, variant in variants:
                actual_rank = race["boats"][course]["actual_rank"]
                baselines[(period, place, course)].add(actual_rank)
                if signal_matches(config, place, course, variant, profiles, ranks):
                    label = LABELS[(course, variant)]
                    signals[(period, "ALL")].add(actual_rank)
                    signals[(period, label)].add(actual_rank)
                    signals[(period, f"venue:{place}")].add(actual_rank)
                    unique_signals[(period, race_code, course)] = actual_rank
                    signal_rows.append({
                        "period": period,
                        "race_code": race_code,
                        "race_date": race_date.isoformat(),
                        "place": place,
                        "course": course,
                        "variant": variant,
                        "label": label,
                        "player_id": race["boats"][course]["player_id"],
                        "actual_rank": actual_rank,
                    })
            if any(ranks[c] is None for c in (1, 2, 3, 4, 5, 6)):
                skipped_missing_rank += 1

    output = {
        "definition": {
            "start": start.isoformat(),
            "end": end.isoformat(),
            "adopted_start": adopted.isoformat(),
            "rule_version": config.get("version"),
            "rule_generated_at": config.get("generated_at"),
            "target_course": "race_entry.lane_number（展示前の仮コース）",
            "history": "対象日を含まない直前12か月",
            "outcome": "対象選手の実着順",
        },
        "completed_races": len(races),
        "signal_instances": len(signal_rows),
        "periods": {},
        "signals": signal_rows,
        "notes": {"races_with_any_missing_st_rank": skipped_missing_rank},
    }

    for period in periods:
        period_rows = [row for row in signal_rows if row["period"] == period]
        unique = Stat()
        for (p, _code, _course), actual_rank in unique_signals.items():
            if p == period:
                unique.add(actual_rank)

        comparable_first = 0.0
        comparable_top2 = 0.0
        comparable_top3 = 0.0
        for row in period_rows:
            base = baselines[(period, row["place"], row["course"])].summary()
            comparable_first += base["first_rate"]
            comparable_top2 += base["top2_rate"]
            comparable_top3 += base["top3_rate"]
        den = len(period_rows)
        overall = signals[(period, "ALL")].summary()
        comparable = {
            "first_rate": comparable_first / den if den else 0.0,
            "top2_rate": comparable_top2 / den if den else 0.0,
            "top3_rate": comparable_top3 / den if den else 0.0,
        }
        overall["comparable_baseline"] = comparable
        overall["delta_first"] = overall["first_rate"] - comparable["first_rate"]
        overall["delta_top2"] = overall["top2_rate"] - comparable["top2_rate"]
        overall["delta_top3"] = overall["top3_rate"] - comparable["top3_rate"]

        by_type = {}
        for label in LABELS.values():
            stat = signals[(period, label)].summary()
            matching = [row for row in period_rows if row["label"] == label]
            if matching:
                base_first = base_top2 = base_top3 = 0.0
                for row in matching:
                    base = baselines[(period, row["place"], row["course"])].summary()
                    base_first += base["first_rate"]
                    base_top2 += base["top2_rate"]
                    base_top3 += base["top3_rate"]
                n = len(matching)
                stat["baseline_first_rate"] = base_first / n
                stat["baseline_top2_rate"] = base_top2 / n
                stat["baseline_top3_rate"] = base_top3 / n
                stat["delta_first"] = stat["first_rate"] - stat["baseline_first_rate"]
                stat["delta_top2"] = stat["top2_rate"] - stat["baseline_top2_rate"]
                stat["delta_top3"] = stat["top3_rate"] - stat["baseline_top3_rate"]
            by_type[label] = stat

        by_venue = {
            key.removeprefix("venue:"): value.summary()
            for (p, key), value in signals.items()
            if p == period and key.startswith("venue:")
        }
        output["periods"][period] = {
            "date_range": [start.isoformat(), end.isoformat()] if period == "september_all" else [adopted.isoformat(), end.isoformat()],
            "overall_instances": overall,
            "unique_signed_boats": unique.summary(),
            "by_type": by_type,
            "by_venue": by_venue,
        }

    return output


def main() -> None:
    if len(sys.argv) not in (3, 4):
        raise SystemExit("Usage: python3 analysis/evaluate_september_course_signals.py START END [ADOPTED_START]")
    start = parse_date(sys.argv[1])
    end = parse_date(sys.argv[2])
    adopted = parse_date(sys.argv[3]) if len(sys.argv) == 4 else date(2026, 9, 10)
    result = evaluate(start, end, adopted)

    out_dir = ROOT / "analysis/output"
    stem = f"september_course_signal_evaluation_{start:%Y%m%d}_{end:%Y%m%d}"
    json_path = out_dir / f"{stem}.json"
    csv_path = out_dir / f"{stem}.csv"
    json_path.write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding="utf-8")
    with csv_path.open("w", encoding="utf-8-sig", newline="") as fp:
        fields = ["period", "race_code", "race_date", "place", "course", "variant", "label", "player_id", "actual_rank"]
        writer = csv.DictWriter(fp, fieldnames=fields)
        writer.writeheader()
        writer.writerows(result["signals"])

    print(json.dumps({
        "completed_races": result["completed_races"],
        "signal_instances": result["signal_instances"],
        "periods": result["periods"],
        "json": str(json_path),
        "csv": str(csv_path),
    }, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
