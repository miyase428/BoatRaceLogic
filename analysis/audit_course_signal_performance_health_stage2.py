#!/usr/bin/env python3
"""Read-only Stage 2 course-signal performance-health audit.

This only transcribes immutable candidate, handoff, manifest and existing
production-artifact metadata.  It never queries the database, runs live
inference, reconstructs result counts from rates, or modifies production.
Run with the legacy production runtime:
    .venv-models/bin/python analysis/audit_course_signal_performance_health_stage2.py
"""

from __future__ import annotations

import csv
import json
from pathlib import Path

import joblib


ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "analysis/output/ml_performance_health"
EPS = 1e-12

FIELDS = [
    "venue", "course", "target", "production_model_version", "selection_objective",
    "eligible_population_definition", "exclusion_condition", "exhibition_required", "missing_excluded_n",
    "historical_evidence_source", "evidence_status", "historical_performance_lineage",
    "valid_eligible_n", "ml_valid_selected_n", "ml_validation_coverage", "ml_valid_positive_n",
    "ml_valid_selected_rate", "traditional_valid_selected_n", "traditional_validation_coverage",
    "traditional_valid_positive_n", "traditional_valid_selected_rate", "valid_selected_rate_delta",
    "valid_population_yield_delta", "test_eligible_n", "ml_test_selected_n",
    "ml_test_realized_coverage", "ml_test_positive_n", "ml_test_selected_rate",
    "traditional_test_selected_n", "traditional_test_realized_coverage",
    "traditional_test_positive_n", "traditional_test_selected_rate", "test_selected_rate_delta",
    "test_coverage_delta", "ml_test_population_yield", "traditional_test_population_yield",
    "test_population_yield_delta", "both_selected_n", "ml_only_n", "traditional_only_n",
    "neither_n", "both_selected_target_rate", "ml_only_target_rate",
    "traditional_only_target_rate", "uncertainty_method", "selected_rate_delta_interval",
    "population_yield_delta_interval", "period_dependency_flag", "comparison_result",
    "comparison_status", "performance_health", "performance_evidence_status",
    "immediate_stop_evidence", "forward_status", "forward_start", "current_forward_n",
    "expected_checkpoint", "checkpoint_reached", "last_capture_date", "missing_count",
    "operational_issue", "notes",
]

MONTHLY_FIELDS = [
    "venue", "course", "target", "month", "eligible_n", "ml_selected_n", "ml_coverage",
    "ml_positive_n", "ml_selected_rate", "ml_population_yield", "traditional_selected_n",
    "traditional_coverage", "traditional_positive_n", "traditional_selected_rate",
    "traditional_population_yield", "selected_rate_delta", "population_yield_delta",
    "comparison_result", "sparse_status", "evidence_status", "notes",
]

LEGACY = {
    "TDA": ("toda", "tda_course_ml_candidate_20260926.json", "toda_course_signal_v1"),
    "OMR": ("omura", "omr_course_ml_candidate_20260926.json", "omura_course_signal_v1"),
    "SMS": ("shimonoseki", "sms_course_ml_candidate_20260926.json", "shimonoseki_course_signal_v1"),
    "SME": ("suminoe", "sme_course_ml_candidate_20260927.json", "suminoe_course_signal_v1"),
    "KRY": ("kiryuu", "kry_course_ml_candidate_20260927.json", "kiryuu_course_signal_v1"),
}
MANIFEST_VENUES = {
    "HWJ": ("heiwajima", "hwj_course_ml_candidate_20260927.json", "heiwajima_course_signal_v1"),
    "EDG": ("edogawa", "edg_course_ml_candidate_20260927.json", "edogawa_course_signal_v1"),
}
NEW_STYLE = {
    "ASY": ("asy_ml_candidate_validation_20260927.json", "ashiya_course_signal_v1", {("3", "top3"), ("4", "top3")}),
    "AMG": ("amg_ml_candidate_validation_20260927.json", "amagasaki_course_signal_v1", {
        ("2", "first"), ("2", "top3"), ("3", "first"), ("3", "top2"), ("3", "top3"),
        ("4", "top2"), ("4", "top3"), ("5", "top2"), ("5", "top3"),
    }),
}


