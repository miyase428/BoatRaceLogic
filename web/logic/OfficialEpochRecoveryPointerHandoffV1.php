<?php

declare(strict_types=1);

require_once __DIR__ . '/FormalMeasurementWindowContext.php';
require_once __DIR__ . '/FormalMeasurementWindowLifecycle.php';
require_once __DIR__ . '/MeasurementImplementationProvenance.php';
require_once __DIR__ . '/OfficialEpochIncidentLifecycleStore.php';
require_once __DIR__ . '/OfficialEpochIncidentPolicyV1.php';
require_once __DIR__ . '/OfficialEpochPolicyV1.php';
require_once __DIR__ . '/OfficialEpochRecoveryLifecycleV1.php';
require_once __DIR__ . '/OfficialEpochStartStore.php';
require_once __DIR__ . '/OfficialEpochWindowBindingResolver.php';
require_once __DIR__ . '/OfficialEpochWindowBindingV1.php';

/**
 * The only recovery-specific route from a superseded PREPARED pointer target
 * to a fresh, normally-bound PREPARED successor.
 *
 * Normal FormalMeasurementWindowLifecycle::handoffPointerCas() deliberately
 * remains unable to move a superseded source.  This class establishes a
 * complete recovery authorization before using that lifecycle's narrow,
 * lock-protected atomic pointer primitive.
 */
final class OfficialEpochRecoveryPointerHandoffV1
{
    public const SCHEMA_VERSION = 'official-epoch-recovery-pointer-handoff/v1';
    public const INTENT_PHASE = 'AUTHORIZED';
    public const APPLIED_PHASE = 'APPLIED';

    public function __construct(
        private readonly string $repoRoot,
        private readonly ?string $externalLifecycleRoot = null,
    ) {}

    public function lifecycleRoot(): string
    {
        return $this->externalLifecycleRoot ?? rtrim($this->repoRoot, DIRECTORY_SEPARATOR) . '/var/strict_forward_live_readiness/lifecycle';
    }

    public function intentPath(string $key): string
    {
        return $this->lifecycleRoot() . '/official_epoch_recovery_pointer_handoffs/intents/' . substr($this->idFromHash($key), 0, 64) . '.json';
    }

    public function appliedPath(string $key): string
    {
        return $this->lifecycleRoot() . '/official_epoch_recovery_pointer_handoffs/applied/' . substr($this->idFromHash($key), 0, 64) . '.json';
    }

