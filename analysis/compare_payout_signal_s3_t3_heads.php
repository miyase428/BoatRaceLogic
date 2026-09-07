<?php

declare(strict_types=1);

/**
 * イン崩壊候補について、S3_T3（2着Top3×3着Top3）を追加し、
 * 2頭合計と「頭候補A/Bを1頭だけ買う9点型」を同条件で比較する。
 *
 * 比較:
 * - FIX2_S3_T2 : 非インAI3 Top2頭 × 2着Top3 × 条件付き3着Top2（最大12点）
 * - FIX2_S2_T3 : 非インAI3 Top2頭 × 2着Top2 × 条件付き3着Top3（最大12点）
 * - FIX2_S3_T3 : 非インAI3 Top2頭 × 2着Top3 × 条件付き3着Top3（最大18点）
 * - HEAD_A_S3_T3 : AI3非イン1位頭だけ × 2着Top3 × 3着Top3（最大9点）
 * - HEAD_B_S3_T3 : AI3非イン2位頭だけ × 2着Top3 × 3着Top3（最大9点）
 *
 * 注意:
 * - 過去日の参考比較用。9/1～9/6など既観察期間は未使用前方検証に数えない。
 * - 荒れ判定、AI3頭順位、cut、候補数、確率ロジックは変更しない。
 * - 本命/対抗/PredictionLogic/本番買い目は変更しない。
 *
 * Usage:
 *   php analysis/compare_payout_signal_s3_t3_heads.php 2026-09-06
 *   php analysis/compare_payout_signal_s3_t3_heads.php 2026-09-01 2026-09-06
 */

require_once __DIR__ . '/../web/controllers/IndexController.php';
require_once __DIR__ . '/../web/logic/AiTrioRateLogic.php';
require_once __DIR__ . '/../web/logic/TrifectaProbabilityLogic.php';
require_once __DIR__ . '/../web/logic/BaseWinRateLogic.php';
require_once __DIR__ . '/../common/PayoutSignalFeatureBuilder.php';
require_once __DIR__ . '/../common/PayoutSignalClassifier.php';
require_once __DIR__ . '/../common/db_connect.php';

date_default_timezone_set('Asia/Tokyo');

const S33_METHODS = [
    'FIX2_S3_T2' => [3, 2, 'both'],
    'FIX2_S2_T3' => [2, 3, 'both'],
    'FIX2_S3_T3' => [3, 3, 'both'],
    'HEAD_A_S3_T3' => [3, 3, 'A'],
    'HEAD_B_S3_T3' => [3, 3, 'B'],
];

function failS33(string $message, int $code = 1): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($code);
}

function keyS33(int $a, int $b, int $c): string
{
    return $a . '-' . $b . '-' . $c;
}

/** @return array<int,int> */
function normalizeCourseMapS33(array $map): array
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
function currentCutS33(array $finalPredictions): array
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
function loadActualS33(string $raceCode): array
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
            ? keyS33((int)$byRank[1], (int)$byRank[2], (int)$byRank[3])
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
function secondScoresS33(int $head, array $rows): array
{
    $mass = array_fill(1, 6, 0.0);
    $total = 0.0;
    foreach ($rows as $row) {
        $boats = is_array($row['boats'] ?? null) ? $row['boats'] : [];
        if (count($boats) !== 3 || (int)$boats[0] !== $head) continue;
        $second = (int)$boats[1];
        $p = (float)($row['probability'] ?? 0.0);
        if ($second < 1 || $second > 6 || $second === $head || $p < 0.0) continue;
        $total += $p;
        $mass[$second] += $p;
    }
    if ($total > 0.0) {
        foreach ($mass as $boat => $p) $mass[$boat] = $p / $total;
    }
    return $mass;
}

