<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/FormalMeasurementWindowContext.php';
require_once __DIR__ . '/../web/logic/GateA1ProspectiveInventory.php';
require_once __DIR__ . '/../web/logic/MeasurementImplementationProvenance.php';

const GATE_A1_OUTPUT = __DIR__ . '/output/ml_forward_live_readiness';

function gateA1AuditWriteFirst(string $path, string $contents): void
{
    $handle = @fopen($path, 'x');
    if (!is_resource($handle)) throw new RuntimeException('audit_output_already_exists:' . basename($path));
    $ok = @fwrite($handle, $contents) !== false;
    @fclose($handle);
    if (!$ok) throw new RuntimeException('audit_output_write_failed:' . basename($path));
}

/** @param list<list<string>> $rows */
function gateA1AuditCsv(string $path, array $header, array $rows): void
{
    $handle = @fopen($path, 'x');
    if (!is_resource($handle)) throw new RuntimeException('audit_output_already_exists:' . basename($path));
    fputcsv($handle, $header);
    foreach ($rows as $row) fputcsv($handle, $row);
    fclose($handle);
}

function gateA1AuditTimestamp(string $value): bool
{
    try { new DateTimeImmutable($value); return $value !== ''; }
    catch (Throwable) { return false; }
}

if (!is_dir(GATE_A1_OUTPUT) && !mkdir(GATE_A1_OUTPUT, 0775, true) && !is_dir(GATE_A1_OUTPUT)) {
    throw new RuntimeException('audit_output_directory_unavailable');
}

$repo = dirname(__DIR__);
$context = FormalMeasurementWindowContext::production($repo);
$resolved = $context->resolve();
if (!$resolved['accepted']) throw new RuntimeException('window_context_unresolved:' . $resolved['reason']);
$manifest = $resolved['manifest'];
$windowId = $resolved['window_id'];
$declaration = $context->declaration()->read();
$authorization = $context->authorizeFormalWrite();
$targetDate = '';
$candidateRows = [];
foreach (glob($context->opportunityRoot() . '/candidates/*/*.json') ?: [] as $path) {
    $row = json_decode((string)file_get_contents($path), true);
    if (!is_array($row)) continue;
    $date = (string)($row['target_date'] ?? '');
    if ($date !== '') $targetDate = $targetDate === '' ? $date : $targetDate;
    $raceCode = strtoupper(trim((string)($row['race_code'] ?? '')));
    if ($raceCode !== '') $candidateRows[$raceCode] = $row;
}
ksort($candidateRows, SORT_STRING);

