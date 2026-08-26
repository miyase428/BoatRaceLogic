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
// 日付ループ設定（last_date → 今日まで）
// ------------------------------------------------------------
$config = require __DIR__ . '/../config/last_date.php';

$start_date = $config['last_date'];      // 例: 20260206
$today      = date('Ymd');               // 今日の日付

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
// placeMap 読み込み
// ------------------------------------------------------------
$placeMap = require __DIR__ . '/../config/place_map.php';

// ------------------------------------------------------------
// PostgreSQL 接続
// ------------------------------------------------------------
$pdo = getPDO();

// ------------------------------------------------------------
// 日付ごとの処理開始
// ------------------------------------------------------------
foreach ($period as $dateObj) {

    $race_date = $dateObj->format('Ymd');
    $race_date_db = $dateObj->format('Y-m-d');

    // ------------------------------------------------------------
    // 6時を過ぎていたら「次の日付には進まない」
    // ただし、今の日付はまだ処理していないので前日までを保存して終了
    // ------------------------------------------------------------
    if (time() >= $limit_time) {

        // 次回取得する日付を保存
        $last_done = $dateObj->format('Ymd');
        file_put_contents(
            __DIR__ . '/../config/last_date.php',
            "<?php\nreturn ['last_date' => '{$last_done}'];"
        );

        // 実行した日付をログに出力して終了
        $yesterday = (clone $dateObj)->modify('-1 day')->format('Ymd');
        log_message("時間切れのため {$yesterday} までで終了");
        exit;
    }

    // ------------------------------------------------------------
    // この日付の処理は最後までやり切る
    // ------------------------------------------------------------
    log_message("=== 日付 {$race_date} の処理開始 ===");

    // この日付で取得エラーが1件でも発生したら last_date を進めない
    $date_has_error = false;

    // ------------------------------------------------------------
    // 開催場の抽出
    // ------------------------------------------------------------
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
        continue;
    }

    log_message("開催場: " . implode(', ', $stadiums));

    // ------------------------------------------------------------
    // 開催場 × 12R ループ
    // ------------------------------------------------------------
    foreach ($stadiums as $stadium_code) {

        $place_no = array_search($stadium_code, $placeMap, true);
        if ($place_no === false) {
            log_message("placeMap に stadium_code {$stadium_code} がありません");
            continue;
        }

        $place_code = $stadium_code;

        for ($race_no = 1; $race_no <= 12; $race_no++) {

            $race_no2  = str_pad($race_no, 2, '0', STR_PAD_LEFT);
            $race_code = $race_date . $place_code . $race_no2;

            // ------------------------------------------------------------
            // 再試行時、すでに6艇分そろっているレースは再取得しない
            // ------------------------------------------------------------
            $check_sql = "
                SELECT COUNT(DISTINCT entry_course)
                FROM boat_race.exhibition_live
                WHERE race_code = :race_code
            ";
            $check_stmt = $pdo->prepare($check_sql);
            $check_stmt->execute([':race_code' => $race_code]);
            $registered_count = (int)$check_stmt->fetchColumn();

            if ($registered_count >= 6) {
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

            // Playwright 実行
            #$cmd = "node D:\\BoatRaceLogic\\playwright\\exhibition_live_scraper.js " . escapeshellarg($url);
            $cmd = "/usr/bin/node /var/www/html/boatrace/playwright/exhibition_live_scraper.js " . escapeshellarg($url);

            $output = [];
            exec($cmd, $output, $return_var);

            if ($return_var !== 0) {
                log_message("Playwright error: {$return_var}（{$place_code} {$race_no}R）");

                // この日付を未完了として、翌日同じ日付から再試行する
                $date_has_error = true;

                // エラーURLを保存
                $error_file = __DIR__ . '/../logs/error_urls.txt';
                file_put_contents($error_file, $url . PHP_EOL, FILE_APPEND);

                continue;
            }

            // JSON変換
            $json = implode("\n", $output);
            $data = json_decode($json, true);

            if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                log_message("JSON解析エラー（{$race_code}）: " . json_last_error_msg());
                $date_has_error = true;

                $error_file = __DIR__ . '/../logs/error_urls.txt';
                file_put_contents($error_file, $url . PHP_EOL, FILE_APPEND);
                continue;
            }

            if (empty($data)) {
                log_message("展示データなし（{$race_code}）");
                continue;
            }

            // ------------------------------------------------------------
            // 過去の場平均
            // ------------------------------------------------------------
            $avg_sql = "
                SELECT
                    AVG(exhibition_time) AS avg_exh,
                    AVG(lap_time)        AS avg_lap,
                    AVG(around_time)     AS avg_around,
                    AVG(straight_time)   AS avg_straight
                FROM boat_race.exhibition_live
                WHERE race_code LIKE :place_prefix
            ";

            $avg_stmt = $pdo->prepare($avg_sql);
            $avg_stmt->execute([':place_prefix' => "%{$place_code}%"]);
            $avg = $avg_stmt->fetch(PDO::FETCH_ASSOC);

            $avg_exh      = $avg['avg_exh']      ?? 0;
            $avg_lap      = $avg['avg_lap']      ?? 0;
            $avg_around   = $avg['avg_around']   ?? 0;
            $avg_straight = $avg['avg_straight'] ?? 0;

            // ------------------------------------------------------------
            // INSERT文準備
            // ------------------------------------------------------------
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

            $stmt_insert = $pdo->prepare($sql);

            // ------------------------------------------------------------
            // 6艇分登録
            // ------------------------------------------------------------
            foreach ($data as $row) {

                $exh      = toFloatOrZero($row['exhibition_time']);
                $lap      = toFloatOrZero($row['lap_time']);
                $around   = toFloatOrZero($row['around_time']);
                $straight = toFloatOrZero($row['straight_time']);

                $diff_straight = $avg_straight - $straight;
                $diff_around   = $avg_around   - $around;
                $diff_lap      = $avg_lap      - $lap;
                $diff_exh      = $avg_exh      - $exh;

                $score =
                    $diff_straight * 0.4 +
                    $diff_around   * 0.3 +
                    $diff_lap      * 0.2 +
                    $diff_exh      * 0.1;

                if ($diff_straight > 0.10) {
                    $type = '伸び型';
                } elseif ($diff_around > 0.10) {
                    $type = '差し型';
                } else {
                    $type = 'バランス';
                }

                $stmt_insert->execute([
                    ':race_code'        => $race_code,
                    ':entry_course'     => $row['entry_course'],
                    ':player_id'        => $row['player_id'],
                    ':exhibition_time'  => toNullOrFloat($row['exhibition_time']),
                    ':start_timing'     => convertStartTiming($row['start_timing']),
                    ':lap_time'         => toNullOrFloat($row['lap_time']),
                    ':around_time'      => toNullOrFloat($row['around_time']),
                    ':straight_time'    => toNullOrFloat($row['straight_time']),
                    ':exhibition_score' => $score,
                    ':exhibition_type'  => $type
                ]);
            }

            log_message("{$race_code} 登録完了");

            // ------------------------------------------------------------
            // 待ち時間（10〜13秒）
            // 競艇日和さんへの負荷軽減のため維持する
            // ------------------------------------------------------------
            $wait = rand(1000, 1300) / 100;
            usleep((int)($wait * 1000000));
        }

        // 一場終了後の待ち時間
        $wait_place = rand(1000, 1500) / 100;
        log_message("一場終了待ち: {$wait_place} 秒");
        usleep((int)($wait_place * 1000000));
    }

    // ------------------------------------------------------------
    // 取得失敗があった場合は、この日付を完了扱いにしない
    // 翌日同じ日付から再試行する。成功済みレースはDB確認でスキップされる。
    // ------------------------------------------------------------
    if ($date_has_error) {
        log_message("=== 日付 {$race_date} は取得失敗レースがあるため未完了 ===");
        log_message("last_date は更新せず、次回この日付から再試行します");
        exit;
    }

    log_message("=== 日付 {$race_date} の処理完了 ===");

    // ------------------------------------------------------------
    // この日付は完走したので last_date を更新
    // ------------------------------------------------------------
    $next_date = (new DateTime($race_date))->modify('+1 day')->format('Ymd');
    file_put_contents(
        __DIR__ . '/../config/last_date.php',
        "<?php\nreturn ['last_date' => '{$next_date}'];"
    );

}

log_message("=== 全日付の処理完了 ===");
