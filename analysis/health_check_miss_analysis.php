<?php

declare(strict_types=1);

/**
 * 現行最終予想 健康診断 STEP 2-3
 *
 * 本命・対抗・買い目の取りこぼし分析
 *
 * Usage:
 *   php analysis/health_check_miss_analysis.php \
 *     boats.csv \
 *     races.csv
 */

if ($argc < 3) {
    echo "Usage:\n";
    echo "  php analysis/health_check_miss_analysis.php <boats_csv> <races_csv>\n";
    exit(1);
}

$boatsCsv = $argv[1];
$racesCsv = $argv[2];

if (!file_exists($boatsCsv)) {
    echo "艇別CSVが見つかりません: {$boatsCsv}\n";
    exit(1);
}

if (!file_exists($racesCsv)) {
    echo "レース別CSVが見つかりません: {$racesCsv}\n";
    exit(1);
}

/**
 * CSV読み込み
 */
function readCsv(string $path): array
{
    $handle = fopen($path, 'r');

    if ($handle === false) {
        throw new RuntimeException("CSVを開けません: {$path}");
    }

    $header = fgetcsv($handle);

    if ($header === false) {
        fclose($handle);
        return [];
    }

    // UTF-8 BOM除去
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

    $rows = [];

    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) < count($header)) {
            continue;
        }

        $data = [];

        foreach ($header as $i => $name) {
            $data[$name] = $row[$i] ?? '';
        }

        $rows[] = $data;
    }

    fclose($handle);

    return $rows;
}

/**
 * 買い目を展開
 *
 * 例:
 *   1-235-2345
 *
 * ↓
 *   1-2-3
 *   1-2-4
 *   ...
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
 * 3連単を配列化
 *
 * 例:
 *   1-2-3
 *
 * ↓
 *   [1, 2, 3]
 */
function parseTrifecta(string $trifecta): array
{
    $parts = explode('-', trim($trifecta));

    if (count($parts) !== 3) {
        return [];
    }

    return [
        (int)$parts[0],
        (int)$parts[1],
        (int)$parts[2],
    ];
}

/**
 * 順位取得
 */
function getActualRank(array $boats, int $lane): ?int
{
    foreach ($boats as $boat) {
        if ((int)$boat['lane_number'] === $lane) {
            $rank = trim($boat['actual_rank']);

            if ($rank === '') {
                return null;
            }

            if (is_numeric($rank)) {
                return (int)$rank;
            }
        }
    }

    return null;
}

/**
 * CSV読み込み
 */
$boats = readCsv($boatsCsv);
$races = readCsv($racesCsv);

/**
 * 艇別データを race_code ごとに整理
 */
$boatsByRace = [];

foreach ($boats as $boat) {
    $raceCode = trim($boat['race_code']);

    if ($raceCode === '') {
        continue;
    }

    $boatsByRace[$raceCode][] = $boat;
}

/**
 * 集計
 */
$total = 0;

$bothTop3 = 0;
$honmeiOnlyTop3 = 0;
$taikouOnlyTop3 = 0;
$bothOutsideTop3 = 0;

$bothTop3ButBetMiss = 0;

$honmei1stTaikouTop3 = 0;
$taikou1stHonmeiTop3 = 0;

$honmei1stTaikou2nd = 0;
$honmei1stTaikou3rd = 0;

$taikou1stHonmei2nd = 0;
$taikou1stHonmei3rd = 0;

$honmeiBetHit = 0;
$taikouBetHit = 0;
$eitherBetHit = 0;

$missExamples = [];

/**
 * レース単位で分析
 */
foreach ($races as $race) {

    $raceCode = trim($race['race_code']);

    if ($raceCode === '') {
        continue;
    }

    $actual = parseTrifecta($race['actual_trifecta']);

    if (count($actual) !== 3) {
        continue;
    }

    $honmei = (int)$race['honmei_head'];
    $taikou = (int)$race['taikou_head'];

    if ($honmei < 1 || $taikou < 1) {
        continue;
    }

    $total++;

    /**
     * 実際の着順
     */
    $boatsForRace = $boatsByRace[$raceCode] ?? [];

    $honmeiRank = getActualRank($boatsForRace, $honmei);
    $taikouRank = getActualRank($boatsForRace, $taikou);

    if ($honmeiRank === null || $taikouRank === null) {
        continue;
    }

    $honmeiTop3 = $honmeiRank <= 3;
    $taikouTop3 = $taikouRank <= 3;

    /**
     * ① 両方3着以内
     */
    if ($honmeiTop3 && $taikouTop3) {
        $bothTop3++;
    }

    /**
     * ② 本命だけ3着以内
     */
    if ($honmeiTop3 && !$taikouTop3) {
        $honmeiOnlyTop3++;
    }

    /**
     * ③ 対抗だけ3着以内
     */
    if (!$honmeiTop3 && $taikouTop3) {
        $taikouOnlyTop3++;
    }

    /**
     * ④ 両方3着外
     */
    if (!$honmeiTop3 && !$taikouTop3) {
        $bothOutsideTop3++;
    }

    /**
     * 着順パターン
     */
    if ($honmeiRank === 1 && $taikouTop3) {
        $honmei1stTaikouTop3++;

        if ($taikouRank === 2) {
            $honmei1stTaikou2nd++;
        }

        if ($taikouRank === 3) {
            $honmei1stTaikou3rd++;
        }
    }

    if ($taikouRank === 1 && $honmeiTop3) {
        $taikou1stHonmeiTop3++;

        if ($honmeiRank === 2) {
            $taikou1stHonmei2nd++;
        }

        if ($honmeiRank === 3) {
            $taikou1stHonmei3rd++;
        }
    }

    /**
     * 現行買い目
     */
    $honmeiBets = expandTrifecta($race['honmei_kai']);
    $taikouBets = expandTrifecta($race['taikou_kai']);

    $actualTrifecta = trim($race['actual_trifecta']);

    $honmeiHit = in_array($actualTrifecta, $honmeiBets, true);
    $taikouHit = in_array($actualTrifecta, $taikouBets, true);

    if ($honmeiHit) {
        $honmeiBetHit++;
    }

    if ($taikouHit) {
        $taikouBetHit++;
    }

    if ($honmeiHit || $taikouHit) {
        $eitherBetHit++;
    }

    /**
     * ⑤ 本命・対抗とも3着以内なのに
     *    買い目で取り逃したケース
     */
    if ($honmeiTop3 && $taikouTop3 && !$honmeiHit && !$taikouHit) {

        $bothTop3ButBetMiss++;

        if (count($missExamples) < 20) {
            $missExamples[] = [
                'race_code' => $raceCode,
                'honmei' => $honmei,
                'taikou' => $taikou,
                'honmei_rank' => $honmeiRank,
                'taikou_rank' => $taikouRank,
                'actual' => $actualTrifecta,
                'honmei_kai' => $race['honmei_kai'],
                'taikou_kai' => $race['taikou_kai'],
            ];
        }
    }
}

