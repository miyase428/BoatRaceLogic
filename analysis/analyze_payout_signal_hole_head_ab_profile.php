<?php

declare(strict_types=1);

/**
 * イン崩壊候補の穴頭A/Bについて「どんな時にBが勝つか」を記述的に比較する。
 *
 * A = 非インAI3連対率1位
 * B = 非インAI3連対率2位
 *
 * 見るもの:
 * - AI3 A-B差
 * - A/Bの進入コース
 * - 一次(first_total_score)のB-A差
 * - 二次(second_score)のB-A差
 * - 最終(final3)のB-A差
 * - 展示総合(ex_sougou)のB-A差
 * - ST評価(st_score)のB-A差
 *
 * 注意:
 * - 既観察期間の構造確認専用。ここで閾値や選択ルールを作らない。
 * - PayoutSignalClassifier / PredictionLogic / 本番買い目は変更しない。
 * - 「B率」は実勝ち頭がA/Bのどちらかだったレース内でのB勝ち比率。
 *
 * Usage:
 *   php analysis/analyze_payout_signal_hole_head_ab_profile.php 2026-08-01 2026-08-31
 *   php analysis/analyze_payout_signal_hole_head_ab_profile.php 2026-08-01 2026-09-06
 */

require_once __DIR__ . '/../web/controllers/IndexController.php';
require_once __DIR__ . '/../web/logic/AiTrioRateLogic.php';
require_once __DIR__ . '/../web/logic/BaseWinRateLogic.php';
require_once __DIR__ . '/../common/PayoutSignalFeatureBuilder.php';
require_once __DIR__ . '/../common/PayoutSignalClassifier.php';
require_once __DIR__ . '/../common/db_connect.php';

date_default_timezone_set('Asia/Tokyo');

function failAB(string $message, int $code = 1): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($code);
}

function pctAB(int|float $n, int|float $d): float
{
    return $d > 0 ? (float)$n / (float)$d * 100.0 : 0.0;
}

/** @return array<int,int> */
function normalizeCourseMapAB(array $map): array
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

/** @return array<int,array<string,mixed>> */
function targetsForDateAB(DateTimeImmutable $dt): array
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
    return $targets;
}

function actualFirstAB(string $raceCode): int
{
    try {
        $pdo = getPDO();
        $stmt = $pdo->prepare(
            "SELECT lane_number FROM boat_race.race_result_detail "
            . "WHERE race_code=:race_code AND rank::text='1' LIMIT 1"
        );
        $stmt->execute([':race_code' => $raceCode]);
        $v = $stmt->fetchColumn();
        return is_numeric($v) ? (int)$v : 0;
    } catch (Throwable) {
        return 0;
    }
}

/** @return array<string,mixed> */
function fpForBoatAB(array $fps, int $boat): array
{
    $row = $fps[$boat] ?? $fps[(string)$boat] ?? [];
    return is_array($row) ? $row : [];
}

/** @return array<string,mixed> */
function tenjiForBoatAB(array $tenji, int $boat): array
{
    foreach ($tenji as $idx => $row) {
        if (!is_array($row)) continue;
        $b = (int)($row['teiban'] ?? $row['boat'] ?? $row['lane_number'] ?? ((int)$idx + 1));
        if ($b === $boat) return $row;
    }
    return [];
}

function numAB(mixed $v): ?float
{
    return is_numeric($v) ? (float)$v : null;
}

function diffAB(?float $b, ?float $a): ?float
{
    return $a !== null && $b !== null ? $b - $a : null;
}

