<?php

declare(strict_types=1);

require_once __DIR__ . '/OfficialExhibitionSourceRouter.php';
require_once __DIR__ . '/OfficialAmagasakiExhibitionStorage.php';
require_once __DIR__ . '/OfficialAmagasakiAttemptState.php';

/**
 * Deterministic decision layer for a future AMG daytime job.  It performs no
 * I/O: callers supply the official deadline, the current database/audit state,
 * and a decoded official payload.  It is not connected to any current cron,
 * web endpoint, collector, or Strict Forward writer.
 */
final class OfficialAmagasakiDaytimeFlow
{
    public function __construct(
        private readonly OfficialExhibitionSourceRouter $router,
        private readonly OfficialAmagasakiExhibitionValidator $validator,
        private readonly int $cutoffSafetySeconds = 120,
        private readonly ?int $minimumRetryIntervalSeconds = null,
        private readonly bool $serverRetryApproved = false,
        private readonly int $claimLeaseSeconds = 90,
    ) {
        if ($this->cutoffSafetySeconds < 0
            || ($this->minimumRetryIntervalSeconds !== null && $this->minimumRetryIntervalSeconds < 0)
            || $this->claimLeaseSeconds <= 0) {
            throw new InvalidArgumentException('invalid daytime attempt policy');
        }
    }

    /**
     * @param array{race_code:string,deadline:string,existing_status:string,attempt_state?:array<string,mixed>|null} $candidate
     * @return array{action:string,reason:string,source:string,deadline:string,state:array<string,mixed>}
     */
    public function plan(array $candidate, DateTimeImmutable $now): array
    {
        $route = $this->router->route($candidate['race_code'], 'strict_forward');
        $normalised = OfficialAmagasakiAttemptState::normalise($candidate['race_code'], $candidate['attempt_state'] ?? null);
        $base = ['source' => $route['source'], 'deadline' => $candidate['deadline'], 'state' => $normalised['state']];
        if ($route['source'] !== 'official_amagasaki') return $base + ['action' => 'not_owned', 'reason' => 'LEGACY_SOURCE_REMAINS_UNCHANGED'];
        if ($route['action'] !== 'official_fetch') return $base + ['action' => 'blocked', 'reason' => $route['reason']];
        if (!$normalised['valid']) return $base + ['action' => 'blocked', 'reason' => $normalised['reason']];
        if (!in_array($candidate['existing_status'], ['none', 'already_official', 'partial', 'source_unknown', 'mismatch'], true)) {
            return $base + ['action' => 'blocked', 'reason' => 'EXISTING_STATUS_INVALID'];
        }
        if ($candidate['existing_status'] === 'already_official') return $base + ['action' => 'skip', 'reason' => 'OFFICIAL_SIX_BOATS_ALREADY_AUDITED'];
        if ($candidate['existing_status'] !== 'none') return $base + ['action' => 'review', 'reason' => 'EXISTING_ROWS_REQUIRE_AUDIT_REVIEW'];
        try {
            $deadline = new DateTimeImmutable($candidate['deadline']);
        } catch (Throwable) {
            return $base + ['action' => 'blocked', 'reason' => 'OFFICIAL_DEADLINE_INVALID'];
        }
        if ($deadline->getTimestamp() - $now->getTimestamp() <= $this->cutoffSafetySeconds) {
            return $base + ['action' => 'skip', 'reason' => 'CUTOFF_SAFETY_WINDOW_CLOSED'];
        }
        $state = $normalised['state'];
        if ($state['outcome'] === 'in_progress') {
            $expiry = OfficialAmagasakiAttemptState::timestamp((string)$state['claim_expires_at']);
            return $base + ['action' => $expiry !== null && $expiry >= $now ? 'skip' : 'review', 'reason' => $expiry !== null && $expiry >= $now ? 'ATTEMPT_ALREADY_CLAIMED' : 'STALE_ATTEMPT_CLAIM_REQUIRES_REVIEW'];
        }
        if ($state['terminal'] || (int)$state['attempt_count'] >= OfficialAmagasakiAttemptState::MAX_ATTEMPTS) {
            return $base + ['action' => $state['manual_review_required'] ? 'review' : 'skip', 'reason' => $state['manual_review_required'] ? 'ATTEMPT_MANUAL_REVIEW_REQUIRED' : 'RETRY_LIMIT_REACHED'];
        }
        $seconds = $deadline->getTimestamp() - $now->getTimestamp();
        if ($state['outcome'] === 'not_attempted') {
            if ($seconds <= 600 && $seconds > 480) return $base + ['action' => 'claim_first', 'reason' => 'FIRST_FETCH_WINDOW'];
            return $base + ['action' => 'skip', 'reason' => $seconds > 600 ? 'BEFORE_FIRST_FETCH_WINDOW' : 'FIRST_FETCH_WINDOW_MISSED'];
        }
        if ($state['outcome'] === 'not_yet_published') {
            $retryAt = OfficialAmagasakiAttemptState::timestamp((string)$state['retry_not_before']);
            if ($retryAt === null) return $base + ['action' => 'blocked', 'reason' => 'RETRY_NOT_BEFORE_INVALID'];
            if ($now < $retryAt) return $base + ['action' => 'skip', 'reason' => 'RETRY_NOT_BEFORE_REACHED'];
            if ($seconds <= 300 && $seconds > 120) return $base + ['action' => 'claim_retry', 'reason' => 'RETRY_FETCH_WINDOW'];
            return $base + ['action' => 'skip', 'reason' => $seconds > 300 ? 'BEFORE_RETRY_FETCH_WINDOW' : 'RETRY_FETCH_WINDOW_MISSED'];
        }
        return $base + ['action' => 'review', 'reason' => 'ATTEMPT_OUTCOME_REQUIRES_REVIEW'];
    }

