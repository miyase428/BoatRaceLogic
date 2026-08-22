#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
現行予想のレースCSV・point-in-time決まり手キャッシュ・実結果を結合し、
決まり手分析専用の1レース1行CSVを出力する。

目的:
- 1逃げ成立時の2着・3着分布
- 外コース攻め率と隣接コースの連動
- イン飛び時の頭候補
- 出目・万舟条件

を、以後DBを叩かず同じデータセットだけで分析できるようにする。

使い方:
  python3 analysis/export_kimarite_analysis_dataset.py \
    analysis/output/final_prediction_races_fast_cached_20250815_20260814.csv \
    analysis/output/kimarite_cache_20250815_20260814.json

出力:
  analysis/output/kimarite_analysis_dataset_20250815_20260814.csv

注意:
- pre-race決まり手率は、キャッシュに入っている各対象レース日より前だけの値。
- actual_* と winner_technique はラベルであり、予測特徴量には使わない。
- 実進入変更に対応するため、実1～3着は「艇番」と「実進入コース」を両方持つ。
"""

from __future__ import annotations

import csv
import json
import re
import sys
from collections import defaultdict
from datetime import datetime
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from slit_validate_v2 import connect_db  # noqa: E402


KIMARITE_KEYS = (
    "win",
    "nige",
    "sashi",
    "makuri",
    "makurizashi",
    "nogashi",
    "sasare",
    "makurare",
    "makurarezashi",
)

PERIODS = (
    ("6month", "6m"),
    ("1year", "1y"),
)


def load_race_csv(path: Path) -> list[dict[str, str]]:
    with path.open("r", encoding="utf-8-sig", newline="") as f:
        return list(csv.DictReader(f))


def load_cache(path: Path) -> dict:
    with path.open("r", encoding="utf-8") as f:
        data = json.load(f)
    if not isinstance(data, dict):
        raise RuntimeError("kimarite cache の形式が不正です")
    return data


def parse_date_range(rows: list[dict[str, str]]):
    dates = []
    for row in rows:
        value = (row.get("race_date") or "").strip()
        if not value:
            continue
        dates.append(datetime.strptime(value, "%Y-%m-%d").date())
    if not dates:
        raise RuntimeError("レースCSVからrace_dateを取得できません")
    return min(dates), max(dates)


def output_path_from_cache(cache_path: Path) -> Path:
    m = re.search(r"kimarite_cache_(\d{8})_(\d{8})\.json$", cache_path.name)
    if m:
        label = f"{m.group(1)}_{m.group(2)}"
    else:
        label = "joined"
    return cache_path.parent / f"kimarite_analysis_dataset_{label}.csv"


def load_actual_results(conn, start_date, end_date, race_codes: set[str]) -> dict[str, dict]:
    """実1～3着の艇番・実進入と、勝者決まり手を一括取得。"""
    sql = """
WITH target AS (
    SELECT race_code
    FROM boat_race.race_master
    WHERE race_date BETWEEN %s::date AND %s::date
),
ranked AS (
    SELECT DISTINCT ON (rrd.race_code, TRIM(rrd.rank))
        rrd.race_code,
        TRIM(rrd.rank) AS rank,
        rrd.player_id,
        rrd.entry_course::integer AS entry_course,
        TRIM(COALESCE(rrd.technique, '')) AS technique
    FROM boat_race.race_result_detail rrd
    JOIN target t
      ON t.race_code = rrd.race_code
    WHERE TRIM(rrd.rank) IN ('1', '2', '3')
    ORDER BY rrd.race_code, TRIM(rrd.rank), rrd.player_id
)
SELECT
    r.race_code,
    r.rank,
    re.lane_number::integer AS boat,
    r.entry_course,
    r.technique
FROM ranked r
LEFT JOIN boat_race.race_entry re
  ON re.race_code = r.race_code
 AND re.player_id = r.player_id
