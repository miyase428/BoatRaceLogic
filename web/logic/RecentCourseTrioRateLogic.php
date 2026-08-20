<?php

/**
 * 最終予想テーブル表示用の直近3連対率。
 *
 * 予想ロジックに使っている従来の three_in_rate_6m / 3m には触れず、
 * 「その選手が今回の進入コースを走った時」に限定した値を表示用に計算する。
 *
 * - 基準日は対象レース日（CURRENT_DATEではない）
 * - 対象レース自身と、それ以降の結果は除外
 * - 過去実コース: result_detail.entry_course -> exhibition_live.entry_course -> 枠番
 * - 6ヶ月 / 3ヶ月をそれぞれ集計
 */
class RecentCourseTrioRateLogic
{
    public function calculate(string $raceCode, array $courseByBoat = []): array
    {
        try {
            if ($raceCode === '') {
                throw new RuntimeException('直近コース別3連対率: race_codeが空です');
            }

            $pdo = getPDO();
            [$targetDate, $boats] = $this->loadTarget($pdo, $raceCode);
            $courses = $this->normalizeCourses($courseByBoat);

            $result = [];
            foreach ($boats as $boat => $row) {
                $course = $courses[$boat] ?? $boat;
                $stats = $this->loadPlayerStats(
                    $pdo,
                    (string)$row['player_id'],
                    $course,
                    $targetDate,
                    $raceCode
                );

                $result[$boat] = [
                    'boat' => $boat,
                    'course' => $course,
                    'player_id' => (string)$row['player_id'],
                    'player_name' => (string)$row['player_name'],
                    'n6' => $stats['n6'],
                    'top3_6' => $stats['top3_6'],
                    'rate6_dec' => $stats['n6'] > 0 ? $stats['top3_6'] / $stats['n6'] : null,
                    'n3' => $stats['n3'],
                    'top3_3' => $stats['top3_3'],
                    'rate3_dec' => $stats['n3'] > 0 ? $stats['top3_3'] / $stats['n3'] : null,
                ];
            }

            ksort($result);

            return [
                'status' => 'ok',
                'boats' => $result,
                'target_date' => $targetDate,
                'error' => '',
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'error',
                'boats' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    private function loadTarget(PDO $pdo, string $raceCode): array
    {
        $sql = <<<SQL
            SELECT
                rm.race_date,
                re.lane_number,
                re.player_id::text AS player_id,
                re.player_name
            FROM boat_race.race_entry re
            JOIN boat_race.race_master rm
              ON rm.race_code = re.race_code
            WHERE re.race_code = ?
            ORDER BY re.lane_number
        SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$raceCode]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) !== 6) {
            throw new RuntimeException('直近コース別3連対率: 対象レースが6艇ではありません');
        }

        $targetDate = (string)$rows[0]['race_date'];
        $boats = [];
        foreach ($rows as $row) {
            $boat = (int)($row['lane_number'] ?? 0);
            if ($boat < 1 || $boat > 6) {
                throw new RuntimeException('直近コース別3連対率: 艇番が不正です');
            }
            $boats[$boat] = [
                'player_id' => (string)($row['player_id'] ?? ''),
                'player_name' => (string)($row['player_name'] ?? ''),
            ];
        }

        return [$targetDate, $boats];
    }

    private function normalizeCourses(array $courseByBoat): array
    {
        $out = [];
        $used = [];

        for ($boat = 1; $boat <= 6; $boat++) {
            $course = isset($courseByBoat[$boat]) && is_numeric($courseByBoat[$boat])
                ? (int)$courseByBoat[$boat]
                : $boat;

            if ($course < 1 || $course > 6 || isset($used[$course])) {
                return array_combine(range(1, 6), range(1, 6));
            }

            $out[$boat] = $course;
            $used[$course] = true;
        }

        return $out;
    }

    private function loadPlayerStats(
        PDO $pdo,
        string $playerId,
        int $targetCourse,
        string $targetDate,
        string $targetRaceCode
    ): array {
        $sql = <<<SQL
            WITH hist AS (
                SELECT
                    rm.race_date,
                    CASE
                        WHEN rrd.rank::text ~ '^[1-6]$' THEN rrd.rank::int
                        ELSE NULL
                    END AS rank_num,
                    COALESCE(
                        CASE
                            WHEN rrd.entry_course::text ~ '^[1-6]$' THEN rrd.entry_course::int
                            ELSE NULL
                        END,
                        CASE
                            WHEN el.entry_course::text ~ '^[1-6]$' THEN el.entry_course::int
                            ELSE NULL
                        END,
                        CASE
                            WHEN re.lane_number::text ~ '^[1-6]$' THEN re.lane_number::int
                            ELSE NULL
                        END
                    ) AS actual_course
                FROM boat_race.race_entry re
                JOIN boat_race.race_master rm
                  ON rm.race_code = re.race_code
                LEFT JOIN boat_race.race_result_detail rrd
                  ON rrd.race_code = re.race_code
                 AND rrd.player_id = re.player_id
                LEFT JOIN LATERAL (
                    SELECT x.entry_course
                    FROM boat_race.exhibition_live x
                    WHERE x.race_code = re.race_code
                      AND x.player_id = re.player_id
                    LIMIT 1
                ) el ON TRUE
                WHERE re.player_id::text = ?
                  AND (
                        rm.race_date < ?::date
                        OR (rm.race_date = ?::date AND re.race_code < ?)
                      )
                  AND rm.race_date >= ?::date - INTERVAL '6 months'
            )
            SELECT
                COUNT(*) FILTER (
                    WHERE actual_course = ?
                      AND rank_num BETWEEN 1 AND 6
                ) AS n6,
                COUNT(*) FILTER (
                    WHERE actual_course = ?
                      AND rank_num BETWEEN 1 AND 3
                ) AS top3_6,
                COUNT(*) FILTER (
                    WHERE actual_course = ?
                      AND rank_num BETWEEN 1 AND 6
                      AND race_date >= ?::date - INTERVAL '3 months'
                ) AS n3,
                COUNT(*) FILTER (
                    WHERE actual_course = ?
                      AND rank_num BETWEEN 1 AND 3
                      AND race_date >= ?::date - INTERVAL '3 months'
                ) AS top3_3
            FROM hist
        SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $playerId,
            $targetDate,
            $targetDate,
            $targetRaceCode,
            $targetDate,
            $targetCourse,
            $targetCourse,
            $targetCourse,
            $targetDate,
            $targetCourse,
            $targetDate,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'n6' => (int)($row['n6'] ?? 0),
            'top3_6' => (int)($row['top3_6'] ?? 0),
            'n3' => (int)($row['n3'] ?? 0),
            'top3_3' => (int)($row['top3_3'] ?? 0),
        ];
    }
}
