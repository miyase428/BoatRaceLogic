<?php

declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../logic/race_url.php';

date_default_timezone_set('Asia/Tokyo');

/**
 * 成立済みなのに exhibition_live が0件のレースを、競艇日和から安全に再取得する。
 *
 * 既定はDRY-RUN。--apply を付けた場合のみDB更新する。
 * --limit=N で1回の最大処理件数を制限する（既定20件）。
 *
 * 安全策:
 * - race_entry 6艇完備 + Top3/3連単払戻で成立確認
 * - exhibition_live 0件だけが対象
 * - スクレイパー結果が6艇分、player_id 6人ユニーク、race_entry全員一致の場合のみ保存
 * - 展示タイム/STが全艇空なら保存しない
 * - 成功時10〜13秒待機
 * - アクセス系エラー時60〜90秒待機、3連続で停止
 * - 1レース単位でcommit
 * - 展示スコアの場平均は対象race_codeより過去だけを使用（未来参照を避ける）
 *
 * Usage:
 *   php analysis/backfill_missing_exhibition.php 2026-08-01 2026-09-02
 *   php analysis/backfill_missing_exhibition.php 2026-08-01 2026-09-02 --apply --limit=1
 *   php analysis/backfill_missing_exhibition.php 2026-08-01 2026-09-02 --apply --limit=20
 */

function usage(): never
{
    fwrite(STDERR, "Usage: php analysis/backfill_missing_exhibition.php YYYY-MM-DD YYYY-MM-DD [--apply] [--limit=N]\n");
    exit(1);
}

$from = trim((string)($argv[1] ?? ''));
$to = trim((string)($argv[2] ?? ''));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    usage();
}
if ($from > $to) {
    fwrite(STDERR, "開始日は終了日以前にしてください。\n");
    exit(1);
}

$apply = false;
$limit = 20;
foreach (array_slice($argv, 3) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
        continue;
    }
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, min(500, (int)$m[1]));
        continue;
    }
    fwrite(STDERR, "不明な引数: {$arg}\n");
    usage();
}

function toNullOrFloat(mixed $v): ?float
{
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '' || $s === '-' || $s === '--') return null;
    return (float)$s;
}

function convertStartTiming(mixed $v): ?float
{
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '' || $s === '-' || $s === '--') return null;
    if (str_starts_with($s, 'F')) return -1.0 * (float)substr($s, 1);
    if (str_starts_with($s, 'L')) return (float)substr($s, 1);
    return (float)$s;
}

function normalWait(): void
{
    $seconds = random_int(1000, 1300) / 100;
    printf("待機 %.2f 秒\n", $seconds);
    usleep((int)round($seconds * 1_000_000));
}

function errorWait(): void
{
    $seconds = random_int(6000, 9000) / 100;
    printf("保護待機 %.2f 秒\n", $seconds);
    usleep((int)round($seconds * 1_000_000));
}

function loadTargets(PDO $pdo, string $from, string $to): array
{
    $sql = <<<SQL
WITH entry AS (
    SELECT
        re.race_code,
        COUNT(*)::int AS entry_rows,
        COUNT(DISTINCT re.lane_number)::int AS lane_count
    FROM boat_race.race_entry re
    WHERE SUBSTRING(re.race_code, 1, 8)
          BETWEEN REPLACE(:from, '-', '') AND REPLACE(:to, '-', '')
    GROUP BY re.race_code
), result AS (
    SELECT
        rrd.race_code,
        COUNT(*) FILTER (WHERE TRIM(rrd.rank) = '1')::int AS r1,
        COUNT(*) FILTER (WHERE TRIM(rrd.rank) = '2')::int AS r2,
        COUNT(*) FILTER (WHERE TRIM(rrd.rank) = '3')::int AS r3
    FROM boat_race.race_result_detail rrd
    WHERE SUBSTRING(rrd.race_code, 1, 8)
          BETWEEN REPLACE(:from, '-', '') AND REPLACE(:to, '-', '')
    GROUP BY rrd.race_code
), payout AS (
    SELECT
        rp.race_code,
        MAX(COALESCE(rp.trifecta_payout, 0))::numeric AS trifecta_payout
    FROM boat_race.race_payouts rp
    WHERE SUBSTRING(rp.race_code, 1, 8)
          BETWEEN REPLACE(:from, '-', '') AND REPLACE(:to, '-', '')
    GROUP BY rp.race_code
), exhibition AS (
    SELECT race_code, COUNT(*)::int AS exhibition_rows
    FROM boat_race.exhibition_live
    WHERE SUBSTRING(race_code, 1, 8)
          BETWEEN REPLACE(:from, '-', '') AND REPLACE(:to, '-', '')
    GROUP BY race_code
)
SELECT e.race_code
FROM entry e
LEFT JOIN result r ON r.race_code = e.race_code
LEFT JOIN payout p ON p.race_code = e.race_code
LEFT JOIN exhibition x ON x.race_code = e.race_code
WHERE e.entry_rows = 6
  AND e.lane_count = 6
  AND COALESCE(x.exhibition_rows, 0) = 0
  AND (
      (COALESCE(r.r1, 0) = 1 AND COALESCE(r.r2, 0) = 1 AND COALESCE(r.r3, 0) = 1)
      OR COALESCE(p.trifecta_payout, 0) > 0
  )
ORDER BY e.race_code
SQL;
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':from' => $from, ':to' => $to]);
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function fetchScraper(string $url): array
{
    $cmd = 'HOME=/home/miyazaki PLAYWRIGHT_BROWSERS_PATH=/home/miyazaki/.cache/ms-playwright '
         . '/usr/bin/node /var/www/html/boatrace/playwright/exhibition_live_scraper.js '
         . escapeshellarg($url) . ' 2>&1';

    $output = [];
    $returnVar = 0;
    exec($cmd, $output, $returnVar);

    if ($returnVar !== 0) {
        return ['ok' => false, 'kind' => 'access', 'message' => 'Playwright error code=' . $returnVar . ' / ' . implode(' | ', $output), 'data' => []];
    }

    $json = implode("\n", $output);
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return ['ok' => false, 'kind' => 'parse', 'message' => 'JSON decode error: ' . json_last_error_msg(), 'data' => []];
    }
    if (count($data) !== 6) {
        return ['ok' => false, 'kind' => 'data', 'message' => '取得件数=' . count($data) . '（6艇未満/超過）', 'data' => $data];
    }

    return ['ok' => true, 'kind' => 'ok', 'message' => '', 'data' => $data];
}

