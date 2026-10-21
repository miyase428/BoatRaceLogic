<?php

declare(strict_types=1);

require_once __DIR__ . '/logic/ForwardStatusReadModel.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $status = (new ForwardStatusReadModel(dirname(__DIR__)))->current();
    echo json_encode($status, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'error' => 'forward_status_unavailable',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
