<?php

declare(strict_types=1);

require_once __DIR__ . '/logic/OfficialOddsLogic.php';

date_default_timezone_set('Asia/Tokyo');

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

const LIVE_T3_HEAD1_GATE = 0.65;
const LIVE_T3_STAKE_EACH = 500;

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function parseRaceCodeLive(string $raceCode): array
{
    $raceCode = strtoupper(trim($raceCode));
    if (!preg_match('/^(\d{4})(\d{2})(\d{2})([A-Z0-9]{3})(0[1-9]|1[0-2])$/', $raceCode, $m)) {
        throw new InvalidArgumentException('race_code形式が不正です。');
    }

    return [
        'race_code' => $raceCode,
        'race_date' => $m[1] . '-' . $m[2] . '-' . $m[3],
        'place_code' => $m[4],
        'race_number' => (int)$m[5],
    ];
}

function postedProbability(string $name): float
{
    $raw = $_POST[$name] ?? null;
    if (!is_numeric($raw)) {
        throw new InvalidArgumentException($name . ' が数値ではありません。');
    }
    $value = (float)$raw;
    if ($value < 0.0 || $value > 1.0) {
        throw new InvalidArgumentException($name . ' が0～1の範囲外です。');
    }
    return $value;
}

function postedCombo(string $name): string
{
    $combo = trim((string)($_POST[$name] ?? ''));
    if (!preg_match('/^([1-6])-([1-6])-([1-6])$/', $combo, $m)) {
        throw new InvalidArgumentException($name . ' の形式が不正です。');
    }
    if ($m[1] === $m[2] || $m[1] === $m[3] || $m[2] === $m[3]) {
        throw new InvalidArgumentException($name . ' に同じ艇番が含まれています。');
    }
    return $combo;
}

function csvHeaderLive(): array
{
    return [
        'observed_at', 'race_code', 'race_date', 'place_code', 'race_number',
        'head1_probability', 'fixed_gate', 'top2_cover_probability',
        'top1_combo', 'top1_probability', 'top1_odds', 'top1_model_value',
        'top2_combo', 'top2_probability', 'top2_odds', 'top2_model_value',
        'combined_odds', 'equal_stake_model_expected_roi',
        'stake_top1', 'stake_top2', 'official_odds_fetched_at', 'official_odds_cache_used',
    ];
}

function ensureFallbackCopy(string $primary, string $fallback): void
{
    if (is_file($fallback) || !is_file($primary) || !is_readable($primary)) {
        return;
    }

    $dir = dirname($fallback);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        @copy($primary, $fallback);
    }
}

function chooseRecordPath(): string
{
    $primary = __DIR__ . '/../analysis/output/live_trifecta_top2_odds_forward.csv';
    $primaryDir = dirname($primary);

    if ((is_file($primary) && is_writable($primary)) || (!is_file($primary) && is_dir($primaryDir) && is_writable($primaryDir))) {
        return $primary;
    }

    // Apacheユーザーからanalysis/outputへ書けない環境では、既存Web更新処理と同じlog配下へ退避する。
    // 初回だけCLI側の既存CSVをコピーし、過去の主記録を失わないようにする。
    $fallback = __DIR__ . '/../log/live_trifecta_top2_odds_forward.csv';
    ensureFallbackCopy($primary, $fallback);
    return $fallback;
}

function readExistingRecord(string $path, string $raceCode): ?array
{
    if (!is_file($path) || filesize($path) === 0) {
        return null;
    }

    $fp = fopen($path, 'rb');
    if ($fp === false) {
        return null;
    }

    $header = fgetcsv($fp);
    if (!is_array($header)) {
        fclose($fp);
        return null;
    }
    if (isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    }
    $idx = array_search('race_code', $header, true);
    if ($idx === false) {
        fclose($fp);
        return null;
    }

    while (($row = fgetcsv($fp)) !== false) {
        if (strtoupper(trim((string)($row[$idx] ?? ''))) !== $raceCode) {
            continue;
        }
        $assoc = [];
        foreach ($header as $i => $key) {
            $assoc[(string)$key] = $row[$i] ?? '';
        }
        fclose($fp);
        return $assoc;
    }

    fclose($fp);
    return null;
}

