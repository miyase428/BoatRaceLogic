<?php
declare(strict_types=1);

/**
 * BoatRaceLogic
 * 一次評価 × 二次評価 検証プログラム
 *
 * 目的:
 *   過去レースについて、
 *
 *     一次評価順位
 *       ×
 *     二次評価順位
 *
 *   の組み合わせと実着順を比較する。
 *
 * 集計内容:
 *   ・一次評価順位 × 二次評価順位
 *   ・N
 *   ・1着率
 *   ・2着率
 *   ・3着率
 *   ・3連対率
 *   ・平均着順
 *
 * 実行:
 *   php analysis/first_second_eval_validate.php
 *
 * 任意:
 *   php analysis/first_second_eval_validate.php 2026-08-01 2026-08-06
 */

const DEFAULT_FROM = '2025-08-01';
const DEFAULT_TO   = '2026-07-31';

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


/*
 * ============================================================
 * 一次評価
 * ============================================================
 */
function calcFirstEval(
    int $lane,
    float $nation,
    float $local,
    float $motor,
    float $boat,
    float $st
): array {

    $shoritsuScore = min(
        10.0,
        max(
            1.0,
            $nation * 1.2
        )
    );

    $tochiScore = min(
        5.0,
        max(
            1.0,
            ($local - 4.0) * 2.0
        )
    );

    $motorScore = min(
        5.0,
        max(
            1.0,
            ($motor - 25.0) / 4.0
        )
    );

    $boatScore = min(
        5.0,
        max(
            1.0,
            ($boat - 25.0) / 4.0
        )
    );

    $stScore = min(
        5.0,
        max(
            1.0,
            8.0 - ($st * 30.0)
        )
    );

    $jiryokuScore =
        $shoritsuScore +
        $tochiScore;

    $ashiScore =
        $motorScore +
        $boatScore;

    $startScore =
        $stScore;

    $totalScore =
        $jiryokuScore +
        $ashiScore +
        $startScore;

    $R = $totalScore;
    $J = $jiryokuScore;
    $P = $ashiScore;
    $N = $stScore;

    if ($lane === 1) {

        if ($R >= 22) {
            $type = 4;
        } elseif ($R >= 18) {
            $type = 5;
        } else {
            $type = 6;
        }

    } elseif ($lane === 6) {

        if ($R >= 22) {
            $type = 11;
        } elseif ($R >= 18) {
            $type = 12;
        } else {
            $type = 13;
        }

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

    return [
        'total_score' => $totalScore,
        'type'        => $type,
    ];
}


/*
 * ============================================================
 * 二次評価
 * ============================================================
 */

function calcExhibitionScore(float $diff): float
{
    if ($diff <= -0.10) return 5.0;
    if ($diff <= -0.05) return 4.0;
    if ($diff <=  0.05) return 3.0;
    if ($diff <=  0.10) return 2.0;

    return 1.0;
}


function calcStScore(float $st): float
{
    if ($st <= -0.05) return 1.0;
    if ($st <  0)     return 2.0;
    if ($st <= 0.05)  return 5.0;
    if ($st <= 0.12)  return 4.0;
    if ($st <= 0.20)  return 2.0;

    return 1.0;
}


function calcLapScore(
    float $lap,
    float $avgLap
): float {

    $diff = $lap - $avgLap;

    if ($diff <= -0.30) return 5.0;
    if ($diff <= -0.10) return 4.0;
    if ($diff <=  0.10) return 3.0;
    if ($diff <=  0.30) return 2.0;

    return 1.0;
}


function calcMawariScore(
    float $mawari,
    float $avgMawari
): float {

    $diff = $mawari - $avgMawari;

    if ($diff <= -0.20) return 5.0;
    if ($diff <= -0.05) return 4.0;
    if ($diff <=  0.05) return 3.0;
    if ($diff <=  0.20) return 2.0;

    return 1.0;
}


function calcStraightScore(
    float $straight,
    float $avgStraight
): float {

    $diff = $straight - $avgStraight;

    if ($diff <= -0.04) return 5.0;
    if ($diff <= -0.01) return 4.0;
    if ($diff <=  0.01) return 3.0;
    if ($diff <=  0.04) return 2.0;

    return 1.0;
}


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
): float {

    $exScore = calcExhibitionScore(
        $exhibition - $avgExhibition
    );

    $stScore = calcStScore($st);

    $lapScore = calcLapScore(
        $lap,
        $avgLap
    );

    $mawariScore = calcMawariScore(
        $mawari,
        $avgMawari
    );

    $straightScore = calcStraightScore(
        $straight,
        $avgStraight
    );

    $exTotal =
        $exScore +
        $lapScore +
        $mawariScore +
        $straightScore;

    $attackPotential =
        $stScore +
        $straightScore;

    $stableScore =
        $lapScore +
        $mawariScore;

    $exSougou =
        $exTotal +
        $attackPotential +
        $stableScore;

    $tenkaiMorai =
        ($lane === 2 || $lane === 4)
            ? 1.0
            : 0.0;

    return
        $exSougou +
        $tenkaiMorai;
}


