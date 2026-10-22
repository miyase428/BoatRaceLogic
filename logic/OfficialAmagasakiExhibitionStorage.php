<?php

declare(strict_types=1);

/**
 * Pure conversion and transactional-persistence boundary for official AMG
 * exhibition payloads.  This is intentionally not wired into production
 * endpoints; a real repository may only be introduced after the audit
 * migration and operator approval have been completed.
 */
final class OfficialAmagasakiExhibitionValidator
{
    /** @param list<array<string,mixed>> $raceEntries @return list<array<string,mixed>> */
    public function validateAndConvert(array $payload, string $raceCode, array $raceEntries): array
    {
        if (($payload['schema'] ?? null) !== 'official-exhibition/v1' || ($payload['status'] ?? null) !== 'published'
            || ($payload['source'] ?? null) !== 'official_amagasaki') {
            throw new InvalidArgumentException('official payload schema, status, or source is invalid');
        }
        if (!preg_match('/^(\d{8})AMG(0[1-9]|1[0-2])$/', $raceCode, $parts)
            || ($payload['race_code'] ?? null) !== $raceCode
            || ($payload['race_date'] ?? null) !== $parts[1]
            || (int)($payload['race_no'] ?? 0) !== (int)$parts[2]) {
            throw new InvalidArgumentException('official payload race identity mismatch');
        }
        $fetchedAt = $this->utcTimestamp((string)($payload['fetched_at'] ?? ''), 'fetched_at');
        $pageTimes = $payload['page_fetched_at'] ?? null;
        if (!is_array($pageTimes) || array_keys($pageTimes) !== ['direct_info', 'start_display', 'original_data']) {
            throw new InvalidArgumentException('page_fetched_at is incomplete');
        }
        foreach ($pageTimes as $name => $value) {
            if ($this->utcTimestamp((string)$value, 'page_fetched_at.' . $name) > $fetchedAt) {
                throw new InvalidArgumentException('fetched_at precedes page completion');
            }
        }

        $expected = [];
        foreach ($raceEntries as $entry) {
            $lane = (int)($entry['lane_number'] ?? 0);
            $playerId = (string)($entry['player_id'] ?? '');
            if ($lane < 1 || $lane > 6 || !preg_match('/^\d{4,6}$/', $playerId) || isset($expected[$lane])) {
                throw new InvalidArgumentException('race_entry contract is invalid');
            }
            $expected[$lane] = $playerId;
        }
        if (count($expected) !== 6) throw new InvalidArgumentException('race_entry does not contain six lanes');

        $boats = $payload['boats'] ?? null;
        if (!is_array($boats) || count($boats) !== 6) throw new InvalidArgumentException('official payload does not contain six boats');
        $lanes = [];
        $courses = [];
        $players = [];
        $converted = [];
        foreach ($boats as $boat) {
            if (!is_array($boat)) throw new InvalidArgumentException('invalid boat row');
            $lane = (int)($boat['lane'] ?? 0);
            $course = (int)($boat['entry_course'] ?? 0);
            $playerId = (string)($boat['player_id'] ?? '');
            if ($lane < 1 || $lane > 6 || $course < 1 || $course > 6 || isset($lanes[$lane]) || isset($courses[$course])
                || isset($players[$playerId]) || !isset($expected[$lane]) || $expected[$lane] !== $playerId) {
                throw new InvalidArgumentException('boat, player, lane, or entry-course identity mismatch');
            }
            foreach (['exhibition_time', 'lap_time', 'around_time'] as $field) {
                if (!preg_match('/^\d+\.\d{2}$/', (string)($boat[$field] ?? '')) || (float)$boat[$field] <= 0.0) {
                    throw new InvalidArgumentException($field . ' is invalid');
                }
            }
            if (($boat['straight_time'] ?? null) !== null) throw new InvalidArgumentException('AMG straight_time must remain null');
            $rawSt = (string)($boat['start_timing'] ?? '');
            if (!preg_match('/^(?:F|L)?(?:\d+\.\d{2}|\.\d{2})$/', $rawSt)) {
                throw new InvalidArgumentException('start_timing is invalid');
            }
            $lanes[$lane] = true;
            $courses[$course] = true;
            $players[$playerId] = true;
            $converted[] = [
                'race_code' => $raceCode,
                'lane' => $lane,
                'entry_course' => $course,
                'player_id' => $playerId,
                'exhibition_time' => (float)$boat['exhibition_time'],
                'start_timing' => $this->startTiming($rawSt),
                'start_timing_raw' => $rawSt,
                'lap_time' => (float)$boat['lap_time'],
                'around_time' => (float)$boat['around_time'],
                'straight_time' => null,
            ];
        }
        if (count($lanes) !== 6 || count($courses) !== 6 || count($players) !== 6) throw new InvalidArgumentException('boat set is incomplete');
        usort($converted, static fn(array $a, array $b): int => $a['entry_course'] <=> $b['entry_course']);
        return $converted;
    }

