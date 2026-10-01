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


def load_entries(target_date) -> dict[str, dict]:
    sql = """
WITH latest_ex AS (
    SELECT DISTINCT ON (el.race_code, el.player_id)
        el.race_code, el.player_id, el.entry_course::integer AS entry_course
    FROM boat_race.exhibition_live el
    JOIN boat_race.race_master rm ON rm.race_code = el.race_code
    WHERE rm.race_date = %s::date
      AND SUBSTRING(el.race_code, 9, 3) = %s
    ORDER BY el.race_code, el.player_id, el.created_date DESC NULLS LAST
)
SELECT
    re.race_code, re.player_id::text, re.lane_number::integer,
    COALESCE(le.entry_course, re.lane_number::integer) AS course,
    ps.national_win_rate, ps.national_exacta_rate,
    ps.local_win_rate, ps.local_exacta_rate,
    es.motor_exacta_rate, es.boat_exacta_rate,
    rr.average_start
FROM boat_race.race_entry re
JOIN boat_race.race_master rm ON rm.race_code = re.race_code
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
WHERE rm.race_date = %s::date
  AND SUBSTRING(re.race_code, 9, 3) = %s
ORDER BY re.race_code, course, re.lane_number
"""
    races: dict[str, dict] = {}
    with connect_db() as conn:
        with conn.cursor() as cur:
            cur.execute(sql, (target_date, PLACE, target_date, PLACE))
            names = [item.name for item in cur.description]
            for values in cur.fetchall():
                row = dict(zip(names, values))
                code = str(row["race_code"])
                row["player_id"] = str(row["player_id"]).strip()
                races.setdefault(code, {"date": target_date, "boats": []})["boats"].append(row)
    return races


def feature_vector(shared: dict[str, float], names: list[str]) -> np.ndarray:
    return np.asarray([[shared.get(name, float("nan")) for name in names]], dtype=np.float64)


def historical_stats(model_item: dict) -> dict:
    stats = model_item.get("test_selection", {})
    return {
        "n": int(stats.get("n", 0)),
        "first_rate": None if stats.get("first_rate") is None else round(100.0 * float(stats["first_rate"]), 2),
        "top2_rate": None if stats.get("top2_rate") is None else round(100.0 * float(stats["top2_rate"]), 2),
        "top3_rate": None if stats.get("top3_rate") is None else round(100.0 * float(stats["top3_rate"]), 2),
        "period": stats.get("period"),
    }


def load_verified_artifact(model_path: Path) -> dict:
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
    return artifact


def main() -> int:
    global PLACE
    parser = argparse.ArgumentParser()
    parser.add_argument("date")
    parser.add_argument("--place", default="TMG", choices=sorted(MODEL_PATHS))
    parser.add_argument("--base", action="store_true", help="展示を使わない事前判定")
    args = parser.parse_args()
    PLACE = str(args.place)
    model_path = MODEL_PATHS[PLACE]
    target_date = datetime.strptime(args.date, "%Y-%m-%d").date()
    if not model_path.is_file():
        print(json.dumps({"status": "error", "error": "model_not_found"}, ensure_ascii=False))
        return 2
    try:
        artifact = load_verified_artifact(model_path)
    except (OSError, ValueError, EOFError) as error:
        print(json.dumps({"status": "error", "error": str(error)}, ensure_ascii=False))
        return 2
    races = load_entries(target_date)
    if not races:
        print(json.dumps({"status": "ok", "version": artifact["version"], "matches": {}}, ensure_ascii=False))
        return 0

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

    for race_code, race in sorted(races.items()):
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

        for course_text, course_models in artifact["models"].items():
            course = int(course_text)
            probabilities: dict[str, float | None] = {target: None for target in ("first", "top2", "top3")}
            triggered: dict[str, bool] = {target: False for target in ("first", "top2", "top3")}
            for target in ("first", "top2", "top3"):
                item = course_models.get(target)
                if item is None:
                    continue
                if item["uses_exhibition"] and not exhibition_ready:
                    probabilities[target] = None
                    triggered[target] = False
                    continue
                probability = float(item["model"].predict_proba(feature_vector(shared, item["feature_names"]))[0, 1])
                probabilities[target] = probability
                triggered[target] = probability >= float(item["threshold"])

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

    print(json.dumps({
        "status": "ok",
        "version": artifact["version"],
        "date": args.date,
        "matches": matches,
    }, ensure_ascii=False, allow_nan=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
