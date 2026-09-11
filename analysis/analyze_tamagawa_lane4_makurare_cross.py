#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
多摩川4コースについて、既存BOATERS追試に
「1コース選手の過去まくられ率」を組み合わせて確認する寄り道分析。

見るもの:
  1) 1コースまくられ率帯ごとの4コース1着率
  2) 4コースまくり率帯 × 1コースまくられ率帯
  3) 4 vs 3 の平均ST順位関係 × 1コースまくられ率帯
  4) 4 vs 3 の平均ST順位関係 × 4コースまくり率帯 × 1コースまくられ率帯

率帯は既存追試と同じ:
  0.0-4.9 / 5.0-9.9 / 10.0-14.9 / 15.0+

過去成績は対象レース当日を含めず、過去12ヶ月・6ヶ月を別々に使用。
級別・勝率・展示評価は入れない。

使い方:
  python3 analysis/analyze_tamagawa_lane4_makurare_cross.py 2025-09-01 2026-09-09

出力:
  analysis/output/tamagawa_lane4_makurare_cross_YYYYMMDD_YYYYMMDD.csv
"""

from __future__ import annotations

import csv
import sys
from collections import Counter, defaultdict
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from analyze_tamagawa_boaters_hypothesis import (  # noqa: E402
    HISTORY_MONTHS,
    load_racer_results,
    load_targets,
    parse_date,
    pct,
    rate_band,
    relation_label,
    required_terms,
    term_info_for_date,
)
from export_kimarite_cache import HistoryIndex, load_history  # noqa: E402
from slit_validate_v2 import connect_db  # noqa: E402

BANDS = ("0.0-4.9", "5.0-9.9", "10.0-14.9", "15.0+")
RELATIONS = ("内側より上", "同じ", "内側より下")


def blank_stat() -> dict:
    return {"n": 0, "win": 0}


def add_stat(stat: dict, won: bool) -> None:
    stat["n"] += 1
    if won:
        stat["win"] += 1


def print_stat(label: str, stat: dict) -> None:
    print(
        f"{label:<34} N={stat['n']:5d}  4号艇1着={stat['win']:4d}  "
        f"4号艇1着率={pct(stat['win'], stat['n']):6.2f}%"
    )


def analyze(start_date, end_date):
    print("多摩川 対象レースを読み込み中...", flush=True)
    races = load_targets(start_date, end_date)
    print(f"  対象レース候補: {len(races)}", flush=True)

    terms = required_terms(start_date, end_date)
    print(f"racer_results 読み込み中... terms={','.join(terms)}", flush=True)
    racer = load_racer_results(terms)

    print("決まり手履歴を一括読み込み中...", flush=True)
    # export_kimarite_cache と同じ定義を使う。
    # 1コースまくられ = 1コースで敗戦し、勝者の決まり手が「まくり」。
    with connect_db() as conn:
        history = load_history(conn, start_date, end_date)
    print(f"  履歴行: {len(history)}", flush=True)
    hist = HistoryIndex(history)

    baseline = defaultdict(blank_stat)
    makurare_band_stats = defaultdict(blank_stat)
    makuri_makurare_cross = defaultdict(blank_stat)
    relation_makurare_cross = defaultdict(blank_stat)
    triple_cross = defaultdict(blank_stat)

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

        # 既存BOATERS追試と同じ採用条件に合わせるため、6艇すべてのコース別平均ST順位を要求。
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
        lane4_won = winner_course == 4
        relation = relation_label(course_rank[4], course_rank[3])

        pid1 = by_course[1]["player_id"]
        pid4 = by_course[4]["player_id"]

        for months in HISTORY_MONTHS:
            add_stat(baseline[months], lane4_won)

            p1 = hist.profile(pid1, 1, race_date, months)
            p4 = hist.profile(pid4, 4, race_date, months)

            # 率帯分析では履歴0件を除外。
            if p1["n"] <= 0 or p4["n"] <= 0:
                skips[f"missing_history_{months}m"] += 1
                continue

            makurare_rate = pct(p1["counts"]["makurare"], p1["n"])
            lane4_makuri_rate = pct(p4["counts"]["makuri"], p4["n"])

            makurare_band = rate_band(makurare_rate)
            makuri_band = rate_band(lane4_makuri_rate)

            add_stat(makurare_band_stats[(months, makurare_band)], lane4_won)
            add_stat(
                makuri_makurare_cross[(months, makuri_band, makurare_band)],
                lane4_won,
            )
            add_stat(
                relation_makurare_cross[(months, relation, makurare_band)],
                lane4_won,
            )
            add_stat(
                triple_cross[(months, relation, makuri_band, makurare_band)],
                lane4_won,
            )

    return {
        "processed": processed,
        "skips": skips,
        "baseline": baseline,
        "makurare_band": makurare_band_stats,
        "makuri_makurare": makuri_makurare_cross,
        "relation_makurare": relation_makurare_cross,
        "triple": triple_cross,
    }


def print_report(result, start_date, end_date) -> None:
    print("\n" + "=" * 100)
    print("多摩川4コース × 1コースまくられ率 追加検証")
    print("=" * 100)
    print(f"対象期間   : {start_date} ～ {end_date}")
    print(f"採用レース : {result['processed']}")
    if result["skips"]:
        print("スキップ   : " + ", ".join(f"{k}={v}" for k, v in result["skips"].items()))

    for months in HISTORY_MONTHS:
        print("\n" + "-" * 100)
        print(f"【過去{months}ヶ月】")
        print("-" * 100)
        print_stat("4コース基準", result["baseline"][months])

        print("\n■ 1コースまくられ率帯 → 4号艇1着率")
        for b1 in BANDS:
            print_stat(f"1まくられ {b1}", result["makurare_band"][(months, b1)])

        print("\n■ 4コースまくり率帯 × 1コースまくられ率帯")
        for b4 in BANDS:
            print(f"\n[4まくり {b4}]")
            for b1 in BANDS:
                print_stat(
                    f"1まくられ {b1}",
                    result["makuri_makurare"][(months, b4, b1)],
                )

        print("\n■ 4 vs 3 ST順位関係 × 1コースまくられ率帯")
        for rel in RELATIONS:
            print(f"\n[{rel}]")
            for b1 in BANDS:
                print_stat(
                    f"1まくられ {b1}",
                    result["relation_makurare"][(months, rel, b1)],
                )

        print("\n■ 3条件クロス: ST順位関係 × 4まくり率帯 × 1まくられ率帯")
        for rel in RELATIONS:
            print(f"\n[{rel}]")
            for b4 in BANDS:
                print(f"  4まくり {b4}")
                for b1 in BANDS:
                    s = result["triple"][(months, rel, b4, b1)]
                    if s["n"] > 0:
                        print_stat(f"    1まくられ {b1}", s)


def write_csv(result, start_date, end_date) -> Path:
    out_dir = Path(__file__).resolve().parent / "output"
    out_dir.mkdir(parents=True, exist_ok=True)
    label = f"{start_date:%Y%m%d}_{end_date:%Y%m%d}"
    path = out_dir / f"tamagawa_lane4_makurare_cross_{label}.csv"

    with path.open("w", newline="", encoding="utf-8-sig") as f:
        w = csv.writer(f)
        w.writerow([
            "history_months",
            "start_rank_relation",
            "lane4_makuri_band",
            "lane1_makurare_band",
            "n",
            "lane4_wins",
            "lane4_win_rate_pct",
        ])
        for months in HISTORY_MONTHS:
            for rel in RELATIONS:
                for b4 in BANDS:
                    for b1 in BANDS:
                        s = result["triple"][(months, rel, b4, b1)]
                        w.writerow([
                            months,
                            rel,
                            b4,
                            b1,
                            s["n"],
                            s["win"],
                            f"{pct(s['win'], s['n']):.4f}",
                        ])
    return path


def main() -> None:
    if len(sys.argv) != 3:
        print(
            "Usage: python3 analysis/analyze_tamagawa_lane4_makurare_cross.py START_DATE END_DATE",
            file=sys.stderr,
        )
        sys.exit(1)

    start_date = parse_date(sys.argv[1])
    end_date = parse_date(sys.argv[2])
    if start_date > end_date:
        raise RuntimeError("開始日が終了日より後です")

    result = analyze(start_date, end_date)
    print_report(result, start_date, end_date)
    path = write_csv(result, start_date, end_date)

    print("\n" + "=" * 100)
    print(f"CSV出力: {path}")
    print("=" * 100)


if __name__ == "__main__":
    main()
