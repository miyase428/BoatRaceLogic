<?php

declare(strict_types=1);

/**
 * Runs only isolated fixture/regression tests and writes the implementation
 * gate report. It never calls lifecycle mutation APIs against the production
 * Window, does not inspect or alter crontab, and does not invoke a producer.
 */
function gateReportRun(string $repo, string $relative): array
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($relative);
    $pipes = [];
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $repo);
    if (!is_resource($process)) return ['passed' => false, 'output' => 'PROCESS_UNAVAILABLE'];
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $status = proc_close($process);
    return ['passed' => $status === 0, 'output' => trim($stdout . ($stderr === '' ? '' : "\n" . $stderr))];
}

/** @return array{count:int,framed_sha256:string} */
function gateReportTree(string $root): array
{
    if (!is_dir($root)) return ['count' => 0, 'framed_sha256' => 'sha256:' . hash('sha256', '')];
    $rows = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile()) $rows[] = substr($file->getPathname(), strlen(rtrim($root, '/')) + 1) . "\0" . hash_file('sha256', $file->getPathname());
    }
    sort($rows, SORT_STRING);
    return ['count' => count($rows), 'framed_sha256' => 'sha256:' . hash('sha256', implode("\n", $rows))];
}

$repo = dirname(__DIR__);
$pointerPath = $repo . '/var/strict_forward_live_readiness/formal_measurement_window_context.json';
$pointer = json_decode((string)file_get_contents($pointerPath), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($pointer) || !is_string($pointer['window_root'] ?? null)) throw new RuntimeException('PRODUCTION_POINTER_UNREADABLE');
$windowRoot = $repo . '/' . ltrim((string)$pointer['window_root'], '/');
$before = gateReportTree($windowRoot);

$tests = [
    'implementation_fixture' => gateReportRun($repo, 'analysis/test_cc30c_recovery_implementation_gate.php'),
    'identity_read_only_fixture' => gateReportRun($repo, 'analysis/test_window_declared_implementation_identity.php'),
    'late_finalizer_and_aggregate_regression' => gateReportRun($repo, 'analysis/test_cc30c_4o_structural_fixes.php'),
    'legacy_technical_incomplete_contract' => gateReportRun($repo, 'analysis/test_cc30c_technical_incomplete_contract_v1.php'),
];
$after = gateReportTree($windowRoot);
$productionUnchanged = $before === $after;

$fixturePass = $tests['implementation_fixture']['passed'];
$identityPass = $tests['identity_read_only_fixture']['passed'];
$latePass = $tests['late_finalizer_and_aggregate_regression']['passed'];
$legacyPass = $tests['legacy_technical_incomplete_contract']['passed'];
$gates = [
    'abort_v2' => $fixturePass ? 'PASS' : 'FAIL',
    'incident_v3' => $fixturePass ? 'PASS' : 'FAIL',
    'major_classifier' => $fixturePass ? 'PASS' : 'FAIL',
    'identity_read_only' => $identityPass ? 'PASS' : 'FAIL',
    'identity_freeze' => $identityPass ? 'PASS' : 'FAIL',
    'vnext_dependency_identity' => $fixturePass ? 'PASS' : 'FAIL',
    'schedule_contract' => $fixturePass ? 'PASS' : 'FAIL',
    'runtime_schedule_attestation' => $fixturePass ? 'PASS' : 'FAIL',
    'late_finalizer_fixture' => $latePass ? 'PASS' : 'FAIL',
    'daytime_regression' => $latePass ? 'PASS' : 'FAIL',
    'aggregate_v2_regression' => $latePass ? 'PASS' : 'FAIL',
    'rollover_fixture' => ($fixturePass && $legacyPass) ? 'PASS' : 'FAIL',
    'evidence_preservation' => ($fixturePass && $legacyPass) ? 'PASS' : 'FAIL',
    'production_window_unchanged_during_fixture_tests' => $productionUnchanged ? 'PASS' : 'FAIL',
];
$allPass = !in_array('FAIL', $gates, true);
$report = [
    'schema_version' => 'cc30c-recovery-implementation-gate-report/v1',
    'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DateTimeInterface::ATOM),
    'source_git_commit' => trim((string)shell_exec('git -C ' . escapeshellarg($repo) . ' rev-parse HEAD')),
    'scope' => 'fixture-only implementation gates; no production lifecycle mutation',
    'production_window' => ['window_id' => $pointer['window_id'] ?? '', 'tree_before' => $before, 'tree_after' => $after, 'unchanged' => $productionUnchanged],
    'gates' => $gates,
    'recovery_execution_gate' => $allPass ? 'PASS' : 'FAIL',
    'execution_boundary' => [
        'real_window_abort' => 'IRREVERSIBLE_TERMINAL_NOT_EXECUTED',
        'incident_append' => 'APPEND_ONLY_NOT_EXECUTED',
        'successor_prepare' => 'REVERSIBLE_BEFORE_ACTIVATION_NOT_EXECUTED',
        'pointer_cas' => 'IRREVERSIBLE_ROUTING_NOT_EXECUTED',
        'binding_activation_runtime_authorization' => 'NOT_EXECUTED',
        'cron_change_or_producer_run' => 'NOT_EXECUTED',
        'historical_repair_or_evidence_generation' => 'NOT_EXECUTED',
    ],
    'test_results' => $tests,
];
$canonical = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$report['report_sha256'] = 'sha256:' . hash('sha256', $canonical);
$path = $repo . '/analysis/output/cc30c_recovery_implementation_gate_report.json';
if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) throw new RuntimeException('OUTPUT_DIRECTORY_UNAVAILABLE');
file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n", LOCK_EX);
$readback = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($readback) || ($readback['report_sha256'] ?? '') !== $report['report_sha256'] || ($readback['recovery_execution_gate'] ?? '') !== ($report['recovery_execution_gate'] ?? '')) throw new RuntimeException('REPORT_READBACK_INVALID');
echo json_encode(['status' => 'PASS', 'report_path' => 'analysis/output/cc30c_recovery_implementation_gate_report.json', 'recovery_execution_gate' => $report['recovery_execution_gate'], 'production_window_unchanged' => $productionUnchanged], JSON_UNESCAPED_SLASHES) . PHP_EOL;
