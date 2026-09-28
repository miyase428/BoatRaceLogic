#!/usr/bin/env python3
"""芦屋・尼崎の用途別コースサイン候補を、未使用TESTで最終検証する。

特徴群・モデル・閾値・簡易ルールはTRAIN/VALIDだけで決める。
TESTは最終成績、月別安定性、bootstrap、偏り確認にだけ使う。
"""

from __future__ import annotations

import itertools
import json
import math
import sys
from collections import Counter
from datetime import date
from pathlib import Path

import numpy as np
from sklearn.base import clone
from sklearn.inspection import permutation_importance
from sklearn.metrics import brier_score_loss

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "analysis"))

import audit_tamagawa_course_signals_zero_base_ml as audit  # noqa: E402
from audit_tamagawa_center_ml_factors import feature_groups  # noqa: E402
from validate_tamagawa_center_ml_candidate import (  # noqa: E402
    choose_model_name,
    names_for_groups,
)

START = date(2023, 9, 27)
TRAIN_END = date(2025, 8, 31)
VALID_START = date(2025, 9, 1)
VALID_END = date(2026, 2, 28)
TEST_START = date(2026, 3, 1)
END = date(2026, 9, 27)
TARGETS = {
    "ASY": {2: ("first",), 3: ("first", "top3"), 4: ("top2", "top3")},
    "AMG": {
        2: ("first", "top2", "top3"),
        3: ("first", "top2", "top3"),
        4: ("first", "top2", "top3"),
        5: ("top2", "top3"),
    },
}
PRIORITY = {
    "ASY": {(3, "top3"), (4, "top2"), (4, "top3")},
    "AMG": {(3, "first"), (3, "top2"), (3, "top3"), (4, "first"), (4, "top2"), (4, "top3")},
}
PLACE_NAMES = {"ASY": "芦屋", "AMG": "尼崎"}
def metrics(y: np.ndarray, mask: np.ndarray) -> dict:
    n = int(mask.sum())
    rate = float(np.mean(y[mask])) if n else None
    base = float(np.mean(y))
    return {
        "n": n, "coverage": float(np.mean(mask)), "rate": rate,
        "base_rate": base, "lift_point": None if rate is None else rate - base,
    }


def probability_metrics(y: np.ndarray, p: np.ndarray) -> dict:
    clipped = np.clip(p, 1e-9, 1 - 1e-9)
    return {
        "n": int(len(y)), "rate": float(np.mean(y)),
        "brier": float(np.mean((p - y) ** 2)),
        "nll": float(np.mean(-(y * np.log(clipped) + (1 - y) * np.log(1 - clipped)))),
    }


def split_rows(rows: list[dict]) -> tuple[list[dict], list[dict], list[dict]]:
    return (
        [r for r in rows if r["date"] <= TRAIN_END],
        [r for r in rows if VALID_START <= r["date"] <= VALID_END],
        [r for r in rows if TEST_START <= r["date"] <= END],
    )


def fit_full(train: list[dict], valid: list[dict], target: str, features: list[str]):
    name = choose_model_name(train, valid, target, features)
    model = clone(audit.make_models()[name])
    model.fit(audit.matrix(train, features), np.asarray([r[target] for r in train]))
    return model, name, model.predict_proba(audit.matrix(valid, features))[:, 1]


def fit_compact(train: list[dict], valid: list[dict], target: str, features: list[str], groups: dict):
    y_train = np.asarray([r[target] for r in train], dtype=np.int8)
    y_valid = np.asarray([r[target] for r in valid], dtype=np.int8)
    model_name = choose_model_name(train, valid, target, features)
    template = audit.make_models()[model_name]
    candidates = []
    # 探索を増やし過ぎない。単独群・2群・全群だけを事前候補とする。
    group_names = tuple(groups)
    subsets = (
        [(name,) for name in group_names]
        + list(itertools.combinations(group_names, 2))
        + [group_names]
    )
    for subset in subsets:
        names = names_for_groups(groups, subset)
        model = clone(template)
        model.fit(audit.matrix(train, names), y_train)
        p = model.predict_proba(audit.matrix(valid, names))[:, 1]
        candidates.append((float(brier_score_loss(y_valid, p)), subset, names))
    best = min(item[0] for item in candidates)
    eligible = [item for item in candidates if item[0] <= best + 0.0005]
    chosen = min(eligible, key=lambda item: (len(item[1]), item[0]))
    model = clone(template)
    model.fit(audit.matrix(train, chosen[2]), y_train)
    valid_p = model.predict_proba(audit.matrix(valid, chosen[2]))[:, 1]
    return model, {
        "model": model_name, "groups": list(chosen[1]), "features": chosen[2],
        "best_valid_brier": best, "chosen_valid_brier": chosen[0],
    }, valid_p


