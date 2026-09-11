#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
多摩川3コースの第一段階検証。

4コース分析と同じ考え方で、まず余計な補正を入れずに確認する。

見るもの:
- 3コース基準成績
- 3が2より平均ST順位 上/同じ/下
- 3コース過去まくり率
- 3コース過去まくり差し率
- 3コース攻め率 = まくり + まくり差し
- 各攻め率帯 × 3 vs 2 平均ST順位関係

決まり手履歴は対象レース当日を含めず、過去12ヶ月・6ヶ月を別々に集計。
級別・勝率・展示などはまだ入れない。

Usage:
  python3 analysis/analyze_tamagawa_lane3_attack_hypothesis.py 2025-09-01 2026-09-09
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
        "lane3_first": 0,
        "lane3_second": 0,
        "lane3_third": 0,
        "winner": Counter(),
    }


def add_outcome(stat: dict, first: int, second: int, third: int | None) -> None:
    stat["n"] += 1
    stat["winner"][first] += 1
    if first == 3:
        stat["lane3_first"] += 1
    if second == 3:
        stat["lane3_second"] += 1
    if third == 3:
        stat["lane3_third"] += 1


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


def print_stat(label: str, stat: dict) -> None:
    n = stat["n"]
    top2 = stat["lane3_first"] + stat["lane3_second"]
    top3 = top2 + stat["lane3_third"]
    print(
        f"{label:<34} N={n:4d}  "
        f"3頭={pct(stat['lane3_first'], n):6.2f}%  "
        f"32着={pct(stat['lane3_second'], n):6.2f}%  "
        f"33着={pct(stat['lane3_third'], n):6.2f}%  "
        f"3-2連対={pct(top2, n):6.2f}%  "
        f"3-3連対={pct(top3, n):6.2f}%"
    )


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
    by_relation = {(m, rel): blank_stat() for m in HISTORY_MONTHS for rel in RELATIONS}
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
        missing = False
        for c in (2, 3):
            rr = racer.get((term, by_course[c]["player_id"]))
            if rr is None or rr[c]["avg_rank"] is None:
                missing = True
                break
            ranks[c] = float(rr[c]["avg_rank"])
        if missing:
            skips["missing_course_avg_rank"] += 1
            continue

        relation = relation_label(ranks[3], ranks[2])
        first, second, third = race["first"], race["second"], race["third"]
        processed += 1

        for months in HISTORY_MONTHS:
            p3 = hist_index.profile(by_course[3]["player_id"], 3, race_date, months)
            if p3["n"] <= 0:
                skips[f"history0_{months}m"] += 1
                continue

            rates = attack_rates(p3)
            add_outcome(baseline[months], first, second, third)
            add_outcome(by_relation[(months, relation)], first, second, third)

            for akey, _ in ATTACK_TYPES:
                band = rate_band(rates[akey])
                add_outcome(by_attack_band[(months, akey, band)], first, second, third)
                add_outcome(by_cross[(months, akey, relation, band)], first, second, third)

    return processed, skips, baseline, by_relation, by_attack_band, by_cross


