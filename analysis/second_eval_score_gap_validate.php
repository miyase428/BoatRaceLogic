<?php
declare(strict_types=1);

/**
 * BoatRaceLogic - 二次評価 スコア差 検証プログラム
 *
 * 目的:
 *   過去レースについて、現行 tenji_api.php の
 *   二次評価ロジックを再現し、
 *   二次評価順位間のスコア差と実着順の関係を検証する。
 *
 * 検証内容:
 *
 *   1. 二次評価順位別の成績
 *   2. 各順位と直下順位とのスコア差
 *   3. スコア差レンジ別の成績
 *
 * 例:
 *
 *   二次評価1位
 *       score = 23
 *       2位   = 20
 *       score gap = 3
 *
 *   二次評価2位
 *       score = 20
 *       3位   = 18
 *       score gap = 2
 *
 *
 * 現行 tenji_api.php の二次評価:
 *
 *   ex_score
 *   st_score
 *   lap_score
 *   mawari_score
 *   straight_score
 *
 *       ↓
 *
 *   ex_total
 *   attack_potential
 *   stable_score
 *
 *       ↓
 *
 *   ex_sougou
 *
 *       ↓
 *
 *   type_hosei
 *   tenkai_morai
 *
 *       ↓
 *
 *   final_2nd_score
 *
 *
 * 現在:
 *
 *   type_hosei = 0
 *
 *   tenkai_morai:
 *       2号艇・4号艇 -> +1
 *       その他       -> 0
 *
 *
 * 本番 tenji_api.php は変更しない。
 *
 *
 * 実行:
 *
 *   php analysis/second_eval_score_gap_validate.php
 *
 *
 * 任意:
 *
 *   php analysis/second_eval_score_gap_validate.php 2025-08-01 2026-07-31
 */


//--------------------------------------------------
// デフォルト検証期間
//--------------------------------------------------

const DEFAULT_FROM = '2025-08-01';
const DEFAULT_TO   = '2026-07-31';


//--------------------------------------------------
// コマンドライン引数
//--------------------------------------------------

$from = $argv[1] ?? DEFAULT_FROM;
$to   = $argv[2] ?? DEFAULT_TO;


//--------------------------------------------------
// 日付チェック
//--------------------------------------------------

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


//--------------------------------------------------
// DB接続
//--------------------------------------------------

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


//--------------------------------------------------
// 展示タイム評価
//
// tenji_api.php と同じ。
//--------------------------------------------------

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


//--------------------------------------------------
// ST評価
//
// tenji_api.php と同じ。
//--------------------------------------------------

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


//--------------------------------------------------
// 周回評価
//
// tenji_api.php と同じ。
//--------------------------------------------------

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


//--------------------------------------------------
// 周り足評価
//
// tenji_api.php と同じ。
//--------------------------------------------------

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


//--------------------------------------------------
// 直線評価
//
// tenji_api.php と同じ。
//--------------------------------------------------

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


//--------------------------------------------------
// 二次評価計算
//
// tenji_api.php の現在ロジックを再現。
//--------------------------------------------------

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

    //--------------------------------------------------
    // 展示タイム
    //--------------------------------------------------

    $exDiff =
        $exhibition - $avgExhibition;

    $exScore =
        calcExhibitionScore(
            $exDiff
        );


    //--------------------------------------------------
    // ST
    //--------------------------------------------------

    $stScore =
        calcStScore(
            $st
        );


    //--------------------------------------------------
    // 周回
    //--------------------------------------------------

    $lapScore =
        calcLapScore(
            $lap,
            $avgLap
        );


    //--------------------------------------------------
    // 周り足
    //--------------------------------------------------

    $mawariScore =
        calcMawariScore(
            $mawari,
            $avgMawari
        );


    //--------------------------------------------------
    // 直線
    //--------------------------------------------------

    $straightScore =
        calcStraightScore(
            $straight,
            $avgStraight
        );


    //--------------------------------------------------
    // 展示足トータル
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

        'ex_diff' =>
            $exDiff,

        'ex_score' =>
            $exScore,

        'st_score' =>
            $stScore,

        'lap_score' =>
            $lapScore,

        'mawari_score' =>
            $mawariScore,

        'straight_score' =>
            $straightScore,

        'ex_total' =>
            $exTotal,

        'attack_potential' =>
            $attackPotential,

        'stable_score' =>
            $stableScore,

        'ex_sougou' =>
            $exSougou,

        'type_hosei' =>
            $typeHosei,

        'tenkai_morai' =>
            $tenkaiMorai,

        'final_2nd_score' =>
            $finalSecondScore,
    ];
}


