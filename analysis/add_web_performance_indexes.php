<?php
declare(strict_types=1);

/**
 * Web表示高速化用インデックス追加。
 *
 * 既存ロジック・データは変更せず、検索/JOINに使う索引だけを追加する。
 * CREATE INDEX CONCURRENTLY を使うため、トランザクション外で1本ずつ作成する。
 *
 * Usage:
 *   php analysis/add_web_performance_indexes.php
 */

require_once __DIR__ . '/../common/db_connect.php';

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$indexes = [
    // BaseWinRateLogic / AiTrioRateLogic の player_id::text = ? + race_code 順検索。
    [
        'name' => 'idx_re_player_text_race',
        'sql' => "CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_re_player_text_race
                  ON boat_race.race_entry ((player_id::text), race_code)",
    ],

    // race_entry -> race_result_detail の race_code + player_id JOIN。
    [
        'name' => 'idx_rrd_race_player',
        'sql' => "CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_rrd_race_player
                  ON boat_race.race_result_detail (race_code, player_id)",
    ],

    // race_entry -> exhibition_live の race_code + player_id LATERAL検索。
    [
        'name' => 'idx_el_race_player',
        'sql' => "CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_el_race_player
                  ON boat_race.exhibition_live (race_code, player_id)",
    ],

    // 日付 + race_code の時系列条件。
    [
        'name' => 'idx_rm_date_race',
        'sql' => "CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_rm_date_race
                  ON boat_race.race_master (race_date, race_code)",
    ],

    // CorrectedWinRateLogic の場別全履歴取得。
    [
        'name' => 'idx_re_place_race_lane',
        'sql' => "CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_re_place_race_lane
                  ON boat_race.race_entry ((SUBSTRING(race_code, 9, 3)), race_code, lane_number)",
    ],

    // BaseWinRateLogic の場×勝者コース集計。
    [
        'name' => 'idx_rrd_winner_place_race',
        'sql' => "CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_rrd_winner_place_race
                  ON boat_race.race_result_detail ((SUBSTRING(race_code, 9, 3)), race_code)
                  INCLUDE (entry_course)
                  WHERE rank = '1'",
    ],
];

echo str_repeat('=', 88) . PHP_EOL;
echo "Web表示高速化 インデックス追加" . PHP_EOL;
echo str_repeat('=', 88) . PHP_EOL;
echo "※ データ/予想ロジックは変更しません。索引作成中はCPU/IO負荷が上がることがあります。\n\n";

$totalStart = hrtime(true);
foreach ($indexes as $i => $item) {
    $name = $item['name'];
    $sql = $item['sql'];
    $start = hrtime(true);

    printf("[%d/%d] %-32s ... ", $i + 1, count($indexes), $name);
    fflush(STDOUT);

    try {
        $pdo->exec($sql);
        $sec = (hrtime(true) - $start) / 1_000_000_000.0;
        printf("OK  %.2f sec\n", $sec);
    } catch (Throwable $e) {
        $sec = (hrtime(true) - $start) / 1_000_000_000.0;
        printf("ERROR %.2f sec\n", $sec);
        fwrite(STDERR, "  " . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

// planner統計を更新。
echo "\nANALYZE ...\n";
foreach ([
    'boat_race.race_entry',
    'boat_race.race_result_detail',
    'boat_race.exhibition_live',
    'boat_race.race_master',
] as $table) {
    $pdo->exec("ANALYZE {$table}");
}

$totalSec = (hrtime(true) - $totalStart) / 1_000_000_000.0;
printf("\n完了: %.2f sec\n", $totalSec);
echo str_repeat('=', 88) . PHP_EOL;
