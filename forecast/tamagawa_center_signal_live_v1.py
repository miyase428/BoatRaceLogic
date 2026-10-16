#!/usr/bin/env python3
"""場別コースサイン v1 を指定日の出走表へ適用する。"""

from __future__ import annotations

import argparse
import hashlib
import json
import math
import sys
from collections import defaultdict
from datetime import datetime
from pathlib import Path

import joblib
import numpy as np

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "analysis"))

from analyze_all_venue_lane_signals import profile_rates  # noqa: E402
from analyze_all_venue_secondary_signals import load_avg_exhibition, load_exhibition  # noqa: E402
from analyze_tamagawa_boaters_hypothesis import (  # noqa: E402
    TechniqueHistoryIndex,
    load_history,
    load_racer_results,
    term_info_for_date,
)
from analyze_tamagawa_lane4_makurizashi_vulnerability import (  # noqa: E402
    VulnerabilityIndex,
    load_lane1_vulnerability_history,
)
from analyze_tamagawa_lane4_strong_condition_second_eval import build_second_scores  # noqa: E402
from audit_tamagawa_course_signals_zero_base_ml import (  # noqa: E402
    PROFILE_KEYS,
    RAW_EXHIBITION_KEYS,
    SECOND_KEYS,
    relative_values,
    safe_float,
)
from slit_validate_v2 import connect_db  # noqa: E402


PLACE = "TMG"
MODEL_PATHS = {
    "AMG": ROOT / "forecast" / "models" / "amagasaki_course_signal_v1.joblib",
    "ASY": ROOT / "forecast" / "models" / "ashiya_course_signal_v1.joblib",
    "BWK": ROOT / "forecast" / "models" / "biwako_course_signal_v1.joblib",
    "EDG": ROOT / "forecast" / "models" / "edogawa_course_signal_v1.joblib",
    "HWJ": ROOT / "forecast" / "models" / "heiwajima_course_signal_v1.joblib",
    "MKN": ROOT / "forecast" / "models" / "mikuni_course_signal_v1.joblib",
    "KRY": ROOT / "forecast" / "models" / "kiryuu_course_signal_v1.joblib",
    "TMG": ROOT / "forecast" / "models" / "tamagawa_center_signal_v1.joblib",
    "TDA": ROOT / "forecast" / "models" / "toda_course_signal_v1.joblib",
    "OMR": ROOT / "forecast" / "models" / "omura_course_signal_v1.joblib",
    "SMS": ROOT / "forecast" / "models" / "shimonoseki_course_signal_v1.joblib",
    "SME": ROOT / "forecast" / "models" / "suminoe_course_signal_v1.joblib",
}


def live_entry_sql() -> str:
    """Return the current-day, outcome-blind live input query.

    ``race_master`` is intentionally absent here.  It is a historical/result
    pipeline table and can be absent for an otherwise complete same-day entry.
    All fields used by the live feature builder are available from the tables
    below at the time of prediction.
    """
    return """
WITH target_entries AS (
    SELECT *
    FROM boat_race.race_entry re
    WHERE re.race_date = %s::date
      AND SUBSTRING(re.race_code, 9, 3) = %s
), latest_ex AS (
    SELECT DISTINCT ON (el.race_code, el.player_id)
        el.race_code, el.player_id, el.entry_course::integer AS entry_course
    FROM boat_race.exhibition_live el
    JOIN target_entries re
      ON re.race_code = el.race_code
     AND re.player_id = el.player_id
    ORDER BY el.race_code, el.player_id, el.created_date DESC NULLS LAST
)
SELECT
    re.race_code, re.player_id::text, re.lane_number::integer,
    COALESCE(le.entry_course, re.lane_number::integer) AS course,
    ps.national_win_rate, ps.national_exacta_rate,
    ps.local_win_rate, ps.local_exacta_rate,
    es.motor_exacta_rate, es.boat_exacta_rate,
    rr.average_start
FROM target_entries re
LEFT JOIN latest_ex le ON le.race_code = re.race_code AND le.player_id = re.player_id
LEFT JOIN boat_race.player_stats ps ON ps.race_code = re.race_code AND ps.player_id = re.player_id
LEFT JOIN boat_race.engine_specs es
  ON es.race_code = re.race_code
 AND es.motor_number = re.motor_number
 AND es.boat_number = re.boat_number
LEFT JOIN boat_race.racer_results rr
  ON rr.player_id = re.player_id
 AND rr.term_info = CASE
    WHEN EXTRACT(MONTH FROM re.race_date) <= 4
      THEN TO_CHAR(re.race_date - INTERVAL '1 year', 'YY') || '10'
    WHEN EXTRACT(MONTH FROM re.race_date) <= 10
      THEN TO_CHAR(re.race_date, 'YY') || '04'
    ELSE TO_CHAR(re.race_date, 'YY') || '10'
 END
ORDER BY re.race_code, course, re.lane_number
"""


