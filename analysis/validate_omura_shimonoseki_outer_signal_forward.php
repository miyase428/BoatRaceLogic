<?php
declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';

/**
 * 大村・下関 外枠注意条件の前方検証。
 *
 * 学習期間（2025-08-15～2026-08-14）のV3分析から、holdoutを見る前に固定した条件だけを検証する。
 *
 * 固定レース条件:
 * - 大村 / 下関
 * - 7～12R
 * - Web本命 = 1号艇
 * - 1C・1年逃げ率 + 2C・1年逃し率 >= 100%
 *
 * 固定外枠シグナル:
 * - 展示4C / 5C / 6C の艇
 * - A1
 * - 最終評価TOP3
 *
 * 対象艇は exhibition_live.entry_course を優先し、展示なし時だけ枠番fallback。
 * 結果は「その艇自身」の actual_rank で判定し、空欄は5.5着外として分母に含める。
 *
 * Usage:
 *   php analysis/validate_omura_shimonoseki_outer_signal_forward.php \
 *     KIMARITE_DATASET_CSV BOATS_CSV
 */

const TARGET_VENUES = ['大村', '下関'];
const OUTER_COURSES = [4, 5, 6];

function usage(): never
{
    fwrite(STDERR,
        "使用方法:\n" .
        "  php analysis/validate_omura_shimonoseki_outer_signal_forward.php KIMARITE_DATASET_CSV BOATS_CSV\n"
    );
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
        $row = array_combine($header, $cols);
        if (is_array($row)) $rows[] = $row;
    }
    fclose($fp);
    return $rows;
}

function sv(array $row, string $key): string
{
    return trim((string)($row[$key] ?? ''));
}

function iv(array $row, string $key): int
{
    $v = sv($row, $key);
    if ($v === '') return 0;
    if (preg_match('/^-?\d+$/', $v)) return (int)$v;
    if (preg_match('/(\d+)/', $v, $m)) return (int)$m[1];
    return 0;
}

function fv(array $row, string $key): float
{
    $v = str_replace('%', '', sv($row, $key));
    return is_numeric($v) ? (float)$v : 0.0;
}

function raceNo(array $row): int
{
    $n = iv($row, 'race_number');
    if ($n >= 1 && $n <= 12) return $n;
    $code = sv($row, 'race_code');
    if (preg_match('/(0[1-9]|1[0-2])$/', $code, $m)) return (int)$m[1];
    return 0;
}

function isFormal(array $row): bool
{
    return iv($row, 'result_top3_course_complete') === 1
        && iv($row, 'result_boat_match') === 1;
}

function pct(int $n, int $d): string
{
    return $d > 0 ? number_format($n * 100 / $d, 2) . '%' : '-';
}

function rankValueOrOutside(array $row, int &$blankCounter): float
{
    $v = sv($row, 'actual_rank');
    if ($v === '') {
        $blankCounter++;
        return 5.5;
    }
    if (!is_numeric($v)) return 5.5;
    $rank = (float)$v;
    return ($rank >= 1.0 && $rank <= 6.0) ? $rank : 5.5;
}

function newBucket(): array
{
    return ['n' => 0, 'first' => 0, 'second' => 0, 'third' => 0, 'top3' => 0];
}

function addRank(array &$b, float $rank): void
{
    $b['n']++;
    if ($rank === 1.0) $b['first']++;
    if ($rank === 2.0) $b['second']++;
    if ($rank === 3.0) $b['third']++;
    if ($rank >= 1.0 && $rank <= 3.0) $b['top3']++;
}

function fmtBucket(array $b): string
{
    return sprintf(
        'N=%4d 1着=%7s 2着=%7s 3着=%7s 3連対=%7s',
        $b['n'],
        pct($b['first'], $b['n']),
        pct($b['second'], $b['n']),
        pct($b['third'], $b['n']),
        pct($b['top3'], $b['n'])
    );
}

function termInfoSql(): string
{
    return "(CASE\n"
        . " WHEN EXTRACT(MONTH FROM rm.race_date) <= 4 THEN TO_CHAR(rm.race_date - INTERVAL '1 year', 'YY') || '10'\n"
        . " WHEN EXTRACT(MONTH FROM rm.race_date) <= 10 THEN TO_CHAR(rm.race_date, 'YY') || '04'\n"
        . " ELSE TO_CHAR(rm.race_date, 'YY') || '10' END)";
}

