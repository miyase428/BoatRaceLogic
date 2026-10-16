<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/FormalMeasurementWindowContext.php';
require_once __DIR__ . '/../web/logic/FormalMeasurementWindowLifecycle.php';

function bootstrapAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException('ASSERTION_FAILED: ' . $message); }
function bootstrapWrite(string $path, string $contents): void { $dir = dirname($path); if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('fixture_dir_failed'); if (file_put_contents($path, $contents) === false) throw new RuntimeException('fixture_write_failed'); }
function bootstrapRemove(string $path): void { if (!file_exists($path)) return; if (is_dir($path) && !is_link($path)) { foreach (scandir($path) ?: [] as $item) if ($item !== '.' && $item !== '..') bootstrapRemove($path . '/' . $item); rmdir($path); } else unlink($path); }

/** @return array{root:string,repo:string,window:string,context:FormalMeasurementWindowContext,pointer:string} */
function bootstrapFixture(bool $legacy = true): array
{
    $root = sys_get_temp_dir() . '/boatrace_bootstrap_close_' . bin2hex(random_bytes(6));
    $repo = $root . '/repo';
    $all = MeasurementImplementationProvenance::criticalPaths();
    $legacyPaths = array_values(array_filter($all, static fn(string $path): bool => !in_array($path, ['web/logic/FormalMeasurementWindowLifecycle.php', 'web/logic/FormalEvidenceAggregate.php'], true)));
    foreach ($all as $path) bootstrapWrite($repo . '/' . $path, "fixture:" . $path . "\n");
    foreach (['config/strict_forward_comparative_scope_v1.json', 'config/strict_forward_comparative_policy_v1.json'] as $path) {
        bootstrapWrite($repo . '/' . $path, (string)file_get_contents(dirname(__DIR__) . '/' . $path));
    }
    $window = $repo . '/var/strict_forward_live_readiness/windows/legacy-window';
    bootstrapAssert(WindowActivationHold::createPrepared($window . '/validation_window_manifest.json', 'legacy-window')['accepted'], 'prepared window');
    $context = FormalMeasurementWindowContext::fromWindowRoot($repo, $window, $legacy ? $legacyPaths : $all);
    bootstrapAssert($context->declaration()->declare()['accepted'], 'declared fixture identity');
    bootstrapAssert($context->activationHold()->activate('fixture://bootstrap-preflight')['accepted'], 'activated fixture');
    // A bootstrap close models a historical, already verified Window.  The
    // ordinary fixture activation below is V1 because it has no active
    // OfficialEpoch; the migration-only authorizer deliberately accepts only
    // the historical V2 activation schema.  Preserve every generated field
    // and alter only that schema marker for the synthetic legacy record.
    $activation = $context->activationHold()->readActivation();
    bootstrapAssert(($activation['activation_schema_version'] ?? '') === WindowActivationHold::ACTIVATION_SCHEMA_VERSION_V1, 'fixture seed activation is V1 without OfficialEpoch');
    $activation['activation_schema_version'] = WindowActivationHold::ACTIVATION_SCHEMA_VERSION;
    bootstrapWrite($window . '/activation_record.json', json_encode($activation, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    bootstrapWrite($window . '/opportunity/candidates/20990101/a.json', "{\"race_code\":\"20990101TMG01\"}\n");
    bootstrapWrite($window . '/opportunity/eligibility/20990101/a.json', "{\"eligibility_status\":\"ELIGIBLE_OBSERVED\"}\n");
    bootstrapWrite($window . '/ledger/expected/20990101/a.json', "{\"stable_unique_key\":\"x\"}\n");
    bootstrapWrite($window . '/ledger/records/20990101/a.json', "{\"stable_unique_key\":\"x\"}\n");
    $pointer = $repo . '/' . FormalMeasurementWindowContext::POINTER_RELATIVE_PATH;
    bootstrapWrite($pointer, json_encode(['schema_version' => FormalMeasurementWindowContext::POINTER_SCHEMA_VERSION, 'window_id' => 'legacy-window', 'window_root' => 'var/strict_forward_live_readiness/windows/legacy-window'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    return compact('root', 'repo', 'window', 'context', 'pointer');
}

function bootstrapInput(array $fixture): array
{
    $aggregate = FormalEvidenceAggregate::calculate($fixture['window'], 'legacy-window', '2099-01-01');
    return [
        'window_id' => 'legacy-window', 'armed_at' => (string)$fixture['context']->activationHold()->readManifest()['armed_at'],
        'closed_at' => '2099-01-02T00:00:00+09:00', 'capture_through_date' => '2099-01-01',
        'scheduler_maintenance_hold_confirmed' => true, 'formal_evidence_aggregate_sha256' => $aggregate['aggregate_sha256'],
        'candidate_count' => 1, 'terminal_candidate_count' => 1, 'unresolved_count' => 0, 'eligible_count' => 1, 'accepted_count' => 1,
        'gate_a1' => 'PASS', 'gate_a2' => 'PASS', 'gate_b_final' => 'PASS', 'gate_c' => 'PASS',
    ];
}

$fixture = bootstrapFixture();
try {
    $lifecycle = new FormalMeasurementWindowLifecycle($fixture['repo']);
    $results = [];
    bootstrapAssert(!FormalMeasurementWindowContext::production($fixture['repo'])->authorizeFormalWrite()['accepted'], 'ordinary write authorization remains identity-strict');
    $declaration = $fixture['context']->declaration()->read();
    $activation = $fixture['context']->activationHold()->readActivation();
    $manifestPath = $fixture['context']->declaration()->provenanceStorageRoot() . '/' . (string)($declaration['declaration']['manifest_ref'] ?? '');
    $frozenManifest = json_decode((string)file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    $frozenPaths = array_map(static fn(array $file): string => (string)($file['path'] ?? ''), (array)($frozenManifest['files'] ?? []));
    bootstrapAssert(($activation['activation_schema_version'] ?? '') === WindowActivationHold::ACTIVATION_SCHEMA_VERSION, 'historical legacy activation uses V2 schema');
    bootstrapAssert(($declaration['declaration']['declaration_schema_version'] ?? '') === WindowDeclaredImplementationIdentity::DECLARATION_SCHEMA_VERSION, 'legacy declaration schema is current');
    bootstrapAssert(($declaration['declaration']['implementation_identity'] ?? '') === ($activation['implementation_identity'] ?? ''), 'legacy declaration and activation identities match');
    bootstrapAssert(!in_array('web/logic/FormalMeasurementWindowLifecycle.php', $frozenPaths, true), 'legacy freeze excludes lifecycle close implementation');
    bootstrapAssert(!in_array('web/logic/FormalEvidenceAggregate.php', $frozenPaths, true), 'legacy freeze excludes aggregate implementation');
    $input = bootstrapInput($fixture);
    $authorization = $lifecycle->authorizeBootstrapClosure($input);
    bootstrapAssert($authorization['accepted'] && ($authorization['authorization']['legacy_window_verified'] ?? false), 'verified legacy window is bootstrap-authorized: ' . json_encode($authorization));
    $results[] = 'normal_write_stays_strict_bootstrap_is_separate';
    $aggregate1 = FormalEvidenceAggregate::calculate($fixture['window'], 'legacy-window', '2099-01-01');
    touch($fixture['window'] . '/ledger/records/20990101/a.json');
    $aggregate2 = FormalEvidenceAggregate::calculate($fixture['window'], 'legacy-window', '2099-01-01');
    bootstrapAssert($aggregate1['aggregate_sha256'] === $aggregate2['aggregate_sha256'], 'mtime does not affect aggregate');
    bootstrapWrite($fixture['window'] . '/ledger/records/20990101/a.json', "{\"stable_unique_key\":\"changed\"}\n");
    $aggregate3 = FormalEvidenceAggregate::calculate($fixture['window'], 'legacy-window', '2099-01-01');
    bootstrapAssert($aggregate1['aggregate_sha256'] !== $aggregate3['aggregate_sha256'], 'evidence bytes change aggregate');
    bootstrapWrite($fixture['window'] . '/ledger/records/20990101/a.json', "{\"stable_unique_key\":\"x\"}\n");
    $input = bootstrapInput($fixture);
    bootstrapAssert($lifecycle->createBootstrapClosure($input)['accepted'], 'bootstrap closure created with separated identities');
    bootstrapAssert($lifecycle->createBootstrapClosure($input)['status'] === 'CLOSURE_ALREADY_EXISTS', 'bootstrap closure first-write idempotent');
    bootstrapAssert(FormalEvidenceAggregate::calculate($fixture['window'], 'legacy-window', '2099-01-01')['aggregate_sha256'] === $input['formal_evidence_aggregate_sha256'], 'outside closure excluded from aggregate');
    $results[] = 'aggregate_determinism_and_bootstrap_closure';
    foreach (['scheduler_maintenance_hold_confirmed' => false, 'unresolved_count' => 1, 'gate_c' => 'FAIL'] as $field => $value) {
        $other = bootstrapFixture();
        try { $candidate = bootstrapInput($other); $candidate[$field] = $value; bootstrapAssert(!(new FormalMeasurementWindowLifecycle($other['repo']))->authorizeBootstrapClosure($candidate)['accepted'], 'bootstrap rejects ' . $field); }
        finally { bootstrapRemove($other['root']); }
    }
    $nonLegacy = bootstrapFixture(false);
    try { bootstrapAssert(!(new FormalMeasurementWindowLifecycle($nonLegacy['repo']))->authorizeBootstrapClosure(bootstrapInput($nonLegacy))['accepted'], 'nonlegacy window rejected'); }
    finally { bootstrapRemove($nonLegacy['root']); }
    $results[] = 'bootstrap_preconditions_fail_closed';
    echo json_encode(['status' => 'PASS', 'fixtures' => $results, 'file_count' => $aggregate1['file_count']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally { bootstrapRemove($fixture['root']); }
