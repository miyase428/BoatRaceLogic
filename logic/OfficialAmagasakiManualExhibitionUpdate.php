<?php

declare(strict_types=1);

require_once __DIR__ . '/OfficialAmagasakiExhibitionStorage.php';
require_once __DIR__ . '/OfficialAmagasakiWriteBarrier.php';
require_once __DIR__ . '/OfficialAmagasakiDeadlineCache.php';
require_once __DIR__ . '/OfficialExhibitionManualRoute.php';

/** One explicit Web/PWA request. It has no retry and never changes scheduler state. */
final class OfficialAmagasakiManualExhibitionUpdate
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly OfficialAmagasakiOperationalPolicy $policy,
        private readonly OfficialAmagasakiPayloadFetcher $fetcher,
        private readonly string $deadlineDirectory,
    ) {}

    /** @return array{status:string,reason:string,comparison?:string} */
    public function run(string $raceCode): array
    {
        $route = OfficialExhibitionManualRoute::decide($this->policy, $raceCode);
        if (!$route['supported']) return ['status' => 'blocked', 'reason' => $route['reason']];
        $existing = $this->pdo->prepare('SELECT COUNT(*) FROM boat_race.exhibition_live WHERE race_code = :race');
        $existing->execute([':race' => $raceCode]);
        if ((int)$existing->fetchColumn() !== 0) return ['status' => 'cached_or_review', 'reason' => 'EXISTING_ROWS_REQUIRE_REVIEW'];
        $deadline = $this->deadline($raceCode);
        if ($deadline === null) return ['status' => 'blocked', 'reason' => 'OFFICIAL_DEADLINE_CACHE_UNAVAILABLE'];
        $entries = $this->pdo->prepare('SELECT lane_number::int AS lane_number, player_id::text AS player_id FROM boat_race.race_entry WHERE race_code = :race ORDER BY lane_number');
        $entries->execute([':race' => $raceCode]);
        $raceEntries = $entries->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($raceEntries) !== 6) return ['status' => 'blocked', 'reason' => 'RACE_ENTRY_INCOMPLETE'];
        $fetched = $this->fetcher->fetch($raceCode);
        if (($fetched['outcome'] ?? null) !== 'published' || !is_array($fetched['payload'] ?? null)) {
            return ['status' => 'no_save', 'reason' => strtoupper((string)($fetched['outcome'] ?? 'parse_error'))];
        }
        $payload = $fetched['payload'];
        try { $completed = new DateTimeImmutable((string)$payload['fetched_at']); } catch (Throwable) { return ['status' => 'no_save', 'reason' => 'FETCH_TIMESTAMP_INVALID']; }
        $begun = false;
        try {
            $this->pdo->beginTransaction(); $begun = true;
            OfficialAmagasakiWriteBarrier::lock($this->pdo, $raceCode);
            $again = $this->pdo->prepare('SELECT entry_course FROM boat_race.exhibition_live WHERE race_code = :race FOR UPDATE');
            $again->execute([':race' => $raceCode]);
            if (($again->fetchAll(PDO::FETCH_COLUMN) ?: []) !== []) { $this->pdo->rollBack(); return ['status' => 'cached_or_review', 'reason' => 'EXISTING_ROWS_REQUIRE_REVIEW']; }
            $store = new OfficialAmagasakiExhibitionTransactionStore(new PdoOfficialExhibitionRepository($this->pdo), new OfficialAmagasakiExhibitionValidator());
            $result = $store->persistManualWithinOpenTransaction($payload, $raceCode, $raceEntries, $completed > $deadline);
            if ($result['status'] !== 'saved') { $this->pdo->rollBack(); return ['status' => 'no_save', 'reason' => $result['status'], 'comparison' => $result['comparison']]; }
            $this->pdo->commit();
            return ['status' => 'saved', 'reason' => $completed > $deadline ? 'POST_DEADLINE_MANUAL_HISTORY' : 'MANUAL_OFFICIAL_SAVED', 'comparison' => 'none'];
        } catch (Throwable) {
            if ($begun && $this->pdo->inTransaction()) $this->pdo->rollBack();
            return ['status' => 'no_save', 'reason' => 'MANUAL_OFFICIAL_TRANSACTION_FAILED'];
        }
    }

    private function deadline(string $raceCode): ?DateTimeImmutable
    {
        $date = substr($raceCode, 0, 8);
        $cache = OfficialAmagasakiDeadlineCache::readComplete($this->deadlineDirectory, $date);
        $time = is_array($cache) ? ($cache['deadlines'][$raceCode] ?? null) : null;
        if (!is_string($time) || preg_match('/^\d{2}:\d{2}$/', $time) !== 1) return null;
        return new DateTimeImmutable(substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2) . ' ' . $time . ':00', new DateTimeZone('Asia/Tokyo'));
    }
}
