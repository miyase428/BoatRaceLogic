<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/FinalPredictionExporter.php';


// ============================================================
// 引数
//
// 1個:
//   race_code指定
//
//   php export_final_prediction.php 20260810WKM12
//
// 2個:
//   期間指定
//
//   php export_final_prediction.php 2026-08-01 2026-08-08
// ============================================================

$args = array_slice(
    $argv,
    1
);

if (count($args) === 1) {

    $mode = 'race';

    $raceCode =
        trim($args[0]);

} elseif (count($args) === 2) {

    $mode = 'period';

    $startDate =
        trim($args[0]);

    $endDate =
        trim($args[1]);

} else {

    fwrite(
        STDERR,
        "\n使用方法:\n\n" .
        "1レース:\n" .
        "  php analysis/export_final_prediction.php 20260810WKM12\n\n" .
        "期間指定:\n" .
        "  php analysis/export_final_prediction.php 2026-08-01 2026-08-08\n\n"
    );

    exit(1);
}


// ============================================================
// Exporter
// ============================================================

$exporter =
    new FinalPredictionExporter();


// ============================================================
// 1レース
// ============================================================

if ($mode === 'race') {

    try {

        $data =
            $exporter->exportRace(
                $raceCode
            );

        echo "\n";
        echo "========================================\n";
        echo "現行最終予想 検証データ取得\n";
        echo "========================================\n";

        echo "race_code : {$raceCode}\n";

        echo "本命       : "
            . ($data['summary']['honmei_head'] ?? '')
            . "\n";

        echo "対抗       : "
            . ($data['summary']['taikou_head'] ?? '')
            . "\n";

        echo "切る艇     : "
            . ($data['summary']['kiru_str'] ?? '')
            . "\n";

        echo "実際の3連単 : "
            . ($data['actual']['trifecta'] ?? '')
            . "\n\n";

    } catch (Throwable $e) {

        fwrite(
            STDERR,
            "エラー: {$e->getMessage()}\n"
        );

        exit(1);
    }

    exit(0);
}


// ============================================================
// 期間指定
// ============================================================

if ($startDate > $endDate) {

    fwrite(
        STDERR,
        "開始日が終了日より後になっています。\n"
    );

    exit(1);
}


// ============================================================
// DB
// ============================================================

require_once __DIR__ . '/../common/db_connect.php';

$pdo =
    getPDO();


// ============================================================
// 対象race_code取得
// ============================================================

$stmt = $pdo->prepare(
    <<<SQL
    SELECT
        race_code,
        race_date,
        stadium_name,
        race_number
    FROM boat_race.race_master
    WHERE race_date >= :start_date
      AND race_date <= :end_date
    ORDER BY
        race_date,
        race_code
    SQL
);

$stmt->execute([
    ':start_date' =>
        $startDate,

    ':end_date' =>
        $endDate,
]);

$raceMasters =
    $stmt->fetchAll(PDO::FETCH_ASSOC);


if (!$raceMasters) {

    fwrite(
        STDERR,
        "指定期間にレースがありません。\n"
    );

    exit(1);
}


// ============================================================
// 出力ファイル
// ============================================================

$outputDir =
    __DIR__ . '/output';

if (!is_dir($outputDir)) {

    mkdir(
        $outputDir,
        0775,
        true
    );
}


$startLabel =
    str_replace(
        '-',
        '',
        $startDate
    );

$endLabel =
    str_replace(
        '-',
        '',
        $endDate
    );


$boatsCsv =
    "{$outputDir}/final_prediction_boats_{$startLabel}_{$endLabel}.csv";

$racesCsv =
    "{$outputDir}/final_prediction_races_{$startLabel}_{$endLabel}.csv";


$boatsFp =
    fopen(
        $boatsCsv,
        'wb'
    );

$racesFp =
    fopen(
        $racesCsv,
        'wb'
    );


if (
    $boatsFp === false ||
    $racesFp === false
) {

    fwrite(
        STDERR,
        "CSVファイルを作成できません。\n"
    );

    exit(1);
}


// BOM
fwrite(
    $boatsFp,
    "\xEF\xBB\xBF"
);

fwrite(
    $racesFp,
    "\xEF\xBB\xBF"
);


// ============================================================
// ヘッダー
// ============================================================

fputcsv(
    $boatsFp,
    [
        'race_code',
        'race_date',
        'stadium_name',
        'race_number',

        'lane_number',
        'player_id',
        'player_name',

        'first_total_score',
        'first_type',
        'first_eval',
        'first_rank',

        'three_in_rate_6m',
        'three_in_rate_3m',

        'second_score',
        'second_rank',

        'kitai',
        'final_type',
        'type_bonus',
        'final3',
        'get_bonus',
        'kiru',
        'final_rank',

        'actual_rank',
    ]
);


