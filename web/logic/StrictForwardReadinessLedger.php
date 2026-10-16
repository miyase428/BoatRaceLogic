<?php

declare(strict_types=1);

require_once __DIR__ . '/StrictForwardOpportunityLedger.php';
require_once __DIR__ . '/MeasurementImplementationProvenance.php';
require_once __DIR__ . '/WindowDeclaredImplementationIdentity.php';
require_once __DIR__ . '/WindowActivationHold.php';
require_once __DIR__ . '/FormalMeasurementWindowContext.php';
require_once __DIR__ . '/ComparativeContractV1.php';

/**
 * Outcome-blind, append-only readiness ledger for Course Signal strict forward.
 *
 * This is deliberately filesystem-backed during READINESS_COMPLETION. Existing
 * production snapshots use PostgreSQL, but a separate best-effort ledger keeps
 * pilot I/O failures from affecting the API/production capture path.  It is not
 * an Official Epoch store and it never reads or writes race outcomes.
 */
final class StrictForwardReadinessLedger
{
    public const EPOCH_STATUS = 'READINESS_COMPLETION';
    public const CANONICAL_STAGE = 'exhibition';

    private const TARGET_LEVELS = ['top3' => 1, 'top2' => 2, 'first' => 3];
    private const CAPTURE_MODES = ['LIVE_STRICT', 'REPLAY_FROM_FROZEN_DECISION', 'REPLAY_RECOMPUTED'];

    public function __construct(
        private readonly string $rootPath,
        private readonly bool $emitErrorLog = true,
        private readonly ?StrictForwardOpportunityLedger $opportunityLedger = null,
        private readonly ?WindowDeclaredImplementationIdentity $declaredImplementation = null,
        private readonly ?WindowActivationHold $activationHold = null,
        private readonly ?Closure $beforeFormalPersist = null,
        private readonly ?FormalMeasurementWindowContext $windowContext = null,
    )
    {
    }

    public static function production(): self
    {
        $repoRoot = dirname(__DIR__, 2);
        try {
            $context = FormalMeasurementWindowContext::production($repoRoot);
            return new self(
                $context->ledgerRoot(),
                true,
                new StrictForwardOpportunityLedger($context->opportunityRoot(), $context),
                $context->declaration(),
                $context->activationHold(),
                null,
                $context,
            );
        } catch (Throwable) {
            // There is deliberately no fallback to the legacy global ledger.
            // Formal writes will fail closed through formalWriteAuthorization().
            return new self($repoRoot . '/var/strict_forward_unbound');
        }
    }

    /**
     * Best-effort hook for the live Course Signal API.  All exceptions stay in
     * the ledger boundary so the existing API result is never changed.
     *
     * @param array<string,mixed> $context
     */
    public static function captureLiveFromContext(array $context): void
    {
        try {
            self::production()->captureLive($context);
        } catch (Throwable $error) {
            // Do not throw into the production API. error_log is intentionally
            // separate from the response and does not change its status/body.
            error_log('strict forward readiness ledger failed open: ' . $error->getMessage());
        }
    }

    /**
     * Records the expected 18-key universe before the API subprocess starts.
     * It never writes a decision and is intentionally independent from the
     * primary decision ledger so missed capture can be observed later.
     *
     * @param array<string,mixed> $context
     */
    public static function recordExpectedLiveFromContext(array $context): void
    {
        try {
            self::production()->recordExpectedLive($context);
        } catch (Throwable $error) {
            error_log('strict forward expected-universe audit failed: ' . $error->getMessage());
        }
    }

    /**
     * Records a capture-process failure to an audit path and PHP error log.
     * This must never throw into the pre-existing scheduler path.
     *
     * @param array<string,mixed> $context
     */
    public static function recordCaptureFailureFromContext(array $context): void
    {
        try {
            self::production()->recordCaptureFailure($context);
        } catch (Throwable $error) {
            error_log('strict forward capture failure audit failed: ' . $error->getMessage());
        }
    }

    /**
     * @param array<string,mixed> $context
     * @return array{status:string,expected:int,duplicates:int,tainted:int,reason?:string}
     */
    public function recordExpectedLive(array $context): array
    {
        $date = trim((string)($context['date'] ?? ''));
        $place = strtoupper(trim((string)($context['place'] ?? '')));
        $races = is_array($context['races'] ?? null) ? $context['races'] : [];
        if (!$this->validDate($date) || !preg_match('/^[A-Z0-9]{3}$/', $place) || $races === []) {
            return ['status' => 'SKIPPED', 'expected' => 0, 'duplicates' => 0, 'reason' => 'INVALID_EXPECTED_CONTEXT'];
        }
        $implementationStatus = $this->formalWriteAuthorization();
        if (!$implementationStatus['accepted']) {
            $this->recordFormalWriteRejection($date, $place, array_column($races, 'race_code'), $implementationStatus);
            return ['status' => 'SKIPPED', 'expected' => 0, 'duplicates' => 0, 'tainted' => 0,
                'reason' => (string)$implementationStatus['reason']];
        }
        $implementation = $implementationStatus['implementation'];
        $comparative = ComparativeContractV1::production(dirname(__DIR__, 2));
        $comparativeIds = $comparative->identities();
        $expected = 0;
        $duplicates = 0;
        $tainted = 0;
        $blocked = 0;
        $attemptedAt = $this->now();
        foreach ($races as $race) {
            if (!is_array($race)) continue;
            $raceCode = strtoupper(trim((string)($race['race_code'] ?? '')));
            $cutoff = trim((string)($race['cutoff_timestamp'] ?? ''));
            if (!preg_match('/^' . preg_quote(str_replace('-', '', $date), '/') . preg_quote($place, '/') . '(0[1-9]|1[0-2])$/', $raceCode)
                || !$this->isTimestampWithOffset($cutoff) || !$this->isBeforeCutoff($attemptedAt, $cutoff)) {
                continue;
            }
            $schedule = $this->formalScheduleStatus($date, $raceCode);
            if (!$schedule['accepted'] || $cutoff !== $schedule['cutoff_timestamp']) {
                $tainted++;
                $this->recordFormalScheduleRejection($date, $place, $raceCode, $attemptedAt, $schedule,
                    $cutoff !== $schedule['cutoff_timestamp'] ? 'SCHEDULE_CUTOFF_CONTRACT_MISMATCH' : (string)$schedule['status']);
                continue;
            }
            for ($course = 1; $course <= 6; $course++) {
                foreach (array_keys(self::TARGET_LEVELS) as $target) {
                    $use = $this->comparativeUseOrNull($comparative, $place, $course, $target);
                    $key = $this->stableKey($raceCode, $place, $course, $target);
                    $row = [
                        'window_id' => (string)$implementationStatus['window_id'],
                        'epoch_status' => self::EPOCH_STATUS,
                        'stable_unique_key' => $key,
                        'opportunity_identity' => $key,
                        'scheduler_candidate_key' => 'scheduler-opportunity|' . $raceCode,
                        'race_code' => $raceCode,
                        'venue' => $place,
                        'course' => $course,
                        'target' => $target,
                        'capture_stage' => self::CANONICAL_STAGE,
                        'capture_mode' => 'LIVE_STRICT',
                        'expected_at' => $attemptedAt,
                        'cutoff_timestamp' => $cutoff,
                        'timezone' => 'Asia/Tokyo',
                        'deadline_source' => trim((string)($race['deadline_source'] ?? '')),
                        'expected_status' => 'EXPECTED',
                        'comparative_scope_identity' => $comparativeIds['comparative_scope_identity'],
                        'comparative_policy_identity' => $comparativeIds['comparative_policy_identity'],
                        'input_snapshot_spec_identity' => $comparativeIds['input_snapshot_spec_identity'],
                        'pair_record_schema_identity' => $comparativeIds['pair_record_schema_identity'],
                        // Gate A/B/C preserve the structural 18-row matrix.
                        // Comparative Contract records pairs only for the
                        // separately registered production uses.
                        'comparative_scope_class' => is_array($use) ? $use['scope_class'] : 'NOT_DEPLOYED',
                        'measurement_implementation_identity' => $implementation['implementation_identity'],
                        'measurement_manifest_schema_version' => $implementation['manifest_schema_version'],
                        'measurement_manifest_sha256' => $implementation['manifest_sha256'],
                        'measurement_manifest_ref' => $implementation['manifest_ref'],
                        'measurement_source_freeze_sha256' => $implementation['source_freeze_sha256'],
                        'measurement_source_freeze_ref' => $implementation['source_freeze_ref'],
                    ];
                    $result = $this->writeExpectedOne($row);
                    if ($result === 'saved') $expected++;
                    if ($result === 'duplicate') $duplicates++;
                    if ($result === 'blocked') $blocked++;
                }
            }
        }
        return [
            'status' => $blocked > 0 && $expected === 0 ? 'SKIPPED' : 'ok',
            'expected' => $expected,
            'duplicates' => $duplicates,
            'tainted' => $tainted,
            'reason' => $blocked > 0 ? 'FORMAL_WRITE_HOLD' : '',
        ];
    }

