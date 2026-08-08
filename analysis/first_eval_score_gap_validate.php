<?php
declare(strict_types=1);

/**
 * BoatRaceLogic - 一次評価 スコア差 検証プログラム
 *
 * 目的:
 *   現行の一次評価ロジックを再現し、
 *   「総合スコア1位と2位のスコア差」が
 *   実際の1着率・3連率・平均着順などと
 *   どのような関係にあるかを検証する。
 *
 * 本番 calc_scores.php は変更しない。
 *
 * 実行:
 *   php analysis/first_eval_score_gap_validate.php
 *
 * 任意:
 *   php analysis/first_eval_score_gap_validate.php 2026-08-01 2026-08-06
 */


/*
 * ============================================================
 * 設定
 * ============================================================
 */

const DEFAULT_FROM = '2025-08-01';
const DEFAULT_TO   = '2026-07-31';


$from = $argv[1] ?? DEFAULT_FROM;
$to   = $argv[2] ?? DEFAULT_TO;


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


/*
 * ============================================================
 * 期情報
 * ============================================================
 */

/**
 * レース日から、そのレース時点の racer_results.term_info を求める。
 *
 * 01～04月 -> 前年下期 YY10
 * 05～10月 -> 当年上期 YY04
 * 11～12月 -> 当年下期 YY10
 */
function getTermInfo(string $raceDate): string
{
    $dt = new DateTimeImmutable($raceDate);

    $year  = (int)$dt->format('Y');
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


/*
 * ============================================================
 * 一次評価計算
 * ============================================================
 */

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

    $shoritsuScore =
        min(
            10.0,
            max(
                1.0,
                $nation * 1.2
            )
        );


    $tochiScore =
        min(
            5.0,
            max(
                1.0,
                ($local - 4.0) * 2.0
            )
        );


    $motorScore =
        min(
            5.0,
            max(
                1.0,
                ($motor - 25.0) / 4.0
            )
        );


    $boatScore =
        min(
            5.0,
            max(
                1.0,
                ($boat - 25.0) / 4.0
            )
        );


    $stScore =
        min(
            5.0,
            max(
                1.0,
                8.0 - ($st * 30.0)
            )
        );


    $jiryokuScore =
        $shoritsuScore + $tochiScore;


    $ashiScore =
        $motorScore + $boatScore;


    $startScore =
        $stScore;


    $totalScore =
        $jiryokuScore +
        $ashiScore +
        $startScore;


    return [
        'total_score' => $totalScore,
    ];
}


/*
 * ============================================================
 * 着順処理
 * ============================================================
 */

/**
 * 実着順を取得。
 *
 * 公式結果DBでは4着までしか保存されないケースがあるため、
 * NULL / 空欄は「着外」として 5.5 を使用する。
 *
 * 5.5 は実際の着順ではなく、
 * 5着・6着を区別しないための集計上の値。
 */
function normalizeActualRank($value): array
{
    $text = trim((string)$value);


    if ($text === '') {

        return [
            'rank' => 5.5,
            'category' => '着外',
        ];
    }


    if (preg_match('/^[1-4]$/', $text)) {

        $rank = (float)$text;

        return [
            'rank' => $rank,
            'category' => $text . '着',
        ];
    }


    return [
        'rank' => null,
        'category' => '異常',
    ];
}


/*
 * ============================================================
 * スコア順位
 * ============================================================
 */

/**
 * 総合スコア順に並べる。
 *
 * 同点の場合は艇番の小さい方を上位とする。
 */
function assignCompetitionRanks(array &$boats): void
{
    usort(
        $boats,
        function (array $a, array $b): int {

            if ($a['total_score'] == $b['total_score']) {
                return $a['lane'] <=> $b['lane'];
            }

            return (
                $a['total_score'] > $b['total_score']
            ) ? -1 : 1;
        }
    );


    $rank = 0;
    $previousScore = null;


    foreach ($boats as $index => &$boat) {

        $position = $index + 1;


        if (
            $previousScore === null ||
            $boat['total_score'] != $previousScore
        ) {

            $rank = $position;
            $previousScore = $boat['total_score'];
        }


        $boat['score_rank'] = $rank;
    }


    unset($boat);
}