    public function isForwardEligible(array $payload, string $cutoff): bool
    {
        try {
            return $this->utcTimestamp((string)($payload['fetched_at'] ?? ''), 'fetched_at')
                <= $this->utcTimestamp($cutoff, 'prediction_cutoff');
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private function startTiming(string $raw): float
    {
        if (str_starts_with($raw, 'F')) return -1.0 * (float)substr($raw, 1);
        if (str_starts_with($raw, 'L')) return (float)substr($raw, 1);
        return (float)$raw;
    }

    private function utcTimestamp(string $value, string $field): DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{3})?Z$/', $value)) {
            throw new InvalidArgumentException($field . ' must be a UTC ISO-8601 timestamp');
        }
        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            throw new InvalidArgumentException($field . ' is invalid');
        }
    }
}

interface OfficialExhibitionRepository
{
    /** @return list<array<string,mixed>> */
    public function existingRows(string $raceCode): array;
    public function hasOfficialAudit(string $raceCode): bool;
    /** @return array{avg_exh:float,avg_lap:float,avg_around:float,avg_straight:float} */
    public function averages(string $placeCode): array;
    public function begin(): void;
    /** @param list<array<string,mixed>> $rows */
    public function insertSix(array $rows): void;
    /** @param array<string,mixed> $audit */
    public function insertAudit(array $audit): void;
    public function commit(): void;
    public function rollback(): void;
}

/**
 * Concrete PDO adapter for a future, explicitly approved integration.  Merely
 * loading this file does not construct it or contact a database.  It uses no
 * UPSERT: a concurrent or pre-existing row turns into a transaction failure,
 * leaving manual review rather than an unaudited overwrite.
 */
final class PdoOfficialExhibitionRepository implements OfficialExhibitionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function existingRows(string $raceCode): array
    {
        $statement = $this->pdo->prepare('SELECT race_code, entry_course, player_id, exhibition_time, start_timing, lap_time, around_time, straight_time FROM boat_race.exhibition_live WHERE race_code = :race_code ORDER BY entry_course FOR UPDATE');
        $statement->execute([':race_code' => $raceCode]);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function hasOfficialAudit(string $raceCode): bool
    {
        $statement = $this->pdo->prepare("SELECT 1 FROM boat_race.exhibition_source_audit_v1 WHERE race_code = :race_code AND source = 'official_amagasaki' AND result_status = 'saved' LIMIT 1");
        $statement->execute([':race_code' => $raceCode]);
        return $statement->fetchColumn() !== false;
    }

    public function averages(string $placeCode): array
    {
        $statement = $this->pdo->prepare('SELECT AVG(exhibition_time) AS avg_exh, AVG(lap_time) AS avg_lap, AVG(around_time) AS avg_around, AVG(straight_time) AS avg_straight FROM boat_race.exhibition_live WHERE race_code LIKE :place_prefix');
        $statement->execute([':place_prefix' => '%' . $placeCode . '%']);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['avg_exh' => (float)($row['avg_exh'] ?? 0), 'avg_lap' => (float)($row['avg_lap'] ?? 0), 'avg_around' => (float)($row['avg_around'] ?? 0), 'avg_straight' => (float)($row['avg_straight'] ?? 0)];
    }

    public function begin(): void { $this->pdo->beginTransaction(); }

    public function insertSix(array $rows): void
    {
        if (count($rows) !== 6) throw new RuntimeException('six rows are required');
        $statement = $this->pdo->prepare('INSERT INTO boat_race.exhibition_live (race_code, entry_course, player_id, exhibition_time, start_timing, lap_time, around_time, straight_time, exhibition_score, exhibition_type, created_date) VALUES (:race_code, :entry_course, :player_id, :exhibition_time, :start_timing, :lap_time, :around_time, :straight_time, :exhibition_score, :exhibition_type, NOW())');
        foreach ($rows as $row) {
            $statement->execute([
                ':race_code' => $row['race_code'], ':entry_course' => $row['entry_course'], ':player_id' => $row['player_id'],
                ':exhibition_time' => $row['exhibition_time'], ':start_timing' => $row['start_timing'], ':lap_time' => $row['lap_time'],
                ':around_time' => $row['around_time'], ':straight_time' => $row['straight_time'], ':exhibition_score' => $row['exhibition_score'], ':exhibition_type' => $row['exhibition_type'],
            ]);
        }
    }

    public function insertAudit(array $audit): void
    {
        $statement = $this->pdo->prepare('INSERT INTO boat_race.exhibition_source_audit_v1 (audit_id, race_code, source, source_url, fetched_at, page_fetched_at, before_rows, after_rows, comparison_status, result_status, error_reason) VALUES (:audit_id, :race_code, :source, :source_url, :fetched_at, CAST(:page_fetched_at AS jsonb), CAST(:before_rows AS jsonb), CAST(:after_rows AS jsonb), :comparison_status, :result_status, :error_reason)');
        $statement->execute([
            ':audit_id' => $audit['audit_id'], ':race_code' => $audit['race_code'], ':source' => $audit['source'], ':source_url' => $audit['source_url'], ':fetched_at' => $audit['fetched_at'],
            ':page_fetched_at' => json_encode($audit['page_fetched_at'], JSON_THROW_ON_ERROR), ':before_rows' => json_encode($audit['before_rows'], JSON_THROW_ON_ERROR),
            ':after_rows' => json_encode($audit['after_rows'], JSON_THROW_ON_ERROR), ':comparison_status' => $audit['comparison_status'], ':result_status' => $audit['result_status'], ':error_reason' => $audit['error_reason'],
        ]);
    }

    public function commit(): void { $this->pdo->commit(); }
    public function rollback(): void { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); }
}