    /** Pure read-only eligibility check. @param array<string,mixed> $input @return array<string,mixed> */
    public function preflight(array $input): array
    {
        $sourceId = trim((string)($input['source_window_id'] ?? ''));
        $targetId = trim((string)($input['target_window_id'] ?? ''));
        if (!$this->validWindowId($sourceId) || !$this->validWindowId($targetId) || $sourceId === $targetId) return $this->blocked('RECOVERY_HANDOFF_WINDOW_ID_INVALID');
        if (empty($input['scheduler_held'])) return $this->blocked('RECOVERY_HANDOFF_SCHEDULER_NOT_HELD');
        if ((int)($input['capture_process_count'] ?? -1) !== 0) return $this->blocked('RECOVERY_HANDOFF_CAPTURE_PROCESS_RUNNING');
        if (empty($input['production_routing_unchanged'])) return $this->blocked('RECOVERY_HANDOFF_PRODUCTION_ROUTING_CHANGED');

        $pointer = $this->readPointer();
        if ($pointer === [] || !FormalMeasurementWindowContext::validPointerPayload($pointer)) return $this->blocked('RECOVERY_HANDOFF_POINTER_INVALID');
        if (!hash_equals($sourceId, (string)$pointer['window_id'])) {
            return $this->blocked((string)$pointer['window_id'] === $targetId ? 'RECOVERY_HANDOFF_ALREADY_TARGET_OR_COMPLETION_REQUIRED' : 'RECOVERY_HANDOFF_EXPECTED_CURRENT_POINTER_MISMATCH');
        }
        $pointerBytes = @file_get_contents($this->pointerPath());
        $pointerHash = is_string($pointerBytes) ? 'sha256:' . hash('sha256', $pointerBytes) : '';
        if (is_string($input['expected_pointer_hash'] ?? null) && $input['expected_pointer_hash'] !== '' && !hash_equals((string)$input['expected_pointer_hash'], $pointerHash)) {
            return $this->blocked('RECOVERY_HANDOFF_EXPECTED_POINTER_HASH_MISMATCH');
        }

        $epoch = (new OfficialEpochStartStore($this->lifecycleRoot()))->existing();
        if ($epoch === []) return $this->blocked('RECOVERY_HANDOFF_EPOCH_NOT_FOUND');
        $projection = (new OfficialEpochIncidentLifecycleStore($this->lifecycleRoot()))->project((string)$epoch['epoch_id']);
        if (empty($projection['valid']) || ($projection['state'] ?? '') !== 'ACTIVE') return $this->blocked('RECOVERY_HANDOFF_EPOCH_NOT_ACTIVE');
        if (!hash_equals(OfficialEpochPolicyV1::identity(), (string)($epoch['epoch_policy_identity'] ?? ''))
            || !hash_equals(OfficialEpochIncidentPolicyV1::identity(), (string)($epoch['incident_policy_identity'] ?? ''))) return $this->blocked('RECOVERY_HANDOFF_POLICY_IDENTITY_MISMATCH');
        if ($this->hasEndRecord((string)$epoch['epoch_id'])) return $this->blocked('RECOVERY_HANDOFF_EPOCH_ENDED');

        $source = $this->preparedWindow($sourceId);
        if (empty($source['accepted'])) return $this->blocked('RECOVERY_HANDOFF_SOURCE_INVALID:' . (string)$source['reason']);
        $recovery = new OfficialEpochRecoveryLifecycleV1($this->repoRoot, $this->lifecycleRoot());
        $supersession = $recovery->supersessionForWindow($sourceId);
        if ($supersession === []) return $this->blocked('RECOVERY_HANDOFF_SOURCE_NOT_SUPERSEDED');
        if (!hash_equals($sourceId, (string)($supersession['window_id'] ?? ''))) return $this->blocked('RECOVERY_HANDOFF_SUPERSESSION_WINDOW_MISMATCH');

        $target = $this->preparedWindow($targetId);
        if (empty($target['accepted'])) return $this->blocked('RECOVERY_HANDOFF_TARGET_INVALID:' . (string)$target['reason']);
        if ($recovery->supersessionForWindow($targetId) !== []) return $this->blocked('RECOVERY_HANDOFF_TARGET_SUPERSEDED');
        $bindingCheck = (new OfficialEpochWindowBindingResolver($this->repoRoot, $this->lifecycleRoot()))->validateBinding($targetId, ['require_prepared' => true]);
        if (empty($bindingCheck['accepted'])) return $this->blocked('RECOVERY_HANDOFF_TARGET_BINDING_INVALID:' . (string)$bindingCheck['reason']);
        $binding = $bindingCheck['binding'];
        if (!hash_equals((string)$epoch['epoch_id'], (string)($binding['epoch_id'] ?? ''))
            || !hash_equals((string)$projection['state_hash'], (string)($binding['epoch_state_projection_hash'] ?? ''))) return $this->blocked('RECOVERY_HANDOFF_TARGET_BINDING_EPOCH_PROJECTION_STALE');
        $currentIdentity = (new MeasurementImplementationProvenance($this->repoRoot, $this->repoRoot . '/var/strict_forward_live_readiness/recovery_pointer_readonly'))->currentIdentityReadOnly();
        if (is_string($input['current_measurement_identity'] ?? null) && $input['current_measurement_identity'] !== '' && !hash_equals((string)$input['current_measurement_identity'], $currentIdentity)) return $this->blocked('RECOVERY_HANDOFF_CURRENT_IDENTITY_INPUT_STALE');
        if (!hash_equals($currentIdentity, (string)$target['identity']) || !hash_equals($currentIdentity, (string)($binding['declared_measurement_identity'] ?? ''))) {
            return $this->blocked('TARGET_MEASUREMENT_IDENTITY_STALE');
        }
        foreach (OfficialEpochWindowBindingV1::requirementNames() as $name) if (($binding['same_epoch_successor_validation_result'][$name] ?? '') !== 'PASS') return $this->blocked('RECOVERY_HANDOFF_TARGET_9_OF_9_NOT_PASS');
        if (!OfficialEpochWindowBindingV1::validHash((string)($binding['same_epoch_successor_validation_package_hash'] ?? ''))) return $this->blocked('RECOVERY_HANDOFF_TARGET_VALIDATION_PACKAGE_INVALID');

        $chain = $this->recoveryChain((string)$epoch['epoch_id']);
        if (empty($chain['accepted'])) return $this->blocked((string)$chain['reason']);
        $targetPointer = ['schema_version' => FormalMeasurementWindowContext::POINTER_SCHEMA_VERSION, 'window_id' => $targetId, 'window_root' => 'var/strict_forward_live_readiness/windows/' . $targetId];
        $intent = $this->intentPayload($epoch, $projection, $source, $supersession, $target, $binding, $chain, $pointer, $pointerHash, $targetPointer, $currentIdentity, (string)($input['created_at'] ?? ''));
        return ['accepted' => true, 'status' => 'RECOVERY_HANDOFF_ELIGIBLE', 'reason' => '', 'intent' => $intent, 'pointer' => $pointer, 'target_pointer' => $targetPointer, 'epoch' => $epoch, 'projection' => $projection, 'current_measurement_identity' => $currentIdentity];
    }

