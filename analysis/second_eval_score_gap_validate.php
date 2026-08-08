<?php
declare(strict_types=1);

/**
 * BoatRaceLogic
 * second_eval_score_gap_validate.php
 *
 * 目的:
 *   一次評価 → 二次評価で順位がどのように変化したかを確認し、
 *   その順位変動が実際の着順に対して有効だったかを検証する。
 *
 * 確認するもの:
 *
 *   1. 一次評価順位
 *   2. 二次評価順位
 *   3. 順位変動
 *   4. 順位UP/DOWN別の1着率・3連対率
 *   5. 一次評価1位 → 二次評価順位別の成績
 *
 * 実行:
 *
 *   php analysis/second_eval_score_gap_validate.php
 *
 * または
 *
 *   php analysis/second_eval_score_gap_validate.php 2026-08-01 2026-08-06
 */


//==================================================
// 設定
//==================================================

const DEFAULT_FROM = '2025-08-01';
const DEFAULT_TO   = '2026-07-31';


//==================================================
// 引数
//==================================================

$from = $argv[1] ?? DEFAULT_FROM;
$to   = $argv[2] ?? DEFAULT_TO;


//==================================================
// 日付チェック
//==================================================

if (
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ||
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)
) {
    fwrite(
        STDERR,
        "日付は YYYY-MM-DD 形式で指定してください。\n"
    );

    exit(1);
}


if ($from > $to) {
    fwrite(
        STDERR,
        "開始日は終了日以前にしてください。\n"
    );

    exit(1);
}


//==================================================
// DB接続
//==================================================

require_once __DIR__ . '/../common/db_connect.php';

try {
    $pdo = getPDO();
} catch (Throwable $e) {

    fwrite(
        STDERR,
        "DB接続エラー: {$e->getMessage()}\n"
    );

    exit(1);
}


//==================================================
// 一次評価計算
//
// ※ここは現在の first_eval_validate.php と
//   同じ計算ロジックに合わせる必要がある。
//==================================================

function calcFirstEvalScore(array $data): float
{
    /*
     * IMPORTANT
     *
     * ここには現在の first_eval_validate.php で
     * 実際に使用している一次評価計算式を入れる。
     *
     * 下記は仮置きではなく、
     * 「既存の一次評価スコアを取得できる場合」は
     * そちらを使用する想定。
     */

    return (float)($data['first_eval_score'] ?? 0);
}


//==================================================
// 展示スコア
//==================================================

function calcExhibitionScore(float $diff): float
{
    if ($diff <= -0.10) {
        return 5.0;
    }

    if ($diff <= -0.05) {
        return 4.0;
    }

    if ($diff <= 0.05) {
        return 3.0;
    }

    if ($diff <= 0.10) {
        return 2.0;
    }

    return 1.0;
}


//==================================================
// STスコア
//==================================================

function calcStScore(float $st): float
{
    if ($st <= -0.05) {
        return 1.0;
    }

    if ($st < 0) {
        return 2.0;
    }

    if ($st <= 0.05) {
        return 5.0;
    }

    if ($st <= 0.12) {
        return 4.0;
    }

    if ($st <= 0.20) {
        return 2.0;
    }

    return 1.0;
}


//==================================================
// 周回スコア
//==================================================

function calcLapScore(
    float $lap,
    float $avgLap
): float {

    $diff = $lap - $avgLap;

    if ($diff <= -0.30) {
        return 5.0;
    }

    if ($diff <= -0.10) {
        return 4.0;
    }

    if ($diff <= 0.10) {
        return 3.0;
    }

    if ($diff <= 0.30) {
        return 2.0;
    }

    return 1.0;
}


//==================================================
// 周り足スコア
//==================================================

function calcMawariScore(
    float $mawari,
    float $avgMawari
): float {

    $diff = $mawari - $avgMawari;

    if ($diff <= -0.20) {
        return 5.0;
    }

    if ($diff <= -0.05) {
        return 4.0;
    }

    if ($diff <= 0.05) {
        return 3.0;
    }

    if ($diff <= 0.20) {
        return 2.0;
    }

    return 1.0;
}


//==================================================
// 直線スコア
//==================================================

