<?php

declare(strict_types=1);

require_once __DIR__ . '/OfficialEpochPolicyV1.php';
require_once __DIR__ . '/OfficialEpochIncidentPolicyV1.php';

/**
 * External create-only CG authorization records.
 *
 * The store is intentionally independent of a measurement Window.  Approval
 * is an explicit human/governance act and is never inferred from a status
 * display, current pointer, or current implementation identity.
 */
final class OfficialEpochCgApprovalStore
{
    public const SCHEMA_VERSION = 'official-epoch-cg-approval/v1';

    public function __construct(private readonly string $lifecycleRoot)
    {
    }

    public function recordPath(string $approvalKey): string
    {
        if (!preg_match('/^sha256:[a-f0-9]{64}$/', $approvalKey)) throw new InvalidArgumentException('epoch_approval_key_invalid');
        return rtrim($this->lifecycleRoot, DIRECTORY_SEPARATOR) . '/official_epoch_approvals/' . substr($approvalKey, 7) . '.json';
    }

    /** @return array<string,mixed> */
    public function read(string $approvalKey): array
    {
        $path = $this->recordPath($approvalKey);
        if (!is_file($path)) return [];
        try {
            $row = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }
        return is_array($row) ? $row : [];
    }

    /**
     * @param array<string,mixed> $input human-supplied, explicit approval payload
     * @return array{accepted:bool,status:string,reason:string,record:array<string,mixed>}
     */
    public function create(array $input): array
    {
        $record = $this->normalize($input);
        $validation = $this->validateRecord($record);
        if (!$validation['valid']) return $this->blocked($validation['reason']);
        $path = $this->recordPath((string)$record['approval_key']);
        $existing = $this->read((string)$record['approval_key']);
        if ($existing !== []) {
            if (($existing['record_hash'] ?? '') === ($record['record_hash'] ?? '')) {
                return ['accepted' => true, 'status' => 'CG_APPROVAL_ALREADY_EXISTS', 'reason' => '', 'record' => $existing];
            }
            return $this->blocked('CG_APPROVAL_CONFLICT', $existing);
        }
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) return $this->blocked('CG_APPROVAL_DIRECTORY_UNAVAILABLE');
        $handle = @fopen($path, 'x');
        if (!is_resource($handle)) {
            $existing = $this->read((string)$record['approval_key']);
            if (($existing['record_hash'] ?? '') === ($record['record_hash'] ?? '')) {
                return ['accepted' => true, 'status' => 'CG_APPROVAL_ALREADY_EXISTS', 'reason' => '', 'record' => $existing];
            }
            return $this->blocked('CG_APPROVAL_CREATE_CONFLICT', $existing);
        }
        $ok = @fwrite($handle, OfficialEpochPolicyV1::canonicalJson($record) . "\n") !== false;
        @fclose($handle);
        if (!$ok) return $this->blocked('CG_APPROVAL_WRITE_FAILED');
        return ['accepted' => true, 'status' => 'CG_APPROVAL_CREATED', 'reason' => '', 'record' => $record];
    }

    /**
     * Checks both hash integrity and binding to a read-only preflight.
     * @param array<string,mixed> $record
     * @param array<string,mixed> $binding
     * @return array{valid:bool,status:string,reason:string}
     */
    public function validateForPreflight(array $record, array $binding): array
    {
        $base = $this->validateRecord($record);
        if (!$base['valid']) return $base;
        foreach ([
            'source_window_id', 'source_closure_hash', 'target_capture_measurement_identity', 'epoch_policy_identity',
            'incident_policy_identity',
        ] as $field) {
            if (!hash_equals((string)($binding[$field] ?? ''), (string)($record[$field] ?? ''))) {
                return ['valid' => false, 'status' => 'CG_APPROVAL_BLOCKED', 'reason' => 'CG_APPROVAL_' . strtoupper($field) . '_MISMATCH'];
            }
        }
        foreach (['operational_live_acceptance', 'numeric_quality_acceptance', 'comparative_limitations_acceptance'] as $field) {
            if (empty($record[$field]['accepted'])) {
                return ['valid' => false, 'status' => 'CG_APPROVAL_BLOCKED', 'reason' => 'CG_APPROVAL_' . strtoupper($field) . '_NOT_ACCEPTED'];
            }
        }
        return ['valid' => true, 'status' => 'CG_APPROVAL_VALID', 'reason' => ''];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function normalize(array $input): array
    {
        $statement = trim((string)($input['approval_statement'] ?? ''));
        $keyMaterial = [
            'schema_version' => self::SCHEMA_VERSION,
            'approver_authority' => trim((string)($input['approver_authority'] ?? '')),
            'approver_stable_identifier' => trim((string)($input['approver_stable_identifier'] ?? '')),
            'source_window_id' => trim((string)($input['source_window_id'] ?? '')),
            'source_closure_hash' => trim((string)($input['source_closure_hash'] ?? '')),
            'target_capture_measurement_identity' => trim((string)($input['target_capture_measurement_identity'] ?? '')),
            'epoch_policy_identity' => trim((string)($input['epoch_policy_identity'] ?? '')),
            'incident_policy_identity' => trim((string)($input['incident_policy_identity'] ?? OfficialEpochIncidentPolicyV1::identity())),
            'approval_statement_hash' => 'sha256:' . hash('sha256', $statement),
        ];
        $record = [
            'schema_version' => self::SCHEMA_VERSION,
            'approval_key' => 'sha256:' . hash('sha256', OfficialEpochPolicyV1::canonicalJson($keyMaterial)),
            'approved' => ($input['approved'] ?? false) === true,
            'approved_at' => (string)($input['approved_at'] ?? ''),
            'approver_authority' => $keyMaterial['approver_authority'],
            'approver_stable_identifier' => $keyMaterial['approver_stable_identifier'],
            'source_window_id' => $keyMaterial['source_window_id'],
            'source_closure_ref' => trim((string)($input['source_closure_ref'] ?? '')),
            'source_closure_hash' => $keyMaterial['source_closure_hash'],
            'target_capture_measurement_identity' => $keyMaterial['target_capture_measurement_identity'],
            'epoch_policy_identity' => $keyMaterial['epoch_policy_identity'],
            'incident_policy_schema' => trim((string)($input['incident_policy_schema'] ?? OfficialEpochIncidentPolicyV1::SCHEMA_VERSION)),
            'incident_policy_identity' => $keyMaterial['incident_policy_identity'],
            'operational_live_acceptance' => is_array($input['operational_live_acceptance'] ?? null) ? $input['operational_live_acceptance'] : [],
            'numeric_quality_acceptance' => is_array($input['numeric_quality_acceptance'] ?? null) ? $input['numeric_quality_acceptance'] : [],
            'comparative_limitations_acceptance' => is_array($input['comparative_limitations_acceptance'] ?? null) ? $input['comparative_limitations_acceptance'] : [],
            'approval_statement' => $statement,
            'approval_statement_hash' => $keyMaterial['approval_statement_hash'],
        ];
        $record['record_hash'] = 'sha256:' . hash('sha256', OfficialEpochPolicyV1::canonicalJson($record));
        return $record;
    }

    /** @param array<string,mixed> $record @return array{valid:bool,status:string,reason:string} */
    public function validateRecord(array $record): array
    {
        if (($record['schema_version'] ?? '') !== self::SCHEMA_VERSION) return ['valid' => false, 'status' => 'CG_APPROVAL_BLOCKED', 'reason' => 'CG_APPROVAL_SCHEMA_INVALID'];
        foreach (['approval_key', 'source_closure_hash', 'target_capture_measurement_identity', 'epoch_policy_identity', 'incident_policy_identity', 'approval_statement_hash', 'record_hash'] as $field) {
            if (!preg_match('/^sha256:[a-f0-9]{64}$/', (string)($record[$field] ?? ''))) return ['valid' => false, 'status' => 'CG_APPROVAL_BLOCKED', 'reason' => 'CG_APPROVAL_HASH_INVALID_' . strtoupper($field)];
        }
        if (($record['incident_policy_schema'] ?? '') !== OfficialEpochIncidentPolicyV1::SCHEMA_VERSION
            || !hash_equals(OfficialEpochIncidentPolicyV1::identity(), (string)($record['incident_policy_identity'] ?? ''))) {
            return ['valid' => false, 'status' => 'CG_APPROVAL_BLOCKED', 'reason' => 'CG_APPROVAL_INCIDENT_POLICY_INVALID'];
        }
        foreach (['approver_authority', 'approver_stable_identifier', 'source_window_id', 'source_closure_ref', 'approval_statement'] as $field) {
            if (trim((string)($record[$field] ?? '')) === '') return ['valid' => false, 'status' => 'CG_APPROVAL_BLOCKED', 'reason' => 'CG_APPROVAL_FIELD_MISSING_' . strtoupper($field)];
        }
        if (($record['approved'] ?? false) !== true) return ['valid' => false, 'status' => 'CG_APPROVAL_BLOCKED', 'reason' => 'CG_APPROVAL_NOT_APPROVED'];
        try { new DateTimeImmutable((string)($record['approved_at'] ?? '')); } catch (Throwable) { return ['valid' => false, 'status' => 'CG_APPROVAL_BLOCKED', 'reason' => 'CG_APPROVAL_TIMESTAMP_INVALID']; }
        if (!hash_equals('sha256:' . hash('sha256', (string)$record['approval_statement']), (string)$record['approval_statement_hash'])) return ['valid' => false, 'status' => 'CG_APPROVAL_BLOCKED', 'reason' => 'CG_APPROVAL_STATEMENT_HASH_INVALID'];
        $material = [
            'schema_version' => self::SCHEMA_VERSION,
            'approver_authority' => (string)$record['approver_authority'], 'approver_stable_identifier' => (string)$record['approver_stable_identifier'],
            'source_window_id' => (string)$record['source_window_id'], 'source_closure_hash' => (string)$record['source_closure_hash'],
            'target_capture_measurement_identity' => (string)$record['target_capture_measurement_identity'], 'epoch_policy_identity' => (string)$record['epoch_policy_identity'],
            'incident_policy_identity' => (string)$record['incident_policy_identity'],
            'approval_statement_hash' => (string)$record['approval_statement_hash'],
        ];
        if (!hash_equals('sha256:' . hash('sha256', OfficialEpochPolicyV1::canonicalJson($material)), (string)$record['approval_key'])) return ['valid' => false, 'status' => 'CG_APPROVAL_BLOCKED', 'reason' => 'CG_APPROVAL_KEY_INVALID'];
        $payload = $record; unset($payload['record_hash']);
        if (!hash_equals('sha256:' . hash('sha256', OfficialEpochPolicyV1::canonicalJson($payload)), (string)$record['record_hash'])) return ['valid' => false, 'status' => 'CG_APPROVAL_BLOCKED', 'reason' => 'CG_APPROVAL_RECORD_HASH_INVALID'];
        return ['valid' => true, 'status' => 'CG_APPROVAL_VALID', 'reason' => ''];
    }

    /** @param array<string,mixed> $existing @return array{accepted:bool,status:string,reason:string,record:array<string,mixed>} */
    private function blocked(string $reason, array $existing = []): array
    {
        return ['accepted' => false, 'status' => 'CG_APPROVAL_BLOCKED', 'reason' => $reason, 'record' => $existing];
    }
}
