#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
多摩川について、BOATERS動画で紹介されていた仮説をまず素直に追試する。

今回の第一段階では級別・勝率・展示などは入れない。
主に見るもの:
  1) 各コースのコース別平均ST順位が、ひとつ内側の艇より上/同じ/下か
  2) 各選手の「そのコースでの決まり手率」
  3) 決まり手率帯ごとの実際の1着率
  4) ST順位関係 × 決まり手率帯 のクロス

BOATERS仮説の代表例:
  - 多摩川4コースで、4のST順位が3より良いと4の1着率が上がる
  - 4コース時のまくり率が高いほど4の1着率が上がる

決まり手率帯:
  0.0-4.9 / 5.0-9.9 / 10.0-14.9 / 15.0%以上

決まり手履歴は対象レース当日を含めず、過去12ヶ月・6ヶ月を別々に集計する。
対象レースの進入コースは race_result_detail.entry_course を優先し、
無い艇は exhibition_live.entry_course で補う。

使い方:
  python3 analysis/analyze_tamagawa_boaters_hypothesis.py 2025-09-01 2026-09-09

CSV出力:
  analysis/output/tamagawa_boaters_summary_YYYYMMDD_YYYYMMDD.csv
  analysis/output/tamagawa_boaters_cross_YYYYMMDD_YYYYMMDD.csv
"""

from __future__ import annotations

import bisect
import csv
import math
import sys
from calendar import monthrange
from collections import Counter, defaultdict
from datetime import date, datetime, timedelta
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from slit_validate_v2 import connect_db  # noqa: E402

VENUE_CODE = "TMG"
VENUE_NAME = "多摩川"

TECHNIQUES = ("逃げ", "差し", "まくり", "まくり差し", "抜き", "恵まれ")
HISTORY_MONTHS = (12, 6)


def parse_date(value: str) -> date:
    return datetime.strptime(value, "%Y-%m-%d").date()


def months_ago(d: date, months: int) -> date:
    total = d.year * 12 + (d.month - 1) - months
    year = total // 12
    month = total % 12 + 1
    day = min(d.day, monthrange(year, month)[1])
    return date(year, month, day)


def term_info_for_date(dt: date) -> str:
    yy = dt.year % 100
    if dt.month <= 4:
        return f"{(dt.year - 1) % 100:02d}10"
    if dt.month <= 10:
        return f"{yy:02d}04"
    return f"{yy:02d}10"


def required_terms(start_date: date, end_date: date) -> list[str]:
    out = set()
    d = start_date
    while d <= end_date:
        out.add(term_info_for_date(d))
        d += timedelta(days=1)
    return sorted(out)


def as_float(v):
    if v is None or v == "":
        return None
    try:
        x = float(v)
    except (TypeError, ValueError):
        return None
    return x if math.isfinite(x) else None


def as_int(v):
    x = as_float(v)
    if x is None:
        return None
    return int(x)


def rate_band(pct: float) -> str:
    if pct < 5.0:
        return "0.0-4.9"
    if pct < 10.0:
        return "5.0-9.9"
    if pct < 15.0:
        return "10.0-14.9"
    return "15.0+"


def relation_label(subject_rank: float, inner_rank: float) -> str:
    # 平均ST順位は数値が小さいほど上位。
    if abs(subject_rank - inner_rank) < 1e-9:
        return "同じ"
    return "内側より上" if subject_rank < inner_rank else "内側より下"


def load_racer_results(terms: list[str]) -> dict:
    cols = ["term_info", "player_id"]
    for c in range(1, 7):
        cols += [f"course{c}_entry", f"course{c}_average_rank"]

    sql = f"""
        SELECT {', '.join(cols)}
        FROM boat_race.racer_results
        WHERE term_info::text = ANY(%s)
    """

    out = {}
    with connect_db() as conn:
        with conn.cursor() as cur:
            cur.execute(sql, (terms,))
            for row in cur.fetchall():
                term = str(row[0]).strip()
                pid = str(row[1]).strip()
                idx = 2
                courses = {}
                for c in range(1, 7):
                    entry = as_int(row[idx]); idx += 1
                    avg_rank = as_float(row[idx]); idx += 1
                    courses[c] = {
                        "entry": max(0, entry or 0),
                        "avg_rank": avg_rank,
                    }
                out[(term, pid)] = courses
    return out


def load_targets(start_date: date, end_date: date) -> dict[str, list[dict]]:
    # TMGは race_code の9～11文字目（1始まり）に入る。
    sql = """
