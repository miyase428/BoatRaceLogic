<?php

declare(strict_types=1);

/**
 * 現行最終予想 健康診断 STEP 2-2
 *
 * 調査内容
 *  - 本命買い目の点数
 *  - 対抗買い目の点数
 *  - 重複除外後の実質購入点数
 *  - 的中率
 *  - 的中時払戻
 *  - 回収率
 *
 * Usage:
 *   php analysis/health_check_bet_efficiency.php \
 *     analysis/output/final_prediction_races_20260801_20260808.csv
 */

if ($argc < 2) {
    echo "Usage:\n";
    echo "  php health_check_bet_efficiency.php <races_csv>\n";
    exit(1);
}

$csvPath = $argv[1];

if (!file_exists($csvPath)) {
    echo "CSVが見つかりません: {$csvPath}\n";
    exit(1);
}

/**
 * DB接続
 */
require_once __DIR__ . '/../common/db_connect.php';

/**
 * CSV読み込み
 */
$handle = fopen($csvPath, 'r');

if ($handle === false) {
    echo "CSVを開けません: {$csvPath}\n";
    exit(1);
}

$header = fgetcsv($handle);

if ($header === false) {
    echo "CSVが空です。\n";
    exit(1);
}

/**
 * UTF-8 BOM除去
 */
$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

$headerMap = [];

foreach ($header as $index => $name) {
    $headerMap[$name] = $index;
}

$requiredColumns = [
    'race_code',
    'honmei_kai',
    'taikou_kai',
    'actual_trifecta',
];

foreach ($requiredColumns as $column) {
    if (!array_key_exists($column, $headerMap)) {
        echo "必要な列がありません: {$column}\n";
        exit(1);
    }
}

/**
 * race_code → 3連単払戻
 */
$payoutStmt = $pdo->prepare(
    'SELECT trifecta_payout
       FROM boat_race.race_payouts
      WHERE race_code = :race_code'
);

/**
 * 3連単買い目を展開
 *
 * 例:
 *   1-235-2345
 *
 * → 1-2-3
 *    1-2-4
 *    1-2-5
 *    1-3-2
 *    ...
 */
function expandTrifecta(string $bet): array
{
    $bet = trim($bet);

    if ($bet === '') {
        return [];
    }

    $parts = explode('-', $bet);

    if (count($parts) !== 3) {
        return [];
    }

    $first  = str_split(trim($parts[0]));
    $second = str_split(trim($parts[1]));
    $third  = str_split(trim($parts[2]));

    $bets = [];

    foreach ($first as $a) {
        foreach ($second as $b) {
            foreach ($third as $c) {

                // 同一艇が同じ買い目内に入るものは無効
                if ($a === $b || $a === $c || $b === $c) {
                    continue;
                }

                $bets[] = "{$a}-{$b}-{$c}";
            }
        }
    }

    return array_values(array_unique($bets));
}

/**
 * 集計値
 */
$totalRaces = 0;

$honmeiHit = 0;
$taikouHit = 0;
$eitherHit = 0;

$honmeiBetCountTotal = 0;
$taikouBetCountTotal = 0;
$combinedBetCountTotal = 0;

$honmeiInvestment = 0;
$taikouInvestment = 0;
$combinedInvestment = 0;

$honmeiPayout = 0;
$taikouPayout = 0;
$combinedPayout = 0;

$honmeiHitPayouts = [];
$taikouHitPayouts = [];
$combinedHitPayouts = [];

$rows = 0;

while (($row = fgetcsv($handle)) !== false) {

    if (count($row) < count($header)) {
        continue;
    }

    $rows++;

    $raceCode = trim($row[$headerMap['race_code']]);

    $honmeiKai = trim($row[$headerMap['honmei_kai']]);
    $taikouKai = trim($row[$headerMap['taikou_kai']]);

    $actualTrifecta = trim($row[$headerMap['actual_trifecta']]);

    if ($raceCode === '' || $actualTrifecta === '') {
        continue;
    }

    /**
     * 買い目展開
     */
    $honmeiBets = expandTrifecta($honmeiKai);
    $taikouBets = expandTrifecta($taikouKai);

    /**
     * 重複を除いた全買い目
     */
    $combinedBets = array_values(
        array_unique(
            array_merge($honmeiBets, $taikouBets)
        )
    );

    /**
     * 払戻取得
     */
    $payoutStmt->execute([
        ':race_code' => $raceCode,
    ]);

    $payout = $payoutStmt->fetchColumn();

    if ($payout === false || $payout === null) {
        continue;
    }

    $payout = (int)$payout;

    $totalRaces++;

    $honmeiCount = count($honmeiBets);
    $taikouCount = count($taikouBets);
    $combinedCount = count($combinedBets);

    $honmeiBetCountTotal += $honmeiCount;
    $taikouBetCountTotal += $taikouCount;
    $combinedBetCountTotal += $combinedCount;

    /**
     * 1点100円として計算
     */
    $honmeiInvestment += $honmeiCount * 100;
    $taikouInvestment += $taikouCount * 100;
    $combinedInvestment += $combinedCount * 100;

    /**
     * 的中判定
     */
    $honmeiIsHit = in_array($actualTrifecta, $honmeiBets, true);
    $taikouIsHit = in_array($actualTrifecta, $taikouBets, true);
    $combinedIsHit = in_array($actualTrifecta, $combinedBets, true);

    if ($honmeiIsHit) {
        $honmeiHit++;
        $honmeiPayout += $payout;
        $honmeiHitPayouts[] = $payout;
    }

    if ($taikouIsHit) {
        $taikouHit++;
        $taikouPayout += $payout;
        $taikouHitPayouts[] = $payout;
    }

    if ($combinedIsHit) {
        $eitherHit++;
        $combinedPayout += $payout;
        $combinedHitPayouts[] = $payout;
    }
}

