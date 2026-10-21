<?php

declare(strict_types=1);

/** Browser-readiness audit. It never starts a server or browser, and writes a
 * report only with --write-report. The JS behavior is exercised by the
 * dependency-free DOM harness, explicitly labeled as non-browser evidence. */

require_once dirname(__DIR__) . '/web/logic/Cc30cDisplayStatusReadModel.php';

$repo = dirname(__DIR__);
$writeReport = in_array('--write-report', $argv, true);
$windowRoot = $repo . '/var/strict_forward_live_readiness/windows/cc30c-clean-window-v4-20261020140615-c8ac4ed2';

/** @return array{candidate_count:int,eligibility_count:int,expected_count:int,record_count:int,sha256:string} */
function ds1bSnapshot(string $root): array
{
    $groups = ['candidate_count' => '/opportunity/candidates', 'eligibility_count' => '/opportunity/eligibility', 'expected_count' => '/ledger/expected', 'record_count' => '/ledger/records'];
    $result = []; $hashes = [];
    foreach ($groups as $key => $suffix) {
        $directory = $root . $suffix; $files = [];
        if (is_dir($directory)) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) if ($file->isFile()) $files[] = $file->getPathname();
        sort($files, SORT_STRING); $result[$key] = count($files);
        foreach ($files as $file) $hashes[] = substr($file, strlen($root) + 1) . ':' . hash_file('sha256', $file);
    }
    $result['sha256'] = hash('sha256', implode("\n", $hashes));
    return $result;
}

function ds1bCommand(string $command): array
{
    $output = []; $status = 1;
    exec($command . ' 2>&1', $output, $status);
    return ['status' => $status, 'output' => implode("\n", $output)];
}

/** @return array<string,mixed> */
function ds1bEndpoint(string $repo, string $raceCode): array
{
    $php = '$_SERVER["REQUEST_METHOD"]="GET"; $_GET["race_code"]=' . var_export($raceCode, true)
        . '; include ' . var_export($repo . '/web/tenkai_ai_status_api.php', true) . ';';
    $result = ds1bCommand(escapeshellcmd(PHP_BINARY) . ' -r ' . escapeshellarg($php));
    try { $body = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR); } catch (Throwable) { $body = null; }
    return ['command_status' => $result['status'], 'body' => $body];
}

/** @return array{race_code:string,status:array<string,mixed>} */
function ds1bFindCurrentWait(Cc30cDisplayStatusReadModel $model, string $windowRoot): array
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($windowRoot . '/opportunity/candidates', FilesystemIterator::SKIP_DOTS)) as $file) {
        if (!$file->isFile()) continue;
        $candidate = json_decode((string)file_get_contents($file->getPathname()), true);
        $raceCode = is_array($candidate) ? (string)($candidate['race_code'] ?? '') : '';
        if ($raceCode === '') continue;
        $status = $model->status($raceCode);
        if ($status['status'] === 'WAITING_EXHIBITION') return ['race_code' => $raceCode, 'status' => $status];
    }
    throw new RuntimeException('CURRENT_WAIT_RACE_NOT_FOUND');
}

$before = ds1bSnapshot($windowRoot);
$model = Cc30cDisplayStatusReadModel::production($repo);
$formal = $model->status('20261020MKN12');
$waitSelection = ds1bFindCurrentWait($model, $windowRoot);
$wait = $waitSelection['status'];
$endpointFormal = ds1bEndpoint($repo, '20261020MKN12');
$endpointWait = ds1bEndpoint($repo, $waitSelection['race_code']);
$endpointInvalid = ds1bEndpoint($repo, 'invalid');
$dom = ds1bCommand('TZ=Asia/Tokyo node ' . escapeshellarg($repo . '/analysis/test_cc30c_display_skeleton_phase1b_dom.js'));
try { $domResult = json_decode($dom['output'], true, 512, JSON_THROW_ON_ERROR); } catch (Throwable) { $domResult = null; }
$after = ds1bSnapshot($windowRoot);

