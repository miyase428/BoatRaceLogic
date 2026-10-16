<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/FormalMeasurementWindowContext.php';
require_once __DIR__ . '/../web/logic/FormalEvidenceAggregate.php';

/**
 * Explicit CC12 terminal operation for an invalid A1 bootstrap Window.
 *
 * It creates only lifecycle/aborts/<window>.json. No Window evidence,
 * manifest, pointer, scheduler setting, or formal evaluation record is
 * changed. This command intentionally has no default target.
 */
function cc12AbortUsage(): never
{
    fwrite(STDERR, "usage: php analysis/abort_formal_measurement_window.php --window-id=<id> --capture-through=YYYY-MM-DD --scheduler-held-confirmed [--abort-reason=a1-bootstrap-invalid|a1-deadline-semantic-conflict|comparative-race-local-reject-batch-abort-bug]\n");
    exit(64);
}

$windowId = '';
$through = '';
$held = false;
$abortReason = FormalMeasurementWindowLifecycle::ABORT_REASON_A1_EXPECTED_INVENTORY_BOOTSTRAP_INVALID;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--window-id=')) $windowId = substr($argument, strlen('--window-id='));
    if (str_starts_with($argument, '--capture-through=')) $through = substr($argument, strlen('--capture-through='));
    if ($argument === '--scheduler-held-confirmed') $held = true;
    if ($argument === '--abort-reason=a1-deadline-semantic-conflict') $abortReason = FormalMeasurementWindowLifecycle::ABORT_REASON_A1_DEADLINE_SNAPSHOT_SEMANTIC_CONFLICT;
    if ($argument === '--abort-reason=a1-bootstrap-invalid') $abortReason = FormalMeasurementWindowLifecycle::ABORT_REASON_A1_EXPECTED_INVENTORY_BOOTSTRAP_INVALID;
    if ($argument === '--abort-reason=comparative-race-local-reject-batch-abort-bug') $abortReason = FormalMeasurementWindowLifecycle::ABORT_REASON_COMPARATIVE_RACE_LOCAL_REJECT_BATCH_ABORT_BUG;
}
if ($windowId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $through) || !$held) cc12AbortUsage();

$repoRoot = dirname(__DIR__);
$context = FormalMeasurementWindowContext::production($repoRoot);
$resolved = $context->resolve();
if (!$resolved['accepted'] || !hash_equals($windowId, (string)$resolved['window_id'])) throw new RuntimeException('abort_window_context_mismatch');
$manifest = $resolved['manifest'];
if (($manifest['status'] ?? '') !== 'ACTIVE' || ($manifest['activation_state'] ?? '') !== 'ACTIVE') throw new RuntimeException('abort_window_not_active');
$declaration = $context->declaration()->read();
$activation = $context->activationHold()->readActivation();
if (!$declaration['accepted']) throw new RuntimeException('abort_declaration_unavailable');
$declared = (string)($declaration['declaration']['implementation_identity'] ?? '');
$activationIdentity = (string)($activation['implementation_identity'] ?? '');
if ($declared === '' || !hash_equals($declared, $activationIdentity)) throw new RuntimeException('abort_declared_activation_identity_mismatch');

// Repeatable read-only identity calculation proves the closer is operating
// under one stable, newer implementation state; it must not equal the bad
// Window's declared identity.
$provenance = new MeasurementImplementationProvenance($repoRoot, $context->declaration()->provenanceStorageRoot());
$identities = [$provenance->currentIdentityReadOnly(), $provenance->currentIdentityReadOnly(), $provenance->currentIdentityReadOnly()];
if (count(array_unique($identities)) !== 1 || hash_equals($declared, $identities[0])) throw new RuntimeException('abort_closing_identity_unverified');

