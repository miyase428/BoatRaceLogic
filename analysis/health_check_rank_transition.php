<?php

declare(strict_types=1);

/**
 * STEP 2-5
 * 評価段階ごとの順位変化分析
 *
 * 対象:
 * 本命・対抗とも3着以内なのに、
 * 現行買い目で取りこぼした艇
 *
 * Usage:
 * php analysis/health_check_rank_transition.php \
 *   analysis/output/final_prediction_boats_20260801_20260808.csv \
 *   analysis/output/final_prediction_races_20260801_20260808.csv
 */

if ($argc < 3) {
    echo "Usage:\n";
    echo "php analysis/health_check_rank_transition.php boats.csv races.csv\n";
    exit(1);
}

$boatsCsv = $argv[1];
$racesCsv = $argv[2];

if (!file_exists($boatsCsv)) {
    echo "エラー: 艇別CSVがありません: {$boatsCsv}\n";
    exit(1);
}

if (!file_exists($racesCsv)) {
    echo "エラー: レース別CSVがありません: {$racesCsv}\n";
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

    $header = fgetcsv($fp);

    if ($header === false) {
        fclose($fp);
        return [];
    }

    // BOM除去
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

    $rows = [];

    while (($data = fgetcsv($fp)) !== false) {

        if (count($data) === 1 && trim((string)$data[0]) === '') {
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
 * CSV
 */
$boats = readCsv($boatsCsv);
$races = readCsv($racesCsv);


/**
 * race_code => race
 */
$raceMap = [];

foreach ($races as $race) {
    $raceMap[$race['race_code']] = $race;
}


/**
 * race_code => boats
 */
$boatMap = [];

foreach ($boats as $boat) {
    $boatMap[$boat['race_code']][] = $boat;
}


/**
 * ============================================================
 * STEP 2-4 と同じ条件で取りこぼし艇を抽出
 * ============================================================
 */

$misses = [];

foreach ($raceMap as $raceCode => $race) {

    $boatsForRace = $boatMap[$raceCode] ?? [];

    if (count($boatsForRace) === 0) {
        continue;
    }

    $honmei = (int)$race['honmei_head'];
    $taikou = (int)$race['taikou_head'];

    $actual1 = (int)$race['actual_1st'];
    $actual2 = (int)$race['actual_2nd'];
    $actual3 = (int)$race['actual_3rd'];

    if ($actual1 <= 0 || $actual2 <= 0 || $actual3 <= 0) {
        continue;
    }

    /*
     * 本命が3着以内
     */
    $honmeiTop3 =
        $actual1 === $honmei ||
        $actual2 === $honmei ||
        $actual3 === $honmei;

    /*
     * 対抗が3着以内
     */
    $taikouTop3 =
        $actual1 === $taikou ||
        $actual2 === $taikou ||
        $actual3 === $taikou;

    if (!$honmeiTop3 || !$taikouTop3) {
        continue;
    }


    /**
     * 本命買い目に実際の3連単が入っているか
     */
    $honmeiParts = explode('-', $race['honmei_kai']);

    $honmeiHit = false;

    if (count($honmeiParts) === 3) {
        $honmeiHit =
            strpos($honmeiParts[0], (string)$actual1) !== false &&
            strpos($honmeiParts[1], (string)$actual2) !== false &&
            strpos($honmeiParts[2], (string)$actual3) !== false;
    }


    /**
     * 対抗買い目
     */
    $taikouParts = explode('-', $race['taikou_kai']);

    $taikouHit = false;

    if (count($taikouParts) === 3) {
        $taikouHit =
            strpos($taikouParts[0], (string)$actual1) !== false &&
            strpos($taikouParts[1], (string)$actual2) !== false &&
            strpos($taikouParts[2], (string)$actual3) !== false;
    }


    /**
     * どちらかで的中していれば対象外
     */
    if ($honmeiHit || $taikouHit) {
        continue;
    }


    /**
     * 本命・対抗以外の実際3着以内の艇
     */
    $actualTop3 = [
        $actual1,
        $actual2,
        $actual3,
    ];

    foreach ($actualTop3 as $boatNo) {

        if ($boatNo === $honmei || $boatNo === $taikou) {
            continue;
        }

        foreach ($boatsForRace as $boat) {

            if ((int)$boat['lane_number'] !== $boatNo) {
                continue;
            }

            $misses[] = [
                'race_code' => $raceCode,
                'race_date' => $boat['race_date'],
                'stadium_name' => $boat['stadium_name'],
                'race_number' => $boat['race_number'],

                'honmei' => $honmei,
                'taikou' => $taikou,

                'actual_1st' => $actual1,
                'actual_2nd' => $actual2,
                'actual_3rd' => $actual3,

                'missing_boat' => $boatNo,

                'actual_rank' => (int)$boat['actual_rank'],

                'first_rank' => (int)$boat['first_rank'],
                'second_rank' => (int)$boat['second_rank'],
                'final_rank' => (int)$boat['final_rank'],

                'first_total_score' =>
                    (float)$boat['first_total_score'],

                'second_score' =>
                    (float)$boat['second_score'],

                'final3' =>
                    (float)$boat['final3'],

                'three_in_rate_6m' =>
                    (float)$boat['three_in_rate_6m'],

                'three_in_rate_3m' =>
                    (float)$boat['three_in_rate_3m'],

                'kitai' =>
                    (float)$boat['kitai'],

                'first_type' =>
                    $boat['first_type'],

                'final_type' =>
                    $boat['final_type'],

                'type_bonus' =>
                    (float)$boat['type_bonus'],

                'get_bonus' =>
                    (float)$boat['get_bonus'],

                'kiru' =>
                    (int)$boat['kiru'],
            ];

            break;
        }
    }
}


/**
 * ============================================================
 * 基本集計
 * ============================================================
 */

$total = count($misses);


/**
 * 一次 → 二次
 * 二次 → 最終
 * 一次 → 最終
 *
 * 順位差
 *
 * 正の値 = 順位が下がった
 * 例:
 * 2位 → 5位
 * 5 - 2 = +3
 *
 * 負の値 = 順位が上がった
 */
$firstToSecond = [];
$secondToFinal = [];
$firstToFinal = [];


/**
 * 順位変化分類
 */
$transitionPattern = [];


/**
 * 段階別の上位3位数
 */
$top3 = [
    'first' => 0,
    'second' => 0,
    'final' => 0,
];


/**
 * 段階別の上位2位数
 */
$top2 = [
    'first' => 0,
    'second' => 0,
    'final' => 0,
];


/**
 * 最終順位が一次順位より下がった艇
 */
$finalDropped = 0;
$finalImproved = 0;
$finalSame = 0;


/**
 * 一次 → 最終で
 * 3位以内 → 4位以下
 */
$top3Lost = 0;


/**
 * 一次 → 最終で
 * 4位以下 → 3位以内
 */
$top3Gained = 0;


foreach ($misses as $row) {

    $first = $row['first_rank'];
    $second = $row['second_rank'];
    $final = $row['final_rank'];


    /**
     * 順位差
     */
    $d12 = $second - $first;
    $d23 = $final - $second;
    $d13 = $final - $first;


    $firstToSecond[$d12] =
        ($firstToSecond[$d12] ?? 0) + 1;

    $secondToFinal[$d23] =
        ($secondToFinal[$d23] ?? 0) + 1;

    $firstToFinal[$d13] =
        ($firstToFinal[$d13] ?? 0) + 1;


    /**
     * 上位2
     */
    if ($first <= 2) {
        $top2['first']++;
    }

    if ($second <= 2) {
        $top2['second']++;
    }

    if ($final <= 2) {
        $top2['final']++;
    }


    /**
     * 上位3
     */
    if ($first <= 3) {
        $top3['first']++;
    }

    if ($second <= 3) {
        $top3['second']++;
    }

    if ($final <= 3) {
        $top3['final']++;
    }


    /**
     * 最終順位の変化
     */
    if ($final > $first) {
        $finalDropped++;
    } elseif ($final < $first) {
        $finalImproved++;
    } else {
        $finalSame++;
    }


    /**
     * 一次3位以内 → 最終4位以下
     */
    if ($first <= 3 && $final >= 4) {
        $top3Lost++;
    }


    /**
     * 一次4位以下 → 最終3位以内
     */
    if ($first >= 4 && $final <= 3) {
        $top3Gained++;
    }


    /**
     * 順位変化パターン
     */
    $pattern =
        $first . '→' .
        $second . '→' .
        $final;

    $transitionPattern[$pattern] =
        ($transitionPattern[$pattern] ?? 0) + 1;
}


/**
 * ============================================================
 * 表示
 * ============================================================
 */

echo "\n";
echo "========================================\n";
echo "現行最終予想 健康診断 STEP 2-5\n";
echo "評価段階ごとの順位変化分析\n";
echo "========================================\n";

echo "対象取りこぼし艇 : {$total}\n";


/**
 * 上位3比較
 */
echo "\n";
echo "========================================\n";
echo "各評価段階の3位以内率\n";
echo "========================================\n";

foreach ([
    'first' => '一次評価',
    'second' => '二次評価',
    'final' => '最終評価',
] as $key => $label) {

    $count = $top3[$key];

    $rate = $total > 0
        ? ($count / $total * 100)
        : 0;

    printf(
        "%-8s : %3d / %3d (%6.2f%%)\n",
        $label,
        $count,
        $total,
        $rate
    );
}


/**
 * 上位2比較
 */
echo "\n";
echo "========================================\n";
echo "各評価段階の2位以内率\n";
echo "========================================\n";

foreach ([
    'first' => '一次評価',
    'second' => '二次評価',
    'final' => '最終評価',
] as $key => $label) {

    $count = $top2[$key];

    $rate = $total > 0
        ? ($count / $total * 100)
        : 0;

    printf(
        "%-8s : %3d / %3d (%6.2f%%)\n",
        $label,
        $count,
        $total,
        $rate
    );
}


/**
 * 最終順位の変化
 */
echo "\n";
echo "========================================\n";
echo "一次評価 → 最終評価\n";
echo "========================================\n";

$rate = $total > 0
    ? ($finalDropped / $total * 100)
    : 0;

printf(
    "順位ダウン : %3d / %3d (%6.2f%%)\n",
    $finalDropped,
    $total,
    $rate
);

$rate = $total > 0
    ? ($finalImproved / $total * 100)
    : 0;

printf(
    "順位アップ : %3d / %3d (%6.2f%%)\n",
    $finalImproved,
    $total,
    $rate
);

$rate = $total > 0
    ? ($finalSame / $total * 100)
    : 0;

printf(
    "順位変化なし : %3d / %3d (%6.2f%%)\n",
    $finalSame,
    $total,
    $rate
);


/**
 * 3位以内から落ちた
 */
echo "\n";
echo "========================================\n";
echo "一次評価3位以内 → 最終評価4位以下\n";
echo "========================================\n";

$rate = $total > 0
    ? ($top3Lost / $total * 100)
    : 0;

printf(
    "%3d / %3d (%6.2f%%)\n",
    $top3Lost,
    $total,
    $rate
);


/**
 * 4位以下から3位以内に上がった
 */
echo "\n";
echo "========================================\n";
echo "一次評価4位以下 → 最終評価3位以内\n";
echo "========================================\n";

$rate = $total > 0
    ? ($top3Gained / $total * 100)
    : 0;

printf(
    "%3d / %3d (%6.2f%%)\n",
    $top3Gained,
    $total,
    $rate
);


/**
 * 一次 → 二次 順位差
 */
echo "\n";
echo "========================================\n";
echo "一次評価 → 二次評価 順位変化\n";
echo "========================================\n";

ksort($firstToSecond);

foreach ($firstToSecond as $diff => $count) {

    $label = $diff > 0
        ? "+" . $diff
        : (string)$diff;

    printf(
        "%3s位 : %3d件\n",
        $label,
        $count
    );
}


/**
 * 二次 → 最終
 */
echo "\n";
echo "========================================\n";
echo "二次評価 → 最終評価 順位変化\n";
echo "========================================\n";

ksort($secondToFinal);

foreach ($secondToFinal as $diff => $count) {

    $label = $diff > 0
        ? "+" . $diff
        : (string)$diff;

    printf(
        "%3s位 : %3d件\n",
        $label,
        $count
    );
}


/**
 * 一次 → 最終
 */
echo "\n";
echo "========================================\n";
echo "一次評価 → 最終評価 順位変化\n";
echo "========================================\n";

ksort($firstToFinal);

foreach ($firstToFinal as $diff => $count) {

    $label = $diff > 0
        ? "+" . $diff
        : (string)$diff;

    printf(
        "%3s位 : %3d件\n",
        $label,
        $count
    );
}


/**
 * 代表的な順位変化パターン
 */
echo "\n";
echo "========================================\n";
echo "順位変化パターン TOP 20\n";
echo "========================================\n";

arsort($transitionPattern);

$displayCount = 0;

foreach ($transitionPattern as $pattern => $count) {

    printf(
        "%-8s : %3d件\n",
        $pattern,
        $count
    );

    $displayCount++;

    if ($displayCount >= 20) {
        break;
    }
}


/**
 * ============================================================
 * 詳細CSV
 * ============================================================
 */

$outputDir = dirname($boatsCsv);

$baseName = basename(
    $boatsCsv,
    '.csv'
);

$outputFile =
    $outputDir .
    '/' .
    $baseName .
    '_rank_transition.csv';


$fp = fopen($outputFile, 'w');

if ($fp === false) {
    echo "\nエラー: CSVを作成できません。\n";
    exit(1);
}


/**
 * BOM
 */
fwrite($fp, "\xEF\xBB\xBF");


$header = [
    'race_code',
    'race_date',
    'stadium_name',
    'race_number',

    'honmei',
    'taikou',

    'actual_1st',
    'actual_2nd',
    'actual_3rd',

    'missing_boat',
    'actual_rank',

    'first_rank',
    'second_rank',
    'final_rank',

    'first_to_second',
    'second_to_final',
    'first_to_final',

    'first_total_score',
    'second_score',
    'final3',

    'three_in_rate_6m',
    'three_in_rate_3m',

    'kitai',

    'first_type',
    'final_type',

    'type_bonus',
    'get_bonus',
    'kiru',
];


fputcsv($fp, $header);


foreach ($misses as $row) {

    $data = [
        $row['race_code'],
        $row['race_date'],
        $row['stadium_name'],
        $row['race_number'],

        $row['honmei'],
        $row['taikou'],

        $row['actual_1st'],
        $row['actual_2nd'],
        $row['actual_3rd'],

        $row['missing_boat'],
        $row['actual_rank'],

        $row['first_rank'],
        $row['second_rank'],
        $row['final_rank'],

        $row['second_rank'] - $row['first_rank'],
        $row['final_rank'] - $row['second_rank'],
        $row['final_rank'] - $row['first_rank'],

        $row['first_total_score'],
        $row['second_score'],
        $row['final3'],

        $row['three_in_rate_6m'],
        $row['three_in_rate_3m'],

        $row['kitai'],

        $row['first_type'],
        $row['final_type'],

        $row['type_bonus'],
        $row['get_bonus'],
        $row['kiru'],
    ];

    fputcsv($fp, $data);
}


fclose($fp);


echo "\n";
echo "========================================\n";
echo "詳細CSV\n";
echo "========================================\n";

echo $outputFile . "\n";

echo "\n";
echo "STEP 2-5 完了\n";
echo "========================================\n";