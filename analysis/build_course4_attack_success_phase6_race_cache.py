#!/usr/bin/env python3
"""Build the Phase 6A-1c race-level cache from frozen Phase 5 artifacts.

This is intentionally cache construction only: it does not fit a model, query
the database, write provenance metadata, or aggregate clusters.
"""
from __future__ import annotations

import csv
import hashlib
import json
import math
import time
from pathlib import Path

import numpy as np

from analyze_course4_attack_success_phase5 import base_scores, predict_artifact, score


ROOT = Path(__file__).resolve().parent
OUT = ROOT / "output"
ENRICHMENT = OUT / "course4_attack_success_phase6_boat56_prerace_enrichment_20251001_20260930.csv"
ENRICHMENT_SHA256 = "54bcf2ff332532078346905dfe3ab148b5c405c713ceed0d217ce48295fd53e9"
CACHE = OUT / "course4_attack_success_phase6_race_cache_20251001_20260930.csv"

OUTCOME_COLUMNS = ("first", "second", "third", "top2", "top3")
PRERACE_COLUMNS = (
    "player_id", "national_win_rate", "local_win_rate", "national_2ren_rate",
    "local_2ren_rate", "average_st", "motor_2ren_rate", "boat_2ren_rate",
)
CACHE_COLUMNS = (
    "race_code", "race_date", "stadium", "split",
    "attack_success_probability", "success_score_0_100", "success_band", "success_decile",
    *(f"course5_{name}" for name in OUTCOME_COLUMNS),
    *(f"course5_{name}" for name in PRERACE_COLUMNS),
    *(f"course6_{name}" for name in OUTCOME_COLUMNS),
    *(f"course6_{name}" for name in PRERACE_COLUMNS),
    "pair_second", "pair_third", "pair_top3",
)


def read_csv(path: Path) -> list[dict[str, str]]:
    with path.open(encoding="utf-8", newline="") as handle:
        return list(csv.DictReader(handle))


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def yes(value: object) -> bool:
    return str(value).lower() in {"true", "1", "t"}


def present(value: object) -> bool:
    return value is not None and str(value) != ""


def band(value: int) -> str:
    for low, high in ((0, 19), (20, 39), (40, 59), (60, 79), (80, 100)):
        if low <= value <= high:
            return f"{low}-{high}"
    raise ValueError(f"score outside 0..100: {value}")


def decile(value: int) -> int:
    # The frozen percentile score has 0..100 endpoints; preserve 100 in D10.
    return min(10, value // 10 + 1)


def write_csv(path: Path, rows: list[dict[str, object]]) -> None:
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=CACHE_COLUMNS, extrasaction="raise")
        writer.writeheader()
        writer.writerows(rows)


def coverage(rows: list[dict[str, str]], columns: tuple[str, ...]) -> dict[str, dict[str, float | int]]:
    n = len(rows)
    return {
        column: {
            "non_null_n": sum(present(row[column]) for row in rows),
            "coverage_pct": 100.0 * sum(present(row[column]) for row in rows) / n if n else math.nan,
        }
        for column in columns
    }


