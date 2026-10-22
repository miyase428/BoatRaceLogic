<?php

declare(strict_types=1);

require_once __DIR__ . '/OfficialAmagasakiDaytimeFlow.php';
require_once __DIR__ . '/OfficialAmagasakiAttemptRepository.php';
require_once __DIR__ . '/OfficialAmagasakiWriteBarrier.php';

/** The adapter result is intentionally explicit: no exception is a success. */
interface OfficialAmagasakiPayloadFetcher
{
    /** @return array{outcome:string,payload?:array<string,mixed>,error_reason?:string} */
    public function fetch(string $raceCode): array;
}

/**
 * Atomic persistence boundary for one claimed race.  Implementations must make
 * the six exhibition rows, source audit, and transition out of in_progress
 * one transaction.  A false result means commit status is unknown to callers,
 * so the race remains manual-review only.
 */
interface OfficialAmagasakiClaimedPersistence
{
    /**
     * @param array<string,mixed> $payload @param list<array<string,mixed>> $raceEntries
     * @param array<string,mixed> $claimedState
     * @return array{committed:bool,status:string,comparison:string}
     */
    public function persistAndComplete(array $payload, string $raceCode, array $raceEntries, array $claimedState, string $claimToken, DateTimeImmutable $finishedAt): array;
}

/**
 * PDO transaction adapter for the future approved worker.  It shares one PDO
 * connection across exhibition rows, audit, and attempt completion.  It is not
 * constructed or invoked by the dry-run entry point.
 */
final class PdoOfficialAmagasakiClaimedPersistence implements OfficialAmagasakiClaimedPersistence
{
    private PdoOfficialExhibitionRepository $exhibitions;
    private OfficialAmagasakiExhibitionTransactionStore $storage;
    private PdoOfficialAmagasakiAttemptRepository $attempts;

    public function __construct(private readonly PDO $pdo, OfficialAmagasakiExhibitionValidator $validator, ?PdoOfficialAmagasakiAttemptRepository $attempts = null, private readonly ?int $retryIntervalSeconds = null)
    {
        $this->exhibitions = new PdoOfficialExhibitionRepository($pdo);
        $this->storage = new OfficialAmagasakiExhibitionTransactionStore($this->exhibitions, $validator);
        $this->attempts = $attempts ?? new PdoOfficialAmagasakiAttemptRepository($pdo);
    }

    public function persistAndComplete(array $payload, string $raceCode, array $raceEntries, array $claimedState, string $claimToken, DateTimeImmutable $finishedAt): array
    {
        $begun = false;
        try {
            $this->pdo->beginTransaction();
            $begun = true;
            OfficialAmagasakiWriteBarrier::lock($this->pdo, $raceCode);
            $saved = $this->storage->persistWithinOpenTransaction($payload, $raceCode, $raceEntries);
            $outcome = $saved['status'] === 'saved' ? 'saved' : $this->stateOutcomeForStorageStatus($saved['status']);
            $completed = OfficialAmagasakiAttemptState::complete($claimedState, $claimToken, $outcome, $finishedAt, $this->retryIntervalSeconds, false);
            if (!$this->attempts->complete($claimedState, $claimToken, $completed)) {
                throw new RuntimeException('attempt_completion_compare_and_swap_failed');
            }
            $this->pdo->commit();
            return ['committed' => true, 'status' => $saved['status'], 'comparison' => $saved['comparison']];
        } catch (Throwable) {
            if ($begun && $this->pdo->inTransaction()) $this->pdo->rollBack();
            return ['committed' => false, 'status' => 'rolled_back', 'comparison' => 'none'];
        }
    }

    private function stateOutcomeForStorageStatus(string $status): string
    {
        return match ($status) {
            'requires_review_source_unknown' => 'source_unknown',
            'requires_review_partial', 'requires_review_mismatch' => 'validation_error',
            default => 'validation_error',
        };
    }
}

/**
 * Side-effect boundary for the proposed AMG daytime worker.  `run(..., true)`
 * is a pure planner: it never calls the fetcher, PDO adapter, or claim store.
 * Normal mode still cannot fetch unless its router was constructed with an
 * explicit approval.
 */
final class OfficialAmagasakiDaytimeIntegration
{
    public function __construct(
        private readonly OfficialAmagasakiDaytimeFlow $flow,
        private readonly OfficialAmagasakiAttemptRepository $attempts,
        private readonly OfficialAmagasakiPayloadFetcher $fetcher,
        private readonly OfficialAmagasakiClaimedPersistence $persistence,
        private readonly ?Closure $clock = null,
    ) {
    }

