<?php

declare(strict_types=1);

/**
 * レース別 配当サイン連動「穴目」診断。
 *
 * 目的:
 * - PayoutSignalClassifier の荒れ方を、そのレース固有の穴目買い目へ接続して確認する。
 * - 展示情報がそろっていれば、補正後1着率 / 展示後AI3連対率 / 実展示進入 / 最終120通りを使う。
 * - 展示前は、基本1着率 + 二次評価中立のAI3連対率 + 枠なり進入で暫定計算する。
 * - 本命/対抗/既存買い目は変更しない。診断表示専用。
 *
 * 現在の穴目候補方式:
 * - イン崩壊   : 非イン艇のAI3連対率Top2を頭、各頭ごとに P(2着|頭) Top3、3着=現行非cut艇
 * - ヒモ荒れ   : イン艇を頭、P(2着|頭) Top3、3着=現行非cut艇
 * - 複合高配当 : 方式未固定のため買い目はまだ出さない
 * - 平常       : 穴目なし
 *
 * Usage:
 *   php analysis/inspect_payout_signal_hole_bet.php 20260906NRT02
 */

require_once __DIR__ . '/../web/controllers/IndexController.php';
require_once __DIR__ . '/../web/logic/AiTrioRateLogic.php';
require_once __DIR__ . '/../web/logic/TrifectaProbabilityLogic.php';
require_once __DIR__ . '/../web/logic/BaseWinRateLogic.php';
require_once __DIR__ . '/../common/PayoutSignalFeatureBuilder.php';
require_once __DIR__ . '/../common/PayoutSignalClassifier.php';

date_default_timezone_set('Asia/Tokyo');

function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($code);
}

function pct(float $v): string
{
    return number_format($v, 1) . '%';
}

function levelJa(string $level): string
{
    return match ($level) {
        'strong' => '強',
        'watch' => '注',
        default => '低',
    };
}

function boatKey(int $a, int $b, int $c): string
{
    return $a . '-' . $b . '-' . $c;
}

/** @return array<int,int> */
function normalizeCourseMap(array $map): array
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
    return $courses === [1, 2, 3, 4, 5, 6] ? $out : [];
}

/** @return array<int,int> */
function currentCut(array $finalPredictions): array
{
    $cut = [];
    foreach ($finalPredictions as $key => $row) {
        if (!is_array($row)) {
            continue;
        }
        $boat = (int)($row['lane_number'] ?? $row['teiban'] ?? $key);
        if ($boat >= 1 && $boat <= 6 && (int)($row['kiru'] ?? 0) === 1) {
            $cut[$boat] = $boat;
        }
    }
    ksort($cut);
    return array_values($cut);
}

/**
 * @return array{head:int,second:array<int,int>,third:array<int,int>,second_rates:array<int,float>,bets:array<string,array{0:int,1:int,2:int}>}|null
 */
function buildOutcomeBetForHead(int $head, array $rows, array $cut): ?array
{
    $cutMap = array_fill_keys($cut, true);
    unset($cutMap[$head]);

    $eligible = [];
    for ($boat = 1; $boat <= 6; $boat++) {
        if ($boat !== $head && !isset($cutMap[$boat])) {
            $eligible[] = $boat;
        }
    }
    if ($eligible === []) {
        return null;
    }

    $mass = array_fill(1, 6, 0.0);
    $headMass = 0.0;
    foreach ($rows as $row) {
        $boats = is_array($row['boats'] ?? null) ? $row['boats'] : [];
        if (count($boats) !== 3 || (int)$boats[0] !== $head) {
            continue;
        }
        $second = (int)$boats[1];
        $p = (float)($row['probability'] ?? 0.0);
        if ($second < 1 || $second > 6 || $second === $head || $p < 0.0) {
            continue;
        }
        $headMass += $p;
        $mass[$second] += $p;
    }
    if ($headMass <= 0.0) {
        return null;
    }

    usort($eligible, static function (int $a, int $b) use ($mass): int {
        $cmp = $mass[$b] <=> $mass[$a];
        return $cmp !== 0 ? $cmp : ($a <=> $b);
    });

    $second = array_slice($eligible, 0, min(3, count($eligible)));
    $third = $eligible;
    sort($third);

    $rates = [];
    foreach ($second as $boat) {
        $rates[$boat] = 100.0 * $mass[$boat] / $headMass;
    }

    $bets = [];
    foreach ($second as $b) {
        foreach ($third as $c) {
            if ($b === $c || $head === $b || $head === $c) {
                continue;
            }
            $bets[boatKey($head, $b, $c)] = [$head, $b, $c];
        }
    }

    return [
        'head' => $head,
        'second' => $second,
        'third' => $third,
        'second_rates' => $rates,
        'bets' => $bets,
    ];
}

function compactBoats(array $boats): string
{
    return $boats === [] ? '-' : implode('', array_map('strval', $boats));
}

/** @return array{actual:?string,payout:?int} */
function loadActual(string $raceCode): array
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
            ? boatKey((int)$byRank[1], (int)$byRank[2], (int)$byRank[3])
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

