<?php
declare(strict_types=1);

require_once __DIR__ . '/logic/RecentPredictionHistoryLogic.php';
require_once __DIR__ . '/logic/RecentPredictionHistoryFreshness.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

set_time_limit(240);
ignore_user_abort(true);

try {
    $place = strtoupper(trim((string)($_GET['place'] ?? '')));
    $date = trim((string)($_GET['date'] ?? ''));
    $force = (string)($_GET['force'] ?? '0') === '1';

    // まず直近5開催日の並びだけを軽量確認する。
    // 朝の結果取得などで最新開催日が増えていれば古い60Rキャッシュを破棄し、
    // この後の load() で新しい60Rを自動再計算する。
    $freshness = new RecentPredictionHistoryFreshness();
    $autoInvalidated = $freshness->invalidateIfDatesChanged($place, $date);

    $logic = new RecentPredictionHistoryLogic();
    $data = $logic->load($place, $date, $force);

    if (!isset($data['cache']) || !is_array($data['cache'])) {
        $data['cache'] = [];
    }
    $data['cache']['auto_invalidated'] = $autoInvalidated;

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