//--------------------------------------------------
// 二次評価順位
//--------------------------------------------------

function assignCompetitionRanks(
    array &$boats
): void {

    usort(
        $boats,
        function (
            array $a,
            array $b
        ): int {

            if (
                $a['final_2nd_score'] ==
                $b['final_2nd_score']
            ) {

                return
                    $a['lane']
                    <=>
                    $b['lane'];
            }

            return
                (
                    $a['final_2nd_score']
                    >
                    $b['final_2nd_score']
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
            $boat['final_2nd_score'] !=
            $previousScore
        ) {

            $rank =
                $position;

            $previousScore =
                $boat['final_2nd_score'];
        }

        $boat['score_rank'] =
            $rank;
    }

    unset($boat);
}


//--------------------------------------------------
// 集計バケット
//--------------------------------------------------

function newBucket(): array
{
    return [

        'count' => 0,

        'first' => 0,

        'second' => 0,

        'third' => 0,

        'top3' => 0,

        'sum_rank' => 0.0,
    ];
}


//--------------------------------------------------
// 実着順追加
//--------------------------------------------------

function addBucket(
    array &$bucket,
    float $actualRank
): void {

    $bucket['count']++;


    if ($actualRank === 1.0) {
        $bucket['first']++;
    }


    if ($actualRank === 2.0) {
        $bucket['second']++;
    }


    if ($actualRank === 3.0) {
        $bucket['third']++;
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


//--------------------------------------------------
// バケット結果
//--------------------------------------------------

function finalizeBucket(
    array $bucket
): array {

    $count =
        $bucket['count'];

    return [

        'count' =>
            $count,

        'first' =>
            $bucket['first'],

        'first_rate' =>
            $count > 0
                ? round(
                    $bucket['first']
                    / $count
                    * 100,
                    2
                )
                : 0.0,

        'second' =>
            $bucket['second'],

        'second_rate' =>
            $count > 0
                ? round(
                    $bucket['second']
                    / $count
                    * 100,
                    2
                )
                : 0.0,

        'third' =>
            $bucket['third'],

        'third_rate' =>
            $count > 0
                ? round(
                    $bucket['third']
                    / $count
                    * 100,
                    2
                )
                : 0.0,

        'top3' =>
            $bucket['top3'],

        'top3_rate' =>
            $count > 0
                ? round(
                    $bucket['top3']
                    / $count
                    * 100,
                    2
                )
                : 0.0,

        'avg_actual_rank' =>
            $count > 0
                ? round(
                    $bucket['sum_rank']
                    / $count,
                    3
                )
                : null,
    ];
}


//--------------------------------------------------
// スコア差レンジ
//--------------------------------------------------

function getGapBucket(
    float $gap
): string {

    if ($gap < 2.0) {
        return '0.0-1.9';
    }

    if ($gap < 4.0) {
        return '2.0-3.9';
    }

    if ($gap < 6.0) {
        return '4.0-5.9';
    }

    if ($gap < 8.0) {
        return '6.0-7.9';
    }

    return '8.0+';
}


//--------------------------------------------------
// 対象レース取得
//--------------------------------------------------

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
    $pdo->prepare(
        $sql
    );

$stmt->execute([

    ':from_date' =>
        $from,

    ':to_date' =>
        $to,
]);

$races =
    $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );

$totalRaces =
    count($races);


echo
    "========================================\n";

echo
    "二次評価 スコア差検証開始\n";

echo
    "期間: {$from} ～ {$to}\n";

echo
    "対象レース: {$totalRaces}\n";

echo
    "========================================\n";


//--------------------------------------------------
// レース統計
//--------------------------------------------------

$raceStats = [

    'processed' => 0,

    'skipped' => 0,

    'boats' => 0,
];


//--------------------------------------------------
// スキップ理由
//--------------------------------------------------

$skipReasons = [

    'not_6_boats' => 0,

    'missing_exhibition' => 0,

    'missing_average' => 0,

    'invalid_rank' => 0,
];


//--------------------------------------------------
// 集計
//--------------------------------------------------

$byScoreRank = [];

$byGap = [];


//--------------------------------------------------
// レースループ
//--------------------------------------------------

foreach (
    $races as $race
) {

    $raceCode =
        (string)$race['race_code'];


    //--------------------------------------------------
    // 出走情報＋実着順
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
            $raceCode,
    ]);

    $entries =
        $stmtEntry->fetchAll(
            PDO::FETCH_ASSOC
        );


    //--------------------------------------------------
    // 6艇確認
    //--------------------------------------------------

    if (
        count($entries) !== 6
    ) {

        $raceStats['skipped']++;

        $skipReasons['not_6_boats']++;

        continue;
    }


    //--------------------------------------------------
    // 展示データ取得
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
            $raceCode,
    ]);

    $exhibitions =
        $stmtExhibition->fetchAll(
            PDO::FETCH_ASSOC
        );


    //--------------------------------------------------
    // 展示6艇確認
    //--------------------------------------------------

    if (
        count($exhibitions) !== 6
    ) {

        $raceStats['skipped']++;

        $skipReasons['missing_exhibition']++;

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
    // 場名取得
    //--------------------------------------------------

    $sqlName = <<<SQL
SELECT
    stadium_name
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
            $jyo,
    ]);

    $stadiumName =
        $stmtName->fetchColumn();


    if (!$stadiumName) {

        $raceStats['skipped']++;

        $skipReasons['missing_average']++;

        continue;
    }


    //--------------------------------------------------
    // 6か月展示平均
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
            $stadiumName,
    ]);

    $avgExhibition =
        (float)$stmtAvg->fetchColumn();


    if (
        $avgExhibition <= 0
    ) {

        $raceStats['skipped']++;

        $skipReasons['missing_average']++;

        continue;
    }


    //--------------------------------------------------
    // 6艇平均
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
    // 実着順 lane → rank
    //--------------------------------------------------

    $actualRanks = [];


    foreach (
        $entries as $entry
    ) {

        $lane =
            (int)$entry['lane'];


        $rankRaw =
            $entry['rank'];


        if (
            $rankRaw === null ||
            $rankRaw === ''
        ) {

            $actualRank =
                5.5;

        } elseif (
            in_array(
                (string)$rankRaw,
                ['1', '2', '3', '4', '5', '6'],
                true
            )
        ) {

            $actualRank =
                (float)$rankRaw;

        } else {

            $actualRanks[$lane] =
                null;

            continue;
        }


        $actualRanks[$lane] =
            $actualRank;
    }


    //--------------------------------------------------
    // 実着順不正確認
    //--------------------------------------------------

    $invalidRank =
        false;


    foreach (
        range(1, 6) as $lane
    ) {

        if (
            !isset($actualRanks[$lane]) ||
            $actualRanks[$lane] === null
        ) {

            $invalidRank =
                true;

            break;
        }
    }


    if ($invalidRank) {

        $raceStats['skipped']++;

        $skipReasons['invalid_rank']++;

        continue;
    }


    //--------------------------------------------------
    // 二次評価計算
    //--------------------------------------------------

    $boats = [];


    foreach (
        $exhibitions as $row
    ) {

        $lane =
            (int)$row['lane'];


        $evaluation =
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

            'final_2nd_score' =>
                $evaluation['final_2nd_score'],

            'actual_rank' =>
                $actualRanks[$lane],
        ];
    }


    //--------------------------------------------------
    // 二次評価順位付け
    //--------------------------------------------------

    assignCompetitionRanks(
        $boats
    );


    //--------------------------------------------------
    // スコア差計算
    //
    // score_gap:
    //   自分の順位のスコア
    //   -
    //   直下順位のスコア
    //
    // 最下位は比較対象がないため
    // score_gap = null。
    //--------------------------------------------------

    $boatCount =
        count($boats);


    for (
        $index = 0;
        $index < $boatCount;
        $index++
    ) {

        if (
            $index < $boatCount - 1
        ) {

            $currentScore =
                (float)$boats[$index]['final_2nd_score'];

            $nextScore =
                (float)$boats[$index + 1]['final_2nd_score'];

            $scoreGap =
                round(
                    $currentScore - $nextScore,
                    3
                );

        } else {

            $scoreGap = null;
        }


        $boats[$index]['score_gap'] =
            $scoreGap;
    }


    //--------------------------------------------------
    // 集計
    //--------------------------------------------------

    foreach (
        $boats as $boat
    ) {

        $scoreRank =
            (int)$boat['score_rank'];


        $actualRank =
            (float)$boat['actual_rank'];


        $scoreGap =
            $boat['score_gap'];


        //--------------------------------------------------
        // 二次評価順位別
        //--------------------------------------------------

        if (
            !isset(
                $byScoreRank[$scoreRank]
            )
        ) {

            $byScoreRank[$scoreRank] =
                newBucket();
        }


        addBucket(
            $byScoreRank[$scoreRank],
            $actualRank
        );


        //--------------------------------------------------
        // スコア差別
        //
        // 最下位は直下順位がないため除外。
        //--------------------------------------------------

        if (
            $scoreGap !== null
        ) {

            $gapBucket =
                getGapBucket(
                    (float)$scoreGap
                );


            if (
                !isset(
                    $byGap[$gapBucket]
                )
            ) {

                $byGap[$gapBucket] =
                    newBucket();
            }


            addBucket(
                $byGap[$gapBucket],
                $actualRank
            );
        }


        $raceStats['boats']++;
    }


    $raceStats['processed']++;


    //--------------------------------------------------
    // 500レースごとの進捗
    //--------------------------------------------------

    if (
        ($raceStats['processed'] % 500) === 0
    ) {

        echo sprintf(
            "[%d/%d] processed=%d skipped=%d\n",

            $raceStats['processed'],

            $totalRaces,

            $raceStats['processed'],

            $raceStats['skipped']
        );
    }
}


