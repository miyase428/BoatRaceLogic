<?php
/**
 * 穴検索3プリセットの高配当性検証。
 *
 * 条件は総当たり→前方検証後に固定した3プリセットから変更しない。
 * - 精度優先: 1C逃げ率<=20% × 2〜6C差し+捲り最大>=20%
 * - バランス: 1C逃げ率<=28% × 2〜6C差し+捲り最大>=20%
 * - 件数優先: 1C逃げ率<=37% × 2〜6C差し+捲り最大>=20%
 *
 * 目的:
 * 1. 「1号艇が負けやすい」だけでなく、3連単払戻も高くなりやすいかを見る。
 * 2. 全レースとの比較に加え、「実際に非1号艇が勝ったレース同士」でも比較する。
 * 3. 2026-08-15を境に選定期間 / 前方期間を分けて表示する。
 *
 * Usage:
 *   php analysis/analyze_upset_presets_payout.php \
 *     analysis/output/kimarite_analysis_dataset_20250815_20260814.csv \
 *     analysis/output/kimarite_analysis_dataset_20260815_20260822.csv \
 *     analysis/output/kimarite_analysis_dataset_20260823_20260831.csv
 *
 * 払戻は boat_race.race_payouts.trifecta_payout を使用。
 * 1〜3着の各順位が1艇ずつでない特殊レース（同着など）は除外する。
 */

declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';

const UP_HOLDOUT_START = '20260815';
const UP_MIN_SAMPLE_N = 10;

$presets = [
    '精度優先' => ['nige_max' => 20.0, 'attack_min' => 20.0, 'train_non1_rate' => 83.17],
    'バランス' => ['nige_max' => 28.0, 'attack_min' => 20.0, 'train_non1_rate' => 78.06],
    '件数優先' => ['nige_max' => 37.0, 'attack_min' => 20.0, 'train_non1_rate' => 72.98],
];

function up_usage(): never
{
    fwrite(STDERR, "使用方法:\n  php analysis/analyze_upset_presets_payout.php KIMARITE_CSV [KIMARITE_CSV ...]\n");
    exit(1);
}

if ($argc < 2) {
    up_usage();
}

function up_pct(int $num, int $den): float
{
    return $den > 0 ? 100.0 * $num / $den : 0.0;
}

function up_avg(array $values): float
{
    return $values ? array_sum($values) / count($values) : 0.0;
}

function up_median(array $values): float
{
    if (!$values) return 0.0;
    sort($values, SORT_NUMERIC);
    $n = count($values);
    $mid = intdiv($n, 2);
    return ($n % 2 === 1)
        ? (float)$values[$mid]
        : ((float)$values[$mid - 1] + (float)$values[$mid]) / 2.0;
}

function up_fmt_date(?string $ymd): string
{
    if ($ymd === null || !preg_match('/^\d{8}$/', $ymd)) return '-';
    return substr($ymd, 0, 4) . '-' . substr($ymd, 4, 2) . '-' . substr($ymd, 6, 2);
}

