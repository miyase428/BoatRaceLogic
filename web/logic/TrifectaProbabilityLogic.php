<?php

/**
 * 出目確率 本番表示用ロジック。
 *
 * 検証済み構成:
 *   STEP1: VENUE_K3000
 *   STEP2: win alpha=1.00 / trio beta=1.25
 *   STEP3: order delta=0.25 / gamma=0.25
 *
 * 既存の最終予想ロジックには影響させず、表示専用で利用する。
 */
class TrifectaProbabilityLogic
{
    private const GLOBAL_ALPHA = 0.5;
    private const VENUE_K = 3000.0;
    private const WIN_ALPHA = 1.0;
    private const TRIO_BETA = 1.25;
    private const ORDER_DELTA = 0.25;
    private const ORDER_GAMMA = 0.25;
    private const EPS = 1.0e-12;

    public function calculate(
        string $raceCode,
        array $correctedWinBoats,
        array $aiTrioBoats,
        array $courseByBoat
    ): array {
        try {
            $raceCode = strtoupper(trim($raceCode));
            if (!preg_match('/^(\d{8})([A-Z0-9]{3})(\d{2})$/', $raceCode, $m)) {
                throw new RuntimeException('出目確率: race_codeの形式が不正です');
            }

            $targetDate = DateTimeImmutable::createFromFormat('!Ymd', $m[1]);
            if (!$targetDate || $targetDate->format('Ymd') !== $m[1]) {
                throw new RuntimeException('出目確率: race_codeの日付が不正です');
            }
            $targetDateText = $targetDate->format('Y-m-d');
            $placeCode = $m[2];

            $courseByBoat = $this->normalizeCourseMap($courseByBoat);
            $boatByCourse = array_flip($courseByBoat);

            $winProb = [];
            $trioProb = [];
            for ($boat = 1; $boat <= 6; $boat++) {
                $winRate = $correctedWinBoats[(string)$boat]['corrected_rate']
                    ?? $correctedWinBoats[$boat]['corrected_rate']
                    ?? null;
                $trioRate = $aiTrioBoats[(string)$boat]['ai_rate']
                    ?? $aiTrioBoats[$boat]['ai_rate']
                    ?? null;

                if ($winRate === null || $trioRate === null) {
                    throw new RuntimeException('出目確率: 補正後1着率またはAI3連対率が6艇分ありません');
                }

                $winProb[$boat] = $this->clip((float)$winRate / 100.0);
                $trioProb[$boat] = $this->clip((float)$trioRate / 100.0);
            }

            $pdo = getPDO();
            [$globalCounts, $globalN, $venueCounts, $venueN] = $this->loadPatternCounts(
                $pdo,
                $targetDateText,
                $raceCode,
                $placeCode
            );

            if ($globalN <= 0) {
                throw new RuntimeException('出目確率: 対象レース以前の3連単履歴がありません');
            }

            $patterns = $this->patterns();
            $mCount = count($patterns);
            $globalDen = $globalN + self::GLOBAL_ALPHA * $mCount;

            $baseByCombo = [];
            foreach ($patterns as $pattern) {
                [$c1, $c2, $c3] = $pattern;
                $key = $this->patternKey($pattern);
                $globalP = (($globalCounts[$key] ?? 0) + self::GLOBAL_ALPHA) / $globalDen;

                if ($venueN > 0) {
                    $baseP = (($venueCounts[$key] ?? 0) + self::VENUE_K * $globalP)
                        / ($venueN + self::VENUE_K);
                } else {
                    $baseP = $globalP;
                }

                $b1 = (int)($boatByCourse[$c1] ?? 0);
                $b2 = (int)($boatByCourse[$c2] ?? 0);
                $b3 = (int)($boatByCourse[$c3] ?? 0);
                if ($b1 < 1 || $b2 < 1 || $b3 < 1) {
                    throw new RuntimeException('出目確率: 進入コースから艇番へ変換できません');
                }

                $comboKey = $this->comboKey($b1, $b2, $b3);
                $baseByCombo[$comboKey] = (float)$baseP;
            }

            $baseByCombo = $this->normalizeAssoc($baseByCombo);

            // 基礎出目から艇別の1着/3連対周辺確率を逆算する。
            $qWin = array_fill(1, 6, 0.0);
            $qTrio = array_fill(1, 6, 0.0);
            foreach ($baseByCombo as $comboKey => $p) {
                [$b1, $b2, $b3] = $this->parseComboKey($comboKey);
                $qWin[$b1] += $p;
                $qTrio[$b1] += $p;
                $qTrio[$b2] += $p;
                $qTrio[$b3] += $p;
            }

            $winRatio = [];
            $trioRatio = [];
            for ($boat = 1; $boat <= 6; $boat++) {
                $winRatio[$boat] = $winProb[$boat] / max($qWin[$boat], self::EPS);
                $trioRatio[$boat] = $trioProb[$boat] / max($qTrio[$boat], self::EPS);
            }

            // STEP2: 基礎出目 × win比 × 2/3着trio比。
            $step2Raw = [];
            foreach ($baseByCombo as $comboKey => $baseP) {
                [$b1, $b2, $b3] = $this->parseComboKey($comboKey);
                $score = $baseP
                    * pow(max($winRatio[$b1], self::EPS), self::WIN_ALPHA)
                    * pow(max($trioRatio[$b2], self::EPS), self::TRIO_BETA)
                    * pow(max($trioRatio[$b3], self::EPS), self::TRIO_BETA);
                $step2Raw[$comboKey] = $score;
            }
            $step2 = $this->normalizeAssoc($step2Raw);

            // STEP3: 同一head・同一2/3着候補のペア合計を維持して順序だけ補正。
            $final = $step2;
            for ($head = 1; $head <= 6; $head++) {
                $others = array_values(array_filter(
                    range(1, 6),
                    static fn(int $b): bool => $b !== $head
                ));

                $n = count($others);
                for ($x = 0; $x < $n; $x++) {
                    for ($y = $x + 1; $y < $n; $y++) {
                        $j = $others[$x];
                        $k = $others[$y];
                        $keyJk = $this->comboKey($head, $j, $k);
                        $keyKj = $this->comboKey($head, $k, $j);

                        $pJk = (float)($step2[$keyJk] ?? 0.0);
                        $pKj = (float)($step2[$keyKj] ?? 0.0);
                        $pairTotal = $pJk + $pKj;
                        if ($pairTotal <= 0.0) {
                            continue;
                        }

                        $logOdds = log(max($pJk, self::EPS) / max($pKj, self::EPS));
                        $logOdds += self::ORDER_DELTA
                            * log(max($trioRatio[$j], self::EPS) / max($trioRatio[$k], self::EPS));
                        $logOdds += self::ORDER_GAMMA
                            * log(max($winRatio[$j], self::EPS) / max($winRatio[$k], self::EPS));

                        $share = $this->sigmoid($logOdds);
                        $final[$keyJk] = $pairTotal * $share;
                        $final[$keyKj] = $pairTotal * (1.0 - $share);
                    }
                }
            }
            $final = $this->normalizeAssoc($final);

            $rows = [];
            foreach ($final as $comboKey => $p) {
                [$b1, $b2, $b3] = $this->parseComboKey($comboKey);
                $rows[] = [
                    'key' => $comboKey,
                    'boats' => [$b1, $b2, $b3],
                    'courses' => [
                        $courseByBoat[$b1],
                        $courseByBoat[$b2],
                        $courseByBoat[$b3],
                    ],
                    'base_probability' => (float)($baseByCombo[$comboKey] ?? 0.0),
                    'step2_probability' => (float)($step2[$comboKey] ?? 0.0),
                    'probability' => (float)$p,
                ];
            }

            usort($rows, static function (array $a, array $b): int {
                $cmp = ($b['probability'] <=> $a['probability']);
                if ($cmp !== 0) {
                    return $cmp;
                }
                return strcmp((string)$a['key'], (string)$b['key']);
            });

            $cum = 0.0;
            foreach ($rows as $idx => &$row) {
                $cum += (float)$row['probability'];
                $row['rank'] = $idx + 1;
                $row['cumulative_probability'] = $cum;
            }
            unset($row);

            return [
                'status' => 'ok',
                'error' => '',
                'race_code' => $raceCode,
                'target_date' => $targetDateText,
                'place_code' => $placeCode,
                'course_by_boat' => $courseByBoat,
                'boat_by_course' => $boatByCourse,
                'rows' => $rows,
                'top20' => array_slice($rows, 0, 20),
                'totals' => [
                    'base' => array_sum($baseByCombo),
                    'step2' => array_sum($step2),
                    'final' => array_sum($final),
                ],
                'history' => [
                    'global_n' => $globalN,
                    'venue_n' => $venueN,
                ],
                'marginals' => [
                    'base_win' => $qWin,
                    'base_trio' => $qTrio,
                    'corrected_win' => $winProb,
                    'ai_trio' => $trioProb,
                    'win_ratio' => $winRatio,
                    'trio_ratio' => $trioRatio,
                ],
                'method' => [
                    'base' => 'VENUE_K3000',
                    'venue_k' => self::VENUE_K,
                    'win_alpha' => self::WIN_ALPHA,
                    'trio_beta' => self::TRIO_BETA,
                    'order_delta' => self::ORDER_DELTA,
                    'order_gamma' => self::ORDER_GAMMA,
                ],
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
                'rows' => [],
                'top20' => [],
                'totals' => [],
                'history' => [],
                'marginals' => [],
                'method' => [],
            ];
        }
    }