fputcsv(
    $racesFp,
    [
        'race_code',
        'race_date',
        'stadium_name',
        'race_number',

        'honmei_head',
        'taikou_head',

        'honmei_aite_str',
        'taikou_aite_str',
        'kiru_str',

        'honmei_kai',
        'taikou_kai',

        'actual_1st',
        'actual_2nd',
        'actual_3rd',
        'actual_trifecta',
    ]
);


// ============================================================
// 処理
// ============================================================

$totalRaces = count($raceMasters);
$successCount = 0;
$errorCount = 0;

$errors = [];


echo "\n";
echo "========================================\n";
echo "現行最終予想 期間検証CSV出力\n";
echo "========================================\n";
echo "期間 : {$startDate} ～ {$endDate}\n";
echo "対象 : {$totalRaces}レース\n";
echo "========================================\n\n";


foreach ($raceMasters as $index => $raceMaster) {

    $raceCode =
        $raceMaster['race_code'];

    $number =
        $index + 1;

    echo sprintf(
        "[%d/%d] %s ... ",
        $number,
        $totalRaces,
        $raceCode
    );


    try {

        $data =
            $exporter->exportRace(
                $raceCode
            );


        $master =
            $data['race_master'];

        $boats =
            $data['boats'];

        $ranks =
            $data['ranks'];

        $summary =
            $data['summary'];

        $actual =
            $data['actual'];


        // ----------------------------------------------------
        // 艇別CSV
        // ----------------------------------------------------

        foreach ($boats as $lane => $boat) {

            fputcsv(
                $boatsFp,
                [
                    $raceCode,
                    $master['race_date'],
                    $master['stadium_name'],
                    $master['race_number'],

                    $lane,
                    $boat['player_id'],
                    $boat['player_name'],

                    $boat['first_total_score'],
                    $boat['first_type'],
                    $boat['first_eval'],
                    $ranks['first'][$lane] ?? '',

                    $boat['three_in_rate_6m'],
                    $boat['three_in_rate_3m'],

                    $boat['second_score'],
                    $ranks['second'][$lane] ?? '',

                    $boat['kitai'],
                    $boat['final_type'],
                    $boat['type_bonus'],
                    $boat['final3'],
                    $boat['get_bonus'],
                    $boat['kiru'],
                    $ranks['final'][$lane] ?? '',

                    $boat['actual_rank'],
                ]
            );
        }


        // ----------------------------------------------------
        // レース別CSV
        // ----------------------------------------------------

        fputcsv(
            $racesFp,
            [
                $raceCode,
                $master['race_date'],
                $master['stadium_name'],
                $master['race_number'],

                $summary['honmei_head'] ?? '',
                $summary['taikou_head'] ?? '',

                $summary['honmei_aite_str'] ?? '',
                $summary['taikou_aite_str'] ?? '',
                $summary['kiru_str'] ?? '',

                $summary['honmei_kai'] ?? '',
                $summary['taikou_kai'] ?? '',

                $actual['first'] ?? '',
                $actual['second'] ?? '',
                $actual['third'] ?? '',
                $actual['trifecta'] ?? '',
            ]
        );


        $successCount++;

        echo "OK\n";

    } catch (Throwable $e) {

        $errorCount++;

        $errors[] = [
            'race_code' =>
                $raceCode,

            'error' =>
                $e->getMessage(),
        ];

        echo "ERROR\n";
        echo "       {$e->getMessage()}\n";
    }
}


fclose($boatsFp);
fclose($racesFp);


// ============================================================
// 完了
// ============================================================

echo "\n";
echo "========================================\n";
echo "期間検証CSV出力完了\n";
echo "========================================\n";

echo "期間       : {$startDate} ～ {$endDate}\n";
echo "対象レース : {$totalRaces}\n";
echo "成功       : {$successCount}\n";
echo "エラー     : {$errorCount}\n";

echo "\n";

echo "艇別CSV:\n";
echo "{$boatsCsv}\n";

echo "\n";

echo "レース別CSV:\n";
echo "{$racesCsv}\n";


if ($errorCount > 0) {

    echo "\n";
    echo "========================================\n";
    echo "エラー一覧\n";
    echo "========================================\n";

    foreach ($errors as $error) {

        echo "{$error['race_code']} : "
            . "{$error['error']}\n";
    }
}

echo "\n";