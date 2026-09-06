<?php

declare(strict_types=1);

require_once __DIR__ . '/../common/PayoutSignalFeatureBuilder.php';
require_once __DIR__ . '/../common/PayoutSignalClassifier.php';

/**
 * PayoutSignalFeatureBuilder が、これまでの分析CSV上の特徴量計算と一致するか検証する。
 * DB/APIは使わず、CSVの c1〜c6_1y_* を kimarite_api 形式へ戻して全件比較する。
 *
 * Usage:
 *   php analysis/validate_payout_signal_feature_builder.php \
 *     analysis/output/kimarite_analysis_dataset_20260901_20260905.csv
 */

if ($argc < 2) {
    fwrite(STDERR, "使用方法:\n  php analysis/validate_payout_signal_feature_builder.php KIMARITE_CSV [KIMARITE_CSV ...]\n");
    exit(1);
}

function vpsNum(mixed $v): ?float
{
    $s = trim((string)$v);
    return ($s !== '' && is_numeric($s)) ? (float)$s : null;
}

function vpsClose(float $a, float $b, float $eps = 1e-9): bool
{
    return abs($a - $b) <= $eps;
}

function vpsReadCsv(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException("CSVがありません: {$path}");
    }
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        throw new RuntimeException("CSVを開けません: {$path}");
    }
    $header = fgetcsv($fh);
    if ($header === false) {
        fclose($fh);
        throw new RuntimeException("CSVヘッダを読めません: {$path}");
    }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    $rows = [];
    while (($data = fgetcsv($fh)) !== false) {
        if (count($data) < count($header)) continue;
        $rows[] = array_combine($header, array_slice($data, 0, count($header)));
    }
    fclose($fh);
    return $rows;
}

function vpsKimariteFromRow(array $r): array
{
    $out = [];
    for ($c = 1; $c <= 6; $c++) {
        $out[$c] = [
            '1year' => [
                '_sample_n' => (int)($r["c{$c}_1y_sample_n"] ?? 0),
                'nige' => vpsNum($r["c{$c}_1y_nige"] ?? null),
                'win' => vpsNum($r["c{$c}_1y_win"] ?? null),
                'sashi' => vpsNum($r["c{$c}_1y_sashi"] ?? null),
                'makuri' => vpsNum($r["c{$c}_1y_makuri"] ?? null),
            ],
        ];
    }
    return $out;
}

function vpsExpected(array $r): array
{
    $n1 = (int)($r['c1_1y_sample_n'] ?? 0);
    $nige = vpsNum($r['c1_1y_nige'] ?? null);
    if ($n1 < PayoutSignalFeatureBuilder::MIN_SAMPLE_N || $nige === null) {
        return ['status' => 'waiting'];
    }

    $attacks = [];
    $sashis = [];
    $makuris = [];
    $wins = [];
    for ($c = 2; $c <= 6; $c++) {
        $n = (int)($r["c{$c}_1y_sample_n"] ?? 0);
        if ($n < PayoutSignalFeatureBuilder::MIN_SAMPLE_N) continue;
        $win = vpsNum($r["c{$c}_1y_win"] ?? null);
        $sashi = vpsNum($r["c{$c}_1y_sashi"] ?? null);
        $makuri = vpsNum($r["c{$c}_1y_makuri"] ?? null);
        if ($win === null || $sashi === null || $makuri === null) continue;
        $attacks[$c] = $sashi + $makuri;
        $sashis[$c] = $sashi;
        $makuris[$c] = $makuri;
        $wins[$c] = $win;
    }
    if (count($attacks) < 2) {
        return ['status' => 'waiting'];
    }

    arsort($attacks, SORT_NUMERIC);
    $vals = array_values($attacks);
    $count20 = 0;
    foreach ($attacks as $a) if ($a >= 20.0) $count20++;

    return [
        'status' => 'ok',
        'input' => [
            'nige' => $nige,
            'makuri_max' => max($makuris),
            'sashi_max' => max($sashis),
            'attack_max' => (float)$vals[0],
            'attack_gap' => (float)$vals[0] - (float)$vals[1],
            'attack_count20' => $count20,
            'outer_win_max' => max($wins),
        ],
    ];
}

$total = 0;
$ok = 0;
$waiting = 0;
$mismatch = 0;
$mismatchSamples = [];
$levelCounts = [
    'medium' => ['low' => 0, 'watch' => 0, 'strong' => 0],
    'high' => ['low' => 0, 'watch' => 0, 'strong' => 0],
    'big' => ['low' => 0, 'watch' => 0, 'strong' => 0],
];
$chaosCounts = [];
$dateMin = null;
$dateMax = null;

