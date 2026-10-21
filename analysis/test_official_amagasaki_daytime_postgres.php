<?php

declare(strict_types=1);

/*
 * Destructive only to the disposable PostgreSQL cluster named by
 * PHASE2M_PG_DSN.  The harness refuses to run without that explicitly passed
 * connection string and creates no connection when this file is imported.
 */
require_once __DIR__ . '/../logic/OfficialAmagasakiDaytimeIntegration.php';
require_once __DIR__ . '/../logic/OfficialAmagasakiNightlyBackfill.php';

function pgAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/** @return list<array{lane_number:int,player_id:string}> */
function pgEntries(): array
{
    return array_map(static fn(int $lane): array => ['lane_number' => $lane, 'player_id' => (string)(8100 + $lane)], range(1, 6));
}

/** @return array<string,mixed> */
function pgPayload(string $raceCode, string $fetchedAt = '2026-10-21T10:00:01.000Z'): array
{
    preg_match('/^(\d{8})AMG(\d{2})$/', $raceCode, $parts);
    $boats = [];
    foreach (range(1, 6) as $lane) {
        $boats[] = [
            'lane' => $lane, 'entry_course' => $lane, 'player_id' => (string)(8100 + $lane),
            'exhibition_time' => '6.8' . $lane, 'start_timing' => $lane === 1 ? 'F.01' : ($lane === 2 ? 'L.02' : '.0' . $lane),
            'lap_time' => '37.' . (10 + $lane), 'around_time' => '11.' . (20 + $lane), 'straight_time' => null,
        ];
    }
    return [
        'schema' => 'official-exhibition/v1', 'status' => 'published', 'source' => 'official_amagasaki',
        'source_url' => 'https://fixture.invalid/amg', 'race_date' => $parts[1], 'race_no' => (int)$parts[2],
        'race_code' => $raceCode, 'fetched_at' => $fetchedAt,
        'page_fetched_at' => [
            'direct_info' => '2026-10-21T09:59:58.000Z', 'start_display' => '2026-10-21T09:59:59.000Z',
            'original_data' => '2026-10-21T10:00:00.000Z',
        ],
        'boats' => $boats,
    ];
}

function pgReset(PDO $pdo): void
{
    $pdo->exec('TRUNCATE boat_race.exhibition_source_audit_v1, boat_race.official_exhibition_attempt_state_v1, boat_race.exhibition_live');
}

/** @return array<string,mixed> */
function pgClaim(PdoOfficialAmagasakiAttemptRepository $attempts, string $raceCode, string $token): array
{
    $before = $attempts->load($raceCode) ?? OfficialAmagasakiAttemptState::initial($raceCode);
    $claimed = OfficialAmagasakiAttemptState::claim($before, new DateTimeImmutable('2026-10-21T10:00:00.000Z'), $token, 90);
    pgAssert($attempts->claim($raceCode, (int)$before['version'], $claimed), 'CAS claim must succeed');
    return $claimed;
}

function pgCount(PDO $pdo, string $table, string $raceCode): int
{
    $statement = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE race_code = :race_code");
    $statement->execute([':race_code' => $raceCode]);
    return (int)$statement->fetchColumn();
}

final class PgFixtureFetcher implements OfficialAmagasakiPayloadFetcher
{
    /** @param array<string,mixed> $result */
    public function __construct(private readonly array $result) {}
    public function fetch(string $raceCode): array { return $this->result; }
}

$dsn = getenv('PHASE2M_PG_DSN');
if (!is_string($dsn) || $dsn === '') {
    fwrite(STDERR, "PHASE2M_PG_DSN must name a disposable PostgreSQL cluster\n");
    exit(2);
}
if (preg_match('#(?:^|[;:])host=/tmp/boatrace-phase2(?:m|n)(?:-[a-z]+)?-pg-[^;]+(?:;|$)#', $dsn) !== 1) {
    fwrite(STDERR, "refusing a non-Phase-2M PostgreSQL DSN\n");
    exit(2);
}

