<?php

declare(strict_types=1);

require_once __DIR__ . '/OfficialEpochPolicyV1.php';

/**
 * Stable schema helpers for the external, create-only relation between an
 * Official Epoch and a future PREPARED measurement Window.
 */
final class OfficialEpochWindowBindingV1
{
    public const SCHEMA_VERSION = 'official-epoch-window-binding/v1';
    public const PROSPECTIVE_BOUNDARY_CONTRACT = 'cutoff_timestamp > armed_at';
    public const SEMANTICS_EQUIVALENT = 'MEASUREMENT_IMPLEMENTATION_CHANGE_SEMANTICS_EQUIVALENT';

    /** @return list<string> */
    public static function requirementNames(): array
    {
        return [
            'measurement_semantics_unchanged',
            'comparative_scope_unchanged',
            'comparative_policy_semantics_unchanged',
            'hard_gate_policy_unchanged',
            'numeric_policy_semantics_unchanged',
            'incident_policy_semantics_unchanged',
            'affected_evidence_uniquely_isolated',
            'regressions_pass',
            'readiness_pass',
        ];
    }

    /** @param array<string,mixed> $payload */
    public static function hash(array $payload): string
    {
        return 'sha256:' . hash('sha256', OfficialEpochPolicyV1::canonicalJson($payload));
    }

    /** @param array<string,mixed> $stable */
    public static function bindingKey(array $stable): string
    {
        return self::hash($stable);
    }

    public static function validHash(string $value): bool
    {
        return preg_match('/^sha256:[a-f0-9]{64}$/', $value) === 1;
    }

    /** @param array<string,mixed> $record */
    public static function validRecordHash(array $record): bool
    {
        if (($record['schema_version'] ?? '') !== self::SCHEMA_VERSION || !self::validHash((string)($record['record_hash'] ?? ''))) return false;
        $payload = $record;
        unset($payload['record_hash']);
        return hash_equals((string)$record['record_hash'], self::hash($payload));
    }
}
