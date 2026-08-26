<?php

if (!function_exists('getPDO')) {
    require_once __DIR__ . '/../../common/db_connect.php';
}

/**
 * 選手SUMチェッカー（表示専用）。
 *
 * 既存の boat_race.sum_history_fact を利用して、
 * 「選手 × 今回の進入コース」で過去SUM帯ごとの着順率を集計する。
 *
 * - 予想ロジックには接続しない
 * - 対象レース自身と未来レースは除外
 * - SUMの8区間を選手単位の母数確保のため4区間へ集約
 * - 差分は、その選手自身の同コース全体成績との差
 * - N < 5 の区間は表示上「参考外」とする
 */
class PlayerSamLogic
{
    public const BANDS = [
        'plus_04'        => '+0.4以上',
        'zero_plus_04'   => '0〜+0.4',
        'minus_04_zero'  => '-0.4〜0',
        'under_minus_04' => '-0.4未満',
    ];

    public function calculate(
        string $raceCode,
        array $entries,
        array $courseByBoat,
        array $samAppliedList
    ): array {
        try {
            if ($raceCode === '') {
                throw new RuntimeException('選手SUM: race_codeが空です');
            }

            $pdo = getPDO();

            // calc_scores.php の表示用 entries は時期によってキー形式が変わるため、
            // 選手SUMでは race_code を基準に race_entry から対象6艇を直接確定する。
            $targetEntries = $this->loadTargetEntries($pdo, $raceCode);
            if (count($targetEntries) !== 6) {
                // 念のため旧 entries もフォールバックとして残す。
                $targetEntries = $this->normalizeFallbackEntries($entries);
            }

            $targets = $this->buildTargets($targetEntries, $courseByBoat, $samAppliedList);
            if (count($targets) !== 6) {
                throw new RuntimeException('選手SUM: 対象6艇を特定できません');
            }

            $raw = $this->loadStats($pdo, $raceCode, $targets);

            foreach ($targets as $boat => &$target) {
                $bandRows = [];
                foreach (self::BANDS as $key => $label) {
                    $bandRows[$key] = [
                        'key' => $key,
                        'label' => $label,
                        'n' => 0,
                        'win' => 0,
                        'place2' => 0,
                        'place3' => 0,
                        'min_date' => null,
                        'max_date' => null,
                    ];
                }

                foreach (($raw[$boat] ?? []) as $row) {
                    $bandKey = $this->bandKeyFromInterval((string)($row['interval_label'] ?? ''));
                    if ($bandKey === null || !isset($bandRows[$bandKey])) {
                        continue;
                    }

                    $bandRows[$bandKey]['n'] += (int)($row['n'] ?? 0);
                    $bandRows[$bandKey]['win'] += (int)($row['win'] ?? 0);
                    $bandRows[$bandKey]['place2'] += (int)($row['place2'] ?? 0);
                    $bandRows[$bandKey]['place3'] += (int)($row['place3'] ?? 0);

                    $minDate = (string)($row['min_date'] ?? '');
                    $maxDate = (string)($row['max_date'] ?? '');
                    if ($minDate !== '') {
                        if ($bandRows[$bandKey]['min_date'] === null || $minDate < $bandRows[$bandKey]['min_date']) {
                            $bandRows[$bandKey]['min_date'] = $minDate;
                        }
                    }
                    if ($maxDate !== '') {
                        if ($bandRows[$bandKey]['max_date'] === null || $maxDate > $bandRows[$bandKey]['max_date']) {
                            $bandRows[$bandKey]['max_date'] = $maxDate;
                        }
                    }
                }

                $base = [
                    'n' => 0,
                    'win' => 0,
                    'place2' => 0,
                    'place3' => 0,
                    'min_date' => null,
                    'max_date' => null,
                ];

                foreach ($bandRows as $band) {
                    $base['n'] += $band['n'];
                    $base['win'] += $band['win'];
                    $base['place2'] += $band['place2'];
                    $base['place3'] += $band['place3'];
                    if ($band['min_date'] !== null) {
                        if ($base['min_date'] === null || $band['min_date'] < $base['min_date']) {
                            $base['min_date'] = $band['min_date'];
                        }
                    }
                    if ($band['max_date'] !== null) {
                        if ($base['max_date'] === null || $band['max_date'] > $base['max_date']) {
                            $base['max_date'] = $band['max_date'];
                        }
                    }
                }

                $baseRates = $this->rates($base);
                $base['rates'] = $baseRates;

                foreach ($bandRows as $key => &$band) {
                    $rates = $this->rates($band);
                    $band['rates'] = $rates;
                    $band['reliability'] = $band['n'] < 5
                        ? '参考外'
                        : ($band['n'] < 10 ? '母数少' : '');

                    $band['diff'] = [
                        'win' => null,
                        'place2' => null,
                        'place3' => null,
                        'trio' => null,
                    ];

                    // 小標本の派手な差を実戦判断へ持ち込まないため、N<5は差を非表示にする。
                    if ($band['n'] >= 5 && $base['n'] > 0) {
                        foreach (['win', 'place2', 'place3', 'trio'] as $metric) {
                            $band['diff'][$metric] = $rates[$metric] - $baseRates[$metric];
                        }
                    }
                }
                unset($band);

                $target['base'] = $base;
                $target['bands'] = $bandRows;
                $currentKey = (string)($target['current_band_key'] ?? '');
                $target['current_band_stats'] = $currentKey !== '' && isset($bandRows[$currentKey])
                    ? $bandRows[$currentKey]
                    : null;
            }
            unset($target);

            uasort($targets, static function (array $a, array $b): int {
                $courseCmp = (int)$a['course'] <=> (int)$b['course'];
                return $courseCmp !== 0 ? $courseCmp : ((int)$a['boat'] <=> (int)$b['boat']);
            });

            return [
                'status' => 'ok',
                'boats' => $targets,
                'bands' => self::BANDS,
                'basis' => 'player_course_sum_history_fact',
                'error' => '',
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'error',
                'boats' => [],
                'bands' => self::BANDS,
                'basis' => 'player_course_sum_history_fact',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function loadTargetEntries(PDO $pdo, string $raceCode): array
    {
        $sql = <<<SQL
            SELECT
                lane_number,
                player_id::text AS player_id,
                player_name
            FROM boat_race.race_entry
            WHERE race_code = ?
            ORDER BY lane_number
        SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$raceCode]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 6) {
            return [];
        }

        $out = [];
        $used = [];
        foreach ($rows as $row) {
            $boat = (int)($row['lane_number'] ?? 0);
            $playerId = trim((string)($row['player_id'] ?? ''));
            if ($boat < 1 || $boat > 6 || isset($used[$boat]) || $playerId === '') {
                return [];
            }
            $used[$boat] = true;
            $out[] = [
                'lane_number' => $boat,
                'player_id' => $playerId,
                'player_name' => (string)($row['player_name'] ?? ''),
            ];
        }

        return count($used) === 6 ? $out : [];
    }

    private function normalizeFallbackEntries(array $entries): array
    {
        $out = [];
        foreach ($entries as $idx => $entry) {
            $boat = (int)($entry['lane_number'] ?? $entry['boat'] ?? $entry['teiban'] ?? ($idx + 1));
            $playerId = trim((string)($entry['player_id'] ?? $entry['registration_number'] ?? ''));
            if ($boat < 1 || $boat > 6 || $playerId === '') {
                continue;
            }
            $out[] = [
                'lane_number' => $boat,
                'player_id' => $playerId,
                'player_name' => (string)($entry['player_name'] ?? $entry['name'] ?? ''),
            ];
        }
        return $out;
    }

    private function buildTargets(array $entries, array $courseByBoat, array $samAppliedList): array
    {
        $entryByBoat = [];
        foreach ($entries as $entry) {
            $boat = (int)($entry['lane_number'] ?? 0);
            if ($boat >= 1 && $boat <= 6) {
                $entryByBoat[$boat] = $entry;
            }
        }

        $samByBoat = [];
        foreach ($samAppliedList as $row) {
            $boat = (int)($row['teiban'] ?? 0);
            if ($boat < 1 || $boat > 6) {
                continue;
            }
            $samByBoat[$boat] = $row;
        }

        $targets = [];
        $usedCourses = [];
        for ($boat = 1; $boat <= 6; $boat++) {
            $entry = $entryByBoat[$boat] ?? [];
            $playerId = trim((string)($entry['player_id'] ?? ''));
            if ($playerId === '') {
                continue;
            }

            $course = isset($courseByBoat[$boat]) && is_numeric($courseByBoat[$boat])
                ? (int)$courseByBoat[$boat]
                : $boat;
            if ($course < 1 || $course > 6 || isset($usedCourses[$course])) {
                continue;
            }
            $usedCourses[$course] = true;

            $sam = $samByBoat[$boat] ?? [];
            $currentInterval = (string)($sam['interval'] ?? '');
            $currentDiff = ($currentInterval !== '' && $currentInterval !== '-' && is_numeric($sam['avg_diff'] ?? null))
                ? (float)$sam['avg_diff']
                : null;
            $currentBandKey = $this->bandKeyFromInterval($currentInterval);

            $targets[$boat] = [
                'boat' => $boat,
                'course' => $course,
                'player_id' => $playerId,
                'player_name' => (string)($entry['player_name'] ?? ''),
                'current_diff' => $currentDiff,
                'current_interval' => $currentInterval !== '' ? $currentInterval : '-',
                'current_band_key' => $currentBandKey,
                'current_band_label' => $currentBandKey !== null ? (self::BANDS[$currentBandKey] ?? '-') : '-',
            ];
        }

        return $targets;
    }

    private function loadStats(PDO $pdo, string $raceCode, array $targets): array
    {
        $values = [];
        $params = [];
        foreach ($targets as $target) {
            $values[] = '(?::int, ?::text, ?::int)';
            $params[] = (int)$target['boat'];
            $params[] = (string)$target['player_id'];
            $params[] = (int)$target['course'];
        }
        $params[] = $raceCode;
        $params[] = $raceCode;

        $valuesSql = implode(', ', $values);
        $sql = <<<SQL
            WITH targets(boat, player_id, course) AS (
                VALUES {$valuesSql}
            ),
            player_ex AS (
                SELECT DISTINCT ON (t.boat, el.race_code, el.entry_course)
                    t.boat,
                    t.player_id,
                    t.course,
                    el.race_code
                FROM targets t
                JOIN boat_race.exhibition_live el
                  ON el.player_id::text = t.player_id
                 AND el.entry_course = t.course
                WHERE el.race_code < ?
                ORDER BY
                    t.boat,
                    el.race_code,
                    el.entry_course,
                    el.created_date DESC NULLS LAST
            ),
            hist AS (
                SELECT
                    pe.boat,
                    f.race_date,
                    f.interval_label,
                    rd.rank_num
                FROM player_ex pe
                JOIN boat_race.sum_history_fact f
                  ON f.race_code = pe.race_code
                 AND f.course = pe.course
                LEFT JOIN LATERAL (
                    SELECT
                        CASE
                            WHEN rrd.rank::text ~ '^[1-6]$' THEN rrd.rank::int
                            ELSE NULL
                        END AS rank_num
                    FROM boat_race.race_result_detail rrd
                    WHERE rrd.race_code = f.race_code
                      AND rrd.entry_course::text ~ '^[1-6]$'
                      AND rrd.entry_course::int = f.course
                    LIMIT 1
                ) rd ON TRUE
                WHERE f.race_code < ?
            )
            SELECT
                boat,
                interval_label,
                COUNT(*) FILTER (WHERE rank_num BETWEEN 1 AND 6)::int AS n,
                COUNT(*) FILTER (WHERE rank_num = 1)::int AS win,
                COUNT(*) FILTER (WHERE rank_num = 2)::int AS place2,
                COUNT(*) FILTER (WHERE rank_num = 3)::int AS place3,
                MIN(race_date) FILTER (WHERE rank_num BETWEEN 1 AND 6) AS min_date,
                MAX(race_date) FILTER (WHERE rank_num BETWEEN 1 AND 6) AS max_date
            FROM hist
            GROUP BY boat, interval_label
            ORDER BY boat, interval_label
        SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $row) {
            $boat = (int)($row['boat'] ?? 0);
            if ($boat < 1 || $boat > 6) {
                continue;
            }
            $out[$boat][] = $row;
        }
        return $out;
    }

    private function rates(array $row): array
    {
        $n = (int)($row['n'] ?? 0);
        if ($n <= 0) {
            return ['win' => 0.0, 'place2' => 0.0, 'place3' => 0.0, 'trio' => 0.0];
        }

        $win = (int)($row['win'] ?? 0);
        $place2 = (int)($row['place2'] ?? 0);
        $place3 = (int)($row['place3'] ?? 0);

        return [
            'win' => $win / $n,
            'place2' => $place2 / $n,
            'place3' => $place3 / $n,
            'trio' => ($win + $place2 + $place3) / $n,
        ];
    }

    private function bandKeyFromInterval(string $interval): ?string
    {
        return match ($interval) {
            '0.4-0.6', '0.6以上' => 'plus_04',
            '0.0-0.2', '0.2-0.4' => 'zero_plus_04',
            '-0.4--0.2', '-0.2-0.0' => 'minus_04_zero',
            '-0.6未満', '-0.6--0.4' => 'under_minus_04',
            default => null,
        };
    }
}
