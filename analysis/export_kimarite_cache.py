#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
対象期間の全レースについて、kimarite_api.php と同じ決まり手集計を
「履歴一括読込 + メモリ上の期間切り出し」で高速生成する。

重要:
- 対象レース当日は含めず、そのレース日より前だけを履歴に使う。
- 12ヶ月 / 6ヶ月は各対象レース日を基準にする。
- in_course=123456（現行 FinalPredictionExporter と同じ）を再現する。
- win は純粋な1着率、nige は本当に「逃げ」で勝った率。
- 本番APIや予想ロジックは変更しない。まずキャッシュの一致検証専用。

使い方:
  python3 analysis/export_kimarite_cache.py 2026-08-14 2026-08-14

出力:
  analysis/output/kimarite_cache_YYYYMMDD_YYYYMMDD.json
"""

from __future__ import annotations

import bisect
import json
import sys
import time
from calendar import monthrange
from collections import defaultdict
from datetime import date, datetime
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from slit_validate_v2 import connect_db  # noqa: E402


KEYS = (
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


def parse_date(value: str) -> date:
    return datetime.strptime(value, "%Y-%m-%d").date()


def months_ago(d: date, months: int) -> date:
    total = d.year * 12 + (d.month - 1) - months
    year = total // 12
    month = total % 12 + 1
    day = min(d.day, monthrange(year, month)[1])
    return date(year, month, day)


def php_round_1(value: float) -> float:
    # 今回の値は0以上。PHP round(..., 1) の HALF_UP 相当。
    return int(value * 10.0 + 0.5 + 1e-12) / 10.0


def blank_counts() -> dict[str, int]:
    return {k: 0 for k in KEYS}


def event_counts(
    player_id: str,
    course: int,
    winner_player_id: str,
    winner_course: int,
    winner_technique: str,
) -> dict[str, int]:
    c = blank_counts()
    won = winner_player_id == player_id

    if won:
        c["win"] = 1

    if course == 1 and won and winner_technique == "逃げ":
        c["nige"] = 1

    if course == 1 and not won and winner_technique == "差し":
        c["sasare"] = 1
    if course == 1 and not won and winner_technique == "まくり":
        c["makurare"] = 1
    if course == 1 and not won and winner_technique == "まくり差し":
        c["makurarezashi"] = 1

    if course == 2 and not won and winner_course == 1:
        c["nogashi"] = 1

    if course != 1 and won and winner_technique == "差し":
        c["sashi"] = 1
    if course != 1 and won and winner_technique == "まくり":
        c["makuri"] = 1
    if course != 1 and won and winner_technique == "まくり差し":
        c["makurizashi"] = 1

    return c


def load_targets(conn, start_date: date, end_date: date) -> list[dict]:
    sql = """
        SELECT
            rm.race_code,
            rm.race_date,
            re.lane_number,
            re.player_id::text
        FROM boat_race.race_master rm
        JOIN boat_race.race_entry re
          ON re.race_code = rm.race_code
        WHERE rm.race_date BETWEEN %s::date AND %s::date
          AND re.lane_number BETWEEN 1 AND 6
        ORDER BY rm.race_date, rm.race_code, re.lane_number
    """
    out = []
    with conn.cursor() as cur:
        cur.execute(sql, (start_date, end_date))
        for race_code, race_date, lane, player_id in cur.fetchall():
            out.append(
                {
                    "race_code": str(race_code),
                    "date": race_date,
                    "course": int(lane),  # in_course=123456 を再現
                    "player_id": str(player_id).strip(),
                }
            )
    return out


def load_history(
    conn,
    start_date: date,
    end_date: date,
) -> list[dict]:
    history_start = months_ago(start_date, 12)

    # 対象期間に登場する選手だけを履歴対象にする。
    sql = """
