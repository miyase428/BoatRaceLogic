#!/usr/bin/env python3
"""新イン飛び警報内で、公式オッズを使う大穴買い目候補を前方分割比較する。"""

from __future__ import annotations

import json
import sys
from datetime import date
from pathlib import Path

import joblib


ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "analysis"))
sys.path.insert(0, str(ROOT / "forecast"))

import train_ai_place_v1 as place_data  # noqa: E402
import upset_hole_head_v1_compare_2026 as head_compare  # noqa: E402
import upset_hole_place_candidates_compare_2026 as place_compare  # noqa: E402
from validate_ai_bet_strategy_modes_2026 import load_official_final_odds  # noqa: E402


ALERT_REPORT = ROOT / "analysis/output/upset_alert_v5_compare_2026.json"
MODEL_PATH = ROOT / "forecast/models/ai_place_v1.joblib"
OUTPUT = ROOT / "analysis/output/upset_big_hole_odds_compare_2026.json"


def probabilities(m: dict) -> dict[tuple[int, int, int], float]:
    out = {}
    for head, p1 in m["first"].items():
        for second, p2 in m["second_by_head"][head].items():
            for third, p3 in m["third_by_pair"][(head, second)].items():
                out[(head, second, third)] = float(p1) * float(p2) * float(p3)
    return out


def top(rows: list[tuple], count: int, score_index: int) -> set[tuple[int, int, int]]:
    rows.sort(key=lambda row: (-float(row[score_index]), row[0]))
    return {row[0] for row in rows[:count]}


def choose(race: dict, m: dict, odds: dict) -> dict[str, set[tuple[int, int, int]]]:
    probs = probabilities(m)
    head_pair = set(place_compare.head_pair(race, m))
    by_probability = [
        (combo, p, p * float(odds.get(combo, 0.0)))
        for combo, p in probs.items()
    ]
    longshot = [row for row in by_probability if float(odds.get(row[0], 0.0)) >= 100.0 and row[1] >= 0.002]
    longshot_pair = [row for row in longshot if row[0][0] in head_pair]
    selective = [
        row for row in by_probability
        if row[1] >= 0.005 and row[2] >= 1.15 and float(odds.get(row[0], 0.0)) > 0.0
    ]
    middle_ev = [
        row for row in by_probability
        if float(odds.get(row[0], 0.0)) >= 30.0 and row[1] >= 0.005 and row[2] >= 1.10
    ]

    # 比較基準：オッズを使わない検証済み8点型。
    main, second_head = place_compare.head_pair(race, m)
    main_joint = sorted(
        [(combo, p) for combo, p in probs.items() if combo[0] == main],
        key=lambda row: (-row[1], row[0]),
    )
    second_joint = sorted(
        [(combo, p) for combo, p in probs.items() if combo[0] == second_head],
        key=lambda row: (-row[1], row[0]),
    )
    fixed8 = {combo for combo, _p in main_joint[:4] + second_joint[:4]}
    return {
        "FIXED_PAIR_4_4": fixed8,
        "ONE_SHOT_P6": top(longshot, 6, 1),
        "ONE_SHOT_PAIR_P6": top(longshot_pair, 6, 1),
        "LONGSHOT_EV6": top(longshot, 6, 2),
        "SELECTIVE_EV6": top(selective, 6, 2),
        "MIDDLE_EV6": top(middle_ev, 6, 2),
    }


def evaluate(rows: list[dict], method: str) -> dict:
    active = [row for row in rows if row["bets"][method]]
    points = sum(len(row["bets"][method]) for row in active)
    hits = [row for row in active if row["actual"] in row["bets"][method]]
    returned = sum(row["payout"] for row in hits)
    investment = points * 100
    return {
        "available_races": len(rows),
        "bet_races": len(active),
        "bet_rate": len(active) / len(rows) if rows else 0.0,
        "points": points,
        "average_points_bet_races": points / len(active) if active else 0.0,
        "hits": len(hits),
        "hit_rate_bet_races": len(hits) / len(active) if active else 0.0,
        "manshu_hits": sum(row["payout"] >= 10000 for row in hits),
        "over_20000_hits": sum(row["payout"] >= 20000 for row in hits),
        "investment_yen": investment,
        "return_yen": returned,
        "roi": returned / investment if investment else None,
        "profit_yen": returned - investment,
    }


def main() -> None:
    alert = json.loads(ALERT_REPORT.read_text(encoding="utf-8"))
    p1_max = 1.0 - float(alert["candidates"]["AI1_v5_low"]["threshold"])
    odds_map = load_official_final_odds()
    artifact = joblib.load(MODEL_PATH)
    all_races, _places, skipped = place_data.build_races()
    selected = []
    for race in all_races:
        if race["race_code"] not in odds_map:
            continue
        in_boat = next(boat for boat in race["boats"] if race["boats"][boat]["course"] == 1)
        if float(race["boats"][in_boat]["v5_probability"]) <= p1_max:
            selected.append(race)
    marginals = head_compare.ai_place_marginals(selected, artifact)
    rows = []
    for race in selected:
        info = odds_map[race["race_code"]]
        rows.append({
            "race_code": race["race_code"],
            "race_date": race["race_date"],
            "actual": tuple(int(x) for x in race["actual"]),
            "payout": int(info["payout"]),
            "bets": choose(race, marginals[race["race_code"]], info["odds"]),
        })
    methods = list(rows[0]["bets"]) if rows else []
    windows = {
        "DEV_0901_0905": [row for row in rows if date(2026, 9, 1) <= row["race_date"] <= date(2026, 9, 5)],
        "TEST_0921": [row for row in rows if row["race_date"] == date(2026, 9, 21)],
        "ALL": rows,
    }
    report = {
        "conditions": {
            "alert": f"AI1着率v5 1C <= {p1_max:.12f}",
            "odds": "official_final_historical",
            "caveat": "確定オッズであり厳密な締切前オッズではない",
            "stake": "全点100円均等",
        },
        "official_odds_races": len(odds_map),
        "selected_races": len(rows),
        "data_skipped": skipped,
        "windows": {
            label: {"races": len(part), "methods": {method: evaluate(part, method) for method in methods}}
            for label, part in windows.items()
        },
    }
    OUTPUT.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")

    print(f"大穴オッズ買い目比較 / 1C AI1着率v5 <= {p1_max*100:.2f}%")
    print("※公式確定オッズ。締切前オッズの厳密な前方検証ではありません。")
    for label in ("DEV_0901_0905", "TEST_0921", "ALL"):
        block = report["windows"][label]
        print(f"\n[{label}] 警報かつオッズ完備 {block['races']}R")
        print("方式                    購入R  平均点  的中  購入時的中率  万舟  2万+     ROI       損益")
        print("-" * 102)
        for method, row in block["methods"].items():
            roi = "-" if row["roi"] is None else f"{row['roi']*100:.2f}%"
            print(
                f"{method:<23} {row['bet_races']:>4} {row['average_points_bet_races']:>7.2f} "
                f"{row['hits']:>4} {row['hit_rate_bet_races']*100:>11.2f}% "
                f"{row['manshu_hits']:>4} {row['over_20000_hits']:>5} {roi:>8} {row['profit_yen']:>10,.0f}円"
            )
    print(f"\nJSON: {OUTPUT}")


if __name__ == "__main__":
    main()
