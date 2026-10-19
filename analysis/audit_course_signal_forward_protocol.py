#!/usr/bin/env python3
"""Create the read-only Strict Forward protocol design artifacts.

This script intentionally reads the historical performance-health inventory only.
It does not open prediction-forward snapshots, result tables, or any forward
performance report.  Its purpose is to freeze the design *before* forward
outcomes are evaluated.
"""

from __future__ import annotations

import csv
from collections import Counter
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INPUT = ROOT / "analysis/output/ml_performance_health/ml_performance_health_final.csv"
OUTPUT = ROOT / "analysis/output/ml_forward_protocol"


REQUIRED_FIELDS = [
    ("forward_record_id", "prediction ledger", "new", "required", "immutable unique ID", "Current snapshot id exists, but not a per-use decision-ledger ID."),
    ("race_code", "identity", "present", "required", "race identity", "Stored as prediction_forward_snapshots.race_code."),
    ("race_date", "identity", "present", "required", "calendar identity", "Stored as prediction_forward_snapshots.race_date."),
    ("place_code", "identity", "present", "required", "venue identity", "Stored as prediction_forward_snapshots.place_code."),
    ("race_no", "identity", "present", "required", "race number", "Stored as prediction_forward_snapshots.race_no."),
    ("meet_id", "identity", "missing", "required", "cluster/bootstrap and meet coverage", "No explicit meeting/event identifier is persisted."),
    ("stage", "capture", "present", "required", "morning versus exhibition decision stage", "Stored as prediction_forward_snapshots.stage."),
    ("prediction_captured_at", "capture", "present", "required", "when the decision was fixed", "Stored as captured_at."),
    ("official_cutoff_at", "capture", "missing", "required", "prove decision was before official deadline", "Runtime checks a cached deadline, but the timestamp/source is not persisted on the snapshot."),
    ("cutoff_source_version", "capture", "missing", "required", "deadline provenance", "Deadline cache/source is not recorded with the decision."),
    ("capture_status", "capture", "partial", "required", "saved, suppressed, failed, or skipped", "A saved row implies success; no durable attempt/status ledger exists."),
    ("missing_reason", "capture", "partial", "required", "why a candidate decision was unavailable", "Generic no_signal exists in payload, but no per-use reason for all 18 uses."),
    ("validation_mode", "capture", "present", "required", "strict versus late_replay separation", "Stored as validation_mode."),
    ("logic_version", "version", "present", "required", "aggregate code/model identity", "Stored as logic_version hash."),
    ("artifact_version", "version", "partial", "required", "selected ML artifact version", "May appear inside emitted ML detail; not a canonical per-use field."),
    ("artifact_sha256", "version", "missing", "required", "artifact provenance for the exact use", "Aggregate logic hash is not a per-artifact SHA256."),
    ("runtime_version", "version", "missing", "recommended", "runtime reproducibility", "Python/runtime version is not captured per decision."),
    ("feature_logic_version", "version", "partial", "required", "feature/logic provenance", "Included only indirectly in aggregate logic_version."),
    ("venue", "policy", "present", "required", "policy venue", "place_code/payload place are available."),
    ("course", "policy", "partial", "required", "subject course", "Present only for emitted signals, not a complete per-use ledger."),
    ("target", "policy", "partial", "required", "first, top2, or top3", "ML selected_target may be emitted; target state is absent for non-selected uses and legacy."),
    ("target_definition_version", "policy", "missing", "required", "formal label definition", "No canonical versioned target-definition field."),
    ("eligible_flag", "eligibility", "missing", "required", "common eligible population denominator", "No row for every venue/course/target candidate."),
    ("exclusion_reason", "eligibility", "missing", "required", "why a use was ineligible", "No per-use exclusion ledger."),
    ("ml_policy_version", "policy", "partial", "required", "ML policy identity", "model_version can be emitted for a selected ML signal, but not canonically for every use."),
    ("ml_threshold", "policy", "partial", "required", "frozen ML threshold", "selected_threshold can be emitted only for a selected ML signal."),
    ("ml_selected_flag", "decision", "missing", "required", "ML decision for every eligible use", "The merged display does not retain false decisions or all three target decisions."),
    ("traditional_policy_version", "policy", "missing", "required", "traditional rule identity", "Rules are indirectly covered by logic_version; no explicit per-use version."),
    ("traditional_threshold_or_rule", "policy", "partial", "required", "frozen traditional policy", "Some emitted legacy detail has rule values; no complete canonical policy record."),
    ("traditional_selected_flag", "decision", "missing", "required", "traditional decision for every eligible use", "mergePurposeMlMatches preserves display behavior but not both policy flags."),
    ("selection_subject_id", "decision", "partial", "required", "player/boat/course identity selected by each policy", "player_id/course exists only when an emitted signal exists."),
    ("canonical_decision_rule_version", "decision", "missing", "required", "which pre-cutoff snapshot is evaluated if more than one exists", "Rows are versioned by content and reports choose latest; no frozen strict-forward canonicalization rule."),
    ("result_available_at", "outcome", "missing", "required", "when outcome first became eligible to join", "graded_at is grading time, not result availability time."),
    ("target_outcome", "outcome", "partial", "required", "post-race label joined without changing decision", "grade JSON is written back to the prediction row; no append-only outcome ledger."),
    ("outcome_source_version", "outcome", "missing", "recommended", "result provenance", "No outcome-source/version field on snapshot grade."),
    ("outcome_joined_at", "outcome", "partial", "required", "audit trail for the post-race join", "graded_at exists, but the prediction and outcome share one mutable row."),
]


