<?php

declare(strict_types=1);

require_once __DIR__ . '/OfficialAmagasakiExhibitionStorage.php';
require_once __DIR__ . '/OfficialAmagasakiWriteBarrier.php';

/**
 * Explicit, one-race import path for a verified official payload obtained
 * after its deadline.  It never fetches a URL and never touches attempt state.
 */
final class OfficialAmagasakiLateHistoryImporter
{
    /** @var list<string> */
    public const ALLOWED_RACE_CODES = [
        '20261022AMG02', '20261022AMG03', '20261022AMG04', '20261022AMG05',
        '20261022AMG06', '20261022AMG07', '20261022AMG08',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly OfficialAmagasakiExhibitionValidator $validator,
        private readonly string $raceCode,
        private readonly DateTimeImmutable $deadline,
    )
    {
        self::assertAllowedRaceCode($this->raceCode);
    }

    /** @param array<string,mixed> $payload @param list<array<string,mixed>> $raceEntries @return array{status:string,comparison:string} */
    public function import(array $payload, array $raceEntries): array
    {
        $this->validate($payload, $raceEntries);
        $begun = false;
        try {
            $this->pdo->beginTransaction();
            $begun = true;
            OfficialAmagasakiWriteBarrier::lock($this->pdo, $this->raceCode);
            $this->assertDatabaseTimeIsLate();
            $this->assertNoSavedAudit();
            $store = new OfficialAmagasakiExhibitionTransactionStore(new PdoOfficialExhibitionRepository($this->pdo), $this->validator);
            $result = $store->persistLateHistoryWithinOpenTransaction($payload, $this->raceCode, $raceEntries);
            if ($result['status'] !== 'saved') {
                $this->pdo->rollBack();
                return $result;
            }
            $this->pdo->commit();
            return $result;
        } catch (Throwable) {
            if ($begun && $this->pdo->inTransaction()) $this->pdo->rollBack();
            return ['status' => 'rolled_back', 'comparison' => 'none'];
        }
    }

    /** @param array<string,mixed> $payload @param list<array<string,mixed>> $entries */
    public function validate(array $payload, array $entries): void
    {
        self::assertLatePayloadIdentity($payload, $this->raceCode, $this->deadline);
        $this->validator->validateAndConvert($payload, $this->raceCode, $entries);
    }

    /** @param array<string,mixed> $payload @param list<array<string,mixed>> $entries @return array{status:string,existing_rows:int,saved_audit:bool} */
    public function dryRun(array $payload, array $entries): array
    {
        $this->validate($payload, $entries);
        $rows = $this->pdo->prepare('SELECT COUNT(*) FROM boat_race.exhibition_live WHERE race_code = :race_code');
        $rows->execute([':race_code' => $this->raceCode]);
        $audit = $this->pdo->prepare("SELECT EXISTS (SELECT 1 FROM boat_race.exhibition_source_audit_v1 WHERE race_code = :race_code AND source = 'official_amagasaki' AND result_status = 'saved')");
        $audit->execute([':race_code' => $this->raceCode]);
        $rowCount = (int)$rows->fetchColumn();
        $savedAudit = in_array($audit->fetchColumn(), [true, 't', '1', 1], true);
        return ['status' => $rowCount === 0 && !$savedAudit ? 'validated_late_history_only' : 'requires_review_existing_state', 'existing_rows' => $rowCount, 'saved_audit' => $savedAudit];
    }

    /** @param array<string,mixed> $payload */
    public static function assertLatePayloadIdentity(array $payload, string $raceCode, DateTimeImmutable $deadline): void
    {
        self::assertAllowedRaceCode($raceCode);
        if (($payload['race_code'] ?? null) !== $raceCode) throw new InvalidArgumentException('late history import race is not approved');
        try {
            $fetched = new DateTimeImmutable((string)($payload['fetched_at'] ?? ''));
        } catch (Throwable) {
            throw new InvalidArgumentException('late history fetched_at is invalid');
        }
        if ($fetched <= $deadline) throw new InvalidArgumentException('late history payload must have completed after deadline');
    }

    private function assertDatabaseTimeIsLate(): void
    {
        $statement = $this->pdo->prepare('SELECT clock_timestamp() > CAST(:deadline AS timestamptz)');
        $statement->execute([':deadline' => $this->deadline->format(DateTimeInterface::ATOM)]);
        if (!in_array($statement->fetchColumn(), [true, 't', '1', 1], true)) throw new RuntimeException('database time is not after fixed deadline');
    }

    private function assertNoSavedAudit(): void
    {
        $statement = $this->pdo->prepare("SELECT 1 FROM boat_race.exhibition_source_audit_v1 WHERE race_code = :race_code AND source = 'official_amagasaki' AND result_status = 'saved' LIMIT 1 FOR UPDATE");
        $statement->execute([':race_code' => $this->raceCode]);
        if ($statement->fetchColumn() !== false) throw new RuntimeException('saved official audit already exists');
    }

    public static function assertAllowedRaceCode(string $raceCode): void
    {
        if (!in_array($raceCode, self::ALLOWED_RACE_CODES, true)) {
            throw new InvalidArgumentException('late history race is not in the approved AMG02-AMG08 set');
        }
    }

}
