#!/usr/bin/env python3
"""Save immutable post-exhibition AI win-rate forward predictions."""

from __future__ import annotations

import argparse
import csv
import fcntl
import hashlib
import json
import os
import subprocess
import sys
import tempfile
from datetime import date, datetime
from pathlib import Path
from zoneinfo import ZoneInfo

import numpy as np
import pandas as pd
from autogluon.tabular import TabularPredictor

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "analysis"))

from ai_winrate_forward_features import PLACES, build_forward_feature_rows

JST = ZoneInfo("Asia/Tokyo")
FORMAL_ROOT = ROOT / "analysis" / "output" / "ai_winrate_forward"
START = date(2026, 10, 1)
FIELDS = (
    "date", "race_code", "place", "race_number", "scheduled_deadline", "prediction_created_at",
    "boat_number", "player_id", "entry_course", "v6_probability", "autogluon_probability",
    "ebm_probability", "blend_autogluon25_probability", "blend_ebm05_probability",
    "model_version", "logic_version",
)


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def load_setup() -> tuple[dict, dict, Path]:
    config = json.loads((ROOT / "analysis" / "ai_winrate_forward_config.json").read_text(encoding="utf-8"))
    manifest_path = ROOT / config["manifest"]
    if sha256(manifest_path) != config["manifest_sha256"]:
        raise RuntimeError("forward manifest SHA256が設定と一致しません")
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    if manifest["training_cutoff"] != "2026-09-27" or manifest["forward_start"] != "2026-10-01":
        raise RuntimeError("forward固定期間が一致しません")
    v6_paths = [ROOT / "forecast" / "models" / name for name in ("ai_winrate_v2.joblib", "ai_winrate_v4.joblib", "ai_winrate_v6.joblib")]
    logic_material = config["manifest_sha256"] + "".join(sha256(path) for path in v6_paths)
    config["logic_version"] = hashlib.sha256(logic_material.encode()).hexdigest()
    return config, manifest, manifest_path.parent


def deadlines(target: date) -> dict[str, dict[str, str]]:
    path = Path("/tmp/boatrace_official_deadlines") / f"deadlines_{target:%Y%m%d}.json"
    if not path.is_file():
        raise RuntimeError(f"公式締切キャッシュがありません: {path}")
    payload = json.loads(path.read_text(encoding="utf-8"))
    return {place: data.get("deadlines", {}) for place, data in payload.get("places", {}).items()}


def v6_probabilities(race_code: str) -> dict[int, float]:
    command = [str(ROOT / ".venv-models" / "bin" / "python"), str(ROOT / "forecast" / "ai_winrate_live_v6.py"), race_code]
    result = subprocess.run(command, cwd=ROOT, text=True, capture_output=True, check=False, timeout=120)
    if result.returncode != 0:
        raise RuntimeError(f"v6 waiting/error {race_code}: {result.stdout.strip()} {result.stderr.strip()}")
    payload = json.loads(result.stdout)
    if payload.get("status") != "ok" or len(payload.get("boats", {})) != 6:
        raise RuntimeError(f"v6未完成 {race_code}: {payload}")
    values = {int(boat): float(item["ai_rate"]) / 100.0 for boat, item in payload["boats"].items()}
    total = sum(values.values())
    return {boat: value / total for boat, value in values.items()}


def autogluon_probabilities(races: dict[str, list[dict]], artifact_root: Path, features: list[str]) -> dict[str, list[float]]:
    predictors: dict[tuple[str, int], TabularPredictor] = {}
    output = {}
    for race_code, rows in races.items():
        raw = []
        for row in rows:
            key = (row["place"], int(row["entry_course"]))
            if key not in predictors:
                predictors[key] = TabularPredictor.load(str(artifact_root / "autogluon" / f"{key[0]}_{key[1]}C"), verbosity=0)
            frame = pd.DataFrame([row["features"]], columns=features)
            proba = predictors[key].predict_proba(frame, as_pandas=False)
            raw.append(float(proba[0, 1] if proba.ndim == 2 else proba[0]))
        total = sum(max(value, 1e-12) for value in raw)
        output[race_code] = [max(value, 1e-12) / total for value in raw]
    return output


def ebm_probabilities(races: dict[str, list[dict]], artifact_root: Path) -> dict[str, list[float]]:
    with tempfile.TemporaryDirectory(prefix="ai-winrate-forward-") as tmp:
        input_path, output_path = Path(tmp) / "input.json", Path(tmp) / "output.json"
        input_path.write_text(json.dumps({"races": races}, ensure_ascii=False, allow_nan=True), encoding="utf-8")
        command = ["/tmp/boatrace-formal-compare-sklearn14/bin/python", str(ROOT / "analysis" / "predict_ai_winrate_forward_ebm.py"), "--model", str(artifact_root / "ebm_models.joblib"), "--input", str(input_path), "--output", str(output_path)]
        subprocess.run(command, cwd=ROOT, check=True, timeout=300)
        return json.loads(output_path.read_text(encoding="utf-8"))["predictions"]


def read_existing(path: Path) -> list[dict]:
    if not path.is_file():
        return []
    with path.open(encoding="utf-8", newline="") as handle:
        return list(csv.DictReader(handle))


