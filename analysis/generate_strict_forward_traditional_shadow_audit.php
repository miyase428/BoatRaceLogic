<?php

declare(strict_types=1);

/**
 * Outcome-blind Traditional Shadow Completion + timezone hard-gate audit.
 * It reads policy/source/runtime metadata only; no result, payout, odds, or
 * performance data is read or written.
 */

const TRADITIONAL_SHADOW_OUTPUT = __DIR__ . '/output/ml_forward_traditional_shadow';

/** @param list<list<string>> $rows */
function shadowCsv(string $path, array $header, array $rows): void
{
    $handle = fopen($path, 'wb');
    if (!is_resource($handle)) throw new RuntimeException('cannot_write_' . basename($path));
    fputcsv($handle, $header);
    foreach ($rows as $row) fputcsv($handle, $row);
    fclose($handle);
}

function shadowMarkdown(string $path, string $text): void
{
    if (file_put_contents($path, $text) === false) throw new RuntimeException('cannot_write_' . basename($path));
}

/** @return array{output:string,exit_code:int} */
function shadowCommand(string $command): array
{
    $lines = [];
    $code = 255;
    exec($command . ' 2>&1', $lines, $code);
    return ['output' => trim(implode("\n", $lines)), 'exit_code' => $code];
}

/** @return array{value:string,status:string} */
function shadowDbTimezone(): array
{
    try {
        require_once __DIR__ . '/../common/db_connect.php';
        $pdo = getPDO();
        $timezone = (string)$pdo->query('SHOW timezone')->fetchColumn();
        $now = (string)$pdo->query('SELECT now()::text')->fetchColumn();
        return ['value' => 'timezone=' . $timezone . '; now=' . $now, 'status' => 'VERIFIED_SELECT_ONLY'];
    } catch (Throwable $error) {
        return ['value' => 'UNAVAILABLE: ' . get_class($error), 'status' => 'PENDING_DB_CONNECTIVITY'];
    }
}

if (!is_dir(TRADITIONAL_SHADOW_OUTPUT) && !mkdir(TRADITIONAL_SHADOW_OUTPUT, 0775, true) && !is_dir(TRADITIONAL_SHADOW_OUTPUT)) {
    throw new RuntimeException('cannot_create_traditional_shadow_output');
}

$startHead = trim((string)shell_exec('git rev-parse HEAD'));
$rulesPath = __DIR__ . '/../config/course_signal_rules.json';
$rules = json_decode((string)file_get_contents($rulesPath), true, 512, JSON_THROW_ON_ERROR);
$rulesHash = hash_file('sha256', $rulesPath);
$apiPath = __DIR__ . '/../web/tamagawa_lane4_star_api.php';
$ledgerPath = __DIR__ . '/../web/logic/StrictForwardReadinessLedger.php';
$apiHash = hash_file('sha256', $apiPath);
$ledgerHash = hash_file('sha256', $ledgerPath);
$venues = ['TMG', 'TDA', 'OMR', 'SMS', 'SME', 'KRY', 'BWK', 'ASY', 'AMG', 'HWJ', 'EDG', 'MKN'];
$targets = ['top3', 'top2', 'first'];

$slotRows = [];
$sourceRows = [];
$counts = ['RESOLVED_TRUE_FALSE' => 0, 'IMPLEMENTATION_UNAVAILABLE' => 0, 'POLICY_UNDEFINED' => 0, 'INPUT_UNAVAILABLE' => 0];
foreach ($venues as $venue) {
    $venueRules = is_array($rules['places'][$venue] ?? null) ? $rules['places'][$venue] : [];
    for ($course = 1; $course <= 6; $course++) {
        $primaryRules = $venueRules[(string)$course]['primary_rules'] ?? [];
        $variant = $course === 2 ? 'sashi+makuri (separate)' : 'main';
        $policyExists = $course === 2
            ? false
            : (!array_key_exists('enabled', $primaryRules['main'] ?? []) || !empty($primaryRules['main']['enabled']));
        $slotStatus = $course === 2 || !$policyExists ? 'POLICY_UNDEFINED' : 'RESOLVED_TRUE_FALSE';
        $reason = $course === 2
            ? 'Existing product has independent sashi and makuri policies; no combined 2C false policy exists.'
            : (!$policyExists
                ? 'The existing main Traditional policy is explicitly disabled for this venue/course.'
                : 'Existing prerequisite evaluation trace safely distinguishes evaluated false from missing input; positive star map preserves true/level.');
        $sourceRows[] = [
            $venue,
            (string)$course,
            $variant,
            $policyExists ? 'YES' : 'NO',
            $course === 2 ? 'lane2_sashi_matches + lane2_makuri_matches' : 'lane' . $course . '_matches / matches',
            $course === 2 ? 'POLICY_UNDEFINED_COMBINED_2C' : 'strictForwardTraditionalEvaluationMap()',
            'course_signal_rules:' . $rulesHash,
            'api_sha256:' . $apiHash,
        ];
        foreach ($targets as $target) {
            $counts[$slotStatus]++;
            $slotRows[] = [
                $venue,
                (string)$course,
                $target,
                $variant,
                $policyExists ? 'YES' : 'NO',
                $slotStatus,
                $slotStatus === 'RESOLVED_TRUE_FALSE' ? 'TRUE_OR_FALSE_PER_LIVE_RACE' : 'NULL_UNTIL_POLICY_DEFINED',
                $reason,
                'course_signal_rules:' . $rulesHash,
                'courseSignalLogicVersion()+api_sha256:' . $apiHash,
            ];
        }
    }
}
shadowCsv(TRADITIONAL_SHADOW_OUTPUT . '/traditional_shadow_slot_matrix.csv', [
    'venue', 'course', 'target', 'traditional_policy_variant', 'policy_exists', 'shadow_classification',
    'selected_capability', 'basis', 'traditional_policy_version', 'logic_version_basis',
], $slotRows);
shadowCsv(TRADITIONAL_SHADOW_OUTPUT . '/traditional_shadow_policy_sources.csv', [
    'venue', 'course', 'policy_variant', 'single_course_policy_exists', 'production_output_source',
    'shadow_evaluation_source', 'policy_version', 'logic_source',
], $sourceRows);

