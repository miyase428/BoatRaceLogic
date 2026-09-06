<?php
/**
 * 中配当・高配当・大穴のサインを、単独性能・併発性能・出現率・前方再現性で整理し、
 * さらに強いペアを「再現下限（探索/前方の小さい方の上昇幅）」で順位付けする。
 *
 * 目的:
 * - 単独で効く主力サイン
 * - 単独は弱いが他と重なると効く増幅サイン
 * - 出現率が低い希少サイン
 * - 特定ペアで強くなる組み合わせ
 * を分け、将来のTOPページ / レース詳細表示に使える候補カタログを作る。
 *
 * 注意:
 * - 2026-08-15〜08-31はすでに候補確認に使っているため、ここでの役割は「候補」。
 * - 役割やペアを固定した後は、2026-09-01以降の未使用期間で再確認する。
 *
 * Usage:
 *   php analysis/rank_payout_signal_roles.php \
 *     analysis/output/kimarite_analysis_dataset_20250815_20260814.csv \
 *     analysis/output/kimarite_analysis_dataset_20260815_20260822.csv \
 *     analysis/output/kimarite_analysis_dataset_20260823_20260831.csv
 */

declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';

const PSR_HOLDOUT_START = '20260815';
const PSR_MIN_SAMPLE_N = 10;
const PSR_PAIR_TRAIN_N = 300;
const PSR_PAIR_FORWARD_N = 15;

function psrUsage(): never
{
    fwrite(STDERR, "使用方法:\n  php analysis/rank_payout_signal_roles.php KIMARITE_CSV [KIMARITE_CSV ...]\n");
    exit(1);
}

if ($argc < 2) psrUsage();

function psrPct(int $num, int $den): float
{
    return $den > 0 ? 100.0 * $num / $den : 0.0;
}

function psrNum($v): ?float
{
    $s = trim((string)$v);
    return ($s !== '' && is_numeric($s)) ? (float)$s : null;
}

function psrFmtDate(?string $ymd): string
{
    if ($ymd === null || !preg_match('/^\d{8}$/', $ymd)) return '-';
    return substr($ymd, 0, 4) . '-' . substr($ymd, 4, 2) . '-' . substr($ymd, 6, 2);
}

function psrLoadRows(string $path): array
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
        foreach (['sample_n','win','sashi','makuri','makurizashi'] as $suffix) {
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
        $nige = psrNum($data[$idx['c1_1y_nige']] ?? null);
        if ($n1 < PSR_MIN_SAMPLE_N || $nige === null) continue;

        $attacks = [];
        $sashis = [];
        $makuris = [];
        $wins = [];
        for ($c = 2; $c <= 6; $c++) {
            $n = (int)($data[$idx["c{$c}_1y_sample_n"]] ?? 0);
            if ($n < PSR_MIN_SAMPLE_N) continue;
            $win = psrNum($data[$idx["c{$c}_1y_win"]] ?? null);
            $sashi = psrNum($data[$idx["c{$c}_1y_sashi"]] ?? null);
            $makuri = psrNum($data[$idx["c{$c}_1y_makuri"]] ?? null);
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
        foreach ($attacks as $a) if ($a >= 20.0) $count20++;

        $raceNo = (int)$m[2];
        if ($hasRaceNo) {
            $raw = trim((string)($data[$idx['race_number']] ?? ''));
            if (ctype_digit($raw)) $raceNo = (int)$raw;
        }

        $honmei = null;
        $taikou = null;
        if ($hasHonmei) {
            $h = trim((string)($data[$idx['honmei_head']] ?? ''));
            if (preg_match('/^[1-6]$/', $h)) $honmei = (int)$h;
        }
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

function psrLoadPayouts(PDO $pdo, array $raceCodes): array
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
FROM payout p LEFT JOIN quality q ON q.race_code=p.race_code
";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($chunk, $chunk));
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $code = trim((string)$r['race_code']);
            $payout = (int)($r['trifecta_payout'] ?? 0);
            $normal = ((int)$r['r1'] === 1 && (int)$r['r2'] === 1 && (int)$r['r3'] === 1);
            $out[$code] = ['payout' => $payout, 'valid' => ($payout > 0 && $normal)];
        }
    }
    return $out;
}

function psrWebBothNon1(array $r): bool
{
    return $r['honmei_head'] !== null && $r['taikou_head'] !== null
        && $r['honmei_head'] !== 1 && $r['taikou_head'] !== 1;
}

