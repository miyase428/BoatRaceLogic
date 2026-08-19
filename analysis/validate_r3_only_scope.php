<?php
declare(strict_types=1);

/**
 * R3_ONLY適用範囲の最終比較。
 *
 * 比較:
 *   CURRENT      : 旧CSVの本命買い目 + 旧CSVの対抗買い目
 *   BOTH         : 新CSVの本命買い目 + 新CSVの対抗買い目
 *   HONMEI_ONLY  : 新CSVの本命買い目 + 旧CSVの対抗買い目
 *
 * 前提:
 *   - 旧CSVはR3_ONLY導入直前に退避したレース別CSV
 *   - 新CSVはR3_ONLY導入後に同期間を再出力したレース別CSV
 *   - R3_ONLYは頭順位を変えず、kiruだけを解除するため、
 *     HONMEI_ONLYは「新しい本命買い目」と「旧対抗買い目」を組み合わせて再現する。
 *
 * Usage:
 *   php analysis/validate_r3_only_scope.php \
 *     analysis/output/final_prediction_races_20260615_20260714_OLD.csv \
 *     analysis/output/final_prediction_races_20260615_20260714.csv \
 *     analysis/output/final_prediction_races_20260715_20260814_OLD.csv \
 *     analysis/output/final_prediction_races_20260715_20260814.csv
 */

if ($argc < 3 || (($argc - 1) % 2) !== 0) {
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php analysis/validate_r3_only_scope.php <old_races.csv> <new_races.csv> [old2.csv new2.csv ...]\n");
    exit(1);
}

require_once __DIR__ . '/../common/db_connect.php';
$pdo = getPDO();

$args = array_slice($argv, 1);
$pairs = array_chunk($args, 2);
$results = [];

foreach ($pairs as [$oldFile, $newFile]) {
    $result = validatePair($pdo, $oldFile, $newFile);
    printResult($result);
    $results[] = $result;
}

if (count($results) >= 2) {
    $pooled = poolResults($results);
    printResult($pooled, true);
}

function loadRaceCsv(string $file): array
{
    if (!is_file($file)) {
        throw new RuntimeException("CSVが見つかりません: {$file}");
    }

    $fp = fopen($file, 'rb');
    if ($fp === false) {
        throw new RuntimeException("CSVを開けません: {$file}");
    }

    $header = fgetcsv($fp);
    if ($header === false) {
        fclose($fp);
        throw new RuntimeException("CSVが空です: {$file}");
    }

    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);

    $map = [];
    foreach ($header as $i => $name) {
        $map[trim((string)$name)] = $i;
    }

    $required = [
        'race_code', 'honmei_head', 'taikou_head',
        'honmei_kai', 'taikou_kai', 'actual_trifecta'
    ];

    foreach ($required as $name) {
        if (!array_key_exists($name, $map)) {
            fclose($fp);
            throw new RuntimeException("必要な列がありません: {$name} ({$file})");
        }
    }

    $rows = [];

    while (($row = fgetcsv($fp)) !== false) {
        if (count($row) < count($header)) {
            continue;
        }

        $raceCode = trim((string)$row[$map['race_code']]);
        if ($raceCode === '') {
            continue;
        }

        $rows[$raceCode] = [
            'race_code' => $raceCode,
            'honmei_head' => (int)$row[$map['honmei_head']],
            'taikou_head' => (int)$row[$map['taikou_head']],
            'honmei_kai' => trim((string)$row[$map['honmei_kai']]),
            'taikou_kai' => trim((string)$row[$map['taikou_kai']]),
            'actual_trifecta' => trim((string)$row[$map['actual_trifecta']]),
        ];
    }

    fclose($fp);
    return $rows;
}

function expandTrifecta(string $formation): array
{
    $formation = trim($formation);
    if ($formation === '') {
        return [];
    }

    $parts = explode('-', $formation);
    if (count($parts) !== 3) {
        return [];
    }

    $first = str_split(trim($parts[0]));
    $second = str_split(trim($parts[1]));
    $third = str_split(trim($parts[2]));

    $bets = [];

    foreach ($first as $a) {
        foreach ($second as $b) {
            foreach ($third as $c) {
                if ($a === $b || $a === $c || $b === $c) {
                    continue;
                }
                $bets[] = "{$a}-{$b}-{$c}";
            }
        }
    }

    sort($bets);
    return array_values(array_unique($bets));
}

