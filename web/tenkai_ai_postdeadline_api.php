<?php

declare(strict_types=1);

require_once __DIR__ . '/logic/TenkaiAiPostDeadlineReadModel.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');

function postDeadlineOut(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    header('Allow: GET');
    postDeadlineOut(405, ['success' => false, 'error_code' => 'METHOD_NOT_ALLOWED']);
}
$raceCode = strtoupper(trim((string)($_GET['race_code'] ?? '')));
try {
    postDeadlineOut(200, ['success' => true] + (new TenkaiAiPostDeadlineReadModel(dirname(__DIR__)))->display($raceCode));
} catch (InvalidArgumentException) {
    postDeadlineOut(400, ['success' => false, 'error_code' => 'INVALID_RACE_CODE']);
} catch (Throwable) {
    postDeadlineOut(503, ['success' => false, 'error_code' => 'POSTDEADLINE_ANALYSIS_UNAVAILABLE']);
}