/*
 * ============================================================
 * バケット
 * ============================================================
 */

function newBucket(): array
{
    return [
        'count' => 0,
        'first' => 0,
        'top3' => 0,
        'sum_rank' => 0.0,

        /*
         * 1位艇が1着でなかった場合に、
         * 実際に誰が1着だったかを見るための集計。
         */
        'winner_score_rank_1' => 0,
        'winner_score_rank_2' => 0,
        'winner_score_rank_3' => 0,
        'winner_score_rank_4' => 0,
        'winner_score_rank_5' => 0,
        'winner_score_rank_6' => 0,
    ];
}


function addBucket(
    array &$bucket,
    float $actualRank,
    int $scoreRank
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


    $bucket['sum_rank'] += $actualRank;


    /*
     * 実際の1着艇が何位評価だったか。
     */
    if ($actualRank === 1.0) {

        $key = 'winner_score_rank_' . $scoreRank;

        if (isset($bucket[$key])) {
            $bucket[$key]++;
        }
    }
}


function finalizeBucket(array $bucket): array
{
    $count = $bucket['count'];


    $result = [
        'count' => $count,

        'first' => $bucket['first'],

        'first_rate' =>
            $count > 0
                ? round(
                    $bucket['first'] / $count * 100,
                    2
                )
                : 0.0,

        'top3' => $bucket['top3'],

        'top3_rate' =>
            $count > 0
                ? round(
                    $bucket['top3'] / $count * 100,
                    2
                )
                : 0.0,

        'avg_actual_rank' =>
            $count > 0
                ? round(
                    $bucket['sum_rank'] / $count,
                    3
                )
                : null,
    ];


    return $result;
}


/*
 * ============================================================
 * スコア差バケット
 * ============================================================
 */

function getGapBucket(float $gap): string
{
    if ($gap < 1.0) {
        return '0.0-1.0';
    }

    if ($gap < 2.0) {
        return '1.0-2.0';
    }

    if ($gap < 3.0) {
        return '2.0-3.0';
    }

    if ($gap < 5.0) {
        return '3.0-5.0';
    }

    return '5.0以上';
}


$gapBucketOrder = [
    '0.0-1.0',
    '1.0-2.0',
    '2.0-3.0',
    '3.0-5.0',
    '5.0以上',
];


function newGapRaceBucket(): array
{
    return [
        'races' => 0,

        'rank1_first' => 0,
        'rank1_top3' => 0,
        'rank1_sum_rank' => 0.0,

        'winner_rank1' => 0,
        'winner_rank2' => 0,
        'winner_rank3' => 0,
        'winner_rank4' => 0,
        'winner_rank5' => 0,
        'winner_rank6' => 0,
    ];
}


function finalizeGapRaceBucket(array $bucket): array
{
    $races = $bucket['races'];


    return [
        'races' => $races,

        'rank1_first_rate' =>
            $races > 0
                ? round(
                    $bucket['rank1_first'] /
                    $races * 100,
                    2
                )
                : 0.0,

        'rank1_top3_rate' =>
            $races > 0
                ? round(
                    $bucket['rank1_top3'] /
                    $races * 100,
                    2
                )
                : 0.0,

        'rank1_avg_actual_rank' =>
            $races > 0
                ? round(
                    $bucket['rank1_sum_rank'] /
                    $races,
                    3
                )
                : null,

        'winner_rank1_rate' =>
            $races > 0
                ? round(
                    $bucket['winner_rank1'] /
                    $races * 100,
                    2
                )
                : 0.0,

        'winner_rank2_rate' =>
            $races > 0
                ? round(
                    $bucket['winner_rank2'] /
                    $races * 100,
                    2
                )
                : 0.0,

        'winner_rank3_rate' =>
            $races > 0
                ? round(
                    $bucket['winner_rank3'] /
                    $races * 100,
                    2
                )
                : 0.0,

        'winner_rank4_rate' =>
            $races > 0
                ? round(
                    $bucket['winner_rank4'] /
                    $races * 100,
                    2
                )
                : 0.0,

        'winner_rank5_rate' =>
            $races > 0
                ? round(
                    $bucket['winner_rank5'] /
                    $races * 100,
                    2
                )
                : 0.0,

        'winner_rank6_rate' =>
            $races > 0
                ? round(
                    $bucket['winner_rank6'] /
                    $races * 100,
                    2
                )
                : 0.0,
    ];
}


