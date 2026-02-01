<?php

function scrapeExhibitionData(string $url)
{
    $html = file_get_contents($url);
    if (!$html) {
        echo "HTML取得失敗";
        return;
    }

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    // 展示情報ブロックの table を取得
    $table = $xpath->query('//td[contains(text(), "展示情報")]/ancestor::table')[0] ?? null;

    if (!$table) {
        echo "展示情報ブロックが見つかりません";
        return;
    }

    echo "展示情報ブロックは見つかったよ！";
}