function validateScrapedData(PDO $pdo, string $raceCode, array $data): array
{
    $scrapedPlayerIds = [];
    $courseSet = [];
    $hasData = false;

    foreach ($data as $row) {
        $course = (int)($row['entry_course'] ?? 0);
        $playerId = trim((string)($row['player_id'] ?? ''));
        if ($course < 1 || $course > 6) {
            return [false, "entry_course異常: {$course}"];
        }
        if ($playerId === '' || !preg_match('/^\d+$/', $playerId)) {
            return [false, "player_id空/異常 course={$course}"];
        }
        $courseSet[$course] = true;
        $scrapedPlayerIds[$playerId] = true;

        if (toNullOrFloat($row['exhibition_time'] ?? null) !== null || convertStartTiming($row['start_timing'] ?? null) !== null) {
            $hasData = true;
        }
    }

    if (count($courseSet) !== 6) return [false, '進入コースが6種類ではありません'];
    if (count($scrapedPlayerIds) !== 6) return [false, 'player_idが6人ユニークではありません'];
    if (!$hasData) return [false, '展示タイム/STが全艇空です'];

    $stmt = $pdo->prepare("SELECT player_id::text FROM boat_race.race_entry WHERE race_code = :race_code");
    $stmt->execute([':race_code' => $raceCode]);
    $entryIds = array_fill_keys(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);

    if (count($entryIds) !== 6) return [false, 'race_entryの選手が6人ではありません'];
    foreach (array_keys($scrapedPlayerIds) as $pid) {
        if (!isset($entryIds[$pid])) return [false, "race_entry選手不一致 player_id={$pid}"];
    }

    return [true, 'OK'];
}

function loadPastVenueAverage(PDO $pdo, string $raceCode): array
{
    $placeCode = substr($raceCode, 8, 3);
    $sql = <<<SQL
SELECT
    AVG(exhibition_time) AS avg_exh,
    AVG(lap_time) AS avg_lap,
    AVG(around_time) AS avg_around,
    AVG(straight_time) AS avg_straight
FROM boat_race.exhibition_live
WHERE SUBSTRING(race_code, 9, 3) = :place_code
  AND race_code < :race_code
SQL;
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':place_code' => $placeCode, ':race_code' => $raceCode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'exh' => isset($row['avg_exh']) ? (float)$row['avg_exh'] : 0.0,
        'lap' => isset($row['avg_lap']) ? (float)$row['avg_lap'] : 0.0,
        'around' => isset($row['avg_around']) ? (float)$row['avg_around'] : 0.0,
        'straight' => isset($row['avg_straight']) ? (float)$row['avg_straight'] : 0.0,
    ];
}