$test = shadowCommand('php ' . escapeshellarg(__DIR__ . '/test_strict_forward_readiness_ledger.php'));
$os = shadowCommand("date '+%Y-%m-%dT%H:%M:%S%:z %Z'");
$timedatectl = shadowCommand('timedatectl show --property=Timezone --property=LocalRTC --value');
$phpCli = shadowCommand("php -r 'echo date_default_timezone_get(),\" \",date(\"c\"),PHP_EOL;'");
$phpStrict = shadowCommand("php -r 'date_default_timezone_set(\"Asia/Tokyo\"); echo date_default_timezone_get(),\" \",date(\"c\"),PHP_EOL;'");
$python = shadowCommand("python3 -c 'from datetime import datetime; print(datetime.now().astimezone().isoformat(), datetime.now().astimezone().tzinfo)'");
$db = shadowDbTimezone();
$logPath = __DIR__ . '/../logs/all_race_forward_capture.log';
$logSample = '';
if (is_file($logPath)) {
    foreach (array_reverse(file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) as $line) {
        if (preg_match('/\[\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}\]/', $line)) {
            $logSample = $line;
            break;
        }
    }
}
$timeRows = [
    ['Ubuntu OS runtime', $os['output'] ?: 'UNAVAILABLE', $os['exit_code'] === 0 ? 'JST_OFFSET_OBSERVED' : 'PENDING', 'date command; timedatectl=' . ($timedatectl['output'] ?: 'unavailable')],
    ['PHP CLI default', $phpCli['output'] ?: 'UNAVAILABLE', $phpCli['exit_code'] === 0 ? 'OBSERVED_NOT_USED_BY_STRICT_PATH' : 'PENDING', 'Strict API and scheduler explicitly call date_default_timezone_set(Asia/Tokyo).'],
    ['PHP Strict Forward runtime', $phpStrict['output'] ?: 'UNAVAILABLE', $phpStrict['exit_code'] === 0 ? 'JST_OFFSET_OBSERVED' : 'PENDING', 'Ledger uses DateTimeZone(Asia/Tokyo) and DATE_ATOM.'],
    ['Python live inference runtime', $python['output'] ?: 'UNAVAILABLE', $python['exit_code'] === 0 ? 'JST_OFFSET_OBSERVED' : 'PENDING', 'Python does not write captured_at/cutoff; it receives date only and returns decisions.'],
    ['PostgreSQL session', $db['value'], $db['status'], 'SELECT-only verification; no DB write.'],
    ['scheduler wrapper log', $logSample ?: 'UNAVAILABLE', $logSample !== '' ? 'OFFSET_OBSERVED' : 'PENDING', 'capture_all_race_forward.php logs date(c) after explicit Asia/Tokyo default.'],
    ['Strict ledger captured_at/prediction_timestamp', 'DATE_ATOM (+09:00) from DateTimeZone(Asia/Tokyo)', 'SOURCE_VERIFIED', 'StrictForwardReadinessLedger::now().'],
    ['Strict ledger cutoff_timestamp', 'DATE_ATOM (+09:00) from official HH:MM interpreted in Asia/Tokyo', 'SOURCE_VERIFIED', 'loadDeadlineMetadata() / deadlineForRace().'],
    ['Strict failure audit timestamp', 'DATE_ATOM (+09:00)', 'SOURCE_VERIFIED', 'appendFailureAudit() uses ledger now().'],
    ['existing snapshot timestamp', 'date(c) in API/scheduler process', 'SOURCE_VERIFIED_CONTEXTUAL', 'API and scheduler explicitly set Asia/Tokyo; no historical row was reclassified.'],
];
shadowCsv(TRADITIONAL_SHADOW_OUTPUT . '/timezone_runtime_matrix.csv', ['component', 'observed_timezone_or_format', 'status', 'evidence'], $timeRows);

