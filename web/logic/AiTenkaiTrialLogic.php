<?php

declare(strict_types=1);

/**
 * AI展開予想（試験）
 *
 * prototype_ai_tenkai_v3.php の考え方をWeb表示用に切り出す。
 * - 展示後の補正後1着率を勝者確率として使用
 * - 選手×今回コースの6ヶ月/1年決まり手を平滑化
 * - 場×コース直近1年の勝ち方構成を弱い事前分布(K=5)として使用
 * - 場平均は「全レース中、その展開が実際に起きた割合」
 *
 * 予想本体のロジックには組み込まず、表示用の試験機能として扱う。
 */
class AiTenkaiTrialLogic
{
    private const RECENT_WEIGHT = 2.0;
    private const PRIOR_K = 5.0;

    private const LABELS = [
        'nige' => '逃げ',
        'sashi' => '差し',
        'makuri' => 'まくり',
        'makurizashi' => 'まくり差し',
        'other' => 'その他',
    ];

    public function calculate(
        PDO $pdo,
        string $targetDate,
        string $place,
        array $correctedWinRateData,
        array $kimariteData,
        array $courseByBoat
    ): array {
        $corrected = is_array($correctedWinRateData['boats'] ?? null)
            ? $correctedWinRateData['boats']
            : [];

        // AI展開予想は展示後限定。補正後1着率が6艇揃わない場合は出さない。
        if (count($corrected) !== 6) {
            return [
                'status' => 'waiting',
                'message' => '展示情報が揃うとAI展開予想を表示します。',
                'events' => [],
                'venue_races' => 0,
            ];
        }

        for ($boat = 1; $boat <= 6; $boat++) {
            $row = $this->boatRow($corrected, $boat);
            if (!isset($row['corrected_rate']) || !is_numeric($row['corrected_rate'])) {
                return [
                    'status' => 'waiting',
                    'message' => '展示情報が揃うとAI展開予想を表示します。',
                    'events' => [],
                    'venue_races' => 0,
                ];
            }
        }

        $venue = $this->loadVenueStats($pdo, $targetDate, $place);
        $events = [];
        $details = [];

        for ($boat = 1; $boat <= 6; $boat++) {
            $course = (int)($courseByBoat[$boat] ?? $courseByBoat[(string)$boat] ?? $boat);
            if ($course < 1 || $course > 6) {
                $course = $boat;
            }

            $correctedRow = $this->boatRow($corrected, $boat);
            $pWin = max(0.0, (float)($correctedRow['corrected_rate'] ?? 0.0));

            $row6 = $this->periodRow($kimariteData, $course, '6month');
            $row12 = $this->periodRow($kimariteData, $course, '1year');
            $c6 = $this->countsFromPeriod($row6, $course);
            $c12 = $this->countsFromPeriod($row12, $course);

            $priorDist = $venue['courses'][$course]['win_dist'] ?? $this->fallbackDist($course);
            $keys = array_keys($priorDist);

            $olderWin = max(0, $c12['win'] - $c6['win']);
            $effectiveWin = self::RECENT_WEIGHT * $c6['win'] + $olderWin;

            $effectiveTech = [];
            foreach ($keys as $key) {
                $recent = (int)($c6['tech'][$key] ?? 0);
                $older = max(0, (int)($c12['tech'][$key] ?? 0) - $recent);
                $effectiveTech[$key] = self::RECENT_WEIGHT * $recent + $older;
            }

            $den = $effectiveWin + self::PRIOR_K;
            $shares = [];
            foreach ($keys as $key) {
                $priorCount = self::PRIOR_K * (float)($priorDist[$key] ?? 0.0);
                $shares[$key] = $den > 0.0
                    ? (($effectiveTech[$key] ?? 0.0) + $priorCount) / $den
                    : (float)($priorDist[$key] ?? 0.0);
            }

            $sum = array_sum($shares);
            if ($sum > 0.0) {
                foreach ($shares as $key => $value) {
                    $shares[$key] = $value / $sum;
                }
            }

            $details[$boat] = [
                'boat' => $boat,
                'course' => $course,
                'p_win' => $pWin,
                'n6' => (int)($row6['_sample_n'] ?? 0),
                'n12' => (int)($row12['_sample_n'] ?? 0),
                'wins6' => $c6['win'],
                'wins12' => $c12['win'],
                'shares' => $shares,
            ];

            foreach ($shares as $key => $share) {
                $prob = $pWin * $share;
                if ($prob <= 0.001) {
                    continue;
                }

                $venueAverage = (float)($venue['courses'][$course]['race_prob'][$key] ?? 0.0) * 100.0;
                $events[] = [
                    'boat' => $boat,
                    'course' => $course,
                    'tech_key' => $key,
                    'tech' => self::LABELS[$key] ?? $key,
                    'prob' => $prob,
                    'venue_average' => $venueAverage,
                    'diff' => $prob - $venueAverage,
                    'win_share' => $share * 100.0,
                ];
            }
        }

        usort($events, static fn(array $a, array $b): int => $b['prob'] <=> $a['prob']);

        // WEB主表示ではBOATERS風に主要決まり手のみ。その他は内部計算には残す。
        $visibleEvents = array_values(array_filter(
            $events,
            static fn(array $event): bool => ($event['tech_key'] ?? '') !== 'other'
        ));

        return [
            'status' => 'ok',
            'events' => $visibleEvents,
            'all_events' => $events,
            'top3' => array_slice($visibleEvents, 0, 3),
            'details' => $details,
            'venue_races' => (int)($venue['race_count'] ?? 0),
            'recent_weight' => self::RECENT_WEIGHT,
            'prior_k' => self::PRIOR_K,
        ];
    }

