<?php

declare(strict_types=1);

/**
 * 指定日の「イン崩壊」候補について、固定済み3方式を同じ母集団で比較する。
 *
 * FIX2_S3_T2 : 非インAI3 Top2頭 × P(2着|頭) Top3 × P(3着|頭,2着) Top2（最大12点）
 * FIX2_S2_T3 : 非インAI3 Top2頭 × P(2着|頭) Top2 × P(3着|頭,2着) Top3（最大12点）
 * FIX2_S2_T2 : 非インAI3 Top2頭 × P(2着|頭) Top2 × P(3着|頭,2着) Top2（最大8点）
 *
 * 注意:
 * - 指定日の参考答え合わせ専用。未使用前方検証には数えない。
 * - 荒れ判定、AI3頭順位、cut、候補数は変更しない。
 * - 本命/対抗/PredictionLogic/本番買い目は変更しない。
 *
 * Usage:
 *   php analysis/compare_payout_signal_fixed12_three_date.php 2026-09-06
 */

require_once __DIR__ . '/../web/controllers/IndexController.php';
require_once __DIR__ . '/../web/logic/AiTrioRateLogic.php';
require_once __DIR__ . '/../web/logic/TrifectaProbabilityLogic.php';
require_once __DIR__ . '/../web/logic/BaseWinRateLogic.php';
require_once __DIR__ . '/../common/PayoutSignalFeatureBuilder.php';
require_once __DIR__ . '/../common/PayoutSignalClassifier.php';
require_once __DIR__ . '/../common/db_connect.php';

date_default_timezone_set('Asia/Tokyo');

const SPECS3 = [
    'FIX2_S3_T2' => [3, 2],
    'FIX2_S2_T3' => [2, 3],
    'FIX2_S2_T2' => [2, 2],
];

function fail3(string $message, int $code = 1): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($code);
}

function boatKey3(int $a, int $b, int $c): string
{
    return $a . '-' . $b . '-' . $c;
}

/** @return array<int,int> */
function normalizeCourseMap3(array $map): array
{
    $out = [];
    for ($boat = 1; $boat <= 6; $boat++) {
        $course = (int)($map[$boat] ?? $map[(string)$boat] ?? 0);
        if ($course < 1 || $course > 6) return [];
        $out[$boat] = $course;
    }
    $courses = array_values($out);
    sort($courses);
    return $courses === [1,2,3,4,5,6] ? $out : [];
}

/** @return array<int,int> */
function currentCut3(array $finalPredictions): array
{
    $cut = [];
    foreach ($finalPredictions as $key => $row) {
        if (!is_array($row)) continue;
        $boat = (int)($row['lane_number'] ?? $row['teiban'] ?? $key);
        if ($boat >= 1 && $boat <= 6 && (int)($row['kiru'] ?? 0) === 1) {
            $cut[$boat] = $boat;
        }
    }
    ksort($cut);
    return array_values($cut);
}

/** @return array{actual:?string,payout:?int} */
function loadActual3(string $raceCode): array
{
    try {
        $pdo = getPDO();
        $stmt = $pdo->prepare(
            "SELECT lane_number, rank::text AS rank_text\n"
            . "FROM boat_race.race_result_detail\n"
            . "WHERE race_code = :race_code\n"
            . "  AND rank::text ~ '^[1-3]$'\n"
            . "ORDER BY rank::int"
        );
        $stmt->execute([':race_code' => $raceCode]);
        $byRank = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rank = (int)($row['rank_text'] ?? 0);
            $boat = (int)($row['lane_number'] ?? 0);
            if ($rank >= 1 && $rank <= 3 && $boat >= 1 && $boat <= 6) {
                $byRank[$rank] = $boat;
            }
        }
        $actual = count($byRank) === 3
            ? boatKey3((int)$byRank[1], (int)$byRank[2], (int)$byRank[3])
            : null;

        $stmt = $pdo->prepare(
            "SELECT trifecta_payout FROM boat_race.race_payouts WHERE race_code = :race_code LIMIT 1"
        );
        $stmt->execute([':race_code' => $raceCode]);
        $raw = $stmt->fetchColumn();
        $payout = is_numeric($raw) && (int)$raw > 0 ? (int)$raw : null;
        return ['actual' => $actual, 'payout' => $payout];
    } catch (Throwable) {
        return ['actual' => null, 'payout' => null];
    }
}

