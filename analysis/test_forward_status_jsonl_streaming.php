<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/FormalMeasurementWindowStatusInspector.php';

function streamingAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('ASSERTION_FAILED: ' . $message);
}

/** @return array{rows:int,bytes:int,peak_bytes:int} */
function streamingFixture(ReflectionMethod $reader, FormalMeasurementWindowStatusInspector $inspector, int $rows): array
{
    $root = sys_get_temp_dir() . '/boatrace-forward-status-stream-' . bin2hex(random_bytes(6));
    if (!mkdir($root, 0775, true) && !is_dir($root)) throw new RuntimeException('fixture_directory_failed');
    $path = $root . '/events.jsonl';
    $handle = fopen($path, 'wb');
    if ($handle === false) throw new RuntimeException('fixture_open_failed');
    try {
        // About 16 KiB per record.  The two runs are therefore roughly the
        // current production revision-log size and twice that size, without
        // committing a large fixture to the repository.
        $padding = str_repeat('x', 16 * 1024 - 96);
        for ($index = 0; $index < $rows; $index++) {
            fwrite($handle, json_encode(['event' => 'SAFE', 'race_code' => sprintf('20990101TMG%02d', ($index % 12) + 1), 'padding' => $padding], JSON_THROW_ON_ERROR) . "\n");
        }
    } finally {
        fclose($handle);
    }

    $seen = 0;
    $reader->invoke($inspector, $root . '/*.jsonl', static function (array $row) use (&$seen): void {
        if (($row['event'] ?? '') === 'SAFE') $seen++;
    });
    $bytes = filesize($path);
    unlink($path);
    rmdir($root);
    return ['rows' => $seen, 'bytes' => is_int($bytes) ? $bytes : 0, 'peak_bytes' => memory_get_peak_usage(true)];
}

$inspector = new FormalMeasurementWindowStatusInspector(dirname(__DIR__));
$reader = new ReflectionMethod(FormalMeasurementWindowStatusInspector::class, 'forEachJsonLine');
$reader->setAccessible(true);

$current = streamingFixture($reader, $inspector, 2500);
$double = streamingFixture($reader, $inspector, 5000);
streamingAssert($current['rows'] === 2500, 'current-size fixture row count');
streamingAssert($double['rows'] === 5000, 'two-times fixture row count');
// The largest generated stream is roughly 78 MiB.  Decoding one row at a
// time must remain comfortably inside the Apache 128 MiB ceiling.
streamingAssert($double['peak_bytes'] < 80 * 1024 * 1024, 'two-times fixture peak remains below 80 MiB');

$invalidRoot = sys_get_temp_dir() . '/boatrace-forward-status-invalid-' . bin2hex(random_bytes(6));
mkdir($invalidRoot, 0775, true);
file_put_contents($invalidRoot . '/invalid.jsonl', "{invalid}\n");
$invalidRejected = false;
try {
    $reader->invoke($inspector, $invalidRoot . '/*.jsonl', static function (array $_row): void {});
} catch (RuntimeException $error) {
    $invalidRejected = str_starts_with($error->getMessage(), 'forward_status_invalid_jsonl:');
} finally {
    unlink($invalidRoot . '/invalid.jsonl');
    rmdir($invalidRoot);
}
streamingAssert($invalidRejected, 'invalid JSONL remains fail-closed');

echo json_encode([
    'status' => 'PASS',
    'current_size' => $current,
    'two_times_size' => $double,
    'invalid_jsonl_fail_closed' => true,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
