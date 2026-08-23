<?php

declare(strict_types=1);

/**
 * 場別 Web本命① × 既存攻め条件のイン危険度診断。
 *
 * 既に本番相手補正で使っている固定条件だけを使い、場ごとに
 * Web本命①の敗率がどれだけ上がるかを見る。
 *
 * 固定条件:
 *   A3: 3C sample_n>=10 かつ (まくり+まくり差し)>=15
 *   A4: 4C sample_n>=10 かつ (まくり+まくり差し)>=20
 *   ANY: A3 または A4
 *   BOTH: A3 かつ A4
 *
 * 結果は診断用。ここでは閾値・対象場・本番ロジックを変更しない。
 *
 * Usage:
 * php analysis/analyze_stadium_web1_attack_signal_risk.php \
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

const OLD_END = '2026-02-14';
const MIN_SAMPLE = 10;

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

function a3(array $r): bool
{
    return inum($r, 'c3_6m_sample_n') >= MIN_SAMPLE && attack($r, 3) >= 15.0;
}

function a4(array $r): bool
{
    return inum($r, 'c4_6m_sample_n') >= MIN_SAMPLE && attack($r, 4) >= 20.0;
}

function emptyStat(): array
{
    return [
        'n'=>0,
        'fail'=>0,
        'winner3'=>0,
        'winner4'=>0,
        'winner34'=>0,
        'one2'=>0,
        'one3'=>0,
        'oneOut'=>0,
    ];
}

function addStat(array &$s, array $r): void
{
    $s['n']++;
    if (inum($r, 'actual_1st') === 1) return;

    $s['fail']++;
    $wc = inum($r, 'actual_1st_course');
    if ($wc === 3) $s['winner3']++;
    if ($wc === 4) $s['winner4']++;
    if ($wc === 3 || $wc === 4) $s['winner34']++;

    if (inum($r, 'actual_2nd') === 1) {
        $s['one2']++;
    } elseif (inum($r, 'actual_3rd') === 1) {
        $s['one3']++;
    } else {
        $s['oneOut']++;
    }
}

function period(string $date): string
{
    return $date <= OLD_END ? 'OLD6M' : 'RECENT6M';
}

function failRate(array $s): float
{
    return pct($s['fail'], $s['n']);
}

function remainRate(array $s): float
{
    return pct($s['one2'] + $s['one3'], $s['fail']);
}

function condText(array $s, float $baseFail, string $kind): string
{
    if ($s['n'] <= 0) return 'N=0';
    $fail = failRate($s);
    $candidate = match ($kind) {
        'A3' => pct($s['winner3'], $s['n']),
        'A4' => pct($s['winner4'], $s['n']),
        default => pct($s['winner34'], $s['n']),
    };
    return sprintf(
        'N=%4d 敗=%5.1f%%(%+5.1f) 候補頭=%4.1f%% ①残=%4.1f%%',
        $s['n'], $fail, $fail - $baseFail, $candidate, remainRate($s)
    );
}

$rows = readCsvAssoc($datasetPath);
$stats = [];
$formalN = 0;
$web1N = 0;
$dates = [];

foreach ($rows as $r) {
    if (!formal($r)) continue;
    $formalN++;

    $date = trim((string)($r['race_date'] ?? ''));
    if ($date !== '') $dates[] = $date;

    // 現行Web本命①（⑤⑥頭補正は①を選ばないため honmei_head=1 で一致）。
    if (inum($r, 'honmei_head') !== 1) continue;
    $web1N++;

    $venue = trim((string)($r['stadium_name'] ?? ''));
    if ($venue === '') continue;

    if (!isset($stats[$venue])) {
        $stats[$venue] = [];
        foreach (['ALL1Y','OLD6M','RECENT6M'] as $p) {
            $stats[$venue][$p] = [
                'BASE'=>emptyStat(),
                'A3'=>emptyStat(),
                'A4'=>emptyStat(),
                'ANY'=>emptyStat(),
                'BOTH'=>emptyStat(),
            ];
        }
    }

    $p = period($date);
    $isA3 = a3($r);
    $isA4 = a4($r);

    foreach (['ALL1Y', $p] as $bucket) {
        addStat($stats[$venue][$bucket]['BASE'], $r);
        if ($isA3) addStat($stats[$venue][$bucket]['A3'], $r);
        if ($isA4) addStat($stats[$venue][$bucket]['A4'], $r);
        if ($isA3 || $isA4) addStat($stats[$venue][$bucket]['ANY'], $r);
        if ($isA3 && $isA4) addStat($stats[$venue][$bucket]['BOTH'], $r);
    }
}

sort($dates);
$start = $dates[0] ?? '-';
$end = $dates ? $dates[count($dates)-1] : '-';

// A3/A4のどちらかで敗率がどれだけ上がるか順。
$ordered = $stats;
uasort($ordered, static function(array $a, array $b): int {
    $ba = failRate($a['ALL1Y']['BASE']);
    $bb = failRate($b['ALL1Y']['BASE']);
    $da = failRate($a['ALL1Y']['ANY']) - $ba;
    $db = failRate($b['ALL1Y']['ANY']) - $bb;
    return $db <=> $da;
});

echo str_repeat('=', 178) . "\n";
echo "場別 Web本命① × 既存攻め条件 イン危険度診断（1年）\n";
echo str_repeat('=', 178) . "\n";
echo "期間: {$start} ～ {$end} / 正式対象={$formalN}R / Web本命①={$web1N}R / " . count($stats) . "場\n";
echo "固定条件: A3=3C攻め>=15(sample>=10), A4=4C攻め>=20(sample>=10), ANY=A3またはA4\n";
echo "敗差 = 条件時①敗率 - その場のWeb①基礎敗率。＋ほど事前条件がイン危険を拾う。候補頭=A3は3C勝率/A4は4C勝率/ANYは3or4C勝率。\n\n";

printf("%-3s %-8s %6s %7s %8s %-38s %-38s %-38s\n",
    '順','場','Web①N','基礎敗','ANY差','A3','A4','ANY');
echo str_repeat('-', 178) . "\n";
$i = 1;
foreach ($ordered as $venue => $v) {
    $base = $v['ALL1Y']['BASE'];
    $baseFail = failRate($base);
    $anyDelta = failRate($v['ALL1Y']['ANY']) - $baseFail;
    printf(
        "%3d %-8s %6d %6.2f%% %+7.2f %-38s %-38s %-38s\n",
        $i++, $venue, $base['n'], $baseFail, $anyDelta,
        condText($v['ALL1Y']['A3'], $baseFail, 'A3'),
        condText($v['ALL1Y']['A4'], $baseFail, 'A4'),
        condText($v['ALL1Y']['ANY'], $baseFail, 'ANY')
    );
}

$focus = ['徳山','下関','多摩川','大村','津','宮島','戸田','江戸川'];
echo "\n" . str_repeat('=', 150) . "\n";
echo "重点8場：期間再現性\n";
echo str_repeat('=', 150) . "\n";
foreach ($focus as $venue) {
    if (!isset($stats[$venue])) continue;
    echo str_repeat('-', 150) . "\n";
    echo "【{$venue}】\n";
    foreach (['OLD6M','RECENT6M','ALL1Y'] as $p) {
        $v = $stats[$venue][$p];
        $baseFail = failRate($v['BASE']);
        printf("  %-8s BASE N=%4d 敗=%5.1f%% | A3 %s | A4 %s | ANY %s\n",
            $p,
            $v['BASE']['n'],
            $baseFail,
            condText($v['A3'], $baseFail, 'A3'),
            condText($v['A4'], $baseFail, 'A4'),
            condText($v['ANY'], $baseFail, 'ANY')
        );
    }
}

echo "\n判断ポイント:\n";
echo "1. 戸田/江戸川でA3/A4条件時の①敗率が基礎より安定して上がるかを見る。\n";
echo "2. 多摩川/下関など相性良好場では、同じ条件がどの程度の危険度になるか比較する。\n";
echo "3. OLD6M/RECENT6Mの方向が揃わない場は、場×攻め条件として固定しない。\n";
echo "4. 候補頭率と①残り率は穴目警報の『展開候補』『穴頭候補』『①残り』用の診断値。\n";
echo "5. この出力を見ても閾値の追加探索・本番補正はまだ行わない。\n";
