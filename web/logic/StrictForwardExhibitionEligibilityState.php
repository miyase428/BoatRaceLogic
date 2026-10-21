<?php

declare(strict_types=1);

require_once __DIR__ . '/../../logic/exhibition_data_quality.php';

/**
 * Single, cutoff-safe exhibition completeness predicate shared by the
 * collector and the late eligibility observer.  It is read-only: callers
 * decide whether and how to write opportunity evidence.
 */
final class StrictForwardExhibitionEligibilityState
{
    /** @return array{complete:bool,complete_boats:int,recorded_at:string} */
    public static function forRace(PDO $pdo, string $raceCode, string $venue, string $cutoff): array
    {
        $straight = exhibitionRequiresStraight($venue) ? 'AND straight_time IS NOT NULL AND straight_time > 0' : '';
        $sql = <<<SQL
SELECT
  COUNT(DISTINCT entry_course) FILTER (
    WHERE entry_course BETWEEN 1 AND 6
      AND player_id IS NOT NULL
      AND exhibition_time IS NOT NULL AND exhibition_time > 0
      AND start_timing IS NOT NULL
      AND lap_time IS NOT NULL AND lap_time > 0
      AND around_time IS NOT NULL AND around_time > 0
      {$straight}
      AND created_date < CAST(:cutoff AS timestamptz)
  ) AS complete_boats,
  MAX(created_date) AS recorded_at
FROM boat_race.exhibition_live
WHERE race_code = :race_code
SQL;
        $statement = $pdo->prepare($sql);
        $statement->execute([':race_code' => $raceCode, ':cutoff' => $cutoff]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        $boats = (int)($row['complete_boats'] ?? 0);
        return ['complete' => $boats === 6, 'complete_boats' => $boats, 'recorded_at' => (string)($row['recorded_at'] ?? '')];
    }
}
