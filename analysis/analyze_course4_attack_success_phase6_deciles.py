#!/usr/bin/env python3
"""Phase 6B-3: fixed-score decile outcome diagnostics only."""
from __future__ import annotations

import csv
import datetime
import hashlib
import json
import subprocess
from pathlib import Path

import numpy as np


ROOT = Path(__file__).resolve().parent
OUT = ROOT / "output"
RACE_CACHE = OUT / "course4_attack_success_phase6_race_cache_20251001_20260930.csv"
RACE_SHA256 = "9275720d6eb56f29bf216cf0daca71cc10a6db3c052e875df8d473f349a8a778"
BOOTSTRAP = OUT / "course4_attack_success_phase6_bootstrap_1000.json"
CSV_OUT = OUT / "course4_attack_success_phase6_deciles.csv"
REPORT_OUT = OUT / "course4_attack_success_phase6_deciles_report.json"
SPLITS = ("Validation", "EXPOSED_CONFIRMATION")
EXPECTED_N = {"Validation": 20595, "EXPOSED_CONFIRMATION": 22122}
OUTCOMES = ("first", "second", "third", "top2", "top3")


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def read_csv(path: Path) -> list[dict[str, str]]:
    with path.open(encoding="utf-8", newline="") as handle:
        return list(csv.DictReader(handle))


def average_ranks(values: list[float]) -> np.ndarray:
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


def spearman(deciles: list[int], rates: list[float]) -> float:
    return float(np.corrcoef(average_ranks([float(x) for x in deciles]), average_ranks(rates))[0, 1])


def direction(value: float) -> str:
    return "positive" if value > 0 else ("negative" if value < 0 else "zero")


def main() -> None:
    if sha256(RACE_CACHE) != RACE_SHA256:
        raise RuntimeError("race cache SHA256 mismatch")
    rows = read_csv(RACE_CACHE)
    keys = [row["race_code"] for row in rows]
    if len(rows) != 42717 or len(keys) != len(set(keys)) or any(not key for key in keys):
        raise RuntimeError("race cache row or race_code audit mismatch")
    required = {"split", "success_decile", *(f"course{course}_{outcome}" for course in (5, 6) for outcome in OUTCOMES)}
    if not required.issubset(rows[0]):
        raise RuntimeError("race cache is missing a required decile/outcome column")

    output: list[dict[str, object]] = []
    by_split: dict[str, list[dict[str, object]]] = {}
    for split in SPLITS:
        split_rows = [row for row in rows if row["split"] == split]
        if len(split_rows) != EXPECTED_N[split]:
            raise RuntimeError(f"unexpected {split} N")
        decile_rows: list[dict[str, object]] = []
        for decile in range(1, 11):
            group = [row for row in split_rows if int(row["success_decile"]) == decile]
            if not group:
                raise RuntimeError(f"empty {split} D{decile}")
            item: dict[str, object] = {"split": split, "decile": decile, "N": len(group)}
            for course in (5, 6):
                for outcome in OUTCOMES:
                    count = sum(int(row[f"course{course}_{outcome}"]) for row in group)
                    item[f"course{course}_{outcome}_count"] = count
                    item[f"course{course}_{outcome}_rate_pct"] = 100.0 * count / len(group)
            decile_rows.append(item); output.append(item)
        if sum(int(item["N"]) for item in decile_rows) != EXPECTED_N[split]:
            raise RuntimeError(f"{split} decile N does not sum to split N")
        by_split[split] = decile_rows

    fields = ["split", "decile", "N", *(f"course{course}_{outcome}_{suffix}" for course in (5, 6) for outcome in OUTCOMES for suffix in ("count", "rate_pct"))]
    with CSV_OUT.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fields); writer.writeheader(); writer.writerows(output)

    bootstrap = json.loads(BOOTSTRAP.read_text(encoding="utf-8"))
    comparison: dict[str, dict[str, object]] = {}
    lifts: dict[str, dict[str, float]] = {}
    trends: dict[str, dict[str, float]] = {}
    for split, values in by_split.items():
        low, high = values[0], values[-1]
        lifts[split] = {}
        trends[split] = {}
        comparison[split] = {}
        for outcome in ("second", "third", "top3"):
            five = float(high[f"course5_{outcome}_rate_pct"]) - float(low[f"course5_{outcome}_rate_pct"])
            six = float(high[f"course6_{outcome}_rate_pct"]) - float(low[f"course6_{outcome}_rate_pct"])
            gap = five - six
            lifts[split][f"five_{outcome}_lift_d10_minus_d1_pct_point"] = five
            lifts[split][f"six_{outcome}_lift_d10_minus_d1_pct_point"] = six
            lifts[split][f"follower_gap_{outcome}_d10_minus_d1_pct_point"] = gap
            for course in (5, 6):
                rates = [float(item[f"course{course}_{outcome}_rate_pct"]) for item in values]
                trends[split][f"course{course}_{outcome}_spearman_decile_vs_rate"] = spearman(list(range(1, 11)), rates)
            band_value = float(bootstrap["splits"][split]["metrics"][f"follower_gap_{outcome}"]["point_estimate_pct_point"])
            comparison[split][outcome] = {
                "bootstrap_high_minus_low_follower_gap_pct_point": band_value,
                "decile_d10_minus_d1_follower_gap_pct_point": gap,
                "direction_consistent": direction(band_value) == direction(gap),
                "bootstrap_direction": direction(band_value),
                "decile_direction": direction(gap),
            }

    report = {
        "race_cache_path": "analysis/output/course4_attack_success_phase6_race_cache_20251001_20260930.csv",
        "race_cache_sha256": RACE_SHA256,
        "race_cache_rows": len(rows),
        "duplicate_race_code": 0,
        "split_n": {split: len([row for row in rows if row["split"] == split]) for split in SPLITS},
        "decile_n": {split: [int(item["N"]) for item in values] for split, values in by_split.items()},
        "d10_minus_d1_lifts_pct_point": lifts,
        "spearman_decile_vs_outcome_rate": trends,
        "bootstrap_1000_direction_comparison": comparison,
        "source_bootstrap_result": "analysis/output/course4_attack_success_phase6_bootstrap_1000.json",
        "source_script": "analysis/analyze_course4_attack_success_phase6_deciles.py",
        "source_script_sha256": sha256(Path(__file__).resolve()),
        "source_git_commit": subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT.parent, text=True).strip(),
        "generated_at": datetime.datetime.now().astimezone().isoformat(),
    }
    REPORT_OUT.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(report, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
