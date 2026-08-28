<?php
date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../common/db_connect.php';

// ------------------------------------------------------------
// ログ出力関数（画面にも出しつつ log/YYYYMMDD.log に保存）
// ------------------------------------------------------------
function log_message($message) {
    $date = date("Y-m-d H:i:s");
    $logLine = "[{$date}] {$message}\n";

    echo $logLine;

    $logDir = __DIR__ . "/../log";
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }

    $logFile = $logDir . "/" . date("Ymd") . ".log";
    file_put_contents($logFile, $logLine, FILE_APPEND);
}

// ------------------------------------------------------------
// start_timing の変換（F.04 → -0.04）
// ------------------------------------------------------------
function convertStartTiming($value)
{
    $value = trim($value);

    if ($value === "" || $value === "--" || $value === "-") {
        return null;
    }

    if (str_starts_with($value, "F")) {
        return -1 * floatval(substr($value, 1));
    }

    if (str_starts_with($value, "L")) {
        return floatval(substr($value, 1));
    }

    return floatval($value);
}

// ------------------------------------------------------------
// "-" や "" を 0 に変換（score 計算用）
// ------------------------------------------------------------
function toFloatOrZero($v)
{
    return ($v === "-" || $v === "" || $v === null) ? 0.0 : floatval($v);
}

// ------------------------------------------------------------
// "-" や "" を null に変換（DB INSERT 用）
// ------------------------------------------------------------
function toNullOrFloat($v)
{
    return ($v === "-" || $v === "" || $v === null) ? null : floatval($v);
}

// ------------------------------------------------------------
// 競艇日和への連続アクセスを抑える待機
// ------------------------------------------------------------
function waitNormalInterval(): void
{
    $wait = rand(1000, 1300) / 100;
    usleep((int)($wait * 1000000));
}

function waitErrorBackoff(): void
{
    $wait = rand(6000, 9000) / 100;
    log_message("取得エラー後の保護待機: {$wait} 秒");
    usleep((int)($wait * 1000000));
}

