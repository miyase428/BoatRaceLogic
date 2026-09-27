<?php

declare(strict_types=1);

require_once __DIR__ . '/logic/OfficialExactaOddsLogic.php';
require_once __DIR__ . '/logic/PredictionForwardSnapshotStore.php';

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
$exactaRows = [];
$rowsJson = (string)($_POST['exacta_rows'] ?? '');
if ($rowsJson !== '' && strlen($rowsJson) <= 30000) {
    $decoded = json_decode($rowsJson, true);
    if (is_array($decoded) && count($decoded) <= 30) {
        $exactaRows = $decoded;
    }
}
$snapshotSource = trim((string)($_POST['snapshot_source'] ?? 'user_display_v1'));
if (!in_array($snapshotSource, ['user_display_v1', 'bet_simulator_v1'], true)) {
    $snapshotSource = 'user_display_v1';
}

try {
    $logic = new OfficialExactaOddsLogic();
    $data = $logic->load($raceCode, $force);
    // 画面で最初に確認した締切前オッズを、3連単と同じ前方検証記録へ固定する。
    PredictionForwardSnapshotStore::captureCurrentExactaOdds(
        $raceCode,
        $data,
        $exactaRows,
        $snapshotSource
    );
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
        'error' => '公式2連単オッズ処理でエラーが発生しました。',
        'race_code' => $raceCode,
        'odds' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
