<?php

declare(strict_types=1);

require_once __DIR__ . '/ComparativeContractV1.php';

/** First-write, outcome-blind PER_RACE input snapshot store. */
final class StrictForwardInputSnapshotStore
{
    public function __construct(private readonly string $rootPath) {}

    /** @param array<string,mixed> $input @return array{status:string,input_snapshot_identity:string,path:string,record:array<string,mixed>} */
    public function write(array $input): array
    {
        $this->assertOutcomeBlind($input);
        $race = strtoupper(trim((string)($input['race_code'] ?? '')));
        $venue = strtoupper(trim((string)($input['venue'] ?? '')));
        $window = trim((string)($input['window_id'] ?? ''));
        if (!preg_match('/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $race) || $venue !== substr($race, 8, 3) || $window === '') {
            throw new InvalidArgumentException('snapshot_identity_invalid');
        }
        foreach (['cutoff_at', 'timezone', 'schedule_semantic_fingerprint', 'deadline_source_identity', 'ml_child', 'traditional_child'] as $field) {
            if (!array_key_exists($field, $input) || $input[$field] === '' || $input[$field] === null) throw new InvalidArgumentException('snapshot_field_missing:' . $field);
        }
        $payload = [
            'schema_version' => ComparativeContractV1::SNAPSHOT_SCHEMA,
            'window_id' => $window, 'race_code' => $race, 'venue' => $venue,
            'cutoff_at' => (string)$input['cutoff_at'], 'timezone' => (string)$input['timezone'],
            'schedule_semantic_fingerprint' => $input['schedule_semantic_fingerprint'],
            'deadline_source_identity' => $input['deadline_source_identity'],
            'entries' => $input['entries'] ?? [], 'exhibition' => $input['exhibition'] ?? [],
            'ml_child' => $input['ml_child'], 'traditional_child' => $input['traditional_child'],
            'missing_markers' => $input['missing_markers'] ?? [],
        ];
        $identity = ComparativeContractV1::identity($payload);
        $record = $payload + [
            'input_snapshot_identity' => $identity,
            'snapshot_stable_key' => 'INPUT_SNAPSHOT|' . $window . '|' . $race,
            'recorded_at' => (string)($input['recorded_at'] ?? ''),
        ];
        $date = substr($race, 0, 8);
        $dir = rtrim($this->rootPath, DIRECTORY_SEPARATOR) . '/input_snapshots/' . $date;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('snapshot_directory_unavailable');
        $path = $dir . '/' . hash('sha256', (string)$record['snapshot_stable_key']) . '.json';
        $json = ComparativeContractV1::canonicalJson($record) . "\n";
        $handle = @fopen($path, 'x');
        if (is_resource($handle)) {
            $ok = @fwrite($handle, $json) !== false; @fclose($handle);
            if (!$ok) { @unlink($path); throw new RuntimeException('snapshot_write_failed'); }
            return ['status' => 'SAVED', 'input_snapshot_identity' => $identity, 'path' => $path, 'record' => $record];
        }
        $existing = $this->read($path);
        if (is_array($existing) && hash_equals((string)($existing['input_snapshot_identity'] ?? ''), $identity)) {
            return ['status' => 'IDEMPOTENT_SUCCESS', 'input_snapshot_identity' => $identity, 'path' => $path, 'record' => $existing];
        }
        throw new RuntimeException('snapshot_conflict');
    }

    /** @return array<string,mixed>|null */
    private function read(string $path): ?array
    {
        try { $row = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); }
        catch (Throwable) { return null; }
        return is_array($row) ? $row : null;
    }

    private function assertOutcomeBlind(mixed $value): void
    {
        if (!is_array($value)) return;
        foreach ($value as $key => $child) {
            if (is_string($key) && in_array(strtolower($key), [
                'result', 'results', 'finish', 'finishes', 'payout', 'payouts',
                'odds', 'roi', 'target_outcome', 'actual_result', 'outcome',
            ], true)) {
                throw new InvalidArgumentException('snapshot_outcome_field_forbidden');
            }
            $this->assertOutcomeBlind($child);
        }
    }
}