//--------------------------------------------------
// 結果整形
//--------------------------------------------------

$scoreRankResult = [];

ksort(
    $byScoreRank
);


foreach (
    $byScoreRank as $rank => $bucket
) {

    $scoreRankResult[
        (string)$rank
    ] =
        finalizeBucket(
            $bucket
        );
}


//--------------------------------------------------
// スコア差結果
//--------------------------------------------------

$gapOrder = [
    '0.0-1.9',
    '2.0-3.9',
    '4.0-5.9',
    '6.0-7.9',
    '8.0+',
];


$gapResult = [];


foreach (
    $gapOrder as $gap
) {

    if (
        isset(
            $byGap[$gap]
        )
    ) {

        $gapResult[$gap] =
            finalizeBucket(
                $byGap[$gap]
            );
    }
}


//--------------------------------------------------
// JSONレポート
//--------------------------------------------------

$report = [

    'period' => [

        'from' =>
            $from,

        'to' =>
            $to,
    ],


    'score_gap_rule' => [

        'definition' =>
            '自順位の二次評価スコア - 直下順位の二次評価スコア',

        'last_rank' =>
            '直下順位が存在しないためスコア差集計から除外',

        'ranges' => [

            '0.0-1.9' =>
                '0.0以上2.0未満',

            '2.0-3.9' =>
                '2.0以上4.0未満',

            '4.0-5.9' =>
                '4.0以上6.0未満',

            '6.0-7.9' =>
                '6.0以上8.0未満',

            '8.0+' =>
                '8.0以上',
        ],
    ],


    'summary' => [

        'target_races' =>
            $totalRaces,

        'processed_races' =>
            $raceStats['processed'],

        'skipped_races' =>
            $raceStats['skipped'],

        'processed_boats' =>
            $raceStats['boats'],
    ],


    'skip_reasons' =>
        $skipReasons,


    'by_score_rank' =>
        $scoreRankResult,


    'by_score_gap' =>
        $gapResult,
];


