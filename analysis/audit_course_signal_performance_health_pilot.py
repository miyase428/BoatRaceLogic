#!/usr/bin/env python3
"""Read-only performance-health pilot from immutable candidate evidence.

This script intentionally does not load a production model or query the DB.  It
only transcribes fixed candidate/production handoff aggregates.  In particular,
it never treats a whole-population base rate as a traditional selection policy
and never reconstructs positive counts from rounded rates.
"""

from __future__ import annotations

import csv
import json
from pathlib import Path

import joblib


ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "analysis/output/ml_performance_health"

FIELDS = [
    "venue", "course", "target", "production_model_version", "selection_objective",
    "eligible_population_definition", "exclusion_condition", "exhibition_required", "missing_excluded_n",
    "historical_evidence_source", "evidence_status",
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
    "population_yield_delta_interval", "period_dependency_flag", "policy_comparison",
    "performance_health", "performance_evidence_status", "immediate_stop_evidence",
    "forward_status", "forward_start", "current_forward_n", "expected_checkpoint",
    "checkpoint_reached", "last_capture_date", "missing_count", "operational_issue",
    "traditional_policy_status", "eligible_population_status", "notes",
]

MONTHLY_FIELDS = [
    "venue", "course", "target", "month", "eligible_n", "ml_selected_n", "ml_coverage",
    "ml_positive_n", "ml_selected_rate", "ml_population_yield", "traditional_selected_n",
    "traditional_coverage", "traditional_positive_n", "traditional_selected_rate",
    "traditional_population_yield", "selected_rate_delta", "population_yield_delta",
    "sparse_status", "evidence_status", "notes",
]


def na() -> str:
    return "unknown"


def rate_delta(left, right):
    return "unknown" if left is None or right is None else left - right


def coverage_delta(left, right):
    return "unknown" if left is None or right is None else left - right


def decision(ml_rate, ml_cov, traditional_rate, traditional_cov, complete=False):
    if traditional_rate is None:
        return "INDETERMINATE", "REVIEW", "INSUFFICIENT"
    if ml_rate > traditional_rate and ml_cov >= traditional_cov:
        policy = "ML_ADVANTAGE"
    elif ml_rate > traditional_rate:
        policy = "TRADEOFF"
    elif ml_rate < traditional_rate and ml_cov > traditional_cov:
        policy = "TRADEOFF"
    elif ml_rate < traditional_rate:
        policy = "TRADITIONAL_ADVANTAGE"
    else:
        policy = "INDETERMINATE"
    # Aggregate policies are not enough for KEEP: no per-race overlap or exact
    # positive-count/yield evidence exists in the pilot's immutable sources.
    return policy, "MONITOR", "PARTIAL" if complete else "INSUFFICIENT"


def base_row(venue, course, target, version, source, valid_n, test_n, notes):
    return {
        "venue": venue, "course": course, "target": target,
        "production_model_version": version, "selection_objective": "UNKNOWN",
        "eligible_population_definition": "fixed candidate VALID/TEST race population for this venue/course/target",
        "exclusion_condition": "candidate-usable race population; exact exclusion breakdown not retained in each fixed source",
        "exhibition_required": "unknown",
        "missing_excluded_n": "unknown",
        "historical_evidence_source": source, "evidence_status": "recorded",
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
        "period_dependency_flag": na(), "policy_comparison": "INDETERMINATE",
        "performance_health": "REVIEW", "performance_evidence_status": "INSUFFICIENT",
        "immediate_stop_evidence": "INSUFFICIENT_EVIDENCE", "forward_status": "INSUFFICIENT_DATA",
        "forward_start": "unknown", "current_forward_n": "unknown", "expected_checkpoint": "UNKNOWN",
        "checkpoint_reached": "unknown", "last_capture_date": "unknown", "missing_count": "unknown",
        "operational_issue": "course-signal strict-forward capture metadata was not used as performance evidence in this pilot",
        "traditional_policy_status": "NOT_REPRODUCIBLE",
        "eligible_population_status": "recorded",
        "notes": notes,
    }


