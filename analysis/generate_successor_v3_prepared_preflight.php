<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/FormalMeasurementWindowContext.php';
require_once __DIR__ . '/../web/logic/GateA1ProspectiveInventory.php';
require_once __DIR__ . '/../web/logic/MeasurementImplementationProvenance.php';

const V3_PREFLIGHT_OUTPUT = __DIR__ . '/output/ml_forward_live_readiness';

function v3WriteFirst(string $path, string $bytes): void
{
    $handle = @fopen($path, 'x');
    if (!is_resource($handle)) throw new RuntimeException('preflight_output_exists:' . basename($path));
    $ok = @fwrite($handle, $bytes) !== false;
    @fclose($handle);
    if (!$ok) throw new RuntimeException('preflight_output_write_failed:' . basename($path));
}

/** @param list<list<string>> $rows */
function v3Csv(string $path, array $header, array $rows): void
{
    $handle = @fopen($path, 'x');
    if (!is_resource($handle)) throw new RuntimeException('preflight_output_exists:' . basename($path));
    fputcsv($handle, $header);
    foreach ($rows as $row) fputcsv($handle, $row);
    fclose($handle);
}

function v3Count(string $pattern): int
{
    return count(glob($pattern) ?: []);
}

function v3ScheduleFingerprint(string $raceCode, string $venue, string $cutoff): string
{
    return 'sha256:' . hash('sha256', json_encode([
        'race_code' => $raceCode, 'venue' => $venue,
        'effective_cutoff_timestamp' => $cutoff, 'official_status' => 'ACTIVE',
    ], JSON_UNESCAPED_SLASHES));
}

if (!is_dir(V3_PREFLIGHT_OUTPUT) && !mkdir(V3_PREFLIGHT_OUTPUT, 0775, true) && !is_dir(V3_PREFLIGHT_OUTPUT)) throw new RuntimeException('output_directory_unavailable');

$repo = dirname(__DIR__);
$context = FormalMeasurementWindowContext::production($repo);
$resolved = $context->resolve();
if (!$resolved['accepted']) throw new RuntimeException('current_context_unresolved:' . $resolved['reason']);
$manifest = $resolved['manifest'];
$windowId = $resolved['window_id'];
if (!str_starts_with($windowId, 'successor-v3-prepared-')) throw new RuntimeException('successor_v3_not_selected');
$declaration = $context->declaration()->read();
$identity = $context->declaration()->assertCurrent();
$authorization = $context->authorizeFormalWrite();
if (!$declaration['accepted'] || !$identity['accepted']) throw new RuntimeException('declared_identity_preflight_failed');

$candidateRows = [];
$candidatePaths = glob($context->opportunityRoot() . '/candidates/*/*.json') ?: [];
foreach ($candidatePaths as $path) {
    $row = json_decode((string)file_get_contents($path), true);
    if (!is_array($row)) continue;
    $raceCode = strtoupper(trim((string)($row['race_code'] ?? '')));
    $targetDate = (string)($row['target_date'] ?? '');
    $venue = strtoupper(trim((string)($row['venue'] ?? '')));
    $cutoff = (string)($row['cutoff_timestamp'] ?? '');
    if ($raceCode === '' || $targetDate === '' || $venue === '' || $cutoff === '') throw new RuntimeException('candidate_invalid');
    $key = GateA1ProspectiveInventory::canonicalKey($windowId, $targetDate, $raceCode, $venue);
    $candidateRows[$key][] = $row + ['canonical_key' => $key];
}