/*
 * ============================================================
 * レース取得
 * ============================================================
 */

$sql = <<<SQL
SELECT DISTINCT
    re.race_code,
    re.race_date
FROM boat_race.race_entry re
INNER JOIN boat_race.race_result_detail rrd
    ON rrd.race_code = re.race_code
WHERE re.race_date BETWEEN :from_date AND :to_date
ORDER BY re.race_date, re.race_code
SQL;


$stmt = $pdo->prepare($sql);

$stmt->execute([
    ':from_date' => $from,
    ':to_date' => $to,
]);


$races = $stmt->fetchAll(PDO::FETCH_ASSOC);


$totalRaces = count($races);


echo "========================================\n";
echo "一次評価 スコア差検証開始\n";
echo "期間: {$from} ～ {$to}\n";
echo "対象レース: {$totalRaces}\n";
echo "========================================\n";


/*
 * ============================================================
 * 集計変数
 * ============================================================
 */

$processed = 0;
$skipped = 0;

$skipNot6 = 0;
$skipMissing = 0;
$skipRankInvalid = 0;


$gapStats = [];

foreach ($gapBucketOrder as $bucketName) {
    $gapStats[$bucketName] = newGapRaceBucket();
}


/*
 * 1位艇の「1位→6位」別結果
 */
$scoreRankStats = [];

for ($rank = 1; $rank <= 6; $rank++) {

    $scoreRankStats[$rank] = [
        'races' => 0,
        'first' => 0,
        'top3' => 0,
        'sum_rank' => 0.0,
    ];
}


/*
 * ============================================================
 * レースループ
 * ============================================================
 */

