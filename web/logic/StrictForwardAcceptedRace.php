<?php

declare(strict_types=1);

/**
 * Pure, outcome-blind Gate C race acceptance predicate.
 *
 * The 18-row matrix is structural evidence.  A deliberately non-deployed
 * target remains part of that matrix, but never becomes an accepted ML or
 * comparative decision.  Any unavailable deployed target fails the race.
 */
final class StrictForwardAcceptedRace
{
    private const TARGET_COUNT = 18;
    private const NON_DEPLOYED = ['ML_COURSE_NOT_DEPLOYED', 'ML_TARGET_NOT_DEPLOYED'];
    private const VALID_DECISION_STATUSES = ['READY', 'TRADITIONAL_DECISION_UNAVAILABLE'];
    private const INVALID_STATUSES = [
        'ARTIFACT_ERROR', 'INVALID', 'BLOCKED', 'TAINTED', 'UNRESOLVED',
        'INFERENCE_INPUT_EMPTY', 'INFERENCE_INPUT_INCOMPLETE', 'INFERENCE_INPUT_ERROR',
    ];

    /**
     * @param list<array<string,mixed>> $expectedRows
     * @param list<array<string,mixed>> $canonicalRows
     * @param array<string,mixed> $context formal_schedule_accepted, window_id,
     *   implementation, scheduler_candidate_key
     * @return array{structural_complete:bool,accepted:bool,reason:string,accepted_ml_decision_count:int,comparative_ready_decision_count:int,provenance_valid:bool}
     */
    public static function evaluate(array $expectedRows, array $canonicalRows, array $context): array
    {
        $expectedKeys = self::keySet($expectedRows);
        $canonicalKeys = self::keySet($canonicalRows);
        $structural = count($expectedKeys) === self::TARGET_COUNT
            && count($canonicalKeys) === self::TARGET_COUNT
            && $expectedKeys === $canonicalKeys;
        if (!$structural) return self::failed('STRUCTURAL_KEYSET_INCOMPLETE');
        if (empty($context['formal_schedule_accepted'])) return self::failed('FORMAL_SCHEDULE_NOT_ACCEPTED', true);

        $windowId = trim((string)($context['window_id'] ?? ''));
        $candidateKey = trim((string)($context['scheduler_candidate_key'] ?? ''));
        $implementation = is_array($context['implementation'] ?? null) ? $context['implementation'] : [];
        if ($windowId === '' || $candidateKey === '' || $implementation === []) {
            return self::failed('ACCEPTANCE_CONTEXT_INVALID', true);
        }
        foreach (array_merge($expectedRows, $canonicalRows) as $row) {
            if (!self::matchesIdentity($row, $windowId, $implementation)) {
                return self::failed('IMPLEMENTATION_OR_WINDOW_MISMATCH', true, false);
            }
        }

        $acceptedMl = 0;
        $comparativeReady = 0;
        foreach ($canonicalRows as $row) {
            if ((string)($row['scheduler_candidate_key'] ?? '') !== $candidateKey
                || (string)($row['capture_mode'] ?? '') !== 'LIVE_STRICT'
                || empty($row['before_cutoff'])
                || !self::beforeCutoff($row)) {
                return self::failed('LIVE_STRICT_TIMING_OR_TRACE_INVALID', true);
            }

            $status = trim((string)($row['capture_status'] ?? ''));
            $reason = trim((string)($row['eligibility_reason'] ?? $row['exclusion_reason'] ?? ''));
            if (in_array($status, self::NON_DEPLOYED, true) || in_array($reason, self::NON_DEPLOYED, true)) {
                if (!empty($row['eligible']) || !empty($row['formal_candidate'])) {
                    return self::failed('NON_DEPLOYED_ROW_MARKED_ELIGIBLE', true);
                }
                continue;
            }
            if (in_array($status, self::INVALID_STATUSES, true) || in_array($reason, self::INVALID_STATUSES, true)) {
                return self::failed('INVALID_DEPLOYED_DECISION:' . ($status !== '' ? $status : $reason), true);
            }
            if (!in_array($status, self::VALID_DECISION_STATUSES, true)
                || empty($row['eligible']) || empty($row['formal_candidate'])
                || !is_bool($row['ml_selected'] ?? null)
                || trim((string)($row['artifact_identifier'] ?? '')) === ''
                || trim((string)($row['artifact_sha256'] ?? '')) === ''
                || trim((string)($row['ml_policy_version'] ?? '')) === ''
                || trim((string)($row['logic_version'] ?? '')) === ''
                || trim((string)($row['traditional_policy_version'] ?? '')) === ''
                || trim((string)($row['traditional_logic_version'] ?? '')) === '') {
                return self::failed('DEPLOYED_DECISION_INVALID:' . ($status !== '' ? $status : 'MISSING_STATUS'), true);
            }
            $acceptedMl++;
            if ($status === 'READY' && is_bool($row['traditional_selected'] ?? null)) $comparativeReady++;
        }

        if ($acceptedMl === 0) return self::failed('NO_DEPLOYED_ML_DECISION', true);
        return [
            'structural_complete' => true,
            'accepted' => true,
            'reason' => 'ACCEPTED',
            'accepted_ml_decision_count' => $acceptedMl,
            'comparative_ready_decision_count' => $comparativeReady,
            'provenance_valid' => true,
        ];
    }

    /** @param list<array<string,mixed>> $rows @return list<string> */
    private static function keySet(array $rows): array
    {
        $keys = [];
        foreach ($rows as $row) {
            $key = trim((string)($row['stable_unique_key'] ?? ''));
            if ($key === '' || isset($keys[$key])) return [];
            $keys[$key] = true;
        }
        $keys = array_keys($keys);
        sort($keys, SORT_STRING);
        return $keys;
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $implementation */
    private static function matchesIdentity(array $row, string $windowId, array $implementation): bool
    {
        if (!hash_equals($windowId, (string)($row['window_id'] ?? ''))) return false;
        foreach ([
            'implementation_identity' => 'measurement_implementation_identity',
            'manifest_schema_version' => 'measurement_manifest_schema_version',
            'manifest_sha256' => 'measurement_manifest_sha256',
            'manifest_ref' => 'measurement_manifest_ref',
            'source_freeze_sha256' => 'measurement_source_freeze_sha256',
            'source_freeze_ref' => 'measurement_source_freeze_ref',
        ] as $source => $rowField) {
            if (!hash_equals((string)($implementation[$source] ?? ''), (string)($row[$rowField] ?? ''))) return false;
        }
        return true;
    }

    /** @param array<string,mixed> $row */
    private static function beforeCutoff(array $row): bool
    {
        try {
            $capturedAt = new DateTimeImmutable((string)($row['captured_at'] ?? ''));
            $cutoff = new DateTimeImmutable((string)($row['cutoff_timestamp'] ?? ''));
            return $capturedAt < $cutoff;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{structural_complete:bool,accepted:bool,reason:string,accepted_ml_decision_count:int,comparative_ready_decision_count:int,provenance_valid:bool} */
    private static function failed(string $reason, bool $structuralComplete = false, bool $provenanceValid = true): array
    {
        return [
            'structural_complete' => $structuralComplete,
            'accepted' => false,
            'reason' => $reason,
            'accepted_ml_decision_count' => 0,
            'comparative_ready_decision_count' => 0,
            'provenance_valid' => $provenanceValid,
        ];
    }
}
