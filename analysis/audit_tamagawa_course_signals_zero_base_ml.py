#!/usr/bin/env python3
"""指定場の全コースサインを、現行条件を前提にせず再監査する。

現行サインは比較対象にだけ使い、モデル特徴量には入れない。
対象レース以前に分かる一次情報と展示情報から、各コースの
1着・2連対・3連対を別々に学習する。
"""

from __future__ import annotations

import argparse
import json
import math
import sys
from collections import Counter, defaultdict, deque
from datetime import date, timedelta
from pathlib import Path

import numpy as np
import sklearn
from sklearn.ensemble import HistGradientBoostingClassifier
from sklearn.impute import SimpleImputer
from sklearn.inspection import permutation_importance
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import brier_score_loss, log_loss, roc_auc_score
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import StandardScaler

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "analysis"))

from analyze_all_venue_lane_signals import load_prerace_targets, profile_rates  # noqa: E402
from analyze_all_venue_secondary_signals import (  # noqa: E402
    build_second_scores,
    load_exhibition,
)
from analyze_tamagawa_boaters_hypothesis import (  # noqa: E402
    TechniqueHistoryIndex,
    load_history,
    load_racer_results,
    months_ago,
    parse_date,
    required_terms,
    term_info_for_date,
)
from analyze_tamagawa_lane4_makurizashi_vulnerability import (  # noqa: E402
    VulnerabilityIndex,
    load_lane1_vulnerability_history,
)
from optimize_all_venue_secondary_rules import primary_match  # noqa: E402
from slit_validate_v2 import connect_db  # noqa: E402


PLACE = "TMG"
PLACE_NAMES = {
    "EDG": "江戸川",
    "KRY": "桐生",
    "HWJ": "平和島",
    "TDA": "戸田",
    "TMG": "多摩川",
    "ASY": "芦屋",
    "AMG": "尼崎",
    "BWK": "びわこ",
}
TARGETS = ("first", "top2", "top3")
PROFILE_KEYS = ("nige", "sashi", "makuri", "attack")
RAW_EXHIBITION_KEYS = (
    "exhibition_time",
    "start_timing",
    "lap_time",
    "around_time",
    "straight_time",
)
SECOND_KEYS = (
    "ex_score",
    "st_score",
    "lap_score",
    "mawari_score",
    "straight_score",
    "attack_potential",
    "stable_score",
    "final_2nd_score",
    "second_rank",
    "gap_to_top",
)
ROLLING_EXHIBITION_DAYS = 183
LAST_DATASET_AUDIT: dict = {}


def safe_float(value) -> float:
    try:
        number = float(value)
    except (TypeError, ValueError):
        return float("nan")
    return number if math.isfinite(number) else float("nan")


def load_entry_facts(start: date, end: date) -> dict[tuple[str, str], dict]:
    sql = """
SELECT
    re.race_code,
    re.player_id::text,
    re.lane_number::integer,
    ps.national_win_rate,
    ps.national_exacta_rate,
    ps.local_win_rate,
    ps.local_exacta_rate,
    es.motor_exacta_rate,
    es.boat_exacta_rate,
    rr.average_start,
    rr."class"::text AS player_class
FROM boat_race.race_entry re
LEFT JOIN boat_race.player_stats ps
  ON ps.race_code = re.race_code
 AND ps.player_id = re.player_id
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
WHERE re.race_date BETWEEN %s::date AND %s::date
  AND SUBSTRING(re.race_code, 9, 3) = %s
ORDER BY re.race_code, re.lane_number
"""
    out: dict[tuple[str, str], dict] = {}
    with connect_db() as conn:
        with conn.cursor() as cur:
            cur.execute(sql, (start, end, PLACE))
            names = [item.name for item in cur.description]
            for values in cur.fetchall():
                row = dict(zip(names, values))
                out[(str(row["race_code"]), str(row["player_id"]).strip())] = row
    return out


