#!/usr/bin/env python3
"""固定済みイン飛び・穴頭・AI着順率v1から穴買い目の点数別成績を比較する。"""

from __future__ import annotations

import json
import sys
from datetime import date
from pathlib import Path

import joblib
import numpy as np


ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "analysis"))
sys.path.insert(0, str(ROOT / "forecast"))

import train_ai_place_v1 as place_data  # noqa: E402
import upset_hole_head_v1_compare_2026 as head_compare  # noqa: E402
import upset_hole_place_candidates_compare_2026 as place_compare  # noqa: E402
from final_prediction_ai_bet_integration_compare import load_payouts  # noqa: E402


ALERT_REPORT = ROOT / "analysis/output/upset_alert_v5_compare_2026.json"
MODEL_PATH = ROOT / "forecast/models/ai_place_v1.joblib"
OUTPUT = ROOT / "analysis/output/upset_hole_bet_v1_compare_2026.json"
START = date(2026, 9, 1)
END = date(2026, 9, 21)
WINDOWS = (
    ("DEV", date(2026, 9, 1), date(2026, 9, 10)),
    ("TEST", date(2026, 9, 11), date(2026, 9, 21)),
    ("ALL", START, END),
)


def joint_order(m: dict, head: int) -> list[tuple[int, int, int]]:
    rows = []
    for second, p2 in m["second_by_head"][head].items():
        for third, p3 in m["third_by_pair"][(head, second)].items():
            rows.append((float(p2) * float(p3), int(second), int(third)))
    rows.sort(key=lambda row: (-row[0], row[1], row[2]))
    return [(head, second, third) for _p, second, third in rows]


def formation_tickets(m: dict, head: int, second_count: int) -> set[tuple[int, int, int]]:
    seconds = sorted(
        m["second_by_head"][head],
        key=lambda boat: (-m["second_by_head"][head][boat], boat),
    )[:second_count]
    return {
        (head, second, third)
        for second in seconds
        for third in range(1, 7)
        if third not in (head, second)
    }


def strategies(race: dict, m: dict) -> dict[str, set[tuple[int, int, int]]]:
    main, second_head = place_compare.head_pair(race, m)
    main_joint = joint_order(m, main)
    second_joint = joint_order(m, second_head)
    return {
        "MAIN_FORMATION12": formation_tickets(m, main, 3),
        "MAIN_JOINT12": set(main_joint[:12]),
        "PAIR_JOINT4_4": set(main_joint[:4] + second_joint[:4]),
        "PAIR_JOINT6_6": set(main_joint[:6] + second_joint[:6]),
        "PAIR_JOINT8_4": set(main_joint[:8] + second_joint[:4]),
        "PAIR_FORMATION2_2": formation_tickets(m, main, 2) | formation_tickets(m, second_head, 2),
        "PAIR_JOINT8_8": set(main_joint[:8] + second_joint[:8]),
        "PAIR_JOINT10_10": set(main_joint[:10] + second_joint[:10]),
    }


def evaluate(rows: list[dict], method: str) -> dict:
    races = len(rows)
    points = sum(len(row["strategies"][method]) for row in rows)
    hits = [row for row in rows if row["actual"] in row["strategies"][method]]
    returns = sum(row["payout"] for row in hits)
    investment = points * 100
    result = {
        "races": races,
        "points": points,
        "average_points": points / races if races else 0.0,
        "hits": len(hits),
        "hit_rate": len(hits) / races if races else 0.0,
        "investment_yen": investment,
        "return_yen": returns,
        "roi": returns / investment if investment else 0.0,
        "profit_yen": returns - investment,
        "hit_payout_median": float(np.median([row["payout"] for row in hits])) if hits else 0.0,
        "hits_5000_plus": sum(row["payout"] >= 5000 for row in hits),
        "hits_10000_plus": sum(row["payout"] >= 10000 for row in hits),
        "hits_20000_plus": sum(row["payout"] >= 20000 for row in hits),
    }
    if races and points:
        investments = np.asarray([len(row["strategies"][method]) * 100 for row in rows], dtype=float)
        returns_by_race = np.asarray([
            row["payout"] if row["actual"] in row["strategies"][method] else 0
            for row in rows
        ], dtype=float)
        rng = np.random.default_rng(20260926)
        indexes = rng.integers(0, races, size=(10000, races))
        boot_investment = investments[indexes].sum(axis=1)
        boot_return = returns_by_race[indexes].sum(axis=1)
        boot_roi = np.divide(boot_return, boot_investment, out=np.zeros_like(boot_return), where=boot_investment > 0)
        result["roi_probability_over_100"] = float((boot_roi > 1.0).mean() + 0.5 * (boot_roi == 1.0).mean())
        result["roi_ci95"] = [float(np.quantile(boot_roi, 0.025)), float(np.quantile(boot_roi, 0.975))]
    else:
        result["roi_probability_over_100"] = 0.0
        result["roi_ci95"] = [0.0, 0.0]
    return result


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
    payouts = load_payouts(START, END)
    rows = []
    for race in selected:
        payout = int(payouts.get(race["race_code"], 0))
        if payout <= 0:
            continue
        m = marginals[race["race_code"]]
        rows.append({
            "race_code": race["race_code"],
            "race_date": race["race_date"],
            "actual": tuple(int(x) for x in race["actual"]),
            "payout": payout,
            "strategies": strategies(race, m),
        })

    methods = list(rows[0]["strategies"]) if rows else []
    report = {
        "conditions": {
            "alert": f"AI1着率v5 1C <= {p1_max:.12f}",
            "head_pair": "AI2連対率v1外艇1位 + AI3連対率v1外艇1位（重複時AI2連対率2位）",
            "ticket_probability": "AI2着率v1 × AI3着率v1（頭固定条件付き）",
            "stake": "全点100円均等",
        },
        "period": [str(START), str(END)],
        "selected_races": len(rows),
        "data_skipped": skipped,
        "windows": {},
    }
    for label, start, end in WINDOWS:
        part = [row for row in rows if start <= row["race_date"] <= end]
        report["windows"][label] = {
            "races": len(part),
            "methods": {method: evaluate(part, method) for method in methods},
        }
    OUTPUT.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")

    print(f"イン飛び穴買い目比較 / 1C AI1着率v5 <= {p1_max*100:.2f}% / 1点100円")
    for label in ("DEV", "TEST", "ALL"):
        block = report["windows"][label]
        print(f"\n[{label}] {block['races']}R")
        print("方式                    平均点数  的中    的中率     ROI   ROI>100確率   1万+  2万+   損益")
        print("-" * 108)
        for method, row in block["methods"].items():
            print(
                f"{method:<23} {row['average_points']:>7.2f} "
                f"{row['hits']:>4} {row['hit_rate']*100:>8.2f}% {row['roi']*100:>7.2f}% "
                f"{row['roi_probability_over_100']*100:>10.1f}% "
                f"{row['hits_10000_plus']:>5} {row['hits_20000_plus']:>5} {row['profit_yen']:>9,.0f}円"
            )
    print(f"\nJSON: {OUTPUT}")


if __name__ == "__main__":
    main()
