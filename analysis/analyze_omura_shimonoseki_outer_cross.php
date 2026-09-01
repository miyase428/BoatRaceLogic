<?php
declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';

/**
 * 大村・下関の4～6コースについて、級別(A1)とWeb評価TOP3を交差分析する。
 *
 * 対象コンテキスト:
 * - 全レース
 * - 1逃げ成立時
 * - 後半7～12R
 * - 後半7～12Rかつ1逃げ成立時
 *
 * 条件:
 * - 全体
 * - A1
 * - 一次TOP3 / 二次TOP3 / 最終TOP3
 * - A1×一次TOP3 / A1×二次TOP3 / A1×最終TOP3
 *
 * 表示・分析専用。PredictionLogicには接続しない。
 *
 * Usage:
 *   php analysis/analyze_omura_shimonoseki_outer_cross.php \
 *     analysis/output/kimarite_analysis_dataset_20250815_20260814.csv \
 *     analysis/output/final_prediction_boats_fast_cached_20250815_20260814.csv
 */

const TARGET_VENUES = ['大村', '下関'];
const COURSES = [4, 5, 6];

function usage(): never
{
    fwrite(STDERR, "使用方法:\n  php analysis/analyze_omura_shimonoseki_outer_cross.php KIMARITE_DATASET_CSV BOATS_CSV\n");
    exit(1);
}

function readCsv(string $path): array
{
    if (!is_file($path)) throw new RuntimeException("CSVがありません: {$path}");
    $fp = fopen($path, 'rb');
    if ($fp === false) throw new RuntimeException("CSVを開けません: {$path}");
    $header = fgetcsv($fp);
    if (!$header) throw new RuntimeException("CSVヘッダを読めません: {$path}");
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    $rows = [];
    while (($cols = fgetcsv($fp)) !== false) {
        if (count($cols) !== count($header)) continue;
        $rows[] = array_combine($header, $cols);
    }
    fclose($fp);
    return $rows;
}

function iv(array $r, string $k): int
{
    $v = trim((string)($r[$k] ?? ''));
    return preg_match('/^-?\d+$/', $v) ? (int)$v : 0;
}

function sv(array $r, string $k): string
{
    return trim((string)($r[$k] ?? ''));
}

function raceNo(array $r): int
{
    $raw = sv($r, 'race_number');
    if (preg_match('/(\d{1,2})/', $raw, $m)) return (int)$m[1];
    $code = sv($r, 'race_code');
    if (preg_match('/(\d{2})$/', $code, $m)) return (int)$m[1];
    return 0;
}

function formal(array $r): bool
{
    return iv($r, 'result_top3_course_complete') === 1 && iv($r, 'result_boat_match') === 1;
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

function rate(int $n, int $d): float
{
    return $d > 0 ? $n * 100.0 / $d : 0.0;
}

function fmt(array $b, ?array $base = null): string
{
    $n = (int)$b['n'];
    if ($n === 0) return 'N=   0';
    $top = rate((int)$b['top3'], $n);
    $lift = '';
    if ($base && (int)$base['n'] > 0) {
        $baseTop = rate((int)$base['top3'], (int)$base['n']);
        $lift = sprintf('  差=%+6.2fpt', $top - $baseTop);
    }
    return sprintf(
        'N=%4d 1着=%6.2f%% 2着=%6.2f%% 3着=%6.2f%% 3連対=%6.2f%%%s',
        $n,
        rate((int)$b['first'], $n),
        rate((int)$b['second'], $n),
        rate((int)$b['third'], $n),
        $top,
        $lift
    );
}

[$script, $datasetPath, $boatsPath] = array_pad($argv, 3, null);
if (!$datasetPath || !$boatsPath || count($argv) !== 3) usage();

$dataset = readCsv($datasetPath);
$boatRows = readCsv($boatsPath);

$formalRows = [];
$from = '9999-12-31';
$to = '0000-01-01';
foreach ($dataset as $r) {
    $venue = sv($r, 'stadium_name');
    if (!in_array($venue, TARGET_VENUES, true) || !formal($r)) continue;
    $formalRows[] = $r;
    $d = sv($r, 'race_date');
    if ($d !== '') {
        if ($d < $from) $from = $d;
        if ($d > $to) $to = $d;
    }
}
if (!$formalRows) throw new RuntimeException('大村・下関の正式対象が0件です。');

$boats = [];
foreach ($boatRows as $r) {
    if (!in_array(sv($r, 'stadium_name'), TARGET_VENUES, true)) continue;
    $code = sv($r, 'race_code');
    $lane = iv($r, 'lane_number');
    if ($code === '' || $lane < 1 || $lane > 6) continue;
    $boats[$code][$lane] = [
        'first_rank' => iv($r, 'first_rank'),
        'second_rank' => iv($r, 'second_rank'),
        'final_rank' => iv($r, 'final_rank'),
    ];
}

// 実進入コース -> 艇番(枠) と、そのレース時点の級別を取得。
$pdo = getPDO();
$sql = <<<SQL
SELECT
    rm.race_code,
    re.lane_number::integer AS lane_number,
    COALESCE(rrd.entry_course, re.lane_number)::integer AS entry_course,
    UPPER(TRIM(COALESCE(rr.class::text, ''))) AS racer_class
FROM boat_race.race_master rm
JOIN boat_race.race_entry re
  ON re.race_code = rm.race_code
LEFT JOIN boat_race.race_result_detail rrd
  ON rrd.race_code = re.race_code
 AND rrd.lane_number = re.lane_number
LEFT JOIN boat_race.racer_results rr
  ON rr.player_id = re.player_id
 AND rr.term_info = (
    CASE
      WHEN EXTRACT(MONTH FROM rm.race_date) <= 4
        THEN TO_CHAR(rm.race_date - INTERVAL '1 year', 'YY') || '10'
      WHEN EXTRACT(MONTH FROM rm.race_date) <= 10
        THEN TO_CHAR(rm.race_date, 'YY') || '04'
      ELSE TO_CHAR(rm.race_date, 'YY') || '10'
    END
 )
WHERE rm.race_date BETWEEN :f AND :t
  AND rm.stadium_name IN ('大村', '下関')
ORDER BY rm.race_code, re.lane_number
SQL;
$stmt = $pdo->prepare($sql);
$stmt->execute([':f' => $from, ':t' => $to]);
$courseMeta = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $code = (string)$r['race_code'];
    $course = (int)$r['entry_course'];
    if ($course < 1 || $course > 6) continue;
    $courseMeta[$code][$course] = [
        'lane' => (int)$r['lane_number'],
        'class' => strtoupper(trim((string)$r['racer_class'])),
    ];
}

