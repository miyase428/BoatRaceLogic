<?php
declare(strict_types=1);

/**
 * 旧版CSVと高速版CSVを行単位・列単位で比較する。
 * BOMは無視し、CSVとして読み込んだ値が完全一致するか確認する。
 *
 * 使い方:
 *   php analysis/compare_prediction_export_csv.php OLD_BOATS FAST_BOATS OLD_RACES FAST_RACES
 */

function usage(): never
{
    fwrite(STDERR,
        "使用方法:\n" .
        "  php analysis/compare_prediction_export_csv.php OLD_BOATS FAST_BOATS OLD_RACES FAST_RACES\n"
    );
    exit(1);
}

function openCsv(string $path)
{
    if (!is_file($path)) {
        throw new RuntimeException("CSVがありません: {$path}");
    }

    $fp = fopen($path, 'rb');
    if ($fp === false) {
        throw new RuntimeException("CSVを開けません: {$path}");
    }

    // UTF-8 BOMを読み飛ばす。
    $prefix = fread($fp, 3);
    if ($prefix !== "\xEF\xBB\xBF") {
        rewind($fp);
    }

    return $fp;
}

function compareCsv(string $label, string $oldPath, string $fastPath): array
{
    $oldFp = openCsv($oldPath);
    $fastFp = openCsv($fastPath);

    $lineNo = 0;
    $diffCount = 0;
    $firstDiff = null;

    while (true) {
        $old = fgetcsv($oldFp);
        $fast = fgetcsv($fastFp);

        if ($old === false && $fast === false) {
            break;
        }

        $lineNo++;

        if ($old !== $fast) {
            $diffCount++;
            if ($firstDiff === null) {
                $firstDiff = [
                    'line' => $lineNo,
                    'old' => $old,
                    'fast' => $fast,
                ];
            }
        }
    }

    fclose($oldFp);
    fclose($fastFp);

    return [
        'label' => $label,
        'lines' => $lineNo,
        'diff_count' => $diffCount,
        'first_diff' => $firstDiff,
    ];
}

$args = array_slice($argv, 1);
if (count($args) !== 4) {
    usage();
}

[$oldBoats, $fastBoats, $oldRaces, $fastRaces] = $args;

try {
    $results = [
        compareCsv('艇別CSV', $oldBoats, $fastBoats),
        compareCsv('レース別CSV', $oldRaces, $fastRaces),
    ];
} catch (Throwable $e) {
    fwrite(STDERR, "エラー: {$e->getMessage()}\n");
    exit(2);
}

echo "\n";
echo "============================================================\n";
echo "旧版 vs 高速版 CSV完全一致検証\n";
echo "============================================================\n";

$allSame = true;
foreach ($results as $result) {
    $same = $result['diff_count'] === 0;
    if (!$same) {
        $allSame = false;
    }

    echo sprintf(
        "%s : %s / 比較行=%d / 差分=%d\n",
        $result['label'],
        $same ? '完全一致' : '差分あり',
        $result['lines'],
        $result['diff_count']
    );

    if (!$same && $result['first_diff'] !== null) {
        $d = $result['first_diff'];
        echo "  最初の差分行: {$d['line']}\n";
        echo "  OLD : " . json_encode($d['old'], JSON_UNESCAPED_UNICODE) . "\n";
        echo "  FAST: " . json_encode($d['fast'], JSON_UNESCAPED_UNICODE) . "\n";
    }
}

echo "------------------------------------------------------------\n";
echo "最終判定 : " . ($allSame ? 'PASS（完全一致）' : 'FAIL（差分あり）') . "\n";
echo "============================================================\n\n";

exit($allSame ? 0 : 1);