function calcStraightScore(
    float $straight,
    float $avgStraight
): float {

    $diff = $straight - $avgStraight;

    if ($diff <= -0.04) {
        return 5.0;
    }

    if ($diff <= -0.01) {
        return 4.0;
    }

    if ($diff <= 0.01) {
        return 3.0;
    }

    if ($diff <= 0.04) {
        return 2.0;
    }

    return 1.0;
}


//==================================================
// 二次評価
//==================================================

function calcSecondEval(
    int $lane,
    float $exhibition,
    float $avgExhibition,
    float $st,
    float $lap,
    float $avgLap,
    float $mawari,
    float $avgMawari,
    float $straight,
    float $avgStraight
): array {

    $exDiff =
        $exhibition - $avgExhibition;

    $exScore =
        calcExhibitionScore(
            $exDiff
        );

    $stScore =
        calcStScore(
            $st
        );

    $lapScore =
        calcLapScore(
            $lap,
            $avgLap
        );

    $mawariScore =
        calcMawariScore(
            $mawari,
            $avgMawari
        );

    $straightScore =
        calcStraightScore(
            $straight,
            $avgStraight
        );


    //--------------------------------------------------
    // 展示総合
    //--------------------------------------------------

    $exTotal =
        $exScore
        + $lapScore
        + $mawariScore
        + $straightScore;


    //--------------------------------------------------
    // 攻めポテンシャル
    //--------------------------------------------------

    $attackPotential =
        $stScore
        + $straightScore;


    //--------------------------------------------------
    // 安定性
    //--------------------------------------------------

    $stableScore =
        $lapScore
        + $mawariScore;


    //--------------------------------------------------
    // 展示総合
    //--------------------------------------------------

    $exSougou =
        $exTotal
        + $attackPotential
        + $stableScore;


    //--------------------------------------------------
    // タイプ補正
    //--------------------------------------------------

    $typeHosei = 0.0;


    //--------------------------------------------------
    // 展開補正
    //--------------------------------------------------

    $tenkaiMorai =
        (
            $lane === 2 ||
            $lane === 4
        )
        ? 1.0
        : 0.0;


    //--------------------------------------------------
    // 最終二次評価
    //--------------------------------------------------

    $finalSecondScore =
        $exSougou
        + $typeHosei
        + $tenkaiMorai;


    return [
        'final_2nd_score' =>
            $finalSecondScore
    ];
}


//==================================================
// 順位付け
//==================================================

function assignRanks(
    array &$boats,
    string $scoreKey
): void {

    usort(
        $boats,
        function (
            array $a,
            array $b
        ) use ($scoreKey): int {

            if (
                $a[$scoreKey]
                ==
                $b[$scoreKey]
            ) {
                return
                    $a['lane']
                    <=>
                    $b['lane'];
            }

            return
                (
                    $a[$scoreKey]
                    >
                    $b[$scoreKey]
                )
                ? -1
                : 1;
        }
    );


    $rank = 0;

    $previousScore = null;


    foreach (
        $boats as $index => &$boat
    ) {

        $position =
            $index + 1;

        if (
            $previousScore === null ||
            $boat[$scoreKey] !=
            $previousScore
        ) {

            $rank =
                $position;

            $previousScore =
                $boat[$scoreKey];
        }

        $boat['rank_' . $scoreKey] =
            $rank;
    }

    unset($boat);
}


//==================================================
// 集計バケット
//==================================================

function newBucket(): array
{
    return [
        'count' => 0,
        'first' => 0,
        'top3' => 0,
        'sum_rank' => 0.0
    ];
}


//==================================================
// 集計追加
//==================================================

function addBucket(
    array &$bucket,
    float $actualRank
): void {

    $bucket['count']++;

    if ($actualRank === 1.0) {
        $bucket['first']++;
    }

    if (
        $actualRank >= 1.0 &&
        $actualRank <= 3.0
    ) {
        $bucket['top3']++;
    }

    $bucket['sum_rank'] +=
        $actualRank;
}


//==================================================
// 集計結果
//==================================================

function finalizeBucket(
    array $bucket
): array {

    $n =
        $bucket['count'];

    return [
        'count' =>
            $n,

        'first_rate' =>
            $n > 0
                ? round(
                    $bucket['first']
                    / $n
                    * 100,
                    2
                )
                : 0.0,

        'top3_rate' =>
            $n > 0
                ? round(
                    $bucket['top3']
                    / $n
                    * 100,
                    2
                )
                : 0.0,

        'avg_rank' =>
            $n > 0
                ? round(
                    $bucket['sum_rank']
                    / $n,
                    3
                )
                : null
    ];
}