def classify_live_input(races: dict[str, dict], target_entry_race_count: int) -> tuple[str, dict[str, str]]:
    """Classify only the live input shape; no outcome data is involved."""
    if target_entry_race_count <= 0:
        return "NO_APPLICABLE_RACE", {}
    if not races:
        return "INFERENCE_INPUT_EMPTY", {}

    failures: dict[str, str] = {}
    for race_code, race in races.items():
        boats = race.get("boats") if isinstance(race, dict) else None
        by_course: set[int] = set()
        for boat in boats or []:
            if not isinstance(boat, dict):
                continue
            try:
                course = int(boat.get("course") or 0)
            except (TypeError, ValueError):
                continue
            if 1 <= course <= 6:
                by_course.add(course)
        if not isinstance(boats, list) or len(boats) != 6 or by_course != set(range(1, 7)):
            failures[race_code] = "INFERENCE_INPUT_INCOMPLETE"
    if len(failures) == len(races):
        return "INFERENCE_INPUT_INCOMPLETE", failures
    return "OK", failures


def error_payload(code: str, detail: str = "") -> dict:
    payload = {"status": "error", "error": code, "input_status": code}
    if detail:
        payload["error_detail"] = detail
    return payload


def load_entries(target_date) -> tuple[dict[str, dict], int]:
    entry_count_sql = """
SELECT COUNT(DISTINCT re.race_code)
FROM boat_race.race_entry re
WHERE re.race_date = %s::date
  AND SUBSTRING(re.race_code, 9, 3) = %s
"""
    sql = live_entry_sql()
    races: dict[str, dict] = {}
    with connect_db() as conn:
        with conn.cursor() as cur:
            cur.execute(entry_count_sql, (target_date, PLACE))
            entry_race_count = int(cur.fetchone()[0] or 0)
            cur.execute(sql, (target_date, PLACE))
            names = [item.name for item in cur.description]
            for values in cur.fetchall():
                row = dict(zip(names, values))
                code = str(row["race_code"])
                row["player_id"] = str(row["player_id"]).strip()
                races.setdefault(code, {"date": target_date, "boats": []})["boats"].append(row)
    return races, entry_race_count


def feature_vector(shared: dict[str, float], names: list[str]) -> np.ndarray:
    return np.asarray([[shared.get(name, float("nan")) for name in names]], dtype=np.float64)


def snapshot_scalar(value):
    """Preserve the inference-time missing representation without JSON NaN."""
    if isinstance(value, (float, np.floating)):
        numeric = float(value)
        return numeric if math.isfinite(numeric) else {"missing": "nan"}
    if isinstance(value, (int, np.integer, str, bool)) or value is None:
        return value
    return str(value)


def snapshot_json(value):
    if isinstance(value, dict):
        return {str(key): snapshot_json(item) for key, item in value.items()}
    if isinstance(value, (list, tuple)):
        return [snapshot_json(item) for item in value]
    return snapshot_scalar(value)


def feature_schema_identity(names: list[str]) -> str:
    payload = {
        "schema_version": "strict-forward-ml-feature-schema/v1",
        "feature_names": list(names),
    }
    raw = json.dumps(payload, sort_keys=True, separators=(",", ":"), ensure_ascii=False)
    return "sha256:" + hashlib.sha256(raw.encode("utf-8")).hexdigest()


