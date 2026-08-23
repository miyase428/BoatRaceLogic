<?php

declare(strict_types=1);

/**
 * 場×既存攻め条件 12候補の前方ホールドアウト検証。
 *
 * 1年分析で固定した候補を、2026-08-15以降など未使用期間へそのまま適用する。
 * この結果を見て対象場・A3/A4閾値を変更しない。
 *
 * 固定条件:
 *   A3 = 3C攻め(まくり+まくり差し) >= 15, sample_n >= 10
 *   A4 = 4C攻め(まくり+まくり差し) >= 20, sample_n >= 10
 *
 * Usage:
 * php analysis/validate_stadium_attack_signal_forward_holdout.php \
 *   analysis/output/kimarite_analysis_dataset_20260815_20260822.csv
 */

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php {$argv[0]} DATASET_CSV\n");
    exit(1);
}

$path = $argv[1];
if (!is_file($path)) {
    throw new RuntimeException("CSVがありません: {$path}");
}

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

function inum(array $r, string $k, int $d = 0): int
{
    $v = $r[$k] ?? null;
    return is_numeric($v) ? (int)$v : $d;
}

function fnum(array $r, string $k, float $d = 0.0): float
{
    $v = $r[$k] ?? null;
    return is_numeric($v) ? (float)$v : $d;
}

function pct(int $n, int $d): float
{
    return $d > 0 ? 100.0 * $n / $d : 0.0;
}

function formal(array $r): bool
{
    return inum($r, 'result_top3_course_complete') === 1
        && inum($r, 'result_boat_match') === 1;
}

function attack(array $r, int $course): float
{
    return fnum($r, "c{$course}_6m_makuri") + fnum($r, "c{$course}_6m_makurizashi");
}

function triggered(array $r, string $type): bool
{
    if ($type === 'A3') {
        return inum($r, 'c3_6m_sample_n') >= 10 && attack($r, 3) >= 15.0;
    }
    if ($type === 'A4') {
        return inum($r, 'c4_6m_sample_n') >= 10 && attack($r, 4) >= 20.0;
    }
    return false;
}

$candidates = [
    ['venue'=>'児島',   'type'=>'A3', 'course'=>3],
    ['venue'=>'大村',   'type'=>'A3', 'course'=>3],
    ['venue'=>'尼崎',   'type'=>'A3', 'course'=>3],
    ['venue'=>'常滑',   'type'=>'A3', 'course'=>3],
    ['venue'=>'桐生',   'type'=>'A3', 'course'=>3],
    ['venue'=>'芦屋',   'type'=>'A3', 'course'=>3],
    ['venue'=>'若松',   'type'=>'A3', 'course'=>3],
    ['venue'=>'丸亀',   'type'=>'A4', 'course'=>4],
    ['venue'=>'唐津',   'type'=>'A4', 'course'=>4],
    ['venue'=>'多摩川', 'type'=>'A4', 'course'=>4],
    ['venue'=>'徳山',   'type'=>'A4', 'course'=>4],
    ['venue'=>'若松',   'type'=>'A4', 'course'=>4],
];

$rows = array_values(array_filter(readCsvAssoc($path), 'formal'));
$dates = [];
$web1N = 0;
$venueBase = [];

foreach ($rows as $r) {
    $date = trim((string)($r['race_date'] ?? ''));
    if ($date !== '') $dates[] = $date;
    if (inum($r, 'honmei_head') !== 1) continue;
    $web1N++;
    $v = trim((string)($r['stadium_name'] ?? ''));
    if ($v === '') continue;
    if (!isset($venueBase[$v])) {
        $venueBase[$v] = ['n'=>0,'fail'=>0,'c3win'=>0,'c4win'=>0];
    }
    $venueBase[$v]['n']++;
    if (inum($r, 'actual_1st_course') !== 1) $venueBase[$v]['fail']++;
    if (inum($r, 'actual_1st_course') === 3) $venueBase[$v]['c3win']++;
    if (inum($r, 'actual_1st_course') === 4) $venueBase[$v]['c4win']++;
}

$stats = [];
$signalEvents = 0;
$signalFail = 0;
$signalHeadWin = 0;
$expectedFailSum = 0.0;
$expectedHeadSum = 0.0;
$uniqueRace = [];

foreach ($candidates as $c) {
    $key = $c['venue'] . '_' . $c['type'];
    $stats[$key] = ['n'=>0,'fail'=>0,'headwin'=>0];
}