/** @return array<string,mixed> */
function evaluateAB(array $target): array
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

    $courseByBoat = normalizeCourseMapAB((array)($view['prediction_course_by_boat'] ?? []));
    if ($courseByBoat === []) $courseByBoat = array_combine(range(1, 6), range(1, 6));
    $inBoat = 0;
    foreach ($courseByBoat as $boat => $course) {
        if ((int)$course === 1) { $inBoat = (int)$boat; break; }
    }
    if ($inBoat < 1 || $inBoat > 6) throw new RuntimeException('in boat missing');

    $results = is_array($view['results'] ?? null) ? $view['results'] : [];
    $tenji = is_array($view['tenji_list'] ?? null) ? $view['tenji_list'] : [];
    $aiLogic = new AiTrioRateLogic();
    $ai = $aiLogic->calculate((string)$target['race_code'], $results, $tenji, $courseByBoat, false);
    $mode = '展示反映済';

    if (($ai['status'] ?? '') !== 'ok') {
        $mode = '暫定';
        $courseByBoat = array_combine(range(1, 6), range(1, 6));
        $inBoat = 1;
        $neutral = [];
        for ($boat = 1; $boat <= 6; $boat++) {
            $neutral[] = ['teiban'=>$boat, 'tenji_course'=>$boat, 'final_2nd_score'=>0.0];
        }
        $ai = $aiLogic->calculate((string)$target['race_code'], $results, $neutral, $courseByBoat, true);
    }
    if (($ai['status'] ?? '') !== 'ok') throw new RuntimeException('AI3 unavailable');
    $aiBoats = is_array($ai['boats'] ?? null) ? $ai['boats'] : [];

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

    $fps = is_array($view['final_predictions'] ?? null) ? $view['final_predictions'] : [];
    $fa = fpForBoatAB($fps, $a);
    $fb = fpForBoatAB($fps, $b);
    $ta = tenjiForBoatAB($tenji, $a);
    $tb = tenjiForBoatAB($tenji, $b);

    $aiA = (float)$ranked[$a];
    $aiB = (float)$ranked[$b];
    $firstA = numAB($fa['first_total_score'] ?? null);
    $firstB = numAB($fb['first_total_score'] ?? null);
    $secondA = numAB($fa['second_score'] ?? $ta['final_2nd_score'] ?? null);
    $secondB = numAB($fb['second_score'] ?? $tb['final_2nd_score'] ?? null);
    $finalA = numAB($fa['final3'] ?? null);
    $finalB = numAB($fb['final3'] ?? null);
    $exA = numAB($ta['ex_sougou'] ?? null);
    $exB = numAB($tb['ex_sougou'] ?? null);
    $stA = numAB($ta['st_score'] ?? null);
    $stB = numAB($tb['st_score'] ?? null);
    $actual = actualFirstAB((string)$target['race_code']);

    return [
        ...$target,
        'mode' => $mode,
        'in_boat' => $inBoat,
        'actual_first' => $actual,
        'winner_group' => $actual === $a ? 'A' : ($actual === $b ? 'B' : 'OTHER'),
        'a_boat' => $a,
        'b_boat' => $b,
        'a_course' => (int)($courseByBoat[$a] ?? $a),
        'b_course' => (int)($courseByBoat[$b] ?? $b),
        'ai_a' => $aiA,
        'ai_b' => $aiB,
        'ai_gap' => $aiA - $aiB,
        'first_a' => $firstA,
        'first_b' => $firstB,
        'first_diff_ba' => diffAB($firstB, $firstA),
        'second_a' => $secondA,
        'second_b' => $secondB,
        'second_diff_ba' => diffAB($secondB, $secondA),
        'final_a' => $finalA,
        'final_b' => $finalB,
        'final_diff_ba' => diffAB($finalB, $finalA),
        'ex_a' => $exA,
        'ex_b' => $exB,
        'ex_diff_ba' => diffAB($exB, $exA),
        'st_a' => $stA,
        'st_b' => $stB,
        'st_diff_ba' => diffAB($stB, $stA),
    ];
}

function meanAB(array $rows, string $key): ?float
{
    $vals = [];
    foreach ($rows as $r) {
        if (isset($r[$key]) && is_numeric($r[$key])) $vals[] = (float)$r[$key];
    }
    return $vals !== [] ? array_sum($vals) / count($vals) : null;
}

