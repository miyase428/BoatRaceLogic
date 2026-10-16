<?php

declare(strict_types=1);

require_once __DIR__ . '/ComparativeContractV1.php';

/** Read-only validator for v1 pair evidence; it never reads outcome data. */
final class FormalComparativeReadinessEvaluator
{
    public function __construct(private readonly ComparativeContractV1 $contract) {}

    /** @param list<array<string,mixed>> $pairs @return array<string,mixed> */
    public function evaluate(array $pairs): array
    {
        $ids = $this->contract->identities(); $paired = 0; $excluded = 0; $errors = [];
        foreach ($pairs as $pair) {
            try {
                $use = $this->contract->use((string)($pair['venue'] ?? ''), (int)($pair['course'] ?? 0), (string)($pair['target'] ?? ''));
                if (($pair['scope_class'] ?? '') !== $use['scope_class'] || !hash_equals($ids['comparative_scope_identity'], (string)($pair['comparative_scope_identity'] ?? '')) || !hash_equals($ids['comparative_policy_identity'], (string)($pair['comparative_policy_identity'] ?? ''))) throw new RuntimeException('PAIR_CONTRACT_MISMATCH');
                if ($use['scope_class'] === 'ML_ONLY') {
                    if (($pair['pair_status'] ?? '') !== 'ML_ONLY_EXCLUDED' || ($pair['traditional_decision'] ?? null) !== 'NONE') throw new RuntimeException('ML_ONLY_DENOMINATOR_OR_TRADITIONAL_VIOLATION');
                    $excluded++; continue;
                }
                if (($pair['pair_status'] ?? '') !== 'PAIRED' || !is_bool($pair['ml_decision']['selected'] ?? null) || !is_bool($pair['traditional_decision']['selected'] ?? null)) throw new RuntimeException('COMPARABLE_PAIR_INVALID');
                $paired++;
            } catch (Throwable $error) { $errors[] = $error->getMessage(); }
        }
        return ['status' => $errors === [] ? 'PASS' : 'BLOCKED', 'paired_denominator' => $paired, 'ml_only_excluded' => $excluded, 'errors' => array_values(array_unique($errors)), 'identities' => $ids];
    }

    /**
     * Validates a Window's complete comparison evidence.  Pair rows alone are
     * insufficient: every deployed expected use needs one parent snapshot,
     * one matching pair, and one create-only race completion record.
     *
     * @return array<string,mixed>
     */
    public function evaluateWindow(string $windowRoot): array
    {
        $root = rtrim($windowRoot, DIRECTORY_SEPARATOR);
        $pairs = $this->rows($root . '/comparative/pairs');
        $snapshots = $this->rows($root . '/input_snapshots');
        $completions = $this->rows($root . '/comparative/captures');
        $failures = $this->jsonLines($root . '/comparative/failures');
        $base = $this->evaluate($pairs);
        $errors = $base['errors'];
        $snapshotById = [];
        foreach ($snapshots as $snapshot) {
            $id = (string)($snapshot['input_snapshot_identity'] ?? '');
            if ($id === '' || isset($snapshotById[$id])) $errors[] = 'SNAPSHOT_IDENTITY_INVALID_OR_DUPLICATE';
            else $snapshotById[$id] = $snapshot;
        }
        $pairsBySnapshot = [];
        $pairByHash = [];
        foreach ($pairs as $pair) {
            $snapshot = (string)($pair['input_snapshot_identity'] ?? '');
            $hash = (string)($pair['record_hash'] ?? '');
            if (!isset($snapshotById[$snapshot])) $errors[] = 'PAIR_ORPHAN_SNAPSHOT';
            if ($hash === '' || isset($pairByHash[$hash])) $errors[] = 'PAIR_HASH_INVALID_OR_DUPLICATE';
            else $pairByHash[$hash] = $pair;
            $pairsBySnapshot[$snapshot][(string)($pair['use_key'] ?? '')] = $pair;
        }
        $completionBySnapshot = [];
        foreach ($completions as $completion) {
            $snapshot = (string)($completion['input_snapshot_identity'] ?? '');
            if ($snapshot === '' || isset($completionBySnapshot[$snapshot])) {
                $errors[] = 'COMPARATIVE_COMPLETION_INVALID_OR_DUPLICATE';
                continue;
            }
            $completionBySnapshot[$snapshot] = $completion;
            foreach ((array)($completion['required_use_keys'] ?? []) as $useKey) {
                $pair = $pairsBySnapshot[$snapshot][(string)$useKey] ?? null;
                if (!is_array($pair)) { $errors[] = 'COMPARATIVE_CAPTURE_INCOMPLETE'; continue; }
                $expectedHash = (string)(($completion['pair_record_hashes'] ?? [])[(string)$useKey] ?? '');
                if ($expectedHash === '' || !hash_equals($expectedHash, (string)($pair['record_hash'] ?? ''))) {
                    $errors[] = 'COMPARATIVE_COMPLETION_PAIR_MISMATCH';
                }
            }
        }
        foreach ($snapshotById as $snapshotId => $_snapshot) {
            if (!isset($completionBySnapshot[$snapshotId])) $errors[] = 'SNAPSHOT_ORPHAN_OR_PARTIAL_CAPTURE';
        }
        foreach ($failures as $failure) {
            if (($failure['status'] ?? '') === 'COMPARATIVE_CAPTURE_CONFLICT') $errors[] = 'COMPARATIVE_CAPTURE_CONFLICT';
        }

        // Expected rows are the structural scheduler contract.  Restrict this
        // check to registered production use_keys, never the 18-row matrix.
        $expected = $this->rows($root . '/ledger/expected');
        $requiredByRace = [];
        foreach ($expected as $row) {
            try {
                $use = $this->contract->use((string)($row['venue'] ?? ''), (int)($row['course'] ?? 0), (string)($row['target'] ?? ''));
            } catch (Throwable) { continue; }
            $race = (string)($row['race_code'] ?? '');
            if ($race !== '') $requiredByRace[$race][(string)$use['use_key']] = true;
        }
        foreach ($requiredByRace as $race => $requiredUses) {
            $matching = array_values(array_filter($completions, static fn(array $row): bool => ($row['race_code'] ?? '') === $race));
            if (count($matching) !== 1) { $errors[] = 'EXPECTED_RACE_COMPARATIVE_COMPLETION_MISSING'; continue; }
            $actual = array_fill_keys(array_map('strval', (array)($matching[0]['required_use_keys'] ?? [])), true);
            if (array_diff_key($requiredUses, $actual) !== [] || array_diff_key($actual, $requiredUses) !== []) {
                $errors[] = 'EXPECTED_DEPLOYMENT_SCOPE_MISMATCH';
            }
        }
        $errors = array_values(array_unique($errors));
        return array_merge($base, [
            'status' => $errors === [] ? 'PASS' : 'BLOCKED',
            'errors' => $errors,
            'snapshot_count' => count($snapshots),
            'completion_count' => count($completions),
            'failure_count' => count($failures),
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $directory): array
    {
        if (!is_dir($directory)) return [];
        $rows = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || !str_ends_with($file->getFilename(), '.json')) continue;
            $row = json_decode((string)file_get_contents($file->getPathname()), true);
            if (is_array($row)) $rows[] = $row;
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function jsonLines(string $directory): array
    {
        if (!is_dir($directory)) return [];
        $rows = [];
        foreach (glob($directory . '/*.jsonl') ?: [] as $path) foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true);
            if (is_array($row)) $rows[] = $row;
        }
        return $rows;
    }
}
