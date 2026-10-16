<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/MeasurementImplementationProvenance.php';

function mipAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('ASSERTION_FAILED: ' . $message);
}

function mipWrite(string $path, string $content): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) throw new RuntimeException('fixture_directory_unavailable');
    if (file_put_contents($path, $content) === false) throw new RuntimeException('fixture_write_failed');
}

$liveDependencies = [
    'analysis/analyze_all_venue_lane_signals.py',
    'analysis/analyze_all_venue_secondary_signals.py',
    'analysis/analyze_tamagawa_boaters_hypothesis.py',
    'analysis/analyze_tamagawa_lane4_exacta_structure.py',
    'analysis/analyze_tamagawa_lane4_makurizashi_vulnerability.py',
    'analysis/analyze_tamagawa_lane4_strong_condition_second_eval.py',
    'analysis/audit_tamagawa_course_signals_zero_base_ml.py',
    'analysis/optimize_all_venue_secondary_rules.py',
    'analysis/slit_validate_v2.py',
    'common/db_config.py',
    'theories/course_correction/classify_slit_pattern.py',
    'theories/course_correction/predict_pattern.py',
];
foreach ($liveDependencies as $dependency) {
    mipAssert(in_array($dependency, MeasurementImplementationProvenance::criticalPaths(), true), 'live dependency is measurement-critical: ' . $dependency);
}

$root = sys_get_temp_dir() . '/boatrace_measurement_provenance_' . bin2hex(random_bytes(8));
$repo = $root . '/repo';
$store = $root . '/store';
mipWrite($repo . '/critical/a.php', "<?php\nreturn 'a';\n");
mipWrite($repo . '/critical/taint.php', "<?php\nreturn 'taint';\n");
mipWrite($repo . '/unrelated.txt', "outside scope\n");
$scope = ['critical/taint.php', 'critical/a.php'];
$first = new MeasurementImplementationProvenance($repo, $store, $scope);
$one = $first->freeze();
$two = $first->freeze();
mipAssert($one['implementation_identity'] === $two['implementation_identity'], 'same raw contents produce same identity');
$verified = $first->verify($one['implementation_identity'], $one['manifest_ref'], $one['source_freeze_ref']);
mipAssert($verified['valid'] === true, 'identity resolves to valid manifest and source freeze');

// Filesystem ordering and mtime are deliberately absent from the identity.
$reordered = new MeasurementImplementationProvenance($repo, $store, array_reverse($scope));
$reorderedFreeze = $reordered->freeze();
mipAssert($reorderedFreeze['implementation_identity'] === $one['implementation_identity'], 'listing order does not change identity');
touch($repo . '/critical/a.php', time() + 60);
$mtimeOnly = $first->freeze();
mipAssert($mtimeOnly['implementation_identity'] === $one['implementation_identity'], 'mtime-only change does not change identity');
mipWrite($repo . '/unrelated.txt', "unrelated changed\n");
$unrelatedOnly = $first->freeze();
mipAssert($unrelatedOnly['implementation_identity'] === $one['implementation_identity'], 'unrelated file change does not change identity');

// Scope itself is part of the canonical files structure: adding/removing a
// critical file necessarily produces a distinct identity.
mipWrite($repo . '/critical/new.php', "<?php\nreturn 'new';\n");
$added = (new MeasurementImplementationProvenance($repo, $store, ['critical/a.php', 'critical/new.php', 'critical/taint.php']))->freeze();
mipAssert($added['implementation_identity'] !== $one['implementation_identity'], 'critical file addition changes identity');
$removed = (new MeasurementImplementationProvenance($repo, $store, ['critical/a.php']))->freeze();
mipAssert($removed['implementation_identity'] !== $one['implementation_identity'], 'critical file removal changes identity');

// One raw byte in a Taint Isolation-equivalent critical file must be visible.
mipWrite($repo . '/critical/taint.php', "<?php\nreturn 'taint!';\n");
$taintChanged = $first->freeze();
mipAssert($taintChanged['implementation_identity'] !== $one['implementation_identity'], 'taint isolation source byte change changes identity');

// Reconstruct the exact raw bytes from the content-addressed freeze artifact.
$freeze = json_decode((string)file_get_contents($store . '/' . $taintChanged['source_freeze_ref']), true, 512, JSON_THROW_ON_ERROR);
$manifest = json_decode((string)file_get_contents($store . '/' . $taintChanged['manifest_ref']), true, 512, JSON_THROW_ON_ERROR);
$restored = $root . '/restored';
foreach ($freeze['files'] as $index => $file) {
    $raw = base64_decode((string)$file['content_base64'], true);
    mipAssert(is_string($raw), 'freeze content is valid base64');
    mipWrite($restored . '/' . $file['path'], $raw);
    mipAssert(hash('sha256', $raw) === $manifest['files'][$index]['sha256'], 'restored raw SHA256 equals manifest for ' . $file['path']);
    mipAssert(hash_file('sha256', $restored . '/' . $file['path']) === $manifest['files'][$index]['sha256'], 'restored file bytes equal manifest for ' . $file['path']);
}

// A formal-evidence shaped reference gives a complete reverse chain.
$evidence = [
    'measurement_implementation_identity' => $taintChanged['implementation_identity'],
    'measurement_manifest_ref' => $taintChanged['manifest_ref'],
    'measurement_source_freeze_ref' => $taintChanged['source_freeze_ref'],
    'measurement_manifest_sha256' => $taintChanged['manifest_sha256'],
    'measurement_source_freeze_sha256' => $taintChanged['source_freeze_sha256'],
];
$trace = $first->verify($evidence['measurement_implementation_identity'], $evidence['measurement_manifest_ref'], $evidence['measurement_source_freeze_ref']);
mipAssert($trace['valid'] === true && $trace['manifest_sha256'] === $evidence['measurement_manifest_sha256'] && $trace['source_freeze_sha256'] === $evidence['measurement_source_freeze_sha256'], 'formal evidence reverse trace is complete');
$missing = $first->verify($evidence['measurement_implementation_identity'], 'implementation_manifests/' . str_repeat('0', 64) . '.json', $evidence['measurement_source_freeze_ref']);
mipAssert($missing['valid'] === false, 'missing implementation manifest fails loud');

echo json_encode([
    'status' => 'PASS',
    'implementation_identity' => $taintChanged['implementation_identity'],
    'tests' => [
        'same_contents', 'critical_byte_change', 'unrelated_file', 'mtime_only', 'listing_order',
        'critical_add_remove', 'taint_isolation_byte_change', 'source_reconstruction', 'formal_evidence_traceability', 'missing_freeze_fail_loud',
        'live_inference_dependency_scope',
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
