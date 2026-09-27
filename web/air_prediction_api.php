<?php
declare(strict_types=1);

// エア予想用の結果参照API。通常はDBだけを参照し、結果取得時だけ公式を確認する。
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../common/db_connect.php';

// 個人利用前提のため、Web・アプリ・端末をまたいで共有する単一プロフィール。
const AIR_SHARED_PROFILE_ID = 'b1e3d0b8-7939-4f1d-8d71-8f3c9d5d71a1';
const AIR_SHARED_CLIENT_ID = 'c2f4e1c9-8a4a-4e2e-90b2-7d4e0e6e82b2';

function airResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function airDatabaseResult(PDO $pdo, string $raceCode): ?array
{
    $rankStmt = $pdo->prepare(<<<'SQL'
SELECT rank, lane_number
FROM boat_race.race_result_detail
WHERE race_code = :race_code
  AND rank IN ('1', '2', '3')
  AND lane_number BETWEEN 1 AND 6
ORDER BY CASE rank WHEN '1' THEN 1 WHEN '2' THEN 2 WHEN '3' THEN 3 ELSE 9 END
SQL);
    $rankStmt->execute([':race_code' => $raceCode]);

    $boats = [];
    foreach ($rankStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $rank = (string)($row['rank'] ?? '');
        $boat = (int)($row['lane_number'] ?? 0);
        if (!isset($boats[$rank]) && $boat >= 1 && $boat <= 6) {
            $boats[$rank] = $boat;
        }
    }
    if (!isset($boats['1'], $boats['2'], $boats['3'])) {
        return null;
    }

    $combination = implode('-', [$boats['1'], $boats['2'], $boats['3']]);
    if (count(array_unique(explode('-', $combination))) !== 3) {
        return null;
    }

    $payoutStmt = $pdo->prepare(<<<'SQL'
SELECT trifecta_combination, trifecta_payout
FROM boat_race.race_payouts
WHERE race_code = :race_code
LIMIT 1
SQL);
    $payoutStmt->execute([':race_code' => $raceCode]);
    $payout = $payoutStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $dbCombination = preg_replace('/[^1-6]/', '', (string)($payout['trifecta_combination'] ?? ''));
    if (strlen((string)$dbCombination) === 3 && count(array_unique(str_split((string)$dbCombination))) === 3) {
        $combination = implode('-', str_split((string)$dbCombination));
    }

    return [
        'combination' => $combination,
        'payout' => is_numeric($payout['trifecta_payout'] ?? null) ? (int)$payout['trifecta_payout'] : null,
        'source' => 'database',
    ];
}

function airOfficialResult(string $raceCode): array
{
    $placeMap = require __DIR__ . '/../config/place_map_reverse.php';
    $placeNumber = (int)($placeMap[substr($raceCode, 8, 3)] ?? 0);
    $raceNumber = (int)substr($raceCode, 11, 2);
    $date = substr($raceCode, 0, 8);
    if ($placeNumber < 1 || $raceNumber < 1 || $raceNumber > 12) {
        throw new RuntimeException('公式結果の開催場またはレース番号を判定できません。');
    }

    $url = sprintf(
        'https://www.boatrace.jp/owpc/pc/race/raceresult?rno=%d&jcd=%02d&hd=%s',
        $raceNumber,
        $placeNumber,
        $date
    );
    if (!function_exists('curl_init')) {
        throw new RuntimeException('公式結果の取得機能を利用できません。');
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; BoatRaceAirPrediction/1.0)',
        CURLOPT_HTTPHEADER => ['Accept-Language: ja-JP,ja;q=0.9'],
    ]);
    $html = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if (!is_string($html) || $html === '' || $status < 200 || $status >= 300) {
        throw new RuntimeException($error !== '' ? $error : '公式結果ページを取得できませんでした。');
    }
    if (!class_exists('DOMDocument')) {
        throw new RuntimeException('公式結果を解析できるPHP拡張がありません。');
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);
    $rows = $xpath->query('//tr[td[1][contains(normalize-space(.), "3連単")]]');
    if ($rows !== false) {
        foreach ($rows as $row) {
            $numbers = [];
            $nodes = $xpath->query('.//span[contains(concat(" ", normalize-space(@class), " "), " numberSet1_number ")]', $row);
            if ($nodes !== false) {
                foreach ($nodes as $node) {
                    $number = preg_replace('/[^1-6]/u', '', (string)$node->textContent);
                    if (strlen((string)$number) === 1) $numbers[] = $number;
                }
            }
            if (count($numbers) !== 3 || count(array_unique($numbers)) !== 3) continue;

            $payout = null;
            $payoutNodes = $xpath->query('.//span[contains(concat(" ", normalize-space(@class), " "), " is-payout1 ")]', $row);
            if ($payoutNodes !== false && $payoutNodes->length > 0) {
                $digits = preg_replace('/\D/u', '', (string)$payoutNodes->item(0)->textContent);
                if ($digits !== '' && is_numeric($digits)) $payout = (int)$digits;
            }
            return [
                'combination' => implode('-', $numbers),
                'payout' => $payout,
                'source' => 'official',
                'official_url' => $url,
            ];
        }
    }

    return ['combination' => null, 'payout' => null, 'source' => 'official', 'official_url' => $url, 'pending' => true];
}

