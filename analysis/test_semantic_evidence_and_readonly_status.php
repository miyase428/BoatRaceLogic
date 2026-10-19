<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/FormalMeasurementWindowStatusInspector.php';
require_once __DIR__ . '/../web/logic/ForwardStatusReadModel.php';
require_once __DIR__ . '/../web/logic/FormalEvidenceAggregate.php';

function semanticAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException('ASSERTION_FAILED: ' . $message); }
function semanticWrite(string $path, string $bytes): void { $dir = dirname($path); if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('fixture_directory_failed'); if (file_put_contents($path, $bytes) === false) throw new RuntimeException('fixture_write_failed'); }
function semanticRemove(string $path): void { if (!file_exists($path)) return; if (is_dir($path) && !is_link($path)) { foreach (scandir($path) ?: [] as $item) if ($item !== '.' && $item !== '..') semanticRemove($path . '/' . $item); rmdir($path); } else unlink($path); }
function semanticTree(string $root): array { $rows = []; foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) if ($file->isFile()) { $path = $file->getPathname(); $rows[substr($path, strlen($root) + 1)] = hash_file('sha256', $path); } ksort($rows); return $rows; }
function semanticCopy(string $from, string $to): void { if (is_dir($from)) { if (!is_dir($to) && !mkdir($to, 0775, true) && !is_dir($to)) throw new RuntimeException('fixture_copy_dir_failed'); foreach (scandir($from) ?: [] as $item) if ($item !== '.' && $item !== '..') semanticCopy($from . '/' . $item, $to . '/' . $item); return; } if (!copy($from, $to)) throw new RuntimeException('fixture_copy_file_failed'); }
function semanticRowReference(array $row, array $reference): array { $row['measurement_implementation_identity'] = $reference['implementation_identity']; $row['measurement_manifest_schema_version'] = $reference['manifest_schema_version']; $row['measurement_manifest_sha256'] = $reference['manifest_sha256']; $row['measurement_manifest_ref'] = $reference['manifest_ref']; $row['measurement_source_freeze_sha256'] = $reference['source_freeze_sha256']; $row['measurement_source_freeze_ref'] = $reference['source_freeze_ref']; return $row; }

