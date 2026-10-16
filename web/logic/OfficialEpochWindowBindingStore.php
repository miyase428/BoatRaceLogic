<?php

declare(strict_types=1);

require_once __DIR__ . '/OfficialEpochWindowBindingV1.php';
require_once __DIR__ . '/OfficialEpochStartStore.php';
require_once __DIR__ . '/OfficialEpochIncidentLifecycleStore.php';
require_once __DIR__ . '/OfficialEpochIncidentPolicyV1.php';
require_once __DIR__ . '/FormalMeasurementWindowContext.php';
require_once __DIR__ . '/OfficialEpochSameEpochSuccessorValidator.php';

/**
 * External append-only store.  A binding never changes Window evidence and is
 * serialized with the exact global lock used by Epoch incident transitions.
 */
final class OfficialEpochWindowBindingStore
{
    public function __construct(private readonly string $repoRoot, private readonly ?string $externalLifecycleRoot = null) {}

    public function lifecycleRoot(): string
    {
        return $this->externalLifecycleRoot ?? rtrim($this->repoRoot, DIRECTORY_SEPARATOR) . '/var/strict_forward_live_readiness/lifecycle';
    }

    public function bindingPath(string $windowId): string
    {
        return $this->lifecycleRoot() . '/official_epoch_window_bindings/' . $windowId . '.json';
    }