/*
 * ============================================================
 * 順位付与
 * ============================================================
 */

function assignRanks(
    array &$boats,
    string $scoreKey
): void {

    usort(
        $boats,
        function (array $a, array $b) use ($scoreKey): int {

            if (
                $a[$scoreKey] ==
                $b[$scoreKey]
            ) {
                return $a['lane'] <=> $b['lane'];
            }

            return
                $a[$scoreKey] >
                $b[$scoreKey]
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
            $boat[$scoreKey] != $previousScore
        ) {
            $rank = $position;
            $previousScore =
                $boat[$scoreKey];
        }

        $boat['rank_' . $scoreKey] =
            $rank;
    }

    unset($boat);
}


/*
 * ============================================================
 * 集計
 * ============================================================
 */

function newBucket(): array
{
    return [
        'count'    => 0,
        'first'    => 0,
        'second'   => 0,
        'third'    => 0,
        'top3'     => 0,
        'sum_rank' => 0.0,
    ];
}


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


/*
 * ============================================================
 * 対象レース取得
 * ============================================================
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
    ':from_date' => $from,
    ':to_date'   => $to,
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
    "一次評価 × 二次評価 検証開始\n";

echo
    "期間: {$from} ～ {$to}\n";

echo
    "対象レース: {$totalRaces}\n";

echo
    "========================================\n";


/*
 * ============================================================
 * 統計
 * ============================================================
 */

$processed = 0;
$skipped = 0;
$boatsProcessed = 0;

$skipReasons = [
    'not_6_boats'       => 0,
    'missing_first_data'=> 0,
    'missing_exhibition'=> 0,
    'missing_average'   => 0,
    'invalid_rank'      => 0,
];

$matrix = [];


/*
 * ============================================================
 * レース処理
 * ============================================================
 */

foreach ($races as $index => $race) {

    $raceCode =
        (string)$race['race_code'];

    /*
     * 出走表＋一次評価用データ＋結果
     */
    $sqlEntry = <<<SQL
SELECT
    re.lane_number AS lane,
    re.player_id,
    ps.national_win_rate,
    ps.local_win_rate,
    es.motor_exacta_rate,
    es.boat_exacta_rate,
    rr.average_start,
    rrd.rank
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
   AND rr.term_info = (
        CASE
            WHEN EXTRACT(MONTH FROM re.race_date) <= 4
                THEN TO_CHAR(re.race_date - INTERVAL '1 year', 'YY')
                     || '10'
            WHEN EXTRACT(MONTH FROM re.race_date) <= 10
                THEN TO_CHAR(re.race_date, 'YY')
                     || '04'
            ELSE
                TO_CHAR(re.race_date, 'YY')
                || '10'
        END
   )

LEFT JOIN boat_race.race_result_detail rrd
    ON rrd.race_code = re.race_code
   AND rrd.lane_number = re.lane_number

WHERE re.race_code = :race_code

ORDER BY re.lane_number
SQL;

    $stmtEntry =
        $pdo->prepare($sqlEntry);

    $stmtEntry->execute([
        ':race_code' => $raceCode,
    ]);

    $entries =
        $stmtEntry->fetchAll(
            PDO::FETCH_ASSOC
        );

    if (count($entries) !== 6) {

        $skipped++;
        $skipReasons['not_6_boats']++;
        continue;
    }


    /*
     * 一次評価
     */
    $firstBoats = [];
    $invalidFirst = false;

    foreach ($entries as $row) {

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
                $invalidFirst = true;
                break 2;
            }
        }

        $rankRaw =
            $row['rank'];

        if (
            $rankRaw === null ||
            $rankRaw === ''
        ) {
            $actualRank = 5.5;
        } elseif (
            preg_match(
                '/^[1-6]$/',
                trim((string)$rankRaw)
            )
        ) {
            $actualRank =
                (float)$rankRaw;
        } else {
            $invalidFirst = true;
            break;
        }

        $first =
            calcFirstEval(
                (int)$row['lane'],
                (float)$row['national_win_rate'],
                (float)$row['local_win_rate'],
                (float)$row['motor_exacta_rate'],
                (float)$row['boat_exacta_rate'],
                (float)$row['average_start']
            );

        $firstBoats[] = [
            'lane' =>
                (int)$row['lane'],

            'first_score' =>
                $first['total_score'],

            'actual_rank' =>
                $actualRank,
        ];
    }

    if (
        $invalidFirst ||
        count($firstBoats) !== 6
    ) {
        $skipped++;
        $skipReasons['missing_first_data']++;
        continue;
    }


    /*
     * 一次評価順位
     */
    assignRanks(
        $firstBoats,
        'first_score'
    );


    /*
     * 展示
     */
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
    ON el.race_code = re.race_code
   AND el.player_id = re.player_id

