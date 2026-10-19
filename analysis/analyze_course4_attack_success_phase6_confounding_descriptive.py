#!/usr/bin/env python3
"""Phase 6C-1: descriptive pre-race confounding diagnostics only."""
from __future__ import annotations

import csv
import datetime
import hashlib
import json
import math
import subprocess
from pathlib import Path

import numpy as np


ROOT = Path(__file__).resolve().parent
OUT = ROOT / "output"
RACE_CACHE = OUT / "course4_attack_success_phase6_race_cache_20251001_20260930.csv"
RACE_SHA256 = "9275720d6eb56f29bf216cf0daca71cc10a6db3c052e875df8d473f349a8a778"
CSV_OUT = OUT / "course4_attack_success_phase6_confounding_descriptive.csv"
REPORT_OUT = OUT / "course4_attack_success_phase6_confounding_report.json"
SPLITS = ("Validation", "EXPOSED_CONFIRMATION")
EXPECTED_N = {"Validation": 20595, "EXPOSED_CONFIRMATION": 22122}
BANDS = ("0-19", "20-39", "40-59", "60-79", "80-100")
FEATURES = ("national_win_rate", "local_win_rate", "national_2ren_rate", "local_2ren_rate", "average_st", "motor_2ren_rate", "boat_2ren_rate")
OUTCOMES = ("second", "third", "top3")


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def read_csv(path: Path) -> list[dict[str, str]]:
    with path.open(encoding="utf-8", newline="") as handle:
        return list(csv.DictReader(handle))


def numeric(value: str) -> float | None:
    try:
        result = float(value)
        return result if math.isfinite(result) else None
    except (TypeError, ValueError):
        return None


def average_ranks(values: np.ndarray) -> np.ndarray:
    order = np.argsort(values, kind="mergesort")
    ranks = np.empty(len(values), dtype=float)
    start = 0
    while start < len(values):
        end = start
        while end + 1 < len(values) and values[order[end + 1]] == values[order[start]]:
            end += 1
        ranks[order[start:end + 1]] = (start + end + 2) / 2.0
        start = end + 1
    return ranks


def spearman(x: list[float], y: list[float]) -> float | None:
    if len(x) < 2:
        return None
    xr, yr = average_ranks(np.asarray(x, dtype=float)), average_ranks(np.asarray(y, dtype=float))
    if np.std(xr) == 0 or np.std(yr) == 0:
        return None
    return float(np.corrcoef(xr, yr)[0, 1])


def details(rows: list[dict[str, str]], course: int, feature: str) -> tuple[list[float], list[float], list[dict[str, str]]]:
    complete = [row for row in rows if numeric(row[f"course{course}_{feature}"]) is not None]
    values = [numeric(row[f"course{course}_{feature}"]) for row in complete]
    probabilities = [float(row["attack_success_probability"]) for row in complete]
    return [float(value) for value in values], probabilities, complete


def main() -> None:
    if sha256(RACE_CACHE) != RACE_SHA256:
        raise RuntimeError("race cache SHA256 mismatch")
    rows = read_csv(RACE_CACHE)
    keys = [row["race_code"] for row in rows]
    if len(rows) != 42717 or len(keys) != len(set(keys)) or any(not key for key in keys):
        raise RuntimeError("race cache row/race_code audit mismatch")
    records: list[dict[str, object]] = []
    report: dict[str, object] = {
        "race_cache_path": "analysis/output/course4_attack_success_phase6_race_cache_20251001_20260930.csv",
        "race_cache_sha256": RACE_SHA256,
        "race_cache_rows": len(rows),
        "duplicate_race_code": 0,
        "fixed_threshold_stratification": "not performed: fixed threshold unavailable for all listed features; only fixed Success bands were used",
        "splits": {},
    }
    for split in SPLITS:
        split_rows = [row for row in rows if row["split"] == split]
        if len(split_rows) != EXPECTED_N[split]:
            raise RuntimeError(f"unexpected {split} N")
        split_report: dict[str, object] = {"N": len(split_rows), "courses": {}}
        for course in (5, 6):
            course_report: dict[str, object] = {}
            for feature in FEATURES:
                values, probabilities, complete = details(split_rows, course, feature)
                attack_rho = spearman(probabilities, values)
                band_stats: dict[str, dict[str, float | int | None]] = {}
                for band in BANDS:
                    band_values = [numeric(row[f"course{course}_{feature}"]) for row in split_rows if row["success_band"] == band and numeric(row[f"course{course}_{feature}"]) is not None]
                    numbers = [float(value) for value in band_values]
                    data = {"N": len(numbers), "mean": float(np.mean(numbers)) if numbers else None, "median": float(np.median(numbers)) if numbers else None}
                    band_stats[band] = data
                    records.append({"analysis_type": "success_band_descriptive", "split": split, "course": course, "feature": feature, "outcome": "", "success_band": band, "effective_n": len(numbers), "mean": data["mean"], "median": data["median"], "spearman": "", "high_minus_low_mean": "", "high_minus_low_median": "", "outcome_positive_n": ""})
                low, high = band_stats["0-19"], band_stats["80-100"]
                high_low = {"mean": float(high["mean"] - low["mean"]), "median": float(high["median"] - low["median"]), "low_n": int(low["N"]), "high_n": int(high["N"])}
                records.append({"analysis_type": "attack_probability_spearman", "split": split, "course": course, "feature": feature, "outcome": "", "success_band": "", "effective_n": len(complete), "mean": "", "median": "", "spearman": attack_rho, "high_minus_low_mean": high_low["mean"], "high_minus_low_median": high_low["median"], "outcome_positive_n": ""})
                outcome_stats: dict[str, dict[str, float | int | None]] = {}
                for outcome in OUTCOMES:
                    outcomes = [float(int(row[f"course{course}_{outcome}"])) for row in complete]
                    rho = spearman(values, outcomes)
                    outcome_stats[outcome] = {"effective_n": len(complete), "positive_n": int(sum(outcomes)), "spearman": rho}
                    records.append({"analysis_type": "outcome_spearman", "split": split, "course": course, "feature": feature, "outcome": outcome, "success_band": "", "effective_n": len(complete), "mean": "", "median": "", "spearman": rho, "high_minus_low_mean": "", "high_minus_low_median": "", "outcome_positive_n": int(sum(outcomes))})
                course_report[feature] = {"missing_n": len(split_rows) - len(complete), "effective_n": len(complete), "attack_success_probability_spearman": attack_rho, "success_band": band_stats, "high_minus_low": high_low, "outcome_spearman": outcome_stats}
            split_report["courses"][f"course{course}"] = course_report
        report["splits"][split] = split_report
    fields = ("analysis_type", "split", "course", "feature", "outcome", "success_band", "effective_n", "mean", "median", "spearman", "high_minus_low_mean", "high_minus_low_median", "outcome_positive_n")
    with CSV_OUT.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fields); writer.writeheader(); writer.writerows(records)
    report.update({
        "source_script": "analysis/analyze_course4_attack_success_phase6_confounding_descriptive.py",
        "source_script_sha256": sha256(Path(__file__).resolve()),
        "source_git_commit": subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT.parent, text=True).strip(),
        "generated_at": datetime.datetime.now().astimezone().isoformat(),
    })
    REPORT_OUT.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(report, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