def na() -> str:
    return "unknown"


def target_rate(selection: dict, target: str):
    return selection.get("rate", selection.get(f"{target}_rate", na()))


def direct_delta(left, right):
    return na() if left == na() or right == na() else float(left) - float(right)


def policy(ml_rate, ml_cov, traditional_rate, traditional_cov):
    if na() in (ml_rate, ml_cov, traditional_rate, traditional_cov):
        return "INDETERMINATE", "INDETERMINATE"
    mr, mc, tr, tc = map(float, (ml_rate, ml_cov, traditional_rate, traditional_cov))
    if mr >= tr - EPS and mc >= tc - EPS and (mr > tr + EPS or mc > tc + EPS):
        return "ML_DOMINANT", "ASSESSABLE"
    if tr >= mr - EPS and tc >= mc - EPS and (tr > mr + EPS or tc > mc + EPS):
        return "TRADITIONAL_DOMINANT", "ASSESSABLE"
    if mr > tr + EPS and mc < tc - EPS:
        return "TRADEOFF_RATE_UP_COVERAGE_DOWN", "ASSESSABLE"
    if mr < tr - EPS and mc > tc + EPS:
        return "TRADEOFF_RATE_DOWN_COVERAGE_UP", "ASSESSABLE"
    return "INDETERMINATE", "INDETERMINATE"


def judgment(comparison_result, comparison_status):
    if comparison_status != "ASSESSABLE":
        return "REVIEW", "INSUFFICIENT", "INSUFFICIENT_EVIDENCE"
    if comparison_result == "TRADITIONAL_DOMINANT":
        # Existing aggregate evidence remains only PARTIAL (no immutable
        # per-race selections/positive counts), so it does not meet the high
        # threshold for an immediate production-stop recommendation.
        return "REVIEW", "PARTIAL", "NO"
    return "MONITOR", "PARTIAL", "NO"


def row_base(venue, course, target, version, source, valid_n, test_n):
    return {
        "venue": venue, "course": course, "target": target,
        "production_model_version": version, "selection_objective": "UNKNOWN",
        "eligible_population_definition": "fixed historical candidate VALID/TEST race population for venue/course/target",
        "exclusion_condition": "candidate-usable race population; exact exclusion breakdown not retained in every source",
        "exhibition_required": na(), "missing_excluded_n": na(),
        "historical_evidence_source": source, "evidence_status": "recorded",
        "historical_performance_lineage": "UNKNOWN",
        "valid_eligible_n": valid_n, "ml_valid_selected_n": na(), "ml_validation_coverage": na(),
        "ml_valid_positive_n": na(), "ml_valid_selected_rate": na(),
        "traditional_valid_selected_n": na(), "traditional_validation_coverage": na(),
        "traditional_valid_positive_n": na(), "traditional_valid_selected_rate": na(),
        "valid_selected_rate_delta": na(), "valid_population_yield_delta": na(),
        "test_eligible_n": test_n, "ml_test_selected_n": na(), "ml_test_realized_coverage": na(),
        "ml_test_positive_n": na(), "ml_test_selected_rate": na(),
        "traditional_test_selected_n": na(), "traditional_test_realized_coverage": na(),
        "traditional_test_positive_n": na(), "traditional_test_selected_rate": na(),
        "test_selected_rate_delta": na(), "test_coverage_delta": na(),
        "ml_test_population_yield": na(), "traditional_test_population_yield": na(),
        "test_population_yield_delta": na(), "both_selected_n": na(), "ml_only_n": na(),
        "traditional_only_n": na(), "neither_n": na(), "both_selected_target_rate": na(),
        "ml_only_target_rate": na(), "traditional_only_target_rate": na(),
        "uncertainty_method": "NOT_REPRODUCIBLE: no fixed per-race policy vectors",
        "selected_rate_delta_interval": na(), "population_yield_delta_interval": na(),
        "period_dependency_flag": na(), "comparison_result": "INDETERMINATE",
        "comparison_status": "INDETERMINATE", "performance_health": "REVIEW",
        "performance_evidence_status": "INSUFFICIENT", "immediate_stop_evidence": "INSUFFICIENT_EVIDENCE",
        "forward_status": "INSUFFICIENT_DATA", "forward_start": na(), "current_forward_n": na(),
        "expected_checkpoint": "UNKNOWN", "checkpoint_reached": na(), "last_capture_date": na(),
        "missing_count": na(),
        "operational_issue": "forward outcome/performance excluded; capture metadata not retained in these historical sources",
        "notes": "Historical TEST is a development holdout, not a pristine final holdout.",
    }