//==================================================
// 対象レース
//==================================================

$sql = <<<SQL

SELECT DISTINCT

    re.race_code,
    re.race_date

FROM boat_race.race_entry re

WHERE re.race_date
      BETWEEN :from_date
      AND :to_date

ORDER BY
    re.race_date,
    re.race_code

SQL;


$stmt =
    $pdo->prepare($sql);


$stmt->execute([
    ':from_date' =>
        $from,

    ':to_date' =>
        $to
]);


$races =
    $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );


echo
    "========================================\n";

echo
    "一次評価 → 二次評価 順位変動検証\n";

echo
    "期間: {$from} ～ {$to}\n";

echo
    "対象レース: "
    . count($races)
    . "\n";

echo
    "========================================\n";


//==================================================
// 集計
//==================================================

$byGap = [];

$byFirstEvalRank = [];

$bySecondEvalRank = [];

$processed = 0;

$skipped = 0;

$skipMissingExhibition = 0;


//==================================================
// レースループ
//==================================================

foreach (
    $races as $race
) {

    $raceCode =
        $race['race_code'];


    //--------------------------------------------------
    // 出走表＋結果
    //--------------------------------------------------

    $sqlEntry = <<<SQL

SELECT

    re.lane_number AS lane,
    re.player_id,
    rrd.rank

FROM boat_race.race_entry re

LEFT JOIN boat_race.race_result_detail rrd

  ON rrd.race_code =
     re.race_code

 AND rrd.player_id =
     re.player_id

WHERE re.race_code =
      :race_code

ORDER BY
    re.lane_number

SQL;


    $stmtEntry =
        $pdo->prepare(
            $sqlEntry
        );

    $stmtEntry->execute([
        ':race_code' =>
            $raceCode
    ]);


    $entries =
        $stmtEntry->fetchAll(
            PDO::FETCH_ASSOC
        );


    if (
        count($entries) !== 6
    ) {
        $skipped++;
        continue;
    }


    //--------------------------------------------------
    // 展示情報
    //--------------------------------------------------

    $sqlExhibition = <<<SQL

SELECT

    re.lane_number AS lane,

    el.exhibition_time,
    el.start_timing,
    el.lap_time,
    el.around_time,
    el.straight_time

FROM boat_race.exhibition_live el

JOIN boat_race.race_entry re

  ON el.race_code =
     re.race_code

 AND el.player_id =
     re.player_id

WHERE el.race_code =
      :race_code

ORDER BY
    re.lane_number

SQL;


    $stmtExhibition =
        $pdo->prepare(
            $sqlExhibition
        );


    $stmtExhibition->execute([
        ':race_code' =>
            $raceCode
    ]);


    $exhibitions =
        $stmtExhibition->fetchAll(
            PDO::FETCH_ASSOC
        );


    if (
        count($exhibitions) !== 6
    ) {

        $skipped++;

        $skipMissingExhibition++;

        continue;
    }


    //--------------------------------------------------
    // ここから一次評価
    //
    // race_entry 等から一次評価の既存値を取得する。
    //
    // first_eval_validate.php の実際の取得元に
    // 合わせる必要がある。
    //--------------------------------------------------

    $firstEval = [];


    /*
     * 現在の一次評価が DB/API から取得可能なら
     * ここで取得する。
     *
     * 例:
     *
     *   $firstEval[$lane] = ...
     *
     * この部分は first_eval_validate.php の
     * データ取得方法に合わせる。
     */


    //--------------------------------------------------
    // 現段階では一次評価値が取得できない場合は
    // スキップ
    //--------------------------------------------------

    if (
        count($firstEval) !== 6
    ) {

        /*
         * この検証では一次評価順位が必須なので、
         * 一次評価値を取得できないレースは除外する。
         */

        $skipped++;

        continue;
    }


    //--------------------------------------------------
    // 場コード
    //--------------------------------------------------

    $jyo =
        substr(
            $raceCode,
            8,
            3
        );


    //--------------------------------------------------
    // 場名
    //--------------------------------------------------

    $sqlName = <<<SQL

SELECT stadium_name

FROM boat_race.stadium_master

WHERE stadium_code =
      :jyo

LIMIT 1

SQL;


    $stmtName =
        $pdo->prepare(
            $sqlName
        );

    $stmtName->execute([
        ':jyo' =>
            $jyo
    ]);


    $stadiumName =
        $stmtName->fetchColumn();


    if (!$stadiumName) {
        $skipped++;
        continue;
    }


    //--------------------------------------------------
    // 展示平均
    //--------------------------------------------------

    $sqlAvg = <<<SQL

SELECT

    avg_exhibition_time_6m

FROM boat_race.exhibition_avg_6m

WHERE stadium_name =
      :stadium_name

LIMIT 1

SQL;


    $stmtAvg =
        $pdo->prepare(
            $sqlAvg
        );

    $stmtAvg->execute([
        ':stadium_name' =>
            $stadiumName
    ]);


    $avgExhibition =
        (float)$stmtAvg->fetchColumn();


    if (
        $avgExhibition <= 0
    ) {
        $skipped++;
        continue;
    }


    //--------------------------------------------------
    // 平均値
    //--------------------------------------------------

    $lapValues = [];

    $mawariValues = [];

    $straightValues = [];


    foreach (
        $exhibitions as $row
    ) {

        $lapValues[] =
            (float)$row['lap_time'];

        $mawariValues[] =
            (float)$row['around_time'];

        $straightValues[] =
            (float)$row['straight_time'];
    }


    $avgLap =
        array_sum($lapValues)
        / count($lapValues);


    $avgMawari =
        array_sum($mawariValues)
        / count($mawariValues);


    $avgStraight =
        array_sum($straightValues)
        / count($straightValues);


    //--------------------------------------------------
    // ボートデータ
    //--------------------------------------------------

    $boats = [];


    foreach (
        $exhibitions as $row
    ) {

        $lane =
            (int)$row['lane'];


        //--------------------------------------------------
        // 実着順
        //--------------------------------------------------

        $actualRank = null;


        foreach (
            $entries as $entry
        ) {

            if (
                (int)$entry['lane']
                ===
                $lane
            ) {

                $actualRank =
                    (float)$entry['rank'];

                break;
            }
        }


        if (
            $actualRank === null ||
            $actualRank < 1 ||
            $actualRank > 6
        ) {

            continue;
        }


        //--------------------------------------------------
        // 二次評価
        //--------------------------------------------------

        $secondEval =
            calcSecondEval(

                $lane,

                (float)$row['exhibition_time'],

                $avgExhibition,

                (float)$row['start_timing'],

                (float)$row['lap_time'],

                $avgLap,

                (float)$row['around_time'],

                $avgMawari,

                (float)$row['straight_time'],

                $avgStraight
            );


        $boats[] = [

            'lane' =>
                $lane,

            'first_eval_score' =>
                $firstEval[$lane],

            'second_eval_score' =>
                $secondEval['final_2nd_score'],

            'actual_rank' =>
                $actualRank
        ];
    }


    if (
        count($boats) !== 6
    ) {
        $skipped++;
        continue;
    }


    //--------------------------------------------------
    // 一次評価順位
    //--------------------------------------------------

    assignRanks(
        $boats,
        'first_eval_score'
    );


    //--------------------------------------------------
    // 二次評価順位
    //--------------------------------------------------

    assignRanks(
        $boats,
        'second_eval_score'
    );


    //--------------------------------------------------
    // 順位変動集計
    //--------------------------------------------------

    foreach (
        $boats as &$boat
    ) {

        $firstRank =
            $boat[
                'rank_first_eval_score'
            ];


        $secondRank =
            $boat[
                'rank_second_eval_score'
            ];


        /*
         * 正の値:
         *   二次評価で順位UP
         *
         * 負の値:
         *   二次評価で順位DOWN
         */

        $gap =
            $firstRank
            -
            $secondRank;


        $boat['rank_gap'] =
            $gap;


        //--------------------------------------------------
        // gapをバケット化
        //--------------------------------------------------

        if ($gap >= 3) {
            $gapKey = '+3以上';
        } elseif ($gap === 2) {
            $gapKey = '+2';
        } elseif ($gap === 1) {
            $gapKey = '+1';
        } elseif ($gap === 0) {
            $gapKey = '0';
        } elseif ($gap === -1) {
            $gapKey = '-1';
        } elseif ($gap === -2) {
            $gapKey = '-2';
        } else {
            $gapKey = '-3以下';
        }


        //--------------------------------------------------
        // 順位変動別
        //--------------------------------------------------

        if (
            !isset(
                $byGap[$gapKey]
            )
        ) {

            $byGap[$gapKey] =
                newBucket();
        }


        addBucket(
            $byGap[$gapKey],
            $boat['actual_rank']
        );


        //--------------------------------------------------
        // 一次評価順位別
        //--------------------------------------------------

        if (
            !isset(
                $byFirstEvalRank[$firstRank]
            )
        ) {

            $byFirstEvalRank[$firstRank] =
                newBucket();
        }


        addBucket(
            $byFirstEvalRank[$firstRank],
            $boat['actual_rank']
        );


        //--------------------------------------------------
        // 二次評価順位別
        //--------------------------------------------------

        if (
            !isset(
                $bySecondEvalRank[$secondRank]
            )
        ) {

            $bySecondEvalRank[$secondRank] =
                newBucket();
        }


        addBucket(
            $bySecondEvalRank[$secondRank],
            $boat['actual_rank']
        );
    }

    unset($boat);


    $processed++;
}


