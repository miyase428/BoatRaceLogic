#!/usr/bin/env python3
"""Build Phase 6A-3 cluster aggregates from the frozen race cache only."""
from __future__ import annotations

import csv
import hashlib
import json
import time
from collections import defaultdict
from pathlib import Path


ROOT = Path(__file__).resolve().parent
OUT = ROOT / "output"
RACE_CACHE = OUT / "course4_attack_success_phase6_race_cache_20251001_20260930.csv"
RACE_CACHE_SHA256 = "9275720d6eb56f29bf216cf0daca71cc10a6db3c052e875df8d473f349a8a778"
CLUSTER_CACHE = OUT / "course4_attack_success_phase6_cluster_cache_20251001_20260930.csv"
BANDS = ("0-19", "20-39", "40-59", "60-79", "80-100")
SUM_COLUMNS = (
    "five_first_count", "five_second_count", "five_third_count", "five_top2_count", "five_top3_count",
    "six_first_count", "six_second_count", "six_third_count", "six_top2_count", "six_top3_count",
    "pair_second_sum", "pair_third_sum", "pair_top3_sum",
)
COLUMNS = ("cluster_key", "split", "stadium", "race_date", "success_band", "N", *SUM_COLUMNS)


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def read_csv(path: Path) -> list[dict[str, str]]:
    with path.open(encoding="utf-8", newline="") as handle:
        return list(csv.DictReader(handle))


def as_int(value: str) -> int:
    return int(value)


def aggregate(rows: list[dict[str, str]]) -> dict[tuple[str, str, str, str], dict[str, int | str]]:
    groups: dict[tuple[str, str, str, str], dict[str, int | str]] = {}
    for row in rows:
        key = (row["split"], row["stadium"], row["race_date"], row["success_band"])
        if key not in groups:
            split, stadium, race_date, success_band = key
            groups[key] = {
                "cluster_key": f"{stadium}|{race_date}", "split": split, "stadium": stadium,
                "race_date": race_date, "success_band": success_band, "N": 0,
                **{column: 0 for column in SUM_COLUMNS},
            }
        item = groups[key]
        item["N"] = int(item["N"]) + 1
        for course, source in (("five", "course5"), ("six", "course6")):
            for outcome in ("first", "second", "third", "top2", "top3"):
                column = f"{course}_{outcome}_count"
                item[column] = int(item[column]) + as_int(row[f"{source}_{outcome}"])
        for outcome in ("second", "third", "top3"):
            column = f"pair_{outcome}_sum"
            item[column] = int(item[column]) + as_int(row[f"pair_{outcome}"])
    return groups


def totals(rows: list[dict[str, str]]) -> dict[str, dict[str, int]]:
    result = {band: {"N": 0, **{column: 0 for column in SUM_COLUMNS}} for band in BANDS}
    for row in rows:
        target = result[row["success_band"]]
        target["N"] += 1
        for course, source in (("five", "course5"), ("six", "course6")):
            for outcome in ("first", "second", "third", "top2", "top3"):
                target[f"{course}_{outcome}_count"] += as_int(row[f"{source}_{outcome}"])
        for outcome in ("second", "third", "top3"):
            target[f"pair_{outcome}_sum"] += as_int(row[f"pair_{outcome}"])
    return result


def cluster_totals(rows: list[dict[str, str]]) -> dict[str, dict[str, int]]:
    result = {band: {"N": 0, **{column: 0 for column in SUM_COLUMNS}} for band in BANDS}
    for row in rows:
        target = result[row["success_band"]]
        target["N"] += as_int(row["N"])
        for column in SUM_COLUMNS:
            target[column] += as_int(row[column])
    return result


def main() -> None:
    started = time.perf_counter()
    if sha256(RACE_CACHE) != RACE_CACHE_SHA256:
        raise RuntimeError("race cache SHA256 differs from the fixed Phase 6A-1c input")
    race_rows = read_csv(RACE_CACHE)
    required = {"split", "stadium", "race_date", "success_band", "course5_first", "course5_second", "course5_third", "course5_top2", "course5_top3", "course6_first", "course6_second", "course6_third", "course6_top2", "course6_top3", "pair_second", "pair_third", "pair_top3"}
    if not race_rows or not required.issubset(race_rows[0]):
        raise RuntimeError("race cache does not contain the required Phase 6 columns")
    if any(not row[key] for row in race_rows for key in ("split", "stadium", "race_date", "success_band")):
        raise RuntimeError("race cache has a missing cluster key component")
    if {row["success_band"] for row in race_rows} - set(BANDS):
        raise RuntimeError("race cache contains an unexpected success band")

    raw_totals = totals(race_rows)
    groups = aggregate(race_rows)
    cluster_rows = [groups[key] for key in sorted(groups)]
    with CLUSTER_CACHE.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=COLUMNS, extrasaction="raise")
        writer.writeheader(); writer.writerows(cluster_rows)

    saved = read_csv(CLUSTER_CACHE)
    keys = [(row["split"], row["stadium"], row["race_date"], row["success_band"]) for row in saved]
    duplicate = len(keys) - len(set(keys))
    missing = {key: sum(not row[key] for row in saved) for key in ("cluster_key", "stadium", "race_date", "success_band")}
    saved_totals = cluster_totals(saved)
    consistency = raw_totals == saved_totals and sum(as_int(row["N"]) for row in saved) == len(race_rows)
    split_by_venue_date: dict[str, set[tuple[str, str]]] = defaultdict(set)
    split_per_venue_date: dict[tuple[str, str], set[str]] = defaultdict(set)
    for row in saved:
        venue_date = (row["stadium"], row["race_date"])
        split_by_venue_date[row["split"]].add(venue_date)
        split_per_venue_date[venue_date].add(row["split"])
    mixed = sum(len(splits) > 1 for splits in split_per_venue_date.values())
    if duplicate or any(missing.values()) or not consistency or mixed:
        raise RuntimeError("cluster cache aggregate audit failed")
    audit = {
        "cluster_cache_path": str(CLUSTER_CACHE.resolve()),
        "cluster_cache_rows": len(saved),
        "unique_venue_date_clusters": len(split_per_venue_date),
        "split_cluster_counts": {split: len(values) for split, values in sorted(split_by_venue_date.items())},
        "mixed_split_venue_date_clusters": mixed,
        "success_band_n": {band: saved_totals[band]["N"] for band in BANDS},
        "total_n": sum(as_int(row["N"]) for row in saved),
        "duplicate_key": duplicate,
        "required_key_missing": missing,
        "aggregate_consistency": "PASS",
        "race_cache_sha256_verified": RACE_CACHE_SHA256,
        "cluster_cache_sha256": sha256(CLUSTER_CACHE),
        "total_seconds": time.perf_counter() - started,
    }
    print(json.dumps(audit, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
