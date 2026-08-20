<?php

class AiTrioRateLogic
{
    private const K_PC = 20.0;
    private const K_PVC = 10.0;

    // STEP6 ENTRY_MODE:
    // P1 (2026-06-15～2026-07-14) で展示進入基準として再学習し、
    // P2 (2026-07-15～2026-08-14) 完全ホールドアウトでFRAME_MODEを上回った係数。
    private const BETA_INTERCEPT = 0.033713;
    private const BETA_BASE_LOGIT = 0.828225;
    private const BETA_PRIMARY_Z = 0.433483;
    private const BETA_SECONDARY_Z = 0.286814;

    public function calculate(
        string $raceCode,
        array $primaryResults,
        array $tenjiList,
        array $courseByBoat = [],
        bool $virtualEntry = false
    ): array {
        try {
            $primaryScores = $this->primaryScoreMap($primaryResults);
            if (count($primaryScores) !== 6) {
                throw new RuntimeException('AI3連対率: 一次評価が6艇分そろっていません');
            }

            $secondaryScores = $this->secondaryScoreMap($tenjiList);
            if (count($secondaryScores) !== 6) {
                return $this->emptyResult('waiting', '展示情報待ち（二次評価が6艇分必要です）');
            }

            if ($courseByBoat === []) {
                $courseByBoat = $this->courseMapFromTenji($tenjiList);
            }
            if (!$this->validCourseMap($courseByBoat)) {
                return $this->emptyResult('waiting', '展示進入待ち（1～6コースが6艇分必要です）');
            }

            [$primaryZ, $primaryMean, $primarySd, $primaryZeroSd] = $this->zScores($primaryScores);
            [$secondaryZ, $secondaryMean, $secondarySd, $secondaryZeroSd] = $this->zScores($secondaryScores);

            $pdo = getPDO();
            [$targetDate, $placeCode, $stadiumName, $boats] = $this->loadTarget($pdo, $raceCode);
            [$prior, $priorSource] = $this->loadCoursePrior($pdo, $raceCode, $targetDate, $placeCode);

            $result = [];
            foreach ($boats as $boat) {
                $lane = (int)$boat['lane'];
                $targetCourse = (int)$courseByBoat[$lane];

                $history = $this->loadLast100($pdo, $boat['player_id'], $targetDate, $raceCode);
                $counts = $this->playerCounts($history, $targetCourse, $placeCode);

                $p0 = (float)($prior[$targetCourse]['rate'] ?? 0.5);
                $pPc = ($counts['pc_w'] + self::K_PC * $p0)
                    / ($counts['pc_n'] + self::K_PC);
                $pBase = ($counts['pvc_w'] + self::K_PVC * $pPc)
                    / ($counts['pvc_n'] + self::K_PVC);

                $baseLogit = $this->logit($pBase);
                $eta = self::BETA_INTERCEPT
                    + self::BETA_BASE_LOGIT * $baseLogit
                    + self::BETA_PRIMARY_Z * $primaryZ[$lane]
                    + self::BETA_SECONDARY_Z * $secondaryZ[$lane];
                $pAi = $this->clipProbability($this->sigmoid($eta));

                $result[$lane] = [
                    ...$boat,
                    ...$counts,
                    'course' => $targetCourse,
                    'venue_n' => (int)($prior[$targetCourse]['n'] ?? 0),
                    'venue_top3' => (int)($prior[$targetCourse]['top3'] ?? 0),
                    'prior_source' => (string)($prior[$targetCourse]['source'] ?? 'neutral'),
                    'p0' => $p0,
                    'p_pc' => $pPc,
                    'p_base' => $pBase,
                    'base_rate' => $pBase * 100.0,
                    'primary_score' => $primaryScores[$lane],
                    'primary_z' => $primaryZ[$lane],
                    'secondary_score' => $secondaryScores[$lane],
                    'secondary_z' => $secondaryZ[$lane],
                    'eta' => $eta,
                    'p_ai' => $pAi,
                    'ai_rate' => $pAi * 100.0,
                ];
            }

            $ranked = array_keys($result);
            usort($ranked, static function (int $a, int $b) use ($result): int {
                $cmp = ($result[$b]['p_ai'] <=> $result[$a]['p_ai']);
                return $cmp !== 0 ? $cmp : ($a <=> $b);
            });
            foreach ($ranked as $index => $lane) {
                $result[$lane]['ai_rank'] = $index + 1;
            }
            ksort($result);

            return [
                'status' => 'ok',
                'boats' => $result,
                'totals' => [
                    'base' => array_sum(array_column($result, 'base_rate')),
                    'ai' => array_sum(array_column($result, 'ai_rate')),
                ],
                'method' => [
                    'base' => 'BB_MEDIUM_RAW',
                    'course_mode' => 'ENTRY_MODE',
                    'entry_source' => $virtualEntry ? 'virtual' : 'exhibition',
                    'k_pc' => self::K_PC,
                    'k_pvc' => self::K_PVC,
                    'intercept' => self::BETA_INTERCEPT,
                    'base_logit' => self::BETA_BASE_LOGIT,
                    'primary_z' => self::BETA_PRIMARY_Z,
                    'secondary_z' => self::BETA_SECONDARY_Z,
                    'normalize_300' => false,
                    'sum' => false,
                    'slit' => false,
                    'history_source' => 'race_history_fact',
                ],
                'feature_stats' => [
                    'primary_mean' => $primaryMean,
                    'primary_sd' => $primarySd,
                    'primary_zero_sd' => $primaryZeroSd,
                    'secondary_mean' => $secondaryMean,
                    'secondary_sd' => $secondarySd,
                    'secondary_zero_sd' => $secondaryZeroSd,
                ],
                'prior_source' => $priorSource,
                'course_by_boat' => $courseByBoat,
                'target_date' => $targetDate,
                'place_code' => $placeCode,
                'stadium_name' => $stadiumName,
                'error' => '',
            ];
        } catch (Throwable $e) {
            return $this->emptyResult('error', $e->getMessage());
        }
    }

