<?php

declare(strict_types=1);

/**
 * 指定日の「イン崩壊」候補を、旧穴目方式と新FIX2_S3_T2（最大12点）で答え合わせする。
 *
 * 新方式:
 * - 頭: 非インAI3連対率Top2
 * - 2着: 各頭の P(2着|頭) Top3
 * - 3着: 各(頭,2着)ごとの P(3着|頭,2着) Top2
 * - cut: 現行固定
 *
 * 旧方式（比較用）:
 * - 頭: 非インAI3連対率Top2
 * - 2着: 各頭の P(2着|頭) Top3
 * - 3着: 現行非cut eligible全艇
 *
 * 注意:
 * - 指定日時点の結果を使った参考答え合わせ。未使用前方検証には数えない。
 * - 本命/対抗/PredictionLogic/本番買い目は変更しない。
 *
 * Usage:
 *   php analysis/compare_payout_signal_fixed12_date.php 2026-09-06
 */

require_once __DIR__ . '/../web/controllers/IndexController.php';
require_once __DIR__ . '/../web/logic/AiTrioRateLogic.php';
require_once __DIR__ . '/../web/logic/TrifectaProbabilityLogic.php';
require_once __DIR__ . '/../web/logic/BaseWinRateLogic.php';
require_once __DIR__ . '/../common/PayoutSignalFeatureBuilder.php';
require_once __DIR__ . '/../common/PayoutSignalClassifier.php';
require_once __DIR__ . '/../common/db_connect.php';

date_default_timezone_set('Asia/Tokyo');

function fail12(string $message, int $code = 1): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($code);
}

function boatKey12(int $a, int $b, int $c): string
{
    return $a . '-' . $b . '-' . $c;
}

/** @return array<int,int> */
function normalizeCourseMap12(array $map): array
{
    $out = [];
    for ($boat = 1; $boat <= 6; $boat++) {
        $course = (int)($map[$boat] ?? $map[(string)$boat] ?? 0);
        if ($course < 1 || $course > 6) {
            return [];
        }
        $out[$boat] = $course;
    }
    $courses = array_values($out);
    sort($courses);
    return $courses === [1,2,3,4,5,6] ? $out : [];
}

/** @return array<int,int> */
function currentCut12(array $finalPredictions): array
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
function loadActual12(string $raceCode): array
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
            ? boatKey12((int)$byRank[1], (int)$byRank[2], (int)$byRank[3])
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
function secondScores12(int $head, array $rows): array
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
        foreach ($mass as $boat => $p) {
            $mass[$boat] = $p / $headMass;
        }
    }
    return $mass;
}

/** @return array<int,float> */
function thirdScores12(int $head, int $second, array $rows): array
{
    $mass = array_fill(1, 6, 0.0);
    $pairMass = 0.0;
    foreach ($rows as $row) {
        $boats = is_array($row['boats'] ?? null) ? $row['boats'] : [];
        if (count($boats) !== 3) continue;
        if ((int)$boats[0] !== $head || (int)$boats[1] !== $second) continue;
        $third = (int)$boats[2];
        $p = (float)($row['probability'] ?? 0.0);
        if ($third < 1 || $third > 6 || in_array($third, [$head,$second], true) || $p < 0.0) continue;
        $pairMass += $p;
        $mass[$third] += $p;
    }
    if ($pairMass > 0.0) {
        foreach ($mass as $boat => $p) {
            $mass[$boat] = $p / $pairMass;
        }
    }
    return $mass;
}

/** @return array<int,int> */
function topFromScores12(array $scores, array $eligible, int $k): array
{
    usort($eligible, static function (int $a, int $b) use ($scores): int {
        $cmp = ((float)($scores[$b] ?? 0.0)) <=> ((float)($scores[$a] ?? 0.0));
        return $cmp !== 0 ? $cmp : ($a <=> $b);
    });
    return array_slice($eligible, 0, min($k, count($eligible)));
}