function saveRace(PDO $pdo, string $raceCode, array $data): int
{
    $avg = loadPastVenueAverage($pdo, $raceCode);

    $sql = <<<SQL
INSERT INTO boat_race.exhibition_live (
    race_code, entry_course, player_id,
    exhibition_time, start_timing, lap_time, around_time, straight_time,
    exhibition_score, exhibition_type, created_date
) VALUES (
    :race_code, :entry_course, :player_id,
    :exhibition_time, :start_timing, :lap_time, :around_time, :straight_time,
    :exhibition_score, :exhibition_type, NOW()
)
ON CONFLICT (race_code, entry_course) DO NOTHING
SQL;
    $stmt = $pdo->prepare($sql);
    $inserted = 0;

    $pdo->beginTransaction();
    try {
        foreach ($data as $row) {
            $exh = toNullOrFloat($row['exhibition_time'] ?? null);
            $lap = toNullOrFloat($row['lap_time'] ?? null);
            $around = toNullOrFloat($row['around_time'] ?? null);
            $straight = toNullOrFloat($row['straight_time'] ?? null);
            $st = convertStartTiming($row['start_timing'] ?? null);

            $diffStraight = $straight === null ? 0.0 : $avg['straight'] - $straight;
            $diffAround = $around === null ? 0.0 : $avg['around'] - $around;
            $diffLap = $lap === null ? 0.0 : $avg['lap'] - $lap;
            $diffExh = $exh === null ? 0.0 : $avg['exh'] - $exh;

            $score = $diffStraight * 0.4 + $diffAround * 0.3 + $diffLap * 0.2 + $diffExh * 0.1;
            if ($diffStraight > 0.10) {
                $type = '伸び型';
            } elseif ($diffAround > 0.10) {
                $type = '差し型';
            } else {
                $type = 'バランス';
            }

            $stmt->execute([
                ':race_code' => $raceCode,
                ':entry_course' => (int)$row['entry_course'],
                ':player_id' => (int)$row['player_id'],
                ':exhibition_time' => $exh,
                ':start_timing' => $st,
                ':lap_time' => $lap,
                ':around_time' => $around,
                ':straight_time' => $straight,
                ':exhibition_score' => $score,
                ':exhibition_type' => $type,
            ]);
            $inserted += max(0, $stmt->rowCount());
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    return $inserted;
}

$pdo = getPDO();
$targets = loadTargets($pdo, $from, $to);
$totalTargets = count($targets);
$selected = array_slice($targets, 0, $limit);

echo str_repeat('=', 120) . "\n";
echo "成立済み 展示0件バックフィル\n";
echo "期間             : {$from} ～ {$to}\n";
echo "対象欠損         : {$totalTargets}R\n";
echo "今回上限         : {$limit}R\n";
echo "今回対象         : " . count($selected) . "R\n";
echo "モード           : " . ($apply ? 'APPLY（DB更新あり）' : 'DRY-RUN（DB更新なし）') . "\n";
echo "待機             : 成功10～13秒 / アクセス系エラー60～90秒\n";
echo str_repeat('=', 120) . "\n";

foreach ($selected as $code) echo $code . "\n";

if (!$apply) {
    echo "\nDRY-RUNで終了しました。最初は --apply --limit=1 で1Rだけ確認してください。\n";
    exit(0);
}

$success = 0;
$failed = 0;
$insertedTotal = 0;
$consecutiveAccessErrors = 0;

foreach ($selected as $index => $raceCode) {
    echo "\n[" . ($index + 1) . "/" . count($selected) . "] {$raceCode}\n";
    try {
        $url = raceCodeToKyoteiBiyoriUrl($raceCode);
        echo "URL: {$url}\n";

        $result = fetchScraper($url);
        if (!$result['ok']) {
            echo "NG: {$result['message']}\n";
            $failed++;
            if ($result['kind'] === 'access') {
                $consecutiveAccessErrors++;
                errorWait();
                if ($consecutiveAccessErrors >= 3) {
                    echo "アクセス系エラー3連続のため安全停止します。\n";
                    break;
                }
            } else {
                $consecutiveAccessErrors = 0;
                normalWait();
            }
            continue;
        }

        [$valid, $reason] = validateScrapedData($pdo, $raceCode, $result['data']);
        if (!$valid) {
            echo "NG: データ検証失敗: {$reason}\n";
            $failed++;
            $consecutiveAccessErrors = 0;
            normalWait();
            continue;
        }

        $inserted = saveRace($pdo, $raceCode, $result['data']);
        echo "OK: inserted={$inserted}\n";
        $insertedTotal += $inserted;
        if ($inserted === 6) {
            $success++;
        } else {
            $failed++;
        }
        $consecutiveAccessErrors = 0;
        normalWait();
    } catch (Throwable $e) {
        echo "NG: " . $e->getMessage() . "\n";
        $failed++;
        $consecutiveAccessErrors = 0;
        normalWait();
    }
}

$afterTargets = loadTargets($pdo, $from, $to);

echo "\n" . str_repeat('=', 120) . "\n";
echo "バックフィル結果\n";
echo "成功レース       : {$success}R\n";
echo "失敗レース       : {$failed}R\n";
echo "DB新規追加       : {$insertedTotal}行\n";
echo "成立済み展示0件  : {$totalTargets}R -> " . count($afterTargets) . "R\n";
echo str_repeat('=', 120) . "\n";