fclose($handle);

/**
 * 平均値
 */
$avgHonmeiBets = $totalRaces > 0
    ? $honmeiBetCountTotal / $totalRaces
    : 0;

$avgTaikouBets = $totalRaces > 0
    ? $taikouBetCountTotal / $totalRaces
    : 0;

$avgCombinedBets = $totalRaces > 0
    ? $combinedBetCountTotal / $totalRaces
    : 0;

/**
 * 的中率
 */
$honmeiHitRate = $totalRaces > 0
    ? ($honmeiHit / $totalRaces) * 100
    : 0;

$taikouHitRate = $totalRaces > 0
    ? ($taikouHit / $totalRaces) * 100
    : 0;

$combinedHitRate = $totalRaces > 0
    ? ($eitherHit / $totalRaces) * 100
    : 0;

/**
 * 回収率
 */
$honmeiRecovery = $honmeiInvestment > 0
    ? ($honmeiPayout / $honmeiInvestment) * 100
    : 0;

$taikouRecovery = $taikouInvestment > 0
    ? ($taikouPayout / $taikouInvestment) * 100
    : 0;

$combinedRecovery = $combinedInvestment > 0
    ? ($combinedPayout / $combinedInvestment) * 100
    : 0;

/**
 * 的中時平均払戻
 */
$avgHonmeiPayout = count($honmeiHitPayouts) > 0
    ? array_sum($honmeiHitPayouts) / count($honmeiHitPayouts)
    : 0;

$avgTaikouPayout = count($taikouHitPayouts) > 0
    ? array_sum($taikouHitPayouts) / count($taikouHitPayouts)
    : 0;

$avgCombinedPayout = count($combinedHitPayouts) > 0
    ? array_sum($combinedHitPayouts) / count($combinedHitPayouts)
    : 0;

/**
 * 表示
 */
echo PHP_EOL;
echo "========================================" . PHP_EOL;
echo "現行最終予想 健康診断 STEP 2-2" . PHP_EOL;
echo "========================================" . PHP_EOL;

echo "対象CSV     : {$csvPath}" . PHP_EOL;
echo "対象レース  : {$totalRaces}" . PHP_EOL;

echo PHP_EOL;
echo "========================================" . PHP_EOL;
echo "本命買い目" . PHP_EOL;
echo "========================================" . PHP_EOL;

echo "平均購入点数 : " . number_format($avgHonmeiBets, 2) . " 点" . PHP_EOL;
echo "的中         : {$honmeiHit} / {$totalRaces} (" .
    number_format($honmeiHitRate, 2) . "%)" . PHP_EOL;
echo "購入金額     : " . number_format($honmeiInvestment) . " 円" . PHP_EOL;
echo "払戻         : " . number_format($honmeiPayout) . " 円" . PHP_EOL;
echo "回収率       : " . number_format($honmeiRecovery, 2) . "%" . PHP_EOL;
echo "的中時平均払戻: " . number_format($avgHonmeiPayout) . " 円" . PHP_EOL;

echo PHP_EOL;
echo "========================================" . PHP_EOL;
echo "対抗買い目" . PHP_EOL;
echo "========================================" . PHP_EOL;

echo "平均購入点数 : " . number_format($avgTaikouBets, 2) . " 点" . PHP_EOL;
echo "的中         : {$taikouHit} / {$totalRaces} (" .
    number_format($taikouHitRate, 2) . "%)" . PHP_EOL;
echo "購入金額     : " . number_format($taikouInvestment) . " 円" . PHP_EOL;
echo "払戻         : " . number_format($taikouPayout) . " 円" . PHP_EOL;
echo "回収率       : " . number_format($taikouRecovery, 2) . "%" . PHP_EOL;
echo "的中時平均払戻: " . number_format($avgTaikouPayout) . " 円" . PHP_EOL;

echo PHP_EOL;
echo "========================================" . PHP_EOL;
echo "本命＋対抗（重複除外）" . PHP_EOL;
echo "========================================" . PHP_EOL;

echo "平均購入点数 : " . number_format($avgCombinedBets, 2) . " 点" . PHP_EOL;
echo "的中         : {$eitherHit} / {$totalRaces} (" .
    number_format($combinedHitRate, 2) . "%)" . PHP_EOL;
echo "購入金額     : " . number_format($combinedInvestment) . " 円" . PHP_EOL;
echo "払戻         : " . number_format($combinedPayout) . " 円" . PHP_EOL;
echo "回収率       : " . number_format($combinedRecovery, 2) . "%" . PHP_EOL;
echo "的中時平均払戻: " . number_format($avgCombinedPayout) . " 円" . PHP_EOL;

echo PHP_EOL;
echo "========================================" . PHP_EOL;
echo "STEP 2-2 健康診断 完了" . PHP_EOL;
echo "========================================" . PHP_EOL;