WHERE el.race_code = :race_code

ORDER BY re.lane_number
SQL;

    $stmtExhibition =
        $pdo->prepare(
            $sqlExhibition
        );

    $stmtExhibition->execute([
        ':race_code' => $raceCode,
    ]);

    $exhibitions =
        $stmtExhibition->fetchAll(
            PDO::FETCH_ASSOC
        );

    if (count($exhibitions) !== 6) {

        $skipped++;
        $skipReasons['missing_exhibition']++;
        continue;
    }


    /*
     * 場コード
     */
    $jyo =
        substr(
            $raceCode,
            8,
            3
        );


    /*
     * 場名
     */
    $sqlName = <<<SQL
SELECT stadium_name
FROM boat_race.stadium_master
WHERE stadium_code = :jyo
LIMIT 1
SQL;

    $stmtName =
        $pdo->prepare(
            $sqlName
        );

    $stmtName->execute([
        ':jyo' => $jyo,
    ]);

    $stadiumName =
        $stmtName->fetchColumn();

    if (!$stadiumName) {

        $skipped++;
        $skipReasons['missing_average']++;
        continue;
    }


    /*
     * 6ヶ月平均
     */
    $sqlAvg = <<<SQL
SELECT avg_exhibition_time_6m
FROM boat_race.exhibition_avg_6m
WHERE stadium_name = :stadium_name
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

    if ($avgExhibition <= 0) {

        $skipped++;
        $skipReasons['missing_average']++;
        continue;
    }


    /*
     * 6艇平均
     */
    $lapValues = [];
    $mawariValues = [];
    $straightValues = [];

    foreach ($exhibitions as $row) {

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


    /*
     * 二次評価
     */
    $secondBoats = [];

    foreach ($exhibitions as $row) {

        $lane =
            (int)$row['lane'];

        $secondScore =
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

        $secondBoats[] = [
            'lane' =>
                $lane,

            'second_score' =>
                $secondScore,
        ];
    }


    /*
     * 二次評価順位
     */
    assignRanks(
        $secondBoats,
        'second_score'
    );


    /*
     * lane → 情報
     */
    $firstByLane = [];

    foreach ($firstBoats as $boat) {

        $firstByLane[
            $boat['lane']
        ] = $boat;
    }


    $secondByLane = [];

    foreach ($secondBoats as $boat) {

        $secondByLane[
            $boat['lane']
        ] = $boat;
    }


    /*
     * 36組み合わせ集計
     */
    foreach (range(1, 6) as $lane) {

        $firstRank =
            (int)$firstByLane[$lane]['rank_first_score'];

        $secondRank =
            (int)$secondByLane[$lane]['rank_second_score'];

        $actualRank =
            (float)$firstByLane[$lane]['actual_rank'];

        if (
            !isset(
                $matrix[$firstRank]
            )
        ) {
            $matrix[$firstRank] = [];
        }

        if (
            !isset(
                $matrix[$firstRank][$secondRank]
            )
        ) {
            $matrix[$firstRank][$secondRank] =
                newBucket();
        }

        addBucket(
            $matrix[$firstRank][$secondRank],
            $actualRank
        );

        $boatsProcessed++;
    }

    $processed++;


    /*
     * 500レースごとの進捗
     */
    if (
        ($processed % 500) === 0
    ) {
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
 * 結果
 * ============================================================
 */

echo "\n";
echo "========================================\n";
echo "検証結果\n";
echo "========================================\n";

echo
    "処理レース: {$processed}\n";

echo
    "スキップレース: {$skipped}\n";

echo
    "処理艇数: {$boatsProcessed}\n";

echo "\n";

echo "【スキップ理由】\n";

foreach (
    $skipReasons as $reason => $count
) {
    echo sprintf(
        "%-24s : %d\n",
        $reason,
        $count
    );
}


echo "\n";
echo "========================================\n";
echo "【一次評価順位 × 二次評価順位】\n";
echo "========================================\n";


for ($firstRank = 1; $firstRank <= 6; $firstRank++) {

    echo "\n";
    echo "【一次評価 {$firstRank}位】\n";

    for (
        $secondRank = 1;
        $secondRank <= 6;
        $secondRank++
    ) {

        if (
            !isset(
                $matrix[$firstRank][$secondRank]
            )
        ) {
            continue;
        }

        $result =
            finalizeBucket(
                $matrix[$firstRank][$secondRank]
            );

        printf(
            "  二次%1d位 : "
            . "N=%4d "
            . "1着=%3d(%6.2f%%) "
            . "2着=%3d(%6.2f%%) "
            . "3着=%3d(%6.2f%%) "
            . "3連対=%3d(%6.2f%%) "
            . "平均着順=%.3f\n",

            $secondRank,

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
}


echo "\n";
echo "========================================\n";
echo "一次評価 × 二次評価 検証終了\n";
echo "========================================\n";