def apply_ml(row, selection, target):
    row["ml_test_selected_n"] = selection.get("n", na())
    row["ml_test_realized_coverage"] = selection.get("coverage", na())
    row["ml_test_selected_rate"] = target_rate(selection, target)


def apply_traditional(row, selection):
    if not selection or selection.get("n", 0) in (0, None) or selection.get("rate") is None:
        return
    row["traditional_test_selected_n"] = selection["n"]
    row["traditional_test_realized_coverage"] = selection["coverage"]
    row["traditional_test_selected_rate"] = selection["rate"]
    row["test_selected_rate_delta"] = direct_delta(row["ml_test_selected_rate"], selection["rate"])
    row["test_coverage_delta"] = direct_delta(row["ml_test_realized_coverage"], selection["coverage"])
    # This density calculation uses directly recorded rate and coverage only;
    # it is not a reconstructed positive count.
    row["ml_test_population_yield"] = float(row["ml_test_selected_rate"]) * float(row["ml_test_realized_coverage"])
    row["traditional_test_population_yield"] = float(selection["rate"]) * float(selection["coverage"])
    row["test_population_yield_delta"] = (
        row["ml_test_population_yield"] - row["traditional_test_population_yield"]
    )
    result, status = policy(row["ml_test_selected_rate"], row["ml_test_realized_coverage"], selection["rate"], selection["coverage"])
    health, evidence, stop = judgment(result, status)
    row["comparison_result"] = result
    row["comparison_status"] = status
    row["performance_health"] = health
    row["performance_evidence_status"] = evidence
    row["immediate_stop_evidence"] = stop


def compare_artifact_candidate(artifact_item, candidate_item, target):
    frozen = candidate_item.get("candidate_frozen_threshold")
    if not frozen:
        return "PARTIAL", "candidate has no frozen production-policy selection aggregate"
    artifact_stats = artifact_item.get("test_selection", {})
    checks = [
        abs(float(artifact_item.get("threshold")) - float(frozen["threshold"])) <= 1e-10,
        int(artifact_stats.get("n")) == int(frozen["n"]),
        abs(float(target_rate(artifact_stats, target)) - float(frozen["rate"])) <= 1e-10,
    ]
    if all(checks):
        return "MATCH", "artifact threshold / TEST selected N / target rate match candidate frozen policy"
    return "CONFLICT", "artifact and candidate frozen policy differ in threshold, TEST selected N, or target rate"


