#!/usr/bin/env python3
"""AI1着率v5のイン飛び警報内で、穴頭候補の選び方を比較する。"""

from __future__ import annotations

import json
import math
import sys
from collections import defaultdict
from datetime import date
from pathlib import Path

import joblib
import numpy as np


ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "analysis"))
sys.path.insert(0, str(ROOT / "forecast"))

import train_ai_place_v1 as place_data  # noqa: E402
from ai_place_features import vector  # noqa: E402
from final_prediction_ai_bet_integration_compare import load_payouts  # noqa: E402


ALERT_REPORT = ROOT / "analysis/output/upset_alert_v5_compare_2026.json"
MODEL_PATH = ROOT / "forecast/models/ai_place_v1.joblib"
OUTPUT = ROOT / "analysis/output/upset_hole_head_v1_compare_2026.json"
START = date(2026, 9, 1)
END = date(2026, 9, 21)
WINDOWS = (
    ("DEV", date(2026, 9, 1), date(2026, 9, 10)),
    ("TEST", date(2026, 9, 11), date(2026, 9, 21)),
    ("ALL", START, END),
)
EPS = 1.0e-12


def normalize(values: dict[int, float]) -> dict[int, float]:
    total = sum(max(float(value), EPS) for value in values.values())
    return {key: max(float(value), EPS) / total for key, value in values.items()}


def softmax(values: np.ndarray, temperature: float) -> np.ndarray:
    z = np.asarray(values, dtype=np.float64) / max(float(temperature), 1.0e-6)
    z -= z.max()
    out = np.exp(z)
    return out / out.sum()


def blend(base: dict[int, float], ml: dict[int, float], alpha: float) -> dict[int, float]:
    scores = {
        boat: (1.0 - alpha) * math.log(max(base[boat], EPS))
        + alpha * math.log(max(ml[boat], EPS))
        for boat in base
    }
    peak = max(scores.values())
    return normalize({boat: math.exp(score - peak) for boat, score in scores.items()})


def ai_place_marginals(races: list[dict], artifact: dict) -> dict[str, dict]:
    """全条件付きベクトルを一括推論し、各艇の2着・3着・3連対率を返す。"""
    second_meta = []
    second_vectors = []
    third_meta = []
    third_vectors = []
    place_to_id = artifact.get("place_to_id", {})

    for race in races:
        rows = race["boats"]
        place_id = int(place_to_id.get(race["place"], 0))
        for head in range(1, 7):
            candidates = [boat for boat in range(1, 7) if boat != head]
            start = len(second_vectors)
            second_vectors.extend([
                vector(rows[boat], rows[head], race["race_number"], place_id)
                for boat in candidates
            ])
            second_meta.append((race["race_code"], head, candidates, start, len(second_vectors)))
            for second in candidates:
                thirds = [boat for boat in range(1, 7) if boat not in (head, second)]
                start3 = len(third_vectors)
                third_vectors.extend([
                    vector(rows[boat], rows[head], race["race_number"], place_id, rows[second])
                    for boat in thirds
                ])
                third_meta.append((race["race_code"], head, second, thirds, start3, len(third_vectors)))

    second_raw = artifact["second"]["model"].predict(np.vstack(second_vectors))
    third_raw = artifact["third"]["model"].predict(np.vstack(third_vectors))
    race_map = {race["race_code"]: race for race in races}
    second_by_head = {}
    third_by_pair = {}

    for code, head, candidates, start, end in second_meta:
        race = race_map[code]
        ml_values = softmax(second_raw[start:end], artifact["second"]["temperature"])
        ml = {boat: float(value) for boat, value in zip(candidates, ml_values)}
        base = normalize({boat: race["boats"][boat]["v5_probability"] for boat in candidates})
        second_by_head[(code, head)] = blend(base, ml, artifact["second"]["alpha"])

    for code, head, second, candidates, start, end in third_meta:
        race = race_map[code]
        ml_values = softmax(third_raw[start:end], artifact["third"]["temperature"])
        ml = {boat: float(value) for boat, value in zip(candidates, ml_values)}
        base = normalize({boat: race["boats"][boat]["v5_probability"] for boat in candidates})
        third_by_pair[(code, head, second)] = blend(base, ml, artifact["third"]["alpha"])

    out = {}
    for race in races:
        code = race["race_code"]
        first = normalize({boat: race["boats"][boat]["v5_probability"] for boat in range(1, 7)})
        second_margin = {boat: 0.0 for boat in range(1, 7)}
        third_margin = {boat: 0.0 for boat in range(1, 7)}
        for head, p1 in first.items():
            for second, p2 in second_by_head[(code, head)].items():
                mass2 = p1 * p2
                second_margin[second] += mass2
                for third, p3 in third_by_pair[(code, head, second)].items():
                    third_margin[third] += mass2 * p3
        out[code] = {
            "first": first,
            "second": second_margin,
            "third": third_margin,
            "top2": {boat: first[boat] + second_margin[boat] for boat in range(1, 7)},
            "trio": {
                boat: first[boat] + second_margin[boat] + third_margin[boat]
                for boat in range(1, 7)
            },
            "second_by_head": {
                head: second_by_head[(code, head)] for head in range(1, 7)
            },
            "third_by_pair": {
                (head, second): third_by_pair[(code, head, second)]
                for head in range(1, 7)
                for second in range(1, 7)
                if second != head
            },
        }
    return out


