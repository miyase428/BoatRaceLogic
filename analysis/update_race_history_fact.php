<?php
declare(strict_types=1);

/**
 * race_history_fact の日次差分更新。
 *
 * 直近N日を一度削除して、現行 rebuild_race_history_fact.php と同じ定義で再UPSERTする。
 * 結果・展示の遅着や当日途中更新も拾えるよう、デフォルトは7日戻して再計算する。
 *
 * Usage:
 *   php analysis/update_race_history_fact.php
 *   php analysis/update_race_history_fact.php 7
 */

require_once __DIR__ . '/../common/db_connect.php';

$lookbackDays = isset($argv[1]) ? max(1, (int)$argv[1]) : 7;
$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$table = 'boat_race.race_history_fact';

$exists = $pdo->query("SELECT to_regclass('{$table}')")->fetchColumn();
if (!$exists) {
    fwrite(STDERR, "race_history_fact がありません。先に php analysis/rebuild_race_history_fact.php を実行してください。\n");
    exit(1);
}

$maxFactDate = $pdo->query("SELECT MAX(race_date) FROM {$table}")->fetchColumn();
if (!$maxFactDate) {
    fwrite(STDERR, "race_history_fact が空です。先にフル再構築してください。\n");
    exit(1);
}

$factDate = new DateTimeImmutable((string)$maxFactDate);
$startDate = $factDate->modify('-' . ($lookbackDays - 1) . ' days')->format('Y-m-d');

$sourceMaxDate = $pdo->query(<<<SQL
SELECT MAX(rm.race_date)
FROM boat_race.race_entry re
JOIN boat_race.race_master rm ON rm.race_code = re.race_code
SQL)->fetchColumn();

$insertSql = <<<SQL
WITH course_rows AS (
    SELECT
        re.race_code,
        rm.race_date,
        SUBSTRING(re.race_code, 9, 3) AS place_code,
        re.lane_number,
        CASE
            WHEN rrd.rank::text ~ '^[1-6]$' THEN rrd.rank::int
            ELSE NULL
        END AS rank_num,
        COALESCE(
            CASE
                WHEN rrd.entry_course::text ~ '^[1-6]$' THEN rrd.entry_course::int
                ELSE NULL
            END,
            CASE
                WHEN el.entry_course::text ~ '^[1-6]$' THEN el.entry_course::int
                ELSE NULL
            END,
            CASE
                WHEN re.lane_number::text ~ '^[1-6]$' THEN re.lane_number::int
                ELSE NULL
            END
        ) AS actual_course
    FROM boat_race.race_entry re
    JOIN boat_race.race_master rm
      ON rm.race_code = re.race_code
    LEFT JOIN boat_race.race_result_detail rrd
      ON rrd.race_code = re.race_code
     AND rrd.player_id = re.player_id
    LEFT JOIN LATERAL (
        SELECT x.entry_course
        FROM boat_race.exhibition_live x
        WHERE x.race_code = re.race_code
          AND x.player_id = re.player_id
        LIMIT 1
    ) el ON TRUE
    WHERE rm.race_date >= ?::date
),
race_rows AS (
    SELECT
        race_code,
        race_date,
        place_code,
        COUNT(*) AS row_n,
        COUNT(DISTINCT lane_number) AS lane_n,
        MIN(lane_number::int) AS min_lane,
        MAX(lane_number::int) AS max_lane,
        COUNT(*) FILTER (WHERE rank_num BETWEEN 1 AND 3) AS top3_row_n,
        COUNT(DISTINCT rank_num) FILTER (WHERE rank_num BETWEEN 1 AND 3) AS top3_rank_n,
        COUNT(DISTINCT actual_course) FILTER (WHERE rank_num BETWEEN 1 AND 3) AS top3_course_n,
        COUNT(*) FILTER (WHERE rank_num = 1) AS rank1_n,
        COUNT(*) FILTER (WHERE rank_num = 2) AS rank2_n,
        COUNT(*) FILTER (WHERE rank_num = 3) AS rank3_n,
        MIN(lane_number::int) FILTER (WHERE rank_num = 1) AS winner_lane,
        COUNT(*) FILTER (WHERE actual_course BETWEEN 1 AND 6) AS valid_course_n,
        COUNT(DISTINCT actual_course) AS course_n,
        MIN(actual_course) AS min_course,
        MAX(actual_course) AS max_course,
        MAX(actual_course) FILTER (WHERE rank_num = 1) AS c1,
        MAX(actual_course) FILTER (WHERE rank_num = 2) AS c2,
        MAX(actual_course) FILTER (WHERE rank_num = 3) AS c3
    FROM course_rows
    GROUP BY race_code, race_date, place_code
)
INSERT INTO {$table} (
    race_code,
    race_date,
    place_code,
    winner_lane,
    c1,
    c2,
    c3,
    trifecta_valid,
    course_valid,
    winner_valid,
    head1_prior_valid,
    head1_player_eligible,
    rebuilt_at
)
SELECT
    race_code,
    race_date,
    place_code,
    winner_lane,
    c1,
    c2,
    c3,
    (
        top3_row_n = 3
        AND top3_rank_n = 3
        AND top3_course_n = 3
        AND c1 BETWEEN 1 AND 6
        AND c2 BETWEEN 1 AND 6
        AND c3 BETWEEN 1 AND 6
    ) AS trifecta_valid,
    (
        row_n = 6
        AND lane_n = 6
        AND rank1_n = 1
        AND rank2_n = 1
        AND rank3_n = 1
        AND valid_course_n = 6
        AND course_n = 6
    ) AS course_valid,
    (
        rank1_n = 1
        AND c1 BETWEEN 1 AND 6
    ) AS winner_valid,
    (
        row_n = 6
        AND lane_n = 6
        AND min_lane = 1
        AND max_lane = 6
        AND rank1_n = 1
        AND rank2_n = 1
        AND winner_lane = 1
        AND valid_course_n = 6
        AND course_n = 6
        AND min_course = 1
        AND max_course = 6
        AND c2 BETWEEN 1 AND 6
    ) AS head1_prior_valid,
    (
        row_n = 6
        AND lane_n = 6
        AND min_lane = 1
        AND max_lane = 6
        AND rank1_n = 1
        AND rank2_n = 1
        AND winner_lane = 1
    ) AS head1_player_eligible,
    now()
