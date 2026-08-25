<?php

declare(strict_types=1);

/**
 * 展示履歴の場別カバレッジ診断。
 *
 * Usage:
 *   php analysis/diagnose_exhibition_history_coverage.php 2025-08-15 2026-08-14
 *
 * 確認内容:
 * - race_entry 側の対象レース数（場別）
 * - exhibition_live に6艇分揃っているレース数（場別）
 * - exhibition_live の最古/最新日
 * - boat_race スキーマ内の展示関連テーブル一覧
 */

require_once __DIR__ . '/../common/db_connect.php';

$from = $argv[1] ?? '2025-08-15';
$to   = $argv[2] ?? '2026-08-14';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)
    || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)
    || $from > $to) {
    fwrite(STDERR, "使用方法: php {$argv[0]} YYYY-MM-DD YYYY-MM-DD\n");
    exit(1);
}

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$affinityPath = __DIR__ . '/../config/stadium_affinity.json';
$affinityJson = is_file($affinityPath) ? file_get_contents($affinityPath) : false;
$affinity = is_string($affinityJson) ? json_decode($affinityJson, true) : null;
$nameByCode = [];
if (is_array($affinity)) {
    foreach (($affinity['stadiums'] ?? []) as $code => $row) {
        if (is_array($row)) {
            $nameByCode[(string)$code] = trim((string)($row['name'] ?? $code));
        }
    }
}

$sql = <<<SQL
WITH base AS (
    SELECT
        SUBSTRING(re.race_code, 9, 3) AS place_code,
        re.race_code,
        re.player_id,
        re.race_date
    FROM boat_race.race_entry re
    WHERE re.race_date BETWEEN :from_date AND :to_date
),
base_races AS (
    SELECT
        place_code,
        COUNT(DISTINCT race_code) AS base_races
    FROM base
    GROUP BY place_code
),
ex_rows AS (
    SELECT
        b.place_code,
        b.race_code,
        COUNT(*) FILTER (
            WHERE el.player_id IS NOT NULL
              AND el.exhibition_time IS NOT NULL
              AND el.start_timing IS NOT NULL
              AND el.lap_time IS NOT NULL
              AND el.around_time IS NOT NULL
        ) AS usable_rows,
        COUNT(*) FILTER (
            WHERE el.player_id IS NOT NULL
              AND el.exhibition_time IS NOT NULL
              AND el.start_timing IS NOT NULL
              AND el.lap_time IS NOT NULL
              AND el.around_time IS NOT NULL
              AND el.straight_time IS NOT NULL
        ) AS full_rows
    FROM base b
    LEFT JOIN boat_race.exhibition_live el
      ON el.race_code = b.race_code
     AND el.player_id = b.player_id
    GROUP BY b.place_code, b.race_code
),
ex_races AS (
    SELECT
        place_code,
        COUNT(*) FILTER (WHERE usable_rows = 6) AS usable6_races,
        COUNT(*) FILTER (WHERE full_rows = 6) AS full6_races
    FROM ex_rows
    GROUP BY place_code
)
SELECT
    br.place_code,
    br.base_races,
    COALESCE(er.usable6_races, 0) AS usable6_races,
    COALESCE(er.full6_races, 0) AS full6_races
FROM base_races br
LEFT JOIN ex_races er USING (place_code)
ORDER BY br.place_code
SQL;

$stmt = $pdo->prepare($sql);
$stmt->execute([':from_date' => $from, ':to_date' => $to]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo str_repeat('=', 94) . PHP_EOL;
echo "展示履歴カバレッジ診断" . PHP_EOL;
echo str_repeat('=', 94) . PHP_EOL;
echo "期間: {$from} ～ {$to}" . PHP_EOL;
echo "usable6 = 展示/ST/周回/周り足が6艇完備" . PHP_EOL;
echo "full6   = usable6 + 直線も6艇完備" . PHP_EOL;
echo str_repeat('-', 94) . PHP_EOL;
printf("%-4s %-10s %10s %12s %12s %10s\n", '場', '場名', '出走R', 'usable6', 'full6', 'full6率');
echo str_repeat('-', 94) . PHP_EOL;

$totalBase = 0;
$totalUsable = 0;
$totalFull = 0;
$coveredUsable = 0;
$coveredFull = 0;

foreach ($rows as $row) {
    $code = (string)$row['place_code'];
    $base = (int)$row['base_races'];
    $usable = (int)$row['usable6_races'];
    $full = (int)$row['full6_races'];
    $rate = $base > 0 ? $full / $base * 100.0 : 0.0;
    $name = $nameByCode[$code] ?? '-';

    $totalBase += $base;
    $totalUsable += $usable;
    $totalFull += $full;
    if ($usable > 0) $coveredUsable++;
    if ($full > 0) $coveredFull++;

    printf(
        "%-4s %-10s %10d %12d %12d %9.2f%%\n",
        $code,
        $name,
        $base,
        $usable,
        $full,
        $rate
    );
}

echo str_repeat('-', 94) . PHP_EOL;
printf(
    "合計              %10d %12d %12d %9.2f%%\n",
    $totalBase,
    $totalUsable,
    $totalFull,
    $totalBase > 0 ? $totalFull / $totalBase * 100.0 : 0.0
);
echo "usable6 が1R以上ある場: {$coveredUsable}場 / full6 が1R以上ある場: {$coveredFull}場" . PHP_EOL;

$rangeSql = <<<SQL
SELECT
    MIN(re.race_date) AS min_date,
    MAX(re.race_date) AS max_date,
    COUNT(DISTINCT el.race_code) AS race_count,
    COUNT(*) AS row_count
FROM boat_race.exhibition_live el
LEFT JOIN boat_race.race_entry re
  ON re.race_code = el.race_code
 AND re.player_id = el.player_id
SQL;
$range = $pdo->query($rangeSql)->fetch(PDO::FETCH_ASSOC) ?: [];

echo PHP_EOL . "【exhibition_live 全体】" . PHP_EOL;
echo "最古日: " . ($range['min_date'] ?? '-') . PHP_EOL;
echo "最新日: " . ($range['max_date'] ?? '-') . PHP_EOL;
echo "レース数: " . number_format((int)($range['race_count'] ?? 0)) . PHP_EOL;
echo "行数: " . number_format((int)($range['row_count'] ?? 0)) . PHP_EOL;

$tableSql = <<<SQL
SELECT table_name
FROM information_schema.tables
WHERE table_schema = 'boat_race'
  AND (
      table_name ILIKE '%exhibition%'
      OR table_name ILIKE '%tenji%'
      OR table_name ILIKE '%display%'
  )
ORDER BY table_name
SQL;
$tables = $pdo->query($tableSql)->fetchAll(PDO::FETCH_COLUMN);

echo PHP_EOL . "【boat_race 内の展示関連テーブル候補】" . PHP_EOL;
if (!$tables) {
    echo "（該当なし）" . PHP_EOL;
} else {
    foreach ($tables as $table) {
        echo "- {$table}" . PHP_EOL;
    }
}

echo PHP_EOL . "判断:" . PHP_EOL;
echo "- 24場すべてで usable6/full6 がある → exporter側の集計ロジックを修正" . PHP_EOL;
echo "- 8場前後しかデータがない → 別の展示履歴テーブル/履歴CSVを探して切替" . PHP_EOL;
