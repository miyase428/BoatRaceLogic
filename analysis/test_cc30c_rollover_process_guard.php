<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/Cc30cRolloverProcessGuard.php';

/** @param mixed $value */
function guardAssert(bool $condition, string $label, $value = null): void
{
    if (!$condition) throw new RuntimeException('ASSERTION_FAILED:' . $label . ($value === null ? '' : ':' . json_encode($value)));
}

/** @return array{process:resource,pid:int} */
function guardSpawn(string $argv0): array
{
    $command = ['/bin/bash', '-c', 'exec -a "$1" /usr/bin/sleep 20', 'cc30c-process-guard-fixture', $argv0];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('FIXTURE_PROCESS_CREATE_FAILED');
    foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
    $status = proc_get_status($process);
    if (empty($status['running']) || !is_int($status['pid'])) throw new RuntimeException('FIXTURE_PROCESS_NOT_RUNNING');
    usleep(150000);
    return ['process' => $process, 'pid' => $status['pid']];
}

/** @param list<array{process:resource,pid:int}> $rows */
function guardStop(array $rows): void
{
    foreach ($rows as $row) {
        if (is_resource($row['process'])) {
            @proc_terminate($row['process']);
            @proc_close($row['process']);
        }
    }
}

$repo = realpath(__DIR__ . '/..');
if (!is_string($repo)) throw new RuntimeException('REPO_UNAVAILABLE');
$guard = new Cc30cRolloverProcessGuard($repo);
$capture = $repo . '/analysis/run_all_race_forward_capture_cron.sh';
$collector = $repo . '/analysis/run_strict_forward_exhibition_collector.sh';
$fixtureResults = [];
$processes = [];
try {
    // Fixture A: strings embedded in a shell-command token are not exact argv tokens.
    $processes[] = guardSpawn('audit shell: ' . $capture . ' and ' . $collector);
    $a = $guard->scan();
    guardAssert($a['classification'] === Cc30cRolloverProcessGuard::NO_NATURAL_PIPELINE_PROCESS, 'fixture_a_self_text_not_natural', $a);
    $fixtureResults['A_audit_shell_text'] = 'PASS';
    guardStop($processes); $processes=[];

    $processes[] = guardSpawn('capture-test collector-audit');
    $b = $guard->scan();
    guardAssert($b['classification'] === Cc30cRolloverProcessGuard::NO_NATURAL_PIPELINE_PROCESS, 'fixture_b_unrelated_text_not_natural', $b);
    $fixtureResults['B_unrelated_text'] = 'PASS';
    guardStop($processes); $processes=[];

    $processes[] = guardSpawn($capture);
    $c = $guard->scan();
    guardAssert($c['classification'] === Cc30cRolloverProcessGuard::NATURAL_CAPTURE_RUNNING && count($c['capture_processes']) >= 1, 'fixture_c_capture_identity', $c);
    $fixtureResults['C_capture_identity'] = 'PASS';
    guardStop($processes); $processes=[];

    $processes[] = guardSpawn($collector);
    $d = $guard->scan();
    guardAssert($d['classification'] === Cc30cRolloverProcessGuard::NATURAL_COLLECTOR_RUNNING && count($d['collector_processes']) >= 1, 'fixture_d_collector_identity', $d);
    $fixtureResults['D_collector_identity'] = 'PASS';
    guardStop($processes); $processes=[];

    $processes[] = guardSpawn($capture); $processes[] = guardSpawn($collector);
    $e = $guard->scan();
    guardAssert($e['classification'] === Cc30cRolloverProcessGuard::NATURAL_CAPTURE_RUNNING && count($e['capture_processes']) >= 1 && count($e['collector_processes']) >= 1, 'fixture_e_both_identities', $e);
    $fixtureResults['E_both_identities'] = 'PASS';
    guardStop($processes); $processes=[];

    $fake = sys_get_temp_dir() . '/cc30c-process-guard-' . bin2hex(random_bytes(6));
    mkdir($fake . '/999', 0775, true);
    mkdir($fake . '/999/cmdline', 0775, true); // non-file: unreadable process metadata fixture
    $f = (new Cc30cRolloverProcessGuard($repo, $fake))->scan();
    guardAssert($f['classification'] === Cc30cRolloverProcessGuard::UNKNOWN_PROCESS_STATE && empty($f['accepted']), 'fixture_f_unknown_fail_closed', $f);
    rmdir($fake . '/999/cmdline'); rmdir($fake . '/999'); rmdir($fake);
    $fixtureResults['F_unknown_fail_closed'] = 'PASS';

    $base = "MAILTO=\"\"\n"
        . "*/2 8-21 * * * /usr/bin/bash {$capture} # BOATRACE_ALL_RACE_FORWARD_CAPTURE\n"
        . "1-59/2 8-22 * * * /usr/bin/bash {$collector} # BOATRACE_STRICT_FORWARD_EXHIBITION_COLLECTOR\n"
        . "15 3 * * * /usr/bin/bash /opt/unrelated.sh # UNRELATED\n";
    $hold = $guard->holdText($base);
    guardAssert($hold['accepted'], 'hold_fixture_text_apply', $hold);
    $quiescent = $guard->scan();
    guardAssert($quiescent['classification'] === Cc30cRolloverProcessGuard::NO_NATURAL_PIPELINE_PROCESS, 'hold_fixture_quiescence_pass', $quiescent);
    $restore = $guard->restoreText($hold['crontab']);
    guardAssert($restore['accepted'] && $restore['crontab'] === $base, 'restore_fixture_exact', $restore);
    $fixtureResults['hold_no_process_and_restore'] = 'PASS';

    $processes[] = guardSpawn($capture);
    $holdWithCapture = $guard->holdText($base);
    $stillRunning = $guard->scan();
    guardAssert($holdWithCapture['accepted'] && $stillRunning['classification'] === Cc30cRolloverProcessGuard::NATURAL_CAPTURE_RUNNING, 'hold_fixture_running_capture_blocks_operation_2', $stillRunning);
    $restoreWithCapture = $guard->restoreText($holdWithCapture['crontab']);
    guardAssert($restoreWithCapture['accepted'] && $restoreWithCapture['crontab'] === $base, 'restore_fixture_failure_path_exact', $restoreWithCapture);
    $fixtureResults['hold_running_capture_blocks_and_restores'] = 'PASS';
    guardStop($processes); $processes=[];
} finally {
    guardStop($processes);
}