$browserBinary = trim((string)shell_exec('command -v chromium || command -v chromium-browser || command -v google-chrome || command -v firefox || true'));
$playwrightInstalled = is_dir($repo . '/node_modules/playwright') || is_dir($repo . '/node_modules/playwright-core');
$browserAvailable = $browserBinary !== '' || $playwrightInstalled;
$domPass = $dom['status'] === 0 && is_array($domResult) && empty(array_filter($domResult['tests'] ?? [], static fn(array $test): bool => empty($test['pass'])));
$apiPass = ($formal['status'] ?? null) === 'FORMAL_CAPTURED'
    && ($formal['capture_mode'] ?? null) === 'LIVE_STRICT'
    && ($formal['captured_at'] ?? null) === '2026-10-20T14:16:59+09:00'
    && ($formal['cutoff_timestamp'] ?? null) === '2026-10-20T14:29:00+09:00'
    && ($wait['status'] ?? null) === 'WAITING_EXHIBITION'
    && ($endpointFormal['body']['status'] ?? null) === 'FORMAL_CAPTURED'
    && ($endpointWait['body']['status'] ?? null) === 'WAITING_EXHIBITION'
    && ($endpointInvalid['body']['error_code'] ?? null) === 'INVALID_RACE_CODE';
$mutation = $before === $after ? 0 : 'NONZERO';

$report = [
    'schema_version' => 'cc30c-display-skeleton-phase1b-browser-report/v1',
    'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM),
    'audit_mode' => 'READ_ONLY_NO_BROWSER_RUNTIME_NO_SERVER_START',
    'source_script' => 'analysis/test_cc30c_display_skeleton_phase1b.php',
    'source_script_sha256' => 'sha256:' . hash_file('sha256', __FILE__),
    'api_regression' => ['formal' => $formal, 'wait' => $wait, 'endpoint_formal' => $endpointFormal, 'endpoint_wait' => $endpointWait, 'endpoint_invalid' => $endpointInvalid, 'pass' => $apiPass],
    'dom_harness' => ['available' => true, 'kind' => is_array($domResult) ? ($domResult['kind'] ?? null) : null, 'pass' => $domPass, 'result' => $domResult],
    'browser_environment' => ['browser_binary' => $browserBinary !== '' ? basename($browserBinary) : null, 'playwright_installed' => $playwrightInstalled, 'browser_runtime_available' => $browserAvailable, 'local_web_server_started' => false],
    'screenshots' => ['attempted' => false, 'reason' => 'BROWSER_RUNTIME_UNAVAILABLE'],
    'window_before' => $before,
    'window_after' => $after,
    'PC_BROWSER_RENDER' => $browserAvailable ? 'NOT_RUN' : 'NOT_TESTED_BROWSER_RUNTIME_UNAVAILABLE',
    'MOBILE_RESPONSIVE_RENDER' => $browserAvailable ? 'NOT_RUN' : 'NOT_TESTED_BROWSER_RUNTIME_UNAVAILABLE',
    'FORMAL_CAPTURED_RENDER' => $apiPass && $domPass ? 'PASS_DOM_HARNESS' : 'FAIL',
    'WAIT_RENDER' => $apiPass && $domPass ? 'PASS_DOM_HARNESS' : 'FAIL',
    'RACE_SWITCHING' => $domPass ? 'PASS_DOM_HARNESS' : 'FAIL',
    'LOADING_ERROR' => $domPass ? 'PASS_DOM_HARNESS' : 'FAIL',
    'UI_API_MUTATION_SIDE_EFFECT' => $mutation,
    'REAL_DEVICE_PWA' => 'NOT_TESTED',
    'LIVE_NUMERIC_VALUE_DISPLAY' => 'DISABLED',
    'DISPLAY_SKELETON_PHASE1B' => ($apiPass && $domPass && $mutation === 0 && $browserAvailable) ? 'PASS' : 'BLOCKED_REAL_BROWSER_UNAVAILABLE',
];

if ($writeReport) {
    $path = $repo . '/analysis/output/cc30c_display_skeleton_phase1b_browser_report.json';
    if (file_exists($path)) throw new RuntimeException('CREATE_ONLY_OUTPUT_ALREADY_EXISTS:' . $path);
    $handle = fopen($path, 'x');
    if ($handle === false) throw new RuntimeException('CREATE_ONLY_OUTPUT_FAILED');
    try { fwrite($handle, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL); }
    finally { fclose($handle); }
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit(($apiPass && $domPass && $mutation === 0) ? 0 : 1);