$raceCode = strtoupper(trim((string)($argv[1] ?? '')));
if (!preg_match('/^(\d{8})([A-Z0-9]{3})(0[1-9]|1[0-2])$/', $raceCode, $m)) {
    fail('Usage: php analysis/inspect_payout_signal_hole_bet.php YYYYMMDDXXXRR');
}

$dateObj = DateTimeImmutable::createFromFormat('!Ymd', $m[1]);
if (!$dateObj || $dateObj->format('Ymd') !== $m[1]) {
    fail('race_codeの日付が不正です');
}

$place = $m[2];
$raceNo = (int)$m[3];

$_GET = [
    'date' => $dateObj->format('Y-m-d'),
    'place' => $place,
    'race' => (string)$raceNo,
];
$_POST = [];

$controller = new IndexController();
$view = $controller->handle();

$kimarite = is_array($view['kimarite_data'] ?? null) ? $view['kimarite_data'] : [];
$summary = [
    'honmei_head' => $view['honmei_head'] ?? null,
    'taikou_head' => $view['taikou_head'] ?? null,
];

$feature = PayoutSignalFeatureBuilder::build($raceNo, $kimarite, $summary);
if (($feature['status'] ?? '') !== 'ok') {
    fail('配当サイン判定待ち: ' . (string)($feature['reason'] ?? '特徴量不足'));
}
$class = PayoutSignalClassifier::classify((array)$feature['input']);

$courseByBoat = normalizeCourseMap((array)($view['prediction_course_by_boat'] ?? []));
if ($courseByBoat === []) {
    $courseByBoat = array_combine(range(1, 6), range(1, 6));
}

$inBoat = 0;
foreach ($courseByBoat as $boat => $course) {
    if ((int)$course === 1) {
        $inBoat = (int)$boat;
        break;
    }
}
if ($inBoat < 1 || $inBoat > 6) {
    fail('1コース艇を特定できません');
}

$results = is_array($view['results'] ?? null) ? $view['results'] : [];
$tenji = is_array($view['tenji_list'] ?? null) ? $view['tenji_list'] : [];
$corrected = is_array($view['corrected_win_rate_data'] ?? null)
    ? $view['corrected_win_rate_data']
    : [];
$correctedBoats = is_array($corrected['boats'] ?? null) ? $corrected['boats'] : [];

$mode = '展示反映済';
$aiTrioLogic = new AiTrioRateLogic();
$aiTrio = $aiTrioLogic->calculate($raceCode, $results, $tenji, $courseByBoat, false);

if (($corrected['status'] ?? '') !== 'ok' || ($aiTrio['status'] ?? '') !== 'ok') {
    $mode = '暫定';

    // 展示前は枠なりで固定する。
    $courseByBoat = array_combine(range(1, 6), range(1, 6));
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
        $neutralTenji[] = [
            'teiban' => $boat,
            'tenji_course' => $boat,
            'final_2nd_score' => 0.0,
        ];
    }
    $aiTrio = $aiTrioLogic->calculate($raceCode, $results, $neutralTenji, $courseByBoat, true);
}

if (($aiTrio['status'] ?? '') !== 'ok') {
    fail('AI3連対率を計算できません: ' . (string)($aiTrio['error'] ?? '不明'));
}
$aiTrioBoats = is_array($aiTrio['boats'] ?? null) ? $aiTrio['boats'] : [];

$trifectaLogic = new TrifectaProbabilityLogic();
$trifecta = $trifectaLogic->calculate($raceCode, $correctedBoats, $aiTrioBoats, $courseByBoat);
if (($trifecta['status'] ?? '') !== 'ok') {
    fail('120通りを計算できません: ' . (string)($trifecta['error'] ?? '不明'));
}
$trifectaRows = is_array($trifecta['rows'] ?? null) ? $trifecta['rows'] : [];

$cut = currentCut((array)($view['final_predictions'] ?? []));
$primary = (string)($class['chaos']['primary'] ?? '平常');

$heads = [];
$strategy = '';
if ($primary === 'イン崩壊') {
    $ranked = [];
    for ($boat = 1; $boat <= 6; $boat++) {
        if ($boat === $inBoat) {
            continue;
        }
        $rate = $aiTrioBoats[$boat]['ai_rate']
            ?? $aiTrioBoats[(string)$boat]['ai_rate']
            ?? null;
        if (is_numeric($rate)) {
            $ranked[$boat] = (float)$rate;
        }
    }
    uksort($ranked, static function (int|string $a, int|string $b) use ($ranked): int {
        $cmp = $ranked[(int)$b] <=> $ranked[(int)$a];
        return $cmp !== 0 ? $cmp : ((int)$a <=> (int)$b);
    });
    $heads = array_slice(array_map('intval', array_keys($ranked)), 0, 2);
    $strategy = '非インAI3連対率Top2頭 + 各頭P(2着|頭)Top3';
} elseif ($primary === 'ヒモ荒れ') {
    $heads = [$inBoat];
    $strategy = 'イン頭固定 + P(2着|頭)Top3';
} elseif ($primary === '複合高配当') {
    $strategy = '方式未固定（診断表示のみ）';
} else {
    $strategy = '平常のため穴目なし';
}

