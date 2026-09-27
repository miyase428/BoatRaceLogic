<?php

declare(strict_types=1);

require_once __DIR__ . '/logic/OfficialOddsLogic.php';
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
$trifectaRows = [];
$rowsJson = (string)($_POST['trifecta_rows'] ?? '');
if ($rowsJson !== '' && strlen($rowsJson) <= 100000) {
    $decoded = json_decode($rowsJson, true);
    if (is_array($decoded) && count($decoded) <= 120) {
        $trifectaRows = $decoded;
    }
}
$honmeiKai = trim((string)($_POST['honmei_kai'] ?? ''));
$taikouKai = trim((string)($_POST['taikou_kai'] ?? ''));
$snapshotSource = trim((string)($_POST['snapshot_source'] ?? 'user_display_v2'));
if (!in_array($snapshotSource, ['user_display_v2', 'ai_bet_modes_v1'], true)) {
    $snapshotSource = 'user_display_v2';
}
$holeAlert = ((string)($_POST['hole_alert'] ?? '0')) === '1';

try {
    $logic = new OfficialOddsLogic();
    $data = $logic->load($raceCode, $force);
    // 日中にユーザーが画面で確認した最初のオッズだけ、検証用に固定する。
    // 取得処理・画面の挙動は従来どおり。
    PredictionForwardSnapshotStore::captureCurrentTrifectaOdds(
        $raceCode,
        $data,
        $trifectaRows,
        $honmeiKai,
        $taikouKai,
        $snapshotSource,
        null,
        $holeAlert
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
        'error' => '公式オッズ処理でエラーが発生しました。',
        'race_code' => $raceCode,
        'odds' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
