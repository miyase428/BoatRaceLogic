<?php

declare(strict_types=1);
date_default_timezone_set('Asia/Tokyo');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../web/logic/OfficialExhibitionManualRequest.php';
require_once __DIR__ . '/../logic/OfficialAmagasakiOperationalPolicy.php';
require_once __DIR__ . '/../logic/NodeOfficialAmagasakiPayloadFetcher.php';
require_once __DIR__ . '/../logic/OfficialAmagasakiManualExhibitionUpdate.php';

header('Content-Type: application/json; charset=UTF-8');
if (!OfficialExhibitionManualRequest::validatePost((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'reason' => 'MANUAL_CSRF_VALIDATION_FAILED'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
$raceCode = strtoupper(trim((string)($_POST['race_code'] ?? '')));
try {
    $pdo = getPDO();
    $service = new OfficialAmagasakiManualExhibitionUpdate(
        $pdo,
        OfficialAmagasakiOperationalPolicy::fromPdo($pdo),
        new NodeOfficialAmagasakiPayloadFetcher('/usr/bin/node', __DIR__ . '/../playwright/official_exhibition_amagasaki.js', true),
        rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/boatrace_official_deadlines',
    );
    $result = $service->run($raceCode);
    $success = in_array($result['status'], ['saved', 'already_saved'], true);
    $http = $success ? 200 : (($result['status'] === 'review' || $result['status'] === 'blocked') ? 409 : 422);
    http_response_code($http);
    echo json_encode([
        'success' => $success,
        'race_code' => $raceCode,
        'source' => 'official_amagasaki',
        'status' => $result['status'],
        'reason' => $result['reason'],
        'comparison' => $result['comparison'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    error_log('[update_official_exhibition_manual] ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'race_code' => $raceCode, 'reason' => 'MANUAL_OFFICIAL_ENDPOINT_FAILED'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