def fill_ml_test(row, selection, target):
    row["ml_test_selected_n"] = selection["n"]
    row["ml_test_realized_coverage"] = selection["coverage"]
    row["ml_test_selected_rate"] = selection["rate"] if "rate" in selection else selection[f"{target}_rate"]
    # The source stores rate and N, but not a direct positive-N field.  Do not
    # reverse-engineer it from rounded display values.


def apply_traditional(row, selection, complete=True):
    if not selection or selection.get("rate") is None:
        return
    row["traditional_test_selected_n"] = selection["n"]
    row["traditional_test_realized_coverage"] = selection["coverage"]
    row["traditional_test_selected_rate"] = selection["rate"]
    row["test_selected_rate_delta"] = rate_delta(row["ml_test_selected_rate"], selection["rate"])
    row["test_coverage_delta"] = coverage_delta(row["ml_test_realized_coverage"], selection["coverage"])
    row["traditional_policy_status"] = "recorded_aggregate"
    policy, health, evidence = decision(
        row["ml_test_selected_rate"], row["ml_test_realized_coverage"],
        selection["rate"], selection["coverage"], complete,
    )
    row["policy_comparison"] = policy
    row["performance_health"] = health
    row["performance_evidence_status"] = evidence


def mkn_rows():
    source = "analysis/output/mkn_course_ml_candidate_20260927.json + production handoff + forecast/models/mikuni_course_signal_v1.json"
    data = json.load(open(ROOT / "analysis/output/mkn_course_ml_candidate_20260927.json"))
    manifest = json.load(open(ROOT / "forecast/models/mikuni_course_signal_v1.json"))
    production = [("2", "first"), ("2", "top2"), ("2", "top3"), ("3", "top2"),
                  ("5", "top2"), ("5", "top3"), ("6", "top2"), ("6", "top3")]
    rows = []
    for course, target in production:
        item = data["courses"][course][target]
        valid_n = item["probability"]["valid"]["n"]
        test_n = item["probability"]["test"]["n"]
        row = base_row("MKN", course, target, "mikuni_course_signal_v1", source, valid_n, test_n,
                       "ML TEST selection and VALID target coverage are read from the production manifest; no per-race vectors retained.")
        model = manifest["models"][course][target]
        fill_ml_test(row, model["test_selection"], target)
        row["ml_validation_coverage"] = model.get("valid_coverage", na())
        row["exhibition_required"] = str(bool(model.get("uses_exhibition"))).lower()
        row["forward_start"] = "2026-10-01"
        current = item.get("current")
        apply_traditional(row, current, complete=True)
        rows.append(row)
    return rows


def bwk_rows():
    source = "analysis/output/bwk_ml_candidate_validation_20260927.json + candidate handoff"
    data = json.load(open(ROOT / "analysis/output/bwk_ml_candidate_validation_20260927.json"))
    rows, monthly = [], []
    for key, item in data["items"].items():
        course, target = key.split("_")
        selected = item["selected_variant"]
        valid = selected["selected_by_valid"]["selection"]
        test = selected["test_selection"]
        row = base_row("BWK", course, target, "biwako_course_signal_v1", source,
                       item["period_counts"]["valid"], item["period_counts"]["test"],
                       "Base-rate comparator is recorded but is not treated as a traditional policy.")
        row["ml_valid_selected_n"] = valid["n"]
        row["ml_validation_coverage"] = valid["coverage"]
        row["ml_valid_selected_rate"] = valid["rate"]
        fill_ml_test(row, test, target)
        row["exhibition_required"] = str(any("exhibition" in f for f in selected.get("feature_names", []))).lower()
        row["forward_start"] = "2026-09-28"
        current = item.get("current")
        apply_traditional(row, current, complete=True)
        if current and current.get("n", 0) > 0:
            uncertainty = item.get("uncertainty", {})
            ordinary = uncertainty.get("ordinary", {})
            block = uncertainty.get("month_block", {})
            row["uncertainty_method"] = "recorded ordinary and month-block bootstrap; policy vectors unavailable"
            row["selected_rate_delta_interval"] = json.dumps({
                "ordinary": [ordinary.get("ci95_low"), ordinary.get("ci95_high")],
                "month_block": [block.get("ci95_low"), block.get("ci95_high")],
            }, ensure_ascii=False)
            for month, value in item.get("monthly", {}).items():
                ml = value.get("selected", {})
                traditional = value.get("current", {})
                monthly.append({
                    "venue": "BWK", "course": course, "target": target, "month": month,
                    "eligible_n": value.get("base", {}).get("n", na()), "ml_selected_n": ml.get("n", na()),
                    "ml_coverage": ml.get("coverage", na()), "ml_positive_n": na(),
                    "ml_selected_rate": ml.get("rate", na()), "ml_population_yield": na(),
                    "traditional_selected_n": traditional.get("n", na()),
                    "traditional_coverage": traditional.get("coverage", na()),
                    "traditional_positive_n": na(), "traditional_selected_rate": traditional.get("rate", na()),
                    "traditional_population_yield": na(),
                    "selected_rate_delta": rate_delta(ml.get("rate"), traditional.get("rate")),
                    "population_yield_delta": na(), "sparse_status": "not_predefined; report raw selected N",
                    "evidence_status": "recorded", "notes": "Fixed candidate monthly aggregate; direct positive N not stored.",
                })
        rows.append(row)
    return rows, monthly


