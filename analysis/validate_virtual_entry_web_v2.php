<?php

declare(strict_types=1);

/**
 * 仮想進入Web機能 エンドツーエンド整合性検証 V2。
 *
 * V1で偽NGになった2点を修正:
 * - スリットbuff: JSON文字列比較ではなく course×metric の数値比較
 * - スリットplayer順: race_entry を独立基準として比較
 *
 * 本番ロジックは変更しない。読み取り検証のみ。
 *
 * Usage:
 *   php analysis/validate_virtual_entry_web_v2.php 20260715AMG08 126345
 */

require_once __DIR__ . '/../web/controllers/IndexController.php';

function failUsageV2(): never
{
    fwrite(STDERR, "Usage: php analysis/validate_virtual_entry_web_v2.php RACE_CODE VIRTUAL_ORDER\n");
    exit(2);
}

function validOrderV2(string $order): bool
{
    if (strlen($order) !== 6) return false;
    $d = str_split($order);
    sort($d);
    return $d === ['1','2','3','4','5','6'];
}

/** @return array{0:array<int,int>,1:array<int,int>,2:string} */
function mapsV2(string $order): array
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
function runControllerV2(string $raceCode, bool $simulate, string $virtualOrder): array
{
    $date8 = substr($raceCode, 0, 8);
    $date = substr($date8, 0, 4) . '-' . substr($date8, 4, 2) . '-' . substr($date8, 6, 2);

    $_POST = [];
    $_GET = [
        'date' => $date,
        'place' => substr($raceCode, 8, 3),
        'race' => substr($raceCode, 11, 2),
        'virtual_entry' => $virtualOrder,
    ];
    if ($simulate) $_GET['simulate_entry'] = '1';

    return (new IndexController())->handle();
}

function fEqV2(mixed $a, mixed $b, float $eps = 1.0e-9): bool
{
    if (is_numeric($a) && is_numeric($b)) {
        return abs((float)$a - (float)$b) <= $eps;
    }
    return $a === $b;
}

function courseIntV2(mixed $v): int
{
    if (is_int($v) || is_float($v) || (is_string($v) && ctype_digit($v))) return (int)$v;
    if (is_string($v) && preg_match('/^([1-6])C/', $v, $m)) return (int)$m[1];
    return 0;
}

/** @return array<int,string> */
function loadPlayerByBoatV2(string $raceCode): array
{
    $pdo = getPDO();
    $stmt = $pdo->prepare(
        'SELECT lane_number, player_id::text AS player_id '
        . 'FROM boat_race.race_entry WHERE race_code = :race_code ORDER BY lane_number'
    );
    $stmt->execute([':race_code' => $raceCode]);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $boat = (int)($row['lane_number'] ?? 0);
        if ($boat >= 1 && $boat <= 6) {
            $out[$boat] = trim((string)($row['player_id'] ?? ''));
        }
    }
    return $out;
}

$checks = [];
$notes = [];
function checkV2(string $label, bool $ok, string $detail = ''): void
{
    global $checks;
    $checks[] = compact('label', 'ok', 'detail');
}
function noteV2(string $text): void
{
    global $notes;
    $notes[] = $text;
}

if ($argc !== 3) failUsageV2();
$raceCode = strtoupper(trim((string)$argv[1]));
$virtualOrder = trim((string)$argv[2]);
if (!preg_match('/^\d{8}[A-Z]{3}\d{2}$/', $raceCode) || !validOrderV2($virtualOrder)) failUsageV2();

[$expectedCourseToBoat, $expectedBoatToCourse, $expectedLaneToCourse] = mapsV2($virtualOrder);

try {
    echo "通常モードを取得中...\n";
    $normal = runControllerV2($raceCode, false, '123456');
    $actualOrder = (string)($normal['exhibition_entry_order'] ?? '');
    if (!validOrderV2($actualOrder)) {
        throw new RuntimeException('展示進入6艇が取得できません');
    }

    echo "展示進入と同じ仮想モード({$actualOrder})を取得中...\n";
    $same = runControllerV2($raceCode, true, $actualOrder);

    echo "指定仮想モード({$virtualOrder})を取得中...\n";
    $virtual = runControllerV2($raceCode, true, $virtualOrder);

    $playerByBoat = loadPlayerByBoatV2($raceCode);
} catch (Throwable $e) {
    fwrite(STDERR, "検証実行エラー: {$e->getMessage()}\n");
    exit(1);
}

