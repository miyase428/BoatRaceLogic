#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
多摩川で「4コースが実際に1着になったレース」だけに絞り、
4コースの過去まくり率と3コースとの平均ST順位関係によって、
2着・3着がどのコースへ流れるかを見る。

主な出力:
- 4コース1着時の2着コース分布 / 3着コース分布
- 4コースまくり率4帯ごとの分布
- 4が3より平均ST順位 上/同じ/下 × まくり率帯ごとの分布
- 4-X-Y の上位3連単構造

決まり手profileは対象レース当日を含めず、過去12ヶ月・6ヶ月を別々に集計。

Usage:
  python3 analysis/analyze_tamagawa_lane4_win_followers.py 2025-09-01 2026-09-09
"""

from __future__ import annotations

import csv
import sys
from collections import Counter
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
from analyze_tamagawa_lane4_exacta_structure import (  # noqa: E402
    BANDS,
    RELATIONS,
    load_targets,
    pct,
)

FOLLOWER_COURSES = (1, 2, 3, 5, 6)


def blank_stat() -> dict:
    return {
        "n": 0,
        "second": Counter(),
        "third": Counter(),
        "pair": Counter(),
    }


def add_outcome(stat: dict, second: int, third: int | None) -> None:
    if second not in FOLLOWER_COURSES:
        return
    stat["n"] += 1
    stat["second"][second] += 1
    if third in FOLLOWER_COURSES and third != second:
        stat["third"][third] += 1
        stat["pair"][(second, third)] += 1


def format_dist(counter: Counter, n: int) -> str:
    return " | ".join(
        f"{c}:{pct(counter[c], n):5.1f}%({counter[c]})" for c in FOLLOWER_COURSES
    )


def print_stat(stat: dict, title: str | None = None, top_pairs: int = 10) -> None:
    if title:
        print(title)
    n = stat["n"]
    print(f"N={n}")
    print("  2着: " + format_dist(stat["second"], n))
    print("  3着: " + format_dist(stat["third"], n))
    if n <= 0:
        return
    ranked = sorted(stat["pair"].items(), key=lambda kv: (-kv[1], kv[0]))
    if ranked:
        print("  4-X-Y 上位:")
        for i, ((second, third), count) in enumerate(ranked[:top_pairs], start=1):
            print(
                f"    {i:2d}. 4-{second}-{third}  "
                f"N={count:3d}  構成率={pct(count, n):6.2f}%"
            )


def analyze(start_date, end_date):
    print("多摩川 対象レースを読み込み中...", flush=True)
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
    by_cross = {
        (m, rel, b): blank_stat()
        for m in HISTORY_MONTHS
        for rel in RELATIONS
        for b in BANDS
    }

    skips = Counter()
    adopted_races = 0
    lane4_win_candidates = 0

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

        # 今回は実際に4コースが1着のレースだけを分析対象にする。
        if race["first"] != 4:
            continue
        lane4_win_candidates += 1

        second = race["second"]
        third = race["third"]
        if second not in FOLLOWER_COURSES or third not in FOLLOWER_COURSES or second == third:
            skips["bad_2nd_or_3rd"] += 1
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
        adopted_races += 1

        for months in HISTORY_MONTHS:
            p4 = hist_index.profile(by_course[4]["player_id"], 4, race_date, months)
            if p4["n"] <= 0:
                skips[f"history0_{months}m"] += 1
                continue

            makuri_rate = pct(p4["tech"]["まくり"], p4["n"])
            band = rate_band(makuri_rate)
            add_outcome(baseline[months], second, third)
            add_outcome(by_band[(months, band)], second, third)
            add_outcome(by_cross[(months, relation, band)], second, third)

    return lane4_win_candidates, adopted_races, skips, baseline, by_band, by_cross


def write_csv(start_date, end_date, baseline, by_band, by_cross):
    out_dir = Path(__file__).resolve().parent / "output"
    out_dir.mkdir(parents=True, exist_ok=True)
    label = f"{start_date:%Y%m%d}_{end_date:%Y%m%d}"
    path = out_dir / f"tamagawa_lane4_win_followers_{label}.csv"

    with path.open("w", newline="", encoding="utf-8-sig") as f:
        w = csv.writer(f)
        w.writerow([
            "history_months",
            "start_relation",
            "makuri_band",
            "lane4_win_n",
            "metric",
            "value",
            "count",
            "rate_pct",
        ])

        def emit(months, relation, band, stat):
            n = stat["n"]
            for c in FOLLOWER_COURSES:
                w.writerow([months, relation, band, n, "second_course", c,
                            stat["second"][c], f"{pct(stat['second'][c], n):.4f}"])
                w.writerow([months, relation, band, n, "third_course", c,
                            stat["third"][c], f"{pct(stat['third'][c], n):.4f}"])
            for second in FOLLOWER_COURSES:
                for third in FOLLOWER_COURSES:
                    if second == third:
                        continue
                    count = stat["pair"][(second, third)]
                    w.writerow([months, relation, band, n, "pair", f"4-{second}-{third}",
                                count, f"{pct(count, n):.4f}"])

        for months in HISTORY_MONTHS:
            emit(months, "ALL", "ALL", baseline[months])
            for band in BANDS:
                emit(months, "ALL", band, by_band[(months, band)])
            for rel in RELATIONS:
                for band in BANDS:
                    emit(months, rel, band, by_cross[(months, rel, band)])

    return path


def main() -> None:
    if len(sys.argv) != 3:
        print(
            "Usage: python3 analysis/analyze_tamagawa_lane4_win_followers.py START_DATE END_DATE",
            file=sys.stderr,
        )
        sys.exit(1)

    start_date = parse_date(sys.argv[1])
    end_date = parse_date(sys.argv[2])
    if start_date > end_date:
        raise RuntimeError("開始日が終了日より後です")

    lane4_win_candidates, adopted, skips, baseline, by_band, by_cross = analyze(start_date, end_date)

    print("\n" + "=" * 112)
    print("多摩川 4コース1着時：まくり率 × ST順位関係 → 2着・3着コース分布")
    print("=" * 112)
    print(f"対象期間       : {start_date} ～ {end_date}")
    print(f"4コース1着候補: {lane4_win_candidates}")
    print(f"採用候補       : {adopted}")
    if skips:
        print("スキップ       : " + ", ".join(f"{k}={v}" for k, v in skips.items()))

    for months in HISTORY_MONTHS:
        print("\n" + "-" * 112)
        print(f"【過去{months}ヶ月の4コースまくり率 profile】")
        print("-" * 112)

        print_stat(baseline[months], "\n■ 4コース1着時の全体基準")

        print("\n■ まくり率帯別（全ST順位関係）")
        for band in BANDS:
            print_stat(by_band[(months, band)], f"\n[4まくり {band}]")

        print("\n■ 4が3より平均ST順位『上』 × まくり率帯")
        for band in BANDS:
            print_stat(by_cross[(months, "内側より上", band)], f"\n[4まくり {band} / ST上]")

        print("\n■ 参考：4が3より平均ST順位『下』 × まくり率帯")
        for band in BANDS:
            print_stat(by_cross[(months, "内側より下", band)], f"\n[4まくり {band} / ST下]", top_pairs=5)

    path = write_csv(start_date, end_date, baseline, by_band, by_cross)
    print("\n" + "=" * 112)
    print(f"CSV出力: {path}")
    print("※ ST順位『同じ』もCSVには出力しています。")
    print("=" * 112)


if __name__ == "__main__":
    main()