/** @return array<int,float> */
function thirdScoresS33(int $head, int $second, array $rows): array
{
    $mass = array_fill(1, 6, 0.0);
    $total = 0.0;
    foreach ($rows as $row) {
        $boats = is_array($row['boats'] ?? null) ? $row['boats'] : [];
        if (count($boats) !== 3 || (int)$boats[0] !== $head || (int)$boats[1] !== $second) continue;
        $third = (int)$boats[2];
        $p = (float)($row['probability'] ?? 0.0);
        if ($third < 1 || $third > 6 || in_array($third, [$head, $second], true) || $p < 0.0) continue;
        $total += $p;
        $mass[$third] += $p;
    }
    if ($total > 0.0) {
        foreach ($mass as $boat => $p) $mass[$boat] = $p / $total;
    }
    return $mass;
}

/** @return array<int,int> */
function topS33(array $scores, array $eligible, int $k): array
{
    usort($eligible, static function (int $a, int $b) use ($scores): int {
        $cmp = ((float)($scores[$b] ?? 0.0)) <=> ((float)($scores[$a] ?? 0.0));
        return $cmp !== 0 ? $cmp : ($a <=> $b);
    });
    return array_slice($eligible, 0, min($k, count($eligible)));
}

/** @return array{bets:array<string,array{0:int,1:int,2:int}>,detail:array<int,array<string,mixed>>} */
function buildScenarioS33(array $heads, array $rows, array $cut, int $secondCount, int $thirdCount): array
{
    $bets = [];
    $detail = [];
    $cutBase = array_fill_keys($cut, true);

    foreach ($heads as $head) {
        $head = (int)$head;
        $cutMap = $cutBase;
        unset($cutMap[$head]);
        $eligible = [];
        for ($boat = 1; $boat <= 6; $boat++) {
            if ($boat !== $head && !isset($cutMap[$boat])) $eligible[] = $boat;
        }
        if (count($eligible) < 2) continue;

        $seconds = topS33(secondScoresS33($head, $rows), $eligible, $secondCount);
        $headDetail = ['second' => $seconds, 'third_by_second' => []];
        foreach ($seconds as $second) {
            $thirdEligible = array_values(array_filter(
                $eligible,
                static fn(int $x): bool => $x !== (int)$second
            ));
            $thirds = topS33(thirdScoresS33($head, (int)$second, $rows), $thirdEligible, $thirdCount);
            $headDetail['third_by_second'][(int)$second] = $thirds;
            foreach ($thirds as $third) {
                $bets[keyS33($head, (int)$second, (int)$third)] = [$head, (int)$second, (int)$third];
            }
        }
        $detail[$head] = $headDetail;
    }
    ksort($bets);
    return ['bets' => $bets, 'detail' => $detail];
}

function pctS33(int|float $n, int|float $d): float
{
    return $d > 0 ? (float)$n / (float)$d * 100.0 : 0.0;
}

