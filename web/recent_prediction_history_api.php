<?php
declare(strict_types=1);

require_once __DIR__ . '/logic/RecentPredictionHistoryLogic.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

set_time_limit(240);
ignore_user_abort(true);

try {
    $place = strtoupper(trim((string)($_GET['place'] ?? '')));
    $date = trim((string)($_GET['date'] ?? ''));
    $force = (string)($_GET['force'] ?? '0') === '1';

    $logic = new RecentPredictionHistoryLogic();
    $data = $logic->load($place, $date, $force);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'error' => $e->getMessage(),
        'rows' => [],
        'dates' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
