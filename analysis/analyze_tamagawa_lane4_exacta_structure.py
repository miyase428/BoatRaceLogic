#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
多摩川4コースについて、4コースまくり率と3コースとの平均ST順位関係で
2連単30通りの出目構造がどう変わるかを見る。

主な出力:
- 4コースまくり率4帯ごとの2連単30通り
- 4が3より平均ST順位 上/同じ/下 × まくり率帯ごとの2連単30通り
- 4号艇の1着率 / 2着率 / 2連対率 / 3連対率
- 1-4 / 4-1 など4絡みの代表出目

決まり手履歴は対象レース当日を含めず、過去12ヶ月・6ヶ月を別々に集計。

Usage:
  python3 analysis/analyze_tamagawa_lane4_exacta_structure.py 2025-09-01 2026-09-09
"""

from __future__ import annotations

import csv
import sys
from collections import Counter, defaultdict
from datetime import date
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from analyze_tamagawa_boaters_hypothesis import (  # noqa: E402
    HISTORY_MONTHS,
    TechniqueHistoryIndex,
    load_history,
    load_racer_results,
    months_ago,
    parse_date,
    rate_band,
    relation_label,
    required_terms,
    term_info_for_date,
)
from slit_validate_v2 import connect_db  # noqa: E402

VENUE_CODE = "TMG"
VENUE_NAME = "多摩川"
BANDS = ("0.0-4.9", "5.0-9.9", "10.0-14.9", "15.0+")
RELATIONS = ("内側より上", "同じ", "内側より下")
EXACTAS = tuple(f"{a}-{b}" for a in range(1, 7) for b in range(1, 7) if a != b)


def pct(num: int, den: int) -> float:
    return 100.0 * num / den if den else 0.0


def load_targets(start_date: date, end_date: date) -> dict[str, dict]:
    sql = """
WITH result_course AS (
    SELECT DISTINCT ON (race_code, player_id)
        race_code, player_id, entry_course::integer AS entry_course
    FROM boat_race.race_result_detail
    WHERE entry_course BETWEEN 1 AND 6
    ORDER BY race_code, player_id
),
ex_course AS (
    SELECT DISTINCT ON (race_code, player_id)
        race_code, player_id, entry_course::integer AS entry_course
    FROM boat_race.exhibition_live
    WHERE entry_course BETWEEN 1 AND 6
    ORDER BY race_code, player_id
),
finish AS (
    SELECT
        race_code,
        MAX(entry_course::integer) FILTER (WHERE TRIM(rank::text) = '1') AS first_course,
        MAX(entry_course::integer) FILTER (WHERE TRIM(rank::text) = '2') AS second_course,
        MAX(entry_course::integer) FILTER (WHERE TRIM(rank::text) = '3') AS third_course
    FROM boat_race.race_result_detail
    GROUP BY race_code
)
SELECT
    rm.race_code,
    rm.race_date,
    re.player_id::text,
    COALESCE(rc.entry_course, ec.entry_course)::integer AS entry_course,
    f.first_course,
    f.second_course,
    f.third_course
FROM boat_race.race_master rm
JOIN boat_race.race_entry re ON re.race_code = rm.race_code
LEFT JOIN result_course rc
  ON rc.race_code = re.race_code AND rc.player_id = re.player_id
LEFT JOIN ex_course ec
  ON ec.race_code = re.race_code AND ec.player_id = re.player_id
JOIN finish f ON f.race_code = rm.race_code
WHERE rm.race_date BETWEEN %s::date AND %s::date
  AND SUBSTRING(rm.race_code, 9, 3) = %s
  AND f.first_course BETWEEN 1 AND 6
  AND f.second_course BETWEEN 1 AND 6