function makeStats(): array
{
    return [
        'races' => 0,
        'honmei_points' => 0,
        'honmei_hits' => 0,
        'honmei_investment' => 0,
        'honmei_payout' => 0,
        'taikou_points' => 0,
        'taikou_hits' => 0,
        'taikou_investment' => 0,
        'taikou_payout' => 0,
        'combined_points' => 0,
        'combined_hits' => 0,
        'combined_investment' => 0,
        'combined_payout' => 0,
    ];
}

function addRaceToStats(array &$stats, array $honmeiBets, array $taikouBets, string $actual, int $payout): void
{
    $combinedBets = array_values(array_unique(array_merge($honmeiBets, $taikouBets)));

    $stats['races']++;

    $stats['honmei_points'] += count($honmeiBets);
    $stats['honmei_investment'] += count($honmeiBets) * 100;
    if (in_array($actual, $honmeiBets, true)) {
        $stats['honmei_hits']++;
        $stats['honmei_payout'] += $payout;
    }

    $stats['taikou_points'] += count($taikouBets);
    $stats['taikou_investment'] += count($taikouBets) * 100;
    if (in_array($actual, $taikouBets, true)) {
        $stats['taikou_hits']++;
        $stats['taikou_payout'] += $payout;
    }

    $stats['combined_points'] += count($combinedBets);
    $stats['combined_investment'] += count($combinedBets) * 100;
    if (in_array($actual, $combinedBets, true)) {
        $stats['combined_hits']++;
        $stats['combined_payout'] += $payout;
    }
}

function validatePair(PDO $pdo, string $oldFile, string $newFile): array
{
    $oldRows = loadRaceCsv($oldFile);
    $newRows = loadRaceCsv($newFile);

    $stats = [
        'CURRENT' => makeStats(),
        'BOTH' => makeStats(),
        'HONMEI_ONLY' => makeStats(),
    ];

    $base = [
        'old_races' => count($oldRows),
        'new_races' => count($newRows),
        'common_races' => 0,
        'evaluated_races' => 0,
        'missing_new' => 0,
        'actual_missing' => 0,
        'actual_mismatch' => 0,
        'head_mismatch' => 0,
        'payout_missing' => 0,
    ];

    $payoutStmt = $pdo->prepare(
        'SELECT trifecta_payout
           FROM boat_race.race_payouts
          WHERE race_code = :race_code
          LIMIT 1'
    );

    foreach ($oldRows as $raceCode => $old) {
        if (!isset($newRows[$raceCode])) {
            $base['missing_new']++;
            continue;
        }

        $base['common_races']++;
        $new = $newRows[$raceCode];

        $oldActual = trim((string)$old['actual_trifecta']);
        $newActual = trim((string)$new['actual_trifecta']);

        if ($oldActual === '' || $newActual === '') {
            $base['actual_missing']++;
            continue;
        }

        if ($oldActual !== $newActual) {
            $base['actual_mismatch']++;
            continue;
        }

        if (
            (int)$old['honmei_head'] !== (int)$new['honmei_head']
            || (int)$old['taikou_head'] !== (int)$new['taikou_head']
        ) {
            $base['head_mismatch']++;
            continue;
        }

        $payoutStmt->execute([':race_code' => $raceCode]);
        $payoutRaw = $payoutStmt->fetchColumn();

        if ($payoutRaw === false || $payoutRaw === null || !is_numeric($payoutRaw)) {
            $base['payout_missing']++;
            continue;
        }

        $payout = (int)$payoutRaw;

        $oldHonmei = expandTrifecta((string)$old['honmei_kai']);
        $oldTaikou = expandTrifecta((string)$old['taikou_kai']);
        $newHonmei = expandTrifecta((string)$new['honmei_kai']);
        $newTaikou = expandTrifecta((string)$new['taikou_kai']);

        addRaceToStats($stats['CURRENT'], $oldHonmei, $oldTaikou, $oldActual, $payout);
        addRaceToStats($stats['BOTH'], $newHonmei, $newTaikou, $oldActual, $payout);
        addRaceToStats($stats['HONMEI_ONLY'], $newHonmei, $oldTaikou, $oldActual, $payout);

        $base['evaluated_races']++;
    }

    return [
        'label' => basename($oldFile) . ' → ' . basename($newFile),
        'base' => $base,
        'stats' => $stats,
    ];
}

function pct(int|float $num, int|float $den): float
{
    return $den > 0 ? ((float)$num * 100.0 / (float)$den) : 0.0;
}

function avg(int|float $num, int $den): float
{
    return $den > 0 ? ((float)$num / (float)$den) : 0.0;
}

function recovery(array $s, string $prefix): float
{
    return pct(
        (int)$s[$prefix . '_payout'],
        (int)$s[$prefix . '_investment']
    );
}