function loadPreRaceCourseMap(PDO $pdo, string $from, string $to): array
{
    $termSql = termInfoSql();
    $sql = <<<SQL
SELECT
    rm.race_code,
    re.lane_number::integer AS lane_number,
    el.entry_course::integer AS exhibition_course,
    UPPER(TRIM(COALESCE(rr."class"::text, ''))) AS grade
FROM boat_race.race_master rm
JOIN boat_race.race_entry re
  ON re.race_code = rm.race_code
LEFT JOIN boat_race.exhibition_live el
  ON el.race_code = re.race_code
 AND el.player_id = re.player_id
LEFT JOIN boat_race.racer_results rr
  ON rr.player_id = re.player_id
 AND rr.term_info::text = {$termSql}
WHERE rm.race_date BETWEEN :f AND :t
  AND rm.stadium_name IN ('大村', '下関')
ORDER BY rm.race_code, re.lane_number
SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':f' => $from, ':t' => $to]);

    $byLane = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $code = (string)$r['race_code'];
        $lane = (int)$r['lane_number'];
        if ($lane < 1 || $lane > 6) continue;
        if (!isset($byLane[$code][$lane])) {
            $byLane[$code][$lane] = [
                'grade' => strtoupper(trim((string)$r['grade'])),
                'courses' => [],
            ];
        }
        $c = (int)($r['exhibition_course'] ?? 0);
        if ($c >= 1 && $c <= 6) $byLane[$code][$lane]['courses'][$c] = true;
    }

    $candidate = [];
    $meta = [
        'exhibition' => 0,
        'fallback' => 0,
        'ambiguous' => 0,
        'duplicate_slot' => 0,
    ];

    foreach ($byLane as $code => $lanes) {
        foreach ($lanes as $lane => $r) {
            $courseKeys = array_map('intval', array_keys($r['courses']));
            if (count($courseKeys) === 1) {
                $course = $courseKeys[0];
                $source = 'exhibition';
                $meta['exhibition']++;
            } else {
                $course = (int)$lane;
                $source = 'fallback';
                $meta['fallback']++;
                if (count($courseKeys) > 1) $meta['ambiguous']++;
            }
            $candidate[$code][$course][] = [
                'lane' => (int)$lane,
                'grade' => (string)$r['grade'],
                'source' => $source,
            ];
        }
    }

    $map = [];
    foreach ($candidate as $code => $courses) {
        foreach ($courses as $course => $items) {
            if (count($items) !== 1) {
                $meta['duplicate_slot']++;
                continue;
            }
            $map[$code][(int)$course] = $items[0];
        }
    }

    return [$map, $meta];
}

[$script, $datasetPath, $boatsPath] = array_pad($argv, 3, null);
if (!$datasetPath || !$boatsPath || count($argv) !== 3) usage();

$dataset = readCsv($datasetPath);
$boatRows = readCsv($boatsPath);

$blankRankCounter = 0;
$boats = [];
foreach ($boatRows as $row) {
    $venue = sv($row, 'stadium_name');
    if (!in_array($venue, TARGET_VENUES, true)) continue;
    $code = sv($row, 'race_code');
    $lane = iv($row, 'lane_number');
    if ($code === '' || $lane < 1 || $lane > 6) continue;
    $boats[$code][$lane] = [
        'final_rank' => iv($row, 'final_rank'),
        'actual_rank' => rankValueOrOutside($row, $blankRankCounter),
    ];
}

$rows = [];
$from = '9999-12-31';
$to = '0000-01-01';
foreach ($dataset as $row) {
    $venue = sv($row, 'stadium_name');
    if (!in_array($venue, TARGET_VENUES, true) || !isFormal($row)) continue;
    $n = raceNo($row);
    if ($n < 7 || $n > 12) continue;

    // holdout前に固定したレース条件だけを採用
    if (iv($row, 'honmei_head') !== 1) continue;
    $escapeIndex = fv($row, 'c1_1y_nige') + fv($row, 'c2_1y_nogashi');
    if ($escapeIndex < 100.0) continue;

    $rows[] = $row;
    $d = sv($row, 'race_date');
    if ($d !== '') {
        if ($d < $from) $from = $d;
        if ($d > $to) $to = $d;
    }
}

if (!$rows) {
    throw new RuntimeException('固定レース条件に該当する大村・下関の正式対象が0件です。');
}

$pdo = getPDO();
[$courseMap, $courseMeta] = loadPreRaceCourseMap($pdo, $from, $to);