$aggregate = FormalEvidenceAggregate::calculate(dirname($context->windowManifestPath()), $windowId, $through);
$root = $context->opportunityRoot();
$armedAt = (string)($manifest['armed_at'] ?? '');
$afterArm = static function (string $at) use ($armedAt): bool {
    try { return $at !== '' && new DateTimeImmutable($at) > new DateTimeImmutable($armedAt); }
    catch (Throwable) { return false; }
};
$candidateCount = 0;
foreach (glob($root . '/candidates/*/*.json') ?: [] as $path) {
    $row = json_decode((string)file_get_contents($path), true);
    if (is_array($row) && $afterArm((string)($row['universe_recorded_at'] ?? ''))) $candidateCount++;
}
$sourceFailureCount = 0;
foreach (glob($root . '/source_failures/*.jsonl') ?: [] as $path) {
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $row = json_decode($line, true);
        if (is_array($row) && $afterArm((string)($row['observed_at'] ?? ''))) $sourceFailureCount++;
    }
}
$expectedCount = 0;
foreach (glob($root . '/expected_inventory/*.json') ?: [] as $path) {
    $row = json_decode((string)file_get_contents($path), true);
    if (is_array($row) && ($row['window_id'] ?? '') === $windowId) $expectedCount += count(is_array($row['expected_entries'] ?? null) ? $row['expected_entries'] : []);
}
$pointer = (string)file_get_contents($repoRoot . '/' . FormalMeasurementWindowContext::POINTER_RELATIVE_PATH);
$comparative = $declaration['declaration']['comparative_contract'] ?? [];
$failureCode = 'BLOCKED_EXPECTED_INVENTORY_MISSING';
$failureDetails = 'Expected inventory was first-written empty before authoritative deadline refresh; CC12 forbids repair or backfill.';
$gateA1 = 'BLOCKED_EXPECTED_INVENTORY_MISSING';
$gateA2 = 'FAIL';
$gateC = 'BLOCKED_A1';
if ($abortReason === FormalMeasurementWindowLifecycle::ABORT_REASON_A1_DEADLINE_SNAPSHOT_SEMANTIC_CONFLICT) {
    $failureCode = 'EXPECTED_INVENTORY_SEMANTIC_REVISION_CONFLICT';
    $failureDetails = 'Deadline acquisition-instance identity changed between otherwise identical scheduler reads, blocking formal capture and creating an instrumentation gap; no repair or backfill is permitted.';
    $gateA1 = 'BLOCKED_EXPECTED_INVENTORY_SEMANTIC_REVISION';
    $gateA2 = 'PASS';
} elseif ($abortReason === FormalMeasurementWindowLifecycle::ABORT_REASON_COMPARATIVE_RACE_LOCAL_REJECT_BATCH_ABORT_BUG) {
    $failureCode = 'COMPARATIVE_RACE_LOCAL_REJECT_BATCH_ABORT_BUG';
    $failureDetails = 'A race-local comparative rejection incorrectly stopped later eligible races from receiving comparative evidence; no repair or backfill is permitted.';
    $gateA1 = 'PASS';
    $gateA2 = 'PASS';
    $gateC = 'BLOCKED_COMPARATIVE_RUNTIME';
}
$result = $context->lifecycle()->createAbort([
    'window_id' => $windowId, 'armed_at' => $armedAt,
    'aborted_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM),
    'capture_through_date' => $through,
    'abort_reason' => $abortReason,
    'failure_code' => $failureCode,
    'failure_details' => $failureDetails,
    'gate_a1' => $gateA1,
    'gate_a2' => $gateA2,
    'gate_b_final' => 'PENDING_LIVE', 'gate_c' => $gateC,
    'candidate_count' => $candidateCount, 'expected_count' => $expectedCount, 'terminal_candidate_count' => 0,
    'source_failure_count' => $sourceFailureCount, 'backfill_count' => 0, 'prearmed_sample_count' => 0,
    'final_evidence_hash' => (string)$aggregate['aggregate_sha256'], 'verified_final_evidence_hash' => (string)$aggregate['aggregate_sha256'],
    'declared_implementation_identity' => $declared, 'activation_implementation_identity' => $activationIdentity,
    'comparative_policy_identity' => (string)($comparative['comparative_policy_identity'] ?? 'NOT_AVAILABLE'),
    'previous_pointer_identity' => 'sha256:' . hash('sha256', $pointer),
    'closing_tool_implementation_identity' => $identities[0],
    'operator_tool_provenance' => 'analysis/abort_formal_measurement_window.php',
]);
echo json_encode(array_merge($result, [
    'closing_identity_three_way_match' => count(array_unique($identities)) === 1,
    'candidate_count_observed' => $candidateCount, 'expected_count_observed' => $expectedCount,
    'source_failure_count_observed' => $sourceFailureCount,
]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(!empty($result['accepted']) ? 0 : 1);
