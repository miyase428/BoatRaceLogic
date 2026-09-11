#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
多摩川で「4コースが実際に1着になったレース」だけに絞り、
実際の決まり手別に2着・3着がどのコースへ流れるかを見る。

No.1 検証:
- 実際に「まくり」で4コースが勝った時の2着/3着コース分布
- 実際に「まくり差し」で4コースが勝った時の2着/3着コース分布
- 参考として差し/抜き/恵まれ/その他も集計
- 4-X-Y の3連単構造も表示

過去profileは使わない。今回レースで実際に記録された決まり手だけを見る。

Usage:
  python3 analysis/analyze_tamagawa_lane4_actual_technique_followers.py 2025-09-01 2026-09-09
"""

from __future__ import annotations

import csv
import sys
from collections import Counter, defaultdict
from datetime import datetime
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from slit_validate_v2 import connect_db  # noqa: E402

VENUE_CODE = "TMG"
VENUE_NAME = "多摩川"
FOLLOWER_COURSES = (1, 2, 3, 5, 6)
MAIN_TECHNIQUES = ("まくり", "まくり差し")
DISPLAY_TECHNIQUES = ("まくり", "まくり差し", "差し", "抜き", "恵まれ", "逃げ", "不明")


def parse_date(value: str):
    return datetime.strptime(value, "%Y-%m-%d").date()


def pct(num: int, den: int) -> float:
    return 100.0 * num / den if den else 0.0


def normalize_technique(value) -> str:
    s = str(value or "").strip()
    return s if s else "不明"


def blank_stat() -> dict:
    return {
        "n": 0,
        "second": Counter(),
        "third": Counter(),
        "pair": Counter(),
    }


def add_outcome(stat: dict, second: int, third: int) -> None:
    stat["n"] += 1
    stat["second"][second] += 1
    stat["third"][third] += 1
    stat["pair"][(second, third)] += 1


def load_lane4_wins(start_date, end_date) -> list[dict]:
    # 対象期間・多摩川へ先に絞ってから結果を集約する。
    sql = """
WITH target_races AS (
    SELECT rm.race_code, rm.race_date
    FROM boat_race.race_master rm
    WHERE rm.race_date BETWEEN %s::date AND %s::date
      AND SUBSTRING(rm.race_code, 9, 3) = %s
),
finish AS (
    SELECT
        rrd.race_code,
        MAX(rrd.entry_course::integer) FILTER (WHERE TRIM(rrd.rank::text) = '1') AS first_course,
        MAX(rrd.entry_course::integer) FILTER (WHERE TRIM(rrd.rank::text) = '2') AS second_course,
        MAX(rrd.entry_course::integer) FILTER (WHERE TRIM(rrd.rank::text) = '3') AS third_course,
        MAX(TRIM(COALESCE(rrd.technique, ''))) FILTER (WHERE TRIM(rrd.rank::text) = '1') AS winner_technique
    FROM boat_race.race_result_detail rrd
    JOIN target_races tr ON tr.race_code = rrd.race_code
    GROUP BY rrd.race_code
)
SELECT
    tr.race_code,
    tr.race_date,
    f.first_course,
    f.second_course,
    f.third_course,
    f.winner_technique