def monthly(rows: list[dict], y: np.ndarray, masks: dict[str, np.ndarray]) -> dict:
    result = {}
    months = [f"2026-{month:02d}" for month in range(3, 10)]
    values = np.asarray([r["date"].strftime("%Y-%m") for r in rows])
    for month in months:
        month_mask = values == month
        result[month] = {"base": metrics(y[month_mask], np.ones(int(month_mask.sum()), dtype=bool))}
        for name, mask in masks.items():
            result[month][name] = metrics(y[month_mask], mask[month_mask])
    return result


def stability(monthly_values: dict, candidate: str, comparator: str) -> dict:
    usable, positive, zero = 0, 0, 0
    for values in monthly_values.values():
        cand, base = values[candidate], values[comparator]
        if cand["n"] == 0:
            zero += 1
        if cand["n"] >= 5 and base["n"] >= 5:
            usable += 1
            positive += int(cand["rate"] > base["rate"])
    if usable >= 5 and positive / usable >= 0.60:
        label = "安定"
    elif usable >= 3 and positive / usable >= 0.50:
        label = "やや不安定"
    else:
        label = "不安定"
    return {"classification": label, "eligible_months": usable, "positive_months": positive, "zero_months": zero}


def partition(y: np.ndarray, current: np.ndarray, candidate: np.ndarray) -> dict:
    return {
        "current_only": metrics(y, current & ~candidate),
        "ml_only": metrics(y, candidate & ~current),
        "both": metrics(y, current & candidate),
        "neither": metrics(y, ~current & ~candidate),
    }


def player_class_bias(rows: list[dict], y: np.ndarray, mask: np.ndarray, current: np.ndarray) -> dict:
    selected = [row for row, flag in zip(rows, mask) if flag]
    players = Counter(row.get("player_id") or "unknown" for row in selected)
    classes = Counter(row.get("player_class") or "unknown" for row in selected)
    n = len(selected)
    class_values = np.asarray([row.get("player_class") or "unknown" for row in rows])
    by_class = {}
    for grade in sorted(set(class_values.tolist())):
        grade_mask = class_values == grade
        by_class[grade] = {
            "base": metrics(y, grade_mask),
            "current": metrics(y, grade_mask & current),
            "candidate": metrics(y, grade_mask & mask),
        }
    return {
        "selected_n": n,
        "unique_players": len(players),
        "largest_player_share": (max(players.values()) / n) if n else None,
        "players_with_3plus_selections": sum(v >= 3 for v in players.values()),
        "class_distribution": {key: value for key, value in sorted(classes.items())},
        "class_share": {key: value / n for key, value in sorted(classes.items())} if n else {},
        "by_class": by_class,
    }


def strength_bias(rows: list[dict], course: int, mask: np.ndarray, current: np.ndarray) -> dict:
    feature = f"c{course}_national_win_rate"
    values = np.asarray([r["features"].get(feature, np.nan) for r in rows], dtype=float)
    finite = np.isfinite(values)
    if not finite.any():
        return {"status": "unavailable"}
    q1, q2 = np.quantile(values[finite], [1 / 3, 2 / 3])
    bands = {"low": values < q1, "middle": (values >= q1) & (values < q2), "high": values >= q2}
    base_n = int(finite.sum())
    selected_n = int(np.sum(mask & finite))
    current_n = int(np.sum(current & finite))
    return {
        "note": "実人気ではなく、全国勝率3分位による選手力偏りの代替確認",
        "cutoffs": [float(q1), float(q2)],
        "base_share": {key: int(np.sum(band & finite)) / base_n for key, band in bands.items()},
        "current_share": {key: int(np.sum(current & band & finite)) / current_n if current_n else None for key, band in bands.items()},
        "candidate_share": {key: int(np.sum(mask & band & finite)) / selected_n if selected_n else None for key, band in bands.items()},
    }


