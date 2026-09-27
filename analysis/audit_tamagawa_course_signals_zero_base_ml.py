#!/usr/bin/env python3
"""多摩川の全コースサインを、現行条件を前提にせず再監査する。

現行サインは比較対象にだけ使い、モデル特徴量には入れない。
対象レース以前に分かる一次情報と展示情報から、各コースの
1着・2連対・3連対を別々に学習する。
"""

from __future__ import annotations

import argparse
import json
import math
import sys
from collections import defaultdict
from datetime import date, timedelta
from pathlib import Path

import numpy as np
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
    load_avg_exhibition,
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
    rr.average_start
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
    print(f"多摩川データ読込 {start}～{end}", flush=True)
    races = load_prerace_targets(start, end, (PLACE,))
    player_ids = sorted({boat["player_id"] for race in races.values() for boat in race["boats"]})
    racer = load_racer_results(required_terms(start, end))
    history = TechniqueHistoryIndex(load_history(start, end, player_ids))
    vulnerability = VulnerabilityIndex(
        load_lane1_vulnerability_history(start, end, player_ids)
    )
    exhibition_by_race = load_exhibition(start, end, PLACE)
    average_exhibition = load_avg_exhibition(PLACE)
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
            records.append({
                "race_code": race_code,
                "date": race_date,
                "course": course,
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
    rows: list[dict], target: str, pre_features: list[str], post_features: list[str]
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

    result = {
        "target": target,
        "period_counts": {"train": len(train), "valid": len(valid), "test": len(test)},
        "base_test_rate": float(np.mean(post["y_test"])),
        "current": selection_metrics(post["y_test"], current_test),
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
        "monthly_test": monthly_selection(test, post["y_test"], selections),
        "uncertainty": {
            "pre_ml_vs_current_rate": bootstrap_rate_difference(
                post["y_test"], pre_selected, current_test
            ),
            "post_ml_vs_current_rate": bootstrap_rate_difference(
                post["y_test"], post_selected, current_test
            ),
            "hybrid_vs_current_rate": bootstrap_rate_difference(
                post["y_test"], hybrid_selected, current_test
            ),
            "post_vs_pre_brier": bootstrap_brier_difference(
                post["y_test"], pre["test_probability"], post["test_probability"]
            ),
        },
    }
    return result


def pct(value) -> str:
    return "-" if value is None else f"{100.0 * float(value):.2f}%"


def write_markdown(report: dict, path: Path) -> None:
    lines = [
        "# 多摩川 全コースサイン ゼロベースML監査",
        "",
        f"- 対象期間: {report['period']['start']}～{report['period']['end']}",
        f"- 学習: ～{report['period']['train_end']} / 検証: ～{report['period']['valid_end']} / 最終テスト: それ以降",
        f"- 使用レース: {report['races']:,}R",
        "- 現行サインは比較対象のみ。モデル特徴量には不使用。",
        "- ML選択数は、原則として現行サインと同程度のカバー率へ検証期間で固定。6Cは20%。",
        "",
    ]
    for course in range(1, 7):
        lines += [f"## {course}コース", ""]
        lines += ["|対象|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|", "|---|---:|---:|---:|---:|---:|"]
        for target in TARGETS:
            item = report["courses"][str(course)][target]
            lines.append(
                f"|{target}|{pct(item['base_test_rate'])}|"
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
    parser = argparse.ArgumentParser()
    parser.add_argument("--end", default=(date.today() - timedelta(days=1)).isoformat())
    parser.add_argument("--months", type=int, default=36)
    parser.add_argument("--valid-months", type=int, default=6)
    parser.add_argument("--test-months", type=int, default=6)
    parser.add_argument(
        "--output-prefix",
        type=Path,
        default=ROOT / "analysis" / "output" / "tamagawa_course_signal_zero_base_ml_20260926",
    )
    args = parser.parse_args()
    end = parse_date(args.end)
    start = months_ago(end + timedelta(days=1), args.months)
    test_start = months_ago(end + timedelta(days=1), args.test_months)
    valid_start = months_ago(test_start, args.valid_months)
    config = json.loads((ROOT / "config" / "course_signal_rules.json").read_text(encoding="utf-8"))
    records, pre_features, post_features = build_dataset(start, end, config)
    for row in records:
        row["split"] = "train" if row["date"] < valid_start else ("valid" if row["date"] < test_start else "test")

    report = {
        "status": "ok",
        "version": "tamagawa-course-signal-zero-base-ml-v1",
        "period": {
            "start": start.isoformat(),
            "end": end.isoformat(),
            "train_end": (valid_start - timedelta(days=1)).isoformat(),
            "valid_end": (test_start - timedelta(days=1)).isoformat(),
        },
        "races": len(records) // 6,
        "feature_sets": {"pre": pre_features, "post": post_features},
        "courses": {},
    }
    for course in range(1, 7):
        print(f"{course}Cを監査中", flush=True)
        course_rows = [row for row in records if row["course"] == course]
        report["courses"][str(course)] = {
            target: evaluate_course_target(course_rows, target, pre_features, post_features)
            for target in TARGETS
        }
    json_path = args.output_prefix.with_suffix(".json")
    markdown_path = args.output_prefix.with_suffix(".md")
    json_path.parent.mkdir(parents=True, exist_ok=True)
    json_path.write_text(json.dumps(report, ensure_ascii=False, indent=2, default=str) + "\n", encoding="utf-8")
    write_markdown(report, markdown_path)
    print(f"JSON: {json_path}")
    print(f"REPORT: {markdown_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