/**
 * @return array{old:array<string,array{0:int,1:int,2:int}>,new:array<string,array{0:int,1:int,2:int}>,detail:array<int,array<string,mixed>>}
 */
function buildBets12(array $heads, array $rows, array $cut): array
{
    $old = [];
    $new = [];
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

        $seconds = topFromScores12(secondScores12($head, $rows), $eligible, 3);
        $d = ['second' => $seconds, 'third_by_second' => []];
        foreach ($seconds as $second) {
            $thirdEligible = array_values(array_filter(
                $eligible,
                static fn(int $x): bool => $x !== (int)$second
            ));
            $thirdTop2 = topFromScores12(thirdScores12($head, (int)$second, $rows), $thirdEligible, 2);
            $d['third_by_second'][(int)$second] = $thirdTop2;

            foreach ($thirdEligible as $third) {
                $key = boatKey12($head, (int)$second, (int)$third);
                $old[$key] = [$head, (int)$second, (int)$third];
            }
            foreach ($thirdTop2 as $third) {
                $key = boatKey12($head, (int)$second, (int)$third);
                $new[$key] = [$head, (int)$second, (int)$third];
            }
        }
        $detail[$head] = $d;
    }

    ksort($old);
    ksort($new);
    return ['old' => $old, 'new' => $new, 'detail' => $detail];
}

$date = trim((string)($argv[1] ?? ''));
$dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
if ($dt === false || $dt->format('Y-m-d') !== $date) {
    fail12('Usage: php analysis/compare_payout_signal_fixed12_date.php YYYY-MM-DD');
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
    fail12('配当サイン候補一覧を取得できませんでした');
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
        'state' => (string)$m[3],
        'race_code' => $dt->format('Ymd') . $placeCode . sprintf('%02d', $raceNo),
    ];
}

if ($targets === []) {
    echo "対象日のイン崩壊候補はありません。\n";
    exit(0);
}

usort($targets, static function (array $a, array $b): int {
    if ($a['place'] !== $b['place']) return strcmp((string)$a['place'], (string)$b['place']);
    return (int)$a['race_no'] <=> (int)$b['race_no'];
});

$rowsOut = [];
$detailOut = [];
$oldHits = $newHits = $headHits = $inFail = 0;
$oldPoints = $newPoints = 0;
$oldInvest = $newInvest = 0.0;
$oldReturn = $newReturn = 0.0;
$oldFixedReturn = $newFixedReturn = 0.0;
$retained = $lostByNarrow = 0;

