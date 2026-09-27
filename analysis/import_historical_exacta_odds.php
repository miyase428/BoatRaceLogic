<?php

declare(strict_types=1);

/**
 * 公式サイトに残る過去2連単オッズを、検証用DBへ取り込む。
 *
 * 締切前に保存した前方記録ではなく、公式に表示される確定後の最終オッズ。
 * 前方記録とは payload.odds_kind で明確に区別する。
 *
 * Usage:
 *   php analysis/import_historical_exacta_odds.php 2026-09-01 2026-09-21
 *   php analysis/import_historical_exacta_odds.php 2026-09-21 2026-09-21 --limit 3
 */

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../web/logic/OfficialExactaOddsLogic.php';
require_once __DIR__ . '/../web/logic/PredictionForwardSnapshotStore.php';

date_default_timezone_set('Asia/Tokyo');

function exactaHistoricalUsage(): never
{
    fwrite(STDERR, "Usage: php analysis/import_historical_exacta_odds.php YYYY-MM-DD YYYY-MM-DD [--limit N] [--sleep-ms N]\n");
    exit(2);
}

function exactaHistoricalValidDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

$start = trim((string)($argv[1] ?? ''));
$end = trim((string)($argv[2] ?? ''));
if (!exactaHistoricalValidDate($start) || !exactaHistoricalValidDate($end) || $start > $end) {
    exactaHistoricalUsage();
}
$limit = 0;
$sleepMs = 300;
for ($i = 3; $i < $argc; $i += 2) {
    $option = (string)($argv[$i] ?? '');
    $value = (string)($argv[$i + 1] ?? '');
    if (!ctype_digit($value)) {
        exactaHistoricalUsage();
    }
    if ($option === '--limit') {
        $limit = max(1, (int)$value);
    } elseif ($option === '--sleep-ms') {
        $sleepMs = min(10000, max(200, (int)$value));
    } else {
        exactaHistoricalUsage();
    }
}

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$store = new PredictionForwardSnapshotStore($pdo);
if (!$store->isReady()) {
    fwrite(STDERR, "前向き検証テーブルがありません。先に setup_prediction_forward_snapshots.php を実行してください。\n");
    exit(1);
}

$sql = <<<'SQL'
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
        AND s.validation_mode = 'late_replay'
        AND s.payload->>'source' = 'official_historical_final'
  )
ORDER BY e.race_code DESC
SQL;
if ($limit > 0) {
    $sql .= ' LIMIT ' . $limit;
}
$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':from_code' => str_replace('-', '', $start) . '00000',
    ':to_code' => str_replace('-', '', $end) . 'ZZZ99',
]);
$raceCodes = array_values(array_filter(array_map(
    static fn($v): string => strtoupper(trim((string)$v)),
    $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []
), static fn(string $code): bool => preg_match('/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $code) === 1));

if ($raceCodes === []) {
    echo "対象レースはありません\n";
    exit(0);
}

$logic = new OfficialExactaOddsLogic();
$saved = 0;
$waiting = 0;
printf("過去2連単オッズ取込: %s ～ %s / %dR\n", $start, $end, count($raceCodes));
foreach ($raceCodes as $index => $raceCode) {
    $data = $logic->load($raceCode, true);
    $count = (int)($data['count'] ?? 0);
    if (($data['status'] ?? '') === 'ok' && in_array($count, [20, 30], true)) {
        PredictionForwardSnapshotStore::captureHistoricalExactaOdds(
            $raceCode,
            substr($raceCode, 0, 4) . '-' . substr($raceCode, 4, 2) . '-' . substr($raceCode, 6, 2),
            $data,
            'official_historical_final',
            $pdo
        );
        $saved++;
        printf("  [%d/%d] OK %s (%d通り)\n", $index + 1, count($raceCodes), $raceCode, $count);
    } else {
        $waiting++;
        printf("  [%d/%d] SKIP %s: %s\n", $index + 1, count($raceCodes), $raceCode, (string)($data['error'] ?? 'オッズ未取得'));
    }
    usleep($sleepMs * 1000);
}

printf("完了: 保存=%d / 未取得=%d\n", $saved, $waiting);
