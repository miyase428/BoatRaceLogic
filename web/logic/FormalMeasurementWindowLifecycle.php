<?php

declare(strict_types=1);

require_once __DIR__ . '/FormalEvidenceAggregate.php';
require_once __DIR__ . '/OfficialEpochStartStore.php';
require_once __DIR__ . '/OfficialEpochRecoveryLifecycleV1.php';
require_once __DIR__ . '/TechnicalIncompleteEvidenceAttestation.php';

/**
 * Lifecycle v2 state kept outside a measurement Window.
 *
 * A closure never mutates the Window evidence it closes.  The record is
 * create-only and its payload binds the terminal evidence hash, declared and
 * activation identities, and the pointer identity that selected the Window.
 */
final class FormalMeasurementWindowLifecycle
{
    public const CLOSURE_SCHEMA_VERSION = 'formal-measurement-window-closure/v2';
    public const IDENTITY_SEPARATED_CLOSURE_SCHEMA_VERSION = 'formal-measurement-window-closure/v3';
    public const ABORT_SCHEMA_VERSION = 'formal-measurement-window-abort/v1';
    public const TECHNICAL_INCOMPLETE_ABORT_SCHEMA_VERSION = 'formal-measurement-window-abort/v2';
    public const ABORT_REASON_A1_EXPECTED_INVENTORY_BOOTSTRAP_INVALID = 'A1_EXPECTED_INVENTORY_BOOTSTRAP_INVALID';
    public const ABORT_REASON_A1_DEADLINE_SNAPSHOT_SEMANTIC_CONFLICT = 'A1_DEADLINE_SNAPSHOT_SEMANTIC_CONFLICT';
    public const ABORT_REASON_COMPARATIVE_RACE_LOCAL_REJECT_BATCH_ABORT_BUG = 'COMPARATIVE_RACE_LOCAL_REJECT_BATCH_ABORT_BUG';
    public const ABORT_REASON_FORMAL_EVIDENCE_INCOMPLETE_PRODUCER_DEFECT = 'FORMAL_EVIDENCE_INCOMPLETE_PRODUCER_DEFECT';
    public const TECHNICAL_INCOMPLETE_RECOVERY_STRATEGY = 'FREEZE_CURRENT_AND_START_CLEAN_WINDOW';
    public const TECHNICAL_INCOMPLETE_EVIDENCE_RETENTION_POLICY = 'RETAIN_DIAGNOSTIC_NATURAL_EVIDENCE_NO_SUCCESSOR_COPY';
    public const POINTER_TRANSITION_SCHEMA_VERSION = 'formal-measurement-pointer-transition/v2';
    public const BOOTSTRAP_AUTHORIZATION_VERSION = 'bootstrap-close-authorization/v1';

    public function __construct(private readonly string $repoRoot)
    {
    }

    public function closurePath(string $windowId): string
    {
        return $this->repoRoot . '/var/strict_forward_live_readiness/lifecycle/closures/' . rawurlencode($windowId) . '.json';
    }

    /** @return array<string,mixed> */
    public function readClosure(string $windowId): array
    {
        $path = $this->closurePath($windowId);
        if (!is_file($path)) return [];
        try {
            $row = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }
        return is_array($row) ? $row : [];
    }

    public function abortPath(string $windowId): string
    {
        return $this->repoRoot . '/var/strict_forward_live_readiness/lifecycle/aborts/' . rawurlencode($windowId) . '.json';
    }