foreach ($targets as $idx => $target) {
    $raceCode = (string)$target['race_code'];
    fprintf(STDERR, "[%d/%d] %s %dR 新12点方式を計算中...\n",
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
        continue;
    }
    $class = PayoutSignalClassifier::classify((array)$feature['input']);
    if ((string)($class['chaos']['primary'] ?? '平常') !== 'イン崩壊') {
        $rowsOut[] = [...$target, 'error' => 'current classification changed'];
        continue;
    }

    $courseByBoat = normalizeCourseMap12((array)($view['prediction_course_by_boat'] ?? []));
    if ($courseByBoat === []) $courseByBoat = array_combine(range(1,6), range(1,6));
    $inBoat = 0;
    foreach ($courseByBoat as $boat => $course) {
        if ((int)$course === 1) { $inBoat = (int)$boat; break; }
    }
    if ($inBoat < 1 || $inBoat > 6) {
        $rowsOut[] = [...$target, 'error' => 'in boat missing'];
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
            if (is_numeric($rate)) {
                $correctedBoats[$boat] = ['corrected_rate' => (float)$rate];
            }
        }
        $neutralTenji = [];
        for ($boat = 1; $boat <= 6; $boat++) {
            $neutralTenji[] = ['teiban'=>$boat, 'tenji_course'=>$boat, 'final_2nd_score'=>0.0];
        }
        $aiTrio = $aiTrioLogic->calculate($raceCode, $results, $neutralTenji, $courseByBoat, true);
    }

    if (($aiTrio['status'] ?? '') !== 'ok') {
        $rowsOut[] = [...$target, 'error' => 'AI3 unavailable'];
        continue;
    }
    $aiTrioBoats = is_array($aiTrio['boats'] ?? null) ? $aiTrio['boats'] : [];

    $trifectaLogic = new TrifectaProbabilityLogic();
    $trifecta = $trifectaLogic->calculate($raceCode, $correctedBoats, $aiTrioBoats, $courseByBoat);
    if (($trifecta['status'] ?? '') !== 'ok') {
        $rowsOut[] = [...$target, 'error' => '120 patterns unavailable'];
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
        continue;
    }

    $cut = currentCut12((array)($view['final_predictions'] ?? []));
    $bets = buildBets12($heads, $trifectaRows, $cut);
    $actualInfo = loadActual12($raceCode);
    $actual = $actualInfo['actual'];
    $payout = $actualInfo['payout'];

    $oldCnt = count($bets['old']);
    $newCnt = count($bets['new']);
    $oldHit = $actual !== null && isset($bets['old'][$actual]);
    $newHit = $actual !== null && isset($bets['new'][$actual]);
    $actualFirst = $actual !== null ? (int)explode('-', $actual)[0] : 0;
    $inFailed = $actualFirst > 0 && $actualFirst !== $inBoat;
    $headHit = $actualFirst > 0 && in_array($actualFirst, $heads, true);

    if ($inFailed) $inFail++;
    if ($headHit) $headHits++;
    if ($oldHit) $oldHits++;
    if ($newHit) $newHits++;
    if ($oldHit && $newHit) $retained++;
    if ($oldHit && !$newHit) $lostByNarrow++;

    $oldPoints += $oldCnt;
    $newPoints += $newCnt;
    $oldInvest += $oldCnt * 100.0;
    $newInvest += $newCnt * 100.0;
    if ($oldHit && is_int($payout)) {
        $oldReturn += $payout;
        if ($oldCnt > 0) $oldFixedReturn += $payout * ((1000.0 / $oldCnt) / 100.0);
    }
    if ($newHit && is_int($payout)) {
        $newReturn += $payout;
        if ($newCnt > 0) $newFixedReturn += $payout * ((1000.0 / $newCnt) / 100.0);
    }

    $row = [
        ...$target,
        'mode' => $mode,
        'in_boat' => $inBoat,
        'heads' => $heads,
        'old_points' => $oldCnt,
        'new_points' => $newCnt,
        'actual' => $actual,
        'payout' => $payout,
        'in_failed' => $inFailed,
        'head_hit' => $headHit,
        'old_hit' => $oldHit,
        'new_hit' => $newHit,
    ];
    $rowsOut[] = $row;

    $detailOut[] = str_repeat('=', 110)
        . "\n{$target['place']} {$target['race_no']}R {$raceCode} {$mode}"
        . "\n1C艇={$inBoat} / 頭=" . implode(',', $heads)
        . " / cut=" . ($cut === [] ? '-' : implode(',', $cut))
        . "\n実3連単=" . ($actual ?? '-') . ' / ' . ($payout !== null ? number_format($payout) . '円' : '-')
        . "\n旧方式={$oldCnt}点 / " . ($oldHit ? '的中' : '不的中')
        . "\n新FIX2_S3_T2={$newCnt}点 / " . ($newHit ? '的中' : '不的中')
        . "\n新買い目=" . implode(', ', array_keys($bets['new']));
}

$validRows = array_values(array_filter($rowsOut, static fn(array $r): bool => !isset($r['error'])));
$n = count($validRows);
$errors = count($rowsOut) - $n;

$outputDir = __DIR__ . '/output';
if (!is_dir($outputDir)) @mkdir($outputDir, 0775, true);
$outPath = $outputDir . '/payout_signal_fixed12_compare_' . $dt->format('Ymd') . '.txt';
file_put_contents($outPath, implode("\n\n", $detailOut) . "\n");

