<?php
declare(strict_types=1);

/**
 * Web高速化の残り候補を一括計測する。
 *
 * 1) RecentCourseTrioRateLogic:
 *    race_master JOIN + race_date条件を、固定形式race_codeの範囲条件へ置換して比較。
 * 2) CorrectedWinRateLogic venue_exhibition_average:
 *    race_master JOINを外し、race_code範囲だけで同一定義を比較。
 * 3) tenji_test.php:
 *    127.0.0.1 / LAN IP のHTTPタイミングとローカル本体の静的ホットスポットを表示。
 *
 * Usage:
 *   php analysis/remaining_web_bottleneck_benchmark.php 20260818SME12
 */

require_once __DIR__ . '/../common/db_connect.php';

$raceCode = strtoupper(trim((string)($argv[1] ?? '')));
if (!preg_match('/^\d{8}[A-Z0-9]{3}\d{2}$/', $raceCode)) {
    fwrite(STDERR, "Usage: php analysis/remaining_web_bottleneck_benchmark.php YYYYMMDDXXXRR\n");
    exit(1);
}

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$placeCode = substr($raceCode, 8, 3);

$targetStmt = $pdo->prepare(<<<SQL
SELECT
    rm.race_date,
    re.lane_number,
    re.player_id::text AS player_id,
    COALESCE(
        CASE WHEN el.entry_course BETWEEN 1 AND 6 THEN el.entry_course ELSE NULL END,
        re.lane_number
    ) AS target_course
FROM boat_race.race_entry re
JOIN boat_race.race_master rm ON rm.race_code = re.race_code
LEFT JOIN LATERAL (
    SELECT x.entry_course
    FROM boat_race.exhibition_live x
    WHERE x.race_code = re.race_code
      AND x.player_id = re.player_id
    LIMIT 1
) el ON TRUE
WHERE re.race_code = ?
ORDER BY re.lane_number
SQL);
$targetStmt->execute([$raceCode]);
$boats = $targetStmt->fetchAll(PDO::FETCH_ASSOC);
if (count($boats) !== 6) {
    throw new RuntimeException('対象レースの出走艇が6艇ではありません');
}
$targetDate = (string)$boats[0]['race_date'];

$cutStmt = $pdo->prepare(<<<SQL
SELECT
    TO_CHAR(?::date - INTERVAL '6 months', 'YYYYMMDD') AS cut6,
    TO_CHAR(?::date - INTERVAL '3 months', 'YYYYMMDD') AS cut3,
    TO_CHAR(?::date - INTERVAL '183 days', 'YYYYMMDD') AS cut183
SQL);
$cutStmt->execute([$targetDate, $targetDate, $targetDate]);
$cuts = $cutStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$cut6 = (string)($cuts['cut6'] ?? '');
$cut3 = (string)($cuts['cut3'] ?? '');
$cut183 = (string)($cuts['cut183'] ?? '');

function timedQuery(PDO $pdo, string $sql, array $params): array
{
    $t0 = hrtime(true);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return [$rows, (hrtime(true) - $t0) / 1_000_000.0];
}

function normRows(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $n = [];
        foreach ($row as $k => $v) {
            $n[(string)$k] = $v === null ? null : (string)$v;
        }
        $out[] = $n;
    }
    return $out;
}

function ratio(float $old, float $new): float
{
    return $new > 0.0 ? $old / $new : 0.0;
}

function yesNo(bool $v): string
{
    return $v ? 'YES' : 'NO';
}

