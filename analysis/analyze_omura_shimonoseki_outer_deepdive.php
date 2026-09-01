<?php
declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';

/**
 * 大村・下関を先行対象に、4～6コースの到達率を深掘りする。
 *
 * 目的:
 * - 全体 / 1逃げ時 / 後半7～12R の4～6C 1・2・3着率 / 3連対率
 * - 1逃げ時の2着・3着コース分布
 * - 4～6Cについて一次/二次/最終TOP3・非切り時の到達率
 * - DBに級別列があればA1/A2/B1以下も自動集計
 *
 * PredictionLogicには接続しない。まず傾向確認専用。
 *
 * Usage:
 *   php analysis/analyze_omura_shimonoseki_outer_deepdive.php \
 *     analysis/output/kimarite_analysis_dataset_20250815_20260814.csv \
 *     analysis/output/final_prediction_boats_fast_cached_20250815_20260814.csv
 */

const TARGET_VENUES = ['大村', '下関'];
const OUTER_COURSES = [4, 5, 6];

function usage(): never
{
    fwrite(STDERR,
        "使用方法:\n" .
        "  php analysis/analyze_omura_shimonoseki_outer_deepdive.php KIMARITE_DATASET_CSV BOATS_CSV\n"
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
        if (count($cols) !== count($header)) {
            continue;
        }
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

function pct(int $n, int $d, int $digits = 2): string
{
    return $d > 0 ? number_format($n * 100 / $d, $digits) . '%' : '-';
}

function isFormal(array $row): bool
{
    return iv($row, 'result_top3_course_complete') === 1
        && iv($row, 'result_boat_match') === 1;
}

function newBucket(): array
{
    return ['n' => 0, 'first' => 0, 'second' => 0, 'third' => 0, 'top3' => 0];
}

function addCourseBucket(array &$bucket, int $course, int $c1, int $c2, int $c3): void
{
    $bucket['n']++;
    if ($c1 === $course) $bucket['first']++;
    if ($c2 === $course) $bucket['second']++;
    if ($c3 === $course) $bucket['third']++;
    if ($c1 === $course || $c2 === $course || $c3 === $course) $bucket['top3']++;
}

function fmtBucket(array $b): string
{
    return sprintf(
        'N=%4d 1着=%6s 2着=%6s 3着=%6s 3連対=%6s',
        $b['n'], pct($b['first'], $b['n']), pct($b['second'], $b['n']),
        pct($b['third'], $b['n']), pct($b['top3'], $b['n'])
    );
}

function discoverGradeSource(PDO $pdo): ?array
{
    $preferred = [
        'player_class','racer_class','class_name','grade_name','rank_class',
        'kyubetsu','kyu_betsu','class','grade'
    ];
    $tables = ['race_entry', 'player_stats', 'racer_results'];
    $stmt = $pdo->prepare(<<<SQL
SELECT table_name, column_name
FROM information_schema.columns
WHERE table_schema = 'boat_race'
  AND table_name = ANY(:tables)
ORDER BY table_name, ordinal_position
SQL);

    // PostgreSQL PDOでは配列bindが扱いづらいので個別SQLにする。
    $all = [];
    foreach ($tables as $table) {
        $s = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema='boat_race' AND table_name=:t ORDER BY ordinal_position");
        $s->execute([':t' => $table]);
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $col) {
            $all[$table][] = (string)$col;
        }
    }

    foreach ($tables as $table) {
        foreach ($preferred as $name) {
            if (in_array($name, $all[$table] ?? [], true)) {
                return ['table' => $table, 'column' => $name, 'candidates' => $all];
            }
        }
    }

    $broad = [];
    foreach ($all as $table => $cols) {
        foreach ($cols as $col) {
            $low = strtolower($col);
            if (str_contains($low, 'class') || str_contains($low, 'grade') || str_contains($low, 'kyu')) {
                $broad[] = "{$table}.{$col}";
            }
        }
    }
    return $broad ? ['table' => '', 'column' => '', 'broad' => $broad, 'candidates' => $all] : null;
}

function quoteIdent(string $name): string
{
    return '"' . str_replace('"', '""', $name) . '"';
}

function loadActualCourseMap(PDO $pdo, string $from, string $to, ?array $gradeSource): array
{
    $gradeSelect = "NULL::text AS grade";
    $gradeJoin = '';

    if ($gradeSource && ($gradeSource['table'] ?? '') && ($gradeSource['column'] ?? '')) {
        $col = quoteIdent((string)$gradeSource['column']);
        if ($gradeSource['table'] === 'race_entry') {
            $gradeSelect = "re.{$col}::text AS grade";
        } elseif ($gradeSource['table'] === 'player_stats') {
            $gradeJoin = "LEFT JOIN boat_race.player_stats ps ON ps.race_code = re.race_code AND ps.player_id = re.player_id";
            $gradeSelect = "ps.{$col}::text AS grade";
        } elseif ($gradeSource['table'] === 'racer_results') {
            $gradeJoin = <<<SQL
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
SQL;
            $gradeSelect = "rr.{$col}::text AS grade";
        }
    }

    $sql = <<<SQL
SELECT
    rm.race_code,
    rm.stadium_name,
    rm.race_number,
    rrd.entry_course::integer AS entry_course,
    re.lane_number::integer AS lane_number,
    re.player_id::text AS player_id,
    {$gradeSelect}
FROM boat_race.race_master rm
JOIN boat_race.race_entry re
  ON re.race_code = rm.race_code
LEFT JOIN boat_race.race_result_detail rrd
  ON rrd.race_code = re.race_code
 AND rrd.lane_number = re.lane_number
{$gradeJoin}
WHERE rm.race_date BETWEEN :f AND :t
  AND rm.stadium_name IN ('大村', '下関')
ORDER BY rm.race_code, re.lane_number
SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':f' => $from, ':t' => $to]);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $code = (string)$r['race_code'];
        $lane = (int)$r['lane_number'];
        $course = (int)($r['entry_course'] ?? 0);
        if ($course < 1 || $course > 6) $course = $lane;
        $map[$code][$course] = [
            'lane' => $lane,
            'grade' => strtoupper(trim((string)($r['grade'] ?? ''))),
        ];
    }
    return $map;
}

