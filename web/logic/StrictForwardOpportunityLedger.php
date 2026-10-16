<?php

declare(strict_types=1);

require_once __DIR__ . '/FormalMeasurementWindowContext.php';
require_once __DIR__ . '/GateA1ProspectiveInventory.php';

/**
 * Outcome-blind scheduler opportunity denominator ledger.
 *
 * The strict expected ledger begins only after a race is exhibition-ready. This
 * additive ledger deliberately starts earlier: it freezes the official schedule
 * candidate universe, then appends every eligibility observation. It never
 * reads predictions, outcomes, payouts, odds, or the strict expected ledger.
 * All production-facing entry points fail open.
 */
final class StrictForwardOpportunityLedger
{
    private const CANDIDATE_PATTERN = '/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/';
    private const VENUE_PATTERN = '/^[A-Z0-9]{3}$/';

    public function __construct(
        private readonly string $rootPath,
        private readonly ?FormalMeasurementWindowContext $windowContext = null,
    )
    {
    }

    public function rootPath(): string
    {
        return $this->rootPath;
    }

    /**
     * Production instances are bound to the pointed Window. Fixture instances
     * without a context intentionally remain usable for isolated legacy tests.
     */
    private function assertMeasurementWriteAllowed(): void
    {
        if ($this->windowContext === null) return;
        $authorization = $this->windowContext->authorizeMeasurementWrite();
        if (!$authorization['accepted']) throw new RuntimeException((string)$authorization['reason']);
    }

    /** Keep the closure race closed without repeating expensive provenance freeze per row. */
    private function assertLifecycleStillOpen(): void
    {
        if ($this->windowContext === null) return;
        $resolved = $this->windowContext->resolve();
        $status = $resolved['accepted']
            ? $this->windowContext->lifecycle()->authorizeWrite((string)$resolved['window_id'], $resolved['manifest'])
            : ['accepted' => false, 'reason' => (string)$resolved['reason']];
        if (!$status['accepted']) throw new RuntimeException((string)$status['reason']);
    }

    public static function production(?FormalMeasurementWindowContext $windowContext = null): self
    {
        $context = $windowContext ?? FormalMeasurementWindowContext::production(dirname(__DIR__, 2));
        return new self($context->opportunityRoot(), $context);
    }

    /** @param array<string,mixed> $context */
    public static function recordCandidateUniverseFromContext(array $context): void
    {
        try {
            self::production()->recordCandidateUniverse($context);
        } catch (Throwable $error) {
            error_log('strict forward opportunity candidate ledger failed open: ' . $error->getMessage());
        }
    }

    /** @param array<string,mixed> $context */
    public static function recordUniverseSourceUnavailableFromContext(array $context): void
    {
        try {
            self::production()->recordUniverseSourceUnavailable($context);
        } catch (Throwable $error) {
            error_log('strict forward opportunity source audit failed open: ' . $error->getMessage());
        }
    }

    /** @param array<string,mixed> $context */
    public static function observeEligibilityFromContext(array $context): void
    {
        try {
            self::production()->observeEligibility($context);
        } catch (Throwable $error) {
            error_log('strict forward opportunity eligibility ledger failed open: ' . $error->getMessage());
        }
    }

    /** @param array<string,mixed> $context */
    public static function finalizeExpiredFromContext(array $context): void
    {
        try {
            self::production()->finalizeExpired($context);
        } catch (Throwable $error) {
            error_log('strict forward opportunity finalization failed open: ' . $error->getMessage());
        }
    }

    /** @param array<string,mixed> $context */
    public static function recordOfficialScheduleSnapshotFromContext(array $context): void
    {
        try {
            self::production()->recordOfficialScheduleSnapshot($context);
        } catch (Throwable $error) {
            error_log('strict forward official schedule revision ledger failed open: ' . $error->getMessage());
        }
    }

