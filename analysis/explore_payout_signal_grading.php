<?php
/**
 * 中配当・高配当・大穴サインの「格付け探索」。
 *
 * 目的:
 * - サインごとの出現率を見る。
 * - そのサインだけが出た時の配当帯率（単独性能）を見る。
 * - 他サインと重なった時の配当帯率（併発性能）を見る。
 * - イン逃げ失敗率も併記し、荒れ方の性格を分ける。
 * - 探索期間と前方期間の両方を出し、S/A/B/Cなどの格付けはまだ固定しない。
 *
 * Usage:
 *   php analysis/explore_payout_signal_grading.php \
 *     analysis/output/kimarite_analysis_dataset_20250815_20260814.csv \
 *     analysis/output/kimarite_analysis_dataset_20260815_20260822.csv \
 *     analysis/output/kimarite_analysis_dataset_20260823_20260831.csv
 */

declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';

const PSG_HOLDOUT_START = '20260815';
const PSG_MIN_SAMPLE_N = 10;

function psgUsage(): never
{
    fwrite(STDERR, "使用方法:\n  php analysis/explore_payout_signal_grading.php KIMARITE_CSV [KIMARITE_CSV ...]\n");
    exit(1);
}
if ($argc < 2) psgUsage();

function psgPct(int $num, int $den): float
{
    return $den > 0 ? 100.0 * $num / $den : 0.0;
}

function psgMedian(array $values): float
{
    if (!$values) return 0.0;
    sort($values, SORT_NUMERIC);
    $n = count($values);
    $m = intdiv($n, 2);
    return ($n % 2 === 1)
        ? (float)$values[$m]
        : ((float)$values[$m - 1] + (float)$values[$m]) / 2.0;
}

function psgNum($v): ?float
{
    $s = trim((string)$v);
    return ($s !== '' && is_numeric($s)) ? (float)$s : null;
}