// A. 通常モード vs 展示進入と同一の仮想モード
checkV2(
    '同一進入: prediction_entry_order',
    ($normal['prediction_entry_order'] ?? '') === $actualOrder
        && ($same['prediction_entry_order'] ?? '') === $actualOrder,
    'normal=' . ($normal['prediction_entry_order'] ?? '-') . ' / virtual_same=' . ($same['prediction_entry_order'] ?? '-')
);

checkV2(
    '同一進入: 決まり手データ完全一致',
    ($normal['kimarite_data'] ?? []) == ($same['kimarite_data'] ?? [])
);

$finalKeys = [
    'course','rate6_dec','rate3_dec','kitai_dec',
    'flg_sashi','flg_makuri','flg_makurizashi','flg_nogashi',
    'type','typeBonus','final3','kiru','final_rank',
];
$ok = true; $diff = [];
for ($boat = 1; $boat <= 6; $boat++) {
    $a = $normal['final_predictions'][$boat] ?? [];
    $b = $same['final_predictions'][$boat] ?? [];
    foreach ($finalKeys as $key) {
        if (!fEqV2($a[$key] ?? null, $b[$key] ?? null, 1.0e-9)) {
            $ok = false; $diff[] = "{$boat}号艇 {$key}";
        }
    }
}
checkV2('同一進入: 最終予想主要項目一致', $ok, implode(', ', array_slice($diff, 0, 8)));

$summaryKeys = [
    'honmei_head','taikou_head','honmei_aite_str','taikou_aite_str','kiru_str',
    'honmei_aite_kako','taikou_aite_kako','honmei_kai','taikou_kai',
];
$ok = true; $diff = [];
foreach ($summaryKeys as $key) {
    if (($normal[$key] ?? null) !== ($same[$key] ?? null)) {
        $ok = false; $diff[] = $key;
    }
}
checkV2('同一進入: 本命/対抗/買い目一致', $ok, implode(', ', $diff));

$ok = true; $diff = [];
for ($boat = 1; $boat <= 6; $boat++) {
    $a = $normal['corrected_win_rate_data']['boats'][(string)$boat] ?? $normal['corrected_win_rate_data']['boats'][$boat] ?? [];
    $b = $same['corrected_win_rate_data']['boats'][(string)$boat] ?? $same['corrected_win_rate_data']['boats'][$boat] ?? [];
    foreach (['exhibition_course','remap_rate','ex_total_rate','sum_rate','slit_raw_buff','corrected_rate'] as $key) {
        if (!fEqV2($a[$key] ?? null, $b[$key] ?? null, 1.0e-7)) {
            $ok = false; $diff[] = "{$boat}号艇 {$key}";
        }
    }
}
checkV2('同一進入: 補正後1着率チェーン一致', $ok, implode(', ', array_slice($diff, 0, 8)));

$normalSam = []; $sameSam = [];
foreach (($normal['sam_applied_list'] ?? []) as $r) $normalSam[(int)($r['teiban'] ?? 0)] = $r;
foreach (($same['sam_applied_list'] ?? []) as $r) $sameSam[(int)($r['teiban'] ?? 0)] = $r;
$ok = true; $diff = [];
for ($boat = 1; $boat <= 6; $boat++) {
    $a = $normalSam[$boat] ?? []; $b = $sameSam[$boat] ?? [];
    foreach (['val_j','val_k','val_l','sum','avg_diff','win','place2','place3','trio'] as $key) {
        if (!fEqV2($a[$key] ?? null, $b[$key] ?? null)) {
            $ok = false; $diff[] = "{$boat}号艇 {$key}";
        }
    }
    if (courseIntV2($a['course'] ?? null) !== courseIntV2($b['course'] ?? null)) {
        $ok = false; $diff[] = "{$boat}号艇 course";
    }
}
checkV2('同一進入: SUM適用値一致', $ok, implode(', ', array_slice($diff, 0, 8)));

$normalPid = (int)($normal['slit_pattern']['id'] ?? 0);
$samePid = (int)($same['slit_pattern']['id'] ?? 0);
checkV2('同一進入: スリットPID一致', $normalPid > 0 && $normalPid === $samePid, "normal={$normalPid} / virtual_same={$samePid}");

