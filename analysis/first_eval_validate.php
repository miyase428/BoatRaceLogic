<?php
declare(strict_types=1);


/**
 * BoatRaceLogic - 一次評価 検証プログラム
 *
 * 目的:
 *   過去レースについて、現行 public/calc_scores.php の
 *   一次評価ロジックを再現し、実着順と比較する。
 *
 * 実着順について:
 *   race_result_detail.rank に保存されている
 *   1～6着をそのまま実着順として使用する。
 *
 *   1着 -> 1
 *   2着 -> 2
 *   3着 -> 3
 *   4着 -> 4
 *   5着 -> 5
 *   6着 -> 6
 *
 *   rank が NULL の場合は 5.5 に置き換える。
 *   1～6以外の場合は、データ異常としてそのレースをスキップする。
 *
 * 本番 calc_scores.php は変更しない。
 *
 * 実行:
 *   php analysis/first_eval_validate.php
 *
 * 任意:
 *   php analysis/first_eval_validate.php 2025-08-01 2026-07-31
 */


/*
 * デフォルト検証期間
 */
const DEFAULT_FROM = '2025-08-01';
const DEFAULT_TO   = '2026-07-31';


/*
 * コマンドライン引数
 */
$from = $argv[1] ?? DEFAULT_FROM;
$to   = $argv[2] ?? DEFAULT_TO;


/*
 * 日付チェック
 */
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


/*
 * DB接続
 */
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


/**
 * レース日から、そのレース時点の racer_results.term_info を求める。
 *
 *  1～4月  -> 前年下期 YY10
 *  5～10月 -> 当年上期 YY04
 * 11～12月 -> 当年下期 YY10
 */
function getTermInfo(string $raceDate): string
{
    $dt = new DateTimeImmutable($raceDate);

    $year = (int)$dt->format('Y');
    $month = (int)$dt->format('n');


    if ($month <= 4) {

        $termYear = $year - 1;
        $termCode = 10;

    } elseif ($month <= 10) {

        $termYear = $year;
        $termCode = 4;

    } else {

        $termYear = $year;
        $termCode = 10;
    }


    return sprintf(
        '%02d%02d',
        $termYear % 100,
        $termCode
    );
}


/**
 * 現行 calc_scores.php の一次評価点数計算を再現。
 */