foreach (array_slice($argv, 1) as $path) {
    try {
        $rows = vpsReadCsv($path);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }

    foreach ($rows as $r) {
        $raceCode = trim((string)($r['race_code'] ?? ''));
        if (!preg_match('/^(\d{8})[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $raceCode, $m)) continue;
        $total++;
        $dateMin = $dateMin === null || $m[1] < $dateMin ? $m[1] : $dateMin;
        $dateMax = $dateMax === null || $m[1] > $dateMax ? $m[1] : $dateMax;

        $raceNo = isset($r['race_number']) && ctype_digit(trim((string)$r['race_number']))
            ? (int)$r['race_number']
            : (int)$m[2];
        $summary = [
            'honmei_head' => $r['honmei_head'] ?? null,
            'taikou_head' => $r['taikou_head'] ?? null,
        ];

        $built = PayoutSignalFeatureBuilder::build($raceNo, vpsKimariteFromRow($r), $summary);
        $expected = vpsExpected($r);

        if (($built['status'] ?? '') !== ($expected['status'] ?? '')) {
            $mismatch++;
            if (count($mismatchSamples) < 10) {
                $mismatchSamples[] = "{$raceCode}: status built=" . ($built['status'] ?? '-') . " expected=" . ($expected['status'] ?? '-');
            }
            continue;
        }

        if (($built['status'] ?? '') !== 'ok') {
            $waiting++;
            continue;
        }

        $fields = ['nige','makuri_max','sashi_max','attack_max','attack_gap','outer_win_max'];
        $rowMismatch = false;
        foreach ($fields as $field) {
            $a = (float)($built['input'][$field] ?? NAN);
            $b = (float)($expected['input'][$field] ?? NAN);
            if (!is_finite($a) || !is_finite($b) || !vpsClose($a, $b)) {
                $rowMismatch = true;
                if (count($mismatchSamples) < 10) {
                    $mismatchSamples[] = "{$raceCode}: {$field} built={$a} expected={$b}";
                }
                break;
            }
        }
        if (!$rowMismatch && (int)$built['input']['attack_count20'] !== (int)$expected['input']['attack_count20']) {
            $rowMismatch = true;
            if (count($mismatchSamples) < 10) {
                $mismatchSamples[] = "{$raceCode}: attack_count20 built=" . $built['input']['attack_count20'] . " expected=" . $expected['input']['attack_count20'];
            }
        }
        if ($rowMismatch) {
            $mismatch++;
            continue;
        }

        $ok++;
        $classified = PayoutSignalClassifier::classify($built['input']);
        foreach (['medium','high','big'] as $target) {
            $level = (string)($classified['payout'][$target]['level'] ?? 'low');
            if (!isset($levelCounts[$target][$level])) $levelCounts[$target][$level] = 0;
            $levelCounts[$target][$level]++;
        }
        $primary = (string)($classified['chaos']['primary'] ?? '平常');
        $chaosCounts[$primary] = ($chaosCounts[$primary] ?? 0) + 1;
    }
}

function vpsDate(?string $d): string
{
    if ($d === null || !preg_match('/^\d{8}$/', $d)) return '-';
    return substr($d,0,4) . '-' . substr($d,4,2) . '-' . substr($d,6,2);
}

$line = str_repeat('=', 110);
echo $line . "\n";
echo "荒れサイン 共通特徴量生成 分析一致検証\n";
echo "期間       : " . vpsDate($dateMin) . " ～ " . vpsDate($dateMax) . "\n";
echo "読込       : {$total}R\n";
echo "判定可能   : {$ok}R\n";
echo "母数不足等 : {$waiting}R\n";
echo "不一致     : {$mismatch}R\n";
echo $line . "\n";

if ($mismatchSamples) {
    echo "【不一致サンプル】\n";
    foreach ($mismatchSamples as $s) echo "- {$s}\n";
    echo "\n";
}

echo "【配当傾向レベル件数（判定可能レース）】\n";
foreach (['medium' => '中配当', 'high' => '高配当', 'big' => '大穴'] as $key => $label) {
    $c = $levelCounts[$key];
    printf("%-8s low=%5d  watch=%5d  strong=%5d\n", $label, $c['low'] ?? 0, $c['watch'] ?? 0, $c['strong'] ?? 0);
}

echo "\n【荒れ方 primary 件数】\n";
arsort($chaosCounts);
foreach ($chaosCounts as $name => $n) {
    printf("%-14s %5dR\n", $name, $n);
}

echo "\n";
if ($mismatch === 0) {
    echo "PASS: 共通特徴量生成は分析時の計算と一致しました。\n";
    exit(0);
}

echo "FAIL: 不一致があります。画面連携前に確認してください。\n";
exit(2);
