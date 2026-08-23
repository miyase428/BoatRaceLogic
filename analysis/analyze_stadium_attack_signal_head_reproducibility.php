<?php

declare(strict_types=1);

/**
 * 場別 Web本命① × 既存攻め条件の「イン危険」と「候補頭」再現性を同時診断する。
 *
 * 固定条件（閾値探索はしない）:
 *   A3 = 3C 6か月まくり+まくり差し >= 15%, sample_n >= 10
 *   A4 = 4C 6か月まくり+まくり差し >= 20%, sample_n >= 10
 *
 * 見るもの:
 * - 条件時の①敗率が、その場・その期間のWeb本命①基礎敗率より上がるか
 * - A3なら3C、A4なら4Cの1着率が、その場・その期間の基礎1着率より上がるか
 * - OLD6M / RECENT6M の両方で方向が揃うか
 *
 * 注意:
 * - 条件・閾値は既存のA3/A4を固定。結果を見て追加探索しない。
 * - actual_* は評価ラベルとしてのみ使用。
 * - ここでは本番補正・買い目変更を行わない。
 *
 * Usage:
 * php analysis/analyze_stadium_attack_signal_head_reproducibility.php \
 *   analysis/output/kimarite_analysis_dataset_20250815_20260814.csv
 */

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php {$argv[0]} DATASET_CSV\n");
    exit(1);
}

