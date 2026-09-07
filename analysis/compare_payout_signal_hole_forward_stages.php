<?php

declare(strict_types=1);

/**
 * 前方保存した「暫定」と「展示反映済」の穴目予想を結果非参照で比較する。
 *
 * 比較:
 * - 穴本命A / 穴対抗B の頭変化
 * - AI3 A-B差の変化
 * - GAP5_INNER（B寄り参考）のON/OFF変化
 * - A/B 9点候補の共通・追加・削除点数
 *
 * race_result_detail / race_payouts は参照しない。
 *
 * Usage:
 *   php analysis/compare_payout_signal_hole_forward_stages.php 2026-09-08
 */

date_default_timezone_set('Asia/Tokyo');

function failHoleStageCompare(string $message, int $code = 1): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($code);
}

function loadJsonHoleStageCompare(string $path): ?array
{
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

/** @return array<int,string> */
function betsHoleStageCompare(array $record, string $side): array
{
    $bets = $record[$side]['bets'] ?? [];
    if (!is_array($bets)) return [];
    $out = [];
    foreach ($bets as $bet) {
        if (is_string($bet) && preg_match('/^[1-6]-[1-6]-[1-6]$/', $bet)) $out[] = $bet;
    }
    return array_values(array_unique($out));
}

function setDiffHoleStageCompare(array $a, array $b): array
{
    return array_values(array_diff($a, $b));
}

function overlapHoleStageCompare(array $a, array $b): int
{
    return count(array_intersect($a, $b));
}

$dateText = trim((string)($argv[1] ?? ''));
$dt = DateTimeImmutable::createFromFormat('!Y-m-d', $dateText);
if ($dt === false || $dt->format('Y-m-d') !== $dateText) {
    failHoleStageCompare('Usage: php analysis/compare_payout_signal_hole_forward_stages.php YYYY-MM-DD');
}

$ymd = $dt->format('Ymd');
$dir = __DIR__ . '/output/hole_forward/' . $ymd;
if (!is_dir($dir)) failHoleStageCompare('前方スナップショット保存先がありません: ' . $dir);

$provFiles = glob($dir . '/*_provisional.json') ?: [];
$exFiles = glob($dir . '/*_exhibition.json') ?: [];

$prov = [];
foreach ($provFiles as $path) {
    $r = loadJsonHoleStageCompare($path);
    $code = is_array($r) ? (string)($r['race_code'] ?? '') : '';
    if ($code !== '') $prov[$code] = ['path'=>$path, 'data'=>$r];
}

$ex = [];
foreach ($exFiles as $path) {
    $r = loadJsonHoleStageCompare($path);
    $code = is_array($r) ? (string)($r['race_code'] ?? '') : '';
    if ($code !== '') $ex[$code] = ['path'=>$path, 'data'=>$r];
}

$codes = array_values(array_intersect(array_keys($prov), array_keys($ex)));
sort($codes, SORT_STRING);

$rows = [];
$headAChanged = 0;
$headBChanged = 0;
$anyHeadChanged = 0;
$bLeanChanged = 0;
$aBetChanged = 0;
$bBetChanged = 0;

foreach ($codes as $code) {
    $p = $prov[$code]['data'];
    $e = $ex[$code]['data'];

    $pA = (int)($p['A']['boat'] ?? 0);
    $eA = (int)($e['A']['boat'] ?? 0);
    $pB = (int)($p['B']['boat'] ?? 0);
    $eB = (int)($e['B']['boat'] ?? 0);
    $aChanged = $pA !== $eA;
    $bChanged = $pB !== $eB;
    if ($aChanged) $headAChanged++;
    if ($bChanged) $headBChanged++;
    if ($aChanged || $bChanged) $anyHeadChanged++;

    $pLean = (bool)($p['b_swap_reference']['matched'] ?? false);
    $eLean = (bool)($e['b_swap_reference']['matched'] ?? false);
    if ($pLean !== $eLean) $bLeanChanged++;

    $pABets = betsHoleStageCompare($p, 'A');
    $eABets = betsHoleStageCompare($e, 'A');
    $pBBets = betsHoleStageCompare($p, 'B');
    $eBBets = betsHoleStageCompare($e, 'B');

    $aAdd = setDiffHoleStageCompare($eABets, $pABets);
    $aDrop = setDiffHoleStageCompare($pABets, $eABets);
    $bAdd = setDiffHoleStageCompare($eBBets, $pBBets);
    $bDrop = setDiffHoleStageCompare($pBBets, $eBBets);
    if ($aAdd !== [] || $aDrop !== []) $aBetChanged++;
    if ($bAdd !== [] || $bDrop !== []) $bBetChanged++;

    $rows[] = [
        'deadline' => substr((string)($e['deadline_at'] ?? $p['deadline_at'] ?? ''), 11, 5),
        'place' => (string)($p['place'] ?? $e['place'] ?? ''),
        'race_no' => (int)($p['race_no'] ?? $e['race_no'] ?? 0),
        'race_code' => $code,
        'p_a' => $pA,
        'e_a' => $eA,
        'p_b' => $pB,
        'e_b' => $eB,
        'p_gap' => (float)($p['ai3_gap_a_minus_b'] ?? 0.0),
        'e_gap' => (float)($e['ai3_gap_a_minus_b'] ?? 0.0),
        'p_lean' => $pLean,
        'e_lean' => $eLean,
        'p_a_points' => count($pABets),
        'e_a_points' => count($eABets),
        'a_common' => overlapHoleStageCompare($pABets, $eABets),
        'a_add' => $aAdd,
        'a_drop' => $aDrop,
        'p_b_points' => count($pBBets),
        'e_b_points' => count($eBBets),
        'b_common' => overlapHoleStageCompare($pBBets, $eBBets),
        'b_add' => $bAdd,
        'b_drop' => $bDrop,
    ];
}

usort($rows, static function (array $a, array $b): int {
    $ta = $a['deadline'] !== '' ? $a['deadline'] : '99:99';
    $tb = $b['deadline'] !== '' ? $b['deadline'] : '99:99';
    $cmp = strcmp($ta, $tb);
    if ($cmp !== 0) return $cmp;
    $cmp = strcmp((string)$a['place'], (string)$b['place']);
    if ($cmp !== 0) return $cmp;
    return (int)$a['race_no'] <=> (int)$b['race_no'];
});

$line = str_repeat('=', 146);
echo $line . PHP_EOL;
echo '穴目予想 前方スナップショット：暫定 vs 展示反映済（結果非参照）' . PHP_EOL;
echo '対象日     : ' . $dateText . PHP_EOL;
echo '暫定保存   : ' . count($prov) . 'R' . PHP_EOL;
echo '展示保存   : ' . count($ex) . 'R' . PHP_EOL;
echo '比較可能   : ' . count($rows) . 'R' . PHP_EOL;
echo $line . PHP_EOL;

printf("%-5s %-7s %3s  %-13s %-13s %-15s %-18s %-18s\n",
    '時刻', '場', 'R', 'A 暫定→展示', 'B 暫定→展示', 'AI3差 暫定→展示', 'A買い目 共/+/−', 'B買い目 共/+/−');
echo str_repeat('-', 146) . PHP_EOL;

foreach ($rows as $r) {
    $leanMark = $r['p_lean'] === $r['e_lean']
        ? ($r['e_lean'] ? ' ★B' : '')
        : (' B寄り:' . ($r['p_lean'] ? 'ON' : 'OFF') . '→' . ($r['e_lean'] ? 'ON' : 'OFF'));

    printf(
        "%-5s %-7s %2dR  %d→%d          %d→%d          %6.2f→%-6.2f   %2d/%2d/%-2d          %2d/%2d/%-2d%s\n",
        $r['deadline'] !== '' ? $r['deadline'] : '--:--',
        $r['place'],
        $r['race_no'],
        $r['p_a'], $r['e_a'],
        $r['p_b'], $r['e_b'],
        $r['p_gap'], $r['e_gap'],
        $r['a_common'], count($r['a_add']), count($r['a_drop']),
        $r['b_common'], count($r['b_add']), count($r['b_drop']),
        $leanMark
    );
}

if ($rows === []) echo "比較できる暫定/展示ペアはまだありません。\n";

echo PHP_EOL . '【変化集計】' . PHP_EOL;
echo 'A頭変化       : ' . $headAChanged . 'R / ' . count($rows) . 'R' . PHP_EOL;
echo 'B頭変化       : ' . $headBChanged . 'R / ' . count($rows) . 'R' . PHP_EOL;
echo 'A/Bどちらか変化: ' . $anyHeadChanged . 'R / ' . count($rows) . 'R' . PHP_EOL;
echo 'B寄り参考変化 : ' . $bLeanChanged . 'R / ' . count($rows) . 'R' . PHP_EOL;
echo 'A買い目変化   : ' . $aBetChanged . 'R / ' . count($rows) . 'R' . PHP_EOL;
echo 'B買い目変化   : ' . $bBetChanged . 'R / ' . count($rows) . 'R' . PHP_EOL;
echo $line . PHP_EOL;
echo '※これは予想同士の差分だけです。結果・払戻は一切参照していません。' . PHP_EOL;
echo $line . PHP_EOL;
