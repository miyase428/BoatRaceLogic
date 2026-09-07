<?php

declare(strict_types=1);

/**
 * 指定日の「イン崩壊」候補について、結果を一切参照せず穴目予想だけを出力する。
 *
 * 出力:
 * - 穴本命A = 非インAI3連対率1位
 * - 穴対抗B = 非インAI3連対率2位
 * - 各頭について S3_T3（2着Top3 × 条件付き3着Top3、最大9点）
 * - GAP5_INNER（AI3差<5 かつ BがAより内）の参考判定
 *
 * 重要:
 * - race_result_detail / race_payouts は読まない。
 * - 結果・払戻は表示もしない。
 * - 本命/対抗/PredictionLogic/PayoutSignalClassifier/本番買い目は変更しない。
 * - GAP5_INNER はまだ本番採用せず「参考B寄り」として表示するだけ。
 *
 * Usage:
 *   php analysis/list_payout_signal_hole_predictions.php 2026-09-07
 */

require_once __DIR__ . '/../web/controllers/IndexController.php';
require_once __DIR__ . '/../web/logic/AiTrioRateLogic.php';
require_once __DIR__ . '/../web/logic/TrifectaProbabilityLogic.php';
require_once __DIR__ . '/../web/logic/BaseWinRateLogic.php';
require_once __DIR__ . '/../common/PayoutSignalFeatureBuilder.php';
require_once __DIR__ . '/../common/PayoutSignalClassifier.php';

date_default_timezone_set('Asia/Tokyo');

function failHolePred(string $message, int $code = 1): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($code);
}

/** @return array<int,int> */
function normalizeCourseMapHolePred(array $map): array
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
function currentCutHolePred(array $finalPredictions): array
{
    $cut = [];
    foreach ($finalPredictions as $key => $row) {
        if (!is_array($row)) continue;
        $boat = (int)($row['lane_number'] ?? $row['teiban'] ?? $row['boat'] ?? $key);
        if ($boat >= 1 && $boat <= 6 && (int)($row['kiru'] ?? 0) === 1) {
            $cut[$boat] = $boat;
        }
    }
    ksort($cut);
    return array_values($cut);
}

/** @return array<int,float> */
function secondScoresHolePred(int $head, array $rows): array
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
function thirdScoresHolePred(int $head, int $second, array $rows): array
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
function topHolePred(array $scores, array $eligible, int $k): array
{
    usort($eligible, static function (int $a, int $b) use ($scores): int {
        $cmp = ((float)($scores[$b] ?? 0.0)) <=> ((float)($scores[$a] ?? 0.0));
        return $cmp !== 0 ? $cmp : ($a <=> $b);
    });
    return array_slice($eligible, 0, min($k, count($eligible)));
}

/**
 * @return array{bets:array<string,array{0:int,1:int,2:int}>,detail:array{second:array<int,int>,third_by_second:array<int,array<int,int>>}}
 */
function buildHeadS33HolePred(int $head, array $rows, array $cut): array
{
    $cutMap = array_fill_keys($cut, true);
    unset($cutMap[$head]);

    $eligible = [];
    for ($boat = 1; $boat <= 6; $boat++) {
        if ($boat !== $head && !isset($cutMap[$boat])) $eligible[] = $boat;
    }

    $bets = [];
    $detail = ['second' => [], 'third_by_second' => []];
    if (count($eligible) < 2) return ['bets'=>$bets, 'detail'=>$detail];

    $seconds = topHolePred(secondScoresHolePred($head, $rows), $eligible, 3);
    $detail['second'] = $seconds;

    foreach ($seconds as $second) {
        $thirdEligible = array_values(array_filter(
            $eligible,
            static fn(int $x): bool => $x !== (int)$second
        ));
        $thirds = topHolePred(thirdScoresHolePred($head, (int)$second, $rows), $thirdEligible, 3);
        $detail['third_by_second'][(int)$second] = $thirds;
        foreach ($thirds as $third) {
            $key = $head . '-' . (int)$second . '-' . (int)$third;
            $bets[$key] = [$head, (int)$second, (int)$third];
        }
    }
    ksort($bets);
    return ['bets'=>$bets, 'detail'=>$detail];
}