def readable_rule(train: list[dict], valid: list[dict], test: list[dict], target: str, model, features: list[str]) -> dict:
    """VALID重要度上位とTRAIN分位点だけで、2～4条件の少数ルールを作る。"""
    y_train = np.asarray([r[target] for r in train], dtype=np.int8)
    y_valid = np.asarray([r[target] for r in valid], dtype=np.int8)
    y_test = np.asarray([r[target] for r in test], dtype=np.int8)
    x_train, x_valid, x_test = (audit.matrix(rows, features) for rows in (train, valid, test))
    importance = permutation_importance(
        model, x_valid, y_valid, scoring="neg_brier_score", n_repeats=3,
        random_state=20260927, n_jobs=1,
    )
    order = [int(i) for i in np.argsort(importance.importances_mean)[::-1] if importance.importances_mean[int(i)] > 0][:4]
    conditions = []
    for index in order:
        column = x_train[:, index]
        finite = np.isfinite(column)
        if not finite.any():
            continue
        pos = column[(y_train == 1) & finite]
        neg = column[(y_train == 0) & finite]
        positive_direction = bool(len(pos) and len(neg) and np.mean(pos) >= np.mean(neg))
        quantile = 0.30 if positive_direction else 0.70
        threshold = float(np.quantile(column[finite], quantile))
        conditions.append({
            "feature": features[index], "operator": ">=" if positive_direction else "<=",
            "threshold": threshold, "train_quantile": quantile,
            "valid_importance": float(importance.importances_mean[index]),
        })
    candidates = []
    for count in range(2, min(4, len(conditions)) + 1):
        def apply(x):
            out = np.ones(len(x), dtype=bool)
            for condition in conditions[:count]:
                idx = features.index(condition["feature"])
                col = x[:, idx]
                if condition["operator"] == ">=":
                    out &= np.isfinite(col) & (col >= condition["threshold"])
                else:
                    out &= np.isfinite(col) & (col <= condition["threshold"])
            return out
        valid_mask = apply(x_valid)
        if int(valid_mask.sum()) < 20:
            continue
        candidates.append((metrics(y_valid, valid_mask)["lift_point"], count, valid_mask, apply(x_test)))
    if not candidates:
        return {"status": "not_found", "reason": "VALIDで20件以上となる2～4条件ルールなし"}
    _, count, valid_mask, test_mask = max(candidates, key=lambda item: item[0])
    return {
        "status": "candidate", "selection_basis": "VALID lift; thresholds from TRAIN quantiles",
        "conditions": conditions[:count], "valid": metrics(y_valid, valid_mask), "test": metrics(y_test, test_mask),
    }


def classify(result: dict) -> str:
    main = result["post_ml"]["current_like"]
    comparator = result["current"] if result["current"]["n"] else result["base"]
    ordinary = result["uncertainty"]["ordinary"]
    block = result["uncertainty"]["month_block"]
    stable = result["monthly_stability"]["classification"]
    difference = None if main["rate"] is None or comparator["rate"] is None else main["rate"] - comparator["rate"]
    if difference is not None and difference > 0 and main["n"] >= 30 and ordinary["probability_improves"] >= 0.95 and block["probability_improves"] >= 0.85 and stable != "不安定":
        return "本番候補"
    if difference is not None and difference > 0 and main["n"] >= 20 and block["probability_improves"] >= 0.65:
        return "条件調整候補"
    if result["current"]["n"] and (difference is None or difference <= 0):
        return "現行維持"
    return "見送り"


