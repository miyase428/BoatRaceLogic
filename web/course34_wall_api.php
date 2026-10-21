<?php

declare(strict_types=1);

/**
 * Read-only public adapter for the frozen WALL-R LIVE core.
 * It deliberately exposes the binary probability only; percentile scores and
 * internal identity fields never cross this boundary.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
date_default_timezone_set('Asia/Tokyo');

function wallApiOut(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    header('Allow: GET');
    wallApiOut(405, ['success' => false, 'error_code' => 'METHOD_NOT_ALLOWED']);
}

$race = strtoupper(trim((string)($_GET['race_code'] ?? '')));
if (preg_match('/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $race) !== 1) {
    wallApiOut(400, ['success' => false, 'error_code' => 'INVALID_RACE_CODE']);
}

$root = dirname(__DIR__);
$python = '/usr/bin/python3';
$capabilityPath = $root . '/config/course4_tenkai_venue_capabilities_v1.json';
try {
    $capabilities = json_decode((string)file_get_contents($capabilityPath), true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    wallApiOut(500, ['success' => false, 'error_code' => 'VENUE_CAPABILITY_UNAVAILABLE']);
}
$venueCode = substr($race, 8, 3);
$venueCapability = $capabilities['venues'][$venueCode] ?? $capabilities['default'] ?? null;
if (!is_array($venueCapability) || !isset($venueCapability['model_variant'])) {
    wallApiOut(500, ['success' => false, 'error_code' => 'VENUE_CAPABILITY_UNAVAILABLE']);
}
if (($venueCapability['supported'] ?? true) === false || $venueCapability['model_variant'] === 'UNSUPPORTED') {
    wallApiOut(200, [
        'success' => true, 'prediction_available' => false, 'race_code' => $race,
        'stage' => 'POST_EXHIBITION', 'reason_code' => 'VENUE_NOT_SUPPORTED',
    ]);
}
$script = $root . '/forecast/course34_wall_live.py';
if ($venueCapability['model_variant'] === 'NO_STRAIGHT') {
    $script = $root . '/forecast/course34_wall_no_straight_live.py';
} elseif ($venueCapability['model_variant'] !== 'FULL') {
    wallApiOut(500, ['success' => false, 'error_code' => 'VENUE_CAPABILITY_UNAVAILABLE']);
}
// CLI-only seam for isolated adapter tests; HTTP cannot choose an executable.
if (PHP_SAPI === 'cli' && is_string(getenv('COURSE34_WALL_API_TEST_PYTHON')) && getenv('COURSE34_WALL_API_TEST_PYTHON') !== '') {
    $python = getenv('COURSE34_WALL_API_TEST_PYTHON');
}
$cutoff = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM);
$cmd = [$python, $script, '--race-code', $race, '--prediction-cutoff', $cutoff];
$pipes = [];
$process = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
if (!is_resource($process)) wallApiOut(500, ['success' => false, 'error_code' => 'INFERENCE_START_FAILED']);
fclose($pipes[0]);
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);
$output = ''; $error = ''; $timedOut = false; $started = microtime(true);
while (($state = proc_get_status($process))['running']) {
    $output .= stream_get_contents($pipes[1]);
    $error .= stream_get_contents($pipes[2]);
    if (microtime(true) - $started > 15.0) { proc_terminate($process); $timedOut = true; break; }
    usleep(10000);
}
$output .= stream_get_contents($pipes[1]);
$error .= stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
$exit = proc_close($process);
if ($timedOut) wallApiOut(504, ['success' => false, 'error_code' => 'INFERENCE_TIMEOUT']);
if ($exit !== 0) wallApiOut(502, ['success' => false, 'error_code' => 'INFERENCE_PROCESS_FAILED']);
try { $data = json_decode($output, true, 512, JSON_THROW_ON_ERROR); }
catch (Throwable) { wallApiOut(502, ['success' => false, 'error_code' => 'INFERENCE_MALFORMED_RESPONSE']); }
if (!is_array($data) || !array_key_exists('prediction_available', $data)) {
    wallApiOut(502, ['success' => false, 'error_code' => 'INFERENCE_MALFORMED_RESPONSE']);
}
if (!$data['prediction_available']) {
    wallApiOut(200, [
        'success' => true, 'prediction_available' => false, 'race_code' => $race,
        'stage' => $data['stage'] ?? 'POST_EXHIBITION',
        'reason_code' => (string)(($data['missing_reason'][0] ?? 'INFERENCE_UNAVAILABLE')),
    ]);
}
$probability = $data['wall_probability'] ?? null;
if (!is_numeric($probability) || !is_finite((float)$probability) || (float)$probability < 0.0 || (float)$probability > 1.0) {
    wallApiOut(502, ['success' => false, 'error_code' => 'INFERENCE_MALFORMED_RESPONSE']);
}
wallApiOut(200, [
    'success' => true, 'prediction_available' => true, 'race_code' => $race,
    'stage' => $data['stage'] ?? 'POST_EXHIBITION', 'wall_probability' => (float)$probability,
]);
