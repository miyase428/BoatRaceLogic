<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/StrictForwardReadinessLedger.php';

function lifecycleAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException('ASSERTION_FAILED: ' . $message); }
function lifecycleWrite(string $path, string $bytes): void { $dir = dirname($path); if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('fixture_dir_failed'); if (file_put_contents($path, $bytes) === false) throw new RuntimeException('fixture_write_failed'); }
function lifecycleRemove(string $path): void { if (!file_exists($path)) return; if (is_dir($path) && !is_link($path)) { foreach (scandir($path) ?: [] as $child) if ($child !== '.' && $child !== '..') lifecycleRemove($path . '/' . $child); rmdir($path); return; } unlink($path); }

/** @return array{repo:string,old:FormalMeasurementWindowContext,new:FormalMeasurementWindowContext,pointer:string,root:string} */
function lifecycleFixture(): array
{
    $root = sys_get_temp_dir() . '/boatrace_lifecycle_v2_' . bin2hex(random_bytes(6));
    $repo = $root . '/repo';
    foreach (MeasurementImplementationProvenance::criticalPaths() as $path) lifecycleWrite($repo . '/' . $path, "fixture:" . $path . "\n");
    // The declaration now binds the frozen comparative contract. Keep the
    // fixture contract valid while the other critical files stay synthetic.
    foreach (['config/strict_forward_comparative_scope_v1.json', 'config/strict_forward_comparative_policy_v1.json'] as $path) {
        lifecycleWrite($repo . '/' . $path, (string)file_get_contents(dirname(__DIR__) . '/' . $path));
    }
    $base = $repo . '/var/strict_forward_live_readiness/windows';
    foreach (['old-window', 'new-window'] as $id) {
        lifecycleAssert(WindowActivationHold::createPrepared($base . '/' . $id . '/validation_window_manifest.json', $id)['accepted'], 'prepared ' . $id);
        $context = FormalMeasurementWindowContext::fromWindowRoot($repo, $base . '/' . $id);
        lifecycleAssert($context->declaration()->declare()['accepted'], 'declaration ' . $id);
        if ($id === 'old-window') lifecycleAssert($context->activationHold()->activate('fixture://preflight')['accepted'], 'activate old');
    }
    $old = FormalMeasurementWindowContext::fromWindowRoot($repo, $base . '/old-window');
    $new = FormalMeasurementWindowContext::fromWindowRoot($repo, $base . '/new-window');
    $pointer = $repo . '/' . FormalMeasurementWindowContext::POINTER_RELATIVE_PATH;
    lifecycleWrite($pointer, json_encode(['schema_version' => FormalMeasurementWindowContext::POINTER_SCHEMA_VERSION, 'window_id' => 'old-window', 'window_root' => 'var/strict_forward_live_readiness/windows/old-window'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    return compact('repo', 'old', 'new', 'pointer', 'root');
}

$fixture = lifecycleFixture();
try {
    $results = [];
    $lifecycle = new FormalMeasurementWindowLifecycle($fixture['repo']);
    $oldResolved = $fixture['old']->resolve();
    $newResolved = $fixture['new']->resolve();
    lifecycleAssert($fixture['old']->authorizeMeasurementWrite()['accepted'], 'active allows writes before closure');
    lifecycleAssert(!$fixture['new']->authorizeMeasurementWrite()['accepted'] && $fixture['new']->authorizeMeasurementWrite()['reason'] === 'FORMAL_WRITE_NOT_ACTIVE', 'prepared blocks all writes');
    $results[] = 'prepared_write_block';

    $oldIdentity = $fixture['old']->declaration()->read()['declaration']['implementation_identity'];
    $pointerBytes = (string)file_get_contents($fixture['pointer']);
    $closureInput = [
        'window_id' => 'old-window', 'armed_at' => (string)$oldResolved['manifest']['armed_at'], 'closed_at' => '2099-01-02T00:00:00+09:00', 'capture_through_date' => '2099-01-01',
        'final_evidence_hash' => 'sha256:' . str_repeat('a', 64), 'verified_final_evidence_hash' => 'sha256:' . str_repeat('a', 64), 'closure_reason' => 'NORMAL_CAPTURE_COMPLETE',
        'gate_a1' => 'PASS', 'gate_a2' => 'PASS', 'gate_b_final' => 'PASS', 'gate_c' => 'PASS',
        'candidate_count' => 2, 'terminal_candidate_count' => 2, 'eligible_count' => 2, 'accepted_count' => 2, 'unresolved_count' => 0, 'official_epoch' => 'NO',
        'declared_implementation_identity' => $oldIdentity, 'activation_implementation_identity' => $oldIdentity,
        'comparative_policy_identity' => 'sha256:' . str_repeat('b', 64),
        'previous_pointer_identity' => 'sha256:' . hash('sha256', $pointerBytes),
        'closing_tool_implementation_identity' => 'sha256:' . str_repeat('c', 64),
    ];
    $closed = $lifecycle->createClosure($closureInput);
    lifecycleAssert($closed['accepted'] && $closed['status'] === 'CLOSURE_CREATED', 'bootstrap close creates external record');
    lifecycleAssert($lifecycle->createClosure($closureInput)['status'] === 'CLOSURE_ALREADY_EXISTS', 'same close idempotent');
    $conflict = $closureInput; $conflict['final_evidence_hash'] = 'sha256:' . str_repeat('d', 64); $conflict['verified_final_evidence_hash'] = $conflict['final_evidence_hash'];
    lifecycleAssert(!$lifecycle->createClosure($conflict)['accepted'], 'different close is conflict');
    $hashMismatch = $closureInput; $hashMismatch['verified_final_evidence_hash'] = 'sha256:' . str_repeat('e', 64);
    lifecycleAssert(!$lifecycle->createClosure($hashMismatch)['accepted'], 'final evidence hash mismatch rejected');
    $terminalMissing = $closureInput; $terminalMissing['terminal_candidate_count'] = 1;
    lifecycleAssert(!$lifecycle->createClosure($terminalMissing)['accepted'], 'terminal candidate shortage rejected');
    $unresolved = $closureInput; $unresolved['unresolved_count'] = 1;
    lifecycleAssert(!$lifecycle->createClosure($unresolved)['accepted'], 'unresolved candidate rejected');
    lifecycleAssert(!$fixture['old']->authorizeMeasurementWrite()['accepted'] && $fixture['old']->authorizeMeasurementWrite()['reason'] === 'WINDOW_CLOSED', 'closed blocks common writer authorization');
    $results[] = 'immutable_closure_first_write_idempotency_conflict';

    $opportunity = new StrictForwardOpportunityLedger($fixture['old']->opportunityRoot(), $fixture['old']);
    try {
        $opportunity->recordCandidateUniverse(['date' => '2099-01-01', 'candidates' => [], 'source' => 'fixture', 'source_identity' => 'sha256:x', 'source_generated_at' => '2099-01-01T00:00:00+09:00']);
        throw new RuntimeException('closed opportunity write unexpectedly allowed');
    } catch (RuntimeException $error) { lifecycleAssert($error->getMessage() === 'WINDOW_CLOSED', 'closed opportunity blocked with machine reason'); }
    $ledger = new StrictForwardReadinessLedger($fixture['old']->ledgerRoot(), false, $opportunity, $fixture['old']->declaration(), $fixture['old']->activationHold(), null, $fixture['old']);
    $expected = $ledger->recordExpectedLive(['date' => '2099-01-01', 'place' => 'TMG', 'races' => [['race_code' => '20990101TMG01', 'cutoff_timestamp' => '2099-01-01T12:00:00+09:00']]]);
    lifecycleAssert($expected['status'] === 'SKIPPED' && $expected['reason'] === 'WINDOW_CLOSED', 'closed expected path blocked without diagnostic append');
    $closedCapture = $ledger->captureLive(['date' => '2099-01-01', 'place' => 'TMG', 'phase' => 'live', 'ready_race_codes' => []]);
    lifecycleAssert($closedCapture['reason'] === 'WINDOW_CLOSED', 'closed canonical entry is blocked before operational append');
    $results[] = 'opportunity_expected_canonical_paths_protected';

    $newPointer = ['schema_version' => FormalMeasurementWindowContext::POINTER_SCHEMA_VERSION, 'window_id' => 'new-window', 'window_root' => 'var/strict_forward_live_readiness/windows/new-window'];
    $handoff = $lifecycle->handoffPointerCas($fixture['pointer'], 'old-window', $newPointer, 'fixture-lifecycle-v2');
    lifecycleAssert($handoff['accepted'] && $handoff['status'] === 'POINTER_HANDOFF_APPLIED', 'closed old to prepared new CAS handoff');
    lifecycleAssert($lifecycle->handoffPointerCas($fixture['pointer'], 'old-window', $newPointer, 'fixture-lifecycle-v2')['accepted'], 'same handoff idempotent');
    lifecycleAssert(FormalMeasurementWindowContext::production($fixture['repo'])->windowId() === 'new-window', 'pointer resolves prepared successor');
    lifecycleAssert(count(glob($fixture['repo'] . '/var/strict_forward_live_readiness/lifecycle/pointer_transitions/applied/*.json') ?: []) === 1, 'append-only applied transition audit');
    lifecycleAssert(!$lifecycle->handoffPointerCas($fixture['pointer'], 'wrong-old', $newPointer, 'fixture-lifecycle-v2')['accepted'], 'pointer CAS conflict detected');
    lifecycleAssert($fixture['new']->activationHold()->activate('fixture://successor-preflight')['accepted'], 'prepared successor activates only through existing activation contract');
    $newActive = FormalMeasurementWindowContext::fromWindowRoot($fixture['repo'], $fixture['repo'] . '/var/strict_forward_live_readiness/windows/new-window');
    lifecycleAssert($newActive->authorizeMeasurementWrite()['accepted'], 'activated successor permits measurement writes');
    $newOpportunity = new StrictForwardOpportunityLedger($newActive->opportunityRoot(), $newActive);
    lifecycleAssert($newOpportunity->recordCandidateUniverse(['date' => '2099-01-02', 'candidates' => [['race_code' => '20990102TMG01', 'venue' => 'TMG', 'cutoff_timestamp' => '2099-01-02T12:00:00+09:00']], 'source' => 'fixture', 'source_identity' => 'sha256:fixture', 'source_generated_at' => '2099-01-02T08:00:00+09:00'])['saved'] === 1, 'active successor candidate write allowed');
    lifecycleAssert(!$fixture['old']->authorizeMeasurementWrite()['accepted'] && $fixture['old']->authorizeMeasurementWrite()['reason'] === 'WINDOW_CLOSED', 'pointer cannot revive closed predecessor');
    $results[] = 'cas_handoff_and_append_only_transition_audit';

    // ABORT is a terminal lifecycle state distinct from CLOSE. It preserves
    // the active Window evidence and permits only a subsequent CAS handoff.
    $abortFixture = lifecycleFixture();
    try {
        $abortLifecycle = new FormalMeasurementWindowLifecycle($abortFixture['repo']);
        $abortResolved = $abortFixture['old']->resolve();
        $abortIdentity = $abortFixture['old']->declaration()->read()['declaration']['implementation_identity'];
        $abortAggregate = FormalEvidenceAggregate::calculate(dirname($abortFixture['old']->windowManifestPath()), 'old-window', '2099-01-01');
        $abortInput = [
            'window_id' => 'old-window', 'armed_at' => (string)$abortResolved['manifest']['armed_at'], 'aborted_at' => '2099-01-01T12:00:00+09:00',
            'capture_through_date' => '2099-01-01', 'abort_reason' => FormalMeasurementWindowLifecycle::ABORT_REASON_A1_DEADLINE_SNAPSHOT_SEMANTIC_CONFLICT,
            'failure_code' => 'EXPECTED_INVENTORY_SEMANTIC_REVISION_CONFLICT', 'failure_details' => 'fixture deadline semantics changed after first-write Expected freeze',
            'gate_a1' => 'BLOCKED_EXPECTED_INVENTORY_SEMANTIC_REVISION', 'gate_a2' => 'PASS', 'gate_b_final' => 'PENDING_LIVE', 'gate_c' => 'BLOCKED_A1',
            'candidate_count' => 72, 'expected_count' => 72, 'terminal_candidate_count' => 0, 'source_failure_count' => 0, 'backfill_count' => 0, 'prearmed_sample_count' => 0,
            'final_evidence_hash' => (string)$abortAggregate['aggregate_sha256'], 'verified_final_evidence_hash' => (string)$abortAggregate['aggregate_sha256'],
            'declared_implementation_identity' => $abortIdentity, 'activation_implementation_identity' => $abortIdentity,
            'comparative_policy_identity' => 'sha256:' . str_repeat('b', 64),
            'previous_pointer_identity' => 'sha256:' . hash('sha256', (string)file_get_contents($abortFixture['pointer'])),
            'closing_tool_implementation_identity' => 'sha256:' . str_repeat('c', 64), 'operator_tool_provenance' => 'fixture',
        ];
        $aborted = $abortLifecycle->createAbort($abortInput);
        lifecycleAssert($aborted['accepted'] && $aborted['status'] === 'ABORT_CREATED', 'abort creates immutable external record');
        lifecycleAssert(($aborted['abort']['abort_reason'] ?? '') === FormalMeasurementWindowLifecycle::ABORT_REASON_A1_DEADLINE_SNAPSHOT_SEMANTIC_CONFLICT, 'semantic deadline conflict has a stable abort reason');
        lifecycleAssert($abortLifecycle->createAbort($abortInput)['status'] === 'ABORT_ALREADY_EXISTS', 'same abort idempotent');
        $abortConflict = $abortInput; $abortConflict['failure_code'] = 'OTHER';
        lifecycleAssert(!$abortLifecycle->createAbort($abortConflict)['accepted'], 'different abort is a conflict');
        lifecycleAssert($abortFixture['old']->lifecycle()->effectiveState('old-window', $abortResolved['manifest'])['effective_state'] === 'ABORTED', 'abort gives effective ABORTED state');
        lifecycleAssert($abortFixture['old']->authorizeMeasurementWrite()['reason'] === 'WINDOW_ABORTED', 'aborted window blocks all common writers');
        $abortHandoff = $abortLifecycle->handoffPointerCas($abortFixture['pointer'], 'old-window', ['schema_version' => FormalMeasurementWindowContext::POINTER_SCHEMA_VERSION, 'window_id' => 'new-window', 'window_root' => 'var/strict_forward_live_readiness/windows/new-window'], 'fixture-abort');
        lifecycleAssert($abortHandoff['accepted'] && ($abortHandoff['transition']['old_terminal_state'] ?? '') === 'ABORTED', 'aborted predecessor can CAS handoff to prepared successor');
        $results[] = 'immutable_abort_and_aborted_cas_handoff';
    } finally { lifecycleRemove($abortFixture['root']); }

    echo json_encode(['status' => 'PASS', 'fixtures' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    lifecycleRemove($fixture['root']);
}
