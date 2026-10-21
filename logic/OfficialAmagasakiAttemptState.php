<?php

declare(strict_types=1);

/**
 * Pure state machine for a future official AMG daytime collector.  It neither
 * reads nor writes a state file or database.  A production adapter must use
 * an atomic compare-and-swap claim before it performs an external request.
 */
final class OfficialAmagasakiAttemptState
{
    public const SOURCE = 'official_amagasaki';
    public const MAX_ATTEMPTS = 2;

    /** @return array<string,mixed> */
    public static function initial(string $raceCode): array
    {
        return [
            'race_code' => $raceCode, 'source' => self::SOURCE, 'outcome' => 'not_attempted',
            'attempt_started_at' => null, 'attempt_finished_at' => null, 'retry_not_before' => null,
            'attempt_count' => 0, 'terminal' => false, 'manual_review_required' => false,
            'last_error_reason' => null, 'claim_token' => null, 'claim_expires_at' => null, 'version' => 0,
        ];
    }

    /** @return array{valid:bool,state:array<string,mixed>,reason:string} */
    public static function normalise(string $raceCode, mixed $input): array
    {
        $state = self::initial($raceCode);
        if ($input === null || $input === []) return ['valid' => true, 'state' => $state, 'reason' => ''];
        if (!is_array($input) || ($input['race_code'] ?? null) !== $raceCode || ($input['source'] ?? null) !== self::SOURCE) {
            return ['valid' => false, 'state' => $state, 'reason' => 'ATTEMPT_STATE_IDENTITY_INVALID'];
        }
        foreach ($state as $field => $_) if (array_key_exists($field, $input)) $state[$field] = $input[$field];
        $allowed = ['not_attempted', 'in_progress', 'not_yet_published', 'saved', 'http_restricted', 'server_error', 'parse_error', 'validation_error', 'source_unknown'];
        if (!in_array($state['outcome'], $allowed, true) || !is_int($state['attempt_count']) && !ctype_digit((string)$state['attempt_count'])) {
            return ['valid' => false, 'state' => self::initial($raceCode), 'reason' => 'ATTEMPT_STATE_SCHEMA_INVALID'];
        }
        $state['attempt_count'] = (int)$state['attempt_count'];
        $state['version'] = (int)$state['version'];
        if ($state['attempt_count'] < 0 || $state['attempt_count'] > self::MAX_ATTEMPTS || $state['version'] < 0
            || !is_bool($state['terminal']) || !is_bool($state['manual_review_required'])) {
            return ['valid' => false, 'state' => self::initial($raceCode), 'reason' => 'ATTEMPT_STATE_SCHEMA_INVALID'];
        }
        if (($state['outcome'] === 'not_attempted' && $state['attempt_count'] !== 0)
            || ($state['outcome'] !== 'not_attempted' && $state['attempt_count'] === 0)) {
            return ['valid' => false, 'state' => self::initial($raceCode), 'reason' => 'ATTEMPT_STATE_TRANSITION_INVALID'];
        }
        foreach (['attempt_started_at', 'attempt_finished_at', 'retry_not_before', 'claim_expires_at'] as $field) {
            if ($state[$field] !== null && !self::timestamp((string)$state[$field])) {
                return ['valid' => false, 'state' => self::initial($raceCode), 'reason' => 'ATTEMPT_STATE_TIMESTAMP_INVALID'];
            }
        }
        if ($state['outcome'] === 'in_progress' && (!is_string($state['claim_token']) || $state['claim_token'] === '' || $state['claim_expires_at'] === null)) {
            return ['valid' => false, 'state' => self::initial($raceCode), 'reason' => 'ATTEMPT_CLAIM_INVALID'];
        }
        if ($state['outcome'] !== 'in_progress' && ($state['claim_token'] !== null || $state['claim_expires_at'] !== null)) {
            return ['valid' => false, 'state' => self::initial($raceCode), 'reason' => 'ATTEMPT_CLAIM_INVALID'];
        }
        return ['valid' => true, 'state' => $state, 'reason' => ''];
    }

    /** @return array<string,mixed> */
    public static function claim(array $state, DateTimeImmutable $startedAt, string $token, int $leaseSeconds): array
    {
        if ($leaseSeconds <= 0 || $token === '' || ($state['terminal'] ?? true) || (int)($state['attempt_count'] ?? self::MAX_ATTEMPTS) >= self::MAX_ATTEMPTS) {
            throw new InvalidArgumentException('invalid attempt claim');
        }
        $state['outcome'] = 'in_progress';
        $state['attempt_started_at'] = self::format($startedAt);
        $state['attempt_finished_at'] = null;
        $state['retry_not_before'] = null;
        $state['attempt_count'] = (int)$state['attempt_count'] + 1;
        $state['claim_token'] = $token;
        $state['claim_expires_at'] = self::format($startedAt->modify('+' . $leaseSeconds . ' seconds'));
        $state['version'] = (int)$state['version'] + 1;
        return $state;
    }

    /** @return array<string,mixed> */
    public static function complete(array $state, string $token, string $outcome, DateTimeImmutable $finishedAt, ?int $retryIntervalSeconds, bool $serverRetryAllowed): array
    {
        if (($state['outcome'] ?? null) !== 'in_progress' || !hash_equals((string)($state['claim_token'] ?? ''), $token)) {
            throw new InvalidArgumentException('attempt claim token mismatch');
        }
        $startedAt = self::timestamp((string)($state['attempt_started_at'] ?? ''));
        if ($startedAt === null || $finishedAt < $startedAt) throw new InvalidArgumentException('attempt completion time invalid');
        $allowed = ['not_yet_published', 'saved', 'http_restricted', 'server_error', 'parse_error', 'validation_error', 'source_unknown'];
        if (!in_array($outcome, $allowed, true)) throw new InvalidArgumentException('attempt outcome invalid');
        $state['outcome'] = $outcome;
        $state['attempt_finished_at'] = self::format($finishedAt);
        $state['claim_token'] = null;
        $state['claim_expires_at'] = null;
        $state['retry_not_before'] = null;
        $state['terminal'] = true;
        $state['manual_review_required'] = false;
        $state['last_error_reason'] = null;
        $mayRetry = ($outcome === 'not_yet_published' || ($outcome === 'server_error' && $serverRetryAllowed))
            && (int)$state['attempt_count'] < self::MAX_ATTEMPTS && $retryIntervalSeconds !== null && $retryIntervalSeconds >= 0;
        if ($mayRetry) {
            $state['terminal'] = false;
            $state['retry_not_before'] = self::format($finishedAt->modify('+' . $retryIntervalSeconds . ' seconds'));
        } elseif (in_array($outcome, ['http_restricted', 'server_error', 'parse_error', 'validation_error', 'source_unknown'], true)) {
            $state['manual_review_required'] = true;
            $state['last_error_reason'] = strtoupper($outcome);
        }
        $state['version'] = (int)$state['version'] + 1;
        return $state;
    }

    public static function timestamp(string $value): ?DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{3})?(?:Z|[+-]\d{2}:\d{2})$/', $value)) return null;
        try { return new DateTimeImmutable($value); } catch (Throwable) { return null; }
    }

    public static function format(DateTimeImmutable $value): string { return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.v\\Z'); }
}

interface OfficialAmagasakiAttemptClaimStore
{
    /**
     * Atomically writes the supplied in-progress state only if the stored
     * version equals $expectedVersion.  An absent row has version zero.
     */
    public function claim(string $raceCode, int $expectedVersion, array $state): bool;
}
