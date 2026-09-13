<?php

declare(strict_types=1);

/**
 * BOAT RACE公式「出走表」から今節成績を取得する表示専用ロジック。
 *
 * 取得対象:
 * - 今節走数
 * - 今節平均ST
 * - 今節進入履歴
 * - 今節ST履歴
 * - 今節着順履歴
 *
 * 予想ロジックには接続しない。
 */
final class OfficialCurrentMeetLogic
{
    private const CURRENT_DAY_TTL = 300;
    private const OTHER_DAY_TTL = 86400;

    public function load(string $raceCode, bool $force = false): array
    {
        $raceCode = strtoupper(trim($raceCode));

        try {
            $parts = $this->parseRaceCode($raceCode);
        } catch (Throwable $e) {
            return $this->errorPayload($raceCode, '', $e->getMessage());
        }

        $url = sprintf(
            'https://www.boatrace.jp/owpc/pc/race/racelist?hd=%s&jcd=%s&rno=%d',
            $parts['date'],
            $parts['jcd'],
            $parts['race_number']
        );

        $cachePath = $this->cachePath($raceCode);
        if (!$force) {
            $cached = $this->readCache($cachePath, $raceCode, $parts['date']);
            if ($cached !== null) {
                $cached['cache'] = ['used' => true];
                return $cached;
            }
        }

        try {
            [$html, $httpStatus] = $this->fetchHtml($url);
            if ($httpStatus !== 200) {
                throw new RuntimeException('BOAT RACE公式 HTTP ' . $httpStatus);
            }

            $boats = $this->parseCurrentMeetBoats($html);
            if (count($boats) !== 6) {
                throw new RuntimeException('今節成績を6艇分取得できませんでした。取得=' . count($boats) . '艇');
            }

            $payload = [
                'status' => 'ok',
                'error' => '',
                'race_code' => $raceCode,
                'source' => 'BOAT RACE公式 出走表・今節成績',
                'source_url' => $url,
                'fetched_at' => date('c'),
                'boats' => $boats,
                'cache' => ['used' => false],
            ];
            $this->writeCache($cachePath, $payload);
            return $payload;
        } catch (Throwable $e) {
            return $this->errorPayload($raceCode, $url, $e->getMessage());
        }
    }

    private function parseRaceCode(string $raceCode): array
    {
        if (!preg_match('/^(\d{8})([A-Z0-9]{3})(0[1-9]|1[0-2])$/', $raceCode, $m)) {
            throw new InvalidArgumentException('race_codeが不正です。');
        }

        $placeMap = require __DIR__ . '/../../config/place_map.php';
        $jcd = null;
        foreach ($placeMap as $number => $code) {
            if (strtoupper((string)$code) === $m[2]) {
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
                throw new RuntimeException($error !== '' ? '公式出走表取得失敗: ' . $error : '公式出走表取得失敗');
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
            throw new RuntimeException('公式出走表取得失敗');
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', (string)$header, $hm)) {
                $status = (int)$hm[1];
            }
        }
        return [$body, $status];
    }