function up_load_kimarite(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException("CSVがありません: {$path}");
    }
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        throw new RuntimeException("CSVを開けません: {$path}");
    }
    $header = fgetcsv($fh);
    if ($header === false) {
        fclose($fh);
        throw new RuntimeException("CSVヘッダを読めません: {$path}");
    }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    $idx = array_flip($header);

    $required = ['race_code', 'actual_1st', 'c1_1y_sample_n', 'c1_1y_nige'];
    for ($c = 2; $c <= 6; $c++) {
        $required[] = "c{$c}_1y_sample_n";
        $required[] = "c{$c}_1y_sashi";
        $required[] = "c{$c}_1y_makuri";
    }
    foreach ($required as $col) {
        if (!array_key_exists($col, $idx)) {
            fclose($fh);
            throw new RuntimeException("必要列がありません {$col}: {$path}");
        }
    }

    $rows = [];
    while (($data = fgetcsv($fh)) !== false) {
        if (count($data) < count($header)) continue;

        $raceCode = trim((string)($data[$idx['race_code']] ?? ''));
        if (!preg_match('/^(\d{8})[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $raceCode, $m)) continue;
        $date = $m[1];

        $actualRaw = trim((string)($data[$idx['actual_1st']] ?? ''));
        if (!preg_match('/^[1-6]$/', $actualRaw)) continue;

        $n1 = (int)($data[$idx['c1_1y_sample_n']] ?? 0);
        $nigeRaw = trim((string)($data[$idx['c1_1y_nige']] ?? ''));
        if ($n1 < UP_MIN_SAMPLE_N || !is_numeric($nigeRaw)) continue;

        $attackMax = null;
        $eligibleAttackCourses = 0;
        for ($c = 2; $c <= 6; $c++) {
            $n = (int)($data[$idx["c{$c}_1y_sample_n"]] ?? 0);
            if ($n < UP_MIN_SAMPLE_N) continue;
            $sRaw = trim((string)($data[$idx["c{$c}_1y_sashi"]] ?? ''));
            $mRaw = trim((string)($data[$idx["c{$c}_1y_makuri"]] ?? ''));
            if (!is_numeric($sRaw) || !is_numeric($mRaw)) continue;
            $attack = (float)$sRaw + (float)$mRaw;
            $attackMax = $attackMax === null ? $attack : max($attackMax, $attack);
            $eligibleAttackCourses++;
        }
        if ($attackMax === null || $eligibleAttackCourses === 0) continue;

        $rows[$raceCode] = [
            'race_code' => $raceCode,
            'date' => $date,
            'race_no' => (int)$m[2],
            'nige' => (float)$nigeRaw,
            'attack_max' => (float)$attackMax,
            'actual_1st' => (int)$actualRaw,
            'source' => basename($path),
        ];
    }
    fclose($fh);
    return $rows;
}

/**
 * race_codeごとの3連単払戻と結果品質を一括取得。
 * rank1/2/3が各1艇のみの通常レースだけ payout_valid=true とする。
 */
function up_load_payout_quality(PDO $pdo, array $raceCodes): array
{
    $out = [];
    foreach (array_chunk($raceCodes, 500) as $chunk) {
        if (!$chunk) continue;
        $ph = implode(',', array_fill(0, count($chunk), '?'));

        $sql = "
WITH payout AS (
    SELECT
        race_code,
        MAX(COALESCE(trifecta_payout, 0))::numeric AS trifecta_payout
    FROM boat_race.race_payouts
    WHERE race_code IN ({$ph})
    GROUP BY race_code
), quality AS (
    SELECT
        race_code,
        COUNT(*) FILTER (WHERE TRIM(rank) = '1')::int AS r1,
        COUNT(*) FILTER (WHERE TRIM(rank) = '2')::int AS r2,
        COUNT(*) FILTER (WHERE TRIM(rank) = '3')::int AS r3
    FROM boat_race.race_result_detail
    WHERE race_code IN ({$ph})
    GROUP BY race_code
)
SELECT
    p.race_code,
    p.trifecta_payout,
    COALESCE(q.r1, 0) AS r1,
    COALESCE(q.r2, 0) AS r2,
    COALESCE(q.r3, 0) AS r3
FROM payout p
LEFT JOIN quality q ON q.race_code = p.race_code
";
        $params = array_merge($chunk, $chunk);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $code = trim((string)$r['race_code']);
            $payout = (int)($r['trifecta_payout'] ?? 0);
            $r1 = (int)($r['r1'] ?? 0);
            $r2 = (int)($r['r2'] ?? 0);
            $r3 = (int)($r['r3'] ?? 0);
            $out[$code] = [
                'payout' => $payout,
                'normal_top3' => ($r1 === 1 && $r2 === 1 && $r3 === 1),
                'valid' => ($payout > 0 && $r1 === 1 && $r2 === 1 && $r3 === 1),
            ];
        }
    }
    return $out;
}

function up_matches(array $r, float $nigeMax, float $attackMin): bool
{
    return $r['nige'] <= $nigeMax && $r['attack_max'] >= $attackMin;
}

function up_metrics(array $rows): array
{
    $n = count($rows);
    $non1 = 0;
    $payouts = [];
    foreach ($rows as $r) {
        if ($r['actual_1st'] !== 1) $non1++;
        $payouts[] = (int)$r['payout'];
    }
    $ge5 = 0; $ge10 = 0; $ge20 = 0;
    foreach ($payouts as $p) {
        if ($p >= 5000) $ge5++;
        if ($p >= 10000) $ge10++;
        if ($p >= 20000) $ge20++;
    }
    return [
        'n' => $n,
        'non1' => $non1,
        'non1_rate' => up_pct($non1, $n),
        'avg' => up_avg($payouts),
        'median' => up_median($payouts),
        'ge5_rate' => up_pct($ge5, $n),
        'ge10_rate' => up_pct($ge10, $n),
        'ge20_rate' => up_pct($ge20, $n),
    ];
}

function up_non1_rows(array $rows): array
{
    return array_values(array_filter($rows, static fn(array $r): bool => $r['actual_1st'] !== 1));
}

function up_print_metrics_line(string $name, array $m): void
{
    printf(
        "%-12s N=%5d  非1頭=%6.2f%%  平均=%8.0f円  中央=%7.0f円  5千+=%6.2f%%  万舟=%6.2f%%  2万+=%6.2f%%\n",
        $name, $m['n'], $m['non1_rate'], $m['avg'], $m['median'], $m['ge5_rate'], $m['ge10_rate'], $m['ge20_rate']
    );
}