$pdo = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$pdo->exec('CREATE SCHEMA IF NOT EXISTS boat_race');
$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS boat_race.exhibition_live (
    race_code varchar(32) NOT NULL,
    entry_course integer NOT NULL,
    player_id varchar(16) NOT NULL,
    exhibition_time double precision NOT NULL,
    start_timing double precision NOT NULL,
    lap_time double precision NOT NULL,
    around_time double precision NOT NULL,
    straight_time double precision NULL,
    updated_date timestamptz NULL,
    exhibition_score double precision NOT NULL,
    exhibition_type varchar(32) NOT NULL,
    created_date timestamptz NOT NULL,
    PRIMARY KEY (race_code, entry_course)
)
SQL);
$pdo->exec((string)file_get_contents(__DIR__ . '/sql/create_official_exhibition_audit_v1.sql'));
$pdo->exec((string)file_get_contents(__DIR__ . '/sql/create_official_exhibition_attempt_state_v1.sql'));
$pdo->exec((string)file_get_contents(__DIR__ . '/sql/create_official_exhibition_control_v1.sql'));
$pdo->exec((string)file_get_contents(__DIR__ . '/sql/create_official_exhibition_nightly_backfill_v1.sql'));
// This fixture may run after another isolated test; always establish the
// disabled deployment default instead of inheriting a prior test's control row.
$pdo->exec("UPDATE boat_race.official_exhibition_control_v1 SET route_enabled = false, automation_approved = false, nightly_backfill_enabled = false, retry_interval_seconds = NULL, updated_by = 'fixture-reset' WHERE control_key = 'amagasaki'");

$attempts = new PdoOfficialAmagasakiAttemptRepository($pdo);
$validator = new OfficialAmagasakiExhibitionValidator();
$persistence = new PdoOfficialAmagasakiClaimedPersistence($pdo, $validator, $attempts);
$cases = 0;

// 1–4: every process reads the same control row; missing/corrupt state blocks
// only the future AMG cut-over and never silently enables it.
$policy = OfficialAmagasakiOperationalPolicy::fromPdo($pdo);
pgAssert($policy->decide('20261021AMG01', 'daytime')['mode'] === 'legacy', 'installed disabled control retains legacy route'); $cases++;
$pdo->exec("UPDATE boat_race.official_exhibition_control_v1 SET route_enabled = true, automation_approved = false, nightly_backfill_enabled = false, retry_interval_seconds = NULL WHERE control_key = 'amagasaki'");
$policy = OfficialAmagasakiOperationalPolicy::fromPdo($pdo);
pgAssert($policy->decide('20261021AMG01', 'web')['reason'] === 'OFFICIAL_AUTOMATION_NOT_APPROVED', 'route and approval remain independent'); $cases++;
$pdo->exec("UPDATE boat_race.official_exhibition_control_v1 SET automation_approved = true, retry_interval_seconds = 120 WHERE control_key = 'amagasaki'");
$policy = OfficialAmagasakiOperationalPolicy::fromPdo($pdo);
pgAssert($policy->decide('20261021AMG01', 'retry')['mode'] === 'official' && $policy->retryIntervalSeconds() === 120, 'cron and web can resolve the same approved retry policy'); $cases++;
$pdo->exec("DELETE FROM boat_race.official_exhibition_control_v1 WHERE control_key = 'amagasaki'");
pgAssert(OfficialAmagasakiOperationalPolicy::fromPdo($pdo)->decide('20261021AMG01', 'nightly')['reason'] === 'OFFICIAL_CONTROL_STATE_INVALID', 'missing installed control row fails closed'); $cases++;
$pdo->exec("INSERT INTO boat_race.official_exhibition_control_v1 (control_key, route_enabled, automation_approved, nightly_backfill_enabled, retry_interval_seconds, updated_by) VALUES ('amagasaki', true, true, false, 120, 'fixture')");

// 1–2: atomic initial claim and stale competing claim.
pgReset($pdo); $race = '20261021AMG01'; $first = pgClaim($attempts, $race, 'claim-one');
pgAssert(($attempts->load($race)['outcome'] ?? null) === 'in_progress', 'initial claim creates state row'); $cases++;
$second = OfficialAmagasakiAttemptState::claim(OfficialAmagasakiAttemptState::initial($race), new DateTimeImmutable('2026-10-21T10:00:00Z'), 'claim-two', 90);
pgAssert(!$attempts->claim($race, 0, $second), 'same race rejects stale concurrent claim'); $cases++;

