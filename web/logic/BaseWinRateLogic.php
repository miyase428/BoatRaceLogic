<?php

class BaseWinRateLogic
{
    private const K_PC = 20.0;
    private const K_PVC = 10.0;

    public function calculate(string $raceCode, array $courseByBoat = []): array
    {
        try {
            $pdo = getPDO();

            [$targetDate, $placeCode, $stadiumName, $boats] = $this->loadTarget($pdo, $raceCode);

            if ($courseByBoat !== []) {
                $this->assertCourseMap($courseByBoat);
                foreach ($boats as &$boat) {
                    $lane = (int)$boat['lane'];
                    $boat['course'] = (int)$courseByBoat[$lane];
                }
                unset($boat);
            }

            $venue = $this->loadVenueCoursePrior($pdo, $raceCode, $targetDate, $placeCode);

            $results = [];
            foreach ($boats as $boat) {
                $history = $this->loadLast100($pdo, $boat['player_id'], $targetDate, $raceCode);
                $counts = $this->playerCounts($history, $boat['course'], $placeCode);

                $p0 = $venue[$boat['course']]['rate'];
                $pPc = ($counts['pc_w'] + self::K_PC * $p0) / ($counts['pc_n'] + self::K_PC);
                $pFinal = ($counts['pvc_w'] + self::K_PVC * $pPc) / ($counts['pvc_n'] + self::K_PVC);

                $results[$boat['lane']] = [
                    ...$boat,
                    ...$counts,
                    'venue_n' => $venue[$boat['course']]['n'],
                    'venue_w' => $venue[$boat['course']]['wins'],
                    'p0' => $p0,
                    'p_pc' => $pPc,
                    'p_final' => $pFinal,
                ];
            }

            $total = array_sum(array_column($results, 'p_final'));
            if ($total <= 0) {
                throw new RuntimeException('基礎1着率の6艇合計が0以下です');
            }

            foreach ($results as $lane => $row) {
                $results[$lane]['p_normalized'] = $row['p_final'] / $total;
                $results[$lane]['normalized_rate'] = ($row['p_final'] / $total) * 100.0;
            }

            ksort($results);

            return [
                'boats' => $results,
                'raw_total' => $total,
                'normalized_total' => array_sum(array_column($results, 'p_normalized')),
                'method' => 'BB_MEDIUM',
                'k_pc' => self::K_PC,
                'k_pvc' => self::K_PVC,
                'target_date' => $targetDate,
                'place_code' => $placeCode,
                'stadium_name' => $stadiumName,
                'virtual_entry' => $courseByBoat !== [],
                'error' => '',
            ];
        } catch (Throwable $e) {
            return [
                'boats' => [],
                'raw_total' => 0.0,
                'normalized_total' => 0.0,
                'method' => 'BB_MEDIUM',
                'k_pc' => self::K_PC,
                'k_pvc' => self::K_PVC,
                'virtual_entry' => $courseByBoat !== [],
                'error' => $e->getMessage(),
            ];
        }
    }

    private function assertCourseMap(array $courseByBoat): void
    {
        if (count($courseByBoat) !== 6) {
            throw new RuntimeException('基本1着率: 仮想進入が6艇分ではありません');
        }

        $courses = [];
        for ($boat = 1; $boat <= 6; $boat++) {
            $course = $this->validCourse($courseByBoat[$boat] ?? null);
            if ($course === null) {
                throw new RuntimeException('基本1着率: 仮想進入に不正なコースがあります');
            }
            $courses[] = $course;
        }

        sort($courses);
        if ($courses !== [1, 2, 3, 4, 5, 6]) {
            throw new RuntimeException('基本1着率: 仮想進入は1～6コースを1回ずつ指定してください');
        }
    }

