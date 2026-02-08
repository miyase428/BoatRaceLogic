<?php
/**
 * log_message.php
 * ------------------------------------------------------------
 * ログ出力専用の共通関数。
 *
 * ・画面（標準出力）にログを表示
 * ・プロジェクト直下の /log/YYYYMMDD.log に追記保存
 * ・ログフォルダが存在しない場合は自動生成
 *
 * 主にスクレイピング処理や再取り込み処理で使用。
 * エラーURL管理（error_urls.json）は本関数では扱わない。
 *
 * 使用例:
 *   log_message("スクレイピング開始");
 *
 * ------------------------------------------------------------
 */
date_default_timezone_set('Asia/Tokyo');

/**
 * ログ出力（画面 + log/YYYYMMDD.log）
 */
function log_message($message)
{
    $date = date("Y-m-d H:i:s");
    $logLine = "[{$date}] {$message}\n";

    // 画面に出力
    echo $logLine;

    // ログフォルダ
    $logDir = __DIR__ . "/../log";
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }

    // 日付ごとのログファイル
    $logFile = $logDir . "/" . date("Ymd") . ".log";
    file_put_contents($logFile, $logLine, FILE_APPEND);
}