function printSection(string $title, string $prefix, array $stats): void
{
    $current = $stats['CURRENT'];
    $currentRecovery = recovery($current, $prefix);
    $currentAvgPoints = avg((int)$current[$prefix . '_points'], (int)$current['races']);

    echo "\n【{$title}】\n";
    printf(
        "%-12s %10s %11s %10s %14s %14s %10s %11s %11s\n",
        '方式', '対象R', '平均点数', '的中率', '購入金額', '払戻', '回収率', '回収率差', '点数差'
    );
    echo str_repeat('-', 124) . "\n";

    foreach (['CURRENT', 'BOTH', 'HONMEI_ONLY'] as $name) {
        $s = $stats[$name];
        $races = (int)$s['races'];
        $avgPoints = avg((int)$s[$prefix . '_points'], $races);
        $hitRate = pct((int)$s[$prefix . '_hits'], $races);
        $rec = recovery($s, $prefix);

        printf(
            "%-12s %10d %10.2f点 %9.2f%% %12s円 %12s円 %9.2f%% %+10.2fpt %+9.2f点\n",
            $name,
            $races,
            $avgPoints,
            $hitRate,
            number_format((int)$s[$prefix . '_investment']),
            number_format((int)$s[$prefix . '_payout']),
            $rec,
            $rec - $currentRecovery,
            $avgPoints - $currentAvgPoints
        );
    }

    echo "\n【CURRENT比の的中増減】\n";
    foreach (['BOTH', 'HONMEI_ONLY'] as $name) {
        $s = $stats[$name];
        printf(
            "%s : 的中 %d → %d (%+d件) / 投資 %+s円 / 払戻 %+s円\n",
            $name,
            (int)$current[$prefix . '_hits'],
            (int)$s[$prefix . '_hits'],
            (int)$s[$prefix . '_hits'] - (int)$current[$prefix . '_hits'],
            number_format((int)$s[$prefix . '_investment'] - (int)$current[$prefix . '_investment']),
            number_format((int)$s[$prefix . '_payout'] - (int)$current[$prefix . '_payout'])
        );
    }
}

function printResult(array $result, bool $pooled = false): void
{
    $base = $result['base'];

    echo "\n" . str_repeat('=', 124) . "\n";
    echo ($pooled ? 'POOLED：' : '') . "R3_ONLY 適用範囲比較（1点100円）\n";
    echo str_repeat('=', 124) . "\n";
    echo "対象                  : {$result['label']}\n";
    echo "旧CSVレース           : {$base['old_races']}\n";
    echo "新CSVレース           : {$base['new_races']}\n";
    echo "共通レース            : {$base['common_races']}\n";
    echo "評価可能              : {$base['evaluated_races']}\n";
    echo "新CSV不足             : {$base['missing_new']}\n";
    echo "実3連単不足           : {$base['actual_missing']}\n";
    echo "実3連単不一致         : {$base['actual_mismatch']}\n";
    echo "本命/対抗頭不一致     : {$base['head_mismatch']}\n";
    echo "払戻不足              : {$base['payout_missing']}\n";

    printSection('本命買い目', 'honmei', $result['stats']);
    printSection('対抗買い目', 'taikou', $result['stats']);
    printSection('本命＋対抗（重複除外）', 'combined', $result['stats']);

    echo "\n判断方針: HONMEI_ONLYで本命側の改善を維持しつつ、BOTHより総合回収率が改善するなら本命限定適用を採用候補とする。\n";
}

function poolResults(array $results): array
{
    $pooled = [
        'label' => 'POOLED',
        'base' => [
            'old_races' => 0,
            'new_races' => 0,
            'common_races' => 0,
            'evaluated_races' => 0,
            'missing_new' => 0,
            'actual_missing' => 0,
            'actual_mismatch' => 0,
            'head_mismatch' => 0,
            'payout_missing' => 0,
        ],
        'stats' => [
            'CURRENT' => makeStats(),
            'BOTH' => makeStats(),
            'HONMEI_ONLY' => makeStats(),
        ],
    ];

    foreach ($results as $result) {
        foreach ($pooled['base'] as $key => $_) {
            $pooled['base'][$key] += (int)($result['base'][$key] ?? 0);
        }

        foreach (['CURRENT', 'BOTH', 'HONMEI_ONLY'] as $scenario) {
            foreach ($pooled['stats'][$scenario] as $key => $_) {
                $pooled['stats'][$scenario][$key] += (int)($result['stats'][$scenario][$key] ?? 0);
            }
        }
    }

    return $pooled;
}
