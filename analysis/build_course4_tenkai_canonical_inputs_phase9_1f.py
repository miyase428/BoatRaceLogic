#!/usr/bin/env python3
"""Build immutable research baselines for WALL-R and A5 without DB access."""
from __future__ import annotations

import csv
import json
import subprocess
import sys
from datetime import datetime
from pathlib import Path

import numpy as np

ROOT = Path(__file__).resolve().parent
REPO = ROOT.parent
OUT = ROOT / "output"
sys.path.insert(0, str(ROOT))
from course4_tenkai_canonical_input import CANONICAL_VERSION, canonical_json, sha256, term_for  # noqa: E402

WALL_CSV = OUT / "course34_wall_canonical_input_v1.csv"
WALL_MANIFEST = OUT / "course34_wall_canonical_input_v1_manifest.json"
A5_CSV = OUT / "course4_attack_a5_canonical_input_v1.csv"
A5_MANIFEST = OUT / "course4_attack_a5_canonical_input_v1_manifest.json"


def clean(value):
    try:
        value = float(value)
        return value if np.isfinite(value) else ""
    except (TypeError, ValueError):
        return ""


def write_once(path: Path, text: str):
    if path.exists():
        if path.read_text(encoding="utf-8") != text:
            raise RuntimeError(f"immutable canonical artifact already exists with different content: {path}")
        return False
    path.write_text(text, encoding="utf-8")
    return True


def write_manifest_once(path: Path, data: dict):
    text = json.dumps(data, ensure_ascii=False, indent=2, sort_keys=True) + "\n"
    if not path.exists():
        path.write_text(text, encoding="utf-8")
        return True
    prior, current = json.loads(path.read_text(encoding="utf-8")), dict(data)
    prior.pop("generated_at", None); current.pop("generated_at", None)
    if prior != current:
        raise RuntimeError(f"immutable canonical manifest already exists with different content: {path}")
    return False


def csv_text(rows):
    fields = list(rows[0])
    from io import StringIO
    out = StringIO()
    writer = csv.DictWriter(out, fieldnames=fields, lineterminator="\n")
    writer.writeheader(); writer.writerows(rows)
    return out.getvalue()


def git_head():
    return subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=REPO, text=True).strip()