function gradeGroup(string $grade): string
{
    $g = strtoupper(trim($grade));
    if ($g === 'A1') return 'A1';
    if ($g === 'A2') return 'A2';
    if ($g === 'B1' || $g === 'B2') return 'B1以下';
    return $g !== '' ? $g : '不明';
}

[$script, $datasetPath, $boatsPath] = array_pad($argv, 3, null);
if (!$datasetPath || !$boatsPath || count($argv) !== 3) usage();

$dataset = readCsv($datasetPath);
$boatRows = readCsv($boatsPath);

$formalRows = [];
$from = '9999-12-31';
$to = '0000-01-01';
foreach ($dataset as $row) {
    $venue = sv($row, 'stadium_name');
    if (!in_array($venue, TARGET_VENUES, true) || !isFormal($row)) continue;
    $formalRows[] = $row;
    $d = sv($row, 'race_date');
    if ($d !== '') {
        if ($d < $from) $from = $d;
        if ($d > $to) $to = $d;
    }
}
if (!$formalRows) throw new RuntimeException('大村・下関の正式対象が0件です。');

$boats = [];
foreach ($boatRows as $row) {
    $venue = sv($row, 'stadium_name');
    if (!in_array($venue, TARGET_VENUES, true)) continue;
    $code = sv($row, 'race_code');
    $lane = iv($row, 'lane_number');
    if ($code === '' || $lane < 1 || $lane > 6) continue;
    $boats[$code][$lane] = [
        'first_rank' => iv($row, 'first_rank'),
        'second_rank' => iv($row, 'second_rank'),
        'final_rank' => iv($row, 'final_rank'),
        'kiru' => iv($row, 'kiru'),
    ];
}

$pdo = getPDO();
$gradeSource = discoverGradeSource($pdo);
$courseMap = loadActualCourseMap($pdo, $from, $to, $gradeSource);

$stats = [];
foreach (TARGET_VENUES as $venue) {
    foreach (['all', 'escape', 'late'] as $ctx) {
        foreach (OUTER_COURSES as $course) $stats[$venue][$ctx][$course] = newBucket();
    }
    $stats[$venue]['escape_second'] = array_fill(1, 6, 0);
    $stats[$venue]['escape_third'] = array_fill(1, 6, 0);
    $stats[$venue]['escape_n'] = 0;
    foreach (OUTER_COURSES as $course) {
        foreach (['all','first_top3','second_top3','final_top3','not_kiru'] as $cond) {
            $stats[$venue]['web'][$course][$cond] = newBucket();
        }
        foreach (['A1','A2','B1以下','不明'] as $g) {
            $stats[$venue]['grade'][$course][$g] = newBucket();
            $stats[$venue]['grade_escape'][$course][$g] = newBucket();
        }
    }
}