// -----------------------------------------------------------------------------
// 1) RecentCourseTrioRateLogic OLD / NEW
// -----------------------------------------------------------------------------
$recentOld = <<<SQL
WITH hist AS (
    SELECT
        rm.race_date,
        EXISTS (
            SELECT 1
            FROM boat_race.race_result_detail w
            WHERE w.race_code = re.race_code
              AND TRIM(w.rank::text) = '1'
        ) AS completed,
        COALESCE(
            CASE WHEN rd.entry_course::text ~ '^[1-6]$' THEN rd.entry_course::int ELSE NULL END,
            CASE WHEN ex.entry_course::text ~ '^[1-6]$' THEN ex.entry_course::int ELSE NULL END
        ) AS actual_course,
        CASE WHEN rd.rank::text ~ '^[1-6]$' THEN rd.rank::int ELSE NULL END AS rank_num
    FROM boat_race.race_entry re
    JOIN boat_race.race_master rm ON rm.race_code = re.race_code
    LEFT JOIN LATERAL (
        SELECT rrd.entry_course, rrd.rank
        FROM boat_race.race_result_detail rrd
        WHERE rrd.race_code = re.race_code
          AND rrd.player_id = re.player_id
        LIMIT 1
    ) rd ON TRUE
    LEFT JOIN LATERAL (
        SELECT el.entry_course
        FROM boat_race.exhibition_live el
        WHERE el.race_code = re.race_code
          AND el.player_id = re.player_id
          AND el.entry_course BETWEEN 1 AND 6
        LIMIT 1
    ) ex ON TRUE
    WHERE re.player_id::text = ?
      AND (rm.race_date < ?::date OR (rm.race_date = ?::date AND re.race_code < ?))
      AND rm.race_date >= ?::date - INTERVAL '6 months'
)
SELECT
    COUNT(*) FILTER (WHERE completed AND actual_course = ?) AS n6,
    COUNT(*) FILTER (WHERE completed AND actual_course = ? AND rank_num BETWEEN 1 AND 3) AS top3_6,
    COUNT(*) FILTER (WHERE completed AND actual_course = ? AND race_date >= ?::date - INTERVAL '3 months') AS n3,
    COUNT(*) FILTER (WHERE completed AND actual_course = ? AND rank_num BETWEEN 1 AND 3 AND race_date >= ?::date - INTERVAL '3 months') AS top3_3
FROM hist
SQL;

$recentNew = <<<SQL
WITH hist AS (
    SELECT
        re.race_code,
        EXISTS (
            SELECT 1
            FROM boat_race.race_result_detail w
            WHERE w.race_code = re.race_code
              AND TRIM(w.rank::text) = '1'
        ) AS completed,
        COALESCE(
            CASE WHEN rd.entry_course::text ~ '^[1-6]$' THEN rd.entry_course::int ELSE NULL END,
            CASE WHEN ex.entry_course::text ~ '^[1-6]$' THEN ex.entry_course::int ELSE NULL END
        ) AS actual_course,
        CASE WHEN rd.rank::text ~ '^[1-6]$' THEN rd.rank::int ELSE NULL END AS rank_num
    FROM boat_race.race_entry re
    LEFT JOIN LATERAL (
        SELECT rrd.entry_course, rrd.rank
        FROM boat_race.race_result_detail rrd
        WHERE rrd.race_code = re.race_code
          AND rrd.player_id = re.player_id
        LIMIT 1
    ) rd ON TRUE
    LEFT JOIN LATERAL (
        SELECT el.entry_course
        FROM boat_race.exhibition_live el
        WHERE el.race_code = re.race_code
          AND el.player_id = re.player_id
          AND el.entry_course BETWEEN 1 AND 6
        LIMIT 1
    ) ex ON TRUE
    WHERE re.player_id::text = ?
      AND re.race_code >= ?
      AND re.race_code < ?
)
SELECT
    COUNT(*) FILTER (WHERE completed AND actual_course = ?) AS n6,
    COUNT(*) FILTER (WHERE completed AND actual_course = ? AND rank_num BETWEEN 1 AND 3) AS top3_6,
    COUNT(*) FILTER (WHERE completed AND actual_course = ? AND race_code >= ?) AS n3,
    COUNT(*) FILTER (WHERE completed AND actual_course = ? AND rank_num BETWEEN 1 AND 3 AND race_code >= ?) AS top3_3
FROM hist
SQL;

