<?php

declare(strict_types=1);

/** @return array{count:int,tree_hash:string} */
function cliWindowFingerprint(string $root): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) continue;
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
        $hash = hash_file('sha256', $file->getPathname());
        if (!is_string($hash)) throw new RuntimeException('fixture_hash_failed:' . $relative);
        $files[$relative] = $hash;
    }
    ksort($files, SORT_STRING);
    $framed = '';
    foreach ($files as $path => $hash) $framed .= $path . "\0" . $hash . "\n";
    return ['count' => count($files), 'tree_hash' => 'sha256:' . hash('sha256', $framed)];
}

/** @return array{exit_code:int,output:string} */
function cliInvoke(string $script, string $argument): array
{
    $output = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($argument) . ' 2>&1', $output, $code);
    return ['exit_code' => $code, 'output' => implode("\n", $output)];
}

function cliAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('ASSERTION_FAILED:' . $message);
}

$repo = dirname(__DIR__);
$sourceId = 'successor-v3-prepared-20261010215741-f5098693';
$preparedId = 'successor-v3-prepared-20261013140942-5ed840d9';
$sourceRoot = $repo . '/var/strict_forward_live_readiness/windows/' . $sourceId;
$preparedRoot = $repo . '/var/strict_forward_live_readiness/windows/' . $preparedId;
$pointer = $repo . '/var/strict_forward_live_readiness/formal_measurement_window_context.json';
$script = $repo . '/analysis/declare_window_implementation_identity.php';

foreach ([$sourceRoot, $preparedRoot, $pointer, $script] as $path) cliAssert(file_exists($path), 'fixture_prerequisite_missing:' . $path);
$before = [
    'source' => cliWindowFingerprint($sourceRoot),
    'prepared' => cliWindowFingerprint($preparedRoot),
    'pointer_hash' => 'sha256:' . hash_file('sha256', $pointer),
];

$help = cliInvoke($script, '--help');
cliAssert($help['exit_code'] === 0 && str_contains($help['output'], 'Usage:'), 'help_is_pure_success');
$version = cliInvoke($script, '--version');
cliAssert($version['exit_code'] === 0 && str_contains($version['output'], 'declare-window-implementation-identity/v1'), 'version_is_pure_success');
$invalid = cliInvoke($script, '--unknown-option');
cliAssert($invalid['exit_code'] !== 0 && str_contains($invalid['output'], 'Usage:'), 'invalid_argument_is_pure_failure');

$after = [
    'source' => cliWindowFingerprint($sourceRoot),
    'prepared' => cliWindowFingerprint($preparedRoot),
    'pointer_hash' => 'sha256:' . hash_file('sha256', $pointer),
];
cliAssert($before === $after, 'cli_paths_create_no_window_or_pointer_mutation');

echo json_encode([
    'status' => 'PASS',
    'tests' => ['help_pure', 'version_pure', 'invalid_argument_pure', 'source_and_prepared_unchanged'],
    'source_file_count' => $after['source']['count'],
    'prepared_file_count' => $after['prepared']['count'],
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