def historical_stats(model_item: dict) -> dict:
    stats = model_item.get("test_selection", {})
    return {
        "n": int(stats.get("n", 0)),
        "first_rate": None if stats.get("first_rate") is None else round(100.0 * float(stats["first_rate"]), 2),
        "top2_rate": None if stats.get("top2_rate") is None else round(100.0 * float(stats["top2_rate"]), 2),
        "top3_rate": None if stats.get("top3_rate") is None else round(100.0 * float(stats["top3_rate"]), 2),
        "period": stats.get("period"),
    }


def load_verified_artifact(model_path: Path) -> tuple[dict, str]:
    """manifestとjoblibが一致しない場合は、呼出元で安全にfallbackさせる。"""
    manifest_path = model_path.with_suffix(".json")
    if not manifest_path.is_file():
        raise ValueError("manifest_not_found")
    try:
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as error:
        raise ValueError("manifest_invalid") from error
    if not isinstance(manifest, dict) or not isinstance(manifest.get("version"), str):
        raise ValueError("manifest_invalid")
    digest = hashlib.sha256(model_path.read_bytes()).hexdigest()
    if not isinstance(manifest.get("sha256"), str) or manifest["sha256"] != digest:
        raise ValueError("manifest_sha256_mismatch")
    artifact = joblib.load(model_path)
    if not isinstance(artifact, dict) or artifact.get("version") != manifest["version"]:
        raise ValueError("artifact_manifest_version_mismatch")
    return artifact, digest


