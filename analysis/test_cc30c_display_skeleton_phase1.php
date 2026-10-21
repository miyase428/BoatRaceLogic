<?php

declare(strict_types=1);

/**
 * Isolated contract tests for the CC30C status-only display skeleton.
 * The production checks are filesystem reads; malformed/missing evidence
 * cases run only against a temporary fixture outside the formal window.
 */

require_once dirname(__DIR__) . '/web/logic/Cc30cDisplayStatusReadModel.php';

$repo = dirname(__DIR__);
$writeReport = in_array('--write-report', $argv, true);
$windowRoot = $repo . '/var/strict_forward_live_readiness/windows/cc30c-clean-window-v4-20261020140615-c8ac4ed2';
$windowId = basename($windowRoot);
$tests = [];

function dsAssert(bool $condition, string $name, array $detail = []): void
{
    global $tests;
    $tests[] = ['name' => $name, 'pass' => $condition, 'detail' => $detail];
    if (!$condition) throw new RuntimeException('ASSERTION_FAILED:' . $name);
}

/** @return array{candidate_count:int,eligibility_count:int,expected_count:int,record_count:int,sha256:string} */
function dsWindowSnapshot(string $root): array
{
    $groups = [
        'candidate_count' => $root . '/opportunity/candidates',
        'eligibility_count' => $root . '/opportunity/eligibility',
        'expected_count' => $root . '/ledger/expected',
        'record_count' => $root . '/ledger/records',
    ];
    $hashes = [];
    $result = [];
    foreach ($groups as $key => $directory) {
        $files = [];
        if (is_dir($directory)) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) $files[] = $file->getPathname();
        }
        sort($files, SORT_STRING);
        $result[$key] = count($files);
        foreach ($files as $path) $hashes[] = substr($path, strlen($root) + 1) . ':' . hash_file('sha256', $path);
    }
    $result['sha256'] = hash('sha256', implode("\n", $hashes));
    return $result;
}

function dsWriteJson(string $path, array $row): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('FIXTURE_DIRECTORY_FAILED');
    file_put_contents($path, json_encode($row, JSON_THROW_ON_ERROR));
}

function dsFixtureCandidatePath(string $root, string $date, string $raceCode): string
{
    return $root . '/opportunity/candidates/' . $date . '/' . hash('sha256', 'scheduler-opportunity|' . $raceCode) . '.json';
}

function dsFixtureResolutionPath(string $root, string $date, string $raceCode): string
{
    return $root . '/opportunity/eligibility/' . $date . '/' . hash('sha256', 'scheduler-opportunity|' . $raceCode) . '.json';
}

function dsRemoveTree(string $path): void
{
    if (!is_dir($path)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

$before = dsWindowSnapshot($windowRoot);
$model = Cc30cDisplayStatusReadModel::fromWindowRoot($windowRoot, $windowId);
$firstNatural = $model->status('20261020MKN12');
dsAssert($firstNatural['status'] === 'FORMAL_CAPTURED', 'first_natural_status', $firstNatural);
dsAssert($firstNatural['formal_evidence_available'] === true && $firstNatural['capture_mode'] === 'LIVE_STRICT', 'first_natural_provenance', $firstNatural);
dsAssert($firstNatural['captured_at'] === '2026-10-20T14:16:59+09:00' && $firstNatural['cutoff_timestamp'] === '2026-10-20T14:29:00+09:00', 'first_natural_timestamps', $firstNatural);

$waiting = null;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($windowRoot . '/opportunity/candidates', FilesystemIterator::SKIP_DOTS)) as $file) {
    if (!$file->isFile()) continue;
    $candidate = json_decode((string)file_get_contents($file->getPathname()), true);
    $raceCode = is_array($candidate) ? (string)($candidate['race_code'] ?? '') : '';
    if ($raceCode === '' || $raceCode === '20261020MKN12') continue;
    $row = $model->status($raceCode);
    if ($row['status'] === 'WAITING_EXHIBITION') { $waiting = $row; break; }
}
dsAssert(is_array($waiting), 'waiting_exhibition_found');
dsAssert(($waiting['status'] ?? null) === 'WAITING_EXHIBITION' && ($waiting['formal_evidence_available'] ?? true) === false, 'waiting_exhibition_status', $waiting ?? []);

$nonTarget = $model->status('20990101ZZZ01');
dsAssert($nonTarget['status'] === 'NOT_TARGET' && $nonTarget['is_candidate'] === false, 'non_target_status', $nonTarget);
dsAssert(Cc30cDisplayStatusReadModel::validRaceCode('bad-input') === false, 'invalid_race_code_rejected');

