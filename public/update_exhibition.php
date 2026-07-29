<?php
date_default_timezone_set('Asia/Tokyo');

// 一時的なエラー表示設定（原因特定用）
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ob_start();

require_once __DIR__ . '/../logic/race_url.php';

// ------------------------------------------------------------
// ログ出力関数
// ------------------------------------------------------------
if (!function_exists('log_message')) {
    function log_message($message) {
        $date = date("Y-m-d H:i:s");
        $logLine = "[{$date}] {$message}\n";

        $logDir = __DIR__ . "/../log";

        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }

        @file_put_contents(
            $logDir . "/" . date("Ymd") . ".log",
            $logLine,
            FILE_APPEND
        );
    }
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

    if (substr($value, 0, 1) === "F") {
        return -1 * floatval(substr($value, 1));
    }

    if (substr($value, 0, 1) === "L") {
        return floatval(substr($value, 1));
    }

    return floatval($value);
}

function toFloatOrZero($v)
{
    return ($v === "-" || $v === "" || $v === null) ? 0.0 : floatval($v);
}

function toNullOrFloat($v)
{
    return ($v === "-" || $v === "" || $v === null) ? null : floatval($v);
}

try {
    // PostgreSQL 接続
    $pdo = new PDO(
        "pgsql:host=192.168.0.208;dbname=devdb",
        "miyase428",
        "herunia0113",
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $race_code = $_POST["race_code"]
            ?? $_GET["race_code"]
            ?? "";

    if ($race_code == "") {
        throw new Exception("race_codeがありません");
    }

    $place_code = substr($race_code, 8, 3);

    log_message("{$race_code} 更新開始");

    if (!function_exists('raceCodeToKyoteiBiyoriUrl')) {
        throw new Exception("関数 raceCodeToKyoteiBiyoriUrl が存在しません");
    }
    $url = raceCodeToKyoteiBiyoriUrl($race_code);

    log_message("URL: {$url}");

    // ------------------------------------------------------------
    // Playwright を直接実行 (scrape_exhibition.php と同じ方式)
    // ------------------------------------------------------------
    // HOME環境変数を明示して、cron等で動作実績のあるユーザーのパスを参照させる
    $cmd = "HOME=/home/miyazaki /usr/bin/node /var/www/html/boatrace/playwright/exhibition_live_scraper.js " . escapeshellarg($url) . " 2>&1";
    $cmd = "/usr/bin/node /var/www/html/boatrace/playwright/exhibition_live_scraper.js " . escapeshellarg($url);

    $output = [];
    exec($cmd, $output, $return_var);

    if ($return_var !== 0) {
        $node_error = implode("\n", $output);
        throw new Exception("Playwright 実行エラー (コード: {$return_var})\n詳細: {$node_error}");
    }

    $json = implode("\n", $output);
    $data = json_decode($json, true);

    if ($data === null || empty($data)) {
        throw new Exception("展示データの取得に失敗したか、データが空でした");
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

        $exh      = toFloatOrZero($row['exhibition_time'] ?? null);
        $lap      = toFloatOrZero($row['lap_time'] ?? null);
        $around   = toFloatOrZero($row['around_time'] ?? null);
        $straight = toFloatOrZero($row['straight_time'] ?? null);

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
            ':entry_course'     => $row['entry_course'] ?? null,
            ':player_id'        => $row['player_id'] ?? null,
            ':exhibition_time'  => toNullOrFloat($row['exhibition_time'] ?? null),
            ':start_timing'     => convertStartTiming($row['start_timing'] ?? ''),
            ':lap_time'         => toNullOrFloat($row['lap_time'] ?? null),
            ':around_time'      => toNullOrFloat($row['around_time'] ?? null),
            ':straight_time'    => toNullOrFloat($row['straight_time'] ?? null),
            ':exhibition_score' => $score,
            ':exhibition_type'  => $type
        ]);
    }
    log_message("{$race_code} 更新完了");

    ob_end_clean();
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode([
        "success" => true,
        "race_code" => $race_code,
        "count" => count($data),
        "message" => "展示情報を更新しました"
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {

    log_message("エラー発生: " . $e->getMessage());

    ob_end_clean();
    header("Content-Type: application/json; charset=UTF-8", true, 500);
    echo json_encode([
        "success" => false,
        "message" => "エラーが発生しました: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);

}

exit;