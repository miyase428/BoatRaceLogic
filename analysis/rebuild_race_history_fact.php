<?php
declare(strict_types=1);

/**
 * Webの確率系計算で毎回全履歴JOINを行わないための履歴Fact再構築。
 *
 * 1レース=1行で以下を保持する。
 * - trifecta_valid       : 現行TrifectaProbabilityLogicのtop3_rows条件を満たす
 * - course_valid         : 現行AiTrioRateLogicの6艇course_rows条件を満たす
 * - head1_prior_valid    : 現行Head1SecondPlaceLogicの場/全場2着率母集団条件を満たす
 * - head1_player_eligible: 現行Head1SecondPlaceLogicの選手直近100走用「1号艇1着」条件を満たす
 * - winner_lane          : 1着艇の枠番
 * - c1/c2/c3             : 実進入コース基準の1～3着コース
 *
 * 元データの結合・進入fallback順は現行本番ロジックと同じ：
 * race_entry -> race_result_detail(player_id一致)
 * -> result_detail.entry_course
 * -> exhibition_live.entry_course
 * -> race_entry.lane_number
 *
 * Usage:
 *   php analysis/rebuild_race_history_fact.php
 */

require_once __DIR__ . '/../common/db_connect.php';

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$table = 'boat_race.race_history_fact';

$createSql = <<<SQL
CREATE TABLE IF NOT EXISTS {$table} (
    race_code              text PRIMARY KEY,
    race_date              date NOT NULL,
    place_code             varchar(3) NOT NULL,
    winner_lane            smallint,
    c1                     smallint,
    c2                     smallint,
    c3                     smallint,
    trifecta_valid         boolean NOT NULL DEFAULT false,
    course_valid           boolean NOT NULL DEFAULT false,
    head1_prior_valid      boolean NOT NULL DEFAULT false,
    head1_player_eligible  boolean NOT NULL DEFAULT false,
    rebuilt_at             timestamptz NOT NULL DEFAULT now()
)
SQL;

$alterSqls = [
    "ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS winner_lane smallint",
    "ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS head1_prior_valid boolean NOT NULL DEFAULT false",
    "ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS head1_player_eligible boolean NOT NULL DEFAULT false",
];

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

$indexes = [
    "CREATE INDEX IF NOT EXISTS idx_race_history_fact_date_code ON {$table} (race_date, race_code)",
    "CREATE INDEX IF NOT EXISTS idx_race_history_fact_place_date_code ON {$table} (place_code, race_date, race_code)",
    "CREATE INDEX IF NOT EXISTS idx_race_history_fact_trifecta_date ON {$table} (race_date, race_code) WHERE trifecta_valid",
    "CREATE INDEX IF NOT EXISTS idx_race_history_fact_course_date ON {$table} (race_date, race_code) WHERE course_valid",
    "CREATE INDEX IF NOT EXISTS idx_race_history_fact_head1_prior_date ON {$table} (race_date, race_code) WHERE head1_prior_valid",
];

echo str_repeat('=', 86) . PHP_EOL;
echo "Web確率計算用 race_history_fact 再構築" . PHP_EOL;
echo str_repeat('=', 86) . PHP_EOL;

echo "テーブル準備...\n";
$pdo->exec($createSql);
foreach ($alterSqls as $sql) {
    $pdo->exec($sql);
}

$t0 = hrtime(true);
try {
    $pdo->beginTransaction();
    $pdo->exec("TRUNCATE TABLE {$table}");
    echo "全履歴を1レース1行へ集約中...（初回は時間がかかります）\n";
    $inserted = $pdo->exec($insertSql);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}
$buildMs = (hrtime(true) - $t0) / 1_000_000.0;

foreach ($indexes as $sql) {
    $pdo->exec($sql);
}
$pdo->exec("ANALYZE {$table}");

$stats = $pdo->query(<<<SQL
SELECT
    COUNT(*) AS all_races,
    COUNT(*) FILTER (WHERE trifecta_valid) AS trifecta_valid_races,
    COUNT(*) FILTER (WHERE course_valid) AS course_valid_races,
    COUNT(*) FILTER (WHERE head1_prior_valid) AS head1_prior_races,
    COUNT(*) FILTER (WHERE head1_player_eligible) AS head1_player_races,
    MIN(race_date) AS min_date,
    MAX(race_date) AS max_date
FROM {$table}
SQL)->fetch(PDO::FETCH_ASSOC);

printf("構築時間       : %.1f 秒\n", $buildMs / 1000.0);
printf("INSERT行数     : %d\n", (int)$inserted);
printf("全レース       : %d\n", (int)($stats['all_races'] ?? 0));
printf("3連単有効R     : %d\n", (int)($stats['trifecta_valid_races'] ?? 0));
printf("6艇コース有効R : %d\n", (int)($stats['course_valid_races'] ?? 0));
printf("1号艇1着prior R: %d\n", (int)($stats['head1_prior_races'] ?? 0));
printf("1号艇1着選手R  : %d\n", (int)($stats['head1_player_races'] ?? 0));
printf("期間           : %s ～ %s\n", (string)($stats['min_date'] ?? '-'), (string)($stats['max_date'] ?? '-'));
echo str_repeat('=', 86) . PHP_EOL;