function medianAB(array $rows, string $key): ?float
{
    $vals = [];
    foreach ($rows as $r) {
        if (isset($r[$key]) && is_numeric($r[$key])) $vals[] = (float)$r[$key];
    }
    if ($vals === []) return null;
    sort($vals, SORT_NUMERIC);
    $n = count($vals);
    $m = intdiv($n, 2);
    return $n % 2 ? $vals[$m] : ($vals[$m - 1] + $vals[$m]) / 2.0;
}

function fmtAB(?float $v, int $dec = 2): string
{
    return $v === null ? '-' : number_format($v, $dec);
}

/** @return array{a:int,b:int,n:int} */
function winnerShareAB(array $rows, callable $pred): array
{
    $a = 0; $b = 0;
    foreach ($rows as $r) {
        if (!$pred($r)) continue;
        if (($r['winner_group'] ?? '') === 'A') $a++;
        elseif (($r['winner_group'] ?? '') === 'B') $b++;
    }
    return ['a'=>$a, 'b'=>$b, 'n'=>$a+$b];
}

function printShareAB(string $label, array $s): void
{
    $bPct = pctAB($s['b'], $s['n']);
    printf("%-24s %5d  A=%4d  B=%4d  B率=%6.2f%%\n", $label, $s['n'], $s['a'], $s['b'], $bPct);
}

$startText = trim((string)($argv[1] ?? ''));
$endText = trim((string)($argv[2] ?? $startText));
$start = DateTimeImmutable::createFromFormat('!Y-m-d', $startText);
$end = DateTimeImmutable::createFromFormat('!Y-m-d', $endText);
if ($start === false || $end === false
    || $start->format('Y-m-d') !== $startText || $end->format('Y-m-d') !== $endText || $end < $start) {
    failAB('Usage: php analysis/analyze_payout_signal_hole_head_ab_profile.php YYYY-MM-DD [YYYY-MM-DD]');
}

$rows = [];
$errors = 0;
for ($dt = $start; $dt <= $end; $dt = $dt->modify('+1 day')) {
    $targets = targetsForDateAB($dt);
    foreach ($targets as $idx => $target) {
        fprintf(STDERR, "[%s %d/%d] %s %dR A/B特徴を計算中...\n",
            $dt->format('Y-m-d'), $idx + 1, count($targets), (string)$target['place'], (int)$target['race_no']);
        try {
            $rows[] = evaluateAB($target);
        } catch (Throwable $e) {
            $errors++;
        }
    }
}

$captured = array_values(array_filter($rows, static fn(array $r): bool => in_array($r['winner_group'], ['A','B'], true)));
$aWins = array_values(array_filter($captured, static fn(array $r): bool => $r['winner_group'] === 'A'));
$bWins = array_values(array_filter($captured, static fn(array $r): bool => $r['winner_group'] === 'B'));

$outputDir = __DIR__ . '/output';
if (!is_dir($outputDir)) @mkdir($outputDir, 0775, true);
$csvPath = $outputDir . '/payout_signal_hole_head_ab_profile_' . $start->format('Ymd') . '_' . $end->format('Ymd') . '.csv';
$fp = fopen($csvPath, 'wb');
if ($fp !== false) {
    $cols = ['date','place','race_no','race_code','mode','in_boat','actual_first','winner_group','a_boat','b_boat','a_course','b_course','ai_a','ai_b','ai_gap','first_a','first_b','first_diff_ba','second_a','second_b','second_diff_ba','final_a','final_b','final_diff_ba','ex_a','ex_b','ex_diff_ba','st_a','st_b','st_diff_ba'];
    fputcsv($fp, $cols);
    foreach ($rows as $r) {
        $out = [];
        foreach ($cols as $c) $out[] = $r[$c] ?? '';
        fputcsv($fp, $out);
    }
    fclose($fp);
}

$line = str_repeat('=', 118);
echo $line . "\n";
echo "イン崩壊：穴本命A / 穴対抗B 勝ちパターン比較\n";
echo "期間       : {$startText} ～ {$endText}\n";
echo "A          : 非インAI3連対率1位\n";
echo "B          : 非インAI3連対率2位\n";
echo "位置づけ   : 既観察期間の記述分析。ここから選択ルールや閾値を固定しない\n";
echo "CSV保存    : {$csvPath}\n";
echo $line . "\n";