    private function loadTarget(PDO $pdo, string $raceCode): array
    {
        $sql = <<<SQL
            SELECT
                COALESCE(
                    rm.race_date,
                    TO_DATE(SUBSTRING(re.race_code, 1, 8), 'YYYYMMDD')
                ) AS race_date,
                rm.stadium_name,
                re.lane_number,
                re.player_id::text AS player_id,
                re.player_name
            FROM boat_race.race_entry re
            LEFT JOIN boat_race.race_master rm
              ON rm.race_code = re.race_code
            WHERE re.race_code = ?
            ORDER BY re.lane_number
        SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$raceCode]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) !== 6) {
            throw new RuntimeException('基本1着率: 対象レースの出走艇が6艇ではありません');
        }

        $targetDate = (string)$rows[0]['race_date'];
        $stadiumName = trim((string)($rows[0]['stadium_name'] ?? ''));
        $placeCode = strlen($raceCode) >= 11 ? substr($raceCode, 8, 3) : '???';

        $boats = [];
        foreach ($rows as $row) {
            $lane = $this->validCourse($row['lane_number'] ?? null);
            if ($lane === null) {
                throw new RuntimeException('基本1着率: 不正なlane_numberがあります');
            }

            $boats[] = [
                'lane' => $lane,
                'course' => $lane,
                'player_id' => trim((string)($row['player_id'] ?? '')),
                'player_name' => trim((string)($row['player_name'] ?? '')),
            ];
        }

        return [$targetDate, $placeCode, $stadiumName, $boats];
    }

    private function loadVenueCoursePrior(PDO $pdo, string $raceCode, string $targetDate, string $placeCode): array
    {
        // race_code は YYYYMMDD+場3文字+R2桁の固定形式なので、
        // 旧「過去日 OR 同日でrace_codeが小さい」と race_code < target は等価。
        $sql = <<<SQL
            WITH winner_rows AS (
                SELECT
                    rrd.race_code,
                    COUNT(*) AS winner_count,
                    MIN(
                        CASE
                            WHEN rrd.entry_course::text ~ '^[1-6]$'
                            THEN rrd.entry_course::int
                            ELSE NULL
                        END
                    ) AS winner_course
                FROM boat_race.race_result_detail rrd
                WHERE rrd.rank = '1'
                  AND SUBSTRING(rrd.race_code, 9, 3) = ?
                  AND rrd.race_code < ?
                GROUP BY rrd.race_code
            )
            SELECT winner_course, COUNT(*) AS wins
            FROM winner_rows
            WHERE winner_count = 1
              AND winner_course BETWEEN 1 AND 6
            GROUP BY winner_course
            ORDER BY winner_course
        SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$placeCode, $raceCode]);

        $winsByCourse = array_fill(1, 6, 0);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $course = $this->validCourse($row['winner_course'] ?? null);
            if ($course !== null) {
                $winsByCourse[$course] = (int)$row['wins'];
            }
        }

        $total = array_sum($winsByCourse);
        if ($total <= 0) {
            throw new RuntimeException("基本1着率: {$placeCode} の対象レース以前の場×コース履歴がありません");
        }

        $out = [];
        for ($course = 1; $course <= 6; $course++) {
            $out[$course] = [
                'n' => $total,
                'wins' => $winsByCourse[$course],
                'rate' => $winsByCourse[$course] / $total,
            ];
        }

        return $out;
    }

    private function loadLast100(PDO $pdo, string $playerId, string $targetDate, string $targetRaceCode): array
    {
        // race_master JOINと日付ソートを外し、player_id×race_code indexを直接使う。
        // 固定race_code形式のため旧条件・並び順と完全一致する。
        $sql = <<<SQL
            SELECT
                re.race_code,
                re.lane_number,
                rrd.rank,
                rrd.entry_course AS result_course,
                el.entry_course AS exhibition_course
            FROM boat_race.race_entry re
            LEFT JOIN boat_race.race_result_detail rrd
              ON rrd.race_code = re.race_code
             AND rrd.player_id = re.player_id
            LEFT JOIN LATERAL (
                SELECT entry_course
                FROM boat_race.exhibition_live x
                WHERE x.race_code = re.race_code
                  AND x.player_id = re.player_id
                LIMIT 1
            ) el ON TRUE
            WHERE re.player_id::text = ?
              AND re.race_code < ?
            ORDER BY re.race_code DESC
            LIMIT 100
        SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$playerId, $targetRaceCode]);

        $history = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $resultCourse = $this->validCourse($row['result_course'] ?? null);
            $exhibitionCourse = $this->validCourse($row['exhibition_course'] ?? null);
            $laneCourse = $this->validCourse($row['lane_number'] ?? null);

            $course = $resultCourse ?? $exhibitionCourse ?? $laneCourse;
            if ($course === null) {
                continue;
            }

            $code = (string)$row['race_code'];
            $history[] = [
                'place' => strlen($code) >= 11 ? substr($code, 8, 3) : '???',
                'course' => $course,
                'win' => ((int)($row['rank'] ?? 0) === 1) ? 1 : 0,
            ];
        }

        return $history;
    }

    private function playerCounts(array $history, int $targetCourse, string $placeCode): array
    {
        $pcN = 0;
        $pcW = 0;
        $pvcN = 0;
        $pvcW = 0;

        foreach ($history as $row) {
            if ($row['course'] !== $targetCourse) {
                continue;
            }

            $pcN++;
            $pcW += $row['win'];

            if ($row['place'] === $placeCode) {
                $pvcN++;
                $pvcW += $row['win'];
            }
        }

        return [
            'history_n' => count($history),
            'pc_n' => $pcN,
            'pc_w' => $pcW,
            'pvc_n' => $pvcN,
            'pvc_w' => $pvcW,
        ];
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
