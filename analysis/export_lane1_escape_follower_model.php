<?php

declare(strict_types=1);

/**
 * 過去のkimarite_analysis_dataset CSVから、
 * 「1コース逃げ成立時」の場別2着/3着コース順位を本番用PHP設定へ固定出力する。
 *
 * Usage:
 * php analysis/export_lane1_escape_follower_model.php \
 *   analysis/output/kimarite_analysis_dataset_20250815_20260814.csv \
 *   config/lane1_escape_follower_model.php
 */

if ($argc < 2 || $argc > 3) {
    fwrite(STDERR, "Usage: php {$argv[0]} INPUT_CSV [OUTPUT_PHP]\n");
    exit(1);
}

$input = $argv[1];
$output = $argv[2] ?? (__DIR__ . '/../config/lane1_escape_follower_model.php');

if (!is_file($input)) {
    throw new RuntimeException("入力CSVがありません: {$input}");
}

function readCsvAssoc(string $path): Generator
{
    $fp = fopen($path, 'rb');
    if ($fp === false) {
        throw new RuntimeException("CSVを開けません: {$path}");
    }

    $header = fgetcsv($fp);
    if ($header === false) {
        fclose($fp);
        return;
    }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);

    try {
        while (($cols = fgetcsv($fp)) !== false) {
            if (count($cols) !== count($header)) {
                continue;
            }
            yield array_combine($header, $cols);
        }
    } finally {
        fclose($fp);
    }
}

function intValue(array $row, string $key): int
{
    $v = $row[$key] ?? null;
    return is_numeric($v) ? (int)$v : 0;
}

function isFormalEscape(array $row): bool
{
    return intValue($row, 'result_top3_course_complete') === 1
        && intValue($row, 'result_boat_match') === 1
        && intValue($row, 'actual_1st_course') === 1
        && trim((string)($row['winner_technique'] ?? '')) === '逃げ';
}

function buildRank(array $counts): array
{
    $courses = [2, 3, 4, 5, 6];
    usort($courses, static function (int $a, int $b) use ($counts): int {
        $ca = (int)($counts[$a] ?? 0);
        $cb = (int)($counts[$b] ?? 0);
        if ($ca !== $cb) {
            return $cb <=> $ca;
        }
        return $a <=> $b;
    });

    $rank = [];
    foreach ($courses as $i => $course) {
        $rank[$course] = $i + 1;
    }
    ksort($rank);
    return $rank;
}

$secondCounts = [];
$thirdCounts = [];
$sampleN = [];
$startDate = null;
$endDate = null;
$totalRows = 0;
$totalEscape = 0;

foreach (readCsvAssoc($input) as $row) {
    $totalRows++;

    $date = trim((string)($row['race_date'] ?? ''));
    if ($date !== '') {
        $startDate = $startDate === null || $date < $startDate ? $date : $startDate;
        $endDate = $endDate === null || $date > $endDate ? $date : $endDate;
    }

    if (!isFormalEscape($row)) {
        continue;
    }

    $stadium = trim((string)($row['stadium_name'] ?? ''));
    if ($stadium === '') {
        continue;
    }

    $second = intValue($row, 'actual_2nd_course');
    $third = intValue($row, 'actual_3rd_course');
    if ($second < 2 || $second > 6 || $third < 2 || $third > 6) {
        continue;
    }

    $secondCounts[$stadium][$second] = ($secondCounts[$stadium][$second] ?? 0) + 1;
    $thirdCounts[$stadium][$third] = ($thirdCounts[$stadium][$third] ?? 0) + 1;
    $sampleN[$stadium] = ($sampleN[$stadium] ?? 0) + 1;
    $totalEscape++;
}

ksort($sampleN, SORT_NATURAL);
$model = [];
foreach ($sampleN as $stadium => $n) {
    $s2 = $secondCounts[$stadium] ?? [];
    $s3 = $thirdCounts[$stadium] ?? [];
    ksort($s2);
    ksort($s3);

    $model[$stadium] = [
        'n' => $n,
        'second_counts' => $s2,
        'third_counts' => $s3,
        'second_rank' => buildRank($s2),
        'third_rank' => buildRank($s3),
    ];
}

$payload = [
    'version' => 1,
    'source' => basename($input),
    'period_start' => $startDate,
    'period_end' => $endDate,
    'formal_escape_n' => $totalEscape,
    'stadium_count' => count($model),
    'rule' => 'final_rank順位 + 場別1逃げフォロワー順位（1:1順位和）',
    'stadiums' => $model,
];

$dir = dirname($output);
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    throw new RuntimeException("出力ディレクトリを作成できません: {$dir}");
}

$content = "<?php\n\n// 自動生成ファイル。手編集しない。\nreturn " . var_export($payload, true) . ";\n";
if (file_put_contents($output, $content) === false) {
    throw new RuntimeException("出力に失敗しました: {$output}");
}

echo str_repeat('=', 72) . PHP_EOL;
echo "1逃げ時 場別相手傾向モデル出力完了" . PHP_EOL;
echo str_repeat('=', 72) . PHP_EOL;
echo "入力       : {$input}" . PHP_EOL;
echo "期間       : " . ($startDate ?? '-') . " ～ " . ($endDate ?? '-') . PHP_EOL;
echo "CSV行      : {$totalRows}" . PHP_EOL;
echo "正式1逃げ  : {$totalEscape}R" . PHP_EOL;
echo "場数       : " . count($model) . PHP_EOL;
echo "出力       : {$output}" . PHP_EOL;
echo "固定方式   : final_rank順位 + 場別1逃げフォロワー順位（1:1順位和）" . PHP_EOL;
echo str_repeat('=', 72) . PHP_EOL;
