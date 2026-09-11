#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
多摩川4コースの強条件で、3連単フォーメーション2案の的中率・回収率を比較する。

強条件（各対象レース時点の過去profile）:
- 4コースまくり率 >= 15.0%
- 4コース選手の平均ST順位が3コース選手より上（average_rankが小さい）

比較:
A) 4-135-1356  = 9点
B) 4-135-12356 = 12点

BはAに以下3点を追加した形:
- 4-1-2
- 4-3-2
- 4-5-2

1点100円の均等買いで評価する。
過去12ヶ月profile / 過去6ヶ月profile を別々に集計する。
対象レース当日は履歴に含めない。

主な出力:
- 対象R / 的中数 / 的中率 / 投資 / 払戻 / 回収率
- B-A の追加3点による増分的中・増分払戻・増分回収率
- 追加3点それぞれの的中数 / 払戻

Usage:
  python3 analysis/analyze_tamagawa_lane4_bet_pattern_roi.py 2025-09-01 2026-09-09
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
from slit_validate_v2 import connect_db  # noqa: E402

STRONG_MAKURI_RATE = 15.0
STRONG_RELATION = "内側より上"
UNIT_YEN = 100


def expand_formation(firsts, seconds, thirds) -> tuple[str, ...]:
    out = []
    for a in firsts:
        for b in seconds:
            for c in thirds:
                if a == b or a == c or b == c:
                    continue
                out.append(f"{a}-{b}-{c}")
    return tuple(sorted(set(out)))


PATTERNS = {
    "4-135-1356": expand_formation((4,), (1, 3, 5), (1, 3, 5, 6)),
    "4-135-12356": expand_formation((4,), (1, 3, 5), (1, 2, 3, 5, 6)),
}
ADDED_BETS = tuple(sorted(set(PATTERNS["4-135-12356"]) - set(PATTERNS["4-135-1356"])))


def load_payouts(race_codes: list[str]) -> dict[str, int]:
    if not race_codes:
        return {}
    sql = """
        SELECT race_code, trifecta_payout
        FROM boat_race.race_payouts
        WHERE race_code = ANY(%s)
    """
    out = {}
    with connect_db() as conn:
        with conn.cursor() as cur:
            cur.execute(sql, (race_codes,))
            for race_code, payout in cur.fetchall():
                if payout is None:
                    continue
                try:
                    out[str(race_code)] = int(payout)
                except (TypeError, ValueError):
                    continue
    return out


def blank_stat(points: int) -> dict:
    return {
        "races": 0,
        "points": points,
        "hits": 0,
        "investment": 0,
        "payout": 0,
        "hit_outcomes": Counter(),
    }


def add_eval(stat: dict, actual: str, payout: int, bets: tuple[str, ...]) -> None:
    stat["races"] += 1
    stat["investment"] += len(bets) * UNIT_YEN
    if actual in bets:
        stat["hits"] += 1
        stat["payout"] += payout
        stat["hit_outcomes"][actual] += 1


def recovery(stat: dict) -> float:
    return pct(stat["payout"], stat["investment"])


def print_pattern(label: str, stat: dict) -> None:
    print(
        f"{label:<18} "
        f"N={stat['races']:4d}  "
        f"点数={stat['points']:2d}  "
        f"的中={stat['hits']:3d}  "
        f"的中率={pct(stat['hits'], stat['races']):6.2f}%  "
        f"投資={stat['investment']:9,d}円  "
        f"払戻={stat['payout']:9,d}円  "
        f"回収率={recovery(stat):6.2f}%"
    )


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

    print("3連単払戻を読み込み中...", flush=True)
    payouts = load_payouts(list(races.keys()))
    print(f"  払戻取得: {len(payouts)}", flush=True)

    stats = {
        months: {
            name: blank_stat(len(bets))
            for name, bets in PATTERNS.items()
        }
        for months in HISTORY_MONTHS
    }
    added_stats = {
        months: {
            "races": 0,
            "hits": 0,
            "investment": 0,
            "payout": 0,
            "hit_outcomes": Counter(),
        }
        for months in HISTORY_MONTHS
    }

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

        first, second, third = race["first"], race["second"], race["third"]
        if first not in range(1, 7) or second not in range(1, 7) or third not in range(1, 7):
            skips["bad_result"] += 1
            continue

        payout = payouts.get(race_code)
        if payout is None:
            skips["payout_missing"] += 1
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
        actual = f"{first}-{second}-{third}"
        processed += 1

        for months in HISTORY_MONTHS:
            p4 = hist_index.profile(by_course[4]["player_id"], 4, race_date, months)
            if p4["n"] <= 0:
                skips[f"history0_{months}m"] += 1
                continue

            makuri_rate = pct(p4["tech"]["まくり"], p4["n"])
            if relation != STRONG_RELATION or makuri_rate < STRONG_MAKURI_RATE:
                continue

            for name, bets in PATTERNS.items():
                add_eval(stats[months][name], actual, payout, bets)

            add = added_stats[months]
            add["races"] += 1
            add["investment"] += len(ADDED_BETS) * UNIT_YEN
            if actual in ADDED_BETS:
                add["hits"] += 1
                add["payout"] += payout
                add["hit_outcomes"][actual] += 1

            rows.append({
                "history_months": months,
                "race_code": race_code,
                "race_date": race_date.isoformat(),
                "lane4_makuri_rate": f"{makuri_rate:.4f}",
                "lane3_avg_rank": ranks[3],
                "lane4_avg_rank": ranks[4],
                "actual_trifecta": actual,
                "trifecta_payout": payout,
                "hit_4-135-1356": int(actual in PATTERNS["4-135-1356"]),
                "hit_4-135-12356": int(actual in PATTERNS["4-135-12356"]),
                "hit_added_3": int(actual in ADDED_BETS),
            })

    return processed, skips, stats, added_stats, rows


