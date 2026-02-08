<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../functions/log_message.php';
require_once __DIR__ . '/../functions/safeGoto.php';
require_once __DIR__ . '/../functions/scrape_exhibition.php';
require_once __DIR__ . '/../functions/save_exhibition.php';

use Playwright\Playwright;

$error_file = __DIR__ . '/../data/error_urls.json';

// ------------------------------------------------------------
// error_urls.json を読み込み
// ------------------------------------------------------------
if (!file_exists($error_file)) {
    log_message("error_urls.json が存在しません。処理を終了します。");
    exit;
}

$error_urls = json_decode(file_get_contents($error_file), true);

if (empty($error_urls)) {
    log_message("error_urls は空です。再取り込みは不要です。");
    exit;
}

log_message("再取り込み開始。件数: " . count($error_urls));

// ------------------------------------------------------------
// Playwright 起動
// ------------------------------------------------------------
$pw = Playwright::create();
$browser = $pw->chromium->launch([
    'headless' => true,
]);
$page = $browser->newPage();

$remaining = [];

foreach ($error_urls as $url) {

    log_message("再取得: $url");

    $success = false;

    // ------------------------------------------------------------
    // 最大2回 retry
    // ------------------------------------------------------------
    for ($i = 0; $i < 2; $i++) {

        $res = safeGoto($page, $url);

        if ($res) {
            $data = scrape_exhibition($page);

            if ($data) {
                save_exhibition($data);
                log_message("成功: $url");
                $success = true;
                break;
            }
        }

        log_message("失敗 → 再試行 ($i): $url");
        usleep(rand(500, 800) * 1000); // 0.5〜0.8秒
    }

    if (!$success) {
        log_message("最終的に失敗: $url");
        $remaining[] = $url;
    }

    // ------------------------------------------------------------
    // レース間の軽い待ち（5〜8秒）
    // ------------------------------------------------------------
    usleep(rand(5000, 8000) * 1000);
}

// ------------------------------------------------------------
// 残った URL を保存
// ------------------------------------------------------------
file_put_contents($error_file, json_encode($remaining, JSON_PRETTY_PRINT));

log_message("再取り込み完了。残り: " . count($remaining));

$page->close();
$browser->close();