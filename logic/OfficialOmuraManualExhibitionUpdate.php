<?php

declare(strict_types=1);

require_once __DIR__ . '/OfficialOmuraExhibitionStorage.php';
require_once __DIR__ . '/OfficialOmuraDeadlineCache.php';
require_once __DIR__ . '/OfficialExhibitionManualRoute.php';

/** One explicit Web/PWA request. It has no retry and never changes scheduler state. */
final class OfficialOmuraManualExhibitionUpdate
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly NodeOfficialOmuraPayloadFetcher $fetcher,
        private readonly string $deadlineDirectory,
        private readonly ?Closure $deadlineLoader = null,
        private readonly ?Closure $clock = null,
    ) {}

    /** @return array{status:string,reason:string,comparison?:string} */
    public function run(string $raceCode): array
    {
        $route = OfficialExhibitionManualRoute::decide($raceCode);
        if ($route['source'] !== 'official_omura') return ['status' => 'blocked', 'reason' => 'OFFICIAL_EXHIBITION_UNSUPPORTED_VENUE'];
        $preflight = self::manualFetchTimingDecision($raceCode, $this->now(), null, null);
        if (!$preflight['allowed']) return ['status' => 'blocked', 'reason' => $preflight['reason']];
        $entries = $this->pdo->prepare('SELECT lane_number::int AS lane_number, player_id::text AS player_id FROM boat_race.race_entry WHERE race_code = :race ORDER BY lane_number');
        $entries->execute([':race' => $raceCode]);
        $raceEntries = $entries->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($raceEntries) !== 6) return ['status' => 'blocked', 'reason' => 'RACE_ENTRY_INCOMPLETE'];
        $existing = $this->existingState($raceCode, $raceEntries, false);
        if ($existing['status'] !== 'absent') return $existing;
        $deadline = $this->deadline($raceCode);
        if ($deadline === null) return ['status' => 'blocked', 'reason' => 'OFFICIAL_DEADLINE_CACHE_UNAVAILABLE'];
        $fetched = $this->fetcher->fetch($raceCode);
        if (($fetched['outcome'] ?? null) !== 'published' || !is_array($fetched['payload'] ?? null)) {
            return ['status' => 'no_save', 'reason' => strtoupper((string)($fetched['outcome'] ?? 'parse_error'))];
        }
        $payload = $fetched['payload'];
        try { $completed = new DateTimeImmutable((string)$payload['fetched_at']); } catch (Throwable) { return ['status' => 'no_save', 'reason' => 'FETCH_TIMESTAMP_INVALID']; }
        $timing = self::manualFetchTimingDecision($raceCode, $this->now(), $deadline, $completed);
        if (!$timing['allowed']) return ['status' => 'blocked', 'reason' => $timing['reason']];
        $begun = false;
        try {
            $this->pdo->beginTransaction(); $begun = true;
            $lock = $this->pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(CAST(:race_code AS text)))');
            $lock->execute([':race_code' => $raceCode]);
            $again = $this->existingState($raceCode, $raceEntries, true);
            if ($again['status'] !== 'absent') { $this->pdo->rollBack(); return $again; }
            $store = new OfficialOmuraExhibitionTransactionStore(new PdoOfficialOmuraExhibitionRepository($this->pdo), new OfficialOmuraExhibitionValidator());
            $result = $store->persistManualWithinOpenTransaction($payload, $raceCode, $raceEntries, $timing['after_deadline']);
            if ($result['status'] !== 'saved') { $this->pdo->rollBack(); return ['status' => 'no_save', 'reason' => $result['status'], 'comparison' => $result['comparison']]; }
            $this->pdo->commit();
            return ['status' => 'saved', 'reason' => $timing['after_deadline'] ? 'MANUAL_OFFICIAL_POSTDEADLINE_SAVED' : 'MANUAL_OFFICIAL_SAVED', 'comparison' => 'none'];
        } catch (Throwable) {
            if ($begun && $this->pdo->inTransaction()) $this->pdo->rollBack();
            return ['status' => 'no_save', 'reason' => 'MANUAL_OFFICIAL_TRANSACTION_FAILED'];
        }
    }

    /**
     * Keeps the manual route limited to the calendar day of the click in JST.
     * A deadline is evidence for audit classification, not a closing time for
     * Omura's same-day historical display.  Strict Forward independently
     * accepts rows only when exhibition_live.created_date is before its cutoff.
     *
     * @return array{allowed:bool,reason:string,after_deadline:bool}
     */
    public static function manualFetchTimingDecision(string $raceCode, DateTimeImmutable $now, ?DateTimeImmutable $deadline, ?DateTimeImmutable $completed): array
    {
        $jstNow = $now->setTimezone(new DateTimeZone('Asia/Tokyo'));
        if (!preg_match('/^\d{8}OMR(?:0[1-9]|1[0-2])$/', $raceCode) || substr($raceCode, 0, 8) !== $jstNow->format('Ymd')) {
            return ['allowed' => false, 'reason' => 'OFFICIAL_MANUAL_NONCURRENT_DATE', 'after_deadline' => false];
        }
        return [
            'allowed' => true,
            'reason' => '',
            'after_deadline' => $deadline !== null && $completed !== null && $completed > $deadline,
        ];
    }

    /** @param list<array<string,mixed>> $raceEntries @return array{status:string,reason:string,comparison?:string} */
    private function existingState(string $raceCode, array $raceEntries, bool $forUpdate): array
    {
        $rowsSql = 'SELECT entry_course, player_id, exhibition_time, start_timing, lap_time, around_time, straight_time FROM boat_race.exhibition_live WHERE race_code = :race ORDER BY entry_course' . ($forUpdate ? ' FOR UPDATE' : '');
        $rowsStmt = $this->pdo->prepare($rowsSql);
        $rowsStmt->execute([':race' => $raceCode]);
        $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $auditStmt = $this->pdo->prepare("SELECT after_rows FROM boat_race.exhibition_source_audit_v1 WHERE race_code = :race AND source = 'official_omura' AND result_status = 'saved' ORDER BY db_saved_at DESC NULLS LAST LIMIT 1");
        $auditStmt->execute([':race' => $raceCode]);
        $audit = $auditStmt->fetch(PDO::FETCH_ASSOC);
        if ($rows === [] && $audit === false) return ['status' => 'absent', 'reason' => 'NO_EXISTING_ROWS'];
        if ($rows === []) return ['status' => 'review', 'reason' => 'OFFICIAL_AUDIT_WITHOUT_ROWS'];
        $expectedPlayers = array_column($raceEntries, 'player_id');
        $courses = []; $players = [];
        foreach ($rows as $row) {
            $course = (int)($row['entry_course'] ?? 0); $player = (string)($row['player_id'] ?? '');
            if ($course < 1 || $course > 6 || isset($courses[$course]) || !in_array($player, $expectedPlayers, true) || isset($players[$player])
                || !is_numeric($row['exhibition_time'] ?? null) || (float)$row['exhibition_time'] <= 0
                || !is_numeric($row['start_timing'] ?? null) || !is_numeric($row['lap_time'] ?? null) || (float)$row['lap_time'] <= 0
                || !is_numeric($row['around_time'] ?? null) || (float)$row['around_time'] <= 0
                || !is_numeric($row['straight_time'] ?? null) || (float)$row['straight_time'] <= 0) {
                return ['status' => 'review', 'reason' => 'EXISTING_ROWS_INVALID'];
            }
            $courses[$course] = true; $players[$player] = true;
        }
        if (count($rows) !== 6 || count($courses) !== 6 || count($players) !== 6) return ['status' => 'review', 'reason' => 'EXISTING_ROWS_INCOMPLETE'];
        // Six valid rows matched to the current race entry are preserved even
        // if they were saved by the existing Kyotei Biyori night process and
        // therefore have no official audit record.
        if ($audit === false) return ['status' => 'already_saved', 'reason' => 'EXISTING_SIX_BOATS_ALREADY_SAVED', 'comparison' => 'source_unknown'];
        $after = json_decode((string)($audit['after_rows'] ?? ''), true);
        $auditRows = [];
        foreach ($after as $row) {
            if (!is_array($row)) return ['status' => 'review', 'reason' => 'OFFICIAL_AUDIT_ROWS_INVALID'];
            $player = (string)($row['player_id'] ?? '');
            if ($player === '' || isset($auditRows[$player])) return ['status' => 'review', 'reason' => 'OFFICIAL_AUDIT_ROWS_INVALID'];
            $auditRows[$player] = $row;
        }
        foreach ($rows as $row) {
            $auditRow = $auditRows[(string)$row['player_id']] ?? null;
            if (!is_array($auditRow) || (int)($auditRow['entry_course'] ?? 0) !== (int)$row['entry_course']) {
                return ['status' => 'review', 'reason' => 'OFFICIAL_AUDIT_ROW_MISMATCH'];
            }
            foreach (['exhibition_time', 'start_timing', 'lap_time', 'around_time'] as $field) {
                if (!is_numeric($auditRow[$field] ?? null) || abs((float)$auditRow[$field] - (float)$row[$field]) > 0.00001) {
                    return ['status' => 'review', 'reason' => 'OFFICIAL_AUDIT_VALUE_MISMATCH'];
                }
            }
            if (!is_numeric($auditRow['straight_time'] ?? null) || !is_numeric($row['straight_time'] ?? null)
                || abs((float)$auditRow['straight_time'] - (float)$row['straight_time']) > 0.00001) {
                return ['status' => 'review', 'reason' => 'OFFICIAL_AUDIT_VALUE_MISMATCH'];
            }
        }
        return ['status' => 'already_saved', 'reason' => 'OFFICIAL_SIX_BOATS_ALREADY_SAVED', 'comparison' => 'official_audit_match'];
    }

    private function deadline(string $raceCode): ?DateTimeImmutable
    {
        $date = substr($raceCode, 0, 8);
        $cache = OfficialOmuraDeadlineCache::readComplete($this->deadlineDirectory, $date);
        $time = $this->completeDeadlineTime($cache, $date, $raceCode);
        if ($time === null) {
            try {
                $loaded = $this->deadlineLoader !== null
                    ? ($this->deadlineLoader)($date)
                    : $this->loadOfficialDeadlines($date);
            } catch (Throwable) {
                return null;
            }
            $time = $this->completeDeadlineTime($loaded, $date, $raceCode);
        }
        if ($time === null) return null;
        return new DateTimeImmutable(substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2) . ' ' . $time . ':00', new DateTimeZone('Asia/Tokyo'));
    }

    /** @return array<string,mixed> */
    private function loadOfficialDeadlines(string $date): array
    {
        require_once __DIR__ . '/../web/logic/OfficialDeadlineLogic.php';
        return (new OfficialDeadlineLogic())->load($date, ['OMR'], false);
    }

    private function completeDeadlineTime(mixed $value, string $date, string $raceCode): ?string
    {
        if (!is_array($value) || ($value['date'] ?? null) !== $date || !is_array($value['deadlines'] ?? null)) return null;
        foreach (range(1, 12) as $race) {
            $code = $date . 'OMR' . sprintf('%02d', $race);
            if (!OfficialOmuraDeadlineCache::isValidTime($value['deadlines'][$code] ?? null)) return null;
        }
        $time = $value['deadlines'][$raceCode] ?? null;
        return OfficialOmuraDeadlineCache::isValidTime($time) ? $time : null;
    }

    private function now(): DateTimeImmutable
    {
        if ($this->clock !== null) return ($this->clock)();
        return new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
    }
}
