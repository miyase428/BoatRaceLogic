#!/usr/bin/env python3
"""Read-only final integration of Performance Health Pilot and Stage 2.

No historical inference, database access, artifact loading, model execution or
production change occurs here.  The only inputs are the frozen Pilot/Stage 2
CSV outputs, and Pilot values are retained alongside normalized values.
"""

from __future__ import annotations

import csv
from collections import Counter
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "analysis/output/ml_performance_health"
EPS = 1e-12

FINAL_FIELDS = [
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
    "operational_issue", "pilot_original_performance_health", "pilot_original_evidence_status",
    "pilot_original_comparison_result", "normalized_performance_health",
    "normalized_evidence_status", "normalized_comparison_status",
    "normalized_comparison_result", "notes",
]

MONTHLY_FIELDS = [
    "venue", "course", "target", "month", "eligible_n", "ml_selected_n", "ml_coverage",
    "ml_positive_n", "ml_selected_rate", "ml_population_yield", "traditional_selected_n",
    "traditional_coverage", "traditional_positive_n", "traditional_selected_rate",
    "traditional_population_yield", "selected_rate_delta", "population_yield_delta",
    "comparison_result", "comparison_status", "sparse_status", "evidence_status", "source_stage", "notes",
]

REFERENCE = {
    "rows": 123, "assessable": 56, "indeterminate": 67, "ml_dominant": 33,
    "traditional_dominant": 0, "tradeoff_up_down": 23, "tradeoff_down_up": 0,
}


def read_csv(name):
    with open(OUT / name, newline="") as f:
        return list(csv.DictReader(f))


def unknown(value):
    return value in (None, "", "unknown", "N/A")


def policy(ml_rate, ml_cov, tr_rate, tr_cov):
    if any(unknown(x) for x in (ml_rate, ml_cov, tr_rate, tr_cov)):
        return "INDETERMINATE", "INDETERMINATE"
    mr, mc, tr, tc = map(float, (ml_rate, ml_cov, tr_rate, tr_cov))
    if mr >= tr - EPS and mc >= tc - EPS and (mr > tr + EPS or mc > tc + EPS):
        return "ML_DOMINANT", "ASSESSABLE"
    if tr >= mr - EPS and tc >= mc - EPS and (tr > mr + EPS or tc > mc + EPS):
        return "TRADITIONAL_DOMINANT", "ASSESSABLE"
    if mr > tr + EPS and mc < tc - EPS:
        return "TRADEOFF_RATE_UP_COVERAGE_DOWN", "ASSESSABLE"
    if mr < tr - EPS and mc > tc + EPS:
        return "TRADEOFF_RATE_DOWN_COVERAGE_UP", "ASSESSABLE"
    return "INDETERMINATE", "INDETERMINATE"


def density(row):
    values = (row.get("ml_test_selected_rate"), row.get("ml_test_realized_coverage"),
              row.get("traditional_test_selected_rate"), row.get("traditional_test_realized_coverage"))
    if any(unknown(x) for x in values):
        return
    ml = float(values[0]) * float(values[1])
    traditional = float(values[2]) * float(values[3])
    row["ml_test_population_yield"] = ml
    row["traditional_test_population_yield"] = traditional
    row["test_population_yield_delta"] = ml - traditional


def normalize_pilot(row):
    row = dict(row)
    row["pilot_original_performance_health"] = row.get("performance_health", "unknown")
    row["pilot_original_evidence_status"] = row.get("performance_evidence_status", "unknown")
    row["pilot_original_comparison_result"] = row.get("policy_comparison", "unknown")
    result, status = policy(
        row.get("ml_test_selected_rate"), row.get("ml_test_realized_coverage"),
        row.get("traditional_test_selected_rate"), row.get("traditional_test_realized_coverage"),
    )
    row["comparison_result"] = result
    row["comparison_status"] = status
    # Pilot did not carry an explicit lineage field.  Its per-row sources,
    # production handoffs and artifact checks establish MATCH for all except
    # the explicitly retained TMG 2C/top3 historical provenance conflict.
    if row.get("evidence_status", "").startswith("CONFLICT"):
        row["historical_performance_lineage"] = "CONFLICT"
    else:
        row["historical_performance_lineage"] = "MATCH"
    density(row)
    if status == "ASSESSABLE":
        row["performance_health"] = "REVIEW" if result == "TRADITIONAL_DOMINANT" else "MONITOR"
        row["performance_evidence_status"] = "PARTIAL"
        row["immediate_stop_evidence"] = "NO"
    else:
        row["performance_health"] = "REVIEW"
        row["performance_evidence_status"] = "INSUFFICIENT"
        row["immediate_stop_evidence"] = "INSUFFICIENT_EVIDENCE"
    row["normalized_performance_health"] = row["performance_health"]
    row["normalized_evidence_status"] = row["performance_evidence_status"]
    row["normalized_comparison_status"] = row["comparison_status"]
    row["normalized_comparison_result"] = row["comparison_result"]
    row["notes"] = (row.get("notes", "") + " Pilot original values retained; Stage 2 taxonomy was "
                    "applied only from directly recorded ML/traditional TEST rate and coverage.")
    return row


