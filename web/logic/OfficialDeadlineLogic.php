<?php

declare(strict_types=1);

/**
 * BOAT RACE公式サイトの「締切予定時刻」を開催場単位で取得し、日単位JSONにまとめてキャッシュする。
 *
 * 方針は OfficialOddsLogic と同じ。
 * - DBには保存しない
 * - その日の初回取得だけ公式サイトへアクセス
 * - 正常取得済みの場は同日中キャッシュを再利用
 * - force=true の時だけ開催中の全場を再取得
 */
final class OfficialDeadlineLogic
{
    /**
     * @param array<int,string> $placeCodes 例: ['KRY', 'OMR']
     */
    public function load(string $date, array $placeCodes, bool $force = false): array
    {
        $date = preg_replace('/\D/', '', trim($date)) ?? '';
        if (!preg_match('/^\d{8}$/', $date)) {
            throw new InvalidArgumentException('日付が不正です。');
        }

        $placeCodes = array_values(array_unique(array_filter(array_map(
            static fn($v): string => strtoupper(trim((string)$v)),
            $placeCodes
        ))));

        $placeToJcd = $this->placeToJcdMap();
        $placeCodes = array_values(array_filter(
            $placeCodes,
            static fn(string $code): bool => isset($placeToJcd[$code])
        ));

        $cachePath = $this->cachePath($date);
        $cached = $this->readCache($cachePath, $date);
        $places = is_array($cached['places'] ?? null) ? $cached['places'] : [];
        $errors = [];
        $fetchedPlaces = [];
        $cachePlaces = [];

        foreach ($placeCodes as $placeCode) {
            $hasValidCache = $this->isValidPlaceData($places[$placeCode] ?? null);
            if (!$force && $hasValidCache) {
                $cachePlaces[] = $placeCode;
                continue;
            }

            $jcd = $placeToJcd[$placeCode];
            $url = sprintf(
                'https://www.boatrace.jp/owpc/pc/race/raceindex?hd=%s&jcd=%s',
                $date,
                $jcd
            );

            try {
                [$html, $httpStatus] = $this->fetchHtml($url);
                if ($httpStatus !== 200) {
                    throw new RuntimeException('公式サイトHTTP ' . $httpStatus);
                }

                $deadlines = $this->parseDeadlineTimes($html);
                if (count($deadlines) !== 12) {
                    throw new RuntimeException('締切予定時刻を12R分取得できませんでした。取得=' . count($deadlines) . 'R');
                }

                $places[$placeCode] = [
                    'place' => $placeCode,
                    'jcd' => $jcd,
                    'source_url' => $url,
                    'fetched_at' => date('c'),
                    'deadlines' => $deadlines,
                ];
                $fetchedPlaces[] = $placeCode;
            } catch (Throwable $e) {
                // 手動更新失敗時も、直前まで正常だった同日キャッシュは捨てない。
                if ($hasValidCache) {
                    $cachePlaces[] = $placeCode;
                }
                $errors[$placeCode] = $e->getMessage();
            }
        }

        $flat = [];
        foreach ($placeCodes as $placeCode) {
            $placeData = $places[$placeCode] ?? null;
            if (!$this->isValidPlaceData($placeData)) {
                continue;
            }
            foreach ($placeData['deadlines'] as $raceNo => $time) {
                $raceNo = (int)$raceNo;
                if ($raceNo < 1 || $raceNo > 12 || !preg_match('/^\d{2}:\d{2}$/', (string)$time)) {
                    continue;
                }
                $raceCode = $date . $placeCode . sprintf('%02d', $raceNo);
                $flat[$raceCode] = (string)$time;
            }
        }
        ksort($flat, SORT_NATURAL);

        $completePlaces = 0;
        foreach ($placeCodes as $placeCode) {
            if ($this->isValidPlaceData($places[$placeCode] ?? null)) {
                $completePlaces++;
            }
        }

        $status = $completePlaces === count($placeCodes)
            ? 'ok'
            : ($completePlaces > 0 ? 'partial' : 'error');

        $payload = [
            'status' => $status,
            'date' => $date,
            'source' => 'BOAT RACE公式 締切予定時刻',
            'fetched_at' => date('c'),
            'requested_places' => count($placeCodes),
            'complete_places' => $completePlaces,
            'fetched_places' => array_values(array_unique($fetchedPlaces)),
            'cache_places' => array_values(array_unique($cachePlaces)),
            'errors' => $errors,
            'places' => $places,
            'deadlines' => $flat,
            'cache' => [
                'used' => count($fetchedPlaces) === 0 && count($flat) > 0,
            ],
        ];

        $this->writeCache($cachePath, $payload);
        return $payload;
    }

