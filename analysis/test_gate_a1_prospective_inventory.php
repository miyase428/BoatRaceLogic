<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/GateA1ProspectiveInventory.php';
require_once __DIR__ . '/../web/logic/StrictForwardOpportunityLedger.php';

function gateA1Assert(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException('ASSERTION_FAILED: ' . $label);
    echo "PASS {$label}\n";
}

/** @return array{root:string,cache:string,inventory:GateA1ProspectiveInventory,ledger:StrictForwardOpportunityLedger,window_id:string,armed_at:string,candidates:list<array<string,string>>} */
function gateA1Fixture(string $suffix): array
{
    $root = sys_get_temp_dir() . '/gate_a1_inventory_' . $suffix . '_' . bin2hex(random_bytes(5));
    $cache = $root . '/official_deadlines.json';
    $windowId = 'fixture-window-' . $suffix;
    $armedAt = '2099-12-28T09:00:00+09:00';
    $deadlines = [];
    $candidates = [];
    foreach (GateA1ProspectiveInventory::strictVenues() as $venue) {
        for ($race = 1; $race <= 8; $race++) {
            $raceCode = '20991228' . $venue . str_pad((string)$race, 2, '0', STR_PAD_LEFT);
            $time = sprintf('%02d:%02d', 10 + intdiv($race - 1, 4), (($race - 1) % 4) * 10);
            $deadlines[$raceCode] = $time;
            $candidates[] = [
                'race_code' => $raceCode,
                'venue' => $venue,
                'cutoff_timestamp' => '2099-12-28T' . $time . ':00+09:00',
            ];
        }
    }
    // A pre-ARM entry may exist in the source/cache, but is outside the
    // prospective denominator under the shared cutoff > armed_at rule.
    $deadlines['20991228TMG12'] = '08:59';
    $candidates[] = ['race_code' => '20991228TMG12', 'venue' => 'TMG', 'cutoff_timestamp' => '2099-12-28T08:59:00+09:00'];
    if (!is_dir(dirname($cache)) && !mkdir(dirname($cache), 0775, true) && !is_dir(dirname($cache))) throw new RuntimeException('fixture_dir_unavailable');
    file_put_contents($cache, json_encode([
        'date' => '20991228', 'status' => 'ok', 'source' => 'fixture-official-cache',
        'fetched_at' => '2099-12-28T08:30:00+09:00', 'errors' => [], 'deadlines' => $deadlines,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    return [
        'root' => $root,
        'cache' => $cache,
        'inventory' => new GateA1ProspectiveInventory($root . '/opportunity'),
        'ledger' => new StrictForwardOpportunityLedger($root . '/opportunity'),
        'window_id' => $windowId, 'armed_at' => $armedAt, 'candidates' => $candidates,
    ];
}

/** @param array{ledger:StrictForwardOpportunityLedger,candidates:list<array<string,string>>} $fixture */
function gateA1RecordCandidates(array $fixture, ?array $candidates = null): void
{
    $fixture['ledger']->recordCandidateUniverse([
        'date' => '2099-12-28', 'candidates' => $candidates ?? $fixture['candidates'],
        'source' => 'fixture-official-cache', 'source_identity' => 'sha256:fixture-source',
        'source_generated_at' => '2099-12-28T08:30:00+09:00', 'universe_recorded_at' => '2099-12-28T08:31:00+09:00',
    ]);
}

/** @param array{inventory:GateA1ProspectiveInventory,cache:string,window_id:string,armed_at:string} $fixture */
function gateA1Freeze(array $fixture): array
{
    return $fixture['inventory']->freezeOfficialCache('2099-12-28', '20991228', $fixture['window_id'], $fixture['armed_at'], $fixture['cache']);
}

$results = [];

// The expected inventory is written directly from the official source before
// Candidate persistence; it is therefore not self-generated from Candidates.
$fixture = gateA1Fixture('exact');
$freeze = gateA1Freeze($fixture);
gateA1Assert($freeze['accepted'] && $freeze['expected_count'] === 96, 'independent prospective expected inventory freezes before candidates');
$before = $fixture['inventory']->compare($fixture['window_id'], $fixture['armed_at']);
gateA1Assert($before['status'] === 'FAIL' && $before['expected_count'] === 96 && $before['observed_count'] === 0, 'expected source is not derived from observed candidates');
gateA1RecordCandidates($fixture);
$exact = $fixture['inventory']->compare($fixture['window_id'], $fixture['armed_at']);
gateA1Assert($exact['status'] === 'PASS' && $exact['expected_count'] === 96 && $exact['observed_count'] === 96, 'exact expected96 observed96 passes and pre-arm candidate is excluded');
$restart = gateA1Freeze($fixture);
$restarted = $fixture['inventory']->compare($fixture['window_id'], $fixture['armed_at']);
gateA1Assert($restart['status'] === 'EXPECTED_INVENTORY_ALREADY_FROZEN' && $restarted['status'] === 'PASS', 'first-write expected inventory is deterministic on restart');
$results[] = 'exact96_and_restart';

$fixture = gateA1Fixture('missing');
gateA1Freeze($fixture);
$withoutLast = $fixture['candidates'];
array_splice($withoutLast, 95, 1);
gateA1RecordCandidates($fixture, $withoutLast);
$missing = $fixture['inventory']->compare($fixture['window_id'], $fixture['armed_at']);
gateA1Assert($missing['status'] === 'FAIL' && count($missing['missing']) === 1 && $missing['observed_count'] === 95, 'expected96 observed95 identifies missing key');
$results[] = 'missing_key_fails';

$fixture = gateA1Fixture('unexpected');
gateA1Freeze($fixture);
gateA1RecordCandidates($fixture);
gateA1RecordCandidates($fixture, [[
    'race_code' => '20991228TMG09', 'venue' => 'TMG', 'cutoff_timestamp' => '2099-12-28T12:00:00+09:00',
]]);
$unexpected = $fixture['inventory']->compare($fixture['window_id'], $fixture['armed_at']);
gateA1Assert($unexpected['status'] === 'FAIL' && count($unexpected['unexpected']) === 1 && $unexpected['observed_count'] === 97, 'expected96 observed97 identifies unexpected key');
$results[] = 'unexpected_key_fails';

$fixture = gateA1Fixture('both');
gateA1Freeze($fixture);
$candidates = $fixture['candidates'];
array_splice($candidates, 0, 1);
gateA1RecordCandidates($fixture, $candidates);
gateA1RecordCandidates($fixture, [[
    'race_code' => '20991228TMG09', 'venue' => 'TMG', 'cutoff_timestamp' => '2099-12-28T12:00:00+09:00',
]]);
$both = $fixture['inventory']->compare($fixture['window_id'], $fixture['armed_at']);
gateA1Assert($both['status'] === 'FAIL' && count($both['missing']) === 1 && count($both['unexpected']) === 1, 'one missing plus one unexpected fails');
$results[] = 'missing_and_unexpected_fail';

$fixture = gateA1Fixture('duplicate');
gateA1Freeze($fixture);
gateA1RecordCandidates($fixture);
$duplicate = $fixture['candidates'][0];
$duplicatePath = $fixture['root'] . '/opportunity/candidates/20991229/duplicate.json';
mkdir(dirname($duplicatePath), 0775, true);
file_put_contents($duplicatePath, json_encode(array_merge($duplicate, ['target_date' => '2099-12-28']), JSON_UNESCAPED_SLASHES));
$duplicateResult = $fixture['inventory']->compare($fixture['window_id'], $fixture['armed_at']);
gateA1Assert($duplicateResult['status'] === 'FAIL' && count($duplicateResult['duplicate']) === 1, 'duplicate canonical key fails');
$results[] = 'duplicate_key_fails';

$fixture = gateA1Fixture('conflict');
gateA1Freeze($fixture);
gateA1RecordCandidates($fixture);
$eventPath = $fixture['root'] . '/opportunity/operational/20991228.jsonl';
if (!is_dir(dirname($eventPath)) && !mkdir(dirname($eventPath), 0775, true) && !is_dir(dirname($eventPath))) throw new RuntimeException('fixture_operational_dir_unavailable');
file_put_contents($eventPath, json_encode([
    'event' => 'CANDIDATE_ORIGINAL_SEMANTIC_DIFFERENCE', 'race_code' => $fixture['candidates'][0]['race_code'],
]) . "\n");
$conflict = $fixture['inventory']->compare($fixture['window_id'], $fixture['armed_at']);
gateA1Assert($conflict['status'] === 'FAIL' && count($conflict['conflicting']) === 1, 'candidate semantic conflict fails Gate A1');
$results[] = 'conflicting_key_fails';

$fixture = gateA1Fixture('cross-window');
gateA1Freeze($fixture);
gateA1RecordCandidates($fixture);
$cross = $fixture['inventory']->compare('other-window', $fixture['armed_at']);
gateA1Assert($cross['status'] === 'BLOCKED_EXPECTED_INVENTORY_INVALID', 'cross-window expected inventory cannot be mixed');
$results[] = 'cross_window_mix_fails';

// CC12: a transient empty cache must not be allowed to consume the
// first-write slot. A later valid authoritative load owns the entire
// Expected -> readback -> Candidate transaction.
$fixture = gateA1Fixture('cc12-bootstrap');
$invalid = GateA1ProspectiveInventory::validateAuthoritativeDeadlineResult('2099-12-28', '20991228', [
    'date' => '20991228', 'status' => 'ok', 'source' => 'fixture', 'fetched_at' => '2099-12-28T08:20:00+09:00',
    'errors' => [], 'deadlines' => [],
], ['TMG']);
gateA1Assert(!$invalid['accepted'] && $invalid['reason'] === 'AUTHORITATIVE_STRICT_INVENTORY_EMPTY', 'initial empty strict source is rejected before first write');
gateA1Assert(!is_file($fixture['root'] . '/opportunity/expected_inventory/20991228.json'), 'invalid initial source writes no expected inventory');
$raw = json_decode((string)file_get_contents($fixture['cache']), true, 512, JSON_THROW_ON_ERROR);
$valid = GateA1ProspectiveInventory::validateAuthoritativeDeadlineResult('2099-12-28', '20991228', array_merge($raw, ['status' => 'ok']), GateA1ProspectiveInventory::strictVenues());
gateA1Assert($valid['accepted'], 'refreshed authoritative snapshot validates after initial empty source');
$derived = GateA1ProspectiveInventory::deriveProspectiveSnapshot($valid['snapshot'], $fixture['armed_at']);
gateA1Assert($derived['accepted'] && count($derived['snapshot']['strict_entries']) === 96, 'authoritative snapshot derives the shared prospective keyset');
$frozen = $fixture['inventory']->freezeValidatedAuthoritativeSnapshot($fixture['window_id'], $fixture['armed_at'], $derived['snapshot']);
$readback = $fixture['inventory']->verifyValidatedAuthoritativeSnapshot($fixture['window_id'], $fixture['armed_at'], $derived['snapshot']);
gateA1Assert($frozen['accepted'] && $readback['accepted'], 'expected inventory freezes and readback verifies same authoritative snapshot');
try {
    $fixture['ledger']->recordCandidateUniverse([
        'date' => '2099-12-28', 'candidates' => $derived['snapshot']['strict_entries'], 'source' => 'fixture',
        'source_identity' => (string)$derived['snapshot']['source_identity'], 'source_generated_at' => (string)$derived['snapshot']['source_generated_at'],
        'deadline_snapshot_identity' => (string)$derived['snapshot']['deadline_snapshot_identity'],
        'prospective_filter_contract' => (string)$derived['snapshot']['prospective_filter_contract'],
        'prospective_keyset_identity' => (string)$derived['snapshot']['prospective_keyset_identity'],
        'armed_at' => (string)$derived['snapshot']['prospective_armed_at'],
        'prospective_entries' => $derived['snapshot']['strict_entries'],
        'formal_barrier_required' => true,
    ]);
    throw new RuntimeException('barrier_bypass_unexpectedly_allowed');
} catch (RuntimeException $error) { gateA1Assert($error->getMessage() === 'EXPECTED_INVENTORY_BARRIER_UNVERIFIED', 'candidate writer blocks before expected readback barrier'); }
$recorded = $fixture['ledger']->recordCandidateUniverse([
    'date' => '2099-12-28', 'candidates' => $derived['snapshot']['strict_entries'], 'source' => 'fixture',
    'source_identity' => (string)$derived['snapshot']['source_identity'], 'source_generated_at' => (string)$derived['snapshot']['source_generated_at'],
    'deadline_snapshot_identity' => (string)$derived['snapshot']['deadline_snapshot_identity'],
    'prospective_filter_contract' => (string)$derived['snapshot']['prospective_filter_contract'],
    'prospective_keyset_identity' => (string)$derived['snapshot']['prospective_keyset_identity'],
    'armed_at' => (string)$derived['snapshot']['prospective_armed_at'],
    'prospective_entries' => $derived['snapshot']['strict_entries'],
    'formal_barrier_required' => true, 'expected_inventory_verified' => true,
]);
gateA1Assert($recorded['saved'] === 96, 'candidate writer accepts only the verified prospective keyset');
gateA1Assert($fixture['inventory']->freezeValidatedAuthoritativeSnapshot($fixture['window_id'], $fixture['armed_at'], $derived['snapshot'])['accepted'], 'same authoritative retry is idempotent');

// CC18: fetched_at/cache metadata identifies an acquisition, not the
// official deadline meaning.  The frozen Expected denominator must accept a
// later acquisition when the strict semantic payload is identical.
$sameContentLater = $raw;
$sameContentLater['fetched_at'] = '2099-12-28T08:45:00+09:00';
$sameLaterSnapshot = GateA1ProspectiveInventory::validateAuthoritativeDeadlineResult('2099-12-28', '20991228', $sameContentLater, GateA1ProspectiveInventory::strictVenues());
$sameLaterDerived = GateA1ProspectiveInventory::deriveProspectiveSnapshot($sameLaterSnapshot['snapshot'], $fixture['armed_at']);
gateA1Assert($sameLaterSnapshot['accepted'] && $sameLaterDerived['accepted']
    && $sameLaterSnapshot['snapshot']['deadline_snapshot_identity'] === $valid['snapshot']['deadline_snapshot_identity']
    && $sameLaterSnapshot['snapshot']['acquisition_deadline_identity'] !== $valid['snapshot']['acquisition_deadline_identity'], 'cc18 generated_at changes acquisition identity but preserves semantic deadline identity');
gateA1Assert($fixture['inventory']->verifyValidatedAuthoritativeSnapshot($fixture['window_id'], $fixture['armed_at'], $sameLaterDerived['snapshot'])['accepted'], 'cc18 same semantic deadline acquisition passes frozen Expected readback');
$reordered = $raw;
$reordered['deadlines'] = array_reverse($raw['deadlines'], true);
$reordered['fetched_at'] = '2099-12-28T08:46:00+09:00';
$reorderedSnapshot = GateA1ProspectiveInventory::validateAuthoritativeDeadlineResult('2099-12-28', '20991228', $reordered, GateA1ProspectiveInventory::strictVenues());
gateA1Assert($reorderedSnapshot['accepted'] && $reorderedSnapshot['snapshot']['deadline_snapshot_identity'] === $valid['snapshot']['deadline_snapshot_identity'], 'cc18 deadline retrieval order does not change semantic identity');
$mtimeOnly = $raw;
$mtimeOnly['fetched_at'] = '2099-12-28T08:47:00+09:00';
touch($fixture['cache'], 1234567890);
$mtimeSnapshot = GateA1ProspectiveInventory::validateAuthoritativeDeadlineResult('2099-12-28', '20991228', $mtimeOnly, GateA1ProspectiveInventory::strictVenues());
gateA1Assert($mtimeSnapshot['accepted'] && $mtimeSnapshot['snapshot']['deadline_snapshot_identity'] === $valid['snapshot']['deadline_snapshot_identity'], 'cc18 cache mtime is excluded from semantic identity');
$changed = $raw; $changed['status'] = 'ok'; $changed['deadlines']['20991228TMG01'] = '12:34';
$changedSnapshot = GateA1ProspectiveInventory::validateAuthoritativeDeadlineResult('2099-12-28', '20991228', $changed, GateA1ProspectiveInventory::strictVenues());
$changedDerived = GateA1ProspectiveInventory::deriveProspectiveSnapshot($changedSnapshot['snapshot'], $fixture['armed_at']);
gateA1Assert($changedSnapshot['accepted'] && $changedDerived['accepted'] && !$fixture['inventory']->verifyValidatedAuthoritativeSnapshot($fixture['window_id'], $fixture['armed_at'], $changedDerived['snapshot'])['accepted']
    && $fixture['inventory']->verifyValidatedAuthoritativeSnapshot($fixture['window_id'], $fixture['armed_at'], $changedDerived['snapshot'])['reason'] === 'EXPECTED_INVENTORY_SEMANTIC_REVISION_CONFLICT', 'cc18 cutoff semantic revision fails loud against frozen Expected inventory');
$removed = $raw; unset($removed['deadlines']['20991228TMG08']);
$removedSnapshot = GateA1ProspectiveInventory::validateAuthoritativeDeadlineResult('2099-12-28', '20991228', $removed, GateA1ProspectiveInventory::strictVenues());
gateA1Assert($removedSnapshot['accepted'] && $removedSnapshot['snapshot']['deadline_snapshot_identity'] !== $valid['snapshot']['deadline_snapshot_identity'], 'cc18 race deletion changes semantic identity');
$strictError = $raw; $strictError['errors'] = ['TMG' => 'official_source_error']; $strictError['status'] = 'partial';
$strictErrorSnapshot = GateA1ProspectiveInventory::validateAuthoritativeDeadlineResult('2099-12-28', '20991228', $strictError, GateA1ProspectiveInventory::strictVenues());
gateA1Assert(!$strictErrorSnapshot['accepted'] && $strictErrorSnapshot['reason'] === 'AUTHORITATIVE_STRICT_VENUE_ERROR', 'cc18 strict source error fail-loud blocks semantic admission');
$results[] = 'cc12_authoritative_bootstrap_barrier';

// CC15: prospective membership is a single, offset-aware cutoff > armed_at
// derivation.  Six strict venues x 12 races gives 72 source rows, of which
// 27 are at/before armed_at and 45 are formally prospective.
$root = sys_get_temp_dir() . '/gate_a1_inventory_cc15_' . bin2hex(random_bytes(5));
$inventory = new GateA1ProspectiveInventory($root . '/opportunity');
$ledger = new StrictForwardOpportunityLedger($root . '/opportunity');
$armedAt = '2099-12-28T13:00:00+09:00';
$venues = array_slice(GateA1ProspectiveInventory::strictVenues(), 0, 6);
$deadlines = [];
$sourceEntries = [];
$position = 0;
foreach ($venues as $venue) {
    for ($race = 1; $race <= 12; $race++) {
        $position++;
        $raceCode = '20991228' . $venue . str_pad((string)$race, 2, '0', STR_PAD_LEFT);
        $time = $position <= 26 ? '12:59' : ($position === 27 ? '13:00' : '13:01');
        $deadlines[$raceCode] = $time;
    }
}
$rawResult = [
    'date' => '20991228', 'status' => 'ok', 'source' => 'fixture-official-deadline',
    'fetched_at' => '2099-12-28T12:00:00+09:00', 'errors' => [], 'deadlines' => $deadlines,
];
$validated = GateA1ProspectiveInventory::validateAuthoritativeDeadlineResult('2099-12-28', '20991228', $rawResult, $venues);
gateA1Assert($validated['accepted'] && count($validated['snapshot']['strict_entries']) === 72, 'cc15 validates 72 strict source races');
$derived = GateA1ProspectiveInventory::deriveProspectiveSnapshot($validated['snapshot'], $armedAt);
gateA1Assert($derived['accepted'] && count($derived['snapshot']['strict_entries']) === 45 && $derived['snapshot']['pre_armed_excluded_count'] === 27, 'cc15 derives exactly 45 cutoff greater than armed_at races');
gateA1Assert(GateA1ProspectiveInventory::deriveProspectiveSnapshot($validated['snapshot'], '2099-12-28T04:00:00Z')['accepted']
    && count(GateA1ProspectiveInventory::deriveProspectiveSnapshot($validated['snapshot'], '2099-12-28T04:00:00Z')['snapshot']['strict_entries']) === 45, 'cc15 compares offset-aware JST and UTC instants consistently');
$timezoneBlocked = GateA1ProspectiveInventory::deriveProspectiveSnapshot($validated['snapshot'], '2099-12-28T13:00:00');
gateA1Assert(!$timezoneBlocked['accepted'] && $timezoneBlocked['reason'] === 'ARMED_AT_TIMEZONE_INVALID', 'cc15 blocks timezone-missing armed_at');

$frozen = $inventory->freezeValidatedAuthoritativeSnapshot('fixture-window-cc15', $armedAt, $derived['snapshot']);
$readback = $inventory->verifyValidatedAuthoritativeSnapshot('fixture-window-cc15', $armedAt, $derived['snapshot']);
gateA1Assert($frozen['accepted'] && $frozen['expected_count'] === 45 && $readback['accepted'], 'cc15 freezes and reads back the 45-race prospective set');
$formalContext = [
    'date' => '2099-12-28', 'candidates' => $derived['snapshot']['strict_entries'],
    'source' => (string)$derived['snapshot']['source'], 'source_identity' => (string)$derived['snapshot']['source_identity'],
    'source_generated_at' => (string)$derived['snapshot']['source_generated_at'],
    'deadline_snapshot_identity' => (string)$derived['snapshot']['deadline_snapshot_identity'],
    'prospective_filter_contract' => (string)$derived['snapshot']['prospective_filter_contract'],
    'prospective_keyset_identity' => (string)$derived['snapshot']['prospective_keyset_identity'],
    'armed_at' => (string)$derived['snapshot']['prospective_armed_at'],
    'prospective_entries' => $derived['snapshot']['strict_entries'],
    'formal_barrier_required' => true, 'expected_inventory_verified' => true,
];
$saved = $ledger->recordCandidateUniverse($formalContext);
gateA1Assert($saved['saved'] === 45 && $inventory->compare('fixture-window-cc15', $armedAt)['status'] === 'PASS', 'cc15 Candidate uses the same 45-race Expected keyset');
$preArmed = $validated['snapshot']['strict_entries'][0];
try {
    $bad = $formalContext;
    $bad['candidates'] = array_merge($bad['candidates'], [$preArmed]);
    $ledger->recordCandidateUniverse($bad);
    throw new RuntimeException('cc15_prearmed_candidate_unexpectedly_allowed');
} catch (RuntimeException $error) {
    gateA1Assert($error->getMessage() === 'PRE_ARMED_OR_INVALID_FORMAL_CANDIDATE', 'cc15 rejects a pre-armed Candidate before any formal write');
}
gateA1Assert(count($ledger->candidateRows('2099-12-28')) === 45, 'cc15 pre-armed races cannot reach downstream formal candidate paths');
$projectionVenue = (string)$derived['snapshot']['strict_entries'][0]['venue'];
$formalCodes = $ledger->formalProspectiveCandidateRaceCodes('2099-12-28', $projectionVenue);
$expectedVenueCount = count(array_filter(
    $derived['snapshot']['strict_entries'],
    static fn(array $row): bool => ($row['venue'] ?? '') === $projectionVenue,
));
$preArmedCode = (string)$preArmed['race_code'];
gateA1Assert(count($formalCodes) === $expectedVenueCount && !in_array($preArmedCode, $formalCodes, true), 'cc15 formal downstream projection excludes every pre-armed Candidate');
gateA1Assert($inventory->freezeValidatedAuthoritativeSnapshot('fixture-window-cc15', $armedAt, $derived['snapshot'])['accepted'], 'cc15 same snapshot and armed_at retry is idempotent');
gateA1Assert(!$inventory->freezeValidatedAuthoritativeSnapshot('fixture-window-cc15', '2099-12-28T13:00:01+09:00', $derived['snapshot'])['accepted'], 'cc15 different armed_at conflicts with frozen Expected inventory');
gateA1Assert($derived['snapshot']['prospective_keyset_identity'] === GateA1ProspectiveInventory::deriveProspectiveSnapshot($validated['snapshot'], $armedAt)['snapshot']['prospective_keyset_identity'], 'cc15 scheduler current time does not alter A1 population');
$results[] = 'cc15_prospective_cutoff_boundary';

echo json_encode(['status' => 'PASS', 'fixtures' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