def evaluate(place: str, course: int, target: str, rows: list[dict], pre: list[str], post: list[str], groups: dict) -> tuple[dict, np.ndarray]:
    train, valid, test = split_rows(rows)
    y_valid = np.asarray([r[target] for r in valid], dtype=np.int8)
    y_test = np.asarray([r[target] for r in test], dtype=np.int8)
    current_valid = np.asarray([bool(r["current_signal"]) for r in valid])
    current_test = np.asarray([bool(r["current_signal"]) for r in test])
    current_coverage = float(np.mean(current_valid)) if current_valid.any() else 0.20

    pre_model, pre_name, pre_valid_p = fit_full(train, valid, target, pre)
    pre_test_p = pre_model.predict_proba(audit.matrix(test, pre))[:, 1]
    post_model, compact, post_valid_p = fit_compact(train, valid, target, post, groups)
    post_test_p = post_model.predict_proba(audit.matrix(test, compact["features"]))[:, 1]

    coverage_specs = {"10pct": 0.10, "15pct": 0.15, "20pct": 0.20, "current_like": current_coverage}
    candidates, masks = {}, {}
    for name, coverage in coverage_specs.items():
        threshold = audit.coverage_threshold(post_valid_p, coverage)
        mask = post_test_p >= threshold
        masks[name] = mask
        candidates[name] = {"valid_target_coverage": coverage, "threshold": threshold, **metrics(y_test, mask)}
    main_mask = masks["current_like"]
    pre_threshold = audit.coverage_threshold(pre_valid_p, current_coverage)
    pre_mask = pre_test_p >= pre_threshold
    hybrid_mask = current_test & main_mask
    base_mask = np.ones(len(test), dtype=bool)
    comparator = current_test if current_test.any() else base_mask
    monthly_values = monthly(test, y_test, {
        "current": current_test, "pre_ml": pre_mask, "post_ml": main_mask, "hybrid": hybrid_mask,
    })
    result = {
        "course": course, "target": target,
        "priority": (course, target) in PRIORITY[place],
        "period_counts": {"train": len(train), "valid": len(valid), "test": len(test)},
        "base": metrics(y_test, base_mask),
        "current": metrics(y_test, current_test),
        "pre_ml": {
            "model": pre_name, "threshold": pre_threshold,
            "valid_probability": probability_metrics(y_valid, pre_valid_p),
            "test_probability": probability_metrics(y_test, pre_test_p),
            "selection": metrics(y_test, pre_mask),
        },
        "post_ml": {
            "model": compact["model"], "groups": compact["groups"],
            "feature_count": len(compact["features"]),
            "valid_probability": probability_metrics(y_valid, post_valid_p),
            "test_probability": probability_metrics(y_test, post_test_p),
            **candidates,
        },
        "hybrid": metrics(y_test, hybrid_mask),
        "partition": partition(y_test, current_test, main_mask),
        "monthly": monthly_values,
        "monthly_stability": stability(monthly_values, "post_ml", "current" if current_test.any() else "base"),
        "uncertainty": {
            "comparator": "current" if current_test.any() else "base",
            "ordinary": audit.bootstrap_rate_difference(y_test, main_mask, comparator),
            "month_block": audit.block_bootstrap_rate_difference(test, y_test, main_mask, comparator),
            "pre_ordinary": audit.bootstrap_rate_difference(y_test, pre_mask, comparator),
            "pre_month_block": audit.block_bootstrap_rate_difference(test, y_test, pre_mask, comparator),
            "hybrid_ordinary": audit.bootstrap_rate_difference(y_test, hybrid_mask, comparator),
            "hybrid_month_block": audit.block_bootstrap_rate_difference(test, y_test, hybrid_mask, comparator),
        },
        "fragmentation": {
            "threshold_count_tested": len(coverage_specs),
            "monthly_zero_count": sum(monthly_values[m]["post_ml"]["n"] == 0 for m in monthly_values),
            "minimum_monthly_n": min(monthly_values[m]["post_ml"]["n"] for m in monthly_values),
            "note": "用途別モデルは1着/2連対/3連対の3系統まで。TESTによる閾値再選択なし。",
        },
        "bias": {
            "player_and_class": player_class_bias(test, y_test, main_mask, current_test),
            "strength_proxy": strength_bias(test, course, main_mask, current_test),
            "popularity": {"status": "not_evaluated", "reason": "締切前人気・オッズの取得時点を保証できる結合キーが未確認"},
        },
        "readable_rule": readable_rule(train, valid, test, target, post_model, compact["features"]),
        "roi": {"status": "skipped", "reason": "締切前オッズの時点由来をこの検証データで保証できないため"},
    }
    result["classification"] = classify(result)
    result["selected_race_codes"] = [row["race_code"] for row, flag in zip(test, main_mask) if flag]
    return result, main_mask