/** @return array<int,float> */
function secondScores3(int $head, array $rows): array
{
    $mass = array_fill(1, 6, 0.0);
    $headMass = 0.0;
    foreach ($rows as $row) {
        $boats = is_array($row['boats'] ?? null) ? $row['boats'] : [];
        if (count($boats) !== 3 || (int)$boats[0] !== $head) continue;
        $second = (int)$boats[1];
        $p = (float)($row['probability'] ?? 0.0);
        if ($second < 1 || $second > 6 || $second === $head || $p < 0.0) continue;
        $headMass += $p;
        $mass[$second] += $p;
    }
    if ($headMass > 0.0) {
        foreach ($mass as $boat => $p) $mass[$boat] = $p / $headMass;
    }
    return $mass;
}

/** @return array<int,float> */
function thirdScores3(int $head, int $second, array $rows): array
{
    $mass = array_fill(1, 6, 0.0);
    $pairMass = 0.0;
    foreach ($rows as $row) {
        $boats = is_array($row['boats'] ?? null) ? $row['boats'] : [];
        if (count($boats) !== 3) continue;
        if ((int)$boats[0] !== $head || (int)$boats[1] !== $second) continue;
        $third = (int)$boats[2];
        $p = (float)($row['probability'] ?? 0.0);
        if ($third < 1 || $third > 6 || in_array($third, [$head, $second], true) || $p < 0.0) continue;
        $pairMass += $p;
        $mass[$third] += $p;
    }
    if ($pairMass > 0.0) {
        foreach ($mass as $boat => $p) $mass[$boat] = $p / $pairMass;
    }
    return $mass;
}

/** @return array<int,int> */
function topFromScores3(array $scores, array $eligible, int $k): array
{
    usort($eligible, static function (int $a, int $b) use ($scores): int {
        $cmp = ((float)($scores[$b] ?? 0.0)) <=> ((float)($scores[$a] ?? 0.0));
        return $cmp !== 0 ? $cmp : ($a <=> $b);
    });
    return array_slice($eligible, 0, min($k, count($eligible)));
}

/** @return array{bets:array<string,array{0:int,1:int,2:int}>,detail:array<int,array<string,mixed>>} */
function buildScenario3(array $heads, array $rows, array $cut, int $secondCount, int $thirdCount): array
{
    $bets = [];
    $detail = [];
    $cutMapBase = array_fill_keys($cut, true);

    foreach ($heads as $head) {
        $head = (int)$head;
        $cutMap = $cutMapBase;
        unset($cutMap[$head]);

        $eligible = [];
        for ($boat = 1; $boat <= 6; $boat++) {
            if ($boat !== $head && !isset($cutMap[$boat])) $eligible[] = $boat;
        }
        if (count($eligible) < 2) continue;

        $seconds = topFromScores3(secondScores3($head, $rows), $eligible, $secondCount);
        $headDetail = ['second' => $seconds, 'third_by_second' => []];

        foreach ($seconds as $second) {
            $thirdEligible = array_values(array_filter(
                $eligible,
                static fn(int $x): bool => $x !== (int)$second
            ));
            $thirds = topFromScores3(
                thirdScores3($head, (int)$second, $rows),
                $thirdEligible,
                $thirdCount
            );
            $headDetail['third_by_second'][(int)$second] = $thirds;
            foreach ($thirds as $third) {
                $key = boatKey3($head, (int)$second, (int)$third);
                $bets[$key] = [$head, (int)$second, (int)$third];
            }
        }
        $detail[$head] = $headDetail;
    }

    ksort($bets);
    return ['bets' => $bets, 'detail' => $detail];
}

$date = trim((string)($argv[1] ?? ''));
$dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
if ($dt === false || $dt->format('Y-m-d') !== $date) {
    fail3('Usage: php analysis/compare_payout_signal_fixed12_three_date.php YYYY-MM-DD');
}