$conditions = [
    'all' => '全体',
    'a1' => 'A1',
    'first_top3' => '一次TOP3',
    'second_top3' => '二次TOP3',
    'final_top3' => '最終TOP3',
    'a1_first_top3' => 'A1×一次TOP3',
    'a1_second_top3' => 'A1×二次TOP3',
    'a1_final_top3' => 'A1×最終TOP3',
];
$contexts = [
    'all' => '全レース',
    'escape' => '1逃げ成立時',
    'late' => '後半7～12R',
    'late_escape' => '後半7～12R × 1逃げ成立時',
];

$stats = [];
foreach (TARGET_VENUES as $venue) {
    foreach ($contexts as $ctx => $_) {
        foreach (COURSES as $course) {
            foreach ($conditions as $cond => $_label) $stats[$venue][$ctx][$course][$cond] = bucket();
        }
    }
}

$metaMissing = 0;
$boatMissing = 0;
foreach ($formalRows as $r) {
    $venue = sv($r, 'stadium_name');
    $code = sv($r, 'race_code');
    $rn = raceNo($r);
    $c1 = iv($r, 'actual_1st_course');
    $c2 = iv($r, 'actual_2nd_course');
    $c3 = iv($r, 'actual_3rd_course');
    $escape = ($c1 === 1 && sv($r, 'winner_technique') === '逃げ');
    $late = ($rn >= 7 && $rn <= 12);

    $activeContexts = ['all'];
    if ($escape) $activeContexts[] = 'escape';
    if ($late) $activeContexts[] = 'late';
    if ($late && $escape) $activeContexts[] = 'late_escape';

    foreach (COURSES as $course) {
        $meta = $courseMeta[$code][$course] ?? null;
        if (!$meta) {
            $metaMissing++;
            continue;
        }
        $lane = (int)$meta['lane'];
        $boat = $boats[$code][$lane] ?? null;
        if (!$boat) {
            $boatMissing++;
            continue;
        }

        $isA1 = strtoupper((string)$meta['class']) === 'A1';
        $f3 = ($boat['first_rank'] >= 1 && $boat['first_rank'] <= 3);
        $s3 = ($boat['second_rank'] >= 1 && $boat['second_rank'] <= 3);
        $z3 = ($boat['final_rank'] >= 1 && $boat['final_rank'] <= 3);

        $flags = [
            'all' => true,
            'a1' => $isA1,
            'first_top3' => $f3,
            'second_top3' => $s3,
            'final_top3' => $z3,
            'a1_first_top3' => $isA1 && $f3,
            'a1_second_top3' => $isA1 && $s3,
            'a1_final_top3' => $isA1 && $z3,
        ];

        foreach ($activeContexts as $ctx) {
            foreach ($flags as $cond => $ok) {
                if ($ok) add($stats[$venue][$ctx][$course][$cond], $course, $c1, $c2, $c3);
            }
        }
    }
}

$allLabel = count($formalRows);
echo str_repeat('=', 100) . "\n";
echo "大村・下関 外枠 A1 × Web評価TOP3 交差分析\n";
echo "期間: {$from} ～ {$to} / 正式対象: {$allLabel}R\n";
echo str_repeat('=', 100) . "\n";

foreach (TARGET_VENUES as $venue) {
    echo "\n【{$venue}】\n";
    foreach ($contexts as $ctx => $ctxLabel) {
        echo "\n--- {$ctxLabel} ---\n";
        foreach (COURSES as $course) {
            echo "[{$course}C]\n";
            $base = $stats[$venue][$ctx][$course]['all'];
            foreach ($conditions as $cond => $label) {
                $b = $stats[$venue][$ctx][$course][$cond];
                printf("  %-14s %s\n", $label, fmt($b, $cond === 'all' ? null : $base));
            }
        }
    }
}

echo "\n" . str_repeat('=', 100) . "\n";
echo "コースメタ不足: {$metaMissing} / Web艇データ不足: {$boatMissing}\n";
echo "※差は同一場・同一コンテキスト・同一コースの『全体3連対率』との差。\n";
echo "※表示・分析専用。ここではPredictionLogicや買い目補正は変更しません。\n";
echo str_repeat('=', 100) . "\n";