    /** @param array<string,mixed> $context */
    public function recordCaptureFailure(array $context): void
    {
        $authorization = $this->formalWriteAuthorization();
        if (!$authorization['accepted']) return;
        $date = trim((string)($context['date'] ?? ''));
        $place = strtoupper(trim((string)($context['place'] ?? '')));
        $raceCodes = array_values(array_filter(array_map(
            static fn($value): string => strtoupper(trim((string)$value)),
            (array)($context['race_codes'] ?? [])
        ), static fn(string $code): bool => preg_match('/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $code) === 1));
        $intendedKeys = $this->intendedKeys($raceCodes, $place);
        // A database failure before race enumeration cannot truthfully name a
        // race.  Preserve that boundary explicitly rather than fabricating an
        // expected key; venue/race failures later in the scheduler retain the
        // full intended-key list for exact linkage.
        $scope = $raceCodes === [] ? 'DATE_WIDE_RACE_ENUMERATION' : 'RACE_SPECIFIC_CAPTURE';
        $opportunityIdentity = $raceCodes === []
            ? 'strict-forward|' . $date . '|exhibition|race-enumeration'
            : 'strict-forward|' . $date . '|exhibition|keys:' . hash('sha256', implode('|', $intendedKeys));
        $this->appendFailureAudit($date, [
            'event' => 'capture_failure',
            'attempted_at' => $this->now(),
            'venue' => $place,
            'capture_stage' => self::CANONICAL_STAGE,
            'race_codes' => $raceCodes,
            'intended_keys' => $intendedKeys,
            'opportunity_scope' => $scope,
            'opportunity_identity' => $opportunityIdentity,
            'error_type' => trim((string)($context['error_type'] ?? 'CAPTURE_PROCESS_ERROR')),
            'error_code' => trim((string)($context['error_code'] ?? 'UNKNOWN')),
            'error_message' => trim((string)($context['error_message'] ?? '')),
        ]);
    }

    /**
     * @param array<string,mixed> $context
     * @return array{status:string,records:int,duplicates:int,conflicts:int,reason?:string}
     */
    public function captureLive(array $context): array
    {
        $date = trim((string)($context['date'] ?? ''));
        $place = strtoupper(trim((string)($context['place'] ?? '')));
        $phase = trim((string)($context['phase'] ?? ''));
        $lateReplay = !empty($context['late_replay']);
        if (!$this->validDate($date) || !preg_match('/^[A-Z0-9]{3}$/', $place) || $phase !== 'live' || $lateReplay) {
            return $this->operationalResult($date, 'SKIPPED', 'NOT_LIVE_STRICT_CONTEXT');
        }

        $capturedAt = $this->now();
        $implementationStatus = $this->formalWriteAuthorization();
        if (!$implementationStatus['accepted']) {
            $this->recordFormalWriteRejection($date, $place, [], $implementationStatus);
            return ['status' => 'SKIPPED', 'records' => 0, 'duplicates' => 0, 'conflicts' => 0,
                'reason' => (string)$implementationStatus['reason']];
        }
        $readyCodes = array_values(array_unique(array_filter(array_map(
            static fn($value): string => strtoupper(trim((string)$value)),
            (array)($context['ready_race_codes'] ?? [])
        ), static fn(string $value): bool => preg_match('/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $value) === 1)));
        if ($readyCodes === []) {
            return $this->operationalResult($date, 'SKIPPED', 'NO_EXHIBITION_READY_RACES');
        }
        $implementation = $implementationStatus['implementation'];
        $comparative = ComparativeContractV1::production(dirname(__DIR__, 2));
        $comparativeIds = $comparative->identities();

        $deadlines = $this->loadDeadlineMetadata($date);
        $legacy = is_array($context['legacy_by_course'] ?? null) ? $context['legacy_by_course'] : [];
        $traditionalEvaluations = is_array($context['traditional_evaluation_by_course'] ?? null)
            ? $context['traditional_evaluation_by_course'] : [];
        $mlDecisions = is_array($context['ml_decisions'] ?? null) ? $context['ml_decisions'] : [];
        $mlFailureByRace = is_array($context['ml_failure_by_race'] ?? null) ? $context['ml_failure_by_race'] : [];
        $defaultMlFailure = $this->normalizeMlFailureReason((string)($context['ml_failure_reason'] ?? ''));
        $logicVersion = trim((string)($context['logic_version'] ?? ''));
        $traditionalVersion = trim((string)($context['traditional_policy_version'] ?? ''));
        $fallbackMlVersion = trim((string)($context['ml_policy_version'] ?? ''));
        $records = [];
        foreach ($readyCodes as $raceCode) {
            $schedule = $this->formalScheduleStatus($date, $raceCode);
            if (!$schedule['accepted']) {
                $this->recordFormalScheduleRejection($date, $place, $raceCode, $capturedAt, $schedule, (string)$schedule['status']);
                continue;
            }
            $deadline = $deadlines[$raceCode] ?? null;
            if (!is_array($deadline) || !is_string($deadline['cutoff_timestamp'] ?? null)) {
                $this->appendOperational($date, [
                    'event' => 'capture_rejected',
                    'reason' => 'DEADLINE_MISSING',
                    'race_code' => $raceCode,
                    'captured_at' => $capturedAt,
                ]);
                $this->appendFailureAudit($date, [
                    'event' => 'capture_rejected', 'attempted_at' => $capturedAt,
                    'venue' => $place, 'capture_stage' => self::CANONICAL_STAGE,
                    'intended_keys' => $this->intendedKeys([$raceCode], $place),
                    'error_type' => 'DEADLINE', 'error_code' => 'DEADLINE_MISSING',
                ]);
                continue;
            }
            $cutoff = (string)$deadline['cutoff_timestamp'];
            if ($cutoff !== $schedule['cutoff_timestamp']) {
                $this->recordFormalScheduleRejection($date, $place, $raceCode, $capturedAt, $schedule, 'SCHEDULE_CUTOFF_CONTRACT_MISMATCH');
                continue;
            }
            if (!$this->isBeforeCutoff($capturedAt, $cutoff)) {
                $this->appendOperational($date, [
                    'event' => 'capture_rejected',
                    'reason' => 'AFTER_CUTOFF',
                    'race_code' => $raceCode,
                    'captured_at' => $capturedAt,
                    'cutoff_timestamp' => $cutoff,
                ]);
                $this->appendFailureAudit($date, [
                    'event' => 'capture_rejected', 'attempted_at' => $capturedAt,
                    'venue' => $place, 'capture_stage' => self::CANONICAL_STAGE,
                    'intended_keys' => $this->intendedKeys([$raceCode], $place),
                    'error_type' => 'TIMING', 'error_code' => 'AFTER_CUTOFF',
                ]);
                continue;
            }

            $byCourse = $mlDecisions[$raceCode] ?? null;
            $raceMlAvailable = is_array($byCourse);
            $raceMlFailure = $this->normalizeMlFailureReason((string)($mlFailureByRace[$raceCode] ?? $defaultMlFailure));
            if (!$raceMlAvailable) {
                $this->appendOperational($date, [
                    'event' => 'capture_missing',
                    'reason' => $raceMlFailure,
                    'race_code' => $raceCode,
                    'captured_at' => $capturedAt,
                ]);
                $this->appendFailureAudit($date, [
                    'event' => 'capture_missing', 'attempted_at' => $capturedAt,
                    'venue' => $place, 'capture_stage' => self::CANONICAL_STAGE,
                    'intended_keys' => $this->intendedKeys([$raceCode], $place),
                    'error_type' => $raceMlFailure === 'ARTIFACT_ERROR' ? 'ARTIFACT' : 'INFERENCE_INPUT',
                    'error_code' => $raceMlFailure,
                ]);
                $byCourse = [];
            }
            // Persist all 18 course/target rows.  Missing ML data is explicit
            // eligibility information, not an omitted denominator.
            for ($course = 1; $course <= 6; $course++) {
                $courseDecision = $byCourse[(string)$course] ?? $byCourse[$course] ?? null;
                $courseAvailable = is_array($courseDecision);
                $targets = $courseAvailable && is_array($courseDecision['targets'] ?? null)
                    ? $courseDecision['targets'] : [];
                foreach (array_keys(self::TARGET_LEVELS) as $target) {
                    $use = $this->comparativeUseOrNull($comparative, $place, $course, $target);
                    $ml = $targets[$target] ?? null;
                    $mlAvailable = is_array($ml);
                    $decisionAvailable = $mlAvailable && !empty($ml['decision_available']);
                    $unavailableReason = !$raceMlAvailable
                        ? $raceMlFailure
                        : (!$courseAvailable ? 'ML_COURSE_NOT_DEPLOYED'
                        : (!$mlAvailable ? 'ML_TARGET_NOT_DEPLOYED'
                        : (string)($ml['unavailable_reason'] ?? 'INPUT_MISSING')));
                    $targetLevel = self::TARGET_LEVELS[$target];
                    $traditional = self::runtimeTraditionalDecision(
                        $legacy,
                        $traditionalEvaluations,
                        $course,
                        $raceCode,
                        $targetLevel
                    );
                    $records[] = [
                        'window_id' => (string)$implementationStatus['window_id'],
                        'epoch_status' => self::EPOCH_STATUS,
                        'capture_mode' => 'LIVE_STRICT',
                        'capture_source' => 'course_signal_api_live',
                        'capture_stage' => self::CANONICAL_STAGE,
                        'race_code' => $raceCode,
                        'venue' => $place,
                        'scheduler_candidate_key' => 'scheduler-opportunity|' . $raceCode,
                        'course' => $course,
                        'target' => $target,
                        // One venue/date is the only safely derivable meet grouping
                        // in the current schema; the explicit value documents it.
                        'meet_id' => $date . ':' . $place,
                        'captured_at' => $capturedAt,
                        'prediction_timestamp' => $capturedAt,
                        'cutoff_timestamp' => $cutoff,
                        'timezone' => 'Asia/Tokyo',
                        'deadline_source' => (string)($deadline['deadline_source'] ?? ''),
                        'before_cutoff' => true,
                        'eligible' => $decisionAvailable,
                        'eligibility_reason' => $decisionAvailable ? 'EXHIBITION_READY_AND_MODEL_DECISION_AVAILABLE' : $unavailableReason,
                        'exclusion_reason' => $decisionAvailable ? null : $unavailableReason,
                        'selection_subject_id' => trim((string)(is_array($courseDecision) ? ($courseDecision['player_id'] ?? '') : '')),
                        'ml_selected' => $decisionAvailable ? !empty($ml['selected']) : null,
                        'ml_policy_version' => trim((string)($ml['model_version'] ?? $fallbackMlVersion)),
                        'artifact_identifier' => trim((string)($ml['artifact_identifier'] ?? '')),
                        'artifact_sha256' => trim((string)($ml['artifact_sha256'] ?? '')),
                        'ml_threshold' => $ml['threshold'] ?? null,
                        'logic_version' => $logicVersion,
                        // A missing legacy detail is intentionally *not* treated
                        // as false: existing display maps only contain positives.
                        'traditional_selected' => $traditional['selected'],
                        'traditional_policy_version' => $traditionalVersion,
                        'traditional_logic_version' => $logicVersion,
                        'traditional_decision_status' => $traditional['status'],
                        'traditional_unknown_classification' => $traditional['classification'],
                        'traditional_component_count' => $traditional['component_count'],
                        'comparative_scope_identity' => $comparativeIds['comparative_scope_identity'],
                        'comparative_policy_identity' => $comparativeIds['comparative_policy_identity'],
                        'input_snapshot_spec_identity' => $comparativeIds['input_snapshot_spec_identity'],
                        'pair_record_schema_identity' => $comparativeIds['pair_record_schema_identity'],
                        'comparative_scope_class' => is_array($use) ? $use['scope_class'] : 'NOT_DEPLOYED',
                        'measurement_implementation_identity' => $implementation['implementation_identity'],
                        'measurement_manifest_schema_version' => $implementation['manifest_schema_version'],
                        'measurement_manifest_sha256' => $implementation['manifest_sha256'],
                        'measurement_manifest_ref' => $implementation['manifest_ref'],
                        'measurement_source_freeze_sha256' => $implementation['source_freeze_sha256'],
                        'measurement_source_freeze_ref' => $implementation['source_freeze_ref'],
                        'capture_status' => $decisionAvailable
                            ? ($traditional['selected'] === null ? 'TRADITIONAL_DECISION_UNAVAILABLE' : 'READY')
                            : $unavailableReason,
                        'missing_reason' => $decisionAvailable
                            ? $traditional['reason']
                            : $unavailableReason,
                    ];
                }
            }
        }
        return $this->writeRecords($records, true);
    }

    /**
     * Maps the already-computed legacy policy result to the one formal
     * Traditional comparator used by Strict Forward.  This is deliberately
     * shared with Comparative Contract capture so a pair can never use a
     * second, reconstructed interpretation of the display maps.
     *
     * @return array{selected:?bool,status:string,reason:?string,classification:?string,component_count:int}
     */
    public static function formalTraditionalDecision(
        array $legacy,
        array $traditionalEvaluations,
        int $course,
        string $raceCode,
        int $targetLevel
    ): array
    {
        if ($course === 2) {
            // The product may display independent 2C sashi/makuri signals,
            // but Strict Forward never promotes either component (or their
            // OR/AND) into one formal Traditional comparator. Comparative
            // Contract v1 records this use as ML_ONLY / Traditional NONE.
            $components = [];
            foreach (['sashi', 'makuri'] as $technique) {
                $detail = is_array($legacy[2] ?? null) ? ($legacy[2][$technique][$raceCode] ?? null) : null;
                if (is_array($detail) && isset($detail['star_level'])) $components[] = $detail;
            }
            return [
                'selected' => null,
                'status' => 'FORMAL_TRADITIONAL_NONE',
                'reason' => 'COMPARATIVE_CONTRACT_2C_SYNTHETIC_FORBIDDEN',
                'classification' => 'POLICY_UNDEFINED',
                'component_count' => count($components),
            ];
        }

        return self::runtimeTraditionalDecision($legacy, $traditionalEvaluations, $course, $raceCode, $targetLevel);
    }

    /**
     * Projects the production display policy into operational readiness.
     * This intentionally retains the pre-existing 2C component semantics for
     * the live status ledger.  It is not a Formal Comparative decision: the
     * separate public method above returns NONE for 2C so a synthetic pair can
     * never be persisted.
     *
     * @param array<string,mixed> $legacy
     * @return array{selected:?bool,status:string,reason:?string,classification:?string,component_count:int}
     */
    private static function runtimeTraditionalDecision(
        array $legacy,
        array $traditionalEvaluations,
        int $course,
        string $raceCode,
        int $targetLevel
    ): array
    {
        if ($course === 2) {
            $components = [];
            foreach (['sashi', 'makuri'] as $technique) {
                $detail = is_array($legacy[2] ?? null) ? ($legacy[2][$technique][$raceCode] ?? null) : null;
                if (is_array($detail) && isset($detail['star_level'])) $components[] = $detail;
            }
            foreach ($components as $detail) {
                if ((int)$detail['star_level'] >= $targetLevel) {
                    return [
                        'selected' => true,
                        'status' => 'OBSERVED_LEGACY_2C_COMPONENT',
                        'reason' => null,
                        'classification' => 'RESOLVED_TRUE_FALSE',
                        'component_count' => count($components),
                    ];
                }
            }
            if ($components !== []) {
                return [
                    'selected' => null,
                    'status' => 'TRADITIONAL_DECISION_UNAVAILABLE',
                    'reason' => 'COMBINED_2C_POLICY_UNDEFINED',
                    'classification' => 'POLICY_UNDEFINED',
                    'component_count' => count($components),
                ];
            }
        }

        $detail = $legacy[$course][$raceCode] ?? null;
        if (is_array($detail) && isset($detail['star_level'])) {
            return [
                'selected' => (int)$detail['star_level'] >= $targetLevel,
                'status' => 'OBSERVED_LEGACY_SIGNAL',
                'reason' => null,
                'classification' => 'RESOLVED_TRUE_FALSE',
                'component_count' => 1,
            ];
        }
        $evaluation = $traditionalEvaluations[$course][$raceCode]
            ?? $traditionalEvaluations[(string)$course][$raceCode]
            ?? null;
        if (is_array($evaluation) && array_key_exists('policy_exists', $evaluation)) {
            if (empty($evaluation['policy_exists'])) {
                return [
                    'selected' => null,
                    'status' => 'TRADITIONAL_DECISION_UNAVAILABLE',
                    'reason' => (string)($evaluation['reason'] ?? 'PRIMARY_POLICY_DISABLED'),
                    'classification' => 'POLICY_UNDEFINED',
                    'component_count' => 0,
                ];
            }
            if (!empty($evaluation['evaluated'])) {
                return [
                    'selected' => false,
                    'status' => 'RESOLVED_TRADITIONAL_FALSE',
                    'reason' => null,
                    'classification' => 'RESOLVED_TRUE_FALSE',
                    'component_count' => 1,
                ];
            }
            return [
                'selected' => null,
                'status' => 'TRADITIONAL_DECISION_UNAVAILABLE',
                'reason' => (string)($evaluation['reason'] ?? 'TRADITIONAL_INPUT_MISSING'),
                'classification' => 'INPUT_UNAVAILABLE',
                'component_count' => 0,
            ];
        }
        return [
            'selected' => null,
            'status' => 'TRADITIONAL_DECISION_UNAVAILABLE',
            'reason' => 'IMPLEMENTATION_UNAVAILABLE_LEGACY_NEGATIVE_NOT_EXPLICIT',
            'classification' => 'IMPLEMENTATION_UNAVAILABLE',
            'component_count' => 0,
        ];
    }

    /** @return array{accepted:bool,status:string,reason:string,cutoff_timestamp:string,official_status:string,schedule_revision_integrity:string} */
    private function formalScheduleStatus(string $date, string $raceCode): array
    {
        return ($this->opportunityLedger ?? StrictForwardOpportunityLedger::production())
            ->formalMeasurementStatus($date, $raceCode);
    }

    /** @return array<string,mixed>|null */
    private function comparativeUseOrNull(ComparativeContractV1 $contract, string $venue, int $course, string $target): ?array
    {
        try {
            return $contract->use($venue, $course, $target);
        } catch (Throwable) {
            // Structural readiness rows are intentionally broader than the
            // deployed comparison scope.  An unregistered row remains a
            // formal 18-row observation, never an invented comparison pair.
            return null;
        }
    }

    private function normalizeMlFailureReason(string $reason): string
    {
        $reason = strtoupper(trim($reason));
        return in_array($reason, [
            'ARTIFACT_ERROR', 'INFERENCE_INPUT_EMPTY', 'INFERENCE_INPUT_INCOMPLETE', 'INFERENCE_INPUT_ERROR',
        ], true) ? $reason : 'ARTIFACT_ERROR';
    }

    private function declaredImplementation(): WindowDeclaredImplementationIdentity
    {
        if ($this->windowContext !== null) return $this->windowContext->declaration();
        if ($this->declaredImplementation !== null) return $this->declaredImplementation;
        throw new LogicException('FORMAL_WINDOW_CONTEXT_REQUIRED');
    }

    private function activationHold(): WindowActivationHold
    {
        if ($this->windowContext !== null) return $this->windowContext->activationHold();
        if ($this->activationHold !== null) return $this->activationHold;
        throw new LogicException('FORMAL_WINDOW_CONTEXT_REQUIRED');
    }

    /** @return array{accepted:bool,status:string,reason:string,manifest:array<string,mixed>,activation:array<string,mixed>,implementation:array<string,string>} */
    private function formalWriteAuthorization(): array
    {
        try {
            $context = $this->resolvedWindowContext();
            if ($context === null) {
                return ['accepted' => false, 'status' => 'WINDOW_CONTEXT_UNRESOLVED', 'reason' => 'FORMAL_WINDOW_CONTEXT_REQUIRED', 'window_id' => '', 'manifest' => [], 'declaration' => [], 'activation' => [], 'implementation' => []];
            }
            if (!hash_equals(rtrim($context->ledgerRoot(), DIRECTORY_SEPARATOR), rtrim($this->rootPath, DIRECTORY_SEPARATOR))) {
                return ['accepted' => false, 'status' => 'WINDOW_CONTEXT_WRITE_BLOCKED', 'reason' => 'FORMAL_LEDGER_ROOT_MISMATCH', 'window_id' => '', 'manifest' => [], 'declaration' => [], 'activation' => [], 'implementation' => []];
            }
            if ($this->opportunityLedger !== null
                && !hash_equals(rtrim($context->opportunityRoot(), DIRECTORY_SEPARATOR), rtrim($this->opportunityLedger->rootPath(), DIRECTORY_SEPARATOR))) {
                return ['accepted' => false, 'status' => 'WINDOW_CONTEXT_WRITE_BLOCKED', 'reason' => 'FORMAL_OPPORTUNITY_ROOT_MISMATCH', 'window_id' => '', 'manifest' => [], 'declaration' => [], 'activation' => [], 'implementation' => []];
            }
            return $context->authorizeMeasurementWrite();
        } catch (Throwable $error) {
            return ['accepted' => false, 'status' => 'WINDOW_CONTEXT_UNRESOLVED', 'reason' => 'FORMAL_WINDOW_CONTEXT_UNAVAILABLE', 'window_id' => '', 'manifest' => [], 'declaration' => [], 'activation' => [], 'implementation' => []];
        }
    }

    private function resolvedWindowContext(): ?FormalMeasurementWindowContext
    {
        if ($this->windowContext !== null) return $this->windowContext;
        if ($this->declaredImplementation === null || $this->activationHold === null) return null;
        return FormalMeasurementWindowContext::fromBoundComponents(
            $this->declaredImplementation->repoRoot(),
            $this->rootPath,
            $this->opportunityLedger instanceof StrictForwardOpportunityLedger ? $this->opportunityLedger->rootPath() : $this->rootPath . '/opportunity',
            $this->declaredImplementation,
            $this->activationHold,
        );
    }

    private function beforeFormalPersistence(string $kind): void
    {
        if ($this->beforeFormalPersist !== null) ($this->beforeFormalPersist)($kind);
    }

    /** @param array<string,mixed> $row @param array<string,string> $implementation */
    private function rowMatchesImplementation(array $row, array $authorization): bool
    {
        $implementation = $authorization['implementation'] ?? [];
        $windowId = (string)($authorization['window_id'] ?? '');
        if ($windowId === '' || !hash_equals($windowId, (string)($row['window_id'] ?? ''))) return false;
        foreach ([
            'implementation_identity' => 'measurement_implementation_identity',
            'manifest_schema_version' => 'measurement_manifest_schema_version',
            'manifest_sha256' => 'measurement_manifest_sha256',
            'manifest_ref' => 'measurement_manifest_ref',
            'source_freeze_sha256' => 'measurement_source_freeze_sha256',
            'source_freeze_ref' => 'measurement_source_freeze_ref',
        ] as $source => $rowKey) {
            if (!hash_equals((string)($implementation[$source] ?? ''), (string)($row[$rowKey] ?? ''))) return false;
        }
        return true;
    }

    /**
     * @param list<mixed> $raceCodes
     * @param array{accepted:bool,status:string,reason:string,declaration:array<string,mixed>,implementation:array<string,string>} $status
     */
    private function recordFormalWriteRejection(string $date, string $place, array $raceCodes, array $status): void
    {
        // A closed Window is immutable, including diagnostic append paths.
        if (in_array((string)($status['reason'] ?? ''), ['WINDOW_CLOSED', 'FORMAL_WRITE_NOT_ACTIVE'], true)) return;
        $codes = array_values(array_filter(array_map(static fn(mixed $value): string => strtoupper(trim((string)$value)), $raceCodes), static fn(string $code): bool => preg_match('/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $code) === 1));
        $this->appendOperational($date, [
            'event' => 'formal_evidence_rejected', 'reason' => (string)$status['reason'],
            'measurement_provenance_status' => (string)$status['status'], 'observed_at' => $this->now(), 'race_codes' => $codes,
            'declared_implementation_identity' => $status['declaration']['implementation_identity'] ?? null,
            'current_implementation_identity' => $status['implementation']['implementation_identity'] ?? null,
        ]);
        $this->appendFailureAudit($date, [
            'event' => 'formal_evidence_rejected', 'attempted_at' => $this->now(), 'venue' => $place,
            'capture_stage' => self::CANONICAL_STAGE, 'race_codes' => $codes,
            'intended_keys' => $this->intendedKeys($codes, $place),
            'error_type' => 'MEASUREMENT_PROVENANCE',
            'error_code' => (string)$status['status'],
            'error_message' => (string)$status['reason'],
            'declared_implementation_identity' => $status['declaration']['implementation_identity'] ?? null,
            'current_implementation_identity' => $status['implementation']['implementation_identity'] ?? null,
        ]);
    }

    /** @param array{accepted:bool,status:string,reason:string,cutoff_timestamp:string,official_status:string,schedule_revision_integrity:string} $schedule */
    private function recordFormalScheduleRejection(string $date, string $place, string $raceCode, string $observedAt, array $schedule, string $code): void
    {
        $fields = [
            'event' => 'formal_evidence_rejected', 'reason' => $code, 'race_code' => $raceCode,
            'observed_at' => $observedAt, 'schedule_measurement_status' => $schedule['status'],
            'schedule_integrity' => $schedule['schedule_revision_integrity'],
            'effective_cutoff_timestamp' => $schedule['cutoff_timestamp'],
            'effective_official_status' => $schedule['official_status'],
            'tainted' => $schedule['status'] === 'TAINTED_SEMANTIC_REVISION_UNVERIFIED',
        ];
        $this->appendOperational($date, $fields);
        $this->appendFailureAudit($date, [
            'event' => 'formal_evidence_rejected', 'attempted_at' => $observedAt, 'venue' => $place,
            'capture_stage' => self::CANONICAL_STAGE, 'intended_keys' => $this->intendedKeys([$raceCode], $place),
            'error_type' => 'SCHEDULE_INTEGRITY', 'error_code' => $code,
            'schedule_measurement_status' => $schedule['status'],
            'schedule_integrity' => $schedule['schedule_revision_integrity'],
            'tainted' => $schedule['status'] === 'TAINTED_SEMANTIC_REVISION_UNVERIFIED',
        ]);
    }

    /**
     * Public for deterministic readiness fixtures. It has no outcome fields and
     * enforces first-write-wins independently of the API.
     *
     * @param list<array<string,mixed>> $records
     * @return array{status:string,records:int,duplicates:int,conflicts:int,reason?:string}
     */
    public function writeRecords(array $records, bool $requireFormalActivation = false): array
    {
        $saved = 0;
        $duplicates = 0;
        $conflicts = 0;
        $blockedReason = '';
        foreach ($records as $input) {
            try {
                if ($requireFormalActivation && (($input['capture_mode'] ?? '') === 'LIVE_STRICT')) {
                    $this->beforeFormalPersistence('CANONICAL');
                    $authorization = $this->formalWriteAuthorization();
                    if (!$authorization['accepted'] || !$this->rowMatchesImplementation($input, $authorization)) {
                        $blockedReason = $authorization['accepted'] ? 'PERSISTENCE_IMPLEMENTATION_MISMATCH' : (string)$authorization['reason'];
                        $date = is_string($input['race_code'] ?? null) ? $this->normalizeDateFromRace(substr((string)$input['race_code'], 0, 8)) : date('Y-m-d');
                        $this->recordFormalWriteRejection($date, strtoupper(trim((string)($input['venue'] ?? ''))), [(string)($input['race_code'] ?? '')], $authorization);
                        continue;
                    }
                }
                $record = $this->normalizeRecord($input);
                $result = $this->writeOne($record);
                if ($result === 'saved') $saved++;
                elseif ($result === 'duplicate') $duplicates++;
                elseif ($result === 'conflict') $conflicts++;
            } catch (Throwable $error) {
                $date = is_string($input['race_code'] ?? null) ? substr((string)$input['race_code'], 0, 8) : date('Y-m-d');
                $this->appendOperational($this->normalizeDateFromRace($date), [
                    'event' => 'write_error',
                    'reason' => 'WRITE_ERROR',
                    'error_code' => $this->safeErrorCode($error),
                ]);
                $this->appendFailureAudit($this->normalizeDateFromRace($date), [
                    'event' => 'ledger_write_error', 'attempted_at' => $this->now(),
                    'capture_stage' => self::CANONICAL_STAGE,
                    'intended_key' => is_string($input['stable_unique_key'] ?? null) ? $input['stable_unique_key'] : null,
                    'error_type' => 'LEDGER_IO', 'error_code' => $this->safeErrorCode($error),
                ]);
            }
        }
        return ['status' => $blockedReason !== '' && $saved === 0 ? 'SKIPPED' : 'ok', 'records' => $saved, 'duplicates' => $duplicates, 'conflicts' => $conflicts, 'reason' => $blockedReason];
    }

    /** @return array<string,mixed> */
    public static function outcomeLedgerContract(): array
    {
        return [
            'prediction_record_id', 'stable_unique_key', 'race_code', 'venue', 'course', 'target',
            'target_outcome', 'result_available_at', 'result_source', 'result_version', 'outcome_joined_at',
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function normalizeRecord(array $input): array
    {
        foreach (['target_outcome', 'actual_result', 'payout', 'roi', 'hit_rate'] as $forbidden) {
            if (array_key_exists($forbidden, $input)) {
                throw new InvalidArgumentException('outcome_field_not_allowed');
            }
        }
        $raceCode = strtoupper(trim((string)($input['race_code'] ?? '')));
        $venue = strtoupper(trim((string)($input['venue'] ?? '')));
        $course = (int)($input['course'] ?? 0);
        $target = trim((string)($input['target'] ?? ''));
        $stage = trim((string)($input['capture_stage'] ?? ''));
        $mode = trim((string)($input['capture_mode'] ?? ''));
        if (!preg_match('/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $raceCode)
            || !preg_match('/^[A-Z0-9]{3}$/', $venue)
            || $venue !== substr($raceCode, 8, 3)
            || $course < 1 || $course > 6 || !isset(self::TARGET_LEVELS[$target])
            || $stage !== self::CANONICAL_STAGE || !in_array($mode, self::CAPTURE_MODES, true)) {
            throw new InvalidArgumentException('invalid_identity');
        }
        $capturedAt = trim((string)($input['captured_at'] ?? ''));
        $predictionAt = trim((string)($input['prediction_timestamp'] ?? ''));
        $cutoffAt = trim((string)($input['cutoff_timestamp'] ?? ''));
        if (!$this->isTimestampWithOffset($capturedAt) || !$this->isTimestampWithOffset($predictionAt)
            || !$this->isTimestampWithOffset($cutoffAt) || trim((string)($input['timezone'] ?? '')) === '') {
            throw new InvalidArgumentException('timezone_or_timestamp_missing');
        }
        $beforeCutoff = $this->isBeforeCutoff($predictionAt, $cutoffAt);
        if ($mode === 'LIVE_STRICT' && !$beforeCutoff) {
            throw new InvalidArgumentException('after_cutoff_live_strict');
        }
        if ($mode === 'REPLAY_FROM_FROZEN_DECISION') {
            foreach (['replay_source_id', 'replay_original_captured_at', 'replay_source_hash'] as $field) {
                if (trim((string)($input[$field] ?? '')) === '') {
                    throw new InvalidArgumentException('frozen_replay_provenance_missing');
                }
            }
        }
        $eligible = (bool)($input['eligible'] ?? false);
        $mlSelected = $input['ml_selected'] ?? null;
        $traditionalSelected = $input['traditional_selected'] ?? null;
        if ($eligible && !is_bool($mlSelected)) {
            throw new InvalidArgumentException('ml_decision_missing');
        }
        if ($eligible && $traditionalSelected !== null && !is_bool($traditionalSelected)) {
            throw new InvalidArgumentException('traditional_decision_invalid');
        }
        $record = $input;
        $record['epoch_status'] = self::EPOCH_STATUS;
        $record['race_code'] = $raceCode;
        $record['venue'] = $venue;
        $record['course'] = $course;
        $record['target'] = $target;
        $record['capture_stage'] = $stage;
        $record['capture_mode'] = $mode;
        $record['captured_at'] = $capturedAt;
        $record['prediction_timestamp'] = $predictionAt;
        $record['cutoff_timestamp'] = $cutoffAt;
        $record['before_cutoff'] = $beforeCutoff;
        $record['eligible'] = $eligible;
        $record['ml_selected'] = $mlSelected;
        $record['traditional_selected'] = $traditionalSelected;
        $record['formal_candidate'] = $mode === 'LIVE_STRICT' && $beforeCutoff && $eligible;
        if ($record['formal_candidate']) {
            $identity = (string)($record['measurement_implementation_identity'] ?? '');
            $manifest = (string)($record['measurement_manifest_sha256'] ?? '');
            $freeze = (string)($record['measurement_source_freeze_sha256'] ?? '');
            $manifestRef = (string)($record['measurement_manifest_ref'] ?? '');
            $freezeRef = (string)($record['measurement_source_freeze_ref'] ?? '');
            if (!preg_match('/^sha256:[a-f0-9]{64}$/', $identity)
                || !preg_match('/^sha256:[a-f0-9]{64}$/', $manifest)
                || !preg_match('/^sha256:[a-f0-9]{64}$/', $freeze)
                || !preg_match('#^implementation_manifests/[a-f0-9]{64}\\.json$#', $manifestRef)
                || !preg_match('#^implementation_sources/[a-f0-9]{64}\\.json$#', $freezeRef)) {
                throw new InvalidArgumentException('measurement_implementation_identity_missing');
            }
        }
        $record['stable_unique_key'] = $this->stableKey($raceCode, $venue, $course, $target);
        $decision = $record;
        unset($decision['captured_at'], $decision['prediction_timestamp'], $decision['record_id'], $decision['record_hash'], $decision['decision_hash']);
        $record['decision_hash'] = hash('sha256', $this->canonicalJson($decision));
        $record['record_id'] = hash('sha256', $record['stable_unique_key'] . '|' . $record['decision_hash']);
        $record['record_hash'] = hash('sha256', $this->canonicalJson($record));
        return $record;
    }

    /** @param array<string,mixed> $record */
    private function writeOne(array $record): string
    {
        if (!$this->measurementWriteStillAllowed()) return 'blocked';
        $date = substr((string)$record['race_code'], 0, 8);
        $directory = $this->rootPath . '/records/' . $date;
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('ledger_directory_unavailable');
        }
        $path = $directory . '/' . hash('sha256', (string)$record['stable_unique_key']) . '.json';
        $json = $this->canonicalJson($record);
        $handle = @fopen($path, 'x');
        if (is_resource($handle)) {
            $ok = @fwrite($handle, $json . "\n") !== false;
            @fclose($handle);
            if (!$ok) {
                @unlink($path);
                throw new RuntimeException('ledger_write_failed');
            }
            return 'saved';
        }
        $existing = json_decode((string)@file_get_contents($path), true);
        if (is_array($existing) && hash_equals((string)($existing['decision_hash'] ?? ''), (string)$record['decision_hash'])) {
            $this->appendOperational($this->dateFromRace((string)$record['race_code']), [
                'event' => 'duplicate', 'stable_unique_key' => $record['stable_unique_key'], 'record_id' => $existing['record_id'] ?? null,
            ]);
            return 'duplicate';
        }
        $this->appendOperational($this->dateFromRace((string)$record['race_code']), [
            'event' => 'conflict', 'stable_unique_key' => $record['stable_unique_key'],
            'existing_decision_hash' => is_array($existing) ? ($existing['decision_hash'] ?? null) : null,
            'incoming_decision_hash' => $record['decision_hash'],
        ]);
        return 'conflict';
    }

    /** @param array<string,mixed> $row */
    private function writeExpectedOne(array $row): string
    {
        // This second, persistence-adjacent check closes the interval between
        // scheduler enumeration and the actual first-write attempt.
        $this->beforeFormalPersistence('EXPECTED');
        $authorization = $this->formalWriteAuthorization();
        if (!$authorization['accepted'] || !$this->rowMatchesImplementation($row, $authorization)) {
            $date = $this->normalizeDateFromRace(substr((string)$row['race_code'], 0, 8));
            $this->recordFormalWriteRejection($date, strtoupper(trim((string)($row['venue'] ?? ''))), [(string)($row['race_code'] ?? '')], $authorization);
            return 'blocked';
        }
        $date = substr((string)$row['race_code'], 0, 8);
        $directory = $this->rootPath . '/expected/' . $date;
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            $this->appendFailureAudit($this->dateFromRace((string)$row['race_code']), [
                'event' => 'expected_universe_write_failure',
                'attempted_at' => $this->now(),
                'venue' => $row['venue'] ?? '',
                'capture_stage' => self::CANONICAL_STAGE,
                'intended_key' => $row['stable_unique_key'] ?? null,
                'error_type' => 'LEDGER_IO',
                'error_code' => 'EXPECTED_DIRECTORY_UNAVAILABLE',
            ]);
            return 'error';
        }
        $path = $directory . '/' . hash('sha256', (string)$row['stable_unique_key']) . '.json';
        $json = $this->canonicalJson($row);
        $handle = @fopen($path, 'x');
        if (is_resource($handle)) {
            $ok = @fwrite($handle, $json . "\n") !== false;
            @fclose($handle);
            if ($ok) return 'saved';
            @unlink($path);
            $this->appendFailureAudit($this->dateFromRace((string)$row['race_code']), [
                'event' => 'expected_universe_write_failure',
                'attempted_at' => $this->now(),
                'venue' => $row['venue'] ?? '',
                'capture_stage' => self::CANONICAL_STAGE,
                'intended_key' => $row['stable_unique_key'] ?? null,
                'error_type' => 'LEDGER_IO',
                'error_code' => 'EXPECTED_WRITE_FAILED',
            ]);
            return 'error';
        }
        return is_file($path) ? 'duplicate' : 'error';
    }

    private function stableKey(string $raceCode, string $venue, int $course, string $target): string
    {
        return implode('|', [self::EPOCH_STATUS, $raceCode, $venue, $course, $target, self::CANONICAL_STAGE]);
    }

    /** @param list<string> $raceCodes @return list<string> */
    private function intendedKeys(array $raceCodes, string $venue): array
    {
        $keys = [];
        foreach ($raceCodes as $raceCode) {
            for ($course = 1; $course <= 6; $course++) {
                foreach (array_keys(self::TARGET_LEVELS) as $target) {
                    $keys[] = $this->stableKey($raceCode, $venue, $course, $target);
                }
            }
        }
        return $keys;
    }

    /** @return array<string,array{cutoff_timestamp:string,deadline_source:string}> */
    private function loadDeadlineMetadata(string $date): array
    {
        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/boatrace_official_deadlines/deadlines_' . str_replace('-', '', $date) . '.json';
        $data = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;
        if (!is_array($data) || !is_array($data['deadlines'] ?? null)) {
            return [];
        }
        $source = trim((string)($data['source'] ?? 'BOATRACE_OFFICIAL_DEADLINE_CACHE'));
        $result = [];
        foreach ($data['deadlines'] as $raceCode => $time) {
            $raceCode = strtoupper(trim((string)$raceCode));
            if (!preg_match('/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $raceCode) || !is_string($time) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
                continue;
            }
            $cutoff = DateTimeImmutable::createFromFormat('!Ymd H:i', substr($raceCode, 0, 8) . ' ' . $time, new DateTimeZone('Asia/Tokyo'));
            if ($cutoff !== false) {
                $result[$raceCode] = ['cutoff_timestamp' => $cutoff->format(DATE_ATOM), 'deadline_source' => $source];
            }
        }
        return $result;
    }

    /** @return array{status:string,records:int,duplicates:int,conflicts:int,reason:string} */
    private function operationalResult(string $date, string $status, string $reason): array
    {
        $this->appendOperational($date, ['event' => 'capture_skipped', 'status' => $status, 'reason' => $reason]);
        return ['status' => $status, 'records' => 0, 'duplicates' => 0, 'conflicts' => 0, 'reason' => $reason];
    }

    /** A closure racing a writer must suppress even diagnostic ledger appends. */
    private function measurementWriteStillAllowed(): bool
    {
        if ($this->windowContext === null) return true;
        try {
            $resolved = $this->windowContext->resolve();
            if (!$resolved['accepted']) return false;
            return (bool)($this->windowContext->lifecycle()->authorizeWrite((string)$resolved['window_id'], $resolved['manifest'])['accepted'] ?? false);
        }
        catch (Throwable) { return false; }
    }

    /** @param array<string,mixed> $event */
    private function appendOperational(string $date, array $event): void
    {
        if (!$this->measurementWriteStillAllowed()) return;
        try {
            $date = $this->validDate($date) ? str_replace('-', '', $date) : date('Ymd');
            $directory = $this->rootPath . '/operational';
            if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) return;
            $event['timestamp'] = $this->now();
            $json = $this->canonicalJson($event);
            $handle = @fopen($directory . '/' . $date . '.jsonl', 'ab');
            if (!is_resource($handle)) return;
            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                @fwrite($handle, $json . "\n");
                @flock($handle, LOCK_UN);
            }
            @fclose($handle);
        } catch (Throwable) {
            // Fail open even if the diagnostic path itself is unavailable.
        }
    }

    /**
     * Failure evidence is emitted both to a separate audit file and PHP's
     * normal error log.  If the ledger filesystem is unavailable, the latter
     * remains an independent observation path.
     *
     * @param array<string,mixed> $event
     */
    private function appendFailureAudit(string $date, array $event): void
    {
        if (!$this->measurementWriteStillAllowed()) return;
        $event['timestamp'] = $this->now();
        $event['epoch_status'] = self::EPOCH_STATUS;
        $event['audit_channel'] = 'strict_forward_capture_failure';
        $compact = $this->safeAuditText($event);
        if ($this->emitErrorLog) {
            error_log('strict forward capture audit: ' . $compact);
        }
        try {
            $date = $this->validDate($date) ? str_replace('-', '', $date) : date('Ymd');
            $directory = $this->rootPath . '/failures';
            if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) return;
            $handle = @fopen($directory . '/' . $date . '.jsonl', 'ab');
            if (!is_resource($handle)) return;
            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                @fwrite($handle, $this->canonicalJson($event) . "\n");
                @flock($handle, LOCK_UN);
            }
            @fclose($handle);
        } catch (Throwable) {
            // error_log above is intentionally independent from this path.
        }
    }

    /** @param array<string,mixed> $event */
    private function safeAuditText(array $event): string
    {
        $text = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($text) ? substr($text, 0, 2000) : 'audit_serialization_failed';
    }

    private function canonicalJson(mixed $value): string
    {
        $normalized = $this->canonicalize($value);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if (!is_string($json)) throw new RuntimeException('ledger_serialization_failed');
        return $json;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        return $value;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM);
    }

    private function isBeforeCutoff(string $prediction, string $cutoff): bool
    {
        try {
            return new DateTimeImmutable($prediction) < new DateTimeImmutable($cutoff);
        } catch (Throwable) {
            return false;
        }
    }

    private function isTimestampWithOffset(string $value): bool
    {
        if (!preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $value)) return false;
        try {
            new DateTimeImmutable($value);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function validDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    private function dateFromRace(string $raceCode): string
    {
        return $this->normalizeDateFromRace(substr($raceCode, 0, 8));
    }

    private function normalizeDateFromRace(string $value): string
    {
        if (preg_match('/^\d{8}$/', $value)) return substr($value, 0, 4) . '-' . substr($value, 4, 2) . '-' . substr($value, 6, 2);
        return date('Y-m-d');
    }

    private function safeErrorCode(Throwable $error): string
    {
        $message = strtolower($error->getMessage());
        return str_contains($message, 'after_cutoff') ? 'AFTER_CUTOFF'
            : (str_contains($message, 'timezone') ? 'TIMEZONE_INVALID'
            : (str_contains($message, 'traditional') ? 'TRADITIONAL_DECISION_UNAVAILABLE' : 'WRITE_ERROR'));
    }
}