def write_csv(start_date, end_date, baseline, by_relation, by_attack_band, by_cross):
    out_dir = Path(__file__).resolve().parent / "output"
    out_dir.mkdir(parents=True, exist_ok=True)
    label = f"{start_date:%Y%m%d}_{end_date:%Y%m%d}"
    path = out_dir / f"tamagawa_lane3_attack_hypothesis_{label}.csv"

    with path.open("w", newline="", encoding="utf-8-sig") as f:
        w = csv.writer(f)
        w.writerow([
            "history_months", "attack_type", "start_relation", "rate_band",
            "n_races", "lane3_first", "lane3_first_rate_pct",
            "lane3_second", "lane3_second_rate_pct",
            "lane3_third", "lane3_third_rate_pct",
            "lane3_top2_rate_pct", "lane3_top3_rate_pct",
        ])

        def emit(months, attack_type, rel, band, s):
            n = s["n"]
            top2 = s["lane3_first"] + s["lane3_second"]
            top3 = top2 + s["lane3_third"]
            w.writerow([
                months, attack_type, rel, band, n,
                s["lane3_first"], f"{pct(s['lane3_first'], n):.4f}",
                s["lane3_second"], f"{pct(s['lane3_second'], n):.4f}",
                s["lane3_third"], f"{pct(s['lane3_third'], n):.4f}",
                f"{pct(top2, n):.4f}", f"{pct(top3, n):.4f}",
            ])

        for months in HISTORY_MONTHS:
            emit(months, "BASELINE", "ALL", "ALL", baseline[months])
            for rel in RELATIONS:
                emit(months, "RELATION_ONLY", rel, "ALL", by_relation[(months, rel)])
            for akey, alabel in ATTACK_TYPES:
                for band in BANDS:
                    emit(months, alabel, "ALL", band, by_attack_band[(months, akey, band)])
                for rel in RELATIONS:
                    for band in BANDS:
                        emit(months, alabel, rel, band, by_cross[(months, akey, rel, band)])

    return path


def main() -> None:
    if len(sys.argv) != 3:
        print(
            "Usage: python3 analysis/analyze_tamagawa_lane3_attack_hypothesis.py START_DATE END_DATE",
            file=sys.stderr,
        )
        sys.exit(1)

    start_date = parse_date(sys.argv[1])
    end_date = parse_date(sys.argv[2])
    if start_date > end_date:
        raise RuntimeError("開始日が終了日より後です")

    processed, skips, baseline, by_relation, by_attack_band, by_cross = analyze(start_date, end_date)

    print("\n" + "=" * 132)
    print("多摩川3コース 第一段階：攻め率 × 2コースとの平均ST順位関係")
    print("4コース分析と同じ条件帯で、まず3コースの強条件候補を探す")
    print("=" * 132)
    print(f"対象期間   : {start_date} ～ {end_date}")
    print(f"採用候補   : {processed}")
    if skips:
        print("スキップ   : " + ", ".join(f"{k}={v}" for k, v in skips.items()))

    for months in HISTORY_MONTHS:
        print("\n" + "-" * 132)
        print(f"【過去{months}ヶ月profile】")
        print("-" * 132)

        print("\n■ 基準")
        print_stat("BASELINE", baseline[months])

        print("\n■ 3 vs 2 平均ST順位関係")
        for rel in RELATIONS:
            print_stat(rel, by_relation[(months, rel)])

        for akey, alabel in ATTACK_TYPES:
            print(f"\n■ 3コース{alabel}：全ST順位関係")
            for band in BANDS:
                print_stat(f"{alabel} {band}", by_attack_band[(months, akey, band)])

            print(f"\n■ 3コース{alabel} × 3が2より平均ST順位『上』")
            for band in BANDS:
                print_stat(f"{alabel} {band} / ST上", by_cross[(months, akey, "内側より上", band)])

            print(f"\n■ 参考：3コース{alabel} × 3が2より平均ST順位『下』")
            for band in BANDS:
                print_stat(f"{alabel} {band} / ST下", by_cross[(months, akey, "内側より下", band)])

        print("\n■ 強条件候補の参考（15%以上 × ST上）")
        for akey, alabel in ATTACK_TYPES:
            print_stat(
                f"{alabel}15%以上 × ST上",
                by_cross[(months, akey, "内側より上", "15.0+")],
            )

    path = write_csv(start_date, end_date, baseline, by_relation, by_attack_band, by_cross)
    print("\n" + "=" * 132)
    print(f"CSV出力: {path}")
    print("※ まだ強条件は確定しません。まくり / まくり差し / 合算のどれを主軸にするかをこの結果から決めます。")
    print("=" * 132)


if __name__ == "__main__":
    main()