// 3–5: unpublished completion, retry wait, then second claim.
$unpublished = OfficialAmagasakiAttemptState::complete($first, 'claim-one', 'not_yet_published', new DateTimeImmutable('2026-10-21T10:00:01Z'), 120, false);
pgAssert($attempts->complete($first, 'claim-one', $unpublished), 'unpublished completion must own the claim');
$stored = $attempts->load($race); pgAssert(($stored['outcome'] ?? null) === 'not_yet_published', 'unpublished state persists'); $cases++;
try {
    OfficialAmagasakiAttemptState::complete($first, 'wrong-claim-token', 'not_yet_published', new DateTimeImmutable('2026-10-21T10:00:01Z'), 120, false);
    throw new RuntimeException('wrong claim token unexpectedly completed');
} catch (InvalidArgumentException) {
    pgAssert(($attempts->load($race)['outcome'] ?? null) === 'not_yet_published', 'wrong claim token cannot alter completed state'); $cases++;
}
$flow = new OfficialAmagasakiDaytimeFlow(new OfficialExhibitionSourceRouter(true), $validator, 120, 120, false, 90);
$candidate = ['race_code' => $race, 'deadline' => '2026-10-21T10:10:00Z', 'existing_status' => 'none', 'attempt_state' => $stored];
pgAssert($flow->plan($candidate, new DateTimeImmutable('2026-10-21T10:02:00Z'))['action'] === 'skip', 'retry_not_before prevents early retry'); $cases++;
$retry = OfficialAmagasakiAttemptState::claim((array)$stored, new DateTimeImmutable('2026-10-21T10:05:00Z'), 'claim-retry', 90);
pgAssert($attempts->claim($race, (int)$stored['version'], $retry), 'second claim succeeds only after prior completion'); $cases++;

// 6–7: six rows, audit, and completion commit as one unit; saved races stay skipped.
$result = $persistence->persistAndComplete(pgPayload($race), $race, pgEntries(), $retry, 'claim-retry', new DateTimeImmutable('2026-10-21T10:05:01Z'));
pgAssert($result['committed'] && pgCount($pdo, 'boat_race.exhibition_live', $race) === 6 && pgCount($pdo, 'boat_race.exhibition_source_audit_v1', $race) === 1 && ($attempts->load($race)['outcome'] ?? null) === 'saved', 'rows, audit, and state completion commit together'); $cases++;
$savedCandidate = $candidate; $savedCandidate['existing_status'] = 'already_official'; $savedCandidate['attempt_state'] = $attempts->load($race);
pgAssert($flow->plan($savedCandidate, new DateTimeImmutable('2026-10-21T10:05:30Z'))['action'] === 'skip', 'audited saved race is not claimed again'); $cases++;

// 8–9: a second connection cannot take the same save lock, and an audited
// official race remains protected even after route_enabled is turned off.
$pdo->beginTransaction();
OfficialAmagasakiWriteBarrier::lock($pdo, $race);
$otherLockPdo = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$tryLock = $otherLockPdo->prepare('SELECT pg_try_advisory_xact_lock(hashtext(CAST(:race_code AS text)))');
$tryLock->execute([':race_code' => $race]);
pgAssert(!in_array($tryLock->fetchColumn(), [true, 't', '1', 1], true), 'legacy connection cannot enter official save critical section'); $cases++;
$pdo->rollBack();
$pdo->exec("UPDATE boat_race.official_exhibition_control_v1 SET route_enabled = false, automation_approved = false WHERE control_key = 'amagasaki'");
try {
    officialAmagasakiBeginLegacyWrite($pdo, $race, 'web');
    throw new RuntimeException('audited official row unexpectedly allowed legacy write');
} catch (RuntimeException $error) {
    pgAssert(str_contains($error->getMessage(), 'OFFICIAL_AUDITED_ROWS_PROTECTED'), 'audited official row stays protected after route disable'); $cases++;
}
$pdo->exec("UPDATE boat_race.official_exhibition_control_v1 SET route_enabled = true, automation_approved = true, nightly_backfill_enabled = false, retry_interval_seconds = 120 WHERE control_key = 'amagasaki'");