$line = str_repeat('=', 132);
echo $line . "\n";
echo "指定日 イン崩壊：旧穴目 vs 新FIX2_S3_T2（最大12点）\n";
echo "日付       : {$date}\n";
echo "対象       : 現在再構築できるイン崩壊候補\n";
echo "新方式     : 非インAI3 Top2頭 × 2着Top3 × 条件付き3着Top2 / 現行cut固定\n";
echo "位置づけ   : 参考答え合わせ（未使用前方検証には数えない）\n";
echo "詳細保存   : {$outPath}\n";
echo $line . "\n";
printf("%-8s %3s %-10s %-8s %-8s %-8s %-16s %-8s %-8s %-8s\n",
    '場','R','モード','頭','旧点','新点','実3連単','1C敗','頭捕捉','新12点');
echo str_repeat('-', 132) . "\n";

foreach ($rowsOut as $r) {
    if (isset($r['error'])) {
        printf("%-8s %2dR ERROR: %s\n", $r['place'], $r['race_no'], $r['error']);
        continue;
    }
    $actualText = ($r['actual'] ?? '-') . ($r['payout'] !== null ? ' / ' . number_format((int)$r['payout']) . '円' : '');
    printf("%-8s %2dR %-10s %-8s %5d点 %5d点 %-16s %-8s %-8s %-8s\n",
        $r['place'],
        $r['race_no'],
        $r['mode'],
        implode(',', $r['heads']),
        $r['old_points'],
        $r['new_points'],
        $actualText,
        $r['in_failed'] ? 'YES' : 'NO',
        $r['head_hit'] ? 'YES' : 'NO',
        $r['new_hit'] ? '的中' : '不的中'
    );
}

echo str_repeat('-', 132) . "\n";
if ($n > 0) {
    $avgOld = $oldPoints / $n;
    $avgNew = $newPoints / $n;
    $oldRate = $oldHits / $n * 100.0;
    $newRate = $newHits / $n * 100.0;
    $inFailRate = $inFail / $n * 100.0;
    $headFailRate = $inFail > 0 ? $headHits / $inFail * 100.0 : 0.0;
    $oldRoi = $oldInvest > 0 ? $oldReturn / $oldInvest * 100.0 : 0.0;
    $newRoi = $newInvest > 0 ? $newReturn / $newInvest * 100.0 : 0.0;
    $oldFixedRoi = $n > 0 ? $oldFixedReturn / ($n * 1000.0) * 100.0 : 0.0;
    $newFixedRoi = $n > 0 ? $newFixedReturn / ($n * 1000.0) * 100.0 : 0.0;
    $retainRate = $oldHits > 0 ? $retained / $oldHits * 100.0 : 0.0;

    echo "有効候補   : {$n}R / エラー {$errors}R\n";
    echo "1C敗戦     : {$inFail}R / {$n}R = " . number_format($inFailRate, 2) . "%\n";
    echo "1C敗戦時頭 : {$headHits}R / {$inFail}R = " . number_format($headFailRate, 2) . "%\n";
    echo "旧方式     : 平均" . number_format($avgOld, 2) . "点 / {$oldHits}的中 = " . number_format($oldRate, 2)
        . "% / 100円ROI " . number_format($oldRoi, 2) . "% / 1000円均等ROI " . number_format($oldFixedRoi, 2) . "%\n";
    echo "新12点方式 : 平均" . number_format($avgNew, 2) . "点 / {$newHits}的中 = " . number_format($newRate, 2)
        . "% / 100円ROI " . number_format($newRoi, 2) . "% / 1000円均等ROI " . number_format($newFixedRoi, 2) . "%\n";
    echo "旧的中保持 : {$retained}R / {$oldHits}R = " . number_format($retainRate, 2) . "%\n";
    echo "12点化で失う旧的中 : {$lostByNarrow}R\n";
}
echo $line . "\n";