$placeCodes = [
    '桐生'=>'KRY','戸田'=>'TDA','江戸川'=>'EDG','平和島'=>'HWJ','多摩川'=>'TMG','浜名湖'=>'HMN',
    '蒲郡'=>'GMG','常滑'=>'TKN','津'=>'TSU','三国'=>'MKN','びわこ'=>'BWK','住之江'=>'SME',
    '尼崎'=>'AMG','鳴門'=>'NRT','丸亀'=>'MRG','児島'=>'KJM','宮島'=>'MYJ','徳山'=>'TKY',
    '下関'=>'SMS','若松'=>'WKM','芦屋'=>'ASY','福岡'=>'FKO','唐津'=>'KRT','大村'=>'OMR',
];

$listScript = __DIR__ . '/list_today_payout_signals.php';
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($listScript)
    . ' ' . escapeshellarg($date) . ' all';
$listOutput = shell_exec($cmd . ' 2>&1');
if (!is_string($listOutput) || trim($listOutput) === '') {
    fail3('配当サイン候補一覧を取得できませんでした');
}

$targets = [];
foreach (preg_split('/\R/u', $listOutput) ?: [] as $line) {
    if (!preg_match('/^\s*(\S+)\s+(\d{1,2})R\s+(Web反映|暫定)\s+\|.*\|\s*(イン崩壊|ヒモ荒れ|複合高配当|平常)\s*\|/u', $line, $m)) {
        continue;
    }
    if ((string)$m[4] !== 'イン崩壊') continue;
    $placeName = (string)$m[1];
    $placeCode = $placeCodes[$placeName] ?? null;
    if ($placeCode === null) continue;
    $raceNo = (int)$m[2];
    $targets[] = [
        'place' => $placeName,
        'place_code' => $placeCode,
        'race_no' => $raceNo,
        'race_code' => $dt->format('Ymd') . $placeCode . sprintf('%02d', $raceNo),
    ];
}

usort($targets, static function (array $a, array $b): int {
    if ($a['place'] !== $b['place']) return strcmp((string)$a['place'], (string)$b['place']);
    return (int)$a['race_no'] <=> (int)$b['race_no'];
});

if ($targets === []) {
    echo "対象日のイン崩壊候補はありません。\n";
    exit(0);
}

$stats = [];
foreach (array_keys(SPECS3) as $method) {
    $stats[$method] = [
        'hits' => 0,
        'points' => 0,
        'invest' => 0.0,
        'return' => 0.0,
        'fixed_return' => 0.0,
        'fail_hits' => 0,
        'head_pair_hits' => 0,
    ];
}

$rowsOut = [];
$detailOut = [];
$inFail = 0;
$headHits = 0;
$errors = 0;

