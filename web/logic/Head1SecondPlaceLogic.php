<?php

class Head1SecondPlaceLogic
{
    private const K_PC = 10.0;
    private const HISTORY_DAYS = 730;

    public function calculate(string $raceCode, array $courseByBoat = []): array
    {
        try {
            [$targetDate, $placeCode] = $this->parseRaceCode($raceCode);
            $pdo = getPDO();

            $boats = $this->loadTarget($pdo, $raceCode);

            if ($courseByBoat !== []) {
                $this->assertCourseMap($courseByBoat);
                foreach ($boats as &$boat) {
                    $boat['course'] = (int)$courseByBoat[$boat['lane']];
                }
                unset($boat);
            }

            $historyStart = (new DateTimeImmutable($targetDate))->modify('-' . self::HISTORY_DAYS . ' days')->format('Y-m-d');
            [$globalPrior, $globalN, $venuePrior, $venueN] = $this->loadCoursePriors(
                $pdo,
                $historyStart,
                $targetDate,
                $raceCode,
                $placeCode
            );

            if ($globalN <= 0) {
                throw new RuntimeException('基本2着率: 1号艇1着時の過去母集団がありません');
            }

            $results = [];
            $basicScores = [];
            $venueScores = [];

            foreach ($boats as $boat) {
                $lane = (int)$boat['lane'];
                $course = (int)$boat['course'];

                if ($lane === 1) {
                    $results[$lane] = [
                        ...$boat,
                        'p0' => null,
                        'pc_n' => 0,
                        'pc_w' => 0,
                        'pc_raw' => null,
                        'p_pc' => null,
                        'basic_rate' => null,
                        'venue_raw' => $venueN > 0 ? ($venuePrior[$course] ?? 0.0) : null,
                        'venue_rate' => null,
                    ];
                    continue;
                }

                $p0 = (float)($globalPrior[$course] ?? 0.0);
                $history = $this->loadLast100($pdo, $boat['player_id'], $historyStart, $targetDate, $raceCode);
                [$pcN, $pcW] = $this->playerCounts($history, $course);
                $pcRaw = $pcN > 0 ? $pcW / $pcN : null;
                $pPc = ($pcW + self::K_PC * $p0) / ($pcN + self::K_PC);
                $venueRaw = $venueN > 0 ? (float)($venuePrior[$course] ?? 0.0) : null;

                $results[$lane] = [
                    ...$boat,
                    'p0' => $p0,
                    'pc_n' => $pcN,
                    'pc_w' => $pcW,
                    'pc_raw' => $pcRaw,
                    'p_pc' => $pPc,
                    'basic_rate' => null,
                    'venue_raw' => $venueRaw,
                    'venue_rate' => null,
                ];

                $basicScores[$lane] = $pPc;
                if ($venueRaw !== null) {
                    $venueScores[$lane] = $venueRaw;
                }
            }

            $basicNormalized = $this->normalize($basicScores);
            foreach ($basicNormalized as $lane => $p) {
                $results[$lane]['basic_rate'] = $p * 100.0;
            }

            if ($venueN > 0) {
                $venueNormalized = $this->normalize($venueScores);
                foreach ($venueNormalized as $lane => $p) {
                    $results[$lane]['venue_rate'] = $p * 100.0;
                }
            }

            ksort($results);

            return [
                'status' => 'ok',
                'boats' => $results,
                'target_date' => $targetDate,
                'place_code' => $placeCode,
                'history_start' => $historyStart,
                'global_n' => $globalN,
                'venue_n' => $venueN,
                'global_course_prior' => $globalPrior,
                'venue_course_prior' => $venuePrior,
                'k_pc' => self::K_PC,
                'virtual_entry' => $courseByBoat !== [],
                'warning' => ((int)($boats[0]['course'] ?? 1) !== 1)
                    ? '1号艇が1Cではないため、過去母集団との比較は参考値です'
                    : '',
                'error' => '',
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'error',
                'boats' => [],
                'global_n' => 0,
                'venue_n' => 0,
                'k_pc' => self::K_PC,
                'virtual_entry' => $courseByBoat !== [],
                'warning' => '',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function parseRaceCode(string $raceCode): array
    {
        $raceCode = strtoupper(trim($raceCode));
        if (!preg_match('/^(\d{8})([A-Z0-9]{3})(\d{2})$/', $raceCode, $m)) {
            throw new RuntimeException('基本2着率: race_codeの形式が不正です');
        }

        $date = DateTimeImmutable::createFromFormat('!Ymd', $m[1]);
        if (!$date || $date->format('Ymd') !== $m[1]) {
            throw new RuntimeException('基本2着率: race_codeの日付が不正です');
        }

        return [$date->format('Y-m-d'), $m[2]];
    }

    private function assertCourseMap(array $courseByBoat): void
    {
        if (count($courseByBoat) !== 6) {
            throw new RuntimeException('基本2着率: 進入が6艇分ではありません');
        }

        $courses = [];
        for ($boat = 1; $boat <= 6; $boat++) {
            $course = $this->validCourse($courseByBoat[$boat] ?? null);
            if ($course === null) {
                throw new RuntimeException('基本2着率: 進入に不正なコースがあります');
            }
            $courses[] = $course;
        }

        sort($courses);
        if ($courses !== [1, 2, 3, 4, 5, 6]) {
            throw new RuntimeException('基本2着率: 進入は1～6コースを1回ずつ指定してください');
        }
    }

    private function loadTarget(PDO $pdo, string $raceCode): array
    {
        $sql = <<<SQL
            SELECT
                re.lane_number,
                re.player_id::text AS player_id,
                re.player_name
            FROM boat_race.race_entry re
            WHERE re.race_code = ?
            ORDER BY re.lane_number
        SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$raceCode]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) !== 6) {
            throw new RuntimeException('基本2着率: 対象レースの出走艇が6艇ではありません');
        }

        $boats = [];
        $lanes = [];
        foreach ($rows as $row) {
            $lane = $this->validCourse($row['lane_number'] ?? null);
            if ($lane === null) {
                throw new RuntimeException('基本2着率: 不正なlane_numberがあります');
            }
            $lanes[] = $lane;
            $boats[] = [
                'lane' => $lane,
                'course' => $lane,
                'player_id' => trim((string)($row['player_id'] ?? '')),
                'player_name' => trim((string)($row['player_name'] ?? '')),
            ];
        }

        sort($lanes);
        if ($lanes !== [1, 2, 3, 4, 5, 6]) {
            throw new RuntimeException('基本2着率: lane_numberが1～6で揃っていません');
        }

        return $boats;
    }

