#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
多摩川3コース：★★候補の長期・時系列安定性比較。

★（固定）:
  - 各レース時点の過去12ヶ月 3コース攻め率
    = まくり率 + まくり差し率 >= 15%

★★候補:
  C1 = ★ + 二次30以上 + TOP差2以内
  C2 = ★ + 二次30以上 + TOP差5以内
  C3 = ★ + 二次27以上 + TOP差2以内
  C4 = ★ + 二次27以上 + TOP差5以内

参考:
  LINE5 = ★ + 直線評価5

重要:
- 18ヶ月 / 24ヶ月は「集計対象期間」。
- ★profileは全期間とも各レース時点の過去12ヶ月で固定。
- 短期は直近24ヶ月を6ヶ月ごとの非重複4ブロックに分ける。
- 二次評価は4コース検証と同じ現行Web再現関数を使用する。
- 表示・買い目・予想ロジックは変更しない。

Usage:
  python3 analysis/analyze_tamagawa_lane3_double_star_stability_compare.py 2026-09-09
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
    months_ago,
    parse_date,
)
from analyze_tamagawa_lane4_exacta_structure import load_targets, pct  # noqa: E402
from analyze_tamagawa_lane4_strong_condition_second_eval import (  # noqa: E402
    build_second_scores,
    load_avg_exhibition,
    load_exhibition,
)

PROFILE_MONTHS = 12
STAR_ATTACK_RATE = 15.0

