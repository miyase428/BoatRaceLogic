<?php

declare(strict_types=1);

require_once __DIR__ . '/audit_cc30c_toda_post12r_20261016.php';
require_once __DIR__ . '/finalize_strict_forward_late_eligibility.php';
require_once __DIR__ . '/../web/logic/FormalWindowProvenanceResolver.php';
require_once __DIR__ . '/../web/logic/StrictForwardOpportunityLedger.php';
require_once __DIR__ . '/../web/logic/OfficialEpochWindowBindingRuntimeAuthorization.php';

function sfAssert(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException('ASSERTION_FAILED:' . $label);
    echo "PASS {$label}\n";
}
function sfWrite(string $path, array $row): void
{
    $dir = dirname($path); if (!is_dir($dir)) mkdir($dir, 0775, true);
    file_put_contents($path, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}
function sfRemove(string $path): void
{
    if (!file_exists($path)) return;
    if (is_dir($path)) { foreach (scandir($path) ?: [] as $name) if ($name !== '.' && $name !== '..') sfRemove($path . '/' . $name); rmdir($path); }
    else unlink($path);
}

// Report-only canonical field and deterministic membership test against the
// immutable natural evidence.  It performs no writes.
$windowRoot = dirname(__DIR__) . '/var/strict_forward_live_readiness/windows/' . TODA_AUDIT_EVIDENCE_WINDOW_ID;
sfAssert(is_dir($windowRoot), 'immutable evidence window available for read-only report test');
$membershipOne = todaAuditCanonicalFormalMembership($windowRoot);
$membershipTwo = todaAuditCanonicalFormalMembership($windowRoot);
sfAssert(count($membershipOne) === 216, 'canonical report raw formal membership is 216');
sfAssert($membershipOne === $membershipTwo, 'canonical report membership deterministic');
sfAssert(($membershipOne[0]['captured_at'] ?? '') !== '' && ($membershipOne[0]['cutoff_timestamp'] ?? '') !== '', 'canonical timestamp fields used');
sfAssert(($membershipOne[0]['captured_at'] ?? '') < ($membershipOne[0]['cutoff_timestamp'] ?? ''), 'first timestamp is pre-cutoff');

// Late 22:45 fixture: normal incomplete observation remains provisional;
// one DB-only late observation makes exactly one terminal resolution.
$fixture = sys_get_temp_dir() . '/cc30c_late_eligibility_' . bin2hex(random_bytes(5));
try {
    $ledger = new StrictForwardOpportunityLedger($fixture . '/opportunity');
    $race = '20990101SMS12';
    $ledger->recordCandidateUniverse([
        'date' => '2099-01-01', 'candidates' => [[
            'race_code' => $race, 'venue' => 'SMS', 'cutoff_timestamp' => '2099-01-01T22:45:00+09:00',
        ]], 'source' => 'fixture', 'source_identity' => 'sha256:fixture',
        'source_generated_at' => '2099-01-01T08:00:00+09:00', 'universe_recorded_at' => '2099-01-01T08:01:00+09:00',
    ]);
    $ledger->observeEligibility(['date' => '2099-01-01', 'observed_at' => '2099-01-01T21:58:00+09:00', 'ready_race_codes' => []]);
    sfAssert(count($ledger->candidateRows('2099-01-01')) === 1 && $ledger->resolutionRows('2099-01-01') === [], 'late fixture has one candidate and no premature terminal resolution');
    $result = cc30cLateEligibilityRun(
        $ledger, '2099-01-01', new DateTimeImmutable('2099-01-01T22:39:00+09:00'),
        static fn(string $_race, string $_venue, string $_cutoff): array => ['complete' => true, 'complete_boats' => 6, 'recorded_at' => '2099-01-01T22:37:00+09:00'], false,
    );
    $resolutions = $ledger->resolutionRows('2099-01-01');
    sfAssert(($result['eligible_before_cutoff'] ?? []) === [$race], 'late fixture observes completed exhibition before cutoff');
    sfAssert(count($ledger->candidateRows('2099-01-01')) === 1 && count($resolutions) === 1, 'late fixture has no duplicate candidate or eligibility');
    sfAssert(($resolutions[0]['eligibility_status'] ?? '') === 'ELIGIBLE_OBSERVED', 'late fixture terminal eligibility is eligible');
    sfAssert(($resolutions[0]['first_eligible_observed_at'] ?? '') === '2099-01-01T22:39:00+09:00', 'late fixture terminal resolution is before 22:45 cutoff');
    // An old normal-scheduler observation without the late-producer marker is
    // deliberately not finalized by this new path after cutoff.
    $guardLedger = new StrictForwardOpportunityLedger($fixture . '/guard/opportunity');
    $guardRace = '20990101SMS11';
    $guardLedger->recordCandidateUniverse(['date' => '2099-01-01', 'candidates' => [['race_code' => $guardRace, 'venue' => 'SMS', 'cutoff_timestamp' => '2099-01-01T22:18:00+09:00']], 'source' => 'fixture', 'source_identity' => 'sha256:fixture-guard', 'source_generated_at' => '2099-01-01T08:00:00+09:00', 'universe_recorded_at' => '2099-01-01T08:01:00+09:00']);
    $guardLedger->observeEligibility(['date' => '2099-01-01', 'observed_at' => '2099-01-01T21:58:00+09:00', 'ready_race_codes' => []]);
    cc30cLateEligibilityRun($guardLedger, '2099-01-01', new DateTimeImmutable('2099-01-01T23:00:00+09:00'), static fn(string $_race, string $_venue, string $_cutoff): array => ['complete' => false, 'complete_boats' => 0, 'recorded_at' => ''], false);
    sfAssert($guardLedger->resolutionRows('2099-01-01') === [], 'late producer cannot repair historical normal-observation gap');
    // Normal daytime default behavior remains unaffected by optional scoping.
    $dayLedger = new StrictForwardOpportunityLedger($fixture . '/day/opportunity');
    $dayRace = '20990102TDA01';
    $dayLedger->recordCandidateUniverse(['date' => '2099-01-02', 'candidates' => [['race_code' => $dayRace, 'venue' => 'TDA', 'cutoff_timestamp' => '2099-01-02T10:06:00+09:00']], 'source' => 'fixture', 'source_identity' => 'sha256:fixture-day', 'source_generated_at' => '2099-01-02T08:00:00+09:00', 'universe_recorded_at' => '2099-01-02T08:01:00+09:00']);
    $dayLedger->observeEligibility(['date' => '2099-01-02', 'observed_at' => '2099-01-02T10:04:00+09:00', 'ready_race_codes' => [$dayRace]]);
    sfAssert(count($dayLedger->resolutionRows('2099-01-02')) === 1, 'normal daytime behavior unchanged');
} finally { sfRemove($fixture); }

// Direct resolver fixture for v2 inventory union, duplicate/missing fail
// closed behavior, deterministic union identity, and legacy v1 regression.
$root = sys_get_temp_dir() . '/cc30c_inventory_v2_' . bin2hex(random_bytes(5));
try {
    $window = 'fixture-window'; $armed = '2099-01-01T00:00:00+09:00';
    $entries = [
        ['date' => '2099-01-01', 'race' => '20990101TDA01', 'venue' => 'TDA', 'cutoff' => '2099-01-01T10:00:00+09:00'],
        ['date' => '2099-01-02', 'race' => '20990102TDA01', 'venue' => 'TDA', 'cutoff' => '2099-01-02T10:00:00+09:00'],
    ];
    $files = [];
    foreach ($entries as $row) {
        $entry = ['race_code' => $row['race'], 'venue' => $row['venue'], 'target_date' => $row['date'], 'cutoff_timestamp' => $row['cutoff'], 'stable_candidate_key' => 'scheduler-opportunity|' . $row['race']];
        $inventoryPath = 'opportunity/expected_inventory/' . str_replace('-', '', $row['date']) . '.json';
        $candidatePath = 'opportunity/candidates/' . str_replace('-', '', $row['date']) . '/' . $row['race'] . '.json';
        $eligibilityPath = 'opportunity/eligibility/' . str_replace('-', '', $row['date']) . '/' . $row['race'] . '.json';
        sfWrite($root . '/' . $inventoryPath, ['window_id' => $window, 'armed_at' => $armed, 'target_date' => $row['date'], 'expected_entries' => [$entry]]);
        sfWrite($root . '/' . $candidatePath, $entry + ['armed_at' => $armed]);
        sfWrite($root . '/' . $eligibilityPath, $entry + ['armed_at' => $armed, 'eligibility_status' => 'VALID_NON_ELIGIBLE_EXHIBITION_INCOMPLETE']);
        foreach ([$inventoryPath, $candidatePath, $eligibilityPath] as $relative) $files[$relative] = hash_file('sha256', $root . '/' . $relative);
    }
    $invoke = static function (FormalWindowProvenanceResolver $resolver, array $fileMap): array {
        $property = new ReflectionProperty($resolver, 'files'); $property->setValue($resolver, $fileMap);
        $method = new ReflectionMethod($resolver, 'expectedInventory'); return $method->invoke($resolver, '2099-01-01T00:00:00+09:00');
    };
    $v2 = new FormalWindowProvenanceResolver($root, $window, FormalWindowProvenanceResolver::EXPECTED_INVENTORY_CONTRACT_V2, '2099-01-01', '2099-01-02');
    $unionOne = $invoke($v2, $files);
    $reversed = array_reverse($files, true); $v2b = new FormalWindowProvenanceResolver($root, $window, FormalWindowProvenanceResolver::EXPECTED_INVENTORY_CONTRACT_V2, '2099-01-01', '2099-01-02');
    $unionTwo = $invoke($v2b, $reversed);
    sfAssert(($unionOne['resolution']['expected_entry_count'] ?? 0) === 2, 'multi-day union includes both required dates');
    sfAssert(($unionOne['resolution']['expected_inventory_union_sha256'] ?? '') === ($unionTwo['resolution']['expected_inventory_union_sha256'] ?? ''), 'multi-day union hash deterministic');
    try { $missing = new FormalWindowProvenanceResolver($root, $window, FormalWindowProvenanceResolver::EXPECTED_INVENTORY_CONTRACT_V2, '2099-01-01', '2099-01-03'); $invoke($missing, $files); throw new RuntimeException('missing inventory should fail'); }
    catch (RuntimeException $e) { sfAssert(str_starts_with($e->getMessage(), 'PROVENANCE_EXPECTED_INVENTORY_DATE_MISSING:2099-01-03'), 'missing daily inventory fails closed'); }
    $duplicatePath = 'opportunity/expected_inventory/duplicate.json'; sfWrite($root . '/' . $duplicatePath, json_decode((string)file_get_contents($root . '/opportunity/expected_inventory/20990101.json'), true)); $duplicateFiles = $files; $duplicateFiles[$duplicatePath] = hash_file('sha256', $root . '/' . $duplicatePath);
    try { $duplicate = new FormalWindowProvenanceResolver($root, $window, FormalWindowProvenanceResolver::EXPECTED_INVENTORY_CONTRACT_V2, '2099-01-01', '2099-01-02'); $invoke($duplicate, $duplicateFiles); throw new RuntimeException('duplicate inventory should fail'); }
    catch (RuntimeException $e) { sfAssert(str_starts_with($e->getMessage(), 'PROVENANCE_EXPECTED_INVENTORY_DATE_DUPLICATE:2099-01-01'), 'duplicate daily inventory fails closed'); }
    try { $legacy = new FormalWindowProvenanceResolver($root, $window); $invoke($legacy, $files); throw new RuntimeException('legacy cardinality should fail'); }
    catch (RuntimeException $e) { sfAssert($e->getMessage() === 'PROVENANCE_EXPECTED_INVENTORY_CARDINALITY_INVALID', 'single-day v1 behavior preserved'); }
} finally { sfRemove($root); }

echo json_encode(['status' => 'PASS', 'checks' => 14], JSON_UNESCAPED_SLASHES) . PHP_EOL;
