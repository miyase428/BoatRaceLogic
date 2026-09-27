<?php

declare(strict_types=1);

/**
 * 当日夜の補完用: 日中に一度も表示記録されなかった、終了済みレースだけの
 * 2連単オッズを低速で取得する。過去を遡る処理ではない。
 */

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../web/logic/OfficialExactaOddsLogic.php';
require_once __DIR__ . '/../web/logic/PredictionForwardSnapshotStore.php';

date_default_timezone_set('Asia/Tokyo');

const EXACTA_NIGHTLY_REQUEST_INTERVAL_MS = 1500;

$date = trim((string)($argv[1] ?? date('Y-m-d')));
$parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $date) {
    fwrite(STDERR, "Usage: php analysis/capture_same_day_missing_exacta_odds.php [YYYY-MM-DD]\n");
    exit(2);
}

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$store = new PredictionForwardSnapshotStore($pdo);
if (!$store->isReady()) {
    fwrite(STDERR, "前向き検証テーブルがありません\n");
    exit(1);
}

$stmt = $pdo->prepare(<<<'SQL'
SELECT DISTINCT e.race_code
FROM boat_race.race_entry e
JOIN boat_race.race_payouts p ON p.race_code = e.race_code
WHERE e.race_code >= :from_code
  AND e.race_code <= :to_code
  AND p.exacta_payout IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM boat_race.prediction_forward_snapshots s
      WHERE s.race_code = e.race_code
        AND s.stage = 'exhibition'
        AND s.component = 'exacta_odds'
  )
ORDER BY e.race_code
SQL);
$prefix = str_replace('-', '', $date);
$stmt->execute([':from_code' => $prefix . '00000', ':to_code' => $prefix . 'ZZZ99']);
$raceCodes = array_values(array_filter(array_map(
    static fn($v): string => strtoupper(trim((string)$v)),
    $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []
), static fn(string $code): bool => preg_match('/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $code) === 1));

printf("当日夜2連単オッズ補完: %s / 未記録=%dR / 間隔=%.1f秒\n", $date, count($raceCodes), EXACTA_NIGHTLY_REQUEST_INTERVAL_MS / 1000);
$logic = new OfficialExactaOddsLogic();
$saved = 0;
$skipped = 0;
foreach ($raceCodes as $index => $raceCode) {
    $data = $logic->load($raceCode, true);
    if (($data['status'] ?? '') === 'ok' && in_array((int)($data['count'] ?? 0), [20, 30], true)) {
        PredictionForwardSnapshotStore::captureHistoricalExactaOdds(
            $raceCode,
            $date,
            $data,
            'nightly_same_day_missing_final',
            $pdo
        );
        $saved++;
        printf("  [%d/%d] OK %s\n", $index + 1, count($raceCodes), $raceCode);
    } else {
        $skipped++;
        printf("  [%d/%d] SKIP %s: %s\n", $index + 1, count($raceCodes), $raceCode, (string)($data['error'] ?? '未取得'));
    }
    usleep(EXACTA_NIGHTLY_REQUEST_INTERVAL_MS * 1000);
}
printf("完了: 保存=%d / 未取得=%d\n", $saved, $skipped);
