#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
多摩川4コースの攻め方と3コースとの平均ST順位関係によって、
各コース(1〜6)の1着率・2連対率・3連対率がどう変わるかを見る。

攻め方は以下を別々に確認する。
- 4コースまくり率
- 4コースまくり差し率
- 4コース攻め率 = まくり率 + まくり差し率

決まり手履歴は対象レース当日を含めず、過去12ヶ月・6ヶ月を別々に集計。
平均ST順位は racer_results の course3/course4 average_rank を用い、
数値が小さい方を「順位が上」と扱う。

Usage:
  python3 analysis/analyze_tamagawa_lane4_attack_impact_all_courses.py 2025-09-01 2026-09-09
"""

from __future__ import annotations

import csv
import sys
from collections import Counter
from datetime import date
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from analyze_tamagawa_boaters_hypothesis import (  # noqa: E402
    HISTORY_MONTHS,
    TechniqueHistoryIndex,
    load_history,
    load_racer_results,
    parse_date,
    rate_band,
    relation_label,
    required_terms,
    term_info_for_date,
)
from analyze_tamagawa_lane4_exacta_structure import load_targets  # noqa: E402

VENUE_NAME = "多摩川"
BANDS = ("0.0-4.9", "5.0-9.9", "10.0-14.9", "15.0+")
RELATIONS = ("内側より上", "同じ", "内側より下")
ATTACK_TYPES = (
    ("makuri", "まくり率"),
    ("makurizashi", "まくり差し率"),
    ("attack_sum", "まくり+まくり差し率"),
)


def pct(num: int, den: int) -> float:
    return 100.0 * num / den if den else 0.0


def blank_stat() -> dict:
    return {
        "n": 0,
        "win": Counter(),
        "top2": Counter(),
        "top3": Counter(),
    }


def add_outcome(stat: dict, first: int, second: int, third: int | None) -> None:
    stat["n"] += 1
    stat["win"][first] += 1
    stat["top2"][first] += 1
    stat["top2"][second] += 1
    stat["top3"][first] += 1
    stat["top3"][second] += 1
    if third in range(1, 7):
        stat["top3"][third] += 1


def attack_rates(profile: dict) -> dict[str, float]:
    n = profile["n"]
    if n <= 0:
        return {"makuri": 0.0, "makurizashi": 0.0, "attack_sum": 0.0}
    makuri = pct(profile["tech"].get("まくり", 0), n)
    makurizashi = pct(profile["tech"].get("まくり差し", 0), n)
    return {
        "makuri": makuri,
        "makurizashi": makurizashi,
        "attack_sum": makuri + makurizashi,
    }


def fmt_course(stat: dict, course: int) -> str:
    n = stat["n"]
    return (
        f"{course}:"
        f"{pct(stat['win'][course], n):5.1f}/"
        f"{pct(stat['top2'][course], n):5.1f}/"
        f"{pct(stat['top3'][course], n):5.1f}"
    )


def print_compact(stat: dict, prefix: str = "") -> None:
    parts = [fmt_course(stat, c) for c in range(1, 7)]
    print(f"{prefix}N={stat['n']:4d}  " + " | ".join(parts))


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
    by_attack_band = {
        (m, akey, band): blank_stat()
        for m in HISTORY_MONTHS
        for akey, _ in ATTACK_TYPES
        for band in BANDS
    }
    by_cross = {
        (m, akey, rel, band): blank_stat()
        for m in HISTORY_MONTHS
        for akey, _ in ATTACK_TYPES
        for rel in RELATIONS
        for band in BANDS
    }

    skips = Counter()
    processed = 0

    for race in races.values():
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
        for c in (3, 4):
            rr = racer.get((term, by_course[c]["player_id"]))
            if rr is None or rr[c]["avg_rank"] is None:
                ranks = {}
                break
            ranks[c] = float(rr[c]["avg_rank"])
        if not ranks:
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

            rates = attack_rates(p4)
            add_outcome(baseline[months], first, second, third)
            for akey, _ in ATTACK_TYPES:
                band = rate_band(rates[akey])
                add_outcome(by_attack_band[(months, akey, band)], first, second, third)
                add_outcome(by_cross[(months, akey, relation, band)], first, second, third)

    return processed, skips, baseline, by_attack_band, by_cross


def write_csv(start_date, end_date, baseline, by_attack_band, by_cross):
    out_dir = Path(__file__).resolve().parent / "output"
    out_dir.mkdir(parents=True, exist_ok=True)
    label = f"{start_date:%Y%m%d}_{end_date:%Y%m%d}"
    path = out_dir / f"tamagawa_lane4_attack_impact_all_courses_{label}.csv"

    with path.open("w", newline="", encoding="utf-8-sig") as f:
        w = csv.writer(f)
        w.writerow([
            "history_months", "attack_type", "start_relation", "rate_band",
            "course", "n_races", "win_count", "win_rate_pct",
            "top2_count", "top2_rate_pct", "top3_count", "top3_rate_pct",
        ])

        for months in HISTORY_MONTHS:
            b = baseline[months]
            for c in range(1, 7):
                w.writerow([
                    months, "BASELINE", "ALL", "ALL", c, b["n"],
                    b["win"][c], f"{pct(b['win'][c], b['n']):.4f}",
                    b["top2"][c], f"{pct(b['top2'][c], b['n']):.4f}",
                    b["top3"][c], f"{pct(b['top3'][c], b['n']):.4f}",
                ])

            for akey, alabel in ATTACK_TYPES:
                for band in BANDS:
                    s = by_attack_band[(months, akey, band)]
                    for c in range(1, 7):
                        w.writerow([
                            months, alabel, "ALL", band, c, s["n"],
                            s["win"][c], f"{pct(s['win'][c], s['n']):.4f}",
                            s["top2"][c], f"{pct(s['top2'][c], s['n']):.4f}",
                            s["top3"][c], f"{pct(s['top3'][c], s['n']):.4f}",
                        ])

                for rel in RELATIONS:
                    for band in BANDS:
                        s = by_cross[(months, akey, rel, band)]
                        for c in range(1, 7):
                            w.writerow([
                                months, alabel, rel, band, c, s["n"],
                                s["win"][c], f"{pct(s['win'][c], s['n']):.4f}",
                                s["top2"][c], f"{pct(s['top2'][c], s['n']):.4f}",
                                s["top3"][c], f"{pct(s['top3'][c], s['n']):.4f}",
                            ])
    return path


def main() -> None:
    if len(sys.argv) != 3:
        print(
            "Usage: python3 analysis/analyze_tamagawa_lane4_attack_impact_all_courses.py START_DATE END_DATE",
            file=sys.stderr,
        )
        sys.exit(1)

    start_date = parse_date(sys.argv[1])
    end_date = parse_date(sys.argv[2])
    if start_date > end_date:
        raise RuntimeError("開始日が終了日より後です")

    processed, skips, baseline, by_attack_band, by_cross = analyze(start_date, end_date)

    print("\n" + "=" * 120)
    print("多摩川4コース 攻め方 × 3コースとの平均ST順位関係 → 各コース成績")
    print("表示: コース:1着率/2連対率/3連対率 (%)")
    print("=" * 120)
    print(f"対象期間   : {start_date} ～ {end_date}")
    print(f"採用候補   : {processed}")
    if skips:
        print("スキップ   : " + ", ".join(f"{k}={v}" for k, v in skips.items()))

    for months in HISTORY_MONTHS:
        print("\n" + "-" * 120)
        print(f"【過去{months}ヶ月 profile】")
        print("-" * 120)
        print("\n■ 基準")
        print_compact(baseline[months])

        for akey, alabel in ATTACK_TYPES:
            print(f"\n■ {alabel}：全ST順位関係")
            for band in BANDS:
                print_compact(by_attack_band[(months, akey, band)], prefix=f"{band:<11} ")

            print(f"\n■ {alabel} × 4が3より平均ST順位『上』")
            for band in BANDS:
                print_compact(by_cross[(months, akey, "内側より上", band)], prefix=f"{band:<11} ")

            print(f"\n■ {alabel} × 4が3より平均ST順位『下』")
            for band in BANDS:
                print_compact(by_cross[(months, akey, "内側より下", band)], prefix=f"{band:<11} ")

    path = write_csv(start_date, end_date, baseline, by_attack_band, by_cross)
    print("\n" + "=" * 120)
    print(f"CSV出力: {path}")
    print("※ ST順位『同じ』もCSVには出力しています。")
    print("=" * 120)


if __name__ == "__main__":
    main()