/** @return array{root:string,repo:string,window:string,context:FormalMeasurementWindowContext,reference:array<string,string>} */
function semanticFixture(): array
{
    $root = sys_get_temp_dir() . '/boatrace_semantic_evidence_' . bin2hex(random_bytes(6));
    $repo = $root . '/repo';
    foreach (MeasurementImplementationProvenance::criticalPaths() as $path) semanticWrite($repo . '/' . $path, "fixture:" . $path . "\n");
    foreach (['config/strict_forward_comparative_scope_v1.json', 'config/strict_forward_comparative_policy_v1.json'] as $path) {
        semanticWrite($repo . '/' . $path, (string)file_get_contents(dirname(__DIR__) . '/' . $path));
    }
    $window = $repo . '/var/strict_forward_live_readiness/windows/fixture-window';
    semanticAssert(WindowActivationHold::createPrepared($window . '/validation_window_manifest.json', 'fixture-window')['accepted'], 'prepared');
    $context = FormalMeasurementWindowContext::fromWindowRoot($repo, $window);
    semanticAssert($context->declaration()->declare()['accepted'], 'declared');
    semanticAssert($context->activationHold()->activate('fixture://semantic-preflight')['accepted'], 'activated');
    $reference = $context->declaration()->read()['implementation'];
    $pointer = $repo . '/' . FormalMeasurementWindowContext::POINTER_RELATIVE_PATH;
    semanticWrite($pointer, json_encode(['schema_version' => FormalMeasurementWindowContext::POINTER_SCHEMA_VERSION, 'window_id' => 'fixture-window', 'window_root' => 'var/strict_forward_live_readiness/windows/fixture-window'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    $row = [
        'race_code' => '20990101TMG01', 'expected_at' => '2099-01-01T10:00:00+09:00', 'captured_at' => '2099-01-01T10:00:00+09:00',
        'capture_mode' => 'LIVE_STRICT', 'stable_unique_key' => 'fixture-key',
        'measurement_implementation_identity' => $reference['implementation_identity'], 'measurement_manifest_schema_version' => $reference['manifest_schema_version'],
        'measurement_manifest_sha256' => $reference['manifest_sha256'], 'measurement_manifest_ref' => $reference['manifest_ref'],
        'measurement_source_freeze_sha256' => $reference['source_freeze_sha256'], 'measurement_source_freeze_ref' => $reference['source_freeze_ref'],
    ];
    semanticWrite($window . '/ledger/expected/20990101/a.json', json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    semanticWrite($window . '/ledger/records/20990101/a.json', json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    semanticWrite($window . '/opportunity/candidates/20990101/a.json', "{\"race_code\":\"20990101TMG01\"}\n");
    semanticWrite($window . '/opportunity/eligibility/20990101/a.json', "{\"eligibility_status\":\"ELIGIBLE_OBSERVED\"}\n");
    return compact('root', 'repo', 'window', 'context', 'reference');
}

$fixture = semanticFixture();
try {
    $results = [];
    $first = FormalEvidenceAggregate::calculate($fixture['window'], 'fixture-window', '2099-01-01');
    semanticAssert(count($first['referenced_implementation_identities']) === 1, 'declared activation expected canonical reach one identity');
    semanticAssert(in_array('provenance/' . $fixture['reference']['manifest_ref'], array_column($first['entries'], 'relative_path'), true), 'referenced manifest included');
    semanticAssert(in_array('provenance/' . $fixture['reference']['source_freeze_ref'], array_column($first['entries'], 'relative_path'), true), 'referenced source included');
    $results[] = 'declared_referenced_provenance_included';
    $results[] = 'activation_referenced_provenance_included';
    $results[] = 'expected_referenced_provenance_included';
    $results[] = 'canonical_referenced_provenance_included';

    $beforeAuxiliary = $first['aggregate_sha256'];
    semanticWrite($fixture['repo'] . '/web/logic/FormalEvidenceAggregate.php', "fixture:post-hoc-change\n");
    $postHoc = (new MeasurementImplementationProvenance($fixture['repo'], $fixture['window'] . '/provenance'))->freeze();
    $second = FormalEvidenceAggregate::calculate($fixture['window'], 'fixture-window', '2099-01-01');
    semanticAssert($beforeAuxiliary === $second['aggregate_sha256'], 'unreferenced post-hoc provenance excluded');
    semanticAssert(in_array('provenance/' . $postHoc['manifest_ref'], $second['excluded_auxiliary_provenance'], true), 'post-hoc manifest reported auxiliary');
    semanticAssert(in_array('provenance/' . $postHoc['source_freeze_ref'], $second['excluded_auxiliary_provenance'], true), 'post-hoc source reported auxiliary');
    $results[] = 'unreferenced_provenance_excluded';
    $results[] = 'posthoc_provenance_excluded';

    $expectedPath = $fixture['window'] . '/ledger/expected/20990101/a.json';
    $canonicalPath = $fixture['window'] . '/ledger/records/20990101/a.json';
    $oldExpected = json_decode((string)file_get_contents($expectedPath), true, 512, JSON_THROW_ON_ERROR);
    $oldCanonical = json_decode((string)file_get_contents($canonicalPath), true, 512, JSON_THROW_ON_ERROR);
    semanticWrite($expectedPath, json_encode(semanticRowReference($oldExpected, $postHoc), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    $expectedChanged = FormalEvidenceAggregate::calculate($fixture['window'], 'fixture-window', '2099-01-01');
    semanticAssert($expectedChanged['aggregate_sha256'] !== $second['aggregate_sha256'] && count($expectedChanged['referenced_implementation_identities']) === 2, 'expected identity change detected');
    semanticWrite($expectedPath, json_encode($oldExpected, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    semanticWrite($canonicalPath, json_encode(semanticRowReference($oldCanonical, $postHoc), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    $canonicalChanged = FormalEvidenceAggregate::calculate($fixture['window'], 'fixture-window', '2099-01-01');
    semanticAssert($canonicalChanged['aggregate_sha256'] !== $second['aggregate_sha256'] && count($canonicalChanged['referenced_implementation_identities']) === 2, 'canonical identity change detected');
    semanticWrite($canonicalPath, json_encode($oldCanonical, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    $declarationPath = $fixture['window'] . '/declared_implementation_identity.json';
    $oldDeclarationBytes = (string)file_get_contents($declarationPath);
    $newDeclaration = json_decode($oldDeclarationBytes, true, 512, JSON_THROW_ON_ERROR);
    foreach (['implementation_identity', 'manifest_schema_version', 'manifest_sha256', 'manifest_ref', 'source_freeze_sha256', 'source_freeze_ref'] as $key) $newDeclaration[$key] = $postHoc[$key];
    semanticWrite($declarationPath, json_encode($newDeclaration, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    semanticAssert(FormalEvidenceAggregate::calculate($fixture['window'], 'fixture-window', '2099-01-01')['aggregate_sha256'] !== $second['aggregate_sha256'], 'declared identity change detected');
    semanticWrite($declarationPath, $oldDeclarationBytes);
    $results[] = 'declared_expected_canonical_identity_change_detected';

    $beforeMtime = $second['aggregate_sha256'];
    touch($fixture['window'] . '/ledger/records/20990101/a.json');
    semanticAssert($beforeMtime === FormalEvidenceAggregate::calculate($fixture['window'], 'fixture-window', '2099-01-01')['aggregate_sha256'], 'mtime excluded');
    semanticWrite($fixture['window'] . '/lifecycle/closures/fixture-window.json', "{\"auxiliary\":true}\n");
    semanticWrite($fixture['repo'] . '/var/strict_forward_live_readiness/lifecycle/pointer_transitions/aux.json', "{\"auxiliary\":true}\n");
    semanticAssert($beforeMtime === FormalEvidenceAggregate::calculate($fixture['window'], 'fixture-window', '2099-01-01')['aggregate_sha256'], 'closure and pointer transition excluded');
    $copyRoot = $fixture['root'] . '/relocated/window';
    semanticCopy($fixture['window'], $copyRoot);
    semanticAssert($beforeMtime === FormalEvidenceAggregate::calculate($copyRoot, 'fixture-window', '2099-01-01')['aggregate_sha256'], 'absolute root excluded');
    $results[] = 'file_order_deterministic';
    $results[] = 'mtime_excluded';
    $results[] = 'absolute_root_excluded';
    $results[] = 'closure_excluded';
    $results[] = 'pointer_transition_excluded';

    $treeBefore = semanticTree($fixture['window']);
    $statusOne = (new FormalMeasurementWindowStatusInspector($fixture['repo']))->inspect();
    $statusTwo = (new FormalMeasurementWindowStatusInspector($fixture['repo']))->inspect();
    semanticAssert($treeBefore === semanticTree($fixture['window']), 'inspector creates or changes no window file');
    semanticAssert(($statusOne['declared_current_match'] ?? true) === false && ($statusTwo['declared_current_match'] ?? true) === false, 'identity mismatch remains readable');
    semanticAssert(($statusOne['declared_activation_match'] ?? false) === true, 'legacy evidence remains valid despite current mismatch');
    semanticAssert((new ForwardStatusReadModel($fixture['repo']))->current()['window_id'] === 'fixture-window', 'TOP read model uses pure inspector');
    semanticAssert($treeBefore === semanticTree($fixture['window']), 'TOP-equivalent status creates no provenance');
    $results[] = 'pure_inspector_single_read_no_side_effects';
    $results[] = 'pure_inspector_repeated_read_no_side_effects';
    $results[] = 'inspector_does_not_freeze_current_identity';
    $results[] = 'top_status_read_no_side_effects';
    $results[] = 'identity_mismatch_status_available';
    $results[] = 'identity_mismatch_legacy_evidence_separated';

    semanticAssert(!$fixture['context']->authorizeFormalWrite()['accepted'], 'ordinary formal write remains rejected after identity mismatch');
    $results[] = 'formal_write_remains_fail_closed';

    $bad = json_decode((string)file_get_contents($fixture['window'] . '/ledger/expected/20990101/a.json'), true, 512, JSON_THROW_ON_ERROR);
    $bad['measurement_manifest_ref'] = 'implementation_manifests/' . str_repeat('0', 64) . '.json';
    semanticWrite($fixture['window'] . '/ledger/expected/20990101/a.json', json_encode($bad, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    try { FormalEvidenceAggregate::calculate($fixture['window'], 'fixture-window', '2099-01-01'); throw new RuntimeException('dangling_reference_accepted'); }
    catch (RuntimeException $error) { semanticAssert($error->getMessage() !== 'dangling_reference_accepted', 'dangling reference rejected'); }
    $results[] = 'dangling_reference_fail_closed';

    semanticWrite($expectedPath, json_encode($oldExpected, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    $sourcePath = $fixture['window'] . '/provenance/' . $fixture['reference']['source_freeze_ref'];
    $sourceBytes = (string)file_get_contents($sourcePath);
    semanticWrite($sourcePath, $sourceBytes . "taint\n");
    try { FormalEvidenceAggregate::calculate($fixture['window'], 'fixture-window', '2099-01-01'); throw new RuntimeException('source_mismatch_accepted'); }
    catch (RuntimeException $error) { semanticAssert($error->getMessage() !== 'source_mismatch_accepted', 'source content mismatch rejected'); }
    semanticWrite($sourcePath, $sourceBytes);
    $results[] = 'manifest_source_content_mismatch_fail_closed';

    semanticAssert(count($results) >= 20, 'required semantic and pure-read fixture coverage present');

    echo json_encode(['status' => 'PASS', 'fixtures' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    semanticRemove($fixture['root']);
}