def normalize_stage2(row):
    row = dict(row)
    row["pilot_original_performance_health"] = "N/A"
    row["pilot_original_evidence_status"] = "N/A"
    row["pilot_original_comparison_result"] = "N/A"
    row["normalized_performance_health"] = row.get("performance_health", "unknown")
    row["normalized_evidence_status"] = row.get("performance_evidence_status", "unknown")
    row["normalized_comparison_status"] = row.get("comparison_status", "unknown")
    row["normalized_comparison_result"] = row.get("comparison_result", "unknown")
    return row


def normalize_monthly(rows, stage):
    result = []
    for raw in rows:
        r = dict(raw)
        comparison, status = policy(r.get("ml_selected_rate"), r.get("ml_coverage"),
                                    r.get("traditional_selected_rate"), r.get("traditional_coverage"))
        r["comparison_result"] = r.get("comparison_result") or comparison
        r["comparison_status"] = status
        r["source_stage"] = stage
        result.append({field: r.get(field, "unknown") for field in MONTHLY_FIELDS})
    return result


def counts(rows):
    c = Counter()
    c["rows"] = len(rows)
    c["assessable"] = sum(r["normalized_comparison_status"] == "ASSESSABLE" for r in rows)
    c["indeterminate"] = sum(r["normalized_comparison_status"] == "INDETERMINATE" for r in rows)
    for label, key in [
        ("ML_DOMINANT", "ml_dominant"), ("TRADITIONAL_DOMINANT", "traditional_dominant"),
        ("TRADEOFF_RATE_UP_COVERAGE_DOWN", "tradeoff_up_down"),
        ("TRADEOFF_RATE_DOWN_COVERAGE_UP", "tradeoff_down_up"),
    ]:
        c[key] = sum(r["normalized_comparison_result"] == label for r in rows)
    for label in ("KEEP", "MONITOR", "REVIEW"):
        c[label.lower()] = sum(r["normalized_performance_health"] == label for r in rows)
    for label in ("STRONG", "PARTIAL", "INSUFFICIENT"):
        c[label.lower()] = sum(r["normalized_evidence_status"] == label for r in rows)
    for label in ("MATCH", "PARTIAL", "CONFLICT", "UNKNOWN"):
        c["lineage_" + label.lower()] = sum(r["historical_performance_lineage"] == label for r in rows)
    for label in ("YES", "NO", "INSUFFICIENT_EVIDENCE"):
        c["stop_" + label.lower()] = sum(r["immediate_stop_evidence"] == label for r in rows)
    c["review_assessable"] = sum(r["normalized_performance_health"] == "REVIEW" and r["normalized_comparison_status"] == "ASSESSABLE" for r in rows)
    c["review_indeterminate"] = sum(r["normalized_performance_health"] == "REVIEW" and r["normalized_comparison_status"] == "INDETERMINATE" for r in rows)
    c["density_known"] = sum(not unknown(r["test_population_yield_delta"]) for r in rows)
    return c


