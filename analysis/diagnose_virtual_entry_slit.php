<?php

declare(strict_types=1);

/**
 * 仮想進入Web検証で残ったスリット2項目の切り分け専用。
 * 本番ロジックは変更しない。
 *
 * Usage:
 *   php analysis/diagnose_virtual_entry_slit.php 20260715AMG08 126345
 */

require_once __DIR__ . '/../web/controllers/IndexController.php';

function validOrder(string $order): bool
{
    if (strlen($order) !== 6) return false;
    $d = str_split($order);
    sort($d);
    return $d === ['1', '2', '3', '4', '5', '6'];
}

function runControllerForDiag(string $raceCode, bool $simulate, string $virtualOrder): array
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
    if ($simulate) $_GET['simulate_entry'] = '1';

    return (new IndexController())->handle();
}

function mapCourseToBoat(string $order): array
{
    $out = [];
    for ($course = 1; $course <= 6; $course++) {
        $out[$course] = (int)$order[$course - 1];
    }
    return $out;
}

function compareBuff(array $a, array $b): array
{
    $diffs = [];
    $metrics = ['win', 'place2', 'place3', 'trio'];

    for ($course = 1; $course <= 6; $course++) {
        $aa = $a[$course] ?? $a[(string)$course] ?? [];
        $bb = $b[$course] ?? $b[(string)$course] ?? [];

        foreach ($metrics as $metric) {
            $av = $aa[$metric] ?? null;
            $bv = $bb[$metric] ?? null;

            if (!is_numeric($av) || !is_numeric($bv)) {
                if ($av !== $bv) {
                    $diffs[] = "{$course}C {$metric}: " . var_export($av, true) . ' != ' . var_export($bv, true);
                }
                continue;
            }

            if (abs((float)$av - (float)$bv) > 1.0e-12) {
                $diffs[] = sprintf('%dC %s: %.15f != %.15f', $course, $metric, (float)$av, (float)$bv);
            }
        }
    }

    return $diffs;
}

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php analysis/diagnose_virtual_entry_slit.php RACE_CODE VIRTUAL_ORDER\n");
    exit(2);
}

$raceCode = strtoupper(trim((string)$argv[1]));
$virtualOrder = trim((string)$argv[2]);
if (!preg_match('/^\d{8}[A-Z]{3}\d{2}$/', $raceCode) || !validOrder($virtualOrder)) {
    fwrite(STDERR, "引数が不正です\n");
    exit(2);
}

echo "通常モード取得...\n";
$normal = runControllerForDiag($raceCode, false, '123456');
$actualOrder = (string)($normal['exhibition_entry_order'] ?? '');
if (!validOrder($actualOrder)) {
    fwrite(STDERR, "展示進入を取得できません: {$actualOrder}\n");
    exit(1);
}

echo "同一進入仮想({$actualOrder})取得...\n";
$same = runControllerForDiag($raceCode, true, $actualOrder);

echo "指定仮想({$virtualOrder})取得...\n";
$virtual = runControllerForDiag($raceCode, true, $virtualOrder);

$line = str_repeat('=', 110);
echo "\n{$line}\n";
echo "仮想進入 スリットNG切り分け\n";
echo "{$line}\n";
echo "Race       : {$raceCode}\n";
echo "展示進入   : {$actualOrder}\n";
echo "試算進入   : {$virtualOrder}\n\n";

$normalPid = (int)($normal['slit_pattern']['id'] ?? 0);
$samePid = (int)($same['slit_pattern']['id'] ?? 0);
$virtualPid = (int)($virtual['slit_pattern']['id'] ?? 0);
$correctedPid = (int)($virtual['corrected_win_rate_data']['method']['slit_pattern_id'] ?? 0);

echo "【1. 同一進入 buff】\n";
echo "PID normal={$normalPid} / same={$samePid}\n";
$buffDiffs = compareBuff((array)($normal['slit_data'] ?? []), (array)($same['slit_data'] ?? []));
if (!$buffDiffs) {
    echo "数値比較 : ALL OK\n";
    echo "判定     : 元validatorのJSON文字列比較による偽NGの可能性が高い\n";
} else {
    echo "数値比較 : NG " . count($buffDiffs) . "件\n";
    foreach (array_slice($buffDiffs, 0, 24) as $d) echo "  - {$d}\n";
}

echo "normal keys: " . implode(',', array_map('strval', array_keys((array)($normal['slit_data'] ?? [])))) . "\n";
echo "same keys  : " . implode(',', array_map('strval', array_keys((array)($same['slit_data'] ?? [])))) . "\n\n";

echo "【2. 仮想コース順 player_id】\n";
$courseToBoat = mapCourseToBoat($virtualOrder);

$playerByBoat = [];
try {
    $pdo = getPDO();
    $stmt = $pdo->prepare(
        'SELECT lane_number, player_id::text AS player_id '
        . 'FROM boat_race.race_entry WHERE race_code = :race_code ORDER BY lane_number'
    );
    $stmt->execute([':race_code' => $raceCode]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $boat = (int)($row['lane_number'] ?? 0);
        if ($boat >= 1 && $boat <= 6) {
            $playerByBoat[$boat] = trim((string)($row['player_id'] ?? ''));
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, "race_entry取得エラー: {$e->getMessage()}\n");
    exit(1);
}

$slitPlayers = $virtual['slit_pattern']['predict_detail']['player_ids'] ?? [];
$playerOk = count($playerByBoat) === 6 && count($slitPlayers) === 6;
for ($course = 1; $course <= 6; $course++) {
    $boat = $courseToBoat[$course];
    $expected = $playerByBoat[$boat] ?? '';
    $actual = trim((string)($slitPlayers[$course - 1] ?? ''));
    $ok = $expected !== '' && $actual === $expected;
    if (!$ok) $playerOk = false;
    printf(
        "%dC=%d号艇 expected=%-6s actual=%-6s : %s\n",
        $course,
        $boat,
        $expected !== '' ? $expected : '-',
        $actual !== '' ? $actual : '-',
        $ok ? 'OK' : 'NG'
    );
}

echo "player順 : " . ($playerOk ? 'ALL OK' : 'NGあり') . "\n\n";

echo "【3. PID連携】\n";
echo "画面スリット PID     : {$virtualPid}\n";
echo "補正後1着率内 PID    : {$correctedPid}\n";
echo "一致                  : " . ($virtualPid > 0 && $virtualPid === $correctedPid ? 'OK' : 'NG') . "\n";
echo "{$line}\n";

exit((!$buffDiffs && $playerOk && $virtualPid > 0 && $virtualPid === $correctedPid) ? 0 : 1);