    /**
     * @param list<array{race_code:string,deadline:string,prediction_cutoff:string,existing_status:string,race_entries:list<array<string,mixed>>,attempt_state?:array<string,mixed>|null}> $candidates
     * @return list<array<string,mixed>>
     */
    public function run(array $candidates, DateTimeImmutable $now, bool $dryRun): array
    {
        $events = [];
        foreach ($candidates as $candidate) {
            $state = $candidate['attempt_state'] ?? null;
            // A dry run must make the same plan as execution while remaining
            // read-only.  Loading the persisted state is a SELECT-only
            // operation; claims, fetches, and persistence remain below the
            // explicit dry-run return path.
            if ($state === null) $state = $this->attempts->load($candidate['race_code']);
            $planCandidate = $candidate;
            $planCandidate['attempt_state'] = $state;
            $plan = $this->flow->plan($planCandidate, $now);
            $event = ['race_code' => $candidate['race_code'], 'action' => $plan['action'], 'reason' => $plan['reason'], 'dry_run' => $dryRun];
            if ($dryRun || !in_array($plan['action'], ['claim_first', 'claim_retry'], true)) {
                $events[] = $event;
                continue;
            }

            $token = bin2hex(random_bytes(24));
            $claimed = $this->flow->claim($plan, $now, $this->attempts, $token);
            if (!$claimed['claimed']) {
                $events[] = array_merge($event, ['action' => 'skip', 'reason' => $claimed['reason']]);
                continue;
            }

            $result = $this->fetcher->fetch($candidate['race_code']);
            $finished = $this->nowUtc();
            $outcome = (string)($result['outcome'] ?? 'parse_error');
            if ($outcome !== 'published') {
                $normalisedOutcome = in_array($outcome, ['not_yet_published', 'http_restricted', 'server_error', 'parse_error', 'validation_error', 'source_unknown'], true)
                    ? $outcome : 'parse_error';
                $errorReason = OfficialAmagasakiAttemptState::safeErrorReason($result['error_reason'] ?? null, strtoupper($normalisedOutcome));
                $completed = $this->completeWithoutSave($claimed['state'], $token, $normalisedOutcome, $finished, $errorReason);
                $events[] = array_merge($event, ['action' => $completed ? 'no_save' : 'review', 'reason' => $completed ? strtoupper($normalisedOutcome) : 'ATTEMPT_COMPLETION_CAS_FAILED', 'error_reason' => $errorReason]);
                continue;
            }

            $payload = $result['payload'] ?? null;
            if (!is_array($payload) || !$this->payloadCompletionWithinAttempt($payload, $claimed['state'], $finished)
                || !$this->withinDeadlineSafety($candidate['deadline'], $finished)
                || !$this->flow->evaluatePayload($payload, $candidate['race_code'], $candidate['race_entries'], $candidate['prediction_cutoff'])['forward_eligible']) {
                $completed = $this->completeWithoutSave($claimed['state'], $token, 'validation_error', $finished);
                $events[] = array_merge($event, ['action' => $completed ? 'no_save' : 'review', 'reason' => $completed ? 'PAYLOAD_OR_TIME_REJECTED' : 'ATTEMPT_COMPLETION_CAS_FAILED']);
                continue;
            }

            $saved = $this->persistence->persistAndComplete($payload, $candidate['race_code'], $candidate['race_entries'], $claimed['state'], $token, $finished);
            $events[] = array_merge($event, ['action' => $saved['committed'] && $saved['status'] === 'saved' ? 'saved' : 'review', 'reason' => $saved['status'], 'comparison' => $saved['comparison']]);
        }
        return $events;
    }

    /** @param array<string,mixed> $claimedState */
    private function completeWithoutSave(array $claimedState, string $token, string $outcome, DateTimeImmutable $finished, ?string $errorReason = null): bool
    {
        $normalisedOutcome = match ($outcome) {
            'not_yet_published', 'http_restricted', 'server_error', 'parse_error', 'validation_error', 'source_unknown' => $outcome,
            default => 'parse_error',
        };
        $completed = $this->flow->completeClaim($claimedState, $token, $normalisedOutcome, $finished, $errorReason);
        return $this->attempts->complete($claimedState, $token, $completed);
    }

    private function withinDeadlineSafety(string $deadlineText, DateTimeImmutable $finished): bool
    {
        try {
            $deadline = new DateTimeImmutable($deadlineText);
            return $finished < $deadline->modify('-120 seconds');
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $claimedState */
    private function payloadCompletionWithinAttempt(array $payload, array $claimedState, DateTimeImmutable $finished): bool
    {
        $fetched = OfficialAmagasakiAttemptState::timestamp((string)($payload['fetched_at'] ?? ''));
        $started = OfficialAmagasakiAttemptState::timestamp((string)($claimedState['attempt_started_at'] ?? ''));
        return $fetched !== null && $started !== null && $fetched >= $started && $fetched <= $finished;
    }

    private function nowUtc(): DateTimeImmutable
    {
        $now = $this->clock === null ? new DateTimeImmutable('now', new DateTimeZone('UTC')) : ($this->clock)();
        if (!$now instanceof DateTimeImmutable) throw new RuntimeException('daytime integration clock returned invalid value');
        return $now->setTimezone(new DateTimeZone('UTC'));
    }
}