    private function emptyResult(string $status, string $error): array
    {
        return [
            'status' => $status,
            'boats' => [],
            'totals' => ['base' => 0.0, 'ai' => 0.0],
            'method' => [
                'base' => 'BB_MEDIUM_RAW',
                'course_mode' => 'ENTRY_MODE',
                'k_pc' => self::K_PC,
                'k_pvc' => self::K_PVC,
                'intercept' => self::BETA_INTERCEPT,
                'base_logit' => self::BETA_BASE_LOGIT,
                'primary_z' => self::BETA_PRIMARY_Z,
                'secondary_z' => self::BETA_SECONDARY_Z,
                'normalize_300' => false,
                'sum' => false,
                'slit' => false,
            ],
            'error' => $error,
        ];
    }

    private function primaryScoreMap(array $rows): array
    {
        $scores = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $lane = $this->validCourse($row['lane'] ?? ($index + 1));
            $score = $row['total_score'] ?? null;
            if ($lane === null || !is_numeric($score) || isset($scores[$lane])) {
                continue;
            }
            $scores[$lane] = (float)$score;
        }
        ksort($scores);
        return $scores;
    }

    private function secondaryScoreMap(array $rows): array
    {
        $scores = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $lane = $this->validCourse($row['teiban'] ?? ($index + 1));
            $score = $row['final_2nd_score'] ?? null;
            if ($lane === null || !is_numeric($score) || isset($scores[$lane])) {
                continue;
            }
            $scores[$lane] = (float)$score;
        }
        ksort($scores);
        return $scores;
    }

    private function courseMapFromTenji(array $rows): array
    {
        $map = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $lane = $this->validCourse($row['teiban'] ?? ($index + 1));
            $course = $this->validCourse($row['tenji_course'] ?? null);
            if ($lane === null || $course === null || isset($map[$lane])) {
                continue;
            }
            $map[$lane] = $course;
        }
        ksort($map);
        return $map;
    }

    private function validCourseMap(array $map): bool
    {
        if (count($map) !== 6) {
            return false;
        }

        $courses = [];
        for ($lane = 1; $lane <= 6; $lane++) {
            $course = $this->validCourse($map[$lane] ?? null);
            if ($course === null) {
                return false;
            }
            $courses[] = $course;
        }

        sort($courses);
        return $courses === [1, 2, 3, 4, 5, 6];
    }

    private function zScores(array $scores): array
    {
        if (count($scores) !== 6) {
            throw new RuntimeException('AI3連対率: Z値計算には6艇分のスコアが必要です');
        }

        $mean = array_sum($scores) / 6.0;
        $variance = 0.0;
        foreach ($scores as $value) {
            $variance += ($value - $mean) ** 2;
        }
        $variance /= 6.0;
        $sd = sqrt($variance);
        $zeroSd = $sd < 1e-12;

        $z = [];
        foreach ($scores as $lane => $value) {
            $z[$lane] = $zeroSd ? 0.0 : (($value - $mean) / $sd);
        }

        return [$z, $mean, $sd, $zeroSd];
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
            throw new RuntimeException('AI3連対率: 対象レースの出走艇が6艇ではありません');
        }

        $targetDate = (string)$rows[0]['race_date'];
        $stadiumName = trim((string)($rows[0]['stadium_name'] ?? ''));
        $placeCode = strlen($raceCode) >= 11 ? substr($raceCode, 8, 3) : '???';

        $boats = [];
        foreach ($rows as $row) {
            $lane = $this->validCourse($row['lane_number'] ?? null);
            $playerId = trim((string)($row['player_id'] ?? ''));
            if ($lane === null || $playerId === '') {
                throw new RuntimeException('AI3連対率: lane/player_idが不正です');
            }
            $boats[] = [
                'lane' => $lane,
                'player_id' => $playerId,
                'player_name' => trim((string)($row['player_name'] ?? '')),
            ];
        }

        return [$targetDate, $placeCode, $stadiumName, $boats];
    }

    private function loadCoursePrior(PDO $pdo, string $raceCode, string $targetDate, string $placeCode): array
    {
        // 場別・全場を同じFact走査1回で取得する。
        $sql = <<<SQL
            WITH base AS (
                SELECT c1, c2, c3, place_code
                FROM boat_race.race_history_fact
                WHERE course_valid
                  AND (
                        race_date < ?::date
                        OR (race_date = ?::date AND race_code < ?)
                      )
            ),
            courses AS (
                SELECT generate_series(1, 6)::int AS course
            )
            SELECT
                c.course,
                COUNT(*) AS global_n,
                COUNT(*) FILTER (WHERE b.place_code = ?) AS venue_n,
                COUNT(*) FILTER (WHERE c.course IN (b.c1, b.c2, b.c3)) AS global_top3,
                COUNT(*) FILTER (
                    WHERE b.place_code = ?
                      AND c.course IN (b.c1, b.c2, b.c3)
                ) AS venue_top3
            FROM base b
            CROSS JOIN courses c
            GROUP BY c.course
            ORDER BY c.course
        SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$targetDate, $targetDate, $raceCode, $placeCode, $placeCode]);

        $global = [];
        $venue = [];
        for ($course = 1; $course <= 6; $course++) {
            $global[$course] = ['n' => 0, 'top3' => 0, 'rate' => 0.5];
            $venue[$course] = ['n' => 0, 'top3' => 0, 'rate' => 0.5];
        }

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $course = $this->validCourse($row['course'] ?? null);
            if ($course === null) {
                continue;
            }

            $gn = (int)($row['global_n'] ?? 0);
            $gt = (int)($row['global_top3'] ?? 0);
            $vn = (int)($row['venue_n'] ?? 0);
            $vt = (int)($row['venue_top3'] ?? 0);

            $global[$course] = [
                'n' => $gn,
                'top3' => $gt,
                'rate' => $gn > 0 ? $gt / $gn : 0.5,
            ];
            $venue[$course] = [
                'n' => $vn,
                'top3' => $vt,
                'rate' => $vn > 0 ? $vt / $vn : 0.5,
            ];
        }

        $out = [];
        $sources = [];
        for ($course = 1; $course <= 6; $course++) {
            $vn = (int)($venue[$course]['n'] ?? 0);
            $gn = (int)($global[$course]['n'] ?? 0);

            if ($vn > 0) {
                $out[$course] = [
                    ...$venue[$course],
                    'source' => 'venue',
                ];
                $sources[] = 'venue';
            } elseif ($gn > 0) {
                $out[$course] = [
                    ...$global[$course],
                    'source' => 'global',
                ];
                $sources[] = 'global';
            } else {
                $out[$course] = [
                    'n' => 0,
                    'top3' => 0,
                    'rate' => 0.5,
                    'source' => 'neutral_0.5',
                ];
                $sources[] = 'neutral_0.5';
            }
        }

        $sourceLabel = count(array_unique($sources)) === 1
            ? $sources[0]
            : 'mixed';

        return [$out, $sourceLabel];
    }

    private function loadLast100(PDO $pdo, string $playerId, string $targetDate, string $targetRaceCode): array
    {
        $sql = <<<SQL
            SELECT
                re.race_code,
                re.lane_number,
                rrd.rank,
                rrd.entry_course AS result_course,
                el.entry_course AS exhibition_course
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
            ORDER BY rm.race_date DESC, re.race_code DESC
            LIMIT 100
        SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$playerId, $targetDate, $targetDate, $targetRaceCode]);

        $history = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $resultCourse = $this->validCourse($row['result_course'] ?? null);
            $exhibitionCourse = $this->validCourse($row['exhibition_course'] ?? null);
            $laneCourse = $this->validCourse($row['lane_number'] ?? null);
            $course = $resultCourse ?? $exhibitionCourse ?? $laneCourse;
            if ($course === null) {
                continue;
            }

            $rank = is_numeric($row['rank'] ?? null) ? (int)$row['rank'] : null;
            $code = (string)$row['race_code'];
            $history[] = [
                'place' => strlen($code) >= 11 ? substr($code, 8, 3) : '???',
                'course' => $course,
                'top3' => in_array($rank, [1, 2, 3], true) ? 1 : 0,
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
            if ((int)$row['course'] !== $targetCourse) {
                continue;
            }

            $pcN++;
            $pcW += (int)$row['top3'];

            if ((string)$row['place'] === $placeCode) {
                $pvcN++;
                $pvcW += (int)$row['top3'];
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

    private function logit(float $p): float
    {
        $p = $this->clipProbability($p);
        return log($p / (1.0 - $p));
    }

    private function sigmoid(float $x): float
    {
        if ($x >= 0.0) {
            return 1.0 / (1.0 + exp(-$x));
        }
        $e = exp($x);
        return $e / (1.0 + $e);
    }

    private function clipProbability(float $p): float
    {
        return min(max($p, 1e-9), 1.0 - 1e-9);
    }

    private function validCourse(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $course = (int)$value;
        return ($course >= 1 && $course <= 6) ? $course : null;
    }
}