function up_print_non1_line(string $name, array $m): void
{
    printf(
        "%-12s N=%5d  平均=%8.0f円  中央=%7.0f円  5千+=%6.2f%%  万舟=%6.2f%%  2万+=%6.2f%%\n",
        $name, $m['n'], $m['avg'], $m['median'], $m['ge5_rate'], $m['ge10_rate'], $m['ge20_rate']
    );
}

$allRows = [];
foreach (array_slice($argv, 1) as $path) {
    try {
        $rows = up_load_kimarite($path);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    foreach ($rows as $code => $row) {
        $allRows[$code] = $row;
    }
}

if (!$allRows) {
    fwrite(STDERR, "分析可能な行がありません\n");
    exit(1);
}
ksort($allRows);

try {
    $pdo = getPDO();
    $pq = up_load_payout_quality($pdo, array_keys($allRows));
} catch (Throwable $e) {
    fwrite(STDERR, "払戻取得失敗: " . $e->getMessage() . "\n");
    exit(1);
}

$validRows = [];
$missingPayout = 0;
$specialTop3 = 0;
foreach ($allRows as $code => $r) {
    if (!isset($pq[$code]) || ($pq[$code]['payout'] ?? 0) <= 0) {
        $missingPayout++;
        continue;
    }
    if (!($pq[$code]['normal_top3'] ?? false)) {
        $specialTop3++;
        continue;
    }
    $r['payout'] = (int)$pq[$code]['payout'];
    $validRows[$code] = $r;
}

$trainRows = [];
$forwardRows = [];
foreach ($validRows as $code => $r) {
    if ($r['date'] < UP_HOLDOUT_START) $trainRows[$code] = $r;
    else $forwardRows[$code] = $r;
}

$sections = [
    '選定期間' => $trainRows,
    '前方期間' => $forwardRows,
    '全期間参考' => $validRows,
];

$dateMin = null; $dateMax = null;
foreach ($validRows as $r) {
    $dateMin = $dateMin === null || $r['date'] < $dateMin ? $r['date'] : $dateMin;
    $dateMax = $dateMax === null || $r['date'] > $dateMax ? $r['date'] : $dateMax;
}

$line = str_repeat('=', 132);
echo $line . "\n";
echo "穴検索 3プリセット 高配当性検証（条件固定）\n";
echo "期間           : " . up_fmt_date($dateMin) . " ～ " . up_fmt_date($dateMax) . "\n";
echo "読込レース     : " . number_format(count($allRows)) . "R\n";
echo "払戻分析可能   : " . number_format(count($validRows)) . "R\n";
echo "払戻なし/0     : " . number_format($missingPayout) . "R\n";
echo "同着等特殊除外 : " . number_format($specialTop3) . "R\n";
echo "前方開始       : 2026-08-15（条件再調整なし）\n";
echo "高配当基準     : 5,000円以上 / 10,000円以上（万舟） / 20,000円以上\n";
echo $line . "\n";

foreach ($sections as $sectionName => $rows) {
    if (!$rows) continue;

    $dmin = null; $dmax = null;
    foreach ($rows as $r) {
        $dmin = $dmin === null || $r['date'] < $dmin ? $r['date'] : $dmin;
        $dmax = $dmax === null || $r['date'] > $dmax ? $r['date'] : $dmax;
    }

    echo "\n【{$sectionName}】 " . up_fmt_date($dmin) . " ～ " . up_fmt_date($dmax) . "\n";
    echo "-- 全該当レース（1号艇逃げも含む） --\n";
    up_print_metrics_line('母体全体', up_metrics(array_values($rows)));

    foreach ($presets as $name => $p) {
        $selected = [];
        foreach ($rows as $r) {
            if (up_matches($r, $p['nige_max'], $p['attack_min'])) $selected[] = $r;
        }
        up_print_metrics_line($name, up_metrics($selected));
    }

    echo "\n-- 実際に非1号艇が1着だったレースだけ --\n";
    $baseNon1 = up_non1_rows(array_values($rows));
    up_print_non1_line('母体非1頭', up_metrics($baseNon1));
    foreach ($presets as $name => $p) {
        $selected = [];
        foreach ($rows as $r) {
            if (up_matches($r, $p['nige_max'], $p['attack_min']) && $r['actual_1st'] !== 1) {
                $selected[] = $r;
            }
        }
        up_print_non1_line($name, up_metrics($selected));
    }
}

echo "\n【判断ポイント】\n";
echo "1. まず前方期間の万舟率・5千円以上率が母体全体より上がるかを見る。\n";
echo "2. 『非1頭だけ』でも母体非1頭より高配当率が上がれば、単なるイン敗北検出以上の価値がある。\n";
echo "3. 平均は大穴1件で跳ねるため、中央値と万舟率も必ず併記して判断する。\n";
echo "4. 条件はここで再調整しない。配当性が弱ければ名称や用途を見直し、後付け最適化はしない。\n";
echo $line . "\n";
