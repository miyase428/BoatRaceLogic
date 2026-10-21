<?php

declare(strict_types=1);

require_once __DIR__ . '/OfficialExhibitionSourceRouter.php';
require_once __DIR__ . '/OfficialAmagasakiExhibitionStorage.php';

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
    ) {
        if ($this->cutoffSafetySeconds < 0) throw new InvalidArgumentException('negative cutoff safety');
    }

    /**
     * @param array{race_code:string,deadline:string,existing_status:string,official_attempted:bool} $candidate
     * @return array{action:string,reason:string,source:string,deadline:string}
     */
    public function plan(array $candidate, DateTimeImmutable $now): array
    {
        $route = $this->router->route($candidate['race_code'], 'strict_forward');
        $base = ['source' => $route['source'], 'deadline' => $candidate['deadline']];
        if ($route['source'] !== 'official_amagasaki') return $base + ['action' => 'not_owned', 'reason' => 'LEGACY_SOURCE_REMAINS_UNCHANGED'];
        if ($route['action'] !== 'official_fetch') return $base + ['action' => 'blocked', 'reason' => $route['reason']];
        if (!in_array($candidate['existing_status'], ['none', 'already_official', 'partial', 'source_unknown', 'mismatch'], true)) {
            return $base + ['action' => 'blocked', 'reason' => 'EXISTING_STATUS_INVALID'];
        }
        if ($candidate['existing_status'] === 'already_official') return $base + ['action' => 'skip', 'reason' => 'OFFICIAL_SIX_BOATS_ALREADY_AUDITED'];
        if ($candidate['existing_status'] !== 'none') return $base + ['action' => 'review', 'reason' => 'EXISTING_ROWS_REQUIRE_AUDIT_REVIEW'];
        if ($candidate['official_attempted']) return $base + ['action' => 'skip', 'reason' => 'OFFICIAL_ATTEMPT_ALREADY_RECORDED'];
        try {
            $deadline = new DateTimeImmutable($candidate['deadline']);
        } catch (Throwable) {
            return $base + ['action' => 'blocked', 'reason' => 'OFFICIAL_DEADLINE_INVALID'];
        }
        if ($deadline->getTimestamp() - $now->getTimestamp() <= $this->cutoffSafetySeconds) {
            return $base + ['action' => 'skip', 'reason' => 'CUTOFF_SAFETY_WINDOW_CLOSED'];
        }
        return $base + ['action' => 'fetch_once', 'reason' => 'OFFICIAL_AMG_DAYTIME_CANDIDATE'];
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
