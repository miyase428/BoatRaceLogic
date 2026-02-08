<?php
/**
 * retry_error_urls.php
 * ------------------------------------------------------------
 * error_urls.txt に記録された URL を再取得し、
 * 展示データを計算 → 保存するリトライ専用スクリプト。
 *
 * ・PHP Playwright でページ再取得
 * ・展示データの抽出
 * ・exhibition_calc.php で計算
 * ・save_exhibition.php で保存
 * ・成功した URL は error_urls.txt から削除
 *
 * ------------------------------------------------------------
 */

require_once __DIR__ . '/../common/log_message.php';
require_once __DIR__ . '/../common/playwright_client.php';
require_once __DIR__ . '/../common/safeGoto.php';
require_once __DIR__ . '/../common/exhibition_calc.php';
require_once __DIR__ . '/../common/save_exhibition.php';
require_once __DIR__ . '/../common/db_connect.php';

// ------------------------------------------------------------
// error_urls.txt の読み込み
// ------------------------------------------------------------
$error_file = __DIR__ . '/../logs/error_urls.txt';

if (!file_exists($error_file)) {
    log_message("error_urls.txt が存在しません。処理終了。");
    exit;
}

$urls = file($error_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

if (empty($urls)) {
    log_message("エラーURLがありません。処理終了。");
    exit;
}

log_message("リトライ対象URL: " . count($urls) . "件");

// ------------------------------------------------------------
// DB 接続
// ------------------------------------------------------------
$pdo = getPDO();

// ------------------------------------------------------------
// Playwright 初期化
// ------------------------------------------------------------
[$playwright, $browser, $page] = createPlaywrightPage();

// ------------------------------------------------------------
// URLごとにリトライ処理
// ------------------------------------------------------------
$success_urls = [];

foreach ($urls as $url) {

    log_message("=== リトライ開始: {$url} ===");

    // URL から race_code を抽出（例: hiduke=20240201, place_no=01, race_no=05）
    parse_str(parse_url($url, PHP_URL_QUERY), $query);

    $race_date = $query['hiduke'] ?? null;
    $place_no  = $query['place_no'] ?? null;
    $race_no   = $query['race_no'] ?? null;

    if (!$race_date || !$place_no || !$race_no) {
        log_message("URL解析エラー: {$url}");
        continue;
    }

    $race_code = $race_date . $place_no . str_pad($race_no, 2, '0', STR_PAD_LEFT);

    // ------------------------------------------------------------
    // ページ取得
    // ------------------------------------------------------------
    if (!safeGoto($page, $url)) {
        log_message("ページ遷移失敗: {$url}");
        continue;
    }

    // ------------------------------------------------------------
    // 展示データの抽出（PHP Playwright）
    // ------------------------------------------------------------
    try {
        $rows = $page->locator("#tenji_table tbody tr")->all();

        if (empty($rows)) {
            log_message("展示データなし: {$race_code}");
            continue;
        }

        $data = [];

        foreach ($rows as $tr) {
            $data[] = [
                'entry_course'     => $tr->locator("td:nth-child(1)")->innerText(),
                'player_id'        => $tr->locator("td:nth-child(2)")->innerText(),
                'exhibition_time'  => $tr->locator("td:nth-child(3)")->innerText(),
                'start_timing'     => $tr->locator("td:nth-child(4)")->innerText(),
                'lap_time'         => $tr->locator("td:nth-child(5)")->innerText(),
                'around_time'      => $tr->locator("td:nth-child(6)")->innerText(),
                'straight_time'    => $tr->locator("td:nth-child(7)")->innerText(),
            ];
        }

    } catch (Exception $e) {
        log_message("データ抽出エラー: {$e->getMessage()}");
        continue;
    }

    // ------------------------------------------------------------
    // 計算 → 保存
    // ------------------------------------------------------------
    $results = [];

    foreach ($data as $row) {
        $calc = calc_exhibition($pdo, $place_no, $row);
        $results[] = $calc;
    }

    save_exhibition($pdo, $race_code, $results);

    log_message("保存完了: {$race_code}");

    // 成功した URL を記録
    $success_urls[] = $url;
}

// ------------------------------------------------------------
// 成功した URL を error_urls.txt から削除
// ------------------------------------------------------------
if (!empty($success_urls)) {
    $remaining = array_diff($urls, $success_urls);
    file_put_contents($error_file, implode(PHP_EOL, $remaining) . PHP_EOL);
    log_message("成功URLを error_urls.txt から削除しました");
}

log_message("=== リトライ処理完了 ===");