final class OfficialAmagasakiExhibitionTransactionStore
{
    public function __construct(private readonly OfficialExhibitionRepository $repository, private readonly OfficialAmagasakiExhibitionValidator $validator)
    {
    }

    /** @param array<string,mixed> $payload @param list<array<string,mixed>> $raceEntries @return array{status:string,comparison:string} */
    public function persist(array $payload, string $raceCode, array $raceEntries): array
    {
        return $this->persistWithAuditMarker($payload, $raceCode, $raceEntries, 'none', null);
    }

    /** @param array<string,mixed> $payload @param list<array<string,mixed>> $raceEntries @return array{status:string,comparison:string} */
    public function persistLateHistory(array $payload, string $raceCode, array $raceEntries): array
    {
        return $this->persistWithAuditMarker($payload, $raceCode, $raceEntries, 'late_manual_history_import', 'POST_DEADLINE_MANUAL_IMPORT');
    }

    /** Explicit user-requested fetch; its audit distinguishes pre/post deadline collection. */
    public function persistManualWithinOpenTransaction(array $payload, string $raceCode, array $raceEntries, bool $afterDeadline): array
    {
        return $this->persistWithinOpenTransactionWithAuditMarker(
            $payload,
            $raceCode,
            $raceEntries,
            $afterDeadline ? 'manual_official_postdeadline' : 'manual_official_predeadline',
            $afterDeadline ? 'POST_DEADLINE_MANUAL_FETCH' : null,
        );
    }

    /** @param array<string,mixed> $payload @param list<array<string,mixed>> $raceEntries @return array{status:string,comparison:string} */
    private function persistWithAuditMarker(array $payload, string $raceCode, array $raceEntries, string $comparisonStatus, ?string $errorReason): array
    {
        $begun = false;
        try {
            $this->repository->begin();
            $begun = true;
            $result = $this->persistWithinOpenTransactionWithAuditMarker($payload, $raceCode, $raceEntries, $comparisonStatus, $errorReason);
            if ($result['status'] !== 'saved') {
                $this->repository->rollback();
                $begun = false;
                return $result;
            }
            $this->repository->commit();
            return $result;
        } catch (Throwable) {
            if ($begun) $this->repository->rollback();
            return ['status' => 'rolled_back', 'comparison' => 'none'];
        }
    }

    /**
     * Performs validation and writes only while a caller-owned transaction is
     * open.  It lets the daytime integration commit the six rows, audit, and
     * claimed attempt completion as one database transaction.
     *
     * @param array<string,mixed> $payload @param list<array<string,mixed>> $raceEntries
     * @return array{status:string,comparison:string}
     */
    public function persistWithinOpenTransaction(array $payload, string $raceCode, array $raceEntries): array
    {
        return $this->persistWithinOpenTransactionWithAuditMarker($payload, $raceCode, $raceEntries, 'none', null);
    }