WITH target_players AS (
    SELECT DISTINCT re.player_id
    FROM boat_race.race_master rm
    JOIN boat_race.race_entry re
      ON re.race_code = rm.race_code
    WHERE rm.race_date BETWEEN %s::date AND %s::date
),
hr AS (
    SELECT race_code, race_date
    FROM boat_race.race_master
    WHERE race_date >= %s::date
      AND race_date < %s::date + INTERVAL '1 day'
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
    COALESCE(rd.entry_course, ex.entry_course)::integer AS entry_course,
    hr.race_date,
    w.winner_player_id,
    COALESCE(w.winner_course, 0),
    w.winner_technique
FROM boat_race.race_entry re
JOIN target_players tp
  ON tp.player_id = re.player_id
JOIN hr
  ON hr.race_code = re.race_code
LEFT JOIN rd_map rd
  ON rd.race_code = re.race_code
 AND rd.player_id = re.player_id
LEFT JOIN ex_map ex
  ON ex.race_code = re.race_code
 AND ex.player_id = re.player_id
JOIN winner w
  ON w.race_code = re.race_code
WHERE COALESCE(rd.entry_course, ex.entry_course) BETWEEN 1 AND 6
ORDER BY re.player_id, entry_course, hr.race_date
    """

    out = []
    with conn.cursor() as cur:
        cur.execute(
            sql,
            (start_date, end_date, history_start, end_date),
        )
        for pid, course, race_date, winner_pid, winner_course, technique in cur.fetchall():
            pid = str(pid).strip()
            out.append(
                {
                    "player_id": pid,
                    "course": int(course),
                    "date": race_date,
                    "counts": event_counts(
                        pid,
                        int(course),
                        str(winner_pid).strip(),
                        int(winner_course or 0),
                        str(technique or "").strip(),
                    ),
                }
            )
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
            prefix = {k: [0] for k in KEYS}

            for item in items:
                prefix_n.append(prefix_n[-1] + 1)
                for k in KEYS:
                    prefix[k].append(prefix[k][-1] + int(item["counts"][k]))

            self.data[key] = {
                "dates": dates,
                "prefix_n": prefix_n,
                "prefix": prefix,
            }

    def profile(self, player_id: str, course: int, target_date: date, months: int) -> dict:
        item = self.data.get((player_id, course))
        if item is None:
            return {"n": 0, "counts": blank_counts()}

        lower = months_ago(target_date, months)
        dates = item["dates"]
        lo = bisect.bisect_left(dates, lower)
        hi = bisect.bisect_left(dates, target_date)  # 当日を除外

        n = hi - lo
        counts = {
            k: item["prefix"][k][hi] - item["prefix"][k][lo]
            for k in KEYS
        }
        return {"n": n, "counts": counts}


def api_shape(profile: dict) -> dict:
    n = int(profile["n"])
    counts = profile["counts"]
    out = {}
    for key in KEYS:
        cnt = int(counts.get(key, 0))
        out[key] = php_round_1(100.0 * cnt / n) if n else 0.0
    out["_sample_n"] = n
    out["_counts"] = {key: int(counts.get(key, 0)) for key in KEYS}
    return out


def main() -> None:
    if len(sys.argv) != 3:
        print(
            "Usage: python3 analysis/export_kimarite_cache.py START_DATE END_DATE",
            file=sys.stderr,
        )
        sys.exit(1)

    start_date = parse_date(sys.argv[1])
    end_date = parse_date(sys.argv[2])
    if start_date > end_date:
        raise RuntimeError("開始日が終了日より後です")

    started = time.perf_counter()

    print("対象レースを読み込み中...", flush=True)
    with connect_db() as conn:
        targets = load_targets(conn, start_date, end_date)
    race_codes = sorted({x["race_code"] for x in targets})
    print(f"  対象レース: {len(race_codes)}", flush=True)
    print(f"  対象profile: {len(targets)}", flush=True)

    print("決まり手履歴を一括読み込み中...", flush=True)
    with connect_db() as conn:
        history = load_history(conn, start_date, end_date)
    print(f"  履歴行: {len(history)}", flush=True)

    print("履歴インデックスを作成中...", flush=True)
    index = HistoryIndex(history)

    cache: dict[str, dict[str, dict]] = {}
    for i, target in enumerate(targets, start=1):
        race_code = target["race_code"]
        course = target["course"]
        pid = target["player_id"]
        target_date = target["date"]

        p12 = index.profile(pid, course, target_date, 12)
        p6 = index.profile(pid, course, target_date, 6)

        cache.setdefault(race_code, {})[str(course)] = {
            "1year": api_shape(p12),
            "6month": api_shape(p6),
        }

        if i % 5000 == 0:
            print(f"  {i}/{len(targets)} profile 完了", flush=True)

    out_dir = Path(__file__).resolve().parent / "output"
    out_dir.mkdir(parents=True, exist_ok=True)
    start_label = start_date.strftime("%Y%m%d")
    end_label = end_date.strftime("%Y%m%d")
    out_path = out_dir / f"kimarite_cache_{start_label}_{end_label}.json"

    with out_path.open("w", encoding="utf-8") as f:
        json.dump(cache, f, ensure_ascii=False, separators=(",", ":"))

    elapsed = time.perf_counter() - started
    print("\n" + "=" * 60)
    print("決まり手一括キャッシュ生成完了")
    print("=" * 60)
    print(f"期間       : {start_date} ～ {end_date}")
    print(f"対象レース : {len(race_codes)}")
    print(f"profile    : {len(targets)}")
    print(f"履歴行     : {len(history)}")
    print(f"所要時間   : {elapsed:.2f}秒")
    print(f"出力       : {out_path}")
    print("=" * 60)


if __name__ == "__main__":
    main()