function calcFirstEval(
    int $lane,
    float $nation,
    float $local,
    float $motor,
    float $boat,
    float $st
): array {

    /*
     * 勝率
     */
    $shoritsuScore = min(
        10.0,
        max(
            1.0,
            $nation * 1.2
        )
    );


    /*
     * 当地勝率
     */
    $tochiScore = min(
        5.0,
        max(
            1.0,
            ($local - 4.0) * 2.0
        )
    );


    /*
     * モーター2連率
     */
    $motorScore = min(
        5.0,
        max(
            1.0,
            ($motor - 25.0) / 4.0
        )
    );


    /*
     * ボート2連率
     */
    $boatScore = min(
        5.0,
        max(
            1.0,
            ($boat - 25.0) / 4.0
        )
    );


    /*
     * 平均ST
     */
    $stScore = min(
        5.0,
        max(
            1.0,
            8.0 - ($st * 30.0)
        )
    );


    /*
     * 基礎カテゴリ
     */
    $jiryokuScore = $shoritsuScore + $tochiScore;
    $ashiScore    = $motorScore + $boatScore;
    $startScore   = $stScore;


    /*
     * 総合スコア
     */
    $totalScore =
        $jiryokuScore +
        $ashiScore +
        $startScore;


    /*
     * タイプ判定用
     */
    $R = $totalScore;
    $J = $jiryokuScore;
    $P = $ashiScore;
    $N = $stScore;


    $type = null;


    /*
     * 1号艇
     */
    if ($lane === 1) {

        if ($R >= 22) {

            $type = 4;

        } elseif ($R >= 18) {

            $type = 5;

        } else {

            $type = 6;
        }


    /*
     * 6号艇
     */
    } elseif ($lane === 6) {

        if ($R >= 22) {

            $type = 11;

        } elseif ($R >= 18) {

            $type = 12;

        } else {

            $type = 13;
        }


    /*
     * その他の艇
     */
    } elseif ($R >= 22) {

        $type = ($J >= 8) ? 1 : 2;

    } elseif ($R >= 20) {

        $type = 3;

    } elseif ($J <= 3 && $P >= 7) {

        $type = 14;

    } elseif ($J >= 7 && $P <= 5) {

        $type = 15;

    } elseif ($N == 5.0) {

        $type = 17;

    } elseif ($R >= 18) {

        $type = 8;

    } elseif ($R >= 15) {

        $type = 9;

    } else {

        $type = 10;
    }


    /*
     * 一次評価記号
     */
    $ichijiMap = [

        1  => '◎',
        2  => '○',
        3  => '○',
        4  => '◎',
        5  => '○',
        6  => '×',
        7  => '△',
        8  => '○',
        9  => '△',
        10 => '×',
        11 => '△',
        12 => '△',
        13 => '×',
        14 => '△',
        15 => '△',
        16 => '△',
        17 => '○',
        18 => '△',
    ];


    return [

        'shoritsu_score' => $shoritsuScore,

        'tochi_score' =>
            $tochiScore,

        'motor_score' =>
            $motorScore,

        'boat_score' =>
            $boatScore,

        'st_score' =>
            $stScore,

        'jiryoku_score' =>
            $jiryokuScore,

        'ashi_score' =>
            $ashiScore,

        'start_score' =>
            $startScore,

        'total_score' =>
            $totalScore,

        'type' =>
            $type,

        'ichiji_eval' =>
            $ichijiMap[$type] ?? '×',
    ];
}


/**
 * 標準的な競争順位。
 *
 * 総合スコアが同点なら同順位。
 * 次順位は飛ぶ。
 */