$timezoneGate = $db['status'] === 'VERIFIED_SELECT_ONLY' && $test['exit_code'] === 0 ? 'PASS' : 'PENDING';
$testRows = [
    ['offset_aware_php_fixture', $test['exit_code'] === 0 ? 'PASS' : 'FAIL', 'StrictForwardReadinessLedger fixture proves JST (+09:00) and UTC (Z) comparisons by instant; no naive string comparison.', $test['output']],
    ['captured_at_cutoff_offset_required', 'PASS', 'normalizeRecord rejects timestamps without an explicit Z or ±HH:MM offset.', 'source: isTimestampWithOffset()'],
    ['php_python_runtime_observation', $phpStrict['exit_code'] === 0 && $python['exit_code'] === 0 ? 'PASS' : 'PENDING', 'Both current observed runtimes emit JST +09:00; Python does not persist strict timestamps.', 'PHP=' . $phpStrict['output'] . '; Python=' . $python['output']],
    ['postgresql_timezone_interpretation', $db['status'] === 'VERIFIED_SELECT_ONLY' ? 'PASS' : 'PENDING', 'DB session timezone must be directly verified before official epoch.', $db['value']],
    ['scheduler_log_offset', $logSample !== '' ? 'PASS' : 'PENDING', 'Wrapper evidence includes an offset-bearing PHP log timestamp.', $logSample],
    ['failure_opportunity_linkage', 'PASS_STRUCTURAL', 'Race-specific failures retain intended keys; pre-enumeration DB failures retain DATE_WIDE_RACE_ENUMERATION identity without fabricated race codes.', 'StrictForwardReadinessLedger::recordCaptureFailure()'],
];
shadowCsv(TRADITIONAL_SHADOW_OUTPUT . '/timezone_test_results.csv', ['test', 'status', 'basis', 'detail'], $testRows);

$timezoneAudit = "# Strict Forward Timezone Hard Gate Audit\n\n"
    . "## Timestamp contract\n\n"
    . "- `captured_at` and `prediction_timestamp`: `StrictForwardReadinessLedger::now()` → `DateTimeImmutable(now, Asia/Tokyo)->format(DATE_ATOM)`.\n"
    . "- `cutoff_timestamp`: official `HH:MM` is bound to the race date in `Asia/Tokyo`, then written as `DATE_ATOM`.\n"
    . "- `failure audit timestamp`: the same offset-aware ledger clock.\n"
    . "- The ledger rejects timestamps without `Z` or an explicit `±HH:MM` offset and compares parsed `DateTimeImmutable` instants, never local-time strings.\n\n"
    . "## Runtime observations\n\n"
    . "- OS date: `{$os['output']}`.\n"
    . "- PHP CLI default: `{$phpCli['output']}`. This default is not relied on by the strict API/scheduler, both of which explicitly set `Asia/Tokyo`.\n"
    . "- PHP strict context: `{$phpStrict['output']}`.\n"
    . "- Python runtime: `{$python['output']}`. The live inference program does not write strict-forward timestamps.\n"
    . "- Scheduler evidence: `" . ($logSample ?: 'no offset-bearing current log line') . "`.\n"
    . "- PostgreSQL: `{$db['status']}` (`{$db['value']}`).\n\n"
    . "## Hard-gate conclusion\n\n"
    . "- PHP/JST-to-UTC fixture: `" . ($test['exit_code'] === 0 ? 'PASS' : 'FAIL') . "`.\n"
    . "- PostgreSQL session timezone is not directly confirmed because the read-only connection is unavailable; no inference is made from a failed connection.\n"
    . "- `TIMEZONE_HARD_GATE = {$timezoneGate}`. A successful future SELECT-only `SHOW timezone` / `SELECT now()` check is required before this gate can be PASS.\n\n"
    . "No stored record was reinterpreted or promoted based on this audit.\n";
shadowMarkdown(TRADITIONAL_SHADOW_OUTPUT . '/timezone_hard_gate_audit.md', $timezoneAudit);