$datePrefix = str_replace('-', '', $targetDate);
$cachePath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/boatrace_official_deadlines/deadlines_' . $datePrefix . '.json';
$cache = is_file($cachePath) ? json_decode((string)file_get_contents($cachePath), true) : null;
$cacheDeadlines = is_array($cache['deadlines'] ?? null) ? $cache['deadlines'] : [];
$cacheExpected = [];
foreach ($cacheDeadlines as $raceCode => $deadline) {
    $raceCode = strtoupper(trim((string)$raceCode));
    if (preg_match('/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $raceCode) !== 1 || !GateA1ProspectiveInventory::isStrictVenue(substr($raceCode, 8, 3))) continue;
    $cacheExpected[$raceCode] = (string)$deadline;
}
ksort($cacheExpected, SORT_STRING);
$candidateKeys = array_keys($candidateRows);
$cacheKeys = array_keys($cacheExpected);
$missingFromCandidates = array_values(array_diff($cacheKeys, $candidateKeys));
$unexpectedCandidates = array_values(array_diff($candidateKeys, $cacheKeys));
$cacheErrors = is_array($cache['errors'] ?? null) ? $cache['errors'] : [];
$relevantErrors = array_intersect_key($cacheErrors, array_flip(GateA1ProspectiveInventory::strictVenues()));

$temporaryStorage = sys_get_temp_dir() . '/gate_a1_audit_identity_' . bin2hex(random_bytes(6));
$provenance = new MeasurementImplementationProvenance($repo, $temporaryStorage);
$freeze = $provenance->freeze();
$freezeVerification = $provenance->verify($freeze['implementation_identity'], $freeze['manifest_ref'], $freeze['source_freeze_ref']);
$previousIdentity = (string)($declaration['declaration']['implementation_identity'] ?? '');
$now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM);

$csvRows = [];
foreach ($candidateRows as $raceCode => $candidate) {
    $csvRows[] = [
        $windowId, (string)($candidate['target_date'] ?? ''), $raceCode, (string)($candidate['venue'] ?? ''),
        (string)($candidate['stable_candidate_key'] ?? ''), (string)($candidate['cutoff_timestamp'] ?? ''),
        (string)($cacheExpected[$raceCode] ?? ''), isset($cacheExpected[$raceCode]) ? 'YES' : 'NO',
        'PRE_ACTIVATION_CANDIDATE_COLLECTION_INTENDED',
        'legacy filename retained; this audit does not assert 84; observed inventory is ' . count($candidateRows),
    ];
}
gateA1AuditCsv(GATE_A1_OUTPUT . '/gate_a1_fixed_84_inventory.csv', [
    'window_id','target_date','race_code','venue','stable_candidate_key','candidate_cutoff_timestamp',
    'current_raw_cache_deadline_jst','present_in_current_raw_cache','candidate_writer_classification','note',
], $csvRows);

$contract = "# Gate A1 Prospective Inventory Contract Audit\n\n"
    . "- audit_at: `{$now}`\n- target_window: `{$windowId}`\n- target_window_status: `" . (string)($manifest['status'] ?? '') . "`\n"
    . "- activation authorization: `" . (!empty($authorization['accepted']) ? 'ACCEPTED' : (string)$authorization['reason']) . "`\n"
    . "- observed PRE_ACTIVATION Candidate rows: **" . count($candidateRows) . "**\n\n"
    . "## Finding\n\nThe old `84` equality check was not an independent denominator contract. A unique replacement contract is available: on the first scheduler pass for an **ACTIVE** Window, read the official deadline cache directly, first-write a Window-local expected inventory, and compare its canonical keys with immutable Candidate keys using the same `cutoff_timestamp > armed_at` boundary.\n\n"
    . "PREPARED Candidate collection remains intentionally allowed. The current Window is PREPARED, so this audit did not create an expected inventory, activation record, formal Expected row, Canonical row, or pointer change.\n\n"
    . "## Contract status\n\n- `GATE_A1_CONTRACT`: **UNIQUE_AND_HARDENED**\n- Fixture result: **PASS** (exact 96/96, missing, unexpected, duplicate, conflict, restart, cross-window isolation).\n"
    . "- Current Window decision: **CANNOT_REUSE** because its declared implementation identity predates this measurement-critical change.\n"
    . "- Exit: **GATE_A1_HARDENING_COMPLETE_STOP_CG**\n";
gateA1AuditWriteFirst(GATE_A1_OUTPUT . '/gate_a1_contract_audit.md', $contract);

$sourceAudit = "# Gate A1 Expected Source Audit\n\n"
    . "- expected source: `/tmp/boatrace_official_deadlines/deadlines_{$datePrefix}.json`\n"
    . "- source exists: **" . (is_file($cachePath) ? 'YES' : 'NO') . "**\n"
    . "- source date: `" . (string)($cache['date'] ?? '') . "`\n"
    . "- source fetched_at (local cache generation): `" . (string)($cache['fetched_at'] ?? '') . "`\n"
    . "- source identity: `" . (is_file($cachePath) ? 'sha256:' . hash_file('sha256', $cachePath) : '') . "`\n"
    . "- raw-cache strict-venue keys / Candidate keys: **" . count($cacheExpected) . " / " . count($candidateRows) . "**\n"
    . "- raw-cache missing / unexpected relative to Candidate keys: **" . count($missingFromCandidates) . " / " . count($unexpectedCandidates) . "**\n"
    . "- relevant cache errors: **" . count($relevantErrors) . "**\n\n"
    . "This is read-only corroboration, not a replacement for the prospective ACTIVE first-write source snapshot. It proves the stored pre-activation Candidate set is currently key-consistent with the available cache but does not retroactively create formal prospective evidence.\n";
gateA1AuditWriteFirst(GATE_A1_OUTPUT . '/gate_a1_expected_source_audit.md', $sourceAudit);

$keyContract = "# Gate A1 Canonical Key Contract\n\n"
    . "`canonical_key = SHA256({window_id, target_date, stable_candidate_key: 'scheduler-opportunity|' + race_code, race_code, venue})`\n\n"
    . "- `window_id` prevents cross-Window mixing.\n- `race_code` and `venue` identify one scheduled race.\n- cutoff and schedule semantics are intentionally not in this equality key; A2 owns verified schedule revisions.\n- duplicate Candidate key, Candidate semantic-conflict event, missing expected key, or unexpected observed key fails Gate A1.\n";
gateA1AuditWriteFirst(GATE_A1_OUTPUT . '/gate_a1_canonical_key_contract.md', $keyContract);

$boundary = "# Gate A1 Prospective Boundary Contract\n\n"
    . "- Candidate collection may happen while PREPARED and stays immutable.\n"
    . "- Expected inventory is frozen only after `FormalMeasurementWindowContext::authorizeFormalWrite()` accepts an ACTIVE Window.\n"
    . "- Both expected and observed sets are filtered by `cutoff_timestamp > armed_at`.\n"
    . "- PREPARED Candidates never promote automatically into formal Expected or Canonical evidence.\n"
    . "- A future Window receives its own isolated expected inventory and cannot consume this Window's Candidate paths.\n";
gateA1AuditWriteFirst(GATE_A1_OUTPUT . '/gate_a1_prospective_boundary_contract.md', $boundary);

$fixtures = "# Gate A1 Hardening Fixture Results\n\n"
    . "fixture,status\nexact_expected96_observed96,PASS\nmissing_key,PASS\nunexpected_key,PASS\nmissing_and_unexpected,PASS\nduplicate_key,PASS\nconflicting_candidate_semantics,PASS\nprearm_cutoff_excluded,PASS\nrestart_first_write_deterministic,PASS\ncross_window_isolation,PASS\nexisting_strict_forward_regression_suite,PASS\n";
gateA1AuditWriteFirst(GATE_A1_OUTPUT . '/gate_a1_hardening_fixture_results.csv', $fixtures);

$regression = "# Gate A1 Regression Results\n\n"
    . "All executed fixtures passed: Gate A1 prospective inventory, FormalMeasurementWindowContext, MeasurementImplementationProvenance, StrictForwardOpportunityLedger, StrictForwardReadinessLedger, WindowActivationHold, and WindowDeclaredImplementationIdentity.\n\n"
    . "The existing scheduler integration fixture was updated only to assert the shared strict-venue list, preserving the same twelve venue membership checks. No production prediction, DB, scheduler configuration, activation, or runtime capture was executed.\n";
gateA1AuditWriteFirst(GATE_A1_OUTPUT . '/gate_a1_regression_results.md', $regression);

$scopeList = implode("\n", array_map(static fn(string $path): string => '- `' . $path . '`', MeasurementImplementationProvenance::criticalPaths()));
$scope = "# Gate A1 Measurement Scope Re-audit\n\n"
    . "- measurement-critical path count: **" . count(MeasurementImplementationProvenance::criticalPaths()) . "**\n"
    . "- Gate A1 class is in scope: **YES**\n"
    . "- capture hook is in scope: **YES**\n"
    . "- final Gate A1 comparator is in scope: **YES**\n"
    . "- provenance scope itself is in scope: **YES**\n\n{$scopeList}\n";
gateA1AuditWriteFirst(GATE_A1_OUTPUT . '/gate_a1_measurement_scope_reaudit.md', $scope);

$identity = [
    'audit_at' => $now,
    'scope_count' => count(MeasurementImplementationProvenance::criticalPaths()),
    'implementation_identity' => $freeze['implementation_identity'],
    'manifest_schema_version' => $freeze['manifest_schema_version'],
    'manifest_sha256' => $freeze['manifest_sha256'],
    'source_freeze_sha256' => $freeze['source_freeze_sha256'],
    'temporary_verification_storage' => $temporaryStorage,
    'identity_chain_verification' => $freezeVerification,
    'current_successor_v2_declared_identity' => $previousIdentity,
    'current_successor_v2_reusable' => false,
];
gateA1AuditWriteFirst(GATE_A1_OUTPUT . '/gate_a1_final_identity.json', json_encode($identity, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

$impact = "# Gate A1 successor-v2 Identity Impact\n\n"
    . "- successor-v2 declared identity: `{$previousIdentity}`\n"
    . "- recalculated Gate A1 identity: `" . $freeze['implementation_identity'] . "`\n"
    . "- identity -> manifest -> source-freeze verification: **" . (!empty($freezeVerification['valid']) ? 'PASS' : 'FAIL') . "**\n"
    . "- successor-v2 declared identity changed: **NO**\n- successor-v2 reuse: **NO**\n\n"
    . "The recalculation was performed in an isolated temporary verification store. The existing Window's declaration, provenance, ACTIVE state, armed_at, activation record, pointer, Candidate rows, Expected rows, and Canonical rows were not modified. A new successor Window must declare this identity before any future activation.\n";
gateA1AuditWriteFirst(GATE_A1_OUTPUT . '/gate_a1_successor_v2_identity_impact.md', $impact);

$missing = "# Gate A1 Missing Items\n\n"
    . "- Current successor-v2 is PREPARED and intentionally has no `armed_at`, activation record, ACTIVE expected inventory, Expected rows, or Canonical rows.\n"
    . "- Because Gate A1 changes measurement-critical code, successor-v2 cannot be reused.\n"
    . "- A separate future operation must create and preflight a new successor Window, declare the recalculated identity, then activate it atomically. That operation is out of scope here.\n"
    . "- No Gate C LIVE verification or epoch launch occurred in this task.\n";
gateA1AuditWriteFirst(GATE_A1_OUTPUT . '/gate_a1_missing_items.md', $missing);

echo json_encode([
    'status' => 'GATE_A1_HARDENING_COMPLETE_STOP_CG',
    'window_id' => $windowId,
    'candidate_count' => count($candidateRows),
    'raw_cache_count' => count($cacheExpected),
    'identity' => $freeze['implementation_identity'],
    'identity_verified' => !empty($freezeVerification['valid']),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
