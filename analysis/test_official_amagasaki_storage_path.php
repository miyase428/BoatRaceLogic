<?php

declare(strict_types=1);

require_once __DIR__ . '/../logic/OfficialExhibitionSourceRouter.php';
require_once __DIR__ . '/../logic/OfficialAmagasakiExhibitionStorage.php';

final class FakeOfficialRepository implements OfficialExhibitionRepository
{
    /** @var list<array<string,mixed>> */ public array $rows = [];
    public bool $audit = false;
    public bool $failInsert = false;
    public bool $failAudit = false;
    /** @var array<string,mixed>|null */ public ?array $lastAudit = null;
    public int $begins = 0;
    public int $commits = 0;
    public int $rollbacks = 0;
    /** @var list<array<string,mixed>> */ private array $snapshotRows = [];
    private bool $snapshotAudit = false;
    public function existingRows(string $raceCode): array { return $this->rows; }
    public function hasOfficialAudit(string $raceCode): bool { return $this->audit; }
    public function averages(string $placeCode): array { return ['avg_exh' => 6.90, 'avg_lap' => 38.00, 'avg_around' => 11.60, 'avg_straight' => 0.0]; }
    public function begin(): void { $this->begins++; $this->snapshotRows = $this->rows; $this->snapshotAudit = $this->audit; }
    public function insertSix(array $rows): void { if ($this->failInsert) throw new RuntimeException('insert failed'); $this->rows = $rows; }
    public function insertAudit(array $audit): void { if ($this->failAudit) throw new RuntimeException('audit failed'); $this->audit = true; $this->lastAudit = $audit; }
    public function commit(): void { $this->commits++; }
    public function rollback(): void { $this->rollbacks++; $this->rows = $this->snapshotRows; $this->audit = $this->snapshotAudit; }
}

function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function expectFailure(callable $callback, string $message): void { try { $callback(); } catch (InvalidArgumentException) { return; } throw new RuntimeException($message); }
function entries(): array { return array_map(static fn(int $lane): array => ['lane_number' => $lane, 'player_id' => (string)(5100 + $lane)], range(1, 6)); }
function payload(): array {
    $boats = [];
    foreach (range(1, 6) as $lane) {
        $boats[] = ['lane' => $lane, 'entry_course' => $lane, 'player_id' => (string)(5100 + $lane), 'exhibition_time' => '6.8' . $lane,
            'start_timing' => $lane === 1 ? 'F.01' : ($lane === 2 ? 'L.02' : ($lane === 3 ? '0.00' : '.0' . $lane)),
            'lap_time' => '37.' . (10 + $lane), 'around_time' => '11.' . (20 + $lane), 'straight_time' => null];
    }
    return ['schema' => 'official-exhibition/v1', 'status' => 'published', 'source' => 'official_amagasaki', 'source_url' => 'https://example.invalid/direct',
        'race_date' => '20261021', 'race_no' => 1, 'race_code' => '20261021AMG01', 'fetched_at' => '2026-10-21T10:00:03.000Z',
        'page_fetched_at' => ['direct_info' => '2026-10-21T10:00:00.000Z', 'start_display' => '2026-10-21T10:00:01.000Z', 'original_data' => '2026-10-21T10:00:02.000Z'], 'boats' => $boats];
}

$cases = 0;
$legacy = new OfficialExhibitionSourceRouter(false);
foreach (['nightly', 'web', 'retry', 'strict_forward'] as $entry) {
    check($legacy->route('20261021AMG01', $entry)['action'] === 'blocked', 'official route must default blocked'); $cases++;
}
check($legacy->route('20261020AMG01', 'nightly')['source'] === 'kyoteibiyori', 'old AMG must be legacy'); $cases++;
check($legacy->route('20261021TMG01', 'web')['source'] === 'kyoteibiyori', 'other venue must be legacy'); $cases++;
check($legacy->legacyRetryUrlAllowed('https://kyoteibiyori.com/race_shusso.php?place_no=13&race_no=1&hiduke=20261021'), 'legacy retry URL accepted'); $cases++;
check(!$legacy->legacyRetryUrlAllowed('https://boatrace-amagasaki.jp/modules/yosou/group-cyokuzen.php'), 'official URL never inferred from legacy retry'); $cases++;
check((new OfficialExhibitionSourceRouter(true))->route('20261021AMG01', 'nightly')['action'] === 'official_fetch', 'explicit approval required'); $cases++;
$guardPath = tempnam(sys_get_temp_dir(), 'official-amg-guard-');
check(is_string($guardPath), 'isolated guard fixture path');
$guard = new OfficialAmagasakiFailureGuard($guardPath, 2, 30);
check($guard->canAttempt(100)['allowed'], 'empty isolated guard allows first attempt'); $cases++;
check($guard->recordFailure(100)['recorded'] && $guard->canAttempt(100)['allowed'], 'first failure is recorded without fallback'); $cases++;
check($guard->recordFailure(101)['blocked_until'] === 131 && !$guard->canAttempt(101)['allowed'], 'second failure blocks official source only'); $cases++;
check($guard->recordSuccess(102) && $guard->canAttempt(102)['allowed'], 'success clears isolated official guard'); $cases++;
unlink($guardPath);