    /** @return array<string,mixed> */
    public function readAbort(string $windowId): array
    {
        $path = $this->abortPath($windowId);
        if (!is_file($path)) return [];
        try { $row = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); }
        catch (Throwable) { return []; }
        return is_array($row) ? $row : [];
    }

    /** @return array{effective_state:string,closure:array<string,mixed>} */
    public function effectiveState(string $windowId, array $manifest): array
    {
        $abort = $this->readAbort($windowId);
        if ($this->validAbort($abort, $windowId)) return ['effective_state' => 'ABORTED', 'closure' => [], 'abort' => $abort];
        $closure = $this->readClosure($windowId);
        if ($this->validClosure($closure, $windowId)) return ['effective_state' => 'CLOSED', 'closure' => $closure];
        if (($manifest['activation_state'] ?? '') === 'PREPARED' && ($manifest['status'] ?? '') === 'PREPARED') {
            return ['effective_state' => 'PREPARED', 'closure' => []];
        }
        if (($manifest['activation_state'] ?? '') === 'ACTIVE' && ($manifest['status'] ?? '') === 'ACTIVE') {
            return ['effective_state' => 'ACTIVE', 'closure' => []];
        }
        return ['effective_state' => 'INVALID', 'closure' => [], 'abort' => []];
    }

    /**
     * Creates an immutable external ABORT record. It never mutates the bad
     * Window's manifest or measurement evidence; the declared and activation
     * identities are deliberately preserved even when the closing tool has a
     * newer identity.
     *
     * @param array<string,mixed> $input
     * @return array{accepted:bool,status:string,reason:string,abort:array<string,mixed>}
     */
    public function createAbort(array $input): array
    {
        foreach (['window_id','armed_at','aborted_at','capture_through_date','abort_reason','failure_code','final_evidence_hash','verified_final_evidence_hash','declared_implementation_identity','activation_implementation_identity','comparative_policy_identity','previous_pointer_identity','closing_tool_implementation_identity'] as $field) {
            if (!is_string($input[$field] ?? null) || trim((string)$input[$field]) === '') return $this->abortBlocked('ABORT_FIELD_MISSING_' . strtoupper($field));
        }
        $windowId = (string)$input['window_id'];
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $windowId)
            || !preg_match('/^sha256:[a-f0-9]{64}$/', (string)$input['final_evidence_hash'])
            || !hash_equals((string)$input['final_evidence_hash'], (string)$input['verified_final_evidence_hash'])
            || !self::validAbortReason((string)$input['abort_reason'])) {
            return $this->abortBlocked('ABORT_INPUT_INVALID');
        }
        $existing = $this->readAbort($windowId);
        if ($existing !== []) {
            if ($this->sameAbortIntent($existing, $input)) return ['accepted' => true, 'status' => 'ABORT_ALREADY_EXISTS', 'reason' => '', 'abort' => $existing];
            return $this->abortBlocked('BLOCKED_ABORT_CONFLICT', $existing);
        }
        try {
            $context = FormalMeasurementWindowContext::production($this->repoRoot);
            $resolved = $context->resolve();
            $manifest = $resolved['manifest'] ?? [];
            $pointerBytes = @file_get_contents($this->repoRoot . '/' . FormalMeasurementWindowContext::POINTER_RELATIVE_PATH);
            if (!$resolved['accepted'] || !hash_equals($windowId, (string)($resolved['window_id'] ?? ''))
                || ($manifest['activation_state'] ?? '') !== 'ACTIVE' || ($manifest['status'] ?? '') !== 'ACTIVE'
                || !is_string($pointerBytes) || !hash_equals((string)$input['previous_pointer_identity'], 'sha256:' . hash('sha256', $pointerBytes))) {
                return $this->abortBlocked('ABORT_CONTEXT_OR_POINTER_UNVERIFIED');
            }
            $declaration = $context->declaration()->read();
            $activation = $context->activationHold()->readActivation();
            $declared = (string)($declaration['declaration']['implementation_identity'] ?? '');
            $activationIdentity = (string)($activation['implementation_identity'] ?? '');
            if (!$declaration['accepted'] || !hash_equals($declared, (string)$input['declared_implementation_identity'])
                || !hash_equals($activationIdentity, (string)$input['activation_implementation_identity'])
                || !hash_equals($declared, $activationIdentity)) return $this->abortBlocked('ABORT_DECLARED_ACTIVATION_IDENTITY_MISMATCH');
            $aggregate = FormalEvidenceAggregate::calculate(dirname($context->windowManifestPath()), $windowId, (string)$input['capture_through_date']);
            if (!hash_equals((string)$aggregate['aggregate_sha256'], (string)$input['final_evidence_hash'])) return $this->abortBlocked('ABORT_EVIDENCE_AGGREGATE_MISMATCH');
        } catch (Throwable $error) { return $this->abortBlocked('ABORT_CONTEXT_UNAVAILABLE:' . $error->getMessage()); }
        $abort = [
            'schema_version' => self::ABORT_SCHEMA_VERSION, 'window_id' => $windowId,
            'armed_at' => (string)$input['armed_at'], 'aborted_at' => (string)$input['aborted_at'],
            'capture_through_date' => (string)$input['capture_through_date'],
            'reason' => (string)$input['abort_reason'],
            'abort_reason' => (string)$input['abort_reason'],
            'failure_code' => (string)$input['failure_code'], 'failure_details' => (string)($input['failure_details'] ?? ''),
            'gate_a1' => (string)($input['gate_a1'] ?? 'BLOCKED_EXPECTED_INVENTORY_MISSING'),
            'gate_a2' => (string)($input['gate_a2'] ?? 'FAIL'), 'gate_b_final' => (string)($input['gate_b_final'] ?? 'PENDING_LIVE'),
            'gate_c' => (string)($input['gate_c'] ?? 'BLOCKED_A1'),
            'candidate_count' => (int)($input['candidate_count'] ?? 0), 'expected_count' => (int)($input['expected_count'] ?? 0),
            'terminal_candidate_count' => (int)($input['terminal_candidate_count'] ?? 0),
            'source_failure_count' => (int)($input['source_failure_count'] ?? 0), 'backfill_count' => (int)($input['backfill_count'] ?? 0),
            'prearmed_sample_count' => (int)($input['prearmed_sample_count'] ?? 0),
            'formal_evidence_aggregate_sha256' => (string)$input['final_evidence_hash'],
            'final_evidence_hash' => (string)$input['final_evidence_hash'],
            'declared_implementation_identity' => (string)$input['declared_implementation_identity'],
            'activation_implementation_identity' => (string)$input['activation_implementation_identity'],
            'comparative_policy_identity' => (string)$input['comparative_policy_identity'],
            'previous_pointer_identity' => (string)$input['previous_pointer_identity'],
            'closing_tool_implementation_identity' => (string)$input['closing_tool_implementation_identity'],
            'operator_tool_provenance' => (string)($input['operator_tool_provenance'] ?? ''),
            'official_epoch' => 'NO',
        ];
        $abort['abort_record_hash'] = 'sha256:' . hash('sha256', $this->canonicalJson($abort));
        if (!$this->writeCreateOnly($this->abortPath($windowId), $this->canonicalJson($abort) . "\n")) return $this->abortBlocked('ABORT_CREATE_FAILED');
        return ['accepted' => true, 'status' => 'ABORT_CREATED', 'reason' => '', 'abort' => $abort];
    }

    /**
     * Builds the proposed Official-Epoch technical-incomplete abort record.
     * This function is pure: it neither resolves the production pointer nor
     * creates an external lifecycle file.
     *
     * @param array<string,mixed> $input
     * @return array{accepted:bool,status:string,reason:string,abort:array<string,mixed>}
     */
    public function technicalIncompleteAbortV2Record(array $input): array
    {
        $required = [
            'window_id', 'epoch_id', 'armed_at', 'aborted_at', 'capture_through_date', 'window_start_date',
            'incident_id', 'incident_hash', 'affected_keyset_hash', 'final_evidence_hash',
            'verified_final_evidence_hash', 'old_implementation_identity', 'activation_implementation_identity',
            'previous_pointer_identity', 'closing_tool_implementation_identity',
        ];
        foreach ($required as $field) if (!is_string($input[$field] ?? null) || trim((string)$input[$field]) === '') return $this->abortBlocked('ABORT_V2_FIELD_MISSING_' . strtoupper($field));
        $races = $input['affected_race_codes'] ?? null;
        if (!is_array($races) || $races === []) return $this->abortBlocked('ABORT_V2_AFFECTED_RACES_INVALID');
        $normalized = [];
        foreach ($races as $race) {
            $race = strtoupper(trim((string)$race));
            if (preg_match('/^\d{8}[A-Z0-9]{3}\d{2}$/', $race) !== 1 || isset($normalized[$race])) return $this->abortBlocked('ABORT_V2_AFFECTED_RACES_INVALID');
            $normalized[$race] = true;
        }
        $races = array_keys($normalized); sort($races, SORT_STRING);
        $hashFields = ['incident_hash', 'affected_keyset_hash', 'final_evidence_hash', 'verified_final_evidence_hash', 'old_implementation_identity', 'activation_implementation_identity', 'previous_pointer_identity', 'closing_tool_implementation_identity'];
        foreach ($hashFields as $field) if (!self::validHash((string)$input[$field])) return $this->abortBlocked('ABORT_V2_HASH_INVALID_' . strtoupper($field));
        $attestationMode = array_key_exists('evidence_attestation_schema', $input)
            || array_key_exists('evidence_attestation_sha256', $input);
        if ($attestationMode) {
            foreach (['evidence_attestation_schema','evidence_attestation_sha256','missing_resolution_keyset_sha256','completeness_status'] as $field) {
                if (!is_string($input[$field] ?? null) || trim((string)$input[$field]) === '') return $this->abortBlocked('ABORT_V2_FIELD_MISSING_' . strtoupper($field));
            }
            if (($input['evidence_attestation_schema'] ?? '') !== TechnicalIncompleteEvidenceAttestation::SCHEMA_VERSION
                || ($input['completeness_status'] ?? '') !== TechnicalIncompleteEvidenceAttestation::COMPLETENESS_STATUS
                || !self::validHash((string)$input['evidence_attestation_sha256'])
                || !self::validHash((string)$input['missing_resolution_keyset_sha256'])
                || !hash_equals((string)$input['evidence_attestation_sha256'], (string)$input['final_evidence_hash'])) {
                return $this->abortBlocked('ABORT_V2_ATTESTATION_INPUT_INVALID');
            }
        }
        if (!preg_match('/^[A-Za-z0-9._-]+$/', (string)$input['window_id'])
            || !preg_match('/^[A-Za-z0-9._-]+$/', (string)$input['incident_id'])
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$input['capture_through_date'])
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$input['window_start_date'])
            || (string)$input['window_start_date'] > (string)$input['capture_through_date']
            || !hash_equals((string)$input['final_evidence_hash'], (string)$input['verified_final_evidence_hash'])
            || !empty($input['historical_repair_performed'])
            || ($input['recovery_strategy'] ?? self::TECHNICAL_INCOMPLETE_RECOVERY_STRATEGY) !== self::TECHNICAL_INCOMPLETE_RECOVERY_STRATEGY
            || ($input['evidence_retention_policy'] ?? self::TECHNICAL_INCOMPLETE_EVIDENCE_RETENTION_POLICY) !== self::TECHNICAL_INCOMPLETE_EVIDENCE_RETENTION_POLICY
            || empty($input['successor_required'])
            || ($input['repair_reconstructibility'] ?? 'PARTIAL') !== 'PARTIAL'
            || (int)($input['missing_resolution_count'] ?? -1) !== count($races)) {
            return $this->abortBlocked('ABORT_V2_INPUT_INVALID');
        }
        $abort = [
            'schema_version' => self::TECHNICAL_INCOMPLETE_ABORT_SCHEMA_VERSION,
            'window_id' => (string)$input['window_id'], 'epoch_id' => (string)$input['epoch_id'],
            'terminal_state' => 'ABORTED', 'armed_at' => (string)$input['armed_at'], 'aborted_at' => (string)$input['aborted_at'],
            'capture_through_date' => (string)$input['capture_through_date'], 'window_start_date' => (string)$input['window_start_date'],
            'reason' => self::ABORT_REASON_FORMAL_EVIDENCE_INCOMPLETE_PRODUCER_DEFECT,
            'abort_reason' => self::ABORT_REASON_FORMAL_EVIDENCE_INCOMPLETE_PRODUCER_DEFECT,
            'failure_code' => (string)($input['failure_code'] ?? 'LATE_ELIGIBILITY_PRODUCER_SCHEDULE_GAP'),
            'failure_details' => (string)($input['failure_details'] ?? ''),
            'incident_id' => (string)$input['incident_id'], 'incident_hash' => (string)$input['incident_hash'],
            'affected_race_codes' => $races, 'affected_keyset_hash' => (string)$input['affected_keyset_hash'],
            'missing_resolution_count' => count($races), 'repair_reconstructibility' => 'PARTIAL',
            'historical_repair_performed' => false,
            'recovery_strategy' => self::TECHNICAL_INCOMPLETE_RECOVERY_STRATEGY,
            'evidence_retention_policy' => self::TECHNICAL_INCOMPLETE_EVIDENCE_RETENTION_POLICY,
            'formal_evaluation_eligible' => false, 'production_adoption_eligible' => false,
            'successor_copy_permitted' => false, 'successor_required' => true,
            'evidence_basis' => $attestationMode ? 'TECHNICAL_INCOMPLETE_ATTESTATION' : 'COMPLETE_FORMAL_AGGREGATE',
            'formal_evidence_aggregate_sha256' => $attestationMode ? null : (string)$input['final_evidence_hash'],
            'final_evidence_hash' => (string)$input['final_evidence_hash'],
            'raw_evidence_sha256' => (string)($input['raw_evidence_sha256'] ?? ''),
            'canonical_membership_sha256' => (string)($input['canonical_membership_sha256'] ?? ''),
            'old_implementation_identity' => (string)$input['old_implementation_identity'],
            'declared_implementation_identity' => (string)$input['old_implementation_identity'],
            'activation_implementation_identity' => (string)$input['activation_implementation_identity'],
            'previous_pointer_identity' => (string)$input['previous_pointer_identity'],
            'closing_tool_implementation_identity' => (string)$input['closing_tool_implementation_identity'],
            'official_epoch' => 'YES',
        ];
        if ($attestationMode) {
            $abort['evidence_attestation_schema'] = (string)$input['evidence_attestation_schema'];
            $abort['evidence_attestation_sha256'] = (string)$input['evidence_attestation_sha256'];
            $abort['missing_resolution_keyset_sha256'] = (string)$input['missing_resolution_keyset_sha256'];
            $abort['completeness_status'] = TechnicalIncompleteEvidenceAttestation::COMPLETENESS_STATUS;
        }
        $abort['abort_record_hash'] = 'sha256:' . hash('sha256', $this->canonicalJson($abort));
        return ['accepted' => true, 'status' => 'ABORT_V2_ELIGIBLE', 'reason' => '', 'abort' => $abort];
    }

    /**
     * Create-only production commit for a v2 technical-incomplete abort.
     * It is not invoked by the implementation-gate phase.
     *
     * @param array<string,mixed> $input
     * @return array{accepted:bool,status:string,reason:string,abort:array<string,mixed>}
     */
    public function createTechnicalIncompleteAbortV2(array $input): array
    {
        $prepared = $this->technicalIncompleteAbortV2Record($input);
        if (!$prepared['accepted']) return $prepared;
        $abort = $prepared['abort']; $windowId = (string)$abort['window_id'];
        $existing = $this->readAbort($windowId);
        if ($existing !== []) {
            if ($this->validAbortV2($existing, $windowId) && hash_equals((string)$existing['abort_record_hash'], (string)$abort['abort_record_hash'])) return ['accepted' => true, 'status' => 'ABORT_V2_ALREADY_EXISTS', 'reason' => '', 'abort' => $existing];
            return $this->abortBlocked('BLOCKED_ABORT_CONFLICT', $existing);
        }
        try {
            $context = FormalMeasurementWindowContext::production($this->repoRoot);
            $resolved = $context->resolve(); $manifest = $resolved['manifest'] ?? [];
            $pointerBytes = @file_get_contents($this->repoRoot . '/' . FormalMeasurementWindowContext::POINTER_RELATIVE_PATH);
            if (!$resolved['accepted'] || !hash_equals($windowId, (string)($resolved['window_id'] ?? ''))
                || ($manifest['activation_state'] ?? '') !== 'ACTIVE' || ($manifest['status'] ?? '') !== 'ACTIVE'
                || !is_string($pointerBytes) || !hash_equals((string)$abort['previous_pointer_identity'], 'sha256:' . hash('sha256', $pointerBytes))) return $this->abortBlocked('ABORT_V2_CONTEXT_OR_POINTER_UNVERIFIED');
            $epoch = (new OfficialEpochStartStore($this->repoRoot . '/var/strict_forward_live_readiness/lifecycle'))->existing();
            if ($epoch === [] || !hash_equals((string)$abort['epoch_id'], (string)($epoch['epoch_id'] ?? ''))) return $this->abortBlocked('ABORT_V2_EPOCH_UNVERIFIED');
            $declaration = $context->declaration()->read(); $activation = $context->activationHold()->readActivation();
            $declared = (string)($declaration['declaration']['implementation_identity'] ?? '');
            $activated = (string)($activation['implementation_identity'] ?? '');
            if (!$declaration['accepted'] || !hash_equals($declared, (string)$abort['old_implementation_identity'])
                || !hash_equals($activated, (string)$abort['activation_implementation_identity']) || !hash_equals($declared, $activated)) return $this->abortBlocked('ABORT_V2_DECLARED_ACTIVATION_IDENTITY_MISMATCH');
            if (($abort['evidence_basis'] ?? '') === 'TECHNICAL_INCOMPLETE_ATTESTATION') {
                $store = new TechnicalIncompleteEvidenceAttestation($this->repoRoot);
                $attestation = $store->read($windowId);
                $rootCause = is_array($attestation['root_cause_binding'] ?? null) ? $attestation['root_cause_binding'] : [];
                $verifiedAttestation = [];
                if ($store->valid($attestation, $windowId)) {
                    $verifiedAttestation = $store->build(dirname($context->windowManifestPath()), [
                        'window_id' => (string)$attestation['window_id'],
                        'epoch_id' => (string)$attestation['epoch_id'],
                        'attested_at' => (string)$attestation['attested_at'],
                        'implementation_identity' => (string)$attestation['implementation_identity'],
                        'window_start_date' => (string)$attestation['window_start_date'],
                        'capture_through_date' => (string)$attestation['capture_through_date'],
                        'required_inventory_dates' => $attestation['required_inventory_dates'] ?? [],
                        'boundary_contract_path' => (string)$attestation['boundary_contract_path'],
                        'boundary_contract_sha256' => (string)$attestation['boundary_contract_sha256'],
                        'incident_id' => (string)($rootCause['incident_id'] ?? ''),
                        'incident_hash' => (string)($rootCause['incident_hash'] ?? ''),
                        'incident_type' => (string)($rootCause['incident_type'] ?? ''),
                        'severity' => (string)($rootCause['severity'] ?? ''),
                        'repair_reconstructibility' => (string)($rootCause['repair_reconstructibility'] ?? ''),
                        'recovery_strategy' => (string)($rootCause['recovery_strategy'] ?? ''),
                        'historical_repair_performed' => (bool)($rootCause['historical_repair_performed'] ?? true),
                        'missing_resolution_keys' => $attestation['missing_resolution_keys'] ?? [],
                        'missing_resolution_race_codes' => $attestation['missing_resolution_race_codes'] ?? [],
                    ]);
                }
                if (!$store->valid($attestation, $windowId)
                    || !hash_equals((string)($attestation['attestation_sha256'] ?? ''), (string)($verifiedAttestation['attestation_sha256'] ?? ''))
                    || !hash_equals((string)$abort['evidence_attestation_sha256'], (string)($attestation['attestation_sha256'] ?? ''))
                    || !hash_equals((string)$abort['final_evidence_hash'], (string)($attestation['attestation_sha256'] ?? ''))
                    || !hash_equals((string)$abort['missing_resolution_keyset_sha256'], (string)($attestation['missing_resolution_keyset_sha256'] ?? ''))
                    || (int)$abort['missing_resolution_count'] !== (int)($attestation['missing_resolution_count'] ?? -1)
                    || !hash_equals((string)$abort['incident_id'], (string)($rootCause['incident_id'] ?? ''))
                    || !hash_equals((string)$abort['incident_hash'], (string)($rootCause['incident_hash'] ?? ''))
                    || !hash_equals((string)$abort['old_implementation_identity'], (string)($attestation['implementation_identity'] ?? ''))) {
                    return $this->abortBlocked('ABORT_V2_EVIDENCE_ATTESTATION_MISMATCH');
                }
            } else {
                $aggregate = FormalEvidenceAggregate::calculateMultiDay(dirname($context->windowManifestPath()), $windowId, (string)$abort['window_start_date'], (string)$abort['capture_through_date']);
                if (!hash_equals((string)$aggregate['aggregate_sha256'], (string)$abort['final_evidence_hash'])) return $this->abortBlocked('ABORT_V2_EVIDENCE_AGGREGATE_MISMATCH');
            }
            $incidentPath = $this->repoRoot . '/var/strict_forward_live_readiness/lifecycle/official_epoch_incidents/' . (string)$abort['incident_id'] . '.json';
            $incident = json_decode((string)@file_get_contents($incidentPath), true);
            if (!is_array($incident) || !hash_equals((string)$abort['incident_hash'], (string)($incident['record_hash'] ?? ''))
                || ($incident['incident_type'] ?? '') !== 'SCHEDULER_FAILURE_MISSED_OPPORTUNITY' || ($incident['severity'] ?? '') !== 'MAJOR') return $this->abortBlocked('ABORT_V2_INCIDENT_UNVERIFIED');
        } catch (Throwable $error) { return $this->abortBlocked('ABORT_V2_CONTEXT_UNAVAILABLE:' . $error->getMessage()); }
        if (!$this->writeCreateOnly($this->abortPath($windowId), $this->canonicalJson($abort) . "\n")) return $this->abortBlocked('ABORT_V2_CREATE_FAILED');
        return ['accepted' => true, 'status' => 'ABORT_V2_CREATED', 'reason' => '', 'abort' => $abort];
    }

    /**
     * Create-only terminal declaration. This class intentionally has no
     * operation that changes a manifest, ledger, candidate, or pointer.
     *
     * @param array<string,mixed> $input
     * @return array{accepted:bool,status:string,reason:string,closure:array<string,mixed>}
     */
    public function createClosure(array $input): array
    {
        $required = ['window_id', 'armed_at', 'closed_at', 'capture_through_date', 'final_evidence_hash', 'verified_final_evidence_hash', 'closure_reason',
            'declared_implementation_identity', 'activation_implementation_identity', 'comparative_policy_identity',
            'previous_pointer_identity', 'closing_tool_implementation_identity', 'official_epoch'];
        foreach ($required as $field) {
            if (!is_string($input[$field] ?? null) || trim((string)$input[$field]) === '') return $this->blocked('CLOSURE_FIELD_MISSING_' . strtoupper($field));
        }
        $windowId = (string)$input['window_id'];
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $windowId)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$input['capture_through_date'])
            || !preg_match('/^sha256:[a-f0-9]{64}$/', (string)$input['final_evidence_hash'])) {
            return $this->blocked('CLOSURE_INPUT_INVALID');
        }
        if (($input['declared_implementation_identity'] ?? '') !== ($input['activation_implementation_identity'] ?? '')) {
            return $this->blocked('CLOSURE_IMPLEMENTATION_IDENTITY_MISMATCH');
        }
        if (!hash_equals((string)$input['final_evidence_hash'], (string)$input['verified_final_evidence_hash'])) return $this->blocked('FINAL_EVIDENCE_HASH_MISMATCH');
        if ((int)($input['candidate_count'] ?? -1) !== (int)($input['terminal_candidate_count'] ?? -2)) return $this->blocked('TERMINAL_CANDIDATE_COUNT_INCOMPLETE');
        if (($input['official_epoch'] ?? '') !== 'NO') return $this->blocked('OFFICIAL_EPOCH_NOT_NO');
        $closure = [
            'schema_version' => self::CLOSURE_SCHEMA_VERSION,
            'window_id' => $windowId,
            'armed_at' => (string)$input['armed_at'],
            'closed_at' => (string)$input['closed_at'],
            'capture_through_date' => (string)$input['capture_through_date'],
            'final_evidence_hash' => (string)$input['final_evidence_hash'],
            'reason' => (string)$input['closure_reason'],
            'closure_reason' => (string)$input['closure_reason'],
            'gate_a1' => (string)($input['gate_a1'] ?? ''),
            'gate_a2' => (string)($input['gate_a2'] ?? ''),
            'gate_b_final' => (string)($input['gate_b_final'] ?? ''),
            'gate_c' => (string)($input['gate_c'] ?? ''),
            'candidate_count' => (int)($input['candidate_count'] ?? 0),
            'terminal_candidate_count' => (int)($input['terminal_candidate_count'] ?? 0),
            'eligible_count' => (int)($input['eligible_count'] ?? $input['eligible_race_count'] ?? 0),
            'accepted_count' => (int)($input['accepted_count'] ?? $input['accepted_race_count'] ?? 0),
            'unresolved_count' => (int)($input['unresolved_count'] ?? 0),
            'declared_implementation_identity' => (string)$input['declared_implementation_identity'],
            'activation_implementation_identity' => (string)$input['activation_implementation_identity'],
            'comparative_policy_identity' => (string)$input['comparative_policy_identity'],
            'previous_pointer_identity' => (string)$input['previous_pointer_identity'],
            'closing_tool_implementation_identity' => (string)$input['closing_tool_implementation_identity'],
            'official_epoch' => 'NO',
        ];
        foreach (['gate_a1', 'gate_a2', 'gate_b_final', 'gate_c'] as $gate) {
            if ($closure[$gate] !== 'PASS') return $this->blocked('CLOSURE_GATE_NOT_PASS_' . strtoupper($gate));
        }
        if ($closure['unresolved_count'] !== 0) return $this->blocked('CLOSURE_UNRESOLVED_EVIDENCE');
        // A closure makes the Window terminal. Check the immutable intent before
        // current-write authorization, which correctly refuses terminal Windows.
        $existing = $this->readClosure($windowId);
        if ($existing !== []) {
            if ($this->sameClosureIntent($existing, $closure)) return ['accepted' => true, 'status' => 'CLOSURE_ALREADY_EXISTS', 'reason' => '', 'closure' => $existing];
            return $this->blocked('BLOCKED_CLOSURE_CONFLICT', $existing);
        }
        try {
            $current = FormalMeasurementWindowContext::production($this->repoRoot);
            $authorization = $current->authorizeFormalWrite();
            $pointerBytes = @file_get_contents($this->repoRoot . '/' . FormalMeasurementWindowContext::POINTER_RELATIVE_PATH);
            if (!$authorization['accepted'] || !is_string($pointerBytes)
                || !hash_equals($windowId, (string)($authorization['window_id'] ?? ''))
                || !hash_equals((string)$input['previous_pointer_identity'], 'sha256:' . hash('sha256', $pointerBytes))
                || !hash_equals((string)$input['declared_implementation_identity'], (string)($authorization['implementation']['implementation_identity'] ?? ''))
                || !hash_equals((string)$input['activation_implementation_identity'], (string)($authorization['activation']['implementation_identity'] ?? ''))) {
                return $this->blocked('CLOSURE_CONTEXT_OR_POINTER_UNVERIFIED');
            }
        } catch (Throwable) {
            return $this->blocked('CLOSURE_CONTEXT_OR_POINTER_UNAVAILABLE');
        }
        return $this->persistClosure($closure);
    }

    /**
     * Narrow migration-only authorization. It deliberately does not call
     * authorizeFormalWrite(): a legacy Window's frozen measurement identity is
     * expected to differ from the newer lifecycle closing-tool identity.
     *
     * @param array<string,mixed> $input
     * @return array{accepted:bool,status:string,reason:string,authorization:array<string,mixed>,aggregate:array<string,mixed>}
     */
    public function authorizeBootstrapClosure(array $input): array
    {
        $empty = ['accepted' => false, 'status' => 'BOOTSTRAP_CLOSE_BLOCKED', 'reason' => '', 'authorization' => [], 'aggregate' => []];
        $windowId = trim((string)($input['window_id'] ?? ''));
        $through = trim((string)($input['capture_through_date'] ?? ''));
        if ($windowId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $through)) { $empty['reason'] = 'BOOTSTRAP_INPUT_INVALID'; return $empty; }
        if (empty($input['scheduler_maintenance_hold_confirmed'])) { $empty['reason'] = 'SCHEDULER_MAINTENANCE_HOLD_UNVERIFIED'; return $empty; }
        try {
            $context = FormalMeasurementWindowContext::production($this->repoRoot);
            $resolved = $context->resolve();
            $manifest = $resolved['manifest'] ?? [];
            if (!$resolved['accepted'] || !hash_equals($windowId, (string)$resolved['window_id'])) { $empty['reason'] = 'POINTER_WINDOW_MISMATCH'; return $empty; }
            if ($this->readClosure($windowId) !== []) { $empty['reason'] = 'CLOSURE_ALREADY_EXISTS'; return $empty; }
            if (($manifest['activation_state'] ?? '') !== 'ACTIVE' || ($manifest['status'] ?? '') !== 'ACTIVE') { $empty['reason'] = 'BOOTSTRAP_WINDOW_NOT_ACTIVE'; return $empty; }
            if (($manifest['official_epoch_started'] ?? '') !== 'NO') { $empty['reason'] = 'OFFICIAL_EPOCH_NOT_NO'; return $empty; }
            $declared = $context->declaration()->read();
            $activation = $context->activationHold()->readActivation();
            $declaredIdentity = (string)($declared['declaration']['implementation_identity'] ?? '');
            $activationIdentity = (string)($activation['implementation_identity'] ?? '');
            if (!$declared['accepted'] || $declaredIdentity === '' || !hash_equals($declaredIdentity, $activationIdentity)) { $empty['reason'] = 'DECLARED_ACTIVATION_IDENTITY_MISMATCH'; return $empty; }
            if (!$this->verifiedLegacyWindow($context, $activation, $declared['declaration'])) { $empty['reason'] = 'BLOCKED_NOT_VERIFIED_LEGACY_WINDOW'; return $empty; }
            $currentIdentity = (new MeasurementImplementationProvenance($this->repoRoot, $context->declaration()->provenanceStorageRoot()))->currentIdentityReadOnly();
            $aggregate = FormalEvidenceAggregate::calculate(dirname($context->windowManifestPath()), $windowId, $through);
            if ((string)($input['formal_evidence_aggregate_sha256'] ?? '') !== (string)$aggregate['aggregate_sha256']) { $empty['reason'] = 'FORMAL_EVIDENCE_AGGREGATE_MISMATCH'; return $empty; }
            foreach (['gate_a1', 'gate_a2', 'gate_b_final', 'gate_c'] as $gate) if (($input[$gate] ?? '') !== 'PASS') { $empty['reason'] = 'BOOTSTRAP_GATE_NOT_PASS_' . strtoupper($gate); return $empty; }
            if ((int)($input['candidate_count'] ?? -1) !== (int)($input['terminal_candidate_count'] ?? -2)) { $empty['reason'] = 'TERMINAL_CANDIDATE_COUNT_INCOMPLETE'; return $empty; }
            if ((int)($input['unresolved_count'] ?? -1) !== 0) { $empty['reason'] = 'BOOTSTRAP_UNRESOLVED_EVIDENCE'; return $empty; }
            $pointerBytes = @file_get_contents($this->repoRoot . '/' . FormalMeasurementWindowContext::POINTER_RELATIVE_PATH);
            if (!is_string($pointerBytes)) { $empty['reason'] = 'POINTER_UNAVAILABLE'; return $empty; }
            return [
                'accepted' => true, 'status' => 'BOOTSTRAP_CLOSE_AUTHORIZED', 'reason' => '',
                'authorization' => [
                    'bootstrap_authorization_version' => self::BOOTSTRAP_AUTHORIZATION_VERSION,
                    'legacy_window_verified' => true, 'window_id' => $windowId,
                    'declared_implementation_identity' => $declaredIdentity,
                    'activation_implementation_identity' => $activationIdentity,
                    'closing_tool_implementation_identity' => $currentIdentity,
                    'previous_pointer_identity' => 'sha256:' . hash('sha256', $pointerBytes),
                ], 'aggregate' => $aggregate,
            ];
        } catch (Throwable $error) { $empty['reason'] = 'BOOTSTRAP_CONTEXT_UNAVAILABLE:' . $error->getMessage(); return $empty; }
    }

    /** @param array<string,mixed> $input @return array{accepted:bool,status:string,reason:string,closure:array<string,mixed>} */
    public function createBootstrapClosure(array $input): array
    {
        $existing = $this->readClosure((string)($input['window_id'] ?? ''));
        if ($existing !== []) {
            if (($existing['reason'] ?? '') === 'NORMAL_CAPTURE_COMPLETE'
                && ($existing['capture_through_date'] ?? '') === ($input['capture_through_date'] ?? '')
                && ($existing['formal_evidence_aggregate_sha256'] ?? '') === ($input['formal_evidence_aggregate_sha256'] ?? '')) {
                return ['accepted' => true, 'status' => 'CLOSURE_ALREADY_EXISTS', 'reason' => '', 'closure' => $existing];
            }
            return $this->blocked('BLOCKED_CLOSURE_CONFLICT', $existing);
        }
        $authorization = $this->authorizeBootstrapClosure($input);
        if (!$authorization['accepted']) return $this->blocked((string)$authorization['reason']);
        $auth = $authorization['authorization'];
        $aggregate = $authorization['aggregate'];
        $closure = [
            'schema_version' => self::CLOSURE_SCHEMA_VERSION,
            'window_id' => (string)$auth['window_id'],
            'armed_at' => (string)($input['armed_at'] ?? ''),
            'closed_at' => (string)($input['closed_at'] ?? ''),
            'capture_through_date' => (string)$input['capture_through_date'],
            'reason' => 'NORMAL_CAPTURE_COMPLETE', 'closure_reason' => 'NORMAL_CAPTURE_COMPLETE',
            'declared_implementation_identity' => (string)$auth['declared_implementation_identity'],
            'activation_implementation_identity' => (string)$auth['activation_implementation_identity'],
            'closing_tool_implementation_identity' => (string)$auth['closing_tool_implementation_identity'],
            'bootstrap_authorization_version' => (string)$auth['bootstrap_authorization_version'],
            'legacy_window_verified' => true,
            'formal_evidence_aggregate_version' => (string)$aggregate['schema_version'],
            'formal_evidence_aggregate_sha256' => (string)$aggregate['aggregate_sha256'],
            'formal_evidence_file_count' => (int)$aggregate['file_count'],
            'final_evidence_hash' => (string)$aggregate['aggregate_sha256'],
            'candidate_count' => (int)$input['candidate_count'],
            'terminal_candidate_count' => (int)$input['terminal_candidate_count'],
            'eligible_count' => (int)($input['eligible_count'] ?? 0),
            'accepted_count' => (int)($input['accepted_count'] ?? 0),
            'unresolved_count' => (int)$input['unresolved_count'],
            'gate_a1' => (string)$input['gate_a1'], 'gate_a2' => (string)$input['gate_a2'],
            'gate_b_final' => (string)$input['gate_b_final'], 'gate_c' => (string)$input['gate_c'],
            'comparative_policy_identity' => (string)($input['comparative_policy_identity'] ?? 'NOT_APPLICABLE_BOOTSTRAP'),
            'previous_pointer_identity' => (string)$auth['previous_pointer_identity'],
            'official_epoch' => 'NO',
        ];
        if ($closure['armed_at'] === '' || $closure['closed_at'] === '') return $this->blocked('BOOTSTRAP_CLOSE_TIMESTAMP_MISSING');
        return $this->persistClosure($closure);
    }

    /** @return array{accepted:bool,status:string,reason:string} */
    public function authorizeWrite(string $windowId, array $manifest): array
    {
        if ((new OfficialEpochRecoveryLifecycleV1($this->repoRoot, $this->repoRoot . '/var/strict_forward_live_readiness/lifecycle'))->supersessionForWindow($windowId) !== []) {
            return ['accepted' => false, 'status' => 'WINDOW_WRITE_BLOCKED', 'reason' => 'WINDOW_PREPARED_SUPERSEDED'];
        }
        $state = $this->effectiveState($windowId, $manifest)['effective_state'];
        if ($state === 'ABORTED') return ['accepted' => false, 'status' => 'WINDOW_WRITE_BLOCKED', 'reason' => 'WINDOW_ABORTED'];
        if ($state === 'CLOSED') return ['accepted' => false, 'status' => 'WINDOW_WRITE_BLOCKED', 'reason' => 'WINDOW_CLOSED'];
        if ($state === 'PREPARED') return ['accepted' => false, 'status' => 'WINDOW_WRITE_BLOCKED', 'reason' => 'FORMAL_WRITE_NOT_ACTIVE'];
        if ($state !== 'ACTIVE') return ['accepted' => false, 'status' => 'WINDOW_WRITE_BLOCKED', 'reason' => 'WINDOW_STATE_INVALID'];
        return ['accepted' => true, 'status' => 'WINDOW_WRITE_STATE_ALLOWED', 'reason' => ''];
    }

    /** @return array{accepted:bool,status:string,reason:string,transition:array<string,mixed>} */
    public function handoffPointerCas(string $pointerPath, string $expectedOldWindowId, array $newPointer, string $actor): array
    {
        $lockPath = $pointerPath . '.lifecycle-v2.lock';
        $directory = dirname($lockPath);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) return $this->pointerBlocked('POINTER_LOCK_DIRECTORY_UNAVAILABLE');
        $lock = @fopen($lockPath, 'c');
        if (!is_resource($lock) || !@flock($lock, LOCK_EX)) return $this->pointerBlocked('POINTER_LOCK_UNAVAILABLE');
        try {
            $beforeBytes = @file_get_contents($pointerPath);
            $before = is_string($beforeBytes) ? json_decode($beforeBytes, true) : null;
            if (!is_array($before) || !hash_equals((string)($before['window_id'] ?? ''), $expectedOldWindowId)) {
                if (is_array($before) && hash_equals((string)($before['window_id'] ?? ''), (string)($newPointer['window_id'] ?? ''))) {
                    foreach (glob($this->repoRoot . '/var/strict_forward_live_readiness/lifecycle/pointer_transitions/applied/*.json') ?: [] as $path) {
                        try { $prior = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); } catch (Throwable) { $prior = null; }
                        if (is_array($prior) && ($prior['old_window_id'] ?? '') === $expectedOldWindowId
                            && ($prior['new_window_id'] ?? '') === ($newPointer['window_id'] ?? '')
                            && ($prior['pointer_after_hash'] ?? '') === ('sha256:' . hash('sha256', $this->canonicalJson($newPointer) . "\n"))) {
                            return ['accepted' => true, 'status' => 'POINTER_HANDOFF_ALREADY_APPLIED', 'reason' => '', 'transition' => $prior];
                        }
                    }
                    return $this->pointerBlocked('POINTER_ALREADY_MOVED_WITHOUT_VERIFIED_AUDIT');
                }
                return $this->pointerBlocked('BLOCKED_POINTER_CAS_CONFLICT');
            }
            $oldClosure = $this->readClosure($expectedOldWindowId);
            $oldAbort = $this->readAbort($expectedOldWindowId);
            if ((new OfficialEpochRecoveryLifecycleV1($this->repoRoot, $this->repoRoot . '/var/strict_forward_live_readiness/lifecycle'))->supersessionForWindow($expectedOldWindowId) !== []) return $this->pointerBlocked('OLD_WINDOW_PREPARED_SUPERSEDED');
            $oldTerminal = $this->validClosure($oldClosure, $expectedOldWindowId) ? $oldClosure : $oldAbort;
            $oldTerminalState = $this->validClosure($oldClosure, $expectedOldWindowId) ? 'CLOSED' : 'ABORTED';
            if ($oldTerminalState === 'ABORTED' && !$this->validAbort($oldAbort, $expectedOldWindowId)) return $this->pointerBlocked('OLD_WINDOW_TERMINAL_RECORD_MISSING');
            if ($oldTerminalState === 'CLOSED' && !$this->validClosure($oldClosure, $expectedOldWindowId)) return $this->pointerBlocked('OLD_WINDOW_TERMINAL_RECORD_MISSING');
            if (!is_string($newPointer['window_id'] ?? null) || !is_string($newPointer['window_root'] ?? null)
                || !FormalMeasurementWindowContext::validPointerPayload($newPointer)) return $this->pointerBlocked('NEW_POINTER_INVALID');
            $newContext = FormalMeasurementWindowContext::fromWindowRoot($this->repoRoot, $this->repoRoot . '/' . (string)$newPointer['window_root']);
            $newResolved = $newContext->resolve();
            $newState = $newContext->lifecycle()->effectiveState((string)($newResolved['window_id'] ?? ''), $newResolved['manifest'] ?? []);
            $newIdentity = $newContext->declaration()->assertCurrent();
            if (!$newResolved['accepted'] || !hash_equals((string)$newPointer['window_id'], (string)$newResolved['window_id'])
                || $newState['effective_state'] !== 'PREPARED' || !$newIdentity['accepted']) {
                return $this->pointerBlocked('NEW_WINDOW_PREPARED_IDENTITY_INVALID');
            }
            // Once an Official Epoch exists, only an externally bound and
            // still-ACTIVE successor may become the current pointer. Legacy
            // pre-Epoch pointer handoffs retain their historical behaviour.
            $epochStart = (new OfficialEpochStartStore($this->repoRoot . '/var/strict_forward_live_readiness/lifecycle'))->existing();
            if ($epochStart !== []) {
                require_once __DIR__ . '/OfficialEpochWindowBindingResolver.php';
                $binding = (new OfficialEpochWindowBindingResolver($this->repoRoot))->validateBinding((string)$newPointer['window_id'], ['require_prepared' => true]);
                if (empty($binding['accepted'])) return $this->pointerBlocked('OFFICIAL_EPOCH_WINDOW_BINDING_INVALID:' . (string)$binding['reason']);
            }
            $beforeHash = 'sha256:' . hash('sha256', $beforeBytes);
            $afterBytes = $this->canonicalJson($newPointer) . "\n";
            $afterHash = 'sha256:' . hash('sha256', $afterBytes);
            $transition = [
                'schema_version' => self::POINTER_TRANSITION_SCHEMA_VERSION,
                'old_window_id' => $expectedOldWindowId,
                'new_window_id' => (string)$newPointer['window_id'],
                'old_closure_record_hash' => (string)($oldClosure['closure_record_hash'] ?? ''),
                'old_terminal_state' => $oldTerminalState,
                'old_terminal_record_hash' => (string)($oldTerminal['closure_record_hash'] ?? $oldTerminal['abort_record_hash'] ?? ''),
                'new_declared_identity' => (string)($newIdentity['implementation']['implementation_identity'] ?? ''),
                'pointer_before_hash' => $beforeHash,
                'pointer_after_hash' => $afterHash,
                'actor' => $actor,
                'transition_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM),
            ];
            $transition['transition_hash'] = 'sha256:' . hash('sha256', $this->canonicalJson($transition));
            $intent = $this->repoRoot . '/var/strict_forward_live_readiness/lifecycle/pointer_transitions/intents/' . substr((string)$transition['transition_hash'], 7) . '.json';
            $applied = $this->repoRoot . '/var/strict_forward_live_readiness/lifecycle/pointer_transitions/applied/' . substr((string)$transition['transition_hash'], 7) . '.json';
            if (!is_file($intent) && !$this->writeCreateOnly($intent, $this->canonicalJson(array_merge($transition, ['phase' => 'PREPARED'])) . "\n")) return $this->pointerBlocked('POINTER_TRANSITION_INTENT_CREATE_FAILED');
            if (!$this->atomicReplace($pointerPath, $afterBytes)) return $this->pointerBlocked('POINTER_CAS_REPLACE_FAILED');
            if (!is_file($applied) && !$this->writeCreateOnly($applied, $this->canonicalJson(array_merge($transition, ['phase' => 'APPLIED'])) . "\n")) return $this->pointerBlocked('POINTER_TRANSITION_AUDIT_CREATE_FAILED');
            return ['accepted' => true, 'status' => 'POINTER_HANDOFF_APPLIED', 'reason' => '', 'transition' => $transition];
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    /**
     * Narrow, lifecycle-owned pointer CAS primitive.
     *
     * This deliberately performs no state authorization and creates no audit
     * records: callers must first establish their own immutable lifecycle
     * authorization.  It exists so recovery code never falls back to a
     * generic filesystem edit while the normal handoff guard above remains
     * unchanged and strict.
     *
     * @param array<string,mixed> $expectedPointer
     * @param array<string,mixed> $targetPointer
     * @return array{accepted:bool,status:string,reason:string,before_hash:string,after_hash:string}
     */
    public function compareAndSwapPointerPayload(string $pointerPath, array $expectedPointer, array $targetPointer): array
    {
        if (!FormalMeasurementWindowContext::validPointerPayload($expectedPointer)
            || !FormalMeasurementWindowContext::validPointerPayload($targetPointer)) {
            return ['accepted' => false, 'status' => 'POINTER_CAS_BLOCKED', 'reason' => 'POINTER_PAYLOAD_INVALID', 'before_hash' => '', 'after_hash' => ''];
        }
        $lockPath = $pointerPath . '.lifecycle-v2.lock';
        $directory = dirname($lockPath);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return ['accepted' => false, 'status' => 'POINTER_CAS_BLOCKED', 'reason' => 'POINTER_LOCK_DIRECTORY_UNAVAILABLE', 'before_hash' => '', 'after_hash' => ''];
        }
        $lock = @fopen($lockPath, 'c');
        if (!is_resource($lock) || !@flock($lock, LOCK_EX)) {
            return ['accepted' => false, 'status' => 'POINTER_CAS_BLOCKED', 'reason' => 'POINTER_LOCK_UNAVAILABLE', 'before_hash' => '', 'after_hash' => ''];
        }
        try {
            $beforeBytes = @file_get_contents($pointerPath);
            $before = is_string($beforeBytes) ? json_decode($beforeBytes, true) : null;
            $beforeHash = is_string($beforeBytes) ? 'sha256:' . hash('sha256', $beforeBytes) : '';
            if (!is_array($before) || $this->canonicalJson($before) !== $this->canonicalJson($expectedPointer)) {
                return ['accepted' => false, 'status' => 'POINTER_CAS_BLOCKED', 'reason' => 'BLOCKED_POINTER_CAS_CONFLICT', 'before_hash' => $beforeHash, 'after_hash' => ''];
            }
            $afterBytes = $this->canonicalJson($targetPointer) . "\n";
            $afterHash = 'sha256:' . hash('sha256', $afterBytes);
            if (!$this->atomicReplace($pointerPath, $afterBytes)) {
                return ['accepted' => false, 'status' => 'POINTER_CAS_BLOCKED', 'reason' => 'POINTER_CAS_REPLACE_FAILED', 'before_hash' => $beforeHash, 'after_hash' => ''];
            }
            return ['accepted' => true, 'status' => 'POINTER_CAS_APPLIED', 'reason' => '', 'before_hash' => $beforeHash, 'after_hash' => $afterHash];
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    /** @return array{accepted:bool,status:string,reason:string,closure:array<string,mixed>} */
    private function blocked(string $reason, array $closure = []): array { return ['accepted' => false, 'status' => 'CLOSURE_BLOCKED', 'reason' => $reason, 'closure' => $closure]; }
    /** @return array{accepted:bool,status:string,reason:string,abort:array<string,mixed>} */
    private function abortBlocked(string $reason, array $abort = []): array { return ['accepted' => false, 'status' => 'ABORT_BLOCKED', 'reason' => $reason, 'abort' => $abort]; }
    /** @return array{accepted:bool,status:string,reason:string,transition:array<string,mixed>} */
    private function pointerBlocked(string $reason): array { return ['accepted' => false, 'status' => 'POINTER_HANDOFF_BLOCKED', 'reason' => $reason, 'transition' => []]; }
    private function validClosure(array $row, string $windowId): bool
    {
        $schema = (string)($row['schema_version'] ?? '');
        if (!in_array($schema, [self::CLOSURE_SCHEMA_VERSION, self::IDENTITY_SEPARATED_CLOSURE_SCHEMA_VERSION], true)
            || ($row['window_id'] ?? '') !== $windowId || !is_string($row['closure_record_hash'] ?? null)) return false;
        if ($schema !== self::IDENTITY_SEPARATED_CLOSURE_SCHEMA_VERSION) return true;
        return ($row['target_capture_measurement_identity'] ?? '') !== ''
            && ($row['closing_tool_measurement_identity'] ?? '') !== ''
            && ($row['preflight_token'] ?? '') !== ''
            && ($row['official_epoch'] ?? '') === 'NO';
    }
    private function validAbort(array $row, string $windowId): bool
    {
        if (($row['schema_version'] ?? '') === self::TECHNICAL_INCOMPLETE_ABORT_SCHEMA_VERSION) return $this->validAbortV2($row, $windowId);
        return ($row['schema_version'] ?? '') === self::ABORT_SCHEMA_VERSION
            && ($row['window_id'] ?? '') === $windowId
            && self::validAbortReason((string)($row['abort_reason'] ?? ''))
            && ($row['official_epoch'] ?? '') === 'NO'
            && is_string($row['abort_record_hash'] ?? null);
    }
    private function validAbortV2(array $row, string $windowId): bool
    {
        $required = ['epoch_id', 'armed_at', 'aborted_at', 'capture_through_date', 'window_start_date', 'incident_id', 'incident_hash', 'affected_keyset_hash', 'final_evidence_hash', 'old_implementation_identity', 'activation_implementation_identity', 'previous_pointer_identity', 'closing_tool_implementation_identity', 'abort_record_hash'];
        foreach ($required as $field) if (!is_string($row[$field] ?? null) || trim((string)$row[$field]) === '') return false;
        if (($row['window_id'] ?? '') !== $windowId
            || ($row['terminal_state'] ?? '') !== 'ABORTED'
            || ($row['abort_reason'] ?? '') !== self::ABORT_REASON_FORMAL_EVIDENCE_INCOMPLETE_PRODUCER_DEFECT
            || ($row['official_epoch'] ?? '') !== 'YES'
            || !is_array($row['affected_race_codes'] ?? null)
            || (int)($row['missing_resolution_count'] ?? -1) !== count($row['affected_race_codes'])
            || ($row['repair_reconstructibility'] ?? '') !== 'PARTIAL'
            || !empty($row['historical_repair_performed'])
            || ($row['recovery_strategy'] ?? '') !== self::TECHNICAL_INCOMPLETE_RECOVERY_STRATEGY
            || ($row['evidence_retention_policy'] ?? '') !== self::TECHNICAL_INCOMPLETE_EVIDENCE_RETENTION_POLICY
            || empty($row['successor_required'])
            || !empty($row['formal_evaluation_eligible'])
            || !empty($row['production_adoption_eligible'])
            || !empty($row['successor_copy_permitted'])) return false;
        foreach (['incident_hash', 'affected_keyset_hash', 'final_evidence_hash', 'old_implementation_identity', 'activation_implementation_identity', 'previous_pointer_identity', 'closing_tool_implementation_identity', 'abort_record_hash'] as $field) if (!self::validHash((string)$row[$field])) return false;
        $basis = (string)($row['evidence_basis'] ?? 'COMPLETE_FORMAL_AGGREGATE');
        if ($basis === 'TECHNICAL_INCOMPLETE_ATTESTATION') {
            if (($row['evidence_attestation_schema'] ?? '') !== TechnicalIncompleteEvidenceAttestation::SCHEMA_VERSION
                || ($row['completeness_status'] ?? '') !== TechnicalIncompleteEvidenceAttestation::COMPLETENESS_STATUS
                || !self::validHash((string)($row['evidence_attestation_sha256'] ?? ''))
                || !self::validHash((string)($row['missing_resolution_keyset_sha256'] ?? ''))
                || !hash_equals((string)$row['evidence_attestation_sha256'], (string)$row['final_evidence_hash'])
                || $row['formal_evidence_aggregate_sha256'] !== null) return false;
        } elseif ($basis !== 'COMPLETE_FORMAL_AGGREGATE'
            || !self::validHash((string)($row['formal_evidence_aggregate_sha256'] ?? ''))
            || !hash_equals((string)$row['formal_evidence_aggregate_sha256'], (string)$row['final_evidence_hash'])) return false;
        $payload = $row; unset($payload['abort_record_hash']);
        return hash_equals((string)$row['abort_record_hash'], 'sha256:' . hash('sha256', $this->canonicalJson($payload)));
    }
    private static function validAbortReason(string $reason): bool
    {
        return in_array($reason, [
            self::ABORT_REASON_A1_EXPECTED_INVENTORY_BOOTSTRAP_INVALID,
            self::ABORT_REASON_A1_DEADLINE_SNAPSHOT_SEMANTIC_CONFLICT,
            self::ABORT_REASON_COMPARATIVE_RACE_LOCAL_REJECT_BATCH_ABORT_BUG,
        ], true);
    }
    private static function validHash(string $value): bool { return preg_match('/^sha256:[a-f0-9]{64}$/', $value) === 1; }
    private function verifiedLegacyWindow(FormalMeasurementWindowContext $context, array $activation, array $declaration): bool
    {
        if (($activation['activation_schema_version'] ?? '') !== WindowActivationHold::ACTIVATION_SCHEMA_VERSION
            || ($declaration['declaration_schema_version'] ?? '') !== WindowDeclaredImplementationIdentity::DECLARATION_SCHEMA_VERSION) return false;
        $manifestPath = $context->declaration()->provenanceStorageRoot() . '/' . (string)($declaration['manifest_ref'] ?? '');
        try { $frozen = json_decode((string)file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR); } catch (Throwable) { return false; }
        if (!is_array($frozen) || !is_array($frozen['files'] ?? null)) return false;
        foreach ($frozen['files'] as $file) if (is_array($file) && ($file['path'] ?? '') === 'web/logic/FormalMeasurementWindowLifecycle.php') return false;
        return true;
    }
    /** @param array<string,mixed> $closure @return array{accepted:bool,status:string,reason:string,closure:array<string,mixed>} */
    private function persistClosure(array $closure): array
    {
        $windowId = (string)$closure['window_id'];
        $closure['closure_record_hash'] = 'sha256:' . hash('sha256', $this->canonicalJson($closure));
        $path = $this->closurePath($windowId);
        if (is_file($path)) {
            $existing = $this->readClosure($windowId);
            if ($this->sameClosureIntent($existing, $closure)) return ['accepted' => true, 'status' => 'CLOSURE_ALREADY_EXISTS', 'reason' => '', 'closure' => $existing];
            return $this->blocked('BLOCKED_CLOSURE_CONFLICT', $existing);
        }
        if (!$this->writeCreateOnly($path, $this->canonicalJson($closure) . "\n")) return $this->blocked('CLOSURE_CREATE_FAILED');
        return ['accepted' => true, 'status' => 'CLOSURE_CREATED', 'reason' => '', 'closure' => $closure];
    }
    private function sameClosureIntent(array $a, array $b): bool { foreach (['window_id','armed_at','capture_through_date','final_evidence_hash','closure_reason','declared_implementation_identity','activation_implementation_identity','comparative_policy_identity','previous_pointer_identity','closing_tool_implementation_identity'] as $key) if (($a[$key] ?? null) !== ($b[$key] ?? null)) return false; return true; }
    /** @param array<string,mixed> $input */
    private function sameAbortIntent(array $existing, array $input): bool
    {
        foreach (['window_id','armed_at','capture_through_date','abort_reason','failure_code','final_evidence_hash','declared_implementation_identity','activation_implementation_identity','comparative_policy_identity','previous_pointer_identity','closing_tool_implementation_identity'] as $key) {
            if (($existing[$key] ?? null) !== ($input[$key] ?? null)) return false;
        }
        return true;
    }
    private function writeCreateOnly(string $path, string $bytes): bool { $dir = dirname($path); if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return false; $h = @fopen($path, 'x'); if (!is_resource($h)) return false; $ok = @fwrite($h, $bytes) !== false; @fclose($h); return $ok; }
    private function atomicReplace(string $path, string $bytes): bool { $tmp = $path . '.tmp.' . bin2hex(random_bytes(8)); if (@file_put_contents($tmp, $bytes, LOCK_EX) === false) return false; if (!@rename($tmp, $path)) { @unlink($tmp); return false; } return true; }
    private function canonicalJson(array $value): string { $normal = $this->sort($value); $json = json_encode($normal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); return is_string($json) ? $json : '{}'; }
    private function sort(mixed $value): mixed { if (!is_array($value)) return $value; if (array_is_list($value)) return array_map(fn(mixed $v): mixed => $this->sort($v), $value); ksort($value, SORT_STRING); foreach ($value as $k => $v) $value[$k] = $this->sort($v); return $value; }
}