def read_rows() -> list[dict[str, str]]:
    with INPUT.open(newline="", encoding="utf-8") as fp:
        return list(csv.DictReader(fp))


def priority_rows(rows: list[dict[str, str]]) -> list[dict[str, str]]:
    result: list[dict[str, str]] = []
    for row in rows:
        common = {
            "venue": row["venue"],
            "course": row["course"],
            "target": row["target"],
            "production_model_version": row["production_model_version"],
            "historical_evidence_status": row["performance_evidence_status"],
            "historical_lineage": row["historical_performance_lineage"],
            "forward_outcome_used": "NO",
        }
        if row["comparison_result"] == "TRADEOFF_RATE_UP_COVERAGE_DOWN":
            result.append({**common, "priority": "TRADEOFF_PRIORITY", "reason": "Historical ML selected rate was higher while coverage was lower; evaluate both axes and derived density only on a common eligible denominator."})
        if row["comparison_status"] == "INDETERMINATE" or row["performance_evidence_status"] == "INSUFFICIENT":
            result.append({**common, "priority": "EVIDENCE_PRIORITY", "reason": "Historical direct ML-versus-traditional comparison was not reproducible; prospective ledger must retain both policies and the common eligible population."})
        if row["comparison_result"] == "ML_DOMINANT":
            result.append({**common, "priority": "CONFIRMATION_PRIORITY", "reason": "Historical development-holdout dominance requires prospective confirmation; it is not proof of future generalization."})
        if row["historical_performance_lineage"] == "CONFLICT":
            result.append({**common, "priority": "LINEAGE_PRIORITY", "reason": "Historical provenance conflict remains a historical record; prospective evidence must name the current artifact/policy without rewriting history."})
    return result


