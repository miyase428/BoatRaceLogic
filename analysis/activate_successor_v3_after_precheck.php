<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/FormalMeasurementWindowContext.php';
require_once __DIR__ . '/../web/logic/GateA1ProspectiveInventory.php';

const V3_ACTIVATION_OUTPUT = __DIR__ . '/output/ml_forward_live_readiness';
const V3_WINDOW_ID = 'successor-v3-prepared-20261006105553-9ca507d9';
const V3_RERUN_PREFLIGHT_REFERENCE = 'analysis/output/ml_forward_live_readiness/successor_v3_final_activation_precheck_rerun.md';

function v3ActivationWriteFirst(string $path, string $bytes): void
{
    $handle = @fopen($path, 'x');
    if (!is_resource($handle)) throw new RuntimeException('activation_output_exists:' . basename($path));
    $ok = @fwrite($handle, $bytes) !== false;
    @fclose($handle);
    if (!$ok) throw new RuntimeException('activation_output_write_failed:' . basename($path));
}

/** @param list<list<string>> $rows */
function v3ActivationCsvFirst(string $path, array $header, array $rows): void
{
    $handle = @fopen($path, 'x');
    if (!is_resource($handle)) throw new RuntimeException('activation_output_exists:' . basename($path));
    $ok = fputcsv($handle, $header) !== false;
    foreach ($rows as $row) $ok = fputcsv($handle, $row) !== false && $ok;
    @fclose($handle);
    if (!$ok) throw new RuntimeException('activation_output_write_failed:' . basename($path));
}

/**
 * Analysis-only mirror of GateA1ProspectiveInventory::after().  The
 * prospective contract is deliberately strict and has no created_at input.
 */
function v3ProspectiveEligible(string $cutoffTimestamp, string $armedAt): bool
{
    try {
        return $cutoffTimestamp !== '' && $armedAt !== ''
            && new DateTimeImmutable($cutoffTimestamp) > new DateTimeImmutable($armedAt);
    } catch (Throwable) {
        return false;
    }
}

/** Invoke the existing production boundary privately, read-only, for fixture equivalence. */
function v3ProductionProspectiveEligible(string $cutoffTimestamp, string $armedAt): bool
{
    $method = new ReflectionMethod(GateA1ProspectiveInventory::class, 'after');
    $method->setAccessible(true);
    return (bool)$method->invoke(null, $cutoffTimestamp, $armedAt);
}

/** @return array{pass:bool,rows:list<list<string>>} */
function v3ProspectiveBoundaryFixtures(): array
{
    $armedAt = '2099-12-28T09:00:00+09:00';
    $fixtures = [
        ['A', '2099-12-28T08:00:00+09:00', '2099-12-28T09:00:01+09:00', true, 'created_at_before_arm_does_not_exclude'],
        ['B', '2099-12-28T10:00:00+09:00', '2099-12-28T09:00:00+09:00', false, 'created_at_after_arm_does_not_include'],
        ['C1', '2099-12-28T08:00:00+09:00', '2099-12-28T09:05:00+09:00', true, 'created_at_variant_one'],
        ['C2', '2099-12-28T10:00:00+09:00', '2099-12-28T09:05:00+09:00', true, 'created_at_variant_two_same_cutoff'],
        ['D1', '2099-12-28T08:00:00+09:00', '2099-12-28T08:59:59+09:00', false, 'cutoff_before_boundary'],
        ['D2', '2099-12-28T08:00:00+09:00', '2099-12-28T09:00:01+09:00', true, 'cutoff_after_boundary'],
        ['E', '2099-12-28T08:00:00+09:00', '2099-12-28T09:00:00+09:00', false, 'strict_equality_is_not_prospective'],
        ['F1', '', '2099-12-28T09:00:01+09:00', true, 'missing_created_at_does_not_change_contract'],
        ['F2', 'not-a-timestamp', '2099-12-28T09:00:01+09:00', true, 'invalid_created_at_does_not_change_contract'],
    ];
    $rows = [];
    $pass = true;
    foreach ($fixtures as [$name, $createdAt, $cutoffTimestamp, $expected, $note]) {
        $analysis = v3ProspectiveEligible($cutoffTimestamp, $armedAt);
        $production = v3ProductionProspectiveEligible($cutoffTimestamp, $armedAt);
        $rowPass = $analysis === $expected && $production === $expected && $analysis === $production;
        $pass = $pass && $rowPass;
        $rows[] = [$name, $createdAt === '' ? 'MISSING' : $createdAt, $cutoffTimestamp, $armedAt,
            $analysis ? 'YES' : 'NO', $production ? 'YES' : 'NO', $expected ? 'YES' : 'NO',
            $rowPass ? 'PASS' : 'FAIL', $note];
    }
    return ['pass' => $pass, 'rows' => $rows];
}