def load_point_in_time_avg_exhibition(
    start: date,
    end: date,
    place: str,
) -> dict[date, float]:
    """対象日前183日の同場展示タイム平均を日付単位で返す。

    対象日当日の展示は、同日内のレース順にかかわらず一切含めない。
    exhibition_live に再取得行がある場合は race_code/player_id ごとの最新行を
    1件だけ採用する。
    """
    history_start = start - timedelta(days=ROLLING_EXHIBITION_DAYS)
    sql = """
SELECT race_date, exhibition_time
FROM (
    SELECT DISTINCT ON (el.race_code, el.player_id)
        rm.race_date,
        el.race_code,
        el.player_id,
        el.exhibition_time
    FROM boat_race.exhibition_live el
    JOIN boat_race.race_master rm ON rm.race_code = el.race_code
    WHERE rm.race_date BETWEEN %s::date AND %s::date
      AND SUBSTRING(el.race_code, 9, 3) = %s
      AND el.exhibition_time IS NOT NULL
      AND el.exhibition_time > 0
    ORDER BY el.race_code, el.player_id, el.created_date DESC NULLS LAST
) latest
ORDER BY race_date
"""
    daily = defaultdict(lambda: [0.0, 0])
    with connect_db() as conn:
        with conn.cursor() as cur:
            cur.execute(sql, (history_start, end, place))
            for race_date, exhibition_time in cur.fetchall():
                value = safe_float(exhibition_time)
                if math.isfinite(value) and value > 0:
                    daily[race_date][0] += value
                    daily[race_date][1] += 1

    history: deque[tuple[date, float, int]] = deque()
    rolling_sum = 0.0
    rolling_n = 0
    averages: dict[date, float] = {}
    cursor = history_start
    while cursor <= end:
        cutoff = cursor - timedelta(days=ROLLING_EXHIBITION_DAYS)
        while history and history[0][0] < cutoff:
            _, old_sum, old_n = history.popleft()
            rolling_sum -= old_sum
            rolling_n -= old_n

        if cursor >= start and rolling_n > 0:
            averages[cursor] = rolling_sum / rolling_n

        # 当日の値は、その日の平均を確定した後で履歴へ追加する。
        day_sum, day_n = daily.get(cursor, (0.0, 0))
        if day_n:
            history.append((cursor, float(day_sum), int(day_n)))
            rolling_sum += float(day_sum)
            rolling_n += int(day_n)
        cursor += timedelta(days=1)
    return averages


def relative_values(rows: list[dict], key: str) -> dict[str, float]:
    values = [safe_float(row.get(key)) for row in rows]
    valid = [value for value in values if math.isfinite(value)]
    mean = float(np.mean(valid)) if valid else float("nan")
    # タイム系は小さいほど良いので「平均との差」は mean - value とする。
    return {
        str(row["player_id"]): mean - value if math.isfinite(mean) and math.isfinite(value) else float("nan")
        for row, value in zip(rows, values)
    }


def current_signal(base: dict, config: dict, course: int) -> bool:
    variants = ("sashi", "makuri") if course == 2 else ("main",)
    return any(primary_match(base, PLACE, course, variant, config) for variant in variants)