function airPrediction(PDO $pdo, string $raceCode): ?array
{
    $stmt = $pdo->prepare(<<<'SQL'
SELECT tickets, ticket_input, stake, memo, result_combination, result_payout, result_source, result_fetched_at, created_at, updated_at
FROM boat_race.air_predictions
WHERE profile_id = CAST(:profile_id AS uuid) AND race_code = :race_code
LIMIT 1
SQL);
    $stmt->execute([':profile_id' => AIR_SHARED_PROFILE_ID, ':race_code' => $raceCode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) return null;
    $tickets = json_decode((string)($row['tickets'] ?? '[]'), true);
    return [
        'tickets' => is_array($tickets) ? array_values($tickets) : [],
        'ticket_input' => (string)($row['ticket_input'] ?? ''),
        'stake' => (int)($row['stake'] ?? 100),
        'memo' => (string)($row['memo'] ?? ''),
        'result_combination' => $row['result_combination'] ?? null,
        'result_payout' => is_numeric($row['result_payout'] ?? null) ? (int)$row['result_payout'] : null,
        'result_source' => $row['result_source'] ?? null,
        'result_fetched_at' => $row['result_fetched_at'] ?? null,
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

function airStoreResult(PDO $pdo, string $raceCode, ?array $result): void
{
    if (!is_array($result) || empty($result['combination'])) return;
    $stmt = $pdo->prepare(<<<'SQL'
UPDATE boat_race.air_predictions
SET result_combination = :combination,
    result_payout = :payout,
    result_source = :source,
    result_fetched_at = NOW()
WHERE profile_id = CAST(:profile_id AS uuid) AND race_code = :race_code
SQL);
    $stmt->execute([
        ':combination' => (string)$result['combination'],
        ':payout' => is_numeric($result['payout'] ?? null) ? (int)$result['payout'] : null,
        ':source' => (string)($result['source'] ?? 'database'),
        ':profile_id' => AIR_SHARED_PROFILE_ID,
        ':race_code' => $raceCode,
    ]);
}

function airStoredResult(PDO $pdo, string $raceCode): ?array
{
    // 公式取得済みの結果は公知情報なので、先に取得したエア予想の記録を
    // 結果キャッシュとして全端末で再利用する。予想・メモ自体は返さない。
    $stmt = $pdo->prepare(<<<'SQL'
SELECT result_combination, result_payout, result_source
FROM boat_race.air_predictions
WHERE race_code = :race_code
  AND result_combination ~ '^[1-6]-[1-6]-[1-6]$'
ORDER BY result_fetched_at DESC NULLS LAST
LIMIT 1
SQL);
    $stmt->execute([':race_code' => $raceCode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) return null;
    $combination = (string)($row['result_combination'] ?? '');
    if (preg_match('/^([1-6])-([1-6])-([1-6])$/', $combination, $match) !== 1 || count(array_unique([$match[1], $match[2], $match[3]])) !== 3) {
        return null;
    }
    return [
        'combination' => $combination,
        'payout' => is_numeric($row['result_payout'] ?? null) ? (int)$row['result_payout'] : null,
        'source' => 'stored',
    ];
}

function airRequestBody(): array
{
    $body = json_decode((string)file_get_contents('php://input'), true);
    return is_array($body) ? $body : [];
}

function airValidTickets(mixed $value): array
{
    if (!is_array($value)) return [];
    $tickets = [];
    foreach ($value as $ticket) {
        $ticket = trim((string)$ticket);
        if (preg_match('/^([1-6])-([1-6])-([1-6])$/', $ticket, $match) !== 1) continue;
        if (count(array_unique([$match[1], $match[2], $match[3]])) !== 3) continue;
        $tickets[$ticket] = true;
    }
    return array_keys($tickets);
}

function airValidRaceCode(mixed $value): string
{
    $raceCode = strtoupper(trim((string)$value));
    return preg_match('/^\d{8}[A-Z]{3}(0[1-9]|1[0-2])$/', $raceCode) === 1 ? $raceCode : '';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $request = airRequestBody();
    $action = (string)($request['action'] ?? '');
    $raceCode = airValidRaceCode($request['race_code'] ?? '');
    if ($raceCode === '') {
        airResponse(['ok' => false, 'message' => '登録情報が不正です。'], 422);
    }
    try {
        $pdo = getPDO();
        if ($action === 'delete') {
            $delete = $pdo->prepare('DELETE FROM boat_race.air_predictions WHERE profile_id = CAST(:profile_id AS uuid) AND race_code = :race_code');
            $delete->execute([':profile_id' => AIR_SHARED_PROFILE_ID, ':race_code' => $raceCode]);
            airResponse(['ok' => true, 'prediction' => null, 'message' => '登録したエア予想を削除しました。']);
        }
        if ($action !== 'save') {
            airResponse(['ok' => false, 'message' => '未対応の操作です。'], 422);
        }
        $tickets = airValidTickets($request['tickets'] ?? []);
        $stake = (int)($request['stake'] ?? 0);
        $memo = trim((string)($request['memo'] ?? ''));
        $ticketInput = trim((string)($request['ticket_input'] ?? ''));
        if (!$tickets || $stake < 100 || $stake > 100000 || $stake % 100 !== 0) {
            airResponse(['ok' => false, 'message' => '予想または金額が不正です。'], 422);
        }
        if (function_exists('mb_substr')) {
            $memo = mb_substr($memo, 0, 120, 'UTF-8');
            $ticketInput = mb_substr($ticketInput, 0, 1000, 'UTF-8');
        } else {
            $memo = substr($memo, 0, 120);
            $ticketInput = substr($ticketInput, 0, 1000);
        }
        $jsonTickets = json_encode($tickets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $save = $pdo->prepare(<<<'SQL'
INSERT INTO boat_race.air_predictions (client_id, profile_id, race_code, tickets, ticket_input, stake, memo)
VALUES (CAST(:client_id AS uuid), CAST(:profile_id AS uuid), :race_code, CAST(:tickets AS jsonb), :ticket_input, :stake, :memo)
ON CONFLICT (profile_id, race_code) DO UPDATE SET
    tickets = EXCLUDED.tickets,
    ticket_input = EXCLUDED.ticket_input,
    stake = EXCLUDED.stake,
    memo = EXCLUDED.memo,
    updated_at = NOW()
SQL);
        $save->execute([':client_id' => AIR_SHARED_CLIENT_ID, ':profile_id' => AIR_SHARED_PROFILE_ID, ':race_code' => $raceCode, ':tickets' => $jsonTickets, ':ticket_input' => $ticketInput, ':stake' => $stake, ':memo' => $memo]);
        $result = airDatabaseResult($pdo, $raceCode);
        airStoreResult($pdo, $raceCode, $result);
        airResponse(['ok' => true, 'prediction' => airPrediction($pdo, $raceCode), 'result' => $result, 'message' => 'サーバーにエア予想を登録しました。']);
    } catch (Throwable $e) {
        airResponse(['ok' => false, 'message' => 'エア予想を保存できませんでした。'], 502);
    }
}

$raceCode = airValidRaceCode($_GET['race_code'] ?? '');
if (preg_match('/^\d{8}[A-Z]{3}(0[1-9]|1[0-2])$/', $raceCode) !== 1) {
    airResponse(['ok' => false, 'message' => 'レースコードが不正です。'], 422);
}
try {
    $pdo = getPDO();
    $databaseResult = airDatabaseResult($pdo, $raceCode);
    $cachedResult = $databaseResult ?? airStoredResult($pdo, $raceCode);
    if ($cachedResult !== null) {
        airStoreResult($pdo, $raceCode, $cachedResult);
        $message = $cachedResult['source'] === 'database'
            ? 'DB保存済みの結果を表示しています。'
            : '先に取得・保存された結果を表示しています。';
        airResponse(['ok' => true, 'prediction' => airPrediction($pdo, $raceCode), 'result' => $cachedResult, 'message' => $message]);
    }
    // 保存済み結果がない時だけ、初期表示でも公式を1回確認する。
    // 未確定なら下のpending応答へ進み、画面には従来どおり案内文を表示する。
    $officialResult = airOfficialResult($raceCode);
    if (!empty($officialResult['pending'])) {
        airResponse(['ok' => true, 'prediction' => airPrediction($pdo, $raceCode), 'result' => null, 'official_url' => $officialResult['official_url'], 'message' => '結果はまだDBにありません。レース確定後に「結果を取得」を押してください。']);
    }
    airStoreResult($pdo, $raceCode, $officialResult);
    airResponse(['ok' => true, 'prediction' => airPrediction($pdo, $raceCode), 'result' => $officialResult, 'message' => '公式サイトから結果を取得しました。']);
} catch (Throwable $e) {
    airResponse(['ok' => false, 'message' => '結果を取得できませんでした。時間をおいて再試行してください。'], 502);
}