foreach ($races as $index => $race) {

    $raceCode = (string)$race['race_code'];
    $raceDate = (string)$race['race_date'];

    $termInfo = getTermInfo($raceDate);


    /*
     * --------------------------------------------------------
     * レースデータ取得
     * --------------------------------------------------------
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

ORDER BY re.lane_number
SQL;


    $stmtRace = $pdo->prepare($sqlRace);

    $stmtRace->execute([
        ':term_info' => $termInfo,
        ':race_code' => $raceCode,
    ]);


    $rows = $stmtRace->fetchAll(PDO::FETCH_ASSOC);


    /*
     * --------------------------------------------------------
     * 6艇確認
     * --------------------------------------------------------
     */

    if (count($rows) !== 6) {

        $skipped++;
        $skipNot6++;

        continue;
    }


    /*
     * --------------------------------------------------------
     * 各艇の一次評価
     * --------------------------------------------------------
     */

    $boats = [];
    $invalid = false;


    foreach ($rows as $row) {

        /*
         * 必須評価データ。
         *
         * NULL の場合は検証不能。
         */
        $required = [
            'national_win_rate',
            'local_win_rate',
            'motor_exacta_rate',
            'boat_exacta_rate',
            'average_start',
        ];


        foreach ($required as $key) {

            if (
                $row[$key] === null ||
                $row[$key] === ''
            ) {

                $invalid = true;

                break 2;
            }
        }


        /*
         * 実着順。
         *
         * NULL / 空欄は着外として5.5。
         */
        $actual = normalizeActualRank(
            $row['actual_rank']
        );


        if ($actual['rank'] === null) {

            $invalid = true;

            break;
        }


        $lane = (int)$row['lane_number'];


        $scores = calcFirstEval(
            $lane,
            (float)$row['national_win_rate'],
            (float)$row['local_win_rate'],
            (float)$row['motor_exacta_rate'],
            (float)$row['boat_exacta_rate'],
            (float)$row['average_start']
        );


        $boats[] = [
            'lane' => $lane,
            'player_id' => (string)$row['player_id'],
            'player_name' => (string)$row['player_name'],

            'actual_rank' => $actual['rank'],

            'total_score' =>
                $scores['total_score'],
        ];
    }


    if ($invalid || count($boats) !== 6) {

        $skipped++;
        $skipMissing++;

        continue;
    }


    /*
     * --------------------------------------------------------
     * スコア順位付与
     * --------------------------------------------------------
     */

    assignCompetitionRanks($boats);


    /*
     * --------------------------------------------------------
     * 今回は「順位1～6」が必ず1艇ずつになるようにする。
     *
     * 同点時は艇番順で並べるため、
     * 実質的には1～6位になる。
     * --------------------------------------------------------
     */

    usort(
        $boats,
        function (array $a, array $b): int {

            return $a['score_rank'] <=> $b['score_rank'];
        }
    );


    $rank1 = $boats[0];
    $rank2 = $boats[1];
    $rank6 = $boats[5];


    /*
     * --------------------------------------------------------
     * スコア差
     * --------------------------------------------------------
     */

    $gap12 =
        $rank1['total_score'] -
        $rank2['total_score'];


    $gap16 =
        $rank1['total_score'] -
        $rank6['total_score'];


    /*
     * 浮動小数点誤差対策。
     */
    if ($gap12 < 0) {
        $gap12 = 0.0;
    }


    if ($gap16 < 0) {
        $gap16 = 0.0;
    }


    $gapBucketName =
        getGapBucket($gap12);


    /*
     * --------------------------------------------------------
     * スコア差バケットへ登録
     * --------------------------------------------------------
     */

    $gapStats[$gapBucketName]['races']++;


    /*
     * 1位艇の実着順。
     */
    $rank1Actual =
        $rank1['actual_rank'];


    if ($rank1Actual === 1.0) {

        $gapStats[$gapBucketName]['rank1_first']++;
    }


    if (
        $rank1Actual >= 1.0 &&
        $rank1Actual <= 3.0
    ) {

        $gapStats[$gapBucketName]['rank1_top3']++;
    }


    $gapStats[$gapBucketName]['rank1_sum_rank'] +=
        $rank1Actual;


    /*
     * 実際の1着艇が何位評価だったか。
     */
    foreach ($boats as $boat) {

        if ($boat['actual_rank'] !== 1.0) {
            continue;
        }


        $winnerRank =
            (int)$boat['score_rank'];


        if ($winnerRank >= 1 && $winnerRank <= 6) {

            $key =
                'winner_rank' .
                $winnerRank;

            $gapStats[$gapBucketName][$key]++;
        }


        break;
    }


    /*
     * 全体のスコア順位別統計。
     *
     * こちらは「スコア差」とは別に、
     * 実際の1着率を再確認するためのもの。
     */
    foreach ($boats as $boat) {

        $rank =
            (int)$boat['score_rank'];

        $actual =
            $boat['actual_rank'];


        $scoreRankStats[$rank]['races']++;


        if ($actual === 1.0) {
            $scoreRankStats[$rank]['first']++;
        }


        if (
            $actual >= 1.0 &&
            $actual <= 3.0
        ) {

            $scoreRankStats[$rank]['top3']++;
        }


        $scoreRankStats[$rank]['sum_rank'] +=
            $actual;
    }


    $processed++;


    /*
     * 進捗表示。
     */
    if (($processed % 500) === 0) {

        echo sprintf(
            "[%d/%d] processed=%d skipped=%d\n",
            $index + 1,
            $totalRaces,
            $processed,
            $skipped
        );
    }
}


/*
 * ============================================================
 * JSON結果
 * ============================================================
 */

$gapResult = [];

foreach ($gapBucketOrder as $bucketName) {

    $gapResult[$bucketName] =
        finalizeGapRaceBucket(
            $gapStats[$bucketName]
        );
}


$scoreRankResult = [];