    private function loadPatternCounts(
        PDO $pdo,
        string $targetDate,
        string $targetRaceCode,
        string $placeCode
    ): array {
        $sql = <<<SQL
            WITH top3_rows AS (
                SELECT
                    re.race_code,
                    SUBSTRING(re.race_code, 9, 3) AS place_code,
                    CASE WHEN rrd.rank::text ~ '^[1-3]$' THEN rrd.rank::int ELSE NULL END AS rank_no,
                    COALESCE(
                        CASE WHEN rrd.entry_course::text ~ '^[1-6]$' THEN rrd.entry_course::int ELSE NULL END,
                        CASE WHEN el.entry_course::text ~ '^[1-6]$' THEN el.entry_course::int ELSE NULL END,
                        CASE WHEN re.lane_number::text ~ '^[1-6]$' THEN re.lane_number::int ELSE NULL END
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
                WHERE (
                        rm.race_date < ?::date
                        OR (rm.race_date = ?::date AND re.race_code < ?)
                      )
                  AND rrd.rank::text IN ('1', '2', '3')
            ),
            race_patterns AS (
                SELECT
                    race_code,
                    place_code,
                    COUNT(*) AS row_n,
                    COUNT(DISTINCT rank_no) AS rank_n,
                    COUNT(DISTINCT actual_course) AS course_n,
                    MAX(actual_course) FILTER (WHERE rank_no = 1) AS c1,
                    MAX(actual_course) FILTER (WHERE rank_no = 2) AS c2,
                    MAX(actual_course) FILTER (WHERE rank_no = 3) AS c3
                FROM top3_rows
                GROUP BY race_code, place_code
            )
            SELECT
                place_code,
                c1,
                c2,
                c3,
                COUNT(*) AS n
            FROM race_patterns
            WHERE row_n = 3
              AND rank_n = 3
              AND course_n = 3
              AND c1 BETWEEN 1 AND 6
              AND c2 BETWEEN 1 AND 6
              AND c3 BETWEEN 1 AND 6
            GROUP BY place_code, c1, c2, c3
            ORDER BY place_code, c1, c2, c3
        SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$targetDate, $targetDate, $targetRaceCode]);

