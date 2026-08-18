<?php

declare(strict_types=1);

/**
 * 仮想進入Web機能の整合性検証。
 *
 * 目的:
 *  1) 通常モードと「展示進入と同じ仮想進入」が、進入依存ロジックで一致すること。
 *  2) 指定した仮想進入が、基本/補正後1着率・決まり手・最終予想・SUM・スリットで
 *     同じ course <-> boat 対応として使われていること。
 *  3) SUMはラベルだけでなく展示値も実艇について移動していること。
 *  4) スリット表示PIDと補正後1着率内部PIDが一致すること。
 *
 * 本番ロジックは変更しない。読み取り検証のみ。
 *
 * Usage:
 *   php analysis/validate_virtual_entry_web.php 20260715AMG08 126345
 */

require_once __DIR__ . '/../web/controllers/IndexController.php';

function failUsage(): never
{
    fwrite(STDERR, "Usage: php analysis/validate_virtual_entry_web.php RACE_CODE VIRTUAL_ORDER\n");
    fwrite(STDERR, "例: php analysis/validate_virtual_entry_web.php 20260715AMG08 126345\n");
    exit(2);
}

function validOrder(string $order): bool
{
    if (strlen($order) !== 6) {
        return false;
    }
    $d = str_split($order);
    sort($d);
    return $d === ['1', '2', '3', '4', '5', '6'];
}

/** @return array{0:array<int,int>,1:array<int,int>,2:string} */
function mapsFromCourseBoatOrder(string $order): array
{
    $courseToBoat = [];
    $boatToCourse = [];
    for ($course = 1; $course <= 6; $course++) {
        $boat = (int)$order[$course - 1];
        $courseToBoat[$course] = $boat;
        $boatToCourse[$boat] = $course;
    }
    ksort($boatToCourse);

    $laneToCourse = '';
    for ($boat = 1; $boat <= 6; $boat++) {
        $laneToCourse .= (string)$boatToCourse[$boat];
    }

    return [$courseToBoat, $boatToCourse, $laneToCourse];
}

/** @return array<string,mixed> */
function runController(string $raceCode, bool $simulate, string $virtualOrder): array
{
    $date8 = substr($raceCode, 0, 8);
    $place = substr($raceCode, 8, 3);
    $race = substr($raceCode, 11, 2);

    $date = substr($date8, 0, 4) . '-' . substr($date8, 4, 2) . '-' . substr($date8, 6, 2);

    $_POST = [];
    $_GET = [
        'date' => $date,
        'place' => $place,
        'race' => $race,
        'virtual_entry' => $virtualOrder,
    ];
    if ($simulate) {
        $_GET['simulate_entry'] = '1';
    }

    $controller = new IndexController();
    return $controller->handle();
}

function fEq(mixed $a, mixed $b, float $eps = 1.0e-9): bool
{
    if (!is_numeric($a) || !is_numeric($b)) {
        return $a === $b;
    }
    return abs((float)$a - (float)$b) <= $eps;
}

function normalizedJson(mixed $v): string
{
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) ?: '';
}

function courseInt(mixed $value): int
{
    if (is_int($value) || is_float($value) || (is_string($value) && ctype_digit($value))) {
        return (int)$value;
    }
    if (is_string($value) && preg_match('/^([1-6])C/', $value, $m)) {
        return (int)$m[1];
    }
    return 0;
}

$checks = [];
$notes = [];

