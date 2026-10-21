<?php

declare(strict_types=1);

require_once __DIR__ . '/OfficialAmagasakiWriteBarrier.php';

/**
 * Explicit execution context for the one permitted Kyotei Biyori fallback.
 * It is deliberately separate from the generic nightly/retry entry points:
 * only the dedicated CLI invocation, during the current JST 23:00 hour, can
 * request a same-day AMG backfill after the official cut-over.
 */
final class OfficialAmagasakiNightlyBackfillContext
{
    /** @var Closure():DateTimeImmutable */
    private Closure $clock;

    /** @param callable():DateTimeImmutable|null $clock */
    public function __construct(private readonly bool $requested, ?callable $clock = null)
    {
        $this->clock = $clock instanceof Closure
            ? $clock
            : Closure::fromCallable($clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')));
    }

    public static function fromCommandLine(array $argv): self
    {
        return new self(PHP_SAPI === 'cli' && in_array('--amagasaki-nightly-backfill', $argv, true));
    }

    public function permits(string $raceCode): bool
    {
        if (!$this->requested || preg_match('/^(\d{8})AMG(0[1-9]|1[0-2])$/', $raceCode, $parts) !== 1) return false;
        $now = ($this->clock)()->setTimezone(new DateTimeZone('Asia/Tokyo'));
        return $now->format('Ymd') === $parts[1] && $now->format('H') === '23';
    }
}

/**
 * Inserts only a genuinely absent race. It never uses UPSERT, so a legacy
 * daytime row, partial row, or late concurrent writer causes rollback.
 */
final class PdoOfficialAmagasakiNightlyBackfillPersistence
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @param list<array<string,mixed>> $results Existing Kyotei Biyori
     *        calculation results; no network operation occurs here.
     * @return array{status:string,reason:string}
     */
    public function persist(string $raceCode, array $results, string $sourceUrl, DateTimeImmutable $fetchedAt, OfficialAmagasakiNightlyBackfillContext $context): array
    {
        $this->validate($raceCode, $results, $sourceUrl, $fetchedAt);
        try {
            $this->pdo->beginTransaction();
            OfficialAmagasakiWriteBarrier::lock($this->pdo, $raceCode);
            if (!$context->permits($raceCode)) {
                $this->pdo->rollBack();
                return ['status' => 'skipped', 'reason' => 'NIGHTLY_BACKFILL_CONTEXT_INVALID'];
            }
            $decision = officialAmagasakiLegacyWriteDecision($this->pdo, $raceCode, 'nightly_backfill');
            if (!$decision['allow_legacy']) {
                $this->pdo->rollBack();
                return ['status' => 'skipped', 'reason' => $decision['reason']];
            }
            $existing = $this->pdo->prepare('SELECT entry_course FROM boat_race.exhibition_live WHERE race_code = :race_code FOR UPDATE');
            $existing->execute([':race_code' => $raceCode]);
            if (($existing->fetchAll(PDO::FETCH_COLUMN) ?: []) !== []) {
                $this->pdo->rollBack();
                return ['status' => 'skipped', 'reason' => 'EXISTING_ROWS_REQUIRE_REVIEW'];
            }

            $insert = $this->pdo->prepare('INSERT INTO boat_race.exhibition_live (race_code, entry_course, player_id, exhibition_time, start_timing, lap_time, around_time, straight_time, exhibition_score, exhibition_type, created_date, updated_date) VALUES (:race_code, :entry_course, :player_id, :exhibition_time, :start_timing, :lap_time, :around_time, :straight_time, :exhibition_score, :exhibition_type, NOW(), NOW())');
            foreach ($results as $row) {
                $insert->execute([
                    ':race_code' => $raceCode,
                    ':entry_course' => (int)$row['entry_course'],
                    ':player_id' => (string)$row['player_id'],
                    ':exhibition_time' => (float)$row['exhibition_time'],
                    ':start_timing' => (float)$row['start_timing'],
                    ':lap_time' => (float)$row['lap_time'],
                    ':around_time' => (float)$row['around_time'],
                    ':straight_time' => $row['straight_time'] === null || $row['straight_time'] === '' || $row['straight_time'] === '-' ? null : (float)$row['straight_time'],
                    ':exhibition_score' => (float)$row['exhibition_score'],
                    ':exhibition_type' => (string)$row['exhibition_type'],
                ]);
            }
            $audit = $this->pdo->prepare("INSERT INTO boat_race.exhibition_source_audit_v1 (audit_id, race_code, source, source_url, fetched_at, page_fetched_at, before_rows, after_rows, comparison_status, result_status, error_reason) VALUES (:audit_id, :race_code, 'kyoteibiyori_nightly', :source_url, :fetched_at, CAST(:page_fetched_at AS jsonb), CAST(:before_rows AS jsonb), CAST(:after_rows AS jsonb), 'none', 'saved', NULL)");
            $audit->execute([
                ':audit_id' => bin2hex(random_bytes(16)), ':race_code' => $raceCode, ':source_url' => $sourceUrl,
                ':fetched_at' => $fetchedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.v\\Z'),
                ':page_fetched_at' => '{}', ':before_rows' => '[]', ':after_rows' => json_encode($results, JSON_THROW_ON_ERROR),
            ]);
            $this->pdo->commit();
            return ['status' => 'saved', 'reason' => 'kyoteibiyori_nightly'];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            return ['status' => 'rolled_back', 'reason' => $error->getMessage()];
        }
    }

    /** @param list<array<string,mixed>> $results */
    private function validate(string $raceCode, array $results, string $sourceUrl, DateTimeImmutable $fetchedAt): void
    {
        if (!OfficialAmagasakiWriteBarrier::isTarget($raceCode) || !filter_var($sourceUrl, FILTER_VALIDATE_URL)) throw new InvalidArgumentException('nightly backfill identity or source URL is invalid');
        if (count($results) !== 6) throw new InvalidArgumentException('nightly backfill requires exactly six rows');
        $courses = [];
        $players = [];
        foreach ($results as $row) {
            $course = (int)($row['entry_course'] ?? 0);
            $player = (string)($row['player_id'] ?? '');
            if ($course < 1 || $course > 6 || isset($courses[$course]) || !preg_match('/^\d{4,6}$/', $player) || isset($players[$player])) throw new InvalidArgumentException('nightly backfill boat identity is invalid');
            foreach (['exhibition_time', 'start_timing', 'lap_time', 'around_time', 'exhibition_score'] as $field) {
                if (!isset($row[$field]) || !is_numeric($row[$field]) || !is_finite((float)$row[$field])) throw new InvalidArgumentException('nightly backfill numeric field is invalid');
            }
            if ((float)$row['exhibition_time'] <= 0 || (float)$row['lap_time'] <= 0 || (float)$row['around_time'] <= 0 || !isset($row['exhibition_type'])) throw new InvalidArgumentException('nightly backfill exhibition row is incomplete');
            $courses[$course] = true;
            $players[$player] = true;
        }
        if (count($courses) !== 6 || count($players) !== 6) throw new InvalidArgumentException('nightly backfill boat set is incomplete');
    }
}