$fixture = sys_get_temp_dir() . '/cc30c-display-status-fixture-' . getmypid();
try {
    $date = '20990101'; $race = '20990101ZZZ01'; $cutoff = '2099-01-01T12:00:00+09:00';
    $candidate = ['record_type' => 'SCHEDULER_CANDIDATE_UNIVERSE', 'race_code' => $race, 'cutoff_timestamp' => $cutoff, 'source' => 'fixture'];
    $resolution = ['record_type' => 'SCHEDULER_ELIGIBILITY_RESOLUTION', 'race_code' => $race, 'cutoff_timestamp' => $cutoff, 'eligibility_status' => 'ELIGIBLE_OBSERVED'];
    dsWriteJson(dsFixtureCandidatePath($fixture, $date, $race), $candidate);
    dsWriteJson(dsFixtureResolutionPath($fixture, $date, $race), $resolution);
    $fixtureModel = Cc30cDisplayStatusReadModel::fromWindowRoot($fixture, 'fixture-window');
    dsAssert($fixtureModel->status($race)['status'] === 'WAITING_FORMAL_CAPTURE', 'missing_evidence_fail_closed');
    $malformed = $fixture . '/ledger/records/' . $date . '/malformed.json';
    if (!is_dir(dirname($malformed))) mkdir(dirname($malformed), 0700, true);
    file_put_contents($malformed, '{not-json');
    $malformedResult = $fixtureModel->status($race);
    dsAssert($malformedResult['status'] === 'UNAVAILABLE' && $malformedResult['reason'] === 'MALFORMED_FORMAL_EVIDENCE', 'malformed_evidence_fail_closed', $malformedResult);
} finally {
    dsRemoveTree($fixture);
}

$apiSource = (string)file_get_contents($repo . '/web/tenkai_ai_status_api.php');
dsAssert(str_contains($apiSource, 'METHOD_NOT_ALLOWED') && str_contains($apiSource, 'INVALID_RACE_CODE'), 'api_fail_closed_contract');
dsAssert(!str_contains($apiSource, 'PDO') && !str_contains($apiSource, 'file_put_contents') && !str_contains($apiSource, 'curl_'), 'api_read_only_static');
$pcView = (string)file_get_contents($repo . '/web/views/index_view.php');
$appView = (string)file_get_contents($repo . '/web/views/app_view.php');
$js = (string)file_get_contents($repo . '/web/assets/js/tenkai_ai_status.js');
$tabs = (string)file_get_contents($repo . '/web/assets/js/pc_main_tabs.js');
dsAssert(str_contains($pcView, 'data-tenkai-ai-panel') && str_contains($pcView, 'tenkai_ai_status.js?v='), 'pc_panel_rendering');
dsAssert(str_contains($appView, 'data-tenkai-ai-panel') && str_contains($appView, 'tenkai_ai_status.js?v='), 'app_panel_rendering');
dsAssert(str_contains($tabs, "hasAttribute('data-tenkai-ai-panel')"), 'pc_basic_tab_integration');
dsAssert(str_contains($js, '/web/tenkai_ai_status_api.php') && str_contains($js, '展開AIを取得できませんでした'), 'shared_ui_api_contract');
dsAssert(str_contains($js, '!response.ok || !validPayload(data, raceCode)') && str_contains($js, 'catch (error) { renderUnavailable(panel); }'), 'ui_api_failure_fail_closed');
dsAssert(!str_contains($js, 'probability') && !str_contains($js, 'threshold') && !str_contains($js, 'ranking'), 'live_numeric_values_disabled');

$after = dsWindowSnapshot($windowRoot);
dsAssert($before === $after, 'production_window_unchanged', ['before' => $before, 'after' => $after]);

$allPass = !array_filter($tests, static fn(array $test): bool => !$test['pass']);
$report = [
    'schema_version' => 'cc30c-display-skeleton-phase1-report/v1',
    'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM),
    'source_script' => 'analysis/test_cc30c_display_skeleton_phase1.php',
    'source_script_sha256' => 'sha256:' . hash_file('sha256', __FILE__),
    'clean_window_id' => $windowId,
    'first_natural_race' => $firstNatural,
    'waiting_exhibition_race' => $waiting,
    'window_before' => $before,
    'window_after' => $after,
    'tests' => $tests,
    'DISPLAY_STATUS_API' => $allPass ? 'READY' : 'FAIL',
    'PC_DISPLAY_SKELETON' => $allPass ? 'READY' : 'FAIL',
    'APP_DISPLAY_SKELETON' => $allPass ? 'READY' : 'FAIL',
    'FIRST_NATURAL_RACE_DISPLAY' => $allPass ? 'PASS' : 'FAIL',
    'WAIT_STATUS_DISPLAY' => $allPass ? 'PASS' : 'FAIL',
    'MUTATION_SIDE_EFFECT' => $before === $after ? 0 : 'NONZERO',
    'LIVE_NUMERIC_VALUE_DISPLAY' => 'DISABLED',
];

if ($writeReport) {
    $path = $repo . '/analysis/output/cc30c_display_skeleton_phase1_report.json';
    if (file_exists($path)) throw new RuntimeException('CREATE_ONLY_OUTPUT_ALREADY_EXISTS:' . $path);
    $handle = fopen($path, 'x');
    if ($handle === false) throw new RuntimeException('CREATE_ONLY_OUTPUT_FAILED');
    try { fwrite($handle, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL); }
    finally { fclose($handle); }
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($allPass ? 0 : 1);