echo "有効候補   : " . count($rows) . "R / エラー {$errors}R\n";
echo "A/B頭捕捉  : " . count($captured) . "R\n";
echo "A勝ち      : " . count($aWins) . "R / " . count($captured) . "R = " . number_format(pctAB(count($aWins), count($captured)), 2) . "%\n";
echo "B勝ち      : " . count($bWins) . "R / " . count($captured) . "R = " . number_format(pctAB(count($bWins), count($captured)), 2) . "%\n";

echo "\n【A勝ち vs B勝ち：平均 / 中央値】\n";
echo "指標                     A勝ち平均  A勝ち中央  B勝ち平均  B勝ち中央\n";
echo str_repeat('-', 82) . "\n";
$metrics = [
    'ai_gap' => 'AI3 A-B差',
    'a_course' => 'Aコース',
    'b_course' => 'Bコース',
    'first_diff_ba' => '一次 B-A差',
    'second_diff_ba' => '二次 B-A差',
    'final_diff_ba' => '最終 B-A差',
    'ex_diff_ba' => '展示総合 B-A差',
    'st_diff_ba' => 'ST評価 B-A差',
];
foreach ($metrics as $key => $label) {
    printf("%-24s %10s %10s %10s %10s\n",
        $label,
        fmtAB(meanAB($aWins, $key)), fmtAB(medianAB($aWins, $key)),
        fmtAB(meanAB($bWins, $key)), fmtAB(medianAB($bWins, $key))
    );
}

echo "\n【AI3 A-B差別：A/Bどちらが勝ったか】\n";
$gapBins = [
    '0～2未満' => static fn(array $r): bool => $r['ai_gap'] >= 0.0 && $r['ai_gap'] < 2.0,
    '2～5未満' => static fn(array $r): bool => $r['ai_gap'] >= 2.0 && $r['ai_gap'] < 5.0,
    '5～10未満' => static fn(array $r): bool => $r['ai_gap'] >= 5.0 && $r['ai_gap'] < 10.0,
    '10以上' => static fn(array $r): bool => $r['ai_gap'] >= 10.0,
];
foreach ($gapBins as $label => $pred) printShareAB($label, winnerShareAB($captured, $pred));

echo "\n【Bの進入コース別】\n";
for ($c = 2; $c <= 6; $c++) {
    printShareAB("B={$c}コース", winnerShareAB($captured, static fn(array $r): bool => (int)$r['b_course'] === $c));
}

echo "\n【A/Bの相対位置】\n";
printShareAB('BがAより内', winnerShareAB($captured, static fn(array $r): bool => (int)$r['b_course'] < (int)$r['a_course']));
printShareAB('BがAより外', winnerShareAB($captured, static fn(array $r): bool => (int)$r['b_course'] > (int)$r['a_course']));

echo "\n【BがAを上回っている時】\n";
$relative = [
    '一次 B>A' => 'first_diff_ba',
    '二次 B>A' => 'second_diff_ba',
    '最終 B>A' => 'final_diff_ba',
    '展示総合 B>A' => 'ex_diff_ba',
    'ST評価 B>A' => 'st_diff_ba',
];
foreach ($relative as $label => $key) {
    printShareAB($label, winnerShareAB($captured, static fn(array $r): bool => isset($r[$key]) && is_numeric($r[$key]) && (float)$r[$key] > 0.0));
}

echo "\n【BがA以下の時：対照】\n";
foreach ($relative as $label => $key) {
    $short = str_replace(' B>A', ' B<=A', $label);
    printShareAB($short, winnerShareAB($captured, static fn(array $r): bool => isset($r[$key]) && is_numeric($r[$key]) && (float)$r[$key] <= 0.0));
}

echo "\n※B率が高い条件があっても、この期間を見て閾値化しません。まず構造確認として扱い、必要なら別期間で再現性を確認します。\n";
echo $line . "\n";
