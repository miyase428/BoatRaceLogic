#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""多摩川5コース一次サインに二次評価を重ね、長期安定性を比較する。

入力は ``analyze_tamagawa_lane5_primary_stability.py`` のCSV。
一次条件は対象日前12ヶ月の5C攻め率10%以上。二次条件は当日展示から
作った既存の二次評価だけを用い、着順は集計にのみ使用する。

Usage:
  python3 analysis/analyze_tamagawa_lane5_secondary_stability.py \
    analysis/output/tamagawa_lane5_primary_stability_20260909.csv
"""

from __future__ import annotations

import csv
import sys
from collections import OrderedDict
from datetime import date
from pathlib import Path


def pct(num: int, den: int) -> float:
    return 100.0 * num / den if den else 0.0


def months_ago(value: date, months: int) -> date:
    year = value.year
    month = value.month - months
    while month <= 0:
        year -= 1
        month += 12
    # The report end is the 9th, so this is sufficient and avoids an external dependency.
    return date(year, month, value.day)


def parse_float(value: str) -> float | None:
    try:
        return float(value)
    except (TypeError, ValueError):
        return None


def parse_int(value: str) -> int | None:
    try:
        return int(float(value))
    except (TypeError, ValueError):
        return None


def load_rows(path: Path) -> list[dict]:
    rows = []
    with path.open("r", encoding="utf-8-sig", newline="") as f:
        reader = csv.DictReader(f)
        required = {
            "race_date", "p5_12_attack", "second_score", "second_rank", "gap",
            "attack_potential", "st_score", "straight_score", "ex_score",
            "lap_score", "mawari_score", "first", "second", "third",
        }
        missing = required - set(reader.fieldnames or [])
        if missing:
            raise RuntimeError(f"CSV列不足: {sorted(missing)}")
        for raw in reader:
            row = {
                "race_date": date.fromisoformat(raw["race_date"]),
                "attack12": parse_float(raw["p5_12_attack"]),
                "score": parse_float(raw["second_score"]),
                "rank": parse_int(raw["second_rank"]),
                "gap": parse_float(raw["gap"]),
                "attack_potential": parse_float(raw["attack_potential"]),
                "st_score": parse_float(raw["st_score"]),
                "straight": parse_float(raw["straight_score"]),
                "ex": parse_float(raw["ex_score"]),
                "lap": parse_float(raw["lap_score"]),
                "mawari": parse_float(raw["mawari_score"]),
                "first": parse_int(raw["first"]),
                "second": parse_int(raw["second"]),
                "third": parse_int(raw["third"]),
            }
            rows.append(row)
    return rows


def has_secondary(row: dict) -> bool:
    return row["score"] is not None and row["gap"] is not None


def candidate_flags(row: dict) -> OrderedDict[str, bool]:
    s = row["score"]
    gap = row["gap"]
    rank = row["rank"]
    return OrderedDict([
        ("STAR_EX", True),
        ("A15", row["attack12"] >= 15.0),
        ("S24", s >= 24.0),
        ("S27", s >= 27.0),
        ("S30", s >= 30.0),
        ("G2", gap <= 2.0),
        ("G5", gap <= 5.0),
        ("R1", rank <= 1),
        ("R2", rank <= 2),
        ("R3", rank <= 3),
        ("S24_G2", s >= 24.0 and gap <= 2.0),
        ("S24_G5", s >= 24.0 and gap <= 5.0),
        ("S27_G2", s >= 27.0 and gap <= 2.0),
        ("S27_G5", s >= 27.0 and gap <= 5.0),
        ("S30_G2", s >= 30.0 and gap <= 2.0),
        ("S30_G5", s >= 30.0 and gap <= 5.0),
        ("ST5", row["st_score"] >= 5.0),
        ("STRAIGHT4", row["straight"] >= 4.0),
        ("STRAIGHT5", row["straight"] >= 5.0),
        ("EX4", row["ex"] >= 4.0),
        ("EX5", row["ex"] >= 5.0),
        ("LAP4", row["lap"] >= 4.0),
        ("LAP5", row["lap"] >= 5.0),
        ("MAWARI4", row["mawari"] >= 4.0),
        ("MAWARI5", row["mawari"] >= 5.0),
        ("ATTACK7", row["attack_potential"] >= 7.0),
        ("ATTACK9", row["attack_potential"] >= 9.0),
    ])


LABELS = {
    "STAR_EX": "★（攻め率10%以上）・二次あり",
    "A15": "★内：攻め率15%以上",
    "S24": "二次24以上", "S27": "二次27以上", "S30": "二次30以上",
    "G2": "TOP差2以内", "G5": "TOP差5以内",
    "R1": "二次順位1位", "R2": "二次順位2位以内", "R3": "二次順位3位以内",
    "S24_G2": "二次24以上×TOP差2以内", "S24_G5": "二次24以上×TOP差5以内",
    "S27_G2": "二次27以上×TOP差2以内", "S27_G5": "二次27以上×TOP差5以内",
    "S30_G2": "二次30以上×TOP差2以内", "S30_G5": "二次30以上×TOP差5以内",
    "ST5": "ST評価5", "STRAIGHT4": "直線4以上", "STRAIGHT5": "直線5",
    "EX4": "展示タイム評価4以上", "EX5": "展示タイム評価5",
    "LAP4": "周回評価4以上", "LAP5": "周回評価5",
    "MAWARI4": "周り足評価4以上", "MAWARI5": "周り足評価5",
    "ATTACK7": "展示攻め成分7以上", "ATTACK9": "展示攻め成分9以上",
}


def blank() -> dict:
    return {"n": 0, "first": 0, "second": 0, "third": 0}


def add(stat: dict, row: dict) -> None:
    stat["n"] += 1
    stat["first"] += row["first"] == 5
    stat["second"] += row["second"] == 5
    stat["third"] += row["third"] == 5


def summarize(stat: dict) -> dict:
    n = stat["n"]
    return {
        **stat,
        "head": pct(stat["first"], n),
        "top2": pct(stat["first"] + stat["second"], n),
        "top3": pct(stat["first"] + stat["second"] + stat["third"], n),
    }


def aggregate(rows: list[dict], start: date, end: date) -> OrderedDict[str, dict]:
    stats = OrderedDict((key, blank()) for key in LABELS)
    for row in rows:
        if not start <= row["race_date"] <= end:
            continue
        if row["attack12"] is None or row["attack12"] < 10.0 or not has_secondary(row):
            continue
        for key, enabled in candidate_flags(row).items():
            if enabled:
                add(stats[key], row)
    return OrderedDict((key, summarize(value)) for key, value in stats.items())


def print_window(label: str, start: date, end: date, rows: list[dict]) -> OrderedDict[str, dict]:
    stats = aggregate(rows, start, end)
    base = stats["STAR_EX"]
    print(f"\n■ {label}  {start} ～ {end}")
    for key, stat in stats.items():
        small = " [N小]" if 0 < stat["n"] < 20 else ""
        print(
            f"{key:<10} {LABELS[key]:<28} N={stat['n']:4d}  "
            f"5頭={stat['head']:6.2f}% ({stat['head'] - base['head']:+6.2f}pt)  "
            f"2連対={stat['top2']:6.2f}% ({stat['top2'] - base['top2']:+6.2f}pt)  "
            f"3連対={stat['top3']:6.2f}% ({stat['top3'] - base['top3']:+6.2f}pt){small}"
        )
    return stats


def main() -> None:
    if len(sys.argv) != 2:
        raise SystemExit("Usage: python3 analysis/analyze_tamagawa_lane5_secondary_stability.py CSV_PATH")
    path = Path(sys.argv[1])
    rows = load_rows(path)
    end = max(row["race_date"] for row in rows)
    anchor = date(end.year, end.month, end.day + 1)
    windows = [
        ("直近12ヶ月", months_ago(anchor, 12), end),
        ("直近18ヶ月", months_ago(anchor, 18), end),
        ("直近24ヶ月", months_ago(anchor, 24), end),
    ]
    blocks = []
    labels = ("直近0-6ヶ月", "6-12ヶ月前", "12-18ヶ月前", "18-24ヶ月前")
    for idx, label in enumerate(labels):
        block_start = months_ago(anchor, (idx + 1) * 6)
        block_end = months_ago(anchor, idx * 6)
        block_end = date.fromordinal(block_end.toordinal() - 1)
        blocks.append((label, block_start, block_end))

    print("=" * 170)
    print("多摩川5コース：★ × 現行二次評価　候補比較と時系列安定性")
    print("★ = 対象日前12ヶ月の5C攻め率（まくり＋まくり差し）10%以上")
    print("二次評価が取得できた★だけを共通母集団として比較")
    print("=" * 170)
    long_values = {label: print_window(label, start, finish, rows) for label, start, finish in windows}
    print("\n【6ヶ月×4非重複ブロック】")
    block_values = {label: print_window(label, start, finish, rows) for label, start, finish in blocks}

    print("\n【再現性まとめ：各区間の★母集団との比較】")
    for key in list(LABELS)[1:]:
        valid = [value for value in block_values.values() if value[key]["n"] >= 20]
        head_wins = sum(value[key]["head"] > value["STAR_EX"]["head"] for value in valid)
        top2_wins = sum(value[key]["top2"] > value["STAR_EX"]["top2"] for value in valid)
        s24 = long_values["直近24ヶ月"][key]
        b24 = long_values["直近24ヶ月"]["STAR_EX"]
        print(
            f"{key:<10} {LABELS[key]:<28} N24={s24['n']:4d}  "
            f"Δ頭={s24['head'] - b24['head']:+6.2f}pt  "
            f"Δ2連={s24['top2'] - b24['top2']:+6.2f}pt  "
            f"6ヶ月頭超え={head_wins}/{len(valid)}  2連超え={top2_wins}/{len(valid)}"
        )

    print("\n注意: 4区間の再現性分母はN>=20の区間のみ。少母数候補は参考扱い。")


if __name__ == "__main__":
    main()
