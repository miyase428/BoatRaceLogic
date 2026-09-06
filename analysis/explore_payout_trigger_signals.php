<?php
/**
 * 中配当・高配当の「きっかけ」候補を単変量で探索する。
 *
 * 目的:
 * - 穴レースを1条件で決め打ちせず、事前に分かる複数の兆候を探す。
 * - まず各兆候を独立に評価し、後段で「何個該当したか」のスコアへ進む。
 * - 2025-08-15〜2026-08-14を探索期間、2026-08-15以降を前方確認として分離する。
 *
 * 払戻帯:
 * - 中配当 : 5,000〜9,999円
 * - 高配当 : 10,000〜19,999円
 * - 大穴   : 20,000円以上
 *
 * Usage:
 *   php analysis/explore_payout_trigger_signals.php \
 *     analysis/output/kimarite_analysis_dataset_20250815_20260814.csv \
 *     analysis/output/kimarite_analysis_dataset_20260815_20260822.csv \
 *     analysis/output/kimarite_analysis_dataset_20260823_20260831.csv
 */

declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';

const PTS_HOLDOUT_START = '20260815';
const PTS_MIN_SAMPLE_N = 10;
const PTS_MIN_TRAIN_N = 500;
const PTS_MIN_FORWARD_N = 20;

function ptsUsage(): never
{
    fwrite(STDERR, "使用方法:\n  php analysis/explore_payout_trigger_signals.php KIMARITE_CSV [KIMARITE_CSV ...]\n");
    exit(1);
}

if ($argc < 2) {
    ptsUsage();
}

function ptsPct(int $num, int $den): float
{
    return $den > 0 ? 100.0 * $num / $den : 0.0;
}

function ptsMedian(array $values): float
{
    if (!$values) return 0.0;
    sort($values, SORT_NUMERIC);
    $n = count($values);
    $m = intdiv($n, 2);
    return ($n % 2 === 1)
        ? (float)$values[$m]
        : ((float)$values[$m - 1] + (float)$values[$m]) / 2.0;
}

function ptsFmtDate(?string $ymd): string
{
    if ($ymd === null || !preg_match('/^\d{8}$/', $ymd)) return '-';
    return substr($ymd, 0, 4) . '-' . substr($ymd, 4, 2) . '-' . substr($ymd, 6, 2);
}

function ptsNum($v): ?float
{
    $s = trim((string)$v);
    return ($s !== '' && is_numeric($s)) ? (float)$s : null;
}

function ptsBucket(float $v, array $cuts, array $labels): string
{
    foreach ($cuts as $i => $cut) {
        if ($v < $cut) return $labels[$i];
    }
    return $labels[count($labels) - 1];
}