CONDITIONS = (
    ("STAR", "★ 攻め率15%以上"),
    ("C1", "★★候補 二次30+ × TOP差2以内"),
    ("C2", "★★候補 二次30+ × TOP差5以内"),
    ("C3", "★★候補 二次27+ × TOP差2以内"),
    ("C4", "★★候補 二次27+ × TOP差5以内"),
    ("LINE5", "参考 直線評価5"),
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


def summary(stat: dict) -> dict[str, float]:
    n = int(stat["n"])
    first = int(stat["first"])
    second = int(stat["second"])
    third = int(stat["third"])
    return {
        "n": n,
        "head": pct(first, n),
        "top2": pct(first + second, n),
        "top3": pct(first + second + third, n),
    }


def matches(row: dict, key: str) -> bool:
    if key == "STAR":
        return True
    score = float(row["second_score"])
    gap = float(row["gap_to_top"])
    straight = float(row["straight_score"])
    if key == "C1":
        return score >= 30.0 and gap <= 2.0
    if key == "C2":
        return score >= 30.0 and gap <= 5.0
    if key == "C3":
        return score >= 27.0 and gap <= 2.0
    if key == "C4":
        return score >= 27.0 and gap <= 5.0
    if key == "LINE5":
        return straight >= 5.0
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


def print_window(label: str, start_date: date, end_date: date, rows: list[dict]):
    stats = aggregate(rows, start_date, end_date)
    sm = {key: summary(stat) for key, stat in stats.items()}
    star = sm["STAR"]

    print(f"\n■ {label}  {start_date} ～ {end_date}")
    for key, title in CONDITIONS:
        s = sm[key]
        small = " [N小]" if 0 < s["n"] < 10 else ""
        print(
            f"{key:<6} {title:<31} N={int(s['n']):4d}  "
            f"3頭={s['head']:6.2f}% ({s['head'] - star['head']:+6.2f}pt)  "
            f"2連対={s['top2']:6.2f}% ({s['top2'] - star['top2']:+6.2f}pt)  "
            f"3連対={s['top3']:6.2f}% ({s['top3'] - star['top3']:+6.2f}pt){small}"
        )
    return sm


def analyze(end_date: date):
    long_windows, short_windows = calc_windows(end_date)
    target_start = min(w[1] for w in long_windows + short_windows)
    required_history_start = months_ago(target_start, PROFILE_MONTHS)

    print("多摩川 対象レースを読み込み中...", flush=True)
    races = load_targets(target_start, end_date)
    print(f"  対象期間: {target_start} ～ {end_date}", flush=True)
    print(f"  対象レース候補: {len(races)}", flush=True)

    target_pids = sorted({b["player_id"] for r in races.values() for b in r["boats"]})
    print("決まり手履歴を一括読み込み中...", flush=True)
    history = load_history(target_start, end_date, target_pids)
    print(f"  履歴行: {len(history)}", flush=True)
    hist_index = TechniqueHistoryIndex(history)

    print("展示データを一括読み込み中...", flush=True)
    ex_by_race = load_exhibition(target_start, end_date)
    avg_exhibition = load_avg_exhibition()
    print(f"  展示レース: {len(ex_by_race)} / 多摩川6ヶ月平均展示={avg_exhibition:.3f}", flush=True)

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
            raw = boat.get("course")
            if raw is None:
                bad = True
                break
            try:
                c = int(raw)
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

        race_date = race["date"]
        pid3 = str(by_course[3]["player_id"])
        p3 = hist_index.profile(pid3, 3, race_date, PROFILE_MONTHS)
        if int(p3["n"]) <= 0:
            skips["history0_12m"] += 1
            continue

        n = int(p3["n"])
        makuri_rate = pct(int(p3["tech"].get("まくり", 0)), n)
        makurizashi_rate = pct(int(p3["tech"].get("まくり差し", 0)), n)
        attack_rate = makuri_rate + makurizashi_rate
        if attack_rate < STAR_ATTACK_RATE:
            continue

        second_map = build_second_scores(ex_by_race.get(race_code, []), avg_exhibition)
        if second_map is None:
            skips["missing_or_bad_exhibition"] += 1
            continue

        lane3_second = second_map.get(pid3)
        if lane3_second is None:
            skips["lane3_second_score_missing"] += 1
            continue

        first = int(race["first"])
        second = int(race["second"])
        third = int(race["third"]) if race["third"] is not None else None

        rows.append({
            "race_code": race_code,
            "race_date": race_date,
            "lane3_player_id": pid3,
            "history_n": n,
            "makuri_rate": makuri_rate,
            "makurizashi_rate": makurizashi_rate,
            "attack_rate": attack_rate,
            "second_score": float(lane3_second["final_2nd_score"]),
            "gap_to_top": float(lane3_second["gap_to_top"]),
            "second_rank": int(lane3_second["second_rank"]),
            "attack_potential": float(lane3_second["attack_potential"]),
            "st_score": float(lane3_second["st_score"]),
            "straight_score": float(lane3_second["straight_score"]),
            "first": first,
            "second": second,
            "third": third,
        })

    return {
        "target_start": target_start,
        "required_history_start": required_history_start,
        "long_windows": long_windows,
        "short_windows": short_windows,
        "rows": rows,
        "skips": skips,
        "avg_exhibition": avg_exhibition,
        "race_count": len(races),
        "ex_count": len(ex_by_race),
    }


def write_csv(end_date: date, rows: list[dict]) -> Path:
    out_dir = Path(__file__).resolve().parent / "output"
    out_dir.mkdir(parents=True, exist_ok=True)
    path = out_dir / f"tamagawa_lane3_double_star_stability_{end_date:%Y%m%d}.csv"
    fields = [
        "race_code", "race_date", "lane3_player_id", "history_n",
        "makuri_rate", "makurizashi_rate", "attack_rate",
        "second_score", "gap_to_top", "second_rank", "attack_potential",
        "st_score", "straight_score", "first", "second", "third",
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
            "Usage: python3 analysis/analyze_tamagawa_lane3_double_star_stability_compare.py END_DATE",
            file=sys.stderr,
        )
        sys.exit(1)

    end_date = parse_date(sys.argv[1])
    result = analyze(end_date)

    print("\n" + "=" * 166)
    print("多摩川3コース：★★候補 長期・6ヶ月ブロック安定性比較")
    print("★ = 過去12ヶ月の3Cまくり+まくり差し率15%以上")
    print("C1 = 二次30+×TOP差2以内 / C2 = 30+×差5以内 / C3 = 27+×差2以内 / C4 = 27+×差5以内")
    print("=" * 166)
    print(f"検証対象      : {result['target_start']} ～ {end_date}")
    print(f"必要履歴開始  : {result['required_history_start']}（最古対象レースの12ヶ月前）")
    print(f"対象レース候補: {result['race_count']}")
    print(f"展示レース    : {result['ex_count']}")
    print(f"多摩川展示平均: {result['avg_exhibition']:.3f}")
    print(f"★採用行       : {len(result['rows'])}")
    if result["skips"]:
        print("スキップ/注意 : " + ", ".join(f"{k}={v}" for k, v in result["skips"].items()))

    print("\n" + "-" * 166)
    print("【長期確認】")
    print("-" * 166)
    for label, start, end in result["long_windows"]:
        print_window(label, start, end, result["rows"])

    print("\n" + "-" * 166)
    print("【短期確認：6ヶ月 × 4 非重複ブロック】")
    print("-" * 166)
    short_results = {}
    for label, start, end in result["short_windows"]:
        short_results[label] = print_window(label, start, end, result["rows"])

    print("\n" + "=" * 166)
    print("【6ヶ月ブロック再現性】")
    for key, title in CONDITIONS[1:]:
        head_up = 0
        top2_up = 0
        valid = 0
        for label, _, _ in result["short_windows"]:
            s = short_results[label]
            if s[key]["n"] <= 0 or s["STAR"]["n"] <= 0:
                continue
            valid += 1
            if s[key]["head"] > s["STAR"]["head"]:
                head_up += 1
            if s[key]["top2"] > s["STAR"]["top2"]:
                top2_up += 1
        print(
            f"{key:<6} {title:<31} "
            f"3頭率が★超え={head_up}/{valid}  2連対率が★超え={top2_up}/{valid}"
        )

    path = write_csv(end_date, result["rows"])
    print(f"\nCSV出力: {path}")
    print("判定ポイント:")
    print("  1) C1が18ヶ月・24ヶ月でも★より3頭率/2連対率を明確に上げるか")
    print("  2) C1が6ヶ月4ブロックで安定するか")
    print("  3) C2/C3/C4の方がNを残しながら同程度以上なら、より広い条件を優先する")
    print("  4) 直線5は★★本体ではなく、次の★★★候補材料としても残して見る")
    print("※ 現行のexhibition_avg_6mが時点別履歴を持たない場合、展示基準は完全なpoint-in-time再現ではありません。")
    print("※ ここでは表示・買い目・本命ロジックは変更しません。")
    print("=" * 166)


if __name__ == "__main__":
    main()