def main():
    import analyze_course34_wall_phase3r as wall
    import analyze_course4_attack_phase4r as a5

    wall_artifact = json.loads((OUT / "course34_wall_phase3r_final_model.json").read_text())
    a5_artifact = json.loads((OUT / "course4_attack_phase4r_final_model.json").read_text())
    wall_raw, overall, wall_paths = wall.source_rows()
    wall_rows = wall.add_features(wall_raw, overall, float(wall_artifact["st_smoothing_k"]))
    wall_features = list(wall_artifact["feature_names"])
    wall_out = []
    for raw, derived in zip(wall_raw, wall_rows):
        date_text = raw["race_date"]
        row = {
            "race_code": raw["race_code"], "race_date": date_text, "term_info": term_for(__import__("datetime").date.fromisoformat(date_text)),
            "course3_player_id": raw["course3_player_id"], "course4_player_id": raw["course4_player_id"],
            "source_kind": "frozen_research", "baseline_frozen": "true", "canonical_version": CANONICAL_VERSION,
        }
        for course in (3, 4):
            row[f"raw_course{course}_overall_average_st"] = clean(overall.get((row["term_info"], raw[f"course{course}_player_id"])))
            for name in ("average_st", "entry_count", "average_st_rank", "ex_st", "exhibition_time", "lap_time", "around_time", "straight_time"):
                row[f"raw_course{course}_{name}"] = clean(raw.get(f"course{course}_{name}"))
        for feature in wall_features:
            row[f"feature_{feature}"] = clean(derived.get(feature))
        wall_out.append(row)
    wall_out.sort(key=lambda row: row["race_code"])
    wall_text = csv_text(wall_out)
    write_once(WALL_CSV, wall_text)

    a5_rows, a5_paths = a5.source_rows()
    a5_features = list(a5_artifact["feature_names"])
    a5_out = []
    for source in a5_rows:
        date_text = source["race_date"]
        row = {
            "race_code": source["race_code"], "race_date": date_text, "term_info": term_for(__import__("datetime").date.fromisoformat(date_text)),
            "course4_player_id": source["course4_player_id"], "source_kind": "frozen_research",
            "baseline_frozen": "true", "canonical_version": CANONICAL_VERSION,
        }
        for name in ("course4_average_st", "course4_average_st_rank", "course4_entry_count", "overall_average_st",
                     "course4_ex_st", "course4_exhibition_time", "course4_lap_time", "course4_around_time", "course4_straight_time",
                     "motor_2ren_rate", "boat_2ren_rate", "national_win_rate", "local_win_rate", "national_2ren_rate", "local_2ren_rate"):
            row[f"raw_{name}"] = clean(source.get(name))
        for feature in a5_features:
            row[f"expected_{feature}"] = clean(source.get(feature))
        a5_out.append(row)
    a5_out.sort(key=lambda row: row["race_code"])
    a5_text = csv_text(a5_out)
    write_once(A5_CSV, a5_text)

    def manifest(signal, artifact, baseline, paths, feature_names, semantic_notes, snapshot_fields):
        data = {
            "canonical_version": CANONICAL_VERSION, "signal_id": signal,
            "artifact_path": str((OUT / artifact).relative_to(REPO)), "artifact_sha256": sha256(OUT / artifact),
            "baseline_path": str(baseline.relative_to(REPO)), "baseline_sha256": sha256(baseline),
            "baseline_row_count": 86504, "date_coverage": {"min": "2024-10-01", "max": "2026-09-30"},
            "feature_order": feature_names,
            "baseline_source_files": [{"path": str(path.relative_to(REPO)), "sha256": sha256(path)} for path in paths],
            "baseline_source_aggregate_sha256": wall.sha256_paths(paths) if signal == "WALL_R" else a5.paths_hash(paths),
            "feature_semantic_notes": semantic_notes,
            "immutability_policy": "baseline is generated once from fixed research sources; an existing file must match byte-for-byte and is never overwritten",
            "prospective_capture_policy": {
                "store": "var/course4_tenkai_input_snapshots/static_v1/<race_code>.json", "write": "O_EXCL first-write only",
                "source": "race_master + race_entry + racer_results(term derived from target race_date) + player_stats + engine_specs; no result table",
                "timing": "explicit timezone-aware cutoff strictly before official deadline", "snapshot_fields": snapshot_fields,
                "term_mapping": "Jan-Apr -> previous Oct; May-Oct -> current Apr; Nov-Dec -> current Oct",
            },
            "generated_at": datetime.now().astimezone().isoformat(), "source_git_commit": git_head(),
        }
        return data

    wall_manifest = manifest("WALL_R", "course34_wall_phase3r_final_model.json", WALL_CSV, wall_paths, wall_features,
                             {"avg_st_rank_delta_34": "source field is average finishing rank, not ST rank"},
                             ["C3/C4 overall/course average ST", "entry count", "average finishing rank", "exhibition fields"])
    a5_manifest = manifest("ATTACK_A5", "course4_attack_phase4r_final_model.json", A5_CSV, a5_paths, a5_features,
                           {"course4_average_st_rank": "source field is course average finishing rank, not ST rank"},
                           ["C4 course/overall ST", "entry count", "average finishing rank", "player rates", "motor/boat rates"])
    for path, data in ((WALL_MANIFEST, wall_manifest), (A5_MANIFEST, a5_manifest)):
        write_manifest_once(path, data)
    print(json.dumps({"wall_baseline": str(WALL_CSV.relative_to(REPO)), "a5_baseline": str(A5_CSV.relative_to(REPO)),
                      "wall_sha256": sha256(WALL_CSV), "a5_sha256": sha256(A5_CSV)}, ensure_ascii=False))


if __name__ == "__main__":
    main()