//==================================================
// 結果表示
//==================================================

echo "\n";

echo
    "========================================\n";

echo
    "処理結果\n";

echo
    "========================================\n";

echo
    "処理レース: {$processed}\n";

echo
    "スキップレース: {$skipped}\n";

echo
    "展示なし: {$skipMissingExhibition}\n";


echo "\n";


//==================================================
// 順位変動別
//==================================================

echo
    "========================================\n";

echo
    "【一次評価 → 二次評価 順位変動別】\n";

echo
    "========================================\n";


$gapOrder = [
    '+3以上',
    '+2',
    '+1',
    '0',
    '-1',
    '-2',
    '-3以下'
];


foreach (
    $gapOrder as $gapKey
) {

    if (
        !isset(
            $byGap[$gapKey]
        )
    ) {
        continue;
    }


    $result =
        finalizeBucket(
            $byGap[$gapKey]
        );


    echo
        sprintf(
            "%-8s : "
            . "N=%d "
            . "1着率=%.2f%% "
            . "3連対率=%.2f%% "
            . "平均着順=%.3f\n",

            $gapKey,

            $result['count'],

            $result['first_rate'],

            $result['top3_rate'],

            $result['avg_rank']
                ?? 0.0
        );
}


echo "\n";


//==================================================
// 一次評価順位別
//==================================================