def main() -> None:
    started = time.perf_counter()
    if sha256(ENRICHMENT) != ENRICHMENT_SHA256:
        raise RuntimeError("enrichment SHA256 differs from the fixed Phase 6A-1b input")

    # base_scores uses frozen artifacts for prediction only; it never calls fit.
    (_, validation, confirmation), (_, validation_x, _, _, confirmation_x), _, _ = base_scores()
    selection = json.loads((OUT / "course4_attack_success_phase5_selection_model.json").read_text(encoding="utf-8"))
    final = json.loads((OUT / "course4_attack_success_phase5_final_model.json").read_text(encoding="utf-8"))
    validation_probability = predict_artifact(selection, validation_x)
    confirmation_probability = predict_artifact(final, confirmation_x)
    validation_score = score(validation_probability, selection["score_percentile_boundaries"])
    confirmation_score = score(confirmation_probability, final["score_percentile_boundaries"])

    base: list[tuple[dict[str, object], float, int, str]] = []
    base.extend((row, float(probability), int(value), "Validation") for row, probability, value in zip(validation, validation_probability, validation_score))
    base.extend((row, float(probability), int(value), "EXPOSED_CONFIRMATION") for row, probability, value in zip(confirmation, confirmation_probability, confirmation_score))
    if len({str(row["race_code"]) for row, *_ in base}) != len(base):
        raise RuntimeError("fixed Phase 5 base is not unique by race_code")

    enrichment_rows = read_csv(ENRICHMENT)
    enrichment = {row["race_code"]: row for row in enrichment_rows}
    if len(enrichment) != len(enrichment_rows):
        raise RuntimeError("enrichment input is not unique by race_code")

    rows: list[dict[str, object]] = []
    matched = 0
    for base_row, probability, value, split in base:
        race_code = str(base_row["race_code"])
        extra = enrichment.get(race_code)
        if extra is not None:
            matched += 1
        # A left join intentionally preserves every frozen primary-sample row.
        result: dict[str, object] = {
            "race_code": race_code,
            "race_date": base_row["race_date"],
            "stadium": base_row["stadium"],
            "split": split,
            "attack_success_probability": probability,
            "success_score_0_100": value,
            "success_band": band(value),
            "success_decile": decile(value),
        }
        for course, source in ((5, "five"), (6, "six")):
            for outcome in OUTCOME_COLUMNS:
                result[f"course{course}_{outcome}"] = int(yes(base_row[f"{source}_{outcome}"]))
            for name in PRERACE_COLUMNS:
                result[f"course{course}_{name}"] = "" if extra is None else extra.get(f"course{course}_{name}", "")
        result["pair_second"] = int(result["course5_second"]) - int(result["course6_second"])
        result["pair_third"] = int(result["course5_third"]) - int(result["course6_third"])
        result["pair_top3"] = int(result["course5_top3"]) - int(result["course6_top3"])
        rows.append(result)

    if len(rows) != len(base):
        raise RuntimeError("left join changed frozen base row count")
    write_csv(CACHE, rows)

    # Save-and-reload audit is intentionally done against the just-written CSV.
    saved = read_csv(CACHE)
    keys = [row["race_code"] for row in saved]
    duplicate = len(keys) - len(set(keys))
    missing = sum(not present(value) for value in keys)
    if len(saved) != len(base) or duplicate or missing:
        raise RuntimeError("saved cache failed its race_code audit")
    pair_values = {column: sorted({int(row[column]) for row in saved}) for column in ("pair_second", "pair_third", "pair_top3")}
    if any(not set(values).issubset({-1, 0, 1}) for values in pair_values.values()):
        raise RuntimeError("paired outcome is outside {-1, 0, +1}")

    audit = {
        "base_rows": len(base),
        "enrichment_rows": len(enrichment_rows),
        "matched_rows": matched,
        "unmatched_rows": len(base) - matched,
        "match_rate_pct": 100.0 * matched / len(base),
        "rows_after_left_join": len(rows),
        "reloaded_rows": len(saved),
        "duplicate_race_code": duplicate,
        "race_code_missing": missing,
        "split_rows": {name: sum(row["split"] == name for row in saved) for name in ("Validation", "EXPOSED_CONFIRMATION")},
        "outcome_counts": {f"course{course}_{outcome}": sum(int(row[f"course{course}_{outcome}"]) for row in saved) for course in (5, 6) for outcome in OUTCOME_COLUMNS},
        "pair_values": pair_values,
        "course5_coverage": coverage(saved, tuple(f"course5_{name}" for name in PRERACE_COLUMNS)),
        "course6_coverage": coverage(saved, tuple(f"course6_{name}" for name in PRERACE_COLUMNS)),
        "attack_success_probability": {
            "min": float(min(float(row["attack_success_probability"]) for row in saved)),
            "max": float(max(float(row["attack_success_probability"]) for row in saved)),
            "mean": float(np.mean([float(row["attack_success_probability"]) for row in saved])),
            "missing": sum(not present(row["attack_success_probability"]) for row in saved),
        },
        "success_score_0_100": {
            "min": min(int(row["success_score_0_100"]) for row in saved),
            "max": max(int(row["success_score_0_100"]) for row in saved),
            "mean": float(np.mean([int(row["success_score_0_100"]) for row in saved])),
            "missing": sum(not present(row["success_score_0_100"]) for row in saved),
        },
        "success_band_n": {name: sum(row["success_band"] == name for row in saved) for name in ("0-19", "20-39", "40-59", "60-79", "80-100")},
        "enrichment_sha256_verified": ENRICHMENT_SHA256,
        "cache_sha256": sha256(CACHE),
        "total_seconds": time.perf_counter() - started,
    }
    print(json.dumps(audit, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