FROM race_rows
SQL;

echo str_repeat('=', 86) . PHP_EOL;
echo "race_history_fact 差分更新" . PHP_EOL;
echo str_repeat('=', 86) . PHP_EOL;
echo "lookback      : {$lookbackDays}日\n";
echo "更新開始日    : {$startDate}\n";
echo "元データ最終日: " . ($sourceMaxDate ?: '-') . PHP_EOL;

$t0 = hrtime(true);
try {
    $pdo->beginTransaction();

    $delete = $pdo->prepare("DELETE FROM {$table} WHERE race_date >= ?::date");
    $delete->execute([$startDate]);
    $deleted = $delete->rowCount();

    $insert = $pdo->prepare($insertSql);
    $insert->execute([$startDate]);
    $inserted = $insert->rowCount();

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

$pdo->exec("ANALYZE {$table}");
$elapsed = (hrtime(true) - $t0) / 1_000_000_000.0;

$stats = $pdo->query(<<<SQL
SELECT
    COUNT(*) AS all_races,
    MAX(race_date) AS max_date,
    COUNT(*) FILTER (WHERE winner_valid) AS winner_valid_races,
    COUNT(*) FILTER (WHERE trifecta_valid) AS trifecta_valid_races,
    COUNT(*) FILTER (WHERE course_valid) AS course_valid_races
FROM {$table}
SQL)->fetch(PDO::FETCH_ASSOC);

printf("削除行数      : %d\n", $deleted);
printf("再作成行数    : %d\n", $inserted);
printf("更新時間      : %.2f 秒\n", $elapsed);
printf("Fact全体      : %d R / 最終日 %s\n", (int)($stats['all_races'] ?? 0), (string)($stats['max_date'] ?? '-'));
printf("winner/trifecta/course : %d / %d / %d\n",
    (int)($stats['winner_valid_races'] ?? 0),
    (int)($stats['trifecta_valid_races'] ?? 0),
    (int)($stats['course_valid_races'] ?? 0)
);
echo str_repeat('=', 86) . PHP_EOL;
