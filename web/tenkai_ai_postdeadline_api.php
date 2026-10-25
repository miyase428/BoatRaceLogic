<?php

declare(strict_types=1);

require_once __DIR__ . '/logic/TenkaiAiPostDeadlineReadModel.php';
require_once __DIR__ . '/logic/TenkaiAiPostDeadlineRateLimiter.php';

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
$testRuntime = in_array(PHP_SAPI, ['cli', 'cli-server'], true);
$deadlineRoot = $testRuntime && is_string(getenv('TENKAI_AI_DEADLINE_ROOT')) && getenv('TENKAI_AI_DEADLINE_ROOT') !== '' ? getenv('TENKAI_AI_DEADLINE_ROOT') : null;
$controlRoot = $testRuntime && is_string(getenv('TENKAI_AI_POSTDEADLINE_CONTROL_ROOT')) && getenv('TENKAI_AI_POSTDEADLINE_CONTROL_ROOT') !== '' ? getenv('TENKAI_AI_POSTDEADLINE_CONTROL_ROOT') : null;
$lease = null;
try {
    $model = new TenkaiAiPostDeadlineReadModel(dirname(__DIR__), null, null, $deadlineRoot ?: null);
    if ($model->phase($raceCode) === 'POST_DEADLINE') {
        // REMOTE_ADDR is supplied by the web server.  Forwarded headers are
        // intentionally ignored because they are client-controlled here.
        $remoteAddress = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $limit = (new TenkaiAiPostDeadlineRateLimiter($controlRoot ?: null))->acquire($raceCode, $remoteAddress);
        if (!$limit['ok']) {
            $retry = (int)($limit['retry_after'] ?? 0);
            if ($retry > 0) header('Retry-After: ' . $retry);
            $code = (string)($limit['error_code'] ?? 'REFERENCE_RATE_LIMIT_UNAVAILABLE');
            postDeadlineOut($code === 'REFERENCE_RATE_LIMIT_UNAVAILABLE' ? 503 : 429, ['success' => false, 'error_code' => $code, 'retry_after' => $retry]);
        }
        $lease = $limit['lease'];
    }
    $body = ['success' => true] + $model->display($raceCode);
    $lease?->release();
    postDeadlineOut(200, $body);
} catch (InvalidArgumentException) {
    $lease?->release();
    postDeadlineOut(400, ['success' => false, 'error_code' => 'INVALID_RACE_CODE']);
} catch (Throwable) {
    $lease?->release();
    postDeadlineOut(503, ['success' => false, 'error_code' => 'POSTDEADLINE_ANALYSIS_UNAVAILABLE']);
}