// 10: any one of the six row inserts failing rolls back the complete set and state completion.
pgReset($pdo); $race = '20261021AMG02'; $claimed = pgClaim($attempts, $race, 'insert-fail');
$pdo->exec('ALTER TABLE boat_race.exhibition_live ADD CONSTRAINT phase2m_reject_lane CHECK (entry_course <> 4)');
$result = $persistence->persistAndComplete(pgPayload($race), $race, pgEntries(), $claimed, 'insert-fail', new DateTimeImmutable('2026-10-21T10:00:01Z'));
pgAssert(!$result['committed'] && pgCount($pdo, 'boat_race.exhibition_live', $race) === 0 && pgCount($pdo, 'boat_race.exhibition_source_audit_v1', $race) === 0 && ($attempts->load($race)['outcome'] ?? null) === 'in_progress', 'six-row insert failure rolls back every write'); $cases++;
$pdo->exec('ALTER TABLE boat_race.exhibition_live DROP CONSTRAINT phase2m_reject_lane');

// 9: a failed audit cannot leave six un-audited rows behind.
pgReset($pdo); $race = '20261021AMG03'; $claimed = pgClaim($attempts, $race, 'audit-fail');
$pdo->exec("ALTER TABLE boat_race.exhibition_source_audit_v1 ADD CONSTRAINT phase2m_reject_saved CHECK (result_status <> 'saved')");
$result = $persistence->persistAndComplete(pgPayload($race), $race, pgEntries(), $claimed, 'audit-fail', new DateTimeImmutable('2026-10-21T10:00:01Z'));
pgAssert(!$result['committed'] && pgCount($pdo, 'boat_race.exhibition_live', $race) === 0 && pgCount($pdo, 'boat_race.exhibition_source_audit_v1', $race) === 0 && ($attempts->load($race)['outcome'] ?? null) === 'in_progress', 'audit failure rolls back six rows and preserves claim'); $cases++;
$pdo->exec('ALTER TABLE boat_race.exhibition_source_audit_v1 DROP CONSTRAINT phase2m_reject_saved');

// 10: completion CAS loss rolls back rows and audit rather than reporting success.
pgReset($pdo); $race = '20261021AMG04'; $claimed = pgClaim($attempts, $race, 'completion-cas');
$pdo->prepare('UPDATE boat_race.official_exhibition_attempt_state_v1 SET version = version + 1 WHERE race_code = :race_code')->execute([':race_code' => $race]);
$result = $persistence->persistAndComplete(pgPayload($race), $race, pgEntries(), $claimed, 'completion-cas', new DateTimeImmutable('2026-10-21T10:00:01Z'));
pgAssert(!$result['committed'] && pgCount($pdo, 'boat_race.exhibition_live', $race) === 0 && pgCount($pdo, 'boat_race.exhibition_source_audit_v1', $race) === 0, 'completion CAS loss rolls back all dependent writes'); $cases++;

// 11: a second independent PDO connection observes the same CAS protection.
pgReset($pdo); $race = '20261021AMG05'; $otherPdo = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); $other = new PdoOfficialAmagasakiAttemptRepository($otherPdo);
$claimed = pgClaim($attempts, $race, 'pdo-one');
$competing = OfficialAmagasakiAttemptState::claim(OfficialAmagasakiAttemptState::initial($race), new DateTimeImmutable('2026-10-21T10:00:00Z'), 'pdo-two', 90);
pgAssert(!$other->claim($race, 0, $competing), 'independent PDO connection cannot duplicate claim'); $cases++;

// 12: player/entry mismatch cannot create partial data.
pgReset($pdo); $race = '20261021AMG06'; $claimed = pgClaim($attempts, $race, 'entry-mismatch'); $bad = pgPayload($race); $bad['boats'][0]['player_id'] = '9999';
$result = $persistence->persistAndComplete($bad, $race, pgEntries(), $claimed, 'entry-mismatch', new DateTimeImmutable('2026-10-21T10:00:01Z'));
pgAssert(!$result['committed'] && pgCount($pdo, 'boat_race.exhibition_live', $race) === 0, 'entry mismatch never saves a partial race'); $cases++;

