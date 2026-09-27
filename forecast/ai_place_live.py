#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""Web/App用 AI2着率・AI3着率 v1 推論。JSONを標準入力で受け取る。"""

from __future__ import annotations

import json
import math
import sys
from pathlib import Path

import joblib
import numpy as np

from ai_place_features import vector


MODEL_PATH = Path(__file__).resolve().parent / "models/ai_place_v1.joblib"
EPS = 1.0e-12


def rank_map(rows: dict[int, dict], key: str) -> dict[int, int]:
    order = sorted(rows, key=lambda boat: (-float(rows[boat].get(key, 0.0)), boat))
    return {boat: index + 1 for index, boat in enumerate(order)}


def normalize(values: dict[int, float]) -> dict[int, float]:
    total = sum(max(float(value), EPS) for value in values.values())
    return {boat: max(float(value), EPS) / total for boat, value in values.items()}


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
    top = max(scores.values())
    return normalize({boat: math.exp(score - top) for boat, score in scores.items()})


def parse_payload(payload: dict, artifact: dict):
    race_code = str(payload.get("race_code", "")).strip().upper()
    if len(race_code) != 13:
        raise RuntimeError("AI着順率: race_codeが不正です")
    raw_v5 = payload.get("ai_win_boats") or {}
    raw_final = payload.get("final_predictions") or {}
    raw_courses = payload.get("course_by_boat") or {}
    legacy_rank = [int(x) for x in (payload.get("legacy_rank_boats") or [])]

    rows = {}
    for boat in range(1, 7):
        v5 = raw_v5.get(str(boat), raw_v5.get(boat, {}))
        final = raw_final.get(str(boat), raw_final.get(boat, {}))
        course = raw_courses.get(str(boat), raw_courses.get(boat, v5.get("course")))
        rate = v5.get("ai_rate")
        if not isinstance(v5, dict) or not isinstance(final, dict) or rate is None or course is None:
            raise RuntimeError("AI着順率: v5・最終評価・進入が6艇分ありません")
        rows[boat] = {
            "lane": boat,
            "course": int(course),
            "v5_probability": float(rate) / 100.0,
            "first_score": float(final.get("first_total_score", 0.0) or 0.0),
            "second_score": float(final.get("second_score", 0.0) or 0.0),
            "final3": float(final.get("final3", 0.0) or 0.0),
            "rate6": float(final.get("rate6_dec", 0.0) or 0.0),
            "rate3": float(final.get("rate3_dec", 0.0) or 0.0),
        }
    if set(row["course"] for row in rows.values()) != set(range(1, 7)):
        raise RuntimeError("AI着順率: 進入コースが不完全です")

    for key, score_key in (("first_rank", "first_score"), ("second_rank", "second_score")):
        ranks = rank_map(rows, score_key)
        for boat in rows:
            rows[boat][key] = ranks[boat]
    if len(legacy_rank) == 6 and set(legacy_rank) == set(range(1, 7)):
        final_ranks = {boat: index + 1 for index, boat in enumerate(legacy_rank)}
    else:
        final_ranks = rank_map(rows, "final3")
    for boat in rows:
        rows[boat]["final_rank"] = final_ranks[boat]
    v5_ranks = rank_map(rows, "v5_probability")
    for boat in rows:
        rows[boat]["v5_rank"] = v5_ranks[boat]

    place = race_code[8:11]
    return (
        race_code,
        rows,
        int(race_code[-2:]),
        int(artifact.get("place_to_id", {}).get(place, 0)),
    )


def conditional(
    artifact: dict,
    target: str,
    rows: dict[int, dict],
    race_number: int,
    place_id: int,
    head: int,
    second: int | None = None,
) -> dict[int, float]:
    candidates = [boat for boat in range(1, 7) if boat != head and boat != second]
    x = np.vstack([
        vector(
            rows[candidate],
            rows[head],
            race_number,
            place_id,
            rows[second] if second is not None else None,
        )
        for candidate in candidates
    ])
    spec = artifact[target]
    ml_values = softmax(spec["model"].predict(x), float(spec["temperature"]))
    ml = {boat: float(value) for boat, value in zip(candidates, ml_values)}
    base = normalize({boat: float(rows[boat]["v5_probability"]) for boat in candidates})
    return blend(base, ml, float(spec["alpha"]))


