<?php

declare(strict_types=1);

/**
 * 24場すべてについて、Web本命①時の既存攻め条件
 * A3=3C攻め>=15 / A4=4C攻め>=20 / ANY=A3 or A4
 * が OLD6M / RECENT6M で①敗率を同方向に動かすかを確認する。
 *
 * ここでは条件追加・閾値探索・本番補正はしない。
 *
 * Usage:
 * php analysis/analyze_stadium_attack_signal_reproducibility_all.php \
 *   analysis/output/kimarite_analysis_dataset_20250815_20260814.csv
 */

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php {$argv[0]} DATASET_CSV\n");
    exit(1);
}

$path = $argv[1];
if (!is_file($path)) {
    throw new RuntimeException("CSVがありません: {$path}");
}

const OLD_START = '2025-08-15';
const OLD_END = '2026-02-14';
const RECENT_START = '2026-02-15';
const RECENT_END = '2026-08-14';

function readCsvAssoc(string $path): array
{
    $fp = fopen($path, 'rb');
    if ($fp === false) throw new RuntimeException("CSVを開けません: {$path}");
    $header = fgetcsv($fp);
    if ($header === false) { fclose($fp); return []; }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    $rows = [];
    while (($cols = fgetcsv($fp)) !== false) {
        if (count($cols) !== count($header)) continue;
        $rows[] = array_combine($header, $cols);
    }
    fclose($fp);
    return $rows;
}

function inum(array $row, string $key, int $default = 0): int
{
    $v = $row[$key] ?? null;
    return is_numeric($v) ? (int)$v : $default;
}

function fnum(array $row, string $key, float $default = 0.0): float
{
    $v = $row[$key] ?? null;
    return is_numeric($v) ? (float)$v : $default;
}

function pct(int $n, int $d): float
{
    return $d > 0 ? 100.0 * $n / $d : 0.0;
}

function formal(array $row): bool
{
    return inum($row, 'result_top3_course_complete') === 1
        && inum($row, 'result_boat_match') === 1;
}

function attack(array $row, int $course): float
{
    return fnum($row, "c{$course}_6m_makuri") + fnum($row, "c{$course}_6m_makurizashi");
}

function a3(array $row): bool
{
    return inum($row, 'c3_6m_sample_n') >= 10 && attack($row, 3) >= 15.0;
}

function a4(array $row): bool
{
    return inum($row, 'c4_6m_sample_n') >= 10 && attack($row, 4) >= 20.0;
}

function periodOf(string $date): ?string
{
    if ($date >= OLD_START && $date <= OLD_END) return 'OLD6M';
    if ($date >= RECENT_START && $date <= RECENT_END) return 'RECENT6M';
    return null;
}

function emptyStat(): array
{
    return [
        'base_n'=>0,
        'base_miss'=>0,
        'A3'=>['n'=>0,'miss'=>0],
        'A4'=>['n'=>0,'miss'=>0],
        'ANY'=>['n'=>0,'miss'=>0],
    ];
}

function diffPt(array $s, string $sig): float
{
    $base = pct($s['base_miss'], $s['base_n']);
    $cur = pct($s[$sig]['miss'], $s[$sig]['n']);
    return $cur - $base;
}

function dirMark(float $old, float $recent): string
{
    $eps = 0.00001;
    $a = $old > $eps ? '+' : ($old < -$eps ? '-' : '0');
    $b = $recent > $eps ? '+' : ($recent < -$eps ? '-' : '0');
    return $a . $b;
}

$rows = readCsvAssoc($path);
$stats = [];
$formalN = 0;
$web1N = 0;