    private function loadCoursePriors(
        PDO $pdo,
        string $historyStart,
        string $targetDate,
        string $targetRaceCode,
        string $placeCode
    ): array {
        $sql = <<<SQL
            WITH race_rows AS (
                SELECT
                    re.race_code,
                    SUBSTRING(re.race_code, 9, 3) AS place_code,
                    re.lane_number::int AS lane,
                    rrd.rank,
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
                WHERE rm.race_date >= ?::date
                  AND (
                        rm.race_date < ?::date
                        OR (rm.race_date = ?::date AND re.race_code < ?)
                      )
            ),
            eligible AS (
                SELECT
                    race_code,
                    place_code,
                    COUNT(*) AS row_n,
                    COUNT(DISTINCT lane) AS lane_n,
                    MIN(lane) AS min_lane,
                    MAX(lane) AS max_lane,
                    COUNT(*) FILTER (WHERE rank = '1') AS rank1_n,
                    COUNT(*) FILTER (WHERE rank = '2') AS rank2_n,
                    MIN(lane) FILTER (WHERE rank = '1') AS winner_lane,
                    MIN(actual_course) FILTER (WHERE rank = '2') AS second_course,
                    COUNT(actual_course) AS course_n,
                    COUNT(DISTINCT actual_course) AS course_distinct_n,
                    MIN(actual_course) AS min_course,
                    MAX(actual_course) AS max_course
                FROM race_rows
                GROUP BY race_code, place_code
            )
            SELECT
                place_code,
                second_course,
                COUNT(*) AS race_n
            FROM eligible
            WHERE row_n = 6
              AND lane_n = 6
              AND min_lane = 1
              AND max_lane = 6
              AND rank1_n = 1
              AND rank2_n = 1
              AND winner_lane = 1
              AND course_n = 6
              AND course_distinct_n = 6
              AND min_course = 1
              AND max_course = 6
              AND second_course BETWEEN 1 AND 6
            GROUP BY place_code, second_course
            ORDER BY place_code, second_course
        SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$historyStart, $targetDate, $targetDate, $targetRaceCode]);

        $globalCounts = array_fill(1, 6, 0);
        $venueCounts = array_fill(1, 6, 0);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $course = $this->validCourse($row['second_course'] ?? null);
            if ($course === null) {
                continue;
            }

            $n = (int)($row['race_n'] ?? 0);
            $globalCounts[$course] += $n;
            if ((string)($row['place_code'] ?? '') === $placeCode) {
                $venueCounts[$course] += $n;
            }
        }

        $globalN = array_sum($globalCounts);
        $venueN = array_sum($venueCounts);
        $globalPrior = [];
        $venuePrior = [];

        for ($course = 1; $course <= 6; $course++) {
            $globalPrior[$course] = $globalN > 0 ? $globalCounts[$course] / $globalN : 0.0;
            $venuePrior[$course] = $venueN > 0 ? $venueCounts[$course] / $venueN : 0.0;
        }

        return [$globalPrior, $globalN, $venuePrior, $venueN];
    }

    private function loadLast100(
        PDO $pdo,
        string $playerId,
        string $historyStart,
        string $targetDate,
        string $targetRaceCode
    ): array {
        if ($playerId === '') {
            return [];
        }

        $sql = <<<SQL
            WITH recent AS (
                SELECT
                    rm.race_date,
                    re.race_code,
                    re.lane_number::int AS lane,
                    rrd.rank,
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
                  AND rm.race_date >= ?::date
                  AND (
                        rm.race_date < ?::date
                        OR (rm.race_date = ?::date AND re.race_code < ?)
                      )
                ORDER BY rm.race_date DESC, re.race_code DESC
                LIMIT 100
            )
            SELECT
                r.race_code,
                r.rank,
                r.actual_course,
                stats.row_n,
                stats.lane_n,
                stats.min_lane,
                stats.max_lane,
                stats.rank1_n,
                stats.rank2_n,
                stats.winner_lane
            FROM recent r
            LEFT JOIN LATERAL (
                SELECT
                    COUNT(*) AS row_n,
                    COUNT(DISTINCT re2.lane_number) AS lane_n,
                    MIN(re2.lane_number::int) AS min_lane,
                    MAX(re2.lane_number::int) AS max_lane,
                    COUNT(*) FILTER (WHERE rrd2.rank = '1') AS rank1_n,
                    COUNT(*) FILTER (WHERE rrd2.rank = '2') AS rank2_n,
                    MIN(re2.lane_number::int) FILTER (WHERE rrd2.rank = '1') AS winner_lane
                FROM boat_race.race_entry re2
                LEFT JOIN boat_race.race_result_detail rrd2
                  ON rrd2.race_code = re2.race_code
                 AND rrd2.player_id = re2.player_id
                WHERE re2.race_code = r.race_code
            ) stats ON TRUE
            ORDER BY r.race_date DESC, r.race_code DESC
        SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$playerId, $historyStart, $targetDate, $targetDate, $targetRaceCode]);

        $history = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $course = $this->validCourse($row['actual_course'] ?? null);
            if ($course === null) {
                continue;
            }

            $eligible = (
                (int)($row['row_n'] ?? 0) === 6
                && (int)($row['lane_n'] ?? 0) === 6
                && (int)($row['min_lane'] ?? 0) === 1
                && (int)($row['max_lane'] ?? 0) === 6
                && (int)($row['rank1_n'] ?? 0) === 1
                && (int)($row['rank2_n'] ?? 0) === 1
                && (int)($row['winner_lane'] ?? 0) === 1
            );

            $history[] = [
                'course' => $course,
                'eligible_head1' => $eligible,
                'second' => $eligible && (string)($row['rank'] ?? '') === '2' ? 1 : 0,
            ];
        }

        return $history;
    }

    private function playerCounts(array $history, int $targetCourse): array
    {
        $n = 0;
        $w = 0;
        foreach ($history as $row) {
            if (empty($row['eligible_head1']) || (int)$row['course'] !== $targetCourse) {
                continue;
            }
            $n++;
            $w += (int)$row['second'];
        }
        return [$n, $w];
    }

    private function normalize(array $scores): array
    {
        if ($scores === []) {
            return [];
        }

        $total = 0.0;
        foreach ($scores as $score) {
            $total += max(0.0, (float)$score);
        }

        if ($total <= 0.0) {
            $uniform = 1.0 / count($scores);
            return array_fill_keys(array_keys($scores), $uniform);
        }

        $out = [];
        foreach ($scores as $key => $score) {
            $out[$key] = max(0.0, (float)$score) / $total;
        }
        return $out;
    }

    private function validCourse(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $course = (int)$value;
        return ($course >= 1 && $course <= 6) ? $course : null;
    }
}