$validator = new OfficialAmagasakiExhibitionValidator();
$valid = payload();
$converted = $validator->validateAndConvert($valid, '20261021AMG01', entries());
check(count($converted) === 6 && $converted[0]['start_timing'] === -0.01 && $converted[1]['start_timing'] === 0.02 && $converted[2]['start_timing'] === 0.0, 'F/L/0.00 conversion'); $cases++;
check($validator->isForwardEligible($valid, '2026-10-21T10:00:03.000Z'), 'completion at cutoff is eligible'); $cases++;
check(!$validator->isForwardEligible($valid, '2026-10-21T10:00:02.999Z'), 'post-cutoff completion rejected'); $cases++;
$bad = payload(); $bad['page_fetched_at']['original_data'] = '2026-10-21T10:00:04.000Z'; expectFailure(fn() => $validator->validateAndConvert($bad, '20261021AMG01', entries()), 'timestamp reversal must fail'); $cases++;
$bad = payload(); $bad['boats'][5]['entry_course'] = 1; expectFailure(fn() => $validator->validateAndConvert($bad, '20261021AMG01', entries()), 'course duplicate must fail'); $cases++;
$bad = payload(); $bad['boats'][0]['player_id'] = '9999'; expectFailure(fn() => $validator->validateAndConvert($bad, '20261021AMG01', entries()), 'entry mismatch must fail'); $cases++;
$bad = payload(); $bad['boats'][0]['straight_time'] = '6.70'; expectFailure(fn() => $validator->validateAndConvert($bad, '20261021AMG01', entries()), 'AMG straight value must fail'); $cases++;

$repo = new FakeOfficialRepository(); $store = new OfficialAmagasakiExhibitionTransactionStore($repo, $validator);
check($store->persist(payload(), '20261021AMG01', entries())['status'] === 'saved' && $repo->commits === 1 && count($repo->rows) === 6 && $repo->audit, 'new six rows and audit commit atomically'); $cases++;
$repo = new FakeOfficialRepository(); $store = new OfficialAmagasakiExhibitionTransactionStore($repo, $validator);
check($store->persistLateHistory(payload(), '20261021AMG01', entries())['status'] === 'saved' && $repo->lastAudit['comparison_status'] === 'late_manual_history_import' && $repo->lastAudit['error_reason'] === 'POST_DEADLINE_MANUAL_IMPORT', 'late history has an explicit audit marker while retaining atomic storage'); $cases++;
$repo = new FakeOfficialRepository(); $repo->failAudit = true; $store = new OfficialAmagasakiExhibitionTransactionStore($repo, $validator);
check($store->persistLateHistory(payload(), '20261021AMG01', entries())['status'] === 'rolled_back' && $repo->rollbacks === 1 && $repo->rows === [] && !$repo->audit, 'late-history audit failure rolls back all six rows'); $cases++;
$repo = new FakeOfficialRepository(); $store = new OfficialAmagasakiExhibitionTransactionStore($repo, $validator);
check($store->persistManualWithinOpenTransaction(payload(), '20261021AMG01', entries(), false)['status'] === 'saved' && $repo->lastAudit['comparison_status'] === 'manual_official_predeadline', 'manual pre-deadline audit marker is explicit'); $cases++;
$repo = new FakeOfficialRepository(); $store = new OfficialAmagasakiExhibitionTransactionStore($repo, $validator);
check($store->persistManualWithinOpenTransaction(payload(), '20261021AMG01', entries(), true)['status'] === 'saved' && $repo->lastAudit['comparison_status'] === 'manual_official_postdeadline' && $repo->lastAudit['error_reason'] === 'POST_DEADLINE_MANUAL_FETCH', 'manual post-deadline audit marker prevents forward provenance confusion'); $cases++;
$repo = new FakeOfficialRepository(); $repo->rows = array_slice($converted, 0, 3); $store = new OfficialAmagasakiExhibitionTransactionStore($repo, $validator);
check($store->persist(payload(), '20261021AMG01', entries())['status'] === 'requires_review_partial' && $repo->rollbacks === 1, 'partial data is not overwritten'); $cases++;
$repo = new FakeOfficialRepository(); $repo->rows = $converted; $store = new OfficialAmagasakiExhibitionTransactionStore($repo, $validator);
check($store->persist(payload(), '20261021AMG01', entries())['status'] === 'requires_review_source_unknown', 'unprovenanced six rows remain unknown'); $cases++;
$repo = new FakeOfficialRepository(); $repo->rows = $converted; $repo->audit = true; $store = new OfficialAmagasakiExhibitionTransactionStore($repo, $validator);
check($store->persist(payload(), '20261021AMG01', entries())['status'] === 'already_official', 'official exact rows preserved'); $cases++;
$repo = new FakeOfficialRepository(); $repo->rows = $converted; $repo->rows[0]['lap_time'] = 99.0; $repo->audit = true; $store = new OfficialAmagasakiExhibitionTransactionStore($repo, $validator);
check($store->persist(payload(), '20261021AMG01', entries())['status'] === 'requires_review_mismatch', 'mismatch not overwritten'); $cases++;
$repo = new FakeOfficialRepository(); $repo->failAudit = true; $store = new OfficialAmagasakiExhibitionTransactionStore($repo, $validator);
check($store->persist(payload(), '20261021AMG01', entries())['status'] === 'rolled_back' && $repo->rollbacks === 1 && $repo->rows === [] && !$repo->audit, 'audit failure rolls back six rows'); $cases++;

echo "PASS {$cases} synthetic cases\n";