for ($rank = 1; $rank <= 6; $rank++) {

    $data =
        $scoreRankStats[$rank];

    $racesCount =
        $data['races'];


    $scoreRankResult[(string)$rank] = [
        'races' => $racesCount,

        'first_rate' =>
            $racesCount > 0
                ? round(
                    $data['first'] /
                    $racesCount * 100,
                    2
                )
                : 0.0,

        'top3_rate' =>
            $racesCount > 0
                ? round(
                    $data['top3'] /
                    $racesCount * 100,
                    2
                )
                : 0.0,

        'avg_actual_rank' =>
            $racesCount > 0
                ? round(
                    $data['sum_rank'] /
                    $racesCount,
                    3
                )
                : null,
    ];
}


/*
 * ============================================================
 * レポート
 * ============================================================
 */

$report = [

    'period' => [
        'from' => $from,
        'to' => $to,
    ],

    'summary' => [
        'target_races' => $totalRaces,
        'processed_races' => $processed,
        'skipped_races' => $skipped,
        'skip_not_6_boats' => $skipNot6,
        'skip_missing' => $skipMissing,
        'skip_rank_invalid' => $skipRankInvalid,
    ],

    'score_rank' =>
        $scoreRankResult,

    'gap_1st_2nd' =>
        $gapResult,
];


/*
 * ============================================================
 * JSON保存
 * ============================================================
 */

$outputDir =
    __DIR__ . '/output';


if (!is_dir($outputDir)) {

    mkdir(
        $outputDir,
        0775,
        true
    );
}


$outputFile =
    $outputDir .
    '/first_eval_score_gap_report_' .
    str_replace('-', '', $from) .
    '_' .
    str_replace('-', '', $to) .
    '.json';


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
 * コンソール表示
 * ============================================================
 */

echo "\n";
echo "========================================\n";
echo "一次評価 スコア差検証完了\n";
echo "========================================\n";

echo "対象レース       : {$totalRaces}\n";
echo "検証レース       : {$processed}\n";
echo "スキップ         : {$skipped}\n";
echo "6艇不足          : {$skipNot6}\n";
echo "必須データ欠損   : {$skipMissing}\n";
echo "着順異常         : {$skipRankInvalid}\n";

echo "結果ファイル     : {$outputFile}\n";

echo "========================================\n\n";


/*
 * ============================================================
 * 総合スコア順位
 * ============================================================
 */

echo "【総合スコア順位】\n";


foreach ($scoreRankResult as $rank => $data) {

    printf(
        "%s位: 件数=%d  1着率=%.2f%%  3連率=%.2f%%  平均着順=%.3f\n",
        $rank,
        $data['races'],
        $data['first_rate'],
        $data['top3_rate'],
        $data['avg_actual_rank']
    );
}


/*
 * ============================================================
 * 1位－2位 スコア差
 * ============================================================
 */

echo "\n";
echo "【1位－2位 スコア差別】\n";

echo
    "差             件数    1位1着率    1位3連率    1位平均着順\n";


foreach ($gapBucketOrder as $bucketName) {

    $data =
        $gapResult[$bucketName];


    printf(
        "%-12s %6d    %8.2f%%    %8.2f%%      %8.3f\n",
        $bucketName,
        $data['races'],
        $data['rank1_first_rate'],
        $data['rank1_top3_rate'],
        $data['rank1_avg_actual_rank']
    );
}


/*
 * ============================================================
 * 1位艇が1着でない場合の逆転先
 * ============================================================
 */

echo "\n";
echo "【スコア差別：実際の1着艇の評価順位】\n";

echo
    "差             1位評価    2位評価    3位評価    4位評価    5位評価    6位評価\n";


foreach ($gapBucketOrder as $bucketName) {

    $data =
        $gapResult[$bucketName];


    printf(
        "%-12s %8.2f%%  %8.2f%%  %8.2f%%  %8.2f%%  %8.2f%%  %8.2f%%\n",

        $bucketName,

        $data['winner_rank1_rate'],
        $data['winner_rank2_rate'],
        $data['winner_rank3_rate'],
        $data['winner_rank4_rate'],
        $data['winner_rank5_rate'],
        $data['winner_rank6_rate']
    );
}


echo "\n";