function ptsLoadRows(string $path): array
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
        foreach (['sample_n','win','sashi','makuri','makurizashi'] as $suffix) {
            $required[] = "c{$c}_1y_{$suffix}";
        }
    }
    foreach ($required as $col) {
        if (!array_key_exists($col, $idx)) {
            fclose($fh);
            throw new RuntimeException("必要列がありません {$col}: {$path}");
        }
    }

    $hasHonmei = array_key_exists('honmei_head', $idx);
    $hasTaikou = array_key_exists('taikou_head', $idx);
    $hasRaceNo = array_key_exists('race_number', $idx);
    $hasStadium = array_key_exists('stadium_name', $idx);

    $rows = [];
    while (($data = fgetcsv($fh)) !== false) {
        if (count($data) < count($header)) continue;

        $raceCode = trim((string)($data[$idx['race_code']] ?? ''));
        if (!preg_match('/^(\d{8})[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $raceCode, $m)) continue;
        $date = $m[1];

        $actual = trim((string)($data[$idx['actual_1st']] ?? ''));
        if (!preg_match('/^[1-6]$/', $actual)) continue;

        $n1 = (int)($data[$idx['c1_1y_sample_n']] ?? 0);
        $nige = ptsNum($data[$idx['c1_1y_nige']] ?? null);
        if ($n1 < PTS_MIN_SAMPLE_N || $nige === null) continue;

        $attacks = [];
        $sashis = [];
        $makuris = [];
        $makurizashis = [];
        $wins = [];

        for ($c = 2; $c <= 6; $c++) {
            $n = (int)($data[$idx["c{$c}_1y_sample_n"]] ?? 0);
            if ($n < PTS_MIN_SAMPLE_N) continue;

            $win = ptsNum($data[$idx["c{$c}_1y_win"]] ?? null);
            $sashi = ptsNum($data[$idx["c{$c}_1y_sashi"]] ?? null);
            $makuri = ptsNum($data[$idx["c{$c}_1y_makuri"]] ?? null);
            $makurizashi = ptsNum($data[$idx["c{$c}_1y_makurizashi"]] ?? null);
            if ($win === null || $sashi === null || $makuri === null || $makurizashi === null) continue;

            $attacks[$c] = $sashi + $makuri;
            $sashis[$c] = $sashi;
            $makuris[$c] = $makuri;
            $makurizashis[$c] = $makurizashi;
            $wins[$c] = $win;
        }

        if (!$attacks) continue;

        arsort($attacks, SORT_NUMERIC);
        $attackCourses = array_keys($attacks);
        $attackVals = array_values($attacks);
        $attackMax = (float)$attackVals[0];
        $attackSecond = isset($attackVals[1]) ? (float)$attackVals[1] : 0.0;
        $attackGap = $attackMax - $attackSecond;
        $attackTopCourse = (int)$attackCourses[0];

        $count20 = 0;
        $count25 = 0;
        foreach ($attacks as $a) {
            if ($a >= 20.0) $count20++;
            if ($a >= 25.0) $count25++;
        }

        $raceNo = (int)$m[2];
        if ($hasRaceNo) {
            $rawNo = trim((string)($data[$idx['race_number']] ?? ''));
            if (ctype_digit($rawNo)) $raceNo = (int)$rawNo;
        }

        $honmei = null;
        if ($hasHonmei) {
            $h = trim((string)($data[$idx['honmei_head']] ?? ''));
            if (preg_match('/^[1-6]$/', $h)) $honmei = (int)$h;
        }
        $taikou = null;
        if ($hasTaikou) {
            $t = trim((string)($data[$idx['taikou_head']] ?? ''));
            if (preg_match('/^[1-6]$/', $t)) $taikou = (int)$t;
        }

        $rows[$raceCode] = [
            'race_code' => $raceCode,
            'date' => $date,
            'race_no' => $raceNo,
            'stadium' => $hasStadium ? trim((string)($data[$idx['stadium_name']] ?? '')) : '',
            'actual_1st' => (int)$actual,
            'nige' => $nige,
            'attack_max' => $attackMax,
            'attack_second' => $attackSecond,
            'attack_gap' => $attackGap,
            'attack_top_course' => $attackTopCourse,
            'attack_count20' => $count20,
            'attack_count25' => $count25,
            'sashi_max' => max($sashis),
            'makuri_max' => max($makuris),
            'makurizashi_max' => max($makurizashis),
            'outer_win_max' => max($wins),
            'honmei_head' => $honmei,
            'taikou_head' => $taikou,
        ];
    }
    fclose($fh);
    return $rows;
}

function ptsLoadPayouts(PDO $pdo, array $raceCodes): array
{
    $out = [];
    foreach (array_chunk($raceCodes, 500) as $chunk) {
        if (!$chunk) continue;
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $sql = "
WITH payout AS (
    SELECT race_code, MAX(COALESCE(trifecta_payout, 0))::numeric AS trifecta_payout
    FROM boat_race.race_payouts
    WHERE race_code IN ({$ph})
    GROUP BY race_code
), quality AS (
    SELECT race_code,
           COUNT(*) FILTER (WHERE TRIM(rank)='1')::int AS r1,
           COUNT(*) FILTER (WHERE TRIM(rank)='2')::int AS r2,
           COUNT(*) FILTER (WHERE TRIM(rank)='3')::int AS r3
    FROM boat_race.race_result_detail
    WHERE race_code IN ({$ph})
    GROUP BY race_code
)
SELECT p.race_code, p.trifecta_payout,
       COALESCE(q.r1,0) AS r1, COALESCE(q.r2,0) AS r2, COALESCE(q.r3,0) AS r3
FROM payout p
LEFT JOIN quality q ON q.race_code=p.race_code
";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($chunk, $chunk));
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $code = trim((string)$r['race_code']);
            $payout = (int)($r['trifecta_payout'] ?? 0);
            $normal = ((int)$r['r1'] === 1 && (int)$r['r2'] === 1 && (int)$r['r3'] === 1);
            $out[$code] = ['payout' => $payout, 'valid' => ($payout > 0 && $normal), 'normal' => $normal];
        }
    }
    return $out;
}

