<?php

declare(strict_types=1);

function httpSessionAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function unusedPort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) throw new RuntimeException($error);
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    return (int)substr(strrchr((string)$name, ':'), 1);
}

function waitForServer(int $port): void
{
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.05);
        if (is_resource($socket)) { fclose($socket); return; }
        usleep(25_000);
    }
    throw new RuntimeException('fixture server did not start');
}

$root = sys_get_temp_dir() . '/official-manual-session-http-' . bin2hex(random_bytes(6));
$sessions = $root . '/sessions';
mkdir($sessions, 0700, true);
$helper = realpath(__DIR__ . '/../web/logic/OfficialExhibitionManualRequest.php');
httpSessionAssert(is_string($helper), 'manual request helper exists');
$childPort = unusedPort();
$parentPort = unusedPort();

$child = <<<'PHP'
<?php
require getenv('MANUAL_REQUEST_HELPER');
header('Content-Type: application/json');
echo json_encode([
    'valid' => OfficialExhibitionManualRequest::validatePost((string)($_POST['csrf_token'] ?? '')),
]);
PHP;
$parent = <<<'PHP'
<?php
require getenv('MANUAL_REQUEST_HELPER');
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['token' => OfficialExhibitionManualRequest::token()]);
    return;
}
$token = (string)($_POST['csrf_token'] ?? '');
if (!OfficialExhibitionManualRequest::validatePost($token)) {
    http_response_code(403);
    echo json_encode(['valid' => false, 'reason' => 'parent_csrf']);
    return;
}
$name = session_name();
$id = session_id();
OfficialExhibitionManualRequest::closeForInternalRequest();
$ch = curl_init('http://127.0.0.1:' . getenv('CHILD_PORT') . '/');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['csrf_token' => $token]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Cookie: ' . $name . '=' . rawurlencode($id)]);
curl_setopt($ch, CURLOPT_TIMEOUT, 3);
$response = curl_exec($ch);
$error = curl_error($ch);
curl_close($ch);
echo json_encode(['valid' => true, 'child' => json_decode((string)$response, true), 'error' => $error]);
PHP;
file_put_contents($root . '/child.php', $child);
file_put_contents($root . '/parent.php', $parent);

$environment = array_merge($_ENV, [
    'MANUAL_REQUEST_HELPER' => $helper,
    'CHILD_PORT' => (string)$childPort,
]);
$null = fopen('/dev/null', 'w');
$descriptor = [['pipe', 'r'], $null, $null];
$childProcess = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessions, '-S', '127.0.0.1:' . $childPort, $root . '/child.php'], $descriptor, $childPipes, $root, $environment);
$parentProcess = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessions, '-S', '127.0.0.1:' . $parentPort, $root . '/parent.php'], $descriptor, $parentPipes, $root, $environment);

try {
    httpSessionAssert(is_resource($childProcess) && is_resource($parentProcess), 'fixture processes started');
    waitForServer($childPort);
    waitForServer($parentPort);
    $get = curl_init('http://127.0.0.1:' . $parentPort . '/');
    curl_setopt($get, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($get, CURLOPT_HEADER, true);
    $firstResponse = (string)curl_exec($get);
    $headerSize = (int)curl_getinfo($get, CURLINFO_HEADER_SIZE);
    $headers = substr($firstResponse, 0, $headerSize);
    $first = json_decode(substr($firstResponse, $headerSize), true);
    curl_close($get);
    preg_match('/^Set-Cookie:\s*([^;\r\n]+)/mi', $headers, $cookieMatch);
    $cookie = (string)($cookieMatch[1] ?? '');
    $token = (string)($first['token'] ?? '');
    httpSessionAssert($cookie !== '', 'parent issued a session cookie');
    httpSessionAssert(preg_match('/^[a-f0-9]{64}$/', $token) === 1, 'parent issued CSRF token');

    $post = curl_init('http://127.0.0.1:' . $parentPort . '/');
    curl_setopt($post, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($post, CURLOPT_COOKIE, $cookie);
    curl_setopt($post, CURLOPT_POST, true);
    curl_setopt($post, CURLOPT_POSTFIELDS, http_build_query(['csrf_token' => $token]));
    curl_setopt($post, CURLOPT_TIMEOUT, 5);
    $started = microtime(true);
    $result = json_decode((string)curl_exec($post), true);
    $elapsed = microtime(true) - $started;
    curl_close($post);
    httpSessionAssert(
        ($result['valid'] ?? false) === true,
        'parent accepted CSRF token: ' . json_encode($result, JSON_UNESCAPED_SLASHES)
    );
    httpSessionAssert(($result['child']['valid'] ?? false) === true, 'child reopened the same session');
    httpSessionAssert(($result['error'] ?? '') === '', 'internal HTTP completed without error');
    httpSessionAssert($elapsed < 4.0, 'internal HTTP did not wait on the parent session lock');
    echo "PASS 4 parent-child HTTP session lock cases\n";
} finally {
    if (is_resource($parentProcess)) proc_terminate($parentProcess);
    if (is_resource($childProcess)) proc_terminate($childProcess);
    fclose($null);
    foreach (glob($sessions . '/*') ?: [] as $file) unlink($file);
    foreach (glob($root . '/*') ?: [] as $file) if (is_file($file)) unlink($file);
    rmdir($sessions);
    rmdir($root);
}
