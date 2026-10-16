<?php

declare(strict_types=1);

require_once __DIR__ . '/OfficialEpochWindowBindingResolver.php';
require_once __DIR__ . '/FormalMeasurementWindowContext.php';

/**
 * One fail-closed runtime boundary for every post-Epoch formal writer and
 * operational collector. Historical readers deliberately never call it.
 */
final class OfficialEpochWindowBindingRuntimeAuthorization
{
    public function __construct(private readonly string $repoRoot) {}

    /** @return array<string,mixed> */
    public function authorizeOperationalPointerWindow(): array
    {
        try {
            $context = FormalMeasurementWindowContext::production($this->repoRoot);
        } catch (Throwable $error) {
            return $error->getMessage() === 'FORMAL_WINDOW_CONTEXT_POINTER_MISSING'
                ? $this->noop('SAFE_NOOP_NO_ACTIVE_WINDOW')
                : $this->blocked('FORMAL_WINDOW_CONTEXT_UNAVAILABLE:' . get_class($error));
        }
        $resolved = $context->resolve();
        if (empty($resolved['accepted'])) return $this->blocked((string)($resolved['reason'] ?? 'WINDOW_CONTEXT_UNRESOLVED'));
        $state = $context->lifecycle()->effectiveState((string)$resolved['window_id'], $resolved['manifest']);
        if (($state['effective_state'] ?? '') !== 'ACTIVE') return $this->noop('SAFE_NOOP_NO_ACTIVE_WINDOW', $context, $resolved);
        $formal = $context->authorizeMeasurementWrite();
        if (empty($formal['accepted'])) return $this->blocked((string)($formal['reason'] ?? 'OFFICIAL_EPOCH_WINDOW_BINDING_INVALID'), $context, $resolved, $formal);
        return $this->accepted($context, $resolved, $formal, is_array($formal['official_epoch_binding'] ?? null) ? $formal['official_epoch_binding'] : []);
    }

    /**
     * Called only after the normal Window declaration/activation checks have
     * passed. An absent Epoch preserves pre-Epoch runtime compatibility; a
     * malformed existing Epoch is never treated as absent.
     *
     * @param array<string,mixed> $resolved
     * @param array<string,mixed> $activationAuthorization
     * @return array<string,mixed>
     */
    public function authorizeWindowForFormalWrite(FormalMeasurementWindowContext $context, array $resolved, array $activationAuthorization): array
    {
        $officialWindows = rtrim($this->repoRoot, DIRECTORY_SEPARATOR) . '/var/strict_forward_live_readiness/windows/';
        if (!str_starts_with($context->windowManifestPath(), $officialWindows)) {
            return ['accepted' => true, 'status' => 'NON_OPERATIONAL_FIXTURE_OR_HISTORICAL_CONTEXT', 'reason' => '', 'binding' => []];
        }
        $lifecycleRoot = rtrim($this->repoRoot, DIRECTORY_SEPARATOR) . '/var/strict_forward_live_readiness/lifecycle';
        $startPaths = glob($lifecycleRoot . '/official_epochs/*/start.json') ?: [];
        if ($startPaths === []) return ['accepted' => true, 'status' => 'PRE_EPOCH_RUNTIME_ALLOWED', 'reason' => '', 'binding' => []];
        $resolver = new OfficialEpochWindowBindingResolver($this->repoRoot);
        $binding = $resolver->validateBinding((string)$resolved['window_id'], ['require_pointer' => true]);
        if (empty($binding['accepted'])) return ['accepted' => false, 'status' => 'OFFICIAL_EPOCH_RUNTIME_BLOCKED', 'reason' => (string)($binding['reason'] ?? 'OFFICIAL_EPOCH_WINDOW_BINDING_INVALID'), 'binding' => []];
        $activation = is_array($activationAuthorization['activation'] ?? null) ? $activationAuthorization['activation'] : [];
        $record = is_array($binding['binding'] ?? null) ? $binding['binding'] : [];
        $epoch = is_array($binding['epoch'] ?? null) ? $binding['epoch'] : [];
        $projection = is_array($binding['projection'] ?? null) ? $binding['projection'] : [];
        if (($activation['activation_schema_version'] ?? '') !== WindowActivationHold::ACTIVATION_SCHEMA_VERSION
            || !hash_equals('lifecycle/official_epoch_window_bindings/' . (string)$resolved['window_id'] . '.json', (string)($activation['official_epoch_window_binding_ref'] ?? ''))
            || !hash_equals((string)($record['record_hash'] ?? ''), (string)($activation['official_epoch_window_binding_hash'] ?? ''))
            || !hash_equals((string)($epoch['epoch_id'] ?? ''), (string)($activation['epoch_id'] ?? ''))
            || !hash_equals((string)($projection['state_hash'] ?? ''), (string)($activation['epoch_projection_hash_at_activation'] ?? ''))) {
            return ['accepted' => false, 'status' => 'OFFICIAL_EPOCH_RUNTIME_BLOCKED', 'reason' => 'OFFICIAL_EPOCH_ACTIVATION_BINDING_MISMATCH', 'binding' => []];
        }
        return ['accepted' => true, 'status' => 'OFFICIAL_EPOCH_RUNTIME_AUTHORIZED', 'reason' => '', 'binding' => $record, 'epoch' => $epoch, 'projection' => $projection];
    }

    /** @param array<string,mixed> $resolved @param array<string,mixed> $formal @param array<string,mixed> $binding @return array<string,mixed> */
    private function accepted(FormalMeasurementWindowContext $context, array $resolved, array $formal, array $binding): array
    {
        return ['accepted' => true, 'kind' => 'ACTIVE', 'status' => 'OFFICIAL_EPOCH_RUNTIME_AUTHORIZED', 'reason' => '', 'context' => $context, 'window_id' => (string)$resolved['window_id'], 'authorization' => $formal, 'binding' => $binding];
    }
    /** @param array<string,mixed>|null $resolved @return array<string,mixed> */
    private function noop(string $reason, ?FormalMeasurementWindowContext $context = null, ?array $resolved = null): array
    {
        return ['accepted' => false, 'kind' => 'NOOP', 'status' => 'SAFE_NOOP_NO_ACTIVE_WINDOW', 'reason' => $reason, 'context' => $context, 'window_id' => (string)(($resolved ?? [])['window_id'] ?? ''), 'authorization' => [], 'binding' => []];
    }
    /** @param array<string,mixed>|null $resolved @param array<string,mixed>|null $formal @return array<string,mixed> */
    private function blocked(string $reason, ?FormalMeasurementWindowContext $context = null, ?array $resolved = null, ?array $formal = null): array
    {
        return ['accepted' => false, 'kind' => 'GLOBAL_FATAL', 'status' => 'OFFICIAL_EPOCH_RUNTIME_BLOCKED', 'reason' => $reason, 'context' => $context, 'window_id' => (string)(($resolved ?? [])['window_id'] ?? ''), 'authorization' => $formal ?? [], 'binding' => []];
    }
}