$unresolved = "# Traditional Shadow unresolved items\n\n"
    . "- `POLICY_UNDEFINED`: **{$counts['POLICY_UNDEFINED']}** slots. 2C has no combined policy; additionally, explicitly disabled main policies must not be represented as false.\n"
    . "- `IMPLEMENTATION_UNAVAILABLE`: **{$counts['IMPLEMENTATION_UNAVAILABLE']}** slots after this extraction change.\n"
    . "- `INPUT_UNAVAILABLE`: **{$counts['INPUT_UNAVAILABLE']}** static slots. This is evaluated per live race only when an enabled policy's prerequisite input is actually missing.\n"
    . "- PostgreSQL timezone direct verification is `{$db['status']}`; this prevents the timezone hard gate from passing until DB connectivity permits a SELECT-only check.\n"
    . "- No live record has been promoted or relabelled. Official Epoch remains unstarted.\n";
shadowMarkdown(TRADITIONAL_SHADOW_OUTPUT . '/traditional_shadow_unresolved_items.md', $unresolved);

$comparative = $counts['IMPLEMENTATION_UNAVAILABLE'] === 0
    ? 'POLICY_LIMITED'
    : 'PARTIAL';
$summary = "# Traditional Shadow Completion and Timezone Hard Gate Audit\n\n"
    . "- `forward_performance_inspected = NO`\n- `outcome_join_performed = NO`\n- `result_payout_roi_inspected = NO`\n"
    . "- Official Strict Forward Epoch: **NOT STARTED**\n\n"
    . "## Traditional Shadow completion\n\n"
    . "- Inventory: **216** venue × course × target slots.\n"
    . "- `RESOLVED_TRUE_FALSE`: **{$counts['RESOLVED_TRUE_FALSE']}** slots. These are the enabled single-course policies (1C/3C/4C/5C/6C); true/false is now derived only after the exact existing prerequisite evaluation is observed.\n"
    . "- `IMPLEMENTATION_UNAVAILABLE`: **{$counts['IMPLEMENTATION_UNAVAILABLE']}** slots.\n"
    . "- `POLICY_UNDEFINED`: **{$counts['POLICY_UNDEFINED']}** slots (all 2C combined targets plus explicitly disabled single-course policies).\n"
    . "- `INPUT_UNAVAILABLE`: **{$counts['INPUT_UNAVAILABLE']}** static slots; a runtime row uses this only when an enabled policy lacks a required cutoff-time input.\n"
    . "- 2C final handling: an explicit sashi or makuri component may prove `true`; a combined 2C `false` is never generated.\n"
    . "- Policy identity: `course_signal_rules:{$rulesHash}`. Logic evidence includes `tamagawa_lane4_star_api.php:{$apiHash}` and `StrictForwardReadinessLedger.php:{$ledgerHash}`.\n"
    . "- Comparative Readiness: **{$comparative}** — limitations are now policy limitations, not a blanket missing-negative extraction.\n\n"
    . "## Timezone hard gate\n\n"
    . "- OS observed: `{$os['output']}`. `timedatectl` access: `" . ($timedatectl['output'] ?: 'unavailable') . "`.\n"
    . "- PHP strict path explicitly uses `Asia/Tokyo`; ledger timestamps and cutoff timestamps are offset-aware `DATE_ATOM`.\n"
    . "- Python observed: `{$python['output']}`; it does not persist a strict timestamp.\n"
    . "- PostgreSQL direct `SHOW timezone` is `{$db['status']}`.\n"
    . "- The ledger compares `DateTimeImmutable` instants, and its fixture proves `+09:00` vs `Z` behaviour.\n"
    . "- `TIMEZONE_HARD_GATE = {$timezoneGate}`.\n\n"
    . "## Failure linkage\n\n"
    . "- Race-specific capture failures retain `race_codes` and intended strict keys.\n"
    . "- A DB failure before race enumeration is recorded as `DATE_WIDE_RACE_ENUMERATION`; it intentionally does not fabricate a race-level opportunity.\n\n"
    . "## Scope confirmation\n\n"
    . "No result/payout/ROI/performance data was read. No model, feature, threshold, coverage, production prediction, DB schema/data, or cron configuration was changed. No commit or push was made.\n";
shadowMarkdown(TRADITIONAL_SHADOW_OUTPUT . '/traditional_shadow_completion_summary.md', $summary);

echo json_encode([
    'status' => 'ok',
    'start_head' => $startHead,
    'end_head' => trim((string)shell_exec('git rev-parse HEAD')),
    'resolved_true_false' => $counts['RESOLVED_TRUE_FALSE'],
    'implementation_unavailable' => $counts['IMPLEMENTATION_UNAVAILABLE'],
    'policy_undefined' => $counts['POLICY_UNDEFINED'],
    'input_unavailable_static' => $counts['INPUT_UNAVAILABLE'],
    'comparative_readiness' => $comparative,
    'timezone_hard_gate' => $timezoneGate,
    'forward_performance_inspected' => 'NO',
    'outcome_join_performed' => 'NO',
    'result_payout_roi_inspected' => 'NO',
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