// ------------------------------------------------------------
// エラーURL管理
// ------------------------------------------------------------
function appendErrorUrl(string $errorFile, string $url): void
{
    $dir = dirname($errorFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($errorFile, $url . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function loadUniqueErrorUrls(string $errorFile): array
{
    if (!is_file($errorFile)) {
        return [];
    }

    $lines = file($errorFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $unique = [];

    foreach ($lines as $line) {
        $url = trim($line);
        if ($url === '') {
            continue;
        }
        $unique[$url] = true;
    }

    return array_keys($unique);
}

function writeErrorUrls(string $errorFile, array $urls): void
{
    $urls = array_values(array_unique(array_filter(array_map('trim', $urls))));
    $content = empty($urls) ? '' : implode(PHP_EOL, $urls) . PHP_EOL;

    $dir = dirname($errorFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $tmp = $errorFile . '.tmp';
    file_put_contents($tmp, $content, LOCK_EX);
    rename($tmp, $errorFile);
}

// ------------------------------------------------------------
// 展示取得・保存の共通処理
// ------------------------------------------------------------
function getRegisteredExhibitionCount(PDO $pdo, string $raceCode): int
{
    $sql = "
        SELECT COUNT(DISTINCT entry_course)
        FROM boat_race.exhibition_live
        WHERE race_code = :race_code
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':race_code' => $raceCode]);
    return (int)$stmt->fetchColumn();
}

function fetchExhibitionData(string $url): array
{
    $cmd = "/usr/bin/node /var/www/html/boatrace/playwright/exhibition_live_scraper.js " . escapeshellarg($url);

    $output = [];
    exec($cmd, $output, $returnVar);

    if ($returnVar !== 0) {
        return [
            'status' => 'playwright_error',
            'return_var' => $returnVar,
            'data' => [],
        ];
    }

    $json = implode("\n", $output);
    $data = json_decode($json, true);

    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        return [
            'status' => 'json_error',
            'error' => json_last_error_msg(),
            'data' => [],
        ];
    }

    if (empty($data)) {
        return [
            'status' => 'empty',
            'data' => [],
        ];
    }

    return [
        'status' => 'ok',
        'data' => $data,
    ];
}

function saveExhibitionData(PDO $pdo, string $raceCode, string $placeCode, array $data): void
{
    $avgSql = "
        SELECT
            AVG(exhibition_time) AS avg_exh,
            AVG(lap_time)        AS avg_lap,
            AVG(around_time)     AS avg_around,
            AVG(straight_time)   AS avg_straight
        FROM boat_race.exhibition_live
        WHERE race_code LIKE :place_prefix
    ";

    $avgStmt = $pdo->prepare($avgSql);
    $avgStmt->execute([':place_prefix' => "%{$placeCode}%"]);
    $avg = $avgStmt->fetch(PDO::FETCH_ASSOC);

    $avgExh      = $avg['avg_exh']      ?? 0;
    $avgLap      = $avg['avg_lap']      ?? 0;
    $avgAround   = $avg['avg_around']   ?? 0;
    $avgStraight = $avg['avg_straight'] ?? 0;

    $sql = "
        INSERT INTO boat_race.exhibition_live (
            race_code,
            entry_course,
            player_id,
            exhibition_time,
            start_timing,
            lap_time,
            around_time,
            straight_time,
            exhibition_score,
            exhibition_type,
            created_date
        ) VALUES (
            :race_code,
            :entry_course,
            :player_id,
            :exhibition_time,
            :start_timing,
            :lap_time,
            :around_time,
            :straight_time,
            :exhibition_score,
            :exhibition_type,
            NOW()
        )
        ON CONFLICT (race_code, entry_course)
        DO UPDATE SET
            player_id        = EXCLUDED.player_id,
            exhibition_time  = EXCLUDED.exhibition_time,
            start_timing     = EXCLUDED.start_timing,
            lap_time         = EXCLUDED.lap_time,
            around_time      = EXCLUDED.around_time,
            straight_time    = EXCLUDED.straight_time,
            exhibition_score = EXCLUDED.exhibition_score,
            exhibition_type  = EXCLUDED.exhibition_type,
            created_date     = NOW()
    ";

    $stmtInsert = $pdo->prepare($sql);

    foreach ($data as $row) {
        $exh      = toFloatOrZero($row['exhibition_time']);
        $lap      = toFloatOrZero($row['lap_time']);
        $around   = toFloatOrZero($row['around_time']);
        $straight = toFloatOrZero($row['straight_time']);

        $diffStraight = $avgStraight - $straight;
        $diffAround   = $avgAround   - $around;
        $diffLap      = $avgLap      - $lap;
        $diffExh      = $avgExh      - $exh;

        $score =
            $diffStraight * 0.4 +
            $diffAround   * 0.3 +
            $diffLap      * 0.2 +
            $diffExh      * 0.1;

        if ($diffStraight > 0.10) {
            $type = '伸び型';
        } elseif ($diffAround > 0.10) {
            $type = '差し型';
        } else {
            $type = 'バランス';
        }

        $stmtInsert->execute([
            ':race_code'        => $raceCode,
            ':entry_course'     => $row['entry_course'],
            ':player_id'        => $row['player_id'],
            ':exhibition_time'  => toNullOrFloat($row['exhibition_time']),
            ':start_timing'     => convertStartTiming($row['start_timing']),
            ':lap_time'         => toNullOrFloat($row['lap_time']),
            ':around_time'      => toNullOrFloat($row['around_time']),
            ':straight_time'    => toNullOrFloat($row['straight_time']),
            ':exhibition_score' => $score,
            ':exhibition_type'  => $type,
        ]);
    }
}

function parseExhibitionUrl(string $url, array $placeMap): ?array
{
    $query = parse_url($url, PHP_URL_QUERY);
    if (!is_string($query) || $query === '') {
        return null;
    }

    parse_str($query, $params);

    $placeNo = isset($params['place_no']) ? (int)$params['place_no'] : 0;
    $raceNo = isset($params['race_no']) ? (int)$params['race_no'] : 0;
    $raceDate = isset($params['hiduke']) ? (string)$params['hiduke'] : '';

    if ($placeNo < 1 || $placeNo > 24 || $raceNo < 1 || $raceNo > 12 || !preg_match('/^\d{8}$/', $raceDate)) {
        return null;
    }

    if (!array_key_exists($placeNo, $placeMap)) {
        return null;
    }

    $placeCode = (string)$placeMap[$placeNo];
    $raceCode = $raceDate . $placeCode . str_pad((string)$raceNo, 2, '0', STR_PAD_LEFT);

    return [
        'place_no' => $placeNo,
        'race_no' => $raceNo,
        'race_date' => $raceDate,
        'place_code' => $placeCode,
        'race_code' => $raceCode,
    ];
}

function retryErrorUrls(PDO $pdo, array $placeMap, string $errorFile, int $limitTime): void
{
    $urls = loadUniqueErrorUrls($errorFile);

    if (empty($urls)) {
        writeErrorUrls($errorFile, []);
        log_message("=== error_urls.txt に再試行対象はありません ===");
        return;
    }

    writeErrorUrls($errorFile, $urls);

    $total = count($urls);
    $remaining = [];
    $consecutiveErrors = 0;
    $maxConsecutiveErrors = 3;

    log_message("=== error_urls.txt 再試行開始: {$total}件 ===");

    for ($i = 0; $i < $total; $i++) {
        if (time() >= $limitTime) {
            $remaining = array_merge($remaining, array_slice($urls, $i));
            log_message("時間切れのため error_urls.txt 再試行を終了します");
            break;
        }

        $url = $urls[$i];
        $info = parseExhibitionUrl($url, $placeMap);

        if ($info === null) {
            log_message("形式不正のため error_urls.txt から除外: {$url}");
            continue;
        }

        $raceCode = $info['race_code'];
        $placeCode = $info['place_code'];
        $raceNo = $info['race_no'];

        if (getRegisteredExhibitionCount($pdo, $raceCode) >= 6) {
            log_message("エラー再試行スキップ: {$raceCode} は6艇分登録済み");
            continue;
        }

        log_message("=== エラー再試行 {$raceCode} ===");
        log_message("URL: {$url}");

        $result = fetchExhibitionData($url);

        if ($result['status'] === 'playwright_error') {
            log_message("エラー再試行 Playwright error: {$result['return_var']}（{$placeCode} {$raceNo}R）");
            $remaining[] = $url;
            $consecutiveErrors++;
            waitErrorBackoff();
        } elseif ($result['status'] === 'json_error') {
            log_message("エラー再試行 JSON解析エラー（{$raceCode}）: {$result['error']}");
            $remaining[] = $url;
            $consecutiveErrors++;
            waitErrorBackoff();
        } elseif ($result['status'] === 'empty') {
            log_message("エラー再試行 展示データなし（{$raceCode}）→ error_urls.txt から除外");
            $consecutiveErrors = 0;
            waitNormalInterval();
        } else {
            try {
                saveExhibitionData($pdo, $raceCode, $placeCode, $result['data']);
                log_message("エラー再試行 {$raceCode} 登録完了 → error_urls.txt から除外");
                $consecutiveErrors = 0;
                waitNormalInterval();
            } catch (Throwable $e) {
                log_message("エラー再試行 DB登録エラー（{$raceCode}）: " . $e->getMessage());
                $remaining[] = $url;
                $consecutiveErrors = 0;
            }
        }

        if ($consecutiveErrors >= $maxConsecutiveErrors) {
            $remaining = array_merge($remaining, array_slice($urls, $i + 1));
            log_message("エラー再試行でアクセス系エラーが {$consecutiveErrors} 回連続したため、再試行だけ停止します");
            break;
        }
    }

    writeErrorUrls($errorFile, $remaining);

    $resolved = $total - count($remaining);
    log_message("=== error_urls.txt 再試行完了: 解決 {$resolved}件 / 残り " . count($remaining) . "件 ===");
}

// ------------------------------------------------------------
// 日付ループ設定（last_date → 今日まで）
// ------------------------------------------------------------
$config = require __DIR__ . '/../config/last_date.php';

$start_date = $config['last_date'];
$today      = date('Ymd');

$period = new DatePeriod(
    new DateTime($start_date),
    new DateInterval('P1D'),
    (new DateTime($today))->modify('+1 day')
);

// ------------------------------------------------------------
// 6時を過ぎたら次の日付には進まない
// ------------------------------------------------------------
$limit_time = strtotime('tomorrow 06:00');
//$limit_time = strtotime('today 09:30');

// ------------------------------------------------------------
// placeMap / エラーファイル / DB
// ------------------------------------------------------------
$placeMap = require __DIR__ . '/../config/place_map.php';
$error_file = __DIR__ . '/../logs/error_urls.txt';
$pdo = getPDO();

$consecutive_access_errors = 0;
$max_consecutive_access_errors = 3;

// ------------------------------------------------------------
// 通常取得: 日付ごとの処理
// ------------------------------------------------------------
foreach ($period as $dateObj) {
    $race_date = $dateObj->format('Ymd');
    $race_date_db = $dateObj->format('Y-m-d');

    if (time() >= $limit_time) {
        $last_done = $dateObj->format('Ymd');
        file_put_contents(
            __DIR__ . '/../config/last_date.php',
            "<?php\nreturn ['last_date' => '{$last_done}'];"
        );

        $yesterday = (clone $dateObj)->modify('-1 day')->format('Ymd');
        log_message("時間切れのため {$yesterday} までで終了");
        exit;
    }

    log_message("=== 日付 {$race_date} の通常取得開始 ===");
    $date_error_count = 0;

    $sql = "
        SELECT DISTINCT stadium_code
        FROM boat_race.race_entry
        WHERE race_date = :race_date
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':race_date' => $race_date_db]);
    $stadiums = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($stadiums)) {
        log_message("開催場なし（{$race_date}）");
    } else {
        log_message("開催場: " . implode(', ', $stadiums));

        foreach ($stadiums as $stadium_code) {
            $place_no = array_search($stadium_code, $placeMap, true);
            if ($place_no === false) {
                log_message("placeMap に stadium_code {$stadium_code} がありません");
                continue;
            }

            $place_code = $stadium_code;

            for ($race_no = 1; $race_no <= 12; $race_no++) {
                $race_no2  = str_pad((string)$race_no, 2, '0', STR_PAD_LEFT);
                $race_code = $race_date . $place_code . $race_no2;

                if (getRegisteredExhibitionCount($pdo, $race_code) >= 6) {
                    log_message("{$race_code} は6艇分登録済みのためスキップ");
                    continue;
                }

                log_message("=== {$place_code} {$race_no}R 開始 ===");

                $url = "https://kyoteibiyori.com/race_shusso.php"
                     . "?place_no={$place_no}"
                     . "&race_no={$race_no}"
                     . "&hiduke={$race_date}"
                     . "&slider=4";

                log_message("URL: {$url}");

                $result = fetchExhibitionData($url);

                if ($result['status'] === 'playwright_error') {
                    log_message("Playwright error: {$result['return_var']}（{$place_code} {$race_no}R）");
                    $date_error_count++;
                    $consecutive_access_errors++;
                    appendErrorUrl($error_file, $url);
                    waitErrorBackoff();

                    if ($consecutive_access_errors >= $max_consecutive_access_errors) {
                        log_message("アクセス系エラーが {$consecutive_access_errors} 回連続したため通常取得を停止します");
                        log_message("通信保護のため last_date は更新せず、次回cronで同じ日付から再開します");
                        exit;
                    }

                    continue;
                }

                if ($result['status'] === 'json_error') {
                    log_message("JSON解析エラー（{$race_code}）: {$result['error']}");
                    $date_error_count++;
                    $consecutive_access_errors++;
                    appendErrorUrl($error_file, $url);
                    waitErrorBackoff();

                    if ($consecutive_access_errors >= $max_consecutive_access_errors) {
                        log_message("アクセス系エラーが {$consecutive_access_errors} 回連続したため通常取得を停止します");
                        log_message("通信保護のため last_date は更新せず、次回cronで同じ日付から再開します");
                        exit;
                    }

                    continue;
                }

                if ($result['status'] === 'empty') {
                    log_message("展示データなし（{$race_code}）");
                    $consecutive_access_errors = 0;
                    waitNormalInterval();
                    continue;
                }

                $consecutive_access_errors = 0;

                try {
                    saveExhibitionData($pdo, $race_code, $place_code, $result['data']);
                    log_message("{$race_code} 登録完了");
                } catch (Throwable $e) {
                    log_message("DB登録エラー（{$race_code}）: " . $e->getMessage());
                    $date_error_count++;
                    appendErrorUrl($error_file, $url);
                }

                waitNormalInterval();
            }

            $wait_place = rand(1000, 1500) / 100;
            log_message("一場終了待ち: {$wait_place} 秒");
            usleep((int)($wait_place * 1000000));
        }
    }

    if ($date_error_count > 0) {
        log_message("=== 日付 {$race_date} の通常取得完了（失敗 {$date_error_count}件は error_urls.txt に保留） ===");
    } else {
        log_message("=== 日付 {$race_date} の通常取得完了 ===");
    }

    $next_date = (new DateTime($race_date))->modify('+1 day')->format('Ymd');
    file_put_contents(
        __DIR__ . '/../config/last_date.php',
        "<?php\nreturn ['last_date' => '{$next_date}'];"
    );
}

log_message("=== 通常取得の全日付処理完了 ===");

retryErrorUrls($pdo, $placeMap, $error_file, $limit_time);

log_message("=== 全処理完了 ===");
