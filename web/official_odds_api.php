<?php

declare(strict_types=1);

require_once __DIR__ . '/logic/OfficialOddsLogic.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'status' => 'error',
        'error' => 'POSTで呼び出してください。',
        'odds' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$raceCode = strtoupper(trim((string)($_POST['race_code'] ?? '')));
$force = ((string)($_POST['refresh'] ?? '0')) === '1';

try {
    $logic = new OfficialOddsLogic();
    $data = $logic->load($raceCode, $force);
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'error' => $e->getMessage(),
        'race_code' => $raceCode,
        'odds' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'error' => '公式オッズ処理でエラーが発生しました。',
        'race_code' => $raceCode,
        'odds' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