foreach ($targets as $idx => $target) {
    $raceCode = (string)$target['race_code'];
    fprintf(STDERR, "[%d/%d] %s %dR 3方式を計算中...\n",
        $idx + 1, count($targets), (string)$target['place'], (int)$target['race_no']);

    $_GET = [
        'date' => $dt->format('Y-m-d'),
        'place' => (string)$target['place_code'],
        'race' => (string)$target['race_no'],
    ];
    $_POST = [];

    try {
        $controller = new IndexController();
        $view = $controller->handle();
    } catch (Throwable $e) {
        $rowsOut[] = [...$target, 'error' => 'view: ' . $e->getMessage()];
        $errors++;
        continue;
    }

    $kimarite = is_array($view['kimarite_data'] ?? null) ? $view['kimarite_data'] : [];
    $summary = [
        'honmei_head' => $view['honmei_head'] ?? null,
        'taikou_head' => $view['taikou_head'] ?? null,
    ];
    $feature = PayoutSignalFeatureBuilder::build((int)$target['race_no'], $kimarite, $summary);
    if (($feature['status'] ?? '') !== 'ok') {
        $rowsOut[] = [...$target, 'error' => 'signal feature not ready'];
        $errors++;
        continue;
    }
    $class = PayoutSignalClassifier::classify((array)$feature['input']);
    if ((string)($class['chaos']['primary'] ?? '平常') !== 'イン崩壊') {
        $rowsOut[] = [...$target, 'error' => 'current classification changed'];
        $errors++;
        continue;
    }

    $courseByBoat = normalizeCourseMap3((array)($view['prediction_course_by_boat'] ?? []));
    if ($courseByBoat === []) $courseByBoat = array_combine(range(1,6), range(1,6));
    $inBoat = 0;
    foreach ($courseByBoat as $boat => $course) {
        if ((int)$course === 1) { $inBoat = (int)$boat; break; }
    }
    if ($inBoat < 1 || $inBoat > 6) {
        $rowsOut[] = [...$target, 'error' => 'in boat missing'];
        $errors++;
        continue;
    }

    $results = is_array($view['results'] ?? null) ? $view['results'] : [];
    $tenji = is_array($view['tenji_list'] ?? null) ? $view['tenji_list'] : [];
    $corrected = is_array($view['corrected_win_rate_data'] ?? null) ? $view['corrected_win_rate_data'] : [];
    $correctedBoats = is_array($corrected['boats'] ?? null) ? $corrected['boats'] : [];

    $mode = '展示反映済';
    $aiTrioLogic = new AiTrioRateLogic();
    $aiTrio = $aiTrioLogic->calculate($raceCode, $results, $tenji, $courseByBoat, false);

    if (($corrected['status'] ?? '') !== 'ok' || ($aiTrio['status'] ?? '') !== 'ok') {
        $mode = '暫定';
        $courseByBoat = array_combine(range(1,6), range(1,6));
        $inBoat = 1;

        $baseLogic = new BaseWinRateLogic();
        $base = $baseLogic->calculate($raceCode, $courseByBoat);
        $baseBoats = is_array($base['boats'] ?? null) ? $base['boats'] : [];
        $correctedBoats = [];
        for ($boat = 1; $boat <= 6; $boat++) {
            $rate = $baseBoats[$boat]['normalized_rate']
                ?? $baseBoats[(string)$boat]['normalized_rate']
                ?? null;
            if (is_numeric($rate)) $correctedBoats[$boat] = ['corrected_rate' => (float)$rate];
        }
        $neutralTenji = [];
        for ($boat = 1; $boat <= 6; $boat++) {
            $neutralTenji[] = ['teiban'=>$boat, 'tenji_course'=>$boat, 'final_2nd_score'=>0.0];
        }
        $aiTrio = $aiTrioLogic->calculate($raceCode, $results, $neutralTenji, $courseByBoat, true);
    }

    if (($aiTrio['status'] ?? '') !== 'ok') {
        $rowsOut[] = [...$target, 'error' => 'AI3 unavailable'];
        $errors++;
        continue;
    }
    $aiTrioBoats = is_array($aiTrio['boats'] ?? null) ? $aiTrio['boats'] : [];

    $trifectaLogic = new TrifectaProbabilityLogic();
    $trifecta = $trifectaLogic->calculate($raceCode, $correctedBoats, $aiTrioBoats, $courseByBoat);
    if (($trifecta['status'] ?? '') !== 'ok') {
        $rowsOut[] = [...$target, 'error' => '120 patterns unavailable'];
        $errors++;
        continue;
    }
    $trifectaRows = is_array($trifecta['rows'] ?? null) ? $trifecta['rows'] : [];

    $ranked = [];
    for ($boat = 1; $boat <= 6; $boat++) {
        if ($boat === $inBoat) continue;
        $rate = $aiTrioBoats[$boat]['ai_rate'] ?? $aiTrioBoats[(string)$boat]['ai_rate'] ?? null;
        if (is_numeric($rate)) $ranked[$boat] = (float)$rate;
    }
    uksort($ranked, static function (int|string $a, int|string $b) use ($ranked): int {
        $cmp = $ranked[(int)$b] <=> $ranked[(int)$a];
        return $cmp !== 0 ? $cmp : ((int)$a <=> (int)$b);
    });
    $heads = array_slice(array_map('intval', array_keys($ranked)), 0, 2);
    if (count($heads) < 2) {
        $rowsOut[] = [...$target, 'error' => 'head ranking unavailable'];
        $errors++;
        continue;
    }

    $cut = currentCut3((array)($view['final_predictions'] ?? []));
    $actualInfo = loadActual3($raceCode);
    $actual = $actualInfo['actual'];
    $payout = $actualInfo['payout'];
    $actualParts = $actual !== null ? array_map('intval', explode('-', $actual)) : [];
    $actualFirst = $actualParts[0] ?? 0;
    $actualSecond = $actualParts[1] ?? 0;
    $inFailed = $actualFirst > 0 && $actualFirst !== $inBoat;
    $headHit = $actualFirst > 0 && in_array($actualFirst, $heads, true);
    if ($inFailed) $inFail++;
    if ($inFailed && $headHit) $headHits++;

    $scenarios = [];
    foreach (SPECS3 as $method => [$secondCount, $thirdCount]) {
        $scenario = buildScenario3($heads, $trifectaRows, $cut, (int)$secondCount, (int)$thirdCount);
        $bets = $scenario['bets'];
        $cnt = count($bets);
        $hit = $actual !== null && isset($bets[$actual]);

        $pairHit = false;
        if ($headHit && $actualFirst > 0 && $actualSecond > 0) {
            $secondList = $scenario['detail'][$actualFirst]['second'] ?? [];
            $pairHit = in_array($actualSecond, $secondList, true);
        }

        $scenarios[$method] = [
            'points' => $cnt,
            'hit' => $hit,
            'pair_hit' => $pairHit,
            'bets' => $bets,
        ];

        $stats[$method]['points'] += $cnt;
        $stats[$method]['invest'] += $cnt * 100.0;
        if ($hit) {
            $stats[$method]['hits']++;
            if ($inFailed) $stats[$method]['fail_hits']++;
            if (is_int($payout)) {
                $stats[$method]['return'] += $payout;
                if ($cnt > 0) $stats[$method]['fixed_return'] += $payout * ((1000.0 / $cnt) / 100.0);
            }
        }
        if ($pairHit) $stats[$method]['head_pair_hits']++;
    }

    $rowsOut[] = [
        ...$target,
        'mode' => $mode,
        'in_boat' => $inBoat,
        'heads' => $heads,
        'actual' => $actual,
        'payout' => $payout,
        'in_failed' => $inFailed,
        'head_hit' => $headHit,
        'scenarios' => $scenarios,
    ];

    $detail = str_repeat('=', 120)
        . "\n{$target['place']} {$target['race_no']}R {$raceCode} {$mode}"
        . "\n1C艇={$inBoat} / 頭=" . implode(',', $heads)
        . " / cut=" . ($cut === [] ? '-' : implode(',', $cut))
        . "\n実3連単=" . ($actual ?? '-') . ' / ' . ($payout !== null ? number_format($payout) . '円' : '-');
    foreach (SPECS3 as $method => $_) {
        $s = $scenarios[$method];
        $detail .= "\n{$method}={$s['points']}点 / " . ($s['hit'] ? '的中' : '不的中')
            . "\n買い目=" . implode(', ', array_keys($s['bets']));
    }
    $detailOut[] = $detail;
}