$recentOldTotal = 0.0;
$recentNewTotal = 0.0;
$recentMatch = true;
$recentLines = [];
foreach ($boats as $boat) {
    $lane = (int)$boat['lane_number'];
    $pid = (string)$boat['player_id'];
    $course = (int)$boat['target_course'];
    [$oldRows, $oldMs] = timedQuery($pdo, $recentOld, [
        $pid, $targetDate, $targetDate, $raceCode, $targetDate,
        $course, $course, $course, $targetDate, $course, $targetDate,
    ]);
    [$newRows, $newMs] = timedQuery($pdo, $recentNew, [
        $pid, $cut6, $raceCode,
        $course, $course, $course, $cut3, $course, $cut3,
    ]);
    $match = normRows($oldRows) === normRows($newRows);
    $recentMatch = $recentMatch && $match;
    $recentOldTotal += $oldMs;
    $recentNewTotal += $newMs;
    $recentLines[] = [$lane, $pid, $course, $oldMs, $newMs, $match];
}

// -----------------------------------------------------------------------------
// 2) venue_exhibition_average OLD / NEW
// -----------------------------------------------------------------------------
$venueOld = <<<SQL
SELECT AVG(el.exhibition_time::double precision) AS avg_ex
FROM boat_race.exhibition_live el
JOIN boat_race.race_master rm ON rm.race_code = el.race_code
WHERE SUBSTRING(el.race_code, 9, 3) = ?
  AND el.exhibition_time IS NOT NULL
  AND el.exhibition_time::double precision > 0
  AND rm.race_date >= ?::date
  AND (rm.race_date < ?::date OR (rm.race_date = ?::date AND el.race_code < ?))
SQL;
$cut183Date = substr($cut183, 0, 4) . '-' . substr($cut183, 4, 2) . '-' . substr($cut183, 6, 2);
[$venueOldRows, $venueOldMs] = timedQuery($pdo, $venueOld, [
    $placeCode, $cut183Date, $targetDate, $targetDate, $raceCode,
]);

$venueNew = <<<SQL
SELECT AVG(el.exhibition_time::double precision) AS avg_ex
FROM boat_race.exhibition_live el
WHERE SUBSTRING(el.race_code, 9, 3) = ?
  AND el.exhibition_time IS NOT NULL
  AND el.exhibition_time::double precision > 0
  AND el.race_code >= ?
  AND el.race_code < ?
SQL;
[$venueNewRows, $venueNewMs] = timedQuery($pdo, $venueNew, [$placeCode, $cut183, $raceCode]);
$oldAvg = isset($venueOldRows[0]['avg_ex']) ? (float)$venueOldRows[0]['avg_ex'] : NAN;
$newAvg = isset($venueNewRows[0]['avg_ex']) ? (float)$venueNewRows[0]['avg_ex'] : NAN;
$venueMatch = is_finite($oldAvg) && is_finite($newAvg) && abs($oldAvg - $newAvg) <= 1e-12;

// -----------------------------------------------------------------------------
// 3) tenji_test endpoint timing
// -----------------------------------------------------------------------------
$courseToBoat = [];
foreach ($boats as $boat) {
    $course = (int)$boat['target_course'];
    $lane = (int)$boat['lane_number'];
    if ($course >= 1 && $course <= 6 && $lane >= 1 && $lane <= 6) {
        $courseToBoat[$course] = $lane;
    }
}
ksort($courseToBoat);
$query = ['race_code' => $raceCode];
for ($course = 1; $course <= 6; $course++) {
    $query['tenji' . $course] = (int)($courseToBoat[$course] ?? 0);
}
$queryString = http_build_query($query);

function curlTiming(string $url): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'curl拡張なし'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    return [
        'ok' => $body !== false,
        'bytes' => is_string($body) ? strlen($body) : 0,
        'error' => $err,
        'connect_ms' => ((float)($info['connect_time'] ?? 0)) * 1000.0,
        'start_ms' => ((float)($info['starttransfer_time'] ?? 0)) * 1000.0,
        'total_ms' => ((float)($info['total_time'] ?? 0)) * 1000.0,
        'http_code' => (int)($info['http_code'] ?? 0),
    ];
}

$tenjiLocal = curlTiming('http://127.0.0.1/tenji_test.php?' . $queryString);
$tenjiLan = curlTiming('http://192.168.0.208/tenji_test.php?' . $queryString);

