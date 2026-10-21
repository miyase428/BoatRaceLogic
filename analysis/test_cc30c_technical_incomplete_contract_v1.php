<?php

declare(strict_types=1);

/**
 * Isolated reference-fixture test for the proposed CC30C technical-incomplete
 * Window contract.  It never resolves or writes the production Window.
 */

function failFixture(string $message): never
{
    throw new RuntimeException($message);
}

function assertFixture(bool $condition, string $message): void
{
    if (!$condition) failFixture($message);
}

/** @param mixed $value */
function canonicalJson(mixed $value): string
{
    $sort = static function (mixed $item) use (&$sort): mixed {
        if (!is_array($item)) return $item;
        if (array_is_list($item)) return array_map($sort, $item);
        ksort($item, SORT_STRING);
        foreach ($item as $key => $child) $item[$key] = $sort($child);
        return $item;
    };
    return json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function shaPayload(array $payload): string
{
    return 'sha256:' . hash('sha256', canonicalJson($payload));
}

/** @return array{count:int,sha256:string} */
function treeIdentity(string $root): array
{
    $rows = [];
    if (is_dir($root)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file->isFile()) continue;
            $path = str_replace('\\', '/', substr($file->getPathname(), strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1));
            $rows[] = $path . "\0" . hash_file('sha256', $file->getPathname());
        }
    }
    sort($rows, SORT_STRING);
    return ['count' => count($rows), 'sha256' => 'sha256:' . hash('sha256', implode("\n", $rows))];
}

/** @param array<string,mixed> $manifest @param array<string,mixed>|null $abort */
function effectiveState(array $manifest, ?array $abort): string
{
    if (is_array($abort)
        && ($abort['schema_version'] ?? '') === 'formal-measurement-window-abort/v2'
        && ($abort['terminal_state'] ?? '') === 'ABORTED'
        && ($abort['abort_reason'] ?? '') === 'FORMAL_EVIDENCE_INCOMPLETE_PRODUCER_DEFECT'
        && ($abort['official_epoch'] ?? '') === 'YES'
        && ($abort['formal_evaluation_eligible'] ?? null) === false) return 'ABORTED';
    if (($manifest['activation_state'] ?? '') === 'PREPARED' && ($manifest['status'] ?? '') === 'PREPARED') return 'PREPARED';
    if (($manifest['activation_state'] ?? '') === 'ACTIVE' && ($manifest['status'] ?? '') === 'ACTIVE') return 'ACTIVE';
    return 'INVALID';
}

$fixtureRoot = sys_get_temp_dir() . '/cc30c_technical_incomplete_' . bin2hex(random_bytes(8));
$sourceRoot = $fixtureRoot . '/windows/current-active';
$successorRoot = $fixtureRoot . '/windows/successor-prepared';
$lifecycleRoot = $fixtureRoot . '/lifecycle';
foreach ([$sourceRoot . '/ledger', $sourceRoot . '/opportunity', $sourceRoot . '/provenance', $lifecycleRoot . '/aborts'] as $dir) {
    if (!mkdir($dir, 0775, true) && !is_dir($dir)) failFixture('fixture_directory_create_failed');
}

$sourceManifest = [
    'window_schema_version' => 'operational-window/v2',
    'validation_window_id' => 'current-active',
    'activation_state' => 'ACTIVE',
    'status' => 'ACTIVE',
    'armed_at' => '2026-10-15T09:09:26+09:00',
];
file_put_contents($sourceRoot . '/validation_window_manifest.json', canonicalJson($sourceManifest) . "\n");
file_put_contents($sourceRoot . '/ledger/natural.json', canonicalJson(['race_code' => '20261016TDA01', 'origin' => 'NATURAL']) . "\n");
file_put_contents($sourceRoot . '/opportunity/candidate.json', canonicalJson(['race_code' => '20261016SMS11', 'status' => 'UNRESOLVED']) . "\n");
file_put_contents($sourceRoot . '/provenance/identity.json', canonicalJson(['identity' => 'sha256:' . str_repeat('1', 64)]) . "\n");

$before = treeIdentity($sourceRoot);
assertFixture(effectiveState($sourceManifest, null) === 'ACTIVE', 'fixture_source_not_active');

