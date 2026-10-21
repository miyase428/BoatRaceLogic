#!/usr/bin/env python3
"""Read-only Phase 6A-4 reuse audit for frozen race and cluster caches."""
from __future__ import annotations

import csv
import datetime
import hashlib
import json
import subprocess
import time
from pathlib import Path


ROOT = Path(__file__).resolve().parent
OUT = ROOT / "output"
RACE = OUT / "course4_attack_success_phase6_race_cache_20251001_20260930.csv"
RACE_META = OUT / "course4_attack_success_phase6_race_cache_meta.json"
CLUSTER = OUT / "course4_attack_success_phase6_cluster_cache_20251001_20260930.csv"
CLUSTER_META = OUT / "course4_attack_success_phase6_cluster_cache_meta.json"
CLUSTER_BUILDER = ROOT / "build_course4_attack_success_phase6_cluster_cache.py"
RACE_HASH = "9275720d6eb56f29bf216cf0daca71cc10a6db3c052e875df8d473f349a8a778"
CLUSTER_HASH = "4dfebd476498bb0ac536f9a6747701853cf2935579afb648388c572613e56894"


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def read_csv(path: Path) -> list[dict[str, str]]:
    with path.open(encoding="utf-8", newline="") as handle:
        return list(csv.DictReader(handle))


def check(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


def create_cluster_meta_if_absent(race_rows: list[dict[str, str]], cluster_rows: list[dict[str, str]]) -> bool:
    if CLUSTER_META.exists():
        return False
    venue_dates = {(row["stadium"], row["race_date"]) for row in cluster_rows}
    split_venue_dates = {
        split: len({(row["stadium"], row["race_date"]) for row in cluster_rows if row["split"] == split})
        for split in ("Validation", "EXPOSED_CONFIRMATION")
    }
    metadata = {
        "cluster_cache_path": "analysis/output/course4_attack_success_phase6_cluster_cache_20251001_20260930.csv",
        "cluster_cache_sha256": sha256(CLUSTER),
        "cluster_cache_row_count": len(cluster_rows),
        "unique_venue_date_count": len(venue_dates),
        "duplicate_key_count": len(cluster_rows) - len({(row["split"], row["stadium"], row["race_date"], row["success_band"]) for row in cluster_rows}),
        "input_race_cache_path": "analysis/output/course4_attack_success_phase6_race_cache_20251001_20260930.csv",
        "input_race_cache_sha256": sha256(RACE),
        "period_start": min(row["race_date"] for row in race_rows),
        "period_end": max(row["race_date"] for row in race_rows),
        "cluster_definition": "split × stadium × race_date × success_band",
        "validation_venue_date_count": split_venue_dates["Validation"],
        "exposed_confirmation_venue_date_count": split_venue_dates["EXPOSED_CONFIRMATION"],
        "cluster_build_script": {
            "path": "analysis/build_course4_attack_success_phase6_cluster_cache.py",
            "sha256": sha256(CLUSTER_BUILDER),
        },
        "source_git_commit": subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT.parent, text=True).strip(),
        "generated_at": datetime.datetime.now().astimezone().isoformat(),
    }
    CLUSTER_META.write_text(json.dumps(metadata, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    return True


def audit() -> dict[str, object]:
    started = time.perf_counter()
    check(RACE.exists() and RACE_META.exists() and CLUSTER.exists(), "required cache or race metadata is missing")
    before_race, before_cluster = sha256(RACE), sha256(CLUSTER)
    check(before_race == RACE_HASH, "race cache SHA256 mismatch")
    check(before_cluster == CLUSTER_HASH, "cluster cache SHA256 mismatch")
    race_rows, cluster_rows = read_csv(RACE), read_csv(CLUSTER)
    created = create_cluster_meta_if_absent(race_rows, cluster_rows)
    check(CLUSTER_META.exists(), "cluster metadata was not created")
    race_meta = json.loads(RACE_META.read_text(encoding="utf-8"))
    cluster_meta = json.loads(CLUSTER_META.read_text(encoding="utf-8"))

    race_keys = [row["race_code"] for row in race_rows]
    race_duplicate = len(race_keys) - len(set(race_keys))
    split_counts = {split: sum(row["split"] == split for row in race_rows) for split in ("Validation", "EXPOSED_CONFIRMATION")}
    check(len(race_rows) == 42717 and race_duplicate == 0 and split_counts == {"Validation": 20595, "EXPOSED_CONFIRMATION": 22122}, "race cache row/key/split audit mismatch")
    check(race_meta["cache_sha256"] == before_race and race_meta["cache_row_count"] == 42717 and race_meta["duplicate_race_code"] == 0, "race metadata mismatch")

    cluster_keys = [(row["split"], row["stadium"], row["race_date"], row["success_band"]) for row in cluster_rows]
    cluster_duplicate = len(cluster_keys) - len(set(cluster_keys))
    total_n = sum(int(row["N"]) for row in cluster_rows)
    check(len(cluster_rows) == 19125 and cluster_duplicate == 0 and total_n == 42717, "cluster cache row/key/N audit mismatch")
    check(cluster_meta["cluster_cache_sha256"] == before_cluster and cluster_meta["input_race_cache_sha256"] == before_race, "cluster metadata hash lineage mismatch")
    check(cluster_meta["cluster_cache_row_count"] == 19125 and cluster_meta["duplicate_key_count"] == 0 and cluster_meta["unique_venue_date_count"] == 4514, "cluster metadata count audit mismatch")

    after_race, after_cluster = sha256(RACE), sha256(CLUSTER)
    check(before_race == after_race and before_cluster == after_cluster, "cache changed during read-only reuse audit")
    return {
        "cluster_metadata_created": created,
        "RACE_CACHE_REUSE": "PASS",
        "CLUSTER_CACHE_REUSE": "PASS",
        "log": ["RACE CACHE: REUSED", "CLUSTER CACHE: REUSED"],
        "db_access": "none",
        "phase5_input_reconstruction": "skipped",
        "wall_r_inference": "skipped",
        "a5_inference": "skipped",
        "attack_success_inference": "skipped",
        "race_cache_regeneration": "skipped",
        "cluster_cache_regeneration": "skipped",
        "race_cache_sha256_before_after": [before_race, after_race],
        "cluster_cache_sha256_before_after": [before_cluster, after_cluster],
        "total_seconds": time.perf_counter() - started,
    }


if __name__ == "__main__":
    print(json.dumps(audit(), ensure_ascii=False, indent=2))