WITH result_course AS (
    SELECT DISTINCT ON (race_code, player_id)
        race_code,
        player_id,
        entry_course::integer AS entry_course
    FROM boat_race.race_result_detail
    WHERE entry_course BETWEEN 1 AND 6
    ORDER BY race_code, player_id
),
ex_course AS (
    SELECT DISTINCT ON (race_code, player_id)
        race_code,
        player_id,
        entry_course::integer AS entry_course
    FROM boat_race.exhibition_live
    WHERE entry_course BETWEEN 1 AND 6
    ORDER BY race_code, player_id
),
winner AS (
    SELECT DISTINCT ON (race_code)
        race_code,
        player_id::text AS winner_player_id,
        entry_course::integer AS winner_course,
        TRIM(COALESCE(technique, '')) AS technique
    FROM boat_race.race_result_detail
    WHERE TRIM(rank) = '1'
    ORDER BY race_code
)
SELECT
    rm.race_code,
    rm.race_date,
    re.player_id::text,
    COALESCE(rc.entry_course, ec.entry_course)::integer AS entry_course,
    w.winner_player_id,
    w.winner_course,
    w.technique
FROM boat_race.race_master rm
JOIN boat_race.race_entry re
  ON re.race_code = rm.race_code
LEFT JOIN result_course rc
  ON rc.race_code = re.race_code
 AND rc.player_id = re.player_id
LEFT JOIN ex_course ec
  ON ec.race_code = re.race_code
 AND ec.player_id = re.player_id
JOIN winner w
  ON w.race_code = rm.race_code
WHERE rm.race_date BETWEEN %s::date AND %s::date
  AND SUBSTRING(rm.race_code, 9, 3) = %s
ORDER BY rm.race_date, rm.race_code, entry_course NULLS LAST
    """

    races = defaultdict(list)
    with connect_db() as conn:
        with conn.cursor() as cur:
            cur.execute(sql, (start_date, end_date, VENUE_CODE))
            for race_code, race_date, player_id, course, winner_pid, winner_course, technique in cur.fetchall():
                races[str(race_code)].append(
                    {
                        "date": race_date,
                        "player_id": str(player_id).strip(),
                        "course": as_int(course),
                        "winner_player_id": str(winner_pid).strip(),
                        "winner_course": as_int(winner_course),
                        "winner_technique": str(technique or "").strip(),
                    }
                )
    return races


def load_history(start_date: date, end_date: date, target_player_ids: list[str]) -> list[dict]:
    history_start = months_ago(start_date, 12)
    sql = """