//--------------------------------------------------
// 出力ディレクトリ
//--------------------------------------------------

$outputDir =
    __DIR__ . '/output';


if (
    !is_dir($outputDir)
) {

    mkdir(
        $outputDir,
        0775,
        true
    );
}


//--------------------------------------------------
// 出力ファイル
//--------------------------------------------------

$outputFile =
    $outputDir .
    '/second_eval_score_gap_report_' .
    str_replace(
        '-',
        '',
        $from
    ) .
    '_' .
    str_replace(
        '-',
        '',
        $to
    ) .
    '.json';


//--------------------------------------------------
// JSON保存
//--------------------------------------------------

$json =
    json_encode(
        $report,
        JSON_UNESCAPED_UNICODE |
        JSON_PRETTY_PRINT |
        JSON_INVALID_UTF8_SUBSTITUTE
    );


if ($json === false) {

    fwrite(
        STDERR,
        "JSON生成に失敗しました。\n"
    );

    exit(1);
}


if (
    file_put_contents(
        $outputFile,
        $json
    ) === false
) {

    fwrite(
        STDERR,
        "結果ファイルの保存に失敗しました。\n"
    );

    exit(1);
}


//--------------------------------------------------
// コンソール出力
//--------------------------------------------------

echo
    "\n========================================\n";

