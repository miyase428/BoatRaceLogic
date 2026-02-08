<?php
/**
 * safeGoto.php
 * ------------------------------------------------------------
 * Playwright-PHP の page.goto() を安全に実行するための共通関数。
 *
 * ・指定URLへ遷移を試みる
 * ・タイムアウトやネットワークエラー時はリトライ
 * ・最大リトライ回数を超えた場合は false を返す
 *
 * 主にスクレイピング処理で使用。
 * ログ出力には log_message() を利用する前提。
 *
 * 使用例:
 *   if (!safeGoto($page, $url)) {
 *       // エラー処理
 *   }
 *
 * ------------------------------------------------------------
 */

function safeGoto($page, $url, $maxRetry = 3, $timeout = 15000)
{
    for ($i = 1; $i <= $maxRetry; $i++) {

        try {
            log_message("【safeGoto】{$i}回目: {$url} へ移動中…");

            $response = $page->goto($url, [
                'timeout'   => $timeout,
                'waitUntil' => 'domcontentloaded'
            ]);

            if ($response && $response->ok()) {
                log_message("【safeGoto】成功: {$url}");
                return true;
            }

            log_message("【safeGoto】レスポンス異常: {$url}");
        } catch (Exception $e) {
            log_message("【safeGoto】例外発生: {$e->getMessage()}");
        }

        // リトライ前に少し待つ
        usleep(500000); // 0.5秒
    }

    log_message("【safeGoto】失敗: {$url} へ移動できませんでした");
    return false;
}