function ptsMetrics(array $rows): array
{
    $n = count($rows);
    $mid = 0; $high = 0; $big = 0; $ge5 = 0;
    $non1 = 0;
    $payouts = [];
    foreach ($rows as $r) {
        $p = (int)$r['payout'];
        $payouts[] = $p;
        if ($p >= 5000 && $p < 10000) $mid++;
        if ($p >= 10000 && $p < 20000) $high++;
        if ($p >= 20000) $big++;
        if ($p >= 5000) $ge5++;
        if ((int)$r['actual_1st'] !== 1) $non1++;
    }
    return [
        'n' => $n,
        'mid_rate' => ptsPct($mid, $n),
        'high_rate' => ptsPct($high, $n),
        'big_rate' => ptsPct($big, $n),
        'ge5_rate' => ptsPct($ge5, $n),
        'non1_rate' => ptsPct($non1, $n),
        'avg' => $n > 0 ? array_sum($payouts) / $n : 0.0,
        'median' => ptsMedian($payouts),
    ];
}

function ptsFeatures(array $r): array
{
    $f = [];

    $f['1号艇逃げ率帯'] = ptsBucket(
        (float)$r['nige'],
        [20,30,40,50,60,70,80],
        ['<20','20-29','30-39','40-49','50-59','60-69','70-79','80+']
    );
    $f['差し+捲り最大帯'] = ptsBucket(
        (float)$r['attack_max'],
        [15,20,25,30,40],
        ['<15','15-19','20-24','25-29','30-39','40+']
    );
    $f['差し最大帯'] = ptsBucket(
        (float)$r['sashi_max'],
        [10,15,20,25],
        ['<10','10-14','15-19','20-24','25+']
    );
    $f['捲り最大帯'] = ptsBucket(
        (float)$r['makuri_max'],
        [10,15,20,25,30],
        ['<10','10-14','15-19','20-24','25-29','30+']
    );
    $f['捲り差し最大帯'] = ptsBucket(
        (float)$r['makurizashi_max'],
        [10,15,20,25,30],
        ['<10','10-14','15-19','20-24','25-29','30+']
    );
    $f['外コース勝率最大帯'] = ptsBucket(
        (float)$r['outer_win_max'],
        [15,20,25,30,35,40],
        ['<15','15-19','20-24','25-29','30-34','35-39','40+']
    );

    $c20 = (int)$r['attack_count20'];
    $f['攻め艇数20+'] = $c20 >= 3 ? '3艇以上' : (string)$c20 . '艇';
    $c25 = (int)$r['attack_count25'];
    $f['攻め艇数25+'] = $c25 >= 3 ? '3艇以上' : (string)$c25 . '艇';

    $f['攻め上位差'] = ptsBucket(
        (float)$r['attack_gap'],
        [3,6,10],
        ['<3pt','3-5pt','6-9pt','10pt+']
    );
    $f['最強攻めコース'] = (string)$r['attack_top_course'] . 'C';

    $rn = (int)$r['race_no'];
    $f['R帯'] = $rn <= 4 ? '1-4R' : ($rn <= 8 ? '5-8R' : '9-12R');

    $h = $r['honmei_head'];
    if ($h !== null) {
        $f['Web本命頭'] = $h === 1 ? '1号艇' : ($h <= 3 ? '2-3号艇' : '4-6号艇');
    }

    $t = $r['taikou_head'];
    if ($h !== null && $t !== null) {
        if ($h === 1 && $t !== 1) $rel = '本命1・対抗非1';
        elseif ($h !== 1 && $t === 1) $rel = '本命非1・対抗1';
        elseif ($h !== 1 && $t !== 1) $rel = '本命対抗とも非1';
        else $rel = '本命対抗とも1';
        $f['Web頭関係'] = $rel;
    }

    return $f;
}

