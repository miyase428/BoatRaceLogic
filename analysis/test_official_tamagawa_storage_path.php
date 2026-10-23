<?php

declare(strict_types=1);

require_once __DIR__ . '/../logic/OfficialTamagawaExhibitionStorage.php';

final class FakeOfficialRepository implements OfficialTamagawaExhibitionRepository
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
    public function averages(string $placeCode): array { return ['avg_exh' => 6.90, 'avg_lap' => 38.00, 'avg_around' => 11.60, 'avg_straight' => 6.90]; }
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
            'lap_time' => '37.' . (10 + $lane), 'around_time' => '11.' . (20 + $lane), 'straight_time' => '6.' . (70 + $lane)];
    }
    return ['schema' => 'official-exhibition/v1', 'status' => 'published', 'source' => 'official_tamagawa', 'source_url' => 'https://example.invalid/direct',
        'race_date' => '20261021', 'race_no' => 1, 'race_code' => '20261021TMG01', 'fetched_at' => '2026-10-21T10:00:03.000Z',
        'page_fetched_at' => ['start_display' => '2026-10-21T10:00:01.000Z', 'original_data' => '2026-10-21T10:00:02.000Z'], 'boats' => $boats];
}

$cases = 0;

$validator = new OfficialTamagawaExhibitionValidator();
$valid = payload();
$converted = $validator->validateAndConvert($valid, '20261021TMG01', entries());
check(count($converted) === 6 && $converted[0]['start_timing'] === -0.01 && $converted[1]['start_timing'] === 0.02 && $converted[2]['start_timing'] === 0.0, 'F/L/0.00 conversion'); $cases++;
check($validator->isForwardEligible($valid, '2026-10-21T10:00:03.000Z'), 'completion at cutoff is eligible'); $cases++;
check(!$validator->isForwardEligible($valid, '2026-10-21T10:00:02.999Z'), 'post-cutoff completion rejected'); $cases++;
$bad = payload(); $bad['page_fetched_at']['original_data'] = '2026-10-21T10:00:04.000Z'; expectFailure(fn() => $validator->validateAndConvert($bad, '20261021TMG01', entries()), 'timestamp reversal must fail'); $cases++;
$bad = payload(); $bad['boats'][5]['entry_course'] = 1; expectFailure(fn() => $validator->validateAndConvert($bad, '20261021TMG01', entries()), 'course duplicate must fail'); $cases++;
$bad = payload(); $bad['boats'][0]['player_id'] = '9999'; expectFailure(fn() => $validator->validateAndConvert($bad, '20261021TMG01', entries()), 'entry mismatch must fail'); $cases++;
$bad = payload(); $bad['boats'][0]['straight_time'] = null; expectFailure(fn() => $validator->validateAndConvert($bad, '20261021TMG01', entries()), 'TMG straight value is required'); $cases++;

$repo = new FakeOfficialRepository(); $store = new OfficialTamagawaExhibitionTransactionStore($repo, $validator);
check($store->persist(payload(), '20261021TMG01', entries())['status'] === 'saved' && $repo->commits === 1 && count($repo->rows) === 6 && $repo->audit, 'new six rows and audit commit atomically'); $cases++;
$repo = new FakeOfficialRepository(); $store = new OfficialTamagawaExhibitionTransactionStore($repo, $validator);
check($store->persistLateHistory(payload(), '20261021TMG01', entries())['status'] === 'saved' && $repo->lastAudit['comparison_status'] === 'late_manual_history_import' && $repo->lastAudit['error_reason'] === 'POST_DEADLINE_MANUAL_IMPORT', 'late history has an explicit audit marker while retaining atomic storage'); $cases++;
$repo = new FakeOfficialRepository(); $repo->failAudit = true; $store = new OfficialTamagawaExhibitionTransactionStore($repo, $validator);
check($store->persistLateHistory(payload(), '20261021TMG01', entries())['status'] === 'rolled_back' && $repo->rollbacks === 1 && $repo->rows === [] && !$repo->audit, 'late-history audit failure rolls back all six rows'); $cases++;
$repo = new FakeOfficialRepository(); $store = new OfficialTamagawaExhibitionTransactionStore($repo, $validator);
check($store->persistManualWithinOpenTransaction(payload(), '20261021TMG01', entries(), false)['status'] === 'saved' && $repo->lastAudit['comparison_status'] === 'manual_official_predeadline', 'manual pre-deadline audit marker is explicit'); $cases++;
$repo = new FakeOfficialRepository(); $store = new OfficialTamagawaExhibitionTransactionStore($repo, $validator);
check($store->persistManualWithinOpenTransaction(payload(), '20261021TMG01', entries(), true)['status'] === 'saved' && $repo->lastAudit['comparison_status'] === 'manual_official_postdeadline' && $repo->lastAudit['error_reason'] === 'POST_DEADLINE_MANUAL_FETCH', 'manual post-deadline audit marker prevents forward provenance confusion'); $cases++;
$repo = new FakeOfficialRepository(); $repo->rows = array_slice($converted, 0, 3); $store = new OfficialTamagawaExhibitionTransactionStore($repo, $validator);
check($store->persist(payload(), '20261021TMG01', entries())['status'] === 'requires_review_partial' && $repo->rollbacks === 1, 'partial data is not overwritten'); $cases++;
$repo = new FakeOfficialRepository(); $repo->rows = $converted; $store = new OfficialTamagawaExhibitionTransactionStore($repo, $validator);
check($store->persist(payload(), '20261021TMG01', entries())['status'] === 'requires_review_source_unknown', 'unprovenanced six rows remain unknown'); $cases++;
$repo = new FakeOfficialRepository(); $repo->rows = $converted; $repo->audit = true; $store = new OfficialTamagawaExhibitionTransactionStore($repo, $validator);
check($store->persist(payload(), '20261021TMG01', entries())['status'] === 'already_official', 'official exact rows preserved'); $cases++;
$repo = new FakeOfficialRepository(); $repo->rows = $converted; $repo->rows[0]['lap_time'] = 99.0; $repo->audit = true; $store = new OfficialTamagawaExhibitionTransactionStore($repo, $validator);
check($store->persist(payload(), '20261021TMG01', entries())['status'] === 'requires_review_mismatch', 'mismatch not overwritten'); $cases++;
$repo = new FakeOfficialRepository(); $repo->failAudit = true; $store = new OfficialTamagawaExhibitionTransactionStore($repo, $validator);
check($store->persist(payload(), '20261021TMG01', entries())['status'] === 'rolled_back' && $repo->rollbacks === 1 && $repo->rows === [] && !$repo->audit, 'audit failure rolls back six rows'); $cases++;

echo "PASS {$cases} Tamagawa storage synthetic cases\n";