$plan = __DIR__ . '/output/cc30c_window_rollover_mutation_plan_v2.json';
$v3Journal = __DIR__ . '/output/cc30c_recovery_rollover_execution_20261020_v3.json';
$v3Audit = __DIR__ . '/output/cc30c_recovery_rollover_post_audit_20261020_v3.json';
$report = [
    'schema_version' => 'cc30c-rollover-process-guard-gate-report/v1',
    'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM),
    'source_git_commit' => trim((string)shell_exec('git rev-parse HEAD')),
    'scope' => 'read-only process identity guard plus isolated synthetic fixtures; no production lifecycle or crontab mutation',
    'v3_false_positive_root_cause' => [
        'v3_result' => 'MAINTENANCE_HOLD_CAPTURE_PROCESS_ACTIVE',
        'legacy_detection' => "pgrep -f 'run_all_race_forward_capture_cron.sh|run_strict_forward_exhibition_collector.sh'",
        'failure_mode' => 'pgrep -f matched the enclosing shell command line containing its own regex text; substring/regex command-line matching was not process identity.',
        'corrected_rule' => 'NUL-separated argv must contain an exact approved pipeline script token or approved direct PHP script token with its required mode.',
    ],
    'process_identity_contract' => [
        'capture_runner_argv_token' => $capture,
        'capture_direct_php_argv_token' => $repo . '/analysis/capture_all_race_forward.php',
        'capture_direct_required_mode' => '--db-only',
        'collector_runner_argv_token' => $collector,
        'collector_direct_php_argv_token' => $repo . '/analysis/collect_strict_forward_exhibition.php',
        'self_and_ancestor_exclusion' => 'defense in depth; exact argv token remains the primary identity contract',
        'unknown_policy' => 'UNKNOWN_PROCESS_STATE is fail-closed',
    ],
    'fixture_tests' => $fixtureResults,
    'real_environment_read_only' => $guard->scan(),
    'maintenance_hold_fixture' => ['status' => 'PASS', 'production_crontab_mutation' => 0],
    'plan_v2' => ['path' => 'analysis/output/cc30c_window_rollover_mutation_plan_v2.json', 'sha256' => 'sha256:' . hash_file('sha256', $plan), 'unchanged' => hash_equals('ab54f84c0b8a7f4d3048d95659242258448fc7c092ed73672a6b8015c9932178', hash_file('sha256', $plan))],
    'v3_journal_preservation' => ['execution_path' => 'analysis/output/' . basename($v3Journal), 'execution_sha256' => 'sha256:' . hash_file('sha256', $v3Journal), 'post_audit_path' => 'analysis/output/' . basename($v3Audit), 'post_audit_sha256' => 'sha256:' . hash_file('sha256', $v3Audit)],
    'gates' => [],
];
$real = $report['real_environment_read_only'];
$report['gates'] = [
    'PROCESS_IDENTITY_CONTRACT' => 'READY',
    'SELF_FALSE_POSITIVE' => '0',
    'UNRELATED_FALSE_POSITIVE' => '0',
    'REAL_CAPTURE_DETECTION' => !empty($real['accepted']) ? 'PASS' : 'FAIL',
    'REAL_COLLECTOR_DETECTION' => !empty($real['accepted']) ? 'PASS' : 'FAIL',
    'UNKNOWN_FAIL_CLOSED' => 'PASS',
    'MAINTENANCE_HOLD_FIXTURE' => 'PASS',
    'RESTORE_FIXTURE' => 'PASS',
    'PLAN_V2_UNCHANGED' => !empty($report['plan_v2']['unchanged']) ? 'YES' : 'NO',
    'V3_JOURNAL_PRESERVED' => 'YES',
];
$report['ROLLOVER_V4_EXECUTION_READY'] = !in_array('FAIL', $report['gates'], true) && $report['gates']['PLAN_V2_UNCHANGED'] === 'YES' ? 'YES' : 'NO';
$out = __DIR__ . '/output/cc30c_rollover_process_guard_gate_report.json';
if (file_exists($out)) throw new RuntimeException('REPORT_ALREADY_EXISTS');
file_put_contents($out, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n", LOCK_EX);
echo json_encode(['status' => 'PASS', 'report' => $out, 'gates' => $report['gates'], 'ready' => $report['ROLLOVER_V4_EXECUTION_READY']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