def best(boats: list[int], values: dict[int, float]) -> int:
    return min(boats, key=lambda boat: (-float(values[boat]), boat))


def top_boats(boats: list[int], values: dict[int, float], count: int = 2) -> list[int]:
    return sorted(boats, key=lambda boat: (-float(values[boat]), boat))[:count]


def choose_candidates(race: dict, marginals: dict) -> dict[str, int]:
    rows = race["boats"]
    in_boat = next(boat for boat in rows if int(rows[boat]["course"]) == 1)
    outer = [boat for boat in range(1, 7) if boat != in_boat]
    return {
        "AI1_V5_OUTER": best(outer, marginals["first"]),
        "AI_TOP2_V1_OUTER": best(outer, marginals["top2"]),
        "AI_TRIO_V1_OUTER": best(outer, marginals["trio"]),
        "FINAL_OUTER": min(outer, key=lambda boat: (int(rows[boat]["final_rank"]), boat)),
        "PRIMARY_OUTER": min(outer, key=lambda boat: (int(rows[boat]["first_rank"]), boat)),
        "SECONDARY_OUTER": min(outer, key=lambda boat: (int(rows[boat]["second_rank"]), boat)),
    }


def choose_pairs(race: dict, marginals: dict) -> dict[str, list[int]]:
    rows = race["boats"]
    in_boat = next(boat for boat in rows if int(rows[boat]["course"]) == 1)
    outer = [boat for boat in range(1, 7) if boat != in_boat]
    ai1 = top_boats(outer, marginals["first"])
    top2 = top_boats(outer, marginals["top2"])
    trio = top_boats(outer, marginals["trio"])
    hybrid = [top2[0]]
    hybrid.append(trio[0] if trio[0] != hybrid[0] else top2[1])
    return {
        "AI1_V5_PAIR": ai1,
        "AI_TOP2_V1_PAIR": top2,
        "AI_TRIO_V1_PAIR": trio,
        "TOP2_TRIO_HYBRID": hybrid,
    }


def summarize(rows: list[dict], method: str) -> dict:
    n = len(rows)
    losses = sum(row["in_loss"] for row in rows)
    head_hits = [row for row in rows if row["candidates"][method] == row["actual"][0]]
    trio_hits = [row for row in rows if row["candidates"][method] in row["actual"]]
    payouts = [row["payout"] for row in head_hits]
    return {
        "races": n,
        "in_losses": losses,
        "head_hits": len(head_hits),
        "head_hit_rate_all": len(head_hits) / n if n else 0.0,
        "head_capture_on_in_loss": len(head_hits) / losses if losses else 0.0,
        "trio_hits": len(trio_hits),
        "trio_hit_rate": len(trio_hits) / n if n else 0.0,
        "head_hit_avg_payout": float(np.mean(payouts)) if payouts else 0.0,
        "head_hit_median_payout": float(np.median(payouts)) if payouts else 0.0,
    }


def paired_compare(rows: list[dict], challenger: str, baseline: str, seed: int = 428) -> dict:
    deltas = []
    gained = lost = changed = 0
    for row in rows:
        actual_head = row["actual"][0]
        a_choice = row["candidates"][challenger]
        b_choice = row["candidates"][baseline]
        changed += int(a_choice != b_choice)
        a_hit = int(a_choice == actual_head)
        b_hit = int(b_choice == actual_head)
        gained += int(a_hit and not b_hit)
        lost += int(b_hit and not a_hit)
        deltas.append(a_hit - b_hit)
    if not deltas:
        return {"races": 0, "changed": 0, "gained": 0, "lost": 0, "net": 0,
                "delta_rate": 0.0, "improvement_probability": 0.0, "ci95": [0.0, 0.0]}
    values = np.asarray(deltas, dtype=np.float64)
    rng = np.random.default_rng(seed)
    samples = values[rng.integers(0, len(values), size=(5000, len(values)))].mean(axis=1)
    return {
        "races": len(rows),
        "changed": changed,
        "gained": gained,
        "lost": lost,
        "net": gained - lost,
        "delta_rate": float(values.mean()),
        "improvement_probability": float((samples > 0.0).mean() + 0.5 * (samples == 0.0).mean()),
        "ci95": [float(np.quantile(samples, 0.025)), float(np.quantile(samples, 0.975))],
    }