    /** Creates an intent, CASes the pointer, then creates an applied record. @param array<string,mixed> $input @return array<string,mixed> */
    public function execute(array $input): array
    {
        return (new OfficialEpochIncidentLifecycleStore($this->lifecycleRoot()))->withGlobalEpochLock(function () use ($input): array {
            $completed = $this->completeIfPointerAlreadyMoved($input);
            if ($completed !== null) return $completed;
            $preflight = $this->preflight($input);
            if (empty($preflight['accepted'])) return $preflight;
            $intent = $preflight['intent'];
            $intentWrite = $this->createOnly($this->intentPath((string)$intent['handoff_key']), $intent, 'RECOVERY_HANDOFF_INTENT');
            if (empty($intentWrite['accepted'])) return $intentWrite;
            $cas = (new FormalMeasurementWindowLifecycle($this->repoRoot))->compareAndSwapPointerPayload($this->pointerPath(), $preflight['pointer'], $preflight['target_pointer']);
            if (empty($cas['accepted'])) return $this->blocked('RECOVERY_HANDOFF_CAS_FAILED:' . (string)$cas['reason']);
            $after = $this->readPointer();
            if ($after === [] || $this->canonicalJson($after) !== $this->canonicalJson($preflight['target_pointer'])) return $this->blocked('RECOVERY_HANDOFF_POST_CAS_READBACK_FAILED');
            $applied = $this->appliedPayload($intent, (string)$cas['before_hash'], (string)$cas['after_hash'], (string)($input['applied_at'] ?? ''));
            $appliedWrite = $this->createOnly($this->appliedPath((string)$intent['handoff_key']), $applied, 'RECOVERY_HANDOFF_APPLIED');
            if (empty($appliedWrite['accepted'])) return $appliedWrite;
            return ['accepted' => true, 'status' => 'RECOVERY_HANDOFF_APPLIED', 'reason' => '', 'intent' => $intentWrite['record'], 'applied' => $appliedWrite['record']];
        });
    }

