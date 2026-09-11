#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
多摩川4コースの「強条件」なのに4コースが1着を取れなかった時の裏目構造を見る。

強条件（各対象レース時点の過去profile）:
- 4コースまくり率 >= 15.0%
- 4コース選手の平均ST順位が3コース選手より上（average_rankが小さい）

過去12ヶ月profile / 過去6ヶ月profile を別々に集計する。
対象レース当日は履歴に含めない。

主な出力:
- 強条件の総数 / 4コース1着率 / 4コース非1着数
- 4が負けた時の勝者コース分布
- 4が負けた時の4自身の2着・3着・4着以下率
- 勝者コース別に4が2着/3着/4着以下へ残る割合
- 2連単・3連単の上位出目

Usage:
  python3 analysis/analyze_tamagawa_lane4_strong_condition_losses.py 2025-09-01 2026-09-09
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
    relation_label,
    required_terms,
    term_info_for_date,
)
from analyze_tamagawa_lane4_exacta_structure import load_targets, pct  # noqa: E402

STRONG_MAKURI_RATE = 15.0
STRONG_RELATION = "内側より上"
WINNER_COURSES = (1, 2, 3, 5, 6)


def blank_stat() -> dict:
    return {
        "strong_n": 0,
        "lane4_win": 0,
        "loss_n": 0,
        "winner": Counter(),
        "lane4_finish": Counter(),
        "winner_x_lane4_finish": Counter(),
        "exacta": Counter(),
        "trifecta": Counter(),
    }


def lane4_finish_label(second: int, third: int | None) -> str:
    if second == 4:
        return "2着"
    if third == 4:
        return "3着"
    return "4着以下"


def add_strong_outcome(stat: dict, first: int, second: int, third: int | None) -> None:
    stat["strong_n"] += 1
    if first == 4:
        stat["lane4_win"] += 1
        return

    stat["loss_n"] += 1
    stat["winner"][first] += 1
    finish = lane4_finish_label(second, third)
    stat["lane4_finish"][finish] += 1
    stat["winner_x_lane4_finish"][(first, finish)] += 1
    stat["exacta"][f"{first}-{second}"] += 1
    if third in range(1, 7):
        stat["trifecta"][f"{first}-{second}-{third}"] += 1


def print_counter_ranked(counter: Counter, n: int, title: str, top_n: int = 15) -> None:
    print(title)
    if n <= 0:
        print("  N=0")
        return
    for i, (key, count) in enumerate(sorted(counter.items(), key=lambda kv: (-kv[1], str(kv[0])))[:top_n], start=1):
        print(f"  {i:2d}. {key:<7} N={count:4d}  構成率={pct(count, n):6.2f}%")


def print_stat(months: int, stat: dict) -> None:
    strong_n = stat["strong_n"]
    loss_n = stat["loss_n"]
    print("\n" + "-" * 116)
    print(f"【過去{months}ヶ月profile：4まくり15%以上 × 4が3より平均ST順位上】")
    print("-" * 116)
    print(
        f"強条件 N={strong_n}  "
        f"4コース1着={stat['lane4_win']} ({pct(stat['lane4_win'], strong_n):.2f}%)  "
        f"4コース非1着={loss_n} ({pct(loss_n, strong_n):.2f}%)"
    )

    print("\n■ 4が負けた時：勝者コース分布")
    print("  " + " | ".join(
        f"{c}:{pct(stat['winner'][c], loss_n):5.1f}%({stat['winner'][c]})"
        for c in WINNER_COURSES
    ))

    print("\n■ 4が負けた時：4コース自身の残り方")
    for label in ("2着", "3着", "4着以下"):
        count = stat["lane4_finish"][label]
        print(f"  {label:<4} N={count:4d}  構成率={pct(count, loss_n):6.2f}%")

    print("\n■ 勝者コース別 × 4コースの残り方")
    for winner in WINNER_COURSES:
        wn = stat["winner"][winner]
        if wn <= 0:
            continue
        parts = []
        for label in ("2着", "3着", "4着以下"):
            count = stat["winner_x_lane4_finish"][(winner, label)]
            parts.append(f"4{label}={pct(count, wn):5.1f}%({count})")
        print(f"  {winner}頭 N={wn:3d}  " + " | ".join(parts))

    print()
    print_counter_ranked(stat["exacta"], loss_n, "■ 4が負けた時：2連単 上位", top_n=15)
    print()
    print_counter_ranked(stat["trifecta"], loss_n, "■ 4が負けた時：3連単 上位", top_n=15)


def analyze(start_date: date, end_date: date):
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

    stats = {m: blank_stat() for m in HISTORY_MONTHS}
    rows = []
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
            if relation != STRONG_RELATION or makuri_rate < STRONG_MAKURI_RATE:
                continue

            add_strong_outcome(stats[months], first, second, third)
            rows.append({
                "history_months": months,
                "race_code": race_code,
                "race_date": race_date.isoformat(),
                "lane4_player_id": by_course[4]["player_id"],
                "lane3_avg_rank": ranks[3],
                "lane4_avg_rank": ranks[4],
                "lane4_makuri_history_n": p4["n"],
                "lane4_makuri_rate": makuri_rate,
                "first_course": first,
                "second_course": second,
                "third_course": third if third is not None else "",
                "lane4_result": "1着" if first == 4 else lane4_finish_label(second, third),
            })

    return processed, skips, stats, rows


def write_csv(start_date: date, end_date: date, rows: list[dict]) -> Path:
    out_dir = Path(__file__).resolve().parent / "output"
    out_dir.mkdir(parents=True, exist_ok=True)
    label = f"{start_date:%Y%m%d}_{end_date:%Y%m%d}"
    path = out_dir / f"tamagawa_lane4_strong_condition_losses_{label}.csv"

    fields = [
        "history_months", "race_code", "race_date", "lane4_player_id",
        "lane3_avg_rank", "lane4_avg_rank", "lane4_makuri_history_n",
        "lane4_makuri_rate", "first_course", "second_course", "third_course",
        "lane4_result",
    ]
    with path.open("w", newline="", encoding="utf-8-sig") as f:
        w = csv.DictWriter(f, fieldnames=fields)
        w.writeheader()
        w.writerows(rows)
    return path


def main() -> None:
    if len(sys.argv) != 3:
        print(
            "Usage: python3 analysis/analyze_tamagawa_lane4_strong_condition_losses.py START_DATE END_DATE",
            file=sys.stderr,
        )
        sys.exit(1)

    start_date = parse_date(sys.argv[1])
    end_date = parse_date(sys.argv[2])
    if start_date > end_date:
        raise RuntimeError("開始日が終了日より後です")

    processed, skips, stats, rows = analyze(start_date, end_date)

    print("\n" + "=" * 116)
    print("多摩川4コース No.2：強条件なのに4が1着を取れなかった時の裏目構造")
    print("強条件 = 過去4コースまくり率15%以上 ＋ 4が3より平均ST順位上")
    print("=" * 116)
    print(f"対象期間   : {start_date} ～ {end_date}")
    print(f"採用候補   : {processed}")
    if skips:
        print("スキップ   : " + ", ".join(f"{k}={v}" for k, v in skips.items()))

    for months in HISTORY_MONTHS:
        print_stat(months, stats[months])

    path = write_csv(start_date, end_date, rows)
    print("\n" + "=" * 116)
    print(f"CSV出力: {path}")
    print("※ CSVには強条件に該当した各レースを12ヶ月/6ヶ月profile別に出力しています。")
    print("=" * 116)


if __name__ == "__main__":
    main()
