<?php
/**
 * health_check_miss_analysis.php
 *
 * 現行最終予想 健康診断 STEP 2-3
 * 本命・対抗・買い目 取りこぼし分析
 *
 * Usage:
 *   php health_check_miss_analysis.php \
 *     boats.csv \
 *     races.csv
 */

if ($argc < 3) {
    echo "Usage:\n";
    echo "  php health_check_miss_analysis.php boats.csv races.csv\n";
    exit(1);
}

$boatsCsv = $argv[1];
$racesCsv = $argv[2];

if (!file_exists($boatsCsv)) {
    echo "ERROR: 艇別CSVがありません: {$boatsCsv}\n";
    exit(1);
}

if (!file_exists($racesCsv)) {
    echo "ERROR: レース別CSVがありません: {$racesCsv}\n";
    exit(1);
}

/**
 * CSV読み込み
 */
function readCsv(string $file): array
{
    $fp = fopen($file, 'r');

    if ($fp === false) {
        throw new RuntimeException("CSVを開けません: {$file}");
    }

    $rows = [];

    $header = fgetcsv($fp);

    if ($header === false) {
        fclose($fp);
        return [];
    }

    // UTF-8 BOM除去
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

    while (($data = fgetcsv($fp)) !== false) {
        if (count($data) === 0) {
            continue;
        }

        $row = [];

        foreach ($header as $i => $key) {
            $row[$key] = $data[$i] ?? '';
        }

        $rows[] = $row;
    }

    fclose($fp);

    return $rows;
}

/**
 * 「1・2・3」などを配列に変換
 */
function parseBoatList(string $value): array
{
    $value = trim($value);

    if ($value === '') {
        return [];
    }

    $value = str_replace(['・', ',', ' '], ['', ',', ''], $value);

    if (str_contains($value, ',')) {
        $parts = explode(',', $value);
    } else {
        $parts = str_split($value);
    }

    return array_values(
        array_filter(
            array_map('trim', $parts),
            fn($v) => $v !== ''
        )
    );
}

/**
 * 3連単買い目を判定
 *
 * 例:
 *   1-235-23456
 *
 * 実際:
 *   1-6-3
 *
 * → true
 */
function isTrifectaHit(
    string $bet,
    string $actual1,
    string $actual2,
    string $actual3
): bool {
    $bet = trim($bet);

    if ($bet === '') {
        return false;
    }

    $parts = explode('-', $bet);

    if (count($parts) !== 3) {
        return false;
    }

    $first  = parseBoatList($parts[0]);
    $second = parseBoatList($parts[1]);
    $third  = parseBoatList($parts[2]);

    return in_array((string)$actual1, $first, true)
        && in_array((string)$actual2, $second, true)
        && in_array((string)$actual3, $third, true);
}

/**
 * CSV内の買い目文字列を判定
 *
 * 複数の買い目がある場合にも対応。
 */
function isAnyBetHit(
    string $betString,
    string $actual1,
    string $actual2,
    string $actual3
): bool {
    $betString = trim($betString);

    if ($betString === '') {
        return false;
    }

    // 通常は1つのフォーメーションなので、そのまま判定
    return isTrifectaHit(
        $betString,
        $actual1,
        $actual2,
        $actual3
    );
}

/**
 * CSV読み込み
 */
$boatRows = readCsv($boatsCsv);
$raceRows = readCsv($racesCsv);

/**
 * 艇別CSVから、
 * race_code × lane_number → 実着順
 * を作成
 */
$actualRanks = [];

foreach ($boatRows as $row) {
    $raceCode = trim($row['race_code'] ?? '');
    $lane     = trim($row['lane_number'] ?? '');
    $rank     = trim($row['actual_rank'] ?? '');

    if ($raceCode === '' || $lane === '') {
        continue;
    }

    if ($rank === '') {
        continue;
    }

    $actualRanks[$raceCode][$lane] = (int)$rank;
}

/**
 * レース別結果を補完
 *
 * race CSVにactual_1st～actual_3rdがあればそれを優先。
 * 無い場合はboats CSVから作成。
 */
$analysisRows = [];

foreach ($raceRows as $row) {
    $raceCode = trim($row['race_code'] ?? '');

    if ($raceCode === '') {
        continue;
    }

    $actual1 = trim($row['actual_1st'] ?? '');
    $actual2 = trim($row['actual_2nd'] ?? '');
    $actual3 = trim($row['actual_3rd'] ?? '');

    /**
     * 念のためboats CSVからも補完
     */
    if (
        ($actual1 === '' || $actual2 === '' || $actual3 === '')
        && isset($actualRanks[$raceCode])
    ) {
        foreach ($actualRanks[$raceCode] as $lane => $rank) {
            if ($rank === 1) {
                $actual1 = $lane;
            } elseif ($rank === 2) {
                $actual2 = $lane;
            } elseif ($rank === 3) {
                $actual3 = $lane;
            }
        }
    }

    if ($actual1 === '' || $actual2 === '' || $actual3 === '') {
        continue;
    }

    $row['_actual1'] = $actual1;
    $row['_actual2'] = $actual2;
    $row['_actual3'] = $actual3;

    $analysisRows[] = $row;
}

