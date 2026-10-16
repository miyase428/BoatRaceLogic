<?php

declare(strict_types=1);

require_once __DIR__ . '/OfficialEpochWindowBindingStore.php';

/** Read-only resolver/validator for immutable Official Epoch Window bindings. */
final class OfficialEpochWindowBindingResolver
{
    public function __construct(private readonly string $repoRoot, private readonly ?string $externalLifecycleRoot = null) {}

    public function store(): OfficialEpochWindowBindingStore { return new OfficialEpochWindowBindingStore($this->repoRoot, $this->externalLifecycleRoot); }
    /** @return array<string,mixed> */ public function bindingForWindow(string $windowId): array { return $this->store()->bindingForWindow($windowId); }
    /** @return list<array<string,mixed>> */ public function windowsForEpoch(string $epochId): array { return $this->store()->windowsForEpoch($epochId); }

    /** @return array<string,mixed> */
    public function epochForBoundWindow(string $windowId): array
    {
        $lifecycle = $this->store()->lifecycleRoot();
        $start = (new OfficialEpochStartStore($lifecycle))->existing();
        if ($start !== [] && ($start['source_closed_window_id'] ?? '') === $windowId) return ['accepted' => true, 'kind' => 'SOURCE_CLOSED_WINDOW', 'epoch' => $start, 'binding' => []];
        $binding = $this->bindingForWindow($windowId);
        if ($binding === [] || $start === [] || !hash_equals((string)$binding['epoch_id'], (string)$start['epoch_id'])) return ['accepted' => false, 'reason' => 'OFFICIAL_EPOCH_WINDOW_BINDING_NOT_FOUND', 'epoch' => [], 'binding' => []];
        return ['accepted' => true, 'kind' => 'BOUND_SUCCESSOR_WINDOW', 'epoch' => $start, 'binding' => $binding];
    }

    /** @param array<string,mixed> $expected @return array<string,mixed> */
    public function validateBinding(string $windowId, array $expected = []): array
    {
        $binding = $this->bindingForWindow($windowId);
        if ($binding === []) return $this->blocked('OFFICIAL_EPOCH_WINDOW_BINDING_MISSING');
        $start = (new OfficialEpochStartStore($this->store()->lifecycleRoot()))->existing();
        if ($start === [] || !hash_equals((string)$binding['epoch_id'], (string)($start['epoch_id'] ?? ''))
            || !hash_equals((string)$binding['epoch_start_record_hash'], (string)($start['record_hash'] ?? ''))
            || !hash_equals((string)$binding['epoch_policy_identity'], (string)($start['epoch_policy_identity'] ?? ''))
            || !hash_equals((string)$binding['incident_policy_identity'], (string)($start['incident_policy_identity'] ?? ''))) return $this->blocked('OFFICIAL_EPOCH_WINDOW_BINDING_EPOCH_MISMATCH');
        $projection = (new OfficialEpochIncidentLifecycleStore($this->store()->lifecycleRoot()))->project((string)$start['epoch_id']);
        if (empty($projection['valid']) || ($projection['state'] ?? '') !== 'ACTIVE' || !hash_equals((string)$binding['epoch_state_projection_hash'], (string)($projection['state_hash'] ?? ''))) return $this->blocked('OFFICIAL_EPOCH_WINDOW_BINDING_EPOCH_STATE_OR_PROJECTION_MISMATCH');
        $root = rtrim($this->repoRoot, DIRECTORY_SEPARATOR) . '/var/strict_forward_live_readiness/windows/' . $windowId;
        $context = FormalMeasurementWindowContext::fromWindowRoot($this->repoRoot, $root);
        $resolved = $context->resolve(true);
        $declaration = $context->declaration()->read(true);
        if (empty($resolved['accepted']) || empty($declaration['accepted']) || !hash_equals((string)$binding['window_id'], (string)($resolved['window_id'] ?? ''))) return $this->blocked('OFFICIAL_EPOCH_WINDOW_BINDING_CONTEXT_INVALID');
        $manifestHash = $this->fileHash($root . '/validation_window_manifest.json');
        $declarationHash = $this->fileHash($context->declaration()->declarationPath());
        $impl = $declaration['implementation'];
        // The manifest hash is a PREPARED-time CAS witness. Activation
        // intentionally and immutably changes that manifest's state/armed_at;
        // ACTIVE runtime instead authenticates the declaration plus v2
        // activation binding below. Never mistake that legitimate transition
        // for a provenance drift.
        if (($expected['require_prepared'] ?? false) === true
            && !hash_equals((string)($binding['window_manifest_hash'] ?? ''), $manifestHash)) {
            return $this->blocked('OFFICIAL_EPOCH_WINDOW_BINDING_WINDOW_MANIFEST_HASH_MISMATCH');
        }
        foreach ([
            'window_declaration_hash' => $declarationHash,
            'declared_measurement_identity' => (string)($impl['implementation_identity'] ?? ''),
            'measurement_manifest_hash' => (string)($impl['manifest_sha256'] ?? ''),
            'measurement_source_freeze_hash' => (string)($impl['source_freeze_sha256'] ?? ''),
        ] as $field => $actual) if (!hash_equals((string)($binding[$field] ?? ''), $actual)) return $this->blocked('OFFICIAL_EPOCH_WINDOW_BINDING_' . strtoupper($field) . '_MISMATCH');
        if (($binding['prospective_boundary_contract'] ?? '') !== OfficialEpochWindowBindingV1::PROSPECTIVE_BOUNDARY_CONTRACT) return $this->blocked('OFFICIAL_EPOCH_WINDOW_BINDING_PROSPECTIVE_CONTRACT_MISMATCH');
        foreach (OfficialEpochWindowBindingV1::requirementNames() as $name) if (($binding['same_epoch_successor_validation_result'][$name] ?? '') !== 'PASS') return $this->blocked('OFFICIAL_EPOCH_WINDOW_BINDING_REQUIREMENT_NOT_PASS:' . $name);
        if (($expected['require_prepared'] ?? false) === true) {
            $m = $resolved['manifest'];
            if (($m['status'] ?? '') !== 'PREPARED' || ($m['activation_state'] ?? '') !== 'PREPARED' || !empty($m['armed_at']) || $context->activationHold()->readActivation() !== []) return $this->blocked('OFFICIAL_EPOCH_WINDOW_BINDING_PREPARED_STATE_INVALID');
        }
        if (($expected['require_pointer'] ?? false) === true && !$this->pointerTargets($windowId)) return $this->blocked('OFFICIAL_EPOCH_WINDOW_BINDING_POINTER_MISMATCH');
        return ['accepted' => true, 'status' => 'OFFICIAL_EPOCH_WINDOW_BINDING_VALID', 'reason' => '', 'binding' => $binding, 'epoch' => $start, 'projection' => $projection];
    }

    private function pointerTargets(string $windowId): bool
    {
        $path = rtrim($this->repoRoot, DIRECTORY_SEPARATOR) . '/' . FormalMeasurementWindowContext::POINTER_RELATIVE_PATH;
        try { $pointer = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); } catch (Throwable) { return false; }
        return is_array($pointer) && ($pointer['window_id'] ?? '') === $windowId;
    }
    private function fileHash(string $path): string { $bytes = @file_get_contents($path); return is_string($bytes) ? 'sha256:' . hash('sha256', $bytes) : ''; }
    /** @return array<string,mixed> */ private function blocked(string $reason): array { return ['accepted' => false, 'status' => 'OFFICIAL_EPOCH_WINDOW_BINDING_INVALID', 'reason' => $reason, 'binding' => [], 'epoch' => [], 'projection' => []]; }
}
