<?php

declare(strict_types=1);

require_once __DIR__ . '/OfficialEpochPolicyV1.php';
require_once __DIR__ . '/OfficialEpochIncidentPolicyV1.php';
require_once __DIR__ . '/OfficialEpochStartStore.php';
require_once __DIR__ . '/OfficialEpochRecoveryLifecycleV1.php';

/**
 * External, append-only lifecycle records for an already-started Official
 * Epoch.  No method accepts a Window root, intentionally: incidents may only
 * project evidence status and must never mutate capture evidence.
 */
final class OfficialEpochIncidentLifecycleStore
{
    public const INCIDENT_SCHEMA = 'official-epoch-incident/v1';
    public const SUSPENSION_SCHEMA = 'official-epoch-suspension/v1';
    /** Legacy v1 records remain readable; new critical resolutions use v2. */
    public const RESOLUTION_SCHEMA = OfficialEpochRecoveryLifecycleV1::RESOLUTION_SCHEMA;
    public const LEGACY_RESOLUTION_SCHEMA = 'official-epoch-incident-resolution/v1';
    public const TECHNICAL_INCOMPLETE_RESOLUTION_SCHEMA = 'official-epoch-incident-resolution/v3';
    public const TECHNICAL_INCOMPLETE_RECOVERY_STRATEGY = 'FREEZE_CURRENT_AND_START_CLEAN_WINDOW';
    public const RESUME_SCHEMA = 'official-epoch-resume/v1';
    public const END_SCHEMA = 'official-epoch-end/v1';

    public function __construct(private readonly string $lifecycleRoot) {}

    /** @return array<string,mixed> */
    public function project(string $epochId): array
    {
        $start = (new OfficialEpochStartStore($this->lifecycleRoot))->existing();
        if ($start === [] || ($start['epoch_id'] ?? '') !== $epochId) return ['valid' => false, 'reason' => 'OFFICIAL_EPOCH_NOT_FOUND'];
        $state = 'ACTIVE';
        $stateHash = $this->stateHash($epochId, '', $state, (string)$start['record_hash']);
        $transitions = [];
        foreach ($this->transitionRows() as $row) {
            if (($row['epoch_id'] ?? '') !== $epochId) continue;
            $valid = $this->validTransition($row);
            if (!$valid['valid']) return ['valid' => false, 'reason' => $valid['reason'], 'state' => $state, 'state_hash' => $stateHash];
            if (($row['from_state'] ?? '') !== $state || ($row['predecessor_state_hash'] ?? '') !== $stateHash) continue;
            $matches = array_filter($this->transitionRows(), static fn(array $candidate): bool => ($candidate['epoch_id'] ?? '') === ($row['epoch_id'] ?? '') && ($candidate['from_state'] ?? '') === ($row['from_state'] ?? '') && ($candidate['predecessor_state_hash'] ?? '') === ($row['predecessor_state_hash'] ?? ''));
            if (count($matches) !== 1) return ['valid' => false, 'reason' => 'OFFICIAL_EPOCH_COMPETING_TRANSITION', 'state' => $state, 'state_hash' => $stateHash];
            $state = (string)$row['to_state'];
            $stateHash = $this->stateHash($epochId, $stateHash, $state, (string)$row['record_hash']);
            $transitions[] = $row;
        }
        return ['valid' => true, 'state' => $state, 'state_hash' => $stateHash, 'start' => $start, 'transitions' => $transitions, 'evidence_designations' => $this->evidenceDesignations($epochId)];
    }