$total = count($analysisRows);

/**
 * 集計
 */
$bothTop3 = 0;
$mainOnlyTop3 = 0;
$subOnlyTop3 = 0;
$bothOutsideTop3 = 0;

$main1Sub2 = 0;
$main1Sub3 = 0;
$sub1Main2 = 0;
$sub1Main3 = 0;

$mainBetHit = 0;
$subBetHit = 0;
$eitherBetHit = 0;

$missExamples = [];

foreach ($analysisRows as $row) {

    $honmei = trim($row['honmei_head'] ?? '');
    $taikou = trim($row['taikou_head'] ?? '');

    $actual1 = $row['_actual1'];
    $actual2 = $row['_actual2'];
    $actual3 = $row['_actual3'];

    if ($honmei === '' || $taikou === '') {
        continue;
    }

    /**
     * 本命・対抗の実着順
     */
    $honmeiRank = null;
    $taikouRank = null;

    if (isset($actualRanks[$row['race_code']])) {
        foreach ($actualRanks[$row['race_code']] as $lane => $rank) {

            if ((string)$lane === (string)$honmei) {
                $honmeiRank = $rank;
            }

            if ((string)$lane === (string)$taikou) {
                $taikouRank = $rank;
            }
        }
    }

    /**
     * 補完
     */
    if ($honmeiRank === null) {
        if ((string)$actual1 === $honmei) {
            $honmeiRank = 1;
        } elseif ((string)$actual2 === $honmei) {
            $honmeiRank = 2;
        } elseif ((string)$actual3 === $honmei) {
            $honmeiRank = 3;
        }
    }

    if ($taikouRank === null) {
        if ((string)$actual1 === $taikou) {
            $taikouRank = 1;
        } elseif ((string)$actual2 === $taikou) {
            $taikouRank = 2;
        } elseif ((string)$actual3 === $taikou) {
            $taikouRank = 3;
        }
    }

    if ($honmeiRank === null || $taikouRank === null) {
        continue;
    }

    $honmeiTop3 = $honmeiRank <= 3;
    $taikouTop3 = $taikouRank <= 3;

    /**
     * 本命・対抗 3着以内
     */
    if ($honmeiTop3 && $taikouTop3) {
        $bothTop3++;
    } elseif ($honmeiTop3) {
        $mainOnlyTop3++;
    } elseif ($taikouTop3) {
        $subOnlyTop3++;
    } else {
        $bothOutsideTop3++;
    }

    /**
     * 着順パターン
     */
    if ($honmeiRank === 1 && $taikouRank === 2) {
        $main1Sub2++;
    }

    if ($honmeiRank === 1 && $taikouRank === 3) {
        $main1Sub3++;
    }

    if ($taikouRank === 1 && $honmeiRank === 2) {
        $sub1Main2++;
    }

    if ($taikouRank === 1 && $honmeiRank === 3) {
        $sub1Main3++;
    }

    /**
     * 現行買い目
     */
    $honmeiBet = trim($row['honmei_kai'] ?? '');
    $taikouBet = trim($row['taikou_kai'] ?? '');

    $honmeiHit = isAnyBetHit(
        $honmeiBet,
        $actual1,
        $actual2,
        $actual3
    );

    $taikouHit = isAnyBetHit(
        $taikouBet,
        $actual1,
        $actual2,
        $actual3
    );

    if ($honmeiHit) {
        $mainBetHit++;
    }

    if ($taikouHit) {
        $subBetHit++;
    }

    if ($honmeiHit || $taikouHit) {
        $eitherBetHit++;
    }

    /**
     * 本命・対抗とも3着以内なのに
     * 買い目で取り逃したケース
     */
    if (
        $honmeiTop3
        && $taikouTop3
        && !$honmeiHit
        && !$taikouHit
    ) {
        if (count($missExamples) < 20) {

            /**
             * 不足艇を確認
             *
             * 実際の3艇から本命・対抗を除いた艇。
             */
            $actualBoats = [
                $actual1,
                $actual2,
                $actual3
            ];

            $missingBoats = array_values(
                array_filter(
                    $actualBoats,
                    fn($boat) =>
                        (string)$boat !== (string)$honmei
                        && (string)$boat !== (string)$taikou
                )
            );

            $missExamples[] = [
                'race_code'    => $row['race_code'],
                'honmei'       => $honmei,
                'honmei_rank'  => $honmeiRank,
                'taikou'       => $taikou,
                'taikou_rank'  => $taikouRank,
                'actual1'      => $actual1,
                'actual2'      => $actual2,
                'actual3'      => $actual3,
                'missing'      => implode(',', $missingBoats),
                'honmei_kai'   => $honmeiBet,
                'taikou_kai'   => $taikouBet,
            ];
        }
    }
}

/**
 * パーセント表示
 */
function pct(int $num, int $den): string
{
    if ($den <= 0) {
        return '0.00%';
    }

    return number_format(
        ($num / $den) * 100,
        2
    ) . '%';
}

/**
 * 出力
 */