    /**
     * Crash recovery is intentionally narrow: only an existing valid intent
     * for exactly this source/target can finalize an already-moved pointer.
     * An arbitrary pointer at the target never becomes self-authorizing.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>|null null means the ordinary source-side flow must run
     */
    private function completeIfPointerAlreadyMoved(array $input): ?array
    {
        $source = (string)($input['source_window_id'] ?? ''); $target = (string)($input['target_window_id'] ?? '');
        $pointer = $this->readPointer();
        if ($pointer === [] || (string)($pointer['window_id'] ?? '') !== $target) return null;
        $intent = $this->findIntentFor($source, $target);
        if ($intent === []) return $this->blocked('RECOVERY_HANDOFF_POINTER_ALREADY_TARGET_WITHOUT_INTENT');
        $targetPointer = $intent['expected_pointer_after'] ?? [];
        if (!is_array($targetPointer) || $this->canonicalJson($pointer) !== $this->canonicalJson($targetPointer)) return $this->blocked('RECOVERY_HANDOFF_POINTER_TARGET_PAYLOAD_MISMATCH');
        $existing = $this->readApplied((string)$intent['handoff_key']);
        if ($existing !== []) {
            if (!hash_equals((string)($existing['intent_hash'] ?? ''), (string)$intent['record_hash'])) return $this->blocked('RECOVERY_HANDOFF_APPLIED_INTENT_MISMATCH');
            return ['accepted' => true, 'status' => 'RECOVERY_HANDOFF_ALREADY_APPLIED', 'reason' => '', 'intent' => $intent, 'applied' => $existing];
        }
        $afterBytes = @file_get_contents($this->pointerPath());
        $afterHash = is_string($afterBytes) ? 'sha256:' . hash('sha256', $afterBytes) : '';
        $applied = $this->appliedPayload($intent, (string)($intent['expected_pointer_before_hash'] ?? ''), $afterHash, (string)($input['applied_at'] ?? ''));
        $write = $this->createOnly($this->appliedPath((string)$intent['handoff_key']), $applied, 'RECOVERY_HANDOFF_APPLIED');
        if (empty($write['accepted'])) return $write;
        return ['accepted' => true, 'status' => 'RECOVERY_HANDOFF_COMPLETION_RECOVERED', 'reason' => '', 'intent' => $intent, 'applied' => $write['record']];
    }

    /** @return array<string,mixed> */
    public function readIntent(string $key): array { return $this->readValid($this->intentPath($key)); }
    /** @return array<string,mixed> */
    public function readApplied(string $key): array { return $this->readValid($this->appliedPath($key)); }

    /** @return array<string,mixed> */
    private function preparedWindow(string $windowId): array
    {
        $root = rtrim($this->repoRoot, '/') . '/var/strict_forward_live_readiness/windows/' . $windowId;
        if (!is_dir($root)) return ['accepted' => false, 'reason' => 'WINDOW_ROOT_MISSING'];
        $context = FormalMeasurementWindowContext::fromWindowRoot($this->repoRoot, $root);
        $resolved = $context->resolve(true);
        $declaration = $context->declaration()->read(true);
        if (empty($resolved['accepted']) || empty($declaration['accepted'])) return ['accepted' => false, 'reason' => 'WINDOW_CONTEXT_INVALID'];
        $manifest = $resolved['manifest'];
        $state = $context->lifecycle()->effectiveState($windowId, $manifest);
        if (($state['effective_state'] ?? '') !== 'PREPARED' || ($manifest['status'] ?? '') !== 'PREPARED' || ($manifest['activation_state'] ?? '') !== 'PREPARED' || !empty($manifest['armed_at'])) return ['accepted' => false, 'reason' => 'WINDOW_NOT_PRISTINE_PREPARED'];
        if ($context->activationHold()->readActivation() !== []) return ['accepted' => false, 'reason' => 'WINDOW_ACTIVATION_EXISTS'];
        if (!empty($state['closure']) || !empty($state['abort'])) return ['accepted' => false, 'reason' => 'WINDOW_TERMINAL_RECORD_EXISTS'];
        if ($this->formalEvidenceCount($root) !== 0) return ['accepted' => false, 'reason' => 'WINDOW_FORMAL_EVIDENCE_PRESENT'];
        $impl = $declaration['implementation'];
        return ['accepted' => true, 'root' => $root, 'manifest_hash' => $this->fileHash($root . '/validation_window_manifest.json'), 'declaration_hash' => $this->fileHash($context->declaration()->declarationPath()), 'declaration_ref' => $this->relative($context->declaration()->declarationPath()), 'identity' => (string)($impl['implementation_identity'] ?? ''), 'measurement_manifest_ref' => (string)($impl['manifest_ref'] ?? ''), 'measurement_manifest_hash' => (string)($impl['manifest_sha256'] ?? ''), 'measurement_source_freeze_ref' => (string)($impl['source_freeze_ref'] ?? ''), 'measurement_source_freeze_hash' => (string)($impl['source_freeze_sha256'] ?? '')];
    }

