<?php

declare(strict_types=1);

require_once __DIR__ . '/OfficialAmagasakiDaytimeIntegration.php';
require_once __DIR__ . '/OfficialAmagasakiOperationalPolicy.php';

/** Builds candidate records without fetching an external page or writing state. */
final class OfficialAmagasakiDaytimeCandidateBuilder
{
    /**
     * @param list<array<string,mixed>> $entryRows
     * @param array<string,string> $deadlines race_code => HH:MM or ISO timestamp
     * @param callable(string):string $existingStatus
     * @return list<array<string,mixed>>
     */
    public function build(string $date, array $entryRows, array $deadlines, callable $existingStatus): array
    {
        if (!preg_match('/^\d{8}$/', $date)) throw new InvalidArgumentException('date must be YYYYMMDD');
        $byRace = [];
        foreach ($entryRows as $row) {
            $race = (string)($row['race_code'] ?? '');
            if (!preg_match('/^' . preg_quote($date, '/') . 'AMG(0[1-9]|1[0-2])$/', $race)) continue;
            $byRace[$race][] = ['lane_number' => (int)($row['lane_number'] ?? 0), 'player_id' => (string)($row['player_id'] ?? '')];
        }
        ksort($byRace, SORT_NATURAL);
        $out = [];
        foreach ($byRace as $race => $entries) {
            if (count($entries) !== 6 || !isset($deadlines[$race])) continue;
            $deadline = $this->deadline($date, (string)$deadlines[$race]);
            if ($deadline === null) continue;
            $out[] = [
                'race_code' => $race,
                'deadline' => $deadline->format(DATE_ATOM),
                'prediction_cutoff' => $deadline->modify('-120 seconds')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.v\\Z'),
                'existing_status' => $existingStatus($race),
                'race_entries' => $entries,
            ];
        }
        return $out;
    }

    private function deadline(string $date, string $value): ?DateTimeImmutable
    {
        try {
            if (preg_match('/^\d{2}:\d{2}$/', $value)) return new DateTimeImmutable(substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2) . ' ' . $value . ':00', new DateTimeZone('Asia/Tokyo'));
            $parsed = new DateTimeImmutable($value);
            return $parsed->setTimezone(new DateTimeZone('Asia/Tokyo'));
        } catch (Throwable) {
            return null;
        }
    }
}

/**
 * Reads only the pre-existing official-deadline cache. It intentionally never
 * refreshes it: an unavailable or malformed cache is a no-fetch condition.
 */
final class PdoOfficialAmagasakiDaytimeCandidateSource
{
    public function __construct(private readonly PDO $pdo, private readonly string $deadlineCacheDirectory)
    {
    }

    /** @return list<array<string,mixed>> */
    public function candidates(string $date): array
    {
        $this->requireMigrationTables();
        $cachePath = rtrim($this->deadlineCacheDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'deadlines_' . $date . '.json';
        $raw = @file_get_contents($cachePath);
        $cache = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($cache) || ($cache['date'] ?? null) !== $date || !is_array($cache['deadlines'] ?? null)) {
            throw new RuntimeException('OFFICIAL_DEADLINE_CACHE_UNAVAILABLE');
        }
        $entries = $this->pdo->prepare("SELECT race_code, lane_number::int AS lane_number, player_id::text AS player_id FROM boat_race.race_entry WHERE race_date = CAST(:race_date AS date) AND stadium_code = 'AMG' ORDER BY race_code, lane_number");
        $entries->execute([':race_date' => substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2)]);
        $rows = $entries->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $status = function (string $raceCode): string {
            $audit = $this->pdo->prepare("SELECT EXISTS (SELECT 1 FROM boat_race.exhibition_source_audit_v1 WHERE race_code = :race_code AND source = 'official_amagasaki' AND result_status = 'saved')");
            $audit->execute([':race_code' => $raceCode]);
            if (in_array($audit->fetchColumn(), [true, 't', '1', 1], true)) return 'already_official';
            $existing = $this->pdo->prepare('SELECT COUNT(DISTINCT entry_course) FROM boat_race.exhibition_live WHERE race_code = :race_code AND player_id IS NOT NULL AND exhibition_time > 0 AND start_timing IS NOT NULL AND lap_time > 0 AND around_time > 0');
            $existing->execute([':race_code' => $raceCode]);
            $count = (int)$existing->fetchColumn();
            return $count === 0 ? 'none' : ($count === 6 ? 'source_unknown' : 'partial');
        };
        return (new OfficialAmagasakiDaytimeCandidateBuilder())->build($date, $rows, $cache['deadlines'], $status);
    }

    private function requireMigrationTables(): void
    {
        $result = $this->pdo->query("SELECT to_regclass('boat_race.exhibition_source_audit_v1') IS NOT NULL AS audit, to_regclass('boat_race.official_exhibition_attempt_state_v1') IS NOT NULL AS attempt");
        $row = $result === false ? false : $result->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !in_array($row['audit'] ?? null, [true, 't', '1', 1], true) || !in_array($row['attempt'] ?? null, [true, 't', '1', 1], true)) {
            throw new RuntimeException('OFFICIAL_AMAGASAKI_MIGRATIONS_NOT_APPLIED');
        }
    }
}

/** Operational shell around the pre-existing claim/fetch/persist integration. */
final class OfficialAmagasakiDaytimeJob
{
    public function __construct(
        private readonly OfficialAmagasakiOperationalPolicy $policy,
        private readonly PdoOfficialAmagasakiDaytimeCandidateSource $candidates,
        private readonly OfficialAmagasakiDaytimeIntegration $integration,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function run(string $date, DateTimeImmutable $now, bool $dryRun, ?int $onlyRace = null): array
    {
        $route = $this->policy->decide($date . 'AMG01', 'daytime');
        if ($route['mode'] === 'legacy') return [['action' => 'skip', 'reason' => 'OFFICIAL_ROUTE_DISABLED', 'dry_run' => $dryRun]];
        if ($route['mode'] !== 'official') return [['action' => 'blocked', 'reason' => $route['reason'], 'dry_run' => $dryRun]];
        return $this->integration->run(self::selectCandidates($this->candidates->candidates($date), $date, $onlyRace), $now, $dryRun);
    }

    /**
     * Restricts the side-effecting integration boundary to one explicitly
     * selected AMG race.  Candidate-source reads may still inspect the daily
     * schedule, but excluded races never reach claim, fetch, or persistence.
     *
     * @param list<array<string,mixed>> $candidates
     * @return list<array<string,mixed>>
     */
    public static function selectCandidates(array $candidates, string $date, ?int $onlyRace): array
    {
        if ($onlyRace === null) return $candidates;
        if ($onlyRace < 1 || $onlyRace > 12 || !preg_match('/^\d{8}$/', $date)) {
            throw new InvalidArgumentException('only race must be between 01 and 12 for a valid date');
        }
        $target = $date . 'AMG' . str_pad((string)$onlyRace, 2, '0', STR_PAD_LEFT);
        return array_values(array_filter($candidates, static fn(array $candidate): bool => ($candidate['race_code'] ?? null) === $target));
    }
}