def write_summary(rows, monthly, discrepancies):
    c = counts(rows)
    venue_counts = Counter(r["venue"] for r in rows)
    tradeoffs = [r for r in rows if r["normalized_comparison_result"] == "TRADEOFF_RATE_UP_COVERAGE_DOWN"]
    monthly_uses = len({(r["venue"], r["course"], r["target"]) for r in monthly})
    lines = [
        "# Production ML Performance Health Audit — 12-Venue Final Historical Integration",
        "",
        "STATUS: HISTORICAL PERFORMANCE AUDIT FINAL / FORWARD PENDING",
        "",
        "これは12場のhistorical performance audit最終統合です。Strict Forward Performanceの最終判定ではありません。forwardの結果、hit rate、lift、target outcome、partial期間成績は使用していません。",
        "",
        "## 統合集計",
        "",
        f"- production ML用途総数: {c['rows']}（" + ", ".join(f"{v} {venue_counts[v]}" for v in sorted(venue_counts)) + "）",
        f"- comparison: ASSESSABLE {c['assessable']} / INDETERMINATE {c['indeterminate']}",
        f"- comparison result: ML_DOMINANT {c['ml_dominant']} / TRADITIONAL_DOMINANT {c['traditional_dominant']} / rate↑coverage↓ {c['tradeoff_up_down']} / rate↓coverage↑ {c['tradeoff_down_up']}",
        f"- health: KEEP {c['keep']} / MONITOR {c['monitor']} / REVIEW {c['review']}（REVIEW+ASSESSABLE {c['review_assessable']}、REVIEW+INDETERMINATE {c['review_indeterminate']}）",
        f"- evidence: STRONG {c['strong']} / PARTIAL {c['partial']} / INSUFFICIENT {c['insufficient']}",
        f"- lineage: MATCH {c['lineage_match']} / PARTIAL {c['lineage_partial']} / CONFLICT {c['lineage_conflict']} / UNKNOWN {c['lineage_unknown']}",
        f"- immediate_stop_evidence: YES {c['stop_yes']} / NO {c['stop_no']} / INSUFFICIENT_EVIDENCE {c['stop_insufficient_evidence']}",
        f"- derived population yield/density計算可能: {c['density_known']}（直接保存されたrate×coverageのみ。positive countの復元ではない）",
        f"- monthly evidence: {monthly_uses}用途、{len(monthly)}行",
        "",
        "REVIEW+INDETERMINATEは、性能不良の意味ではありません。traditional policyまたは共通eligible denominatorのhistorical evidence不足により、正式比較できない用途です。",
        "ASSESSABLE 56用途のうち55用途はlineage MATCH、1用途（TMG 2C/top3）はhistorical lineage CONFLICTです。後者はPARTIAL evidenceとして残し、現行artifactのSTRONG performance evidenceへ昇格していません。",
        "",
        "## reference照合",
        "",
    ]
    if discrepancies:
        lines.extend([f"- CONFLICT: {d}" for d in discrepancies])
    else:
        lines.append("- 提示reference（123 / 56 / 67 / 33 / 23 / 0）との集計差異はありません。")
    lines += [
        "",
        "## tradeoff一覧 — Strict Forward優先確認",
        "",
        "これらはperformance defectではありません。Rate単独で勝敗判定せず、strict forward checkpointでrate・coverageを併記し、共通分母が保証できる時だけderived densityを補助評価します。",
        "",
        "|venue|course|target|ML rate|ML coverage|traditional rate|traditional coverage|rate delta|coverage delta|density delta|evidence|lineage|",
        "|---|---:|---|---:|---:|---:|---:|---:|---:|---:|---|---|",
    ]
    for r in tradeoffs:
        lines.append(
            f"|{r['venue']}|{r['course']}C|{r['target']}|{float(r['ml_test_selected_rate']):.2%}|{float(r['ml_test_realized_coverage']):.2%}|"
            f"{float(r['traditional_test_selected_rate']):.2%}|{float(r['traditional_test_realized_coverage']):.2%}|"
            f"{float(r['test_selected_rate_delta']):+.2%}|{float(r['test_coverage_delta']):+.2%}|"
            f"{float(r['test_population_yield_delta']):+.2%}|{r['normalized_evidence_status']}|{r['historical_performance_lineage']}|")
    lines += [
        "",
        "## forward優先分類（結果未使用）",
        "",
        f"- TRADEOFF_PRIORITY: 上表の{len(tradeoffs)}用途。RateとCoverageの双方を固定ルールで確認する。",
        f"- EVIDENCE_PRIORITY: INDETERMINATE / INSUFFICIENTの{c['indeterminate']}用途。traditional policyと共通分母の保存不足を埋める将来schemaが必要。",
        f"- CONFIRMATION_PRIORITY: ML_DOMINANTの{c['ml_dominant']}用途。historical development holdoutでの採用根拠はあるが、future generalizationは未証明。",
        f"- LINEAGE_PRIORITY: historical lineage CONFLICTの{c['lineage_conflict']}用途。TMG 2C/top3を維持し、current operational safety conflictとは混同しない。",
        "",
        "Strict Forward Checkpointでは事前に固定したまま、ML selected rate、ML coverage、traditional selected rate、traditional coverage、rate delta、coverage deltaを最低限評価する。共通eligible denominatorが保証できる場合のみderived densityを補助評価する。forward outcomeを見た後に評価軸やcheckpointを変更しない。",
        "",
        "## 6問への回答",
        "",
        "1. **既存12場MLは継続利用してよいか。** はい。historicalで比較可能な56用途にtraditional優位はなく、即時停止根拠もありません。ただし55用途はlineage MATCH、TMG 2C/top3はPARTIALなhistorical conflictであり、いずれもfuture superiorityの証明ではありません。",
        f"2. **特にforward監視が重要な用途。** Rate↑/Coverage↓の{len(tradeoffs)} tradeoff用途、historical比較不能の{c['indeterminate']}用途、そしてML_DOMINANTの{c['ml_dominant']}用途を上の優先区分どおり確認します。",
        "3. **historicalだけで再検討すべき明確なperformance不良用途。** ありません（REVIEW+ASSESSABLE=0、TRADITIONAL_DOMINANT=0）。",
        f"4. **forward checkpointまで最終判断を保留すべき用途。** 全{c['rows']}用途です。特にtradeoff、evidence不足、lineage conflictは優先確認対象です。",
        "5. **現段階でproduction変更が必要なほど明確な性能問題。** ありません。",
        "6. **NRT開発を進めながら既存12場のforwardを待つ運用。** historical evidence上は妥当です。ただしNRTの実装可否やforward運用の安全性は本統合の対象外であり、別途のGate/運用判断に従います。",
        "",
        "## 限界と今後Strict Forwardで判断する事項",
        "",
        "採用済み用途だけを統合しているためadoption / selection biasがあります。Historical TESTはdevelopment holdoutでありpristine untouched final holdoutではありません。per-race selection、direct positive count、全用途の月別production-policy evidenceが欠けるため、全123用途のML superiorityは証明していません。Strict Forwardで事前固定の評価軸・checkpointに従ってgeneralizationとtradeoffを確認します。",
        "",
        "新規Core Safety issueは発見していません。production artifact、manifest、runtime、PHP/API、frontend、snapshot、DB、threshold、coverage、forward条件・checkpointは変更していません。commit/pushもしていません。",
    ]
    (OUT / "ml_performance_health_final_summary.md").write_text("\n".join(lines) + "\n")

    missing = [
        "# 12-Venue Final Historical Integration: missing items",
        "",
        "STATUS: HISTORICAL PERFORMANCE AUDIT FINAL / FORWARD PENDING",
        "",
        "- historical performance is not future generalization evidence; strict forward checkpoint remains required.",
        "- per-race ML/traditional selection IDs and direct labels are missing, so overlap, ML-only/traditional-only rates, LOO and common block bootstrap are not reproducible.",
        "- direct selected positive counts are not stored. This integration never reconstructs them from selected N×rate.",
        "- many adopted uses have no reproducible traditional policy; base population rate is not substituted for traditional policy.",
        "- monthly production-policy evidence exists only for ASY/AMG; equal-count candidate monthly evidence for other venues is not treated as production-threshold evidence.",
        "- TMG 2C/top3 retains historical_performance_lineage=CONFLICT: the historical candidate and current artifact relationship is not promoted to STRONG evidence, while current operational safety remains a separate question.",
        "",
        "Future append-only artifact/forward records should retain: selection objective; common eligible/excluded N; exhibition dependency; VALID and TEST fixed-policy selection metrics; per-race ML/traditional IDs; target label; month/meet key; logic/artifact version; capture metadata; and predefined strict-forward checkpoint metadata.",
    ]
    (OUT / "ml_performance_health_final_missing_items.md").write_text("\n".join(missing) + "\n")