echo "\n";
echo "========================================\n";
echo "現行最終予想 健康診断 STEP 2-3\n";
echo "本命・対抗・買い目 取りこぼし分析\n";
echo "========================================\n";
echo "対象レース : {$total}\n";

echo "\n";
echo "========================================\n";
echo "本命・対抗の3着以内分析\n";
echo "========================================\n";

echo "本命・対抗とも3着以内 : "
    . "{$bothTop3} / {$total} ("
    . pct($bothTop3, $total)
    . ")\n";

echo "本命だけ3着以内       : "
    . "{$mainOnlyTop3} / {$total} ("
    . pct($mainOnlyTop3, $total)
    . ")\n";

echo "対抗だけ3着以内       : "
    . "{$subOnlyTop3} / {$total} ("
    . pct($subOnlyTop3, $total)
    . ")\n";

echo "本命・対抗とも3着外   : "
    . "{$bothOutsideTop3} / {$total} ("
    . pct($bothOutsideTop3, $total)
    . ")\n";

echo "\n";
echo "========================================\n";
echo "本命・対抗の着順パターン\n";
echo "========================================\n";

echo "本命1着＋対抗2着 : "
    . "{$main1Sub2} / {$total} ("
    . pct($main1Sub2, $total)
    . ")\n";

echo "本命1着＋対抗3着 : "
    . "{$main1Sub3} / {$total} ("
    . pct($main1Sub3, $total)
    . ")\n";

echo "対抗1着＋本命2着 : "
    . "{$sub1Main2} / {$total} ("
    . pct($sub1Main2, $total)
    . ")\n";

echo "対抗1着＋本命3着 : "
    . "{$sub1Main3} / {$total} ("
    . pct($sub1Main3, $total)
    . ")\n";

echo "\n";
echo "========================================\n";
echo "買い目の取りこぼし\n";
echo "========================================\n";

echo "本命買い目 的中 : "
    . "{$mainBetHit} / {$total} ("
    . pct($mainBetHit, $total)
    . ")\n";

echo "対抗買い目 的中 : "
    . "{$subBetHit} / {$total} ("
    . pct($subBetHit, $total)
    . ")\n";

echo "どちらか的中   : "
    . "{$eitherBetHit} / {$total} ("
    . pct($eitherBetHit, $total)
    . ")\n";

$missCount = $bothTop3 - (
    $bothTop3 > 0
        ? ($bothTop3 - count($missExamples))
        : 0
);

/**
 * 実際の取りこぼし件数は、
 * missExamplesが20件制限なので別途正確に計算。
 */
$actualMissCount = 0;

foreach ($analysisRows as $row) {

    $honmei = trim($row['honmei_head'] ?? '');
    $taikou = trim($row['taikou_head'] ?? '');

    $actual1 = $row['_actual1'];
    $actual2 = $row['_actual2'];
    $actual3 = $row['_actual3'];

    $honmeiRank = null;
    $taikouRank = null;

    if (isset($actualRanks[$row['race_code']])) {
        foreach ($actualRanks[$row['race_code']] as $lane => $rank) {

            if ((string)$lane === (string)$honmei) {
                $honmeiRank = $rank;
            }

            if ((string)$lane === (string)$taikou) {
                $taikouRank = $rank;
            }
        }
    }

    if ($honmeiRank === null || $taikouRank === null) {
        continue;
    }

    if ($honmeiRank <= 3 && $taikouRank <= 3) {

        $honmeiHit = isAnyBetHit(
            trim($row['honmei_kai'] ?? ''),
            $actual1,
            $actual2,
            $actual3
        );

        $taikouHit = isAnyBetHit(
            trim($row['taikou_kai'] ?? ''),
            $actual1,
            $actual2,
            $actual3
        );

        if (!$honmeiHit && !$taikouHit) {
            $actualMissCount++;
        }
    }
}

echo "\n";
echo "本命・対抗とも3着以内なのに\n";
echo "買い目で取り逃したレース : "
    . "{$actualMissCount} / {$bothTop3} ("
    . pct($actualMissCount, $bothTop3)
    . ")\n";

echo "\n";
echo "========================================\n";
echo "取りこぼし事例（最大20件）\n";
echo "========================================\n";

foreach ($missExamples as $example) {

    /**
     * ここが今回の修正ポイント。
     *
     * 以前:
     *   1---3
     *
     * 今回:
     *   1-6-3
     */
    $actual = sprintf(
        '%s-%s-%s',
        $example['actual1'],
        $example['actual2'],
        $example['actual3']
    );

    echo "\n";
    echo "race_code : {$example['race_code']}\n";

    echo "本命      : "
        . "{$example['honmei']}着順={$example['honmei_rank']}\n";

    echo "対抗      : "
        . "{$example['taikou']}着順={$example['taikou_rank']}\n";

    echo "実際      : {$actual}\n";

    if ($example['missing'] !== '') {
        echo "不足艇    : {$example['missing']}\n";
    }

    echo "本命買い目: {$example['honmei_kai']}\n";
    echo "対抗買い目: {$example['taikou_kai']}\n";
}

echo "\n";
echo "========================================\n";
echo "STEP 2-3 健康診断 完了\n";
echo "========================================\n";