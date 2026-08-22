<?php
declare(strict_types=1);

/**
 * kimarite の nige 定義だけを切り替えて本命への影響を比較する。
 *
 * A STRICT : nige = 本当の「逃げ」率（現行修正版）
 * B COMPAT : nige = win（旧互換の1コース1着率）
 *
 * 未来混入除去・一次評価・二次評価・STEP4は同じまま固定する。
 * DB/APIは呼ばず、修正版6か月CSVと point-in-time kimarite cache のみ使用する。
 *
 * 安全策:
 * - CSV上の final3 から現行 honmei_head を再現できるレースだけ採用。
 * - lane1 の保存済み type_bonus と cache course1 から再計算した STRICT bonus が
 *   一致するレースだけ採用。進入変更等で単純な lane1=course1 とみなせないものは除外。
 *
 * Usage:
 * php analysis/compare_kimarite_nige_semantics.php \
 *   analysis/output/final_prediction_boats_fast_cached_20260215_20260814.csv \
 *   analysis/output/final_prediction_races_fast_cached_20260215_20260814.csv \
 *   analysis/output/kimarite_cache_20260215_20260814.json
 */

function loadCsv(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException("CSVが見つかりません: {$path}");
    }
    $fp = fopen($path, 'rb');
    if ($fp === false) {
        throw new RuntimeException("CSVを開けません: {$path}");
    }
    $header = fgetcsv($fp);
    if ($header === false) {
        fclose($fp);
        throw new RuntimeException("CSVヘッダーを読めません: {$path}");
    }
    if (isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    }

    $rows = [];
    while (($row = fgetcsv($fp)) !== false) {
        if (count($row) < count($header)) {
            continue;
        }
        $x = [];
        foreach ($header as $i => $name) {
            $x[(string)$name] = $row[$i] ?? '';
        }
        $rows[] = $x;
    }
    fclose($fp);
    return $rows;
}

function toDec($value): float
{
    $f = (float)$value;
    return $f > 1.0 ? $f / 100.0 : $f;
}

/** course1 の typeBonus。course1では sashi/makuri/makurizashi は使わない。 */
function lane1Bonus(array $k, bool $compatWinAsNige): int
{
    $nige = toDec($compatWinAsNige ? ($k['win'] ?? 0) : ($k['nige'] ?? 0));
    $sasare = toDec($k['sasare'] ?? 0);
    $makurarezashi = toDec($k['makurarezashi'] ?? 0);

    if ($nige >= 0.2) {
        return 1; // 逃げ型
    }
    if ($sasare >= 0.2 || $makurarezashi >= 0.2) {
        return -1; // 脆い型
    }
    return 0; // 無色
}

/**
 * final3順位 + STEP4 で本命を再現する。
 * 同点は艇番昇順（元配列1..6の安定順）で扱う。
 */
function calcHead(array $boats, ?array $final3Override = null): int
{
    $rows = [];
    for ($lane = 1; $lane <= 6; $lane++) {
        if (!isset($boats[$lane])) {
            return 0;
        }
        $row = $boats[$lane];
        $rows[$lane] = [
            'lane' => $lane,
            'final3' => $final3Override[$lane] ?? (float)($row['final3'] ?? 0),
            'first' => (float)($row['first_total_score'] ?? 0),
            'second' => (float)($row['second_score'] ?? 0),
        ];
    }

    $finalSorted = array_values($rows);
    usort($finalSorted, static function (array $a, array $b): int {
        if ($a['final3'] == $b['final3']) {
            return $a['lane'] <=> $b['lane'];
        }
        return $a['final3'] < $b['final3'] ? 1 : -1;
    });
    $rankBoats = array_column($finalSorted, 'lane');

    $primary = array_values($rows);
    usort($primary, static function (array $a, array $b): int {
        if ($a['first'] == $b['first']) {
            return $a['lane'] <=> $b['lane'];
        }
        return $a['first'] < $b['first'] ? 1 : -1;
    });

    $secondary = array_values($rows);
    usort($secondary, static function (array $a, array $b): int {
        if ($a['second'] == $b['second']) {
            return $a['lane'] <=> $b['lane'];
        }
        return $a['second'] < $b['second'] ? 1 : -1;
    });

    $primaryGap = $primary[0]['first'] - $primary[1]['first'];
    $secondGap = $secondary[0]['second'] - $secondary[1]['second'];

    if (
        $primaryGap >= 5.0 && $primaryGap < 10.0
        && $secondGap >= 1.0 && $secondGap < 2.0
    ) {
        $p1 = (int)$primary[0]['lane'];
        if (($rankBoats[0] ?? 0) !== $p1) {
            $rankBoats = array_values(array_filter(
                $rankBoats,
                static fn($lane): bool => (int)$lane !== $p1
            ));
            array_unshift($rankBoats, $p1);
        }
    }

    return (int)($rankBoats[0] ?? 0);
}

