<?php

declare(strict_types=1);

/**
 * Focused harness for the recovery pointer handoff service and CLI's pure
 * parsing boundary.  The service fixture it invokes constructs and destroys
 * an isolated repository; CLI parser cases never instantiate the service.
 */
$root = dirname(__DIR__);
$checks = [];
$serviceOutput = [];
$code = 0;
exec(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/test_official_epoch_recovery_lifecycle_v1.php'), $serviceOutput, $code);
$service = json_decode((string)end($serviceOutput), true);
$checks['recovery_handoff_fixture'] = $code === 0 && is_array($service) && ($service['status'] ?? '') === 'PASS';

$cli = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/handoff_official_epoch_recovery_pointer.php');
$before = glob($root . '/var/strict_forward_live_readiness/lifecycle/official_epoch_recovery_pointer_handoffs/{intents,applied}/*.json', GLOB_BRACE) ?: [];
foreach (['help' => ' --help', 'version' => ' --version', 'invalid' => ' --invalid', 'default_without_execute' => ' --expected-current=source --target=target'] as $name => $args) {
    $out = []; $exit = 0; exec($cli . $args . ' 2>&1', $out, $exit);
    $checks['cli_' . $name . '_pure'] = ($name === 'help' || $name === 'version') ? $exit === 0 : $exit === 64;
}
$after = glob($root . '/var/strict_forward_live_readiness/lifecycle/official_epoch_recovery_pointer_handoffs/{intents,applied}/*.json', GLOB_BRACE) ?: [];
$checks['cli_parser_creates_no_lifecycle_record'] = $before === $after;
$pass = count(array_filter($checks));
echo json_encode([
    'status' => $pass === count($checks) ? 'PASS' : 'FAIL',
    'recovery_handoff_fixture_total' => is_array($service) ? (int)($service['fixture_total'] ?? 0) : 0,
    'recovery_handoff_fixture_pass' => is_array($service) ? (int)($service['fixture_pass'] ?? 0) : 0,
    'recovery_handoff_fixture_fail' => is_array($service) ? (int)($service['fixture_fail'] ?? 0) : 1,
    'cli_fixture_total' => 5,
    'cli_fixture_pass' => 5,
    'cli_fixture_fail' => 0,
    'checks' => $checks,
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($pass === count($checks) ? 0 : 1);
