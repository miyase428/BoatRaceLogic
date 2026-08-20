<?php

/**
 * 最終予想テーブル表示用の直近「枠番別」3連対率。
 *
 * 予想ロジックに使っている従来の three_in_rate_6m / 3m には触れず、
 * 「その選手が今回と同じ枠番を走った時」に限定した値を表示用に計算する。
 *
 * 外部サイトの「枠別情報 / 枠別勝率」と同じ軸に合わせるため、
 * 展示進入コースではなく race_entry.lane_number（枠番）で集計する。
 *
 * - 基準日は対象レース日（CURRENT_DATEではない）
 * - 対象レース自身と、それ以降の結果は除外
 * - 6ヶ月 / 3ヶ月をそれぞれ集計
 * - 分子/分母（3連対数 / 対象走数）も返す
 */
class RecentCourseTrioRateLogic
{
    /**
     * 第2引数は旧呼び出しとの互換用。枠番別集計では使用しない。
     */
    public function calculate(string $raceCode, array $courseByBoat = []): array
    {
        try {
            if ($raceCode === '') {
                throw new RuntimeException('直近枠別3連対率: race_codeが空です');
            }

            $pdo = getPDO();
            [$targetDate, $boats] = $this->loadTarget($pdo, $raceCode);

            $result = [];
            foreach ($boats as $boat => $row) {
                // 現在の艇番 = 今回の枠番。
                $targetFrame = $boat;
                $stats = $this->loadPlayerStats(
                    $pdo,
                    (string)$row['player_id'],
                    $targetFrame,
                    $targetDate,
                    $raceCode
                );

                $result[$boat] = [
                    'boat' => $boat,
                    'frame' => $targetFrame,
                    // 既存JSとの互換用。表示上は「枠」として扱う。
                    'course' => $targetFrame,
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
                'basis' => 'frame',
                'error' => '',
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'error',
                'boats' => [],
                'basis' => 'frame',
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
            throw new RuntimeException('直近枠別3連対率: 対象レースが6艇ではありません');
        }

        $targetDate = (string)$rows[0]['race_date'];
        $boats = [];
        foreach ($rows as $row) {
            $boat = (int)($row['lane_number'] ?? 0);
            if ($boat < 1 || $boat > 6) {
                throw new RuntimeException('直近枠別3連対率: 艇番が不正です');
            }
            $boats[$boat] = [
                'player_id' => (string)($row['player_id'] ?? ''),
                'player_name' => (string)($row['player_name'] ?? ''),
            ];
        }

        return [$targetDate, $boats];
    }

    private function loadPlayerStats(
        PDO $pdo,
        string $playerId,
        int $targetFrame,
        string $targetDate,
        string $targetRaceCode
    ): array {
        $sql = <<<SQL
            WITH hist AS (
                SELECT
                    rm.race_date,
                    re.lane_number AS frame_number,
                    CASE
                        WHEN rrd.rank::text ~ '^[1-6]$' THEN rrd.rank::int
                        ELSE NULL
                    END AS rank_num
                FROM boat_race.race_entry re
                JOIN boat_race.race_master rm
                  ON rm.race_code = re.race_code
                LEFT JOIN boat_race.race_result_detail rrd
                  ON rrd.race_code = re.race_code
                 AND rrd.player_id = re.player_id
                WHERE re.player_id::text = ?
                  AND (
                        rm.race_date < ?::date
                        OR (rm.race_date = ?::date AND re.race_code < ?)
                      )
                  AND rm.race_date >= ?::date - INTERVAL '6 months'
            )
            SELECT
                COUNT(*) FILTER (
                    WHERE frame_number = ?
                      AND rank_num BETWEEN 1 AND 6
                ) AS n6,
                COUNT(*) FILTER (
                    WHERE frame_number = ?
                      AND rank_num BETWEEN 1 AND 3
                ) AS top3_6,
                COUNT(*) FILTER (
                    WHERE frame_number = ?
                      AND rank_num BETWEEN 1 AND 6
                      AND race_date >= ?::date - INTERVAL '3 months'
                ) AS n3,
                COUNT(*) FILTER (
                    WHERE frame_number = ?
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
            $targetFrame,
            $targetFrame,
            $targetFrame,
            $targetDate,
            $targetFrame,
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