$abort = [
    'schema_version' => 'formal-measurement-window-abort/v2',
    'window_id' => 'current-active',
    'terminal_state' => 'ABORTED',
    'abort_reason' => 'FORMAL_EVIDENCE_INCOMPLETE_PRODUCER_DEFECT',
    'failure_code' => 'LATE_ELIGIBILITY_PRODUCER_SCHEDULE_GAP',
    'official_epoch' => 'YES',
    'incident_id' => 'fixture-incident',
    'incident_hash' => 'sha256:' . str_repeat('2', 64),
    'missing_count' => 8,
    'repair_reconstructibility' => 'PARTIAL',
    'historical_repair_performed' => false,
    'evidence_disposition' => 'QUARANTINED_RETAINED_NONCANONICAL_NOT_INVALIDATED',
    'formal_evaluation_eligible' => false,
    'production_adoption_eligible' => false,
    'successor_copy_permitted' => false,
    'final_evidence_hash' => $before['sha256'],
    'aborted_at' => '2026-10-20T00:00:00+09:00',
];
$abort['abort_record_hash'] = shaPayload($abort);
file_put_contents($lifecycleRoot . '/aborts/current-active.json', canonicalJson($abort) . "\n");

assertFixture(effectiveState($sourceManifest, $abort) === 'ABORTED', 'fixture_abort_not_effective');
$afterAbort = treeIdentity($sourceRoot);
assertFixture($before === $afterAbort, 'source_window_evidence_changed_by_external_abort');

if (!mkdir($successorRoot, 0775, true) && !is_dir($successorRoot)) failFixture('successor_directory_create_failed');
$successorManifest = [
    'window_schema_version' => 'operational-window/v2',
    'validation_window_id' => 'successor-prepared',
    'activation_state' => 'PREPARED',
    'status' => 'PREPARED',
    'prepared_at' => '2026-10-20T00:01:00+09:00',
    'armed_at' => null,
];
file_put_contents($successorRoot . '/validation_window_manifest.json', canonicalJson($successorManifest) . "\n");
file_put_contents($successorRoot . '/declared_implementation_identity.json', canonicalJson([
    'declaration_schema_version' => 'window-declared-implementation-identity/v1',
    'window_id' => 'successor-prepared',
    'implementation_identity' => 'sha256:' . str_repeat('3', 64),
]) . "\n");

assertFixture(effectiveState($successorManifest, null) === 'PREPARED', 'successor_not_prepared');
$successorEvidenceCount = 0;
foreach (['ledger', 'opportunity', 'input_snapshots', 'comparative_pairs'] as $dir) {
    if (!is_dir($successorRoot . '/' . $dir)) continue;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($successorRoot . '/' . $dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.json')) $successorEvidenceCount++;
    }
}
assertFixture($successorEvidenceCount === 0, 'successor_contains_formal_evidence');
assertFixture(!is_file($successorRoot . '/ledger/natural.json'), 'source_evidence_copied_to_successor');
assertFixture(strcmp((string)$successorManifest['prepared_at'], (string)$abort['aborted_at']) > 0, 'successor_future_boundary_invalid');

$result = [
    'schema_version' => 'cc30c-technical-incomplete-contract-fixture-result/v1',
    'status' => 'PASS',
    'fixture_only' => true,
    'production_window_accessed' => false,
    'transition' => ['ACTIVE', 'ABORTED', 'SUCCESSOR_PREPARED'],
    'checks' => [
        'external_abort_only' => 'PASS',
        'source_evidence_byte_identity_after_abort' => 'PASS',
        'technical_incomplete_not_formal_evaluation_eligible' => 'PASS',
        'successor_pristine_prepared' => 'PASS',
        'source_evidence_not_copied' => 'PASS',
        'future_only_boundary' => 'PASS',
    ],
    'source_tree_before' => $before,
    'source_tree_after' => $afterAbort,
    'successor_formal_evidence_count' => $successorEvidenceCount,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

// Remove only this process's unique /tmp fixture after a successful run.
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $child) $remove($child->getPathname());
        rmdir($path);
        return;
    }
    if (is_file($path) || is_link($path)) unlink($path);
};
assertFixture(str_starts_with($fixtureRoot, sys_get_temp_dir() . '/cc30c_technical_incomplete_'), 'fixture_cleanup_scope_invalid');
$remove($fixtureRoot);