function ptsGroupStats(array $rows): array
{
    $groups = [];
    foreach ($rows as $r) {
        foreach (ptsFeatures($r) as $family => $bucket) {
            $groups[$family][$bucket][] = $r;
        }
    }
    $out = [];
    foreach ($groups as $family => $buckets) {
        foreach ($buckets as $bucket => $rs) {
            $out[$family][$bucket] = ptsMetrics($rs);
        }
        ksort($out[$family], SORT_NATURAL);
    }
    return $out;
}

function ptsStableCandidates(array $trainStats, array $forwardStats, array $baseTrain, array $baseForward, string $metric): array
{
    $items = [];
    foreach ($trainStats as $family => $buckets) {
        foreach ($buckets as $bucket => $tr) {
            $fw = $forwardStats[$family][$bucket] ?? null;
            if ($fw === null) continue;
            if ($tr['n'] < PTS_MIN_TRAIN_N || $fw['n'] < PTS_MIN_FORWARD_N) continue;
            $trainLift = (float)$tr[$metric] - (float)$baseTrain[$metric];
            $forwardLift = (float)$fw[$metric] - (float)$baseForward[$metric];
            if ($trainLift <= 0.0 || $forwardLift <= 0.0) continue;
            $items[] = [
                'family' => $family,
                'bucket' => $bucket,
                'train_n' => $tr['n'],
                'train_rate' => $tr[$metric],
                'train_lift' => $trainLift,
                'forward_n' => $fw['n'],
                'forward_rate' => $fw[$metric],
                'forward_lift' => $forwardLift,
                'score' => min($trainLift, $forwardLift),
            ];
        }
    }
    usort($items, static function(array $a, array $b): int {
        if (abs($a['score'] - $b['score']) > 1e-9) return $b['score'] <=> $a['score'];
        return $b['train_n'] <=> $a['train_n'];
    });
    return $items;
}

function ptsPrintCandidates(string $title, array $items): void
{
    echo "\n【{$title}：探索期間・前方期間の両方で母体超え】\n";
    if (!$items) {
        echo "該当なし\n";
        return;
    }
    printf("%-4s %-22s %-18s %8s %9s %9s %8s %9s %9s\n",
        '順位','きっかけ','帯/状態','学習N','学習率','学習差','前方N','前方率','前方差');
    echo str_repeat('-', 118) . "\n";
    foreach (array_slice($items, 0, 10) as $i => $x) {
        printf("%3d  %-22s %-18s %8d %8.2f%% %+8.2fpt %8d %8.2f%% %+8.2fpt\n",
            $i + 1, $x['family'], $x['bucket'], $x['train_n'], $x['train_rate'], $x['train_lift'],
            $x['forward_n'], $x['forward_rate'], $x['forward_lift']);
    }
}