    /** @return array<string,mixed> */
    private function recoveryChain(string $epochId): array
    {
        $incident = $this->findRecord('official_epoch_incidents', fn(array $r): bool => ($r['epoch_id'] ?? '') === $epochId);
        $suspension = $this->findRecord('official_epoch_suspensions', fn(array $r): bool => ($r['epoch_id'] ?? '') === $epochId && ($r['to_state'] ?? '') === 'SUSPENDED');
        $resolution = $this->findRecord('official_epoch_incident_resolutions', fn(array $r): bool => ($r['epoch_id'] ?? '') === $epochId);
        $approval = $this->findRecord('official_epoch_recovery_approvals', fn(array $r): bool => ($r['epoch_id'] ?? '') === $epochId);
        $resume = $this->findRecord('official_epoch_resumes', fn(array $r): bool => ($r['epoch_id'] ?? '') === $epochId && ($r['to_state'] ?? '') === 'ACTIVE');
        if ($incident === [] || $suspension === [] || $resolution === [] || $approval === [] || $resume === []) return ['accepted' => false, 'reason' => 'RECOVERY_HANDOFF_RECOVERY_CHAIN_MISSING'];
        if (!hash_equals((string)($incident['record_hash'] ?? ''), (string)($suspension['incident_hash'] ?? ''))
            || !hash_equals((string)($incident['record_hash'] ?? ''), (string)($resolution['incident_hash'] ?? ''))
            || !hash_equals((string)($resolution['record_hash'] ?? ''), (string)($approval['resolution_hash'] ?? ''))
            || !hash_equals((string)($resolution['record_hash'] ?? ''), (string)($resume['resolution_hash'] ?? ''))
            || !hash_equals((string)($approval['record_hash'] ?? ''), (string)($resume['cg_authorization_hash'] ?? ''))) return ['accepted' => false, 'reason' => 'RECOVERY_HANDOFF_RECOVERY_CHAIN_CROSS_BINDING_MISMATCH'];
        if (!empty($incident['requires_new_epoch']) || !empty($resolution['requires_new_epoch'])) return ['accepted' => false, 'reason' => 'RECOVERY_HANDOFF_NEW_EPOCH_REQUIRED'];
        return ['accepted' => true, 'incident' => $incident, 'suspension' => $suspension, 'resolution' => $resolution, 'approval' => $approval, 'resume' => $resume];
    }

