<?php

declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../web/logic/BaseWinRateLogic.php';

/**
 * race_entry は6艇揃っているが race_master がないレースを抽出し、
 * BaseWinRateLogic が race_code フォールバックで基本1着率を計算できるか確認する。
 *
 * Usage:
 *   php analysis/validate_base_winrate_master_fallback.php [FROM] [TO] [LIMIT]
 *
 * Example:
 *   php analysis/validate_base_winrate_master_fallback.php 2026-08-01 2026-09-03 100
 */

$from = trim((string)($argv[1] ?? '2026-08-01'));
$to = trim((string)($argv[2] ?? date('Y-m-d')));
$limit = (int)($argv[3] ?? 100);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    fwrite(STDERR, "FROM/TO は YYYY-MM-DD で指定してください。\n");
    exit(1);
}
if ($limit < 1 || $limit > 1000) {
    fwrite(STDERR, "LIMIT は 1～1000 で指定してください。\n");
    exit(1);
}

$pdo = getPDO();

$sql = <<<SQL
SELECT
    re.race_code,
    COUNT(*) AS entry_rows,
    COUNT(DISTINCT re.lane_number) AS lane_count
FROM boat_race.race_entry re
LEFT JOIN boat_race.race_master rm
  ON rm.race_code = re.race_code
WHERE rm.race_code IS NULL
  AND re.race_code ~ '^\\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$'
  AND TO_DATE(SUBSTRING(re.race_code, 1, 8), 'YYYYMMDD') BETWEEN :from AND :to
GROUP BY re.race_code
HAVING COUNT(*) = 6
   AND COUNT(DISTINCT re.lane_number) = 6
ORDER BY re.race_code DESC
LIMIT {$limit}
SQL;

$stmt = $pdo->prepare($sql);
$stmt->execute([':from' => $from, ':to' => $to]);
$targets = $stmt->fetchAll(PDO::FETCH_ASSOC);

printf("%s\n", str_repeat('=', 118));
printf("基本1着率 race_master欠損フォールバック 回帰検証\n");
printf("期間: %s ～ %s / 最大 %dR\n", $from, $to, $limit);
printf("対象条件: race_entry=6艇・lane=6種・race_masterなし\n");
printf("%s\n", str_repeat('=', 118));

if (!$targets) {
    echo "対象レース: 0R\n";
    echo "現在のDBには指定期間内で検証できる race_master 欠損レースがありません。\n";
    echo "※ 本体 BaseWinRateLogic は race_entry 主体 + race_code日付フォールバック実装済みです。\n";
    exit(0);
}

$logic = new BaseWinRateLogic();
$ok = 0;
$ng = 0;

foreach ($targets as $row) {
    $raceCode = (string)$row['race_code'];
    $result = $logic->calculate($raceCode);
    $boats = $result['boats'] ?? [];
    $error = trim((string)($result['error'] ?? ''));
    $normalized = (float)($result['normalized_total'] ?? 0.0);

    $passed = count($boats) === 6
        && $error === ''
        && abs($normalized - 1.0) < 0.000001;

    if ($passed) {
        $ok++;
    } else {
        $ng++;
    }

    printf(
        "%s | %s | boats=%d normalized=%.6f%s\n",
        $raceCode,
        $passed ? 'OK' : 'NG',
        count($boats),
        $normalized,
        $error !== '' ? ' | error=' . $error : ''
    );
}

printf("\n%s\n", str_repeat('-', 118));
printf("対象=%dR / OK=%dR / NG=%dR\n", count($targets), $ok, $ng);

if ($ng === 0) {
    echo "判定: PASS - race_master欠損でも基本1着率を6艇100%で計算できています。\n";
    exit(0);
}

echo "判定: FAIL - NGレースを analysis/diagnose_base_winrate_target.php で個別確認してください。\n";
exit(2);