foreach ($rows as $row) {
    if (!formal($row)) continue;
    $formalN++;
    if (inum($row, 'honmei_head') !== 1) continue;
    $web1N++;

    $date = trim((string)($row['race_date'] ?? ''));
    $period = periodOf($date);
    if ($period === null) continue;

    $venue = trim((string)($row['stadium_name'] ?? ''));
    if ($venue === '') continue;

    if (!isset($stats[$venue])) {
        $stats[$venue] = ['OLD6M'=>emptyStat(), 'RECENT6M'=>emptyStat()];
    }

    $miss = inum($row, 'actual_1st') !== 1;
    $sigA3 = a3($row);
    $sigA4 = a4($row);
    $sigAny = $sigA3 || $sigA4;

    $s =& $stats[$venue][$period];
    $s['base_n']++;
    $s['base_miss'] += (int)$miss;

    foreach ([
        'A3'=>$sigA3,
        'A4'=>$sigA4,
        'ANY'=>$sigAny,
    ] as $name => $on) {
        if (!$on) continue;
        $s[$name]['n']++;
        $s[$name]['miss'] += (int)$miss;
    }
    unset($s);
}

$rowsOut = [];
foreach ($stats as $venue => $p) {
    $row = ['venue'=>$venue, 'OLD6M'=>$p['OLD6M'], 'RECENT6M'=>$p['RECENT6M']];
    foreach (['A3','A4','ANY'] as $sig) {
        $old = diffPt($p['OLD6M'], $sig);
        $recent = diffPt($p['RECENT6M'], $sig);
        $row[$sig] = [
            'old_diff'=>$old,
            'recent_diff'=>$recent,
            'mark'=>dirMark($old, $recent),
            'strength'=>min($old, $recent),
        ];
    }
    $rowsOut[] = $row;
}

usort($rowsOut, static function(array $a, array $b): int {
    $sa = max($a['A3']['strength'], $a['A4']['strength'], $a['ANY']['strength']);
    $sb = max($b['A3']['strength'], $b['A4']['strength'], $b['ANY']['strength']);
    return $sb <=> $sa;
});

echo str_repeat('=', 180) . "\n";
echo "24場 Web本命① × 既存攻め条件 再現性一覧\n";
echo str_repeat('=', 180) . "\n";
echo "正式対象={$formalN}R / Web本命①={$web1N}R\n";
echo "固定条件: A3=3C攻め>=15(sample>=10), A4=4C攻め>=20(sample>=10), ANY=A3またはA4\n";
echo "記号: ++=両期間で①敗率上昇 / --=両期間で低下 / +-/ -+=方向反転。閾値探索はしない。\n\n";

printf(
    "%-8s | %-21s | %-27s | %-27s | %-27s\n",
    '場', 'BASE OLD/RECENT', 'A3 OLD/RECENT', 'A4 OLD/RECENT', 'ANY OLD/RECENT'
);
echo str_repeat('-', 180) . "\n";

foreach ($rowsOut as $r) {
    $o = $r['OLD6M'];
    $n = $r['RECENT6M'];
    $baseOld = pct($o['base_miss'], $o['base_n']);
    $baseNew = pct($n['base_miss'], $n['base_n']);

    $parts = [];
    foreach (['A3','A4','ANY'] as $sig) {
        $parts[$sig] = sprintf(
            "%s %3d/%3d %+.1f/%+.1f",
            $r[$sig]['mark'],
            $o[$sig]['n'], $n[$sig]['n'],
            $r[$sig]['old_diff'], $r[$sig]['recent_diff']
        );
    }

    printf(
        "%-8s | %4d/%4d %5.1f/%5.1f | %-27s | %-27s | %-27s\n",
        $r['venue'],
        $o['base_n'], $n['base_n'], $baseOld, $baseNew,
        $parts['A3'], $parts['A4'], $parts['ANY']
    );
}

echo "\n見る点:\n";
echo "1. ++ の場だけが『場×既存攻め条件』として再現性候補。\n";
echo "2. +- / -+ は場補正候補にしない。特に戸田/江戸川のような反転は要注意。\n";
echo "3. -- はその条件がその場ではイン危険信号になっていない可能性。\n";
echo "4. Nが少ない行は方向一致でも結論を急がない。\n";
echo "5. この一覧から新しい閾値を探索しない。\n";