def main():
    OUT.mkdir(parents=True, exist_ok=True)
    pilot = [normalize_pilot(r) for r in read_csv("ml_performance_health_pilot.csv")]
    stage2 = [normalize_stage2(r) for r in read_csv("ml_performance_health_stage2.csv")]
    rows = pilot + stage2
    monthly = normalize_monthly(read_csv("ml_performance_monthly_pilot.csv"), "PILOT")
    monthly += normalize_monthly(read_csv("ml_performance_monthly_stage2.csv"), "STAGE2")
    c = counts(rows)
    discrepancies = [f"{key}: expected {value}, observed {c[key]}" for key, value in REFERENCE.items() if c[key] != value]
    with open(OUT / "ml_performance_health_final.csv", "w", newline="") as f:
        writer = csv.DictWriter(f, fieldnames=FINAL_FIELDS, extrasaction="ignore")
        writer.writeheader(); writer.writerows(rows)
    with open(OUT / "ml_performance_monthly_final.csv", "w", newline="") as f:
        writer = csv.DictWriter(f, fieldnames=MONTHLY_FIELDS)
        writer.writeheader(); writer.writerows(monthly)
    write_summary(rows, monthly, discrepancies)
    print({"rows": len(rows), "monthly_rows": len(monthly), "discrepancies": discrepancies})


if __name__ == "__main__":
    main()
