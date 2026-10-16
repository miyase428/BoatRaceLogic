<?php

declare(strict_types=1);

require_once __DIR__ . '/OfficialEpochIncidentPolicyV1.php';

/**
 * Frozen, outcome-blind contract for promoting a completed Formal
 * Measurement Window to the single Official Epoch.
 *
 * This is deliberately a policy definition, not a mutable status document.
 * Dynamic evidence is evaluated by OfficialEpochLifecycleV1 against an
 * explicit closed Window.
 */
final class OfficialEpochPolicyV1
{
    public const SCHEMA_VERSION = 'official-epoch-policy/v1';

    /** @return list<string> */
    public static function hardGateNames(): array
    {
        return [
            'expected_prediction_storage_separation',
            'canonical_first_write_rule',
            'conflicting_duplicate_detection',
            'twelve_venue_integration',
            'artifact_identity_available',
            'policy_identity_available',
            'scheduler_operation_verified',
            'cutoff_timebase_verified',
            'timezone_evidence_chain_verified',
            'capture_failure_observable',
            'replay_recomputed_excluded',
            'traditional_shadow_status_classified',
            'production_use_crosswalk_frozen',
            'live_strict_real_capture_observed',
        ];
    }

    /** @return array<string,mixed> */
    public static function definition(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'official_epoch_meaning' => 'FORMAL_MEASUREMENT_PROMOTION_ONLY',
            'production_prediction_routing_changes' => false,
            // The epoch promotion contract is explicitly bound to the
            // long-term incident semantics; it never infers them from a
            // mutable operational document.
            'incident_policy_schema' => OfficialEpochIncidentPolicyV1::SCHEMA_VERSION,
            'incident_policy_identity' => OfficialEpochIncidentPolicyV1::identity(),
            'hard_gates' => self::hardGateNames(),
            'formal_promotion_gates' => [
                'gate_a1' => 'PASS', 'gate_a2' => 'PASS', 'gate_b_now' => 'PASS',
                'gate_b_final' => 'PASS', 'gate_c' => 'PASS',
                'formal_evidence_aggregate' => 'COMPLETE',
                'formal_provenance_validation' => 'PASS',
                'formal_comparative_readiness' => 'PASS',
                'closure_v3_valid' => true,
                'historical_reproducibility' => 'PASS',
                'source_failure_count' => 0,
                'missing_terminal_resolution_count' => 0,
                'post_cutoff_backfill_count' => 0,
                'global_fatal_count' => 0,
            ],
            'numeric_data_quality' => [
                'expected_completeness' => 1.0,
                'canonical_completeness' => 1.0,
                'eligible_opportunity_capture_rate' => 1.0,
                'unclassified_missing_count' => 0,
                'artifact_identity_completeness' => 1.0,
                'policy_identity_completeness' => 1.0,
                'unresolved_conflict_count' => 0,
                'post_cutoff_backfill_count' => 0,
                'source_failure_classification' => '100_PERCENT_OR_ZERO_DENOMINATOR_PASS',
                'scheduler_opportunity_capture_rate' => 1.0,
                'outcome_or_performance_metrics_allowed' => false,
            ],
            'operational_live' => [
                'minimum_distinct_eligible_race_codes' => 2,
                'multiple_windows_required' => false,
                'multiple_days_required' => false,
                'minimum_venues_required' => null,
                'live_strict_before_cutoff_required' => true,
                'replay_or_backfill_count' => 0,
            ],
            'comparative_limitations' => [
                'comparable_use_count' => 40,
                'ml_only_use_count' => 83,
                'ml_only_excluded_from_comparison_denominator' => true,
                'course_2_traditional' => 'NONE',
                'synthetic_traditional_prohibited' => true,
            ],
            'formal_evaluation_record_required' => false,
        ];
    }

    public static function identity(): string
    {
        return 'sha256:' . hash('sha256', self::canonicalJson(self::definition()));
    }

    /**
     * @param array<string,bool> $signals one boolean per hard-gate name
     * @return array{status:string,gates:array<string,string>,failed:list<string>}
     */
    public static function evaluateHardGates(array $signals): array
    {
        $gates = [];
        $failed = [];
        foreach (self::hardGateNames() as $name) {
            $pass = ($signals[$name] ?? false) === true;
            $gates[$name] = $pass ? 'PASS' : 'FAIL';
            if (!$pass) $failed[] = $name;
        }
        return ['status' => $failed === [] ? 'PASS' : 'FAIL', 'gates' => $gates, 'failed' => $failed];
    }

    /**
     * @param array<string,int|float> $metrics
     * @return array{status:string,hard_failures:list<string>,metrics:array<string,int|float>}
     */
    public static function evaluateNumericDataQuality(array $metrics): array
    {
        $required = self::definition()['numeric_data_quality'];
        $failures = [];
        foreach ([
            'expected_completeness', 'canonical_completeness', 'eligible_opportunity_capture_rate',
            'artifact_identity_completeness', 'policy_identity_completeness',
            'scheduler_opportunity_capture_rate',
        ] as $field) {
            if ((float)($metrics[$field] ?? -1) < (float)$required[$field]) $failures[] = $field;
        }
        foreach (['unclassified_missing_count', 'unresolved_conflict_count', 'post_cutoff_backfill_count'] as $field) {
            if ((int)($metrics[$field] ?? -1) !== 0) $failures[] = $field;
        }
        $sourceFailures = (int)($metrics['source_failure_count'] ?? -1);
        $classified = (int)($metrics['classified_source_failure_count'] ?? -1);
        if ($sourceFailures < 0 || $classified < 0 || ($sourceFailures > 0 && $classified !== $sourceFailures)) {
            $failures[] = 'source_failure_classification';
        }
        return ['status' => $failures === [] ? 'PASS' : 'HARD_FAIL', 'hard_failures' => $failures, 'metrics' => $metrics];
    }

    /**
     * @param array<string,int|float|bool> $metrics
     * @return array{status:string,failures:list<string>}
     */
    public static function evaluateOperationalLive(array $metrics): array
    {
        $definition = self::definition();
        $numeric = self::evaluateNumericDataQuality($metrics);
        $failures = $numeric['hard_failures'];
        if ((int)($metrics['distinct_eligible_race_codes'] ?? 0) < (int)$definition['operational_live']['minimum_distinct_eligible_race_codes']) {
            $failures[] = 'distinct_eligible_race_codes';
        }
        if (empty($metrics['live_strict_before_cutoff'])) $failures[] = 'live_strict_before_cutoff';
        if ((int)($metrics['replay_or_backfill_count'] ?? -1) !== 0) $failures[] = 'replay_or_backfill_count';
        return ['status' => $failures === [] ? 'PASS' : 'FAIL', 'failures' => array_values(array_unique($failures))];
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