// 13: six existing rows without provenance remain protected and are not relabelled official.
pgReset($pdo); $race = '20261021AMG07'; $rows = $validator->validateAndConvert(pgPayload($race), $race, pgEntries());
$insert = $pdo->prepare('INSERT INTO boat_race.exhibition_live (race_code, entry_course, player_id, exhibition_time, start_timing, lap_time, around_time, straight_time, exhibition_score, exhibition_type, created_date) VALUES (:race_code, :entry_course, :player_id, :exhibition_time, :start_timing, :lap_time, :around_time, :straight_time, 0, :exhibition_type, NOW())');
foreach ($rows as $row) {
    $insert->execute([
        ':race_code' => $row['race_code'], ':entry_course' => $row['entry_course'], ':player_id' => $row['player_id'],
        ':exhibition_time' => $row['exhibition_time'], ':start_timing' => $row['start_timing'], ':lap_time' => $row['lap_time'],
        ':around_time' => $row['around_time'], ':straight_time' => $row['straight_time'], ':exhibition_type' => 'fixture',
    ]);
}
$claimed = pgClaim($attempts, $race, 'source-unknown');
$result = $persistence->persistAndComplete(pgPayload($race), $race, pgEntries(), $claimed, 'source-unknown', new DateTimeImmutable('2026-10-21T10:00:01Z'));
pgAssert($result['committed'] && $result['status'] === 'requires_review_source_unknown' && pgCount($pdo, 'boat_race.exhibition_live', $race) === 6 && pgCount($pdo, 'boat_race.exhibition_source_audit_v1', $race) === 0 && ($attempts->load($race)['outcome'] ?? null) === 'source_unknown', 'unknown-provenance rows are retained without official relabelling'); $cases++;

// 14: a post-cutoff fetched payload is rejected before actual persistence.
pgReset($pdo); $race = '20261021AMG08';
$runner = new OfficialAmagasakiDaytimeIntegration($flow, $attempts, new PgFixtureFetcher(['outcome' => 'published', 'payload' => pgPayload($race, '2026-10-21T10:08:00.001Z')]), $persistence, static fn(): DateTimeImmutable => new DateTimeImmutable('2026-10-21T10:08:00.001Z'));
$events = $runner->run([['race_code' => $race, 'deadline' => '2026-10-21T10:10:00Z', 'prediction_cutoff' => '2026-10-21T10:08:00.000Z', 'existing_status' => 'none', 'race_entries' => pgEntries()]], new DateTimeImmutable('2026-10-21T10:00:00Z'), false);
pgAssert($events[0]['reason'] === 'PAYLOAD_OR_TIME_REJECTED' && pgCount($pdo, 'boat_race.exhibition_live', $race) === 0 && pgCount($pdo, 'boat_race.exhibition_source_audit_v1', $race) === 0, 'post-cutoff payload is not persisted as forward evidence'); $cases++;


