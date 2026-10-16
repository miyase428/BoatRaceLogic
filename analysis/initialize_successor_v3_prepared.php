<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/FormalMeasurementWindowContext.php';
require_once __DIR__ . '/../web/logic/FormalMeasurementWindowPrepareOnlyV1.php';

/**
 * Creates and selects one inert successor Window. It deliberately does not
 * activate, invoke capture, or write any formal Expected/Canonical evidence.
 */
function successorV3WritePointer(string $repo, string $expectedOldWindowId, string $windowId, string $relativeRoot): void
{
    $path = $repo . '/' . FormalMeasurementWindowContext::POINTER_RELATIVE_PATH;
    $payload = [
        'schema_version' => FormalMeasurementWindowContext::POINTER_SCHEMA_VERSION,
        'window_id' => $windowId,
        'window_root' => $relativeRoot,
    ];
    $result = (new FormalMeasurementWindowLifecycle($repo))->handoffPointerCas($path, $expectedOldWindowId, $payload, 'initialize_successor_v3_prepared');
    if (!$result['accepted']) throw new RuntimeException('pointer_handoff_failed:' . $result['reason']);
}

$repo = dirname(__DIR__);
$previous = FormalMeasurementWindowContext::production($repo)->resolve();
if (!$previous['accepted']) throw new RuntimeException('previous_context_unresolved:' . $previous['reason']);
$prepared = (new FormalMeasurementWindowPrepareOnlyV1($repo))->create(['mode' => 'NORMAL']);
if (!$prepared['accepted']) throw new RuntimeException('prepared_creation_failed:' . $prepared['reason']);
$windowId = (string)$prepared['window_id'];
$windowRoot = (string)$prepared['window_root'];
$relativeRoot = 'var/strict_forward_live_readiness/windows/' . $windowId;
$context = FormalMeasurementWindowContext::fromWindowRoot($repo, $windowRoot);
$declaration = ['accepted' => true, 'implementation' => $prepared['implementation']];
$readback = $context->declaration()->read();
if (!$readback['accepted'] || !hash_equals((string)$prepared['implementation']['implementation_identity'], (string)$readback['declaration']['implementation_identity'])) throw new RuntimeException('declaration_readback_failed');

// This is the one intended successor selection. PREPARED remains fail-closed
// because authorization also requires ACTIVE plus an activation record.
successorV3WritePointer($repo, (string)$previous['window_id'], $windowId, $relativeRoot);
$resolved = FormalMeasurementWindowContext::production($repo)->resolve();
if (!$resolved['accepted'] || !hash_equals($windowId, $resolved['window_id'])) {
    throw new RuntimeException('pointer_readback_failed');
}

echo json_encode([
    'status' => 'NEW_SUCCESSOR_PREPARED_INITIALIZED',
    'previous_window_id' => $previous['window_id'],
    'window_id' => $windowId,
    'window_root' => $windowRoot,
    'prepared_at' => $prepared['manifest']['prepared_at'] ?? '',
    'activation_state' => $prepared['manifest']['activation_state'] ?? '',
    'armed_at' => $prepared['manifest']['armed_at'] ?? null,
    'activation_record_exists' => is_file($context->activationHold()->activationRecordPath()),
    'implementation' => $declaration['implementation'],
    'pointer_window_id' => $resolved['window_id'],
    'ledger_root' => $resolved['declaration']['window_id'] === $windowId ? $context->ledgerRoot() : '',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