function ptsPrintFamily(string $family, array $trainStats, array $forwardStats, array $baseTrain, array $baseForward): void
{
    if (!isset($trainStats[$family])) return;
    echo "\n【{$family}】\n";
    printf("%-18s %7s %8s %8s %8s | %7s %8s %8s %8s\n",
        '帯/状態','学習N','中配当','高配当','大穴','前方N','中配当','高配当','大穴');
    echo str_repeat('-', 100) . "\n";
    foreach ($trainStats[$family] as $bucket => $tr) {
        $fw = $forwardStats[$family][$bucket] ?? ['n'=>0,'mid_rate'=>0,'high_rate'=>0,'big_rate'=>0];
        printf("%-18s %7d %7.2f%% %7.2f%% %7.2f%% | %7d %7.2f%% %7.2f%% %7.2f%%\n",
            $bucket,$tr['n'],$tr['mid_rate'],$tr['high_rate'],$tr['big_rate'],
            $fw['n'],$fw['mid_rate'],$fw['high_rate'],$fw['big_rate']);
    }
    printf("%-18s %7d %7.2f%% %7.2f%% %7.2f%% | %7d %7.2f%% %7.2f%% %7.2f%%\n",
        '母体',$baseTrain['n'],$baseTrain['mid_rate'],$baseTrain['high_rate'],$baseTrain['big_rate'],
        $baseForward['n'],$baseForward['mid_rate'],$baseForward['high_rate'],$baseForward['big_rate']);
}

$allRows = [];
foreach (array_slice($argv, 1) as $path) {
    try {
        foreach (ptsLoadRows($path) as $code => $r) {
            $allRows[$code] = $r;
        }
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}

if (!$allRows) {
    fwrite(STDERR, "分析可能な行がありません\n");
    exit(1);
}
ksort($allRows);

try {
    $pdo = getPDO();
    $payoutMap = ptsLoadPayouts($pdo, array_keys($allRows));
} catch (Throwable $e) {
    fwrite(STDERR, "払戻取得失敗: " . $e->getMessage() . "\n");
    exit(1);
}

$validRows = [];
$missing = 0;
$special = 0;
foreach ($allRows as $code => $r) {
    if (!isset($payoutMap[$code]) || ($payoutMap[$code]['payout'] ?? 0) <= 0) {
        $missing++;
        continue;
    }
    if (!($payoutMap[$code]['normal'] ?? false)) {
        $special++;
        continue;
    }
    $r['payout'] = (int)$payoutMap[$code]['payout'];
    $validRows[$code] = $r;
}

$trainRows = [];
$forwardRows = [];
$dateMin = null;
$dateMax = null;
foreach ($validRows as $code => $r) {
    $dateMin = $dateMin === null || $r['date'] < $dateMin ? $r['date'] : $dateMin;
    $dateMax = $dateMax === null || $r['date'] > $dateMax ? $r['date'] : $dateMax;
    if ($r['date'] < PTS_HOLDOUT_START) $trainRows[$code] = $r;
    else $forwardRows[$code] = $r;
}

if (!$trainRows || !$forwardRows) {
    fwrite(STDERR, "探索期間または前方期間の行がありません\n");
    exit(1);
}

$baseTrain = ptsMetrics($trainRows);
$baseForward = ptsMetrics($forwardRows);
$trainStats = ptsGroupStats($trainRows);
$forwardStats = ptsGroupStats($forwardRows);

$outPath = __DIR__ . '/output/payout_trigger_exploration_' . $dateMin . '_' . $dateMax . '.csv';
$out = fopen($outPath, 'wb');
if ($out === false) {
    fwrite(STDERR, "出力CSVを作れません: {$outPath}\n");
    exit(1);
}
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, [
    'family','bucket',
    'train_n','train_mid_rate','train_mid_lift','train_high_rate','train_high_lift','train_big_rate','train_big_lift','train_ge5_rate','train_avg','train_median',
    'forward_n','forward_mid_rate','forward_mid_lift','forward_high_rate','forward_high_lift','forward_big_rate','forward_big_lift','forward_ge5_rate','forward_avg','forward_median'
]);
foreach ($trainStats as $family => $buckets) {
    foreach ($buckets as $bucket => $tr) {
        $fw = $forwardStats[$family][$bucket] ?? ['n'=>0,'mid_rate'=>0,'high_rate'=>0,'big_rate'=>0,'ge5_rate'=>0,'avg'=>0,'median'=>0];
        fputcsv($out, [
            $family,$bucket,
            $tr['n'],$tr['mid_rate'],$tr['mid_rate']-$baseTrain['mid_rate'],$tr['high_rate'],$tr['high_rate']-$baseTrain['high_rate'],$tr['big_rate'],$tr['big_rate']-$baseTrain['big_rate'],$tr['ge5_rate'],$tr['avg'],$tr['median'],
            $fw['n'],$fw['mid_rate'],$fw['mid_rate']-$baseForward['mid_rate'],$fw['high_rate'],$fw['high_rate']-$baseForward['high_rate'],$fw['big_rate'],$fw['big_rate']-$baseForward['big_rate'],$fw['ge5_rate'],$fw['avg'],$fw['median'],
        ]);
    }
}
fclose($out);