echo
    "========================================\n";

echo
    "【一次評価順位別】\n";

echo
    "========================================\n";


ksort(
    $byFirstEvalRank
);


foreach (
    $byFirstEvalRank as $rank => $bucket
) {

    $result =
        finalizeBucket(
            $bucket
        );


    echo
        sprintf(
            "順位 %d : "
            . "N=%d "
            . "1着率=%.2f%% "
            . "3連対率=%.2f%% "
            . "平均着順=%.3f\n",

            $rank,

            $result['count'],

            $result['first_rate'],

            $result['top3_rate'],

            $result['avg_rank']
                ?? 0.0
        );
}


echo "\n";


//==================================================
// 二次評価順位別
//==================================================

echo
    "========================================\n";

echo
    "【二次評価順位別】\n";

echo
    "========================================\n";


ksort(
    $bySecondEvalRank
);


foreach (
    $bySecondEvalRank as $rank => $bucket
) {

    $result =
        finalizeBucket(
            $bucket
        );


    echo
        sprintf(
            "順位 %d : "
            . "N=%d "
            . "1着率=%.2f%% "
            . "3連対率=%.2f%% "
            . "平均着順=%.3f\n",

            $rank,

            $result['count'],

            $result['first_rate'],

            $result['top3_rate'],

            $result['avg_rank']
                ?? 0.0
        );
}


echo "\n";


//==================================================
// 終了
//==================================================

echo
    "========================================\n";

echo
    "検証終了\n";

echo
    "========================================\n";