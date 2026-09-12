#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
多摩川3コース★条件に、現行Webの二次評価が★★候補の絞り込み材料として使えるかを見る。

★候補（対象レース時点の過去profile）:
- 3コースの攻め率 = まくり率 + まくり差し率 >= 15.0%
- ST順位は★必須条件にしない

4コース分析と同じ流れで、過去12ヶ月profile / 過去6ヶ月profileを別々に確認する。

主な確認:
1) ★全体の3コース 1着 / 2連対 / 3連対
2) ★ × 3コースの二次順位（1～6位）
3) ★ × 二次トップとの差（0 / 1-2 / 3-5 / 6点以上）
4) ★ × 二次スコア帯（30+ / 27-29 / 24-26 / 23以下）
5) ★ × 二次攻め成分（展示ST点 + 直線点）
6) ★ × 直線評価

二次評価計算は analyze_tamagawa_lane4_strong_condition_second_eval.py の共通関数を使い、
4コース時と同じ評価式で比較する。

Usage:
  python3 analysis/analyze_tamagawa_lane3_strong_condition_second_eval.py 2025-09-01 2026-09-09

CSV:
  analysis/output/tamagawa_lane3_strong_condition_second_eval_YYYYMMDD_YYYYMMDD.csv
"""

from __future__ import annotations

import csv
import sys
from collections import Counter, defaultdict
from datetime import date
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from analyze_tamagawa_boaters_hypothesis import (  # noqa: E402
    HISTORY_MONTHS,
    TechniqueHistoryIndex,
    load_history,
    parse_date,
)
from analyze_tamagawa_lane4_exacta_structure import load_targets, pct  # noqa: E402
from analyze_tamagawa_lane4_strong_condition_second_eval import (  # noqa: E402
    build_second_scores,
    load_avg_exhibition,
    load_exhibition,
)

STAR_ATTACK_RATE = 15.0


def blank_stat() -> dict:
    return {
        "n": 0,
        "first": 0,
        "second": 0,
        "third": 0,
        "top2": 0,
        "top3": 0,
    }


def add_outcome(stat: dict, first: int, second: int, third: int | None) -> None:
    stat["n"] += 1
    if first == 3:
        stat["first"] += 1
    if second == 3:
        stat["second"] += 1
    if third == 3:
        stat["third"] += 1
    if first == 3 or second == 3:
        stat["top2"] += 1
    if first == 3 or second == 3 or third == 3:
        stat["top3"] += 1


def gap_band(gap: float) -> str:
    if gap < 1e-9:
        return "TOP差0"
    if gap <= 2.0:
        return "TOP差1-2"
    if gap <= 5.0:
        return "TOP差3-5"
    return "TOP差6以上"


def score_band(value: float) -> str:
    if value >= 30.0:
        return "二次30以上"
    if value >= 27.0:
        return "二次27-29"
    if value >= 24.0:
        return "二次24-26"
    return "二次23以下"


def attack_band(value: float) -> str:
    if value >= 9.0:
        return "攻め成分9-10"
    if value >= 7.0:
        return "攻め成分7-8"
    if value >= 5.0:
        return "攻め成分5-6"
    return "攻め成分2-4"


def straight_band(value: float) -> str:
    return f"直線{int(round(value))}"


def print_stat(label: str, stat: dict) -> None:
    n = int(stat["n"])
    print(
        f"{label:<24} N={n:4d}  "
        f"3頭={pct(stat['first'], n):6.2f}%  "
        f"32着={pct(stat['second'], n):6.2f}%  "
        f"33着={pct(stat['third'], n):6.2f}%  "
        f"3-2連対={pct(stat['top2'], n):6.2f}%  "
        f"3-3連対={pct(stat['top3'], n):6.2f}%"
    )


def profile_attack(profile: dict) -> tuple[float, float, float]:
    n = int(profile["n"])
    if n <= 0:
        return 0.0, 0.0, 0.0
    makuri = pct(int(profile["tech"].get("まくり", 0)), n)
    makurizashi = pct(int(profile["tech"].get("まくり差し", 0)), n)
    return makuri, makurizashi, makuri + makurizashi


def analyze(start_date: date, end_date: date):
    print("多摩川 対象レースを読み込み中...", flush=True)
    races = load_targets(start_date, end_date)
    print(f"  対象レース候補: {len(races)}", flush=True)

    target_pids = sorted({b["player_id"] for r in races.values() for b in r["boats"]})
    print("決まり手履歴を一括読み込み中...", flush=True)
    history = load_history(start_date, end_date, target_pids)
    print(f"  履歴行: {len(history)}", flush=True)
    hist_index = TechniqueHistoryIndex(history)

    print("展示データを一括読み込み中...", flush=True)
    ex_by_race = load_exhibition(start_date, end_date)
    avg_exhibition = load_avg_exhibition()
    print(f"  展示レース: {len(ex_by_race)} / 多摩川6ヶ月平均展示={avg_exhibition:.3f}", flush=True)

    stats = {}
    for months in HISTORY_MONTHS:
        stats[months] = {
            "all": blank_stat(),
            "rank_exact": defaultdict(blank_stat),
            "gap_band": defaultdict(blank_stat),
            "score_band": defaultdict(blank_stat),
            "attack_band": defaultdict(blank_stat),
            "straight_band": defaultdict(blank_stat),
        }

    rows: list[dict] = []
    skips = Counter()

    for race_code, race in races.items():
        boats = race["boats"]
        if len(boats) != 6:
            skips["not_6_boats"] += 1
            continue

        by_course = {}
        bad = False
        for boat in boats:
            raw_course = boat.get("course")
            try:
                c = int(raw_course)
            except (TypeError, ValueError):
                bad = True
                break
            if c not in range(1, 7) or c in by_course:
                bad = True
                break
            by_course[c] = boat
        if bad or set(by_course) != set(range(1, 7)):
            skips["bad_entry_course"] += 1
            continue

        second_map = build_second_scores(ex_by_race.get(race_code, []), avg_exhibition)
        if second_map is None:
            skips["missing_or_bad_exhibition"] += 1
            continue

        pid3 = str(by_course[3]["player_id"])
        lane3_second = second_map.get(pid3)
        if lane3_second is None:
            skips["lane3_second_score_missing"] += 1
            continue

        first, second, third = race["first"], race["second"], race["third"]
        race_date = race["date"]

        second_score = float(lane3_second["final_2nd_score"])
        second_rank = int(lane3_second["second_rank"])
        top_gap = float(lane3_second["gap_to_top"])
        attack_potential = float(lane3_second["attack_potential"])
        straight_score = float(lane3_second["straight_score"])

        for months in HISTORY_MONTHS:
            p3 = hist_index.profile(pid3, 3, race_date, months)
            if int(p3["n"]) <= 0:
                skips[f"history0_{months}m"] += 1
                continue

            makuri_rate, makurizashi_rate, attack_rate = profile_attack(p3)
            if attack_rate < STAR_ATTACK_RATE:
                continue

            s = stats[months]
            add_outcome(s["all"], first, second, third)
            add_outcome(s["rank_exact"][second_rank], first, second, third)
            add_outcome(s["gap_band"][gap_band(top_gap)], first, second, third)
            add_outcome(s["score_band"][score_band(second_score)], first, second, third)
            add_outcome(s["attack_band"][attack_band(attack_potential)], first, second, third)
            add_outcome(s["straight_band"][straight_band(straight_score)], first, second, third)

            rows.append({
                "history_months": months,
                "race_code": race_code,
                "race_date": race_date.isoformat(),
                "lane3_player_id": pid3,
                "lane3_history_n": int(p3["n"]),
                "lane3_makuri_rate": makuri_rate,
                "lane3_makurizashi_rate": makurizashi_rate,
                "lane3_attack_rate": attack_rate,
                "lane3_second_score": second_score,
                "lane3_second_rank": second_rank,
                "lane3_gap_to_top": top_gap,
                "lane3_attack_potential": attack_potential,
                "lane3_st_score": float(lane3_second["st_score"]),
                "lane3_straight_score": straight_score,
                "lane3_ex_score": float(lane3_second["ex_score"]),
                "lane3_lap_score": float(lane3_second["lap_score"]),
                "lane3_mawari_score": float(lane3_second["mawari_score"]),
                "first_course": int(first),
                "second_course": int(second),
                "third_course": int(third) if third is not None else "",
            })

    return stats, rows, skips, len(races), len(ex_by_race), avg_exhibition


def write_csv(start_date: date, end_date: date, rows: list[dict]) -> Path:
    out_dir = Path(__file__).resolve().parent / "output"
    out_dir.mkdir(parents=True, exist_ok=True)
    path = out_dir / f"tamagawa_lane3_strong_condition_second_eval_{start_date:%Y%m%d}_{end_date:%Y%m%d}.csv"
    fields = [
        "history_months", "race_code", "race_date", "lane3_player_id",
        "lane3_history_n", "lane3_makuri_rate", "lane3_makurizashi_rate", "lane3_attack_rate",
        "lane3_second_score", "lane3_second_rank", "lane3_gap_to_top",
        "lane3_attack_potential", "lane3_st_score", "lane3_straight_score",
        "lane3_ex_score", "lane3_lap_score", "lane3_mawari_score",
        "first_course", "second_course", "third_course",
    ]
    with path.open("w", newline="", encoding="utf-8-sig") as f:
        writer = csv.DictWriter(f, fieldnames=fields)
        writer.writeheader()
        writer.writerows(rows)
    return path


def main() -> None:
    if len(sys.argv) != 3:
        print(
            "Usage: python3 analysis/analyze_tamagawa_lane3_strong_condition_second_eval.py START_DATE END_DATE",
            file=sys.stderr,
        )
        sys.exit(1)

    start_date = parse_date(sys.argv[1])
    end_date = parse_date(sys.argv[2])
    if start_date > end_date:
        raise RuntimeError("開始日が終了日より後です")

    stats, rows, skips, race_count, ex_count, avg_exhibition = analyze(start_date, end_date)

    print("\n" + "=" * 144)
    print("多摩川3コース：★条件 × 現行二次評価")
    print("★ = 3Cまくり+まくり差し率15%以上（ST順位は必須にしない）")
    print("★★候補を4コース時と同じく、二次スコア・TOP差・攻め成分から探す")
    print("=" * 144)
    print(f"対象期間       : {start_date} ～ {end_date}")
    print(f"対象レース候補 : {race_count}")
    print(f"展示レース     : {ex_count}")
    print(f"多摩川展示平均 : {avg_exhibition:.3f}")
    if skips:
        print("スキップ       : " + ", ".join(f"{k}={v}" for k, v in skips.items()))

    gap_order = ("TOP差0", "TOP差1-2", "TOP差3-5", "TOP差6以上")
    score_order = ("二次30以上", "二次27-29", "二次24-26", "二次23以下")
    attack_order = ("攻め成分9-10", "攻め成分7-8", "攻め成分5-6", "攻め成分2-4")
    straight_order = ("直線5", "直線4", "直線3", "直線2", "直線1")

    for months in HISTORY_MONTHS:
        s = stats[months]
        print("\n" + "-" * 144)
        print(f"【過去{months}ヶ月profile：★ = 攻め率15%以上】")
        print("-" * 144)
        print("\n■ ★全体")
        print_stat("★ ALL", s["all"])

        print("\n■ 二次順位")
        for rank in range(1, 7):
            print_stat(f"二次{rank}位", s["rank_exact"][rank])

        print("\n■ TOP差")
        for label in gap_order:
            print_stat(label, s["gap_band"][label])

        print("\n■ 二次スコア帯")
        for label in score_order:
            print_stat(label, s["score_band"][label])

        print("\n■ 攻め成分（展示ST点＋直線点）")
        for label in attack_order:
            print_stat(label, s["attack_band"][label])

        print("\n■ 直線評価")
        for label in straight_order:
            print_stat(label, s["straight_band"][label])

    path = write_csv(start_date, end_date, rows)
    print("\n" + "=" * 144)
    print(f"CSV出力: {path}")
    print("次の判断: ★★を『3軸・頭もあり』として成立させる二次スコア×TOP差の候補を絞ります。")
    print("※ 12ヶ月/6ヶ月profileは同じ対象レースを含むため独立標本ではありません。")
    print("※ 表示・買い目・本命ロジックはまだ変更しません。")
    print("=" * 144)


if __name__ == "__main__":
    main()
