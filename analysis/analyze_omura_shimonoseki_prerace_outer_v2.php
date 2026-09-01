<?php
declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';

/**
 * 大村・下関の後半7～12Rについて、完全にレース前に分かる艇定義で
 * 4～6コース艇の到達率を集計する V2。
 *
 * V1との重要な違い:
 * - 対象艇のコース判定は race_result_detail.entry_course を使わない。
 * - exhibition_live.entry_course を優先し、展示が無い艇だけ枠番へfallbackする。
 * - 的中判定は「その艇自身」の actual_rank で行う。
 *   したがって展示進入と本番進入が変わっても、展示時点で選んだ艇の成績を評価できる。
 *
 * 事前コンテキスト:
 * - 後半7～12R 全体
 * - Web本命=1
 * - 1Cの1年逃げ率 + 2Cの1年逃し率 >= 100%
 * - Web本命=1 かつ 上記逃げ目安
 *
 * 各4～6C艇について:
 * 全体 / A1 / 一次TOP3 / 二次TOP3 / 最終TOP3 /
 * A1×一次TOP3 / A1×二次TOP3 / A1×最終TOP3
 * の1・2・3着率、3連対率を出す。
 *
 * Usage:
 *   php analysis/analyze_omura_shimonoseki_prerace_outer_v2.php \
 *     analysis/output/kimarite_analysis_dataset_20250815_20260814.csv \
 *     analysis/output/final_prediction_boats_fast_cached_20250815_20260814.csv
 *
 * 表示・分析専用。PredictionLogicには接続しない。
 */

const TARGET_VENUES = ['大村', '下関'];
const OUTER_COURSES = [4, 5, 6];
const CONDITIONS = [
    'all' => '全体',
    'A1' => 'A1',
    'first_top3' => '一次TOP3',
    'second_top3' => '二次TOP3',
    'final_top3' => '最終TOP3',
    'A1_first_top3' => 'A1×一次TOP3',
    'A1_second_top3' => 'A1×二次TOP3',
    'A1_final_top3' => 'A1×最終TOP3',
];