function psrSignals(array $r): array
{
    $medium = [
        'イン逃げ50未満' => $r['nige'] < 50.0,
        'Web本命対抗とも非1' => psrWebBothNon1($r),
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
        'Web本命対抗とも非1' => psrWebBothNon1($r),
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

function psrTargetHit(int $payout, string $target): bool
{
    return match ($target) {
        'medium' => $payout >= 5000 && $payout < 10000,
        'high' => $payout >= 10000 && $payout < 20000,
        'big' => $payout >= 20000,
        default => false,
    };
}

function psrMetrics(array $rows, string $target): array
{
    $n = count($rows);
    $hit = 0;
    $ge5 = 0;
    $non1 = 0;
    foreach ($rows as $r) {
        $p = (int)$r['payout'];
        if (psrTargetHit($p, $target)) $hit++;
        if ($p >= 5000) $ge5++;
        if ((int)$r['actual_1st'] !== 1) $non1++;
    }
    return [
        'n' => $n,
        'rate' => psrPct($hit, $n),
        'ge5_rate' => psrPct($ge5, $n),
        'non1_rate' => psrPct($non1, $n),
    ];
}

function psrSelect(array $rows, callable $fn): array
{
    $out = [];
    foreach ($rows as $code => $r) if ($fn($r)) $out[$code] = $r;
    return $out;
}

function psrRole(
    float $occurrence,
    array $baseTrain,
    array $baseForward,
    array $allTrain,
    array $allForward,
    array $soloTrain,
    array $soloForward,
    array $coTrain,
    array $coForward
): string {
    $allUp = $allTrain['rate'] > $baseTrain['rate'] && $allForward['rate'] > $baseForward['rate'];
    $soloUp = $soloTrain['n'] > 0 && $soloForward['n'] > 0
        && $soloTrain['rate'] > $baseTrain['rate'] && $soloForward['rate'] > $baseForward['rate'];
    $coUp = $coTrain['n'] > 0 && $coForward['n'] > 0
        && $coTrain['rate'] > $baseTrain['rate'] && $coForward['rate'] > $baseForward['rate'];
    $ampTrain = $coTrain['rate'] - $soloTrain['rate'];
    $ampForward = $coForward['rate'] - $soloForward['rate'];

    if ($occurrence < 5.0 && $allUp && $soloUp) return '希少・単独強力候補';
    if ($allUp && $soloUp) return '安定主力候補';
    if ($coUp && $ampTrain > 0.0 && $ampForward > 0.0) return '増幅型候補';
    if ($occurrence < 5.0 && $allUp) return '希少・条件付き候補';
    if ($allUp) return '補助型候補';
    return '不安定/保留';
}

$allRows = [];
foreach (array_slice($argv, 1) as $path) {
    try {
        $rows = psrLoadRows($path);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    foreach ($rows as $code => $r) $allRows[$code] = $r;
}
ksort($allRows);
if (!$allRows) {
    fwrite(STDERR, "分析可能な行がありません\n");
    exit(1);
}

try {
    $pdo = getPDO();
    $payouts = psrLoadPayouts($pdo, array_keys($allRows));
} catch (Throwable $e) {
    fwrite(STDERR, "払戻取得失敗: {$e->getMessage()}\n");
    exit(1);
}

$valid = [];
$missing = 0;
$special = 0;
foreach ($allRows as $code => $r) {
    if (!isset($payouts[$code]) || ($payouts[$code]['payout'] ?? 0) <= 0) {
        $missing++;
        continue;
    }
    if (!($payouts[$code]['valid'] ?? false)) {
        $special++;
        continue;
    }
    $r['payout'] = (int)$payouts[$code]['payout'];
    $r['signals'] = psrSignals($r);
    $valid[$code] = $r;
}

$train = [];
$forward = [];
$dateMin = null;
$dateMax = null;
foreach ($valid as $code => $r) {
    if ($r['date'] < PSR_HOLDOUT_START) $train[$code] = $r;
    else $forward[$code] = $r;
    $dateMin = $dateMin === null || $r['date'] < $dateMin ? $r['date'] : $dateMin;
    $dateMax = $dateMax === null || $r['date'] > $dateMax ? $r['date'] : $dateMax;
}

$targets = [
    'medium' => '中配当 5,000〜9,999円',
    'high' => '高配当 10,000〜19,999円',
    'big' => '大穴 20,000円以上',
];

$outDir = __DIR__ . '/output';
if (!is_dir($outDir)) mkdir($outDir, 0775, true);
$outCsv = $outDir . '/payout_signal_role_ranking_' . ($dateMin ?? 'unknown') . '_' . ($dateMax ?? 'unknown') . '.csv';
$fp = fopen($outCsv, 'wb');
if ($fp !== false) {
    fwrite($fp, "\xEF\xBB\xBF");
    fputcsv($fp, [
        'target','kind','name','role','train_n','forward_n','train_rate','forward_rate',
        'train_lift','forward_lift','repro_floor','occurrence_train','occurrence_forward',
        'solo_train_n','solo_forward_n','solo_train_rate','solo_forward_rate',
        'co_train_n','co_forward_n','co_train_rate','co_forward_rate',
        'train_non1_rate','forward_non1_rate'
    ]);
}

$line = str_repeat('=', 156);
echo $line . "\n";
echo "荒れサイン 役割候補 + 強いペア順位付け\n";
echo "期間         : " . psrFmtDate($dateMin) . " ～ " . psrFmtDate($dateMax) . "\n";
echo "探索期間     : ～ 2026-08-14\n";
echo "前方確認     : 2026-08-15 ～ 2026-08-31相当\n";
echo "分析可能     : " . number_format(count($valid)) . "R\n";
echo "払戻欠損     : {$missing}R / 特殊除外: {$special}R\n";
echo "ペア表示基準 : 探索N>=" . PSR_PAIR_TRAIN_N . " / 前方N>=" . PSR_PAIR_FORWARD_N . " / 両期間とも母体超え\n";
echo "CSV          : {$outCsv}\n";
echo "注意         : 役割は候補。固定後に2026-09-01以降で未使用前方検証する\n";
echo $line . "\n";

foreach ($targets as $target => $label) {
    $baseTrain = psrMetrics($train, $target);
    $baseForward = psrMetrics($forward, $target);
    echo "\n【{$label}】\n";
    printf("母体: 探索 N=%d 率=%5.2f%% / 前方 N=%d 率=%5.2f%%\n", $baseTrain['n'], $baseTrain['rate'], $baseForward['n'], $baseForward['rate']);

    $sampleRow = reset($valid);
    $signalNames = array_keys($sampleRow['signals'][$target]);
    $singleRows = [];

    foreach ($signalNames as $name) {
        $trainAll = psrSelect($train, static fn(array $r): bool => (bool)($r['signals'][$target][$name] ?? false));
        $forwardAll = psrSelect($forward, static fn(array $r): bool => (bool)($r['signals'][$target][$name] ?? false));

        $trainSolo = psrSelect($trainAll, static function(array $r) use ($target): bool {
            $count = 0;
            foreach ($r['signals'][$target] as $v) if ($v) $count++;
            return $count === 1;
        });
        $forwardSolo = psrSelect($forwardAll, static function(array $r) use ($target): bool {
            $count = 0;
            foreach ($r['signals'][$target] as $v) if ($v) $count++;
            return $count === 1;
        });

        $trainCo = array_diff_key($trainAll, $trainSolo);
        $forwardCo = array_diff_key($forwardAll, $forwardSolo);

        $mAllT = psrMetrics($trainAll, $target);
        $mAllF = psrMetrics($forwardAll, $target);
        $mSoloT = psrMetrics($trainSolo, $target);
        $mSoloF = psrMetrics($forwardSolo, $target);
        $mCoT = psrMetrics($trainCo, $target);
        $mCoF = psrMetrics($forwardCo, $target);

        $occT = psrPct($mAllT['n'], $baseTrain['n']);
        $occF = psrPct($mAllF['n'], $baseForward['n']);
        $role = psrRole($occT, $baseTrain, $baseForward, $mAllT, $mAllF, $mSoloT, $mSoloF, $mCoT, $mCoF);
        $liftT = $mAllT['rate'] - $baseTrain['rate'];
        $liftF = $mAllF['rate'] - $baseForward['rate'];
        $floor = min($liftT, $liftF);

        $singleRows[] = compact('name','role','occT','occF','mAllT','mAllF','mSoloT','mSoloF','mCoT','mCoF','liftT','liftF','floor');
    }

    usort($singleRows, static function(array $a, array $b): int {
        if ($a['floor'] === $b['floor']) return $b['mAllT']['n'] <=> $a['mAllT']['n'];
        return $b['floor'] <=> $a['floor'];
    });

    echo "\n-- サイン役割候補（再現下限順） --\n";
    echo "順位 サイン                     役割                   出現率   探索率(差)      前方率(差)      単独 探索/前方      併発 探索/前方      非1頭 探索/前方\n";
    echo str_repeat('-', 156) . "\n";
    foreach ($singleRows as $i => $x) {
        printf(
            "%2d   %-26s %-22s %5.1f%%  %5.2f%%(%+5.2f)  %5.2f%%(%+5.2f)  %5.2f/%5.2f%%  %5.2f/%5.2f%%  %5.2f/%5.2f%%\n",
            $i + 1,
            $x['name'], $x['role'], $x['occT'],
            $x['mAllT']['rate'], $x['liftT'],
            $x['mAllF']['rate'], $x['liftF'],
            $x['mSoloT']['rate'], $x['mSoloF']['rate'],
            $x['mCoT']['rate'], $x['mCoF']['rate'],
            $x['mAllT']['non1_rate'], $x['mAllF']['non1_rate']
        );
        if ($fp !== false) {
            fputcsv($fp, [
                $target,'single',$x['name'],$x['role'],
                $x['mAllT']['n'],$x['mAllF']['n'],$x['mAllT']['rate'],$x['mAllF']['rate'],
                $x['liftT'],$x['liftF'],$x['floor'],$x['occT'],$x['occF'],
                $x['mSoloT']['n'],$x['mSoloF']['n'],$x['mSoloT']['rate'],$x['mSoloF']['rate'],
                $x['mCoT']['n'],$x['mCoF']['n'],$x['mCoT']['rate'],$x['mCoF']['rate'],
                $x['mAllT']['non1_rate'],$x['mAllF']['non1_rate']
            ]);
        }
    }

    $pairs = [];
    for ($i = 0; $i < count($signalNames); $i++) {
        for ($j = $i + 1; $j < count($signalNames); $j++) {
            $a = $signalNames[$i];
            $b = $signalNames[$j];
            $trainPair = psrSelect($train, static fn(array $r): bool =>
                (bool)($r['signals'][$target][$a] ?? false) && (bool)($r['signals'][$target][$b] ?? false)
            );
            $forwardPair = psrSelect($forward, static fn(array $r): bool =>
                (bool)($r['signals'][$target][$a] ?? false) && (bool)($r['signals'][$target][$b] ?? false)
            );
            $mT = psrMetrics($trainPair, $target);
            $mF = psrMetrics($forwardPair, $target);
            if ($mT['n'] < PSR_PAIR_TRAIN_N || $mF['n'] < PSR_PAIR_FORWARD_N) continue;
            $liftT = $mT['rate'] - $baseTrain['rate'];
            $liftF = $mF['rate'] - $baseForward['rate'];
            if ($liftT <= 0.0 || $liftF <= 0.0) continue;
            $floor = min($liftT, $liftF);
            $pairs[] = [
                'name' => "{$a} × {$b}",
                'mT' => $mT,
                'mF' => $mF,
                'liftT' => $liftT,
                'liftF' => $liftF,
                'floor' => $floor,
            ];
        }
    }

    usort($pairs, static function(array $a, array $b): int {
        if ($a['floor'] === $b['floor']) return $b['mT']['n'] <=> $a['mT']['n'];
        return $b['floor'] <=> $a['floor'];
    });

    echo "\n-- 強いペア（探索/前方の再現下限順） --\n";
    if (!$pairs) {
        echo "該当なし\n";
    } else {
        echo "順位 ペア                                             探索N 率(差)          前方N 率(差)          再現下限  非1頭 探索/前方\n";
        echo str_repeat('-', 156) . "\n";
        foreach ($pairs as $i => $p) {
            printf(
                "%2d   %-48s %6d %5.2f%%(%+5.2f)  %6d %5.2f%%(%+5.2f)  %+6.2fpt   %5.2f/%5.2f%%\n",
                $i + 1, $p['name'],
                $p['mT']['n'], $p['mT']['rate'], $p['liftT'],
                $p['mF']['n'], $p['mF']['rate'], $p['liftF'],
                $p['floor'], $p['mT']['non1_rate'], $p['mF']['non1_rate']
            );
            if ($fp !== false) {
                fputcsv($fp, [
                    $target,'pair',$p['name'],'強いペア候補',
                    $p['mT']['n'],$p['mF']['n'],$p['mT']['rate'],$p['mF']['rate'],
                    $p['liftT'],$p['liftF'],$p['floor'],'','',
                    '','','','','','','','',
                    $p['mT']['non1_rate'],$p['mF']['non1_rate']
                ]);
            }
        }
    }
}

if ($fp !== false) fclose($fp);

echo "\n" . $line . "\n";
echo "【次の使い方】\n";
echo "1. 単独サインは『役割』、ペアは『再現下限』で候補を絞る。\n";
echo "2. ここで候補を固定したら閾値を再調整しない。\n";
echo "3. 2026-09-01以降の未使用期間で同じ定義を前方検証する。\n";
echo "4. 合格後に共通定義へ切り出し、TOPページ一覧とレース詳細の両方から同じ判定を呼ぶ。\n";
echo $line . "\n";
