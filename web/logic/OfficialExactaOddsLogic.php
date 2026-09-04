<?php

declare(strict_types=1);

/**
 * BOAT RACE公式サイトの2連単オッズを取得し、レース単位JSONでキャッシュする。
 *
 * - 通常6艇立て: 30通り
 * - 実質5艇立て: 20通り
 * - 初回表示: キャッシュがなければ1回だけ公式サイトへ取得
 * - 以後: 正常キャッシュを再利用
 * - 手動更新: force=true の時だけ再取得
 * - DBには保存しない
 */
class OfficialExactaOddsLogic
{
    public function load(string $raceCode, bool $force = false): array
    {
        $raceCode = strtoupper(trim($raceCode));
        $parts = $this->parseRaceCode($raceCode);
        $cachePath = $this->cachePath($raceCode);

        if (!$force && is_file($cachePath)) {
            $cached = json_decode((string)file_get_contents($cachePath), true);
            $cachedCount = is_array($cached) ? (int)($cached['count'] ?? 0) : 0;
            if (
                is_array($cached)
                && ($cached['race_code'] ?? '') === $raceCode
                && ($cached['status'] ?? '') === 'ok'
                && in_array($cachedCount, [20, 30], true)
            ) {
                $cached['cache'] = ['used' => true];
                return $cached;
            }
        }

        $url = sprintf(
            'https://www.boatrace.jp/owpc/pc/race/odds2tf?hd=%s&jcd=%s&rno=%d',
            $parts['date'],
            $parts['jcd'],
            $parts['race_number']
        );

        try {
            [$html, $httpStatus] = $this->fetchHtml($url);
            if ($httpStatus !== 200) {
                throw new RuntimeException('公式サイトHTTP ' . $httpStatus);
            }

            $odds = $this->parseExactaOdds($html);
            $count = count($odds);
            $status = in_array($count, [20, 30], true) ? 'ok' : 'waiting';
            $error = $status === 'ok'
                ? ''
                : '公式2連単オッズを20/30通り取得できませんでした。未公開またはページ構造変更の可能性があります。';
        } catch (Throwable $e) {
            $odds = [];
            $status = 'error';
            $error = $e->getMessage();
        }

        $data = [
            'status' => $status,
            'error' => $error,
            'race_code' => $raceCode,
            'fetched_at' => date('c'),
            'source' => 'BOAT RACE公式 2連単オッズ',
            'source_url' => $url,
            'count' => count($odds),
            'odds' => $odds,
            'cache' => ['used' => false],
        ];

        $this->writeCache($cachePath, $data);
        return $data;
    }

    private function parseRaceCode(string $raceCode): array
    {
        if (!preg_match('/^(\d{8})([A-Z0-9]{3})(0[1-9]|1[0-2])$/', $raceCode, $m)) {
            throw new InvalidArgumentException('race_codeが不正です。');
        }

        $placeMap = require __DIR__ . '/../../config/place_map.php';
        $jcd = null;
        foreach ($placeMap as $number => $code) {
            if ((string)$code === $m[2]) {
                $jcd = sprintf('%02d', (int)$number);
                break;
            }
        }

        if ($jcd === null) {
            throw new InvalidArgumentException('場コードを公式場番号へ変換できません。');
        }

        return [
            'date' => $m[1],
            'place_code' => $m[2],
            'jcd' => $jcd,
            'race_number' => (int)$m[3],
        ];
    }