$datasetPath = $argv[1];
if (!is_file($datasetPath)) {
    throw new RuntimeException("CSVがありません: {$datasetPath}");
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
    if ($header === false) {
        fclose($fp);
        return [];
    }
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

function sampleOk(array $row, int $course): bool
{
    return inum($row, "c{$course}_6m_sample_n") >= 10;
}

function signalHit(array $row, string $signal): bool
{
    return match ($signal) {
        'A3' => sampleOk($row, 3) && attack($row, 3) >= 15.0,
        'A4' => sampleOk($row, 4) && attack($row, 4) >= 20.0,
        default => false,
    };
}

function periodOf(string $date): ?string
{
    if ($date >= OLD_START && $date <= OLD_END) return 'OLD6M';
    if ($date >= RECENT_START && $date <= RECENT_END) return 'RECENT6M';
    return null;
}

function emptyPeriod(): array
{
    return [
        'base_n' => 0,
        'base_loss' => 0,
        'base_head3' => 0,
        'base_head4' => 0,
        'A3' => ['n'=>0, 'loss'=>0, 'head'=>0],
        'A4' => ['n'=>0, 'loss'=>0, 'head'=>0],
    ];
}

function signPair(float $old, float $recent): string
{
    $a = $old > 0 ? '+' : ($old < 0 ? '-' : '0');
    $b = $recent > 0 ? '+' : ($recent < 0 ? '-' : '0');
    return $a . $b;
}

function signalStats(array $period, string $signal): array
{
    $course = $signal === 'A3' ? 3 : 4;
    $baseHeadKey = $course === 3 ? 'base_head3' : 'base_head4';
    $baseN = (int)$period['base_n'];
    $sig = $period[$signal];
    $n = (int)$sig['n'];

    $baseLossRate = pct((int)$period['base_loss'], $baseN);
    $condLossRate = pct((int)$sig['loss'], $n);
    $baseHeadRate = pct((int)$period[$baseHeadKey], $baseN);
    $condHeadRate = pct((int)$sig['head'], $n);

    return [
        'n' => $n,
        'base_loss' => $baseLossRate,
        'cond_loss' => $condLossRate,
        'loss_delta' => $condLossRate - $baseLossRate,
        'base_head' => $baseHeadRate,
        'cond_head' => $condHeadRate,
        'head_delta' => $condHeadRate - $baseHeadRate,
    ];
}

$rows = readCsvAssoc($datasetPath);
$stats = [];
$formalN = 0;
$web1N = 0;

foreach ($rows as $row) {
    if (!formal($row)) continue;
    $formalN++;

    $date = trim((string)($row['race_date'] ?? ''));
    $period = periodOf($date);
    if ($period === null) continue;

    if (inum($row, 'honmei_head') !== 1) continue;
    $web1N++;

    $venue = trim((string)($row['stadium_name'] ?? ''));
    if ($venue === '') continue;
    if (!isset($stats[$venue])) {
        $stats[$venue] = [
            'OLD6M' => emptyPeriod(),
            'RECENT6M' => emptyPeriod(),
        ];
    }

    $winnerCourse = inum($row, 'actual_1st_course');
    $oneLost = inum($row, 'actual_1st') !== 1;

    $stats[$venue][$period]['base_n']++;
    $stats[$venue][$period]['base_loss'] += (int)$oneLost;
    $stats[$venue][$period]['base_head3'] += (int)($winnerCourse === 3);
    $stats[$venue][$period]['base_head4'] += (int)($winnerCourse === 4);

    foreach (['A3','A4'] as $signal) {
        if (!signalHit($row, $signal)) continue;
        $course = $signal === 'A3' ? 3 : 4;
        $stats[$venue][$period][$signal]['n']++;
        $stats[$venue][$period][$signal]['loss'] += (int)$oneLost;
        $stats[$venue][$period][$signal]['head'] += (int)($winnerCourse === $course);
    }
}

ksort($stats, SORT_NATURAL);

$rowsOut = [];
foreach ($stats as $venue => $periods) {
    foreach (['A3','A4'] as $signal) {
        $old = signalStats($periods['OLD6M'], $signal);
        $recent = signalStats($periods['RECENT6M'], $signal);
        $risk = signPair($old['loss_delta'], $recent['loss_delta']);
        $head = signPair($old['head_delta'], $recent['head_delta']);
        $rowsOut[] = [
            'venue'=>$venue,
            'signal'=>$signal,
            'old'=>$old,
            'recent'=>$recent,
            'risk'=>$risk,
            'head'=>$head,
            'both'=>($risk === '++' && $head === '++') ? '◎' : (($risk === '++') ? '危険のみ' : (($head === '++') ? '頭のみ' : '-')),
        ];
    }
}

usort($rowsOut, static function(array $a, array $b): int {
    $score = static function(array $r): int {
        if ($r['both'] === '◎') return 3;
        if ($r['both'] === '危険のみ') return 2;
        if ($r['both'] === '頭のみ') return 1;
        return 0;
    };
    $sa = $score($a);
    $sb = $score($b);
    if ($sa !== $sb) return $sb <=> $sa;
    if ($a['signal'] !== $b['signal']) return strcmp($a['signal'], $b['signal']);
    return strcmp($a['venue'], $b['venue']);
});

echo str_repeat('=', 196) . "\n";
echo "24場 Web本命① × 既存攻め条件 『イン危険＋候補頭』再現性\n";
echo str_repeat('=', 196) . "\n";
echo "正式対象={$formalN}R / Web本命①={$web1N}R\n";
echo "固定条件: A3=3C攻め>=15(sample>=10), A4=4C攻め>=20(sample>=10)\n";
echo "危険記号=条件時①敗率ΔのOLD/RECENT方向、頭記号=候補コース1着率ΔのOLD/RECENT方向。◎=両方++。\n\n";
printf("%-8s %-3s %-8s %-8s | %-48s | %-48s\n",
    '場','条件','危険','候補頭','OLD6M','RECENT6M');
echo str_repeat('-', 196) . "\n";

foreach ($rowsOut as $r) {
    $o = $r['old'];
    $n = $r['recent'];
    $oldText = sprintf(
        'N=%3d ①敗Δ=%+5.1fpt 頭 %.1f→%.1f(%+5.1f)',
        $o['n'], $o['loss_delta'], $o['base_head'], $o['cond_head'], $o['head_delta']
    );
    $recentText = sprintf(
        'N=%3d ①敗Δ=%+5.1fpt 頭 %.1f→%.1f(%+5.1f)',
        $n['n'], $n['loss_delta'], $n['base_head'], $n['cond_head'], $n['head_delta']
    );
    printf("%-8s %-3s %-8s %-8s | %-48s | %-48s\n",
        $r['venue'], $r['signal'], $r['risk'], $r['head'], $oldText, $recentText);
}

echo "\n◎候補（イン危険も候補頭も両期間で上昇）:\n";
$found = false;
foreach ($rowsOut as $r) {
    if ($r['both'] !== '◎') continue;
    $found = true;
    printf("  %s %s  OLD N=%d / RECENT N=%d\n",
        $r['venue'], $r['signal'], $r['old']['n'], $r['recent']['n']);
}
if (!$found) echo "  なし\n";

echo "\n判断ポイント:\n";
echo "1. 危険++だけでは頭候補にはしない。候補頭++まで揃うかを見る。\n";
echo "2. ◎でもNが小さい条件は保留。ここでは新しいN閾値や率閾値を作らない。\n";
echo "3. 方向反転は場×展開条件として固定しない。\n";
echo "4. ここでも本番ロジックは変更しない。\n";