// V2修正点1: 配列文字列ではなく、実際に使う数値を course×metric で比較。
$ok = true; $diff = [];
foreach (range(1, 6) as $course) {
    $a = $normal['slit_data'][(string)$course] ?? $normal['slit_data'][$course] ?? [];
    $b = $same['slit_data'][(string)$course] ?? $same['slit_data'][$course] ?? [];
    foreach (['win','place2','place3','trio'] as $metric) {
        if (!fEqV2($a[$metric] ?? null, $b[$metric] ?? null, 1.0e-12)) {
            $ok = false; $diff[] = "{$course}C {$metric}";
        }
    }
}
checkV2('同一進入: スリットbuff数値一致', $ok, implode(', ', $diff));

if ($actualOrder === '123456') {
    $ok = true;
    for ($boat = 1; $boat <= 6; $boat++) {
        if (!fEqV2(
            $normal['base_win_rate_data']['boats'][$boat]['normalized_rate'] ?? null,
            $same['base_win_rate_data']['boats'][$boat]['normalized_rate'] ?? null
        )) $ok = false;
    }
    checkV2('同一進入: 基本1着率一致（展示123456）', $ok);
} else {
    noteV2('基本1着率の通常表示は展示前の枠=コース基準。展示進入変更レースでは同一値比較対象外。');
}

// B. 指定仮想進入の全コンポーネント整合性
checkV2('仮想: simulation_active', !empty($virtual['simulation_active']) && empty($virtual['virtual_entry_error']));
checkV2('仮想: コース順艇番', ($virtual['prediction_entry_order'] ?? '') === $virtualOrder, "expected={$virtualOrder} / actual=" . ($virtual['prediction_entry_order'] ?? '-'));
checkV2('仮想: 内部艇番→コース6桁', ($virtual['effective_in_course'] ?? '') === $expectedLaneToCourse, "expected={$expectedLaneToCourse} / actual=" . ($virtual['effective_in_course'] ?? '-'));

$ok = true;
for ($boat = 1; $boat <= 6; $boat++) {
    if ((int)($virtual['prediction_course_by_boat'][$boat] ?? 0) !== $expectedBoatToCourse[$boat]) $ok = false;
}
for ($course = 1; $course <= 6; $course++) {
    if ((int)($virtual['prediction_boat_by_course'][$course] ?? 0) !== $expectedCourseToBoat[$course]) $ok = false;
}
checkV2('仮想: controller双方向map一致', $ok);

$ok = true; $total = 0.0;
for ($boat = 1; $boat <= 6; $boat++) {
    $r = $virtual['base_win_rate_data']['boats'][$boat] ?? [];
    if ((int)($r['course'] ?? 0) !== $expectedBoatToCourse[$boat]) $ok = false;
    $total += (float)($r['normalized_rate'] ?? 0.0);
}
checkV2('仮想: 基本1着率 boat→course一致', $ok);
checkV2('仮想: 基本1着率合計100%', abs($total - 100.0) < 1.0e-6, sprintf('%.8f%%', $total));

$ok = (($virtual['corrected_win_rate_data']['status'] ?? '') === 'ok'); $total = 0.0;
for ($boat = 1; $boat <= 6; $boat++) {
    $r = $virtual['corrected_win_rate_data']['boats'][(string)$boat] ?? $virtual['corrected_win_rate_data']['boats'][$boat] ?? [];
    if ((int)($r['exhibition_course'] ?? 0) !== $expectedBoatToCourse[$boat]) $ok = false;
    $total += (float)($r['corrected_rate'] ?? 0.0);
}
checkV2('仮想: 補正後1着率 boat→course一致', $ok, (string)($virtual['corrected_win_rate_data']['error'] ?? ''));
checkV2('仮想: 補正後1着率合計100%', abs($total - 100.0) < 1.0e-5, sprintf('%.8f%%', $total));

$ok = true;
for ($boat = 1; $boat <= 6; $boat++) {
    if ((int)($virtual['final_predictions'][$boat]['course'] ?? 0) !== $expectedBoatToCourse[$boat]) $ok = false;
}
checkV2('仮想: 最終予想 boat→course一致', $ok);

