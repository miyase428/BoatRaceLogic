<?php

/**
 * 最終予想テーブル表示用の直近「進入コース別」3連対率。
 *
 * 予想ロジックに使っている従来の three_in_rate_6m / 3m には触れず、
 * 「その選手が今回の進入コースを走った時」に限定した値を表示用に計算する。
 *
 * - 基準日は対象レース日（CURRENT_DATEではない）
 * - 対象レース自身と、それ以降の結果は除外
 * - 今回コースは展示進入（仮想進入時は試算進入）
 * - 過去実コースは result_detail を優先し、欠損時のみ exhibition_live で補完
 * - result_detail / exhibition_live の両方で進入不明なら推測せず除外
 * - winner行が存在する完了レースだけを分母にする
 * - 本人result_detail行が欠けていても、完了レースかつ展示で実進入を確認できれば分母へ含める
 * - 分子は本人の着順が1～3着のレース数
 * - 6ヶ月 / 3ヶ月をそれぞれ集計し、分子/分母も返す
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
            [$cut6, $cut3] = $this->loadCutRaceCodes($pdo, $targetDate);
            $courses = $this->normalizeCourses($courseByBoat);

            $result = [];
            foreach ($boats as $boat => $row) {
                $targetCourse = $courses[$boat] ?? $boat;
                $stats = $this->loadPlayerStats(
                    $pdo,
                    (string)$row['player_id'],
                    $targetCourse,
                    $cut6,
                    $cut3,
                    $raceCode
                );

                $result[$boat] = [
                    'boat' => $boat,
                    'course' => $targetCourse,
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
                'basis' => 'entry_course_completed',
                'error' => '',
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'error',
                'boats' => [],
                'basis' => 'entry_course_completed',
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

    private function loadCutRaceCodes(PDO $pdo, string $targetDate): array
    {
        $sql = <<<SQL
            SELECT
                TO_CHAR(?::date - INTERVAL '6 months', 'YYYYMMDD') AS cut6,
                TO_CHAR(?::date - INTERVAL '3 months', 'YYYYMMDD') AS cut3
        SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$targetDate, $targetDate]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $cut6 = (string)($row['cut6'] ?? '');
        $cut3 = (string)($row['cut3'] ?? '');
        if (!preg_match('/^\d{8}$/', $cut6) || !preg_match('/^\d{8}$/', $cut3)) {
            throw new RuntimeException('直近コース別3連対率: 集計開始日の計算に失敗しました');
        }

        return [$cut6, $cut3];
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
        string $cut6,
        string $cut3,
        string $targetRaceCode
    ): array {
        // race_code は YYYYMMDD + 場3文字 + R2桁の固定形式。
        // OLD/NEW比較で完全一致を確認済みのため、race_master JOINを外して
        // player_id×race_code indexを使いやすい範囲検索へ置き換える。
        $sql = <<<SQL
            WITH hist AS (
                SELECT
                    re.race_code,
                    EXISTS (
                        SELECT 1
                        FROM boat_race.race_result_detail w
                        WHERE w.race_code = re.race_code
                          AND TRIM(w.rank::text) = '1'
                    ) AS completed,
                    COALESCE(
                        CASE
                            WHEN rd.entry_course::text ~ '^[1-6]$' THEN rd.entry_course::int
                            ELSE NULL
                        END,
                        CASE
                            WHEN ex.entry_course::text ~ '^[1-6]$' THEN ex.entry_course::int
                            ELSE NULL
                        END
                    ) AS actual_course,
                    CASE
                        WHEN rd.rank::text ~ '^[1-6]$' THEN rd.rank::int
                        ELSE NULL
                    END AS rank_num
                FROM boat_race.race_entry re
                LEFT JOIN LATERAL (
                    SELECT rrd.entry_course, rrd.rank
                    FROM boat_race.race_result_detail rrd
                    WHERE rrd.race_code = re.race_code
                      AND rrd.player_id = re.player_id
                    LIMIT 1
                ) rd ON TRUE
                LEFT JOIN LATERAL (
                    SELECT el.entry_course
                    FROM boat_race.exhibition_live el
                    WHERE el.race_code = re.race_code
                      AND el.player_id = re.player_id
                      AND el.entry_course BETWEEN 1 AND 6
                    LIMIT 1
                ) ex ON TRUE
                WHERE re.player_id::text = ?
                  AND re.race_code >= ?
                  AND re.race_code < ?
            )
            SELECT
                COUNT(*) FILTER (
                    WHERE completed
                      AND actual_course = ?
                ) AS n6,
                COUNT(*) FILTER (
                    WHERE completed
                      AND actual_course = ?
                      AND rank_num BETWEEN 1 AND 3
                ) AS top3_6,
                COUNT(*) FILTER (
                    WHERE completed
                      AND actual_course = ?
                      AND race_code >= ?
                ) AS n3,
                COUNT(*) FILTER (
                    WHERE completed
                      AND actual_course = ?
                      AND rank_num BETWEEN 1 AND 3
                      AND race_code >= ?
                ) AS top3_3
            FROM hist
        SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $playerId,
            $cut6,
            $targetRaceCode,
            $targetCourse,
            $targetCourse,
            $targetCourse,
            $cut3,
            $targetCourse,
            $cut3,
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
