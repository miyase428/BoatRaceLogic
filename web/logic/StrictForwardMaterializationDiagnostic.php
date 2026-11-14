<?php

declare(strict_types=1);

/**
 * Read-only diagnostic for the TOP status projection.
 *
 * The formal Gate C predicate remains owned by StrictForwardAcceptedRace.
 * This companion only recognizes a structurally valid partial 18-row matrix;
 * it cannot accept a race or write evidence.
 */
final class StrictForwardMaterializationDiagnostic
{
    private const TARGET_COUNT = 18;
    private const TARGETS = ['top3', 'top2', 'first'];
    private const INVALID_STATUSES = [
        'ARTIFACT_ERROR', 'INVALID', 'BLOCKED', 'TAINTED', 'UNRESOLVED',
        'INFERENCE_INPUT_EMPTY', 'INFERENCE_INPUT_INCOMPLETE', 'INFERENCE_INPUT_ERROR',
    ];

    /**
     * @param list<array<string,mixed>> $expectedRows
     * @param list<array<string,mixed>> $canonicalRows
     * @param array<string,mixed> $context
     * @return array{state:string,reason:string,expected_count:int,canonical_count:int,latest_artifact_at:?string}
     */
    public static function diagnose(array $expectedRows, array $canonicalRows, array $context): array
    {
        if (empty($context['formal_schedule_accepted'])) {
            return self::result('INVALID', 'FORMAL_SCHEDULE_NOT_ACCEPTED', $expectedRows, $canonicalRows);
        }
        $windowId = trim((string)($context['window_id'] ?? ''));
        $candidateKey = trim((string)($context['scheduler_candidate_key'] ?? ''));
        $implementation = is_array($context['implementation'] ?? null) ? $context['implementation'] : [];
        if ($windowId === '' || $candidateKey === '' || $implementation === []) {
            return self::result('INVALID', 'ACCEPTANCE_CONTEXT_INVALID', $expectedRows, $canonicalRows);
        }
        $raceCode = str_starts_with($candidateKey, 'scheduler-opportunity|')
            ? substr($candidateKey, strlen('scheduler-opportunity|'))
            : '';
        if (!preg_match('/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $raceCode)) {
            return self::result('INVALID', 'SCHEDULER_CANDIDATE_KEY_INVALID', $expectedRows, $canonicalRows);
        }

        $allowed = self::allowedKeys($raceCode);
        $expectedKeys = self::partialKeySet($expectedRows, $allowed);
        $canonicalKeys = self::partialKeySet($canonicalRows, $allowed);
        if ($expectedKeys === null || $canonicalKeys === null) {
            return self::result('INVALID', 'DUPLICATE_OR_UNEXPECTED_KEY', $expectedRows, $canonicalRows);
        }
        if (array_diff($canonicalKeys, $expectedKeys) !== []) {
            return self::result('INVALID', 'CANONICAL_KEY_WITHOUT_EXPECTED_KEY', $expectedRows, $canonicalRows);
        }
        foreach (array_merge($expectedRows, $canonicalRows) as $row) {
            if (!self::matchesIdentity($row, $windowId, $implementation)) {
                return self::result('INVALID', 'IMPLEMENTATION_OR_WINDOW_MISMATCH', $expectedRows, $canonicalRows);
            }
        }
        foreach ($canonicalRows as $row) {
            if ((string)($row['scheduler_candidate_key'] ?? '') !== $candidateKey
                || (string)($row['capture_mode'] ?? '') !== 'LIVE_STRICT'
                || empty($row['before_cutoff'])
                || !self::beforeCutoff($row)) {
                return self::result('INVALID', 'LIVE_STRICT_TIMING_OR_TRACE_INVALID', $expectedRows, $canonicalRows);
            }
            $status = trim((string)($row['capture_status'] ?? ''));
            $reason = trim((string)($row['eligibility_reason'] ?? $row['exclusion_reason'] ?? ''));
            if (in_array($status, self::INVALID_STATUSES, true) || in_array($reason, self::INVALID_STATUSES, true)) {
                return self::result('INVALID', 'INVALID_DEPLOYED_DECISION', $expectedRows, $canonicalRows);
            }
        }

        if (count($expectedKeys) === self::TARGET_COUNT && count($canonicalKeys) === self::TARGET_COUNT) {
            return self::result('COMPLETE', 'STRUCTURAL_KEYSET_COMPLETE', $expectedRows, $canonicalRows);
        }
        return self::result('IN_PROGRESS', 'STRUCTURAL_KEYSET_MATERIALIZING', $expectedRows, $canonicalRows);
    }

    /** @param array<string,mixed> $diagnostic */
    public static function displayAsInProgress(array $diagnostic, bool $explicitFailure, bool $stale, bool $runtimeAuthorized): bool
    {
        return ($diagnostic['state'] ?? '') === 'IN_PROGRESS'
            && !$explicitFailure
            && !$stale
            && $runtimeAuthorized;
    }

    /** @return array<string,true> */
    private static function allowedKeys(string $raceCode): array
    {
        $venue = substr($raceCode, 8, 3);
        $keys = [];
        foreach (range(1, 6) as $course) foreach (self::TARGETS as $target) {
            $keys['READINESS_COMPLETION|' . $raceCode . '|' . $venue . '|' . $course . '|' . $target . '|exhibition'] = true;
        }
        return $keys;
    }

    /** @param list<array<string,mixed>> $rows @param array<string,true> $allowed @return list<string>|null */
    private static function partialKeySet(array $rows, array $allowed): ?array
    {
        $keys = [];
        foreach ($rows as $row) {
            $key = trim((string)($row['stable_unique_key'] ?? ''));
            if ($key === '' || !isset($allowed[$key]) || isset($keys[$key])) return null;
            $keys[$key] = true;
        }
        $result = array_keys($keys);
        sort($result, SORT_STRING);
        return $result;
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
            return new DateTimeImmutable((string)($row['captured_at'] ?? ''))
                < new DateTimeImmutable((string)($row['cutoff_timestamp'] ?? ''));
        } catch (Throwable) { return false; }
    }

    /**
     * @param list<array<string,mixed>> $expectedRows
     * @param list<array<string,mixed>> $canonicalRows
     * @return array{state:string,reason:string,expected_count:int,canonical_count:int,latest_artifact_at:?string}
     */
    private static function result(string $state, string $reason, array $expectedRows, array $canonicalRows): array
    {
        $latest = null;
        foreach (array_merge($expectedRows, $canonicalRows) as $row) foreach (['expected_at', 'captured_at'] as $field) {
            $value = trim((string)($row[$field] ?? ''));
            if ($value === '') continue;
            try { $timestamp = new DateTimeImmutable($value); } catch (Throwable) { continue; }
            if ($latest === null || $timestamp > $latest) $latest = $timestamp;
        }
        return [
            'state' => $state,
            'reason' => $reason,
            'expected_count' => count($expectedRows),
            'canonical_count' => count($canonicalRows),
            'latest_artifact_at' => $latest?->format(DATE_ATOM),
        ];
    }
}