FROM target_races tr
JOIN finish f ON f.race_code = tr.race_code
WHERE f.first_course = 4
ORDER BY tr.race_date, tr.race_code
    """

    rows = []
    with connect_db() as conn:
        with conn.cursor() as cur:
            cur.execute(sql, (start_date, end_date, VENUE_CODE))
            for race_code, race_date, first, second, third, technique in cur.fetchall():
                rows.append({
                    "race_code": str(race_code),
                    "date": race_date,
                    "first": int(first) if first is not None else None,
                    "second": int(second) if second is not None else None,
                    "third": int(third) if third is not None else None,
                    "technique": normalize_technique(technique),
                })
    return rows


def format_dist(counter: Counter, n: int) -> str:
    return " | ".join(
        f"{c}:{pct(counter[c], n):5.1f}%({counter[c]})" for c in FOLLOWER_COURSES
    )


def print_stat(stat: dict, title: str | None = None, top_pairs: int = 12) -> None:
    if title:
        print(title)
    n = stat["n"]
    print(f"N={n}")
    if n <= 0:
        return
    print("  2着: " + format_dist(stat["second"], n))
    print("  3着: " + format_dist(stat["third"], n))

    ranked = sorted(stat["pair"].items(), key=lambda kv: (-kv[1], kv[0]))
    print("  4-X-Y 上位:")
    for i, ((second, third), count) in enumerate(ranked[:top_pairs], start=1):
        print(
            f"    {i:2d}. 4-{second}-{third}  "
            f"N={count:3d}  構成率={pct(count, n):6.2f}%"
        )


def analyze(start_date, end_date):
    print(f"{VENUE_NAME} 4コース1着レースを読み込み中...", flush=True)
    rows = load_lane4_wins(start_date, end_date)
    print(f"  4コース1着候補: {len(rows)}", flush=True)

    baseline = blank_stat()
    by_tech = defaultdict(blank_stat)
    technique_counts = Counter()
    skips = Counter()

    for row in rows:
        second = row["second"]
        third = row["third"]
        if second not in FOLLOWER_COURSES or third not in FOLLOWER_COURSES or second == third:
            skips["bad_2nd_or_3rd"] += 1
            continue

        technique = row["technique"]
        technique_counts[technique] += 1
        add_outcome(baseline, second, third)
        add_outcome(by_tech[technique], second, third)

    return rows, skips, technique_counts, baseline, by_tech


def write_csv(start_date, end_date, baseline, by_tech):
    out_dir = Path(__file__).resolve().parent / "output"
    out_dir.mkdir(parents=True, exist_ok=True)
    label = f"{start_date:%Y%m%d}_{end_date:%Y%m%d}"
    path = out_dir / f"tamagawa_lane4_actual_technique_followers_{label}.csv"

    with path.open("w", newline="", encoding="utf-8-sig") as f:
        w = csv.writer(f)
        w.writerow([
            "actual_technique",
            "lane4_win_n",
            "metric",
            "value",
            "count",
            "rate_pct",
        ])

        def emit(technique: str, stat: dict):
            n = stat["n"]
            for c in FOLLOWER_COURSES:
                w.writerow([
                    technique, n, "second_course", c,
                    stat["second"][c], f"{pct(stat['second'][c], n):.4f}"
                ])
                w.writerow([
                    technique, n, "third_course", c,
                    stat["third"][c], f"{pct(stat['third'][c], n):.4f}"
                ])
            for second in FOLLOWER_COURSES:
                for third in FOLLOWER_COURSES:
                    if second == third:
                        continue
                    count = stat["pair"][(second, third)]
                    w.writerow([
                        technique, n, "pair", f"4-{second}-{third}",
                        count, f"{pct(count, n):.4f}"
                    ])

        emit("ALL", baseline)
        for technique in sorted(by_tech):
            emit(technique, by_tech[technique])

    return path


def main() -> None:
    if len(sys.argv) != 3:
        print(
            "Usage: python3 analysis/analyze_tamagawa_lane4_actual_technique_followers.py START_DATE END_DATE",
            file=sys.stderr,
        )
        sys.exit(1)

    start_date = parse_date(sys.argv[1])
    end_date = parse_date(sys.argv[2])
    if start_date > end_date:
        raise RuntimeError("開始日が終了日より後です")

    rows, skips, technique_counts, baseline, by_tech = analyze(start_date, end_date)

    print("\n" + "=" * 116)
    print("多摩川 4コース1着時：実際の決まり手別 → 2着・3着コース分布")
    print("=" * 116)
    print(f"対象期間       : {start_date} ～ {end_date}")
    print(f"4コース1着候補: {len(rows)}")
    print(f"採用           : {baseline['n']}")
    if skips:
        print("スキップ       : " + ", ".join(f"{k}={v}" for k, v in skips.items()))

    print("\n■ 4コース1着時の実際の決まり手構成")
    total = sum(technique_counts.values())
    for technique, count in sorted(technique_counts.items(), key=lambda kv: (-kv[1], kv[0])):
        print(f"  {technique:<10} N={count:4d}  構成率={pct(count, total):6.2f}%")

    print_stat(baseline, "\n■ 全4コース1着 基準")

    print("\n■ 主分析：実際の『まくり』勝ち")
    print_stat(by_tech["まくり"])

    print("\n■ 主分析：実際の『まくり差し』勝ち")
    print_stat(by_tech["まくり差し"])

    print("\n■ 参考：その他の実際の決まり手")
    for technique in DISPLAY_TECHNIQUES:
        if technique in MAIN_TECHNIQUES:
            continue
        if by_tech[technique]["n"] <= 0:
            continue
        print_stat(by_tech[technique], f"\n[{technique}]", top_pairs=8)

    # DBに想定外ラベルがある場合も表示する。
    known = set(DISPLAY_TECHNIQUES)
    for technique in sorted(set(by_tech) - known):
        if by_tech[technique]["n"] > 0:
            print_stat(by_tech[technique], f"\n[{technique}]", top_pairs=8)

    path = write_csv(start_date, end_date, baseline, by_tech)
    print("\n" + "=" * 116)
    print(f"CSV出力: {path}")
    print("=" * 116)


if __name__ == "__main__":
    main()