    /** @return array<int,array<string,mixed>> */
    private function parseCurrentMeetBoats(string $html): array
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
            throw new RuntimeException('公式出走表HTMLを解析できませんでした。');
        }

        $table = $this->findRaceListTable($doc);
        if (!$table instanceof DOMElement) {
            throw new RuntimeException('公式出走表の本表を特定できませんでした。');
        }

        $rows = [];
        foreach ($table->getElementsByTagName('tr') as $tr) {
            if ($tr instanceof DOMElement) {
                $rows[] = $tr;
            }
        }
        if (count($rows) < 27) {
            throw new RuntimeException('公式出走表の行数が想定より少ないです。');
        }

        $slotCount = $this->detectCurrentMeetSlotCount($rows);
        if ($slotCount <= 0) {
            throw new RuntimeException('今節成績の列数を特定できませんでした。');
        }

        $boats = [];
        for ($rowIndex = 0; $rowIndex < count($rows) - 3; $rowIndex++) {
            $mainCells = $this->directCells($rows[$rowIndex]);
            if (!$mainCells) continue;

            $lane = $this->parseSmallInt($this->cellText($mainCells[0] ?? null));
            if ($lane < 1 || $lane > 6) continue;

            $hasRowspan4 = false;
            foreach ($mainCells as $cell) {
                if ((int)$cell->getAttribute('rowspan') >= 4) {
                    $hasRowspan4 = true;
                    break;
                }
            }
            if (!$hasRowspan4) continue;

            $courseCells = $this->directCells($rows[$rowIndex + 1]);
            $stCells = $this->directCells($rows[$rowIndex + 2]);
            $finishCells = $this->directCells($rows[$rowIndex + 3]);
            if (!$courseCells || !$stCells || !$finishCells) continue;

            // 本行の末尾は「早見」。その直前slotCount列が今節成績。
            $slotStart = count($mainCells) - $slotCount - 1;
            if ($slotStart < 0) continue;

            $playerId = null;
            for ($i = 0; $i < $slotStart; $i++) {
                $text = $this->normalizeText($this->cellText($mainCells[$i] ?? null));
                if (preg_match('/\b(\d{4})\s*\/\s*[AB]\d\b/u', $text, $m)) {
                    $playerId = $m[1];
                    break;
                }
            }

            $records = [];
            $stValues = [];
            for ($slot = 0; $slot < $slotCount; $slot++) {
                $raceRaw = $this->normalizeDigits($this->cellText($mainCells[$slotStart + $slot] ?? null));
                if ($raceRaw === '') continue;

                $raceNo = $this->parseSmallInt($raceRaw);
                if ($raceNo < 1 || $raceNo > 12) continue;

                $courseRaw = $this->normalizeDigits($this->cellText($courseCells[$slot] ?? null));
                $stRaw = $this->normalizeText($this->cellText($stCells[$slot] ?? null));
                $finishRaw = $this->normalizeDigits($this->cellText($finishCells[$slot] ?? null));

                $course = $this->parseSmallInt($courseRaw);
                if ($course < 1 || $course > 6) {
                    $course = null;
                }

                $st = $this->parseStartTiming($stRaw);
                if ($st !== null) {
                    $stValues[] = $st;
                }

                $records[] = [
                    'race_no' => $raceNo,
                    'course' => $course,
                    'st_raw' => $stRaw,
                    'st' => $st,
                    'finish' => $finishRaw,
                ];
            }

            $avgSt = $stValues ? array_sum($stValues) / count($stValues) : null;
            $boats[$lane] = [
                'boat' => $lane,
                'player_id' => $playerId,
                'run_count' => count($records),
                'st_count' => count($stValues),
                'average_st' => $avgSt,
                'records' => $records,
                'course_history' => array_values(array_map(
                    static fn(array $r): ?int => $r['course'],
                    $records
                )),
                'st_history' => array_values(array_map(
                    static fn(array $r): string => (string)$r['st_raw'],
                    $records
                )),
                'finish_history' => array_values(array_map(
                    static fn(array $r): string => (string)$r['finish'],
                    $records
                )),
            ];

            $rowIndex += 3;
        }

        ksort($boats, SORT_NUMERIC);
        return $boats;
    }

    private function findRaceListTable(DOMDocument $doc): ?DOMElement
    {
        $best = null;
        $bestScore = -1;
        foreach ($doc->getElementsByTagName('table') as $table) {
            if (!$table instanceof DOMElement) continue;
            $text = $this->normalizeText((string)$table->textContent);
            $score = 0;
            foreach (['ボートレーサー', 'レースNo', '進入コース', 'STタイミング', '成績'] as $needle) {
                if (mb_strpos($text, $needle, 0, 'UTF-8') !== false) {
                    $score++;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $table;
            }
        }
        return $bestScore >= 4 ? $best : null;
    }

    /** @param array<int,DOMElement> $rows */
    private function detectCurrentMeetSlotCount(array $rows): int
    {
        foreach (array_slice($rows, 0, 4) as $row) {
            foreach ($this->directCells($row) as $cell) {
                $text = $this->normalizeText((string)$cell->textContent);
                if (mb_strpos($text, 'レースNo', 0, 'UTF-8') === false) continue;
                $colspan = (int)$cell->getAttribute('colspan');
                if ($colspan > 0) return $colspan;
            }
        }
        return 0;
    }

    /** @return array<int,DOMElement> */
    private function directCells(DOMElement $tr): array
    {
        $cells = [];
        foreach ($tr->childNodes as $child) {
            if (!$child instanceof DOMElement) continue;
            $tag = strtolower($child->tagName);
            if ($tag === 'td' || $tag === 'th') {
                $cells[] = $child;
            }
        }
        return $cells;
    }

    private function cellText(?DOMElement $cell): string
    {
        return $cell instanceof DOMElement ? $this->normalizeText((string)$cell->textContent) : '';
    }

    private function normalizeText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[\x{00A0}\s]+/u', ' ', $value) ?? $value;
        return trim($value);
    }

    private function normalizeDigits(string $value): string
    {
        $value = $this->normalizeText($value);
        if (function_exists('mb_convert_kana')) {
            $value = mb_convert_kana($value, 'n', 'UTF-8');
        }
        return trim($value);
    }

    private function parseSmallInt(string $value): int
    {
        $value = $this->normalizeDigits($value);
        return preg_match('/^\d{1,2}$/', $value) ? (int)$value : 0;
    }

    private function parseStartTiming(string $raw): ?float
    {
        $value = strtoupper($this->normalizeText($raw));
        if (function_exists('mb_convert_kana')) {
            $value = mb_convert_kana($value, 'as', 'UTF-8');
        }
        $value = str_replace(' ', '', $value);
        if ($value === '') return null;

        if (preg_match('/^F\.?([0-9]{1,2})$/', $value, $m)) {
            return -((float)((int)$m[1]) / 100.0);
        }
        if (preg_match('/^(?:0)?\.([0-9]{1,2})$/', $value, $m)) {
            return (float)((int)$m[1]) / 100.0;
        }
        if (preg_match('/^[0-9]+(?:\.[0-9]+)?$/', $value)) {
            return (float)$value;
        }

        // L表示は単純な0.xxとして平均へ入れると意味が変わるため、表示だけ残して平均から除外する。
        return null;
    }

    private function cachePath(string $raceCode): string
    {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'boatrace_official_current_meet'
            . DIRECTORY_SEPARATOR . 'current_meet_' . $raceCode . '.json';
    }

    private function readCache(string $path, string $raceCode, string $date): ?array
    {
        if (!is_file($path)) return null;
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') return null;
        $data = json_decode($raw, true);
        if (!is_array($data)) return null;
        if (($data['status'] ?? '') !== 'ok' || ($data['race_code'] ?? '') !== $raceCode) return null;

        $ttl = $date === date('Ymd') ? self::CURRENT_DAY_TTL : self::OTHER_DAY_TTL;
        if ((time() - (int)@filemtime($path)) > $ttl) return null;
        if (!is_array($data['boats'] ?? null) || count($data['boats']) !== 6) return null;
        return $data;
    }

    private function writeCache(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($json)) return;

        $tmp = $path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
            @rename($tmp, $path);
        } else {
            @unlink($tmp);
        }
    }

    private function errorPayload(string $raceCode, string $url, string $message): array
    {
        return [
            'status' => 'error',
            'error' => $message,
            'race_code' => $raceCode,
            'source' => 'BOAT RACE公式 出走表・今節成績',
            'source_url' => $url,
            'fetched_at' => date('c'),
            'boats' => [],
            'cache' => ['used' => false],
        ];
    }
}
