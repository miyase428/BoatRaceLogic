<?php

declare(strict_types=1);

/**
 * BOAT RACE公式「出走表」の今節成績部分を調べる診断スクリプト。
 *
 * Usage:
 *   php analysis/diagnose_official_current_meet.php 20260913TMG02
 *
 * 画面表示・予想ロジックには未接続。
 */

$raceCode = strtoupper(trim((string)($argv[1] ?? '')));
if (!preg_match('/^(\d{8})([A-Z0-9]{3})(0[1-9]|1[0-2])$/', $raceCode, $m)) {
    fwrite(STDERR, "Usage: php analysis/diagnose_official_current_meet.php RACE_CODE\n");
    exit(2);
}

$date = $m[1];
$placeCode = $m[2];
$raceNo = (int)$m[3];

$placeMap = require __DIR__ . '/../config/place_map.php';
$jcd = null;
foreach ($placeMap as $number => $code) {
    if (strtoupper((string)$code) === $placeCode) {
        $jcd = sprintf('%02d', (int)$number);
        break;
    }
}
if ($jcd === null) {
    fwrite(STDERR, "場コードを公式場番号へ変換できません: {$placeCode}\n");
    exit(2);
}

$url = sprintf(
    'https://www.boatrace.jp/owpc/pc/race/racelist?hd=%s&jcd=%s&rno=%d',
    $date,
    $jcd,
    $raceNo
);

function normMeetDiag(string $value): string
{
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = preg_replace('/[\x{00A0}\s]+/u', ' ', $value) ?? $value;
    return trim($value);
}

function shortMeetDiag(string $value, int $max = 120): string
{
    $value = normMeetDiag($value);
    if (mb_strlen($value, 'UTF-8') <= $max) return $value;
    return mb_substr($value, 0, $max, 'UTF-8') . '…';
}

/** @return array{0:string,1:int} */
function fetchMeetDiag(string $url): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) throw new RuntimeException('cURL初期化失敗');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: ja,en-US;q=0.7,en;q=0.5',
                'Cache-Control: no-cache',
            ],
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if (!is_string($body) || $body === '') {
            throw new RuntimeException($error !== '' ? $error : '公式HTML取得失敗');
        }
        return [$body, $status];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 15,
            'ignore_errors' => true,
            'header' => implode("\r\n", [
                'User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36',
                'Accept-Language: ja,en-US;q=0.7,en;q=0.5',
            ]),
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    if (!is_string($body) || $body === '') throw new RuntimeException('公式HTML取得失敗');
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', (string)$header, $hm)) $status = (int)$hm[1];
    }
    return [$body, $status];
}

try {
    [$html, $status] = fetchMeetDiag($url);
} catch (Throwable $e) {
    fwrite(STDERR, '取得エラー: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

if (!class_exists('DOMDocument')) {
    fwrite(STDERR, "DOM拡張がありません。php-xmlを確認してください。\n");
    exit(1);
}

$previous = libxml_use_internal_errors(true);
$doc = new DOMDocument();
$loaded = $doc->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
libxml_clear_errors();
libxml_use_internal_errors($previous);
if (!$loaded) {
    fwrite(STDERR, "公式出走表HTMLをDOM解析できませんでした。\n");
    exit(1);
}

$title = '';
if ($doc->getElementsByTagName('title')->length > 0) {
    $title = normMeetDiag((string)$doc->getElementsByTagName('title')->item(0)?->textContent);
}

$pageText = normMeetDiag((string)$doc->textContent);

echo str_repeat('=', 110) . PHP_EOL;
echo "BOAT RACE公式 出走表・今節成績 診断 v2" . PHP_EOL;
echo str_repeat('=', 110) . PHP_EOL;
echo "race_code : {$raceCode}" . PHP_EOL;
echo "URL       : {$url}" . PHP_EOL;
echo "HTTP      : {$status}" . PHP_EOL;
echo "HTML bytes: " . strlen($html) . PHP_EOL;
echo "title     : {$title}" . PHP_EOL;
echo "今節成績  : " . (mb_strpos($pageText, '今節成績', 0, 'UTF-8') !== false ? 'あり' : 'なし') . PHP_EOL;
echo PHP_EOL;

$tables = $doc->getElementsByTagName('table');
$candidates = [];

echo "【table一覧】件数={$tables->length}" . PHP_EOL;
for ($i = 0; $i < $tables->length; $i++) {
    $table = $tables->item($i);
    if (!$table instanceof DOMElement) continue;
    $text = normMeetDiag((string)$table->textContent);

    // 「今節成績」の見出し自体はtable外にあるため、表内の固有ヘッダで判定する。
    $score = 0;
    foreach (['レースNo', '進入コース', 'STタイミング', '成績', '初日'] as $needle) {
        if (mb_strpos($text, $needle, 0, 'UTF-8') !== false) $score++;
    }
    if ($score >= 4) $candidates[] = $i;

    echo sprintf(
        "table[%02d] rows=%d score=%d class=%s text=%s\n",
        $i,
        $table->getElementsByTagName('tr')->length,
        $score,
        trim($table->getAttribute('class')),
        shortMeetDiag($text, 180)
    );
}

echo PHP_EOL;
if (!$candidates) {
    echo "今節成績の本表を特定できませんでした。table一覧を貼ってください。" . PHP_EOL;
    exit(0);
}

foreach ($candidates as $tableIndex) {
    $table = $tables->item($tableIndex);
    if (!$table instanceof DOMElement) continue;

    echo str_repeat('-', 110) . PHP_EOL;
    echo "【今節成績 candidate table[{$tableIndex}]】" . PHP_EOL;
    echo str_repeat('-', 110) . PHP_EOL;

    $rowNo = 0;
    foreach ($table->getElementsByTagName('tr') as $tr) {
        if (!$tr instanceof DOMElement) continue;

        $cells = [];
        $cellIndex = 0;
        foreach ($tr->childNodes as $child) {
            if (!$child instanceof DOMElement) continue;
            $tag = strtolower($child->tagName);
            if (!in_array($tag, ['th', 'td'], true)) continue;

            $rowspan = max(1, (int)($child->getAttribute('rowspan') ?: 1));
            $colspan = max(1, (int)($child->getAttribute('colspan') ?: 1));
            $cells[] = sprintf(
                '[%02d %s r%d c%d] %s',
                $cellIndex,
                $tag,
                $rowspan,
                $colspan,
                shortMeetDiag((string)$child->textContent, 160)
            );
            $cellIndex++;
        }
        if (!$cells) continue;

        echo sprintf("row[%02d]\n  %s\n", $rowNo, implode("\n  ", $cells));
        $rowNo++;
    }
    echo PHP_EOL;
}

echo "この出力を貼ってください。次に6艇それぞれの今節走・ST・着順を抽出します。" . PHP_EOL;
