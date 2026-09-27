#!/usr/bin/env python3
"""固定済みイン飛び・穴頭条件内で、2着/3着候補の順位品質を比較する。"""

from __future__ import annotations

import json
import sys
from collections import defaultdict
from datetime import date
from pathlib import Path

import joblib


ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "analysis"))
sys.path.insert(0, str(ROOT / "forecast"))

import train_ai_place_v1 as place_data  # noqa: E402
import upset_hole_head_v1_compare_2026 as head_compare  # noqa: E402


ALERT_REPORT = ROOT / "analysis/output/upset_alert_v5_compare_2026.json"
MODEL_PATH = ROOT / "forecast/models/ai_place_v1.joblib"
OUTPUT = ROOT / "analysis/output/upset_hole_place_candidates_compare_2026.json"
START = date(2026, 9, 1)
END = date(2026, 9, 21)
WINDOWS = (
    ("DEV", date(2026, 9, 1), date(2026, 9, 10)),
    ("TEST", date(2026, 9, 11), date(2026, 9, 21)),
    ("ALL", START, END),
)


def ordered(candidates: list[int], values: dict[int, float]) -> list[int]:
    return sorted(candidates, key=lambda boat: (-float(values[boat]), boat))


def head_pair(race: dict, m: dict) -> list[int]:
    rows = race["boats"]
    in_boat = next(boat for boat in rows if int(rows[boat]["course"]) == 1)
    outer = [boat for boat in range(1, 7) if boat != in_boat]
    top2_order = ordered(outer, m["top2"])
    trio_order = ordered(outer, m["trio"])
    pair = [top2_order[0]]
    pair.append(trio_order[0] if trio_order[0] != pair[0] else top2_order[1])
    return pair


def ranking_methods(race: dict, m: dict, head: int, second: int | None = None) -> dict[str, list[int]]:
    excluded = {head} if second is None else {head, second}
    candidates = [boat for boat in range(1, 7) if boat not in excluded]
    if second is None:
        ai_values = m["second_by_head"][head]
    else:
        ai_values = m["third_by_pair"][(head, second)]
    rows = race["boats"]
    return {
        "AI_PLACE_V1": ordered(candidates, ai_values),
        "AI1_V5_BASE": ordered(candidates, {boat: rows[boat]["v5_probability"] for boat in candidates}),
        "FINAL_SCORE": sorted(candidates, key=lambda boat: (int(rows[boat]["final_rank"]), boat)),
    }


def empty_counts() -> dict:
    return {"eligible": 0, "top1": 0, "top2": 0, "top3": 0, "top4": 0}


def add_rank(counts: dict, rank: int) -> None:
    counts["eligible"] += 1
    for k in range(1, 5):
        counts[f"top{k}"] += int(rank <= k)


def metrics(counts: dict) -> dict:
    n = counts["eligible"]
    return {
        **counts,
        **{f"top{k}_rate": counts[f"top{k}"] / n if n else 0.0 for k in range(1, 5)},
    }


def evaluate(rows: list[dict]) -> dict:
    second = defaultdict(empty_counts)
    third = defaultdict(empty_counts)
    winning_head_races = 0
    for row in rows:
        race, m = row["race"], row["marginals"]
        actual_head, actual_second, actual_third = race["actual"]
        pair = row["head_pair"]
        if actual_head not in pair:
            continue
        winning_head_races += 1
        for method, rank_order in ranking_methods(race, m, actual_head).items():
            add_rank(second[method], rank_order.index(actual_second) + 1)
        for method, rank_order in ranking_methods(race, m, actual_head, actual_second).items():
            add_rank(third[method], rank_order.index(actual_third) + 1)
    return {
        "races": len(rows),
        "winning_head_races": winning_head_races,
        "second": {method: metrics(counts) for method, counts in second.items()},
        "third": {method: metrics(counts) for method, counts in third.items()},
    }


def main() -> None:
    alert = json.loads(ALERT_REPORT.read_text(encoding="utf-8"))
    p1_max = 1.0 - float(alert["candidates"]["AI1_v5_low"]["threshold"])
    artifact = joblib.load(MODEL_PATH)
    all_races, _places, skipped = place_data.build_races()
    selected = []
    for race in all_races:
        if not (START <= race["race_date"] <= END):
            continue
        in_boat = next(boat for boat in race["boats"] if race["boats"][boat]["course"] == 1)
        if float(race["boats"][in_boat]["v5_probability"]) <= p1_max:
            selected.append(race)
    marginals = head_compare.ai_place_marginals(selected, artifact)
    rows = [{
        "race": race,
        "marginals": marginals[race["race_code"]],
        "head_pair": head_pair(race, marginals[race["race_code"]]),
    } for race in selected]

    report = {
        "conditions": {
            "alert": f"AI1着率v5 1C <= {p1_max:.12f}",
            "head_main": "AI2連対率v1 外艇1位",
            "head_second": "AI3連対率v1 外艇1位（本命と同じならAI2連対率v1 外艇2位）",
        },
        "period": [str(START), str(END)],
        "selected_races": len(rows),
        "data_skipped": skipped,
        "windows": {},
    }
    for label, start, end in WINDOWS:
        part = [row for row in rows if start <= row["race"]["race_date"] <= end]
        report["windows"][label] = evaluate(part)
    OUTPUT.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")

    print(f"穴頭的中後の2着・3着順位比較 / 1C AI1着率v5 <= {p1_max*100:.2f}%")
    for label in ("DEV", "TEST", "ALL"):
        block = report["windows"][label]
        print(f"\n[{label}] 対象{block['races']}R / 穴頭2艇で実頭捕捉{block['winning_head_races']}R")
        for target in ("second", "third"):
            print(f"{target.upper()} 方式                 Top1    Top2    Top3    Top4")
            print("-" * 68)
            for method, row in block[target].items():
                print(
                    f"{method:<24} {row['top1_rate']*100:>6.2f}% "
                    f"{row['top2_rate']*100:>7.2f}% {row['top3_rate']*100:>7.2f}% "
                    f"{row['top4_rate']*100:>7.2f}%"
                )
    print(f"\nJSON: {OUTPUT}")


if __name__ == "__main__":
    main()
