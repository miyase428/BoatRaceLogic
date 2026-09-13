<?php
require_once __DIR__ . '/logic/OfficialCurrentMeetLogic.php';
require_once __DIR__ . '/logic/CurrentMeetBoatNumberEnricher.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');

$raceCode = strtoupper(trim((string)($_GET['race_code'] ?? '')));
if (!preg_match('/^\d{8}[A-Z]{3}\d{2}$/', $raceCode)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'error' => 'race_code が不正です。',
        'boats' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $data = (new OfficialCurrentMeetLogic())->load($raceCode);
    $data = (new CurrentMeetBoatNumberEnricher())->enrich($data);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'error' => $e->getMessage(),
        'race_code' => $raceCode,
        'boats' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