def tmg_rows():
    source = "tamagawa candidate JSONs + prod-runtime record + forecast/models/tamagawa_center_signal_v1.joblib"
    center = json.load(open(ROOT / "analysis/output/tamagawa_center_ml_candidate_20260926.json"))
    other = json.load(open(ROOT / "analysis/output/tamagawa_other_course_ml_candidate_20260926.json"))
    prod_runtime = json.load(open(ROOT / "analysis/output/tamagawa_other_course_ml_candidate_prod_runtime_20260926.json"))
    artifact = joblib.load(ROOT / "forecast/models/tamagawa_center_signal_v1.joblib")
    production = [("2", "top2"), ("2", "top3"), ("3", "first"), ("3", "top2"), ("3", "top3"),
                  ("4", "first"), ("4", "top2"), ("4", "top3"), ("5", "top3"),
                  ("6", "top2"), ("6", "top3")]
    rows = []
    for course, target in production:
        item = (center if course in ("3", "4") else other)["courses"][course][target]
        row = base_row("TMG", course, target, "tamagawa_course_signal_v1", source,
                       item["probability"]["valid"]["n"], item["probability"]["test"]["n"],
                       "Production TEST selection is read directly from the current artifact; monthly candidate values are equal-count, not frozen-production-threshold, so they are not used as production monthly evidence.")
        production_selection = artifact["models"][course][target]["test_selection"]
        fill_ml_test(row, production_selection, target)
        row["exhibition_required"] = str("exhibition" in item.get("groups", [])).lower()
        # 6C was adopted even though the candidate JSON has no frozen threshold.
        # The production artifact is the only fixed source for its selection.
        if course == "6":
            row["notes"] += " 6C candidate JSON has no frozen-selection aggregate; artifact is the authoritative fixed source."
        # The original other-course candidate file used a different 2C/top3
        # threshold.  The separately retained production-runtime evidence agrees
        # with the current artifact, so retain both facts rather than overwrite
        # the historical discrepancy.
        if (course, target) == ("2", "top3"):
            row["evidence_status"] = "CONFLICT_RESOLVED_CURRENT_MAPPING"
            row["notes"] += (
                " CONFLICT: original candidate threshold=0.8020485240807984 "
                "(n=118, rate=0.7711864406779662) differs from current artifact "
                "threshold=0.8076583616335488 (n=100, rate=0.78); retained "
                "prod-runtime record matches the current artifact."
            )
        # The legacy current policy is stored as an aggregate for the same test population.
        apply_traditional(row, item.get("current"), complete=True)
        rows.append(row)
    return rows