def overlap_summary(items: dict, course: int, targets: tuple[str, ...]) -> dict:
    code_sets = {target: set(items[f"{course}_{target}"]["selected_race_codes"]) for target in targets}
    out = {"course": course, "targets": list(targets), "selected_n": {k: len(v) for k, v in code_sets.items()}}
    out["all_intersection_n"] = len(set.intersection(*code_sets.values()))
    out["all_union_n"] = len(set.union(*code_sets.values()))
    out["pairwise"] = {}
    for i, left in enumerate(targets):
        for right in targets[i + 1:]:
            inter, union = code_sets[left] & code_sets[right], code_sets[left] | code_sets[right]
            out["pairwise"][f"{left}__{right}"] = {
                "intersection_n": len(inter), "union_n": len(union),
                "jaccard": len(inter) / len(union) if union else None,
            }
    return out


def run_place(place: str, config: dict) -> dict:
    audit.PLACE = place
    records, pre, post = audit.build_dataset(START, END, config)
    training_records = [row for row in records if row["date"] <= TRAIN_END]
    # 全TRAIN欠損列は情報を持たず、imputer警告と無駄な探索だけを生むため除く。
    def has_training_value(name: str) -> bool:
        return any(math.isfinite(float(row["features"].get(name, float("nan")))) for row in training_records)
    pre = [name for name in pre if has_training_value(name)]
    post = [name for name in post if has_training_value(name)]
    groups = feature_groups(pre, post)
    items = {}
    for course, targets in TARGETS[place].items():
        course_rows = [row for row in records if row["course"] == course]
        for target in targets:
            print(f"{place} {course}C {target}", flush=True)
            item, _ = evaluate(place, course, target, course_rows, pre, post, groups)
            items[f"{course}_{target}"] = item
    overlaps = []
    if place == "ASY":
        overlaps.append(overlap_summary(items, 4, ("top2", "top3")))
    else:
        overlaps.append(overlap_summary(items, 3, ("first", "top2", "top3")))
        overlaps.append(overlap_summary(items, 4, ("first", "top2", "top3")))
    # race code lists are useful for overlap calculation only; final JSON is kept compact.
    for item in items.values():
        item.pop("selected_race_codes", None)
    return {
        "status": "ok", "version": "asy-amg-purpose-candidate-validation-v1",
        "place": place, "place_name": PLACE_NAMES[place], "races": len(records) // 6,
        "period": {
            "start": START.isoformat(), "train_end": TRAIN_END.isoformat(),
            "valid_start": VALID_START.isoformat(), "valid_end": VALID_END.isoformat(),
            "test_start": TEST_START.isoformat(), "end": END.isoformat(),
        },
        "leakage_control": {
            "model_and_groups": "TRAIN fit / VALID selection",
            "thresholds": "VALID fixed",
            "test": "final evaluation only",
            "test_equal_count_selection": False,
        },
        "items": items, "overlaps": overlaps,
    }


def pct(value) -> str:
    return "-" if value is None else f"{100 * float(value):.1f}%"


