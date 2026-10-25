<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/web/logic/TenkaiAiPostDeadlineRateLimiter.php';

$root = sys_get_temp_dir() . '/tenkai-rate-test-' . bin2hex(random_bytes(5));
$now = 100.0;
$limiter = new TenkaiAiPostDeadlineRateLimiter($root, static function () use (&$now): float { return $now; }, 2, 10.0);
$checks = [];
$one = $limiter->acquire('20261023TMG01', '192.0.2.1');
$checks['first_allowed'] = $one['ok'];
$sameRace = $limiter->acquire('20261023TMG01', '192.0.2.2');
$checks['same_race_blocked'] = !$sameRace['ok'] && $sameRace['error_code'] === 'REFERENCE_RACE_BUSY';
$two = $limiter->acquire('20261023TMG02', '192.0.2.2');
$checks['different_race_second_slot'] = $two['ok'];
$global = $limiter->acquire('20261023TMG03', '192.0.2.3');
$checks['global_limit'] = !$global['ok'] && $global['error_code'] === 'REFERENCE_GLOBAL_BUSY';
$one['lease']->release(); $two['lease']->release();
$raceCooldown = $limiter->acquire('20261023TMG01', '192.0.2.9');
$checks['same_race_repeat_limited'] = !$raceCooldown['ok'] && $raceCooldown['error_code'] === 'REFERENCE_RACE_RATE_LIMITED' && $raceCooldown['retry_after'] === 30;
$cooldown = $limiter->acquire('20261023TMG03', '192.0.2.1');
$checks['client_cooldown'] = !$cooldown['ok'] && $cooldown['error_code'] === 'REFERENCE_CLIENT_RATE_LIMITED' && $cooldown['retry_after'] === 10;
$now = 111.0;
$recovered = $limiter->acquire('20261023TMG03', '192.0.2.1');
$checks['normal_recovery'] = $recovered['ok']; $recovered['lease']->release();
$now = 131.0;
$released = $limiter->acquire('20261023TMG01', '192.0.2.4');
$checks['lock_released'] = $released['ok']; $released['lease']->release();
$invalidClient = $limiter->acquire('20261023TMG04', 'forwarded-for-value');
$checks['untrusted_client_rejected'] = !$invalidClient['ok'] && $invalidClient['error_code'] === 'REFERENCE_RATE_LIMIT_UNAVAILABLE';

$multiRoot = sys_get_temp_dir() . '/tenkai-rate-multiprocess-' . bin2hex(random_bytes(5));
$helper = sys_get_temp_dir() . '/tenkai-rate-helper-' . bin2hex(random_bytes(5)) . '.php';
file_put_contents($helper, '<?php require ' . var_export(dirname(__DIR__) . '/web/logic/TenkaiAiPostDeadlineRateLimiter.php', true)
    . '; $x=(new TenkaiAiPostDeadlineRateLimiter($argv[1], null, 1, 0))->acquire("20261023TMG05","192.0.2.5"); echo $x["ok"]?"READY\\n":"FAILED\\n"; flush(); if($x["ok"]){sleep(2);$x["lease"]->release();}');
$pipes = [];
$process = proc_open(['/usr/bin/php', $helper, $multiRoot], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) throw new RuntimeException('helper failed');
fclose($pipes[0]);
$ready = trim((string)fgets($pipes[1]));
$otherProcess = (new TenkaiAiPostDeadlineRateLimiter($multiRoot, null, 1, 0))->acquire('20261023TMG06', '192.0.2.6');
$checks['multiple_processes_share_limit'] = $ready === 'READY' && !$otherProcess['ok'] && $otherProcess['error_code'] === 'REFERENCE_GLOBAL_BUSY';
stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
$afterProcess = (new TenkaiAiPostDeadlineRateLimiter($multiRoot, null, 1, 0))->acquire('20261023TMG06', '192.0.2.6');
$checks['process_exit_releases_lock'] = $afterProcess['ok']; if ($afterProcess['ok']) $afterProcess['lease']->release();

$unsafe = sys_get_temp_dir() . '/tenkai-rate-unsafe-' . bin2hex(random_bytes(5)); mkdir($unsafe, 0777); chmod($unsafe, 0777);
$unsafeResult = (new TenkaiAiPostDeadlineRateLimiter($unsafe))->acquire('20261023TMG07', '192.0.2.7');
$checks['unsafe_control_state_fails_closed'] = !$unsafeResult['ok'] && $unsafeResult['error_code'] === 'REFERENCE_RATE_LIMIT_UNAVAILABLE';

$cleanup = static function (string $directory): void {
    if (!is_dir($directory)) return;
    foreach (scandir($directory) ?: [] as $file) if ($file !== '.' && $file !== '..') @unlink($directory . '/' . $file);
    @rmdir($directory);
};
$cleanup($root); $cleanup($multiRoot); $cleanup($unsafe); @unlink($helper);
if (!array_product(array_map(static fn(bool $value): int => $value ? 1 : 0, $checks))) throw new RuntimeException(json_encode($checks));
echo json_encode(['passed' => count($checks), 'total' => count($checks), 'checks' => $checks]), PHP_EOL;