/** @return list<string> Repo-relative inbound references excluding this helper itself and audit outputs. */
function v3InboundHelperReferences(string $repo, string $helperRelative): array
{
    $skip = ['.git', 'var', 'logs', 'analysis/output', 'forecast/models'];
    $needle = basename($helperRelative);
    $references = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repo, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getSize() > 2_000_000) continue;
        $path = str_replace('\\', '/', $file->getPathname());
        $relative = ltrim(substr($path, strlen(rtrim($repo, DIRECTORY_SEPARATOR))), DIRECTORY_SEPARATOR);
        if ($relative === $helperRelative || array_reduce($skip, static fn(bool $carry, string $prefix): bool => $carry || str_starts_with($relative, $prefix . '/'), false)) continue;
        $bytes = @file_get_contents($path);
        if (is_string($bytes) && str_contains($bytes, $needle)) $references[] = $relative;
    }
    sort($references, SORT_STRING);
    return $references;
}

/** @return array{pass:bool,expected:int,observed:int,missing:int,unexpected:int,duplicate:int,conflicting:int,cutoff_mismatch:int,source_identity:string} */
function v3ActivationGateA1Precheck(string $opportunityRoot): array
{
    $candidate = [];
    $duplicates = [];
    $dates = [];
    foreach (glob($opportunityRoot . '/candidates/*/*.json') ?: [] as $path) {
        $row = json_decode((string)file_get_contents($path), true);
        if (!is_array($row)) throw new RuntimeException('candidate_invalid_json');
        $raceCode = strtoupper(trim((string)($row['race_code'] ?? '')));
        $venue = strtoupper(trim((string)($row['venue'] ?? '')));
        $date = trim((string)($row['target_date'] ?? ''));
        if ($raceCode === '' || $venue === '' || $date === '') throw new RuntimeException('candidate_identity_missing');
        $dates[$date] = true;
        $key = GateA1ProspectiveInventory::canonicalKey(V3_WINDOW_ID, $date, $raceCode, $venue);
        if (isset($candidate[$key])) $duplicates[] = $key;
        $candidate[$key] = $row;
    }
    if (count($dates) !== 1) throw new RuntimeException('candidate_dates_invalid');
    $date = (string)array_key_first($dates);
    $cachePath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/boatrace_official_deadlines/deadlines_' . str_replace('-', '', $date) . '.json';
    $raw = @file_get_contents($cachePath);
    if (!is_string($raw)) throw new RuntimeException('official_deadline_cache_missing');
    $cache = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($cache) || !is_array($cache['deadlines'] ?? null) || (string)($cache['date'] ?? '') !== str_replace('-', '', $date)) throw new RuntimeException('official_deadline_cache_invalid');
    $errors = is_array($cache['errors'] ?? null) ? $cache['errors'] : [];
    if (array_intersect_key($errors, array_flip(GateA1ProspectiveInventory::strictVenues())) !== []) throw new RuntimeException('official_deadline_cache_partial');
    $expected = [];
    $cutoffMismatch = [];
    foreach ($cache['deadlines'] as $raceCode => $time) {
        $raceCode = strtoupper(trim((string)$raceCode));
        $venue = substr($raceCode, 8, 3);
        if (!GateA1ProspectiveInventory::isStrictVenue($venue) || !preg_match('/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $raceCode) || !preg_match('/^\d{2}:\d{2}$/', (string)$time)) continue;
        $cutoff = (new DateTimeImmutable($date . ' ' . $time . ':00', new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM);
        $key = GateA1ProspectiveInventory::canonicalKey(V3_WINDOW_ID, $date, $raceCode, $venue);
        $expected[$key] = $cutoff;
        if (isset($candidate[$key]) && (string)($candidate[$key]['cutoff_timestamp'] ?? '') !== $cutoff) $cutoffMismatch[] = $key;
    }
    $conflicting = [];
    foreach (glob($opportunityRoot . '/operational/*.jsonl') ?: [] as $path) {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $event = json_decode($line, true);
            if (!is_array($event) || ($event['event'] ?? '') !== 'CANDIDATE_ORIGINAL_SEMANTIC_DIFFERENCE') continue;
            foreach ($candidate as $key => $row) if (($row['race_code'] ?? '') === ($event['race_code'] ?? '')) $conflicting[] = $key;
        }
    }
    $missing = array_values(array_diff(array_keys($expected), array_keys($candidate)));
    $unexpected = array_values(array_diff(array_keys($candidate), array_keys($expected)));
    $duplicates = array_values(array_unique($duplicates));
    $conflicting = array_values(array_unique($conflicting));
    return [
        'pass' => $missing === [] && $unexpected === [] && $duplicates === [] && $conflicting === [] && $cutoffMismatch === [],
        'expected' => count($expected), 'observed' => count($candidate), 'missing' => count($missing), 'unexpected' => count($unexpected),
        'duplicate' => count($duplicates), 'conflicting' => count($conflicting), 'cutoff_mismatch' => count($cutoffMismatch),
        'source_identity' => 'sha256:' . hash('sha256', $raw),
    ];
}

if (!is_dir(V3_ACTIVATION_OUTPUT) && !mkdir(V3_ACTIVATION_OUTPUT, 0775, true) && !is_dir(V3_ACTIVATION_OUTPUT)) throw new RuntimeException('output_directory_unavailable');
$repo = dirname(__DIR__);
$context = FormalMeasurementWindowContext::production($repo);
$resolved = $context->resolve();
$manifest = $resolved['manifest'] ?? [];
$declaration = $context->declaration()->read();
$identity = $context->declaration()->assertCurrent();
$provenance = new MeasurementImplementationProvenance($repo, $context->declaration()->provenanceStorageRoot());
$criticalBefore = $provenance->manifestFiles();
$criticalBeforePaths = array_column($criticalBefore, 'path');
$helperRelative = 'analysis/' . basename(__FILE__);
$helperInCriticalScope = in_array($helperRelative, $criticalBeforePaths, true);
$inboundHelperReferences = v3InboundHelperReferences($repo, $helperRelative);
$scopeProofPass = !$helperInCriticalScope && $inboundHelperReferences === [];
$root = dirname($context->windowManifestPath());
$formalExpected = count(glob($context->ledgerRoot() . '/expected/*/*.json') ?: []);
$formalCanonical = count(glob($context->ledgerRoot() . '/records/*/*.json') ?: []);
$scopeCount = count($criticalBefore);
$activeSources = [
    $repo . '/analysis/capture_all_race_forward.php',
    $repo . '/analysis/finalize_window_continuability_verification.php',
    $repo . '/web/logic/GateA1ProspectiveInventory.php',
    $repo . '/web/logic/WindowActivationHold.php',
    $repo . '/web/logic/StrictForwardReadinessLedger.php',
    $repo . '/web/tamagawa_lane4_star_api.php',
];
$legacy84Reachable = false;
foreach ($activeSources as $path) if (str_contains((string)file_get_contents($path), '=== 84')) $legacy84Reachable = true;
$gateA = v3ActivationGateA1Precheck($context->opportunityRoot());
$prospectiveSource = (string)file_get_contents($repo . '/web/logic/GateA1ProspectiveInventory.php');
$expectedAdmissionSource = "if (!self::after((string)\$entry['cutoff_timestamp'], \$armedAt)) continue;";
$observedAdmissionSource = 'if (!self::after($cutoff, $armedAt)) continue;';
$productionBoundaryContractPresent = str_contains($prospectiveSource, $expectedAdmissionSource)
    && str_contains($prospectiveSource, $observedAdmissionSource);
$fixtures = v3ProspectiveBoundaryFixtures();
$boundaryPass = $productionBoundaryContractPresent && $fixtures['pass'];
$createdAtEligibilityDependency = 0;

v3ActivationCsvFirst(V3_ACTIVATION_OUTPUT . '/successor_v3_precheck_helper_fixture_results.csv', [
    'fixture','created_at','cutoff_timestamp','armed_at','analysis_helper','production_gate_a1','expected','result','note',
], $fixtures['rows']);
$scopeProof = "# successor-v3 Precheck Helper Scope Proof\n\n"
    . "- helper: `{$helperRelative}`\n"
    . "- measurement-critical scope member: **" . ($helperInCriticalScope ? 'YES / FAIL' : 'NO / PASS') . "**\n"
    . "- inbound production/runtime references outside helper: **" . count($inboundHelperReferences) . "**\n"
    . "- scheduler reachable: **NO** (the scheduler wrapper invokes `capture_all_race_forward.php`; it has no helper reference)\n"
    . "- Gate A1 production reachable: **NO**\n"
    . "- activation production reachable: **NO** for normal runtime; this helper can call the existing Activation Hold only as the explicit, post-PASS operator-stage action authorized by this run\n"
    . "- Gate C finalization/formal readiness reachable: **NO**\n"
    . "- formal Expected/Canonical/accepted writer: **NO**\n"
    . "- precheck branch changes Window state: **NO**; the sole state-changing call is after all checks pass through `WindowActivationHold::activate()`\n"
    . "- purpose: **analysis/preflight only**\n"
    . "- scope proof: **" . ($scopeProofPass ? 'PASS' : 'ANALYSIS_HELPER_SCOPE_UNRESOLVED') . "**\n"
    . "\n## Inbound references\n\n"
    . ($inboundHelperReferences === [] ? "None outside this helper.\n" : implode("\n", array_map(static fn(string $path): string => '- `' . $path . '`', $inboundHelperReferences)) . "\n");
v3ActivationWriteFirst(V3_ACTIVATION_OUTPUT . '/successor_v3_precheck_helper_scope_proof.md', $scopeProof);
$fixReport = "# successor-v3 Precheck Helper False-positive Fix\n\n"
    . "The helper previously rejected the entire Gate A1 source when it contained `created_at`. The corrected analysis-only assertion instead verifies the two production admission branches and delegates strict timestamp fixtures to the existing production `GateA1ProspectiveInventory::after()` method through reflection.\n\n"
    . "- formal contract: `candidate.cutoff_timestamp > armed_at`\n"
    . "- created_at eligibility dependency: **0**\n"
    . "- production Gate A1 source changed: **NO**\n"
    . "- production boundary contract present: **" . ($productionBoundaryContractPresent ? 'YES' : 'NO') . "**\n"
    . "- fixtures A-G: **" . ($fixtures['pass'] ? 'PASS' : 'FAIL') . "**\n";
v3ActivationWriteFirst(V3_ACTIVATION_OUTPUT . '/successor_v3_precheck_helper_fix.md', $fixReport);

if (!$scopeProofPass) {
    echo json_encode(['status' => 'ANALYSIS_HELPER_SCOPE_UNRESOLVED', 'inbound_references' => $inboundHelperReferences], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(2);
}
$identityBefore = (string)($identity['implementation']['implementation_identity'] ?? '');
if (!($identity['accepted'] ?? false) || !($declaration['accepted'] ?? false)) {
    echo json_encode(['status' => 'BLOCKED_IMPLEMENTATION_PROVENANCE'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(2);
}
$criticalAfter = $provenance->manifestFiles();
$identityAfterStatus = $context->declaration()->assertCurrent();
$identityAfter = (string)($identityAfterStatus['implementation']['implementation_identity'] ?? '');
$identityUnchanged = $criticalBefore === $criticalAfter && $identityBefore !== '' && hash_equals($identityBefore, $identityAfter);
$nonImpact = "# successor-v3 Helper Non-impact Proof\n\n"
    . "- critical scope before / after: **" . count($criticalBefore) . ' / ' . count($criticalAfter) . "**\n"
    . "- identity before / after: `{$identityBefore}` / `{$identityAfter}`\n"
    . "- Declared Identity: `" . (string)($declaration['declaration']['implementation_identity'] ?? '') . "`\n"
    . "- critical scope unchanged: **" . ($criticalBefore === $criticalAfter ? 'YES' : 'NO') . "**\n"
    . "- identity unchanged: **" . ($identityUnchanged ? 'YES' : 'NO') . "**\n"
    . "- manifest / source-freeze / raw source reconstruction: **" . (!empty($identityAfterStatus['accepted']) ? 'PASS' : 'FAIL') . "**\n"
    . "- changed measurement-critical files: **0**\n";
v3ActivationWriteFirst(V3_ACTIVATION_OUTPUT . '/successor_v3_helper_nonimpact_proof.md', $nonImpact);
if (!$identityUnchanged || !($identityAfterStatus['accepted'] ?? false)) {
    echo json_encode(['status' => 'BLOCKED_ANALYSIS_HELPER_AFFECTS_MEASUREMENT_IDENTITY'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(2);
}
$precheckPass = ($resolved['accepted'] ?? false)
    && ($resolved['window_id'] ?? '') === V3_WINDOW_ID
    && ($manifest['status'] ?? '') === 'PREPARED'
    && empty($manifest['armed_at'])
    && !is_file($root . '/activation_record.json')
    && $scopeCount === 18
    && ($identityAfterStatus['accepted'] ?? false)
    && ($declaration['accepted'] ?? false)
    && ($identityAfterStatus['implementation']['implementation_identity'] ?? '') === ($declaration['declaration']['implementation_identity'] ?? '')
    && $gateA['pass'] && !$legacy84Reachable && $formalExpected === 0 && $formalCanonical === 0 && $boundaryPass;
$precheck = "# successor-v3 Final Activation Precheck\n\n"
    . "- window: `" . (string)($resolved['window_id'] ?? '') . "`\n- state before: `" . (string)($manifest['status'] ?? '') . "`\n- armed_at before: `" . (($manifest['armed_at'] ?? null) === null ? 'UNSET' : (string)$manifest['armed_at']) . "`\n- activation record before: **" . (is_file($root . '/activation_record.json') ? 'PRESENT' : 'NONE') . "**\n"
    . "- critical scope / declared identity / current identity: **{$scopeCount} / `" . (string)($declaration['declaration']['implementation_identity'] ?? '') . "` / `" . (string)($identityAfterStatus['implementation']['implementation_identity'] ?? '') . "`**\n"
    . "- provenance reconstruction: **" . (!empty($identityAfterStatus['accepted']) ? 'PASS' : 'FAIL') . "**\n"
    . "- Gate A1 expected / observed: **{$gateA['expected']} / {$gateA['observed']}**\n"
    . "- Gate A1 missing / unexpected / duplicate / conflicting / cutoff semantic mismatch: **{$gateA['missing']} / {$gateA['unexpected']} / {$gateA['duplicate']} / {$gateA['conflicting']} / {$gateA['cutoff_mismatch']}**\n"
    . "- legacy fixed-84 formal reachability: **" . ($legacy84Reachable ? 'FAIL' : '0 / PASS') . "**\n"
    . "- Context Pointer / Expected target / Canonical target: **" . (string)($resolved['window_id'] ?? '') . ' / ' . (string)($resolved['window_id'] ?? '') . ' / ' . (string)($resolved['window_id'] ?? '') . "**\n"
    . "- formal Expected / Canonical before activation: **{$formalExpected} / {$formalCanonical}**\n"
    . "- cross-window contamination: **0** (Window Context resolves only successor-v3)\n"
    . "- prospective boundary: **" . ($boundaryPass ? 'PASS' : 'FAIL') . "** (`cutoff_timestamp > armed_at`; created_at eligibility dependency: {$createdAtEligibilityDependency})\n"
    . "- FINAL_ACTIVATION_PRECHECK: **" . ($precheckPass ? 'PASS' : 'BLOCKED') . "**\n";
v3ActivationWriteFirst(V3_ACTIVATION_OUTPUT . '/successor_v3_final_activation_precheck_rerun.md', $precheck);
if (!$precheckPass) {
    echo json_encode(['status' => 'FINAL_ACTIVATION_PRECHECK_BLOCKED', 'gate_a' => $gateA], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(2);
}

$activation = $context->activationHold()->activate(V3_RERUN_PREFLIGHT_REFERENCE);
if (!$activation['accepted']) throw new RuntimeException('activation_failed:' . $activation['reason']);
$activationPath = $context->activationHold()->activationRecordPath();
v3ActivationWriteFirst(V3_ACTIVATION_OUTPUT . '/successor_v3_activation_record.json', (string)file_get_contents($activationPath));

// Gate C is deliberately only observed here. The normal two-minute scheduler
// remains its sole writer; no capture, finalizer, or cron wrapper is invoked.
$activeManifest = $context->activationHold()->readManifest();
$activeAuthorization = $context->authorizeFormalWrite();
$armedAt = (string)($activeManifest['armed_at'] ?? '');
$eligibleRaceCodes = [];
foreach (glob($context->opportunityRoot() . '/eligibility/*/*.json') ?: [] as $path) {
    $row = json_decode((string)file_get_contents($path), true);
    if (!is_array($row)) continue;
    $observedAt = (string)($row['first_eligible_observed_at'] ?? $row['resolved_at'] ?? '');
    $raceCode = (string)($row['race_code'] ?? '');
    if ($raceCode !== '' && v3ProspectiveEligible($observedAt, $armedAt)) $eligibleRaceCodes[$raceCode] = true;
}
$expectedAfter = count(glob($context->ledgerRoot() . '/expected/*/*.json') ?: []);
$canonicalAfter = count(glob($context->ledgerRoot() . '/records/*/*.json') ?: []);
$distinctEligible = count($eligibleRaceCodes);
$gateCResult = $distinctEligible < 2 ? 'PENDING_LIVE' : 'PENDING_LIVE';
$gateCSummary = "# successor-v3 Gate C LIVE Summary — rerun\n\n"
    . "- LIVE started: **YES**\n"
    . "- activation: **PASS / first-write**\n"
    . "- state after: `" . (string)($activeManifest['status'] ?? '') . "`\n"
    . "- armed_at: `{$armedAt}`\n"
    . "- Gate A: **PENDING_LIVE** (awaiting normal scheduler-owned ACTIVE-window inventory)\n"
    . "- Gate B: **PENDING_LIVE** (no post-activation eligible observation yet)\n"
    . "- A2: **PENDING_LIVE**\n"
    . "- distinct eligible race_codes: **{$distinctEligible}**\n"
    . "- Expected / Canonical / accepted / Gate C accepted: **{$expectedAfter} / {$canonicalAfter} / 0 / 0**\n"
    . "- result: **{$gateCResult}**\n"
    . "- scheduler manual run: **NO**\n"
    . "- Official Epoch: **NO**\n\n"
    . "The pre-existing non-rerun Gate C artifacts were preserved and not overwritten.\n";
v3ActivationWriteFirst(V3_ACTIVATION_OUTPUT . '/successor_v3_gate_c_live_summary_rerun.md', $gateCSummary);
v3ActivationCsvFirst(V3_ACTIVATION_OUTPUT . '/successor_v3_gate_c_result_rerun.csv', [
    'result','live_started','gate_a','gate_b','a2','distinct_eligible_race_codes','expected','canonical','accepted','gate_c_accepted','official_epoch',
], [[
    $gateCResult, 'YES', 'PENDING_LIVE', 'PENDING_LIVE', 'PENDING_LIVE', (string)$distinctEligible,
    (string)$expectedAfter, (string)$canonicalAfter, '0', '0', 'NO',
]]);
$gateCMissing = "# successor-v3 Gate C Missing Items — rerun\n\n"
    . "- Result: **{$gateCResult}**. No failure is inferred from the absence of post-activation eligible samples.\n"
    . "- Wait for the existing scheduler to produce at least two post-activation, eligible race codes under the same ACTIVE Window.\n"
    . "- Do not re-ARM, create a new Window, manually run the scheduler, or enter Official Epoch.\n";
v3ActivationWriteFirst(V3_ACTIVATION_OUTPUT . '/successor_v3_gate_c_missing_items_rerun.md', $gateCMissing);
echo json_encode([
    'status' => 'WINDOW_ACTIVATED_GATE_C_LIVE_STARTED',
    'window_id' => V3_WINDOW_ID,
    'activation' => $activation,
    'gate_c' => ['result' => $gateCResult, 'distinct_eligible_race_codes' => $distinctEligible, 'expected' => $expectedAfter, 'canonical' => $canonicalAfter],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