// 15–18: permitted 23:00 backfill only inserts an absent race, records its
// own source, and rolls back when its provenance write cannot commit.
$pdo->exec("UPDATE boat_race.official_exhibition_control_v1 SET route_enabled = true, automation_approved = false, nightly_backfill_enabled = true, retry_interval_seconds = NULL WHERE control_key = 'amagasaki'");
$backfill = new PdoOfficialAmagasakiNightlyBackfillPersistence($pdo);
$nightlyContext = new OfficialAmagasakiNightlyBackfillContext(true, static fn(): DateTimeImmutable => new DateTimeImmutable('2026-10-21T23:10:00+09:00'));
pgReset($pdo); $race = '20261021AMG10';
$nightRows = array_map(static fn(int $lane): array => ['entry_course' => $lane, 'player_id' => (string)(8200 + $lane), 'exhibition_time' => 6.80 + $lane / 100, 'start_timing' => 0.01 * $lane, 'lap_time' => 37.10 + $lane / 100, 'around_time' => 11.20 + $lane / 100, 'straight_time' => null, 'exhibition_score' => 0.0, 'exhibition_type' => 'fixture'], range(1, 6));
$result = $backfill->persist($race, $nightRows, 'https://fixture.invalid/kyoteibiyori', new DateTimeImmutable('2026-10-21T14:00:00Z'), $nightlyContext);
$source = $pdo->prepare('SELECT source FROM boat_race.exhibition_source_audit_v1 WHERE race_code = :race_code'); $source->execute([':race_code' => $race]);
pgAssert($result['status'] === 'saved' && pgCount($pdo, 'boat_race.exhibition_live', $race) === 6 && $source->fetchColumn() === 'kyoteibiyori_nightly', 'nightly backfill saves six absent rows and distinct provenance atomically'); $cases++;
$result = $backfill->persist($race, $nightRows, 'https://fixture.invalid/kyoteibiyori', new DateTimeImmutable('2026-10-21T14:01:00Z'), $nightlyContext);
pgAssert($result['status'] === 'skipped' && $result['reason'] === 'EXISTING_ROWS_REQUIRE_REVIEW', 'nightly backfill cannot overwrite its own prior rows'); $cases++;
pgReset($pdo); $officialRace = '20261021AMG09'; $officialClaim = pgClaim($attempts, $officialRace, 'official-before-night');
$officialSaved = $persistence->persistAndComplete(pgPayload($officialRace), $officialRace, pgEntries(), $officialClaim, 'official-before-night', new DateTimeImmutable('2026-10-21T10:00:01Z'));
$result = $backfill->persist($officialRace, $nightRows, 'https://fixture.invalid/kyoteibiyori', new DateTimeImmutable('2026-10-21T14:01:00Z'), $nightlyContext);
pgAssert($officialSaved['committed'] && $result['status'] === 'skipped' && $result['reason'] === 'OFFICIAL_AUDITED_ROWS_PROTECTED', 'officially audited six rows are never backfilled at 23:00'); $cases++;
pgReset($pdo); $race = '20261021AMG11';
$pdo->exec("INSERT INTO boat_race.exhibition_live (race_code, entry_course, player_id, exhibition_time, start_timing, lap_time, around_time, straight_time, exhibition_score, exhibition_type, created_date, updated_date) VALUES ('20261021AMG11', 1, '8201', 6.81, 0.01, 37.11, 11.21, NULL, 0, 'fixture', NOW(), NOW())");
$result = $backfill->persist($race, $nightRows, 'https://fixture.invalid/kyoteibiyori', new DateTimeImmutable('2026-10-21T14:00:00Z'), $nightlyContext);
pgAssert($result['status'] === 'skipped' && $result['reason'] === 'EXISTING_ROWS_REQUIRE_REVIEW' && pgCount($pdo, 'boat_race.exhibition_live', $race) === 1, 'nightly backfill never overwrites partial or low-quality rows'); $cases++;
pgReset($pdo); $race = '20261021AMG12';
$pdo->exec("ALTER TABLE boat_race.exhibition_source_audit_v1 ADD CONSTRAINT phase2m_reject_nightly CHECK (source <> 'kyoteibiyori_nightly')");
$result = $backfill->persist($race, $nightRows, 'https://fixture.invalid/kyoteibiyori', new DateTimeImmutable('2026-10-21T14:00:00Z'), $nightlyContext);
pgAssert($result['status'] === 'rolled_back' && pgCount($pdo, 'boat_race.exhibition_live', $race) === 0, 'nightly audit failure rolls back all six rows'); $cases++;
$pdo->exec('ALTER TABLE boat_race.exhibition_source_audit_v1 DROP CONSTRAINT phase2m_reject_nightly');
$pdo->exec("UPDATE boat_race.official_exhibition_control_v1 SET nightly_backfill_enabled = false WHERE control_key = 'amagasaki'");
$result = $backfill->persist('20261021AMG09', $nightRows, 'https://fixture.invalid/kyoteibiyori', new DateTimeImmutable('2026-10-21T14:00:00Z'), $nightlyContext);
pgAssert($result['status'] === 'skipped' && $result['reason'] === 'OFFICIAL_NIGHTLY_BACKFILL_NOT_ENABLED', 'nightly backfill fails closed when its dedicated control is disabled'); $cases++;

echo "PASS {$cases} disposable PostgreSQL PDO integration cases\n";