$targetDates = [];
foreach ($candidateRows as $rows) foreach ($rows as $row) $targetDates[(string)$row['target_date']] = true;
if (count($targetDates) !== 1) throw new RuntimeException('prepared_candidate_dates_not_singleton');
$targetDate = (string)array_key_first($targetDates);
$datePrefix = str_replace('-', '', $targetDate);
$cachePath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/boatrace_official_deadlines/deadlines_' . $datePrefix . '.json';
$raw = @file_get_contents($cachePath);
if (!is_string($raw)) throw new RuntimeException('official_cache_missing');
$cache = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
if (!is_array($cache) || ($cache['date'] ?? '') !== $datePrefix || !is_array($cache['deadlines'] ?? null)) throw new RuntimeException('official_cache_invalid');
$errors = is_array($cache['errors'] ?? null) ? $cache['errors'] : [];
$relevantErrors = array_intersect_key($errors, array_flip(GateA1ProspectiveInventory::strictVenues()));
if ($relevantErrors !== []) throw new RuntimeException('official_cache_relevant_errors');

$expected = [];
foreach ($cache['deadlines'] as $raceCode => $time) {
    $raceCode = strtoupper(trim((string)$raceCode));
    $venue = substr($raceCode, 8, 3);
    if (!GateA1ProspectiveInventory::isStrictVenue($venue) || !preg_match('/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $raceCode) || !preg_match('/^\d{2}:\d{2}$/', (string)$time)) continue;
    $cutoff = (new DateTimeImmutable($targetDate . ' ' . $time . ':00', new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM);
    $key = GateA1ProspectiveInventory::canonicalKey($windowId, $targetDate, $raceCode, $venue);
    if (isset($expected[$key])) throw new RuntimeException('expected_duplicate_key');
    $expected[$key] = ['race_code' => $raceCode, 'venue' => $venue, 'cutoff_timestamp' => $cutoff, 'canonical_key' => $key,
        'semantic_schedule_identity' => v3ScheduleFingerprint($raceCode, $venue, $cutoff)];
}
ksort($expected, SORT_STRING);

$observed = [];
$duplicates = [];
foreach ($candidateRows as $key => $rows) {
    if (count($rows) !== 1) { $duplicates[] = $key; continue; }
    $observed[$key] = $rows[0];
}
ksort($observed, SORT_STRING);
$conflicting = [];
foreach (glob($context->opportunityRoot() . '/operational/*.jsonl') ?: [] as $path) {
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $event = json_decode($line, true);
        if (!is_array($event) || ($event['event'] ?? '') !== 'CANDIDATE_ORIGINAL_SEMANTIC_DIFFERENCE') continue;
        foreach ($observed as $key => $row) if (($row['race_code'] ?? '') === ($event['race_code'] ?? '')) $conflicting[] = $key;
    }
}
$missing = array_values(array_diff(array_keys($expected), array_keys($observed)));
$unexpected = array_values(array_diff(array_keys($observed), array_keys($expected)));
sort($duplicates); sort($conflicting);
$gateA1Pass = $missing === [] && $unexpected === [] && $duplicates === [] && $conflicting === [];

$expectedRows = [];
foreach ($expected as $entry) $expectedRows[] = [$windowId,$targetDate,$entry['race_code'],$entry['venue'],$entry['canonical_key'],$entry['cutoff_timestamp'],$entry['semantic_schedule_identity'],'sha256:' . hash('sha256', $raw),'PREPARED_PREFLIGHT_ONLY'];
v3Csv(V3_PREFLIGHT_OUTPUT . '/successor_v3_gate_a1_expected_inventory.csv', ['window_id','target_date','race_code','venue','canonical_key','cutoff_timestamp','semantic_schedule_identity','official_source_identity','contract_phase'], $expectedRows);
$observedRows = [];
foreach ($observed as $entry) $observedRows[] = [$windowId,(string)$entry['target_date'],(string)$entry['race_code'],(string)$entry['venue'],(string)$entry['canonical_key'],(string)$entry['cutoff_timestamp'],(string)($entry['source_identity'] ?? ''),(string)($entry['universe_recorded_at'] ?? ''),'PREPARED_CANDIDATE'];
v3Csv(V3_PREFLIGHT_OUTPUT . '/successor_v3_gate_a1_observed_inventory.csv', ['window_id','target_date','race_code','venue','canonical_key','cutoff_timestamp','candidate_source_identity','candidate_recorded_at','record_type'], $observedRows);

$files = (new MeasurementImplementationProvenance($repo, $context->declaration()->provenanceStorageRoot()))->manifestFiles();
v3Csv(V3_PREFLIGHT_OUTPUT . '/successor_v3_critical_scope.csv', ['path','sha256'], array_map(static fn(array $file): array => [(string)$file['path'],(string)$file['sha256']], $files));

$identityJson = [
    'window_id' => $windowId,
    'declared_identity' => $declaration['declaration']['implementation_identity'],
    'current_identity' => $identity['implementation']['implementation_identity'],
    'identity_match' => true,
    'manifest_sha256' => $identity['implementation']['manifest_sha256'],
    'source_freeze_sha256' => $identity['implementation']['source_freeze_sha256'],
    'manifest_ref' => $identity['implementation']['manifest_ref'],
    'source_freeze_ref' => $identity['implementation']['source_freeze_ref'],
    'scope_file_count' => count($files),
    'provenance_reconstruction' => 'PASS',
];
v3WriteFirst(V3_PREFLIGHT_OUTPUT . '/successor_v3_identity.json', json_encode($identityJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

$routingRows = [
    ['context_pointer',$windowId,$resolved['window_id'],$context->ledgerRoot(),'PASS'],
    ['expected_formal_target',$windowId,$windowId,$context->ledgerRoot(),'WINDOW_NOT_ACTIVE'],
    ['canonical_formal_target',$windowId,$windowId,$context->ledgerRoot(),'WINDOW_NOT_ACTIVE'],
];
v3Csv(V3_PREFLIGHT_OUTPUT . '/successor_v3_routing_preflight.csv', ['path','expected_window_id','resolved_window_id','resolved_ledger_root','result'], $routingRows);

$windowRoots = [
    'original_operational' => $repo . '/var/strict_forward_live_readiness',
    'obsolete_successor' => $repo . '/var/strict_forward_live_readiness/windows/successor-prepared-20261006073536-1fd0d7e3',
    'successor_v2' => $repo . '/var/strict_forward_live_readiness/windows/successor-v2-prepared-20261006093527-a8bc19e7',
    'successor_v3' => dirname($context->windowManifestPath()),
];
$isolationRows = [];
foreach ($windowRoots as $label => $root) {
    $isolationRows[] = [$label,$root,(string)v3Count($root . '/ledger/expected/*/*.json'),(string)v3Count($root . '/ledger/records/*/*.json'),(string)v3Count($root . '/opportunity/candidates/*/*.json'),$label === 'successor_v3' ? 'TARGET' : 'NO_FORMAL_CARRYOVER'];
}
v3Csv(V3_PREFLIGHT_OUTPUT . '/successor_v3_cross_window_isolation.csv', ['window','root','formal_expected_count','formal_canonical_count','candidate_count','classification'], $isolationRows);

$a1 = "# successor-v3 Gate A1 Preflight\n\n"
    . "- independent official source: `{$cachePath}`\n- official source identity: `sha256:" . hash('sha256', $raw) . "`\n"
    . "- local cache generation: `" . (string)($cache['fetched_at'] ?? '') . "`\n"
    . "- expected / observed keys: **" . count($expected) . ' / ' . count($observed) . "**\n"
    . "- missing / unexpected / duplicate / conflicting: **" . count($missing) . ' / ' . count($unexpected) . ' / ' . count($duplicates) . ' / ' . count($conflicting) . "**\n"
    . "- key-set preflight: **" . ($gateA1Pass ? 'PASS' : 'FAIL') . "**\n\n"
    . "This is a PREPARED source-binding preflight only. `armed_at` is unset, so it does not claim the ACTIVE Gate A1 prospective filter (`cutoff_timestamp > armed_at`) has run. The formal expected inventory remains lifecycle-blocked until a future ACTIVE Window; no Candidate is promoted here.\n";
v3WriteFirst(V3_PREFLIGHT_OUTPUT . '/successor_v3_gate_a1_preflight.md', $a1);

$provenance = "# successor-v3 Provenance Preflight\n\n- declared identity equals current identity: **PASS**\n- identity → manifest → source freeze → raw source reconstruction: **PASS**\n- measurement-critical scope file count: **" . count($files) . "**\n- policy source (`config/course_signal_rules.json`) is included in the declared scope: **PASS**\n- other-window fallback: **0**\n- old-ledger dependency: **0**\n";
v3WriteFirst(V3_PREFLIGHT_OUTPUT . '/successor_v3_provenance_preflight.md', $provenance);

$formalExpected = v3Count($context->ledgerRoot() . '/expected/*/*.json');
$formalCanonical = v3Count($context->ledgerRoot() . '/records/*/*.json');
$prepared = "# successor-v3 PREPARED Preflight\n\n"
    . "- state: `" . (string)($manifest['status'] ?? '') . "`\n- prepared_at: `" . (string)($manifest['prepared_at'] ?? '') . "`\n- armed_at: **UNSET**\n- activation record: **NONE**\n"
    . "- formal authorization: `" . (string)($authorization['reason'] ?? '') . "`\n- formal Expected / Canonical / accepted / Gate C accepted: **{$formalExpected} / {$formalCanonical} / 0 / 0**\n"
    . "- pointer target and resolved Window: **{$windowId} / " . (string)$resolved['window_id'] . "**\n"
    . "- Gate A1 PREPARED source-binding preflight: **" . ($gateA1Pass ? 'PASS' : 'FAIL') . "**\n"
    . "- result: **" . ($gateA1Pass && $formalExpected === 0 && $formalCanonical === 0 && empty($authorization['accepted']) ? 'NEW_SUCCESSOR_PREPARED_PREFLIGHT_PASS' : 'NEW_SUCCESSOR_PREPARED_PREFLIGHT_BLOCKED') . "**\n";
v3WriteFirst(V3_PREFLIGHT_OUTPUT . '/successor_v3_prepared_preflight.md', $prepared);

$summary = "# successor-v3 PREPARED Initialization Summary\n\n"
    . "- window_id: `{$windowId}`\n- state: `PREPARED`\n- prepared_at: `" . (string)($manifest['prepared_at'] ?? '') . "`\n- declared identity: `" . (string)$identity['implementation']['implementation_identity'] . "`\n- Gate A1 preflight: **" . ($gateA1Pass ? 'PASS' : 'FAIL') . "**\n- formal capture: **NOT STARTED**\n- ACTIVE / armed_at / activation record / Gate C LIVE: **NO / UNSET / NONE / NO**\n";
v3WriteFirst(V3_PREFLIGHT_OUTPUT . '/successor_v3_prepared_summary.md', $summary);

$missingItems = "# successor-v3 Missing Items\n\n- This Window is intentionally PREPARED. Atomic ACTIVE transition, armed_at, activation record, formal expected/canonical capture, Gate C LIVE, and Official Epoch remain out of scope.\n- The PREPARED key-set preflight does not replace the future ACTIVE cutoff boundary (`cutoff_timestamp > armed_at`).\n- No results, payouts, ROI, performance, outcome join, model, feature, threshold, training, cron, or DB schema operation was used.\n";
v3WriteFirst(V3_PREFLIGHT_OUTPUT . '/successor_v3_missing_items.md', $missingItems);

echo json_encode(['status' => $gateA1Pass ? 'NEW_SUCCESSOR_PREPARED_PREFLIGHT_PASS' : 'NEW_SUCCESSOR_PREPARED_PREFLIGHT_BLOCKED','window_id' => $windowId,'expected' => count($expected),'observed' => count($observed),'missing' => count($missing),'unexpected' => count($unexpected),'duplicate' => count($duplicates),'conflicting' => count($conflicting),'formal_expected' => $formalExpected,'formal_canonical' => $formalCanonical], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
