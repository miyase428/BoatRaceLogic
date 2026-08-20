<?php
declare(strict_types=1);

/**
 * race_history_fact が現行Web条件と同じ母集団を返すか確認する軽量チェック。
 * 旧巨大JOINは再実行しない。
 *
 * Usage:
 *   php analysis/check_race_history_fact.php 20260820OMR01
 */

require_once __DIR__ . '/../common/db_connect.php';

$raceCode = strtoupper(trim((string)($argv[1] ?? '')));
if (!preg_match('/^(\d{8})([A-Z0-9]{3})(\d{2})$/', $raceCode, $m)) {
    fwrite(STDERR, "Usage: php analysis/check_race_history_fact.php YYYYMMDDXXXRR\n");
    exit(1);
}

$targetDate = DateTimeImmutable::createFromFormat('!Ymd', $m[1]);
if (!$targetDate) {
    fwrite(STDERR, "日付解析エラー\n");
    exit(1);
}
$targetDateText = $targetDate->format('Y-m-d');
$placeCode = $m[2];
$pdo = getPDO();

$table = 'boat_race.race_history_fact';

$exists = $pdo->query("SELECT to_regclass('{$table}')")->fetchColumn();
if (!$exists) {
    fwrite(STDERR, "{$table} がありません。先に rebuild_race_history_fact.php を実行してください。\n");
    exit(1);
}

$params = [$targetDateText, $targetDateText, $raceCode];

$t0 = hrtime(true);
$stmt = $pdo->prepare(<<<SQL
SELECT
    COUNT(*) AS races,
    COUNT(DISTINCT place_code || '-' || c1 || '-' || c2 || '-' || c3) AS grouped
FROM {$table}
WHERE trifecta_valid
  AND (
        race_date < ?::date
        OR (race_date = ?::date AND race_code < ?)
      )
SQL);
$stmt->execute($params);
$trifectaSummary = $stmt->fetch(PDO::FETCH_ASSOC);
$trifectaSummaryMs = (hrtime(true) - $t0) / 1_000_000.0;

$t0 = hrtime(true);
$stmt = $pdo->prepare(<<<SQL
SELECT
    c1,
    c2,
    c3,
    COUNT(*) AS global_n,
    COUNT(*) FILTER (WHERE place_code = ?) AS venue_n
FROM {$table}
WHERE trifecta_valid
  AND (
        race_date < ?::date
        OR (race_date = ?::date AND race_code < ?)
      )
GROUP BY c1, c2, c3
ORDER BY c1, c2, c3
SQL);
$stmt->execute([$placeCode, ...$params]);
$patternRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$patternMs = (hrtime(true) - $t0) / 1_000_000.0;

$t0 = hrtime(true);
$stmt = $pdo->prepare(<<<SQL
WITH base AS (
    SELECT c1, c2, c3, place_code
    FROM {$table}
    WHERE course_valid
      AND (
            race_date < ?::date
            OR (race_date = ?::date AND race_code < ?)
          )
),
courses AS (
    SELECT generate_series(1, 6)::int AS course
)
SELECT
    c.course,
    COUNT(*) AS global_n,
    COUNT(*) FILTER (WHERE b.place_code = ?) AS venue_n,
    COUNT(*) FILTER (WHERE c.course IN (b.c1, b.c2, b.c3)) AS global_top3,
    COUNT(*) FILTER (
        WHERE b.place_code = ?
          AND c.course IN (b.c1, b.c2, b.c3)
    ) AS venue_top3
FROM base b
CROSS JOIN courses c
GROUP BY c.course
ORDER BY c.course
SQL);
$stmt->execute([$targetDateText, $targetDateText, $raceCode, $placeCode, $placeCode]);
$courseRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$courseMs = (hrtime(true) - $t0) / 1_000_000.0;

echo str_repeat('=', 90) . PHP_EOL;
echo "race_history_fact 軽量参照チェック" . PHP_EOL;
echo str_repeat('=', 90) . PHP_EOL;
echo "race_code : {$raceCode}\n";
echo "target    : {$targetDateText} / {$placeCode}\n\n";
printf(
    "3連単母集団 : races=%d / grouped=%d / %.1f ms\n",
    (int)($trifectaSummary['races'] ?? 0),
    (int)($trifectaSummary['grouped'] ?? 0),
    $trifectaSummaryMs
);
printf("3連単120集計: rows=%d / %.1f ms\n", count($patternRows), $patternMs);
printf("AI3連対prior: rows=%d / %.1f ms\n", count($courseRows), $courseMs);

echo "\n【AI3連対 prior】\n";
echo "C   global n/top3(rate)        venue n/top3(rate)\n";
echo str_repeat('-', 70) . PHP_EOL;
foreach ($courseRows as $row) {
    $gn = (int)$row['global_n'];
    $gt = (int)$row['global_top3'];
    $vn = (int)$row['venue_n'];
    $vt = (int)$row['venue_top3'];
    $gr = $gn > 0 ? $gt / $gn * 100.0 : 0.0;
    $vr = $vn > 0 ? $vt / $vn * 100.0 : 0.0;
    printf(
        "%dC  %7d/%7d (%6.2f%%)    %7d/%7d (%6.2f%%)\n",
        (int)$row['course'],
        $gt,
        $gn,
        $gr,
        $vt,
        $vn,
        $vr
    );
}

echo "\n参考: 直前ベンチのOLDが同じrace_codeなら、3連単母集団racesがOLDのracesと一致することを確認。\n";
echo "      20260820OMR01 の直前結果では OLD races=662570 / grouped=2880。\n";
echo str_repeat('=', 90) . PHP_EOL;