        $global = [];
        $venue = [];
        $globalN = 0;
        $venueN = 0;

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $pattern = [(int)$row['c1'], (int)$row['c2'], (int)$row['c3']];
            $key = $this->patternKey($pattern);
            $n = (int)($row['n'] ?? 0);
            if ($n <= 0) {
                continue;
            }

            $global[$key] = ($global[$key] ?? 0) + $n;
            $globalN += $n;

            if ((string)($row['place_code'] ?? '') === $placeCode) {
                $venue[$key] = ($venue[$key] ?? 0) + $n;
                $venueN += $n;
            }
        }

        return [$global, $globalN, $venue, $venueN];
    }

    private function normalizeCourseMap(array $courseByBoat): array
    {
        $map = [];
        for ($boat = 1; $boat <= 6; $boat++) {
            $course = (int)($courseByBoat[$boat] ?? 0);
            if ($course < 1 || $course > 6) {
                throw new RuntimeException('出目確率: 進入が6艇分そろっていません');
            }
            $map[$boat] = $course;
        }

        $courses = array_values($map);
        sort($courses);
        if ($courses !== [1, 2, 3, 4, 5, 6]) {
            throw new RuntimeException('出目確率: 進入は1～6コースを1回ずつ指定してください');
        }

        return $map;
    }

    private function patterns(): array
    {
        $out = [];
        for ($a = 1; $a <= 6; $a++) {
            for ($b = 1; $b <= 6; $b++) {
                if ($b === $a) continue;
                for ($c = 1; $c <= 6; $c++) {
                    if ($c === $a || $c === $b) continue;
                    $out[] = [$a, $b, $c];
                }
            }
        }
        return $out;
    }

    private function normalizeAssoc(array $values): array
    {
        $total = array_sum($values);
        if ($total <= 0.0) {
            throw new RuntimeException('出目確率: 120通りを正規化できません');
        }
        foreach ($values as $key => $value) {
            $values[$key] = max(0.0, (float)$value) / $total;
        }
        return $values;
    }

    private function patternKey(array $pattern): string
    {
        return implode('-', array_map('intval', $pattern));
    }

    private function comboKey(int $b1, int $b2, int $b3): string
    {
        return $b1 . '-' . $b2 . '-' . $b3;
    }

    private function parseComboKey(string $key): array
    {
        $parts = array_map('intval', explode('-', $key));
        if (count($parts) !== 3) {
            throw new RuntimeException('出目確率: 組合せキーが不正です');
        }
        return $parts;
    }

    private function clip(float $p): float
    {
        return min(max($p, self::EPS), 1.0 - self::EPS);
    }

    private function sigmoid(float $x): float
    {
        if ($x >= 0.0) {
            $z = exp(-$x);
            return 1.0 / (1.0 + $z);
        }
        $z = exp($x);
        return $z / (1.0 + $z);
    }
}