    /**
     * First-write candidate persistence. Identical subsequent scheduler polls
     * do not replace the original source evidence; differing source evidence is
     * recorded as a conflict event and preserves the original candidate.
     *
     * @param array<string,mixed> $context
     * @return array{saved:int,duplicates:int,conflicts:int,invalid:int}
     */
    public function recordCandidateUniverse(array $context): array
    {
        $this->assertMeasurementWriteAllowed();
        // A production scheduler may create Candidate evidence only after the
        // independent A1 Expected inventory was frozen and read back from the
        // exact same authoritative deadline snapshot. Fixture/legacy callers
        // remain supported unless they opt into this formal barrier.
        if (!empty($context['formal_barrier_required'])
            && (empty($context['expected_inventory_verified'])
                || !preg_match('/^sha256:[a-f0-9]{64}$/', (string)($context['deadline_snapshot_identity'] ?? '')))) {
            throw new RuntimeException('EXPECTED_INVENTORY_BARRIER_UNVERIFIED');
        }
        $date = trim((string)($context['date'] ?? ''));
        $candidates = is_array($context['candidates'] ?? null) ? $context['candidates'] : [];
        $source = trim((string)($context['source'] ?? ''));
        $sourceIdentity = trim((string)($context['source_identity'] ?? ''));
        $sourceGeneratedAt = trim((string)($context['source_generated_at'] ?? ''));
        $recordedAt = $this->timestamp($context['universe_recorded_at'] ?? null);
        if (!$this->validDate($date) || $source === '' || $sourceIdentity === '' || !$this->isTimestamp($sourceGeneratedAt)) {
            throw new InvalidArgumentException('invalid_candidate_universe_context');
        }
        if (!empty($context['formal_barrier_required'])) {
            $this->assertFormalProspectiveCandidateSet($context, $date, $candidates);
        }

        $saved = $duplicates = $conflicts = $invalid = 0;
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                $invalid++;
                continue;
            }
            $raceCode = strtoupper(trim((string)($candidate['race_code'] ?? '')));
            $venue = strtoupper(trim((string)($candidate['venue'] ?? substr($raceCode, 8, 3))));
            $cutoff = trim((string)($candidate['cutoff_timestamp'] ?? ''));
            if (!preg_match(self::CANDIDATE_PATTERN, $raceCode) || !preg_match(self::VENUE_PATTERN, $venue)
                || substr($raceCode, 8, 3) !== $venue || !$this->isTimestamp($cutoff)) {
                $invalid++;
                continue;
            }
            $row = [
                'record_type' => 'SCHEDULER_CANDIDATE_UNIVERSE',
                'stable_candidate_key' => $this->candidateKey($raceCode),
                'race_code' => $raceCode,
                'venue' => $venue,
                'target_date' => $date,
                'cutoff_timestamp' => $cutoff,
                'source' => $source,
                'source_identity' => $sourceIdentity,
                'source_generated_at' => $sourceGeneratedAt,
                'deadline_snapshot_identity' => trim((string)($context['deadline_snapshot_identity'] ?? '')),
                'prospective_filter_contract' => trim((string)($context['prospective_filter_contract'] ?? '')),
                'prospective_keyset_identity' => trim((string)($context['prospective_keyset_identity'] ?? '')),
                'armed_at' => trim((string)($context['armed_at'] ?? '')),
                'universe_recorded_at' => $recordedAt,
                'timezone' => 'Asia/Tokyo',
                'candidate_status' => 'CANDIDATE_RECORDED',
            ];
            $path = $this->candidatePath($date, $raceCode);
            $result = $this->writeFirst($path, $row);
            if ($result === 'saved') {
                $saved++;
            } elseif ($result === 'duplicate') {
                $duplicates++;
                $existing = $this->readJson($path);
                if (($existing['cutoff_timestamp'] ?? '') !== $cutoff) {
                    $conflicts++;
                    $this->append($this->operationalPath($date), [
                        // Candidate provenance is immutable. Semantic schedule
                        // changes are resolved in schedule_revisions/, not by
                        // overwriting this original record.
                        'event' => 'CANDIDATE_ORIGINAL_SEMANTIC_DIFFERENCE', 'observed_at' => $recordedAt,
                        'race_code' => $raceCode, 'original_source_identity' => $existing['source_identity'] ?? '',
                        'new_source_identity' => $sourceIdentity, 'original_cutoff_timestamp' => $existing['cutoff_timestamp'] ?? '',
                        'new_cutoff_timestamp' => $cutoff,
                    ]);
                }
            }
        }
        $this->append($this->operationalPath($date), [
            'event' => 'CANDIDATE_UNIVERSE_OBSERVED', 'observed_at' => $recordedAt,
            'source' => $source, 'source_identity' => $sourceIdentity,
            'source_generated_at' => $sourceGeneratedAt, 'submitted_candidates' => count($candidates),
            'saved' => $saved, 'duplicates' => $duplicates, 'conflicts' => $conflicts, 'invalid' => $invalid,
        ]);
        return compact('saved', 'duplicates', 'conflicts', 'invalid');
    }

    /**
     * Records a source failure without manufacturing a zero-candidate day.
     *
     * @param array<string,mixed> $context
     */
    public function recordUniverseSourceUnavailable(array $context): void
    {
        $this->assertMeasurementWriteAllowed();
        $date = trim((string)($context['date'] ?? ''));
        if (!$this->validDate($date)) throw new InvalidArgumentException('invalid_source_failure_date');
        $this->append($this->sourceFailurePath($date), [
            'event' => 'UNIVERSE_SOURCE_UNAVAILABLE',
            'observed_at' => $this->timestamp($context['observed_at'] ?? null),
            'target_date' => $date,
            'source' => trim((string)($context['source'] ?? 'OFFICIAL_DEADLINE_CACHE')),
            'source_identity' => trim((string)($context['source_identity'] ?? '')),
            'error_type' => trim((string)($context['error_type'] ?? 'UNIVERSE_SOURCE_UNAVAILABLE')),
            'error_code' => trim((string)($context['error_code'] ?? 'UNKNOWN')),
            'error_message' => trim((string)($context['error_message'] ?? '')),
            'known_race_codes' => array_values((array)($context['known_race_codes'] ?? [])),
            'zero_candidates_asserted' => false,
        ]);
    }

    /**
     * Appends an official schedule observation and classifies semantic changes
     * independently from volatile raw-cache metadata. The original candidate is
     * never rewritten; current schedule is resolved from this history.
     *
     * `source_generated_at` identifies local cache generation only. It is not
     * an official revision timestamp, so a changed semantic payload cannot
     * replace the effective schedule without independent official chronology
     * evidence.
     *
     * @param array<string,mixed> $context
     */
    public function recordOfficialScheduleSnapshot(array $context): void
    {
        $this->assertMeasurementWriteAllowed();
        $date = trim((string)($context['date'] ?? ''));
        if (!$this->validDate($date)) throw new InvalidArgumentException('invalid_schedule_snapshot_date');
        $sourceGeneratedAt = trim((string)($context['source_generated_at'] ?? ''));
        $rawSourceIdentity = trim((string)($context['raw_source_identity'] ?? ''));
        if (!$this->isTimestamp($sourceGeneratedAt) || $rawSourceIdentity === '') {
            throw new InvalidArgumentException('invalid_schedule_snapshot_source');
        }
        $observedAt = $this->timestamp($context['observed_at'] ?? null);
        $schedules = is_array($context['schedules'] ?? null) ? $context['schedules'] : [];
        $byRace = [];
        foreach ($schedules as $schedule) {
            if (!is_array($schedule)) continue;
            $raceCode = strtoupper(trim((string)($schedule['race_code'] ?? '')));
            if (!preg_match(self::CANDIDATE_PATTERN, $raceCode)) continue;
            $status = strtoupper(trim((string)($schedule['official_status'] ?? 'ACTIVE')));
            $cutoff = trim((string)($schedule['cutoff_timestamp'] ?? ''));
            if (!in_array($status, ['ACTIVE', 'CANCELLED', 'EXCLUDED'], true)) continue;
            if ($status === 'ACTIVE' && !$this->isTimestamp($cutoff)) continue;
            $byRace[$raceCode] = ['cutoff_timestamp' => $cutoff, 'official_status' => $status];
        }
        foreach ($this->candidateRows($date) as $candidate) {
            $raceCode = (string)$candidate['race_code'];
            if (!isset($byRace[$raceCode])) continue;
            $next = $byRace[$raceCode];
            $fingerprint = $this->semanticScheduleFingerprint($raceCode, (string)$candidate['venue'], (string)$next['cutoff_timestamp'], (string)$next['official_status']);
            $effective = $this->resolveEffectiveSchedule($date, $raceCode);
            $previousFingerprint = (string)($effective['semantic_fingerprint'] ?? '');
            $previousGeneratedAt = (string)($effective['source_generated_at'] ?? '');
            $previousCutoff = (string)($effective['effective_cutoff_timestamp'] ?? '');
            $previousStatus = (string)($effective['effective_official_status'] ?? '');
            $officialRevisionEvidence = $this->officialRevisionEvidence(
                $context['official_revision_evidence'] ?? null,
                $previousFingerprint
            );
            $stale = $previousGeneratedAt !== '' && $this->compareTimestamp($sourceGeneratedAt, $previousGeneratedAt) < 0;
            if ($stale) {
                $type = 'STALE_OR_OUT_OF_ORDER_SOURCE';
            } elseif ($previousFingerprint === '' || $previousFingerprint === $fingerprint) {
                $type = 'SOURCE_REFRESH_NO_SEMANTIC_CHANGE';
            } elseif (!$officialRevisionEvidence['verified']) {
                $type = 'SEMANTIC_REVISION_UNVERIFIED';
            } elseif ((string)$next['official_status'] === 'CANCELLED') {
                $type = 'OFFICIAL_CANCELLED';
            } elseif ((string)$next['official_status'] === 'EXCLUDED') {
                $type = 'OFFICIAL_EXCLUDED';
            } else {
                $type = 'CUTOFF_CHANGED';
            }
            $sequence = $this->nextScheduleSequence($date, $raceCode);
            $this->append($this->scheduleRevisionPath($date, $raceCode), [
                'event' => 'OFFICIAL_SCHEDULE_REVISION_OBSERVATION',
                'stable_candidate_key' => $candidate['stable_candidate_key'],
                'race_code' => $raceCode,
                'venue' => $candidate['venue'],
                'observed_at' => $observedAt,
                'source_generated_at' => $sourceGeneratedAt,
                'raw_source_identity' => $rawSourceIdentity,
                'source_sequence' => $sequence,
                'previous_semantic_fingerprint' => $previousFingerprint,
                'new_semantic_fingerprint' => $fingerprint,
                'previous_effective_cutoff_timestamp' => $previousCutoff,
                'observed_cutoff_timestamp' => $next['cutoff_timestamp'],
                'previous_official_status' => $previousStatus,
                'observed_official_status' => $next['official_status'],
                'revision_type' => $type,
                'official_revision_evidence' => $officialRevisionEvidence['record'],
                'official_chronology_verified' => $officialRevisionEvidence['verified'],
                'ordering_rule' => 'source_generated_at ASC, observed_at ASC, source_sequence ASC; deterministic local observation ordering only; older source_generated_at never replaces effective state',
            ]);
        }
    }

    /** @return array<string,mixed> */
    public function resolveEffectiveSchedule(string $date, string $raceCode): array
    {
        $candidate = null;
        foreach ($this->candidateRows($date) as $row) {
            if (($row['race_code'] ?? '') === $raceCode) { $candidate = $row; break; }
        }
        if (!is_array($candidate)) return [];
        $effective = [
            'stable_candidate_key' => $candidate['stable_candidate_key'], 'race_code' => $raceCode,
            'effective_cutoff_timestamp' => $candidate['cutoff_timestamp'], 'effective_official_status' => 'ACTIVE',
            'semantic_fingerprint' => $this->semanticScheduleFingerprint($raceCode, (string)$candidate['venue'], (string)$candidate['cutoff_timestamp'], 'ACTIVE'),
            'source_generated_at' => $candidate['source_generated_at'], 'resolved_from' => 'CANDIDATE_ORIGINAL',
            'schedule_revision_integrity' => 'PASS', 'unverified_revision_count' => 0,
        ];
        $events = $this->scheduleRevisionRows($date, $raceCode);
        usort($events, function (array $a, array $b): int {
            $primary = $this->compareTimestamp((string)($a['source_generated_at'] ?? ''), (string)($b['source_generated_at'] ?? ''));
            if ($primary !== 0) return $primary;
            $secondary = $this->compareTimestamp((string)($a['observed_at'] ?? ''), (string)($b['observed_at'] ?? ''));
            if ($secondary !== 0) return $secondary;
            return ((int)($a['source_sequence'] ?? 0)) <=> ((int)($b['source_sequence'] ?? 0));
        });
        $unverified = [];
        foreach ($events as $event) {
            if (($event['revision_type'] ?? '') === 'STALE_OR_OUT_OF_ORDER_SOURCE') continue;
            if (($event['revision_type'] ?? '') === 'SEMANTIC_REVISION_UNVERIFIED') {
                $unverified[] = $event;
                continue;
            }
            if ($this->compareTimestamp((string)($event['source_generated_at'] ?? ''), (string)$effective['source_generated_at']) < 0) continue;
            $effective = [
                'stable_candidate_key' => $candidate['stable_candidate_key'], 'race_code' => $raceCode,
                'effective_cutoff_timestamp' => (string)($event['observed_cutoff_timestamp'] ?? ''),
                'effective_official_status' => (string)($event['observed_official_status'] ?? 'ACTIVE'),
                'semantic_fingerprint' => (string)($event['new_semantic_fingerprint'] ?? ''),
                'source_generated_at' => (string)($event['source_generated_at'] ?? ''),
                'resolved_at' => (string)($event['observed_at'] ?? ''), 'resolved_from' => 'SCHEDULE_REVISION_LEDGER',
                'revision_type' => (string)($event['revision_type'] ?? ''),
                'schedule_revision_integrity' => 'PASS', 'unverified_revision_count' => 0,
            ];
        }
        if ($unverified !== []) {
            // Measurement and audit fail loud: retain the last safe effective
            // state, but make the unknown official chronology explicit.
            $effective['schedule_revision_integrity'] = 'BLOCKED_SEMANTIC_REVISION_UNVERIFIED';
            $effective['unverified_revision_count'] = count($unverified);
            $effective['latest_unverified_observed_at'] = (string)($unverified[count($unverified) - 1]['observed_at'] ?? '');
        }
        return $effective;
    }

    /**
     * Single race-level admission rule for formal Strict Forward measurement.
     * It is intentionally stricter than production prediction: a missing or
     * tainted schedule is observable, but may not become formal Expected or
     * Canonical evidence. It never changes candidate provenance or eligibility
     * into INELIGIBLE.
     *
     * @return array{accepted:bool,status:string,reason:string,cutoff_timestamp:string,official_status:string,schedule_revision_integrity:string}
     */
    public function formalMeasurementStatus(string $date, string $raceCode): array
    {
        $effective = $this->resolveEffectiveSchedule($date, $raceCode);
        if ($effective === []) {
            return [
                'accepted' => false, 'status' => 'SCHEDULE_INTEGRITY_UNRESOLVED',
                'reason' => 'CANDIDATE_OR_EFFECTIVE_SCHEDULE_MISSING', 'cutoff_timestamp' => '',
                'official_status' => '', 'schedule_revision_integrity' => 'UNRESOLVED',
            ];
        }
        $integrity = (string)($effective['schedule_revision_integrity'] ?? 'PASS');
        if ($integrity !== 'PASS') {
            return [
                'accepted' => false, 'status' => 'TAINTED_SEMANTIC_REVISION_UNVERIFIED',
                'reason' => $integrity, 'cutoff_timestamp' => (string)($effective['effective_cutoff_timestamp'] ?? ''),
                'official_status' => (string)($effective['effective_official_status'] ?? ''),
                'schedule_revision_integrity' => $integrity,
            ];
        }
        $status = (string)($effective['effective_official_status'] ?? '');
        $cutoff = (string)($effective['effective_cutoff_timestamp'] ?? '');
        if ($status !== 'ACTIVE' || !$this->isTimestamp($cutoff)) {
            return [
                'accepted' => false, 'status' => 'SCHEDULE_INTEGRITY_UNRESOLVED',
                'reason' => $status === '' ? 'EFFECTIVE_SCHEDULE_INCOMPLETE' : 'EFFECTIVE_STATUS_' . $status,
                'cutoff_timestamp' => $cutoff, 'official_status' => $status,
                'schedule_revision_integrity' => $integrity,
            ];
        }
        return [
            'accepted' => true, 'status' => 'FORMAL_SCHEDULE_ACCEPTED', 'reason' => '',
            'cutoff_timestamp' => $cutoff, 'official_status' => $status,
            'schedule_revision_integrity' => $integrity,
        ];
    }

    /**
     * Append every read of the exhibition predicate. Before cutoff, incomplete
     * is only an observation; it can never finalise a candidate as ineligible.
     *
     * @param array<string,mixed> $context
     */
    public function observeEligibility(array $context): void
    {
        $this->assertMeasurementWriteAllowed();
        $date = trim((string)($context['date'] ?? ''));
        if (!$this->validDate($date)) throw new InvalidArgumentException('invalid_eligibility_date');
        $observedAt = $this->timestamp($context['observed_at'] ?? null);
        $ready = array_fill_keys(array_values(array_filter(array_map('strval', (array)($context['ready_race_codes'] ?? [])),
            static fn(string $code): bool => preg_match(self::CANDIDATE_PATTERN, $code) === 1)), true);
        $availability = strtoupper(trim((string)($context['input_availability'] ?? 'AVAILABLE')));
        $reason = trim((string)($context['reason'] ?? ''));
        foreach ($this->candidateRows($date) as $candidate) {
            $raceCode = (string)$candidate['race_code'];
            $isComplete = isset($ready[$raceCode]);
            $eventStatus = $availability === 'AVAILABLE'
                ? ($isComplete ? 'ELIGIBLE_OBSERVED' : 'EXHIBITION_INCOMPLETE_OBSERVED')
                : 'ELIGIBILITY_INPUT_UNAVAILABLE';
            $event = [
                'event' => 'ELIGIBILITY_OBSERVATION', 'eligibility_status' => $eventStatus,
                'observed_at' => $observedAt, 'race_code' => $raceCode, 'venue' => $candidate['venue'],
                'cutoff_timestamp' => $candidate['cutoff_timestamp'], 'candidate_key' => $candidate['stable_candidate_key'],
                'input_availability' => $availability, 'exhibition_complete' => $availability === 'AVAILABLE' ? $isComplete : null,
                'reason' => $reason,
            ];
            $this->append($this->observationPath($date), $event);
            if ($eventStatus === 'ELIGIBLE_OBSERVED' && $this->beforeCutoff($observedAt, (string)$candidate['cutoff_timestamp'])) {
                $this->writeResolution($date, $candidate, [
                    'eligibility_status' => 'ELIGIBLE_OBSERVED', 'first_eligible_observed_at' => $observedAt,
                    'resolution_reason' => 'EXHIBITION_COMPLETE_BEFORE_CUTOFF',
                ]);
            }
        }
        $this->finalizeExpired(['date' => $date, 'observed_at' => $observedAt]);
    }

    /** @param array<string,mixed> $context */
    public function finalizeExpired(array $context): void
    {
        $this->assertMeasurementWriteAllowed();
        $date = trim((string)($context['date'] ?? ''));
        if (!$this->validDate($date)) throw new InvalidArgumentException('invalid_finalization_date');
        $now = $this->timestamp($context['observed_at'] ?? null);
        $allObservations = $this->observationsByRace($date);
        foreach ($this->candidateRows($date) as $candidate) {
            $raceCode = (string)$candidate['race_code'];
            if ($this->beforeCutoff($now, (string)$candidate['cutoff_timestamp']) || is_file($this->resolutionPath($date, $raceCode))) continue;
            $events = $allObservations[$raceCode] ?? [];
            $inputFailure = array_filter($events, static fn(array $event): bool => ($event['eligibility_status'] ?? '') === 'ELIGIBILITY_INPUT_UNAVAILABLE');
            $validObservations = array_filter($events, static fn(array $event): bool => ($event['input_availability'] ?? '') === 'AVAILABLE');
            if ($inputFailure !== [] || $validObservations === []) {
                $status = 'ELIGIBILITY_UNRESOLVED';
                $reason = $inputFailure !== [] ? 'ELIGIBILITY_INPUT_UNAVAILABLE_BEFORE_RESOLUTION' : 'NO_ELIGIBILITY_OBSERVATION_BEFORE_CUTOFF';
            } else {
                $status = 'VALID_NON_ELIGIBLE_EXHIBITION_INCOMPLETE';
                $reason = 'EXHIBITION_INCOMPLETE_IN_ALL_VALID_PRE_CUTOFF_OBSERVATIONS';
            }
            $this->writeResolution($date, $candidate, [
                'eligibility_status' => $status, 'resolved_at' => $now, 'resolution_reason' => $reason,
            ]);
        }
    }

    /** @return list<array<string,mixed>> */
    public function candidateRows(string $date): array
    {
        $directory = $this->rootPath . '/candidates/' . str_replace('-', '', $date);
        if (!is_dir($directory)) return [];
        $rows = [];
        foreach (glob($directory . '/*.json') ?: [] as $path) {
            $row = $this->readJson($path);
            if ($row !== []) $rows[] = $row;
        }
        usort($rows, static fn(array $a, array $b): int => strcmp((string)$a['race_code'], (string)$b['race_code']));
        return $rows;
    }

    /**
     * Return only the immutable Candidate rows admitted by the current
     * prospective-inventory contract.  Scheduler-owned downstream writers use
     * this read-only projection instead of discovering races again from the
     * live API response: a race that was before armed_at can therefore never
     * enter Expected, Canonical, input-snapshot, or comparative-pair evidence.
     *
     * @return list<string>
     */
    public function formalProspectiveCandidateRaceCodes(string $date, string $venue): array
    {
        $venue = strtoupper(trim($venue));
        if (!$this->validDate($date) || !preg_match(self::VENUE_PATTERN, $venue)) return [];
        $codes = [];
        foreach ($this->candidateRows($date) as $candidate) {
            $raceCode = strtoupper(trim((string)($candidate['race_code'] ?? '')));
            $cutoff = trim((string)($candidate['cutoff_timestamp'] ?? ''));
            $armedAt = trim((string)($candidate['armed_at'] ?? ''));
            if (($candidate['venue'] ?? '') !== $venue
                || ($candidate['prospective_filter_contract'] ?? '') !== GateA1ProspectiveInventory::PROSPECTIVE_FILTER_CONTRACT_VERSION
                || !$this->isOffsetTimestamp($cutoff) || !$this->isOffsetTimestamp($armedAt)) {
                continue;
            }
            try {
                if ((new DateTimeImmutable($cutoff)) <= (new DateTimeImmutable($armedAt))) continue;
            } catch (Throwable) {
                continue;
            }
            if (preg_match(self::CANDIDATE_PATTERN, $raceCode) && substr($raceCode, 8, 3) === $venue) $codes[] = $raceCode;
        }
        $codes = array_values(array_unique($codes));
        sort($codes, SORT_STRING);
        return $codes;
    }

    /** @return list<array<string,mixed>> */
    public function resolutionRows(string $date): array
    {
        $directory = $this->rootPath . '/eligibility/' . str_replace('-', '', $date);
        if (!is_dir($directory)) return [];
        $rows = [];
        foreach (glob($directory . '/*.json') ?: [] as $path) {
            $row = $this->readJson($path);
            if ($row !== []) $rows[] = $row;
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function sourceFailureRows(string $date): array
    {
        $path = $this->sourceFailurePath($date);
        if (!is_file($path)) return [];
        $rows = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true);
            if (is_array($row)) $rows[] = $row;
        }
        return $rows;
    }

    private function writeResolution(string $date, array $candidate, array $fields): void
    {
        $row = array_merge($candidate, $fields, [
            'record_type' => 'SCHEDULER_ELIGIBILITY_RESOLUTION',
            'resolved_timezone' => 'Asia/Tokyo',
        ]);
        $this->writeFirst($this->resolutionPath($date, (string)$candidate['race_code']), $row);
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function observationsByRace(string $date): array
    {
        $path = $this->observationPath($date);
        if (!is_file($path)) return [];
        $rows = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true);
            if (is_array($row) && isset($row['race_code'])) $rows[(string)$row['race_code']][] = $row;
        }
        return $rows;
    }

    private function candidatePath(string $date, string $raceCode): string { return $this->rootPath . '/candidates/' . str_replace('-', '', $date) . '/' . hash('sha256', $this->candidateKey($raceCode)) . '.json'; }

    /**
     * Formal Candidate writes are permitted only for the exact prospective
     * set that the independent A1 Expected inventory already froze and read
     * back.  The scheduler cannot substitute its current time or a second
     * deadline load at this boundary.
     *
     * @param array<string,mixed> $context
     * @param list<array<string,mixed>> $candidates
     */
    private function assertFormalProspectiveCandidateSet(array $context, string $date, array $candidates): void
    {
        $armedAt = trim((string)($context['armed_at'] ?? ''));
        $deadlineIdentity = trim((string)($context['deadline_snapshot_identity'] ?? ''));
        $keysetIdentity = trim((string)($context['prospective_keyset_identity'] ?? ''));
        $contract = trim((string)($context['prospective_filter_contract'] ?? ''));
        $submitted = is_array($context['prospective_entries'] ?? null) ? $context['prospective_entries'] : null;
        if (!$this->isOffsetTimestamp($armedAt)
            || $contract !== GateA1ProspectiveInventory::PROSPECTIVE_FILTER_CONTRACT_VERSION
            || !preg_match('/^sha256:[a-f0-9]{64}$/', $deadlineIdentity)
            || !preg_match('/^sha256:[a-f0-9]{64}$/', $keysetIdentity)
            || $submitted === null) {
            throw new RuntimeException('FORMAL_PROSPECTIVE_CONTEXT_INVALID');
        }
        $expectedPath = $this->rootPath . '/expected_inventory/' . str_replace('-', '', $date) . '.json';
        $expected = $this->readJson($expectedPath);
        if (($expected['armed_at'] ?? '') !== $armedAt
            || ($expected['deadline_snapshot_identity'] ?? '') !== $deadlineIdentity
            || ($expected['prospective_filter_contract'] ?? '') !== $contract
            || ($expected['prospective_keyset_identity'] ?? '') !== $keysetIdentity
            || !is_array($expected['expected_entries'] ?? null)) {
            throw new RuntimeException('EXPECTED_INVENTORY_BARRIER_UNVERIFIED');
        }
        $submittedEntries = $this->normalizeProspectiveEntries($submitted, $armedAt);
        $candidateEntries = $this->normalizeProspectiveEntries($candidates, $armedAt);
        $expectedEntries = $this->normalizeProspectiveEntries($expected['expected_entries'], $armedAt);
        if ($submittedEntries === null || $candidateEntries === null || $expectedEntries === null) {
            throw new RuntimeException('PRE_ARMED_OR_INVALID_FORMAL_CANDIDATE');
        }
        if (!hash_equals($keysetIdentity, GateA1ProspectiveInventory::prospectiveKeysetIdentity($deadlineIdentity, $armedAt, $submitted))) {
            throw new RuntimeException('FORMAL_PROSPECTIVE_KEYSET_IDENTITY_INVALID');
        }
        if ($candidateEntries !== $submittedEntries || $candidateEntries !== $expectedEntries) {
            throw new RuntimeException('FORMAL_CANDIDATE_EXPECTED_KEYSET_MISMATCH');
        }
    }

    /** @param list<array<string,mixed>> $entries @return list<string>|null */
    private function normalizeProspectiveEntries(array $entries, string $armedAt): ?array
    {
        $keys = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) return null;
            $raceCode = strtoupper(trim((string)($entry['race_code'] ?? '')));
            $venue = strtoupper(trim((string)($entry['venue'] ?? substr($raceCode, 8, 3))));
            $cutoff = trim((string)($entry['cutoff_timestamp'] ?? ''));
            if (!preg_match(self::CANDIDATE_PATTERN, $raceCode) || !preg_match(self::VENUE_PATTERN, $venue)
                || $venue !== substr($raceCode, 8, 3) || !$this->isOffsetTimestamp($cutoff)) return null;
            try {
                if ((new DateTimeImmutable($cutoff)) <= (new DateTimeImmutable($armedAt))) return null;
            } catch (Throwable) { return null; }
            $key = $raceCode . '|' . $venue . '|' . $cutoff;
            if (isset($keys[$key])) return null;
            $keys[$key] = true;
        }
        $result = array_keys($keys);
        sort($result, SORT_STRING);
        return $result;
    }
    private function scheduleRevisionPath(string $date, string $raceCode): string { return $this->rootPath . '/schedule_revisions/' . str_replace('-', '', $date) . '/' . hash('sha256', $this->candidateKey($raceCode)) . '.jsonl'; }
    private function resolutionPath(string $date, string $raceCode): string { return $this->rootPath . '/eligibility/' . str_replace('-', '', $date) . '/' . hash('sha256', $this->candidateKey($raceCode)) . '.json'; }
    private function observationPath(string $date): string { return $this->rootPath . '/observations/' . str_replace('-', '', $date) . '.jsonl'; }
    private function operationalPath(string $date): string { return $this->rootPath . '/operational/' . str_replace('-', '', $date) . '.jsonl'; }
    private function sourceFailurePath(string $date): string { return $this->rootPath . '/source_failures/' . str_replace('-', '', $date) . '.jsonl'; }
    private function candidateKey(string $raceCode): string { return 'scheduler-opportunity|' . $raceCode; }
    private function semanticScheduleFingerprint(string $raceCode, string $venue, string $cutoff, string $status): string
    {
        return 'sha256:' . hash('sha256', json_encode([
            'race_code' => $raceCode, 'venue' => $venue,
            'effective_cutoff_timestamp' => $cutoff, 'official_status' => $status,
        ], JSON_UNESCAPED_SLASHES));
    }
    /**
     * A local cache timestamp, raw cache hash, or local sequence is not
     * independent official chronology evidence. Future producers may mark a
     * revision verified only with an official timestamp/version sequence plus
     * a named evidence source, stable evidence identity, and the immediately
     * preceding semantic fingerprint. This prevents an unrelated official
     * timestamp from being treated as proof that this payload supersedes the
     * resolver's current effective state.
     *
     * @return array{verified:bool,record:array<string,string>}
     */
    private function officialRevisionEvidence(mixed $value, string $expectedPreviousFingerprint): array
    {
        $raw = is_array($value) ? $value : [];
        $source = trim((string)($raw['evidence_source'] ?? ''));
        $identity = trim((string)($raw['evidence_identity'] ?? ''));
        $officialTimestamp = trim((string)($raw['official_revision_timestamp'] ?? ''));
        $previousOfficialTimestamp = trim((string)($raw['official_predecessor_revision_timestamp'] ?? ''));
        $officialVersion = trim((string)($raw['official_revision_version'] ?? ''));
        $previousOfficialVersion = trim((string)($raw['official_predecessor_revision_version'] ?? ''));
        $previousFingerprint = trim((string)($raw['predecessor_semantic_fingerprint'] ?? ''));
        $timestampChronology = $this->isTimestamp($officialTimestamp) && $this->isTimestamp($previousOfficialTimestamp)
            && $this->compareTimestamp($officialTimestamp, $previousOfficialTimestamp) > 0;
        $numericVersionChronology = preg_match('/^\d+(?:\.\d+)?$/', $officialVersion) === 1
            && preg_match('/^\d+(?:\.\d+)?$/', $previousOfficialVersion) === 1
            && (float)$officialVersion > (float)$previousOfficialVersion;
        $hasOfficialChronology = $timestampChronology || $numericVersionChronology;
        $verified = $source !== '' && $identity !== '' && $expectedPreviousFingerprint !== ''
            && hash_equals($expectedPreviousFingerprint, $previousFingerprint) && $hasOfficialChronology;
        return ['verified' => $verified, 'record' => [
            'evidence_source' => $source,
            'evidence_identity' => $identity,
            'official_revision_timestamp' => $officialTimestamp,
            'official_predecessor_revision_timestamp' => $previousOfficialTimestamp,
            'official_revision_version' => $officialVersion,
            'official_predecessor_revision_version' => $previousOfficialVersion,
            'predecessor_semantic_fingerprint' => $previousFingerprint,
            'verification_status' => $verified ? 'VERIFIED_OFFICIAL_CHRONOLOGY' : 'LOCAL_OBSERVATION_ONLY',
        ]];
    }
    /** @return list<array<string,mixed>> */
    private function scheduleRevisionRows(string $date, string $raceCode): array
    {
        $path = $this->scheduleRevisionPath($date, $raceCode);
        if (!is_file($path)) return [];
        $rows = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true);
            if (is_array($row)) $rows[] = $row;
        }
        return $rows;
    }
    private function nextScheduleSequence(string $date, string $raceCode): int { return count($this->scheduleRevisionRows($date, $raceCode)) + 1; }
    private function compareTimestamp(string $left, string $right): int
    {
        try { return (new DateTimeImmutable($left)) <=> (new DateTimeImmutable($right)); }
        catch (Throwable) { return strcmp($left, $right); }
    }
    private function validDate(string $date): bool { return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $date); }
    private function timestamp(mixed $value): string { $raw = is_string($value) ? trim($value) : ''; return $this->isTimestamp($raw) ? $raw : (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM); }
    private function isTimestamp(string $value): bool { try { return $value !== '' && (new DateTimeImmutable($value))->format('P') !== ''; } catch (Throwable) { return false; } }
    private function isOffsetTimestamp(string $value): bool { return preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $value) === 1 && $this->isTimestamp($value); }
    private function beforeCutoff(string $left, string $right): bool { return (new DateTimeImmutable($left)) < (new DateTimeImmutable($right)); }
    private function ensureDirectory(string $path): void { $directory = dirname($path); if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) throw new RuntimeException('cannot_create_ledger_directory'); }
    private function writeFirst(string $path, array $row): string { $this->assertLifecycleStillOpen(); $this->ensureDirectory($path); $handle = @fopen($path, 'x'); if (!is_resource($handle)) return is_file($path) ? 'duplicate' : 'failed'; fwrite($handle, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"); fclose($handle); return 'saved'; }
    private function append(string $path, array $row): void { $this->assertLifecycleStillOpen(); $this->ensureDirectory($path); if (file_put_contents($path, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX) === false) throw new RuntimeException('cannot_append_ledger'); }
    /** @return array<string,mixed> */
    private function readJson(string $path): array { $data = json_decode((string)file_get_contents($path), true); return is_array($data) ? $data : []; }
}