function psgLoadRows(string $path): array
{
    if (!is_file($path)) throw new RuntimeException("CSVがありません: {$path}");
    $fh = fopen($path, 'rb');
    if ($fh === false) throw new RuntimeException("CSVを開けません: {$path}");

    $header = fgetcsv($fh);
    if ($header === false) {
        fclose($fh);
        throw new RuntimeException("CSVヘッダを読めません: {$path}");
    }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    $idx = array_flip($header);

    $required = ['race_code','actual_1st','c1_1y_sample_n','c1_1y_nige'];
    for ($c = 2; $c <= 6; $c++) {
        foreach (['sample_n','win','sashi','makuri'] as $suffix) {
            $required[] = "c{$c}_1y_{$suffix}";
        }
    }
    foreach ($required as $col) {
        if (!array_key_exists($col, $idx)) {
            fclose($fh);
            throw new RuntimeException("必要列がありません {$col}: {$path}");
        }
    }

    $hasHonmei = array_key_exists('honmei_head', $idx);
    $hasTaikou = array_key_exists('taikou_head', $idx);
    $hasRaceNo = array_key_exists('race_number', $idx);

    $rows = [];
    while (($data = fgetcsv($fh)) !== false) {
        if (count($data) < count($header)) continue;

        $raceCode = trim((string)($data[$idx['race_code']] ?? ''));
        if (!preg_match('/^(\d{8})[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $raceCode, $m)) continue;
        $date = $m[1];

        $actual = trim((string)($data[$idx['actual_1st']] ?? ''));
        if (!preg_match('/^[1-6]$/', $actual)) continue;

        $n1 = (int)($data[$idx['c1_1y_sample_n']] ?? 0);
        $nige = psgNum($data[$idx['c1_1y_nige']] ?? null);
        if ($n1 < PSG_MIN_SAMPLE_N || $nige === null) continue;

        $attacks = [];
        $sashis = [];
        $makuris = [];
        $wins = [];
        for ($c = 2; $c <= 6; $c++) {
            $n = (int)($data[$idx["c{$c}_1y_sample_n"]] ?? 0);
            if ($n < PSG_MIN_SAMPLE_N) continue;
            $win = psgNum($data[$idx["c{$c}_1y_win"]] ?? null);
            $sashi = psgNum($data[$idx["c{$c}_1y_sashi"]] ?? null);
            $makuri = psgNum($data[$idx["c{$c}_1y_makuri"]] ?? null);
            if ($win === null || $sashi === null || $makuri === null) continue;
            $attacks[$c] = $sashi + $makuri;
            $sashis[$c] = $sashi;
            $makuris[$c] = $makuri;
            $wins[$c] = $win;
        }
        if (count($attacks) < 2) continue;

        arsort($attacks, SORT_NUMERIC);
        $vals = array_values($attacks);
        $attackMax = (float)$vals[0];
        $attackSecond = (float)$vals[1];
        $attackGap = $attackMax - $attackSecond;
        $count20 = 0;
        foreach ($attacks as $a) {
            if ($a >= 20.0) $count20++;
        }

        $raceNo = (int)$m[2];
        if ($hasRaceNo) {
            $rawNo = trim((string)($data[$idx['race_number']] ?? ''));
            if (ctype_digit($rawNo)) $raceNo = (int)$rawNo;
        }

        $honmei = null;
        if ($hasHonmei) {
            $h = trim((string)($data[$idx['honmei_head']] ?? ''));
            if (preg_match('/^[1-6]$/', $h)) $honmei = (int)$h;
        }
        $taikou = null;
        if ($hasTaikou) {
            $t = trim((string)($data[$idx['taikou_head']] ?? ''));
            if (preg_match('/^[1-6]$/', $t)) $taikou = (int)$t;
        }

        $rows[$raceCode] = [
            'race_code' => $raceCode,
            'date' => $date,
            'race_no' => $raceNo,
            'actual_1st' => (int)$actual,
            'nige' => $nige,
            'attack_max' => $attackMax,
            'attack_gap' => $attackGap,
            'attack_count20' => $count20,
            'sashi_max' => max($sashis),
            'makuri_max' => max($makuris),
            'outer_win_max' => max($wins),
            'honmei_head' => $honmei,
            'taikou_head' => $taikou,
        ];
    }
    fclose($fh);
    return $rows;
}

function psgLoadPayouts(PDO $pdo, array $raceCodes): array
{
    $out = [];
    foreach (array_chunk($raceCodes, 500) as $chunk) {
        if (!$chunk) continue;
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $sql = "
WITH payout AS (
    SELECT race_code, MAX(COALESCE(trifecta_payout,0))::numeric AS trifecta_payout
    FROM boat_race.race_payouts
    WHERE race_code IN ({$ph})
    GROUP BY race_code
), quality AS (
    SELECT race_code,
           COUNT(*) FILTER (WHERE TRIM(rank)='1')::int AS r1,
           COUNT(*) FILTER (WHERE TRIM(rank)='2')::int AS r2,
           COUNT(*) FILTER (WHERE TRIM(rank)='3')::int AS r3
    FROM boat_race.race_result_detail
    WHERE race_code IN ({$ph})
    GROUP BY race_code
)
SELECT p.race_code,p.trifecta_payout,
       COALESCE(q.r1,0) AS r1,COALESCE(q.r2,0) AS r2,COALESCE(q.r3,0) AS r3
FROM payout p
LEFT JOIN quality q ON q.race_code=p.race_code
";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($chunk, $chunk));
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $code = trim((string)$r['race_code']);
            $p = (int)($r['trifecta_payout'] ?? 0);
            $normal = ((int)$r['r1'] === 1 && (int)$r['r2'] === 1 && (int)$r['r3'] === 1);
            $out[$code] = ['payout' => $p, 'valid' => ($p > 0 && $normal)];
        }
    }
    return $out;
}

function psgWebBothNon1(array $r): bool
{
    return $r['honmei_head'] !== null && $r['taikou_head'] !== null
        && $r['honmei_head'] !== 1 && $r['taikou_head'] !== 1;
}

function psgSignals(array $r): array
{
    $medium = [
        'イン逃げ50未満' => $r['nige'] < 50.0,
        'Web本命対抗とも非1' => psgWebBothNon1($r),
        '序盤1-4R' => $r['race_no'] >= 1 && $r['race_no'] <= 4,
        '捲り最大20-29' => $r['makuri_max'] >= 20.0 && $r['makuri_max'] < 30.0,
        '攻め20+が2艇' => $r['attack_count20'] === 2,
    ];

    $strongAttack =
        ($r['attack_max'] >= 30.0 && $r['attack_max'] < 40.0)
        || ($r['makuri_max'] >= 15.0 && $r['makuri_max'] < 20.0)
        || ($r['sashi_max'] >= 25.0);

    $high = [
        'イン逃げ40未満' => $r['nige'] < 40.0,
        'Web本命対抗とも非1' => psgWebBothNon1($r),
        '序盤1-4R' => $r['race_no'] >= 1 && $r['race_no'] <= 4,
        '強い攻め兆候' => $strongAttack,
        '外コース勝率40以上' => $r['outer_win_max'] >= 40.0,
        '攻め上位差3pt未満' => $r['attack_gap'] < 3.0,
    ];

    $big = [
        'イン逃げ60-69' => $r['nige'] >= 60.0 && $r['nige'] < 70.0,
        '後半9-12R' => $r['race_no'] >= 9 && $r['race_no'] <= 12,
        '攻め20+が2艇' => $r['attack_count20'] === 2,
        '捲り最大15-19' => $r['makuri_max'] >= 15.0 && $r['makuri_max'] < 20.0,
        '外コース勝率15未満' => $r['outer_win_max'] < 15.0,
    ];

    return ['medium' => $medium, 'high' => $high, 'big' => $big];
}

function psgTargetHit(int $payout, string $target): bool
{
    return match ($target) {
        'medium' => $payout >= 5000 && $payout < 10000,
        'high' => $payout >= 10000 && $payout < 20000,
        'big' => $payout >= 20000,
        default => false,
    };
}

function psgMetrics(array $rows, string $target): array
{
    $n = count($rows);
    $targetHit = 0;
    $ge5 = 0;
    $non1 = 0;
    $payouts = [];
    foreach ($rows as $r) {
        $p = (int)$r['payout'];
        $payouts[] = $p;
        if (psgTargetHit($p, $target)) $targetHit++;
        if ($p >= 5000) $ge5++;
        if ((int)$r['actual_1st'] !== 1) $non1++;
    }
    return [
        'n' => $n,
        'target_rate' => psgPct($targetHit, $n),
        'ge5_rate' => psgPct($ge5, $n),
        'non1_rate' => psgPct($non1, $n),
        'avg' => $n > 0 ? array_sum($payouts) / $n : 0.0,
        'median' => psgMedian($payouts),
    ];
}

function psgScore(array $flags): int
{
    $s = 0;
    foreach ($flags as $v) if ($v) $s++;
    return $s;
}

function psgSubset(array $rows, string $target, ?string $signal = null, ?string $mode = null): array
{
    if ($signal === null) return array_values($rows);
    $out = [];
    foreach ($rows as $r) {
        $signals = psgSignals($r)[$target];
        if (!($signals[$signal] ?? false)) continue;
        $score = psgScore($signals);
        if ($mode === 'solo' && $score !== 1) continue;
        if ($mode === 'co' && $score < 2) continue;
        $out[] = $r;
    }
    return $out;
}

function psgSignalNames(string $target): array
{
    $dummy = [
        'race_no'=>1,'nige'=>0.0,'attack_max'=>0.0,'attack_gap'=>0.0,'attack_count20'=>0,
        'sashi_max'=>0.0,'makuri_max'=>0.0,'outer_win_max'=>0.0,'honmei_head'=>1,'taikou_head'=>1,
    ];
    return array_keys(psgSignals($dummy)[$target]);
}

function psgPairMetrics(array $rows, string $target, string $a, string $b): array
{
    $subset = [];
    foreach ($rows as $r) {
        $flags = psgSignals($r)[$target];
        if (($flags[$a] ?? false) && ($flags[$b] ?? false)) $subset[] = $r;
    }
    return psgMetrics($subset, $target);
}

$allRows = [];
foreach (array_slice($argv, 1) as $path) {
    try {
        $rows = psgLoadRows($path);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    foreach ($rows as $code => $row) $allRows[$code] = $row;
}
if (!$allRows) {
    fwrite(STDERR, "分析可能な行がありません\n");
    exit(1);
}
ksort($allRows);

try {
    $pdo = getPDO();
    $pq = psgLoadPayouts($pdo, array_keys($allRows));
} catch (Throwable $e) {
    fwrite(STDERR, "払戻取得失敗: " . $e->getMessage() . "\n");
    exit(1);
}

$valid = [];
$missing = 0;
$special = 0;
foreach ($allRows as $code => $r) {
    if (!isset($pq[$code]) || !($pq[$code]['valid'] ?? false)) {
        if (!isset($pq[$code]) || (int)($pq[$code]['payout'] ?? 0) <= 0) $missing++;
        else $special++;
        continue;
    }
    $r['payout'] = (int)$pq[$code]['payout'];
    $valid[$code] = $r;
}

$train = [];
$forward = [];
foreach ($valid as $code => $r) {
    if ($r['date'] < PSG_HOLDOUT_START) $train[$code] = $r;
    else $forward[$code] = $r;
}

$targets = [
    'medium' => '中配当 5,000〜9,999円',
    'high' => '高配当 10,000〜19,999円',
    'big' => '大穴 20,000円以上',
];

$outPath = __DIR__ . '/output/payout_signal_grading_20250815_20260831.csv';
$out = fopen($outPath, 'wb');
if ($out !== false) {
    fputcsv($out, [
        'target','signal','period','base_n','base_target_rate','signal_n','occurrence_rate',
        'signal_target_rate','signal_lift','solo_n','solo_target_rate','co_n','co_target_rate','co_minus_solo',
        'signal_ge5_rate','signal_non1_rate','signal_avg_payout','signal_median_payout'
    ]);
}

$line = str_repeat('=', 154);
echo $line . "\n";
echo "中配当・高配当・大穴 サイン格付け探索（単独性能 / 併発性能 / 出現率）\n";
echo "探索期間     : 2025-08-15 ～ 2026-08-14\n";
echo "前方確認     : 2026-08-15 ～ 2026-08-31相当\n";
echo "分析可能     : " . number_format(count($valid)) . "R\n";
echo "払戻欠損     : {$missing}R / 特殊除外: {$special}R\n";
echo "出力CSV      : {$outPath}\n";
echo "注意         : ここでは格付けを固定せず、希少性・単独性能・併発性能・前方再現性を分けて見る\n";
echo $line . "\n";

foreach ($targets as $target => $title) {
    $baseTrain = psgMetrics($train, $target);
    $baseForward = psgMetrics($forward, $target);
    $signals = psgSignalNames($target);

    echo "\n【{$title}】\n";
    printf("母体 探索N=%d 率=%.2f%% / 前方N=%d 率=%.2f%%\n\n",
        $baseTrain['n'], $baseTrain['target_rate'], $baseForward['n'], $baseForward['target_rate']);

    printf("%-26s | %-57s | %-57s\n", 'サイン', '探索期間', '前方期間');
    echo str_repeat('-',154) . "\n";

    foreach ($signals as $signal) {
        $trainAll = psgSubset($train, $target, $signal, null);
        $trainSolo = psgSubset($train, $target, $signal, 'solo');
        $trainCo = psgSubset($train, $target, $signal, 'co');
        $fwdAll = psgSubset($forward, $target, $signal, null);
        $fwdSolo = psgSubset($forward, $target, $signal, 'solo');
        $fwdCo = psgSubset($forward, $target, $signal, 'co');

        $tm = psgMetrics($trainAll, $target);
        $ts = psgMetrics($trainSolo, $target);
        $tc = psgMetrics($trainCo, $target);
        $fm = psgMetrics($fwdAll, $target);
        $fs = psgMetrics($fwdSolo, $target);
        $fc = psgMetrics($fwdCo, $target);

        $tOcc = psgPct($tm['n'], $baseTrain['n']);
        $fOcc = psgPct($fm['n'], $baseForward['n']);
        $tLift = $tm['target_rate'] - $baseTrain['target_rate'];
        $fLift = $fm['target_rate'] - $baseForward['target_rate'];
        $tCoMinusSolo = $tc['target_rate'] - $ts['target_rate'];
        $fCoMinusSolo = $fc['target_rate'] - $fs['target_rate'];

        printf(
            "%-26s | 出現=%5.1f%% N=%5d 全体=%5.2f%%(%+5.2f) 単独N=%4d=%5.2f%% 併発N=%5d=%5.2f%% | 出現=%5.1f%% N=%4d 全体=%5.2f%%(%+5.2f) 単独N=%3d=%5.2f%% 併発N=%4d=%5.2f%%\n",
            $signal,
            $tOcc,$tm['n'],$tm['target_rate'],$tLift,$ts['n'],$ts['target_rate'],$tc['n'],$tc['target_rate'],
            $fOcc,$fm['n'],$fm['target_rate'],$fLift,$fs['n'],$fs['target_rate'],$fc['n'],$fc['target_rate']
        );
        printf(
            "%-26s   探索: 5千+=%5.2f%% 非1頭=%5.2f%% 平均=%7.0f 中央=%6.0f 併発-単独=%+5.2fpt | 前方: 5千+=%5.2f%% 非1頭=%5.2f%% 平均=%7.0f 中央=%6.0f 併発-単独=%+5.2fpt\n",
            '',
            $tm['ge5_rate'],$tm['non1_rate'],$tm['avg'],$tm['median'],$tCoMinusSolo,
            $fm['ge5_rate'],$fm['non1_rate'],$fm['avg'],$fm['median'],$fCoMinusSolo
        );

        if ($out !== false) {
            foreach ([
                ['探索',$baseTrain,$tm,$ts,$tc,$tOcc,$tLift,$tCoMinusSolo],
                ['前方',$baseForward,$fm,$fs,$fc,$fOcc,$fLift,$fCoMinusSolo],
            ] as $x) {
                [$period,$base,$m,$solo,$co,$occ,$lift,$cms] = $x;
                fputcsv($out, [
                    $target,$signal,$period,$base['n'],$base['target_rate'],$m['n'],$occ,
                    $m['target_rate'],$lift,$solo['n'],$solo['target_rate'],$co['n'],$co['target_rate'],$cms,
                    $m['ge5_rate'],$m['non1_rate'],$m['avg'],$m['median']
                ]);
            }
        }
    }

    echo "\n-- ペア併発（探索N>=300・前方N>=15、探索/前方とも母体以上） --\n";
    $pairs = [];
    for ($i = 0; $i < count($signals); $i++) {
        for ($j = $i + 1; $j < count($signals); $j++) {
            $a = $signals[$i];
            $b = $signals[$j];
            $tm = psgPairMetrics($train, $target, $a, $b);
            $fm = psgPairMetrics($forward, $target, $a, $b);
            if ($tm['n'] < 300 || $fm['n'] < 15) continue;
            $tLift = $tm['target_rate'] - $baseTrain['target_rate'];
            $fLift = $fm['target_rate'] - $baseForward['target_rate'];
            if ($tLift <= 0 || $fLift <= 0) continue;
            $pairs[] = [
                'name' => $a . ' × ' . $b,
                'tn' => $tm['n'], 'tr' => $tm['target_rate'], 'tl' => $tLift,
                'fn' => $fm['n'], 'fr' => $fm['target_rate'], 'fl' => $fLift,
            ];
        }
    }
    usort($pairs, static fn($a,$b) => ($b['tl'] <=> $a['tl']));
    if (!$pairs) {
        echo "該当ペアなし\n";
    } else {
        foreach (array_slice($pairs, 0, 10) as $p) {
            printf("%-58s 探索N=%5d 率=%5.2f%%(%+5.2f) / 前方N=%4d 率=%5.2f%%(%+5.2f)\n",
                $p['name'],$p['tn'],$p['tr'],$p['tl'],$p['fn'],$p['fr'],$p['fl']);
        }
    }
}

if ($out !== false) fclose($out);

echo "\n【見るポイント】\n";
echo "1. 出現率が低くても、単独時の対象率が高く前方でも再現するなら『希少・強力型』候補。\n";
echo "2. 出現率が高く、単独時は母体並みでも併発時に上がるなら『頻出・補助/増幅型』候補。\n";
echo "3. 全体では強いのに単独時が弱いサインは、別サインに乗って見えている可能性がある。\n";
echo "4. 非1頭率も併記し、イン崩壊型かヒモ荒れ型かを分ける。\n";
echo "5. この結果でS/A/B/Cを即固定せず、まず役割分類→未使用期間で再確認する。\n";
echo $line . "\n";
