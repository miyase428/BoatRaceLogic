<?php

declare(strict_types=1);

/**
 * BOAT RACE公式「出走表」の今節成績部分を調べるための診断スクリプト。
 *
 * Usage:
 *   php analysis/diagnose_official_current_meet.php 20260913TMG02
 *
 * まだWeb表示や予想ロジックには接続しない。
 * まず公式HTMLから「今節成績」を安定して取得できる構造か確認するためのもの。
 */

$raceCode = strtoupper(trim((string)($argv[1] ?? '')));
if (!preg_match('/^(\d{8})([A-Z0-9]{3})(0[1-9]|1[0-2])$/', $raceCode, $m)) {
    fwrite(STDERR, "Usage: php analysis/diagnose_official_current_meet.php RACE_CODE\n");
    fwrite(STDERR, "例: php analysis/diagnose_official_current_meet.php 20260913TMG02\n");
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

function normalizeTextDiag(string $value): string
{
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = preg_replace('/[\x{00A0}\s]+/u', ' ', $value) ?? $value;
    return trim($value);
}

/** @return array{0:string,1:int} */
function fetchOfficialHtmlDiag(string $url): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('cURL初期化に失敗しました。');
        }
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
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: ja,en-US;q=0.7,en;q=0.5',
            ]),
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    if (!is_string($body) || $body === '') {
        throw new RuntimeException('公式HTML取得失敗');
    }
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', (string)$header, $hm)) {
            $status = (int)$hm[1];
        }
    }
    return [$body, $status];
}

function shortCellDiag(string $value, int $max = 90): string
{
    $value = normalizeTextDiag($value);
    if (mb_strlen($value, 'UTF-8') <= $max) {
        return $value;
    }
    return mb_substr($value, 0, $max, 'UTF-8') . '…';
}

try {
    [$html, $status] = fetchOfficialHtmlDiag($url);
} catch (Throwable $e) {
    fwrite(STDERR, "取得エラー: {$e->getMessage()}\n");
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

$xpath = new DOMXPath($doc);
$pageText = normalizeTextDiag((string)$doc->textContent);
$containsCurrentMeet = mb_strpos($pageText, '今節成績', 0, 'UTF-8') !== false;

$title = '';
$titleNodes = $doc->getElementsByTagName('title');
if ($titleNodes->length > 0) {
    $title = normalizeTextDiag((string)$titleNodes->item(0)?->textContent);
}

echo str_repeat('=', 110) . PHP_EOL;
echo "BOAT RACE公式 出走表・今節成績 診断" . PHP_EOL;
echo str_repeat('=', 110) . PHP_EOL;
echo "race_code : {$raceCode}" . PHP_EOL;
echo "URL       : {$url}" . PHP_EOL;
echo "HTTP      : {$status}" . PHP_EOL;
echo "HTML bytes: " . strlen($html) . PHP_EOL;
echo "title     : {$title}" . PHP_EOL;
echo "今節成績  : " . ($containsCurrentMeet ? 'あり' : 'なし') . PHP_EOL;
echo PHP_EOL;

// 「今節成績」を含む要素を親方向にたどり、候補ブロックを表示。
$keywordNodes = $xpath->query("//*[contains(normalize-space(string(.)), '今節成績')]");
$keywordCount = $keywordNodes instanceof DOMNodeList ? $keywordNodes->length : 0;
echo "【今節成績キーワード候補】件数={$keywordCount}" . PHP_EOL;
if ($keywordNodes instanceof DOMNodeList) {
    $shown = 0;
    foreach ($keywordNodes as $node) {
        if (!$node instanceof DOMElement) continue;
        $text = normalizeTextDiag((string)$node->textContent);
        // body/htmlのような巨大要素は除外。
        if (mb_strlen($text, 'UTF-8') > 1200) continue;
        $class = trim($node->getAttribute('class'));
        $id = trim($node->getAttribute('id'));
        echo sprintf(
            "- <%s%s%s> %s\n",
            $node->tagName,
            $id !== '' ? ' id=' . $id : '',
            $class !== '' ? ' class=' . $class : '',
            shortCellDiag($text, 350)
        );
        $shown++;
        if ($shown >= 12) break;
    }
}
echo PHP_EOL;

// 出走表ページ内のtableを一覧化。今節成績を含むtableを優先して詳細表示。
$tables = $doc->getElementsByTagName('table');
echo "【table一覧】件数={$tables->length}" . PHP_EOL;
$candidateIndexes = [];
for ($i = 0; $i < $tables->length; $i++) {
    $table = $tables->item($i);
    if (!$table instanceof DOMElement) continue;
    $text = normalizeTextDiag((string)$table->textContent);
    $has = mb_strpos($text, '今節成績', 0, 'UTF-8') !== false;
    if ($has) $candidateIndexes[] = $i;
    echo sprintf(
        "table[%02d] rows=%d 今節=%s class=%s text=%s\n",
        $i,
        $table->getElementsByTagName('tr')->length,
        $has ? 'YES' : 'no',
        trim($table->getAttribute('class')),
        shortCellDiag($text, 140)
    );
}
echo PHP_EOL;

if (!$candidateIndexes) {
    echo "今節成績を含むtableを特定できませんでした。上のキーワード候補とtable一覧を貼ってください。" . PHP_EOL;
    exit(0);
}

foreach ($candidateIndexes as $tableIndex) {
    $table = $tables->item($tableIndex);
    if (!$table instanceof DOMElement) continue;
    echo str_repeat('-', 110) . PHP_EOL;
    echo "【今節成績 candidate table[{$tableIndex}]】" . PHP_EOL;
    echo str_repeat('-', 110) . PHP_EOL;

    $rowNo = 0;
    foreach ($table->getElementsByTagName('tr') as $tr) {
        if (!$tr instanceof DOMElement) continue;
        $cells = [];
        foreach ($tr->childNodes as $child) {
            if (!$child instanceof DOMElement) continue;
            if (!in_array(strtolower($child->tagName), ['th', 'td'], true)) continue;
            $cells[] = shortCellDiag((string)$child->textContent, 120);
        }
        if (!$cells) continue;
        echo sprintf("row[%02d] %s\n", $rowNo, implode(' | ', $cells));
        $rowNo++;
    }
    echo PHP_EOL;
}

echo "この出力をそのまま貼ってください。次に今節走数・平均ST・着順実績を抽出する本体を作ります。" . PHP_EOL;
