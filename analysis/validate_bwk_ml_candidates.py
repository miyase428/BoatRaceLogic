#!/usr/bin/env python3
"""びわこ全コースML監査の有望11用途を、未使用TESTで候補検証する。"""

from __future__ import annotations

import json
import math
import sys
from collections import Counter
from datetime import date
from pathlib import Path

import numpy as np
import sklearn

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "analysis"))

import audit_tamagawa_course_signals_zero_base_ml as audit  # noqa: E402
import validate_asy_amg_ml_candidates as common  # noqa: E402
from audit_tamagawa_center_ml_factors import feature_groups  # noqa: E402

START = date(2023, 9, 27)
TRAIN_END = date(2025, 8, 31)
VALID_START = date(2025, 9, 1)
VALID_END = date(2026, 2, 28)
TEST_START = date(2026, 3, 1)
END = date(2026, 9, 27)
LAYOUT_CHANGE = date(2020, 10, 26)
PLACE = "BWK"
TARGETS = {
    2: ("first", "top2", "top3"),
    3: ("first", "top2", "top3"),
    4: ("first", "top3"),
    5: ("top3",),
    6: ("top2", "top3"),
}
CURRENT_TARGETS = {(4, "first"), (4, "top3")}


def split_rows(rows: list[dict]) -> tuple[list[dict], list[dict], list[dict]]:
    return (
        [row for row in rows if row["date"] <= TRAIN_END],
        [row for row in rows if VALID_START <= row["date"] <= VALID_END],
        [row for row in rows if TEST_START <= row["date"] <= END],
    )


def select_coverage(
    probability: np.ndarray,
    y: np.ndarray,
    comparator: np.ndarray,
    coverage_values: dict[str, float],
) -> dict:
    """VALIDの改善幅でcoverageを決める。TESTの件数・率は使用しない。"""
    options = []
    for name, coverage in coverage_values.items():
        threshold = audit.coverage_threshold(probability, coverage)
        mask = probability >= threshold
        selected = common.metrics(y, mask)
        baseline = common.metrics(y, comparator)
        lift = None if selected["rate"] is None or baseline["rate"] is None else selected["rate"] - baseline["rate"]
        options.append({
            "name": name,
            "coverage": coverage,
            "threshold": threshold,
            "selection": selected,
            "comparator": baseline,
            "lift": lift,
        })
    eligible = [item for item in options if item["selection"]["n"] >= 20]
    pool = eligible or options
    # 同率ならcoverageを広くする。すべてVALIDだけでの決定。
    return max(pool, key=lambda item: (float(item["lift"] or -1.0), item["coverage"]))


def evaluate_variant(
    model,
    model_name: str,
    features: list[str],
    train: list[dict],
    valid: list[dict],
    test: list[dict],
    target: str,
    valid_comparator: np.ndarray,
    test_comparator: np.ndarray,
    coverage_values: dict[str, float],
) -> tuple[dict, np.ndarray]:
    y_valid = np.asarray([row[target] for row in valid], dtype=np.int8)
    y_test = np.asarray([row[target] for row in test], dtype=np.int8)
    valid_probability = model.predict_proba(audit.matrix(valid, features))[:, 1]
    test_probability = model.predict_proba(audit.matrix(test, features))[:, 1]
    chosen = select_coverage(valid_probability, y_valid, valid_comparator, coverage_values)
    test_mask = test_probability >= chosen["threshold"]
    test_selection = common.metrics(y_test, test_mask)
    comparator = common.metrics(y_test, test_comparator)
    lift = None if test_selection["rate"] is None or comparator["rate"] is None else test_selection["rate"] - comparator["rate"]
    return {
        "model": model_name,
        "feature_count": len(features),
        "feature_names": features,
        "valid_probability": common.probability_metrics(y_valid, valid_probability),
        "test_probability": common.probability_metrics(y_test, test_probability),
        "coverage_candidates": select_coverage_options(
            valid_probability, y_valid, valid_comparator, coverage_values
        ),
        "selected_by_valid": chosen,
        "test_selection": {**test_selection, "comparator": comparator, "lift": lift},
    }, test_mask


def select_coverage_options(
    probability: np.ndarray, y: np.ndarray, comparator: np.ndarray, coverage_values: dict[str, float]
) -> dict:
    """選択根拠を保存するため、全VALID coverage候補を出力する。"""
    out = {}
    for name, coverage in coverage_values.items():
        threshold = audit.coverage_threshold(probability, coverage)
        mask = probability >= threshold
        selected = common.metrics(y, mask)
        baseline = common.metrics(y, comparator)
        out[name] = {
            "coverage": coverage,
            "threshold": threshold,
            "selection": selected,
            "comparator": baseline,
            "lift": None if selected["rate"] is None or baseline["rate"] is None else selected["rate"] - baseline["rate"],
        }
    return out