function appendUniqueRecord(string $path, string $raceCode, array $row): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('記録ディレクトリを作成できません。');
    }

    $fp = fopen($path, 'c+b');
    if ($fp === false) {
        throw new RuntimeException('前方記録CSVを開けません。');
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new RuntimeException('前方記録CSVをロックできません。');
    }

    try {
        rewind($fp);
        $header = fgetcsv($fp);
        $isNew = !is_array($header);
        if ($isNew) {
            $header = csvHeaderLive();
        } else {
            if (isset($header[0])) {
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
            }
            $idx = array_search('race_code', $header, true);
            if ($idx === false) {
                throw new RuntimeException('前方記録CSVのヘッダーが不正です。');
            }
            while (($existing = fgetcsv($fp)) !== false) {
                if (strtoupper(trim((string)($existing[$idx] ?? ''))) === $raceCode) {
                    return false;
                }
            }
        }

        fseek($fp, 0, SEEK_END);
        if ($isNew) {
            fputcsv($fp, $header);
        }
        fputcsv($fp, $row);
        fflush($fp);
        return true;
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function responseFromRecord(array $record, string $path): array
{
    return [
        'status' => 'ok',
        'eligible' => true,
        'record_status' => 'already_recorded',
        'record_path' => $path,
        'race_code' => (string)($record['race_code'] ?? ''),
        'head1_probability' => (float)($record['head1_probability'] ?? 0),
        'fixed_gate' => (float)($record['fixed_gate'] ?? LIVE_T3_HEAD1_GATE),
        'top2_cover_probability' => (float)($record['top2_cover_probability'] ?? 0),
        'top1' => [
            'combo' => (string)($record['top1_combo'] ?? ''),
            'probability' => (float)($record['top1_probability'] ?? 0),
            'odds' => (float)($record['top1_odds'] ?? 0),
            'model_value' => (float)($record['top1_model_value'] ?? 0),
            'stake' => (int)($record['stake_top1'] ?? LIVE_T3_STAKE_EACH),
        ],
        'top2' => [
            'combo' => (string)($record['top2_combo'] ?? ''),
            'probability' => (float)($record['top2_probability'] ?? 0),
            'odds' => (float)($record['top2_odds'] ?? 0),
            'model_value' => (float)($record['top2_model_value'] ?? 0),
            'stake' => (int)($record['stake_top2'] ?? LIVE_T3_STAKE_EACH),
        ],
        'combined_odds' => (float)($record['combined_odds'] ?? 0),
        'model_expected_roi' => (float)($record['equal_stake_model_expected_roi'] ?? 0),
        'official_odds_fetched_at' => (string)($record['official_odds_fetched_at'] ?? ''),
        'official_odds_cache_used' => ((string)($record['official_odds_cache_used'] ?? '0')) === '1',
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonResponse(['status' => 'error', 'error' => 'POSTで呼び出してください。'], 405);
}

try {
    $parsed = parseRaceCodeLive((string)($_POST['race_code'] ?? ''));
    $raceCode = $parsed['race_code'];
    $head1 = postedProbability('head1_probability');
    $top1Combo = postedCombo('top1_combo');
    $top2Combo = postedCombo('top2_combo');
    $top1Prob = postedProbability('top1_probability');
    $top2Prob = postedProbability('top2_probability');
    $forceRefresh = ((string)($_POST['refresh'] ?? '0')) === '1';

    if ($top1Combo === $top2Combo) {
        throw new InvalidArgumentException('Top1とTop2が同じ買い目です。');
    }

    if ($head1 < LIVE_T3_HEAD1_GATE) {
        jsonResponse([
            'status' => 'ok',
            'eligible' => false,
            'record_status' => 'not_applicable',
            'race_code' => $raceCode,
            'head1_probability' => $head1,
            'fixed_gate' => LIVE_T3_HEAD1_GATE,
        ]);
    }

    $recordPath = chooseRecordPath();
    $existing = readExistingRecord($recordPath, $raceCode);
    if ($existing !== null) {
        jsonResponse(responseFromRecord($existing, $recordPath));
    }

    $oddsLogic = new OfficialOddsLogic();
    $oddsData = $oddsLogic->load($raceCode, $forceRefresh);
    if ((string)($oddsData['status'] ?? '') !== 'ok' || (int)($oddsData['count'] ?? 0) !== 120) {
        jsonResponse([
            'status' => 'waiting',
            'eligible' => true,
            'record_status' => 'waiting_odds',
            'race_code' => $raceCode,
            'head1_probability' => $head1,
            'fixed_gate' => LIVE_T3_HEAD1_GATE,
            'error' => (string)($oddsData['error'] ?? '公式3連単オッズ待ちです。'),
        ]);
    }

    $oddsMap = is_array($oddsData['odds'] ?? null) ? $oddsData['odds'] : [];
    $top1Odds = (float)($oddsMap[$top1Combo] ?? 0.0);
    $top2Odds = (float)($oddsMap[$top2Combo] ?? 0.0);
    if ($top1Odds <= 0.0 || $top2Odds <= 0.0) {
        throw new RuntimeException('Top2の公式3連単オッズを取得できません。');
    }

    $cover = $top1Prob + $top2Prob;
    $inverseOdds = (1.0 / $top1Odds) + (1.0 / $top2Odds);
    $combinedOdds = $inverseOdds > 0.0 ? 1.0 / $inverseOdds : 0.0;
    $top1Value = $top1Prob * $top1Odds;
    $top2Value = $top2Prob * $top2Odds;
    $modelExpectedRoi = 0.5 * ($top1Value + $top2Value);

    $row = [
        date('c'),
        $raceCode,
        $parsed['race_date'],
        $parsed['place_code'],
        $parsed['race_number'],
        number_format($head1, 8, '.', ''),
        number_format(LIVE_T3_HEAD1_GATE, 8, '.', ''),
        number_format($cover, 8, '.', ''),
        $top1Combo,
        number_format($top1Prob, 8, '.', ''),
        number_format($top1Odds, 2, '.', ''),
        number_format($top1Value, 8, '.', ''),
        $top2Combo,
        number_format($top2Prob, 8, '.', ''),
        number_format($top2Odds, 2, '.', ''),
        number_format($top2Value, 8, '.', ''),
        number_format($combinedOdds, 8, '.', ''),
        number_format($modelExpectedRoi, 8, '.', ''),
        LIVE_T3_STAKE_EACH,
        LIVE_T3_STAKE_EACH,
        (string)($oddsData['fetched_at'] ?? ''),
        !empty($oddsData['cache']['used']) ? 1 : 0,
    ];

    $saved = appendUniqueRecord($recordPath, $raceCode, $row);
    if (!$saved) {
        $existing = readExistingRecord($recordPath, $raceCode);
        if ($existing !== null) {
            jsonResponse(responseFromRecord($existing, $recordPath));
        }
    }

    jsonResponse([
        'status' => 'ok',
        'eligible' => true,
        'record_status' => 'saved',
        'record_path' => $recordPath,
        'race_code' => $raceCode,
        'head1_probability' => $head1,
        'fixed_gate' => LIVE_T3_HEAD1_GATE,
        'top2_cover_probability' => $cover,
        'top1' => [
            'combo' => $top1Combo,
            'probability' => $top1Prob,
            'odds' => $top1Odds,
            'model_value' => $top1Value,
            'stake' => LIVE_T3_STAKE_EACH,
        ],
        'top2' => [
            'combo' => $top2Combo,
            'probability' => $top2Prob,
            'odds' => $top2Odds,
            'model_value' => $top2Value,
            'stake' => LIVE_T3_STAKE_EACH,
        ],
        'combined_odds' => $combinedOdds,
        'model_expected_roi' => $modelExpectedRoi,
        'official_odds_fetched_at' => (string)($oddsData['fetched_at'] ?? ''),
        'official_odds_cache_used' => !empty($oddsData['cache']['used']),
    ]);
} catch (InvalidArgumentException $e) {
    jsonResponse(['status' => 'error', 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    jsonResponse([
        'status' => 'error',
        'error' => $e->getMessage(),
    ], 500);
}
