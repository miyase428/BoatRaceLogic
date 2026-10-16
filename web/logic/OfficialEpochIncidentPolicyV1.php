<?php

declare(strict_types=1);

/**
 * Frozen governance policy for incidents after Official Epoch promotion.
 * It is outcome-blind and governs only measurement evidence/lifecycle.
 */
final class OfficialEpochIncidentPolicyV1
{
    public const SCHEMA_VERSION = 'official-epoch-incident-policy/v1';

    /** @return array<string,array<string,mixed>> */
    public static function incidentTypes(): array
    {
        return [
            'CAPTURE_SOURCE_FAILURE' => ['range' => ['WARNING', 'MAJOR', 'CRITICAL'], 'rule' => 'RACE_LOCAL_CLASSIFIED_WARNING_OTHERWISE_ESCALATE'],
            'SCHEDULER_FAILURE_MISSED_OPPORTUNITY' => ['range' => ['WARNING', 'MAJOR', 'CRITICAL'], 'rule' => 'POPULATION_LOSS_ESCALATES'],
            'DEADLINE_CUTOFF_SEMANTICS_FAILURE' => ['range' => ['MAJOR', 'CRITICAL'], 'rule' => 'SEMANTIC_OR_AMBIGUOUS_CRITICAL'],
            'TIMESTAMP_TIMEZONE_AMBIGUITY' => ['range' => ['MAJOR', 'CRITICAL'], 'rule' => 'UNPROVEN_ORDERING_ESCALATES'],
            'EXPECTED_CANONICAL_COMPLETENESS_FAILURE' => ['range' => ['WARNING', 'MAJOR'], 'rule' => 'RACE_LOCAL_ONLY_WARNING'],
            'IDENTITY_MISMATCH' => ['range' => ['MAJOR', 'CRITICAL'], 'rule' => 'PROVENANCE_ROOT_ESCALATES'],
            'ARTIFACT_CORRUPTION' => ['range' => ['MAJOR', 'CRITICAL'], 'rule' => 'SCOPE_UNCERTAIN_ESCALATES'],
            'POLICY_IDENTITY_MISMATCH' => ['range' => ['CRITICAL'], 'rule' => 'ALWAYS_CRITICAL'],
            'CONFLICTING_DUPLICATE' => ['range' => ['MAJOR', 'CRITICAL'], 'rule' => 'OFFICIAL_KEY_CONFLICT_ESCALATES'],
            'POST_CUTOFF_CAPTURE_BACKFILL_CONTAMINATION' => ['range' => ['CRITICAL'], 'rule' => 'ALWAYS_CRITICAL'],
            'COMPARATIVE_CONTRACT_VIOLATION' => ['range' => ['CRITICAL'], 'rule' => 'ALWAYS_CRITICAL'],
            'SYNTHETIC_TRADITIONAL_CONTAMINATION' => ['range' => ['CRITICAL'], 'rule' => 'ALWAYS_CRITICAL'],
            'ML_ONLY_DENOMINATOR_CONTAMINATION' => ['range' => ['CRITICAL'], 'rule' => 'ALWAYS_CRITICAL'],
            'MEASUREMENT_IMPLEMENTATION_IDENTITY_DRIFT' => ['range' => ['MAJOR', 'CRITICAL'], 'rule' => 'SEMANTICS_CHANGE_CRITICAL'],
            'SEMANTIC_REVISION' => ['range' => ['CRITICAL'], 'rule' => 'ALWAYS_CRITICAL'],
            'SOURCE_SCHEMA_CHANGE' => ['range' => ['MAJOR', 'CRITICAL'], 'rule' => 'SEMANTICS_UNKNOWN_ESCALATES'],
            'UNKNOWN_UNCLASSIFIED_FAILURE' => ['range' => ['CRITICAL'], 'rule' => 'ALWAYS_CRITICAL'],
            'FORMAL_EVIDENCE_HASH_MISMATCH' => ['range' => ['CRITICAL'], 'rule' => 'ALWAYS_CRITICAL'],
            'CLOSURE_LIFECYCLE_CORRUPTION' => ['range' => ['CRITICAL'], 'rule' => 'ALWAYS_CRITICAL'],
        ];
    }