/** @return array<int,array<string,mixed>> */
function targetsHolePred(DateTimeImmutable $dt): array
{
    $placeCodes = [
        '桐生'=>'KRY','戸田'=>'TDA','江戸川'=>'EDG','平和島'=>'HWJ','多摩川'=>'TMG','浜名湖'=>'HMN',
        '蒲郡'=>'GMG','常滑'=>'TKN','津'=>'TSU','三国'=>'MKN','びわこ'=>'BWK','住之江'=>'SME',
        '尼崎'=>'AMG','鳴門'=>'NRT','丸亀'=>'MRG','児島'=>'KJM','宮島'=>'MYJ','徳山'=>'TKY',
        '下関'=>'SMS','若松'=>'WKM','芦屋'=>'ASY','福岡'=>'FKO','唐津'=>'KRT','大村'=>'OMR',
    ];

    $date = $dt->format('Y-m-d');
    $script = __DIR__ . '/list_today_payout_signals.php';
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script)
        . ' ' . escapeshellarg($date) . ' all';
    $out = shell_exec($cmd . ' 2>&1');
    if (!is_string($out) || trim($out) === '') return [];

    $targets = [];
    foreach (preg_split('/\R/u', $out) ?: [] as $line) {
        if (!preg_match('/^\s*(\S+)\s+(\d{1,2})R\s+(Web反映|暫定)\s+\|.*\|\s*(イン崩壊|ヒモ荒れ|複合高配当|平常)\s*\|/u', $line, $m)) {
            continue;
        }
        if ((string)$m[4] !== 'イン崩壊') continue;
        $place = (string)$m[1];
        $code = $placeCodes[$place] ?? null;
        if ($code === null) continue;
        $raceNo = (int)$m[2];
        $targets[] = [
            'date' => $date,
            'place' => $place,
            'place_code' => $code,
            'race_no' => $raceNo,
            'race_code' => $dt->format('Ymd') . $code . sprintf('%02d', $raceNo),
        ];
    }

    usort($targets, static function (array $a, array $b): int {
        if ($a['place'] !== $b['place']) return strcmp((string)$a['place'], (string)$b['place']);
        return (int)$a['race_no'] <=> (int)$b['race_no'];
    });
    return $targets;
}

/** @return array<string,mixed> */
function evaluateHolePred(array $target): array
{
    $_GET = [
        'date' => (string)$target['date'],
        'place' => (string)$target['place_code'],
        'race' => (string)$target['race_no'],
    ];
    $_POST = [];

    $view = (new IndexController())->handle();

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

    $courseByBoat = normalizeCourseMapHolePred((array)($view['prediction_course_by_boat'] ?? []));
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
    $aiLogic = new AiTrioRateLogic();
    $ai = $aiLogic->calculate((string)$target['race_code'], $results, $tenji, $courseByBoat, false);

    if (($corrected['status'] ?? '') !== 'ok' || ($ai['status'] ?? '') !== 'ok') {
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
        $ai = $aiLogic->calculate((string)$target['race_code'], $results, $neutral, $courseByBoat, true);
    }

    if (($ai['status'] ?? '') !== 'ok') throw new RuntimeException('AI3 unavailable');
    $aiBoats = is_array($ai['boats'] ?? null) ? $ai['boats'] : [];

    $trifecta = (new TrifectaProbabilityLogic())->calculate(
        (string)$target['race_code'], $correctedBoats, $aiBoats, $courseByBoat
    );
    if (($trifecta['status'] ?? '') !== 'ok') throw new RuntimeException('120 patterns unavailable');
    $trifectaRows = is_array($trifecta['rows'] ?? null) ? $trifecta['rows'] : [];

    $ranked = [];
    for ($boat = 1; $boat <= 6; $boat++) {
        if ($boat === $inBoat) continue;
        $rate = $aiBoats[$boat]['ai_rate'] ?? $aiBoats[(string)$boat]['ai_rate'] ?? null;
        if (is_numeric($rate)) $ranked[$boat] = (float)$rate;
    }
    uksort($ranked, static function (int|string $a, int|string $b) use ($ranked): int {
        $cmp = $ranked[(int)$b] <=> $ranked[(int)$a];
        return $cmp !== 0 ? $cmp : ((int)$a <=> (int)$b);
    });

    $heads = array_slice(array_map('intval', array_keys($ranked)), 0, 2);
    if (count($heads) < 2) throw new RuntimeException('head ranking unavailable');
    [$a, $b] = $heads;

    $cut = currentCutHolePred((array)($view['final_predictions'] ?? []));
    $aScenario = buildHeadS33HolePred($a, $trifectaRows, $cut);
    $bScenario = buildHeadS33HolePred($b, $trifectaRows, $cut);

    $aiA = (float)$ranked[$a];
    $aiB = (float)$ranked[$b];
    $aCourse = (int)($courseByBoat[$a] ?? $a);
    $bCourse = (int)($courseByBoat[$b] ?? $b);
    $gap = $aiA - $aiB;
    $bLean = $gap < 5.0 && $bCourse < $aCourse;

    return [
        ...$target,
        'mode' => $mode,
        'in_boat' => $inBoat,
        'cut' => $cut,
        'a_boat' => $a,
        'b_boat' => $b,
        'a_course' => $aCourse,
        'b_course' => $bCourse,
        'ai_a' => $aiA,
        'ai_b' => $aiB,
        'ai_gap' => $gap,
        'b_lean' => $bLean,
        'a_bets' => $aScenario['bets'],
        'b_bets' => $bScenario['bets'],
    ];
}

