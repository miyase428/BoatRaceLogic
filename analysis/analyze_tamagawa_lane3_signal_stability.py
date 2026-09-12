#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
多摩川3コース：主軸サイン候補の長期・時系列安定性検証。

第一段階/第二段階で有力だった条件を、各レース時点の過去12ヶ月profileで固定して確認する。

主に見る条件:
- BASE       : 対象レース全体
- A15        : 3コース攻め率（まくり + まくり差し）>= 15%
- A15_ST     : A15 + 3が2より平均ST順位 上
- M15        : 3コースまくり率 >= 15%
- M15_ST     : M15 + 3が2より平均ST順位 上

重要:
- 18ヶ月 / 24ヶ月は集計期間。
- 攻め率/まくり率は、全期間とも各レース時点の「過去12ヶ月profile」で固定。
- 短期は直近24ヶ月を6ヶ月ごとの非重複4ブロックに分ける。
- 6ヶ月profileとの比較ではなく、同じ条件が時系列で再現するかを見る。
- 表示・買い目・予想ロジックは変更しない。

Usage:
  python3 analysis/analyze_tamagawa_lane3_signal_stability.py 2026-09-09
"""

from __future__ import annotations

import csv
import sys
from collections import Counter
from datetime import date, timedelta
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from analyze_tamagawa_boaters_hypothesis import (  # noqa: E402
    TechniqueHistoryIndex,
    load_history,
    load_racer_results,
    months_ago,
    parse_date,
    relation_label,
    required_terms,
    term_info_for_date,
)
from analyze_tamagawa_lane4_exacta_structure import load_targets, pct  # noqa: E402

PROFILE_MONTHS = 12
ATTACK_MIN = 15.0
MAKURI_MIN = 15.0
ST_RELATION = "内側より上"

CONDITIONS = (
    ("BASE", "基準"),
    ("A15", "攻め率15%以上"),
    ("A15_ST", "攻め率15%以上 × ST上"),
    ("M15", "まくり15%以上"),
    ("M15_ST", "まくり15%以上 × ST上"),
)


def calc_windows(end_date: date):
    anchor_exclusive = end_date + timedelta(days=1)
    start_24 = months_ago(anchor_exclusive, 24)
    start_18 = months_ago(anchor_exclusive, 18)

    long_windows = [
        ("直近18ヶ月", start_18, end_date),
        ("直近24ヶ月", start_24, end_date),
    ]

    labels = ("直近0-6ヶ月", "6-12ヶ月前", "12-18ヶ月前", "18-24ヶ月前")
    short_windows = []
    for i, label in enumerate(labels):
        end_exclusive = months_ago(anchor_exclusive, 6 * i)
        start = months_ago(anchor_exclusive, 6 * (i + 1))
        end = end_exclusive - timedelta(days=1)
        short_windows.append((label, start, end))

    return long_windows, short_windows


def blank_stat() -> dict:
    return {"n": 0, "first": 0, "second": 0, "third": 0}


def add_outcome(stat: dict, first: int, second: int, third: int | None) -> None:
    stat["n"] += 1
    if first == 3:
        stat["first"] += 1
    if second == 3:
        stat["second"] += 1
    if third == 3:
        stat["third"] += 1


def rate_summary(stat: dict) -> dict[str, float]:
    n = stat["n"]
    top2 = stat["first"] + stat["second"]
    top3 = top2 + stat["third"]
    return {
        "n": n,
        "head": pct(stat["first"], n),
        "top2": pct(top2, n),
        "top3": pct(top3, n),
    }


def matches(row: dict, key: str) -> bool:
    if key == "BASE":
        return True
    if key == "A15":
        return row["attack_rate"] >= ATTACK_MIN
    if key == "A15_ST":
        return row["attack_rate"] >= ATTACK_MIN and row["st_relation"] == ST_RELATION
    if key == "M15":
        return row["makuri_rate"] >= MAKURI_MIN
    if key == "M15_ST":
        return row["makuri_rate"] >= MAKURI_MIN and row["st_relation"] == ST_RELATION
    raise ValueError(key)


def aggregate(rows: list[dict], start_date: date, end_date: date) -> dict[str, dict]:
    stats = {key: blank_stat() for key, _ in CONDITIONS}
    for row in rows:
        d = row["race_date"]
        if not (start_date <= d <= end_date):
            continue
        for key, _ in CONDITIONS:
            if matches(row, key):
                add_outcome(stats[key], row["first"], row["second"], row["third"])
    return stats


def print_window(label: str, start_date: date, end_date: date, rows: list[dict]) -> dict[str, dict[str, float]]:
    stats = aggregate(rows, start_date, end_date)
    summaries = {key: rate_summary(stat) for key, stat in stats.items()}
    base = summaries["BASE"]

    print(f"\n■ {label}  {start_date} ～ {end_date}")
    for key, title in CONDITIONS:
        s = summaries[key]
        small = " [N小]" if 0 < s["n"] < 10 else ""
        print(
            f"{key:<7} {title:<25} N={int(s['n']):4d}  "
            f"3頭={s['head']:6.2f}% ({s['head'] - base['head']:+6.2f}pt)  "
            f"2連対={s['top2']:6.2f}% ({s['top2'] - base['top2']:+6.2f}pt)  "
            f"3連対={s['top3']:6.2f}% ({s['top3'] - base['top3']:+6.2f}pt){small}"
        )

    a = summaries["A15"]
    m = summaries["M15"]
    print(
        f"  A15→M15 3頭率差 = {m['head'] - a['head']:+.2f}pt  "
        f"(A15 {a['head']:.2f}% → M15 {m['head']:.2f}%)"
    )
    return summaries


def analyze(end_date: date):
    long_windows, short_windows = calc_windows(end_date)
    target_start = min(w[1] for w in long_windows + short_windows)
    required_history_start = months_ago(target_start, PROFILE_MONTHS)

    print("多摩川 対象レースを読み込み中...", flush=True)
    races = load_targets(target_start, end_date)
    print(f"  対象期間: {target_start} ～ {end_date}", flush=True)
    print(f"  対象レース候補: {len(races)}", flush=True)

    target_pids = sorted({b["player_id"] for r in races.values() for b in r["boats"]})
    terms = required_terms(target_start, end_date)
    print(f"racer_results 読み込み中... terms={','.join(terms)}", flush=True)
    racer = load_racer_results(terms)

    print("決まり手履歴を一括読み込み中...", flush=True)
    history = load_history(target_start, end_date, target_pids)
    print(f"  履歴行: {len(history)}", flush=True)
    hist_index = TechniqueHistoryIndex(history)

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
            c = boat["course"]
            if c not in range(1, 7) or c in by_course:
                bad = True
                break
            by_course[c] = boat
        if bad or set(by_course) != set(range(1, 7)):
            skips["bad_entry_course"] += 1
            continue

        race_date = race["date"]
        pid3 = by_course[3]["player_id"]
        p3 = hist_index.profile(pid3, 3, race_date, PROFILE_MONTHS)
        if p3["n"] <= 0:
            skips["history0_12m"] += 1
            continue

        n = p3["n"]
        makuri_rate = pct(p3["tech"].get("まくり", 0), n)
        makurizashi_rate = pct(p3["tech"].get("まくり差し", 0), n)
        attack_rate = makuri_rate + makurizashi_rate

        st_relation = None
        lane2_avg_rank = None
        lane3_avg_rank = None
        term = term_info_for_date(race_date)
        rr2 = racer.get((term, by_course[2]["player_id"]))
        rr3 = racer.get((term, pid3))
        if rr2 is not None and rr3 is not None:
            r2 = rr2[2]["avg_rank"]
            r3 = rr3[3]["avg_rank"]
            if r2 is not None and r3 is not None:
                lane2_avg_rank = float(r2)
                lane3_avg_rank = float(r3)
                st_relation = relation_label(lane3_avg_rank, lane2_avg_rank)
            else:
                skips["missing_course_avg_rank"] += 1
        else:
            skips["missing_course_avg_rank"] += 1

        rows.append({
            "race_code": race_code,
            "race_date": race_date,
            "lane3_player_id": pid3,
            "history_n": n,
            "makuri_rate": makuri_rate,
            "makurizashi_rate": makurizashi_rate,
            "attack_rate": attack_rate,
            "lane2_avg_rank": lane2_avg_rank,
            "lane3_avg_rank": lane3_avg_rank,
            "st_relation": st_relation,
            "first": int(race["first"]),
            "second": int(race["second"]),
            "third": int(race["third"]) if race["third"] is not None else None,
        })

    return target_start, required_history_start, long_windows, short_windows, rows, skips


def write_csv(end_date: date, rows: list[dict]) -> Path:
    out_dir = Path(__file__).resolve().parent / "output"
    out_dir.mkdir(parents=True, exist_ok=True)
    path = out_dir / f"tamagawa_lane3_signal_stability_{end_date:%Y%m%d}.csv"
    fields = [
        "race_code", "race_date", "lane3_player_id", "history_n",
        "makuri_rate", "makurizashi_rate", "attack_rate",
        "lane2_avg_rank", "lane3_avg_rank", "st_relation",
        "first", "second", "third",
    ]
    with path.open("w", newline="", encoding="utf-8-sig") as f:
        writer = csv.DictWriter(f, fieldnames=fields)
        writer.writeheader()
        for row in rows:
            out = dict(row)
            out["race_date"] = row["race_date"].isoformat()
            writer.writerow(out)
    return path


def main() -> None:
    if len(sys.argv) != 2:
        print(
            "Usage: python3 analysis/analyze_tamagawa_lane3_signal_stability.py END_DATE",
            file=sys.stderr,
        )
        sys.exit(1)

    end_date = parse_date(sys.argv[1])
    target_start, history_start, long_windows, short_windows, rows, skips = analyze(end_date)

    print("\n" + "=" * 152)
    print("多摩川3コース：主軸サイン候補 長期・6ヶ月ブロック安定性検証")
    print("A15 = 過去12ヶ月の3Cまくり+まくり差し率15%以上（★主軸候補）")
    print("M15 = 過去12ヶ月の3Cまくり率15%以上（上位サイン候補）")
    print("=" * 152)
    print(f"検証対象      : {target_start} ～ {end_date}")
    print(f"必要履歴開始  : {history_start}（最古対象レースの12ヶ月前）")
    print(f"採用行        : {len(rows)}")
    if skips:
        print("スキップ/注意 : " + ", ".join(f"{k}={v}" for k, v in skips.items()))

    print("\n" + "-" * 152)
    print("【長期確認】")
    print("-" * 152)
    long_result = {}
    for label, start, end in long_windows:
        long_result[label] = print_window(label, start, end, rows)

    print("\n" + "-" * 152)
    print("【短期確認：6ヶ月 × 4 非重複ブロック】")
    print("-" * 152)
    short_result = {}
    for label, start, end in short_windows:
        short_result[label] = print_window(label, start, end, rows)

    print("\n" + "=" * 152)
    for key, title in CONDITIONS[1:]:
        wins = 0
        valid = 0
        for label, _, _ in short_windows:
            result = short_result[label]
            if result[key]["n"] <= 0:
                continue
            valid += 1
            if result[key]["head"] > result["BASE"]["head"]:
                wins += 1
        print(f"{key:<7} {title:<25}: 6ヶ月ブロックで基準3頭率を上回る = {wins}/{valid}")

    path = write_csv(end_date, rows)
    print(f"\nCSV出力: {path}")
    print("判定ポイント:")
    print("  1) A15が18ヶ月・24ヶ月でも基準より明確に上か")
    print("  2) A15が6ヶ月4ブロックで一貫して基準より上か")
    print("  3) M15がA15より強い上位サインとして時系列でも再現するか")
    print("  4) ST上追加がN減少に見合う改善を継続的に出すか")
    print("※ この段階では3コース★条件をまだ表示・買い目・本命ロジックへ反映しません。")
    print("=" * 152)


if __name__ == "__main__":
    main()