    /** @param array<string,mixed> $epoch @param array<string,mixed> $projection @param array<string,mixed> $source @param array<string,mixed> $supersession @param array<string,mixed> $target @param array<string,mixed> $binding @param array<string,mixed> $chain @param array<string,mixed> $before @param array<string,mixed> $after @return array<string,mixed> */
    private function intentPayload(array $epoch, array $projection, array $source, array $supersession, array $target, array $binding, array $chain, array $before, string $beforeHash, array $after, string $currentIdentity, string $createdAt): array
    {
        $stable = ['schema_version' => self::SCHEMA_VERSION, 'epoch_id' => $epoch['epoch_id'], 'epoch_projection_hash' => $projection['state_hash'], 'source_window_id' => $before['window_id'], 'source_supersession_hash' => $supersession['record_hash'], 'target_window_id' => $after['window_id'], 'target_binding_hash' => $binding['record_hash'], 'target_declaration_hash' => $target['declaration_hash'], 'current_measurement_identity' => $currentIdentity, 'expected_pointer_before_hash' => $beforeHash];
        $key = $this->hash($stable);
        $row = $stable + [
            'handoff_key' => $key, 'phase' => self::INTENT_PHASE, 'epoch_start_record_hash' => (string)$epoch['record_hash'],
            'epoch_start_record_ref' => 'lifecycle/official_epochs/' . (string)$epoch['epoch_id'] . '/start.json',
            'epoch_policy_identity' => (string)$epoch['epoch_policy_identity'], 'incident_policy_identity' => (string)$epoch['incident_policy_identity'],
            'incident_hash' => (string)$chain['incident']['record_hash'], 'incident_ref' => $this->relative($this->recordPath('official_epoch_incidents', (string)$chain['incident']['incident_id'])),
            'suspension_hash' => (string)$chain['suspension']['record_hash'], 'suspension_ref' => $this->relative($this->recordPath('official_epoch_suspensions', (string)$chain['suspension']['transition_id'])),
            'resolution_hash' => (string)$chain['resolution']['record_hash'], 'resolution_ref' => $this->relative($this->recordPath('official_epoch_incident_resolutions', (string)$chain['resolution']['resolution_id'])),
            'cg_recovery_approval_hash' => (string)$chain['approval']['record_hash'], 'cg_recovery_approval_ref' => $this->relative($this->recordPath('official_epoch_recovery_approvals', (string)$chain['approval']['approval_key'])),
            'resume_hash' => (string)$chain['resume']['record_hash'], 'resume_ref' => $this->relative($this->recordPath('official_epoch_resumes', (string)$chain['resume']['transition_id'])),
            'source_declaration_hash' => (string)$source['declaration_hash'], 'source_supersession_ref' => $this->relative($this->supersessionPath((string)$before['window_id'])),
            'target_window_manifest_hash' => (string)$target['manifest_hash'], 'target_declaration_hash' => (string)$target['declaration_hash'], 'target_binding_ref' => $this->relative((new OfficialEpochWindowBindingResolver($this->repoRoot, $this->lifecycleRoot()))->store()->bindingPath((string)$after['window_id'])),
            'target_binding_hash' => (string)$binding['record_hash'], 'target_declared_measurement_identity' => (string)$target['identity'],
            'validation_package_hash' => (string)$binding['same_epoch_successor_validation_package_hash'], 'same_epoch_successor_validation_result' => $binding['same_epoch_successor_validation_result'],
            'expected_pointer_before' => $before, 'expected_pointer_before_hash' => $beforeHash, 'expected_pointer_after' => $after,
            'scheduler_expected_state' => 'HELD', 'capture_process_expected_count' => 0, 'created_at' => $createdAt === '' ? (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM) : $createdAt,
        ];
        $row['record_hash'] = $this->hash($row);
        return $row;
    }

    /** @param array<string,mixed> $intent @return array<string,mixed> */
    private function appliedPayload(array $intent, string $beforeHash, string $afterHash, string $appliedAt): array
    {
        $row = ['schema_version' => self::SCHEMA_VERSION, 'handoff_key' => $intent['handoff_key'], 'phase' => self::APPLIED_PHASE, 'intent_hash' => $intent['record_hash'], 'source_window_id' => $intent['source_window_id'], 'target_window_id' => $intent['target_window_id'], 'pointer_before_hash' => $beforeHash, 'pointer_after_hash' => $afterHash, 'target_binding_hash' => $intent['target_binding_hash'], 'epoch_projection_hash' => $intent['epoch_projection_hash'], 'current_measurement_identity' => $intent['current_measurement_identity'], 'applied_at' => $appliedAt === '' ? (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM) : $appliedAt];
        $row['record_hash'] = $this->hash($row); return $row;
    }

    /** @param callable(array<string,mixed>):bool $match @return array<string,mixed> */
    private function findRecord(string $dir, callable $match): array
    {
        foreach (glob($this->lifecycleRoot() . '/' . $dir . '/*.json') ?: [] as $path) { $row = $this->readValid($path); if ($row !== [] && $match($row)) return $row; }
        return [];
    }