    /** @return array{claimed:bool,state:array<string,mixed>,reason:string} */
    public function claim(array $plan, DateTimeImmutable $startedAt, OfficialAmagasakiAttemptClaimStore $store, string $token): array
    {
        if (!in_array($plan['action'] ?? '', ['claim_first', 'claim_retry'], true)) return ['claimed' => false, 'state' => $plan['state'] ?? [], 'reason' => 'PLAN_NOT_CLAIMABLE'];
        $state = OfficialAmagasakiAttemptState::claim((array)$plan['state'], $startedAt, $token, $this->claimLeaseSeconds);
        $claimed = $store->claim((string)$state['race_code'], (int)$plan['state']['version'], $state);
        return ['claimed' => $claimed, 'state' => $claimed ? $state : (array)$plan['state'], 'reason' => $claimed ? '' : 'ATOMIC_CLAIM_REJECTED'];
    }

    /** @return array<string,mixed> */
    public function completeClaim(array $state, string $token, string $outcome, DateTimeImmutable $finishedAt, ?string $errorReason = null): array
    {
        return OfficialAmagasakiAttemptState::complete($state, $token, $outcome, $finishedAt, $this->minimumRetryIntervalSeconds, $this->serverRetryApproved, $errorReason);
    }

    /**
     * Validates a completed fetch.  `not_yet_published` is neither a success
     * nor a save: the scheduler needs an explicitly persisted attempt policy
     * before it may schedule another external request.
     *
     * @param array<string,mixed> $payload @param list<array<string,mixed>> $raceEntries
     * @return array{action:string,reason:string,forward_eligible:bool}
     */
    public function evaluatePayload(array $payload, string $raceCode, array $raceEntries, string $cutoff): array
    {
        if (($payload['status'] ?? null) === 'not_yet_published') {
            return ['action' => 'no_save', 'reason' => 'OFFICIAL_NOT_YET_PUBLISHED', 'forward_eligible' => false];
        }
        if (($payload['status'] ?? null) !== 'published') {
            return ['action' => 'no_save', 'reason' => 'OFFICIAL_FETCH_OR_PARSE_FAILED', 'forward_eligible' => false];
        }
        try {
            $this->validator->validateAndConvert($payload, $raceCode, $raceEntries);
        } catch (Throwable) {
            return ['action' => 'no_save', 'reason' => 'OFFICIAL_PAYLOAD_OR_ENTRY_MISMATCH', 'forward_eligible' => false];
        }
        if (!$this->validator->isForwardEligible($payload, $cutoff)) {
            return ['action' => 'no_save', 'reason' => 'OFFICIAL_FETCH_COMPLETED_AFTER_CUTOFF', 'forward_eligible' => false];
        }
        return ['action' => 'save', 'reason' => 'OFFICIAL_PAYLOAD_VALID_BEFORE_CUTOFF', 'forward_eligible' => true];
    }

    /**
     * Existing 23:00 processing must not overwrite a daytime official save.
     * This is a policy function only; it changes no nightly job in this phase.
     */
    public function nightlyOverwriteAllowed(string $raceCode, bool $hasSavedOfficialAudit): bool
    {
        return !$this->isOfficialAmagasakiTarget($raceCode) || !$hasSavedOfficialAudit;
    }

    /** A saved row with unknown provenance never becomes forward evidence. */
    public function strictForwardAvailable(bool $hasSavedOfficialAudit, string $fetchedAt, string $predictionCutoff): bool
    {
        if (!$hasSavedOfficialAudit) return false;
        return $this->validator->isForwardEligible(['fetched_at' => $fetchedAt], $predictionCutoff);
    }

    private function isOfficialAmagasakiTarget(string $raceCode): bool
    {
        return preg_match('/^(\d{8})AMG(?:0[1-9]|1[0-2])$/', $raceCode, $matches) === 1
            && $matches[1] >= OfficialExhibitionSourceRouter::OFFICIAL_START_DATE;
    }
}