    /** @return array<string,mixed> */
    public function bindingForWindow(string $windowId): array
    {
        $path = $this->bindingPath($windowId);
        if (!is_file($path)) return [];
        try { $row = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); } catch (Throwable) { return []; }
        return is_array($row) && OfficialEpochWindowBindingV1::validRecordHash($row) ? $row : [];
    }

    /** @return list<array<string,mixed>> */
    public function windowsForEpoch(string $epochId): array
    {
        $rows = [];
        foreach (glob($this->lifecycleRoot() . '/official_epoch_window_bindings/*.json') ?: [] as $path) {
            try { $row = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); } catch (Throwable) { continue; }
            if (is_array($row) && OfficialEpochWindowBindingV1::validRecordHash($row) && ($row['epoch_id'] ?? '') === $epochId) $rows[] = $row;
        }
        usort($rows, static fn(array $a, array $b): int => strcmp((string)$a['window_id'], (string)$b['window_id']));
        return $rows;
    }

    /**
     * Builds and commits a binding only after revalidating all mutable inputs
     * under the shared Epoch lock. Validation inputs are hashed evidence
     * attestations supplied by the future Window preflight.
     *
     * @param array<string,mixed> $validation
     * @return array<string,mixed>
     */
    public function createForPrepared(string $windowId, array $validation): array
    {
        return $this->incidentStore()->withGlobalEpochLock(function () use ($windowId, $validation): array {
            $preflight = $this->preflightPrepared($windowId, $validation);
            if (empty($preflight['accepted'])) return $preflight;
            $record = $preflight['record'];
            $path = $this->bindingPath($windowId);
            if (is_file($path)) {
                $existing = $this->bindingForWindow($windowId);
                if ($existing !== [] && $this->sameBindingIntent($existing, $record)) {
                    return ['accepted' => true, 'status' => 'OFFICIAL_EPOCH_WINDOW_BINDING_ALREADY_EXISTS', 'reason' => '', 'record' => $existing];
                }
                return $this->blocked('OFFICIAL_EPOCH_WINDOW_BINDING_CONFLICT');
            }
            $dir = dirname($path);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return $this->blocked('OFFICIAL_EPOCH_WINDOW_BINDING_DIRECTORY_UNAVAILABLE');
            $handle = @fopen($path, 'x');
            if (!is_resource($handle)) return $this->blocked('OFFICIAL_EPOCH_WINDOW_BINDING_CREATE_CONFLICT');
            $ok = @fwrite($handle, OfficialEpochPolicyV1::canonicalJson($record) . "\n") !== false;
            @fclose($handle);
            return $ok
                ? ['accepted' => true, 'status' => 'OFFICIAL_EPOCH_WINDOW_BINDING_CREATED', 'reason' => '', 'record' => $record]
                : $this->blocked('OFFICIAL_EPOCH_WINDOW_BINDING_WRITE_FAILED');
        });
    }

    /** @param array<string,mixed> $validation @return array<string,mixed> */
    public function preflightPrepared(string $windowId, array $validation): array
    {
        if (preg_match('/^[A-Za-z0-9._-]+$/', $windowId) !== 1) return $this->blocked('OFFICIAL_EPOCH_WINDOW_BINDING_WINDOW_ID_INVALID');
        $epoch = (new OfficialEpochStartStore($this->lifecycleRoot()))->existing();
        if ($epoch === []) return $this->blocked('OFFICIAL_EPOCH_WINDOW_BINDING_EPOCH_NOT_FOUND');
        $projection = $this->incidentStore()->project((string)$epoch['epoch_id']);
        if (empty($projection['valid']) || ($projection['state'] ?? '') !== 'ACTIVE') return $this->blocked('OFFICIAL_EPOCH_WINDOW_BINDING_EPOCH_NOT_ACTIVE');
        if (!OfficialEpochWindowBindingV1::validHash((string)($epoch['record_hash'] ?? ''))
            || !OfficialEpochWindowBindingV1::validHash((string)($projection['state_hash'] ?? ''))
            || !hash_equals(OfficialEpochIncidentPolicyV1::identity(), (string)($epoch['incident_policy_identity'] ?? ''))) {
            return $this->blocked('OFFICIAL_EPOCH_WINDOW_BINDING_EPOCH_PROVENANCE_INVALID');
        }
        $root = rtrim($this->repoRoot, DIRECTORY_SEPARATOR) . '/var/strict_forward_live_readiness/windows/' . $windowId;
        if (!is_dir($root)) return $this->blocked('OFFICIAL_EPOCH_WINDOW_BINDING_WINDOW_ROOT_MISSING');
        $context = FormalMeasurementWindowContext::fromWindowRoot($this->repoRoot, $root);
        $resolved = $context->resolve(true);
        if (empty($resolved['accepted'])) return $this->blocked('OFFICIAL_EPOCH_WINDOW_BINDING_CONTEXT_INVALID:' . (string)($resolved['reason'] ?? ''));
        $manifest = $resolved['manifest'];
        $declaration = $context->declaration()->read(true);
        if (empty($declaration['accepted'])) return $this->blocked('OFFICIAL_EPOCH_WINDOW_BINDING_DECLARATION_INVALID');
        if (($manifest['activation_state'] ?? '') !== 'PREPARED' || ($manifest['status'] ?? '') !== 'PREPARED' || !empty($manifest['armed_at'])) return $this->blocked('OFFICIAL_EPOCH_WINDOW_BINDING_WINDOW_NOT_PREPARED');
        if ($context->activationHold()->readActivation() !== []) return $this->blocked('OFFICIAL_EPOCH_WINDOW_BINDING_ACTIVATION_EXISTS');
        $lifecycle = $context->lifecycle()->effectiveState($windowId, $manifest);
        if (!empty($lifecycle['closure']) || !empty($lifecycle['abort'])) return $this->blocked('OFFICIAL_EPOCH_WINDOW_BINDING_TERMINAL_RECORD_EXISTS');
        if ($this->formalEvidenceCount($root) !== 0) return $this->blocked('OFFICIAL_EPOCH_WINDOW_BINDING_FORMAL_EVIDENCE_PRESENT');

        $declarationRow = $declaration['declaration'];
        $implementation = $declaration['implementation'];
        $manifestPath = $root . '/validation_window_manifest.json';
        $declarationPath = $context->declaration()->declarationPath();
        $manifestHash = $this->fileHash($manifestPath);
        $declarationHash = $this->fileHash($declarationPath);
        if ($manifestHash === '' || $declarationHash === '') return $this->blocked('OFFICIAL_EPOCH_WINDOW_BINDING_WINDOW_ARTIFACT_MISSING');
        foreach (['implementation_identity', 'manifest_sha256', 'source_freeze_sha256'] as $field) {
            if (!hash_equals((string)($declarationRow[$field] ?? ''), (string)($implementation[$field] ?? ''))) return $this->blocked('OFFICIAL_EPOCH_WINDOW_BINDING_DECLARATION_IMPLEMENTATION_MISMATCH');
        }
        $evaluation = (new OfficialEpochSameEpochSuccessorValidator($this->repoRoot))->evaluate($windowId, $epoch, $validation);
        if (empty($evaluation['accepted'])) return $this->blocked('OFFICIAL_EPOCH_WINDOW_BINDING_VALIDATOR_INVALID:' . (string)$evaluation['reason']);
        $requirements = $evaluation['requirements'];
        foreach ($requirements as $status) if ($status !== 'PASS') return $this->blocked('OFFICIAL_EPOCH_WINDOW_BINDING_SUCCESSOR_REQUIREMENT_NOT_PASS');

        $refs = is_array($evaluation['package']['validation_evidence_refs'] ?? null) ? $evaluation['package']['validation_evidence_refs'] : [];
        $hashes = is_array($evaluation['package']['validation_evidence_hashes'] ?? null) ? $evaluation['package']['validation_evidence_hashes'] : [];
        foreach (OfficialEpochWindowBindingV1::requirementNames() as $name) {
            if (!is_string($refs[$name] ?? null) || $refs[$name] === '' || !OfficialEpochWindowBindingV1::validHash((string)($hashes[$name] ?? ''))) {
                return $this->blocked('OFFICIAL_EPOCH_WINDOW_BINDING_EVIDENCE_REFERENCE_INVALID');
            }
        }
        $relative = fn(string $path): string => ltrim(substr($path, strlen(rtrim($this->repoRoot, DIRECTORY_SEPARATOR))), DIRECTORY_SEPARATOR);
        $stable = [
            'schema_version' => OfficialEpochWindowBindingV1::SCHEMA_VERSION,
            'window_id' => $windowId,
            'window_declaration_hash' => $declarationHash,
            'declared_measurement_identity' => (string)$implementation['implementation_identity'],
            'measurement_manifest_hash' => (string)$implementation['manifest_sha256'],
            'measurement_source_freeze_hash' => (string)$implementation['source_freeze_sha256'],
            'epoch_id' => (string)$epoch['epoch_id'],
            'epoch_start_record_hash' => (string)$epoch['record_hash'],
            'epoch_policy_identity' => (string)$epoch['epoch_policy_identity'],
            'incident_policy_identity' => (string)$epoch['incident_policy_identity'],
            'successor_validation_hash' => OfficialEpochWindowBindingV1::hash(['requirements' => $requirements, 'evidence_hashes' => $hashes]),
        ];
        $record = [
            'schema_version' => OfficialEpochWindowBindingV1::SCHEMA_VERSION,
            'binding_key' => OfficialEpochWindowBindingV1::bindingKey($stable),
            'window_id' => $windowId,
            'window_manifest_ref' => $relative($manifestPath),
            'window_manifest_hash' => $manifestHash,
            'prepared_at' => (string)($manifest['prepared_at'] ?? ''),
            'window_declaration_ref' => $relative($declarationPath),
            'window_declaration_hash' => $declarationHash,
            'declared_measurement_identity' => (string)$implementation['implementation_identity'],
            'measurement_manifest_ref' => (string)$implementation['manifest_ref'],
            'measurement_manifest_hash' => (string)$implementation['manifest_sha256'],
            'measurement_source_freeze_ref' => (string)$implementation['source_freeze_ref'],
            'measurement_source_freeze_hash' => (string)$implementation['source_freeze_sha256'],
            'prospective_boundary_contract' => OfficialEpochWindowBindingV1::PROSPECTIVE_BOUNDARY_CONTRACT,
            'epoch_id' => (string)$epoch['epoch_id'],
            'epoch_start_record_ref' => 'lifecycle/official_epochs/' . (string)$epoch['epoch_id'] . '/start.json',
            'epoch_start_record_hash' => (string)$epoch['record_hash'],
            'epoch_state_at_binding' => 'ACTIVE',
            'epoch_state_projection_hash' => (string)$projection['state_hash'],
            'epoch_policy_identity' => (string)$epoch['epoch_policy_identity'],
            'incident_policy_identity' => (string)$epoch['incident_policy_identity'],
            'successor_semantics_classification' => OfficialEpochWindowBindingV1::SEMANTICS_EQUIVALENT,
            'same_epoch_successor_requirements' => OfficialEpochWindowBindingV1::requirementNames(),
            'same_epoch_successor_validation_result' => $requirements,
            'validation_evidence_refs' => $refs,
            'validation_evidence_hashes' => $hashes,
            'same_epoch_successor_validation_package_hash' => (string)($evaluation['package']['validation_package_hash'] ?? ''),
            'created_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM),
        ];
        $record['record_hash'] = OfficialEpochWindowBindingV1::hash($record);
        return ['accepted' => true, 'status' => 'OFFICIAL_EPOCH_WINDOW_BINDING_ELIGIBLE', 'reason' => '', 'record' => $record];
    }

    private function incidentStore(): OfficialEpochIncidentLifecycleStore { return new OfficialEpochIncidentLifecycleStore($this->lifecycleRoot()); }
    /** @param array<string,mixed> $a @param array<string,mixed> $b */
    private function sameBindingIntent(array $a, array $b): bool
    {
        unset($a['record_hash'], $a['created_at'], $b['record_hash'], $b['created_at']);
        return OfficialEpochWindowBindingV1::hash($a) === OfficialEpochWindowBindingV1::hash($b);
    }
    private function fileHash(string $path): string { $bytes = @file_get_contents($path); return is_string($bytes) ? 'sha256:' . hash('sha256', $bytes) : ''; }
    private function formalEvidenceCount(string $root): int
    {
        $count = 0;
        foreach (['ledger', 'opportunity', 'input_snapshots', 'comparative_pairs'] as $dir) {
            $path = $root . '/' . $dir;
            if (!is_dir($path)) continue;
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) if ($file->isFile() && str_ends_with($file->getFilename(), '.json')) $count++;
        }
        return $count;
    }
    /** @return array<string,mixed> */ private function blocked(string $reason): array { return ['accepted' => false, 'status' => 'OFFICIAL_EPOCH_WINDOW_BINDING_BLOCKED', 'reason' => $reason, 'record' => []]; }
}