    /** @return array<string,mixed> */
    private function findIntentFor(string $source, string $target): array
    {
        foreach (glob($this->lifecycleRoot() . '/official_epoch_recovery_pointer_handoffs/intents/*.json') ?: [] as $path) {
            $row = $this->readValid($path);
            if ($row !== [] && ($row['schema_version'] ?? '') === self::SCHEMA_VERSION && ($row['phase'] ?? '') === self::INTENT_PHASE
                && ($row['source_window_id'] ?? '') === $source && ($row['target_window_id'] ?? '') === $target) return $row;
        }
        return [];
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function createOnly(string $path, array $record, string $kind): array
    {
        if (is_file($path)) { $existing = $this->readValid($path); return $existing !== [] && hash_equals((string)$existing['record_hash'], (string)$record['record_hash']) ? ['accepted' => true, 'status' => $kind . '_ALREADY_EXISTS', 'reason' => '', 'record' => $existing] : $this->blocked($kind . '_CONFLICT'); }
        $dir = dirname($path); if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return $this->blocked($kind . '_DIRECTORY_UNAVAILABLE');
        $handle = @fopen($path, 'x'); if (!is_resource($handle)) return $this->blocked($kind . '_CREATE_CONFLICT');
        $ok = @fwrite($handle, $this->canonicalJson($record) . "\n") !== false; @fclose($handle);
        return $ok ? ['accepted' => true, 'status' => $kind . '_CREATED', 'reason' => '', 'record' => $record] : $this->blocked($kind . '_WRITE_FAILED');
    }

    /** @return array<string,mixed> */ private function readPointer(): array { return $this->readJson($this->pointerPath()); }
    /** @return array<string,mixed> */ private function readValid(string $path): array { $row = $this->readJson($path); if ($row === []) return []; $copy = $row; unset($copy['record_hash']); return OfficialEpochWindowBindingV1::validHash((string)($row['record_hash'] ?? '')) && hash_equals($this->hash($copy), (string)$row['record_hash']) ? $row : []; }
    /** @return array<string,mixed> */ private function readJson(string $path): array { try { $row = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); } catch (Throwable) { return []; } return is_array($row) ? $row : []; }
    private function formalEvidenceCount(string $root): int { $count = 0; foreach (['ledger','opportunity','input_snapshots','comparative_pairs'] as $dir) if (is_dir($root . '/' . $dir)) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS)) as $file) if ($file->isFile() && str_ends_with($file->getFilename(), '.json')) $count++; return $count; }
    private function hasEndRecord(string $epochId): bool { return $this->findRecord('official_epoch_ends', fn(array $r): bool => ($r['epoch_id'] ?? '') === $epochId) !== []; }
    private function pointerPath(): string { return rtrim($this->repoRoot, '/') . '/' . FormalMeasurementWindowContext::POINTER_RELATIVE_PATH; }
    private function supersessionPath(string $windowId): string { return $this->lifecycleRoot() . '/prepared_supersessions/' . rawurlencode($windowId) . '.json'; }
    private function recordPath(string $dir, string $id): string { return $this->lifecycleRoot() . '/' . $dir . '/' . preg_replace('/^sha256:/', '', $id) . '.json'; }
    private function fileHash(string $path): string { $bytes = @file_get_contents($path); return is_string($bytes) ? 'sha256:' . hash('sha256', $bytes) : ''; }
    private function relative(string $path): string { $root = rtrim($this->repoRoot, '/'); return str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : $path; }
    private function validWindowId(string $value): bool { return preg_match('/^[A-Za-z0-9._-]+$/', $value) === 1; }
    private function idFromHash(string $hash): string { return preg_replace('/^sha256:/', '', $hash); }
    private function hash(array $payload): string { return 'sha256:' . hash('sha256', $this->canonicalJson($payload)); }
    private function canonicalJson(array $payload): string { return OfficialEpochPolicyV1::canonicalJson($payload); }
    /** @return array<string,mixed> */ private function blocked(string $reason): array { return ['accepted' => false, 'status' => 'RECOVERY_HANDOFF_BLOCKED', 'reason' => $reason, 'intent' => [], 'applied' => []]; }
}