    /**
     * Read-only projection.  It deliberately reports designations without
     * moving, deleting, rewriting, or otherwise changing source evidence.
     * @return list<array<string,mixed>>
     */
    public function evidenceDesignations(string $epochId): array
    {
        $rows = [];
        foreach (glob(rtrim($this->lifecycleRoot, '/') . '/official_epoch_incidents/*.json') ?: [] as $path) {
            try { $row = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); } catch (Throwable) { continue; }
            if (!is_array($row) || ($row['epoch_id'] ?? '') !== $epochId) continue;
            $payload = $row; unset($payload['record_hash']);
            if (!hash_equals($this->hash($payload), (string)($row['record_hash'] ?? ''))) continue;
            $status = (string)($row['evidence_status_projection'] ?? '');
            if (!in_array($status, ['VALID', 'QUARANTINED', 'INVALIDATED'], true)) continue;
            $rows[] = ['incident_id' => (string)($row['incident_id'] ?? ''), 'affected_scope' => $row['affected_scope'] ?? [], 'evidence_status' => $status, 'source_evidence_ref' => (string)($row['source_evidence_ref'] ?? ''), 'source_evidence_hash' => (string)($row['source_evidence_hash'] ?? '')];
        }
        usort($rows, static fn(array $a, array $b): int => strcmp((string)$a['incident_id'], (string)$b['incident_id']));
        return $rows;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function incidentRecord(array $input): array
    {
        $classification = OfficialEpochIncidentPolicyV1::classify((string)($input['incident_type'] ?? ''), $input);
        $key = ['schema_version' => self::INCIDENT_SCHEMA, 'epoch_id' => (string)($input['epoch_id'] ?? ''), 'incident_type' => (string)($classification['incident_type'] ?? 'NON_MEASUREMENT_CHANGE'), 'source_evidence_hash' => (string)($input['source_evidence_hash'] ?? ''), 'affected_scope' => $input['affected_scope'] ?? []];
        $record = [
            'schema_version' => self::INCIDENT_SCHEMA,
            'incident_id' => 'official-epoch-incident-v1-' . substr(hash('sha256', OfficialEpochPolicyV1::canonicalJson($key)), 0, 16),
            'incident_key' => 'sha256:' . hash('sha256', OfficialEpochPolicyV1::canonicalJson($key)),
            'epoch_id' => (string)($input['epoch_id'] ?? ''),
            'source_window_id' => $input['source_window_id'] ?? null,
            'detected_at' => (string)($input['detected_at'] ?? ''),
            'incident_type' => (string)($classification['incident_type'] ?? 'NON_MEASUREMENT_CHANGE'),
            'severity' => (string)($classification['severity'] ?? 'INFO'),
            'classification' => (string)($classification['classification'] ?? 'INFORMATIONAL'),
            'affected_scope' => is_array($input['affected_scope'] ?? null) ? $input['affected_scope'] : [],
            'proven_good_boundary' => $input['proven_good_boundary'] ?? null,
            'detection_evidence_ref' => (string)($input['detection_evidence_ref'] ?? $input['source_evidence_ref'] ?? ''),
            'detection_evidence_hash' => (string)($input['detection_evidence_hash'] ?? $input['source_evidence_hash'] ?? ''),
            'source_evidence_ref' => (string)($input['source_evidence_ref'] ?? ''),
            'source_evidence_hash' => (string)($input['source_evidence_hash'] ?? ''),
            'epoch_state_before' => (string)($input['epoch_state_before'] ?? 'ACTIVE'),
            'recommended_state_after' => (string)($input['recommended_state_after'] ?? (($classification['severity'] ?? '') === 'CRITICAL' ? 'SUSPENDED' : 'ACTIVE')),
            'window_action' => (string)($input['window_action'] ?? (($classification['severity'] ?? '') === 'MAJOR' ? 'ABORT_ACTIVE_WINDOW' : 'NONE')),
            'evidence_action' => (string)($input['evidence_action'] ?? (($classification['severity'] ?? '') === 'INFO' ? 'NONE' : 'QUARANTINE')),
            'evidence_status_projection' => (string)($input['evidence_status_projection'] ?? (($classification['severity'] ?? '') === 'INFO' ? 'VALID' : 'QUARANTINED')),
            'automatic_actions' => $classification['actions'] ?? [],
            'requires_new_window' => !empty($classification['requires_new_window']),
            'requires_new_epoch' => !empty($classification['requires_new_epoch']),
            'requires_cg_reapproval' => !empty($classification['requires_cg_reapproval']),
            'production_routing_changed' => false,
            'incident_policy_schema' => OfficialEpochIncidentPolicyV1::SCHEMA_VERSION,
            'incident_policy_identity' => OfficialEpochIncidentPolicyV1::identity(),
        ];
        $record['record_hash'] = $this->hash($record);
        return $record;
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    public function createIncident(array $record): array { return $this->create('official_epoch_incidents', (string)($record['incident_id'] ?? ''), $record, self::INCIDENT_SCHEMA, 'INCIDENT'); }

    /**
     * Builds the existing v1 incident envelope using the narrow MAJOR
     * technical-incomplete classifier. The general incident classifier and
     * its frozen policy identity remain unchanged.
     *
     * @param array<string,mixed> $input
     * @return array{accepted:bool,status:string,reason:string,record:array<string,mixed>}
     */
    public function technicalIncompleteIncidentRecord(array $input): array
    {
        $classification = OfficialEpochIncidentPolicyV1::classifyTechnicalIncompleteSchedulerFailure($input);
        if (empty($classification['accepted'])) return $this->blocked((string)$classification['reason']);
        $base = $this->incidentRecord(array_merge($input, ['incident_type' => 'SCHEDULER_FAILURE_MISSED_OPPORTUNITY']));
        $base['severity'] = 'MAJOR';
        $base['classification'] = 'INCIDENT';
        $base['recommended_state_after'] = 'ACTIVE';
        $base['window_action'] = 'ABORT_ACTIVE_WINDOW';
        $base['evidence_action'] = 'QUARANTINE';
        $base['evidence_status_projection'] = 'QUARANTINED';
        $base['automatic_actions'] = $classification['actions'];
        $base['requires_new_window'] = true;
        $base['requires_new_epoch'] = false;
        $base['requires_cg_reapproval'] = false;
        $base['technical_incomplete_classification_reason'] = (string)$classification['reason'];
        $base['record_hash'] = $this->hash(array_diff_key($base, ['record_hash' => true]));
        return ['accepted' => true, 'status' => 'TECHNICAL_INCOMPLETE_INCIDENT_ELIGIBLE', 'reason' => '', 'record' => $base];
    }

    /**
     * Versioned resolution for a bounded, evidence-preserving Window abort.
     * It is intentionally separate from v2 suspended-Epoch recovery records.
     *
     * @param array<string,mixed> $input
     * @return array{accepted:bool,status:string,reason:string,record:array<string,mixed>}
     */
    public function technicalIncompleteResolutionRecord(array $input): array
    {
        $required = ['epoch_id', 'incident_id', 'incident_hash', 'affected_window_id', 'detected_at', 'resolved_at', 'root_cause', 'old_implementation_identity'];
        foreach ($required as $field) if (!is_string($input[$field] ?? null) || trim((string)$input[$field]) === '') return $this->blocked('TECHNICAL_INCOMPLETE_RESOLUTION_FIELD_MISSING_' . strtoupper($field));
        $races = $input['affected_race_codes'] ?? null;
        if (!is_array($races) || $races === []) return $this->blocked('TECHNICAL_INCOMPLETE_RESOLUTION_AFFECTED_RACES_INVALID');
        $normalized = [];
        foreach ($races as $race) {
            $race = strtoupper(trim((string)$race));
            if (preg_match('/^\d{8}[A-Z0-9]{3}\d{2}$/', $race) !== 1 || isset($normalized[$race])) return $this->blocked('TECHNICAL_INCOMPLETE_RESOLUTION_AFFECTED_RACES_INVALID');
            $normalized[$race] = true;
        }
        $races = array_keys($normalized); sort($races, SORT_STRING);
        $status = (string)($input['resolution_status'] ?? 'PREPARED_FOR_ROLLOVER');
        if (!in_array($status, ['PREPARED_FOR_ROLLOVER', 'RESOLVED'], true)
            || ($input['incident_type'] ?? 'SCHEDULER_FAILURE_MISSED_OPPORTUNITY') !== 'SCHEDULER_FAILURE_MISSED_OPPORTUNITY'
            || ($input['severity'] ?? 'MAJOR') !== 'MAJOR'
            || ($input['repair_reconstructibility'] ?? 'PARTIAL') !== 'PARTIAL'
            || !empty($input['historical_repair_performed'])
            || empty($input['requires_new_window']) || !empty($input['requires_new_epoch'])
            || ($input['recovery_strategy'] ?? self::TECHNICAL_INCOMPLETE_RECOVERY_STRATEGY) !== self::TECHNICAL_INCOMPLETE_RECOVERY_STRATEGY
            || !self::validHash((string)$input['incident_hash'])
            || !self::validHash((string)$input['old_implementation_identity'])) {
            return $this->blocked('TECHNICAL_INCOMPLETE_RESOLUTION_INPUT_INVALID');
        }
        $newIdentity = $input['new_implementation_identity'] ?? null;
        if ($newIdentity !== null && (!is_string($newIdentity) || !self::validHash($newIdentity))) return $this->blocked('TECHNICAL_INCOMPLETE_RESOLUTION_NEW_IDENTITY_INVALID');
        if ($status === 'RESOLVED' && ($newIdentity === null || empty($input['root_cause_fixed']))) return $this->blocked('TECHNICAL_INCOMPLETE_RESOLUTION_RESOLVED_FIELDS_MISSING');
        $key = [
            'schema_version' => self::TECHNICAL_INCOMPLETE_RESOLUTION_SCHEMA,
            'epoch_id' => (string)$input['epoch_id'], 'incident_hash' => (string)$input['incident_hash'],
            'affected_window_id' => (string)$input['affected_window_id'], 'old_implementation_identity' => (string)$input['old_implementation_identity'],
            'new_implementation_identity' => $newIdentity, 'resolution_status' => $status,
        ];
        $record = $key + [
            'resolution_id' => 'official-epoch-resolution-v3-' . substr(hash('sha256', OfficialEpochPolicyV1::canonicalJson($key)), 0, 16),
            'resolution_key' => $this->hash($key), 'incident_id' => (string)$input['incident_id'],
            'incident_type' => 'SCHEDULER_FAILURE_MISSED_OPPORTUNITY', 'severity' => 'MAJOR',
            'detected_at' => (string)$input['detected_at'], 'resolved_at' => (string)$input['resolved_at'],
            'root_cause' => (string)$input['root_cause'], 'root_cause_fixed' => !empty($input['root_cause_fixed']),
            'affected_race_codes' => $races, 'affected_count' => count($races),
            'repair_reconstructibility' => 'PARTIAL', 'historical_repair_performed' => false,
            'requires_new_window' => true, 'requires_new_epoch' => false,
            'recovery_strategy' => self::TECHNICAL_INCOMPLETE_RECOVERY_STRATEGY,
            'resolution_status' => $status,
            'evidence_retention_policy' => 'QUARANTINED_RETAINED_NONCANONICAL_NOT_INVALIDATED',
            'formal_evaluation_eligible' => false, 'successor_copy_permitted' => false,
            'new_implementation_identity' => $newIdentity,
            'created_at' => (string)($input['created_at'] ?? $input['resolved_at']),
        ];
        $record['record_hash'] = $this->hash($record);
        return ['accepted' => true, 'status' => 'TECHNICAL_INCOMPLETE_RESOLUTION_ELIGIBLE', 'reason' => '', 'record' => $record];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createTechnicalIncompleteResolution(array $input): array
    {
        return $this->withGlobalLock(function () use ($input): array {
            $prepared = $this->technicalIncompleteResolutionRecord($input);
            if (empty($prepared['accepted'])) return $prepared;
            $record = $prepared['record'];
            $projection = $this->project((string)$record['epoch_id']);
            if (empty($projection['valid']) || ($projection['state'] ?? '') !== 'ACTIVE') return $this->blocked('TECHNICAL_INCOMPLETE_RESOLUTION_EPOCH_NOT_ACTIVE');
            $incident = $this->incidentForId((string)$record['incident_id']);
            if ($incident === []
                || !hash_equals((string)$record['incident_hash'], (string)($incident['record_hash'] ?? ''))
                || ($incident['incident_type'] ?? '') !== 'SCHEDULER_FAILURE_MISSED_OPPORTUNITY'
                || ($incident['severity'] ?? '') !== 'MAJOR'
                || ($incident['source_window_id'] ?? '') !== $record['affected_window_id']) {
                return $this->blocked('TECHNICAL_INCOMPLETE_RESOLUTION_INCIDENT_CROSS_BINDING_INVALID');
            }
            return $this->createUnlocked('official_epoch_incident_resolutions', (string)$record['resolution_id'], $record, self::TECHNICAL_INCOMPLETE_RESOLUTION_SCHEMA, 'TECHNICAL_INCOMPLETE_RESOLUTION');
        });
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function resolutionRecord(array $input): array
    {
        return $this->recovery()->resolutionRecord($input);
        /* Legacy v1 constructor retained below only as historical documentation.
        $key = ['schema_version' => self::RESOLUTION_SCHEMA, 'epoch_id' => (string)($input['epoch_id'] ?? ''), 'incident_id' => (string)($input['incident_id'] ?? ''), 'incident_hash' => (string)($input['incident_hash'] ?? '')];
        $row = ['schema_version' => self::RESOLUTION_SCHEMA, 'resolution_id' => 'official-epoch-resolution-v1-' . substr(hash('sha256', OfficialEpochPolicyV1::canonicalJson($key)), 0, 16), 'resolution_key' => 'sha256:' . hash('sha256', OfficialEpochPolicyV1::canonicalJson($key)), 'epoch_id' => $key['epoch_id'], 'incident_id' => $key['incident_id'], 'incident_hash' => $key['incident_hash'], 'resolved_at' => (string)($input['resolved_at'] ?? ''), 'resolution_status' => (string)($input['resolution_status'] ?? 'RESOLVED'), 'root_cause' => (string)($input['root_cause'] ?? ''), 'affected_scope_final' => is_array($input['affected_scope_final'] ?? null) ? $input['affected_scope_final'] : [], 'evidence_status_final' => (string)($input['evidence_status_final'] ?? 'QUARANTINED'), 'fix_implementation_identity' => (string)($input['fix_implementation_identity'] ?? ''), 'semantic_change_classification' => (string)($input['semantic_change_classification'] ?? 'UNKNOWN'), 'regression_result' => (string)($input['regression_result'] ?? ''), 'hard_gate_result' => (string)($input['hard_gate_result'] ?? ''), 'numeric_quality_result' => (string)($input['numeric_quality_result'] ?? ''), 'operational_readiness_result' => (string)($input['operational_readiness_result'] ?? ''), 'requires_new_window' => !empty($input['requires_new_window']), 'requires_new_epoch' => !empty($input['requires_new_epoch']), 'requires_cg_reapproval' => !empty($input['requires_cg_reapproval']), 'resolution_evidence_ref' => (string)($input['resolution_evidence_ref'] ?? ''), 'resolution_evidence_hash' => (string)($input['resolution_evidence_hash'] ?? ''), 'cg_reapproval_ref' => (string)($input['cg_reapproval_ref'] ?? ''), 'cg_reapproval_hash' => (string)($input['cg_reapproval_hash'] ?? ''), 'incident_policy_identity' => OfficialEpochIncidentPolicyV1::identity()];
        $row['record_hash'] = $this->hash($row); return $row; */
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    public function createResolution(array $record): array
    {
        $valid = $this->recovery()->validateResolution($record);
        if (empty($valid['accepted'])) return $this->blocked('OFFICIAL_EPOCH_RESOLUTION_PRECHECK_FAILED:' . (string)$valid['reason']);
        return $this->create('official_epoch_incident_resolutions', (string)($record['resolution_id'] ?? ''), $record, self::RESOLUTION_SCHEMA, 'RESOLUTION');
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function transitionRecord(string $kind, array $input): array
    {
        $schema = match ($kind) { 'SUSPEND' => self::SUSPENSION_SCHEMA, 'RESUME' => self::RESUME_SCHEMA, 'END' => self::END_SCHEMA, default => throw new InvalidArgumentException('incident_transition_kind_invalid') };
        $to = $kind === 'SUSPEND' ? 'SUSPENDED' : ($kind === 'RESUME' ? 'ACTIVE' : 'ENDED');
        $key = ['schema_version' => $schema, 'epoch_id' => (string)($input['epoch_id'] ?? ''), 'from_state' => (string)($input['from_state'] ?? ''), 'predecessor_state_hash' => (string)($input['predecessor_state_hash'] ?? ''), 'incident_hash' => (string)($input['incident_hash'] ?? ''), 'resolution_hash' => (string)($input['resolution_hash'] ?? '')];
        $row = ['schema_version' => $schema, 'transition_id' => 'official-epoch-' . strtolower($kind) . '-v1-' . substr(hash('sha256', OfficialEpochPolicyV1::canonicalJson($key)), 0, 16), 'transition_key' => 'sha256:' . hash('sha256', OfficialEpochPolicyV1::canonicalJson($key)), 'epoch_id' => $key['epoch_id'], 'transition_at' => (string)($input['transition_at'] ?? ''), 'from_state' => $key['from_state'], 'to_state' => $to, 'predecessor_state_hash' => $key['predecessor_state_hash'], 'incident_ref' => (string)($input['incident_ref'] ?? ''), 'incident_hash' => $key['incident_hash'], 'resolution_ref' => (string)($input['resolution_ref'] ?? ''), 'resolution_hash' => $key['resolution_hash'], 'reason' => (string)($input['reason'] ?? ''), 'scheduler_expected_state' => (string)($input['scheduler_expected_state'] ?? ($kind === 'SUSPEND' ? 'HELD' : 'UNCHANGED')), 'active_window_action' => (string)($input['active_window_action'] ?? 'NONE'), 'requires_new_epoch' => $kind === 'END' ? true : !empty($input['requires_new_epoch']), 'cg_authorization_required' => $kind !== 'SUSPEND' || !empty($input['cg_authorization_required']), 'cg_authorization_ref' => (string)($input['cg_authorization_ref'] ?? ''), 'cg_authorization_hash' => (string)($input['cg_authorization_hash'] ?? ''), 'recovery_successor_attestation_ref' => (string)($input['recovery_successor_attestation_ref'] ?? ''), 'recovery_successor_attestation_hash' => (string)($input['recovery_successor_attestation_hash'] ?? ''), 'recovery_replacement_window_id' => (string)($input['recovery_replacement_window_id'] ?? ''), 'recovery_current_measurement_identity' => (string)($input['recovery_current_measurement_identity'] ?? ''), 'scheduler_held' => !empty($input['scheduler_held']), 'capture_process_count' => (int)($input['capture_process_count'] ?? -1), 'incident_policy_identity' => OfficialEpochIncidentPolicyV1::identity()];
        $row['record_hash'] = $this->hash($row); return $row;
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    public function commitTransition(string $kind, array $record): array
    {
        return $this->withGlobalLock(fn(): array => $this->commitTransitionUnlocked($kind, $record));
    }

    /**
     * Reuses the one global Epoch lock for adjacent lifecycle commits such as
     * successor Window binding and guarded activation.  The callback itself
     * decides its create-only operation; no Epoch state is changed here.
     *
     * @param callable():array<string,mixed> $operation
     * @return array<string,mixed>
     */
    public function withGlobalEpochLock(callable $operation): array
    {
        return $this->withGlobalLock($operation);
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function commitTransitionUnlocked(string $kind, array $record): array
    {
        $dir = match ($kind) { 'SUSPEND' => 'official_epoch_suspensions', 'RESUME' => 'official_epoch_resumes', 'END' => 'official_epoch_ends' };
        $schema = match ($kind) { 'SUSPEND' => self::SUSPENSION_SCHEMA, 'RESUME' => self::RESUME_SCHEMA, 'END' => self::END_SCHEMA };
        $id = (string)($record['transition_id'] ?? '');
        $existingPath = rtrim($this->lifecycleRoot, '/') . '/' . $dir . '/' . $id . '.json';
        if ($id !== '' && is_file($existingPath)) {
            $existing = json_decode((string)file_get_contents($existingPath), true);
            if (is_array($existing) && ($existing['record_hash'] ?? '') === ($record['record_hash'] ?? '')) return ['accepted' => true, 'status' => 'OFFICIAL_EPOCH_' . $kind . '_ALREADY_EXISTS', 'reason' => '', 'record' => $existing];
            return $this->blocked('OFFICIAL_EPOCH_' . $kind . '_CONFLICT');
        }
        $projection = $this->project((string)($record['epoch_id'] ?? ''));
        if (empty($projection['valid'])) return $this->blocked('OFFICIAL_EPOCH_STATE_INVALID:' . (string)($projection['reason'] ?? ''));
        if (($record['from_state'] ?? '') !== ($projection['state'] ?? '') || ($record['predecessor_state_hash'] ?? '') !== ($projection['state_hash'] ?? '')) return $this->blocked('OFFICIAL_EPOCH_STATE_CAS_CONFLICT');
        if ($kind === 'SUSPEND' && ($projection['state'] ?? '') !== 'ACTIVE') return $this->blocked('OFFICIAL_EPOCH_SUSPEND_STATE_INVALID');
        if ($kind === 'RESUME') {
            if (($projection['state'] ?? '') !== 'SUSPENDED') return $this->blocked('OFFICIAL_EPOCH_RESUME_STATE_INVALID');
            $preflight = $this->recovery()->preflightResume([
                'epoch_state' => 'SUSPENDED', 'scheduler_held' => !empty($record['scheduler_held']), 'capture_process_count' => (int)($record['capture_process_count'] ?? -1),
                'end_record_exists' => false, 'resolution_path' => $this->resolveLifecycleRef((string)($record['resolution_ref'] ?? '')),
                'attestation_path' => $this->resolveLifecycleRef((string)($record['recovery_successor_attestation_ref'] ?? '')),
                'approval_path' => $this->resolveLifecycleRef((string)($record['cg_authorization_ref'] ?? '')),
                'current_measurement_identity' => (string)($record['recovery_current_measurement_identity'] ?? ''),
            ]);
            if (empty($preflight['accepted'])) return $this->blocked('OFFICIAL_EPOCH_RESUME_RECOVERY_PRECHECK_FAILED:' . (string)$preflight['reason']);
            if (!hash_equals((string)($record['resolution_hash'] ?? ''), (string)($preflight['resolution']['record_hash'] ?? '')) || !hash_equals((string)($record['cg_authorization_hash'] ?? ''), (string)($preflight['approval']['record_hash'] ?? '')) || !hash_equals((string)($record['recovery_successor_attestation_hash'] ?? ''), (string)($preflight['attestation']['record_hash'] ?? ''))) return $this->blocked('OFFICIAL_EPOCH_RESUME_HASH_CROSS_BINDING_MISMATCH');
        }
        if ($kind === 'END' && !in_array((string)($projection['state'] ?? ''), ['ACTIVE', 'SUSPENDED'], true)) return $this->blocked('OFFICIAL_EPOCH_END_STATE_INVALID');
        return $this->createUnlocked($dir, (string)($record['transition_id'] ?? ''), $record, $schema, $kind);
    }

    /** @return list<array<string,mixed>> */
    private function transitionRows(): array
    {
        $rows = [];
        foreach (['official_epoch_suspensions', 'official_epoch_resumes', 'official_epoch_ends'] as $dir) foreach (glob(rtrim($this->lifecycleRoot, '/') . '/' . $dir . '/*.json') ?: [] as $path) {
            try { $row = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); } catch (Throwable) { $row = null; }
            if (is_array($row)) { $row['_path'] = $path; $rows[] = $row; }
        }
        usort($rows, static function (array $a, array $b): int {
            $time = strcmp((string)($a['transition_at'] ?? ''), (string)($b['transition_at'] ?? ''));
            return $time !== 0 ? $time : strcmp((string)($a['_path'] ?? ''), (string)($b['_path'] ?? ''));
        });
        return $rows;
    }

    /** @param array<string,mixed> $row @return array{valid:bool,reason:string} */
    private function validTransition(array $row): array
    {
        $schema = (string)($row['schema_version'] ?? '');
        $allowed = [self::SUSPENSION_SCHEMA => ['ACTIVE', 'SUSPENDED'], self::RESUME_SCHEMA => ['SUSPENDED', 'ACTIVE'], self::END_SCHEMA => [null, 'ENDED']];
        if (!isset($allowed[$schema])) return ['valid' => false, 'reason' => 'OFFICIAL_EPOCH_TRANSITION_SCHEMA_INVALID'];
        if (($schema !== self::END_SCHEMA && ($row['from_state'] ?? '') !== $allowed[$schema][0])
            || ($schema === self::END_SCHEMA && !in_array((string)($row['from_state'] ?? ''), ['ACTIVE', 'SUSPENDED'], true))
            || ($row['to_state'] ?? '') !== $allowed[$schema][1]) return ['valid' => false, 'reason' => 'OFFICIAL_EPOCH_TRANSITION_STATE_INVALID'];
        $payload = $row; unset($payload['record_hash'], $payload['_path']);
        return hash_equals($this->hash($payload), (string)($row['record_hash'] ?? '')) ? ['valid' => true, 'reason' => ''] : ['valid' => false, 'reason' => 'OFFICIAL_EPOCH_TRANSITION_HASH_INVALID'];
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function create(string $dir, string $id, array $record, string $schema, string $kind): array
    {
        return $this->withGlobalLock(fn(): array => $this->createUnlocked($dir, $id, $record, $schema, $kind));
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function createUnlocked(string $dir, string $id, array $record, string $schema, string $kind): array
    {
        if ($id === '' || ($record['schema_version'] ?? '') !== $schema || !hash_equals($this->hash(array_diff_key($record, ['record_hash' => true])), (string)($record['record_hash'] ?? ''))) return $this->blocked('OFFICIAL_EPOCH_' . $kind . '_RECORD_INVALID');
        $path = rtrim($this->lifecycleRoot, '/') . '/' . $dir . '/' . $id . '.json'; $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) return $this->blocked('OFFICIAL_EPOCH_' . $kind . '_DIRECTORY_UNAVAILABLE');
        if (is_file($path)) { $existing = json_decode((string)file_get_contents($path), true); return is_array($existing) && ($existing['record_hash'] ?? '') === ($record['record_hash'] ?? '') ? ['accepted' => true, 'status' => 'OFFICIAL_EPOCH_' . $kind . '_ALREADY_EXISTS', 'reason' => '', 'record' => $existing] : $this->blocked('OFFICIAL_EPOCH_' . $kind . '_CONFLICT'); }
        $handle = @fopen($path, 'x'); if (!is_resource($handle)) return $this->blocked('OFFICIAL_EPOCH_' . $kind . '_CREATE_CONFLICT');
        $ok = @fwrite($handle, OfficialEpochPolicyV1::canonicalJson($record) . "\n") !== false; @fclose($handle);
        return $ok ? ['accepted' => true, 'status' => 'OFFICIAL_EPOCH_' . $kind . '_CREATED', 'reason' => '', 'record' => $record] : $this->blocked('OFFICIAL_EPOCH_' . $kind . '_WRITE_FAILED');
    }

    /** @param callable():array<string,mixed> $operation @return array<string,mixed> */
    private function withGlobalLock(callable $operation): array
    {
        $path = rtrim($this->lifecycleRoot, '/') . '/official_epoch_v1.lock';
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) return $this->blocked('OFFICIAL_EPOCH_LOCK_DIRECTORY_UNAVAILABLE');
        $lock = @fopen($path, 'c');
        if (!is_resource($lock) || !@flock($lock, LOCK_EX)) return $this->blocked('OFFICIAL_EPOCH_LOCK_UNAVAILABLE');
        try { return $operation(); } finally { @flock($lock, LOCK_UN); @fclose($lock); }
    }

    private function stateHash(string $epoch, string $previous, string $state, string $recordHash): string { return 'sha256:' . hash('sha256', OfficialEpochPolicyV1::canonicalJson(['epoch_id' => $epoch, 'previous_state_hash' => $previous, 'state' => $state, 'record_hash' => $recordHash])); }
    private function recovery(): OfficialEpochRecoveryLifecycleV1 { return new OfficialEpochRecoveryLifecycleV1(dirname($this->lifecycleRoot, 3), $this->lifecycleRoot); }
    /** @return array<string,mixed> */
    private function incidentForId(string $incidentId): array
    {
        if (preg_match('/^[A-Za-z0-9._-]+$/', $incidentId) !== 1) return [];
        $path = rtrim($this->lifecycleRoot, '/') . '/official_epoch_incidents/' . $incidentId . '.json';
        try { $row = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); }
        catch (Throwable) { return []; }
        if (!is_array($row) || ($row['schema_version'] ?? '') !== self::INCIDENT_SCHEMA) return [];
        $payload = $row; unset($payload['record_hash']);
        return hash_equals($this->hash($payload), (string)($row['record_hash'] ?? '')) ? $row : [];
    }
    private function resolveLifecycleRef(string $ref): string { $ref = ltrim($ref, '/'); return $ref === '' || str_contains($ref, '..') ? '' : rtrim($this->lifecycleRoot, '/') . '/' . preg_replace('#^lifecycle/#', '', $ref); }
    /** @param array<string,mixed> $value */ private function hash(array $value): string { return 'sha256:' . hash('sha256', OfficialEpochPolicyV1::canonicalJson($value)); }
    private static function validHash(string $value): bool { return preg_match('/^sha256:[a-f0-9]{64}$/', $value) === 1; }
    /** @return array<string,mixed> */ private function blocked(string $reason): array { return ['accepted' => false, 'status' => 'OFFICIAL_EPOCH_LIFECYCLE_BLOCKED', 'reason' => $reason, 'record' => []]; }
}
