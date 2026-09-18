<?php
date_default_timezone_set('Asia/Tokyo');

// API応答をJSONに保つため、PHPエラーは画面へ直接出さずcatch側のmessageで返す。
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

ob_start();

// catch節でも参照できるよう、リクエストされたレースを先に保持する。
// 外部サイトの一時的な応答不良時でも、保存済みの正しい展示を消さないために使う。
$race_code = $_POST["race_code"]
        ?? $_GET["race_code"]
        ?? "";
$pdo = null;

require_once __DIR__ . '/../logic/race_url.php';
require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../logic/exhibition_source_guard.php';
require_once __DIR__ . '/../logic/exhibition_data_quality.php';

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

function hasSavedExhibition(PDO $pdo, string $raceCode): bool
{
    $savedStmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT entry_course) FILTER (
                WHERE player_id IS NOT NULL
                  AND exhibition_time IS NOT NULL
                  AND exhibition_time > 0
                  AND start_timing IS NOT NULL
                  AND lap_time IS NOT NULL
                  AND lap_time > 0
                  AND around_time IS NOT NULL
                  AND around_time > 0
                  AND straight_time IS NOT NULL
                  AND straight_time > 0
            ) AS complete_rows
        FROM boat_race.exhibition_live
        WHERE race_code = :race_code
    ");
    $savedStmt->execute([':race_code' => $raceCode]);
    $saved = $savedStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return (int)($saved['complete_rows'] ?? 0) === 6;
}

try {
    // PostgreSQL 接続
    $pdo = getPDO();

    if ($race_code == "") {
        throw new Exception("race_codeがありません");
    }

    // 正しい展示を持つレースでは再スクレイピングしない。
    // 表示のためのボタン操作が取得元への追加アクセスにならないようにする。
    if (hasSavedExhibition($pdo, $race_code)) {
        ob_end_clean();
        header("Content-Type: application/json; charset=UTF-8");
        echo json_encode([
            "success" => true,
            "race_code" => $race_code,
            "cached" => true,
            "message" => "展示情報は保存済みのため、再取得せず表示しています"
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $cooldownRemaining = exhibitionSourceCooldownRemaining();
    if ($cooldownRemaining > 0) {
        throw new Exception(exhibitionSourceCooldownMessage($cooldownRemaining));
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
    $cmd = "HOME=/home/miyazaki PLAYWRIGHT_BROWSERS_PATH=/home/miyazaki/.cache/ms-playwright /usr/bin/node /var/www/html/boatrace/playwright/exhibition_live_scraper.js " . escapeshellarg($url) . " 2>&1";

    $output = [];
    exec($cmd, $output, $return_var);

    if ($return_var !== 0) {
        $node_error = implode("\n", $output);
        $guard = recordExhibitionSourceFailure();
        $suffix = $guard['cooldown_remaining'] > 0
            ? "\n" . exhibitionSourceCooldownMessage($guard['cooldown_remaining'])
            : '';
        throw new Exception("Playwright 実行エラー (コード: {$return_var})\n詳細: {$node_error}{$suffix}");
    }

    $json = implode("\n", $output);
    $data = json_decode($json, true);

    if (!is_array($data) || empty($data)) {
        throw new Exception("展示データの取得に失敗したか、データが空でした");
    }

    if (count($data) !== 6) {
        throw new Exception("展示データが6艇分そろっていません（取得件数: " . count($data) . "）");
    }

    // ページ取得に成功した時点で、通信異常の連続回数をリセットする。
    recordExhibitionSourceSuccess();

    if (!hasCompleteExhibitionData($data)) {
        throw new Exception("展示情報が全項目そろっていないため、保存せず次回更新対象にします");
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
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {

    $errorMessage = $e->getMessage();
    log_message("エラー発生: " . $errorMessage);
    // Apache実行ユーザーが通常ログに書き込めない環境でも、原因をサーバーログへ残す。
    error_log("[update_exhibition] race_code={$race_code} error={$errorMessage}");

    // 再取得だけが一時的に失敗した場合、すでに揃っている展示をエラー扱いにしない。
    // DBの既存値を上書き・削除せず、そのまま画面で利用する。
    $hasSavedExhibition = false;
    if ($pdo instanceof PDO && $race_code !== '') {
        try {
            $hasSavedExhibition = hasSavedExhibition($pdo, $race_code);
        } catch (Throwable $savedCheckError) {
            error_log("[update_exhibition] saved-data check failed for {$race_code}: " . $savedCheckError->getMessage());
        }
    }

    ob_end_clean();
    header("Content-Type: application/json; charset=UTF-8", true, 500);

    if ($hasSavedExhibition) {
        http_response_code(200);
        echo json_encode([
            "success" => true,
            "race_code" => $race_code,
            "cached" => true,
            "message" => "展示情報の再取得はできませんでしたが、保存済みの展示情報を表示しています"
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode([
        "success" => false,
        "message" => $errorMessage
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

exit;