ORDER BY rm.race_date, rm.race_code, entry_course NULLS LAST
    """

    races = defaultdict(lambda: {"boats": [], "date": None, "first": None, "second": None, "third": None})
    with connect_db() as conn:
        with conn.cursor() as cur:
            cur.execute(sql, (start_date, end_date, VENUE_CODE))
            for race_code, race_date, pid, course, first, second, third in cur.fetchall():
                r = races[str(race_code)]
                r["date"] = race_date
                r["first"] = int(first)
                r["second"] = int(second)
                r["third"] = int(third) if third is not None else None
                r["boats"].append({"player_id": str(pid).strip(), "course": int(course) if course else None})
    return races


def add_outcome(stat: dict, first: int, second: int, third: int | None) -> None:
    stat["n"] += 1
    stat["exacta"][f"{first}-{second}"] += 1
    if first == 4:
        stat["lane4_first"] += 1
    if second == 4:
        stat["lane4_second"] += 1
    if first == 4 or second == 4:
        stat["lane4_top2"] += 1
    if first == 4 or second == 4 or third == 4:
        stat["lane4_top3"] += 1


def blank_stat() -> dict:
    return {
        "n": 0,
        "lane4_first": 0,
        "lane4_second": 0,
        "lane4_top2": 0,
        "lane4_top3": 0,
        "exacta": Counter(),
    }


def print_lane4_metrics(stat: dict, prefix: str = "") -> None:
    n = stat["n"]
    print(
        f"{prefix}N={n:4d}  "
        f"4-1着={pct(stat['lane4_first'], n):6.2f}%  "
        f"4-2着={pct(stat['lane4_second'], n):6.2f}%  "
        f"4-2連対={pct(stat['lane4_top2'], n):6.2f}%  "
        f"4-3連対={pct(stat['lane4_top3'], n):6.2f}%"
    )


def print_exacta30(stat: dict) -> None:
    n = stat["n"]
    ranked = sorted(EXACTAS, key=lambda x: (-stat["exacta"][x], x))
    for i, ex in enumerate(ranked, start=1):
        c = stat["exacta"][ex]
        print(f"{i:2d}. {ex:<4} N={c:4d}  出現率={pct(c, n):6.2f}%")


def analyze(start_date: date, end_date: date):
    print(f"{VENUE_NAME} 対象レースを読み込み中...", flush=True)
    races = load_targets(start_date, end_date)
    print(f"  対象レース候補: {len(races)}", flush=True)

    target_pids = sorted({b["player_id"] for r in races.values() for b in r["boats"]})
    terms = required_terms(start_date, end_date)
    print(f"racer_results 読み込み中... terms={','.join(terms)}", flush=True)
    racer = load_racer_results(terms)

    print("決まり手履歴を一括読み込み中...", flush=True)
    history = load_history(start_date, end_date, target_pids)
    print(f"  履歴行: {len(history)}", flush=True)
    hist_index = TechniqueHistoryIndex(history)

    baseline = {m: blank_stat() for m in HISTORY_MONTHS}
    by_band = {(m, b): blank_stat() for m in HISTORY_MONTHS for b in BANDS}
    by_cross = {(m, rel, b): blank_stat() for m in HISTORY_MONTHS for rel in RELATIONS for b in BANDS}
    skips = Counter()
    processed = 0

    for race_code, race in races.items():
        boats = race["boats"]
        if len(boats) != 6:
            skips["not_6_boats"] += 1
            continue
        by_course = {}
        bad = False
        for b in boats:
            c = b["course"]
            if c not in range(1, 7) or c in by_course:
                bad = True
                break
            by_course[c] = b
        if bad or set(by_course) != set(range(1, 7)):
            skips["bad_entry_course"] += 1
            continue

        race_date = race["date"]
        term = term_info_for_date(race_date)
        ranks = {}
        missing = False
        for c in (3, 4):
            rr = racer.get((term, by_course[c]["player_id"]))
            if rr is None or rr[c]["avg_rank"] is None:
                missing = True
                break
            ranks[c] = float(rr[c]["avg_rank"])
        if missing:
            skips["missing_course_avg_rank"] += 1
            continue

        relation = relation_label(ranks[4], ranks[3])
        first, second, third = race["first"], race["second"], race["third"]
        processed += 1

        for months in HISTORY_MONTHS:
            p4 = hist_index.profile(by_course[4]["player_id"], 4, race_date, months)
            if p4["n"] <= 0:
                skips[f"history0_{months}m"] += 1
                continue
            makuri_rate = pct(p4["tech"]["まくり"], p4["n"])
            band = rate_band(makuri_rate)
            add_outcome(baseline[months], first, second, third)
            add_outcome(by_band[(months, band)], first, second, third)
            add_outcome(by_cross[(months, relation, band)], first, second, third)

    return processed, skips, baseline, by_band, by_cross


def write_csv(start_date, end_date, baseline, by_band, by_cross):
    out_dir = Path(__file__).resolve().parent / "output"
    out_dir.mkdir(parents=True, exist_ok=True)
    label = f"{start_date:%Y%m%d}_{end_date:%Y%m%d}"
    path = out_dir / f"tamagawa_lane4_exacta_structure_{label}.csv"
    with path.open("w", newline="", encoding="utf-8-sig") as f:
        w = csv.writer(f)
        w.writerow(["history_months", "start_relation", "makuri_band", "exacta", "n_races", "count", "rate_pct"])
        for months in HISTORY_MONTHS:
            for band in BANDS:
                s = by_band[(months, band)]
                for ex in EXACTAS:
                    w.writerow([months, "ALL", band, ex, s["n"], s["exacta"][ex], f"{pct(s['exacta'][ex], s['n']):.4f}"])
            for rel in RELATIONS:
                for band in BANDS:
                    s = by_cross[(months, rel, band)]
                    for ex in EXACTAS:
                        w.writerow([months, rel, band, ex, s["n"], s["exacta"][ex], f"{pct(s['exacta'][ex], s['n']):.4f}"])
    return path


def main() -> None:
    if len(sys.argv) != 3:
        print("Usage: python3 analysis/analyze_tamagawa_lane4_exacta_structure.py START_DATE END_DATE", file=sys.stderr)
        sys.exit(1)
    start_date = parse_date(sys.argv[1])
    end_date = parse_date(sys.argv[2])
    if start_date > end_date:
        raise RuntimeError("開始日が終了日より後です")

    processed, skips, baseline, by_band, by_cross = analyze(start_date, end_date)

    print("\n" + "=" * 100)
    print("多摩川4コース まくり率 × 2連単30通り 出目構造")
    print("=" * 100)
    print(f"対象期間   : {start_date} ～ {end_date}")
    print(f"採用候補   : {processed}")
    if skips:
        print("スキップ   : " + ", ".join(f"{k}={v}" for k, v in skips.items()))

    for months in HISTORY_MONTHS:
        print("\n" + "-" * 100)
        print(f"【過去{months}ヶ月の4コースまくり率】")
        print("-" * 100)
        print("\n■ 基準")
        print_lane4_metrics(baseline[months])

        for band in BANDS:
            s = by_band[(months, band)]
            print(f"\n■ 4コースまくり率 {band}")
            print_lane4_metrics(s)
            print_exacta30(s)

        print("\n■ 4が3より平均ST順位『上』だけを抽出")
        for band in BANDS:
            s = by_cross[(months, "内側より上", band)]
            print(f"\n[4まくり {band}] ")
            print_lane4_metrics(s)
            print_exacta30(s)

        print("\n■ 参考: ST順位関係 × まくり率帯 の4号艇成績")
        for rel in RELATIONS:
            print(f"\n[{rel}]")
            for band in BANDS:
                print(f"4まくり {band:<11} ", end="")
                print_lane4_metrics(by_cross[(months, rel, band)])

    path = write_csv(start_date, end_date, baseline, by_band, by_cross)
    print("\n" + "=" * 100)
    print(f"CSV出力: {path}")
    print("=" * 100)


if __name__ == "__main__":
    main()