def calculate(payload: dict) -> dict:
    if not MODEL_PATH.is_file():
        return {"status": "waiting", "error": "AI2着率・AI3着率モデルが未学習です"}
    artifact = joblib.load(MODEL_PATH)
    if artifact.get("version") != "ai_place_v1":
        raise RuntimeError("AI着順率モデルの形式が一致しません")
    race_code, rows, race_number, place_id = parse_payload(payload, artifact)

    first_prob = normalize({boat: row["v5_probability"] for boat, row in rows.items()})
    combinations = []
    second_by_head = {}
    third_by_pair = {}
    for head in range(1, 7):
        second_probs = conditional(
            artifact, "second", rows, race_number, place_id, head
        )
        second_by_head[str(head)] = {str(k): v for k, v in second_probs.items()}
        for second, p2 in second_probs.items():
            third_probs = conditional(
                artifact, "third", rows, race_number, place_id, head, second
            )
            third_by_pair[f"{head}-{second}"] = {str(k): v for k, v in third_probs.items()}
            for third, p3 in third_probs.items():
                combinations.append({
                    "key": f"{head}-{second}-{third}",
                    "boats": [head, second, third],
                    "probability": first_prob[head] * p2 * p3,
                })
    combinations.sort(key=lambda row: (-row["probability"], row["key"]))
    cumulative = 0.0
    first_marginal = {boat: 0.0 for boat in range(1, 7)}
    second_marginal = {boat: 0.0 for boat in range(1, 7)}
    third_marginal = {boat: 0.0 for boat in range(1, 7)}
    for index, row in enumerate(combinations):
        cumulative += row["probability"]
        row["rank"] = index + 1
        row["cumulative_probability"] = cumulative
        first, second, third = row["boats"]
        probability = float(row["probability"])
        first_marginal[first] += probability
        second_marginal[second] += probability
        third_marginal[third] += probability

    trio_marginal = {
        boat: first_marginal[boat] + second_marginal[boat] + third_marginal[boat]
        for boat in range(1, 7)
    }
    top2_marginal = {
        boat: first_marginal[boat] + second_marginal[boat]
        for boat in range(1, 7)
    }
    top2_order = sorted(top2_marginal, key=lambda boat: (-top2_marginal[boat], boat))
    trio_order = sorted(trio_marginal, key=lambda boat: (-trio_marginal[boat], boat))

    main_head = min(rows, key=lambda boat: (-first_prob[boat], boat))
    main_second = second_by_head[str(main_head)]
    main_third = {boat: 0.0 for boat in range(1, 7) if boat != main_head}
    for second_text, p2 in main_second.items():
        second = int(second_text)
        for third_text, p3 in third_by_pair[f"{main_head}-{second}"].items():
            main_third[int(third_text)] += float(p2) * float(p3)

    second_order = sorted(main_second, key=lambda b: (-main_second[b], int(b)))
    third_order = sorted(main_third, key=lambda b: (-main_third[b], int(b)))
    return {
        "status": "ok",
        "error": "",
        "race_code": race_code,
        "head_boat": main_head,
        "boats": {
            str(boat): {
                "course": int(rows[boat]["course"]),
                "ai_second_rate": 0.0 if boat == main_head else float(main_second[str(boat)] * 100.0),
                "ai_third_rate": 0.0 if boat == main_head else float(main_third[boat] * 100.0),
                "ai_second_rank": 0 if boat == main_head else second_order.index(str(boat)) + 1,
                "ai_third_rank": 0 if boat == main_head else third_order.index(boat) + 1,
                "ai_first_unconditional_rate": float(first_marginal[boat] * 100.0),
                "ai_second_unconditional_rate": float(second_marginal[boat] * 100.0),
                "ai_third_unconditional_rate": float(third_marginal[boat] * 100.0),
                "ai_top2_rate": float(top2_marginal[boat] * 100.0),
                "ai_top2_rank": top2_order.index(boat) + 1,
                "ai_trio_rate": float(trio_marginal[boat] * 100.0),
                "ai_trio_rank": trio_order.index(boat) + 1,
            }
            for boat in range(1, 7)
        },
        "combinations": combinations,
        "second_by_head": second_by_head,
        "third_by_pair": third_by_pair,
        "totals": {
            "second": float(sum(main_second.values()) * 100.0),
            "third": float(sum(main_third.values()) * 100.0),
            "trifecta": float(sum(row["probability"] for row in combinations) * 100.0),
            "first_unconditional": float(sum(first_marginal.values()) * 100.0),
            "second_unconditional": float(sum(second_marginal.values()) * 100.0),
            "third_unconditional": float(sum(third_marginal.values()) * 100.0),
            "top2": float(sum(top2_marginal.values()) * 100.0),
            "trio": float(sum(trio_marginal.values()) * 100.0),
        },
        "method": {
            "name": "AI2着率・AI3着率 v1（条件付きLambdaRank）",
            "first": "AI1着率 v5",
            "second_alpha": float(artifact["second"]["alpha"]),
            "third_alpha": float(artifact["third"]["alpha"]),
        },
        "training": artifact.get("training", {}),
        "evaluation": artifact.get("evaluation", {}),
    }


def main() -> int:
    try:
        payload = json.load(sys.stdin)
        print(json.dumps(calculate(payload), ensure_ascii=False))
        return 0
    except Exception as exc:
        print(json.dumps({"status": "error", "error": str(exc)}, ensure_ascii=False))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