    /** @return array<string,string> */
    private function placeToJcdMap(): array
    {
        $placeMap = require __DIR__ . '/../../config/place_map.php';
        $out = [];
        foreach ($placeMap as $number => $code) {
            $code = strtoupper(trim((string)$code));
            if ($code === '') {
                continue;
            }
            $out[$code] = sprintf('%02d', (int)$number);
        }
        return $out;
    }

    /** @return array{0:string,1:int} */
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
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_TIMEOUT => 10,
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
                throw new RuntimeException($error !== '' ? '公式時刻取得失敗: ' . $error : '公式時刻取得失敗');
            }
            return [$body, $status];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
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
            throw new RuntimeException('公式時刻取得失敗: cURL拡張がなく、HTTP取得にも失敗しました。');
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', (string)$header, $m)) {
                $status = (int)$m[1];
            }
        }
        return [$body, $status];
    }

    /** @return array<int,string> */
    private function parseDeadlineTimes(string $html): array
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
            throw new RuntimeException('公式レース一覧HTMLを解析できませんでした。');
        }

        $deadlines = [];
        foreach ($doc->getElementsByTagName('tr') as $tr) {
            if (!$tr instanceof DOMElement) {
                continue;
            }

            $raceNo = 0;
            foreach ($tr->getElementsByTagName('a') as $anchor) {
                $text = $this->normalizeText((string)$anchor->textContent);
                if (preg_match('/^(\d{1,2})R$/', $text, $m)) {
                    $candidate = (int)$m[1];
                    if ($candidate >= 1 && $candidate <= 12) {
                        $raceNo = $candidate;
                        break;
                    }
                }
            }
            if ($raceNo === 0) {
                continue;
            }

            $rowText = $this->normalizeText((string)$tr->textContent);
            if (!preg_match('/(?:^|\s)([01]\d|2[0-3]):([0-5]\d)(?:\s|$)/u', $rowText, $m)) {
                continue;
            }

            $deadlines[$raceNo] = $m[1] . ':' . $m[2];
        }

        ksort($deadlines, SORT_NUMERIC);
        return $deadlines;
    }

    private function normalizeText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[\x{00A0}\s]+/u', ' ', $value) ?? $value;
        return trim($value);
    }

    private function cachePath(string $date): string
    {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'boatrace_official_deadlines'
            . DIRECTORY_SEPARATOR . 'deadlines_' . $date . '.json';
    }

    private function readCache(string $path, string $date): array
    {
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($path), true);
        if (!is_array($data) || (string)($data['date'] ?? '') !== $date) {
            return [];
        }
        return $data;
    }

    private function isValidPlaceData(mixed $value): bool
    {
        if (!is_array($value) || !is_array($value['deadlines'] ?? null)) {
            return false;
        }
        $deadlines = $value['deadlines'];
        if (count($deadlines) !== 12) {
            return false;
        }
        for ($raceNo = 1; $raceNo <= 12; $raceNo++) {
            $time = $deadlines[$raceNo] ?? $deadlines[(string)$raceNo] ?? null;
            if (!is_string($time) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
                return false;
            }
        }
        return true;
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