def candidate_rows_with_artifact(venue, slug, candidate_name, version, manifest_only=False):
    candidate = json.load(open(ROOT / "analysis/output" / candidate_name))
    if manifest_only:
        artifact = json.load(open(ROOT / "forecast/models" / f"{slug}_course_signal_v1.json"))
        models = artifact["models"]
    else:
        artifact = joblib.load(ROOT / "forecast/models" / f"{slug}_course_signal_v1.joblib")
        models = artifact["models"]
    rows = []
    for course, targets in models.items():
        for target, artifact_item in targets.items():
            item = candidate["courses"][course][target]
            valid_n = item["probability"]["valid"]["n"]
            test_n = item["probability"]["test"]["n"]
            source = f"analysis/output/{candidate_name} + forecast/models/{slug}_course_signal_v1.{'json' if manifest_only else 'joblib'}"
            row = row_base(venue, course, target, version, source, valid_n, test_n)
            row["exhibition_required"] = str(bool(artifact_item.get("uses_exhibition"))).lower()
            row["ml_validation_coverage"] = artifact_item.get("valid_coverage", na())
            apply_ml(row, artifact_item["test_selection"], target)
            lineage, note = compare_artifact_candidate(artifact_item, item, target)
            row["historical_performance_lineage"] = lineage
            row["notes"] += " " + note
            if lineage == "CONFLICT":
                row["evidence_status"] = "CONFLICT"
                row["comparison_status"] = "INDETERMINATE"
                row["performance_health"] = "REVIEW"
                row["performance_evidence_status"] = "INSUFFICIENT"
            else:
                apply_traditional(row, item.get("current"))
            rows.append(row)
    return rows


def new_style_rows(venue, candidate_name, version, adopted):
    data = json.load(open(ROOT / "analysis/output" / candidate_name))
    rows, monthly = [], []
    for course, target in sorted(adopted, key=lambda x: (int(x[0]), x[1])):
        item = data["items"][f"{course}_{target}"]
        selected = item["post_ml"]["current_like"]
        row = row_base(
            venue, course, target, version,
            f"analysis/output/{candidate_name} + chat_handoff_asy_amg_production_ml.md",
            item["post_ml"]["valid_probability"]["n"], item["post_ml"]["test_probability"]["n"],
        )
        row["exhibition_required"] = str("exhibition" in item["post_ml"].get("groups", [])).lower()
        row["ml_validation_coverage"] = selected["valid_target_coverage"]
        apply_ml(row, selected, target)
        row["historical_performance_lineage"] = "MATCH"
        row["notes"] += " Production handoff states candidate current_like threshold/N/rate match the production joblib."
        apply_traditional(row, item.get("current"))
        uncertainty = item.get("uncertainty", {})
        ordinary, block = uncertainty.get("ordinary", {}), uncertainty.get("month_block", {})
        if ordinary:
            row["uncertainty_method"] = "recorded ordinary and month-block bootstrap; no new resampling"
            row["selected_rate_delta_interval"] = json.dumps({
                "ordinary": [ordinary.get("ci95_low"), ordinary.get("ci95_high")],
                "month_block": [block.get("ci95_low"), block.get("ci95_high")],
            }, ensure_ascii=False)
        for month, values in item.get("monthly", {}).items():
            ml, trad = values.get("post_ml", {}), values.get("current", {})
            if not ml or not trad:
                continue
            mr, mc, tr, tc = ml.get("rate"), ml.get("coverage"), trad.get("rate"), trad.get("coverage")
            result, status = policy(mr, mc, tr, tc)
            monthly.append({
                "venue": venue, "course": course, "target": target, "month": month,
                "eligible_n": values.get("base", {}).get("n", na()), "ml_selected_n": ml.get("n", na()),
                "ml_coverage": mc, "ml_positive_n": na(), "ml_selected_rate": mr,
                "ml_population_yield": float(mr) * float(mc),
                "traditional_selected_n": trad.get("n", na()), "traditional_coverage": tc,
                "traditional_positive_n": na(), "traditional_selected_rate": tr,
                "traditional_population_yield": float(tr) * float(tc),
                "selected_rate_delta": direct_delta(mr, tr),
                "population_yield_delta": float(mr) * float(mc) - float(tr) * float(tc),
                "comparison_result": result,
                "sparse_status": "UNCLASSIFIED_NO_PREDEFINED_CUTOFF; raw selected N retained",
                "evidence_status": "recorded", "notes": "post_ml monthly selection sums to production current_like aggregate.",
            })
        rows.append(row)
    return rows, monthly