function compactBetsHolePred(array $bets): string
{
    return $bets === [] ? '-' : implode(', ', array_keys($bets));
}

$dateText = trim((string)($argv[1] ?? ''));
$dt = DateTimeImmutable::createFromFormat('!Y-m-d', $dateText);
if ($dt === false || $dt->format('Y-m-d') !== $dateText) {
    failHolePred('Usage: php analysis/list_payout_signal_hole_predictions.php YYYY-MM-DD');
}

$targets = targetsHolePred($dt);
if ($targets === []) {
    echo "対象日のイン崩壊候補はありません。\n";
    exit(0);
}

$rows = [];
$errors = 0;
foreach ($targets as $idx => $target) {
    fprintf(STDERR, "[%d/%d] %s %dR 穴目予想を計算中...\n",
        $idx + 1, count($targets), (string)$target['place'], (int)$target['race_no']);
    try {
        $rows[] = evaluateHolePred($target);
    } catch (Throwable $e) {
        $errors++;
        $rows[] = [...$target, 'error'=>$e->getMessage()];
    }
}

$stamp = (new DateTimeImmutable('now'))->format('Ymd_His');
$outputDir = __DIR__ . '/output';
if (!is_dir($outputDir)) @mkdir($outputDir, 0775, true);
$outPath = $outputDir . '/payout_signal_hole_predictions_' . $dt->format('Ymd') . '_' . $stamp . '.txt';

$lines = [];
$line = str_repeat('=', 122);
$lines[] = $line;
$lines[] = 'イン崩壊：穴目予想（結果非参照）';
$lines[] = '日付       : ' . $dateText;
$lines[] = '穴本命A    : 非インAI3連対率1位 × S3_T3（最大9点）';
$lines[] = '穴対抗B    : 非インAI3連対率2位 × S3_T3（最大9点）';
$lines[] = 'B寄り参考  : AI3差<5 かつ BがAより内（表示入替は未採用）';
$lines[] = '重要       : 結果・払戻テーブルは一切参照していません';
$lines[] = '作成時刻   : ' . (new DateTimeImmutable('now'))->format('Y-m-d H:i:s T');
$lines[] = $line;

$valid = 0;
$bLeanCount = 0;
foreach ($rows as $r) {
    if (isset($r['error'])) {
        $lines[] = sprintf('%s %dR ERROR: %s', $r['place'], $r['race_no'], $r['error']);
        continue;
    }
    $valid++;
    if ($r['b_lean']) $bLeanCount++;
    $lines[] = '';
    $lines[] = sprintf(
        '%s %dR %s / 1C=%d / cut=%s',
        $r['place'], $r['race_no'], $r['mode'], $r['in_boat'],
        $r['cut'] === [] ? '-' : implode(',', $r['cut'])
    );
    $lines[] = sprintf(
        '穴本命A=%d（%dC AI3=%.2f） / 穴対抗B=%d（%dC AI3=%.2f） / 差=%.2f%s',
        $r['a_boat'], $r['a_course'], $r['ai_a'],
        $r['b_boat'], $r['b_course'], $r['ai_b'],
        $r['ai_gap'], $r['b_lean'] ? ' / ★B寄り参考' : ''
    );
    $lines[] = 'A ' . count($r['a_bets']) . '点: ' . compactBetsHolePred($r['a_bets']);
    $lines[] = 'B ' . count($r['b_bets']) . '点: ' . compactBetsHolePred($r['b_bets']);
}

$lines[] = '';
$lines[] = $line;
$lines[] = "有効候補={$valid}R / エラー={$errors}R / B寄り参考={$bLeanCount}R";
$lines[] = '※このファイルは予想スナップショットです。結果を後から見ても、この出力自体は変更しません。';
$lines[] = $line;

$text = implode("\n", $lines) . "\n";
file_put_contents($outPath, $text);
echo $text;
echo "保存: {$outPath}\n";