function usage(): never
{
    fwrite(STDERR,
        "使用方法:\n" .
        "  php analysis/analyze_omura_shimonoseki_prerace_outer_v2.php KIMARITE_DATASET_CSV BOATS_CSV\n"
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

function rankValue(array $row): ?float
{
    $v = sv($row, 'actual_rank');
    if ($v === '' || !is_numeric($v)) return null;
    $rank = (float)$v;
    return ($rank >= 1.0 && $rank <= 6.0) ? $rank : null;
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

function newBucket(): array
{
    return ['n' => 0, 'first' => 0, 'second' => 0, 'third' => 0, 'top3' => 0];
}

function addRankBucket(array &$b, float $rank): void
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

/**
 * レース前のコース定義を作る。
 * 展示進入が1種類だけ取れる艇は展示進入を使用。
 * 展示無し、または同一艇に複数の展示進入がある場合は枠番fallback。
 * 最後に同一レース内で同じコースが複数艇に割り当たった場合は、そのコースを曖昧として除外する。
 */
function loadPreRaceCourseMap(PDO $pdo, string $from, string $to): array
{
    $termSql = termInfoSql();
    $sql = <<<SQL
SELECT
    rm.race_code,
    re.lane_number::integer AS lane_number,
    re.player_id::text AS player_id,
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
        if ($c >= 1 && $c <= 6) {
            $byLane[$code][$lane]['courses'][$c] = true;
        }
    }

    $candidate = [];
    $meta = [
        'exhibition_boats' => 0,
        'lane_fallback_boats' => 0,
        'ambiguous_exhibition_boats' => 0,
        'duplicate_course_slots' => 0,
    ];

    foreach ($byLane as $code => $lanes) {
        foreach ($lanes as $lane => $row) {
            $courseKeys = array_map('intval', array_keys($row['courses']));
            if (count($courseKeys) === 1) {
                $course = $courseKeys[0];
                $source = 'exhibition';
                $meta['exhibition_boats']++;
            } else {
                $course = (int)$lane;
                $source = count($courseKeys) === 0 ? 'lane_fallback' : 'lane_fallback_ambiguous';
                $meta['lane_fallback_boats']++;
                if (count($courseKeys) > 1) $meta['ambiguous_exhibition_boats']++;
            }

            $candidate[$code][$course][] = [
                'lane' => (int)$lane,
                'grade' => (string)$row['grade'],
                'source' => $source,
            ];
        }
    }

    $map = [];
    foreach ($candidate as $code => $courses) {
        foreach ($courses as $course => $items) {
            if (count($items) !== 1) {
                $meta['duplicate_course_slots']++;
                continue;
            }
            $map[$code][(int)$course] = $items[0];
        }
    }

    return [$map, $meta];
}

function conditionMatches(string $key, string $grade, array $boat): bool
{
    $a1 = ($grade === 'A1');
    $first = (($boat['first_rank'] ?? 0) >= 1 && ($boat['first_rank'] ?? 0) <= 3);
    $second = (($boat['second_rank'] ?? 0) >= 1 && ($boat['second_rank'] ?? 0) <= 3);
    $final = (($boat['final_rank'] ?? 0) >= 1 && ($boat['final_rank'] ?? 0) <= 3);

    return match ($key) {
        'all' => true,
        'A1' => $a1,
        'first_top3' => $first,
        'second_top3' => $second,
        'final_top3' => $final,
        'A1_first_top3' => $a1 && $first,
        'A1_second_top3' => $a1 && $second,
        'A1_final_top3' => $a1 && $final,
        default => false,
    };
}

[$script, $datasetPath, $boatsPath] = array_pad($argv, 3, null);
if (!$datasetPath || !$boatsPath || count($argv) !== 3) usage();

$dataset = readCsv($datasetPath);
$boatRows = readCsv($boatsPath);

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
        'actual_rank' => rankValue($row),
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
    $rows[] = $row;
    $d = sv($row, 'race_date');
    if ($d !== '') {
        if ($d < $from) $from = $d;
        if ($d > $to) $to = $d;
    }
}
if (!$rows) throw new RuntimeException('大村・下関の後半正式対象が0件です。');

$pdo = getPDO();
[$courseMap, $courseSourceMeta] = loadPreRaceCourseMap($pdo, $from, $to);

$contexts = [
    'late_all' => '後半7～12R 全体',
    'late_web1' => '後半7～12R × Web本命=1',
    'late_escape_signal' => '後半7～12R × 逃げ目安100%以上',
    'late_web1_escape_signal' => '後半7～12R × Web本命=1 × 逃げ目安100%以上',
];

$stats = [];
$ctxMeta = [];
foreach (TARGET_VENUES as $venue) {
    foreach ($contexts as $ctxKey => $label) {
        $ctxMeta[$venue][$ctxKey] = ['races' => 0, 'actual_escape' => 0];
        foreach (OUTER_COURSES as $course) {
            foreach (array_keys(CONDITIONS) as $cond) {
                $stats[$venue][$ctxKey][$course][$cond] = newBucket();
            }
        }
    }
}

$courseMetaMissing = 0;
$webBoatMissing = 0;
$actualRankMissing = 0;
$sourceUsed = ['exhibition' => 0, 'fallback' => 0];

foreach ($rows as $row) {
    $venue = sv($row, 'stadium_name');
    $code = sv($row, 'race_code');
    $actualEscape = (
        iv($row, 'actual_1st_course') === 1
        && sv($row, 'winner_technique') === '逃げ'
    );

    $webHead1 = (iv($row, 'honmei_head') === 1);
    $escapeIndex = fv($row, 'c1_1y_nige') + fv($row, 'c2_1y_nogashi');
    $escapeSignal = ($escapeIndex >= 100.0);

    $activeContexts = ['late_all'];
    if ($webHead1) $activeContexts[] = 'late_web1';
    if ($escapeSignal) $activeContexts[] = 'late_escape_signal';
    if ($webHead1 && $escapeSignal) $activeContexts[] = 'late_web1_escape_signal';

    foreach ($activeContexts as $ctxKey) {
        $ctxMeta[$venue][$ctxKey]['races']++;
        if ($actualEscape) $ctxMeta[$venue][$ctxKey]['actual_escape']++;
    }

    foreach (OUTER_COURSES as $course) {
        $meta = $courseMap[$code][$course] ?? null;
        if (!$meta) {
            $courseMetaMissing++;
            continue;
        }

        $lane = (int)$meta['lane'];
        $grade = strtoupper(trim((string)$meta['grade']));
        $boat = $boats[$code][$lane] ?? null;
        if (!$boat) {
            $webBoatMissing++;
            continue;
        }
        $actualRank = $boat['actual_rank'] ?? null;
        if (!is_float($actualRank) && !is_int($actualRank)) {
            $actualRankMissing++;
            continue;
        }
        $actualRank = (float)$actualRank;

        if (($meta['source'] ?? '') === 'exhibition') {
            $sourceUsed['exhibition']++;
        } else {
            $sourceUsed['fallback']++;
        }

        foreach ($activeContexts as $ctxKey) {
            foreach (array_keys(CONDITIONS) as $cond) {
                if (!conditionMatches($cond, $grade, $boat)) continue;
                addRankBucket($stats[$venue][$ctxKey][$course][$cond], $actualRank);
            }
        }
    }
}

echo str_repeat('=', 116) . "\n";
echo "大村・下関 後半レース 事前条件 × 外枠到達率 V2\n";
echo "期間: {$from} ～ {$to}\n";
echo "艇定義: 展示進入 exhibition_live.entry_course（展示無しは枠番fallback）\n";
echo "成績判定: 展示時点で選んだ『その艇自身』の actual_rank\n";
echo "逃げ目安: 1C・1年逃げ率 + 2C・1年逃し率 >= 100%\n";
echo str_repeat('=', 116) . "\n";

foreach (TARGET_VENUES as $venue) {
    echo "\n【{$venue}】\n";
    foreach ($contexts as $ctxKey => $label) {
        $m = $ctxMeta[$venue][$ctxKey];
        echo "\n--- {$label} ---\n";
        echo sprintf(
            "対象=%dR / 実際に1逃げ=%s (%dR)\n",
            $m['races'], pct($m['actual_escape'], $m['races']), $m['actual_escape']
        );

        foreach (OUTER_COURSES as $course) {
            echo "[展示{$course}C艇]\n";
            $base = $stats[$venue][$ctxKey][$course]['all'];
            foreach (CONDITIONS as $cond => $condLabel) {
                $b = $stats[$venue][$ctxKey][$course][$cond];
                $delta = ($base['n'] > 0 && $b['n'] > 0)
                    ? (($b['top3'] / $b['n']) - ($base['top3'] / $base['n'])) * 100.0
                    : 0.0;
                $deltaText = $cond === 'all' || $b['n'] === 0
                    ? ''
                    : sprintf(' 差=%+6.2fpt', $delta);
                echo sprintf("  %-17s %s%s\n", $condLabel, fmtBucket($b), $deltaText);
            }
        }
    }
}

echo "\n" . str_repeat('=', 116) . "\n";
echo "DBコース構築: 展示艇={$courseSourceMeta['exhibition_boats']} / 枠番fallback={$courseSourceMeta['lane_fallback_boats']} / "
    . "展示複数値={$courseSourceMeta['ambiguous_exhibition_boats']} / コース重複slot={$courseSourceMeta['duplicate_course_slots']}\n";
echo "分析時: コースメタ不足={$courseMetaMissing} / Web艇データ不足={$webBoatMissing} / actual_rank不足={$actualRankMissing}\n";
echo "分析対象4～6Cのソース利用: 展示={$sourceUsed['exhibition']} / fallback={$sourceUsed['fallback']}\n";
echo "※抽出条件と対象艇の定義はレース前情報のみ。『実際に1逃げ』は条件精度の確認ラベルだけに使用。\n";
echo "※表示・分析専用。PredictionLogicや買い目補正は変更しません。\n";
echo str_repeat('=', 116) . "\n";