$sourcePath = '/var/www/html/tenji_test.php';
$sourceInfo = ['exists' => is_file($sourcePath)];
$hotLines = [];
if (is_file($sourcePath)) {
    $src = (string)file_get_contents($sourcePath);
    $lines = preg_split('/\R/', $src) ?: [];
    $sourceInfo += [
        'bytes' => strlen($src),
        'lines' => count($lines),
        'sha1' => sha1($src),
    ];
    $pattern = '/\b(SELECT|FROM|JOIN|WITH|shell_exec|exec\s*\(|file_get_contents|curl_|python|require|include)\b/i';
    foreach ($lines as $i => $line) {
        if (preg_match($pattern, $line)) {
            $text = trim($line);
            if (strlen($text) > 180) {
                $text = substr($text, 0, 177) . '...';
            }
            $hotLines[] = [($i + 1), $text];
            if (count($hotLines) >= 40) {
                break;
            }
        }
    }
}

// -----------------------------------------------------------------------------
// output
// -----------------------------------------------------------------------------
echo str_repeat('=', 100) . PHP_EOL;
echo "Web残りボトルネック OLD/NEW・endpoint計測" . PHP_EOL;
echo str_repeat('=', 100) . PHP_EOL;
echo "race_code : {$raceCode}" . PHP_EOL;
echo "target    : {$targetDate} / {$placeCode}" . PHP_EOL;
echo "cut6/cut3 : {$cut6} / {$cut3}" . PHP_EOL;
echo str_repeat('-', 100) . PHP_EOL;
echo "RecentCourseTrioRateLogic" . PHP_EOL;
foreach ($recentLines as [$lane, $pid, $course, $oldMs, $newMs, $match]) {
    printf("%d号艇 player=%s %dC  OLD %7.1f ms / NEW %7.1f ms / %6.2fx / 一致 %s\n",
        $lane, $pid, $course, $oldMs, $newMs, ratio($oldMs, $newMs), yesNo($match));
}
printf("Recent合計             OLD %7.1f ms / NEW %7.1f ms / %6.2fx / 一致 %s\n",
    $recentOldTotal, $recentNewTotal, ratio($recentOldTotal, $recentNewTotal), yesNo($recentMatch));
echo str_repeat('-', 100) . PHP_EOL;
printf("venue exhibition avg   OLD %7.1f ms / NEW %7.1f ms / %6.2fx / 一致 %s\n",
    $venueOldMs, $venueNewMs, ratio($venueOldMs, $venueNewMs), yesNo($venueMatch));
printf("avg OLD/NEW            %.12f / %.12f\n", $oldAvg, $newAvg);
echo str_repeat('-', 100) . PHP_EOL;
echo "tenji_test endpoint" . PHP_EOL;
foreach ([['127.0.0.1', $tenjiLocal], ['192.168.0.208', $tenjiLan]] as [$name, $t]) {
    if (!($t['ok'] ?? false)) {
        printf("%-15s ERROR %s\n", $name, (string)($t['error'] ?? ''));
        continue;
    }
    printf("%-15s connect=%7.1f ms / first_byte=%7.1f ms / total=%7.1f ms / HTTP=%d / bytes=%d\n",
        $name,
        (float)$t['connect_ms'],
        (float)$t['start_ms'],
        (float)$t['total_ms'],
        (int)$t['http_code'],
        (int)$t['bytes']
    );
}
echo "source      : " . ($sourceInfo['exists'] ? 'FOUND' : 'NOT FOUND') . " {$sourcePath}" . PHP_EOL;
if ($sourceInfo['exists']) {
    printf("source size : %d bytes / %d lines / sha1=%s\n",
        (int)$sourceInfo['bytes'], (int)$sourceInfo['lines'], (string)$sourceInfo['sha1']);
    if ($hotLines !== []) {
        echo "source hot lines (最大40):" . PHP_EOL;
        foreach ($hotLines as [$lineNo, $text]) {
            printf("  L%-4d %s\n", $lineNo, $text);
        }
    }
}
echo str_repeat('-', 100) . PHP_EOL;
echo "Recent完全一致          : " . yesNo($recentMatch) . PHP_EOL;
echo "venue平均完全一致       : " . yesNo($venueMatch) . PHP_EOL;
echo str_repeat('=', 100) . PHP_EOL;

exit(($recentMatch && $venueMatch) ? 0 : 2);