    private function fetchHtml(string $url): array
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
                CURLOPT_TIMEOUT => 12,
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
                throw new RuntimeException($error !== '' ? '公式2連単オッズ取得失敗: ' . $error : '公式2連単オッズ取得失敗');
            }

            return [$body, $status];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 12,
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
            throw new RuntimeException('公式2連単オッズ取得失敗: cURL拡張がなく、HTTP取得にも失敗しました。');
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', (string)$header, $m)) {
                $status = (int)$m[1];
            }
        }

        return [$body, $status];
    }

    private function parseExactaOdds(string $html): array
    {
        if (!class_exists('DOMDocument')) {
            throw new RuntimeException('DOM拡張がありません。php-xmlを確認してください。');
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $loaded = $doc->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            throw new RuntimeException('公式2連単オッズHTMLを解析できませんでした。');
        }

        $best = [];
        foreach ($doc->getElementsByTagName('table') as $table) {
            if (!$table instanceof DOMElement) {
                continue;
            }

            $grid = $this->expandTable($table);
            $parsed = $this->parseOddsGrid($grid);
            if (count($parsed) > count($best)) {
                $best = $parsed;
            }
            if (count($best) === 30) {
                break;
            }
        }

        ksort($best, SORT_NATURAL);
        return in_array(count($best), [20, 30], true) ? $best : [];
    }

    private function expandTable(DOMElement $table): array
    {
        $grid = [];
        $rowIndex = 0;

        foreach ($table->getElementsByTagName('tr') as $tr) {
            if (!$tr instanceof DOMElement) {
                continue;
            }

            $colIndex = 0;
            foreach ($tr->childNodes as $cell) {
                if (!$cell instanceof DOMElement || !in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                    continue;
                }

                while (array_key_exists($colIndex, $grid[$rowIndex] ?? [])) {
                    $colIndex++;
                }

                $text = $this->normalizeText($cell->textContent ?? '');
                $rowspan = max(1, (int)($cell->getAttribute('rowspan') ?: 1));
                $colspan = max(1, (int)($cell->getAttribute('colspan') ?: 1));

                for ($r = 0; $r < $rowspan; $r++) {
                    for ($c = 0; $c < $colspan; $c++) {
                        $grid[$rowIndex + $r][$colIndex + $c] = $text;
                    }
                }

                $colIndex += $colspan;
            }

            if (isset($grid[$rowIndex])) {
                ksort($grid[$rowIndex]);
                $grid[$rowIndex] = array_values($grid[$rowIndex]);
            }
            $rowIndex++;
        }

        return $grid;
    }

    private function parseOddsGrid(array $grid): array
    {
        $maxWidth = 0;
        foreach ($grid as $row) {
            $maxWidth = max($maxWidth, is_array($row) ? count($row) : 0);
        }
        if ($maxWidth < 12) {
            return [];
        }

        $best = [];
        $maxOffset = min(6, $maxWidth - 12);

        for ($offset = 0; $offset <= $maxOffset; $offset++) {
            $map = [];

            foreach ($grid as $row) {
                if (!is_array($row) || count($row) < $offset + 12) {
                    continue;
                }

                for ($first = 1; $first <= 6; $first++) {
                    $base = $offset + (($first - 1) * 2);
                    $second = $this->parseBoatNumber($row[$base] ?? '');
                    $odds = $this->parseOddsValue($row[$base + 1] ?? '');

                    if (
                        $second < 1 || $second > 6
                        || $first === $second
                        || $odds === null
                    ) {
                        continue;
                    }

                    $map[$first . '-' . $second] = $odds;
                }
            }

            if (count($map) > count($best)) {
                $best = $map;
            }
        }

        return in_array(count($best), [20, 30], true) ? $best : [];
    }

    private function parseBoatNumber(string $value): int
    {
        $value = trim($value);
        return preg_match('/^[1-6]$/', $value) ? (int)$value : 0;
    }

    private function parseOddsValue(string $value): ?float
    {
        $value = str_replace([',', '倍', ' '], '', trim($value));
        if ($value === '' || !preg_match('/^\d+(?:\.\d+)?$/', $value)) {
            return null;
        }

        $odds = (float)$value;
        return $odds > 0.0 ? $odds : null;
    }

    private function normalizeText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[\x{00A0}\s]+/u', ' ', $value) ?? $value;
        return trim($value);
    }

    private function cachePath(string $raceCode): string
    {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'boatrace_official_odds'
            . DIRECTORY_SEPARATOR . 'exacta_' . $raceCode . '.json';
    }

    private function writeCache(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
        if (!is_string($json)) {
            return;
        }

        $tmp = $path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
            @rename($tmp, $path);
        } else {
            @unlink($tmp);
        }
    }
}
