<?php

declare(strict_types=1);

/**
 * CC30C display-readiness audit.
 * Static/read-only: it makes no HTTP request, opens no DB connection, and
 * never calls the live Course Signal API because that endpoint can append
 * forward evidence when invoked by a formal scheduler.
 */

$repo = dirname(__DIR__);
$reportPath = $repo . '/analysis/output/cc30c_display_readiness_audit.json';
$inventoryPath = $repo . '/analysis/output/cc30c_display_field_inventory.csv';
$mapPath = $repo . '/analysis/output/cc30c_display_integration_map.json';
$write = in_array('--write-report', $argv, true);

function drJson(string $path): array {
    try { $value = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); return is_array($value) ? $value : []; }
    catch (Throwable) { return []; }
}
/** @return list<array{path:string,row:array<string,mixed>}> */
function drFiles(string $root): array {
    if (!is_dir($root)) return [];
    $rows = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (!$file->isFile() || !str_ends_with($file->getFilename(), '.json')) continue;
        $row = drJson($file->getPathname());
        if ($row !== []) $rows[] = ['path' => $file->getPathname(), 'row' => $row];
    }
    return $rows;
}
function drCsv(mixed $value): string { return '"' . str_replace('"', '""', (string)$value) . '"'; }
function drCreateOnly(string $path, string $contents): void {
    $handle = fopen($path, 'x');
    if ($handle === false) throw new RuntimeException('CREATE_ONLY_WRITE_FAILED:' . $path);
    try { if (fwrite($handle, $contents) === false) throw new RuntimeException('WRITE_FAILED:' . $path); }
    finally { fclose($handle); }
}

$window = 'cc30c-clean-window-v4-20261020140615-c8ac4ed2';
$root = $repo . '/var/strict_forward_live_readiness/windows/' . $window;
$records = array_values(array_filter(drFiles($root . '/ledger/records'), static fn(array $entry): bool => ($entry['row']['capture_mode'] ?? '') === 'LIVE_STRICT'));
$fields = [];
foreach ($records as $entry) foreach ($entry['row'] as $name => $value) {
    if (!isset($fields[$name])) $fields[$name] = ['types' => [], 'values' => [], 'present' => 0, 'nulls' => 0];
    $fields[$name]['present']++;
    if ($value === null) { $fields[$name]['nulls']++; continue; }
    $type = gettype($value); $fields[$name]['types'][$type] = true;
    if (is_scalar($value)) $fields[$name]['values'][(string)$value] = true;
}

$classification = [
    'race_code' => ['semantic' => '対象レース識別子', 'scope' => 'race-level', 'availability' => 'formal capture後', 'display_policy' => 'status/context only'],
    'venue' => ['semantic' => '開催場コード', 'scope' => 'race-level', 'availability' => 'formal capture後', 'display_policy' => 'status/context only'],
    'course' => ['semantic' => '実行されたコース signal', 'scope' => 'boat/course-level', 'availability' => 'formal capture後', 'display_policy' => 'not a standalone user value'],
    'target' => ['semantic' => 'モデル target (first/top2/top3)', 'scope' => 'boat/course-level', 'availability' => 'formal capture後', 'display_policy' => 'not a standalone user value'],
    'eligible' => ['semantic' => '展示・モデル入力が正式 capture 条件を満たしたか', 'scope' => 'race/course/target-level', 'availability' => 'eligibility resolution後', 'display_policy' => 'future status candidate'],
    'capture_status' => ['semantic' => 'capture時の状態コード', 'scope' => 'race/course/target-level', 'availability' => 'formal capture後', 'display_policy' => 'future status candidate'],
    'eligibility_reason' => ['semantic' => 'eligible / unavailable の理由コード', 'scope' => 'race/course/target-level', 'availability' => 'eligibility resolution後', 'display_policy' => 'future fail-closed status candidate'],
    'captured_at' => ['semantic' => 'formal record capture timestamp', 'scope' => 'race-level', 'availability' => 'formal capture後', 'display_policy' => 'provenance/status only'],
    'cutoff_timestamp' => ['semantic' => '公式締切', 'scope' => 'race-level', 'availability' => 'candidate生成時', 'display_policy' => 'provenance/status only'],
    'capture_mode' => ['semantic' => 'capture mode', 'scope' => 'record-level', 'availability' => 'formal capture後', 'display_policy' => 'provenance only'],
    'capture_source' => ['semantic' => 'formal producer source', 'scope' => 'record-level', 'availability' => 'formal capture後', 'display_policy' => 'provenance only'],
    'ml_selected' => ['semantic' => '当該モデル targetの選択 boolean', 'scope' => 'course/target-level', 'availability' => 'formal capture後', 'display_policy' => 'NOT AUTHORIZED for customer display before formal validation'],
    'ml_threshold' => ['semantic' => 'research/operational selection threshold', 'scope' => 'course/target-level', 'availability' => 'formal capture後', 'display_policy' => 'never expose as UI value'],
    'selection_subject_id' => ['semantic' => 'selection subject internal identifier', 'scope' => 'boat-level', 'availability' => 'formal capture後', 'display_policy' => 'never expose'],
];

