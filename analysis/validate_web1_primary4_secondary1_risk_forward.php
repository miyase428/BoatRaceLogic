<?php

declare(strict_types=1);

/**
 * Web本命①の危険候補を未使用前方期間で固定検証する。
 *
 * 固定候補（歴史診断後は変更しない）:
 *   Web本命① × 1号艇一次順位4位以下 × 1号艇二次順位1位
 *
 * 目的:
 *   - Web本命①全体より①失敗率が高いか
 *   - ①の完全飛び（実4着以下）が増えるか
 *   - 頭の差し替えは行わず、将来の「イン危険度」候補として再現性だけを見る
 *
 * Usage:
 * php analysis/validate_web1_primary4_secondary1_risk_forward.php \
 *   analysis/output/kimarite_analysis_dataset_20260815_20260822.csv \
 *   analysis/output/final_prediction_boats_fast_cached_20260815_20260822.csv
 */

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php {$argv[0]} DATASET_CSV BOATS_CSV\n");
    exit(1);
}

[$script, $datasetPath, $boatsPath] = $argv;
foreach ([$datasetPath, $boatsPath] as $p) {
    if (!is_file($p)) {
        throw new RuntimeException("必要ファイルがありません: {$p}");
    }
}

function readCsvAssoc(string $path): array
{
    $fp = fopen($path, 'rb');
    if ($fp === false) throw new RuntimeException("CSVを開けません: {$path}");
    $header = fgetcsv($fp);
    if ($header === false) {
        fclose($fp);
        return [];
    }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    $rows = [];
    while (($cols = fgetcsv($fp)) !== false) {
        if (count($cols) !== count($header)) continue;
        $rows[] = array_combine($header, $cols);
    }
    fclose($fp);
    return $rows;
}

function inum(array $row, string $key, int $default = 0): int
{
    $v = $row[$key] ?? null;
    return is_numeric($v) ? (int)$v : $default;
}

function pct(int $n, int $d): float
{
    return $d > 0 ? 100.0 * $n / $d : 0.0;
}

function formal(array $row): bool
{
    return inum($row, 'result_top3_course_complete') === 1
        && inum($row, 'result_boat_match') === 1;
}

function emptyStat(): array
{
    return [
        'n' => 0,
        'one_win' => 0,
        'one_fail' => 0,
        'one_remain' => 0,
        'one_out' => 0,
    ];
}

function addStat(array &$s, int $actualRank1): void
{
    if ($actualRank1 < 1 || $actualRank1 > 6) return;
    $s['n']++;
    if ($actualRank1 === 1) {
        $s['one_win']++;
        return;
    }
    $s['one_fail']++;
    if ($actualRank1 <= 3) {
        $s['one_remain']++;
    } else {
        $s['one_out']++;
    }
}

function statText(array $s): string
{
    $n = $s['n'];
    $fail = $s['one_fail'];
    return sprintf(
        'N=%4d | ①勝 %6.2f%% 失敗 %6.2f%% | 全体①飛 %6.2f%% | 敗戦時①残 %6.2f%% 飛 %6.2f%%',
        $n,
        pct($s['one_win'], $n),
        pct($fail, $n),
        pct($s['one_out'], $n),
        pct($s['one_remain'], $fail),
        pct($s['one_out'], $fail)
    );
}

$dataset = readCsvAssoc($datasetPath);
$boatRows = readCsvAssoc($boatsPath);

$boatsByRace = [];
foreach ($boatRows as $b) {
    $rc = trim((string)($b['race_code'] ?? ''));
    $lane = inum($b, 'lane_number');
    if ($rc === '' || $lane < 1 || $lane > 6) continue;
    $boatsByRace[$rc][$lane] = $b;
}

$base = emptyStat();
$trigger = emptyStat();
$dailyBase = [];
$dailyTrigger = [];
$formalN = 0;
$web1N = 0;
$dates = [];

foreach ($dataset as $row) {
    if (!formal($row)) continue;
    $formalN++;

    $date = trim((string)($row['race_date'] ?? ''));
    if ($date !== '') $dates[] = $date;

    // 現行productionでは、元Web頭①が別艇へ頭変更されるルールはない。
    if (inum($row, 'honmei_head') !== 1) continue;

    $rc = trim((string)($row['race_code'] ?? ''));
    $lane1 = $boatsByRace[$rc][1] ?? null;
    if (!is_array($lane1)) continue;

    $actualRank1 = inum($lane1, 'actual_rank', 0);
    if ($actualRank1 < 1 || $actualRank1 > 6) continue;

    $web1N++;
    addStat($base, $actualRank1);
    if (!isset($dailyBase[$date])) $dailyBase[$date] = emptyStat();
    addStat($dailyBase[$date], $actualRank1);

    $firstRank = inum($lane1, 'first_rank', 99);
    $secondRank = inum($lane1, 'second_rank', 99);

    // 凍結候補: Web本命① × ①一次4位以下 × ①二次1位
    if ($firstRank < 4 || $secondRank !== 1) continue;

    addStat($trigger, $actualRank1);
    if (!isset($dailyTrigger[$date])) $dailyTrigger[$date] = emptyStat();
    addStat($dailyTrigger[$date], $actualRank1);
}

sort($dates);
$start = $dates[0] ?? '-';
$end = $dates ? $dates[count($dates)-1] : '-';

$baseFail = pct($base['one_fail'], $base['n']);
$triggerFail = pct($trigger['one_fail'], $trigger['n']);
$baseOut = pct($base['one_out'], $base['n']);
$triggerOut = pct($trigger['one_out'], $trigger['n']);

echo str_repeat('=', 180) . "\n";
echo "Web本命① × 一次4位以下 × 二次1位 危険候補 前方ホールドアウト\n";
echo str_repeat('=', 180) . "\n";
echo "期間: {$start} ～ {$end}\n";
echo "正式対象: {$formalN}R / Web本命①分析可能: {$web1N}R\n";
echo "固定条件: Web本命① × 1号艇一次4位以下 × 1号艇二次1位\n";
echo "※ 場名・場1C強さ・攻め率などの条件は追加しない。頭差し替えもしない。\n\n";

echo "BASE    " . statText($base) . "\n";
echo "TRIGGER " . statText($trigger) . "\n";
printf(
    "差       失敗率 %+6.2fpt | 全体①飛率 %+6.2fpt\n",
    $triggerFail - $baseFail,
    $triggerOut - $baseOut
);

echo "\n【日別参考】\n";
$allDates = array_values(array_unique($dates));
foreach ($allDates as $date) {
    $b = $dailyBase[$date] ?? emptyStat();
    $t = $dailyTrigger[$date] ?? emptyStat();
    printf(
        "%s | BASE N=%3d 失敗=%5.1f%% 飛=%5.1f%% | TRIGGER N=%3d 失敗=%5.1f%% 飛=%5.1f%%\n",
        $date,
        $b['n'], pct($b['one_fail'], $b['n']), pct($b['one_out'], $b['n']),
        $t['n'], pct($t['one_fail'], $t['n']), pct($t['one_out'], $t['n'])
    );
}

echo "\n判断ポイント:\n";
echo "1. 最優先はTRIGGERの①失敗率が同期間BASEより高いか。\n";
echo "2. 次に『全体①飛率』もBASEより高いか。これは穴目・イン危険度との接続に重要。\n";
echo "3. Nが少なくても、この結果を見て場・閾値・別条件を追加しない。\n";
echo "4. 通過しても本命①を別艇へ差し替えない。危険警報・買い方側の候補として扱う。\n";
echo "5. 前方で逆転または差が消えるなら、この候補は本番化しない。\n";
