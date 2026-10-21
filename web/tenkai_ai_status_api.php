<?php

declare(strict_types=1);

require_once __DIR__ . '/logic/Cc30cDisplayStatusReadModel.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');

function tenkaiStatusOut(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    header('Allow: GET');
    tenkaiStatusOut(405, ['success' => false, 'error_code' => 'METHOD_NOT_ALLOWED']);
}

$raceCode = strtoupper(trim((string)($_GET['race_code'] ?? '')));
if (!Cc30cDisplayStatusReadModel::validRaceCode($raceCode)) {
    tenkaiStatusOut(400, ['success' => false, 'error_code' => 'INVALID_RACE_CODE']);
}

try {
    tenkaiStatusOut(200, ['success' => true] + Cc30cDisplayStatusReadModel::production(dirname(__DIR__))->status($raceCode));
} catch (Throwable) {
    // Do not reveal Window paths, identities, or malformed-record details.
    tenkaiStatusOut(503, ['success' => false, 'error_code' => 'STATUS_UNAVAILABLE']);
}