$base = [];
$signal = [];
$raceMeta = [];
$signalRaceSeen = [];
$sourceUsed = ['exhibition' => 0, 'fallback' => 0];
$courseMissing = 0;
$boatMissing = 0;

foreach (TARGET_VENUES as $venue) {
    $raceMeta[$venue] = ['races' => 0, 'actual_escape' => 0, 'signal_races' => 0];
    foreach (OUTER_COURSES as $course) {
        $base[$venue][$course] = newBucket();
        $signal[$venue][$course] = newBucket();
    }
}

foreach ($rows as $row) {
    $venue = sv($row, 'stadium_name');
    $code = sv($row, 'race_code');
    $raceMeta[$venue]['races']++;
    $actualEscape = (iv($row, 'actual_1st_course') === 1 && sv($row, 'winner_technique') === '逃げ');
    if ($actualEscape) $raceMeta[$venue]['actual_escape']++;

    foreach (OUTER_COURSES as $course) {
        $meta = $courseMap[$code][$course] ?? null;
        if (!$meta) {
            $courseMissing++;
            continue;
        }
        $lane = (int)$meta['lane'];
        $boat = $boats[$code][$lane] ?? null;
        if (!$boat) {
            $boatMissing++;
            continue;
        }
        $rank = (float)$boat['actual_rank'];
        addRank($base[$venue][$course], $rank);

        if (($meta['source'] ?? '') === 'exhibition') $sourceUsed['exhibition']++;
        else $sourceUsed['fallback']++;

        $isSignal = strtoupper(trim((string)$meta['grade'])) === 'A1'
            && (int)$boat['final_rank'] >= 1
            && (int)$boat['final_rank'] <= 3;

        if ($isSignal) {
            addRank($signal[$venue][$course], $rank);
            $signalRaceSeen[$venue][$code] = true;
        }
    }
}

foreach (TARGET_VENUES as $venue) {
    $raceMeta[$venue]['signal_races'] = count($signalRaceSeen[$venue] ?? []);
}

echo str_repeat('=', 112) . "\n";
echo "大村・下関 外枠注意条件 前方検証\n";
echo "期間: {$from} ～ {$to}\n";
echo "固定レース条件: 7～12R × Web本命=1 × 逃げ目安100%以上\n";
echo "固定外枠シグナル: 展示4～6C × A1 × 最終TOP3\n";
echo str_repeat('=', 112) . "\n";

foreach (TARGET_VENUES as $venue) {
    $m = $raceMeta[$venue];
    echo "\n【{$venue}】\n";
    echo sprintf(
        "固定条件対象=%dR / 実際に1逃げ=%s (%dR) / シグナル艇あり=%dR\n",
        $m['races'], pct($m['actual_escape'], $m['races']), $m['actual_escape'], $m['signal_races']
    );

    foreach (OUTER_COURSES as $course) {
        $b = $base[$venue][$course];
        $s = $signal[$venue][$course];
        $baseTop3 = $b['n'] > 0 ? $b['top3'] * 100.0 / $b['n'] : 0.0;
        $signalTop3 = $s['n'] > 0 ? $s['top3'] * 100.0 / $s['n'] : 0.0;
        $delta = $signalTop3 - $baseTop3;
        echo "\n[展示{$course}C艇]\n";
        echo "  ベース   " . fmtBucket($b) . "\n";
        echo "  シグナル " . fmtBucket($s)
            . ($s['n'] > 0 ? sprintf(' 差=%+6.2fpt', $delta) : '') . "\n";
    }
}

echo "\n" . str_repeat('=', 112) . "\n";
echo "actual_rank空欄→5.5着外: {$blankRankCounter}艇\n";
echo "コース構築: 展示={$courseMeta['exhibition']} / fallback={$courseMeta['fallback']} / 展示複数={$courseMeta['ambiguous']} / 重複slot={$courseMeta['duplicate_slot']}\n";
echo "分析対象4～6Cソース: 展示={$sourceUsed['exhibition']} / fallback={$sourceUsed['fallback']}\n";
echo "分析時: コースメタ不足={$courseMissing} / Web艇データ不足={$boatMissing}\n";
echo "※holdout結果を見て条件を変更しないため、条件は学習期間V3の結果から事前固定。\n";
echo "※表示・検証専用。PredictionLogicや買い目補正は変更しません。\n";
echo str_repeat('=', 112) . "\n";