def write_markdown(rows, monthly):
    total = len(rows)
    venues = ", ".join(sorted({r["venue"] for r in rows}))
    direct = [r for r in rows if r["traditional_policy_status"] == "recorded_aggregate"]
    no_traditional = [r for r in rows if r["traditional_policy_status"] != "recorded_aggregate"]
    policy_counts = {k: sum(r["policy_comparison"] == k for r in rows)
                     for k in ("ML_ADVANTAGE", "TRADEOFF", "TRADITIONAL_ADVANTAGE", "INDETERMINATE")}
    health_counts = {k: sum(r["performance_health"] == k for r in rows)
                     for k in ("KEEP", "MONITOR", "REVIEW")}
    evidence_counts = {k: sum(r["performance_evidence_status"] == k for r in rows)
                       for k in ("STRONG", "PARTIAL", "INSUFFICIENT")}
    conflicts = [r for r in rows if str(r["evidence_status"]).startswith("CONFLICT")]
    selected_rate_advantage = sum(
        r["traditional_test_selected_rate"] != "unknown"
        and float(r["ml_test_selected_rate"]) > float(r["traditional_test_selected_rate"])
        for r in rows
    )
    selected_rate_disadvantage = sum(
        r["traditional_test_selected_rate"] != "unknown"
        and float(r["ml_test_selected_rate"]) < float(r["traditional_test_selected_rate"])
        for r in rows
    )
    by_venue = {}
    for venue in sorted({r["venue"] for r in rows}):
        rs = [r for r in rows if r["venue"] == venue]
        by_venue[venue] = {
            "models": len(rs), "traditional": sum(r in direct for r in rs),
            "indeterminate": sum(r["policy_comparison"] == "INDETERMINATE" for r in rs),
        }
    lines = [
        "# Production ML Performance Health Audit Pilot",
        "",
        "STATUS: PILOT / NOT FINAL",
        "",
        "対象は MKN・BWK・TMG の既存production course-signal MLのみです。これは履歴に残る固定集計を使った比較可能性パイロットであり、残り9場の監査、本番変更、再学習、NRT作業には進んでいません。",
        "",
        "## 結論（パイロット）",
        "",
        f"- production ML用途: {total}（{venues}）",
        f"- traditional選択方針を同一TEST母集団上で直接比較できた用途: {len(direct)}。比較不能: {len(no_traditional)}。",
        f"- policy比較: ML_ADVANTAGE {policy_counts['ML_ADVANTAGE']} / TRADEOFF {policy_counts['TRADEOFF']} / TRADITIONAL_ADVANTAGE {policy_counts['TRADITIONAL_ADVANTAGE']} / INDETERMINATE {policy_counts['INDETERMINATE']}。",
        f"- health: KEEP {health_counts['KEEP']} / MONITOR {health_counts['MONITOR']} / REVIEW {health_counts['REVIEW']}。",
        f"- evidence: STRONG {evidence_counts['STRONG']} / PARTIAL {evidence_counts['PARTIAL']} / INSUFFICIENT {evidence_counts['INSUFFICIENT']}。",
        f"- 直接比較できた12用途でのTEST selected-rate: ML優位 {selected_rate_advantage} / ML不利 {selected_rate_disadvantage}。population yieldはpositive count未保存のため優位・不利とも判定不能。",
        "- 即時停止: 全用途 `INSUFFICIENT_EVIDENCE`。このパイロットの固定集計だけでは停止の要否を根拠化できません。",
        "",
        "`base`（全対象母集団の率）はtraditional選択方針ではないため、比較器として一切使用していません。また、丸められた率×Nからpositive件数を復元していません。したがってpopulation yield、選択集合の重なり、LOO、再計算bootstrapは `unknown` です。",
        "",
        "## 監査対象・固定ソース",
        "",
        "- MKN: `mkn_course_ml_candidate_20260927.json`、production handoff、production manifest。採用8用途のうち、直接traditional aggregateがあるのは3C/top2のみ。",
        "- BWK: `bwk_ml_candidate_validation_20260927.json` とcandidate handoff。直接traditional aggregateがあるのは4C/first、4C/top3のみ。両用途のみ既存ordinary/month-block intervalと月別集計を転記。",
        "- TMG: center/other candidate JSON、other-course prod-runtime record、現行production artifact `tamagawa_center_signal_v1.joblib`。artifactに保存済みのTEST選択値だけをML側に使用。",
        "",
        "## 場別の比較可能性",
        "",
    ]
    for venue, stats in by_venue.items():
        lines.append(f"- {venue}: {stats['models']}用途、traditional直接比較 {stats['traditional']}、INDETERMINATE {stats['indeterminate']}。")
    lines += [
        "",
        "## 月別・不確実性",
        "",
        f"- 月別にML/traditional双方の固定集計がある行: {len(monthly)}（BWK 4C/first・4C/top3のみ）。他の保存月別値はequal-count候補等で、現行production threshold方針を表さないため使用しません。",
        "- bootstrap: BWKの2用途についてのみ、既存成果物に記録済みのordinaryおよびmonth-block区間を転記しました。新規bootstrapやブロック設計は実行していません。",
        "- per-race選択ベクトルが保存されていないため、overlap/ML-only/traditional-only/LOOは全用途で未算出です。",
        "- BWK 4C/firstは月別selected-rate差が7か月中5か月正・2か月負、ML選択Nの最小/中央値は6/7、traditionalは3/11。BWK 4C/top3は6か月正・1か月負、MLは7/12、traditionalは3/11です。小さい月次Nがあるため、符号だけで安定/不安定を断定していません。",
        "- LOOは、production policyに対応する全用途の月別選択ベクトルがないため `unknown` です。period_dependency_flagも全用途 `unknown` のままです。",
        "",
        "## 直接復元できたTEST policy比較",
        "",
        "Historical TESTはdevelopment holdoutです。pristine untouched final holdoutとは扱いません。率は固定保存値、coverageは固定保存値であり、positive countやpopulation yieldは推定していません。",
        "",
        "|venue|course|target|ML n / rate / coverage|traditional n / rate / coverage|rate差|coverage差|policy|",
        "|---|---:|---|---|---|---:|---:|---|",
    ]
    for r in direct:
        lines.append(
            f"|{r['venue']}|{r['course']}C|{r['target']}|{r['ml_test_selected_n']} / {float(r['ml_test_selected_rate']):.2%} / {float(r['ml_test_realized_coverage']):.2%}|"
            f"{r['traditional_test_selected_n']} / {float(r['traditional_test_selected_rate']):.2%} / {float(r['traditional_test_realized_coverage']):.2%}|"
            f"{float(r['test_selected_rate_delta']):+.2%}|{float(r['test_coverage_delta']):+.2%}|{r['policy_comparison']}|")
    lines += [
        "",
        "最小の復元済みML TEST選択NはBWK 4C/firstの59、次いでTMG 4C/top3の86、TMG 4C/top2の92です。これらを固定閾値によるsmall-N判定にはせず、Stage 2で用途別のsparse基準を事前固定する候補として記録します。",
        "",
        "## TMG 2C/top3 の履歴差",
        "",
        "original candidate JSONは threshold 0.8020485240807984、n=118、rate=0.7711864406779662 ですが、現行artifactは threshold 0.8076583616335488、n=100、rate=0.78 です。別保存のprod-runtime recordはartifact側と一致します。本パイロットでは現行artifactをML production evidenceとして採用し、この差自体は `CONFLICT_RESOLVED_CURRENT_MAPPING` としてCSVに保存しました。候補JSONと現行artifactの履歴差を消したり補正したりしていません。",
        "",
        "## forward",
        "",
        "MKN/BWKのcourse-signal strict-forward開始日は各handoffからそれぞれ2026-10-01/2026-09-28と記録しました。capture件数・継続性・checkpointは固定監査ソースにないため `unknown` / `INSUFFICIENT_DATA` です。TMGは開始日も固定ソースで確認できませんでした。AI1 v6 forward validationはcourse-signal healthとはdeployment roleが別です。本監査ではいずれのforward outcome、hit rate、結果確定後の再評価も使用していません。",
        "",
        "## 次のCG判断（Stage 2向け）",
        "",
        "1. per-raceのML/traditional選択IDと結果ラベルを将来の監査保存物に含め、overlap・population yield・LOO・共通bootstrapを再現可能にするか。",
        "2. traditional方針が未保存の用途を、無理にbase rateで代替せず、履歴比較不能として残す方針を承認するか。",
        "3. TMG 2C/top3の旧candidate/prod-runtime/artifact差をHistorical provenance conflictとして台帳管理するか。現行artifactとprod-runtimeは一致しており、本パイロットでproduction変更はしていません。",
        "",
        "## 変更範囲",
        "",
        "本パイロットで変更したのはanalysis用のread-only集計スクリプトと `analysis/output/ml_performance_health/` 配下の出力だけです。production joblib、manifest、runtime、PHP/API、frontend、DB、threshold、coverage、NRT、forward設定は変更していません。commit/pushは実施していません。",
    ]
    (OUT / "ml_performance_health_summary.md").write_text("\n".join(lines) + "\n")

    missing = [
        "# Performance Health Pilot: missing items / evidence limits",
        "",
        "STATUS: PILOT / NOT FINAL",
        "",
        "## 全用途に共通して未保存",
        "",
        "- 固定historical ML/traditionalのper-race selection IDとラベル。従ってoverlap、ML-only、traditional-only、neither、LOO、共通母集団bootstrapは再現不能。",
        "- selected positive count。率表示から `N × rate` を作らず `unknown` のままとしたため、population yieldとその差分も未算出。",
        "- production thresholdそのものに対応する月別集計（BWKの2用途を除く）。equal-count候補の月別値はproduction policyと異なるため採用していない。",
        "",
        "## venue別",
        "",
        "- MKN: 採用8用途中、traditional aggregateは3C/top2だけ。VALID時のfrozen-policy選択値も未保存。",
        "- BWK: 採用11用途中、traditional aggregateは4C/first・4C/top3だけ。その他はcurrent n=0/rate=nullでbase rateしかないが、baseはtraditional policyではない。",
        "- TMG: 6C/top2・6C/top3はcandidate JSONにfrozen selectionがなく、現行artifactのtest_selectionを使用。6Cにはtraditional aggregateもない。",
        "- TMG 2C/top3: original candidate JSONとcurrent artifactは閾値・選択n・率が異なる。prod-runtime recordはartifactと一致するため現行mappingは裏付けられるが、候補履歴のprovenance差はCONFLICTとして残す。",
        "- course-signal strict-forward: MKN/BWKの開始日はhandoffにあるが、capture件数、連続性、checkpoint、missing countの固定運用metadataがない。TMGの開始日も固定ソースでは不明。",
        "",
        "## 監査上の制約",
        "",
        "- これは固定済み履歴証拠の比較であり、DBから現在モデルを再推論して過去成績を作り直していない。",
        "- AI1 forwardのprediction/resultsは参照していない。course-signal performanceとは混在させない。",
        "- `KEEP`や本番置換・threshold変更・停止決定を支持する強い証拠は、このパイロット単独にはない。",
        "- Stage 2前のschema案: future artifact/forward ledgerに、eligible/excluded N、exhibition requirement、selection objective、per-race ML/traditional selection ID、direct positive count、month key、strict-forward capture metadataをappend-onlyで保存する。",
    ]
    (OUT / "ml_performance_health_missing_items.md").write_text("\n".join(missing) + "\n")


def main():
    OUT.mkdir(parents=True, exist_ok=True)
    rows = mkn_rows()
    bwk, monthly = bwk_rows()
    rows.extend(bwk)
    rows.extend(tmg_rows())
    with open(OUT / "ml_performance_health_pilot.csv", "w", newline="") as f:
        writer = csv.DictWriter(f, fieldnames=FIELDS)
        writer.writeheader(); writer.writerows(rows)
    with open(OUT / "ml_performance_monthly_pilot.csv", "w", newline="") as f:
        writer = csv.DictWriter(f, fieldnames=MONTHLY_FIELDS)
        writer.writeheader(); writer.writerows(monthly)
    write_markdown(rows, monthly)
    print(json.dumps({"rows": len(rows), "monthly_rows": len(monthly)}, ensure_ascii=False))


if __name__ == "__main__":
    main()