def write_csv(start_date: date, end_date: date, rows: list[dict]) -> Path:
    out_dir = Path(__file__).resolve().parent / "output"
    out_dir.mkdir(parents=True, exist_ok=True)
    label = f"{start_date:%Y%m%d}_{end_date:%Y%m%d}"
    path = out_dir / f"tamagawa_lane4_bet_pattern_roi_{label}.csv"
    fields = [
        "history_months", "race_code", "race_date", "lane4_makuri_rate",
        "lane3_avg_rank", "lane4_avg_rank", "actual_trifecta", "trifecta_payout",
        "hit_4-135-1356", "hit_4-135-12356", "hit_added_3",
    ]
    with path.open("w", newline="", encoding="utf-8-sig") as f:
        w = csv.DictWriter(f, fieldnames=fields)
        w.writeheader()
        w.writerows(rows)
    return path


def main() -> None:
    if len(sys.argv) != 3:
        print(
            "Usage: python3 analysis/analyze_tamagawa_lane4_bet_pattern_roi.py START_DATE END_DATE",
            file=sys.stderr,
        )
        sys.exit(1)

    start_date = parse_date(sys.argv[1])
    end_date = parse_date(sys.argv[2])
    if start_date > end_date:
        raise RuntimeError("開始日が終了日より後です")

    processed, skips, stats, added_stats, rows = analyze(start_date, end_date)

    print("\n" + "=" * 132)
    print("多摩川4コース：強条件での買い目比較")
    print("強条件 = 過去4コースまくり率15%以上 ＋ 4が3より平均ST順位上")
    print("1点100円均等買い")
    print("=" * 132)
    print(f"対象期間   : {start_date} ～ {end_date}")
    print(f"採用候補   : {processed}")
    print(f"A買い目    : 4-135-1356  ({len(PATTERNS['4-135-1356'])}点)")
    print(f"B買い目    : 4-135-12356 ({len(PATTERNS['4-135-12356'])}点)")
    print(f"Bの追加3点 : {', '.join(ADDED_BETS)}")
    if skips:
        print("スキップ   : " + ", ".join(f"{k}={v}" for k, v in skips.items()))

    for months in HISTORY_MONTHS:
        print("\n" + "-" * 132)
        print(f"【過去{months}ヶ月profile】")
        print("-" * 132)
        a = stats[months]["4-135-1356"]
        b = stats[months]["4-135-12356"]
        add = added_stats[months]

        print_pattern("A 4-135-1356", a)
        print_pattern("B 4-135-12356", b)

        print("\n■ B-A：3着に2を追加した3点の増分")
        print(
            f"追加点={len(ADDED_BETS)}点/R  "
            f"追加的中={add['hits']}件  "
            f"的中率増分={pct(add['hits'], add['races']):.2f}pt  "
            f"追加投資={add['investment']:,}円  "
            f"追加払戻={add['payout']:,}円  "
            f"追加3点だけの回収率={pct(add['payout'], add['investment']):.2f}%"
        )
        print(
            f"総回収率差 = {recovery(b) - recovery(a):+.2f}pt / "
            f"総的中率差 = {pct(b['hits'], b['races']) - pct(a['hits'], a['races']):+.2f}pt"
        )

        print("\n■ 追加3点の的中内訳")
        if add["hits"] <= 0:
            print("  的中なし")
        else:
            for bet in ADDED_BETS:
                count = add["hit_outcomes"][bet]
                if count > 0:
                    print(f"  {bet}: {count}件")

    path = write_csv(start_date, end_date, rows)
    print("\n" + "=" * 132)
    print(f"CSV出力: {path}")
    print("※ 払戻は race_payouts.trifecta_payout を使用。的中時は100円券1枚分の払戻を加算。")
    print("=" * 132)


if __name__ == "__main__":
    main()
