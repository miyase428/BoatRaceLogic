<?php
declare(strict_types=1);

require_once __DIR__ . '/../../common/db_connect.php';

/**
 * 直近60Rキャッシュの対象開催日だけを軽量確認する。
 *
 * レース画面表示時に毎回60Rを再計算しないため、
 * 「結果が12R揃った直近5開催日」がキャッシュ作成時から変わった時だけ
 * 既存キャッシュを破棄し、次の load() で再計算させる。
 */
class RecentPredictionHistoryFreshness
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = getPDO();
    }

    public function invalidateIfDatesChanged(string $placeCode, string $endDate): bool
    {
        $placeCode = strtoupper(trim($placeCode));
        $endDate = trim($endDate);

        if (!preg_match('/^[A-Z0-9]{3}$/', $placeCode)) {
            return false;
        }
        if (!$this->isValidDate($endDate)) {
            return false;
        }

        $currentDates = $this->loadRecentCompletedDates($placeCode, $endDate);
        if (empty($currentDates)) {
            // DB取得途中などで一時的に0件になった時に、正常キャッシュまで消さない。
            return false;
        }

        $safeDate = str_replace('-', '', $endDate);
        $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'boatrace_recent_prediction_history';
        $pattern = $dir . DIRECTORY_SEPARATOR . $placeCode . '_' . $safeDate . '_*.json';
        $files = glob($pattern) ?: [];
        $invalidated = false;

        foreach ($files as $path) {
            if (!is_file($path)) {
                continue;
            }

            $cached = json_decode((string)@file_get_contents($path), true);
            if (!is_array($cached) || ($cached['status'] ?? '') !== 'ok') {
                continue;
            }

            $cachedDates = array_values(array_map(
                static fn($value): string => (string)$value,
                is_array($cached['dates'] ?? null) ? $cached['dates'] : []
            ));

            if ($cachedDates === $currentDates) {
                continue;
            }

            if (@unlink($path)) {
                $invalidated = true;
            }
        }

        return $invalidated;
    }

    private function loadRecentCompletedDates(string $placeCode, string $endDate): array
    {
        $sql = <<<'SQL'
WITH completed_races AS (
    SELECT
        rm.race_code,
        rm.race_date
    FROM boat_race.race_master rm
    JOIN boat_race.race_result_detail rrd
      ON rrd.race_code = rm.race_code
    WHERE SUBSTRING(rm.race_code, 9, 3) = :place_code
      AND rm.race_date <= :end_date::date
      AND NULLIF(regexp_replace(rm.race_number, '[^0-9]', '', 'g'), '')::int BETWEEN 1 AND 12
    GROUP BY rm.race_code, rm.race_date, rm.race_number
    HAVING COUNT(DISTINCT CASE
        WHEN rrd.rank IN ('1', '2', '3') THEN rrd.rank
        ELSE NULL
    END) = 3
)
SELECT race_date
FROM completed_races
GROUP BY race_date
HAVING COUNT(*) = 12
ORDER BY race_date DESC
LIMIT 5
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':place_code' => $placeCode,
            ':end_date' => $endDate,
        ]);

        return array_values(array_map(
            static fn($value): string => (string)$value,
            $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []
        ));
    }

    private function isValidDate(string $date): bool
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $dt !== false && $dt->format('Y-m-d') === $date;
    }
}