def main() -> int:
    global PLACE
    parser = argparse.ArgumentParser()
    parser.add_argument("date")
    parser.add_argument("--place", default="TMG", choices=sorted(MODEL_PATHS))
    parser.add_argument("--base", action="store_true", help="展示を使わない事前判定")
    parser.add_argument("--strict-forward-input", action="store_true", help="Strict Forward専用の実推論入力を内部出力する")
    args = parser.parse_args()
    PLACE = str(args.place)
    model_path = MODEL_PATHS[PLACE]
    target_date = datetime.strptime(args.date, "%Y-%m-%d").date()
    if not model_path.is_file():
        print(json.dumps(error_payload("ARTIFACT_ERROR", "model_not_found"), ensure_ascii=False))
        return 2
    try:
        artifact, artifact_sha256 = load_verified_artifact(model_path)
    except (OSError, ValueError, EOFError) as error:
        print(json.dumps(error_payload("ARTIFACT_ERROR", str(error)), ensure_ascii=False))
        return 2
    try:
        races, entry_race_count = load_entries(target_date)
    except Exception as error:
        print(json.dumps(error_payload("INFERENCE_INPUT_ERROR", type(error).__name__), ensure_ascii=False))
        return 3
    input_status, race_input_failures = classify_live_input(races, entry_race_count)
    if input_status == "NO_APPLICABLE_RACE":
        print(json.dumps({
            "status": "ok", "version": artifact["version"], "date": args.date,
            "input_status": input_status, "matches": {}, "readiness_decisions": {},
        }, ensure_ascii=False))
        return 0
    if input_status in {"INFERENCE_INPUT_EMPTY", "INFERENCE_INPUT_INCOMPLETE"}:
        print(json.dumps(error_payload(input_status), ensure_ascii=False))
        return 3

    player_ids = sorted({boat["player_id"] for race in races.values() for boat in race["boats"]})
    history = TechniqueHistoryIndex(load_history(target_date, target_date, player_ids))
    vulnerability = VulnerabilityIndex(
        load_lane1_vulnerability_history(target_date, target_date, player_ids)
    )
    racer = load_racer_results([term_info_for_date(target_date)])
    exhibition_by_race = {} if args.base else load_exhibition(target_date, target_date, PLACE)
    average_exhibition = None if args.base else load_avg_exhibition(PLACE)
    term = term_info_for_date(target_date)
    matches: dict[str, dict[str, dict]] = {str(course): {} for course in artifact["models"]}
    # This internal-only section exposes every raw ML target decision, including
    # false decisions.  The existing `matches` contract and display selection
    # remain unchanged; PHP uses this only for the fail-open readiness ledger.
    readiness_decisions: dict[str, dict[str, dict]] = {}
    readiness_inputs: dict[str, dict] = {}

    for race_code, race in sorted(races.items()):
        if race_code in race_input_failures:
            continue
        boats = race["boats"]
        by_course = {int(boat["course"]): boat for boat in boats if 1 <= int(boat["course"] or 0) <= 6}
        if len(boats) != 6 or set(by_course) != set(range(1, 7)):
            continue
        profiles = {
            c: profile_rates(history.profile(by_course[c]["player_id"], c, target_date, 12))
            for c in range(1, 7)
        }
        lane1 = vulnerability.profile(by_course[1]["player_id"], target_date, 12)
        shared: dict[str, float] = {
            "lane1_vulnerability_n": float(lane1["n"]),
            "lane1_vulnerability_rate": (
                100.0 * (lane1["makurare"] + lane1["makurarezashi"]) / lane1["n"]
                if lane1["n"] else float("nan")
            ),
        }
        for c in range(1, 7):
            boat = by_course[c]
            pid = boat["player_id"]
            shared[f"c{c}_history_n"] = float(profiles[c]["n"])
            for key in PROFILE_KEYS:
                shared[f"c{c}_{key}"] = float(profiles[c][key])
            rr = racer.get((term, pid), {}).get(c, {})
            shared[f"c{c}_st_rank"] = safe_float(rr.get("avg_rank"))
            for key in (
                "national_win_rate", "national_exacta_rate", "local_win_rate",
                "local_exacta_rate", "motor_exacta_rate", "boat_exacta_rate", "average_start",
            ):
                shared[f"c{c}_{key}"] = safe_float(boat.get(key))

        exhibition_rows = exhibition_by_race.get(race_code, [])
        second_map = (
            build_second_scores(exhibition_rows, average_exhibition)
            if average_exhibition is not None and len(exhibition_rows) == 6 else None
        )
        exhibition_ready = second_map is not None
        if exhibition_ready:
            relative = {key: relative_values(exhibition_rows, key) for key in RAW_EXHIBITION_KEYS}
            for c in range(1, 7):
                pid = by_course[c]["player_id"]
                second = second_map.get(pid)
                if second is None:
                    exhibition_ready = False
                    break
                for key in RAW_EXHIBITION_KEYS:
                    shared[f"c{c}_{key}_relative"] = relative[key].get(pid, float("nan"))
                for key in SECOND_KEYS:
                    shared[f"c{c}_{key}"] = safe_float(second.get(key))

        race_readiness: dict[str, dict] = {}
        race_ml_inputs: dict[str, dict] = {}
        missing_features: list[str] = []
        for course_text, course_models in artifact["models"].items():
            course = int(course_text)
            probabilities: dict[str, float | None] = {target: None for target in ("first", "top2", "top3")}
            triggered: dict[str, bool] = {target: False for target in ("first", "top2", "top3")}
            target_readiness: dict[str, dict] = {}
            for target in ("first", "top2", "top3"):
                item = course_models.get(target)
                if item is None:
                    continue
                if item["uses_exhibition"] and not exhibition_ready:
                    probabilities[target] = None
                    triggered[target] = False
                    target_readiness[target] = {
                        "decision_available": False,
                        "unavailable_reason": "EXHIBITION_MISSING",
                        "selected": False,
                        "threshold": float(item["threshold"]),
                        "uses_exhibition": True,
                        "model_version": artifact["version"],
                        "artifact_identifier": str(model_path),
                        "artifact_sha256": artifact_sha256,
                    }
                    continue
                probability = float(item["model"].predict_proba(feature_vector(shared, item["feature_names"]))[0, 1])
                feature_names = list(item["feature_names"])
                feature_values = {name: snapshot_scalar(shared.get(name, float("nan"))) for name in feature_names}
                missing_features.extend(name for name, value in feature_values.items() if isinstance(value, dict) and value.get("missing") == "nan")
                race_ml_inputs[f"{course}|{target}"] = {
                    "feature_schema_identity": feature_schema_identity(feature_names),
                    "feature_names": feature_names,
                    "feature_values": feature_values,
                    "uses_exhibition": bool(item["uses_exhibition"]),
                    "model_version": artifact["version"],
                    "artifact_identifier": str(model_path),
                    "artifact_sha256": artifact_sha256,
                }
                probabilities[target] = probability
                triggered[target] = probability >= float(item["threshold"])
                target_readiness[target] = {
                    "decision_available": True,
                    "selected": bool(triggered[target]),
                    "threshold": float(item["threshold"]),
                    "uses_exhibition": bool(item["uses_exhibition"]),
                    "model_version": artifact["version"],
                    "artifact_identifier": str(model_path),
                    "artifact_sha256": artifact_sha256,
                }

            race_readiness[str(course)] = {
                "course": course,
                "player_id": by_course[course]["player_id"],
                "targets": target_readiness,
            }

            if triggered["first"]:
                level, selected_target, label = 3, "first", f"{course}頭候補"
            elif triggered["top2"]:
                level, selected_target, label = 2, "top2", f"{course}軸候補"
            elif triggered["top3"]:
                level, selected_target, label = 1, "top3", f"{course}相手候補"
            else:
                continue
            item = course_models[selected_target]
            matches[str(course)][race_code] = {
                "race_code": race_code,
                "player_id": by_course[course]["player_id"],
                "course": course,
                "star_level": level,
                "star_text": "★" * level,
                "signal": label,
                "model_version": artifact["version"],
                "model_label": "機械学習 v1",
                "phase": "展示反映" if exhibition_ready else "展示前",
                "secondary_ready": exhibition_ready,
                "ai_first_rate": None if probabilities["first"] is None else round(100.0 * probabilities["first"], 2),
                "ai_top2_rate": None if probabilities["top2"] is None else round(100.0 * probabilities["top2"], 2),
                "ai_top3_rate": None if probabilities["top3"] is None else round(100.0 * probabilities["top3"], 2),
                "selected_target": selected_target,
                "selected_threshold": round(100.0 * float(item["threshold"]), 2),
                "history_n": int(profiles[course]["n"]),
                "attack_rate": round(float(profiles[course]["attack"]), 2),
                "makuri_rate": round(float(profiles[course]["makuri"]), 2),
                "historical_stats": historical_stats(item),
            }
        # The readiness ledger needs the full 6C x 3-target decision universe.
        # An undispatched target is explicitly unavailable, never represented by
        # an omitted key that downstream code could accidentally call false.
        for course in range(1, 7):
            readiness_course = race_readiness.setdefault(str(course), {
                "course": course,
                "player_id": by_course[course]["player_id"],
                "targets": {},
            })
            readiness_targets = readiness_course["targets"]
            for target in ("first", "top2", "top3"):
                if target in readiness_targets:
                    continue
                readiness_targets[target] = {
                    "decision_available": False,
                    "unavailable_reason": "ML_TARGET_NOT_DEPLOYED",
                    "selected": False,
                    "threshold": None,
                    "uses_exhibition": None,
                    "model_version": artifact["version"],
                    "artifact_identifier": str(model_path),
                    "artifact_sha256": artifact_sha256,
                }
        readiness_decisions[race_code] = race_readiness
        if args.strict_forward_input:
            readiness_inputs[race_code] = {
                "entries": [snapshot_json(by_course[course]) for course in range(1, 7)],
                "exhibition": snapshot_json(exhibition_rows),
                "ml_child": {
                    "runtime": "tamagawa_center_signal_live_v1",
                    "uses": race_ml_inputs,
                },
                "missing_markers": [{"kind": "ml_feature_nan", "feature": name} for name in sorted(set(missing_features))],
            }

    payload = {
        "status": "ok",
        "version": artifact["version"],
        "date": args.date,
        "input_status": "OK" if not race_input_failures else "PARTIAL_INPUT_INCOMPLETE",
        "race_input_failures": race_input_failures,
        "matches": matches,
        "readiness_decisions": readiness_decisions,
    }
    if args.strict_forward_input:
        payload["readiness_inputs"] = readiness_inputs
    print(json.dumps(payload, ensure_ascii=False, allow_nan=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