ORDER BY r.race_code, r.rank
    """

    out: dict[str, dict] = defaultdict(dict)
    with conn.cursor() as cur:
        cur.execute(sql, (start_date, end_date))
        for race_code, rank, boat, course, technique in cur.fetchall():
            race_code = str(race_code)
            if race_code not in race_codes:
                continue
            rank_i = int(str(rank).strip())
            if rank_i not in (1, 2, 3):
                continue
            out[race_code][rank_i] = {
                "boat": int(boat) if boat is not None else 0,
                "course": int(course) if course is not None else 0,
                "technique": str(technique or "").strip(),
            }
    return dict(out)


def base_columns(source_fields: list[str]) -> list[str]:
    # 元レースCSVはそのまま残す。
    cols = list(source_fields)
    extra = [
        "winner_technique",
        "actual_1st_course",
        "actual_2nd_course",
        "actual_3rd_course",
        "result_top3_course_complete",
        "result_boat_match",
    ]
    for c in extra:
        if c not in cols:
            cols.append(c)
    return cols


def kimarite_columns() -> list[str]:
    cols: list[str] = []
    for course in range(1, 7):
        for period_key, short in PERIODS:
            _ = period_key
            cols.append(f"c{course}_{short}_sample_n")
            for key in KIMARITE_KEYS:
                cols.append(f"c{course}_{short}_{key}")
    return cols


def flatten_kimarite(cache_row: dict) -> dict[str, object]:
    out: dict[str, object] = {}
    for course in range(1, 7):
        cdata = cache_row.get(str(course), {}) if isinstance(cache_row, dict) else {}
        for period_key, short in PERIODS:
            pdata = cdata.get(period_key, {}) if isinstance(cdata, dict) else {}
            out[f"c{course}_{short}_sample_n"] = int(pdata.get("_sample_n", 0) or 0)
            for key in KIMARITE_KEYS:
                value = pdata.get(key, 0)
                try:
                    out[f"c{course}_{short}_{key}"] = float(value or 0)
                except (TypeError, ValueError):
                    out[f"c{course}_{short}_{key}"] = 0.0
    return out


def main() -> None:
    if len(sys.argv) != 3:
        print(
            "Usage: python3 analysis/export_kimarite_analysis_dataset.py RACES_CSV KIMARITE_CACHE_JSON",
            file=sys.stderr,
        )
        sys.exit(1)

    races_path = Path(sys.argv[1]).resolve()
    cache_path = Path(sys.argv[2]).resolve()

    if not races_path.is_file():
        raise RuntimeError(f"レースCSVがありません: {races_path}")
    if not cache_path.is_file():
        raise RuntimeError(f"kimarite cacheがありません: {cache_path}")

    print("レースCSVを読み込み中...", flush=True)
    race_rows = load_race_csv(races_path)
    if not race_rows:
        raise RuntimeError("レースCSVが空です")

    race_codes = {
        (row.get("race_code") or "").strip()
        for row in race_rows
        if (row.get("race_code") or "").strip()
    }
    start_date, end_date = parse_date_range(race_rows)

    print(f"  レースCSV行: {len(race_rows)}", flush=True)
    print(f"  race_code  : {len(race_codes)}", flush=True)
    print(f"  期間       : {start_date} ～ {end_date}", flush=True)

    print("kimarite cacheを読み込み中...", flush=True)
    cache = load_cache(cache_path)
    print(f"  cache race : {len(cache)}", flush=True)

    print("実1～3着・実進入・決まり手を一括取得中...", flush=True)
    with connect_db() as conn:
        actual = load_actual_results(conn, start_date, end_date, race_codes)
    print(f"  実結果race : {len(actual)}", flush=True)

    out_path = output_path_from_cache(cache_path)
    out_path.parent.mkdir(parents=True, exist_ok=True)

    source_fields = list(race_rows[0].keys())
    fields = base_columns(source_fields) + kimarite_columns()

    complete_top3 = 0
    boat_match = 0
    cache_complete = 0
    output_rows = 0

    with out_path.open("w", encoding="utf-8", newline="") as f:
        writer = csv.DictWriter(f, fieldnames=fields, extrasaction="ignore")
        writer.writeheader()

        for row in race_rows:
            race_code = (row.get("race_code") or "").strip()
            if not race_code:
                continue

            result = actual.get(race_code, {})
            r1 = result.get(1, {})
            r2 = result.get(2, {})
            r3 = result.get(3, {})

            courses = [
                int(r1.get("course", 0) or 0),
                int(r2.get("course", 0) or 0),
                int(r3.get("course", 0) or 0),
            ]
            top3_ok = all(1 <= c <= 6 for c in courses)
            if top3_ok:
                complete_top3 += 1

            csv_boats = [
                int(row.get("actual_1st") or 0),
                int(row.get("actual_2nd") or 0),
                int(row.get("actual_3rd") or 0),
            ]
            db_boats = [
                int(r1.get("boat", 0) or 0),
                int(r2.get("boat", 0) or 0),
                int(r3.get("boat", 0) or 0),
            ]
            boats_ok = all(b > 0 for b in db_boats) and csv_boats == db_boats
            if boats_ok:
                boat_match += 1

            cache_row = cache.get(race_code, {})
            cache_ok = isinstance(cache_row, dict) and all(
                str(course) in cache_row for course in range(1, 7)
            )
            if cache_ok:
                cache_complete += 1

            out = dict(row)
            out.update(
                {
                    "winner_technique": str(r1.get("technique", "") or ""),
                    "actual_1st_course": courses[0] if courses[0] else "",
                    "actual_2nd_course": courses[1] if courses[1] else "",
                    "actual_3rd_course": courses[2] if courses[2] else "",
                    "result_top3_course_complete": 1 if top3_ok else 0,
                    "result_boat_match": 1 if boats_ok else 0,
                }
            )
            out.update(flatten_kimarite(cache_row))

            writer.writerow(out)
            output_rows += 1

    print("\n" + "=" * 72)
    print("決まり手分析用データセット出力完了")
    print("=" * 72)
    print(f"期間                     : {start_date} ～ {end_date}")
    print(f"出力行                   : {output_rows}")
    print(f"実1～3着コース完備       : {complete_top3}")
    print(f"レースCSV着順との艇番一致: {boat_match}")
    print(f"kimarite 6コース完備      : {cache_complete}")
    print(f"出力                     : {out_path}")
    print("=" * 72)


if __name__ == "__main__":
    main()
