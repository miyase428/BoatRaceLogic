<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/web/logic/TenkaiAiPostDeadlineRateLimiter.php';

$root = sys_get_temp_dir() . '/tenkai-api-limit-' . bin2hex(random_bytes(5));
$deadlines = $root . '/deadlines';
$control = $root . '/control';
mkdir($deadlines, 0700, true);
mkdir($control, 0700, true);
$raceCode = '20261022TMG01';
file_put_contents($deadlines . '/deadlines_20261022.json', json_encode(['deadlines' => [$raceCode => '00:01']], JSON_THROW_ON_ERROR));

$holderScript = $root . '/hold.php';
file_put_contents($holderScript, <<<'PHP'
<?php
require_once $argv[1];
$result = (new TenkaiAiPostDeadlineRateLimiter($argv[2]))->acquire($argv[3], '192.0.2.10');
if (!$result['ok']) exit(2);
file_put_contents($argv[4], 'ready');
usleep(3000000);
PHP);

$server = null;
$holder = null;
$pipes = [];
$holderPipes = [];
try {
    $ready = $root . '/ready';
    $holder = proc_open(['/usr/bin/php', $holderScript, dirname(__DIR__) . '/web/logic/TenkaiAiPostDeadlineRateLimiter.php', $control, $raceCode, $ready],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $holderPipes, dirname(__DIR__));
    if (!is_resource($holder)) throw new RuntimeException('holder_start_failed');
    for ($i = 0; $i < 100 && !is_file($ready); $i++) usleep(20000);
    if (!is_file($ready)) throw new RuntimeException('holder_not_ready');

    $runnerScript = $root . '/run-api.php';
    $statusPath = $root . '/status';
    file_put_contents($runnerScript, <<<'PHP'
<?php
register_shutdown_function(static function () use ($argv): void { file_put_contents($argv[1], (string)http_response_code()); });
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_GET['race_code'] = $argv[2];
require $argv[3];
PHP);
    $env = array_merge($_ENV, [
        'PATH' => (string)getenv('PATH'),
        'TENKAI_AI_DEADLINE_ROOT' => $deadlines,
        'TENKAI_AI_POSTDEADLINE_CONTROL_ROOT' => $control,
    ]);
    $server = proc_open(['/usr/bin/php', $runnerScript, $statusPath, $raceCode, dirname(__DIR__) . '/web/tenkai_ai_postdeadline_api.php'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $env);
    if (!is_resource($server)) throw new RuntimeException('server_start_failed');
    fclose($pipes[0]);
    $body = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $pipes = [];
    $exit = proc_close($server); $server = null;
    $decoded = json_decode((string)$body, true);
    $status429 = is_file($statusPath) && trim((string)file_get_contents($statusPath)) === '429';
    $retryHeader = is_array($decoded) && ($decoded['retry_after'] ?? 0) === 2;
    $errorCode = is_array($decoded) && ($decoded['error_code'] ?? null) === 'REFERENCE_RACE_BUSY';
    if ($exit !== 0 || !$status429 || !$retryHeader || !$errorCode) throw new RuntimeException('rate_limit_http_contract_failed:' . json_encode([$exit, $stderr, $decoded, @file_get_contents($statusPath)]));
    echo json_encode(['passed' => 3, 'total' => 3, 'checks' => ['http_429' => true, 'retry_after_contract' => true, 'safe_error_code' => true]], JSON_UNESCAPED_SLASHES), PHP_EOL;
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    if (is_resource($holder)) { proc_terminate($holder); proc_close($holder); }
    foreach (array_merge($pipes, $holderPipes) as $pipe) if (is_resource($pipe)) fclose($pipe);
    foreach (glob($control . '/*') ?: [] as $path) @unlink($path);
    @unlink($deadlines . '/deadlines_20261022.json'); @unlink($holderScript); @unlink($runnerScript ?? ''); @unlink($statusPath ?? ''); @unlink($root . '/ready');
    @rmdir($control); @rmdir($deadlines); @rmdir($root);
}