$tenjiByBoat = [];
foreach (($virtual['tenji_list'] ?? []) as $t) $tenjiByBoat[(int)($t['teiban'] ?? 0)] = $t;
$samRows = array_values($virtual['sam_applied_list'] ?? []);
$mapOk = count($samRows) === 6; $valueOk = $mapOk; $samOrder = '';
foreach ($samRows as $idx => $s) {
    $course = courseIntV2($s['course'] ?? null);
    $boat = (int)($s['teiban'] ?? 0);
    if ($boat < 1 || $boat > 6 || $course !== $idx + 1 || $course !== ($expectedBoatToCourse[$boat] ?? 0)) $mapOk = false;
    if ($course >= 1 && $course <= 6) $samOrder .= (string)$boat;
    $t = $tenjiByBoat[$boat] ?? [];
    foreach ([['val_j','tenji_J'],['val_k','tenji_K'],['val_l','tenji_L']] as [$sk,$tk]) {
        $tv = is_numeric($t[$tk] ?? null) ? (float)$t[$tk] : 0.0;
        if (!fEqV2($s[$sk] ?? null, $tv)) $valueOk = false;
    }
}
checkV2('仮想: SUMコース順/艇番一致', $mapOk && $samOrder === $virtualOrder, "order={$samOrder}");
checkV2('仮想: SUM展示値が実艇に追従', $valueOk);

// V2修正点2: race_entry を独立基準にする。
$slitPlayers = $virtual['slit_pattern']['predict_detail']['player_ids'] ?? [];
$ok = count($slitPlayers) === 6 && count($playerByBoat) === 6;
$diff = [];
for ($course = 1; $course <= 6; $course++) {
    $boat = $expectedCourseToBoat[$course];
    $expected = $playerByBoat[$boat] ?? '';
    $actual = trim((string)($slitPlayers[$course - 1] ?? ''));
    if ($expected === '' || $expected !== $actual) {
        $ok = false; $diff[] = "{$course}C={$boat}号艇 expected={$expected} actual={$actual}";
    }
}
checkV2('仮想: スリット選手が仮想コース順', $ok, implode(' / ', $diff));

$screenPid = (int)($virtual['slit_pattern']['id'] ?? 0);
$correctedPid = (int)($virtual['corrected_win_rate_data']['method']['slit_pattern_id'] ?? 0);
checkV2('仮想: スリット表示PID = 補正後1着率PID', $screenPid > 0 && $screenPid === $correctedPid, "screen={$screenPid} / corrected={$correctedPid}");

$errorFields = [
    '出走表' => (string)($virtual['api_error'] ?? ''),
    '決まり手' => (string)($virtual['kimarite_error'] ?? ''),
    '展示' => (string)($virtual['tenji_error'] ?? ''),
    'SUM' => (string)($virtual['sam_error'] ?? ''),
];
$ok = true; $diff = [];
foreach ($errorFields as $name => $value) {
    if ($value !== '') { $ok = false; $diff[] = "{$name}:{$value}"; }
}
checkV2('仮想: API/展示/SUMエラーなし', $ok, implode(' / ', $diff));

// 出力
$okCount = count(array_filter($checks, static fn(array $x): bool => $x['ok']));
$ngCount = count($checks) - $okCount;
$line = str_repeat('=', 118);

echo "\n{$line}\n";
echo "仮想進入Web エンドツーエンド整合性検証 V2\n";
echo "{$line}\n";
echo "Race              : {$raceCode}\n";
echo "展示進入          : {$actualOrder}\n";
echo "試算進入          : {$virtualOrder}\n";
echo "内部boat→course   : {$expectedLaneToCourse}\n";
echo "\n【A. 通常モード ↔ 展示進入と同じ仮想モード】\n";

$bStarted = false;
foreach ($checks as $c) {
    if (!$bStarted && str_starts_with($c['label'], '仮想:')) {
        $bStarted = true;
        echo "\n【B. 指定仮想進入の全コンポーネント整合性】\n";
    }
    printf("%-54s : %s", $c['label'], $c['ok'] ? 'OK' : 'NG');
    if ($c['detail'] !== '') echo '  ' . $c['detail'];
    echo "\n";
}

if ($notes) {
    echo "\n【注記】\n";
    foreach ($notes as $n) echo "- {$n}\n";
}

echo "\n【最終判定】\n";
echo "OK={$okCount} / NG={$ngCount}\n";
echo $ngCount === 0
    ? "仮想進入の整合性 : ALL OK\n判定               : 単一レースE2E検証 合格\n"
    : "仮想進入の整合性 : NGあり\n判定               : NG項目の追加診断が必要\n";
echo "{$line}\n";

exit($ngCount === 0 ? 0 : 1);