def build_dataset(start: date, end: date, config: dict) -> tuple[list[dict], list[str], list[str]]:
    global LAST_DATASET_AUDIT
    print(f"{PLACE_NAMES.get(PLACE, PLACE)}データ読込 {start}～{end}", flush=True)
    races = load_prerace_targets(start, end, (PLACE,))
    player_ids = sorted({boat["player_id"] for race in races.values() for boat in race["boats"]})
    racer = load_racer_results(required_terms(start, end))
    history = TechniqueHistoryIndex(load_history(start, end, player_ids))
    vulnerability = VulnerabilityIndex(
        load_lane1_vulnerability_history(start, end, player_ids)
    )
    exhibition_by_race = load_exhibition(start, end, PLACE)
    average_exhibition_by_date = load_point_in_time_avg_exhibition(start, end, PLACE)
    entry_facts = load_entry_facts(start, end)

    pre_features = []
    for c in range(1, 7):
        pre_features += [f"c{c}_{key}" for key in ("history_n",) + PROFILE_KEYS]
        pre_features += [f"c{c}_st_rank"]
        pre_features += [
            f"c{c}_{key}"
            for key in (
                "national_win_rate",
                "national_exacta_rate",
                "local_win_rate",
                "local_exacta_rate",
                "motor_exacta_rate",
                "boat_exacta_rate",
                "average_start",
            )
        ]
    pre_features += ["lane1_vulnerability_rate", "lane1_vulnerability_n"]
    post_features = list(pre_features)
    for c in range(1, 7):
        post_features += [f"c{c}_{key}_relative" for key in RAW_EXHIBITION_KEYS]
        post_features += [f"c{c}_{key}" for key in SECOND_KEYS]

    records: list[dict] = []
    skipped = defaultdict(int)
    for race_code, race in sorted(races.items()):
        boats = race["boats"]
        average_exhibition = average_exhibition_by_date.get(race["date"])
        if len(boats) != 6 or race_code not in exhibition_by_race or average_exhibition is None:
            skipped["missing_race_or_exhibition"] += 1
            continue
        by_course = {int(boat["course"]): boat for boat in boats if boat["course"] is not None}
        if set(by_course) != set(range(1, 7)):
            skipped["bad_course_map"] += 1
            continue
        race_date = race["date"]
        term = term_info_for_date(race_date)
        if any(
            racer.get((term, by_course[c]["player_id"])) is None
            or racer[(term, by_course[c]["player_id"])][c]["avg_rank"] is None
            for c in range(1, 7)
        ):
            skipped["missing_st_rank"] += 1
            continue
        exhibition_rows = exhibition_by_race[race_code]
        second_map = build_second_scores(exhibition_rows, average_exhibition)
        if second_map is None:
            skipped["bad_second_eval"] += 1
            continue
        relative = {
            key: relative_values(exhibition_rows, key) for key in RAW_EXHIBITION_KEYS
        }
        profiles = {
            c: profile_rates(
                history.profile(by_course[c]["player_id"], c, race_date, 12)
            )
            for c in range(1, 7)
        }
        st_rank = {
            c: float(racer[(term, by_course[c]["player_id"])][c]["avg_rank"])
            for c in range(1, 7)
        }
        base = {
            "profiles": profiles,
            "st_rank": st_rank,
            "lane1_vulnerability_rate": None,
            "lane1_vulnerability_n": 0,
        }
        lane1_profile = vulnerability.profile(by_course[1]["player_id"], race_date, 12)
        base["lane1_vulnerability_n"] = int(lane1_profile["n"])
        if lane1_profile["n"]:
            base["lane1_vulnerability_rate"] = 100.0 * (
                lane1_profile["makurare"] + lane1_profile["makurarezashi"]
            ) / lane1_profile["n"]

        shared: dict[str, float] = {
            "lane1_vulnerability_rate": safe_float(base["lane1_vulnerability_rate"]),
            "lane1_vulnerability_n": float(base["lane1_vulnerability_n"]),
        }
        valid = True
        for c in range(1, 7):
            player_id = by_course[c]["player_id"]
            facts = entry_facts.get((race_code, player_id))
            second = second_map.get(player_id)
            if facts is None or second is None:
                valid = False
                break
            shared[f"c{c}_history_n"] = float(profiles[c]["n"])
            for key in PROFILE_KEYS:
                shared[f"c{c}_{key}"] = float(profiles[c][key])
            shared[f"c{c}_st_rank"] = st_rank[c]
            for key in (
                "national_win_rate",
                "national_exacta_rate",
                "local_win_rate",
                "local_exacta_rate",
                "motor_exacta_rate",
                "boat_exacta_rate",
                "average_start",
            ):
                shared[f"c{c}_{key}"] = safe_float(facts.get(key))
            for key in RAW_EXHIBITION_KEYS:
                shared[f"c{c}_{key}_relative"] = relative[key].get(player_id, float("nan"))
            for key in SECOND_KEYS:
                shared[f"c{c}_{key}"] = safe_float(second.get(key))
        if not valid:
            skipped["missing_boat_feature"] += 1
            continue

        for course in range(1, 7):
            subject_player_id = str(by_course[course]["player_id"]).strip()
            subject_facts = entry_facts.get((race_code, subject_player_id), {})
            records.append({
                "race_code": race_code,
                "date": race_date,
                "course": course,
                "player_id": subject_player_id,
                "player_class": str(subject_facts.get("player_class") or "").strip().upper(),
                "first": int(race["first"] == course),
                "top2": int(course in (race["first"], race["second"])),
                "top3": int(course in (race["first"], race["second"], race["third"])),
                "current_signal": current_signal(base, config, course),
                "features": dict(shared),
            })
    print(
        f"使用可能={len(records) // 6:,}R / 元={len(races):,}R / skip={dict(skipped)}",
        flush=True,
    )
    source_dates = [race["date"] for race in races.values()]
    used_dates = [row["date"] for row in records]
    LAST_DATASET_AUDIT = {
        "source_races": len(races),
        "source_min_date": min(source_dates).isoformat() if source_dates else None,
        "source_max_date": max(source_dates).isoformat() if source_dates else None,
        "used_races": len(records) // 6,
        "used_min_date": min(used_dates).isoformat() if used_dates else None,
        "used_max_date": max(used_dates).isoformat() if used_dates else None,
        "excluded_races": len(races) - (len(records) // 6),
        "exclusion_reasons": dict(sorted(skipped.items())),
    }
    return records, pre_features, post_features


def make_models() -> dict[str, Pipeline]:
    return {
        "logistic": Pipeline([
            ("imputer", SimpleImputer(strategy="median", add_indicator=True)),
            ("scale", StandardScaler()),
            ("model", LogisticRegression(C=0.5, max_iter=1500)),
        ]),
        "hist_gradient": Pipeline([
            ("imputer", SimpleImputer(strategy="median", add_indicator=True)),
            ("model", HistGradientBoostingClassifier(
                learning_rate=0.045,
                max_iter=220,
                max_leaf_nodes=15,
                min_samples_leaf=35,
                l2_regularization=1.0,
                random_state=20260927,
            )),
        ]),
    }


def matrix(rows: list[dict], feature_names: list[str]) -> np.ndarray:
    return np.asarray(
        [[row["features"].get(name, float("nan")) for name in feature_names] for row in rows],
        dtype=np.float64,
    )


def probability_metrics(y: np.ndarray, p: np.ndarray) -> dict:
    return {
        "n": int(len(y)),
        "rate": float(np.mean(y)),
        "brier": float(brier_score_loss(y, p)),
        "nll": float(log_loss(y, np.clip(p, 1e-9, 1 - 1e-9), labels=[0, 1])),
        "auc": float(roc_auc_score(y, p)) if len(np.unique(y)) == 2 else None,
    }


def selection_metrics(y: np.ndarray, selected: np.ndarray) -> dict:
    n = int(np.sum(selected))
    base = float(np.mean(y))
    rate = float(np.mean(y[selected])) if n else None
    return {
        "n": n,
        "coverage": n / len(y) if len(y) else 0.0,
        "rate": rate,
        "base_rate": base,
        "lift_point": None if rate is None else rate - base,
    }


def selection_bias(rows: list[dict], selected: np.ndarray, course: int) -> dict:
    """選抜が特定選手・級別・全国勝率帯へ偏り過ぎていないかを記録する。"""
    selected_rows = [row for row, include in zip(rows, selected) if include]
    class_counts = Counter(str(row.get("player_class") or "未取得") for row in selected_rows)
    player_counts = Counter(str(row.get("player_id") or "") for row in selected_rows)
    bands = Counter()
    key = f"c{course}_national_win_rate"
    for row in selected_rows:
        value = safe_float(row["features"].get(key))
        if not math.isfinite(value):
            bands["未取得"] += 1
        elif value < 4.0:
            bands["4.0未満"] += 1
        elif value < 5.0:
            bands["4.0-5.0"] += 1
        elif value < 6.0:
            bands["5.0-6.0"] += 1
        else:
            bands["6.0以上"] += 1
    total = len(selected_rows)
    return {
        "n": total,
        "top_players": [
            {"player_id": player_id, "n": count, "share": count / total if total else 0.0}
            for player_id, count in player_counts.most_common(10)
        ],
        "class_counts": dict(sorted(class_counts.items())),
        "national_win_rate_bands": dict(sorted(bands.items())),
    }


def monthly_selection(rows: list[dict], y: np.ndarray, selections: dict[str, np.ndarray]) -> dict:
    months = sorted({row["date"].strftime("%Y-%m") for row in rows})
    out = {}
    for month in months:
        month_mask = np.asarray(
            [row["date"].strftime("%Y-%m") == month for row in rows], dtype=bool
        )
        out[month] = {
            "base": selection_metrics(y[month_mask], np.ones(int(month_mask.sum()), dtype=bool)),
        }
        for name, selected in selections.items():
            out[month][name] = selection_metrics(y[month_mask], selected[month_mask])
    return out


def bootstrap_rate_difference(
    y: np.ndarray,
    candidate: np.ndarray,
    baseline: np.ndarray,
    samples: int = 5000,
) -> dict:
    if not np.any(candidate) or not np.any(baseline):
        return {"difference": None, "ci95_low": None, "ci95_high": None, "probability_improves": None}
    observed = float(np.mean(y[candidate]) - np.mean(y[baseline]))
    rng = np.random.default_rng(20260927)
    differences = []
    for _ in range(samples):
        indices = rng.integers(0, len(y), len(y))
        cand = candidate[indices]
        base = baseline[indices]
        if not np.any(cand) or not np.any(base):
            continue
        sampled = y[indices]
        differences.append(float(np.mean(sampled[cand]) - np.mean(sampled[base])))
    values = np.asarray(differences, dtype=np.float64)
    low, high = np.quantile(values, [0.025, 0.975])
    return {
        "difference": observed,
        "ci95_low": float(low),
        "ci95_high": float(high),
        "probability_improves": float(np.mean(values > 0)),
    }


def block_bootstrap_rate_difference(
    rows: list[dict],
    y: np.ndarray,
    candidate: np.ndarray,
    baseline: np.ndarray,
    samples: int = 5000,
) -> dict:
    """月をブロックとして再標本化し、選択率の差を評価する。"""
    if not np.any(candidate) or not np.any(baseline):
        return {
            "unit": "month",
            "blocks": 0,
            "difference": None,
            "ci95_low": None,
            "ci95_high": None,
            "probability_improves": None,
        }
    month_keys = np.asarray([row["date"].strftime("%Y-%m") for row in rows])
    months = sorted(set(month_keys.tolist()))
    block_indices = {month: np.flatnonzero(month_keys == month) for month in months}
    rng = np.random.default_rng(20260927)
    differences = []
    for _ in range(samples):
        sampled_months = rng.choice(months, size=len(months), replace=True)
        indices = np.concatenate([block_indices[str(month)] for month in sampled_months])
        cand = candidate[indices]
        base = baseline[indices]
        if not np.any(cand) or not np.any(base):
            continue
        sampled = y[indices]
        differences.append(float(np.mean(sampled[cand]) - np.mean(sampled[base])))
    values = np.asarray(differences, dtype=np.float64)
    low, high = np.quantile(values, [0.025, 0.975])
    return {
        "unit": "month",
        "blocks": len(months),
        "difference": float(np.mean(y[candidate]) - np.mean(y[baseline])),
        "ci95_low": float(low),
        "ci95_high": float(high),
        "probability_improves": float(np.mean(values > 0)),
    }


def classify_monthly_stability(
    rows: list[dict],
    y: np.ndarray,
    candidate: np.ndarray,
    baseline: np.ndarray,
) -> dict:
    months = sorted({row["date"].strftime("%Y-%m") for row in rows})
    details = []
    for month in months:
        mask = np.asarray([row["date"].strftime("%Y-%m") == month for row in rows])
        cand_n = int(np.sum(candidate & mask))
        base_n = int(np.sum(baseline & mask))
        cand_rate = float(np.mean(y[candidate & mask])) if cand_n else None
        base_rate = float(np.mean(y[baseline & mask])) if base_n else None
        eligible = cand_n >= 5 and base_n >= 5
        difference = None if cand_rate is None or base_rate is None else cand_rate - base_rate
        details.append({
            "month": month,
            "candidate_n": cand_n,
            "candidate_rate": cand_rate,
            "baseline_n": base_n,
            "baseline_rate": base_rate,
            "difference": difference,
            "eligible": eligible,
        })
    eligible_rows = [item for item in details if item["eligible"]]
    positive = sum(1 for item in eligible_rows if item["difference"] is not None and item["difference"] > 0)
    negative = sum(1 for item in eligible_rows if item["difference"] is not None and item["difference"] < 0)
    eligible_n = len(eligible_rows)
    if eligible_n >= 4 and positive / eligible_n >= 0.67 and int(np.sum(candidate)) >= 30:
        label = "安定"
    elif eligible_n >= 3 and positive / eligible_n >= 0.50 and int(np.sum(candidate)) >= 20:
        label = "やや不安定"
    else:
        label = "不安定"
    return {
        "classification": label,
        "eligible_months": eligible_n,
        "positive_months": positive,
        "negative_months": negative,
        "minimum_n_per_selection": 5,
        "months": details,
    }


def bootstrap_brier_difference(
    y: np.ndarray, baseline: np.ndarray, candidate: np.ndarray, samples: int = 5000
) -> dict:
    row_delta = (candidate - y) ** 2 - (baseline - y) ** 2
    rng = np.random.default_rng(20260927)
    means = np.empty(samples, dtype=np.float64)
    for index in range(samples):
        selected = rng.integers(0, len(y), len(y))
        means[index] = float(np.mean(row_delta[selected]))
    low, high = np.quantile(means, [0.025, 0.975])
    return {
        "difference": float(np.mean(row_delta)),
        "ci95_low": float(low),
        "ci95_high": float(high),
        "probability_improves": float(np.mean(means < 0)),
    }


def coverage_threshold(probability: np.ndarray, coverage: float) -> float:
    coverage = min(max(float(coverage), 0.02), 0.98)
    return float(np.quantile(probability, 1.0 - coverage))


def fit_best(
    train: list[dict], valid: list[dict], test: list[dict], target: str, feature_names: list[str]
) -> tuple[Pipeline, dict]:
    x_train, x_valid, x_test = (matrix(rows, feature_names) for rows in (train, valid, test))
    y_train = np.asarray([row[target] for row in train], dtype=np.int8)
    y_valid = np.asarray([row[target] for row in valid], dtype=np.int8)
    y_test = np.asarray([row[target] for row in test], dtype=np.int8)
    candidates = []
    for name, model in make_models().items():
        model.fit(x_train, y_train)
        valid_probability = model.predict_proba(x_valid)[:, 1]
        candidates.append((brier_score_loss(y_valid, valid_probability), name, model, valid_probability))
    _, name, model, valid_probability = min(candidates, key=lambda item: item[0])
    test_probability = model.predict_proba(x_test)[:, 1]
    return model, {
        "model": name,
        "valid_probability": valid_probability,
        "test_probability": test_probability,
        "valid": probability_metrics(y_valid, valid_probability),
        "test": probability_metrics(y_test, test_probability),
        "x_test": x_test,
        "y_test": y_test,
    }


def importance(model: Pipeline, x: np.ndarray, y: np.ndarray, names: list[str]) -> list[dict]:
    result = permutation_importance(
        model,
        x,
        y,
        scoring="neg_brier_score",
        n_repeats=4,
        random_state=20260927,
        n_jobs=1,
    )
    order = np.argsort(result.importances_mean)[::-1][:10]
    return [
        {
            "feature": names[int(index)],
            "importance": float(result.importances_mean[int(index)]),
        }
        for index in order
        if result.importances_mean[int(index)] > 0
    ]


def evaluate_course_target(
    rows: list[dict], target: str, pre_features: list[str], post_features: list[str], course: int
) -> dict:
    train = [row for row in rows if row["split"] == "train"]
    valid = [row for row in rows if row["split"] == "valid"]
    test = [row for row in rows if row["split"] == "test"]
    pre_model, pre = fit_best(train, valid, test, target, pre_features)
    post_model, post = fit_best(train, valid, test, target, post_features)
    del pre_model

    current_valid = np.asarray([bool(row["current_signal"]) for row in valid])
    current_test = np.asarray([bool(row["current_signal"]) for row in test])
    coverage = float(np.mean(current_valid)) if np.any(current_valid) else 0.20
    pre_threshold = coverage_threshold(pre["valid_probability"], coverage)
    post_threshold = coverage_threshold(post["valid_probability"], coverage)
    pre_selected = pre["test_probability"] >= pre_threshold
    post_selected = post["test_probability"] >= post_threshold

    if np.any(current_valid):
        hybrid_threshold = coverage_threshold(
            post["valid_probability"][current_valid], 0.50
        )
        hybrid_selected = current_test & (post["test_probability"] >= hybrid_threshold)
    else:
        hybrid_threshold = None
        hybrid_selected = np.zeros(len(test), dtype=bool)

    selections = {
        "current": current_test,
        "pre_ml": pre_selected,
        "post_ml": post_selected,
        "hybrid": hybrid_selected,
    }
    comparison_baseline_test = current_test if np.any(current_test) else np.ones(len(test), dtype=bool)

    result = {
        "target": target,
        "period_counts": {"train": len(train), "valid": len(valid), "test": len(test)},
        "base_test_rate": float(np.mean(post["y_test"])),
        "current": selection_metrics(post["y_test"], current_test),
        "comparator": "current_signal" if np.any(current_test) else "base_rate",
        "pre_ml": {
            "model": pre["model"],
            "threshold": pre_threshold,
            "probability": {"valid": pre["valid"], "test": pre["test"]},
            "selection": selection_metrics(pre["y_test"], pre_selected),
        },
        "post_ml": {
            "model": post["model"],
            "threshold": post_threshold,
            "probability": {"valid": post["valid"], "test": post["test"]},
            "selection": selection_metrics(post["y_test"], post_selected),
            "top_features": importance(post_model, post["x_test"], post["y_test"], post_features),
        },
        "hybrid": {
            "threshold": hybrid_threshold,
            "selection": selection_metrics(post["y_test"], hybrid_selected),
        },
        "selection_bias": {
            "pre_ml": selection_bias(test, pre_selected, course),
            "post_ml": selection_bias(test, post_selected, course),
            "hybrid": selection_bias(test, hybrid_selected, course),
        },
        "monthly_test": monthly_selection(test, post["y_test"], selections),
        "monthly_stability": {
            "comparator": "current" if np.any(current_test) else "base",
            "pre_ml": classify_monthly_stability(
                test, post["y_test"], pre_selected, comparison_baseline_test
            ),
            "post_ml": classify_monthly_stability(
                test, post["y_test"], post_selected, comparison_baseline_test
            ),
            "hybrid": classify_monthly_stability(
                test, post["y_test"], hybrid_selected, comparison_baseline_test
            ) if np.any(hybrid_selected) else None,
        },
        "uncertainty": {
            "pre_ml_vs_current_rate": bootstrap_rate_difference(
                post["y_test"], pre_selected, comparison_baseline_test
            ),
            "post_ml_vs_current_rate": bootstrap_rate_difference(
                post["y_test"], post_selected, comparison_baseline_test
            ),
            "hybrid_vs_current_rate": bootstrap_rate_difference(
                post["y_test"], hybrid_selected, comparison_baseline_test
            ),
            "post_vs_pre_brier": bootstrap_brier_difference(
                post["y_test"], pre["test_probability"], post["test_probability"]
            ),
            "pre_ml_vs_comparator_month_block": block_bootstrap_rate_difference(
                test, post["y_test"], pre_selected, comparison_baseline_test
            ),
            "post_ml_vs_comparator_month_block": block_bootstrap_rate_difference(
                test, post["y_test"], post_selected, comparison_baseline_test
            ),
            "hybrid_vs_comparator_month_block": block_bootstrap_rate_difference(
                test, post["y_test"], hybrid_selected, comparison_baseline_test
            ),
        },
    }
    return result


def classify_candidate(item: dict) -> str:
    """TESTはモデル・閾値の選択に使わず、探索結果の説明分類にのみ使う。"""
    selection = item["post_ml"]["selection"]
    ordinary = item["uncertainty"]["post_ml_vs_current_rate"]
    block = item["uncertainty"]["post_ml_vs_comparator_month_block"]
    stability = item["monthly_stability"]["post_ml"]["classification"]
    improvement = ordinary.get("probability_improves")
    block_improvement = block.get("probability_improves")
    if (
        selection["n"] >= 30
        and selection["lift_point"] is not None
        and selection["lift_point"] > 0
        and improvement is not None and improvement >= 0.95
        and block_improvement is not None and block_improvement >= 0.80
        and stability in {"安定", "やや不安定"}
    ):
        return "強い候補"
    if (
        selection["n"] >= 20
        and selection["lift_point"] is not None
        and selection["lift_point"] > 0
        and improvement is not None and improvement >= 0.75
    ):
        return "次点"
    if item["comparator"] == "current_signal":
        return "現行維持寄り"
    return "見送り寄り"


def pct(value) -> str:
    return "-" if value is None else f"{100.0 * float(value):.2f}%"


def write_markdown(report: dict, path: Path) -> None:
    place_name = str(report.get("place_name") or report.get("place") or "対象場")
    lines = [
        f"# {place_name} 全コースサイン ゼロベースML監査（point-in-time版）",
        "",
        f"- 実行環境: Python {report['runtime']['python']} / scikit-learn {report['runtime']['scikit_learn']} / numpy {report['runtime']['numpy']}",
        f"- 対象期間: {report['period']['start']}～{report['period']['end']}",
        f"- 学習: ～{report['period']['train_end']} / 検証: ～{report['period']['valid_end']} / 最終テスト: それ以降",
        f"- 使用レース: {report['races']:,}R",
        "- 現行サインは比較対象のみ。モデル特徴量には不使用。",
        f"- 展示タイム場平均は対象日を除く同場直近{ROLLING_EXHIBITION_DAYS}日だけで算出。",
        "- ML選択数は、原則として現行サインと同程度のカバー率へ検証期間で固定。現行サインなしは20%。",
        "- 月block比較は現行サインありなら現行、なしなら全体基礎率を比較対象とする。",
        "",
    ]
    layout = report.get("layout_audit", {})
    if layout.get("layout_change_date"):
        lines += [
            f"- レイアウト変更日: {layout['layout_change_date']}",
            f"- 元データ最古日: {layout.get('source_min_date')}",
            f"- 使用データは全件レイアウト変更後: {'はい' if layout.get('all_source_races_after_layout_change') else 'いいえ'}",
            "",
        ]
    for course in range(1, 7):
        lines += [f"## {course}コース", ""]
        lines += ["|対象|分類|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|", "|---|---|---:|---:|---:|---:|---:|"]
        for target in TARGETS:
            item = report["courses"][str(course)][target]
            lines.append(
                f"|{target}|{item['classification']}|{pct(item['base_test_rate'])}|"
                f"{item['current']['n']} / {pct(item['current']['rate'])}|"
                f"{item['pre_ml']['selection']['n']} / {pct(item['pre_ml']['selection']['rate'])}|"
                f"{item['post_ml']['selection']['n']} / {pct(item['post_ml']['selection']['rate'])}|"
                f"{item['hybrid']['selection']['n']} / {pct(item['hybrid']['selection']['rate'])}|"
            )
        lines += ["", "MLと現行サインの差（最終6か月ブートストラップ）:", ""]
        for target in TARGETS:
            item = report["courses"][str(course)][target]
            pre = item["uncertainty"]["pre_ml_vs_current_rate"]
            post = item["uncertainty"]["post_ml_vs_current_rate"]
            lines.append(
                f"- {target}: 展示前 {pct(pre['difference'])}（改善確率 {pct(pre['probability_improves'])}）"
                f" / 展示後 {pct(post['difference'])}（改善確率 {pct(post['probability_improves'])}）"
            )
        lines += ["", "展示後MLの月ブロック評価・月別安定性:", ""]
        for target in TARGETS:
            item = report["courses"][str(course)][target]
            block = item["uncertainty"]["post_ml_vs_comparator_month_block"]
            stability = item["monthly_stability"]["post_ml"]
            lines.append(
                f"- {target}: 月block改善確率 {pct(block['probability_improves'])} / "
                f"{stability['classification']} "
                f"（改善月 {stability['positive_months']}/{stability['eligible_months']}）"
            )
        lines += ["", "展示後MLの主な特徴:", ""]
        for target in TARGETS:
            features = report["courses"][str(course)][target]["post_ml"]["top_features"][:5]
            label = "、".join(item["feature"] for item in features) or "明確な特徴なし"
            lines.append(f"- {target}: {label}")
        lines.append("")
    lines += [
        "## 判定上の注意",
        "",
        "- これは条件見直し前の探索監査であり、本番サインは変更しない。",
        "- 最終テストで良くても、月別安定性・ブートストラップ・回収率を通すまでは採用しない。",
        "- 次段階で、MLが示した特徴を人が読める少数条件へ戻し、現行条件と再比較する。",
    ]
    path.write_text("\n".join(lines) + "\n", encoding="utf-8")


def main() -> int:
    global PLACE
    parser = argparse.ArgumentParser()
    parser.add_argument("--place", default="TMG")
    parser.add_argument("--end", default=(date.today() - timedelta(days=1)).isoformat())
    parser.add_argument("--months", type=int, default=36)
    parser.add_argument("--valid-months", type=int, default=6)
    parser.add_argument("--test-months", type=int, default=6)
    parser.add_argument(
        "--output-prefix",
        type=Path,
        default=None,
    )
    args = parser.parse_args()
    PLACE = str(args.place).strip().upper()
    if not (len(PLACE) == 3 and PLACE.isalnum()):
        raise SystemExit(f"invalid place: {PLACE}")
    end = parse_date(args.end)
    start = months_ago(end + timedelta(days=1), args.months)
    test_start = months_ago(end + timedelta(days=1), args.test_months)
    valid_start = months_ago(test_start, args.valid_months)
    config = json.loads((ROOT / "config" / "course_signal_rules.json").read_text(encoding="utf-8"))
    records, pre_features, post_features = build_dataset(start, end, config)
    layout_change = date(2020, 10, 26) if PLACE == "BWK" else None
    source_min = LAST_DATASET_AUDIT.get("source_min_date")
    if layout_change is not None and source_min is not None and parse_date(source_min) < layout_change:
        raise RuntimeError(
            f"{PLACE} layout-change boundary breach: {source_min} < {layout_change.isoformat()}"
        )
    for row in records:
        row["split"] = "train" if row["date"] < valid_start else ("valid" if row["date"] < test_start else "test")
    split_races = {
        split: {row["race_code"] for row in records if row["split"] == split}
        for split in ("train", "valid", "test")
    }
    split_overlap = {
        "train_valid": len(split_races["train"] & split_races["valid"]),
        "train_test": len(split_races["train"] & split_races["test"]),
        "valid_test": len(split_races["valid"] & split_races["test"]),
    }

    report = {
        "status": "ok",
        "version": "venue-course-signal-zero-base-ml-point-in-time-v2",
        "place": PLACE,
        "place_name": PLACE_NAMES.get(PLACE, PLACE),
        "period": {
            "start": start.isoformat(),
            "end": end.isoformat(),
            "train_end": (valid_start - timedelta(days=1)).isoformat(),
            "valid_end": (test_start - timedelta(days=1)).isoformat(),
        },
        "runtime": {
            "python": sys.version.split()[0],
            "scikit_learn": sklearn.__version__,
            "numpy": np.__version__,
        },
        "races": len(records) // 6,
        "dataset_audit": LAST_DATASET_AUDIT,
        "layout_audit": {
            "layout_change_date": layout_change.isoformat() if layout_change else None,
            "source_min_date": source_min,
            "all_source_races_after_layout_change": (
                source_min is None or layout_change is None or parse_date(source_min) >= layout_change
            ),
        },
        "split_integrity": {
            "race_counts": {key: len(value) for key, value in split_races.items()},
            "race_code_overlap": split_overlap,
        },
        "feature_time_policy": {
            "exhibition_average": "same venue, previous 183 days, target date excluded",
            "same_day_history": "excluded for technique and vulnerability histories",
        },
        "feature_sets": {"pre": pre_features, "post": post_features},
        "courses": {},
    }
    for course in range(1, 7):
        print(f"{course}Cを監査中", flush=True)
        course_rows = [row for row in records if row["course"] == course]
        report["courses"][str(course)] = {
            target: evaluate_course_target(course_rows, target, pre_features, post_features, course)
            for target in TARGETS
        }
        for item in report["courses"][str(course)].values():
            item["classification"] = classify_candidate(item)
    output_prefix = args.output_prefix or (
        ROOT / "analysis" / "output" /
        f"{PLACE.lower()}_course_signal_zero_base_ml_point_in_time_{end.strftime('%Y%m%d')}"
    )
    json_path = output_prefix.with_suffix(".json")
    markdown_path = output_prefix.with_suffix(".md")
    json_path.parent.mkdir(parents=True, exist_ok=True)
    json_path.write_text(json.dumps(report, ensure_ascii=False, indent=2, default=str) + "\n", encoding="utf-8")
    write_markdown(report, markdown_path)
    print(f"JSON: {json_path}")
    print(f"REPORT: {markdown_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