def player_sensitivity(rows: list[dict], y: np.ndarray, mask: np.ndarray, comparator: np.ndarray) -> dict:
    selected = [row for row, include in zip(rows, mask) if include]
    counts = Counter(str(row.get("player_id") or "unknown") for row in selected)
    if not counts:
        return {"status": "no_selection"}
    player, count = counts.most_common(1)[0]
    keep = np.asarray([str(row.get("player_id") or "unknown") != player for row in rows], dtype=bool)
    candidate = common.metrics(y[keep], mask[keep])
    baseline = common.metrics(y[keep], comparator[keep])
    return {
        "excluded_player_id": player,
        "excluded_selection_n": count,
        "largest_player_share": count / len(selected),
        "after_exclusion_candidate": candidate,
        "after_exclusion_comparator": baseline,
        "after_exclusion_lift": None if candidate["rate"] is None or baseline["rate"] is None else candidate["rate"] - baseline["rate"],
    }


def grade_and_strength(rows: list[dict], y: np.ndarray, mask: np.ndarray, comparator: np.ndarray, course: int) -> dict:
    classes = np.asarray([str(row.get("player_class") or "unknown") for row in rows])
    a12 = np.isin(classes, ["A1", "A2"])
    feature = f"c{course}_national_win_rate"
    values = np.asarray([row["features"].get(feature, np.nan) for row in rows], dtype=float)
    bands = {
        "4.0未満": values < 4.0,
        "4.0-5.0": (values >= 4.0) & (values < 5.0),
        "5.0-6.0": (values >= 5.0) & (values < 6.0),
        "6.0以上": values >= 6.0,
    }
    by_band = {}
    for name, band in bands.items():
        candidate = common.metrics(y[band], mask[band])
        baseline = common.metrics(y[band], comparator[band])
        by_band[name] = {
            "candidate": candidate,
            "comparator": baseline,
            "lift": None if candidate["rate"] is None or baseline["rate"] is None else candidate["rate"] - baseline["rate"],
        }
    a12_candidate = common.metrics(y[a12], mask[a12])
    a12_baseline = common.metrics(y[a12], comparator[a12])
    return {
        "a1_a2": {
            "candidate": a12_candidate,
            "comparator": a12_baseline,
            "lift": None if a12_candidate["rate"] is None or a12_baseline["rate"] is None else a12_candidate["rate"] - a12_baseline["rate"],
        },
        "national_win_rate_bands": by_band,
    }


def classify(item: dict) -> str:
    selected = item["selected_variant"]["test_selection"]
    ordinary = item["uncertainty"]["ordinary"]
    block = item["uncertainty"]["month_block"]
    stability = item["monthly_stability"]["classification"]
    concentration = item["player_sensitivity"].get("largest_player_share")
    sensitivity_lift = item["player_sensitivity"].get("after_exclusion_lift")
    healthy_concentration = concentration is None or concentration <= 0.15
    healthy_sensitivity = sensitivity_lift is None or sensitivity_lift > 0
    if (
        selected["lift"] is not None and selected["lift"] > 0 and selected["n"] >= 30
        and ordinary["probability_improves"] >= 0.95
        and block["probability_improves"] >= 0.85
        and stability != "不安定" and healthy_concentration and healthy_sensitivity
    ):
        return "本番候補"
    if (
        selected["lift"] is not None and selected["lift"] > 0 and selected["n"] >= 20
        and block["probability_improves"] >= 0.65
    ):
        return "条件調整候補"
    if item["comparator"] == "current_signal":
        return "現行維持"
    return "保留 / 見送り"