foreach ($rows as $r) {
    if (inum($r, 'honmei_head') !== 1) continue;
    $venue = trim((string)($r['stadium_name'] ?? ''));
    $rc = trim((string)($r['race_code'] ?? ''));

    foreach ($candidates as $c) {
        if ($venue !== $c['venue'] || !triggered($r, $c['type'])) continue;
        $key = $c['venue'] . '_' . $c['type'];
        $course = $c['course'];
        $stats[$key]['n']++;
        $signalEvents++;
        if ($rc !== '') $uniqueRace[$rc] = true;

        $fail = inum($r, 'actual_1st_course') !== 1;
        $headWin = inum($r, 'actual_1st_course') === $course;
        if ($fail) { $stats[$key]['fail']++; $signalFail++; }
        if ($headWin) { $stats[$key]['headwin']++; $signalHeadWin++; }

        $b = $venueBase[$venue] ?? ['n'=>0,'fail'=>0,'c3win'=>0,'c4win'=>0];
        if ($b['n'] > 0) {
            $expectedFailSum += $b['fail'] / $b['n'];
            $expectedHeadSum += $b[$course === 3 ? 'c3win' : 'c4win'] / $b['n'];
        }
    }
}

sort($dates);
$start = $dates[0] ?? '-';
$end = $dates ? $dates[count($dates)-1] : '-';

echo str_repeat('=', 194) . "\n";
echo "場×既存攻め条件 12候補 前方ホールドアウト検証\n";
echo str_repeat('=', 194) . "\n";
echo "期間: {$start} ～ {$end} / 正式対象=" . count($rows) . "R / Web本命①={$web1N}R\n";
echo "固定候補: 1年分析で危険++かつ候補頭++だった12条件。閾値・対象場は変更しない。\n";
echo "Δ危険 = 条件時①敗率 - 前方期間の同場Web①基礎敗率。Δ頭 = 条件時候補C勝率 - 同場Web①での候補C基礎勝率。\n\n";

printf("%-8s %-4s %5s | %-18s | %-26s | %-26s | %-8s\n",
    '場','条件','N','同場Web①基礎','①敗 条件/基礎/Δ','候補頭 条件/基礎/Δ','判定');
echo str_repeat('-', 194) . "\n";

foreach ($candidates as $c) {
    $key = $c['venue'] . '_' . $c['type'];
    $s = $stats[$key];
    $b = $venueBase[$c['venue']] ?? ['n'=>0,'fail'=>0,'c3win'=>0,'c4win'=>0];
    $bn = $b['n'];
    $baseFail = pct($b['fail'], $bn);
    $baseHead = pct($b[$c['course'] === 3 ? 'c3win' : 'c4win'], $bn);
    $condFail = pct($s['fail'], $s['n']);
    $condHead = pct($s['headwin'], $s['n']);
    $dFail = $condFail - $baseFail;
    $dHead = $condHead - $baseHead;
    $judge = $s['n'] === 0 ? '対象なし' : (($dFail > 0.0 && $dHead > 0.0) ? '両方+' : '未再現');

    printf("%-8s %-4s %5d | N=%4d 敗=%5.1f%% | %5.1f/%5.1f/%+6.1fpt | %5.1f/%5.1f/%+6.1fpt | %-8s\n",
        $c['venue'], $c['type'], $s['n'], $bn, $baseFail,
        $condFail, $baseFail, $dFail,
        $condHead, $baseHead, $dHead,
        $judge
    );
}

echo "\n" . str_repeat('-', 194) . "\n";
$weightedBaseFail = $signalEvents > 0 ? 100.0 * $expectedFailSum / $signalEvents : 0.0;
$weightedBaseHead = $signalEvents > 0 ? 100.0 * $expectedHeadSum / $signalEvents : 0.0;
printf("12候補シグナル合計: event=%d / unique race=%d | ①敗=%5.1f%% vs 加重基礎=%5.1f%% (Δ=%+5.1fpt) | 候補頭=%5.1f%% vs 加重基礎=%5.1f%% (Δ=%+5.1fpt)\n",
    $signalEvents, count($uniqueRace),
    pct($signalFail, $signalEvents), $weightedBaseFail, pct($signalFail, $signalEvents)-$weightedBaseFail,
    pct($signalHeadWin, $signalEvents), $weightedBaseHead, pct($signalHeadWin, $signalEvents)-$weightedBaseHead
);

echo "\n判断ポイント:\n";
echo "1. 前方1週間程度なので個別Nは小さい。まず12候補合計の方向を確認する。\n";
echo "2. 個別条件はNが少なくても条件変更せず、そのまま追加期間を蓄積する。\n";
echo "3. Δ危険とΔ頭が両方プラスなら前方再現を支持。片方でもマイナスなら現時点では未再現。\n";
echo "4. この出力を見て場・A3/A4閾値を追加探索しない。\n";
echo "5. まだ本番補正・買い目変更は行わない。\n";