/** @return array<int,array<string,mixed>> */
function targetsForDateS33(DateTimeImmutable $dt): array
{
    $placeCodes = [
        '桐生'=>'KRY','戸田'=>'TDA','江戸川'=>'EDG','平和島'=>'HWJ','多摩川'=>'TMG','浜名湖'=>'HMN',
        '蒲郡'=>'GMG','常滑'=>'TKN','津'=>'TSU','三国'=>'MKN','びわこ'=>'BWK','住之江'=>'SME',
        '尼崎'=>'AMG','鳴門'=>'NRT','丸亀'=>'MRG','児島'=>'KJM','宮島'=>'MYJ','徳山'=>'TKY',
        '下関'=>'SMS','若松'=>'WKM','芦屋'=>'ASY','福岡'=>'FKO','唐津'=>'KRT','大村'=>'OMR',
    ];
    $date = $dt->format('Y-m-d');
    $listScript = __DIR__ . '/list_today_payout_signals.php';
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($listScript)
        . ' ' . escapeshellarg($date) . ' all';
    $out = shell_exec($cmd . ' 2>&1');
    if (!is_string($out) || trim($out) === '') return [];

    $targets = [];
    foreach (preg_split('/\R/u', $out) ?: [] as $line) {
        if (!preg_match('/^\s*(\S+)\s+(\d{1,2})R\s+(Web反映|暫定)\s+\|.*\|\s*(イン崩壊|ヒモ荒れ|複合高配当|平常)\s*\|/u', $line, $m)) {
            continue;
        }
        if ((string)$m[4] !== 'イン崩壊') continue;
        $placeName = (string)$m[1];
        $placeCode = $placeCodes[$placeName] ?? null;
        if ($placeCode === null) continue;
        $raceNo = (int)$m[2];
        $targets[] = [
            'date' => $date,
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
    return $targets;
}

/** @return array<string,mixed> */
function evaluateTargetS33(array $target): array
{
    $_GET = [
        'date' => (string)$target['date'],
        'place' => (string)$target['place_code'],
        'race' => (string)$target['race_no'],
    ];
    $_POST = [];

    $controller = new IndexController();
    $view = $controller->handle();

    $kimarite = is_array($view['kimarite_data'] ?? null) ? $view['kimarite_data'] : [];
    $feature = PayoutSignalFeatureBuilder::build((int)$target['race_no'], $kimarite, [
        'honmei_head' => $view['honmei_head'] ?? null,
        'taikou_head' => $view['taikou_head'] ?? null,
    ]);
    if (($feature['status'] ?? '') !== 'ok') throw new RuntimeException('signal feature not ready');
    $class = PayoutSignalClassifier::classify((array)$feature['input']);
    if ((string)($class['chaos']['primary'] ?? '平常') !== 'イン崩壊') {
        throw new RuntimeException('current classification changed');
    }

    $courseByBoat = normalizeCourseMapS33((array)($view['prediction_course_by_boat'] ?? []));
    if ($courseByBoat === []) $courseByBoat = array_combine(range(1,6), range(1,6));
    $inBoat = 0;
    foreach ($courseByBoat as $boat => $course) {
        if ((int)$course === 1) { $inBoat = (int)$boat; break; }
    }
    if ($inBoat < 1 || $inBoat > 6) throw new RuntimeException('in boat missing');

    $results = is_array($view['results'] ?? null) ? $view['results'] : [];
    $tenji = is_array($view['tenji_list'] ?? null) ? $view['tenji_list'] : [];
    $corrected = is_array($view['corrected_win_rate_data'] ?? null) ? $view['corrected_win_rate_data'] : [];
    $correctedBoats = is_array($corrected['boats'] ?? null) ? $corrected['boats'] : [];

    $mode = '展示反映済';
    $aiTrioLogic = new AiTrioRateLogic();
    $aiTrio = $aiTrioLogic->calculate((string)$target['race_code'], $results, $tenji, $courseByBoat, false);
    if (($corrected['status'] ?? '') !== 'ok' || ($aiTrio['status'] ?? '') !== 'ok') {
        $mode = '暫定';
        $courseByBoat = array_combine(range(1,6), range(1,6));
        $inBoat = 1;
        $base = (new BaseWinRateLogic())->calculate((string)$target['race_code'], $courseByBoat);
        $baseBoats = is_array($base['boats'] ?? null) ? $base['boats'] : [];
        $correctedBoats = [];
        for ($boat = 1; $boat <= 6; $boat++) {
            $rate = $baseBoats[$boat]['normalized_rate'] ?? $baseBoats[(string)$boat]['normalized_rate'] ?? null;
            if (is_numeric($rate)) $correctedBoats[$boat] = ['corrected_rate' => (float)$rate];
        }
        $neutral = [];
        for ($boat = 1; $boat <= 6; $boat++) {
            $neutral[] = ['teiban'=>$boat, 'tenji_course'=>$boat, 'final_2nd_score'=>0.0];
        }
        $aiTrio = $aiTrioLogic->calculate((string)$target['race_code'], $results, $neutral, $courseByBoat, true);
    }
    if (($aiTrio['status'] ?? '') !== 'ok') throw new RuntimeException('AI3 unavailable');
    $aiTrioBoats = is_array($aiTrio['boats'] ?? null) ? $aiTrio['boats'] : [];

    $trifecta = (new TrifectaProbabilityLogic())->calculate(
        (string)$target['race_code'], $correctedBoats, $aiTrioBoats, $courseByBoat
    );
    if (($trifecta['status'] ?? '') !== 'ok') throw new RuntimeException('120 patterns unavailable');
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
    if (count($heads) < 2) throw new RuntimeException('head ranking unavailable');

    $cut = currentCutS33((array)($view['final_predictions'] ?? []));
    $actualInfo = loadActualS33((string)$target['race_code']);
    $actual = $actualInfo['actual'];
    $payout = $actualInfo['payout'];
    $actualParts = $actual !== null ? array_map('intval', explode('-', $actual)) : [];
    $actualFirst = $actualParts[0] ?? 0;
    $inFailed = $actualFirst > 0 && $actualFirst !== $inBoat;

    $scenarios = [];
    foreach (S33_METHODS as $method => [$secondCount, $thirdCount, $headMode]) {
        $methodHeads = match ($headMode) {
            'A' => [$heads[0]],
            'B' => [$heads[1]],
            default => $heads,
        };
        $scenario = buildScenarioS33($methodHeads, $trifectaRows, $cut, (int)$secondCount, (int)$thirdCount);
        $bets = $scenario['bets'];
        $scenarios[$method] = [
            'points' => count($bets),
            'hit' => $actual !== null && isset($bets[$actual]),
            'bets' => $bets,
        ];
    }

    return [
        ...$target,
        'mode' => $mode,
        'in_boat' => $inBoat,
        'heads' => $heads,
        'actual' => $actual,
        'payout' => $payout,
        'in_failed' => $inFailed,
        'head_a_won' => $actualFirst === $heads[0],
        'head_b_won' => $actualFirst === $heads[1],
        'scenarios' => $scenarios,
        'cut' => $cut,
    ];
}

$startText = trim((string)($argv[1] ?? ''));
$endText = trim((string)($argv[2] ?? $startText));
$start = DateTimeImmutable::createFromFormat('!Y-m-d', $startText);
$end = DateTimeImmutable::createFromFormat('!Y-m-d', $endText);
if ($start === false || $start->format('Y-m-d') !== $startText
    || $end === false || $end->format('Y-m-d') !== $endText || $end < $start) {
    failS33('Usage: php analysis/compare_payout_signal_s3_t3_heads.php YYYY-MM-DD [YYYY-MM-DD]');
}

$stats = [];
foreach (array_keys(S33_METHODS) as $method) {
    $stats[$method] = ['points'=>0,'hits'=>0,'invest'=>0.0,'return'=>0.0,'fixed_return'=>0.0];
}
$daily = [];
$details = [];
$totalN = 0;
$totalFail = 0;
$headAWins = 0;
$headBWins = 0;
$errors = 0;
$extraVsS3T2 = 0;
$extraVsS2T3 = 0;
$extraVsUnion = 0;
$lostVsS3T2 = 0;
$lostVsS2T3 = 0;

for ($dt = $start; $dt <= $end; $dt = $dt->modify('+1 day')) {
    $date = $dt->format('Y-m-d');
    $targets = targetsForDateS33($dt);
    $day = ['n'=>0,'fail'=>0,'a_win'=>0,'b_win'=>0,'hits'=>array_fill_keys(array_keys(S33_METHODS), 0)];
    foreach ($targets as $idx => $target) {
        fprintf(STDERR, "[%s %d/%d] %s %dR S3_T3頭別比較を計算中...\n",
            $date, $idx + 1, count($targets), (string)$target['place'], (int)$target['race_no']);
        try {
            $row = evaluateTargetS33($target);
        } catch (Throwable $e) {
            $errors++;
            $details[] = "{$date} {$target['place']} {$target['race_no']}R ERROR: {$e->getMessage()}";
            continue;
        }

        $totalN++;
        $day['n']++;
        if ($row['in_failed']) { $totalFail++; $day['fail']++; }
        if ($row['head_a_won']) { $headAWins++; $day['a_win']++; }
        if ($row['head_b_won']) { $headBWins++; $day['b_win']++; }

        foreach (array_keys(S33_METHODS) as $method) {
            $s = $row['scenarios'][$method];
            $pts = (int)$s['points'];
            $hit = (bool)$s['hit'];
            $stats[$method]['points'] += $pts;
            $stats[$method]['invest'] += $pts * 100.0;
            if ($hit) {
                $stats[$method]['hits']++;
                $day['hits'][$method]++;
                if (is_int($row['payout'])) {
                    $stats[$method]['return'] += $row['payout'];
                    if ($pts > 0) {
                        $stats[$method]['fixed_return'] += $row['payout'] * ((1000.0 / $pts) / 100.0);
                    }
                }
            }
        }

        $h33 = (bool)$row['scenarios']['FIX2_S3_T3']['hit'];
        $h32 = (bool)$row['scenarios']['FIX2_S3_T2']['hit'];
        $h23 = (bool)$row['scenarios']['FIX2_S2_T3']['hit'];
        if ($h33 && !$h32) $extraVsS3T2++;
        if ($h33 && !$h23) $extraVsS2T3++;
        if ($h33 && !$h32 && !$h23) $extraVsUnion++;
        if ($h32 && !$h33) $lostVsS3T2++;
        if ($h23 && !$h33) $lostVsS2T3++;

        $actualText = ($row['actual'] ?? '-') . ($row['payout'] !== null ? '/' . number_format((int)$row['payout']) . '円' : '');
        $details[] = str_repeat('=', 124)
            . "\n{$date} {$row['place']} {$row['race_no']}R {$row['race_code']} {$row['mode']}"
            . "\n1C艇={$row['in_boat']} / 頭A={$row['heads'][0]} / 頭B={$row['heads'][1]} / cut="
            . ($row['cut'] === [] ? '-' : implode(',', $row['cut']))
            . "\n実3連単={$actualText}"
            . "\nS3_T2=" . $row['scenarios']['FIX2_S3_T2']['points'] . '点/' . ($h32 ? '的中' : '不的中')
            . "\nS2_T3=" . $row['scenarios']['FIX2_S2_T3']['points'] . '点/' . ($h23 ? '的中' : '不的中')
            . "\nS3_T3=" . $row['scenarios']['FIX2_S3_T3']['points'] . '点/' . ($h33 ? '的中' : '不的中')
            . "\n頭A=" . $row['scenarios']['HEAD_A_S3_T3']['points'] . '点/' . ($row['scenarios']['HEAD_A_S3_T3']['hit'] ? '的中' : '不的中')
            . ' / 買い目=' . implode(', ', array_keys($row['scenarios']['HEAD_A_S3_T3']['bets']))
            . "\n頭B=" . $row['scenarios']['HEAD_B_S3_T3']['points'] . '点/' . ($row['scenarios']['HEAD_B_S3_T3']['hit'] ? '的中' : '不的中')
            . ' / 買い目=' . implode(', ', array_keys($row['scenarios']['HEAD_B_S3_T3']['bets']));
    }
    $daily[$date] = $day;
}

$outputDir = __DIR__ . '/output';
if (!is_dir($outputDir)) @mkdir($outputDir, 0775, true);
$outPath = $outputDir . '/payout_signal_s3_t3_heads_' . $start->format('Ymd') . '_' . $end->format('Ymd') . '.txt';
file_put_contents($outPath, implode("\n\n", $details) . "\n");

$line = str_repeat('=', 142);
echo $line . "\n";
echo "イン崩壊：S3_T3 + 頭候補A/B 9点型 比較\n";
echo "期間       : {$startText} ～ {$endText}\n";
echo "S3_T3      : 頭2艇 × 2着Top3 × 条件付き3着Top3（最大18点）\n";
echo "頭A/B      : 各1頭 × 2着Top3 × 条件付き3着Top3（各最大9点）\n";
echo "頭A        : 非インAI3連対率1位 / 頭B: 非インAI3連対率2位\n";
echo "位置づけ   : 既観察期間の参考比較。ルール・閾値の再調整には使わない\n";
echo "詳細保存   : {$outPath}\n";
echo $line . "\n";

echo "\n【日別】\n";
echo "日付         R数  1C敗戦  A頭勝 B頭勝  S3_T2      S2_T3      S3_T3      Aのみ9点    Bのみ9点\n";
echo str_repeat('-', 124) . "\n";
foreach ($daily as $date => $d) {
    $n = (int)$d['n'];
    printf("%s %4d %3d(%5.1f%%) %3d   %3d   %2d/%-2d %5.1f%%  %2d/%-2d %5.1f%%  %2d/%-2d %5.1f%%  %2d/%-2d %5.1f%%  %2d/%-2d %5.1f%%\n",
        $date,
        $n,
        (int)$d['fail'], pctS33((int)$d['fail'], $n),
        (int)$d['a_win'], (int)$d['b_win'],
        (int)$d['hits']['FIX2_S3_T2'], $n, pctS33((int)$d['hits']['FIX2_S3_T2'], $n),
        (int)$d['hits']['FIX2_S2_T3'], $n, pctS33((int)$d['hits']['FIX2_S2_T3'], $n),
        (int)$d['hits']['FIX2_S3_T3'], $n, pctS33((int)$d['hits']['FIX2_S3_T3'], $n),
        (int)$d['hits']['HEAD_A_S3_T3'], $n, pctS33((int)$d['hits']['HEAD_A_S3_T3'], $n),
        (int)$d['hits']['HEAD_B_S3_T3'], $n, pctS33((int)$d['hits']['HEAD_B_S3_T3'], $n)
    );
}

if ($totalN > 0) {
    echo "\n【期間合計】\n";
    echo "有効候補   : {$totalN}R / エラー {$errors}R\n";
    echo "1C敗戦     : {$totalFail}R / {$totalN}R = " . number_format(pctS33($totalFail, $totalN), 2) . "%\n";
    echo "実勝ち頭A  : {$headAWins}R / {$totalN}R = " . number_format(pctS33($headAWins, $totalN), 2) . "%\n";
    echo "実勝ち頭B  : {$headBWins}R / {$totalN}R = " . number_format(pctS33($headBWins, $totalN), 2) . "%\n";
    echo "Top2頭捕捉 : " . ($headAWins + $headBWins) . "R / {$totalFail}R = "
        . number_format(pctS33($headAWins + $headBWins, $totalFail), 2) . "%（1C敗戦時）\n";

    echo "\n方式             平均点   的中        的中率   1C敗戦時的中   100円ROI  1000円均等ROI\n";
    echo str_repeat('-', 100) . "\n";
    foreach (array_keys(S33_METHODS) as $method) {
        $s = $stats[$method];
        $avg = $s['points'] / $totalN;
        $roi100 = $s['invest'] > 0 ? $s['return'] / $s['invest'] * 100.0 : 0.0;
        $roi1000 = $s['fixed_return'] / ($totalN * 1000.0) * 100.0;
        printf("%-18s %6.2f  %3d/%-3d  %7.2f%%      %7.2f%%      %7.2f%%      %7.2f%%\n",
            $method,
            $avg,
            (int)$s['hits'], $totalN,
            pctS33((int)$s['hits'], $totalN),
            pctS33((int)$s['hits'], $totalFail),
            $roi100,
            $roi1000
        );
    }

    echo "\n【S3_T3の追加価値】\n";
    echo "S3_T2より追加的中 : {$extraVsS3T2}R\n";
    echo "S2_T3より追加的中 : {$extraVsS2T3}R\n";
    echo "S3_T2/S2_T3の両方が外れてS3_T3だけ的中 : {$extraVsUnion}R\n";
    echo "S3_T3で失ったS3_T2的中 : {$lostVsS3T2}R（通常0のはず）\n";
    echo "S3_T3で失ったS2_T3的中 : {$lostVsS2T3}R（通常0のはず）\n";
    echo "\n※頭A/Bの9点成績は『常にAだけ』『常にBだけ』を買った場合の機械的基準。人がレースごとに選ぶ性能そのものではありません。\n";
}

echo $line . "\n";
