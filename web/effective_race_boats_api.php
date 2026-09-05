<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/logic/EffectiveRaceOutcomeFilter.php';

$raceCode = strtoupper(trim((string)($_GET['race_code'] ?? $_POST['race_code'] ?? '')));

if (!preg_match('/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $raceCode)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'error' => 'race_codeが不正です。',
        'active_boats' => range(1, 6),
        'excluded_boats' => [],
        'trifecta_count' => 120,
        'exacta_count' => 30,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $logic = new EffectiveRaceOutcomeFilter();
    $activeBoats = $logic->detectActiveBoats($raceCode);
    sort($activeBoats, SORT_NUMERIC);
    $activeBoats = array_values(array_unique(array_map('intval', $activeBoats)));

    $excludedBoats = array_values(array_diff(range(1, 6), $activeBoats));
    $n = count($activeBoats);

    echo json_encode([
        'status' => 'ok',
        'error' => '',
        'race_code' => $raceCode,
        'active_boats' => $activeBoats,
        'excluded_boats' => $excludedBoats,
        'boat_count' => $n,
        'trifecta_count' => $n >= 3 ? $n * ($n - 1) * ($n - 2) : 0,
        'exacta_count' => $n >= 2 ? $n * ($n - 1) : 0,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'error' => $e->getMessage(),
        'active_boats' => range(1, 6),
        'excluded_boats' => [],
        'trifecta_count' => 120,
        'exacta_count' => 30,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