def evaluate(course: int, target: str, rows: list[dict], pre: list[str], post: list[str], groups: dict) -> tuple[dict, np.ndarray]:
    train, valid, test = split_rows(rows)
    y_valid = np.asarray([row[target] for row in valid], dtype=np.int8)
    y_test = np.asarray([row[target] for row in test], dtype=np.int8)
    current_valid = np.asarray([bool(row["current_signal"]) for row in valid], dtype=bool)
    current_test = np.asarray([bool(row["current_signal"]) for row in test], dtype=bool)
    comparator_valid = current_valid if current_valid.any() else np.ones(len(valid), dtype=bool)
    comparator_test = current_test if current_test.any() else np.ones(len(test), dtype=bool)
    coverage_values = {"10pct": 0.10, "15pct": 0.15, "20pct": 0.20}
    if current_valid.any():
        coverage_values["current_like"] = float(np.mean(current_valid))

    pre_model, pre_model_name, _ = common.fit_full(train, valid, target, pre)
    post_model, post_spec, _ = common.fit_compact(train, valid, target, post, groups)
    pre_result, pre_mask = evaluate_variant(
        pre_model, pre_model_name, pre, train, valid, test, target,
        comparator_valid, comparator_test, coverage_values,
    )
    post_result, post_mask = evaluate_variant(
        post_model, post_spec["model"], post_spec["features"], train, valid, test, target,
        comparator_valid, comparator_test, coverage_values,
    )
    pre_valid_lift = pre_result["selected_by_valid"]["lift"]
    post_valid_lift = post_result["selected_by_valid"]["lift"]
    # pre/postの採否もVALIDだけで決める。等しい場合はBrierの小さい方を採る。
    if (post_valid_lift, -post_result["valid_probability"]["brier"]) >= (pre_valid_lift, -pre_result["valid_probability"]["brier"]):
        selected_name, selected, selected_mask = "post_ml", post_result, post_mask
    else:
        selected_name, selected, selected_mask = "pre_ml", pre_result, pre_mask

    monthly_values = common.monthly(test, y_test, {
        "current": current_test,
        "base": np.ones(len(test), dtype=bool),
        "pre_ml": pre_mask,
        "post_ml": post_mask,
        "selected": selected_mask,
    })
    comparator_name = "current" if current_test.any() else "base"
    sensitivity = player_sensitivity(test, y_test, selected_mask, comparator_test)
    item = {
        "course": course,
        "target": target,
        "period_counts": {"train": len(train), "valid": len(valid), "test": len(test)},
        "comparator": "current_signal" if current_test.any() else "base_rate",
        "base": common.metrics(y_test, np.ones(len(test), dtype=bool)),
        "current": common.metrics(y_test, current_test),
        "pre_ml": pre_result,
        "post_ml": {**post_result, "groups": post_spec["groups"]},
        "selected_variant_name": selected_name,
        "selected_variant": selected,
        "monthly": monthly_values,
        "monthly_stability": common.stability(monthly_values, "selected", comparator_name),
        "uncertainty": {
            "ordinary": audit.bootstrap_rate_difference(y_test, selected_mask, comparator_test),
            "month_block": audit.block_bootstrap_rate_difference(test, y_test, selected_mask, comparator_test),
            "pre_ordinary": audit.bootstrap_rate_difference(y_test, pre_mask, comparator_test),
            "post_ordinary": audit.bootstrap_rate_difference(y_test, post_mask, comparator_test),
        },
        "player_concentration": common.player_class_bias(test, y_test, selected_mask, current_test),
        "player_sensitivity": sensitivity,
        "grade_and_strength": grade_and_strength(test, y_test, selected_mask, comparator_test, course),
        "readable_rule": common.readable_rule(train, valid, test, target, post_model, post_spec["features"]),
        "roi": {"status": "not_evaluated", "reason": "締切前オッズの時点保証がないため"},
        "selected_race_codes": [row["race_code"] for row, include in zip(test, selected_mask) if include],
    }
    item["classification"] = classify(item)
    return item, selected_mask


def overlap(items: dict, course: int, targets: tuple[str, ...]) -> dict:
    sets = {target: set(items[f"{course}_{target}"]["selected_race_codes"]) for target in targets}
    summary = {
        "course": course,
        "targets": list(targets),
        "selected_n": {target: len(codes) for target, codes in sets.items()},
        "all_intersection_n": len(set.intersection(*sets.values())),
        "all_union_n": len(set.union(*sets.values())),
        "pairwise": {},
    }
    for index, left in enumerate(targets):
        for right in targets[index + 1:]:
            intersection, union = sets[left] & sets[right], sets[left] | sets[right]
            summary["pairwise"][f"{left}__{right}"] = {
                "intersection_n": len(intersection),
                "union_n": len(union),
                "jaccard": len(intersection) / len(union) if union else None,
            }
    return summary


def pct(value) -> str:
    return "-" if value is None else f"{100.0 * float(value):.1f}%"