function assignCompetitionRanks(array &$boats): void
{
    usort(
        $boats,
        function (array $a, array $b): int {

            if (
                $a['total_score'] ==
                $b['total_score']
            ) {

                return $a['lane'] <=>
                       $b['lane'];
            }


            return (
                $a['total_score'] >
                $b['total_score']
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

        $position = $index + 1;


        if (
            $previousScore === null ||
            $boat['total_score'] !=
            $previousScore
        ) {

            $rank = $position;

            $previousScore =
                $boat['total_score'];
        }


        $boat['score_rank'] =
            $rank;
    }


    unset($boat);
}


/**
 * 集計用の空バケット。
 */
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


/**
 * 実着順を集計。
 *
 * actualRank:
 *   1～6または5.5（NULL代替）を使用。
 */
function addBucket(
    array &$bucket,
    float $actualRank,
    ?int $scoreRank = null
): void {

    $bucket['count']++;


    /*
     * 1着
     */
    if ($actualRank === 1.0) {

        $bucket['first']++;
    }


    /*
     * 2着
     */
    if ($actualRank === 2.0) {

        $bucket['second']++;
    }


    /*
     * 3着
     */
    if ($actualRank === 3.0) {

        $bucket['third']++;
    }


    /*
     * 3着以内
     */
    if (
        $actualRank >= 1.0 &&
        $actualRank <= 3.0
    ) {

        $bucket['top3']++;
    }


    /*
     * 平均着順
     *
     * 着順をそのまま加算。
     */
    $bucket['sum_rank'] +=
        $actualRank;
}


/**
 * 集計結果を整形。
 */
function finalizeBucket(
    array $bucket
): array {

    $count =
        $bucket['count'];


    return [

        'count' =>
            $count,


        /*
         * 1着
         */
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


        /*
         * 2着
         */
        'second' =>
            $bucket['second'],


        /*
         * 3着
         */
        'third' =>
            $bucket['third'],


        /*
         * 3連率
         */
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


        /*
         * 平均着順
         */
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


/*
 * 対象レースを取得。
 */
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
    "一次評価 検証開始\n";

echo
    "期間: {$from} ～ {$to}\n";

echo
    "対象レース: {$totalRaces}\n";

echo
    "========================================\n";


/*
 * レース統計
 */
$raceStats = [

    'processed' => 0,

    'skipped' => 0,

    'boats' => 0,
];


/*
 * スキップ理由
 */
$skipReasons = [

    'not_6_boats' => 0,

    'missing_required_data' => 0,

    'invalid_rank' => 0,
];


/*
 * 集計配列
 */
$byScoreRank = [];

$byEval = [];

$byLaneEval = [];

$byType = [];

$byStadium = [];


/*
 * レースループ
 */
for (
    $i = 0;
    $i < $totalRaces;
    $i++
) {

    $race =
        $races[$i];


    $raceCode =
        (string)$race['race_code'];


    $raceDate =
        (string)$race['race_date'];


    $termInfo =
        getTermInfo($raceDate);


    /*
     * レース単位の入力データ取得。
     */
    $sqlRace = <<<SQL
SELECT
    re.lane_number,
    re.player_id,
    re.player_name,
    re.motor_number,
    re.boat_number,

    ps.national_win_rate,
    ps.local_win_rate,

    es.motor_exacta_rate,
    es.boat_exacta_rate,

    rr.average_start,

    rrd.rank AS actual_rank

FROM boat_race.race_entry re

LEFT JOIN boat_race.player_stats ps
    ON ps.race_code = re.race_code
   AND ps.player_id = re.player_id

LEFT JOIN boat_race.engine_specs es
    ON es.race_code = re.race_code
   AND es.motor_number = re.motor_number
   AND es.boat_number = re.boat_number

LEFT JOIN boat_race.racer_results rr
    ON rr.player_id = re.player_id
   AND rr.term_info = :term_info

LEFT JOIN boat_race.race_result_detail rrd
    ON rrd.race_code = re.race_code
   AND rrd.lane_number = re.lane_number

WHERE re.race_code = :race_code

ORDER BY
    re.lane_number
SQL;


    $stmtRace =
        $pdo->prepare(
            $sqlRace
        );


    $stmtRace->execute([

        ':term_info' =>
            $termInfo,

        ':race_code' =>
            $raceCode,
    ]);


    $rows =
        $stmtRace->fetchAll(
            PDO::FETCH_ASSOC
        );


    /*
     * 6艇揃っているか確認。
     */
    if (
        count($rows) !== 6
    ) {

        $raceStats['skipped']++;

        $skipReasons[
            'not_6_boats'
        ]++;

        continue;
    }


    $boats = [];

    $invalid = false;


    /*
     * 6艇処理
     */
    foreach (
        $rows as $row
    ) {


        /*
         * 一次評価に必要なデータ。
         */
        $required = [

            'national_win_rate',

            'local_win_rate',

            'motor_exacta_rate',

            'boat_exacta_rate',

            'average_start',
        ];


        foreach (
            $required as $key
        ) {

            if (
                $row[$key] === null ||
                $row[$key] === ''
            ) {

                $invalid = true;

                break 2;
            }
        }


        /*
         * 実着順チェック・置換。
         *
         * NULL の場合は 5.5 に置き換える。
         * 1～6着はそのまま正常値として扱う。
         * それ以外の不正値の場合はスキップ対象とする。
         */
        $rawRank = $row['actual_rank'];

        if ($rawRank === null || $rawRank === '') {
            $actualRank = 5.5;
        } else {
            $actualRankText = trim((string)$rawRank);

            if (
                !preg_match(
                    '/^[1-6]$/',
                    $actualRankText
                )
            ) {

                $invalid = true;

                break;
            }

            $actualRank =
                (float)$actualRankText;
        }


        /*
         * 艇番
         */
        $lane =
            (int)$row['lane_number'];


        /*
         * 一次評価計算
         */
        $scores =
            calcFirstEval(

                $lane,

                (float)
                $row['national_win_rate'],

                (float)
                $row['local_win_rate'],

                (float)
                $row['motor_exacta_rate'],

                (float)
                $row['boat_exacta_rate'],

                (float)
                $row['average_start']
            );


        /*
         * ボート情報
         */
        $boats[] = [

            'lane' =>
                $lane,

            'player_id' =>
                (string)
                $row['player_id'],

            'player_name' =>
                (string)
                $row['player_name'],

            'actual_rank' =>
                $actualRank,


            /*
             * 評価
             */
            'total_score' =>
                $scores['total_score'],

            'type' =>
                $scores['type'],

            'ichiji_eval' =>
                $scores['ichiji_eval'],


            /*
             * 各スコア
             */
            'shoritsu_score' =>
                $scores['shoritsu_score'],

            'tochi_score' =>
                $scores['tochi_score'],

            'motor_score' =>
                $scores['motor_score'],

            'boat_score' =>
                $scores['boat_score'],

            'st_score' =>
                $scores['st_score'],

            'jiryoku_score' =>
                $scores['jiryoku_score'],

            'ashi_score' =>
                $scores['ashi_score'],

            'start_score' =>
                $scores['start_score'],
        ];
    }


    /*
     * データ異常の場合
     */
    if (
        $invalid ||
        count($boats) !== 6
    ) {

        $raceStats['skipped']++;

        $skipReasons[
            'missing_required_data'
        ]++;

        continue;
    }


    /*
     * 総合スコア順位付与
     */
    assignCompetitionRanks(
        $boats
    );


    /*
     * 処理数
     */
    $raceStats['processed']++;

    $raceStats['boats'] += 6;


    /*
     * 6艇の集計
     */
    foreach (
        $boats as $boat
    ) {

        $actualRank =
            $boat['actual_rank'];

        $scoreRank =
            $boat['score_rank'];

        $eval =
            $boat['ichiji_eval'];

        $lane =
            $boat['lane'];

        $type =
            $boat['type'];


        /*
         * 総合スコア順位別
         */
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
            $actualRank,
            $scoreRank
        );


        /*
         * 評価記号別
         */
        if (
            !isset(
                $byEval[$eval]
            )
        ) {

            $byEval[$eval] =
                newBucket();
        }


        addBucket(
            $byEval[$eval],
            $actualRank
        );


        /*
         * 艇番 × 評価記号
         */
        if (
            !isset(
                $byLaneEval[$lane]
            )
        ) {

            $byLaneEval[$lane] =
                [];
        }


        if (
            !isset(
                $byLaneEval[$lane][$eval]
            )
        ) {

            $byLaneEval[$lane][$eval] =
                newBucket();
        }


        addBucket(
            $byLaneEval[$lane][$eval],
            $actualRank
        );


        /*
         * タイプ別
         */
        if (
            !isset(
                $byType[$type]
            )
        ) {

            $byType[$type] =
                newBucket();
        }


        addBucket(
            $byType[$type],
            $actualRank
        );


        /*
         * 場別
         */
        $stadiumCode =
            substr(
                $raceCode,
                8,
                3
            );


        if (
            !isset(
                $byStadium[$stadiumCode]
            )
        ) {

            $byStadium[$stadiumCode] =
                newBucket();
        }


        addBucket(
            $byStadium[$stadiumCode],
            $actualRank
        );
    }


    /*
     * 500レースごとの進捗表示
     */
    if (
        ($raceStats['processed'] % 500) === 0
    ) {

        echo sprintf(

            "[%d/%d] processed=%d skipped=%d\n",

            $i + 1,

            $totalRaces,

            $raceStats['processed'],

            $raceStats['skipped']
        );
    }
}


/*
 * ============================================================
 * 結果整形
 * ============================================================
 */


/*
 * 総合スコア順位
 */
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


/*
 * 評価記号
 */
$evalResult = [];

foreach (
    ['◎', '○', '△', '×'] as $eval
) {

    if (
        isset(
            $byEval[$eval]
        )
    ) {

        $evalResult[$eval] =
            finalizeBucket(
                $byEval[$eval]
            );
    }
}


/*
 * 艇番 × 評価記号
 */
$laneEvalResult = [];

ksort(
    $byLaneEval
);


foreach (
    $byLaneEval as $lane => $evals
) {

    $laneEvalResult[
        (string)$lane
    ] = [];


    foreach (
        ['◎', '○', '△', '×'] as $eval
    ) {

        if (
            isset(
                $evals[$eval]
            )
        ) {

            $laneEvalResult[
                (string)$lane
            ][$eval] =
                finalizeBucket(
                    $evals[$eval]
                );
        }
    }
}


/*
 * タイプ別
 */
$typeResult = [];

ksort(
    $byType
);


foreach (
    $byType as $type => $bucket
) {

    $typeResult[
        (string)$type
    ] =
        finalizeBucket(
            $bucket
        );
}


/*
 * 場別
 */
$stadiumResult = [];

ksort(
    $byStadium
);


foreach (
    $byStadium as $stadium => $bucket
) {

    $stadiumResult[
        $stadium
    ] =
        finalizeBucket(
            $bucket
        );
}


/*
 * ============================================================
 * JSONレポート
 * ============================================================
 */
$report = [

    'period' => [

        'from' =>
            $from,

        'to' =>
            $to,
    ],


    'term_rule' => [

        '01-04' =>
            '前年下期 YY10',

        '05-10' =>
            '当年上期 YY04',

        '11-12' =>
            '当年下期 YY10',
    ],


    /*
     * 実着順ルール
     */
    'result_rule' => [

        'rank_1_to_6' =>
            'race_result_detail.rank をそのまま実着順として使用',

        'rank_null' =>
            'NULL の場合は 5.5 に置き換え',

        'rank_invalid' =>
            '1～6以外の場合はデータ異常としてスキップ',

        'valid_rank_range' =>
            '1-6',
    ],


    /*
     * 集計サマリー
     */
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


    /*
     * スキップ理由
     */
    'skip_reasons' => $skipReasons,


    /*
     * 集計結果
     */
    'by_score_rank' =>
        $scoreRankResult,

    'by_eval' =>
        $evalResult,

    'by_lane_eval' =>
        $laneEvalResult,

    'by_type' =>
        $typeResult,

    'by_stadium' =>
        $stadiumResult,
];


/*
 * 出力ディレクトリ
 */
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


/*
 * 出力ファイル
 */
$outputFile =
    $outputDir .
    '/first_eval_report_' .
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


/*
 * JSON保存
 */
file_put_contents(

    $outputFile,

    json_encode(

        $report,

        JSON_UNESCAPED_UNICODE |
        JSON_PRETTY_PRINT |
        JSON_INVALID_UTF8_SUBSTITUTE
    )
);


/*
 * ============================================================
 * コンソール出力
 * ============================================================
 */

echo
    "\n========================================\n";

echo
    "一次評価 検証完了\n";

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
    "========================================\n\n";


/*
 * 総合スコア順位
 */
echo
    "【総合スコア順位】\n";


foreach (
    $scoreRankResult as $rank => $data
) {

    printf(

        "%s位: 件数=%d  1着率=%.2f%%  3連率=%.2f%%  平均着順=%.3f\n",

        $rank,

        $data['count'],

        $data['first_rate'],

        $data['top3_rate'],

        $data['avg_actual_rank']
    );
}


/*
 * 評価記号
 */
echo
    "\n【評価記号】\n";


foreach (
    $evalResult as $eval => $data
) {

    printf(

        "%s: 件数=%d  1着率=%.2f%%  3連率=%.2f%%  平均着順=%.3f\n",

        $eval,

        $data['count'],

        $data['first_rate'],

        $data['top3_rate'],

        $data['avg_actual_rank']
    );
}


/*
 * スキップ理由
 */
echo
    "\n【スキップ理由】\n";


echo
    "6艇不足             : {$skipReasons['not_6_boats']}\n";

echo
    "必須データ欠損/異常  : {$skipReasons['missing_required_data']}\n";

echo
    "着順データ異常       : {$skipReasons['invalid_rank']}\n";