<?php

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/logic/OfficialDeadlineLogic.php';

date_default_timezone_set('Asia/Tokyo');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function homeDeadlinesJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function homeDeadlinesValidDate(string $value): bool
{
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $dt !== false && $dt->format('Y-m-d') === $value;
}

$dateText = trim((string)($_GET['date'] ?? date('Y-m-d')));
if (!homeDeadlinesValidDate($dateText)) {
    homeDeadlinesJson([
        'status' => 'error',
        'error' => '日付の形式が不正です。',
        'deadlines' => [],
    ], 400);
}

$force = (string)($_GET['force'] ?? '') === '1';
$datePrefix = str_replace('-', '', $dateText);

try {
    $pdo = getPDO();
    $stmt = $pdo->prepare(<<<SQL
        SELECT DISTINCT SUBSTRING(race_code FROM 9 FOR 3) AS place
        FROM boat_race.race_entry
        WHERE race_code LIKE :prefix
        ORDER BY place
    SQL);
    $stmt->execute([':prefix' => $datePrefix . '%']);

    $places = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $place = strtoupper(trim((string)($row['place'] ?? '')));
        if ($place !== '') {
            $places[] = $place;
        }
    }

    if ($places === []) {
        homeDeadlinesJson([
            'status' => 'ok',
            'date' => $dateText,
            'deadlines' => [],
            'requested_places' => 0,
            'complete_places' => 0,
            'fetched_places' => [],
            'cache_places' => [],
            'errors' => [],
            'cache' => ['used' => false],
            'message' => '開催場データがありません。',
        ]);
    }

    $logic = new OfficialDeadlineLogic();
    $result = $logic->load($datePrefix, $places, $force);
    $result['date'] = $dateText;

    homeDeadlinesJson($result, ($result['status'] ?? '') === 'error' ? 502 : 200);
} catch (Throwable $e) {
    homeDeadlinesJson([
        'status' => 'error',
        'date' => $dateText,
        'error' => $e->getMessage(),
        'deadlines' => [],
    ], 500);
}
