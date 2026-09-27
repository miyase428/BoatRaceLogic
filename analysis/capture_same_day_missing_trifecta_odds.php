<?php

declare(strict_types=1);

/**
 * 当日夜の補完用: 日中に一度も表示記録されなかった、終了済みレースだけの
 * 3連単締切時オッズを低速で取得する。過去を遡る処理ではない。
 */

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../web/logic/OfficialOddsLogic.php';
require_once __DIR__ . '/../web/logic/PredictionForwardSnapshotStore.php';

date_default_timezone_set('Asia/Tokyo');

const NIGHTLY_REQUEST_INTERVAL_MS = 1500;

$date = trim((string)($argv[1] ?? date('Y-m-d')));
$parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $date) {
    fwrite(STDERR, "Usage: php analysis/capture_same_day_missing_trifecta_odds.php [YYYY-MM-DD]\n");
    exit(2);
}

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$store = new PredictionForwardSnapshotStore($pdo);
if (!$store->isReady()) {
    fwrite(STDERR, "前向き検証テーブルがありません\n");
    exit(1);
}

// 払戻が確定したレースだけ。日中のuser_display記録済みレースは公式へ再アクセスしない。
$stmt = $pdo->prepare(<<<'SQL'
SELECT DISTINCT e.race_code
FROM boat_race.race_entry e
JOIN boat_race.race_payouts p ON p.race_code = e.race_code
WHERE e.race_code >= :from_code
  AND e.race_code <= :to_code
  AND p.trifecta_payout IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM boat_race.prediction_forward_snapshots s
      WHERE s.race_code = e.race_code
        AND s.stage = 'exhibition'
        AND s.component = 'trifecta_odds'
  )
ORDER BY e.race_code
SQL);
$prefix = str_replace('-', '', $date);
$stmt->execute([':from_code' => $prefix . '00000', ':to_code' => $prefix . 'ZZZ99']);
$raceCodes = array_values(array_filter(array_map(
    static fn($v): string => strtoupper(trim((string)$v)),
    $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []
), static fn(string $code): bool => preg_match('/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $code) === 1));

printf("当日夜オッズ補完: %s / 未記録=%dR / 間隔=%.1f秒\n", $date, count($raceCodes), NIGHTLY_REQUEST_INTERVAL_MS / 1000);
$logic = new OfficialOddsLogic();
$saved = 0;
$skipped = 0;
foreach ($raceCodes as $index => $raceCode) {
    $data = $logic->load($raceCode, true);
    if (($data['status'] ?? '') === 'ok' && in_array((int)($data['count'] ?? 0), [60, 120], true)) {
        PredictionForwardSnapshotStore::captureHistoricalTrifectaOdds(
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
    usleep(NIGHTLY_REQUEST_INTERVAL_MS * 1000);
}
printf("完了: 保存=%d / 未取得=%d\n", $saved, $skipped);