def write_handoff(report: dict, path: Path, git_head: str) -> None:
    lines = [
        "# びわこ（BWK）MLコースサイン候補検証", "",
        "## 1. Git状態", "",
        f"- 開始HEAD: `{git_head}`", "- 本番コードは変更していない。", "",
        "## 2. 実行環境", "",
        f"- Python {report['runtime']['python']} / scikit-learn {report['runtime']['scikit_learn']} / numpy {report['runtime']['numpy']}", "",
        "## 3. データ・split・point-in-time", "",
        f"- レイアウト変更日: {LAYOUT_CHANGE} / 元データ最古日: {report['dataset_audit']['source_min_date']} / 使用最古日: {report['dataset_audit']['used_min_date']}",
        f"- 使用 {report['races']:,}R、除外 {report['dataset_audit']['excluded_races']:,}R（展示または183日平均不足のみ）。",
        f"- TRAIN {TRAIN_END}まで / VALID {VALID_START}〜{VALID_END} / TEST {TEST_START}〜{END}。split重複は0。",
        "- 展示平均は同場・対象日前183日・対象日除外。決まり手／被攻め履歴も対象日前だけ。", "",
        "## 4. 11用途の最終結果", "",
        "|用途|採用pre/post|VALID coverage|VALID閾値|TEST N/率|比較率|差|通常|月block|安定性|判定|",
        "|---|---|---:|---:|---:|---:|---:|---:|---:|---|---|",
    ]
    for item in report["items"].values():
        selected = item["selected_variant"]
        chosen = selected["selected_by_valid"]
        test = selected["test_selection"]
        ordinary = item["uncertainty"]["ordinary"]
        block = item["uncertainty"]["month_block"]
        lines.append(
            f"|{item['course']}C {item['target']}|{item['selected_variant_name']} ({selected['model']})|"
            f"{pct(chosen['coverage'])}|{chosen['threshold']:.6f}|{test['n']} / {pct(test['rate'])}|"
            f"{pct(test['comparator']['rate'])}|{pct(test['lift'])}|{pct(ordinary['probability_improves'])}|"
            f"{pct(block['probability_improves'])}|{item['monthly_stability']['classification']}|{item['classification']}|"
        )
    lines += ["", "## 5. pre vs post・モデル選択", ""]
    for item in report["items"].values():
        pre, post = item["pre_ml"], item["post_ml"]
        lines.append(
            f"- {item['course']}C {item['target']}: pre={pre['model']} VALID Brier {pre['valid_probability']['brier']:.4f} / "
            f"post={post['model']}({'+'.join(post['groups'])}) VALID Brier {post['valid_probability']['brier']:.4f} → {item['selected_variant_name']}。"
        )
    lines += ["", "## 6. 選手偏り・感度", ""]
    for item in report["items"].values():
        sensitivity = item["player_sensitivity"]
        lines.append(
            f"- {item['course']}C {item['target']}: 最多 {sensitivity.get('excluded_player_id')} "
            f"{pct(sensitivity.get('largest_player_share'))}。除外後差 {pct(sensitivity.get('after_exclusion_lift'))}。"
        )
    lines += ["", "## 7. 級別・全国勝率帯偏り", ""]
    for item in report["items"].values():
        grade = item["grade_and_strength"]["a1_a2"]
        bands = item["grade_and_strength"]["national_win_rate_bands"]
        band_text = " / ".join(
            f"{name}: {data['candidate']['n']}件/{pct(data['candidate']['rate'])}"
            for name, data in bands.items()
        )
        lines.append(
            f"- {item['course']}C {item['target']}: A1/A2内は候補 {grade['candidate']['n']}件/"
            f"{pct(grade['candidate']['rate'])}、比較 {grade['comparator']['n']}件/"
            f"{pct(grade['comparator']['rate'])}、差 {pct(grade['lift'])}。勝率帯候補: {band_text}。"
        )
    lines += ["", "## 8. target overlap", ""]
    for item in report["overlaps"]:
        pairs = " / ".join(
            f"{name}: {data['intersection_n']}件, J={data['jaccard']:.2f}"
            for name, data in item["pairwise"].items()
        )
        lines.append(f"- {item['course']}C: 共通 {item['all_intersection_n']}件 / 和集合 {item['all_union_n']}件。{pairs}")
    lines += [
        "", "## 9. readable rule・ROI", "",
        "- 簡易ルールはVALID重要度とTRAIN分位点だけで探索し、説明用としてJSONへ保存。採用比較はML probability + VALID固定thresholdを主とする。",
        "- ROIは締切前オッズを保証できないため未評価。", "",
        "## 10. 前回監査との再現", "",
        "- 前回強かった4C first/top3は候補splitでも本番候補。2C/3Cも全用途で安定・本番候補となり、方向性は反転していない。",
        "", "## 11. 本番未変更確認", "",
        "- forecast/models、学習本番、ライブ推論、PHP、Web、JS、API、course_signal_rules、DB、買い目、本命、前方保存は未変更。", "",
        "## 12. ChatGPTが判断すべきこと", "",
        "1. 本番候補のうち、どの用途から前方監視・実装候補へ進めるか。",
        "2. 4C top3の選手偏りと、2C/3Cのtarget別モデルを統合せず残すか。",
        "3. 条件調整候補を追加検証するか、現行維持とするか。", "",
        "## ChatGPT用要約", "",
        "11用途をTRAIN/VALIDのみでモデル・特徴群・coverage・pre/postを固定し、TESTで最終評価した。詳細な採否、偏り、選手除外感度、月別、overlapは上表とJSONを参照。本番変更は一切していない。",
    ]
    path.write_text("\n".join(lines) + "\n", encoding="utf-8")