$scenarios = [];
$allBets = [];
foreach ($heads as $head) {
    $s = buildOutcomeBetForHead($head, $trifectaRows, $cut);
    if ($s === null) {
        continue;
    }
    $scenarios[] = $s;
    foreach ($s['bets'] as $key => $bet) {
        $allBets[$key] = $bet;
    }
}
ksort($allBets, SORT_NATURAL);

$placeNames = is_array($view['place_names'] ?? null) ? $view['place_names'] : [];
$placeName = (string)($placeNames[$place] ?? $place);

$medium = $class['payout']['medium'] ?? [];
$high = $class['payout']['high'] ?? [];
$big = $class['payout']['big'] ?? [];

$actual = loadActual($raceCode);

printf("%s\n", str_repeat('=', 132));
printf("配当サイン連動 穴目・1レース診断\n");
printf("%s\n", str_repeat('=', 132));
printf("レース       : %s %dR  (%s)\n", $placeName, $raceNo, $raceCode);
printf("判定モード   : %s\n", $mode);
printf("進入         : ");
$byCourse = array_flip($courseByBoat);
for ($course = 1; $course <= 6; $course++) {
    printf("%dC=%d号艇%s", $course, (int)($byCourse[$course] ?? 0), $course === 6 ? "\n" : ' / ');
}
printf("本命/対抗    : %s / %s\n", (string)($view['honmei_head'] ?? '-'), (string)($view['taikou_head'] ?? '-'));
printf("現行cut      : %s\n", $cut === [] ? '-' : implode(',', $cut));
printf("配当傾向     : 中=%s %d/%d  高=%s %d/%d  大=%s %d/%d\n",
    levelJa((string)($medium['level'] ?? 'low')), (int)($medium['score'] ?? 0), (int)($medium['max_score'] ?? 0),
    levelJa((string)($high['level'] ?? 'low')), (int)($high['score'] ?? 0), (int)($high['max_score'] ?? 0),
    levelJa((string)($big['level'] ?? 'low')), (int)($big['score'] ?? 0), (int)($big['max_score'] ?? 0)
);
printf("荒れ方       : %s\n", $primary);
printf("穴目方式     : %s\n", $strategy);

printf("\n【一致サイン】\n");
foreach (['medium' => '中', 'high' => '高', 'big' => '大'] as $key => $label) {
    $signals = is_array($class['payout'][$key]['signals'] ?? null) ? $class['payout'][$key]['signals'] : [];
    $pairs = is_array($class['payout'][$key]['pairs'] ?? null) ? $class['payout'][$key]['pairs'] : [];
    printf("%s: %s\n", $label, $signals === [] ? '-' : implode(' / ', $signals));
    if ($pairs !== []) {
        printf("   強ペア: %s\n", implode(' / ', $pairs));
    }
}

printf("\n【AI3連対率（%s）】\n", $mode);
$aiRows = [];
for ($boat = 1; $boat <= 6; $boat++) {
    $rate = $aiTrioBoats[$boat]['ai_rate']
        ?? $aiTrioBoats[(string)$boat]['ai_rate']
        ?? null;
    if (is_numeric($rate)) {
        $aiRows[$boat] = (float)$rate;
    }
}
arsort($aiRows, SORT_NUMERIC);
foreach ($aiRows as $boat => $rate) {
    printf("%d号艇(%dC) %6.2f%%%s\n", $boat, (int)($courseByBoat[$boat] ?? 0), $rate, in_array($boat, $heads, true) ? '  ←穴頭' : '');
}

if ($scenarios === []) {
    printf("\n【穴目買い目】\n%s\n", $strategy);
} else {
    printf("\n【穴目買い目】\n");
    foreach ($scenarios as $s) {
        $head = (int)$s['head'];
        printf("頭 %d号艇(%dC): %d-%s-%s  %d点\n",
            $head,
            (int)($courseByBoat[$head] ?? 0),
            $head,
            compactBoats($s['second']),
            compactBoats($s['third']),
            count($s['bets'])
        );
        $rateParts = [];
        foreach ($s['second'] as $boat) {
            $rateParts[] = $boat . '号艇=' . pct((float)($s['second_rates'][$boat] ?? 0.0));
        }
        printf("  2着条件付き: %s\n", implode(' / ', $rateParts));
    }
    printf("合計          : %d点（重複除外）\n", count($allBets));
    printf("全買い目      : %s\n", implode(' ', array_keys($allBets)));
}

if ($actual['actual'] !== null) {
    $hit = isset($allBets[$actual['actual']]);
    printf("\n【結果確認】\n");
    printf("実3連単       : %s", $actual['actual']);
    if ($actual['payout'] !== null) {
        printf(" / %s円", number_format((int)$actual['payout']));
    }
    printf("\n");
    if ($allBets !== []) {
        printf("穴目           : %s\n", $hit ? '的中' : '不的中');
    }
}

printf("%s\n", str_repeat('=', 132));
printf("注: 診断用。既存の本命/対抗/買い目は変更していません。複合高配当の穴目方式は未固定です。\n");
printf("%s\n", str_repeat('=', 132));