$validRows = array_values(array_filter($rowsOut, static fn(array $r): bool => !isset($r['error'])));
$n = count($validRows);

$outputDir = __DIR__ . '/output';
if (!is_dir($outputDir)) @mkdir($outputDir, 0775, true);
$outPath = $outputDir . '/payout_signal_fixed12_three_compare_' . $dt->format('Ymd') . '.txt';
file_put_contents($outPath, implode("\n\n", $detailOut) . "\n");

$line = str_repeat('=', 160);
echo $line . "\n";
echo "指定日 イン崩壊：固定3方式比較\n";
echo "日付       : {$date}\n";
echo "対象       : 現在再構築できるイン崩壊候補\n";
echo "S3_T2      : AI3非インTop2頭 × 2着Top3 × 条件付き3着Top2（最大12点）\n";
echo "S2_T3      : AI3非インTop2頭 × 2着Top2 × 条件付き3着Top3（最大12点）\n";
echo "S2_T2      : AI3非インTop2頭 × 2着Top2 × 条件付き3着Top2（最大8点）\n";
echo "位置づけ   : 9/6参考答え合わせ。未使用前方検証には数えない\n";
echo "詳細保存   : {$outPath}\n";
echo $line . "\n";
printf("%-8s %3s %-8s %-16s %-8s %-13s %-13s %-13s\n",
    '場','R','頭','実3連単','1C敗','S3_T2','S2_T3','S2_T2');