function pct(int $n, int $d): string
{
    return $d > 0 ? number_format($n * 100.0 / $d, 2) . '%' : '-';
}

if ($argc < 4) {
    fwrite(STDERR,
        "Usage:\n" .
        "php analysis/compare_kimarite_nige_semantics.php 艇別CSV レース別CSV kimarite_cache.json\n"
    );
    exit(1);
}

$boatsPath = $argv[1];
$racesPath = $argv[2];
$cachePath = $argv[3];

try {
    $boatRows = loadCsv($boatsPath);
    $raceRows = loadCsv($racesPath);
    if (!is_file($cachePath)) {
        throw new RuntimeException("kimarite cacheが見つかりません: {$cachePath}");
    }
    $cache = json_decode((string)file_get_contents($cachePath), true);
    if (!is_array($cache)) {
        throw new RuntimeException("kimarite cacheのJSON形式が不正です");
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$boatsByRace = [];
foreach ($boatRows as $row) {
    $race = trim((string)($row['race_code'] ?? ''));
    $lane = (int)($row['lane_number'] ?? 0);
    if ($race === '' || $lane < 1 || $lane > 6) {
        continue;
    }
    $boatsByRace[$race][$lane] = $row;
}

$totalRaceRows = 0;
$completeBoats = 0;
$actualOk = 0;
$strictHeadReproduced = 0;
$lane1BonusMatched = 0;
$eligible = 0;
$bonusChanged = 0;
$headChanged = 0;

$strictHit = 0;
$compatHit = 0;
$strictOnly = 0;
$compatOnly = 0;
$bothWrongChanged = 0;

$direction = [];
$bonusTransitions = [];

$examples = [];

foreach ($raceRows as $raceRow) {
    $totalRaceRows++;
    $raceCode = trim((string)($raceRow['race_code'] ?? ''));
    if ($raceCode === '' || !isset($boatsByRace[$raceCode]) || count($boatsByRace[$raceCode]) !== 6) {
        continue;
    }
    $completeBoats++;

    $actual1 = (int)($raceRow['actual_1st'] ?? 0);
    $storedHead = (int)($raceRow['honmei_head'] ?? 0);
    if ($actual1 < 1 || $actual1 > 6 || $storedHead < 1 || $storedHead > 6) {
        continue;
    }
    $actualOk++;

    $boats = $boatsByRace[$raceCode];
    ksort($boats);

    // 保存済みSTRICT final3から現行本命を再現できるか。
    $strictHead = calcHead($boats);
    if ($strictHead !== $storedHead) {
        continue;
    }
    $strictHeadReproduced++;

    $k1 = $cache[$raceCode]['1']['6month'] ?? null;
    if (!is_array($k1)) {
        continue;
    }

    // lane1=course1 として扱えるレースだけに限定する。
    $strictBonusCalc = lane1Bonus($k1, false);
    $storedLane1Bonus = (int)round((float)($boats[1]['type_bonus'] ?? 0));
    if ($strictBonusCalc !== $storedLane1Bonus) {
        continue;
    }
    $lane1BonusMatched++;
    $eligible++;

    $compatBonus = lane1Bonus($k1, true);
    $transitionKey = $strictBonusCalc . '→' . $compatBonus;
    $bonusTransitions[$transitionKey] = ($bonusTransitions[$transitionKey] ?? 0) + 1;

    $override = null;
    if ($compatBonus !== $strictBonusCalc) {
        $bonusChanged++;
        $override = [];
        for ($lane = 1; $lane <= 6; $lane++) {
            $override[$lane] = (float)($boats[$lane]['final3'] ?? 0);
        }
        $override[1] = (float)($boats[1]['second_score'] ?? 0) + $compatBonus;
    }

    $compatHead = $override === null ? $strictHead : calcHead($boats, $override);

    $sHit = ($strictHead === $actual1);
    $cHit = ($compatHead === $actual1);
    if ($sHit) $strictHit++;
    if ($cHit) $compatHit++;

    if ($compatHead !== $strictHead) {
        $headChanged++;
        $key = $strictHead . '→' . $compatHead;
        if (!isset($direction[$key])) {
            $direction[$key] = ['n' => 0, 'strict' => 0, 'compat' => 0];
        }
        $direction[$key]['n']++;
        if ($sHit) $direction[$key]['strict']++;
        if ($cHit) $direction[$key]['compat']++;

        if ($sHit && !$cHit) {
            $strictOnly++;
        } elseif (!$sHit && $cHit) {
            $compatOnly++;
        } elseif (!$sHit && !$cHit) {
            $bothWrongChanged++;
        }

        if (count($examples) < 20) {
            $examples[] = [
                'race' => $raceCode,
                'strict' => $strictHead,
                'compat' => $compatHead,
                'actual' => $actual1,
                'nige' => (float)($k1['nige'] ?? 0),
                'win' => (float)($k1['win'] ?? 0),
                'bonus' => $transitionKey,
            ];
        }
    }
}

uasort($direction, static fn(array $a, array $b): int => $b['n'] <=> $a['n']);
arsort($bonusTransitions);

$strictRate = $eligible > 0 ? $strictHit * 100.0 / $eligible : 0.0;
$compatRate = $eligible > 0 ? $compatHit * 100.0 / $eligible : 0.0;
$diffPt = $compatRate - $strictRate;

$line = str_repeat('=', 118);
echo "\n{$line}\n";
echo "kimarite nige定義だけの切り分け検証（point-in-time固定）\n";
echo "{$line}\n";
echo "A STRICT : nige = 本当の逃げ率\n";
echo "B COMPAT : nige = win（旧互換1コース1着率）\n";
echo "艇別CSV   : {$boatsPath}\n";
echo "レースCSV : {$racesPath}\n";
echo "cache     : {$cachePath}\n";
echo str_repeat('-', 118) . "\n";
echo "レースCSV行                 : {$totalRaceRows}\n";
echo "6艇完備                     : {$completeBoats}\n";
echo "実1着・本命あり             : {$actualOk}\n";
echo "STRICT本命をCSVから再現      : {$strictHeadReproduced}\n";
echo "lane1 STRICT bonus一致       : {$lane1BonusMatched}\n";
echo "最終比較対象                 : {$eligible}\n";
echo "nige定義でlane1 bonus変化    : {$bonusChanged} / {$eligible} (" . pct($bonusChanged, $eligible) . ")\n";
echo "nige定義で本命まで変化       : {$headChanged} / {$eligible} (" . pct($headChanged, $eligible) . ")\n";
echo "{$line}\n\n";

echo "【本命1着率】\n";
echo "STRICT : {$strictHit} / {$eligible} (" . pct($strictHit, $eligible) . ")\n";
echo "COMPAT : {$compatHit} / {$eligible} (" . pct($compatHit, $eligible) . ")\n";
echo sprintf("差     : %+d件 / %+.2fpt\n", $compatHit - $strictHit, $diffPt);

echo "\n【本命が変わったレースだけ】\n";
echo "対象             : {$headChanged}\n";
echo "STRICTだけ正解   : {$strictOnly} (" . pct($strictOnly, $headChanged) . ")\n";
echo "COMPATだけ正解   : {$compatOnly} (" . pct($compatOnly, $headChanged) . ")\n";
echo "両方外れ         : {$bothWrongChanged} (" . pct($bothWrongChanged, $headChanged) . ")\n";
echo "純増減           : " . sprintf('%+d', $compatOnly - $strictOnly) . "件\n";

echo "\n【lane1 typeBonus遷移】\n";
echo str_pad('遷移', 12) . str_pad('N', 10, ' ', STR_PAD_LEFT) . "\n";
echo str_repeat('-', 24) . "\n";
foreach ($bonusTransitions as $key => $n) {
    echo str_pad($key, 12) . str_pad((string)$n, 10, ' ', STR_PAD_LEFT) . "\n";
}

echo "\n【本命変更方向】\n";
echo str_pad('STRICT→COMPAT', 18) . str_pad('N', 8, ' ', STR_PAD_LEFT)
    . str_pad('STRICT正解', 14, ' ', STR_PAD_LEFT)
    . str_pad('COMPAT正解', 14, ' ', STR_PAD_LEFT)
    . str_pad('純増減', 10, ' ', STR_PAD_LEFT) . "\n";
echo str_repeat('-', 64) . "\n";
foreach ($direction as $key => $x) {
    $delta = $x['compat'] - $x['strict'];
    echo str_pad($key, 18)
        . str_pad((string)$x['n'], 8, ' ', STR_PAD_LEFT)
        . str_pad((string)$x['strict'], 14, ' ', STR_PAD_LEFT)
        . str_pad((string)$x['compat'], 14, ' ', STR_PAD_LEFT)
        . str_pad(sprintf('%+d', $delta), 10, ' ', STR_PAD_LEFT)
        . "\n";
}

if ($examples) {
    echo "\n【本命変更例 最大20件】\n";
    echo "race_code       STRICT COMPAT 実1着   nige    win   bonus\n";
    echo str_repeat('-', 74) . "\n";
    foreach ($examples as $x) {
        echo sprintf(
            "%-15s %6d %6d %5d %6.1f %6.1f %7s\n",
            $x['race'], $x['strict'], $x['compat'], $x['actual'],
            $x['nige'], $x['win'], $x['bonus']
        );
    }
}

echo "\n{$line}\n";
echo "検証完了\n";
echo "{$line}\n\n";