def write_csv(path: Path, fieldnames: list[str], rows: list[dict[str, str]]) -> None:
    with path.open("w", newline="", encoding="utf-8") as fp:
        writer = csv.DictWriter(fp, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerows(rows)


def main() -> None:
    if not INPUT.is_file():
        raise SystemExit(f"Historical audit input not found: {INPUT}")
    OUTPUT.mkdir(parents=True, exist_ok=True)
    rows = read_rows()
    if len(rows) != 123:
        raise SystemExit(f"Expected 123 production uses from final historical audit; found {len(rows)}")

    priorities = priority_rows(rows)
    counts = Counter(item["priority"] for item in priorities)
    expected = {
        "TRADEOFF_PRIORITY": 23,
        "EVIDENCE_PRIORITY": 67,
        "CONFIRMATION_PRIORITY": 33,
        "LINEAGE_PRIORITY": 1,
    }
    if dict(counts) != expected:
        raise SystemExit(f"Historical priority counts differ from final audit: {dict(counts)}")

    write_csv(
        OUTPUT / "strict_forward_required_fields.csv",
        ["field", "group", "current_status", "strict_forward_requirement", "purpose", "current_observation"],
        [
            {
                "field": name,
                "group": group,
                "current_status": status,
                "strict_forward_requirement": requirement,
                "purpose": purpose,
                "current_observation": observation,
            }
            for name, group, status, requirement, purpose, observation in REQUIRED_FIELDS
        ],
    )
    write_csv(
        OUTPUT / "strict_forward_priority.csv",
        ["venue", "course", "target", "priority", "reason", "production_model_version", "historical_evidence_status", "historical_lineage", "forward_outcome_used"],
        sorted(priorities, key=lambda x: (x["priority"], x["venue"], int(x["course"]), x["target"])),
    )

    missing = [item for item in REQUIRED_FIELDS if item[2] in {"missing", "partial"}]
    missing_lines = [
        "# Strict Forward — Missing Items (Design Only)",
        "",
        "STATUS: DESIGN / FORWARD PERFORMANCE NOT INSPECTED",
        "",
        "This is a schema and operating-design gap list. It does not inspect or score any forward outcome.",
        "",
        "## Blocking for a formal ML-versus-traditional strict-forward comparison",
        "",
        "The current display snapshot can prove that a rendered signal was fixed before the runtime deadline guard, but it does not retain a complete decision row for both policies over a common eligible population. The following must be added before a formal comparison can start:",
        "",
    ]
    for name, group, status, requirement, purpose, observation in missing:
        if requirement == "required":
            missing_lines.append(f"- `{name}` ({group}, {status}): {observation}")
    missing_lines += [
        "",
        "## Required non-destructive design",
        "",
        "1. Append an immutable prediction-decision ledger with one row for every `race_code × venue × course × target × policy` that was eligible for the fixed evaluation population. Store both `ML` and `TRADITIONAL` decisions, including false decisions and explicit exclusions.",
        "2. Keep the existing display snapshot unchanged as operational/UI evidence. Do not reinterpret it as the complete comparison ledger.",
        "3. Append result/outcome records keyed to the immutable decision ID (or snapshot hash); do not overwrite the decision payload. Record when an outcome became available and when it was joined.",
        "4. Persist the official cutoff timestamp/source and a durable capture attempt status, including no-capture and missing-exhibition reasons.",
        "5. Freeze a canonical pre-cutoff selection rule before outcome review. A recommended rule is: one canonical strict decision per use, selected as the latest successful `strict` capture at or before the persisted official cutoff; retain every raw capture for audit.",
        "",
        "## Not a production defect finding",
        "",
        "These are forward-evaluation completeness gaps, not evidence that current production predictions are unsafe. No production, model, threshold, coverage, runtime, DB, or forward process has been changed by this design work.",
    ]
    (OUTPUT / "strict_forward_missing_items.md").write_text("\n".join(missing_lines) + "\n", encoding="utf-8")

    protocol = f"""# Strict Course Signal Forward Evaluation Protocol

STATUS: DESIGN / FORWARD PERFORMANCE NOT INSPECTED

## Scope and safety boundary

This document fixes the evaluation design before any Course Signal forward outcome is inspected. It uses only the completed historical performance-health inventory. It does **not** read, aggregate, calculate, or report forward hit rate, traditional hit rate, lift, target results, monthly forward performance, payout, or ROI.

`forward_performance_inspected = NO`

The protocol covers the 123 current production Course Signal ML uses. It does not alter production behavior, forward runtime, artifacts, manifests, thresholds, coverage, features, PHP/API, frontend, snapshots, database data, NRT work, or AI1 forward validation.

## Independent historical-audit reference

Input: `analysis/output/ml_performance_health/ml_performance_health_final.csv`

- production uses: {len(rows)}
- historical TRADEOFF_PRIORITY: {counts['TRADEOFF_PRIORITY']}
- historical EVIDENCE_PRIORITY: {counts['EVIDENCE_PRIORITY']}
- historical CONFIRMATION_PRIORITY: {counts['CONFIRMATION_PRIORITY']}
- historical LINEAGE_PRIORITY: {counts['LINEAGE_PRIORITY']}

The priority rows are exported to `strict_forward_priority.csv`. A use may be in multiple priority classes. Historical evidence remains historical; it is neither changed nor resolved by prospective results.

## Current forward infrastructure (read-only inventory)

### Capture path

1. `analysis/run_all_race_forward_capture_cron.sh` invokes `analysis/capture_all_race_forward.php --db-only` for daytime capture. The wrapper deliberately avoids automated external exhibition scraping.
2. `capture_all_race_forward.php` calls the same Course Signal API path as the UI (`web/tamagawa_lane4_star_api.php`) for relevant venues.
3. The API calls `PredictionForwardSnapshotStore::captureDisplayedCourseSignals()` before returning a successful response.
4. `PredictionForwardSnapshotStore` writes `component=course_signals` records to `boat_race.prediction_forward_snapshots` after current-date, result-not-complete, and (for exhibition stage) official-deadline guards.
5. `analysis/prewarm_home_course_signals.php` generates the morning/base view and uses the same snapshot-store method. TOP’s display cache is separately version-checked with `courseSignalLogicVersion()`.
6. `analysis/grade_prediction_forward_snapshots.php` later writes results/grade into the same snapshot row. The existing generic report can aggregate rendered signal types, but it is not a strict per-policy comparison ledger.

### Current schedule evidence

`analysis/install_prediction_forward_automation.sh` defines an intended schedule of daytime capture every two minutes, a 23:10 late replay, and an 08:35 grading/prewarm job. The current user crontab could not be read in this environment (`crontabs/miyazaki/: fopen: Permission denied`), so actual registration is **not confirmed** here.

### Operational metadata check (no outcomes read)

A read-only metadata query on 2026-10-04 selected only `race_date`, `validation_mode`, `stage`, distinct saved-race count, and capture timestamps for `component=course_signals`. It did not select payload, grade, actual result, payout, or any outcome field.

For 2026-09-28 through 2026-10-03, exhibition-stage strict capture was recorded for 10, 0, 0, 0, 2, and 0 races respectively, while late-replay exhibition records existed on each of those days. This establishes an **operational continuity gap**, not a performance result: the current snapshot table cannot explain every absent strict decision at policy/use level. The strict ledger must therefore retain capture attempt status and explicit missing reasons before it can support a formal comparison. Morning/base snapshots were present, but are a separate stage and must not be silently substituted for exhibition-stage strict decisions.

### Current stored fields

The table stores identity (`race_code`, date/place/race), stage, component, validation mode, snapshot hash, aggregate `logic_version`, JSON payload, `captured_at`, `last_seen_at`, and later result/grade fields. Course-signal payloads preserve rendered signal details such as type, course, player, star level, selected ML target/threshold when an ML signal is rendered, and display detail.

This is valuable display and capture evidence. It is not sufficient to compare ML and traditional policies prospectively because non-selected policy decisions, a common eligible denominator, false decisions, and durable exclusion/capture reasons are not retained for every use.

## Strict Forward unit of record

The required immutable prediction ledger unit is:

`race_code × venue × course × target × policy_version × canonical_decision_version`

where `policy_version` is separately `ML` and `TRADITIONAL`, and a row exists for every eligible use whether selected or not. The two policy rows share the same explicitly recorded eligible-population identity. A separate, append-only outcome ledger references the immutable decision record.

Do not overwrite the prediction decision after a result arrives. Existing UI/display snapshots can remain raw evidence; they are not to be rewritten or discarded.

The full required field inventory and current gap status are in `strict_forward_required_fields.csv`.

## Eligibility and capture protocol

Before outcomes are viewed, freeze all of the following:

- The target definition (`first`, `top2`, `top3`) and its version.
- The venue/course/target policy mapping, ML artifact SHA/version and threshold, and traditional rule/version.
- The common eligible-population definition. At minimum this names exhibition dependency, required input completeness, valid course/player mapping, official deadline availability, and exclusion reasons.
- One `ml_selected_flag` and one `traditional_selected_flag` for every eligible use. `false` is a valid decision and must be stored.
- The selection subject identity (course/player/boat) used by each policy.
- The official cutoff timestamp and source, capture timestamp, validation mode, capture status, and canonicalization rule.

Recommended canonicalization rule (proposal, not implemented): retain all raw strict captures, then designate the latest successful strict capture at or before the persisted official cutoff as canonical for the use. A capture after cutoff, late replay, missing exhibition, no official deadline, or incomplete input is never silently converted into a strict decision; it receives an explicit status/reason.

## Formal checkpoint metrics

At a predefined checkpoint and only from the immutable ledger plus append-only outcomes, calculate for each venue/course/target:

| Axis | ML | Traditional |
|---|---|---|
| Eligible population | eligible N | same eligible N |
| Selection | selected N / coverage | selected N / coverage |
| Outcome | positive N / selected target rate | positive N / selected target rate |
| Difference | rate delta | coverage delta |

When and only when the two policies share the exact recorded eligible denominator, also calculate the derived metric:

`selected_positive_density = coverage × selected target rate`

`population_yield_delta = ML density − Traditional density`

Density is supplementary. It must never hide rate or coverage, nor determine a winner alone. For the 23 historical rate-up/coverage-down uses, the report must always show rate delta, coverage delta, selected N for both policies, uncertainty, and density (if valid).

## Comparison and uncertainty design

The policies can select different races, so a same-selected-subset comparison is not valid as the primary evaluation. Instead, retain both policy flags on the common eligible race/use ledger and evaluate the full eligible population.

1. Report Wilson intervals for each policy’s selected target rate as descriptive binomial uncertainty.
2. Use a predeclared meet/block bootstrap over the common eligible ledger to estimate intervals for rate delta, coverage delta, and (when valid) population-yield delta. Resample whole meet blocks; if a stable `meet_id` is unavailable, use a predeclared date×venue block and state that limitation.
3. Preserve within-race ML/traditional overlap while resampling. A bootstrap replicate with zero selected rows for a policy is non-estimable for that rate and is recorded, not replaced with zero.
4. Do not use a post-outcome choice of bootstrap block, confidence level, or metric. The exact confidence level and resample count require CG approval before implementation.

## Checkpoint design

No Course Signal-specific predefined checkpoint was found in current code or history. `evaluate_ai_winrate_blend_forward.py` has `100/300/500/1000` checkpoints, but that is AI1 v6-forward code and cannot be reused as a Course Signal checkpoint by implication.

Therefore the current status is:

`existing_course_signal_checkpoint = NOT_FOUND`

`checkpoint_source = NONE`

`THRESHOLD_REQUIRES_CG_DECISION = YES`

Outcome-independent checkpoint proposal for CG approval:

1. **Continuity gate:** only strict records; a predeclared minimum calendar span and meet count; explicit capture/missing accounting.
2. **Eligibility gate:** a predeclared minimum common eligible N per use.
3. **Selection gate:** a predeclared minimum selected N for **each** policy per use. A policy with too few selections is `INSUFFICIENT_FORWARD_DATA`, not a loss.
4. **Precision gate:** a predeclared maximum uncertainty width for selected rates/deltas, or a documented business/statistical precision target.
5. **Comparison gate:** both policies have the same frozen eligibility definition and complete policy/version metadata. Density is calculated only if this is true.

No numerical minimum is set here because the repository contains no Course Signal checkpoint specification and a number chosen after viewing any forward outcomes would be invalid. CG should approve the numerical N/span/meet/precision criteria before schema implementation or outcome review.

## Classification after an approved checkpoint

These are protocol states only; none are applied now.

- `INSUFFICIENT_FORWARD_DATA`: any continuity, eligibility, selection, or precision gate is not met; no performance conclusion.
- `FORWARD_KEEP`: data-integrity gates pass; the preapproved uncertainty rule supports ML’s intended advantage; coverage/density guardrails are met; no policy/version or capture conflict exists.
- `FORWARD_MONITOR`: integrity gates pass but uncertainty or a rate/coverage tradeoff is not decisive under the approved rule; retain production unchanged and collect the next fixed block.
- `FORWARD_REVIEW`: a preapproved rate, coverage, density, consistency, or integrity guardrail is breached; send to CG review. This is not an automated rollback.
- `FORWARD_DATA_CONFLICT`: canonical identity, artifact/policy version, cutoff, eligibility, or outcome-join integrity is inconsistent. Resolve data provenance before performance interpretation.

For the 56 historical `MONITOR` uses, forward evidence may later move a use to `FORWARD_KEEP`, `FORWARD_MONITOR`, or `FORWARD_REVIEW` only under the approved rules. For the 67 historical `INDETERMINATE` uses, strict forward is the **first formal ML-versus-traditional performance comparison**, not a historical-health promotion.

## Priority-specific treatment

- **23 TRADEOFF_PRIORITY uses:** rate alone cannot support KEEP. Require joint rate/coverage reporting and, only with the common denominator, derived density and its uncertainty.
- **67 EVIDENCE_PRIORITY uses:** do not compare current display rows retroactively. Start formal comparison only after the dual-policy, common-eligible ledger is in place.
- **33 CONFIRMATION_PRIORITY uses:** historical ML dominance is a hypothesis for prospective confirmation, not an automatic KEEP outcome.
- **TMG 2C top3 LINEAGE_PRIORITY:** retain the historical lineage `CONFLICT` permanently in documentation. Prospective records must identify the current artifact and policy. A clean forward record adds prospective evidence; it does not rewrite the historical conflict as resolved.

## Production-change trigger

No automatic production action is permitted. At any checkpoint:

- `FORWARD_KEEP` or `FORWARD_MONITOR` means continue unchanged unless CG separately authorizes a change.
- `FORWARD_REVIEW` or `FORWARD_DATA_CONFLICT` creates a CG review item with the frozen ledger, metrics, and metadata.
- Any production enable/disable, threshold/coverage change, retraining, artifact replacement, or fallback change requires a separate CG-approved task and a new pre-outcome evaluation plan.

## Required CG decisions before implementation/outcome review

1. Exact numerical checkpoint gates: minimum common eligible N, selected N per policy, calendar span, meet count, and precision target.
2. Bootstrap block definition (`meet_id` source or date×venue fallback), confidence level, and resample count.
3. The predefined rate/coverage/density guardrails that distinguish KEEP, MONITOR, and REVIEW.
4. Whether morning and exhibition are separate populations (recommended) and whether only exhibition-stage strict decisions are eligible for ML-vs-traditional comparisons.
5. The canonical strict capture rule and treatment of duplicate captures before cutoff.
6. The append-only outcome-ledger schema and owner of operational capture/missing-status records.

## Explicit non-actions

No forward performance outcome was inspected. No checkpoint was applied. No forward schema, runtime, production code, model, artifact, threshold, coverage, database record, PHP/API, frontend, snapshot, NRT item, commit, or push was changed.
"""
    (OUTPUT / "strict_forward_protocol.md").write_text(protocol, encoding="utf-8")
    print({"production_uses": len(rows), "priority_rows": len(priorities), "priority_counts": dict(counts), "forward_performance_inspected": "NO"})


if __name__ == "__main__":
    main()