$inventory = [];
ksort($fields, SORT_STRING);
foreach ($fields as $field => $data) {
    $policy = $classification[$field] ?? ['semantic' => 'provenance / implementation traceability', 'scope' => 'record-level', 'availability' => 'formal capture後', 'display_policy' => 'provenance only'];
    $values = array_keys($data['values']); sort($values, SORT_STRING);
    $inventory[] = [
        'field' => $field, 'semantic' => $policy['semantic'], 'type' => implode('|', array_keys($data['types'])),
        'observed_range_or_values' => count($values) <= 8 ? implode('|', $values) : ('distinct=' . count($values)),
        'nullable' => $data['nulls'] > 0 ? 'yes' : 'no', 'availability_timing' => $policy['availability'],
        'scope' => $policy['scope'], 'live_strict_origin' => 'yes', 'display_policy' => $policy['display_policy'],
    ];
}

$integration = [
    'existing_read_only_status_api' => [
        'path' => 'web/forward_status_api.php', 'consumer' => 'web/assets/js/home_forward_status.js',
        'view' => 'web/home.php#forward-status-panel', 'scope' => 'window aggregate status only',
        'safe_for_browser_read' => true, 'per_race_cc30c_value_contract' => false,
    ],
    'pc_race_detail_course_signal_panel' => [
        'path' => 'web/assets/js/pc_main_tabs.js', 'existing_endpoint' => 'web/tamagawa_lane4_star_api.php',
        'scope' => 'per-race course signals', 'safe_as_cc30c_read_endpoint' => false,
        'reason' => 'endpoint can write display snapshots/cache and, under formal scheduler environment, forward evidence; do not reuse for a read-only CC30C display API',
    ],
    'app_race_detail_course_signal_panel' => [
        'view' => 'web/views/app_view.php#app-course-signals', 'script' => 'web/assets/js/app_course_signals.js',
        'existing_endpoint' => 'web/tamagawa_lane4_star_api.php', 'safe_as_cc30c_read_endpoint' => false,
        'reason' => 'same side-effecting endpoint as PC path',
    ],
    'minimum_future_read_path' => [
        'api_requirement' => 'new GET-only, filesystem-read-only projection over authorized display fields; no invocation of producer/collector/live signal API',
        'pc_integration_point' => 'web/assets/js/pc_main_tabs.js basic tab, adjacent to existing course-signal section',
        'app_integration_point' => 'web/views/app_view.php, adjacent to #app-course-signals',
        'status_only_skeleton_path' => 'existing web/home.php forward-status-panel via web/forward_status_api.php',
    ],
];

$report = [
    'schema_version' => 'cc30c-display-readiness-audit/v1',
    'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM),
    'audit_mode' => 'READ_ONLY_STATIC_NO_HTTP_NO_DB_NO_PRODUCER',
    'script' => 'analysis/audit_cc30c_display_readiness.php', 'script_sha256' => 'sha256:' . hash_file('sha256', __FILE__),
    'clean_window_id' => $window, 'formal_live_strict_record_count' => count($records), 'formal_live_strict_race_count' => count(array_unique(array_map(static fn(array $e): string => (string)($e['row']['race_code'] ?? ''), $records))),
    'field_inventory' => $inventory,
    'integration_map' => $integration,
    'fail_closed_ui_proposal' => [
        'formal_output_available' => 'Only render an authorized status/value supplied by a future read-only API.',
        'pending' => 'データ待ち', 'non_eligible' => '対象外', 'unavailable' => '取得不能',
        'zero_or_inferred_value_for_missing_data' => 'PROHIBITED',
    ],
    'readiness' => [
        'DISPLAY_DATA_CONTRACT' => 'INCOMPLETE',
        'DISPLAY_API' => 'NEEDS_IMPLEMENTATION',
        'DISPLAY_UI_INTEGRATION_POINT' => 'IDENTIFIED',
        'DISPLAY_SKELETON_IMPLEMENTATION' => 'READY',
        'LIVE_VALUE_PRODUCTION_DISPLAY' => 'WAIT_FORMAL_VALIDATION',
        'reason' => 'Formal records are provenance-rich measurement evidence, not a user-display contract; they contain selection flags but no authorized public score/probability field or race-level read model. One natural chain is insufficient for formal validation.',
    ],
    'performance_evaluation_performed' => false,
];

if ($write) {
    foreach ([$reportPath, $inventoryPath, $mapPath] as $path) if (file_exists($path)) throw new RuntimeException('CREATE_ONLY_OUTPUT_ALREADY_EXISTS:' . $path);
    drCreateOnly($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);
    $csv = "field,semantic,type,observed_range_or_values,nullable,availability_timing,scope,live_strict_origin,display_policy\n";
    foreach ($inventory as $row) $csv .= implode(',', array_map('drCsv', $row)) . "\n";
    drCreateOnly($inventoryPath, $csv);
    drCreateOnly($mapPath, json_encode($integration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);
}
echo json_encode($report['readiness'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