echo
    "二次評価 スコア差検証完了\n";

echo
    "========================================\n";


echo
    "対象レース     : {$totalRaces}\n";

echo
    "検証レース     : {$raceStats['processed']}\n";

echo
    "スキップ       : {$raceStats['skipped']}\n";

echo
    "対象艇数       : {$raceStats['boats']}\n";

echo
    "結果ファイル   : {$outputFile}\n";

echo
    "========================================\n";


//--------------------------------------------------
// スキップ理由
//--------------------------------------------------

echo
    "\n【スキップ理由】\n";


foreach (
    $skipReasons as $reason => $count
) {

    echo sprintf(
        "%-24s : %d\n",
        $reason,
        $count
    );
}


//--------------------------------------------------
// 二次評価順位別
//--------------------------------------------------

echo
    "\n【二次評価順位別】\n";


foreach (
    $scoreRankResult as $rank => $result
) {

    printf(

        "順位 %d : "
        . "N=%d "
        . "1着=%d(%.2f%%) "
        . "2着=%d(%.2f%%) "
        . "3着=%d(%.2f%%) "
        . "3連対=%d(%.2f%%) "
        . "平均着順=%.3f\n",

        $rank,

        $result['count'],

        $result['first'],
        $result['first_rate'],

        $result['second'],
        $result['second_rate'],

        $result['third'],
        $result['third_rate'],

        $result['top3'],
        $result['top3_rate'],

        $result['avg_actual_rank']
            ?? 0.0
    );
}


//--------------------------------------------------
// スコア差別
//--------------------------------------------------

echo
    "\n【スコア差別】\n";


foreach (
    $gapResult as $gap => $result
) {

    printf(

        "差 %s : "
        . "N=%d "
        . "1着=%d(%.2f%%) "
        . "2着=%d(%.2f%%) "
        . "3着=%d(%.2f%%) "
        . "3連対=%d(%.2f%%) "
        . "平均着順=%.3f\n",

        $gap,

        $result['count'],

        $result['first'],
        $result['first_rate'],

        $result['second'],
        $result['second_rate'],

        $result['third'],
        $result['third_rate'],

        $result['top3'],
        $result['top3_rate'],

        $result['avg_actual_rank']
            ?? 0.0
    );
}


//--------------------------------------------------
// 終了
//--------------------------------------------------

echo
    "\n========================================\n";

echo
    "二次評価 スコア差検証終了\n";

echo
    "========================================\n";
?>