def summarize_pair(rows: list[dict], method: str) -> dict:
    n = len(rows)
    losses = sum(row["in_loss"] for row in rows)
    hits = sum(row["actual"][0] in row["pairs"][method] for row in rows)
    return {
        "races": n,
        "in_losses": losses,
        "head_pair_hits": hits,
        "head_pair_hit_rate_all": hits / n if n else 0.0,
        "head_pair_capture_on_in_loss": hits / losses if losses else 0.0,
    }


def main() -> None:
    alert = json.loads(ALERT_REPORT.read_text(encoding="utf-8"))
    risk_threshold = float(alert["candidates"]["AI1_v5_low"]["threshold"])
    in_probability_threshold = 1.0 - risk_threshold
    artifact = joblib.load(MODEL_PATH)
    races, _places, skipped = place_data.build_races()
    selected = []
    for race in races:
        if not (START <= race["race_date"] <= END):
            continue
        in_boat = next(boat for boat in race["boats"] if race["boats"][boat]["course"] == 1)
        if float(race["boats"][in_boat]["v5_probability"]) <= in_probability_threshold:
            selected.append(race)

    marginals = ai_place_marginals(selected, artifact)
    payouts = load_payouts(START, END)
    rows = []
    for race in selected:
        actual = tuple(int(x) for x in race["actual"])
        in_boat = next(boat for boat in race["boats"] if race["boats"][boat]["course"] == 1)
        rows.append({
            "race_code": race["race_code"],
            "race_date": race["race_date"],
            "actual": actual,
            "in_loss": int(actual[0] != in_boat),
            "payout": int(payouts.get(race["race_code"], 0)),
            "candidates": choose_candidates(race, marginals[race["race_code"]]),
            "pairs": choose_pairs(race, marginals[race["race_code"]]),
        })

    methods = list(rows[0]["candidates"]) if rows else []
    pair_methods = list(rows[0]["pairs"]) if rows else []
    report = {
        "alert": {
            "name": "AI1_v5_low",
            "ai1_in_probability_max": in_probability_threshold,
            "fixed_from": "2026-08-23..2026-08-31",
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
            "in_losses": sum(row["in_loss"] for row in part),
            "methods": {method: summarize(part, method) for method in methods},
            "pair_methods": {method: summarize_pair(part, method) for method in pair_methods},
            "paired_ai_trio_v1": {
                baseline: paired_compare(part, "AI_TRIO_V1_OUTER", baseline)
                for baseline in ("AI1_V5_OUTER", "AI_TOP2_V1_OUTER", "FINAL_OUTER")
            },
        }

    OUTPUT.write_text(json.dumps(report, ensure_ascii=False, indent=2, default=str), encoding="utf-8")
    print(f"穴頭候補比較 / AI1着率v5イン飛び警報（1C<={in_probability_threshold*100:.2f}%）")
    for label in ("DEV", "TEST", "ALL"):
        block = report["windows"][label]
        print(f"\n[{label}] {block['races']}R / イン敗退 {block['in_losses']}R")
        print("候補                    頭的中   全体率  敗退時捕捉  3連対率  頭的中時中央配当")
        print("-" * 84)
        for method in methods:
            row = block["methods"][method]
            print(
                f"{method:<22} {row['head_hits']:>4} "
                f"{row['head_hit_rate_all']*100:>7.2f}% {row['head_capture_on_in_loss']*100:>10.2f}% "
                f"{row['trio_hit_rate']*100:>8.2f}% {row['head_hit_median_payout']:>13,.0f}円"
            )
        print("AI_TRIO_V1_OUTERの直接比較")
        for baseline, pair in block["paired_ai_trio_v1"].items():
            print(
                f"  vs {baseline:<20} 変更{pair['changed']:>3} / "
                f"獲得{pair['gained']:>2}・喪失{pair['lost']:>2} / 純増{pair['net']:+d} / "
                f"改善確率{pair['improvement_probability']*100:>5.1f}%"
            )
        print("穴頭2艇セット")
        for method, row in block["pair_methods"].items():
            print(
                f"  {method:<22} 捕捉{row['head_pair_hits']:>3} / "
                f"全体{row['head_pair_hit_rate_all']*100:>6.2f}% / "
                f"イン敗退時{row['head_pair_capture_on_in_loss']*100:>6.2f}%"
            )
    print(f"\nJSON: {OUTPUT}")


if __name__ == "__main__":
    main()
