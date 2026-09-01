<?php
declare(strict_types=1);

/**
 * 大村・下関の前半1～6R / 後半7～12Rについて、4～6C到達率を比較する。
 * 1逃げ成立時も同じ区分で確認する。
 *
 * Usage:
 *   php analysis/analyze_omura_shimonoseki_late_outer.php \
 *     analysis/output/kimarite_analysis_dataset_20250815_20260814.csv
 *
 * 表示・分析専用。PredictionLogicには接続しない。
 */

const TARGET_VENUES = ['大村', '下関'];
const OUTER_COURSES = [4, 5, 6];

function usage(): never
{
    fwrite(STDERR, "使用方法: php analysis/analyze_omura_shimonoseki_late_outer.php KIMARITE_DATASET_CSV\n");
    exit(1);
}

function readCsv(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException("CSVがありません: {$path}");
    }
    $fp = fopen($path, 'rb');
    if ($fp === false) {
        throw new RuntimeException("CSVを開けません: {$path}");
    }
    $header = fgetcsv($fp);
    if (!$header) {
        fclose($fp);
        throw new RuntimeException("CSVヘッダを読めません: {$path}");
    }
    if (isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    }
    $rows = [];
    while (($cols = fgetcsv($fp)) !== false) {
        if (count($cols) !== count($header)) continue;
        $rows[] = array_combine($header, $cols);
    }
    fclose($fp);
    return $rows;
}

function iv(array $row, string $key): int
{
    $v = trim((string)($row[$key] ?? ''));
    return preg_match('/^-?\d+$/', $v) ? (int)$v : 0;
}

function sv(array $row, string $key): string
{
    return trim((string)($row[$key] ?? ''));
}

function raceNo(array $row): int
{
    $raw = sv($row, 'race_number');
    if ($raw !== '' && preg_match('/(\d{1,2})/', $raw, $m)) {
        $n = (int)$m[1];
        if ($n >= 1 && $n <= 12) return $n;
    }

    $code = sv($row, 'race_code');
    if ($code !== '' && preg_match('/(\d{2})$/', $code, $m)) {
        $n = (int)$m[1];
        if ($n >= 1 && $n <= 12) return $n;
    }
    return 0;
}

function formal(array $row): bool
{
    return iv($row, 'result_top3_course_complete') === 1
        && iv($row, 'result_boat_match') === 1;
}

function pct(int $n, int $d): string
{
    return $d > 0 ? number_format($n * 100 / $d, 2) . '%' : '-';
}

function bucket(): array
{
    return ['n' => 0, 'first' => 0, 'second' => 0, 'third' => 0, 'top3' => 0];
}

function add(array &$b, int $course, int $c1, int $c2, int $c3): void
{
    $b['n']++;
    if ($c1 === $course) $b['first']++;
    if ($c2 === $course) $b['second']++;
    if ($c3 === $course) $b['third']++;
    if ($c1 === $course || $c2 === $course || $c3 === $course) $b['top3']++;
}

function fmt(array $b): string
{
    return sprintf(
        'N=%4d 1着=%6s 2着=%6s 3着=%6s 3連対=%6s',
        $b['n'], pct($b['first'], $b['n']), pct($b['second'], $b['n']),
        pct($b['third'], $b['n']), pct($b['top3'], $b['n'])
    );
}

[$script, $path] = array_pad($argv, 2, null);
if (!$path || count($argv) !== 2) usage();

$rows = readCsv($path);
$stats = [];
foreach (TARGET_VENUES as $venue) {
    foreach (['early', 'late'] as $half) {
        foreach (['all', 'escape'] as $ctx) {
            foreach (OUTER_COURSES as $course) {
                $stats[$venue][$half][$ctx][$course] = bucket();
            }
        }
    }
    $stats[$venue]['invalid_race_no'] = 0;
}

foreach ($rows as $row) {
    $venue = sv($row, 'stadium_name');
    if (!in_array($venue, TARGET_VENUES, true) || !formal($row)) continue;

    $rn = raceNo($row);
    if ($rn < 1 || $rn > 12) {
        $stats[$venue]['invalid_race_no']++;
        continue;
    }
    $half = $rn <= 6 ? 'early' : 'late';

    $c1 = iv($row, 'actual_1st_course');
    $c2 = iv($row, 'actual_2nd_course');
    $c3 = iv($row, 'actual_3rd_course');
    $escape = ($c1 === 1 && sv($row, 'winner_technique') === '逃げ');

    foreach (OUTER_COURSES as $course) {
        add($stats[$venue][$half]['all'][$course], $course, $c1, $c2, $c3);
        if ($escape) add($stats[$venue][$half]['escape'][$course], $course, $c1, $c2, $c3);
    }
}

echo str_repeat('=', 88) . PHP_EOL;
echo "大村・下関 前半1～6R vs 後半7～12R 外枠到達率" . PHP_EOL;
echo str_repeat('=', 88) . PHP_EOL;

foreach (TARGET_VENUES as $venue) {
    echo "\n【{$venue}】\n";
    foreach (['early' => '前半1～6R', 'late' => '後半7～12R'] as $half => $label) {
        echo "\n--- {$label} 全体 ---\n";
        foreach (OUTER_COURSES as $course) {
            echo "{$course}C  " . fmt($stats[$venue][$half]['all'][$course]) . PHP_EOL;
        }

        echo "\n--- {$label} 1逃げ成立時 ---\n";
        foreach (OUTER_COURSES as $course) {
            echo "{$course}C  " . fmt($stats[$venue][$half]['escape'][$course]) . PHP_EOL;
        }
    }
    echo "\nレース番号解釈不能: {$stats[$venue]['invalid_race_no']}R\n";
}

echo "\n" . str_repeat('=', 88) . PHP_EOL;
echo "※ race_numberが『7R』形式でも数値を抽出し、取れない場合はrace_code末尾2桁を使用。" . PHP_EOL;
echo "※ 表示・分析専用。PredictionLogicや買い目補正は変更しません。" . PHP_EOL;
echo str_repeat('=', 88) . PHP_EOL;