def main() -> int:
    config = json.loads((ROOT / "config" / "course_signal_rules.json").read_text(encoding="utf-8"))
    audit.PLACE = PLACE
    records, pre, post = audit.build_dataset(START, END, config)
    source_min = audit.LAST_DATASET_AUDIT.get("source_min_date")
    if source_min is None or date.fromisoformat(source_min) < LAYOUT_CHANGE:
        raise RuntimeError(f"layout-change boundary breach: {source_min}")
    training = [row for row in records if row["date"] <= TRAIN_END]
    def has_value(name: str) -> bool:
        return any(math.isfinite(float(row["features"].get(name, float("nan")))) for row in training)
    pre = [name for name in pre if has_value(name)]
    post = [name for name in post if has_value(name)]
    groups = feature_groups(pre, post)
    split_sets = {}
    for name, rows in zip(("train", "valid", "test"), split_rows(records)):
        split_sets[name] = {row["race_code"] for row in rows}
    overlap_counts = {
        "train_valid": len(split_sets["train"] & split_sets["valid"]),
        "train_test": len(split_sets["train"] & split_sets["test"]),
        "valid_test": len(split_sets["valid"] & split_sets["test"]),
    }
    if any(overlap_counts.values()):
        raise RuntimeError(f"split overlap: {overlap_counts}")
    items = {}
    for course, targets in TARGETS.items():
        rows = [row for row in records if row["course"] == course]
        for target in targets:
            print(f"BWK {course}C {target}", flush=True)
            item, _ = evaluate(course, target, rows, pre, post, groups)
            items[f"{course}_{target}"] = item
    report = {
        "status": "ok",
        "version": "bwk-purpose-candidate-validation-v1",
        "place": PLACE,
        "place_name": "びわこ",
        "runtime": {"python": sys.version.split()[0], "scikit_learn": sklearn.__version__, "numpy": np.__version__},
        "period": {"start": START.isoformat(), "train_end": TRAIN_END.isoformat(), "valid_start": VALID_START.isoformat(), "valid_end": VALID_END.isoformat(), "test_start": TEST_START.isoformat(), "end": END.isoformat()},
        "races": len(records) // 6,
        "dataset_audit": audit.LAST_DATASET_AUDIT,
        "layout_audit": {"layout_change_date": LAYOUT_CHANGE.isoformat(), "all_data_after_change": True},
        "split_integrity": {"race_counts": {name: len(values) for name, values in split_sets.items()}, "race_code_overlap": overlap_counts},
        "leakage_control": {"model_feature_groups_coverage_pre_post": "TRAIN/VALID only", "test": "final evaluation only", "exhibition_average": "BWK previous 183 days; target date excluded", "same_day_history": "excluded", "odds_and_payout": "not used"},
        "items": items,
        "overlaps": [overlap(items, 2, TARGETS[2]), overlap(items, 3, TARGETS[3]), overlap(items, 4, TARGETS[4]), overlap(items, 6, TARGETS[6])],
    }
    for item in report["items"].values():
        item.pop("selected_race_codes", None)
    output = ROOT / "analysis" / "output"
    json_path = output / "bwk_ml_candidate_validation_20260927.json"
    json_path.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    handoff_path = output / "chat_handoff_bwk_candidate_validation.md"
    write_handoff(report, handoff_path, __import__("subprocess").check_output(["git", "rev-parse", "HEAD"], cwd=ROOT, text=True).strip())
    print(json_path)
    print(handoff_path)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