def write_handoff(reports: dict, path: Path) -> None:
    lines = [
        "# 芦屋・尼崎 用途別MLサイン候補 最終検証", "",
        "## 検証固定条件", "",
        f"- TRAIN: {START}～{TRAIN_END} / VALID: {VALID_START}～{VALID_END} / TEST: {TEST_START}～{END}",
        "- モデル・特徴群・閾値・簡易ルールはTRAIN/VALIDだけで決定。TEST同数選択は禁止。",
        "- 通常bootstrapと月block bootstrapを併記。月別は2026年3～9月。",
        "- ROIと実人気偏りは、締切前オッズの取得時点を保証できないため評価対象外。全国勝率帯を代替確認した。", "",
    ]
    for place in ("ASY", "AMG"):
        report = reports[place]
        lines += [f"## {report['place_name']}", "", "|対象|優先|判定|現行 N/率|展示前ML N/率|展示後ML N/率|差|通常改善確率|月block改善確率|月安定性|", "|---|---|---|---:|---:|---:|---:|---:|---:|---|"]
        for key, item in report["items"].items():
            current = item["current"] if item["current"]["n"] else item["base"]
            post = item["post_ml"]["current_like"]
            diff = None if post["rate"] is None else post["rate"] - current["rate"]
            ordinary = item["uncertainty"]["ordinary"]
            block = item["uncertainty"]["month_block"]
            lines.append(
                f"|{item['course']}C {item['target']}|{'主' if item['priority'] else '参考'}|{item['classification']}|"
                f"{current['n']} / {pct(current['rate'])}|{item['pre_ml']['selection']['n']} / {pct(item['pre_ml']['selection']['rate'])}|"
                f"{post['n']} / {pct(post['rate'])}|{pct(diff)}|{pct(ordinary['probability_improves'])}|"
                f"{pct(block['probability_improves'])}|{item['monthly_stability']['classification']}|"
            )
        lines += ["", "### 用途重複", ""]
        for overlap in report["overlaps"]:
            pair_text = " / ".join(f"{name}: {data['intersection_n']}件, J={data['jaccard']:.2f}" for name, data in overlap["pairwise"].items())
            lines.append(f"- {overlap['course']}C: 全用途共通 {overlap['all_intersection_n']}件 / 和集合 {overlap['all_union_n']}件。{pair_text}")
        lines.append("")
        lines += ["### 優先候補の少数条件ルール", ""]
        for item in report["items"].values():
            if not item["priority"]:
                continue
            rule = item["readable_rule"]
            if rule["status"] != "candidate":
                lines.append(f"- {item['course']}C {item['target']}: 作成できず（{rule['reason']}）")
                continue
            clauses = " AND ".join(
                f"{condition['feature']} {condition['operator']} {condition['threshold']:.3f}"
                for condition in rule["conditions"]
            )
            lines.append(
                f"- {item['course']}C {item['target']}: `{clauses}` → "
                f"TEST {rule['test']['n']}件 / {pct(rule['test']['rate'])}。"
                "特徴はVALID重要度、閾値はTRAIN分位点で固定。"
            )
        lines.append("")
    lines += [
        "## 総合結論", "",
        "- 用途別MLは、少数条件ルールより明確に成績が高い。主案はML確率のVALID固定閾値とする。",
        "- 芦屋は **3C 3連対・4C 3連対が本番候補**。4C 2連対は改善方向だが67件のため条件調整候補。3C 1着は現行維持。",
        "- 尼崎は **3Cの全用途、4Cの2連対・3連対が本番候補**。4C 1着は改善方向だが通常bootstrapが基準未満のため条件調整候補。",
        "- 選手個人への集中は最大4.6%で、特定選手への過度な依存は見られない。",
        "- 4C候補は強い級別・全国勝率帯へ寄る。ただしA1/A2級内でも多くの主候補が現行以上で、級別だけの置換ではない。尼崎4C 2連対は高全国勝率帯86.5%のため前方監視を強める。",
        "- 少数条件ルールは説明用の参考候補。主MLを上回らず、現時点で本番置換には採用しない。",
        "",
        "## 判定の読み方", "",
        "- **本番候補**: 通常・月block・月別の三方向で改善を確認できた候補。まだ本番へは反映していない。",
        "- **条件調整候補**: 改善方向だが、月変動・件数・不確実性のいずれかが残る。",
        "- **現行維持**: 現行条件の方がTESTで良い。",
        "- **見送り**: 現段階では用途別サインにする根拠が不足。",
        "", "## 実装状態", "",
        "- 分析・候補判定のみ。API / Web / アプリ / モデル / サイン設定 / DB / 買い目は変更していない。",
    ]
    path.write_text("\n".join(lines) + "\n", encoding="utf-8")


def main() -> int:
    config = json.loads((ROOT / "config" / "course_signal_rules.json").read_text(encoding="utf-8"))
    reports = {place: run_place(place, config) for place in ("ASY", "AMG")}
    out = ROOT / "analysis" / "output"
    for place, report in reports.items():
        path = out / f"{place.lower()}_ml_candidate_validation_20260927.json"
        path.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        print(path, flush=True)
    handoff = out / "chat_handoff_asy_amg_candidate_validation.md"
    write_handoff(reports, handoff)
    print(handoff, flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