    private function boatRow(array $rows, int $boat): array
    {
        $row = $rows[$boat] ?? $rows[(string)$boat] ?? [];
        return is_array($row) ? $row : [];
    }

    private function periodRow(array $kimarite, int $course, string $period): array
    {
        $root = $kimarite[$course] ?? $kimarite[(string)$course] ?? [];
        if (!is_array($root)) {
            return [];
        }
        $row = $root[$period] ?? [];
        return is_array($row) ? $row : [];
    }

    private function countsFromPeriod(array $row, int $course): array
    {
        $counts = is_array($row['_counts'] ?? null) ? $row['_counts'] : [];
        $win = max(0, (int)($counts['win'] ?? 0));

        if ($course === 1) {
            $nige = max(0, min($win, (int)($counts['nige'] ?? 0)));
            return [
                'win' => $win,
                'tech' => [
                    'nige' => $nige,
                    'other' => max(0, $win - $nige),
                ],
            ];
        }

        $sashi = max(0, (int)($counts['sashi'] ?? 0));
        $makuri = max(0, (int)($counts['makuri'] ?? 0));
        $makurizashi = max(0, (int)($counts['makurizashi'] ?? 0));
        $known = min($win, $sashi + $makuri + $makurizashi);

        return [
            'win' => $win,
            'tech' => [
                'sashi' => $sashi,
                'makuri' => $makuri,
                'makurizashi' => $makurizashi,
                'other' => max(0, $win - $known),
            ],
        ];
    }

    private function fallbackDist(int $course): array
    {
        if ($course === 1) {
            return ['nige' => 0.90, 'other' => 0.10];
        }
        return ['sashi' => 0.25, 'makuri' => 0.35, 'makurizashi' => 0.30, 'other' => 0.10];
    }

    private function loadVenueStats(PDO $pdo, string $targetDate, string $place): array
    {
        $sql = <<<'SQL'
WITH winners AS (
    SELECT
        rm.race_code,
        rrd.entry_course::integer AS course,
        TRIM(COALESCE(rrd.technique, '')) AS technique
    FROM boat_race.race_result_detail rrd
    JOIN boat_race.race_master rm
      ON rm.race_code = rrd.race_code
    WHERE TRIM(rrd.rank) = '1'
      AND rrd.entry_course BETWEEN 1 AND 6
      AND rm.race_date >= :target_date::date - INTERVAL '12 months'
      AND rm.race_date < :target_date::date
      AND SUBSTRING(rm.race_code FROM 9 FOR 3) = :place
)
SELECT course, technique, COUNT(*)::integer AS n
FROM winners
GROUP BY course, technique
ORDER BY course, technique
SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':target_date' => $targetDate,
            ':place' => $place,
        ]);

        $raw = [];
        $raceCount = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $course = (int)($row['course'] ?? 0);
            if ($course < 1 || $course > 6) {
                continue;
            }
            $technique = trim((string)($row['technique'] ?? ''));
            $n = max(0, (int)($row['n'] ?? 0));
            $raw[$course][$technique] = $n;
            $raceCount += $n;
        }

        $courses = [];
        for ($course = 1; $course <= 6; $course++) {
            $rows = $raw[$course] ?? [];
            $courseWins = array_sum($rows);

            if ($course === 1) {
                $nige = (int)($rows['逃げ'] ?? 0);
                $techCounts = [
                    'nige' => $nige,
                    'other' => max(0, $courseWins - $nige),
                ];
            } else {
                $sashi = (int)($rows['差し'] ?? 0);
                $makuri = (int)($rows['まくり'] ?? 0);
                $makurizashi = (int)($rows['まくり差し'] ?? 0);
                $known = $sashi + $makuri + $makurizashi;
                $techCounts = [
                    'sashi' => $sashi,
                    'makuri' => $makuri,
                    'makurizashi' => $makurizashi,
                    'other' => max(0, $courseWins - $known),
                ];
            }

            $winDist = [];
            $raceProb = [];
            foreach ($techCounts as $key => $count) {
                $winDist[$key] = $courseWins > 0
                    ? $count / $courseWins
                    : (float)($this->fallbackDist($course)[$key] ?? 0.0);
                $raceProb[$key] = $raceCount > 0 ? $count / $raceCount : 0.0;
            }

            $courses[$course] = [
                'win_count' => $courseWins,
                'win_dist' => $winDist,
                'race_prob' => $raceProb,
            ];
        }

        return [
            'race_count' => $raceCount,
            'courses' => $courses,
        ];
    }
}