WITH hr AS (
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
JOIN winner w
  ON w.race_code = re.race_code
WHERE re.player_id::text = ANY(%s)
  AND COALESCE(rd.entry_course, ex.entry_course) BETWEEN 1 AND 6
ORDER BY re.player_id, entry_course, hr.race_date
    """

    out = []
    with connect_db() as conn:
        with conn.cursor() as cur:
            cur.execute(sql, (history_start, end_date, target_player_ids))
            for pid, course, race_date, winner_pid, winner_course, technique in cur.fetchall():
                pid = str(pid).strip()
                winner_pid = str(winner_pid).strip()
                won = pid == winner_pid
                out.append(
                    {
                        "player_id": pid,
                        "course": int(course),
                        "date": race_date,
                        "won": won,
                        "winner_course": int(winner_course or 0),
                        "technique": str(technique or "").strip() if won else "",
                    }
                )
    return out


class TechniqueHistoryIndex:
    def __init__(self, rows: list[dict]):
        grouped = defaultdict(list)
        for row in rows:
            grouped[(row["player_id"], row["course"])].append(row)

        self.data = {}
        for key, items in grouped.items():
            items.sort(key=lambda x: x["date"])
            dates = [x["date"] for x in items]
            prefix_n = [0]
            prefix_win = [0]
            prefix_tech = {t: [0] for t in TECHNIQUES}

            for item in items:
                prefix_n.append(prefix_n[-1] + 1)
                prefix_win.append(prefix_win[-1] + (1 if item["won"] else 0))
                for t in TECHNIQUES:
                    prefix_tech[t].append(prefix_tech[t][-1] + (1 if item["won"] and item["technique"] == t else 0))

            self.data[key] = {
                "dates": dates,
                "prefix_n": prefix_n,
                "prefix_win": prefix_win,
                "prefix_tech": prefix_tech,
            }

    def profile(self, player_id: str, course: int, target_date: date, months: int) -> dict:
        item = self.data.get((player_id, course))
        if item is None:
            return {"n": 0, "win": 0, "tech": {t: 0 for t in TECHNIQUES}}

        lower = months_ago(target_date, months)
        dates = item["dates"]
        lo = bisect.bisect_left(dates, lower)
        hi = bisect.bisect_left(dates, target_date)  # 対象日を含めない

        return {
            "n": hi - lo,
            "win": item["prefix_win"][hi] - item["prefix_win"][lo],
            "tech": {
                t: item["prefix_tech"][t][hi] - item["prefix_tech"][t][lo]
                for t in TECHNIQUES
            },
        }


def pct(num: int, den: int) -> float:
    return 100.0 * num / den if den else 0.0


def blank_stat() -> dict:
    return {"n": 0, "win": 0}


def add_stat(stat: dict, won: bool) -> None:
    stat["n"] += 1
    if won:
        stat["win"] += 1


def print_stat(label: str, stat: dict) -> None:
    print(f"{label:<18} N={stat['n']:5d}  1着={stat['win']:5d}  1着率={pct(stat['win'], stat['n']):6.2f}%")


def analyze(start_date: date, end_date: date):
    print(f"{VENUE_NAME} 対象レースを読み込み中...", flush=True)
    races = load_targets(start_date, end_date)
    target_pids = sorted({b["player_id"] for boats in races.values() for b in boats})
    print(f"  対象レース候補: {len(races)}", flush=True)
    print(f"  対象選手       : {len(target_pids)}", flush=True)

    terms = required_terms(start_date, end_date)
    print(f"racer_results 読み込み中... terms={','.join(terms)}", flush=True)
    racer = load_racer_results(terms)

    print("決まり手履歴を一括読み込み中...", flush=True)
    history = load_history(start_date, end_date, target_pids)
    print(f"  履歴行: {len(history)}", flush=True)
    hist_index = TechniqueHistoryIndex(history)

    # summary[(months, course, kind, key)] -> stat
    baseline = defaultdict(blank_stat)
    relation_stats = defaultdict(blank_stat)
    band_stats = defaultdict(blank_stat)
    cross_stats = defaultdict(blank_stat)

    skips = Counter()
    processed = 0

    for race_code in sorted(races):
        boats = races[race_code]
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

        race_date = boats[0]["date"]
        term = term_info_for_date(race_date)
        course_rank = {}
        missing_rank = False
        for c in range(1, 7):
            pid = by_course[c]["player_id"]
            rr = racer.get((term, pid))
            if rr is None:
                missing_rank = True
                break
            avg_rank = rr[c]["avg_rank"]
            if avg_rank is None or not (1.0 <= avg_rank <= 6.0):
                missing_rank = True
                break
            course_rank[c] = avg_rank
        if missing_rank:
            skips["missing_course_avg_rank"] += 1
            continue

        processed += 1
        winner_course = by_course[1]["winner_course"]

        for months in HISTORY_MONTHS:
            profiles = {}
            for c in range(1, 7):
                pid = by_course[c]["player_id"]
                profiles[c] = hist_index.profile(pid, c, race_date, months)

            for c in range(1, 7):
                won = (winner_course == c)
                add_stat(baseline[(months, c)], won)

                relation = "1コース"
                if c >= 2:
                    relation = relation_label(course_rank[c], course_rank[c - 1])
                    add_stat(relation_stats[(months, c, relation)], won)

                p = profiles[c]
                n_hist = p["n"]
                # 履歴0件は率0%扱いにせず、率帯分析から除外する。
                if n_hist <= 0:
                    continue

                for technique in TECHNIQUES:
                    tech_rate = pct(p["tech"][technique], n_hist)
                    band = rate_band(tech_rate)
                    add_stat(band_stats[(months, c, technique, band)], won)
                    if c >= 2:
                        add_stat(cross_stats[(months, c, technique, relation, band)], won)

    return {
        "processed": processed,
        "skips": skips,
        "baseline": baseline,
        "relation": relation_stats,
        "band": band_stats,
        "cross": cross_stats,
    }


def print_report(result, start_date: date, end_date: date) -> None:
    print("\n" + "=" * 90)
    print(f"{VENUE_NAME} BOATERS仮説 追試")
    print("=" * 90)
    print(f"対象期間   : {start_date} ～ {end_date}")
    print(f"採用レース : {result['processed']}")
    if result["skips"]:
        print("スキップ   : " + ", ".join(f"{k}={v}" for k, v in result["skips"].items()))

    for months in HISTORY_MONTHS:
        print("\n" + "-" * 90)
        print(f"【過去{months}ヶ月の選手コース別決まり手率を使用】")
        print("-" * 90)

        print("\n■ コース別 基準1着率")
        for c in range(1, 7):
            print_stat(f"{c}コース", result["baseline"][(months, c)])

        print("\n■ ST順位関係（2～6コースはひとつ内側と比較）")
        for c in range(2, 7):
            print(f"\n{c}コース vs {c-1}コース")
            for relation in ("内側より上", "同じ", "内側より下"):
                print_stat(relation, result["relation"][(months, c, relation)])

        print("\n■ BOATERS代表仮説: 4コース")
        print("4の平均ST順位が3より上/同じ/下")
        for relation in ("内側より上", "同じ", "内側より下"):
            print_stat(relation, result["relation"][(months, 4, relation)])

        for technique in TECHNIQUES:
            print(f"\n4コース {technique}率帯")
            for band in ("0.0-4.9", "5.0-9.9", "10.0-14.9", "15.0+"):
                print_stat(band, result["band"][(months, 4, technique, band)])

        print("\n■ 4コース『ST順位関係 × まくり率帯』")
        for relation in ("内側より上", "同じ", "内側より下"):
            print(f"\n[{relation}]")
            for band in ("0.0-4.9", "5.0-9.9", "10.0-14.9", "15.0+"):
                print_stat(band, result["cross"][(months, 4, "まくり", relation, band)])


def write_csv(result, start_date: date, end_date: date) -> tuple[Path, Path]:
    out_dir = Path(__file__).resolve().parent / "output"
    out_dir.mkdir(parents=True, exist_ok=True)
    label = f"{start_date:%Y%m%d}_{end_date:%Y%m%d}"
    summary_path = out_dir / f"tamagawa_boaters_summary_{label}.csv"
    cross_path = out_dir / f"tamagawa_boaters_cross_{label}.csv"

    with summary_path.open("w", newline="", encoding="utf-8-sig") as f:
        w = csv.writer(f)
        w.writerow(["history_months", "section", "course", "technique", "condition", "n", "wins", "win_rate_pct"])

        for months in HISTORY_MONTHS:
            for c in range(1, 7):
                s = result["baseline"][(months, c)]
                w.writerow([months, "baseline", c, "", "all", s["n"], s["win"], f"{pct(s['win'], s['n']):.4f}"])

            for c in range(2, 7):
                for relation in ("内側より上", "同じ", "内側より下"):
                    s = result["relation"][(months, c, relation)]
                    w.writerow([months, "start_rank_relation", c, "", relation, s["n"], s["win"], f"{pct(s['win'], s['n']):.4f}"])

            for c in range(1, 7):
                for technique in TECHNIQUES:
                    for band in ("0.0-4.9", "5.0-9.9", "10.0-14.9", "15.0+"):
                        s = result["band"][(months, c, technique, band)]
                        w.writerow([months, "technique_band", c, technique, band, s["n"], s["win"], f"{pct(s['win'], s['n']):.4f}"])

    with cross_path.open("w", newline="", encoding="utf-8-sig") as f:
        w = csv.writer(f)
        w.writerow(["history_months", "course", "technique", "start_rank_relation", "technique_rate_band", "n", "wins", "win_rate_pct"])
        for months in HISTORY_MONTHS:
            for c in range(2, 7):
                for technique in TECHNIQUES:
                    for relation in ("内側より上", "同じ", "内側より下"):
                        for band in ("0.0-4.9", "5.0-9.9", "10.0-14.9", "15.0+"):
                            s = result["cross"][(months, c, technique, relation, band)]
                            w.writerow([months, c, technique, relation, band, s["n"], s["win"], f"{pct(s['win'], s['n']):.4f}"])

    return summary_path, cross_path


def main() -> None:
    if len(sys.argv) != 3:
        print("Usage: python3 analysis/analyze_tamagawa_boaters_hypothesis.py START_DATE END_DATE", file=sys.stderr)
        sys.exit(1)

    start_date = parse_date(sys.argv[1])
    end_date = parse_date(sys.argv[2])
    if start_date > end_date:
        raise RuntimeError("開始日が終了日より後です")

    result = analyze(start_date, end_date)
    print_report(result, start_date, end_date)
    summary_path, cross_path = write_csv(result, start_date, end_date)

    print("\n" + "=" * 90)
    print("CSV出力")
    print("=" * 90)
    print(f"summary : {summary_path}")
    print(f"cross   : {cross_path}")
    print("=" * 90)


if __name__ == "__main__":
    main()