    /**
     * Stores a verified historical payload after the race deadline.  The
     * caller owns the transaction and must separately prove the late-import
     * conditions; the audit marker prevents this path from being described as
     * a normal forward collection.
     *
     * @param array<string,mixed> $payload @param list<array<string,mixed>> $raceEntries
     * @return array{status:string,comparison:string}
     */
    public function persistLateHistoryWithinOpenTransaction(array $payload, string $raceCode, array $raceEntries): array
    {
        return $this->persistWithinOpenTransactionWithAuditMarker($payload, $raceCode, $raceEntries, 'late_manual_history_import', 'POST_DEADLINE_MANUAL_IMPORT');
    }

    /** @param array<string,mixed> $payload @param list<array<string,mixed>> $raceEntries @return array{status:string,comparison:string} */
    private function persistWithinOpenTransactionWithAuditMarker(array $payload, string $raceCode, array $raceEntries, string $comparisonStatus, ?string $errorReason): array
    {
        $rows = $this->validator->validateAndConvert($payload, $raceCode, $raceEntries);
        $existing = $this->repository->existingRows($raceCode);
        $comparison = $this->comparison($existing, $rows);
        if ($comparison !== 'none') {
            if (count($existing) !== 6) return ['status' => 'requires_review_partial', 'comparison' => $comparison];
            if (!$this->repository->hasOfficialAudit($raceCode)) return ['status' => 'requires_review_source_unknown', 'comparison' => $comparison];
            return ['status' => $comparison === 'exact' ? 'already_official' : 'requires_review_mismatch', 'comparison' => $comparison];
        }
        $averages = $this->repository->averages('AMG');
        foreach ($rows as &$row) {
            [$row['exhibition_score'], $row['exhibition_type']] = $this->score($averages, $row);
        }
        unset($row);
        $audit = [
            'audit_id' => bin2hex(random_bytes(16)), 'race_code' => $raceCode, 'source' => 'official_amagasaki',
            'source_url' => (string)$payload['source_url'], 'fetched_at' => (string)$payload['fetched_at'], 'page_fetched_at' => $payload['page_fetched_at'],
            'before_rows' => [], 'after_rows' => $rows, 'comparison_status' => $comparisonStatus, 'result_status' => 'saved', 'error_reason' => $errorReason,
        ];
        $this->repository->insertSix($rows);
        $this->repository->insertAudit($audit);
        return ['status' => 'saved', 'comparison' => 'none'];
    }

    /** @param list<array<string,mixed>> $existing @param list<array<string,mixed>> $rows */
    private function comparison(array $existing, array $rows): string
    {
        if ($existing === []) return 'none';
        if (count($existing) !== 6) return 'partial';
        $byCourse = [];
        foreach ($existing as $row) $byCourse[(int)($row['entry_course'] ?? 0)] = $row;
        if (count($byCourse) !== 6) return 'partial';
        foreach ($rows as $row) {
            $old = $byCourse[(int)$row['entry_course']] ?? null;
            if (!is_array($old) || (string)($old['player_id'] ?? '') !== $row['player_id']) return 'mismatch';
            foreach (['exhibition_time', 'start_timing', 'lap_time', 'around_time'] as $field) {
                if (!isset($old[$field]) || abs((float)$old[$field] - (float)$row[$field]) > 0.00001) return 'mismatch';
            }
            if (($old['straight_time'] ?? null) !== null) return 'mismatch';
        }
        return 'exact';
    }

    /** @param array<string,float> $avg @param array<string,mixed> $row @return array{0:float,1:string} */
    private function score(array $avg, array $row): array
    {
        $straight = 0.0; // Matches existing AMG calculation while retaining null in storage.
        $diffStraight = $avg['avg_straight'] - $straight;
        $diffAround = $avg['avg_around'] - (float)$row['around_time'];
        $diffLap = $avg['avg_lap'] - (float)$row['lap_time'];
        $diffExh = $avg['avg_exh'] - (float)$row['exhibition_time'];
        $score = $diffStraight * 0.4 + $diffAround * 0.3 + $diffLap * 0.2 + $diffExh * 0.1;
        $type = $diffStraight > 0.10 ? '伸び型' : ($diffAround > 0.10 ? '差し型' : 'バランス');
        return [$score, $type];
    }
}