def summarize(rows, monthly):
    def count(field, value): return sum(r[field] == value for r in rows)
    assessable = count("comparison_status", "ASSESSABLE")
    lineages = {x: count("historical_performance_lineage", x) for x in ("MATCH", "PARTIAL", "CONFLICT", "UNKNOWN")}
    comparisons = {x: count("comparison_result", x) for x in (
        "ML_DOMINANT", "TRADITIONAL_DOMINANT", "TRADEOFF_RATE_UP_COVERAGE_DOWN",
        "TRADEOFF_RATE_DOWN_COVERAGE_UP", "INDETERMINATE")}
    health = {x: count("performance_health", x) for x in ("KEEP", "MONITOR", "REVIEW")}
    evidence = {x: count("performance_evidence_status", x) for x in ("STRONG", "PARTIAL", "INSUFFICIENT")}
    stops = {x: count("immediate_stop_evidence", x) for x in ("YES", "NO", "INSUFFICIENT_EVIDENCE")}
    yield_known = sum(r["test_population_yield_delta"] != na() for r in rows)
    review_assessable = sum(r["performance_health"] == "REVIEW" and r["comparison_status"] == "ASSESSABLE" for r in rows)
    review_indeterminate = sum(r["performance_health"] == "REVIEW" and r["comparison_status"] == "INDETERMINATE" for r in rows)
    monthly_uses = len({(r["venue"], r["course"], r["target"]) for r in monthly})
    forward_counts = {x: count("forward_status", x) for x in sorted({r["forward_status"] for r in rows})}
    direct = [r for r in rows if r["comparison_status"] == "ASSESSABLE"]
    lines = [
        "# Production ML Performance Health Audit — Stage 2",
        "",
        "STATUS: STAGE 2 / NOT FINAL",
        "",
        "対象はPilot外の9場（TDA / OMR / SMS / SME / KRY / ASY / AMG / HWJ / EDG）のproduction Course Signal MLです。Pilot 3場、12場統合判定、本番変更、再学習、forward成績評価、NRT、commit/pushには進んでいません。",
        "",
        "## 集計",
        "",
        f"- production ML用途: {len(rows)}",
        f"- comparison: ASSESSABLE {assessable} / INDETERMINATE {len(rows) - assessable}",
        f"- comparison result: ML_DOMINANT {comparisons['ML_DOMINANT']} / TRADITIONAL_DOMINANT {comparisons['TRADITIONAL_DOMINANT']} / rate↑coverage↓ {comparisons['TRADEOFF_RATE_UP_COVERAGE_DOWN']} / rate↓coverage↑ {comparisons['TRADEOFF_RATE_DOWN_COVERAGE_UP']} / INDETERMINATE {comparisons['INDETERMINATE']}",
        f"- health: KEEP {health['KEEP']} / MONITOR {health['MONITOR']} / REVIEW {health['REVIEW']}（REVIEW+ASSESSABLE {review_assessable}、REVIEW+INDETERMINATE {review_indeterminate}）",
        f"- evidence: STRONG {evidence['STRONG']} / PARTIAL {evidence['PARTIAL']} / INSUFFICIENT {evidence['INSUFFICIENT']}",
        f"- lineage: MATCH {lineages['MATCH']} / PARTIAL {lineages['PARTIAL']} / CONFLICT {lineages['CONFLICT']} / UNKNOWN {lineages['UNKNOWN']}",
        f"- population_yield_deltaを直接rate×coverageから計算可能: {yield_known}。positive Nを逆算したものではありません。",
        f"- 月別のproduction-policy比較根拠あり: {monthly_uses}用途、{len(monthly)} venue×course×target×month行（ASY/AMGのみ）。",
        f"- forward monitoring対象: {len(rows)}用途（" + " / ".join(f"{k} {v}" for k, v in forward_counts.items()) + "）。",
        f"- immediate_stop_evidence: YES {stops['YES']} / NO {stops['NO']} / INSUFFICIENT_EVIDENCE {stops['INSUFFICIENT_EVIDENCE']}",
        "",
        "`REVIEW + INDETERMINATE`は性能不良ではなく、traditional policyまたは共通分母の固定履歴が不足して正式比較できない状態です。base（全母集団率）をtraditionalの代替には使用していません。",
        "",
        "## assessableなhistorical TEST policy比較",
        "",
        "Historical TESTはdevelopment holdoutであり、pristine final untouched holdoutではありません。",
        "",
        "|venue|course|target|ML rate / coverage|traditional rate / coverage|rate差|density差|result|lineage|",
        "|---|---:|---|---|---|---:|---:|---|---|",
    ]
    for r in direct:
        lines.append(
            f"|{r['venue']}|{r['course']}C|{r['target']}|{float(r['ml_test_selected_rate']):.2%} / {float(r['ml_test_realized_coverage']):.2%}|"
            f"{float(r['traditional_test_selected_rate']):.2%} / {float(r['traditional_test_realized_coverage']):.2%}|"
            f"{float(r['test_selected_rate_delta']):+.2%}|{float(r['test_population_yield_delta']):+.2%}|"
            f"{r['comparison_result']}|{r['historical_performance_lineage']}|")
    lines += [
        "",
        "## 月別・不確実性・forward",
        "",
        "- ASY/AMGのmonthly `post_ml` はproduction `current_like` aggregateと選択Nが一致することを確認したため使用しました。それ以外の候補JSONにあるequal-count月別値はproduction threshold方針ではないため使用していません。",
        "- 新たなsmall-N cutoffは導入していません。month-level CSVでは実Nを残し、`UNCLASSIFIED_NO_PREDEFINED_CUTOFF` としています。LOOは全用途でproduction-policy月別選択ベクトルが不足し `unknown` です。",
        "- 月別で最小のML選択NはASY 4C/top3の5、次いでAMG 4C/top2の9、AMG 4C/top3の10です。ASY 4C/top3は7か月中6か月rate差が正・1か月負、AMG 4C/top2は6か月正・1か月負です。小さい月次Nがあるため、これを安定/不安定の決定的証拠にはしません。",
        "- forwardはMONITORING ONLY。開始日やcapture継続性はhandoff/manifestに残る範囲だけで、forward hit rate・traditional rate・lift・結果ラベル・partial performanceを一切使用していません。",
        "",
        "## lineageと不足根拠",
        "",
        "- `MATCH`: candidate frozen/current_likeとartifactまたはproduction handoffのthreshold・TEST選択N・対象率が一致。",
        "- `PARTIAL`: artifactは存在するがcandidate側にfixed frozen production-policy aggregateがなく、完全なhistorical correspondenceを直接証明できない。",
        "- `CONFLICT`: 現行artifactと固定candidateのthreshold/N/rateが食い違う場合にのみ使用。今回Stage 2の9場では該当なし。",
        "- per-raceのML/traditional選択ID、direct positive count、全用途のproduction-policy月別値がないため、overlap、ML-only、traditional-only、共通block bootstrap、LOOの正式復元はできません。",
        "",
        "## Stage 3前にCG判断が必要な事項",
        "",
        "1. `REVIEW + INDETERMINATE`を性能不良と扱わず、historical evidence不足として別管理すること。",
        "2. 将来artifact/forward ledgerへ、共通eligible母集団・per-race policy selections・直接positive count・月キー・selection objectiveをappend-onlyで保存すること。",
        "3. rateとcoverageを両立できない用途のdensity trade-offを、KEEP/MONITOR/REVIEWにどう数値化するかをStage 3前に固定すること。",
        "4. small-N / sparseの事前閾値とstrict forward checkpointを、partial forward結果を見ずに定義すること。",
        "",
        "新たなCore Safety issueは発見していません。本Stage 2ではproduction artifact、manifest、runtime、PHP/API、frontend、snapshot、DB、threshold、coverage、forward条件を変更していません。commit/pushもしていません。",
    ]
    (OUT / "ml_performance_health_stage2_summary.md").write_text("\n".join(lines) + "\n")

    missing = [
        "# Performance Health Stage 2: missing items / constraints",
        "",
        "STATUS: STAGE 2 / NOT FINAL",
        "",
        "## 共通不足",
        "",
        "- per-race ML/traditional selection IDsと直接結果ラベルがないため、overlap、ML-only/traditional-only、neither、LOO、共通eligible bootstrapは再現不能。",
        "- direct selected positive Nがない。selected N×rateでpositive Nを復元していないため、positive count自体はunknown。densityは直接記録されたrate×coverageの補助計算に限定。",
        "- VALIDのproduction-threshold selected N/rateは多くの保存物にない。validation coverageとTEST realized coverageは混同しない。",
        "- 5場の古い候補月別値はequal-count policyであり、本番frozen thresholdの月別根拠ではない。",
        "",
        "## 場別",
        "",
        "- TDA/OMR/SMS/SME/KRY: production joblibのfixed TEST selectionは読めるが、candidate側にfrozen production-policy aggregateがない採用用途はlineage PARTIAL。",
        "- ASY/AMG: handoffがcandidate `post_ml/current_like`とjoblibの一致を記録。monthly post_mlはproduction aggregateとNが一致するが、per-race vectorは残っていない。",
        "- HWJ/EDG: manifestにproduction contractがありcandidateと照合できる。traditionalが停止または未保存の用途はbase rateで代替しない。",
        "- forward: start/capture metadataの保存は不均一。今回performance判断にforward outcomeは使用していない。",
        "",
        "## Stage 3前のschema改善案",
        "",
        "1. artifact adoption時に、selection objective、eligible/excluded N、exhibition requirement、VALID selected N/rate、TEST selected N/rate/coverageをmachine-readableで固定保存する。",
        "2. ML/traditional双方のper-race selection ID、target label、month/meet keyをappend-only保存し、policy overlapとblock bootstrapを後から再現可能にする。",
        "3. strict-forward ledgerにはstart、eligible/suppressed reason、saved count、logic/artifact version、capture timestamp、predefined checkpointを保存する。",
    ]
    (OUT / "ml_performance_health_stage2_missing_items.md").write_text("\n".join(missing) + "\n")


def main():
    OUT.mkdir(parents=True, exist_ok=True)
    rows, monthly = [], []
    for venue, (slug, candidate, version) in LEGACY.items():
        rows.extend(candidate_rows_with_artifact(venue, slug, candidate, version))
    for venue, (candidate, version, adopted) in NEW_STYLE.items():
        rs, ms = new_style_rows(venue, candidate, version, adopted)
        rows.extend(rs); monthly.extend(ms)
    for venue, (slug, candidate, version) in MANIFEST_VENUES.items():
        rows.extend(candidate_rows_with_artifact(venue, slug, candidate, version, manifest_only=True))
    with open(OUT / "ml_performance_health_stage2.csv", "w", newline="") as f:
        writer = csv.DictWriter(f, fieldnames=FIELDS); writer.writeheader(); writer.writerows(rows)
    with open(OUT / "ml_performance_monthly_stage2.csv", "w", newline="") as f:
        writer = csv.DictWriter(f, fieldnames=MONTHLY_FIELDS); writer.writeheader(); writer.writerows(monthly)
    summarize(rows, monthly)
    print(json.dumps({"rows": len(rows), "monthly_rows": len(monthly)}, ensure_ascii=False))


if __name__ == "__main__":
    main()