echo str_repeat('-', 160) . "\n";

foreach ($rowsOut as $r) {
    if (isset($r['error'])) {
        printf("%-8s %2dR ERROR: %s\n", $r['place'], $r['race_no'], $r['error']);
        continue;
    }
    $actualText = ($r['actual'] ?? '-') . ($r['payout'] !== null ? '/' . number_format((int)$r['payout']) : '');
    $cells = [];
    foreach (array_keys(SPECS3) as $method) {
        $s = $r['scenarios'][$method];
        $cells[$method] = $s['points'] . '点' . ($s['hit'] ? '○' : '×');
    }
    printf("%-8s %2dR %-8s %-16s %-8s %-13s %-13s %-13s\n",
        $r['place'],
        $r['race_no'],
        implode(',', $r['heads']),
        $actualText,
        $r['in_failed'] ? 'YES' : 'NO',
        $cells['FIX2_S3_T2'],
        $cells['FIX2_S2_T3'],
        $cells['FIX2_S2_T2']
    );
}

echo str_repeat('-', 160) . "\n";
if ($n > 0) {
    echo "有効候補   : {$n}R / エラー {$errors}R\n";
    echo "1C敗戦     : {$inFail}R / {$n}R = " . number_format($inFail / $n * 100.0, 2) . "%\n";
    echo "1C敗戦時頭 : {$headHits}R / {$inFail}R = " . number_format($inFail > 0 ? $headHits / $inFail * 100.0 : 0.0, 2) . "%\n";

    echo "\n【3方式サマリー】\n";
    echo "方式             平均点  的中      的中率   1C敗戦時的中   頭+2着捕捉   100円ROI  1000円均等ROI\n";
    echo str_repeat('-', 112) . "\n";
    foreach (array_keys(SPECS3) as $method) {
        $s = $stats[$method];
        $avg = $s['points'] / $n;
        $rate = $s['hits'] / $n * 100.0;
        $failRate = $inFail > 0 ? $s['fail_hits'] / $inFail * 100.0 : 0.0;
        $pairRate = $n > 0 ? $s['head_pair_hits'] / $n * 100.0 : 0.0;
        $roi100 = $s['invest'] > 0 ? $s['return'] / $s['invest'] * 100.0 : 0.0;
        $roiFixed = $n > 0 ? $s['fixed_return'] / ($n * 1000.0) * 100.0 : 0.0;
        printf("%-17s %6.2f  %2d/%-2d    %6.2f%%      %6.2f%%        %6.2f%%      %7.2f%%      %7.2f%%\n",
            $method, $avg, $s['hits'], $n, $rate, $failRate, $pairRate, $roi100, $roiFixed);
    }

    echo "\n【方式間の的中差】\n";
    $pairs = [
        ['FIX2_S3_T2','FIX2_S2_T3'],
        ['FIX2_S3_T2','FIX2_S2_T2'],
        ['FIX2_S2_T3','FIX2_S2_T2'],
    ];
    echo "比較                         Aのみ  Bのみ  両方  どちらも外れ\n";
    echo str_repeat('-', 76) . "\n";
    foreach ($pairs as [$a, $b]) {
        $aOnly = $bOnly = $both = $neither = 0;
        foreach ($validRows as $r) {
            $ah = (bool)$r['scenarios'][$a]['hit'];
            $bh = (bool)$r['scenarios'][$b]['hit'];
            if ($ah && !$bh) $aOnly++;
            elseif (!$ah && $bh) $bOnly++;
            elseif ($ah && $bh) $both++;
            else $neither++;
        }
        printf("%-29s %5d  %5d  %4d  %10d\n", $a . ' vs ' . $b, $aOnly, $bOnly, $both, $neither);
    }
}

echo "\n※9/6は既観察日なので、結果を見て方式を再調整しない。正式評価は9/7以降の固定前方検証。\n";
echo $line . "\n";