$line = str_repeat('=', 132);
echo $line . "\n";
echo "中配当・高配当 きっかけ候補探索（単変量・前方確認付き）\n";
echo "期間           : " . ptsFmtDate($dateMin) . " ～ " . ptsFmtDate($dateMax) . "\n";
echo "探索期間       : ～ 2026-08-14\n";
echo "前方確認       : 2026-08-15 ～\n";
echo "読込レース     : " . number_format(count($allRows)) . "R\n";
echo "払戻分析可能   : " . number_format(count($validRows)) . "R\n";
echo "払戻なし/0     : {$missing}R\n";
echo "同着等特殊除外 : {$special}R\n";
echo "安定候補基準   : 探索N>=" . PTS_MIN_TRAIN_N . " / 前方N>=" . PTS_MIN_FORWARD_N . " / 両期間とも母体超え\n";
echo "出力CSV        : " . str_replace(__DIR__ . '/', 'analysis/', $outPath) . "\n";
echo $line . "\n";

printf("探索母体 N=%d  中配当=%5.2f%%  高配当=%5.2f%%  大穴=%5.2f%%  5千以上=%5.2f%%  平均=%7.0f円  中央=%6.0f円\n",
    $baseTrain['n'],$baseTrain['mid_rate'],$baseTrain['high_rate'],$baseTrain['big_rate'],$baseTrain['ge5_rate'],$baseTrain['avg'],$baseTrain['median']);
printf("前方母体 N=%d  中配当=%5.2f%%  高配当=%5.2f%%  大穴=%5.2f%%  5千以上=%5.2f%%  平均=%7.0f円  中央=%6.0f円\n",
    $baseForward['n'],$baseForward['mid_rate'],$baseForward['high_rate'],$baseForward['big_rate'],$baseForward['ge5_rate'],$baseForward['avg'],$baseForward['median']);

ptsPrintCandidates('中配当 5,000〜9,999円の安定候補', ptsStableCandidates($trainStats,$forwardStats,$baseTrain,$baseForward,'mid_rate'));
ptsPrintCandidates('高配当 10,000〜19,999円の安定候補', ptsStableCandidates($trainStats,$forwardStats,$baseTrain,$baseForward,'high_rate'));
ptsPrintCandidates('大穴 20,000円以上の安定候補', ptsStableCandidates($trainStats,$forwardStats,$baseTrain,$baseForward,'big_rate'));

foreach (['1号艇逃げ率帯','差し+捲り最大帯','攻め艇数20+','攻め上位差','最強攻めコース','Web頭関係','R帯'] as $family) {
    ptsPrintFamily($family,$trainStats,$forwardStats,$baseTrain,$baseForward);
}

echo "\n【次の判断】\n";
echo "1. ここでは1条件で穴検索を決めない。複数の『きっかけ候補』を拾う段階。\n";
echo "2. 同じ系統の帯違いを何個も数えず、1ファミリー1シグナルに整理してから重なりを見る。\n";
echo "3. 中配当・高配当・大穴で効くきっかけが違う可能性があるため、別々に候補を残す。\n";
echo "4. この前方期間も候補確認に使うため、最終スコアを決めた後はさらに新しい未使用期間で再確認する。\n";
echo $line . "\n";
