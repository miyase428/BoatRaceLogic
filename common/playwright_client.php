<?php
/**
 * playwright_client.php
 * ------------------------------------------------------------
 * Playwright-PHP の初期化を行う共通モジュール。
 *
 * ・Playwright の起動
 * ・ブラウザの生成
 * ・新規ページの作成
 *
 * 使用例:
 *   [$playwright, $browser, $page] = createPlaywrightPage();
 *
 * ------------------------------------------------------------
 */

use Playwright\Playwright;

function createPlaywrightPage($headless = true)
{
    // Playwright インスタンス生成
    $playwright = new Playwright();

    // ブラウザ起動
    $browser = $playwright->chromium->launch([
        'headless' => $headless
    ]);

    // 新規ページ作成
    $page = $browser->newPage();

    return [$playwright, $browser, $page];
}