    /** @return array<string,mixed> */
    public static function definition(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'production_prediction_routing_changes' => false,
            'incident_types' => self::incidentTypes(),
            'non_measurement_change' => ['classification' => 'INFORMATIONAL', 'same_epoch_allowed' => true, 'same_window_allowed' => true],
            'severity_model' => [
                'INFO' => ['official_evidence' => 'CONTINUE', 'new_capture' => 'CONTINUE'],
                'WARNING' => ['scope' => 'RACE_LOCAL_UNIQUE', 'evidence' => 'QUARANTINE_AFFECTED', 'new_capture' => 'CONTINUE'],
                'MAJOR' => ['scope' => 'WINDOW_OR_UNCERTAIN', 'new_capture' => 'PAUSE', 'active_window' => 'ABORT', 'same_epoch_recovery' => true],
                'CRITICAL' => ['scope' => 'EPOCH_CONTRACT_OR_PROVENANCE_ROOT', 'epoch' => 'SUSPEND_OR_END', 'new_epoch_or_reapproval' => true],
            ],
            'epoch_states' => ['ACTIVE', 'SUSPENDED', 'ENDED'],
            'allowed_transitions' => ['ACTIVE->SUSPENDED', 'ACTIVE->ENDED', 'SUSPENDED->ACTIVE', 'SUSPENDED->ENDED'],
            'evidence_statuses' => ['VALID', 'QUARANTINED', 'INVALIDATED'],
            'automatic_safe_actions' => ['INCIDENT_RECORD', 'QUARANTINE_EVIDENCE', 'PAUSE_NEW_CAPTURE', 'MAINTENANCE_HOLD', 'SUSPEND_OFFICIAL_EPOCH', 'ABORT_ACTIVE_WINDOW', 'READ_ONLY_HISTORICAL_EVALUATION'],
            'cg_authorized_actions' => ['INVALIDATE_WINDOW', 'FINAL_INVALIDATE_EVIDENCE', 'RESUME_OFFICIAL_EPOCH', 'END_OFFICIAL_EPOCH', 'START_NEW_EPOCH', 'SYSTEMIC_INCIDENT_RECOVERY', 'BREAKING_POLICY_SCOPE_SEMANTIC_CHANGE'],
            'closed_window_mutation_forbidden' => true,
            'physical_evidence_mutation_forbidden' => true,
            'backfill_as_official_forbidden' => true,
            'synthetic_traditional_forbidden' => true,
            'ml_only_in_comparison_denominator_forbidden' => true,
            'unknown_scope_quarantine' => 'LAST_PROVEN_GOOD_BOUNDARY_TO_DETECTION_OR_ACTIVE_WINDOW',
            'semantic_revision' => ['same_window' => 'PROHIBITED', 'new_window' => true, 'new_epoch' => true, 'cg_reapproval' => true],
            'implementation_change' => [
                'NON_MEASUREMENT_CHANGE' => ['new_window' => false, 'new_epoch' => false, 'cg_reapproval' => false],
                'MEASUREMENT_IMPLEMENTATION_CHANGE_SEMANTICS_EQUIVALENT' => ['new_window' => true, 'new_epoch' => false, 'cg_reapproval' => false],
                'MEASUREMENT_SEMANTICS_CHANGE' => ['new_window' => true, 'new_epoch' => true, 'cg_reapproval' => true],
            ],
            'same_epoch_successor_requires' => ['measurement_semantics_unchanged', 'comparative_scope_unchanged', 'comparative_policy_semantics_unchanged', 'hard_gate_policy_unchanged', 'numeric_policy_semantics_unchanged', 'incident_policy_semantics_unchanged', 'affected_evidence_uniquely_isolated', 'regressions_pass', 'readiness_pass'],
            'new_epoch_requires' => ['comparative_scope_change', 'comparative_policy_semantic_change', 'eligibility_semantic_change', 'cutoff_timestamp_semantics_breaking_change', 'measurement_semantics_breaking_change', 'hard_gate_contract_breaking_change', 'numeric_quality_policy_breaking_change', 'incident_policy_major_breaking_revision', 'synthetic_traditional_contamination', 'ml_only_denominator_contamination', 'unbounded_taint', 'epoch_provenance_root_loss', 'closure_or_aggregate_trust_loss', 'epoch_ended'],
            'cg_reapproval_required_for' => ['new_epoch_start', 'suspended_to_active_resume', 'breaking_semantic_revision', 'comparative_scope_or_policy_change', 'incident_policy_major_revision', 'systemic_incident_recovery', 'official_evidence_invalidated', 'affected_scope_not_bounded', 'epoch_termination'],
            'incident_record_schemas' => ['official-epoch-incident/v1', 'official-epoch-suspension/v1', 'official-epoch-incident-resolution/v1', 'official-epoch-resume/v1', 'official-epoch-end/v1'],
            'cas_required' => true,
            'idempotency_required' => true,
        ];
    }

    public static function identity(): string
    {
        return 'sha256:' . hash('sha256', self::canonicalJson(self::definition()));
    }

    /** @return array<string,mixed> */
    public static function classify(string $type, array $context = []): array
    {
        if ($type === 'NON_MEASUREMENT_CHANGE') {
            return ['classification' => 'INFORMATIONAL', 'severity' => 'INFO', 'actions' => ['CONTINUE'], 'requires_new_window' => false, 'requires_new_epoch' => false, 'requires_cg_reapproval' => false];
        }
        $types = self::incidentTypes();
        if (!isset($types[$type])) $type = 'UNKNOWN_UNCLASSIFIED_FAILURE';
        $severity = $types[$type]['range'][0];
        $scope = (string)($context['affected_scope_kind'] ?? '');
        $unknownScope = !empty($context['scope_unknown']);
        $systemic = !empty($context['systemic']) || !empty($context['semantics_unknown']) || !empty($context['multi_window']);
        if ($unknownScope || $systemic) $severity = 'CRITICAL';
        if (in_array($type, ['CAPTURE_SOURCE_FAILURE', 'SCHEDULER_FAILURE_MISSED_OPPORTUNITY', 'EXPECTED_CANONICAL_COMPLETENESS_FAILURE'], true)
            && $scope === 'RACE_LOCAL' && !empty($context['classified']) && empty($context['downstream_propagation'])) $severity = 'WARNING';
        if ($type === 'MEASUREMENT_IMPLEMENTATION_IDENTITY_DRIFT' && (($context['change_class'] ?? '') === 'MEASUREMENT_SEMANTICS_CHANGE')) $severity = 'CRITICAL';
        $critical = $severity === 'CRITICAL';
        $major = $severity === 'MAJOR';
        return [
            'classification' => 'INCIDENT', 'incident_type' => $type, 'severity' => $severity,
            'actions' => $critical ? ['INCIDENT_RECORD', 'QUARANTINE_EVIDENCE', 'PAUSE_NEW_CAPTURE', 'MAINTENANCE_HOLD', 'SUSPEND_OFFICIAL_EPOCH'] : ($major ? ['INCIDENT_RECORD', 'QUARANTINE_EVIDENCE', 'PAUSE_NEW_CAPTURE', 'ABORT_ACTIVE_WINDOW'] : ['INCIDENT_RECORD', 'QUARANTINE_EVIDENCE', 'CONTINUE']),
            'requires_new_window' => $major || $critical || !empty($context['active_window_measurement_change']),
            'requires_new_epoch' => $critical && (in_array($type, ['SEMANTIC_REVISION', 'MEASUREMENT_IMPLEMENTATION_IDENTITY_DRIFT', 'POLICY_IDENTITY_MISMATCH', 'POST_CUTOFF_CAPTURE_BACKFILL_CONTAMINATION', 'COMPARATIVE_CONTRACT_VIOLATION', 'SYNTHETIC_TRADITIONAL_CONTAMINATION', 'ML_ONLY_DENOMINATOR_CONTAMINATION', 'FORMAL_EVIDENCE_HASH_MISMATCH', 'CLOSURE_LIFECYCLE_CORRUPTION'], true) || !empty($context['provenance_root_loss']) || !empty($context['semantics_change'])),
            'requires_cg_reapproval' => $critical || !empty($context['evidence_invalidated']) || $unknownScope,
        ];
    }

    /** @param array<string,mixed> $value */
    public static function canonicalJson(array $value): string
    {
        $json = json_encode(self::sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return is_string($json) ? $json : '{}';
    }

    private static function sort(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map(static fn(mixed $item): mixed => self::sort($item), $value);
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = self::sort($item);
        return $value;
    }
}