foreach ($formalRows as $row) {
    $venue = sv($row, 'stadium_name');
    $code = sv($row, 'race_code');
    $raceNo = iv($row, 'race_number');
    $c1 = iv($row, 'actual_1st_course');
    $c2 = iv($row, 'actual_2nd_course');
    $c3 = iv($row, 'actual_3rd_course');
    $escape = ($c1 === 1 && sv($row, 'winner_technique') === '逃げ');

    foreach (OUTER_COURSES as $course) {
        addCourseBucket($stats[$venue]['all'][$course], $course, $c1, $c2, $c3);
        if ($raceNo >= 7 && $raceNo <= 12) {
            addCourseBucket($stats[$venue]['late'][$course], $course, $c1, $c2, $c3);
        }
        if ($escape) {
            addCourseBucket($stats[$venue]['escape'][$course], $course, $c1, $c2, $c3);
        }

        $map = $courseMap[$code][$course] ?? null;
        if ($map) {
            $lane = (int)$map['lane'];
            $boat = $boats[$code][$lane] ?? null;
            if ($boat) {
                addCourseBucket($stats[$venue]['web'][$course]['all'], $course, $c1, $c2, $c3);
                if (($boat['first_rank'] ?? 0) >= 1 && ($boat['first_rank'] ?? 0) <= 3) {
                    addCourseBucket($stats[$venue]['web'][$course]['first_top3'], $course, $c1, $c2, $c3);
                }
                if (($boat['second_rank'] ?? 0) >= 1 && ($boat['second_rank'] ?? 0) <= 3) {
                    addCourseBucket($stats[$venue]['web'][$course]['second_top3'], $course, $c1, $c2, $c3);
                }
                if (($boat['final_rank'] ?? 0) >= 1 && ($boat['final_rank'] ?? 0) <= 3) {
                    addCourseBucket($stats[$venue]['web'][$course]['final_top3'], $course, $c1, $c2, $c3);
                }
                if ((int)($boat['kiru'] ?? 0) !== 1) {
                    addCourseBucket($stats[$venue]['web'][$course]['not_kiru'], $course, $c1, $c2, $c3);
                }
            }

            $group = gradeGroup((string)($map['grade'] ?? ''));
            if (!isset($stats[$venue]['grade'][$course][$group])) {
                $stats[$venue]['grade'][$course][$group] = newBucket();
                $stats[$venue]['grade_escape'][$course][$group] = newBucket();
            }
            addCourseBucket($stats[$venue]['grade'][$course][$group], $course, $c1, $c2, $c3);
            if ($escape) addCourseBucket($stats[$venue]['grade_escape'][$course][$group], $course, $c1, $c2, $c3);
        }
    }

    if ($escape) {
        $stats[$venue]['escape_n']++;
        if ($c2 >= 1 && $c2 <= 6) $stats[$venue]['escape_second'][$c2]++;
        if ($c3 >= 1 && $c3 <= 6) $stats[$venue]['escape_third'][$c3]++;
    }
}

echo str_repeat('=', 88) . "\n";
echo "大村・下関 外枠到達率 深掘り\n";
echo "期間: {$from} ～ {$to}\n";
echo "正式対象: " . count($formalRows) . "R\n";
if ($gradeSource && ($gradeSource['table'] ?? '') && ($gradeSource['column'] ?? '')) {
    echo "級別取得: boat_race.{$gradeSource['table']}.{$gradeSource['column']}\n";
} elseif ($gradeSource && !empty($gradeSource['broad'])) {
    echo "級別取得: 自動確定できず。候補=" . implode(', ', $gradeSource['broad']) . "\n";
} else {
    echo "級別取得: 対応列を検出できず（A1分析は『不明』へ集約）\n";
}
echo str_repeat('=', 88) . "\n";

foreach (TARGET_VENUES as $venue) {
    echo "\n\n【{$venue}】\n";

    foreach ([
        'all' => '全レース',
        'late' => '後半7～12R',
        'escape' => '1逃げ成立時',
    ] as $ctx => $label) {
        echo "\n--- {$label} ---\n";
        foreach (OUTER_COURSES as $course) {
            echo "{$course}C  " . fmtBucket($stats[$venue][$ctx][$course]) . "\n";
        }
    }

    echo "\n--- 1逃げ成立時 相手コース分布 ---\n";
    $n = (int)$stats[$venue]['escape_n'];
    echo "対象 {$n}R\n";
    for ($course = 2; $course <= 6; $course++) {
        $s2 = (int)$stats[$venue]['escape_second'][$course];
        $s3 = (int)$stats[$venue]['escape_third'][$course];
        echo sprintf("%dC : 2着=%6s (%4d) / 3着=%6s (%4d)\n", $course, pct($s2, $n), $s2, pct($s3, $n), $s3);
    }

    echo "\n--- Web評価条件 × 4～6C到達 ---\n";
    foreach (OUTER_COURSES as $course) {
        echo "[{$course}C]\n";
        foreach ([
            'all' => '全体',
            'first_top3' => '一次TOP3',
            'second_top3' => '二次TOP3',
            'final_top3' => '最終TOP3',
            'not_kiru' => '非切り',
        ] as $cond => $label) {
            echo sprintf("  %-10s %s\n", $label, fmtBucket($stats[$venue]['web'][$course][$cond]));
        }
    }

    echo "\n--- 級別 × 4～6C到達 ---\n";
    foreach (OUTER_COURSES as $course) {
        echo "[{$course}C 全体]\n";
        foreach ($stats[$venue]['grade'][$course] as $g => $b) {
            if (($b['n'] ?? 0) > 0) echo sprintf("  %-8s %s\n", $g, fmtBucket($b));
        }
        echo "[{$course}C 1逃げ時]\n";
        foreach ($stats[$venue]['grade_escape'][$course] as $g => $b) {
            if (($b['n'] ?? 0) > 0) echo sprintf("  %-8s %s\n", $g, fmtBucket($b));
        }
    }
}

echo "\n" . str_repeat('=', 88) . "\n";
echo "※表示・分析専用。ここではPredictionLogicや買い目補正は変更しません。\n";
echo str_repeat('=', 88) . "\n";