/**
 * 割合表示
 */
function rate(int $value, int $total): string
{
    if ($total === 0) {
        return '0.00%';
    }

    return number_format(($value / $total) * 100, 2) . '%';
}

/**
 * 表示
 */
echo PHP_EOL;
echo "========================================" . PHP_EOL;
echo "現行最終予想 健康診断 STEP 2-3" . PHP_EOL;
echo "本命・対抗・買い目 取りこぼし分析" . PHP_EOL;
echo "========================================" . PHP_EOL;

echo "対象レース : {$total}" . PHP_EOL;

echo PHP_EOL;
echo "========================================" . PHP_EOL;
echo "本命・対抗の3着以内分析" . PHP_EOL;
echo "========================================" . PHP_EOL;

echo "本命・対抗とも3着以内 : {$bothTop3} / {$total} (" .
    rate($bothTop3, $total) . ")" . PHP_EOL;

echo "本命だけ3着以内       : {$honmeiOnlyTop3} / {$total} (" .
    rate($honmeiOnlyTop3, $total) . ")" . PHP_EOL;

echo "対抗だけ3着以内       : {$taikouOnlyTop3} / {$total} (" .
    rate($taikouOnlyTop3, $total) . ")" . PHP_EOL;

echo "本命・対抗とも3着外   : {$bothOutsideTop3} / {$total} (" .
    rate($bothOutsideTop3, $total) . ")" . PHP_EOL;

echo PHP_EOL;
echo "========================================" . PHP_EOL;
echo "本命・対抗の着順パターン" . PHP_EOL;
echo "========================================" . PHP_EOL;

echo "本命1着＋対抗2着 : {$honmei1stTaikou2nd} / {$total} (" .
    rate($honmei1stTaikou2nd, $total) . ")" . PHP_EOL;

echo "本命1着＋対抗3着 : {$honmei1stTaikou3rd} / {$total} (" .
    rate($honmei1stTaikou3rd, $total) . ")" . PHP_EOL;

echo "対抗1着＋本命2着 : {$taikou1stHonmei2nd} / {$total} (" .
    rate($taikou1stHonmei2nd, $total) . ")" . PHP_EOL;

echo "対抗1着＋本命3着 : {$taikou1stHonmei3rd} / {$total} (" .
    rate($taikou1stHonmei3rd, $total) . ")" . PHP_EOL;

echo PHP_EOL;
echo "========================================" . PHP_EOL;
echo "買い目の取りこぼし" . PHP_EOL;
echo "========================================" . PHP_EOL;

echo "本命買い目 的中 : {$honmeiBetHit} / {$total} (" .
    rate($honmeiBetHit, $total) . ")" . PHP_EOL;

echo "対抗買い目 的中 : {$taikouBetHit} / {$total} (" .
    rate($taikouBetHit, $total) . ")" . PHP_EOL;

echo "どちらか的中   : {$eitherBetHit} / {$total} (" .
    rate($eitherBetHit, $total) . ")" . PHP_EOL;

echo PHP_EOL;

echo "本命・対抗とも3着以内なのに" . PHP_EOL;
echo "買い目で取り逃したレース : {$bothTop3ButBetMiss} / {$bothTop3} (" .
    rate($bothTop3ButBetMiss, $bothTop3) . ")" . PHP_EOL;

echo PHP_EOL;
echo "========================================" . PHP_EOL;
echo "取りこぼし事例（最大20件）" . PHP_EOL;
echo "========================================" . PHP_EOL;

if (count($missExamples) === 0) {

    echo "該当なし" . PHP_EOL;

} else {

    foreach ($missExamples as $example) {

        echo PHP_EOL;
        echo "race_code : {$example['race_code']}" . PHP_EOL;
        echo "本命      : {$example['honmei']}着順={$example['honmei_rank']}" . PHP_EOL;
        echo "対抗      : {$example['taikou']}着順={$example['taikou_rank']}" . PHP_EOL;
        echo "実際      : {$example['actual'][0]}-{$example['actual'][1]}-{$example['actual'][2]}" . PHP_EOL;
        echo "本命買い目: {$example['honmei_kai']}" . PHP_EOL;
        echo "対抗買い目: {$example['taikou_kai']}" . PHP_EOL;
    }
}

echo PHP_EOL;
echo "========================================" . PHP_EOL;
echo "STEP 2-3 健康診断 完了" . PHP_EOL;
echo "========================================" . PHP_EOL;