def atomic_write_csv(path: Path, rows: list[dict]) -> None:
    temporary = path.with_suffix(".tmp")
    with temporary.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=FIELDS, lineterminator="\n")
        writer.writeheader(); writer.writerows(rows)
        handle.flush(); os.fsync(handle.fileno())
    os.replace(temporary, path)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--date", default=datetime.now(JST).date().isoformat())
    parser.add_argument("--output-root", default=str(FORMAL_ROOT))
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--ignore-deadline", action="store_true")
    args = parser.parse_args()
    target = date.fromisoformat(args.date)
    output_root = Path(args.output_root).resolve()
    today = datetime.now(JST).date()
    if not args.dry_run and (target < START or target != today):
        raise RuntimeError("正式forwardは2026-10-01以降の当日分のみ保存できます")
    if args.dry_run and output_root == FORMAL_ROOT.resolve():
        raise RuntimeError("dry-runは正式forwardディレクトリへ保存できません")
    if args.ignore_deadline and not args.dry_run:
        raise RuntimeError("--ignore-deadlineはdry-run専用です")

    config, manifest, artifact_root = load_setup()
    deadline_map = deadlines(target)
    all_races: dict[str, list[dict]] = {}
    for place in PLACES:
        all_races.update(build_forward_feature_rows(target, place, manifest["feature_names"]))
    now = datetime.now(JST)
    eligible = {}
    scheduled = {}
    for code, rows in all_races.items():
        place, race_number = rows[0]["place"], str(rows[0]["race_number"])
        value = deadline_map.get(place, {}).get(race_number)
        if not value:
            continue
        deadline = datetime.fromisoformat(f"{target.isoformat()}T{value}:00").replace(tzinfo=JST)
        if args.ignore_deadline or now < deadline:
            eligible[code], scheduled[code] = rows, deadline.isoformat(timespec="minutes")
    if not eligible:
        print(json.dumps({"status": "waiting", "date": target.isoformat(), "reason": "no post-exhibition pre-deadline races"}, ensure_ascii=False))
        return 0

    day_dir = output_root / f"{target:%Y%m%d}"; day_dir.mkdir(parents=True, exist_ok=True)
    prediction_path, lock_path = day_dir / "prediction.csv", day_dir / ".prediction.lock"
    preexisting_codes = {row["race_code"] for row in read_existing(prediction_path)}
    eligible_count = len(eligible)
    eligible = {code: rows for code, rows in eligible.items() if code not in preexisting_codes}
    if not eligible:
        print(json.dumps({"status": "ok", "date": target.isoformat(), "eligible_races": eligible_count, "saved_new_races": 0, "saved_new_rows": 0, "skipped_existing_races": len(preexisting_codes), "prediction": str(prediction_path)}, ensure_ascii=False))
        return 0

    ag = autogluon_probabilities(eligible, artifact_root, manifest["feature_names"])
    ebm = ebm_probabilities(eligible, artifact_root)
    created = datetime.now(JST).isoformat(timespec="seconds")
    new_rows = []
    for code, race_rows in eligible.items():
        v6 = v6_probabilities(code)
        for index, row in enumerate(race_rows):
            boat = int(row["boat_number"])
            p_v6, p_ag, p_ebm = v6[boat], float(ag[code][index]), float(ebm[code][index])
            new_rows.append({
                "date": target.isoformat(), "race_code": code, "place": row["place"], "race_number": row["race_number"],
                "scheduled_deadline": scheduled[code], "prediction_created_at": created, "boat_number": boat,
                "player_id": row["player_id"], "entry_course": row["entry_course"],
                "v6_probability": f"{p_v6:.12f}", "autogluon_probability": f"{p_ag:.12f}", "ebm_probability": f"{p_ebm:.12f}",
                "blend_autogluon25_probability": f"{0.75*p_v6+0.25*p_ag:.12f}",
                "blend_ebm05_probability": f"{0.95*p_v6+0.05*p_ebm:.12f}",
                "model_version": manifest["version"], "logic_version": config["logic_version"],
            })

    with lock_path.open("a+") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        existing = read_existing(prediction_path)
        saved_codes = {row["race_code"] for row in existing}
        append = [row for row in new_rows if row["race_code"] not in saved_codes]
        if append:
            atomic_write_csv(prediction_path, existing + append)
        metadata_path = day_dir / "metadata.json"
        if not metadata_path.exists():
            metadata_path.write_text(json.dumps({
                "date": target.isoformat(), "forward_start": "2026-10-01", "training_cutoff": "2026-09-27",
                "created_at": created, "model_version": manifest["version"], "logic_version": config["logic_version"],
                "manifest": config["manifest"], "manifest_sha256": config["manifest_sha256"],
                "immutable_policy": "A race_code is written once; later executions never replace its six rows.",
                "target_outcome_access": "none; target race result/payout tables are not queried by this saver",
            }, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"status": "ok", "date": target.isoformat(), "eligible_races": eligible_count, "saved_new_races": len({row["race_code"] for row in append}), "saved_new_rows": len(append), "skipped_existing_races": len(preexisting_codes | ({r["race_code"] for r in new_rows} & saved_codes)), "prediction": str(prediction_path)}, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