function addCheck(string $label, bool $ok, string $detail = ''): void
{
    global $checks;
    $checks[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
}

function addNote(string $text): void
{
    global $notes;
    $notes[] = $text;
}

if ($argc !== 3) {
    failUsage();
}

$raceCode = strtoupper(trim((string)$argv[1]));
$virtualOrder = trim((string)$argv[2]);

if (!preg_match('/^\d{8}[A-Z]{3}\d{2}$/', $raceCode)) {
    fwrite(STDERR, "race_code形式が不正です: {$raceCode}\n");
    exit(2);
}
if (!validOrder($virtualOrder)) {
    fwrite(STDERR, "仮想進入は1～6号艇を1回ずつ使う6桁です: {$virtualOrder}\n");
    exit(2);
}

[$expectedCourseToBoat, $expectedBoatToCourse, $expectedLaneToCourse] = mapsFromCourseBoatOrder($virtualOrder);

try {
    echo "通常モードを取得中...\n";
    $normal = runController($raceCode, false, '123456');

    $actualOrder = (string)($normal['exhibition_entry_order'] ?? '');
    if (!validOrder($actualOrder)) {
        throw new RuntimeException('展示進入6艇が取得できません: ' . ($actualOrder !== '' ? $actualOrder : 'なし'));
    }

    echo "展示進入と同じ仮想モード({$actualOrder})を取得中...\n";
    $sameAsExhibition = runController($raceCode, true, $actualOrder);

    echo "指定仮想モード({$virtualOrder})を取得中...\n";
    $virtual = runController($raceCode, true, $virtualOrder);
} catch (Throwable $e) {
    fwrite(STDERR, "検証実行エラー: {$e->getMessage()}\n");
    exit(1);
}

// ------------------------------------------------------------------
// A. 通常モード vs 展示進入と同じ仮想モード
// ------------------------------------------------------------------
addCheck(
    '同一進入: prediction_entry_order',
    (string)($normal['prediction_entry_order'] ?? '') === $actualOrder
        && (string)($sameAsExhibition['prediction_entry_order'] ?? '') === $actualOrder,
    "normal=" . ($normal['prediction_entry_order'] ?? '-') . " / virtual_same=" . ($sameAsExhibition['prediction_entry_order'] ?? '-')
);

// 決まり手APIは同じ player×course を見るので完全一致が期待値。
addCheck(
    '同一進入: 決まり手データ完全一致',
    normalizedJson($normal['kimarite_data'] ?? []) === normalizedJson($sameAsExhibition['kimarite_data'] ?? [])
);

// 最終予想: 表示用waku以外の主要計算項目を艇ごとに比較。
$finalKeys = [
    'course', 'rate6_dec', 'rate3_dec', 'kitai_dec',
    'flg_sashi', 'flg_makuri', 'flg_makurizashi', 'flg_nogashi',
    'type', 'typeBonus', 'final3', 'kiru', 'final_rank',
];
$finalParity = true;
$finalDiff = [];
for ($boat = 1; $boat <= 6; $boat++) {
    $a = $normal['final_predictions'][$boat] ?? [];
    $b = $sameAsExhibition['final_predictions'][$boat] ?? [];
    foreach ($finalKeys as $key) {
        $av = $a[$key] ?? null;
        $bv = $b[$key] ?? null;
        if (!fEq($av, $bv)) {
            $finalParity = false;
            $finalDiff[] = "{$boat}号艇 {$key}: " . var_export($av, true) . ' != ' . var_export($bv, true);
        }
    }
}
addCheck('同一進入: 最終予想主要項目一致', $finalParity, implode(' / ', array_slice($finalDiff, 0, 5)));

$summaryKeys = [
    'honmei_head', 'taikou_head', 'honmei_aite_str', 'taikou_aite_str',
    'kiru_str', 'honmei_aite_kako', 'taikou_aite_kako', 'honmei_kai', 'taikou_kai',
];
$summaryParity = true;
$summaryDiff = [];
foreach ($summaryKeys as $key) {
    if (!array_key_exists($key, $normal) && !array_key_exists($key, $sameAsExhibition)) {
        continue;
    }
    $a = $normal[$key] ?? null;
    $b = $sameAsExhibition[$key] ?? null;
    if ($a !== $b) {
        $summaryParity = false;
        $summaryDiff[] = "{$key}: " . var_export($a, true) . ' != ' . var_export($b, true);
    }
}
addCheck('同一進入: 本命/対抗/買い目一致', $summaryParity, implode(' / ', array_slice($summaryDiff, 0, 5)));

// 補正後1着率: 仮想ラッパーでも同じ進入なら同じ値になるべき。
$correctedParity = true;
$correctedDiff = [];
for ($boat = 1; $boat <= 6; $boat++) {
    $a = $normal['corrected_win_rate_data']['boats'][(string)$boat]
        ?? $normal['corrected_win_rate_data']['boats'][$boat]
        ?? [];
    $b = $sameAsExhibition['corrected_win_rate_data']['boats'][(string)$boat]
        ?? $sameAsExhibition['corrected_win_rate_data']['boats'][$boat]
        ?? [];
    foreach (['exhibition_course', 'remap_rate', 'ex_total_rate', 'sum_rate', 'slit_raw_buff', 'corrected_rate'] as $key) {
        if (!fEq($a[$key] ?? null, $b[$key] ?? null, 1.0e-7)) {
            $correctedParity = false;
            $correctedDiff[] = "{$boat}号艇 {$key}";
        }
    }
}
addCheck('同一進入: 補正後1着率チェーン一致', $correctedParity, implode(', ', array_slice($correctedDiff, 0, 10)));

// SUM: コース、展示値、区間、buffが一致すること。
$samParity = true;
$samDiff = [];
$normalSamByBoat = [];
foreach (($normal['sam_applied_list'] ?? []) as $row) {
    $normalSamByBoat[(int)($row['teiban'] ?? 0)] = $row;
}
$sameSamByBoat = [];
foreach (($sameAsExhibition['sam_applied_list'] ?? []) as $row) {
    $sameSamByBoat[(int)($row['teiban'] ?? 0)] = $row;
}
for ($boat = 1; $boat <= 6; $boat++) {
    $a = $normalSamByBoat[$boat] ?? [];
    $b = $sameSamByBoat[$boat] ?? [];
    foreach (['val_j', 'val_k', 'val_l', 'sum', 'avg_diff', 'win', 'place2', 'place3', 'trio'] as $key) {
        if (!fEq($a[$key] ?? null, $b[$key] ?? null, 1.0e-9)) {
            $samParity = false;
            $samDiff[] = "{$boat}号艇 {$key}";
        }
    }
    if (courseInt($a['course'] ?? null) !== courseInt($b['course'] ?? null)) {
        $samParity = false;
        $samDiff[] = "{$boat}号艇 course";
    }
}
addCheck('同一進入: SUM適用値一致', $samParity, implode(', ', array_slice($samDiff, 0, 10)));

$normalSlitPid = (int)($normal['slit_pattern']['id'] ?? 0);
$sameSlitPid = (int)($sameAsExhibition['slit_pattern']['id'] ?? 0);
addCheck(
    '同一進入: スリットPID一致',
    $normalSlitPid > 0 && $normalSlitPid === $sameSlitPid,
    "normal={$normalSlitPid} / virtual_same={$sameSlitPid}"
);
addCheck(
    '同一進入: スリットbuff一致',
    normalizedJson($normal['slit_data'] ?? []) === normalizedJson($sameAsExhibition['slit_data'] ?? [])
);

if ($actualOrder === '123456') {
    $baseParity = true;
    for ($boat = 1; $boat <= 6; $boat++) {
        $a = $normal['base_win_rate_data']['boats'][$boat]['normalized_rate'] ?? null;
        $b = $sameAsExhibition['base_win_rate_data']['boats'][$boat]['normalized_rate'] ?? null;
        if (!fEq($a, $b, 1.0e-9)) {
            $baseParity = false;
        }
    }
    addCheck('同一進入: 基本1着率一致（展示123456）', $baseParity);
} else {
    addNote('基本1着率は通常表示が展示前の枠=コース基準、仮想モードは指定コース基準なので、展示進入変更レースでは同一値比較の対象外。');
}

// ------------------------------------------------------------------
// B. 指定仮想進入の全コンポーネント整合性
// ------------------------------------------------------------------
addCheck(
    '仮想: simulation_active',
    !empty($virtual['simulation_active']) && empty($virtual['virtual_entry_error']),
    (string)($virtual['virtual_entry_error'] ?? '')
);
addCheck(
    '仮想: コース順艇番',
    (string)($virtual['prediction_entry_order'] ?? '') === $virtualOrder,
    'expected=' . $virtualOrder . ' / actual=' . ($virtual['prediction_entry_order'] ?? '-')
);
addCheck(
    '仮想: 内部艇番→コース6桁',
    (string)($virtual['effective_in_course'] ?? '') === $expectedLaneToCourse,
    'expected=' . $expectedLaneToCourse . ' / actual=' . ($virtual['effective_in_course'] ?? '-')
);

$mapOk = true;
for ($boat = 1; $boat <= 6; $boat++) {
    if ((int)($virtual['prediction_course_by_boat'][$boat] ?? 0) !== $expectedBoatToCourse[$boat]) {
        $mapOk = false;
    }
}
for ($course = 1; $course <= 6; $course++) {
    if ((int)($virtual['prediction_boat_by_course'][$course] ?? 0) !== $expectedCourseToBoat[$course]) {
        $mapOk = false;
    }
}
addCheck('仮想: controller双方向map一致', $mapOk);

$baseMapOk = true;
$baseTotal = 0.0;
for ($boat = 1; $boat <= 6; $boat++) {
    $row = $virtual['base_win_rate_data']['boats'][$boat] ?? [];
    if ((int)($row['course'] ?? 0) !== $expectedBoatToCourse[$boat]) {
        $baseMapOk = false;
    }
    $baseTotal += (float)($row['normalized_rate'] ?? 0.0);
}
addCheck('仮想: 基本1着率 boat→course一致', $baseMapOk);
addCheck('仮想: 基本1着率合計100%', abs($baseTotal - 100.0) < 1.0e-6, sprintf('%.8f%%', $baseTotal));

$correctedMapOk = (($virtual['corrected_win_rate_data']['status'] ?? '') === 'ok');
$correctedTotal = 0.0;
for ($boat = 1; $boat <= 6; $boat++) {
    $row = $virtual['corrected_win_rate_data']['boats'][(string)$boat]
        ?? $virtual['corrected_win_rate_data']['boats'][$boat]
        ?? [];
    if ((int)($row['exhibition_course'] ?? 0) !== $expectedBoatToCourse[$boat]) {
        $correctedMapOk = false;
    }
    $correctedTotal += (float)($row['corrected_rate'] ?? 0.0);
}
addCheck(
    '仮想: 補正後1着率 boat→course一致',
    $correctedMapOk,
    (string)($virtual['corrected_win_rate_data']['error'] ?? '')
);
addCheck('仮想: 補正後1着率合計100%', abs($correctedTotal - 100.0) < 1.0e-5, sprintf('%.8f%%', $correctedTotal));

$finalMapOk = true;
for ($boat = 1; $boat <= 6; $boat++) {
    $row = $virtual['final_predictions'][$boat] ?? [];
    if ((int)($row['course'] ?? 0) !== $expectedBoatToCourse[$boat]) {
        $finalMapOk = false;
    }
}
addCheck('仮想: 最終予想 boat→course一致', $finalMapOk);

// SUMのコースと、J/K/Lが実艇の展示値に追従しているか。
$tenjiByBoat = [];
foreach (($virtual['tenji_list'] ?? []) as $t) {
    $tenjiByBoat[(int)($t['teiban'] ?? 0)] = $t;
}
$samRows = array_values($virtual['sam_applied_list'] ?? []);
$samMapOk = count($samRows) === 6;
$samValueOk = count($samRows) === 6;
$samOrder = '';
foreach ($samRows as $idx => $s) {
    $course = courseInt($s['course'] ?? null);
    $boat = (int)($s['teiban'] ?? 0);
    $expectedCourse = $boat >= 1 && $boat <= 6 ? $expectedBoatToCourse[$boat] : 0;
    if ($course !== $expectedCourse || $course !== $idx + 1) {
        $samMapOk = false;
    }
    if ($course >= 1 && $course <= 6) {
        $samOrder .= (string)$boat;
    }

    $t = $tenjiByBoat[$boat] ?? [];
    foreach ([['val_j', 'tenji_J'], ['val_k', 'tenji_K'], ['val_l', 'tenji_L']] as [$samKey, $tenjiKey]) {
        $tv = $t[$tenjiKey] ?? null;
        $sv = $s[$samKey] ?? null;
        if (!fEq($sv, is_numeric($tv) ? (float)$tv : 0.0, 1.0e-9)) {
            $samValueOk = false;
        }
    }
}
addCheck('仮想: SUMコース順/艇番一致', $samMapOk && $samOrder === $virtualOrder, "order={$samOrder}");
addCheck('仮想: SUM展示値が実艇に追従', $samValueOk);

// スリット predict_detail の player_id がコース順に仮想配置された選手と一致すること。
$entryPlayerByBoat = [];
foreach (($virtual['entries'] ?? []) as $e) {
    $boat = (int)($e['lane_number'] ?? 0);
    if ($boat >= 1 && $boat <= 6) {
        $entryPlayerByBoat[$boat] = trim((string)($e['player_id'] ?? ''));
    }
}
$predictDetail = $virtual['slit_pattern']['predict_detail'] ?? [];
$slitPlayers = $predictDetail['player_ids'] ?? [];
$slitPlayerOk = count($slitPlayers) === 6;
for ($course = 1; $course <= 6; $course++) {
    $boat = $expectedCourseToBoat[$course];
    $expectedPid = $entryPlayerByBoat[$boat] ?? '';
    $actualPid = trim((string)($slitPlayers[$course - 1] ?? ''));
    if ($expectedPid === '' || $actualPid !== $expectedPid) {
        $slitPlayerOk = false;
    }
}
addCheck('仮想: スリット選手が仮想コース順', $slitPlayerOk);

$screenSlitPid = (int)($virtual['slit_pattern']['id'] ?? 0);
$correctedSlitPid = (int)($virtual['corrected_win_rate_data']['method']['slit_pattern_id'] ?? 0);
addCheck(
    '仮想: スリット表示PID = 補正後1着率PID',
    $screenSlitPid > 0 && $screenSlitPid === $correctedSlitPid,
    "screen={$screenSlitPid} / corrected={$correctedSlitPid}"
);

// エラー系。
$errorFields = [
    '出走表' => (string)($virtual['api_error'] ?? ''),
    '決まり手' => (string)($virtual['kimarite_error'] ?? ''),
    '展示' => (string)($virtual['tenji_error'] ?? ''),
    'SUM' => (string)($virtual['sam_error'] ?? ''),
];
$errorsOk = true;
$errorText = [];
foreach ($errorFields as $name => $value) {
    if ($value !== '') {
        $errorsOk = false;
        $errorText[] = "{$name}:{$value}";
    }
}
addCheck('仮想: API/展示/SUMエラーなし', $errorsOk, implode(' / ', $errorText));

// ------------------------------------------------------------------
// 出力
// ------------------------------------------------------------------
$okCount = count(array_filter($checks, static fn(array $x): bool => $x['ok']));
$ngCount = count($checks) - $okCount;

$line = str_repeat('=', 118);
echo "\n{$line}\n";
echo "仮想進入Web エンドツーエンド整合性検証\n";
echo "{$line}\n";
echo "Race              : {$raceCode}\n";
echo "展示進入          : {$actualOrder}\n";
echo "試算進入          : {$virtualOrder}  (コース順の艇番)\n";
echo "内部boat→course   : {$expectedLaneToCourse}\n";
echo "\n【A. 通常モード ↔ 展示進入と同じ仮想モード】\n";

$sectionBStarted = false;
foreach ($checks as $i => $check) {
    if (!$sectionBStarted && str_starts_with($check['label'], '仮想:')) {
        $sectionBStarted = true;
        echo "\n【B. 指定仮想進入の全コンポーネント整合性】\n";
    }
    $mark = $check['ok'] ? 'OK' : 'NG';
    printf("%-54s : %s", $check['label'], $mark);
    if ($check['detail'] !== '') {
        echo "  {$check['detail']}";
    }
    echo "\n";
}

if ($notes) {
    echo "\n【注記】\n";
    foreach ($notes as $note) {
        echo "- {$note}\n";
    }
}

echo "\n【最終判定】\n";
echo "OK={$okCount} / NG={$ngCount}\n";
if ($ngCount === 0) {
    echo "仮想進入の整合性 : ALL OK\n";
    echo "判定               : 通常経路との同一進入パリティ、および指定仮想進入の全ロジック連携に問題なし\n";
} else {
    echo "仮想進入の整合性 : NGあり\n";
    echo "判定               : NG項目を修正して再検証\n";
}
echo "{$line}\n";

exit($ngCount === 0 ? 0 : 1);
